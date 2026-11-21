<?php
// modules/dashboard/back_whatsapp_analytics.php
// Controlador de Métricas Meta WhatsApp API y Auditoría Híbrida — STARFI CRM (QA-STD-GLOBAL-2.1)
// 100% mysqli_prepare(), CURLOPT_TIMEOUT <= 8s, cero fugas SQL y bitácora starfi_log_exception().

@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
require_once __DIR__ . '/../../config/database.php';

requireAuth();
requirePermission('whatsapp_analytics');
header('Content-Type: application/json; charset=utf-8');

$agente = getAgenteInfo();
$es_master = (($agente['rol'] ?? '') === 'MASTER' || intval($agente['id'] ?? 0) === 1);
$id_sede_agente = intval($agente['id_sede'] ?? 0);

$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'get_analytics') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    if (!$es_master) {
        $id_sede = $id_sede_agente;
    }

    $fecha_desde_in = trim((string)($_POST['fecha_desde'] ?? date('Y-m-01')));
    $fecha_hasta_in = trim((string)($_POST['fecha_hasta'] ?? date('Y-m-t')));
    $fecha_desde = strtotime($fecha_desde_in) ? date('Y-m-d', strtotime($fecha_desde_in)) : date('Y-m-01');
    $fecha_hasta = strtotime($fecha_hasta_in) ? date('Y-m-d', strtotime($fecha_hasta_in)) : date('Y-m-t');

    $hoy_ymd = date('Y-m-d');
    $fecha_hasta_meta = ($fecha_hasta > $hoy_ymd) ? $hoy_ymd : $fecha_hasta;
    $start_ts = strtotime($fecha_desde . " 00:00:00");
    $end_ts   = strtotime($fecha_hasta_meta . " 23:59:59");
    if ($start_ts > $end_ts) {
        $start_ts = strtotime('-7 days', $end_ts);
    }

    $id_plantilla = preg_replace('/[^0-9A-Za-z_-]/', '', trim((string)($_POST['id_plantilla'] ?? '')));

    $session_cache_snapshot = $_SESSION ?? [];
    session_write_close();

    $con = getDbConnection();
    if (!$con) {
        echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
        exit;
    }

    $rowLinea = null;
    if ($id_sede > 0) {
        $stmtLin = $con->prepare("SELECT id_negocio, meta_token FROM lineas_whatsapp WHERE id_sede = ? AND id_negocio IS NOT NULL AND id_negocio != '' ORDER BY (estado = 'ACTIVO') DESC LIMIT 1");
        if ($stmtLin) {
            $stmtLin->bind_param("i", $id_sede);
            $stmtLin->execute();
            $resLin = $stmtLin->get_result();
            $rowLinea = ($resLin && $resLin->num_rows > 0) ? $resLin->fetch_assoc() : null;
            $stmtLin->close();
        }
    } else {
        $stmtLin = $con->prepare("SELECT id_negocio, meta_token FROM lineas_whatsapp WHERE id_negocio IS NOT NULL AND id_negocio != '' ORDER BY (estado = 'ACTIVO') DESC LIMIT 1");
        if ($stmtLin) {
            $stmtLin->execute();
            $resLin = $stmtLin->get_result();
            $rowLinea = ($resLin && $resLin->num_rows > 0) ? $resLin->fetch_assoc() : null;
            $stmtLin->close();
        }
    }

    if (!$rowLinea) {
        echo json_encode(['status' => 'error', 'message' => 'No hay líneas de WhatsApp configuradas']);
        exit;
    }

    $waba_id = trim((string)($rowLinea['id_negocio'] ?? ''));
    if ($waba_id === '') {
        echo json_encode(['status' => 'error', 'message' => 'WABA ID no configurado']);
        exit;
    }

    $tokenToUse = (defined('META_GLOBAL_TOKEN') && META_GLOBAL_TOKEN !== '') ? META_GLOBAL_TOKEN : trim((string)($rowLinea['meta_token'] ?? ''));
    $fecha_hasta_mas_uno = date('Y-m-d', strtotime($fecha_hasta . ' +1 day'));

    $fields = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY),conversation_analytics.start($start_ts).end($end_ts).granularity(DAILY),analytics.start($start_ts).end($end_ts).granularity(DAY),message_templates";
    if ($id_plantilla !== '') {
        $fields .= ",template_analytics.start($fecha_desde).end($fecha_hasta_mas_uno).granularity(DAILY).template_ids(['$id_plantilla'])";
    }

    $url = "https://graph.facebook.com/v23.0/" . rawurlencode($waba_id) . "?fields=$fields";

    $cache_key = 'waba_analytics_' . md5($url);
    if (isset($session_cache_snapshot[$cache_key]) && (time() - ($session_cache_snapshot[$cache_key]['ts'] ?? 0) < 180)) {
        $resp = $session_cache_snapshot[$cache_key]['resp'];
    } else {
        $resp = [];
        if ($tokenToUse !== '') {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $tokenToUse, "Content-Type: application/json"]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($curl, CURLOPT_TIMEOUT, 8);
            $raw_exec = curl_exec($curl);
            $curl_err = curl_error($curl);
            curl_close($curl);

            if ($raw_exec === false && $curl_err !== '') {
                starfi_log_exception('whatsapp_analytics', 'Error cURL consultando Meta Analytics', ['error' => $curl_err]);
            } else {
                $decoded = json_decode((string)$raw_exec, true);
                if (is_array($decoded)) {
                    $resp = $decoded;
                }
            }
        }

        if (is_array($resp) && !empty($resp) && !isset($resp['error'])) {
            @session_start();
            foreach (array_keys($_SESSION) as $k) {
                if (strpos($k, 'waba_analytics_') === 0 && $k !== $cache_key) {
                    unset($_SESSION[$k]);
                }
            }
            $_SESSION[$cache_key] = ['ts' => time(), 'resp' => $resp];
            session_write_close();
        } elseif (isset($resp['error'])) {
            starfi_log_exception('whatsapp_analytics', 'Respuesta de error Meta Graph API', ['meta_code' => $resp['error']['code'] ?? null]);
            if (!$es_master) {
                $resp = [];
            }
        }
    }

    // Auditoría híbrida local con 100% mysqli_prepare()
    $local_audit = [
        'enviados'   => 0,
        'entregados' => 0,
        'leidos'     => 0,
        'recibidos'  => 0,
        'por_categoria' => [
            'MARKETING'      => 0,
            'UTILITY'        => 0,
            'AUTHENTICATION' => 0,
            'SERVICE'        => 0,
        ],
        'costo_local_por_categoria' => [
            'MARKETING'      => 0.0,
            'UTILITY'        => 0.0,
            'AUTHENTICATION' => 0.0,
            'SERVICE'        => 0.0,
        ],
        'gratuitos' => 0,
        'pagados'   => 0,
    ];

    $desde_dt = $fecha_desde . " 00:00:00";
    $hasta_dt = $fecha_hasta . " 23:59:59";

    $camp_ids_active = [];
    $chk_camp = @$con->query("SHOW TABLES LIKE 'campanas_difusion_destinatarios'");
    $has_camp_table = ($chk_camp && $chk_camp->num_rows > 0);
    $destCols = [];
    $campCols = [];

    if ($has_camp_table) {
        $rD = @$con->query("SHOW COLUMNS FROM `campanas_difusion_destinatarios`");
        if ($rD) { while ($rw = $rD->fetch_assoc()) $destCols[$rw['Field']] = true; }
        $rC = @$con->query("SHOW COLUMNS FROM `campanas_difusion`");
        if ($rC) { while ($rw = $rC->fetch_assoc()) $campCols[$rw['Field']] = true; }

        $catExpr  = isset($campCols['template_category']) ? "c.template_category" : (isset($campCols['categoria_meta']) ? "c.categoria_meta" : "''");
        $dateCamp = isset($campCols['fecha_creacion']) ? "c.fecha_creacion" : "c.created_at";
        $costExpr = isset($destCols['costo_usd'])
            ? "CASE WHEN d.costo_usd > 0 THEN d.costo_usd WHEN c.costo_unitario_usd > 0 THEN c.costo_unitario_usd ELSE 0.0700 END"
            : "CASE WHEN c.costo_unitario_usd > 0 THEN c.costo_unitario_usd ELSE 0.0700 END";

        $where_camp_sede = "";
        $caTypes  = "ssss";
        $caParams = [$desde_dt, $hasta_dt, $desde_dt, $hasta_dt];
        if ($id_sede > 0) {
            if (isset($destCols['id_sede'])) {
                $where_camp_sede = " AND (d.id_sede = ? OR (d.id_sede = 0 AND c.id_sede = ?)) ";
                $caTypes   .= "ii";
                $caParams[] = $id_sede;
                $caParams[] = $id_sede;
            } else {
                $where_camp_sede = " AND c.id_sede = ? ";
                $caTypes   .= "i";
                $caParams[] = $id_sede;
            }
        }

        $q_camp_audit = "SELECT 
                            UPPER(COALESCE(NULLIF($catExpr, ''), 'MARKETING')) AS cat,
                            d.estado_envio,
                            COUNT(d.id) AS total,
                            SUM($costExpr) AS costo_sum,
                            GROUP_CONCAT(DISTINCT d.id_campana) AS camp_ids
                         FROM campanas_difusion_destinatarios d
                         LEFT JOIN campanas_difusion c ON d.id_campana = c.id
                         WHERE d.estado_envio IN ('ENVIADO', 'ENTREGADO', 'LEIDO', 'RESPONDIDO')
                           AND (
                               (d.fecha_envio >= ? AND d.fecha_envio <= ?)
                               OR (d.fecha_envio IS NULL AND $dateCamp >= ? AND $dateCamp <= ?)
                           )
                           $where_camp_sede
                         GROUP BY cat, d.estado_envio";

        $stmtCa = $con->prepare($q_camp_audit);
        if ($stmtCa) {
            $stmtCa->bind_param($caTypes, ...$caParams);
            $stmtCa->execute();
            $res_ca = $stmtCa->get_result();
            while ($res_ca && ($rca = $res_ca->fetch_assoc())) {
                $tot = intval($rca['total']);
                $cat = $rca['cat'] ?: 'MARKETING';
                $est = strtoupper($rca['estado_envio'] ?? 'ENVIADO');

                if (!empty($rca['camp_ids'])) {
                    foreach (explode(',', (string)$rca['camp_ids']) as $cid) {
                        $cid_int = intval($cid);
                        if ($cid_int > 0) {
                            $camp_ids_active[$cid_int] = $cid_int;
                        }
                    }
                }

                $local_audit['enviados'] += $tot;
                if (in_array($est, ['ENTREGADO', 'LEIDO', 'RESPONDIDO'], true)) {
                    $local_audit['entregados'] += $tot;
                }
                if (in_array($est, ['LEIDO', 'RESPONDIDO'], true)) {
                    $local_audit['leidos'] += $tot;
                }
                $local_audit['pagados'] += $tot;

                if (!isset($local_audit['por_categoria'][$cat])) {
                    $local_audit['por_categoria'][$cat] = 0;
                    $local_audit['costo_local_por_categoria'][$cat] = 0.0;
                }
                $local_audit['por_categoria'][$cat] += $tot;
                $local_audit['costo_local_por_categoria'][$cat] += floatval($rca['costo_sum']);
            }
            $stmtCa->close();
        }
    }

    // 2. Cruzar mensajes_y_eventos con mysqli_prepare()
    $audTypes  = "ss";
    $audParams = [$desde_dt, $hasta_dt];
    $where_sede = "";
    if ($id_sede > 0) {
        $where_sede = " AND c.id_linea IN (SELECT id FROM lineas_whatsapp WHERE id_sede = ?) ";
        $audTypes   .= "i";
        $audParams[] = $id_sede;
    }

    $exclude_wamid = "";
    if ($has_camp_table && !empty($camp_ids_active)) {
        $placeholders = implode(',', array_fill(0, count($camp_ids_active), '?'));
        $convCond = isset($destCols['id_conversacion'])
            ? "AND (d2.id_conversacion = m.id_conversacion OR d2.id_conversacion = 0)"
            : "";
        $exclude_wamid = " AND (
            m.origen != 'API_TRANSACCIONAL'
            OR m.id_mensaje_meta IS NULL
            OR m.id_mensaje_meta = ''
            OR NOT EXISTS (
                SELECT 1 FROM campanas_difusion_destinatarios d2 
                WHERE d2.id_campana IN ($placeholders)
                  $convCond
                  AND d2.id_mensaje_meta COLLATE utf8mb4_unicode_ci = m.id_mensaje_meta COLLATE utf8mb4_unicode_ci
            )
        ) ";
        foreach ($camp_ids_active as $cidInt) {
            $audTypes   .= "i";
            $audParams[] = intval($cidInt);
        }
    }

    $query_audit = "SELECT 
        m.origen, m.estado_envio, m.categoria_meta, m.es_pagado, COUNT(*) AS total,
        SUM(COALESCE(m.costo_meta_estimado, 0)) AS costo_est
        FROM mensajes_y_eventos m 
        JOIN conversaciones c ON m.id_conversacion = c.id 
        WHERE m.timestamp >= ? AND m.timestamp <= ? $where_sede $exclude_wamid
        GROUP BY m.origen, m.estado_envio, m.categoria_meta, m.es_pagado";

    $stmtAud = $con->prepare($query_audit);
    if ($stmtAud) {
        $stmtAud->bind_param($audTypes, ...$audParams);
        $stmtAud->execute();
        $res_audit = $stmtAud->get_result();
        while ($res_audit && ($r = $res_audit->fetch_assoc())) {
            $tot = intval($r['total']);
            if ($r['origen'] === 'CLIENTE') {
                $local_audit['recibidos'] += $tot;
            } else {
                $local_audit['enviados'] += $tot;
                if ($r['estado_envio'] === 'ENTREGADO' || $r['estado_envio'] === 'LEIDO') {
                    $local_audit['entregados'] += $tot;
                }
                if ($r['estado_envio'] === 'LEIDO') {
                    $local_audit['leidos'] += $tot;
                }

                $cat = strtoupper($r['categoria_meta'] ?? '');
                if (array_key_exists($cat, $local_audit['por_categoria'])) {
                    $local_audit['por_categoria'][$cat] += $tot;
                    $local_audit['costo_local_por_categoria'][$cat] += floatval($r['costo_est'] ?? 0);
                }

                if ($r['es_pagado'] == 1) {
                    $local_audit['pagados'] += $tot;
                } elseif ($r['es_pagado'] === '0' || $r['es_pagado'] === 0) {
                    $local_audit['gratuitos'] += $tot;
                }
            }
        }
        $stmtAud->close();
    }

    // Obtener configuración de tarifa de la sede
    $tarifa_config = ['tipo_tarifa' => 'PORCENTAJE', 'valor' => 10.00];
    if ($id_sede > 0) {
        $chkTarCols = @$con->query("SHOW COLUMNS FROM `waba_tarifas_sede` LIKE 'tipo_tarifa'");
        if ($chkTarCols && $chkTarCols->num_rows > 0) {
            $stmt = $con->prepare("SELECT tipo_tarifa, valor FROM waba_tarifas_sede WHERE id_sede = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $id_sede);
                $stmt->execute();
                $res_t = $stmt->get_result();
                if ($res_t && ($row_t = $res_t->fetch_assoc())) {
                    $tarifa_config = $row_t;
                }
                $stmt->close();
            }
        }
    }

    echo json_encode([
        'status'        => 'success',
        'is_master'     => $es_master,
        'data'          => $resp,
        'local_audit'   => $local_audit,
        'tarifa_config' => $tarifa_config,
    ]);
    exit;
}

$con_inv = getDbConnection();
if ($con_inv) {
    registrar_auditoria(
        'whatsapp_analytics',
        'ACCION_NO_VALIDA',
        'Intento de acción no soportada en analítica WhatsApp',
        null,
        ['action' => $action],
        $con_inv,
        intval($agente['id'] ?? 0),
        $id_sede_agente > 0 ? $id_sede_agente : null
    );
    $con_inv->close();
}
echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
exit;
