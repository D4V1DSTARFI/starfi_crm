<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('perfil_empresa');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !isset($_GET['action']) && !isset($_POST['action'])) {
    registrar_auditoria('perfil_empresa', 'ACCESO_METODO_NO_PERMITIDO', 'Petición sin acción a back_perfil_empresa', null, ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
}
require __DIR__ . '/back_empresa.php';
