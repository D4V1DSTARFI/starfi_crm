<?php
date_default_timezone_set('America/Caracas');
/*
 * WEBHOOK MEJORADO PARA RECEPCIÓN DE MENSAJES WHATSAPP
 * - Identifica el número receptor (telefono_meta)
 * - Gestiona conversaciones automáticamente
 * - Vincula con sistema de gestión avanzada
 */

/*
 * VERIFICACION DEL WEBHOOK
*/
// Cargar variables de entorno
$envPath = __DIR__ . '/.env';
$env = file_exists($envPath) ? parse_ini_file($envPath) : [];

//TOKEN QUE QUEREMOS PONER 
$token = $env['WEBHOOK_VERIFY_TOKEN'] ?? 'PARALELEPIPEDO3312';
//RETO QUE RECIBIREMOS DE FACEBOOK
$palabraReto = $_GET['hub_challenge'] ?? '';
//TOKEN DE VERIFICACION QUE RECIBIREMOS DE FACEBOOK
$tokenVerificacion = $_GET['hub_verify_token'] ?? '';
//SI EL TOKEN QUE GENERAMOS ES EL MISMO QUE NOS ENVIA FACEBOOK RETORNAMOS EL RETO PARA VALIDAR QUE SOMOS NOSOTROS
if ($token === $tokenVerificacion) {
    echo $palabraReto;
    exit;
}

