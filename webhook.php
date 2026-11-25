<?php
date_default_timezone_set('America/Caracas');
/*
 * WEBHOOK PARA RECEPCIÓN DE MENSAJES Y ESTADOS WHATSAPP — STARFI CRM (QA-STD-GLOBAL-2.1)
 * - Validación estricta obligatoria de firma HMAC-SHA256 en todos los HTTP POST
 * - Aislamiento estricto Multi-Sede de IA (sin fallback cruzado entre sedes)
 * - 100% sentencias preparadas mysqli_prepare() y cero exposición de errores SQL
 */

// Cargar variables de entorno (admite .env raíz o rutas locales de respaldo protegidas)
$envCandidates = [
    __DIR__ . '/.env',
    __DIR__ . '/config/.env.local',
    __DIR__ . '/assets/uploads/.env.local',
];
$envPath = __DIR__ . '/.env';
foreach ($envCandidates as $candPath) {
    if (file_exists($candPath) && is_readable($candPath)) {
        $envPath = $candPath;
        break;
    }
}
$env = [];
if (file_exists($envPath) && is_readable($envPath)) {
    $parsedIni = @parse_ini_file($envPath, false, INI_SCANNER_RAW);
    if (is_array($parsedIni) && !empty($parsedIni)) {
        foreach ($parsedIni as $k => $v) {
            $env[trim((string)$k)] = trim((string)$v, " \t\n\r\0\x0B\"'");
        }
    } else {
        foreach (@file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $envLine) {
            $tLine = ltrim($envLine);
            if ($tLine === '' || $tLine[0] === '#' || $tLine[0] === ';') {
                continue;
            }
            $eq = strpos($tLine, '=');
            if ($eq !== false) {
                $k = trim(substr($tLine, 0, $eq));
                $v = trim(substr($tLine, $eq + 1), " \t\n\r\0\x0B\"'");
                if ($k !== '') {
                    $env[$k] = $v;
                }
            }
        }
    }
}

if (!defined('META_API_VERSION')) {
    define('META_API_VERSION', trim((string)($env['META_API_VERSION'] ?? getenv('META_API_VERSION') ?: 'v23.0')));
}
if (!defined('META_APP_SECRET')) {
    $rawMetaSecret = trim((string)($env['META_APP_SECRET'] ?? getenv('META_APP_SECRET') ?: ''));
    if ($rawMetaSecret === 'replace_with_meta_app_secret') {
        $rawMetaSecret = '';
    }
    define('META_APP_SECRET', $rawMetaSecret);
}

$token = trim((string)($env['WEBHOOK_VERIFY_TOKEN'] ?? getenv('WEBHOOK_VERIFY_TOKEN') ?: ''));
$hubMode = $_GET['hub_mode'] ?? '';
$palabraReto = $_GET['hub_challenge'] ?? '';
$tokenVerificacion = $_GET['hub_verify_token'] ?? '';

if ($hubMode === 'subscribe' || isset($_GET['hub_verify_token'])) {
    if ($token !== '' && $tokenVerificacion !== '' && hash_equals($token, (string)$tokenVerificacion)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $palabraReto;
        exit;
    }
    http_response_code(403);
    echo 'Forbidden: Invalid verify token';
    exit;
}

require_once __DIR__ . '/core/audit.php';

/**
 * Cierra inmediatamente la entrega HTTP 200 OK hacia Meta para cumplir el SLA (< 300ms)
 * mientras el proceso PHP continúa en segundo plano ejecutando IA (Gemini) o envíos de contactos.
 */
function responder_http_200_y_continuar($body = 'EVENT_RECEIVED') {
    static $ya_enviado = false;
    if ($ya_enviado) {
        return;
    }
    $ya_enviado = true;

    ignore_user_abort(true);
    @set_time_limit(120);

    if (PHP_SAPI === 'cli') {
        return;
    }

    if (function_exists('fastcgi_finish_request')) {
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $body;
        fastcgi_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Connection: close');
        header('Content-Encoding: none');
        header('Content-Length: ' . strlen($body));
    }

    echo $body;
    @ob_flush();
    @flush();

    ob_start(function () {
        return '';
    });
}

/**
 * Sincroniza conversaciones.ultimo_msg_id en O(1) por clave primaria tras insertar un mensaje/evento.
 */
function webhook_sync_ultimo_msg($con, $id_conversacion, $msg_id = 0) {
    $cid = intval($id_conversacion);
    $mid = intval($msg_id);
    if ($con instanceof mysqli && $cid > 0 && $mid > 0) {
        $stmt = $con->prepare('UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?) WHERE id = ? AND IFNULL(ultimo_msg_id, 0) < ?');
        if ($stmt) {
            $stmt->bind_param('iii', $mid, $cid, $mid);
            @$stmt->execute();
            $stmt->close();
        }
    }
}

/**
 * Recalcula todos los contadores del embudo de una campaña en 1 sola pasada agregada indexada.
 */
