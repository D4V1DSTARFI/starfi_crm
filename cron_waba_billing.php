<?php
require_once __DIR__ . '/core/auth.php';
$con = getDbConnection();

// Este script se ejecuta por CRON (Task Scheduler)
echo "Iniciando revisión de límites de facturación automática WABA...\n";

function emitir_orden_sede($con, $id_sede, $fecha_desde, $fecha_hasta, $orden_pendiente_id) {
    if (!$id_sede || !$fecha_desde || !$fecha_hasta) {
        return ['status' => 'error', 'message' => 'Faltan parámetros'];
    }

    $start_ts = strtotime($fecha_desde . " 00:00:00");
    $end_ts = strtotime($fecha_hasta . " 23:59:59");
    
    // Obtener WABA ID
    $res = $con->query("SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = $id_sede LIMIT 1");
    if (!$res || !($row = $res->fetch_assoc()) || empty($row['id_negocio'])) {
        return ['status' => 'error', 'message' => 'Sede sin WABA configurada'];
    }
    $waba_id = $row['id_negocio'];

    // Obtener Tarifa
    $tipo_tarifa = 'PORCENTAJE';
    $valor_tarifa = 10.00;
    $res = $con->query("SELECT tipo_tarifa, valor FROM waba_tarifas_sede WHERE id_sede = $id_sede");
    if ($res && $row = $res->fetch_assoc()) {
        $tipo_tarifa = $row['tipo_tarifa'];
        $valor_tarifa = floatval($row['valor']);
    }
    
    // API Call
    $fields = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY),analytics.start($start_ts).end($end_ts).granularity(DAY)";
    $url = "https://graph.facebook.com/v23.0/$waba_id?fields=$fields";
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_GLOBAL_TOKEN, "Content-Type: application/json"]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $raw_exec = curl_exec($curl);
    $resp = json_decode($raw_exec, true);
    curl_close($curl);
    
    if (isset($resp['error'])) {
        return ['status' => 'error', 'message' => 'Error de Meta API: ' . $resp['error']['message']];
    }
    
    $costo_meta = 0;
    $costo_meta_por_categoria = [
        'MARKETING' => 0.0,
        'UTILITY' => 0.0,
        'AUTHENTICATION' => 0.0,
        'SERVICE' => 0.0
    ];

    if(isset($resp['pricing_analytics']['data'][0]['data_points'])) {
        foreach($resp['pricing_analytics']['data'][0]['data_points'] as $dp) {
            $cat = strtoupper($dp['pricing_category'] ?? 'MARKETING');
            $c = floatval($dp['cost'] ?? 0);
            if (!isset($costo_meta_por_categoria[$cat])) {
                $costo_meta_por_categoria[$cat] = 0.0;
            }
            $costo_meta_por_categoria[$cat] += $c;
            $costo_meta += $c;
        }
    }
    
    $mensajes_totales = 0;
    if(isset($resp['analytics']['data_points'])) {
        foreach($resp['analytics']['data_points'] as $dp) {
            if (isset($dp['sent'])) $mensajes_totales += intval($dp['sent']);
        }
    } else if(isset($resp['analytics']['data'][0]['data_points'])) {
        foreach($resp['analytics']['data'][0]['data_points'] as $dp) {
            if (isset($dp['sent'])) $mensajes_totales += intval($dp['sent']);
        }
    }
    
    $costo_final = 0;
    $margen = 0;
    
    if ($tipo_tarifa === 'PORCENTAJE') {
        $costo_final = $costo_meta * (1 + ($valor_tarifa / 100));
        $margen = $costo_final - $costo_meta;
    } else if ($tipo_tarifa === 'FIJA') {
        $margen = $mensajes_totales * $valor_tarifa;
        $costo_final = $costo_meta + $margen;
    } else if ($tipo_tarifa === 'PORCENTAJE_VOLUMEN') {
        $p = $mensajes_totales > 1000 ? ($valor_tarifa / 2) : $valor_tarifa;
        $costo_final = $costo_meta * (1 + ($p / 100));
        $margen = $costo_final - $costo_meta;
    }
    
    // Insert order
    $con->begin_transaction();
    try {
        if ($orden_pendiente_id) {
            // Update old to fused
            $con->query("UPDATE waba_ordenes_cobro SET estado = 'FUSIONADO' WHERE id = " . intval($orden_pendiente_id));
        }
        
        if ($orden_pendiente_id) {
            $stmt = $con->prepare("INSERT INTO waba_ordenes_cobro (id_sede, fecha_desde, fecha_hasta, mensajes_totales, costo_meta, margen_ganancia, monto_total, orden_padre_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issidddi", $id_sede, $fecha_desde, $fecha_hasta, $mensajes_totales, $costo_meta, $margen, $costo_final, $orden_pendiente_id);
        } else {
            $stmt = $con->prepare("INSERT INTO waba_ordenes_cobro (id_sede, fecha_desde, fecha_hasta, mensajes_totales, costo_meta, margen_ganancia, monto_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issiddd", $id_sede, $fecha_desde, $fecha_hasta, $mensajes_totales, $costo_meta, $margen, $costo_final);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Error al insertar orden: " . $stmt->error);
        }
        $new_order_id = $stmt->insert_id;
        $stmt->close();
        
        // Registrar desglose por categoría consultando la BD de auditoría local y Meta
        $cat_details = [
            'MARKETING' => ['nombre' => 'Mensajes de Marketing', 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
            'UTILITY' => ['nombre' => 'Mensajes de Utilidad', 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
            'AUTHENTICATION' => ['nombre' => 'Mensajes de Autenticación', 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
            'SERVICE' => ['nombre' => 'Mensajes de Servicio', 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0]
        ];

        // Consultar categorías de la BD local
        $q_cat = "SELECT 
            UPPER(COALESCE(NULLIF(m.categoria_meta, ''), 'MARKETING')) as cat,
            COUNT(*) as vol,
            SUM(CASE WHEN m.es_pagado = 1 THEN 1 ELSE 0 END) as pag,
            SUM(CASE WHEN m.es_pagado = 0 THEN 1 ELSE 0 END) as grat
            FROM mensajes_y_eventos m
            JOIN conversaciones c ON m.id_conversacion = c.id
            JOIN lineas_whatsapp l ON c.id_linea = l.id
            WHERE l.id_sede = $id_sede AND m.origen != 'CLIENTE'
              AND m.timestamp >= '$fecha_desde 00:00:00' AND m.timestamp <= '$fecha_hasta 23:59:59'
            GROUP BY cat";
        $res_c = $con->query($q_cat);
        $total_vol_local = 0;
        if ($res_c) {
            while ($rc = $res_c->fetch_assoc()) {
                $cname = $rc['cat'];
                $v = intval($rc['vol']);
                $total_vol_local += $v;
                if (!isset($cat_details[$cname])) {
                    $cat_details[$cname] = ['nombre' => 'Mensajes ' . ucfirst(strtolower($cname)), 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0];
                }
                $cat_details[$cname]['volumen'] += $v;
                $cat_details[$cname]['pagados'] += intval($rc['pag']);
                $cat_details[$cname]['gratuitos'] += intval($rc['grat']);
            }
        }

        // Asignar el costo real extraído de Meta en lugar de prorratearlo ciegamente
        foreach ($cat_details as $cname => &$cd) {
            $cd['costo'] = round($costo_meta_por_categoria[$cname] ?? 0.0, 4);
        }

        // Si no hay detalle local, asignar volumen total a Marketing por defecto, pero usando su costo real
        if ($total_vol_local === 0) {
            $cat_details['MARKETING']['volumen'] = $mensajes_totales;
            $cat_details['MARKETING']['pagados'] = $mensajes_totales;
        }

        // Insertar cada categoría en waba_ordenes_detalles
        foreach ($cat_details as $cname => $cd) {
            if ($cd['volumen'] > 0 || $cd['costo'] > 0) {
                $n_esc = mysqli_real_escape_string($con, $cd['nombre']);
                $c_esc = mysqli_real_escape_string($con, $cname);
                $vol = intval($cd['volumen']);
                $c_base = floatval($cd['costo']);
                $grat = intval($cd['gratuitos']);
                $pag = intval($cd['pagados']);
                $con->query("INSERT INTO waba_ordenes_detalles (id_orden, nombre_plantilla, categoria, costo_base, volumen, mensajes_gratuitos, mensajes_pagados) VALUES ($new_order_id, '$n_esc', '$c_esc', $c_base, $vol, $grat, $pag)");
            }
        }

        if ($margen > 0) {
            $con->query("INSERT INTO waba_ordenes_detalles (id_orden, nombre_plantilla, categoria, costo_base, volumen, mensajes_gratuitos, mensajes_pagados) VALUES ($new_order_id, 'Margen Administrativo (CRM)', 'ADMIN', $margen, 1, 0, 0)");
        }
        
        $con->commit();
        
        // ==========================================
        // PASO 3: MOTOR DE NOTIFICACIÓN
        // ==========================================
        $notificado = 0;
        $res_s = $con->query("SELECT gerente_nombre, gerente_telefono, gerente_email, pref_not_cobro, nombre_sede FROM sedes WHERE id = $id_sede");
        if ($res_s && $s = $res_s->fetch_assoc()) {
            $msg = "Hola {$s['gerente_nombre']}, se ha generado la orden de cobro *#$new_order_id* para *{$s['nombre_sede']}* por el consumo de WhatsApp API.\n\n*Periodo:* $fecha_desde al $fecha_hasta\n*Monto Total:* $costo_final USD\n\nPor favor coordine el pago correspondiente.";
            
            // WHATSAPP
            if (in_array($s['pref_not_cobro'], ['WHATSAPP', 'AMBOS']) && !empty($s['gerente_telefono'])) {
                $res_l = $con->query("SELECT meta_app_id, meta_token FROM lineas_whatsapp WHERE id_sede = $id_sede AND estado = 'ACTIVO' LIMIT 1");
                if ($res_l && $l = $res_l->fetch_assoc()) {
                    $waba_token = $l['meta_token'];
                    
                    // Asegurar que la plantilla existe
                    $check_url = "https://graph.facebook.com/v23.0/$waba_id/message_templates?name=notificacion_orden_cobro";
                    $ch_ck = curl_init($check_url);
                    curl_setopt($ch_ck, CURLOPT_HTTPHEADER, ["Authorization: Bearer $waba_token"]);
                    curl_setopt($ch_ck, CURLOPT_RETURNTRANSFER, true);
                    $res_ck = json_decode(curl_exec($ch_ck), true);
                    curl_close($ch_ck);
                    
                    $template_ready = false;
                    if (isset($res_ck['data']) && count($res_ck['data']) > 0) {
                        $template_ready = true; // Ya existe
                    } else {
                        // Crear plantilla
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
                                            [$s['gerente_nombre'], strval($new_order_id), $s['nombre_sede'], $fecha_desde, $fecha_hasta, strval($costo_final)]
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
                        $template_ready = true; // Meta suele autoaprobar utilitarias
                    }
                    
                    // Enviar Mensaje
                    $url_w = 'https://graph.facebook.com/v23.0/'.$l['meta_app_id'].'/messages';
                    
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
                                            ['type' => 'text', 'text' => $s['gerente_nombre']],
                                            ['type' => 'text', 'text' => strval($new_order_id)],
                                            ['type' => 'text', 'text' => $s['nombre_sede']],
                                            ['type' => 'text', 'text' => $fecha_desde],
                                            ['type' => 'text', 'text' => $fecha_hasta],
                                            ['type' => 'text', 'text' => strval(round($costo_final, 2))]
                                        ]
                                    ]
                                ]
                            ]
                        ];
                    } else {
                        // Fallback texto plano (si algo falló con la creación)
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
                    <p style='font-size: 16px; color: #333;'>Hola <strong>".($s['gerente_nombre'] ?? 'Administración')."</strong>,</p>
                    <p style='color: #4a5568; line-height: 1.6;'>Se ha generado una nueva orden de cobro para la sede <strong>{$s['nombre_sede']}</strong> correspondiente a los servicios de mensajería consolidada y costos operativos.</p>
                    
                    <div style='background-color: #f7fafc; border-left: 4px solid #3182ce; padding: 15px; margin: 20px 0;'>
                        <p style='margin: 0 0 10px 0;'><strong>Orden #:</strong> NC-".date('Y')."-".str_pad($new_order_id, 4, '0', STR_PAD_LEFT)."</p>
                        <p style='margin: 0 0 10px 0;'><strong>Periodo:</strong> $fecha_desde al $fecha_hasta</p>
                        <p style='margin: 0; font-size: 18px;'><strong>Monto a Pagar: <span style='color: #c53030;'>$" . number_format($costo_final, 2) . " USD</span></strong></p>
                    </div>
                    
                    <p style='color: #4a5568; font-size: 14px; line-height: 1.5;'>Por favor coordine el pago correspondiente e informe por este medio adjuntando el comprobante.</p>
                    
                    <div style='text-align: center; margin-top: 30px;'>
                        <a href='#' style='display: inline-block; background-color: #3182ce; color: white; text-decoration: none; padding: 12px 24px; border-radius: 4px; font-weight: bold;'>Gestionar Pago</a>
                    </div>
                </div>
                <div style='background-color: #f1f5f9; padding: 15px; text-align: center; color: #718096; font-size: 12px;'>
                    Este es un mensaje automático de STARFI CRM. No es necesario responder a este correo si ya realizó el pago.
                </div>
            </div>";
                $asunto = "Estado de Cuenta WhatsApp API - {$s['nombre_sede']}";
                
                $mail_res = $mailer->sendDirectEmail($email_destino, $s['gerente_nombre'] ?? $s['nombre_sede'], $asunto, $html_msg);
                if ($mail_res['status'] === 'success') {
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




// Lógica Principal del CRON
$q = "SELECT t.id_sede, t.limite_facturacion, l.id_negocio as waba_id 
        FROM waba_tarifas_sede t 
        JOIN lineas_whatsapp l ON t.id_sede = l.id_sede 
        WHERE t.limite_facturacion > 0 AND l.estado = 'ACTIVO'";
$res = $con->query($q);

if (!$res || $res->num_rows === 0) {
    echo "No hay sedes con límite de facturación configurado.\n";
    exit;
}

while ($row = $res->fetch_assoc()) {
    $id_sede = intval($row['id_sede']);
    $limite = floatval($row['limite_facturacion']);
    $waba_id = $row['waba_id'];
    
    echo "Revisando sede ID: $id_sede (Límite: $".$limite.")\n";
    
    // Buscar la fecha de la última orden de cobro
    $stmt = $con->prepare("SELECT fecha_hasta FROM waba_ordenes_cobro WHERE id_sede = ? AND estado != 'ANULADA' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $res_last = $stmt->get_result();
    
    $fecha_desde = date('Y-m-01');
    if ($last_order = $res_last->fetch_assoc()) {
        $fecha_desde = date('Y-m-d', strtotime($last_order['fecha_hasta'] . ' + 1 day'));
    }
    $stmt->close();
    
    $fecha_hasta = date('Y-m-d', strtotime('-1 day')); // Revisar hasta ayer
    
    if (strtotime($fecha_desde) > strtotime($fecha_hasta)) {
        echo " - Ya facturado hasta la fecha actual.\n";
        continue;
    }
    
    // Consultar el consumo a Meta para este periodo
    $start_ts = strtotime($fecha_desde . " 00:00:00");
    $end_ts = strtotime($fecha_hasta . " 23:59:59");
    
    $fields = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY)";
    $url = "https://graph.facebook.com/v23.0/$waba_id?fields=$fields";
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_GLOBAL_TOKEN, "Content-Type: application/json"]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $resp_json = curl_exec($curl);
    $resp = json_decode($resp_json, true);
    curl_close($curl);
    
    $costo_acumulado = 0;
    if(isset($resp['pricing_analytics']['data'][0]['data_points'])) {
        foreach($resp['pricing_analytics']['data'][0]['data_points'] as $dp) {
            $costo_acumulado += floatval($dp['cost'] ?? 0);
        }
    }
    
    echo " - Consumo Acumulado (".$fecha_desde." al ".$fecha_hasta."): $".round($costo_acumulado, 2)."\n";
    
    if ($costo_acumulado >= $limite) {
        echo " - ¡Límite superado! Generando orden de cobro...\n";
        // Llamar la función de emisión (la que extrajimos)
        $resultado = emitir_orden_sede($con, $id_sede, $fecha_desde, $fecha_hasta, null);
        if ($resultado['status'] === 'success') {
            echo " - Orden #{$resultado['id_orden']} generada con éxito por ${$resultado['monto_total']} USD.\n";
        } else {
            echo " - ERROR al generar orden: {$resultado['message']}\n";
        }
    } else {
        echo " - Límite no superado aún.\n";
    }
}
echo "Revisión finalizada.\n";