if (!defined('WEBHOOK_NO_EXECUTE')) {

    /*
     * RECEPCION DE MENSAJES
     */
    //LEEMOS LOS DATOS ENVIADOS POR WHATSAPP
    $respuesta = file_get_contents("php://input");

    // Log para debugging (lo movemos arriba para saber si Facebook llega aquí)
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) mkdir($log_dir, 0777, true);
    file_put_contents($log_dir . "/webhook_" . date('Y-m-d') . ".log", date('Y-m-d H:i:s') . " - " . $respuesta . "\n", FILE_APPEND);

    // VALIDACIÓN DE FIRMA SHA-256 (Seguridad Crítica)
    $appSecret = $env['META_APP_SECRET'] ?? '';
    if (!empty($appSecret)) {
        $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        if (empty($signature)) {
            http_response_code(403);
            exit('Firma no proporcionada');
        }
        $expected_signature = 'sha256=' . hash_hmac('sha256', $respuesta, $appSecret);
        if (!hash_equals($expected_signature, $signature)) {
            http_response_code(403);
            exit('Firma invalida');
        }
    }

    //CONVERTIMOS EL JSON EN ARRAY DE PHP
    $respuesta_array = json_decode($respuesta, true);

    // Incluir conexión temprana para la auditoría
    require_once('config/database.php');
    $con = getDbConnection();

    if ($con) {
        $payload_esc = mysqli_real_escape_string($con, $respuesta);
        mysqli_query($con, "INSERT INTO auditoria_webhooks (payload_json) VALUES ('$payload_esc')");
    }

    // Verificar que hay datos
    if (!$respuesta_array || !isset($respuesta_array['entry'][0]['changes'][0]['value'])) {
        exit;
    }

    $value = $respuesta_array['entry'][0]['changes'][0]['value'];

    $telefonoReceptorID = $value['metadata']['phone_number_id'] ?? null;
    $displayPhoneNumber = $value['metadata']['display_phone_number'] ?? null;

    // GESTIÓN DE ESTADOS (Doble check: enviado, entregado, leído, fallido)
    if (isset($value['statuses'][0])) {
        $status_obj = $value['statuses'][0];
        $estado = $status_obj['status']; // sent, delivered, read, failed
        $id_mensaje_meta_status = $status_obj['id'];
        
        $estado_sql = null;
        if ($estado == 'sent') $estado_sql = 'ENVIADO';
        else if ($estado == 'delivered') $estado_sql = 'ENTREGADO';
        else if ($estado == 'read') $estado_sql = 'LEIDO';
        else if ($estado == 'failed') $estado_sql = 'FALLIDO';

        // Extraer metadatos de facturación / conversación de Meta
        $pricing_cat = isset($status_obj['pricing']['category']) ? strtoupper($status_obj['pricing']['category']) : null;
        if (!$pricing_cat && isset($status_obj['conversation']['origin']['type'])) {
            $pricing_cat = strtoupper($status_obj['conversation']['origin']['type']);
        }
        $is_billable = isset($status_obj['pricing']['billable']) ? ($status_obj['pricing']['billable'] ? 1 : 0) : null;
        $conv_id_meta = $status_obj['conversation']['id'] ?? null;

        $error_detalle_meta = null;
        if ($estado == 'failed' && isset($status_obj['errors'][0])) {
            $err = $status_obj['errors'][0];
            $err_code = $err['code'] ?? '';
            $err_title = $err['title'] ?? '';
            $err_data = $err['error_data']['details'] ?? ($err['message'] ?? '');
            
            if ($err_code == 131047) {
                $error_detalle_meta = "Ventana de 24 horas excedida (Error Meta 131047). El cliente debe enviar un mensaje primero o enviar una plantilla aprobada.";
            } elseif ($err_code == 131026) {
                $error_detalle_meta = "Mensaje no entregable (Error Meta 131026). El número de teléfono no posee WhatsApp activo o fue rechazado.";
            } elseif ($err_code == 131049) {
                $error_detalle_meta = "Mensaje no entregado (Error Meta 131049). Restricción por salud de ecosistema o políticas de Meta.";
            } elseif ($err_code == 130472) {
                $error_detalle_meta = "Restricción de Meta (Error 130472). El número forma parte de un entorno de pruebas o experimento.";
            } else {
                $error_detalle_meta = "Rechazado por Meta (Error $err_code - $err_title): $err_data";
            }
        }
        
        if ($con && $estado_sql) {
            $id_msg_esc = mysqli_real_escape_string($con, $id_mensaje_meta_status);
            $updates = ["estado_envio = '$estado_sql'"];
            
            if (!empty($error_detalle_meta)) {
                $err_esc = mysqli_real_escape_string($con, $error_detalle_meta);
                $updates[] = "error_detalle = '$err_esc'";
            }
            if ($pricing_cat !== null) {
                $p_cat_esc = mysqli_real_escape_string($con, $pricing_cat);
                $updates[] = "categoria_meta = '$p_cat_esc'";
            }
            if ($is_billable !== null) {
                $updates[] = "es_pagado = $is_billable";
            }
            if ($conv_id_meta !== null) {
                $c_id_esc = mysqli_real_escape_string($con, $conv_id_meta);
                $updates[] = "conversation_id_meta = '$c_id_esc'";
            }

            $update_sql = "UPDATE mensajes_y_eventos SET " . implode(", ", $updates) . " WHERE id_mensaje_meta = '$id_msg_esc'";
            mysqli_query($con, $update_sql);
        }
        exit;
    }

    // Verificar que hay mensajes
    if (!isset($value['messages'][0])) {
        exit;
    }

    $msg = $value['messages'][0];
    $telefonoCliente = $msg['from'] ?? null;
    $id_mensaje_meta = $msg['id'] ?? null;
    $times = $msg['timestamp'] ?? time();
    $perfil = $respuesta_array['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'Usuario';

    $tipo_mensaje = $msg['type'] ?? 'text';
    $mensaje_texto = null;
    $tipo_bd = 'TEXTO';
    $url_archivo = null;
    $mime_type = null;

    if ($tipo_mensaje === 'text') {
        $mensaje_texto = $msg['text']['body'] ?? '';
    } else if ($tipo_mensaje === 'image') {
        $tipo_bd = 'IMAGEN';
        $mensaje_texto = $msg['image']['caption'] ?? 'Imagen recibida';
        $url_archivo = $msg['image']['id']; 
        $mime_type = $msg['image']['mime_type'] ?? 'image/jpeg';
    } else if ($tipo_mensaje === 'document') {
        $tipo_bd = 'DOCUMENTO';
        $mensaje_texto = $msg['document']['caption'] ?? $msg['document']['filename'] ?? 'Documento recibido';
        $url_archivo = $msg['document']['id']; 
        $mime_type = $msg['document']['mime_type'] ?? 'application/pdf';
    } else if ($tipo_mensaje === 'audio') {
        $tipo_bd = 'AUDIO'; 
        $mensaje_texto = 'Audio recibido';
        $url_archivo = $msg['audio']['id']; 
        $mime_type = $msg['audio']['mime_type'] ?? 'audio/ogg';
    } else if ($tipo_mensaje === 'interactive') {
        $tipo_interactivo = $msg['interactive']['type'] ?? '';
        if ($tipo_interactivo === 'button_reply') {
            $mensaje_texto = $msg['interactive']['button_reply']['title'] ?? '';
        } else if ($tipo_interactivo === 'list_reply') {
            $mensaje_texto = $msg['interactive']['list_reply']['title'] ?? '';
        }
    } else if ($tipo_mensaje === 'sticker') {
        $tipo_bd = 'IMAGEN';
        $mensaje_texto = 'Sticker recibido';
        $url_archivo = $msg['sticker']['id']; 
        $mime_type = $msg['sticker']['mime_type'] ?? 'image/webp';
    } else if (in_array($tipo_mensaje, ['location', 'reaction'])) {
        $tipo_bd = 'EVENTO_SISTEMA';
        if ($tipo_mensaje === 'reaction') {
            $emoji = $msg['reaction']['emoji'] ?? '';
            $mensaje_texto = "El usuario reaccionó con: $emoji";
        } else if ($tipo_mensaje === 'location') {
            $mensaje_texto = "El usuario envió una ubicación.";
        }
    } else {
        $tipo_bd = 'EVENTO_SISTEMA';
        $mensaje_texto = "Formato de mensaje no soportado recibido ($tipo_mensaje).";
    }

    //SI HAY UN MENSAJE
    if($telefonoCliente != null){
        save_mensaje($con, $id_mensaje_meta, $telefonoCliente, $times, $mensaje_texto, $perfil, $telefonoReceptorID, $displayPhoneNumber, $tipo_bd, $url_archivo, $mime_type);
    }
}

/**
 * Guardar mensaje recibido y gestionar conversación
 */
