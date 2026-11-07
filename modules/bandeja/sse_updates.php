<?php
// modules/bandeja/sse_updates.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
session_write_close(); // Liberar la sesión para permitir otras peticiones AJAX simultáneas

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
// Deshabilitar buffering en nginx/apache si aplica
header('X-Accel-Buffering: no');

$con = getDbConnection();
$agente_id = intval($_SESSION['agente_id'] ?? 0);

// Obtener el último ID de mensaje (lookup O(1) por PRIMARY KEY) para evitar full table scans cada 2s
$res_init = $con->query("SELECT IFNULL(MAX(id), 0) AS max_id FROM mensajes_y_eventos");
$last_msg_id = ($res_init && $row_init = $res_init->fetch_assoc()) ? intval($row_init['max_id']) : 0;

// Mantener la conexión abierta por un máximo de 60 segundos para evitar procesos colgados
$max_loops = 30; 

for ($i = 0; $i < $max_loops; $i++) {
    // Consulta ultrarrápida usando índice primario (m.id > $last_msg_id)
    $query = "
        SELECT MAX(m.id) AS nuevo_max_id
        FROM mensajes_y_eventos m
        JOIN conversaciones c ON m.id_conversacion = c.id
        WHERE m.id > $last_msg_id
          AND (c.id_agente = $agente_id OR c.estado = 'ESPERA_ASIGNACION' OR $agente_id = 1)
    ";
    
    $res = $con->query($query);
    if ($res && ($row = $res->fetch_assoc()) && !empty($row['nuevo_max_id'])) {
        $last_msg_id = intval($row['nuevo_max_id']);
        echo "data: {\"type\": \"update\"}\n\n";
        @ob_flush();
        @flush();
    } else {
        // Avanzar el puntero global si entraron mensajes de otros agentes para mantener el rango pequeño
        $res_max = $con->query("SELECT IFNULL(MAX(id), 0) AS max_id FROM mensajes_y_eventos");
        if ($res_max && ($rm = $res_max->fetch_assoc())) {
            $last_msg_id = max($last_msg_id, intval($rm['max_id']));
        }
    }
    
    echo ": ping\n\n"; // Comentario SSE para mantener viva la conexión
    @ob_flush();
    @flush();
    
    sleep(3); // Esperar 3 segundos antes de volver a consultar
}

// Le decimos al cliente que se reconecte
echo "data: {\"type\": \"reconnect\"}\n\n";
ob_flush();
flush();
?>
