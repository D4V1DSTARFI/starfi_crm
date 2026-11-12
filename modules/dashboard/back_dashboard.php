<?php
// modules/dashboard/back_dashboard.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
$agente_dashboard = getAgenteInfo();
session_write_close();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

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

        // 1. Volumen Total de Chats
        $query1 = "SELECT COUNT(c.id) as total FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $where_sql;
        $res = $con->query($query1);
        $total_chats = $res ? intval($res->fetch_assoc()['total'] ?? 0) : 0;

        // 2. Ciclo de Ventas Promedio real (solo VENTA_CERRADA, sin valores Mock)
        $ventas_where = empty($where_sql)
            ? " WHERE c.resultado_comercial = 'VENTA_CERRADA' AND c.fecha_cierre_venta IS NOT NULL"
            : $where_sql . " AND c.resultado_comercial = 'VENTA_CERRADA' AND c.fecha_cierre_venta IS NOT NULL";
        $query2 = "SELECT AVG(TIMESTAMPDIFF(MINUTE, c.fecha_inicio, c.fecha_cierre_venta)) as avg_res FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $ventas_where;
        $res2 = $con->query($query2);
        $avg_res = 0;
        if ($res2 && ($row2 = $res2->fetch_assoc()) && $row2['avg_res'] !== null) {
            $avg_res = max(0, intval(round(floatval($row2['avg_res']))));
        }

        // 3. Tasa de Conversión (%)
        $cerrados_where = empty($where_sql) ? " WHERE c.estado = 'CERRADO'" : $where_sql . " AND c.estado = 'CERRADO'";
        $query3 = "SELECT 
                    COUNT(c.id) as total_cerrados, 
                    COALESCE(SUM(IF(c.resultado_comercial = 'VENTA_CERRADA', 1, 0)), 0) as ventas_cerradas 
                   FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where;
        $res3 = $con->query($query3);
        $conversion = 0;
        $ventas_cerradas = 0;
        if ($res3 && ($row_conv = $res3->fetch_assoc())) {
            $total_cerrados = intval($row_conv['total_cerrados']);
            $ventas_cerradas = intval($row_conv['ventas_cerradas']);
            if ($total_cerrados > 0) {
                $conversion = round(($ventas_cerradas / $total_cerrados) * 100, 1);
            }
        }

        // 4. CAC real basado 100% en consumo registrado en Base de Datos (Campañas + Mensajes facturables WABA)
        $gasto_real_total = 0.0;
        $camp_ids_periodo = [];

        $chk_camp_tbl = $con->query("SHOW TABLES LIKE 'campanas_difusion_destinatarios'");
        if ($chk_camp_tbl && $chk_camp_tbl->num_rows > 0) {
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
            $res_gc = $con->query($q_gasto_camp);
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
        }

        // Sumar mensajes facturables fuera de campañas ya contabilizadas
        $msg_conds = ["m.origen != 'CLIENTE'", "m.es_pagado = 1"];
        if ($sede_int > 0) {
            $msg_conds[] = "lw.id_sede = $sede_int";
        }
        if ($desde_esc !== '') {
            $msg_conds[] = "m.timestamp >= '$desde_esc'";
        }
        if ($hasta_esc !== '') {
            $msg_conds[] = "m.timestamp <= '$hasta_esc'";
        }
        if (!empty($camp_ids_periodo)) {
            $camp_csv = implode(',', $camp_ids_periodo);
            $msg_conds[] = "(
                m.origen != 'API_TRANSACCIONAL'
                OR m.id_mensaje_meta IS NULL
                OR m.id_mensaje_meta = ''
                OR NOT EXISTS (
                    SELECT 1 FROM campanas_difusion_destinatarios d2
                    WHERE d2.id_campana IN ($camp_csv)
                      AND d2.id_mensaje_meta = m.id_mensaje_meta
                )
            )";
        }

        $q_gasto_msg = "
            SELECT COALESCE(SUM(COALESCE(m.costo_meta_estimado, 0)), 0) AS gasto_msg
            FROM mensajes_y_eventos m
            JOIN conversaciones c ON m.id_conversacion = c.id
            LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id
            WHERE " . implode(" AND ", $msg_conds);
        $res_gm = $con->query($q_gasto_msg);
        if ($res_gm && ($row_gm = $res_gm->fetch_assoc())) {
            $gasto_real_total += floatval($row_gm['gasto_msg'] ?? 0);
        }

        $cac = ($ventas_cerradas > 0) ? round($gasto_real_total / $ventas_cerradas, 2) : 0;

        // 5. Lead Scoring Promedio
        $lead_query = "SELECT AVG(cc.calificacion_calidad) as avg_score FROM clientes_contactos cc JOIN conversaciones c ON cc.id = c.id_cliente LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where . " AND cc.calificacion_calidad > 0";
        $res_lead = $con->query($lead_query);
        $avg_lead = ($res_lead && ($rl = $res_lead->fetch_assoc()) && $rl['avg_score'] !== null) ? round(floatval($rl['avg_score']), 1) : 0;

        // 5.5 CSAT Promedio
        $csat_query = "SELECT AVG(c.csat_score) as avg_csat FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where . " AND c.csat_score > 0";
        $res_csat = $con->query($csat_query);
        $avg_csat = ($res_csat && ($rcsat = $res_csat->fetch_assoc()) && $rcsat['avg_csat'] !== null) ? round(floatval($rcsat['avg_csat']), 1) : 0;

        // 6. Desempeño por Operador
        $op_where_clauses = $where_clauses;
        $op_where_clauses[] = "c.id IS NOT NULL";
        $op_where_sql = " WHERE " . implode(" AND ", $op_where_clauses);
        $query4 = "
            SELECT COALESCE(up.nombre, u.usuario) as nombre_completo, COUNT(c.id) as chats_atendidos 
            FROM usuario u 
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
            LEFT JOIN conversaciones c ON u.id = c.id_agente 
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