function save_mensaje($con, $id_mensaje_meta, $telefono_cliente, $timestamp, $cuerpo_mensaje, $perfil, $telefono_receptor_id, $display_phone, $tipo_bd = 'TEXTO', $url_archivo = null, $mime_type = null) {
    
    if(!$con) {
        error_log("Error de conexión a BD en webhook");
        return;
    }
    
    // Escapar datos para evitar SQL injection
    $telefono_cliente = mysqli_real_escape_string($con, $telefono_cliente);
    $cuerpo_mensaje = mysqli_real_escape_string($con, $cuerpo_mensaje);
    $perfil = mysqli_real_escape_string($con, $perfil);
    $telefono_receptor_id = mysqli_real_escape_string($con, $telefono_receptor_id);
    
    // 1. BUSCAR LA LINEA CORRESPONDIENTE AL NÚMERO RECEPTOR (STARFI CODE / SUPERFORMICA / ETC)
    $id_linea = null;
    $id_empresa = 1; // Default
    $id_sede = null;
    
    if ($telefono_receptor_id) {
        $query_api = "SELECT l.id, s.id_empresa, l.id_sede 
                      FROM lineas_whatsapp l 
                      LEFT JOIN sedes s ON l.id_sede = s.id 
                      WHERE (l.meta_app_id = '$telefono_receptor_id' OR l.meta_telefono_id = '$telefono_receptor_id' OR l.numero_telefono LIKE '%$telefono_receptor_id%')
                        AND (l.estado = 'ACTIVO' OR l.estado_conexion = 'CONECTADO') 
                      LIMIT 1";
        $result_api = mysqli_query($con, $query_api);
        if ($result_api && mysqli_num_rows($result_api) > 0) {
            $row = mysqli_fetch_assoc($result_api);
            $id_linea = $row['id'];
            if ($row['id_empresa']) $id_empresa = $row['id_empresa'];
            if ($row['id_sede']) $id_sede = $row['id_sede'];
        }
    }
    
    // Si no se encuentra la coincidencia específica, abortamos para evitar mensajes cruzados
    if (!$id_linea) {
        error_log("Línea receptora no encontrada en BD. Telefono receptor Meta ID: " . $telefono_receptor_id);
        http_response_code(200);
        return;
    }
    
    // 2. BUSCAR O CREAR CLIENTE
    $id_cliente = null;
    $nombre_db = null;
    $query_cliente = "SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = '$telefono_cliente'";
    $res_cliente = mysqli_query($con, $query_cliente);
    if ($res_cliente && mysqli_num_rows($res_cliente) > 0) {
        $row_cliente = mysqli_fetch_assoc($res_cliente);
        $id_cliente = $row_cliente['id'];
        $nombre_db = $row_cliente['nombre'];
    } else {
        $sede_val = $id_sede ? $id_sede : 'NULL';
        $insert_cliente = "INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre) VALUES ($id_empresa, $sede_val, '$telefono_cliente', NULL)";
        if (mysqli_query($con, $insert_cliente)) {
            $id_cliente = mysqli_insert_id($con);
        }
    }
    
    if (!$id_cliente) {
        error_log("No se pudo obtener ni crear el cliente en el webhook.");
        return;
    }
    
    // VERIFICAR SI ES UNA RESPUESTA CSAT (1 al 5) PARA UN CHAT RECIENTEMENTE CERRADO
    $is_csat = false;
    $csat_value = 0;
    if ($tipo_bd === 'TEXTO' && preg_match('/^[1-5]$/', trim($cuerpo_mensaje))) {
        $csat_value = intval(trim($cuerpo_mensaje));
    } else if (isset($GLOBALS['tipo_interactivo']) && $GLOBALS['tipo_interactivo'] === 'button_reply') {
        // En caso de que la plantilla use botones numéricos (1, 2, 3, 4, 5)
        if (preg_match('/^[1-5]$/', trim($cuerpo_mensaje))) {
            $csat_value = intval(trim($cuerpo_mensaje));
        }
    }

    if ($csat_value > 0) {
        // Buscar la última conversación cerrada de este cliente
        $q_last_closed = mysqli_query($con, "SELECT id, csat_score FROM conversaciones WHERE id_cliente = $id_cliente AND estado = 'CERRADO' ORDER BY id DESC LIMIT 1");
        if ($q_last_closed && mysqli_num_rows($q_last_closed) > 0) {
            $row_last = mysqli_fetch_assoc($q_last_closed);
            if (empty($row_last['csat_score'])) {
                // Es una respuesta a la encuesta CSAT!
                mysqli_query($con, "UPDATE conversaciones SET csat_score = $csat_value WHERE id = " . $row_last['id']);
                mysqli_query($con, "INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES (" . $row_last['id'] . ", 'EVENTO_SISTEMA', 'El cliente calificó la atención con $csat_value estrellas (CSAT).')");
                
                // Enviar mensaje de agradecimiento
                if ($id_linea) {
                    $q_t = mysqli_query($con, "SELECT meta_app_id, meta_token FROM lineas_whatsapp WHERE id = $id_linea");
                    if ($q_t && mysqli_num_rows($q_t) > 0) {
                        $l_info = mysqli_fetch_assoc($q_t);
                        enviar_mensaje_texto_api($con, $l_info, $telefono_cliente, "¡Gracias por tu calificación! Nos ayuda a mejorar.", $row_last['id']);
                        marcar_como_leido_api($l_info, $id_mensaje_meta);
                    }
                }
                return; // Cortamos el flujo aquí para que no abra un nuevo chat
            }
        }
    }

    // 3. BUSCAR O CREAR CONVERSACION
    $id_conversacion = null;
    $estado_conv = null;
    $nueva_conversacion = false;
    $query_conv = "SELECT id, estado FROM conversaciones WHERE id_cliente = $id_cliente AND id_linea = $id_linea AND estado NOT IN ('CERRADO', 'RESUELTO') LIMIT 1";
    $res_conv = mysqli_query($con, $query_conv);
    if ($res_conv && mysqli_num_rows($res_conv) > 0) {
        $row_conv = mysqli_fetch_assoc($res_conv);
        $id_conversacion = $row_conv['id'];
        $estado_conv = $row_conv['estado'];
        // Incrementar mensajes no leídos
        mysqli_query($con, "UPDATE conversaciones SET mensajes_no_leidos = IFNULL(mensajes_no_leidos, 0) + 1 WHERE id = $id_conversacion");
    } else {
        $estado_inicial = (!empty($nombre_db)) ? 'ESPERA_ASIGNACION' : 'BOT_RECOPILANDO';
        $insert_conv = "INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos) VALUES ($id_linea, $id_cliente, '$estado_inicial', 1)";
        if (mysqli_query($con, $insert_conv)) {
            $id_conversacion = mysqli_insert_id($con);
            $estado_conv = $estado_inicial;
            $nueva_conversacion = true;
        }
    }
    
    if (!$id_conversacion) {
        error_log("No se pudo obtener ni crear la conversación en el webhook.");
        return;
    }

    /* LÓGICA DE BOT_RECOPILANDO OBSOLETA
    if (!$nueva_conversacion && $estado_conv === 'BOT_RECOPILANDO' && $tipo_bd === 'TEXTO') {
        // Extraer y limpiar el nombre ingresado
        $nombre_ingresado = extract_clean_name($cuerpo_mensaje, $perfil);
        $nombre_esc = mysqli_real_escape_string($con, $nombre_ingresado);
        mysqli_query($con, "UPDATE clientes_contactos SET nombre = '$nombre_esc' WHERE id = $id_cliente");
        $nombre_db = $nombre_ingresado;
        
        // Pasar estado a ESPERA_ASIGNACION
        mysqli_query($con, "UPDATE conversaciones SET estado = 'ESPERA_ASIGNACION' WHERE id = $id_conversacion");
        $estado_conv = 'ESPERA_ASIGNACION';
        $nueva_conversacion = true; // Forzamos el envío del saludo y operadores
    }
    */
    
    // 4. INSERTAR MENSAJE RECIBIDO
    $url_archivo_esc = $url_archivo ? "'" . mysqli_real_escape_string($con, $url_archivo) . "'" : "NULL";
    $mime_type_esc = $mime_type ? "'" . mysqli_real_escape_string($con, $mime_type) . "'" : "NULL";
    $id_msg_meta_esc = $id_mensaje_meta ? "'" . mysqli_real_escape_string($con, $id_mensaje_meta) . "'" : "NULL";
    
    $query_msg = "INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, tipo, origen, contenido, url_archivo, mime_type) 
                  VALUES ($id_conversacion, $id_msg_meta_esc, '$tipo_bd', 'CLIENTE', '$cuerpo_mensaje', $url_archivo_esc, $mime_type_esc)";
    if (!mysqli_query($con, $query_msg)) {
        error_log("Error al guardar mensaje en la bd: " . mysqli_error($con));
    }
    
    // 5. ENVIAR RESPUESTA AUTOMÁTICA Y CONTACTOS
    if ($id_linea) {
        $q_token = mysqli_query($con, "SELECT l.meta_app_id, l.meta_token, l.id_sede, s.bot_activo FROM lineas_whatsapp l LEFT JOIN sedes s ON l.id_sede = s.id WHERE l.id = $id_linea AND l.estado = 'ACTIVO'");
        if($q_token && mysqli_num_rows($q_token) > 0) {
            $linea_info = mysqli_fetch_assoc($q_token);
            
            // Marcar el mensaje entrante como leído (doble check azul)
            marcar_como_leido_api($linea_info, $id_mensaje_meta);
            
            // ====== LÓGICA DE ROBOT / BOT DE SEDE ======
            // Verificar si el número remitente pertenece a un operador/usuario registrado en la plataforma
            $es_operador = false;
            $tel_digits = preg_replace('/[^0-9]/', '', $telefono_cliente);
            if (!empty($tel_digits)) {
                $q_ops = mysqli_query($con, "SELECT telefono FROM usuario_perfil WHERE telefono IS NOT NULL AND telefono != '' AND telefono != '-'");
                if ($q_ops && mysqli_num_rows($q_ops) > 0) {
                    while ($row_op = mysqli_fetch_assoc($q_ops)) {
                        $op_tel_digits = preg_replace('/[^0-9]/', '', $row_op['telefono']);
                        if (!empty($op_tel_digits) && (strpos($tel_digits, $op_tel_digits) !== false || strpos($op_tel_digits, $tel_digits) !== false)) {
                            $es_operador = true;
                            break;
                        }
                    }
                }
            }

            // ====== LÓGICA DE ROBOT / BOT DE SEDE Y ATENCIÓN IA ======
            $bot_activo = (isset($linea_info['bot_activo']) && intval($linea_info['bot_activo']) === 1);
            $id_sede = intval($linea_info['id_sede']);
            $notificar_admin = false;

            // Verificar si el chat ya fue asignado a un agente humano en el CRM
            $q_agente_check = mysqli_query($con, "SELECT id_agente, estado FROM conversaciones WHERE id = $id_conversacion");
            $id_agente_actual = 0;
            if ($q_agente_check && $row_ag = mysqli_fetch_assoc($q_agente_check)) {
                $id_agente_actual = intval($row_ag['id_agente'] ?? 0);
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
                    mysqli_query($con, "UPDATE conversaciones SET estado = 'ESPERA_ASIGNACION' WHERE id = $id_conversacion");
                    mysqli_query($con, "INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES ($id_conversacion, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', 'El cliente solicitó atención por un agente humano (Handover).')");
                    
                    enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, "Te estoy transfiriendo con un asesor de nuestra tienda. En un momento serás atendido por nuestro equipo.", $id_conversacion);
                    enviar_contactos_asesores($linea_info['meta_app_id'], $linea_info['meta_token'], $telefono_cliente, $id_sede, $con, $id_conversacion);
                    $bot_respondio = true;
                    $notificar_admin = true; // Notificar al admin solo cuando hay transferencia a humano
                }

                // 2. RESPUESTA DEL ASISTENTE VIRTUAL IA
                if (!$bot_respondio) {
                    require_once __DIR__ . '/core/IaContextEngine.php';
                    require_once __DIR__ . '/core/IaConnector.php';

                    // Cargar configuración de la sede o fallback a la activa
                    $q_ia_config = mysqli_query($con, "SELECT * FROM configuraciones_ia WHERE (id_sede = $id_sede OR id_sede = 0 OR id_sede IS NULL) AND estado_ia = 'ACTIVO' ORDER BY (id_sede = $id_sede) DESC LIMIT 1");
                    if (!$q_ia_config || mysqli_num_rows($q_ia_config) == 0) {
                        $q_ia_config = mysqli_query($con, "SELECT * FROM configuraciones_ia WHERE estado_ia = 'ACTIVO' LIMIT 1");
                    }

                    if ($q_ia_config && mysqli_num_rows($q_ia_config) > 0) {
                        $configIa = mysqli_fetch_assoc($q_ia_config);

                        $contextoJIT = IaContextEngine::obtenerContextoInventario($con, $id_sede, $cuerpo_mensaje);
                        $historial = IaConnector::recuperarHistorialMensajes($con, $id_conversacion, 12);
                        $respuestaIa = IaConnector::generarRespuesta($configIa, $historial, $cuerpo_mensaje, $contextoJIT);

                        if (!empty($respuestaIa)) {
                            if (strpos($respuestaIa, '[SOLICITAR_AGENTE_HUMANO]') !== false) {
                                $respuestaLimpia = trim(str_replace('[SOLICITAR_AGENTE_HUMANO]', '', $respuestaIa));
                                mysqli_query($con, "UPDATE conversaciones SET estado = 'ESPERA_ASIGNACION' WHERE id = $id_conversacion");
                                mysqli_query($con, "INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES ($id_conversacion, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', 'La IA solicitó transferencia a agente humano.')");
                                
                                if (empty($respuestaLimpia)) {
                                    $respuestaLimpia = "Te estoy transfiriendo con un asesor de nuestra sede para atenderte personalmente.";
                                }
                                enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $respuestaLimpia, $id_conversacion);
                                enviar_contactos_asesores($linea_info['meta_app_id'], $linea_info['meta_token'], $telefono_cliente, $id_sede, $con, $id_conversacion);
                                $notificar_admin = true; // Notificar al admin por transferencia humana
                            } else {
                                enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $respuestaIa, $id_conversacion);
                            }
                            $bot_respondio = true;
                        }
                    }
                }

                // 3. FALLBACK DE RESPUESTAS TRADICIONALES DE LA SEDE (SI LA IA NO RESPONDIÓ)
                if (!$bot_respondio) {
                    $cuerpo_upper = strtoupper(trim($cuerpo_mensaje));
                    $q_match = mysqli_query($con, "SELECT tipo, mensaje FROM bot_respuestas WHERE id_sede = $id_sede AND estado = 'ACTIVO' AND UPPER(disparador) = '$cuerpo_upper' LIMIT 1");
                    if ($q_match && mysqli_num_rows($q_match) > 0) {
                        $row = mysqli_fetch_assoc($q_match);
                        enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $row['mensaje'], $id_conversacion);
                        $bot_respondio = true;
                    }
                }
            } else if (!$es_operador && !$bot_activo) {
                // El bot está DESACTIVADO en la sede: notificar al Administrador para atención manual
                $notificar_admin = true;
            }

            // Enviar plantilla de notificación al Administrador únicamente si el Bot está Inactivo o solicitó Transferencia Humana
            if ($notificar_admin) {
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
    
    if(empty($telefonoID) || empty($token_seguro) || empty($id_mensaje_meta)) {
        return;
    }
    
    $url = 'https://graph.facebook.com/v23.0/' . $telefonoID . '/messages';
    
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'status' => 'read',
        'message_id' => $id_mensaje_meta
    ]);
    
    $header = [
        "Authorization: Bearer " . $token_seguro,
        "Content-Type: application/json"
    ];
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    // Timeout corto para que el webhook no se demore respondiendo a Meta
    curl_setopt($curl, CURLOPT_TIMEOUT, 3); 
    curl_exec($curl);
    curl_close($curl);
}

