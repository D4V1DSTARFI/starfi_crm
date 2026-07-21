<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

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
        
        if (empty($tipo) || empty($disparador) || empty($mensaje) || $id_sede == 0) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos o falta seleccionar Sede.']);
            exit;
        }

        if ($id > 0) {
            $stmt = $con->prepare("UPDATE bot_respuestas SET id_sede = ?, tipo = ?, disparador = ?, mensaje = ?, estado = ? WHERE id = ?");
            $stmt->bind_param("issssi", $id_sede, $tipo, $disparador, $mensaje, $estado, $id);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Regla actualizada.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Fallo al actualizar.']);
            }
        } else {
            $id_empresa = 1;
            $stmt = $con->prepare("INSERT INTO bot_respuestas (id_empresa, id_sede, tipo, disparador, mensaje, estado) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissss", $id_empresa, $id_sede, $tipo, $disparador, $mensaje, $estado);
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
