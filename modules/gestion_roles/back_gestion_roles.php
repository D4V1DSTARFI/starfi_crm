<?php
/**
 * Backend Controller Canónico - Gestión de Roles y Permisos STARFI CRM
 * Protege inmutablemente role_id = 1 (MASTER), restringe buzon_correos exclusivamente a MASTER
 * y restringe edición de MASTER CI a MASTER puro (QA-STD-GLOBAL-2.1).
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('gestion_roles');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !isset($_GET['action']) && !isset($_POST['action'])) {
    registrar_auditoria('gestion_roles', 'ACCESO_METODO_NO_PERMITIDO', 'Petición sin acción a back_gestion_roles', null, ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
}

require_once __DIR__ . '/../../config/database.php';

$con = getDbConnection('core');
if (!$con) {
    starfi_log_exception('gestion_roles', 'Error de conexión con la base de datos central');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos central.']);
    exit();
}

$action = $_REQUEST['action'] ?? 'list';

$agente_info = getAgenteInfo();
$mi_id = intval($agente_info['id'] ?? 0);
$mi_sede = isset($agente_info['id_sede']) ? intval($agente_info['id_sede']) : null;
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

$modulos_visibles = $modulos_definidos;
if (!$es_master_puro) {
    unset($modulos_visibles['whatsapp_analytics'], $modulos_visibles['waba_ordenes'], $modulos_visibles['buzon_correos']);
}

try {
    switch ($action) {
        case 'list':
            $sql_roles = $es_master_puro
                ? "SELECT id, nombre FROM roles ORDER BY id ASC"
                : "SELECT id, nombre FROM roles WHERE id != 1 AND UPPER(TRIM(nombre)) != 'MASTER' ORDER BY id ASC";
            $stmt_roles = $con->prepare($sql_roles);
            $roles = [];

            if ($stmt_roles && $stmt_roles->execute()) {
                $res_roles = $stmt_roles->get_result();
                $stmt_sel = $con->prepare("SELECT permitido FROM permisos_roles WHERE id_rol = ? AND modulo = ? LIMIT 1");
                $stmt_ins = $con->prepare("INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES (?, ?, 0)");
                $stmt_fix_buzon = $con->prepare("UPDATE permisos_roles SET permitido = 0 WHERE id_rol = ? AND modulo = 'buzon_correos'");

                while ($row = $res_roles->fetch_assoc()) {
                    $role_id = (int)$row['id'];
                    $role_name_upper = strtoupper(trim((string)$row['nombre']));
                    $is_target_master = ($role_id === 1 || $role_name_upper === 'MASTER');
                    $permisos = [];

                    foreach ($modulos_visibles as $mod_key => $mod_name) {
                        $status = $is_target_master ? 1 : 0;
                        if (!$is_target_master && $stmt_sel) {
                            $stmt_sel->bind_param("is", $role_id, $mod_key);
                            $stmt_sel->execute();
                            $res_p = $stmt_sel->get_result();
                            if ($res_p && ($row_p = $res_p->fetch_assoc())) {
                                $status = (int)$row_p['permitido'];
                            } elseif ($stmt_ins) {
                                $stmt_ins->bind_param("is", $role_id, $mod_key);
                                if (@$stmt_ins->execute()) {
                                    registrar_auditoria(
                                        'gestion_roles',
                                        'INICIALIZAR_PERMISO_ROL',
                                        "Inicialización de permiso base '{$mod_key}' para rol #{$role_id}",
                                        null,
                                        ['id_rol' => $role_id, 'modulo' => $mod_key, 'permitido' => 0],
                                        $con,
                                        $mi_id,
                                        $mi_sede
                                    );
                                }
                            }

                            // Regla estricta QA-BUG-CRM3-04: buzon_correos NUNCA permitido en roles no-MASTER
                            if ($mod_key === 'buzon_correos' && $status !== 0) {
                                $status = 0;
                                if ($stmt_fix_buzon) {
                                    $stmt_fix_buzon->bind_param("i", $role_id);
                                    @$stmt_fix_buzon->execute();
                                }
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
                if ($stmt_fix_buzon) $stmt_fix_buzon->close();
                $stmt_roles->close();
            } else {
                if ($stmt_roles) $stmt_roles->close();
                starfi_log_exception('gestion_roles', 'Error al listar roles', ['errno' => $con->errno]);
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

                $permisos_iniciales = [];
                $stmt_p = $con->prepare("INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES (?, ?, ?)");
                foreach ($modulos_definidos as $mod_key => $mod_name) {
                    $permitido = in_array($mod_key, ['whatsapp_analytics', 'waba_ordenes', 'gestion_roles', 'buzon_correos'], true) ? 0 : 1;
                    $stmt_p->bind_param("isi", $nuevo_id_rol, $mod_key, $permitido);
                    $stmt_p->execute();
                    $permisos_iniciales[$mod_key] = $permitido;
                }
                $stmt_p->close();

                registrar_auditoria(
                    'gestion_roles',
                    'CREAR_ROL',
                    "Creación del rol '{$nombre_rol}' (#{$nuevo_id_rol})",
                    null,
                    ['id' => $nuevo_id_rol, 'nombre' => $nombre_rol, 'permisos' => $permisos_iniciales],
                    $con,
                    $mi_id,
                    $mi_sede
                );

                echo json_encode(['success' => true, 'id' => $nuevo_id_rol, 'message' => 'Rol creado con éxito.']);
            } else {
                $errno = $con->errno;
                $stmt_ins->close();
                starfi_log_exception('gestion_roles', 'Error al crear rol en base de datos', ['nombre' => $nombre_rol, 'errno' => $errno]);
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

            $stmt_rn = $con->prepare("SELECT id, nombre FROM roles WHERE id = ? LIMIT 1");
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

            // QA-BUG-CRM3-04: buzon_correos NUNCA puede otorgarse a ningún rol distinto de MASTER
            if ($modulo === 'buzon_correos') {
                $stmt_force = $con->prepare("INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES (?, 'buzon_correos', 0) ON DUPLICATE KEY UPDATE permitido = 0");
                if ($stmt_force) {
                    $stmt_force->bind_param("i", $role_id);
                    @$stmt_force->execute();
                    $stmt_force->close();
                }
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'nuevo_estado' => 0,
                    'message' => 'El módulo Buzón de Correos es exclusivo del rol MASTER y no puede habilitarse para otros roles.'
                ]);
                exit();
            }

            if (!$es_master_puro && ($role_id === 4 || $target_rname === 'MASTER CI' || in_array($modulo, ['gestion_roles', 'waba_ordenes', 'whatsapp_analytics'], true))) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Solo MASTER puede modificar permisos administrativos globales.']);
                exit();
            }

            $stmt_cur = $con->prepare("SELECT id, id_rol, modulo, permitido FROM permisos_roles WHERE id_rol = ? AND modulo = ? LIMIT 1");
            $stmt_cur->bind_param("is", $role_id, $modulo);
            $stmt_cur->execute();
            $res = $stmt_cur->get_result();
            $datos_anteriores = null;
            $nuevo_estado = 1;

            if ($res && ($row = $res->fetch_assoc())) {
                $stmt_cur->close();
                $datos_anteriores = [
                    'id' => (int)$row['id'],
                    'id_rol' => (int)$row['id_rol'],
                    'rol_nombre' => $row_rn['nombre'],
                    'modulo' => $row['modulo'],
                    'permitido' => (int)$row['permitido']
                ];
                $nuevo_estado = ((int)$row['permitido'] === 1) ? 0 : 1;
                $stmt_upd = $con->prepare("UPDATE permisos_roles SET permitido = ? WHERE id_rol = ? AND modulo = ?");
                $stmt_upd->bind_param("iis", $nuevo_estado, $role_id, $modulo);
                if (!$stmt_upd->execute()) {
                    $errno = $con->errno;
                    $stmt_upd->close();
                    starfi_log_exception('gestion_roles', 'Error al actualizar permiso de rol', ['role_id' => $role_id, 'modulo' => $modulo, 'errno' => $errno]);
                    echo json_encode(['success' => false, 'message' => 'No se pudo actualizar el permiso.']);
                    exit();
                }
                $stmt_upd->close();
            } else {
                $stmt_cur->close();
                $stmt_add = $con->prepare("INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES (?, ?, 1)");
                $stmt_add->bind_param("is", $role_id, $modulo);
                if (!$stmt_add->execute()) {
                    $errno = $con->errno;
                    $stmt_add->close();
                    starfi_log_exception('gestion_roles', 'Error al insertar permiso de rol', ['role_id' => $role_id, 'modulo' => $modulo, 'errno' => $errno]);
                    echo json_encode(['success' => false, 'message' => 'No se pudo registrar el permiso.']);
                    exit();
                }
                $stmt_add->close();
            }

            $datos_nuevos = [
                'id_rol' => $role_id,
                'rol_nombre' => $row_rn['nombre'],
                'modulo' => $modulo,
                'permitido' => $nuevo_estado
            ];

            registrar_auditoria(
                'gestion_roles',
                'TOGGLE_PERMISO_ROL',
                "Cambio de permiso en módulo '{$modulo}' para rol '{$row_rn['nombre']}' (#{$role_id}) a {$nuevo_estado}",
                $datos_anteriores,
                $datos_nuevos,
                $con,
                $mi_id,
                $mi_sede
            );

            echo json_encode([
                'success' => true,
                'nuevo_estado' => $nuevo_estado,
                'message' => 'Permiso actualizado correctamente.'
            ]);
            break;

        case 'delete_role':
            if (!$es_master_puro) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Solo MASTER puede eliminar roles.']);
                exit();
            }
            $role_id = (int)($_POST['role_id'] ?? ($_POST['id'] ?? 0));
            if ($role_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Rol inválido.']);
                exit();
            }
            $stmt_r = $con->prepare("SELECT id, nombre FROM roles WHERE id = ? LIMIT 1");
            $stmt_r->bind_param("i", $role_id);
            $stmt_r->execute();
            $row_r = $stmt_r->get_result()->fetch_assoc();
            $stmt_r->close();

            if (!$row_r) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Rol no encontrado.']);
                exit();
            }
            $rname = strtoupper(trim((string)$row_r['nombre']));
            if (in_array($role_id, [1, 2, 3, 4], true) || in_array($rname, ['MASTER', 'MASTER CI', 'ADMINISTRADOR', 'OPERADOR'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No se pueden eliminar roles base del sistema.']);
                exit();
            }

            $stmt_u = $con->prepare("SELECT COUNT(*) AS cnt FROM usuario WHERE CAST(rol AS UNSIGNED) = ?");
            $stmt_u->bind_param("i", $role_id);
            $stmt_u->execute();
            $cnt_u = (int)($stmt_u->get_result()->fetch_assoc()['cnt'] ?? 0);
            $stmt_u->close();
            if ($cnt_u > 0) {
                echo json_encode(['success' => false, 'message' => 'No se puede eliminar un rol que tiene usuarios asignados.']);
                exit();
            }

            $stmt_dp = $con->prepare("DELETE FROM permisos_roles WHERE id_rol = ?");
            $stmt_dp->bind_param("i", $role_id);
            $stmt_dp->execute();
            $stmt_dp->close();

            $stmt_dr = $con->prepare("DELETE FROM roles WHERE id = ?");
            $stmt_dr->bind_param("i", $role_id);
            if ($stmt_dr->execute()) {
                $stmt_dr->close();
                registrar_auditoria(
                    'gestion_roles',
                    'ELIMINAR_ROL',
                    "Eliminación del rol '{$row_r['nombre']}' (#{$role_id})",
                    $row_r,
                    null,
                    $con,
                    $mi_id,
                    $mi_sede
                );
                echo json_encode(['success' => true, 'message' => 'Rol eliminado correctamente.']);
            } else {
                $stmt_dr->close();
                echo json_encode(['success' => false, 'message' => 'No se pudo eliminar el rol.']);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Acción no permitida.']);
            break;
    }
} catch (Throwable $ex) {
    starfi_log_exception('gestion_roles', 'Excepción en controlador de roles', [
        'action' => $action,
        'code' => $ex->getCode(),
        'line' => $ex->getLine()
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno al procesar la solicitud.']);
}

mysqli_close($con);