/**
 * Enviar mensaje de texto automático vía Meta API
 */
function enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $mensaje_texto, $id_conversacion) {
    
    $telefonoID = $linea_info['meta_app_id'];
    $token_seguro = $linea_info['meta_token'];
    
    if(empty($telefonoID) || empty($token_seguro)) {
        return;
    }
    
    // URL para enviar mensaje
    $url = 'https://graph.facebook.com/v23.0/' . $telefonoID . '/messages';
    
    // Configuración del mensaje
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
    
    // Declarar cabeceras
    $header = [
        "Authorization: Bearer " . $token_seguro,
        "Content-Type: application/json"
    ];
    
    // Iniciar CURL
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje_enviar);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    
    // Obtener respuesta
    $response = curl_exec($curl);
    $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    // Guardar mensaje enviado en BD
    if ($status_code == 200) {
        $enviado_esc = mysqli_real_escape_string($con, $mensaje_texto);
        mysqli_query($con, "INSERT INTO mensajes_y_eventos (id_conversacion, tipo, origen, contenido) VALUES ($id_conversacion, 'TEXTO', 'BOT', '$enviado_esc')");
    } else {
        error_log("Error al enviar mensaje automatico: " . $response);
    }
}

/**
 * Enviar contactos de asesores (fallback lista)
 */
