<?php
/**
 * API Transaccional y Sincronización WABA — STARFI CRM
 * Autenticación obligatoria mediante api_token de 64 caracteres perteneciente a una sede ACTIVA
 * en todas sus ramas (cero fallbacks anónimos, cero tokens hardcodeados, cero exposición de errores SQL).
 */

@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';

// Política CORS restringida (solo orígenes explícitos en ALLOWED_CORS_ORIGINS o mismo host)
$req_origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($req_origin !== '') {
    $allowed_raw = isset($env['ALLOWED_CORS_ORIGINS']) ? (string)$env['ALLOWED_CORS_ORIGINS'] : (string)(getenv('ALLOWED_CORS_ORIGINS') ?: '');
    $allowed_origins = array_filter(array_map('trim', explode(',', $allowed_raw)));
    $req_host = parse_url($req_origin, PHP_URL_HOST);
    $srv_host = explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0];
    if (in_array($req_origin, $allowed_origins, true) || ($req_host && $srv_host && strcasecmp($req_host, $srv_host) === 0)) {
        header('Access-Control-Allow-Origin: ' . $req_origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function starfi_api_log_error($msg) {
    $log_dir = __DIR__ . '/logs';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0755, true);
    }
    @file_put_contents(
        $log_dir . '/logs_api_notificaciones.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL,
        FILE_APPEND
    );
}

function starfi_extract_bearer_or_token(array $json_body = []) {
    $auth_hdr = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth_hdr === '' && function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        if (is_array($hdrs)) {
            foreach ($hdrs as $hk => $hv) {
                if (strcasecmp($hk, 'Authorization') === 0) {
                    $auth_hdr = (string)$hv;
                    break;
                }
            }
        }
    }
    if (preg_match('/^\s*Bearer\s+([a-f0-9]{64})\s*$/i', $auth_hdr, $m)) {
        return strtolower($m[1]);
    }

    $candidates = [
        $_POST['api_token'] ?? '',
        $_POST['verify_token'] ?? '',
        $_POST['token_tienda'] ?? '',
        $_POST['api_key'] ?? '',
        $_POST['token'] ?? '',
        $json_body['api_token'] ?? '',
        $json_body['verify_token'] ?? '',
        $json_body['token_tienda'] ?? '',
        $json_body['api_key'] ?? '',
        $json_body['token'] ?? '',
    ];
    foreach ($candidates as $cand) {
        $t = strtolower(trim((string)$cand));
        if (preg_match('/^[a-f0-9]{64}$/', $t)) {
            return $t;
        }
    }
    return '';
}

$raw_input = file_get_contents('php://input');
$raw_json = json_decode((string)$raw_input, true);
if (!is_array($raw_json)) {
    $raw_json = [];
}
foreach ($raw_json as $k => $v) {
    if (!isset($_POST[$k])) {
        $_POST[$k] = $v;
    }
}

$api_token_validado = starfi_extract_bearer_or_token($raw_json);

