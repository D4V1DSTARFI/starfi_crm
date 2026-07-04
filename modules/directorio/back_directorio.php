<?php
// modules/directorio/back_directorio.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'load_clients':
        $query = "SELECT id, nombre, numero_whatsapp, estado, fecha_registro FROM clientes_contactos ORDER BY fecha_registro DESC";
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
        $stmt = $con->prepare("SELECT * FROM clientes_contactos WHERE id = ?");
        
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

        if ($id > 0) {
            $stmt = $con->prepare("UPDATE clientes_contactos SET nombre = ?, direccion = ?, notas_internas = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("sssi", $nombre, $direccion, $notas, $id);
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
        if (empty($numero_whatsapp)) {
            echo json_encode(['status' => 'error', 'message' => 'Número vacío']);
            exit;
        }
        
        $stmt = $con->prepare("SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = ? LIMIT 1");
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
        $numero_whatsapp = preg_replace('/[^0-9]/', '', $_POST['numero_whatsapp'] ?? '');
        
        if (empty($numero_whatsapp) || empty($nombre)) {
            echo json_encode(['status' => 'error', 'message' => 'Nombre y número son obligatorios']);
            exit;
        }

        // Asignar al ID de empresa 1 (prototipo)
        $id_empresa = 1;

        $stmt = $con->prepare("INSERT INTO clientes_contactos (id_empresa, numero_whatsapp, nombre, direccion, notas_internas) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("issss", $id_empresa, $numero_whatsapp, $nombre, $direccion, $notas);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Cliente creado con éxito']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error: El número de WhatsApp ya podría estar registrado.']);
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
