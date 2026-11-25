<?php
// modules/waba_ordenes/back_waba_ordenes.php
// Motor Financiero y Controlador de Órdenes de Cobro WABA — STARFI CRM (QA-STD-GLOBAL-2.1)
// Blindado con finfo(FILEINFO_MIME_TYPE) <= 5 MB, bloqueo FOR UPDATE anti-duplicidad de referencias y periodos,
// 100% mysqli_prepare(), timeouts HTTP <= 8s, cero fugas SQL y auditoría formal registrar_auditoria().

@ini_set('display_errors', '0');
if (!defined('WABA_BILLING_LIB_ONLY') || WABA_BILLING_LIB_ONLY !== true) {
    header('Content-Type: application/json; charset=utf-8');
}
date_default_timezone_set('America/Caracas');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../core/crm_sprint2_helper.php';
require_once __DIR__ . '/../../core/audit.php';

/**
 * Devuelve mapa de columnas existentes para tablas conocidas de facturación WABA
 * usando consultas SHOW COLUMNS estáticas preparadas (cero interpolación de variables).
 */
function starfi_waba_table_columns(mysqli $con, string $table, bool $forceRefresh = false): array {
    static $cache = [];
    $oid = function_exists('spl_object_id') ? spl_object_id($con) : 0;
    $key = $oid . ':' . $table;
    if (!$forceRefresh && isset($cache[$key])) {
        return $cache[$key];
    }

    $staticShowQueries = [
        'waba_ordenes_cobro'              => "SHOW COLUMNS FROM `waba_ordenes_cobro`",
        'waba_ordenes_detalles'           => "SHOW COLUMNS FROM `waba_ordenes_detalles`",
        'waba_tarifas_sede'               => "SHOW COLUMNS FROM `waba_tarifas_sede`",
        'sedes'                           => "SHOW COLUMNS FROM `sedes`",
        'lineas_whatsapp'                 => "SHOW COLUMNS FROM `lineas_whatsapp`",
        'campanas_difusion'               => "SHOW COLUMNS FROM `campanas_difusion`",
        'campanas_difusion_destinatarios' => "SHOW COLUMNS FROM `campanas_difusion_destinatarios`",
        'campanas_cuotas_sede'            => "SHOW COLUMNS FROM `campanas_cuotas_sede`",
    ];

    if (!isset($staticShowQueries[$table])) {
        return [];
    }

    $cols = [];
    $stmtShow = @$con->prepare($staticShowQueries[$table]);
    if ($stmtShow && $stmtShow->execute()) {
        $res = $stmtShow->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            if (!empty($row['Field'])) {
                $cols[$row['Field']] = $row;
            }
        }
        $stmtShow->close();
    }
    $cache[$key] = $cols;
    return $cols;
}

function starfi_waba_has_column(mysqli $con, string $table, string $column): bool {
    $cols = starfi_waba_table_columns($con, $table);
    return isset($cols[$column]);
}

function starfi_waba_table_exists(mysqli $con, string $table): bool {
    $staticChecks = [
        'campanas_cuotas_sede'            => "SHOW TABLES LIKE 'campanas_cuotas_sede'",
        'campanas_difusion_destinatarios' => "SHOW TABLES LIKE 'campanas_difusion_destinatarios'",
        'campanas_difusion'               => "SHOW TABLES LIKE 'campanas_difusion'",
    ];
    if (!isset($staticChecks[$table])) {
        return false;
    }
    $stmtChk = @$con->prepare($staticChecks[$table]);
    if (!$stmtChk || !$stmtChk->execute()) {
        return false;
    }
    $res = $stmtChk->get_result();
    $exists = ($res && $res->num_rows > 0);
    $stmtChk->close();
    return $exists;
}

/**
 * Construye la URL autenticada de visualización/descarga de comprobante WABA vía ver_documento.php.
 */
function starfi_waba_receipt_secure_url(?string $storedPath): ?string {
    $clean = trim((string)$storedPath);
    if ($clean === '') {
        return null;
    }
    $base = basename(str_replace('\\', '/', $clean));
    if ($base === '' || $base === '.' || $base === '..') {
        return null;
    }
    return '../../ver_documento.php?tipo=comprobante_waba&file=' . rawurlencode($base);
}

/**
 * [CHK-SEC-07] Validación estricta de comprobante WABA:
 * - Tamaño > 0 y <= 5 MB (5 * 1024 * 1024 bytes)
 * - MIME real verificado con finfo(FILEINFO_MIME_TYPE)
 * - Extensión derivada exclusivamente del mapa MIME permitido (jamás del nombre original del cliente)
 */
function starfi_validate_waba_receipt_file(string $filePath, int $fileSize): array {
    $maxBytes = 5 * 1024 * 1024;
    if ($fileSize <= 0) {
        return [
            'ok'      => false,
            'mime'    => '',
            'ext'     => '',
            'message' => 'El archivo del comprobante está vacío o es inválido.',
        ];
    }
    if ($fileSize > $maxBytes) {
        return [
            'ok'      => false,
            'mime'    => '',
            'ext'     => '',
            'message' => 'El comprobante supera el tamaño máximo permitido de 5 MB.',
        ];
    }
    if ($filePath === '' || !is_file($filePath)) {
        return [
            'ok'      => false,
            'mime'    => '',
            'ext'     => '',
            'message' => 'No se pudo acceder al archivo temporal del comprobante.',
        ];
    }

    $allowedMimes = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = @$finfo->file($filePath);
    if ($detected === false) {
        return [
            'ok'      => false,
            'mime'    => '',
            'ext'     => '',
            'message' => 'No se pudo inspeccionar el contenido binario del comprobante.',
        ];
    }
    $realMime = strtolower(trim((string)$detected));

    if (!isset($allowedMimes[$realMime])) {
        return [
            'ok'      => false,
            'mime'    => $realMime,
            'ext'     => '',
            'message' => 'Formato real de archivo no permitido por seguridad. Adjunte únicamente JPG, PNG, WEBP o PDF válido (máx. 5 MB).',
        ];
    }

    return [
        'ok'      => true,
        'mime'    => $realMime,
        'ext'     => $allowedMimes[$realMime],
        'message' => 'Comprobante válido.',
    ];
}

/**
 * [CHK-SEC-09] Verificación transaccional con bloqueo pesimista (FOR UPDATE) contra duplicidad
 * de referencias de pago en órdenes WABA en estado EN_REVISION o PAGADO (excepto Efectivo).
 */
function starfi_check_duplicate_waba_reference(mysqli $con, int $idOrden, string $metodoPago, string $referenciaPago): ?array {
    $metodoClean = trim($metodoPago);
    $refClean    = trim($referenciaPago);

    if ($metodoClean === '' || $refClean === '' || strcasecmp($metodoClean, 'Efectivo') === 0) {
        return null;
    }

    $hasCodigo = starfi_waba_has_column($con, 'waba_ordenes_cobro', 'codigo_orden');
    $sql = $hasCodigo
        ? "SELECT id, codigo_orden FROM waba_ordenes_cobro WHERE id != ? AND estado IN ('EN_REVISION', 'PAGADO') AND UPPER(TRIM(metodo_pago)) = UPPER(TRIM(?)) AND UPPER(TRIM(referencia_pago)) = UPPER(TRIM(?)) LIMIT 1 FOR UPDATE"
        : "SELECT id, CONCAT('NC-', id) AS codigo_orden FROM waba_ordenes_cobro WHERE id != ? AND estado IN ('EN_REVISION', 'PAGADO') AND UPPER(TRIM(metodo_pago)) = UPPER(TRIM(?)) AND UPPER(TRIM(referencia_pago)) = UPPER(TRIM(?)) LIMIT 1 FOR UPDATE";

    $stmt = $con->prepare($sql);
    if (!$stmt) {
        starfi_log_exception('waba_ordenes', 'No se pudo preparar validación anti-duplicidad de referencia', ['errno' => $con->errno]);
        throw new RuntimeException('Error interno al validar unicidad de la referencia de pago.');
    }
    $stmt->bind_param("iss", $idOrden, $metodoClean, $refClean);
    if (!$stmt->execute()) {
        $errno = $stmt->errno;
        $stmt->close();
        starfi_log_exception('waba_ordenes', 'Error ejecutando validación anti-duplicidad de referencia', ['errno' => $errno]);
        throw new RuntimeException('Error interno al consultar referencias de pago.');
    }
    $res = $stmt->get_result();
    $dup = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    return $dup ?: null;
}

/**
 * Auto-migración defensiva e idempotente para garantizar columnas de pagos y trazabilidad.
 * Respeta starfi_tx_is_active($con) para jamás disparar DDL implícito dentro de transacciones activas.
 */
function ensureWabaBillingSchema($con) {
    if (!($con instanceof mysqli)) {
        return;
    }
    if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['waba_schema_v3_ready'])) {
        return;
    }

    $dbHash = function_exists('starfi_db_schema_hash') ? starfi_db_schema_hash($con) : 'default';
    $flag = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_waba_schema_v3_' . $dbHash . '.flag';
    if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
        $colsChk = starfi_waba_table_columns($con, 'waba_ordenes_cobro');
        if (isset($colsChk['comprobante_ruta']) && isset($colsChk['codigo_orden'])) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['waba_schema_v3_ready'] = true;
            }
            return;
        }
    }

    $colsOrd = starfi_waba_table_columns($con, 'waba_ordenes_cobro', true);
    if (isset($colsOrd['estado'])) {
        $typeLower = strtolower((string)($colsOrd['estado']['Type'] ?? ''));
        if (strpos($typeLower, 'enum') !== false && (strpos($typeLower, 'en_revision') === false || strpos($typeLower, 'fusionado') === false)) {
            $stModEst = @$con->prepare("ALTER TABLE `waba_ordenes_cobro` MODIFY COLUMN `estado` VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE'");
            if ($stModEst) { @$stModEst->execute(); $stModEst->close(); }
        }
    }

    $alterOrdenes = [
        'codigo_orden'         => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `codigo_orden` VARCHAR(50) DEFAULT NULL AFTER `id`",
        'mensajes_totales'     => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `mensajes_totales` INT NOT NULL DEFAULT 0",
        'costo_meta'           => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `costo_meta` DECIMAL(12,4) NOT NULL DEFAULT 0.0000",
        'margen_ganancia'      => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `margen_ganancia` DECIMAL(12,4) NOT NULL DEFAULT 0.0000",
        'monto_total'          => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `monto_total` DECIMAL(12,4) NOT NULL DEFAULT 0.0000",
        'metodo_pago'          => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `metodo_pago` VARCHAR(80) DEFAULT NULL",
        'referencia_pago'      => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `referencia_pago` VARCHAR(120) DEFAULT NULL",
        'fecha_pago'           => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `fecha_pago` DATETIME DEFAULT NULL",
        'notas_pago'           => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `notas_pago` TEXT DEFAULT NULL",
        'comprobante_ruta'     => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `comprobante_ruta` VARCHAR(500) DEFAULT NULL",
        'comprobante_path'     => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `comprobante_path` VARCHAR(255) DEFAULT NULL",
        'reportado_por'        => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `reportado_por` INT DEFAULT NULL",
        'verificado_por'       => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `verificado_por` INT DEFAULT NULL",
        'fecha_verificacion'   => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `fecha_verificacion` DATETIME DEFAULT NULL",
        'notificacion_enviada' => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `notificacion_enviada` INT NOT NULL DEFAULT 0",
        'orden_padre_id'       => "ALTER TABLE `waba_ordenes_cobro` ADD COLUMN `orden_padre_id` INT DEFAULT NULL",
    ];
    foreach ($alterOrdenes as $colName => $sqlAlter) {
        if (!empty($colsOrd) && !isset($colsOrd[$colName])) {
            $stAlt = @$con->prepare($sqlAlter);
            if ($stAlt) { @$stAlt->execute(); $stAlt->close(); }
        }
    }

    $colsDet = starfi_waba_table_columns($con, 'waba_ordenes_detalles', true);
    $alterDetalles = [
        'categoria'          => "ALTER TABLE `waba_ordenes_detalles` ADD COLUMN `categoria` VARCHAR(50) DEFAULT 'MARKETING'",
        'costo_base'         => "ALTER TABLE `waba_ordenes_detalles` ADD COLUMN `costo_base` DECIMAL(12,4) NOT NULL DEFAULT 0.0000",
        'mensajes_gratuitos' => "ALTER TABLE `waba_ordenes_detalles` ADD COLUMN `mensajes_gratuitos` INT NOT NULL DEFAULT 0",
        'mensajes_pagados'   => "ALTER TABLE `waba_ordenes_detalles` ADD COLUMN `mensajes_pagados` INT NOT NULL DEFAULT 0",
    ];
    foreach ($alterDetalles as $colName => $sqlAlter) {
        if (!empty($colsDet) && !isset($colsDet[$colName])) {
            $stAlt = @$con->prepare($sqlAlter);
            if ($stAlt) { @$stAlt->execute(); $stAlt->close(); }
        }
    }

    $colsTar = starfi_waba_table_columns($con, 'waba_tarifas_sede', true);
    $alterTarifas = [
        'tipo_tarifa'        => "ALTER TABLE `waba_tarifas_sede` ADD COLUMN `tipo_tarifa` VARCHAR(40) NOT NULL DEFAULT 'PORCENTAJE'",
        'valor'              => "ALTER TABLE `waba_tarifas_sede` ADD COLUMN `valor` DECIMAL(10,4) NOT NULL DEFAULT 10.0000",
        'notas_negociacion'  => "ALTER TABLE `waba_tarifas_sede` ADD COLUMN `notas_negociacion` TEXT DEFAULT NULL",
        'limite_facturacion' => "ALTER TABLE `waba_tarifas_sede` ADD COLUMN `limite_facturacion` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
    ];
    foreach ($alterTarifas as $colName => $sqlAlter) {
        if (!empty($colsTar) && !isset($colsTar[$colName])) {
            $stAlt = @$con->prepare($sqlAlter);
            if ($stAlt) { @$stAlt->execute(); $stAlt->close(); }
        }
    }

    $colsSede = starfi_waba_table_columns($con, 'sedes', true);
    $alterSedes = [
        'gerente_nombre'   => "ALTER TABLE `sedes` ADD COLUMN `gerente_nombre` VARCHAR(150) DEFAULT NULL",
        'gerente_telefono' => "ALTER TABLE `sedes` ADD COLUMN `gerente_telefono` VARCHAR(50) DEFAULT NULL",
        'gerente_email'    => "ALTER TABLE `sedes` ADD COLUMN `gerente_email` VARCHAR(150) DEFAULT NULL",
        'pref_not_cobro'   => "ALTER TABLE `sedes` ADD COLUMN `pref_not_cobro` VARCHAR(30) DEFAULT 'WHATSAPP'",
    ];
    foreach ($alterSedes as $colName => $sqlAlter) {
        if (!empty($colsSede) && !isset($colsSede[$colName])) {
            $stAlt = @$con->prepare($sqlAlter);
            if ($stAlt) { @$stAlt->execute(); $stAlt->close(); }
        }
    }

    starfi_waba_table_columns($con, 'waba_ordenes_cobro', true);
    starfi_waba_table_columns($con, 'waba_ordenes_detalles', true);
    starfi_waba_table_columns($con, 'waba_tarifas_sede', true);
    starfi_waba_table_columns($con, 'sedes', true);

    @file_put_contents($flag, date('Y-m-d H:i:s'));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['waba_schema_v3_ready'] = true;
    }
}

