<?php
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

    // NUEVO: Extraer el número de teléfono que recibió el mensaje (telefono_meta)
    $telefonoReceptorID = $value['metadata']['phone_number_id'] ?? null;
    $displayPhoneNumber = $value['metadata']['display_phone_number'] ?? null;
    
    file_put_contents(__DIR__ . "/logs/debug_phone_id.txt", "Received phone_number_id: " . $telefonoReceptorID . "\n", FILE_APPEND);

    // GESTIÓN DE ESTADOS (Doble check: enviado, entregado, leído)
    if (isset($value['statuses'][0])) {
        $estado = $value['statuses'][0]['status']; // sent, delivered, read, failed
        $id_mensaje_meta_status = $value['statuses'][0]['id'];
        
        $estado_sql = null;
        if ($estado == 'sent') $estado_sql = 'ENVIADO';
        else if ($estado == 'delivered') $estado_sql = 'ENTREGADO';
        else if ($estado == 'read') $estado_sql = 'LEIDO';
        else if ($estado == 'failed') $estado_sql = 'FALLIDO';
        
        if ($con && $estado_sql) {
            $id_msg_esc = mysqli_real_escape_string($con, $id_mensaje_meta_status);
            mysqli_query($con, "UPDATE mensajes_y_eventos SET estado_envio = '$estado_sql' WHERE id_mensaje_meta = '$id_msg_esc'");
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
        $tipo_bd = 'EVENTO_SISTEMA'; 
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
    } else if (in_array($tipo_mensaje, ['sticker', 'location', 'reaction'])) {
        $tipo_bd = 'EVENTO_SISTEMA';
        if ($tipo_mensaje === 'reaction') {
            $emoji = $msg['reaction']['emoji'] ?? '';
            $mensaje_texto = "El usuario reaccionó con: $emoji";
        } else if ($tipo_mensaje === 'location') {
            $mensaje_texto = "El usuario envió una ubicación.";
        } else {
            $mensaje_texto = "El usuario envió un sticker.";
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
    
    // 1. BUSCAR LA LINEA CORRESPONDIENTE AL NÚMERO RECEPTOR
    $id_linea = null;
    $id_empresa = 1; // Default
    
    if ($telefono_receptor_id) {
        $query_api = "SELECT l.id, s.id_empresa FROM lineas_whatsapp l 
                      LEFT JOIN sedes s ON l.id_sede = s.id 
                      WHERE l.meta_app_id = '$telefono_receptor_id' AND l.estado_conexion = 'CONECTADO' LIMIT 1";
        $result_api = mysqli_query($con, $query_api);
        if ($result_api && mysqli_num_rows($result_api) > 0) {
            $row = mysqli_fetch_assoc($result_api);
            $id_linea = $row['id'];
            if ($row['id_empresa']) $id_empresa = $row['id_empresa'];
        }
    }
    
    // Fallback a cualquier línea activa si no se encuentra
    if (!$id_linea) {
        $query_api = "SELECT l.id, s.id_empresa FROM lineas_whatsapp l 
                      LEFT JOIN sedes s ON l.id_sede = s.id 
                      WHERE l.estado_conexion = 'CONECTADO' LIMIT 1";
        $result_api = mysqli_query($con, $query_api);
        if ($result_api && mysqli_num_rows($result_api) > 0) {
            $row = mysqli_fetch_assoc($result_api);
            $id_linea = $row['id'];
            if ($row['id_empresa']) $id_empresa = $row['id_empresa'];
        } else {
            $id_linea = 1; // Fallback definitivo
        }
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
        $insert_cliente = "INSERT INTO clientes_contactos (id_empresa, numero_whatsapp, nombre) VALUES ($id_empresa, '$telefono_cliente', NULL)";
        if (mysqli_query($con, $insert_cliente)) {
            $id_cliente = mysqli_insert_id($con);
        }
    }
    
    if (!$id_cliente) {
        error_log("No se pudo obtener ni crear el cliente en el webhook.");
        return;
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

    // LÓGICA DE BOT_RECOPILANDO
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
        $q_token = mysqli_query($con, "SELECT meta_app_id, meta_token, id_sede FROM lineas_whatsapp WHERE id = $id_linea");
        if($q_token && mysqli_num_rows($q_token) > 0) {
            $linea_info = mysqli_fetch_assoc($q_token);
            
            // Marcar el mensaje entrante como leído (doble check azul)
            marcar_como_leido_api($linea_info, $id_mensaje_meta);
            
            if ($estado_conv === 'BOT_RECOPILANDO') {
                // Pedir nombre si es la primera vez
                if ($nueva_conversacion) {
                    $mensaje_bot = "Hola, buenos días. ¿Cuál es tu nombre, por favor me indicas?";
                    enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $mensaje_bot, $id_conversacion);
                }
            } else if ($nueva_conversacion && $estado_conv === 'ESPERA_ASIGNACION') {
                // Saludar por nombre y mandar operadores
                $nombre_saludo = !empty($nombre_db) ? $nombre_db : $perfil;
                $mensaje_bot = "Hola $nombre_saludo, ¿en qué te puedo ayudar? Te comunicaremos con uno de nuestros operadores.";
                enviar_mensaje_texto_api($con, $linea_info, $telefono_cliente, $mensaje_bot, $id_conversacion);
                
                $id_sede = $linea_info['id_sede'] ?? null;
                enviar_contactos_asesores($linea_info['meta_app_id'], $linea_info['meta_token'], $telefono_cliente, $id_sede, $con, $id_conversacion);
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
    
    // Usar la lista por defecto del API_STARFI_WSAP
    $asesores = [
        ['nombre' => 'Ceny Landaeta', 'telefono' => '+584126127873'],
        ['nombre' => 'Gabriel Benitez', 'telefono' => '+584242907452'],
        ['nombre' => 'Junior Sosa', 'telefono' => '+584123695820'],
        ['nombre' => 'Luis Albarran', 'telefono' => '+584242988805'],
        ['nombre' => 'Yorman Cardozo', 'telefono' => '+584127029710'],
        ['nombre' => 'Marcos Santo Domingo', 'telefono' => '+584122949976'],
        ['nombre' => 'Miguel Strocchia', 'telefono' => '+584126862683']
    ];
    
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
                            'type' => 'CELL'
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
?>
