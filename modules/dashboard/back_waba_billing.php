<?php
// modules/dashboard/back_waba_billing.php
date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/../../core/auth.php';

/**
 * Auto-migración defensiva para garantizar las columnas de pagos y trazabilidad
 * en waba_ordenes_cobro y waba_ordenes_detalles.
 */
function ensureWabaBillingSchema($con) {
    if (!$con) return;

    // 1. Asegurar que la columna 'estado' en waba_ordenes_cobro soporte 'EN_REVISION' además de 'PENDIENTE', 'PAGADO', 'FUSIONADO', 'ANULADA'
    $res_est = $con->query("SHOW COLUMNS FROM waba_ordenes_cobro LIKE 'estado'");
    if ($res_est && $row_est = $res_est->fetch_assoc()) {
        $type_lower = strtolower($row_est['Type'] ?? '');
        if (strpos($type_lower, 'enum') !== false && strpos($type_lower, 'en_revision') === false) {
            $con->query("ALTER TABLE waba_ordenes_cobro MODIFY COLUMN estado VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE'");
        }
    }

    // 2. Columnas para el flujo de registro, reporte y auditoría de pagos [FAC-02]
    $cols_ordenes = [
        'metodo_pago'        => "ALTER TABLE waba_ordenes_cobro ADD COLUMN metodo_pago VARCHAR(60) DEFAULT NULL AFTER estado",
        'referencia_pago'    => "ALTER TABLE waba_ordenes_cobro ADD COLUMN referencia_pago VARCHAR(120) DEFAULT NULL AFTER metodo_pago",
        'fecha_pago'         => "ALTER TABLE waba_ordenes_cobro ADD COLUMN fecha_pago DATETIME DEFAULT NULL AFTER referencia_pago",
        'notas_pago'         => "ALTER TABLE waba_ordenes_cobro ADD COLUMN notas_pago TEXT DEFAULT NULL AFTER fecha_pago",
        'comprobante_path'   => "ALTER TABLE waba_ordenes_cobro ADD COLUMN comprobante_path VARCHAR(255) DEFAULT NULL AFTER notas_pago",
        'reportado_por'      => "ALTER TABLE waba_ordenes_cobro ADD COLUMN reportado_por INT DEFAULT NULL AFTER comprobante_path",
        'verificado_por'     => "ALTER TABLE waba_ordenes_cobro ADD COLUMN verificado_por INT DEFAULT NULL AFTER reportado_por",
        'fecha_verificacion' => "ALTER TABLE waba_ordenes_cobro ADD COLUMN fecha_verificacion DATETIME DEFAULT NULL AFTER verificado_por"
    ];

    foreach ($cols_ordenes as $col => $sql_alter) {
        $chk = $con->query("SHOW COLUMNS FROM waba_ordenes_cobro LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $con->query($sql_alter);
        }
    }

    // 3. Columnas de desglose en waba_ordenes_detalles
    $cols_detalles = [
        'categoria'          => "ALTER TABLE waba_ordenes_detalles ADD COLUMN categoria VARCHAR(50) DEFAULT 'MARKETING' AFTER nombre_plantilla",
        'mensajes_gratuitos' => "ALTER TABLE waba_ordenes_detalles ADD COLUMN mensajes_gratuitos INT DEFAULT 0 AFTER volumen",
        'mensajes_pagados'   => "ALTER TABLE waba_ordenes_detalles ADD COLUMN mensajes_pagados INT DEFAULT 0 AFTER mensajes_gratuitos"
    ];

    foreach ($cols_detalles as $col => $sql_alter) {
        $chk = $con->query("SHOW COLUMNS FROM waba_ordenes_detalles LIKE '$col'");
        if ($chk && $chk->num_rows === 0) {
            $con->query($sql_alter);
        }
    }
}

/**
 * [FAC-03] Motor Unificado de Conciliación Híbrida por Sede
 * Cruza Meta API por línea + campanas_difusion_destinatarios + mensajes_y_eventos
 * aplicando la tarifa configurada en waba_tarifas_sede.
 */