// =========================================================================
// RAMA 1: SINCRONIZACIÓN REMOTA DE LÍNEA (accion = sync_linea)
// =========================================================================
if (isset($_POST['accion']) && $_POST['accion'] === 'sync_linea') {
    if ($api_token_validado === '') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'No autorizado: se requiere un api_token válido de 64 caracteres.']);
        exit;
    }

    $con = getDbConnection();
    if (!$con) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error interno de conexión.']);
        exit;
    }

    $stmt_sede = $con->prepare("SELECT id, estado FROM sedes WHERE api_token = ? LIMIT 1");
    if (!$stmt_sede) {
        $con->close();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error interno al validar credenciales.']);
        exit;
    }
    $stmt_sede->bind_param("s", $api_token_validado);
    $stmt_sede->execute();
    $res_sede = $stmt_sede->get_result();
    $row_sede = $res_sede ? $res_sede->fetch_assoc() : null;
    $stmt_sede->close();

    if (!$row_sede) {
        $con->close();
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Token inválido o no reconocido.']);
        exit;
    }
    if (strtoupper(trim((string)($row_sede['estado'] ?? ''))) !== 'ACTIVO') {
        $con->close();
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'La sede asociada al token se encuentra pausada o inactiva.']);
        exit;
    }

    $crm_sede_id = intval($row_sede['id']);
    $instance_id = trim((string)($_POST['instance_id'] ?? ''));
    $phone_number = preg_replace('/[^0-9]/', '', (string)($_POST['phone_number'] ?? ''));
    $activo = isset($_POST['activo']) ? intval($_POST['activo']) : 1;
    $crm_estado = ($activo === 1) ? 'ACTIVO' : 'INACTIVO';

    $meta_token_param = trim((string)($_POST['meta_token'] ?? ''));
    $raw_tok_post = trim((string)($_POST['token'] ?? ''));
    if ($meta_token_param !== '' && strpos($meta_token_param, 'EAA') === 0) {
        $valid_meta_token = $meta_token_param;
    } elseif ($raw_tok_post !== '' && strpos($raw_tok_post, 'EAA') === 0) {
        $valid_meta_token = $raw_tok_post;
    } else {
        $valid_meta_token = defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '';
    }

    $stmt_chk = $con->prepare("SELECT id, id_negocio, meta_app_id FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1");
    $stmt_chk->bind_param("i", $crm_sede_id);
    $stmt_chk->execute();
    $existing = $stmt_chk->get_result()->fetch_assoc();
    $stmt_chk->close();

    $ok_sync = false;
    if ($existing) {
        if (!empty($existing['id_negocio']) && !empty($existing['meta_app_id'])) {
            $stmt_up = $con->prepare("UPDATE lineas_whatsapp SET meta_token = ?, estado = ? WHERE id_sede = ?");
            $stmt_up->bind_param("ssi", $valid_meta_token, $crm_estado, $crm_sede_id);
        } else {
            $stmt_up = $con->prepare("UPDATE lineas_whatsapp SET meta_token = ?, meta_app_id = ?, numero_telefono = ?, estado = ? WHERE id_sede = ?");
            $stmt_up->bind_param("ssssi", $valid_meta_token, $instance_id, $phone_number, $crm_estado, $crm_sede_id);
        }
        $ok_sync = $stmt_up->execute();
        if (!$ok_sync) {
            starfi_api_log_error('sync_linea UPDATE error sede=' . $crm_sede_id . ': ' . $stmt_up->error);
        }
        $stmt_up->close();
    } else {
        $stmt_in = $con->prepare("INSERT INTO lineas_whatsapp (id_sede, descripcion, numero_telefono, meta_app_id, meta_token, estado) VALUES (?, 'API WSAP AUTO SYNCED', ?, ?, ?, ?)");
        $stmt_in->bind_param("issss", $crm_sede_id, $phone_number, $instance_id, $valid_meta_token, $crm_estado);
        $ok_sync = $stmt_in->execute();
        if (!$ok_sync) {
            starfi_api_log_error('sync_linea INSERT error sede=' . $crm_sede_id . ': ' . $stmt_in->error);
        }
        $stmt_in->close();
    }

    $con->close();
    if ($ok_sync) {
        echo json_encode(['status' => 'success', 'message' => 'Sincronizado con CRM correctamente.']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'No se pudo completar la sincronización de línea en el CRM.']);
    }
    exit;
}

// =========================================================================
// RAMA 2: CONSULTA DE CREDENCIALES DE LÍNEA (accion = get_info)
// =========================================================================
if (isset($_POST['accion']) && $_POST['accion'] === 'get_info') {
    if ($api_token_validado === '') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'No autorizado: se requiere un api_token válido de 64 caracteres.']);
        exit;
    }

    $con = getDbConnection();
    if (!$con) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error interno de conexión.']);
        exit;
    }

    $stmt = $con->prepare("
        SELECT l.meta_app_id AS instance_id, l.numero_telefono AS phone_number, s.nombre_sede, s.estado AS estado_sede, l.estado
        FROM sedes s
        LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id
        WHERE s.api_token = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $api_token_validado);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($data = $res->fetch_assoc())) {
            if (strtoupper(trim((string)($data['estado_sede'] ?? ''))) !== 'ACTIVO') {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'La sede asociada al token se encuentra inactiva.']);
            } else {
                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'instance_id' => $data['instance_id'] ?? '',
                        'phone_number' => $data['phone_number'] ?? '',
                        'nombre_sede' => $data['nombre_sede'],
                        'estado_linea' => $data['estado'] ?? 'SIN_LINEA'
                    ]
                ]);
            }
        } else {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Token inválido o Sede no encontrada.']);
        }
        $stmt->close();
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error de base de datos.']);
    }
    $con->close();
    exit;
}

