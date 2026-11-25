<?php
/**
 * core/services/DripRemarketingService.php
 * [IA-04] Motor de Secuencias Automáticas de Remarketing (Drip Campaigns) — STARFI CRM
 * - Evalúa destinatarios que leyeron una campaña (`LEIDO` / `fecha_lectura IS NOT NULL`) pero no respondieron (`respondido = 0`)
 *   transcurridas X horas (`drip_horas_espera`).
 * - Ejecuta bajo candado transaccional pesimista `SELECT ... FOR UPDATE` (compatible con transacciones anidadas vía SAVEPOINT):
 *     a) `ASIGNAR_ASESOR`: asigna automáticamente la conversación a un vendedor de la sede (Round-Robin / menor carga),
 *        registra oportunidad en el Embudo Kanban y deja nota de contexto comercial en Bandeja.
 *     b) `REIMPACTAR_PLANTILLA`: despacha una segunda plantilla oficial Meta respetando el Escudo Anti-Fatiga 24h (Meta 131049)
 *        y la cuota diaria de la sede.
 *     c) `AMBOS`: re-impacta y asigna simultáneamente a vendedor para seguimiento activo.
 * - 100% mysqli_prepare() + registrar_auditoria() (QA-STD-GLOBAL-2.1).
 */

require_once __DIR__ . '/../crm_sprint2_helper.php';
require_once __DIR__ . '/../audit.php';

class DripRemarketingService {