function conciliar_consumo_sede($con, $id_sede, $fecha_desde, $fecha_hasta) {
    $id_sede = intval($id_sede);
    if ($id_sede <= 0 || empty($fecha_desde) || empty($fecha_hasta)) {
        return ['status' => 'error', 'message' => 'Parámetros de conciliación inválidos'];
    }

    $start_ts = strtotime($fecha_desde . " 00:00:00");
    $end_ts   = strtotime($fecha_hasta . " 23:59:59");
    $desde_dt = $con->real_escape_string($fecha_desde) . " 00:00:00";
    $hasta_dt = $con->real_escape_string($fecha_hasta) . " 23:59:59";

    // 1. Obtener todas las líneas de WhatsApp asociadas a la sede
    $lineas = [];
    $waba_ids = [];
    $phone_numbers_clean = [];
    $phone_ids = [];

    $res_lin = $con->query("SELECT id, numero_telefono, meta_app_id, meta_telefono_id, meta_token, id_negocio, estado 
                            FROM lineas_whatsapp 
                            WHERE id_sede = $id_sede 
                            ORDER BY (estado = 'ACTIVO') DESC, id ASC");
    if ($res_lin) {
        while ($l = $res_lin->fetch_assoc()) {
            $lineas[] = $l;
            if (!empty($l['id_negocio'])) {
                $waba_ids[$l['id_negocio']] = $l['meta_token'] ?: META_GLOBAL_TOKEN;
            }
            if (!empty($l['numero_telefono'])) {
                $clean_num = preg_replace('/\D+/', '', $l['numero_telefono']);
                if ($clean_num !== '') {
                    $phone_numbers_clean[$clean_num] = true;
                }
            }
            if (!empty($l['meta_telefono_id'])) {
                $phone_ids[$l['meta_telefono_id']] = true;
            }
            if (!empty($l['meta_app_id'])) {
                $phone_ids[$l['meta_app_id']] = true;
            }
        }
    }

    if (empty($waba_ids)) {
        return ['status' => 'error', 'message' => 'Sede sin cuenta WABA (id_negocio) configurada en líneas de WhatsApp'];
    }

    $primary_waba_id = array_key_first($waba_ids);

    // 2. Obtener Tarifa de la sede desde waba_tarifas_sede
    $tipo_tarifa  = 'PORCENTAJE';
    $valor_tarifa = 10.00;
    $limite_fact  = 0.00;
    $res_tar = $con->query("SELECT tipo_tarifa, valor, limite_facturacion FROM waba_tarifas_sede WHERE id_sede = $id_sede LIMIT 1");
    if ($res_tar && $row_t = $res_tar->fetch_assoc()) {
        $tipo_tarifa  = $row_t['tipo_tarifa'] ?: 'PORCENTAJE';
        $valor_tarifa = floatval($row_t['valor']);
        $limite_fact  = floatval($row_t['limite_facturacion'] ?? 0);
    }

    // Costos unitarios base de referencia por si Meta API reporta 0 en envíos locales registrados
    $costo_unit_mkt_ref  = 0.0700;
    $costo_unit_util_ref = 0.0150;
    $chk_cuotas = $con->query("SHOW TABLES LIKE 'campanas_cuotas_sede'");
    if ($chk_cuotas && $chk_cuotas->num_rows > 0) {
        $res_cuota = $con->query("SELECT costo_marketing_usd, costo_utility_usd FROM campanas_cuotas_sede WHERE id_sede = $id_sede LIMIT 1");
        if ($res_cuota && $rc = $res_cuota->fetch_assoc()) {
            if (floatval($rc['costo_marketing_usd']) > 0) $costo_unit_mkt_ref = floatval($rc['costo_marketing_usd']);
            if (floatval($rc['costo_utility_usd']) > 0)   $costo_unit_util_ref = floatval($rc['costo_utility_usd']);
        }
    }

    // 3. Consultar Meta API por cada WABA vinculada a las líneas de la sede
    $costo_meta_api_por_cat = [
        'MARKETING'      => 0.0,
        'UTILITY'        => 0.0,
        'AUTHENTICATION' => 0.0,
        'SERVICE'        => 0.0
    ];
    $mensajes_meta_api = 0;

    foreach ($waba_ids as $waba_id => $line_token) {
        $token_to_use = defined('META_GLOBAL_TOKEN') && !empty(META_GLOBAL_TOKEN) ? META_GLOBAL_TOKEN : $line_token;

        // Verificar cuántas sedes distintas comparten este mismo WABA ID
        $res_shared = $con->query("SELECT COUNT(DISTINCT id_sede) AS total_sedes FROM lineas_whatsapp WHERE id_negocio = '" . $con->real_escape_string($waba_id) . "' AND estado = 'ACTIVO'");
        $total_sedes_waba = ($res_shared && $rs = $res_shared->fetch_assoc()) ? intval($rs['total_sedes']) : 1;

        // Intentar primero con dimensión PHONE para aislar exactamente las líneas de la sede
        $fields_phone = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY,PHONE),analytics.start($start_ts).end($end_ts).granularity(DAY)";
        $url_phone = "https://graph.facebook.com/v23.0/$waba_id?fields=" . urlencode($fields_phone);

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://graph.facebook.com/v23.0/$waba_id?fields=$fields_phone");
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token_to_use, "Content-Type: application/json"]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        $raw_exec = curl_exec($curl);
        $resp = json_decode($raw_exec, true);
        curl_close($curl);

        // Fallback sin dimensión PHONE si la API rechaza la dimensión combinada
        if (isset($resp['error'])) {
            $fields_basic = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY),analytics.start($start_ts).end($end_ts).granularity(DAY)";
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, "https://graph.facebook.com/v23.0/$waba_id?fields=$fields_basic");
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token_to_use, "Content-Type: application/json"]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_TIMEOUT, 20);
            $raw_exec = curl_exec($curl);
            $resp = json_decode($raw_exec, true);
            curl_close($curl);
        }

        if (!isset($resp['error'])) {
            $points = $resp['pricing_analytics']['data'][0]['data_points'] ?? [];
            foreach ($points as $dp) {
                // Si el punto trae teléfono, validar que pertenezca a una línea de esta sede
                if (!empty($dp['phone'])) {
                    $dp_phone = preg_replace('/\D+/', '', strval($dp['phone']));
                    if (!empty($phone_numbers_clean) && !isset($phone_numbers_clean[$dp_phone]) && !isset($phone_ids[$dp['phone']])) {
                        // Verificar coincidencia por sufijo (últimos 10 dígitos)
                        $matched_phone = false;
                        foreach (array_keys($phone_numbers_clean) as $sede_num) {
                            if (strlen($sede_num) >= 8 && strlen($dp_phone) >= 8 && substr($sede_num, -8) === substr($dp_phone, -8)) {
                                $matched_phone = true;
                                break;
                            }
                        }
                        if (!$matched_phone) {
                            continue;
                        }
                    }
                } elseif ($total_sedes_waba > 1) {
                    // Si el WABA es compartido por varias sedes y Meta no desglosó por teléfono,
                    // no adjudicamos a ciegas el 100% del WABA a una sola sede; priorizaremos la auditoría híbrida local
                    continue;
                }

                $cat = strtoupper($dp['pricing_category'] ?? ($dp['category'] ?? 'MARKETING'));
                $c   = floatval($dp['cost'] ?? 0);
                if (!isset($costo_meta_api_por_cat[$cat])) {
                    $costo_meta_api_por_cat[$cat] = 0.0;
                }
                $costo_meta_api_por_cat[$cat] += $c;
            }

            // Mensajes totales en analytics (solo si el WABA es exclusivo de esta sede)
            if ($total_sedes_waba <= 1) {
                $analytics_pts = $resp['analytics']['data_points'] ?? ($resp['analytics']['data'][0]['data_points'] ?? []);
                foreach ($analytics_pts as $dp) {
                    if (isset($dp['sent'])) {
                        $mensajes_meta_api += intval($dp['sent']);
                    }
                }
            }
        }
    }

    // 4. Estructura base de categorías para conciliación híbrida
    $cat_details = [
        'MARKETING'      => ['nombre' => 'Mensajes de Marketing (Campañas y Difusión)', 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
        'UTILITY'        => ['nombre' => 'Mensajes de Utilidad (Notificaciones)',       'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
        'AUTHENTICATION' => ['nombre' => 'Mensajes de Autenticación',                   'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
        'SERVICE'        => ['nombre' => 'Mensajes de Servicio y Atención (Bandeja/Bot)','volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0]
    ];

    $costo_local_por_cat = [
        'MARKETING'      => 0.0,
        'UTILITY'        => 0.0,
        'AUTHENTICATION' => 0.0,
        'SERVICE'        => 0.0
    ];

    // 5. Cruce con campanas_difusion_destinatarios + campanas_difusion
    $chk_camp = $con->query("SHOW TABLES LIKE 'campanas_difusion_destinatarios'");
    if ($chk_camp && $chk_camp->num_rows > 0) {
        $q_camp = "SELECT 
                    UPPER(COALESCE(NULLIF(c.template_category, ''), 'MARKETING')) AS cat,
                    COUNT(d.id) AS vol,
                    SUM(CASE 
                        WHEN d.costo_usd > 0 THEN d.costo_usd 
                        WHEN c.costo_unitario_usd > 0 THEN c.costo_unitario_usd 
                        ELSE $costo_unit_mkt_ref 
                    END) AS costo_camp
                   FROM campanas_difusion_destinatarios d
                   LEFT JOIN campanas_difusion c ON d.id_campana = c.id
                   WHERE (d.id_sede = $id_sede OR (d.id_sede = 0 AND c.id_sede = $id_sede))
                     AND d.estado_envio IN ('ENVIADO', 'ENTREGADO', 'LEIDO', 'RESPONDIDO')
                     AND COALESCE(d.fecha_envio, d.fecha_entrega, c.fecha_creacion) >= '$desde_dt'
                     AND COALESCE(d.fecha_envio, d.fecha_entrega, c.fecha_creacion) <= '$hasta_dt'
                   GROUP BY cat";
        $res_camp = $con->query($q_camp);
        if ($res_camp) {
            while ($rc = $res_camp->fetch_assoc()) {
                $cat = $rc['cat'] ?: 'MARKETING';
                if (!isset($cat_details[$cat])) {
                    $cat_details[$cat] = ['nombre' => 'Mensajes ' . ucfirst(strtolower($cat)), 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0];
                    $costo_local_por_cat[$cat] = 0.0;
                }
                $vol_c = intval($rc['vol']);
                $costo_c = floatval($rc['costo_camp']);
                $cat_details[$cat]['volumen'] += $vol_c;
                $cat_details[$cat]['pagados'] += $vol_c;
                $costo_local_por_cat[$cat]    += $costo_c;
            }
        }
    }

    // 6. Cruce con mensajes_y_eventos (excluyendo wamids que ya fueron contabilizados en campanas_difusion_destinatarios)
    $exclude_camp_wamid_sql = "";
    if ($chk_camp && $chk_camp->num_rows > 0) {
        $exclude_camp_wamid_sql = " AND (m.id_mensaje_meta IS NULL OR m.id_mensaje_meta = '' OR NOT EXISTS (
            SELECT 1 FROM campanas_difusion_destinatarios d2 
            WHERE d2.id_mensaje_meta = m.id_mensaje_meta
        ))";
    }

    $q_msg = "SELECT 
                UPPER(COALESCE(NULLIF(m.categoria_meta, ''), 
                    CASE WHEN m.origen = 'API_TRANSACCIONAL' THEN 'UTILITY' ELSE 'SERVICE' END
                )) AS cat,
                COUNT(m.id) AS vol,
                SUM(CASE WHEN m.es_pagado = 1 THEN 1 ELSE 0 END) AS pag,
                SUM(CASE WHEN m.es_pagado = 0 OR m.es_pagado IS NULL THEN 1 ELSE 0 END) AS grat,
                SUM(COALESCE(m.costo_meta_estimado, 0)) AS costo_est
              FROM mensajes_y_eventos m
              JOIN conversaciones conv ON m.id_conversacion = conv.id
              JOIN lineas_whatsapp l ON conv.id_linea = l.id
              WHERE l.id_sede = $id_sede
                AND m.origen != 'CLIENTE'
                AND m.timestamp >= '$desde_dt'
                AND m.timestamp <= '$hasta_dt'
                $exclude_camp_wamid_sql
              GROUP BY cat";
    $res_msg = $con->query($q_msg);
    if ($res_msg) {
        while ($rm = $res_msg->fetch_assoc()) {
            $cat = $rm['cat'] ?: 'SERVICE';
            if (!isset($cat_details[$cat])) {
                $cat_details[$cat] = ['nombre' => 'Mensajes ' . ucfirst(strtolower($cat)), 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0];
                $costo_local_por_cat[$cat] = 0.0;
            }
            $vol_m  = intval($rm['vol']);
            $pag_m  = intval($rm['pag']);
            $grat_m = intval($rm['grat']);
            $c_est  = floatval($rm['costo_est']);

            // Si en BD local hay mensajes marcados como pagados pero sin costo_meta_estimado, estimar con tarifa base según categoría
            if ($c_est <= 0 && $pag_m > 0) {
                $unit_fallback = ($cat === 'MARKETING') ? $costo_unit_mkt_ref : (($cat === 'UTILITY' || $cat === 'AUTHENTICATION') ? $costo_unit_util_ref : 0.0);
                $c_est = $pag_m * $unit_fallback;
            }

            $cat_details[$cat]['volumen']   += $vol_m;
            $cat_details[$cat]['pagados']   += $pag_m;
            $cat_details[$cat]['gratuitos'] += $grat_m;
            $costo_local_por_cat[$cat]      += $c_est;
        }
    }

    // 7. Conciliar Costo Base Meta y Volumen Total entre Meta API y Auditoría Híbrida Local
    $costo_meta_total = 0.0;
    $volumen_local_total = 0;

    foreach ($cat_details as $cat => &$cd) {
        $c_api = floatval($costo_meta_api_por_cat[$cat] ?? 0.0);
        $c_loc = floatval($costo_local_por_cat[$cat] ?? 0.0);

        // Usamos el mayor entre el costo reportado por Meta API para la línea y el auditado en campañas/eventos locales
        // (garantiza que campañas locales o costos de Meta API nunca se pierdan)
        $costo_cat = ($c_api > 0 && $c_api >= $c_loc) ? $c_api : $c_loc;
        $cd['costo'] = round($costo_cat, 4);
        $costo_meta_total += $cd['costo'];
        $volumen_local_total += $cd['volumen'];
    }
    unset($cd);

    $mensajes_totales = max($volumen_local_total, $mensajes_meta_api);

    // Si no hubo registros locales pero Meta API sí reportó mensajes/costos
    if ($volumen_local_total === 0 && ($mensajes_totales > 0 || $costo_meta_total > 0)) {
        $cat_details['MARKETING']['volumen'] = $mensajes_totales;
        $cat_details['MARKETING']['pagados'] = $mensajes_totales;
    }

    // 8. Aplicar tarifa de waba_tarifas_sede
    $costo_final = 0.0;
    $margen      = 0.0;

    if ($tipo_tarifa === 'PORCENTAJE') {
        $costo_final = $costo_meta_total * (1 + ($valor_tarifa / 100));
        $margen      = $costo_final - $costo_meta_total;
    } elseif ($tipo_tarifa === 'FIJA') {
        $margen      = $mensajes_totales * $valor_tarifa;
        $costo_final = $costo_meta_total + $margen;
    } elseif ($tipo_tarifa === 'PORCENTAJE_VOLUMEN') {
        $p           = $mensajes_totales > 1000 ? ($valor_tarifa / 2) : $valor_tarifa;
        $costo_final = $costo_meta_total * (1 + ($p / 100));
        $margen      = $costo_final - $costo_meta_total;
    } else {
        $costo_final = $costo_meta_total * 1.10;
        $margen      = $costo_final - $costo_meta_total;
    }

    return [
        'status'           => 'success',
        'waba_id'          => $primary_waba_id,
        'mensajes_totales' => $mensajes_totales,
        'costo_meta'       => round($costo_meta_total, 4),
        'margen'           => round($margen, 4),
        'costo_final'      => round($costo_final, 2),
        'tipo_tarifa'      => $tipo_tarifa,
        'valor_tarifa'     => $valor_tarifa,
        'limite_fact'      => $limite_fact,
        'cat_details'      => $cat_details
    ];
}

/**
 * [FAC-03] Emisión centralizada de Orden de Cobro por Sede
 * Usada por back_waba_billing.php y cron_waba_billing.php.
 */
function emitir_orden_sede($con, $id_sede, $fecha_desde, $fecha_hasta, $orden_pendiente_id = null) {
    ensureWabaBillingSchema($con);

    if (!$id_sede || !$fecha_desde || !$fecha_hasta) {
        return ['status' => 'error', 'message' => 'Faltan parámetros para emitir la orden'];
    }

    $conciliacion = conciliar_consumo_sede($con, $id_sede, $fecha_desde, $fecha_hasta);
    if ($conciliacion['status'] !== 'success') {
        return $conciliacion;
    }

    $waba_id          = $conciliacion['waba_id'];
    $mensajes_totales = intval($conciliacion['mensajes_totales']);
    $costo_meta       = floatval($conciliacion['costo_meta']);
    $margen           = floatval($conciliacion['margen']);
    $costo_final      = floatval($conciliacion['costo_final']);
    $cat_details      = $conciliacion['cat_details'];

    $con->begin_transaction();
    try {
        if (!empty($orden_pendiente_id)) {
            $con->query("UPDATE waba_ordenes_cobro SET estado = 'FUSIONADO' WHERE id = " . intval($orden_pendiente_id));
        }

        if (!empty($orden_pendiente_id)) {
            $id_padre = intval($orden_pendiente_id);
            $stmt = $con->prepare("INSERT INTO waba_ordenes_cobro (id_sede, fecha_desde, fecha_hasta, mensajes_totales, costo_meta, margen_ganancia, monto_total, orden_padre_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issidddi", $id_sede, $fecha_desde, $fecha_hasta, $mensajes_totales, $costo_meta, $margen, $costo_final, $id_padre);
        } else {
            $stmt = $con->prepare("INSERT INTO waba_ordenes_cobro (id_sede, fecha_desde, fecha_hasta, mensajes_totales, costo_meta, margen_ganancia, monto_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issiddd", $id_sede, $fecha_desde, $fecha_hasta, $mensajes_totales, $costo_meta, $margen, $costo_final);
        }

        if (!$stmt->execute()) {
            throw new Exception("Error al insertar orden: " . $stmt->error);
        }
        $new_order_id = $stmt->insert_id;
        $stmt->close();

        // Insertar desglose por categoría en waba_ordenes_detalles
        foreach ($cat_details as $cname => $cd) {
            if ($cd['volumen'] > 0 || $cd['costo'] > 0) {
                $n_esc  = mysqli_real_escape_string($con, $cd['nombre']);
                $c_esc  = mysqli_real_escape_string($con, $cname);
                $vol    = intval($cd['volumen']);
                $c_base = floatval($cd['costo']);
                $grat   = intval($cd['gratuitos']);
                $pag    = intval($cd['pagados']);
                $con->query("INSERT INTO waba_ordenes_detalles (id_orden, nombre_plantilla, categoria, costo_base, volumen, mensajes_gratuitos, mensajes_pagados) VALUES ($new_order_id, '$n_esc', '$c_esc', $c_base, $vol, $grat, $pag)");
            }
        }

        if ($margen > 0) {
            $con->query("INSERT INTO waba_ordenes_detalles (id_orden, nombre_plantilla, categoria, costo_base, volumen, mensajes_gratuitos, mensajes_pagados) VALUES ($new_order_id, 'Margen Administrativo (CRM)', 'ADMIN', $margen, 1, 0, 0)");
        }

        $con->commit();

        // ==========================================
        // MOTOR DE NOTIFICACIÓN (WhatsApp + Email)
        // ==========================================
        $notificado = 0;
        $res_s = $con->query("SELECT gerente_nombre, gerente_telefono, gerente_email, email, pref_not_cobro, nombre_sede FROM sedes WHERE id = " . intval($id_sede));
        if ($res_s && $s = $res_s->fetch_assoc()) {
            $monto_fmt = number_format($costo_final, 2);
            $msg = "Hola {$s['gerente_nombre']}, se ha generado la orden de cobro *#$new_order_id* para *{$s['nombre_sede']}* por el consumo de WhatsApp API.\n\n*Periodo:* $fecha_desde al $fecha_hasta\n*Monto Total:* $monto_fmt USD\n\nPor favor coordine el pago correspondiente.";

            // WHATSAPP
            if (in_array($s['pref_not_cobro'], ['WHATSAPP', 'AMBOS']) && !empty($s['gerente_telefono'])) {
                $res_l = $con->query("SELECT meta_app_id, meta_token FROM lineas_whatsapp WHERE id_sede = " . intval($id_sede) . " AND estado = 'ACTIVO' LIMIT 1");
                if ($res_l && $l = $res_l->fetch_assoc()) {
                    $waba_token = $l['meta_token'];

                    $check_url = "https://graph.facebook.com/v23.0/$waba_id/message_templates?name=notificacion_orden_cobro";
                    $ch_ck = curl_init($check_url);
                    curl_setopt($ch_ck, CURLOPT_HTTPHEADER, ["Authorization: Bearer $waba_token"]);
                    curl_setopt($ch_ck, CURLOPT_RETURNTRANSFER, true);
                    $res_ck = json_decode(curl_exec($ch_ck), true);
                    curl_close($ch_ck);

                    $template_ready = false;
                    if (isset($res_ck['data']) && count($res_ck['data']) > 0) {
                        $template_ready = true;
                    } else {
                        $url_create = "https://graph.facebook.com/v23.0/$waba_id/message_templates";
                        $payload_create = [
                            "name" => "notificacion_orden_cobro",
                            "category" => "UTILITY",
                            "allow_category_change" => true,
                            "language" => "es",
                            "components" => [
                                [
                                    "type" => "BODY",
                                    "text" => "Hola {{1}}, se ha generado la orden de cobro *#{{2}}* para *{{3}}* por el consumo de WhatsApp API.\n\n*Periodo:* {{4}} al {{5}}\n*Monto Total:* {{6}} USD\n\nPor favor coordine el pago correspondiente.",
                                    "example" => [
                                        "body_text" => [
                                            [$s['gerente_nombre'] ?: 'Administración', strval($new_order_id), $s['nombre_sede'], $fecha_desde, $fecha_hasta, $monto_fmt]
                                        ]
                                    ]
                                ]
                            ]
                        ];
                        $ch_cr = curl_init($url_create);
                        curl_setopt($ch_cr, CURLOPT_HTTPHEADER, ["Authorization: Bearer $waba_token", "Content-Type: application/json"]);
                        curl_setopt($ch_cr, CURLOPT_POST, true);
                        curl_setopt($ch_cr, CURLOPT_POSTFIELDS, json_encode($payload_create));
                        curl_setopt($ch_cr, CURLOPT_RETURNTRANSFER, true);
                        curl_exec($ch_cr);
                        curl_close($ch_cr);
                        $template_ready = true;
                    }

                    $url_w = 'https://graph.facebook.com/v23.0/' . $l['meta_app_id'] . '/messages';
                    if ($template_ready) {
                        $payload_w = [
                            'messaging_product' => 'whatsapp',
                            'to' => $s['gerente_telefono'],
                            'type' => 'template',
                            'template' => [
                                'name' => 'notificacion_orden_cobro',
                                'language' => ['code' => 'es'],
                                'components' => [
                                    [
                                        'type' => 'body',
                                        'parameters' => [
                                            ['type' => 'text', 'text' => $s['gerente_nombre'] ?: 'Administración'],
                                            ['type' => 'text', 'text' => strval($new_order_id)],
                                            ['type' => 'text', 'text' => $s['nombre_sede']],
                                            ['type' => 'text', 'text' => $fecha_desde],
                                            ['type' => 'text', 'text' => $fecha_hasta],
                                            ['type' => 'text', 'text' => $monto_fmt]
                                        ]
                                    ]
                                ]
                            ]
                        ];
                    } else {
                        $payload_w = [
                            'messaging_product' => 'whatsapp',
                            'to' => $s['gerente_telefono'],
                            'type' => 'text',
                            'text' => ['body' => $msg]
                        ];
                    }

                    $ch_w = curl_init($url_w);
                    curl_setopt($ch_w, CURLOPT_HTTPHEADER, ["Authorization: Bearer $waba_token", "Content-Type: application/json"]);
                    curl_setopt($ch_w, CURLOPT_POST, true);
                    curl_setopt($ch_w, CURLOPT_POSTFIELDS, json_encode($payload_w));
                    curl_setopt($ch_w, CURLOPT_RETURNTRANSFER, true);
                    $resp_w = curl_exec($ch_w);
                    curl_close($ch_w);

                    $resp_dec = json_decode($resp_w, true);
                    if (isset($resp_dec['messages'])) {
                        $notificado = 1;
                    }
                }
            }

            // EMAIL
            $email_destino = !empty($s['gerente_email']) ? $s['gerente_email'] : ($s['email'] ?? '');
            if (!empty($email_destino)) {
                require_once __DIR__ . '/../../core/services/MailerService.php';
                $mailer = new MailerService($con);

                $html_msg = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                <div style='background-color: #2b6cb0; color: white; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Notificación de Cobro</h2>
                    <p style='margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;'>Infraestructura API WhatsApp</p>
                </div>
                <div style='padding: 30px; background-color: #ffffff;'>
                    <p style='font-size: 16px; color: #333;'>Hola <strong>" . ($s['gerente_nombre'] ?: 'Administración') . "</strong>,</p>
                    <p style='color: #4a5568; line-height: 1.6;'>Se ha generado una nueva orden de cobro para la sede <strong>{$s['nombre_sede']}</strong> correspondiente a los servicios de mensajería consolidada y costos operativos.</p>
                    
                    <div style='background-color: #f7fafc; border-left: 4px solid #3182ce; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0 0 10px 0;'><strong>Orden #:</strong> NC-" . date('Y') . "-" . str_pad($new_order_id, 4, '0', STR_PAD_LEFT) . "</p>
                        <p style='margin: 0 0 10px 0;'><strong>Periodo:</strong> $fecha_desde al $fecha_hasta</p>
                        <p style='margin: 0; font-size: 18px;'><strong>Monto a Pagar: <span style='color: #c53030;'>$" . $monto_fmt . " USD</span></strong></p>
                    </div>
                    
                    <p style='color: #4a5568; font-size: 14px; line-height: 1.5;'>Por favor coordine el pago correspondiente y registre su comprobante en el panel de órdenes del CRM.</p>
                </div>
                <div style='background-color: #f1f5f9; padding: 15px; text-align: center; color: #718096; font-size: 12px;'>
                    Este es un mensaje automático de STARFI CRM. No es necesario responder a este correo si ya realizó el pago.
                </div>
            </div>";
                $asunto = "Estado de Cuenta WhatsApp API - {$s['nombre_sede']}";

                $mail_res = $mailer->sendDirectEmail($email_destino, $s['gerente_nombre'] ?: $s['nombre_sede'], $asunto, $html_msg);
                if (isset($mail_res['status']) && $mail_res['status'] === 'success') {
                    $notificado = 1;
                }
            }

            if ($notificado == 1) {
                $con->query("UPDATE waba_ordenes_cobro SET notificacion_enviada = 1 WHERE id = $new_order_id");
            }
        }

        return ['status' => 'success', 'id_orden' => $new_order_id, 'monto_total' => round($costo_final, 2)];
    } catch (Exception $e) {
        $con->rollback();
        return ['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()];
    }
}

// Si el archivo es requerido en modo librería (ej. desde cron_waba_billing.php), detenemos aquí
if (defined('WABA_BILLING_LIB_ONLY') && WABA_BILLING_LIB_ONLY === true) {
    return;
}

// =========================================================================
// CONTROLADOR HTTP / AJAX
// =========================================================================
requireAuth();
header('Content-Type: application/json; charset=utf-8');

$agente = getAgenteInfo();
$es_master = (($agente['rol'] ?? '') === 'MASTER');
$id_sede_agente = intval($agente['id_sede'] ?? 0);
$id_usuario_actual = intval($agente['id'] ?? 0);

$con = getDbConnection();
if (!$con) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
    exit;
}

ensureWabaBillingSchema($con);

$action = $_POST['action'] ?? '';

// Acciones de uso exclusivo para MASTER
$master_only_actions = [
    'install_cron',
    'get_sede_config',
    'save_sede_config',
    'check_last_order',
    'generate_order',
    'generate_massive_orders',
    'resend_notification',
    'delete_order',
    'verify_payment'
];

if (in_array($action, $master_only_actions, true) && !$es_master) {
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado. Esta acción es exclusiva del rol MASTER.']);
    exit;
}

if ($action === 'install_cron') {
    $php_bin = PHP_BINARY;
    $script_path = realpath(__DIR__ . '/../../cron_waba_billing.php');
    $task_name = "Starfi_Waba_Billing_Cron";

    if (!$script_path) {
        echo json_encode(['status' => 'error', 'message' => 'No se encontró el script cron_waba_billing.php.']);
        exit;
    }

    $cmd = "schtasks /create /tn \"$task_name\" /tr \"$php_bin -f $script_path\" /sc daily /st 01:00 /f 2>&1";
    $output = shell_exec($cmd);

    if (strpos(strtolower($output), 'success') !== false || strpos(strtolower($output), 'correcto') !== false || strpos(strtolower($output), 'éxito') !== false) {
        echo json_encode(['status' => 'success', 'message' => 'Instalado correctamente.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => "Respuesta de Windows: $output"]);
    }
    exit;
}

if ($action === 'get_sede_config') {
    $id_sede = intval($_POST['id_sede'] ?? 0);

    $stmt = $con->prepare("SELECT gerente_nombre, gerente_telefono, gerente_email, pref_not_cobro FROM sedes WHERE id = ?");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $sede_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $con->prepare("SELECT tipo_tarifa, valor, notas_negociacion, limite_facturacion FROM waba_tarifas_sede WHERE id_sede = ?");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $tarifa_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$tarifa_info) {
        $tarifa_info = [
            'tipo_tarifa' => 'PORCENTAJE',
            'valor' => 10.00,
            'notas_negociacion' => '',
            'limite_facturacion' => 0.00
        ];
    }

    echo json_encode(['status' => 'success', 'sede' => $sede_info, 'tarifa' => $tarifa_info]);
    exit;
}

if ($action === 'save_sede_config') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $gerente_nombre = $_POST['gerente_nombre'] ?? '';
    $gerente_telefono = $_POST['gerente_telefono'] ?? '';
    $gerente_email = $_POST['gerente_email'] ?? '';
    $pref_not_cobro = $_POST['pref_not_cobro'] ?? 'WHATSAPP';

    $tipo_tarifa = $_POST['tipo_tarifa'] ?? 'PORCENTAJE';
    $valor = floatval($_POST['valor'] ?? 10.00);
    $limite_facturacion = floatval($_POST['limite_facturacion'] ?? 0.00);
    $notas = $_POST['notas'] ?? '';

    $stmt = $con->prepare("UPDATE sedes SET gerente_nombre = ?, gerente_telefono = ?, gerente_email = ?, pref_not_cobro = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $gerente_nombre, $gerente_telefono, $gerente_email, $pref_not_cobro, $id_sede);
    $stmt->execute();
    $stmt->close();

    $stmt = $con->prepare("SELECT id FROM waba_tarifas_sede WHERE id_sede = ?");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows > 0) {
        $stmt = $con->prepare("UPDATE waba_tarifas_sede SET tipo_tarifa = ?, valor = ?, notas_negociacion = ?, limite_facturacion = ? WHERE id_sede = ?");
        $stmt->bind_param("sdsdi", $tipo_tarifa, $valor, $notas, $limite_facturacion, $id_sede);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $con->prepare("INSERT INTO waba_tarifas_sede (id_sede, tipo_tarifa, valor, notas_negociacion, limite_facturacion) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("isdsd", $id_sede, $tipo_tarifa, $valor, $notas, $limite_facturacion);
        $stmt->execute();
        $stmt->close();
    }

    echo json_encode(['status' => 'success', 'message' => 'Configuración guardada exitosamente.']);
    exit;
}

if ($action === 'check_last_order') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $fecha_hasta = $_POST['fecha_hasta'] ?? date('Y-m-d');
    $fecha_desde = date('Y-m-01');
    $tiene_pendiente = false;
    $pendiente_id = null;

    $stmt = $con->prepare("SELECT id, estado, fecha_desde, fecha_hasta FROM waba_ordenes_cobro WHERE id_sede = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if ($row['estado'] === 'PENDIENTE') {
            $tiene_pendiente = true;
            $pendiente_id = $row['id'];
            $fecha_desde = $row['fecha_desde'];
        } else {
            $fecha_desde = date('Y-m-d', strtotime($row['fecha_hasta'] . ' + 1 day'));
        }
    }
    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'fecha_desde' => $fecha_desde,
        'fecha_hasta' => $fecha_hasta,
        'tiene_pendiente' => $tiene_pendiente,
        'pendiente_id' => $pendiente_id
    ]);
    exit;
}