// =========================================================================
// RAMA 3: DESPACHO TRANSACCIONAL DE PLANTILLAS OFICIALES META
// =========================================================================
if ($api_token_validado === '') {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'No autorizado: toda solicitud de notificación requiere un api_token válido de 64 caracteres.'
    ]);
    exit;
}

if (!empty($raw_json)) {
    if (empty($_POST['telefono'])) {
        $_POST['telefono'] = $raw_json['telefono_destino'] ?? $raw_json['telefono'] ?? '';
    }
    if (isset($raw_json['variables']) && is_array($raw_json['variables'])) {
        $vars = $raw_json['variables'];
        if (!isset($_POST['nombre_cliente'])) $_POST['nombre_cliente'] = $vars[0] ?? 'Cliente';
        if (!isset($_POST['monto_total'])) $_POST['monto_total'] = $vars[2] ?? '0.00';
        if (!isset($_POST['asesor_ventas'])) $_POST['asesor_ventas'] = $vars[3] ?? 'Asesor';
        if (!isset($_POST['nombre_empresa'])) $_POST['nombre_empresa'] = $vars[4] ?? 'Empresa';
        if (!isset($_POST['correlativo'])) $_POST['correlativo'] = $vars[5] ?? '';
        if (!isset($_POST['telefono_asesor'])) $_POST['telefono_asesor'] = $vars[6] ?? '0000000000';
    }
    if (isset($raw_json['template_name'])) {
        $_POST['template_name'] = $raw_json['template_name'];
    } elseif (isset($raw_json['plantilla'])) {
        $_POST['template_name'] = $raw_json['plantilla'];
    }
    if (isset($raw_json['template_params'])) {
        $_POST['template_params'] = $raw_json['template_params'];
    }
}

$telefono = (string)($_POST['telefono'] ?? '');
$telefono_asesor = (string)($_POST['telefono_asesor'] ?? '0000000000');
$nombre_cliente = (string)($_POST['nombre_cliente'] ?? 'Cliente');
$monto_total = (string)($_POST['monto_total'] ?? '0.00');
$asesor_ventas = (string)($_POST['asesor_ventas'] ?? 'Nuestro Asesor');
$correlativo = (string)($_POST['correlativo'] ?? '');
$nombre_empresa = (string)($_POST['nombre_empresa'] ?? 'Nuestra Empresa');
$fecha_compra = date('Y-m-d');
$hora_actual = date('H:i:s');
$signo = (string)($_POST['moneda'] ?? 'USD');

if ($telefono === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No phone number provided']);
    exit;
}

$telefono = preg_replace('/[^0-9]/', '', $telefono);
if (strlen($telefono) === 11 && strpos($telefono, '0') === 0) {
    $telefono = substr($telefono, 1);
} elseif (strlen($telefono) === 13 && strpos($telefono, '580') === 0) {
    $telefono = '58' . substr($telefono, 3);
}
if (strlen($telefono) === 10) {
    $telefono = '58' . $telefono;
}

if (strlen($telefono) !== 12 || !preg_match('/^58(414|424|412|416|426|2[0-9]{2})[0-9]{7}$/', $telefono)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'El número de teléfono provisto es inválido para el formato requerido.'
    ]);
    exit;
}

$con = getDbConnection();
if (!$con) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error interno de conexión.']);
    exit;
}

