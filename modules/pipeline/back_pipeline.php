<?php
// modules/pipeline/back_pipeline.php
// Backend AJAX para Tablero Kanban de Embudo de Ventas (USD) | STARFI CRM [COM-02]
// Blindado con display_errors=0, 100% mysqli_prepare(), firma canónica registrar_auditoria() y starfi_log_exception().

@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
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
    $stmt = $con->prepare("SELECT * FROM pipeline_oportunidades WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $did);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();
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
        $stmtC = $con->prepare("UPDATE conversaciones SET resultado_comercial = 'VENTA_CERRADA', fecha_cierre_venta = IFNULL(fecha_cierre_venta, NOW()) WHERE id = ?");
        if ($stmtC) {
            $stmtC->bind_param("i", $cid);
            $stmtC->execute();
            $stmtC->close();
        }
    } elseif ($etapa === 'PERDIDO') {
        $stmtC = $con->prepare("UPDATE conversaciones SET resultado_comercial = IFNULL(resultado_comercial, 'NO_INTERESADO') WHERE id = ?");
        if ($stmtC) {
            $stmtC->bind_param("i", $cid);
            $stmtC->execute();
            $stmtC->close();
        }
    }
    $lbl = pipeline_stage_label($etapa);
    $monto_fmt = number_format(floatval($monto_usd), 2);
    $extra = ($etapa === 'PERDIDO' && trim((string)$motivo_perdida) !== '') ? " · Motivo: " . trim((string)$motivo_perdida) : "";
    $txt = "💼 Embudo Kanban: Etapa [$lbl] · Monto estimado: \${$monto_fmt} USD{$extra}";

    $stmtEv = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', ?)");
    if ($stmtEv) {
        $stmtEv->bind_param("is", $cid, $txt);
        if ($stmtEv->execute()) {
            $new_mid = intval($stmtEv->insert_id);
            if ($new_mid > 0) {
                $stmtU = $con->prepare("UPDATE conversaciones SET ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?) WHERE id = ?");
                if ($stmtU) {
                    $stmtU->bind_param("ii", $new_mid, $cid);
                    $stmtU->execute();
                    $stmtU->close();
                }
            }
        }
        $stmtEv->close();
    }
}

$action = trim((string)($_POST['action'] ?? $_GET['action'] ?? ''));

