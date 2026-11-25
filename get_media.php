<?php
/**
 * Controlador Seguro de Transmisión y Caché Multimedia WABA — STARFI CRM (QA-STD-GLOBAL-2.1)
 * - Exige sesión activa y permiso modular (bandeja o directorio).
 * - Valida pertenencia estricta del archivo a id_conversacion e id_sede (Anti-IDOR Cross-Sede).
 * - Lista blanca estricta de MIME seguros con finfo; fuerza application/octet-stream + attachment + nosniff
 *   para cualquier otro formato (incluyendo text/html, image/svg+xml, text/xml, application/javascript).
 */

@ini_set('display_errors', '0');
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/audit.php';

if (!isset($_SESSION['agente_id']) || empty($_SESSION['agente_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit('401 Unauthorized');
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit('401 Session Expired');
}
requireAuth();

if (!hasPermission('bandeja') && !hasPermission('directorio')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit('403 Forbidden');
}

$agente_info = getAgenteInfo();
$agente_id = intval($agente_info['id'] ?? ($_SESSION['agente_id'] ?? 0));
$rol_raw = strtoupper(trim((string)($agente_info['rol'] ?? '')));
$is_master = ($agente_id === 1 || $rol_raw === 'MASTER');
$is_admin = (!$is_master && ($rol_raw === 'ADMINISTRADOR' || $rol_raw === 'ADMIN' || $rol_raw === 'GERENTE' || $rol_raw === 'MASTER CI'));
$is_operador = (!$is_master && !$is_admin);
$user_sede = intval($agente_info['id_sede'] ?? 0);

session_write_close();

/**
 * Transmite un archivo aplicando lista blanca estricta de MIME seguros (CHK-SEC-03 / CHK-SEC-07).
 *
 * @param string      $file           Ruta física del archivo validado.
 * @param string|null $requested_mime MIME sugerido (se revalida con finfo).
 */
function stream_file($file, $requested_mime = null) {
    if (!is_file($file) || !is_readable($file)) {
        http_response_code(404);
        header('X-Content-Type-Options: nosniff');
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected_mime = strtolower(trim((string)($finfo->file($file) ?: 'application/octet-stream')));
    $ext_lower = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    if ($ext_lower === 'ogg' && in_array($detected_mime, ['audio/ogg', 'audio/opus', 'audio/x-opus+ogg', 'application/ogg', 'video/ogg'], true)) {
        $detected_mime = 'audio/ogg';
    } elseif ($ext_lower === 'm4a' && in_array($detected_mime, ['audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/m4a'], true)) {
        $detected_mime = 'audio/mp4';
    } elseif ($ext_lower === 'mp3' && in_array($detected_mime, ['audio/mpeg', 'audio/mp3'], true)) {
        $detected_mime = 'audio/mpeg';
    } elseif ($ext_lower === 'aac' && in_array($detected_mime, ['audio/aac', 'audio/x-aac'], true)) {
        $detected_mime = 'audio/aac';
    } elseif ($ext_lower === 'wav' && in_array($detected_mime, ['audio/wav', 'audio/x-wav'], true)) {
        $detected_mime = 'audio/wav';
    } elseif ($ext_lower === 'webm' && in_array($detected_mime, ['audio/webm', 'video/webm'], true)) {
        $detected_mime = 'audio/webm';
    }

    $safe_inline_mimes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'audio/ogg',
        'audio/opus',
        'audio/mpeg',
        'audio/mp4',
        'audio/aac',
        'audio/amr',
        'audio/wav',
        'audio/webm',
        'video/mp4',
        'application/pdf',
    ];

    if (in_array($detected_mime, $safe_inline_mimes, true)) {
        $content_type = $detected_mime;
        $disposition = 'inline';
    } else {
        $content_type = 'application/octet-stream';
        $disposition = 'attachment';
    }

    $size = filesize($file);
    $length = $size;
    $start = 0;
    $end = max(0, $size - 1);
    $safe_dl_name = rawurlencode(basename($file));

    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; media-src \'self\'; img-src \'self\'; style-src \'none\'; script-src \'none\'; sandbox');
    header("Content-Type: $content_type");
    header("Content-Disposition: $disposition; filename=\"$safe_dl_name\"");
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=86400');

    if ($size > 0 && isset($_SERVER['HTTP_RANGE'])) {
        $c_start = $start;
        $c_end = $end;

        $parts = explode('=', $_SERVER['HTTP_RANGE'], 2);
        $range = $parts[1] ?? '';
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$size");
            exit;
        }

        if ($range === '-') {
            $c_start = $size - intval(substr($range, 1));
        } else {
            $range_arr = explode('-', $range);
            $c_start = intval($range_arr[0]);
            $c_end = (isset($range_arr[1]) && is_numeric($range_arr[1])) ? intval($range_arr[1]) : $end;
        }

        $c_end = ($c_end > $end) ? $end : $c_end;

        if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$size");
            exit;
        }

        $start = $c_start;
        $end = $c_end;
        $length = $end - $start + 1;

        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$size");
    } else {
        header('HTTP/1.1 200 OK');
    }

    header("Content-Length: $length");

    $fp = fopen($file, 'rb');
    if ($fp) {
        if ($start > 0) {
            fseek($fp, $start);
        }
        $buffer_size = 1024 * 8;
        $bytes_sent = 0;

        while (!feof($fp) && ($bytes_sent < $length) && (connection_status() === 0)) {
            $buffer = fread($fp, min($buffer_size, $length - $bytes_sent));
            if ($buffer === false || $buffer === '') {
                break;
            }
            echo $buffer;
            flush();
            $bytes_sent += strlen($buffer);
        }
        fclose($fp);
    }
    exit;
}

