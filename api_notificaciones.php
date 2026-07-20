<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Check for remote sync command from Starfi 2.0 decentralized installations
if (isset($_POST['accion']) && $_POST['accion'] === 'sync_linea') {
    require_once __DIR__ . '/config/database.php';
    $con = getDbConnection();
    
    $id_sede = $_POST['id_sede'] ?? '';
    $token = $_POST['token'] ?? '';
    $instance_id = $_POST['instance_id'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $activo = isset($_POST['activo']) ? intval($_POST['activo']) : 1;
    
    if (empty($id_sede)) {
        echo json_encode(['status' => 'error', 'message' => 'Falta id_sede para sincronizar']);
        exit;
    }
    
    $getCrmSedeId = function($legacy_id) {
        if ($legacy_id == 23) { return 24; }
        return intval($legacy_id) + 2;
    };
    $crm_sede_id = $getCrmSedeId($id_sede);
    $crm_token = $con->real_escape_string($token);
    $crm_instance_id = $con->real_escape_string($instance_id);
    $crm_phone_number = $con->real_escape_string($phone_number);
    $crm_estado = ($activo === 1) ? 'ACTIVO' : 'INACTIVO';
    
    $check_crm = $con->query("SELECT id FROM lineas_whatsapp WHERE id_sede = $crm_sede_id");
    if ($check_crm && $check_crm->num_rows > 0) {
        $q = "UPDATE lineas_whatsapp SET meta_token = '$crm_token', meta_telefono_id = '$crm_instance_id', numero_telefono = '$crm_phone_number', estado = '$crm_estado' WHERE id_sede = $crm_sede_id";
    } else {
        $q = "INSERT INTO lineas_whatsapp (id_sede, descripcion, numero_telefono, meta_telefono_id, meta_token, estado) VALUES ($crm_sede_id, 'API WSAP AUTO SYNCED', '$crm_phone_number', '$crm_instance_id', '$crm_token', '$crm_estado')";
    }
    
    if ($con->query($q)) {
        echo json_encode(['status' => 'success', 'message' => 'Sincronizado con CRM correctamente']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error al guardar en CRM: ' . $con->error]);
    }
    $con->close();
    exit;
}

// Endpoint para que Starfi 2.0 (o sistemas externos) auto-completen sus credenciales leyendo desde el CRM
if (isset($_POST['accion']) && $_POST['accion'] === 'get_info') {
    require_once __DIR__ . '/config/database.php';
    $con = getDbConnection();
    
    $token = $_POST['token'] ?? '';
    if (empty($token)) {
        echo json_encode(['status' => 'error', 'message' => 'Token no proporcionado']);
        exit;
    }
    
    $stmt = $con->prepare("
        SELECT l.meta_telefono_id as instance_id, l.numero_telefono as phone_number, s.nombre_sede, l.estado
        FROM sedes s
        LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id
        WHERE s.api_token = ? 
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $data = $res->fetch_assoc();
            echo json_encode([
                'status' => 'success', 
                'data' => [
                    'instance_id' => $data['instance_id'] ?? '',
                    'phone_number' => $data['phone_number'] ?? '',
                    'nombre_sede' => $data['nombre_sede'],
                    'estado_linea' => $data['estado'] ?? 'SIN_LINEA'
                ]
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Token inválido o Sede no encontrada']);
        }
        $stmt->close();
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
    }
    $con->close();
    exit;
}

// Recibir datos POST
$telefono = $_POST['telefono'] ?? '';
$telefono_asesor = $_POST['telefono_asesor'] ?? '0000000000';
$nombre_cliente = $_POST['nombre_cliente'] ?? 'Cliente';
$monto_total = $_POST['monto_total'] ?? '0.00';
$asesor_ventas = $_POST['asesor_ventas'] ?? 'Nuestro Asesor';
$correlativo = $_POST['correlativo'] ?? '';
$nombre_empresa = $_POST['nombre_empresa'] ?? 'Nuestra Empresa';
$id_sede = $_POST['id_sede'] ?? null;
$verify_token = $_POST['verify_token'] ?? $_POST['token_tienda'] ?? '';
$meta_token_val = $_POST['meta_token_val'] ?? '';
$fecha_compra = date('Y-m-d');
$hora_actual = date('H:i:s');
$signo = 'USD'; // o EUR según configuración

if(empty($telefono)) {
    echo json_encode(['status' => 'error', 'message' => 'No phone number provided']);
    exit;
}

// Limpiar el teléfono para la API (quitar +, espacios, guiones)
$telefono = str_replace(['+', ' ', '-'], '', $telefono);

// Formatear automáticamente números de Venezuela (si no tienen código de país)
if (strlen($telefono) == 11 && strpos($telefono, '0') === 0) {
    // Si empieza con 0 y mide 11 dígitos, ej: 04241234567 -> 584241234567
    $telefono = '58' . substr($telefono, 1);
} elseif (strlen($telefono) == 10 && strpos($telefono, '4') === 0) {
    // Si empieza con 4 y mide 10 dígitos, ej: 4241234567 -> 584241234567
    $telefono = '58' . $telefono;
}

// Configuración de la base de datos de CRM
require_once __DIR__ . '/config/database.php';
$con = getDbConnection();

// Buscar línea activa de WhatsApp correspondiente a la sede del mensaje
$telefonoID = '';
$token = '';
$id_linea = 0;
$id_empresa = 1; // Default
$row_active = null; // Guardará la info de la línea activa en caso de fallback

// 1. Intentar buscar por token de verificación único (60-64 caracteres)
if (!empty($verify_token)) {
    $stmt_token = $con->prepare("SELECT l.id as id_linea, l.meta_telefono_id, l.meta_token, s.id_empresa, s.nombre_sede, s.id as crm_id_sede FROM sedes s JOIN lineas_whatsapp l ON l.id_sede = s.id WHERE s.api_token = ? AND l.estado = 'ACTIVO' LIMIT 1");
    if ($stmt_token) {
        $stmt_token->bind_param("s", $verify_token);
        $stmt_token->execute();
        $q_linea = $stmt_token->get_result();
        if ($q_linea && $q_linea->num_rows > 0) {
            $row = $q_linea->fetch_assoc();
            $telefonoID = $row['meta_telefono_id'];
            $token = $row['meta_token'];
            $id_linea = $row['id_linea'];
            $id_empresa = $row['id_empresa'];
            $crm_id_sede = $row['crm_id_sede'];
        }
        $stmt_token->close();
    }
}

// 2. Intentar buscar por token de Meta (si se envió)
if (empty($token) && !empty($meta_token_val)) {
    $stmt_meta = $con->prepare("SELECT l.id as id_linea, l.meta_telefono_id, l.meta_token, s.id_empresa, s.nombre_sede, s.id as crm_id_sede FROM lineas_whatsapp l JOIN sedes s ON l.id_sede = s.id WHERE l.meta_token = ? AND l.estado = 'ACTIVO' LIMIT 1");
    if ($stmt_meta) {
        $stmt_meta->bind_param("s", $meta_token_val);
        $stmt_meta->execute();
        $q_linea = $stmt_meta->get_result();
        if ($q_linea && $q_linea->num_rows > 0) {
            $row = $q_linea->fetch_assoc();
            $telefonoID = $row['meta_telefono_id'];
            $token = $row['meta_token'];
            $id_linea = $row['id_linea'];
            $id_empresa = $row['id_empresa'];
            $crm_id_sede = $row['crm_id_sede'];
        }
        $stmt_meta->close();
    }
}

// 3. Intentar buscar por ID de Sede preciso (fallback original)
if (empty($token) && !empty($id_sede)) {
    $crm_id_sede = ($id_sede == 23) ? 24 : (intval($id_sede) + 2);
    $q_linea = $con->query("SELECT l.id as id_linea, l.meta_telefono_id, l.meta_token, s.id_empresa, s.nombre_sede, s.id as crm_id_sede FROM lineas_whatsapp l JOIN sedes s ON l.id_sede = s.id WHERE s.id = $crm_id_sede AND l.estado = 'ACTIVO' LIMIT 1");
    if ($q_linea && $q_linea->num_rows > 0) {
        $row = $q_linea->fetch_assoc();
        $telefonoID = $row['meta_telefono_id'];
        $token = $row['meta_token'];
        $id_linea = $row['id_linea'];
        $id_empresa = $row['id_empresa'];
        $crm_id_sede = $row['crm_id_sede'];
    }
}

// 2. Fallback de coincidencia por nombre si no se encontró por ID
if (empty($token) && !empty($nombre_empresa) && $nombre_empresa !== 'Nuestra Empresa') {
    $clean_nombre = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $nombre_empresa));
    
    // Obtener todas las líneas activas configuradas
    $q_all = $con->query("SELECT l.id as id_linea, l.meta_telefono_id, l.meta_token, s.id_empresa, s.nombre_sede, s.id as crm_id_sede FROM lineas_whatsapp l JOIN sedes s ON l.id_sede = s.id WHERE l.estado = 'ACTIVO'");
    
    if ($q_all && $q_all->num_rows > 0) {
        while ($row = $q_all->fetch_assoc()) {
            $clean_db = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $row['nombre_sede']));
            // Intentar coincidencia exacta o por subcadena
            if (stripos($clean_nombre, $clean_db) !== false || stripos($clean_db, $clean_nombre) !== false) {
                $telefonoID = $row['meta_telefono_id'];
                $token = $row['meta_token'];
                $id_linea = $row['id_linea'];
                $id_empresa = $row['id_empresa'];
                $crm_id_sede = $row['crm_id_sede'];
                break;
            }
        }
    }
}

