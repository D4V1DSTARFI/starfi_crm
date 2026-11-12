<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';

$agente = getAgenteInfo();
$agente_id = intval($agente['id'] ?? ($_SESSION['agente_id'] ?? 0));
$rol_raw = strtoupper(trim($agente['rol'] ?? 'AGENTE'));
$is_master = ($agente_id === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI');
$user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;

session_write_close();

if (!$is_master && $user_sede <= 0) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Usuario sin sede asignada. Acceso denegado.']);
    exit;
}

if (!$is_master) {
    $_POST['id_sede'] = $user_sede;
    $_REQUEST['id_sede'] = $user_sede;
}

switch ($action) {
    case 'load_rules':
        $id_sede = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
        $rules = [];
        if ($id_sede > 0) {
            $stmt = $con->prepare("SELECT * FROM bot_respuestas WHERE id_empresa = 1 AND id_sede = ? ORDER BY tipo ASC, id ASC");
            if ($stmt) {
                $stmt->bind_param("i", $id_sede);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $rules[] = $row;
                }
                $stmt->close();
            }
        } else {
            $res = $con->query("SELECT * FROM bot_respuestas WHERE id_empresa = 1 ORDER BY tipo ASC, id ASC");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $rules[] = $row;
                }
            }
        }
        echo json_encode(['status' => 'success', 'data' => $rules]);
        break;

    case 'save_rule':
        $id = intval($_POST['id'] ?? 0);
        $id_sede = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
        $tipo = $_POST['tipo'] ?? '';
        $disparador = $_POST['disparador'] ?? '';
        $mensaje = $_POST['mensaje'] ?? '';
        $estado = $_POST['estado'] ?? 'ACTIVO';

        // Campos para Flujos Avanzados
        $id_padre = !empty($_POST['id_padre']) ? intval($_POST['id_padre']) : null;
        $formato_respuesta = $_POST['formato_respuesta'] ?? 'TEXTO';
        $media_url = !empty($_POST['media_url']) ? $_POST['media_url'] : null;
        $latitud = !empty($_POST['latitud']) ? $_POST['latitud'] : null;
        $longitud = !empty($_POST['longitud']) ? $_POST['longitud'] : null;
        $espera_respuesta = isset($_POST['espera_respuesta']) && $_POST['espera_respuesta'] == '1' ? 1 : 0;

        if (empty($tipo) || empty($disparador) || empty($mensaje) || $id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Datos incompletos o falta seleccionar Sede.']);
            exit;
        }

        // Validar que id_padre pertenezca a la misma sede si se especifica
        if ($id_padre !== null && $id_padre > 0 && !$is_master) {
            $stmtChkPadre = $con->prepare("SELECT id FROM bot_respuestas WHERE id = ? AND id_sede = ? LIMIT 1");
            if ($stmtChkPadre) {
                $stmtChkPadre->bind_param("ii", $id_padre, $user_sede);
                $stmtChkPadre->execute();
                $stmtChkPadre->store_result();
                $okPadre = ($stmtChkPadre->num_rows > 0);
                $stmtChkPadre->close();
                if (!$okPadre) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'El nodo padre no pertenece a tu sede.']);
                    exit;
                }
            }
        }

        if ($id > 0) {
            if ($is_master) {
                $stmt = $con->prepare("UPDATE bot_respuestas SET id_sede = ?, id_padre = ?, tipo = ?, formato_respuesta = ?, disparador = ?, mensaje = ?, media_url = ?, latitud = ?, longitud = ?, espera_respuesta = ?, estado = ? WHERE id = ?");
                $stmt->bind_param("iisssssssisi", $id_sede, $id_padre, $tipo, $formato_respuesta, $disparador, $mensaje, $media_url, $latitud, $longitud, $espera_respuesta, $estado, $id);
            } else {
                $stmt = $con->prepare("UPDATE bot_respuestas SET id_sede = ?, id_padre = ?, tipo = ?, formato_respuesta = ?, disparador = ?, mensaje = ?, media_url = ?, latitud = ?, longitud = ?, espera_respuesta = ?, estado = ? WHERE id = ? AND id_sede = ?");
                $stmt->bind_param("iisssssssisii", $user_sede, $id_padre, $tipo, $formato_respuesta, $disparador, $mensaje, $media_url, $latitud, $longitud, $espera_respuesta, $estado, $id, $user_sede);
            }
            if ($stmt && $stmt->execute()) {
                if (!$is_master && $stmt->affected_rows === 0) {
                    // Verificar si la regla existía en otra sede
                    $stmtOwn = $con->prepare("SELECT id FROM bot_respuestas WHERE id = ? AND id_sede = ? LIMIT 1");
                    $stmtOwn->bind_param("ii", $id, $user_sede);
                    $stmtOwn->execute();
                    $stmtOwn->store_result();
                    $existsInSede = ($stmtOwn->num_rows > 0);
                    $stmtOwn->close();
                    if (!$existsInSede) {
                        http_response_code(403);
                        echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para modificar reglas de otra sede.']);
                        $stmt->close();
                        exit;
                    }
                }
                $stmt->close();
                echo json_encode(['status' => 'success', 'message' => 'Regla actualizada.']);
            } else {
                if ($stmt) $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Fallo al actualizar.']);
            }
        } else {
            $id_empresa = 1;
            $stmt = $con->prepare("INSERT INTO bot_respuestas (id_empresa, id_sede, id_padre, tipo, formato_respuesta, disparador, mensaje, media_url, latitud, longitud, espera_respuesta, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iiisssssssis", $id_empresa, $id_sede, $id_padre, $tipo, $formato_respuesta, $disparador, $mensaje, $media_url, $latitud, $longitud, $espera_respuesta, $estado);
            if ($stmt && $stmt->execute()) {
                $stmt->close();
                echo json_encode(['status' => 'success', 'message' => 'Regla creada con éxito.']);
            } else {
                if ($stmt) $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Fallo al crear.']);
            }
        }
        break;

    case 'delete_rule':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID inválido.']);
            break;
        }
        if ($is_master) {
            $stmt = $con->prepare("DELETE FROM bot_respuestas WHERE id = ?");
            $stmt->bind_param("i", $id);
        } else {
            $stmt = $con->prepare("DELETE FROM bot_respuestas WHERE id = ? AND id_sede = ?");
            $stmt->bind_param("ii", $id, $user_sede);
        }
        if ($stmt && $stmt->execute()) {
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted > 0) {
                echo json_encode(['status' => 'success', 'message' => 'Eliminada correctamente.']);
            } else {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Regla no encontrada o pertenece a otra sede.']);
            }
        } else {
            if ($stmt) $stmt->close();
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la regla.']);
        }
        break;

    case 'update_network':
        $nodes_json = $_POST['nodes'] ?? '[]';
        $nodes = json_decode($nodes_json, true);

        if (is_array($nodes)) {
            $success_count = 0;
            if ($is_master) {
                $stmt = $con->prepare("UPDATE bot_respuestas SET id_padre = ? WHERE id = ?");
            } else {
                $stmt = $con->prepare("UPDATE bot_respuestas SET id_padre = ? WHERE id = ? AND id_sede = ?");
                $stmtChkPadre = $con->prepare("SELECT id FROM bot_respuestas WHERE id = ? AND id_sede = ? LIMIT 1");
            }
            if ($stmt) {
                foreach ($nodes as $n) {
                    $db_id = intval($n['id'] ?? 0);
                    $id_padre = !empty($n['id_padre']) ? intval($n['id_padre']) : null;
                    if ($db_id <= 0) {
                        continue;
                    }
                    if (!$is_master && $id_padre !== null && $id_padre > 0 && isset($stmtChkPadre) && $stmtChkPadre) {
                        $stmtChkPadre->bind_param("ii", $id_padre, $user_sede);
                        $stmtChkPadre->execute();
                        $stmtChkPadre->store_result();
                        $validPadre = ($stmtChkPadre->num_rows > 0);
                        if (!$validPadre) {
                            continue;
                        }
                    }
                    if ($is_master) {
                        $stmt->bind_param("ii", $id_padre, $db_id);
                    } else {
                        $stmt->bind_param("iii", $id_padre, $db_id, $user_sede);
                    }
                    if ($stmt->execute()) {
                        $success_count++;
                    }
                }
                $stmt->close();
            }
            if (isset($stmtChkPadre) && $stmtChkPadre) {
                $stmtChkPadre->close();
            }
            echo json_encode(['status' => 'success', 'message' => "Árbol actualizado ($success_count nodos)."]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'JSON inválido.']);
        }
        break;

    case 'load_contactos':
        $id_sede = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
        $contactos = [];
        if ($id_sede > 0) {
            $stmt = $con->prepare("SELECT * FROM bot_vendedores WHERE id_sede = ? ORDER BY id ASC");
            if ($stmt) {
                $stmt->bind_param("i", $id_sede);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $contactos[] = $row;
                }
                $stmt->close();
            }
        } else {
            $res = $con->query("SELECT * FROM bot_vendedores WHERE (id_sede = 0 OR id_sede IS NULL) ORDER BY id ASC");
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $contactos[] = $row;
                }
            }
        }
        echo json_encode(['status' => 'success', 'data' => $contactos]);
        break;

    case 'save_contacto':
        $id = intval($_POST['id'] ?? 0);
        $id_sede = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
        $nombre = trim($_POST['nombre'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $estado = $_POST['estado'] ?? 'ACTIVO';

        if (empty($nombre) || empty($telefono) || (!$is_master && $id_sede <= 0)) {
            echo json_encode(['status' => 'error', 'message' => 'Faltan datos obligatorios.']);
            exit;
        }

        if ($id > 0) {
            if ($is_master) {
                $stmt = $con->prepare("UPDATE bot_vendedores SET id_sede = ?, nombre = ?, telefono = ?, estado = ? WHERE id = ?");
                $stmt->bind_param("isssi", $id_sede, $nombre, $telefono, $estado, $id);
            } else {
                $stmt = $con->prepare("UPDATE bot_vendedores SET id_sede = ?, nombre = ?, telefono = ?, estado = ? WHERE id = ? AND id_sede = ?");
                $stmt->bind_param("isssii", $user_sede, $nombre, $telefono, $estado, $id, $user_sede);
            }
            if ($stmt && $stmt->execute()) {
                if (!$is_master && $stmt->affected_rows === 0) {
                    $stmtOwn = $con->prepare("SELECT id FROM bot_vendedores WHERE id = ? AND id_sede = ? LIMIT 1");
                    $stmtOwn->bind_param("ii", $id, $user_sede);
                    $stmtOwn->execute();
                    $stmtOwn->store_result();
                    $existsInSede = ($stmtOwn->num_rows > 0);
                    $stmtOwn->close();
                    if (!$existsInSede) {
                        http_response_code(403);
                        echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para modificar contactos de otra sede.']);
                        $stmt->close();
                        exit;
                    }
                }
                $stmt->close();
                echo json_encode(['status' => 'success', 'message' => 'Contacto actualizado.']);
            } else {
                if ($stmt) $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Fallo al actualizar.']);
            }
        } else {
            $stmt = $con->prepare("INSERT INTO bot_vendedores (id_sede, nombre, telefono, estado) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $id_sede, $nombre, $telefono, $estado);
            if ($stmt && $stmt->execute()) {
                $stmt->close();
                echo json_encode(['status' => 'success', 'message' => 'Contacto creado.']);
            } else {
                if ($stmt) $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Fallo al crear contacto.']);
            }
        }
        break;

    case 'delete_contacto':
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID inválido.']);
            break;
        }
        if ($is_master) {
            $stmt = $con->prepare("DELETE FROM bot_vendedores WHERE id = ?");
            $stmt->bind_param("i", $id);
        } else {
            $stmt = $con->prepare("DELETE FROM bot_vendedores WHERE id = ? AND id_sede = ?");
            $stmt->bind_param("ii", $id, $user_sede);
        }
        if ($stmt && $stmt->execute()) {
            $deleted = $stmt->affected_rows;
            $stmt->close();
            if ($deleted > 0) {
                echo json_encode(['status' => 'success', 'message' => 'Eliminado correctamente.']);
            } else {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Contacto no encontrado o pertenece a otra sede.']);
            }
        } else {
            if ($stmt) $stmt->close();
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar contacto.']);
        }
        break;

    case 'toggle_bot':
        $id_sede = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
        $status = intval($_POST['status'] ?? 0);
        if ($id_sede > 0) {
            $stmt = $con->prepare("UPDATE sedes SET bot_activo = ? WHERE id = ?");
            $stmt->bind_param("ii", $status, $id_sede);
            if ($stmt->execute()) {
                $stmt->close();
                echo json_encode(['status' => 'success']);
            } else {
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar base de datos.']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Sede inválida.']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción inválida.']);
        break;
}
?>