/**
 * Helper cURL con timeouts estrictos (CURLOPT_CONNECTTIMEOUT = 4s, CURLOPT_TIMEOUT = 8s).
 */
function starfi_waba_http_request(string $url, array $headers = [], ?array $postPayload = null): ?array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    if ($postPayload !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postPayload, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $raw === '') {
        if ($curlErr !== '') {
            starfi_log_exception('waba_ordenes', 'Error de transporte HTTP Meta API', ['detail' => $curlErr]);
        }
        return null;
    }
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Normaliza los campos de una fila de waba_ordenes_cobro entre variantes de esquema.
 */
function starfi_normalize_waba_order_row(array $row): array {
    if (!isset($row['mensajes_totales']) && isset($row['total_mensajes'])) {
        $row['mensajes_totales'] = intval($row['total_mensajes']);
    }
    if (!isset($row['costo_meta']) && isset($row['costo_meta_usd'])) {
        $row['costo_meta'] = floatval($row['costo_meta_usd']);
    }
    if (!isset($row['monto_total']) && isset($row['monto_total_usd'])) {
        $row['monto_total'] = floatval($row['monto_total_usd']);
    }
    if (!isset($row['margen_ganancia'])) {
        $row['margen_ganancia'] = max(0.0, floatval($row['monto_total'] ?? 0) - floatval($row['costo_meta'] ?? 0));
    }
    if (empty($row['notas_pago']) && !empty($row['observaciones_pago'])) {
        $row['notas_pago'] = $row['observaciones_pago'];
    }
    $compRel = !empty($row['comprobante_ruta'])
        ? (string)$row['comprobante_ruta']
        : (!empty($row['comprobante_path']) ? (string)$row['comprobante_path'] : null);
    $row['comprobante_ruta'] = $compRel;
    $row['comprobante_path'] = $compRel;
    $row['comprobante_url']  = starfi_waba_receipt_secure_url($compRel);
    return $row;
}

/**
 * Registro/Reporte transaccional de pago de orden WABA con bloqueo FOR UPDATE,
 * validación anti-duplicidad de referencias y validación finfo <= 5 MB.
 */
function starfi_report_waba_payment(
    mysqli $con,
    int $idOrden,
    array $input,
    ?array $uploadedFile = null,
    int $idUsuario = 0,
    bool $esMaster = false,
    int $idSedeAgente = 0
): array {
    if ($idOrden <= 0) {
        return ['status' => 'error', 'message' => 'ID de orden no válido.'];
    }

    $metodoPago     = trim((string)($input['metodo_pago'] ?? ''));
    $referenciaPago = trim((string)($input['referencia_pago'] ?? ''));
    $fechaPagoIn    = trim((string)($input['fecha_pago'] ?? date('Y-m-d H:i:s')));
    $notasUsuario   = trim((string)($input['notas_pago'] ?? ''));
    $bancoEmisor    = trim((string)($input['banco_emisor'] ?? ''));
    $titularEmisor  = trim((string)($input['titular_emisor'] ?? ''));
    $telefonoEmisor = trim((string)($input['telefono_emisor'] ?? ''));
    $montoBs        = trim((string)($input['monto_bs'] ?? ''));

    if ($metodoPago === '') {
        return ['status' => 'error', 'message' => 'Debe seleccionar el Método de Pago.'];
    }
    if (strcasecmp($metodoPago, 'Efectivo') === 0 && $referenciaPago === '') {
        $referenciaPago = 'Entrega en Efectivo';
    }
    if ($referenciaPago === '') {
        return ['status' => 'error', 'message' => 'El número de Referencia / TxID / Confirmación es obligatorio para ' . $metodoPago . '.'];
    }

    $validatedReceipt = null;
    if (is_array($uploadedFile) && ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['status' => 'error', 'message' => 'Error al recibir el archivo del comprobante.'];
        }
        $tmpFile  = (string)($uploadedFile['tmp_name'] ?? '');
        $fileSize = intval($uploadedFile['size'] ?? 0);
        if (PHP_SAPI !== 'cli' && ($tmpFile === '' || !is_uploaded_file($tmpFile))) {
            return ['status' => 'error', 'message' => 'Origen de carga de comprobante no válido.'];
        }
        $val = starfi_validate_waba_receipt_file($tmpFile, $fileSize);
        if (!$val['ok']) {
            return ['status' => 'error', 'message' => $val['message']];
        }
        $validatedReceipt = [
            'tmp_name' => $tmpFile,
            'ext'      => $val['ext'],
            'mime'     => $val['mime'],
        ];
    }

    $detallesMetodo = [];
    if ($bancoEmisor !== '') {
        $detallesMetodo[] = "Banco: $bancoEmisor";
    }
    if ($titularEmisor !== '') {
        $lblTit = (strcasecmp($metodoPago, 'Efectivo') === 0)
            ? 'Entregado/Recibido'
            : ((strcasecmp($metodoPago, 'Binance') === 0) ? 'Cuenta Binance' : 'Titular');
        $detallesMetodo[] = "$lblTit: $titularEmisor";
    }
    if ($telefonoEmisor !== '') {
        $detallesMetodo[] = "Tel/CI: $telefonoEmisor";
    }
    if ($montoBs !== '') {
        $detallesMetodo[] = "Monto Bs: $montoBs";
    }

    $prefijoDetalles = !empty($detallesMetodo) ? implode(' | ', $detallesMetodo) : '';
    if ($prefijoDetalles !== '' && $notasUsuario !== '') {
        $notasPago = (strpos($notasUsuario, $prefijoDetalles) === false)
            ? ($prefijoDetalles . " — " . $notasUsuario)
            : $notasUsuario;
    } elseif ($prefijoDetalles !== '') {
        $notasPago = $prefijoDetalles;
    } else {
        $notasPago = $notasUsuario;
    }

    $tsPago = strtotime($fechaPagoIn);
    $colsOrd = starfi_waba_table_columns($con, 'waba_ordenes_cobro');
    $fechaPagoType = strtolower((string)($colsOrd['fecha_pago']['Type'] ?? 'datetime'));
    $fechaPago = ($fechaPagoType === 'date')
        ? ($tsPago ? date('Y-m-d', $tsPago) : date('Y-m-d'))
        : ($tsPago ? date('Y-m-d H:i:s', $tsPago) : date('Y-m-d H:i:s'));

    $own_tx = !(function_exists('starfi_tx_is_active') && starfi_tx_is_active($con));
    if ($own_tx) {
        $con->begin_transaction();
        if (function_exists('starfi_tx_set_active')) {
            starfi_tx_set_active($con, true);
        }
    } else {
        @$con->query("SAVEPOINT sp_waba_report_payment");
    }

    $movedDestPath = null;
    try {
        $stmtOrd = $con->prepare("SELECT * FROM waba_ordenes_cobro WHERE id = ? LIMIT 1 FOR UPDATE");
        if (!$stmtOrd) {
            throw new RuntimeException('No se pudo bloquear la orden de cobro.');
        }
        $stmtOrd->bind_param("i", $idOrden);
        $stmtOrd->execute();
        $resOrd = $stmtOrd->get_result();
        $ordenRaw = ($resOrd && $resOrd->num_rows > 0) ? $resOrd->fetch_assoc() : null;
        $stmtOrd->close();

        if (!$ordenRaw) {
            if ($own_tx) {
                $con->rollback();
                if (function_exists('starfi_tx_set_active')) starfi_tx_set_active($con, false);
            } else {
                @$con->query("ROLLBACK TO SAVEPOINT sp_waba_report_payment");
            }
            return ['status' => 'error', 'message' => 'La orden especificada no existe.'];
        }

        $orden = starfi_normalize_waba_order_row($ordenRaw);
        $ordenSede = intval($orden['id_sede'] ?? 0);

        if (!$esMaster && $ordenSede !== $idSedeAgente) {
            if ($own_tx) {
                $con->rollback();
                if (function_exists('starfi_tx_set_active')) starfi_tx_set_active($con, false);
            } else {
                @$con->query("ROLLBACK TO SAVEPOINT sp_waba_report_payment");
            }
            return ['status' => 'error', 'message' => 'No tiene permiso para reportar pagos de otra sede.'];
        }

        $dup = starfi_check_duplicate_waba_reference($con, $idOrden, $metodoPago, $referenciaPago);
        if ($dup !== null) {
            if ($own_tx) {
                $con->rollback();
                if (function_exists('starfi_tx_set_active')) starfi_tx_set_active($con, false);
            } else {
                @$con->query("ROLLBACK TO SAVEPOINT sp_waba_report_payment");
            }
            $dupLabel = !empty($dup['codigo_orden']) ? $dup['codigo_orden'] : ('#' . intval($dup['id']));
            return [
                'status'             => 'error',
                'duplicate'          => true,
                'duplicate_order_id' => intval($dup['id']),
                'message'            => "La referencia de pago '$referenciaPago' ($metodoPago) ya fue registrada en la orden $dupLabel.",
            ];
        }

        $comprobanteRelPath = $orden['comprobante_ruta'] ?? null;
        if ($validatedReceipt !== null) {
            $uploadDir = __DIR__ . '/../../assets/uploads/comprobantes_waba';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $filename = 'pago_orden_' . $idOrden . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $validatedReceipt['ext'];
            $destPath = $uploadDir . '/' . $filename;
            $moved = false;
            if (is_uploaded_file($validatedReceipt['tmp_name'])) {
                $moved = @move_uploaded_file($validatedReceipt['tmp_name'], $destPath);
            } elseif (PHP_SAPI === 'cli' && is_file($validatedReceipt['tmp_name'])) {
                $moved = @copy($validatedReceipt['tmp_name'], $destPath);
            }
            if (!$moved) {
                throw new RuntimeException('No se pudo almacenar el comprobante en el servidor.');
            }
            $movedDestPath = $destPath;
            $comprobanteRelPath = 'assets/uploads/comprobantes_waba/' . $filename;
        }

        $nuevoEstado = 'EN_REVISION';
        if ($esMaster && !empty($input['estado_destino'])) {
            $estadoSolicitado = strtoupper(trim((string)$input['estado_destino']));
            if (in_array($estadoSolicitado, ['EN_REVISION', 'PAGADO'], true)) {
                $nuevoEstado = $estadoSolicitado;
            }
        }

        $setParts = ["`estado` = ?", "`metodo_pago` = ?", "`referencia_pago` = ?", "`fecha_pago` = ?"];
        $typesUp  = "ssss";
        $paramsUp = [$nuevoEstado, $metodoPago, $referenciaPago, $fechaPago];

        if (isset($colsOrd['notas_pago'])) {
            $setParts[] = "`notas_pago` = ?";
            $typesUp   .= "s";
            $paramsUp[] = $notasPago;
        }
        if (isset($colsOrd['observaciones_pago'])) {
            $setParts[] = "`observaciones_pago` = ?";
            $typesUp   .= "s";
            $paramsUp[] = $notasPago;
        }
        if (isset($colsOrd['monto_pagado_bs']) && $montoBs !== '') {
            $setParts[] = "`monto_pagado_bs` = ?";
            $typesUp   .= "s";
            $paramsUp[] = $montoBs;
        }
        if (isset($colsOrd['comprobante_ruta'])) {
            $setParts[] = "`comprobante_ruta` = ?";
            $typesUp   .= "s";
            $paramsUp[] = $comprobanteRelPath;
        }
        if (isset($colsOrd['comprobante_path'])) {
            $setParts[] = "`comprobante_path` = ?";
            $typesUp   .= "s";
            $paramsUp[] = $comprobanteRelPath;
        }
        $uidParam = ($idUsuario > 0) ? $idUsuario : null;
        if (isset($colsOrd['reportado_por'])) {
            $setParts[] = "`reportado_por` = COALESCE(`reportado_por`, ?)";
            $typesUp   .= "i";
            $paramsUp[] = $uidParam;
        }
        if ($nuevoEstado === 'PAGADO' && $esMaster) {
            if (isset($colsOrd['verificado_por'])) {
                $setParts[] = "`verificado_por` = ?";
                $typesUp   .= "i";
                $paramsUp[] = $uidParam;
            }
            if (isset($colsOrd['fecha_verificacion'])) {
                $setParts[] = "`fecha_verificacion` = NOW()";
            }
        }

        $typesUp   .= "i";
        $paramsUp[] = $idOrden;

        $sqlUp = "UPDATE `waba_ordenes_cobro` SET " . implode(", ", $setParts) . " WHERE `id` = ?";
        $stmtUp = $con->prepare($sqlUp);
        if (!$stmtUp) {
            starfi_log_exception('waba_ordenes', 'Error preparando UPDATE en starfi_report_waba_payment', ['errno' => $con->errno]);
            throw new RuntimeException('Error interno al preparar actualización de pago.');
        }
        $stmtUp->bind_param($typesUp, ...$paramsUp);
        if (!$stmtUp->execute()) {
            $sqlErrno = $stmtUp->errno;
            $stmtUp->close();
            starfi_log_exception('waba_ordenes', 'Error ejecutando UPDATE en starfi_report_waba_payment', ['id_orden' => $idOrden, 'errno' => $sqlErrno]);
            throw new RuntimeException('No se pudo guardar el registro de pago.');
        }
        $stmtUp->close();

        $datosAnteriores = [
            'id_orden'         => $idOrden,
            'estado'           => $orden['estado'] ?? null,
            'metodo_pago'      => $orden['metodo_pago'] ?? null,
            'referencia_pago'  => $orden['referencia_pago'] ?? null,
            'comprobante_ruta' => $orden['comprobante_ruta'] ?? null,
        ];
        $datosNuevos = [
            'id_orden'         => $idOrden,
            'estado'           => $nuevoEstado,
            'metodo_pago'      => $metodoPago,
            'referencia_pago'  => $referenciaPago,
            'fecha_pago'       => $fechaPago,
            'comprobante_ruta' => $comprobanteRelPath,
        ];
        registrar_auditoria(
            'waba_ordenes',
            'REPORTAR_PAGO',
            "Pago reportado en orden #$idOrden ($metodoPago / Ref: $referenciaPago)",
            $datosAnteriores,
            $datosNuevos,
            $con,
            $uidParam,
            $ordenSede
        );

        if ($own_tx) {
            $con->commit();
            if (function_exists('starfi_tx_set_active')) {
                starfi_tx_set_active($con, false);
            }
        } else {
            @$con->query("RELEASE SAVEPOINT sp_waba_report_payment");
        }

        $msgOk = ($nuevoEstado === 'PAGADO')
            ? "El pago de la orden #$idOrden ha sido registrado y verificado como PAGADO."
            : "El pago de la orden #$idOrden fue reportado exitosamente y quedó PENDIENTE POR REVISIÓN del administrador MASTER.";

        return [
            'status'           => 'success',
            'message'          => $msgOk,
            'nuevo_estado'     => $nuevoEstado,
            'comprobante_ruta' => $comprobanteRelPath,
            'comprobante_path' => $comprobanteRelPath,
            'comprobante_url'  => starfi_waba_receipt_secure_url($comprobanteRelPath),
        ];
    } catch (Throwable $e) {
        if ($movedDestPath !== null && is_file($movedDestPath)) {
            @unlink($movedDestPath);
        }
        if ($own_tx) {
            @$con->rollback();
            if (function_exists('starfi_tx_set_active')) {
                starfi_tx_set_active($con, false);
            }
        } else {
            @$con->query("ROLLBACK TO SAVEPOINT sp_waba_report_payment");
        }
        starfi_log_exception('waba_ordenes', 'Excepción en starfi_report_waba_payment', [
            'id_orden' => $idOrden,
            'detail'   => $e->getMessage(),
        ]);
        return ['status' => 'error', 'message' => 'No se pudo procesar el reporte de pago en este momento.'];
    }
}

