<?php
// cron_campanas_autopilot.php
// Ejecutor Diario de Piloto Automático para Campañas de Difusión APROBADAS | STARFI CRM (QA-STD-GLOBAL-2.1)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/audit.php';

$con = getDbConnection();
if (!($con instanceof mysqli)) {
    echo "Error de conexión a la base de datos.\n";
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . "] Iniciando Piloto Automático Diario de Campañas de Difusión...\n";

function normalizeVePhoneCron($rawPhone) {
    $tel = preg_replace('/[^0-9]/', '', (string)$rawPhone);
    if (strlen($tel) === 11 && strpos($tel, '0') === 0) {
        $tel = '58' . substr($tel, 1);
    } elseif (strlen($tel) === 13 && strpos($tel, '580') === 0) {
        $tel = '58' . substr($tel, 3);
    } elseif (strlen($tel) === 10 && preg_match('/^(414|424|412|416|426|2[0-9]{2})[0-9]{7}$/', $tel)) {
        $tel = '58' . $tel;
    }
    return $tel;
}

function sanitizeParamCron($text, $stripTrailingDot = false) {
    $clean = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$text);
    $clean = preg_replace('/\s{2,}/', ' ', $clean);
    $clean = trim($clean);
    if ($stripTrailingDot) {
        $clean = rtrim($clean, '.');
        $clean = trim($clean);
    }
    return $clean !== '' ? $clean : 'N/A';
}

if (!function_exists('starfi_marketing_category_sql_expr')) {
    function starfi_marketing_category_sql_expr(mysqli $con): string {
        static $cache = [];
        $cid = spl_object_id($con);
        if (isset($cache[$cid])) {
            return $cache[$cid];
        }
        $hasCatMeta = false;
        $hasTplCat = false;
        $resCols = @$con->query('SHOW COLUMNS FROM `campanas_difusion`');
        if ($resCols) {
            while ($col = $resCols->fetch_assoc()) {
                $field = (string)($col['Field'] ?? '');
                if ($field === 'categoria_meta') {
                    $hasCatMeta = true;
                } elseif ($field === 'template_category') {
                    $hasTplCat = true;
                }
            }
        }
        if ($hasCatMeta && $hasTplCat) {
            $expr = 'UPPER(COALESCE(NULLIF(c.categoria_meta, \'\'), NULLIF(c.template_category, \'\'), \'MARKETING\')) = \'MARKETING\'';
        } elseif ($hasCatMeta) {
            $expr = 'UPPER(IFNULL(NULLIF(c.categoria_meta, \'\'), \'MARKETING\')) = \'MARKETING\'';
        } elseif ($hasTplCat) {
            $expr = 'UPPER(IFNULL(NULLIF(c.template_category, \'\'), \'MARKETING\')) = \'MARKETING\'';
        } else {
            $expr = '1 = 1';
        }
        $cache[$cid] = $expr;
        return $expr;
    }
}

