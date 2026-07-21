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
$agente_id = intval($_SESSION['agente_id']);
$last_check = date('Y-m-d H:i:s', time() - 2); // 2 segundos atrás

// Mantener la conexión abierta por un máximo de 60 segundos para evitar procesos colgados
$max_loops = 30; 

for ($i = 0; $i < $max_loops; $i++) {
    
    // Verificar si hay mensajes nuevos en cualquier conversación asociada al agente o que esté en espera
    $query = "
        SELECT m.id 
        FROM mensajes_y_eventos m
        JOIN conversaciones c ON m.id_conversacion = c.id
        WHERE m.updated_at > '$last_check' 
        AND (c.id_agente = $agente_id OR c.estado = 'ESPERA_ASIGNACION')
        LIMIT 1
    ";
    
    $res = $con->query($query);
    
    if ($res && $res->num_rows > 0) {
        $last_check = date('Y-m-d H:i:s');
        echo "data: {\"type\": \"update\"}\n\n";
        ob_flush();
        flush();
    }
    
    echo ": ping\n\n"; // Comentario SSE para mantener viva la conexión
    ob_flush();
    flush();
    
    sleep(2); // Esperar 2 segundos antes de volver a consultar
}

// Le decimos al cliente que se reconecte
echo "data: {\"type\": \"reconnect\"}\n\n";
ob_flush();
flush();
?>
