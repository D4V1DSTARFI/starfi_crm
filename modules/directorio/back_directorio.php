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
        $rol = $agente['rol'] ?? 'AGENTE';
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        
        $where = "";
        if ($rol !== 'MASTER' && $user_sede > 0) {
            $where = "WHERE id = $user_sede";
        }
        $res = $con->query("SELECT id, nombre_sede FROM sedes $where ORDER BY nombre_sede ASC");
        $sedes = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) $sedes[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $sedes]);
        break;

    case 'load_clients':
        $agente = getAgenteInfo();
        $rol = $agente['rol'] ?? 'AGENTE';
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
                // Get timeline events (Bot, System Events, API)
                $stmt2 = $con->prepare("
                    SELECT m.tipo, m.origen, m.contenido, m.timestamp 
                    FROM mensajes_y_eventos m
                    JOIN conversaciones c ON m.id_conversacion = c.id
                    WHERE c.id_cliente = ? AND (m.origen = 'BOT' OR m.origen = 'EVENTO_SISTEMA' OR m.origen = 'API_TRANSACCIONAL')
                    ORDER BY m.timestamp DESC LIMIT 20
                ");
                $stmt2->bind_param("i", $id);
                $stmt2->execute();
                $events_res = $stmt2->get_result();
                $events = [];
                while ($ev = $events_res->fetch_assoc()) {
                    $events[] = $ev;
                }
                
                echo json_encode(['status' => 'success', 'data' => ['client' => $client, 'events' => $events]]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado']);
            }
        }
        break;

    case 'save_profile':
        $id = intval($_POST['id'] ?? 0);
        $nombre = $_POST['nombre'] ?? '';
        $direccion = $_POST['direccion'] ?? '';
        $notas = $_POST['notas'] ?? '';
        $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : null;

        if ($id > 0) {
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
