<?php
// modules/dashboard/back_dashboard.php
// Controlador de KPIs y Métricas del Dashboard — STARFI CRM (QA-STD-GLOBAL-2.1)
// 100% mysqli_prepare() + cero fugas SQL + bitácora formal starfi_log_exception().

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';

requireAuth();
requirePermission('dashboard');

$agente_dashboard = getAgenteInfo();
session_write_close();

$con = getDbConnection();
if (!$con) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos.']);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));

// Asegurar una sola vez por BD índices compuestos (solo fuera de transacciones activas)
$dash_db_hash = starfi_db_schema_hash($con);
$dash_idx_flag = rtrim(sys_get_temp_dir(), '/\\') . '/starfi_dash_idx_v3_' . $dash_db_hash . '.flag';
$in_active_tx = function_exists('starfi_tx_is_active') && starfi_tx_is_active($con);
if (!$in_active_tx && !file_exists($dash_idx_flag)) {
    $stmtIdx1 = @$con->prepare("SHOW INDEX FROM mensajes_y_eventos WHERE Key_name = 'idx_msg_pagado_ts'");
    if ($stmtIdx1 && $stmtIdx1->execute()) {
        $rIdx1 = $stmtIdx1->get_result();
        $hasIdx1 = ($rIdx1 && $rIdx1->num_rows > 0);
        $stmtIdx1->close();
        if (!$hasIdx1) {
            $stAlt1 = @$con->prepare("ALTER TABLE mensajes_y_eventos ADD INDEX idx_msg_pagado_ts (es_pagado, timestamp)");
            if ($stAlt1) { @$stAlt1->execute(); $stAlt1->close(); }
        }
    }
    $stmtIdx2 = @$con->prepare("SHOW INDEX FROM mensajes_y_eventos WHERE Key_name = 'idx_meta_wamid'");
    if ($stmtIdx2 && $stmtIdx2->execute()) {
        $rIdx2 = $stmtIdx2->get_result();
        $hasIdx2 = ($rIdx2 && $rIdx2->num_rows > 0);
        $stmtIdx2->close();
        if (!$hasIdx2) {
            $stAlt2 = @$con->prepare("ALTER TABLE mensajes_y_eventos ADD INDEX idx_meta_wamid (id_mensaje_meta(100))");
            if ($stAlt2) { @$stAlt2->execute(); $stAlt2->close(); }
        }
    }
    @file_put_contents($dash_idx_flag, date('c'));
}