/**
 * Verificación / Auditoría transaccional de Pago por MASTER con bloqueo FOR UPDATE
 * y validación anti-duplicidad de referencias al aprobar o pasar a revisión.
 */
function starfi_verify_waba_payment(
    mysqli $con,
    int $idOrden,
    string $decision,
    string $notasVerificacion = '',
    int $idUsuario = 0
): array {
    if ($idOrden <= 0) {
        return ['status' => 'error', 'message' => 'ID de orden inválido.'];
    }

    $decisionNorm = strtoupper(trim($decision ?: 'APROBAR'));
    if (!in_array($decisionNorm, ['APROBAR', 'EN_REVISION', 'RECHAZAR'], true)) {
        $decisionNorm = 'RECHAZAR';
    }

    $colsOrd = starfi_waba_table_columns($con, 'waba_ordenes_cobro');
    $own_tx = !(function_exists('starfi_tx_is_active') && starfi_tx_is_active($con));
    if ($own_tx) {
        $con->begin_transaction();
        if (function_exists('starfi_tx_set_active')) {
            starfi_tx_set_active($con, true);
        }
    } else {
        @$con->query("SAVEPOINT sp_waba_verify_payment");
    }

    try {
        $stmtOrd = $con->prepare("SELECT * FROM waba_ordenes_cobro WHERE id = ? LIMIT 1 FOR UPDATE");
        if (!$stmtOrd) {
            throw new RuntimeException('No se pudo bloquear la orden para verificación.');
        }
        $stmtOrd->bind_param("i", $idOrden);
        $stmtOrd->execute();
        $resOrd = $stmtOrd->get_result();
        $ordRaw = ($resOrd && $resOrd->num_rows > 0) ? $resOrd->fetch_assoc() : null;
        $stmtOrd->close();

        if (!$ordRaw) {
            if ($own_tx) {
                $con->rollback();
                if (function_exists('starfi_tx_set_active')) starfi_tx_set_active($con, false);
            } else {
                @$con->query("ROLLBACK TO SAVEPOINT sp_waba_verify_payment");
            }
            return ['status' => 'error', 'message' => 'Orden no encontrada.'];
        }

        $ord = starfi_normalize_waba_order_row($ordRaw);
        $ordenSede = intval($ord['id_sede'] ?? 0);

        if (in_array($decisionNorm, ['APROBAR', 'EN_REVISION'], true)) {
            $dup = starfi_check_duplicate_waba_reference(
                $con,
                $idOrden,
                (string)($ord['metodo_pago'] ?? ''),
                (string)($ord['referencia_pago'] ?? '')
            );
            if ($dup !== null) {
                if ($own_tx) {
                    $con->rollback();
                    if (function_exists('starfi_tx_set_active')) starfi_tx_set_active($con, false);
                } else {
                    @$con->query("ROLLBACK TO SAVEPOINT sp_waba_verify_payment");
                }
                $dupLabel = !empty($dup['codigo_orden']) ? $dup['codigo_orden'] : ('#' . intval($dup['id']));
                return [
                    'status'             => 'error',
                    'duplicate'          => true,
                    'duplicate_order_id' => intval($dup['id']),
                    'message'            => "No se puede aprobar: la referencia de pago ya está asociada a la orden $dupLabel.",
                ];
            }
        }

        $notasFinal = (string)($ord['notas_pago'] ?? '');
        $notasVerifClean = trim($notasVerificacion);
        if ($notasVerifClean !== '') {
            if ($decisionNorm === 'APROBAR') {
                $prefix = '[Verificado MASTER]: ';
            } elseif ($decisionNorm === 'EN_REVISION') {
                $prefix = '[En Revisión MASTER]: ';
            } else {
                $prefix = '[Observación Rechazo MASTER]: ';
            }
            $notasFinal = trim($notasFinal . "\n" . $prefix . $notasVerifClean);
        }

        if ($decisionNorm === 'APROBAR') {
            $nuevoEstado = 'PAGADO';
            $msg = "Pago verificado correctamente. La orden #$idOrden ahora está marcada como PAGADO.";
        } elseif ($decisionNorm === 'EN_REVISION') {
            $nuevoEstado = 'EN_REVISION';
            $msg = "La orden #$idOrden fue colocada en estado PAGADA (PENDIENTE POR REVISIÓN).";
        } else {
            $nuevoEstado = 'PENDIENTE';
            $msg = "El reporte de pago fue rechazado y la orden #$idOrden regresó a estado PENDIENTE.";
        }

        $setParts = ["`estado` = ?"];
        $typesUp  = "s";
        $paramsUp = [$nuevoEstado];

        if (isset($colsOrd['notas_pago'])) {
            $setParts[] = "`notas_pago` = ?";
            $typesUp   .= "s";
            $paramsUp[] = $notasFinal;
        }
        if (isset($colsOrd['observaciones_pago'])) {
            $setParts[] = "`observaciones_pago` = ?";
            $typesUp   .= "s";
            $paramsUp[] = $notasFinal;
        }
        $uidParam = ($idUsuario > 0) ? $idUsuario : null;
        if (isset($colsOrd['verificado_por'])) {
            if ($decisionNorm === 'APROBAR') {
                $setParts[] = "`verificado_por` = ?";
                $typesUp   .= "i";
                $paramsUp[] = $uidParam;
            } else {
                $setParts[] = "`verificado_por` = NULL";
            }
        }
        if (isset($colsOrd['fecha_verificacion'])) {
            $setParts[] = ($decisionNorm === 'APROBAR') ? "`fecha_verificacion` = NOW()" : "`fecha_verificacion` = NULL";
        }

        $typesUp   .= "i";
        $paramsUp[] = $idOrden;

        $stmt = $con->prepare("UPDATE `waba_ordenes_cobro` SET " . implode(", ", $setParts) . " WHERE `id` = ?");
        if (!$stmt) {
            throw new RuntimeException('No se pudo preparar la actualización de verificación.');
        }
        $stmt->bind_param($typesUp, ...$paramsUp);
        if (!$stmt->execute()) {
            $sqlErrno = $stmt->errno;
            $stmt->close();
            starfi_log_exception('waba_ordenes', 'Error ejecutando verify_payment', ['id_orden' => $idOrden, 'errno' => $sqlErrno]);
            throw new RuntimeException('No se pudo actualizar el estado de verificación.');
        }
        $stmt->close();

        registrar_auditoria(
            'waba_ordenes',
            'VERIFICAR_PAGO_' . $decisionNorm,
            "Verificación MASTER sobre orden #$idOrden: $decisionNorm -> $nuevoEstado",
            [
                'id_orden'        => $idOrden,
                'estado'          => $ord['estado'] ?? null,
                'metodo_pago'     => $ord['metodo_pago'] ?? null,
                'referencia_pago' => $ord['referencia_pago'] ?? null,
            ],
            [
                'id_orden'           => $idOrden,
                'estado'             => $nuevoEstado,
                'decision'           => $decisionNorm,
                'notas_verificacion' => $notasVerifClean,
            ],
            $con,
            $uidParam,
            $ordenSede
        );

        if ($own_tx) {
            $con->commit();
            if (function_exists('starfi_tx_set_active')) {
                starfi_tx_set_active($con, false);
            }
        } else {
            @$con->query("RELEASE SAVEPOINT sp_waba_verify_payment");
        }

        return [
            'status'       => 'success',
            'message'      => $msg,
            'nuevo_estado' => $nuevoEstado,
        ];
    } catch (Throwable $e) {
        if ($own_tx) {
            @$con->rollback();
            if (function_exists('starfi_tx_set_active')) {
                starfi_tx_set_active($con, false);
            }
        } else {
            @$con->query("ROLLBACK TO SAVEPOINT sp_waba_verify_payment");
        }
        starfi_log_exception('waba_ordenes', 'Excepción en starfi_verify_waba_payment', [
            'id_orden' => $idOrden,
            'detail'   => $e->getMessage(),
        ]);
        return ['status' => 'error', 'message' => 'No se pudo actualizar el estado de verificación.'];
    }
}

/**
 * Motor Unificado de Conciliación Híbrida por Sede (100% mysqli_prepare()).
 */
