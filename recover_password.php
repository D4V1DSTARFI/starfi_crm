<?php
/**
 * Controlador de Recuperación de Contraseña para STARFI CRM
 * Blindado contra fuga de OTP, enumeración, fuerza bruta y salto de preguntas de seguridad.
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/audit.php';

// Rate-limit por IP + sesión (máx. 5 intentos fallidos cada 15 minutos)
$client_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rl_path = rtrim(sys_get_temp_dir(), '/\\') . '/starfi_recover_rl_' . hash('sha256', $client_ip) . '.json';

function starfi_recovery_get_rl($path) {
    $default = ['fails' => 0, 'first_ts' => time()];
    if (!is_file($path)) {
        return $default;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data) || !isset($data['first_ts']) || (time() - (int)$data['first_ts']) >= 900) {
        return $default;
    }
    return $data;
}

function starfi_recovery_add_fail($path) {
    $data = starfi_recovery_get_rl($path);
    $data['fails'] = (int)($data['fails'] ?? 0) + 1;
    @file_put_contents($path, json_encode($data));
    $_SESSION['recovery_fails'] = (int)($_SESSION['recovery_fails'] ?? 0) + 1;
    $_SESSION['recovery_fail_ts'] = $_SESSION['recovery_fail_ts'] ?? time();
}

function starfi_recovery_clear_fails($path) {
    @unlink($path);
    unset($_SESSION['recovery_fails'], $_SESSION['recovery_fail_ts']);
}

$rl_state = starfi_recovery_get_rl($rl_path);
$sess_fails = 0;
if (isset($_SESSION['recovery_fail_ts']) && (time() - (int)$_SESSION['recovery_fail_ts']) < 900) {
    $sess_fails = (int)($_SESSION['recovery_fails'] ?? 0);
} else {
    unset($_SESSION['recovery_fails'], $_SESSION['recovery_fail_ts']);
}

if ((int)$rl_state['fails'] >= 5 || $sess_fails >= 5) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Demasiados intentos fallidos de recuperación. Por seguridad, intente nuevamente en 15 minutos.'
    ]);
    exit();
}

$action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';

$con = getDbConnection('core');
if (!$con) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
    exit();
}

switch ($action) {
    case 'verify_cedula':
        $cedula = isset($_POST['cedula']) ? trim((string)$_POST['cedula']) : '';
        if ($cedula === '') {
            echo json_encode(['success' => false, 'message' => 'Por favor ingrese su cédula.']);
            break;
        }

        // Limpiar cualquier verificación previa al iniciar un nuevo flujo
        unset($_SESSION['recovery_user_id'], $_SESSION['recovery_verified_user_id'], $_SESSION['recovery_otp_tries']);

        $stmt = $con->prepare("SELECT up.id_usuario, up.correo FROM usuario_perfil up JOIN usuario u ON u.id = up.id_usuario WHERE up.cedula = ? AND u.estado = 'ACTIVO' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $cedula);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $id_usuario = (int)$row['id_usuario'];
                $correo = (string)($row['correo'] ?? '');

                $partes = explode('@', $correo);
                $name = $partes[0] ?? '';
                $domain = $partes[1] ?? '';
                $len = strlen($name);
                $masked_name = ($len > 2)
                    ? (substr($name, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($name, -1))
                    : (substr($name, 0, 1) . '***');
                $correo_enmascarado = $domain !== '' ? ($masked_name . '@' . $domain) : '***';

                $stmt_preg = $con->prepare("SELECT pregunta FROM preguntas_seguridad WHERE id_usuario = ?");
                $preguntas = [];
                if ($stmt_preg) {
                    $stmt_preg->bind_param("i", $id_usuario);
                    $stmt_preg->execute();
                    $res_preg = $stmt_preg->get_result();
                    while ($preg_row = $res_preg->fetch_assoc()) {
                        $preguntas[] = $preg_row['pregunta'];
                    }
                    $stmt_preg->close();
                }

                $_SESSION['recovery_user_id'] = $id_usuario;

                echo json_encode([
                    'success' => true,
                    'id_usuario' => $id_usuario,
                    'correo_enmascarado' => $correo_enmascarado,
                    'preguntas' => $preguntas
                ]);
            } else {
                starfi_recovery_add_fail($rl_path);
                echo json_encode(['success' => false, 'message' => 'La cédula ingresada no está registrada o la cuenta no está activa.']);
            }
            $stmt->close();
        }
        break;

    case 'verify_questions':
        $id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;
        $respuestas = (isset($_POST['respuestas']) && is_array($_POST['respuestas'])) ? $_POST['respuestas'] : [];

        if ($id_usuario <= 0 || !isset($_SESSION['recovery_user_id']) || (int)$_SESSION['recovery_user_id'] !== $id_usuario) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Sesión de recuperación no válida.']);
            break;
        }

        $stmt = $con->prepare("SELECT id, pregunta, respuesta FROM preguntas_seguridad WHERE id_usuario = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id_usuario);
            $stmt->execute();
            $res = $stmt->get_result();
            $coincidencias = 0;
            $total_preguntas = 0;
            $legacy_upgrades = [];

            while ($row = $res->fetch_assoc()) {
                $total_preguntas++;
                $pregunta_id = (int)$row['id'];
                $pregunta = trim((string)$row['pregunta']);
                $respuesta_stored = (string)$row['respuesta'];

                foreach ($respuestas as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (trim((string)($item['pregunta'] ?? '')) === $pregunta) {
                        $resp_norm = mb_strtolower(trim((string)($item['respuesta'] ?? '')), 'UTF-8');
                        if ($resp_norm === '') {
                            break;
                        }
                        $is_bcrypt = (strpos($respuesta_stored, '$2y$') === 0 || strpos($respuesta_stored, '$2a$') === 0 || strpos($respuesta_stored, '$argon2') === 0);
                        if ($is_bcrypt) {
                            if (password_verify($resp_norm, $respuesta_stored)) {
                                $coincidencias++;
                            }
                        } else {
                            $db_norm = mb_strtolower(trim($respuesta_stored), 'UTF-8');
                            if ($db_norm !== '' && hash_equals($db_norm, $resp_norm)) {
                                $coincidencias++;
                                $legacy_upgrades[$pregunta_id] = password_hash($resp_norm, PASSWORD_BCRYPT);
                            }
                        }
                        break;
                    }
                }
            }
            $stmt->close();

            if ($total_preguntas >= 3 && $coincidencias === $total_preguntas) {
                // Migrar respuestas legacy en texto plano a bcrypt tras verificación legítima
                if (!empty($legacy_upgrades)) {
                    $stmt_up = $con->prepare("UPDATE preguntas_seguridad SET respuesta = ? WHERE id = ? AND id_usuario = ?");
                    if ($stmt_up) {
                        foreach ($legacy_upgrades as $pid => $bhash) {
                            $stmt_up->bind_param("sii", $bhash, $pid, $id_usuario);
                            $stmt_up->execute();
                        }
                        $stmt_up->close();
                    }
                }

                starfi_recovery_clear_fails($rl_path);
                $_SESSION['recovery_verified_user_id'] = $id_usuario;
                registrar_auditoria(
                    'auth',
                    'RECUPERACION_PREGUNTAS_OK',
                    'Verificación exitosa de preguntas de seguridad',
                    ['id_usuario' => $id_usuario, 'verificado' => false],
                    ['id_usuario' => $id_usuario, 'verificado' => true],
                    $con,
                    $id_usuario,
                    null
                );
                echo json_encode(['success' => true, 'message' => 'Respuestas de seguridad verificadas correctamente.']);
            } else {
                starfi_recovery_add_fail($rl_path);
                registrar_auditoria(
                    'auth',
                    'RECUPERACION_PREGUNTAS_FALLO',
                    'Intento fallido en preguntas de seguridad',
                    ['id_usuario' => $id_usuario],
                    ['id_usuario' => $id_usuario, 'coincidencias' => $coincidencias],
                    $con,
                    $id_usuario,
                    null
                );
                echo json_encode(['success' => false, 'message' => 'Una o más respuestas de seguridad son incorrectas.']);
            }
        }
        break;

    case 'send_code':
        $id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;

        // Exigir obligatoriamente haber superado la verificación de preguntas de seguridad en la sesión actual
        if (
            $id_usuario <= 0 ||
            !isset($_SESSION['recovery_user_id']) ||
            (int)$_SESSION['recovery_user_id'] !== $id_usuario ||
            !isset($_SESSION['recovery_verified_user_id']) ||
            (int)$_SESSION['recovery_verified_user_id'] !== $id_usuario
        ) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Debe verificar primero sus preguntas de seguridad antes de solicitar un código OTP.'
            ]);
            break;
        }

        // Generar OTP criptográficamente seguro de 6 dígitos (nunca devolverlo en la respuesta HTTP)
        $codigo = (string)random_int(100000, 999999);
        $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = $con->prepare("UPDATE usuario SET codigo_recuperacion = ?, expiracion_codigo = ? WHERE id = ? AND estado = 'ACTIVO'");
        if ($stmt) {
            $stmt->bind_param("ssi", $codigo, $expiracion, $id_usuario);
            if ($stmt->execute() && $stmt->affected_rows >= 0) {
                $stmt->close();
                $_SESSION['recovery_otp_tries'] = 0;

                // Enviar exclusivamente por canal oficial SMTP (MailerService)
                $stmt_mail = $con->prepare("SELECT nombre, correo FROM usuario_perfil WHERE id_usuario = ? LIMIT 1");
                $correo_dest = '';
                $nombre_dest = 'Operador';
                if ($stmt_mail) {
                    $stmt_mail->bind_param("i", $id_usuario);
                    $stmt_mail->execute();
                    $res_mail = $stmt_mail->get_result();
                    if ($rm = $res_mail->fetch_assoc()) {
                        $correo_dest = trim((string)($rm['correo'] ?? ''));
                        $nombre_dest = trim((string)($rm['nombre'] ?? 'Operador'));
                    }
                    $stmt_mail->close();
                }

                if ($correo_dest === '') {
                    echo json_encode(['success' => false, 'message' => 'El usuario no posee un correo electrónico registrado para envío de OTP.']);
                    break;
                }

                require_once __DIR__ . '/core/services/MailerService.php';
                $mailer = new MailerService($con);
                $html_otp = "<div style='font-family:sans-serif;max-width:520px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:10px;'>"
                    . "<h3 style='color:#37414A;margin-top:0;'>Recuperación de Acceso — STARFI CRM</h3>"
                    . "<p>Hola <strong>" . htmlspecialchars($nombre_dest, ENT_QUOTES, 'UTF-8') . "</strong>,</p>"
                    . "<p>Tu código de verificación de un solo uso (válido por 15 minutos) es:</p>"
                    . "<div style='font-size:28px;font-weight:700;letter-spacing:6px;color:#E85B14;padding:12px 16px;background:#f8fafc;border-radius:8px;text-align:center;'>"
                    . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8')
                    . "</div>"
                    . "<p style='color:#64748b;font-size:12px;margin-top:16px;'>Si no solicitaste este cambio, ignora este correo.</p>"
                    . "</div>";

                $send_res = $mailer->sendDirectEmail($correo_dest, $nombre_dest, 'Código de Verificación OTP - STARFI CRM', $html_otp);
                registrar_auditoria(
                    'auth',
                    'RECUPERACION_OTP_ENVIADO',
                    'Generación y despacho de código OTP de recuperación',
                    ['id_usuario' => $id_usuario],
                    ['id_usuario' => $id_usuario, 'expiracion' => $expiracion, 'smtp_status' => $send_res['status'] ?? 'error'],
                    $con,
                    $id_usuario,
                    null
                );
                if (isset($send_res['status']) && $send_res['status'] === 'success') {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Se ha enviado el código de verificación a su correo electrónico registrado.'
                    ]);
                } else {
                    echo json_encode([
                        'success' => false,
                        'message' => 'No fue posible despachar el correo OTP en este momento. Puede continuar con sus preguntas de seguridad ya verificadas.'
                    ]);
                }
            } else {
                $stmt->close();
                echo json_encode(['success' => false, 'message' => 'Error al registrar el código de recuperación.']);
            }
        }
        break;

    case 'verify_code':
        $id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;
        $codigo = isset($_POST['codigo']) ? trim((string)$_POST['codigo']) : '';

        if (
            $id_usuario <= 0 ||
            !isset($_SESSION['recovery_user_id']) ||
            (int)$_SESSION['recovery_user_id'] !== $id_usuario ||
            !isset($_SESSION['recovery_verified_user_id']) ||
            (int)$_SESSION['recovery_verified_user_id'] !== $id_usuario
        ) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Sesión de recuperación no autorizada.']);
            break;
        }

        $otp_tries = (int)($_SESSION['recovery_otp_tries'] ?? 0);
        if ($otp_tries >= 5) {
            $stmt_inv = $con->prepare("UPDATE usuario SET codigo_recuperacion = NULL, expiracion_codigo = NULL WHERE id = ?");
            if ($stmt_inv) {
                $stmt_inv->bind_param("i", $id_usuario);
                $stmt_inv->execute();
                $stmt_inv->close();
            }
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Código invalidado por exceso de intentos fallidos.']);
            break;
        }

        $stmt = $con->prepare("SELECT codigo_recuperacion, expiracion_codigo FROM usuario WHERE id = ? AND estado = 'ACTIVO' LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $id_usuario);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $codigo_db = (string)($row['codigo_recuperacion'] ?? '');
                $expiracion_db = (string)($row['expiracion_codigo'] ?? '');

                if ($codigo_db === '' || $codigo === '' || !hash_equals($codigo_db, $codigo)) {
                    $_SESSION['recovery_otp_tries'] = $otp_tries + 1;
                    starfi_recovery_add_fail($rl_path);
                    registrar_auditoria(
                        'auth',
                        'RECUPERACION_OTP_FALLIDO',
                        'Código OTP incorrecto en recuperación de contraseña',
                        ['id_usuario' => $id_usuario, 'intentos_otp' => $otp_tries],
                        ['id_usuario' => $id_usuario, 'intentos_otp' => $otp_tries + 1],
                        $con,
                        $id_usuario,
                        null
                    );
                    echo json_encode(['success' => false, 'message' => 'El código de verificación es incorrecto.']);
                } elseif (empty($expiracion_db) || strtotime($expiracion_db) < time()) {
                    echo json_encode(['success' => false, 'message' => 'El código de verificación ha expirado.']);
                } else {
                    $stmt_clear = $con->prepare("UPDATE usuario SET codigo_recuperacion = NULL, expiracion_codigo = NULL WHERE id = ?");
                    if ($stmt_clear) {
                        $stmt_clear->bind_param("i", $id_usuario);
                        $stmt_clear->execute();
                        $stmt_clear->close();
                    }

                    starfi_recovery_clear_fails($rl_path);
                    $_SESSION['recovery_verified_user_id'] = $id_usuario;
                    registrar_auditoria(
                        'auth',
                        'RECUPERACION_OTP_VERIFICADO',
                        'Código OTP verificado exitosamente',
                        ['id_usuario' => $id_usuario, 'otp_activo' => true],
                        ['id_usuario' => $id_usuario, 'otp_activo' => false],
                        $con,
                        $id_usuario,
                        null
                    );
                    echo json_encode(['success' => true, 'message' => 'Código verificado con éxito.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Usuario no encontrado.']);
            }
            $stmt->close();
        }
        break;

    case 'reset_password':
        $id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;
        $contrasena = isset($_POST['contrasena']) ? (string)$_POST['contrasena'] : '';

        if (
            $id_usuario <= 0 ||
            !isset($_SESSION['recovery_verified_user_id']) ||
            (int)$_SESSION['recovery_verified_user_id'] !== $id_usuario
        ) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado para restablecer contraseña.']);
            break;
        }

        if (strlen($contrasena) < 8) {
            echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 8 caracteres.']);
            break;
        }

        $contrasena_hash = password_hash($contrasena, PASSWORD_BCRYPT);
        $stmt = $con->prepare("UPDATE usuario SET contrasena = ?, codigo_recuperacion = NULL, expiracion_codigo = NULL WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $contrasena_hash, $id_usuario);
            if ($stmt->execute()) {
                unset($_SESSION['recovery_user_id'], $_SESSION['recovery_verified_user_id'], $_SESSION['recovery_otp_tries']);
                starfi_recovery_clear_fails($rl_path);
                registrar_auditoria(
                    'auth',
                    'PASSWORD_RESTABLECIDO',
                    'Restablecimiento exitoso de contraseña mediante flujo de recuperación',
                    ['id_usuario' => $id_usuario, 'contrasena' => '[REDACTED]'],
                    ['id_usuario' => $id_usuario, 'contrasena' => '[REDACTED]', 'fecha_cambio' => date('Y-m-d H:i:s')],
                    $con,
                    $id_usuario,
                    null
                );
                echo json_encode(['success' => true, 'message' => '¡Su contraseña ha sido restablecida correctamente!']);
            } else {
                starfi_log_exception('auth', 'Error al actualizar contraseña en recover_password', ['id_usuario' => $id_usuario]);
                echo json_encode(['success' => false, 'message' => 'Error al actualizar la contraseña.']);
            }
            $stmt->close();
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Acción no válida.']);
        break;
}

mysqli_close($con);
?>
