<?php
require 'c:/xampp/htdocs/starfi_crm/config/database.php';
$con = getDbConnection();
$res = $con->query("SELECT * FROM lineas_whatsapp");
if ($res) print_r($res->fetch_all(MYSQLI_ASSOC));
else echo "Error: " . $con->error;
