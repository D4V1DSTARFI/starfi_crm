<?php 
require_once 'c:\xampp\htdocs\starfi_crm\core\auth.php'; 
$con=getDbConnection(); 
$query = "
            SELECT m.id, m.tipo, m.origen, m.contenido, m.timestamp, m.estado_envio, m.url_archivo, m.reply_to_text, m.id_mensaje_meta, a.nombre_completo as nombre_agente 
            FROM mensajes_y_eventos m
            LEFT JOIN usuarios_agentes a ON m.id_agente = a.id
            WHERE m.id_conversacion = ? 
            ORDER BY m.timestamp ASC
        ";
$stmt=$con->prepare($query); 
if(!$stmt) echo 'Error: '.$con->error; 
else echo 'OK';
?>
