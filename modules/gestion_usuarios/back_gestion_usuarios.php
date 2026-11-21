<?php
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('gestion_usuarios');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !isset($_GET['action']) && !isset($_POST['action'])) {
    registrar_auditoria('gestion_usuarios', 'ACCESO_METODO_NO_PERMITIDO', 'Petición sin acción a back_gestion_usuarios', null, ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
}
require __DIR__ . '/back_usuarios.php';