if ($action === 'generate_order') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $fecha_desde = $_POST['fecha_desde'] ?? '';
    $fecha_hasta = $_POST['fecha_hasta'] ?? '';
    $orden_pendiente_id = $_POST['orden_pendiente_id'] ?? null;

    $res = emitir_orden_sede($con, $id_sede, $fecha_desde, $fecha_hasta, $orden_pendiente_id);
    echo json_encode($res);
    exit;
}

if ($action === 'generate_massive_orders') {
    $sedes = $_POST['sedes'] ?? [];
    $fecha_desde = $_POST['fecha_desde'] ?? '';
    $fecha_hasta = $_POST['fecha_hasta'] ?? '';

    if (empty($sedes) || !$fecha_desde || !$fecha_hasta) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros para la emisión masiva.']);
        exit;
    }

    $exitos = 0;
    $errores = 0;
    $mensajes_error = [];

    foreach ($sedes as $id_sede) {
        $id = intval($id_sede);
        $res = emitir_orden_sede($con, $id, $fecha_desde, $fecha_hasta, null);

        if ($res['status'] === 'success') {
            $exitos++;
        } else {
            $errores++;
            $mensajes_error[] = "Sede $id: " . $res['message'];
        }
    }

    if ($exitos > 0 && $errores === 0) {
        echo json_encode(['status' => 'success', 'message' => "Se generaron $exitos órdenes exitosamente."]);
    } elseif ($exitos > 0 && $errores > 0) {
        echo json_encode(['status' => 'success', 'message' => "Se generaron $exitos órdenes, pero hubo $errores errores.<br>" . implode("<br>", $mensajes_error)]);
    } else {
        echo json_encode(['status' => 'error', 'message' => "Error al generar órdenes:<br>" . implode("<br>", $mensajes_error)]);
    }
    exit;
}