function conciliar_consumo_sede($con, $id_sede, $fecha_desde, $fecha_hasta) {
    $id_sede = intval($id_sede);
    if ($id_sede <= 0 || empty($fecha_desde) || empty($fecha_hasta)) {
        return ['status' => 'error', 'message' => 'Parámetros de conciliación inválidos'];
    }

    $hoy_ymd = date('Y-m-d');
    $fecha_hasta_meta = ($fecha_hasta > $hoy_ymd) ? $hoy_ymd : $fecha_hasta;
    $start_ts = strtotime($fecha_desde . " 00:00:00");
    $end_ts   = strtotime($fecha_hasta_meta . " 23:59:59");
    if ($start_ts > $end_ts) {
        $start_ts = strtotime('-7 days', $end_ts);
    }
    $desde_dt = date('Y-m-d', strtotime($fecha_desde)) . " 00:00:00";
    $hasta_dt = date('Y-m-d', strtotime($fecha_hasta)) . " 23:59:59";

    $lineas = [];
    $waba_ids = [];
    $phone_numbers_clean = [];
    $phone_ids = [];

    $colsLin = starfi_waba_table_columns($con, 'lineas_whatsapp');
    $hasMetaTelId = isset($colsLin['meta_telefono_id']);

    $sqlLin = $hasMetaTelId
        ? "SELECT id, numero_telefono, meta_app_id, meta_telefono_id, meta_token, id_negocio, estado FROM lineas_whatsapp WHERE id_sede = ? ORDER BY (estado = 'ACTIVO') DESC, id ASC"
        : "SELECT id, numero_telefono, meta_app_id, '' AS meta_telefono_id, meta_token, id_negocio, estado FROM lineas_whatsapp WHERE id_sede = ? ORDER BY (estado = 'ACTIVO') DESC, id ASC";

    $stmtLin = $con->prepare($sqlLin);
    if ($stmtLin) {
        $stmtLin->bind_param("i", $id_sede);
        $stmtLin->execute();
        $resLin = $stmtLin->get_result();
        while ($resLin && ($l = $resLin->fetch_assoc())) {
            $lineas[] = $l;
            if (!empty($l['id_negocio'])) {
                $waba_ids[$l['id_negocio']] = $l['meta_token'] ?: (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');
            }
            if (!empty($l['numero_telefono'])) {
                $clean_num = preg_replace('/\D+/', '', (string)$l['numero_telefono']);
                if ($clean_num !== '') {
                    $phone_numbers_clean[$clean_num] = true;
                }
            }
            if (!empty($l['meta_telefono_id'])) {
                $phone_ids[$l['meta_telefono_id']] = true;
            }
            if (!empty($l['meta_app_id'])) {
                $phone_ids[$l['meta_app_id']] = true;
            }
        }
        $stmtLin->close();
    }

    if (empty($waba_ids)) {
        return ['status' => 'error', 'message' => 'Sede sin cuenta WABA (id_negocio) configurada en líneas de WhatsApp'];
    }

    $primary_waba_id = array_key_first($waba_ids);

    $tipo_tarifa  = 'PORCENTAJE';
    $valor_tarifa = 10.00;
    $limite_fact  = 0.00;
    $colsTar = starfi_waba_table_columns($con, 'waba_tarifas_sede');
    if (isset($colsTar['tipo_tarifa']) && isset($colsTar['valor'])) {
        $stmtTar = $con->prepare("SELECT tipo_tarifa, valor, limite_facturacion FROM waba_tarifas_sede WHERE id_sede = ? LIMIT 1");
        if ($stmtTar) {
            $stmtTar->bind_param("i", $id_sede);
            $stmtTar->execute();
            $resTar = $stmtTar->get_result();
            if ($resTar && ($rowT = $resTar->fetch_assoc())) {
                $tipo_tarifa  = $rowT['tipo_tarifa'] ?: 'PORCENTAJE';
                $valor_tarifa = floatval($rowT['valor']);
                $limite_fact  = floatval($rowT['limite_facturacion'] ?? 0);
            }
            $stmtTar->close();
        }
    } elseif (isset($colsTar['limite_facturacion'])) {
        $stmtTar = $con->prepare("SELECT limite_facturacion FROM waba_tarifas_sede WHERE id_sede = ? LIMIT 1");
        if ($stmtTar) {
            $stmtTar->bind_param("i", $id_sede);
            $stmtTar->execute();
            $resTar = $stmtTar->get_result();
            if ($resTar && ($rowT = $resTar->fetch_assoc())) {
                $limite_fact = floatval($rowT['limite_facturacion'] ?? 0);
            }
            $stmtTar->close();
        }
    }

    $costo_unit_mkt_ref  = 0.0700;
    $costo_unit_util_ref = 0.0150;
    if (starfi_waba_table_exists($con, 'campanas_cuotas_sede')) {
        $stmtCuota = $con->prepare("SELECT costo_marketing_usd, costo_utility_usd FROM campanas_cuotas_sede WHERE id_sede = ? LIMIT 1");
        if ($stmtCuota) {
            $stmtCuota->bind_param("i", $id_sede);
            $stmtCuota->execute();
            $resCuota = $stmtCuota->get_result();
            if ($resCuota && ($rc = $resCuota->fetch_assoc())) {
                if (floatval($rc['costo_marketing_usd']) > 0) $costo_unit_mkt_ref = floatval($rc['costo_marketing_usd']);
                if (floatval($rc['costo_utility_usd']) > 0)   $costo_unit_util_ref = floatval($rc['costo_utility_usd']);
            }
            $stmtCuota->close();
        }
    }

    $costo_meta_api_por_cat = [
        'MARKETING'      => 0.0,
        'UTILITY'        => 0.0,
        'AUTHENTICATION' => 0.0,
        'SERVICE'        => 0.0,
    ];
    $mensajes_meta_api = 0;
    static $meta_api_mem_cache = [];

    $stmtShared = $con->prepare("SELECT COUNT(DISTINCT id_sede) AS total_sedes FROM lineas_whatsapp WHERE id_negocio = ? AND estado = 'ACTIVO'");

    foreach ($waba_ids as $waba_id => $line_token) {
        $token_to_use = (defined('META_GLOBAL_TOKEN') && META_GLOBAL_TOKEN !== '') ? META_GLOBAL_TOKEN : $line_token;
        $total_sedes_waba = 1;
        if ($stmtShared) {
            $wabaStr = (string)$waba_id;
            $stmtShared->bind_param("s", $wabaStr);
            $stmtShared->execute();
            $resSh = $stmtShared->get_result();
            if ($resSh && ($rs = $resSh->fetch_assoc())) {
                $total_sedes_waba = max(1, intval($rs['total_sedes']));
            }
        }

        $wabaEnc = rawurlencode((string)$waba_id);
        $fields_req = ($total_sedes_waba > 1)
            ? "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY,PHONE),analytics.start($start_ts).end($end_ts).granularity(DAY)"
            : "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY),analytics.start($start_ts).end($end_ts).granularity(DAY)";

        $cache_key_waba = $waba_id . '_' . $start_ts . '_' . $end_ts . '_' . $total_sedes_waba;
        if (isset($meta_api_mem_cache[$cache_key_waba])) {
            $resp = $meta_api_mem_cache[$cache_key_waba];
        } else {
            $headers = ["Authorization: Bearer " . $token_to_use, "Content-Type: application/json"];
            $resp = ($token_to_use !== '')
                ? starfi_waba_http_request("https://graph.facebook.com/v23.0/$wabaEnc?fields=$fields_req", $headers)
                : null;

            if (is_array($resp) && isset($resp['error']) && $total_sedes_waba > 1 && $token_to_use !== '') {
                $fields_basic = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY),analytics.start($start_ts).end($end_ts).granularity(DAY)";
                $resp = starfi_waba_http_request("https://graph.facebook.com/v23.0/$wabaEnc?fields=$fields_basic", $headers);
            }
            $meta_api_mem_cache[$cache_key_waba] = $resp;
        }

        if (is_array($resp) && !isset($resp['error'])) {
            $points = $resp['pricing_analytics']['data'][0]['data_points'] ?? [];
            foreach ($points as $dp) {
                if (!empty($dp['phone'])) {
                    $dp_phone = preg_replace('/\D+/', '', strval($dp['phone']));
                    if (!empty($phone_numbers_clean) && !isset($phone_numbers_clean[$dp_phone]) && !isset($phone_ids[$dp['phone']])) {
                        $matched_phone = false;
                        foreach (array_keys($phone_numbers_clean) as $sede_num) {
                            if (strlen($sede_num) >= 8 && strlen($dp_phone) >= 8 && substr($sede_num, -8) === substr($dp_phone, -8)) {
                                $matched_phone = true;
                                break;
                            }
                        }
                        if (!$matched_phone) {
                            continue;
                        }
                    }
                } elseif ($total_sedes_waba > 1) {
                    continue;
                }

                $cat = strtoupper($dp['pricing_category'] ?? ($dp['category'] ?? 'MARKETING'));
                $c   = floatval($dp['cost'] ?? 0);
                if (!isset($costo_meta_api_por_cat[$cat])) {
                    $costo_meta_api_por_cat[$cat] = 0.0;
                }
                $costo_meta_api_por_cat[$cat] += $c;
            }

            if ($total_sedes_waba <= 1) {
                $analytics_pts = $resp['analytics']['data_points'] ?? ($resp['analytics']['data'][0]['data_points'] ?? []);
                foreach ($analytics_pts as $dp) {
                    if (isset($dp['sent'])) {
                        $mensajes_meta_api += intval($dp['sent']);
                    }
                }
            }
        }
    }
    if ($stmtShared) {
        $stmtShared->close();
    }

    $cat_details = [
        'MARKETING'      => ['nombre' => 'Mensajes de Marketing (Campañas y Difusión)',   'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
        'UTILITY'        => ['nombre' => 'Mensajes de Utilidad (Notificaciones)',         'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
        'AUTHENTICATION' => ['nombre' => 'Mensajes de Autenticación',                     'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
        'SERVICE'        => ['nombre' => 'Mensajes de Servicio y Atención (Bandeja/Bot)', 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0],
    ];
    $costo_local_por_cat = [
        'MARKETING'      => 0.0,
        'UTILITY'        => 0.0,
        'AUTHENTICATION' => 0.0,
        'SERVICE'        => 0.0,
    ];

    $camp_ids_active = [];
    $hasCampDest = starfi_waba_table_exists($con, 'campanas_difusion_destinatarios');
    $colsCamp    = $hasCampDest ? starfi_waba_table_columns($con, 'campanas_difusion') : [];
    $colsDest    = $hasCampDest ? starfi_waba_table_columns($con, 'campanas_difusion_destinatarios') : [];

    if ($hasCampDest && !empty($colsDest)) {
        $catExpr   = isset($colsCamp['template_category']) ? "c.template_category" : (isset($colsCamp['categoria_meta']) ? "c.categoria_meta" : "''");
        $dateCamp  = isset($colsCamp['fecha_creacion']) ? "c.fecha_creacion" : "c.created_at";
        $costExpr  = isset($colsDest['costo_usd'])
            ? "CASE WHEN d.costo_usd > 0 THEN d.costo_usd WHEN c.costo_unitario_usd > 0 THEN c.costo_unitario_usd ELSE ? END"
            : "CASE WHEN c.costo_unitario_usd > 0 THEN c.costo_unitario_usd ELSE ? END";
        $sedeWhere = isset($colsDest['id_sede'])
            ? "(d.id_sede = ? OR (d.id_sede = 0 AND c.id_sede = ?))"
            : "c.id_sede = ?";

        $sqlCamp = "SELECT 
                        UPPER(COALESCE(NULLIF($catExpr, ''), 'MARKETING')) AS cat,
                        COUNT(d.id) AS vol,
                        SUM($costExpr) AS costo_camp,
                        GROUP_CONCAT(DISTINCT d.id_campana) AS camp_ids
                    FROM campanas_difusion_destinatarios d
                    LEFT JOIN campanas_difusion c ON d.id_campana = c.id
                    WHERE $sedeWhere
                      AND d.estado_envio IN ('ENVIADO', 'ENTREGADO', 'LEIDO', 'RESPONDIDO')
                      AND (
                          (d.fecha_envio >= ? AND d.fecha_envio <= ?)
                          OR (d.fecha_envio IS NULL AND $dateCamp >= ? AND $dateCamp <= ?)
                      )
                    GROUP BY cat";

        $stmtCamp = $con->prepare($sqlCamp);
        if ($stmtCamp) {
            if (isset($colsDest['id_sede'])) {
                $stmtCamp->bind_param("diissss", $costo_unit_mkt_ref, $id_sede, $id_sede, $desde_dt, $hasta_dt, $desde_dt, $hasta_dt);
            } else {
                $stmtCamp->bind_param("dissss", $costo_unit_mkt_ref, $id_sede, $desde_dt, $hasta_dt, $desde_dt, $hasta_dt);
            }
            $stmtCamp->execute();
            $resCamp = $stmtCamp->get_result();
            while ($resCamp && ($rc = $resCamp->fetch_assoc())) {
                $cat = $rc['cat'] ?: 'MARKETING';
                if (!empty($rc['camp_ids'])) {
                    foreach (explode(',', (string)$rc['camp_ids']) as $cid) {
                        $cid_int = intval($cid);
                        if ($cid_int > 0) {
                            $camp_ids_active[$cid_int] = $cid_int;
                        }
                    }
                }
                if (!isset($cat_details[$cat])) {
                    $cat_details[$cat] = ['nombre' => 'Mensajes ' . ucfirst(strtolower($cat)), 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0];
                    $costo_local_por_cat[$cat] = 0.0;
                }
                $vol_c   = intval($rc['vol']);
                $costo_c = floatval($rc['costo_camp']);
                $cat_details[$cat]['volumen'] += $vol_c;
                $cat_details[$cat]['pagados'] += $vol_c;
                $costo_local_por_cat[$cat]    += $costo_c;
            }
            $stmtCamp->close();
        }
    }

    $exclude_camp_wamid_sql = "";
    $msgTypes  = "iss";
    $msgParams = [$id_sede, $desde_dt, $hasta_dt];

    if ($hasCampDest && !empty($camp_ids_active)) {
        $placeholders = implode(',', array_fill(0, count($camp_ids_active), '?'));
        $convJoinCond = isset($colsDest['id_conversacion'])
            ? "AND (d2.id_conversacion = m.id_conversacion OR d2.id_conversacion = 0)"
            : "";
        $exclude_camp_wamid_sql = " AND (
            m.origen != 'API_TRANSACCIONAL'
            OR m.id_mensaje_meta IS NULL
            OR m.id_mensaje_meta = ''
            OR NOT EXISTS (
                SELECT 1 FROM campanas_difusion_destinatarios d2
                WHERE d2.id_campana IN ($placeholders)
                  $convJoinCond
                  AND d2.id_mensaje_meta COLLATE utf8mb4_unicode_ci = m.id_mensaje_meta COLLATE utf8mb4_unicode_ci
            )
        )";
        foreach ($camp_ids_active as $cidInt) {
            $msgTypes   .= "i";
            $msgParams[] = intval($cidInt);
        }
    }

    $q_msg = "SELECT 
                UPPER(COALESCE(NULLIF(m.categoria_meta, ''), 
                    CASE WHEN m.origen = 'API_TRANSACCIONAL' THEN 'UTILITY' ELSE 'SERVICE' END
                )) AS cat,
                COUNT(m.id) AS vol,
                SUM(CASE WHEN m.es_pagado = 1 THEN 1 ELSE 0 END) AS pag,
                SUM(CASE WHEN m.es_pagado = 0 OR m.es_pagado IS NULL THEN 1 ELSE 0 END) AS grat,
                SUM(COALESCE(m.costo_meta_estimado, 0)) AS costo_est
              FROM mensajes_y_eventos m
              JOIN conversaciones conv ON m.id_conversacion = conv.id
              JOIN lineas_whatsapp l ON conv.id_linea = l.id
              WHERE l.id_sede = ?
                AND m.origen != 'CLIENTE'
                AND m.timestamp >= ?
                AND m.timestamp <= ?
                $exclude_camp_wamid_sql
              GROUP BY cat";

    $stmtMsg = $con->prepare($q_msg);
    if ($stmtMsg) {
        $stmtMsg->bind_param($msgTypes, ...$msgParams);
        $stmtMsg->execute();
        $resMsg = $stmtMsg->get_result();
        while ($resMsg && ($rm = $resMsg->fetch_assoc())) {
            $cat = $rm['cat'] ?: 'SERVICE';
            if (!isset($cat_details[$cat])) {
                $cat_details[$cat] = ['nombre' => 'Mensajes ' . ucfirst(strtolower($cat)), 'volumen' => 0, 'costo' => 0.0, 'pagados' => 0, 'gratuitos' => 0];
                $costo_local_por_cat[$cat] = 0.0;
            }
            $vol_m  = intval($rm['vol']);
            $pag_m  = intval($rm['pag']);
            $grat_m = intval($rm['grat']);
            $c_est  = floatval($rm['costo_est']);

            if ($c_est <= 0 && $pag_m > 0) {
                $unit_fallback = ($cat === 'MARKETING') ? $costo_unit_mkt_ref : (($cat === 'UTILITY' || $cat === 'AUTHENTICATION') ? $costo_unit_util_ref : 0.0);
                $c_est = $pag_m * $unit_fallback;
            }

            $cat_details[$cat]['volumen']   += $vol_m;
            $cat_details[$cat]['pagados']   += $pag_m;
            $cat_details[$cat]['gratuitos'] += $grat_m;
            $costo_local_por_cat[$cat]      += $c_est;
        }
        $stmtMsg->close();
    }

    $costo_meta_total    = 0.0;
    $volumen_local_total = 0;

    foreach ($cat_details as $cat => &$cd) {
        $c_api = floatval($costo_meta_api_por_cat[$cat] ?? 0.0);
        $c_loc = floatval($costo_local_por_cat[$cat] ?? 0.0);
        $costo_cat = ($c_api > 0 && $c_api >= $c_loc) ? $c_api : $c_loc;
        $cd['costo'] = round($costo_cat, 4);
        $costo_meta_total    += $cd['costo'];
        $volumen_local_total += $cd['volumen'];
    }
    unset($cd);

    $mensajes_totales = max($volumen_local_total, $mensajes_meta_api);
    if ($volumen_local_total === 0 && ($mensajes_totales > 0 || $costo_meta_total > 0)) {
        $cat_details['MARKETING']['volumen'] = $mensajes_totales;
        $cat_details['MARKETING']['pagados'] = $mensajes_totales;
    }

    if ($tipo_tarifa === 'PORCENTAJE') {
        $costo_final = $costo_meta_total * (1 + ($valor_tarifa / 100));
        $margen      = $costo_final - $costo_meta_total;
    } elseif ($tipo_tarifa === 'FIJA') {
        $margen      = $mensajes_totales * $valor_tarifa;
        $costo_final = $costo_meta_total + $margen;
    } elseif ($tipo_tarifa === 'PORCENTAJE_VOLUMEN') {
        $p           = $mensajes_totales > 1000 ? ($valor_tarifa / 2) : $valor_tarifa;
        $costo_final = $costo_meta_total * (1 + ($p / 100));
        $margen      = $costo_final - $costo_meta_total;
    } else {
        $costo_final = $costo_meta_total * 1.10;
        $margen      = $costo_final - $costo_meta_total;
    }

    return [
        'status'           => 'success',
        'waba_id'          => $primary_waba_id,
        'mensajes_totales' => $mensajes_totales,
        'costo_meta'       => round($costo_meta_total, 4),
        'margen'           => round($margen, 4),
        'costo_final'      => round($costo_final, 2),
        'tipo_tarifa'      => $tipo_tarifa,
        'valor_tarifa'     => $valor_tarifa,
        'limite_fact'      => $limite_fact,
        'cat_details'      => $cat_details,
    ];
}

/**
 * Envía notificaciones de orden de cobro (WhatsApp + Email) usando mysqli_prepare() y timeouts <= 8s.
 */
function starfi_notify_waba_order(mysqli $con, int $id_orden, int $id_sede, string $fecha_desde, string $fecha_hasta, float $costo_final, string $waba_id = '', bool $is_reminder = false): int {
    $notificado = 0;
    $stmtS = $con->prepare("SELECT * FROM sedes WHERE id = ? LIMIT 1");
    $s = null;
    if ($stmtS) {
        $stmtS->bind_param("i", $id_sede);
        $stmtS->execute();
        $resS = $stmtS->get_result();
        $s = ($resS && $resS->num_rows > 0) ? $resS->fetch_assoc() : null;
        $stmtS->close();
    }
    if (!$s) {
        return 0;
    }

    $gerenteNombre = trim((string)($s['gerente_nombre'] ?? '')) ?: 'Administración';
    $gerenteTel    = trim((string)($s['gerente_telefono'] ?? ($s['telefono_notificaciones'] ?? '')));
    $emailDestino  = trim((string)($s['gerente_email'] ?? ($s['correo_notificaciones'] ?? ($s['email'] ?? ''))));
    $prefNotif     = strtoupper(trim((string)($s['pref_not_cobro'] ?? 'WHATSAPP')));
    $nombreSede    = (string)($s['nombre_sede'] ?? ('Sede #' . $id_sede));
    $montoFmt      = number_format($costo_final, 2, '.', '');

    $msg = $is_reminder
        ? "Hola $gerenteNombre, le recordamos la orden de cobro *#$id_orden* para *$nombreSede* por el consumo de WhatsApp API.\n\n*Periodo:* $fecha_desde al $fecha_hasta\n*Monto Total:* $montoFmt USD\n\nPor favor coordine el pago en el módulo Órdenes WABA (modules/waba_ordenes/waba_ordenes.php)."
        : "Hola $gerenteNombre, se ha generado la orden de cobro *#$id_orden* para *$nombreSede* por el consumo de WhatsApp API.\n\n*Periodo:* $fecha_desde al $fecha_hasta\n*Monto Total:* $montoFmt USD\n\nPor favor coordine el pago en el módulo Órdenes WABA (modules/waba_ordenes/waba_ordenes.php).";

    if (in_array($prefNotif, ['WHATSAPP', 'AMBOS'], true) && $gerenteTel !== '') {
        $stmtL = $con->prepare("SELECT meta_app_id, meta_token, id_negocio FROM lineas_whatsapp WHERE id_sede = ? AND estado = 'ACTIVO' LIMIT 1");
        $l = null;
        if ($stmtL) {
            $stmtL->bind_param("i", $id_sede);
            $stmtL->execute();
            $resL = $stmtL->get_result();
            $l = ($resL && $resL->num_rows > 0) ? $resL->fetch_assoc() : null;
            $stmtL->close();
        }
        if ($l && !empty($l['meta_token']) && !empty($l['meta_app_id'])) {
            $wabaToken  = $l['meta_token'];
            $wabaTarget = $waba_id !== '' ? $waba_id : (string)($l['id_negocio'] ?? '');
            $headersJson = ["Authorization: Bearer $wabaToken", "Content-Type: application/json"];

            if (!$is_reminder && $wabaTarget !== '') {
                $checkUrl = "https://graph.facebook.com/v23.0/" . rawurlencode($wabaTarget) . "/message_templates?name=notificacion_orden_cobro";
                $resCk = starfi_waba_http_request($checkUrl, ["Authorization: Bearer $wabaToken"]);
                if (!isset($resCk['data']) || count($resCk['data']) === 0) {
                    $payloadCreate = [
                        "name"                  => "notificacion_orden_cobro",
                        "category"              => "UTILITY",
                        "allow_category_change" => true,
                        "language"              => "es",
                        "components"            => [[
                            "type"    => "BODY",
                            "text"    => "Hola {{1}}, se ha generado la orden de cobro *#{{2}}* para *{{3}}* por el consumo de WhatsApp API.\n\n*Periodo:* {{4}} al {{5}}\n*Monto Total:* {{6}} USD\n\nPor favor coordine el pago correspondiente.",
                            "example" => ["body_text" => [[$gerenteNombre, strval($id_orden), $nombreSede, $fecha_desde, $fecha_hasta, $montoFmt]]],
                        ]],
                    ];
                    starfi_waba_http_request("https://graph.facebook.com/v23.0/" . rawurlencode($wabaTarget) . "/message_templates", $headersJson, $payloadCreate);
                }
            }

            $urlW = "https://graph.facebook.com/v23.0/" . rawurlencode((string)$l['meta_app_id']) . "/messages";
            $payloadW = [
                'messaging_product' => 'whatsapp',
                'to'                => $gerenteTel,
                'type'              => 'template',
                'template'          => [
                    'name'       => 'notificacion_orden_cobro',
                    'language'   => ['code' => 'es'],
                    'components' => [[
                        'type'       => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $gerenteNombre],
                            ['type' => 'text', 'text' => strval($id_orden)],
                            ['type' => 'text', 'text' => $nombreSede],
                            ['type' => 'text', 'text' => $fecha_desde],
                            ['type' => 'text', 'text' => $fecha_hasta],
                            ['type' => 'text', 'text' => $montoFmt],
                        ],
                    ]],
                ],
            ];

            $respDec = starfi_waba_http_request($urlW, $headersJson, $payloadW);
            if (is_array($respDec) && isset($respDec['messages'])) {
                $notificado = 1;
            } elseif ($is_reminder) {
                $payloadText = [
                    'messaging_product' => 'whatsapp',
                    'to'                => $gerenteTel,
                    'type'              => 'text',
                    'text'              => ['body' => $msg],
                ];
                $respDec2 = starfi_waba_http_request($urlW, $headersJson, $payloadText);
                if (is_array($respDec2) && isset($respDec2['messages'])) {
                    $notificado = 1;
                }
            }
        }
    }

    if ($emailDestino !== '') {
        require_once __DIR__ . '/../../core/services/MailerService.php';
        $mailer = new MailerService($con);
        $tituloEmail = $is_reminder ? 'Recordatorio de Cobro' : 'Notificación de Cobro';
        $asunto = ($is_reminder ? 'Recordatorio de Estado de Cuenta WhatsApp API - ' : 'Estado de Cuenta WhatsApp API - ') . $nombreSede;
        $codigoDoc = "NC-" . date('Y') . "-" . str_pad((string)$id_orden, 4, '0', STR_PAD_LEFT);
        $htmlMsg = "
        <div style='font-family: Gotham, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;'>
            <div style='background-color: #2b6cb0; color: white; padding: 20px; text-align: center;'>
                <h2 style='margin: 0;'>" . htmlspecialchars($tituloEmail, ENT_QUOTES, 'UTF-8') . "</h2>
                <p style='margin: 5px 0 0 0; font-size: 14px; opacity: 0.9;'>Infraestructura API WhatsApp</p>
            </div>
            <div style='padding: 30px; background-color: #ffffff;'>
                <p style='font-size: 16px; color: #333;'>Hola <strong>" . htmlspecialchars($gerenteNombre, ENT_QUOTES, 'UTF-8') . "</strong>,</p>
                <p style='color: #4a5568; line-height: 1.6;'>Existe una orden de cobro para la sede <strong>" . htmlspecialchars($nombreSede, ENT_QUOTES, 'UTF-8') . "</strong> correspondiente a los servicios de mensajería consolidada y costos operativos.</p>
                <div style='background-color: #f7fafc; border-left: 4px solid #3182ce; padding: 15px; margin: 20px 0;'>
                    <p style='margin: 0 0 10px 0;'><strong>Orden #:</strong> " . htmlspecialchars($codigoDoc, ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin: 0 0 10px 0;'><strong>Periodo:</strong> " . htmlspecialchars($fecha_desde, ENT_QUOTES, 'UTF-8') . " al " . htmlspecialchars($fecha_hasta, ENT_QUOTES, 'UTF-8') . "</p>
                    <p style='margin: 0; font-size: 18px;'><strong>Monto a Pagar: <span style='color: #c53030;'>\$" . htmlspecialchars($montoFmt, ENT_QUOTES, 'UTF-8') . " USD</span></strong></p>
                </div>
                <p style='color: #4a5568; font-size: 14px; line-height: 1.5;'>Por favor coordine el pago correspondiente y registre su comprobante en el módulo de Órdenes WABA (<code>modules/waba_ordenes/waba_ordenes.php</code>).</p>
            </div>
        </div>";

        $mailRes = $mailer->sendDirectEmail($emailDestino, $gerenteNombre, $asunto, $htmlMsg);
        if (isset($mailRes['status']) && $mailRes['status'] === 'success') {
            $notificado = 1;
        }
    }

    return $notificado;
}

