<?php
/**
 * core/crm_sprint2_helper.php
 * Motor central para OT-STARFI-2026-11 (SPRINT 2 — Remediado QA V2):
 * - Auto-migración ligera e indexada con bandera .flag por hash MD5 de BD
 * - Heartbeat de presencia de asesores en línea por sede
 * - Enrutamiento Automático Round-Robin con transacción y SELECT ... FOR UPDATE ([COM-03])
 * - Sincronización de Oportunidades Kanban USD con aislamiento estricto Multi-Sede y FOR UPDATE ([COM-02])
 */

if (!function_exists('starfi_db_schema_hash')) {
    function starfi_db_schema_hash($con) {
        static $cached = [];
        if (!$con) {
            return 'nodb';
        }
        $oid = function_exists('spl_object_id') ? spl_object_id($con) : 0;
        if (isset($cached[$oid])) {
            return $cached[$oid];
        }
        $dbName = 'starfi_crm';
        $res = @mysqli_query($con, "SELECT DATABASE() AS db_name");
        if ($res && ($row = mysqli_fetch_assoc($res)) && !empty($row['db_name'])) {
            $dbName = (string)$row['db_name'];
        }
        $hash = md5($dbName);
        $cached[$oid] = $hash;
        return $hash;
    }
}

if (!function_exists('starfi_tx_is_active')) {
    function starfi_tx_is_active($con) {
        $oid = function_exists('spl_object_id') ? spl_object_id($con) : 0;
        return !empty($GLOBALS['__starfi_active_tx'][$oid]);
    }
    function starfi_tx_set_active($con, $active) {
        $oid = function_exists('spl_object_id') ? spl_object_id($con) : 0;
        $GLOBALS['__starfi_active_tx'][$oid] = (bool)$active;
    }
}