/**
 * [FAC-01] & [FAC-04] Listado paginado de órdenes + KPIs financieros con Blindaje de Privacidad
 */
if ($action === 'get_orders') {
    $page = max(1, intval($_POST['page'] ?? 1));
    $limit = 10;
    $offset = ($page - 1) * $limit;

    $id_sede = intval($_POST['id_sede'] ?? 0);
    // Si el usuario NO es MASTER, forzar estrictamente su propia sede
    if (!$es_master) {
        if ($id_sede_agente <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Usuario sin sede asignada']);
            exit;
        }
        $id_sede = $id_sede_agente;
    }

    $estado = trim($_POST['estado'] ?? '');
    $fecha_desde = trim($_POST['fecha_desde'] ?? '');
    $fecha_hasta = trim($_POST['fecha_hasta'] ?? '');

    $where = ["1=1"];
    $types = "";
    $params = [];

    if ($id_sede > 0) {
        $where[] = "o.id_sede = ?";
        $types .= "i";
        $params[] = $id_sede;
    }
    if (!empty($estado)) {
        $where[] = "o.estado = ?";
        $types .= "s";
        $params[] = $estado;
    }
    if (!empty($fecha_desde)) {
        $where[] = "o.fecha_desde >= ?";
        $types .= "s";
        $params[] = $fecha_desde;
    }
    if (!empty($fecha_hasta)) {
        $where[] = "o.fecha_hasta <= ?";
        $types .= "s";
        $params[] = $fecha_hasta;
    }

    $whereClause = implode(" AND ", $where);

    // 1. Calcular Tarjetas KPI Financieras [FAC-01] sobre los filtros aplicados
    // Excluimos FUSIONADO del Total Facturado y Margen Neto para no duplicar órdenes refundidas
    $qKpi = "SELECT 
                COALESCE(SUM(CASE WHEN o.estado != 'FUSIONADO' THEN o.monto_total ELSE 0 END), 0) AS total_facturado,
                COALESCE(SUM(CASE WHEN o.estado = 'PAGADO' THEN o.monto_total ELSE 0 END), 0) AS total_cobrado,
                COALESCE(SUM(CASE WHEN o.estado IN ('PENDIENTE', 'EN_REVISION') THEN o.monto_total ELSE 0 END), 0) AS por_cobrar,
                COALESCE(SUM(CASE WHEN o.estado = 'EN_REVISION' THEN o.monto_total ELSE 0 END), 0) AS en_revision,
                COALESCE(SUM(CASE WHEN o.estado != 'FUSIONADO' THEN o.margen_ganancia ELSE 0 END), 0) AS margen_neto,
                COALESCE(SUM(CASE WHEN o.estado = 'PAGADO' THEN o.margen_ganancia ELSE 0 END), 0) AS margen_cobrado
             FROM waba_ordenes_cobro o
             WHERE $whereClause";
    $stmtKpi = $con->prepare($qKpi);
    if (!empty($params)) {
        $stmtKpi->bind_param($types, ...$params);
    }
    $stmtKpi->execute();
    $kpiRow = $stmtKpi->get_result()->fetch_assoc();
    $stmtKpi->close();

    $kpis = [
        'total_facturado' => round(floatval($kpiRow['total_facturado'] ?? 0), 2),
        'total_cobrado'   => round(floatval($kpiRow['total_cobrado'] ?? 0), 2),
        'por_cobrar'      => round(floatval($kpiRow['por_cobrar'] ?? 0), 2),
        'en_revision'     => round(floatval($kpiRow['en_revision'] ?? 0), 2)
    ];
    // Regla de Oro de Privacidad: solo MASTER recibe el Margen Neto
    if ($es_master) {
        $kpis['margen_neto']    = round(floatval($kpiRow['margen_neto'] ?? 0), 2);
        $kpis['margen_cobrado'] = round(floatval($kpiRow['margen_cobrado'] ?? 0), 2);
    }

    // 2. Total de registros para paginación
    $qTotal = "SELECT COUNT(*) as total FROM waba_ordenes_cobro o WHERE $whereClause";
    $stmtTotal = $con->prepare($qTotal);
    if (!empty($params)) {
        $stmtTotal->bind_param($types, ...$params);
    }
    $stmtTotal->execute();
    $total_records = intval($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtTotal->close();

    $total_pages = ceil($total_records / $limit);

    // 3. Obtener registros de la página actual
    $qData = "SELECT o.*, s.nombre_sede 
              FROM waba_ordenes_cobro o 
              JOIN sedes s ON o.id_sede = s.id 
              WHERE $whereClause 
              ORDER BY o.id DESC 
              LIMIT ? OFFSET ?";

    $stmtData = $con->prepare($qData);
    $typesData = $types . "ii";
    $paramsData = $params;
    $paramsData[] = $limit;
    $paramsData[] = $offset;
    $stmtData->bind_param($typesData, ...$paramsData);
    $stmtData->execute();

    $res = $stmtData->get_result();
    $data = [];
    while ($row = $res->fetch_assoc()) {
        // [FAC-04] Regla de Oro de Privacidad: ocultar Costo Base Meta y Margen CRM a roles no-MASTER
        if (!$es_master) {
            unset($row['costo_meta'], $row['margen_ganancia']);
        }
        $data[] = $row;
    }
    $stmtData->close();

    echo json_encode([
        'status'        => 'success',
        'is_master'     => $es_master,
        'kpis'          => $kpis,
        'data'          => $data,
        'total_pages'   => $total_pages,
        'current_page'  => $page,
        'total_records' => $total_records
    ]);
    exit;
}

/**
 * [FAC-04] Desglose de una orden con Blindaje de Privacidad (MASTER vs Sede)
 */
if ($action === 'get_order_details') {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    if ($id_orden <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID de orden inválido']);
        exit;
    }

    $stmtOrd = $con->prepare("SELECT o.*, s.nombre_sede FROM waba_ordenes_cobro o JOIN sedes s ON o.id_sede = s.id WHERE o.id = ?");
    $stmtOrd->bind_param("i", $id_orden);
    $stmtOrd->execute();
    $orden = $stmtOrd->get_result()->fetch_assoc();
    $stmtOrd->close();

    if (!$orden) {
        echo json_encode(['status' => 'error', 'message' => 'Orden no encontrada']);
        exit;
    }

    if (!$es_master && intval($orden['id_sede']) !== $id_sede_agente) {
        echo json_encode(['status' => 'error', 'message' => 'Acceso denegado a esta orden']);
        exit;
    }

    $res = $con->query("SELECT * FROM waba_ordenes_detalles WHERE id_orden = $id_orden ORDER BY id ASC");
    $raw_rows = [];
    $costo_meta_sum = 0.0;
    $margen_sum = 0.0;

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (($row['categoria'] ?? '') === 'ADMIN') {
                $margen_sum += floatval($row['costo_base']);
            } else {
                $costo_meta_sum += floatval($row['costo_base']);
                $raw_rows[] = $row;
            }
        }
    }

    $monto_total_orden = floatval($orden['monto_total']);
    if ($margen_sum <= 0 && floatval($orden['margen_ganancia']) > 0) {
        $margen_sum = floatval($orden['margen_ganancia']);
    }
    if ($costo_meta_sum <= 0 && floatval($orden['costo_meta']) > 0) {
        $costo_meta_sum = floatval($orden['costo_meta']);
    }

    $factor = ($costo_meta_sum > 0) ? (($costo_meta_sum + $margen_sum) / $costo_meta_sum) : 1.0;

    $items_response = [];
    $is_first = true;
    foreach ($raw_rows as $r) {
        $c_base = floatval($r['costo_base']);
        $vol    = intval($r['volumen']);
        $grat   = intval($r['mensajes_gratuitos'] ?? 0);
        $pag    = intval($r['mensajes_pagados'] ?? 0);
        if ($pag <= 0 && $vol > 0 && $grat == 0) {
            $pag = $vol;
        }

        $subtotal_final = $c_base * $factor;
        if ($costo_meta_sum == 0 && $is_first) {
            $subtotal_final = $monto_total_orden;
        }
        $is_first = false;

        $divisor_unit = $pag > 0 ? $pag : ($vol > 0 ? $vol : 1);
        $precio_unitario_final = $subtotal_final / $divisor_unit;

        $item = [
            'nombre_plantilla'      => $r['nombre_plantilla'],
            'categoria'             => $r['categoria'] ?? 'MARKETING',
            'volumen'               => $vol,
            'mensajes_gratuitos'    => $grat,
            'mensajes_pagados'      => $pag,
            'precio_unitario_final' => round($precio_unitario_final, 4),
            'subtotal_final'        => round($subtotal_final, 2)
        ];

        if ($es_master) {
            $item['costo_base'] = round($c_base, 4);
        }

        $items_response[] = $item;
    }

    // Si no había detalles guardados, generar una línea consolidada con comisión integrada
    if (empty($items_response)) {
        $vol_tot = intval($orden['mensajes_totales']);
        $unit_f  = $vol_tot > 0 ? ($monto_total_orden / $vol_tot) : $monto_total_orden;
        $item = [
            'nombre_plantilla'      => 'Servicios de Mensajería Consolidada Meta (API)',
            'categoria'             => 'CONSOLIDADO',
            'volumen'               => $vol_tot,
            'mensajes_gratuitos'    => 0,
            'mensajes_pagados'      => $vol_tot,
            'precio_unitario_final' => round($unit_f, 4),
            'subtotal_final'        => round($monto_total_orden, 2)
        ];
        if ($es_master) {
            $item['costo_base'] = round(floatval($orden['costo_meta']), 4);
        }
        $items_response[] = $item;
    }

    $summary = [
        'id_orden'         => $id_orden,
        'nombre_sede'      => $orden['nombre_sede'],
        'estado'           => $orden['estado'],
        'monto_total'      => round($monto_total_orden, 2),
        'metodo_pago'      => $orden['metodo_pago'] ?? null,
        'referencia_pago'  => $orden['referencia_pago'] ?? null,
        'fecha_pago'       => $orden['fecha_pago'] ?? null,
        'notas_pago'       => $orden['notas_pago'] ?? null,
        'comprobante_path' => $orden['comprobante_path'] ?? null
    ];

    if ($es_master) {
        $summary['costo_meta']      = round(floatval($orden['costo_meta']), 2);
        $summary['margen_ganancia'] = round(floatval($orden['margen_ganancia']), 2);
    }

    echo json_encode([
        'status'    => 'success',
        'is_master' => $es_master,
        'summary'   => $summary,
        'data'      => $items_response
    ]);
    exit;
}