/**
 * [QA-BUG-CRM3-05 / CHK-SEC-05]
 * Emisión centralizada de Orden de Cobro por Sede con bloqueo transaccional SELECT ... FOR UPDATE
 * contra duplicidad/solapamiento de periodos (100% mysqli_prepare() + registrar_auditoria()).
 */
function emitir_orden_sede($con, $id_sede, $fecha_desde, $fecha_hasta, $orden_pendiente_id = null) {
    ensureWabaBillingSchema($con);

    $id_sede = intval($id_sede);
    $fecha_desde_clean = trim((string)$fecha_desde);
    $fecha_hasta_clean = trim((string)$fecha_hasta);

    if ($id_sede <= 0 || $fecha_desde_clean === '' || $fecha_hasta_clean === '') {
        return ['status' => 'error', 'message' => 'Faltan parámetros para emitir la orden'];
    }
    $tsDesde = strtotime($fecha_desde_clean);
    $tsHasta = strtotime($fecha_hasta_clean);
    if (!$tsDesde || !$tsHasta || $tsDesde > $tsHasta) {
        return ['status' => 'error', 'message' => 'Rango de fechas inválido para la emisión de la orden'];
    }
    $fecha_desde = date('Y-m-d', $tsDesde);
    $fecha_hasta = date('Y-m-d', $tsHasta);

    $colsOrd = starfi_waba_table_columns($con, 'waba_ordenes_cobro');
    $colsDet = starfi_waba_table_columns($con, 'waba_ordenes_detalles');

    $own_tx = !(function_exists('starfi_tx_is_active') && starfi_tx_is_active($con));
    if ($own_tx) {
        $con->begin_transaction();
        if (function_exists('starfi_tx_set_active')) {
            starfi_tx_set_active($con, true);
        }
    } else {
        @$con->query("SAVEPOINT sp_emitir_orden");
    }

    $rollbackCurrentScope = static function () use ($con, $own_tx): void {
        if ($own_tx) {
            @$con->rollback();
            if (function_exists('starfi_tx_set_active')) {
                starfi_tx_set_active($con, false);
            }
        } else {
            @$con->query("ROLLBACK TO SAVEPOINT sp_emitir_orden");
        }
    };

    try {
        // 1. Bloqueo pesimista SELECT ... FOR UPDATE para impedir órdenes duplicadas o solapadas en la misma sede
        if ($orden_pendiente_id === null || intval($orden_pendiente_id) <= 0) {
            $sqlOverlap = isset($colsOrd['codigo_orden'])
                ? "SELECT id, codigo_orden FROM waba_ordenes_cobro WHERE id_sede = ? AND estado NOT IN ('FUSIONADO', 'ANULADA') AND fecha_desde <= ? AND fecha_hasta >= ? LIMIT 1 FOR UPDATE"
                : "SELECT id, CONCAT('NC-', id) AS codigo_orden FROM waba_ordenes_cobro WHERE id_sede = ? AND estado NOT IN ('FUSIONADO', 'ANULADA') AND fecha_desde <= ? AND fecha_hasta >= ? LIMIT 1 FOR UPDATE";
            $stmtLockOverlap = $con->prepare($sqlOverlap);
            if (!$stmtLockOverlap) {
                throw new RuntimeException('No se pudo preparar validación anti-solapamiento de periodo.');
            }
            $stmtLockOverlap->bind_param("iss", $id_sede, $fecha_hasta, $fecha_desde);
            $stmtLockOverlap->execute();
            $resOverlap = $stmtLockOverlap->get_result();
            $dupRow = ($resOverlap && $resOverlap->num_rows > 0) ? $resOverlap->fetch_assoc() : null;
            $stmtLockOverlap->close();

            if ($dupRow) {
                $rollbackCurrentScope();
                $dupCode = !empty($dupRow['codigo_orden']) ? $dupRow['codigo_orden'] : ('#' . intval($dupRow['id']));
                return [
                    'status'               => 'error',
                    'code'                 => 'DUPLICATE_PERIOD_ORDER',
                    'duplicate'            => true,
                    'overlapping_order_id' => intval($dupRow['id']),
                    'message'              => 'Ya existe una orden de cobro activa (' . $dupCode . ') que solapa el periodo seleccionado para esta sede.',
                ];
            }
        }

        // 2. Conciliar consumo híbrido de la sede
        $conciliacion = conciliar_consumo_sede($con, $id_sede, $fecha_desde, $fecha_hasta);
        if (($conciliacion['status'] ?? '') !== 'success') {
            $rollbackCurrentScope();
            return $conciliacion;
        }

        $waba_id          = (string)$conciliacion['waba_id'];
        $mensajes_totales = intval($conciliacion['mensajes_totales']);
        $costo_meta       = floatval($conciliacion['costo_meta']);
        $margen           = floatval($conciliacion['margen']);
        $costo_final      = floatval($conciliacion['costo_final']);
        $cat_details      = $conciliacion['cat_details'];

        $id_padre = ($orden_pendiente_id !== null && intval($orden_pendiente_id) > 0) ? intval($orden_pendiente_id) : 0;
        $padreAnterior = null;
        if ($id_padre > 0) {
            $stmtPrev = $con->prepare("SELECT id, estado, monto_total FROM waba_ordenes_cobro WHERE id = ? AND id_sede = ? LIMIT 1 FOR UPDATE");
            if ($stmtPrev) {
                $stmtPrev->bind_param("ii", $id_padre, $id_sede);
                $stmtPrev->execute();
                $resPrev = $stmtPrev->get_result();
                $padreAnterior = ($resPrev && $resPrev->num_rows > 0) ? $resPrev->fetch_assoc() : null;
                $stmtPrev->close();
            }

            if ($padreAnterior) {
                $stmtFus = $con->prepare("UPDATE waba_ordenes_cobro SET estado = 'FUSIONADO' WHERE id = ?");
                if ($stmtFus) {
                    $stmtFus->bind_param("i", $id_padre);
                    $stmtFus->execute();
                    $stmtFus->close();
                }
            }

            // Verificar que no exista otra orden activa distinta a la fusionada que solape el nuevo rango
            $sqlOverlapFus = isset($colsOrd['codigo_orden'])
                ? "SELECT id, codigo_orden FROM waba_ordenes_cobro WHERE id_sede = ? AND id != ? AND estado NOT IN ('FUSIONADO', 'ANULADA') AND fecha_desde <= ? AND fecha_hasta >= ? LIMIT 1 FOR UPDATE"
                : "SELECT id, CONCAT('NC-', id) AS codigo_orden FROM waba_ordenes_cobro WHERE id_sede = ? AND id != ? AND estado NOT IN ('FUSIONADO', 'ANULADA') AND fecha_desde <= ? AND fecha_hasta >= ? LIMIT 1 FOR UPDATE";
            $stmtLockFus = $con->prepare($sqlOverlapFus);
            if ($stmtLockFus) {
                $stmtLockFus->bind_param("iiss", $id_sede, $id_padre, $fecha_hasta, $fecha_desde);
                $stmtLockFus->execute();
                $resDupFus = $stmtLockFus->get_result();
                $dupFusRow = ($resDupFus && $resDupFus->num_rows > 0) ? $resDupFus->fetch_assoc() : null;
                $stmtLockFus->close();
                if ($dupFusRow) {
                    $rollbackCurrentScope();
                    $dupCode = !empty($dupFusRow['codigo_orden']) ? $dupFusRow['codigo_orden'] : ('#' . intval($dupFusRow['id']));
                    return [
                        'status'               => 'error',
                        'code'                 => 'DUPLICATE_PERIOD_ORDER',
                        'duplicate'            => true,
                        'overlapping_order_id' => intval($dupFusRow['id']),
                        'message'              => 'Ya existe una orden de cobro activa (' . $dupCode . ') que solapa el periodo seleccionado para esta sede.',
                    ];
                }
            }
        }

        $codigoTemp = 'NC-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $insCols    = ['id_sede', 'fecha_desde', 'fecha_hasta'];
        $insTypes   = 'iss';
        $insParams  = [$id_sede, $fecha_desde, $fecha_hasta];

        if (isset($colsOrd['codigo_orden'])) {
            $insCols[]   = 'codigo_orden';
            $insTypes   .= 's';
            $insParams[] = $codigoTemp;
        }
        if (isset($colsOrd['mensajes_totales'])) {
            $insCols[]   = 'mensajes_totales';
            $insTypes   .= 'i';
            $insParams[] = $mensajes_totales;
        }
        if (isset($colsOrd['total_mensajes'])) {
            $insCols[]   = 'total_mensajes';
            $insTypes   .= 'i';
            $insParams[] = $mensajes_totales;
        }
        if (isset($colsOrd['costo_meta'])) {
            $insCols[]   = 'costo_meta';
            $insTypes   .= 'd';
            $insParams[] = $costo_meta;
        }
        if (isset($colsOrd['costo_meta_usd'])) {
            $insCols[]   = 'costo_meta_usd';
            $insTypes   .= 'd';
            $insParams[] = $costo_meta;
        }
        if (isset($colsOrd['margen_ganancia'])) {
            $insCols[]   = 'margen_ganancia';
            $insTypes   .= 'd';
            $insParams[] = $margen;
        }
        if (isset($colsOrd['monto_total'])) {
            $insCols[]   = 'monto_total';
            $insTypes   .= 'd';
            $insParams[] = $costo_final;
        }
        if (isset($colsOrd['monto_total_usd'])) {
            $insCols[]   = 'monto_total_usd';
            $insTypes   .= 'd';
            $insParams[] = $costo_final;
        }
        if ($id_padre > 0 && isset($colsOrd['orden_padre_id'])) {
            $insCols[]   = 'orden_padre_id';
            $insTypes   .= 'i';
            $insParams[] = $id_padre;
        }

        $placeholders = implode(', ', array_fill(0, count($insCols), '?'));
        $colsSql      = '`' . implode('`, `', $insCols) . '`';
        $stmt = $con->prepare("INSERT INTO `waba_ordenes_cobro` ($colsSql) VALUES ($placeholders)");
        if (!$stmt) {
            starfi_log_exception('waba_ordenes', 'Error preparando INSERT waba_ordenes_cobro', ['errno' => $con->errno]);
            throw new RuntimeException('No se pudo preparar el registro de la orden.');
        }
        $stmt->bind_param($insTypes, ...$insParams);
        if (!$stmt->execute()) {
            $sqlErrno = $stmt->errno;
            $stmt->close();
            starfi_log_exception('waba_ordenes', 'Error insertando orden en emitir_orden_sede', ['id_sede' => $id_sede, 'errno' => $sqlErrno]);
            throw new RuntimeException('No se pudo registrar la orden en base de datos.');
        }
        $new_order_id = intval($stmt->insert_id);
        $stmt->close();

        $codigoFinal = 'NC-' . date('Y') . '-' . str_pad((string)$new_order_id, 4, '0', STR_PAD_LEFT);
        if (isset($colsOrd['codigo_orden'])) {
            $stmtCod = $con->prepare("UPDATE `waba_ordenes_cobro` SET `codigo_orden` = ? WHERE `id` = ?");
            if ($stmtCod) {
                $stmtCod->bind_param("si", $codigoFinal, $new_order_id);
                $stmtCod->execute();
                $stmtCod->close();
            }
        }

        $hasCostoBase = isset($colsDet['costo_base']);
        $hasSubtotal  = isset($colsDet['subtotal_usd']);
        if ($hasCostoBase && $hasSubtotal) {
            $sqlDet = "INSERT INTO `waba_ordenes_detalles` (`id_orden`, `nombre_plantilla`, `categoria`, `costo_base`, `subtotal_usd`, `volumen`, `mensajes_gratuitos`, `mensajes_pagados`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        } elseif ($hasCostoBase) {
            $sqlDet = "INSERT INTO `waba_ordenes_detalles` (`id_orden`, `nombre_plantilla`, `categoria`, `costo_base`, `volumen`, `mensajes_gratuitos`, `mensajes_pagados`) VALUES (?, ?, ?, ?, ?, ?, ?)";
        } else {
            $sqlDet = "INSERT INTO `waba_ordenes_detalles` (`id_orden`, `nombre_plantilla`, `categoria`, `subtotal_usd`, `volumen`, `mensajes_gratuitos`, `mensajes_pagados`) VALUES (?, ?, ?, ?, ?, ?, ?)";
        }
        $stmtDet = $con->prepare($sqlDet);

        if ($stmtDet) {
            foreach ($cat_details as $cname => $cd) {
                if ($cd['volumen'] > 0 || $cd['costo'] > 0) {
                    $nomPl  = (string)$cd['nombre'];
                    $catStr = (string)$cname;
                    $vol    = intval($cd['volumen']);
                    $cBase  = floatval($cd['costo']);
                    $grat   = intval($cd['gratuitos']);
                    $pag    = intval($cd['pagados']);
                    if ($hasCostoBase && $hasSubtotal) {
                        $stmtDet->bind_param("issddiii", $new_order_id, $nomPl, $catStr, $cBase, $cBase, $vol, $grat, $pag);
                    } else {
                        $stmtDet->bind_param("issdiii", $new_order_id, $nomPl, $catStr, $cBase, $vol, $grat, $pag);
                    }
                    $stmtDet->execute();
                }
            }

            if ($margen > 0) {
                $adminLabel = 'Margen Administrativo (CRM)';
                $adminCat   = 'ADMIN';
                $uno = 1;
                $cero = 0;
                if ($hasCostoBase && $hasSubtotal) {
                    $stmtDet->bind_param("issddiii", $new_order_id, $adminLabel, $adminCat, $margen, $margen, $uno, $cero, $cero);
                } else {
                    $stmtDet->bind_param("issdiii", $new_order_id, $adminLabel, $adminCat, $margen, $uno, $cero, $cero);
                }
                $stmtDet->execute();
            }
            $stmtDet->close();
        }

        registrar_auditoria(
            'waba_ordenes',
            'EMITIR_ORDEN_COBRO',
            "Orden de cobro #$new_order_id ($codigoFinal) emitida para sede #$id_sede",
            $padreAnterior ? ['orden_padre_fusionada' => $padreAnterior] : null,
            [
                'id_orden'         => $new_order_id,
                'codigo_orden'     => $codigoFinal,
                'id_sede'          => $id_sede,
                'fecha_desde'      => $fecha_desde,
                'fecha_hasta'      => $fecha_hasta,
                'mensajes_totales' => $mensajes_totales,
                'costo_meta'       => round($costo_meta, 4),
                'margen_ganancia'  => round($margen, 4),
                'monto_total'      => round($costo_final, 2),
                'orden_padre_id'   => $id_padre > 0 ? $id_padre : null,
            ],
            $con,
            null,
            $id_sede
        );

        if ($own_tx) {
            $con->commit();
            if (function_exists('starfi_tx_set_active')) {
                starfi_tx_set_active($con, false);
            }
        } else {
            @$con->query("RELEASE SAVEPOINT sp_emitir_orden");
        }

        if ($own_tx) {
            $notificado = starfi_notify_waba_order($con, $new_order_id, $id_sede, $fecha_desde, $fecha_hasta, $costo_final, $waba_id, false);
            if ($notificado === 1 && isset($colsOrd['notificacion_enviada'])) {
                $stmtNot = $con->prepare("UPDATE `waba_ordenes_cobro` SET `notificacion_enviada` = 1 WHERE `id` = ?");
                if ($stmtNot) {
                    $stmtNot->bind_param("i", $new_order_id);
                    $stmtNot->execute();
                    $stmtNot->close();
                }
            }
        }

        return [
            'status'       => 'success',
            'id_orden'     => $new_order_id,
            'codigo_orden' => $codigoFinal,
            'monto_total'  => round($costo_final, 2),
        ];
    } catch (Throwable $e) {
        $rollbackCurrentScope();
        starfi_log_exception('waba_ordenes', 'Excepción en emitir_orden_sede', [
            'id_sede' => $id_sede,
            'detail'  => $e->getMessage(),
        ]);
        return ['status' => 'error', 'message' => 'No se pudo generar la orden de cobro en base de datos.'];
    }
}

