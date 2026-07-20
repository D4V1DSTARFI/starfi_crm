<?php
/**
 * Controlador de Recuperación de Contraseña para STARFI CRM
 * Procesa la validación por Cédula, Preguntas de Seguridad y Código por Correo.
 */

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit();
}

require_once __DIR__ . '/config/database.php';

$action = isset($_POST['action']) ? trim($_POST['action']) : '';

$con = getDbConnection('core');
if (!$con) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
    exit();
}

switch ($action) {
    case 'verify_cedula':
        $cedula = isset($_POST['cedula']) ? trim($_POST['cedula']) : '';
        if (empty($cedula)) {
            echo json_encode(['success' => false, 'message' => 'Por favor ingrese su cédula.']);
            break;
        }

        // Buscar el usuario por cédula
        $stmt = $con->prepare("SELECT id_usuario, correo FROM usuario_perfil WHERE cedula = ?");
        if ($stmt) {
            $stmt->bind_param("s", $cedula);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $id_usuario = $row['id_usuario'];
                $correo = $row['correo'];
                
                // Enmascarar el correo (ej. j***z@correo.com)
                $partes = explode('@', $correo);
                $name = $partes[0];
                $domain = $partes[1] ?? '';
                $len = strlen($name);
                $masked_name = substr($name, 0, 1) . str_repeat('*', max(1, $len - 2)) . substr($name, -1);
                $correo_enmascarado = $masked_name . '@' . $domain;

                // Cargar las preguntas de seguridad del usuario
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

                // Guardar temporalmente en sesión para rastreo
                $_SESSION['recovery_user_id'] = $id_usuario;

                echo json_encode([
                    'success' => true,
                    'id_usuario' => $id_usuario,
                    'correo_enmascarado' => $correo_enmascarado,
                    'preguntas' => $preguntas
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'La cédula ingresada no está registrada.']);
            }
            $stmt->close();
        }
        break;

    case 'verify_questions':
        $id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;
        $respuestas = isset($_POST['respuestas']) ? $_POST['respuestas'] : [];

        if ($id_usuario <= 0 || !isset($_SESSION['recovery_user_id']) || $_SESSION['recovery_user_id'] !== $id_usuario) {
            echo json_encode(['success' => false, 'message' => 'Sesión de recuperación no válida.']);
            break;
        }

        // Obtener preguntas y respuestas de la BD
        $stmt = $con->prepare("SELECT pregunta, respuesta FROM preguntas_seguridad WHERE id_usuario = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id_usuario);
            $stmt->execute();
            $res = $stmt->get_result();
            $coincidencias = 0;
            $total_preguntas = 0;
            
            while ($row = $res->fetch_assoc()) {
                $total_preguntas++;
                $pregunta = $row['pregunta'];
                $respuesta_db = strtolower(trim($row['respuesta']));
                
                // Buscar respuesta provista por el usuario para esta pregunta
                foreach ($respuestas as $item) {
                    if (trim($item['pregunta']) === $pregunta) {
                        $resp_usuario = strtolower(trim($item['respuesta']));
                        if ($resp_usuario === $respuesta_db) {
                            $coincidencias++;
                        }
                        break;
                    }
                }
            }
            $stmt->close();

            if ($total_preguntas > 0 && $coincidencias === $total_preguntas) {
                // Marcar como verificado en la sesión
                $_SESSION['recovery_verified_user_id'] = $id_usuario;
                echo json_encode(['success' => true, 'message' => 'Respuestas correctas.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Una o más respuestas de seguridad son incorrectas.']);
            }
        }
        break;

    case 'send_code':
        $id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;

        if ($id_usuario <= 0 || !isset($_SESSION['recovery_user_id']) || $_SESSION['recovery_user_id'] !== $id_usuario) {
            echo json_encode(['success' => false, 'message' => 'Sesión de recuperación no válida.']);
            break;
        }

        // Generar un código de 6 dígitos
        $codigo = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        // Guardar en la base de datos
        $stmt = $con->prepare("UPDATE usuario SET codigo_recuperacion = ?, expiracion_codigo = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("ssi", $codigo, $expiracion, $id_usuario);
            if ($stmt->execute()) {
                // Retornar éxito. Indicamos el código para pruebas rápidas
                echo json_encode([
                    'success' => true,
                    'message' => 'Código de verificación generado con éxito.',
                    'codigo_simulado' => $codigo // Se incluye en la respuesta para facilitar las pruebas locales
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al registrar el código de recuperación.']);
            }
            $stmt->close();
        }
        break;

    case 'verify_code':
        $id_usuario = isset($_POST['id_usuario']) ? intval($_POST['id_usuario']) : 0;
        $codigo = isset($_POST['codigo']) ? trim($_POST['codigo']) : '';

        if ($id_usuario <= 0 || !isset($_SESSION['recovery_user_id']) || $_SESSION['recovery_user_id'] !== $id_usuario) {
            echo json_encode(['success' => false, 'message' => 'Sesión de recuperación no válida.']);
            break;
        }

        $stmt = $con->prepare("SELECT codigo_recuperacion, expiracion_codigo FROM usuario WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id_usuario);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                $codigo_db = $row['codigo_recuperacion'];
                $expiracion_db = $row['expiracion_codigo'];
                
                if (empty($codigo_db) || $codigo_db !== $codigo) {
                    echo json_encode(['success' => false, 'message' => 'El código de verificación es incorrecto.']);
                } else if (strtotime($expiracion_db) < time()) {
                    echo json_encode(['success' => false, 'message' => 'El código de verificación ha expirado.']);
                } else {
                    // Limpiar el código en BD
                    $stmt_clear = $con->prepare("UPDATE usuario SET codigo_recuperacion = NULL, expiracion_codigo = NULL WHERE id = ?");
                    if ($stmt_clear) {
                        $stmt_clear->bind_param("i", $id_usuario);
                        $stmt_clear->execute();
                        $stmt_clear->close();
                    }

                    $_SESSION['recovery_verified_user_id'] = $id_usuario;
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
        $contrasena = isset($_POST['contrasena']) ? $_POST['contrasena'] : '';

        if ($id_usuario <= 0 || !isset($_SESSION['recovery_verified_user_id']) || $_SESSION['recovery_verified_user_id'] !== $id_usuario) {
            echo json_encode(['success' => false, 'message' => 'Acceso no autorizado para restablecer contraseña.']);
            break;
        }

        if (strlen($contrasena) < 4) {
            echo json_encode(['success' => false, 'message' => 'La contraseña debe tener al menos 4 caracteres.']);
            break;
        }

        $contrasena_hash = password_hash($contrasena, PASSWORD_BCRYPT);
        $stmt = $con->prepare("UPDATE usuario SET contrasena = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $contrasena_hash, $id_usuario);
            if ($stmt->execute()) {
                // Limpiar variables de sesión
                unset($_SESSION['recovery_user_id']);
                unset($_SESSION['recovery_verified_user_id']);
                
                echo json_encode(['success' => true, 'message' => '¡Su contraseña ha sido restablecida correctamente!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error al actualizar la contraseña.']);
            }
            $stmt->close();
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida.']);
        break;
}

mysqli_close($con);
?>
