<?php
require_once 'config/database.php';
$con = getDbConnection();

$media_id = $_GET['id'] ?? '';
$chat_id = intval($_GET['chat_id'] ?? 0);

if (empty($media_id) || $chat_id <= 0) {
    http_response_code(400);
    exit;
}

// If media_id is already a path (from old DB format), try to serve it directly
if (strpos($media_id, '/assets/uploads/') !== false || strpos($media_id, 'media_') !== false) {
    $filename = basename(str_replace('\\', '/', $media_id));
    $direct_path = __DIR__ . '/assets/uploads/' . $filename;
    if (file_exists($direct_path) && filesize($direct_path) > 200) {
        $mime_local = mime_content_type($direct_path);
        if (strpos($direct_path, '.ogg') !== false) $mime_local = 'audio/ogg';
        header('Content-Type: ' . $mime_local);
        header('Content-Length: ' . filesize($direct_path));
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000');
        readfile($direct_path);
        exit;
    }
}

// Check if we already cached it in DB
$res = $con->query("SELECT url_archivo FROM mensajes_y_eventos WHERE url_archivo LIKE '%$media_id%' AND id_conversacion = $chat_id LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $url = $row['url_archivo'];
    if (strpos($url, '/assets/uploads/') !== false) {
        // Fallback relative path calculation to check if file exists
        $local_path = __DIR__ . substr($url, strpos($url, '/assets/uploads/'));
        if (file_exists($local_path) && filesize($local_path) > 200) {
            $mime_local = mime_content_type($local_path);
            if (strpos($local_path, '.ogg') !== false) $mime_local = 'audio/ogg';
            header('Content-Type: ' . $mime_local);
            header('Content-Length: ' . filesize($local_path));
            header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000');
            readfile($local_path);
            exit;
        }
    }
}

// Fetch meta token
$q = "SELECT l.meta_token FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id WHERE c.id = $chat_id LIMIT 1";
$resToken = $con->query($q);
if (!$resToken || $resToken->num_rows == 0) {
    http_response_code(404);
    exit;
}
$meta_token = $resToken->fetch_assoc()['meta_token'];

// Step 1: Get media URL from Meta
$ch = curl_init("https://graph.facebook.com/v19.0/$media_id");
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $meta_token"]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

$media_info = json_decode($response, true);
if (!isset($media_info['url'])) {
    http_response_code(404);
    exit;
}
$meta_media_url = $media_info['url'];
$mime = $media_info['mime_type'];

// Extensions based on MIME
$ext = 'bin';
if (strpos($mime, 'jpeg') !== false) $ext = 'jpg';
else if (strpos($mime, 'png') !== false) $ext = 'png';
else if (strpos($mime, 'webp') !== false) $ext = 'webp';
else if (strpos($mime, 'ogg') !== false) $ext = 'ogg';
else if (strpos($mime, 'pdf') !== false) $ext = 'pdf';

$filename = "media_{$media_id}.$ext";
$upload_dir = __DIR__ . "/assets/uploads";
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}
$save_path = $upload_dir . "/$filename";

// Step 2: Download the binary file
$ch2 = curl_init($meta_media_url);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $meta_token"]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch2, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
$binary = curl_exec($ch2);
curl_close($ch2);

if ($binary) {
    file_put_contents($save_path, $binary);
    
    // Calcular el directorio relativo para que funcione independientemente de si está en /starfi_crm o en raíz
    $base_path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if ($base_path == '/') $base_path = '';
    
    $local_url = $base_path . "/assets/uploads/$filename";
    
    // Update DB to cache it
    $con->query("UPDATE mensajes_y_eventos SET url_archivo = '$local_url' WHERE url_archivo = '$media_id'");
    
    $mime_local = mime_content_type($save_path);
    if (strpos($save_path, '.ogg') !== false) $mime_local = 'audio/ogg';
    header('Content-Type: ' . $mime_local);
    header('Content-Length: ' . filesize($save_path));
    header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000');
    readfile($save_path);
    exit;
} else {
    http_response_code(500);
}
?>
