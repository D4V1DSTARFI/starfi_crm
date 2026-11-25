<?php
/**
 * Backend Controller Canónico - Perfil de Empresa STARFI CRM
 * Blindado con RBAC (requirePermission), aislamiento por sede/empresa, prepared statements,
 * validación estricta de cargas (finfo + lista blanca MIME + extensión derivada + <= 5 MB)
 * y auditoría formal canónica (registrar_auditoria + starfi_log_exception).
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('perfil_empresa');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' && !isset($_GET['action']) && !isset($_POST['action'])) {
    registrar_auditoria('perfil_empresa', 'ACCESO_METODO_NO_PERMITIDO', 'Petición sin acción a back_perfil_empresa', null, ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
}

require_once __DIR__ . '/../../config/database.php';

$action = $_REQUEST['action'] ?? '';

if ($action === 'download_expediente') {
    $_GET['tipo'] = 'expediente';
    $_GET['id'] = intval($_REQUEST['id'] ?? 0);
    require __DIR__ . '/../../ver_documento.php';
    exit();
}

$con = getDbConnection('core');
if (!$con) {
    starfi_log_exception('perfil_empresa', 'Error de conexión con la base de datos central');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión con la base de datos.']);
    exit();
}

$agente = getAgenteInfo();
$agente_id = intval($agente['id'] ?? 0);
$user_rol = strtoupper(trim((string)($agente['rol'] ?? 'SIN_ROL')));
$user_sede = intval($agente['id_sede'] ?? 0);
$user_empresa = intval($agente['id_empresa'] ?? 0);
$es_global = ($agente_id === 1 || $user_rol === 'MASTER' || $user_rol === 'MASTER CI');

function starfi_empresa_autorizada($con, $id_empresa, $es_global, $user_sede, $user_empresa) {
    $id_emp = intval($id_empresa);
    if ($id_emp <= 0) {
        return false;
    }
    if ($es_global) {
        return true;
    }
    $stmt = $con->prepare("SELECT id, id_sede FROM empresa_perfil WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("i", $id_emp);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return false;
    }
    if ($user_empresa > 0 && intval($row['id']) !== $user_empresa) {
        return false;
    }
    if ($user_sede > 0 && intval($row['id_sede']) !== $user_sede) {
        return false;
    }
    return ($user_empresa > 0 || $user_sede > 0);
}

function starfi_fetch_row_by_id($con, $sql, $types, ...$params) {
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

function starfi_validar_subida_segura($file, array $mime_map, $max_bytes = 5242880) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Error al recibir el archivo adjunto.'];
    }
    $tmp = $file['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'message' => 'Origen de carga no válido.'];
    }
    $size = intval($file['size'] ?? 0);
    if ($size <= 0 || $size > $max_bytes) {
        return ['ok' => false, 'message' => 'El archivo supera el tamaño máximo permitido de 5 MB.'];
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $real_mime = (string)$finfo->file($tmp);
    if (!isset($mime_map[$real_mime])) {
        return ['ok' => false, 'message' => 'Tipo de archivo no permitido por políticas de seguridad.'];
    }
    return [
        'ok' => true,
        'mime' => $real_mime,
        'ext' => $mime_map[$real_mime],
        'tmp' => $tmp
    ];
}

try {
    switch ($action) {

        case 'get_sedes':
            if ($es_global) {
                $stmt_s = $con->prepare("SELECT id, nombre_sede AS razon_social FROM sedes WHERE estado = 'ACTIVO' ORDER BY id ASC");
            } else {
                $stmt_s = $con->prepare("SELECT id, nombre_sede AS razon_social FROM sedes WHERE id = ? AND estado = 'ACTIVO' LIMIT 1");
                if ($stmt_s) {
                    $stmt_s->bind_param("i", $user_sede);
                }
            }
            $sedes = [];
            if ($stmt_s && $stmt_s->execute()) {
                $res = $stmt_s->get_result();
                while ($row = $res->fetch_assoc()) {
                    $sedes[] = $row;
                }
                $stmt_s->close();
            } elseif ($stmt_s) {
                $stmt_s->close();
            }
            if (empty($sedes) && $es_global) {
                $sedes[] = ['id' => 1, 'razon_social' => 'Sede Principal'];
            }
            echo json_encode(['success' => true, 'data' => $sedes]);
            break;

        case 'get_perfiles':
            $perfiles = [];
            if (!$es_global) {
                if ($user_empresa > 0) {
                    $stmt = $con->prepare("SELECT p.id, p.razon_social, p.letra, p.rif, p.email, p.telefono, p.condicion, p.logo_empresa, p.id_sede,
                                                  (SELECT nombre FROM empresa_firmantes WHERE id_empresa = p.id LIMIT 1) AS firmante_principal,
                                                  (SELECT fecha FROM empresa_registro WHERE id_empresa = p.id LIMIT 1) AS fecha_registro
                                           FROM empresa_perfil p
                                           WHERE p.id = ?
                                           ORDER BY p.id DESC");
                    $stmt->bind_param("i", $user_empresa);
                } else {
                    $stmt = $con->prepare("SELECT p.id, p.razon_social, p.letra, p.rif, p.email, p.telefono, p.condicion, p.logo_empresa, p.id_sede,
                                                  (SELECT nombre FROM empresa_firmantes WHERE id_empresa = p.id LIMIT 1) AS firmante_principal,
                                                  (SELECT fecha FROM empresa_registro WHERE id_empresa = p.id LIMIT 1) AS fecha_registro
                                           FROM empresa_perfil p
                                           WHERE p.id_sede = ?
                                           ORDER BY p.id DESC");
                    $stmt->bind_param("i", $user_sede);
                }
                if ($stmt) {
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $perfiles[] = $row;
                    }
                    $stmt->close();
                }
            } else {
                $id_sede = (int)($_REQUEST['id_sede'] ?? 0);
                if ($id_sede > 0) {
                    $stmt = $con->prepare("SELECT p.id, p.razon_social, p.letra, p.rif, p.email, p.telefono, p.condicion, p.logo_empresa, p.id_sede,
                                                  (SELECT nombre FROM empresa_firmantes WHERE id_empresa = p.id LIMIT 1) AS firmante_principal,
                                                  (SELECT fecha FROM empresa_registro WHERE id_empresa = p.id LIMIT 1) AS fecha_registro
                                           FROM empresa_perfil p
                                           WHERE p.id_sede = ?
                                           ORDER BY p.id DESC");
                    $stmt->bind_param("i", $id_sede);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        $perfiles[] = $row;
                    }
                    $stmt->close();
                }
                if (empty($perfiles)) {
                    $stmt_all = $con->prepare("SELECT p.id, p.razon_social, p.letra, p.rif, p.email, p.telefono, p.condicion, p.logo_empresa, p.id_sede,
                                                      (SELECT nombre FROM empresa_firmantes WHERE id_empresa = p.id LIMIT 1) AS firmante_principal,
                                                      (SELECT fecha FROM empresa_registro WHERE id_empresa = p.id LIMIT 1) AS fecha_registro
                                               FROM empresa_perfil p
                                               ORDER BY p.id DESC");
                    if ($stmt_all && $stmt_all->execute()) {
                        $res_all = $stmt_all->get_result();
                        while ($row = $res_all->fetch_assoc()) {
                            $perfiles[] = $row;
                        }
                        $stmt_all->close();
                    } elseif ($stmt_all) {
                        $stmt_all->close();
                    }
                }
            }

            echo json_encode(['success' => true, 'data' => $perfiles, 'user_rol' => $user_rol, 'user_empresa' => $user_empresa]);
            break;

        case 'get_detalle_empresa':
            $id_empresa = (int)($_REQUEST['id_empresa'] ?? 0);
            if (!starfi_empresa_autorizada($con, $id_empresa, $es_global, $user_sede, $user_empresa)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No tiene autorización para consultar esta empresa.']);
                exit();
            }

            $perfil = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_perfil WHERE id = ? LIMIT 1", "i", $id_empresa);
            if (!$perfil) {
                echo json_encode(['success' => false, 'message' => 'Perfil no encontrado.']);
                exit();
            }

            $stmt_f = $con->prepare("SELECT * FROM empresa_firmantes WHERE id_empresa = ? ORDER BY id ASC");
            $stmt_f->bind_param("i", $id_empresa);
            $stmt_f->execute();
            $rf = $stmt_f->get_result();
            $firmantes = [];
            while ($r = $rf->fetch_assoc()) { $firmantes[] = $r; }
            $stmt_f->close();

            $stmt_r = $con->prepare("SELECT * FROM empresa_registro WHERE id_empresa = ? ORDER BY id DESC");
            $stmt_r->bind_param("i", $id_empresa);
            $stmt_r->execute();
            $rr = $stmt_r->get_result();
            $registros = [];
            while ($r = $rr->fetch_assoc()) { $registros[] = $r; }
            $stmt_r->close();

            $stmt_e = $con->prepare("SELECT * FROM empresa_expediente WHERE id_empresa = ? ORDER BY id DESC");
            $stmt_e->bind_param("i", $id_empresa);
            $stmt_e->execute();
            $re = $stmt_e->get_result();
            $expedientes = [];
            while ($r = $re->fetch_assoc()) { $expedientes[] = $r; }
            $stmt_e->close();

            echo json_encode([
                'success' => true,
                'perfil' => $perfil,
                'firmantes' => $firmantes,
                'registros' => $registros,
                'expedientes' => $expedientes
            ]);
            break;

        case 'save_perfil':
            $id = (int)($_POST['id'] ?? 0);
            $id_sede = $es_global ? (int)($_POST['id_sede'] ?? 1) : ($user_sede > 0 ? $user_sede : 1);
            $razon_social = trim((string)($_POST['razon_social'] ?? ''));
            $letra = in_array($_POST['letra'] ?? 'J', ['J', 'V', 'G', 'E'], true) ? $_POST['letra'] : 'J';
            $rif = trim((string)($_POST['rif'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $telefono = trim((string)($_POST['telefono'] ?? ''));
            $direccion = trim((string)($_POST['direccion'] ?? ''));
            $web = trim((string)($_POST['web'] ?? ''));
            $instagram = trim((string)($_POST['instagram'] ?? ''));
            $condicion = (($_POST['condicion'] ?? '') === '[ESPECIAL]') ? '[ESPECIAL]' : '[ORDINARIO]';

            if ($razon_social === '' || $rif === '') {
                echo json_encode(['success' => false, 'message' => 'Razón Social y RIF son campos obligatorios.']);
                exit();
            }

            if ($id > 0 && !starfi_empresa_autorizada($con, $id, $es_global, $user_sede, $user_empresa)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado para modificar esta empresa.']);
                exit();
            }
            if ($id === 0 && !$es_global && $user_sede <= 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No tiene sede asignada para crear perfiles corporativos.']);
                exit();
            }

            $logo_filename = null;
            if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $allowed_logos = [
                    'image/png'  => 'png',
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                ];
                $val = starfi_validar_subida_segura($_FILES['logo'], $allowed_logos, 5 * 1024 * 1024);
                if (!$val['ok']) {
                    echo json_encode(['success' => false, 'message' => $val['message']]);
                    exit();
                }
                $upload_dir = __DIR__ . '/../../uploads/empresa/';
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0755, true);
                }
                $logo_filename = 'logo_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $val['ext'];
                if (!@move_uploaded_file($val['tmp'], $upload_dir . $logo_filename)) {
                    starfi_log_exception('perfil_empresa', 'Error al almacenar archivo de logo corporativo', ['id' => $id]);
                    echo json_encode(['success' => false, 'message' => 'No se pudo almacenar el logo en el servidor.']);
                    exit();
                }
            }

            if ($id > 0) {
                $datos_anteriores = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_perfil WHERE id = ? LIMIT 1", "i", $id);
                if (!$datos_anteriores) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Perfil de empresa no encontrado.']);
                    exit();
                }

                if ($logo_filename) {
                    $stmt = $con->prepare("UPDATE empresa_perfil SET razon_social=?, letra=?, rif=?, email=?, telefono=?, direccion=?, web=?, instagram=?, condicion=?, logo_empresa=? WHERE id=?");
                    $stmt->bind_param("ssssssssssi", $razon_social, $letra, $rif, $email, $telefono, $direccion, $web, $instagram, $condicion, $logo_filename, $id);
                } else {
                    $stmt = $con->prepare("UPDATE empresa_perfil SET razon_social=?, letra=?, rif=?, email=?, telefono=?, direccion=?, web=?, instagram=?, condicion=? WHERE id=?");
                    $stmt->bind_param("sssssssssi", $razon_social, $letra, $rif, $email, $telefono, $direccion, $web, $instagram, $condicion, $id);
                }
                if (!$stmt->execute()) {
                    $errno = $con->errno;
                    $stmt->close();
                    starfi_log_exception('perfil_empresa', 'Error al actualizar empresa_perfil', ['id' => $id, 'errno' => $errno]);
                    echo json_encode(['success' => false, 'message' => 'No se pudo actualizar el perfil corporativo.']);
                    exit();
                }
                $stmt->close();

                $datos_nuevos = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_perfil WHERE id = ? LIMIT 1", "i", $id);
                registrar_auditoria(
                    'perfil_empresa',
                    'ACTUALIZAR_PERFIL_EMPRESA',
                    "Actualización de perfil corporativo #{$id} ({$razon_social})",
                    $datos_anteriores,
                    $datos_nuevos,
                    $con,
                    $agente_id,
                    intval($datos_nuevos['id_sede'] ?? $id_sede)
                );

                echo json_encode(['success' => true, 'message' => 'Perfil de empresa actualizado con éxito.', 'id_empresa' => $id]);
            } else {
                $stmt = $con->prepare("INSERT INTO empresa_perfil (id_sede, razon_social, letra, rif, email, telefono, direccion, web, instagram, condicion, logo_empresa) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("issssssssss", $id_sede, $razon_social, $letra, $rif, $email, $telefono, $direccion, $web, $instagram, $condicion, $logo_filename);
                if ($stmt->execute()) {
                    $new_id = (int)$stmt->insert_id;
                    $stmt->close();

                    $datos_nuevos = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_perfil WHERE id = ? LIMIT 1", "i", $new_id);
                    registrar_auditoria(
                        'perfil_empresa',
                        'CREAR_PERFIL_EMPRESA',
                        "Registro de perfil corporativo #{$new_id} ({$razon_social})",
                        null,
                        $datos_nuevos,
                        $con,
                        $agente_id,
                        $id_sede
                    );

                    echo json_encode(['success' => true, 'message' => 'Perfil de empresa registrado exitosamente.', 'id_empresa' => $new_id]);
                } else {
                    $errno = $con->errno;
                    $stmt->close();
                    starfi_log_exception('perfil_empresa', 'Error al crear empresa_perfil', ['razon_social' => $razon_social, 'errno' => $errno]);
                    echo json_encode(['success' => false, 'message' => 'Error al registrar la empresa.']);
                }
            }
            break;

        case 'save_firmante':
            $id = (int)($_POST['id'] ?? 0);
            $id_empresa = (int)($_POST['id_empresa'] ?? 0);
            $id_sede = $es_global ? (int)($_POST['id_sede'] ?? 1) : ($user_sede > 0 ? $user_sede : 1);
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $cedula = trim((string)($_POST['cedula'] ?? ''));
            $telefono = trim((string)($_POST['telefono'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $cargo = trim((string)($_POST['cargo'] ?? ''));
            $direccion = trim((string)($_POST['direccion'] ?? ''));

            if ($id_empresa <= 0 || $nombre === '' || $cedula === '') {
                echo json_encode(['success' => false, 'message' => 'Nombre y Cédula son obligatorios para el firmante.']);
                exit();
            }
            if (!starfi_empresa_autorizada($con, $id_empresa, $es_global, $user_sede, $user_empresa)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
                exit();
            }

            if ($id > 0) {
                $datos_anteriores = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_firmantes WHERE id = ? AND id_empresa = ? LIMIT 1", "ii", $id, $id_empresa);
                if (!$datos_anteriores) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Firmante no encontrado.']);
                    exit();
                }

                $stmt = $con->prepare("UPDATE empresa_firmantes SET nombre=?, cedula=?, telefono=?, email=?, cargo=?, direccion=? WHERE id=? AND id_empresa=?");
                $stmt->bind_param("ssssssii", $nombre, $cedula, $telefono, $email, $cargo, $direccion, $id, $id_empresa);
                if (!$stmt->execute()) {
                    $errno = $con->errno;
                    $stmt->close();
                    starfi_log_exception('perfil_empresa', 'Error al actualizar empresa_firmantes', ['id' => $id, 'errno' => $errno]);
                    echo json_encode(['success' => false, 'message' => 'No se pudo actualizar el firmante.']);
                    exit();
                }
                $stmt->close();

                $datos_nuevos = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_firmantes WHERE id = ? AND id_empresa = ? LIMIT 1", "ii", $id, $id_empresa);
                registrar_auditoria(
                    'perfil_empresa',
                    'ACTUALIZAR_FIRMANTE',
                    "Actualización de firmante #{$id} ({$nombre}) en empresa #{$id_empresa}",
                    $datos_anteriores,
                    $datos_nuevos,
                    $con,
                    $agente_id,
                    $id_sede
                );

                echo json_encode(['success' => true, 'message' => 'Firmante actualizado con éxito.']);
            } else {
                $stmt = $con->prepare("INSERT INTO empresa_firmantes (id_empresa, id_sede, nombre, cedula, telefono, email, cargo, direccion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iissssss", $id_empresa, $id_sede, $nombre, $cedula, $telefono, $email, $cargo, $direccion);
                if (!$stmt->execute()) {
                    $errno = $con->errno;
                    $stmt->close();
                    starfi_log_exception('perfil_empresa', 'Error al insertar empresa_firmantes', ['id_empresa' => $id_empresa, 'errno' => $errno]);
                    echo json_encode(['success' => false, 'message' => 'No se pudo registrar el firmante.']);
                    exit();
                }
                $new_id = (int)$stmt->insert_id;
                $stmt->close();

                $datos_nuevos = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_firmantes WHERE id = ? LIMIT 1", "i", $new_id);
                registrar_auditoria(
                    'perfil_empresa',
                    'CREAR_FIRMANTE',
                    "Registro de firmante #{$new_id} ({$nombre}) en empresa #{$id_empresa}",
                    null,
                    $datos_nuevos,
                    $con,
                    $agente_id,
                    $id_sede
                );

                echo json_encode(['success' => true, 'message' => 'Firmante agregado con éxito.']);
            }
            break;

        case 'delete_firmante':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID inválido.']);
                break;
            }
            $row_chk = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_firmantes WHERE id = ? LIMIT 1", "i", $id);
            if (!$row_chk || !starfi_empresa_autorizada($con, (int)$row_chk['id_empresa'], $es_global, $user_sede, $user_empresa)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
                break;
            }
            $stmt_del = $con->prepare("DELETE FROM empresa_firmantes WHERE id = ?");
            $stmt_del->bind_param("i", $id);
            if (!$stmt_del->execute()) {
                $errno = $con->errno;
                $stmt_del->close();
                starfi_log_exception('perfil_empresa', 'Error al eliminar empresa_firmantes', ['id' => $id, 'errno' => $errno]);
                echo json_encode(['success' => false, 'message' => 'Error al eliminar el firmante.']);
                break;
            }
            $stmt_del->close();

            registrar_auditoria(
                'perfil_empresa',
                'ELIMINAR_FIRMANTE',
                "Eliminación de firmante #{$id} (" . ($row_chk['nombre'] ?? '') . ") de empresa #" . intval($row_chk['id_empresa']),
                $row_chk,
                null,
                $con,
                $agente_id,
                intval($row_chk['id_sede'] ?? $user_sede)
            );

            echo json_encode(['success' => true, 'message' => 'Firmante eliminado.']);
            break;

        case 'save_registro':
            $id = (int)($_POST['id'] ?? 0);
            $id_empresa = (int)($_POST['id_empresa'] ?? 0);
            $id_sede = $es_global ? (int)($_POST['id_sede'] ?? 1) : ($user_sede > 0 ? $user_sede : 1);
            $fecha = $_POST['fecha'] ?? date('Y-m-d');
            $n_registro = trim((string)($_POST['n_registro'] ?? ''));
            $n_tomo = trim((string)($_POST['n_tomo'] ?? ''));
            $descripcion = trim((string)($_POST['descripcion'] ?? ''));

            if ($id_empresa <= 0 || $n_registro === '' || $n_tomo === '') {
                echo json_encode(['success' => false, 'message' => 'N° de Registro y N° de Tomo son obligatorios.']);
                exit();
            }
            if (!starfi_empresa_autorizada($con, $id_empresa, $es_global, $user_sede, $user_empresa)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
                exit();
            }

            if ($id > 0) {
                $datos_anteriores = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_registro WHERE id = ? AND id_empresa = ? LIMIT 1", "ii", $id, $id_empresa);
                if (!$datos_anteriores) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Registro mercantil no encontrado.']);
                    exit();
                }

                $stmt = $con->prepare("UPDATE empresa_registro SET fecha=?, n_registro=?, n_tomo=?, descripcion=? WHERE id=? AND id_empresa=?");
                $stmt->bind_param("ssssii", $fecha, $n_registro, $n_tomo, $descripcion, $id, $id_empresa);
                if (!$stmt->execute()) {
                    $errno = $con->errno;
                    $stmt->close();
                    starfi_log_exception('perfil_empresa', 'Error al actualizar empresa_registro', ['id' => $id, 'errno' => $errno]);
                    echo json_encode(['success' => false, 'message' => 'No se pudo actualizar el registro mercantil.']);
                    exit();
                }
                $stmt->close();

                $datos_nuevos = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_registro WHERE id = ? AND id_empresa = ? LIMIT 1", "ii", $id, $id_empresa);
                registrar_auditoria(
                    'perfil_empresa',
                    'ACTUALIZAR_REGISTRO_MERCANTIL',
                    "Actualización de registro mercantil #{$id} ({$n_registro}) en empresa #{$id_empresa}",
                    $datos_anteriores,
                    $datos_nuevos,
                    $con,
                    $agente_id,
                    $id_sede
                );

                echo json_encode(['success' => true, 'message' => 'Registro mercantil actualizado.']);
            } else {
                $stmt = $con->prepare("INSERT INTO empresa_registro (id_empresa, id_sede, fecha, n_registro, n_tomo, descripcion) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("iissss", $id_empresa, $id_sede, $fecha, $n_registro, $n_tomo, $descripcion);
                if (!$stmt->execute()) {
                    $errno = $con->errno;
                    $stmt->close();
                    starfi_log_exception('perfil_empresa', 'Error al insertar empresa_registro', ['id_empresa' => $id_empresa, 'errno' => $errno]);
                    echo json_encode(['success' => false, 'message' => 'No se pudo guardar el registro mercantil.']);
                    exit();
                }
                $new_id = (int)$stmt->insert_id;
                $stmt->close();

                $datos_nuevos = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_registro WHERE id = ? LIMIT 1", "i", $new_id);
                registrar_auditoria(
                    'perfil_empresa',
                    'CREAR_REGISTRO_MERCANTIL',
                    "Creación de registro mercantil #{$new_id} ({$n_registro}) en empresa #{$id_empresa}",
                    null,
                    $datos_nuevos,
                    $con,
                    $agente_id,
                    $id_sede
                );

                echo json_encode(['success' => true, 'message' => 'Registro mercantil guardado.']);
            }
            break;

        case 'delete_registro':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID inválido.']);
                break;
            }
            $row_chk = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_registro WHERE id = ? LIMIT 1", "i", $id);
            if (!$row_chk || !starfi_empresa_autorizada($con, (int)$row_chk['id_empresa'], $es_global, $user_sede, $user_empresa)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
                break;
            }
            $stmt_del = $con->prepare("DELETE FROM empresa_registro WHERE id = ?");
            $stmt_del->bind_param("i", $id);
            if (!$stmt_del->execute()) {
                $errno = $con->errno;
                $stmt_del->close();
                starfi_log_exception('perfil_empresa', 'Error al eliminar empresa_registro', ['id' => $id, 'errno' => $errno]);
                echo json_encode(['success' => false, 'message' => 'Error al eliminar el registro mercantil.']);
                break;
            }
            $stmt_del->close();

            registrar_auditoria(
                'perfil_empresa',
                'ELIMINAR_REGISTRO_MERCANTIL',
                "Eliminación de registro mercantil #{$id} (" . ($row_chk['n_registro'] ?? '') . ") de empresa #" . intval($row_chk['id_empresa']),
                $row_chk,
                null,
                $con,
                $agente_id,
                intval($row_chk['id_sede'] ?? $user_sede)
            );

            echo json_encode(['success' => true, 'message' => 'Registro mercantil eliminado.']);
            break;

        case 'save_expediente':
            $id_empresa = (int)($_POST['id_empresa'] ?? 0);
            $id_sede = $es_global ? (int)($_POST['id_sede'] ?? 1) : ($user_sede > 0 ? $user_sede : 1);
            $fecha = $_POST['fecha'] ?? date('Y-m-d');
            $descripcion = trim((string)($_POST['descripcion'] ?? ''));

            if ($id_empresa <= 0 || $descripcion === '') {
                echo json_encode(['success' => false, 'message' => 'La descripción del documento es requerida.']);
                exit();
            }
            if (!starfi_empresa_autorizada($con, $id_empresa, $es_global, $user_sede, $user_empresa)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
                exit();
            }
            if (!isset($_FILES['archivo'])) {
                echo json_encode(['success' => false, 'message' => 'Debe adjuntar un archivo para el expediente.']);
                exit();
            }

            $allowed_docs = [
                'image/png'       => 'png',
                'image/jpeg'      => 'jpg',
                'image/webp'      => 'webp',
                'application/pdf' => 'pdf',
            ];
            $val = starfi_validar_subida_segura($_FILES['archivo'], $allowed_docs, 5 * 1024 * 1024);
            if (!$val['ok']) {
                echo json_encode(['success' => false, 'message' => $val['message']]);
                exit();
            }

            $upload_dir = __DIR__ . '/../../uploads/expedientes/';
            if (!is_dir($upload_dir)) {
                @mkdir($upload_dir, 0755, true);
            }
            $filename = 'doc_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $val['ext'];
            if (!@move_uploaded_file($val['tmp'], $upload_dir . $filename)) {
                starfi_log_exception('perfil_empresa', 'Error al almacenar documento de expediente', ['id_empresa' => $id_empresa]);
                echo json_encode(['success' => false, 'message' => 'No se pudo almacenar el documento en el servidor.']);
                exit();
            }
            $ruta_relativa = 'uploads/expedientes/' . $filename;

            $stmt = $con->prepare("INSERT INTO empresa_expediente (id_empresa, id_sede, fecha, descripcion, ruta) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisss", $id_empresa, $id_sede, $fecha, $descripcion, $ruta_relativa);
            if (!$stmt->execute()) {
                $errno = $con->errno;
                $stmt->close();
                starfi_log_exception('perfil_empresa', 'Error al insertar empresa_expediente', ['id_empresa' => $id_empresa, 'errno' => $errno]);
                echo json_encode(['success' => false, 'message' => 'Error al registrar el documento en el expediente.']);
                exit();
            }
            $new_id = (int)$stmt->insert_id;
            $stmt->close();

            $datos_nuevos = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_expediente WHERE id = ? LIMIT 1", "i", $new_id);
            registrar_auditoria(
                'perfil_empresa',
                'ADJUNTAR_EXPEDIENTE',
                "Adjunto de documento #{$new_id} ({$descripcion}) en expediente de empresa #{$id_empresa}",
                null,
                $datos_nuevos,
                $con,
                $agente_id,
                $id_sede
            );

            echo json_encode(['success' => true, 'message' => 'Documento adjuntado al expediente correctamente.']);
            break;

        case 'delete_expediente':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID inválido.']);
                break;
            }
            $r = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_expediente WHERE id = ? LIMIT 1", "i", $id);
            if (!$r || !starfi_empresa_autorizada($con, (int)$r['id_empresa'], $es_global, $user_sede, $user_empresa)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Acceso denegado.']);
                break;
            }

            if (!empty($r['ruta'])) {
                $cand = realpath(__DIR__ . '/../../' . ltrim((string)$r['ruta'], '/\\'));
                $baseUp = realpath(__DIR__ . '/../../uploads/expedientes');
                $baseLegacy = realpath(__DIR__ . '/../../assets/docs/expedientes');
                if ($cand && (($baseUp && strpos($cand, $baseUp) === 0) || ($baseLegacy && strpos($cand, $baseLegacy) === 0)) && is_file($cand)) {
                    @unlink($cand);
                }
            }
            $stmt_del = $con->prepare("DELETE FROM empresa_expediente WHERE id = ?");
            $stmt_del->bind_param("i", $id);
            if (!$stmt_del->execute()) {
                $errno = $con->errno;
                $stmt_del->close();
                starfi_log_exception('perfil_empresa', 'Error al eliminar empresa_expediente', ['id' => $id, 'errno' => $errno]);
                echo json_encode(['success' => false, 'message' => 'Error al eliminar el documento.']);
                break;
            }
            $stmt_del->close();

            registrar_auditoria(
                'perfil_empresa',
                'ELIMINAR_EXPEDIENTE',
                "Eliminación de documento #{$id} (" . ($r['descripcion'] ?? '') . ") del expediente de empresa #" . intval($r['id_empresa']),
                $r,
                null,
                $con,
                $agente_id,
                intval($r['id_sede'] ?? $user_sede)
            );

            echo json_encode(['success' => true, 'message' => 'Documento eliminado del expediente.']);
            break;

        case 'delete_perfil':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID de empresa no válido.']);
                break;
            }
            if (!$es_global) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Solo el rol MASTER puede eliminar perfiles de empresa completos.']);
                break;
            }

            $perfil_ant = starfi_fetch_row_by_id($con, "SELECT * FROM empresa_perfil WHERE id = ? LIMIT 1", "i", $id);
            if (!$perfil_ant) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Perfil de empresa no encontrado.']);
                break;
            }

            $firm_ant = [];
            $sf = $con->prepare("SELECT * FROM empresa_firmantes WHERE id_empresa = ?");
            $sf->bind_param("i", $id);
            $sf->execute();
            $rf = $sf->get_result();
            while ($rw = $rf->fetch_assoc()) { $firm_ant[] = $rw; }
            $sf->close();

            $reg_ant = [];
            $sr = $con->prepare("SELECT * FROM empresa_registro WHERE id_empresa = ?");
            $sr->bind_param("i", $id);
            $sr->execute();
            $rr = $sr->get_result();
            while ($rw = $rr->fetch_assoc()) { $reg_ant[] = $rw; }
            $sr->close();

            $exp_ant = [];
            $se = $con->prepare("SELECT * FROM empresa_expediente WHERE id_empresa = ?");
            $se->bind_param("i", $id);
            $se->execute();
            $re = $se->get_result();
            while ($rw = $re->fetch_assoc()) { $exp_ant[] = $rw; }
            $se->close();

            $stmt1 = $con->prepare("DELETE FROM empresa_firmantes WHERE id_empresa = ?");
            $stmt1->bind_param("i", $id);
            $stmt1->execute();
            $stmt1->close();

            $stmt2 = $con->prepare("DELETE FROM empresa_registro WHERE id_empresa = ?");
            $stmt2->bind_param("i", $id);
            $stmt2->execute();
            $stmt2->close();

            $stmt3 = $con->prepare("DELETE FROM empresa_expediente WHERE id_empresa = ?");
            $stmt3->bind_param("i", $id);
            $stmt3->execute();
            $stmt3->close();

            $stmt4 = $con->prepare("DELETE FROM empresa_perfil WHERE id = ?");
            $stmt4->bind_param("i", $id);
            $stmt4->execute();
            $stmt4->close();

            registrar_auditoria(
                'perfil_empresa',
                'ELIMINAR_PERFIL_EMPRESA',
                "Eliminación completa de perfil corporativo #{$id} (" . ($perfil_ant['razon_social'] ?? '') . ")",
                [
                    'perfil' => $perfil_ant,
                    'firmantes' => $firm_ant,
                    'registros' => $reg_ant,
                    'expedientes' => $exp_ant
                ],
                null,
                $con,
                $agente_id,
                intval($perfil_ant['id_sede'] ?? $user_sede)
            );

            echo json_encode(['success' => true, 'message' => 'Perfil de empresa eliminado.']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Acción no especificada.']);
            break;
    }
} catch (Throwable $ex) {
    starfi_log_exception('perfil_empresa', 'Excepción en controlador de perfil_empresa', [
        'action' => $action,
        'code' => $ex->getCode(),
        'line' => $ex->getLine()
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno al procesar la solicitud.']);
}

mysqli_close($con);
