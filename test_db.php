<?php 
require_once 'c:\xampp\htdocs\starfi_crm\core\auth.php'; 
$con=getDbConnection(); 
$q='ALTER TABLE starfi_crm.mensajes_y_eventos ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'; 
$con->query($q); 
echo "Error if any: " . $con->error; 
?>
