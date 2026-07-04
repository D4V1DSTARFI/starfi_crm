<?php
// Script to clean up duplicate entries in empresa_perfil
$servidor_sb = "192.168.0.71";
$usuario_sb = "starfi_v2_user";
$contrasenha_sb = md5("PARALELEPIPEDO3312");

echo "=== CLEANING UP DUPLICATE EMPRESA_PERFIL ROWS ===\n";

// Conectar a Sandbox
$con_sb = mysqli_connect($servidor_sb, $usuario_sb, $contrasenha_sb, "starfi");
if ($con_sb) {
    echo "Connected to Sandbox Server successfully.\n";
    $q = "DELETE t1 FROM empresa_perfil t1
          INNER JOIN empresa_perfil t2 
          WHERE t1.id > t2.id AND t1.id_sede = t2.id_sede";
    $res = mysqli_query($con_sb, $q);
    if ($res) {
        echo "  - Cleaned Sandbox empresa_perfil successfully. Affected: " . mysqli_affected_rows($con_sb) . " rows.\n";
    } else {
        echo "  - Error: " . mysqli_error($con_sb) . "\n";
    }
    mysqli_close($con_sb);
} else {
    echo "Failed to connect to Sandbox Server.\n";
}

// Conectar a Local (en caso de que exista la base de datos local starfi con empresa_perfil)
$con_local = mysqli_connect("localhost", "root", "", "starfi");
if ($con_local) {
    echo "Connected to Local starfi database.\n";
    $q = "DELETE t1 FROM empresa_perfil t1
          INNER JOIN empresa_perfil t2 
          WHERE t1.id > t2.id AND t1.id_sede = t2.id_sede";
    $res = mysqli_query($con_local, $q);
    if ($res) {
        echo "  - Cleaned Local empresa_perfil successfully. Affected: " . mysqli_affected_rows($con_local) . " rows.\n";
    } else {
        echo "  - Error: " . mysqli_error($con_local) . "\n";
    }
    mysqli_close($con_local);
} else {
    echo "Local starfi database not found or connection failed.\n";
}

echo "=== CLEANUP COMPLETED ===\n";
?>
