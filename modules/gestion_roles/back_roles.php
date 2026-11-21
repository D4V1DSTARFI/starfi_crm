<?php
/**
 * Backend Controller - Gestión de Roles y Permisos STARFI CRM
 * Protege inmutablemente role_id = 1 (MASTER) y restringe edición de MASTER CI a MASTER puro.
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('gestion_roles');

require_once __DIR__ . '/../../config/database.php';

$con = getDbConnection('core');
if (!$con) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos central.']);
    exit();
}

$action = $_REQUEST['action'] ?? 'list';

$agente_info = getAgenteInfo();
$mi_id = intval($agente_info['id'] ?? 0);
$es_master_puro = ($mi_id === 1 || strtoupper(trim((string)($agente_info['rol'] ?? ''))) === 'MASTER');

$modulos_definidos = [
    'bandeja' => 'Centro de Mensajes',
    'pipeline' => 'Embudo de Ventas (Kanban)',
    'campanas' => 'Campañas de Difusión',
    'perfil_empresa' => 'Perfil de Empresa',
    'directorio' => 'Directorio 360',
    'gestion_usuarios' => 'Gestión de Usuarios',
    'gestion_roles' => 'Roles y Permisos',
    'dashboard' => 'Métricas y KPIs',
    'gestor_bots' => 'Gestor de Bots',
    'waba_ordenes' => 'Panel de Órdenes WABA',
    'buzon_correos' => 'Buzón de Salida SMTP',
    'whatsapp_analytics' => 'Facturación WhatsApp',
    'configuracion' => 'Configuración del Sistema'
];

if (!$es_master_puro) {
    unset($modulos_definidos['whatsapp_analytics'], $modulos_definidos['waba_ordenes']);
}

switch ($action) {
    case 'list':
        $sql_roles = $es_master_puro
            ? "SELECT id, nombre FROM roles ORDER BY id ASC"
            : "SELECT id, nombre FROM roles WHERE id != 1 AND UPPER(TRIM(nombre)) != 'MASTER' ORDER BY id ASC";
        $res_roles = mysqli_query($con, $sql_roles);
        $roles = [];

        if ($res_roles) {
            $stmt_sel = $con->prepare("SELECT permitido FROM permisos_roles WHERE id_rol = ? AND modulo = ? LIMIT 1");
            $stmt_ins = $con->prepare("INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES (?, ?, 0)");

            while ($row = mysqli_fetch_assoc($res_roles)) {
                $role_id = (int)$row['id'];
                $permisos = [];

                foreach ($modulos_definidos as $mod_key => $mod_name) {
                    $status = ($role_id === 1) ? 1 : 0;
                    if ($role_id !== 1 && $stmt_sel) {
                        $stmt_sel->bind_param("is", $role_id, $mod_key);
                        $stmt_sel->execute();
                        $res_p = $stmt_sel->get_result();
                        if ($res_p && ($row_p = $res_p->fetch_assoc())) {
                            $status = (int)$row_p['permitido'];
                        } elseif ($stmt_ins) {
                            $stmt_ins->bind_param("is", $role_id, $mod_key);
                            @$stmt_ins->execute();
                        }
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
            if ($stmt_sel) $stmt_sel->close();
            if ($stmt_ins) $stmt_ins->close();
        }

        echo json_encode(['success' => true, 'roles' => $roles]);
        break;

    case 'create_role':
        $nombre_rol = trim((string)($_POST['nombre'] ?? ''));
        if ($nombre_rol === '') {
            echo json_encode(['success' => false, 'message' => 'El nombre del rol no puede estar vacío.']);
            exit();
        }

        if (in_array(strtoupper($nombre_rol), ['MASTER', 'MASTER CI'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No se pueden crear roles con nombres reservados de sistema.']);
            exit();
        }

        $stmt_check = $con->prepare("SELECT id FROM roles WHERE UPPER(TRIM(nombre)) = UPPER(?) LIMIT 1");
        $stmt_check->bind_param("s", $nombre_rol);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        if ($res_check && $res_check->num_rows > 0) {
            $stmt_check->close();
            echo json_encode(['success' => false, 'message' => 'Ya existe un rol con ese nombre.']);
            exit();
        }
        $stmt_check->close();

        $stmt_ins = $con->prepare("INSERT INTO roles (nombre) VALUES (?)");
        $stmt_ins->bind_param("s", $nombre_rol);
        if ($stmt_ins->execute()) {
            $nuevo_id_rol = (int)$stmt_ins->insert_id;
            $stmt_ins->close();

            $stmt_p = $con->prepare("INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES (?, ?, ?)");
            foreach ($modulos_definidos as $mod_key => $mod_name) {
                $permitido = in_array($mod_key, ['whatsapp_analytics', 'waba_ordenes', 'gestion_roles'], true) ? 0 : 1;
                $stmt_p->bind_param("isi", $nuevo_id_rol, $mod_key, $permitido);
                $stmt_p->execute();
            }
            $stmt_p->close();

            registrar_auditoria($con, 'gestion_roles', 'CREAR_ROL', 'roles', $nuevo_id_rol, [
                'nombre' => $nombre_rol
            ], $mi_id);

            echo json_encode(['success' => true, 'id' => $nuevo_id_rol, 'message' => 'Rol creado con éxito.']);
        } else {
            $stmt_ins->close();
            echo json_encode(['success' => false, 'message' => 'Error al crear el rol en base de datos.']);
        }
        break;

    case 'toggle_permission':
        $role_id = (int)($_POST['role_id'] ?? 0);
        $modulo = trim((string)($_POST['modulo'] ?? ''));

        if ($role_id <= 0 || !array_key_exists($modulo, $modulos_definidos)) {
            echo json_encode(['success' => false, 'message' => 'Parámetros inválidos.']);
            exit();
        }

        // Bloquear absolutamente cualquier modificación sobre role_id = 1 (MASTER)
        $stmt_rn = $con->prepare("SELECT nombre FROM roles WHERE id = ? LIMIT 1");
        $stmt_rn->bind_param("i", $role_id);
        $stmt_rn->execute();
        $row_rn = $stmt_rn->get_result()->fetch_assoc();
        $stmt_rn->close();

        if (!$row_rn) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Rol no encontrado.']);
            exit();
        }

        $target_rname = strtoupper(trim((string)$row_rn['nombre']));
        if ($role_id === 1 || $target_rname === 'MASTER') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Operación prohibida: los permisos del rol MASTER son inmutables.']);
            exit();
        }

        if (!$es_master_puro && ($role_id === 4 || $target_rname === 'MASTER CI' || $modulo === 'gestion_roles')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Solo MASTER puede modificar permisos administrativos globales.']);
            exit();
        }

        $stmt_cur = $con->prepare("SELECT permitido FROM permisos_roles WHERE id_rol = ? AND modulo = ? LIMIT 1");
        $stmt_cur->bind_param("is", $role_id, $modulo);
        $stmt_cur->execute();
        $res = $stmt_cur->get_result();
        $nuevo_estado = 1;

        if ($res && ($row = $res->fetch_assoc())) {
            $stmt_cur->close();
            $nuevo_estado = ((int)$row['permitido'] === 1) ? 0 : 1;
            $stmt_upd = $con->prepare("UPDATE permisos_roles SET permitido = ? WHERE id_rol = ? AND modulo = ?");
            $stmt_upd->bind_param("iis", $nuevo_estado, $role_id, $modulo);
            $stmt_upd->execute();
            $stmt_upd->close();
        } else {
            $stmt_cur->close();
            $stmt_add = $con->prepare("INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES (?, ?, 1)");
            $stmt_add->bind_param("is", $role_id, $modulo);
            $stmt_add->execute();
            $stmt_add->close();
        }

        registrar_auditoria($con, 'gestion_roles', 'TOGGLE_PERMISO_ROL', 'permisos_roles', $role_id, [
            'role_id'      => $role_id,
            'modulo'       => $modulo,
            'nuevo_estado' => $nuevo_estado
        ], $mi_id);

        echo json_encode([
            'success' => true,
            'nuevo_estado' => $nuevo_estado,
            'message' => 'Permiso actualizado correctamente.'
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no permitida.']);
        break;
}

mysqli_close($con);
?>
