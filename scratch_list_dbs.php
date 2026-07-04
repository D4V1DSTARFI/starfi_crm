<?php
$servidor = "192.168.0.70";
$usuario = "starfi_user";
$contrasenha = "33aea3c20d201fb0fe3cf8fa74db4675";

$con = @mysqli_connect($servidor, $usuario, $contrasenha);
if ($con) {
    echo "Connected to 192.168.0.70. Databases:\n";
    $res = mysqli_query($con, "SHOW DATABASES");
    while ($row = mysqli_fetch_row($res)) {
        echo "  {$row[0]}\n";
    }
    mysqli_close($con);
} else {
    echo "Could not connect to 192.168.0.70: " . mysqli_connect_error() . "\n";
}
?>
