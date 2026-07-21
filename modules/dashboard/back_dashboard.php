<?php
// modules/dashboard/back_dashboard.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'load_kpis':
        $id_sede = $_POST['id_sede'] ?? 'all';
        $fecha_desde = $_POST['fecha_desde'] ?? '';
        $fecha_hasta = $_POST['fecha_hasta'] ?? '';

        $where_clauses = [];
        if (!empty($fecha_desde)) {
            $where_clauses[] = "c.fecha_inicio >= '" . $con->real_escape_string($fecha_desde) . " 00:00:00'";
        }
        if (!empty($fecha_hasta)) {
            $where_clauses[] = "c.fecha_inicio <= '" . $con->real_escape_string($fecha_hasta) . " 23:59:59'";
        }
        if ($id_sede !== 'all' && $id_sede !== '') {
            $where_clauses[] = "lw.id_sede = " . intval($id_sede);
        }

        $where_sql = "";
        if (count($where_clauses) > 0) {
            $where_sql = " WHERE " . implode(" AND ", $where_clauses);
        }

        // 1. Volumen Total de Chats
        $query1 = "SELECT COUNT(c.id) as total FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $where_sql;
        $res = $con->query($query1);
        $total_chats = $res ? $res->fetch_assoc()['total'] : 0;

        // 2. Ciclo de Ventas Promedio (Avg resolution time solo para VENTA_CERRADA)
        $ventas_where = $where_sql;
        if (empty($ventas_where)) {
            $ventas_where = " WHERE c.resultado_comercial = 'VENTA_CERRADA' AND c.fecha_cierre_venta IS NOT NULL";
        } else {
            $ventas_where .= " AND c.resultado_comercial = 'VENTA_CERRADA' AND c.fecha_cierre_venta IS NOT NULL";
        }
        $query2 = "SELECT AVG(TIMESTAMPDIFF(MINUTE, c.fecha_inicio, c.fecha_cierre_venta)) as avg_res FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $ventas_where;
        $res2 = $con->query($query2);
        $avg_res = $res2 ? intval($res2->fetch_assoc()['avg_res']) : 0;
        if($avg_res == 0) $avg_res = 15; // Mock

        // 3. Tasa de Conversión (%)
        $cerrados_where = empty($where_sql) ? " WHERE c.estado = 'CERRADO'" : $where_sql . " AND c.estado = 'CERRADO'";
        $query3 = "SELECT 
                    COUNT(c.id) as total_cerrados, 
                    SUM(IF(c.resultado_comercial = 'VENTA_CERRADA', 1, 0)) as ventas_cerradas 
                   FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where;
        $res3 = $con->query($query3);
        $conversion = 0;
        if ($res3) {
            $row_conv = $res3->fetch_assoc();
            $total_cerrados = intval($row_conv['total_cerrados']);
            $ventas_cerradas = intval($row_conv['ventas_cerradas']);
            if ($total_cerrados > 0) {
                $conversion = round(($ventas_cerradas / $total_cerrados) * 100, 1);
            }
        }

        // 4. CAC (Placeholder / Cálculo Básico)
        // Por ahora lo calculamos con un estimado de $0.10 por chat cobrado por Meta + Margen (solo como demo visual)
        // En un futuro debe leer de facturacion_waba_config_tarifas
        $costo_estimado_meta = $total_chats * 0.10; 
        $cac = 0;
        if (isset($ventas_cerradas) && $ventas_cerradas > 0) {
            $cac = round($costo_estimado_meta / $ventas_cerradas, 2);
        }

        // 5. Lead Scoring Promedio
        $lead_query = "SELECT AVG(cc.calificacion_calidad) as avg_score FROM clientes_contactos cc JOIN conversaciones c ON cc.id = c.id_cliente" . $cerrados_where . " AND cc.calificacion_calidad > 0";
        $res_lead = $con->query($lead_query);
        $avg_lead = $res_lead ? round($res_lead->fetch_assoc()['avg_score'], 1) : 0;

        // 5.5 CSAT Promedio
        $csat_query = "SELECT AVG(c.csat_score) as avg_csat FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where . " AND c.csat_score > 0";
        $res_csat = $con->query($csat_query);
        $avg_csat = $res_csat ? round($res_csat->fetch_assoc()['avg_csat'], 1) : 0;

        // 6. Desempeño por Operador
        $op_where_clauses = $where_clauses;
        $op_where_clauses[] = "c.id IS NOT NULL";
        $op_where_sql = " WHERE " . implode(" AND ", $op_where_clauses);
        $query4 = "
<<<<<<< HEAD
            SELECT COALESCE(up.nombre, u.usuario) as nombre_completo, COUNT(c.id) as chats_atendidos 
            FROM usuario u 
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
=======
            SELECT up.nombre AS nombre_completo, COUNT(c.id) as chats_atendidos 
            FROM usuario u 
            JOIN usuario_perfil up ON u.id = up.id_usuario
>>>>>>> ebaa681d04de0eff8aace2ea568a98e5878ac3e6
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