// Autenticación exclusiva por api_token de sede activa (cero fallbacks por id_sede, nombre_empresa o primera línea)
$stmt_token = $con->prepare("
    SELECT l.id AS id_linea, l.meta_app_id, l.meta_token, l.estado AS estado_linea,
           s.estado AS estado_sede, s.id_empresa, s.id AS crm_id_sede, s.nombre_sede
    FROM sedes s
    LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id
    WHERE s.api_token = ?
    ORDER BY (l.estado = 'ACTIVO') DESC, l.id ASC
    LIMIT 1
");
if (!$stmt_token) {
    $con->close();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error interno al autenticar la sede.']);
    exit;
}

$stmt_token->bind_param("s", $api_token_validado);
$stmt_token->execute();
$res_tok = $stmt_token->get_result();
$row_auth = $res_tok ? $res_tok->fetch_assoc() : null;
$stmt_token->close();

if (!$row_auth) {
    $con->close();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No autorizado: api_token inválido.']);
    exit;
}

if (strtoupper(trim((string)($row_auth['estado_sede'] ?? ''))) !== 'ACTIVO') {
    $con->close();
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Envío denegado: la sede autenticada se encuentra pausada o inactiva.']);
    exit;
}

$telefonoID = trim((string)($row_auth['meta_app_id'] ?? ''));
$token = trim((string)($row_auth['meta_token'] ?? ''));
if ($token === '' && defined('META_GLOBAL_TOKEN') && META_GLOBAL_TOKEN !== '') {
    $token = META_GLOBAL_TOKEN;
}
$id_linea = intval($row_auth['id_linea'] ?? 0);
$id_empresa = intval($row_auth['id_empresa'] ?? 1);
$crm_id_sede = intval($row_auth['crm_id_sede'] ?? 0);

if (strtoupper(trim((string)($row_auth['estado_linea'] ?? ''))) !== 'ACTIVO' || $token === '' || $telefonoID === '') {
    $con->close();
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Línea de WhatsApp inactiva o no configurada para la sede autenticada.'
    ]);
    exit;
}

$template_name = trim((string)($_POST['template_name'] ?? ''));
if ($template_name === '' && $crm_id_sede > 0) {
    $legacy_id = ($crm_id_sede === 24) ? 23 : ($crm_id_sede - 2);
    $con_ventas = @getExternalDbConnection('ventas');
    if ($con_ventas) {
        $stmt_tpl = $con_ventas->prepare("SELECT plantilla FROM config_api_wsap_plantillas WHERE id_sede = ? AND modulo = 'VENTAS' LIMIT 1");
        if ($stmt_tpl) {
            $stmt_tpl->bind_param("i", $legacy_id);
            $stmt_tpl->execute();
            $res_tpl = $stmt_tpl->get_result();
            if ($res_tpl && ($row_t = $res_tpl->fetch_assoc()) && !empty($row_t['plantilla'])) {
                $template_name = (string)$row_t['plantilla'];
            }
            $stmt_tpl->close();
        }
        mysqli_close($con_ventas);
    }
}

if ($template_name === '') {
    $template_name = 'send_confirmacion_compra_cn_sn_btn';
}

$api_ver = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
$url = 'https://graph.facebook.com/' . $api_ver . '/' . $telefonoID . '/messages';
$header = ["Authorization: Bearer " . $token, "Content-Type: application/json"];

if (isset($_POST['template_params']) && is_array($_POST['template_params'])) {
    $params = $_POST['template_params'];
} elseif (isset($_POST['template_params']) && is_string($_POST['template_params'])) {
    $params = json_decode($_POST['template_params'], true) ?? [];
} elseif (isset($raw_json['variables']) && is_array($raw_json['variables']) && !empty($raw_json['variables'])) {
    $params = $raw_json['variables'];
} else {
    $params = [
        $nombre_cliente,
        $fecha_compra,
        $monto_total . ' ' . $signo,
        $asesor_ventas,
        $nombre_empresa,
        $correlativo,
        $telefono_asesor
    ];
}

$components = [];
$document_url = (string)($_POST['documento_url'] ?? $_POST['document_url'] ?? $_POST['url_documento'] ?? ($raw_json['documento_url'] ?? $raw_json['document_url'] ?? ''));
$media_id = (string)($_POST['media_id'] ?? ($raw_json['media_id'] ?? ''));
$filename = basename((string)($_POST['archivo_nombre'] ?? $_POST['filename'] ?? ($raw_json['archivo_nombre'] ?? 'Documento.pdf')));
$upload_mime = 'application/pdf';
$url_archivo_local = '';

$allowed_api_mimes = [
    'application/pdf' => 'pdf',
    'image/png'       => 'png',
    'image/jpeg'      => 'jpg',
    'image/webp'      => 'webp',
    'video/mp4'       => 'mp4',
];

