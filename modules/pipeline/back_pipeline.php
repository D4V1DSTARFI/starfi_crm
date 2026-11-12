<?php
// modules/pipeline/back_pipeline.php
// Backend AJAX para Tablero Kanban de Embudo de Ventas (USD) | STARFI CRM [COM-02]

date_default_timezone_set('America/Caracas');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('pipeline');

$agente = getAgenteInfo();
$agente_id = intval($agente['id'] ?? 0);
$rol_raw = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
$user_sede = intval($agente['id_sede'] ?? 0);

$is_master = ($agente_id === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI' || ($rol_raw === 'ADMIN' && $user_sede === 0));
$is_admin = (!$is_master && in_array($rol_raw, ['ADMINISTRADOR', 'ADMIN', 'GERENTE'], true));
$is_operador = (!$is_master && !$is_admin);

// Liberar bloqueo de sesión para fluidez concurrente con Bandeja SSE
session_write_close();

$con = getDbConnection('core');
if (!$con) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos central.']);
    exit;
}

starfi_ensure_sprint2_schema($con);

function pipeline_stage_probability($etapa) {
    $map = [
        'NUEVO_LEAD'     => 20,
        'COTIZADO'       => 45,
        'EN_NEGOCIACION' => 70,
        'PAGO_PENDIENTE' => 90,
        'GANADO'         => 100,
        'PERDIDO'        => 0,
    ];
    return $map[$etapa] ?? 20;
}

function pipeline_stage_label($etapa) {
    $map = [
        'NUEVO_LEAD'     => 'Nuevo Lead',
        'COTIZADO'       => 'Cotizado',
        'EN_NEGOCIACION' => 'En Negociación',
        'PAGO_PENDIENTE' => 'Pago Pendiente',
        'GANADO'         => 'Ganado',
        'PERDIDO'        => 'Perdido',
    ];
    return $map[$etapa] ?? $etapa;
}

function authorize_pipeline_deal($con, $deal_id, $is_master, $is_admin, $agente_id, $user_sede) {
    $did = intval($deal_id);
    if ($did <= 0) {
        return null;
    }
    $res = $con->query("SELECT * FROM pipeline_oportunidades WHERE id = $did LIMIT 1");
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    if (!$row) {
        return null;
    }
    if ($is_master) {
        return $row;
    }
    if ($user_sede <= 0 || intval($row['id_sede']) !== $user_sede) {
        return false;
    }
    if ($is_admin) {
        return $row;
    }
    if (intval($row['id_agente']) === $agente_id) {
        return $row;
    }
    return false;
}

