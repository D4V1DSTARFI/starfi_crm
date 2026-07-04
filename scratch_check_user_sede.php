<?php
$servidor = "192.168.0.70";
$usuario = "starfi_user";
$contrasenha = "33aea3c20d201fb0fe3cf8fa74db4675";
$bd = "starfi";

$con = @mysqli_connect($servidor, $usuario, $contrasenha, $bd);
if ($con) {
    echo "Connected to $bd on $servidor.\n";
    
    // Check user 1
    $res = mysqli_query($con, "SELECT u.id, u.usuario, u.id_sede, s.descripcion as nombre_sede 
                               FROM usuario u 
                               LEFT JOIN sede s ON u.id_sede = s.id 
                               WHERE u.id = 1");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        echo "User ID: {$row['id']} | Username: {$row['usuario']} | Sede ID: {$row['id_sede']} | Sede Name: {$row['nombre_sede']}\n";
    } else {
        echo "Failed to fetch user or no user found: " . mysqli_error($con) . "\n";
    }
    
    // Dump all users and their sedes
    echo "\n=== ALL USERS ===\n";
    $res = mysqli_query($con, "SELECT u.id, u.usuario, u.id_sede, s.descripcion as nombre_sede 
                               FROM usuario u 
                               LEFT JOIN sede s ON u.id_sede = s.id");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            echo "User ID: {$row['id']} | Username: {$row['usuario']} | Sede ID: {$row['id_sede']} | Sede Name: {$row['nombre_sede']}\n";
        }
    }
    
    mysqli_close($con);
} else {
    echo "Could not connect to $bd on $servidor: " . mysqli_connect_error() . "\n";
}
?>