/**
 * [FAC-02] Flujo de Registro y Reporte de Pagos
 * Soporta método de pago, referencia/TxID, fecha, notas y comprobante adjunto.
 * - Si reporta una Sede (no-MASTER): pasa a estado 'EN_REVISION' (Pagada - Pendiente por Revisión).
 * - Si registra MASTER: permite confirmar directamente como 'PAGADO' o dejar 'EN_REVISION'.
 */
if ($action === 'register_payment' || $action === 'mark_paid') {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    if ($id_orden <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID de orden no válido.']);
        exit;
    }

    $stmtOrd = $con->prepare("SELECT id, id_sede, estado, comprobante_path FROM waba_ordenes_cobro WHERE id = ?");
    $stmtOrd->bind_param("i", $id_orden);
    $stmtOrd->execute();
    $orden = $stmtOrd->get_result()->fetch_assoc();
    $stmtOrd->close();

    if (!$orden) {
        echo json_encode(['status' => 'error', 'message' => 'La orden especificada no existe.']);
        exit;
    }

    if (!$es_master && intval($orden['id_sede']) !== $id_sede_agente) {
        echo json_encode(['status' => 'error', 'message' => 'No tiene permiso para reportar pagos de otra sede.']);
        exit;
    }

    $metodo_pago     = trim($_POST['metodo_pago'] ?? '');
    $referencia_pago = trim($_POST['referencia_pago'] ?? '');
    $fecha_pago_in   = trim($_POST['fecha_pago'] ?? date('Y-m-d H:i:s'));
    $notas_pago      = trim($_POST['notas_pago'] ?? '');

    if (empty($metodo_pago) || empty($referencia_pago)) {
        echo json_encode(['status' => 'error', 'message' => 'El Método de Pago y la Referencia / TxID son obligatorios.']);
        exit;
    }

    // Normalizar fecha_pago
    $ts_pago = strtotime($fecha_pago_in);
    $fecha_pago = $ts_pago ? date('Y-m-d H:i:s', $ts_pago) : date('Y-m-d H:i:s');

    // Procesar archivo comprobante adjunto si se envió
    $comprobante_rel_path = $orden['comprobante_path'] ?? null;
    if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['comprobante']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'Error al subir el archivo del comprobante.']);
            exit;
        }

        $ext = strtolower(pathinfo($_FILES['comprobante']['name'], PATHINFO_EXTENSION));
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (!in_array($ext, $allowed_exts, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Formato de comprobante no permitido. Use JPG, PNG, WEBP o PDF.']);
            exit;
        }

        if ($_FILES['comprobante']['size'] > 10 * 1024 * 1024) {
            echo json_encode(['status' => 'error', 'message' => 'El comprobante supera el tamaño máximo de 10MB.']);
            exit;
        }

        $upload_dir = __DIR__ . '/../../assets/uploads/comprobantes_waba';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0775, true);
        }

        $filename = 'pago_orden_' . $id_orden . '_' . time() . '.' . $ext;
        $dest_path = $upload_dir . '/' . $filename;

        if (!move_uploaded_file($_FILES['comprobante']['tmp_name'], $dest_path)) {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar el archivo del comprobante en el servidor.']);
            exit;
        }

        $comprobante_rel_path = 'assets/uploads/comprobantes_waba/' . $filename;
    }

    // Determinar estado:
    // Por regla de control financiero, todo registro/reporte de pago entra por defecto en 'EN_REVISION'
    // ("Pagada - Pendiente por Revisión") para que MASTER verifique que el dinero realmente ingresó.
    $nuevo_estado = 'EN_REVISION';
    if ($es_master && !empty($_POST['estado_destino'])) {
        $estado_solicitado = strtoupper(trim($_POST['estado_destino']));
        if (in_array($estado_solicitado, ['EN_REVISION', 'PAGADO'], true)) {
            $nuevo_estado = $estado_solicitado;
        }
    }

    if ($nuevo_estado === 'PAGADO' && $es_master) {
        $stmtUp = $con->prepare("UPDATE waba_ordenes_cobro 
                                 SET estado = ?, metodo_pago = ?, referencia_pago = ?, fecha_pago = ?, notas_pago = ?, comprobante_path = ?, reportado_por = COALESCE(reportado_por, ?), verificado_por = ?, fecha_verificacion = NOW() 
                                 WHERE id = ?");
        $stmtUp->bind_param("ssssssiii", $nuevo_estado, $metodo_pago, $referencia_pago, $fecha_pago, $notas_pago, $comprobante_rel_path, $id_usuario_actual, $id_usuario_actual, $id_orden);
    } else {
        $stmtUp = $con->prepare("UPDATE waba_ordenes_cobro 
                                 SET estado = 'EN_REVISION', metodo_pago = ?, referencia_pago = ?, fecha_pago = ?, notas_pago = ?, comprobante_path = ?, reportado_por = ? 
                                 WHERE id = ?");
        $stmtUp->bind_param("sssssii", $metodo_pago, $referencia_pago, $fecha_pago, $notas_pago, $comprobante_rel_path, $id_usuario_actual, $id_orden);
    }

    if ($stmtUp->execute()) {
        $stmtUp->close();
        $msg_ok = ($nuevo_estado === 'PAGADO')
            ? "El pago de la orden #$id_orden ha sido registrado y verificado como PAGADO."
            : "El pago de la orden #$id_orden fue reportado exitosamente y quedó PENDIENTE POR REVISIÓN del administrador MASTER.";
        echo json_encode(['status' => 'success', 'message' => $msg_ok, 'nuevo_estado' => $nuevo_estado]);
    } else {
        $err = $stmtUp->error;
        $stmtUp->close();
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar el pago: ' . $err]);
    }
    exit;
}

/**
 * [FAC-02] Verificación / Auditoría de Pago por el rol MASTER
 * Permite aprobar (pasar de EN_REVISION a PAGADO) o rechazar (devolver a PENDIENTE).
 */
if ($action === 'verify_payment') {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    $decision = strtoupper(trim($_POST['decision'] ?? 'APROBAR')); // APROBAR | RECHAZAR
    $notas_verificacion = trim($_POST['notas_verificacion'] ?? '');

    if ($id_orden <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID de orden inválido']);
        exit;
    }

    $stmtOrd = $con->prepare("SELECT notas_pago FROM waba_ordenes_cobro WHERE id = ?");
    $stmtOrd->bind_param("i", $id_orden);
    $stmtOrd->execute();
    $ord = $stmtOrd->get_result()->fetch_assoc();
    $stmtOrd->close();

    if (!$ord) {
        echo json_encode(['status' => 'error', 'message' => 'Orden no encontrada']);
        exit;
    }

    $notas_final = $ord['notas_pago'] ?? '';
    if (!empty($notas_verificacion)) {
        if ($decision === 'APROBAR') {
            $prefix = '[Verificado MASTER]: ';
        } elseif ($decision === 'EN_REVISION') {
            $prefix = '[En Revisión MASTER]: ';
        } else {
            $prefix = '[Observación Rechazo MASTER]: ';
        }
        $notas_final = trim($notas_final . "\n" . $prefix . $notas_verificacion);
    }

    if ($decision === 'APROBAR') {
        $stmt = $con->prepare("UPDATE waba_ordenes_cobro SET estado = 'PAGADO', notas_pago = ?, verificado_por = ?, fecha_verificacion = NOW() WHERE id = ?");
        $stmt->bind_param("sii", $notas_final, $id_usuario_actual, $id_orden);
        $msg = "Pago verificado correctamente. La orden #$id_orden ahora está marcada como PAGADO.";
    } elseif ($decision === 'EN_REVISION') {
        $stmt = $con->prepare("UPDATE waba_ordenes_cobro SET estado = 'EN_REVISION', notas_pago = ?, verificado_por = NULL, fecha_verificacion = NULL WHERE id = ?");
        $stmt->bind_param("si", $notas_final, $id_orden);
        $msg = "La orden #$id_orden fue colocada en estado PAGADA (PENDIENTE POR REVISIÓN).";
    } else {
        $stmt = $con->prepare("UPDATE waba_ordenes_cobro SET estado = 'PENDIENTE', notas_pago = ?, verificado_por = NULL, fecha_verificacion = NULL WHERE id = ?");
        $stmt->bind_param("si", $notas_final, $id_orden);
        $msg = "El reporte de pago fue rechazado y la orden #$id_orden regresó a estado PENDIENTE.";
    }

    if ($stmt->execute()) {
        $stmt->close();
        echo json_encode(['status' => 'success', 'message' => $msg]);
    } else {
        $stmt->close();
        echo json_encode(['status' => 'error', 'message' => 'No se pudo actualizar el estado de verificación.']);
    }
    exit;
}

if ($action === 'resend_notification') {
    $id_orden = intval($_POST['id_orden'] ?? 0);

    $res_o = $con->query("SELECT id_sede, fecha_desde, fecha_hasta, monto_total FROM waba_ordenes_cobro WHERE id = $id_orden");
    if (!$res_o || !($o = $res_o->fetch_assoc())) {
        echo json_encode(['status' => 'error', 'message' => 'Orden no encontrada']);
        exit;
    }
    $id_sede = $o['id_sede'];
    $fecha_desde = $o['fecha_desde'];
    $fecha_hasta = $o['fecha_hasta'];
    $costo_final = $o['monto_total'];

    $notificado = 0;
    $res_s = $con->query("SELECT gerente_nombre, gerente_telefono, gerente_email, email, pref_not_cobro, nombre_sede FROM sedes WHERE id = $id_sede");
    if ($res_s && $s = $res_s->fetch_assoc()) {
        $msg = "Hola {$s['gerente_nombre']}, le recordamos la orden de cobro *#$id_orden* para *{$s['nombre_sede']}* por el consumo de WhatsApp API.\n\n*Periodo:* $fecha_desde al $fecha_hasta\n*Monto Total:* $costo_final USD\n\nPor favor coordine el pago correspondiente.";

        // WHATSAPP
        if (in_array($s['pref_not_cobro'], ['WHATSAPP', 'AMBOS']) && !empty($s['gerente_telefono'])) {
            $res_l = $con->query("SELECT meta_app_id, meta_token, id_negocio FROM lineas_whatsapp WHERE id_sede = $id_sede AND estado = 'ACTIVO' LIMIT 1");
            if ($res_l && $l = $res_l->fetch_assoc()) {
                $waba_token = $l['meta_token'];
                $url_w = 'https://graph.facebook.com/v23.0/' . $l['meta_app_id'] . '/messages';

                $payload_w = [
                    'messaging_product' => 'whatsapp',
                    'to' => $s['gerente_telefono'],
                    'type' => 'template',
                    'template' => [
                        'name' => 'notificacion_orden_cobro',
                        'language' => ['code' => 'es'],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => $s['gerente_nombre'] ?: 'Administración'],
                                    ['type' => 'text', 'text' => strval($id_orden)],
                                    ['type' => 'text', 'text' => $s['nombre_sede']],
                                    ['type' => 'text', 'text' => $fecha_desde],
                                    ['type' => 'text', 'text' => $fecha_hasta],
                                    ['type' => 'text', 'text' => strval(round($costo_final, 2))]
                                ]
                            ]
                        ]
                    ]
                ];

                $ch_w = curl_init($url_w);
                curl_setopt($ch_w, CURLOPT_HTTPHEADER, ["Authorization: Bearer $waba_token", "Content-Type: application/json"]);
                curl_setopt($ch_w, CURLOPT_POST, true);
                curl_setopt($ch_w, CURLOPT_POSTFIELDS, json_encode($payload_w));
                curl_setopt($ch_w, CURLOPT_RETURNTRANSFER, true);
                $resp_w = curl_exec($ch_w);
                curl_close($ch_w);

                $resp_dec = json_decode($resp_w, true);
                if (isset($resp_dec['messages'])) {
                    $notificado = 1;
                } else {
                    $payload_w['type'] = 'text';
                    $payload_w['text'] = ['body' => $msg];
                    unset($payload_w['template']);

                    $ch_w2 = curl_init($url_w);
                    curl_setopt($ch_w2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $waba_token", "Content-Type: application/json"]);
                    curl_setopt($ch_w2, CURLOPT_POST, true);
                    curl_setopt($ch_w2, CURLOPT_POSTFIELDS, json_encode($payload_w));
                    curl_setopt($ch_w2, CURLOPT_RETURNTRANSFER, true);
                    curl_exec($ch_w2);
                    curl_close($ch_w2);
                    $notificado = 1;
                }
            }
        }

        // EMAIL
        $email_destino = !empty($s['gerente_email']) ? $s['gerente_email'] : ($s['email'] ?? '');
        if (!empty($email_destino)) {
            require_once __DIR__ . '/../../core/services/MailerService.php';
            $mailer = new MailerService($con);

            $html_msg = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.05);'>
                <div style='background-color: #2b6cb0; color: white; padding: 20px; text-align: center;'>
                    <h2 style='margin: 0;'>Recordatorio de Cobro</h2>
                    <p style='margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;'>Infraestructura API WhatsApp</p>
                </div>
                <div style='padding: 30px; background-color: #ffffff;'>
                    <p style='font-size: 16px; color: #333;'>Hola <strong>" . ($s['gerente_nombre'] ?: 'Administración') . "</strong>,</p>
                    <p style='color: #4a5568; line-height: 1.6;'>Le recordamos que existe una orden de cobro pendiente para la sede <strong>{$s['nombre_sede']}</strong> correspondiente a los servicios de mensajería consolidada y costos operativos de WhatsApp API.</p>
                    
                    <div style='background-color: #f7fafc; border-left: 4px solid #3182ce; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0 0 10px 0;'><strong>Orden #:</strong> NC-" . date('Y') . "-" . str_pad($id_orden, 4, '0', STR_PAD_LEFT) . "</p>
                        <p style='margin: 0 0 10px 0;'><strong>Periodo:</strong> $fecha_desde al $fecha_hasta</p>
                        <p style='margin: 0; font-size: 18px;'><strong>Monto a Pagar: <span style='color: #c53030;'>$" . number_format($costo_final, 2) . " USD</span></strong></p>
                    </div>
                    
                    <p style='color: #4a5568; font-size: 14px; line-height: 1.5;'>Por favor coordine el pago correspondiente a la brevedad posible y adjunte su comprobante en el módulo de Órdenes WABA.</p>
                </div>
                <div style='background-color: #f1f5f9; padding: 15px; text-align: center; color: #718096; font-size: 12px;'>
                    Este es un mensaje automático de STARFI CRM. No es necesario responder a este correo si ya realizó el pago.
                </div>
            </div>";
            $asunto = "Recordatorio de Estado de Cuenta WhatsApp API - {$s['nombre_sede']}";

            $mail_res = $mailer->sendDirectEmail($email_destino, $s['gerente_nombre'] ?: $s['nombre_sede'], $asunto, $html_msg);
            if (isset($mail_res['status']) && $mail_res['status'] === 'success') {
                $notificado = 1;
            }
        }

        if ($notificado == 1) {
            $con->query("UPDATE waba_ordenes_cobro SET notificacion_enviada = notificacion_enviada + 1 WHERE id = $id_orden");
            echo json_encode(['status' => 'success', 'message' => 'Notificación reenviada exitosamente.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo enviar la notificación. Revise si la sede tiene teléfono o correo válido configurado.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Sede no encontrada']);
    }
    exit;
}

if ($action === 'delete_order') {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    $con->query("DELETE FROM waba_ordenes_detalles WHERE id_orden = $id_orden");
    $con->query("DELETE FROM waba_ordenes_cobro WHERE id = $id_orden");
    echo json_encode(['status' => 'success', 'message' => 'Orden eliminada correctamente.']);
    exit;
}
?>
