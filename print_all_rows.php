<?php
$servidor_sb = "192.168.0.71";
$usuario_sb = "starfi_v2_user";
$contrasenha_sb = md5("PARALELEPIPEDO3312");

$con_sb = mysqli_connect($servidor_sb, $usuario_sb, $contrasenha_sb);
if ($con_sb) {
    mysqli_select_db($con_sb, "starfi_ventas");
    echo "=== SANDBOX config_api_wsap ROWS ===\n";
    $res = mysqli_query($con_sb, "SELECT id, id_sede, phone_number, api_url FROM config_api_wsap ORDER BY id_sede, id");
    while ($row = mysqli_fetch_assoc($res)) {
        echo "ID {$row['id']} - Sede {$row['id_sede']} - Phone: {$row['phone_number']} - Url: {$row['api_url']}\n";
    }
    
    echo "\n=== SANDBOX config_api_wsap_plantillas ROWS ===\n";
    $res = mysqli_query($con_sb, "SELECT id, id_sede, modulo, plantilla FROM config_api_wsap_plantillas ORDER BY id_sede, modulo, id");
    while ($row = mysqli_fetch_assoc($res)) {
        echo "ID {$row['id']} - Sede {$row['id_sede']} - Modulo: {$row['modulo']} - Plantilla: {$row['plantilla']}\n";
    }
    mysqli_close($con_sb);
}

$con_local = mysqli_connect("localhost", "root", "");
if ($con_local) {
    mysqli_select_db($con_local, "starfi_crm");
    echo "\n=== LOCAL CRM lineas_whatsapp ROWS ===\n";
    $res = mysqli_query($con_local, "SELECT id, id_sede, descripcion, numero_telefono FROM lineas_whatsapp ORDER BY id_sede, id");
    while ($row = mysqli_fetch_assoc($res)) {
        echo "ID {$row['id']} - Sede {$row['id_sede']} - Desc: {$row['descripcion']} - Phone: {$row['numero_telefono']}\n";
    }
    
    mysqli_select_db($con_local, "starfi_ventas");
    echo "\n=== LOCAL starfi_ventas config_api_wsap ROWS ===\n";
    $res = mysqli_query($con_local, "SELECT id, id_sede, phone_number, api_url FROM config_api_wsap ORDER BY id_sede, id");
    while ($row = mysqli_fetch_assoc($res)) {
        echo "ID {$row['id']} - Sede {$row['id_sede']} - Phone: {$row['phone_number']} - Url: {$row['api_url']}\n";
    }
    
    mysqli_close($con_local);
}
?>
