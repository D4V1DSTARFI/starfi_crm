<?php
// get_media.php
require_once __DIR__ . '/core/auth.php';

// Exigir sesión activa devolviendo HTTP 401 inmediato ante accesos sin sesión (ej. modo incógnito)
if (!isset($_SESSION['agente_id']) || empty($_SESSION['agente_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('401 Unauthorized');
}
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 1800)) {
    session_unset();
    session_destroy();
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('401 Session Expired');
}
requireAuth();

$agente_info = getAgenteInfo();
$agente_id = intval($agente_info['id'] ?? ($_SESSION['agente_id'] ?? 0));
$rol_raw = strtoupper(trim($agente_info['rol'] ?? ''));
$is_master = ($agente_id === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI');
$is_admin = (!$is_master && ($rol_raw === 'ADMINISTRADOR' || $rol_raw === 'ADMIN' || $rol_raw === 'GERENTE'));
$is_operador = (!$is_master && !$is_admin);
$user_sede = intval($agente_info['id_sede'] ?? 0);

session_write_close();

function stream_file($file, $content_type) {
    if (!file_exists($file)) {
        http_response_code(404);
        exit;
    }

    $size = filesize($file);
    $length = $size;
    $start = 0;
    $end = $size - 1;

    header("Content-Type: $content_type");
    header("Accept-Ranges: bytes");
    header("Cache-Control: private, max-age=86400");

    if (isset($_SERVER['HTTP_RANGE'])) {
        $c_start = $start;
        $c_end = $end;

        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$size");
            exit;
        }

        if ($range == '-') {
            $c_start = $size - substr($range, 1);
        } else {
            $range = explode('-', $range);
            $c_start = intval($range[0]);
            $c_end = (isset($range[1]) && is_numeric($range[1])) ? intval($range[1]) : $size;
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
    fseek($fp, $start);
    $buffer_size = 1024 * 8; // 8kb
    $bytes_sent = 0;

    while (!feof($fp) && ($bytes_sent < $length) && (connection_status() == 0)) {
        $buffer = fread($fp, $buffer_size);
        echo $buffer;
        flush();
        $bytes_sent += strlen($buffer);
    }
    fclose($fp);
    exit;
}

$con = getDbConnection();
if (!$con) {
    http_response_code(500);
    exit;
}

$media_id = trim((string)($_GET['id'] ?? ''));
$chat_id = intval($_GET['chat_id'] ?? 0);

if ($media_id === '' || $chat_id <= 0) {
    http_response_code(400);
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
    exit;
}
$stmtChat->bind_param("i", $chat_id);
$stmtChat->execute();
$resChat = $stmtChat->get_result();
$chatRow = $resChat ? $resChat->fetch_assoc() : null;
$stmtChat->close();

if (!$chatRow) {
    http_response_code(404);
    exit;
}

if (!$is_master) {
    $chat_sede = intval($chatRow['id_sede'] ?? 0);
    if ($user_sede <= 0 || $chat_sede !== $user_sede) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('403 Forbidden');
    }
    if ($is_operador) {
        $conv_agente = intval($chatRow['id_agente'] ?? 0);
        if ($conv_agente !== $agente_id && $chatRow['estado'] !== 'ESPERA_ASIGNACION') {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit('403 Forbidden');
        }
    }
}

$meta_token = trim((string)($chatRow['meta_token'] ?? ''));

// 2. Si media_id ya es ruta local (formato heredado), servir validando que no salga de /assets/uploads/
if (strpos($media_id, '/assets/uploads/') !== false || strpos($media_id, 'media_') !== false) {
    $filename = basename(str_replace('\\', '/', $media_id));
    $direct_path = __DIR__ . '/assets/uploads/' . $filename;
    if (file_exists($direct_path) && filesize($direct_path) > 200) {
        $mime_local = mime_content_type($direct_path);
        if (strpos($direct_path, '.ogg') !== false) $mime_local = 'audio/ogg';
        stream_file($direct_path, $mime_local);
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
            CURLOPT_TIMEOUT => 10
        ]);
        $remBin = curl_exec($chRem);
        $remCode = curl_getinfo($chRem, CURLINFO_HTTP_CODE);
        curl_close($chRem);
        if ($remCode === 200 && !empty($remBin) && strlen($remBin) > 200) {
            if (!is_dir(__DIR__ . '/assets/uploads')) @mkdir(__DIR__ . '/assets/uploads', 0775, true);
            @file_put_contents($direct_path, $remBin);
            $mime_local = mime_content_type($direct_path);
            if (strpos($direct_path, '.ogg') !== false) $mime_local = 'audio/ogg';
            stream_file($direct_path, $mime_local);
            exit;
        }
    }

    if (preg_match('/media_([0-9]+)/', $filename, $mIdMatch)) {
        $media_id = $mIdMatch[1];
    }
}

