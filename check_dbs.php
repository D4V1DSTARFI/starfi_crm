<?php
$con = mysqli_connect("localhost", "root", "");
if ($con) {
    echo "--- LOCAL DATABASES ---\n";
    $res = mysqli_query($con, "SHOW DATABASES");
    while ($row = mysqli_fetch_row($res)) {
        echo $row[0] . "\n";
    }
} else {
    echo "Failed to connect to local MySQL.\n";
}
?>
