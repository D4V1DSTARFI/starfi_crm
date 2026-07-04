<?php
require_once __DIR__ . '/../starfi2.0/config/database.php';
$con = getDbConnection('ventas');
if ($con) {
    echo "=== COLUMNS IN starfi_ventas.venta ===\n";
    $res = mysqli_query($con, "SHOW COLUMNS FROM venta");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            echo "Field: {$row['Field']} | Type: {$row['Type']}\n";
        }
    } else {
        echo "Error: " . mysqli_error($con) . "\n";
    }
    mysqli_close($con);
}
?>
