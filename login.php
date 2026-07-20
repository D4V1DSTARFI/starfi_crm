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
        $email_clean = strtolower(trim($email));
        // Primero buscar en el nuevo sistema de usuarios
        $stmt_new = $con->prepare("SELECT u.id, up.nombre, u.contrasena FROM usuario u JOIN usuario_perfil up ON u.id = up.id_usuario WHERE u.usuario = ? OR up.correo = ?");
        if ($stmt_new) {
            $stmt_new->bind_param("ss", $email_clean, $email_clean);
            $stmt_new->execute();
            $res_new = $stmt_new->get_result();
            if ($row_new = $res_new->fetch_assoc()) {
                if (password_verify($password, $row_new['contrasena'])) {
                    // Cargar sesión
                    $_SESSION['agente_id'] = $row_new['id'];
                    $_SESSION['nombre_completo'] = $row_new['nombre'];
                    $_SESSION['last_activity'] = time();
                    header("Location: index.php");
                    exit();
                }
            }
            $stmt_new->close();
        }

        // Si no se inició sesión en la tabla nueva, buscar en usuarios_agentes (sistema heredado)
        $stmt = $con->prepare("SELECT id, nombre_completo, password_hash, estado FROM usuarios_agentes WHERE email = ?");
        if ($stmt) {
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
        /* Estilos del modal y pasos */
        .step-progress {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            position: relative;
        }
        .step-progress::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background-color: #e9ecef;
            z-index: 1;
            transform: translateY(-50%);
        }
        .step-progress-item {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background-color: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.9rem;
            position: relative;
            z-index: 2;
            transition: all 0.3s;
        }
        .step-progress-item.active {
            background-color: var(--primary);
            color: #fff;
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.2);
        }
        .step-progress-item.completed {
            background-color: #198754;
            color: #fff;
        }
        .step-content {
            display: none;
        }
        .step-content.active {
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
                <label class="login-label">Usuario / Correo</label>
                <input type="text" name="email" class="form-control-custom" placeholder="Ingrese su usuario o correo" required>
            </div>
            <div class="mb-4">
                <label class="login-label">Contraseña</label>
                <input type="password" name="password" class="form-control-custom" placeholder="••••••••" required>
            </div>
            <button type="submit" class="btn btn-starfi-primary w-100 py-2 fw-bold mb-3">Ingresar al CRM</button>
            
            <div class="text-center">
                <span class="text-muted" style="font-size: 0.85rem;">¿No tienes cuenta? </span>
                <a href="#" id="btnOpenRegister" class="text-starfi-primary fw-semibold" style="font-size: 0.85rem; text-decoration: none;" data-bs-toggle="modal" data-bs-target="#registerModal">Regístrate aquí</a>
            </div>
        </form>
    </div>

    <!-- Modal de Registro de 3 Pasos -->
    <div class="modal fade" id="registerModal" tabindex="-1" aria-labelledby="registerModalLabel" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0" style="border-radius: 12px; box-shadow: 0 15px 35px rgba(0,0,0,0.1);">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-starfi-dark" id="registerModalLabel">Registro de Nuevo Operador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body px-4 py-3">
                    <!-- Progreso de Pasos -->
                    <div class="step-progress">
                        <div class="step-progress-item active" id="prog-step-1">1</div>
                        <div class="step-progress-item" id="prog-step-2">2</div>
                        <div class="step-progress-item" id="prog-step-3">3</div>
                    </div>

                    <form id="registerForm">
                        <!-- PASO 1: Datos Personales -->
                        <div class="step-content active" id="step-1">
                            <h6 class="fw-bold mb-3 text-muted">Paso 1: Datos Personales</h6>
                            <div class="mb-3">
                                <label class="login-label">Nombre Completo <span class="text-danger">*</span></label>
                                <input type="text" id="reg_nombre" class="form-control-custom" placeholder="Ej. Juan Pérez" required>
                            </div>
                            <div class="mb-3">
                                <label class="login-label">Cédula <span class="text-danger">*</span></label>
                                <input type="text" id="reg_cedula" class="form-control-custom" placeholder="Ej. 12345678" required>
                            </div>
                            <div class="mb-3">
                                <label class="login-label">Dirección</label>
                                <textarea id="reg_direccion" class="form-control-custom" rows="2" placeholder="Dirección de habitación"></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="login-label">Teléfono</label>
                                <input type="text" id="reg_telefono" class="form-control-custom" placeholder="Ej. +584120000000">
                            </div>
                            <div class="mb-3">
                                <label class="login-label">Correo Electrónico <span class="text-danger">*</span></label>
                                <input type="email" id="reg_correo" class="form-control-custom" placeholder="Ej. juan@correo.com" required>
                            </div>
                        </div>

                        <!-- PASO 2: Usuario y Contraseña -->
                        <div class="step-content" id="step-2">
                            <h6 class="fw-bold mb-3 text-muted">Paso 2: Credenciales de Acceso</h6>
                            <div class="mb-3">
                                <label class="login-label">Usuario <span class="text-danger">*</span></label>
                                <input type="text" id="reg_usuario" class="form-control-custom" placeholder="Ej. juanperez" required>
                            </div>
                            <div class="mb-3">
                                <label class="login-label">Contraseña <span class="text-danger">*</span></label>
                                <input type="password" id="reg_contrasena" class="form-control-custom" placeholder="••••••••" required>
                            </div>
                            <div class="mb-3">
                                <label class="login-label">Confirmar Contraseña <span class="text-danger">*</span></label>
                                <input type="password" id="reg_confirmar_contrasena" class="form-control-custom" placeholder="••••••••" required>
                            </div>
                        </div>

                        <!-- PASO 3: Preguntas de Seguridad -->
                        <div class="step-content" id="step-3">
                            <h6 class="fw-bold mb-3 text-muted">Paso 3: Preguntas de Seguridad</h6>
                            <p class="text-muted" style="font-size: 0.8rem; line-height: 1.3;">Por favor, seleccione tres preguntas y escriba sus respuestas. Serán requeridas en caso de que necesite recuperar su acceso.</p>
                            
                            <div class="mb-3">
                                <label class="login-label">Pregunta 1 <span class="text-danger">*</span></label>
                                <select id="reg_pregunta_1" class="form-control-custom mb-2">
                                    <option value="¿Cuál es el nombre de tu primera mascota?">¿Cuál es el nombre de tu primera mascota?</option>
                                    <option value="¿En qué ciudad nació tu madre?">¿En qué ciudad nació tu madre?</option>
                                    <option value="¿Cuál es tu comida favorita?">¿Cuál es tu comida favorita?</option>
                                    <option value="¿Cuál es el nombre de tu primer colegio?">¿Cuál es el nombre de tu primer colegio?</option>
                                </select>
                                <input type="text" id="reg_respuesta_1" class="form-control-custom" placeholder="Respuesta 1" required>
                            </div>

                            <div class="mb-3">
                                <label class="login-label">Pregunta 2 <span class="text-danger">*</span></label>
                                <select id="reg_pregunta_2" class="form-control-custom mb-2">
                                    <option value="¿En qué ciudad nació tu madre?">¿En qué ciudad nació tu madre?</option>
                                    <option value="¿Cuál es el nombre de tu primera mascota?">¿Cuál es el nombre de tu primera mascota?</option>
                                    <option value="¿Cuál es tu comida favorita?">¿Cuál es tu comida favorita?</option>
                                    <option value="¿Cuál es tu color favorito?">¿Cuál es tu color favorito?</option>
                                </select>
                                <input type="text" id="reg_respuesta_2" class="form-control-custom" placeholder="Respuesta 2" required>
                            </div>

                            <div class="mb-3">
                                <label class="login-label">Pregunta 3 <span class="text-danger">*</span></label>
                                <select id="reg_pregunta_3" class="form-control-custom mb-2">
                                    <option value="¿Cuál es tu comida favorita?">¿Cuál es tu comida favorita?</option>
                                    <option value="¿Cuál es el nombre de tu primera mascota?">¿Cuál es el nombre de tu primera mascota?</option>
                                    <option value="¿En qué ciudad nació tu madre?">¿En qué ciudad nació tu madre?</option>
                                    <option value="¿Cuál era la marca de tu primer vehículo?">¿Cuál era la marca de tu primer vehículo?</option>
                                </select>
                                <input type="text" id="reg_respuesta_3" class="form-control-custom" placeholder="Respuesta 3" required>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-secondary py-2 px-3 fw-bold" id="btnBack" style="border-radius: 8px; font-size: 0.9rem; display: none;">Atrás</button>
                    <button type="button" class="btn btn-starfi-primary py-2 px-4 fw-bold ms-auto" id="btnNext" style="border-radius: 8px; font-size: 0.9rem;">Siguiente</button>
                    <button type="button" class="btn btn-success py-2 px-4 fw-bold ms-auto" id="btnSubmit" style="border-radius: 8px; font-size: 0.9rem; display: none;">Completar Registro</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts necesarios -->
    <script src="assets/js/jquery-3.7.1.min.js"></script>
    <script src="assets/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sweetalert2.all.min.js"></script>

    <script>
        $(document).ready(function() {
            let currentStep = 1;

            // Al abrir el modal, resetear al paso 1
            $('#registerModal').on('show.bs.modal', function () {
                currentStep = 1;
                showStep(currentStep);
                $('#registerForm')[0].reset();
            });

            // Función para cambiar de paso
            function showStep(step) {
                $('.step-content').removeClass('active');
                $('#step-' + step).addClass('active');

                // Actualizar barra de progreso
                $('.step-progress-item').removeClass('active completed');
                for (let i = 1; i <= 3; i++) {
                    if (i < step) {
                        $('#prog-step-' + i).addClass('completed');
                    } else if (i === step) {
                        $('#prog-step-' + i).addClass('active');
                    }
                }

                // Ajustar botones de control
                if (step === 1) {
                    $('#btnBack').hide();
                    $('#btnNext').show().addClass('ms-auto');
                    $('#btnSubmit').hide();
                } else if (step === 2) {
                    $('#btnBack').show();
                    $('#btnNext').show().removeClass('ms-auto');
                    $('#btnSubmit').hide();
                } else if (step === 3) {
                    $('#btnBack').show();
                    $('#btnNext').hide();
                    $('#btnSubmit').show();
                }
            }

            // Validar campos de un paso en específico
            function validateStep(step) {
                let isValid = true;
                
                if (step === 1) {
                    const nombre = $('#reg_nombre').val().trim();
                    const cedula = $('#reg_cedula').val().trim();
                    const correo = $('#reg_correo').val().trim();
                    
                    if (nombre === '' || cedula === '' || correo === '') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Campos pendientes',
                            text: 'Por favor complete todos los campos obligatorios del Paso 1.'
                        });
                        return false;
                    }
                    // Validar formato de email sencillo
                    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(correo)) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Email inválido',
                            text: 'Por favor ingrese un correo electrónico válido.'
                        });
                        return false;
                    }
                } else if (step === 2) {
                    const usuario = $('#reg_usuario').val().trim();
                    const contrasena = $('#reg_contrasena').val();
                    const confirmar = $('#reg_confirmar_contrasena').val();
                    
                    if (usuario === '' || contrasena === '' || confirmar === '') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Campos pendientes',
                            text: 'Por favor complete todos los campos de credenciales.'
                        });
                        return false;
                    }

                    if (contrasena !== confirmar) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Contraseñas no coinciden',
                            text: 'La contraseña de confirmación debe ser idéntica.'
                        });
                        return false;
                    }

                    if (contrasena.length < 4) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Contraseña muy corta',
                            text: 'La contraseña debe tener al menos 4 caracteres.'
                        });
                        return false;
                    }
                } else if (step === 3) {
                    const resp1 = $('#reg_respuesta_1').val().trim();
                    const resp2 = $('#reg_respuesta_2').val().trim();
                    const resp3 = $('#reg_respuesta_3').val().trim();

                    if (resp1 === '' || resp2 === '' || resp3 === '') {
                        Swal.fire({
                            icon: 'warning',
                            title: 'Respuestas vacías',
                            text: 'Debe responder las tres preguntas de seguridad.'
                        });
                        return false;
                    }
                }

                return true;
            }

            // Manejo de botones de navegación
            $('#btnNext').on('click', function() {
                if (validateStep(currentStep)) {
                    currentStep++;
                    showStep(currentStep);
                }
            });

            $('#btnBack').on('click', function() {
                currentStep--;
                showStep(currentStep);
            });

            // Enviar el formulario
            $('#btnSubmit').on('click', function() {
                if (!validateStep(3)) return;

                const data = {
                    nombre: $('#reg_nombre').val().trim(),
                    cedula: $('#reg_cedula').val().trim(),
                    direccion: $('#reg_direccion').val().trim(),
                    telefono: $('#reg_telefono').val().trim(),
                    correo: $('#reg_correo').val().trim(),
                    usuario: $('#reg_usuario').val().trim(),
                    contrasena: $('#reg_contrasena').val(),
                    preguntas: [
                        { pregunta: $('#reg_pregunta_1').val(), respuesta: $('#reg_respuesta_1').val().trim() },
                        { pregunta: $('#reg_pregunta_2').val(), respuesta: $('#reg_respuesta_2').val().trim() },
                        { pregunta: $('#reg_pregunta_3').val(), respuesta: $('#reg_respuesta_3').val().trim() }
                    ]
                };

                // Deshabilitar botón para evitar envíos múltiples
                $('#btnSubmit').prop('disabled', true).text('Registrando...');

                $.ajax({
                    url: 'register.php',
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(response) {
                        $('#btnSubmit').prop('disabled', false).text('Completar Registro');
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Registro Exitoso!',
                                text: response.message,
                                confirmButtonColor: '#E85B14'
                            }).then(() => {
                                $('#registerModal').modal('hide');
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error en el Registro',
                                text: response.message
                            });
                        }
                    },
                    error: function() {
                        $('#btnSubmit').prop('disabled', false).text('Completar Registro');
                        Swal.fire({
                            icon: 'error',
                            title: 'Error del Servidor',
                            text: 'No se pudo procesar la solicitud de registro en este momento.'
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>

