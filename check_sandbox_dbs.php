<?php
$servidor = "192.168.0.71";
$usuario = "starfi_v2_user";
$contrasenha = md5("PARALELEPIPEDO3312");

$con = mysqli_connect($servidor, $usuario, $contrasenha);
if ($con) {
    echo "--- SANDBOX SERVER DATABASES ---\n";
    $res = mysqli_query($con, "SHOW DATABASES");
    if ($res) {
        while ($row = mysqli_fetch_row($res)) {
            echo $row[0] . "\n";
        }
    } else {
        echo "Error: " . mysqli_error($con) . "\n";
    }
} else {
    echo "Failed to connect to Sandbox server $servidor.\n";
}
?>