function recalcular_embudo_campana_webhook($con, $id_camp) {
    $id_camp = intval($id_camp);
    if (!($con instanceof mysqli) || $id_camp <= 0) {
        return;
    }
    $sql = 'UPDATE campanas_difusion c
            INNER JOIN (
                SELECT id_campana,
                       COALESCE(SUM(estado_envio = \'PENDIENTE\'), 0) AS pend,
                       COALESCE(SUM(estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')), 0) AS env,
                       COALESCE(SUM(fecha_entrega IS NOT NULL OR estado_envio IN (\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')), 0) AS entr,
                       COALESCE(SUM(fecha_lectura IS NOT NULL OR estado_envio IN (\'LEIDO\',\'RESPONDIDO\')), 0) AS lei,
                       COALESCE(SUM(respondido = 1), 0) AS resp,
                       COALESCE(SUM(estado_envio = \'FALLIDO\'), 0) AS fall,
                       COALESCE(SUM(CASE WHEN estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') THEN costo_usd ELSE 0 END), 0) AS costo_real
                FROM campanas_difusion_destinatarios
                WHERE id_campana = ?
                GROUP BY id_campana
            ) agg ON agg.id_campana = c.id
            SET c.pendientes = agg.pend,
                c.enviados = agg.env,
                c.entregados = agg.entr,
                c.leidos = agg.lei,
                c.respondidos = agg.resp,
                c.fallidos = agg.fall,
                c.costo_real_usd = agg.costo_real
            WHERE c.id = ?';
    $stmt = $con->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $id_camp, $id_camp);
        if (!@$stmt->execute()) {
            starfi_log_exception('webhook', 'Error recalculando embudo de campaña', ['id_campana' => $id_camp, 'error' => $stmt->error]);
        }
        $stmt->close();
    }
}

/**
 * Garantiza una sola vez la existencia de índices críticos (idx_meta_wamid, idx_campana_estado, idx_meta_msg).
 */
function ensure_webhook_indexes($con) {
    if (!($con instanceof mysqli)) {
        return;
    }
    if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
        return;
    }
    $dbName = 'starfi_crm';
    $stmtDb = $con->prepare('SELECT DATABASE() AS db_name');
    if ($stmtDb) {
        if (@$stmtDb->execute()) {
            $resDb = $stmtDb->get_result();
            if ($resDb && ($rw = $resDb->fetch_assoc()) && !empty($rw['db_name'])) {
                $dbName = (string)$rw['db_name'];
            }
        }
        $stmtDb->close();
    }
    $flag = rtrim(sys_get_temp_dir(), '/\\') . '/starfi_webhook_idx_v2_' . md5($dbName) . '.flag';
    if (file_exists($flag)) {
        return;
    }
    $r1 = @$con->query('SHOW INDEX FROM `mensajes_y_eventos` WHERE Key_name = \'idx_meta_wamid\'');
    if ($r1 && $r1->num_rows === 0) {
        @$con->query('ALTER TABLE `mensajes_y_eventos` ADD INDEX `idx_meta_wamid` (id_mensaje_meta(100))');
    }
    $r2 = @$con->query('SHOW INDEX FROM `campanas_difusion_destinatarios` WHERE Key_name = \'idx_campana_estado\'');
    if ($r2 !== false && $r2->num_rows === 0) {
        @$con->query('ALTER TABLE `campanas_difusion_destinatarios` ADD INDEX `idx_campana_estado` (id_campana, estado_envio)');
    }
    $r3 = @$con->query('SHOW INDEX FROM `campanas_difusion_destinatarios` WHERE Key_name = \'idx_meta_msg\'');
    if ($r3 !== false && $r3->num_rows === 0) {
        @$con->query('ALTER TABLE `campanas_difusion_destinatarios` ADD INDEX `idx_meta_msg` (id_mensaje_meta)');
    }
    @file_put_contents($flag, date('Y-m-d H:i:s'));
}

if (!defined('WEBHOOK_NO_EXECUTE')) {

    /*
     * RECEPCIÓN DE MENSAJES Y ESTADOS (HTTP POST)
     */
    @ini_set('display_errors', '0');
    $raw_payload = file_get_contents('php://input');
    $respuesta = (string)$raw_payload;

    // VALIDACIÓN ESTRICTA Y OBLIGATORIA DE FIRMA HMAC SHA-256 EN TODO HTTP POST (QA-BUG-CRM2-12 / CHK-CLD-02)
    if (PHP_SAPI !== 'cli') {
        if (!defined('META_APP_SECRET') || trim((string)META_APP_SECRET) === '') {
            require_once __DIR__ . '/config/database.php';
            registrar_auditoria('webhook', 'RECHAZO_FIRMA_HMAC', 'Petición POST a webhook rechazada: META_APP_SECRET no configurado.', null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            http_response_code(403);
            exit('Forbidden: META_APP_SECRET requerido');
        }
        $signature = trim((string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''));
        if ($signature === '') {
            require_once __DIR__ . '/config/database.php';
            registrar_auditoria('webhook', 'RECHAZO_FIRMA_HMAC', 'Petición POST a webhook rechazada: cabecera X-Hub-Signature-256 ausente.', null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            http_response_code(403);
            exit('Forbidden: Firma X-Hub-Signature-256 no proporcionada');
        }
        $expected_signature = 'sha256=' . hash_hmac('sha256', $raw_payload, trim((string)META_APP_SECRET));
        if (!hash_equals($expected_signature, $signature)) {
            require_once __DIR__ . '/config/database.php';
            registrar_auditoria('webhook', 'RECHAZO_FIRMA_HMAC', 'Petición POST a webhook rechazada: firma X-Hub-Signature-256 inválida.', null, ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
            http_response_code(403);
            exit('Forbidden: Firma X-Hub-Signature-256 invalida');
        }
    }

    // Log local una vez validada la autenticidad del origen
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    @file_put_contents($log_dir . '/logs_webhook.log', date('Y-m-d H:i:s') . ' - ' . $respuesta . "\n", FILE_APPEND);

    // Convertimos el JSON en array de PHP
    $respuesta_array = json_decode($respuesta, true);

    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/core/crm_sprint2_helper.php';
    $con = getDbConnection();

    if ($con instanceof mysqli) {
        ensure_webhook_indexes($con);
        $stmt_aud_wh = $con->prepare('INSERT INTO auditoria_webhooks (payload_json) VALUES (?)');
        if ($stmt_aud_wh) {
            $stmt_aud_wh->bind_param('s', $respuesta);
            if (!@$stmt_aud_wh->execute()) {
                starfi_log_exception('webhook', 'Error registrando auditoria_webhooks', ['error' => $stmt_aud_wh->error]);
            }
            $stmt_aud_wh->close();
        }
    }

    // Verificar que hay datos
    if (!$respuesta_array || !isset($respuesta_array['entry'][0]['changes'][0]['value'])) {
        http_response_code(200);
        exit;
    }

    $value = $respuesta_array['entry'][0]['changes'][0]['value'];

    $telefonoReceptorID = $value['metadata']['phone_number_id'] ?? null;
    $displayPhoneNumber = $value['metadata']['display_phone_number'] ?? null;

    // GESTIÓN DE ESTADOS (Doble check: enviado, entregado, leído, fallido)
    if (!empty($value['statuses']) && is_array($value['statuses'])) {
        $campanas_por_recalcular = [];
        foreach ($value['statuses'] as $status_obj) {
            $estado = $status_obj['status'] ?? '';
            $id_mensaje_meta_status = trim((string)($status_obj['id'] ?? ''));
            if ($id_mensaje_meta_status === '') {
                continue;
            }

            $estado_sql = null;
            if ($estado === 'sent') {
                $estado_sql = 'ENVIADO';
            } elseif ($estado === 'delivered') {
                $estado_sql = 'ENTREGADO';
            } elseif ($estado === 'read') {
                $estado_sql = 'LEIDO';
            } elseif ($estado === 'failed') {
                $estado_sql = 'FALLIDO';
            }

            // Extraer metadatos de facturación / conversación de Meta
            $pricing_cat = isset($status_obj['pricing']['category']) ? strtoupper((string)$status_obj['pricing']['category']) : null;
            if (!$pricing_cat && isset($status_obj['conversation']['origin']['type'])) {
                $pricing_cat = strtoupper((string)$status_obj['conversation']['origin']['type']);
            }
            $is_billable = isset($status_obj['pricing']['billable']) ? ($status_obj['pricing']['billable'] ? 1 : 0) : null;
            $conv_id_meta = isset($status_obj['conversation']['id']) ? (string)$status_obj['conversation']['id'] : null;

            $error_detalle_meta = null;
            if ($estado === 'failed' && isset($status_obj['errors'][0])) {
                $err = $status_obj['errors'][0];
                $err_code = $err['code'] ?? '';
                $err_title = $err['title'] ?? '';
                $err_data = $err['error_data']['details'] ?? ($err['message'] ?? '');

                if ($err_code == 131047) {
                    $error_detalle_meta = 'Ventana de 24 horas excedida (Error Meta 131047). El cliente debe enviar un mensaje primero o enviar una plantilla aprobada.';
                } elseif ($err_code == 131026) {
                    $error_detalle_meta = 'Mensaje no entregable (Error Meta 131026). El número de teléfono no posee WhatsApp activo o fue rechazado.';
                } elseif ($err_code == 131049) {
                    $error_detalle_meta = 'Límite de frecuencia de Marketing (Error Meta 131049): este número ya recibió varios mensajes promocionales hoy sin responder. Si el cliente envía un mensaje (ej. \'Hola\') al número de la sede, Meta desbloquea la entrega de inmediato.';
                } elseif ($err_code == 131042) {
                    $error_detalle_meta = 'Problema de método de pago en Meta (Error 131042): la cuenta WABA de esta sede tiene un saldo o pago pendiente en Meta Billing Hub.';
                } elseif ($err_code == 130472) {
                    $error_detalle_meta = 'Restricción de Meta (Error 130472). El número forma parte de un entorno de pruebas o experimento.';
                } else {
                    $error_detalle_meta = "Rechazado por Meta (Error {$err_code} - {$err_title}): {$err_data}";
                }
            }

            if (($con instanceof mysqli) && $estado_sql !== null) {
                // Evitar degradar el estado si el webhook 'sent' llega después de 'delivered' o 'read'
                $set_clauses = [
                    'estado_envio = CASE
                        WHEN ? = \'FALLIDO\' THEN \'FALLIDO\'
                        WHEN ? = \'LEIDO\' THEN \'LEIDO\'
                        WHEN ? = \'ENTREGADO\' AND estado_envio NOT IN (\'LEIDO\') THEN \'ENTREGADO\'
                        WHEN ? = \'ENVIADO\' AND (estado_envio IS NULL OR estado_envio NOT IN (\'ENTREGADO\',\'LEIDO\',\'FALLIDO\')) THEN \'ENVIADO\'
                        ELSE estado_envio END'
                ];
                $bind_types = 'ssss';
                $bind_vals = [$estado_sql, $estado_sql, $estado_sql, $estado_sql];

                if (!empty($error_detalle_meta)) {
                    $set_clauses[] = 'error_detalle = ?';
                    $bind_types .= 's';
                    $bind_vals[] = $error_detalle_meta;
                }
                if ($pricing_cat !== null) {
                    $set_clauses[] = 'categoria_meta = ?';
                    $bind_types .= 's';
                    $bind_vals[] = $pricing_cat;
                }
                if ($is_billable !== null) {
                    $set_clauses[] = 'es_pagado = ?';
                    $bind_types .= 'i';
                    $bind_vals[] = intval($is_billable);
                }
                if ($conv_id_meta !== null) {
                    $set_clauses[] = 'conversation_id_meta = ?';
                    $bind_types .= 's';
                    $bind_vals[] = $conv_id_meta;
                }

                if ($is_billable === 1 || ($is_billable === null && $pricing_cat !== null)) {
                    $cat_check = $pricing_cat ?? 'MARKETING';
                    $costo_estimado = 0.0700;
                    if ($cat_check === 'UTILITY' || $cat_check === 'AUTHENTICATION') {
                        $costo_estimado = 0.0150;
                    } elseif ($cat_check === 'SERVICE') {
                        $costo_estimado = 0.0120;
                    }
                    $set_clauses[] = 'costo_meta_estimado = ?';
                    $bind_types .= 'd';
                    $bind_vals[] = $costo_estimado;
                } elseif ($is_billable === 0) {
                    $costo_cero = 0.0000;
                    $set_clauses[] = 'costo_meta_estimado = ?';
                    $bind_types .= 'd';
                    $bind_vals[] = $costo_cero;
                }

                $bind_types .= 's';
                $bind_vals[] = $id_mensaje_meta_status;

                $sql_upd_msg = 'UPDATE mensajes_y_eventos SET ' . implode(', ', $set_clauses) . ' WHERE id_mensaje_meta = ?';
                $stmt_upd_msg = $con->prepare($sql_upd_msg);
                if ($stmt_upd_msg) {
                    $stmt_upd_msg->bind_param($bind_types, ...$bind_vals);
                    if (!@$stmt_upd_msg->execute()) {
                        starfi_log_exception('webhook', 'Error actualizando estado en mensajes_y_eventos', ['error' => $stmt_upd_msg->error]);
                    }
                    $stmt_upd_msg->close();
                }

                // ====== ECOSISTEMA DE CAMPAÑAS DE DIFUSIÓN: ACTUALIZAR ENTREGADOS, LEÍDOS Y FALLIDOS ======
                $row_dest = null;
                $stmt_dest_sel = $con->prepare(
                    'SELECT d.id, d.id_campana, d.estado_envio, d.respondido, c.costo_unitario_usd
                     FROM campanas_difusion_destinatarios d
                     LEFT JOIN campanas_difusion c ON c.id = d.id_campana
                     WHERE d.id_mensaje_meta = ?
                     LIMIT 1'
                );
                if ($stmt_dest_sel) {
                    $stmt_dest_sel->bind_param('s', $id_mensaje_meta_status);
                    if (@$stmt_dest_sel->execute()) {
                        $res_dest = $stmt_dest_sel->get_result();
                        if ($res_dest && $res_dest->num_rows > 0) {
                            $row_dest = $res_dest->fetch_assoc();
                        }
                    }
                    $stmt_dest_sel->close();
                }

                if ($row_dest) {
                    $id_dest = (int)$row_dest['id'];
                    $id_camp = (int)$row_dest['id_campana'];
                    $est_actual = (string)$row_dest['estado_envio'];
                    $ya_respondio = (int)$row_dest['respondido'];
                    $costo_camp_unit = floatval($row_dest['costo_unitario_usd'] ?? 0.0770);

                    $dest_clauses = [];
                    $dest_types = '';
                    $dest_vals = [];

                    if ($estado_sql === 'ENTREGADO') {
                        if ($ya_respondio === 0 && $est_actual !== 'LEIDO') {
                            $dest_clauses[] = 'estado_envio = \'ENTREGADO\'';
                        }
                        $dest_clauses[] = 'fecha_entrega = IFNULL(fecha_entrega, NOW())';
                    } elseif ($estado_sql === 'LEIDO') {
                        if ($ya_respondio === 0) {
                            $dest_clauses[] = 'estado_envio = \'LEIDO\'';
                        }
                        $dest_clauses[] = 'fecha_entrega = IFNULL(fecha_entrega, NOW())';
                        $dest_clauses[] = 'fecha_lectura = IFNULL(fecha_lectura, NOW())';
                    } elseif ($estado_sql === 'FALLIDO') {
                        $dest_clauses[] = 'estado_envio = \'FALLIDO\'';
                        $dest_clauses[] = 'costo_usd = 0.0000';
                        if (!empty($error_detalle_meta)) {
                            $dest_clauses[] = 'error_detalle = ?';
                            $dest_types .= 's';
                            $dest_vals[] = $error_detalle_meta;
                        }
                    }

                    if ($estado_sql !== 'FALLIDO' && $costo_camp_unit > 0) {
                        $dest_clauses[] = 'costo_usd = ?';
                        $dest_types .= 'd';
                        $dest_vals[] = $costo_camp_unit;
                    }

                    if (!empty($dest_clauses)) {
                        $dest_types .= 'i';
                        $dest_vals[] = $id_dest;
                        $sql_upd_dest = 'UPDATE campanas_difusion_destinatarios SET ' . implode(', ', $dest_clauses) . ' WHERE id = ?';
                        $stmt_upd_dest = $con->prepare($sql_upd_dest);
                        if ($stmt_upd_dest) {
                            $stmt_upd_dest->bind_param($dest_types, ...$dest_vals);
                            if (@$stmt_upd_dest->execute() && $id_camp > 0) {
                                $campanas_por_recalcular[$id_camp] = true;
                            }
                            $stmt_upd_dest->close();
                        }
                    }
                }
            }
        }
        foreach (array_keys($campanas_por_recalcular) as $cid_recalc) {
            recalcular_embudo_campana_webhook($con, $cid_recalc);
        }
        if (!isset($value['messages'][0])) {
            http_response_code(200);
            echo 'EVENT_RECEIVED';
            exit;
        }
    }

    // Verificar que hay mensajes
    if (!isset($value['messages'][0])) {
        http_response_code(200);
        exit;
    }

    $msg = $value['messages'][0];
    $telefonoCliente = $msg['from'] ?? null;
    $id_mensaje_meta = $msg['id'] ?? null;
    $times = $msg['timestamp'] ?? time();
    $perfil = $respuesta_array['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'Usuario';

    // WHK-01: Idempotencia estricta por id_mensaje_meta usando idx_meta_wamid antes de procesar o llamar a la IA
    if (($con instanceof mysqli) && !empty($id_mensaje_meta)) {
        $stmt_dup = @$con->prepare('SELECT id FROM mensajes_y_eventos USE INDEX (idx_meta_wamid) WHERE id_mensaje_meta = ? LIMIT 1');
        if (!$stmt_dup) {
            $stmt_dup = $con->prepare('SELECT id FROM mensajes_y_eventos WHERE id_mensaje_meta = ? LIMIT 1');
        }
        if ($stmt_dup) {
            $id_meta_str = (string)$id_mensaje_meta;
            $stmt_dup->bind_param('s', $id_meta_str);
            $stmt_dup->execute();
            $stmt_dup->store_result();
            $es_duplicado = ($stmt_dup->num_rows > 0);
            $stmt_dup->close();
            if ($es_duplicado) {
                http_response_code(200);
                echo 'EVENT_RECEIVED';
                exit;
            }
        }
    }

    $tipo_mensaje = $msg['type'] ?? 'text';
    $mensaje_texto = null;
    $tipo_bd = 'TEXTO';
    $url_archivo = null;
    $mime_type = null;

    if ($tipo_mensaje === 'text') {
        $mensaje_texto = $msg['text']['body'] ?? '';
    } elseif ($tipo_mensaje === 'image') {
        $tipo_bd = 'IMAGEN';
        $mensaje_texto = $msg['image']['caption'] ?? 'Imagen recibida';
        $url_archivo = $msg['image']['id'] ?? null;
        $mime_type = $msg['image']['mime_type'] ?? 'image/jpeg';
    } elseif ($tipo_mensaje === 'document') {
        $tipo_bd = 'DOCUMENTO';
        $mensaje_texto = $msg['document']['caption'] ?? $msg['document']['filename'] ?? 'Documento recibido';
        $url_archivo = $msg['document']['id'] ?? null;
        $mime_type = $msg['document']['mime_type'] ?? 'application/pdf';
    } elseif ($tipo_mensaje === 'audio') {
        $tipo_bd = 'AUDIO';
        $mensaje_texto = 'Audio recibido';
        $url_archivo = $msg['audio']['id'] ?? null;
        $mime_type = $msg['audio']['mime_type'] ?? 'audio/ogg';
    } elseif ($tipo_mensaje === 'interactive') {
        $tipo_interactivo = $msg['interactive']['type'] ?? '';
        $GLOBALS['tipo_interactivo'] = $tipo_interactivo;
        if ($tipo_interactivo === 'button_reply') {
            $mensaje_texto = $msg['interactive']['button_reply']['title'] ?? '';
        } elseif ($tipo_interactivo === 'list_reply') {
            $mensaje_texto = $msg['interactive']['list_reply']['title'] ?? '';
        }
    } elseif ($tipo_mensaje === 'sticker') {
        $tipo_bd = 'IMAGEN';
        $mensaje_texto = 'Sticker recibido';
        $url_archivo = $msg['sticker']['id'] ?? null;
        $mime_type = $msg['sticker']['mime_type'] ?? 'image/webp';
    } elseif (in_array($tipo_mensaje, ['location', 'reaction'], true)) {
        $tipo_bd = 'EVENTO_SISTEMA';
        if ($tipo_mensaje === 'reaction') {
            $emoji = $msg['reaction']['emoji'] ?? '';
            $mensaje_texto = "El usuario reaccionó con: {$emoji}";
        } elseif ($tipo_mensaje === 'location') {
            $mensaje_texto = 'El usuario envió una ubicación.';
        }
    } else {
        $tipo_bd = 'EVENTO_SISTEMA';
        $mensaje_texto = "Formato de mensaje no soportado recibido ({$tipo_mensaje}).";
    }

    if ($telefonoCliente !== null) {
        save_mensaje($con, $id_mensaje_meta, $telefonoCliente, $times, $mensaje_texto, $perfil, $telefonoReceptorID, $displayPhoneNumber, $tipo_bd, $url_archivo, $mime_type);
    }
}

/**
 * Guardar mensaje recibido y gestionar conversación (100% mysqli_prepare + Aislamiento IA por Sede)
 */
function save_mensaje($con, $id_mensaje_meta, $telefono_cliente, $timestamp, $cuerpo_mensaje, $perfil, $telefono_receptor_id, $display_phone, $tipo_bd = 'TEXTO', $url_archivo = null, $mime_type = null) {
    if (!($con instanceof mysqli)) {
        starfi_log_exception('webhook', 'Error de conexión a BD en save_mensaje');
        return;
    }

    $telefono_cliente = trim((string)$telefono_cliente);
    $cuerpo_mensaje = (string)($cuerpo_mensaje ?? '');
    $perfil = trim((string)($perfil ?? 'Usuario'));
    $telefono_receptor_id = trim((string)($telefono_receptor_id ?? ''));
    $tipo_bd = trim((string)($tipo_bd ?: 'TEXTO'));

    // 1. BUSCAR LA LÍNEA CORRESPONDIENTE AL NÚMERO RECEPTOR
    $id_linea = null;
    $id_empresa = 1;
    $id_sede = 0;

    if ($telefono_receptor_id !== '') {
        $like_receptor = '%' . $telefono_receptor_id . '%';
        $stmt_api = $con->prepare(
            'SELECT l.id, s.id_empresa, l.id_sede
             FROM lineas_whatsapp l
             LEFT JOIN sedes s ON l.id_sede = s.id
             WHERE (l.meta_app_id = ? OR l.meta_telefono_id = ? OR l.numero_telefono LIKE ?)
               AND (l.estado = \'ACTIVO\' OR l.estado_conexion = \'CONECTADO\')
             LIMIT 1'
        );
        if ($stmt_api) {
            $stmt_api->bind_param('sss', $telefono_receptor_id, $telefono_receptor_id, $like_receptor);
            if (@$stmt_api->execute()) {
                $res_api = $stmt_api->get_result();
                if ($res_api && $res_api->num_rows > 0) {
                    $row = $res_api->fetch_assoc();
                    $id_linea = intval($row['id']);
                    if (!empty($row['id_empresa'])) {
                        $id_empresa = intval($row['id_empresa']);
                    }
                    if (!empty($row['id_sede'])) {
                        $id_sede = intval($row['id_sede']);
                    }
                }
            }
            $stmt_api->close();
        }
    }

    // Si no se encuentra la coincidencia específica, abortamos para evitar mensajes cruzados
    if (!$id_linea) {
        starfi_log_exception('webhook', 'Línea receptora no encontrada en BD', ['telefono_receptor_id' => $telefono_receptor_id]);
        http_response_code(200);
        return;
    }

    // 2. BUSCAR O CREAR CLIENTE
    $id_cliente = null;
    $nombre_db = null;
    $stmt_cli = $con->prepare('SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = ? LIMIT 1');
    if ($stmt_cli) {
        $stmt_cli->bind_param('s', $telefono_cliente);
        if (@$stmt_cli->execute()) {
            $res_cliente = $stmt_cli->get_result();
            if ($res_cliente && $res_cliente->num_rows > 0) {
                $row_cliente = $res_cliente->fetch_assoc();
                $id_cliente = intval($row_cliente['id']);
                $nombre_db = $row_cliente['nombre'];
            }
        }
        $stmt_cli->close();
    }

    if (!$id_cliente) {
        $sede_param = ($id_sede > 0) ? $id_sede : null;
        $stmt_ins_cli = $con->prepare('INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre) VALUES (?, ?, ?, NULL)');
        if ($stmt_ins_cli) {
            $stmt_ins_cli->bind_param('iis', $id_empresa, $sede_param, $telefono_cliente);
            if (@$stmt_ins_cli->execute()) {
                $id_cliente = intval($stmt_ins_cli->insert_id);
            } else {
                starfi_log_exception('webhook', 'Error insertando cliente en webhook', ['error' => $stmt_ins_cli->error]);
            }
            $stmt_ins_cli->close();
        }
    }

    if (!$id_cliente) {
        starfi_log_exception('webhook', 'No se pudo obtener ni crear el cliente en el webhook', ['telefono' => $telefono_cliente]);
        return;
    }

    // VERIFICAR SI ES UNA RESPUESTA CSAT (1 al 5) PARA UN CHAT RECIENTEMENTE CERRADO
    $csat_value = 0;
    if ($tipo_bd === 'TEXTO' && preg_match('/^[1-5]$/', trim($cuerpo_mensaje))) {
        $csat_value = intval(trim($cuerpo_mensaje));
    } elseif (isset($GLOBALS['tipo_interactivo']) && $GLOBALS['tipo_interactivo'] === 'button_reply') {
        if (preg_match('/^[1-5]$/', trim($cuerpo_mensaje))) {
            $csat_value = intval(trim($cuerpo_mensaje));
        }
    }

    if ($csat_value > 0) {
        $row_last = null;
        $stmt_last = $con->prepare('SELECT id, csat_score FROM conversaciones WHERE id_cliente = ? AND estado = \'CERRADO\' ORDER BY id DESC LIMIT 1');
        if ($stmt_last) {
            $stmt_last->bind_param('i', $id_cliente);
            if (@$stmt_last->execute()) {
                $res_last = $stmt_last->get_result();
                if ($res_last && $res_last->num_rows > 0) {
                    $row_last = $res_last->fetch_assoc();
                }
            }
            $stmt_last->close();
        }

        if ($row_last && empty($row_last['csat_score'])) {
            $csat_conv_id = intval($row_last['id']);
            $stmt_upd_csat = $con->prepare('UPDATE conversaciones SET csat_score = ? WHERE id = ?');
            if ($stmt_upd_csat) {
                $stmt_upd_csat->bind_param('ii', $csat_value, $csat_conv_id);
                @$stmt_upd_csat->execute();
                $stmt_upd_csat->close();
                registrar_auditoria(
                    'webhook',
                    'CALIFICACION_CSAT_CLIENTE',
                    "Cliente #{$id_cliente} calificó conversación #{$csat_conv_id} con {$csat_value} estrellas",
                    ['csat_score' => null],
                    ['id_conversacion' => $csat_conv_id, 'csat_score' => $csat_value],
                    $con,
                    null,
                    $id_sede
                );
            }

            $id_msg_csat_param = !empty($id_mensaje_meta) ? (string)$id_mensaje_meta : null;
            $csat_txt = "El cliente calificó la atención con {$csat_value} estrellas (CSAT).";
            $stmt_ev_csat = $con->prepare('INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, origen, contenido) VALUES (?, ?, \'EVENTO_SISTEMA\', ?)');
            if ($stmt_ev_csat) {
                $stmt_ev_csat->bind_param('iss', $csat_conv_id, $id_msg_csat_param, $csat_txt);
                if (@$stmt_ev_csat->execute()) {
                    webhook_sync_ultimo_msg($con, $csat_conv_id, intval($stmt_ev_csat->insert_id));
                }
                $stmt_ev_csat->close();
            }

            responder_http_200_y_continuar();

            if ($id_linea) {
                $stmt_lt = $con->prepare('SELECT meta_app_id, meta_token FROM lineas_whatsapp WHERE id = ? LIMIT 1');
                if ($stmt_lt) {
                    $stmt_lt->bind_param('i', $id_linea);
                    if (@$stmt_lt->execute()) {
                        $res_lt = $stmt_lt->get_result();
                        if ($res_lt && ($l_info = $res_lt->fetch_assoc())) {
                            enviar_mensaje_texto_api($con, $l_info, $telefono_cliente, '¡Gracias por tu calificación! Nos ayuda a mejorar.', $csat_conv_id);
                            marcar_como_leido_api($l_info, $id_mensaje_meta);
                        }
                    }
                    $stmt_lt->close();
                }
            }
            return;
        }
    }

    // 3. BUSCAR O CREAR CONVERSACIÓN
    $id_conversacion = null;
    $estado_conv = null;
    $stmt_conv = $con->prepare('SELECT id, estado FROM conversaciones WHERE id_cliente = ? AND id_linea = ? AND estado NOT IN (\'CERRADO\', \'RESUELTO\') LIMIT 1');
    if ($stmt_conv) {
        $stmt_conv->bind_param('ii', $id_cliente, $id_linea);
        if (@$stmt_conv->execute()) {
            $res_conv = $stmt_conv->get_result();
            if ($res_conv && $res_conv->num_rows > 0) {
                $row_conv = $res_conv->fetch_assoc();
                $id_conversacion = intval($row_conv['id']);
                $estado_conv = $row_conv['estado'];
            }
        }
        $stmt_conv->close();
    }

    if ($id_conversacion) {
        $stmt_inc = $con->prepare('UPDATE conversaciones SET mensajes_no_leidos = IFNULL(mensajes_no_leidos, 0) + 1 WHERE id = ?');
        if ($stmt_inc) {
            $stmt_inc->bind_param('i', $id_conversacion);
            @$stmt_inc->execute();
            $stmt_inc->close();
        }
    } else {
        $estado_inicial = (!empty($nombre_db)) ? 'ESPERA_ASIGNACION' : 'BOT_RECOPILANDO';
        $stmt_ins_conv = $con->prepare('INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos) VALUES (?, ?, ?, 1)');
        if ($stmt_ins_conv) {
            $stmt_ins_conv->bind_param('iis', $id_linea, $id_cliente, $estado_inicial);
            if (@$stmt_ins_conv->execute()) {
                $id_conversacion = intval($stmt_ins_conv->insert_id);
                $estado_conv = $estado_inicial;
            } else {
                starfi_log_exception('webhook', 'Error creando conversación', ['error' => $stmt_ins_conv->error]);
            }
            $stmt_ins_conv->close();
        }
    }

    if (!$id_conversacion) {
        starfi_log_exception('webhook', 'No se pudo obtener ni crear la conversación en el webhook', ['id_cliente' => $id_cliente]);
        return;
    }

    // 4. INSERTAR MENSAJE RECIBIDO
    $id_msg_meta_param = !empty($id_mensaje_meta) ? (string)$id_mensaje_meta : null;
    $url_archivo_param = !empty($url_archivo) ? (string)$url_archivo : null;
    $mime_type_param = !empty($mime_type) ? (string)$mime_type : null;

    $stmt_msg = $con->prepare(
        'INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, tipo, origen, contenido, url_archivo, mime_type)
         VALUES (?, ?, ?, \'CLIENTE\', ?, ?, ?)'
    );
    if ($stmt_msg) {
        $stmt_msg->bind_param('isssss', $id_conversacion, $id_msg_meta_param, $tipo_bd, $cuerpo_mensaje, $url_archivo_param, $mime_type_param);
        if (!@$stmt_msg->execute()) {
            starfi_log_exception('webhook', 'Error al guardar mensaje entrante en BD', ['error' => $stmt_msg->error]);
        } else {
            webhook_sync_ultimo_msg($con, $id_conversacion, intval($stmt_msg->insert_id));
        }
        $stmt_msg->close();
    }

    // 4.05 [IA-02] ETIQUETADO AUTOMÁTICO DE SENTIMIENTO DEL CLIENTE (Satisfecho, Neutro, Urgente/Molesto)
    if ($tipo_bd === 'TEXTO' && trim((string)$cuerpo_mensaje) !== '') {
        require_once __DIR__ . '/core/IaConnector.php';
        @IaConnector::actualizarSentimientoConversacion($con, $id_conversacion, null);
    }

    // 4.1 ECOSISTEMA DE CAMPAÑAS DE DIFUSIÓN: REGISTRAR RESPUESTA DEL CLIENTE (CONVERSIÓN)
    $tel_clean_camp = preg_replace('/[^0-9]/', '', $telefono_cliente);
    $tel_tail = strlen($tel_clean_camp) >= 10 ? substr($tel_clean_camp, -10) : $tel_clean_camp;
    if (!empty($tel_tail)) {
        $tel_tail_like = '%' . $tel_tail . '%';
        $row_hit = null;
        $stmt_hit = $con->prepare(
            'SELECT id, id_campana FROM campanas_difusion_destinatarios
             WHERE (id_cliente = ? OR telefono LIKE ?)
               AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\')
               AND respondido = 0
             ORDER BY id DESC LIMIT 1'
        );
        if ($stmt_hit) {
            $stmt_hit->bind_param('is', $id_cliente, $tel_tail_like);
            if (@$stmt_hit->execute()) {
                $res_hit = $stmt_hit->get_result();
                if ($res_hit && $res_hit->num_rows > 0) {
                    $row_hit = $res_hit->fetch_assoc();
                }
            }
            $stmt_hit->close();
        }

        if ($row_hit) {
            $id_dest_hit = (int)$row_hit['id'];
            $id_camp_hit = (int)$row_hit['id_campana'];
            $resp_preview = mb_substr(strip_tags($cuerpo_mensaje), 0, 250);
            $stmt_upd_hit = $con->prepare(
                'UPDATE campanas_difusion_destinatarios
                 SET respondido = 1,
                     estado_envio = \'RESPONDIDO\',
                     respuesta_texto = ?,
                     fecha_entrega = IFNULL(fecha_entrega, NOW()),
                     fecha_lectura = IFNULL(fecha_lectura, NOW()),
                     fecha_respuesta = NOW()
                 WHERE id = ?'
            );
            if ($stmt_upd_hit) {
                $stmt_upd_hit->bind_param('si', $resp_preview, $id_dest_hit);
                @$stmt_upd_hit->execute();
                $stmt_upd_hit->close();
            }
            recalcular_embudo_campana_webhook($con, $id_camp_hit);
        }
    }

    // WHK-03: Cerrar entrega HTTP 200 OK hacia Meta inmediatamente antes de ejecutar IA (Gemini) y envíos
    responder_http_200_y_continuar();

    // 5. ENVIAR RESPUESTA AUTOMÁTICA Y CONTACTOS (en segundo plano)
    if ($id_linea) {
        $linea_info = null;
        $stmt_tok = $con->prepare(
            'SELECT l.meta_app_id, l.meta_token, l.id_sede, s.bot_activo
             FROM lineas_whatsapp l
             LEFT JOIN sedes s ON l.id_sede = s.id
             WHERE l.id = ? AND l.estado = \'ACTIVO\'
             LIMIT 1'
        );
        if ($stmt_tok) {
            $stmt_tok->bind_param('i', $id_linea);
            if (@$stmt_tok->execute()) {
                $res_tok = $stmt_tok->get_result();
                if ($res_tok && $res_tok->num_rows > 0) {
                    $linea_info = $res_tok->fetch_assoc();
                }
            }
            $stmt_tok->close();
        }

        if ($linea_info) {
            // Marcar el mensaje entrante como leído (doble check azul)
            marcar_como_leido_api($linea_info, $id_mensaje_meta);

            // Verificar si el número remitente pertenece a un operador/usuario registrado en la plataforma
            $es_operador = false;
            $tel_digits = preg_replace('/[^0-9]/', '', $telefono_cliente);
            if (!empty($tel_digits)) {
                $stmt_ops = $con->prepare('SELECT telefono FROM usuario_perfil WHERE telefono IS NOT NULL AND telefono != \'\' AND telefono != \'-\'');
                if ($stmt_ops) {
                    if (@$stmt_ops->execute()) {
                        $res_ops = $stmt_ops->get_result();
                        if ($res_ops) {
                            while ($row_op = $res_ops->fetch_assoc()) {
                                $op_tel_digits = preg_replace('/[^0-9]/', '', (string)$row_op['telefono']);
                                if (!empty($op_tel_digits) && (strpos($tel_digits, $op_tel_digits) !== false || strpos($op_tel_digits, $tel_digits) !== false)) {
                                    $es_operador = true;
                                    break;
                                }
                            }
                        }
                    }
                    $stmt_ops->close();
                }
            }

            // ====== LÓGICA DE ROBOT / BOT DE SEDE Y ATENCIÓN IA CON AISLAMIENTO MULTI-SEDE ESTRICTO ======
            $bot_activo = (isset($linea_info['bot_activo']) && intval($linea_info['bot_activo']) === 1);
            $id_sede = intval($linea_info['id_sede'] ?? 0);
            $notificar_admin = false;

            // Verificar si el chat ya fue asignado a un agente humano en el CRM
            $id_agente_actual = 0;
            $stmt_ag = $con->prepare('SELECT id_agente, estado FROM conversaciones WHERE id = ? LIMIT 1');
            if ($stmt_ag) {
                $stmt_ag->bind_param('i', $id_conversacion);
                if (@$stmt_ag->execute()) {
                    $res_ag = $stmt_ag->get_result();
                    if ($res_ag && ($row_ag = $res_ag->fetch_assoc())) {
                        $id_agente_actual = intval($row_ag['id_agente'] ?? 0);
                    }
                }
                $stmt_ag->close();
            }

            // Si el bot está ACTIVO, el remitente NO es un operador y el chat NO ha sido tomado por un vendedor humano:
            if ($bot_activo && !$es_operador && $id_agente_actual === 0) {
                $cuerpo_clean = trim($cuerpo_mensaje);
                $cuerpo_lower = mb_strtolower($cuerpo_clean, 'UTF-8');
                $bot_respondio = false;

                // 1. DETECCIÓN PREVIA DE SOLICITUD DE ATENCIÓN HUMANA (HANDOVER)
                $patrones_humano = ['asesor humano', 'hablar con persona', 'atencion personal', 'soporte humano', 'hablar con vendedor'];
                $solicita_humano = false;
                foreach ($patrones_humano as $patron) {
                    if (mb_strpos($cuerpo_lower, $patron) !== false) {
                        $solicita_humano = true;
                        break;
                    }
                }

                if ($solicita_humano) {
                    require_once __DIR__ . '/core/crm_sprint2_helper.php';
                    $ev_handover = 'El cliente solicitó atención por un agente humano (Handover).';
                    $stmt_ho = $con->prepare('INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, \'EVENTO_SISTEMA\', \'EVENTO_SISTEMA\', ?)');
                    if ($stmt_ho) {
                        $stmt_ho->bind_param('is', $id_conversacion, $ev_handover);
                        if (@$stmt_ho->execute()) {
                            webhook_sync_ultimo_msg($con, $id_conversacion, intval($stmt_ho->insert_id));
                        }
                        $stmt_ho->close();
                    }

                    $asesor_rr = starfi_assign_round_robin_advisor($con, $id_sede, $id_conversacion, $id_cliente, 'Handover Cliente');
                    registrar_auditoria(
                        'webhook',
                        'HANDOVER_HUMANO_CLIENTE',
                        "Transferencia a asesor humano solicitada por cliente en conversación #{$id_conversacion}",
                        ['estado' => $estado_conv],
                        ['id_conversacion' => $id_conversacion, 'id_cliente' => $id_cliente, 'id_asesor' => $asesor_rr['id'] ?? null],
                        $con,
                        null,
                        $id_sede
                    );
                    $msg_handover = $asesor_rr
                        ? "Te estoy transfiriendo con nuestro asesor en línea ({$asesor_rr['nombre']}). En un momento te atenderá personalmente."
                        : 'Te estoy transfiriendo con un asesor de nuestra tienda. En un momento serás atendido por nuestro equipo.';

                    enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $msg_handover, $id_conversacion);
                    enviar_contactos_asesores($linea_info['meta_app_id'], $linea_info['meta_token'], $telefono_cliente, $id_sede, $con, $id_conversacion);
                    if ($asesor_rr && !empty($asesor_rr['telefono'])) {
                        notificar_asesor_round_robin_api($linea_info, $asesor_rr, $nombre_db, $telefono_cliente);
                    }
                    $bot_respondio = true;
                    $notificar_admin = true;
                }

                // 2. RESPUESTA DEL ASISTENTE VIRTUAL IA (ESTRICTO POR SEDE — QA-BUG-CRM2-02 / CHK-CLD-01)
                if (!$bot_respondio) {
                    require_once __DIR__ . '/core/IaContextEngine.php';
                    require_once __DIR__ . '/core/IaConnector.php';

                    // Aislamiento estricto multi-sede: NUNCA usar fallback a configuraciones_ia de otra sede ni global sin sede
                    $configIa = null;
                    if ($id_sede > 0) {
                        $stmt_ia = $con->prepare('SELECT * FROM configuraciones_ia WHERE id_sede = ? AND estado_ia = \'ACTIVO\' LIMIT 1');
                        if ($stmt_ia) {
                            $stmt_ia->bind_param('i', $id_sede);
                            if (@$stmt_ia->execute()) {
                                $res_ia = $stmt_ia->get_result();
                                if ($res_ia && $res_ia->num_rows > 0) {
                                    $configIa = $res_ia->fetch_assoc();
                                }
                            }
                            $stmt_ia->close();
                        }
                    }

                    if ($configIa !== null) {
                        $contextoJIT = IaContextEngine::obtenerContextoInventario($con, $id_sede, $cuerpo_mensaje);
                        $historial = IaConnector::recuperarHistorialMensajes($con, $id_conversacion, 12);
                        $respuestaIa = IaConnector::generarRespuesta($configIa, $historial, $cuerpo_mensaje, $contextoJIT, (string)($nombre_db ?? ''));

                        if (!empty($respuestaIa)) {
                            if (preg_match('/\[GUARDAR_NOMBRE:\s*([^\]]+)\]/i', $respuestaIa, $mNombre)) {
                                $nuevoNombre = trim($mNombre[1]);
                                if (!empty($nuevoNombre)) {
                                    $stmt_upd_nom = $con->prepare('UPDATE clientes_contactos SET nombre = ? WHERE id = ?');
                                    if ($stmt_upd_nom) {
                                        $stmt_upd_nom->bind_param('si', $nuevoNombre, $id_cliente);
                                        @$stmt_upd_nom->execute();
                                        $stmt_upd_nom->close();
                                        registrar_auditoria(
                                            'webhook',
                                            'ACTUALIZAR_NOMBRE_CLIENTE_IA',
                                            "Nombre de cliente #{$id_cliente} actualizado por asistente IA en sede #{$id_sede}",
                                            ['nombre' => $nombre_db],
                                            ['id_cliente' => $id_cliente, 'nombre' => $nuevoNombre],
                                            $con,
                                            null,
                                            $id_sede
                                        );
                                    }
                                    $nombre_db = $nuevoNombre;
                                }
                                $respuestaIa = trim(str_replace($mNombre[0], '', $respuestaIa));
                            }

                            if (strpos($respuestaIa, '[SOLICITAR_AGENTE_HUMANO]') !== false) {
                                require_once __DIR__ . '/core/crm_sprint2_helper.php';
                                $respuestaLimpia = trim(str_replace('[SOLICITAR_AGENTE_HUMANO]', '', $respuestaIa));
                                $ev_ia_ho = 'La IA solicitó transferencia a agente humano ([SOLICITAR_AGENTE_HUMANO]).';
                                $stmt_ia_ho = $con->prepare('INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, \'EVENTO_SISTEMA\', \'EVENTO_SISTEMA\', ?)');
                                if ($stmt_ia_ho) {
                                    $stmt_ia_ho->bind_param('is', $id_conversacion, $ev_ia_ho);
                                    if (@$stmt_ia_ho->execute()) {
                                        webhook_sync_ultimo_msg($con, $id_conversacion, intval($stmt_ia_ho->insert_id));
                                    }
                                    $stmt_ia_ho->close();
                                }

                                $asesor_rr = starfi_assign_round_robin_advisor($con, $id_sede, $id_conversacion, $id_cliente, 'IA [SOLICITAR_AGENTE_HUMANO]');
                                registrar_auditoria(
                                    'webhook',
                                    'HANDOVER_HUMANO_IA',
                                    "Transferencia a asesor humano solicitada por IA en conversación #{$id_conversacion}",
                                    ['estado' => $estado_conv],
                                    ['id_conversacion' => $id_conversacion, 'id_cliente' => $id_cliente, 'id_asesor' => $asesor_rr['id'] ?? null],
                                    $con,
                                    null,
                                    $id_sede
                                );

                                if (empty($respuestaLimpia)) {
                                    $respuestaLimpia = $asesor_rr
                                        ? "Te estoy transfiriendo con nuestro asesor en línea ({$asesor_rr['nombre']}) para atenderte personalmente."
                                        : 'Te estoy transfiriendo con un asesor de nuestra sede para atenderte personalmente.';
                                }
                                enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $respuestaLimpia, $id_conversacion);
                                enviar_contactos_asesores($linea_info['meta_app_id'], $linea_info['meta_token'], $telefono_cliente, $id_sede, $con, $id_conversacion);
                                if ($asesor_rr && !empty($asesor_rr['telefono'])) {
                                    notificar_asesor_round_robin_api($linea_info, $asesor_rr, $nombre_db, $telefono_cliente);
                                }
                                $notificar_admin = true;
                            } else {
                                enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $respuestaIa, $id_conversacion);
                            }
                            $bot_respondio = true;
                        }
                    }
                }

                // 3. FALLBACK DE RESPUESTAS TRADICIONALES DE LA SEDE (AISLADO POR SEDE O GLOBAL NEUTRO NULL/0)
                if (!$bot_respondio) {
                    $cuerpo_upper = mb_strtoupper(trim($cuerpo_mensaje), 'UTF-8');
                    $row_bot_resp = null;
                    if ($id_sede > 0) {
                        $stmt_br = $con->prepare(
                            'SELECT tipo, mensaje FROM bot_respuestas
                             WHERE (id_sede = ? OR id_sede IS NULL OR id_sede = 0)
                               AND estado = \'ACTIVO\'
                               AND UPPER(disparador) = ?
                             ORDER BY (id_sede = ?) DESC
                             LIMIT 1'
                        );
                        if ($stmt_br) {
                            $stmt_br->bind_param('isi', $id_sede, $cuerpo_upper, $id_sede);
                            if (@$stmt_br->execute()) {
                                $res_br = $stmt_br->get_result();
                                if ($res_br && $res_br->num_rows > 0) {
                                    $row_bot_resp = $res_br->fetch_assoc();
                                }
                            }
                            $stmt_br->close();
                        }
                    } else {
                        $stmt_br = $con->prepare(
                            'SELECT tipo, mensaje FROM bot_respuestas
                             WHERE (id_sede IS NULL OR id_sede = 0)
                               AND estado = \'ACTIVO\'
                               AND UPPER(disparador) = ?
                             LIMIT 1'
                        );
                        if ($stmt_br) {
                            $stmt_br->bind_param('s', $cuerpo_upper);
                            if (@$stmt_br->execute()) {
                                $res_br = $stmt_br->get_result();
                                if ($res_br && $res_br->num_rows > 0) {
                                    $row_bot_resp = $res_br->fetch_assoc();
                                }
                            }
                            $stmt_br->close();
                        }
                    }

                    if ($row_bot_resp && !empty($row_bot_resp['mensaje'])) {
                        enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $row_bot_resp['mensaje'], $id_conversacion);
                        $bot_respondio = true;
                    }
                }
            } elseif (!$es_operador && !$bot_activo) {
                $notificar_admin = true;
            }

            if ($notificar_admin && intval($id_sede) === 15) {
                enviar_notificacion_interna_administrador($con, $id_sede, $id_conversacion, $nombre_db, $telefono_cliente);
            }
        }
    }
}

/**
 * Marcar mensaje como leído (Doble check azul) vía Meta API
 */
function marcar_como_leido_api($linea_info, $id_mensaje_meta) {
    $telefonoID = $linea_info['meta_app_id'] ?? null;
    $token_seguro = $linea_info['meta_token'] ?? null;

    if (empty($telefonoID) || empty($token_seguro) || empty($id_mensaje_meta)) {
        return;
    }

    $url = 'https://graph.facebook.com/' . META_API_VERSION . '/' . $telefonoID . '/messages';
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'status' => 'read',
        'message_id' => $id_mensaje_meta
    ]);
    $header = [
        'Authorization: Bearer ' . $token_seguro,
        'Content-Type: application/json'
    ];

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($curl, CURLOPT_TIMEOUT, 3);
    @curl_exec($curl);
    curl_close($curl);
}

/**
 * Enviar mensaje de texto automático vía Meta API
 */
function enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $mensaje_texto, $id_conversacion) {
    $telefonoID = $linea_info['meta_app_id'] ?? null;
    $token_seguro = $linea_info['meta_token'] ?? null;

    if (empty($telefonoID) || empty($token_seguro)) {
        return;
    }

    $url = 'https://graph.facebook.com/' . META_API_VERSION . '/' . $telefonoID . '/messages';
    $mensaje_enviar = json_encode([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $telefono_cliente,
        'type' => 'text',
        'text' => [
            'preview_url' => false,
            'body' => $mensaje_texto
        ]
    ]);
    $header = [
        'Authorization: Bearer ' . $token_seguro,
        'Content-Type: application/json'
    ];

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje_enviar);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($curl, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($curl);
    $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($curl);
    curl_close($curl);

    if ($status_code == 200 && ($con instanceof mysqli)) {
        $res_bot_meta = json_decode((string)$response, true);
        $bot_wamid = isset($res_bot_meta['messages'][0]['id']) ? (string)$res_bot_meta['messages'][0]['id'] : null;
        $cid = intval($id_conversacion);
        $txt = (string)$mensaje_texto;
        $stmt = $con->prepare('INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, tipo, origen, contenido, estado_envio) VALUES (?, ?, \'TEXTO\', \'BOT\', ?, \'ENVIADO\')');
        if ($stmt) {
            $stmt->bind_param('iss', $cid, $bot_wamid, $txt);
            if (@$stmt->execute()) {
                webhook_sync_ultimo_msg($con, $cid, intval($stmt->insert_id));
            }
            $stmt->close();
        }
    } else {
        starfi_log_exception('webhook', 'Error enviando mensaje automático por Meta API', ['http_code' => $status_code, 'curl_err' => $curl_err]);
    }
}

/**
 * Enviar contactos de asesores (fallback lista)
 */
function enviar_contactos_asesores($telefonoID, $token_seguro, $telefono_cliente, $id_sede, $con, $id_conversacion) {
    if (!($con instanceof mysqli) || empty($telefonoID) || empty($token_seguro)) {
        return;
    }

    $id_sede = intval($id_sede);
    $asesores = [];
    if ($id_sede > 0) {
        $stmt_as = $con->prepare('SELECT nombre, telefono FROM bot_vendedores WHERE estado = \'ACTIVO\' AND id_sede = ?');
        if ($stmt_as) {
            $stmt_as->bind_param('i', $id_sede);
            if (@$stmt_as->execute()) {
                $res_as = $stmt_as->get_result();
                if ($res_as) {
                    while ($row = $res_as->fetch_assoc()) {
                        $asesores[] = $row;
                    }
                }
            }
            $stmt_as->close();
        }
    } else {
        $stmt_as = $con->prepare('SELECT nombre, telefono FROM bot_vendedores WHERE estado = \'ACTIVO\' AND (id_sede = 0 OR id_sede IS NULL)');
        if ($stmt_as) {
            if (@$stmt_as->execute()) {
                $res_as = $stmt_as->get_result();
                if ($res_as) {
                    while ($row = $res_as->fetch_assoc()) {
                        $asesores[] = $row;
                    }
                }
            }
            $stmt_as->close();
        }
    }

    if (empty($asesores)) {
        return;
    }

    $nombre_sede = 'Sede';
    if ($id_sede > 0) {
        $stmt_sd = $con->prepare('SELECT nombre_sede FROM sedes WHERE id = ? LIMIT 1');
        if ($stmt_sd) {
            $stmt_sd->bind_param('i', $id_sede);
            if (@$stmt_sd->execute()) {
                $res_sd = $stmt_sd->get_result();
                if ($res_sd && ($row_sede = $res_sd->fetch_assoc()) && !empty(trim((string)($row_sede['nombre_sede'] ?? '')))) {
                    $nombre_sede = trim((string)$row_sede['nombre_sede']);
                }
            }
            $stmt_sd->close();
        }
    }

    $url = 'https://graph.facebook.com/' . META_API_VERSION . '/' . $telefonoID . '/messages';
    $cid = intval($id_conversacion);

    foreach ($asesores as $asesor) {
        $nombre_asesor_limpio = trim((string)($asesor['nombre'] ?? 'Asesor'));
        $mensaje_contacto = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $telefono_cliente,
            'type' => 'contacts',
            'contacts' => [
                [
                    'name' => [
                        'formatted_name' => trim($nombre_asesor_limpio . ' ' . $nombre_sede),
                        'first_name' => $nombre_asesor_limpio,
                        'last_name' => $nombre_sede
                    ],
                    'phones' => [
                        [
                            'phone' => $asesor['telefono'],
                            'type' => 'CELL',
                            'wa_id' => preg_replace('/[^0-9]/', '', (string)$asesor['telefono'])
                        ]
                    ]
                ]
            ]
        ]);

        $header = [
            'Authorization: Bearer ' . $token_seguro,
            'Content-Type: application/json'
        ];

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje_contacto);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($curl, CURLOPT_TIMEOUT, 8);
        @curl_exec($curl);
        curl_close($curl);

        $cont_str = 'Contacto enviado: ' . $asesor['nombre'] . ' (' . $asesor['telefono'] . ')';
        $stmt_ins = $con->prepare('INSERT INTO mensajes_y_eventos (id_conversacion, tipo, origen, contenido) VALUES (?, \'TEXTO\', \'BOT\', ?)');
        if ($stmt_ins) {
            $stmt_ins->bind_param('is', $cid, $cont_str);
            if (@$stmt_ins->execute()) {
                webhook_sync_ultimo_msg($con, $cid, intval($stmt_ins->insert_id));
            }
            $stmt_ins->close();
        }

        usleep(500000);
    }
}

/**
 * Extraer y limpiar el nombre de un mensaje conversacional
 */
function extract_clean_name($text, $profile_fallback = 'Usuario') {
    $parts = preg_split('/[\.\n,]/u', (string)$text);
    $clean = trim($parts[0] ?? '');

    $prefixes = [
        '/^hola\s+soy\s+/ui',
        '/^hola,\s+soy\s+/ui',
        '/^soy\s+/ui',
        '/^mi\s+nombre\s+es\s+/ui',
        '/^me\s+llamo\s+/ui',
        '/^es\s+/ui',
        '/^hola\s+/ui',
        '/^buen\s+dia\s+/ui',
        '/^buenos\s+dias\s+/ui'
    ];

    foreach ($prefixes as $pattern) {
        $clean = preg_replace($pattern, '', $clean);
    }

    $clean = trim($clean);
    $clean_lower = mb_strtolower($clean);

    $invalid_words = ['precio', 'cuanto', 'cuesta', 'tienen', 'donde', 'ubicados', 'hola', 'buen', 'dias', 'tardes', 'noches', 'pregunta', 'lamina', 'pvc', 'fondo', 'blanco', 'disp', '?', '¿', '!', '¡', 'gracias', 'catalogo'];
    foreach ($invalid_words as $word) {
        if (mb_strpos($clean_lower, $word) !== false) {
            return $profile_fallback;
        }
    }

    if (mb_strlen($clean) < 2 || mb_strlen($clean) > 40) {
        return $profile_fallback;
    }

    return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Envía la encuesta CSAT y cierra la conversación
 */
function enviar_csat_y_cerrar_api($con, $linea_info, $telefono_cliente, $id_conversacion) {
    $telefonoID = $linea_info['meta_app_id'] ?? null;
    $token_seguro = $linea_info['meta_token'] ?? null;

    if (empty($telefonoID) || empty($token_seguro)) {
        return;
    }

    $url = 'https://graph.facebook.com/' . META_API_VERSION . '/' . $telefonoID . '/messages';
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $telefono_cliente,
        'type' => 'template',
        'template' => [
            'name' => 'starfi_csat_survey',
            'language' => ['code' => 'es']
        ]
    ]);

    $ch_csat = curl_init($url);
    curl_setopt($ch_csat, CURLOPT_POST, 1);
    curl_setopt($ch_csat, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch_csat, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token_seguro, 'Content-Type: application/json']);
    curl_setopt($ch_csat, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_csat, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch_csat, CURLOPT_TIMEOUT, 8);
    @curl_exec($ch_csat);
    curl_close($ch_csat);

    if ($con instanceof mysqli) {
        $cid = intval($id_conversacion);
        $stmt_cl = $con->prepare('UPDATE conversaciones SET estado = \'CERRADO\' WHERE id = ?');
        if ($stmt_cl) {
            $stmt_cl->bind_param('i', $cid);
            @$stmt_cl->execute();
            $stmt_cl->close();
        }

        $ev_txt = 'Encuesta CSAT enviada y conversación cerrada por el BOT.';
        $stmt_ev = $con->prepare('INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES (?, \'EVENTO_SISTEMA\', ?)');
        if ($stmt_ev) {
            $stmt_ev->bind_param('is', $cid, $ev_txt);
            if (@$stmt_ev->execute()) {
                webhook_sync_ultimo_msg($con, $cid, intval($stmt_ev->insert_id));
            }
            $stmt_ev->close();
        }
    }
}

/**
 * Enviar mensaje de imagen vía Meta API
 */
function enviar_mensaje_imagen_api($con, $linea_info, $telefono_cliente, $image_url, $caption, $id_conversacion) {
    $telefonoID = $linea_info['meta_app_id'] ?? null;
    $token_seguro = $linea_info['meta_token'] ?? null;

    if (empty($telefonoID) || empty($token_seguro)) {
        return;
    }

    $url = 'https://graph.facebook.com/' . META_API_VERSION . '/' . $telefonoID . '/messages';
    $mensaje_enviar = json_encode([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $telefono_cliente,
        'type' => 'image',
        'image' => [
            'link' => $image_url,
            'caption' => $caption
        ]
    ]);
    $header = [
        'Authorization: Bearer ' . $token_seguro,
        'Content-Type: application/json'
    ];

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje_enviar);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($curl, CURLOPT_TIMEOUT, 8);
    @curl_exec($curl);
    curl_close($curl);

    if ($con instanceof mysqli) {
        $cid = intval($id_conversacion);
        $cap_str = (string)$caption;
        $img_str = (string)$image_url;
        $stmt = $con->prepare('INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido, url_archivo) VALUES (?, \'BOT\', \'IMAGEN\', ?, ?)');
        if ($stmt) {
            $stmt->bind_param('iss', $cid, $cap_str, $img_str);
            if (@$stmt->execute()) {
                webhook_sync_ultimo_msg($con, $cid, intval($stmt->insert_id));
            }
            $stmt->close();
        }
    }
}

/**
 * Enviar mensaje de ubicación vía Meta API
 */
function enviar_mensaje_ubicacion_api($con, $linea_info, $telefono_cliente, $latitud, $longitud, $nombre_lugar, $id_conversacion) {
    $telefonoID = $linea_info['meta_app_id'] ?? null;
    $token_seguro = $linea_info['meta_token'] ?? null;

    if (empty($telefonoID) || empty($token_seguro)) {
        return;
    }

    $url = 'https://graph.facebook.com/' . META_API_VERSION . '/' . $telefonoID . '/messages';
    $mensaje_enviar = json_encode([
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $telefono_cliente,
        'type' => 'location',
        'location' => [
            'latitude' => $latitud,
            'longitude' => $longitud,
            'name' => $nombre_lugar,
            'address' => 'Sede'
        ]
    ]);
    $header = [
        'Authorization: Bearer ' . $token_seguro,
        'Content-Type: application/json'
    ];

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje_enviar);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($curl, CURLOPT_TIMEOUT, 8);
    @curl_exec($curl);
    curl_close($curl);

    if ($con instanceof mysqli) {
        $cid = intval($id_conversacion);
        $loc_txt = "{$nombre_lugar} (Lat: {$latitud}, Lng: {$longitud})";
        $stmt = $con->prepare('INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, \'BOT\', \'UBICACION\', ?)');
        if ($stmt) {
            $stmt->bind_param('is', $cid, $loc_txt);
            if (@$stmt->execute()) {
                webhook_sync_ultimo_msg($con, $cid, intval($stmt->insert_id));
            }
            $stmt->close();
        }
    }
}

/**
 * Envía lista de asesores de la sede (como texto formateado con sus números)
 */
function enviar_mensaje_contactos_sede_api($con, $linea_info, $telefono_cliente, $id_conversacion, $id_sede, $mensaje_inicial) {
    if (!($con instanceof mysqli)) {
        return;
    }

    $col_check = @$con->query('SHOW COLUMNS FROM usuario_perfil LIKE \'telefono\'');
    $has_tel = ($col_check && $col_check->num_rows > 0);
    $sql_asesores = $has_tel
        ? 'SELECT up.nombre, up.telefono AS telefono, r.nombre AS rol
           FROM usuario u
           JOIN usuario_perfil up ON u.id = up.id_usuario
           LEFT JOIN roles r ON u.rol = r.id
           WHERE u.id_sede = ? AND u.estado = \'ACTIVO\' AND r.nombre IN (\'ASESOR\', \'AGENTE\', \'VENDEDOR\')'
        : 'SELECT up.nombre, up.celular AS telefono, r.nombre AS rol
           FROM usuario u
           JOIN usuario_perfil up ON u.id = up.id_usuario
           LEFT JOIN roles r ON u.rol = r.id
           WHERE u.id_sede = ? AND u.estado = \'ACTIVO\' AND r.nombre IN (\'ASESOR\', \'AGENTE\', \'VENDEDOR\')';

    $lista_contactos = $mensaje_inicial . "\n\n";
    $hay_contactos = false;
    $sid = intval($id_sede);

    $stmt_as = $con->prepare($sql_asesores);
    if ($stmt_as) {
        $stmt_as->bind_param('i', $sid);
        if (@$stmt_as->execute()) {
            $res_as = $stmt_as->get_result();
            if ($res_as) {
                while ($row = $res_as->fetch_assoc()) {
                    if (!empty($row['telefono'])) {
                        $lista_contactos .= '👤 *' . $row['nombre'] . "*\n";
                        $lista_contactos .= '📱 Wa.me/' . preg_replace('/[^0-9]/', '', (string)$row['telefono']) . "\n\n";
                        $hay_contactos = true;
                    }
                }
            }
        }
        $stmt_as->close();
    }

    if (!$hay_contactos) {
        $lista_contactos = 'Lo sentimos, no hay asesores disponibles registrados para esta sede en este momento.';
    }

    enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $lista_contactos, $id_conversacion);
}

/**
 * Enviar plantilla de notificación interna (starfi_notificacion_interna) ÚNICAMENTE al Administrador cuando un cliente escribe
 */
function enviar_notificacion_interna_administrador($con, $id_sede, $id_conversacion, $nombre_cliente, $numero_cliente) {
    if (!($con instanceof mysqli) || intval($id_sede) !== 15) {
        return;
    }

    $sede_fija = 15;
    $rowLinea = null;
    $stmtL = $con->prepare('SELECT meta_token, meta_app_id FROM lineas_whatsapp WHERE id_sede = ? AND estado = \'ACTIVO\' LIMIT 1');
    if ($stmtL) {
        $stmtL->bind_param('i', $sede_fija);
        if (@$stmtL->execute()) {
            $resL = $stmtL->get_result();
            if ($resL && $resL->num_rows > 0) {
                $rowLinea = $resL->fetch_assoc();
            }
        }
        $stmtL->close();
    }
    if (!$rowLinea) {
        return;
    }

    $meta_token = $rowLinea['meta_token'] ?? '';
    $phone_number_id = $rowLinea['meta_app_id'] ?? '';
    if (empty($meta_token) || empty($phone_number_id)) {
        return;
    }

    $normalizar_tel = function ($raw) {
        $t = preg_replace('/[^0-9]/', '', (string)($raw ?? ''));
        if (strlen($t) === 11 && strpos($t, '0') === 0) {
            $t = '58' . substr($t, 1);
        } elseif (strlen($t) === 13 && strpos($t, '580') === 0) {
            $t = '58' . substr($t, 3);
        } elseif (strlen($t) === 10) {
            $t = '58' . $t;
        }
        return (strlen($t) === 12) ? $t : '';
    };

    $telefonos_destinatarios = [];
    $stmtSop = @$con->prepare(
        'SELECT DISTINCT telefono
         FROM starfi_soporte.usuarios
         WHERE estado = 1
           AND rol IN (\'tecnico\', \'admin\', \'master\')
           AND telefono IS NOT NULL
           AND telefono != \'\''
    );
    if ($stmtSop) {
        if (@$stmtSop->execute()) {
            $resSop = $stmtSop->get_result();
            if ($resSop) {
                while ($rowSop = $resSop->fetch_assoc()) {
                    $tel = $normalizar_tel($rowSop['telefono'] ?? '');
                    if (!empty($tel) && !in_array($tel, $telefonos_destinatarios, true)) {
                        $telefonos_destinatarios[] = $tel;
                    }
                }
            }
        }
        $stmtSop->close();
    }

    if (empty($telefonos_destinatarios)) {
        $stmtAdm = $con->prepare(
            'SELECT DISTINCT up.telefono
             FROM usuario u
             JOIN usuario_perfil up ON u.id = up.id_usuario
             WHERE u.estado = \'ACTIVO\'
               AND u.id_sede = ?'
        );
        if ($stmtAdm) {
            $stmtAdm->bind_param('i', $sede_fija);
            if (@$stmtAdm->execute()) {
                $resAdm = $stmtAdm->get_result();
                if ($resAdm) {
                    while ($rowAd = $resAdm->fetch_assoc()) {
                        $tel = $normalizar_tel($rowAd['telefono'] ?? '');
                        if (!empty($tel) && !in_array($tel, $telefonos_destinatarios, true)) {
                            $telefonos_destinatarios[] = $tel;
                        }
                    }
                }
            }
            $stmtAdm->close();
        }
    }

    if (empty($telefonos_destinatarios)) {
        return;
    }

    $cliente_nombre = (!empty($nombre_cliente) && strtolower((string)$nombre_cliente) !== 'cliente') ? "{$nombre_cliente} ({$numero_cliente})" : $numero_cliente;
    $texto_notificacion = "Tienes un mensaje por responder de {$cliente_nombre}";
    $msg_url = 'https://graph.facebook.com/' . META_API_VERSION . "/{$phone_number_id}/messages";

    foreach ($telefonos_destinatarios as $tel_dest) {
        $post_payload = [
            'messaging_product' => 'whatsapp',
            'to' => $tel_dest,
            'type' => 'template',
            'template' => [
                'name' => 'starfi_notificacion_interna',
                'language' => ['code' => 'es'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $texto_notificacion]
                        ]
                    ]
                ]
            ]
        ];

        $ch = curl_init($msg_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token, 'Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        @curl_exec($ch);
        curl_close($ch);
    }
}

/**
 * [COM-03] Notifica por WhatsApp al asesor en línea seleccionado vía Round-Robin tras transferencia desde IA
 */
function notificar_asesor_round_robin_api($linea_info, $asesor_rr, $nombre_cliente, $numero_cliente) {
    $phone_number_id = trim((string)($linea_info['meta_app_id'] ?? ''));
    $meta_token = trim((string)($linea_info['meta_token'] ?? ''));
    $tel_raw = trim((string)($asesor_rr['telefono'] ?? ''));
    if ($phone_number_id === '' || $meta_token === '' || $meta_token === 'temp_token' || $tel_raw === '' || $tel_raw === '-') {
        return;
    }

    $t = preg_replace('/[^0-9]/', '', $tel_raw);
    if (strlen($t) === 11 && strpos($t, '0') === 0) {
        $t = '58' . substr($t, 1);
    } elseif (strlen($t) === 13 && strpos($t, '580') === 0) {
        $t = '58' . substr($t, 3);
    } elseif (strlen($t) === 10) {
        $t = '58' . $t;
    }
    if (strlen($t) < 10) {
        return;
    }

    $cli_label = (!empty($nombre_cliente) && strtolower((string)$nombre_cliente) !== 'cliente')
        ? "{$nombre_cliente} (+{$numero_cliente})"
        : "+{$numero_cliente}";
    $nombre_ag = $asesor_rr['nombre'] ?? 'Asesor';
    $texto_notif = "Hola {$nombre_ag}, la IA transfirió por Round-Robin el chat de {$cli_label}. ¡Atiéndelo ahora en la Bandeja!";

    $msg_url = 'https://graph.facebook.com/' . META_API_VERSION . "/{$phone_number_id}/messages";
    $post_payload = [
        'messaging_product' => 'whatsapp',
        'to' => $t,
        'type' => 'template',
        'template' => [
            'name' => 'starfi_notificacion_interna',
            'language' => ['code' => 'es'],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $texto_notif]
                    ]
                ]
            ]
        ]
    ];

    $ch = curl_init($msg_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token, 'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    @curl_exec($ch);
    curl_close($ch);
}
