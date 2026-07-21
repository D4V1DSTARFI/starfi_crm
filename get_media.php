<?php
require_once 'config/database.php';
$con = getDbConnection();

$media_id = $_GET['id'] ?? '';
$chat_id = intval($_GET['chat_id'] ?? 0);

if (empty($media_id) || $chat_id <= 0) {
    http_response_code(400);
    exit;
}

// Check if we already cached it in DB
$res = $con->query("SELECT url_archivo FROM mensajes_y_eventos WHERE url_archivo LIKE '%$media_id%' AND id_conversacion = $chat_id LIMIT 1");
if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $url = $row['url_archivo'];
    if (strpos($url, '/assets/uploads/') !== false && file_exists(__DIR__ . str_replace('/starfi_crm', '', $url))) {
        header("Location: " . $url);
        exit;
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
$save_path = __DIR__ . "/assets/uploads/$filename";

// Step 2: Download the binary file
$ch2 = curl_init($meta_media_url);
curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $meta_token"]);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
$binary = curl_exec($ch2);
curl_close($ch2);

if ($binary) {
    file_put_contents($save_path, $binary);
    $local_url = "/starfi_crm/assets/uploads/$filename";
    
    // Update DB to cache it
    $con->query("UPDATE mensajes_y_eventos SET url_archivo = '$local_url' WHERE url_archivo = '$media_id'");
    
    header("Location: " . $local_url);
} else {
    http_response_code(500);
}
?>
