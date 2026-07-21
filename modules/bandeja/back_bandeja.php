<?php
// modules/bandeja/back_bandeja.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';
$agente_id = intval($_SESSION['agente_id']);

switch ($action) {
    case 'load_chats':
        $filter = $_POST['filter'] ?? 'mis-chats';
        
        $join_condition = "AND c.estado != 'CERRADO'";
        if ($filter === 'cerrados') {
            $join_condition = "AND c.estado = 'CERRADO'";
        }

        $query = "
            SELECT 
                IFNULL(c.id, 0) as id, 
                cl.id as id_cliente, 
                cl.nombre as cliente_nombre, 
                cl.numero_whatsapp, 
                IFNULL(c.estado, 'SIN INICIAR') as estado, 
                IFNULL(c.fecha_inicio, cl.fecha_registro) as fecha_inicio,
                (SELECT MAX(timestamp) FROM mensajes_y_eventos WHERE id_conversacion = c.id) as ultimo_mensaje_ts,
                IFNULL(c.mensajes_no_leidos, 0) as no_leidos,
                IFNULL(s.nombre_sede, 'Sede Principal') as nombre_sede,
                up.nombre as nombre_asesor,
                IFNULL(cl.calificacion_calidad, 0) as calificacion_calidad
            FROM clientes_contactos cl
            JOIN conversaciones c ON cl.id = c.id_cliente $join_condition
            LEFT JOIN lineas_whatsapp l ON c.id_linea = l.id
            LEFT JOIN sedes s ON l.id_sede = s.id
            LEFT JOIN usuario_perfil up ON c.id_agente = up.id_usuario
            WHERE cl.id_empresa = 1
        ";
        
        if ($filter === 'mis-chats') {
            $query .= " AND c.id_agente = $agente_id";
        } elseif ($filter === 'no-leido') {
            $query .= " AND c.mensajes_no_leidos > 0";
        } elseif ($filter === 'cerrados') {
            $query .= " AND c.estado = 'CERRADO'";
        } elseif ($filter === 'todos') {
            // Todos los chats
        }
        
        $id_sede = isset($_POST['id_sede']) ? intval($_POST['id_sede']) : 0;
        if ($id_sede > 0) {
            $query .= " AND l.id_sede = $id_sede";
        }

        $query .= " ORDER BY IFNULL(ultimo_mensaje_ts, c.fecha_inicio) DESC";
        $res = $con->query($query);
        
        $chats = [];
        if($res){
            while ($row = $res->fetch_assoc()) {
                $chats[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $chats]);
        break;

    case 'load_messages':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        
        $query = "
            SELECT m.id, m.tipo, m.origen, m.contenido, m.timestamp, m.estado_envio, m.url_archivo, m.reply_to_text, m.id_mensaje_meta, up.nombre as nombre_agente 
            FROM mensajes_y_eventos m
            LEFT JOIN usuario_perfil up ON m.id_agente = up.id_usuario
            WHERE m.id_conversacion = ? 
            ORDER BY m.timestamp ASC
        ";
        $stmt = $con->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $conversacion_id);
            $stmt->execute();
            $res = $stmt->get_result();
            
            // Marcar mensajes como leídos
            $con->query("UPDATE conversaciones SET mensajes_no_leidos = 0 WHERE id = $conversacion_id");
            
            $messages = [];
            while ($row = $res->fetch_assoc()) {
                // Formatting contact strings sent by BOT
                if ($row['origen'] == 'BOT' && strpos($row['contenido'], 'Contacto enviado:') === 0) {
                    $row['tipo'] = 'CONTACTO'; // New type to help UI identify
                }
                $messages[] = $row;
            }
            echo json_encode(['status' => 'success', 'data' => $messages]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error preparando consulta.']);
        }
        break;
        
    case 'send_message':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $contenido = trim($_POST['contenido'] ?? '');
        
        if (empty($contenido) || ($conversacion_id <= 0 && $cliente_id <= 0)) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos.']);
            exit;
        }

        $is_new_chat = false;
        // Si no hay conversación activa, la creamos
        if ($conversacion_id <= 0 && $cliente_id > 0) {
            // Buscar la primera línea conectada
            $resLinea = $con->query("SELECT id FROM lineas_whatsapp WHERE estado_conexion = 'CONECTADO' LIMIT 1");
            $id_linea = ($resLinea && $resLinea->num_rows > 0) ? $resLinea->fetch_assoc()['id'] : 1;
            
            $stmt = $con->prepare("INSERT INTO conversaciones (id_linea, id_cliente, id_agente, estado) VALUES (?, ?, ?, 'ATENDIENDO')");
            $stmt->bind_param("iii", $id_linea, $cliente_id, $agente_id);
            $stmt->execute();
            $conversacion_id = $stmt->insert_id;
            $is_new_chat = true;
        } else {
            // Auto-assign to agent if replying to a waiting chat
            $con->query("UPDATE conversaciones SET estado = 'ATENDIENDO', id_agente = $agente_id, fecha_primera_respuesta = IFNULL(fecha_primera_respuesta, NOW()) WHERE id = $conversacion_id AND (id_agente IS NULL OR estado = 'ESPERA_ASIGNACION')");
        }
        
        $is_internal = intval($_POST['is_internal'] ?? 0);
        $id_mensaje_meta = null;
        $origen = 'AGENTE';
        $tipo = 'TEXTO';
        
        if ($is_internal === 1) {
            $origen = 'NOTA_INTERNA';
            // Saltamos todo el envío a la API de WhatsApp, solo se guarda local
        } else {
            // -------------------------------------------------------------
            // INICIO BLOQUE ENVÍO A WHATSAPP CLOUD API
            // -------------------------------------------------------------
            $queryChat = "
                SELECT c.id_cliente, cl.numero_whatsapp, l.meta_token, l.meta_app_id as phone_number_id
                FROM conversaciones c
                JOIN clientes_contactos cl ON c.id_cliente = cl.id
                JOIN lineas_whatsapp l ON c.id_linea = l.id
                WHERE c.id = $conversacion_id
            ";
            $resChat = $con->query($queryChat);
            $chat_data = $resChat ? $resChat->fetch_assoc() : null;

            if ($chat_data && !empty($chat_data['meta_token']) && $chat_data['meta_token'] !== 'temp_token') {
                $numero_destino = preg_replace('/[^0-9]/', '', $chat_data['numero_whatsapp']);
                $meta_token = $chat_data['meta_token'];
                $phone_number_id = $chat_data['phone_number_id'];

                $url = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
                
                $post_data = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $numero_destino,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $contenido
                    ]
                ];
                
                $reply_meta_id = $_POST['reply_to_meta_id'] ?? '';
                if (!empty($reply_meta_id)) {
                    $post_data['context'] = [
                        'message_id' => $reply_meta_id
                    ];
                }

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $meta_token,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout rápido para no bloquear
                $response = curl_exec($ch);
                curl_close($ch);
                
                $res_meta = json_decode($response, true);
                if (isset($res_meta['messages'][0]['id'])) {
                    $id_mensaje_meta = $res_meta['messages'][0]['id'];
                }
            }
            // -------------------------------------------------------------
            // FIN BLOQUE ENVÍO
            // -------------------------------------------------------------
        }
        
        $reply_to_meta_id = $_POST['reply_to_meta_id'] ?? null;
        $reply_to_text = $_POST['reply_to_text'] ?? null;
        if(empty($reply_to_meta_id)) $reply_to_meta_id = null;
        if(empty($reply_to_text)) $reply_to_text = null;
        
        $query = "INSERT INTO mensajes_y_eventos (id_conversacion, tipo, origen, id_agente, contenido, id_mensaje_meta, reply_to_meta_id, reply_to_text) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $con->prepare($query);
        if ($stmt) {
            $stmt->bind_param("ississss", $conversacion_id, $tipo, $origen, $agente_id, $contenido, $id_mensaje_meta, $reply_to_meta_id, $reply_to_text);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message_id' => $stmt->insert_id, 'new_chat_id' => ($is_new_chat ? $conversacion_id : null)]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Fallo al guardar mensaje en BD.']);
            }
        }
        break;

    case 'upload_media':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'Error al subir archivo.']);
            exit;
        }
        
        $file = $_FILES['file'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $mime = @mime_content_type($file['tmp_name']);
        
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4'];
        if (!$mime || !in_array($mime, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Tipo de archivo no permitido.']);
            exit;
        }

        $filename = uniqid('media_') . '.' . $ext;
        $dest_path = __DIR__ . '/../../assets/uploads/' . $filename;
        
        if (@move_uploaded_file($file['tmp_name'], $dest_path)) {
            $url_archivo = '/starfi_crm/assets/uploads/' . $filename;
            $tipo_bd = (strpos($mime, 'image') !== false) ? 'IMAGEN' : 'DOCUMENTO';
            $contenido = $file['name'];
            
            $is_new_chat = false;
            // Si no hay conversación activa, la creamos (Misma lógica que enviar mensaje)
            if ($conversacion_id <= 0 && $cliente_id > 0) {
                $resLinea = $con->query("SELECT id FROM lineas_whatsapp WHERE estado_conexion = 'CONECTADO' LIMIT 1");
                $id_linea = ($resLinea && $resLinea->num_rows > 0) ? $resLinea->fetch_assoc()['id'] : 1;
                $stmt = $con->prepare("INSERT INTO conversaciones (id_linea, id_cliente, id_agente, estado) VALUES (?, ?, ?, 'ATENDIENDO')");
                $stmt->bind_param("iii", $id_linea, $cliente_id, $agente_id);
                $stmt->execute();
                $conversacion_id = $stmt->insert_id;
                $is_new_chat = true;
            } else {
                $con->query("UPDATE conversaciones SET estado = 'ATENDIENDO', id_agente = $agente_id, fecha_primera_respuesta = IFNULL(fecha_primera_respuesta, NOW()) WHERE id = $conversacion_id AND (id_agente IS NULL OR estado = 'ESPERA_ASIGNACION')");
            }
            
            // -------------------------------------------------------------
            // INICIO BLOQUE ENVÍO A WHATSAPP CLOUD API (MULTIMEDIA)
            // -------------------------------------------------------------
            $queryChat = "
                SELECT c.id_cliente, cl.numero_whatsapp, l.meta_token, l.meta_app_id as phone_number_id
                FROM conversaciones c
                JOIN clientes_contactos cl ON c.id_cliente = cl.id
                JOIN lineas_whatsapp l ON c.id_linea = l.id
                WHERE c.id = $conversacion_id
            ";
            $resChat = $con->query($queryChat);
            $chat_data = $resChat ? $resChat->fetch_assoc() : null;

            if ($chat_data && !empty($chat_data['meta_token']) && $chat_data['meta_token'] !== 'temp_token') {
                $numero_destino = preg_replace('/[^0-9]/', '', $chat_data['numero_whatsapp']);
                $meta_token = $chat_data['meta_token'];
                $phone_number_id = $chat_data['phone_number_id'];

                // 1. Subir archivo a los servidores de Meta para obtener un Media ID
                $media_url = "https://graph.facebook.com/v19.0/{$phone_number_id}/media";
                $cfile = new CURLFile($dest_path, $mime, $filename);
                $post_media = [
                    'messaging_product' => 'whatsapp',
                    'file' => $cfile
                ];

                $ch = curl_init($media_url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_media);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $meta_token
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $media_response = curl_exec($ch);
                curl_close($ch);
                
                $media_data = json_decode($media_response, true);
                
                // 2. Si la subida fue exitosa, enviar el mensaje con el Media ID
                if (isset($media_data['id'])) {
                    $media_id = $media_data['id'];
                    $msg_url = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
                    
                    $meta_type = 'document';
                    if (strpos($mime, 'image') !== false) {
                        $meta_type = 'image';
                        if ($mime === 'image/webp') {
                            $meta_type = 'sticker';
                        }
                    }
                    
                    $post_msg = [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $numero_destino,
                        'type' => $meta_type,
                        $meta_type => [
                            'id' => $media_id
                        ]
                    ];

                    $ch2 = curl_init($msg_url);
                    curl_setopt($ch2, CURLOPT_POST, 1);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($post_msg));
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $meta_token,
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    $response2 = curl_exec($ch2);
                    curl_close($ch2);
                    
                    $res_meta2 = json_decode($response2, true);
                    if (isset($res_meta2['messages'][0]['id'])) {
                        $id_mensaje_meta = $res_meta2['messages'][0]['id'];
                    }
                }
            }
            // -------------------------------------------------------------
            // FIN BLOQUE ENVÍO A WHATSAPP
            // -------------------------------------------------------------
            // Guardar en BD
            $reply_to_meta_id = $_POST['reply_to_meta_id'] ?? null;
            $reply_to_text = $_POST['reply_to_text'] ?? null;
            
            $stmt_msg = $con->prepare("
                INSERT INTO mensajes_y_eventos (
                    id_conversacion, id_mensaje_meta, tipo, origen, 
                    contenido, url_archivo, mime_type, estado_envio, reply_to_meta_id, reply_to_text
                ) VALUES (?, ?, ?, 'AGENTE', ?, ?, ?, 'ENVIADO', ?, ?)
            ");
            $stmt_msg->bind_param("isssssss", $conversacion_id, $id_mensaje_meta, $tipo_bd, $contenido, $url_archivo, $mime, $reply_to_meta_id, $reply_to_text);
            if ($stmt_msg->execute()) {
                echo json_encode(['status' => 'success', 'new_chat_id' => ($is_new_chat ? $conversacion_id : null)]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Fallo al guardar en BD.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Fallo al mover el archivo al servidor.']);
        }
        break;

    case 'retry_message':
        $msg_id = intval($_POST['msg_id'] ?? 0);
        if ($msg_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID inválido']);
            exit;
        }
        
        $res = $con->query("SELECT m.*, c.id_cliente, cl.numero_whatsapp, l.meta_token, l.meta_app_id as phone_number_id 
                            FROM mensajes_y_eventos m
                            JOIN conversaciones c ON m.id_conversacion = c.id
                            JOIN clientes_contactos cl ON c.id_cliente = cl.id
                            JOIN lineas_whatsapp l ON c.id_linea = l.id
                            WHERE m.id = $msg_id");
                            
        if (!$res || $res->num_rows == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Mensaje no encontrado']);
            exit;
        }
        
        $msg = $res->fetch_assoc();
        
        if ($msg['origen'] !== 'AGENTE' && $msg['origen'] !== 'API_TRANSACCIONAL') {
             echo json_encode(['status' => 'error', 'message' => 'Solo se pueden reintentar mensajes salientes']);
             exit;
        }
        
        $numero_destino = preg_replace('/[^0-9]/', '', $msg['numero_whatsapp']);
        $meta_token = $msg['meta_token'];
        $phone_number_id = $msg['phone_number_id'];
        
        if (empty($meta_token) || $meta_token === 'temp_token') {
            echo json_encode(['status' => 'error', 'message' => 'Línea de WhatsApp no configurada']);
            exit;
        }
        
        $id_mensaje_meta = null;
        
        if ($msg['tipo'] === 'TEXTO') {
            $url = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
            $post_data = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $numero_destino,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $msg['contenido']
                ]
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $meta_token,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $res_meta = json_decode($response, true);
            if (isset($res_meta['messages'][0]['id'])) {
                $id_mensaje_meta = $res_meta['messages'][0]['id'];
            }
        } else if (($msg['tipo'] === 'IMAGEN' || $msg['tipo'] === 'DOCUMENTO') && !empty($msg['url_archivo'])) {
            // Es multimedia
            $dest_path = __DIR__ . '/../..' . str_replace('/starfi_crm', '', $msg['url_archivo']);
            
            if (file_exists($dest_path)) {
                $mime = $msg['mime_type'];
                $filename = basename($dest_path);
                
                $media_url = "https://graph.facebook.com/v19.0/{$phone_number_id}/media";
                $cfile = new CURLFile($dest_path, $mime, $filename);
                $post_media = ['messaging_product' => 'whatsapp', 'file' => $cfile];
                
                $ch = curl_init($media_url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_media);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $media_response = curl_exec($ch);
                curl_close($ch);
                
                $media_data = json_decode($media_response, true);
                if (isset($media_data['id'])) {
                    $media_id = $media_data['id'];
                    $msg_url = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
                    
                    $meta_type = 'document';
                    if (strpos($mime, 'image') !== false) {
                        $meta_type = 'image';
                        if ($mime === 'image/webp') $meta_type = 'sticker';
                    }
                    
                    $post_msg = [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $numero_destino,
                        'type' => $meta_type,
                        $meta_type => ['id' => $media_id]
                    ];
                    
                    $ch2 = curl_init($msg_url);
                    curl_setopt($ch2, CURLOPT_POST, 1);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($post_msg));
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token, 'Content-Type: application/json']);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    $response2 = curl_exec($ch2);
                    curl_close($ch2);
                    
                    $res_meta2 = json_decode($response2, true);
                    if (isset($res_meta2['messages'][0]['id'])) {
                        $id_mensaje_meta = $res_meta2['messages'][0]['id'];
                    }
                }
            }
        }
        
        if ($id_mensaje_meta) {
            $stmt = $con->prepare("UPDATE mensajes_y_eventos SET estado_envio = 'ENVIADO', id_mensaje_meta = ? WHERE id = ?");
            $stmt->bind_param("si", $id_mensaje_meta, $msg_id);
            $stmt->execute();
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Fallo al reintentar con la API de Meta.']);
        }
        break;

    case 'close_chat':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $motivo = $_POST['motivo_cierre'] ?? 'NO_ESPECIFICADO';
        $calidad = intval($_POST['calificacion_calidad'] ?? 0);
        $observacion = trim($_POST['observacion'] ?? '');
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        
        if($conversacion_id > 0) {
            $update_conv = "UPDATE conversaciones SET estado = 'CERRADO', fecha_resolucion = NOW(), resultado_comercial = ?";
            if ($motivo === 'VENTA_CERRADA') {
                $update_conv .= ", fecha_cierre_venta = NOW()";
            }
            $update_conv .= " WHERE id = ?";
            
            $stmt = $con->prepare($update_conv);
            $stmt->bind_param("si", $motivo, $conversacion_id);
            $stmt->execute();
            
            if ($cliente_id > 0 && $calidad > 0) {
                $stmt_cli = $con->prepare("UPDATE clientes_contactos SET calificacion_calidad = ? WHERE id = ?");
                $stmt_cli->bind_param("ii", $calidad, $cliente_id);
                $stmt_cli->execute();
            }

            $evento_texto = "Conversación cerrada por el operador. Motivo: $motivo | Lead Scoring: $calidad estrellas";
            $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES ($conversacion_id, 'EVENTO_SISTEMA', '$evento_texto')");
            
            if (!empty($observacion)) {
                $agente_id_actual = intval($_SESSION['agente_id']);
                $stmt_obs = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, id_agente, tipo, contenido) VALUES (?, 'NOTA_INTERNA', ?, 'TEXTO', ?)");
                $stmt_obs->bind_param("iis", $conversacion_id, $agente_id_actual, $observacion);
                $stmt_obs->execute();
            }
            
            // AUTOMATIZACIÓN CSAT: Enviar la plantilla starfi_csat_survey al cliente
            $enviar_csat = intval($_POST['enviar_csat'] ?? 1);
            if ($enviar_csat === 1) {
                $queryChat = "
                    SELECT cl.numero_whatsapp, l.meta_token, l.meta_app_id as phone_number_id
                    FROM conversaciones c
                    JOIN clientes_contactos cl ON c.id_cliente = cl.id
                    JOIN lineas_whatsapp l ON c.id_linea = l.id
                    WHERE c.id = $conversacion_id
                ";
                $resChat = $con->query($queryChat);
                if ($resChat && $rowChat = $resChat->fetch_assoc()) {
                    $numero_destino = $rowChat['numero_whatsapp'];
                    $meta_token = $rowChat['meta_token'];
                    $phone_number_id = $rowChat['phone_number_id'];
                    
                    $msg_url = "https://graph.facebook.com/v19.0/{$phone_number_id}/messages";
                    $post_csat = [
                        'messaging_product' => 'whatsapp',
                        'to' => $numero_destino,
                        'type' => 'template',
                        'template' => [
                            'name' => 'starfi_csat_survey',
                            'language' => [
                                'code' => 'es'
                            ]
                        ]
                    ];
                    
                    $ch_csat = curl_init($msg_url);
                    curl_setopt($ch_csat, CURLOPT_POST, 1);
                    curl_setopt($ch_csat, CURLOPT_POSTFIELDS, json_encode($post_csat));
                    curl_setopt($ch_csat, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token, 'Content-Type: application/json']);
                    curl_setopt($ch_csat, CURLOPT_RETURNTRANSFER, true);
                    $res_csat_json = curl_exec($ch_csat);
                    curl_close($ch_csat);
                    
                    $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES ($conversacion_id, 'EVENTO_SISTEMA', 'Encuesta CSAT (starfi_csat_survey) enviada al cliente automáticamente.')");
                }
            }
            
            echo json_encode(['status' => 'success']);
        }
        break;

    case 'reassign_chat':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $nuevo_agente_id = intval($_POST['nuevo_agente_id'] ?? 0);
        if($conversacion_id > 0 && $nuevo_agente_id > 0) {
            $con->query("UPDATE conversaciones SET id_agente = $nuevo_agente_id, estado = 'ESPERA_ASIGNACION' WHERE id = $conversacion_id");
            
            $res = $con->query("SELECT COALESCE(up.nombre, u.usuario) AS nombre_completo FROM usuario u LEFT JOIN usuario_perfil up ON u.id = up.id_usuario WHERE u.id = $nuevo_agente_id");
            $nombre_agente = $res->fetch_assoc()['nombre_completo'];

            $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES ($conversacion_id, 'EVENTO_SISTEMA', 'Conversación reasignada a $nombre_agente')");
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Datos inválidos']);
        }
        break;

    case 'load_profile':
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        if($cliente_id > 0) {
            $res = $con->query("SELECT * FROM clientes_contactos WHERE id = $cliente_id");
            if($row = $res->fetch_assoc()) {
                echo json_encode(['status' => 'success', 'data' => $row]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado']);
            }
        }
        break;

    case 'get_agents':
        $res = $con->query("
            SELECT u.id, COALESCE(up.nombre, u.usuario) AS nombre_completo 
            FROM usuario u 
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario 
            WHERE u.estado = 'ACTIVO' OR u.estado = 1
        ");
        $agents = [];
        if ($res) {
            while($row = $res->fetch_assoc()) {
                $agents[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $agents]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
        break;
}
?>
