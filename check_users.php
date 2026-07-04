<?php
require 'C:/xampp/htdocs/starfi2.0/config/database.php';

$con = getDbConnection('core');
echo "--- SANDBOX USERS AND THEIR ASSIGNED BRANCHES (id_sede) ---\n";
$res = mysqli_query($con, "SELECT u.id, u.usuario, u.id_sede, s.descripcion FROM usuario u LEFT JOIN sede s ON u.id_sede = s.id ORDER BY u.id_sede ASC");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        echo "User: " . $row['usuario'] . " | id_sede: " . $row['id_sede'] . " (" . $row['descripcion'] . ")\n";
    }
} else {
    echo "Error: " . mysqli_error($con) . "\n";
}
?>
