<?php
// modules/directorio/back_directorio.php
// Controlador del Directorio 360 de Clientes — STARFI CRM (QA-STD-GLOBAL-2.1)
// Blindado con RBAC, 100% mysqli_prepare(), cero fugas SQL, starfi_log_exception() y registrar_auditoria().

@ini_set('display_errors', '0');
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('directorio');
$agente_dir = getAgenteInfo();
session_write_close();
header('Content-Type: application/json; charset=utf-8');

$con = getDbConnection();
if (!$con) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos.']);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));

$agente_id_dir = intval($agente_dir['id'] ?? 0);
$rol_dir       = strtoupper(trim((string)($agente_dir['rol'] ?? 'AGENTE')));
$user_sede_dir = isset($agente_dir['id_sede']) ? intval($agente_dir['id_sede']) : 0;
$is_master_dir = ($rol_dir === 'MASTER' || $rol_dir === 'MASTER CI' || $agente_id_dir === 1 || ($rol_dir === 'ADMIN' && $user_sede_dir === 0));

function authorize_directorio_cliente($con, $cliente_id, $is_master, $user_sede) {
    $cid = intval($cliente_id);
    if ($cid <= 0) {
        return false;
    }
    if ($is_master) {
        return true;
    }
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
                  FROM conversaciones cv2
                  INNER JOIN lineas_whatsapp lw2 ON lw2.id = cv2.id_linea
                  WHERE cv2.id_cliente = cl.id AND lw2.id_sede = ?
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

switch ($action) {
    case 'get_sedes':
        $sedes = [];
        if (!$is_master_dir && $user_sede_dir > 0) {
            $stmt = $con->prepare("SELECT id, nombre_sede FROM sedes WHERE id = ? ORDER BY nombre_sede ASC");
            if ($stmt) {
                $stmt->bind_param("i", $user_sede_dir);
            }
        } else {
            $stmt = $con->prepare("SELECT id, nombre_sede FROM sedes ORDER BY nombre_sede ASC");
        }
        if ($stmt) {
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $sedes[] = $row;
                }
            } else {
                starfi_log_exception('directorio', 'Error ejecutando get_sedes', ['errno' => $stmt->errno]);
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'success', 'is_master' => $is_master_dir, 'data' => $sedes]);
        break;

    case 'load_clients':
        if (!$is_master_dir && $user_sede_dir <= 0) {
            echo json_encode(['status' => 'success', 'data' => []]);
            break;
        }

        if (!$is_master_dir) {
            $stmt = $con->prepare("
                SELECT c.id, c.id_sede, c.nombre, c.numero_whatsapp, c.estado, c.etiquetas, c.fecha_registro, s.nombre_sede 
                FROM clientes_contactos c 
                LEFT JOIN sedes s ON c.id_sede = s.id 
                WHERE (c.id_sede = ? OR EXISTS (
                    SELECT 1 FROM conversaciones cv2
                    INNER JOIN lineas_whatsapp lw2 ON lw2.id = cv2.id_linea
                    WHERE cv2.id_cliente = c.id AND lw2.id_sede = ?
                ))
                ORDER BY c.fecha_registro DESC
            ");
            if ($stmt) {
                $stmt->bind_param("ii", $user_sede_dir, $user_sede_dir);
            }
        } else {
            $stmt = $con->prepare("
                SELECT c.id, c.id_sede, c.nombre, c.numero_whatsapp, c.estado, c.etiquetas, c.fecha_registro, s.nombre_sede 
                FROM clientes_contactos c 
                LEFT JOIN sedes s ON c.id_sede = s.id 
                ORDER BY c.fecha_registro DESC
            ");
        }

        $clients = [];
        if ($stmt) {
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($res && ($row = $res->fetch_assoc())) {
                    $clients[] = $row;
                }
            } else {
                starfi_log_exception('directorio', 'Error ejecutando load_clients', ['errno' => $stmt->errno]);
            }
            $stmt->close();
        }
        echo json_encode(['status' => 'success', 'data' => $clients]);
        break;

    case 'get_broadcast_config':
    case 'create_broadcast_campaign':
    case 'send_broadcast_item':
    case 'finish_broadcast_campaign':
        http_response_code(410);
        echo json_encode([
            'status'     => 'error',
            'deprecated' => true,
            'redirect'   => '../campanas/campanas.php',
            'message'    => 'La lógica de campañas de difusión fue centralizada exclusivamente en el módulo Campañas (modules/campanas/back_campanas.php).',
        ]);
        break;

    case 'load_profile':
        $id = intval($_POST['id'] ?? 0);
        if (!authorize_directorio_cliente($con, $id, $is_master_dir, $user_sede_dir)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
            exit;
        }
        $stmt = $con->prepare("SELECT c.*, s.nombre_sede FROM clientes_contactos c LEFT JOIN sedes s ON c.id_sede = s.id WHERE c.id = ? LIMIT 1");
        if (!$stmt) {
            starfi_log_exception('directorio', 'Error preparando load_profile', ['errno' => $con->errno]);
            echo json_encode(['status' => 'error', 'message' => 'Error al consultar el perfil del cliente.']);
            break;
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $client = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$client) {
            echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado']);
            break;
        }

        $convCols = [];
        $stmtCols = @$con->prepare("SHOW COLUMNS FROM `conversaciones`");
        if ($stmtCols && $stmtCols->execute()) {
            $colsConvRes = $stmtCols->get_result();
            if ($colsConvRes) {
                while ($rc = $colsConvRes->fetch_assoc()) {
                    $convCols[$rc['Field']] = true;
                }
            }
            $stmtCols->close();
        } elseif ($stmtCols) {
            $stmtCols->close();
        }
        $resComSelect = isset($convCols['resultado_comercial']) ? "conv.resultado_comercial" : "NULL AS resultado_comercial";
        $fecCierreSelect = isset($convCols['fecha_cierre_venta']) ? "conv.fecha_cierre_venta" : "NULL AS fecha_cierre_venta";
        $ventasExtraCond = isset($convCols['resultado_comercial'])
            ? "OR conv.resultado_comercial = 'VENTA_CERRADA' OR conv.resultado_comercial LIKE '%VENTA%'"
            : "";

        // 1. Historial de Conversaciones (100% mysqli_prepare())
        if (!$is_master_dir && $user_sede_dir > 0) {
            $stmt_conv = $con->prepare("
                SELECT conv.id, conv.estado, conv.fecha_inicio, conv.fecha_resolucion, $resComSelect,
                       COALESCE(up.nombre, 'Sin Asesor') AS agente_nombre,
                       (SELECT contenido FROM mensajes_y_eventos WHERE id_conversacion = conv.id ORDER BY id DESC LIMIT 1) AS ultimo_mensaje,
                       (SELECT timestamp FROM mensajes_y_eventos WHERE id_conversacion = conv.id ORDER BY id DESC LIMIT 1) AS ultimo_timestamp
                FROM conversaciones conv
                LEFT JOIN lineas_whatsapp lw ON conv.id_linea = lw.id
                LEFT JOIN usuario_perfil up ON conv.id_agente = up.id_usuario
                WHERE conv.id_cliente = ? AND lw.id_sede = ?
                ORDER BY conv.fecha_inicio DESC
            ");
            if ($stmt_conv) {
                $stmt_conv->bind_param("ii", $id, $user_sede_dir);
            }
        } else {
            $stmt_conv = $con->prepare("
                SELECT conv.id, conv.estado, conv.fecha_inicio, conv.fecha_resolucion, $resComSelect,
                       COALESCE(up.nombre, 'Sin Asesor') AS agente_nombre,
                       (SELECT contenido FROM mensajes_y_eventos WHERE id_conversacion = conv.id ORDER BY id DESC LIMIT 1) AS ultimo_mensaje,
                       (SELECT timestamp FROM mensajes_y_eventos WHERE id_conversacion = conv.id ORDER BY id DESC LIMIT 1) AS ultimo_timestamp
                FROM conversaciones conv
                LEFT JOIN lineas_whatsapp lw ON conv.id_linea = lw.id
                LEFT JOIN usuario_perfil up ON conv.id_agente = up.id_usuario
                WHERE conv.id_cliente = ?
                ORDER BY conv.fecha_inicio DESC
            ");
            if ($stmt_conv) {
                $stmt_conv->bind_param("i", $id);
            }
        }

        $conversaciones = [];
        if ($stmt_conv) {
            $stmt_conv->execute();
            $conv_res = $stmt_conv->get_result();
            while ($conv_res && ($c = $conv_res->fetch_assoc())) {
                $conversaciones[] = $c;
            }
            $stmt_conv->close();
        }

        // 2. Historial de Ventas (100% mysqli_prepare())
        if (!$is_master_dir && $user_sede_dir > 0) {
            $stmt_ventas = $con->prepare("
                SELECT m.tipo, m.origen, m.contenido, m.timestamp, conv.id AS id_conversacion, $fecCierreSelect, $resComSelect
                FROM mensajes_y_eventos m
                JOIN conversaciones conv ON m.id_conversacion = conv.id
                LEFT JOIN lineas_whatsapp lw ON conv.id_linea = lw.id
                WHERE conv.id_cliente = ? AND lw.id_sede = ?
                  AND (
                      (m.origen = 'API_TRANSACCIONAL'
                       AND m.contenido NOT LIKE '%starfi_difusion_directa%'
                       AND m.contenido NOT LIKE '%[DIFUSIÓN:%'
                       AND m.contenido NOT LIKE '%| Texto:%'
                       AND IFNULL(m.categoria_meta, '') != 'MARKETING')
                      OR m.tipo = 'VENTA'
                      $ventasExtraCond
                  )
                ORDER BY m.timestamp DESC LIMIT 30
            ");
            if ($stmt_ventas) {
                $stmt_ventas->bind_param("ii", $id, $user_sede_dir);
            }
        } else {
            $stmt_ventas = $con->prepare("
                SELECT m.tipo, m.origen, m.contenido, m.timestamp, conv.id AS id_conversacion, $fecCierreSelect, $resComSelect
                FROM mensajes_y_eventos m
                JOIN conversaciones conv ON m.id_conversacion = conv.id
                LEFT JOIN lineas_whatsapp lw ON conv.id_linea = lw.id
                WHERE conv.id_cliente = ?
                  AND (
                      (m.origen = 'API_TRANSACCIONAL'
                       AND m.contenido NOT LIKE '%starfi_difusion_directa%'
                       AND m.contenido NOT LIKE '%[DIFUSIÓN:%'
                       AND m.contenido NOT LIKE '%| Texto:%'
                       AND IFNULL(m.categoria_meta, '') != 'MARKETING')
                      OR m.tipo = 'VENTA'
                      $ventasExtraCond
                  )
                ORDER BY m.timestamp DESC LIMIT 30
            ");
            if ($stmt_ventas) {
                $stmt_ventas->bind_param("i", $id);
            }
        }

        $ventas = [];
        if ($stmt_ventas) {
            $stmt_ventas->execute();
            $ventas_res = $stmt_ventas->get_result();
            while ($ventas_res && ($v = $ventas_res->fetch_assoc())) {
                $ventas[] = $v;
            }
            $stmt_ventas->close();
        }

        echo json_encode([
            'status' => 'success',
            'data'   => [
                'client'         => $client,
                'conversaciones' => $conversaciones,
                'ventas'         => $ventas,
                'events'         => $ventas,
            ],
        ]);
        break;

    case 'save_profile':
        $id = intval($_POST['id'] ?? 0);
        if (!authorize_directorio_cliente($con, $id, $is_master_dir, $user_sede_dir)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para modificar este cliente.']);
            exit;
        }
        $nombre          = trim((string)($_POST['nombre'] ?? ''));
        $numero_whatsapp = preg_replace('/[^0-9]/', '', (string)($_POST['numero_whatsapp'] ?? ''));
        $direccion       = trim((string)($_POST['direccion'] ?? ''));
        $notas           = trim((string)($_POST['notas'] ?? ''));
        if ($is_master_dir) {
            $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : null;
        } else {
            $id_sede = ($user_sede_dir > 0) ? $user_sede_dir : null;
        }

        if ($id <= 0 || $nombre === '') {
            echo json_encode(['status' => 'error', 'message' => 'Datos de cliente inválidos.']);
            break;
        }

        // Capturar estado anterior para auditoría formal
        $prevClient = null;
        $stmtPrev = $con->prepare("SELECT id, id_sede, nombre, numero_whatsapp, direccion, notas_internas, etiquetas, estado FROM clientes_contactos WHERE id = ? LIMIT 1");
        if ($stmtPrev) {
            $stmtPrev->bind_param("i", $id);
            $stmtPrev->execute();
            $prevClient = $stmtPrev->get_result()->fetch_assoc();
            $stmtPrev->close();
        }

        $etiquetas_json = isset($_POST['etiquetas'])
            ? trim((string)$_POST['etiquetas'])
            : ($prevClient['etiquetas'] ?? null);

        $okUpdate = false;
        if ($numero_whatsapp !== '') {
            $stmt = $con->prepare("UPDATE clientes_contactos SET nombre = ?, numero_whatsapp = ?, direccion = ?, notas_internas = ?, etiquetas = ?, id_sede = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("sssssii", $nombre, $numero_whatsapp, $direccion, $notas, $etiquetas_json, $id_sede, $id);
                $okUpdate = $stmt->execute();
                if (!$okUpdate) {
                    starfi_log_exception('directorio', 'Error UPDATE save_profile con teléfono', ['id' => $id, 'errno' => $stmt->errno]);
                }
                $stmt->close();
            }
        } else {
            $stmt = $con->prepare("UPDATE clientes_contactos SET nombre = ?, direccion = ?, notas_internas = ?, etiquetas = ?, id_sede = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ssssii", $nombre, $direccion, $notas, $etiquetas_json, $id_sede, $id);
                $okUpdate = $stmt->execute();
                if (!$okUpdate) {
                    starfi_log_exception('directorio', 'Error UPDATE save_profile sin teléfono', ['id' => $id, 'errno' => $stmt->errno]);
                }
                $stmt->close();
            }
        }

        if ($okUpdate) {
            registrar_auditoria(
                'directorio',
                'ACTUALIZAR_CLIENTE',
                "Ficha de cliente #$id actualizada ($nombre)",
                $prevClient,
                [
                    'id'              => $id,
                    'id_sede'         => $id_sede,
                    'nombre'          => $nombre,
                    'numero_whatsapp' => ($numero_whatsapp !== '') ? $numero_whatsapp : ($prevClient['numero_whatsapp'] ?? ''),
                    'direccion'       => $direccion,
                    'notas_internas'  => $notas,
                ],
                $con,
                $agente_id_dir,
                $id_sede ?? $user_sede_dir
            );
            echo json_encode(['status' => 'success', 'message' => 'Perfil actualizado']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al actualizar el perfil (verifique que el número no esté duplicado en la sede).']);
        }
        break;

    case 'check_duplicate':
        $numero_whatsapp = preg_replace('/[^0-9]/', '', (string)($_POST['numero_whatsapp'] ?? ''));
        $id_sede = $is_master_dir
            ? (!empty($_POST['id_sede']) ? intval($_POST['id_sede']) : 0)
            : $user_sede_dir;

        if ($numero_whatsapp === '') {
            echo json_encode(['status' => 'error', 'message' => 'Número vacío']);
            exit;
        }

        if ($id_sede > 0) {
            $stmt = $con->prepare("SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = ? AND id_sede = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("si", $numero_whatsapp, $id_sede);
            }
        } else {
            $stmt = $con->prepare("SELECT id, nombre FROM clientes_contactos WHERE numero_whatsapp = ? AND id_sede IS NULL LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $numero_whatsapp);
            }
        }

        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $client = $res->fetch_assoc();
                echo json_encode(['status' => 'exists', 'client' => $client]);
            } else {
                echo json_encode(['status' => 'clean']);
            }
            $stmt->close();
        } else {
            starfi_log_exception('directorio', 'Error preparando check_duplicate', ['errno' => $con->errno]);
            echo json_encode(['status' => 'clean']);
        }
        break;

    case 'create_profile':
        $nombre    = trim((string)($_POST['nombre'] ?? ''));
        $direccion = trim((string)($_POST['direccion'] ?? ''));
        $notas     = trim((string)($_POST['notas'] ?? ''));
        if ($is_master_dir) {
            $id_sede = !empty($_POST['id_sede']) ? intval($_POST['id_sede']) : null;
        } else {
            if ($user_sede_dir <= 0) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Tu usuario no tiene una sede asignada.']);
                exit;
            }
            $id_sede = $user_sede_dir;
        }
        $numero_whatsapp = preg_replace('/[^0-9]/', '', (string)($_POST['numero_whatsapp'] ?? ''));

        if ($numero_whatsapp === '' || $nombre === '') {
            echo json_encode(['status' => 'error', 'message' => 'Nombre y número son obligatorios']);
            exit;
        }

        $id_empresa = max(1, intval($agente_dir['id_empresa'] ?? 1));
        $stmt = $con->prepare("INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre, direccion, notas_internas) VALUES (?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iissss", $id_empresa, $id_sede, $numero_whatsapp, $nombre, $direccion, $notas);
            if ($stmt->execute()) {
                $newClientId = intval($stmt->insert_id);
                $stmt->close();
                registrar_auditoria(
                    'directorio',
                    'CREAR_CLIENTE',
                    "Nuevo cliente #$newClientId registrado en directorio ($nombre)",
                    null,
                    [
                        'id'              => $newClientId,
                        'id_empresa'      => $id_empresa,
                        'id_sede'         => $id_sede,
                        'numero_whatsapp' => $numero_whatsapp,
                        'nombre'          => $nombre,
                        'direccion'       => $direccion,
                        'notas_internas'  => $notas,
                    ],
                    $con,
                    $agente_id_dir,
                    $id_sede
                );
                echo json_encode(['status' => 'success', 'id' => $newClientId, 'message' => 'Cliente creado con éxito']);
            } else {
                starfi_log_exception('directorio', 'Error INSERT create_profile', ['errno' => $stmt->errno]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'El número de WhatsApp ya podría estar registrado para esta sede.']);
            }
        } else {
            starfi_log_exception('directorio', 'Error preparando INSERT create_profile', ['errno' => $con->errno]);
            echo json_encode(['status' => 'error', 'message' => 'Error interno al preparar el registro del cliente.']);
        }
        break;

    case 'delete_profile':
        if (!$is_master_dir && !in_array($rol_dir, ['ADMINISTRADOR', 'ADMIN', 'GERENTE'], true)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Solo Administradores o MASTER pueden eliminar contactos.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        if (!authorize_directorio_cliente($con, $id, $is_master_dir, $user_sede_dir)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes autorización sobre este cliente o sede.']);
            exit;
        }
        $prevDel = null;
        $stmtP = $con->prepare("SELECT * FROM clientes_contactos WHERE id = ? LIMIT 1");
        if ($stmtP) {
            $stmtP->bind_param("i", $id);
            $stmtP->execute();
            $prevDel = $stmtP->get_result()->fetch_assoc();
            $stmtP->close();
        }
        if (!$prevDel) {
            echo json_encode(['status' => 'error', 'message' => 'Cliente no encontrado.']);
            break;
        }
        $stmtDel = $con->prepare("DELETE FROM clientes_contactos WHERE id = ?");
        $okDel = false;
        if ($stmtDel) {
            $stmtDel->bind_param("i", $id);
            $okDel = $stmtDel->execute();
            if (!$okDel) {
                starfi_log_exception('directorio', 'Error DELETE delete_profile', ['id' => $id, 'errno' => $stmtDel->errno]);
            }
            $stmtDel->close();
        }
        if ($okDel) {
            registrar_auditoria(
                'directorio',
                'ELIMINAR_CLIENTE',
                "Cliente #$id eliminado del directorio (" . ($prevDel['nombre'] ?? '') . ")",
                $prevDel,
                ['eliminado' => true, 'id' => $id],
                $con,
                $agente_id_dir,
                intval($prevDel['id_sede'] ?? $user_sede_dir)
            );
            echo json_encode(['status' => 'success', 'message' => 'Cliente eliminado correctamente.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar el cliente.']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
        break;
}
