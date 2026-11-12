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
            }

            foreach ($chats as &$ch) {
                $cid = intval($ch['id']);
                $mid = intval($ch['ultimo_msg_id'] ?? 0);
                $lm = ($mid > 0 && isset($last_msgs_map[$mid])) ? $last_msgs_map[$mid] : null;
                $fl = isset($flags_map[$cid]) ? $flags_map[$cid] : null;

                $ch['ultimo_mensaje_ts']     = $lm ? $lm['timestamp'] : $ch['fecha_inicio'];
                $ch['ultimo_mensaje_texto']  = $lm ? $lm['contenido'] : null;
                $ch['ultimo_mensaje_origen'] = $lm ? $lm['origen'] : null;

                $es_venta_comercial = ($ch['resultado_comercial'] === 'VENTA_CERRADA' || !empty($ch['fecha_cierre_venta']));
                $ch['es_campana'] = ($fl && intval($fl['flag_campana']) === 1) ? 1 : 0;
                $ch['es_venta']   = ($es_venta_comercial || ($fl && intval($fl['flag_venta']) === 1)) ? 1 : 0;

                unset($ch['ultimo_msg_id'], $ch['resultado_comercial'], $ch['fecha_cierre_venta']);
            }
            unset($ch);
        }

        echo json_encode([
            'status' => 'success', 
            'data' => $chats,
            'page' => $page,
            'limit' => $limit,
            'has_more' => $has_more
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
            echo json_encode(['status' => 'success', 'data' => $messages]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error preparando consulta.']);
        }
        break;
        
    case 'send_message':
        $conversacion_id = intval($_POST['conversacion_id'] ?? 0);
        $cliente_id = intval($_POST['cliente_id'] ?? 0);
        $contenido = trim($_POST['contenido'] ?? '');
        
        if (empty($contenido) || ($conversacion_id <= 0 && $cliente_id <= 0)) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos.']);
            exit;
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
            SELECT u.id, COALESCE(up.nombre, u.usuario) AS nombre_completo 
            FROM usuario u 
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario 
            LEFT JOIN roles r ON (u.rol = r.id OR u.rol = r.nombre)
            WHERE (u.estado = 'ACTIVO' OR u.estado = 1)
              AND u.id != 1
              AND u.usuario != 'master'
              AND (UPPER(COALESCE(r.nombre, u.rol)) = 'OPERADOR' OR u.rol = '3' OR u.rol = 3)
        ";

        if ($target_sede > 0) {
            $query .= " AND u.id_sede = " . intval($target_sede);
        }

        $query .= " ORDER BY nombre_completo ASC";

        $res = $con->query($query);
        $agents = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $agents[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $agents]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
        break;
}
?>
