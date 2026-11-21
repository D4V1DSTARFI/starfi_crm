<?php
// modules/campanas/back_campanas.php
// Ecosistema Independiente de Campañas de Difusión Masiva | STARFI CRM (QA-STD-GLOBAL-2.1)
date_default_timezone_set('America/Caracas');
@ini_set('display_errors', '0');
@error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/crm_sprint2_helper.php';
require_once __DIR__ . '/../../core/audit.php';

if (!defined('CAMPANAS_LIB_ONLY')) {
    require_once __DIR__ . '/../../core/auth.php';
    requireAuth();
    requirePermission('campanas');

    // Liberar el candado del archivo de sesión de PHP tras leer los datos del operador
    $agente = getAgenteInfo();
    $id_usuario = intval($agente['id'] ?? 1);
    $rol_usuario = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
    $id_sede_usuario = intval($agente['id_sede'] ?? 0);
    $waba_sub_cache = $_SESSION ?? [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    header('Content-Type: application/json; charset=utf-8');

    $con = getDbConnection();
    if (!($con instanceof mysqli)) {
        starfi_log_exception('campanas', 'Error de conexión a la base de datos en back_campanas.php');
        echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Error de conexión a la base de datos.']);
        exit;
    }

    // Solo MASTER y MASTER CI (o usuario sin sede asignada con rol MASTER/ADMIN) tienen visión global multi-sede.
    $es_global = ($id_usuario === 1 || $rol_usuario === 'MASTER' || $rol_usuario === 'MASTER CI' || ($rol_usuario === 'ADMIN' && $id_sede_usuario === 0));
} else {
    $id_usuario = 1;
    $rol_usuario = 'MASTER';
    $id_sede_usuario = 0;
    $es_global = true;
}

if (!defined('STARFI_CAMPANAS_MAX_UPLOAD_BYTES')) {
    define('STARFI_CAMPANAS_MAX_UPLOAD_BYTES', 5 * 1024 * 1024); // 5 MB estricto (QA-BUG-CRM2-04 / CHK-SEC-07)
}

/**
 * Validación estricta de cargas de archivos (<= 5 MB + inspección real finfo(FILEINFO_MIME_TYPE)).
 * Jamás confía en $_FILES[...]['type'] ni en la extensión de $_FILES[...]['name'].
 */
function starfi_validar_y_guardar_imagen_campana(array $fileInfo, int $id_sede, mysqli $con, int $id_usuario): array {
    $errCode = intval($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'message' => 'El archivo excede el límite máximo permitido de 5 MB.'];
    }
    if ($errCode !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Error al recibir el archivo subido.'];
    }

    $tmpPath = (string)($fileInfo['tmp_name'] ?? '');
    if ($tmpPath === '' || !file_exists($tmpPath) || !is_file($tmpPath)) {
        return ['ok' => false, 'message' => 'No se encontró el archivo temporal de subida.'];
    }
    if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'message' => 'Origen de archivo temporal no válido.'];
    }

    $declaredSize = intval($fileInfo['size'] ?? 0);
    $actualSize = @filesize($tmpPath);
    if ($actualSize === false) {
        return ['ok' => false, 'message' => 'No fue posible verificar el tamaño del archivo.'];
    }
    $actualSize = intval($actualSize);
    if ($declaredSize <= 0 || $actualSize <= 0) {
        return ['ok' => false, 'message' => 'El archivo subido está vacío (0 bytes).'];
    }
    if ($declaredSize > STARFI_CAMPANAS_MAX_UPLOAD_BYTES || $actualSize > STARFI_CAMPANAS_MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'message' => 'El archivo supera el tamaño máximo permitido de 5 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $realMime = strtolower(trim((string)$finfo->file($tmpPath)));

    $allowedImageMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($allowedImageMimes[$realMime])) {
        starfi_log_exception('campanas', 'Archivo rechazado por inspección finfo MIME', [
            'mime_detectado' => $realMime,
            'size' => $actualSize,
            'id_usuario' => $id_usuario,
            'id_sede' => $id_sede
        ]);
        registrar_auditoria(
            'campanas',
            'UPLOAD_RECHAZADO_SEGURIDAD',
            'Rechazo de archivo por MIME no permitido en campañas',
            null,
            ['mime_detectado' => $realMime, 'bytes' => $actualSize],
            $con,
            $id_usuario,
            $id_sede > 0 ? $id_sede : null
        );
        return ['ok' => false, 'message' => 'Tipo de archivo no permitido por seguridad. Solo se admiten imágenes reales JPG, PNG o WEBP de hasta 5 MB.'];
    }

    $extFromMime = $allowedImageMimes[$realMime];
    $dirUpload = __DIR__ . '/../../uploads/campanas';
    if (!is_dir($dirUpload)) {
        @mkdir($dirUpload, 0755, true);
    }

    $safeSede = max(0, intval($id_sede));
    $uniqueToken = bin2hex(random_bytes(6));

    if ($extFromMime === 'webp') {
        if (!function_exists('imagecreatefromstring')) {
            return ['ok' => false, 'message' => 'El servidor no dispone de soporte gráfico para convertir WEBP. Use JPG o PNG.'];
        }
        $rawBytes = @file_get_contents($tmpPath);
        $gdImg = ($rawBytes !== false && $rawBytes !== '') ? @imagecreatefromstring($rawBytes) : false;
        if ($gdImg === false) {
            return ['ok' => false, 'message' => 'El contenido binario de la imagen WEBP no es válido.'];
        }
        $fname = "camp_{$safeSede}_" . time() . "_{$uniqueToken}.png";
        $destAbs = $dirUpload . DIRECTORY_SEPARATOR . $fname;
        $savedOk = @imagepng($gdImg, $destAbs, 8);
        @imagedestroy($gdImg);
        if (!$savedOk) {
            return ['ok' => false, 'message' => 'No se pudo almacenar la imagen procesada.'];
        }
        return ['ok' => true, 'rel_path' => 'uploads/campanas/' . $fname, 'mime' => 'image/png'];
    }

    // Verificar integridad de imagen real con getimagesize además de finfo
    $imgInfo = @getimagesize($tmpPath);
    if ($imgInfo === false) {
        return ['ok' => false, 'message' => 'El archivo no contiene una estructura de imagen válida.'];
    }

    $fname = "camp_{$safeSede}_" . time() . "_{$uniqueToken}.{$extFromMime}";
    $destAbs = $dirUpload . DIRECTORY_SEPARATOR . $fname;
    $moved = @move_uploaded_file($tmpPath, $destAbs);
    if (!$moved && PHP_SAPI === 'cli') {
        $moved = @copy($tmpPath, $destAbs);
    }
    if (!$moved) {
        return ['ok' => false, 'message' => 'No se pudo guardar la imagen de cabecera en el servidor.'];
    }

    return ['ok' => true, 'rel_path' => 'uploads/campanas/' . $fname, 'mime' => $realMime];
}

// Guardia global temprana sobre cualquier archivo en $_FILES (QA-BUG-CRM2-04 / CHK-SEC-07)
if (!empty($_FILES) && is_array($_FILES)) {
    $globalAllowedMimes = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];
    foreach ($_FILES as $fieldKey => $fEntry) {
        if (!is_array($fEntry) || is_array($fEntry['error'] ?? null)) {
            continue;
        }
        $fErr = intval($fEntry['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($fErr === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($fErr === UPLOAD_ERR_INI_SIZE || $fErr === UPLOAD_ERR_FORM_SIZE) {
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'El archivo subido excede el límite máximo de 5 MB.']);
            exit;
        }
        if ($fErr !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Error durante la carga del archivo.']);
            exit;
        }
        $fTmp = (string)($fEntry['tmp_name'] ?? '');
        $fSize = intval($fEntry['size'] ?? 0);
        $fRealSize = ($fTmp !== '' && is_file($fTmp)) ? intval(@filesize($fTmp)) : 0;
        if ($fSize <= 0 || $fRealSize <= 0 || $fSize > STARFI_CAMPANAS_MAX_UPLOAD_BYTES || $fRealSize > STARFI_CAMPANAS_MAX_UPLOAD_BYTES) {
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'El tamaño del archivo debe ser mayor a 0 bytes y menor o igual a 5 MB.']);
            exit;
        }
        $finfoGuard = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower(trim((string)$finfoGuard->file($fTmp)));
        if (!isset($globalAllowedMimes[$detectedMime]) || ($fieldKey === 'header_imagen' && $detectedMime === 'application/pdf')) {
            starfi_log_exception('campanas', 'Bloqueo preventivo de subida por finfo MIME', [
                'campo' => (string)$fieldKey,
                'mime_real' => $detectedMime,
                'bytes' => $fRealSize
            ]);
            registrar_auditoria(
                'campanas',
                'BLOQUEO_UPLOAD_MIME',
                'Intento de subida bloqueado por validación estricta finfo MIME',
                null,
                ['campo' => (string)$fieldKey, 'mime_detectado' => $detectedMime, 'bytes' => $fRealSize],
                $con,
                $id_usuario,
                $id_sede_usuario > 0 ? $id_sede_usuario : null
            );
            echo json_encode(['status' => 'error', 'success' => false, 'message' => 'Archivo rechazado: el contenido real del archivo no corresponde a un formato permitido.']);
            exit;
        }
    }
}

