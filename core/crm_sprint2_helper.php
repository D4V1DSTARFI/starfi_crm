<?php
/**
 * core/crm_sprint2_helper.php
 * Motor central para OT-STARFI-2026-11 (SPRINT 2):
 * - Auto-migración ligera e indexada (Presencia en línea, Round-Robin, Pipeline Kanban USD, SLA)
 * - Heartbeat de presencia de asesores en línea por sede
 * - Enrutamiento Automático Round-Robin ([COM-03])
 * - Sincronización de Oportunidades del Embudo de Ventas ([COM-02])
 */

if (!function_exists('starfi_ensure_sprint2_schema')) {
    function starfi_ensure_sprint2_schema($con) {
        if (!$con) {
            return;
        }
        $flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'starfi_sprint2_schema_v3.flag';
        if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
            return;
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
    /**
     * Obtiene los umbrales de SLA aplicables a una sede (si tiene regla propia usa la de la sede; si no, hereda la Global id_sede=0).
     */
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
        $sql = ($sede > 0)
            ? "SELECT * FROM `config_sla` WHERE `id_sede` IN (0, $sede) ORDER BY (`id_sede` = $sede) DESC LIMIT 1"
            : "SELECT * FROM `config_sla` WHERE `id_sede` = 0 LIMIT 1";
        $res = @mysqli_query($con, $sql);
        if ($res && ($row = mysqli_fetch_assoc($res))) {
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

        $ok = @mysqli_query($con, "
            INSERT INTO `config_sla` (`id_sede`, `verde_max_min`, `amarillo_max_min`, `round_robin_online_min`, `alerta_sonora_activa`)
            VALUES ($sede, $v, $a, $rr, $snd)
            ON DUPLICATE KEY UPDATE
                `verde_max_min` = VALUES(`verde_max_min`),
                `amarillo_max_min` = VALUES(`amarillo_max_min`),
                `round_robin_online_min` = VALUES(`round_robin_online_min`),
                `alerta_sonora_activa` = VALUES(`alerta_sonora_activa`)
        ");
        if (!$ok) {
            return ['ok' => false, 'message' => 'No se pudo guardar la configuración SLA en base de datos.'];
        }
        return ['ok' => true, 'config' => starfi_get_sla_config($con, $sede)];
    }
}

if (!function_exists('starfi_touch_agent_presence')) {
    /**
     * Registra actividad reciente del usuario en BD para detección en tiempo real de asesores en línea (Round-Robin).
     */
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
        @mysqli_query($con, "UPDATE `usuario` SET `ultima_actividad` = NOW() WHERE `id` = $uid");
    }
}

if (!function_exists('starfi_parse_usd_number')) {
    /**
     * Convierte cadenas monetarias como "906.50 + IVA", "1,019.30 USD", "1.250,50" o "$236.40" a float USD.
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
        if (!preg_match('/(\d{1,3}(?:[.,]\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)/', $str, $m)) {
            return 0.0;
        }
        $num = $m[1];
        $has_comma = (strpos($num, ',') !== false);
        $has_dot = (strpos($num, '.') !== false);

        if ($has_comma && $has_dot) {
            $last_comma = strrpos($num, ',');
            $last_dot = strrpos($num, '.');
            if ($last_comma > $last_dot) {
                // Formato europeo/latino: 1.250,50
                $num = str_replace('.', '', $num);
                $num = str_replace(',', '.', $num);
            } else {
                // Formato anglosajón: 1,250.50
                $num = str_replace(',', '', $num);
            }
        } elseif ($has_comma) {
            $parts = explode(',', $num);
            $last_part = end($parts);
            if (count($parts) === 2 && strlen($last_part) <= 2) {
                $num = str_replace(',', '.', $num);
            } else {
                $num = str_replace(',', '', $num);
            }
        } elseif ($has_dot) {
            $parts = explode('.', $num);
            $last_part = end($parts);
            if (count($parts) > 2 || (count($parts) === 2 && strlen($last_part) === 3)) {
                $num = str_replace('.', '', $num);
            }
        }

        $val = round(floatval($num), 2);
        return ($val > 0 && $val < 10000000) ? $val : 0.0;
    }
}

if (!function_exists('starfi_extract_deal_enrichment')) {
    /**
     * Extrae el monto estimado en USD, correlativo y asesor desde los mensajes de la conversación o del cliente:
     * 1) Prioridad 1: Mensajes API_TRANSACCIONAL (ej. Params[2] = "12.30 + IVA", Params[5] = "NE_117500").
     * 2) Prioridad 2: Mensajes de cotización en USD enviados por AGENTE o BOT en el chat activo.
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

        // Resolver teléfono y sede del cliente/conversación para cruzar si la notificación entró en una conversación hermana
        $cli_phone = '';
        if ($cli <= 0 && $conv > 0) {
            $rC = @mysqli_query($con, "SELECT c.id_cliente, l.id_sede, cl.numero_whatsapp FROM conversaciones c JOIN clientes_contactos cl ON c.id_cliente = cl.id LEFT JOIN lineas_whatsapp l ON c.id_linea = l.id WHERE c.id = $conv LIMIT 1");
            if ($rC && ($rc = mysqli_fetch_assoc($rC))) {
                $cli = intval($rc['id_cliente']);
                if ($sede <= 0) $sede = intval($rc['id_sede'] ?? 0);
                $cli_phone = preg_replace('/[^0-9]/', '', (string)($rc['numero_whatsapp'] ?? ''));
            }
        } elseif ($cli > 0) {
            $rCl = @mysqli_query($con, "SELECT numero_whatsapp, id_sede FROM clientes_contactos WHERE id = $cli LIMIT 1");
            if ($rCl && ($rcl = mysqli_fetch_assoc($rCl))) {
                $cli_phone = preg_replace('/[^0-9]/', '', (string)($rcl['numero_whatsapp'] ?? ''));
                if ($sede <= 0) $sede = intval($rcl['id_sede'] ?? 0);
            }
        }

        // 1. PRIORIDAD 1: Consulta 100% indexada por idx_conv_origen_ts (evita OR entre tablas que causaba full scan)
        $cand_client_ids = [];
        if ($cli > 0) {
            $cand_client_ids[$cli] = $cli;
        }
        if ($cli_phone !== '') {
            $ph_esc = mysqli_real_escape_string($con, $cli_phone);
            $rPh = @mysqli_query($con, "SELECT `id` FROM `clientes_contactos` WHERE `numero_whatsapp` = '$ph_esc' LIMIT 10");
            if ($rPh) {
                while ($rp = mysqli_fetch_assoc($rPh)) {
                    $cid_p = intval($rp['id']);
                    if ($cid_p > 0) $cand_client_ids[$cid_p] = $cid_p;
                }
            }
        }

        $cand_conv_ids = [];
        if ($conv > 0) {
            $cand_conv_ids[$conv] = $conv;
        }
        if (!empty($cand_client_ids)) {
            $cl_csv = implode(',', $cand_client_ids);
            $rCvs = @mysqli_query($con, "SELECT `id` FROM `conversaciones` WHERE `id_cliente` IN ($cl_csv) ORDER BY `id` DESC LIMIT 15");
            if ($rCvs) {
                while ($rcv = mysqli_fetch_assoc($rCvs)) {
                    $cv_i = intval($rcv['id']);
                    if ($cv_i > 0) $cand_conv_ids[$cv_i] = $cv_i;
                }
            }
        }

        if (!empty($cand_conv_ids)) {
            $cv_csv = implode(',', $cand_conv_ids);
            $order_conv = ($conv > 0) ? "(`id_conversacion` = $conv) DESC, " : "";

            $sql_api = "
                SELECT `contenido`
                FROM `mensajes_y_eventos`
                WHERE `id_conversacion` IN ($cv_csv)
                  AND `origen` = 'API_TRANSACCIONAL'
                  AND `contenido` NOT LIKE '%starfi_difusion_directa%'
                  AND `contenido` NOT LIKE '%starfi_csat_survey%'
                  AND `contenido` NOT LIKE '%starfi_notificacion_interna%'
                  AND `contenido` NOT LIKE '%[DIFUSIÓN:%'
                  AND `contenido` NOT LIKE '%| Texto:%'
                ORDER BY {$order_conv}`id` DESC
                LIMIT 6
            ";
            $rApi = @mysqli_query($con, $sql_api);
            if ($rApi) {
                while ($rm = mysqli_fetch_assoc($rApi)) {
                    $txt = (string)($rm['contenido'] ?? '');
                    // Formato A: Envío dinámico de plantilla: <name>. Params: [...]
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
                    // Formato B: Legacy "monto total: 906.50 USD ... asesor de ventas: ... correlativo: ..."
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
            }
        }

        // 2. PRIORIDAD 2: Cotización escrita en USD por AGENTE o BOT en el chat activo
        if ($result['monto_usd'] <= 0 && $conv > 0) {
            $rQuote = @mysqli_query($con, "
                SELECT contenido
                FROM mensajes_y_eventos
                WHERE id_conversacion = $conv
                  AND origen IN ('AGENTE', 'BOT')
                  AND tipo = 'TEXTO'
                ORDER BY id DESC
                LIMIT 15
            ");
            if ($rQuote) {
                while ($rq = mysqli_fetch_assoc($rQuote)) {
                    $qtxt = (string)($rq['contenido'] ?? '');
                    if (preg_match_all('/(?:\$\s*(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)|(\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?)\s*(?:\$|usd|d[oó]lares\b))/iu', $qtxt, $matches, PREG_SET_ORDER)) {
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
            }
        }

        // Intentar vincular id_agente_sugerido si viene nombre de asesor ERP en la sede
        if ($result['asesor_erp'] !== '' && $sede > 0) {
            $as_clean = trim(preg_replace('/\s+/', ' ', $result['asesor_erp']));
            $parts = explode(' ', $as_clean);
            $first_word = mysqli_real_escape_string($con, $parts[0] ?? '');
            $full_esc = mysqli_real_escape_string($con, $as_clean);
            if (strlen($first_word) >= 3) {
                $rAgMatch = @mysqli_query($con, "
                    SELECT u.id
                    FROM usuario u
                    JOIN usuario_perfil up ON up.id_usuario = u.id
                    WHERE u.id_sede = $sede
                      AND u.estado = 'ACTIVO'
                      AND (UPPER(TRIM(up.nombre)) = UPPER('$full_esc') OR UPPER(up.nombre) LIKE UPPER('$first_word%'))
                    ORDER BY (UPPER(TRIM(up.nombre)) = UPPER('$full_esc')) DESC, u.id ASC
                    LIMIT 1
                ");
                if ($rAgMatch && ($rag = mysqli_fetch_assoc($rAgMatch))) {
                    $result['id_agente_sugerido'] = intval($rag['id']);
                }
            }
        }

        return $result;
    }
}

if (!function_exists('starfi_backfill_zero_deals')) {
    /**
     * Completa retroactivamente oportunidades existentes cuyo monto_estimado_usd sigue en 0.00
     * sin tocar ninguna oportunidad donde ya se haya ingresado un monto > 0.
     */
    function starfi_backfill_zero_deals($con, $id_sede_filter = 0, $limit = 150) {
        if (!$con) {
            return 0;
        }
        $sede_f = intval($id_sede_filter);
        $lim = max(1, min(300, intval($limit)));
        $where_sede = ($sede_f > 0) ? " AND `id_sede` = $sede_f" : "";

        $res = @mysqli_query($con, "
            SELECT `id`, `id_sede`, `id_cliente`, `id_conversacion`, `id_agente`, `titulo`, `notas`
            FROM `pipeline_oportunidades`
            WHERE `monto_estimado_usd` <= 0 $where_sede
            ORDER BY `id` DESC
            LIMIT $lim
        ");
        if (!$res) {
            return 0;
        }

        $updated = 0;
        while ($row = mysqli_fetch_assoc($res)) {
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

            $sets = ["`monto_estimado_usd` = " . number_format($enr['monto_usd'], 2, '.', '')];

            $cur_tit = trim((string)($row['titulo'] ?? ''));
            if ($enr['correlativo'] !== '' && stripos($cur_tit, $enr['correlativo']) === false) {
                if (stripos($cur_tit, 'Lead WhatsApp') === 0 || stripos($cur_tit, 'Oportunidad') === 0) {
                    $new_tit = mb_substr($cur_tit . ' · ' . $enr['correlativo'], 0, 195);
                    $sets[] = "`titulo` = '" . mysqli_real_escape_string($con, $new_tit) . "'";
                }
            }

            $cur_notas = trim((string)($row['notas'] ?? ''));
            if ($cur_notas === '' && ($enr['correlativo'] !== '' || $enr['asesor_erp'] !== '' || $enr['monto_raw'] !== '')) {
                $nota_parts = [];
                if ($enr['correlativo'] !== '') $nota_parts[] = "Documento: " . $enr['correlativo'];
                if ($enr['monto_raw'] !== '') $nota_parts[] = "Monto mensaje: " . $enr['monto_raw'];
                if ($enr['asesor_erp'] !== '') $nota_parts[] = "Vendedor ERP: " . $enr['asesor_erp'];
                if ($enr['fecha_doc'] !== '') $nota_parts[] = "Fecha: " . $enr['fecha_doc'];
                $nota_auto = implode(' | ', $nota_parts);
                $sets[] = "`notas` = '" . mysqli_real_escape_string($con, $nota_auto) . "'";
            }

            if (intval($row['id_agente'] ?? 0) === 0 && $enr['id_agente_sugerido'] > 0) {
                $sets[] = "`id_agente` = " . intval($enr['id_agente_sugerido']);
            }

            // Preservar fecha_actualizacion original para no alterar el orden cronológico al auto-completar
            $sets[] = "`fecha_actualizacion` = `fecha_actualizacion`";

            if (@mysqli_query($con, "UPDATE `pipeline_oportunidades` SET " . implode(', ', $sets) . " WHERE `id` = $did")) {
                $updated++;
            }
        }
        return $updated;
    }
}

if (!function_exists('starfi_ensure_pipeline_lead')) {
    /**
     * Garantiza que exista una tarjeta activa en el Embudo de Ventas para la conversación/cliente indicados,
     * auto-completando el monto estimado en USD desde los mensajes de la conversación si está disponible.
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

        // Buscar oportunidad abierta existente para esta conversación o cliente en la sede
        $where_match = $conv ? "(`id_conversacion` = $conv OR (`id_cliente` = $cli AND `etapa` NOT IN ('GANADO','PERDIDO')))" : "(`id_cliente` = $cli AND `etapa` NOT IN ('GANADO','PERDIDO'))";
        if ($sede > 0) {
            $where_match .= " AND (`id_sede` = $sede OR `id_sede` = 0)";
        }
        $res = @mysqli_query($con, "SELECT `id`, `id_agente`, `id_conversacion`, `id_sede`, `monto_estimado_usd`, `titulo`, `notas` FROM `pipeline_oportunidades` WHERE $where_match ORDER BY `id` DESC LIMIT 1");
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $deal_id = intval($row['id']);
            $updates = [];
            if ($ag && intval($row['id_agente'] ?? 0) === 0) {
                $updates[] = "`id_agente` = $ag";
            }
            if ($conv && intval($row['id_conversacion'] ?? 0) === 0) {
                $updates[] = "`id_conversacion` = $conv";
            }
            if ($sede > 0 && intval($row['id_sede'] ?? 0) === 0) {
                $updates[] = "`id_sede` = $sede";
            }
            if (floatval($row['monto_estimado_usd'] ?? 0) <= 0 && $monto_final > 0) {
                $updates[] = "`monto_estimado_usd` = " . number_format($monto_final, 2, '.', '');
            }
            if (!empty($updates)) {
                @mysqli_query($con, "UPDATE `pipeline_oportunidades` SET " . implode(', ', $updates) . " WHERE `id` = $deal_id");
            }
            return $deal_id;
        }

        // Obtener nombre del cliente si no se pasó título
        $titulo = trim((string)$titulo_default);
        if ($titulo === '') {
            $rc = @mysqli_query($con, "SELECT `nombre`, `numero_whatsapp` FROM `clientes_contactos` WHERE `id` = $cli LIMIT 1");
            if ($rc && ($cl = mysqli_fetch_assoc($rc))) {
                $nom = trim((string)($cl['nombre'] ?? ''));
                $tel = trim((string)($cl['numero_whatsapp'] ?? ''));
                $titulo = ($nom !== '') ? "Lead WhatsApp - $nom" : "Lead WhatsApp +$tel";
            } else {
                $titulo = "Lead WhatsApp #$cli";
            }
            if ($enr['correlativo'] !== '') {
                $titulo .= ' · ' . $enr['correlativo'];
            }
        }

        $notas = trim((string)$notas_default);
        if ($notas === '' && ($enr['correlativo'] !== '' || $enr['asesor_erp'] !== '' || $enr['monto_raw'] !== '')) {
            $np = [];
            if ($enr['correlativo'] !== '') $np[] = "Documento: " . $enr['correlativo'];
            if ($enr['monto_raw'] !== '') $np[] = "Monto mensaje: " . $enr['monto_raw'];
            if ($enr['asesor_erp'] !== '') $np[] = "Vendedor ERP: " . $enr['asesor_erp'];
            if ($enr['fecha_doc'] !== '') $np[] = "Fecha: " . $enr['fecha_doc'];
            $notas = implode(' | ', $np);
        }

        $conv_sql = $conv ? $conv : 'NULL';
        $ag_sql = $ag ? $ag : 'NULL';
        $tit_esc = mysqli_real_escape_string($con, mb_substr($titulo, 0, 195));
        $not_sql = ($notas !== '') ? "'" . mysqli_real_escape_string($con, $notas) . "'" : "NULL";
        $monto_sql = number_format(max(0, $monto_final), 2, '.', '');
        $ins = @mysqli_query($con, "INSERT INTO `pipeline_oportunidades` (`id_sede`, `id_cliente`, `id_conversacion`, `id_agente`, `titulo`, `etapa`, `monto_estimado_usd`, `probabilidad`, `notas`)
                                    VALUES ($sede, $cli, $conv_sql, $ag_sql, '$tit_esc', 'NUEVO_LEAD', $monto_sql, 20, $not_sql)");
        return $ins ? intval(mysqli_insert_id($con)) : 0;
    }
}

if (!function_exists('starfi_assign_round_robin_advisor')) {
    /**
     * [COM-03] Enrutamiento Automático Round-Robin entre asesores EN LÍNEA de la sede.
     * Ventana de presencia en línea: actividad en los últimos 5 minutos y disponible_chat = 1.
     * Respeta limite_chats_simultaneos (con fallback a todos los conectados si todos están al tope).
     *
     * @return array|null Datos del asesor asignado ['id', 'nombre', 'telefono'] o null si no hay asesores en línea.
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

        // Buscar asesores/operadores activos y EN LÍNEA de la sede ordenados por Round-Robin estricto (el que lleva más tiempo sin recibir lead va primero)
        $sql_rr = "
            SELECT 
                u.id,
                u.usuario,
                up.nombre AS up_nombre,
                up.telefono AS up_telefono,
                IFNULL(u.limite_chats_simultaneos, 0) AS limite_chats,
                IFNULL(carga.chats_activos, 0) AS chats_activos,
                u.ultima_asignacion_rr
            FROM usuario u
            LEFT JOIN usuario_perfil up ON up.id_usuario = u.id
            LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
            LEFT JOIN (
                SELECT id_agente, COUNT(*) AS chats_activos
                FROM conversaciones
                WHERE estado IN ('ESPERA_ASIGNACION', 'ATENDIENDO')
                  AND id_agente IS NOT NULL AND id_agente > 0
                GROUP BY id_agente
            ) carga ON carga.id_agente = u.id
            WHERE u.id_sede = $sede
              AND (u.estado = 'ACTIVO' OR CAST(u.estado AS CHAR) = '1')
              AND IFNULL(u.disponible_chat, 1) = 1
              AND u.id != 1
              AND LOWER(u.usuario) != 'master'
              AND (
                  UPPER(TRIM(IFNULL(r.nombre, ''))) IN ('OPERADOR', 'ASESOR', 'AGENTE', 'VENDEDOR')
                  OR UPPER(TRIM(IFNULL(CAST(u.rol AS CHAR), ''))) IN ('3', 'OPERADOR', 'ASESOR', 'AGENTE', 'VENDEDOR')
              )
              AND u.ultima_actividad IS NOT NULL
              AND u.ultima_actividad >= (NOW() - INTERVAL $win_min MINUTE)
            ORDER BY 
              (CASE WHEN IFNULL(u.limite_chats_simultaneos, 0) > 0 AND IFNULL(carga.chats_activos, 0) >= u.limite_chats_simultaneos THEN 1 ELSE 0 END) ASC,
              (u.ultima_asignacion_rr IS NULL) DESC,
              u.ultima_asignacion_rr ASC,
              chats_activos ASC,
              u.id ASC
            LIMIT 1
        ";

        $res_rr = @mysqli_query($con, $sql_rr);
        $elegido = ($res_rr && mysqli_num_rows($res_rr) > 0) ? mysqli_fetch_assoc($res_rr) : null;

        if (!$elegido) {
            // Ningún asesor en línea en la sede: dejar en ESPERA_ASIGNACION para cola general de la sede
            @mysqli_query($con, "UPDATE `conversaciones` SET `estado` = 'ESPERA_ASIGNACION' WHERE `id` = $conv_id");
            $msg_cola = mysqli_real_escape_string($con, "⏳ Transferencia ($motivo_origen) en cola de espera: no hay asesores en línea en esta sede en este momento.");
            @mysqli_query($con, "INSERT INTO `mensajes_y_eventos` (`id_conversacion`, `origen`, `tipo`, `contenido`) VALUES ($conv_id, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', '$msg_cola')");
            $ev_id = intval(mysqli_insert_id($con));
            if ($ev_id > 0) {
                @mysqli_query($con, "UPDATE `conversaciones` SET `ultimo_msg_id` = GREATEST(IFNULL(`ultimo_msg_id`, 0), $ev_id) WHERE `id` = $conv_id");
            }
            if (intval($id_cliente) > 0) {
                starfi_ensure_pipeline_lead($con, $sede, intval($id_cliente), $conv_id, null);
            }
            return null;
        }

        $asesor_id = intval($elegido['id']);
        $up_nom = trim((string)($elegido['up_nombre'] ?? ''));
        $asesor_nombre = ($up_nom !== '') ? $up_nom : trim((string)($elegido['usuario'] ?? 'Asesor'));
        $asesor_tel = trim((string)($elegido['up_telefono'] ?? ''));

        // Actualizar puntero Round-Robin del asesor y asignar conversación
        @mysqli_query($con, "UPDATE `usuario` SET `ultima_asignacion_rr` = NOW() WHERE `id` = $asesor_id");
        @mysqli_query($con, "UPDATE `conversaciones` SET `id_agente` = $asesor_id, `estado` = 'ESPERA_ASIGNACION' WHERE `id` = $conv_id");

        // Registrar trazabilidad en el historial del chat
        $ev_txt = mysqli_real_escape_string($con, "🔀 Enrutamiento Round-Robin ($motivo_origen): conversación asignada automáticamente al asesor en línea $asesor_nombre.");
        @mysqli_query($con, "INSERT INTO `mensajes_y_eventos` (`id_conversacion`, `origen`, `tipo`, `contenido`) VALUES ($conv_id, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', '$ev_txt')");
        $ev_id = intval(mysqli_insert_id($con));
        if ($ev_id > 0) {
            @mysqli_query($con, "UPDATE `conversaciones` SET `ultimo_msg_id` = GREATEST(IFNULL(`ultimo_msg_id`, 0), $ev_id) WHERE `id` = $conv_id");
        }

        // Sincronizar tarjeta en el Embudo de Ventas (Nuevo Lead)
        if (intval($id_cliente) > 0) {
            starfi_ensure_pipeline_lead($con, $sede, intval($id_cliente), $conv_id, $asesor_id);
        }

        return [
            'id' => $asesor_id,
            'nombre' => $asesor_nombre,
            'telefono' => $asesor_tel
        ];
    }
}
