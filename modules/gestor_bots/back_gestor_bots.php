<?php
/**
 * Backend Controller - Gestor de Bots STARFI CRM
 * Blindado con RBAC, aislamiento por sede, 100% mysqli_prepare y auditoría formal canónica.
 */

@ini_set('display_errors', '0');
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('gestor_bots');
header('Content-Type: application/json; charset=utf-8');

$con = getDbConnection();
if (!$con) {
    starfi_log_exception('gestor_bots', 'Error de conexión con la base de datos');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error de conexión con la base de datos.']);
    exit;
}

$action = $_POST['action'] ?? '';

$agente = getAgenteInfo();
$agente_id = intval($agente['id'] ?? ($_SESSION['agente_id'] ?? 0));
$rol_raw = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
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

function starfi_bots_fetch_one($con, $sql, $types, ...$params) {
    $stmt = $con->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row;
}

try {
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
                $stmt = $con->prepare("SELECT * FROM bot_respuestas WHERE id_empresa = 1 ORDER BY tipo ASC, id ASC");
                if ($stmt) {
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $rules[] = $row;
                    }
                    $stmt->close();
                }
            }
            echo json_encode(['status' => 'success', 'data' => $rules]);
            break;

        case 'save_rule':
            $id = intval($_POST['id'] ?? 0);
            $id_sede = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
            $tipo = trim((string)($_POST['tipo'] ?? ''));
            $disparador = trim((string)($_POST['disparador'] ?? ''));
            $mensaje = trim((string)($_POST['mensaje'] ?? ''));
            $estado = (($_POST['estado'] ?? 'ACTIVO') === 'INACTIVO') ? 'INACTIVO' : 'ACTIVO';

            $id_padre = !empty($_POST['id_padre']) ? intval($_POST['id_padre']) : null;
            $formato_respuesta = trim((string)($_POST['formato_respuesta'] ?? 'TEXTO')) ?: 'TEXTO';
            $media_url = !empty($_POST['media_url']) ? trim((string)$_POST['media_url']) : null;
            $latitud = !empty($_POST['latitud']) ? trim((string)$_POST['latitud']) : null;
            $longitud = !empty($_POST['longitud']) ? trim((string)$_POST['longitud']) : null;
            $espera_respuesta = (isset($_POST['espera_respuesta']) && $_POST['espera_respuesta'] == '1') ? 1 : 0;

            if ($tipo === '' || $disparador === '' || $mensaje === '' || $id_sede <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Datos incompletos o falta seleccionar Sede.']);
                exit;
            }

            if ($id_padre !== null && $id_padre > 0 && !$is_master) {
                $okPadre = starfi_bots_fetch_one($con, "SELECT id FROM bot_respuestas WHERE id = ? AND id_sede = ? LIMIT 1", "ii", $id_padre, $user_sede);
                if (!$okPadre) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'El nodo padre no pertenece a tu sede.']);
                    exit;
                }
            }

            if ($id > 0) {
                $datos_anteriores = starfi_bots_fetch_one($con, "SELECT * FROM bot_respuestas WHERE id = ? LIMIT 1", "i", $id);
                if (!$datos_anteriores) {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'Regla no encontrada.']);
                    exit;
                }
                if (!$is_master && intval($datos_anteriores['id_sede'] ?? 0) !== $user_sede) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para modificar reglas de otra sede.']);
                    exit;
                }

                if ($is_master) {
                    $stmt = $con->prepare("UPDATE bot_respuestas SET id_sede = ?, id_padre = ?, tipo = ?, formato_respuesta = ?, disparador = ?, mensaje = ?, media_url = ?, latitud = ?, longitud = ?, espera_respuesta = ?, estado = ? WHERE id = ?");
                    $stmt->bind_param("iisssssssisi", $id_sede, $id_padre, $tipo, $formato_respuesta, $disparador, $mensaje, $media_url, $latitud, $longitud, $espera_respuesta, $estado, $id);
                } else {
                    $stmt = $con->prepare("UPDATE bot_respuestas SET id_sede = ?, id_padre = ?, tipo = ?, formato_respuesta = ?, disparador = ?, mensaje = ?, media_url = ?, latitud = ?, longitud = ?, espera_respuesta = ?, estado = ? WHERE id = ? AND id_sede = ?");
                    $stmt->bind_param("iisssssssisii", $user_sede, $id_padre, $tipo, $formato_respuesta, $disparador, $mensaje, $media_url, $latitud, $longitud, $espera_respuesta, $estado, $id, $user_sede);
                }

                if ($stmt && $stmt->execute()) {
                    $stmt->close();
                    $datos_nuevos = starfi_bots_fetch_one($con, "SELECT * FROM bot_respuestas WHERE id = ? LIMIT 1", "i", $id);
                    registrar_auditoria(
                        'gestor_bots',
                        'ACTUALIZAR_REGLA_BOT',
                        "Actualización de regla de bot #{$id} ({$disparador})",
                        $datos_anteriores,
                        $datos_nuevos,
                        $con,
                        $agente_id,
                        $id_sede
                    );
                    echo json_encode(['status' => 'success', 'message' => 'Regla actualizada.']);
                } else {
                    $errno = $con->errno;
                    if ($stmt) $stmt->close();
                    starfi_log_exception('gestor_bots', 'Error al actualizar bot_respuestas', ['id' => $id, 'errno' => $errno]);
                    echo json_encode(['status' => 'error', 'message' => 'Fallo al actualizar.']);
                }
            } else {
                $id_empresa = 1;
                $stmt = $con->prepare("INSERT INTO bot_respuestas (id_empresa, id_sede, id_padre, tipo, formato_respuesta, disparador, mensaje, media_url, latitud, longitud, espera_respuesta, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iiisssssssis", $id_empresa, $id_sede, $id_padre, $tipo, $formato_respuesta, $disparador, $mensaje, $media_url, $latitud, $longitud, $espera_respuesta, $estado);
                if ($stmt && $stmt->execute()) {
                    $new_id = (int)$stmt->insert_id;
                    $stmt->close();
                    $datos_nuevos = starfi_bots_fetch_one($con, "SELECT * FROM bot_respuestas WHERE id = ? LIMIT 1", "i", $new_id);
                    registrar_auditoria(
                        'gestor_bots',
                        'CREAR_REGLA_BOT',
                        "Creación de regla de bot #{$new_id} ({$disparador})",
                        null,
                        $datos_nuevos,
                        $con,
                        $agente_id,
                        $id_sede
                    );
                    echo json_encode(['status' => 'success', 'message' => 'Regla creada con éxito.']);
                } else {
                    $errno = $con->errno;
                    if ($stmt) $stmt->close();
                    starfi_log_exception('gestor_bots', 'Error al insertar bot_respuestas', ['id_sede' => $id_sede, 'errno' => $errno]);
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
            $datos_anteriores = starfi_bots_fetch_one($con, "SELECT * FROM bot_respuestas WHERE id = ? LIMIT 1", "i", $id);
            if (!$datos_anteriores || (!$is_master && intval($datos_anteriores['id_sede'] ?? 0) !== $user_sede)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Regla no encontrada o pertenece a otra sede.']);
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
                    registrar_auditoria(
                        'gestor_bots',
                        'ELIMINAR_REGLA_BOT',
                        "Eliminación de regla de bot #{$id} (" . ($datos_anteriores['disparador'] ?? '') . ")",
                        $datos_anteriores,
                        null,
                        $con,
                        $agente_id,
                        intval($datos_anteriores['id_sede'] ?? $user_sede)
                    );
                    echo json_encode(['status' => 'success', 'message' => 'Eliminada correctamente.']);
                } else {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Regla no encontrada o pertenece a otra sede.']);
                }
            } else {
                $errno = $con->errno;
                if ($stmt) $stmt->close();
                starfi_log_exception('gestor_bots', 'Error al eliminar bot_respuestas', ['id' => $id, 'errno' => $errno]);
                echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la regla.']);
            }
            break;

        case 'update_network':
            $nodes_json = $_POST['nodes'] ?? '[]';
            $nodes = json_decode($nodes_json, true);

            if (is_array($nodes)) {
                $success_count = 0;
                $nodos_antes = [];
                $nodos_despues = [];

                if ($is_master) {
                    $stmt = $con->prepare("UPDATE bot_respuestas SET id_padre = ? WHERE id = ?");
                } else {
                    $stmt = $con->prepare("UPDATE bot_respuestas SET id_padre = ? WHERE id = ? AND id_sede = ?");
                }
                if ($stmt) {
                    foreach ($nodes as $n) {
                        $db_id = intval($n['id'] ?? 0);
                        $id_padre = !empty($n['id_padre']) ? intval($n['id_padre']) : null;
                        if ($db_id <= 0) {
                            continue;
                        }

                        $prev_node = starfi_bots_fetch_one($con, "SELECT id, id_sede, id_padre, disparador FROM bot_respuestas WHERE id = ? LIMIT 1", "i", $db_id);
                        if (!$prev_node) {
                            continue;
                        }
                        if (!$is_master && intval($prev_node['id_sede'] ?? 0) !== $user_sede) {
                            continue;
                        }
                        if (!$is_master && $id_padre !== null && $id_padre > 0) {
                            $validPadre = starfi_bots_fetch_one($con, "SELECT id FROM bot_respuestas WHERE id = ? AND id_sede = ? LIMIT 1", "ii", $id_padre, $user_sede);
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
                            $nodos_antes[] = $prev_node;
                            $nodos_despues[] = [
                                'id' => $db_id,
                                'id_sede' => intval($prev_node['id_sede'] ?? 0),
                                'id_padre' => $id_padre,
                                'disparador' => $prev_node['disparador'] ?? ''
                            ];
                        }
                    }
                    $stmt->close();
                }

                if ($success_count > 0) {
                    registrar_auditoria(
                        'gestor_bots',
                        'ACTUALIZAR_ARBOL_BOT',
                        "Actualización de árbol conversacional ({$success_count} nodos)",
                        $nodos_antes,
                        $nodos_despues,
                        $con,
                        $agente_id,
                        $user_sede > 0 ? $user_sede : null
                    );
                }

                echo json_encode(['status' => 'success', 'message' => "Árbol actualizado ({$success_count} nodos)."]);
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
                $stmt = $con->prepare("SELECT * FROM bot_vendedores WHERE (id_sede = 0 OR id_sede IS NULL) ORDER BY id ASC");
                if ($stmt) {
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $contactos[] = $row;
                    }
                    $stmt->close();
                }
            }
            echo json_encode(['status' => 'success', 'data' => $contactos]);
            break;

        case 'save_contacto':
            $id = intval($_POST['id'] ?? 0);
            $id_sede = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $telefono = trim((string)($_POST['telefono'] ?? ''));
            $estado = (($_POST['estado'] ?? 'ACTIVO') === 'INACTIVO') ? 'INACTIVO' : 'ACTIVO';

            if ($nombre === '' || $telefono === '' || (!$is_master && $id_sede <= 0)) {
                echo json_encode(['status' => 'error', 'message' => 'Faltan datos obligatorios.']);
                exit;
            }

            if ($id > 0) {
                $datos_anteriores = starfi_bots_fetch_one($con, "SELECT * FROM bot_vendedores WHERE id = ? LIMIT 1", "i", $id);
                if (!$datos_anteriores) {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'Vendedor no encontrado.']);
                    exit;
                }
                if (!$is_master && intval($datos_anteriores['id_sede'] ?? 0) !== $user_sede) {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'No tienes autorización para modificar contactos de otra sede.']);
                    exit;
                }

                if ($is_master) {
                    $stmt = $con->prepare("UPDATE bot_vendedores SET id_sede = ?, nombre = ?, telefono = ?, estado = ? WHERE id = ?");
                    $stmt->bind_param("isssi", $id_sede, $nombre, $telefono, $estado, $id);
                } else {
                    $stmt = $con->prepare("UPDATE bot_vendedores SET id_sede = ?, nombre = ?, telefono = ?, estado = ? WHERE id = ? AND id_sede = ?");
                    $stmt->bind_param("isssii", $user_sede, $nombre, $telefono, $estado, $id, $user_sede);
                }
                if ($stmt && $stmt->execute()) {
                    $stmt->close();
                    $datos_nuevos = starfi_bots_fetch_one($con, "SELECT * FROM bot_vendedores WHERE id = ? LIMIT 1", "i", $id);
                    registrar_auditoria(
                        'gestor_bots',
                        'ACTUALIZAR_VENDEDOR_BOT',
                        "Actualización de vendedor del bot #{$id} ({$nombre})",
                        $datos_anteriores,
                        $datos_nuevos,
                        $con,
                        $agente_id,
                        $id_sede > 0 ? $id_sede : null
                    );
                    echo json_encode(['status' => 'success', 'message' => 'Contacto actualizado.']);
                } else {
                    $errno = $con->errno;
                    if ($stmt) $stmt->close();
                    starfi_log_exception('gestor_bots', 'Error al actualizar bot_vendedores', ['id' => $id, 'errno' => $errno]);
                    echo json_encode(['status' => 'error', 'message' => 'Fallo al actualizar.']);
                }
            } else {
                $stmt = $con->prepare("INSERT INTO bot_vendedores (id_sede, nombre, telefono, estado) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("isss", $id_sede, $nombre, $telefono, $estado);
                if ($stmt && $stmt->execute()) {
                    $new_id = (int)$stmt->insert_id;
                    $stmt->close();
                    $datos_nuevos = starfi_bots_fetch_one($con, "SELECT * FROM bot_vendedores WHERE id = ? LIMIT 1", "i", $new_id);
                    registrar_auditoria(
                        'gestor_bots',
                        'CREAR_VENDEDOR_BOT',
                        "Creación de vendedor del bot #{$new_id} ({$nombre})",
                        null,
                        $datos_nuevos,
                        $con,
                        $agente_id,
                        $id_sede > 0 ? $id_sede : null
                    );
                    echo json_encode(['status' => 'success', 'message' => 'Contacto creado.']);
                } else {
                    $errno = $con->errno;
                    if ($stmt) $stmt->close();
                    starfi_log_exception('gestor_bots', 'Error al insertar bot_vendedores', ['id_sede' => $id_sede, 'errno' => $errno]);
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
            $datos_anteriores = starfi_bots_fetch_one($con, "SELECT * FROM bot_vendedores WHERE id = ? LIMIT 1", "i", $id);
            if (!$datos_anteriores || (!$is_master && intval($datos_anteriores['id_sede'] ?? 0) !== $user_sede)) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Contacto no encontrado o pertenece a otra sede.']);
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
                    registrar_auditoria(
                        'gestor_bots',
                        'ELIMINAR_VENDEDOR_BOT',
                        "Eliminación de vendedor del bot #{$id} (" . ($datos_anteriores['nombre'] ?? '') . ")",
                        $datos_anteriores,
                        null,
                        $con,
                        $agente_id,
                        intval($datos_anteriores['id_sede'] ?? $user_sede) ?: null
                    );
                    echo json_encode(['status' => 'success', 'message' => 'Eliminado correctamente.']);
                } else {
                    http_response_code(403);
                    echo json_encode(['status' => 'error', 'message' => 'Contacto no encontrado o pertenece a otra sede.']);
                }
            } else {
                $errno = $con->errno;
                if ($stmt) $stmt->close();
                starfi_log_exception('gestor_bots', 'Error al eliminar bot_vendedores', ['id' => $id, 'errno' => $errno]);
                echo json_encode(['status' => 'error', 'message' => 'Error al eliminar contacto.']);
            }
            break;

        case 'toggle_bot':
            $id_sede = $is_master ? intval($_POST['id_sede'] ?? 0) : $user_sede;
            $status = intval($_POST['status'] ?? 0) ? 1 : 0;
            if ($id_sede > 0) {
                $datos_anteriores = starfi_bots_fetch_one($con, "SELECT id, nombre_sede, bot_activo FROM sedes WHERE id = ? LIMIT 1", "i", $id_sede);
                if (!$datos_anteriores) {
                    echo json_encode(['status' => 'error', 'message' => 'Sede inválida.']);
                    break;
                }
                $stmt = $con->prepare("UPDATE sedes SET bot_activo = ? WHERE id = ?");
                $stmt->bind_param("ii", $status, $id_sede);
                if ($stmt->execute()) {
                    $stmt->close();
                    $datos_nuevos = $datos_anteriores;
                    $datos_nuevos['bot_activo'] = $status;
                    registrar_auditoria(
                        'gestor_bots',
                        'TOGGLE_ESTADO_BOT',
                        "Cambio de estado global del bot en sede #{$id_sede} (" . ($datos_anteriores['nombre_sede'] ?? '') . ") a " . ($status ? 'ACTIVO' : 'INACTIVO'),
                        $datos_anteriores,
                        $datos_nuevos,
                        $con,
                        $agente_id,
                        $id_sede
                    );
                    echo json_encode(['status' => 'success']);
                } else {
                    $errno = $con->errno;
                    $stmt->close();
                    starfi_log_exception('gestor_bots', 'Error al cambiar bot_activo en sedes', ['id_sede' => $id_sede, 'errno' => $errno]);
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
} catch (Throwable $ex) {
    starfi_log_exception('gestor_bots', 'Excepción en controlador de gestor_bots', [
        'action' => $action,
        'code' => $ex->getCode(),
        'line' => $ex->getLine()
    ]);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error interno al procesar la solicitud.']);
}
