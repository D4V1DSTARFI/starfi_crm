<?php
$servidor = "192.168.0.71";
$usuario = "starfi_v2_user";
$contrasenha = md5("PARALELEPIPEDO3312");

$con = mysqli_connect($servidor, $usuario, $contrasenha, "starfi_ventas");
if ($con) {
    echo "--- SANDBOX 'config_api_wsap' RECORDS ---\n";
    $res = mysqli_query($con, "SELECT * FROM config_api_wsap");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            print_r($row);
        }
    } else {
        echo "Error: " . mysqli_error($con) . "\n";
    }
} else {
    echo "Failed to connect to Sandbox server.\n";
}
?>
