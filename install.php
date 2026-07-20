<?php
/**
 * STARFI CRM - Asistente de Instalación y Migración
 * Diseñado con una interfaz premium para configurar bases de datos e importar registros.
 */

// Aumentar límites para la importación de bases de datos pesadas
set_time_limit(600);
ini_set('memory_limit', '512M');

$envPath = __DIR__ . '/.env';
$isInstalled = false;

// Comprobar si ya está instalado y funcionando
if (file_exists($envPath)) {
    $env = @parse_ini_file($envPath);
    if (!empty($env['DB_HOST']) && !empty($env['DB_USER'])) {
        $con = @mysqli_connect($env['DB_HOST'], $env['DB_USER'], $env['DB_PASS'] ?? '');
        if ($con) {
            // Solo consideramos completamente instalado si ya se completó la importación
            // de tablas y la creación de agentes.
            $db_selected = @mysqli_select_db($con, 'starfi_crm');
            if ($db_selected) {
                $check_table = @mysqli_query($con, "SHOW TABLES LIKE 'usuarios_agentes'");
                if ($check_table && mysqli_num_rows($check_table) > 0) {
                    $isInstalled = true;
                }
            }
            @mysqli_close($con);
        }
    }
}

// Procesar peticiones AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // Si ya está instalado, denegar AJAX por seguridad (excepto si se solicita explícitamente re-check)
    if ($isInstalled && $action !== 'check_status') {
        echo json_encode(['status' => 'error', 'message' => 'El sistema ya se encuentra instalado. Por seguridad, no se permiten modificaciones.']);
        exit;
    }

    switch ($action) {
        case 'test_connection':
            $host = $_POST['host'] ?? 'localhost';
            $user = $_POST['user'] ?? 'root';
            $pass = $_POST['pass'] ?? '';
            
            $conn = @mysqli_connect($host, $user, $pass);
            if (!$conn) {
                echo json_encode([
                    'status' => 'error', 
                    'message' => 'No se pudo conectar a MySQL: ' . mysqli_connect_error()
                ]);
            } else {
                mysqli_close($conn);
                echo json_encode(['status' => 'success', 'message' => 'Conexión exitosa con el servidor MySQL.']);
            }
            exit;

        case 'write_env':
            $host = $_POST['host'] ?? 'localhost';
            $user = $_POST['user'] ?? 'root';
            $pass = $_POST['pass'] ?? '';

            // Escribir archivo .env
            $envContent = "DB_HOST=\"$host\"\n";
            $envContent .= "DB_USER=\"$user\"\n";
            $envContent .= "DB_PASS=\"$pass\"\n";
            $envContent .= "DB_NAME=\"starfi_crm\"\n\n";
            $envContent .= "WEBHOOK_VERIFY_TOKEN=\"PARALELEPIPEDO3312\"\n";

            if (@file_put_contents($envPath, $envContent) !== false) {
                echo json_encode(['status' => 'success', 'message' => 'Archivo de entorno .env configurado correctamente.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'No se pudo escribir el archivo .env. Compruebe los permisos de escritura en la carpeta raíz.']);
            }
            exit;

        case 'import_db':
            $host = $_POST['host'] ?? 'localhost';
            $user = $_POST['user'] ?? 'root';
            $pass = $_POST['pass'] ?? '';
            $db = $_POST['db'] ?? '';

            $databasesList = ['starfi_crm'];
            if (!in_array($db, $databasesList)) {
                echo json_encode(['status' => 'error', 'message' => 'Base de datos no válida.']);
                exit;
            }

            $sqlFile = __DIR__ . '/database/install_dumps/' . $db . '.sql';
            if (!file_exists($sqlFile)) {
                echo json_encode(['status' => 'error', 'message' => "No se encontró el archivo de volcado para la base de datos: $db"]);
                exit;
            }

            // Realizar importación secuencial y eficiente
            $result = importSqlFileNative($host, $user, $pass, $sqlFile);
            if ($result === true) {
                echo json_encode(['status' => 'success', 'message' => "Base de datos '$db' importada correctamente."]);
            } else {
                echo json_encode(['status' => 'error', 'message' => "Error importando '$db': $result"]);
            }
            exit;

        case 'create_master':
            $host = $_POST['host'] ?? 'localhost';
            $user = $_POST['user'] ?? 'root';
            $pass = $_POST['pass'] ?? '';

            $conn = @mysqli_connect($host, $user, $pass, 'starfi_crm');
            if (!$conn) {
                echo json_encode(['status' => 'error', 'message' => 'No se pudo conectar a starfi_crm para crear el usuario master.']);
                exit;
            }

            // Asegurar que existan la empresa y sede por defecto
            mysqli_query($conn, "INSERT INTO empresas (id, nombre_comercial, razon_social, documento_identidad) 
                                 VALUES (1, 'Empresa Corp. S.A.', 'Empresas Corporativas C.A.', 'J-12345678-9') 
                                 ON DUPLICATE KEY UPDATE id=id");
            
            mysqli_query($conn, "INSERT INTO sedes (id, id_empresa, nombre_sede) 
                                 VALUES (1, 1, 'Sede Principal') 
                                 ON DUPLICATE KEY UPDATE id=id");

            // Crear o actualizar usuario master
            $passHash = password_hash('1234', PASSWORD_DEFAULT);
            $query = "INSERT INTO usuarios_agentes (id, id_empresa, id_sede, nombre_completo, email, password_hash, rol, limite_chats_simultaneos, estado) 
                      VALUES (1, 1, 1, 'Usuario Master', 'master', '$passHash', 'ADMIN', 999, 'ACTIVO') 
                      ON DUPLICATE KEY UPDATE password_hash='$passHash', email='master', rol='ADMIN', estado='ACTIVO'";

            if (mysqli_query($conn, $query)) {
                mysqli_close($conn);
                echo json_encode(['status' => 'success', 'message' => 'Usuario master (master / 1234) configurado y validado en la base de datos.']);
            } else {
                $err = mysqli_error($conn);
                mysqli_close($conn);
                echo json_encode(['status' => 'error', 'message' => 'No se pudo insertar el usuario master: ' . $err]);
            }
            exit;
    }
}

/**
 * Función auxiliar para importar un archivo SQL línea por línea, evitando desbordamiento de memoria y tiempos de espera cortos
 */
function importSqlFileNative($host, $user, $pass, $filePath) {
    $conn = @mysqli_connect($host, $user, $pass);
    if (!$conn) {
        return "Conexión fallida: " . mysqli_connect_error();
    }
    
    mysqli_set_charset($conn, "utf8mb4");

    $fp = fopen($filePath, 'r');
    if (!$fp) {
        mysqli_close($conn);
        return "No se pudo abrir el archivo de volcado SQL.";
    }

    // Desactivar temporalmente verificaciones de claves foráneas para acelerar importación y evitar problemas de orden
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 0");
    mysqli_query($conn, "SET UNIQUE_CHECKS = 0");
    mysqli_query($conn, "SET AUTOCOMMIT = 0");

    $query = '';
    while (($line = fgets($fp)) !== false) {
        // Ignorar comentarios e instrucciones vacías
        $trimmed = trim($line);
        if ($trimmed === '' || substr($trimmed, 0, 2) === '--' || substr($trimmed, 0, 2) === '/*' || substr($trimmed, 0, 1) === '#') {
            continue;
        }

        $query .= $line;

        // Si la línea termina con punto y coma, ejecutar la sentencia
        if (substr(rtrim($trimmed), -1) === ';') {
            if (!mysqli_query($conn, $query)) {
                // Log de error interno pero continuar
                $errorMsg = mysqli_error($conn);
                // Si es un error crítico como falta de permisos o sintaxis incorrecta grave
                // pero ignoramos si es por elementos ya existentes
                if (stripos($errorMsg, 'syntax error') !== false) {
                    fclose($fp);
                    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
                    mysqli_close($conn);
                    return "Error SQL: $errorMsg en consulta: $query";
                }
            }
            $query = '';
        }
    }

    mysqli_query($conn, "COMMIT");
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS = 1");
    mysqli_query($conn, "SET UNIQUE_CHECKS = 1");

    fclose($fp);
    mysqli_close($conn);
    return true;
}

// Ejecutar pre-chequeos del sistema
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, '7.4.0', '>=');
$mysqliLoaded = extension_loaded('mysqli');
$jsonLoaded = extension_loaded('json');
$sessionOk = function_exists('session_start');
$writableDir = is_writable(__DIR__);
$envWritable = file_exists($envPath) ? is_writable($envPath) : is_writable(__DIR__);

// Comprobar archivos SQL
$databases = ['starfi_crm'];
$sqlFilesCount = 0;
$missingDumps = [];
foreach ($databases as $db) {
    $f = __DIR__ . '/database/install_dumps/' . $db . '.sql';
    if (file_exists($f) && filesize($f) > 100) {
        $sqlFilesCount++;
    } else {
        $missingDumps[] = $db . '.sql';
    }
}
$dumpsOk = ($sqlFilesCount === count($databases));

$allChecksOk = ($phpOk && $mysqliLoaded && $jsonLoaded && $sessionOk && $writableDir && $envWritable && $dumpsOk);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador STARFI CRM | Migración y Configuración</title>
    <link rel="icon" href="docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/starfi_theme.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #E85B14;
            --primary-hover: #CC4A0E;
            --dark-blue: #0F172A;
            --border-color: #E2E8F0;
        }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: #334155;
        }
        .install-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 650px;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .install-header {
            background-color: var(--dark-blue);
            padding: 30px;
            text-align: center;
            border-bottom: 4px solid var(--primary);
            position: relative;
        }
        .install-header img {
            height: 48px;
            margin-bottom: 12px;
        }
        .install-header h3 {
            color: #ffffff;
            font-family: 'Outfit', sans-serif !important;
            font-weight: 700;
            margin: 0;
        }
        .install-header p {
            color: #94A3B8;
            font-size: 0.9rem;
            margin: 5px 0 0 0;
        }
        .install-body {
            padding: 40px;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 35px;
            position: relative;
        }
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 15px;
            left: 0;
            right: 0;
            height: 3px;
            background-color: #E2E8F0;
            z-index: 1;
        }
        .step-dot {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #ffffff;
            border: 3px solid #E2E8F0;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            color: #94A3B8;
            transition: all 0.3s;
        }
        .step-dot.active {
            border-color: var(--primary);
            color: var(--primary);
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.15);
        }
        .step-dot.complete {
            background-color: var(--primary);
            border-color: var(--primary);
            color: #ffffff;
        }
        .install-step {
            display: none;
        }
        .install-step.active {
            display: block;
            animation: fadeIn 0.4s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            background-color: #F8FAFC;
            border-radius: 10px;
            margin-bottom: 10px;
            border: 1px solid #F1F5F9;
        }
        .check-item span {
            font-weight: 500;
            font-size: 0.95rem;
        }
        .badge-check {
            font-size: 0.8rem;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 600;
        }
        .btn-install-primary {
            background-color: var(--primary);
            border: none;
            color: #ffffff;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-install-primary:hover {
            background-color: var(--primary-hover);
            transform: translateY(-1px);
        }
        .btn-install-primary:disabled {
            background-color: #CBD5E1;
            transform: none;
            cursor: not-allowed;
        }
        .form-label-custom {
            font-weight: 600;
            font-size: 0.85rem;
            color: #475569;
            margin-bottom: 6px;
        }
        .form-control-custom {
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            font-size: 0.95rem;
            background-color: #F8FAFC;
            transition: all 0.2s;
        }
        .form-control-custom:focus {
            outline: none;
            border-color: var(--primary);
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(232, 91, 20, 0.1);
        }
        .import-list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 18px;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            margin-bottom: 10px;
            background-color: #ffffff;
            transition: background-color 0.2s;
        }
        .import-list-item.pending {
            border-left: 4px solid #94A3B8;
        }
        .import-list-item.running {
            border-left: 4px solid var(--primary);
            background-color: #FFFDFB;
        }
        .import-list-item.success {
            border-left: 4px solid #10B981;
            background-color: #F0FDF4;
        }
        .import-list-item.error {
            border-left: 4px solid #EF4444;
            background-color: #FEF2F2;
        }
        .progress-bar-custom {
            height: 8px;
            border-radius: 4px;
            background-color: #E2E8F0;
            overflow: hidden;
            margin-bottom: 25px;
        }
        .progress-bar-fill {
            height: 100%;
            background-color: var(--primary);
            width: 0%;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body>

    <div class="install-card">
        <div class="install-header">
            <img src="docs/identidad_visual/logos/logo_starfi.png" alt="STARFI logo">
            <h3>Instalación y Migración</h3>
            <p>STARFI CRM Omnicanal - Configuración del Sistema</p>
        </div>

        <div class="install-body">
            
            <?php if ($isInstalled): ?>
                <!-- Locked Screen if already installed -->
                <div class="text-center py-4">
                    <div class="mb-4">
                        <i class="fa-solid fa-lock text-warning" style="font-size: 4rem; animation: pulse 2s infinite;"></i>
                    </div>
                    <h4 class="fw-bold text-dark mb-2">Sistema Ya Instalado</h4>
                    <p class="text-muted mb-4 px-3">
                        El archivo de entorno <code>.env</code> ya contiene una configuración de base de datos activa y operativa.
                        Por razones de seguridad, el instalador ha sido bloqueado para evitar sobreescribir la información.
                    </p>
                    <div class="alert alert-info text-start small mb-4">
                        <i class="fa-solid fa-circle-info me-2"></i><strong>¿Deseas volver a instalar?</strong><br>
                        Debes eliminar o renombrar el archivo <code>.env</code> ubicado en la raíz del proyecto para desbloquear el asistente.
                    </div>
                    <a href="login.php" class="btn btn-install-primary px-5 py-3">
                        <i class="fa-solid fa-right-to-bracket me-2"></i> Ir al Login de Operadores
                    </a>
                </div>
            <?php else: ?>
                <!-- Steps Indicator -->
                <div class="step-indicator">
                    <div class="step-dot active" id="dot-1">1</div>
                    <div class="step-dot" id="dot-2">2</div>
                    <div class="step-dot" id="dot-3">3</div>
                    <div class="step-dot" id="dot-4">4</div>
                </div>

                <!-- STEP 1: PRE-CHECKS -->
                <div class="install-step active" id="step-1">
                    <h5 class="fw-bold mb-3 text-dark">Paso 1: Verificación de Requisitos</h5>
                    <p class="text-muted small mb-4">Antes de comenzar, validamos que tu servidor local cumpla con los requerimientos necesarios para alojar STARFI CRM.</p>
                    
                    <div class="check-list mb-4">
                        <div class="check-item">
                            <span>Versión PHP (>= 7.4.0) <small class="text-muted">(Detectada: <?= $phpVersion ?>)</small></span>
                            <span class="badge badge-check <?= $phpOk ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                                <?= $phpOk ? '<i class="fa-solid fa-check me-1"></i> Correcto' : '<i class="fa-solid fa-xmark me-1"></i> Requerido' ?>
                            </span>
                        </div>
                        <div class="check-item">
                            <span>Extensión MySQLi</span>
                            <span class="badge badge-check <?= $mysqliLoaded ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                                <?= $mysqliLoaded ? '<i class="fa-solid fa-check me-1"></i> Activo' : '<i class="fa-solid fa-xmark me-1"></i> Inactivo' ?>
                            </span>
                        </div>
                        <div class="check-item">
                            <span>Soporte JSON</span>
                            <span class="badge badge-check <?= $jsonLoaded ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                                <?= $jsonLoaded ? '<i class="fa-solid fa-check me-1"></i> Activo' : '<i class="fa-solid fa-xmark me-1"></i> Inactivo' ?>
                            </span>
                        </div>
                        <div class="check-item">
                            <span>Sesiones PHP</span>
                            <span class="badge badge-check <?= $sessionOk ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                                <?= $sessionOk ? '<i class="fa-solid fa-check me-1"></i> Activo' : '<i class="fa-solid fa-xmark me-1"></i> Inactivo' ?>
                            </span>
                        </div>
                        <div class="check-item">
                            <span>Escritura de Archivo <code>.env</code></span>
                            <span class="badge badge-check <?= $envWritable ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                                <?= $envWritable ? '<i class="fa-solid fa-check me-1"></i> Permitido' : '<i class="fa-solid fa-xmark me-1"></i> Denegado' ?>
                            </span>
                        </div>
                        <div class="check-item">
                            <span>Dumps SQL de Migración (6 archivos)</span>
                            <span class="badge badge-check <?= $dumpsOk ? 'bg-success text-white' : 'bg-danger text-white' ?>">
                                <?= $dumpsOk ? '<i class="fa-solid fa-check me-1"></i> Listos' : '<i class="fa-solid fa-xmark me-1"></i> Faltan archivos' ?>
                            </span>
                        </div>
                    </div>

                    <?php if (!$dumpsOk && !empty($missingDumps)): ?>
                        <div class="alert alert-warning py-2 mb-4" style="font-size: 0.85rem;">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i>
                            <strong>Atención:</strong> Faltan los siguientes archivos SQL en <code>database/install_dumps/</code>: 
                            <?= implode(', ', $missingDumps) ?>. Ejecute <code>php export_databases.php</code> en el servidor de origen.
                        </div>
                    <?php endif; ?>

                    <div class="text-end">
                        <button class="btn btn-install-primary" id="btn-to-step-2" <?= $allChecksOk ? '' : 'disabled' ?>>
                            Continuar <i class="fa-solid fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>

                <!-- STEP 2: DB CONFIG -->
                <div class="install-step" id="step-2">
                    <h5 class="fw-bold mb-3 text-dark">Paso 2: Conexión de Base de Datos</h5>
                    <p class="text-muted small mb-4">Ingresa las credenciales del servidor MySQL para configurar el archivo de variables de entorno y crear las bases de datos necesarias.</p>
                    
                    <form id="db-form" class="mb-4">
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label class="form-label-custom">Host del Servidor</label>
                                <input type="text" id="db_host" class="form-control form-control-custom w-100" value="localhost" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label-custom">Puerto</label>
                                <input type="text" id="db_port" class="form-control form-control-custom w-100" value="3306" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-custom">Usuario MySQL</label>
                            <input type="text" id="db_user" class="form-control form-control-custom w-100" value="root" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label-custom">Contraseña MySQL</label>
                            <input type="password" id="db_pass" class="form-control form-control-custom w-100" placeholder="Dejar vacío si no tiene">
                        </div>
                    </form>

                    <div class="d-flex justify-content-between">
                        <button class="btn btn-outline-secondary" onclick="changeStep(1)">
                            <i class="fa-solid fa-arrow-left me-2"></i> Atrás
                        </button>
                        <div>
                            <button class="btn btn-outline-primary me-2 fw-semibold" id="btn-test-db">
                                <i class="fa-solid fa-plug me-2"></i> Probar Conexión
                            </button>
                            <button class="btn btn-install-primary" id="btn-to-step-3" disabled>
                                Siguiente <i class="fa-solid fa-arrow-right ms-2"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: MIGRATION & DATA IMPORT -->
                <div class="install-step" id="step-3">
                    <h5 class="fw-bold mb-3 text-dark">Paso 3: Carga de Datos y Migración</h5>
                    <p class="text-muted small mb-4">Importando las bases de datos del sistema original. Esto restaurará todas las configuraciones, plantillas, historiales de chat y registros.</p>
                    
                    <div class="progress-bar-custom">
                        <div class="progress-bar-fill" id="import-progress"></div>
                    </div>

                    <div class="import-list mb-4">
                        <div class="import-list-item pending" id="db-item-starfi_crm">
                            <div>
                                <i class="fa-solid fa-database text-muted me-2"></i>
                                <strong>starfi_crm</strong> (Configuración del CRM, Agentes)
                            </div>
                            <span class="status-badge text-muted small"><i class="fa-solid fa-clock me-1"></i> Esperando</span>
                        </div>
                    </div>

                    <div class="text-end">
                        <button class="btn btn-install-primary px-4 py-3" id="btn-start-import">
                            <i class="fa-solid fa-play me-2"></i> Iniciar Importación de Datos
                        </button>
                    </div>
                </div>

                <!-- STEP 4: SUCCESS -->
                <div class="install-step text-center" id="step-4">
                    <div class="mb-4">
                        <i class="fa-solid fa-circle-check text-success" style="font-size: 5rem;"></i>
                    </div>
                    
                    <h4 class="fw-bold text-dark mb-2">¡Instalación Exitosa!</h4>
                    <p class="text-muted px-4 mb-4">
                        Todas las bases de datos de STARFI han sido restauradas y migradas correctamente. El usuario master ha sido configurado para permitir el acceso inicial de seguridad.
                    </p>

                    <div class="card border border-light-subtle bg-light text-start p-3 mb-4 rounded-3 shadow-sm">
                        <h6 class="fw-bold text-dark border-bottom pb-2 mb-2"><i class="fa-solid fa-key me-2 text-warning"></i>Credenciales de Administrador</h6>
                        <div class="row small mb-1">
                            <div class="col-4 text-muted">Usuario:</div>
                            <div class="col-8 fw-semibold text-dark">master</div>
                        </div>
                        <div class="row small">
                            <div class="col-4 text-muted">Contraseña:</div>
                            <div class="col-8 fw-semibold text-dark">1234</div>
                        </div>
                    </div>

                    <a href="login.php" class="btn btn-install-primary w-100 py-3 fw-bold fs-6">
                        <i class="fa-solid fa-right-to-bracket me-2"></i> Entrar al CRM de STARFI
                    </a>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <!-- Scripts locales -->
    <script src="assets/js/jquery-3.7.1.min.js"></script>
    <script src="assets/js/sweetalert2.all.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let dbHost = 'localhost';
        let dbUser = 'root';
        let dbPass = '';

        function changeStep(step) {
            $('.install-step').removeClass('active');
            $('#step-' + step).addClass('active');
            
            $('.step-dot').removeClass('active complete');
            for(let i=1; i<=4; i++) {
                let dot = $('#dot-' + i);
                if (i < step) dot.addClass('complete');
                else if (i === step) dot.addClass('active');
            }
        }

        $(document).ready(function() {
            // Ir al paso 2
            $('#btn-to-step-2').click(function() {
                changeStep(2);
            });

            // Probar conexión
            $('#btn-test-db').click(function() {
                let host = $('#db_host').val().trim();
                let port = $('#db_port').val().trim();
                let user = $('#db_user').val().trim();
                let pass = $('#db_pass').val();
                
                if(!host || !user) {
                    Swal.fire('Atención', 'El host y el usuario de la base de datos son campos obligatorios.', 'warning');
                    return;
                }

                let fullHost = host;
                if(port && port !== '3306') {
                    fullHost = host + ':' + port;
                }

                $('#btn-test-db').html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Probando...').prop('disabled', true);

                $.ajax({
                    url: 'install.php?action=test_connection',
                    method: 'POST',
                    data: { host: fullHost, user: user, pass: pass },
                    dataType: 'json',
                    success: function(res) {
                        $('#btn-test-db').html('<i class="fa-solid fa-plug me-2"></i> Probar Conexión').prop('disabled', false);
                        if(res.status === 'success') {
                            dbHost = fullHost;
                            dbUser = user;
                            dbPass = pass;
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'Conexión Exitosa',
                                text: 'Se ha establecido comunicación con el servidor MySQL.',
                                timer: 2000,
                                showConfirmButton: false
                            });
                            
                            $('#btn-to-step-3').prop('disabled', false);
                        } else {
                            Swal.fire('Error de Conexión', res.message, 'error');
                            $('#btn-to-step-3').prop('disabled', true);
                        }
                    },
                    error: function() {
                        $('#btn-test-db').html('<i class="fa-solid fa-plug me-2"></i> Probar Conexión').prop('disabled', false);
                        Swal.fire('Error', 'Fallo en la comunicación con el instalador web.', 'error');
                    }
                });
            });

            // Ir al paso 3
            $('#btn-to-step-3').click(function() {
                // Escribir archivo .env antes de ir al paso 3
                $.ajax({
                    url: 'install.php?action=write_env',
                    method: 'POST',
                    data: { host: dbHost, user: dbUser, pass: dbPass },
                    dataType: 'json',
                    success: function(res) {
                        if(res.status === 'success') {
                            changeStep(3);
                        } else {
                            Swal.fire('Error de Configuración', res.message, 'error');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'No se pudo configurar el archivo de entorno .env.', 'error');
                    }
                });
            });

            // Iniciar importaciones
            $('#btn-start-import').click(function() {
                $('#btn-start-import').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Importando datos...');
                                const databases = ['starfi_crm'];
                let currentIndex = 0;

                function importNext() {
                    if(currentIndex >= databases.length) {
                        // Al terminar de importar todo, crear el usuario master
                        createMasterUser();
                        return;
                    }

                    let db = databases[currentIndex];
                    let item = $('#db-item-' + db);
                    
                    item.removeClass('pending').addClass('running');
                    item.find('.status-badge').html('<i class="fa-solid fa-spinner fa-spin me-1 text-primary"></i> Procesando...');

                    $.ajax({
                        url: 'install.php?action=import_db',
                        method: 'POST',
                        data: { host: dbHost, user: dbUser, pass: dbPass, db: db },
                        dataType: 'json',
                        success: function(res) {
                            if(res.status === 'success') {
                                item.removeClass('running').addClass('success');
                                item.find('.status-badge').removeClass('text-muted').addClass('text-success').html('<i class="fa-solid fa-circle-check me-1"></i> Listo');
                                
                                currentIndex++;
                                let progressPercent = Math.round((currentIndex / databases.length) * 100);
                                $('#import-progress').css('width', progressPercent + '%');
                                
                                importNext();
                            } else {
                                item.removeClass('running').addClass('error');
                                item.find('.status-badge').removeClass('text-muted').addClass('text-danger').html('<i class="fa-solid fa-circle-xmark me-1"></i> Falló');
                                
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error de Importación',
                                    text: 'Hubo un error importando la base de datos ' + db + ': ' + res.message,
                                    confirmButtonText: 'Reintentar'
                                }).then(function() {
                                    // Permitir al usuario reintentar
                                    $('#btn-start-import').prop('disabled', false).html('<i class="fa-solid fa-rotate-right me-2"></i> Reintentar Importación');
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            item.removeClass('running').addClass('error');
                            item.find('.status-badge').removeClass('text-muted').addClass('text-danger').html('<i class="fa-solid fa-circle-xmark me-1"></i> Error');
                            
                            Swal.fire({
                                icon: 'error',
                                title: 'Error Crítico',
                                text: 'Fallo la conexión de red o tiempo de espera agotado al importar: ' + db,
                                confirmButtonText: 'Entendido'
                            }).then(function() {
                                $('#btn-start-import').prop('disabled', false).html('<i class="fa-solid fa-rotate-right me-2"></i> Reintentar Importación');
                            });
                        }
                    });
                }

                importNext();
            });

            function createMasterUser() {
                $.ajax({
                    url: 'install.php?action=create_master',
                    method: 'POST',
                    data: { host: dbHost, user: dbUser, pass: dbPass },
                    dataType: 'json',
                    success: function(res) {
                        if(res.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Completado!',
                                text: 'Toda la base de datos y configuraciones se han cargado.',
                                timer: 2500,
                                showConfirmButton: false
                            });
                            changeStep(4);
                        } else {
                            Swal.fire('Error', res.message, 'error');
                            $('#btn-start-import').prop('disabled', false).html('<i class="fa-solid fa-rotate-right me-2"></i> Reintentar');
                        }
                    },
                    error: function() {
                        Swal.fire('Error', 'Fallo al configurar el usuario master de seguridad.', 'error');
                        $('#btn-start-import').prop('disabled', false).html('<i class="fa-solid fa-rotate-right me-2"></i> Reintentar');
                    }
                });
            }
        });
    </script>
</body>
</html>
