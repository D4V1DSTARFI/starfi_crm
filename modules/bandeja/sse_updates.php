<?php
// modules/bandeja/sse_updates.php
require_once __DIR__ . '/../../core/auth.php';
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
// Deshabilitar buffering en nginx/apache si aplica
header('X-Accel-Buffering: no');

$con = getDbConnection();
if (!$con) {
    echo "data: {\"type\": \"reconnect\"}\n\n";
    @ob_flush();
    @flush();
    exit;
}

// Construir filtro de visibilidad estricto por Rol y Sede
if ($is_master) {
    $visibility_where = "1=1";
} elseif ($user_sede <= 0) {
    $visibility_where = "0=1";
} elseif ($is_admin) {
    // Admin / Gerente recibe eventos de todos los chats de su sede (incluyendo ATENDIENDO de sus operadores)
    $visibility_where = "l.id_sede = $user_sede";
} else {
    // Operador solo recibe eventos de sus chats asignados o en espera de asignación dentro de su propia sede
    $visibility_where = "l.id_sede = $user_sede AND (c.id_agente = $agente_id OR c.estado = 'ESPERA_ASIGNACION')";
}

// Obtener el último ID global de mensaje (lookup O(1) por PRIMARY KEY)
$res_init = $con->query("SELECT IFNULL(MAX(id), 0) AS max_id FROM mensajes_y_eventos");
$last_msg_id = ($res_init && ($row_init = $res_init->fetch_assoc())) ? intval($row_init['max_id']) : 0;

// Piso fijo para monitoreo ligero de cambios en estado_envio / error_detalle / asignación (evita falsos positivos por ventana deslizante)
$status_floor_id = max(0, $last_msg_id - 400);

$computeStatusSignature = function () use ($con, $status_floor_id, $visibility_where) {
    $q_sig = "
        SELECT COUNT(*) AS cnt,
               COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', m.id, IFNULL(m.estado_envio, ''), IFNULL(m.error_detalle, ''), IFNULL(c.estado, ''), IFNULL(c.id_agente, 0)))), 0) AS sig_xor,
               COALESCE(SUM(CRC32(CONCAT_WS('|', m.id, IFNULL(m.estado_envio, ''), IFNULL(m.error_detalle, ''), IFNULL(c.estado, ''), IFNULL(c.id_agente, 0)))), 0) AS sig_sum
        FROM mensajes_y_eventos m
        JOIN conversaciones c ON m.id_conversacion = c.id
        JOIN lineas_whatsapp l ON c.id_linea = l.id
        WHERE m.id > $status_floor_id
          AND ($visibility_where)
    ";
    $res_sig = $con->query($q_sig);
    if ($res_sig && ($row_sig = $res_sig->fetch_assoc())) {
        return $row_sig['cnt'] . ':' . $row_sig['sig_xor'] . ':' . $row_sig['sig_sum'];
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

    $has_new_msg = false;
    $query_new = "
        SELECT MAX(m.id) AS nuevo_max_id
        FROM mensajes_y_eventos m
        JOIN conversaciones c ON m.id_conversacion = c.id
        JOIN lineas_whatsapp l ON c.id_linea = l.id
        WHERE m.id > $last_msg_id
          AND ($visibility_where)
    ";

    $res = $con->query($query_new);
    if ($res && ($row = $res->fetch_assoc()) && !empty($row['nuevo_max_id'])) {
        $last_msg_id = intval($row['nuevo_max_id']);
        $has_new_msg = true;
    } else {
        // Avanzar el puntero global si entraron mensajes de otras sedes/agentes para mantener el rango pequeño
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

    echo ": ping\n\n"; // Comentario SSE para mantener viva la conexión
    @ob_flush();
    @flush();

    sleep(3); // Esperar 3 segundos antes de volver a consultar
}

// Le decimos al cliente que se reconecte
echo "data: {\"type\": \"reconnect\"}\n\n";
@ob_flush();
@flush();
?>
