<?php 
require_once 'c:\xampp\htdocs\starfi_crm\core\auth.php'; 
$con=getDbConnection(); 
$q='SHOW COLUMNS FROM starfi_crm.usuario'; 
$r=$con->query($q); 
while($row=$r->fetch_assoc()) echo $row['Field'] . "\n"; 
echo "--\n";
$q2='SHOW COLUMNS FROM starfi_crm.usuario_perfil'; 
$r2=$con->query($q2); 
while($row2=$r2->fetch_assoc()) echo $row2['Field'] . "\n";
?>
