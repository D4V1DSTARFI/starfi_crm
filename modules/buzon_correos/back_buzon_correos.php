<?php
// modules/buzon_correos/back_buzon_correos.php
// Controlador Backend del Buzón Global SMTP Exclusivo MASTER — STARFI CRM (QA-STD-GLOBAL-2.1)
// 100% mysqli_prepare(), guardia estricto MASTER (403), cero fugas SQL y auditoría formal.

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';

requireAuth();
requirePermission('buzon_correos');

$agente = getAgenteInfo();
$es_master = (intval($agente['id'] ?? 0) === 1 || strtoupper(trim((string)($agente['rol'] ?? ''))) === 'MASTER');
if (!$es_master) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: exclusivo para el rol MASTER.']);
    exit;
}

$id_usuario_actual = intval($agente['id'] ?? 0);
$id_sede_actual = intval($agente['id_sede'] ?? 0) ?: null;
session_write_close();

$con = getDbConnection();
if (!$con) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos.']);
    exit;
}

// Verificar existencia de tabla cola_correos de forma segura
$stmtChkTbl = @$con->prepare("SHOW TABLES LIKE 'cola_correos'");
$hasCola = false;
if ($stmtChkTbl && $stmtChkTbl->execute()) {
    $rChk = $stmtChkTbl->get_result();
    $hasCola = ($rChk && $rChk->num_rows > 0);
    $stmtChkTbl->close();
}

