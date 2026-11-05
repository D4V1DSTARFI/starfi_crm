<?php
// cron_campanas_autopilot.php
// Ejecutor Diario de Piloto Automático para Campañas de Difusión APROBADAS | STARFI CRM
date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/core/auth.php';

$con = getDbConnection();
if (!$con) {
    echo "Error de conexión a la base de datos.\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Iniciando Piloto Automático Diario de Campañas de Difusión...\n";

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

function resolveCampaignImageCron($con, $camp) {
    $baseDir = __DIR__;
    if (!empty($camp['header_imagen_path'])) {
        $rel = ltrim(str_replace(['/starfi_crm/', '\\'], ['', '/'], $camp['header_imagen_path']), '/');
        $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (file_exists($candidate) && is_file($candidate)) {
            return $candidate;
        }
    }
    $res_emp = @mysqli_query($con, "SELECT logo_url FROM empresa_perfil ORDER BY id ASC LIMIT 1");
    if ($res_emp && mysqli_num_rows($res_emp) > 0) {
        $logo_rel = trim(mysqli_fetch_assoc($res_emp)['logo_url'] ?? '');
        if (!empty($logo_rel)) {
            $rel = ltrim(str_replace(['/starfi_crm/', '\\'], ['', '/'], $logo_rel), '/');
            $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (file_exists($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }
    }
    $fallbacks = [
        $baseDir . '/docs/identidad_visual/logos/logo_starfi.png',
        $baseDir . '/uploads/empresa/logo_1784639274_107.png',
        $baseDir . '/assets/img/legacy_pdf_assets/logo_sf.png',
        $baseDir . '/docs/identidad_visual/logos/isologo.png'
    ];
    foreach ($fallbacks as $fb) {
        $fbReal = str_replace('/', DIRECTORY_SEPARATOR, $fb);
        if (file_exists($fbReal) && is_file($fbReal)) {
            return $fbReal;
        }
    }
    return null;
}

function getOrUploadHeaderMediaIdCron($con, array &$camp, string $phone_id, string $token): ?string {
    $existing_id = trim($camp['header_media_id'] ?? '');
    if (!empty($existing_id)) {
        return $existing_id;
    }
    $img_path = resolveCampaignImageCron($con, $camp);
    if (!$img_path || !file_exists($img_path)) {
        return null;
    }
    $mime = @mime_content_type($img_path) ?: 'image/png';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        $mime = 'image/png';
    }
    $media_url = "https://graph.facebook.com/v23.0/{$phone_id}/media";
    $cfile = new CURLFile($img_path, $mime, basename($img_path));
    $post_media = ['messaging_product' => 'whatsapp', 'type' => $mime, 'file' => $cfile];

    $ch = curl_init($media_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_media);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!empty($data['id'])) {
        $media_id = trim($data['id']);
        $camp['header_media_id'] = $media_id;
        $id_camp = intval($camp['id'] ?? 0);
        if ($id_camp > 0) {
            $mid_esc = mysqli_real_escape_string($con, $media_id);
            mysqli_query($con, "UPDATE campanas_difusion SET header_media_id = '$mid_esc' WHERE id = $id_camp");
        }
        return $media_id;
    }
    return null;
}

function recalcularCampanaCron($con, $id_campana) {
    $id_campana = intval($id_campana);
    if ($id_campana <= 0) return;
    @mysqli_query($con, "UPDATE campanas_difusion_destinatarios d
        INNER JOIN mensajes_y_eventos m ON m.id_mensaje_meta COLLATE utf8mb4_unicode_ci = d.id_mensaje_meta COLLATE utf8mb4_unicode_ci
        LEFT JOIN campanas_difusion c ON c.id = d.id_campana
        SET d.estado_envio = CASE
                WHEN m.estado_envio = 'FALLIDO' THEN 'FALLIDO'
                WHEN d.respondido = 1 THEN 'RESPONDIDO'
                WHEN m.estado_envio = 'LEIDO' THEN 'LEIDO'
                WHEN m.estado_envio = 'ENTREGADO' AND d.estado_envio NOT IN ('LEIDO', 'RESPONDIDO') THEN 'ENTREGADO'
                ELSE d.estado_envio
            END,
            d.fecha_entrega = CASE
                WHEN m.estado_envio IN ('ENTREGADO', 'LEIDO') THEN IFNULL(d.fecha_entrega, IFNULL(m.timestamp, NOW()))
                ELSE d.fecha_entrega
            END,
            d.fecha_lectura = CASE
                WHEN m.estado_envio = 'LEIDO' THEN IFNULL(d.fecha_lectura, IFNULL(m.timestamp, NOW()))
                ELSE d.fecha_lectura
            END,
            d.error_detalle = CASE
                WHEN m.estado_envio = 'FALLIDO' AND m.error_detalle IS NOT NULL AND m.error_detalle != '' THEN m.error_detalle
                ELSE d.error_detalle
            END,
            d.costo_usd = CASE
                WHEN m.estado_envio = 'FALLIDO' THEN 0.0000
                WHEN d.costo_usd <= 0 AND c.costo_unitario_usd > 0 THEN c.costo_unitario_usd
                ELSE d.costo_usd
            END
        WHERE d.id_campana = $id_campana
          AND d.id_mensaje_meta IS NOT NULL
          AND d.id_mensaje_meta != ''");

    mysqli_query($con, "UPDATE campanas_difusion c SET 
        total_destinatarios = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana),
        pendientes = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.estado_envio = 'PENDIENTE'),
        enviados = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO')),
        entregados = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND (d.fecha_entrega IS NOT NULL OR d.estado_envio IN ('ENTREGADO','LEIDO','RESPONDIDO'))),
        leidos = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND (d.fecha_lectura IS NOT NULL OR d.estado_envio IN ('LEIDO','RESPONDIDO'))),
        respondidos = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.respondido = 1),
        fallidos = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.estado_envio = 'FALLIDO'),
        costo_real_usd = (SELECT IFNULL(SUM(costo_usd), 0) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO'))
        WHERE c.id = $id_campana");
}

