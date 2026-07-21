<?php 
require_once 'c:\xampp\htdocs\starfi_crm\core\auth.php'; 
$con=getDbConnection(); 
$q='SHOW COLUMNS FROM starfi_crm.conversaciones'; 
$r=$con->query($q); 
while($row=$r->fetch_assoc()) echo $row['Field'] . "\n"; 
?>
