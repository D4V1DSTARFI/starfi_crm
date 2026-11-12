<?php
// logout.php
session_start();
if (isset($_SESSION['agente_id']) && intval($_SESSION['agente_id']) > 1) {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/core/crm_sprint2_helper.php';
    $con = getDbConnection();
    if ($con) {
        starfi_ensure_sprint2_schema($con);
        $uid = intval($_SESSION['agente_id']);
        @mysqli_query($con, "UPDATE `usuario` SET `ultima_actividad` = NULL WHERE `id` = $uid");
    }
}
session_unset();
session_destroy();
header("Location: login.php");
exit();
?>