// 3. Verificar si ya fue cacheado en BD usando consulta preparada (0 concatenación SQL)
$like_media = '%' . $media_id . '%';
$stmtCache = $con->prepare("SELECT url_archivo FROM mensajes_y_eventos WHERE id_conversacion = ? AND url_archivo LIKE ? LIMIT 1");
if ($stmtCache) {
    $stmtCache->bind_param("is", $chat_id, $like_media);
    $stmtCache->execute();
    $resCache = $stmtCache->get_result();
    $rowCache = $resCache ? $resCache->fetch_assoc() : null;
    $stmtCache->close();

    if ($rowCache && !empty($rowCache['url_archivo'])) {
        $url = $rowCache['url_archivo'];
        if (strpos($url, '/assets/uploads/') !== false) {
            $safe_name = basename(substr($url, strpos($url, '/assets/uploads/') + strlen('/assets/uploads/')));
            $local_path = __DIR__ . '/assets/uploads/' . $safe_name;
            if (file_exists($local_path) && filesize($local_path) > 200) {
                $mime_local = mime_content_type($local_path);
                if (strpos($local_path, '.ogg') !== false) $mime_local = 'audio/ogg';
                stream_file($local_path, $mime_local);
                exit;
            }
        }
    }
}

if ($meta_token === '' || $meta_token === 'temp_token') {
    http_response_code(404);
    exit;
}

$api_ver = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';

// 4. Paso 1: Obtener URL del medio en Meta Graph API con SSL verificado
$ch = curl_init("https://graph.facebook.com/{$api_ver}/" . rawurlencode($media_id));
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $meta_token"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
curl_close($ch);

$media_info = json_decode($response, true);
if (!isset($media_info['url'])) {
    http_response_code(404);
    exit;
}
$meta_media_url = $media_info['url'];
$mime = $media_info['mime_type'] ?? 'application/octet-stream';

$ext = 'bin';
if (strpos($mime, 'jpeg') !== false) $ext = 'jpg';
else if (strpos($mime, 'png') !== false) $ext = 'png';
else if (strpos($mime, 'webp') !== false) $ext = 'webp';
else if (strpos($mime, 'ogg') !== false) $ext = 'ogg';
else if (strpos($mime, 'pdf') !== false) $ext = 'pdf';
else if (strpos($mime, 'mp4') !== false) $ext = 'mp4';

$safe_media_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', $media_id);
if ($safe_media_id === '') {
    http_response_code(400);
    exit;
}
$filename = "media_{$safe_media_id}.$ext";
$upload_dir = __DIR__ . "/assets/uploads";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0775, true);
}
$save_path = $upload_dir . "/$filename";

// 5. Paso 2: Descargar el binario con SSL verificado
$ch2 = curl_init($meta_media_url);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $meta_token"]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
$binary = curl_exec($ch2);
curl_close($ch2);

if ($binary) {
    file_put_contents($save_path, $binary);

    $base_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if ($base_path == '/') $base_path = '';

    $local_url = $base_path . "/assets/uploads/$filename";

    // Actualizar caché en BD con sentencia preparada acotada a la conversación
    $stmtUpd = $con->prepare("UPDATE mensajes_y_eventos SET url_archivo = ? WHERE id_conversacion = ? AND url_archivo = ?");
    if ($stmtUpd) {
        $stmtUpd->bind_param("sis", $local_url, $chat_id, $media_id);
        $stmtUpd->execute();
        $stmtUpd->close();
    }

    $mime_local = mime_content_type($save_path);
    if (strpos($save_path, '.ogg') !== false) $mime_local = 'audio/ogg';
    stream_file($save_path, $mime_local);
    exit;
} else {
    http_response_code(500);
}
?>