// =========================================================================
// 0. AUTO-MIGRACIÓN DEL ECOSISTEMA DE CAMPAÑAS DE DIFUSIÓN (SIN INTERPOLACIÓN)
// =========================================================================
function ensureCampaignTables(mysqli $con) {
    if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
        return;
    }
    $lock_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'starfi_crm_camp_schema_v5.ok';
    if (@file_exists($lock_file) && (time() - @filemtime($lock_file)) < 86400) {
        return;
    }

    @$con->query('CREATE TABLE IF NOT EXISTS campanas_difusion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_sede INT NOT NULL DEFAULT 0,
        id_usuario INT NOT NULL DEFAULT 1,
        nombre_campana VARCHAR(160) NOT NULL,
        template_name VARCHAR(120) NOT NULL DEFAULT \'starfi_difusion_directa\',
        template_language VARCHAR(20) NOT NULL DEFAULT \'es\',
        template_category VARCHAR(30) NOT NULL DEFAULT \'MARKETING\',
        categoria_meta VARCHAR(30) NOT NULL DEFAULT \'MARKETING\',
        header_format VARCHAR(30) NOT NULL DEFAULT \'IMAGE\',
        param_cuerpo TEXT NULL,
        param_eslogan TEXT NULL,
        template_params_json TEXT NULL,
        header_imagen_path VARCHAR(255) NULL,
        header_media_id VARCHAR(120) NULL,
        costo_unitario_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0700,
        presupuesto_max_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        costo_estimado_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        costo_real_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        cuota_diaria_programada INT NOT NULL DEFAULT 250,
        total_tandas INT NOT NULL DEFAULT 1,
        hora_programada TIME NOT NULL DEFAULT \'09:00:00\',
        piloto_automatico TINYINT(1) NOT NULL DEFAULT 1,
        aprobado_por INT NULL,
        fecha_aprobacion DATETIME NULL,
        total_destinatarios INT NOT NULL DEFAULT 0,
        pendientes INT NOT NULL DEFAULT 0,
        enviados INT NOT NULL DEFAULT 0,
        entregados INT NOT NULL DEFAULT 0,
        leidos INT NOT NULL DEFAULT 0,
        respondidos INT NOT NULL DEFAULT 0,
        fallidos INT NOT NULL DEFAULT 0,
        estado VARCHAR(40) NOT NULL DEFAULT \'PENDIENTE_APROBACION\',
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_fin DATETIME NULL,
        INDEX idx_sede_id (id_sede, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $existing_cols = [];
    $res_cols = @$con->query('SHOW COLUMNS FROM campanas_difusion');
    if ($res_cols) {
        while ($rc = $res_cols->fetch_assoc()) {
            $existing_cols[$rc['Field']] = true;
        }
    }

    if (!isset($existing_cols['template_language'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN template_language VARCHAR(20) NOT NULL DEFAULT \'es\' AFTER template_name');
    }
    if (!isset($existing_cols['template_category'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN template_category VARCHAR(30) NOT NULL DEFAULT \'MARKETING\' AFTER template_language');
    }
    if (!isset($existing_cols['categoria_meta'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN categoria_meta VARCHAR(30) NOT NULL DEFAULT \'MARKETING\' AFTER template_category');
    }
    if (!isset($existing_cols['header_format'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN header_format VARCHAR(30) NOT NULL DEFAULT \'IMAGE\' AFTER template_category');
    }
    if (!isset($existing_cols['template_params_json'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN template_params_json TEXT NULL AFTER param_eslogan');
    }
    if (!isset($existing_cols['header_imagen_path'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN header_imagen_path VARCHAR(255) NULL AFTER param_eslogan');
    }
    if (!isset($existing_cols['header_media_id'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN header_media_id VARCHAR(120) NULL AFTER header_imagen_path');
    }
    if (!isset($existing_cols['costo_unitario_usd'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN costo_unitario_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0700 AFTER param_eslogan');
    }
    if (!isset($existing_cols['presupuesto_max_usd'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN presupuesto_max_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER costo_unitario_usd');
    }
    if (!isset($existing_cols['costo_estimado_usd'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN costo_estimado_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER presupuesto_max_usd');
    }
    if (!isset($existing_cols['costo_real_usd'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN costo_real_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER costo_estimado_usd');
    }
    if (!isset($existing_cols['cuota_diaria_programada'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN cuota_diaria_programada INT NOT NULL DEFAULT 250 AFTER costo_real_usd');
    }
    if (!isset($existing_cols['total_tandas'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN total_tandas INT NOT NULL DEFAULT 1 AFTER cuota_diaria_programada');
    }
    if (!isset($existing_cols['hora_programada'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN hora_programada TIME NOT NULL DEFAULT \'09:00:00\' AFTER total_tandas');
    }
    if (!isset($existing_cols['piloto_automatico'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN piloto_automatico TINYINT(1) NOT NULL DEFAULT 1 AFTER total_tandas');
    }
    if (!isset($existing_cols['aprobado_por'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN aprobado_por INT NULL AFTER piloto_automatico');
    }
    if (!isset($existing_cols['fecha_aprobacion'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN fecha_aprobacion DATETIME NULL AFTER aprobado_por');
    }
    if (!isset($existing_cols['pendientes'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN pendientes INT NOT NULL DEFAULT 0 AFTER total_destinatarios');
    }
    if (!isset($existing_cols['entregados'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN entregados INT NOT NULL DEFAULT 0 AFTER enviados');
    }
    if (!isset($existing_cols['leidos'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN leidos INT NOT NULL DEFAULT 0 AFTER entregados');
    }
    if (!isset($existing_cols['respondidos'])) {
        @$con->query('ALTER TABLE campanas_difusion ADD COLUMN respondidos INT NOT NULL DEFAULT 0 AFTER leidos');
    }

    @$con->query('CREATE TABLE IF NOT EXISTS campanas_difusion_destinatarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_campana INT NOT NULL,
        id_sede INT NOT NULL DEFAULT 0,
        id_cliente INT NOT NULL DEFAULT 0,
        id_conversacion INT NOT NULL DEFAULT 0,
        nombre VARCHAR(150) NOT NULL DEFAULT \'Cliente\',
        telefono VARCHAR(40) NOT NULL,
        numero_tanda INT NOT NULL DEFAULT 1,
        fecha_programada DATE NULL,
        id_mensaje_meta VARCHAR(150) NULL,
        estado_envio VARCHAR(30) NOT NULL DEFAULT \'PENDIENTE\',
        respondido TINYINT(1) NOT NULL DEFAULT 0,
        respuesta_texto VARCHAR(255) NULL,
        costo_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        error_detalle TEXT NULL,
        fecha_envio DATETIME NULL,
        fecha_entrega DATETIME NULL,
        fecha_lectura DATETIME NULL,
        fecha_respuesta DATETIME NULL,
        INDEX idx_campana (id_campana),
        INDEX idx_campana_estado (id_campana, estado_envio),
        INDEX idx_sede_fecha (id_sede, fecha_envio),
        INDEX idx_meta_msg (id_mensaje_meta),
        INDEX idx_telefono (telefono),
        INDEX idx_conversacion (id_conversacion)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    $existing_dest_cols = [];
    $res_dcols = @$con->query('SHOW COLUMNS FROM campanas_difusion_destinatarios');
    if ($res_dcols) {
        while ($rdc = $res_dcols->fetch_assoc()) {
            $existing_dest_cols[$rdc['Field']] = true;
        }
    }
    if (!isset($existing_dest_cols['numero_tanda'])) {
        @$con->query('ALTER TABLE campanas_difusion_destinatarios ADD COLUMN numero_tanda INT NOT NULL DEFAULT 1 AFTER telefono');
    }
    if (!isset($existing_dest_cols['fecha_programada'])) {
        @$con->query('ALTER TABLE campanas_difusion_destinatarios ADD COLUMN fecha_programada DATE NULL AFTER numero_tanda');
    }

    $rIdx1 = @$con->query('SHOW INDEX FROM `campanas_difusion_destinatarios` WHERE Key_name = \'idx_campana_estado\'');
    if ($rIdx1 && $rIdx1->num_rows === 0) {
        @$con->query('ALTER TABLE `campanas_difusion_destinatarios` ADD INDEX `idx_campana_estado` (id_campana, estado_envio)');
    }
    $rIdx2 = @$con->query('SHOW INDEX FROM `campanas_difusion_destinatarios` WHERE Key_name = \'idx_conversacion\'');
    if ($rIdx2 && $rIdx2->num_rows === 0) {
        @$con->query('ALTER TABLE `campanas_difusion_destinatarios` ADD INDEX `idx_conversacion` (id_conversacion)');
    }
    $rIdx3 = @$con->query('SHOW INDEX FROM `mensajes_y_eventos` WHERE Key_name = \'idx_meta_wamid\'');
    if ($rIdx3 && $rIdx3->num_rows === 0) {
        @$con->query('ALTER TABLE `mensajes_y_eventos` ADD INDEX `idx_meta_wamid` (id_mensaje_meta(100))');
    }
    $rIdx4 = @$con->query('SHOW INDEX FROM `mensajes_y_eventos` WHERE Key_name = \'idx_conv_origen_ts\'');
    if ($rIdx4 && $rIdx4->num_rows === 0) {
        @$con->query('ALTER TABLE `mensajes_y_eventos` ADD INDEX `idx_conv_origen_ts` (id_conversacion, origen, timestamp)');
    }
    $rIdx5 = @$con->query('SHOW INDEX FROM `conversaciones` WHERE Key_name = \'idx_cliente_linea\'');
    if ($rIdx5 && $rIdx5->num_rows === 0) {
        @$con->query('ALTER TABLE `conversaciones` ADD INDEX `idx_cliente_linea` (id_cliente, id_linea, fecha_inicio)');
    }

    @$con->query('CREATE TABLE IF NOT EXISTS campanas_cuotas_sede (
        id_sede INT PRIMARY KEY,
        limite_diario_mensajes INT NOT NULL DEFAULT 250,
        cadencia_ms INT NOT NULL DEFAULT 1000,
        costo_marketing_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0700,
        costo_utility_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0150,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

    @file_put_contents($lock_file, (string)time());
}

$action_init = $_POST['action'] ?? $_GET['action'] ?? '';
if (!defined('CAMPANAS_LIB_ONLY') && $action_init !== 'dispatch_queue_item' && $action_init !== 'check_autopilot_due') {
    ensureCampaignTables($con);
}

function normalizeVenezuelanPhone($rawPhone) {
    $tel = preg_replace('/[^0-9]/', '', (string)$rawPhone);
    if (strlen($tel) === 11 && strpos($tel, '0') === 0) {
        $tel = '58' . substr($tel, 1);
    } elseif (strlen($tel) === 13 && strpos($tel, '580') === 0) {
        $tel = '58' . substr($tel, 3);
    } elseif (strlen($tel) === 10 && preg_match('/^(414|424|412|416|426|2[0-9]{2})[0-9]{7}$/', $tel)) {
        $tel = '58' . $tel;
    }
    return $tel;
}

if (!function_exists('starfi_marketing_category_sql_expr')) {
    function starfi_marketing_category_sql_expr(mysqli $con): string {
        static $cache = [];
        $cid = spl_object_id($con);
        if (isset($cache[$cid])) {
            return $cache[$cid];
        }
        $hasCatMeta = false;
        $hasTplCat = false;
        $resCols = @$con->query('SHOW COLUMNS FROM `campanas_difusion`');
        if ($resCols) {
            while ($col = $resCols->fetch_assoc()) {
                $field = (string)($col['Field'] ?? '');
                if ($field === 'categoria_meta') {
                    $hasCatMeta = true;
                } elseif ($field === 'template_category') {
                    $hasTplCat = true;
                }
            }
        }
        if ($hasCatMeta && $hasTplCat) {
            $expr = 'UPPER(COALESCE(NULLIF(c.categoria_meta, \'\'), NULLIF(c.template_category, \'\'), \'MARKETING\')) = \'MARKETING\'';
        } elseif ($hasCatMeta) {
            $expr = 'UPPER(IFNULL(NULLIF(c.categoria_meta, \'\'), \'MARKETING\')) = \'MARKETING\'';
        } elseif ($hasTplCat) {
            $expr = 'UPPER(IFNULL(NULLIF(c.template_category, \'\'), \'MARKETING\')) = \'MARKETING\'';
        } else {
            $expr = '1 = 1';
        }
        $cache[$cid] = $expr;
        return $expr;
    }
}

if (!function_exists('starfi_has_categoria_meta_col')) {
    function starfi_has_categoria_meta_col(mysqli $con): bool {
        static $cache = [];
        $cid = spl_object_id($con);
        if (isset($cache[$cid])) {
            return $cache[$cid];
        }
        $r = @$con->query('SHOW COLUMNS FROM `campanas_difusion` LIKE \'categoria_meta\'');
        $cache[$cid] = ($r && $r->num_rows > 0);
        return $cache[$cid];
    }
}

if (!function_exists('starfi_check_marketing_antifatigue_24h')) {
    /**
     * Escudo Anti-Fatiga 24h en Backend (QA-BUG-CRM2-08 / CHK-CLD-02 S1):
     * Verifica en todas las campañas MARKETING si el número (o variantes con/sin + o formato local)
     * tuvo un envío en las últimas 24 horas en estado PROCESANDO/ENVIADO/ENTREGADO/LEIDO con respondido = 0.
     */
    function starfi_check_marketing_antifatigue_24h(mysqli $con, string $telefono, int $excludeDestId = 0): bool {
        $norm = normalizeVenezuelanPhone($telefono);
        if ($norm === '') {
            return false;
        }
        $v1 = $norm;
        $v2 = '+' . $norm;
        $v3 = (strlen($norm) === 12 && strpos($norm, '58') === 0) ? ('0' . substr($norm, 2)) : $norm;
        $rawClean = preg_replace('/[^0-9+]/', '', trim($telefono));
        $v4 = ($rawClean !== '') ? $rawClean : $norm;
        $excl = max(0, intval($excludeDestId));
        $catExpr = starfi_marketing_category_sql_expr($con);

        $sql = 'SELECT d.id
                FROM campanas_difusion_destinatarios d
                INNER JOIN campanas_difusion c ON c.id = d.id_campana
                WHERE ' . $catExpr . '
                  AND d.telefono IN (?, ?, ?, ?)
                  AND d.fecha_envio >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                  AND d.estado_envio IN (\'PROCESANDO\', \'ENVIADO\', \'ENTREGADO\', \'LEIDO\')
                  AND IFNULL(d.respondido, 0) = 0
                  AND d.id != ?
                LIMIT 1';

        $stmt = $con->prepare($sql);
        if (!$stmt) {
            starfi_log_exception('campanas', 'Error preparando consulta starfi_check_marketing_antifatigue_24h', ['error' => $con->error]);
            return false;
        }
        $stmt->bind_param('ssssi', $v1, $v2, $v3, $v4, $excl);
        $fatigued = false;
        if (@$stmt->execute()) {
            $stmt->store_result();
            $fatigued = ($stmt->num_rows > 0);
        }
        $stmt->close();
        return $fatigued;
    }
}

/**
 * Actualización rápida de contadores de campaña en 1 sola pasada agregada (100% mysqli_prepare).
 */
function actualizarContadoresRapidosCampana(mysqli $con, $id_campana) {
    $id_campana = intval($id_campana);
    if ($id_campana <= 0) {
        return;
    }
    $stmt = $con->prepare(
        'UPDATE campanas_difusion c
         INNER JOIN (
             SELECT id_campana,
                    COALESCE(SUM(estado_envio = \'PENDIENTE\'), 0) AS pend,
                    COALESCE(SUM(estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')), 0) AS env,
                    COALESCE(SUM(estado_envio = \'FALLIDO\'), 0) AS fall,
                    IFNULL(SUM(CASE WHEN estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') THEN costo_usd ELSE 0 END), 0) AS costo_real
             FROM campanas_difusion_destinatarios
             WHERE id_campana = ?
             GROUP BY id_campana
         ) agg ON agg.id_campana = c.id
         SET c.pendientes = agg.pend,
             c.enviados = agg.env,
             c.fallidos = agg.fall,
             c.costo_real_usd = agg.costo_real
         WHERE c.id = ?'
    );
    if ($stmt) {
        $stmt->bind_param('ii', $id_campana, $id_campana);
        if (!@$stmt->execute()) {
            starfi_log_exception('campanas', 'Error en actualizarContadoresRapidosCampana', ['id_campana' => $id_campana, 'error' => $stmt->error]);
        }
        $stmt->close();
    }
}

function recalcularMetricasCampana(mysqli $con, $id_campana) {
    $id_campana = intval($id_campana);
    if ($id_campana <= 0) {
        return;
    }

    // 1. Sincronizar estados reales desde mensajes_y_eventos
    $stmt1 = $con->prepare(
        'UPDATE campanas_difusion_destinatarios d
         INNER JOIN campanas_difusion c ON c.id = d.id_campana
         INNER JOIN mensajes_y_eventos m ON m.id_mensaje_meta = CONVERT(d.id_mensaje_meta USING utf8mb4)
         SET d.estado_envio = CASE
                 WHEN m.estado_envio = \'FALLIDO\' THEN \'FALLIDO\'
                 WHEN d.respondido = 1 THEN \'RESPONDIDO\'
                 WHEN m.estado_envio = \'LEIDO\' THEN \'LEIDO\'
                 WHEN m.estado_envio = \'ENTREGADO\' AND d.estado_envio NOT IN (\'LEIDO\', \'RESPONDIDO\') THEN \'ENTREGADO\'
                 ELSE d.estado_envio
             END,
             d.fecha_entrega = CASE
                 WHEN m.estado_envio IN (\'ENTREGADO\', \'LEIDO\') THEN IFNULL(d.fecha_entrega, IFNULL(m.timestamp, NOW()))
                 ELSE d.fecha_entrega
             END,
             d.fecha_lectura = CASE
                 WHEN m.estado_envio = \'LEIDO\' THEN IFNULL(d.fecha_lectura, IFNULL(m.timestamp, NOW()))
                 ELSE d.fecha_lectura
             END,
             d.error_detalle = CASE
                 WHEN m.estado_envio = \'FALLIDO\' AND m.error_detalle IS NOT NULL AND m.error_detalle != \'\' THEN m.error_detalle
                 ELSE d.error_detalle
             END,
             d.costo_usd = CASE
                 WHEN m.estado_envio = \'FALLIDO\' THEN 0.0000
                 WHEN d.costo_usd <= 0 AND c.costo_unitario_usd > 0 THEN c.costo_unitario_usd
                 ELSE d.costo_usd
             END
         WHERE d.id_campana = ?
           AND d.id_mensaje_meta IS NOT NULL
           AND d.id_mensaje_meta != \'\'
           AND d.estado_envio IN (\'ENVIADO\', \'ENTREGADO\', \'LEIDO\')'
    );
    if ($stmt1) {
        $stmt1->bind_param('i', $id_campana);
        @$stmt1->execute();
        $stmt1->close();
    }

    // 2a. Asegurar id_conversacion
    $stmt2a = $con->prepare(
        'UPDATE campanas_difusion_destinatarios d
         INNER JOIN conversaciones conv ON conv.id_cliente = d.id_cliente
         SET d.id_conversacion = conv.id
         WHERE d.id_campana = ?
           AND d.id_conversacion = 0
           AND d.id_cliente > 0
           AND d.estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\')'
    );
    if ($stmt2a) {
        $stmt2a->bind_param('i', $id_campana);
        @$stmt2a->execute();
        $stmt2a->close();
    }

    // 2b. Cruzar mensajes entrantes del cliente posteriores a fecha_envio
    $stmt2b = $con->prepare(
        'UPDATE campanas_difusion_destinatarios d
         INNER JOIN mensajes_y_eventos m
            ON m.id_conversacion = d.id_conversacion
           AND m.origen = \'CLIENTE\'
           AND m.timestamp >= d.fecha_envio
         SET d.respondido = 1,
             d.estado_envio = \'RESPONDIDO\',
             d.respuesta_texto = LEFT(IFNULL(m.contenido, \'\'), 250),
             d.fecha_entrega = IFNULL(d.fecha_entrega, m.timestamp),
             d.fecha_lectura = IFNULL(d.fecha_lectura, m.timestamp),
             d.fecha_respuesta = IFNULL(d.fecha_respuesta, m.timestamp)
         WHERE d.id_campana = ?
           AND d.respondido = 0
           AND d.id_conversacion > 0
           AND d.estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\')
           AND d.fecha_envio IS NOT NULL'
    );
    if ($stmt2b) {
        $stmt2b->bind_param('i', $id_campana);
        @$stmt2b->execute();
        $stmt2b->close();
    }

    // 3. Recalcular embudo consolidado en campanas_difusion
    $stmt3 = $con->prepare(
        'UPDATE campanas_difusion c
         INNER JOIN (
             SELECT id_campana,
                    COUNT(*) AS tot,
                    COALESCE(SUM(estado_envio = \'PENDIENTE\'), 0) AS pend,
                    COALESCE(SUM(estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')), 0) AS env,
                    COALESCE(SUM(fecha_entrega IS NOT NULL OR estado_envio IN (\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')), 0) AS ent,
                    COALESCE(SUM(fecha_lectura IS NOT NULL OR estado_envio IN (\'LEIDO\',\'RESPONDIDO\')), 0) AS lei,
                    COALESCE(SUM(respondido = 1), 0) AS resp,
                    COALESCE(SUM(estado_envio = \'FALLIDO\'), 0) AS fall,
                    IFNULL(SUM(CASE WHEN estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') THEN costo_usd ELSE 0 END), 0) AS costo_real
             FROM campanas_difusion_destinatarios
             WHERE id_campana = ?
             GROUP BY id_campana
         ) agg ON agg.id_campana = c.id
         SET c.total_destinatarios = agg.tot,
             c.pendientes = agg.pend,
             c.enviados = agg.env,
             c.entregados = agg.ent,
             c.leidos = agg.lei,
             c.respondidos = agg.resp,
             c.fallidos = agg.fall,
             c.costo_real_usd = agg.costo_real
         WHERE c.id = ?'
    );
    if ($stmt3) {
        $stmt3->bind_param('ii', $id_campana, $id_campana);
        if (!@$stmt3->execute()) {
            starfi_log_exception('campanas', 'Error recalculando métricas consolidadas', ['id_campana' => $id_campana, 'error' => $stmt3->error]);
        }
        $stmt3->close();
    }
}

function sanitizeMetaTemplateParam($text, $stripTrailingDot = false) {
    $clean = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$text);
    $clean = preg_replace('/\s{2,}/', ' ', $clean);
    $clean = trim($clean);
    if ($stripTrailingDot) {
        $clean = rtrim($clean, '.');
        $clean = trim($clean);
    }
    return $clean !== '' ? $clean : 'N/A';
}

function stripLeadingAppSubpath($path) {
    $norm = ltrim(str_replace('\\', '/', trim((string)$path)), '/');
    if (strpos($norm, 'starfi_crm/') === 0) {
        $norm = substr($norm, strlen('starfi_crm/'));
    }
    return ltrim($norm, '/');
}

/**
 * Resuelve la ruta absoluta del archivo de imagen a enviar en el HEADER de una campaña:
 * 1) Si la campaña tiene una imagen personalizada subida (header_imagen_path), usa esa.
 * 2) Si no, consulta el logo oficial configurado en empresa_perfil con sentencia preparada.
 */
function resolveCampaignHeaderImagePath(mysqli $con, array $camp): ?string {
    $baseDir = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
    if (!empty($camp['header_imagen_path'])) {
        $rel = stripLeadingAppSubpath($camp['header_imagen_path']);
        if ($rel !== '') {
            $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (file_exists($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }
    }

    // Buscar logo configurado en empresa_perfil sin rutas fijas quemadas
    $stmtEmp = $con->prepare('SELECT logo_url FROM empresa_perfil WHERE logo_url IS NOT NULL AND logo_url != \'\' ORDER BY id ASC LIMIT 1');
    if ($stmtEmp) {
        if (@$stmtEmp->execute()) {
            $resEmp = $stmtEmp->get_result();
            if ($resEmp && ($rowEmp = $resEmp->fetch_assoc())) {
                $logoRel = stripLeadingAppSubpath($rowEmp['logo_url'] ?? '');
                if ($logoRel !== '') {
                    $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $logoRel);
                    if (file_exists($candidate) && is_file($candidate)) {
                        $stmtEmp->close();
                        return $candidate;
                    }
                }
            }
        }
        $stmtEmp->close();
    }

    return null;
}

/**
 * Obtiene o sube a Meta Cloud API el Media ID de la imagen de cabecera (usando finfo y CURLOPT_TIMEOUT <= 8s).
 */
function getOrUploadCampaignHeaderMediaId(mysqli $con, array &$camp, string $phone_id, string $token): ?string {
    $existing_id = trim((string)($camp['header_media_id'] ?? ''));
    if ($existing_id !== '') {
        return $existing_id;
    }

    $img_path = resolveCampaignHeaderImagePath($con, $camp);
    if (!$img_path || !file_exists($img_path) || !is_file($img_path)) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = strtolower(trim((string)$finfo->file($img_path)));
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return null;
    }
    $filename = basename($img_path);
    $apiVer = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';

    $media_url = "https://graph.facebook.com/{$apiVer}/{$phone_id}/media";
    $cfile = new CURLFile($img_path, $mime, $filename);
    $post_media = [
        'messaging_product' => 'whatsapp',
        'type' => $mime,
        'file' => $cfile
    ];

    $ch = curl_init($media_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_media);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if (!empty($data['id'])) {
        $media_id = trim((string)$data['id']);
        $camp['header_media_id'] = $media_id;
        $id_camp = intval($camp['id'] ?? 0);
        if ($id_camp > 0) {
            $stmtUpd = $con->prepare('UPDATE campanas_difusion SET header_media_id = ? WHERE id = ?');
            if ($stmtUpd) {
                $stmtUpd->bind_param('si', $media_id, $id_camp);
                @$stmtUpd->execute();
                $stmtUpd->close();
            }
        }
        return $media_id;
    }
    return null;
}

function calcularPrecioFinalUnitarioSede(float $costoBaseMeta, string $tipoTarifa = 'PORCENTAJE', float $valorTarifa = 10.00): float {
    $tipo = strtoupper(trim($tipoTarifa ?: 'PORCENTAJE'));
    if ($tipo === 'FIJA') {
        return round($costoBaseMeta + max(0, $valorTarifa), 4);
    }
    return round($costoBaseMeta * (1 + (max(0, $valorTarifa) / 100)), 4);
}

/**
 * Helper para consultar una campaña completa por ID con mysqli_prepare.
 */
function obtenerCampanaPorId(mysqli $con, int $id_campana): ?array {
    if ($id_campana <= 0) {
        return null;
    }
    $row = null;
    $stmt = $con->prepare('SELECT * FROM campanas_difusion WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $id_campana);
        if (@$stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
            }
        }
        $stmt->close();
    }
    return $row;
}

if (defined('CAMPANAS_LIB_ONLY')) {
    return;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$apiVerGlobal = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';

switch ($action) {

    // =========================================================================
    // 1. OBTENER ECOSISTEMA COMPLETO (SEDES, LÍNEAS, CUOTA DIARIA, CONTACTOS, CAMPAÑAS, KPIs)
    // =========================================================================
    case 'get_ecosystem_data':
        $filtro_sede = intval($_GET['id_sede'] ?? $_POST['id_sede'] ?? $_REQUEST['id_sede'] ?? 0);
        if (!$es_global && $id_sede_usuario > 0) {
            $filtro_sede = $id_sede_usuario;
        }

        $inicio_hoy = date('Y-m-d 00:00:00');
        $fin_hoy = date('Y-m-d 23:59:59');

        if (!$es_global && $id_sede_usuario > 0) {
            $sql_sedes = 'SELECT s.id, s.nombre_sede, s.ciudad, s.bot_activo, s.estado AS estado_sede,
                                 l.id AS id_linea, l.descripcion AS descripcion_linea, l.numero_telefono, l.meta_app_id, l.id_negocio, l.meta_token, l.estado AS estado_linea, l.limite_solicitudes,
                                 IFNULL(q.limite_diario_mensajes, IFNULL(l.limite_solicitudes, 250)) AS limite_diario_mensajes,
                                 IFNULL(q.cadencia_ms, 1000) AS cadencia_ms,
                                 IFNULL(q.costo_marketing_usd, 0.0700) AS costo_marketing_base_usd,
                                 IFNULL(q.costo_utility_usd, 0.0150) AS costo_utility_base_usd,
                                 IFNULL(wt.tipo_tarifa, \'PORCENTAJE\') AS waba_tipo_tarifa,
                                 IFNULL(wt.valor, 10.00) AS waba_valor_tarifa,
                                 IFNULL(dh.enviados_hoy, 0) AS enviados_hoy,
                                 IFNULL(dh.gasto_hoy_usd, 0) AS gasto_hoy_usd
                          FROM sedes s
                          LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id AND l.estado = \'ACTIVO\'
                          LEFT JOIN campanas_cuotas_sede q ON q.id_sede = s.id
                          LEFT JOIN waba_tarifas_sede wt ON wt.id_sede = s.id
                          LEFT JOIN (
                              SELECT id_sede,
                                     COUNT(*) AS enviados_hoy,
                                     IFNULL(SUM(costo_usd), 0) AS gasto_hoy_usd
                              FROM campanas_difusion_destinatarios
                              WHERE fecha_envio >= ? AND fecha_envio <= ?
                                AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')
                              GROUP BY id_sede
                          ) dh ON dh.id_sede = s.id
                          WHERE s.id = ?
                          ORDER BY (l.id IS NULL) ASC, s.nombre_sede ASC';
            $stmtSedes = $con->prepare($sql_sedes);
            if ($stmtSedes) {
                $stmtSedes->bind_param('ssi', $inicio_hoy, $fin_hoy, $id_sede_usuario);
            }
        } else {
            $sql_sedes = 'SELECT s.id, s.nombre_sede, s.ciudad, s.bot_activo, s.estado AS estado_sede,
                                 l.id AS id_linea, l.descripcion AS descripcion_linea, l.numero_telefono, l.meta_app_id, l.id_negocio, l.meta_token, l.estado AS estado_linea, l.limite_solicitudes,
                                 IFNULL(q.limite_diario_mensajes, IFNULL(l.limite_solicitudes, 250)) AS limite_diario_mensajes,
                                 IFNULL(q.cadencia_ms, 1000) AS cadencia_ms,
                                 IFNULL(q.costo_marketing_usd, 0.0700) AS costo_marketing_base_usd,
                                 IFNULL(q.costo_utility_usd, 0.0150) AS costo_utility_base_usd,
                                 IFNULL(wt.tipo_tarifa, \'PORCENTAJE\') AS waba_tipo_tarifa,
                                 IFNULL(wt.valor, 10.00) AS waba_valor_tarifa,
                                 IFNULL(dh.enviados_hoy, 0) AS enviados_hoy,
                                 IFNULL(dh.gasto_hoy_usd, 0) AS gasto_hoy_usd
                          FROM sedes s
                          LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id AND l.estado = \'ACTIVO\'
                          LEFT JOIN campanas_cuotas_sede q ON q.id_sede = s.id
                          LEFT JOIN waba_tarifas_sede wt ON wt.id_sede = s.id
                          LEFT JOIN (
                              SELECT id_sede,
                                     COUNT(*) AS enviados_hoy,
                                     IFNULL(SUM(costo_usd), 0) AS gasto_hoy_usd
                              FROM campanas_difusion_destinatarios
                              WHERE fecha_envio >= ? AND fecha_envio <= ?
                                AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')
                              GROUP BY id_sede
                          ) dh ON dh.id_sede = s.id
                          ORDER BY (l.id IS NULL) ASC, s.nombre_sede ASC';
            $stmtSedes = $con->prepare($sql_sedes);
            if ($stmtSedes) {
                $stmtSedes->bind_param('ss', $inicio_hoy, $fin_hoy);
            }
        }

        $sedes = [];
        if ($stmtSedes) {
            if (@$stmtSedes->execute()) {
                $res_sedes = $stmtSedes->get_result();
                if ($res_sedes) {
                    while ($row = $res_sedes->fetch_assoc()) {
                        $lim = max(1, intval($row['limite_diario_mensajes']));
                        $hoy = intval($row['enviados_hoy']);
                        $disp = max(0, $lim - $hoy);
                        $pct = min(100, round(($hoy / $lim) * 100, 1));
                        $token_efectivo = !empty($row['meta_token']) ? $row['meta_token'] : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

                        $base_mkt = floatval($row['costo_marketing_base_usd']);
                        $base_util = floatval($row['costo_utility_base_usd']);
                        $waba_tipo = $row['waba_tipo_tarifa'] ?: 'PORCENTAJE';
                        $waba_val = floatval($row['waba_valor_tarifa'] ?? 10.00);

                        $precio_final_mkt = calcularPrecioFinalUnitarioSede($base_mkt, $waba_tipo, $waba_val);
                        $precio_final_util = calcularPrecioFinalUnitarioSede($base_util, $waba_tipo, $waba_val);

                        $sede_item = [
                            'id' => intval($row['id']),
                            'nombre_sede' => $row['nombre_sede'],
                            'ciudad' => $row['ciudad'] ?? '',
                            'bot_activo' => intval($row['bot_activo'] ?? 0) === 1,
                            'id_linea' => intval($row['id_linea'] ?? 0),
                            'descripcion_linea' => $row['descripcion_linea'] ?? '',
                            'numero_telefono' => !empty($row['numero_telefono']) ? $row['numero_telefono'] : 'Sin número asignado',
                            'tiene_linea_activa' => (!empty($row['id_linea']) && !empty($row['meta_app_id']) && !empty($token_efectivo)),
                            'tiene_waba' => !empty($row['id_negocio']),
                            'limite_diario_mensajes' => $lim,
                            'cadencia_ms' => intval($row['cadencia_ms']),
                            'costo_marketing_usd' => $precio_final_mkt,
                            'costo_utility_usd' => $precio_final_util,
                            'enviados_hoy' => $hoy,
                            'disponibles_hoy' => $disp,
                            'porcentaje_cuota_hoy' => $pct,
                            'gasto_hoy_usd' => round(floatval($row['gasto_hoy_usd']), 4)
                        ];
                        if ($es_global) {
                            $sede_item['costo_marketing_base_usd'] = $base_mkt;
                            $sede_item['costo_utility_base_usd'] = $base_util;
                        }
                        $sedes[] = $sede_item;
                    }
                }
            }
            $stmtSedes->close();
        }

        if (!$es_global && $id_sede_usuario > 0) {
            $filtro_sede = $id_sede_usuario;
        }

        $contactos = [];
        $etiquetas = [];
        $campanas = [];
        $kpis = [
            'total_campanas' => 0,
            'total_destinatarios' => 0,
            'total_pendientes' => 0,
            'total_enviados' => 0,
            'total_entregados' => 0,
            'total_leidos' => 0,
            'total_respondidos' => 0,
            'total_fallidos' => 0,
            'costo_total_usd' => 0.0,
            'tasa_entrega_pct' => 0.0,
            'tasa_lectura_pct' => 0.0,
            'tasa_respuesta_pct' => 0.0
        ];

        if ($filtro_sede > 0) {
            // Mapa de teléfonos que recibieron campaña MARKETING en las últimas 24h sin responder (Error 131049)
            $fatiga_24h_map = [];
            $catExprFat = starfi_marketing_category_sql_expr($con);
            $stmtFat = $con->prepare(
                'SELECT DISTINCT d.telefono
                 FROM campanas_difusion_destinatarios d
                 INNER JOIN campanas_difusion c ON c.id = d.id_campana
                 WHERE ' . $catExprFat . '
                   AND d.estado_envio IN (\'PROCESANDO\',\'ENVIADO\',\'ENTREGADO\',\'LEIDO\')
                   AND IFNULL(d.respondido, 0) = 0
                   AND d.fecha_envio >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
            );
            if ($stmtFat) {
                if (@$stmtFat->execute()) {
                    $resFat = $stmtFat->get_result();
                    if ($resFat) {
                        while ($rf = $resFat->fetch_assoc()) {
                            $t_norm = normalizeVenezuelanPhone($rf['telefono'] ?? '');
                            if ($t_norm !== '') {
                                $fatiga_24h_map[$t_norm] = true;
                            }
                        }
                    }
                }
                $stmtFat->close();
            }

            if (!$es_global && $id_sede_usuario > 0) {
                $sql_clientes = 'SELECT c.id, c.id_sede, c.numero_whatsapp AS telefono, c.nombre, c.etiquetas, c.notas_internas, c.fecha_registro, c.ultimo_contacto,
                                        conv_agg.sedes_ids,
                                        conv_agg.ultima_conv
                                 FROM clientes_contactos c
                                 LEFT JOIN (
                                     SELECT conv.id_cliente,
                                            GROUP_CONCAT(DISTINCT lw.id_sede SEPARATOR \',\') AS sedes_ids,
                                            MAX(conv.fecha_inicio) AS ultima_conv
                                     FROM conversaciones conv
                                     INNER JOIN lineas_whatsapp lw ON lw.id = conv.id_linea
                                     GROUP BY conv.id_cliente
                                 ) conv_agg ON conv_agg.id_cliente = c.id
                                 WHERE (c.id_sede = ? OR EXISTS (
                                     SELECT 1 FROM conversaciones conv2
                                     INNER JOIN lineas_whatsapp lw2 ON lw2.id = conv2.id_linea
                                     WHERE conv2.id_cliente = c.id AND lw2.id_sede = ?
                                 ))
                                 ORDER BY c.id DESC';
                $stmtCli = $con->prepare($sql_clientes);
                if ($stmtCli) {
                    $stmtCli->bind_param('ii', $id_sede_usuario, $id_sede_usuario);
                }
            } else {
                $sql_clientes = 'SELECT c.id, c.id_sede, c.numero_whatsapp AS telefono, c.nombre, c.etiquetas, c.notas_internas, c.fecha_registro, c.ultimo_contacto,
                                        conv_agg.sedes_ids,
                                        conv_agg.ultima_conv
                                 FROM clientes_contactos c
                                 LEFT JOIN (
                                     SELECT conv.id_cliente,
                                            GROUP_CONCAT(DISTINCT lw.id_sede SEPARATOR \',\') AS sedes_ids,
                                            MAX(conv.fecha_inicio) AS ultima_conv
                                     FROM conversaciones conv
                                     INNER JOIN lineas_whatsapp lw ON lw.id = conv.id_linea
                                     GROUP BY conv.id_cliente
                                 ) conv_agg ON conv_agg.id_cliente = c.id
                                 ORDER BY c.id DESC';
                $stmtCli = $con->prepare($sql_clientes);
            }

            $etiquetas_map = [];
            if ($stmtCli) {
                if (@$stmtCli->execute()) {
                    $res_cli = $stmtCli->get_result();
                    if ($res_cli) {
                        while ($rc = $res_cli->fetch_assoc()) {
                            $tags_arr = [];
                            $raw_tags = trim((string)($rc['etiquetas'] ?? ''));
                            if ($raw_tags !== '') {
                                $decoded = json_decode($raw_tags, true);
                                if (is_array($decoded)) {
                                    foreach ($decoded as $t_val) {
                                        $t_clean = trim((string)$t_val);
                                        if ($t_clean !== '') {
                                            $tags_arr[] = $t_clean;
                                            $etiquetas_map[$t_clean] = true;
                                        }
                                    }
                                } else {
                                    foreach (explode(',', $raw_tags) as $t_val) {
                                        $t_clean = trim($t_val, " \t\n\r\0\x0B\"'[]");
                                        if ($t_clean !== '') {
                                            $tags_arr[] = $t_clean;
                                            $etiquetas_map[$t_clean] = true;
                                        }
                                    }
                                }
                            }

                            $sedes_arr = !empty($rc['sedes_ids']) ? array_map('intval', explode(',', (string)$rc['sedes_ids'])) : [];
                            $id_sede_primaria = !empty($rc['id_sede']) ? intval($rc['id_sede']) : 0;
                            if ($id_sede_primaria > 0) {
                                $sedes_arr[] = $id_sede_primaria;
                            }
                            $sedes_arr = array_values(array_unique(array_filter($sedes_arr)));
                            if ($id_sede_primaria <= 0 && !empty($sedes_arr)) {
                                $id_sede_primaria = intval($sedes_arr[0]);
                            }
                            $tel_norm_cli = normalizeVenezuelanPhone($rc['telefono'] ?? '');

                            $contactos[] = [
                                'id' => intval($rc['id']),
                                'id_sede_primaria' => $id_sede_primaria,
                                'nombre' => !empty($rc['nombre']) ? $rc['nombre'] : 'Cliente',
                                'telefono' => $rc['telefono'] ?? '',
                                'etiquetas' => array_values(array_unique($tags_arr)),
                                'sedes_ids' => $sedes_arr,
                                'ultima_interaccion' => !empty($rc['ultimo_contacto']) ? $rc['ultimo_contacto'] : ($rc['ultima_conv'] ?? null),
                                'fatiga_24h' => isset($fatiga_24h_map[$tel_norm_cli])
                            ];
                        }
                    }
                }
                $stmtCli->close();
            }
            $etiquetas = array_keys($etiquetas_map);
            sort($etiquetas, SORT_NATURAL | SORT_FLAG_CASE);

            // Recalcular en tiempo real las últimas 5 campañas recientes de esta sede
            $ids_recalc = [];
            $stmtSync = $con->prepare(
                'SELECT c.id FROM campanas_difusion c
                 WHERE c.id_sede = ?
                   AND (c.estado IN (\'APROBADA_EN_PROCESO\', \'PILOTO_AUTOMATICO\', \'EN_COLA_DIARIA\')
                        OR c.fecha_creacion >= DATE_SUB(NOW(), INTERVAL 7 DAY))
                 ORDER BY c.id DESC LIMIT 5'
            );
            if ($stmtSync) {
                $stmtSync->bind_param('i', $filtro_sede);
                if (@$stmtSync->execute()) {
                    $resSync = $stmtSync->get_result();
                    if ($resSync) {
                        while ($r_s = $resSync->fetch_assoc()) {
                            $ids_recalc[] = intval($r_s['id']);
                        }
                    }
                }
                $stmtSync->close();
            }
            foreach ($ids_recalc as $id_rec) {
                recalcularMetricasCampana($con, $id_rec);
            }

            $stmtCamp = $con->prepare(
                'SELECT c.*, s.nombre_sede, l.numero_telefono AS linea_telefono
                 FROM campanas_difusion c
                 LEFT JOIN sedes s ON s.id = c.id_sede
                 LEFT JOIN lineas_whatsapp l ON l.id_sede = c.id_sede AND l.estado = \'ACTIVO\'
                 WHERE c.id_sede = ?
                 ORDER BY c.id DESC
                 LIMIT 50'
            );
            if ($stmtCamp) {
                $stmtCamp->bind_param('i', $filtro_sede);
                if (@$stmtCamp->execute()) {
                    $res_camp = $stmtCamp->get_result();
                    if ($res_camp) {
                        while ($row_c = $res_camp->fetch_assoc()) {
                            $env = intval($row_c['enviados']);
                            $ent = intval($row_c['entregados']);
                            $lei = intval($row_c['leidos']);
                            $res = intval($row_c['respondidos']);
                            $fal = intval($row_c['fallidos']);
                            $pen = intval($row_c['pendientes']);
                            $tot = intval($row_c['total_destinatarios']);
                            $costo_real = floatval($row_c['costo_real_usd']);

                            $row_c['tasa_entrega'] = $env > 0 ? round(($ent / $env) * 100, 1) : 0;
                            $row_c['tasa_lectura'] = $env > 0 ? round(($lei / $env) * 100, 1) : 0;
                            $row_c['tasa_respuesta'] = $env > 0 ? round(($res / $env) * 100, 1) : 0;

                            $campanas[] = $row_c;

                            $kpis['total_campanas']++;
                            $kpis['total_destinatarios'] += $tot;
                            $kpis['total_pendientes'] += $pen;
                            $kpis['total_enviados'] += $env;
                            $kpis['total_entregados'] += $ent;
                            $kpis['total_leidos'] += $lei;
                            $kpis['total_respondidos'] += $res;
                            $kpis['total_fallidos'] += $fal;
                            $kpis['costo_total_usd'] += $costo_real;
                        }
                    }
                }
                $stmtCamp->close();
            }

            if ($kpis['total_enviados'] > 0) {
                $kpis['tasa_entrega_pct'] = round(($kpis['total_entregados'] / $kpis['total_enviados']) * 100, 1);
                $kpis['tasa_lectura_pct'] = round(($kpis['total_leidos'] / $kpis['total_enviados']) * 100, 1);
                $kpis['tasa_respuesta_pct'] = round(($kpis['total_respondidos'] / $kpis['total_enviados']) * 100, 1);
            }
            $kpis['costo_total_usd'] = round($kpis['costo_total_usd'], 4);
        }

        echo json_encode([
            'status' => 'success',
            'sede_seleccionada' => $filtro_sede,
            'es_global' => $es_global,
            'sedes' => $sedes,
            'etiquetas' => $etiquetas,
            'contactos' => $contactos,
            'campanas' => $campanas,
            'kpis' => $kpis
        ]);
        break;

    // =========================================================================
    // 2. GUARDAR CONTROL DE FLUJO DIARIO Y COSTO UNITARIO META DE UNA SEDE
    // =========================================================================
    case 'save_sede_flow_config':
        $id_sede = intval($_POST['id_sede'] ?? 0);
        if (!$es_global && $id_sede_usuario > 0) {
            $id_sede = $id_sede_usuario;
        }
        $limite_diario = max(10, min(100000, intval($_POST['limite_diario_mensajes'] ?? 250)));
        $cadencia_ms = max(250, min(15000, intval($_POST['cadencia_ms'] ?? 1000)));

        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Seleccione una sede válida.']);
            exit;
        }

        $datos_anteriores = null;
        $costo_mkt = 0.0700;
        $costo_util = 0.0150;
        $stmtCur = $con->prepare('SELECT * FROM campanas_cuotas_sede WHERE id_sede = ? LIMIT 1');
        if ($stmtCur) {
            $stmtCur->bind_param('i', $id_sede);
            if (@$stmtCur->execute()) {
                $resCur = $stmtCur->get_result();
                if ($resCur && ($rcur = $resCur->fetch_assoc())) {
                    $datos_anteriores = $rcur;
                    $costo_mkt = floatval($rcur['costo_marketing_usd']);
                    $costo_util = floatval($rcur['costo_utility_usd']);
                }
            }
            $stmtCur->close();
        }

        $stmt = $con->prepare(
            'INSERT INTO campanas_cuotas_sede (id_sede, limite_diario_mensajes, cadencia_ms, costo_marketing_usd, costo_utility_usd)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             limite_diario_mensajes = VALUES(limite_diario_mensajes),
             cadencia_ms = VALUES(cadencia_ms)'
        );
        if (!$stmt) {
            starfi_log_exception('campanas', 'Error preparando save_sede_flow_config', ['error' => $con->error]);
            echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar la configuración de la sede.']);
            exit;
        }
        $stmt->bind_param('iiidd', $id_sede, $limite_diario, $cadencia_ms, $costo_mkt, $costo_util);
        if ($stmt->execute()) {
            $stmt->close();
            $datos_nuevos = [
                'id_sede' => $id_sede,
                'limite_diario_mensajes' => $limite_diario,
                'cadencia_ms' => $cadencia_ms,
                'costo_marketing_usd' => $costo_mkt,
                'costo_utility_usd' => $costo_util
            ];
            registrar_auditoria(
                'campanas',
                'CONFIGURAR_FLUJO_SEDE',
                "Actualización de límite diario ({$limite_diario}) y cadencia ({$cadencia_ms}ms) para sede #{$id_sede}",
                $datos_anteriores,
                $datos_nuevos,
                $con,
                $id_usuario,
                $id_sede
            );
            echo json_encode([
                'status' => 'success',
                'message' => 'Límite y ritmo diario actualizados para la sede.'
            ]);
        } else {
            starfi_log_exception('campanas', 'Error ejecutando save_sede_flow_config', ['error' => $stmt->error]);
            $stmt->close();
            echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar la configuración de la sede.']);
        }
        break;

    // =========================================================================
    // 3. SINCRONIZAR PLANTILLAS DE META PARA LA SEDE SELECCIONADA
    // =========================================================================
    case 'get_sede_templates':
        $id_sede = intval($_GET['id_sede'] ?? $_POST['id_sede'] ?? 0);
        if (!$es_global && $id_sede_usuario > 0) {
            $id_sede = $id_sede_usuario;
        }
        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Sede inválida.']);
            exit;
        }

        $lin = null;
        $stmtL = $con->prepare('SELECT id_negocio, meta_token, numero_telefono FROM lineas_whatsapp WHERE id_sede = ? AND estado = \'ACTIVO\' LIMIT 1');
        if ($stmtL) {
            $stmtL->bind_param('i', $id_sede);
            if (@$stmtL->execute()) {
                $resL = $stmtL->get_result();
                if ($resL && $resL->num_rows > 0) {
                    $lin = $resL->fetch_assoc();
                }
            }
            $stmtL->close();
        }

        $templates = [];
        $tiene_starfi_directa = false;
        $estado_starfi_directa = 'MISSING';
        $waba_id = '';
        $token = '';

        if ($lin) {
            $waba_id = trim((string)($lin['id_negocio'] ?? ''));
            $token = !empty($lin['meta_token']) ? trim((string)$lin['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

            if ($waba_id !== '' && $token !== '') {
                $url = "https://graph.facebook.com/{$apiVerGlobal}/{$waba_id}/message_templates?fields=name,status,language,category,components&limit=100";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code >= 200 && $code < 300 && $resp) {
                    $json = json_decode((string)$resp, true);
                    if (!empty($json['data'])) {
                        foreach ($json['data'] as $tpl) {
                            $st = strtoupper((string)($tpl['status'] ?? ''));
                            if (($tpl['name'] ?? '') === 'starfi_difusion_directa') {
                                $estado_starfi_directa = $st;
                            }
                            if ($st === 'APPROVED' || $st === 'ACTIVE') {
                                $body_txt = '';
                                $header_fmt = 'NONE';
                                $header_txt = '';
                                $header_examples = [];
                                $body_examples = [];
                                $footer_txt = '';
                                $buttons_arr = [];

                                foreach (($tpl['components'] ?? []) as $comp) {
                                    $c_type = strtoupper((string)($comp['type'] ?? ''));
                                    if ($c_type === 'BODY') {
                                        $body_txt = $comp['text'] ?? '';
                                        if (!empty($comp['example']['body_text'][0]) && is_array($comp['example']['body_text'][0])) {
                                            $body_examples = array_values(array_map('strval', $comp['example']['body_text'][0]));
                                        }
                                    } elseif ($c_type === 'HEADER') {
                                        $header_fmt = strtoupper((string)($comp['format'] ?? 'TEXT'));
                                        if ($header_fmt === 'TEXT') {
                                            $header_txt = $comp['text'] ?? '';
                                            if (!empty($comp['example']['header_text']) && is_array($comp['example']['header_text'])) {
                                                $header_examples = array_values(array_map('strval', $comp['example']['header_text']));
                                            }
                                        }
                                    } elseif ($c_type === 'FOOTER') {
                                        $footer_txt = $comp['text'] ?? '';
                                    } elseif ($c_type === 'BUTTONS' && !empty($comp['buttons']) && is_array($comp['buttons'])) {
                                        foreach ($comp['buttons'] as $b) {
                                            $buttons_arr[] = [
                                                'type' => strtoupper((string)($b['type'] ?? 'QUICK_REPLY')),
                                                'text' => $b['text'] ?? '',
                                                'url' => $b['url'] ?? '',
                                                'phone_number' => $b['phone_number'] ?? ''
                                            ];
                                        }
                                    }
                                }

                                if (($tpl['name'] ?? '') === 'starfi_difusion_directa' && $header_fmt === 'NONE') {
                                    $header_fmt = 'IMAGE';
                                }

                                $templates[] = [
                                    'name' => $tpl['name'],
                                    'language' => $tpl['language'] ?? 'es',
                                    'category' => strtoupper((string)($tpl['category'] ?? 'MARKETING')),
                                    'status' => $st,
                                    'header_format' => $header_fmt,
                                    'header_text' => $header_txt,
                                    'header_examples' => $header_examples,
                                    'body' => $body_txt,
                                    'body_examples' => $body_examples,
                                    'footer' => $footer_txt,
                                    'buttons' => $buttons_arr
                                ];
                                if (($tpl['name'] ?? '') === 'starfi_difusion_directa') {
                                    $tiene_starfi_directa = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        if ($estado_starfi_directa === 'MISSING' && $waba_id !== '' && $token !== '') {
            $create_payload = [
                'name' => 'starfi_difusion_directa',
                'language' => 'es',
                'category' => 'MARKETING',
                'components' => [
                    [
                        'type' => 'BODY',
                        'text' => "👋¡Hola {{1}}!\nHoy queremos {{2}}.\nRecuerda que en {{3}}.\n📲 *Responde a este mensaje para obtener más información*👇",
                        'example' => [
                            'body_text' => [
                                [
                                    'Carlos',
                                    'invitarte a la próxima inauguración de nuestra nueva tienda Carpintero Express con ofertas especiales para ti',
                                    'Carpintero Express estamos cada día más cerca de ti y somos tu parada obligatoria'
                                ]
                            ]
                        ]
                    ],
                    [
                        'type' => 'FOOTER',
                        'text' => 'Servicio de mensajería inteligente de STARFI CRM'
                    ]
                ]
            ];
            $ch_c = curl_init("https://graph.facebook.com/{$apiVerGlobal}/{$waba_id}/message_templates");
            curl_setopt($ch_c, CURLOPT_POST, true);
            curl_setopt($ch_c, CURLOPT_POSTFIELDS, json_encode($create_payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch_c, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", 'Content-Type: application/json']);
            curl_setopt($ch_c, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_c, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch_c, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch_c, CURLOPT_TIMEOUT, 8);
            $resp_c = json_decode((string)curl_exec($ch_c), true);
            curl_close($ch_c);
            if (!empty($resp_c['status'])) {
                $estado_starfi_directa = strtoupper((string)$resp_c['status']);
                if ($estado_starfi_directa === 'APPROVED' || $estado_starfi_directa === 'ACTIVE') {
                    $tiene_starfi_directa = true;
                }
            }
        }

        if (!$tiene_starfi_directa) {
            array_unshift($templates, [
                'name' => 'starfi_difusion_directa',
                'language' => 'es',
                'category' => 'MARKETING',
                'status' => $estado_starfi_directa !== 'MISSING' ? $estado_starfi_directa : 'APPROVED',
                'header_format' => 'IMAGE',
                'header_text' => '',
                'header_examples' => [],
                'body' => "👋¡Hola {{1}}!\nHoy queremos {{2}}.\nRecuerda que en {{3}}.\n📲 *Responde a este mensaje para obtener más información*👇",
                'body_examples' => [
                    'Carlos',
                    'invitarte este 16 de noviembre a la GRAN INAUGURACIÓN de nuestra nueva sede CARPINTERO EXPRESS BOLEÍTA 🎉🪵 Ven a disfrutar de grandes ofertas especiales de apertura, sorpresas y todo lo que necesitas para tus proyectos',
                    'Carpintero Express estamos cada día más cerca de ti y somos tu parada obligatoria'
                ],
                'footer' => 'Servicio de mensajería inteligente de STARFI CRM',
                'buttons' => []
            ]);
        }

        echo json_encode([
            'status' => 'success',
            'templates' => $templates
        ]);
        break;

    // =========================================================================
    // 4. CREAR CAMPAÑA CON COLA INTELIGENTE + ESCUDO ANTI-FATIGA 24H EN BACKEND
    // =========================================================================
    case 'create_campaign_with_queue':
        $id_sede = intval($_POST['id_sede'] ?? 0);
        if (!$es_global && $id_sede_usuario > 0) {
            $id_sede = $id_sede_usuario;
        }
        $nombre_campana = trim((string)($_POST['nombre_campana'] ?? ''));
        $template_name = trim((string)($_POST['template_name'] ?? 'starfi_difusion_directa')) ?: 'starfi_difusion_directa';
        $template_language = trim((string)($_POST['template_language'] ?? 'es')) ?: 'es';
        $template_category = strtoupper(trim((string)($_POST['template_category'] ?? 'MARKETING'))) ?: 'MARKETING';
        $header_format = strtoupper(trim((string)($_POST['header_format'] ?? 'IMAGE'))) ?: 'IMAGE';
        $template_params_raw = $_POST['template_params_json'] ?? '';
        $template_params_arr = !empty($template_params_raw) ? json_decode((string)$template_params_raw, true) : null;

        $raw_cuerpo = trim((string)($_POST['param_cuerpo'] ?? ''));
        $raw_eslogan = trim((string)($_POST['param_eslogan'] ?? ''));
        $costo_unitario_usd = max(0, floatval($_POST['costo_unitario_usd'] ?? 0.0700));
        $presupuesto_max_usd = max(0, floatval($_POST['presupuesto_max_usd'] ?? 0.00));
        $hora_prog_raw = trim((string)($_POST['hora_programada'] ?? '09:00'));
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $hora_prog_raw)) {
            $hora_prog_raw = '09:00:00';
        } elseif (strlen($hora_prog_raw) === 5) {
            $hora_prog_raw .= ':00';
        }
        $hora_programada = $hora_prog_raw;
        $destinatarios_json = $_POST['destinatarios'] ?? '[]';
        $destinatarios = json_decode((string)$destinatarios_json, true);

        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar la Sede emisora para la campaña.']);
            exit;
        }
        if ($nombre_campana === '') {
            $nombre_campana = 'Campaña ' . date('d/m/Y H:i');
        }

        $body_params_clean = [];
        $header_text_param_clean = '';
        $body_template_str = '';
        $header_text_template_str = '';
        $footer_text_str = '';

        if (is_array($template_params_arr) && isset($template_params_arr['body_params']) && is_array($template_params_arr['body_params'])) {
            foreach ($template_params_arr['body_params'] as $idx_bp => $val_bp) {
                $v_trim = trim((string)$val_bp);
                if ($v_trim === '') {
                    $num_var = intval($idx_bp) + 1;
                    echo json_encode(['status' => 'error', 'message' => "Complete la Variable {{{$num_var}}} solicitada por la plantilla."]);
                    exit;
                }
                $body_params_clean[] = sanitizeMetaTemplateParam($v_trim, true);
            }
            if (!empty($template_params_arr['header_has_var'])) {
                $ht_val = trim((string)($template_params_arr['header_text_param'] ?? ''));
                if ($ht_val === '') {
                    echo json_encode(['status' => 'error', 'message' => 'Complete la Variable {{1}} de la cabecera de la plantilla.']);
                    exit;
                }
                $header_text_param_clean = sanitizeMetaTemplateParam($ht_val, false);
            }
            $body_template_str = (string)($template_params_arr['body_template'] ?? '');
            $header_text_template_str = (string)($template_params_arr['header_text_template'] ?? '');
            $footer_text_str = (string)($template_params_arr['footer_text'] ?? '');

            $param_cuerpo = $body_params_clean[1] ?? ($body_params_clean[0] ?? 'Plantilla sin variables');
            $param_eslogan = $body_params_clean[2] ?? ($body_params_clean[1] ?? ($body_params_clean[0] ?? ''));
        } else {
            if ($raw_cuerpo === '' || $raw_eslogan === '') {
                echo json_encode(['status' => 'error', 'message' => 'Complete las variables del mensaje ({{2}} y {{3}}).']);
                exit;
            }
            $param_cuerpo = sanitizeMetaTemplateParam($raw_cuerpo, true);
            $param_eslogan = sanitizeMetaTemplateParam($raw_eslogan, true);
            $body_params_clean = ['@NOMBRE_CLIENTE', $param_cuerpo, $param_eslogan];
        }

        $template_params_json_save = json_encode([
            'body_params' => $body_params_clean,
            'header_text_param' => $header_text_param_clean,
            'body_template' => $body_template_str,
            'header_text_template' => $header_text_template_str,
            'footer_text' => $footer_text_str
        ], JSON_UNESCAPED_UNICODE);

        if (!is_array($destinatarios) || count($destinatarios) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Seleccione al menos un destinatario para la campaña.']);
            exit;
        }

        // Verificar que la sede tenga línea activa
        $linea = null;
        $stmtLin = $con->prepare('SELECT id, numero_telefono, meta_app_id, meta_token, limite_solicitudes FROM lineas_whatsapp WHERE id_sede = ? AND estado = \'ACTIVO\' LIMIT 1');
        if ($stmtLin) {
            $stmtLin->bind_param('i', $id_sede);
            if (@$stmtLin->execute()) {
                $resLin = $stmtLin->get_result();
                if ($resLin && $resLin->num_rows > 0) {
                    $linea = $resLin->fetch_assoc();
                }
            }
            $stmtLin->close();
        }
        if (!$linea) {
            echo json_encode(['status' => 'error', 'message' => 'La sede seleccionada no posee una línea oficial de WhatsApp activa.']);
            exit;
        }
        $token_efectivo = !empty($linea['meta_token']) ? trim((string)$linea['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');
        if (empty($linea['meta_app_id']) || $token_efectivo === '') {
            echo json_encode(['status' => 'error', 'message' => 'La línea de WhatsApp de esta sede no tiene configurado el Phone Number ID o Token de Meta.']);
            exit;
        }

        // Obtener cuota diaria y tarifa oficial de la sede
        $limite_diario = max(1, intval($linea['limite_solicitudes'] ?? 250));
        $cadencia_ms = 1000;
        $base_mkt_sede = 0.0700;
        $base_util_sede = 0.0150;
        $waba_tipo_sede = 'PORCENTAJE';
        $waba_val_sede = 10.00;

        $stmtQ = $con->prepare(
            'SELECT IFNULL(q.limite_diario_mensajes, IFNULL(l.limite_solicitudes, 250)) AS limite_diario_mensajes,
                    IFNULL(q.cadencia_ms, 1000) AS cadencia_ms,
                    IFNULL(q.costo_marketing_usd, 0.0700) AS costo_marketing_base_usd,
                    IFNULL(q.costo_utility_usd, 0.0150) AS costo_utility_base_usd,
                    IFNULL(wt.tipo_tarifa, \'PORCENTAJE\') AS waba_tipo_tarifa,
                    IFNULL(wt.valor, 10.00) AS waba_valor_tarifa
             FROM sedes s
             LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id AND l.estado = \'ACTIVO\'
             LEFT JOIN campanas_cuotas_sede q ON q.id_sede = s.id
             LEFT JOIN waba_tarifas_sede wt ON wt.id_sede = s.id
             WHERE s.id = ? LIMIT 1'
        );
        if ($stmtQ) {
            $stmtQ->bind_param('i', $id_sede);
            if (@$stmtQ->execute()) {
                $resQ = $stmtQ->get_result();
                if ($resQ && ($rq = $resQ->fetch_assoc())) {
                    $limite_diario = max(1, intval($rq['limite_diario_mensajes']));
                    $cadencia_ms = max(250, intval($rq['cadencia_ms']));
                    $base_mkt_sede = floatval($rq['costo_marketing_base_usd']);
                    $base_util_sede = floatval($rq['costo_utility_base_usd']);
                    $waba_tipo_sede = $rq['waba_tipo_tarifa'] ?: 'PORCENTAJE';
                    $waba_val_sede = floatval($rq['waba_valor_tarifa'] ?? 10.00);
                }
            }
            $stmtQ->close();
        }

        $cuota_solicitada = max(1, intval($_POST['cuota_diaria_programada'] ?? $limite_diario));
        if ($cuota_solicitada > $limite_diario) {
            $limite_diario = min(100000, $cuota_solicitada);
            $stmtUpLim = $con->prepare(
                'INSERT INTO campanas_cuotas_sede (id_sede, limite_diario_mensajes, cadencia_ms)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE limite_diario_mensajes = GREATEST(limite_diario_mensajes, ?)'
            );
            if ($stmtUpLim) {
                $stmtUpLim->bind_param('iiii', $id_sede, $limite_diario, $cadencia_ms, $limite_diario);
                @$stmtUpLim->execute();
                $stmtUpLim->close();
            }
        }

        $base_tarifa_aplicable = ($template_category === 'UTILITY') ? $base_util_sede : $base_mkt_sede;
        $costo_unitario_usd = calcularPrecioFinalUnitarioSede($base_tarifa_aplicable, $waba_tipo_sede, $waba_val_sede);

        $enviados_hoy = 0;
        $stmtHoy = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
        if ($stmtHoy) {
            $stmtHoy->bind_param('i', $id_sede);
            if (@$stmtHoy->execute()) {
                $resHoy = $stmtHoy->get_result();
                if ($resHoy && ($rh = $resHoy->fetch_assoc())) {
                    $enviados_hoy = intval($rh['c']);
                }
            }
            $stmtHoy->close();
        }
        $disponibles_hoy = max(0, $limite_diario - $enviados_hoy);

        $max_por_presupuesto = 999999;
        if ($presupuesto_max_usd > 0 && $costo_unitario_usd > 0) {
            $max_por_presupuesto = intval(floor(($presupuesto_max_usd + 0.00001) / $costo_unitario_usd));
            if ($max_por_presupuesto <= 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'El presupuesto máximo asignado ($' . number_format($presupuesto_max_usd, 2) . ' USD) es menor al costo de 1 mensaje ($' . number_format($costo_unitario_usd, 4) . ' USD).'
                ]);
                exit;
            }
        }

        // Limpiar, normalizar, deduplicar Y aplicar Escudo Anti-Fatiga 24h en Backend si es MARKETING
        $dedup = [];
        $omitidos_antifatiga_24h = 0;
        $es_campana_marketing = ($template_category === 'MARKETING');

        foreach ($destinatarios as $d) {
            $tel_raw = trim((string)($d['telefono'] ?? ''));
            $tel_digits = normalizeVenezuelanPhone($tel_raw);
            if (strlen($tel_digits) < 8) {
                continue;
            }
            if (isset($dedup[$tel_digits])) {
                continue;
            }
            if ($es_campana_marketing && starfi_check_marketing_antifatigue_24h($con, $tel_digits, 0)) {
                $omitidos_antifatiga_24h++;
                continue;
            }
            $dedup[$tel_digits] = [
                'id_cliente' => intval($d['id_cliente'] ?? 0),
                'nombre' => trim((string)($d['nombre'] ?? 'Cliente')) ?: 'Cliente',
                'telefono' => $tel_digits
            ];
        }

        $lista_limpia = array_values($dedup);
        $recortados_por_presupuesto = 0;
        if (count($lista_limpia) > $max_por_presupuesto) {
            $recortados_por_presupuesto = count($lista_limpia) - $max_por_presupuesto;
            $lista_limpia = array_slice($lista_limpia, 0, $max_por_presupuesto);
        }

        $total_dest = count($lista_limpia);
        if ($total_dest === 0) {
            $msgEmpty = ($omitidos_antifatiga_24h > 0)
                ? "Los {$omitidos_antifatiga_24h} destinatarios seleccionados fueron omitidos por el Escudo Anti-Fatiga 24h (Meta Error 131049) al haber recibido un mensaje promocional en las últimas 24 horas sin responder."
                : 'Ninguno de los destinatarios tiene un número de teléfono válido.';
            echo json_encode(['status' => 'error', 'message' => $msgEmpty]);
            exit;
        }

        // Validar y guardar imagen opcional de cabecera ANTES de insertar la campaña (finfo + <= 5 MB)
        $header_imagen_path = null;
        if (isset($_FILES['header_imagen']) && intval($_FILES['header_imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upRes = starfi_validar_y_guardar_imagen_campana($_FILES['header_imagen'], $id_sede, $con, $id_usuario);
            if (!$upRes['ok']) {
                echo json_encode(['status' => 'error', 'message' => $upRes['message']]);
                exit;
            }
            $header_imagen_path = $upRes['rel_path'];
        }

        $cuota_diaria_prog = max(1, intval($_POST['cuota_diaria_programada'] ?? $limite_diario));
        $cuota_diaria_prog = min($cuota_diaria_prog, $limite_diario);
        $piloto_automatico = intval($_POST['piloto_automatico'] ?? 1) === 1 ? 1 : 0;

        $cupo_tanda_1 = min($cuota_diaria_prog, $disponibles_hoy);
        $plan_tandas = [];
        $restantes_calc = $total_dest;
        $tanda_idx = 1;
        $dia_offset = 0;

        if ($cupo_tanda_1 > 0) {
            $cant_t1 = min($restantes_calc, $cupo_tanda_1);
            $plan_tandas[] = [
                'numero_tanda' => $tanda_idx,
                'fecha_programada' => date('Y-m-d'),
                'cantidad' => $cant_t1,
                'costo_estimado_usd' => round($cant_t1 * $costo_unitario_usd, 4)
            ];
            $restantes_calc -= $cant_t1;
            $tanda_idx++;
            $dia_offset = 1;
        } else {
            $dia_offset = 1;
        }

        while ($restantes_calc > 0) {
            $cant_t = min($restantes_calc, $cuota_diaria_prog);
            $fecha_t = date('Y-m-d', strtotime("+{$dia_offset} days"));
            $plan_tandas[] = [
                'numero_tanda' => $tanda_idx,
                'fecha_programada' => $fecha_t,
                'cantidad' => $cant_t,
                'costo_estimado_usd' => round($cant_t * $costo_unitario_usd, 4)
            ];
            $restantes_calc -= $cant_t;
            $tanda_idx++;
            $dia_offset++;
        }

        $total_tandas = max(1, count($plan_tandas));
        $costo_estimado_usd = round($total_dest * $costo_unitario_usd, 4);

        if (starfi_has_categoria_meta_col($con)) {
            $stmt = $con->prepare(
                'INSERT INTO campanas_difusion
                    (id_sede, id_usuario, nombre_campana, template_name, template_language, template_category, categoria_meta, header_format, param_cuerpo, param_eslogan, template_params_json, header_imagen_path, costo_unitario_usd, presupuesto_max_usd, costo_estimado_usd, cuota_diaria_programada, total_tandas, hora_programada, piloto_automatico, total_destinatarios, pendientes, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'PENDIENTE_APROBACION\')'
            );
            if ($stmt) {
                $stmt->bind_param(
                    'iissssssssssdddiisiii',
                    $id_sede,
                    $id_usuario,
                    $nombre_campana,
                    $template_name,
                    $template_language,
                    $template_category,
                    $template_category,
                    $header_format,
                    $param_cuerpo,
                    $param_eslogan,
                    $template_params_json_save,
                    $header_imagen_path,
                    $costo_unitario_usd,
                    $presupuesto_max_usd,
                    $costo_estimado_usd,
                    $cuota_diaria_prog,
                    $total_tandas,
                    $hora_programada,
                    $piloto_automatico,
                    $total_dest,
                    $total_dest
                );
            }
        } else {
            $stmt = $con->prepare(
                'INSERT INTO campanas_difusion
                    (id_sede, id_usuario, nombre_campana, template_name, template_language, template_category, header_format, param_cuerpo, param_eslogan, template_params_json, header_imagen_path, costo_unitario_usd, presupuesto_max_usd, costo_estimado_usd, cuota_diaria_programada, total_tandas, hora_programada, piloto_automatico, total_destinatarios, pendientes, estado)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'PENDIENTE_APROBACION\')'
            );
            if ($stmt) {
                $stmt->bind_param(
                    'iisssssssssdddiisiii',
                    $id_sede,
                    $id_usuario,
                    $nombre_campana,
                    $template_name,
                    $template_language,
                    $template_category,
                    $header_format,
                    $param_cuerpo,
                    $param_eslogan,
                    $template_params_json_save,
                    $header_imagen_path,
                    $costo_unitario_usd,
                    $presupuesto_max_usd,
                    $costo_estimado_usd,
                    $cuota_diaria_prog,
                    $total_tandas,
                    $hora_programada,
                    $piloto_automatico,
                    $total_dest,
                    $total_dest
                );
            }
        }

        if (!$stmt || !$stmt->execute()) {
            starfi_log_exception('campanas', 'Error al insertar campaña en campanas_difusion', [
                'error' => $stmt ? $stmt->error : $con->error,
                'id_sede' => $id_sede
            ]);
            if ($stmt) {
                $stmt->close();
            }
            echo json_encode(['status' => 'error', 'message' => 'Error interno al registrar la campaña en la base de datos.']);
            exit;
        }
        $id_campana = intval($stmt->insert_id);
        $stmt->close();

        $stmt_d = $con->prepare(
            'INSERT INTO campanas_difusion_destinatarios
                (id_campana, id_sede, id_cliente, nombre, telefono, numero_tanda, fecha_programada, estado_envio)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'PENDIENTE\')'
        );
        $cola_items = [];
        $cursor_item = 0;
        if ($stmt_d) {
            foreach ($plan_tandas as $t_info) {
                $num_tanda = intval($t_info['numero_tanda']);
                $fecha_prog = (string)$t_info['fecha_programada'];
                $cant_en_tanda = intval($t_info['cantidad']);
                for ($k = 0; $k < $cant_en_tanda && $cursor_item < $total_dest; $k++, $cursor_item++) {
                    $item = $lista_limpia[$cursor_item];
                    $id_cli = intval($item['id_cliente']);
                    $nom_cli = (string)$item['nombre'];
                    $tel_cli = (string)$item['telefono'];
                    $stmt_d->bind_param('iiissis', $id_campana, $id_sede, $id_cli, $nom_cli, $tel_cli, $num_tanda, $fecha_prog);
                    if (@$stmt_d->execute()) {
                        $cola_items[] = [
                            'id_destinatario' => intval($stmt_d->insert_id),
                            'id_cliente' => $id_cli,
                            'nombre' => $nom_cli,
                            'telefono' => $tel_cli,
                            'numero_tanda' => $num_tanda,
                            'fecha_programada' => $fecha_prog
                        ];
                    }
                }
            }
            $stmt_d->close();
        }

        registrar_auditoria(
            'campanas',
            'CREAR_CAMPANA',
            "Registro de campaña #{$id_campana}: {$nombre_campana} ({$total_dest} destinatarios)",
            null,
            [
                'id_campana' => $id_campana,
                'id_sede' => $id_sede,
                'nombre_campana' => $nombre_campana,
                'template_name' => $template_name,
                'template_category' => $template_category,
                'total_encolados' => count($cola_items),
                'omitidos_antifatiga_24h' => $omitidos_antifatiga_24h,
                'costo_estimado_usd' => $costo_estimado_usd,
                'cuota_diaria_programada' => $cuota_diaria_prog,
                'hora_programada' => $hora_programada,
                'piloto_automatico' => $piloto_automatico,
                'header_imagen_path' => $header_imagen_path
            ],
            $con,
            $id_usuario,
            $id_sede
        );

        echo json_encode([
            'status' => 'success',
            'estado_inicial' => 'PENDIENTE_APROBACION',
            'id_campana' => $id_campana,
            'id_sede' => $id_sede,
            'nombre_campana' => $nombre_campana,
            'linea_telefono' => $linea['numero_telefono'],
            'cadencia_ms' => $cadencia_ms,
            'limite_diario' => $limite_diario,
            'cuota_diaria_programada' => $cuota_diaria_prog,
            'hora_programada' => substr($hora_programada, 0, 5),
            'piloto_automatico' => $piloto_automatico,
            'enviados_hoy' => $enviados_hoy,
            'disponibles_hoy' => $disponibles_hoy,
            'total_encolados' => count($cola_items),
            'omitidos_antifatiga_24h' => $omitidos_antifatiga_24h,
            'total_tandas' => $total_tandas,
            'costo_unitario_usd' => $costo_unitario_usd,
            'costo_estimado_usd' => $costo_estimado_usd,
            'recortados_por_presupuesto' => $recortados_por_presupuesto,
            'plan_tandas' => $plan_tandas
        ]);
        break;

    // =========================================================================
    // 4.1 APROBAR CAMPAÑA E INICIAR DIFUSIÓN
    // =========================================================================
    case 'approve_and_start_campaign':
        $id_campana = intval($_POST['id_campana'] ?? $_GET['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID de campaña inválido.']);
            exit;
        }
        $camp = obtenerCampanaPorId($con, $id_campana);
        if (!$camp) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
            exit;
        }
        $datos_anteriores_camp = $camp;
        $id_sede = intval($camp['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: solo puede gestionar campañas de su propia sede.']);
            exit;
        }

        if (!empty($_POST['hora_programada'])) {
            $hp_raw = trim((string)$_POST['hora_programada']);
            if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $hp_raw)) {
                if (strlen($hp_raw) === 5) {
                    $hp_raw .= ':00';
                }
                $stmtHp = $con->prepare('UPDATE campanas_difusion SET hora_programada = ? WHERE id = ?');
                if ($stmtHp) {
                    $stmtHp->bind_param('si', $hp_raw, $id_campana);
                    @$stmtHp->execute();
                    $stmtHp->close();
                }
                $camp['hora_programada'] = $hp_raw;
            }
        }

        if (isset($_FILES['header_imagen']) && intval($_FILES['header_imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upRes = starfi_validar_y_guardar_imagen_campana($_FILES['header_imagen'], $id_sede, $con, $id_usuario);
            if (!$upRes['ok']) {
                echo json_encode(['status' => 'error', 'message' => $upRes['message']]);
                exit;
            }
            $new_rel_path = $upRes['rel_path'];
            $stmtImg = $con->prepare('UPDATE campanas_difusion SET header_imagen_path = ?, header_media_id = NULL WHERE id = ?');
            if ($stmtImg) {
                $stmtImg->bind_param('si', $new_rel_path, $id_campana);
                @$stmtImg->execute();
                $stmtImg->close();
            }
            $camp['header_imagen_path'] = $new_rel_path;
            $camp['header_media_id'] = null;
        }

        $limite_diario_sede = 250;
        $cadencia_ms = 1000;
        $stmtQ = $con->prepare(
            'SELECT IFNULL(q.limite_diario_mensajes, IFNULL(l.limite_solicitudes, 250)) AS limite_diario_mensajes,
                    IFNULL(q.cadencia_ms, 1000) AS cadencia_ms
             FROM sedes s
             LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id AND l.estado = \'ACTIVO\'
             LEFT JOIN campanas_cuotas_sede q ON q.id_sede = s.id
             WHERE s.id = ? LIMIT 1'
        );
        if ($stmtQ) {
            $stmtQ->bind_param('i', $id_sede);
            if (@$stmtQ->execute()) {
                $resQ = $stmtQ->get_result();
                if ($resQ && ($rq = $resQ->fetch_assoc())) {
                    $limite_diario_sede = max(1, intval($rq['limite_diario_mensajes']));
                    $cadencia_ms = max(250, intval($rq['cadencia_ms']));
                }
            }
            $stmtQ->close();
        }

        $cuota_campana = max(1, intval($camp['cuota_diaria_programada'] ?? $limite_diario_sede));
        if ($cuota_campana > $limite_diario_sede) {
            $limite_diario_sede = $cuota_campana;
        }
        $tope_efectivo_hoy = min($limite_diario_sede, $cuota_campana);

        $enviados_hoy_sede = 0;
        $stmtHs = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
        if ($stmtHs) {
            $stmtHs->bind_param('i', $id_sede);
            if (@$stmtHs->execute()) {
                $rHs = $stmtHs->get_result();
                if ($rHs && ($rh = $rHs->fetch_assoc())) {
                    $enviados_hoy_sede = intval($rh['c']);
                }
            }
            $stmtHs->close();
        }

        $enviados_hoy_camp = 0;
        $stmtHc = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
        if ($stmtHc) {
            $stmtHc->bind_param('i', $id_campana);
            if (@$stmtHc->execute()) {
                $rHc = $stmtHc->get_result();
                if ($rHc && ($rhc = $rHc->fetch_assoc())) {
                    $enviados_hoy_camp = intval($rhc['c']);
                }
            }
            $stmtHc->close();
        }

        $disp_sede = max(0, $limite_diario_sede - $enviados_hoy_sede);
        $disp_camp = max(0, $tope_efectivo_hoy - $enviados_hoy_camp);
        $disponibles_hoy = min($disp_sede, $disp_camp);

        $hora_prog_camp = !empty($camp['hora_programada']) ? substr(trim((string)$camp['hora_programada']), 0, 5) : '09:00';
        $hora_actual_hm = date('H:i');
        $iniciar_inmediato = isset($_POST['iniciar_inmediato']) ? (intval($_POST['iniciar_inmediato']) === 1) : true;
        $esperar_hora_programada = (!$iniciar_inmediato && $hora_actual_hm < $hora_prog_camp);

        $nuevo_estado = ($disponibles_hoy > 0 && !$esperar_hora_programada) ? 'APROBADA_EN_PROCESO' : 'PILOTO_AUTOMATICO';
        $stmtApr = $con->prepare(
            'UPDATE campanas_difusion
             SET estado = ?,
                 aprobado_por = COALESCE(aprobado_por, ?),
                 fecha_aprobacion = COALESCE(fecha_aprobacion, NOW())
             WHERE id = ?'
        );
        if ($stmtApr) {
            $stmtApr->bind_param('sii', $nuevo_estado, $id_usuario, $id_campana);
            @$stmtApr->execute();
            $stmtApr->close();
        }

        $cola = [];
        if ($disponibles_hoy > 0 && !$esperar_hora_programada) {
            $stmtUnstick = $con->prepare(
                'UPDATE campanas_difusion_destinatarios
                 SET estado_envio = \'PENDIENTE\'
                 WHERE id_campana = ? AND estado_envio = \'PROCESANDO\' AND (id_mensaje_meta IS NULL OR id_mensaje_meta = \'\')'
            );
            if ($stmtUnstick) {
                $stmtUnstick->bind_param('i', $id_campana);
                @$stmtUnstick->execute();
                $stmtUnstick->close();
            }

            $stmtPend = $con->prepare(
                'SELECT id AS id_destinatario, id_cliente, nombre, telefono, numero_tanda, fecha_programada
                 FROM campanas_difusion_destinatarios
                 WHERE id_campana = ? AND estado_envio = \'PENDIENTE\'
                 ORDER BY numero_tanda ASC, id ASC
                 LIMIT ?'
            );
            if ($stmtPend) {
                $stmtPend->bind_param('ii', $id_campana, $disponibles_hoy);
                if (@$stmtPend->execute()) {
                    $resPend = $stmtPend->get_result();
                    if ($resPend) {
                        while ($r = $resPend->fetch_assoc()) {
                            $cola[] = $r;
                        }
                    }
                }
                $stmtPend->close();
            }

            if (empty($cola)) {
                $hay_fallidos = false;
                $stmtFalChk = $con->prepare(
                    'SELECT id FROM campanas_difusion_destinatarios
                     WHERE id_campana = ? AND estado_envio = \'FALLIDO\' AND fecha_entrega IS NULL AND fecha_lectura IS NULL AND respondido = 0
                     LIMIT 1'
                );
                if ($stmtFalChk) {
                    $stmtFalChk->bind_param('i', $id_campana);
                    if (@$stmtFalChk->execute()) {
                        $stmtFalChk->store_result();
                        $hay_fallidos = ($stmtFalChk->num_rows > 0);
                    }
                    $stmtFalChk->close();
                }

                if ($hay_fallidos) {
                    $stmtReq = $con->prepare(
                        'UPDATE campanas_difusion_destinatarios
                         SET estado_envio = \'PENDIENTE\', error_detalle = NULL, id_mensaje_meta = NULL
                         WHERE id_campana = ? AND estado_envio = \'FALLIDO\' AND fecha_entrega IS NULL AND fecha_lectura IS NULL AND respondido = 0'
                    );
                    if ($stmtReq) {
                        $stmtReq->bind_param('i', $id_campana);
                        @$stmtReq->execute();
                        $stmtReq->close();
                    }
                    recalcularMetricasCampana($con, $id_campana);

                    $stmtPend2 = $con->prepare(
                        'SELECT id AS id_destinatario, id_cliente, nombre, telefono, numero_tanda, fecha_programada
                         FROM campanas_difusion_destinatarios
                         WHERE id_campana = ? AND estado_envio = \'PENDIENTE\'
                         ORDER BY numero_tanda ASC, id ASC
                         LIMIT ?'
                    );
                    if ($stmtPend2) {
                        $stmtPend2->bind_param('ii', $id_campana, $disponibles_hoy);
                        if (@$stmtPend2->execute()) {
                            $resPend2 = $stmtPend2->get_result();
                            if ($resPend2) {
                                while ($r2 = $resPend2->fetch_assoc()) {
                                    $cola[] = $r2;
                                }
                            }
                        }
                        $stmtPend2->close();
                    }
                }
            }
        }

        $camp_despues = obtenerCampanaPorId($con, $id_campana) ?? array_merge($camp, ['estado' => $nuevo_estado]);
        registrar_auditoria(
            'campanas',
            'APROBAR_CAMPANA',
            "Aprobación e inicio de campaña #{$id_campana}: {$camp['nombre_campana']}",
            $datos_anteriores_camp,
            $camp_despues,
            $con,
            $id_usuario,
            $id_sede
        );

        echo json_encode([
            'status' => 'success',
            'id_campana' => $id_campana,
            'nombre_campana' => $camp['nombre_campana'],
            'template_name' => $camp['template_name'],
            'template_language' => $camp['template_language'] ?? 'es',
            'header_format' => $camp['header_format'] ?? 'IMAGE',
            'cadencia_ms' => $cadencia_ms,
            'disponibles_hoy' => $disponibles_hoy,
            'hora_programada' => $hora_prog_camp,
            'esperando_hora' => $esperar_hora_programada,
            'total_tandas' => intval($camp['total_tandas'] ?? 1),
            'piloto_automatico' => intval($camp['piloto_automatico'] ?? 1),
            'cola_envio' => $cola
        ]);
        break;

    // =========================================================================
    // 4.2 RECHAZAR / CANCELAR CAMPAÑA PENDIENTE DE APROBACIÓN
    // =========================================================================
    case 'reject_campaign':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID de campaña inválido.']);
            exit;
        }
        $camp_ant = obtenerCampanaPorId($con, $id_campana);
        if (!$camp_ant) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
            exit;
        }
        $id_sede_camp = intval($camp_ant['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede_camp !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado a campaña de otra sede.']);
            exit;
        }

        $stmtRej = $con->prepare('UPDATE campanas_difusion SET estado = \'CANCELADA\', fecha_fin = NOW() WHERE id = ?');
        if ($stmtRej) {
            $stmtRej->bind_param('i', $id_campana);
            @$stmtRej->execute();
            $stmtRej->close();
        }

        $camp_nue = obtenerCampanaPorId($con, $id_campana) ?? array_merge($camp_ant, ['estado' => 'CANCELADA']);
        registrar_auditoria(
            'campanas',
            'CANCELAR_CAMPANA',
            "Cancelación de campaña #{$id_campana}: {$camp_ant['nombre_campana']}",
            $camp_ant,
            $camp_nue,
            $con,
            $id_usuario,
            $id_sede_camp
        );

        echo json_encode(['status' => 'success', 'message' => 'Campaña cancelada.']);
        break;

    // =========================================================================
    // 4.2A ELIMINAR CAMPAÑA (SOLO PENDIENTE_APROBACION O CANCELADA)
    // =========================================================================
    case 'delete_campaign':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID de campaña inválido.']);
            exit;
        }
        $camp_del = obtenerCampanaPorId($con, $id_campana);
        if (!$camp_del) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
            exit;
        }
        $id_sede_del = intval($camp_del['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede_del !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado a campaña de otra sede.']);
            exit;
        }

        $stmtDelDest = $con->prepare('DELETE FROM campanas_difusion_destinatarios WHERE id_campana = ?');
        if ($stmtDelDest) {
            $stmtDelDest->bind_param('i', $id_campana);
            @$stmtDelDest->execute();
            $stmtDelDest->close();
        }
        $stmtDelCamp = $con->prepare('DELETE FROM campanas_difusion WHERE id = ?');
        if ($stmtDelCamp) {
            $stmtDelCamp->bind_param('i', $id_campana);
            @$stmtDelCamp->execute();
            $stmtDelCamp->close();
        }

        registrar_auditoria(
            'campanas',
            'ELIMINAR_CAMPANA',
            "Eliminación de campaña #{$id_campana}: {$camp_del['nombre_campana']}",
            $camp_del,
            null,
            $con,
            $id_usuario,
            $id_sede_del
        );

        echo json_encode(['status' => 'success', 'message' => 'Campaña eliminada correctamente.']);
        break;

    // =========================================================================
    // 4.2B EDITAR / REAJUSTAR CAMPAÑA ANTES DE APROBAR
    // =========================================================================
    case 'edit_pending_campaign':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID de campaña inválido.']);
            exit;
        }
        $camp = obtenerCampanaPorId($con, $id_campana);
        if (!$camp) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
            exit;
        }
        $datos_anteriores_edit = $camp;
        $id_sede = intval($camp['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: solo puede editar campañas de su propia sede.']);
            exit;
        }

        // Validar imagen si fue adjuntada antes de aplicar mutaciones
        $new_rel_path_edit = null;
        if (isset($_FILES['header_imagen']) && intval($_FILES['header_imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upRes = starfi_validar_y_guardar_imagen_campana($_FILES['header_imagen'], $id_sede, $con, $id_usuario);
            if (!$upRes['ok']) {
                echo json_encode(['status' => 'error', 'message' => $upRes['message']]);
                exit;
            }
            $new_rel_path_edit = $upRes['rel_path'];
        }

        $nuevo_nombre = trim((string)($_POST['nombre_campana'] ?? $camp['nombre_campana']));
        if ($nuevo_nombre === '') {
            $nuevo_nombre = (string)$camp['nombre_campana'];
        }
        $nueva_cuota_diaria = max(1, min(100000, intval($_POST['cuota_diaria_programada'] ?? ($camp['cuota_diaria_programada'] ?: 250))));
        $nueva_hora_raw = trim((string)($_POST['hora_programada'] ?? ($camp['hora_programada'] ?: '09:00:00')));
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $nueva_hora_raw)) {
            $nueva_hora_raw = '09:00:00';
        } elseif (strlen($nueva_hora_raw) === 5) {
            $nueva_hora_raw .= ':00';
        }
        $nuevo_piloto = isset($_POST['piloto_automatico']) ? (intval($_POST['piloto_automatico']) === 1 ? 1 : 0) : intval($camp['piloto_automatico'] ?? 1);
        $nuevo_presupuesto = max(0, floatval($_POST['presupuesto_max_usd'] ?? ($camp['presupuesto_max_usd'] ?? 0)));
        $tope_destinatarios = intval($_POST['tope_destinatarios'] ?? 0);
        $cuota_por_sede_recorte = intval($_POST['cuota_por_sede'] ?? 0);

        $stmtUpLim = $con->prepare(
            'INSERT INTO campanas_cuotas_sede (id_sede, limite_diario_mensajes, cadencia_ms)
             VALUES (?, ?, 1000)
             ON DUPLICATE KEY UPDATE limite_diario_mensajes = GREATEST(limite_diario_mensajes, ?)'
        );
        if ($stmtUpLim) {
            $stmtUpLim->bind_param('iii', $id_sede, $nueva_cuota_diaria, $nueva_cuota_diaria);
            @$stmtUpLim->execute();
            $stmtUpLim->close();
        }

        $total_recortados_eliminados = 0;

        // 1. Recorte por cuota por sede con sentencias preparadas
        if ($cuota_por_sede_recorte > 0) {
            $conteo_s = [];
            $ids_eliminar = [];
            $stmtAllD = $con->prepare(
                'SELECT d.id, COALESCE(c.id_sede, 0) AS id_sede_cli
                 FROM campanas_difusion_destinatarios d
                 LEFT JOIN clientes_contactos c ON c.id = d.id_cliente
                 WHERE d.id_campana = ? AND d.estado_envio = \'PENDIENTE\'
                 ORDER BY d.id ASC'
            );
            if ($stmtAllD) {
                $stmtAllD->bind_param('i', $id_campana);
                if (@$stmtAllD->execute()) {
                    $resAllD = $stmtAllD->get_result();
                    if ($resAllD) {
                        while ($rd = $resAllD->fetch_assoc()) {
                            $sc = intval($rd['id_sede_cli']);
                            if (!isset($conteo_s[$sc])) {
                                $conteo_s[$sc] = 0;
                            }
                            if ($conteo_s[$sc] < $cuota_por_sede_recorte) {
                                $conteo_s[$sc]++;
                            } else {
                                $ids_eliminar[] = intval($rd['id']);
                            }
                        }
                    }
                }
                $stmtAllD->close();
            }

            if (!empty($ids_eliminar)) {
                foreach (array_chunk($ids_eliminar, 400) as $chunk_del) {
                    $placeholders = implode(',', array_fill(0, count($chunk_del), '?'));
                    $types_del = 'i' . str_repeat('i', count($chunk_del));
                    $params_del = array_merge([$id_campana], $chunk_del);
                    $stmtDelChunk = $con->prepare(
                        'DELETE FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio = \'PENDIENTE\' AND id IN (' . $placeholders . ')'
                    );
                    if ($stmtDelChunk) {
                        $stmtDelChunk->bind_param($types_del, ...$params_del);
                        if (@$stmtDelChunk->execute()) {
                            $total_recortados_eliminados += max(0, intval($stmtDelChunk->affected_rows));
                        }
                        $stmtDelChunk->close();
                    }
                }
            }
        }

        // 2. Recorte al tope total de destinatarios con sentencias preparadas
        $costo_unit = floatval($camp['costo_unitario_usd'] ?: 0.0770);
        if ($nuevo_presupuesto > 0 && $costo_unit > 0) {
            $max_pres = intval(floor(($nuevo_presupuesto + 0.00001) / $costo_unit));
            if ($max_pres > 0 && ($tope_destinatarios <= 0 || $max_pres < $tope_destinatarios)) {
                $tope_destinatarios = $max_pres;
            }
        }

        if ($tope_destinatarios > 0) {
            $keep_ids = [];
            $stmtKeep = $con->prepare(
                'SELECT id FROM campanas_difusion_destinatarios
                 WHERE id_campana = ?
                 ORDER BY FIELD(estado_envio, \'RESPONDIDO\',\'LEIDO\',\'ENTREGADO\',\'ENVIADO\',\'PENDIENTE\',\'FALLIDO\'), id ASC
                 LIMIT ?'
            );
            if ($stmtKeep) {
                $stmtKeep->bind_param('ii', $id_campana, $tope_destinatarios);
                if (@$stmtKeep->execute()) {
                    $resKeep = $stmtKeep->get_result();
                    if ($resKeep) {
                        while ($rk = $resKeep->fetch_assoc()) {
                            $keep_ids[] = intval($rk['id']);
                        }
                    }
                }
                $stmtKeep->close();
            }

            if (!empty($keep_ids)) {
                $keep_map = array_flip($keep_ids);
                $del_ids = [];
                $stmtAllP = $con->prepare('SELECT id FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio = \'PENDIENTE\'');
                if ($stmtAllP) {
                    $stmtAllP->bind_param('i', $id_campana);
                    if (@$stmtAllP->execute()) {
                        $resAllP = $stmtAllP->get_result();
                        if ($resAllP) {
                            while ($rp = $resAllP->fetch_assoc()) {
                                $pid = intval($rp['id']);
                                if (!isset($keep_map[$pid])) {
                                    $del_ids[] = $pid;
                                }
                            }
                        }
                    }
                    $stmtAllP->close();
                }

                if (!empty($del_ids)) {
                    foreach (array_chunk($del_ids, 400) as $chunk_del) {
                        $placeholders = implode(',', array_fill(0, count($chunk_del), '?'));
                        $types_del = 'i' . str_repeat('i', count($chunk_del));
                        $params_del = array_merge([$id_campana], $chunk_del);
                        $stmtDelChunk = $con->prepare(
                            'DELETE FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio = \'PENDIENTE\' AND id IN (' . $placeholders . ')'
                        );
                        if ($stmtDelChunk) {
                            $stmtDelChunk->bind_param($types_del, ...$params_del);
                            if (@$stmtDelChunk->execute()) {
                                $total_recortados_eliminados += max(0, intval($stmtDelChunk->affected_rows));
                            }
                            $stmtDelChunk->close();
                        }
                    }
                }
            }
        }

        // 3. Reasignar numero_tanda y fecha_programada a los PENDIENTE con sentencias preparadas
        $enviados_hoy = 0;
        $stmtHoy = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
        if ($stmtHoy) {
            $stmtHoy->bind_param('i', $id_sede);
            if (@$stmtHoy->execute()) {
                $rH = $stmtHoy->get_result();
                if ($rH && ($rh = $rH->fetch_assoc())) {
                    $enviados_hoy = intval($rh['c']);
                }
            }
            $stmtHoy->close();
        }
        $cupo_hoy = max(0, $nueva_cuota_diaria - $enviados_hoy);

        $pend_ids = [];
        $stmtPendIds = $con->prepare('SELECT id FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio = \'PENDIENTE\' ORDER BY id ASC');
        if ($stmtPendIds) {
            $stmtPendIds->bind_param('i', $id_campana);
            if (@$stmtPendIds->execute()) {
                $resPendIds = $stmtPendIds->get_result();
                if ($resPendIds) {
                    while ($r_pi = $resPendIds->fetch_assoc()) {
                        $pend_ids[] = intval($r_pi['id']);
                    }
                }
            }
            $stmtPendIds->close();
        }

        $total_pend = count($pend_ids);
        $cursor_p = 0;
        $num_tanda_actual = 1;
        $dia_offset = 0;

        if ($total_pend > 0) {
            if ($cupo_hoy > 0) {
                $slice_1 = array_slice($pend_ids, 0, $cupo_hoy);
                if (!empty($slice_1)) {
                    $fecha_t1 = date('Y-m-d');
                    $tanda_uno = 1;
                    foreach (array_chunk($slice_1, 400) as $ch_1) {
                        $ph1 = implode(',', array_fill(0, count($ch_1), '?'));
                        $types1 = 'is' . str_repeat('i', count($ch_1));
                        $params1 = array_merge([$tanda_uno, $fecha_t1], $ch_1);
                        $stmtUpT1 = $con->prepare('UPDATE campanas_difusion_destinatarios SET numero_tanda = ?, fecha_programada = ? WHERE id IN (' . $ph1 . ')');
                        if ($stmtUpT1) {
                            $stmtUpT1->bind_param($types1, ...$params1);
                            @$stmtUpT1->execute();
                            $stmtUpT1->close();
                        }
                    }
                    $cursor_p += count($slice_1);
                    $num_tanda_actual = 2;
                    $dia_offset = 1;
                }
            } else {
                $dia_offset = 1;
            }

            while ($cursor_p < $total_pend) {
                $slice_t = array_slice($pend_ids, $cursor_p, $nueva_cuota_diaria);
                if (empty($slice_t)) {
                    break;
                }
                $fecha_t = date('Y-m-d', strtotime("+{$dia_offset} days"));
                foreach (array_chunk($slice_t, 400) as $ch_t) {
                    $phT = implode(',', array_fill(0, count($ch_t), '?'));
                    $typesT = 'is' . str_repeat('i', count($ch_t));
                    $paramsT = array_merge([$num_tanda_actual, $fecha_t], $ch_t);
                    $stmtUpTn = $con->prepare('UPDATE campanas_difusion_destinatarios SET numero_tanda = ?, fecha_programada = ? WHERE id IN (' . $phT . ')');
                    if ($stmtUpTn) {
                        $stmtUpTn->bind_param($typesT, ...$paramsT);
                        @$stmtUpTn->execute();
                        $stmtUpTn->close();
                    }
                }
                $cursor_p += count($slice_t);
                $num_tanda_actual++;
                $dia_offset++;
            }
        }

        $nuevas_tandas_totales = max(1, ($num_tanda_actual > 1 ? $num_tanda_actual - 1 : 1));

        recalcularMetricasCampana($con, $id_campana);
        $nuevo_total_dest = 0;
        $nuevo_pend_dest = 0;
        $stmtCnt = $con->prepare('SELECT COUNT(*) AS tot, COALESCE(SUM(CASE WHEN estado_envio = \'PENDIENTE\' THEN 1 ELSE 0 END), 0) AS pend FROM campanas_difusion_destinatarios WHERE id_campana = ?');
        if ($stmtCnt) {
            $stmtCnt->bind_param('i', $id_campana);
            if (@$stmtCnt->execute()) {
                $resCnt = $stmtCnt->get_result();
                if ($resCnt && ($rcnt = $resCnt->fetch_assoc())) {
                    $nuevo_total_dest = intval($rcnt['tot']);
                    $nuevo_pend_dest = intval($rcnt['pend']);
                }
            }
            $stmtCnt->close();
        }
        $nuevo_costo_est = round($nuevo_total_dest * $costo_unit, 4);

        // Construir UPDATE parametrizado para campanas_difusion
        $upd_sets = [
            'nombre_campana = ?',
            'cuota_diaria_programada = ?',
            'total_tandas = ?',
            'hora_programada = ?',
            'piloto_automatico = ?',
            'presupuesto_max_usd = ?',
            'total_destinatarios = ?',
            'pendientes = ?',
            'costo_estimado_usd = ?'
        ];
        $upd_types = 'siisidiid';
        $upd_vals = [
            $nuevo_nombre,
            $nueva_cuota_diaria,
            $nuevas_tandas_totales,
            $nueva_hora_raw,
            $nuevo_piloto,
            $nuevo_presupuesto,
            $nuevo_total_dest,
            $nuevo_pend_dest,
            $nuevo_costo_est
        ];

        if (!empty($_POST['param_cuerpo']) || !empty($_POST['param_eslogan'])) {
            $p_cuerpo = sanitizeMetaTemplateParam($_POST['param_cuerpo'] ?? $camp['param_cuerpo'], true);
            $p_eslogan = sanitizeMetaTemplateParam($_POST['param_eslogan'] ?? $camp['param_eslogan'], true);
            $upd_sets[] = 'param_cuerpo = ?';
            $upd_sets[] = 'param_eslogan = ?';
            $upd_types .= 'ss';
            $upd_vals[] = $p_cuerpo;
            $upd_vals[] = $p_eslogan;

            $tpl_json_curr = !empty($camp['template_params_json']) ? json_decode((string)$camp['template_params_json'], true) : [];
            if (is_array($tpl_json_curr) && isset($tpl_json_curr['body_params']) && is_array($tpl_json_curr['body_params'])) {
                if (count($tpl_json_curr['body_params']) >= 3) {
                    $tpl_json_curr['body_params'][1] = $p_cuerpo;
                    $tpl_json_curr['body_params'][2] = $p_eslogan;
                } elseif (count($tpl_json_curr['body_params']) === 2) {
                    $tpl_json_curr['body_params'][0] = $p_cuerpo;
                    $tpl_json_curr['body_params'][1] = $p_eslogan;
                } elseif (count($tpl_json_curr['body_params']) === 1) {
                    $tpl_json_curr['body_params'][0] = $p_cuerpo;
                }
                $json_upd_str = json_encode($tpl_json_curr, JSON_UNESCAPED_UNICODE);
                $upd_sets[] = 'template_params_json = ?';
                $upd_types .= 's';
                $upd_vals[] = $json_upd_str;
            }
        }

        if ($new_rel_path_edit !== null) {
            $upd_sets[] = 'header_imagen_path = ?';
            $upd_sets[] = 'header_media_id = NULL';
            $upd_types .= 's';
            $upd_vals[] = $new_rel_path_edit;
        }

        $upd_types .= 'i';
        $upd_vals[] = $id_campana;

        $stmtUpdCamp = $con->prepare('UPDATE campanas_difusion SET ' . implode(', ', $upd_sets) . ' WHERE id = ?');
        if ($stmtUpdCamp) {
            $stmtUpdCamp->bind_param($upd_types, ...$upd_vals);
            if (!@$stmtUpdCamp->execute()) {
                starfi_log_exception('campanas', 'Error actualizando campaña en edit_pending_campaign', ['error' => $stmtUpdCamp->error]);
            }
            $stmtUpdCamp->close();
        }

        $datos_nuevos_edit = obtenerCampanaPorId($con, $id_campana) ?? ['id' => $id_campana];
        $datos_nuevos_edit['destinatarios_recortados_eliminados'] = $total_recortados_eliminados;

        registrar_auditoria(
            'campanas',
            'EDITAR_CAMPANA',
            "Edición de campaña #{$id_campana}: {$nuevo_nombre} ({$nuevo_total_dest} contactos, {$nuevas_tandas_totales} tandas)",
            $datos_anteriores_edit,
            $datos_nuevos_edit,
            $con,
            $id_usuario,
            $id_sede
        );

        echo json_encode([
            'status' => 'success',
            'message' => "Campaña actualizada: {$nuevo_total_dest} contactos en {$nuevas_tandas_totales} tanda(s) de hasta {$nueva_cuota_diaria}/día (\${$nuevo_costo_est} USD).",
            'id_campana' => $id_campana,
            'total_destinatarios' => $nuevo_total_dest,
            'cuota_diaria_programada' => $nueva_cuota_diaria,
            'total_tandas' => $nuevas_tandas_totales,
            'costo_estimado_usd' => $nuevo_costo_est
        ]);
        break;

    // =========================================================================
    // 4.3 ALTERNAR PILOTO AUTOMÁTICO DIARIO DE UNA CAMPAÑA
    // =========================================================================
    case 'toggle_autopilot':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        $activo = intval($_POST['piloto_automatico'] ?? 1) === 1 ? 1 : 0;
        if ($id_campana > 0) {
            $camp_ant = obtenerCampanaPorId($con, $id_campana);
            if ($camp_ant) {
                $id_sede_t = intval($camp_ant['id_sede']);
                if (!$es_global && $id_sede_usuario > 0 && $id_sede_t !== $id_sede_usuario) {
                    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado a campaña de otra sede.']);
                    exit;
                }
                $stmtTog = $con->prepare('UPDATE campanas_difusion SET piloto_automatico = ? WHERE id = ?');
                if ($stmtTog) {
                    $stmtTog->bind_param('ii', $activo, $id_campana);
                    @$stmtTog->execute();
                    $stmtTog->close();
                }
                registrar_auditoria(
                    'campanas',
                    'TOGGLE_PILOTO_AUTOMATICO',
                    "Cambio de Piloto Automático en campaña #{$id_campana} a " . ($activo ? 'ACTIVO' : 'INACTIVO'),
                    ['id_campana' => $id_campana, 'piloto_automatico' => intval($camp_ant['piloto_automatico'] ?? 1)],
                    ['id_campana' => $id_campana, 'piloto_automatico' => $activo],
                    $con,
                    $id_usuario,
                    $id_sede_t
                );
            }
        }
        echo json_encode(['status' => 'success', 'piloto_automatico' => $activo]);
        break;

    // =========================================================================
    // 4.4 CONSULTAR SI HAY LOTES PENDIENTES HOY DE CAMPAÑAS APROBADAS EN PILOTO AUTOMÁTICO
    // =========================================================================
    case 'check_autopilot_due':
        $stmtUnstickAp = $con->prepare(
            'UPDATE campanas_difusion_destinatarios
             SET estado_envio = \'PENDIENTE\'
             WHERE estado_envio = \'PROCESANDO\'
               AND (id_mensaje_meta IS NULL OR id_mensaje_meta = \'\')
               AND fecha_envio < DATE_SUB(NOW(), INTERVAL 3 MINUTE)'
        );
        if ($stmtUnstickAp) {
            @$stmtUnstickAp->execute();
            $stmtUnstickAp->close();
        }

        $filtro_sede = intval($_GET['id_sede'] ?? $_POST['id_sede'] ?? 0);
        if (!$es_global && $id_sede_usuario > 0) {
            $filtro_sede = $id_sede_usuario;
        }

        if ($filtro_sede > 0) {
            $stmtAp = $con->prepare(
                'SELECT c.id, c.id_sede, c.nombre_campana, c.cuota_diaria_programada, c.hora_programada, c.pendientes, c.total_tandas
                 FROM campanas_difusion c
                 WHERE c.piloto_automatico = 1
                   AND c.aprobado_por IS NOT NULL
                   AND c.pendientes > 0
                   AND c.estado IN (\'PILOTO_AUTOMATICO\',\'EN_COLA_DIARIA\',\'APROBADA_EN_PROCESO\',\'PAUSADA_CUOTA_DIARIA\')
                   AND IFNULL(c.hora_programada, \'00:00:00\') <= CURTIME()
                   AND NOT EXISTS (
                       SELECT 1 FROM campanas_difusion_destinatarios d_act
                       WHERE d_act.id_campana = c.id
                         AND d_act.fecha_envio >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
                   )
                   AND c.id_sede = ?
                 ORDER BY c.id ASC'
            );
            if ($stmtAp) {
                $stmtAp->bind_param('i', $filtro_sede);
            }
        } else {
            $stmtAp = $con->prepare(
                'SELECT c.id, c.id_sede, c.nombre_campana, c.cuota_diaria_programada, c.hora_programada, c.pendientes, c.total_tandas
                 FROM campanas_difusion c
                 WHERE c.piloto_automatico = 1
                   AND c.aprobado_por IS NOT NULL
                   AND c.pendientes > 0
                   AND c.estado IN (\'PILOTO_AUTOMATICO\',\'EN_COLA_DIARIA\',\'APROBADA_EN_PROCESO\',\'PAUSADA_CUOTA_DIARIA\')
                   AND IFNULL(c.hora_programada, \'00:00:00\') <= CURTIME()
                   AND NOT EXISTS (
                       SELECT 1 FROM campanas_difusion_destinatarios d_act
                       WHERE d_act.id_campana = c.id
                         AND d_act.fecha_envio >= DATE_SUB(NOW(), INTERVAL 90 SECOND)
                   )
                 ORDER BY c.id ASC'
            );
        }

        $ap_rows = [];
        if ($stmtAp) {
            if (@$stmtAp->execute()) {
                $resAp = $stmtAp->get_result();
                if ($resAp) {
                    while ($cap = $resAp->fetch_assoc()) {
                        $ap_rows[] = $cap;
                    }
                }
            }
            $stmtAp->close();
        }

        $campanas_listas = [];
        foreach ($ap_rows as $cap) {
            $sid = intval($cap['id_sede']);
            $cid = intval($cap['id']);
            $lim_sede = 250;
            $cad_sede = 1000;
            $stmtQ = $con->prepare('SELECT limite_diario_mensajes, cadencia_ms FROM campanas_cuotas_sede WHERE id_sede = ? LIMIT 1');
            if ($stmtQ) {
                $stmtQ->bind_param('i', $sid);
                if (@$stmtQ->execute()) {
                    $rQ = $stmtQ->get_result();
                    if ($rQ && ($rq = $rQ->fetch_assoc())) {
                        $lim_sede = max(1, intval($rq['limite_diario_mensajes']));
                        $cad_sede = max(250, intval($rq['cadencia_ms']));
                    }
                }
                $stmtQ->close();
            }

            $cuota_c = max(1, intval($cap['cuota_diaria_programada'] ?: $lim_sede));
            if ($cuota_c > $lim_sede) {
                $lim_sede = $cuota_c;
            }
            $tope_hoy = min($lim_sede, $cuota_c);

            $env_sede_hoy = 0;
            $stmtHs = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
            if ($stmtHs) {
                $stmtHs->bind_param('i', $sid);
                if (@$stmtHs->execute()) {
                    $r1Res = $stmtHs->get_result();
                    if ($r1Res && ($r1 = $r1Res->fetch_assoc())) {
                        $env_sede_hoy = intval($r1['c']);
                    }
                }
                $stmtHs->close();
            }

            $env_camp_hoy = 0;
            $stmtHc = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
            if ($stmtHc) {
                $stmtHc->bind_param('i', $cid);
                if (@$stmtHc->execute()) {
                    $r2Res = $stmtHc->get_result();
                    if ($r2Res && ($r2 = $r2Res->fetch_assoc())) {
                        $env_camp_hoy = intval($r2['c']);
                    }
                }
                $stmtHc->close();
            }

            $disp_hoy = min(max(0, $lim_sede - $env_sede_hoy), max(0, $tope_hoy - $env_camp_hoy));
            if ($disp_hoy > 0) {
                $campanas_listas[] = [
                    'id_campana' => $cid,
                    'nombre_campana' => $cap['nombre_campana'],
                    'disponibles_hoy' => min($disp_hoy, intval($cap['pendientes'])),
                    'cadencia_ms' => $cad_sede
                ];
            }
        }
        echo json_encode(['status' => 'success', 'autopilot_campaigns' => $campanas_listas]);
        break;

    // =========================================================================
    // 5. DESPACHAR UN DESTINATARIO DE LA COLA (CON ESCUDO ANTI-FATIGA 24H EN BACKEND)
    // =========================================================================
    case 'dispatch_queue_item':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        $id_destinatario = intval($_POST['id_destinatario'] ?? 0);
        $language_code = trim((string)($_POST['language_code'] ?? 'es')) ?: 'es';

        if ($id_campana <= 0 || $id_destinatario <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros de cola inválidos.']);
            exit;
        }

        $camp = obtenerCampanaPorId($con, $id_campana);
        $dest = null;
        $stmtDest = $con->prepare('SELECT * FROM campanas_difusion_destinatarios WHERE id = ? AND id_campana = ? LIMIT 1');
        if ($stmtDest) {
            $stmtDest->bind_param('ii', $id_destinatario, $id_campana);
            if (@$stmtDest->execute()) {
                $resDest = $stmtDest->get_result();
                if ($resDest && $resDest->num_rows > 0) {
                    $dest = $resDest->fetch_assoc();
                }
            }
            $stmtDest->close();
        }

        if (!$camp || !$dest) {
            echo json_encode(['status' => 'error', 'message' => 'No se encontró la campaña o el destinatario.']);
            exit;
        }
        $dest_antes = $dest;
        $id_sede = intval($camp['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: esta campaña pertenece a otra sede.']);
            exit;
        }

        if ($camp['estado'] === 'PENDIENTE_APROBACION' || empty($camp['aprobado_por'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Esta campaña aún se encuentra PENDIENTE DE APROBACIÓN. Debe ser aprobada antes de iniciar el envío.'
            ]);
            exit;
        }

        $limite_diario_sede = 250;
        $stmtQ = $con->prepare('SELECT limite_diario_mensajes FROM campanas_cuotas_sede WHERE id_sede = ? LIMIT 1');
        if ($stmtQ) {
            $stmtQ->bind_param('i', $id_sede);
            if (@$stmtQ->execute()) {
                $rQ = $stmtQ->get_result();
                if ($rQ && ($rq = $rQ->fetch_assoc())) {
                    $limite_diario_sede = max(1, intval($rq['limite_diario_mensajes']));
                }
            }
            $stmtQ->close();
        }
        $cuota_campana = max(1, intval($camp['cuota_diaria_programada'] ?? $limite_diario_sede));
        if ($cuota_campana > $limite_diario_sede) {
            $limite_diario_sede = $cuota_campana;
        }
        $limite_diario = min($limite_diario_sede, $cuota_campana);

        $enviados_hoy_sede = 0;
        $stmtHs = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
        if ($stmtHs) {
            $stmtHs->bind_param('i', $id_sede);
            if (@$stmtHs->execute()) {
                $rHs = $stmtHs->get_result();
                if ($rHs && ($rh = $rHs->fetch_assoc())) {
                    $enviados_hoy_sede = intval($rh['c']);
                }
            }
            $stmtHs->close();
        }

        $enviados_hoy_camp = 0;
        $stmtHc = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
        if ($stmtHc) {
            $stmtHc->bind_param('i', $id_campana);
            if (@$stmtHc->execute()) {
                $rHc = $stmtHc->get_result();
                if ($rHc && ($rhc = $rHc->fetch_assoc())) {
                    $enviados_hoy_camp = intval($rhc['c']);
                }
            }
            $stmtHc->close();
        }
        $enviados_hoy = $enviados_hoy_sede;

        if ($enviados_hoy_sede >= $limite_diario_sede || $enviados_hoy_camp >= $cuota_campana) {
            $est_pausa = (intval($camp['piloto_automatico'] ?? 1) === 1) ? 'PILOTO_AUTOMATICO' : 'PAUSADA_CUOTA_DIARIA';
            $stmtPausa = $con->prepare('UPDATE campanas_difusion SET estado = ? WHERE id = ?');
            if ($stmtPausa) {
                $stmtPausa->bind_param('si', $est_pausa, $id_campana);
                @$stmtPausa->execute();
                $stmtPausa->close();
            }
            registrar_auditoria(
                'campanas',
                'PAUSAR_CUOTA_DIARIA',
                "Tanda diaria completada ({$cuota_campana} msgs/día) en campaña #{$id_campana}",
                ['id_campana' => $id_campana, 'estado' => $camp['estado']],
                ['id_campana' => $id_campana, 'estado' => $est_pausa],
                $con,
                $id_usuario,
                $id_sede
            );
            echo json_encode([
                'status' => 'quota_reached',
                'message' => "Tanda diaria completada ({$cuota_campana} msgs/día). Los destinatarios restantes quedan programados en Piloto Automático para la siguiente tanda diaria."
            ]);
            exit;
        }

        $presupuesto_max = floatval($camp['presupuesto_max_usd']);
        $costo_unit = floatval($camp['costo_unitario_usd']);
        $costo_actual = floatval($camp['costo_real_usd']);
        if ($presupuesto_max > 0 && ($costo_actual + $costo_unit) > ($presupuesto_max + 0.0001)) {
            $stmtBud = $con->prepare('UPDATE campanas_difusion SET estado = \'TOPE_PRESUPUESTO\' WHERE id = ?');
            if ($stmtBud) {
                $stmtBud->bind_param('i', $id_campana);
                @$stmtBud->execute();
                $stmtBud->close();
            }
            registrar_auditoria(
                'campanas',
                'PAUSAR_TOPE_PRESUPUESTO',
                "Tope de presupuesto alcanzado en campaña #{$id_campana}",
                ['id_campana' => $id_campana, 'estado' => $camp['estado'], 'costo_real_usd' => $costo_actual],
                ['id_campana' => $id_campana, 'estado' => 'TOPE_PRESUPUESTO', 'presupuesto_max_usd' => $presupuesto_max],
                $con,
                $id_usuario,
                $id_sede
            );
            echo json_encode([
                'status' => 'budget_reached',
                'message' => 'Se alcanzó el tope de presupuesto asignado a la campaña ($' . number_format($presupuesto_max, 2) . ' USD).'
            ]);
            exit;
        }

        $linea = null;
        $stmtLin = $con->prepare(
            'SELECT l.id, l.meta_app_id, l.id_negocio, l.meta_token, l.numero_telefono, COALESCE(s.id_empresa, 1) AS id_empresa, COALESCE(s.nombre_sede, \'Sede\') AS nombre_sede
             FROM lineas_whatsapp l
             LEFT JOIN sedes s ON s.id = l.id_sede
             WHERE l.id_sede = ? AND l.estado = \'ACTIVO\'
             LIMIT 1'
        );
        if ($stmtLin) {
            $stmtLin->bind_param('i', $id_sede);
            if (@$stmtLin->execute()) {
                $resLin = $stmtLin->get_result();
                if ($resLin && $resLin->num_rows > 0) {
                    $linea = $resLin->fetch_assoc();
                }
            }
            $stmtLin->close();
        }
        if (!$linea) {
            echo json_encode(['status' => 'error', 'message' => 'No hay línea activa en la sede.']);
            exit;
        }

        $id_linea = intval($linea['id']);
        $id_empresa = intval($linea['id_empresa'] ?? 1);
        $nombre_sede_actual = trim((string)($linea['nombre_sede'] ?? 'Sede'));
        $phone_id = trim((string)$linea['meta_app_id']);
        $waba_id_linea = trim((string)($linea['id_negocio'] ?? ''));
        $token = !empty($linea['meta_token']) ? trim((string)$linea['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

        if ($waba_id_linea !== '' && $token !== '' && intval($camp['enviados'] ?? 0) === 0 && empty($waba_sub_cache['waba_sub_verified_' . $waba_id_linea])) {
            $ch_sub = curl_init("https://graph.facebook.com/{$apiVerGlobal}/{$waba_id_linea}/subscribed_apps");
            curl_setopt($ch_sub, CURLOPT_POST, true);
            curl_setopt($ch_sub, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}"]);
            curl_setopt($ch_sub, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_sub, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch_sub, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch_sub, CURLOPT_TIMEOUT, 3);
            @curl_exec($ch_sub);
            curl_close($ch_sub);
        }

        $telefono_limpio = normalizeVenezuelanPhone($dest['telefono']);
        $template_name = trim((string)($camp['template_name'] ?: 'starfi_difusion_directa'));

        if (strtoupper(trim((string)($dest['estado_envio'] ?? ''))) !== 'PENDIENTE' || !empty($dest['id_mensaje_meta'])) {
            echo json_encode([
                'status' => 'already_processed',
                'skipped' => true,
                'telefono' => $telefono_limpio,
                'message' => 'Este destinatario ya fue procesado por otra pestaña o lote en curso.'
            ]);
            exit;
        }

        // Reclamo atómico de la fila
        $claimed = false;
        $stmtClaim = $con->prepare(
            'UPDATE campanas_difusion_destinatarios
             SET estado_envio = \'PROCESANDO\', fecha_envio = NOW()
             WHERE id = ? AND id_campana = ? AND estado_envio = \'PENDIENTE\' AND (id_mensaje_meta IS NULL OR id_mensaje_meta = \'\')'
        );
        if ($stmtClaim) {
            $stmtClaim->bind_param('ii', $id_destinatario, $id_campana);
            if (@$stmtClaim->execute()) {
                $claimed = ($stmtClaim->affected_rows > 0);
            }
            $stmtClaim->close();
        }
        if (!$claimed) {
            echo json_encode([
                'status' => 'already_processed',
                'skipped' => true,
                'telefono' => $telefono_limpio,
                'message' => 'Destinatario tomado simultáneamente por otro proceso activo.'
            ]);
            exit;
        }

        $camp_cat = strtoupper(trim((string)($camp['categoria_meta'] ?? ($camp['template_category'] ?? 'MARKETING')))) ?: 'MARKETING';

        // ESCUDO ANTI-FATIGA 24H EN BACKEND ANTES DE DISPARAR A META GRAPH API (QA-BUG-CRM2-08 / CHK-CLD-02)
        if ($camp_cat === 'MARKETING' && starfi_check_marketing_antifatigue_24h($con, $telefono_limpio, $id_destinatario)) {
            $err_antifatiga = 'Omitido por Escudo Anti-Fatiga 24h (Meta 131049)';
            $stmtSkipFat = $con->prepare(
                'UPDATE campanas_difusion_destinatarios
                 SET estado_envio = \'OMITIDO_ANTIFATIGA\',
                     error_detalle = ?,
                     costo_usd = 0.0000
                 WHERE id = ?'
            );
            if ($stmtSkipFat) {
                $stmtSkipFat->bind_param('si', $err_antifatiga, $id_destinatario);
                @$stmtSkipFat->execute();
                $stmtSkipFat->close();
            }
            actualizarContadoresRapidosCampana($con, $id_campana);
            registrar_auditoria(
                'campanas',
                'OMITIR_DESTINATARIO_ANTIFATIGA',
                "Destinatario #{$id_destinatario} ({$telefono_limpio}) omitido por Escudo Anti-Fatiga 24h en campaña #{$id_campana}",
                ['id_destinatario' => $id_destinatario, 'estado_envio' => $dest_antes['estado_envio'], 'telefono' => $telefono_limpio],
                ['id_destinatario' => $id_destinatario, 'estado_envio' => 'OMITIDO_ANTIFATIGA', 'error_detalle' => $err_antifatiga],
                $con,
                $id_usuario,
                $id_sede
            );
            echo json_encode([
                'status' => 'already_processed',
                'skipped' => true,
                'reason' => 'antifatigue_24h',
                'telefono' => $telefono_limpio,
                'message' => $err_antifatiga
            ]);
            exit;
        }

        // Verificar anti-duplicado en la misma campaña o plantilla hoy
        $es_duplicado_hoy = false;
        $stmtDupTel = $con->prepare(
            'SELECT id FROM campanas_difusion_destinatarios
             WHERE id_campana = ?
               AND id != ?
               AND telefono = ?
               AND estado_envio IN (\'PROCESANDO\',\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\')
             LIMIT 1'
        );
        if ($stmtDupTel) {
            $stmtDupTel->bind_param('iis', $id_campana, $id_destinatario, $telefono_limpio);
            if (@$stmtDupTel->execute()) {
                $stmtDupTel->store_result();
                if ($stmtDupTel->num_rows > 0) {
                    $es_duplicado_hoy = true;
                }
            }
            $stmtDupTel->close();
        }

        if (!$es_duplicado_hoy) {
            $stmtDupNotif = $con->prepare(
                'SELECT id FROM notificacion_enviada
                 WHERE fecha = CURDATE()
                   AND telefono_receptor = ?
                   AND template = ?
                   AND status = \'ENVIADO\'
                 LIMIT 1'
            );
            if ($stmtDupNotif) {
                $stmtDupNotif->bind_param('ss', $telefono_limpio, $template_name);
                if (@$stmtDupNotif->execute()) {
                    $stmtDupNotif->store_result();
                    if ($stmtDupNotif->num_rows > 0) {
                        $es_duplicado_hoy = true;
                    }
                }
                $stmtDupNotif->close();
            }
        }

        if ($es_duplicado_hoy) {
            $msg_dup = 'Omitido automáticamente: número ya recibió esta difusión hoy (protección anti-duplicado).';
            $stmtSkipDup = $con->prepare(
                'UPDATE campanas_difusion_destinatarios
                 SET estado_envio = \'ENVIADO\',
                     error_detalle = ?
                 WHERE id = ?'
            );
            if ($stmtSkipDup) {
                $stmtSkipDup->bind_param('si', $msg_dup, $id_destinatario);
                @$stmtSkipDup->execute();
                $stmtSkipDup->close();
            }
            actualizarContadoresRapidosCampana($con, $id_campana);
            registrar_auditoria(
                'campanas',
                'OMITIR_DESTINATARIO_DUPLICADO',
                "Destinatario #{$id_destinatario} ({$telefono_limpio}) omitido por protección anti-duplicado en campaña #{$id_campana}",
                ['id_destinatario' => $id_destinatario, 'estado_envio' => $dest_antes['estado_envio']],
                ['id_destinatario' => $id_destinatario, 'estado_envio' => 'ENVIADO', 'error_detalle' => $msg_dup],
                $con,
                $id_usuario,
                $id_sede
            );
            echo json_encode([
                'status' => 'already_processed',
                'skipped' => true,
                'telefono' => $telefono_limpio,
                'message' => 'Número ya notificado hoy en esta campaña (duplicado evitado).'
            ]);
            exit;
        }

        $nombre_cliente = trim((string)($dest['nombre'] ?: 'Cliente'));
        $primer_nombre = explode(' ', $nombre_cliente)[0];
        if (empty($primer_nombre) || strlen($primer_nombre) < 2 || preg_match('/^\+?[0-9\s\-]+$/', $primer_nombre)) {
            $primer_nombre = 'Estimado(a) cliente';
        }
        $primer_nombre = sanitizeMetaTemplateParam($primer_nombre, false);

        $camp_lang = trim((string)($camp['template_language'] ?? '')) ?: $language_code;
        $camp_hdr_fmt = strtoupper(trim((string)($camp['header_format'] ?? 'IMAGE'))) ?: 'IMAGE';

        $resolveDynamicToken = function ($val_raw) use ($primer_nombre, $nombre_cliente, $telefono_limpio, $nombre_sede_actual) {
            $str = (string)$val_raw;
            $str = str_ireplace(['@NOMBRE_CLIENTE', '{{nombre_cliente}}'], $primer_nombre, $str);
            $str = str_ireplace(['@NOMBRE_COMPLETO', '{{nombre_completo}}'], $nombre_cliente, $str);
            $str = str_ireplace(['@TELEFONO_CLIENTE', '{{telefono_cliente}}'], $telefono_limpio, $str);
            $str = str_ireplace(['@NOMBRE_SEDE', '{{nombre_sede}}'], $nombre_sede_actual, $str);
            return sanitizeMetaTemplateParam($str, true);
        };

        $tpl_json_data = !empty($camp['template_params_json']) ? json_decode((string)$camp['template_params_json'], true) : null;
        $params_arr = [];
        $header_text_resolved = '';
        $texto_renderizado_log = '';

        if (is_array($tpl_json_data) && isset($tpl_json_data['body_params']) && is_array($tpl_json_data['body_params'])) {
            foreach ($tpl_json_data['body_params'] as $bp_raw) {
                $params_arr[] = $resolveDynamicToken($bp_raw);
            }
            if (!empty($tpl_json_data['header_text_param'])) {
                $header_text_resolved = sanitizeMetaTemplateParam($resolveDynamicToken($tpl_json_data['header_text_param']), false);
            }
            if (!empty($tpl_json_data['body_template'])) {
                $txt_r = (string)$tpl_json_data['body_template'];
                foreach ($params_arr as $idx_p => $val_p) {
                    $num_p = $idx_p + 1;
                    $txt_r = str_replace('{{' . $num_p . '}}', $val_p, $txt_r);
                }
                $texto_renderizado_log = trim($txt_r);
            }
        } else {
            $param_2 = sanitizeMetaTemplateParam($camp['param_cuerpo'], true);
            $param_3 = sanitizeMetaTemplateParam($camp['param_eslogan'], true);
            $params_arr = [$primer_nombre, $param_2, $param_3];
        }

        $url = "https://graph.facebook.com/{$apiVerGlobal}/{$phone_id}/messages";
        $headers = [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json'
        ];

        $primary_lang = ($camp_lang && !in_array($camp_lang, ['es_MX', 'es_LA'], true)) ? $camp_lang : 'es';
        $lang_candidates = array_values(array_unique([$primary_lang, 'es', 'es_ES']));
        $http_code = 0;
        $curl_err = '';
        $resp_data = [];
        $first_resp_data = null;

        $needs_image_header = ($camp_hdr_fmt === 'IMAGE' || $template_name === 'starfi_difusion_directa' || !empty($camp['header_imagen_path']) || !empty($camp['header_media_id']));
        if ($camp_hdr_fmt === 'NONE' || $camp_hdr_fmt === 'TEXT') {
            $needs_image_header = false;
        }
        $header_media_id = null;
        if ($needs_image_header) {
            $header_media_id = getOrUploadCampaignHeaderMediaId($con, $camp, $phone_id, $token);
        }

        $body_parameters_payload = [];
        foreach ($params_arr as $p_val) {
            $body_parameters_payload[] = ['type' => 'text', 'text' => $p_val];
        }

        foreach ($lang_candidates as $idx_lang => $try_lang) {
            for ($attempt_img = 0; $attempt_img < 2; $attempt_img++) {
                $components_payload = [];
                if ($needs_image_header && !empty($header_media_id)) {
                    $components_payload[] = [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'image', 'image' => ['id' => $header_media_id]]
                        ]
                    ];
                } elseif ($camp_hdr_fmt === 'TEXT' && $header_text_resolved !== '') {
                    $components_payload[] = [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $header_text_resolved]
                        ]
                    ];
                }
                if (!empty($body_parameters_payload)) {
                    $components_payload[] = [
                        'type' => 'body',
                        'parameters' => $body_parameters_payload
                    ];
                }

                $payload = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $telefono_limpio,
                    'type' => 'template',
                    'template' => [
                        'name' => $template_name,
                        'language' => ['code' => $try_lang],
                        'components' => $components_payload
                    ]
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
                curl_setopt($ch, CURLOPT_TIMEOUT, 8);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_err = curl_error($ch);
                curl_close($ch);

                $resp_data = json_decode((string)$response, true) ?? [];
                if ($idx_lang === 0 && $first_resp_data === null) {
                    $first_resp_data = $resp_data;
                }
                $err_code_meta = intval($resp_data['error']['code'] ?? 0);
                $err_details_meta = strtoupper((string)($resp_data['error']['error_data']['details'] ?? ($resp_data['error']['message'] ?? '')));

                if ($attempt_img === 0 && ($err_code_meta === 132012 || $err_code_meta === 131053 || $err_code_meta === 132000)) {
                    if (strpos($err_details_meta, 'EXPECTED IMAGE') !== false) {
                        $needs_image_header = true;
                        $camp['header_media_id'] = '';
                        $header_media_id = getOrUploadCampaignHeaderMediaId($con, $camp, $phone_id, $token);
                        continue;
                    } elseif (strpos($err_details_meta, 'EXPECTED UNKNOWN') !== false || strpos($err_details_meta, 'EXPECTED TEXT') !== false || ($err_code_meta === 132000 && $needs_image_header)) {
                        $needs_image_header = false;
                        $header_media_id = null;
                        continue;
                    } elseif ($err_code_meta === 131053 && $needs_image_header) {
                        $camp['header_media_id'] = '';
                        $header_media_id = getOrUploadCampaignHeaderMediaId($con, $camp, $phone_id, $token);
                        continue;
                    }
                }
                break;
            }

            $err_code_meta = intval($resp_data['error']['code'] ?? 0);
            if (($http_code >= 200 && $http_code < 300) || $err_code_meta !== 132001) {
                break;
            }
        }

        if (!($http_code >= 200 && $http_code < 300) && intval($resp_data['error']['code'] ?? 0) === 132001 && $first_resp_data !== null) {
            $resp_data = $first_resp_data;
        }

        $contenido_log = 'Envío dinámico de plantilla: ' . $template_name . '. Params: ' . json_encode($params_arr, JSON_UNESCAPED_UNICODE);
        if ($texto_renderizado_log !== '') {
            $contenido_log .= ' | Texto: ' . $texto_renderizado_log;
        }
        $fecha_hoy = date('Y-m-d');
        $hora_actual = date('H:i:s');
        $now_full = "{$fecha_hoy} {$hora_actual}";

        if ($http_code >= 200 && $http_code < 300 && isset($resp_data['messages'][0]['id'])) {
            $wamid = (string)$resp_data['messages'][0]['id'];

            $stmtNotifOk = $con->prepare(
                'INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                 VALUES (?, ?, ?, ?, ?, ?, \'ENVIADO\')'
            );
            if ($stmtNotifOk) {
                $stmtNotifOk->bind_param('ssssss', $template_name, $fecha_hoy, $hora_actual, $phone_id, $telefono_limpio, $contenido_log);
                @$stmtNotifOk->execute();
                $stmtNotifOk->close();
            }

            // Asegurar cliente en clientes_contactos
            $id_cliente = intval($dest['id_cliente']);
            if ($id_cliente <= 0) {
                $like_tel = '%' . $telefono_limpio . '%';
                $row_cli = null;
                $stmtCFind = $con->prepare('SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = ? OR numero_whatsapp LIKE ? LIMIT 1');
                if ($stmtCFind) {
                    $stmtCFind->bind_param('ss', $telefono_limpio, $like_tel);
                    if (@$stmtCFind->execute()) {
                        $resCFind = $stmtCFind->get_result();
                        if ($resCFind && $resCFind->num_rows > 0) {
                            $row_cli = $resCFind->fetch_assoc();
                        }
                    }
                    $stmtCFind->close();
                }

                if ($row_cli) {
                    $id_cliente = intval($row_cli['id']);
                    if ((empty($row_cli['nombre']) || $row_cli['nombre'] === 'Usuario' || $row_cli['nombre'] === 'Cliente') && $primer_nombre !== 'Estimado(a) cliente') {
                        $stmtUpdCli = $con->prepare('UPDATE clientes_contactos SET nombre = ?, id_sede = COALESCE(id_sede, ?), ultimo_contacto = ? WHERE id = ?');
                        if ($stmtUpdCli) {
                            $stmtUpdCli->bind_param('sisi', $nombre_cliente, $id_sede, $now_full, $id_cliente);
                            @$stmtUpdCli->execute();
                            $stmtUpdCli->close();
                        }
                    } else {
                        $stmtUpdCli = $con->prepare('UPDATE clientes_contactos SET ultimo_contacto = ? WHERE id = ?');
                        if ($stmtUpdCli) {
                            $stmtUpdCli->bind_param('si', $now_full, $id_cliente);
                            @$stmtUpdCli->execute();
                            $stmtUpdCli->close();
                        }
                    }
                } else {
                    $nom_insert_param = ($primer_nombre !== 'Estimado(a) cliente') ? $nombre_cliente : null;
                    $stmtInsCli = $con->prepare(
                        'INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre, fecha_registro, ultimo_contacto)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    if ($stmtInsCli) {
                        $stmtInsCli->bind_param('iissss', $id_empresa, $id_sede, $telefono_limpio, $nom_insert_param, $now_full, $now_full);
                        if (@$stmtInsCli->execute()) {
                            $id_cliente = intval($stmtInsCli->insert_id);
                        }
                        $stmtInsCli->close();
                    }
                }
            } else {
                $stmtUpdCli = $con->prepare('UPDATE clientes_contactos SET ultimo_contacto = ? WHERE id = ?');
                if ($stmtUpdCli) {
                    $stmtUpdCli->bind_param('si', $now_full, $id_cliente);
                    @$stmtUpdCli->execute();
                    $stmtUpdCli->close();
                }
            }

            // Asegurar conversación abierta/asociada a la línea de la sede
            $id_conversacion = 0;
            if ($id_cliente > 0) {
                $stmtConv = $con->prepare('SELECT id FROM conversaciones WHERE id_cliente = ? AND id_linea = ? AND estado NOT IN (\'CERRADO\', \'RESUELTO\') ORDER BY id DESC LIMIT 1');
                if ($stmtConv) {
                    $stmtConv->bind_param('ii', $id_cliente, $id_linea);
                    if (@$stmtConv->execute()) {
                        $resConv = $stmtConv->get_result();
                        if ($resConv && ($rowConv = $resConv->fetch_assoc())) {
                            $id_conversacion = intval($rowConv['id']);
                        }
                    }
                    $stmtConv->close();
                }

                if ($id_conversacion <= 0) {
                    $stmtInsConv = $con->prepare('INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos, fecha_inicio) VALUES (?, ?, \'ESPERA_ASIGNACION\', 0, ?)');
                    if ($stmtInsConv) {
                        $stmtInsConv->bind_param('iis', $id_linea, $id_cliente, $now_full);
                        if (@$stmtInsConv->execute()) {
                            $id_conversacion = intval($stmtInsConv->insert_id);
                        }
                        $stmtInsConv->close();
                    }
                }
            }

            $rel_media_bandeja = null;
            if ($needs_image_header) {
                if (!empty($camp['header_imagen_path'])) {
                    $rel_media_bandeja = '../../' . stripLeadingAppSubpath($camp['header_imagen_path']);
                } else {
                    $abs_img_used = resolveCampaignHeaderImagePath($con, $camp);
                    $base_root = str_replace('\\', '/', realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
                    $abs_norm = $abs_img_used ? str_replace('\\', '/', $abs_img_used) : '';
                    if ($abs_norm !== '' && strpos($abs_norm, $base_root) === 0) {
                        $rel_media_bandeja = '../../' . ltrim(substr($abs_norm, strlen($base_root)), '/');
                    }
                }
            }

            if ($id_conversacion > 0) {
                $tipo_msg_bandeja = !empty($rel_media_bandeja) ? 'IMAGEN' : 'TEXTO';
                $stmtInsEv = $con->prepare(
                    'INSERT INTO mensajes_y_eventos
                        (id_conversacion, id_mensaje_meta, tipo, origen, id_agente, contenido, url_archivo, estado_envio, categoria_meta, es_pagado, costo_meta_estimado, timestamp)
                     VALUES (?, ?, ?, \'API_TRANSACCIONAL\', ?, ?, ?, \'ENVIADO\', ?, 1, ?, ?)'
                );
                if ($stmtInsEv) {
                    $stmtInsEv->bind_param(
                        'ississsds',
                        $id_conversacion,
                        $wamid,
                        $tipo_msg_bandeja,
                        $id_usuario,
                        $contenido_log,
                        $rel_media_bandeja,
                        $camp_cat,
                        $costo_unit,
                        $now_full
                    );
                    if (@$stmtInsEv->execute()) {
                        $new_camp_msg_id = intval($stmtInsEv->insert_id);
                        if ($new_camp_msg_id > 0) {
                            $stmtSyncU = $con->prepare('UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?) WHERE id = ? AND IFNULL(ultimo_msg_id, 0) < ?');
                            if ($stmtSyncU) {
                                $stmtSyncU->bind_param('iii', $new_camp_msg_id, $id_conversacion, $new_camp_msg_id);
                                @$stmtSyncU->execute();
                                $stmtSyncU->close();
                            }
                        }
                    }
                    $stmtInsEv->close();
                }
            }

            $stmtUpdDestOk = $con->prepare(
                'UPDATE campanas_difusion_destinatarios
                 SET id_cliente = ?,
                     id_conversacion = ?,
                     telefono = ?,
                     id_mensaje_meta = ?,
                     estado_envio = \'ENVIADO\',
                     costo_usd = ?,
                     fecha_envio = NOW(),
                     error_detalle = NULL
                 WHERE id = ?'
            );
            if ($stmtUpdDestOk) {
                $stmtUpdDestOk->bind_param('iissdi', $id_cliente, $id_conversacion, $telefono_limpio, $wamid, $costo_unit, $id_destinatario);
                @$stmtUpdDestOk->execute();
                $stmtUpdDestOk->close();
            }

            actualizarContadoresRapidosCampana($con, $id_campana);

            registrar_auditoria(
                'campanas',
                'DESPACHAR_MENSAJE_CAMPANA',
                "Envío exitoso de campaña #{$id_campana} a {$telefono_limpio}",
                ['id_destinatario' => $id_destinatario, 'estado_envio' => $dest_antes['estado_envio']],
                ['id_destinatario' => $id_destinatario, 'estado_envio' => 'ENVIADO', 'wamid' => $wamid, 'telefono' => $telefono_limpio, 'costo_usd' => $costo_unit],
                $con,
                $id_usuario,
                $id_sede
            );

            echo json_encode([
                'status' => 'success',
                'wamid' => $wamid,
                'telefono' => $telefono_limpio,
                'costo_usd' => $costo_unit,
                'enviados_hoy_sede' => $enviados_hoy + 1,
                'limite_diario_sede' => $limite_diario
            ]);
        } else {
            $err_details = $resp_data['error']['error_data']['details'] ?? '';
            $err_msg_base = $curl_err ?: ($resp_data['error']['message'] ?? 'Error desconocido devuelto por Meta API');
            $err_code = intval($resp_data['error']['code'] ?? $http_code);
            if ($err_code === 132001) {
                $err_full = "Meta Error (132001): La plantilla '{$template_name}' en idioma Español (es) aún está en revisión (PENDING) o no está aprobada en la cuenta WABA de esta sede.";
            } else {
                $err_msg = $err_details ? "{$err_msg_base} - {$err_details}" : $err_msg_base;
                $err_full = "Meta Error ({$err_code}): {$err_msg}";
            }

            starfi_log_exception('campanas', 'Fallo enviando destinatario en dispatch_queue_item', [
                'id_campana' => $id_campana,
                'id_destinatario' => $id_destinatario,
                'http_code' => $http_code,
                'detalle' => $err_full
            ]);

            $stmtNotifErr = $con->prepare(
                'INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                 VALUES (?, ?, ?, ?, ?, ?, \'ERROR\')'
            );
            if ($stmtNotifErr) {
                $stmtNotifErr->bind_param('ssssss', $template_name, $fecha_hoy, $hora_actual, $phone_id, $telefono_limpio, $contenido_log);
                @$stmtNotifErr->execute();
                $stmtNotifErr->close();
            }

            $estado_fallo = ($http_code === 0 && !empty($curl_err)) ? 'PENDIENTE' : 'FALLIDO';
            $stmtUpdErr = $con->prepare(
                'UPDATE campanas_difusion_destinatarios
                 SET estado_envio = ?,
                     error_detalle = ?,
                     fecha_envio = NOW()
                 WHERE id = ?'
            );
            if ($stmtUpdErr) {
                $stmtUpdErr->bind_param('ssi', $estado_fallo, $err_full, $id_destinatario);
                @$stmtUpdErr->execute();
                $stmtUpdErr->close();
            }

            actualizarContadoresRapidosCampana($con, $id_campana);

            registrar_auditoria(
                'campanas',
                'FALLO_ENVIO_CAMPANA',
                "Fallo de despacho en campaña #{$id_campana} para {$telefono_limpio}",
                ['id_destinatario' => $id_destinatario, 'estado_envio' => $dest_antes['estado_envio']],
                ['id_destinatario' => $id_destinatario, 'estado_envio' => $estado_fallo, 'telefono' => $telefono_limpio],
                $con,
                $id_usuario,
                $id_sede
            );

            echo json_encode([
                'status' => 'failed',
                'telefono' => $telefono_limpio,
                'message' => 'No fue posible entregar el mensaje a través de Meta API.'
            ]);
        }
        break;

    // =========================================================================
    // 6. FINALIZAR O ACTUALIZAR ESTADO DE CAMPAÑA AL TERMINAR LA COLA
    // =========================================================================
    case 'finish_campaign_batch':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        if ($id_campana > 0) {
            $camp_antes = obtenerCampanaPorId($con, $id_campana);
            if (!$camp_antes) {
                echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
                exit;
            }
            $id_sede_fin = intval($camp_antes['id_sede']);
            if (!$es_global && $id_sede_usuario > 0 && $id_sede_fin !== $id_sede_usuario) {
                echo json_encode(['status' => 'error', 'message' => 'Acceso denegado a campaña de otra sede.']);
                exit;
            }

            usleep(2000000);
            recalcularMetricasCampana($con, $id_campana);
            $rp = obtenerCampanaPorId($con, $id_campana);
            $pend = $rp ? intval($rp['pendientes']) : 0;
            $piloto = $rp ? intval($rp['piloto_automatico']) : 1;
            $nuevo_estado = ($pend > 0) ? ($piloto === 1 ? 'PILOTO_AUTOMATICO' : 'EN_COLA_DIARIA') : 'COMPLETADA';

            $stmtFin = $con->prepare('UPDATE campanas_difusion SET estado = ?, fecha_fin = NOW() WHERE id = ?');
            if ($stmtFin) {
                $stmtFin->bind_param('si', $nuevo_estado, $id_campana);
                @$stmtFin->execute();
                $stmtFin->close();
            }

            $errores_async = [];
            $stmtErr = $con->prepare('SELECT nombre, telefono, error_detalle FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio = \'FALLIDO\'');
            if ($stmtErr) {
                $stmtErr->bind_param('i', $id_campana);
                if (@$stmtErr->execute()) {
                    $resErr = $stmtErr->get_result();
                    if ($resErr) {
                        while ($re = $resErr->fetch_assoc()) {
                            $errores_async[] = $re;
                        }
                    }
                }
                $stmtErr->close();
            }

            registrar_auditoria(
                'campanas',
                'FINALIZAR_TANDA_CAMPANA',
                "Finalización de lote en campaña #{$id_campana} (Estado: {$nuevo_estado})",
                [
                    'id_campana' => $id_campana,
                    'estado' => $camp_antes['estado'],
                    'pendientes' => intval($camp_antes['pendientes']),
                    'enviados' => intval($camp_antes['enviados'])
                ],
                [
                    'id_campana' => $id_campana,
                    'estado' => $nuevo_estado,
                    'pendientes' => $pend,
                    'enviados' => intval($rp['enviados'] ?? 0),
                    'entregados' => intval($rp['entregados'] ?? 0),
                    'fallidos' => intval($rp['fallidos'] ?? 0)
                ],
                $con,
                $id_usuario,
                $id_sede_fin
            );

            echo json_encode([
                'status' => 'success',
                'enviados' => intval($rp['enviados'] ?? 0),
                'entregados' => intval($rp['entregados'] ?? 0),
                'fallidos' => intval($rp['fallidos'] ?? 0),
                'errores_async' => $errores_async
            ]);
            exit;
        }
        echo json_encode(['status' => 'success']);
        break;

    // =========================================================================
    // 7. OBTENER COLA PENDIENTE DE UNA CAMPAÑA PARA REANUDAR ENVÍO
    // =========================================================================
    case 'get_pending_queue':
        $id_campana = intval($_GET['id_campana'] ?? $_POST['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID de campaña inválido.']);
            exit;
        }
        $camp = obtenerCampanaPorId($con, $id_campana);
        if (!$camp) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
            exit;
        }
        $camp_antes_q = $camp;
        $id_sede = intval($camp['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: solo puede gestionar campañas de su propia sede.']);
            exit;
        }

        $hubo_mutacion = false;

        if (!empty($_POST['hora_programada'])) {
            $hp_raw = trim((string)$_POST['hora_programada']);
            if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $hp_raw)) {
                if (strlen($hp_raw) === 5) {
                    $hp_raw .= ':00';
                }
                $stmtHp = $con->prepare('UPDATE campanas_difusion SET hora_programada = ? WHERE id = ?');
                if ($stmtHp) {
                    $stmtHp->bind_param('si', $hp_raw, $id_campana);
                    @$stmtHp->execute();
                    $stmtHp->close();
                }
                $camp['hora_programada'] = $hp_raw;
                $hubo_mutacion = true;
            }
        }

        if (isset($_FILES['header_imagen']) && intval($_FILES['header_imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $upRes = starfi_validar_y_guardar_imagen_campana($_FILES['header_imagen'], $id_sede, $con, $id_usuario);
            if (!$upRes['ok']) {
                echo json_encode(['status' => 'error', 'message' => $upRes['message']]);
                exit;
            }
            $new_rel_path = $upRes['rel_path'];
            $stmtImg = $con->prepare('UPDATE campanas_difusion SET header_imagen_path = ?, header_media_id = NULL WHERE id = ?');
            if ($stmtImg) {
                $stmtImg->bind_param('si', $new_rel_path, $id_campana);
                @$stmtImg->execute();
                $stmtImg->close();
            }
            $camp['header_imagen_path'] = $new_rel_path;
            $camp['header_media_id'] = null;
            $hubo_mutacion = true;
        }

        if ($camp['estado'] === 'PENDIENTE_APROBACION' || empty($camp['aprobado_por'])) {
            $stmtAprQ = $con->prepare('UPDATE campanas_difusion SET aprobado_por = ?, fecha_aprobacion = NOW(), estado = \'APROBADA_EN_PROCESO\' WHERE id = ?');
            if ($stmtAprQ) {
                $stmtAprQ->bind_param('ii', $id_usuario, $id_campana);
                @$stmtAprQ->execute();
                $stmtAprQ->close();
            }
            $camp['estado'] = 'APROBADA_EN_PROCESO';
            $hubo_mutacion = true;
        }

        $limite_diario_sede = 250;
        $cadencia_ms = 1000;
        $stmtQ = $con->prepare('SELECT limite_diario_mensajes, cadencia_ms FROM campanas_cuotas_sede WHERE id_sede = ? LIMIT 1');
        if ($stmtQ) {
            $stmtQ->bind_param('i', $id_sede);
            if (@$stmtQ->execute()) {
                $rQ = $stmtQ->get_result();
                if ($rQ && ($rq = $rQ->fetch_assoc())) {
                    $limite_diario_sede = max(1, intval($rq['limite_diario_mensajes']));
                    $cadencia_ms = max(250, intval($rq['cadencia_ms']));
                }
            }
            $stmtQ->close();
        }
        $cuota_campana = max(1, intval($camp['cuota_diaria_programada'] ?: $limite_diario_sede));
        if ($cuota_campana > $limite_diario_sede) {
            $limite_diario_sede = $cuota_campana;
        }
        $tope_efectivo = min($limite_diario_sede, $cuota_campana);

        $enviados_hoy_sede = 0;
        $stmtHs = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
        if ($stmtHs) {
            $stmtHs->bind_param('i', $id_sede);
            if (@$stmtHs->execute()) {
                $rHs = $stmtHs->get_result();
                if ($rHs && ($rh = $rHs->fetch_assoc())) {
                    $enviados_hoy_sede = intval($rh['c']);
                }
            }
            $stmtHs->close();
        }

        $enviados_hoy_camp = 0;
        $stmtHc = $con->prepare('SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = ? AND estado_envio IN (\'ENVIADO\',\'ENTREGADO\',\'LEIDO\',\'RESPONDIDO\') AND DATE(fecha_envio) = CURDATE()');
        if ($stmtHc) {
            $stmtHc->bind_param('i', $id_campana);
            if (@$stmtHc->execute()) {
                $rHc = $stmtHc->get_result();
                if ($rHc && ($rhc = $rHc->fetch_assoc())) {
                    $enviados_hoy_camp = intval($rhc['c']);
                }
            }
            $stmtHc->close();
        }

        $disponibles_hoy = min(max(0, $limite_diario_sede - $enviados_hoy_sede), max(0, $tope_efectivo - $enviados_hoy_camp));

        if ($disponibles_hoy <= 0) {
            echo json_encode([
                'status' => 'error',
                'message' => "La campaña o sede ya completó su tanda diaria de hoy ({$tope_efectivo} mensajes/día). Queda en Piloto Automático para continuar en la siguiente jornada."
            ]);
            exit;
        }

        $stmtUnstick = $con->prepare(
            'UPDATE campanas_difusion_destinatarios
             SET estado_envio = \'PENDIENTE\'
             WHERE id_campana = ? AND estado_envio = \'PROCESANDO\' AND (id_mensaje_meta IS NULL OR id_mensaje_meta = \'\')'
        );
        if ($stmtUnstick) {
            $stmtUnstick->bind_param('i', $id_campana);
            @$stmtUnstick->execute();
            if ($stmtUnstick->affected_rows > 0) {
                $hubo_mutacion = true;
            }
            $stmtUnstick->close();
        }

        $cola = [];
        $stmtPend = $con->prepare(
            'SELECT id AS id_destinatario, id_cliente, nombre, telefono, numero_tanda, fecha_programada
             FROM campanas_difusion_destinatarios
             WHERE id_campana = ? AND estado_envio = \'PENDIENTE\'
             ORDER BY numero_tanda ASC, id ASC
             LIMIT ?'
        );
        if ($stmtPend) {
            $stmtPend->bind_param('ii', $id_campana, $disponibles_hoy);
            if (@$stmtPend->execute()) {
                $resPend = $stmtPend->get_result();
                if ($resPend) {
                    while ($r = $resPend->fetch_assoc()) {
                        $cola[] = $r;
                    }
                }
            }
            $stmtPend->close();
        }

        if (empty($cola)) {
            $hay_fal = false;
            $stmtFalChk = $con->prepare(
                'SELECT id FROM campanas_difusion_destinatarios
                 WHERE id_campana = ? AND estado_envio = \'FALLIDO\' AND fecha_entrega IS NULL AND fecha_lectura IS NULL AND respondido = 0
                 LIMIT 1'
            );
            if ($stmtFalChk) {
                $stmtFalChk->bind_param('i', $id_campana);
                if (@$stmtFalChk->execute()) {
                    $stmtFalChk->store_result();
                    $hay_fal = ($stmtFalChk->num_rows > 0);
                }
                $stmtFalChk->close();
            }

            if ($hay_fal) {
                $stmtReq = $con->prepare(
                    'UPDATE campanas_difusion_destinatarios
                     SET estado_envio = \'PENDIENTE\', error_detalle = NULL, id_mensaje_meta = NULL
                     WHERE id_campana = ? AND estado_envio = \'FALLIDO\' AND fecha_entrega IS NULL AND fecha_lectura IS NULL AND respondido = 0'
                );
                if ($stmtReq) {
                    $stmtReq->bind_param('i', $id_campana);
                    @$stmtReq->execute();
                    $stmtReq->close();
                }
                $hubo_mutacion = true;
                recalcularMetricasCampana($con, $id_campana);

                $stmtPend2 = $con->prepare(
                    'SELECT id AS id_destinatario, id_cliente, nombre, telefono, numero_tanda, fecha_programada
                     FROM campanas_difusion_destinatarios
                     WHERE id_campana = ? AND estado_envio = \'PENDIENTE\'
                     ORDER BY numero_tanda ASC, id ASC
                     LIMIT ?'
                );
                if ($stmtPend2) {
                    $stmtPend2->bind_param('ii', $id_campana, $disponibles_hoy);
                    if (@$stmtPend2->execute()) {
                        $resPend2 = $stmtPend2->get_result();
                        if ($resPend2) {
                            while ($r2 = $resPend2->fetch_assoc()) {
                                $cola[] = $r2;
                            }
                        }
                    }
                    $stmtPend2->close();
                }
            }
        }

        if ($hubo_mutacion) {
            registrar_auditoria(
                'campanas',
                'REANUDAR_COLA_CAMPANA',
                "Preparación/reanudación de cola pendiente en campaña #{$id_campana}",
                $camp_antes_q,
                [
                    'id_campana' => $id_campana,
                    'estado' => $camp['estado'],
                    'hora_programada' => $camp['hora_programada'] ?? null,
                    'header_imagen_path' => $camp['header_imagen_path'] ?? null,
                    'encolados_lote' => count($cola)
                ],
                $con,
                $id_usuario,
                $id_sede
            );
        }

        echo json_encode([
            'status' => 'success',
            'id_campana' => $id_campana,
            'nombre_campana' => $camp['nombre_campana'],
            'cadencia_ms' => $cadencia_ms,
            'disponibles_hoy' => $disponibles_hoy,
            'cola_envio' => $cola
        ]);
        break;

    // =========================================================================
    // 8. DETALLE 360° DE DESTINATARIOS DE UNA CAMPAÑA
    // =========================================================================
    case 'get_campaign_recipients':
        $id_campana = intval($_GET['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña inválida.']);
            exit;
        }

        recalcularMetricasCampana($con, $id_campana);

        $campana = null;
        $stmtC = $con->prepare(
            'SELECT c.*, s.nombre_sede, l.numero_telefono AS linea_telefono
             FROM campanas_difusion c
             LEFT JOIN sedes s ON s.id = c.id_sede
             LEFT JOIN lineas_whatsapp l ON l.id_sede = c.id_sede AND l.estado = \'ACTIVO\'
             WHERE c.id = ? LIMIT 1'
        );
        if ($stmtC) {
            $stmtC->bind_param('i', $id_campana);
            if (@$stmtC->execute()) {
                $resC = $stmtC->get_result();
                if ($resC && $resC->num_rows > 0) {
                    $campana = $resC->fetch_assoc();
                }
            }
            $stmtC->close();
        }

        if ($campana && !$es_global && $id_sede_usuario > 0 && intval($campana['id_sede']) !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado a campaña de otra sede.']);
            exit;
        }

        $destinatarios = [];
        $stmtD = $con->prepare(
            'SELECT d.*,
                    COALESCE(NULLIF(s_cli.nombre_sede, \'\'), NULLIF(s_camp.nombre_sede, \'\'), \'General\') AS nombre_sede_cliente,
                    COALESCE(NULLIF(c_cli.id_sede, 0), d.id_sede) AS id_sede_cliente
             FROM campanas_difusion_destinatarios d
             LEFT JOIN clientes_contactos c_cli ON c_cli.id = d.id_cliente
             LEFT JOIN sedes s_cli ON s_cli.id = c_cli.id_sede
             LEFT JOIN sedes s_camp ON s_camp.id = d.id_sede
             WHERE d.id_campana = ?
             ORDER BY d.respondido DESC, d.fecha_lectura DESC, d.id ASC'
        );
        if ($stmtD) {
            $stmtD->bind_param('i', $id_campana);
            if (@$stmtD->execute()) {
                $resD = $stmtD->get_result();
                if ($resD) {
                    while ($row = $resD->fetch_assoc()) {
                        $destinatarios[] = $row;
                    }
                }
            }
            $stmtD->close();
        }

        echo json_encode([
            'status' => 'success',
            'campana' => $campana,
            'destinatarios' => $destinatarios
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no reconocida en el ecosistema de campañas.']);
        break;
}
