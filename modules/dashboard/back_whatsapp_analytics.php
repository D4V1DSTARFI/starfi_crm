<?php
date_default_timezone_set('America/Caracas');
define('WABA_BILLING_LIB_ONLY', true);
require_once __DIR__ . '/back_waba_billing.php';

requireAuth();
header('Content-Type: application/json; charset=utf-8');

$agente = getAgenteInfo();
$es_master = (($agente['rol'] ?? '') === 'MASTER');
$id_sede_agente = intval($agente['id_sede'] ?? 0);

$action = $_POST['action'] ?? '';

if ($action === 'get_analytics') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    if (!$es_master) {
        $id_sede = $id_sede_agente;
    }

    $fecha_desde = $_POST['fecha_desde'] ?? date('Y-m-01');
    $fecha_hasta = $_POST['fecha_hasta'] ?? date('Y-m-t');

    $start_ts = strtotime($fecha_desde . " 00:00:00");
    $end_ts   = strtotime($fecha_hasta . " 23:59:59");

    $id_plantilla = trim($_POST['id_plantilla'] ?? '');

    $con = getDbConnection();
    if (!$con) {
        echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos']);
        exit;
    }

    if ($id_sede > 0) {
        $res = $con->query("SELECT id_negocio, meta_token FROM lineas_whatsapp WHERE id_sede = $id_sede AND id_negocio IS NOT NULL AND id_negocio != '' ORDER BY (estado = 'ACTIVO') DESC LIMIT 1");
    } else {
        $res = $con->query("SELECT id_negocio, meta_token FROM lineas_whatsapp WHERE id_negocio IS NOT NULL AND id_negocio != '' ORDER BY (estado = 'ACTIVO') DESC LIMIT 1");
    }

    if ($res && $row = $res->fetch_assoc()) {
        $waba_id = $row['id_negocio'];
        if (empty($waba_id)) {
            echo json_encode(['status' => 'error', 'message' => 'WABA ID no configurado']);
            exit;
        }

        $fecha_hasta_mas_uno = date('Y-m-d', strtotime($fecha_hasta . ' +1 day'));

        // Meta Endpoint base (costos, conversaciones, mensajes y lista de plantillas)
        $fields = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY),conversation_analytics.start($start_ts).end($end_ts).granularity(DAILY),analytics.start($start_ts).end($end_ts).granularity(DAY),message_templates";

        if (!empty($id_plantilla)) {
            $fields .= ",template_analytics.start($fecha_desde).end($fecha_hasta_mas_uno).granularity(DAILY).template_ids(['$id_plantilla'])";
        }

        $url = "https://graph.facebook.com/v23.0/$waba_id?fields=$fields";

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_GLOBAL_TOKEN, "Content-Type: application/json"]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        $raw_exec = curl_exec($curl);
        $resp = json_decode($raw_exec, true);
        curl_close($curl);

        // Auditoría híbrida local (mensajes_y_eventos + campanas_difusion_destinatarios)
        $local_audit = [
            'enviados'   => 0,
            'entregados' => 0,
            'leidos'     => 0,
            'recibidos'  => 0,
            'por_categoria' => [
                'MARKETING'      => 0,
                'UTILITY'        => 0,
                'AUTHENTICATION' => 0,
                'SERVICE'        => 0
            ],
            'costo_local_por_categoria' => [
                'MARKETING'      => 0.0,
                'UTILITY'        => 0.0,
                'AUTHENTICATION' => 0.0,
                'SERVICE'        => 0.0
            ],
            'gratuitos' => 0,
            'pagados'   => 0
        ];

        $desde_dt = $con->real_escape_string($fecha_desde) . " 00:00:00";
        $hasta_dt = $con->real_escape_string($fecha_hasta) . " 23:59:59";

        // 1. Cruzar campanas_difusion_destinatarios
        $chk_camp = $con->query("SHOW TABLES LIKE 'campanas_difusion_destinatarios'");
        $has_camp_table = ($chk_camp && $chk_camp->num_rows > 0);
        if ($has_camp_table) {
            $where_camp_sede = ($id_sede > 0) ? " AND (d.id_sede = $id_sede OR (d.id_sede = 0 AND c.id_sede = $id_sede)) " : "";
            $q_camp_audit = "SELECT 
                                UPPER(COALESCE(NULLIF(c.template_category, ''), 'MARKETING')) AS cat,
                                d.estado_envio,
                                COUNT(d.id) AS total,
                                SUM(CASE WHEN d.costo_usd > 0 THEN d.costo_usd WHEN c.costo_unitario_usd > 0 THEN c.costo_unitario_usd ELSE 0.0700 END) AS costo_sum
                             FROM campanas_difusion_destinatarios d
                             LEFT JOIN campanas_difusion c ON d.id_campana = c.id
                             WHERE d.estado_envio IN ('ENVIADO', 'ENTREGADO', 'LEIDO', 'RESPONDIDO')
                               AND COALESCE(d.fecha_envio, d.fecha_entrega, c.fecha_creacion) >= '$desde_dt'
                               AND COALESCE(d.fecha_envio, d.fecha_entrega, c.fecha_creacion) <= '$hasta_dt'
                               $where_camp_sede
                             GROUP BY cat, d.estado_envio";
            $res_ca = $con->query($q_camp_audit);
            if ($res_ca) {
                while ($rca = $res_ca->fetch_assoc()) {
                    $tot = intval($rca['total']);
                    $cat = $rca['cat'] ?: 'MARKETING';
                    $est = strtoupper($rca['estado_envio'] ?? 'ENVIADO');

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
            }
        }

        // 2. Cruzar mensajes_y_eventos (excluyendo wamids de campañas para evitar doble conteo)
        $where_sede = "";
        if ($id_sede > 0) {
            $where_sede = " AND c.id_linea IN (SELECT id FROM lineas_whatsapp WHERE id_sede = $id_sede) ";
        }
        $exclude_wamid = "";
        if ($has_camp_table) {
            $exclude_wamid = " AND (m.id_mensaje_meta IS NULL OR m.id_mensaje_meta = '' OR NOT EXISTS (
                SELECT 1 FROM campanas_difusion_destinatarios d2 WHERE d2.id_mensaje_meta COLLATE utf8mb4_unicode_ci = m.id_mensaje_meta COLLATE utf8mb4_unicode_ci
            )) ";
        }

        $query_audit = "SELECT 
            m.origen, m.estado_envio, m.categoria_meta, m.es_pagado, COUNT(*) as total,
            SUM(COALESCE(m.costo_meta_estimado, 0)) as costo_est
            FROM mensajes_y_eventos m 
            JOIN conversaciones c ON m.id_conversacion = c.id 
            WHERE m.timestamp >= '$desde_dt' AND m.timestamp <= '$hasta_dt' $where_sede $exclude_wamid
            GROUP BY m.origen, m.estado_envio, m.categoria_meta, m.es_pagado";

        $res_audit = $con->query($query_audit);
        if ($res_audit) {
            while ($r = $res_audit->fetch_assoc()) {
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
        }

        // Obtener configuración de tarifa de la sede
        $tarifa_config = ['tipo_tarifa' => 'PORCENTAJE', 'valor' => 10.00];
        if ($id_sede > 0) {
            $stmt = $con->prepare("SELECT tipo_tarifa, valor FROM waba_tarifas_sede WHERE id_sede = ?");
            $stmt->bind_param("i", $id_sede);
            $stmt->execute();
            $res_t = $stmt->get_result();
            if ($row_t = $res_t->fetch_assoc()) {
                $tarifa_config = $row_t;
            }
            $stmt->close();
        }

        echo json_encode([
            'status'        => 'success',
            'is_master'     => $es_master,
            'data'          => $resp,
            'local_audit'   => $local_audit,
            'tarifa_config' => $tarifa_config
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No hay líneas de WhatsApp configuradas']);
    }
}
?>
