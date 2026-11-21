<?php
// logout.php
session_start();
if (isset($_SESSION['agente_id']) && intval($_SESSION['agente_id']) > 0) {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/core/crm_sprint2_helper.php';
    require_once __DIR__ . '/core/audit.php';
    $con = getDbConnection();
    $uid = intval($_SESSION['agente_id']);
    if ($con) {
        starfi_ensure_sprint2_schema($con);
        if ($uid > 1) {
            $stmtOut = $con->prepare("UPDATE `usuario` SET `ultima_actividad` = NULL WHERE `id` = ?");
            if ($stmtOut) {
                $stmtOut->bind_param("i", $uid);
                @$stmtOut->execute();
                $stmtOut->close();
            }
        }
        registrar_auditoria(
            'auth',
            'LOGOUT',
            'Cierre de sesión de operador',
            ['agente_id' => $uid, 'sesion_activa' => true],
            ['agente_id' => $uid, 'sesion_activa' => false],
            $con,
            $uid,
            null
        );
    }
}
session_unset();
session_destroy();
header("Location: login.php");
exit();
?>