// 3. Fallback global: Si la sede específica no posee una línea de WhatsApp activa, usar la única línea activa en el sistema
if (empty($token)) {
    $q_active_global = $con->query("SELECT l.id as id_linea, l.meta_telefono_id, l.meta_token, s.id_empresa, s.nombre_sede, s.id as crm_id_sede FROM lineas_whatsapp l JOIN sedes s ON l.id_sede = s.id WHERE l.estado = 'ACTIVO' LIMIT 1");
    if ($q_active_global && $q_active_global->num_rows > 0) {
        $row_active = $q_active_global->fetch_assoc();
        $telefonoID = $row_active['meta_telefono_id'];
        $token = $row_active['meta_token'];
        $id_linea = $row_active['id_linea'];
        $id_empresa = $row_active['id_empresa'];
        if (empty($crm_id_sede)) {
            $crm_id_sede = $row_active['crm_id_sede'];
        }
    }
}

// Si la Sede no posee una línea de WhatsApp activa configurada, NO enviar usando otra sede
if (empty($token)) {
    echo json_encode([
        'status' => 'error', 
        'message' => 'WhatsApp API inactive or not configured for branch: ' . ($nombre_empresa ?? 'Unknown')
    ]);
    exit;
}

// Obtener plantilla configurada para la sede y módulo VENTAS
$template_name = '';
if (isset($crm_id_sede)) {
    $legacy_id = ($crm_id_sede == 24) ? 23 : ($crm_id_sede - 2);
    $con_ventas = getExternalDbConnection('ventas');
    if ($con_ventas) {
        $q_t = mysqli_query($con_ventas, "SELECT plantilla FROM config_api_wsap_plantillas WHERE id_sede = $legacy_id AND modulo = 'VENTAS' LIMIT 1");
        if ($q_t && $row_t = mysqli_fetch_assoc($q_t)) {
            if (!empty($row_t['plantilla'])) {
                $template_name = $row_t['plantilla'];
            }
        }
        
        // Si la sede actual no tiene plantilla configurada, intentar usar la de la sede de la línea activa de WhatsApp
        if (empty($template_name) && isset($row_active['crm_id_sede'])) {
            $active_legacy_id = ($row_active['crm_id_sede'] == 23) ? 23 : ($row_active['crm_id_sede'] - 2);
            $q_t_active = mysqli_query($con_ventas, "SELECT plantilla FROM config_api_wsap_plantillas WHERE id_sede = $active_legacy_id AND modulo = 'VENTAS' LIMIT 1");
            if ($q_t_active && $row_t_active = mysqli_fetch_assoc($q_t_active)) {
                if (!empty($row_t_active['plantilla'])) {
                    $template_name = $row_t_active['plantilla'];
                }
            }
        }
        
        mysqli_close($con_ventas);
    }
}

