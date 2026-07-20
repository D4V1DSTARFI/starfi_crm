<?php
// login.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Si ya tiene sesión, mandarlo a la bandeja
if (isset($_SESSION['agente_id'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/config/database.php';

$error = '';
if (isset($_GET['error']) && $_GET['error'] == 'expired') {
    $error = "Su sesión expiró por inactividad.";
}

// Procesar el formulario de login con validación real en BD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Intentar conectar a la base de datos core
    $con = getDbConnection('core');
    
    if ($con) {
        $stmt = $con->prepare("SELECT id, nombre_completo, password_hash, estado FROM usuarios_agentes WHERE email = ?");
        if ($stmt) {
            $email_clean = strtolower(trim($email));
            $stmt->bind_param("s", $email_clean);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                if ($row['estado'] !== 'ACTIVO') {
                    $error = "El usuario está inactivo en el sistema.";
                } else if (password_verify($password, $row['password_hash']) || ($email_clean === 'master' && $password === '1234' && $row['password_hash'] === '1234')) {
                    // Cargar sesión
                    $_SESSION['agente_id'] = $row['id'];
                    $_SESSION['nombre_completo'] = $row['nombre_completo'];
                    $_SESSION['last_activity'] = time();
                    header("Location: index.php");
                    exit();
                } else {
                    $error = "Contraseña incorrecta. Inténtelo de nuevo.";
                }
            } else {
                // Fallback backdoor temporal si no existe en la base de datos aún
                if ($email_clean === 'master' && $password === '1234') {
                    $_SESSION['agente_id'] = 1;
                    $_SESSION['nombre_completo'] = "Acceso Master";
                    $_SESSION['last_activity'] = time();
                    header("Location: index.php");
                    exit();
                } else {
                    $error = "El usuario no está registrado.";
                }
            }
            $stmt->close();
        } else {
            $error = "Error al preparar la consulta de seguridad.";
        }
    } else {
        // Si no hay conexión de BD, pero coincide con el backdoor, permitir acceso temporal de emergencia
        if (strtolower(trim($email)) === 'master' && $password === '1234') {
            $_SESSION['agente_id'] = 1;
            $_SESSION['nombre_completo'] = "Acceso Master (Modo Emergencia)";
            $_SESSION['last_activity'] = time();
            header("Location: index.php");
            exit();
        } else {
            $error = "Error de conexión a la base de datos central.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | STARFI CRM</title>
    <link rel="icon" href="docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/starfi_theme.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: var(--bg-main);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-family);
        }
        .login-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            width: 100%;
            max-width: 400px;
            padding: 40px;
            border: 1px solid var(--border-color);
        }
        .logo-container {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo-container img {
            height: 45px;
            margin-bottom: 10px;
        }
        .form-control-custom {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-size: 0.95rem;
            background-color: #F8FAFC;
            transition: all 0.2s;
        }
        .form-control-custom:focus {
            outline: none;
            border-color: var(--primary);
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(232, 91, 20, 0.1);
        }
        .login-label {
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 5px;
            display: block;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-container">
            <img src="docs/identidad_visual/logos/logo_starfi.png" alt="STARFI CRM">
            <h5 class="brand-font fw-bold mt-2 text-starfi-dark">Portal de Operadores</h5>
        </div>

        <?php if($error): ?>
            <div class="alert alert-danger" style="font-size: 0.85rem; padding: 10px;"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="login-label">Usuario</label>
                <input type="text" name="email" class="form-control-custom" placeholder="Usuario master" required>
            </div>
            <div class="mb-4">
                <label class="login-label">Contraseña</label>
                <input type="password" name="password" class="form-control-custom" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-starfi-primary w-100 py-2 fw-bold">Ingresar al CRM</button>
        </form>
    </div>
</body>
</html>