// Liberar filas atascadas en PROCESANDO por más de 3 minutos sin wamid
@mysqli_query($con, "UPDATE campanas_difusion_destinatarios SET estado_envio = 'PENDIENTE' WHERE estado_envio = 'PROCESANDO' AND (id_mensaje_meta IS NULL OR id_mensaje_meta = '') AND fecha_envio < DATE_SUB(NOW(), INTERVAL 3 MINUTE)");

// Buscar únicamente campañas APROBADAS con Piloto Automático activo, destinatarios pendientes, cuya hora programada ya llegó y que NO estén enviando en vivo desde el navegador en los últimos 90s
$sql = "SELECT * FROM campanas_difusion c
        WHERE c.piloto_automatico = 1 
          AND c.aprobado_por IS NOT NULL 
          AND c.pendientes > 0 
          AND IFNULL(c.hora_programada, '00:00:00') <= CURTIME()
          AND c.estado NOT IN ('PENDIENTE_APROBACION', 'CANCELADA', 'COMPLETADA', 'TOPE_PRESUPUESTO')
          AND NOT EXISTS (SELECT 1 FROM campanas_difusion_destinatarios d_act WHERE d_act.id_campana = c.id AND d_act.fecha_envio >= DATE_SUB(NOW(), INTERVAL 90 SECOND))
        ORDER BY c.id ASC";
$res = mysqli_query($con, $sql);

if (!$res || mysqli_num_rows($res) === 0) {
    echo "No hay campañas aprobadas con cuotas pendientes para hoy.\n";
    exit(0);
}