// Modo librería para cron_waba_billing.php y suites QA
if (defined('WABA_BILLING_LIB_ONLY') && WABA_BILLING_LIB_ONLY === true) {
    return;
}

// =========================================================================
// CONTROLADOR HTTP / AJAX
// =========================================================================
require_once __DIR__ . '/../../core/auth.php';
requireAuth();

if (!hasPermission('waba_ordenes') && !hasPermission('whatsapp_analytics')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado.']);
    exit;
}

$agente = getAgenteInfo();
$es_master = (strtoupper(trim((string)($agente['rol'] ?? ''))) === 'MASTER' || intval($agente['id'] ?? 0) === 1);
$id_sede_agente = intval($agente['id_sede'] ?? 0);
$id_usuario_actual = intval($agente['id'] ?? 0);

session_write_close();

$con = getDbConnection();
if (!$con) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
    exit;
}

ensureWabaBillingSchema($con);

$action = trim((string)($_POST['action'] ?? ''));

// Bloqueo explícito de instalación de cron vía HTTP (QA-BUG-CRM3-01)
if ($action === 'install_cron') {
    http_response_code(403);
    registrar_auditoria(
        'waba_ordenes',
        'BLOQUEO_INSTALL_CRON_HTTP',
        'Intento bloqueado de instalar tarea programada vía HTTP; restringido exclusivamente a CLI.',
        null,
        ['action' => 'install_cron', 'cli_script' => 'modules/waba_ordenes/test_ia/install_cron_waba.php'],
        $con,
        $id_usuario_actual,
        $id_sede_agente > 0 ? $id_sede_agente : null
    );
    echo json_encode([
        'status'  => 'error',
        'code'    => 'CLI_ONLY',
        'message' => 'Por seguridad, el registro de tareas programadas está deshabilitado vía HTTP. Ejecute por consola del servidor: php modules/waba_ordenes/test_ia/install_cron_waba.php',
    ]);
    exit;
}

