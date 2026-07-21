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

// Obtener la hora actual de la base de datos para evitar desfasajes con PHP
$res_time = $con->query("SELECT NOW() - INTERVAL 2 SECOND as db_time");
$row_time = $res_time->fetch_assoc();
$last_check = $row_time['db_time'];

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
        // Actualizar last_check con la hora de la DB
        $res_time = $con->query("SELECT NOW() as db_time");
        $row_time = $res_time->fetch_assoc();
        $last_check = $row_time['db_time'];
        
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
