<?php 
require_once 'c:\xampp\htdocs\starfi_crm\core\auth.php'; 
$con=getDbConnection(); 
$q="SELECT * FROM mensajes_y_eventos ORDER BY id DESC LIMIT 5"; 
$r=$con->query($q); 
while($row=$r->fetch_assoc()) echo json_encode($row) . "\n";
?>
