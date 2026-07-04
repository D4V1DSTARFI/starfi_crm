<?php
$servidor = "192.168.0.70";
$usuario = "starfi_user";
$contrasenha = "33aea3c20d201fb0fe3cf8fa74db4675";

echo "=== CONNECTING TO starfi_vent ===\n";
$con_vent = @mysqli_connect($servidor, $usuario, $contrasenha, "starfi_vent");
if ($con_vent) {
    $res = mysqli_query($con_vent, "SHOW TABLES LIKE 'usuario'");
    if ($res && mysqli_num_rows($res) > 0) {
        echo "usuario table exists in starfi_vent.\n";
        $res_cols = mysqli_query($con_vent, "SHOW COLUMNS FROM usuario");
        while ($row = mysqli_fetch_assoc($res_cols)) {
            echo "  Field: {$row['Field']} | Type: {$row['Type']}\n";
        }
    } else {
        echo "usuario table does NOT exist in starfi_vent.\n";
    }
    mysqli_close($con_vent);
} else {
    echo "Could not connect to starfi_vent: " . mysqli_connect_error() . "\n";
}

echo "\n=== CONNECTING TO starfi_starfi ===\n";
$con_star = @mysqli_connect($servidor, $usuario, $contrasenha, "starfi_starfi");
if ($con_star) {
    $res = mysqli_query($con_star, "SHOW TABLES LIKE 'usuario'");
    if ($res && mysqli_num_rows($res) > 0) {
        echo "usuario table exists in starfi_starfi.\n";
        $res_cols = mysqli_query($con_star, "SHOW COLUMNS FROM usuario");
        while ($row = mysqli_fetch_assoc($res_cols)) {
            echo "  Field: {$row['Field']} | Type: {$row['Type']}\n";
        }
    } else {
        echo "usuario table does NOT exist in starfi_starfi.\n";
    }
    mysqli_close($con_star);
} else {
    echo "Could not connect to starfi_starfi: " . mysqli_connect_error() . "\n";
}
?>
