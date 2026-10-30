<?php
require 'c:/xampp/htdocs/starfi_crm/core/auth.php';
$con = getDbConnection();
$res = $con->query("DESCRIBE waba_ordenes_cobro");
while($row = $res->fetch_assoc()) {
    if($row['Field'] == 'estado') {
        print_r($row);
    }
}
