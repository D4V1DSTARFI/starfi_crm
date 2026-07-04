<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'load_rules':
        $query = "SELECT * FROM bot_respuestas WHERE id_empresa = 1 ORDER BY tipo ASC, id ASC";
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
        $tipo = $_POST['tipo'] ?? '';
        $disparador = $_POST['disparador'] ?? '';
        $mensaje = $_POST['mensaje'] ?? '';
        $estado = $_POST['estado'] ?? 'ACTIVO';
        
        if (empty($tipo) || empty($disparador) || empty($mensaje)) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos.']);
            exit;
        }

        if ($id > 0) {
            $stmt = $con->prepare("UPDATE bot_respuestas SET tipo = ?, disparador = ?, mensaje = ?, estado = ? WHERE id = ?");
            $stmt->bind_param("ssssi", $tipo, $disparador, $mensaje, $estado, $id);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Regla actualizada.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Fallo al actualizar.']);
            }
        } else {
            $id_empresa = 1;
            $stmt = $con->prepare("INSERT INTO bot_respuestas (id_empresa, tipo, disparador, mensaje, estado) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $id_empresa, $tipo, $disparador, $mensaje, $estado);
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

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción inválida.']);
        break;
}
?>