function enviar_contactos_asesores($telefonoID, $token_seguro, $telefono_cliente, $id_sede, $con, $id_conversacion) {
    
    // Buscar en la base de datos
    $sede_filter = ($id_sede > 0) ? "AND id_sede = $id_sede" : "AND (id_sede = 0 OR id_sede IS NULL)";
    $q_asesores = mysqli_query($con, "SELECT nombre, telefono FROM bot_vendedores WHERE estado = 'ACTIVO' $sede_filter");
    
    $asesores = [];
    if ($q_asesores && mysqli_num_rows($q_asesores) > 0) {
        while ($row = mysqli_fetch_assoc($q_asesores)) {
            $asesores[] = $row;
        }
    }
    
    // Si la sede no posee asesores registrados en la base de datos, NO enviar ninguno
    if (empty($asesores)) {
        return;
    }
    
    $url = 'https://graph.facebook.com/v23.0/' . $telefonoID . '/messages';
    
    foreach ($asesores as $asesor) {
        $mensaje_contacto = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $telefono_cliente,
            'type' => 'contacts',
            'contacts' => [
                [
                    'name' => [
                        'formatted_name' => $asesor['nombre'] . ' SuperFormica',
                        'first_name' => $asesor['nombre'],
                        'last_name' => 'SuperFormica'
                    ],
                    'phones' => [
                        [
                            'phone' => $asesor['telefono'],
                            'type' => 'CELL',
                            'wa_id' => preg_replace('/[^0-9]/', '', $asesor['telefono'])
                        ]
                    ]
                ]
            ]
        ]);
        
        $header = [
            "Authorization: Bearer " . $token_seguro,
            "Content-Type: application/json"
        ];
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje_contacto);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_exec($curl);
        curl_close($curl);
        
        // Registrar en CRM que se enviaron los contactos
        $cont_str = mysqli_real_escape_string($con, "Contacto enviado: " . $asesor['nombre'] . " (" . $asesor['telefono'] . ")");
        mysqli_query($con, "INSERT INTO mensajes_y_eventos (id_conversacion, tipo, origen, contenido) VALUES ($id_conversacion, 'TEXTO', 'BOT', '$cont_str')");
        
        // Pequeña pausa entre envíos
        usleep(500000); // 0.5 segundos
    }
}