// Acciones de gestión directa de órdenes requieren permiso waba_ordenes
$ordenes_module_actions = [
    'get_orders',
    'get_order_details',
    'report_payment',
    'register_payment',
    'mark_paid',
    'verify_payment',
    'resend_notification',
    'delete_order',
];
if (in_array($action, $ordenes_module_actions, true) && !hasPermission('waba_ordenes')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: su rol no posee autorización para el módulo waba_ordenes.']);
    exit;
}

$master_only_actions = [
    'get_sede_config',
    'save_sede_config',
    'check_last_order',
    'generate_order',
    'emitir_orden',
    'generate_massive_orders',
    'resend_notification',
    'delete_order',
    'verify_payment',
];

if (in_array($action, $master_only_actions, true) && !$es_master) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado. Esta acción es exclusiva del rol MASTER.']);
    exit;
}

if ($action === 'get_sede_config') {
    $id_sede = intval($_POST['id_sede'] ?? 0);

    $stmt = $con->prepare("SELECT gerente_nombre, gerente_telefono, gerente_email, pref_not_cobro FROM sedes WHERE id = ? LIMIT 1");
    $sede_info = null;
    if ($stmt) {
        $stmt->bind_param("i", $id_sede);
        $stmt->execute();
        $sede_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    $stmt = $con->prepare("SELECT tipo_tarifa, valor, notas_negociacion, limite_facturacion FROM waba_tarifas_sede WHERE id_sede = ? LIMIT 1");
    $tarifa_info = null;
    if ($stmt) {
        $stmt->bind_param("i", $id_sede);
        $stmt->execute();
        $tarifa_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$tarifa_info) {
        $tarifa_info = [
            'tipo_tarifa'        => 'PORCENTAJE',
            'valor'              => 10.00,
            'notas_negociacion'  => '',
            'limite_facturacion' => 0.00,
        ];
    }

    echo json_encode(['status' => 'success', 'sede' => $sede_info, 'tarifa' => $tarifa_info]);
    exit;
}

if ($action === 'save_sede_config') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    if ($id_sede <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Sede no válida.']);
        exit;
    }
    $gerente_nombre     = trim((string)($_POST['gerente_nombre'] ?? ''));
    $gerente_telefono   = trim((string)($_POST['gerente_telefono'] ?? ''));
    $gerente_email      = trim((string)($_POST['gerente_email'] ?? ''));
    $pref_not_cobro     = trim((string)($_POST['pref_not_cobro'] ?? 'WHATSAPP'));
    $tipo_tarifa        = trim((string)($_POST['tipo_tarifa'] ?? 'PORCENTAJE'));
    $valor              = floatval($_POST['valor'] ?? 10.00);
    $limite_facturacion = floatval($_POST['limite_facturacion'] ?? 0.00);
    $notas              = trim((string)($_POST['notas'] ?? ''));

    $prevSede = null;
    $stmtPs = $con->prepare("SELECT gerente_nombre, gerente_telefono, gerente_email, pref_not_cobro FROM sedes WHERE id = ? LIMIT 1");
    if ($stmtPs) {
        $stmtPs->bind_param("i", $id_sede);
        $stmtPs->execute();
        $prevSede = $stmtPs->get_result()->fetch_assoc();
        $stmtPs->close();
    }

    $prevTarifa = null;
    $stmtPt = $con->prepare("SELECT id, tipo_tarifa, valor, notas_negociacion, limite_facturacion FROM waba_tarifas_sede WHERE id_sede = ? LIMIT 1");
    if ($stmtPt) {
        $stmtPt->bind_param("i", $id_sede);
        $stmtPt->execute();
        $prevTarifa = $stmtPt->get_result()->fetch_assoc();
        $stmtPt->close();
    }

    $stmt = $con->prepare("UPDATE sedes SET gerente_nombre = ?, gerente_telefono = ?, gerente_email = ?, pref_not_cobro = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ssssi", $gerente_nombre, $gerente_telefono, $gerente_email, $pref_not_cobro, $id_sede);
        $stmt->execute();
        $stmt->close();
    }

    if ($prevTarifa) {
        $stmt = $con->prepare("UPDATE waba_tarifas_sede SET tipo_tarifa = ?, valor = ?, notas_negociacion = ?, limite_facturacion = ? WHERE id_sede = ?");
        if ($stmt) {
            $stmt->bind_param("sdsdi", $tipo_tarifa, $valor, $notas, $limite_facturacion, $id_sede);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $con->prepare("INSERT INTO waba_tarifas_sede (id_sede, tipo_tarifa, valor, notas_negociacion, limite_facturacion) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("isdsd", $id_sede, $tipo_tarifa, $valor, $notas, $limite_facturacion);
            $stmt->execute();
            $stmt->close();
        }
    }

    registrar_auditoria(
        'waba_ordenes',
        'ACTUALIZAR_TARIFA_SEDE',
        "Configuración de tarifa y contacto de cobro actualizada para sede #$id_sede",
        ['sede' => $prevSede, 'tarifa' => $prevTarifa],
        [
            'sede' => [
                'gerente_nombre'   => $gerente_nombre,
                'gerente_telefono' => $gerente_telefono,
                'gerente_email'    => $gerente_email,
                'pref_not_cobro'   => $pref_not_cobro,
            ],
            'tarifa' => [
                'tipo_tarifa'        => $tipo_tarifa,
                'valor'              => $valor,
                'limite_facturacion' => $limite_facturacion,
                'notas_negociacion'  => $notas,
            ],
        ],
        $con,
        $id_usuario_actual,
        $id_sede
    );

    echo json_encode(['status' => 'success', 'message' => 'Configuración guardada exitosamente.']);
    exit;
}

if ($action === 'check_last_order') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $fecha_hasta = trim((string)($_POST['fecha_hasta'] ?? date('Y-m-d')));
    $fecha_desde = date('Y-m-01');
    $tiene_pendiente = false;
    $pendiente_id = null;

    $stmt = $con->prepare("SELECT id, estado, fecha_desde, fecha_hasta FROM waba_ordenes_cobro WHERE id_sede = ? AND estado NOT IN ('FUSIONADO', 'ANULADA') ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $id_sede);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            if ($row['estado'] === 'PENDIENTE') {
                $tiene_pendiente = true;
                $pendiente_id = intval($row['id']);
                $fecha_desde = $row['fecha_desde'];
            } else {
                $fecha_desde = date('Y-m-d', strtotime($row['fecha_hasta'] . ' + 1 day'));
            }
        }
        $stmt->close();
    }

    echo json_encode([
        'status'          => 'success',
        'fecha_desde'     => $fecha_desde,
        'fecha_hasta'     => $fecha_hasta,
        'tiene_pendiente' => $tiene_pendiente,
        'pendiente_id'    => $pendiente_id,
    ]);
    exit;
}

if ($action === 'generate_order' || $action === 'emitir_orden') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $fecha_desde = trim((string)($_POST['fecha_desde'] ?? ''));
    $fecha_hasta = trim((string)($_POST['fecha_hasta'] ?? ''));
    $orden_pendiente_id = !empty($_POST['orden_pendiente_id']) ? intval($_POST['orden_pendiente_id']) : null;

    $res = emitir_orden_sede($con, $id_sede, $fecha_desde, $fecha_hasta, $orden_pendiente_id);
    echo json_encode($res);
    exit;
}

if ($action === 'generate_massive_orders') {
    $sedes = is_array($_POST['sedes'] ?? null) ? $_POST['sedes'] : [];
    $fecha_desde = trim((string)($_POST['fecha_desde'] ?? ''));
    $fecha_hasta = trim((string)($_POST['fecha_hasta'] ?? ''));

    if (empty($sedes) || !$fecha_desde || !$fecha_hasta) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros para la emisión masiva.']);
        exit;
    }

    $exitos = 0;
    $errores = 0;
    $mensajes_error = [];

    foreach ($sedes as $id_sede) {
        $id = intval($id_sede);
        $res = emitir_orden_sede($con, $id, $fecha_desde, $fecha_hasta, null);
        if (($res['status'] ?? '') === 'success') {
            $exitos++;
        } else {
            $errores++;
            $mensajes_error[] = "Sede $id: " . (string)($res['message'] ?? 'Error');
        }
    }

    registrar_auditoria(
        'waba_ordenes',
        'EMISION_MASIVA_ORDENES',
        "Emisión masiva ejecutada ($exitos exitosas, $errores fallidas)",
        null,
        ['fecha_desde' => $fecha_desde, 'fecha_hasta' => $fecha_hasta, 'exitos' => $exitos, 'errores' => $errores],
        $con,
        $id_usuario_actual,
        $id_sede_agente > 0 ? $id_sede_agente : null
    );

    if ($exitos > 0 && $errores === 0) {
        echo json_encode(['status' => 'success', 'message' => "Se generaron $exitos órdenes exitosamente.", 'warnings' => []]);
    } elseif ($exitos > 0 && $errores > 0) {
        echo json_encode([
            'status'   => 'success',
            'message'  => "Se generaron $exitos órdenes con $errores observaciones.",
            'warnings' => $mensajes_error,
        ]);
    } else {
        echo json_encode([
            'status'   => 'error',
            'message'  => "No se pudieron generar las órdenes solicitadas.",
            'warnings' => $mensajes_error,
        ]);
    }
    exit;
}

