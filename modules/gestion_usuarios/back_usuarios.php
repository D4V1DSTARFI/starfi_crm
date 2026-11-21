<?php
/**
 * Backend Controller - Gestión de Usuarios STARFI CRM
 * Blindado con requirePermission('gestion_usuarios'), protección absoluta de id=1 / MASTER / MASTER CI
 * y aislamiento estricto por id_sede para administradores de tienda.
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('gestion_usuarios');

require_once __DIR__ . '/../../config/database.php';

$con = getDbConnection('core');
if (!$con) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos central.']);
    exit();
}

$agente_info = getAgenteInfo();
$mi_id = intval($agente_info['id'] ?? 0);
$mi_rol = strtoupper(trim((string)($agente_info['rol'] ?? '')));
$mi_sede = intval($agente_info['id_sede'] ?? 0);

// Solo MASTER puro (o id=1) tiene jerarquía absoluta sobre id=1, MASTER y MASTER CI
$es_master_puro = ($mi_id === 1 || $mi_rol === 'MASTER');
// MASTER y MASTER CI tienen visibilidad multi-sede; cualquier otro rol queda acotado a su propia id_sede
$es_alcance_global = ($es_master_puro || $mi_rol === 'MASTER CI');

if (!$es_alcance_global && $mi_sede <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado: no posee una sede asignada para gestionar usuarios.']);
    exit();
}

function starfi_get_role_name_by_id($con, $role_id) {
    $rid = intval($role_id);
    if ($rid <= 0) {
        return '';
    }
    $stmt = $con->prepare("SELECT nombre FROM roles WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return '';
    }
    $stmt->bind_param("i", $rid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return strtoupper(trim((string)($row['nombre'] ?? '')));
}

function starfi_get_target_user($con, $target_id) {
    $tid = intval($target_id);
    if ($tid <= 0) {
        return null;
    }
    $stmt = $con->prepare("SELECT u.id, u.usuario, u.rol, u.id_sede, r.nombre AS rol_nombre
                           FROM usuario u
                           LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
                           WHERE u.id = ?
                           LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $tid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

$action = $_REQUEST['action'] ?? 'list';

switch ($action) {
    case 'list':
        if ($es_alcance_global) {
            $stmt_list = $con->prepare("SELECT u.id, u.usuario, u.estado, u.rol AS id_rol, r.nombre AS rol_nombre, u.id_sede,
                                               up.nombre, up.cedula, up.correo, up.telefono, up.direccion,
                                               (SELECT nombre_sede FROM sedes WHERE id = u.id_sede LIMIT 1) AS Sede_nombre
                                        FROM usuario u
                                        LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
                                        LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
                                        ORDER BY u.id DESC");
        } else {
            $stmt_list = $con->prepare("SELECT u.id, u.usuario, u.estado, u.rol AS id_rol, r.nombre AS rol_nombre, u.id_sede,
                                               up.nombre, up.cedula, up.correo, up.telefono, up.direccion,
                                               (SELECT nombre_sede FROM sedes WHERE id = u.id_sede LIMIT 1) AS Sede_nombre
                                        FROM usuario u
                                        LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
                                        LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
                                        WHERE u.id_sede = ?
                                        ORDER BY u.id DESC");
            if ($stmt_list) {
                $stmt_list->bind_param("i", $mi_sede);
            }
        }

        $usuarios = [];
        if ($stmt_list && $stmt_list->execute()) {
            $res = $stmt_list->get_result();
            while ($row = $res->fetch_assoc()) {
                $usuarios[] = [
                    'id' => (int)$row['id'],
                    'usuario' => $row['usuario'] ?? '',
                    'nombre' => $row['nombre'] ?? $row['usuario'] ?? 'Sin Nombre',
                    'cedula' => $row['cedula'] ?? '-',
                    'correo' => $row['correo'] ?? '-',
                    'telefono' => $row['telefono'] ?? '-',
                    'direccion' => $row['direccion'] ?? '-',
                    'estado' => $row['estado'] ?? 'INACTIVO',
                    'rol' => $row['rol_nombre'] ?? 'SIN_ASIGNAR',
                    'id_rol' => $row['id_rol'] ? (int)$row['id_rol'] : null,
                    'id_sede' => $row['id_sede'] ? (int)$row['id_sede'] : null,
                    'sede_nombre' => $row['Sede_nombre'] ?? 'Sin Sede'
                ];
            }
            $stmt_list->close();
        }

        if (!$es_master_puro) {
            // Ocultar cuentas MASTER y MASTER CI para cualquier usuario que no sea MASTER puro
            $usuarios = array_values(array_filter($usuarios, function ($u) {
                $rUpper = strtoupper(trim((string)($u['rol'] ?? '')));
                $uLower = strtolower(trim((string)($u['usuario'] ?? '')));
                return ($uLower !== 'master' && !in_array($rUpper, ['MASTER', 'MASTER CI'], true) && (int)$u['id'] !== 1);
            }));
        }

        // Lista de sedes según alcance
        $sedes = [];
        if ($es_alcance_global) {
            $res_sed = mysqli_query($con, "SELECT id, nombre_sede FROM sedes ORDER BY id DESC");
            if ($res_sed) {
                while ($r_sed = mysqli_fetch_assoc($res_sed)) {
                    $sedes[] = $r_sed;
                }
            }
        } else {
            $stmt_sed = $con->prepare("SELECT id, nombre_sede FROM sedes WHERE id = ? LIMIT 1");
            if ($stmt_sed) {
                $stmt_sed->bind_param("i", $mi_sede);
                $stmt_sed->execute();
                $rs = $stmt_sed->get_result();
                while ($r_sed = $rs->fetch_assoc()) {
                    $sedes[] = $r_sed;
                }
                $stmt_sed->close();
            }
        }

        // Lista de roles asignables (no-MASTER jamás puede ver ni asignar MASTER ni MASTER CI)
        $sql_r = $es_master_puro
            ? "SELECT id, nombre FROM roles ORDER BY id ASC"
            : "SELECT id, nombre FROM roles WHERE UPPER(TRIM(nombre)) NOT IN ('MASTER', 'MASTER CI') AND id NOT IN (1, 4) ORDER BY id ASC";
        $res_roles = mysqli_query($con, $sql_r);
        $roles_list = [];
        if ($res_roles) {
            while ($r_rol = mysqli_fetch_assoc($res_roles)) {
                $roles_list[] = $r_rol;
            }
        }

        echo json_encode([
            'success' => true,
            'data' => $usuarios,
            'sedes' => $sedes,
            'roles' => $roles_list
        ]);
        break;

    case 'toggle_status':
        $id = (int)($_POST['id'] ?? 0);
        $nuevo_estado = (($_POST['estado'] ?? '') === 'ACTIVO') ? 'ACTIVO' : 'INACTIVO';

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de usuario no válido.']);
            exit();
        }

        // Nadie puede desactivar la cuenta raíz id = 1
        if ($id === 1 && $nuevo_estado !== 'ACTIVO') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No está permitido desactivar la cuenta principal MASTER.']);
            exit();
        }

        $target = starfi_get_target_user($con, $id);
        if (!$target) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.']);
            exit();
        }

        $target_rol = strtoupper(trim((string)($target['rol_nombre'] ?? '')));
        if (!$es_master_puro && ($id === 1 || in_array($target_rol, ['MASTER', 'MASTER CI'], true))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado para modificar cuentas de jerarquía global.']);
            exit();
        }

        if (!$es_alcance_global && intval($target['id_sede'] ?? 0) !== $mi_sede) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado: el usuario pertenece a otra sede.']);
            exit();
        }

        $stmt = $con->prepare("UPDATE usuario SET estado = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $nuevo_estado, $id);
            $stmt->execute();
            $stmt->close();
        }
        registrar_auditoria($con, 'gestion_usuarios', 'CAMBIAR_ESTADO_USUARIO', 'usuario', $id, [
            'nuevo_estado' => $nuevo_estado
        ], $mi_id, $mi_sede);

        echo json_encode([
            'success' => true,
            'message' => "Estado de usuario cambiado a {$nuevo_estado} con éxito.",
            'nuevo_estado' => $nuevo_estado
        ]);
        break;

    case 'save':
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $usuario = trim((string)($_POST['usuario'] ?? ''));
        $cedula = trim((string)($_POST['cedula'] ?? ''));
        $correo = trim((string)($_POST['correo'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? ''));
        $contrasena = (string)($_POST['contrasena'] ?? '');
        $estado = (($_POST['estado'] ?? '') === 'ACTIVO') ? 'ACTIVO' : 'INACTIVO';

        $rol_in = (int)($_POST['rol'] ?? 0);
        $rol = ($rol_in > 0) ? $rol_in : null;

        $id_sede_in = (int)($_POST['id_sede'] ?? 0);
        $id_sede = $es_alcance_global ? (($id_sede_in > 0) ? $id_sede_in : null) : $mi_sede;

        if ($usuario === '' || $nombre === '' || $correo === '') {
            echo json_encode(['success' => false, 'message' => 'Nombre, Usuario y Correo son campos obligatorios.']);
            exit();
        }

        if ($contrasena !== '' && strlen($contrasena) < 8) {
            echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.']);
            exit();
        }

        // Bloquear asignación de MASTER o MASTER CI por usuarios que no sean MASTER puro
        if ($rol !== null) {
            $rol_destino_nombre = starfi_get_role_name_by_id($con, $rol);
            if ($rol_destino_nombre === '') {
                echo json_encode(['success' => false, 'message' => 'El rol seleccionado no existe.']);
                exit();
            }
            if (!$es_master_puro && ($rol === 1 || $rol === 4 || in_array($rol_destino_nombre, ['MASTER', 'MASTER CI'], true))) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado: no tiene permisos para asignar roles globales (MASTER / MASTER CI).']);
                exit();
            }
        }

        if ($id > 0) {
            $target = starfi_get_target_user($con, $id);
            if (!$target) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Usuario a editar no encontrado.']);
                exit();
            }

            $target_rol = strtoupper(trim((string)($target['rol_nombre'] ?? '')));
            if (!$es_master_puro && ($id === 1 || in_array($target_rol, ['MASTER', 'MASTER CI'], true))) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado: solo MASTER puede editar cuentas globales.']);
                exit();
            }

            if ($id === 1 && $estado !== 'ACTIVO') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No se puede desactivar el usuario raíz MASTER.']);
                exit();
            }

            if (!$es_alcance_global && intval($target['id_sede'] ?? 0) !== $mi_sede) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado: el usuario pertenece a otra sede.']);
                exit();
            }

            $stmt = $con->prepare("UPDATE usuario SET usuario = ?, estado = ?, rol = ?, id_sede = ? WHERE id = ?");
            $stmt->bind_param("ssiii", $usuario, $estado, $rol, $id_sede, $id);
            $stmt->execute();
            $stmt->close();

            if ($contrasena !== '') {
                $hash = password_hash($contrasena, PASSWORD_BCRYPT);
                $stmtP = $con->prepare("UPDATE usuario SET contrasena = ? WHERE id = ?");
                $stmtP->bind_param("si", $hash, $id);
                $stmtP->execute();
                $stmtP->close();
            }

            $stmtPerf = $con->prepare("INSERT INTO usuario_perfil (id_usuario, nombre, cedula, correo, telefono) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), cedula = VALUES(cedula), correo = VALUES(correo), telefono = VALUES(telefono)");
            if ($stmtPerf) {
                $stmtPerf->bind_param("issss", $id, $nombre, $cedula, $correo, $telefono);
                $stmtPerf->execute();
                $stmtPerf->close();
            }

            registrar_auditoria($con, 'gestion_usuarios', 'EDITAR_USUARIO', 'usuario', $id, [
                'usuario' => $usuario,
                'estado'  => $estado,
                'rol'     => $rol,
                'id_sede' => $id_sede
            ], $mi_id, $mi_sede);

            echo json_encode(['success' => true, 'message' => 'Usuario actualizado correctamente.']);
        } else {
            if ($contrasena === '' || strlen($contrasena) < 8) {
                echo json_encode(['success' => false, 'message' => 'La contraseña es requerida y debe tener al menos 8 caracteres.']);
                exit();
            }

            $check = $con->prepare("SELECT id FROM usuario WHERE LOWER(usuario) = LOWER(?) LIMIT 1");
            $check->bind_param("s", $usuario);
            $check->execute();
            $res = $check->get_result();
            if ($res->fetch_assoc()) {
                echo json_encode(['success' => false, 'message' => 'El nombre de usuario ya existe.']);
                $check->close();
                exit();
            }
            $check->close();

            $hash = password_hash($contrasena, PASSWORD_BCRYPT);
            $stmtNew = $con->prepare("INSERT INTO usuario (usuario, contrasena, estado, rol, id_sede) VALUES (?, ?, ?, ?, ?)");
            $stmtNew->bind_param("ssiii", $usuario, $hash, $estado, $rol, $id_sede);
            if ($stmtNew->execute()) {
                $new_id = mysqli_insert_id($con);
                $stmtNew->close();

                $stmtPerfNew = $con->prepare("INSERT INTO usuario_perfil (id_usuario, nombre, cedula, correo, telefono) VALUES (?, ?, ?, ?, ?)");
                $stmtPerfNew->bind_param("issss", $new_id, $nombre, $cedula, $correo, $telefono);
                $stmtPerfNew->execute();
                $stmtPerfNew->close();

                registrar_auditoria($con, 'gestion_usuarios', 'CREAR_USUARIO', 'usuario', $new_id, [
                    'usuario' => $usuario,
                    'estado'  => $estado,
                    'rol'     => $rol,
                    'id_sede' => $id_sede
                ], $mi_id, $mi_sede);

                echo json_encode(['success' => true, 'message' => 'Usuario creado exitosamente.']);
            } else {
                $stmtNew->close();
                echo json_encode(['success' => false, 'message' => 'Error al crear el usuario.']);
            }
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida.']);
        break;
}

mysqli_close($con);
?>
