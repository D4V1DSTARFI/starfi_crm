<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

$agente = getAgenteInfo();
$rol = $agente['rol'] ?? 'AGENTE';
$user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;

if ($rol !== 'MASTER' && $user_sede > 0) {
    $_POST['id_sede'] = $user_sede;
    $_REQUEST['id_sede'] = $user_sede;
}

switch ($action) {
    case 'load_rules':
        $id_sede = intval($_POST['id_sede'] ?? 0);
        $where = "id_empresa = 1";
        if ($id_sede > 0) {
            $where .= " AND id_sede = $id_sede";
        }
        $query = "SELECT * FROM bot_respuestas WHERE $where ORDER BY tipo ASC, id ASC";
        $res = $con->query($query);
        $rules = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rules[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $rules]);
        break;

    case 'save_rule':
        $id = intval($_POST['id'] ?? 0);
        $id_sede = intval($_POST['id_sede'] ?? 0);
        $tipo = $_POST['tipo'] ?? '';
        $disparador = $_POST['disparador'] ?? '';
        $mensaje = $_POST['mensaje'] ?? '';
        $estado = $_POST['estado'] ?? 'ACTIVO';
        
        // Nuevos campos para Flujos Avanzados
        $id_padre = !empty($_POST['id_padre']) ? intval($_POST['id_padre']) : null;
        $formato_respuesta = $_POST['formato_respuesta'] ?? 'TEXTO';
        $media_url = !empty($_POST['media_url']) ? $_POST['media_url'] : null;
        $latitud = !empty($_POST['latitud']) ? $_POST['latitud'] : null;
        $longitud = !empty($_POST['longitud']) ? $_POST['longitud'] : null;
        $espera_respuesta = isset($_POST['espera_respuesta']) && $_POST['espera_respuesta'] == '1' ? 1 : 0;
        
        if (empty($tipo) || empty($disparador) || empty($mensaje) || $id_sede == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos o falta seleccionar Sede.']);
            exit;
        }

        if ($id > 0) {
            $stmt = $con->prepare("UPDATE bot_respuestas SET id_sede = ?, id_padre = ?, tipo = ?, formato_respuesta = ?, disparador = ?, mensaje = ?, media_url = ?, latitud = ?, longitud = ?, espera_respuesta = ?, estado = ? WHERE id = ?");
            $stmt->bind_param("iisssssssisi", $id_sede, $id_padre, $tipo, $formato_respuesta, $disparador, $mensaje, $media_url, $latitud, $longitud, $espera_respuesta, $estado, $id);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Regla actualizada.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Fallo al actualizar.']);
            }
        } else {
            $id_empresa = 1;
            $stmt = $con->prepare("INSERT INTO bot_respuestas (id_empresa, id_sede, id_padre, tipo, formato_respuesta, disparador, mensaje, media_url, latitud, longitud, espera_respuesta, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiisssssssis", $id_empresa, $id_sede, $id_padre, $tipo, $formato_respuesta, $disparador, $mensaje, $media_url, $latitud, $longitud, $espera_respuesta, $estado);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Regla creada con éxito.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Fallo al crear.']);
            }
        }
        break;

    case 'delete_rule':
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $con->query("DELETE FROM bot_respuestas WHERE id = $id");
            echo json_encode(['status' => 'success', 'message' => 'Eliminada correctamente.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ID inválido.']);
        }
        break;

    case 'update_network':
        $nodes_json = $_POST['nodes'] ?? '[]';
        $nodes = json_decode($nodes_json, true);
        
        if (is_array($nodes)) {
            $success_count = 0;
            $stmt = $con->prepare("UPDATE bot_respuestas SET id_padre = ? WHERE id = ?");
            foreach ($nodes as $n) {
                $db_id = intval($n['id']);
                $id_padre = !empty($n['id_padre']) ? intval($n['id_padre']) : null;
                if ($db_id > 0) {
                    $stmt->bind_param("ii", $id_padre, $db_id);
                    if($stmt->execute()) $success_count++;
                }
            }
            echo json_encode(['status' => 'success', 'message' => "Árbol actualizado ($success_count nodos)."]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'JSON inválido.']);
        }
        break;

    case 'toggle_bot':
        $id_sede = intval($_POST['id_sede'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if ($id_sede > 0) {
            $stmt = $con->prepare("UPDATE sedes SET bot_activo = ? WHERE id = ?");
            $stmt->bind_param("ii", $status, $id_sede);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar base de datos.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Sede inválida.']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción inválida.']);
        break;
}
?>