function dash_fetch_all_prepared(mysqli $con, string $sql, string $types = '', array $params = []): array {
    $stmt = @$con->prepare($sql);
    if (!$stmt) {
        starfi_log_exception('dashboard', 'No se pudo preparar consulta de KPIs', ['errno' => $con->errno]);
        return [];
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        starfi_log_exception('dashboard', 'Fallo ejecutando consulta de KPIs', ['errno' => $stmt->errno]);
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    $stmt->close();
    return $rows;
}

switch ($action) {
    case 'load_kpis':
        $agente = $agente_dashboard;
        $rol = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        $is_master = ($rol === 'MASTER' || $rol === 'MASTER CI' || intval($agente['id'] ?? 0) === 1);

        if (!$is_master && $user_sede > 0) {
            $id_sede = $user_sede;
        } else {
            $id_sede = $_POST['id_sede'] ?? 'all';
        }

        $fecha_desde = trim((string)($_POST['fecha_desde'] ?? ''));
        $fecha_hasta = trim((string)($_POST['fecha_hasta'] ?? ''));
        $desde_dt = ($fecha_desde !== '' && strtotime($fecha_desde)) ? (date('Y-m-d', strtotime($fecha_desde)) . ' 00:00:00') : '';
        $hasta_dt = ($fecha_hasta !== '' && strtotime($fecha_hasta)) ? (date('Y-m-d', strtotime($fecha_hasta)) . ' 23:59:59') : '';
        $sede_int = ($id_sede !== 'all' && $id_sede !== '') ? intval($id_sede) : 0;

        $where_clauses = [];
        $base_types    = '';
        $base_params   = [];

        if ($desde_dt !== '') {
            $where_clauses[] = "c.fecha_inicio >= ?";
            $base_types     .= "s";
            $base_params[]   = $desde_dt;
        }
        if ($hasta_dt !== '') {
            $where_clauses[] = "c.fecha_inicio <= ?";
            $base_types     .= "s";
            $base_params[]   = $hasta_dt;
        }
        if ($sede_int > 0) {
            $where_clauses[] = "lw.id_sede = ?";
            $base_types     .= "i";
            $base_params[]   = $sede_int;
        }

        $where_sql = count($where_clauses) > 0 ? (" WHERE " . implode(" AND ", $where_clauses)) : "";
        $cerrados_where = ($where_sql === "") ? " WHERE c.estado = 'CERRADO'" : ($where_sql . " AND c.estado = 'CERRADO'");

        $convCols = [];
        foreach (dash_fetch_all_prepared($con, "SHOW COLUMNS FROM `conversaciones`") as $rc) {
            if (!empty($rc['Field'])) {
                $convCols[$rc['Field']] = true;
            }
        }
        $csatCol = isset($convCols['csat_score']) ? 'c.csat_score' : (isset($convCols['calificacion_csat']) ? 'c.calificacion_csat' : 'NULL');
        $hasResCom = isset($convCols['resultado_comercial']);
        $hasCierre = isset($convCols['fecha_cierre_venta']);

        $avgResExpr = ($hasResCom && $hasCierre)
            ? "AVG(CASE WHEN c.resultado_comercial = 'VENTA_CERRADA' AND c.fecha_cierre_venta IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, c.fecha_inicio, c.fecha_cierre_venta) ELSE NULL END)"
            : "NULL";
        $ventasExpr = $hasResCom
            ? "COALESCE(SUM(CASE WHEN c.estado = 'CERRADO' AND c.resultado_comercial = 'VENTA_CERRADA' THEN 1 ELSE 0 END), 0)"
            : "0";
        $csatExpr = ($csatCol !== 'NULL')
            ? "AVG(CASE WHEN c.estado = 'CERRADO' AND $csatCol > 0 THEN $csatCol ELSE NULL END)"
            : "NULL";

        $q_summary = "
            SELECT 
                COUNT(c.id) AS total_chats,
                $avgResExpr AS avg_res,
                COALESCE(SUM(CASE WHEN c.estado = 'CERRADO' THEN 1 ELSE 0 END), 0) AS total_cerrados,
                $ventasExpr AS ventas_cerradas,
                $csatExpr AS avg_csat
            FROM conversaciones c
            LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id
            $where_sql
        ";
        $sumRows = dash_fetch_all_prepared($con, $q_summary, $base_types, $base_params);
        $total_chats     = 0;
        $avg_res         = 0;
        $conversion      = 0;
        $ventas_cerradas = 0;
        $avg_csat        = 0;

        if (!empty($sumRows)) {
            $rsum = $sumRows[0];
            $total_chats = intval($rsum['total_chats'] ?? 0);
            if ($rsum['avg_res'] !== null) {
                $avg_res = max(0, intval(round(floatval($rsum['avg_res']))));
            }
            $total_cerrados  = intval($rsum['total_cerrados'] ?? 0);
            $ventas_cerradas = intval($rsum['ventas_cerradas'] ?? 0);
            if ($total_cerrados > 0) {
                $conversion = round(($ventas_cerradas / $total_cerrados) * 100, 1);
            }
            if ($rsum['avg_csat'] !== null) {
                $avg_csat = round(floatval($rsum['avg_csat']), 1);
            }
        }

        // 4. CAC real basado 100% en consumo registrado en Base de Datos
        $gasto_real_total = 0.0;
        $camp_ids_periodo = [];

        $chkCampRows = dash_fetch_all_prepared($con, "SHOW TABLES LIKE 'campanas_difusion_destinatarios'");
        if (!empty($chkCampRows)) {
            $destCols = [];
            foreach (dash_fetch_all_prepared($con, "SHOW COLUMNS FROM `campanas_difusion_destinatarios`") as $rd) {
                if (!empty($rd['Field'])) $destCols[$rd['Field']] = true;
            }
            $cdCols = [];
            foreach (dash_fetch_all_prepared($con, "SHOW COLUMNS FROM `campanas_difusion`") as $rcd) {
                if (!empty($rcd['Field'])) $cdCols[$rcd['Field']] = true;
            }

            $dateCampCol = isset($cdCols['fecha_creacion']) ? 'cd.fecha_creacion' : 'cd.created_at';
            $costCampExpr = isset($destCols['costo_usd'])
                ? "CASE WHEN d.costo_usd > 0 THEN d.costo_usd WHEN cd.costo_unitario_usd > 0 THEN cd.costo_unitario_usd ELSE 0 END"
                : "CASE WHEN cd.costo_unitario_usd > 0 THEN cd.costo_unitario_usd ELSE 0 END";

            $camp_conds  = ["d.estado_envio IN ('ENVIADO', 'ENTREGADO', 'LEIDO', 'RESPONDIDO')"];
            $camp_types  = '';
            $camp_params = [];

            if ($sede_int > 0) {
                if (isset($destCols['id_sede'])) {
                    $camp_conds[]  = "(d.id_sede = ? OR (d.id_sede = 0 AND cd.id_sede = ?))";
                    $camp_types   .= "ii";
                    $camp_params[] = $sede_int;
                    $camp_params[] = $sede_int;
                } else {
                    $camp_conds[]  = "cd.id_sede = ?";
                    $camp_types   .= "i";
                    $camp_params[] = $sede_int;
                }
            }
            if ($desde_dt !== '') {
                $camp_conds[]  = "COALESCE(d.fecha_envio, $dateCampCol) >= ?";
                $camp_types   .= "s";
                $camp_params[] = $desde_dt;
            }
            if ($hasta_dt !== '') {
                $camp_conds[]  = "COALESCE(d.fecha_envio, $dateCampCol) <= ?";
                $camp_types   .= "s";
                $camp_params[] = $hasta_dt;
            }

            $q_gasto_camp = "
                SELECT 
                    COALESCE(SUM($costCampExpr), 0) AS gasto_camp,
                    GROUP_CONCAT(DISTINCT d.id_campana) AS camp_ids
                FROM campanas_difusion_destinatarios d
                LEFT JOIN campanas_difusion cd ON d.id_campana = cd.id
                WHERE " . implode(" AND ", $camp_conds);
            $gcRows = dash_fetch_all_prepared($con, $q_gasto_camp, $camp_types, $camp_params);
            if (!empty($gcRows)) {
                $row_gc = $gcRows[0];
                $gasto_real_total += floatval($row_gc['gasto_camp'] ?? 0);
                if (!empty($row_gc['camp_ids'])) {
                    foreach (explode(',', (string)$row_gc['camp_ids']) as $cid_raw) {
                        $cid_i = intval($cid_raw);
                        if ($cid_i > 0) {
                            $camp_ids_periodo[$cid_i] = $cid_i;
                        }
                    }
                }
            }
        }

        // Sumar mensajes facturables en mensajes_y_eventos
        $msg_conds  = ["m.es_pagado = 1", "m.origen != 'CLIENTE'"];
        $msg_types  = '';
        $msg_params = [];
        if ($desde_dt !== '') {
            $msg_conds[]  = "m.timestamp >= ?";
            $msg_types   .= "s";
            $msg_params[] = $desde_dt;
        }
        if ($hasta_dt !== '') {
            $msg_conds[]  = "m.timestamp <= ?";
            $msg_types   .= "s";
            $msg_params[] = $hasta_dt;
        }
        if ($sede_int > 0) {
            $msg_conds[]  = "lw.id_sede = ?";
            $msg_types   .= "i";
            $msg_params[] = $sede_int;
        }

        $join_sede_msg = ($sede_int > 0)
            ? "JOIN conversaciones c ON m.id_conversacion = c.id JOIN lineas_whatsapp lw ON c.id_linea = lw.id"
            : "";
        $q_gasto_msg = "
            SELECT COALESCE(SUM(COALESCE(m.costo_meta_estimado, 0)), 0) AS gasto_msg
            FROM mensajes_y_eventos m
            $join_sede_msg
            WHERE " . implode(" AND ", $msg_conds);
        $gmRows = dash_fetch_all_prepared($con, $q_gasto_msg, $msg_types, $msg_params);
        $gasto_msg_bruto = !empty($gmRows) ? floatval($gmRows[0]['gasto_msg'] ?? 0) : 0.0;

        if ($gasto_msg_bruto > 0 && !empty($camp_ids_periodo)) {
            $placeholders = implode(',', array_fill(0, count($camp_ids_periodo), '?'));
            $ov_conds  = [
                "d2.id_campana IN ($placeholders)",
                "d2.id_mensaje_meta IS NOT NULL",
                "d2.id_mensaje_meta != ''",
                "m.es_pagado = 1",
            ];
            $ov_types  = str_repeat('i', count($camp_ids_periodo));
            $ov_params = array_values($camp_ids_periodo);

            if ($desde_dt !== '') {
                $ov_conds[]  = "m.timestamp >= ?";
                $ov_types   .= "s";
                $ov_params[] = $desde_dt;
            }
            if ($hasta_dt !== '') {
                $ov_conds[]  = "m.timestamp <= ?";
                $ov_types   .= "s";
                $ov_params[] = $hasta_dt;
            }

            $q_overlap = "
                SELECT COALESCE(SUM(COALESCE(m.costo_meta_estimado, 0)), 0) AS gasto_duplicado
                FROM campanas_difusion_destinatarios d2
                JOIN mensajes_y_eventos m
                  ON m.id_mensaje_meta = CONVERT(d2.id_mensaje_meta USING utf8mb4) COLLATE utf8mb4_unicode_ci
                WHERE " . implode(" AND ", $ov_conds);
            $ovRows = dash_fetch_all_prepared($con, $q_overlap, $ov_types, $ov_params);
            if (!empty($ovRows)) {
                $gasto_msg_bruto = max(0.0, $gasto_msg_bruto - floatval($ovRows[0]['gasto_duplicado'] ?? 0));
            }
        }
        $gasto_real_total += $gasto_msg_bruto;
        $cac = ($ventas_cerradas > 0) ? round($gasto_real_total / $ventas_cerradas, 2) : 0;

        // 5. Lead Scoring Promedio
        $avg_lead = 0;
        $chkLeadRows = dash_fetch_all_prepared($con, "SHOW COLUMNS FROM `clientes_contactos` LIKE 'calificacion_calidad'");
        if (!empty($chkLeadRows)) {
            $lead_query = "SELECT AVG(cc.calificacion_calidad) AS avg_score FROM clientes_contactos cc JOIN conversaciones c ON cc.id = c.id_cliente LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where . " AND cc.calificacion_calidad > 0";
            $leadRows = dash_fetch_all_prepared($con, $lead_query, $base_types, $base_params);
            if (!empty($leadRows) && $leadRows[0]['avg_score'] !== null) {
                $avg_lead = round(floatval($leadRows[0]['avg_score']), 1);
            }
        }

        // 6. Desempeño por Operador
        $op_where_clauses = $where_clauses;
        $op_where_clauses[] = "c.id_agente IS NOT NULL AND c.id_agente > 0";
        $op_where_sql = " WHERE " . implode(" AND ", $op_where_clauses);
        $query4 = "
            SELECT COALESCE(up.nombre, u.usuario) AS nombre_completo, COUNT(c.id) AS chats_atendidos 
            FROM conversaciones c
            JOIN usuario u ON u.id = c.id_agente
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
            LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id
            $op_where_sql
            GROUP BY u.id 
            ORDER BY chats_atendidos DESC LIMIT 5
        ";
        $operadores = dash_fetch_all_prepared($con, $query4, $base_types, $base_params);

        // 7. Volumen de Chats por Día
        $chart_where_sql = ($where_sql === "") ? " WHERE c.fecha_inicio IS NOT NULL" : ($where_sql . " AND c.fecha_inicio IS NOT NULL");
        $query5 = "SELECT DATE(c.fecha_inicio) AS fecha, COUNT(c.id) AS volumen FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id $chart_where_sql GROUP BY DATE(c.fecha_inicio) ORDER BY fecha ASC";
        $chart_data = dash_fetch_all_prepared($con, $query5, $base_types, $base_params);

        // 8. Motivos de Cierre (Gráfico Embudo)
        $motivos_data = [];
        if ($hasResCom) {
            $motivos_query = "SELECT IFNULL(c.resultado_comercial, 'NO_ESPECIFICADO') AS motivo, COUNT(c.id) AS cantidad FROM conversaciones c LEFT JOIN lineas_whatsapp lw ON c.id_linea = lw.id" . $cerrados_where . " GROUP BY c.resultado_comercial";
            $motivos_data = dash_fetch_all_prepared($con, $motivos_query, $base_types, $base_params);
        }

        echo json_encode([
            'status' => 'success',
            'data'   => [
                'total_chats'     => $total_chats,
                'avg_res'         => $avg_res . " min",
                'conversion_rate' => $conversion . "%",
                'cac'             => "$" . number_format($cac, 2),
                'gasto_real_usd'  => round($gasto_real_total, 4),
                'lead_score'      => $avg_lead,
                'csat_score'      => $avg_csat,
                'operadores'      => $operadores,
                'chart_data'      => $chart_data,
                'motivos_data'    => $motivos_data,
            ],
        ]);
        break;

    case 'audit_export_excel':
        $agente = $agente_dashboard;
        $rol = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
        $user_sede = intval($agente['id_sede'] ?? 0);
        $is_master = ($rol === 'MASTER' || $rol === 'MASTER CI' || intval($agente['id'] ?? 0) === 1);

        $fecha_ini = trim((string)($_POST['fecha_inicio'] ?? ($_POST['fecha_desde'] ?? '')));
        $fecha_fin = trim((string)($_POST['fecha_fin'] ?? ($_POST['fecha_hasta'] ?? '')));
        $req_sede  = trim((string)($_POST['id_sede'] ?? 'all'));
        $sede_efectiva = (!$is_master && $user_sede > 0) ? (string)$user_sede : $req_sede;

        registrar_auditoria(
            'dashboard',
            'EXPORTAR_KPI_EXCEL',
            'Exportación ejecutiva de métricas y KPIs en libro Excel (.xlsx)',
            null,
            [
                'fecha_inicio' => $fecha_ini,
                'fecha_fin'    => $fecha_fin,
                'id_sede'      => $sede_efectiva,
            ],
            $con,
            intval($agente['id'] ?? 0),
            $user_sede > 0 ? $user_sede : null
        );

        echo json_encode([
            'status'  => 'success',
            'message' => 'Exportación registrada en auditoría.',
        ]);
        break;

    default:
        registrar_auditoria(
            'dashboard',
            'ACCION_NO_VALIDA',
            'Intento de acción no soportada en controlador de métricas',
            null,
            ['action' => $action],
            $con,
            intval($agente_dashboard['id'] ?? 0),
            intval($agente_dashboard['id_sede'] ?? 0) ?: null
        );
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
        break;
}
