<?php
require_once('config/database.php');
$con = getDbConnection();

echo "Últimos Mensajes:\n";
$res = mysqli_query($con, "SELECT id, id_conversacion, origen, contenido, timestamp FROM mensajes_y_eventos ORDER BY id DESC LIMIT 5");
while ($row = mysqli_fetch_assoc($res)) {
    print_r($row);
}

echo "\nConversaciones Recientes:\n";
$res = mysqli_query($con, "SELECT id, id_cliente, id_agente, estado, mensajes_no_leidos FROM conversaciones ORDER BY id DESC LIMIT 3");
while ($row = mysqli_fetch_assoc($res)) {
    print_r($row);
}
