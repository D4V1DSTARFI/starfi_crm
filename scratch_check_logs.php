<?php
$con = mysqli_connect("localhost", "root", "", "starfi_crm");
if ($con) {
    echo "=== LATEST 10 NOTIFICATIONS IN CRM ===\n";
    $res = mysqli_query($con, "SELECT * FROM notificacion_enviada ORDER BY id DESC LIMIT 10");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            echo "ID: {$row['id']} | Date: {$row['fecha']} {$row['hora']} | Emisor: {$row['telefono_emisor']} | Receptor: {$row['telefono_receptor']} | Status: {$row['status']} | Content: " . substr($row['contenido'], 0, 100) . "...\n";
        }
    } else {
        echo "Error: " . mysqli_error($con) . "\n";
    }
    mysqli_close($con);
}
?>