/**
 * Extraer y limpiar el nombre de un mensaje conversacional
 */
function extract_clean_name($text, $profile_fallback = 'Usuario') {
    // 1. Dividir por el primer punto, salto de línea o coma para tomar solo la primera parte
    $parts = preg_split('/[\.\n,]/u', $text);
    $clean = trim($parts[0]);
    
    // 2. Limpiar frases típicas introductorias (insensible a mayúsculas/minúsculas)
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
    
    // 3. Descartar si contiene palabras típicas de mensajes o preguntas
    $invalid_words = ['precio', 'cuanto', 'cuesta', 'tienen', 'donde', 'ubicados', 'hola', 'buen', 'dias', 'tardes', 'noches', 'pregunta', 'lamina', 'pvc', 'fondo', 'blanco', 'disp', '?', '¿', '!', '¡', 'gracias', 'catalogo'];
    foreach ($invalid_words as $word) {
        if (mb_strpos($clean_lower, $word) !== false) {
            return $profile_fallback;
        }
    }
    
    // 4. Validación de longitud razonable (2 a 40 caracteres)
    if (mb_strlen($clean) < 2 || mb_strlen($clean) > 40) {
        return $profile_fallback;
    }
    
    // 5. Convertir a Capitalización Tipo Título (Ej: "Juan Pérez")
    return mb_convert_case($clean, MB_CASE_TITLE, "UTF-8");
}

