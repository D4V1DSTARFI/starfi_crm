<?php
require 'core/auth.php';
$con = getDbConnection();
$res = $con->query("SELECT * FROM waba_ordenes_cobro LIMIT 1");
print_r($res->fetch_assoc());
