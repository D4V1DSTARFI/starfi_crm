<?php
// modules/bandeja/back_bandeja.php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$agente_info_global = getAgenteInfo();
$agente_id = intval($_SESSION['agente_id'] ?? ($agente_info_global['id'] ?? 0));
// Liberar bloqueo de sesión inmediatamente para que load_chats, get_agents y sse_updates corran en paralelo sin bloquearse
session_write_close();

starfi_ensure_sprint2_schema($con);

// Ejecutar migraciones ligeras, indexación y TRIGGER de sincronización una sola vez
$migration_flag = sys_get_temp_dir() . '/starfi_bandeja_migrated_v4.flag';
if (!file_exists($migration_flag)) {
    @mysqli_query($con, "ALTER TABLE conversaciones ADD COLUMN resultado_comercial VARCHAR(100) DEFAULT NULL");
    @mysqli_query($con, "ALTER TABLE conversaciones ADD COLUMN fecha_cierre_venta DATETIME DEFAULT NULL");
    @mysqli_query($con, "ALTER TABLE conversaciones ADD COLUMN ultimo_msg_id INT NOT NULL DEFAULT 0");
    @mysqli_query($con, "ALTER TABLE conversaciones ADD INDEX idx_ultimo_msg (ultimo_msg_id, id)");
    @mysqli_query($con, "ALTER TABLE mensajes_y_eventos ADD COLUMN error_detalle TEXT DEFAULT NULL");
    @mysqli_query($con, "ALTER TABLE mensajes_y_eventos ADD COLUMN categoria_meta VARCHAR(50) DEFAULT NULL");
    @mysqli_query($con, "ALTER TABLE mensajes_y_eventos ADD INDEX idx_conv_origen (id_conversacion, origen)");
    @mysqli_query($con, "UPDATE conversaciones c JOIN (SELECT id_conversacion, MAX(id) AS max_id FROM mensajes_y_eventos GROUP BY id_conversacion) m ON c.id = m.id_conversacion SET c.ultimo_msg_id = m.max_id WHERE c.ultimo_msg_id < m.max_id");
    @mysqli_query($con, "DROP TRIGGER IF EXISTS trg_mensajes_ultimo_msg_ai");
    @mysqli_query($con, "CREATE TRIGGER trg_mensajes_ultimo_msg_ai AFTER INSERT ON mensajes_y_eventos FOR EACH ROW UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), NEW.id) WHERE id = NEW.id_conversacion");
    @file_put_contents($migration_flag, date('Y-m-d H:i:s'));
}

function sync_ultimo_msg_conversacion($con, $id_conversacion, $msg_id) {
    $cid = intval($id_conversacion);
    $mid = intval($msg_id);
    if ($cid > 0 && $mid > 0) {
        $con->query("UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), $mid) WHERE id = $cid AND ultimo_msg_id < $mid");
    }
}

/**
 * [COM-01] Calcula en tiempo real el estado de la Ventana Gratuita de 24h de Meta (basada en el último mensaje entrante del CLIENTE).
 */
function get_conversacion_window_24h($con, $conversacion_id) {
    $cid = intval($conversacion_id);
    $now_epoch = time();
    $res_now = $con->query("SELECT UNIX_TIMESTAMP(NOW()) AS now_ep");
    if ($res_now && ($rn = $res_now->fetch_assoc())) {
        $now_epoch = intval($rn['now_ep']);
    }
    $last_cli_epoch = 0;
    if ($cid > 0) {
        $stmt_w = $con->prepare("SELECT MAX(UNIX_TIMESTAMP(timestamp)) AS last_cli FROM mensajes_y_eventos WHERE id_conversacion = ? AND origen = 'CLIENTE'");
        if ($stmt_w) {
            $stmt_w->bind_param("i", $cid);
            $stmt_w->execute();
            $rw = $stmt_w->get_result()->fetch_assoc();
            $stmt_w->close();
            if ($rw && !empty($rw['last_cli'])) {
                $last_cli_epoch = intval($rw['last_cli']);
            }
        }
    }
    $expires_at = ($last_cli_epoch > 0) ? ($last_cli_epoch + 86400) : 0;
    $remaining = ($expires_at > $now_epoch) ? ($expires_at - $now_epoch) : 0;
    return [
        'server_now_epoch' => $now_epoch,
        'last_client_epoch' => $last_cli_epoch,
        'expires_at_epoch' => $expires_at,
        'remaining_sec' => $remaining,
        'is_open' => ($remaining > 0)
    ];
}

/**
 * [COM-02] Obtiene la oportunidad del Embudo Kanban vinculada a la conversación o al cliente.
 */
function get_conversacion_deal_info($con, $conversacion_id, $cliente_id = 0) {
    $cid = intval($conversacion_id);
    $clid = intval($cliente_id);
    if ($cid <= 0 && $clid <= 0) {
        return null;
    }
    $where = ($cid > 0 && $clid > 0)
        ? "(id_conversacion = $cid OR id_cliente = $clid)"
        : ($cid > 0 ? "id_conversacion = $cid" : "id_cliente = $clid");
    $order = ($cid > 0) ? "ORDER BY (id_conversacion = $cid) DESC, id DESC LIMIT 1" : "ORDER BY id DESC LIMIT 1";
    $res = @$con->query("SELECT id, etapa, monto_estimado_usd, titulo, probabilidad FROM pipeline_oportunidades WHERE $where $order");
    if ($res && ($row = $res->fetch_assoc())) {
        return [
            'id' => intval($row['id']),
            'etapa' => $row['etapa'],
            'monto_estimado_usd' => round(floatval($row['monto_estimado_usd']), 2),
            'titulo' => $row['titulo'],
            'probabilidad' => intval($row['probabilidad'])
        ];
    }
    return null;
}

function sanitize_bandeja_tpl_param($text) {
    $clean = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string)$text);
    $clean = preg_replace('/\s{2,}/', ' ', $clean);
    $clean = trim($clean);
    return $clean !== '' ? $clean : 'Cliente';
}

/**
 * Valida que el usuario actual tenga autorización por Sede y Rol sobre una conversación específica.
 */
function authorize_conversacion_access($con, $conversacion_id, $agente_info, $allow_waiting_for_operador = false) {
    $cid = intval($conversacion_id);
    if ($cid <= 0) {
        return false;
    }
    $ag_id = intval($agente_info['id'] ?? 0);
    $rol_raw = strtoupper(trim($agente_info['rol'] ?? ''));
    $is_master = ($ag_id === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI');
    if ($is_master) {
        return true;
    }
    $is_admin = ($rol_raw === 'ADMINISTRADOR' || $rol_raw === 'ADMIN' || $rol_raw === 'GERENTE');
    $user_sede = intval($agente_info['id_sede'] ?? 0);
    if ($user_sede <= 0) {
        return false;
    }

    $stmt = $con->prepare("
        SELECT c.id_agente, c.estado, l.id_sede
        FROM conversaciones c
        JOIN lineas_whatsapp l ON c.id_linea = l.id
        WHERE c.id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $cid);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return false;
    }
    if (intval($row['id_sede']) !== $user_sede) {
        return false;
    }
    if ($is_admin) {
        return true;
    }
    $conv_agente = intval($row['id_agente'] ?? 0);
    if ($conv_agente === $ag_id) {
        return true;
    }
    if ($allow_waiting_for_operador && ($conv_agente === 0 || $row['estado'] === 'ESPERA_ASIGNACION')) {
        return true;
    }
    return false;
}

