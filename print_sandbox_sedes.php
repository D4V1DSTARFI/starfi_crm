<?php
$servidor_sb = "192.168.0.71";
$usuario_sb = "starfi_v2_user";
$contrasenha_sb = md5("PARALELEPIPEDO3312");

$con_sb = mysqli_connect($servidor_sb, $usuario_sb, $contrasenha_sb, "starfi");
if ($con_sb) {
    echo "=== SANDBOX starfi.sede ROWS ===\n";
    $res = mysqli_query($con_sb, "SELECT id, descripcion FROM sede ORDER BY id");
    while ($row = mysqli_fetch_assoc($res)) {
        echo "ID {$row['id']}: {$row['descripcion']}\n";
    }
    
    echo "\n=== SANDBOX starfi.empresa_perfil ROWS ===\n";
    $res = mysqli_query($con_sb, "SELECT id_sede, razon_social, rif FROM empresa_perfil ORDER BY id_sede");
    while ($row = mysqli_fetch_assoc($res)) {
        echo "Sede {$row['id_sede']}: {$row['razon_social']} (RIF: {$row['rif']})\n";
    }
    mysqli_close($con_sb);
}
?>
