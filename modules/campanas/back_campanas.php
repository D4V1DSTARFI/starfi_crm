<?php
// modules/campanas/back_campanas.php
// Ecosistema Independiente de Campañas de Difusión Masiva | STARFI CRM
date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('campanas');

header('Content-Type: application/json; charset=utf-8');

$con = getDbConnection();
if (!$con) {
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos.']);
    exit;
}

$agente = getAgenteInfo();
$id_usuario = intval($agente['id'] ?? 1);
$rol_usuario = strtoupper(trim($agente['rol'] ?? 'AGENTE'));
$id_sede_usuario = intval($agente['id_sede'] ?? 0);
// Solo MASTER y MASTER CI (o usuario sin sede asignada con rol MASTER) tienen visión global multi-sede.
// Los Gerentes de Tienda (ADMINISTRADOR con id_sede > 0) y Operadores quedan estrictamente vinculados a su sede.
$es_global = ($id_usuario === 1 || $rol_usuario === 'MASTER' || $rol_usuario === 'MASTER CI' || ($rol_usuario === 'ADMIN' && $id_sede_usuario === 0));

// =========================================================================
// 0. AUTO-MIGRACIÓN DEL ECOSISTEMA DE CAMPAÑAS DE DIFUSIÓN
// =========================================================================
function ensureCampaignTables($con) {
    // 1. Tabla maestra de campañas
    mysqli_query($con, "CREATE TABLE IF NOT EXISTS campanas_difusion (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_sede INT NOT NULL DEFAULT 0,
        id_usuario INT NOT NULL DEFAULT 1,
        nombre_campana VARCHAR(160) NOT NULL,
        template_name VARCHAR(120) NOT NULL DEFAULT 'starfi_difusion_directa',
        param_cuerpo TEXT NULL,
        param_eslogan TEXT NULL,
        costo_unitario_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0700,
        presupuesto_max_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        costo_estimado_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        costo_real_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        cuota_diaria_programada INT NOT NULL DEFAULT 250,
        total_tandas INT NOT NULL DEFAULT 1,
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
        estado VARCHAR(40) NOT NULL DEFAULT 'PENDIENTE_APROBACION',
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_fin DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Asegurar columnas nuevas si la tabla ya existía de una versión previa
    $cols_needed = [
        'template_language' => "ALTER TABLE campanas_difusion ADD COLUMN template_language VARCHAR(20) NOT NULL DEFAULT 'es' AFTER template_name",
        'template_category' => "ALTER TABLE campanas_difusion ADD COLUMN template_category VARCHAR(30) NOT NULL DEFAULT 'MARKETING' AFTER template_language",
        'header_format' => "ALTER TABLE campanas_difusion ADD COLUMN header_format VARCHAR(30) NOT NULL DEFAULT 'IMAGE' AFTER template_category",
        'template_params_json' => "ALTER TABLE campanas_difusion ADD COLUMN template_params_json TEXT NULL AFTER param_eslogan",
        'header_imagen_path' => "ALTER TABLE campanas_difusion ADD COLUMN header_imagen_path VARCHAR(255) NULL AFTER param_eslogan",
        'header_media_id' => "ALTER TABLE campanas_difusion ADD COLUMN header_media_id VARCHAR(120) NULL AFTER header_imagen_path",
        'costo_unitario_usd' => "ALTER TABLE campanas_difusion ADD COLUMN costo_unitario_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0700 AFTER param_eslogan",
        'presupuesto_max_usd' => "ALTER TABLE campanas_difusion ADD COLUMN presupuesto_max_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER costo_unitario_usd",
        'costo_estimado_usd' => "ALTER TABLE campanas_difusion ADD COLUMN costo_estimado_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER presupuesto_max_usd",
        'costo_real_usd' => "ALTER TABLE campanas_difusion ADD COLUMN costo_real_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER costo_estimado_usd",
        'cuota_diaria_programada' => "ALTER TABLE campanas_difusion ADD COLUMN cuota_diaria_programada INT NOT NULL DEFAULT 250 AFTER costo_real_usd",
        'total_tandas' => "ALTER TABLE campanas_difusion ADD COLUMN total_tandas INT NOT NULL DEFAULT 1 AFTER cuota_diaria_programada",
        'hora_programada' => "ALTER TABLE campanas_difusion ADD COLUMN hora_programada TIME NOT NULL DEFAULT '09:00:00' AFTER total_tandas",
        'piloto_automatico' => "ALTER TABLE campanas_difusion ADD COLUMN piloto_automatico TINYINT(1) NOT NULL DEFAULT 1 AFTER total_tandas",
        'aprobado_por' => "ALTER TABLE campanas_difusion ADD COLUMN aprobado_por INT NULL AFTER piloto_automatico",
        'fecha_aprobacion' => "ALTER TABLE campanas_difusion ADD COLUMN fecha_aprobacion DATETIME NULL AFTER aprobado_por",
        'pendientes' => "ALTER TABLE campanas_difusion ADD COLUMN pendientes INT NOT NULL DEFAULT 0 AFTER total_destinatarios",
        'entregados' => "ALTER TABLE campanas_difusion ADD COLUMN entregados INT NOT NULL DEFAULT 0 AFTER enviados",
        'leidos' => "ALTER TABLE campanas_difusion ADD COLUMN leidos INT NOT NULL DEFAULT 0 AFTER entregados",
        'respondidos' => "ALTER TABLE campanas_difusion ADD COLUMN respondidos INT NOT NULL DEFAULT 0 AFTER leidos"
    ];
    foreach ($cols_needed as $col_name => $alter_sql) {
        $chk = mysqli_query($con, "SHOW COLUMNS FROM campanas_difusion LIKE '$col_name'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            mysqli_query($con, $alter_sql);
        }
    }

    // 2. Tabla de destinatarios individuales y trazabilidad 360 (Enviado / Entregado / Leído / Respondido + Tanda Diaria)
    mysqli_query($con, "CREATE TABLE IF NOT EXISTS campanas_difusion_destinatarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_campana INT NOT NULL,
        id_sede INT NOT NULL DEFAULT 0,
        id_cliente INT NOT NULL DEFAULT 0,
        id_conversacion INT NOT NULL DEFAULT 0,
        nombre VARCHAR(150) NOT NULL DEFAULT 'Cliente',
        telefono VARCHAR(40) NOT NULL,
        numero_tanda INT NOT NULL DEFAULT 1,
        fecha_programada DATE NULL,
        id_mensaje_meta VARCHAR(150) NULL,
        estado_envio VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
        respondido TINYINT(1) NOT NULL DEFAULT 0,
        respuesta_texto VARCHAR(255) NULL,
        costo_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        error_detalle TEXT NULL,
        fecha_envio DATETIME NULL,
        fecha_entrega DATETIME NULL,
        fecha_lectura DATETIME NULL,
        fecha_respuesta DATETIME NULL,
        INDEX idx_campana (id_campana),
        INDEX idx_sede_fecha (id_sede, fecha_envio),
        INDEX idx_meta_msg (id_mensaje_meta),
        INDEX idx_telefono (telefono)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cols_dest = [
        'numero_tanda' => "ALTER TABLE campanas_difusion_destinatarios ADD COLUMN numero_tanda INT NOT NULL DEFAULT 1 AFTER telefono",
        'fecha_programada' => "ALTER TABLE campanas_difusion_destinatarios ADD COLUMN fecha_programada DATE NULL AFTER numero_tanda"
    ];
    foreach ($cols_dest as $c_dest => $sql_alter_dest) {
        $chk_d = mysqli_query($con, "SHOW COLUMNS FROM campanas_difusion_destinatarios LIKE '$c_dest'");
        if ($chk_d && mysqli_num_rows($chk_d) === 0) {
            mysqli_query($con, $sql_alter_dest);
        }
    }

    // 3. Tabla de Control de Flujo Diario Anti-Bloqueo y Tarifas Meta por Sede
    mysqli_query($con, "CREATE TABLE IF NOT EXISTS campanas_cuotas_sede (
        id_sede INT PRIMARY KEY,
        limite_diario_mensajes INT NOT NULL DEFAULT 250,
        cadencia_ms INT NOT NULL DEFAULT 1000,
        costo_marketing_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0700,
        costo_utility_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0150,
        actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

ensureCampaignTables($con);

function recalcularMetricasCampana($con, $id_campana) {
    $id_campana = intval($id_campana);
    if ($id_campana <= 0) return;

    // 1. Sincronizar estados reales (ENTREGADO, LEIDO, FALLIDO) desde mensajes_y_eventos por id_mensaje_meta (wamid)
    @mysqli_query($con, "UPDATE campanas_difusion_destinatarios d
        INNER JOIN mensajes_y_eventos m ON m.id_mensaje_meta COLLATE utf8mb4_unicode_ci = d.id_mensaje_meta COLLATE utf8mb4_unicode_ci
        LEFT JOIN campanas_difusion c ON c.id = d.id_campana
        SET d.estado_envio = CASE
                WHEN m.estado_envio = 'FALLIDO' THEN 'FALLIDO'
                WHEN d.respondido = 1 THEN 'RESPONDIDO'
                WHEN m.estado_envio = 'LEIDO' THEN 'LEIDO'
                WHEN m.estado_envio = 'ENTREGADO' AND d.estado_envio NOT IN ('LEIDO', 'RESPONDIDO') THEN 'ENTREGADO'
                ELSE d.estado_envio
            END,
            d.fecha_entrega = CASE
                WHEN m.estado_envio IN ('ENTREGADO', 'LEIDO') THEN IFNULL(d.fecha_entrega, IFNULL(m.timestamp, NOW()))
                ELSE d.fecha_entrega
            END,
            d.fecha_lectura = CASE
                WHEN m.estado_envio = 'LEIDO' THEN IFNULL(d.fecha_lectura, IFNULL(m.timestamp, NOW()))
                ELSE d.fecha_lectura
            END,
            d.error_detalle = CASE
                WHEN m.estado_envio = 'FALLIDO' AND m.error_detalle IS NOT NULL AND m.error_detalle != '' THEN m.error_detalle
                ELSE d.error_detalle
            END,
            d.costo_usd = CASE
                WHEN m.estado_envio = 'FALLIDO' THEN 0.0000
                WHEN d.costo_usd <= 0 AND c.costo_unitario_usd > 0 THEN c.costo_unitario_usd
                ELSE d.costo_usd
            END
        WHERE d.id_campana = $id_campana
          AND d.id_mensaje_meta IS NOT NULL
          AND d.id_mensaje_meta != ''");

    // 2. Sincronizar respuestas de clientes recibidas en mensajes_y_eventos después del envío de la campaña
    $res_dest_env = @mysqli_query($con, "SELECT id, id_conversacion, id_cliente, fecha_envio 
                                         FROM campanas_difusion_destinatarios 
                                         WHERE id_campana = $id_campana 
                                           AND respondido = 0 
                                           AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO') 
                                           AND fecha_envio IS NOT NULL");
    if ($res_dest_env) {
        while ($rd = mysqli_fetch_assoc($res_dest_env)) {
            $id_d = intval($rd['id']);
            $id_conv_d = intval($rd['id_conversacion']);
            $id_cli_d = intval($rd['id_cliente']);
            $f_env = mysqli_real_escape_string($con, $rd['fecha_envio']);
            $cond_conv = [];
            if ($id_conv_d > 0) $cond_conv[] = "m.id_conversacion = $id_conv_d";
            if ($id_cli_d > 0) $cond_conv[] = "conv.id_cliente = $id_cli_d";
            if (empty($cond_conv)) continue;
            $where_conv_or = implode(' OR ', $cond_conv);

            $res_reply = @mysqli_query($con, "SELECT m.contenido, m.timestamp 
                                              FROM mensajes_y_eventos m
                                              LEFT JOIN conversaciones conv ON conv.id = m.id_conversacion
                                              WHERE ($where_conv_or)
                                                AND m.origen = 'CLIENTE'
                                                AND m.timestamp >= '$f_env'
                                              ORDER BY m.id ASC LIMIT 1");
            if ($res_reply && mysqli_num_rows($res_reply) > 0) {
                $r_rep = mysqli_fetch_assoc($res_reply);
                $txt_rep = mysqli_real_escape_string($con, mb_substr(strip_tags($r_rep['contenido'] ?? ''), 0, 250));
                $ts_rep = mysqli_real_escape_string($con, $r_rep['timestamp'] ?: date('Y-m-d H:i:s'));
                @mysqli_query($con, "UPDATE campanas_difusion_destinatarios 
                                     SET respondido = 1,
                                         estado_envio = 'RESPONDIDO',
                                         respuesta_texto = '$txt_rep',
                                         fecha_entrega = IFNULL(fecha_entrega, '$ts_rep'),
                                         fecha_lectura = IFNULL(fecha_lectura, '$ts_rep'),
                                         fecha_respuesta = '$ts_rep'
                                     WHERE id = $id_d");
            }
        }
    }

    // 3. Recalcular embudo consolidado en campanas_difusion
    mysqli_query($con, "UPDATE campanas_difusion c SET 
        total_destinatarios = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana),
        pendientes = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.estado_envio = 'PENDIENTE'),
        enviados = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO')),
        entregados = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND (d.fecha_entrega IS NOT NULL OR d.estado_envio IN ('ENTREGADO','LEIDO','RESPONDIDO'))),
        leidos = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND (d.fecha_lectura IS NOT NULL OR d.estado_envio IN ('LEIDO','RESPONDIDO'))),
        respondidos = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.respondido = 1),
        fallidos = (SELECT COUNT(*) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.estado_envio = 'FALLIDO'),
        costo_real_usd = (SELECT IFNULL(SUM(costo_usd), 0) FROM campanas_difusion_destinatarios d WHERE d.id_campana = $id_campana AND d.estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO'))
        WHERE c.id = $id_campana");
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

function sanitizeMetaTemplateParam($text, $stripTrailingDot = false) {
    // Meta Graph API prohíbe saltos de línea (\n, \r), tabulaciones (\t) y >4 espacios seguidos en parámetros de BODY (Error 132018)
    $clean = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$text);
    $clean = preg_replace('/\s{2,}/', ' ', $clean);
    $clean = trim($clean);
    if ($stripTrailingDot) {
        $clean = rtrim($clean, '.');
        $clean = trim($clean);
    }
    return $clean !== '' ? $clean : 'N/A';
}

/**
 * Resuelve la ruta absoluta del archivo de imagen a enviar en el HEADER de una campaña:
 * 1) Si la campaña tiene una imagen personalizada subida (header_imagen_path), usa esa.
 * 2) Si no, usa automáticamente el logo oficial de la empresa/STARFI para que nunca falle por falta de imagen.
 */
function resolveCampaignHeaderImagePath($con, $camp) {
    $baseDir = realpath(__DIR__ . '/../..');
    if (!empty($camp['header_imagen_path'])) {
        $rel = ltrim(str_replace(['/starfi_crm/', '\\'], ['', '/'], $camp['header_imagen_path']), '/');
        $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (file_exists($candidate) && is_file($candidate)) {
            return $candidate;
        }
    }

    // Si esta campaña no tenía header_imagen_path pero el operador subió recientemente un flyer a otra campaña en los últimos 30 minutos, usar ese flyer reciente antes de caer en el logo
    $id_camp_actual = intval($camp['id'] ?? 0);
    $res_recent = @mysqli_query($con, "SELECT header_imagen_path FROM campanas_difusion 
                                       WHERE header_imagen_path IS NOT NULL AND header_imagen_path != '' 
                                         AND fecha_creacion >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                                       ORDER BY id DESC LIMIT 1");
    if ($res_recent && mysqli_num_rows($res_recent) > 0) {
        $rec_rel = trim(mysqli_fetch_assoc($res_recent)['header_imagen_path'] ?? '');
        if (!empty($rec_rel)) {
            $rel = ltrim(str_replace(['/starfi_crm/', '\\'], ['', '/'], $rec_rel), '/');
            $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (file_exists($candidate) && is_file($candidate)) {
                if ($id_camp_actual > 0) {
                    $rec_esc = mysqli_real_escape_string($con, $rec_rel);
                    @mysqli_query($con, "UPDATE campanas_difusion SET header_imagen_path = '$rec_esc' WHERE id = $id_camp_actual");
                }
                return $candidate;
            }
        }
    }

    // Buscar logo de la empresa en BD si existe
    $res_emp = @mysqli_query($con, "SELECT logo_url FROM empresa_perfil ORDER BY id ASC LIMIT 1");
    if ($res_emp && mysqli_num_rows($res_emp) > 0) {
        $logo_rel = trim(mysqli_fetch_assoc($res_emp)['logo_url'] ?? '');
        if (!empty($logo_rel)) {
            $rel = ltrim(str_replace(['/starfi_crm/', '\\'], ['', '/'], $logo_rel), '/');
            $candidate = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (file_exists($candidate) && is_file($candidate)) {
                return $candidate;
            }
        }
    }

    // Fallbacks oficiales dentro del proyecto
    $fallbacks = [
        $baseDir . '/docs/identidad_visual/logos/logo_starfi.png',
        $baseDir . '/uploads/empresa/logo_1784639274_107.png',
        $baseDir . '/assets/img/legacy_pdf_assets/logo_sf.png',
        $baseDir . '/docs/identidad_visual/logos/isologo.png'
    ];
    foreach ($fallbacks as $fb) {
        $fbReal = str_replace('/', DIRECTORY_SEPARATOR, $fb);
        if (file_exists($fbReal) && is_file($fbReal)) {
            return $fbReal;
        }
    }
    return null;
}

/**
 * Obtiene o sube a Meta Cloud API el Media ID de la imagen de cabecera para la campaña y lo guarda en caché en campanas_difusion.header_media_id
 */
function getOrUploadCampaignHeaderMediaId($con, array &$camp, string $phone_id, string $token): ?string {
    $existing_id = trim($camp['header_media_id'] ?? '');
    if (!empty($existing_id)) {
        return $existing_id;
    }

    $img_path = resolveCampaignHeaderImagePath($con, $camp);
    if (!$img_path || !file_exists($img_path)) {
        return null;
    }

    $mime = @mime_content_type($img_path) ?: 'image/png';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        $mime = 'image/png';
    }
    $filename = basename($img_path);

    $media_url = "https://graph.facebook.com/v23.0/{$phone_id}/media";
    $cfile = new CURLFile($img_path, $mime, $filename);
    $post_media = [
        'messaging_product' => 'whatsapp',
        'type' => $mime,
        'file' => $cfile
    ];

    $ch = curl_init($media_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_media);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!empty($data['id'])) {
        $media_id = trim($data['id']);
        $camp['header_media_id'] = $media_id;
        $id_camp = intval($camp['id'] ?? 0);
        if ($id_camp > 0) {
            $mid_esc = mysqli_real_escape_string($con, $media_id);
            mysqli_query($con, "UPDATE campanas_difusion SET header_media_id = '$mid_esc' WHERE id = $id_camp");
        }
        return $media_id;
    }
    return null;
}

/**
 * Calcula el precio unitario final para la sede aplicando la tarifa/comisión configurada en waba_tarifas_sede (por defecto 10% PORCENTAJE).
 * Los gerentes de sede ven únicamente este precio final integrado.
 */
function calcularPrecioFinalUnitarioSede(float $costoBaseMeta, string $tipoTarifa = 'PORCENTAJE', float $valorTarifa = 10.00): float {
    $tipo = strtoupper(trim($tipoTarifa ?: 'PORCENTAJE'));
    if ($tipo === 'FIJA') {
        return round($costoBaseMeta + max(0, $valorTarifa), 4);
    }
    // PORCENTAJE o PORCENTAJE_VOLUMEN
    return round($costoBaseMeta * (1 + (max(0, $valorTarifa) / 100)), 4);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // =========================================================================
    // 1. OBTENER ECOSISTEMA COMPLETO (SEDES, LÍNEAS, CUOTA DIARIA, CONTACTOS, CAMPAÑAS, KPIs)
    // =========================================================================
    case 'get_ecosystem_data':
        $filtro_sede = intval($_GET['id_sede'] ?? $_POST['id_sede'] ?? $_REQUEST['id_sede'] ?? 0);
        if (!$es_global && $id_sede_usuario > 0) {
            $filtro_sede = $id_sede_usuario;
        }

        // 1.1 Cargar sedes y su línea de WhatsApp asignada + configuración de flujo diario + tarifa configurada por sede (waba_tarifas_sede)
        $where_sede = (!$es_global && $id_sede_usuario > 0) ? "WHERE s.id = $id_sede_usuario" : "";
        $sql_sedes = "SELECT s.id, s.nombre_sede, s.ciudad, s.bot_activo, s.estado AS estado_sede,
                             l.id AS id_linea, l.descripcion AS descripcion_linea, l.numero_telefono, l.meta_app_id, l.id_negocio, l.meta_token, l.estado AS estado_linea, l.limite_solicitudes,
                             IFNULL(q.limite_diario_mensajes, IFNULL(l.limite_solicitudes, 250)) AS limite_diario_mensajes,
                             IFNULL(q.cadencia_ms, 1000) AS cadencia_ms,
                             IFNULL(q.costo_marketing_usd, 0.0700) AS costo_marketing_base_usd,
                             IFNULL(q.costo_utility_usd, 0.0150) AS costo_utility_base_usd,
                             IFNULL(wt.tipo_tarifa, 'PORCENTAJE') AS waba_tipo_tarifa,
                             IFNULL(wt.valor, 10.00) AS waba_valor_tarifa,
                             (SELECT COUNT(*) FROM campanas_difusion_destinatarios d 
                              WHERE d.id_sede = s.id 
                                AND d.estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') 
                                AND DATE(d.fecha_envio) = CURDATE()) AS enviados_hoy,
                             (SELECT IFNULL(SUM(d2.costo_usd), 0) FROM campanas_difusion_destinatarios d2
                              WHERE d2.id_sede = s.id
                                AND d2.estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO')
                                AND DATE(d2.fecha_envio) = CURDATE()) AS gasto_hoy_usd
                      FROM sedes s
                      LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id AND l.estado = 'ACTIVO'
                      LEFT JOIN campanas_cuotas_sede q ON q.id_sede = s.id
                      LEFT JOIN waba_tarifas_sede wt ON wt.id_sede = s.id
                      $where_sede
                      ORDER BY (l.id IS NULL) ASC, s.nombre_sede ASC";
        $res_sedes = mysqli_query($con, $sql_sedes);
        $sedes = [];
        if ($res_sedes) {
            while ($row = mysqli_fetch_assoc($res_sedes)) {
                $lim = max(1, intval($row['limite_diario_mensajes']));
                $hoy = intval($row['enviados_hoy']);
                $disp = max(0, $lim - $hoy);
                $pct = min(100, round(($hoy / $lim) * 100, 1));
                $token_efectivo = !empty($row['meta_token']) ? $row['meta_token'] : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

                $base_mkt = floatval($row['costo_marketing_base_usd']);
                $base_util = floatval($row['costo_utility_base_usd']);
                $waba_tipo = $row['waba_tipo_tarifa'] ?: 'PORCENTAJE';
                $waba_val = floatval($row['waba_valor_tarifa'] ?? 10.00);

                // Precio final con la comisión de la sede ya integrada (el único valor que ve el gerente de sede)
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

        // Si es Gerente de Tienda (!es_global), queda fijo en su sede. Si es Global (Master), arranca en 0 hasta que seleccione una sede.
        if (!$es_global && $id_sede_usuario > 0) {
            $filtro_sede = $id_sede_usuario;
        }

        // 1.2 Cargar clientes del Directorio (con sus sedes asociadas, etiquetas y detección de fatiga Meta 24h)
        // Si aún no se ha seleccionado sede ($filtro_sede === 0), devolver listas vacías para cargar instantáneamente y mantener el panel bloqueado hasta elegir sede.
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
            $where_clientes = "";
            if (!$es_global && $id_sede_usuario > 0) {
                $where_clientes = "WHERE (c.id_sede = $id_sede_usuario OR EXISTS (
                    SELECT 1 FROM conversaciones conv2
                    INNER JOIN lineas_whatsapp lw2 ON lw2.id = conv2.id_linea
                    WHERE conv2.id_cliente = c.id AND lw2.id_sede = $id_sede_usuario
                ))";
            }

            // Mapa de teléfonos que recibieron campaña en las últimas 24h sin haber respondido aún (para prevención de Error 131049)
            $fatiga_24h_map = [];
            $res_fat = @mysqli_query($con, "SELECT DISTINCT telefono FROM campanas_difusion_destinatarios 
                                            WHERE estado_envio IN ('ENVIADO','ENTREGADO','LEIDO') 
                                              AND respondido = 0 
                                              AND fecha_envio >= (NOW() - INTERVAL 24 HOUR)");
            if ($res_fat) {
                while ($rf = mysqli_fetch_assoc($res_fat)) {
                    $t_norm = normalizeVenezuelanPhone($rf['telefono'] ?? '');
                    if ($t_norm !== '') {
                        $fatiga_24h_map[$t_norm] = true;
                    }
                }
            }

            $sql_clientes = "SELECT c.id, c.id_sede, c.numero_whatsapp AS telefono, c.nombre, c.etiquetas, c.notas_internas, c.fecha_registro, c.ultimo_contacto,
                                    GROUP_CONCAT(DISTINCT lw.id_sede SEPARATOR ',') AS sedes_ids,
                                    MAX(conv.fecha_inicio) AS ultima_conv
                             FROM clientes_contactos c
                             LEFT JOIN conversaciones conv ON conv.id_cliente = c.id
                             LEFT JOIN lineas_whatsapp lw ON lw.id = conv.id_linea
                             $where_clientes
                             GROUP BY c.id
                             ORDER BY c.id DESC";
            $res_cli = mysqli_query($con, $sql_clientes);
            $etiquetas_map = [];
            if ($res_cli) {
                while ($rc = mysqli_fetch_assoc($res_cli)) {
                    $tags_arr = [];
                    $raw_tags = trim($rc['etiquetas'] ?? '');
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

                    $sedes_arr = !empty($rc['sedes_ids']) ? array_map('intval', explode(',', $rc['sedes_ids'])) : [];
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
            $etiquetas = array_keys($etiquetas_map);
            sort($etiquetas, SORT_NATURAL | SORT_FLAG_CASE);

            // 1.4 Cargar Campañas de Difusión y KPIs por Sede seleccionada
            $where_camp = "WHERE c.id_sede = $filtro_sede";
            $res_sync = @mysqli_query($con, "SELECT c.id FROM campanas_difusion c $where_camp ORDER BY c.id DESC LIMIT 25");
            if ($res_sync) {
                while ($r_s = mysqli_fetch_assoc($res_sync)) {
                    recalcularMetricasCampana($con, intval($r_s['id']));
                }
            }
            $sql_camp = "SELECT c.*, s.nombre_sede, l.numero_telefono AS linea_telefono
                         FROM campanas_difusion c
                         LEFT JOIN sedes s ON s.id = c.id_sede
                         LEFT JOIN lineas_whatsapp l ON l.id_sede = c.id_sede AND l.estado = 'ACTIVO'
                         $where_camp
                         ORDER BY c.id DESC
                         LIMIT 50";
            $res_camp = mysqli_query($con, $sql_camp);

            if ($res_camp) {
                while ($row_c = mysqli_fetch_assoc($res_camp)) {
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

        // Conservar las tarifas actuales de la BD; este endpoint solo actualiza Límite y Ritmo Diario
        $costo_mkt = 0.0700;
        $costo_util = 0.0150;
        $res_cur = mysqli_query($con, "SELECT costo_marketing_usd, costo_utility_usd FROM campanas_cuotas_sede WHERE id_sede = $id_sede LIMIT 1");
        if ($res_cur && $rcur = mysqli_fetch_assoc($res_cur)) {
            $costo_mkt = floatval($rcur['costo_marketing_usd']);
            $costo_util = floatval($rcur['costo_utility_usd']);
        }

        $stmt = $con->prepare("INSERT INTO campanas_cuotas_sede (id_sede, limite_diario_mensajes, cadencia_ms, costo_marketing_usd, costo_utility_usd)
                               VALUES (?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE
                               limite_diario_mensajes = VALUES(limite_diario_mensajes),
                               cadencia_ms = VALUES(cadencia_ms)");
        $stmt->bind_param("iiidd", $id_sede, $limite_diario, $cadencia_ms, $costo_mkt, $costo_util);
        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Límite y ritmo diario actualizados para la sede.'
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar la configuración de la sede.']);
        }
        $stmt->close();
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

        $res_l = mysqli_query($con, "SELECT id_negocio, meta_token, numero_telefono FROM lineas_whatsapp WHERE id_sede = $id_sede AND estado = 'ACTIVO' LIMIT 1");
        $templates = [];
        $tiene_starfi_directa = false;
        $estado_starfi_directa = 'MISSING';
        $waba_id = '';
        $token = '';

        if ($res_l && mysqli_num_rows($res_l) > 0) {
            $lin = mysqli_fetch_assoc($res_l);
            $waba_id = trim($lin['id_negocio'] ?? '');
            $token = !empty($lin['meta_token']) ? trim($lin['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

            if (!empty($waba_id) && !empty($token)) {
                $url = "https://graph.facebook.com/v23.0/{$waba_id}/message_templates?fields=name,status,language,category,components&limit=100";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 12);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code >= 200 && $code < 300 && $resp) {
                    $json = json_decode($resp, true);
                    if (!empty($json['data'])) {
                        foreach ($json['data'] as $tpl) {
                            $st = strtoupper($tpl['status'] ?? '');
                            if ($tpl['name'] === 'starfi_difusion_directa') {
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
                                    $c_type = strtoupper($comp['type'] ?? '');
                                    if ($c_type === 'BODY') {
                                        $body_txt = $comp['text'] ?? '';
                                        if (!empty($comp['example']['body_text'][0]) && is_array($comp['example']['body_text'][0])) {
                                            $body_examples = array_values(array_map('strval', $comp['example']['body_text'][0]));
                                        }
                                    } elseif ($c_type === 'HEADER') {
                                        $header_fmt = strtoupper($comp['format'] ?? 'TEXT');
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
                                                'type' => strtoupper($b['type'] ?? 'QUICK_REPLY'),
                                                'text' => $b['text'] ?? '',
                                                'url' => $b['url'] ?? '',
                                                'phone_number' => $b['phone_number'] ?? ''
                                            ];
                                        }
                                    }
                                }

                                // Si es starfi_difusion_directa, permitir siempre subir o usar imagen de cabecera
                                if ($tpl['name'] === 'starfi_difusion_directa' && $header_fmt === 'NONE') {
                                    $header_fmt = 'IMAGE';
                                }

                                $templates[] = [
                                    'name' => $tpl['name'],
                                    'language' => $tpl['language'] ?? 'es',
                                    'category' => strtoupper($tpl['category'] ?? 'MARKETING'),
                                    'status' => $st,
                                    'header_format' => $header_fmt,
                                    'header_text' => $header_txt,
                                    'header_examples' => $header_examples,
                                    'body' => $body_txt,
                                    'body_examples' => $body_examples,
                                    'footer' => $footer_txt,
                                    'buttons' => $buttons_arr
                                ];
                                if ($tpl['name'] === 'starfi_difusion_directa') {
                                    $tiene_starfi_directa = true;
                                }
                            }
                        }
                    }
                }
            }
        }

        // Si la sede tiene WABA pero aún no se ha creado starfi_difusion_directa en Meta, registrarla automáticamente en idioma 'es' (Español)
        if ($estado_starfi_directa === 'MISSING' && !empty($waba_id) && !empty($token)) {
            $create_payload = [
                "name" => "starfi_difusion_directa",
                "language" => "es",
                "category" => "MARKETING",
                "components" => [
                    [
                        "type" => "BODY",
                        "text" => "👋¡Hola {{1}}!\nHoy queremos {{2}}.\nRecuerda que en {{3}}.\n📲 *Responde a este mensaje para obtener más información*👇",
                        "example" => [
                            "body_text" => [
                                [
                                    "Carlos",
                                    "invitarte a la próxima inauguración de nuestra nueva tienda Carpintero Express con ofertas especiales para ti",
                                    "Carpintero Express estamos cada día más cerca de ti y somos tu parada obligatoria"
                                ]
                            ]
                        ]
                    ],
                    [
                        "type" => "FOOTER",
                        "text" => "Servicio de mensajería inteligente de STARFI CRM"
                    ]
                ]
            ];
            $ch_c = curl_init("https://graph.facebook.com/v23.0/{$waba_id}/message_templates");
            curl_setopt($ch_c, CURLOPT_POST, true);
            curl_setopt($ch_c, CURLOPT_POSTFIELDS, json_encode($create_payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch_c, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
            curl_setopt($ch_c, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_c, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch_c, CURLOPT_TIMEOUT, 10);
            $resp_c = json_decode(curl_exec($ch_c), true);
            curl_close($ch_c);
            if (!empty($resp_c['status'])) {
                $estado_starfi_directa = strtoupper($resp_c['status']);
                if ($estado_starfi_directa === 'APPROVED' || $estado_starfi_directa === 'ACTIVE') {
                    $tiene_starfi_directa = true;
                }
            }
        }

        // Asegurar siempre la plantilla oficial starfi_difusion_directa en la lista con su idioma 'es' (Español)
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
    // 4. CREAR CAMPAÑA CON COLA INTELIGENTE (CONTROL DE FLUJO + PRESUPUESTO)
    // =========================================================================
    case 'create_campaign_with_queue':
        $id_sede = intval($_POST['id_sede'] ?? 0);
        if (!$es_global && $id_sede_usuario > 0) {
            $id_sede = $id_sede_usuario;
        }
        $nombre_campana = trim($_POST['nombre_campana'] ?? '');
        $template_name = trim($_POST['template_name'] ?? 'starfi_difusion_directa');
        $template_language = trim($_POST['template_language'] ?? 'es') ?: 'es';
        $template_category = strtoupper(trim($_POST['template_category'] ?? 'MARKETING')) ?: 'MARKETING';
        $header_format = strtoupper(trim($_POST['header_format'] ?? 'IMAGE')) ?: 'IMAGE';
        $template_params_raw = $_POST['template_params_json'] ?? '';
        $template_params_arr = !empty($template_params_raw) ? json_decode($template_params_raw, true) : null;

        $raw_cuerpo = trim($_POST['param_cuerpo'] ?? '');
        $raw_eslogan = trim($_POST['param_eslogan'] ?? '');
        $costo_unitario_usd = max(0, floatval($_POST['costo_unitario_usd'] ?? 0.0700));
        $presupuesto_max_usd = max(0, floatval($_POST['presupuesto_max_usd'] ?? 0.00));
        $hora_prog_raw = trim($_POST['hora_programada'] ?? '09:00');
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $hora_prog_raw)) {
            $hora_prog_raw = '09:00:00';
        } elseif (strlen($hora_prog_raw) === 5) {
            $hora_prog_raw .= ':00';
        }
        $hora_programada = $hora_prog_raw;
        $destinatarios_json = $_POST['destinatarios'] ?? '[]';
        $destinatarios = json_decode($destinatarios_json, true);

        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar la Sede emisora para la campaña.']);
            exit;
        }
        if (empty($nombre_campana)) {
            $nombre_campana = 'Campaña ' . date('d/m/Y H:i');
        }

        // Validar parámetros dinámicos según las especificaciones de la plantilla seleccionada
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

            // Conservar compatibilidad con columnas param_cuerpo y param_eslogan
            $param_cuerpo = $body_params_clean[1] ?? ($body_params_clean[0] ?? 'Plantilla sin variables');
            $param_eslogan = $body_params_clean[2] ?? ($body_params_clean[1] ?? ($body_params_clean[0] ?? ''));
        } else {
            if (empty($raw_cuerpo) || empty($raw_eslogan)) {
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
        $res_lin = mysqli_query($con, "SELECT id, numero_telefono, meta_app_id, meta_token, limite_solicitudes FROM lineas_whatsapp WHERE id_sede = $id_sede AND estado = 'ACTIVO' LIMIT 1");
        if (!$res_lin || mysqli_num_rows($res_lin) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'La sede seleccionada no posee una línea oficial de WhatsApp activa.']);
            exit;
        }
        $linea = mysqli_fetch_assoc($res_lin);
        $token_efectivo = !empty($linea['meta_token']) ? trim($linea['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');
        if (empty($linea['meta_app_id']) || empty($token_efectivo)) {
            echo json_encode(['status' => 'error', 'message' => 'La línea de WhatsApp de esta sede no tiene configurado el Phone Number ID o Token de Meta.']);
            exit;
        }

        // Obtener cuota diaria y tarifa oficial de la sede (con la comisión de waba_tarifas_sede incluida)
        $res_q = mysqli_query($con, "SELECT IFNULL(q.limite_diario_mensajes, IFNULL(l.limite_solicitudes, 250)) AS limite_diario_mensajes,
                                            IFNULL(q.cadencia_ms, 1000) AS cadencia_ms,
                                            IFNULL(q.costo_marketing_usd, 0.0700) AS costo_marketing_base_usd,
                                            IFNULL(q.costo_utility_usd, 0.0150) AS costo_utility_base_usd,
                                            IFNULL(wt.tipo_tarifa, 'PORCENTAJE') AS waba_tipo_tarifa,
                                            IFNULL(wt.valor, 10.00) AS waba_valor_tarifa
                                     FROM sedes s
                                     LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id AND l.estado = 'ACTIVO'
                                     LEFT JOIN campanas_cuotas_sede q ON q.id_sede = s.id
                                     LEFT JOIN waba_tarifas_sede wt ON wt.id_sede = s.id
                                     WHERE s.id = $id_sede LIMIT 1");
        $limite_diario = max(1, intval($linea['limite_solicitudes'] ?? 250));
        $cadencia_ms = 1000;
        $base_mkt_sede = 0.0700;
        $base_util_sede = 0.0150;
        $waba_tipo_sede = 'PORCENTAJE';
        $waba_val_sede = 10.00;
        if ($res_q && mysqli_num_rows($res_q) > 0) {
            $rq = mysqli_fetch_assoc($res_q);
            $limite_diario = max(1, intval($rq['limite_diario_mensajes']));
            $cadencia_ms = max(250, intval($rq['cadencia_ms']));
            $base_mkt_sede = floatval($rq['costo_marketing_base_usd']);
            $base_util_sede = floatval($rq['costo_utility_base_usd']);
            $waba_tipo_sede = $rq['waba_tipo_tarifa'] ?: 'PORCENTAJE';
            $waba_val_sede = floatval($rq['waba_valor_tarifa'] ?? 10.00);
        }
        // Si el usuario especificó una cuota diaria mayor (ej. 1000/día), elevar automáticamente el tope diario de la sede para no truncarla a 250
        $cuota_solicitada = max(1, intval($_POST['cuota_diaria_programada'] ?? $limite_diario));
        if ($cuota_solicitada > $limite_diario) {
            $limite_diario = min(100000, $cuota_solicitada);
            mysqli_query($con, "INSERT INTO campanas_cuotas_sede (id_sede, limite_diario_mensajes, cadencia_ms) VALUES ($id_sede, $limite_diario, $cadencia_ms) ON DUPLICATE KEY UPDATE limite_diario_mensajes = GREATEST(limite_diario_mensajes, $limite_diario)");
        }
        // Aplicar el precio final oficial de la sede según la categoría de la plantilla (UTILITY o MARKETING) con la comisión configurada en waba_tarifas_sede
        $base_tarifa_aplicable = ($template_category === 'UTILITY') ? $base_util_sede : $base_mkt_sede;
        $costo_unitario_usd = calcularPrecioFinalUnitarioSede($base_tarifa_aplicable, $waba_tipo_sede, $waba_val_sede);

        // Consultar enviados hoy por esta sede
        $res_hoy = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $id_sede AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy = ($res_hoy && $rh = mysqli_fetch_assoc($res_hoy)) ? intval($rh['c']) : 0;
        $disponibles_hoy = max(0, $limite_diario - $enviados_hoy);

        // Si el usuario definió un presupuesto máximo en USD, calcular cuántos mensajes permite el presupuesto
        $max_por_presupuesto = 999999;
        if ($presupuesto_max_usd > 0 && $costo_unitario_usd > 0) {
            $max_por_presupuesto = intval(floor(($presupuesto_max_usd + 0.00001) / $costo_unitario_usd));
            if ($max_por_presupuesto <= 0) {
                echo json_encode([
                    'status' => 'error',
                    'message' => "El presupuesto máximo asignado (\$" . number_format($presupuesto_max_usd, 2) . " USD) es menor al costo de 1 mensaje (\$" . number_format($costo_unitario_usd, 4) . " USD)."
                ]);
                exit;
            }
        }

        // Limpiar, normalizar y deduplicar destinatarios por teléfono
        $dedup = [];
        foreach ($destinatarios as $d) {
            $tel_raw = trim($d['telefono'] ?? '');
            $tel_digits = normalizeVenezuelanPhone($tel_raw);
            if (strlen($tel_digits) < 8) continue;
            if (!isset($dedup[$tel_digits])) {
                $dedup[$tel_digits] = [
                    'id_cliente' => intval($d['id_cliente'] ?? 0),
                    'nombre' => trim($d['nombre'] ?? 'Cliente') ?: 'Cliente',
                    'telefono' => $tel_digits
                ];
            }
        }

        $lista_limpia = array_values($dedup);
        // Si hay tope de presupuesto, recortar la lista total de la campaña al tope presupuestario
        $recortados_por_presupuesto = 0;
        if (count($lista_limpia) > $max_por_presupuesto) {
            $recortados_por_presupuesto = count($lista_limpia) - $max_por_presupuesto;
            $lista_limpia = array_slice($lista_limpia, 0, $max_por_presupuesto);
        }

        $total_dest = count($lista_limpia);
        if ($total_dest === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Ninguno de los destinatarios tiene un número de teléfono válido.']);
            exit;
        }

        // Parámetros de programación por tandas y piloto automático diario
        $cuota_diaria_prog = max(1, intval($_POST['cuota_diaria_programada'] ?? $limite_diario));
        $cuota_diaria_prog = min($cuota_diaria_prog, $limite_diario);
        $piloto_automatico = intval($_POST['piloto_automatico'] ?? 1) === 1 ? 1 : 0;

        // Calcular tandas diarias proyectadas (considerando disponibilidad hoy para la Tanda 1)
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
            // Si hoy ya no hay cupo en la sede, la Tanda 1 arranca mañana
            $dia_offset = 1;
        }

        while ($restantes_calc > 0) {
            $cant_t = min($restantes_calc, $cuota_diaria_prog);
            $fecha_t = date('Y-m-d', strtotime("+$dia_offset days"));
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

        // Procesar imagen opcional de cabecera subida para la campaña (acepta JPG, PNG y convierte WEBP/GIF automáticamente a PNG para Meta)
        $header_imagen_path = null;
        if (isset($_FILES['header_imagen']) && $_FILES['header_imagen']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['header_imagen']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['status' => 'error', 'message' => 'Error al subir la imagen de cabecera (Código: ' . intval($_FILES['header_imagen']['error']) . ').']);
                exit;
            }
            $tmp_img = $_FILES['header_imagen']['tmp_name'];
            $mime_img = @mime_content_type($tmp_img) ?: '';
            $dir_upload = __DIR__ . '/../../uploads/campanas';
            if (!is_dir($dir_upload)) {
                @mkdir($dir_upload, 0775, true);
            }

            if ($mime_img === 'image/jpeg' || $mime_img === 'image/jpg') {
                $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.jpg';
                $dest_img = $dir_upload . DIRECTORY_SEPARATOR . $fname_img;
                if (@move_uploaded_file($tmp_img, $dest_img)) {
                    $header_imagen_path = 'uploads/campanas/' . $fname_img;
                }
            } elseif ($mime_img === 'image/png') {
                $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.png';
                $dest_img = $dir_upload . DIRECTORY_SEPARATOR . $fname_img;
                if (@move_uploaded_file($tmp_img, $dest_img)) {
                    $header_imagen_path = 'uploads/campanas/' . $fname_img;
                }
            } elseif (in_array($mime_img, ['image/webp', 'image/gif', 'image/bmp'], true) && function_exists('imagecreatefromstring')) {
                $raw_bytes = @file_get_contents($tmp_img);
                $gd_img = $raw_bytes ? @imagecreatefromstring($raw_bytes) : false;
                if ($gd_img !== false) {
                    $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.png';
                    $dest_img = $dir_upload . DIRECTORY_SEPARATOR . $fname_img;
                    if (@imagepng($gd_img, $dest_img, 8)) {
                        $header_imagen_path = 'uploads/campanas/' . $fname_img;
                    }
                    @imagedestroy($gd_img);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'No se pudo procesar el formato de la imagen subida. Use JPG o PNG.']);
                    exit;
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Formato de imagen no válido (' . htmlspecialchars($mime_img) . '). Suba un archivo JPG, PNG o WEBP.']);
                exit;
            }
        }

        // Insertar campaña en estado PENDIENTE_APROBACION (no envía nada hasta ser aprobada)
        $stmt = $con->prepare("INSERT INTO campanas_difusion 
            (id_sede, id_usuario, nombre_campana, template_name, template_language, template_category, header_format, param_cuerpo, param_eslogan, template_params_json, header_imagen_path, costo_unitario_usd, presupuesto_max_usd, costo_estimado_usd, cuota_diaria_programada, total_tandas, hora_programada, piloto_automatico, total_destinatarios, pendientes, estado) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE_APROBACION')");
        $stmt->bind_param(
            "iisssssssssdddiisiii",
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
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Error al registrar la campaña en base de datos: ' . $con->error]);
            exit;
        }
        $id_campana = $stmt->insert_id;
        $stmt->close();

        // Insertar todos los destinatarios en la cola como PENDIENTE asignándoles su Tanda y Fecha Programada
        $stmt_d = $con->prepare("INSERT INTO campanas_difusion_destinatarios (id_campana, id_sede, id_cliente, nombre, telefono, numero_tanda, fecha_programada, estado_envio) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDIENTE')");
        $cola_items = [];
        $cursor_item = 0;
        foreach ($plan_tandas as $t_info) {
            $num_tanda = $t_info['numero_tanda'];
            $fecha_prog = $t_info['fecha_programada'];
            $cant_en_tanda = $t_info['cantidad'];
            for ($k = 0; $k < $cant_en_tanda && $cursor_item < $total_dest; $k++, $cursor_item++) {
                $item = $lista_limpia[$cursor_item];
                $id_cli = $item['id_cliente'];
                $nom_cli = $item['nombre'];
                $tel_cli = $item['telefono'];
                $stmt_d->bind_param("iiissis", $id_campana, $id_sede, $id_cli, $nom_cli, $tel_cli, $num_tanda, $fecha_prog);
                if ($stmt_d->execute()) {
                    $cola_items[] = [
                        'id_destinatario' => $stmt_d->insert_id,
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
            'total_tandas' => $total_tandas,
            'costo_unitario_usd' => $costo_unitario_usd,
            'costo_estimado_usd' => $costo_estimado_usd,
            'recortados_por_presupuesto' => $recortados_por_presupuesto,
            'plan_tandas' => $plan_tandas
        ]);
        break;

    // =========================================================================
    // 4.1 APROBAR CAMPAÑA E INICIAR DIFUSIÓN (Y DEJAR PILOTO AUTOMÁTICO ACTIVO)
    // =========================================================================
    case 'approve_and_start_campaign':
        $id_campana = intval($_POST['id_campana'] ?? $_GET['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID de campaña inválido.']);
            exit;
        }
        $res_c = mysqli_query($con, "SELECT * FROM campanas_difusion WHERE id = $id_campana LIMIT 1");
        if (!$res_c || mysqli_num_rows($res_c) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
            exit;
        }
        $camp = mysqli_fetch_assoc($res_c);
        $id_sede = intval($camp['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: solo puede gestionar campañas de su propia sede.']);
            exit;
        }

        // Si el operador actualizó la hora programada al aprobar, guardarla
        if (!empty($_POST['hora_programada'])) {
            $hp_raw = trim($_POST['hora_programada']);
            if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $hp_raw)) {
                if (strlen($hp_raw) === 5) $hp_raw .= ':00';
                $hp_esc = mysqli_real_escape_string($con, $hp_raw);
                mysqli_query($con, "UPDATE campanas_difusion SET hora_programada = '$hp_esc' WHERE id = $id_campana");
                $camp['hora_programada'] = $hp_raw;
            }
        }

        // Si el operador seleccionó una nueva imagen/flyer antes de aprobar, actualizarla y limpiar header_media_id previo
        if (isset($_FILES['header_imagen']) && $_FILES['header_imagen']['error'] === UPLOAD_ERR_OK) {
            $tmp_img = $_FILES['header_imagen']['tmp_name'];
            $mime_img = @mime_content_type($tmp_img) ?: '';
            $dir_upload = __DIR__ . '/../../uploads/campanas';
            if (!is_dir($dir_upload)) {
                @mkdir($dir_upload, 0775, true);
            }
            $new_rel_path = null;
            if ($mime_img === 'image/jpeg' || $mime_img === 'image/jpg') {
                $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.jpg';
                if (@move_uploaded_file($tmp_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img)) {
                    $new_rel_path = 'uploads/campanas/' . $fname_img;
                }
            } elseif ($mime_img === 'image/png') {
                $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.png';
                if (@move_uploaded_file($tmp_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img)) {
                    $new_rel_path = 'uploads/campanas/' . $fname_img;
                }
            } elseif (in_array($mime_img, ['image/webp', 'image/gif', 'image/bmp'], true) && function_exists('imagecreatefromstring')) {
                $raw_bytes = @file_get_contents($tmp_img);
                $gd_img = $raw_bytes ? @imagecreatefromstring($raw_bytes) : false;
                if ($gd_img !== false) {
                    $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.png';
                    if (@imagepng($gd_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img, 8)) {
                        $new_rel_path = 'uploads/campanas/' . $fname_img;
                    }
                    @imagedestroy($gd_img);
                }
            }
            if ($new_rel_path) {
                $rel_esc = mysqli_real_escape_string($con, $new_rel_path);
                mysqli_query($con, "UPDATE campanas_difusion SET header_imagen_path = '$rel_esc', header_media_id = NULL WHERE id = $id_campana");
                $camp['header_imagen_path'] = $new_rel_path;
                $camp['header_media_id'] = null;
            }
        }

        // Obtener configuración de flujo diario de la sede
        $res_q = mysqli_query($con, "SELECT IFNULL(q.limite_diario_mensajes, IFNULL(l.limite_solicitudes, 250)) AS limite_diario_mensajes, IFNULL(q.cadencia_ms, 1000) AS cadencia_ms FROM sedes s LEFT JOIN lineas_whatsapp l ON l.id_sede = s.id AND l.estado = 'ACTIVO' LEFT JOIN campanas_cuotas_sede q ON q.id_sede = s.id WHERE s.id = $id_sede LIMIT 1");
        $limite_diario_sede = 250;
        $cadencia_ms = 1000;
        if ($res_q && mysqli_num_rows($res_q) > 0) {
            $rq = mysqli_fetch_assoc($res_q);
            $limite_diario_sede = max(1, intval($rq['limite_diario_mensajes']));
            $cadencia_ms = max(250, intval($rq['cadencia_ms']));
        }

        $cuota_campana = max(1, intval($camp['cuota_diaria_programada'] ?? $limite_diario_sede));
        if ($cuota_campana > $limite_diario_sede) {
            $limite_diario_sede = $cuota_campana;
        }
        $tope_efectivo_hoy = min($limite_diario_sede, $cuota_campana);

        $res_hoy = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $id_sede AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy_sede = ($res_hoy && $rh = mysqli_fetch_assoc($res_hoy)) ? intval($rh['c']) : 0;

        $res_hoy_camp = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy_camp = ($res_hoy_camp && $rhc = mysqli_fetch_assoc($res_hoy_camp)) ? intval($rhc['c']) : 0;

        $disp_sede = max(0, $limite_diario_sede - $enviados_hoy_sede);
        $disp_camp = max(0, $tope_efectivo_hoy - $enviados_hoy_camp);
        $disponibles_hoy = min($disp_sede, $disp_camp);

        $hora_prog_camp = !empty($camp['hora_programada']) ? substr(trim($camp['hora_programada']), 0, 5) : '09:00';
        $hora_actual_hm = date('H:i');
        $iniciar_inmediato = isset($_POST['iniciar_inmediato']) ? (intval($_POST['iniciar_inmediato']) === 1) : true;
        $esperar_hora_programada = (!$iniciar_inmediato && $hora_actual_hm < $hora_prog_camp);

        // Marcar campaña como APROBADA / EN_COLA_PILOTO_AUTO
        $nuevo_estado = ($disponibles_hoy > 0 && !$esperar_hora_programada) ? 'APROBADA_EN_PROCESO' : 'PILOTO_AUTOMATICO';
        mysqli_query($con, "UPDATE campanas_difusion 
                            SET estado = '$nuevo_estado', 
                                aprobado_por = COALESCE(aprobado_por, $id_usuario), 
                                fecha_aprobacion = COALESCE(fecha_aprobacion, NOW()) 
                            WHERE id = $id_campana");

        $cola = [];
        if ($disponibles_hoy > 0 && !$esperar_hora_programada) {
            $res_pend = mysqli_query($con, "SELECT id AS id_destinatario, id_cliente, nombre, telefono, numero_tanda, fecha_programada 
                                            FROM campanas_difusion_destinatarios 
                                            WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' 
                                            ORDER BY numero_tanda ASC, id ASC 
                                            LIMIT $disponibles_hoy");
            if ($res_pend) {
                while ($r = mysqli_fetch_assoc($res_pend)) {
                    $cola[] = $r;
                }
            }
            if (empty($cola)) {
                $res_fal = mysqli_query($con, "SELECT id FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'FALLIDO' LIMIT $disponibles_hoy");
                if ($res_fal && mysqli_num_rows($res_fal) > 0) {
                    mysqli_query($con, "UPDATE campanas_difusion_destinatarios SET estado_envio = 'PENDIENTE', error_detalle = NULL WHERE id_campana = $id_campana AND estado_envio = 'FALLIDO'");
                } else {
                    // Si el operador presionó Reenviar sobre una campaña ya completada (o tras cambiar el flyer), reencolar todos sus destinatarios para nuevo despacho
                    mysqli_query($con, "UPDATE campanas_difusion_destinatarios SET estado_envio = 'PENDIENTE', error_detalle = NULL, id_mensaje_meta = NULL, fecha_envio = NULL WHERE id_campana = $id_campana");
                }
                recalcularMetricasCampana($con, $id_campana);
                $res_pend2 = mysqli_query($con, "SELECT id AS id_destinatario, id_cliente, nombre, telefono, numero_tanda, fecha_programada 
                                                 FROM campanas_difusion_destinatarios 
                                                 WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' 
                                                 ORDER BY numero_tanda ASC, id ASC 
                                                 LIMIT $disponibles_hoy");
                if ($res_pend2) {
                    while ($r2 = mysqli_fetch_assoc($res_pend2)) {
                        $cola[] = $r2;
                    }
                }
            }
        }

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
        $where_own = (!$es_global && $id_sede_usuario > 0) ? " AND id_sede = $id_sede_usuario" : "";
        mysqli_query($con, "UPDATE campanas_difusion SET estado = 'CANCELADA', fecha_fin = NOW() WHERE id = $id_campana $where_own");
        echo json_encode(['status' => 'success', 'message' => 'Campaña cancelada.']);
        break;

    // =========================================================================
    // 4.2B EDITAR / REAJUSTAR CAMPAÑA ANTES DE APROBAR (CUOTA DIARIA, TOPE DE CONTACTOS, HORA, VARIABLES, FLYER)
    // =========================================================================
    case 'edit_pending_campaign':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID de campaña inválido.']);
            exit;
        }
        $res_c = mysqli_query($con, "SELECT * FROM campanas_difusion WHERE id = $id_campana LIMIT 1");
        if (!$res_c || mysqli_num_rows($res_c) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
            exit;
        }
        $camp = mysqli_fetch_assoc($res_c);
        $id_sede = intval($camp['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: solo puede editar campañas de su propia sede.']);
            exit;
        }

        $nuevo_nombre = trim($_POST['nombre_campana'] ?? $camp['nombre_campana']);
        if ($nuevo_nombre === '') {
            $nuevo_nombre = $camp['nombre_campana'];
        }
        $nueva_cuota_diaria = max(1, min(100000, intval($_POST['cuota_diaria_programada'] ?? ($camp['cuota_diaria_programada'] ?: 250))));
        $nueva_hora_raw = trim($_POST['hora_programada'] ?? ($camp['hora_programada'] ?: '09:00:00'));
        if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $nueva_hora_raw)) {
            $nueva_hora_raw = '09:00:00';
        } elseif (strlen($nueva_hora_raw) === 5) {
            $nueva_hora_raw .= ':00';
        }
        $nuevo_piloto = isset($_POST['piloto_automatico']) ? (intval($_POST['piloto_automatico']) === 1 ? 1 : 0) : intval($camp['piloto_automatico'] ?? 1);
        $nuevo_presupuesto = max(0, floatval($_POST['presupuesto_max_usd'] ?? ($camp['presupuesto_max_usd'] ?? 0)));
        $tope_destinatarios = intval($_POST['tope_destinatarios'] ?? 0); // Si > 0 y menor que los pendientes, recorta la cola
        $cuota_por_sede_recorte = intval($_POST['cuota_por_sede'] ?? 0); // Si > 0, deja máximo X destinatarios por cada sede de origen del cliente

        // Si la nueva cuota diaria supera el límite diario actual de la sede, actualizar también el límite diario de la sede para que no la frene a 250
        mysqli_query($con, "INSERT INTO campanas_cuotas_sede (id_sede, limite_diario_mensajes, cadencia_ms) VALUES ($id_sede, $nueva_cuota_diaria, 1000) ON DUPLICATE KEY UPDATE limite_diario_mensajes = GREATEST(limite_diario_mensajes, $nueva_cuota_diaria)");

        // 1. Si pidió recortar por cuota por sede (ej: máximo 100 o 250 por sede en una campaña global)
        if ($cuota_por_sede_recorte > 0) {
            $res_all_d = mysqli_query($con, "SELECT d.id, COALESCE(c.id_sede, 0) AS id_sede_cli
                                             FROM campanas_difusion_destinatarios d
                                             LEFT JOIN clientes_contactos c ON c.id = d.id_cliente
                                             WHERE d.id_campana = $id_campana AND d.estado_envio = 'PENDIENTE'
                                             ORDER BY d.id ASC");
            $conteo_s = [];
            $ids_eliminar = [];
            if ($res_all_d) {
                while ($rd = mysqli_fetch_assoc($res_all_d)) {
                    $sc = intval($rd['id_sede_cli']);
                    if (!isset($conteo_s[$sc])) $conteo_s[$sc] = 0;
                    if ($conteo_s[$sc] < $cuota_por_sede_recorte) {
                        $conteo_s[$sc]++;
                    } else {
                        $ids_eliminar[] = intval($rd['id']);
                    }
                }
            }
            if (!empty($ids_eliminar)) {
                foreach (array_chunk($ids_eliminar, 500) as $chunk_del) {
                    $in_del = implode(',', $chunk_del);
                    mysqli_query($con, "DELETE FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' AND id IN ($in_del)");
                }
            }
        }

        // 2. Si pidió recortar al tope total de destinatarios (ej: bajar de 9354 a 1000 contactos en 1 tanda)
        $costo_unit = floatval($camp['costo_unitario_usd'] ?: 0.0770);
        if ($nuevo_presupuesto > 0 && $costo_unit > 0) {
            $max_pres = intval(floor(($nuevo_presupuesto + 0.00001) / $costo_unit));
            if ($max_pres > 0 && ($tope_destinatarios <= 0 || $max_pres < $tope_destinatarios)) {
                $tope_destinatarios = $max_pres;
            }
        }

        if ($tope_destinatarios > 0) {
            $res_keep = mysqli_query($con, "SELECT id FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana ORDER BY FIELD(estado_envio, 'RESPONDIDO','LEIDO','ENTREGADO','ENVIADO','PENDIENTE','FALLIDO'), id ASC LIMIT $tope_destinatarios");
            $keep_ids = [];
            if ($res_keep) {
                while ($rk = mysqli_fetch_assoc($res_keep)) {
                    $keep_ids[] = intval($rk['id']);
                }
            }
            if (!empty($keep_ids)) {
                // Eliminar los PENDIENTE que excedan el tope
                $res_all_p = mysqli_query($con, "SELECT id FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE'");
                $keep_map = array_flip($keep_ids);
                $del_ids = [];
                if ($res_all_p) {
                    while ($rp = mysqli_fetch_assoc($res_all_p)) {
                        $pid = intval($rp['id']);
                        if (!isset($keep_map[$pid])) {
                            $del_ids[] = $pid;
                        }
                    }
                }
                if (!empty($del_ids)) {
                    foreach (array_chunk($del_ids, 500) as $chunk_del) {
                        $in_del = implode(',', $chunk_del);
                        mysqli_query($con, "DELETE FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' AND id IN ($in_del)");
                    }
                }
            }
        }

        // 3. Reasignar numero_tanda y fecha_programada a todos los destinatarios PENDIENTE según la nueva cuota diaria
        $res_hoy = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $id_sede AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy = ($res_hoy && $rh = mysqli_fetch_assoc($res_hoy)) ? intval($rh['c']) : 0;
        $cupo_hoy = max(0, $nueva_cuota_diaria - $enviados_hoy);

        $res_pend_ids = mysqli_query($con, "SELECT id FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' ORDER BY id ASC");
        $pend_ids = [];
        if ($res_pend_ids) {
            while ($r_pi = mysqli_fetch_assoc($res_pend_ids)) {
                $pend_ids[] = intval($r_pi['id']);
            }
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
                    foreach (array_chunk($slice_1, 500) as $ch_1) {
                        $in_1 = implode(',', $ch_1);
                        mysqli_query($con, "UPDATE campanas_difusion_destinatarios SET numero_tanda = 1, fecha_programada = '$fecha_t1' WHERE id IN ($in_1)");
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
                if (empty($slice_t)) break;
                $fecha_t = date('Y-m-d', strtotime("+$dia_offset days"));
                foreach (array_chunk($slice_t, 500) as $ch_t) {
                    $in_t = implode(',', $ch_t);
                    mysqli_query($con, "UPDATE campanas_difusion_destinatarios SET numero_tanda = $num_tanda_actual, fecha_programada = '$fecha_t' WHERE id IN ($in_t)");
                }
                $cursor_p += count($slice_t);
                $num_tanda_actual++;
                $dia_offset++;
            }
        }

        $nuevas_tandas_totales = max(1, ($num_tanda_actual > 1 ? $num_tanda_actual - 1 : 1));

        // 4. Actualizar variables de plantilla si fueron enviadas
        $sql_extra_cols = "";
        if (!empty($_POST['param_cuerpo']) || !empty($_POST['param_eslogan'])) {
            $p_cuerpo = sanitizeMetaTemplateParam($_POST['param_cuerpo'] ?? $camp['param_cuerpo'], true);
            $p_eslogan = sanitizeMetaTemplateParam($_POST['param_eslogan'] ?? $camp['param_eslogan'], true);
            $p_cuerpo_esc = mysqli_real_escape_string($con, $p_cuerpo);
            $p_eslogan_esc = mysqli_real_escape_string($con, $p_eslogan);
            $sql_extra_cols .= ", param_cuerpo = '$p_cuerpo_esc', param_eslogan = '$p_eslogan_esc'";

            $tpl_json_curr = !empty($camp['template_params_json']) ? json_decode($camp['template_params_json'], true) : [];
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
                $json_upd_esc = mysqli_real_escape_string($con, json_encode($tpl_json_curr, JSON_UNESCAPED_UNICODE));
                $sql_extra_cols .= ", template_params_json = '$json_upd_esc'";
            }
        }

        // 5. Si adjuntó un nuevo flyer/imagen en la edición
        if (isset($_FILES['header_imagen']) && $_FILES['header_imagen']['error'] === UPLOAD_ERR_OK) {
            $tmp_img = $_FILES['header_imagen']['tmp_name'];
            $mime_img = @mime_content_type($tmp_img) ?: '';
            $dir_upload = __DIR__ . '/../../uploads/campanas';
            if (!is_dir($dir_upload)) @mkdir($dir_upload, 0775, true);
            $new_rel_path = null;
            if ($mime_img === 'image/jpeg' || $mime_img === 'image/jpg') {
                $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.jpg';
                if (@move_uploaded_file($tmp_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img)) {
                    $new_rel_path = 'uploads/campanas/' . $fname_img;
                }
            } elseif ($mime_img === 'image/png') {
                $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.png';
                if (@move_uploaded_file($tmp_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img)) {
                    $new_rel_path = 'uploads/campanas/' . $fname_img;
                }
            } elseif (in_array($mime_img, ['image/webp', 'image/gif', 'image/bmp'], true) && function_exists('imagecreatefromstring')) {
                $raw_bytes = @file_get_contents($tmp_img);
                $gd_img = $raw_bytes ? @imagecreatefromstring($raw_bytes) : false;
                if ($gd_img !== false) {
                    $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.png';
                    if (@imagepng($gd_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img, 8)) {
                        $new_rel_path = 'uploads/campanas/' . $fname_img;
                    }
                    @imagedestroy($gd_img);
                }
            }
            if ($new_rel_path) {
                $rel_esc = mysqli_real_escape_string($con, $new_rel_path);
                $sql_extra_cols .= ", header_imagen_path = '$rel_esc', header_media_id = NULL";
            }
        }

        recalcularMetricasCampana($con, $id_campana);
        $res_cnt = mysqli_query($con, "SELECT COUNT(*) AS tot, SUM(CASE WHEN estado_envio='PENDIENTE' THEN 1 ELSE 0 END) AS pend FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana");
        $rcnt = ($res_cnt && $r_c = mysqli_fetch_assoc($res_cnt)) ? $r_c : ['tot' => 0, 'pend' => 0];
        $nuevo_total_dest = intval($rcnt['tot']);
        $nuevo_pend_dest = intval($rcnt['pend']);
        $nuevo_costo_est = round($nuevo_total_dest * $costo_unit, 4);

        $nom_esc = mysqli_real_escape_string($con, $nuevo_nombre);
        $hora_esc = mysqli_real_escape_string($con, $nueva_hora_raw);

        mysqli_query($con, "UPDATE campanas_difusion
                            SET nombre_campana = '$nom_esc',
                                cuota_diaria_programada = $nueva_cuota_diaria,
                                total_tandas = $nuevas_tandas_totales,
                                hora_programada = '$hora_esc',
                                piloto_automatico = $nuevo_piloto,
                                presupuesto_max_usd = $nuevo_presupuesto,
                                total_destinatarios = $nuevo_total_dest,
                                pendientes = $nuevo_pend_dest,
                                costo_estimado_usd = $nuevo_costo_est
                                $sql_extra_cols
                            WHERE id = $id_campana");

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
            $where_own = (!$es_global && $id_sede_usuario > 0) ? " AND id_sede = $id_sede_usuario" : "";
            mysqli_query($con, "UPDATE campanas_difusion SET piloto_automatico = $activo WHERE id = $id_campana $where_own");
        }
        echo json_encode(['status' => 'success', 'piloto_automatico' => $activo]);
        break;

    // =========================================================================
    // 4.4 CONSULTAR SI HAY LOTES PENDIENTES HOY DE CAMPAÑAS APROBADAS EN PILOTO AUTOMÁTICO
    // =========================================================================
    case 'check_autopilot_due':
        $filtro_sede = intval($_GET['id_sede'] ?? $_POST['id_sede'] ?? 0);
        if (!$es_global && $id_sede_usuario > 0) {
            $filtro_sede = $id_sede_usuario;
        }
        $where_ap = "WHERE c.piloto_automatico = 1 AND c.aprobado_por IS NOT NULL AND c.pendientes > 0 AND c.estado IN ('PILOTO_AUTOMATICO','EN_COLA_DIARIA','APROBADA_EN_PROCESO','PAUSADA_CUOTA_DIARIA') AND IFNULL(c.hora_programada, '00:00:00') <= CURTIME()";
        if ($filtro_sede > 0) {
            $where_ap .= " AND c.id_sede = $filtro_sede";
        }
        $res_ap = mysqli_query($con, "SELECT c.id, c.id_sede, c.nombre_campana, c.cuota_diaria_programada, c.hora_programada, c.pendientes, c.total_tandas
                                      FROM campanas_difusion c
                                      $where_ap
                                      ORDER BY c.id ASC");
        $campanas_listas = [];
        if ($res_ap) {
            while ($cap = mysqli_fetch_assoc($res_ap)) {
                $sid = intval($cap['id_sede']);
                $cid = intval($cap['id']);
                $res_q = mysqli_query($con, "SELECT limite_diario_mensajes, cadencia_ms FROM campanas_cuotas_sede WHERE id_sede = $sid LIMIT 1");
                $lim_sede = 250;
                $cad_sede = 1000;
                if ($res_q && mysqli_num_rows($res_q) > 0) {
                    $rq = mysqli_fetch_assoc($res_q);
                    $lim_sede = max(1, intval($rq['limite_diario_mensajes']));
                    $cad_sede = max(250, intval($rq['cadencia_ms']));
                }
                $cuota_c = max(1, intval($cap['cuota_diaria_programada'] ?: $lim_sede));
                if ($cuota_c > $lim_sede) $lim_sede = $cuota_c;
                $tope_hoy = min($lim_sede, $cuota_c);

                $rh_s = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $sid AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
                $env_sede_hoy = ($rh_s && $r1 = mysqli_fetch_assoc($rh_s)) ? intval($r1['c']) : 0;

                $rh_c = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = $cid AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
                $env_camp_hoy = ($rh_c && $r2 = mysqli_fetch_assoc($rh_c)) ? intval($r2['c']) : 0;

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
        }
        echo json_encode(['status' => 'success', 'autopilot_campaigns' => $campanas_listas]);
        break;

    // =========================================================================
    // 5. DESPACHAR UN DESTINATARIO DE LA COLA (CON PROTECCIÓN ANTI-BAN Y PRESUPUESTO)
    // =========================================================================
    case 'dispatch_queue_item':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        $id_destinatario = intval($_POST['id_destinatario'] ?? 0);
        $language_code = trim($_POST['language_code'] ?? 'es');

        if ($id_campana <= 0 || $id_destinatario <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros de cola inválidos.']);
            exit;
        }

        // Cargar campaña y destinatario
        $res_c = mysqli_query($con, "SELECT * FROM campanas_difusion WHERE id = $id_campana LIMIT 1");
        $res_d = mysqli_query($con, "SELECT * FROM campanas_difusion_destinatarios WHERE id = $id_destinatario AND id_campana = $id_campana LIMIT 1");
        if (!$res_c || mysqli_num_rows($res_c) === 0 || !$res_d || mysqli_num_rows($res_d) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'No se encontró la campaña o el destinatario.']);
            exit;
        }
        $camp = mysqli_fetch_assoc($res_c);
        $dest = mysqli_fetch_assoc($res_d);
        $id_sede = intval($camp['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: esta campaña pertenece a otra sede.']);
            exit;
        }

        // Verificar que la campaña haya sido APROBADA antes de permitir el envío de cualquier mensaje
        if ($camp['estado'] === 'PENDIENTE_APROBACION' || empty($camp['aprobado_por'])) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Esta campaña aún se encuentra PENDIENTE DE APROBACIÓN. Debe ser aprobada antes de iniciar el envío.'
            ]);
            exit;
        }

        // Verificar Control de Flujo Diario (Sede y Cuota Diaria de la Campaña) antes de disparar
        $res_q = mysqli_query($con, "SELECT limite_diario_mensajes FROM campanas_cuotas_sede WHERE id_sede = $id_sede LIMIT 1");
        $limite_diario_sede = ($res_q && $rq = mysqli_fetch_assoc($res_q)) ? max(1, intval($rq['limite_diario_mensajes'])) : 250;
        $cuota_campana = max(1, intval($camp['cuota_diaria_programada'] ?? $limite_diario_sede));
        if ($cuota_campana > $limite_diario_sede) $limite_diario_sede = $cuota_campana;
        $limite_diario = min($limite_diario_sede, $cuota_campana);

        $res_hoy = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $id_sede AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy_sede = ($res_hoy && $rh = mysqli_fetch_assoc($res_hoy)) ? intval($rh['c']) : 0;

        $res_hoy_camp = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy_camp = ($res_hoy_camp && $rhc = mysqli_fetch_assoc($res_hoy_camp)) ? intval($rhc['c']) : 0;
        $enviados_hoy = $enviados_hoy_sede;

        if ($enviados_hoy_sede >= $limite_diario_sede || $enviados_hoy_camp >= $cuota_campana) {
            $est_pausa = (intval($camp['piloto_automatico'] ?? 1) === 1) ? 'PILOTO_AUTOMATICO' : 'PAUSADA_CUOTA_DIARIA';
            mysqli_query($con, "UPDATE campanas_difusion SET estado = '$est_pausa' WHERE id = $id_campana");
            echo json_encode([
                'status' => 'quota_reached',
                'message' => "Tanda diaria completada ($cuota_campana msgs/día). Los destinatarios restantes quedan programados en Piloto Automático para la siguiente tanda diaria."
            ]);
            exit;
        }

        // Verificar Presupuesto Máximo de la campaña antes de disparar
        $presupuesto_max = floatval($camp['presupuesto_max_usd']);
        $costo_unit = floatval($camp['costo_unitario_usd']);
        $costo_actual = floatval($camp['costo_real_usd']);
        if ($presupuesto_max > 0 && ($costo_actual + $costo_unit) > ($presupuesto_max + 0.0001)) {
            mysqli_query($con, "UPDATE campanas_difusion SET estado = 'TOPE_PRESUPUESTO' WHERE id = $id_campana");
            echo json_encode([
                'status' => 'budget_reached',
                'message' => "Se alcanzó el tope de presupuesto asignado a la campaña (\$" . number_format($presupuesto_max, 2) . " USD)."
            ]);
            exit;
        }

        // Obtener credenciales de la línea WhatsApp de la sede
        $res_lin = mysqli_query($con, "SELECT l.id, l.meta_app_id, l.id_negocio, l.meta_token, l.numero_telefono, COALESCE(s.id_empresa, 1) AS id_empresa, COALESCE(s.nombre_sede, 'Sede') AS nombre_sede
                                       FROM lineas_whatsapp l
                                       LEFT JOIN sedes s ON s.id = l.id_sede
                                       WHERE l.id_sede = $id_sede AND l.estado = 'ACTIVO' LIMIT 1");
        if (!$res_lin || mysqli_num_rows($res_lin) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'No hay línea activa en la sede.']);
            exit;
        }
        $linea = mysqli_fetch_assoc($res_lin);
        $id_linea = intval($linea['id']);
        $id_empresa = intval($linea['id_empresa'] ?? 1);
        $nombre_sede_actual = trim($linea['nombre_sede'] ?? 'Sede');
        $phone_id = trim($linea['meta_app_id']);
        $waba_id_linea = trim($linea['id_negocio'] ?? '');
        $token = !empty($linea['meta_token']) ? trim($linea['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

        // Garantizar que el WABA de la sede esté suscrito a los webhooks de la App para recibir estados de entrega/lectura/fallo
        if (!empty($waba_id_linea) && !empty($token) && empty($_SESSION['waba_sub_verified_' . $waba_id_linea])) {
            $ch_sub = curl_init("https://graph.facebook.com/v23.0/{$waba_id_linea}/subscribed_apps");
            curl_setopt($ch_sub, CURLOPT_POST, true);
            curl_setopt($ch_sub, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
            curl_setopt($ch_sub, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_sub, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch_sub, CURLOPT_TIMEOUT, 5);
            @curl_exec($ch_sub);
            curl_close($ch_sub);
            $_SESSION['waba_sub_verified_' . $waba_id_linea] = true;
        }

        // Formatear teléfono destino (normalizar 04xx Venezuela a 584xx)
        $telefono_limpio = normalizeVenezuelanPhone($dest['telefono']);

        $nombre_cliente = trim($dest['nombre'] ?: 'Cliente');
        // Extraer primer nombre amigable para variables dinámicas de saludo
        $primer_nombre = explode(' ', $nombre_cliente)[0];
        if (empty($primer_nombre) || strlen($primer_nombre) < 2 || preg_match('/^\+?[0-9\s\-]+$/', $primer_nombre)) {
            $primer_nombre = 'Estimado(a) cliente';
        }
        $primer_nombre = sanitizeMetaTemplateParam($primer_nombre, false);

        $template_name = $camp['template_name'] ?: 'starfi_difusion_directa';
        $camp_lang = trim($camp['template_language'] ?? '') ?: $language_code;
        $camp_cat = strtoupper(trim($camp['template_category'] ?? 'MARKETING')) ?: 'MARKETING';
        $camp_hdr_fmt = strtoupper(trim($camp['header_format'] ?? 'IMAGE')) ?: 'IMAGE';

        $resolveDynamicToken = function ($val_raw) use ($primer_nombre, $nombre_cliente, $telefono_limpio, $nombre_sede_actual) {
            $str = (string)$val_raw;
            $str = str_ireplace(['@NOMBRE_CLIENTE', '{{nombre_cliente}}'], $primer_nombre, $str);
            $str = str_ireplace(['@NOMBRE_COMPLETO', '{{nombre_completo}}'], $nombre_cliente, $str);
            $str = str_ireplace(['@TELEFONO_CLIENTE', '{{telefono_cliente}}'], $telefono_limpio, $str);
            $str = str_ireplace(['@NOMBRE_SEDE', '{{nombre_sede}}'], $nombre_sede_actual, $str);
            return sanitizeMetaTemplateParam($str, true);
        };

        $tpl_json_data = !empty($camp['template_params_json']) ? json_decode($camp['template_params_json'], true) : null;
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

        $url = "https://graph.facebook.com/v23.0/{$phone_id}/messages";
        $headers = [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ];

        // Idioma oficial de la plantilla seleccionada
        $primary_lang = ($camp_lang && !in_array($camp_lang, ['es_MX', 'es_LA'], true)) ? $camp_lang : 'es';
        $lang_candidates = array_values(array_unique([$primary_lang, 'es', 'es_ES']));
        $http_code = 0;
        $curl_err = '';
        $resp_data = [];
        $first_resp_data = null;

        // Determinar si la plantilla utiliza cabecera de imagen
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
                            [
                                'type' => 'image',
                                'image' => ['id' => $header_media_id]
                            ]
                        ]
                    ];
                } elseif ($camp_hdr_fmt === 'TEXT' && $header_text_resolved !== '') {
                    $components_payload[] = [
                        'type' => 'header',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $header_text_resolved
                            ]
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
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_err = curl_error($ch);
                curl_close($ch);

                $resp_data = json_decode($response, true) ?? [];
                if ($idx_lang === 0 && $first_resp_data === null) {
                    $first_resp_data = $resp_data;
                }
                $err_code_meta = intval($resp_data['error']['code'] ?? 0);
                $err_details_meta = strtoupper($resp_data['error']['error_data']['details'] ?? ($resp_data['error']['message'] ?? ''));

                // Si Meta devuelve 132012 (Format mismatch) o 131053 (Media upload error / media_id expirado):
                if ($attempt_img === 0 && ($err_code_meta === 132012 || $err_code_meta === 131053 || $err_code_meta === 132000)) {
                    if (strpos($err_details_meta, 'EXPECTED IMAGE') !== false) {
                        // La plantilla exige imagen y no la teníamos o el media_id expiró -> forzar subida fresca
                        $needs_image_header = true;
                        $camp['header_media_id'] = '';
                        $header_media_id = getOrUploadCampaignHeaderMediaId($con, $camp, $phone_id, $token);
                        continue;
                    } elseif (strpos($err_details_meta, 'EXPECTED UNKNOWN') !== false || strpos($err_details_meta, 'EXPECTED TEXT') !== false || ($err_code_meta === 132000 && $needs_image_header)) {
                        // La plantilla en esta sede NO lleva imagen en el header -> reintentar sin header
                        $needs_image_header = false;
                        $header_media_id = null;
                        continue;
                    } elseif ($err_code_meta === 131053 && $needs_image_header) {
                        // El media_id guardado en caché expiró o pertenece a otro phone_id -> regenerar
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

        // Si todos los candidatos dieron 132001, conservar la respuesta original del idioma primario ('es')
        if (!($http_code >= 200 && $http_code < 300) && intval($resp_data['error']['code'] ?? 0) === 132001 && $first_resp_data !== null) {
            $resp_data = $first_resp_data;
        }

        // Registrar también en notificacion_enviada para auditoría transaccional y Bandeja
        $contenido_log = 'Envío dinámico de plantilla: ' . $template_name . '. Params: ' . json_encode($params_arr, JSON_UNESCAPED_UNICODE);
        if ($texto_renderizado_log !== '') {
            $contenido_log .= ' | Texto: ' . $texto_renderizado_log;
        }
        $contenido_log_esc = mysqli_real_escape_string($con, $contenido_log);
        $fecha_hoy = date('Y-m-d');
        $hora_actual = date('H:i:s');
        $now_full = "$fecha_hoy $hora_actual";

        if ($http_code >= 200 && $http_code < 300 && isset($resp_data['messages'][0]['id'])) {
            $wamid = $resp_data['messages'][0]['id'];

            mysqli_query($con, "INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                                VALUES ('$template_name', '$fecha_hoy', '$hora_actual', '$phone_id', '$telefono_limpio', '$contenido_log_esc', 'ENVIADO')");

            // Asegurar cliente en clientes_contactos usando la columna real numero_whatsapp
            $id_cliente = intval($dest['id_cliente']);
            $tel_esc = mysqli_real_escape_string($con, $telefono_limpio);
            if ($id_cliente <= 0) {
                $res_c_find = mysqli_query($con, "SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = '$tel_esc' OR numero_whatsapp LIKE '%$tel_esc%' LIMIT 1");
                if ($res_c_find && mysqli_num_rows($res_c_find) > 0) {
                    $row_cli = mysqli_fetch_assoc($res_c_find);
                    $id_cliente = intval($row_cli['id']);
                    if ((empty($row_cli['nombre']) || $row_cli['nombre'] === 'Usuario' || $row_cli['nombre'] === 'Cliente') && $primer_nombre !== 'Estimado(a) cliente') {
                        $nom_esc = mysqli_real_escape_string($con, $nombre_cliente);
                        mysqli_query($con, "UPDATE clientes_contactos SET nombre = '$nom_esc', id_sede = COALESCE(id_sede, $id_sede), ultimo_contacto = '$now_full' WHERE id = $id_cliente");
                    } else {
                        mysqli_query($con, "UPDATE clientes_contactos SET ultimo_contacto = '$now_full' WHERE id = $id_cliente");
                    }
                } else {
                    $nom_insert = ($primer_nombre !== 'Estimado(a) cliente') ? "'" . mysqli_real_escape_string($con, $nombre_cliente) . "'" : "NULL";
                    mysqli_query($con, "INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre, fecha_registro, ultimo_contacto) 
                                        VALUES ($id_empresa, $id_sede, '$tel_esc', $nom_insert, '$now_full', '$now_full')");
                    $id_cliente = mysqli_insert_id($con);
                }
            } else {
                mysqli_query($con, "UPDATE clientes_contactos SET ultimo_contacto = '$now_full' WHERE id = $id_cliente");
            }

            // Asegurar conversación abierta/asociada a la línea de la sede para que aparezca de inmediato en la Bandeja de Mensajes y cuando el cliente responda el Bot IA tenga todo el contexto
            $id_conversacion = 0;
            if ($id_cliente > 0) {
                $res_conv = mysqli_query($con, "SELECT id FROM conversaciones WHERE id_cliente = $id_cliente AND id_linea = $id_linea AND estado NOT IN ('CERRADO', 'RESUELTO') ORDER BY id DESC LIMIT 1");
                if ($res_conv && mysqli_num_rows($res_conv) > 0) {
                    $id_conversacion = intval(mysqli_fetch_assoc($res_conv)['id']);
                } else {
                    mysqli_query($con, "INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos, fecha_inicio) VALUES ($id_linea, $id_cliente, 'ESPERA_ASIGNACION', 0, '$now_full')");
                    $id_conversacion = mysqli_insert_id($con);
                }
            }

            // Resolver ruta relativa de la imagen/flyer (si aplica) para que la Bandeja de Mensajes la muestre encima de la burbuja de difusión
            $rel_media_bandeja = null;
            if ($needs_image_header) {
                if (!empty($camp['header_imagen_path'])) {
                    $rel_media_bandeja = '../../' . ltrim(str_replace(['/starfi_crm/', '\\'], ['', '/'], $camp['header_imagen_path']), '/');
                } else {
                    $abs_img_used = resolveCampaignHeaderImagePath($con, $camp);
                    $base_root = str_replace('\\', '/', realpath(__DIR__ . '/../..'));
                    $abs_norm = $abs_img_used ? str_replace('\\', '/', $abs_img_used) : '';
                    if ($abs_norm && strpos($abs_norm, $base_root) === 0) {
                        $rel_media_bandeja = '../../' . ltrim(substr($abs_norm, strlen($base_root)), '/');
                    }
                }
            }

            // Guardar en mensajes_y_eventos en el formato oficial que leen Bandeja e IaConnector (Gemini)
            if ($id_conversacion > 0) {
                $wamid_esc = mysqli_real_escape_string($con, $wamid);
                $tipo_msg_bandeja = !empty($rel_media_bandeja) ? 'IMAGEN' : 'TEXTO';
                $url_arch_sql = !empty($rel_media_bandeja) ? ("'" . mysqli_real_escape_string($con, $rel_media_bandeja) . "'") : "NULL";
                $cat_meta_esc = mysqli_real_escape_string($con, $camp_cat);
                mysqli_query($con, "INSERT INTO mensajes_y_eventos 
                    (id_conversacion, id_mensaje_meta, tipo, origen, id_agente, contenido, url_archivo, estado_envio, categoria_meta, es_pagado, costo_meta_estimado, timestamp) 
                    VALUES ($id_conversacion, '$wamid_esc', '$tipo_msg_bandeja', 'API_TRANSACCIONAL', $id_usuario, '$contenido_log_esc', $url_arch_sql, 'ENVIADO', '$cat_meta_esc', 1, $costo_unit, '$now_full')");
            }

            // Actualizar fila en campanas_difusion_destinatarios
            $wamid_esc2 = mysqli_real_escape_string($con, $wamid);
            mysqli_query($con, "UPDATE campanas_difusion_destinatarios 
                                SET id_cliente = $id_cliente,
                                    id_conversacion = $id_conversacion,
                                    telefono = '$tel_esc',
                                    id_mensaje_meta = '$wamid_esc2',
                                    estado_envio = 'ENVIADO',
                                    costo_usd = $costo_unit,
                                    fecha_envio = NOW(),
                                    error_detalle = NULL
                                WHERE id = $id_destinatario");

            recalcularMetricasCampana($con, $id_campana);

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
                $err_full = "Meta Error (132001): La plantilla '$template_name' en idioma Español (es) aún está en revisión (PENDING) o no está aprobada en la cuenta WABA de esta sede. Meta suele aprobarla en pocos minutos.";
            } else {
                $err_msg = $err_details ? "$err_msg_base - $err_details" : $err_msg_base;
                $err_full = "Meta Error ($err_code): $err_msg";
            }
            $err_esc = mysqli_real_escape_string($con, $err_full);

            mysqli_query($con, "INSERT INTO notificacion_enviada (template, fecha, hora, telefono_emisor, telefono_receptor, contenido, status)
                                VALUES ('$template_name', '$fecha_hoy', '$hora_actual', '$phone_id', '$telefono_limpio', '$contenido_log_esc', 'ERROR')");

            mysqli_query($con, "UPDATE campanas_difusion_destinatarios 
                                SET estado_envio = 'FALLIDO',
                                    error_detalle = '$err_esc',
                                    fecha_envio = NOW()
                                WHERE id = $id_destinatario");

            recalcularMetricasCampana($con, $id_campana);

            echo json_encode([
                'status' => 'failed',
                'telefono' => $telefono_limpio,
                'message' => $err_full
            ]);
        }
        break;

    // =========================================================================
    // 6. FINALIZAR O ACTUALIZAR ESTADO DE CAMPAÑA AL TERMINAR LA COLA
    // =========================================================================
    case 'finish_campaign_batch':
        $id_campana = intval($_POST['id_campana'] ?? 0);
        if ($id_campana > 0) {
            // Breve pausa de 2 segundos para dar tiempo a que Meta envíe los webhooks asíncronos (delivered / failed 131049 / failed 131042)
            usleep(2000000);
            recalcularMetricasCampana($con, $id_campana);
            $res_p = mysqli_query($con, "SELECT pendientes, enviados, entregados, fallidos, piloto_automatico FROM campanas_difusion WHERE id = $id_campana LIMIT 1");
            $rp = ($res_p) ? mysqli_fetch_assoc($res_p) : null;
            $pend = $rp ? intval($rp['pendientes']) : 0;
            $piloto = $rp ? intval($rp['piloto_automatico']) : 1;
            $nuevo_estado = ($pend > 0) ? ($piloto === 1 ? 'PILOTO_AUTOMATICO' : 'EN_COLA_DIARIA') : 'COMPLETADA';
            mysqli_query($con, "UPDATE campanas_difusion SET estado = '$nuevo_estado', fecha_fin = NOW() WHERE id = $id_campana");

            // Consultar si algún destinatario de la tanda fue rechazado asíncronamente por Meta (ej. 131049 Frequenz Cap / 131042 Pago)
            $errores_async = [];
            $res_err = mysqli_query($con, "SELECT nombre, telefono, error_detalle FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'FALLIDO'");
            if ($res_err) {
                while ($re = mysqli_fetch_assoc($res_err)) {
                    $errores_async[] = $re;
                }
            }
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
        $res_c = mysqli_query($con, "SELECT * FROM campanas_difusion WHERE id = $id_campana LIMIT 1");
        if (!$res_c || mysqli_num_rows($res_c) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña no encontrada.']);
            exit;
        }
        $camp = mysqli_fetch_assoc($res_c);
        $id_sede = intval($camp['id_sede']);
        if (!$es_global && $id_sede_usuario > 0 && $id_sede !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado: solo puede gestionar campañas de su propia sede.']);
            exit;
        }

        // Si el operador actualizó la hora programada al aprobar, guardarla
        if (!empty($_POST['hora_programada'])) {
            $hp_raw = trim($_POST['hora_programada']);
            if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(:[0-5][0-9])?$/', $hp_raw)) {
                if (strlen($hp_raw) === 5) $hp_raw .= ':00';
                $hp_esc = mysqli_real_escape_string($con, $hp_raw);
                mysqli_query($con, "UPDATE campanas_difusion SET hora_programada = '$hp_esc' WHERE id = $id_campana");
                $camp['hora_programada'] = $hp_raw;
            }
        }

        // Si el operador seleccionó una nueva imagen/flyer antes de aprobar, actualizarla y limpiar header_media_id previo
        if (isset($_FILES['header_imagen']) && $_FILES['header_imagen']['error'] === UPLOAD_ERR_OK) {
            $tmp_img = $_FILES['header_imagen']['tmp_name'];
            $mime_img = @mime_content_type($tmp_img) ?: '';
            $dir_upload = __DIR__ . '/../../uploads/campanas';
            if (!is_dir($dir_upload)) {
                @mkdir($dir_upload, 0775, true);
            }
            $new_rel_path = null;
            if ($mime_img === 'image/jpeg' || $mime_img === 'image/jpg') {
                $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.jpg';
                if (@move_uploaded_file($tmp_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img)) {
                    $new_rel_path = 'uploads/campanas/' . $fname_img;
                }
            } elseif ($mime_img === 'image/png') {
                $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.png';
                if (@move_uploaded_file($tmp_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img)) {
                    $new_rel_path = 'uploads/campanas/' . $fname_img;
                }
            } elseif (in_array($mime_img, ['image/webp', 'image/gif', 'image/bmp'], true) && function_exists('imagecreatefromstring')) {
                $raw_bytes = @file_get_contents($tmp_img);
                $gd_img = $raw_bytes ? @imagecreatefromstring($raw_bytes) : false;
                if ($gd_img !== false) {
                    $fname_img = 'camp_' . $id_sede . '_' . time() . '_' . mt_rand(100, 999) . '.png';
                    if (@imagepng($gd_img, $dir_upload . DIRECTORY_SEPARATOR . $fname_img, 8)) {
                        $new_rel_path = 'uploads/campanas/' . $fname_img;
                    }
                    @imagedestroy($gd_img);
                }
            }
            if ($new_rel_path) {
                $rel_esc = mysqli_real_escape_string($con, $new_rel_path);
                mysqli_query($con, "UPDATE campanas_difusion SET header_imagen_path = '$rel_esc', header_media_id = NULL WHERE id = $id_campana");
                $camp['header_imagen_path'] = $new_rel_path;
                $camp['header_media_id'] = null;
            }
        }

        // Si estaba PENDIENTE_APROBACION, marcarla aprobada al iniciar
        if ($camp['estado'] === 'PENDIENTE_APROBACION' || empty($camp['aprobado_por'])) {
            mysqli_query($con, "UPDATE campanas_difusion SET aprobado_por = $id_usuario, fecha_aprobacion = NOW(), estado = 'APROBADA_EN_PROCESO' WHERE id = $id_campana");
        }

        // Verificar cuota diaria disponible hoy para la sede y para la campaña
        $res_q = mysqli_query($con, "SELECT limite_diario_mensajes, cadencia_ms FROM campanas_cuotas_sede WHERE id_sede = $id_sede LIMIT 1");
        $limite_diario_sede = 250;
        $cadencia_ms = 1000;
        if ($res_q && mysqli_num_rows($res_q) > 0) {
            $rq = mysqli_fetch_assoc($res_q);
            $limite_diario_sede = max(1, intval($rq['limite_diario_mensajes']));
            $cadencia_ms = max(250, intval($rq['cadencia_ms']));
        }
        $cuota_campana = max(1, intval($camp['cuota_diaria_programada'] ?: $limite_diario_sede));
        if ($cuota_campana > $limite_diario_sede) $limite_diario_sede = $cuota_campana;
        $tope_efectivo = min($limite_diario_sede, $cuota_campana);

        $res_hoy = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $id_sede AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy_sede = ($res_hoy && $rh = mysqli_fetch_assoc($res_hoy)) ? intval($rh['c']) : 0;

        $res_hoy_camp = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy_camp = ($res_hoy_camp && $rhc = mysqli_fetch_assoc($res_hoy_camp)) ? intval($rhc['c']) : 0;

        $disponibles_hoy = min(max(0, $limite_diario_sede - $enviados_hoy_sede), max(0, $tope_efectivo - $enviados_hoy_camp));

        if ($disponibles_hoy <= 0) {
            echo json_encode([
                'status' => 'error',
                'message' => "La campaña o sede ya completó su tanda diaria de hoy ($tope_efectivo mensajes/día). Queda en Piloto Automático para continuar en la siguiente jornada."
            ]);
            exit;
        }

        $res_pend = mysqli_query($con, "SELECT id AS id_destinatario, id_cliente, nombre, telefono, numero_tanda, fecha_programada FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' ORDER BY numero_tanda ASC, id ASC LIMIT $disponibles_hoy");
        $cola = [];
        if ($res_pend) {
            while ($r = mysqli_fetch_assoc($res_pend)) {
                $cola[] = $r;
            }
        }

        // Si no había PENDIENTE pero el usuario pidió reintentar los FALLIDO (o la campaña solo tenía fallidos por el error previo de imagen), reencolar los FALLIDO
        if (empty($cola)) {
            $res_fal = mysqli_query($con, "SELECT id FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'FALLIDO' LIMIT $disponibles_hoy");
            if ($res_fal && mysqli_num_rows($res_fal) > 0) {
                mysqli_query($con, "UPDATE campanas_difusion_destinatarios SET estado_envio = 'PENDIENTE', error_detalle = NULL WHERE id_campana = $id_campana AND estado_envio = 'FALLIDO'");
                recalcularMetricasCampana($con, $id_campana);
                $res_pend2 = mysqli_query($con, "SELECT id AS id_destinatario, id_cliente, nombre, telefono, numero_tanda, fecha_programada FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' ORDER BY numero_tanda ASC, id ASC LIMIT $disponibles_hoy");
                if ($res_pend2) {
                    while ($r2 = mysqli_fetch_assoc($res_pend2)) {
                        $cola[] = $r2;
                    }
                }
            }
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
    // 8. DETALLE 360° DE DESTINATARIOS DE UNA CAMPAÑA (LEÍDOS, RESPONDIDOS, COSTOS)
    // =========================================================================
    case 'get_campaign_recipients':
        $id_campana = intval($_GET['id_campana'] ?? 0);
        if ($id_campana <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Campaña inválida.']);
            exit;
        }

        recalcularMetricasCampana($con, $id_campana);

        $res_c = mysqli_query($con, "SELECT c.*, s.nombre_sede, l.numero_telefono AS linea_telefono
                                     FROM campanas_difusion c
                                     LEFT JOIN sedes s ON s.id = c.id_sede
                                     LEFT JOIN lineas_whatsapp l ON l.id_sede = c.id_sede AND l.estado = 'ACTIVO'
                                     WHERE c.id = $id_campana LIMIT 1");
        $campana = ($res_c && mysqli_num_rows($res_c) > 0) ? mysqli_fetch_assoc($res_c) : null;
        if ($campana && !$es_global && $id_sede_usuario > 0 && intval($campana['id_sede']) !== $id_sede_usuario) {
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado a campaña de otra sede.']);
            exit;
        }

        $res_d = mysqli_query($con, "SELECT d.*,
                                            COALESCE(NULLIF(s_cli.nombre_sede, ''), NULLIF(s_camp.nombre_sede, ''), 'General') AS nombre_sede_cliente,
                                            COALESCE(NULLIF(c_cli.id_sede, 0), d.id_sede) AS id_sede_cliente
                                     FROM campanas_difusion_destinatarios d
                                     LEFT JOIN clientes_contactos c_cli ON c_cli.id = d.id_cliente
                                     LEFT JOIN sedes s_cli ON s_cli.id = c_cli.id_sede
                                     LEFT JOIN sedes s_camp ON s_camp.id = d.id_sede
                                     WHERE d.id_campana = $id_campana
                                     ORDER BY d.respondido DESC, d.fecha_lectura DESC, d.id ASC");
        $destinatarios = [];
        if ($res_d) {
            while ($row = mysqli_fetch_assoc($res_d)) {
                $destinatarios[] = $row;
            }
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
?>