$con = getDbConnection();
if (!$con) {
    http_response_code(500);
    header('X-Content-Type-Options: nosniff');
    exit;
}

$media_id = trim((string)($_GET['id'] ?? ''));
$chat_id = intval($_GET['chat_id'] ?? 0);

if ($media_id === '' || $chat_id <= 0) {
    http_response_code(400);
    header('X-Content-Type-Options: nosniff');
    exit;
}

// 1. Verificar existencia del chat y autorización estricta por Sede / Operador usando consulta preparada
$stmtChat = $con->prepare("
    SELECT c.id, c.id_agente, c.estado, l.id_sede, l.meta_token
    FROM conversaciones c
    JOIN lineas_whatsapp l ON c.id_linea = l.id
    WHERE c.id = ?
    LIMIT 1
");
if (!$stmtChat) {
    http_response_code(500);
    header('X-Content-Type-Options: nosniff');
    exit;
}
$stmtChat->bind_param("i", $chat_id);
$stmtChat->execute();
$resChat = $stmtChat->get_result();
$chatRow = $resChat ? $resChat->fetch_assoc() : null;
$stmtChat->close();

if (!$chatRow) {
    http_response_code(404);
    header('X-Content-Type-Options: nosniff');
    exit;
}

$chat_sede = intval($chatRow['id_sede'] ?? 0);
if (!$is_master) {
    if ($user_sede <= 0 || $chat_sede !== $user_sede) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        exit('403 Forbidden');
    }
    if ($is_operador) {
        $conv_agente = intval($chatRow['id_agente'] ?? 0);
        if ($conv_agente !== $agente_id && $chatRow['estado'] !== 'ESPERA_ASIGNACION') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            exit('403 Forbidden');
        }
    }
}

$meta_token = trim((string)($chatRow['meta_token'] ?? ''));

// 2. Si media_id apunta a ruta local o prefijo media_*, verificar pertenencia a id_conversacion ANTES de servir (Anti-IDOR)
if (strpos($media_id, '/assets/uploads/') !== false || strpos($media_id, 'media_') !== false) {
    $filename = basename(str_replace('\\', '/', $media_id));
    if ($filename === '' || $filename === '.' || $filename === '..' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
        http_response_code(400);
        header('X-Content-Type-Options: nosniff');
        exit('400 Bad Request');
    }

    $like_upload = '%/assets/uploads/' . $filename;
    $like_filename = '%' . $filename;
    $extracted_meta_id = '';
    if (preg_match('/^media_([A-Za-z0-9_-]+)\.[A-Za-z0-9]+$/', $filename, $mOwn)) {
        $extracted_meta_id = $mOwn[1];
    }

    $stmtOwn = $con->prepare("
        SELECT id
        FROM mensajes_y_eventos
        WHERE id_conversacion = ?
          AND (
              url_archivo = ?
              OR url_archivo LIKE ?
              OR url_archivo LIKE ?
              OR (? != '' AND url_archivo = ?)
          )
        LIMIT 1
    ");
    $belongs_to_chat = false;
    if ($stmtOwn) {
        $stmtOwn->bind_param("isssss", $chat_id, $media_id, $like_upload, $like_filename, $extracted_meta_id, $extracted_meta_id);
        $stmtOwn->execute();
        $resOwn = $stmtOwn->get_result();
        $belongs_to_chat = ($resOwn && $resOwn->num_rows > 0);
        $stmtOwn->close();
    }

    if (!$belongs_to_chat) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        exit('403 Forbidden');
    }

    $uploads_root = realpath(__DIR__ . '/assets/uploads');
    $direct_path = __DIR__ . '/assets/uploads/' . $filename;
    $real_direct = realpath($direct_path);
    if ($real_direct && $uploads_root && strpos($real_direct, $uploads_root) === 0 && is_file($real_direct) && filesize($real_direct) > 0) {
        stream_file($real_direct);
        exit;
    }

    // Sincronización opcional desde servidor central si está definido en .env
    global $env;
    $central_base = !empty($env['CENTRAL_MEDIA_BASE_URL']) ? rtrim($env['CENTRAL_MEDIA_BASE_URL'], '/') : '';
    if ($central_base !== '') {
        $chRem = curl_init($central_base . '/' . rawurlencode($filename));
        curl_setopt_array($chRem, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
        ]);
        $remBin = curl_exec($chRem);
        $remCode = curl_getinfo($chRem, CURLINFO_HTTP_CODE);
        curl_close($chRem);
        if ($remCode === 200 && !empty($remBin) && strlen($remBin) > 0) {
            if (!is_dir(__DIR__ . '/assets/uploads')) {
                @mkdir(__DIR__ . '/assets/uploads', 0775, true);
            }
            @file_put_contents($direct_path, $remBin);
            stream_file($direct_path);
            exit;
        }
    }

    if ($extracted_meta_id !== '') {
        $media_id = $extracted_meta_id;
    }
}