/**
 * Envía la encuesta CSAT y cierra la conversación
 */
function enviar_csat_y_cerrar_api($con, $linea_info, $telefono_cliente, $id_conversacion) {
    $telefonoID = $linea_info['meta_app_id'] ?? null;
    $token_seguro = $linea_info['meta_token'] ?? null;
    
    if(empty($telefonoID) || empty($token_seguro)) {
        return;
    }
    
    // 1. Enviar la plantilla starfi_csat_survey
    $url = 'https://graph.facebook.com/v23.0/' . $telefonoID . '/messages';
    
    $payload = json_encode([
        'messaging_product' => 'whatsapp',
        'to' => $telefono_cliente,
        'type' => 'template',
        'template' => [
            'name' => 'starfi_csat_survey',
            'language' => [
                'code' => 'es'
            ]
        ]
    ]);
    
    $ch_csat = curl_init($url);
    curl_setopt($ch_csat, CURLOPT_POST, 1);
    curl_setopt($ch_csat, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch_csat, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token_seguro, 'Content-Type: application/json']);
    curl_setopt($ch_csat, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch_csat);
    curl_close($ch_csat);
    
    // 2. Cerrar la conversación
    mysqli_query($con, "UPDATE conversaciones SET estado = 'CERRADO' WHERE id = $id_conversacion");
    
    // 3. Registrar en eventos
    mysqli_query($con, "INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES ($id_conversacion, 'EVENTO_SISTEMA', 'Encuesta CSAT enviada y conversación cerrada por el BOT.')");
}

/**
 * Enviar mensaje de imagen vía Meta API
 */
function enviar_mensaje_imagen_api($con, $linea_info, $telefono_cliente, $image_url, $caption, $id_conversacion) {
    $telefonoID = $linea_info['meta_app_id'];
    $token_seguro = $linea_info['meta_token'];
    
    if(empty($telefonoID) || empty($token_seguro)) return;
    
    $url = 'https://graph.facebook.com/v23.0/' . $telefonoID . '/messages';
    
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
        "Authorization: Bearer " . $token_seguro,
        "Content-Type: application/json"
    ];
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje_enviar);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    curl_close($curl);
    
    $q_guardar = "INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido, url_archivo) VALUES ($id_conversacion, 'BOT', 'IMAGEN', '" . mysqli_real_escape_string($con, $caption) . "', '" . mysqli_real_escape_string($con, $image_url) . "')";
    mysqli_query($con, $q_guardar);
}

/**
 * Enviar mensaje de ubicación vía Meta API
 */
function enviar_mensaje_ubicacion_api($con, $linea_info, $telefono_cliente, $latitud, $longitud, $nombre_lugar, $id_conversacion) {
    $telefonoID = $linea_info['meta_app_id'];
    $token_seguro = $linea_info['meta_token'];
    
    if(empty($telefonoID) || empty($token_seguro)) return;
    
    $url = 'https://graph.facebook.com/v23.0/' . $telefonoID . '/messages';
    
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
        "Authorization: Bearer " . $token_seguro,
        "Content-Type: application/json"
    ];
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje_enviar);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($curl);
    curl_close($curl);
    
    $q_guardar = "INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES ($id_conversacion, 'BOT', 'UBICACION', '" . mysqli_real_escape_string($con, $nombre_lugar . " (Lat: $latitud, Lng: $longitud)") . "')";
    mysqli_query($con, $q_guardar);
}
/**
 * Envia lista de asesores de la sede (como texto formateado con sus números)
 */
function enviar_mensaje_contactos_sede_api($con, $linea_info, $telefono_cliente, $id_conversacion, $id_sede, $mensaje_inicial) {
    // Buscar agentes en esa sede
    // Asumiremos que el teléfono está en usuario_perfil.telefono o que se puede mapear.
    // Si no sabemos la estructura exacta, hacemos un SHOW COLUMNS temporal para armar la query si es necesario, 
    // pero intentaremos buscar up.telefono o up.celular
    
    // Primero, verificamos si 'telefono' existe en usuario_perfil
    $col_check = mysqli_query($con, "SHOW COLUMNS FROM usuario_perfil LIKE 'telefono'");
    $campo_tel = (mysqli_num_rows($col_check) > 0) ? 'up.telefono' : 'up.celular';
    
    // Si no existe celular tampoco, no pasa nada, fallará limpio en la query
    $q_asesores = mysqli_query($con, "
        SELECT up.nombre, $campo_tel as telefono, r.nombre as rol
        FROM usuario u 
        JOIN usuario_perfil up ON u.id = up.id_usuario 
        LEFT JOIN roles r ON u.rol = r.id 
        WHERE u.id_sede = $id_sede AND u.estado = 'ACTIVO' AND r.nombre IN ('ASESOR', 'AGENTE', 'VENDEDOR')
    ");
    
    $lista_contactos = $mensaje_inicial . "\n\n";
    $hay_contactos = false;
    
    if ($q_asesores && mysqli_num_rows($q_asesores) > 0) {
        while ($row = mysqli_fetch_assoc($q_asesores)) {
            if (!empty($row['telefono'])) {
                $lista_contactos .= "👤 *" . $row['nombre'] . "*\n";
                $lista_contactos .= "📱 Wa.me/" . preg_replace('/[^0-9]/', '', $row['telefono']) . "\n\n";
                $hay_contactos = true;
            }
        }
    }
    
    if (!$hay_contactos) {
        $lista_contactos = "Lo sentimos, no hay asesores disponibles registrados para esta sede en este momento.";
    }
    
    enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $lista_contactos, $id_conversacion);
}

