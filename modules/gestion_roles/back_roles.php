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

if (!$es_master_puro) {
    unset($modulos_definidos['whatsapp_analytics'], $modulos_definidos['waba_ordenes']);
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

                while ($row = $res_roles->fetch_assoc()) {
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
                    $permitido = in_array($mod_key, ['whatsapp_analytics', 'waba_ordenes', 'gestion_roles'], true) ? 0 : 1;
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

            if (!$es_master_puro && ($role_id === 4 || $target_rname === 'MASTER CI' || $modulo === 'gestion_roles')) {
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