if (!function_exists('starfi_check_marketing_antifatigue_24h')) {
    /**
     * Escudo Anti-Fatiga 24h (Meta Error 131049) en Backend:
     * Verifica si el teléfono (o sus variantes con/sin + o formato local) recibió algún envío
     * de una campaña MARKETING en las últimas 24 horas sin haber respondido.
     */
    function starfi_check_marketing_antifatigue_24h(mysqli $con, string $telefono, int $excludeDestId = 0): bool {
        $norm = normalizeVePhoneCron($telefono);
        if ($norm === '') {
            return false;
        }
        $v1 = $norm;
        $v2 = '+' . $norm;
        $v3 = (strlen($norm) === 12 && strpos($norm, '58') === 0) ? ('0' . substr($norm, 2)) : $norm;
        $rawClean = preg_replace('/[^0-9+]/', '', trim($telefono));
        $v4 = ($rawClean !== '') ? $rawClean : $norm;
        $excl = max(0, intval($excludeDestId));
        $catExpr = starfi_marketing_category_sql_expr($con);

        $sql = 'SELECT d.id
                FROM campanas_difusion_destinatarios d
                INNER JOIN campanas_difusion c ON c.id = d.id_campana
                WHERE ' . $catExpr . '
                  AND d.telefono IN (?, ?, ?, ?)
                  AND d.fecha_envio >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND d.estado_envio IN (\'PROCESANDO\', \'ENVIADO\', \'ENTREGADO\', \'LEIDO\')
                  AND IFNULL(d.respondido, 0) = 0
                  AND d.id != ?
                LIMIT 1';

        $stmt = $con->prepare($sql);
        if (!$stmt) {
            starfi_log_exception('campanas', 'Error preparando verificación anti-fatiga 24h en cron', ['error' => $con->error]);
            return false;
        }
        $stmt->bind_param('ssssi', $v1, $v2, $v3, $v4, $excl);
        $fatigued = false;
        if (@$stmt->execute()) {
            $stmt->store_result();
            $fatigued = ($stmt->num_rows > 0);
        }
        $stmt->close();
        return $fatigued;
    }
}

function stripLeadingAppSubpathCron($path) {
    $norm = ltrim(str_replace('\\', '/', trim((string)$path)), '/');
    if (strpos($norm, 'starfi_crm/') === 0) {
        $norm = substr($norm, strlen('starfi_crm/'));
    }
    return ltrim($norm, '/');
}

function resolveCampaignImageCron($con, $camp) {
    $baseDir = __DIR__;
    if (!empty($camp['header_imagen_path'])) {
        $rel = stripLeadingAppSubpathCron($camp['header_imagen_path']);
        if ($rel !== '') {
            $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (file_exists($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }
    }

    if ($con instanceof mysqli) {
        $stmtEmp = $con->prepare('SELECT logo_url FROM empresa_perfil WHERE logo_url IS NOT NULL AND logo_url != \'\' ORDER BY id ASC LIMIT 1');
        if ($stmtEmp) {
            if (@$stmtEmp->execute()) {
                $resEmp = $stmtEmp->get_result();
                if ($resEmp && ($rowEmp = $resEmp->fetch_assoc())) {
                    $logo_rel = stripLeadingAppSubpathCron($rowEmp['logo_url'] ?? '');
                    if ($logo_rel !== '') {
                        $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logo_rel);
                        if (file_exists($candidate) && is_file($candidate)) {
                            $stmtEmp->close();
                            return $candidate;
                        }
                    }
                }
            }
            $stmtEmp->close();
        }
    }

    return null;
}

function getOrUploadHeaderMediaIdCron($con, array &$camp, string $phone_id, string $token): ?string {
    $existing_id = trim((string)($camp['header_media_id'] ?? ''));
    if ($existing_id !== '') {
        return $existing_id;
    }
    $img_path = resolveCampaignImageCron($con, $camp);
    if (!$img_path || !file_exists($img_path) || !is_file($img_path)) {
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower(trim((string)$finfo->file($img_path)));
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return null;
    }
    $apiVer = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
    $media_url = "https://graph.facebook.com/{$apiVer}/{$phone_id}/media";
    $cfile = new CURLFile($img_path, $mime, basename($img_path));
    $post_media = ['messaging_product' => 'whatsapp', 'type' => $mime, 'file' => $cfile];

    $ch = curl_init($media_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_media);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if (!empty($data['id'])) {
        $media_id = trim((string)$data['id']);
        $camp['header_media_id'] = $media_id;
        $id_camp = intval($camp['id'] ?? 0);
        if ($id_camp > 0 && ($con instanceof mysqli)) {
            $stmtUpd = $con->prepare('UPDATE campanas_difusion SET header_media_id = ? WHERE id = ?');
            if ($stmtUpd) {
                $stmtUpd->bind_param('si', $media_id, $id_camp);
                @$stmtUpd->execute();
                $stmtUpd->close();
            }
        }
        return $media_id;
    }
    return null;
}

function recalcularCampanaCron($con, $id_campana) {
    $id_campana = intval($id_campana);
    if (!($con instanceof mysqli) || $id_campana <= 0) {
        return;
    }

    $stmtSync = $con->prepare(
        'UPDATE campanas_difusion_destinatarios d
         INNER JOIN campanas_difusion c ON c.id = d.id_campana
         INNER JOIN mensajes_y_eventos m ON m.id_mensaje_meta = CONVERT(d.id_mensaje_meta USING utf8mb4)
         SET d.estado_envio = CASE
                 WHEN m.estado_envio = \'FALLIDO\' THEN \'FALLIDO\'
                 WHEN d.respondido = 1 THEN \'RESPONDIDO\'
                 WHEN m.estado_envio = \'LEIDO\' THEN \'LEIDO\'
                 WHEN m.estado_envio = \'ENTREGADO\' AND d.estado_envio NOT IN (\'LEIDO\', \'RESPONDIDO\') THEN \'ENTREGADO\'
                 ELSE d.estado_envio
             END,
             d.fecha_entrega = CASE
                 WHEN m.estado_envio IN (\'ENTREGADO\', \'LEIDO\') THEN IFNULL(d.fecha_entrega, IFNULL(m.timestamp, NOW()))
                 ELSE d.fecha_entrega
             END,
             d.fecha_lectura = CASE
                 WHEN m.estado_envio = \'LEIDO\' THEN IFNULL(d.fecha_lectura, IFNULL(m.timestamp, NOW()))
                 ELSE d.fecha_lectura
             END,
             d.error_detalle = CASE
                 WHEN m.estado_envio = \'FALLIDO\' AND m.error_detalle IS NOT NULL AND m.error_detalle != \'\' THEN m.error_detalle
                 ELSE d.error_detalle
             END,
             d.costo_usd = CASE
                 WHEN m.estado_envio = \'FALLIDO\' THEN 0.0000
                 WHEN d.costo_usd <= 0 AND c.costo_unitario_usd > 0 THEN c.costo_unitario_usd
                 ELSE d.costo_usd
             END
         WHERE d.id_campana = ?
           AND d.id_mensaje_meta IS NOT NULL
           AND d.id_mensaje_meta != \'\'
           AND d.estado_envio IN (\'ENVIADO\', \'ENTREGADO\', \'LEIDO\')'
    );
    if ($stmtSync) {
        $stmtSync->bind_param('i', $id_campana);
        @$stmtSync->execute();
        $stmtSync->close();
    }

    $stmtAgg = $con->prepare(
        'UPDATE campanas_difusion c
         INNER JOIN (
             SELECT id_campana,
                    COUNT(*) AS tot,
                    COALESCE(SUM(estado_envio = \'PENDIENTE\'), 0) AS pend,
                    COALESCE(SUM(estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')), 0) AS env,
                    COALESCE(SUM(fecha_entrega IS NOT NULL OR estado_envio IN (\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')), 0) AS ent,
                    COALESCE(SUM(fecha_lectura IS NOT NULL OR estado_envio IN (\'LEIDO\',\'RESPONDIDO\')), 0) AS lei,
                    COALESCE(SUM(respondido = 1), 0) AS resp,
                    COALESCE(SUM(estado_envio = \'FALLIDO\'), 0) AS fall,
                    IFNULL(SUM(CASE WHEN estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') THEN costo_usd ELSE 0 END), 0) AS costo_real
             FROM campanas_difusion_destinatarios
             WHERE id_campana = ?
             GROUP BY id_campana
         ) agg ON agg.id_campana = c.id
         SET c.total_destinatarios = agg.tot,
             c.pendientes = agg.pend,
             c.enviados = agg.env,
             c.entregados = agg.ent,
             c.leidos = agg.lei,
             c.respondidos = agg.resp,
             c.fallidos = agg.fall,
             c.costo_real_usd = agg.costo_real
         WHERE c.id = ?'
    );
    if ($stmtAgg) {
        $stmtAgg->bind_param('ii', $id_campana, $id_campana);
        if (!@$stmtAgg->execute()) {
            starfi_log_exception('campanas', 'Error recalculando métricas de campaña en cron', ['id_campana' => $id_campana, 'error' => $stmtAgg->error]);
        }
        $stmtAgg->close();
    }
}

// Liberar filas atascadas en PROCESANDO por más de 3 minutos sin wamid
$stmtStuck = $con->prepare(
    'UPDATE campanas_difusion_destinatarios
     SET estado_envio = \'PENDIENTE\'
     WHERE estado_envio = \'PROCESANDO\'
       AND (id_mensaje_meta IS NULL OR id_mensaje_meta = \'\')
       AND fecha_envio < DATE_SUB(NOW(), INTERVAL 3 MINUTE)'
);
if ($stmtStuck) {
    @$stmtStuck->execute();
    $stmtStuck->close();
}

// Buscar únicamente campañas APROBADAS con Piloto Automático activo, destinatarios pendientes, cuya hora programada ya llegó
$stmtCamps = $con->prepare(
    'SELECT * FROM campanas_difusion c
     WHERE c.piloto_automatico = 1
       AND c.aprobado_por IS NOT NULL
       AND c.pendientes > 0
       AND IFNULL(c.hora_programada, \'00:00:00\') <= CURTIME()
       AND c.estado NOT IN (\'PENDIENTE_APROBACION\', \'CANCELADA\', \'COMPLETADA\', \'TOPE_PRESUPUESTO\')
       AND NOT EXISTS (
           SELECT 1 FROM campanas_difusion_destinatarios d_act
           WHERE d_act.id_campana = c.id
             AND d_act.fecha_envio >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
       )
     ORDER BY c.id ASC'
);

$campanas_pendientes = [];
if ($stmtCamps) {
    if (@$stmtCamps->execute()) {
        $resCamps = $stmtCamps->get_result();
        if ($resCamps) {
            while ($rCamp = $resCamps->fetch_assoc()) {
                $campanas_pendientes[] = $rCamp;
            }
        }
    }
    $stmtCamps->close();
}

if (empty($campanas_pendientes)) {
    echo "No hay campañas aprobadas con cuotas pendientes para hoy.\n";
}

$apiVer = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';

foreach ($campanas_pendientes as $camp) {
    $camp_antes = $camp;
    $id_campana = intval($camp['id']);
    $id_sede = intval($camp['id_sede']);
    $id_usuario = intval($camp['aprobado_por'] ?: $camp['id_usuario'] ?: 1);
    $nombre_campana = (string)$camp['nombre_campana'];

    echo "Procesando Campaña #{$id_campana}: {$nombre_campana} (Sede ID: {$id_sede})...\n";

    // Obtener configuración de flujo diario de la sede
    $lim_sede = 250;
    $cad_ms = 1000;
    $stmtQ = $con->prepare('SELECT limite_diario_mensajes, cadencia_ms FROM campanas_cuotas_sede WHERE id_sede = ? LIMIT 1');
    if ($stmtQ) {
        $stmtQ->bind_param('i', $id_sede);
        if (@$stmtQ->execute()) {
            $resQ = $stmtQ->get_result();
            if ($resQ && ($rq = $resQ->fetch_assoc())) {
                $lim_sede = max(1, intval($rq['limite_diario_mensajes']));
                $cad_ms = max(250, intval($rq['cadencia_ms']));
            }
        }
        $stmtQ->close();
    }

    $cuota_camp = max(1, intval($camp['cuota_diaria_programada'] ?: $lim_sede));
    if ($cuota_camp > $lim_sede) {
        $lim_sede = $cuota_camp;
    }
    $tope_hoy = min($lim_sede, $cuota_camp);

    // Verificar enviados hoy en la sede y en la campaña
    $env_sede_hoy = 0;
    $stmtHs = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
    if ($stmtHs) {
        $stmtHs->bind_param('i', $id_sede);
        if (@$stmtHs->execute()) {
            $rHs = $stmtHs->get_result();
            if ($rHs && ($r1 = $rHs->fetch_assoc())) {
                $env_sede_hoy = intval($r1['c']);
            }
        }
        $stmtHs->close();
    }

    $env_camp_hoy = 0;
    $stmtHc = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
    if ($stmtHc) {
        $stmtHc->bind_param('i', $id_campana);
        if (@$stmtHc->execute()) {
            $rHc = $stmtHc->get_result();
            if ($rHc && ($r2 = $rHc->fetch_assoc())) {
                $env_camp_hoy = intval($r2['c']);
            }
        }
        $stmtHc->close();
    }

    $disp_hoy = min(max(0, $lim_sede - $env_sede_hoy), max(0, $tope_hoy - $env_camp_hoy));
    if ($disp_hoy <= 0) {
        echo " - Cuota diaria de hoy ya alcanzada para esta campaña/sede. Se omite hasta mañana.\n";
        continue;
    }

    // Obtener línea activa de la sede
    $linea = null;
    $stmtLin = $con->prepare(
        'SELECT l.id, l.meta_app_id, l.meta_token, COALESCE(s.id_empresa, 1) AS id_empresa, COALESCE(s.nombre_sede, \'Sede\') AS nombre_sede
         FROM lineas_whatsapp l
         LEFT JOIN sedes s ON s.id = l.id_sede
         WHERE l.id_sede = ? AND l.estado = \'ACTIVO\'
         LIMIT 1'
    );
    if ($stmtLin) {
        $stmtLin->bind_param('i', $id_sede);
        if (@$stmtLin->execute()) {
            $resLin = $stmtLin->get_result();
            if ($resLin && $resLin->num_rows > 0) {
                $linea = $resLin->fetch_assoc();
            }
        }
        $stmtLin->close();
    }

    if (!$linea) {
        echo " - Sin línea activa en la sede {$id_sede}.\n";
        continue;
    }

    $id_linea = intval($linea['id']);
    $id_empresa = intval($linea['id_empresa'] ?? 1);
    $nombre_sede_actual = trim((string)($linea['nombre_sede'] ?? 'Sede'));
    $phone_id = trim((string)$linea['meta_app_id']);
    $token = !empty($linea['meta_token']) ? trim((string)$linea['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

    // Obtener lote pendiente de hoy
    $lote_dest = [];
    $stmtDestList = $con->prepare(
        'SELECT * FROM campanas_difusion_destinatarios
         WHERE id_campana = ? AND estado_envio = \'PENDIENTE\'
         ORDER BY numero_tanda ASC, id ASC
         LIMIT ?'
    );
    if ($stmtDestList) {
        $stmtDestList->bind_param('ii', $id_campana, $disp_hoy);
        if (@$stmtDestList->execute()) {
            $resDestList = $stmtDestList->get_result();
            if ($resDestList) {
                while ($rd = $resDestList->fetch_assoc()) {
                    $lote_dest[] = $rd;
                }
            }
        }
        $stmtDestList->close();
    }

    $enviados_tanda = 0;
    $omitidos_antifatiga = 0;
    $camp_cat = strtoupper(trim((string)($camp['categoria_meta'] ?? ($camp['template_category'] ?? 'MARKETING')))) ?: 'MARKETING';

    foreach ($lote_dest as $dest) {
        $id_dest = intval($dest['id']);
        $telefono_limpio = normalizeVePhoneCron($dest['telefono']);
        $template_name = trim((string)($camp['template_name'] ?: 'starfi_difusion_directa'));

        // Reclamo atómico de la fila para evitar colisiones con navegador u otro cron
        $claimed = false;
        $stmtClaim = $con->prepare(
            'UPDATE campanas_difusion_destinatarios
             SET estado_envio = \'PROCESANDO\', fecha_envio = NOW()
             WHERE id = ? AND id_campana = ? AND estado_envio = \'PENDIENTE\' AND (id_mensaje_meta IS NULL OR id_mensaje_meta = \'\')'
        );
        if ($stmtClaim) {
            $stmtClaim->bind_param('ii', $id_dest, $id_campana);
            if (@$stmtClaim->execute()) {
                $claimed = ($stmtClaim->affected_rows > 0);
            }
            $stmtClaim->close();
        }
        if (!$claimed) {
            continue;
        }

        // ESCUDO ANTI-FATIGA 24H EN BACKEND (QA-BUG-CRM2-08 / CHK-CLD-02)
        if ($camp_cat === 'MARKETING' && starfi_check_marketing_antifatigue_24h($con, $telefono_limpio, $id_dest)) {
            $msg_fatiga = 'Omitido por Escudo Anti-Fatiga 24h (Meta 131049)';
            $stmtSkipFat = $con->prepare(
                'UPDATE campanas_difusion_destinatarios
                 SET estado_envio = \'OMITIDO_ANTIFATIGA\', error_detalle = ?, costo_usd = 0.0000
                 WHERE id = ?'
            );
            if ($stmtSkipFat) {
                $stmtSkipFat->bind_param('si', $msg_fatiga, $id_dest);
                @$stmtSkipFat->execute();
                $stmtSkipFat->close();
            }
            $omitidos_antifatiga++;
            registrar_auditoria(
                'campanas',
                'OMITIR_DESTINATARIO_ANTIFATIGA_CRON',
                "Destinatario #{$id_dest} ({$telefono_limpio}) omitido por Escudo Anti-Fatiga 24h en campaña #{$id_campana}",
                ['id_destinatario' => $id_dest, 'estado_envio' => 'PENDIENTE', 'telefono' => $telefono_limpio],
                ['id_destinatario' => $id_dest, 'estado_envio' => 'OMITIDO_ANTIFATIGA', 'error_detalle' => $msg_fatiga],
                $con,
                $id_usuario,
                $id_sede
            );
            continue;
        }

        // Protección anti-duplicado por teléfono en la misma campaña / mismo día
        $es_dup_hoy = false;
        $stmtDupTel = $con->prepare(
            'SELECT id FROM campanas_difusion_destinatarios
             WHERE id_campana = ? AND id != ? AND telefono = ?
               AND estado_envio IN (\'PROCESANDO\',\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')
             LIMIT 1'
        );
        if ($stmtDupTel) {
            $stmtDupTel->bind_param('iis', $id_campana, $id_dest, $telefono_limpio);
            if (@$stmtDupTel->execute()) {
                $stmtDupTel->store_result();
                if ($stmtDupTel->num_rows > 0) {
                    $es_dup_hoy = true;
                }
            }
            $stmtDupTel->close();
        }

        if (!$es_dup_hoy) {
            $stmtDupNotif = $con->prepare(
                'SELECT id FROM notificacion_enviada
                 WHERE fecha = CURDATE() AND telefono_receptor = ? AND template = ? AND status = \'ENVIADO\'
                 LIMIT 1'
            );
            if ($stmtDupNotif) {
                $stmtDupNotif->bind_param('ss', $telefono_limpio, $template_name);
                if (@$stmtDupNotif->execute()) {
                    $stmtDupNotif->store_result();
                    if ($stmtDupNotif->num_rows > 0) {
                        $es_dup_hoy = true;
                    }
                }
                $stmtDupNotif->close();
            }
        }

        if ($es_dup_hoy) {
            $msg_dup = 'Omitido automáticamente: número ya notificado hoy.';
            $stmtSkipDup = $con->prepare('UPDATE campanas_difusion_destinatarios SET estado_envio = \'ENVIADO\', error_detalle = ? WHERE id = ?');
            if ($stmtSkipDup) {
                $stmtSkipDup->bind_param('si', $msg_dup, $id_dest);
                @$stmtSkipDup->execute();
                $stmtSkipDup->close();
            }
            continue;
        }

        $nombre_cliente = trim((string)($dest['nombre'] ?: 'Cliente'));
        $primer_nombre = explode(' ', $nombre_cliente)[0];
        if (empty($primer_nombre) || strlen($primer_nombre) < 2 || preg_match('/^\+?[0-9\s\-]+$/', $primer_nombre)) {
            $primer_nombre = 'Estimado(a) cliente';
        }
        $primer_nombre = sanitizeParamCron($primer_nombre, false);
        $camp_lang = trim((string)($camp['template_language'] ?? 'es')) ?: 'es';
        $camp_hdr_fmt = strtoupper(trim((string)($camp['header_format'] ?? 'IMAGE'))) ?: 'IMAGE';
        $costo_unit = floatval($camp['costo_unitario_usd'] ?: 0.07);

        $resolveDynamicTokenCron = function ($val_raw) use ($primer_nombre, $nombre_cliente, $telefono_limpio, $nombre_sede_actual) {
            $str = (string)$val_raw;
            $str = str_ireplace(['@NOMBRE_CLIENTE', '{{nombre_cliente}}'], $primer_nombre, $str);
            $str = str_ireplace(['@NOMBRE_COMPLETO', '{{nombre_completo}}'], $nombre_cliente, $str);
            $str = str_ireplace(['@TELEFONO_CLIENTE', '{{telefono_cliente}}'], $telefono_limpio, $str);
            $str = str_ireplace(['@NOMBRE_SEDE', '{{nombre_sede}}'], $nombre_sede_actual, $str);
            return sanitizeParamCron($str, true);
        };

        $tpl_json_data = !empty($camp['template_params_json']) ? json_decode((string)$camp['template_params_json'], true) : null;
        $params_arr = [];
        $header_text_resolved = '';
        $texto_renderizado_log = '';

        if (is_array($tpl_json_data) && isset($tpl_json_data['body_params']) && is_array($tpl_json_data['body_params'])) {
            foreach ($tpl_json_data['body_params'] as $bp_raw) {
                $params_arr[] = $resolveDynamicTokenCron($bp_raw);
            }
            if (!empty($tpl_json_data['header_text_param'])) {
                $header_text_resolved = sanitizeParamCron($resolveDynamicTokenCron($tpl_json_data['header_text_param']), false);
            }
            if (!empty($tpl_json_data['body_template'])) {
                $txt_r = (string)$tpl_json_data['body_template'];
                foreach ($params_arr as $idx_p => $val_p) {
                    $num_p = $idx_p + 1;
                    $txt_r = str_replace('{{' . $num_p . '}}', $val_p, $txt_r);
                }
                $texto_renderizado_log = trim($txt_r);
            }
        } else {
            $param_2 = sanitizeParamCron($camp['param_cuerpo'], true);
            $param_3 = sanitizeParamCron($camp['param_eslogan'], true);
            $params_arr = [$primer_nombre, $param_2, $param_3];
        }

        $url = "https://graph.facebook.com/{$apiVer}/{$phone_id}/messages";
        $headers = ["Authorization: Bearer {$token}", 'Content-Type: application/json'];
        $lang_candidates = array_values(array_unique([$camp_lang, 'es', 'es_ES']));
        $http_code = 0;
        $resp_data = [];
        $first_resp_data = null;

        $needs_image_header = ($camp_hdr_fmt === 'IMAGE' || $template_name === 'starfi_difusion_directa' || !empty($camp['header_imagen_path']) || !empty($camp['header_media_id']));
        if ($camp_hdr_fmt === 'NONE' || $camp_hdr_fmt === 'TEXT') {
            $needs_image_header = false;
        }
        $header_media_id = null;
        if ($needs_image_header) {
            $header_media_id = getOrUploadHeaderMediaIdCron($con, $camp, $phone_id, $token);
        }

        $body_parameters_payload = [];
        foreach ($params_arr as $p_val) {
            $body_parameters_payload[] = ['type' => 'text', 'text' => $p_val];
        }

        foreach ($lang_candidates as $idx_lang => $try_lang) {
            for ($attempt_img = 0; $attempt_img < 2; $attempt_img++) {
                $components_payload = [];
                if ($needs_image_header && !empty($header_media_id)) {
                    $components_payload[] = [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'image', 'image' => ['id' => $header_media_id]]
                        ]
                    ];
                } elseif ($camp_hdr_fmt === 'TEXT' && $header_text_resolved !== '') {
                    $components_payload[] = [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $header_text_resolved]
                        ]
                    ];
                }
                if (!empty($body_parameters_payload)) {
                    $components_payload[] = [
                        'type' => 'body',
                        'parameters' => $body_parameters_payload
                    ];
                }

                $payload = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $telefono_limpio,
                    'type' => 'template',
                    'template' => [
                        'name' => $template_name,
                        'language' => ['code' => $try_lang],
                        'components' => $components_payload
                    ]
                ];
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $resp_data = json_decode((string)$response, true) ?? [];
                if ($idx_lang === 0 && $first_resp_data === null) {
                    $first_resp_data = $resp_data;
                }
                $err_code_meta = intval($resp_data['error']['code'] ?? 0);
                $err_details_meta = strtoupper((string)($resp_data['error']['error_data']['details'] ?? ($resp_data['error']['message'] ?? '')));

                if ($attempt_img === 0 && ($err_code_meta === 132012 || $err_code_meta === 131053 || $err_code_meta === 132000)) {
                    if (strpos($err_details_meta, 'EXPECTED IMAGE') !== false) {
                        $needs_image_header = true;
                        $camp['header_media_id'] = '';
                        $header_media_id = getOrUploadHeaderMediaIdCron($con, $camp, $phone_id, $token);
                        continue;
                    } elseif (strpos($err_details_meta, 'EXPECTED UNKNOWN') !== false || strpos($err_details_meta, 'EXPECTED TEXT') !== false || ($err_code_meta === 132000 && $needs_image_header)) {
                        $needs_image_header = false;
                        $header_media_id = null;
                        continue;
                    } elseif ($err_code_meta === 131053 && $needs_image_header) {
                        $camp['header_media_id'] = '';
                        $header_media_id = getOrUploadHeaderMediaIdCron($con, $camp, $phone_id, $token);
                        continue;
                    }
                }
                break;
            }
            if (($http_code >= 200 && $http_code < 300) || intval($resp_data['error']['code'] ?? 0) !== 132001) {
                break;
            }
        }
        if (!($http_code >= 200 && $http_code < 300) && intval($resp_data['error']['code'] ?? 0) === 132001 && $first_resp_data !== null) {
            $resp_data = $first_resp_data;
        }

        $contenido_log = 'Envío dinámico de plantilla: ' . $template_name . '. Params: ' . json_encode($params_arr, JSON_UNESCAPED_UNICODE);
        if ($texto_renderizado_log !== '') {
            $contenido_log .= ' | Texto: ' . $texto_renderizado_log;
        }
        $fecha_hoy = date('Y-m-d');
        $hora_actual = date('H:i:s');
        $now_full = "{$fecha_hoy} {$hora_actual}";

        if ($http_code >= 200 && $http_code < 300 && isset($resp_data['messages'][0]['id'])) {
            $wamid_raw = (string)$resp_data['messages'][0]['id'];

            $stmtNotifOk = $con->prepare(
                'INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                 VALUES (?, ?, ?, ?, ?, ?, \'ENVIADO\')'
            );
            if ($stmtNotifOk) {
                $stmtNotifOk->bind_param('ssssss', $template_name, $fecha_hoy, $hora_actual, $phone_id, $telefono_limpio, $contenido_log);
                @$stmtNotifOk->execute();
                $stmtNotifOk->close();
            }

            // Asegurar cliente en clientes_contactos
            $id_cliente = intval($dest['id_cliente'] ?? 0);
            if ($id_cliente <= 0) {
                $like_tel = '%' . $telefono_limpio . '%';
                $stmtCFind = $con->prepare('SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = ? OR numero_whatsapp LIKE ? LIMIT 1');
                if ($stmtCFind) {
                    $stmtCFind->bind_param('ss', $telefono_limpio, $like_tel);
                    if (@$stmtCFind->execute()) {
                        $resCFind = $stmtCFind->get_result();
                        if ($resCFind && ($row_cli = $resCFind->fetch_assoc())) {
                            $id_cliente = intval($row_cli['id']);
                        }
                    }
                    $stmtCFind->close();
                }

                if ($id_cliente > 0) {
                    $stmtUpdCli = $con->prepare('UPDATE clientes_contactos SET ultimo_contacto = ? WHERE id = ?');
                    if ($stmtUpdCli) {
                        $stmtUpdCli->bind_param('si', $now_full, $id_cliente);
                        @$stmtUpdCli->execute();
                        $stmtUpdCli->close();
                    }
                } else {
                    $nom_insert_param = ($primer_nombre !== 'Estimado(a) cliente') ? $nombre_cliente : null;
                    $stmtInsCli = $con->prepare(
                        'INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre, fecha_registro, ultimo_contacto)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    if ($stmtInsCli) {
                        $stmtInsCli->bind_param('iissss', $id_empresa, $id_sede, $telefono_limpio, $nom_insert_param, $now_full, $now_full);
                        if (@$stmtInsCli->execute()) {
                            $id_cliente = intval($stmtInsCli->insert_id);
                        }
                        $stmtInsCli->close();
                    }
                }
            } else {
                $stmtUpdCli = $con->prepare('UPDATE clientes_contactos SET ultimo_contacto = ? WHERE id = ?');
                if ($stmtUpdCli) {
                    $stmtUpdCli->bind_param('si', $now_full, $id_cliente);
                    @$stmtUpdCli->execute();
                    $stmtUpdCli->close();
                }
            }

            // Asegurar conversación activa en la sede
            $id_conversacion = 0;
            if ($id_cliente > 0) {
                $stmtConv = $con->prepare('SELECT id FROM conversaciones WHERE id_cliente = ? AND id_linea = ? AND estado NOT IN (\'CERRADO\', \'RESUELTO\') ORDER BY id DESC LIMIT 1');
                if ($stmtConv) {
                    $stmtConv->bind_param('ii', $id_cliente, $id_linea);
                    if (@$stmtConv->execute()) {
                        $resConv = $stmtConv->get_result();
                        if ($resConv && ($rowConv = $resConv->fetch_assoc())) {
                            $id_conversacion = intval($rowConv['id']);
                        }
                    }
                    $stmtConv->close();
                }

                if ($id_conversacion <= 0) {
                    $stmtInsConv = $con->prepare('INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos, fecha_inicio) VALUES (?, ?, \'ESPERA_ASIGNACION\', 0, ?)');
                    if ($stmtInsConv) {
                        $stmtInsConv->bind_param('iis', $id_linea, $id_cliente, $now_full);
                        if (@$stmtInsConv->execute()) {
                            $id_conversacion = intval($stmtInsConv->insert_id);
                        }
                        $stmtInsConv->close();
                    }
                }
            }

            if ($id_conversacion > 0) {
                $rel_media_bandeja = null;
                if ($needs_image_header) {
                    if (!empty($camp['header_imagen_path'])) {
                        $rel_media_bandeja = '../../' . stripLeadingAppSubpathCron($camp['header_imagen_path']);
                    } else {
                        $abs_img_used = resolveCampaignImageCron($con, $camp);
                        $base_root = str_replace('\\', '/', __DIR__);
                        $abs_norm = $abs_img_used ? str_replace('\\', '/', $abs_img_used) : '';
                        if ($abs_norm !== '' && strpos($abs_norm, $base_root) === 0) {
                            $rel_media_bandeja = '../../' . ltrim(substr($abs_norm, strlen($base_root)), '/');
                        }
                    }
                }
                $tipo_msg_bandeja = !empty($rel_media_bandeja) ? 'IMAGEN' : 'TEXTO';
                $stmtInsEv = $con->prepare(
                    'INSERT INTO mensajes_y_eventos
                        (id_conversacion, id_mensaje_meta, tipo, origen, id_agente, contenido, url_archivo, estado_envio, categoria_meta, es_pagado, costo_meta_estimado, timestamp)
                     VALUES (?, ?, ?, \'API_TRANSACCIONAL\', ?, ?, ?, \'ENVIADO\', ?, 1, ?, ?)'
                );
                if ($stmtInsEv) {
                    $stmtInsEv->bind_param(
                        'ississsds',
                        $id_conversacion,
                        $wamid_raw,
                        $tipo_msg_bandeja,
                        $id_usuario,
                        $contenido_log,
                        $rel_media_bandeja,
                        $camp_cat,
                        $costo_unit,
                        $now_full
                    );
                    if (@$stmtInsEv->execute()) {
                        $new_cron_msg_id = intval($stmtInsEv->insert_id);
                        if ($new_cron_msg_id > 0) {
                            $stmtSyncU = $con->prepare('UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?) WHERE id = ? AND IFNULL(ultimo_msg_id, 0) < ?');
                            if ($stmtSyncU) {
                                $stmtSyncU->bind_param('iii', $new_cron_msg_id, $id_conversacion, $new_cron_msg_id);
                                @$stmtSyncU->execute();
                                $stmtSyncU->close();
                            }
                        }
                    }
                    $stmtInsEv->close();
                }
            }

            $stmtUpdDestOk = $con->prepare(
                'UPDATE campanas_difusion_destinatarios
                 SET id_cliente = ?, id_conversacion = ?, telefono = ?, id_mensaje_meta = ?, estado_envio = \'ENVIADO\', costo_usd = ?, fecha_envio = NOW(), error_detalle = NULL
                 WHERE id = ?'
            );
            if ($stmtUpdDestOk) {
                $stmtUpdDestOk->bind_param('iissdi', $id_cliente, $id_conversacion, $telefono_limpio, $wamid_raw, $costo_unit, $id_dest);
                @$stmtUpdDestOk->execute();
                $stmtUpdDestOk->close();
            }
            $enviados_tanda++;
        } else {
            $err_msg = (string)($resp_data['error']['message'] ?? 'Error Meta API');
            starfi_log_exception('campanas', 'Fallo de envío Meta API en cron piloto automático', [
                'id_campana' => $id_campana,
                'id_destinatario' => $id_dest,
                'http_code' => $http_code,
                'error' => $err_msg
            ]);

            $stmtNotifErr = $con->prepare(
                'INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                 VALUES (?, ?, ?, ?, ?, ?, \'ERROR\')'
            );
            if ($stmtNotifErr) {
                $stmtNotifErr->bind_param('ssssss', $template_name, $fecha_hoy, $hora_actual, $phone_id, $telefono_limpio, $contenido_log);
                @$stmtNotifErr->execute();
                $stmtNotifErr->close();
            }

            $stmtUpdDestErr = $con->prepare('UPDATE campanas_difusion_destinatarios SET estado_envio = \'FALLIDO\', error_detalle = ?, fecha_envio = NOW() WHERE id = ?');
            if ($stmtUpdDestErr) {
                $stmtUpdDestErr->bind_param('si', $err_msg, $id_dest);
                @$stmtUpdDestErr->execute();
                $stmtUpdDestErr->close();
            }
        }

        usleep($cad_ms * 1000);
    }

    recalcularCampanaCron($con, $id_campana);

    $pend = 0;
    $stmtPend = $con->prepare('SELECT pendientes FROM campanas_difusion WHERE id = ? LIMIT 1');
    if ($stmtPend) {
        $stmtPend->bind_param('i', $id_campana);
        if (@$stmtPend->execute()) {
            $resPend = $stmtPend->get_result();
            if ($resPend && ($rp = $resPend->fetch_assoc())) {
                $pend = intval($rp['pendientes']);
            }
        }
        $stmtPend->close();
    }

    $nuevo_est = ($pend > 0) ? 'PILOTO_AUTOMATICO' : 'COMPLETADA';
    $stmtFinCamp = $con->prepare('UPDATE campanas_difusion SET estado = ?, fecha_fin = NOW() WHERE id = ?');
    if ($stmtFinCamp) {
        $stmtFinCamp->bind_param('si', $nuevo_est, $id_campana);
        @$stmtFinCamp->execute();
        $stmtFinCamp->close();
    }

    registrar_auditoria(
        'campanas',
        'EJECUTAR_TANDA_PILOTO_AUTOMATICO',
        "Ejecución diaria de Piloto Automático en campaña #{$id_campana}: {$enviados_tanda} enviados, {$omitidos_antifatiga} omitidos por anti-fatiga, {$pend} pendientes",
        [
            'id_campana' => $id_campana,
            'estado' => $camp_antes['estado'] ?? '',
            'pendientes' => intval($camp_antes['pendientes'] ?? 0),
            'enviados' => intval($camp_antes['enviados'] ?? 0)
        ],
        [
            'id_campana' => $id_campana,
            'estado' => $nuevo_est,
            'enviados_tanda' => $enviados_tanda,
            'omitidos_antifatiga_24h' => $omitidos_antifatiga,
            'pendientes_restantes' => $pend
        ],
        $con,
        $id_usuario,
        $id_sede
    );

    echo " - Tanda finalizada: {$enviados_tanda} enviados, {$omitidos_antifatiga} omitidos por anti-fatiga 24h. Pendientes restantes: {$pend}.\n";
}

// [IA-04] Secuencias Automáticas de Remarketing (Drip Campaigns: Leídos sin responder en X horas)
require_once __DIR__ . '/core/services/DripRemarketingService.php';
$dripSummary = DripRemarketingService::processDueDripSequences($con, 0, 100, true);
if (!empty($dripSummary['processed']) && intval($dripSummary['processed']) > 0) {
    echo " - [IA-04 Drip Remarketing] Evaluados: {$dripSummary['evaluated']} | Procesados: {$dripSummary['processed']} | Re-impactados: {$dripSummary['reimpacted']} | Asignados a Vendedor: {$dripSummary['assigned']}.\n";
} else {
    echo " - [IA-04 Drip Remarketing] Sin destinatarios vencidos pendientes de seguimiento en este ciclo.\n";
}

echo "Ejecución de Piloto Automático finalizada.\n";
