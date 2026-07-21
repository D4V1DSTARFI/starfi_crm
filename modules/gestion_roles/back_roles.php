<?php
/**
 * Backend Controller - Gestión de Roles y Permisos STARFI CRM
 * Procesa peticiones AJAX para listar la matriz de permisos y alternar (toggle) los accesos de cada rol.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
requireAuth();
// Restringir módulo de roles y permisos únicamente a MASTER
requirePermission('gestion_roles');

require_once __DIR__ . '/../../config/database.php';

$con = getDbConnection('core');

if (!$con) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos central.']);
    exit();
}

$action = $_REQUEST['action'] ?? 'list';

$modulos_definidos = [
    'bandeja' => 'Bandeja Omnicanal',
    'perfil_empresa' => 'Perfil de Empresa',
    'directorio' => 'Directorio 360',
    'gestion_usuarios' => 'Gestión de Usuarios',
    'gestion_roles' => 'Roles y Permisos',
    'dashboard' => 'Métricas y KPIs',
    'gestor_bots' => 'Gestor de Bots',
    'configuracion' => 'Configuración del Sistema'
];

switch ($action) {
    case 'list':
        // Cargar los roles (excluyendo a MASTER que tiene bypass)
        $res_roles = mysqli_query($con, "SELECT id, nombre FROM roles WHERE nombre != 'MASTER' ORDER BY id ASC");
        $roles = [];
        
        if ($res_roles) {
            while ($row = mysqli_fetch_assoc($res_roles)) {
                $role_id = (int)$row['id'];
                
                // Cargar los permisos del rol actual
                $permisos = [];
                foreach ($modulos_definidos as $mod_key => $mod_name) {
                    $res_p = mysqli_query($con, "SELECT permitido FROM permisos_roles WHERE id_rol = $role_id AND modulo = '$mod_key' LIMIT 1");
                    $status = 0;
                    if ($res_p && $row_p = mysqli_fetch_assoc($res_p)) {
                        $status = (int)$row_p['permitido'];
                    } else {
                        // Si no existe, insertar por defecto como inactivo
                        mysqli_query($con, "INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES ($role_id, '$mod_key', 0)");
                    }
                    $permisos[] = [
                        'modulo' => $mod_key,
                        'nombre' => $mod_name,
                        'permitido' => $status
                    ];
                }
                
                $roles[] = [
                    'id' => $role_id,
                    'nombre' => $row['nombre'],
                    'permisos' => $permisos
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'roles' => $roles
        ]);
        break;

    case 'toggle_permission':
        $role_id = (int)($_POST['role_id'] ?? 0);
        $modulo = trim($_POST['modulo'] ?? '');
        
        if ($role_id <= 0 || !array_key_exists($modulo, $modulos_definidos)) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos.']);
            exit();
        }
        
        // Cargar estado actual
        $res = mysqli_query($con, "SELECT permitido FROM permisos_roles WHERE id_rol = $role_id AND modulo = '$modulo' LIMIT 1");
        $nuevo_estado = 1;
        if ($res && $row = mysqli_fetch_assoc($res)) {
            $nuevo_estado = $row['permitido'] == 1 ? 0 : 1;
            mysqli_query($con, "UPDATE permisos_roles SET permitido = $nuevo_estado WHERE id_rol = $role_id AND modulo = '$modulo'");
        } else {
            mysqli_query($con, "INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES ($role_id, '$modulo', 1)");
        }
        
        echo json_encode([
            'success' => true,
            'nuevo_estado' => $nuevo_estado,
            'message' => 'Permiso actualizado correctamente.'
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no permitida.']);
        break;
}

mysqli_close($con);
?>