// Adjunto vía Base64 validado con finfo y límite de 5 MB
$doc_b64 = (string)($_POST['documento_base64'] ?? ($raw_json['documento_base64'] ?? ($raw_json['pdf_base64'] ?? '')));
if ($media_id === '' && $document_url === '' && $doc_b64 !== '') {
    $decoded_bin = base64_decode($doc_b64, true);
    if ($decoded_bin !== false && strlen($decoded_bin) > 0 && strlen($decoded_bin) <= 5 * 1024 * 1024) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected_mime = (string)$finfo->buffer($decoded_bin);
        if (isset($allowed_api_mimes[$detected_mime])) {
            $upload_mime = $detected_mime;
            $safe_ext = $allowed_api_mimes[$detected_mime];
            $tmp_b64_file = tempnam(sys_get_temp_dir(), 'wsap_doc_');
            file_put_contents($tmp_b64_file, $decoded_bin);

            $save_dir = __DIR__ . '/uploads/documentos';
            if (!is_dir($save_dir)) {
                @mkdir($save_dir, 0755, true);
            }
            $base_stem = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($filename, PATHINFO_FILENAME)) ?: 'documento';
            $local_name = time() . '_' . bin2hex(random_bytes(3)) . '_' . substr($base_stem, 0, 60) . '.' . $safe_ext;
            @copy($tmp_b64_file, $save_dir . '/' . $local_name);
            $url_archivo_local = 'uploads/documentos/' . $local_name;

            $media_url = 'https://graph.facebook.com/' . $api_ver . '/' . $telefonoID . '/media';
            $ch_media = curl_init($media_url);
            $cfile = new CURLFile($tmp_b64_file, $upload_mime, $base_stem . '.' . $safe_ext);
            curl_setopt($ch_media, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_media, CURLOPT_POST, true);
            curl_setopt($ch_media, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
            curl_setopt($ch_media, CURLOPT_POSTFIELDS, [
                'messaging_product' => 'whatsapp',
                'type' => $upload_mime,
                'file' => $cfile
            ]);
            $res_media = curl_exec($ch_media);
            curl_close($ch_media);
            @unlink($tmp_b64_file);

            $json_media = json_decode((string)$res_media, true);
            if (!empty($json_media['id'])) {
                $media_id = (string)$json_media['id'];
            }
        }
    }
}

// Adjunto vía $_FILES validado con finfo y límite de 5 MB
$file_key = isset($_FILES['documento']) ? 'documento' : (isset($_FILES['file']) ? 'file' : (isset($_FILES['archivo']) ? 'archivo' : null));
if ($media_id === '' && $document_url === '' && $file_key && ($_FILES[$file_key]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $upload_path = $_FILES[$file_key]['tmp_name'] ?? '';
    $upload_size = intval($_FILES[$file_key]['size'] ?? 0);
    if ($upload_path !== '' && is_uploaded_file($upload_path) && $upload_size > 0 && $upload_size <= 5 * 1024 * 1024) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected_mime = (string)$finfo->file($upload_path);
        if (isset($allowed_api_mimes[$detected_mime])) {
            $upload_mime = $detected_mime;
            $safe_ext = $allowed_api_mimes[$detected_mime];
            $raw_up_name = !empty($_FILES[$file_key]['name']) ? basename((string)$_FILES[$file_key]['name']) : $filename;
            $base_stem = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($raw_up_name, PATHINFO_FILENAME)) ?: 'documento';
            $safe_display = $base_stem . '.' . $safe_ext;

            $save_dir = __DIR__ . '/uploads/documentos';
            if (!is_dir($save_dir)) {
                @mkdir($save_dir, 0755, true);
            }
            $local_name = time() . '_' . bin2hex(random_bytes(3)) . '_' . substr($base_stem, 0, 60) . '.' . $safe_ext;
            @copy($upload_path, $save_dir . '/' . $local_name);
            $url_archivo_local = 'uploads/documentos/' . $local_name;

            $media_url = 'https://graph.facebook.com/' . $api_ver . '/' . $telefonoID . '/media';
            $ch_media = curl_init($media_url);
            $cfile = new CURLFile($upload_path, $upload_mime, $safe_display);
            curl_setopt($ch_media, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_media, CURLOPT_POST, true);
            curl_setopt($ch_media, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
            curl_setopt($ch_media, CURLOPT_POSTFIELDS, [
                'messaging_product' => 'whatsapp',
                'type' => $upload_mime,
                'file' => $cfile
            ]);
            $res_media = curl_exec($ch_media);
            curl_close($ch_media);

            $json_media = json_decode((string)$res_media, true);
            if (!empty($json_media['id'])) {
                $media_id = (string)$json_media['id'];
                $filename = $safe_display;
            }
        }
    }
}