    /**
     * Garantiza las columnas Drip en `campanas_difusion` y `campanas_difusion_destinatarios`
     * usando `SHOW COLUMNS` 100% compatible con MySQL 8.0 y MariaDB (sin DDL durante transacciones activas).
     */
    public static function ensureSchema(mysqli $con): void {
        if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
            return;
        }
        $flag = rtrim(sys_get_temp_dir(), '/\\') . '/starfi_drip_schema_v1.flag';
        if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
            return;
        }

        $colsCamp = [];
        $r1 = @$con->query("SHOW COLUMNS FROM `campanas_difusion`");
        if ($r1) {
            while ($c = $r1->fetch_assoc()) {
                $colsCamp[$c['Field']] = true;
            }
        }
        if (!empty($colsCamp)) {
            if (!isset($colsCamp['drip_activo'])) {
                @$con->query("ALTER TABLE `campanas_difusion` ADD COLUMN `drip_activo` TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!isset($colsCamp['drip_horas_espera'])) {
                @$con->query("ALTER TABLE `campanas_difusion` ADD COLUMN `drip_horas_espera` INT NOT NULL DEFAULT 24");
            }
            if (!isset($colsCamp['drip_accion'])) {
                @$con->query("ALTER TABLE `campanas_difusion` ADD COLUMN `drip_accion` VARCHAR(30) NOT NULL DEFAULT 'ASIGNAR_ASESOR'");
            }
            if (!isset($colsCamp['drip_template_name'])) {
                @$con->query("ALTER TABLE `campanas_difusion` ADD COLUMN `drip_template_name` VARCHAR(120) DEFAULT NULL");
            }
            if (!isset($colsCamp['drip_template_language'])) {
                @$con->query("ALTER TABLE `campanas_difusion` ADD COLUMN `drip_template_language` VARCHAR(20) NOT NULL DEFAULT 'es'");
            }
            if (!isset($colsCamp['drip_template_category'])) {
                @$con->query("ALTER TABLE `campanas_difusion` ADD COLUMN `drip_template_category` VARCHAR(30) NOT NULL DEFAULT 'UTILITY'");
            }
            if (!isset($colsCamp['drip_params_json'])) {
                @$con->query("ALTER TABLE `campanas_difusion` ADD COLUMN `drip_params_json` TEXT DEFAULT NULL");
            }
            if (!isset($colsCamp['drip_procesados'])) {
                @$con->query("ALTER TABLE `campanas_difusion` ADD COLUMN `drip_procesados` INT NOT NULL DEFAULT 0");
            }
        }

        $colsDest = [];
        $r2 = @$con->query("SHOW COLUMNS FROM `campanas_difusion_destinatarios`");
        if ($r2) {
            while ($c = $r2->fetch_assoc()) {
                $colsDest[$c['Field']] = true;
            }
        }
        if (!empty($colsDest)) {
            if (!isset($colsDest['drip_ejecutado'])) {
                @$con->query("ALTER TABLE `campanas_difusion_destinatarios` ADD COLUMN `drip_ejecutado` TINYINT(1) NOT NULL DEFAULT 0");
            }
            if (!isset($colsDest['drip_estado'])) {
                @$con->query("ALTER TABLE `campanas_difusion_destinatarios` ADD COLUMN `drip_estado` VARCHAR(40) DEFAULT NULL");
            }
            if (!isset($colsDest['drip_fecha'])) {
                @$con->query("ALTER TABLE `campanas_difusion_destinatarios` ADD COLUMN `drip_fecha` DATETIME DEFAULT NULL");
            }
            if (!isset($colsDest['drip_id_asesor'])) {
                @$con->query("ALTER TABLE `campanas_difusion_destinatarios` ADD COLUMN `drip_id_asesor` INT DEFAULT NULL");
            }
            if (!isset($colsDest['drip_id_mensaje_meta'])) {
                @$con->query("ALTER TABLE `campanas_difusion_destinatarios` ADD COLUMN `drip_id_mensaje_meta` VARCHAR(150) DEFAULT NULL");
            }
            $rIdx = @$con->query("SHOW INDEX FROM `campanas_difusion_destinatarios` WHERE Key_name = 'idx_drip_followup'");
            if ($rIdx && $rIdx->num_rows === 0) {
                @$con->query("ALTER TABLE `campanas_difusion_destinatarios` ADD INDEX `idx_drip_followup` (`id_campana`, `respondido`, `drip_ejecutado`, `fecha_lectura`)");
            }
        }

        @file_put_contents($flag, (string)time());
    }

    /**
     * Guarda o actualiza la regla Drip de una campaña existente con trazabilidad de auditoría.
     */
    public static function saveCampaignDripRule(mysqli $con, int $idCampana, array $cfg, int $idUsuario, ?int $idSedeScope = null): array {
        self::ensureSchema($con);
        if ($idCampana <= 0) {
            return ['ok' => false, 'message' => 'ID de campaña inválido.'];
        }

        $stmtPrev = $con->prepare("SELECT id, id_sede, nombre_campana, drip_activo, drip_horas_espera, drip_accion, drip_template_name, drip_template_language, drip_template_category, drip_params_json FROM campanas_difusion WHERE id = ? LIMIT 1");
        if (!$stmtPrev) {
            return ['ok' => false, 'message' => 'Error preparando lectura de campaña.'];
        }
        $stmtPrev->bind_param("i", $idCampana);
        $stmtPrev->execute();
        $resPrev = $stmtPrev->get_result();
        $antes = ($resPrev && $resPrev->num_rows > 0) ? $resPrev->fetch_assoc() : null;
        $stmtPrev->close();

        if (!$antes) {
            return ['ok' => false, 'message' => 'Campaña no encontrada.'];
        }
        $campSede = intval($antes['id_sede'] ?? 0);
        if ($idSedeScope !== null && $idSedeScope > 0 && $campSede !== $idSedeScope) {
            return ['ok' => false, 'http_code' => 403, 'message' => 'No posee autorización sobre la sede de esta campaña.'];
        }

        $activo = !empty($cfg['drip_activo']) ? 1 : 0;
        $horas  = max(1, min(720, intval($cfg['drip_horas_espera'] ?? 24)));
        $accion = strtoupper(trim((string)($cfg['drip_accion'] ?? 'ASIGNAR_ASESOR')));
        if (!in_array($accion, ['ASIGNAR_ASESOR', 'REIMPACTAR_PLANTILLA', 'AMBOS'], true)) {
            $accion = 'ASIGNAR_ASESOR';
        }
        $tplName = trim((string)($cfg['drip_template_name'] ?? ''));
        $tplLang = trim((string)($cfg['drip_template_language'] ?? 'es')) ?: 'es';
        $tplCat  = strtoupper(trim((string)($cfg['drip_template_category'] ?? 'UTILITY'))) ?: 'UTILITY';
        $paramsRaw = $cfg['drip_params_json'] ?? null;
        if (is_array($paramsRaw)) {
            $paramsJson = json_encode($paramsRaw, JSON_UNESCAPED_UNICODE);
        } elseif (is_string($paramsRaw) && trim($paramsRaw) !== '') {
            $paramsJson = trim($paramsRaw);
        } else {
            $paramsJson = null;
        }

        if ($activo === 1 && in_array($accion, ['REIMPACTAR_PLANTILLA', 'AMBOS'], true) && $tplName === '') {
            return ['ok' => false, 'message' => 'Seleccione la plantilla oficial de seguimiento para la acción de re-impacto.'];
        }

        $stmtUpd = $con->prepare("
            UPDATE campanas_difusion
            SET drip_activo = ?,
                drip_horas_espera = ?,
                drip_accion = ?,
                drip_template_name = ?,
                drip_template_language = ?,
                drip_template_category = ?,
                drip_params_json = ?
            WHERE id = ?
        ");
        if (!$stmtUpd) {
            return ['ok' => false, 'message' => 'Error preparando actualización de regla Drip.'];
        }
        $tplNameParam = ($tplName !== '') ? $tplName : null;
        $stmtUpd->bind_param("iisssssi", $activo, $horas, $accion, $tplNameParam, $tplLang, $tplCat, $paramsJson, $idCampana);
        $ok = $stmtUpd->execute();
        $stmtUpd->close();

        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo guardar la secuencia de remarketing.'];
        }

        $nuevos = [
            'id_campana'             => $idCampana,
            'drip_activo'            => $activo,
            'drip_horas_espera'      => $horas,
            'drip_accion'            => $accion,
            'drip_template_name'     => $tplNameParam,
            'drip_template_language' => $tplLang,
            'drip_template_category' => $tplCat,
        ];

        registrar_auditoria(
            'campanas',
            'CONFIGURAR_SECUENCIA_DRIP',
            "Secuencia Drip de Remarketing actualizada en campaña #{$idCampana} ({$antes['nombre_campana']}): activo={$activo}, espera={$horas}h, acción={$accion}",
            $antes,
            $nuevos,
            $con,
            $idUsuario,
            $campSede
        );

        return [
            'ok'      => true,
            'data'    => $nuevos,
            'rule'    => $nuevos,
            'message' => $activo === 1
                ? "Secuencia Drip activada: seguimiento automático tras {$horas}h de lectura sin respuesta ({$accion})."
                : "Secuencia Drip desactivada para esta campaña.",
        ];
    }

    /**
     * Ejecuta el barrido de Secuencias Automáticas de Remarketing (Drip) para todas las campañas activas
     * (o para una campaña específica si `$onlyCampanaId > 0`).
     * Soporta modo simulación/transacción activa (`$allowHttpDispatch = false` omite llamada cURL externa en tests unitarios).
     */
    public static function processDueDripSequences(
        mysqli $con,
        int $onlyCampanaId = 0,
        int $maxPerCampaign = 50,
        bool $allowHttpDispatch = true
    ): array {
        self::ensureSchema($con);

        if ($onlyCampanaId > 0) {
            $stmtC = $con->prepare("
                SELECT * FROM campanas_difusion
                WHERE id = ? AND drip_activo = 1 AND estado NOT IN ('CANCELADA', 'PENDIENTE_APROBACION')
                LIMIT 1
            ");
            if (!$stmtC) {
                return ['ok' => false, 'evaluated' => 0, 'processed' => 0, 'assigned' => 0, 'reimpacted' => 0, 'skipped_fatigue' => 0, 'skipped_quota' => 0, 'details' => []];
            }
            $stmtC->bind_param("i", $onlyCampanaId);
        } else {
            $stmtC = $con->prepare("
                SELECT * FROM campanas_difusion
                WHERE drip_activo = 1
                  AND aprobado_por IS NOT NULL
                  AND estado NOT IN ('CANCELADA', 'PENDIENTE_APROBACION')
                ORDER BY id ASC
            ");
            if (!$stmtC) {
                return ['ok' => false, 'evaluated' => 0, 'processed' => 0, 'assigned' => 0, 'reimpacted' => 0, 'skipped_fatigue' => 0, 'skipped_quota' => 0, 'details' => []];
            }
        }

        $campanas = [];
        if ($stmtC->execute()) {
            $resC = $stmtC->get_result();
            while ($resC && ($r = $resC->fetch_assoc())) {
                $campanas[] = $r;
            }
        }
        $stmtC->close();

        $totalEvaluated    = 0;
        $totalProcessed    = 0;
        $totalAssigned     = 0;
        $totalReimpact     = 0;
        $totalFatigue      = 0;
        $totalQuotaSkipped = 0;
        $quotaRemBySede    = [];
        $details           = [];

        foreach ($campanas as $camp) {
            $idCamp   = intval($camp['id']);
            $idSede   = intval($camp['id_sede']);
            $horas    = max(1, intval($camp['drip_horas_espera'] ?? 24));
            $accion   = strtoupper(trim((string)($camp['drip_accion'] ?? 'ASIGNAR_ASESOR')));
            $nomCamp  = (string)($camp['nombre_campana'] ?? ('Campaña #' . $idCamp));
            $tplName  = trim((string)($camp['drip_template_name'] ?? ''));
            $tplLang  = trim((string)($camp['drip_template_language'] ?? 'es')) ?: 'es';
            $tplCat   = strtoupper(trim((string)($camp['drip_template_category'] ?? 'UTILITY'))) ?: 'UTILITY';
            $paramsCfg = !empty($camp['drip_params_json']) ? json_decode((string)$camp['drip_params_json'], true) : [];

            // 1. Antes de evaluar destinatarios, sincronizar cualquier respuesta entrante reciente del cliente
            self::syncRecentRepliesBeforeDrip($con, $idCamp);

            // 2. Obtener línea WhatsApp de la sede
            $linea = self::getActiveLineForSede($con, $idSede);
            if (!$linea) {
                continue;
            }

            // 3. Buscar destinatarios que leyeron hace >= $horas horas y siguen con respondido = 0 y drip_ejecutado = 0
            $lim = max(1, min(200, $maxPerCampaign));
            $stmtDue = $con->prepare("
                SELECT d.*
                FROM campanas_difusion_destinatarios d
                WHERE d.id_campana = ?
                  AND IFNULL(d.respondido, 0) = 0
                  AND IFNULL(d.drip_ejecutado, 0) = 0
                  AND (d.estado_envio = 'LEIDO' OR d.fecha_lectura IS NOT NULL)
                  AND IFNULL(d.fecha_lectura, d.fecha_entrega) <= DATE_SUB(NOW(), INTERVAL ? HOUR)
                ORDER BY d.fecha_lectura ASC, d.id ASC
                LIMIT ?
            ");
            if (!$stmtDue) {
                continue;
            }
            $stmtDue->bind_param("iii", $idCamp, $horas, $lim);
            $dueRows = [];
            if ($stmtDue->execute()) {
                $rDue = $stmtDue->get_result();
                while ($rDue && ($dr = $rDue->fetch_assoc())) {
                    $dueRows[] = $dr;
                }
            }
            $stmtDue->close();

            if (empty($dueRows)) {
                continue;
            }

            $totalEvaluated += count($dueRows);
            $campProcessedDelta = 0;

            foreach ($dueRows as $dest) {
                $idDest = intval($dest['id']);
                $sedeDest = intval($dest['id_sede'] ?? 0) > 0 ? intval($dest['id_sede']) : $idSede;
                $lineaDest = ($sedeDest === $idSede) ? $linea : (self::getActiveLineForSede($con, $sedeDest) ?: $linea);
                if (!isset($quotaRemBySede[$sedeDest])) {
                    $quotaRemBySede[$sedeDest] = self::getSedeRemainingDailyQuota($con, $sedeDest);
                }

                $ownTx = !(function_exists('starfi_tx_is_active') && starfi_tx_is_active($con));
                if ($ownTx) {
                    $con->begin_transaction();
                    starfi_tx_set_active($con, true);
                } else {
                    @$con->query("SAVEPOINT sp_drip_dest");
                }

                try {
                    // Candado pesimista SELECT ... FOR UPDATE para evitar doble ejecución entre cron y disparo manual
                    $stmtLock = $con->prepare("
                        SELECT id, id_cliente, id_conversacion, nombre, telefono, respondido, drip_ejecutado, fecha_envio
                        FROM campanas_difusion_destinatarios
                        WHERE id = ?
                        LIMIT 1
                        FOR UPDATE
                    ");
                    $locked = null;
                    if ($stmtLock) {
                        $stmtLock->bind_param("i", $idDest);
                        if ($stmtLock->execute()) {
                            $rLk = $stmtLock->get_result();
                            $locked = ($rLk && $rLk->num_rows > 0) ? $rLk->fetch_assoc() : null;
                        }
                        $stmtLock->close();
                    }

                    if (!$locked || intval($locked['respondido']) === 1 || intval($locked['drip_ejecutado']) === 1) {
                        if ($ownTx) {
                            $con->rollback();
                            starfi_tx_set_active($con, false);
                        } else {
                            @$con->query("ROLLBACK TO SAVEPOINT sp_drip_dest");
                        }
                        continue;
                    }

                    $idCliente = intval($locked['id_cliente'] ?? 0);
                    $idConv    = intval($locked['id_conversacion'] ?? 0);
                    $nomCli    = trim((string)($locked['nombre'] ?? 'Cliente'));
                    $telCli    = trim((string)($locked['telefono'] ?? ''));

                    // Asegurar cliente y conversación activa de la sede para enrutar o registrar el re-impacto
                    if ($idCliente <= 0 && $telCli !== '') {
                        $idCliente = self::ensureClienteContacto($con, intval($lineaDest['id_empresa'] ?? 1), $sedeDest, $telCli, $nomCli);
                    }
                    if ($idConv <= 0 && $idCliente > 0) {
                        $idConv = self::ensureActiveConversation($con, intval($lineaDest['id']), $idCliente);
                    } elseif ($idConv > 0) {
                        // Reabrir a ESPERA_ASIGNACION si estaba RESUELTO/CERRADO y vamos a asignar vendedor
                        if (in_array($accion, ['ASIGNAR_ASESOR', 'AMBOS'], true)) {
                            $stmtReopen = $con->prepare("UPDATE conversaciones SET estado = CASE WHEN estado IN ('CERRADO', 'RESUELTO') THEN 'ESPERA_ASIGNACION' ELSE estado END WHERE id = ?");
                            if ($stmtReopen) {
                                $stmtReopen->bind_param("i", $idConv);
                                @$stmtReopen->execute();
                                $stmtReopen->close();
                            }
                        }
                    }

                    $assignedAdvisorId = null;
                    $assignedAdvisorName = null;
                    $reimpactWamid = null;
                    $finalState = null;

                    // Acción 1: REIMPACTAR CON PLANTILLA OFICIAL META (respetando Cuota Diaria de Sede y Anti-Fatiga 24h CHK-CLD-02)
                    if (in_array($accion, ['REIMPACTAR_PLANTILLA', 'AMBOS'], true) && $tplName !== '') {
                        if (($quotaRemBySede[$sedeDest] ?? 0) <= 0) {
                            $totalQuotaSkipped++;
                            if ($accion === 'REIMPACTAR_PLANTILLA') {
                                if ($ownTx) {
                                    $con->rollback();
                                    starfi_tx_set_active($con, false);
                                } else {
                                    @$con->query("ROLLBACK TO SAVEPOINT sp_drip_dest");
                                }
                                continue;
                            }
                        } elseif ($tplCat === 'MARKETING' && function_exists('starfi_check_marketing_antifatigue_24h') && starfi_check_marketing_antifatigue_24h($con, $telCli, $idDest)) {
                            // Si aún está dentro de las 24h de una plantilla MARKETING previa, posponer sin quemar drip_ejecutado
                            // salvo que también tenga ASIGNAR_ASESOR en horas < 24.
                            if ($accion === 'REIMPACTAR_PLANTILLA') {
                                $totalFatigue++;
                                if ($ownTx) {
                                    $con->rollback();
                                    starfi_tx_set_active($con, false);
                                } else {
                                    @$con->query("ROLLBACK TO SAVEPOINT sp_drip_dest");
                                }
                                continue;
                            }
                        } else {
                            $sendRes = self::dispatchFollowupTemplate(
                                $con,
                                $lineaDest,
                                $idConv,
                                $telCli,
                                $nomCli,
                                $tplName,
                                $tplLang,
                                $paramsCfg,
                                $nomCamp,
                                $horas,
                                $allowHttpDispatch
                            );
                            if ($sendRes['ok']) {
                                $reimpactWamid = $sendRes['wamid'];
                                $finalState = 'REIMPACTADO';
                                $totalReimpact++;
                                $quotaRemBySede[$sedeDest] = max(0, intval($quotaRemBySede[$sedeDest]) - 1);
                            } elseif ($accion === 'REIMPACTAR_PLANTILLA') {
                                $finalState = 'FALLIDO_REIMPACTO';
                            }
                        }
                    }

                    // Acción 2: ASIGNAR A VENDEDOR HUMANO (ROUND-ROBIN CON FALLBACK A MENOR CARGA ACTIVA DE LA SEDE)
                    if (in_array($accion, ['ASIGNAR_ASESOR', 'AMBOS'], true) && $idConv > 0) {
                        $rr = starfi_assign_round_robin_advisor($con, $sedeDest, $idConv, $idCliente, "Remarketing Drip +{$horas}h ({$nomCamp})", 5);
                        if (!$rr) {
                            $rr = self::assignFallbackSedeAdvisor($con, $sedeDest, $idConv, $idCliente, $nomCamp, $horas);
                        }
                        if ($rr && !empty($rr['id'])) {
                            $assignedAdvisorId = intval($rr['id']);
                            $assignedAdvisorName = (string)($rr['nombre'] ?? ('Asesor #' . $assignedAdvisorId));
                        } else {
                            // Dejar la conversación en ESPERA_ASIGNACION + prioridad en el embudo de la sede
                            if ($idCliente > 0) {
                                starfi_ensure_pipeline_lead($con, $sedeDest, $idCliente, $idConv, null, "Lead Remarketing: {$nomCli}");
                            }
                        }

                        // Registrar nota interna de contexto para el vendedor en Bandeja
                        $notaDrip = "🎯 [SECUENCIA DRIP REMARKETING]: El cliente leyó la campaña \"{$nomCamp}\" hace más de {$horas}h sin responder."
                                  . ($assignedAdvisorName ? " Asignado a {$assignedAdvisorName} para contacto comercial." : " En cola prioritaria de la sede para seguimiento comercial.");
                        $stmtNota = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, 'NOTA_INTERNA', 'TEXTO', ?)");
                        if ($stmtNota) {
                            $stmtNota->bind_param("is", $idConv, $notaDrip);
                            if (@$stmtNota->execute()) {
                                $nid = intval($stmtNota->insert_id);
                                $stmtUm = $con->prepare("UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?), mensajes_no_leidos = GREATEST(IFNULL(mensajes_no_leidos, 0), 1) WHERE id = ?");
                                if ($stmtUm) {
                                    $stmtUm->bind_param("ii", $nid, $idConv);
                                    @$stmtUm->execute();
                                    $stmtUm->close();
                                }
                            }
                            $stmtNota->close();
                        }

                        $finalState = ($finalState === 'REIMPACTADO') ? 'REIMPACTADO_Y_ASIGNADO' : 'ASIGNADO_ASESOR';
                        $totalAssigned++;
                    }

                    if ($finalState === null) {
                        $finalState = 'PROCESADO';
                    }

                    $stmtMark = $con->prepare("
                        UPDATE campanas_difusion_destinatarios
                        SET drip_ejecutado = 1,
                            drip_estado = ?,
                            drip_fecha = NOW(),
                            drip_id_asesor = ?,
                            drip_id_mensaje_meta = ?,
                            id_cliente = CASE WHEN id_cliente = 0 AND ? > 0 THEN ? ELSE id_cliente END,
                            id_conversacion = CASE WHEN id_conversacion = 0 AND ? > 0 THEN ? ELSE id_conversacion END
                        WHERE id = ?
                    ");
                    if ($stmtMark) {
                        $stmtMark->bind_param(
                            "sisiiiii",
                            $finalState,
                            $assignedAdvisorId,
                            $reimpactWamid,
                            $idCliente,
                            $idCliente,
                            $idConv,
                            $idConv,
                            $idDest
                        );
                        $stmtMark->execute();
                        $stmtMark->close();
                    }

                    if ($ownTx) {
                        $con->commit();
                        starfi_tx_set_active($con, false);
                    }

                    $totalProcessed++;
                    $campProcessedDelta++;
                    $details[] = [
                        'id_campana' => $idCamp,
                        'id_dest'    => $idDest,
                        'telefono'   => $telCli,
                        'estado'     => $finalState,
                        'id_asesor'  => $assignedAdvisorId,
                    ];
                } catch (Throwable $e) {
                    if ($ownTx) {
                        @$con->rollback();
                        starfi_tx_set_active($con, false);
                    } else {
                        @$con->query("ROLLBACK TO SAVEPOINT sp_drip_dest");
                    }
                    starfi_log_exception('campanas', 'Error procesando destinatario Drip #' . $idDest . ': ' . $e->getMessage());
                }
            }

            if ($campProcessedDelta > 0) {
                $stmtCnt = $con->prepare("UPDATE campanas_difusion SET drip_procesados = IFNULL(drip_procesados, 0) + ? WHERE id = ?");
                if ($stmtCnt) {
                    $stmtCnt->bind_param("ii", $campProcessedDelta, $idCamp);
                    @$stmtCnt->execute();
                    $stmtCnt->close();
                }

                registrar_auditoria(
                    'campanas',
                    'EJECUCION_DRIP_REMARKETING',
                    "Secuencia Drip ejecutada en campaña #{$idCamp} ({$nomCamp}): {$campProcessedDelta} destinatarios leídos sin respuesta procesados ({$accion})",
                    null,
                    [
                        'id_campana'        => $idCamp,
                        'horas_espera'      => $horas,
                        'accion'            => $accion,
                        'procesados_tanda'  => $campProcessedDelta,
                    ],
                    $con,
                    null,
                    $idSede
                );
            }
        }

        return [
            'ok'              => true,
            'evaluated'       => $totalEvaluated,
            'processed'       => $totalProcessed,
            'assigned'        => $totalAssigned,
            'reimpacted'      => $totalReimpact,
            'skipped_fatigue' => $totalFatigue,
            'skipped_quota'   => $totalQuotaSkipped,
            'details'         => $details,
        ];
    }

    /**
     * Calcula el cupo diario disponible de mensajes salientes WABA para una sede (CHK-CLD-02).
     */
    public static function getSedeRemainingDailyQuota(mysqli $con, int $idSede): int {
        if ($idSede <= 0) {
            return 250;
        }
        $limite = 250;
        $stmtLim = $con->prepare("
            SELECT COALESCE(q.limite_diario_mensajes, l.limite_solicitudes, 250) AS lim
            FROM sedes s
            LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id AND l.estado = 'ACTIVO'
            LEFT JOIN campanas_cuotas_sede q ON q.id_sede = s.id
            WHERE s.id = ?
            LIMIT 1
        ");
        if ($stmtLim) {
            $stmtLim->bind_param("i", $idSede);
            if (@$stmtLim->execute()) {
                $rLim = $stmtLim->get_result();
                if ($rLim && ($rowL = $rLim->fetch_assoc())) {
                    $limite = max(1, intval($rowL['lim']));
                }
            }
            $stmtLim->close();
        }

        $enviadosHoy = 0;
        $stmtSent = $con->prepare("
            SELECT COUNT(*) AS c
            FROM campanas_difusion_destinatarios
            WHERE id_sede = ?
              AND (
                  (estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE())
                  OR (drip_ejecutado = 1 AND drip_estado IN ('REIMPACTADO','REIMPACTADO_Y_ASIGNADO') AND DATE(drip_fecha) = CURDATE())
              )
        ");
        if ($stmtSent) {
            $stmtSent->bind_param("i", $idSede);
            if (@$stmtSent->execute()) {
                $rS = $stmtSent->get_result();
                if ($rS && ($rowS = $rS->fetch_assoc())) {
                    $enviadosHoy = intval($rowS['c']);
                }
            }
            $stmtSent->close();
        }

        return max(0, $limite - $enviadosHoy);
    }

    /**
     * Resuelve de forma segura las variables del cuerpo de una plantilla Drip (`body_params`, `body_vars` o arreglo plano)
     * sin producir jamás conversiones `Array to string` y sustituyendo `@NOMBRE_CLIENTE` y `@NOMBRE_SEDE` / `@SEDE`.
     */
    public static function resolveBodyParamsList($paramsCfg, string $nombreCliente, string $nombreSede): array {
        if (is_string($paramsCfg) && trim($paramsCfg) !== '') {
            $decoded = json_decode($paramsCfg, true);
            $paramsCfg = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($paramsCfg) || empty($paramsCfg)) {
            return [];
        }

        if (isset($paramsCfg['body_params']) && is_array($paramsCfg['body_params'])) {
            $rawList = $paramsCfg['body_params'];
        } elseif (isset($paramsCfg['body_vars']) && is_array($paramsCfg['body_vars'])) {
            $rawList = $paramsCfg['body_vars'];
        } else {
            $rawList = [];
            foreach ($paramsCfg as $item) {
                if (is_scalar($item)) {
                    $rawList[] = $item;
                } elseif (is_array($item)) {
                    foreach ($item as $sub) {
                        if (is_scalar($sub)) {
                            $rawList[] = $sub;
                        }
                    }
                }
            }
        }

        $nomClean = trim($nombreCliente) !== '' ? trim($nombreCliente) : 'Cliente';
        $sedeClean = trim($nombreSede) !== '' ? trim($nombreSede) : 'nuestra sede';

        $bodyParams = [];
        foreach ($rawList as $v) {
            if (!is_scalar($v)) {
                continue;
            }
            $val = trim((string)$v);
            if ($val === '') {
                continue;
            }
            $val = str_ireplace(['@NOMBRE_CLIENTE', '{{nombre_cliente}}', '{{nombre}}'], $nomClean, $val);
            $val = str_ireplace(['@NOMBRE_COMPLETO', '{{nombre_completo}}'], $nomClean, $val);
            $val = str_ireplace(['@NOMBRE_SEDE', '@SEDE', '{{nombre_sede}}', '{{sede}}'], $sedeClean, $val);
            $val = trim(preg_replace('/\s{2,}/', ' ', str_replace(["\r\n", "\r", "\n", "\t"], ' ', $val)));
            if ($val !== '') {
                $bodyParams[] = ['type' => 'text', 'text' => $val];
            }
        }
        return $bodyParams;
    }

    private static function syncRecentRepliesBeforeDrip(mysqli $con, int $idCampana): void {
        $stmt = $con->prepare("
            UPDATE campanas_difusion_destinatarios d
            INNER JOIN mensajes_y_eventos m
               ON m.id_conversacion = d.id_conversacion
              AND m.origen = 'CLIENTE'
              AND m.timestamp >= d.fecha_envio
            SET d.respondido = 1,
                d.estado_envio = 'RESPONDIDO',
                d.fecha_respuesta = IFNULL(d.fecha_respuesta, m.timestamp)
            WHERE d.id_campana = ?
              AND d.respondido = 0
              AND d.id_conversacion > 0
              AND d.fecha_envio IS NOT NULL
        ");
        if ($stmt) {
            $stmt->bind_param("i", $idCampana);
            @$stmt->execute();
            $stmt->close();
        }
    }

    private static function getActiveLineForSede(mysqli $con, int $idSede): ?array {
        if ($idSede <= 0) {
            return null;
        }
        $stmt = $con->prepare("
            SELECT l.id, l.id_sede, l.meta_app_id, l.meta_token, COALESCE(s.id_empresa, 1) AS id_empresa, COALESCE(s.nombre_sede, 'Sede') AS nombre_sede
            FROM lineas_whatsapp l
            LEFT JOIN sedes s ON s.id = l.id_sede
            WHERE l.id_sede = ? AND l.estado = 'ACTIVO'
            LIMIT 1
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $idSede);
        $row = null;
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
            }
        }
        $stmt->close();
        return $row ?: null;
    }

    public static function ensureClienteContacto(mysqli $con, int $idEmpresa, int $idSede, string $telefono, string $nombre): int {
        $stmt = $con->prepare("SELECT id FROM clientes_contactos WHERE numero_whatsapp = ? AND id_sede = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("si", $telefono, $idSede);
            if ($stmt->execute()) {
                $r = $stmt->get_result();
                if ($r && ($row = $r->fetch_assoc())) {
                    $stmt->close();
                    return intval($row['id']);
                }
            }
            $stmt->close();
        }
        $stmtIns = $con->prepare("INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre) VALUES (?, ?, ?, ?)");
        if ($stmtIns) {
            $nomParam = $nombre !== '' ? $nombre : 'Cliente';
            $stmtIns->bind_param("iiss", $idEmpresa, $idSede, $telefono, $nomParam);
            if (@$stmtIns->execute()) {
                $newId = intval($stmtIns->insert_id);
                $stmtIns->close();
                return $newId;
            }
            $stmtIns->close();
        }
        return 0;
    }

    private static function ensureActiveConversation(mysqli $con, int $idLinea, int $idCliente): int {
        $stmt = $con->prepare("SELECT id FROM conversaciones WHERE id_cliente = ? AND id_linea = ? ORDER BY (estado NOT IN ('CERRADO','RESUELTO')) DESC, id DESC LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("ii", $idCliente, $idLinea);
            if ($stmt->execute()) {
                $r = $stmt->get_result();
                if ($r && ($row = $r->fetch_assoc())) {
                    $cid = intval($row['id']);
                    $stmt->close();
                    $stmtUp = $con->prepare("UPDATE conversaciones SET estado = CASE WHEN estado IN ('CERRADO','RESUELTO') THEN 'ESPERA_ASIGNACION' ELSE estado END WHERE id = ?");
                    if ($stmtUp) {
                        $stmtUp->bind_param("i", $cid);
                        @$stmtUp->execute();
                        $stmtUp->close();
                    }
                    return $cid;
                }
            }
            $stmt->close();
        }
        $stmtIns = $con->prepare("INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos, fecha_inicio) VALUES (?, ?, 'ESPERA_ASIGNACION', 1, NOW())");
        if ($stmtIns) {
            $stmtIns->bind_param("ii", $idLinea, $idCliente);
            if ($stmtIns->execute()) {
                $newCid = intval($stmtIns->insert_id);
                $stmtIns->close();
                return $newCid;
            }
            $stmtIns->close();
        }
        return 0;
    }

    /**
     * Si ningún asesor estuvo activo en los últimos 5 min (p.ej. cron nocturno/matutino),
     * asigna bajo `FOR UPDATE` al asesor ACTIVO y `disponible_chat = 1` con menor carga en esa sede.
     */
    private static function assignFallbackSedeAdvisor(mysqli $con, int $idSede, int $idConv, int $idCliente, string $nomCamp, int $horas): ?array {
        $stmt = $con->prepare("
            SELECT u.id,
                   COALESCE(up.nombre, u.usuario) AS nombre,
                   COALESCE(up.telefono, '') AS telefono,
                   (SELECT COUNT(*) FROM conversaciones ca WHERE ca.id_agente = u.id AND ca.estado NOT IN ('CERRADO', 'RESUELTO')) AS chats_activos
            FROM usuario u
            LEFT JOIN usuario_perfil up ON up.id_usuario = u.id
            LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
            WHERE u.id_sede = ?
              AND u.estado = 'ACTIVO'
              AND IFNULL(u.disponible_chat, 1) = 1
              AND u.id > 1
              AND UPPER(IFNULL(r.nombre, '')) NOT IN ('MASTER', 'MASTER CI')
            ORDER BY chats_activos ASC, (u.ultima_asignacion_rr IS NULL) DESC, u.ultima_asignacion_rr ASC, u.id ASC
            LIMIT 1
            FOR UPDATE
        ");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $idSede);
        $elegido = null;
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $elegido = $res->fetch_assoc();
            }
        }
        $stmt->close();

        if (!$elegido) {
            return null;
        }
        $aid = intval($elegido['id']);
        $anom = trim((string)$elegido['nombre']);

        $stmtUpU = $con->prepare("UPDATE usuario SET ultima_asignacion_rr = NOW() WHERE id = ?");
        if ($stmtUpU) {
            $stmtUpU->bind_param("i", $aid);
            @$stmtUpU->execute();
            $stmtUpU->close();
        }
        $stmtUpC = $con->prepare("UPDATE conversaciones SET id_agente = ?, estado = 'ATENDIENDO' WHERE id = ?");
        if ($stmtUpC) {
            $stmtUpC->bind_param("ii", $aid, $idConv);
            @$stmtUpC->execute();
            $stmtUpC->close();
        }
        if ($idCliente > 0) {
            starfi_ensure_pipeline_lead($con, $idSede, $idCliente, $idConv, $aid, "Seguimiento Drip: {$nomCamp}");
        }
        return [
            'id'       => $aid,
            'nombre'   => $anom,
            'telefono' => (string)$elegido['telefono'],
        ];
    }

    private static function dispatchFollowupTemplate(
        mysqli $con,
        array $linea,
        int $idConv,
        string $telefono,
        string $nombreCliente,
        string $tplName,
        string $tplLang,
        $paramsCfg,
        string $nomCamp,
        int $horas,
        bool $allowHttpDispatch
    ): array {
        $phoneId = trim((string)($linea['meta_app_id'] ?? ''));
        $token   = trim((string)($linea['meta_token'] ?? ''));
        if ($token === '' && defined('META_GLOBAL_TOKEN') && META_GLOBAL_TOKEN !== '') {
            $token = (string)META_GLOBAL_TOKEN;
        }

        $bodyParams = self::resolveBodyParamsList(
            $paramsCfg,
            $nombreCliente,
            (string)($linea['nombre_sede'] ?? 'nuestra sede')
        );

        $components = [];
        if (!empty($bodyParams)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => $bodyParams,
            ];
        }

        $telDigits = preg_replace('/[^0-9]/', '', $telefono);
        if (strlen($telDigits) === 11 && $telDigits[0] === '0') {
            $telDigits = '58' . substr($telDigits, 1);
        }

        $resolvedTexts = array_map(static function ($p) {
            return (string)($p['text'] ?? '');
        }, $bodyParams);

        if (!$allowHttpDispatch) {
            $simWamid = 'wamid.drip_sim_' . bin2hex(random_bytes(6));
            if ($idConv > 0) {
                $resTxt = "🔄 [RE-IMPACTO DRIP +{$horas}h · {$tplName}] Seguimiento automático de campaña '{$nomCamp}'"
                        . (!empty($resolvedTexts) ? ' | Params: ' . json_encode($resolvedTexts, JSON_UNESCAPED_UNICODE) : '');
                $stmtM = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, tipo, origen, contenido, estado_envio) VALUES (?, ?, 'TEXTO', 'API_TRANSACCIONAL', ?, 'ENVIADO')");
                if ($stmtM) {
                    $stmtM->bind_param("iss", $idConv, $simWamid, $resTxt);
                    @$stmtM->execute();
                    $stmtM->close();
                }
            }
            return ['ok' => true, 'wamid' => $simWamid, 'resolved_params' => $resolvedTexts];
        }

        if ($phoneId === '' || $token === '' || $telDigits === '') {
            return ['ok' => false, 'wamid' => null];
        }

        $apiVer = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
        $url = "https://graph.facebook.com/{$apiVer}/" . rawurlencode($phoneId) . "/messages";
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $telDigits,
            'type'              => 'template',
            'template'          => [
                'name'     => $tplName,
                'language' => ['code' => $tplLang],
            ],
        ];
        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json",
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $raw = curl_exec($ch);
        $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        curl_close($ch);

        $data = json_decode((string)$raw, true);
        $wamid = isset($data['messages'][0]['id']) ? (string)$data['messages'][0]['id'] : null;

        if ($httpCode >= 200 && $httpCode < 300 && $wamid !== null) {
            if ($idConv > 0) {
                $resTxt = "🔄 [RE-IMPACTO DRIP +{$horas}h · {$tplName}] Seguimiento automático de campaña '{$nomCamp}'";
                $stmtM = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, tipo, origen, contenido, estado_envio) VALUES (?, ?, 'TEXTO', 'API_TRANSACCIONAL', ?, 'ENVIADO')");
                if ($stmtM) {
                    $stmtM->bind_param("iss", $idConv, $wamid, $resTxt);
                    if (@$stmtM->execute()) {
                        $mid = intval($stmtM->insert_id);
                        $stmtUm = $con->prepare("UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?) WHERE id = ?");
                        if ($stmtUm) {
                            $stmtUm->bind_param("ii", $mid, $idConv);
                            @$stmtUm->execute();
                            $stmtUm->close();
                        }
                    }
                    $stmtM->close();
                }
            }
            return ['ok' => true, 'wamid' => $wamid];
        }

        return ['ok' => false, 'wamid' => null];
    }
}
