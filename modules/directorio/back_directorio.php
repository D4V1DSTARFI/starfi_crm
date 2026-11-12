<?php
// modules/directorio/back_directorio.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
$agente_dir = getAgenteInfo();
session_write_close();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

$rol_dir = strtoupper(trim($agente_dir['rol'] ?? 'AGENTE'));
$user_sede_dir = isset($agente_dir['id_sede']) ? intval($agente_dir['id_sede']) : 0;
$is_master_dir = ($rol_dir === 'MASTER' || $rol_dir === 'MASTER CI' || intval($agente_dir['id'] ?? 0) === 1 || ($rol_dir === 'ADMIN' && $user_sede_dir === 0));

function authorize_directorio_cliente($con, $cliente_id, $is_master, $user_sede) {
    $cid = intval($cliente_id);
    if ($cid <= 0) {
        return false;
    }
    if ($is_master) {
        return true;
    }
    if ($user_sede <= 0) {
        return false;
    }
    $stmt = $con->prepare("
        SELECT 1
        FROM clientes_contactos cl
        WHERE cl.id = ?
          AND (
              cl.id_sede = ?
              OR EXISTS (
                  SELECT 1
                  FROM conversaciones cv2
                  INNER JOIN lineas_whatsapp lw2 ON lw2.id = cv2.id_linea
                  WHERE cv2.id_cliente = cl.id AND lw2.id_sede = ?
              )
          )
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iii", $cid, $user_sede, $user_sede);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

switch ($action) {
    case 'get_sedes':
        $user_sede = $user_sede_dir;
        $is_master = $is_master_dir;
        
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
        $user_sede = $user_sede_dir;
        $is_master = $is_master_dir;
        
        $where = "";
        if (!$is_master && $user_sede > 0) {
            $where = "WHERE (c.id_sede = $user_sede OR EXISTS (
                SELECT 1 FROM conversaciones cv2
                INNER JOIN lineas_whatsapp lw2 ON lw2.id = cv2.id_linea
                WHERE cv2.id_cliente = c.id AND lw2.id_sede = $user_sede
            ))";
        } elseif (!$is_master) {
            echo json_encode(['status' => 'success', 'data' => []]);
            break;
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
    case 'create_broadcast_campaign':
    case 'send_broadcast_item':
    case 'finish_broadcast_campaign':
        http_response_code(410);
        echo json_encode([
            'status' => 'error',
            'deprecated' => true,
            'redirect' => '../campanas/campanas.php',
            'message' => 'La lógica de campañas de difusión fue centralizada exclusivamente en el módulo Campañas (modules/campanas/back_campanas.php).'
        ]);
        break;

    case 'load_profile':
        $id = intval($_POST['id'] ?? 0);
        if (!authorize_directorio_cliente($con, $id, $is_master_dir, $user_sede_dir)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
            exit;
        }
        $stmt = $con->prepare("SELECT c.*, s.nombre_sede FROM clientes_contactos c LEFT JOIN sedes s ON c.id_sede = s.id WHERE c.id = ?");
        
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $client = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($client) {
                $sede_conv_filter = (!$is_master_dir && $user_sede_dir > 0) ? " AND lw.id_sede = " . intval($user_sede_dir) : "";

                // 1. Historial de Conversaciones (aislado por sede si no es MASTER)
                $stmt_conv = $con->prepare("
                    SELECT conv.id, conv.estado, conv.fecha_inicio, conv.fecha_resolucion, conv.resultado_comercial,
                           COALESCE(up.nombre, 'Sin Asesor') as agente_nombre,
                           (SELECT contenido FROM mensajes_y_eventos WHERE id_conversacion = conv.id ORDER BY id DESC LIMIT 1) as ultimo_mensaje,
                           (SELECT timestamp FROM mensajes_y_eventos WHERE id_conversacion = conv.id ORDER BY id DESC LIMIT 1) as ultimo_timestamp
                    FROM conversaciones conv
                    LEFT JOIN lineas_whatsapp lw ON conv.id_linea = lw.id
                    LEFT JOIN usuario_perfil up ON conv.id_agente = up.id_usuario
                    WHERE conv.id_cliente = ? $sede_conv_filter
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

                // 2. Historial de Ventas (BAN-02: excluir mensajes de campañas masivas)
                $stmt_ventas = $con->prepare("
                    SELECT m.tipo, m.origen, m.contenido, m.timestamp, conv.id as id_conversacion, conv.fecha_cierre_venta, conv.resultado_comercial
                    FROM mensajes_y_eventos m
                    JOIN conversaciones conv ON m.id_conversacion = conv.id
                    LEFT JOIN lineas_whatsapp lw ON conv.id_linea = lw.id
                    WHERE conv.id_cliente = ? $sede_conv_filter
                      AND (
                          (m.origen = 'API_TRANSACCIONAL'
                           AND m.contenido NOT LIKE '%starfi_difusion_directa%'
                           AND m.contenido NOT LIKE '%[DIFUSIÓN:%'
                           AND m.contenido NOT LIKE '%| Texto:%'
                           AND IFNULL(m.categoria_meta, '') != 'MARKETING')
                          OR m.tipo = 'VENTA'
                          OR conv.resultado_comercial = 'VENTA_CERRADA'
                          OR conv.resultado_comercial LIKE '%VENTA%'
                      )
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
        if (!authorize_directorio_cliente($con, $id, $is_master_dir, $user_sede_dir)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para modificar este cliente.']);
            exit;
        }
        $nombre = $_POST['nombre'] ?? '';
        $numero_whatsapp = preg_replace('/[^0-9]/', '', $_POST['numero_whatsapp'] ?? '');
        $direccion = $_POST['direccion'] ?? '';
        $notas = $_POST['notas'] ?? '';
        if ($is_master_dir) {
            $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : null;
        } else {
            $id_sede = ($user_sede_dir > 0) ? $user_sede_dir : null;
        }

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
                    $stmt->close();
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
                    $stmt->close();
                }
            }
        }
        break;

    case 'check_duplicate':
        $numero_whatsapp = preg_replace('/[^0-9]/', '', $_POST['numero_whatsapp'] ?? '');
        if ($is_master_dir) {
            $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : 0;
        } else {
            $id_sede = $user_sede_dir;
        }
        
        if (empty($numero_whatsapp)) {
            echo json_encode(['status' => 'error', 'message' => 'Número vacío']);
            exit;
        }
        
        $sede_cond = $id_sede > 0 ? "AND id_sede = " . intval($id_sede) : "AND id_sede IS NULL";
        
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
            $stmt->close();
        }
        break;

    case 'create_profile':
        $nombre = $_POST['nombre'] ?? '';
        $direccion = $_POST['direccion'] ?? '';
        $notas = $_POST['notas'] ?? '';
        if ($is_master_dir) {
            $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : null;
        } else {
            if ($user_sede_dir <= 0) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Tu usuario no tiene una sede asignada.']);
                exit;
            }
            $id_sede = $user_sede_dir;
        }
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
            $stmt->close();
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al preparar la consulta.']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
        break;
}
?>