if (empty($template_name)) {
    $template_name = 'send_confirmacion_compra_cn_sn_btn';
}

$url = 'https://graph.facebook.com/v23.0/'.$telefonoID.'/messages';
$header = array("Authorization: Bearer " . $token, "Content-Type: application/json");

// CONFIGURACION DEL MENSAJE (Plantilla dinámica)
$params = [
    $nombre_cliente,
    $fecha_compra,
    $monto_total . ' ' . $signo,
    $asesor_ventas,
    $nombre_empresa,
    $correlativo,
    $telefono_asesor
];

$mensaje_payload = [
    'messaging_product' => 'whatsapp',
    'to' => $telefono,
    'type' => 'template',
    'template' => [
        'namespace' => 'be78987b_011f_4891_8e13_cd03b764fb99',
        'name' => $template_name,
        'language' => [
            'code' => 'es',
            'policy' => 'deterministic'
        ],
        'components' => [
            [
                'type' => 'body',
                'parameters' => array_map(function($p) {
                    return ['type' => 'text', 'text' => (string)$p];
                }, $params)
            ]
        ]
    ]
];
$mensaje = json_encode($mensaje_payload, JSON_UNESCAPED_UNICODE);

// INICIAMOS EL CURL
$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje);
curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$raw_exec = curl_exec($curl);
$estado = json_decode($raw_exec, true);
$status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