/**
 * Enviar plantilla de notificación interna (starfi_notificacion_interna) ÚNICAMENTE al Administrador cuando un cliente escribe
 */
function enviar_notificacion_interna_administrador($con, $id_sede, $id_conversacion, $nombre_cliente, $numero_cliente) {

    if ($id_sede <= 0) return;
    
    // 1. Obtener id_empresa de la sede
    $id_empresa = 0;
    $qSedeInfo = mysqli_query($con, "SELECT id_empresa FROM sedes WHERE id = $id_sede LIMIT 1");
    if ($qSedeInfo && $rowSede = mysqli_fetch_assoc($qSedeInfo)) {
        $id_empresa = intval($rowSede['id_empresa']);
    }

    // 2. Obtener línea de WhatsApp activa EXCLUSIVAMENTE para la sede
    $qLinea = mysqli_query($con, "SELECT meta_token, meta_app_id FROM lineas_whatsapp WHERE id_sede = $id_sede AND estado = 'ACTIVO' LIMIT 1");
    if (!$qLinea || mysqli_num_rows($qLinea) == 0) return;
    
    $rowLinea = mysqli_fetch_assoc($qLinea);
    $meta_token = $rowLinea['meta_token'];
    $phone_number_id = $rowLinea['meta_app_id'];
    if (empty($meta_token) || empty($phone_number_id)) return;

    // 3. Colección de números de destino ÚNICAMENTE para Administradores/Gerentes de ESTA SEDE o EMPRESA
    $telefonos_destinatarios = [];
    
    // Primero: buscar administradores asignados específicamente a ESTA SEDE
    $qAdmin = mysqli_query($con, "
        SELECT DISTINCT up.telefono 
        FROM usuario u 
        JOIN usuario_perfil up ON u.id = up.id_usuario 
        LEFT JOIN roles r ON u.rol = r.id 
        WHERE u.estado = 'ACTIVO' 
          AND u.id_sede = $id_sede
          AND (r.nombre IN ('MASTER', 'ADMINISTRADOR', 'ADMIN', 'GERENTE') OR u.rol IN ('MASTER', 'ADMINISTRADOR', 'ADMIN', 'GERENTE'))
    ");

    if ($qAdmin && mysqli_num_rows($qAdmin) > 0) {
        while ($rowAd = mysqli_fetch_assoc($qAdmin)) {
            $tel = preg_replace('/[^0-9]/', '', $rowAd['telefono'] ?? '');
            if (!empty($tel) && !in_array($tel, $telefonos_destinatarios)) {
                $telefonos_destinatarios[] = $tel;
            }
        }
    }

    // Segundo: si no hay admin en esa sede específica, buscar administradores asignados a la MISMA EMPRESA (id_empresa)
    if (empty($telefonos_destinatarios) && $id_empresa > 0) {
        $qAdminEmpresa = mysqli_query($con, "
            SELECT DISTINCT up.telefono 
            FROM usuario u 
            JOIN usuario_perfil up ON u.id = up.id_usuario 
            LEFT JOIN roles r ON u.rol = r.id 
            WHERE u.estado = 'ACTIVO' 
              AND u.id_empresa = $id_empresa
              AND (r.nombre IN ('MASTER', 'ADMINISTRADOR', 'ADMIN') OR u.rol IN ('MASTER', 'ADMINISTRADOR', 'ADMIN'))
        ");
        if ($qAdminEmpresa) {
            while ($rowAd = mysqli_fetch_assoc($qAdminEmpresa)) {
                $tel = preg_replace('/[^0-9]/', '', $rowAd['telefono'] ?? '');
                if (!empty($tel) && !in_array($tel, $telefonos_destinatarios)) {
                    $telefonos_destinatarios[] = $tel;
                }
            }
        }
    }
    
    if (empty($telefonos_destinatarios)) return;
    
    // 3. Generar mensaje de plantilla: "Tienes un mensaje por responder de [Cliente]"
    $cliente_nombre = (!empty($nombre_cliente) && strtolower($nombre_cliente) !== 'cliente') ? "$nombre_cliente ($numero_cliente)" : $numero_cliente;
    $texto_notificacion = "Tienes un mensaje por responder de $cliente_nombre";
    
    // 4. Enviar plantilla 'starfi_notificacion_interna' vía API de Meta únicamente a Administradores
    $msg_url = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
    
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
        curl_close($ch);
    }
}