function authorize_cliente_access($con, $cliente_id, $agente_info) {
    $cid = intval($cliente_id);
    if ($cid <= 0) {
        return false;
    }
    $ag_id = intval($agente_info['id'] ?? 0);
    $rol_raw = strtoupper(trim($agente_info['rol'] ?? ''));
    $is_master = ($ag_id === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI' || ($rol_raw === 'ADMIN' && intval($agente_info['id_sede'] ?? 0) === 0));
    if ($is_master) {
        return true;
    }
    $user_sede = intval($agente_info['id_sede'] ?? 0);
    if ($user_sede <= 0) {
        return false;
    }

    $stmt = $con->prepare("
        SELECT 1
        FROM clientes_contactos cl
        WHERE cl.id = ?
          AND (
              cl.id_sede = ?
              OR EXISTS (
                  SELECT 1
                  FROM conversaciones c
                  JOIN lineas_whatsapp l ON c.id_linea = l.id
                  WHERE c.id_cliente = cl.id AND l.id_sede = ?
              )
          )
        LIMIT 1
    ");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("iii", $cid, $user_sede, $user_sede);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = ($res && $res->num_rows > 0);
    $stmt->close();
    return $ok;
}

function parse_meta_error_detail($res_meta, $status_code = 0) {
    if (isset($res_meta['error'])) {
        $code = $res_meta['error']['code'] ?? '';
        $msg = $res_meta['error']['message'] ?? '';
        $details = $res_meta['error']['error_data']['details'] ?? '';
        
        if ($code == 131047) {
            return "Ventana de 24 horas excedida (Error Meta 131047). El cliente debe enviar un mensaje primero o utilizar una plantilla aprobada.";
        } elseif ($code == 131026) {
            return "Mensaje no entregable (Error Meta 131026). El número de teléfono no posee WhatsApp activo o fue rechazado.";
        } elseif ($code == 131049) {
            return "Mensaje no entregado (Error Meta 131049). Restricción por salud de ecosistema o políticas de Meta.";
        } elseif ($code == 130472) {
            return "Restricción de Meta (Error 130472). El número forma parte de una prueba o restricción.";
        } else {
            return "Rechazado por Meta (Error $code): " . ($details ? $details : $msg);
        }
    }
    return "Fallo de conexión o HTTP $status_code con Meta WhatsApp Cloud API.";
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'get_respuestas_rapidas':
        $res = $con->query("SELECT titulo, mensaje FROM respuestas_rapidas WHERE estado = 'ACTIVO' ORDER BY id DESC");
        $data = [];
        if($res){
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'get_bot_respuestas':
        $agente = $agente_info_global;
        $rol = $agente['rol'] ?? 'AGENTE';
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        
        $id_sede = 2; // Default to Sede 2 (SUPERFORMICA) if MASTER or Sede 1 referenced
        if ($rol !== 'MASTER' && $user_sede > 0) {
            $id_sede = $user_sede;
        }
        
        $stmt = $con->prepare("SELECT disparador, mensaje FROM bot_respuestas WHERE id_sede = ? AND estado = 'ACTIVO' ORDER BY disparador ASC");
        $data = [];
        if ($stmt) {
            $stmt->bind_param("i", $id_sede);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'create_quick_reply':
        $titulo = trim($_POST['titulo'] ?? '');
        $mensaje = trim($_POST['mensaje'] ?? '');
        if (!empty($titulo) && !empty($mensaje)) {
            $agente = $agente_info_global;
            $id_sede = $agente['id_sede'] ?? 1; // Fallback to 1 if no sede is found

            $stmt = $con->prepare("INSERT INTO respuestas_rapidas (id_sede, titulo, mensaje, estado) VALUES (?, ?, ?, 'ACTIVO')");
            if ($stmt) {
                $stmt->bind_param("iss", $id_sede, $titulo, $mensaje);
                if ($stmt->execute()) {
                    echo json_encode(['status' => 'success']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Error al guardar la respuesta']);
                }
                $stmt->close();
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error de base de datos']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Título y mensaje son obligatorios']);
        }
        break;

    case 'load_chats':
        $filter = $_POST['filter'] ?? 'mis-chats';
        
        $join_condition = "AND c.estado != 'CERRADO'";
        if ($filter === 'cerrados') {
            $join_condition = "AND c.estado = 'CERRADO'";
        } elseif ($filter === 'ventas' || $filter === 'campanas' || $filter === 'todos') {
            $join_condition = ""; // Permite listar conversaciones tanto abiertas como cerradas
        }

        // FASE 1: Paginación indexada ligera (sin escanear textos de mensajes_y_eventos en toda la tabla)
        // Nota: En InnoDB, KEY(id_conversacion) incluye implícitamente el PRIMARY KEY(id) -> (id_conversacion, id),
        // por lo que (SELECT MAX(id) FROM mensajes_y_eventos WHERE id_conversacion = c.id) es un lookup O(1) "Using index".
        $where_clauses = "WHERE cl.id_empresa = 1";
        
        if ($filter === 'mis-chats') {
            $where_clauses .= " AND c.id_agente = $agente_id";
        } elseif ($filter === 'clientes') {
            $where_clauses .= " AND EXISTS (SELECT 1 FROM mensajes_y_eventos me WHERE me.id_conversacion = c.id AND me.origen = 'CLIENTE')";
        } elseif ($filter === 'campanas') {
            $where_clauses .= " AND EXISTS (SELECT 1 FROM mensajes_y_eventos me_camp WHERE me_camp.id_conversacion = c.id AND me_camp.origen = 'API_TRANSACCIONAL' AND (me_camp.contenido LIKE '%starfi_difusion_directa%' OR me_camp.contenido LIKE '%[DIFUSIÓN:%' OR me_camp.contenido LIKE '%| Texto:%' OR IFNULL(me_camp.categoria_meta, '') = 'MARKETING'))";
        } elseif ($filter === 'ventas') {
            $where_clauses .= " AND (
                c.resultado_comercial = 'VENTA_CERRADA' 
                OR c.resultado_comercial LIKE '%VENTA%'
                OR c.fecha_cierre_venta IS NOT NULL
                OR EXISTS (SELECT 1 FROM mensajes_y_eventos me WHERE me.id_conversacion = c.id AND me.origen = 'API_TRANSACCIONAL' AND me.contenido NOT LIKE '%starfi_difusion_directa%' AND me.contenido NOT LIKE '%[DIFUSIÓN:%' AND me.contenido NOT LIKE '%| Texto:%' AND IFNULL(me.categoria_meta, '') != 'MARKETING')
            )";
        } elseif ($filter === 'no-leido') {
            $where_clauses .= " AND c.mensajes_no_leidos > 0";
        } elseif ($filter === 'sla-critico') {
            $where_clauses .= " AND c.estado NOT IN ('CERRADO', 'RESUELTO') AND EXISTS (
                SELECT 1 FROM mensajes_y_eventos mc
                WHERE mc.id_conversacion = c.id
                  AND mc.origen = 'CLIENTE'
                  AND mc.timestamp <= (NOW() - INTERVAL 15 MINUTE)
                  AND NOT EXISTS (
                      SELECT 1 FROM mensajes_y_eventos ma
                      WHERE ma.id_conversacion = c.id
                        AND ma.origen = 'AGENTE'
                        AND ma.timestamp >= mc.timestamp
                  )
            )";
        } elseif ($filter === 'cerrados') {
            $where_clauses .= " AND c.estado = 'CERRADO'";
        }
        
        $agente = $agente_info_global;
        $agente_id_actual = intval($agente['id'] ?? $agente_id);
        $rol_raw = strtoupper(trim($agente['rol'] ?? ''));
        $is_master = ($agente_id_actual === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI');
        $is_admin = (!$is_master && ($rol_raw === 'ADMINISTRADOR' || $rol_raw === 'ADMIN' || $rol_raw === 'GERENTE'));
        $is_operador = (!$is_master && !$is_admin);
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        
        // RESTRICCIÓN PARA OPERADOR: No puede ver Ventas ni Clientes, y sólo puede ver mensajes asignados a él
        if ($is_operador) {
            if ($filter === 'ventas' || $filter === 'clientes') {
                echo json_encode(['status' => 'success', 'data' => [], 'page' => 1, 'limit' => 20, 'has_more' => false]);
                break;
            }
            $where_clauses .= " AND c.id_agente = $agente_id_actual";
        } else {
            $selected_agente = intval($_POST['id_agente'] ?? 0);
            if ($selected_agente > 0) {
                $where_clauses .= " AND c.id_agente = $selected_agente";
            }
        }

        if (!$is_master && $user_sede > 0) {
            $where_clauses .= " AND l.id_sede = $user_sede";
        } else {
            $id_sede = isset($_POST['id_sede']) ? intval($_POST['id_sede']) : 0;
            if ($id_sede > 0) {
                $where_clauses .= " AND l.id_sede = $id_sede";
            }
        }

        $search_query = trim($_POST['search_query'] ?? '');
        $search_phone = preg_replace('/[^0-9]/', '', trim($_POST['search_phone'] ?? ''));
        $target_conv_id = intval($_POST['target_conv_id'] ?? 0);

        if ($target_conv_id > 0) {
            $where_clauses .= " AND c.id = $target_conv_id";
        } elseif ($search_query !== '') {
            $sq_esc = $con->real_escape_string($search_query);
            $sq_digits = preg_replace('/[^0-9]/', '', $search_query);
            if ($sq_digits !== '') {
                $sq_digits_esc = $con->real_escape_string($sq_digits);
                $where_clauses .= " AND (cl.nombre LIKE '%$sq_esc%' OR cl.numero_whatsapp LIKE '%$sq_digits_esc%')";
            } else {
                $where_clauses .= " AND (cl.nombre LIKE '%$sq_esc%' OR cl.numero_whatsapp LIKE '%$sq_esc%')";
            }
        } elseif ($search_phone !== '') {
            $search_esc = $con->real_escape_string($search_phone);
            $where_clauses .= " AND cl.numero_whatsapp LIKE '%$search_esc%'";
        }

        $page = max(1, intval($_POST['page'] ?? 1));
        $limit = max(1, min(50, intval($_POST['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $fetch_limit = $limit + 1;

        $query = "
            SELECT 
                c.id, 
                cl.id AS id_cliente, 
                cl.nombre AS cliente_nombre, 
                cl.numero_whatsapp, 
                IFNULL(c.estado, 'SIN INICIAR') AS estado, 
                IFNULL(c.fecha_inicio, cl.fecha_registro) AS fecha_inicio,
                IFNULL(c.mensajes_no_leidos, 0) AS no_leidos,
                IFNULL(s.nombre_sede, 'Sede Principal') AS nombre_sede,
                up.nombre AS nombre_asesor,
                IFNULL(cl.calificacion_calidad, 0) AS calificacion_calidad,
                c.resultado_comercial,
                c.fecha_cierre_venta,
                c.ultimo_msg_id
            FROM conversaciones c
            JOIN clientes_contactos cl ON cl.id = c.id_cliente $join_condition
            LEFT JOIN lineas_whatsapp l ON c.id_linea = l.id
            LEFT JOIN sedes s ON l.id_sede = s.id
            LEFT JOIN usuario_perfil up ON c.id_agente = up.id_usuario
            $where_clauses
            ORDER BY c.ultimo_msg_id DESC, c.id DESC
            LIMIT $fetch_limit OFFSET $offset
        ";

        $res = $con->query($query);
        
        $chats = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $chats[] = $row;
            }
        }

        $has_more = false;
        if (count($chats) > $limit) {
            $has_more = true;
            array_pop($chats);
        }

        // Reloj del servidor en epoch para sincronización exacta de Ventana Meta 24h ([COM-01]) y Semáforo SLA ([COM-04])
        $server_now_epoch = time();
        $resNow = $con->query("SELECT UNIX_TIMESTAMP(NOW()) AS now_ep");
        if ($resNow && ($rn = $resNow->fetch_assoc())) {
            $server_now_epoch = intval($rn['now_ep']);
        }

        // FASE 2: Enriquecer ÚNICAMENTE las 20 conversaciones de la página actual (evita escanear miles de filas)
        if (!empty($chats)) {
            $conv_ids = [];
            $last_msg_ids = [];
            foreach ($chats as $ch) {
                $cid = intval($ch['id']);
                if ($cid > 0) $conv_ids[] = $cid;
                $mid = intval($ch['ultimo_msg_id'] ?? 0);
                if ($mid > 0) $last_msg_ids[] = $mid;
            }

            // 2A. Obtener texto, timestamp y origen del último mensaje por PRIMARY KEY (instantáneo)
            $last_msgs_map = [];
            if (!empty($last_msg_ids)) {
                $mids_csv = implode(',', array_unique($last_msg_ids));
                $resLast = $con->query("SELECT id, id_conversacion, timestamp, contenido, origen FROM mensajes_y_eventos WHERE id IN ($mids_csv)");
                if ($resLast) {
                    while ($rm = $resLast->fetch_assoc()) {
                        $last_msgs_map[intval($rm['id'])] = $rm;
                    }
                }
            }

            // 2B. Detectar banderas es_campana y es_venta solo para las 20 conversaciones visibles
            $flags_map = [];
            // 2C. [COM-01] Ventana Meta 24h y [COM-04] Semáforo SLA sin respuesta humana (usando idx_conv_origen_ts)
            $win_sla_map = [];
            $sla_wait_map = [];
            // 2D. [COM-02] Etapa y Monto USD del Embudo de Ventas
            $deals_map = [];

            if (!empty($conv_ids)) {
                $cids_csv = implode(',', array_unique($conv_ids));
                $resFlags = $con->query("
                    SELECT 
                        id_conversacion,
                        MAX(CASE WHEN contenido LIKE '%starfi_difusion_directa%' OR contenido LIKE '%[DIFUSIÓN:%' OR contenido LIKE '%| Texto:%' OR IFNULL(categoria_meta, '') = 'MARKETING' THEN 1 ELSE 0 END) AS flag_campana,
                        MAX(CASE WHEN contenido NOT LIKE '%starfi_difusion_directa%' AND contenido NOT LIKE '%[DIFUSIÓN:%' AND contenido NOT LIKE '%| Texto:%' AND IFNULL(categoria_meta, '') != 'MARKETING' THEN 1 ELSE 0 END) AS flag_venta
                    FROM mensajes_y_eventos
                    WHERE id_conversacion IN ($cids_csv)
                      AND origen = 'API_TRANSACCIONAL'
                    GROUP BY id_conversacion
                ");
                if ($resFlags) {
                    while ($rf = $resFlags->fetch_assoc()) {
                        $flags_map[intval($rf['id_conversacion'])] = $rf;
                    }
                }

                $resWinSla = $con->query("
                    SELECT 
                        id_conversacion,
                        MAX(CASE WHEN origen = 'CLIENTE' THEN UNIX_TIMESTAMP(timestamp) ELSE NULL END) AS last_cli_ep,
                        MAX(CASE WHEN origen = 'AGENTE' THEN UNIX_TIMESTAMP(timestamp) ELSE NULL END) AS last_ag_ep
                    FROM mensajes_y_eventos
                    WHERE id_conversacion IN ($cids_csv)
                      AND origen IN ('CLIENTE', 'AGENTE')
                    GROUP BY id_conversacion
                ");
                if ($resWinSla) {
                    while ($rw = $resWinSla->fetch_assoc()) {
                        $win_sla_map[intval($rw['id_conversacion'])] = $rw;
                    }
                }

                $resSlaWait = $con->query("
                    SELECT m.id_conversacion,
                           MIN(UNIX_TIMESTAMP(m.timestamp)) AS wait_since_ep
                    FROM mensajes_y_eventos m
                    LEFT JOIN (
                        SELECT id_conversacion, MAX(timestamp) AS max_ag_ts
                        FROM mensajes_y_eventos
                        WHERE id_conversacion IN ($cids_csv)
                          AND origen = 'AGENTE'
                        GROUP BY id_conversacion
                    ) ag ON ag.id_conversacion = m.id_conversacion
                    WHERE m.id_conversacion IN ($cids_csv)
                      AND m.origen = 'CLIENTE'
                      AND (ag.max_ag_ts IS NULL OR m.timestamp > ag.max_ag_ts)
                    GROUP BY m.id_conversacion
                ");
                if ($resSlaWait) {
                    while ($rsw = $resSlaWait->fetch_assoc()) {
                        $sla_wait_map[intval($rsw['id_conversacion'])] = intval($rsw['wait_since_ep']);
                    }
                }

                $resDeals = @$con->query("
                    SELECT p.id, p.id_conversacion, p.etapa, p.monto_estimado_usd
                    FROM pipeline_oportunidades p
                    INNER JOIN (
                        SELECT id_conversacion, MAX(id) AS max_pid
                        FROM pipeline_oportunidades
                        WHERE id_conversacion IN ($cids_csv)
                        GROUP BY id_conversacion
                    ) pm ON pm.max_pid = p.id
                ");
                if ($resDeals) {
                    while ($rd = $resDeals->fetch_assoc()) {
                        $deals_map[intval($rd['id_conversacion'])] = $rd;
                    }
                }
            }

            foreach ($chats as &$ch) {
                $cid = intval($ch['id']);
                $mid = intval($ch['ultimo_msg_id'] ?? 0);
                $lm = ($mid > 0 && isset($last_msgs_map[$mid])) ? $last_msgs_map[$mid] : null;
                $fl = isset($flags_map[$cid]) ? $flags_map[$cid] : null;
                $ws = isset($win_sla_map[$cid]) ? $win_sla_map[$cid] : null;
                $dl = isset($deals_map[$cid]) ? $deals_map[$cid] : null;

                $ch['ultimo_mensaje_ts']     = $lm ? $lm['timestamp'] : $ch['fecha_inicio'];
                $ch['ultimo_mensaje_texto']  = $lm ? $lm['contenido'] : null;
                $ch['ultimo_mensaje_origen'] = $lm ? $lm['origen'] : null;

                $es_venta_comercial = ($ch['resultado_comercial'] === 'VENTA_CERRADA' || !empty($ch['fecha_cierre_venta']));
                $ch['es_campana'] = ($fl && intval($fl['flag_campana']) === 1) ? 1 : 0;
                $ch['es_venta']   = ($es_venta_comercial || ($fl && intval($fl['flag_venta']) === 1)) ? 1 : 0;

                // [COM-01] Ventana Meta 24h
                $last_cli_ep = ($ws && !empty($ws['last_cli_ep'])) ? intval($ws['last_cli_ep']) : 0;
                $last_ag_ep  = ($ws && !empty($ws['last_ag_ep'])) ? intval($ws['last_ag_ep']) : 0;
                $exp_ep      = ($last_cli_ep > 0) ? ($last_cli_ep + 86400) : 0;
                $rem_sec     = ($exp_ep > $server_now_epoch) ? ($exp_ep - $server_now_epoch) : 0;
                $ch['last_client_epoch']        = $last_cli_ep;
                $ch['window_24h_open']          = ($rem_sec > 0) ? 1 : 0;
                $ch['window_24h_remaining_sec'] = $rem_sec;

                // [COM-04] Semáforo SLA en vivo (solo conversaciones abiertas)
                $is_closed = in_array(strtoupper((string)$ch['estado']), ['CERRADO', 'RESUELTO'], true);
                $wait_since_ep = (!$is_closed && isset($sla_wait_map[$cid])) ? intval($sla_wait_map[$cid]) : 0;
                $ch['sla_wait_since_epoch'] = $wait_since_ep;
                $ch['sla_human_replied']    = (!$is_closed && $last_cli_ep > 0 && $wait_since_ep === 0 && $last_ag_ep >= $last_cli_ep) ? 1 : 0;

                // [COM-02] Embudo Kanban
                $ch['deal_id']        = $dl ? intval($dl['id']) : 0;
                $ch['deal_etapa']     = $dl ? $dl['etapa'] : null;
                $ch['deal_monto_usd'] = $dl ? round(floatval($dl['monto_estimado_usd']), 2) : null;

                unset($ch['ultimo_msg_id'], $ch['resultado_comercial'], $ch['fecha_cierre_venta']);
            }
            unset($ch);
        }

        echo json_encode([
            'status' => 'success', 
            'data' => $chats,
            'page' => $page,
            'limit' => $limit,
            'has_more' => $has_more,
            'server_now_epoch' => $server_now_epoch
        ]);
        break;

    case 'load_messages':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $agente = $agente_info_global;
        $agente_id_actual = intval($agente['id'] ?? $agente_id);
        $rol_raw = strtoupper(trim($agente['rol'] ?? ''));
        $is_master = ($agente_id_actual === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI');
        $is_admin = (!$is_master && ($rol_raw === 'ADMINISTRADOR' || $rol_raw === 'ADMIN' || $rol_raw === 'GERENTE'));
        $is_operador = (!$is_master && !$is_admin);

        if ($conversacion_id > 0 && !authorize_conversacion_access($con, $conversacion_id, $agente_info_global, false)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Acceso denegado. No tienes autorización para esta conversación o sede.']);
            break;
        }
        
        $query = "
            SELECT m.id, m.tipo, m.origen, m.contenido, m.timestamp, m.estado_envio, m.url_archivo, m.reply_to_text, m.id_mensaje_meta, m.error_detalle, up.nombre as nombre_agente 
            FROM mensajes_y_eventos m
            LEFT JOIN usuario_perfil up ON m.id_agente = up.id_usuario
            WHERE m.id_conversacion = ? 
            ORDER BY m.timestamp ASC
        ";
        $stmt = $con->prepare($query);
        if ($stmt) {
            $stmt->bind_param("i", $conversacion_id);
            $stmt->execute();
            $res = $stmt->get_result();
            
            // Marcar mensajes como leídos solo si es OPERADOR al abrir.
            // Para Administrador/Master se mantiene sin leer a menos que respondan.
            if ($is_operador) {
                $con->query("UPDATE conversaciones SET mensajes_no_leidos = 0 WHERE id = $conversacion_id");
            }
            
            $messages = [];
            while ($row = $res->fetch_assoc()) {
                // Formatting contact strings sent by BOT
                if ($row['origen'] == 'BOT' && strpos($row['contenido'], 'Contacto enviado:') === 0) {
                    $row['tipo'] = 'CONTACTO'; // New type to help UI identify
                }
                $messages[] = $row;
            }
            $win24 = get_conversacion_window_24h($con, $conversacion_id);
            $deal_info = get_conversacion_deal_info($con, $conversacion_id, intval($_POST['cliente_id'] ?? 0));
            echo json_encode([
                'status' => 'success',
                'data' => $messages,
                'window_24h' => $win24,
                'deal' => $deal_info
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error preparando consulta.']);
        }
        break;
        
    case 'send_message':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $contenido = trim($_POST['contenido'] ?? '');
        $is_internal = intval($_POST['is_internal'] ?? 0);
        
        if (empty($contenido) || ($conversacion_id <= 0 && $cliente_id <= 0)) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos.']);
            exit;
        }

        // [COM-01] Bloqueo automático estricto si la Ventana Meta 24h está cerrada (salvo Notas Internas privadas)
        if ($is_internal !== 1) {
            if ($conversacion_id > 0) {
                if (!authorize_conversacion_access($con, $conversacion_id, $agente_info_global, true)) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para responder en esta conversación o sede.']);
                    exit;
                }
                $win_chk = get_conversacion_window_24h($con, $conversacion_id);
                if (!$win_chk['is_open']) {
                    echo json_encode([
                        'status' => 'error',
                        'code' => 'WINDOW_24H_EXPIRED',
                        'message' => 'La ventana gratuita de 24h de Meta ha expirado. Usa el botón "Reactivar con Plantilla Oficial Meta" para volver a escribirle al cliente.'
                    ]);
                    exit;
                }
            } else {
                echo json_encode([
                    'status' => 'error',
                    'code' => 'WINDOW_24H_EXPIRED',
                    'message' => 'Este contacto aún no tiene ventana activa de 24h. Inicia o reactiva la conversación con una Plantilla Oficial Meta.'
                ]);
                exit;
            }
        }

        $is_new_chat = false;
        // Si no hay conversación activa, la creamos respetando la sede del agente / cliente
        if ($conversacion_id <= 0 && $cliente_id > 0) {
            if (!authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
                exit;
            }
            $agente_info = $agente_info_global;
            $ag_id_chk = intval($agente_info['id'] ?? 0);
            $rol_chk = strtoupper(trim($agente_info['rol'] ?? ''));
            $is_master_chk = ($ag_id_chk === 1 || $rol_chk === 'MASTER' || $rol_chk === 'MASTER CI' || ($rol_chk === 'ADMIN' && intval($agente_info['id_sede'] ?? 0) === 0));
            $user_sede = isset($agente_info['id_sede']) ? intval($agente_info['id_sede']) : 0;
            
            $id_linea = null;
            if ($user_sede > 0) {
                $resLinea = $con->query("SELECT id FROM lineas_whatsapp WHERE id_sede = $user_sede AND (estado = 'ACTIVO' OR estado_conexion = 'CONECTADO' OR estado = 'CONECTADO') LIMIT 1");
                if ($resLinea && $resLinea->num_rows > 0) {
                    $id_linea = intval($resLinea->fetch_assoc()['id']);
                }
            }
            if (!$id_linea && $is_master_chk) {
                $resCliSede = $con->query("SELECT id_sede FROM clientes_contactos WHERE id = $cliente_id LIMIT 1");
                $cli_sede = ($resCliSede && $resCliSede->num_rows > 0) ? intval($resCliSede->fetch_assoc()['id_sede'] ?? 0) : 0;
                if ($cli_sede > 0) {
                    $resLinea = $con->query("SELECT id FROM lineas_whatsapp WHERE id_sede = $cli_sede AND (estado = 'ACTIVO' OR estado_conexion = 'CONECTADO' OR estado = 'CONECTADO') LIMIT 1");
                    if ($resLinea && $resLinea->num_rows > 0) {
                        $id_linea = intval($resLinea->fetch_assoc()['id']);
                    }
                }
            }
            if (!$id_linea) {
                echo json_encode(['status' => 'error', 'message' => 'No hay una línea de WhatsApp configurada y activa exclusivamente para esta sede. No se puede iniciar el chat cruzando líneas de otra sede.']);
                exit;
            }
            
            $stmt = $con->prepare("INSERT INTO conversaciones (id_linea, id_cliente, id_agente, estado) VALUES (?, ?, ?, 'ATENDIENDO')");
            $stmt->bind_param("iii", $id_linea, $cliente_id, $agente_id);
            $stmt->execute();
            $conversacion_id = $stmt->insert_id;
            $is_new_chat = true;
        } else {
            if (!authorize_conversacion_access($con, $conversacion_id, $agente_info_global, true)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para responder en esta conversación o sede.']);
                exit;
            }
            // Auto-assign to agent if replying to a waiting chat
            $con->query("UPDATE conversaciones SET estado = 'ATENDIENDO', id_agente = $agente_id, fecha_primera_respuesta = IFNULL(fecha_primera_respuesta, NOW()) WHERE id = $conversacion_id AND (id_agente IS NULL OR estado = 'ESPERA_ASIGNACION')");
        }
        
        $is_internal = intval($_POST['is_internal'] ?? 0);
        $id_mensaje_meta = null;
        $origen = 'AGENTE';
        $tipo = 'TEXTO';
        
        if ($is_internal === 1) {
            $origen = 'NOTA_INTERNA';
            // Saltamos todo el envío a la API de WhatsApp, solo se guarda local
        } else {
            // -------------------------------------------------------------
            // INICIO BLOQUE ENVÍO A WHATSAPP CLOUD API
            // -------------------------------------------------------------
            $queryChat = "
                SELECT c.id_cliente, cl.numero_whatsapp, l.meta_token, l.meta_app_id as phone_number_id
                FROM conversaciones c
                JOIN clientes_contactos cl ON c.id_cliente = cl.id
                JOIN lineas_whatsapp l ON c.id_linea = l.id
                WHERE c.id = $conversacion_id
            ";
            $resChat = $con->query($queryChat);
            $chat_data = $resChat ? $resChat->fetch_assoc() : null;

            if ($chat_data && !empty($chat_data['meta_token']) && $chat_data['meta_token'] !== 'temp_token') {
                $numero_destino = preg_replace('/[^0-9]/', '', $chat_data['numero_whatsapp']);
                $meta_token = $chat_data['meta_token'];
                $phone_number_id = $chat_data['phone_number_id'];

                $url = "https://graph.facebook.com/" . (defined('META_API_VERSION') ? META_API_VERSION : 'v23.0') . "/{$phone_number_id}/messages";
                
                $post_data = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $numero_destino,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $contenido
                    ]
                ];
                
                $reply_meta_id = $_POST['reply_to_meta_id'] ?? '';
                if (!empty($reply_meta_id)) {
                    $post_data['context'] = [
                        'message_id' => $reply_meta_id
                    ];
                }

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $meta_token,
                    'Content-Type: application/json'
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout rápido para no bloquear
                $response = curl_exec($ch);
                $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                $res_meta = json_decode($response, true);
                if (isset($res_meta['messages'][0]['id'])) {
                    $id_mensaje_meta = $res_meta['messages'][0]['id'];
                    $estado_envio = 'ENVIADO';
                } else {
                    $estado_envio = 'FALLIDO';
                    $error_detalle = parse_meta_error_detail($res_meta, $status_code);
                }
            }
            // -------------------------------------------------------------
            // FIN BLOQUE ENVÍO
            // -------------------------------------------------------------
        }
        
        $reply_to_meta_id = $_POST['reply_to_meta_id'] ?? null;
        $reply_to_text = $_POST['reply_to_text'] ?? null;
        if(empty($reply_to_meta_id)) $reply_to_meta_id = null;
        if(empty($reply_to_text)) $reply_to_text = null;
        
        $query = "INSERT INTO mensajes_y_eventos (id_conversacion, tipo, origen, id_agente, contenido, id_mensaje_meta, reply_to_meta_id, reply_to_text, estado_envio, error_detalle) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $con->prepare($query);
        if ($stmt) {
            $stmt->bind_param("ississssss", $conversacion_id, $tipo, $origen, $agente_id, $contenido, $id_mensaje_meta, $reply_to_meta_id, $reply_to_text, $estado_envio, $error_detalle);
            if ($stmt->execute()) {
                $new_msg_id = intval($stmt->insert_id);
                // Al responder (ya sea Operador, Administrador o Master), la conversación se marca como leída y sincroniza ultimo_msg_id
                $con->query("UPDATE conversaciones SET mensajes_no_leidos = 0, ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), $new_msg_id) WHERE id = $conversacion_id");
                echo json_encode(['status' => 'success', 'message_id' => $new_msg_id, 'new_chat_id' => ($is_new_chat ? $conversacion_id : null)]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Fallo al guardar mensaje en BD.']);
            }
        }
        break;

    case 'upload_media':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $cliente_id = intval($_POST['cliente_id'] ?? 0);

        if ($conversacion_id <= 0 && $cliente_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Datos de destinatario incompletos.']);
            exit;
        }
        if ($conversacion_id > 0 && !authorize_conversacion_access($con, $conversacion_id, $agente_info_global, true)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre esta conversación o sede.']);
            exit;
        }
        // [COM-01] Bloquear adjuntos fuera de la ventana de 24h de Meta
        if ($conversacion_id <= 0 || !get_conversacion_window_24h($con, $conversacion_id)['is_open']) {
            echo json_encode([
                'status' => 'error',
                'code' => 'WINDOW_24H_EXPIRED',
                'message' => 'La ventana gratuita de 24h de Meta ha expirado. Reactiva primero la conversación con una Plantilla Oficial Meta.'
            ]);
            exit;
        }
        $id_linea_upload = null;
        if ($conversacion_id <= 0 && $cliente_id > 0) {
            if (!authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
                exit;
            }
            $agente_info = $agente_info_global;
            $ag_id_chk = intval($agente_info['id'] ?? 0);
            $rol_chk = strtoupper(trim($agente_info['rol'] ?? ''));
            $is_master_chk = ($ag_id_chk === 1 || $rol_chk === 'MASTER' || $rol_chk === 'MASTER CI' || ($rol_chk === 'ADMIN' && intval($agente_info['id_sede'] ?? 0) === 0));
            $user_sede = isset($agente_info['id_sede']) ? intval($agente_info['id_sede']) : 0;

            if ($user_sede > 0) {
                $resLinea = $con->query("SELECT id FROM lineas_whatsapp WHERE id_sede = $user_sede AND (estado = 'ACTIVO' OR estado_conexion = 'CONECTADO' OR estado = 'CONECTADO') LIMIT 1");
                if ($resLinea && $resLinea->num_rows > 0) {
                    $id_linea_upload = intval($resLinea->fetch_assoc()['id']);
                }
            }
            if (!$id_linea_upload && $is_master_chk) {
                $resCliSede = $con->query("SELECT id_sede FROM clientes_contactos WHERE id = $cliente_id LIMIT 1");
                $cli_sede = ($resCliSede && $resCliSede->num_rows > 0) ? intval($resCliSede->fetch_assoc()['id_sede'] ?? 0) : 0;
                if ($cli_sede > 0) {
                    $resLinea = $con->query("SELECT id FROM lineas_whatsapp WHERE id_sede = $cli_sede AND (estado = 'ACTIVO' OR estado_conexion = 'CONECTADO' OR estado = 'CONECTADO') LIMIT 1");
                    if ($resLinea && $resLinea->num_rows > 0) {
                        $id_linea_upload = intval($resLinea->fetch_assoc()['id']);
                    }
                }
            }
            if (!$id_linea_upload) {
                echo json_encode(['status' => 'error', 'message' => 'No hay una línea de WhatsApp configurada y activa exclusivamente para esta sede. No se puede enviar multimedia cruzando líneas de otra sede.']);
                exit;
            }
        }
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['status' => 'error', 'message' => 'Error al subir archivo.']);
            exit;
        }
        
        $file = $_FILES['file'];
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', pathinfo($file['name'], PATHINFO_EXTENSION)));
        $mime = @mime_content_type($file['tmp_name']);
        
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'video/mp4'];
        if (!$mime || !in_array($mime, $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Tipo de archivo no permitido.']);
            exit;
        }

        $filename = uniqid('media_') . '.' . $ext;
        $dest_path = __DIR__ . '/../../assets/uploads/' . $filename;
        
        if (@move_uploaded_file($file['tmp_name'], $dest_path)) {
            $url_archivo = '/starfi_crm/assets/uploads/' . $filename;
            $tipo_bd = (strpos($mime, 'image') !== false) ? 'IMAGEN' : 'DOCUMENTO';
            $contenido = $file['name'];
            
            $is_new_chat = false;
            // Si no hay conversación activa, la creamos con la línea validada de su sede
            if ($conversacion_id <= 0 && $cliente_id > 0) {
                $stmt = $con->prepare("INSERT INTO conversaciones (id_linea, id_cliente, id_agente, estado) VALUES (?, ?, ?, 'ATENDIENDO')");
                $stmt->bind_param("iii", $id_linea_upload, $cliente_id, $agente_id);
                $stmt->execute();
                $conversacion_id = $stmt->insert_id;
                $is_new_chat = true;
            } else {
                $con->query("UPDATE conversaciones SET estado = 'ATENDIENDO', id_agente = $agente_id, fecha_primera_respuesta = IFNULL(fecha_primera_respuesta, NOW()) WHERE id = $conversacion_id AND (id_agente IS NULL OR estado = 'ESPERA_ASIGNACION')");
            }
            
            // -------------------------------------------------------------
            // INICIO BLOQUE ENVÍO A WHATSAPP CLOUD API (MULTIMEDIA)
            // -------------------------------------------------------------
            $queryChat = "
                SELECT c.id_cliente, cl.numero_whatsapp, l.meta_token, l.meta_app_id as phone_number_id
                FROM conversaciones c
                JOIN clientes_contactos cl ON c.id_cliente = cl.id
                JOIN lineas_whatsapp l ON c.id_linea = l.id
                WHERE c.id = $conversacion_id
            ";
            $resChat = $con->query($queryChat);
            $chat_data = $resChat ? $resChat->fetch_assoc() : null;

            if ($chat_data && !empty($chat_data['meta_token']) && $chat_data['meta_token'] !== 'temp_token') {
                $numero_destino = preg_replace('/[^0-9]/', '', $chat_data['numero_whatsapp']);
                $meta_token = $chat_data['meta_token'];
                $phone_number_id = $chat_data['phone_number_id'];

                // 1. Subir archivo a los servidores de Meta para obtener un Media ID
                $media_url = "https://graph.facebook.com/" . (defined('META_API_VERSION') ? META_API_VERSION : 'v23.0') . "/{$phone_number_id}/media";
                $cfile = new CURLFile($dest_path, $mime, $filename);
                $post_media = [
                    'messaging_product' => 'whatsapp',
                    'file' => $cfile
                ];

                $ch = curl_init($media_url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_media);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $meta_token
                ]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $media_response = curl_exec($ch);
                curl_close($ch);
                
                $media_data = json_decode($media_response, true);
                
                // 2. Si la subida fue exitosa, enviar el mensaje con el Media ID
                if (isset($media_data['id'])) {
                    $media_id = $media_data['id'];
                    $msg_url = "https://graph.facebook.com/" . (defined('META_API_VERSION') ? META_API_VERSION : 'v23.0') . "/{$phone_number_id}/messages";
                    
                    $meta_type = 'document';
                    if (strpos($mime, 'image') !== false) {
                        $meta_type = 'image';
                        if ($mime === 'image/webp') {
                            $meta_type = 'sticker';
                        }
                    }
                    
                    $post_msg = [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $numero_destino,
                        'type' => $meta_type,
                        $meta_type => [
                            'id' => $media_id
                        ]
                    ];

                    $ch2 = curl_init($msg_url);
                    curl_setopt($ch2, CURLOPT_POST, 1);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($post_msg));
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $meta_token,
                        'Content-Type: application/json'
                    ]);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    $response2 = curl_exec($ch2);
                    curl_close($ch2);
                    
                    $res_meta2 = json_decode($response2, true);
                    if (isset($res_meta2['messages'][0]['id'])) {
                        $id_mensaje_meta = $res_meta2['messages'][0]['id'];
                    }
                }
            }
            // -------------------------------------------------------------
            // FIN BLOQUE ENVÍO A WHATSAPP
            // -------------------------------------------------------------
            // Guardar en BD
            $reply_to_meta_id = $_POST['reply_to_meta_id'] ?? null;
            $reply_to_text = $_POST['reply_to_text'] ?? null;
            
            $stmt_msg = $con->prepare("
                INSERT INTO mensajes_y_eventos (
                    id_conversacion, id_mensaje_meta, tipo, origen, 
                    contenido, url_archivo, mime_type, estado_envio, reply_to_meta_id, reply_to_text
                ) VALUES (?, ?, ?, 'AGENTE', ?, ?, ?, 'ENVIADO', ?, ?)
            ");
            $stmt_msg->bind_param("isssssss", $conversacion_id, $id_mensaje_meta, $tipo_bd, $contenido, $url_archivo, $mime, $reply_to_meta_id, $reply_to_text);
            if ($stmt_msg->execute()) {
                $new_media_msg_id = intval($stmt_msg->insert_id);
                // Al enviar un archivo/multimedia, la conversación se marca como leída y sincroniza ultimo_msg_id
                $con->query("UPDATE conversaciones SET mensajes_no_leidos = 0, ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), $new_media_msg_id) WHERE id = $conversacion_id");
                echo json_encode(['status' => 'success', 'new_chat_id' => ($is_new_chat ? $conversacion_id : null)]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Fallo al guardar en BD.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Fallo al mover el archivo al servidor.']);
        }
        break;

    case 'retry_message':
        $msg_id = intval($_POST['msg_id'] ?? 0);
        if ($msg_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID inválido']);
            exit;
        }
        
        $stmtMsg = $con->prepare("SELECT m.*, c.id_cliente, cl.numero_whatsapp, l.meta_token, l.meta_app_id as phone_number_id 
                                  FROM mensajes_y_eventos m
                                  JOIN conversaciones c ON m.id_conversacion = c.id
                                  JOIN clientes_contactos cl ON c.id_cliente = cl.id
                                  JOIN lineas_whatsapp l ON c.id_linea = l.id
                                  WHERE m.id = ?");
        $stmtMsg->bind_param("i", $msg_id);
        $stmtMsg->execute();
        $res = $stmtMsg->get_result();
                            
        if (!$res || $res->num_rows == 0) {
            $stmtMsg->close();
            echo json_encode(['status' => 'error', 'message' => 'Mensaje no encontrado']);
            exit;
        }
        
        $msg = $res->fetch_assoc();
        $stmtMsg->close();

        if (!authorize_conversacion_access($con, intval($msg['id_conversacion']), $agente_info_global, false)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para reintentar mensajes de otra sede o conversación.']);
            exit;
        }
        
        if ($msg['origen'] !== 'AGENTE' && $msg['origen'] !== 'API_TRANSACCIONAL') {
             echo json_encode(['status' => 'error', 'message' => 'Solo se pueden reintentar mensajes salientes']);
             exit;
        }
        
        $numero_destino = preg_replace('/[^0-9]/', '', $msg['numero_whatsapp']);
        $meta_token = $msg['meta_token'];
        $phone_number_id = $msg['phone_number_id'];
        
        if (empty($meta_token) || $meta_token === 'temp_token') {
            echo json_encode(['status' => 'error', 'message' => 'Línea de WhatsApp no configurada']);
            exit;
        }
        
        $id_mensaje_meta = null;
        
        if ($msg['tipo'] === 'TEXTO') {
            $url = "https://graph.facebook.com/" . (defined('META_API_VERSION') ? META_API_VERSION : 'v23.0') . "/{$phone_number_id}/messages";
            $post_data = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $numero_destino,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $msg['contenido']
                ]
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $meta_token,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);
            
            $res_meta = json_decode($response, true);
            if (isset($res_meta['messages'][0]['id'])) {
                $id_mensaje_meta = $res_meta['messages'][0]['id'];
            }
        } else if (($msg['tipo'] === 'IMAGEN' || $msg['tipo'] === 'DOCUMENTO') && !empty($msg['url_archivo'])) {
            // Es multimedia
            $dest_path = __DIR__ . '/../..' . str_replace('/starfi_crm', '', $msg['url_archivo']);
            
            if (file_exists($dest_path)) {
                $mime = $msg['mime_type'];
                $filename = basename($dest_path);
                
                $media_url = "https://graph.facebook.com/" . (defined('META_API_VERSION') ? META_API_VERSION : 'v23.0') . "/{$phone_number_id}/media";
                $cfile = new CURLFile($dest_path, $mime, $filename);
                $post_media = ['messaging_product' => 'whatsapp', 'file' => $cfile];
                
                $ch = curl_init($media_url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post_media);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token]);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $media_response = curl_exec($ch);
                curl_close($ch);
                
                $media_data = json_decode($media_response, true);
                if (isset($media_data['id'])) {
                    $media_id = $media_data['id'];
                    $msg_url = "https://graph.facebook.com/" . (defined('META_API_VERSION') ? META_API_VERSION : 'v23.0') . "/{$phone_number_id}/messages";
                    
                    $meta_type = 'document';
                    if (strpos($mime, 'image') !== false) {
                        $meta_type = 'image';
                        if ($mime === 'image/webp') $meta_type = 'sticker';
                    }
                    
                    $post_msg = [
                        'messaging_product' => 'whatsapp',
                        'recipient_type' => 'individual',
                        'to' => $numero_destino,
                        'type' => $meta_type,
                        $meta_type => ['id' => $media_id]
                    ];
                    
                    $ch2 = curl_init($msg_url);
                    curl_setopt($ch2, CURLOPT_POST, 1);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($post_msg));
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token, 'Content-Type: application/json']);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    $response2 = curl_exec($ch2);
                    curl_close($ch2);
                    
                    $res_meta2 = json_decode($response2, true);
                    if (isset($res_meta2['messages'][0]['id'])) {
                        $id_mensaje_meta = $res_meta2['messages'][0]['id'];
                    }
                }
            }
        }
        
        if ($id_mensaje_meta) {
            $stmt = $con->prepare("UPDATE mensajes_y_eventos SET estado_envio = 'ENVIADO', error_detalle = NULL, id_mensaje_meta = ? WHERE id = ?");
            $stmt->bind_param("si", $id_mensaje_meta, $msg_id);
            $stmt->execute();
            echo json_encode(['status' => 'success']);
        } else {
            $err_reason = parse_meta_error_detail($res_meta ?? ($res_meta2 ?? []), 0);
            $err_esc = $con->real_escape_string($err_reason);
            $con->query("UPDATE mensajes_y_eventos SET estado_envio = 'FALLIDO', error_detalle = '$err_esc' WHERE id = $msg_id");
            echo json_encode(['status' => 'error', 'message' => $err_reason]);
        }
        break;

    case 'close_chat':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $motivo = $_POST['motivo_cierre'] ?? 'NO_ESPECIFICADO';
        $calidad = intval($_POST['calificacion_calidad'] ?? 0);
        $observacion = trim($_POST['observacion'] ?? '');
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        
        if($conversacion_id > 0) {
            if (!authorize_conversacion_access($con, $conversacion_id, $agente_info_global, false)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para cerrar conversaciones de otra sede o agente.']);
                exit;
            }
            $update_conv = "UPDATE conversaciones SET estado = 'CERRADO', fecha_resolucion = NOW(), resultado_comercial = ?";
            if ($motivo === 'VENTA_CERRADA') {
                $update_conv .= ", fecha_cierre_venta = NOW()";
            }
            $update_conv .= " WHERE id = ?";
            
            $stmt = $con->prepare($update_conv);
            $stmt->bind_param("si", $motivo, $conversacion_id);
            $stmt->execute();

            // [COM-02] Sincronizar cierre comercial con el Tablero Kanban de Embudo de Ventas
            if ($motivo === 'VENTA_CERRADA') {
                @$con->query("UPDATE pipeline_oportunidades SET etapa = 'GANADO', probabilidad = 100, fecha_cierre = NOW() WHERE (id_conversacion = $conversacion_id OR (id_cliente = $cliente_id AND $cliente_id > 0)) AND etapa NOT IN ('GANADO','PERDIDO')");
            } elseif ($motivo === 'NO_INTERESADO') {
                @$con->query("UPDATE pipeline_oportunidades SET etapa = 'PERDIDO', probabilidad = 0, motivo_perdida = 'Cerrado como No Interesado en Bandeja', fecha_cierre = NOW() WHERE (id_conversacion = $conversacion_id OR (id_cliente = $cliente_id AND $cliente_id > 0)) AND etapa NOT IN ('GANADO','PERDIDO')");
            }
            
            if ($calidad > 0) {
                $stmt_cli = $con->prepare("UPDATE clientes_contactos cl JOIN conversaciones c ON c.id_cliente = cl.id SET cl.calificacion_calidad = ? WHERE c.id = ?");
                $stmt_cli->bind_param("ii", $calidad, $conversacion_id);
                $stmt_cli->execute();
            }

            $motivo_esc = $con->real_escape_string($motivo);
            $evento_texto = "Conversación cerrada por el operador. Motivo: $motivo_esc | Lead Scoring: $calidad estrellas";
            $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES ($conversacion_id, 'EVENTO_SISTEMA', '$evento_texto')");
            sync_ultimo_msg_conversacion($con, $conversacion_id, $con->insert_id);
            
            if (!empty($observacion)) {
                $agente_id_actual = $agente_id;
                $stmt_obs = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, id_agente, tipo, contenido) VALUES (?, 'NOTA_INTERNA', ?, 'TEXTO', ?)");
                $stmt_obs->bind_param("iis", $conversacion_id, $agente_id_actual, $observacion);
                $stmt_obs->execute();
                sync_ultimo_msg_conversacion($con, $conversacion_id, $stmt_obs->insert_id);
            }
            
            // AUTOMATIZACIÓN CSAT: Enviar la plantilla starfi_csat_survey al cliente
            $enviar_csat = intval($_POST['enviar_csat'] ?? 1);
            if ($enviar_csat === 1) {
                $queryChat = "
                    SELECT cl.numero_whatsapp, l.meta_token, l.meta_app_id as phone_number_id
                    FROM conversaciones c
                    JOIN clientes_contactos cl ON c.id_cliente = cl.id
                    JOIN lineas_whatsapp l ON c.id_linea = l.id
                    WHERE c.id = $conversacion_id
                ";
                $resChat = $con->query($queryChat);
                if ($resChat && $rowChat = $resChat->fetch_assoc()) {
                    $numero_destino = $rowChat['numero_whatsapp'];
                    $meta_token = $rowChat['meta_token'];
                    $phone_number_id = $rowChat['phone_number_id'];
                    
                    $msg_url = "https://graph.facebook.com/" . (defined('META_API_VERSION') ? META_API_VERSION : 'v23.0') . "/{$phone_number_id}/messages";
                    $post_csat = [
                        'messaging_product' => 'whatsapp',
                        'to' => $numero_destino,
                        'type' => 'template',
                        'template' => [
                            'name' => 'starfi_csat_survey',
                            'language' => [
                                'code' => 'es'
                            ]
                        ]
                    ];
                    
                    $ch_csat = curl_init($msg_url);
                    curl_setopt($ch_csat, CURLOPT_POST, 1);
                    curl_setopt($ch_csat, CURLOPT_POSTFIELDS, json_encode($post_csat));
                    curl_setopt($ch_csat, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token, 'Content-Type: application/json']);
                    curl_setopt($ch_csat, CURLOPT_RETURNTRANSFER, true);
                    $res_csat_json = curl_exec($ch_csat);
                    curl_close($ch_csat);
                    
                    $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES ($conversacion_id, 'EVENTO_SISTEMA', 'Encuesta CSAT (starfi_csat_survey) enviada al cliente automáticamente.')");
                    sync_ultimo_msg_conversacion($con, $conversacion_id, $con->insert_id);
                }
            }
            
            echo json_encode(['status' => 'success']);
        }
        break;

    case 'mark_as_read':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        if ($conversacion_id > 0) {
            if (!authorize_conversacion_access($con, $conversacion_id, $agente_info_global, false)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre esta conversación o sede.']);
                exit;
            }
            $stmtRead = $con->prepare("UPDATE conversaciones SET mensajes_no_leidos = 0 WHERE id = ?");
            $stmtRead->bind_param("i", $conversacion_id);
            $stmtRead->execute();
            $stmtRead->close();
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ID inválido']);
        }
        break;

    case 'reassign_chat':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $nuevo_agente_id = intval($_POST['nuevo_agente_id'] ?? 0);
        if($conversacion_id > 0 && $nuevo_agente_id > 0) {
            if (!authorize_conversacion_access($con, $conversacion_id, $agente_info_global, true)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para reasignar conversaciones de otra sede.']);
                exit;
            }

            $rol_reass = strtoupper(trim($agente_info_global['rol'] ?? ''));
            $is_master_reass = (intval($agente_info_global['id'] ?? 0) === 1 || $rol_reass === 'MASTER' || $rol_reass === 'MASTER CI');

            $stmtAg = $con->prepare("SELECT u.id_sede, COALESCE(up.nombre, u.usuario) AS nombre_completo, up.telefono FROM usuario u LEFT JOIN usuario_perfil up ON u.id = up.id_usuario WHERE u.id = ? LIMIT 1");
            $rowAgente = null;
            if ($stmtAg) {
                $stmtAg->bind_param("i", $nuevo_agente_id);
                $stmtAg->execute();
                $resAg = $stmtAg->get_result();
                $rowAgente = $resAg ? $resAg->fetch_assoc() : null;
                $stmtAg->close();
            }
            if (!$rowAgente) {
                echo json_encode(['status' => 'error', 'message' => 'El agente seleccionado no existe.']);
                exit;
            }

            if (!$is_master_reass) {
                $user_sede_reass = intval($agente_info_global['id_sede'] ?? 0);
                if (intval($rowAgente['id_sede'] ?? 0) !== $user_sede_reass) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'No puedes reasignar la conversación a un agente de otra sede.']);
                    exit;
                }
            }

            $stmtUpdConv = $con->prepare("UPDATE conversaciones SET id_agente = ?, estado = 'ESPERA_ASIGNACION' WHERE id = ?");
            if ($stmtUpdConv) {
                $stmtUpdConv->bind_param("ii", $nuevo_agente_id, $conversacion_id);
                $stmtUpdConv->execute();
                $stmtUpdConv->close();
            }

            $nombre_agente = $rowAgente['nombre_completo'] ?? 'Agente';
            $telefono_agente = trim($rowAgente['telefono'] ?? '');

            $contenido_ev = 'Conversación reasignada a ' . $nombre_agente;
            $stmtEv = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido) VALUES (?, 'EVENTO_SISTEMA', ?)");
            if ($stmtEv) {
                $stmtEv->bind_param("is", $conversacion_id, $contenido_ev);
                $stmtEv->execute();
                $ev_id = $stmtEv->insert_id;
                $stmtEv->close();
                sync_ultimo_msg_conversacion($con, $conversacion_id, $ev_id);
            }

            // Enviar notificación por WhatsApp al operador y a los administradores
            // Se elimina el fallback a cualquier línea global para evitar usar el número de otra sede
            $qLinea = $con->query("SELECT l.meta_token, l.meta_app_id, l.id_sede FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id WHERE c.id = $conversacion_id LIMIT 1");
            if ($qLinea && $rowLinea = $qLinea->fetch_assoc()) {
                $meta_token = $rowLinea['meta_token'];
                $phone_number_id = $rowLinea['meta_app_id'];
                $id_sede_notif = intval($rowLinea['id_sede'] ?? 1);
                
                // Obtener nombre del asignador y datos del cliente
                $resAsignador = $con->query("SELECT COALESCE(up.nombre, u.usuario) AS nombre_asignador FROM usuario u LEFT JOIN usuario_perfil up ON u.id = up.id_usuario WHERE u.id = $agente_id");
                $nombre_asignador = ($resAsignador && $rowAsig = $resAsignador->fetch_assoc()) ? $rowAsig['nombre_asignador'] : 'Administrador';
                
                $resCliente = $con->query("SELECT cl.nombre, cl.numero_whatsapp FROM conversaciones c JOIN clientes_contactos cl ON c.id_cliente = cl.id WHERE c.id = $conversacion_id LIMIT 1");
                $infoCliente = ($resCliente && $rowCli = $resCliente->fetch_assoc()) ? ($rowCli['nombre'] ? $rowCli['nombre'] . ' (' . $rowCli['numero_whatsapp'] . ')' : $rowCli['numero_whatsapp']) : 'Cliente';
                
                $texto_notif = "Hola $nombre_agente, tienes una conversación asignada por $nombre_asignador de $infoCliente. ¡Ingresa para atenderla!";

                // Coleccionar teléfono de destino ÚNICAMENTE del vendedor/operador asignado (NO notificar al Administrador)
                $telefonos_destinatarios = [];
                if (!empty($telefono_agente) && $telefono_agente !== '-') {
                    $tel_clean = preg_replace('/[^0-9]/', '', $telefono_agente);
                    if (!empty($tel_clean)) {
                        $telefonos_destinatarios[] = $tel_clean;
                    }
                }

                // Enviar la plantilla starfi_notificacion_interna a cada destinatario
                $msg_url = "https://graph.facebook.com/" . (defined('META_API_VERSION') ? META_API_VERSION : 'v23.0') . "/{$phone_number_id}/messages";
                foreach ($telefonos_destinatarios as $tel_dest) {
                    $post_payload = [
                        'messaging_product' => 'whatsapp',
                        'to' => $tel_dest,
                        'type' => 'template',
                        'template' => [
                            'name' => 'starfi_notificacion_interna',
                            'language' => ['code' => 'es'],
                            'components' => [
                                [
                                    'type' => 'body',
                                    'parameters' => [
                                        ['type' => 'text', 'text' => $texto_notif]
                                    ]
                                ]
                            ]
                        ]
                    ];
                    
                    $ch_notif = curl_init($msg_url);
                    curl_setopt($ch_notif, CURLOPT_POST, 1);
                    curl_setopt($ch_notif, CURLOPT_POSTFIELDS, json_encode($post_payload));
                    curl_setopt($ch_notif, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $meta_token, 'Content-Type: application/json']);
                    curl_setopt($ch_notif, CURLOPT_RETURNTRANSFER, true);
                    curl_exec($ch_notif);
                    curl_close($ch_notif);
                }
            }

            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Datos inválidos']);
        }
        break;

    case 'load_profile':
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        if($cliente_id > 0) {
            if (!authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
                exit;
            }
            $stmtProf = $con->prepare("SELECT * FROM clientes_contactos WHERE id = ? LIMIT 1");
            $row = null;
            if ($stmtProf) {
                $stmtProf->bind_param("i", $cliente_id);
                $stmtProf->execute();
                $resProf = $stmtProf->get_result();
                $row = $resProf ? $resProf->fetch_assoc() : null;
                $stmtProf->close();
            }
            if($row) {
                echo json_encode(['status' => 'success', 'data' => $row]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado']);
            }
        }
        break;

    case 'get_all_tags':
        $rol_tags = strtoupper(trim($agente_info_global['rol'] ?? ''));
        $is_master_tags = (intval($agente_info_global['id'] ?? 0) === 1 || $rol_tags === 'MASTER' || $rol_tags === 'MASTER CI');
        $user_sede_tags = intval($agente_info_global['id_sede'] ?? 0);
        if ($is_master_tags) {
            $res = $con->query("SELECT etiquetas FROM clientes_contactos WHERE etiquetas IS NOT NULL AND etiquetas != ''");
        } else {
            $stmtTags = $con->prepare("
                SELECT DISTINCT cl.etiquetas
                FROM clientes_contactos cl
                JOIN conversaciones c ON c.id_cliente = cl.id
                JOIN lineas_whatsapp l ON c.id_linea = l.id
                WHERE l.id_sede = ? AND cl.etiquetas IS NOT NULL AND cl.etiquetas != ''
            ");
            $res = false;
            if ($stmtTags) {
                $stmtTags->bind_param("i", $user_sede_tags);
                $stmtTags->execute();
                $res = $stmtTags->get_result();
            }
        }
        $all_tags = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $tags = !empty($row['etiquetas']) ? json_decode($row['etiquetas'], true) : [];
                if (is_array($tags)) {
                    foreach ($tags as $t) {
                        $t_trim = trim($t);
                        if (!empty($t_trim) && !in_array($t_trim, $all_tags)) {
                            $all_tags[] = $t_trim;
                        }
                    }
                }
            }
        }
        if (isset($stmtTags) && $stmtTags) {
            $stmtTags->close();
        }
        sort($all_tags);
        echo json_encode(['status' => 'success', 'data' => $all_tags]);
        break;

    case 'save_profile':
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $nombre = $_POST['nombre'] ?? '';
        $email = $_POST['email'] ?? '';
        if ($cliente_id > 0) {
            if (!authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
                exit;
            }
            $stmt = $con->prepare("UPDATE clientes_contactos SET nombre = ?, email = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssi", $nombre, $email, $cliente_id);
                if ($stmt->execute()) {
                    echo json_encode(['status' => 'success', 'message' => 'Perfil actualizado']);
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'Error al guardar el perfil: ' . $stmt->error]);
                }
                $stmt->close();
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error de preparación: ' . $con->error]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ID de cliente inválido.']);
        }
        break;

    case 'add_tag':
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $new_tag = trim($_POST['tag'] ?? '');
        if ($cliente_id > 0 && !empty($new_tag)) {
            if (!authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
                exit;
            }
            $stmtGet = $con->prepare("SELECT etiquetas FROM clientes_contactos WHERE id = ? LIMIT 1");
            $row = null;
            if ($stmtGet) {
                $stmtGet->bind_param("i", $cliente_id);
                $stmtGet->execute();
                $resGet = $stmtGet->get_result();
                $row = $resGet ? $resGet->fetch_assoc() : null;
                $stmtGet->close();
            }
            if ($row) {
                $tags = !empty($row['etiquetas']) ? json_decode($row['etiquetas'], true) : [];
                if (!is_array($tags)) $tags = [];
                
                if (!in_array($new_tag, $tags)) {
                    $tags[] = $new_tag;
                    $tags_json = json_encode(array_values($tags));
                    $stmt = $con->prepare("UPDATE clientes_contactos SET etiquetas = ? WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("si", $tags_json, $cliente_id);
                        if ($stmt->execute()) {
                            $stmt->close();
                            echo json_encode(['status' => 'success']);
                            exit;
                        }
                        $stmt->close();
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'La etiqueta ya existe.']);
                    exit;
                }
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Error al añadir la etiqueta.']);
        break;

    case 'remove_tag':
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $tag_to_remove = trim($_POST['tag'] ?? '');
        if ($cliente_id > 0 && !empty($tag_to_remove)) {
            if (!authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
                exit;
            }
            $stmtGet = $con->prepare("SELECT etiquetas FROM clientes_contactos WHERE id = ? LIMIT 1");
            $row = null;
            if ($stmtGet) {
                $stmtGet->bind_param("i", $cliente_id);
                $stmtGet->execute();
                $resGet = $stmtGet->get_result();
                $row = $resGet ? $resGet->fetch_assoc() : null;
                $stmtGet->close();
            }
            if ($row) {
                $tags = !empty($row['etiquetas']) ? json_decode($row['etiquetas'], true) : [];
                if (!is_array($tags)) $tags = [];
                
                $new_tags = array_filter($tags, function($t) use ($tag_to_remove) {
                    return $t !== $tag_to_remove;
                });
                $tags_json = json_encode(array_values($new_tags));
                $stmt = $con->prepare("UPDATE clientes_contactos SET etiquetas = ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("si", $tags_json, $cliente_id);
                    if ($stmt->execute()) {
                        $stmt->close();
                        echo json_encode(['status' => 'success']);
                        exit;
                    }
                    $stmt->close();
                }
            }
        }
        echo json_encode(['status' => 'error', 'message' => 'Error al quitar la etiqueta.']);
        break;

    case 'get_agents':
        $conversacion_id = intval($_POST['conversacion_id'] ?? $_GET['conversacion_id'] ?? 0);
        $posted_sede = intval($_POST['id_sede'] ?? $_GET['id_sede'] ?? 0);
        $agente = $agente_info_global;
        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
        $rol_ga = strtoupper(trim($agente['rol'] ?? ''));
        $is_master_ga = (intval($agente['id'] ?? 0) === 1 || $rol_ga === 'MASTER' || $rol_ga === 'MASTER CI');
        
        $target_sede = 0;
        if (!$is_master_ga) {
            $target_sede = $user_sede;
            if ($target_sede <= 0) {
                echo json_encode(['status' => 'success', 'data' => []]);
                exit;
            }
        } elseif ($posted_sede > 0) {
            $target_sede = $posted_sede;
        } elseif ($conversacion_id > 0) {
            $stmt_s = $con->prepare("
                SELECT l.id_sede 
                FROM conversaciones c 
                JOIN lineas_whatsapp l ON c.id_linea = l.id 
                WHERE c.id = ? 
                LIMIT 1
            ");
            if ($stmt_s) {
                $stmt_s->bind_param("i", $conversacion_id);
                $stmt_s->execute();
                $res_s = $stmt_s->get_result();
                if ($row_s = $res_s->fetch_assoc()) {
                    $target_sede = intval($row_s['id_sede'] ?? 0);
                }
                $stmt_s->close();
            }
        }

        if ($target_sede <= 0 && $user_sede > 0) {
            $target_sede = $user_sede;
        }

        $query = "
            SELECT u.id,
                   u.usuario,
                   up.nombre AS up_nombre,
                   (CASE WHEN IFNULL(u.disponible_chat, 1) = 1 AND u.ultima_actividad IS NOT NULL AND u.ultima_actividad >= (NOW() - INTERVAL 5 MINUTE) THEN 1 ELSE 0 END) AS en_linea
            FROM usuario u 
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario 
            LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
            WHERE (u.estado = 'ACTIVO' OR CAST(u.estado AS CHAR) = '1')
              AND u.id != 1
              AND u.usuario != 'master'
              AND (UPPER(TRIM(IFNULL(r.nombre, ''))) = 'OPERADOR' OR UPPER(TRIM(IFNULL(CAST(u.rol AS CHAR), ''))) IN ('3', 'OPERADOR'))
        ";

        if ($target_sede > 0) {
            $query .= " AND u.id_sede = " . intval($target_sede);
        }

        $query .= " ORDER BY en_linea DESC, up.nombre ASC, u.usuario ASC";

        $res = $con->query($query);
        $agents = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $nom = trim((string)($row['up_nombre'] ?? ''));
                $agents[] = [
                    'id' => $row['id'],
                    'nombre_completo' => ($nom !== '') ? $nom : $row['usuario'],
                    'en_linea' => intval($row['en_linea'] ?? 0),
                ];
            }
        }
        echo json_encode(['status' => 'success', 'data' => $agents]);
        break;

    case 'heartbeat':
        if ($agente_id > 1) {
            starfi_touch_agent_presence($con, $agente_id, true);
        }
        $disp = 1;
        if ($agente_id > 1) {
            $rDisp = $con->query("SELECT IFNULL(disponible_chat, 1) AS disp FROM usuario WHERE id = $agente_id LIMIT 1");
            if ($rDisp && ($rd = $rDisp->fetch_assoc())) {
                $disp = intval($rd['disp']);
            }
        }
        echo json_encode(['status' => 'success', 'disponible_chat' => $disp, 'server_now_epoch' => time()]);
        break;

    case 'toggle_availability':
        if ($agente_id <= 1) {
            echo json_encode(['status' => 'success', 'disponible_chat' => 1]);
            break;
        }
        $nuevo_disp = isset($_POST['disponible']) ? (intval($_POST['disponible']) === 1 ? 1 : 0) : 1;
        $con->query("UPDATE usuario SET disponible_chat = $nuevo_disp, ultima_actividad = NOW() WHERE id = $agente_id");
        echo json_encode(['status' => 'success', 'disponible_chat' => $nuevo_disp]);
        break;

    case 'get_meta_templates_bandeja':
        $conversacion_id = intval($_POST['conversacion_id'] ?? $_GET['conversacion_id'] ?? 0);
        $cliente_id = intval($_POST['cliente_id'] ?? $_GET['cliente_id'] ?? 0);
        $force_sync = intval($_POST['force_sync'] ?? 0) === 1;

        if ($conversacion_id > 0 && !authorize_conversacion_access($con, $conversacion_id, $agente_info_global, true)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre esta conversación o sede.']);
            exit;
        }
        if ($conversacion_id <= 0 && $cliente_id > 0 && !authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
            exit;
        }

        // Resolver sede y línea activa
        $sede_tpl = intval($agente_info_global['id_sede'] ?? 0);
        if ($conversacion_id > 0) {
            $rL = $con->query("SELECT l.id_sede, l.id_negocio, l.meta_token, s.nombre_sede FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id LEFT JOIN sedes s ON l.id_sede = s.id WHERE c.id = $conversacion_id LIMIT 1");
            $lin = ($rL && $rL->num_rows > 0) ? $rL->fetch_assoc() : null;
        } else {
            if ($sede_tpl <= 0 && $cliente_id > 0) {
                $rCs = $con->query("SELECT id_sede FROM clientes_contactos WHERE id = $cliente_id LIMIT 1");
                if ($rCs && ($rcs = $rCs->fetch_assoc())) {
                    $sede_tpl = intval($rcs['id_sede'] ?? 0);
                }
            }
            $rL = ($sede_tpl > 0) ? $con->query("SELECT l.id_sede, l.id_negocio, l.meta_token, s.nombre_sede FROM lineas_whatsapp l LEFT JOIN sedes s ON l.id_sede = s.id WHERE l.id_sede = $sede_tpl AND l.estado = 'ACTIVO' LIMIT 1") : false;
            $lin = ($rL && $rL->num_rows > 0) ? $rL->fetch_assoc() : null;
        }

        $sede_id_eff = intval($lin['id_sede'] ?? $sede_tpl);
        $nombre_sede_eff = trim((string)($lin['nombre_sede'] ?? 'Nuestra Sede'));
        $waba_id = trim((string)($lin['id_negocio'] ?? ''));
        $token = !empty($lin['meta_token']) ? trim((string)$lin['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

        $cache_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "starfi_bandeja_tpls_sede_{$sede_id_eff}.json";
        $templates = [];

        if (!$force_sync && $sede_id_eff > 0 && @file_exists($cache_file) && (time() - @filemtime($cache_file)) < 300) {
            $cached = json_decode((string)@file_get_contents($cache_file), true);
            if (is_array($cached) && !empty($cached)) {
                $templates = $cached;
            }
        }

        if (empty($templates) && $waba_id !== '' && $token !== '' && $token !== 'temp_token') {
            $api_ver = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver}/{$waba_id}/message_templates?fields=name,status,language,category,components&limit=100";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code >= 200 && $code < 300 && $resp) {
                $json = json_decode($resp, true);
                foreach (($json['data'] ?? []) as $tpl) {
                    $st = strtoupper($tpl['status'] ?? '');
                    if ($st !== 'APPROVED' && $st !== 'ACTIVE') {
                        continue;
                    }
                    $body_txt = '';
                    $header_fmt = 'NONE';
                    $header_txt = '';
                    $footer_txt = '';
                    $body_examples = [];
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
                            }
                        } elseif ($c_type === 'FOOTER') {
                            $footer_txt = $comp['text'] ?? '';
                        }
                    }
                    $templates[] = [
                        'name' => $tpl['name'],
                        'language' => $tpl['language'] ?? 'es',
                        'category' => strtoupper($tpl['category'] ?? 'UTILITY'),
                        'header_format' => $header_fmt,
                        'header_text' => $header_txt,
                        'body' => $body_txt,
                        'body_examples' => $body_examples,
                        'footer' => $footer_txt
                    ];
                }
                if (!empty($templates) && $sede_id_eff > 0) {
                    @file_put_contents($cache_file, json_encode($templates, JSON_UNESCAPED_UNICODE));
                }
            }
        }

        // Fallback local desde tabla plantillas_whatsapp + plantillas oficiales aprobadas de STARFI
        if (empty($templates)) {
            if ($sede_id_eff > 0) {
                $rDbTpl = @$con->query("SELECT name, category, language, status FROM plantillas_whatsapp WHERE id_sede = $sede_id_eff AND UPPER(status) IN ('APPROVED','ACTIVE') ORDER BY name ASC LIMIT 40");
                if ($rDbTpl) {
                    while ($rt = $rDbTpl->fetch_assoc()) {
                        $tName = $rt['name'];
                        $defBody = "Hola {{1}}, nos comunicamos desde {$nombre_sede_eff} respecto a {{2}}.";
                        if ($tName === 'starfi_difusion_directa') {
                            $defBody = "👋¡Hola {{1}}!\nHoy queremos {{2}}.\nRecuerda que en {{3}}.\n📲 Responde a este mensaje para obtener más información👇";
                        } elseif ($tName === 'starfi_csat_survey') {
                            $defBody = "¡Hola! Califícanos del 1 al 5 para mejorar nuestra atención en {$nombre_sede_eff}.";
                        } elseif ($tName === 'starfi_notificacion_interna') {
                            $defBody = "{{1}}";
                        }
                        $templates[] = [
                            'name' => $tName,
                            'language' => $rt['language'] ?: 'es',
                            'category' => strtoupper($rt['category'] ?: 'MARKETING'),
                            'header_format' => 'NONE',
                            'header_text' => '',
                            'body' => $defBody,
                            'body_examples' => [],
                            'footer' => ''
                        ];
                    }
                }
            }
            if (empty($templates)) {
                $templates[] = [
                    'name' => 'starfi_difusion_directa',
                    'language' => 'es',
                    'category' => 'MARKETING',
                    'header_format' => 'NONE',
                    'header_text' => '',
                    'body' => "👋¡Hola {{1}}!\nHoy queremos {{2}}.\nRecuerda que en {{3}}.\n📲 Responde a este mensaje para obtener más información👇",
                    'body_examples' => ['Cliente', 'retomar tu solicitud de cotización y brindarte atención inmediata', $nombre_sede_eff . ' estamos listos para ayudarte'],
                    'footer' => 'Servicio de mensajería inteligente de STARFI CRM'
                ];
            }
        }

        echo json_encode([
            'status' => 'success',
            'nombre_sede' => $nombre_sede_eff,
            'templates' => $templates
        ]);
        break;

    case 'send_meta_template_reactivate':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $tpl_name = preg_replace('/[^a-zA-Z0-9_]/', '', trim((string)($_POST['template_name'] ?? '')));
        $tpl_lang = preg_replace('/[^a-zA-Z0-9_]/', '', trim((string)($_POST['template_language'] ?? 'es')));
        $tpl_cat = strtoupper(trim((string)($_POST['template_category'] ?? 'UTILITY')));
        $header_fmt = strtoupper(trim((string)($_POST['header_format'] ?? 'NONE')));
        $header_text_param = trim((string)($_POST['header_param_text'] ?? ''));
        $body_params_raw = $_POST['body_params'] ?? '[]';
        $rendered_preview = trim((string)($_POST['rendered_preview'] ?? ''));

        if ($tpl_name === '' || ($conversacion_id <= 0 && $cliente_id <= 0)) {
            echo json_encode(['status' => 'error', 'message' => 'Selecciona una plantilla oficial válida.']);
            exit;
        }

        $body_params = is_array($body_params_raw) ? $body_params_raw : json_decode((string)$body_params_raw, true);
        if (!is_array($body_params)) {
            $body_params = [];
        }
        $clean_params = array_values(array_map('sanitize_bandeja_tpl_param', $body_params));

        $is_new_chat = false;
        if ($conversacion_id <= 0 && $cliente_id > 0) {
            if (!authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
                exit;
            }
            $user_sede_r = intval($agente_info_global['id_sede'] ?? 0);
            if ($user_sede_r <= 0) {
                $rCs = $con->query("SELECT id_sede FROM clientes_contactos WHERE id = $cliente_id LIMIT 1");
                if ($rCs && ($rcs = $rCs->fetch_assoc())) {
                    $user_sede_r = intval($rcs['id_sede'] ?? 0);
                }
            }
            $resL = ($user_sede_r > 0) ? $con->query("SELECT id FROM lineas_whatsapp WHERE id_sede = $user_sede_r AND (estado = 'ACTIVO' OR estado_conexion = 'CONECTADO') LIMIT 1") : false;
            if (!$resL || $resL->num_rows === 0) {
                echo json_encode(['status' => 'error', 'message' => 'No hay línea activa configurada para esta sede.']);
                exit;
            }
            $id_linea_new = intval($resL->fetch_assoc()['id']);
            $stmtIns = $con->prepare("INSERT INTO conversaciones (id_linea, id_cliente, id_agente, estado) VALUES (?, ?, ?, 'ATENDIENDO')");
            $stmtIns->bind_param("iii", $id_linea_new, $cliente_id, $agente_id);
            $stmtIns->execute();
            $conversacion_id = intval($stmtIns->insert_id);
            $stmtIns->close();
            $is_new_chat = true;
        } else {
            if (!authorize_conversacion_access($con, $conversacion_id, $agente_info_global, true)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para reactivar esta conversación o sede.']);
                exit;
            }
        }

        $resChat = $con->query("
            SELECT c.id_cliente, cl.numero_whatsapp, l.id_sede, l.meta_token, l.meta_app_id AS phone_number_id
            FROM conversaciones c
            JOIN clientes_contactos cl ON c.id_cliente = cl.id
            JOIN lineas_whatsapp l ON c.id_linea = l.id
            WHERE c.id = $conversacion_id
            LIMIT 1
        ");
        $chat_data = ($resChat && $resChat->num_rows > 0) ? $resChat->fetch_assoc() : null;
        if (!$chat_data) {
            echo json_encode(['status' => 'error', 'message' => 'Datos de línea o conversación no encontrados.']);
            exit;
        }

        $numero_destino = preg_replace('/[^0-9]/', '', (string)$chat_data['numero_whatsapp']);
        $meta_token = !empty($chat_data['meta_token']) ? trim((string)$chat_data['meta_token']) : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');
        $phone_number_id = trim((string)($chat_data['phone_number_id'] ?? ''));

        $components = [];
        if ($header_fmt === 'TEXT' && $header_text_param !== '') {
            $components[] = [
                'type' => 'header',
                'parameters' => [
                    ['type' => 'text', 'text' => sanitize_bandeja_tpl_param($header_text_param)]
                ]
            ];
        } elseif ($header_fmt === 'IMAGE' && !empty($meta_token) && $meta_token !== 'temp_token' && !empty($phone_number_id)) {
            // Si la plantilla requiere HEADER IMAGE, subir el logo corporativo de respaldo si no se adjuntó imagen
            $logo_candidates = [
                realpath(__DIR__ . '/../../docs/identidad_visual/logos/logo_starfi.png'),
                realpath(__DIR__ . '/../../docs/identidad_visual/logos/isologo.png')
            ];
            $img_path = null;
            foreach ($logo_candidates as $cand) {
                if ($cand && file_exists($cand)) {
                    $img_path = $cand;
                    break;
                }
            }
            if ($img_path) {
                $api_ver = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
                $ch_m = curl_init("https://graph.facebook.com/{$api_ver}/{$phone_number_id}/media");
                curl_setopt($ch_m, CURLOPT_POST, true);
                curl_setopt($ch_m, CURLOPT_POSTFIELDS, [
                    'messaging_product' => 'whatsapp',
                    'type' => 'image/png',
                    'file' => new CURLFile($img_path, 'image/png', basename($img_path))
                ]);
                curl_setopt($ch_m, CURLOPT_HTTPHEADER, ["Authorization: Bearer $meta_token"]);
                curl_setopt($ch_m, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch_m, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch_m, CURLOPT_TIMEOUT, 12);
                $m_resp = json_decode((string)curl_exec($ch_m), true);
                curl_close($ch_m);
                if (!empty($m_resp['id'])) {
                    $components[] = [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'image', 'image' => ['id' => $m_resp['id']]]
                        ]
                    ];
                }
            }
        }

        if (!empty($clean_params)) {
            $body_param_objs = [];
            foreach ($clean_params as $cp) {
                $body_param_objs[] = ['type' => 'text', 'text' => $cp];
            }
            $components[] = [
                'type' => 'body',
                'parameters' => $body_param_objs
            ];
        }

        $tpl_payload = [
            'name' => $tpl_name,
            'language' => ['code' => $tpl_lang ?: 'es']
        ];
        if (!empty($components)) {
            $tpl_payload['components'] = $components;
        }

        $post_data = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $numero_destino,
            'type' => 'template',
            'template' => $tpl_payload
        ];

        $id_mensaje_meta = null;
        $estado_envio = 'PENDIENTE';
        $error_detalle = null;

        if (!empty($meta_token) && $meta_token !== 'temp_token' && !empty($phone_number_id)) {
            $api_ver = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver}/{$phone_number_id}/messages";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $meta_token,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res_meta = json_decode((string)$response, true);
            if (!empty($res_meta['messages'][0]['id'])) {
                $id_mensaje_meta = $res_meta['messages'][0]['id'];
                $estado_envio = 'ENVIADO';
            } else {
                $estado_envio = 'FALLIDO';
                $error_detalle = parse_meta_error_detail($res_meta, $status_code);
            }
        } else {
            $estado_envio = 'FALLIDO';
            $error_detalle = 'Línea WhatsApp sin token Meta configurado en el servidor.';
        }

        $contenido_log = "Envío dinámico de plantilla: {$tpl_name}. Params: " . json_encode($clean_params, JSON_UNESCAPED_UNICODE);
        if ($rendered_preview !== '') {
            $contenido_log .= " | Texto: " . $rendered_preview;
        }

        $stmt_log = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, tipo, origen, id_agente, contenido, id_mensaje_meta, estado_envio, error_detalle, categoria_meta) VALUES (?, 'TEXTO', 'API_TRANSACCIONAL', ?, ?, ?, ?, ?, ?)");
        $new_msg_id = 0;
        if ($stmt_log) {
            $stmt_log->bind_param("iisssss", $conversacion_id, $agente_id, $contenido_log, $id_mensaje_meta, $estado_envio, $error_detalle, $tpl_cat);
            $stmt_log->execute();
            $new_msg_id = intval($stmt_log->insert_id);
            $stmt_log->close();
        }

        $con->query("UPDATE conversaciones SET estado = CASE WHEN estado IN ('CERRADO','RESUELTO','ESPERA_ASIGNACION') THEN 'ATENDIENDO' ELSE estado END, id_agente = IFNULL(id_agente, $agente_id), mensajes_no_leidos = 0, ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), $new_msg_id) WHERE id = $conversacion_id");

        starfi_ensure_pipeline_lead($con, intval($chat_data['id_sede'] ?? 0), intval($chat_data['id_cliente'] ?? 0), $conversacion_id, $agente_id);

        if ($estado_envio === 'FALLIDO') {
            echo json_encode([
                'status' => 'error',
                'message' => $error_detalle ?: 'Meta rechazó el envío de la plantilla.',
                'new_chat_id' => ($is_new_chat ? $conversacion_id : null)
            ]);
        } else {
            echo json_encode([
                'status' => 'success',
                'message' => 'Plantilla Oficial enviada con éxito. Cuando el cliente responda se abrirá nuevamente la ventana gratuita de 24h.',
                'message_id' => $new_msg_id,
                'wamid' => $id_mensaje_meta,
                'new_chat_id' => ($is_new_chat ? $conversacion_id : null)
            ]);
        }
        break;

    case 'quick_save_deal':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $etapa = strtoupper(trim((string)($_POST['etapa'] ?? 'NUEVO_LEAD')));
        $monto = max(0, round(floatval($_POST['monto_estimado_usd'] ?? 0), 2));
        $titulo = trim((string)($_POST['titulo'] ?? ''));

        $etapas_validas = ['NUEVO_LEAD', 'COTIZADO', 'EN_NEGOCIACION', 'PAGO_PENDIENTE', 'GANADO', 'PERDIDO'];
        if (!in_array($etapa, $etapas_validas, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Etapa inválida.']);
            exit;
        }
        if ($conversacion_id > 0 && !authorize_conversacion_access($con, $conversacion_id, $agente_info_global, true)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Sin autorización para esta conversación o sede.']);
            exit;
        }
        if ($conversacion_id <= 0 && $cliente_id > 0 && !authorize_cliente_access($con, $cliente_id, $agente_info_global)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Sin autorización para este cliente o sede.']);
            exit;
        }

        $sede_deal = intval($agente_info_global['id_sede'] ?? 0);
        if ($conversacion_id > 0) {
            $rC = $con->query("SELECT c.id_cliente, c.id_agente, l.id_sede FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id WHERE c.id = $conversacion_id LIMIT 1");
            if ($rC && ($rc = $rC->fetch_assoc())) {
                $sede_deal = intval($rc['id_sede'] ?? $sede_deal);
                if ($cliente_id <= 0) $cliente_id = intval($rc['id_cliente']);
            }
        }

        $prob_map = [
            'NUEVO_LEAD' => 20,
            'COTIZADO' => 45,
            'EN_NEGOCIACION' => 70,
            'PAGO_PENDIENTE' => 90,
            'GANADO' => 100,
            'PERDIDO' => 0
        ];
        $prob = $prob_map[$etapa] ?? 20;

        $deal_id = starfi_ensure_pipeline_lead($con, $sede_deal, $cliente_id, $conversacion_id, $agente_id, $titulo);
        if ($deal_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo crear o localizar la oportunidad.']);
            exit;
        }

        $cierre_sql = in_array($etapa, ['GANADO', 'PERDIDO'], true) ? ", fecha_cierre = NOW()" : ", fecha_cierre = NULL";
        $tit_sql = ($titulo !== '') ? ", titulo = '" . $con->real_escape_string(mb_substr($titulo, 0, 195)) . "'" : "";
        $con->query("UPDATE pipeline_oportunidades SET etapa = '$etapa', monto_estimado_usd = $monto, probabilidad = $prob $tit_sql $cierre_sql WHERE id = $deal_id");

        if ($conversacion_id > 0) {
            if ($etapa === 'GANADO') {
                $con->query("UPDATE conversaciones SET resultado_comercial = 'VENTA_CERRADA', fecha_cierre_venta = IFNULL(fecha_cierre_venta, NOW()) WHERE id = $conversacion_id");
            }
            $etapa_pretty = str_replace('_', ' ', $etapa);
            $monto_fmt = number_format($monto, 2);
            $ev_msg = $con->real_escape_string("💼 Embudo actualizado a [$etapa_pretty] · Monto estimado: \$$monto_fmt USD.");
            $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES ($conversacion_id, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', '$ev_msg')");
            sync_ultimo_msg_conversacion($con, $conversacion_id, $con->insert_id);
        }

        echo json_encode([
            'status' => 'success',
            'deal' => get_conversacion_deal_info($con, $conversacion_id, $cliente_id)
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
        break;
}
?>