if (!function_exists('starfi_ensure_sprint2_schema')) {
    function starfi_ensure_sprint2_schema($con) {
        if (!$con) {
            return;
        }
        if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
            return;
        }
        $dbHash = starfi_db_schema_hash($con);
        $flag = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_sprint3_schema_v7_' . $dbHash . '.flag';
        if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
            $chkTbl = @mysqli_query($con, "SHOW TABLES LIKE 'pipeline_oportunidades'");
            $chkIdxSent = @mysqli_query($con, "SHOW INDEX FROM `conversaciones` WHERE Key_name = 'idx_linea_sentimiento'");
            $chkUkCli = @mysqli_query($con, "SHOW INDEX FROM `clientes_contactos` WHERE Key_name = 'uk_empresa_whatsapp_sede'");
            if ($chkTbl && mysqli_num_rows($chkTbl) > 0 && $chkIdxSent && mysqli_num_rows($chkIdxSent) > 0 && $chkUkCli && mysqli_num_rows($chkUkCli) >= 3) {
                return;
            }
        }

        // 1. Columnas de presencia y Round-Robin en tabla `usuario`
        $u_cols = [];
        $res_u = @mysqli_query($con, "SHOW COLUMNS FROM `usuario`");
        if ($res_u) {
            while ($r = mysqli_fetch_assoc($res_u)) {
                $u_cols[$r['Field']] = true;
            }
        }
        if (!isset($u_cols['ultima_actividad'])) {
            @mysqli_query($con, "ALTER TABLE `usuario` ADD COLUMN `ultima_actividad` DATETIME DEFAULT NULL");
        }
        if (!isset($u_cols['disponible_chat'])) {
            @mysqli_query($con, "ALTER TABLE `usuario` ADD COLUMN `disponible_chat` TINYINT(1) NOT NULL DEFAULT 1");
        }
        if (!isset($u_cols['ultima_asignacion_rr'])) {
            @mysqli_query($con, "ALTER TABLE `usuario` ADD COLUMN `ultima_asignacion_rr` DATETIME DEFAULT NULL");
        }

        $r_idx_u = @mysqli_query($con, "SHOW INDEX FROM `usuario` WHERE Key_name = 'idx_sede_online_rr'");
        if ($r_idx_u && mysqli_num_rows($r_idx_u) === 0) {
            @mysqli_query($con, "ALTER TABLE `usuario` ADD INDEX `idx_sede_online_rr` (`id_sede`, `estado`, `disponible_chat`, `ultima_actividad`)");
        }

        // 1b. Columnas Sprint 3 IA ([IA-01] Resumen Ejecutivo y [IA-02] Sentimiento Cliente) en `conversaciones`
        $c_cols = [];
        $res_c = @mysqli_query($con, "SHOW COLUMNS FROM `conversaciones`");
        if ($res_c) {
            while ($rc = mysqli_fetch_assoc($res_c)) {
                $c_cols[$rc['Field']] = true;
            }
        }
        if (!empty($c_cols)) {
            if (!isset($c_cols['sentimiento_cliente'])) {
                @mysqli_query($con, "ALTER TABLE `conversaciones` ADD COLUMN `sentimiento_cliente` VARCHAR(30) NOT NULL DEFAULT 'NEUTRO'");
            }
            if (!isset($c_cols['sentimiento_actualizado'])) {
                @mysqli_query($con, "ALTER TABLE `conversaciones` ADD COLUMN `sentimiento_actualizado` DATETIME DEFAULT NULL");
            }
            if (!isset($c_cols['resumen_ia'])) {
                @mysqli_query($con, "ALTER TABLE `conversaciones` ADD COLUMN `resumen_ia` TEXT DEFAULT NULL");
            }
            if (!isset($c_cols['resumen_ia_actualizado'])) {
                @mysqli_query($con, "ALTER TABLE `conversaciones` ADD COLUMN `resumen_ia_actualizado` DATETIME DEFAULT NULL");
            }
            $r_idx_old_sent = @mysqli_query($con, "SHOW INDEX FROM `conversaciones` WHERE Key_name = 'idx_sede_sentimiento'");
            if ($r_idx_old_sent && mysqli_num_rows($r_idx_old_sent) > 0) {
                @mysqli_query($con, "ALTER TABLE `conversaciones` DROP INDEX `idx_sede_sentimiento`");
            }
            $r_idx_sent = @mysqli_query($con, "SHOW INDEX FROM `conversaciones` WHERE Key_name = 'idx_linea_sentimiento'");
            if ($r_idx_sent && mysqli_num_rows($r_idx_sent) === 0) {
                @mysqli_query($con, "ALTER TABLE `conversaciones` ADD INDEX `idx_linea_sentimiento` (`id_linea`, `sentimiento_cliente`, `estado`)");
            }
        }

        // 1c. Homologar llave única Multi-Sede `uk_empresa_whatsapp_sede (id_empresa, numero_whatsapp, id_sede)` en `clientes_contactos` (database_schema.sql)
        $r_idx_cli_st = @mysqli_query($con, "SHOW INDEX FROM `clientes_contactos` WHERE Key_name = 'idx_cli_sede_tel'");
        if ($r_idx_cli_st && mysqli_num_rows($r_idx_cli_st) === 0) {
            @mysqli_query($con, "ALTER TABLE `clientes_contactos` ADD INDEX `idx_cli_sede_tel` (`id_sede`, `numero_whatsapp`)");
        }
        $r_uk_cli_new = @mysqli_query($con, "SHOW INDEX FROM `clientes_contactos` WHERE Key_name = 'uk_empresa_whatsapp_sede'");
        if ($r_uk_cli_new && mysqli_num_rows($r_uk_cli_new) === 0) {
            @mysqli_query($con, "ALTER TABLE `clientes_contactos` ADD UNIQUE INDEX `uk_empresa_whatsapp_sede` (`id_empresa`, `numero_whatsapp`, `id_sede`)");
        }
        $r_uk_cli_old = @mysqli_query($con, "SHOW INDEX FROM `clientes_contactos` WHERE Key_name = 'id_empresa' AND Non_unique = 0");
        if ($r_uk_cli_old && mysqli_num_rows($r_uk_cli_old) > 0 && mysqli_num_rows($r_uk_cli_old) < 3) {
            @mysqli_query($con, "ALTER TABLE `clientes_contactos` DROP INDEX `id_empresa`");
        }

        // 2. Índice compuesto para Ventana Meta 24h ([COM-01]) y Semáforo SLA ([COM-04])
        $r_idx_m = @mysqli_query($con, "SHOW INDEX FROM `mensajes_y_eventos` WHERE Key_name = 'idx_conv_origen_ts'");
        if ($r_idx_m && mysqli_num_rows($r_idx_m) === 0) {
            @mysqli_query($con, "ALTER TABLE `mensajes_y_eventos` ADD INDEX `idx_conv_origen_ts` (`id_conversacion`, `origen`, `timestamp`)");
        }

        // 3. Tabla Kanban de Embudo de Ventas ([COM-02])
        @mysqli_query($con, "CREATE TABLE IF NOT EXISTS `pipeline_oportunidades` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `id_sede` INT NOT NULL DEFAULT 0,
            `id_cliente` INT NOT NULL DEFAULT 0,
            `id_conversacion` INT DEFAULT NULL,
            `id_agente` INT DEFAULT NULL,
            `titulo` VARCHAR(200) NOT NULL DEFAULT 'Oportunidad Comercial',
            `etapa` ENUM('NUEVO_LEAD','COTIZADO','EN_NEGOCIACION','PAGO_PENDIENTE','GANADO','PERDIDO') NOT NULL DEFAULT 'NUEVO_LEAD',
            `monto_estimado_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `probabilidad` INT NOT NULL DEFAULT 20,
            `notas` TEXT DEFAULT NULL,
            `motivo_perdida` VARCHAR(200) DEFAULT NULL,
            `orden_kanban` INT NOT NULL DEFAULT 0,
            `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `fecha_cierre` DATETIME DEFAULT NULL,
            INDEX `idx_sede_etapa` (`id_sede`, `etapa`, `orden_kanban`),
            INDEX `idx_conversacion` (`id_conversacion`),
            INDEX `idx_cliente` (`id_cliente`),
            INDEX `idx_agente` (`id_agente`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 4. Tabla de configuración dinámica de tiempos SLA y presencia Round-Robin (Global = 0 o por Sede > 0)
        @mysqli_query($con, "CREATE TABLE IF NOT EXISTS `config_sla` (
            `id_sede` INT NOT NULL PRIMARY KEY DEFAULT 0,
            `verde_max_min` INT NOT NULL DEFAULT 5,
            `amarillo_max_min` INT NOT NULL DEFAULT 15,
            `round_robin_online_min` INT NOT NULL DEFAULT 5,
            `alerta_sonora_activa` TINYINT(1) NOT NULL DEFAULT 1,
            `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @mysqli_query($con, "INSERT IGNORE INTO `config_sla` (`id_sede`, `verde_max_min`, `amarillo_max_min`, `round_robin_online_min`, `alerta_sonora_activa`) VALUES (0, 5, 15, 5, 1)");

        @file_put_contents($flag, date('Y-m-d H:i:s'));
    }
}

if (!function_exists('starfi_get_sla_config')) {
    function starfi_get_sla_config($con, $id_sede = 0) {
        $defaults = [
            'id_sede_solicitada'     => intval($id_sede),
            'id_sede_aplicada'       => 0,
            'es_personalizado'       => false,
            'verde_max_min'          => 5,
            'amarillo_max_min'       => 15,
            'round_robin_online_min' => 5,
            'alerta_sonora_activa'   => 1,
        ];
        if (!$con) {
            return $defaults;
        }
        starfi_ensure_sprint2_schema($con);
        $sede = max(0, intval($id_sede));
        if ($sede > 0) {
            $stmt = $con->prepare("SELECT * FROM `config_sla` WHERE `id_sede` IN (0, ?) ORDER BY (`id_sede` = ?) DESC LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ii", $sede, $sede);
            }
        } else {
            $stmt = $con->prepare("SELECT * FROM `config_sla` WHERE `id_sede` = 0 LIMIT 1");
        }
        if ($stmt && $stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $stmt->close();
                $v = max(1, intval($row['verde_max_min'] ?? 5));
                $a = max($v + 1, intval($row['amarillo_max_min'] ?? 15));
                $rr = max(1, intval($row['round_robin_online_min'] ?? 5));
                $app_sede = intval($row['id_sede'] ?? 0);
                return [
                    'id_sede_solicitada'     => $sede,
                    'id_sede_aplicada'       => $app_sede,
                    'es_personalizado'       => ($sede > 0 && $app_sede === $sede),
                    'verde_max_min'          => $v,
                    'amarillo_max_min'       => $a,
                    'round_robin_online_min' => $rr,
                    'alerta_sonora_activa'   => intval($row['alerta_sonora_activa'] ?? 1) ? 1 : 0,
                ];
            }
            $stmt->close();
        }
        return $defaults;
    }
}

if (!function_exists('starfi_save_sla_config')) {
    function starfi_save_sla_config($con, $id_sede, $verde_min, $amarillo_min, $rr_online_min = 5, $alerta_sonora = 1) {
        if (!$con) {
            return ['ok' => false, 'message' => 'Sin conexión a base de datos.'];
        }
        starfi_ensure_sprint2_schema($con);
        $sede = max(0, intval($id_sede));
        $v = intval($verde_min);
        $a = intval($amarillo_min);
        $rr = max(1, min(120, intval($rr_online_min)));
        $snd = intval($alerta_sonora) ? 1 : 0;

        if ($v < 1 || $v > 1440) {
            return ['ok' => false, 'message' => 'El tiempo máximo en Verde debe estar entre 1 y 1440 minutos.'];
        }
        if ($a <= $v || $a > 2880) {
            return ['ok' => false, 'message' => 'El tiempo límite para Rojo Crítico debe ser mayor al tiempo en Verde (máx. 2880 min).'];
        }

        $stmt = $con->prepare("
            INSERT INTO `config_sla` (`id_sede`, `verde_max_min`, `amarillo_max_min`, `round_robin_online_min`, `alerta_sonora_activa`)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                `verde_max_min` = VALUES(`verde_max_min`),
                `amarillo_max_min` = VALUES(`amarillo_max_min`),
                `round_robin_online_min` = VALUES(`round_robin_online_min`),
                `alerta_sonora_activa` = VALUES(`alerta_sonora_activa`)
        ");
        if (!$stmt) {
            return ['ok' => false, 'message' => 'No se pudo preparar la configuración SLA.'];
        }
        $stmt->bind_param("iiiii", $sede, $v, $a, $rr, $snd);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo guardar la configuración SLA en base de datos.'];
        }
        return ['ok' => true, 'config' => starfi_get_sla_config($con, $sede)];
    }
}

if (!function_exists('starfi_touch_agent_presence')) {
    function starfi_touch_agent_presence($con, $agente_id, $force = false) {
        $uid = intval($agente_id);
        if (!$con || $uid <= 1) {
            return;
        }
        if (!$force && session_status() === PHP_SESSION_ACTIVE) {
            $last_hb = intval($_SESSION['last_db_heartbeat'] ?? 0);
            if ((time() - $last_hb) < 25) {
                return;
            }
            $_SESSION['last_db_heartbeat'] = time();
        }
        starfi_ensure_sprint2_schema($con);
        $stmt = $con->prepare("UPDATE `usuario` SET `ultima_actividad` = NOW() WHERE `id` = ?");
        if ($stmt) {
            $stmt->bind_param("i", $uid);
            @$stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('starfi_parse_usd_number')) {
    /**
     * Convierte cadenas monetarias como "906.50 + IVA", "1,019.30 USD", "1.250,50", "$1.250 USD" o "$236.40" a float USD.
     * En notación con un único punto decimal (ej. "$1.250 USD"), trata el punto como separador decimal (1.25 USD)
     * evitando inflar 1,000x montos unitarios con 3 o 4 decimales.
     */
    function starfi_parse_usd_number($raw) {
        $str = trim((string)$raw);
        if ($str === '') {
            return 0.0;
        }
        // Descartar si es explícitamente en Bolívares sin referencia a USD/$
        if (preg_match('/\b(bs\.?|ves|bol[ií]vares)\b/iu', $str) && !preg_match('/(\$|usd|d[oó]lar)/iu', $str)) {
            return 0.0;
        }
        if (!preg_match('/(\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,4})?|\d+(?:[.,]\d{1,4})?)/', $str, $m)) {
            return 0.0;
        }
        $num = $m[1];
        $has_comma = (strpos($num, ',') !== false);
        $has_dot = (strpos($num, '.') !== false);

        if ($has_comma && $has_dot) {
            $last_comma = strrpos($num, ',');
            $last_dot = strrpos($num, '.');
            if ($last_comma > $last_dot) {
                // Formato europeo/latino: 1.250,50 -> 1250.50
                $num = str_replace('.', '', $num);
                $num = str_replace(',', '.', $num);
            } else {
                // Formato anglosajón: 1,250.50 -> 1250.50
                $num = str_replace(',', '', $num);
            }
        } elseif ($has_comma) {
            $parts = explode(',', $num);
            $last_part = end($parts);
            if (count($parts) === 2 && (strlen($last_part) !== 3 || $parts[0] === '0')) {
                // Ej: 12,50 o 0,125 -> decimal
                $num = str_replace(',', '.', $num);
            } else {
                // Ej: 1,250 o 1,250,000 -> separador de miles en USD
                $num = str_replace(',', '', $num);
            }
        } elseif ($has_dot) {
            $parts = explode('.', $num);
            if (count($parts) > 2) {
                // Múltiples puntos (ej. 1.250.000) -> separadores de miles
                $num = str_replace('.', '', $num);
            }
            // Un único punto (ej. 906.50 o $1.250 USD) se preserva como punto decimal estándar USD
        }

        $val = round(floatval($num), 2);
        return ($val > 0 && $val < 10000000) ? $val : 0.0;
    }
}

if (!function_exists('starfi_extract_deal_enrichment')) {
    /**
     * Extrae monto estimado en USD, correlativo y asesor desde mensajes pertenecientes ESTRICTAMENTE a la misma id_sede.
     * Nunca cruza conversaciones ni notificaciones de otra sede (CHK-CLD-01), ni arrastra ventas cerradas (GANADO/PERDIDO) a hilos nuevos.
     */
    function starfi_extract_deal_enrichment($con, $id_sede, $id_cliente, $id_conversacion = 0) {
        $result = [
            'monto_usd'          => 0.0,
            'monto_raw'          => '',
            'correlativo'        => '',
            'asesor_erp'         => '',
            'fecha_doc'          => '',
            'id_agente_sugerido' => 0,
            'fuente'             => '',
        ];
        if (!$con) {
            return $result;
        }

        $sede = intval($id_sede);
        $cli = intval($id_cliente);
        $conv = intval($id_conversacion);
        if ($cli <= 0 && $conv <= 0) {
            return $result;
        }

        // Resolver teléfono y sede real de la conversación o del cliente
        $cli_phone = '';
        if ($conv > 0) {
            $stmtC = $con->prepare("
                SELECT c.id_cliente, l.id_sede, cl.numero_whatsapp
                FROM conversaciones c
                JOIN clientes_contactos cl ON c.id_cliente = cl.id
                LEFT JOIN lineas_whatsapp l ON c.id_linea = l.id
                WHERE c.id = ?
                LIMIT 1
            ");
            if ($stmtC) {
                $stmtC->bind_param("i", $conv);
                $stmtC->execute();
                if ($rc = $stmtC->get_result()->fetch_assoc()) {
                    $conv_sede = intval($rc['id_sede'] ?? 0);
                    // Si se solicitó una sede específica y la conversación pertenece a OTRA sede, abortar cruce
                    if ($sede > 0 && $conv_sede > 0 && $conv_sede !== $sede) {
                        $stmtC->close();
                        return $result;
                    }
                    if ($cli <= 0) {
                        $cli = intval($rc['id_cliente']);
                    }
                    if ($sede <= 0) {
                        $sede = $conv_sede;
                    }
                    $cli_phone = preg_replace('/[^0-9]/', '', (string)($rc['numero_whatsapp'] ?? ''));
                }
                $stmtC->close();
            }
        }

        if ($cli > 0 && ($cli_phone === '' || $sede <= 0)) {
            $stmtCl = $con->prepare("SELECT numero_whatsapp, id_sede FROM clientes_contactos WHERE id = ? LIMIT 1");
            if ($stmtCl) {
                $stmtCl->bind_param("i", $cli);
                $stmtCl->execute();
                if ($rcl = $stmtCl->get_result()->fetch_assoc()) {
                    if ($cli_phone === '') {
                        $cli_phone = preg_replace('/[^0-9]/', '', (string)($rcl['numero_whatsapp'] ?? ''));
                    }
                    if ($sede <= 0) {
                        $sede = intval($rcl['id_sede'] ?? 0);
                    }
                }
                $stmtCl->close();
            }
        }

        // Construir lista de conversaciones candidatas ESTRICTAMENTE acotadas a $sede
        $cand_conv_ids = [];
        if ($conv > 0) {
            $cand_conv_ids[$conv] = $conv;
        }

        if ($sede > 0) {
            $cand_client_ids = [];
            if ($cli > 0) {
                $cand_client_ids[$cli] = $cli;
            }
            if ($cli_phone !== '') {
                $stmtPh = $con->prepare("
                    SELECT DISTINCT cl.id
                    FROM clientes_contactos cl
                    LEFT JOIN conversaciones cv ON cv.id_cliente = cl.id
                    LEFT JOIN lineas_whatsapp lw ON cv.id_linea = lw.id
                    WHERE cl.numero_whatsapp = ?
                      AND (cl.id_sede = ? OR lw.id_sede = ?)
                    LIMIT 10
                ");
                if ($stmtPh) {
                    $stmtPh->bind_param("sii", $cli_phone, $sede, $sede);
                    $stmtPh->execute();
                    $rPh = $stmtPh->get_result();
                    while ($rp = $rPh->fetch_assoc()) {
                        $cid_p = intval($rp['id']);
                        if ($cid_p > 0) {
                            $cand_client_ids[$cid_p] = $cid_p;
                        }
                    }
                    $stmtPh->close();
                }
            }

            if (!empty($cand_client_ids)) {
                $cl_ids = array_values(array_map('intval', $cand_client_ids));
                $placeholders = implode(',', array_fill(0, count($cl_ids), '?'));
                // Filtrar conversaciones EXCLUSIVAMENTE de la línea WhatsApp perteneciente a $sede
                // y excluir hilos hermanos que ya tengan una oportunidad cerrada (GANADO/PERDIDO)
                $sql_cvs = "
                    SELECT c.id
                    FROM conversaciones c
                    INNER JOIN lineas_whatsapp l ON c.id_linea = l.id
                    WHERE c.id_cliente IN ($placeholders)
                      AND l.id_sede = ?
                      AND (
                          c.id = ?
                          OR NOT EXISTS (
                              SELECT 1 FROM pipeline_oportunidades po
                              WHERE po.id_conversacion = c.id
                                AND po.etapa IN ('GANADO', 'PERDIDO')
                          )
                      )
                    ORDER BY (c.id = ?) DESC, c.id DESC
                    LIMIT 15
                ";
                $stmtCvs = $con->prepare($sql_cvs);
                if ($stmtCvs) {
                    $types = str_repeat('i', count($cl_ids)) . 'iii';
                    $bind_vals = array_merge($cl_ids, [$sede, $conv, $conv]);
                    $stmtCvs->bind_param($types, ...$bind_vals);
                    $stmtCvs->execute();
                    $rCvs = $stmtCvs->get_result();
                    while ($rcv = $rCvs->fetch_assoc()) {
                        $cv_i = intval($rcv['id']);
                        if ($cv_i > 0) {
                            $cand_conv_ids[$cv_i] = $cv_i;
                        }
                    }
                    $stmtCvs->close();
                }
            }
        }

        // 1. PRIORIDAD 1: Mensajes API_TRANSACCIONAL en las conversaciones de esta sede únicamente
        if (!empty($cand_conv_ids)) {
            $cv_list = array_values(array_map('intval', $cand_conv_ids));
            $cv_ph = implode(',', array_fill(0, count($cv_list), '?'));
            $sql_api = "
                SELECT `contenido`
                FROM `mensajes_y_eventos`
                WHERE `id_conversacion` IN ($cv_ph)
                  AND `origen` = 'API_TRANSACCIONAL'
                  AND `contenido` NOT LIKE '%starfi_difusion_directa%'
                  AND `contenido` NOT LIKE '%starfi_csat_survey%'
                  AND `contenido` NOT LIKE '%starfi_notificacion_interna%'
                  AND `contenido` NOT LIKE '%[DIFUSIÓN:%'
                  AND `contenido` NOT LIKE '%| Texto:%'
                ORDER BY (`id_conversacion` = ?) DESC, `id` DESC
                LIMIT 6
            ";
            $stmtApi = $con->prepare($sql_api);
            if ($stmtApi) {
                $types_api = str_repeat('i', count($cv_list)) . 'i';
                $vals_api = array_merge($cv_list, [$conv]);
                $stmtApi->bind_param($types_api, ...$vals_api);
                $stmtApi->execute();
                $rApi = $stmtApi->get_result();
                while ($rm = $rApi->fetch_assoc()) {
                    $txt = (string)($rm['contenido'] ?? '');
                    if (preg_match('/Params:\s*(\[.*?\])/s', $txt, $mp)) {
                        $params = json_decode($mp[1], true);
                        if (is_array($params) && count($params) >= 4 && isset($params[2]) && preg_match('/^\s*\$?\s*\d+(?:[.,]\d+)*\s*(?:\+\s*IVA)?\s*(?:USD|EUR|\$)?\s*(?:\+\s*IVA)?\s*$/iu', (string)$params[2])) {
                            $parsed = starfi_parse_usd_number($params[2]);
                            if ($parsed > 0) {
                                $result['monto_usd'] = $parsed;
                                $result['monto_raw'] = trim((string)$params[2]);
                                $result['fecha_doc'] = trim((string)($params[1] ?? ''));
                                $result['asesor_erp'] = trim((string)($params[3] ?? ''));
                                $result['correlativo'] = trim((string)($params[5] ?? ''));
                                $result['fuente'] = 'API_TRANSACCIONAL';
                                break;
                            }
                        }
                    }
                    if (preg_match('/monto\s+total:\s*([^,]+)/iu', $txt, $mm)) {
                        $parsed = starfi_parse_usd_number($mm[1]);
                        if ($parsed > 0) {
                            $result['monto_usd'] = $parsed;
                            $result['monto_raw'] = trim($mm[1]);
                            if (preg_match('/correlativo:\s*([^,]+)/iu', $txt, $mc)) {
                                $result['correlativo'] = trim($mc[1]);
                            }
                            if (preg_match('/asesor\s+de\s+ventas:\s*([^,]+)/iu', $txt, $ma)) {
                                $result['asesor_erp'] = trim($ma[1]);
                            }
                            $result['fuente'] = 'API_LEGACY';
                            break;
                        }
                    }
                }
                $stmtApi->close();
            }
        }

        // 2. PRIORIDAD 2: Cotización escrita en USD por AGENTE o BOT en el chat activo
        if ($result['monto_usd'] <= 0 && $conv > 0) {
            $stmtQuote = $con->prepare("
                SELECT contenido
                FROM mensajes_y_eventos
                WHERE id_conversacion = ?
                  AND origen IN ('AGENTE', 'BOT')
                  AND tipo = 'TEXTO'
                ORDER BY id DESC
                LIMIT 15
            ");
            if ($stmtQuote) {
                $stmtQuote->bind_param("i", $conv);
                $stmtQuote->execute();
                $rQuote = $stmtQuote->get_result();
                while ($rq = $rQuote->fetch_assoc()) {
                    $qtxt = (string)($rq['contenido'] ?? '');
                    if (preg_match_all('/(?:\$\s*(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,4})?|\d+(?:[.,]\d{1,4})?)|(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,4})?|\d+(?:[.,]\d{1,4})?)\s*(?:\$|usd|d[oó]lares\b))/iu', $qtxt, $matches, PREG_SET_ORDER)) {
                        $last_match = end($matches);
                        $candidate_str = !empty($last_match[1]) ? $last_match[1] : ($last_match[2] ?? '');
                        $parsed_q = starfi_parse_usd_number($candidate_str);
                        if ($parsed_q > 0) {
                            $result['monto_usd'] = $parsed_q;
                            $result['monto_raw'] = '$' . number_format($parsed_q, 2) . ' USD';
                            $result['fuente'] = 'COTIZACION_CHAT';
                            break;
                        }
                    }
                }
                $stmtQuote->close();
            }
        }

        // Intentar vincular id_agente_sugerido exclusivamente entre usuarios activos de la misma $sede
        if ($result['asesor_erp'] !== '' && $sede > 0) {
            $as_clean = trim(preg_replace('/\s+/', ' ', $result['asesor_erp']));
            $parts = explode(' ', $as_clean);
            $first_word = $parts[0] ?? '';
            if (strlen($first_word) >= 3) {
                $first_like = $first_word . '%';
                $stmtAg = $con->prepare("
                    SELECT u.id
                    FROM usuario u
                    JOIN usuario_perfil up ON up.id_usuario = u.id
                    WHERE u.id_sede = ?
                      AND u.estado = 'ACTIVO'
                      AND (UPPER(TRIM(up.nombre)) = UPPER(?) OR UPPER(up.nombre) LIKE UPPER(?))
                    ORDER BY (UPPER(TRIM(up.nombre)) = UPPER(?)) DESC, u.id ASC
                    LIMIT 1
                ");
                if ($stmtAg) {
                    $stmtAg->bind_param("isss", $sede, $as_clean, $first_like, $as_clean);
                    $stmtAg->execute();
                    if ($rag = $stmtAg->get_result()->fetch_assoc()) {
                        $result['id_agente_sugerido'] = intval($rag['id']);
                    }
                    $stmtAg->close();
                }
            }
        }

        return $result;
    }
}

if (!function_exists('starfi_backfill_zero_deals')) {
    function starfi_backfill_zero_deals($con, $id_sede_filter = 0, $limit = 150) {
        if (!$con) {
            return 0;
        }
        $sede_f = intval($id_sede_filter);
        $lim = max(1, min(300, intval($limit)));

        if ($sede_f > 0) {
            $stmt = $con->prepare("
                SELECT `id`, `id_sede`, `id_cliente`, `id_conversacion`, `id_agente`, `titulo`, `notas`
                FROM `pipeline_oportunidades`
                WHERE `monto_estimado_usd` <= 0 AND `id_sede` = ?
                ORDER BY `id` DESC
                LIMIT ?
            ");
            if ($stmt) {
                $stmt->bind_param("ii", $sede_f, $lim);
            }
        } else {
            $stmt = $con->prepare("
                SELECT `id`, `id_sede`, `id_cliente`, `id_conversacion`, `id_agente`, `titulo`, `notas`
                FROM `pipeline_oportunidades`
                WHERE `monto_estimado_usd` <= 0 AND `id_sede` > 0
                ORDER BY `id` DESC
                LIMIT ?
            ");
            if ($stmt) {
                $stmt->bind_param("i", $lim);
            }
        }

        if (!$stmt || !$stmt->execute()) {
            if ($stmt) $stmt->close();
            return 0;
        }
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        $updated = 0;
        foreach ($rows as $row) {
            $did = intval($row['id']);
            $enr = starfi_extract_deal_enrichment(
                $con,
                intval($row['id_sede']),
                intval($row['id_cliente']),
                intval($row['id_conversacion'] ?? 0)
            );
            if ($enr['monto_usd'] <= 0) {
                continue;
            }

            $new_tit = trim((string)($row['titulo'] ?? ''));
            if ($enr['correlativo'] !== '' && stripos($new_tit, $enr['correlativo']) === false) {
                if (stripos($new_tit, 'Lead WhatsApp') === 0 || stripos($new_tit, 'Oportunidad') === 0) {
                    $new_tit = mb_substr($new_tit . ' · ' . $enr['correlativo'], 0, 195);
                }
            }

            $new_notas = trim((string)($row['notas'] ?? ''));
            if ($new_notas === '' && ($enr['correlativo'] !== '' || $enr['asesor_erp'] !== '' || $enr['monto_raw'] !== '')) {
                $nota_parts = [];
                if ($enr['correlativo'] !== '') $nota_parts[] = "Documento: " . $enr['correlativo'];
                if ($enr['monto_raw'] !== '') $nota_parts[] = "Monto mensaje: " . $enr['monto_raw'];
                if ($enr['asesor_erp'] !== '') $nota_parts[] = "Vendedor ERP: " . $enr['asesor_erp'];
                if ($enr['fecha_doc'] !== '') $nota_parts[] = "Fecha: " . $enr['fecha_doc'];
                $new_notas = implode(' | ', $nota_parts);
            }

            $cur_ag = intval($row['id_agente'] ?? 0);
            $new_ag = ($cur_ag > 0) ? $cur_ag : ($enr['id_agente_sugerido'] > 0 ? intval($enr['id_agente_sugerido']) : null);
            $notas_db = ($new_notas !== '') ? $new_notas : null;

            $stmtUp = $con->prepare("
                UPDATE `pipeline_oportunidades`
                SET `monto_estimado_usd` = ?,
                    `titulo` = ?,
                    `notas` = ?,
                    `id_agente` = ?,
                    `fecha_actualizacion` = `fecha_actualizacion`
                WHERE `id` = ? AND `monto_estimado_usd` <= 0
            ");
            if ($stmtUp) {
                $stmtUp->bind_param("dssii", $enr['monto_usd'], $new_tit, $notas_db, $new_ag, $did);
                if ($stmtUp->execute() && $stmtUp->affected_rows > 0) {
                    $updated++;
                }
                $stmtUp->close();
            }
        }
        return $updated;
    }
}

if (!function_exists('starfi_ensure_pipeline_lead')) {
    /**
     * Garantiza transaccionalmente (con bloqueo de fila SELECT ... FOR UPDATE) una única oportunidad activa
     * por conversación/cliente dentro de la sede indicada, sin condiciones de carrera ante webhooks simultáneos.
     */
    function starfi_ensure_pipeline_lead($con, $id_sede, $id_cliente, $id_conversacion = null, $id_agente = null, $titulo_default = '', $monto_override = null, $notas_default = '') {
        if (!$con || intval($id_cliente) <= 0) {
            return 0;
        }
        starfi_ensure_sprint2_schema($con);
        $sede = intval($id_sede);
        $cli = intval($id_cliente);
        $conv = ($id_conversacion !== null && intval($id_conversacion) > 0) ? intval($id_conversacion) : null;
        $ag = ($id_agente !== null && intval($id_agente) > 0) ? intval($id_agente) : null;

        $enr = starfi_extract_deal_enrichment($con, $sede, $cli, $conv ? $conv : 0);
        $monto_final = ($monto_override !== null && floatval($monto_override) > 0)
            ? round(floatval($monto_override), 2)
            : $enr['monto_usd'];
        if (!$ag && $enr['id_agente_sugerido'] > 0) {
            $ag = intval($enr['id_agente_sugerido']);
        }

        $own_tx = !starfi_tx_is_active($con);
        if ($own_tx) {
            $con->begin_transaction();
            starfi_tx_set_active($con, true);
        }

        try {
            // Bloquear fila ancla del cliente para serializar inserciones concurrentes sobre el mismo lead
            $stmtLockCli = $con->prepare("SELECT `id`, `nombre`, `numero_whatsapp` FROM `clientes_contactos` WHERE `id` = ? LIMIT 1 FOR UPDATE");
            $cl_row = null;
            if ($stmtLockCli) {
                $stmtLockCli->bind_param("i", $cli);
                $stmtLockCli->execute();
                $cl_row = $stmtLockCli->get_result()->fetch_assoc();
                $stmtLockCli->close();
            }

            // Buscar oportunidad abierta existente con bloqueo exclusivo FOR UPDATE y aislamiento por sede
            if ($conv) {
                $stmtFind = $con->prepare("
                    SELECT `id`, `id_agente`, `id_conversacion`, `id_sede`, `monto_estimado_usd`, `titulo`, `notas`
                    FROM `pipeline_oportunidades`
                    WHERE (`id_conversacion` = ? OR (`id_cliente` = ? AND `etapa` NOT IN ('GANADO','PERDIDO')))
                      AND `id_sede` = ?
                    ORDER BY (`id_conversacion` = ?) DESC, `id` DESC
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmtFind->bind_param("iiii", $conv, $cli, $sede, $conv);
            } else {
                $stmtFind = $con->prepare("
                    SELECT `id`, `id_agente`, `id_conversacion`, `id_sede`, `monto_estimado_usd`, `titulo`, `notas`
                    FROM `pipeline_oportunidades`
                    WHERE `id_cliente` = ?
                      AND `id_sede` = ?
                      AND `etapa` NOT IN ('GANADO','PERDIDO')
                    ORDER BY `id` DESC
                    LIMIT 1
                    FOR UPDATE
                ");
                $stmtFind->bind_param("ii", $cli, $sede);
            }

            $existing = null;
            if ($stmtFind && $stmtFind->execute()) {
                $existing = $stmtFind->get_result()->fetch_assoc();
                $stmtFind->close();
            } elseif ($stmtFind) {
                $stmtFind->close();
            }

            if ($existing) {
                $deal_id = intval($existing['id']);
                $new_ag = (intval($existing['id_agente'] ?? 0) > 0) ? intval($existing['id_agente']) : $ag;
                $new_conv = (intval($existing['id_conversacion'] ?? 0) > 0) ? intval($existing['id_conversacion']) : $conv;
                $cur_monto = floatval($existing['monto_estimado_usd'] ?? 0);
                $new_monto = ($cur_monto > 0) ? $cur_monto : max(0.0, $monto_final);

                $stmtUpd = $con->prepare("
                    UPDATE `pipeline_oportunidades`
                    SET `id_agente` = ?, `id_conversacion` = ?, `monto_estimado_usd` = ?
                    WHERE `id` = ?
                ");
                if ($stmtUpd) {
                    $stmtUpd->bind_param("iidi", $new_ag, $new_conv, $new_monto, $deal_id);
                    $stmtUpd->execute();
                    $stmtUpd->close();
                }

                if ($own_tx) {
                    $con->commit();
                    starfi_tx_set_active($con, false);
                }
                return $deal_id;
            }

            $titulo = trim((string)$titulo_default);
            if ($titulo === '') {
                if ($cl_row) {
                    $nom = trim((string)($cl_row['nombre'] ?? ''));
                    $tel = trim((string)($cl_row['numero_whatsapp'] ?? ''));
                    $titulo = ($nom !== '') ? "Lead WhatsApp - $nom" : "Lead WhatsApp +$tel";
                } else {
                    $titulo = "Lead WhatsApp #$cli";
                }
                if ($enr['correlativo'] !== '') {
                    $titulo .= ' · ' . $enr['correlativo'];
                }
            }
            $titulo = mb_substr($titulo, 0, 195);

            $notas = trim((string)$notas_default);
            if ($notas === '' && ($enr['correlativo'] !== '' || $enr['asesor_erp'] !== '' || $enr['monto_raw'] !== '')) {
                $np = [];
                if ($enr['correlativo'] !== '') $np[] = "Documento: " . $enr['correlativo'];
                if ($enr['monto_raw'] !== '') $np[] = "Monto mensaje: " . $enr['monto_raw'];
                if ($enr['asesor_erp'] !== '') $np[] = "Vendedor ERP: " . $enr['asesor_erp'];
                if ($enr['fecha_doc'] !== '') $np[] = "Fecha: " . $enr['fecha_doc'];
                $notas = implode(' | ', $np);
            }
            $notas_param = ($notas !== '') ? $notas : null;
            $monto_ins = max(0.0, $monto_final);

            $stmtIns = $con->prepare("
                INSERT INTO `pipeline_oportunidades`
                    (`id_sede`, `id_cliente`, `id_conversacion`, `id_agente`, `titulo`, `etapa`, `monto_estimado_usd`, `probabilidad`, `notas`)
                VALUES (?, ?, ?, ?, ?, 'NUEVO_LEAD', ?, 20, ?)
            ");
            $new_deal_id = 0;
            if ($stmtIns) {
                $stmtIns->bind_param("iiiisds", $sede, $cli, $conv, $ag, $titulo, $monto_ins, $notas_param);
                if ($stmtIns->execute()) {
                    $new_deal_id = intval($stmtIns->insert_id);
                }
                $stmtIns->close();
            }

            if ($own_tx) {
                $con->commit();
                starfi_tx_set_active($con, false);
            }
            return $new_deal_id;
        } catch (Throwable $e) {
            if ($own_tx) {
                @$con->rollback();
                starfi_tx_set_active($con, false);
            }
            return 0;
        }
    }
}

if (!function_exists('starfi_assign_round_robin_advisor')) {
    /**
     * [COM-03] Enrutamiento Automático Round-Robin transaccional con SELECT ... FOR UPDATE.
     * Serializa asignaciones concurrentes sobre los asesores en línea de la sede para impedir colisiones de puntero.
     */
    function starfi_assign_round_robin_advisor($con, $id_sede, $id_conversacion, $id_cliente = 0, $motivo_origen = 'IA', $online_window_minutes = null) {
        if (!$con || intval($id_sede) <= 0 || intval($id_conversacion) <= 0) {
            return null;
        }
        starfi_ensure_sprint2_schema($con);

        $sede = intval($id_sede);
        $conv_id = intval($id_conversacion);
        if ($online_window_minutes === null) {
            $sla_c = starfi_get_sla_config($con, $sede);
            $win_min = max(1, intval($sla_c['round_robin_online_min'] ?? 5));
        } else {
            $win_min = max(1, intval($online_window_minutes));
        }

        $own_tx = !starfi_tx_is_active($con);
        if ($own_tx) {
            $con->begin_transaction();
            starfi_tx_set_active($con, true);
        }

        try {
            // 1. Bloquear la fila de la conversación objetivo
            $stmtConvLock = $con->prepare("SELECT `id`, `id_agente`, `estado` FROM `conversaciones` WHERE `id` = ? LIMIT 1 FOR UPDATE");
            if ($stmtConvLock) {
                $stmtConvLock->bind_param("i", $conv_id);
                $stmtConvLock->execute();
                $stmtConvLock->get_result()->fetch_assoc();
                $stmtConvLock->close();
            }

            // 2. Bloquear exclusivamente los usuarios candidatos en línea de la sede (tabla pura InnoDB con FOR UPDATE)
            $stmtLockUsers = $con->prepare("
                SELECT `id`, `usuario`, `rol`,
                       IFNULL(`limite_chats_simultaneos`, 0) AS `limite_chats`,
                       `ultima_asignacion_rr`
                FROM `usuario`
                WHERE `id_sede` = ?
                  AND (`estado` = 'ACTIVO' OR CAST(`estado` AS CHAR) = '1')
                  AND IFNULL(`disponible_chat`, 1) = 1
                  AND `id` != 1
                  AND LOWER(`usuario`) != 'master'
                  AND `ultima_actividad` IS NOT NULL
                  AND `ultima_actividad` >= (NOW() - INTERVAL ? MINUTE)
                FOR UPDATE
            ");
            $raw_candidates = [];
            if ($stmtLockUsers) {
                $stmtLockUsers->bind_param("ii", $sede, $win_min);
                $stmtLockUsers->execute();
                $rLock = $stmtLockUsers->get_result();
                while ($uRow = $rLock->fetch_assoc()) {
                    $raw_candidates[] = $uRow;
                }
                $stmtLockUsers->close();
            }

            // 3. Filtrar por rol operativo elegible y calcular carga activa bajo el candado transaccional
            $roles_map = [];
            $rRoles = @mysqli_query($con, "SELECT `id`, UPPER(TRIM(`nombre`)) AS `rname` FROM `roles`");
            if ($rRoles) {
                while ($rr = mysqli_fetch_assoc($rRoles)) {
                    $roles_map[intval($rr['id'])] = (string)$rr['rname'];
                }
            }

            $valid_roles = ['OPERADOR', 'ASESOR', 'AGENTE', 'VENDEDOR'];
            $eligible = [];
            foreach ($raw_candidates as $cand) {
                $rol_raw = strtoupper(trim((string)($cand['rol'] ?? '')));
                $rol_id = intval($cand['rol'] ?? 0);
                $rol_name = $roles_map[$rol_id] ?? '';
                if (in_array($rol_name, $valid_roles, true) || in_array($rol_raw, ['3', 'OPERADOR', 'ASESOR', 'AGENTE', 'VENDEDOR'], true)) {
                    $eligible[intval($cand['id'])] = $cand;
                }
            }

            $elegido = null;
            if (!empty($eligible)) {
                $ids_el = array_keys($eligible);
                $ph_el = implode(',', array_fill(0, count($ids_el), '?'));
                $types_el = str_repeat('i', count($ids_el));

                // Carga de chats activos por asesor
                $loads = [];
                $stmtLoad = $con->prepare("
                    SELECT `id_agente`, COUNT(*) AS `chats_activos`
                    FROM `conversaciones`
                    WHERE `estado` IN ('ESPERA_ASIGNACION', 'ATENDIENDO')
                      AND `id_agente` IN ($ph_el)
                    GROUP BY `id_agente`
                ");
                if ($stmtLoad) {
                    $stmtLoad->bind_param($types_el, ...$ids_el);
                    $stmtLoad->execute();
                    $rLd = $stmtLoad->get_result();
                    while ($ld = $rLd->fetch_assoc()) {
                        $loads[intval($ld['id_agente'])] = intval($ld['chats_activos']);
                    }
                    $stmtLoad->close();
                }

                // Perfiles de asesores
                $perfiles = [];
                $stmtPf = $con->prepare("SELECT `id_usuario`, `nombre`, `telefono` FROM `usuario_perfil` WHERE `id_usuario` IN ($ph_el)");
                if ($stmtPf) {
                    $stmtPf->bind_param($types_el, ...$ids_el);
                    $stmtPf->execute();
                    $rPf = $stmtPf->get_result();
                    while ($pf = $rPf->fetch_assoc()) {
                        $perfiles[intval($pf['id_usuario'])] = $pf;
                    }
                    $stmtPf->close();
                }

                $scored = [];
                foreach ($eligible as $uid => $cand) {
                    $chats_act = $loads[$uid] ?? 0;
                    $lim_c = intval($cand['limite_chats'] ?? 0);
                    $at_cap = ($lim_c > 0 && $chats_act >= $lim_c) ? 1 : 0;
                    $rr_ts = !empty($cand['ultima_asignacion_rr']) ? strtotime((string)$cand['ultima_asignacion_rr']) : 0;
                    $scored[] = [
                        'id'            => $uid,
                        'usuario'       => $cand['usuario'],
                        'up_nombre'     => $perfiles[$uid]['nombre'] ?? '',
                        'up_telefono'   => $perfiles[$uid]['telefono'] ?? '',
                        'at_cap'        => $at_cap,
                        'rr_is_null'    => ($rr_ts === 0) ? 0 : 1, // 0 primero cuando nunca ha recibido lead
                        'rr_ts'         => $rr_ts,
                        'chats_activos' => $chats_act,
                    ];
                }

                usort($scored, function ($a, $b) {
                    if ($a['at_cap'] !== $b['at_cap']) return $a['at_cap'] <=> $b['at_cap'];
                    if ($a['rr_is_null'] !== $b['rr_is_null']) return $a['rr_is_null'] <=> $b['rr_is_null'];
                    if ($a['rr_ts'] !== $b['rr_ts']) return $a['rr_ts'] <=> $b['rr_ts'];
                    if ($a['chats_activos'] !== $b['chats_activos']) return $a['chats_activos'] <=> $b['chats_activos'];
                    return $a['id'] <=> $b['id'];
                });

                $elegido = $scored[0];
            }

            if (!$elegido) {
                $stmtWait = $con->prepare("UPDATE `conversaciones` SET `estado` = 'ESPERA_ASIGNACION' WHERE `id` = ?");
                if ($stmtWait) {
                    $stmtWait->bind_param("i", $conv_id);
                    $stmtWait->execute();
                    $stmtWait->close();
                }

                $msg_cola = "⏳ Transferencia ($motivo_origen) en cola de espera: no hay asesores en línea en esta sede en este momento.";
                $stmtEv = $con->prepare("INSERT INTO `mensajes_y_eventos` (`id_conversacion`, `origen`, `tipo`, `contenido`) VALUES (?, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', ?)");
                $ev_id = 0;
                if ($stmtEv) {
                    $stmtEv->bind_param("is", $conv_id, $msg_cola);
                    if ($stmtEv->execute()) {
                        $ev_id = intval($stmtEv->insert_id);
                    }
                    $stmtEv->close();
                }
                if ($ev_id > 0) {
                    $stmtUm = $con->prepare("UPDATE `conversaciones` SET `ultimo_msg_id` = GREATEST(IFNULL(`ultimo_msg_id`, 0), ?) WHERE `id` = ?");
                    if ($stmtUm) {
                        $stmtUm->bind_param("ii", $ev_id, $conv_id);
                        $stmtUm->execute();
                        $stmtUm->close();
                    }
                }
                if (intval($id_cliente) > 0) {
                    starfi_ensure_pipeline_lead($con, $sede, intval($id_cliente), $conv_id, null);
                }

                if ($own_tx) {
                    $con->commit();
                    starfi_tx_set_active($con, false);
                }
                return null;
            }

            $asesor_id = intval($elegido['id']);
            $up_nom = trim((string)($elegido['up_nombre'] ?? ''));
            $asesor_nombre = ($up_nom !== '') ? $up_nom : trim((string)($elegido['usuario'] ?? 'Asesor'));
            $asesor_tel = trim((string)($elegido['up_telefono'] ?? ''));

            // Actualizar puntero Round-Robin y asignar conversación atómicamente
            $stmtUpRr = $con->prepare("UPDATE `usuario` SET `ultima_asignacion_rr` = NOW() WHERE `id` = ?");
            if ($stmtUpRr) {
                $stmtUpRr->bind_param("i", $asesor_id);
                $stmtUpRr->execute();
                $stmtUpRr->close();
            }

            $stmtUpCv = $con->prepare("UPDATE `conversaciones` SET `id_agente` = ?, `estado` = 'ESPERA_ASIGNACION' WHERE `id` = ?");
            if ($stmtUpCv) {
                $stmtUpCv->bind_param("ii", $asesor_id, $conv_id);
                $stmtUpCv->execute();
                $stmtUpCv->close();
            }

            $ev_txt = "🔀 Enrutamiento Round-Robin ($motivo_origen): conversación asignada automáticamente al asesor en línea $asesor_nombre.";
            $stmtEv = $con->prepare("INSERT INTO `mensajes_y_eventos` (`id_conversacion`, `origen`, `tipo`, `contenido`) VALUES (?, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', ?)");
            $ev_id = 0;
            if ($stmtEv) {
                $stmtEv->bind_param("is", $conv_id, $ev_txt);
                if ($stmtEv->execute()) {
                    $ev_id = intval($stmtEv->insert_id);
                }
                $stmtEv->close();
            }
            if ($ev_id > 0) {
                $stmtUm = $con->prepare("UPDATE `conversaciones` SET `ultimo_msg_id` = GREATEST(IFNULL(`ultimo_msg_id`, 0), ?) WHERE `id` = ?");
                if ($stmtUm) {
                    $stmtUm->bind_param("ii", $ev_id, $conv_id);
                    $stmtUm->execute();
                    $stmtUm->close();
                }
            }

            if (intval($id_cliente) > 0) {
                starfi_ensure_pipeline_lead($con, $sede, intval($id_cliente), $conv_id, $asesor_id);
            }

            if ($own_tx) {
                $con->commit();
                starfi_tx_set_active($con, false);
            }

            return [
                'id'       => $asesor_id,
                'nombre'   => $asesor_nombre,
                'telefono' => $asesor_tel
            ];
        } catch (Throwable $e) {
            if ($own_tx) {
                @$con->rollback();
                starfi_tx_set_active($con, false);
            }
            return null;
        }
    }
}

if (!function_exists('starfi_persist_media_cache')) {
    /**
     * Persiste la ruta local cacheada de un archivo multimedia WABA y audita la operación (CHK-ARC-02 / CHK-SEC-04).
     */
    function starfi_persist_media_cache($con, $chat_id, $media_id, $local_url, $agente_id = null, $id_sede = null) {
        if (!($con instanceof mysqli)) {
            return false;
        }
        $cid = intval($chat_id);
        $mid = trim((string)$media_id);
        $url = trim((string)$local_url);
        if ($cid <= 0 || $mid === '' || $url === '') {
            return false;
        }
        $stmtUpd = $con->prepare("UPDATE `mensajes_y_eventos` SET `url_archivo` = ? WHERE `id_conversacion` = ? AND `url_archivo` = ?");
        if (!$stmtUpd) {
            return false;
        }
        $stmtUpd->bind_param("sis", $url, $cid, $mid);
        $ok = $stmtUpd->execute();
        $affected = $stmtUpd->affected_rows;
        $stmtUpd->close();

        if ($ok && $affected > 0) {
            require_once __DIR__ . '/audit.php';
            registrar_auditoria(
                'bandeja',
                'CACHE_MEDIA_WABA',
                "Materialización de caché local multimedia WABA en conversación #{$cid}",
                ['url_archivo' => $mid],
                ['id_conversacion' => $cid, 'url_archivo' => $url],
                $con,
                $agente_id,
                $id_sede
            );
        }
        return (bool)$ok;
    }
}