while ($camp = mysqli_fetch_assoc($res)) {
    $id_campana = intval($camp['id']);
    $id_sede = intval($camp['id_sede']);
    $id_usuario = intval($camp['aprobado_por'] ?: $camp['id_usuario'] ?: 1);
    $nombre_campana = $camp['nombre_campana'];

    echo "Procesando Campaña #{$id_campana}: {$nombre_campana} (Sede ID: {$id_sede})...\n";

    // Obtener configuración de flujo diario de la sede
    $res_q = mysqli_query($con, "SELECT limite_diario_mensajes, cadencia_ms FROM campanas_cuotas_sede WHERE id_sede = $id_sede LIMIT 1");
    $lim_sede = 250;
    $cad_ms = 1000;
    if ($res_q && mysqli_num_rows($res_q) > 0) {
        $rq = mysqli_fetch_assoc($res_q);
        $lim_sede = max(1, intval($rq['limite_diario_mensajes']));
        $cad_ms = max(250, intval($rq['cadencia_ms']));
    }

    $cuota_camp = max(1, intval($camp['cuota_diaria_programada'] ?: $lim_sede));
    if ($cuota_camp > $lim_sede) $lim_sede = $cuota_camp;
    $tope_hoy = min($lim_sede, $cuota_camp);

    // Verificar enviados hoy en la sede y en la campaña
    $rh_s = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $id_sede AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
    $env_sede_hoy = ($rh_s && $r1 = mysqli_fetch_assoc($rh_s)) ? intval($r1['c']) : 0;

    $rh_c = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
    $env_camp_hoy = ($rh_c && $r2 = mysqli_fetch_assoc($rh_c)) ? intval($r2['c']) : 0;

    $disp_hoy = min(max(0, $lim_sede - $env_sede_hoy), max(0, $tope_hoy - $env_camp_hoy));
    if ($disp_hoy <= 0) {
        echo " - Cuota diaria de hoy ya alcanzada para esta campaña/sede. Se omite hasta mañana.\n";
        continue;
    }

    // Obtener línea activa de la sede
    $res_lin = mysqli_query($con, "SELECT l.id, l.meta_app_id, l.meta_token, COALESCE(s.id_empresa, 1) AS id_empresa, COALESCE(s.nombre_sede, 'Sede') AS nombre_sede
                                   FROM lineas_whatsapp l
                                   LEFT JOIN sedes s ON s.id = l.id_sede
                                   WHERE l.id_sede = $id_sede AND l.estado = 'ACTIVO' LIMIT 1");
    if (!$res_lin || mysqli_num_rows($res_lin) === 0) {
        echo " - Sin línea activa en la sede $id_sede.\n";
        continue;
    }
    $linea = mysqli_fetch_assoc($res_lin);
    $id_linea = intval($linea['id']);
    $id_empresa = intval($linea['id_empresa'] ?? 1);
    $nombre_sede_actual = trim($linea['nombre_sede'] ?? 'Sede');
    $phone_id = trim($linea['meta_app_id']);
    $token = !empty($linea['meta_token']) ? trim($linea['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

    // Obtener lote pendiente de hoy
    $res_dest = mysqli_query($con, "SELECT * FROM campanas_difusion_destinatarios 
                                    WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' 
                                    ORDER BY numero_tanda ASC, id ASC 
                                    LIMIT $disp_hoy");
    $enviados_tanda = 0;
    while ($dest = mysqli_fetch_assoc($res_dest)) {
        $id_dest = intval($dest['id']);
        $telefono_limpio = normalizeVePhoneCron($dest['telefono']);
        $tel_esc_lock = mysqli_real_escape_string($con, $telefono_limpio);
        $template_name = $camp['template_name'] ?: 'starfi_difusion_directa';
        $tpl_esc_lock = mysqli_real_escape_string($con, $template_name);

        // Reclamo atómico de la fila para evitar colisiones con navegador u otro cron
        mysqli_query($con, "UPDATE campanas_difusion_destinatarios 
                            SET estado_envio = 'PROCESANDO', fecha_envio = NOW() 
                            WHERE id = $id_dest AND id_campana = $id_campana AND estado_envio = 'PENDIENTE' AND (id_mensaje_meta IS NULL OR id_mensaje_meta = '')");
        if (mysqli_affected_rows($con) <= 0) {
            continue;
        }

        // Protección anti-duplicado por teléfono en la misma campaña / mismo día
        $res_dup_tel = mysqli_query($con, "SELECT id FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND id != $id_dest AND telefono = '$tel_esc_lock' AND estado_envio IN ('PROCESANDO','ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') LIMIT 1");
        $res_dup_notif = mysqli_query($con, "SELECT id FROM notificacion_enviada WHERE fecha = CURDATE() AND telefono_receptor = '$tel_esc_lock' AND template = '$tpl_esc_lock' AND status = 'ENVIADO' LIMIT 1");
        if (($res_dup_tel && mysqli_num_rows($res_dup_tel) > 0) || ($res_dup_notif && mysqli_num_rows($res_dup_notif) > 0)) {
            mysqli_query($con, "UPDATE campanas_difusion_destinatarios SET estado_envio = 'ENVIADO', error_detalle = 'Omitido automáticamente: número ya notificado hoy.' WHERE id = $id_dest");
            continue;
        }

        $nombre_cliente = trim($dest['nombre'] ?: 'Cliente');
        $primer_nombre = explode(' ', $nombre_cliente)[0];
        if (empty($primer_nombre) || strlen($primer_nombre) < 2 || preg_match('/^\+?[0-9\s\-]+$/', $primer_nombre)) {
            $primer_nombre = 'Estimado(a) cliente';
        }
        $primer_nombre = sanitizeParamCron($primer_nombre, false);
        $camp_lang = trim($camp['template_language'] ?? 'es') ?: 'es';
        $camp_cat = strtoupper(trim($camp['template_category'] ?? 'MARKETING')) ?: 'MARKETING';
        $camp_hdr_fmt = strtoupper(trim($camp['header_format'] ?? 'IMAGE')) ?: 'IMAGE';
        $costo_unit = floatval($camp['costo_unitario_usd'] ?: 0.07);

        $resolveDynamicTokenCron = function ($val_raw) use ($primer_nombre, $nombre_cliente, $telefono_limpio, $nombre_sede_actual) {
            $str = (string)$val_raw;
            $str = str_ireplace(['@NOMBRE_CLIENTE', '{{nombre_cliente}}'], $primer_nombre, $str);
            $str = str_ireplace(['@NOMBRE_COMPLETO', '{{nombre_completo}}'], $nombre_cliente, $str);
            $str = str_ireplace(['@TELEFONO_CLIENTE', '{{telefono_cliente}}'], $telefono_limpio, $str);
            $str = str_ireplace(['@NOMBRE_SEDE', '{{nombre_sede}}'], $nombre_sede_actual, $str);
            return sanitizeParamCron($str, true);
        };

        $tpl_json_data = !empty($camp['template_params_json']) ? json_decode($camp['template_params_json'], true) : null;
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

        $url = "https://graph.facebook.com/v23.0/{$phone_id}/messages";
        $headers = ["Authorization: Bearer $token", "Content-Type: application/json"];
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
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $resp_data = json_decode($response, true) ?? [];
                if ($idx_lang === 0 && $first_resp_data === null) {
                    $first_resp_data = $resp_data;
                }
                $err_code_meta = intval($resp_data['error']['code'] ?? 0);
                $err_details_meta = strtoupper($resp_data['error']['error_data']['details'] ?? ($resp_data['error']['message'] ?? ''));

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
        $contenido_log_esc = mysqli_real_escape_string($con, $contenido_log);
        $fecha_hoy = date('Y-m-d');
        $hora_actual = date('H:i:s');
        $now_full = "$fecha_hoy $hora_actual";

        if ($http_code >= 200 && $http_code < 300 && isset($resp_data['messages'][0]['id'])) {
            $wamid_raw = $resp_data['messages'][0]['id'];
            $wamid = mysqli_real_escape_string($con, $wamid_raw);
            $tel_esc = mysqli_real_escape_string($con, $telefono_limpio);

            mysqli_query($con, "INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                                VALUES ('$template_name', '$fecha_hoy', '$hora_actual', '$phone_id', '$telefono_limpio', '$contenido_log_esc', 'ENVIADO')");

            // Asegurar cliente en clientes_contactos
            $id_cliente = intval($dest['id_cliente'] ?? 0);
            if ($id_cliente <= 0) {
                $res_c_find = mysqli_query($con, "SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = '$tel_esc' OR numero_whatsapp LIKE '%$tel_esc%' LIMIT 1");
                if ($res_c_find && mysqli_num_rows($res_c_find) > 0) {
                    $row_cli = mysqli_fetch_assoc($res_c_find);
                    $id_cliente = intval($row_cli['id']);
                    mysqli_query($con, "UPDATE clientes_contactos SET ultimo_contacto = '$now_full' WHERE id = $id_cliente");
                } else {
                    $nom_insert = ($primer_nombre !== 'Estimado(a) cliente') ? "'" . mysqli_real_escape_string($con, $nombre_cliente) . "'" : "NULL";
                    mysqli_query($con, "INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre, fecha_registro, ultimo_contacto) 
                                        VALUES ($id_empresa, $id_sede, '$tel_esc', $nom_insert, '$now_full', '$now_full')");
                    $id_cliente = mysqli_insert_id($con);
                }
            } else {
                mysqli_query($con, "UPDATE clientes_contactos SET ultimo_contacto = '$now_full' WHERE id = $id_cliente");
            }

            // Asegurar conversación activa en la sede para trazabilidad con Bandeja y Bot IA
            $id_conversacion = 0;
            if ($id_cliente > 0) {
                $res_conv = mysqli_query($con, "SELECT id FROM conversaciones WHERE id_cliente = $id_cliente AND id_linea = $id_linea AND estado NOT IN ('CERRADO', 'RESUELTO') ORDER BY id DESC LIMIT 1");
                if ($res_conv && mysqli_num_rows($res_conv) > 0) {
                    $id_conversacion = intval(mysqli_fetch_assoc($res_conv)['id']);
                } else {
                    mysqli_query($con, "INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos, fecha_inicio) VALUES ($id_linea, $id_cliente, 'ESPERA_ASIGNACION', 0, '$now_full')");
                    $id_conversacion = mysqli_insert_id($con);
                }
            }

            if ($id_conversacion > 0) {
                $rel_media_bandeja = null;
                if ($needs_image_header) {
                    if (!empty($camp['header_imagen_path'])) {
                        $rel_media_bandeja = '../../' . ltrim(str_replace(['/starfi_crm/', '\\'], ['', '/'], $camp['header_imagen_path']), '/');
                    } else {
                        $abs_img_used = resolveCampaignImageCron($con, $camp);
                        $base_root = str_replace('\\', '/', __DIR__);
                        $abs_norm = $abs_img_used ? str_replace('\\', '/', $abs_img_used) : '';
                        if ($abs_norm && strpos($abs_norm, $base_root) === 0) {
                            $rel_media_bandeja = '../../' . ltrim(substr($abs_norm, strlen($base_root)), '/');
                        }
                    }
                }
                $tipo_msg_bandeja = !empty($rel_media_bandeja) ? 'IMAGEN' : 'TEXTO';
                $url_arch_sql = !empty($rel_media_bandeja) ? ("'" . mysqli_real_escape_string($con, $rel_media_bandeja) . "'") : "NULL";
                $cat_meta_esc = mysqli_real_escape_string($con, $camp_cat);
                mysqli_query($con, "INSERT INTO mensajes_y_eventos 
                    (id_conversacion, id_mensaje_meta, tipo, origen, id_agente, contenido, url_archivo, estado_envio, categoria_meta, es_pagado, costo_meta_estimado, timestamp) 
                    VALUES ($id_conversacion, '$wamid', '$tipo_msg_bandeja', 'API_TRANSACCIONAL', $id_usuario, '$contenido_log_esc', $url_arch_sql, 'ENVIADO', '$cat_meta_esc', 1, $costo_unit, '$now_full')");
            }

            mysqli_query($con, "UPDATE campanas_difusion_destinatarios 
                                SET id_cliente = $id_cliente, id_conversacion = $id_conversacion, telefono = '$tel_esc', id_mensaje_meta = '$wamid', estado_envio = 'ENVIADO', costo_usd = $costo_unit, fecha_envio = NOW(), error_detalle = NULL
                                WHERE id = $id_dest");
            $enviados_tanda++;
        } else {
            $err_msg = mysqli_real_escape_string($con, $resp_data['error']['message'] ?? 'Error Meta API');
            mysqli_query($con, "INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                                VALUES ('$template_name', '$fecha_hoy', '$hora_actual', '$phone_id', '$telefono_limpio', '$contenido_log_esc', 'ERROR')");
            mysqli_query($con, "UPDATE campanas_difusion_destinatarios SET estado_envio = 'FALLIDO', error_detalle = '$err_msg', fecha_envio = NOW() WHERE id = $id_dest");
        }

        usleep($cad_ms * 1000);
    }

    recalcularCampanaCron($con, $id_campana);
    $res_p = mysqli_query($con, "SELECT pendientes FROM campanas_difusion WHERE id = $id_campana LIMIT 1");
    $pend = ($res_p && $rp = mysqli_fetch_assoc($res_p)) ? intval($rp['pendientes']) : 0;
    $nuevo_est = ($pend > 0) ? 'PILOTO_AUTOMATICO' : 'COMPLETADA';
    mysqli_query($con, "UPDATE campanas_difusion SET estado = '$nuevo_est', fecha_fin = NOW() WHERE id = $id_campana");

    echo " - Tanda finalizada: {$enviados_tanda} enviados. Pendientes restantes: {$pend}.\n";
}

echo "Ejecución de Piloto Automático finalizada.\n";
?>
