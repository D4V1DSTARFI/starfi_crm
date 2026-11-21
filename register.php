<?php
/**
 * Controlador de Registro para STARFI CRM
 * Procesa la creación de un nuevo usuario en estado INACTIVO sin ejecutar DDL en caliente
 * y cifrando contraseñas y respuestas de seguridad con PASSWORD_BCRYPT.
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit();
}

// Rate-limit por IP (máx. 5 registros cada 15 minutos)
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rl_file = rtrim(sys_get_temp_dir(), '/\\') . '/starfi_reg_rl_' . hash('sha256', $client_ip) . '.json';
$rl_data = ['count' => 0, 'first_ts' => time()];
if (is_file($rl_file)) {
    $dec = json_decode((string)@file_get_contents($rl_file), true);
    if (is_array($dec) && isset($dec['first_ts']) && (time() - (int)$dec['first_ts']) < 900) {
        $rl_data = $dec;
    }
}
if ((int)$rl_data['count'] >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Demasiadas solicitudes desde su dirección. Intente nuevamente más tarde.']);
    exit();
}

require_once __DIR__ . '/config/database.php';

$nombre = isset($_POST['nombre']) ? trim((string)$_POST['nombre']) : '';
$cedula = isset($_POST['cedula']) ? trim((string)$_POST['cedula']) : '';
$direccion = isset($_POST['direccion']) ? trim((string)$_POST['direccion']) : '';
$telefono = isset($_POST['telefono']) ? trim((string)$_POST['telefono']) : '';
$correo = isset($_POST['correo']) ? trim((string)$_POST['correo']) : '';

$usuario = isset($_POST['usuario']) ? trim((string)$_POST['usuario']) : '';
$contrasena = isset($_POST['contrasena']) ? (string)$_POST['contrasena'] : '';

$preguntas = (isset($_POST['preguntas']) && is_array($_POST['preguntas'])) ? $_POST['preguntas'] : [];

if ($nombre === '' || $cedula === '' || $correo === '' || $usuario === '' || $contrasena === '') {
    echo json_encode(['success' => false, 'message' => 'Por favor complete todos los campos obligatorios del registro.']);
    exit();
}

if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'El formato del correo electrónico no es válido.']);
    exit();
}

if (strlen($contrasena) < 8) {
    echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.']);
    exit();
}

if (count($preguntas) < 3) {
    echo json_encode(['success' => false, 'message' => 'Por favor configure al menos 3 preguntas de seguridad.']);
    exit();
}

$con = getDbConnection('core');
if (!$con) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
    exit();
}

// Verificar si el nombre de usuario ya existe
$stmt = $con->prepare("SELECT id FROM usuario WHERE LOWER(usuario) = LOWER(?) LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $usuario);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'El nombre de usuario ya está registrado.']);
        $stmt->close();
        mysqli_close($con);
        exit();
    }
    $stmt->close();
}

// Verificar si la cédula ya existe
$stmt = $con->prepare("SELECT id FROM usuario_perfil WHERE cedula = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $cedula);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'La cédula ya está registrada.']);
        $stmt->close();
        mysqli_close($con);
        exit();
    }
    $stmt->close();
}

// Verificar si el correo ya existe
$stmt = $con->prepare("SELECT id FROM usuario_perfil WHERE LOWER(correo) = LOWER(?) LIMIT 1");
if ($stmt) {
    $stmt->bind_param("s", $correo);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => 'El correo electrónico ya está registrado.']);
        $stmt->close();
        mysqli_close($con);
        exit();
    }
    $stmt->close();
}

mysqli_begin_transaction($con);

try {
    // 1. Insertar en usuario (INACTIVO por defecto y sin rol/sede asignada; cero ALTER TABLE en caliente)
    $contrasena_hash = password_hash($contrasena, PASSWORD_BCRYPT);

    $stmtUser = $con->prepare("INSERT INTO usuario (usuario, contrasena, estado, rol) VALUES (?, ?, 'INACTIVO', NULL)");
    if (!$stmtUser) {
        throw new Exception("Error al preparar el registro de usuario.");
    }
    $stmtUser->bind_param("ss", $usuario, $contrasena_hash);
    if (!$stmtUser->execute()) {
        $stmtUser->close();
        throw new Exception("Error al guardar las credenciales de usuario.");
    }
    $id_usuario = (int)mysqli_insert_id($con);
    $stmtUser->close();

    // 2. Insertar en usuario_perfil
    $stmtPerfil = $con->prepare("INSERT INTO usuario_perfil (id_usuario, nombre, cedula, direccion, telefono, correo) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmtPerfil) {
        throw new Exception("Error al preparar el registro de perfil.");
    }
    $stmtPerfil->bind_param("isssss", $id_usuario, $nombre, $cedula, $direccion, $telefono, $correo);
    if (!$stmtPerfil->execute()) {
        $stmtPerfil->close();
        throw new Exception("Error al guardar el perfil del usuario.");
    }
    $stmtPerfil->close();

    // 3. Insertar preguntas de seguridad cifradas con PASSWORD_BCRYPT
    $stmtPreguntas = $con->prepare("INSERT INTO preguntas_seguridad (id_usuario, pregunta, respuesta) VALUES (?, ?, ?)");
    if (!$stmtPreguntas) {
        throw new Exception("Error al preparar las preguntas de seguridad.");
    }

    foreach ($preguntas as $item) {
        $preg = isset($item['pregunta']) ? trim((string)$item['pregunta']) : '';
        $resp = isset($item['respuesta']) ? trim((string)$item['respuesta']) : '';

        if ($preg === '' || $resp === '') {
            $stmtPreguntas->close();
            throw new Exception("Preguntas o respuestas de seguridad vacías.");
        }

        $resp_hash = password_hash(mb_strtolower($resp, 'UTF-8'), PASSWORD_BCRYPT);
        $stmtPreguntas->bind_param("iss", $id_usuario, $preg, $resp_hash);
        if (!$stmtPreguntas->execute()) {
            $stmtPreguntas->close();
            throw new Exception("Error al guardar una de las preguntas de seguridad.");
        }
    }
    $stmtPreguntas->close();

    mysqli_commit($con);

    $rl_data['count'] = (int)$rl_data['count'] + 1;
    @file_put_contents($rl_file, json_encode($rl_data));

    echo json_encode(['success' => true, 'message' => '¡Usuario registrado correctamente! Su cuenta quedará pendiente de activación por el administrador.']);
} catch (Exception $e) {
    mysqli_rollback($con);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

mysqli_close($con);
?>
