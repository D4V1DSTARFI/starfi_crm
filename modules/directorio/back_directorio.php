<?php
// modules/directorio/back_directorio.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'get_sedes':
        $agente = getAgenteInfo();
        $rol = strtoupper(trim($agente['rol'] ?? 'AGENTE'));
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        $is_master = ($rol === 'MASTER');
        
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
        
        $where = "";
        if ($rol !== 'MASTER' && $user_sede > 0) {
            $where = "WHERE c.id_sede = $user_sede";
        }
        
        $query = "SELECT c.id, c.nombre, c.numero_whatsapp, c.estado, c.fecha_registro, s.nombre_sede 
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
        
        // Clients can repeat if they are in different sedes, so check by number AND sede
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

        // Asignar al ID de empresa 1 (prototipo)
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
