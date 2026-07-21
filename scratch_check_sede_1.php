<?php
require_once __DIR__ . '/config/database.php';
$c = getDbConnection('core');
if ($c) {
    $r = $c->query("SELECT * FROM sedes WHERE id = 1");
    if ($r && $row = $r->fetch_assoc()) {
        print_r($row);
    } else {
        echo "No Sede with ID 1 found.\n";
    }
}
?>
