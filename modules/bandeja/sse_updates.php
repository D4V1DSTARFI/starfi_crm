<?php
// modules/bandeja/sse_updates.php
@ini_set('display_errors', '0');
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();

// Leer información de rol y sede ANTES de cerrar la sesión
$agente_info = getAgenteInfo();
$agente_id = intval($agente_info['id'] ?? ($_SESSION['agente_id'] ?? 0));
$rol_raw = strtoupper(trim($agente_info['rol'] ?? ''));
$is_master = ($agente_id === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI');
$is_admin = (!$is_master && ($rol_raw === 'ADMINISTRADOR' || $rol_raw === 'ADMIN' || $rol_raw === 'GERENTE'));
$is_operador = (!$is_master && !$is_admin);
$user_sede = intval($agente_info['id_sede'] ?? 0);

session_write_close(); // Liberar la sesión para permitir otras peticiones AJAX simultáneas

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$con = getDbConnection();
if (!$con) {
    echo "data: {\"type\": \"reconnect\"}\n\n";
    @ob_flush();
    @flush();
    exit;
}

if (!$is_master && $user_sede <= 0) {
    echo "data: {\"type\": \"reconnect\"}\n\n";
    @ob_flush();
    @flush();
    exit;
}

// Obtener el último ID global de mensaje (lookup O(1) por PRIMARY KEY)
$res_init = $con->query("SELECT IFNULL(MAX(id), 0) AS max_id FROM mensajes_y_eventos");
$last_msg_id = ($res_init && ($row_init = $res_init->fetch_assoc())) ? intval($row_init['max_id']) : 0;

// Piso fijo para monitoreo ligero de cambios en estado_envio / error_detalle / asignación
$status_floor_id = max(0, $last_msg_id - 400);

$computeStatusSignature = function () use ($con, $status_floor_id, $is_master, $is_admin, $user_sede, $agente_id) {
    $base_select = "
        SELECT COUNT(*) AS cnt,
               COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', m.id, IFNULL(m.estado_envio, ''), IFNULL(m.error_detalle, ''), IFNULL(c.estado, ''), IFNULL(c.id_agente, 0)))), 0) AS sig_xor,
               COALESCE(SUM(CRC32(CONCAT_WS('|', m.id, IFNULL(m.estado_envio, ''), IFNULL(m.error_detalle, ''), IFNULL(c.estado, ''), IFNULL(c.id_agente, 0)))), 0) AS sig_sum
        FROM mensajes_y_eventos m
        JOIN conversaciones c ON m.id_conversacion = c.id
        JOIN lineas_whatsapp l ON c.id_linea = l.id
    ";
    if ($is_master) {
        $stmt_sig = $con->prepare($base_select . " WHERE m.id > ?");
        if ($stmt_sig) {
            $stmt_sig->bind_param("i", $status_floor_id);
        }
    } elseif ($is_admin) {
        $stmt_sig = $con->prepare($base_select . " WHERE m.id > ? AND l.id_sede = ?");
        if ($stmt_sig) {
            $stmt_sig->bind_param("ii", $status_floor_id, $user_sede);
        }
    } else {
        $stmt_sig = $con->prepare($base_select . " WHERE m.id > ? AND l.id_sede = ? AND (c.id_agente = ? OR c.estado = 'ESPERA_ASIGNACION')");
        if ($stmt_sig) {
            $stmt_sig->bind_param("iii", $status_floor_id, $user_sede, $agente_id);
        }
    }

    if (isset($stmt_sig) && $stmt_sig && $stmt_sig->execute()) {
        $res_sig = $stmt_sig->get_result();
        if ($res_sig && ($row_sig = $res_sig->fetch_assoc())) {
            $stmt_sig->close();
            return $row_sig['cnt'] . ':' . $row_sig['sig_xor'] . ':' . $row_sig['sig_sum'];
        }
        $stmt_sig->close();
    }
    return '0:0:0';
};

$last_status_sig = $computeStatusSignature();

// Mantener la conexión abierta por un máximo de 60 segundos para evitar procesos colgados
$max_loops = 20;

for ($i = 0; $i < $max_loops; $i++) {
    if (connection_aborted()) {
        break;
    }

    // Mantener presencia en línea actualizada para enrutamiento Round-Robin ([COM-03])
    if (($i === 0 || $i === 10) && $agente_id > 1) {
        starfi_touch_agent_presence($con, $agente_id, true);
    }

    $has_new_msg = false;
    $base_new = "
        SELECT MAX(m.id) AS nuevo_max_id
        FROM mensajes_y_eventos m
        JOIN conversaciones c ON m.id_conversacion = c.id
        JOIN lineas_whatsapp l ON c.id_linea = l.id
    ";
    if ($is_master) {
        $stmt_new = $con->prepare($base_new . " WHERE m.id > ?");
        if ($stmt_new) {
            $stmt_new->bind_param("i", $last_msg_id);
        }
    } elseif ($is_admin) {
        $stmt_new = $con->prepare($base_new . " WHERE m.id > ? AND l.id_sede = ?");
        if ($stmt_new) {
            $stmt_new->bind_param("ii", $last_msg_id, $user_sede);
        }
    } else {
        $stmt_new = $con->prepare($base_new . " WHERE m.id > ? AND l.id_sede = ? AND (c.id_agente = ? OR c.estado = 'ESPERA_ASIGNACION')");
        if ($stmt_new) {
            $stmt_new->bind_param("iii", $last_msg_id, $user_sede, $agente_id);
        }
    }

    $row_new = null;
    if (isset($stmt_new) && $stmt_new && $stmt_new->execute()) {
        $res = $stmt_new->get_result();
        $row_new = $res ? $res->fetch_assoc() : null;
        $stmt_new->close();
    }

    if ($row_new && !empty($row_new['nuevo_max_id'])) {
        $last_msg_id = intval($row_new['nuevo_max_id']);
        $has_new_msg = true;
    } else {
        $res_max = $con->query("SELECT IFNULL(MAX(id), 0) AS max_id FROM mensajes_y_eventos");
        if ($res_max && ($rm = $res_max->fetch_assoc())) {
            $last_msg_id = max($last_msg_id, intval($rm['max_id']));
        }
    }

    $current_status_sig = $computeStatusSignature();

    if ($has_new_msg) {
        $last_status_sig = $current_status_sig;
        echo "data: {\"type\": \"update\", \"reason\": \"new_message\"}\n\n";
        @ob_flush();
        @flush();
    } elseif ($current_status_sig !== $last_status_sig) {
        $last_status_sig = $current_status_sig;
        echo "data: {\"type\": \"update\", \"reason\": \"status_change\"}\n\n";
        @ob_flush();
        @flush();
    }

    echo ": ping\n\n";
    @ob_flush();
    @flush();

    sleep(3);
}

echo "data: {\"type\": \"reconnect\"}\n\n";
@ob_flush();
@flush();
?>
