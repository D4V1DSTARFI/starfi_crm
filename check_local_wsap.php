<?php
$con = mysqli_connect("localhost", "root", "", "starfi_ventas");
if ($con) {
    echo "--- 'config_api_wsap' SCHEMA IN 'starfi_ventas' ---\n";
    $res = mysqli_query($con, "DESCRIBE config_api_wsap");
    while ($row = mysqli_fetch_assoc($res)) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }

    echo "\n--- 'config_api_wsap' RECORDS IN 'starfi_ventas' ---\n";
    $res2 = mysqli_query($con, "SELECT * FROM config_api_wsap");
    while ($row = mysqli_fetch_assoc($res2)) {
        print_r($row);
    }
}
?>