// Auto-Retry on parameter count mismatch (Error code 132000)
if ($status_code != 200 && isset($estado['error']['code']) && $estado['error']['code'] == 132000) {
    $err_data = $estado['error']['error_data']['details'] ?? $estado['error']['message'] ?? '';
    if (preg_match('/expected number of params \((\d+)\)/i', $err_data, $matches)) {
        $expected_count = intval($matches[1]);
        if ($expected_count > 0 && $expected_count < count($params)) {
            $params_sliced = array_slice($params, 0, $expected_count);
            $mensaje_payload['template']['components'][0]['parameters'] = array_map(function($p) {
                return ['type' => 'text', 'text' => (string)$p];
            }, $params_sliced);
            
            $mensaje = json_encode($mensaje_payload, JSON_UNESCAPED_UNICODE);
            
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $raw_exec = curl_exec($curl);
            $estado = json_decode($raw_exec, true);
            $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
        }
    }
}

$response = [];

if($status_code == 200) {
    $response['status_envio'] = "[EXITOSO]";
    $status_bd = 'ENVIADO';
} else {
    $response['status_envio'] = "[ERROR]";
    $status_bd = 'ERROR';
}

// Registrar en base de datos
$telefono_emisor = $telefonoID;   
$telefono_receptor = $telefono;
$contenido = 'Confirmacion de compra, cliente: '.$nombre_cliente.', fecha compra: '.$fecha_compra.', monto total: '.$monto_total.' '.$signo.', asesor de ventas: '.$asesor_ventas.', empresa: '.$nombre_empresa.', correlativo: '.$correlativo.', contacto: '.$telefono_asesor;

$insertar = $con->query("INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status) 
                         VALUES ('$template_name', '$fecha_compra', '$hora_actual', '$telefono_emisor', '$telefono_receptor', '$contenido', '$status_bd')");

if($insertar) {
    $response['registro_bd'] = "[EXITOSO]";
} else {
    $response['registro_bd'] = "[ERROR]";
}

// Vinculacion con el inbox nativo del CRM (mensajes_y_eventos y conversaciones)
if($id_linea > 0) {
    // 1. Buscar o crear cliente
    $q_cliente = $con->query("SELECT id FROM clientes_contactos WHERE id_empresa = $id_empresa AND numero_whatsapp = '$telefono'");
    if($q_cliente && $q_cliente->num_rows > 0) {
        $id_cliente = $q_cliente->fetch_assoc()['id'];
        $con->query("UPDATE clientes_contactos SET ultimo_contacto = '$fecha_compra $hora_actual' WHERE id = $id_cliente");
    } else {
        $nombre_cli_esc = $con->real_escape_string($nombre_cliente);
        $con->query("INSERT INTO clientes_contactos (id_empresa, numero_whatsapp, nombre, fecha_registro, ultimo_contacto) VALUES ($id_empresa, '$telefono', '$nombre_cli_esc', '$fecha_compra $hora_actual', '$fecha_compra $hora_actual')");
        $id_cliente = $con->insert_id;
    }

    // 2. Buscar o crear conversación activa
    $q_conv = $con->query("SELECT id FROM conversaciones WHERE id_linea = $id_linea AND id_cliente = $id_cliente AND estado != 'CERRADO' ORDER BY id DESC LIMIT 1");
    if($q_conv && $q_conv->num_rows > 0) {
        $id_conversacion = $q_conv->fetch_assoc()['id'];
    } else {
        $con->query("INSERT INTO conversaciones (id_linea, id_cliente, estado, fecha_inicio) VALUES ($id_linea, $id_cliente, 'RESUELTO', '$fecha_compra $hora_actual')");
        $id_conversacion = $con->insert_id;
    }

    // 3. Insertar mensaje en el chat
    $id_mensaje_meta = "";
    if(isset($estado['messages'][0]['id'])) {
        $id_mensaje_meta = $estado['messages'][0]['id'];
    }
    
    $contenido_esc = $con->real_escape_string($contenido);
    $estado_envio = ($status_code == 200) ? 'ENVIADO' : 'FALLIDO';
    
    $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, tipo, origen, contenido, estado_envio, timestamp) 
                 VALUES ($id_conversacion, '$id_mensaje_meta', 'EVENTO_SISTEMA', 'API_TRANSACCIONAL', '$contenido_esc', '$estado_envio', '$fecha_compra $hora_actual')");
}

$response['meta_response'] = $estado;
echo json_encode($response);
?>
