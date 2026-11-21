<?php
/**
 * Controlador Autenticado de Descarga de Documentos Confidenciales por Sede | STARFI CRM
 * Protege expedientes legales y PDFs transaccionales/financieros contra acceso directo sin sesión o cruce de sede.
 */

@ini_set('display_errors', '0');
require_once __DIR__ . '/core/auth.php';

if (!isset($_SESSION['agente_id']) || empty($_SESSION['agente_id'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Acceso denegado: sesión requerida.');
}

requireAuth();
$agente = getAgenteInfo();
$agente_id = intval($agente['id'] ?? 0);
$rol = strtoupper(trim((string)($agente['rol'] ?? '')));
$user_sede = intval($agente['id_sede'] ?? 0);
$user_empresa = intval($agente['id_empresa'] ?? 0);
$is_global = ($agente_id === 1 || $rol === 'MASTER' || $rol === 'MASTER CI');

session_write_close();

$con = getDbConnection('core');
if (!$con) {
    http_response_code(500);
    exit('Error interno de conexión.');
}

$tipo = trim((string)($_GET['tipo'] ?? ''));
$file = basename((string)($_GET['file'] ?? ''));
$exp_id = intval($_GET['id'] ?? 0);

if ($exp_id <= 0 && ($file === '' || $file === '.' || $file === '..' || strpos($file, '.htaccess') !== false || strpos($file, '.gitkeep') !== false)) {
    http_response_code(400);
    exit('Archivo inválido.');
}

$target_path = '';

if ($exp_id > 0 || $tipo === 'expediente' || $tipo === 'expediente_legacy') {
    if (!hasPermission('perfil_empresa')) {
        http_response_code(403);
        exit('Acceso denegado al módulo de expedientes.');
    }

    if ($exp_id > 0) {
        $stmt = $con->prepare("SELECT id, id_empresa, id_sede, ruta FROM empresa_expediente WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $exp_id);
    } else {
        $like1 = 'uploads/expedientes/' . $file;
        $like2 = 'assets/docs/expedientes/' . $file;
        $stmt = $con->prepare("SELECT id, id_empresa, id_sede, ruta FROM empresa_expediente WHERE ruta = ? OR ruta = ? LIMIT 1");
        $stmt->bind_param("ss", $like1, $like2);
    }

    $row_exp = null;
    if ($stmt) {
        $stmt->execute();
        $res = $stmt->get_result();
        $row_exp = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if (!$row_exp) {
        if (!$is_global) {
            http_response_code(403);
            exit('Expediente no autorizado para su sede.');
        }
        $baseDir = ($tipo === 'expediente_legacy') ? (__DIR__ . '/assets/docs/expedientes') : (__DIR__ . '/uploads/expedientes');
        $cand = realpath($baseDir . '/' . $file);
        $realBase = realpath($baseDir);
        if (!$cand || !$realBase || strpos($cand, $realBase) !== 0 || !is_file($cand)) {
            http_response_code(404);
            exit('Documento no encontrado.');
        }
        $target_path = $cand;
    } else {
        $doc_sede = intval($row_exp['id_sede'] ?? 0);
        $doc_emp = intval($row_exp['id_empresa'] ?? 0);
        if (!$is_global) {
            if (($user_sede > 0 && $doc_sede !== $user_sede) || ($user_empresa > 0 && $doc_emp !== $user_empresa)) {
                http_response_code(403);
                exit('Acceso denegado: este expediente pertenece a otra sede.');
            }
        }
        $rel = ltrim(str_replace('\\', '/', (string)$row_exp['ruta']), '/');
        $cand = realpath(__DIR__ . '/' . $rel);
        $allowed1 = realpath(__DIR__ . '/uploads/expedientes');
        $allowed2 = realpath(__DIR__ . '/assets/docs/expedientes');
        $in_allowed = ($cand && (($allowed1 && strpos($cand, $allowed1) === 0) || ($allowed2 && strpos($cand, $allowed2) === 0)));
        if (!$in_allowed || !is_file($cand)) {
            http_response_code(404);
            exit('Archivo de expediente no encontrado.');
        }
        $target_path = $cand;
    }
} elseif ($tipo === 'documento') {
    if (!hasPermission('bandeja') && !hasPermission('dashboard')) {
        http_response_code(403);
        exit('Acceso denegado.');
    }
    $rel_doc = 'uploads/documentos/' . $file;
    if (!$is_global) {
        if ($user_sede <= 0) {
            http_response_code(403);
            exit('Usuario sin sede asignada.');
        }
        $like_doc = '%' . $file;
        $stmt_d = $con->prepare("
            SELECT 1
            FROM mensajes_y_eventos m
            JOIN conversaciones c ON m.id_conversacion = c.id
            JOIN lineas_whatsapp l ON c.id_linea = l.id
            WHERE (m.url_archivo = ? OR m.url_archivo LIKE ?)
              AND l.id_sede = ?
            LIMIT 1
        ");
        $authorized_doc = false;
        if ($stmt_d) {
            $stmt_d->bind_param("ssi", $rel_doc, $like_doc, $user_sede);
            $stmt_d->execute();
            $res_d = $stmt_d->get_result();
            $authorized_doc = ($res_d && $res_d->num_rows > 0);
            $stmt_d->close();
        }
        if (!$authorized_doc) {
            http_response_code(403);
            exit('Acceso denegado: este documento no pertenece a su sede.');
        }
    }
    $baseDocs = realpath(__DIR__ . '/uploads/documentos');
    $cand = $baseDocs ? realpath($baseDocs . '/' . $file) : false;
    if (!$cand || !$baseDocs || strpos($cand, $baseDocs) !== 0 || !is_file($cand)) {
        http_response_code(404);
        exit('Documento no encontrado.');
    }
    $target_path = $cand;
} elseif ($tipo === 'comprobante_waba') {
    if (!$is_global && !hasPermission('waba_ordenes') && !hasPermission('dashboard')) {
        http_response_code(403);
        exit('Acceso denegado a comprobantes WABA.');
    }

    $rel1 = 'assets/uploads/comprobantes_waba/' . $file;
    $likeComp = '%comprobantes_waba/' . $file;
    $stmt_c = $con->prepare("SELECT id, id_sede, comprobante_ruta FROM waba_ordenes_cobro WHERE comprobante_ruta = ? OR comprobante_ruta LIKE ? LIMIT 1");
    $row_comp = null;
    if ($stmt_c) {
        $stmt_c->bind_param("ss", $rel1, $likeComp);
        $stmt_c->execute();
        $res_c = $stmt_c->get_result();
        $row_comp = $res_c ? $res_c->fetch_assoc() : null;
        $stmt_c->close();
    }

    if (!$row_comp) {
        if (!$is_global) {
            http_response_code(403);
            exit('Comprobante no autorizado para su sede.');
        }
    } else {
        $comp_sede = intval($row_comp['id_sede'] ?? 0);
        if (!$is_global && ($user_sede <= 0 || $comp_sede !== $user_sede)) {
            http_response_code(403);
            exit('Acceso denegado: este comprobante pertenece a otra sede.');
        }
    }

    $baseComp = realpath(__DIR__ . '/assets/uploads/comprobantes_waba');
    $cand = $baseComp ? realpath($baseComp . '/' . $file) : false;
    if (!$cand || !$baseComp || strpos($cand, $baseComp) !== 0 || !is_file($cand)) {
        http_response_code(404);
        exit('Comprobante no encontrado.');
    }
    $target_path = $cand;
} else {
    http_response_code(400);
    exit('Tipo de recurso no soportado.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($target_path) ?: 'application/octet-stream';
$allowed_stream_mimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];
if (!in_array($mime, $allowed_stream_mimes, true)) {
    $mime = 'application/octet-stream';
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($target_path));
$disp = ($mime === 'application/octet-stream') ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode(basename($target_path)) . '"');
readfile($target_path);
exit;
