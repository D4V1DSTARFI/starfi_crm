<?php
/**
 * Backend Controller - Gestión de Usuarios STARFI CRM
 * Procesa peticiones AJAX para listar usuarios, cambiar estatus (Activo/Inactivo),
 * asignar Roles (relacional de la tabla roles) y vincular Sede corporativa.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
requireAuth();

require_once __DIR__ . '/../../config/database.php';

$con = getDbConnection('core');

if (!$con) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos central.']);
    exit();
}

// Asegurar existencia de la tabla roles y columnas relacionales
@mysqli_query($con, "CREATE TABLE IF NOT EXISTS roles (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
@mysqli_query($con, "INSERT IGNORE INTO roles (id, nombre) VALUES (1, 'MASTER'), (2, 'ADMINISTRADOR'), (3, 'OPERADOR'), (4, 'MASTER CI')");

// Asegurar permisos predeterminados para MASTER CI
$res_mci = mysqli_query($con, "SELECT id FROM roles WHERE nombre = 'MASTER CI' LIMIT 1");
if ($res_mci && $row_mci = mysqli_fetch_assoc($res_mci)) {
    $mci_id = (int)$row_mci['id'];
    $modulos_all = ['bandeja', 'perfil_empresa', 'directorio', 'gestion_usuarios', 'gestion_roles', 'dashboard', 'gestor_bots', 'waba_ordenes', 'buzon_correos', 'whatsapp_analytics', 'configuracion'];
    foreach ($modulos_all as $m_key) {
        $perm = ($m_key === 'whatsapp_analytics' || $m_key === 'waba_ordenes') ? 0 : 1;
        @mysqli_query($con, "INSERT INTO permisos_roles (id_rol, modulo, permitido) VALUES ($mci_id, '$m_key', $perm) ON DUPLICATE KEY UPDATE permitido = VALUES(permitido)");
    }
}

@mysqli_query($con, "ALTER TABLE usuario ADD COLUMN estado ENUM('ACTIVO', 'INACTIVO') DEFAULT 'INACTIVO'");
@mysqli_query($con, "ALTER TABLE usuario ADD COLUMN id_sede INT DEFAULT NULL");
@mysqli_query($con, "ALTER TABLE usuario MODIFY COLUMN rol VARCHAR(100) DEFAULT NULL");

$agente_info = getAgenteInfo();
$es_master_puro = ($agente_info && strtoupper(trim($agente_info['rol'] ?? '')) === 'MASTER');

$action = $_REQUEST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $query = "SELECT u.id, u.usuario, u.estado, u.rol AS id_rol, r.nombre AS rol_nombre, u.id_sede,
                         up.nombre, up.cedula, up.correo, up.telefono, up.direccion,
                         (SELECT nombre_sede FROM sedes WHERE id = u.id_sede LIMIT 1) AS Sede_nombre
                  FROM usuario u
                  LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
                  LEFT JOIN roles r ON u.rol = r.id
                  ORDER BY u.id DESC";
        $res = mysqli_query($con, $query);
        $usuarios = [];

        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
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
        }

        if ($es_master_puro) {
            // Garantizar que el usuario Master siempre esté en el listado para MASTER puro
            $has_master = false;
            foreach ($usuarios as $u) {
                if ($u['usuario'] === 'master' || $u['rol'] === 'MASTER') {
                    $has_master = true;
                    break;
                }
            }
            if (!$has_master) {
                array_unshift($usuarios, [
                    'id' => 1,
                    'usuario' => 'master',
                    'nombre' => 'Acceso Master',
                    'cedula' => 'V-00000000',
                    'correo' => 'master@starfi.com',
                    'telefono' => '+58 000 0000000',
                    'direccion' => 'Sede Central',
                    'estado' => 'ACTIVO',
                    'rol' => 'MASTER',
                    'id_rol' => 1,
                    'id_sede' => null,
                    'sede_nombre' => 'Todas las Sedes'
                ]);
            }
        } else {
            // Filtrar usuario MASTER si el usuario actual no es MASTER puro
            $usuarios = array_values(array_filter($usuarios, function($u) {
                return ($u['usuario'] !== 'master' && strtoupper($u['rol'] ?? '') !== 'MASTER' && (int)$u['id'] !== 1);
            }));
        }

        // Obtener lista de sedes disponibles para asignación
        $res_sed = mysqli_query($con, "SELECT id, nombre_sede FROM sedes ORDER BY id DESC");
        $sedes = [];
        if ($res_sed) {
            while ($r_sed = mysqli_fetch_assoc($res_sed)) {
                $sedes[] = $r_sed;
            }
        }

        // Obtener lista de roles relacionales (filtrando MASTER para no-MASTER)
        $sql_r = $es_master_puro ? "SELECT id, nombre FROM roles ORDER BY id ASC" : "SELECT id, nombre FROM roles WHERE nombre != 'MASTER' ORDER BY id ASC";
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
        $nuevo_estado = ($_POST['estado'] === 'ACTIVO') ? 'ACTIVO' : 'INACTIVO';

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de usuario no válido.']);
            exit();
        }

        $stmt = $con->prepare("UPDATE usuario SET estado = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $nuevo_estado, $id);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode([
            'success' => true, 
            'message' => "Estado de usuario cambiado a {$nuevo_estado} con éxito.",
            'nuevo_estado' => $nuevo_estado
        ]);
        break;

    case 'save':
        $id = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        $usuario = trim($_POST['usuario'] ?? '');
        $cedula = trim($_POST['cedula'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $contrasena = $_POST['contrasena'] ?? '';
        $estado = ($_POST['estado'] === 'ACTIVO') ? 'ACTIVO' : 'INACTIVO';
        
        $rol_in = (int)($_POST['rol'] ?? 0);
        $rol = ($rol_in > 0) ? $rol_in : null;
        
        $id_sede_in = (int)($_POST['id_sede'] ?? 0);
        $id_sede = ($id_sede_in > 0) ? $id_sede_in : null;

        if (empty($usuario) || empty($nombre) || empty($correo)) {
            echo json_encode(['success' => false, 'message' => 'Nombre, Usuario y Correo son campos obligatorios.']);
            exit();
        }

        // Bloquear asignación del rol MASTER si quien realiza la acción no es MASTER puro
        if (!$es_master_puro && $rol === 1) {
            echo json_encode(['success' => false, 'message' => 'Acceso denegado. No tienes permisos para asignar el rol MASTER.']);
            exit();
        }

        if ($id > 0) {
            // Actualizar usuario existente (usuario, estado, rol INT, id_sede INT)
            $stmt = $con->prepare("UPDATE usuario SET usuario = ?, estado = ?, rol = ?, id_sede = ? WHERE id = ?");
            $stmt->bind_param("ssiii", $usuario, $estado, $rol, $id_sede, $id);
            $stmt->execute();
            $stmt->close();

            // Actualizar contraseña si se ingresó una nueva
            if (!empty($contrasena)) {
                $hash = password_hash($contrasena, PASSWORD_BCRYPT);
                $stmtP = $con->prepare("UPDATE usuario SET contrasena = ? WHERE id = ?");
                $stmtP->bind_param("si", $hash, $id);
                $stmtP->execute();
                $stmtP->close();
            }

            // Actualizar o crear perfil de usuario (UPSERT)
            $stmtPerf = $con->prepare("INSERT INTO usuario_perfil (id_usuario, nombre, cedula, correo, telefono) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), cedula = VALUES(cedula), correo = VALUES(correo), telefono = VALUES(telefono)");
            if ($stmtPerf) {
                $stmtPerf->bind_param("issss", $id, $nombre, $cedula, $correo, $telefono);
                $stmtPerf->execute();
                $stmtPerf->close();
            }

            echo json_encode(['success' => true, 'message' => 'Usuario actualizado correctamente.']);
        } else {
            // Crear nuevo usuario
            if (empty($contrasena)) {
                echo json_encode(['success' => false, 'message' => 'La contraseña es requerida para nuevos usuarios.']);
                exit();
            }

            $check = $con->prepare("SELECT id FROM usuario WHERE usuario = ?");
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

                echo json_encode(['success' => true, 'message' => 'Usuario creado exitosamente.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al crear el usuario.']);
            }
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no reconocida.']);
        break;
}

mysqli_close($con);
?>
