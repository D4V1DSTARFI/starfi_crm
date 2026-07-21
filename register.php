<?php
/**
 * Controlador de Registro para STARFI CRM
 * Procesa la creación de un nuevo usuario en la base de datos de manera transaccional.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit();
}

require_once __DIR__ . '/config/database.php';

// Obtener y sanitizar datos de entrada
$nombre = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
$cedula = isset($_POST['cedula']) ? trim($_POST['cedula']) : '';
$direccion = isset($_POST['direccion']) ? trim($_POST['direccion']) : '';
$telefono = isset($_POST['telefono']) ? trim($_POST['telefono']) : '';
$correo = isset($_POST['correo']) ? trim($_POST['correo']) : '';

$usuario = isset($_POST['usuario']) ? trim($_POST['usuario']) : '';
$contrasena = isset($_POST['contrasena']) ? $_POST['contrasena'] : '';

$preguntas = isset($_POST['preguntas']) ? $_POST['preguntas'] : [];

// Validaciones básicas de campos requeridos
if (empty($nombre) || empty($cedula) || empty($correo) || empty($usuario) || empty($contrasena)) {
    echo json_encode(['success' => false, 'message' => 'Por favor complete todos los campos obligatorios del registro.']);
    exit();
}

if (count($preguntas) < 3) {
    echo json_encode(['success' => false, 'message' => 'Por favor configure al menos 3 preguntas de seguridad.']);
    exit();
}

// Conectar a la base de datos
$con = getDbConnection('core');
if (!$con) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
    exit();
}

// Verificar si el nombre de usuario ya existe
$stmt = $con->prepare("SELECT id FROM usuario WHERE usuario = ?");
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
$stmt = $con->prepare("SELECT id FROM usuario_perfil WHERE cedula = ?");
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
$stmt = $con->prepare("SELECT id FROM usuario_perfil WHERE correo = ?");
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

// Iniciar transacción
mysqli_begin_transaction($con);

try {
    // 1. Insertar en la tabla usuario
    $contrasena_hash = password_hash($contrasena, PASSWORD_BCRYPT);
    $stmtUser = $con->prepare("INSERT INTO usuario (usuario, contrasena) VALUES (?, ?)");
    if (!$stmtUser) {
        throw new Exception("Error al preparar el registro de usuario.");
    }
    $stmtUser->bind_param("ss", $usuario, $contrasena_hash);
    if (!$stmtUser->execute()) {
        throw new Exception("Error al guardar las credenciales de usuario.");
    }
    $id_usuario = mysqli_insert_id($con);
    $stmtUser->close();

    // 2. Insertar en la tabla usuario_perfil
    $stmtPerfil = $con->prepare("INSERT INTO usuario_perfil (id_usuario, nombre, cedula, direccion, telefono, correo) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmtPerfil) {
        throw new Exception("Error al preparar el registro de perfil.");
    }
    $stmtPerfil->bind_param("isssss", $id_usuario, $nombre, $cedula, $direccion, $telefono, $correo);
    if (!$stmtPerfil->execute()) {
        throw new Exception("Error al guardar el perfil del usuario.");
    }
    $stmtPerfil->close();

    // 3. Insertar preguntas de seguridad
    $stmtPreguntas = $con->prepare("INSERT INTO preguntas_seguridad (id_usuario, pregunta, respuesta) VALUES (?, ?, ?)");
    if (!$stmtPreguntas) {
        throw new Exception("Error al preparar las preguntas de seguridad.");
    }

    foreach ($preguntas as $item) {
        $preg = isset($item['pregunta']) ? trim($item['pregunta']) : '';
        $resp = isset($item['respuesta']) ? trim($item['respuesta']) : '';
        
        if (empty($preg) || empty($resp)) {
            throw new Exception("Preguntas o respuestas de seguridad vacías.");
        }
        
        // Encriptar o guardar las respuestas sanitizadas, aquí las guardamos en texto plano o hash para validación posterior.
        // Las guardaremos en minúsculas y limpias para facilitar coincidencias al recuperar, o texto plano simple.
        $resp_limpia = strtolower($resp);
        
        $stmtPreguntas->bind_param("iss", $id_usuario, $preg, $resp_limpia);
        if (!$stmtPreguntas->execute()) {
            throw new Exception("Error al guardar una de las preguntas de seguridad.");
        }
    }
    $stmtPreguntas->close();

    // Confirmar cambios
    mysqli_commit($con);
    echo json_encode(['success' => true, 'message' => '¡Usuario registrado correctamente!']);

} catch (Exception $e) {
    mysqli_rollback($con);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

mysqli_close($con);
?>