if ($media_id !== '' || $document_url !== '') {
    $is_image = (strpos($upload_mime, 'image/') === 0);
    $is_video = (strpos($upload_mime, 'video/') === 0);

    if ($is_image) {
        $img_obj = ($media_id !== '') ? ['id' => $media_id] : ['link' => $document_url];
        $components[] = ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => $img_obj]]];
    } elseif ($is_video) {
        $vid_obj = ($media_id !== '') ? ['id' => $media_id] : ['link' => $document_url];
        $components[] = ['type' => 'header', 'parameters' => [['type' => 'video', 'video' => $vid_obj]]];
    } else {
        $doc_obj = ['filename' => $filename];
        if ($media_id !== '') {
            $doc_obj['id'] = $media_id;
        } else {
            $doc_obj['link'] = $document_url;
        }
        $components[] = ['type' => 'header', 'parameters' => [['type' => 'document', 'document' => $doc_obj]]];
    }
}

$components[] = [
    'type' => 'body',
    'parameters' => array_map(function ($p) {
        $str = trim((string)$p);
        return ['type' => 'text', 'text' => ($str !== '' ? $str : 'N/A')];
    }, $params)
];

$mensaje_payload = [
    'messaging_product' => 'whatsapp',
    'to' => $telefono,
    'type' => 'template',
    'template' => [
        'name' => $template_name,
        'language' => ['code' => 'es'],
        'components' => $components
    ]
];
$mensaje = json_encode($mensaje_payload, JSON_UNESCAPED_UNICODE);

$curl = curl_init();
curl_setopt($curl, CURLOPT_URL, $url);
curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje);
curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
$raw_exec = curl_exec($curl);
$estado = json_decode((string)$raw_exec, true);
$status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($status_code != 200 && isset($estado['error']['code']) && $estado['error']['code'] == 132000) {
    $err_data = $estado['error']['error_data']['details'] ?? $estado['error']['message'] ?? '';
    if (preg_match('/expected number of params \((\d+)\)/i', (string)$err_data, $matches)) {
        $expected_count = intval($matches[1]);
        if ($expected_count > 0 && $expected_count < count($params)) {
            $params_sliced = array_slice($params, 0, $expected_count);
            foreach ($mensaje_payload['template']['components'] as &$comp_item) {
                if ($comp_item['type'] === 'body') {
                    $comp_item['parameters'] = array_map(function ($p) {
                        $str = trim((string)$p);
                        return ['type' => 'text', 'text' => ($str !== '' ? $str : 'N/A')];
                    }, $params_sliced);
                    break;
                }
            }
            unset($comp_item);

            $mensaje = json_encode($mensaje_payload, JSON_UNESCAPED_UNICODE);
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $mensaje);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            $raw_exec = curl_exec($curl);
            $estado = json_decode((string)$raw_exec, true);
            $status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
        }
    }
}

$response = [];
if ($status_code == 200) {
    $response['status_envio'] = "[EXITOSO]";
    $status_bd = 'ENVIADO';
} else {
    $response['status_envio'] = "[ERROR]";
    $status_bd = 'ERROR';
}

$telefono_emisor = $telefonoID;
$telefono_receptor = $telefono;
if (isset($_POST['template_name']) || isset($_POST['template_params'])) {
    $contenido = 'Envío dinámico de plantilla: ' . $template_name . '. Params: ' . json_encode($params, JSON_UNESCAPED_UNICODE);
} else {
    $contenido = 'Confirmacion de compra, cliente: ' . $nombre_cliente . ', fecha compra: ' . $fecha_compra . ', monto total: ' . $monto_total . ' ' . $signo . ', asesor de ventas: ' . $asesor_ventas . ', empresa: ' . $nombre_empresa . ', correlativo: ' . $correlativo . ', contacto: ' . $telefono_asesor;
}