if ($action === 'get_orders') {
    $page   = max(1, intval($_POST['page'] ?? 1));
    $limit  = 10;
    $offset = ($page - 1) * $limit;

    $id_sede = intval($_POST['id_sede'] ?? 0);
    if (!$es_master) {
        if ($id_sede_agente <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Usuario sin sede asignada']);
            exit;
        }
        $id_sede = $id_sede_agente;
    }

    $estado      = trim((string)($_POST['estado'] ?? ''));
    $fecha_desde = trim((string)($_POST['fecha_desde'] ?? ''));
    $fecha_hasta = trim((string)($_POST['fecha_hasta'] ?? ''));

    $where  = ["1=1"];
    $types  = "";
    $params = [];

    if ($id_sede > 0) {
        $where[]  = "o.id_sede = ?";
        $types   .= "i";
        $params[] = $id_sede;
    }
    if ($estado !== '') {
        $where[]  = "o.estado = ?";
        $types   .= "s";
        $params[] = $estado;
    }
    if ($fecha_desde !== '') {
        $where[]  = "o.fecha_desde >= ?";
        $types   .= "s";
        $params[] = $fecha_desde;
    }
    if ($fecha_hasta !== '') {
        $where[]  = "o.fecha_hasta <= ?";
        $types   .= "s";
        $params[] = $fecha_hasta;
    }

    $whereClause = implode(" AND ", $where);
    $colsOrd = starfi_waba_table_columns($con, 'waba_ordenes_cobro');
    $montoCol  = isset($colsOrd['monto_total']) ? 'o.monto_total' : 'o.monto_total_usd';
    $margenCol = isset($colsOrd['margen_ganancia']) ? 'o.margen_ganancia' : "($montoCol - o.costo_meta_usd)";

    $qKpi = "SELECT 
                COALESCE(SUM(CASE WHEN o.estado NOT IN ('FUSIONADO', 'ANULADA') THEN $montoCol ELSE 0 END), 0) AS total_facturado,
                COALESCE(SUM(CASE WHEN o.estado = 'PAGADO' THEN $montoCol ELSE 0 END), 0) AS total_cobrado,
                COALESCE(SUM(CASE WHEN o.estado IN ('PENDIENTE', 'EN_REVISION') THEN $montoCol ELSE 0 END), 0) AS por_cobrar,
                COALESCE(SUM(CASE WHEN o.estado = 'EN_REVISION' THEN $montoCol ELSE 0 END), 0) AS en_revision,
                COALESCE(SUM(CASE WHEN o.estado NOT IN ('FUSIONADO', 'ANULADA') THEN $margenCol ELSE 0 END), 0) AS margen_neto,
                COALESCE(SUM(CASE WHEN o.estado = 'PAGADO' THEN $margenCol ELSE 0 END), 0) AS margen_cobrado
             FROM waba_ordenes_cobro o
             WHERE $whereClause";
    $stmtKpi = $con->prepare($qKpi);
    $kpiRow = [];
    if ($stmtKpi) {
        if (!empty($params)) {
            $stmtKpi->bind_param($types, ...$params);
        }
        $stmtKpi->execute();
        $kpiRow = $stmtKpi->get_result()->fetch_assoc() ?: [];
        $stmtKpi->close();
    }

    $kpis = [
        'total_facturado' => round(floatval($kpiRow['total_facturado'] ?? 0), 2),
        'total_cobrado'   => round(floatval($kpiRow['total_cobrado'] ?? 0), 2),
        'por_cobrar'      => round(floatval($kpiRow['por_cobrar'] ?? 0), 2),
        'en_revision'     => round(floatval($kpiRow['en_revision'] ?? 0), 2),
    ];
    if ($es_master) {
        $kpis['margen_neto']    = round(floatval($kpiRow['margen_neto'] ?? 0), 2);
        $kpis['margen_cobrado'] = round(floatval($kpiRow['margen_cobrado'] ?? 0), 2);
    }

    $stmtTotal = $con->prepare("SELECT COUNT(*) AS total FROM waba_ordenes_cobro o WHERE $whereClause");
    $total_records = 0;
    if ($stmtTotal) {
        if (!empty($params)) {
            $stmtTotal->bind_param($types, ...$params);
        }
        $stmtTotal->execute();
        $total_records = intval($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
        $stmtTotal->close();
    }
    $total_pages = (int)ceil($total_records / $limit);

    $qData = "SELECT o.*, s.nombre_sede 
              FROM waba_ordenes_cobro o 
              JOIN sedes s ON o.id_sede = s.id 
              WHERE $whereClause 
              ORDER BY o.id DESC 
              LIMIT ? OFFSET ?";
    $stmtData = $con->prepare($qData);
    $data = [];
    if ($stmtData) {
        $typesData    = $types . "ii";
        $paramsData   = $params;
        $paramsData[] = $limit;
        $paramsData[] = $offset;
        $stmtData->bind_param($typesData, ...$paramsData);
        $stmtData->execute();
        $res = $stmtData->get_result();
        while ($row = $res->fetch_assoc()) {
            $norm = starfi_normalize_waba_order_row($row);
            if (!$es_master) {
                unset($norm['costo_meta'], $norm['costo_meta_usd'], $norm['margen_ganancia']);
            }
            $data[] = $norm;
        }
        $stmtData->close();
    }

    echo json_encode([
        'status'        => 'success',
        'is_master'     => $es_master,
        'kpis'          => $kpis,
        'data'          => $data,
        'total_pages'   => $total_pages,
        'current_page'  => $page,
        'total_records' => $total_records,
    ]);
    exit;
}

if ($action === 'get_order_details') {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    if ($id_orden <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID de orden inválido']);
        exit;
    }

    $stmtOrd = $con->prepare("SELECT o.*, s.nombre_sede FROM waba_ordenes_cobro o JOIN sedes s ON o.id_sede = s.id WHERE o.id = ? LIMIT 1");
    $ordenRaw = null;
    if ($stmtOrd) {
        $stmtOrd->bind_param("i", $id_orden);
        $stmtOrd->execute();
        $ordenRaw = $stmtOrd->get_result()->fetch_assoc();
        $stmtOrd->close();
    }

    if (!$ordenRaw) {
        echo json_encode(['status' => 'error', 'message' => 'Orden no encontrada']);
        exit;
    }

    $orden = starfi_normalize_waba_order_row($ordenRaw);
    if (!$es_master && intval($orden['id_sede']) !== $id_sede_agente) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Acceso denegado a esta orden']);
        exit;
    }

    $raw_rows = [];
    $costo_meta_sum = 0.0;
    $margen_sum = 0.0;

    $stmtDet = $con->prepare("SELECT * FROM waba_ordenes_detalles WHERE id_orden = ? ORDER BY id ASC");
    if ($stmtDet) {
        $stmtDet->bind_param("i", $id_orden);
        $stmtDet->execute();
        $res = $stmtDet->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $cBaseRow = isset($row['costo_base']) ? floatval($row['costo_base']) : floatval($row['subtotal_usd'] ?? 0);
            $row['_costo_base_norm'] = $cBaseRow;
            if (($row['categoria'] ?? '') === 'ADMIN') {
                $margen_sum += $cBaseRow;
            } else {
                $costo_meta_sum += $cBaseRow;
                $raw_rows[] = $row;
            }
        }
        $stmtDet->close();
    }

    $monto_total_orden = floatval($orden['monto_total'] ?? 0);
    if ($margen_sum <= 0 && floatval($orden['margen_ganancia'] ?? 0) > 0) {
        $margen_sum = floatval($orden['margen_ganancia']);
    }
    if ($costo_meta_sum <= 0 && floatval($orden['costo_meta'] ?? 0) > 0) {
        $costo_meta_sum = floatval($orden['costo_meta']);
    }

    $factor = ($costo_meta_sum > 0) ? (($costo_meta_sum + $margen_sum) / $costo_meta_sum) : 1.0;

    $items_response = [];
    $is_first = true;
    foreach ($raw_rows as $r) {
        $c_base = floatval($r['_costo_base_norm']);
        $vol    = intval($r['volumen']);
        $grat   = intval($r['mensajes_gratuitos'] ?? 0);
        $pag    = intval($r['mensajes_pagados'] ?? 0);
        if ($pag <= 0 && $vol > 0 && $grat === 0) {
            $pag = $vol;
        }

        $subtotal_final = $c_base * $factor;
        if ($costo_meta_sum == 0 && $is_first) {
            $subtotal_final = $monto_total_orden;
        }
        $is_first = false;

        $divisor_unit = $pag > 0 ? $pag : ($vol > 0 ? $vol : 1);
        $precio_unitario_final = $subtotal_final / $divisor_unit;

        $item = [
            'nombre_plantilla'      => $r['nombre_plantilla'],
            'categoria'             => $r['categoria'] ?? 'MARKETING',
            'volumen'               => $vol,
            'mensajes_gratuitos'    => $grat,
            'mensajes_pagados'      => $pag,
            'precio_unitario_final' => round($precio_unitario_final, 4),
            'subtotal_final'        => round($subtotal_final, 2),
        ];
        if ($es_master) {
            $item['costo_base'] = round($c_base, 4);
        }
        $items_response[] = $item;
    }

    if (empty($items_response)) {
        $vol_tot = intval($orden['mensajes_totales'] ?? 0);
        $unit_f  = $vol_tot > 0 ? ($monto_total_orden / $vol_tot) : $monto_total_orden;
        $item = [
            'nombre_plantilla'      => 'Servicios de Mensajería Consolidada Meta (API)',
            'categoria'             => 'CONSOLIDADO',
            'volumen'               => $vol_tot,
            'mensajes_gratuitos'    => 0,
            'mensajes_pagados'      => $vol_tot,
            'precio_unitario_final' => round($unit_f, 4),
            'subtotal_final'        => round($monto_total_orden, 2),
        ];
        if ($es_master) {
            $item['costo_base'] = round(floatval($orden['costo_meta'] ?? 0), 4);
        }
        $items_response[] = $item;
    }

    $summary = [
        'id_orden'         => $id_orden,
        'nombre_sede'      => $orden['nombre_sede'],
        'estado'           => $orden['estado'],
        'monto_total'      => round($monto_total_orden, 2),
        'metodo_pago'      => $orden['metodo_pago'] ?? null,
        'referencia_pago'  => $orden['referencia_pago'] ?? null,
        'fecha_pago'       => $orden['fecha_pago'] ?? null,
        'notas_pago'       => $orden['notas_pago'] ?? null,
        'comprobante_ruta' => $orden['comprobante_ruta'] ?? null,
        'comprobante_path' => $orden['comprobante_path'] ?? null,
        'comprobante_url'  => $orden['comprobante_url'] ?? null,
    ];

    if ($es_master) {
        $summary['costo_meta']      = round(floatval($orden['costo_meta'] ?? 0), 2);
        $summary['margen_ganancia'] = round(floatval($orden['margen_ganancia'] ?? 0), 2);
    }

    echo json_encode([
        'status'    => 'success',
        'is_master' => $es_master,
        'summary'   => $summary,
        'data'      => $items_response,
    ]);
    exit;
}

if (in_array($action, ['report_payment', 'register_payment', 'mark_paid'], true)) {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    $filePayload = isset($_FILES['comprobante']) ? $_FILES['comprobante'] : null;

    $result = starfi_report_waba_payment(
        $con,
        $id_orden,
        $_POST,
        $filePayload,
        $id_usuario_actual,
        $es_master,
        $id_sede_agente
    );
    echo json_encode($result);
    exit;
}

if ($action === 'verify_payment') {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    $decision = trim((string)($_POST['decision'] ?? 'APROBAR'));
    $notas_verificacion = trim((string)($_POST['notas_verificacion'] ?? ''));

    $result = starfi_verify_waba_payment(
        $con,
        $id_orden,
        $decision,
        $notas_verificacion,
        $id_usuario_actual
    );
    echo json_encode($result);
    exit;
}

if ($action === 'resend_notification') {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    if ($id_orden <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID de orden inválido']);
        exit;
    }

    $stmtO = $con->prepare("SELECT * FROM waba_ordenes_cobro WHERE id = ? LIMIT 1");
    $oRaw = null;
    if ($stmtO) {
        $stmtO->bind_param("i", $id_orden);
        $stmtO->execute();
        $oRaw = $stmtO->get_result()->fetch_assoc();
        $stmtO->close();
    }

    if (!$oRaw) {
        echo json_encode(['status' => 'error', 'message' => 'Orden no encontrada']);
        exit;
    }

    $o = starfi_normalize_waba_order_row($oRaw);
    $id_sede     = intval($o['id_sede']);
    $fecha_desde = (string)$o['fecha_desde'];
    $fecha_hasta = (string)$o['fecha_hasta'];
    $costo_final = floatval($o['monto_total'] ?? 0);
    $prevNotif   = intval($o['notificacion_enviada'] ?? 0);

    $notificado = starfi_notify_waba_order($con, $id_orden, $id_sede, $fecha_desde, $fecha_hasta, $costo_final, '', true);

    if ($notificado === 1) {
        if (starfi_waba_has_column($con, 'waba_ordenes_cobro', 'notificacion_enviada')) {
            $stmtUpN = $con->prepare("UPDATE waba_ordenes_cobro SET notificacion_enviada = COALESCE(notificacion_enviada, 0) + 1 WHERE id = ?");
            if ($stmtUpN) {
                $stmtUpN->bind_param("i", $id_orden);
                $stmtUpN->execute();
                $stmtUpN->close();
            }
        }
        registrar_auditoria(
            'waba_ordenes',
            'REENVIAR_NOTIFICACION_ORDEN',
            "Notificación de cobro reenviada para orden #$id_orden",
            ['id_orden' => $id_orden, 'notificacion_enviada' => $prevNotif],
            ['id_orden' => $id_orden, 'notificacion_enviada' => $prevNotif + 1],
            $con,
            $id_usuario_actual,
            $id_sede
        );
        echo json_encode(['status' => 'success', 'message' => 'Notificación reenviada exitosamente.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo enviar la notificación. Revise si la sede tiene teléfono o correo válido configurado.']);
    }
    exit;
}

if ($action === 'delete_order') {
    $id_orden = intval($_POST['id_orden'] ?? 0);
    if ($id_orden <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'ID de orden inválido.']);
        exit;
    }

    $con->begin_transaction();
    try {
        $stmtPrev = $con->prepare("SELECT * FROM waba_ordenes_cobro WHERE id = ? LIMIT 1 FOR UPDATE");
        $ordPrev = null;
        if ($stmtPrev) {
            $stmtPrev->bind_param("i", $id_orden);
            $stmtPrev->execute();
            $ordPrev = $stmtPrev->get_result()->fetch_assoc();
            $stmtPrev->close();
        }
        if (!$ordPrev) {
            $con->rollback();
            echo json_encode(['status' => 'error', 'message' => 'La orden indicada no existe.']);
            exit;
        }

        $stmtDelDet = $con->prepare("DELETE FROM waba_ordenes_detalles WHERE id_orden = ?");
        if ($stmtDelDet) {
            $stmtDelDet->bind_param("i", $id_orden);
            $stmtDelDet->execute();
            $stmtDelDet->close();
        }

        $stmtDelOrd = $con->prepare("DELETE FROM waba_ordenes_cobro WHERE id = ?");
        if ($stmtDelOrd) {
            $stmtDelOrd->bind_param("i", $id_orden);
            $stmtDelOrd->execute();
            $stmtDelOrd->close();
        }

        registrar_auditoria(
            'waba_ordenes',
            'ELIMINAR_ORDEN_COBRO',
            "Orden de cobro #$id_orden eliminada permanentemente",
            starfi_normalize_waba_order_row($ordPrev),
            ['eliminado' => true, 'id_orden' => $id_orden],
            $con,
            $id_usuario_actual,
            intval($ordPrev['id_sede'] ?? $id_sede_agente)
        );

        $con->commit();
        echo json_encode(['status' => 'success', 'message' => 'Orden eliminada correctamente.']);
    } catch (Throwable $e) {
        @$con->rollback();
        starfi_log_exception('waba_ordenes', 'Error eliminando orden WABA', ['id_orden' => $id_orden, 'detail' => $e->getMessage()]);
        echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar la orden de cobro.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no reconocida.']);
exit;