function sync_pipeline_conv_event($con, $id_conversacion, $etapa, $monto_usd, $motivo_perdida = '') {
    $cid = intval($id_conversacion);
    if ($cid <= 0) {
        return;
    }
    if ($etapa === 'GANADO') {
        $con->query("UPDATE conversaciones SET resultado_comercial = 'VENTA_CERRADA', fecha_cierre_venta = IFNULL(fecha_cierre_venta, NOW()) WHERE id = $cid");
    } elseif ($etapa === 'PERDIDO') {
        $con->query("UPDATE conversaciones SET resultado_comercial = IFNULL(resultado_comercial, 'NO_INTERESADO') WHERE id = $cid");
    }
    $lbl = pipeline_stage_label($etapa);
    $monto_fmt = number_format(floatval($monto_usd), 2);
    $extra = ($etapa === 'PERDIDO' && trim((string)$motivo_perdida) !== '') ? " · Motivo: " . trim((string)$motivo_perdida) : "";
    $txt = $con->real_escape_string("💼 Embudo Kanban: Etapa [$lbl] · Monto estimado: \${$monto_fmt} USD{$extra}");
    if ($con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES ($cid, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', '$txt')")) {
        $new_mid = intval($con->insert_id);
        if ($new_mid > 0) {
            $con->query("UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), $new_mid) WHERE id = $cid");
        }
    }
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

switch ($action) {
    case 'get_board':
        $where = "WHERE 1=1";

        if (!$is_master) {
            if ($user_sede > 0) {
                $where .= " AND p.id_sede = $user_sede";
            } else {
                $where .= " AND 1=0";
            }
        } else {
            $req_sede = intval($_POST['id_sede'] ?? $_GET['id_sede'] ?? 0);
            if ($req_sede > 0) {
                $where .= " AND p.id_sede = $req_sede";
            }
        }

        if ($is_operador) {
            $where .= " AND p.id_agente = $agente_id";
        } else {
            $req_ag = intval($_POST['id_agente'] ?? $_GET['id_agente'] ?? 0);
            if ($req_ag > 0) {
                $where .= " AND p.id_agente = $req_ag";
            }
        }

        $periodo = trim((string)($_POST['periodo'] ?? $_GET['periodo'] ?? '90d'));
        if ($periodo === '30d') {
            $where .= " AND p.fecha_actualizacion >= (NOW() - INTERVAL 30 DAY)";
        } elseif ($periodo === '90d') {
            $where .= " AND p.fecha_actualizacion >= (NOW() - INTERVAL 90 DAY)";
        } elseif ($periodo === 'mes') {
            $where .= " AND YEAR(p.fecha_actualizacion) = YEAR(CURDATE()) AND MONTH(p.fecha_actualizacion) = MONTH(CURDATE())";
        }

        $search = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));
        if ($search !== '') {
            $s_esc = $con->real_escape_string($search);
            $s_num = preg_replace('/[^0-9]/', '', $search);
            if ($s_num !== '') {
                $s_num_esc = $con->real_escape_string($s_num);
                $where .= " AND (p.titulo LIKE '%$s_esc%' OR cl.nombre LIKE '%$s_esc%' OR cl.numero_whatsapp LIKE '%$s_num_esc%')";
            } else {
                $where .= " AND (p.titulo LIKE '%$s_esc%' OR cl.nombre LIKE '%$s_esc%')";
            }
        }

        $sql = "
            SELECT
                p.id,
                p.id_sede,
                p.id_cliente,
                p.id_conversacion,
                p.id_agente,
                p.titulo,
                p.etapa,
                p.monto_estimado_usd,
                p.probabilidad,
                p.notas,
                p.motivo_perdida,
                p.orden_kanban,
                p.fecha_creacion,
                p.fecha_actualizacion,
                p.fecha_cierre,
                cl.nombre AS cliente_nombre,
                cl.numero_whatsapp,
                IFNULL(s.nombre_sede, 'Sede Principal') AS nombre_sede,
                COALESCE(up.nombre, u.usuario, 'Sin asignar') AS asesor_nombre,
                c.estado AS chat_estado,
                IFNULL(c.mensajes_no_leidos, 0) AS chat_no_leidos
            FROM pipeline_oportunidades p
            JOIN clientes_contactos cl ON p.id_cliente = cl.id
            LEFT JOIN sedes s ON p.id_sede = s.id
            LEFT JOIN usuario u ON p.id_agente = u.id
            LEFT JOIN usuario_perfil up ON p.id_agente = up.id_usuario
            LEFT JOIN conversaciones c ON p.id_conversacion = c.id
            $where
            ORDER BY p.orden_kanban ASC, p.fecha_actualizacion DESC, p.id DESC
            LIMIT 400
        ";

        $res = $con->query($sql);
        $stages = [
            'NUEVO_LEAD'     => [],
            'COTIZADO'       => [],
            'EN_NEGOCIACION' => [],
            'PAGO_PENDIENTE' => [],
            'GANADO'         => [],
            'PERDIDO'        => [],
        ];

        $kpis = [
            'total_oportunidades'    => 0,
            'pipeline_activo_usd'    => 0.0,
            'pipeline_ponderado_usd' => 0.0,
            'ganado_usd'             => 0.0,
            'ganado_count'           => 0,
            'perdido_usd'            => 0.0,
            'perdido_count'          => 0,
            'ticket_promedio_usd'    => 0.0,
            'tasa_cierre_pct'        => 0.0,
        ];

        $suma_total_usd = 0.0;
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $etapa = strtoupper((string)$row['etapa']);
                if (!isset($stages[$etapa])) {
                    $etapa = 'NUEVO_LEAD';
                }
                $monto = round(floatval($row['monto_estimado_usd']), 2);
                $prob = intval($row['probabilidad']);
                $row['id'] = intval($row['id']);
                $row['id_sede'] = intval($row['id_sede']);
                $row['id_cliente'] = intval($row['id_cliente']);
                $row['id_conversacion'] = intval($row['id_conversacion'] ?? 0);
                $row['id_agente'] = intval($row['id_agente'] ?? 0);
                $row['monto_estimado_usd'] = $monto;
                $row['probabilidad'] = $prob;
                $row['chat_no_leidos'] = intval($row['chat_no_leidos'] ?? 0);

                $stages[$etapa][] = $row;
                $kpis['total_oportunidades']++;
                $suma_total_usd += $monto;

                if ($etapa === 'GANADO') {
                    $kpis['ganado_usd'] += $monto;
                    $kpis['ganado_count']++;
                } elseif ($etapa === 'PERDIDO') {
                    $kpis['perdido_usd'] += $monto;
                    $kpis['perdido_count']++;
                } else {
                    $kpis['pipeline_activo_usd'] += $monto;
                    $kpis['pipeline_ponderado_usd'] += ($monto * ($prob / 100.0));
                }
            }
        }

        if ($kpis['total_oportunidades'] > 0) {
            $kpis['ticket_promedio_usd'] = round($suma_total_usd / $kpis['total_oportunidades'], 2);
        }
        $cerrados_totales = $kpis['ganado_count'] + $kpis['perdido_count'];
        if ($cerrados_totales > 0) {
            $kpis['tasa_cierre_pct'] = round(($kpis['ganado_count'] / $cerrados_totales) * 100.0, 1);
        } elseif ($kpis['total_oportunidades'] > 0) {
            $kpis['tasa_cierre_pct'] = round(($kpis['ganado_count'] / $kpis['total_oportunidades']) * 100.0, 1);
        }

        $kpis['pipeline_activo_usd'] = round($kpis['pipeline_activo_usd'], 2);
        $kpis['pipeline_ponderado_usd'] = round($kpis['pipeline_ponderado_usd'], 2);
        $kpis['ganado_usd'] = round($kpis['ganado_usd'], 2);
        $kpis['perdido_usd'] = round($kpis['perdido_usd'], 2);

        // Catálogo de asesores de la sede filtrada para selector y modal
        $sede_agentes = $is_master ? intval($_POST['id_sede'] ?? $_GET['id_sede'] ?? 0) : $user_sede;
        $sqlAg = "
            SELECT u.id, u.usuario, up.nombre AS up_nombre, u.id_sede
            FROM usuario u
            LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
            LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
            WHERE u.estado = 'ACTIVO'
              AND u.usuario != 'master'
              AND (
                  UPPER(TRIM(IFNULL(r.nombre, ''))) IN ('OPERADOR','ASESOR','AGENTE','VENDEDOR','ADMINISTRADOR','ADMIN','GERENTE')
                  OR UPPER(TRIM(IFNULL(CAST(u.rol AS CHAR), ''))) IN ('2','3','OPERADOR','ASESOR','AGENTE','VENDEDOR','ADMINISTRADOR','ADMIN','GERENTE')
              )
        ";
        if ($sede_agentes > 0) {
            $sqlAg .= " AND u.id_sede = $sede_agentes";
        }
        $sqlAg .= " ORDER BY up.nombre ASC, u.usuario ASC";
        $agentes_list = [];
        $rAg = $con->query($sqlAg);
        if ($rAg) {
            while ($ra = $rAg->fetch_assoc()) {
                $nom_ag = trim((string)($ra['up_nombre'] ?? ''));
                $agentes_list[] = [
                    'id' => intval($ra['id']),
                    'nombre' => ($nom_ag !== '') ? $nom_ag : $ra['usuario'],
                    'id_sede' => intval($ra['id_sede'] ?? 0),
                ];
            }
        }

        echo json_encode([
            'status'  => 'success',
            'kpis'    => $kpis,
            'stages'  => $stages,
            'agentes' => $agentes_list,
        ]);
        break;

    case 'save_deal':
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $etapa = strtoupper(trim((string)($_POST['etapa'] ?? 'NUEVO_LEAD')));
        $monto = max(0, round(floatval($_POST['monto_estimado_usd'] ?? 0), 2));
        $notas = trim((string)($_POST['notas'] ?? ''));
        $motivo_perdida = trim((string)($_POST['motivo_perdida'] ?? ''));
        $id_cliente = intval($_POST['id_cliente'] ?? 0);
        $nuevo_nombre = trim((string)($_POST['nuevo_nombre'] ?? ''));
        $nuevo_telefono = preg_replace('/[^0-9]/', '', (string)($_POST['nuevo_telefono'] ?? ''));
        $id_sede_in = $is_master ? intval($_POST['id_sede'] ?? $user_sede) : $user_sede;
        $id_agente_in = $is_operador ? $agente_id : intval($_POST['id_agente'] ?? $agente_id);

        $etapas_validas = ['NUEVO_LEAD', 'COTIZADO', 'EN_NEGOCIACION', 'PAGO_PENDIENTE', 'GANADO', 'PERDIDO'];
        if (!in_array($etapa, $etapas_validas, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Etapa de embudo no válida.']);
            exit;
        }
        if ($titulo === '') {
            echo json_encode(['status' => 'error', 'message' => 'Ingresa un título o producto para la oportunidad.']);
            exit;
        }

        $prob = pipeline_stage_probability($etapa);

        if ($deal_id > 0) {
            $existing = authorize_pipeline_deal($con, $deal_id, $is_master, $is_admin, $agente_id, $user_sede);
            if ($existing === false) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre esta oportunidad o sede.']);
                exit;
            }
            if (!$existing) {
                echo json_encode(['status' => 'error', 'message' => 'La oportunidad ya no existe.']);
                exit;
            }

            $target_sede = $is_master && $id_sede_in > 0 ? $id_sede_in : intval($existing['id_sede']);
            $target_agente = $is_operador ? intval($existing['id_agente']) : ($id_agente_in > 0 ? $id_agente_in : null);
            $cierre_sql = in_array($etapa, ['GANADO', 'PERDIDO'], true)
                ? (empty($existing['fecha_cierre']) ? ", fecha_cierre = NOW()" : "")
                : ", fecha_cierre = NULL";

            $tit_esc = $con->real_escape_string(mb_substr($titulo, 0, 195));
            $notas_esc = $con->real_escape_string($notas);
            $mot_esc = $con->real_escape_string(mb_substr($motivo_perdida, 0, 250));
            $ag_sql = $target_agente ? intval($target_agente) : "NULL";

            $ok = $con->query("
                UPDATE pipeline_oportunidades
                SET id_sede = $target_sede,
                    id_agente = $ag_sql,
                    titulo = '$tit_esc',
                    etapa = '$etapa',
                    monto_estimado_usd = $monto,
                    probabilidad = $prob,
                    notas = '$notas_esc',
                    motivo_perdida = '$mot_esc'
                    $cierre_sql
                WHERE id = $deal_id
            ");
            if (!$ok) {
                echo json_encode(['status' => 'error', 'message' => 'No se pudo actualizar la oportunidad.']);
                exit;
            }

            if ($existing['etapa'] !== $etapa || floatval($existing['monto_estimado_usd']) !== $monto) {
                sync_pipeline_conv_event($con, intval($existing['id_conversacion'] ?? 0), $etapa, $monto, $motivo_perdida);
            }

            echo json_encode(['status' => 'success', 'deal_id' => $deal_id, 'message' => 'Oportunidad actualizada correctamente.']);
            break;
        }

        // Creación de nueva oportunidad
        if ($id_sede_in <= 0) {
            $rS1 = $con->query("SELECT id FROM sedes WHERE estado = 'ACTIVO' ORDER BY id ASC LIMIT 1");
            if ($rS1 && ($rs1 = $rS1->fetch_assoc())) {
                $id_sede_in = intval($rs1['id']);
            }
        }
        if ($id_sede_in <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Selecciona una sede activa para registrar la oportunidad.']);
            exit;
        }

        if ($id_cliente <= 0) {
            if ($nuevo_telefono === '' || strlen($nuevo_telefono) < 8) {
                echo json_encode(['status' => 'error', 'message' => 'Selecciona un cliente existente o ingresa un número de WhatsApp válido.']);
                exit;
            }
            $tel_esc = $con->real_escape_string($nuevo_telefono);
            $rCli = $con->query("SELECT id FROM clientes_contactos WHERE numero_whatsapp = '$tel_esc' LIMIT 1");
            if ($rCli && ($rc = $rCli->fetch_assoc())) {
                $id_cliente = intval($rc['id']);
            } else {
                $nom_cli = $nuevo_nombre !== '' ? $nuevo_nombre : ('Prospecto ' . $nuevo_telefono);
                $nom_esc = $con->real_escape_string(mb_substr($nom_cli, 0, 120));
                $con->query("INSERT INTO clientes_contactos (numero_whatsapp, nombre, id_sede) VALUES ('$tel_esc', '$nom_esc', $id_sede_in)");
                $id_cliente = intval($con->insert_id);
            }
        } else {
            // Validar pertenencia del cliente si no es Master
            if (!$is_master) {
                $rChk = $con->query("
                    SELECT 1 FROM clientes_contactos cl
                    WHERE cl.id = $id_cliente
                      AND (cl.id_sede = $id_sede_in OR EXISTS (
                          SELECT 1 FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id
                          WHERE c.id_cliente = cl.id AND l.id_sede = $id_sede_in
                      ))
                    LIMIT 1
                ");
                if (!$rChk || $rChk->num_rows === 0) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'El cliente seleccionado pertenece a otra sede.']);
                    exit;
                }
            }
        }

        if ($id_cliente <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo vincular el cliente a la oportunidad.']);
            exit;
        }

        // Buscar conversación abierta en esa sede para vincularla automáticamente
        $id_conv_link = "NULL";
        $id_conv_num = 0;
        $rConv = $con->query("
            SELECT c.id FROM conversaciones c
            JOIN lineas_whatsapp l ON c.id_linea = l.id
            WHERE c.id_cliente = $id_cliente AND l.id_sede = $id_sede_in
            ORDER BY (c.estado NOT IN ('CERRADO','RESUELTO')) DESC, c.id DESC
            LIMIT 1
        ");
        if ($rConv && ($rcv = $rConv->fetch_assoc())) {
            $id_conv_num = intval($rcv['id']);
            $id_conv_link = (string)$id_conv_num;
        }

        $tit_esc = $con->real_escape_string(mb_substr($titulo, 0, 195));
        $notas_esc = $con->real_escape_string($notas);
        $mot_esc = $con->real_escape_string(mb_substr($motivo_perdida, 0, 250));
        $ag_sql = ($id_agente_in > 0) ? intval($id_agente_in) : "NULL";
        $cierre_val = in_array($etapa, ['GANADO', 'PERDIDO'], true) ? "NOW()" : "NULL";

        $okIns = $con->query("
            INSERT INTO pipeline_oportunidades (
                id_sede, id_cliente, id_conversacion, id_agente,
                titulo, etapa, monto_estimado_usd, probabilidad,
                notas, motivo_perdida, fecha_cierre
            ) VALUES (
                $id_sede_in, $id_cliente, $id_conv_link, $ag_sql,
                '$tit_esc', '$etapa', $monto, $prob,
                '$notas_esc', '$mot_esc', $cierre_val
            )
        ");

        if (!$okIns) {
            echo json_encode(['status' => 'error', 'message' => 'Error al crear la oportunidad en base de datos.']);
            exit;
        }

        $new_id = intval($con->insert_id);
        if ($id_conv_num > 0) {
            sync_pipeline_conv_event($con, $id_conv_num, $etapa, $monto, $motivo_perdida);
        }

        echo json_encode(['status' => 'success', 'deal_id' => $new_id, 'message' => 'Oportunidad creada en el Embudo Kanban.']);
        break;

    case 'move_stage':
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $nueva_etapa = strtoupper(trim((string)($_POST['nueva_etapa'] ?? '')));
        $motivo_perdida = trim((string)($_POST['motivo_perdida'] ?? ''));
        $ordered_ids_raw = $_POST['ordered_ids'] ?? '[]';

        $etapas_validas = ['NUEVO_LEAD', 'COTIZADO', 'EN_NEGOCIACION', 'PAGO_PENDIENTE', 'GANADO', 'PERDIDO'];
        if ($deal_id <= 0 || !in_array($nueva_etapa, $etapas_validas, true)) {
            echo json_encode(['status' => 'error', 'message' => 'Parámetros de movimiento inválidos.']);
            exit;
        }

        $existing = authorize_pipeline_deal($con, $deal_id, $is_master, $is_admin, $agente_id, $user_sede);
        if ($existing === false) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para mover oportunidades de otra sede o asesor.']);
            exit;
        }
        if (!$existing) {
            echo json_encode(['status' => 'error', 'message' => 'La oportunidad no existe.']);
            exit;
        }

        $prob = pipeline_stage_probability($nueva_etapa);
        $cierre_sql = in_array($nueva_etapa, ['GANADO', 'PERDIDO'], true)
            ? ", fecha_cierre = IFNULL(fecha_cierre, NOW())"
            : ", fecha_cierre = NULL";
        $mot_sql = ($nueva_etapa === 'PERDIDO' && $motivo_perdida !== '')
            ? ", motivo_perdida = '" . $con->real_escape_string(mb_substr($motivo_perdida, 0, 250)) . "'"
            : "";

        $con->query("UPDATE pipeline_oportunidades SET etapa = '$nueva_etapa', probabilidad = $prob $cierre_sql $mot_sql WHERE id = $deal_id");

        // Persistir orden visual dentro de la columna destino
        $ordered_ids = is_array($ordered_ids_raw) ? $ordered_ids_raw : json_decode((string)$ordered_ids_raw, true);
        if (is_array($ordered_ids) && !empty($ordered_ids)) {
            $pos = 1;
            foreach ($ordered_ids as $oid) {
                $oid_int = intval($oid);
                if ($oid_int > 0) {
                    $sede_guard = (!$is_master && $user_sede > 0) ? " AND id_sede = $user_sede" : "";
                    $con->query("UPDATE pipeline_oportunidades SET orden_kanban = $pos WHERE id = $oid_int $sede_guard");
                    $pos++;
                }
            }
        }

        if ($existing['etapa'] !== $nueva_etapa) {
            sync_pipeline_conv_event($con, intval($existing['id_conversacion'] ?? 0), $nueva_etapa, floatval($existing['monto_estimado_usd']), $motivo_perdida);
        }

        echo json_encode([
            'status'       => 'success',
            'deal_id'      => $deal_id,
            'nueva_etapa'  => $nueva_etapa,
            'probabilidad' => $prob,
        ]);
        break;

    case 'delete_deal':
        if ($is_operador) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Solo Administradores o Gerentes pueden eliminar oportunidades del embudo.']);
            exit;
        }
        $deal_id = intval($_POST['deal_id'] ?? 0);
        $existing = authorize_pipeline_deal($con, $deal_id, $is_master, $is_admin, $agente_id, $user_sede);
        if ($existing === false) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre esta sede.']);
            exit;
        }
        if (!$existing) {
            echo json_encode(['status' => 'error', 'message' => 'Oportunidad no encontrada.']);
            exit;
        }
        $con->query("DELETE FROM pipeline_oportunidades WHERE id = $deal_id");
        echo json_encode(['status' => 'success', 'message' => 'Oportunidad eliminada.']);
        break;

    case 'sync_active_chats':
        $whereSync = "WHERE c.estado IN ('ESPERA_ASIGNACION', 'ATENDIENDO') OR c.resultado_comercial = 'VENTA_CERRADA'";
        if (!$is_master) {
            if ($user_sede <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Sin sede asignada.']);
                exit;
            }
            $whereSync = "WHERE (c.estado IN ('ESPERA_ASIGNACION', 'ATENDIENDO') OR c.resultado_comercial = 'VENTA_CERRADA') AND l.id_sede = $user_sede";
        } else {
            $req_sede = intval($_POST['id_sede'] ?? 0);
            if ($req_sede > 0) {
                $whereSync = "WHERE (c.estado IN ('ESPERA_ASIGNACION', 'ATENDIENDO') OR c.resultado_comercial = 'VENTA_CERRADA') AND l.id_sede = $req_sede";
            }
        }
        if ($is_operador) {
            $whereSync .= " AND c.id_agente = $agente_id";
        }

        $resSync = $con->query("
            SELECT c.id AS id_conv, c.id_cliente, c.id_agente, c.resultado_comercial, l.id_sede,
                   cl.nombre AS cli_nombre, cl.numero_whatsapp
            FROM conversaciones c
            JOIN lineas_whatsapp l ON c.id_linea = l.id
            JOIN clientes_contactos cl ON c.id_cliente = cl.id
            $whereSync
              AND NOT EXISTS (
                  SELECT 1 FROM pipeline_oportunidades p
                  WHERE p.id_conversacion = c.id
              )
            ORDER BY c.id DESC
            LIMIT 80
        ");

        $imported = 0;
        if ($resSync) {
            while ($r = $resSync->fetch_assoc()) {
                $s_id = intval($r['id_sede']);
                $cl_id = intval($r['id_cliente']);
                $cv_id = intval($r['id_conv']);
                $ag_id = intval($r['id_agente'] ?? 0);
                $ag_sql = $ag_id > 0 ? (string)$ag_id : "NULL";
                $et = ($r['resultado_comercial'] === 'VENTA_CERRADA') ? 'GANADO' : 'NUEVO_LEAD';
                $pr = pipeline_stage_probability($et);
                $cierre_v = ($et === 'GANADO') ? "NOW()" : "NULL";
                $nom = trim((string)($r['cli_nombre'] ?: ('+' . $r['numero_whatsapp'])));
                $tit = $con->real_escape_string(mb_substr("Lead WhatsApp - $nom", 0, 195));

                if ($con->query("INSERT INTO pipeline_oportunidades (id_sede, id_cliente, id_conversacion, id_agente, titulo, etapa, monto_estimado_usd, probabilidad, fecha_cierre) VALUES ($s_id, $cl_id, $cv_id, $ag_sql, '$tit', '$et', 0.00, $pr, $cierre_v)")) {
                    $imported++;
                }
            }
        }

        echo json_encode([
            'status'   => 'success',
            'imported' => $imported,
            'message'  => $imported > 0
                ? "Se importaron $imported conversaciones activas al Embudo Kanban."
                : "Todas las conversaciones activas ya están sincronizadas en el tablero.",
        ]);
        break;

    case 'search_clients':
        $q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
        $sede_f = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
        $whereCli = "WHERE 1=1";
        if ($sede_f > 0) {
            $whereCli .= " AND (cl.id_sede = $sede_f OR EXISTS (SELECT 1 FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id WHERE c.id_cliente = cl.id AND l.id_sede = $sede_f))";
        } elseif (!$is_master) {
            $whereCli .= " AND 1=0";
        }
        if ($q !== '') {
            $q_esc = $con->real_escape_string($q);
            $q_num = preg_replace('/[^0-9]/', '', $q);
            if ($q_num !== '') {
                $q_num_esc = $con->real_escape_string($q_num);
                $whereCli .= " AND (cl.nombre LIKE '%$q_esc%' OR cl.numero_whatsapp LIKE '%$q_num_esc%')";
            } else {
                $whereCli .= " AND cl.nombre LIKE '%$q_esc%'";
            }
        }
        $resCli = $con->query("SELECT cl.id, cl.nombre, cl.numero_whatsapp, cl.id_sede FROM clientes_contactos cl $whereCli ORDER BY cl.id DESC LIMIT 25");
        $list = [];
        if ($resCli) {
            while ($rc = $resCli->fetch_assoc()) {
                $list[] = $rc;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $list]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción de pipeline no reconocida.']);
        break;
}