if (!$hasCola && !(function_exists('starfi_tx_is_active') && starfi_tx_is_active($con))) {
    $stCreate = @$con->prepare("CREATE TABLE IF NOT EXISTS cola_correos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        destinatario_email VARCHAR(255) NOT NULL,
        destinatario_nombre VARCHAR(255) NOT NULL,
        asunto VARCHAR(255) NOT NULL,
        cuerpo_html TEXT NOT NULL,
        adjunto_ruta VARCHAR(500) DEFAULT NULL,
        estado ENUM('Pendiente', 'Enviado', 'Error') DEFAULT 'Pendiente',
        intentos INT DEFAULT 0,
        error_mensaje TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        sent_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ($stCreate) {
        @$stCreate->execute();
        $stCreate->close();
        $hasCola = true;
    }
}

$action = trim((string)($_POST['action'] ?? 'list_emails'));

switch ($action) {
    case 'list_emails':
        $stats = ['Pendiente' => 0, 'Enviado' => 0, 'Error' => 0];
        if (!$hasCola) {
            echo json_encode(['status' => 'success', 'stats' => $stats, 'data' => []]);
            exit;
        }

        $stmtStats = $con->prepare("SELECT estado, COUNT(*) AS qty FROM cola_correos GROUP BY estado");
        if ($stmtStats) {
            $stmtStats->execute();
            $resStats = $stmtStats->get_result();
            while ($resStats && ($rowS = $resStats->fetch_assoc())) {
                $estKey = (string)($rowS['estado'] ?? '');
                if (array_key_exists($estKey, $stats)) {
                    $stats[$estKey] = intval($rowS['qty']);
                }
            }
            $stmtStats->close();
        }

        $estado = trim((string)($_POST['estado'] ?? ''));
        $q      = trim((string)($_POST['q'] ?? ''));
        $limit  = 100;

        $where = ["1=1"];
        $types = "";
        $params = [];

        if (in_array($estado, ['Pendiente', 'Enviado', 'Error'], true)) {
            $where[]  = "estado = ?";
            $types   .= "s";
            $params[] = $estado;
        }
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[]  = "(destinatario_email LIKE ? OR destinatario_nombre LIKE ? OR asunto LIKE ?)";
            $types   .= "sss";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $types   .= "i";
        $params[] = $limit;

        $sql = "SELECT id, destinatario_email, destinatario_nombre, asunto, estado, intentos, error_mensaje, created_at, sent_at
                FROM cola_correos
                WHERE " . implode(" AND ", $where) . "
                ORDER BY created_at DESC, id DESC
                LIMIT ?";

        $rows = [];
        $stmt = $con->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) {
                $rows[] = [
                    'id'                  => intval($r['id']),
                    'destinatario'        => (string)($r['destinatario_email'] ?? ''),
                    'destinatario_email'  => (string)($r['destinatario_email'] ?? ''),
                    'destinatario_nombre' => (string)($r['destinatario_nombre'] ?? ''),
                    'asunto'              => (string)($r['asunto'] ?? ''),
                    'estado'              => (string)($r['estado'] ?? 'Pendiente'),
                    'intentos'            => intval($r['intentos'] ?? 0),
                    'error_detalle'       => (string)($r['error_mensaje'] ?? ''),
                    'fecha_creacion'      => (string)($r['created_at'] ?? ''),
                    'sent_at'             => (string)($r['sent_at'] ?? ''),
                ];
            }
            $stmt->close();
        } else {
            starfi_log_exception('buzon_correos', 'No se pudo preparar listado de cola_correos', ['errno' => $con->errno]);
        }

        echo json_encode([
            'status' => 'success',
            'stats'  => $stats,
            'data'   => $rows,
        ]);
        break;

    case 'get_email_detail':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0 || !$hasCola) {
            echo json_encode(['status' => 'error', 'message' => 'ID de correo inválido.']);
            exit;
        }

        $stmt = $con->prepare("SELECT id, destinatario_email, destinatario_nombre, asunto, cuerpo_html, estado, intentos, error_mensaje, created_at, sent_at FROM cola_correos WHERE id = ? LIMIT 1");
        $row = null;
        if ($stmt) {
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Registro de correo no encontrado.']);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'email'  => [
                'id'                  => intval($row['id']),
                'destinatario'        => (string)($row['destinatario_email'] ?? ''),
                'destinatario_nombre' => (string)($row['destinatario_nombre'] ?? ''),
                'asunto'              => (string)($row['asunto'] ?? ''),
                'cuerpo_html'         => (string)($row['cuerpo_html'] ?? ''),
                'estado'              => (string)($row['estado'] ?? 'Pendiente'),
                'intentos'            => intval($row['intentos'] ?? 0),
                'error_detalle'       => (string)($row['error_mensaje'] ?? ''),
                'fecha_creacion'      => (string)($row['created_at'] ?? ''),
                'sent_at'             => (string)($row['sent_at'] ?? ''),
            ],
        ]);
        break;

    case 'retry_email':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0 || !$hasCola) {
            echo json_encode(['status' => 'error', 'message' => 'ID de correo no válido.']);
            exit;
        }

        $stmtPrev = $con->prepare("SELECT id, estado, intentos, destinatario_email FROM cola_correos WHERE id = ? LIMIT 1");
        $prev = null;
        if ($stmtPrev) {
            $stmtPrev->bind_param("i", $id);
            $stmtPrev->execute();
            $rP = $stmtPrev->get_result();
            $prev = ($rP && $rP->num_rows > 0) ? $rP->fetch_assoc() : null;
            $stmtPrev->close();
        }

        if (!$prev) {
            echo json_encode(['status' => 'error', 'message' => 'El correo especificado no existe.']);
            exit;
        }

        $stmtUp = $con->prepare("UPDATE cola_correos SET estado = 'Pendiente', intentos = 0, error_mensaje = NULL WHERE id = ?");
        if (!$stmtUp) {
            starfi_log_exception('buzon_correos', 'Fallo preparando retry_email', ['errno' => $con->errno]);
            echo json_encode(['status' => 'error', 'message' => 'No se pudo preparar la actualización del correo.']);
            exit;
        }
        $stmtUp->bind_param("i", $id);
        $ok = $stmtUp->execute();
        $stmtUp->close();

        if (!$ok) {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo reencolar el correo seleccionado.']);
            exit;
        }

        registrar_auditoria(
            'buzon_correos',
            'REENCOLAR_CORREO_SMTP',
            "Correo #$id reencolado manualmente para envío SMTP",
            $prev,
            ['id' => $id, 'estado' => 'Pendiente', 'intentos' => 0],
            $con,
            $id_usuario_actual,
            $id_sede_actual
        );

        echo json_encode(['status' => 'success', 'message' => "El correo #$id fue marcado como Pendiente para reintento de envío."]);
        break;

    case 'process_queue':
        require_once __DIR__ . '/../../core/services/MailerService.php';
        $mailer = new MailerService($con);
        $resProc = $mailer->processQueue(20);

        registrar_auditoria(
            'buzon_correos',
            'PROCESAR_COLA_SMTP',
            'Ejecución manual de despacho de cola SMTP desde Buzón MASTER',
            null,
            $resProc,
            $con,
            $id_usuario_actual,
            $id_sede_actual
        );

        if (($resProc['status'] ?? '') === 'success') {
            $ex = intval($resProc['exitosos'] ?? 0);
            $er = intval($resProc['errores'] ?? 0);
            echo json_encode([
                'status'  => 'success',
                'message' => "Procesamiento completado: $ex enviados, $er con error.",
                'result'  => $resProc,
            ]);
        } else {
            echo json_encode([
                'status'  => 'error',
                'message' => (string)($resProc['message'] ?? 'No se pudo procesar la cola SMTP.'),
            ]);
        }
        break;

    default:
        registrar_auditoria(
            'buzon_correos',
            'ACCION_NO_VALIDA',
            'Intento de acción desconocida en controlador Buzón SMTP',
            null,
            ['action' => $action],
            $con,
            $id_usuario_actual,
            $id_sede_actual
        );
        echo json_encode(['status' => 'error', 'message' => 'Acción no reconocida.']);
        break;
}