$stmt_notif = $con->prepare("INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
$insertar = false;
if ($stmt_notif) {
    $stmt_notif->bind_param("sssssss", $template_name, $fecha_compra, $hora_actual, $telefono_emisor, $telefono_receptor, $contenido, $status_bd);
    $insertar = $stmt_notif->execute();
    if (!$insertar) {
        starfi_api_log_error('notificacion_enviada INSERT error: ' . $stmt_notif->error);
    }
    $stmt_notif->close();
}
$response['registro_bd'] = $insertar ? "[EXITOSO]" : "[ERROR]";

if ($id_linea > 0) {
    $ts_now = $fecha_compra . ' ' . $hora_actual;
    $id_cliente = 0;

    $stmt_cli = $con->prepare("SELECT id FROM clientes_contactos WHERE id_empresa = ? AND numero_whatsapp = ? LIMIT 1");
    if ($stmt_cli) {
        $stmt_cli->bind_param("is", $id_empresa, $telefono);
        $stmt_cli->execute();
        $rc = $stmt_cli->get_result()->fetch_assoc();
        $stmt_cli->close();
        if ($rc) {
            $id_cliente = intval($rc['id']);
            $stmt_uc = $con->prepare("UPDATE clientes_contactos SET ultimo_contacto = ? WHERE id = ?");
            if ($stmt_uc) {
                $stmt_uc->bind_param("si", $ts_now, $id_cliente);
                $stmt_uc->execute();
                $stmt_uc->close();
            }
        }
    }

    if ($id_cliente <= 0) {
        $stmt_ic = $con->prepare("INSERT INTO clientes_contactos (id_empresa, numero_whatsapp, nombre, fecha_registro, ultimo_contacto) VALUES (?, ?, ?, ?, ?)");
        if ($stmt_ic) {
            $stmt_ic->bind_param("issss", $id_empresa, $telefono, $nombre_cliente, $ts_now, $ts_now);
            $stmt_ic->execute();
            $id_cliente = intval($stmt_ic->insert_id);
            $stmt_ic->close();
        }
    }

    // Buscar conversación activa de la MISMA sede/línea para no mezclar historiales entre sedes
    $id_conversacion = 0;
    $stmt_cv = $con->prepare("
        SELECT c.id
        FROM conversaciones c
        JOIN lineas_whatsapp l ON c.id_linea = l.id
        WHERE c.id_cliente = ? AND l.id_sede = ? AND c.estado != 'CERRADO'
        ORDER BY c.id DESC
        LIMIT 1
    ");
    if ($stmt_cv) {
        $stmt_cv->bind_param("ii", $id_cliente, $crm_id_sede);
        $stmt_cv->execute();
        $rcv = $stmt_cv->get_result()->fetch_assoc();
        $stmt_cv->close();
        if ($rcv) {
            $id_conversacion = intval($rcv['id']);
            $stmt_upcv = $con->prepare("UPDATE conversaciones SET id_linea = ? WHERE id = ?");
            if ($stmt_upcv) {
                $stmt_upcv->bind_param("ii", $id_linea, $id_conversacion);
                $stmt_upcv->execute();
                $stmt_upcv->close();
            }
        }
    }

    if ($id_conversacion <= 0) {
        $stmt_incv = $con->prepare("INSERT INTO conversaciones (id_linea, id_cliente, estado, fecha_inicio) VALUES (?, ?, 'RESUELTO', ?)");
        if ($stmt_incv) {
            $stmt_incv->bind_param("iis", $id_linea, $id_cliente, $ts_now);
            $stmt_incv->execute();
            $id_conversacion = intval($stmt_incv->insert_id);
            $stmt_incv->close();
        }
    }

    $id_mensaje_meta = isset($estado['messages'][0]['id']) ? (string)$estado['messages'][0]['id'] : '';
    $estado_envio = ($status_code == 200 && $id_mensaje_meta !== '') ? 'ENVIADO' : 'FALLIDO';
    $error_detalle = null;

    if ($estado_envio === 'FALLIDO') {
        if (isset($estado['error'])) {
            $code = $estado['error']['code'] ?? '';
            $msg = $estado['error']['message'] ?? '';
            $details = $estado['error']['error_data']['details'] ?? '';
            if ($code == 131047) {
                $error_detalle = "Ventana de 24 horas excedida (Error Meta 131047).";
            } elseif ($code == 131026) {
                $error_detalle = "Mensaje no entregable (Error Meta 131026).";
            } elseif ($code == 131049) {
                $error_detalle = "Mensaje no entregado (Error Meta 131049).";
            } elseif ($code == 132000) {
                $error_detalle = "Incompatibilidad de parámetros en plantilla (Error Meta 132000): " . ($details ?: $msg);
            } elseif ($code == 132012) {
                $error_detalle = "Incompatibilidad de formato en plantilla (Error Meta 132012): " . ($details ?: $msg);
            } elseif ($code == 130472) {
                $error_detalle = "Restricción de Meta (Error 130472).";
            } else {
                $error_detalle = "Rechazado por Meta (Error $code): " . ($details ?: $msg);
            }
        } else {
            $error_detalle = "Fallo de conexión o error HTTP $status_code con la API de Meta WhatsApp.";
        }
    }

    $tipo_msg = ($media_id !== '' || $document_url !== '') ? 'DOCUMENTO' : 'EVENTO_SISTEMA';
    $url_archivo_val = ($url_archivo_local !== '') ? $url_archivo_local : (($document_url !== '') ? $document_url : null);
    $mime_val = ($media_id !== '' || $document_url !== '') ? $upload_mime : null;

    $stmt_msg = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, id_mensaje_meta, tipo, origen, contenido, url_archivo, mime_type, estado_envio, error_detalle, timestamp) VALUES (?, ?, ?, 'API_TRANSACCIONAL', ?, ?, ?, ?, ?, ?)");
    $nuevo_msg_id_api = 0;
    if ($stmt_msg) {
        $stmt_msg->bind_param("issssssss", $id_conversacion, $id_mensaje_meta, $tipo_msg, $contenido, $url_archivo_val, $mime_val, $estado_envio, $error_detalle, $ts_now);
        if ($stmt_msg->execute()) {
            $nuevo_msg_id_api = intval($stmt_msg->insert_id);
        }
        $stmt_msg->close();
    }

    if ($nuevo_msg_id_api > 0) {
        $stmt_um = $con->prepare("UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?) WHERE id = ?");
        if ($stmt_um) {
            $stmt_um->bind_param("ii", $nuevo_msg_id_api, $id_conversacion);
            $stmt_um->execute();
            $stmt_um->close();
        }
    }

    // [COM-02] Sincronizar monto estimado USD solo cuando monto_estimado_usd <= 0 (nunca sobrescribir COTIZADO / manuales)
    $tpl_lower = strtolower(trim($template_name));
    if (!in_array($tpl_lower, ['starfi_difusion_directa', 'starfi_csat_survey', 'starfi_notificacion_interna'], true)) {
        require_once __DIR__ . '/core/crm_sprint2_helper.php';
        $raw_monto_notif = isset($params[2]) ? (string)$params[2] : $monto_total;
        $monto_notif_usd = starfi_parse_usd_number($raw_monto_notif);
        if ($monto_notif_usd > 0 && $crm_id_sede > 0 && $id_cliente > 0) {
            $deal_sync_id = starfi_ensure_pipeline_lead($con, $crm_id_sede, $id_cliente, $id_conversacion, null, '', $monto_notif_usd);
            if ($deal_sync_id > 0) {
                $stmt_pd = $con->prepare("UPDATE pipeline_oportunidades SET monto_estimado_usd = ? WHERE id = ? AND monto_estimado_usd <= 0");
                if ($stmt_pd) {
                    $stmt_pd->bind_param("di", $monto_notif_usd, $deal_sync_id);
                    $stmt_pd->execute();
                    $stmt_pd->close();
                }
            }
        }
    }
}

$con->close();
$response['meta_response'] = $estado;
echo json_encode($response);
?>