// 3. Verificar que media_id pertenezca a esta conversación y revisar si ya fue cacheado en BD
$like_media = '%' . $media_id . '%';
$stmtCache = $con->prepare("
    SELECT url_archivo
    FROM mensajes_y_eventos
    WHERE id_conversacion = ?
      AND (url_archivo = ? OR url_archivo LIKE ?)
    LIMIT 1
");
$found_in_chat = false;
if ($stmtCache) {
    $stmtCache->bind_param("iss", $chat_id, $media_id, $like_media);
    $stmtCache->execute();
    $resCache = $stmtCache->get_result();
    $rowCache = $resCache ? $resCache->fetch_assoc() : null;
    $stmtCache->close();

    if ($rowCache && !empty($rowCache['url_archivo'])) {
        $found_in_chat = true;
        $url = (string)$rowCache['url_archivo'];
        if (strpos($url, '/assets/uploads/') !== false) {
            $safe_name = basename(substr($url, strpos($url, '/assets/uploads/') + strlen('/assets/uploads/')));
            $local_path = __DIR__ . '/assets/uploads/' . $safe_name;
            if (is_file($local_path) && filesize($local_path) > 0) {
                stream_file($local_path);
                exit;
            }
        }
    }
}

if (!$found_in_chat) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    exit('403 Forbidden');
}

if ($meta_token === '' || $meta_token === 'temp_token') {
    http_response_code(404);
    header('X-Content-Type-Options: nosniff');
    exit;
}

$api_ver = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';

// 4. Paso 1: Obtener URL del medio en Meta Graph API con SSL verificado
$ch = curl_init("https://graph.facebook.com/{$api_ver}/" . rawurlencode($media_id));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $meta_token"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
$response = curl_exec($ch);
curl_close($ch);

$media_info = json_decode((string)$response, true);
if (!isset($media_info['url'])) {
    http_response_code(404);
    header('X-Content-Type-Options: nosniff');
    exit;
}
$meta_media_url = $media_info['url'];
$mime = strtolower(trim((string)($media_info['mime_type'] ?? 'application/octet-stream')));

$ext = 'bin';
if ($mime === 'image/jpeg') {
    $ext = 'jpg';
} elseif ($mime === 'image/png') {
    $ext = 'png';
} elseif ($mime === 'image/webp') {
    $ext = 'webp';
} elseif (strpos($mime, 'ogg') !== false) {
    $ext = 'ogg';
} elseif ($mime === 'application/pdf') {
    $ext = 'pdf';
} elseif ($mime === 'video/mp4') {
    $ext = 'mp4';
}

$safe_media_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $media_id);
if ($safe_media_id === '') {
    http_response_code(400);
    header('X-Content-Type-Options: nosniff');
    exit;
}

$upload_dir = __DIR__ . '/assets/uploads';
if (!is_dir($upload_dir)) {
    @mkdir($upload_dir, 0775, true);
}

// 5. Paso 2: Descargar el binario con SSL verificado y validar MIME real con finfo antes de fijar extensión
$ch2 = curl_init($meta_media_url);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $meta_token"]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch2, CURLOPT_CONNECTTIMEOUT, 4);
curl_setopt($ch2, CURLOPT_TIMEOUT, 8);
curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
$binary = curl_exec($ch2);
curl_close($ch2);

if ($binary !== false && $binary !== '') {
    $finfo_buf = new finfo(FILEINFO_MIME_TYPE);
    $real_bin_mime = strtolower(trim((string)($finfo_buf->buffer($binary) ?: 'application/octet-stream')));
    $mime_to_ext = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'audio/ogg'       => 'ogg',
        'application/ogg' => 'ogg',
        'video/mp4'       => 'mp4',
        'application/pdf' => 'pdf',
    ];
    $ext = $mime_to_ext[$real_bin_mime] ?? 'bin';
    $filename = "media_{$safe_media_id}.{$ext}";
    $save_path = $upload_dir . '/' . $filename;

    file_put_contents($save_path, $binary);

    $base_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($base_path === '/' || $base_path === '\\' || $base_path === '.') {
        $base_path = '';
    }
    $local_url = $base_path . "/assets/uploads/$filename";

    if (function_exists('starfi_persist_media_cache')) {
        starfi_persist_media_cache($con, $chat_id, $media_id, $local_url, $agente_id, $chat_sede);
    }

    stream_file($save_path);
    exit;
}

http_response_code(500);
header('X-Content-Type-Options: nosniff');