switch ($action) {
    case 'get_board':
        $sede_backfill = $is_master ? intval($_POST['id_sede'] ?? $_GET['id_sede'] ?? 0) : $user_sede;
        $dbHash = starfi_db_schema_hash($con);
        $bf_flag = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . "starfi_pipe_bf_sede_{$sede_backfill}_{$dbHash}.flag";
        if (!@file_exists($bf_flag) || (time() - @filemtime($bf_flag)) > 60) {
            starfi_backfill_zero_deals($con, $sede_backfill, 100);
            @file_put_contents($bf_flag, (string)time());
        }

        $conds = ["1=1"];
        $types = "";
        $params = [];

        if (!$is_master) {
            if ($user_sede > 0) {
                $conds[] = "p.id_sede = ?";
                $types .= "i";
                $params[] = $user_sede;
            } else {
                $conds[] = "1=0";
            }
        } else {
            $req_sede = intval($_POST['id_sede'] ?? $_GET['id_sede'] ?? 0);
            if ($req_sede > 0) {
                $conds[] = "p.id_sede = ?";
                $types .= "i";
                $params[] = $req_sede;
            }
        }

        if ($is_operador) {
            $conds[] = "p.id_agente = ?";
            $types .= "i";
            $params[] = $agente_id;
        } else {
            $req_ag = intval($_POST['id_agente'] ?? $_GET['id_agente'] ?? 0);
            if ($req_ag > 0) {
                $conds[] = "p.id_agente = ?";
                $types .= "i";
                $params[] = $req_ag;
            }
        }

        $periodo = trim((string)($_POST['periodo'] ?? $_GET['periodo'] ?? '90d'));
        if ($periodo === '30d') {
            $conds[] = "p.fecha_actualizacion >= (NOW() - INTERVAL 30 DAY)";
        } elseif ($periodo === '90d') {
            $conds[] = "p.fecha_actualizacion >= (NOW() - INTERVAL 90 DAY)";
        } elseif ($periodo === 'mes') {
            $conds[] = "YEAR(p.fecha_actualizacion) = YEAR(CURDATE()) AND MONTH(p.fecha_actualizacion) = MONTH(CURDATE())";
        }

        $search = trim((string)($_POST['search'] ?? $_GET['search'] ?? ''));
        if ($search !== '') {
            $like_s = '%' . $search . '%';
            $s_num = preg_replace('/[^0-9]/', '', $search);
            if ($s_num !== '') {
                $like_num = '%' . $s_num . '%';
                $conds[] = "(p.titulo LIKE ? OR cl.nombre LIKE ? OR cl.numero_whatsapp LIKE ?)";
                $types .= "sss";
                $params[] = $like_s;
                $params[] = $like_s;
                $params[] = $like_num;
            } else {
                $conds[] = "(p.titulo LIKE ? OR cl.nombre LIKE ?)";
                $types .= "ss";
                $params[] = $like_s;
                $params[] = $like_s;
            }
        }

        $where_sql = "WHERE " . implode(" AND ", $conds);
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
                up.nombre AS asesor_up_nombre,
                u.usuario AS asesor_usuario,
                c.estado AS chat_estado,
                IFNULL(c.mensajes_no_leidos, 0) AS chat_no_leidos
            FROM pipeline_oportunidades p
            JOIN clientes_contactos cl ON p.id_cliente = cl.id
            LEFT JOIN sedes s ON p.id_sede = s.id
            LEFT JOIN usuario u ON p.id_agente = u.id
            LEFT JOIN usuario_perfil up ON p.id_agente = up.id_usuario
            LEFT JOIN conversaciones c ON p.id_conversacion = c.id
            $where_sql
            ORDER BY p.orden_kanban ASC, p.fecha_actualizacion DESC, p.id DESC
            LIMIT 400
        ";

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
        $stmtBoard = $con->prepare($sql);
        if ($stmtBoard) {
            if ($types !== '') {
                $stmtBoard->bind_param($types, ...$params);
            }
            $stmtBoard->execute();
            $res = $stmtBoard->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
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
                $up_n = trim((string)($row['asesor_up_nombre'] ?? ''));
                $us_n = trim((string)($row['asesor_usuario'] ?? ''));
                $row['asesor_nombre'] = ($up_n !== '') ? $up_n : (($us_n !== '') ? $us_n : 'Sin asignar');
                unset($row['asesor_up_nombre'], $row['asesor_usuario']);

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
            $stmtBoard->close();
        } else {
            starfi_log_exception('pipeline', 'Error preparando get_board', ['errno' => $con->errno]);
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
            $sqlAg .= " AND u.id_sede = ? ORDER BY up.nombre ASC, u.usuario ASC";
            $stmtAg = $con->prepare($sqlAg);
            if ($stmtAg) {
                $stmtAg->bind_param("i", $sede_agentes);
            }
        } else {
            $sqlAg .= " ORDER BY up.nombre ASC, u.usuario ASC";
            $stmtAg = $con->prepare($sqlAg);
        }

        $agentes_list = [];
        if ($stmtAg && $stmtAg->execute()) {
            $rAg = $stmtAg->get_result();
            while ($ra = $rAg->fetch_assoc()) {
                $nom_ag = trim((string)($ra['up_nombre'] ?? ''));
                $agentes_list[] = [
                    'id' => intval($ra['id']),
                    'nombre' => ($nom_ag !== '') ? $nom_ag : $ra['usuario'],
                    'id_sede' => intval($ra['id_sede'] ?? 0),
                ];
            }
            $stmtAg->close();
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
        $monto = max(0.0, round(floatval($_POST['monto_estimado_usd'] ?? 0), 2));
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
        $tit_clean = mb_substr($titulo, 0, 195);
        $mot_clean = mb_substr($motivo_perdida, 0, 200);

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

            $target_sede = ($is_master && $id_sede_in > 0) ? $id_sede_in : intval($existing['id_sede']);
            $target_agente = $is_operador ? intval($existing['id_agente']) : ($id_agente_in > 0 ? $id_agente_in : null);
            $is_closed = in_array($etapa, ['GANADO', 'PERDIDO'], true);
            $fecha_cierre_val = $is_closed ? (!empty($existing['fecha_cierre']) ? $existing['fecha_cierre'] : date('Y-m-d H:i:s')) : null;

            $stmtUp = $con->prepare("
                UPDATE pipeline_oportunidades
                SET id_sede = ?,
                    id_agente = ?,
                    titulo = ?,
                    etapa = ?,
                    monto_estimado_usd = ?,
                    probabilidad = ?,
                    notas = ?,
                    motivo_perdida = ?,
                    fecha_cierre = ?
                WHERE id = ?
            ");
            $ok = false;
            if ($stmtUp) {
                $stmtUp->bind_param("iissdisssi", $target_sede, $target_agente, $tit_clean, $etapa, $monto, $prob, $notas, $mot_clean, $fecha_cierre_val, $deal_id);
                $ok = $stmtUp->execute();
                if (!$ok) {
                    starfi_log_exception('pipeline', 'Error UPDATE save_deal', ['deal_id' => $deal_id, 'errno' => $stmtUp->errno]);
                }
                $stmtUp->close();
            }

            if (!$ok) {
                echo json_encode(['status' => 'error', 'message' => 'No se pudo actualizar la oportunidad.']);
                exit;
            }

            if ($existing['etapa'] !== $etapa || floatval($existing['monto_estimado_usd']) !== $monto) {
                sync_pipeline_conv_event($con, intval($existing['id_conversacion'] ?? 0), $etapa, $monto, $motivo_perdida);
            }

            registrar_auditoria(
                'pipeline',
                'ACTUALIZAR_OPORTUNIDAD',
                "Oportunidad #$deal_id actualizada ($tit_clean)",
                [
                    'id'                 => $deal_id,
                    'id_sede'            => intval($existing['id_sede']),
                    'id_agente'          => isset($existing['id_agente']) ? intval($existing['id_agente']) : null,
                    'titulo'             => $existing['titulo'] ?? '',
                    'etapa'              => $existing['etapa'] ?? '',
                    'monto_estimado_usd' => (float)($existing['monto_estimado_usd'] ?? 0),
                    'probabilidad'       => intval($existing['probabilidad'] ?? 0),
                    'notas'              => $existing['notas'] ?? null,
                    'motivo_perdida'     => $existing['motivo_perdida'] ?? null,
                ],
                [
                    'id'                 => $deal_id,
                    'id_sede'            => $target_sede,
                    'id_agente'          => $target_agente,
                    'titulo'             => $tit_clean,
                    'etapa'              => $etapa,
                    'monto_estimado_usd' => $monto,
                    'probabilidad'       => $prob,
                    'notas'              => $notas,
                    'motivo_perdida'     => $mot_clean,
                ],
                $con,
                $agente_id,
                $target_sede
            );

            echo json_encode(['status' => 'success', 'deal_id' => $deal_id, 'message' => 'Oportunidad actualizada correctamente.']);
            break;
        }

        if ($id_sede_in <= 0) {
            $stmtS1 = $con->prepare("SELECT id FROM sedes WHERE estado = 'ACTIVO' ORDER BY id ASC LIMIT 1");
            if ($stmtS1) {
                $stmtS1->execute();
                $rS1 = $stmtS1->get_result();
                if ($rS1 && ($rs1 = $rS1->fetch_assoc())) {
                    $id_sede_in = intval($rs1['id']);
                }
                $stmtS1->close();
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
            $stmtCli = $con->prepare("SELECT id FROM clientes_contactos WHERE numero_whatsapp = ? AND (id_sede = ? OR id_sede IS NULL) LIMIT 1");
            if ($stmtCli) {
                $stmtCli->bind_param("si", $nuevo_telefono, $id_sede_in);
                $stmtCli->execute();
                if ($rc = $stmtCli->get_result()->fetch_assoc()) {
                    $id_cliente = intval($rc['id']);
                }
                $stmtCli->close();
            }
            if ($id_cliente <= 0) {
                $nom_cli = mb_substr(($nuevo_nombre !== '' ? $nuevo_nombre : ('Prospecto ' . $nuevo_telefono)), 0, 120);
                $stmtInsCli = $con->prepare("INSERT INTO clientes_contactos (numero_whatsapp, nombre, id_sede) VALUES (?, ?, ?)");
                if ($stmtInsCli) {
                    $stmtInsCli->bind_param("ssi", $nuevo_telefono, $nom_cli, $id_sede_in);
                    if ($stmtInsCli->execute()) {
                        $id_cliente = intval($stmtInsCli->insert_id);
                    } else {
                        starfi_log_exception('pipeline', 'Error creando cliente en save_deal', ['errno' => $stmtInsCli->errno]);
                    }
                    $stmtInsCli->close();
                }
                if ($id_cliente > 0) {
                    registrar_auditoria(
                        'pipeline',
                        'CREAR_PROSPECTO_EMBUDO',
                        "Prospecto #$id_cliente registrado desde Embudo Kanban ($nom_cli)",
                        null,
                        [
                            'id'              => $id_cliente,
                            'id_sede'         => $id_sede_in,
                            'numero_whatsapp' => $nuevo_telefono,
                            'nombre'          => $nom_cli,
                        ],
                        $con,
                        $agente_id,
                        $id_sede_in
                    );
                }
            }
        } else {
            if (!$is_master) {
                $stmtChk = $con->prepare("
                    SELECT 1 FROM clientes_contactos cl
                    WHERE cl.id = ?
                      AND (cl.id_sede = ? OR EXISTS (
                          SELECT 1 FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id
                          WHERE c.id_cliente = cl.id AND l.id_sede = ?
                      ))
                    LIMIT 1
                ");
                $authorized_cli = false;
                if ($stmtChk) {
                    $stmtChk->bind_param("iii", $id_cliente, $id_sede_in, $id_sede_in);
                    $stmtChk->execute();
                    $authorized_cli = ($stmtChk->get_result()->num_rows > 0);
                    $stmtChk->close();
                }
                if (!$authorized_cli) {
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

        $id_conv_num = null;
        $stmtConv = $con->prepare("
            SELECT c.id FROM conversaciones c
            JOIN lineas_whatsapp l ON c.id_linea = l.id
            WHERE c.id_cliente = ? AND l.id_sede = ?
            ORDER BY (c.estado NOT IN ('CERRADO','RESUELTO')) DESC, c.id DESC
            LIMIT 1
        ");
        if ($stmtConv) {
            $stmtConv->bind_param("ii", $id_cliente, $id_sede_in);
            $stmtConv->execute();
            if ($rcv = $stmtConv->get_result()->fetch_assoc()) {
                $id_conv_num = intval($rcv['id']);
            }
            $stmtConv->close();
        }

        $ag_param = ($id_agente_in > 0) ? $id_agente_in : null;
        $fecha_cierre_ins = in_array($etapa, ['GANADO', 'PERDIDO'], true) ? date('Y-m-d H:i:s') : null;

        $stmtIns = $con->prepare("
            INSERT INTO pipeline_oportunidades (
                id_sede, id_cliente, id_conversacion, id_agente,
                titulo, etapa, monto_estimado_usd, probabilidad,
                notas, motivo_perdida, fecha_cierre
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $new_id = 0;
        if ($stmtIns) {
            $stmtIns->bind_param("iiiissdisss", $id_sede_in, $id_cliente, $id_conv_num, $ag_param, $tit_clean, $etapa, $monto, $prob, $notas, $mot_clean, $fecha_cierre_ins);
            if ($stmtIns->execute()) {
                $new_id = intval($stmtIns->insert_id);
            } else {
                starfi_log_exception('pipeline', 'Error INSERT save_deal', ['errno' => $stmtIns->errno]);
            }
            $stmtIns->close();
        }

        if ($new_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Error al crear la oportunidad en base de datos.']);
            exit;
        }

        if ($id_conv_num) {
            sync_pipeline_conv_event($con, $id_conv_num, $etapa, $monto, $motivo_perdida);
        }

        registrar_auditoria(
            'pipeline',
            'CREAR_OPORTUNIDAD',
            "Oportunidad #$new_id creada en Embudo Kanban ($tit_clean)",
            null,
            [
                'id'                 => $new_id,
                'id_sede'            => $id_sede_in,
                'id_cliente'         => $id_cliente,
                'id_conversacion'    => $id_conv_num,
                'id_agente'          => $ag_param,
                'titulo'             => $tit_clean,
                'etapa'              => $etapa,
                'monto_estimado_usd' => $monto,
                'probabilidad'       => $prob,
                'notas'              => $notas,
                'motivo_perdida'     => $mot_clean,
            ],
            $con,
            $agente_id,
            $id_sede_in
        );

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
        $is_closed = in_array($nueva_etapa, ['GANADO', 'PERDIDO'], true);
        $fecha_cierre_mv = $is_closed ? (!empty($existing['fecha_cierre']) ? $existing['fecha_cierre'] : date('Y-m-d H:i:s')) : null;
        $mot_mv = ($nueva_etapa === 'PERDIDO' && $motivo_perdida !== '')
            ? mb_substr($motivo_perdida, 0, 200)
            : (string)($existing['motivo_perdida'] ?? '');

        $stmtMv = $con->prepare("UPDATE pipeline_oportunidades SET etapa = ?, probabilidad = ?, motivo_perdida = ?, fecha_cierre = ? WHERE id = ?");
        if ($stmtMv) {
            $stmtMv->bind_param("sissi", $nueva_etapa, $prob, $mot_mv, $fecha_cierre_mv, $deal_id);
            if (!$stmtMv->execute()) {
                starfi_log_exception('pipeline', 'Error UPDATE move_stage', ['deal_id' => $deal_id, 'errno' => $stmtMv->errno]);
            }
            $stmtMv->close();
        }

        $ordered_ids = is_array($ordered_ids_raw) ? $ordered_ids_raw : json_decode((string)$ordered_ids_raw, true);
        if (is_array($ordered_ids) && !empty($ordered_ids)) {
            if (!$is_master && $user_sede > 0) {
                $stmtOrd = $con->prepare("UPDATE pipeline_oportunidades SET orden_kanban = ? WHERE id = ? AND id_sede = ?");
            } else {
                $stmtOrd = $con->prepare("UPDATE pipeline_oportunidades SET orden_kanban = ? WHERE id = ?");
            }
            if ($stmtOrd) {
                $pos = 1;
                foreach ($ordered_ids as $oid) {
                    $oid_int = intval($oid);
                    if ($oid_int > 0) {
                        if (!$is_master && $user_sede > 0) {
                            $stmtOrd->bind_param("iii", $pos, $oid_int, $user_sede);
                        } else {
                            $stmtOrd->bind_param("ii", $pos, $oid_int);
                        }
                        $stmtOrd->execute();
                        $pos++;
                    }
                }
                $stmtOrd->close();
            }
        }

        $etapaChanged = ($existing['etapa'] !== $nueva_etapa);
        if ($etapaChanged) {
            sync_pipeline_conv_event($con, intval($existing['id_conversacion'] ?? 0), $nueva_etapa, floatval($existing['monto_estimado_usd']), $motivo_perdida);
        }

        registrar_auditoria(
            'pipeline',
            $etapaChanged ? 'MOVER_ETAPA_KANBAN' : 'REORDENAR_KANBAN',
            $etapaChanged
                ? "Oportunidad #$deal_id movida de {$existing['etapa']} a $nueva_etapa"
                : "Oportunidad #$deal_id reordenada en etapa $nueva_etapa",
            [
                'id'                 => $deal_id,
                'etapa'              => $existing['etapa'] ?? '',
                'probabilidad'       => intval($existing['probabilidad'] ?? 0),
                'monto_estimado_usd' => (float)($existing['monto_estimado_usd'] ?? 0),
                'motivo_perdida'     => $existing['motivo_perdida'] ?? null,
                'orden_kanban'       => intval($existing['orden_kanban'] ?? 0),
            ],
            [
                'id'                 => $deal_id,
                'etapa'              => $nueva_etapa,
                'probabilidad'       => $prob,
                'monto_estimado_usd' => (float)($existing['monto_estimado_usd'] ?? 0),
                'motivo_perdida'     => $mot_mv,
            ],
            $con,
            $agente_id,
            intval($existing['id_sede'])
        );

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

        $stmtDel = $con->prepare("DELETE FROM pipeline_oportunidades WHERE id = ?");
        if ($stmtDel) {
            $stmtDel->bind_param("i", $deal_id);
            if (!$stmtDel->execute()) {
                starfi_log_exception('pipeline', 'Error DELETE delete_deal', ['deal_id' => $deal_id, 'errno' => $stmtDel->errno]);
            }
            $stmtDel->close();
        }

        registrar_auditoria(
            'pipeline',
            'ELIMINAR_OPORTUNIDAD',
            "Oportunidad #$deal_id eliminada (" . ($existing['titulo'] ?? '') . ")",
            [
                'id'                 => $deal_id,
                'id_sede'            => intval($existing['id_sede']),
                'id_cliente'         => intval($existing['id_cliente'] ?? 0),
                'id_agente'          => isset($existing['id_agente']) ? intval($existing['id_agente']) : null,
                'titulo'             => $existing['titulo'] ?? '',
                'etapa'              => $existing['etapa'] ?? '',
                'monto_estimado_usd' => (float)($existing['monto_estimado_usd'] ?? 0),
                'notas'              => $existing['notas'] ?? null,
            ],
            ['eliminado' => true, 'id' => $deal_id],
            $con,
            $agente_id,
            intval($existing['id_sede'])
        );

        echo json_encode(['status' => 'success', 'message' => 'Oportunidad eliminada.']);
        break;

    case 'sync_active_chats':
        $condSync = ["(c.estado IN ('ESPERA_ASIGNACION', 'ATENDIENDO') OR c.resultado_comercial = 'VENTA_CERRADA')"];
        $sTypes = "";
        $sParams = [];

        if (!$is_master) {
            if ($user_sede <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Sin sede asignada.']);
                exit;
            }
            $condSync[] = "l.id_sede = ?";
            $sTypes .= "i";
            $sParams[] = $user_sede;
        } else {
            $req_sede = intval($_POST['id_sede'] ?? 0);
            if ($req_sede > 0) {
                $condSync[] = "l.id_sede = ?";
                $sTypes .= "i";
                $sParams[] = $req_sede;
            }
        }
        if ($is_operador) {
            $condSync[] = "c.id_agente = ?";
            $sTypes .= "i";
            $sParams[] = $agente_id;
        }

        $sqlSync = "
            SELECT c.id AS id_conv, c.id_cliente, c.id_agente, c.resultado_comercial, l.id_sede,
                   cl.nombre AS cli_nombre, cl.numero_whatsapp
            FROM conversaciones c
            JOIN lineas_whatsapp l ON c.id_linea = l.id
            JOIN clientes_contactos cl ON c.id_cliente = cl.id
            WHERE " . implode(" AND ", $condSync) . "
              AND NOT EXISTS (
                  SELECT 1 FROM pipeline_oportunidades p
                  WHERE p.id_conversacion = c.id
              )
            ORDER BY c.id DESC
            LIMIT 80
        ";

        $imported = 0;
        $stmtSync = $con->prepare($sqlSync);
        $rowsSync = [];
        if ($stmtSync) {
            if ($sTypes !== '') {
                $stmtSync->bind_param($sTypes, ...$sParams);
            }
            $stmtSync->execute();
            $resSync = $stmtSync->get_result();
            while ($r = $resSync->fetch_assoc()) {
                $rowsSync[] = $r;
            }
            $stmtSync->close();
        }

        $stmtInsSync = $con->prepare("
            INSERT INTO pipeline_oportunidades
                (id_sede, id_cliente, id_conversacion, id_agente, titulo, etapa, monto_estimado_usd, probabilidad, notas, fecha_cierre)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($rowsSync as $r) {
            $s_id = intval($r['id_sede']);
            $cl_id = intval($r['id_cliente']);
            $cv_id = intval($r['id_conv']);
            $ag_id = intval($r['id_agente'] ?? 0);

            $enr = starfi_extract_deal_enrichment($con, $s_id, $cl_id, $cv_id);
            if ($ag_id <= 0 && $enr['id_agente_sugerido'] > 0) {
                $ag_id = intval($enr['id_agente_sugerido']);
            }
            $ag_param = ($ag_id > 0) ? $ag_id : null;
            $et = ($r['resultado_comercial'] === 'VENTA_CERRADA') ? 'GANADO' : 'NUEVO_LEAD';
            $pr = pipeline_stage_probability($et);
            $cierre_v = ($et === 'GANADO') ? date('Y-m-d H:i:s') : null;
            $nom = trim((string)($r['cli_nombre'] ?: ('+' . $r['numero_whatsapp'])));
            $tit = mb_substr("Lead WhatsApp - $nom" . ($enr['correlativo'] !== '' ? ' · ' . $enr['correlativo'] : ''), 0, 195);
            $monto_v = max(0.0, floatval($enr['monto_usd']));

            $notas_v = null;
            if ($enr['correlativo'] !== '' || $enr['asesor_erp'] !== '' || $enr['monto_raw'] !== '') {
                $np = [];
                if ($enr['correlativo'] !== '') $np[] = "Documento: " . $enr['correlativo'];
                if ($enr['monto_raw'] !== '') $np[] = "Monto mensaje: " . $enr['monto_raw'];
                if ($enr['asesor_erp'] !== '') $np[] = "Vendedor ERP: " . $enr['asesor_erp'];
                if ($enr['fecha_doc'] !== '') $np[] = "Fecha: " . $enr['fecha_doc'];
                $notas_v = implode(' | ', $np);
            }

            if ($stmtInsSync) {
                $stmtInsSync->bind_param("iiiissdiss", $s_id, $cl_id, $cv_id, $ag_param, $tit, $et, $monto_v, $pr, $notas_v, $cierre_v);
                if ($stmtInsSync->execute()) {
                    $imported++;
                }
            }
        }
        if ($stmtInsSync) {
            $stmtInsSync->close();
        }

        $sede_bf = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
        $updated_zeros = starfi_backfill_zero_deals($con, $sede_bf, 250);

        if ($imported > 0 || $updated_zeros > 0) {
            registrar_auditoria(
                'pipeline',
                'SINCRONIZAR_CHATS_KANBAN',
                "Sincronización Kanban ejecutada ($imported nuevas, $updated_zeros montos USD actualizados)",
                ['id_sede' => $sede_bf],
                [
                    'imported'      => $imported,
                    'updated_zeros' => $updated_zeros,
                    'id_sede'       => $sede_bf,
                ],
                $con,
                $agente_id,
                $sede_bf
            );
        }

        $msg_parts = [];
        if ($imported > 0) $msg_parts[] = "$imported conversaciones nuevas importadas";
        if ($updated_zeros > 0) $msg_parts[] = "$updated_zeros montos USD sincronizados desde mensajes";

        echo json_encode([
            'status'        => 'success',
            'imported'      => $imported,
            'updated_zeros' => $updated_zeros,
            'message'       => !empty($msg_parts)
                ? ('Sincronización completada: ' . implode(' y ', $msg_parts) . '.')
                : 'Todas las conversaciones y montos USD ya están sincronizados.',
        ]);
        break;

    case 'search_clients':
        $q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
        $sede_f = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
        $condCli = ["1=1"];
        $cTypes = "";
        $cParams = [];

        if ($sede_f > 0) {
            $condCli[] = "(cl.id_sede = ? OR EXISTS (SELECT 1 FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id WHERE c.id_cliente = cl.id AND l.id_sede = ?))";
            $cTypes .= "ii";
            $cParams[] = $sede_f;
            $cParams[] = $sede_f;
        } elseif (!$is_master) {
            $condCli[] = "1=0";
        }

        if ($q !== '') {
            $q_like = '%' . $q . '%';
            $q_num = preg_replace('/[^0-9]/', '', $q);
            if ($q_num !== '') {
                $q_num_like = '%' . $q_num . '%';
                $condCli[] = "(cl.nombre LIKE ? OR cl.numero_whatsapp LIKE ?)";
                $cTypes .= "ss";
                $cParams[] = $q_like;
                $cParams[] = $q_num_like;
            } else {
                $condCli[] = "cl.nombre LIKE ?";
                $cTypes .= "s";
                $cParams[] = $q_like;
            }
        }

        $sqlCli = "SELECT cl.id, cl.nombre, cl.numero_whatsapp, cl.id_sede FROM clientes_contactos cl WHERE " . implode(" AND ", $condCli) . " ORDER BY cl.id DESC LIMIT 25";
        $list = [];
        $stmtCli = $con->prepare($sqlCli);
        if ($stmtCli) {
            if ($cTypes !== '') {
                $stmtCli->bind_param($cTypes, ...$cParams);
            }
            $stmtCli->execute();
            $resCli = $stmtCli->get_result();
            while ($rc = $resCli->fetch_assoc()) {
                $list[] = $rc;
            }
            $stmtCli->close();
        }
        echo json_encode(['status' => 'success', 'data' => $list]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción de pipeline no reconocida.']);
        break;
}
