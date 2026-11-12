<?php
// modules/dashboard/back_dashboard.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
$agente_dashboard = getAgenteInfo();
session_write_close();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

// Asegurar una sola vez índice compuesto para consultas instantáneas de costos WABA en Dashboard
$dash_idx_flag = rtrim(sys_get_temp_dir(), '/\\') . '/starfi_dash_idx_v1.flag';
if ($con && !file_exists($dash_idx_flag)) {
    $chkIdx = @$con->query("SHOW INDEX FROM mensajes_y_eventos WHERE Key_name = 'idx_msg_pagado_ts'");
    if ($chkIdx && $chkIdx->num_rows === 0) {
        @$con->query("ALTER TABLE mensajes_y_eventos ADD INDEX idx_msg_pagado_ts (es_pagado, timestamp)");
    }
    @file_put_contents($dash_idx_flag, date('c'));
}

switch ($action) {
    case 'load_kpis':
        $agente = $agente_dashboard;
        $rol = strtoupper(trim($agente['rol'] ?? 'AGENTE'));
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        $is_master = ($rol === 'MASTER' || $rol === 'MASTER CI' || intval($agente['id'] ?? 0) === 1);

        if (!$is_master && $user_sede > 0) {
            $id_sede = $user_sede;
        } else {
            $id_sede = $_POST['id_sede'] ?? 'all';
        }

        $fecha_desde = trim($_POST['fecha_desde'] ?? '');
        $fecha_hasta = trim($_POST['fecha_hasta'] ?? '');
        $desde_esc = !empty($fecha_desde) ? $con->real_escape_string($fecha_desde) . ' 00:00:00' : '';
        $hasta_esc = !empty($fecha_hasta) ? $con->real_escape_string($fecha_hasta) . ' 23:59:59' : '';
        $sede_int = ($id_sede !== 'all' && $id_sede !== '') ? intval($id_sede) : 0;

        $where_clauses = [];
        if ($desde_esc !== '') {
            $where_clauses[] = "c.fecha_inicio >= '$desde_esc'";
        }
        if ($hasta_esc !== '') {
            $where_clauses[] = "c.fecha_inicio <= '$hasta_esc'";
        }
        if ($sede_int > 0) {
            $where_clauses[] = "lw.id_sede = $sede_int";
        }

        $where_sql = count($where_clauses) > 0 ? (" WHERE " . implode(" AND ", $where_clauses)) : "";
        $cerrados_where = empty($where_sql) ? " WHERE c.estado = 'CERRADO'" : $where_sql . " AND c.estado = 'CERRADO'";

        // 1, 2, 3 y 5.5 consolidados en UNA sola lectura de conversaciones (sin valores Mock)
        $q_summary = "
            SELECT 
                COUNT(c.id) AS total_chats,
                AVG(CASE WHEN c.resultado_comercial = 'VENTA_CERRADA' AND c.fecha_cierre_venta IS NOT NULL 
                    THEN TIMESTAMPDIFF(MINUTE, c.fecha_inicio, c.fecha_cierre_venta) ELSE NULL END) AS avg_res,
                COALESCE(SUM(CASE WHEN c.estado = 'CERRADO' THEN 1 ELSE 0 END), 0) AS total_cerrados,
                COALESCE(SUM(CASE WHEN c.estado = 'CERRADO' AND c.resultado_comercial = 'VENTA_CERRADA' THEN 1 ELSE 0 END), 0) AS ventas_cerradas,
                AVG(CASE WHEN c.estado = 'CERRADO' AND c.csat_score > 0 THEN c.csat_score ELSE NULL END) AS avg_csat
            FROM conversaciones c
            LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id
            $where_sql
        ";
        $res_sum = $con->query($q_summary);
        $total_chats = 0;
        $avg_res = 0;
        $conversion = 0;
        $ventas_cerradas = 0;
        $avg_csat = 0;

        if ($res_sum && ($rsum = $res_sum->fetch_assoc())) {
            $total_chats = intval($rsum['total_chats'] ?? 0);
            if ($rsum['avg_res'] !== null) {
                $avg_res = max(0, intval(round(floatval($rsum['avg_res']))));
            }
            $total_cerrados = intval($rsum['total_cerrados'] ?? 0);
            $ventas_cerradas = intval($rsum['ventas_cerradas'] ?? 0);
            if ($total_cerrados > 0) {
                $conversion = round(($ventas_cerradas / $total_cerrados) * 100, 1);
            }
            if ($rsum['avg_csat'] !== null) {
                $avg_csat = round(floatval($rsum['avg_csat']), 1);
            }
        }

        // 4. CAC real basado 100% en consumo registrado en Base de Datos (Campañas + Mensajes facturables WABA)
        $gasto_real_total = 0.0;
        $camp_ids_periodo = [];

        $camp_conds = ["d.estado_envio IN ('ENVIADO', 'ENTREGADO', 'LEIDO', 'RESPONDIDO')"];
        if ($sede_int > 0) {
            $camp_conds[] = "(d.id_sede = $sede_int OR (d.id_sede = 0 AND cd.id_sede = $sede_int))";
        }
        if ($desde_esc !== '') {
            $camp_conds[] = "COALESCE(d.fecha_envio, cd.fecha_creacion) >= '$desde_esc'";
        }
        if ($hasta_esc !== '') {
            $camp_conds[] = "COALESCE(d.fecha_envio, cd.fecha_creacion) <= '$hasta_esc'";
        }

        $q_gasto_camp = "
            SELECT 
                COALESCE(SUM(CASE 
                    WHEN d.costo_usd > 0 THEN d.costo_usd 
                    WHEN cd.costo_unitario_usd > 0 THEN cd.costo_unitario_usd 
                    ELSE 0 
                END), 0) AS gasto_camp,
                GROUP_CONCAT(DISTINCT d.id_campana) AS camp_ids
            FROM campanas_difusion_destinatarios d
            LEFT JOIN campanas_difusion cd ON d.id_campana = cd.id
            WHERE " . implode(" AND ", $camp_conds);
        $res_gc = @$con->query($q_gasto_camp);
        if ($res_gc && ($row_gc = $res_gc->fetch_assoc())) {
            $gasto_real_total += floatval($row_gc['gasto_camp'] ?? 0);
            if (!empty($row_gc['camp_ids'])) {
                foreach (explode(',', $row_gc['camp_ids']) as $cid_raw) {
                    $cid_i = intval($cid_raw);
                    if ($cid_i > 0) {
                        $camp_ids_periodo[$cid_i] = $cid_i;
                    }
                }
            }
        }

        // Sumar mensajes facturables en mensajes_y_eventos (usando idx_msg_pagado_ts)
        $msg_conds = ["m.es_pagado = 1", "m.origen != 'CLIENTE'"];
        if ($desde_esc !== '') {
            $msg_conds[] = "m.timestamp >= '$desde_esc'";
        }
        if ($hasta_esc !== '') {
            $msg_conds[] = "m.timestamp <= '$hasta_esc'";
        }
        if ($sede_int > 0) {
            $msg_conds[] = "lw.id_sede = $sede_int";
        }

        $join_sede_msg = ($sede_int > 0)
            ? "JOIN conversaciones c ON m.id_conversacion = c.id JOIN lineas_whatsapp lw ON c.id_linea = lw.id"
            : "";
        $q_gasto_msg = "
            SELECT COALESCE(SUM(COALESCE(m.costo_meta_estimado, 0)), 0) AS gasto_msg
            FROM mensajes_y_eventos m
            $join_sede_msg
            WHERE " . implode(" AND ", $msg_conds);
        $res_gm = $con->query($q_gasto_msg);
        $gasto_msg_bruto = 0.0;
        if ($res_gm && ($row_gm = $res_gm->fetch_assoc())) {
            $gasto_msg_bruto = floatval($row_gm['gasto_msg'] ?? 0);
        }

        // Descontar en O(K) por índice idx_meta_wamid cualquier wamid de campaña que también figure con es_pagado=1 en mensajes_y_eventos
        if ($gasto_msg_bruto > 0 && !empty($camp_ids_periodo)) {
            $camp_csv = implode(',', $camp_ids_periodo);
            $ov_conds = [
                "d2.id_campana IN ($camp_csv)",
                "d2.id_mensaje_meta IS NOT NULL",
                "d2.id_mensaje_meta != ''",
                "m.es_pagado = 1"
            ];
            if ($desde_esc !== '') {
                $ov_conds[] = "m.timestamp >= '$desde_esc'";
            }
            if ($hasta_esc !== '') {
                $ov_conds[] = "m.timestamp <= '$hasta_esc'";
            }
            $q_overlap = "
                SELECT COALESCE(SUM(COALESCE(m.costo_meta_estimado, 0)), 0) AS gasto_duplicado
                FROM campanas_difusion_destinatarios d2
                JOIN mensajes_y_eventos m USE INDEX (idx_meta_wamid)
                  ON m.id_mensaje_meta = CONVERT(d2.id_mensaje_meta USING utf8mb4) COLLATE utf8mb4_unicode_ci
                WHERE " . implode(" AND ", $ov_conds);
            $res_ov = @$con->query($q_overlap);
            if ($res_ov && ($row_ov = $res_ov->fetch_assoc())) {
                $gasto_msg_bruto = max(0.0, $gasto_msg_bruto - floatval($row_ov['gasto_duplicado'] ?? 0));
            }
        }
        $gasto_real_total += $gasto_msg_bruto;

        $cac = ($ventas_cerradas > 0) ? round($gasto_real_total / $ventas_cerradas, 2) : 0;

        // 5. Lead Scoring Promedio
        $lead_query = "SELECT AVG(cc.calificacion_calidad) as avg_score FROM clientes_contactos cc JOIN conversaciones c ON cc.id = c.id_cliente LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where . " AND cc.calificacion_calidad > 0";
        $res_lead = $con->query($lead_query);
        $avg_lead = ($res_lead && ($rl = $res_lead->fetch_assoc()) && $rl['avg_score'] !== null) ? round(floatval($rl['avg_score']), 1) : 0;

        // 6. Desempeño por Operador
        $op_where_clauses = $where_clauses;
        $op_where_clauses[] = "c.id_agente IS NOT NULL AND c.id_agente > 0";
        $op_where_sql = " WHERE " . implode(" AND ", $op_where_clauses);
        $query4 = "
            SELECT COALESCE(up.nombre, u.usuario) as nombre_completo, COUNT(c.id) as chats_atendidos 
            FROM conversaciones c
            JOIN usuario u ON u.id = c.id_agente
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
            LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id
            $op_where_sql
            GROUP BY u.id 
            ORDER BY chats_atendidos DESC LIMIT 5
        ";
        $res4 = $con->query($query4);
        $operadores = [];
        if ($res4) {
            while ($row = $res4->fetch_assoc()) {
                $operadores[] = $row;
            }
        }

        // 7. Volumen de Chats por Día (Gráfico)
        $chart_where_sql = empty($where_sql) ? " WHERE c.fecha_inicio IS NOT NULL" : $where_sql . " AND c.fecha_inicio IS NOT NULL";
        $query5 = "SELECT DATE(c.fecha_inicio) as fecha, COUNT(c.id) as volumen FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id $chart_where_sql GROUP BY DATE(c.fecha_inicio) ORDER BY fecha ASC";
        $res5 = $con->query($query5);
        $chart_data = [];
        if ($res5) {
            while ($row = $res5->fetch_assoc()) {
                $chart_data[] = $row;
            }
        }

        // 8. Motivos de Cierre (Gráfico Embudo)
        $motivos_query = "SELECT IFNULL(c.resultado_comercial, 'NO_ESPECIFICADO') as motivo, COUNT(c.id) as cantidad FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where . " GROUP BY c.resultado_comercial";
        $res_motivos = $con->query($motivos_query);
        $motivos_data = [];
        if ($res_motivos) {
            while ($row = $res_motivos->fetch_assoc()) {
                $motivos_data[] = $row;
            }
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'total_chats' => $total_chats,
                'avg_res' => $avg_res . " min",
                'conversion_rate' => $conversion . "%",
                'cac' => "$" . number_format($cac, 2),
                'gasto_real_usd' => round($gasto_real_total, 4),
                'lead_score' => $avg_lead,
                'csat_score' => $avg_csat,
                'operadores' => $operadores,
                'chart_data' => $chart_data,
                'motivos_data' => $motivos_data
            ]
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
        break;
}
?>
