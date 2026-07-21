<?php
/**
 * Backend Controller - Perfil de Empresa STARFI CRM
 * Procesa peticiones AJAX para gestionar las 4 tablas corporativas:
 * 1. empresa_perfil (Información Principal, RIF, Condición Fiscal)
 * 2. empresa_firmantes (Representantes Legales)
 * 3. empresa_registro (Registro Mercantil y Tomo)
 * 4. empresa_expediente (Documentación y Expedientes Digitales)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
requireAuth();

require_once __DIR__ . '/../../config/database.php';

$con = getDbConnection('core');

if (!$con) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos.']);
    exit();
}

// Asegurar existencia de las 4 tablas corporativas
@mysqli_query($con, "CREATE TABLE IF NOT EXISTS empresa_perfil (id INT AUTO_INCREMENT PRIMARY KEY, id_sede INT NOT NULL DEFAULT 1, razon_social VARCHAR(255) NOT NULL, letra CHAR(1) DEFAULT 'J', rif VARCHAR(50) NOT NULL, email VARCHAR(255), telefono VARCHAR(50), direccion TEXT, web VARCHAR(255), instagram VARCHAR(100), condicion ENUM('[ORDINARIO]', '[ESPECIAL]') DEFAULT '[ORDINARIO]', logo_empresa VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@mysqli_query($con, "CREATE TABLE IF NOT EXISTS empresa_firmantes (id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL, id_sede INT NOT NULL DEFAULT 1, nombre VARCHAR(255) NOT NULL, cedula VARCHAR(50) NOT NULL, telefono VARCHAR(50), email VARCHAR(255), cargo VARCHAR(100), direccion TEXT, firma VARCHAR(255)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@mysqli_query($con, "CREATE TABLE IF NOT EXISTS empresa_registro (id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL, id_sede INT NOT NULL DEFAULT 1, fecha DATE NOT NULL, n_registro VARCHAR(50) NOT NULL, n_tomo VARCHAR(50) NOT NULL, descripcion TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

@mysqli_query($con, "CREATE TABLE IF NOT EXISTS empresa_expediente (id INT AUTO_INCREMENT PRIMARY KEY, id_empresa INT NOT NULL, id_sede INT NOT NULL DEFAULT 1, fecha DATE NOT NULL, descripcion VARCHAR(255) NOT NULL, ruta VARCHAR(255) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");


$action = $_REQUEST['action'] ?? '';

switch ($action) {

    // 1. Obtener sedes disponibles
    case 'get_sedes':
        $res = mysqli_query($con, "SELECT id, nombre_sede AS razon_social FROM sedes WHERE estado = 'ACTIVO' ORDER BY id ASC");
        $sedes = [];
        if ($res && mysqli_num_rows($res) > 0) {
            while ($row = mysqli_fetch_assoc($res)) {
                $sedes[] = $row;
            }
        } else {
            // Fallback sede por defecto
            $sedes[] = ['id' => 1, 'razon_social' => 'Sede Principal'];
        }
        echo json_encode(['success' => true, 'data' => $sedes]);
        break;

    // 2. Obtener lista unificada de perfiles de empresa (filtrada por rol y empresa asignada)
    case 'get_perfiles':
        $agente = getAgenteInfo();
        $user_rol = $agente['rol'] ?? 'SIN_ROL';
        $user_empresa = (int)($agente['id_empresa'] ?? 0);

        if ($user_rol !== 'MASTER' && $user_empresa > 0) {
            $sql = "SELECT p.id, p.razon_social, p.letra, p.rif, p.email, p.telefono, p.condicion, p.logo_empresa, p.id_sede,
                           (SELECT nombre FROM empresa_firmantes WHERE id_empresa = p.id LIMIT 1) AS firmante_principal,
                           (SELECT fecha FROM empresa_registro WHERE id_empresa = p.id LIMIT 1) AS fecha_registro
                    FROM empresa_perfil p
                    WHERE p.id = $user_empresa
                    ORDER BY p.id DESC";
        } else {
            $id_sede = (int)($_REQUEST['id_sede'] ?? 0);
            $where = ($id_sede > 0) ? "WHERE p.id_sede = $id_sede" : "";

            $sql = "SELECT p.id, p.razon_social, p.letra, p.rif, p.email, p.telefono, p.condicion, p.logo_empresa, p.id_sede,
                           (SELECT nombre FROM empresa_firmantes WHERE id_empresa = p.id LIMIT 1) AS firmante_principal,
                           (SELECT fecha FROM empresa_registro WHERE id_empresa = p.id LIMIT 1) AS fecha_registro
                    FROM empresa_perfil p
                    $where
                    ORDER BY p.id DESC";
        }

        $res = mysqli_query($con, $sql);
        $perfiles = [];
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $perfiles[] = $row;
            }
        }

        // Fallback: si no hay empresas en esa sede específica y es MASTER, cargar todas las empresas registradas
        if (empty($perfiles) && $user_rol === 'MASTER') {
            $sql_all = "SELECT p.id, p.razon_social, p.letra, p.rif, p.email, p.telefono, p.condicion, p.logo_empresa, p.id_sede,
                               (SELECT nombre FROM empresa_firmantes WHERE id_empresa = p.id LIMIT 1) AS firmante_principal,
                               (SELECT fecha FROM empresa_registro WHERE id_empresa = p.id LIMIT 1) AS fecha_registro
                        FROM empresa_perfil p
                        ORDER BY p.id DESC";
            $res_all = mysqli_query($con, $sql_all);
            if ($res_all) {
                while ($row = mysqli_fetch_assoc($res_all)) {
                    $perfiles[] = $row;
                }
            }
        }

        echo json_encode(['success' => true, 'data' => $perfiles, 'user_rol' => $user_rol, 'user_empresa' => $user_empresa]);
        break;

    // 3. Obtener detalle completo de las 4 tablas para un perfil específico
    case 'get_detalle_empresa':
        $id_empresa = (int)($_REQUEST['id_empresa'] ?? 0);
        if ($id_empresa <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID de empresa no válido.']);
            exit();
        }

        // Datos principales (empresa_perfil)
        $q_perfil = mysqli_query($con, "SELECT * FROM empresa_perfil WHERE id = $id_empresa LIMIT 1");
        $perfil = mysqli_fetch_assoc($q_perfil);

        if (!$perfil) {
            echo json_encode(['success' => false, 'message' => 'Perfil no encontrado.']);
            exit();
        }

        // Tabla Firmantes (empresa_firmantes)
        $q_firmantes = mysqli_query($con, "SELECT * FROM empresa_firmantes WHERE id_empresa = $id_empresa ORDER BY id ASC");
        $firmantes = [];
        while ($r = mysqli_fetch_assoc($q_firmantes)) { $firmantes[] = $r; }

        // Tabla Registro Mercantil (empresa_registro)
        $q_registros = mysqli_query($con, "SELECT * FROM empresa_registro WHERE id_empresa = $id_empresa ORDER BY id DESC");
        $registros = [];
        while ($r = mysqli_fetch_assoc($q_registros)) { $registros[] = $r; }

        // Tabla Expedientes (empresa_expediente)
        $q_expedientes = mysqli_query($con, "SELECT * FROM empresa_expediente WHERE id_empresa = $id_empresa ORDER BY id DESC");
        $expedientes = [];
        while ($r = mysqli_fetch_assoc($q_expedientes)) { $expedientes[] = $r; }

        echo json_encode([
            'success' => true,
            'perfil' => $perfil,
            'firmantes' => $firmantes,
            'registros' => $registros,
            'expedientes' => $expedientes
        ]);
        break;

    // 4. Guardar / Editar Datos Principales (empresa_perfil)
    case 'save_perfil':
        $id = (int)($_POST['id'] ?? 0);
        $id_sede = (int)($_POST['id_sede'] ?? 1);
        $razon_social = trim($_POST['razon_social'] ?? '');
        $letra = trim($_POST['letra'] ?? 'J');
        $rif = trim($_POST['rif'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $web = trim($_POST['web'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');
        $condicion = ($_POST['condicion'] === '[ESPECIAL]') ? '[ESPECIAL]' : '[ORDINARIO]';

        if (empty($razon_social) || empty($rif)) {
            echo json_encode(['success' => false, 'message' => 'Razón Social y RIF son campos obligatorios.']);
            exit();
        }

        // Manejo de carga de logo
        $logo_filename = null;
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../uploads/empresa/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0777, true);
            }
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $logo_filename = 'logo_' . time() . '_' . rand(100, 999) . '.' . $ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo_filename);
        }

        if ($id > 0) {
            // Actualizar existente
            if ($logo_filename) {
                $stmt = $con->prepare("UPDATE empresa_perfil SET razon_social=?, letra=?, rif=?, email=?, telefono=?, direccion=?, web=?, instagram=?, condicion=?, logo_empresa=? WHERE id=?");
                $stmt->bind_param("ssssssssssi", $razon_social, $letra, $rif, $email, $telefono, $direccion, $web, $instagram, $condicion, $logo_filename, $id);
            } else {
                $stmt = $con->prepare("UPDATE empresa_perfil SET razon_social=?, letra=?, rif=?, email=?, telefono=?, direccion=?, web=?, instagram=?, condicion=? WHERE id=?");
                $stmt->bind_param("sssssssssi", $razon_social, $letra, $rif, $email, $telefono, $direccion, $web, $instagram, $condicion, $id);
            }
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Perfil de empresa actualizado con éxito.', 'id_empresa' => $id]);
        } else {
            // Crear nuevo perfil
            $stmt = $con->prepare("INSERT INTO empresa_perfil (id_sede, razon_social, letra, rif, email, telefono, direccion, web, instagram, condicion, logo_empresa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssssss", $id_sede, $razon_social, $letra, $rif, $email, $telefono, $direccion, $web, $instagram, $condicion, $logo_filename);
            if ($stmt->execute()) {
                $new_id = mysqli_insert_id($con);
                $stmt->close();
                echo json_encode(['success' => true, 'message' => 'Perfil de empresa registrado exitosamente.', 'id_empresa' => $new_id]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al registrar la empresa.']);
            }
        }
        break;

    // 5. Guardar / Editar Firmante (empresa_firmantes)
    case 'save_firmante':
        $id = (int)($_POST['id'] ?? 0);
        $id_empresa = (int)($_POST['id_empresa'] ?? 0);
        $id_sede = (int)($_POST['id_sede'] ?? 1);
        $nombre = trim($_POST['nombre'] ?? '');
        $cedula = trim($_POST['cedula'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $cargo = trim($_POST['cargo'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');

        if ($id_empresa <= 0 || empty($nombre) || empty($cedula)) {
            echo json_encode(['success' => false, 'message' => 'Nombre y Cédula son obligatorios para el firmante.']);
            exit();
        }

        if ($id > 0) {
            $stmt = $con->prepare("UPDATE empresa_firmantes SET nombre=?, cedula=?, telefono=?, email=?, cargo=?, direccion=? WHERE id=?");
            $stmt->bind_param("ssssssi", $nombre, $cedula, $telefono, $email, $cargo, $direccion, $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Firmante actualizado con éxito.']);
        } else {
            $stmt = $con->prepare("INSERT INTO empresa_firmantes (id_empresa, id_sede, nombre, cedula, telefono, email, cargo, direccion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissssss", $id_empresa, $id_sede, $nombre, $cedula, $telefono, $email, $cargo, $direccion);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Firmante agregado con éxito.']);
        }
        break;

    // 6. Eliminar Firmante
    case 'delete_firmante':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM empresa_firmantes WHERE id = $id");
            echo json_encode(['success' => true, 'message' => 'Firmante eliminado.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        }
        break;

    // 7. Guardar / Editar Registro Mercantil (empresa_registro)
    case 'save_registro':
        $id = (int)($_POST['id'] ?? 0);
        $id_empresa = (int)($_POST['id_empresa'] ?? 0);
        $id_sede = (int)($_POST['id_sede'] ?? 1);
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $n_registro = trim($_POST['n_registro'] ?? '');
        $n_tomo = trim($_POST['n_tomo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');

        if ($id_empresa <= 0 || empty($n_registro) || empty($n_tomo)) {
            echo json_encode(['success' => false, 'message' => 'N° de Registro y N° de Tomo son obligatorios.']);
            exit();
        }

        if ($id > 0) {
            $stmt = $con->prepare("UPDATE empresa_registro SET fecha=?, n_registro=?, n_tomo=?, descripcion=? WHERE id=?");
            $stmt->bind_param("ssssi", $fecha, $n_registro, $n_tomo, $descripcion, $id);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Registro mercantil actualizado.']);
        } else {
            $stmt = $con->prepare("INSERT INTO empresa_registro (id_empresa, id_sede, fecha, n_registro, n_tomo, descripcion) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iissss", $id_empresa, $id_sede, $fecha, $n_registro, $n_tomo, $descripcion);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['success' => true, 'message' => 'Registro mercantil guardado.']);
        }
        break;

    // 8. Eliminar Registro Mercantil
    case 'delete_registro':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM empresa_registro WHERE id = $id");
            echo json_encode(['success' => true, 'message' => 'Registro mercantil eliminado.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        }
        break;

    // 9. Guardar Expediente / Documento Digital (empresa_expediente)
    case 'save_expediente':
        $id_empresa = (int)($_POST['id_empresa'] ?? 0);
        $id_sede = (int)($_POST['id_sede'] ?? 1);
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $descripcion = trim($_POST['descripcion'] ?? '');

        if ($id_empresa <= 0 || empty($descripcion)) {
            echo json_encode(['success' => false, 'message' => 'La descripción del documento es requerida.']);
            exit();
        }

        $ruta_relativa = '';
        if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../uploads/expedientes/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0777, true);
            }
            $ext = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
            $filename = 'doc_' . time() . '_' . rand(100, 999) . '.' . $ext;
            move_uploaded_file($_FILES['archivo']['tmp_name'], $upload_dir . $filename);
            $ruta_relativa = 'uploads/expedientes/' . $filename;
        } else {
            echo json_encode(['success' => false, 'message' => 'Debe adjuntar un archivo para el expediente.']);
            exit();
        }

        $stmt = $con->prepare("INSERT INTO empresa_expediente (id_empresa, id_sede, fecha, descripcion, ruta) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisss", $id_empresa, $id_sede, $fecha, $descripcion, $ruta_relativa);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Documento adjuntado al expediente correctamente.']);
        break;

    // 10. Eliminar Expediente
    case 'delete_expediente':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $q = mysqli_query($con, "SELECT ruta FROM empresa_expediente WHERE id = $id");
            if ($r = mysqli_fetch_assoc($q)) {
                if (!empty($r['ruta']) && file_exists(__DIR__ . '/../../' . $r['ruta'])) {
                    @unlink(__DIR__ . '/../../' . $r['ruta']);
                }
            }
            mysqli_query($con, "DELETE FROM empresa_expediente WHERE id = $id");
            echo json_encode(['success' => true, 'message' => 'Documento eliminado del expediente.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'ID inválido.']);
        }
        break;

    // 11. Eliminar Perfil Completo
    case 'delete_perfil':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            mysqli_query($con, "DELETE FROM empresa_perfil WHERE id = $id");
            mysqli_query($con, "DELETE FROM empresa_firmantes WHERE id_empresa = $id");
            mysqli_query($con, "DELETE FROM empresa_registro WHERE id_empresa = $id");
            mysqli_query($con, "DELETE FROM empresa_expediente WHERE id_empresa = $id");
            echo json_encode(['success' => true, 'message' => 'Perfil de empresa eliminado.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'ID de empresa no válido.']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no especificada.']);
        break;
}

mysqli_close($con);
?>
