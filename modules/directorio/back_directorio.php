<?php
// modules/directorio/back_directorio.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

function ensureBroadcastTables($con) {
    $con->query("CREATE TABLE IF NOT EXISTS `campanas_difusion` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `id_sede` INT NOT NULL,
        `id_usuario` INT NOT NULL,
        `nombre_campana` VARCHAR(180) NOT NULL,
        `template_name` VARCHAR(120) NOT NULL DEFAULT 'starfi_difusion_directa',
        `param_cuerpo` TEXT NOT NULL,
        `param_eslogan` TEXT NOT NULL,
        `total_destinatarios` INT NOT NULL DEFAULT 0,
        `enviados` INT NOT NULL DEFAULT 0,
        `fallidos` INT NOT NULL DEFAULT 0,
        `estado` VARCHAR(30) NOT NULL DEFAULT 'EN_PROCESO',
        `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `fecha_fin` DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $con->query("CREATE TABLE IF NOT EXISTS `notificacion_enviada` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `template` VARCHAR(150) DEFAULT NULL,
        `fecha` DATE DEFAULT NULL,
        `hora` TIME DEFAULT NULL,
        `telefono_emisor` VARCHAR(50) DEFAULT NULL,
        `telefono_receptor` VARCHAR(50) DEFAULT NULL,
        `contenido` TEXT DEFAULT NULL,
        `status` VARCHAR(30) DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

function normalizeVenezuelanPhone($rawPhone) {
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

function sanitizeMetaTemplateParam($text, $stripTrailingDot = false) {
    // Meta Graph API prohíbe saltos de línea (\n, \r), tabulaciones (\t) y >4 espacios seguidos en parámetros de BODY (Error 132018)
    $clean = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$text);
    $clean = preg_replace('/\s{2,}/', ' ', $clean);
    $clean = trim($clean);
    if ($stripTrailingDot) {
        $clean = rtrim($clean, '.');
        $clean = trim($clean);
    }
    return $clean !== '' ? $clean : 'N/A';
}

switch ($action) {
    case 'get_sedes':
        $agente = getAgenteInfo();
        $rol = strtoupper(trim($agente['rol'] ?? 'AGENTE'));
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        $is_master = ($rol === 'MASTER' || intval($agente['id'] ?? 0) === 1);
        
        $where = "";
        if (!$is_master && $user_sede > 0) {
            $where = "WHERE id = $user_sede";
        }
        $res = $con->query("SELECT id, nombre_sede FROM sedes $where ORDER BY nombre_sede ASC");
        $sedes = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) $sedes[] = $row;
        }
        echo json_encode(['status' => 'success', 'is_master' => $is_master, 'data' => $sedes]);
        break;

    case 'load_clients':
        $agente = getAgenteInfo();
        $rol = strtoupper(trim($agente['rol'] ?? 'AGENTE'));
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        $is_master = ($rol === 'MASTER' || intval($agente['id'] ?? 0) === 1);
        
        $where = "";
        if (!$is_master && $user_sede > 0) {
            $where = "WHERE c.id_sede = $user_sede";
        }
        
        $query = "SELECT c.id, c.id_sede, c.nombre, c.numero_whatsapp, c.estado, c.etiquetas, c.fecha_registro, s.nombre_sede 
                  FROM clientes_contactos c 
                  LEFT JOIN sedes s ON c.id_sede = s.id 
                  $where
                  ORDER BY c.fecha_registro DESC";
        $res = $con->query($query);
        
        $clients = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $clients[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $clients]);
        break;

    case 'get_broadcast_config':
        ensureBroadcastTables($con);
        $agente = getAgenteInfo();
        $rol = strtoupper(trim($agente['rol'] ?? 'AGENTE'));
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        $is_master = ($rol === 'MASTER' || intval($agente['id'] ?? 0) === 1);

        $where_sede = (!$is_master && $user_sede > 0) ? "AND s.id = $user_sede" : "";

        // 1. Líneas de WhatsApp activas por sede
        $q_lineas = "SELECT s.id AS id_sede, s.nombre_sede, l.id AS id_linea, l.numero_telefono, l.meta_app_id, l.estado
                     FROM sedes s
                     JOIN lineas_whatsapp l ON l.id_sede = s.id
                     WHERE l.estado = 'ACTIVO' AND s.estado = 'ACTIVO' $where_sede
                     ORDER BY s.nombre_sede ASC";
        $res_lineas = $con->query($q_lineas);
        $lineas = [];
        if ($res_lineas) {
            while ($r = $res_lineas->fetch_assoc()) {
                $lineas[] = $r;
            }
        }

        // 2. Plantillas aprobadas (asegurando siempre starfi_difusion_directa primero)
        $plantillas = [
            [
                'name' => 'starfi_difusion_directa',
                'category' => 'MARKETING',
                'language' => 'es',
                'status' => 'APPROVED'
            ]
        ];
        $res_tpl = @$con->query("SELECT DISTINCT name, category, language, status FROM plantillas_whatsapp WHERE status IN ('APPROVED', 'ACTIVE') ORDER BY name ASC");
        if ($res_tpl) {
            while ($t = $res_tpl->fetch_assoc()) {
                if ($t['name'] !== 'starfi_difusion_directa') {
                    $plantillas[] = $t;
                } else {
                    // Actualizar el idioma real si está sincronizada en BD
                    $plantillas[0]['language'] = !empty($t['language']) ? $t['language'] : 'es';
                }
            }
        }

        // 3. Historial reciente de campañas
        $q_camp = "SELECT cd.*, s.nombre_sede 
                   FROM campanas_difusion cd
                   LEFT JOIN sedes s ON cd.id_sede = s.id
                   " . ((!$is_master && $user_sede > 0) ? "WHERE cd.id_sede = $user_sede" : "") . "
                   ORDER BY cd.id DESC LIMIT 15";
        $res_camp = $con->query($q_camp);
        $campanas = [];
        if ($res_camp) {
            while ($c = $res_camp->fetch_assoc()) {
                $campanas[] = $c;
            }
        }

        echo json_encode([
            'status' => 'success',
            'lineas' => $lineas,
            'plantillas' => $plantillas,
            'campanas' => $campanas
        ]);
        break;

    case 'create_broadcast_campaign':
        ensureBroadcastTables($con);
        $agente = getAgenteInfo();
        $id_usuario = intval($agente['id'] ?? $_SESSION['agente_id'] ?? 1);
        $id_sede = intval($_POST['id_sede'] ?? 0);
        $nombre_campana = trim($_POST['nombre_campana'] ?? '');
        $template_name = trim($_POST['template_name'] ?? 'starfi_difusion_directa');
        $param_cuerpo = sanitizeMetaTemplateParam($_POST['param_2'] ?? '', true);
        $param_eslogan = sanitizeMetaTemplateParam($_POST['param_3'] ?? '', true);
        $total = intval($_POST['total_destinatarios'] ?? 0);

        if ($id_sede <= 0 || $total <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Sede o lista de destinatarios inválida.']);
            exit;
        }
        if (empty($nombre_campana)) {
            $nombre_campana = 'Difusión ' . date('d/m/Y H:i');
        }

        $stmt = $con->prepare("INSERT INTO campanas_difusion (id_sede, id_usuario, nombre_campana, template_name, param_cuerpo, param_eslogan, total_destinatarios, estado) VALUES (?, ?, ?, ?, ?, ?, ?, 'EN_PROCESO')");
        if ($stmt) {
            $stmt->bind_param("iissssi", $id_sede, $id_usuario, $nombre_campana, $template_name, $param_cuerpo, $param_eslogan, $total);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'campaign_id' => $stmt->insert_id]);
                exit;
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'No se pudo inicializar el registro de campaña.']);
        break;

    case 'send_broadcast_item':
        ensureBroadcastTables($con);
        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $id_sede = intval($_POST['id_sede'] ?? 0);
        $template_name = trim($_POST['template_name'] ?? 'starfi_difusion_directa');
        $lang_code = trim($_POST['language_code'] ?? 'es');
        $raw_phone = trim($_POST['telefono'] ?? '');
        $raw_nombre = trim($_POST['nombre'] ?? '');
        $raw_param2 = $_POST['param_2'] ?? '';
        $raw_param3 = $_POST['param_3'] ?? '';

        if ($id_sede <= 0 || empty($raw_phone)) {
            echo json_encode(['status' => 'error', 'message' => 'Falta sede o teléfono de destino.']);
            exit;
        }

        // 1. Normalizar y validar número venezolano
        $telefono = normalizeVenezuelanPhone($raw_phone);
        if (strlen($telefono) !== 12 || !preg_match('/^58(414|424|412|416|426|2[0-9]{2})[0-9]{7}$/', $telefono)) {
            if ($campaign_id > 0) {
                $con->query("UPDATE campanas_difusion SET fallidos = fallidos + 1 WHERE id = $campaign_id");
            }
            echo json_encode([
                'status' => 'error',
                'telefono' => $raw_phone,
                'message' => "Formato de teléfono inválido ($telefono). Se requiere móvil/local venezolano."
            ]);
            exit;
        }

        // 2. Obtener credenciales de la línea activa de la sede seleccionada
        $stmt_lin = $con->prepare("SELECT l.id AS id_linea, l.meta_app_id, l.meta_token, COALESCE(s.id_empresa, 1) AS id_empresa, s.nombre_sede
                                   FROM lineas_whatsapp l
                                   JOIN sedes s ON l.id_sede = s.id
                                   WHERE s.id = ? AND l.estado = 'ACTIVO' AND s.estado = 'ACTIVO'
                                   LIMIT 1");
        $stmt_lin->bind_param("i", $id_sede);
        $stmt_lin->execute();
        $res_lin = $stmt_lin->get_result();
        if (!$res_lin || $res_lin->num_rows === 0) {
            echo json_encode(['status' => 'error', 'telefono' => $telefono, 'message' => 'La sede seleccionada no tiene una línea de WhatsApp activa.']);
            exit;
        }
        $linea = $res_lin->fetch_assoc();
        $stmt_lin->close();

        $telefonoID = trim($linea['meta_app_id'] ?? '');
        $token = !empty($linea['meta_token']) ? trim($linea['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');
        $id_linea = intval($linea['id_linea']);
        $id_empresa = intval($linea['id_empresa']);

        if (empty($telefonoID) || empty($token)) {
            echo json_encode(['status' => 'error', 'telefono' => $telefono, 'message' => 'Faltan credenciales Meta (Phone ID / Token) en la sede.']);
            exit;
        }

        // 3. Sanitizar las 3 variables de la plantilla
        $nombre_limpio = $raw_nombre;
        if (empty($nombre_limpio) || preg_match('/^\+?[0-9\s\-]+$/', $nombre_limpio)) {
            $nombre_limpio = 'Estimado(a) cliente';
        }
        $p1 = sanitizeMetaTemplateParam($nombre_limpio, false);
        $p2 = sanitizeMetaTemplateParam($raw_param2, true);
        $p3 = sanitizeMetaTemplateParam($raw_param3, true);

        $params = [$p1, $p2, $p3];
        $components = [
            [
                'type' => 'body',
                'parameters' => array_map(function($val) {
                    return ['type' => 'text', 'text' => $val];
                }, $params)
            ]
        ];

        $url = "https://graph.facebook.com/v23.0/{$telefonoID}/messages";
        $headers = [
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ];

        // Lista de códigos de idioma en español para auto-reintento si Meta la registró bajo otra variante regional
        $lang_candidates = array_values(array_unique([$lang_code, 'es', 'es_ES', 'es_LA', 'es_MX']));
        $status_code = 0;
        $estado_meta = [];

        foreach ($lang_candidates as $try_lang) {
            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $telefono,
                'type' => 'template',
                'template' => [
                    'name' => $template_name,
                    'language' => ['code' => $try_lang],
                    'components' => $components
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            $raw_exec = curl_exec($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $estado_meta = json_decode($raw_exec, true) ?? [];

            // Si fue exitoso o el error NO es 132001 (idioma de plantilla no coincide), salimos del bucle
            $err_code = intval($estado_meta['error']['code'] ?? 0);
            if ($status_code === 200 || $err_code !== 132001) {
                break;
            }
        }

        $envio_exitoso = ($status_code === 200 && !empty($estado_meta['messages'][0]['id']));
        $id_mensaje_meta = $estado_meta['messages'][0]['id'] ?? '';
        $fecha_hoy = date('Y-m-d');
        $hora_actual = date('H:i:s');
        $now_full = "$fecha_hoy $hora_actual";

        // 4. Registrar en notificacion_enviada
        $contenido_log = 'Envío dinámico de plantilla: ' . $template_name . '. Params: ' . json_encode($params, JSON_UNESCAPED_UNICODE);
        $contenido_esc = $con->real_escape_string($contenido_log);
        $status_bd = $envio_exitoso ? 'ENVIADO' : 'ERROR';
        $con->query("INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                     VALUES ('$template_name', '$fecha_hoy', '$hora_actual', '$telefonoID', '$telefono', '$contenido_esc', '$status_bd')");

        // 5. Vincular con el Directorio y la Bandeja / Bot IA del CRM
        $q_cli = $con->query("SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = '$telefono' LIMIT 1");
        if ($q_cli && $q_cli->num_rows > 0) {
            $row_cli = $q_cli->fetch_assoc();
            $id_cliente = intval($row_cli['id']);
            // Si el cliente no tenía nombre guardado y en la difusión le pusimos uno real, lo actualizamos
            if ((empty($row_cli['nombre']) || $row_cli['nombre'] === 'Usuario' || $row_cli['nombre'] === 'Cliente') && $p1 !== 'Estimado(a) cliente') {
                $nom_esc = $con->real_escape_string($p1);
                $con->query("UPDATE clientes_contactos SET nombre = '$nom_esc', id_sede = COALESCE(id_sede, $id_sede), ultimo_contacto = '$now_full' WHERE id = $id_cliente");
            } else {
                $con->query("UPDATE clientes_contactos SET ultimo_contacto = '$now_full' WHERE id = $id_cliente");
            }
        } else {
            $nom_insert = ($p1 !== 'Estimado(a) cliente') ? "'" . $con->real_escape_string($p1) . "'" : "NULL";
            $con->query("INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre, fecha_registro, ultimo_contacto)
                         VALUES ($id_empresa, $id_sede, '$telefono', $nom_insert, '$now_full', '$now_full')");
            $id_cliente = intval($con->insert_id);
        }

        if ($id_cliente > 0) {
            // Buscar conversación abierta en esta línea o crearla en ESPERA_ASIGNACION (sin agente humano)
            // para que cuando el cliente responda a la difusión, el Bot / IA de la sede le conteste automáticamente con el contexto de la campaña.
            $q_conv = $con->query("SELECT id FROM conversaciones WHERE id_cliente = $id_cliente AND estado NOT IN ('CERRADO', 'RESUELTO') ORDER BY id DESC LIMIT 1");
            if ($q_conv && $q_conv->num_rows > 0) {
                $id_conversacion = intval($q_conv->fetch_assoc()['id']);
                $con->query("UPDATE conversaciones SET id_linea = $id_linea WHERE id = $id_conversacion");
            } else {
                $con->query("INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos, fecha_inicio)
                             VALUES ($id_linea, $id_cliente, 'ESPERA_ASIGNACION', 0, '$now_full')");
                $id_conversacion = intval($con->insert_id);
            }

            $estado_msg = $envio_exitoso ? 'ENVIADO' : 'FALLIDO';
            $error_detalle = null;
            if (!$envio_exitoso) {
                $code = $estado_meta['error']['code'] ?? '';
                $msg_err = $estado_meta['error']['message'] ?? 'Error al enviar plantilla';
                $details = $estado_meta['error']['error_data']['details'] ?? '';
                $error_detalle = "Error Meta ($code): " . ($details ? "$msg_err - $details" : $msg_err);
            }
            $err_sql = $error_detalle ? "'" . $con->real_escape_string($error_detalle) . "'" : "NULL";
            $id_meta_esc = $con->real_escape_string($id_mensaje_meta);

            $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, tipo, origen, contenido, estado_envio, error_detalle, timestamp)
                         VALUES ($id_conversacion, '$id_meta_esc', 'TEXTO', 'API_TRANSACCIONAL', '$contenido_esc', '$estado_msg', $err_sql, '$now_full')");
        }

        // 6. Actualizar contadores de la campaña
        if ($campaign_id > 0) {
            if ($envio_exitoso) {
                $con->query("UPDATE campanas_difusion SET enviados = enviados + 1 WHERE id = $campaign_id");
            } else {
                $con->query("UPDATE campanas_difusion SET fallidos = fallidos + 1 WHERE id = $campaign_id");
            }
            $con->query("UPDATE campanas_difusion SET estado = 'COMPLETADA', fecha_fin = NOW() WHERE id = $campaign_id AND (enviados + fallidos) >= total_destinatarios");
        }

        if ($envio_exitoso) {
            echo json_encode([
                'status' => 'success',
                'telefono' => $telefono,
                'nombre' => $p1,
                'message_id' => $id_mensaje_meta
            ]);
        } else {
            $err_msg = $estado_meta['error']['error_data']['details'] ?? ($estado_meta['error']['message'] ?? 'Error desconocido en Meta API');
            echo json_encode([
                'status' => 'error',
                'telefono' => $telefono,
                'nombre' => $p1,
                'message' => $err_msg,
                'meta_error' => $estado_meta['error'] ?? null
            ]);
        }
        break;

    case 'finish_broadcast_campaign':
        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $estado = ($_POST['estado'] ?? 'COMPLETADA') === 'CANCELADA' ? 'CANCELADA' : 'COMPLETADA';
        if ($campaign_id > 0) {
            $con->query("UPDATE campanas_difusion SET estado = '$estado', fecha_fin = NOW() WHERE id = $campaign_id");
        }
        echo json_encode(['status' => 'success']);
        break;

    case 'load_profile':
        $id = intval($_POST['id'] ?? 0);
        $stmt = $con->prepare("SELECT c.*, s.nombre_sede FROM clientes_contactos c LEFT JOIN sedes s ON c.id_sede = s.id WHERE c.id = ?");
        
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $client = $stmt->get_result()->fetch_assoc();

            if ($client) {
                // 1. Historial de Conversaciones
                $stmt_conv = $con->prepare("
                    SELECT conv.id, conv.estado, conv.fecha_inicio, conv.fecha_resolucion, conv.resultado_comercial,
                           COALESCE(up.nombre, 'Sin Asesor') as agente_nombre,
                           (SELECT contenido FROM mensajes_y_eventos WHERE id_conversacion = conv.id ORDER BY timestamp DESC LIMIT 1) as ultimo_mensaje,
                           (SELECT timestamp FROM mensajes_y_eventos WHERE id_conversacion = conv.id ORDER BY timestamp DESC LIMIT 1) as ultimo_timestamp
                    FROM conversaciones conv
                    LEFT JOIN usuario_perfil up ON conv.id_agente = up.id_usuario
                    WHERE conv.id_cliente = ?
                    ORDER BY conv.fecha_inicio DESC
                ");
                $conversaciones = [];
                if ($stmt_conv) {
                    $stmt_conv->bind_param("i", $id);
                    $stmt_conv->execute();
                    $conv_res = $stmt_conv->get_result();
                    while ($c = $conv_res->fetch_assoc()) {
                        $conversaciones[] = $c;
                    }
                    $stmt_conv->close();
                }

                // 2. Historial de Ventas
                $stmt_ventas = $con->prepare("
                    SELECT m.tipo, m.origen, m.contenido, m.timestamp, conv.id as id_conversacion, conv.fecha_cierre_venta, conv.resultado_comercial
                    FROM mensajes_y_eventos m
                    JOIN conversaciones conv ON m.id_conversacion = conv.id
                    WHERE conv.id_cliente = ? AND (m.origen = 'API_TRANSACCIONAL' OR m.tipo = 'VENTA' OR conv.resultado_comercial = 'VENTA_CERRADA' OR conv.resultado_comercial LIKE '%VENTA%')
                    ORDER BY m.timestamp DESC LIMIT 30
                ");
                $ventas = [];
                if ($stmt_ventas) {
                    $stmt_ventas->bind_param("i", $id);
                    $stmt_ventas->execute();
                    $ventas_res = $stmt_ventas->get_result();
                    while ($v = $ventas_res->fetch_assoc()) {
                        $ventas[] = $v;
                    }
                    $stmt_ventas->close();
                }
                
                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'client' => $client,
                        'conversaciones' => $conversaciones,
                        'ventas' => $ventas,
                        'events' => $ventas
                    ]
                ]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado']);
            }
        }
        break;

    case 'save_profile':
        $id = intval($_POST['id'] ?? 0);
        $nombre = $_POST['nombre'] ?? '';
        $numero_whatsapp = preg_replace('/[^0-9]/', '', $_POST['numero_whatsapp'] ?? '');
        $direccion = $_POST['direccion'] ?? '';
        $notas = $_POST['notas'] ?? '';
        $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : null;

        if ($id > 0) {
            if (!empty($numero_whatsapp)) {
                $stmt = $con->prepare("UPDATE clientes_contactos SET nombre = ?, numero_whatsapp = ?, direccion = ?, notas_internas = ?, id_sede = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("ssssii", $nombre, $numero_whatsapp, $direccion, $notas, $id_sede, $id);
                    if ($stmt->execute()) {
                        echo json_encode(['status' => 'success', 'message' => 'Perfil actualizado']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Error al actualizar el perfil (posible número duplicado)']);
                    }
                }
            } else {
                $stmt = $con->prepare("UPDATE clientes_contactos SET nombre = ?, direccion = ?, notas_internas = ?, id_sede = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("sssii", $nombre, $direccion, $notas, $id_sede, $id);
                    if ($stmt->execute()) {
                        echo json_encode(['status' => 'success', 'message' => 'Perfil actualizado']);
                    } else {
                        echo json_encode(['status' => 'error', 'message' => 'Error al actualizar']);
                    }
                }
            }
        }
        break;

    case 'check_duplicate':
        $numero_whatsapp = preg_replace('/[^0-9]/', '', $_POST['numero_whatsapp'] ?? '');
        $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : 0;
        
        if (empty($numero_whatsapp)) {
            echo json_encode(['status' => 'error', 'message' => 'Número vacío']);
            exit;
        }
        
        $sede_cond = $id_sede > 0 ? "AND id_sede = $id_sede" : "AND id_sede IS NULL";
        
        $stmt = $con->prepare("SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = ? $sede_cond LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $numero_whatsapp);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $client = $res->fetch_assoc();
                echo json_encode(['status' => 'exists', 'client' => $client]);
            } else {
                echo json_encode(['status' => 'clean']);
            }
        }
        break;

    case 'create_profile':
        $nombre = $_POST['nombre'] ?? '';
        $direccion = $_POST['direccion'] ?? '';
        $notas = $_POST['notas'] ?? '';
        $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : null;
        $numero_whatsapp = preg_replace('/[^0-9]/', '', $_POST['numero_whatsapp'] ?? '');
        
        if (empty($numero_whatsapp) || empty($nombre)) {
            echo json_encode(['status' => 'error', 'message' => 'Nombre y número son obligatorios']);
            exit;
        }

        $id_empresa = 1;

        $stmt = $con->prepare("INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre, direccion, notas_internas) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iissss", $id_empresa, $id_sede, $numero_whatsapp, $nombre, $direccion, $notas);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Cliente creado con éxito']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error: El número de WhatsApp ya podría estar registrado para esta sede.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al preparar la consulta.']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
        break;
}
?>
