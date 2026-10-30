<?php
// modules/campanas/back_campanas.php
// Ecosistema Independiente de Campañas de Difusión Masiva | STARFI CRM
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
$es_global = ($rol_usuario === 'MASTER' || $rol_usuario === 'ADMIN' || $id_sede_usuario === 0);

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
        costo_real_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        total_destinatarios INT NOT NULL DEFAULT 0,
        pendientes INT NOT NULL DEFAULT 0,
        enviados INT NOT NULL DEFAULT 0,
        entregados INT NOT NULL DEFAULT 0,
        leidos INT NOT NULL DEFAULT 0,
        respondidos INT NOT NULL DEFAULT 0,
        fallidos INT NOT NULL DEFAULT 0,
        estado VARCHAR(30) NOT NULL DEFAULT 'EN_PROCESO',
        fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        fecha_fin DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Asegurar columnas nuevas si la tabla ya existía de una versión previa
    $cols_needed = [
        'costo_unitario_usd' => "ALTER TABLE campanas_difusion ADD COLUMN costo_unitario_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0700 AFTER param_eslogan",
        'presupuesto_max_usd' => "ALTER TABLE campanas_difusion ADD COLUMN presupuesto_max_usd DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER costo_unitario_usd",
        'costo_real_usd' => "ALTER TABLE campanas_difusion ADD COLUMN costo_real_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000 AFTER presupuesto_max_usd",
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

    // 2. Tabla de destinatarios individuales y trazabilidad 360 (Enviado / Entregado / Leído / Respondido)
    mysqli_query($con, "CREATE TABLE IF NOT EXISTS campanas_difusion_destinatarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_campana INT NOT NULL,
        id_sede INT NOT NULL DEFAULT 0,
        id_cliente INT NOT NULL DEFAULT 0,
        id_conversacion INT NOT NULL DEFAULT 0,
        nombre VARCHAR(150) NOT NULL DEFAULT 'Cliente',
        telefono VARCHAR(40) NOT NULL,
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

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // =========================================================================
    // 1. OBTENER ECOSISTEMA COMPLETO (SEDES, LÍNEAS, CUOTA DIARIA, CONTACTOS, CAMPAÑAS, KPIs)
    // =========================================================================
    case 'get_ecosystem_data':
        $filtro_sede = isset($_REQUEST['id_sede']) ? intval($_REQUEST['id_sede']) : 0;
        if (!$es_global && $id_sede_usuario > 0) {
            $filtro_sede = $id_sede_usuario;
        }

        // 1.1 Cargar sedes y su línea de WhatsApp asignada + configuración de flujo diario
        $where_sede = (!$es_global && $id_sede_usuario > 0) ? "WHERE s.id = $id_sede_usuario" : "";
        $sql_sedes = "SELECT s.id, s.nombre_sede, s.ciudad, s.bot_activo,
                             l.id AS id_linea, l.numero_telefono, l.meta_app_id, l.waba_id, l.meta_token, l.estado AS estado_linea,
                             IFNULL(q.limite_diario_mensajes, 250) AS limite_diario_mensajes,
                             IFNULL(q.cadencia_ms, 1000) AS cadencia_ms,
                             IFNULL(q.costo_marketing_usd, 0.0700) AS costo_marketing_usd,
                             IFNULL(q.costo_utility_usd, 0.0150) AS costo_utility_usd,
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
                      $where_sede
                      ORDER BY s.nombre_sede ASC";
        $res_sedes = mysqli_query($con, $sql_sedes);
        $sedes = [];
        if ($res_sedes) {
            while ($row = mysqli_fetch_assoc($res_sedes)) {
                $lim = max(1, intval($row['limite_diario_mensajes']));
                $hoy = intval($row['enviados_hoy']);
                $disp = max(0, $lim - $hoy);
                $pct = min(100, round(($hoy / $lim) * 100, 1));
                $sedes[] = [
                    'id' => intval($row['id']),
                    'nombre_sede' => $row['nombre_sede'],
                    'ciudad' => $row['ciudad'] ?? '',
                    'bot_activo' => intval($row['bot_activo'] ?? 0) === 1,
                    'id_linea' => intval($row['id_linea'] ?? 0),
                    'numero_telefono' => $row['numero_telefono'] ?? 'Sin número asignado',
                    'tiene_linea_activa' => (!empty($row['id_linea']) && !empty($row['meta_app_id']) && !empty($row['meta_token'])),
                    'tiene_waba' => !empty($row['waba_id']),
                    'limite_diario_mensajes' => $lim,
                    'cadencia_ms' => intval($row['cadencia_ms']),
                    'costo_marketing_usd' => floatval($row['costo_marketing_usd']),
                    'costo_utility_usd' => floatval($row['costo_utility_usd']),
                    'enviados_hoy' => $hoy,
                    'disponibles_hoy' => $disp,
                    'porcentaje_cuota_hoy' => $pct,
                    'gasto_hoy_usd' => round(floatval($row['gasto_hoy_usd']), 4)
                ];
            }
        }

        // Si no se envió filtro_sede pero hay sedes con línea activa, preseleccionar la primera con línea activa
        if ($filtro_sede === 0 && !empty($sedes)) {
            foreach ($sedes as $s_item) {
                if ($s_item['tiene_linea_activa']) {
                    $filtro_sede = $s_item['id'];
                    break;
                }
            }
            if ($filtro_sede === 0) {
                $filtro_sede = $sedes[0]['id'];
            }
        }

        // 1.2 Cargar etiquetas únicas del Directorio para segmentación
        $res_tags = mysqli_query($con, "SELECT DISTINCT etiqueta FROM cliente_etiquetas ORDER BY etiqueta ASC");
        $etiquetas = [];
        if ($res_tags) {
            while ($rt = mysqli_fetch_assoc($res_tags)) {
                if (!empty($rt['etiqueta'])) {
                    $etiquetas[] = $rt['etiqueta'];
                }
            }
        }

        // 1.3 Cargar clientes del Directorio (con sus sedes asociadas y etiquetas)
        $sql_clientes = "SELECT c.id, c.telefono, c.nombre, c.notas_internas, c.fecha_registro,
                                GROUP_CONCAT(DISTINCT ce.etiqueta SEPARATOR ',') AS etiquetas_str,
                                GROUP_CONCAT(DISTINCT lw.id_sede SEPARATOR ',') AS sedes_ids,
                                MAX(conv.ultima_interaccion) AS ultima_interaccion
                         FROM clientes_contactos c
                         LEFT JOIN cliente_etiquetas ce ON ce.id_cliente = c.id
                         LEFT JOIN conversaciones conv ON conv.id_cliente = c.id
                         LEFT JOIN lineas_whatsapp lw ON lw.id = conv.id_linea
                         GROUP BY c.id
                         ORDER BY c.id DESC";
        $res_cli = mysqli_query($con, $sql_clientes);
        $contactos = [];
        if ($res_cli) {
            while ($rc = mysqli_fetch_assoc($res_cli)) {
                $tags_arr = !empty($rc['etiquetas_str']) ? explode(',', $rc['etiquetas_str']) : [];
                $sedes_arr = !empty($rc['sedes_ids']) ? array_map('intval', explode(',', $rc['sedes_ids'])) : [];
                $contactos[] = [
                    'id' => intval($rc['id']),
                    'nombre' => !empty($rc['nombre']) ? $rc['nombre'] : 'Cliente',
                    'telefono' => $rc['telefono'],
                    'etiquetas' => $tags_arr,
                    'sedes_ids' => $sedes_arr,
                    'ultima_interaccion' => $rc['ultima_interaccion']
                ];
            }
        }

        // 1.4 Cargar Campañas de Difusión y KPIs Globales / por Sede
        $where_camp = ($filtro_sede > 0) ? "WHERE c.id_sede = $filtro_sede" : "";
        $sql_camp = "SELECT c.*, s.nombre_sede, l.numero_telefono AS linea_telefono
                     FROM campanas_difusion c
                     LEFT JOIN sedes s ON s.id = c.id_sede
                     LEFT JOIN lineas_whatsapp l ON l.id_sede = c.id_sede AND l.estado = 'ACTIVO'
                     $where_camp
                     ORDER BY c.id DESC
                     LIMIT 50";
        $res_camp = mysqli_query($con, $sql_camp);
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
        $limite_diario = max(10, min(100000, intval($_POST['limite_diario_mensajes'] ?? 250)));
        $cadencia_ms = max(250, min(15000, intval($_POST['cadencia_ms'] ?? 1000)));
        $costo_mkt = max(0, floatval($_POST['costo_marketing_usd'] ?? 0.0700));
        $costo_util = max(0, floatval($_POST['costo_utility_usd'] ?? 0.0150));

        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Seleccione una sede válida.']);
            exit;
        }

        $stmt = $con->prepare("INSERT INTO campanas_cuotas_sede (id_sede, limite_diario_mensajes, cadencia_ms, costo_marketing_usd, costo_utility_usd)
                               VALUES (?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE
                               limite_diario_mensajes = VALUES(limite_diario_mensajes),
                               cadencia_ms = VALUES(cadencia_ms),
                               costo_marketing_usd = VALUES(costo_marketing_usd),
                               costo_utility_usd = VALUES(costo_utility_usd)");
        $stmt->bind_param("iiidd", $id_sede, $limite_diario, $cadencia_ms, $costo_mkt, $costo_util);
        if ($stmt->execute()) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Control de Flujo Diario y Tarifas Meta actualizados para la sede.'
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
        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Sede inválida.']);
            exit;
        }

        $res_l = mysqli_query($con, "SELECT waba_id, meta_token, numero_telefono FROM lineas_whatsapp WHERE id_sede = $id_sede AND estado = 'ACTIVO' LIMIT 1");
        $templates = [];
        $tiene_starfi_directa = false;

        if ($res_l && mysqli_num_rows($res_l) > 0) {
            $lin = mysqli_fetch_assoc($res_l);
            $waba_id = trim($lin['waba_id'] ?? '');
            $token = trim($lin['meta_token'] ?? '');

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
                            if ($st === 'APPROVED' || $st === 'ACTIVE') {
                                $body_txt = '';
                                foreach (($tpl['components'] ?? []) as $comp) {
                                    if (($comp['type'] ?? '') === 'BODY') {
                                        $body_txt = $comp['text'] ?? '';
                                    }
                                }
                                $templates[] = [
                                    'name' => $tpl['name'],
                                    'language' => $tpl['language'] ?? 'es',
                                    'category' => strtoupper($tpl['category'] ?? 'MARKETING'),
                                    'status' => $st,
                                    'body' => $body_txt
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

        // Asegurar siempre la plantilla oficial starfi_difusion_directa en la lista
        if (!$tiene_starfi_directa) {
            array_unshift($templates, [
                'name' => 'starfi_difusion_directa',
                'language' => 'es',
                'category' => 'MARKETING',
                'status' => 'APPROVED',
                'body' => "👋¡Hola {{1}}!\nHoy queremos {{2}}.\nRecuerda que en {{3}}.\n📲 *Responde a este mensaje para obtener más información*👇"
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
        $nombre_campana = trim($_POST['nombre_campana'] ?? '');
        $template_name = trim($_POST['template_name'] ?? 'starfi_difusion_directa');
        $param_cuerpo = trim($_POST['param_cuerpo'] ?? '');
        $param_eslogan = trim($_POST['param_eslogan'] ?? '');
        $costo_unitario_usd = max(0, floatval($_POST['costo_unitario_usd'] ?? 0.0700));
        $presupuesto_max_usd = max(0, floatval($_POST['presupuesto_max_usd'] ?? 0.00));
        $destinatarios_json = $_POST['destinatarios'] ?? '[]';
        $destinatarios = json_decode($destinatarios_json, true);

        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar la Sede emisora para la campaña.']);
            exit;
        }
        if (empty($nombre_campana)) {
            $nombre_campana = 'Campaña ' . date('d/m/Y H:i');
        }
        if (empty($param_cuerpo) || empty($param_eslogan)) {
            echo json_encode(['status' => 'error', 'message' => 'Complete las variables del mensaje ({{2}} y {{3}}).']);
            exit;
        }
        if (!is_array($destinatarios) || count($destinatarios) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'Seleccione al menos un destinatario para la campaña.']);
            exit;
        }

        // Verificar que la sede tenga línea activa
        $res_lin = mysqli_query($con, "SELECT id, numero_telefono, meta_app_id, meta_token FROM lineas_whatsapp WHERE id_sede = $id_sede AND estado = 'ACTIVO' LIMIT 1");
        if (!$res_lin || mysqli_num_rows($res_lin) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'La sede seleccionada no posee una línea oficial de WhatsApp activa.']);
            exit;
        }
        $linea = mysqli_fetch_assoc($res_lin);
        if (empty($linea['meta_app_id']) || empty($linea['meta_token'])) {
            echo json_encode(['status' => 'error', 'message' => 'La línea de WhatsApp de esta sede no tiene configurado el Phone Number ID o Token de Meta.']);
            exit;
        }

        // Obtener cuota diaria de la sede
        $res_q = mysqli_query($con, "SELECT limite_diario_mensajes, cadencia_ms FROM campanas_cuotas_sede WHERE id_sede = $id_sede LIMIT 1");
        $limite_diario = 250;
        $cadencia_ms = 1000;
        if ($res_q && mysqli_num_rows($res_q) > 0) {
            $rq = mysqli_fetch_assoc($res_q);
            $limite_diario = max(1, intval($rq['limite_diario_mensajes']));
            $cadencia_ms = max(250, intval($rq['cadencia_ms']));
        }

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

        // Limpiar y deduplicar destinatarios por teléfono
        $dedup = [];
        foreach ($destinatarios as $d) {
            $tel_raw = trim($d['telefono'] ?? '');
            $tel_digits = preg_replace('/[^0-9]/', '', $tel_raw);
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

        // Insertar campaña
        $stmt = $con->prepare("INSERT INTO campanas_difusion 
            (id_sede, id_usuario, nombre_campana, template_name, param_cuerpo, param_eslogan, costo_unitario_usd, presupuesto_max_usd, total_destinatarios, pendientes, estado) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'EN_PROCESO')");
        $stmt->bind_param(
            "iissssddii",
            $id_sede,
            $id_usuario,
            $nombre_campana,
            $template_name,
            $param_cuerpo,
            $param_eslogan,
            $costo_unitario_usd,
            $presupuesto_max_usd,
            $total_dest,
            $total_dest
        );
        if (!$stmt->execute()) {
            echo json_encode(['status' => 'error', 'message' => 'Error al registrar la campaña en base de datos.']);
            exit;
        }
        $id_campana = $stmt->insert_id;
        $stmt->close();

        // Insertar todos los destinatarios en la cola como PENDIENTE
        $stmt_d = $con->prepare("INSERT INTO campanas_difusion_destinatarios (id_campana, id_sede, id_cliente, nombre, telefono, estado_envio) VALUES (?, ?, ?, ?, ?, 'PENDIENTE')");
        $cola_items = [];
        foreach ($lista_limpia as $item) {
            $id_cli = $item['id_cliente'];
            $nom_cli = $item['nombre'];
            $tel_cli = $item['telefono'];
            $stmt_d->bind_param("iiiss", $id_campana, $id_sede, $id_cli, $nom_cli, $tel_cli);
            if ($stmt_d->execute()) {
                $cola_items[] = [
                    'id_destinatario' => $stmt_d->insert_id,
                    'id_cliente' => $id_cli,
                    'nombre' => $nom_cli,
                    'telefono' => $tel_cli
                ];
            }
        }
        $stmt_d->close();

        // Determinar cuántos se pueden despachar en esta tanda según el Control de Flujo Diario
        $despachar_ahora = min(count($cola_items), $disponibles_hoy);
        $en_cola_para_manana = max(0, count($cola_items) - $despachar_ahora);

        echo json_encode([
            'status' => 'success',
            'id_campana' => $id_campana,
            'id_sede' => $id_sede,
            'linea_telefono' => $linea['numero_telefono'],
            'cadencia_ms' => $cadencia_ms,
            'limite_diario' => $limite_diario,
            'enviados_hoy' => $enviados_hoy,
            'disponibles_hoy' => $disponibles_hoy,
            'total_encolados' => count($cola_items),
            'despachar_ahora' => $despachar_ahora,
            'diferidos_por_flujo_diario' => $en_cola_para_manana,
            'recortados_por_presupuesto' => $recortados_por_presupuesto,
            'cola_envio' => array_slice($cola_items, 0, $despachar_ahora)
        ]);
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

        // Verificar Control de Flujo Diario antes de disparar
        $res_q = mysqli_query($con, "SELECT limite_diario_mensajes FROM campanas_cuotas_sede WHERE id_sede = $id_sede LIMIT 1");
        $limite_diario = ($res_q && $rq = mysqli_fetch_assoc($res_q)) ? max(1, intval($rq['limite_diario_mensajes'])) : 250;

        $res_hoy = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $id_sede AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy = ($res_hoy && $rh = mysqli_fetch_assoc($res_hoy)) ? intval($rh['c']) : 0;

        if ($enviados_hoy >= $limite_diario) {
            mysqli_query($con, "UPDATE campanas_difusion SET estado = 'PAUSADA_CUOTA_DIARIA' WHERE id = $id_campana");
            echo json_encode([
                'status' => 'quota_reached',
                'message' => "Se alcanzó el límite diario de protección Anti-Bloqueo de la sede ($limite_diario mensajes/día). Los destinatarios restantes quedan en estado PENDIENTE para reanudar mañana."
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
        $res_lin = mysqli_query($con, "SELECT id, meta_app_id, meta_token, numero_telefono FROM lineas_whatsapp WHERE id_sede = $id_sede AND estado = 'ACTIVO' LIMIT 1");
        if (!$res_lin || mysqli_num_rows($res_lin) === 0) {
            echo json_encode(['status' => 'error', 'message' => 'No hay línea activa en la sede.']);
            exit;
        }
        $linea = mysqli_fetch_assoc($res_lin);
        $id_linea = intval($linea['id']);
        $phone_id = trim($linea['meta_app_id']);
        $token = trim($linea['meta_token']);

        // Formatear teléfono destino (normalizar 04xx Venezuela a 584xx)
        $telefono_limpio = preg_replace('/[^0-9]/', '', $dest['telefono']);
        if (strlen($telefono_limpio) === 11 && strpos($telefono_limpio, '04') === 0) {
            $telefono_limpio = '58' . substr($telefono_limpio, 1);
        } elseif (strlen($telefono_limpio) === 10 && strpos($telefono_limpio, '4') === 0) {
            $telefono_limpio = '58' . $telefono_limpio;
        }

        $nombre_cliente = trim($dest['nombre'] ?: 'Cliente');
        // Extraer primer nombre amigable para {{1}}
        $primer_nombre = explode(' ', $nombre_cliente)[0];
        if (empty($primer_nombre) || strlen($primer_nombre) < 2) {
            $primer_nombre = $nombre_cliente;
        }

        $template_name = $camp['template_name'] ?: 'starfi_difusion_directa';
        $param_2 = $camp['param_cuerpo'];
        $param_3 = $camp['param_eslogan'];

        // Armar payload oficial de Meta WhatsApp Cloud API v23.0
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $telefono_limpio,
            'type' => 'template',
            'template' => [
                'name' => $template_name,
                'language' => ['code' => $language_code ?: 'es'],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $primer_nombre],
                            ['type' => 'text', 'text' => $param_2],
                            ['type' => 'text', 'text' => $param_3]
                        ]
                    ]
                ]
            ]
        ];

        $url = "https://graph.facebook.com/v23.0/{$phone_id}/messages";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        $resp_data = json_decode($response, true);

        if ($http_code >= 200 && $http_code < 300 && isset($resp_data['messages'][0]['id'])) {
            $wamid = $resp_data['messages'][0]['id'];

            // Asegurar cliente en clientes_contactos
            $id_cliente = intval($dest['id_cliente']);
            if ($id_cliente <= 0) {
                $tel_esc = mysqli_real_escape_string($con, $telefono_limpio);
                $res_c_find = mysqli_query($con, "SELECT id FROM clientes_contactos WHERE telefono LIKE '%$tel_esc%' LIMIT 1");
                if ($res_c_find && mysqli_num_rows($res_c_find) > 0) {
                    $id_cliente = intval(mysqli_fetch_assoc($res_c_find)['id']);
                } else {
                    $nom_esc = mysqli_real_escape_string($con, $nombre_cliente);
                    mysqli_query($con, "INSERT INTO clientes_contactos (telefono, nombre) VALUES ('$tel_esc', '$nom_esc')");
                    $id_cliente = mysqli_insert_id($con);
                }
            }

            // Asegurar conversación abierta/asociada a la línea de la sede para que cuando el cliente responda el Bot IA tenga todo el contexto
            $id_conversacion = 0;
            if ($id_cliente > 0) {
                $res_conv = mysqli_query($con, "SELECT id FROM conversaciones WHERE id_cliente = $id_cliente AND id_linea = $id_linea AND estado != 'CERRADA' ORDER BY id DESC LIMIT 1");
                if ($res_conv && mysqli_num_rows($res_conv) > 0) {
                    $id_conversacion = intval(mysqli_fetch_assoc($res_conv)['id']);
                    mysqli_query($con, "UPDATE conversaciones SET ultima_interaccion = NOW() WHERE id = $id_conversacion");
                } else {
                    mysqli_query($con, "INSERT INTO conversaciones (id_linea, id_cliente, estado, mensajes_no_leidos) VALUES ($id_linea, $id_cliente, 'ESPERA_ASIGNACION', 0)");
                    $id_conversacion = mysqli_insert_id($con);
                }
            }

            // Guardar en mensajes_y_eventos en formato [TEMPLATE:starfi_difusion_directa:...] para que Bandeja e IaConnector (Gemini) lo lean completo
            if ($id_conversacion > 0) {
                $contenido_tag = "[TEMPLATE:{$template_name}:{$primer_nombre}|{$param_2}|{$param_3}]";
                $contenido_esc = mysqli_real_escape_string($con, $contenido_tag);
                $wamid_esc = mysqli_real_escape_string($con, $wamid);
                mysqli_query($con, "INSERT INTO mensajes_y_eventos 
                    (id_conversacion, id_mensaje_meta, tipo, origen, id_agente_autor, contenido, estado_envio, categoria_meta, es_pagado, costo_meta_estimado) 
                    VALUES ($id_conversacion, '$wamid_esc', 'TEXTO', 'AGENTE', $id_usuario, '$contenido_esc', 'ENVIADO', 'MARKETING', 1, $costo_unit)");
            }

            // Actualizar fila en campanas_difusion_destinatarios
            $wamid_esc2 = mysqli_real_escape_string($con, $wamid);
            mysqli_query($con, "UPDATE campanas_difusion_destinatarios 
                                SET id_cliente = $id_cliente,
                                    id_conversacion = $id_conversacion,
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
            $err_msg = $curl_err ?: ($resp_data['error']['message'] ?? 'Error desconocido devuelto por Meta API');
            $err_code = $resp_data['error']['code'] ?? $http_code;
            $err_full = "Meta Error ($err_code): $err_msg";
            $err_esc = mysqli_real_escape_string($con, $err_full);

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
            recalcularMetricasCampana($con, $id_campana);
            $res_p = mysqli_query($con, "SELECT pendientes FROM campanas_difusion WHERE id = $id_campana LIMIT 1");
            $pend = ($res_p && $rp = mysqli_fetch_assoc($res_p)) ? intval($rp['pendientes']) : 0;
            $nuevo_estado = ($pend > 0) ? 'EN_COLA_DIARIA' : 'COMPLETADA';
            mysqli_query($con, "UPDATE campanas_difusion SET estado = '$nuevo_estado', fecha_fin = NOW() WHERE id = $id_campana");
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

        // Verificar cuota diaria disponible hoy para la sede
        $res_q = mysqli_query($con, "SELECT limite_diario_mensajes, cadencia_ms FROM campanas_cuotas_sede WHERE id_sede = $id_sede LIMIT 1");
        $limite_diario = 250;
        $cadencia_ms = 1000;
        if ($res_q && mysqli_num_rows($res_q) > 0) {
            $rq = mysqli_fetch_assoc($res_q);
            $limite_diario = max(1, intval($rq['limite_diario_mensajes']));
            $cadencia_ms = max(250, intval($rq['cadencia_ms']));
        }
        $res_hoy = mysqli_query($con, "SELECT COUNT(*) AS c FROM campanas_difusion_destinatarios WHERE id_sede = $id_sede AND estado_envio IN ('ENVIADO','ENTREGADO','LEIDO','RESPONDIDO') AND DATE(fecha_envio) = CURDATE()");
        $enviados_hoy = ($res_hoy && $rh = mysqli_fetch_assoc($res_hoy)) ? intval($rh['c']) : 0;
        $disponibles_hoy = max(0, $limite_diario - $enviados_hoy);

        if ($disponibles_hoy <= 0) {
            echo json_encode([
                'status' => 'error',
                'message' => "La sede ya alcanzó hoy su tope de flujo diario ($limite_diario mensajes/día). Puede reanudar mañana o ampliar el límite diario de la sede."
            ]);
            exit;
        }

        $res_pend = mysqli_query($con, "SELECT id AS id_destinatario, id_cliente, nombre, telefono FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana AND estado_envio = 'PENDIENTE' ORDER BY id ASC LIMIT $disponibles_hoy");
        $cola = [];
        if ($res_pend) {
            while ($r = mysqli_fetch_assoc($res_pend)) {
                $cola[] = $r;
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

        $res_d = mysqli_query($con, "SELECT * FROM campanas_difusion_destinatarios WHERE id_campana = $id_campana ORDER BY respondido DESC, fecha_lectura DESC, id ASC");
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
