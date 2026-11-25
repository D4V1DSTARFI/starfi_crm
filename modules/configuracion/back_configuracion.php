<?php
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/audit.php';
requireAuth();
requirePermission('configuracion');

$agente = getAgenteInfo();
$es_master = (intval($agente['id'] ?? 0) === 1 || strtoupper(trim((string)($agente['rol'] ?? ''))) === 'MASTER');
$user_sede = intval($agente['id_sede'] ?? 0);

$con = getDbConnection();
$action = $_POST['action'] ?? '';
$id_empresa = 1;

function cfg_deny_master_only() {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Operación reservada exclusivamente al rol MASTER.']);
    exit;
}

function cfg_deny_sede_scope($message = 'Acceso denegado: no posee autorización sobre la sede solicitada.') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function starfi_mask_secret($s) {
    $str = trim((string)$s);
    if ($str === '') {
        return '';
    }
    if (strlen($str) <= 4) {
        return '••••••••';
    }
    return '••••••••' . substr($str, -4);
}

function starfi_is_masked_or_empty_secret($s) {
    $str = trim((string)$s);
    if ($str === '') {
        return true;
    }
    if (strpos($str, '••••') !== false || strpos($str, '****') !== false || strpos($str, '••') !== false) {
        return true;
    }
    return false;
}

function cfg_sanitize_sede_row($row) {
    if (!is_array($row)) {
        return null;
    }
    $rawTok = trim((string)($row['api_token'] ?? ''));
    $hasTok = ($rawTok !== '');
    $masked = starfi_mask_secret($rawTok);
    $row['has_token'] = $hasTok;
    $row['api_token_masked'] = $masked;
    $row['api_token'] = $masked;
    return $row;
}

function cfg_sanitize_api_row($row) {
    if (!is_array($row)) {
        return null;
    }
    $rawTok = trim((string)($row['meta_token'] ?? ''));
    $hasTok = ($rawTok !== '');
    $masked = starfi_mask_secret($rawTok);
    $row['has_token'] = $hasTok;
    $row['meta_token_masked'] = $masked;
    $row['meta_token'] = $masked;
    return $row;
}

function ensureConfiguracionIaSchema($con) {
    if (!$con) {
        return;
    }
    if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
        return;
    }

    $dbName = 'starfi_crm';
    $stmtDb = $con->prepare("SELECT DATABASE() AS db_name");
    if ($stmtDb && $stmtDb->execute()) {
        $resDb = $stmtDb->get_result();
        if ($resDb && ($rwDb = $resDb->fetch_assoc()) && !empty($rwDb['db_name'])) {
            $dbName = (string)$rwDb['db_name'];
        }
        $stmtDb->close();
    }

    $flag = rtrim(sys_get_temp_dir(), '/\\') . '/starfi_cfg_ia_schema_v3_' . md5($dbName) . '.flag';
    if (@file_exists($flag)) {
        return;
    }

    $colsIa = [];
    $resColsIa = @$con->query("SHOW COLUMNS FROM `configuraciones_ia`");
    if ($resColsIa) {
        while ($c = $resColsIa->fetch_assoc()) {
            $colsIa[$c['Field']] = true;
        }
        if (!isset($colsIa['direccion_sede'])) {
            @$con->query("ALTER TABLE `configuraciones_ia` ADD COLUMN `direccion_sede` TEXT DEFAULT NULL");
        }
        if (!isset($colsIa['link_gps'])) {
            @$con->query("ALTER TABLE `configuraciones_ia` ADD COLUMN `link_gps` TEXT DEFAULT NULL");
        }
    }

    $colsSedes = [];
    $resColsSedes = @$con->query("SHOW COLUMNS FROM `sedes`");
    if ($resColsSedes) {
        while ($c = $resColsSedes->fetch_assoc()) {
            $colsSedes[$c['Field']] = true;
        }
        if (!isset($colsSedes['direccion'])) {
            @$con->query("ALTER TABLE `sedes` ADD COLUMN `direccion` TEXT DEFAULT NULL");
        }
        if (!isset($colsSedes['link_gps'])) {
            @$con->query("ALTER TABLE `sedes` ADD COLUMN `link_gps` TEXT DEFAULT NULL");
        }
    }

    @file_put_contents($flag, date('Y-m-d H:i:s'));
}

function cfg_resolve_scoped_sede($es_master, $user_sede, $requested_sede) {
    $req = intval($requested_sede);
    if (!$es_master) {
        if ($user_sede <= 0) {
            cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
        }
        if ($req > 0 && $req !== $user_sede) {
            cfg_deny_sede_scope();
        }
        return $user_sede;
    }
    return $req;
}

function cfg_fetch_one($con, $sql, $types = '', $params = []) {
    if (!$con) return null;
    $stmt = $con->prepare($sql);
    if (!$stmt) {
        starfi_log_exception('configuracion', 'Error preparando consulta SELECT', ['sql_error' => $con->error]);
        return null;
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        starfi_log_exception('configuracion', 'Error ejecutando consulta SELECT', ['sql_error' => $stmt->error]);
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

function cfg_fetch_all($con, $sql, $types = '', $params = []) {
    if (!$con) return [];
    $stmt = $con->prepare($sql);
    if (!$stmt) {
        starfi_log_exception('configuracion', 'Error preparando consulta listado', ['sql_error' => $con->error]);
        return [];
    }
    if ($types !== '' && !empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        starfi_log_exception('configuracion', 'Error ejecutando consulta listado', ['sql_error' => $stmt->error]);
        $stmt->close();
        return [];
    }
    $res = $stmt->get_result();
    $rows = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    $stmt->close();
    return $rows;
}

function cfg_sync_ventas_wsap($legacy_id, $local_api, $activo_val) {
    $con_ventas = getExternalDbConnection('ventas');
    if (!$con_ventas) {
        return;
    }
    $leg_id = intval($legacy_id);
    $act = intval($activo_val);

    if ($local_api) {
        $meta_token = (string)($local_api['meta_token'] ?? '');
        $meta_telefono_id = (string)($local_api['meta_telefono_id'] ?? ($local_api['meta_app_id'] ?? ''));
        $num_tel = (string)($local_api['numero_telefono'] ?? '');

        $exists = false;
        $stmt_chk = $con_ventas->prepare("SELECT id FROM config_api_wsap WHERE id_sede = ? LIMIT 1");
        if ($stmt_chk) {
            $stmt_chk->bind_param("i", $leg_id);
            if ($stmt_chk->execute()) {
                $r_chk = $stmt_chk->get_result();
                $exists = ($r_chk && $r_chk->num_rows > 0);
            }
            $stmt_chk->close();
        }

        if ($exists) {
            $stmt_v = $con_ventas->prepare("UPDATE config_api_wsap SET token = ?, instance_id = ?, phone_number = ?, activo = ? WHERE id_sede = ?");
            if ($stmt_v) {
                $stmt_v->bind_param("sssii", $meta_token, $meta_telefono_id, $num_tel, $act, $leg_id);
                $stmt_v->execute();
                $stmt_v->close();
            }
        } else {
            $default_api_url = 'https://api.starficloud.com/api_starfi_wsap/api_enviar_plantilla_meta.php';
            $stmt_v = $con_ventas->prepare("INSERT INTO config_api_wsap (id_sede, api_url, token, instance_id, phone_number, activo) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt_v) {
                $stmt_v->bind_param("issssi", $leg_id, $default_api_url, $meta_token, $meta_telefono_id, $num_tel, $act);
                $stmt_v->execute();
                $stmt_v->close();
            }
        }
    } else {
        $stmt_v = $con_ventas->prepare("UPDATE config_api_wsap SET activo = ? WHERE id_sede = ?");
        if ($stmt_v) {
            $stmt_v->bind_param("ii", $act, $leg_id);
            $stmt_v->execute();
            $stmt_v->close();
        }
    }
    mysqli_close($con_ventas);
}

function cfg_ensure_plantillas_whatsapp_table($con) {
    if (!$con) {
        return;
    }
    if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
        return;
    }
    $chk = @$con->query("SHOW TABLES LIKE 'plantillas_whatsapp'");
    if ($chk && $chk->num_rows > 0) {
        return;
    }
    @$con->query("CREATE TABLE IF NOT EXISTS `plantillas_whatsapp` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `id_sede` int(11) NOT NULL,
      `name` varchar(255) NOT NULL,
      `category` varchar(100) NOT NULL,
      `language` varchar(50) NOT NULL,
      `status` varchar(100) NOT NULL,
      `last_sync` datetime NOT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `sede_name` (`id_sede`,`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
}

switch ($action) {
    case 'generate_api_token':
        if (!$es_master) {
            cfg_deny_master_only();
        }
        $id_sede = intval($_POST['id_sede'] ?? 0);
        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Sede no válida']);
            exit;
        }

        try {
            $datos_anteriores = cfg_fetch_one($con, "SELECT id, nombre_sede, api_token FROM sedes WHERE id = ? AND id_empresa = ? LIMIT 1", "ii", [$id_sede, $id_empresa]);
            if (!$datos_anteriores) {
                echo json_encode(['status' => 'error', 'message' => 'Sede no encontrada']);
                exit;
            }
            $token = bin2hex(random_bytes(32));
            $stmt = $con->prepare("UPDATE sedes SET api_token = ? WHERE id = ? AND id_empresa = ?");
            if ($stmt) {
                $stmt->bind_param("sii", $token, $id_sede, $id_empresa);
                if ($stmt->execute()) {
                    $stmt->close();
                    registrar_auditoria(
                        'configuracion',
                        'GENERAR_API_TOKEN',
                        "Token API externo regenerado para sede #$id_sede",
                        $datos_anteriores,
                        ['id_sede' => $id_sede, 'api_token' => $token],
                        $con,
                        intval($agente['id'] ?? 0),
                        $id_sede
                    );
                    echo json_encode([
                        'status' => 'success',
                        'token' => $token,
                        'api_token_masked' => starfi_mask_secret($token),
                        'has_token' => true
                    ]);
                } else {
                    starfi_log_exception('configuracion', 'Error ejecutando generate_api_token', ['id_sede' => $id_sede, 'sql_error' => $stmt->error]);
                    $stmt->close();
                    echo json_encode(['status' => 'error', 'message' => 'Error al guardar el token']);
                }
            } else {
                starfi_log_exception('configuracion', 'Error preparando generate_api_token', ['sql_error' => $con->error]);
                echo json_encode(['status' => 'error', 'message' => 'Error en la base de datos']);
            }
        } catch (Exception $e) {
            starfi_log_exception('configuracion', 'Excepción en generate_api_token', ['error' => $e->getMessage()]);
            echo json_encode(['status' => 'error', 'message' => 'Error al generar el token']);
        }
        break;

    // --- GESTIÓN DE PLANTILLAS META ---
    case 'get_meta_templates':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        if ($id_sede <= 0) {
            echo json_encode(['status' => 'success', 'data' => []]);
            exit;
        }
        $chk_tbl = @$con->query("SHOW TABLES LIKE 'plantillas_whatsapp'");
        if ($chk_tbl && $chk_tbl->num_rows > 0) {
            $data = cfg_fetch_all($con, "SELECT name, category, language, status FROM plantillas_whatsapp WHERE id_sede = ? ORDER BY name ASC", "i", [$id_sede]);
            echo json_encode(['status' => 'success', 'data' => $data]);
        } else {
            echo json_encode(['status' => 'success', 'data' => []]);
        }
        break;

    case 'sync_meta_templates':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $row = cfg_fetch_one($con, "SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);
        if ($row) {
            if (empty($row['id_negocio'])) {
                echo json_encode(['status' => 'error', 'message' => 'WABA ID (ID de Negocio) no configurado en la API de WhatsApp de esta sede.']);
                exit;
            }

            cfg_ensure_plantillas_whatsapp_table($con);
            $prev_tpls = cfg_fetch_all($con, "SELECT name, status FROM plantillas_whatsapp WHERE id_sede = ?", "i", [$id_sede]);

            $waba_id = $row['id_negocio'];
            $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver_cfg}/{$waba_id}/message_templates";
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : ''), "Content-Type: application/json"]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($curl, CURLOPT_TIMEOUT, 8);
            $raw_exec = curl_exec($curl);
            $resp = json_decode((string)$raw_exec, true);
            curl_close($curl);

            if (isset($resp['data']) && is_array($resp['data'])) {
                $now = date('Y-m-d H:i:s');
                $stmt_del = $con->prepare("DELETE FROM plantillas_whatsapp WHERE id_sede = ?");
                if ($stmt_del) {
                    $stmt_del->bind_param("i", $id_sede);
                    $stmt_del->execute();
                    $stmt_del->close();
                }

                $saved_data = [];
                $stmt_ins = $con->prepare("INSERT INTO plantillas_whatsapp (id_sede, name, category, language, status, last_sync) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($resp['data'] as $t) {
                    $name = trim((string)($t['name'] ?? ''));
                    $category = trim((string)($t['category'] ?? ''));
                    $language = trim((string)($t['language'] ?? ''));
                    $status = trim((string)($t['status'] ?? ''));

                    if ($name !== '' && $stmt_ins) {
                        $stmt_ins->bind_param("isssss", $id_sede, $name, $category, $language, $status, $now);
                        $stmt_ins->execute();
                        $saved_data[] = [
                            'name' => $name,
                            'category' => $category,
                            'language' => $language,
                            'status' => $status
                        ];
                    }
                }
                if ($stmt_ins) {
                    $stmt_ins->close();
                }

                registrar_auditoria(
                    'configuracion',
                    'SYNC_PLANTILLAS_META',
                    "Plantillas Meta sincronizadas para sede #$id_sede (" . count($saved_data) . " plantillas)",
                    ['total_anterior' => count($prev_tpls)],
                    ['id_sede' => $id_sede, 'total_sincronizadas' => count($saved_data)],
                    $con,
                    null,
                    $id_sede
                );

                echo json_encode(['status' => 'success', 'data' => $saved_data]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al leer de Meta: ' . ($resp['error']['message'] ?? 'Desconocido')]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Línea de WhatsApp no configurada para esta sede.']);
        }
        break;

    case 'create_meta_template':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $category = trim((string)($_POST['category'] ?? ''));
        $language = trim((string)($_POST['language'] ?? 'es'));
        $body = trim((string)($_POST['body'] ?? ''));

        $row = cfg_fetch_one($con, "SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);
        if ($row) {
            if (empty($row['id_negocio'])) {
                echo json_encode(['status' => 'error', 'message' => 'WABA ID no configurado.']);
                exit;
            }
            $waba_id = $row['id_negocio'];
            $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver_cfg}/{$waba_id}/message_templates";
            $payload = [
                "name" => $name,
                "language" => $language,
                "category" => $category,
                "components" => [
                    [
                        "type" => "BODY",
                        "text" => $body
                    ]
                ]
            ];

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : ''), "Content-Type: application/json"]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($curl, CURLOPT_TIMEOUT, 8);
            $raw_exec = curl_exec($curl);
            $resp = json_decode((string)$raw_exec, true);
            curl_close($curl);

            if (isset($resp['id'])) {
                registrar_auditoria(
                    'configuracion',
                    'CREAR_PLANTILLA_META',
                    "Plantilla Meta '$name' enviada a revisión en sede #$id_sede",
                    null,
                    ['id_sede' => $id_sede, 'name' => $name, 'category' => $category, 'language' => $language, 'meta_id' => $resp['id']],
                    $con,
                    null,
                    $id_sede
                );
                echo json_encode(['status' => 'success', 'id' => $resp['id']]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al crear en Meta: ' . ($resp['error']['message'] ?? 'Desconocido')]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Línea de WhatsApp no configurada.']);
        }
        break;

    case 'create_csat_template':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $name = 'starfi_csat_survey';
        $category = 'UTILITY';
        $language = 'es';
        $body = 'Hola. ¿Cómo calificarías nuestra atención el día de hoy? Por favor, responde a este mensaje con un número del 1 (Muy malo) al 5 (Excelente). ¡Tu opinión es muy importante para nosotros!';

        $row = cfg_fetch_one($con, "SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);
        if ($row) {
            if (empty($row['id_negocio'])) {
                echo json_encode(['status' => 'error', 'message' => 'WABA ID no configurado.']);
                exit;
            }
            $waba_id = $row['id_negocio'];
            $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver_cfg}/{$waba_id}/message_templates";
            $payload = [
                "name" => $name,
                "language" => $language,
                "category" => $category,
                "components" => [
                    [
                        "type" => "BODY",
                        "text" => $body
                    ]
                ]
            ];

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : ''), "Content-Type: application/json"]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($curl, CURLOPT_TIMEOUT, 8);
            $raw_exec = curl_exec($curl);
            $resp = json_decode((string)$raw_exec, true);
            curl_close($curl);

            if (isset($resp['id'])) {
                registrar_auditoria(
                    'configuracion',
                    'CREAR_PLANTILLA_CSAT',
                    "Plantilla CSAT creada en Meta para sede #$id_sede",
                    null,
                    ['id_sede' => $id_sede, 'name' => $name, 'meta_id' => $resp['id']],
                    $con,
                    null,
                    $id_sede
                );
                echo json_encode(['status' => 'success', 'id' => $resp['id']]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al crear en Meta: ' . ($resp['error']['message'] ?? 'Desconocido')]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Línea de WhatsApp no configurada.']);
        }
        break;

    case 'create_internal_notification_template':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $name = 'starfi_notificacion_interna';
        $category = 'UTILITY';
        $language = 'es';
        $body = 'STARFI CRM: 💬 {{1}} ¡Ingresa para atenderla!';

        $row = cfg_fetch_one($con, "SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);
        if ($row) {
            if (empty($row['id_negocio'])) {
                echo json_encode(['status' => 'error', 'message' => 'WABA ID no configurado.']);
                exit;
            }
            $waba_id = $row['id_negocio'];
            $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver_cfg}/{$waba_id}/message_templates";
            $payload = [
                "name" => $name,
                "language" => $language,
                "category" => $category,
                "components" => [
                    [
                        "type" => "BODY",
                        "text" => $body,
                        "example" => [
                            "body_text" => [
                                ["Hola Operador, se te ha asignado una nueva conversacion."]
                            ]
                        ]
                    ]
                ]
            ];

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : ''), "Content-Type: application/json"]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($curl, CURLOPT_TIMEOUT, 8);
            $raw_exec = curl_exec($curl);
            $resp = json_decode((string)$raw_exec, true);
            curl_close($curl);

            if (isset($resp['id'])) {
                registrar_auditoria(
                    'configuracion',
                    'CREAR_PLANTILLA_NOTIF_INTERNA',
                    "Plantilla de notificación interna creada en Meta para sede #$id_sede",
                    null,
                    ['id_sede' => $id_sede, 'name' => $name, 'meta_id' => $resp['id']],
                    $con,
                    null,
                    $id_sede
                );
                echo json_encode(['status' => 'success', 'id' => $resp['id']]);
            } else {
                $errMsg = $resp['error']['error_user_msg'] ?? $resp['error']['message'] ?? 'Desconocido';
                echo json_encode(['status' => 'error', 'message' => 'Error al crear en Meta: ' . $errMsg]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Línea de WhatsApp no configurada.']);
        }
        break;

    case 'create_broadcast_template':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $name = 'starfi_difusion_directa';
        $category = 'MARKETING';
        $language = 'es';
        $body = "👋¡Hola {{1}}!\nHoy queremos {{2}}.\nRecuerda que en {{3}}.\n📲 *Responde a este mensaje para obtener más información*👇";
        $footer = "Servicio de mensajería inteligente de STARFI CRM";

        $row = cfg_fetch_one($con, "SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);
        if ($row) {
            if (empty($row['id_negocio'])) {
                echo json_encode(['status' => 'error', 'message' => 'WABA ID no configurado en la línea de esta sede.']);
                exit;
            }
            $waba_id = $row['id_negocio'];
            $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver_cfg}/{$waba_id}/message_templates";
            $payload = [
                "name" => $name,
                "language" => $language,
                "category" => $category,
                "components" => [
                    [
                        "type" => "BODY",
                        "text" => $body,
                        "example" => [
                            "body_text" => [
                                [
                                    "Carlos",
                                    "invitarte a la próxima inauguración de nuestra nueva tienda Carpintero Express con ofertas especiales para ti",
                                    "Carpintero Express estamos cada día más cerca de ti y somos tu parada obligatoria"
                                ]
                            ]
                        ]
                    ],
                    [
                        "type" => "FOOTER",
                        "text" => $footer
                    ]
                ]
            ];

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : ''), "Content-Type: application/json"]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($curl, CURLOPT_TIMEOUT, 8);
            $raw_exec = curl_exec($curl);
            $resp = json_decode((string)$raw_exec, true);
            curl_close($curl);

            if (isset($resp['id'])) {
                registrar_auditoria(
                    'configuracion',
                    'CREAR_PLANTILLA_DIFUSION',
                    "Plantilla de difusión directa creada en Meta para sede #$id_sede",
                    null,
                    ['id_sede' => $id_sede, 'name' => $name, 'meta_id' => $resp['id']],
                    $con,
                    null,
                    $id_sede
                );
                echo json_encode(['status' => 'success', 'id' => $resp['id']]);
            } else {
                $errMsg = $resp['error']['error_user_msg'] ?? $resp['error']['message'] ?? 'Desconocido';
                echo json_encode(['status' => 'error', 'message' => 'Error al crear en Meta: ' . $errMsg]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Línea de WhatsApp no configurada.']);
        }
        break;

    case 'delete_meta_template':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));

        $row = cfg_fetch_one($con, "SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);
        if ($row) {
            $waba_id = $row['id_negocio'];
            $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver_cfg}/{$waba_id}/message_templates?name=" . rawurlencode($name);

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '')]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($curl, CURLOPT_TIMEOUT, 8);
            $raw_exec = curl_exec($curl);
            $resp = json_decode((string)$raw_exec, true);
            curl_close($curl);

            if (isset($resp['success']) && $resp['success'] == true) {
                $chk_tbl = @$con->query("SHOW TABLES LIKE 'plantillas_whatsapp'");
                if ($chk_tbl && $chk_tbl->num_rows > 0) {
                    $stmt_del_t = $con->prepare("DELETE FROM plantillas_whatsapp WHERE id_sede = ? AND name = ?");
                    if ($stmt_del_t) {
                        $stmt_del_t->bind_param("is", $id_sede, $name);
                        $stmt_del_t->execute();
                        $stmt_del_t->close();
                    }
                }
                registrar_auditoria(
                    'configuracion',
                    'ELIMINAR_PLANTILLA_META',
                    "Plantilla Meta '$name' eliminada en sede #$id_sede",
                    ['id_sede' => $id_sede, 'name' => $name],
                    null,
                    $con,
                    null,
                    $id_sede
                );
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al eliminar en Meta: ' . ($resp['error']['message'] ?? 'Desconocido')]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Línea de WhatsApp no configurada.']);
        }
        break;

    // --- GESTIÓN DE SEDES ---
    case 'load_sedes':
    case 'list_sedes':
        if (!$es_master) {
            if ($user_sede <= 0) {
                cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
            }
            $rows = cfg_fetch_all(
                $con,
                "SELECT s.*, (SELECT COUNT(*) FROM lineas_whatsapp l WHERE l.id_sede = s.id) AS total_apis FROM sedes s WHERE s.id_empresa = ? AND s.id = ? ORDER BY s.id DESC",
                "ii",
                [$id_empresa, $user_sede]
            );
        } else {
            $rows = cfg_fetch_all(
                $con,
                "SELECT s.*, (SELECT COUNT(*) FROM lineas_whatsapp l WHERE l.id_sede = s.id) AS total_apis FROM sedes s WHERE s.id_empresa = ? ORDER BY s.id DESC",
                "i",
                [$id_empresa]
            );
        }
        $data = [];
        foreach ($rows as $row) {
            $row['tiene_api'] = (intval($row['total_apis']) > 0);
            $data[] = cfg_sanitize_sede_row($row);
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'save_sede':
        $id_sede_raw = trim((string)($_POST['id_sede'] ?? ''));
        $id_sede_num = ($id_sede_raw === '') ? 0 : intval($id_sede_raw);

        if (!$es_master) {
            if ($id_sede_num <= 0 || $user_sede <= 0 || $id_sede_num !== $user_sede) {
                cfg_deny_master_only();
            }
        }

        $razon_social = trim((string)($_POST['razon_social'] ?? ''));
        $rif = trim((string)($_POST['rif'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $direccion = trim((string)($_POST['direccion'] ?? ''));
        $ciudad = trim((string)($_POST['ciudad'] ?? ''));
        $estado_loc = trim((string)($_POST['estado_loc'] ?? ''));
        $codigo_postal = trim((string)($_POST['codigo_postal'] ?? ''));
        $estado_sede = trim((string)($_POST['estado_sede'] ?? 'ACTIVO'));
        $tipo_sede = trim((string)($_POST['tipo_sede'] ?? ''));
        $observaciones = trim((string)($_POST['observaciones'] ?? ''));

        if ($razon_social === '' || $rif === '') {
            echo json_encode(['status' => 'error', 'message' => 'Razón social y RIF son obligatorios.']);
            exit;
        }

        if ($id_sede_num <= 0) {
            $stmt = $con->prepare("INSERT INTO sedes (id_empresa, nombre_sede, rif, telefono, email, direccion, ciudad, estado_loc, codigo_postal, tipo_sede, observaciones, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                starfi_log_exception('configuracion', 'Error preparando INSERT sedes', ['sql_error' => $con->error]);
                echo json_encode(['status' => 'error', 'message' => 'Error al crear la sede.']);
                exit;
            }
            $stmt->bind_param("isssssssssss", $id_empresa, $razon_social, $rif, $telefono, $email, $direccion, $ciudad, $estado_loc, $codigo_postal, $tipo_sede, $observaciones, $estado_sede);

            if ($stmt->execute()) {
                $target_id = intval($stmt->insert_id);
                $stmt->close();

                $stmt_w = $con->prepare("UPDATE lineas_whatsapp SET estado = ? WHERE id_sede = ?");
                if ($stmt_w) {
                    $stmt_w->bind_param("si", $estado_sede, $target_id);
                    $stmt_w->execute();
                    $stmt_w->close();
                }

                $legacy_id = ($target_id === 23) ? 23 : ($target_id - 2);
                $legacy_status = ($estado_sede === 'ACTIVO') ? '[ACTIVO]' : '[INACTIVO]';
                $activo_val = ($estado_sede === 'ACTIVO') ? 1 : 0;

                $con_core = getExternalDbConnection('core');
                if ($con_core) {
                    $stmt_core = $con_core->prepare("UPDATE sede SET status = ? WHERE id = ?");
                    if ($stmt_core) {
                        $stmt_core->bind_param("si", $legacy_status, $legacy_id);
                        $stmt_core->execute();
                        $stmt_core->close();
                    }
                    mysqli_close($con_core);
                }

                $local_api = cfg_fetch_one($con, "SELECT numero_telefono, meta_app_id AS meta_telefono_id, meta_token FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$target_id]);
                cfg_sync_ventas_wsap($legacy_id, $local_api, $activo_val);

                registrar_auditoria(
                    'configuracion',
                    'CREAR_SEDE',
                    "Sede creada: $razon_social (#$target_id)",
                    null,
                    [
                        'id' => $target_id,
                        'nombre_sede' => $razon_social,
                        'rif' => $rif,
                        'telefono' => $telefono,
                        'email' => $email,
                        'ciudad' => $ciudad,
                        'estado_loc' => $estado_loc,
                        'tipo_sede' => $tipo_sede,
                        'estado' => $estado_sede
                    ],
                    $con,
                    null,
                    $target_id
                );

                echo json_encode(['status' => 'success', 'message' => 'Sede creada correctamente.']);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando INSERT sedes', ['sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al crear la sede.']);
            }
        } else {
            $id_sede = $id_sede_num;
            $datos_anteriores = cfg_fetch_one($con, "SELECT * FROM sedes WHERE id = ? AND id_empresa = ? LIMIT 1", "ii", [$id_sede, $id_empresa]);
            if (!$datos_anteriores) {
                echo json_encode(['status' => 'error', 'message' => 'Sede no encontrada.']);
                exit;
            }

            $stmt = $con->prepare("UPDATE sedes SET nombre_sede=?, rif=?, telefono=?, email=?, direccion=?, ciudad=?, estado_loc=?, codigo_postal=?, tipo_sede=?, observaciones=?, estado=? WHERE id=? AND id_empresa=?");
            if (!$stmt) {
                starfi_log_exception('configuracion', 'Error preparando UPDATE sedes', ['sql_error' => $con->error]);
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar la sede.']);
                exit;
            }
            $stmt->bind_param("sssssssssssii", $razon_social, $rif, $telefono, $email, $direccion, $ciudad, $estado_loc, $codigo_postal, $tipo_sede, $observaciones, $estado_sede, $id_sede, $id_empresa);

            if ($stmt->execute()) {
                $stmt->close();
                $target_id = $id_sede;

                $stmt_w = $con->prepare("UPDATE lineas_whatsapp SET estado = ? WHERE id_sede = ?");
                if ($stmt_w) {
                    $stmt_w->bind_param("si", $estado_sede, $target_id);
                    $stmt_w->execute();
                    $stmt_w->close();
                }

                $legacy_id = ($target_id === 23) ? 23 : ($target_id - 2);
                $legacy_status = ($estado_sede === 'ACTIVO') ? '[ACTIVO]' : '[INACTIVO]';
                $activo_val = ($estado_sede === 'ACTIVO') ? 1 : 0;

                $con_core = getExternalDbConnection('core');
                if ($con_core) {
                    $stmt_core = $con_core->prepare("UPDATE sede SET status = ? WHERE id = ?");
                    if ($stmt_core) {
                        $stmt_core->bind_param("si", $legacy_status, $legacy_id);
                        $stmt_core->execute();
                        $stmt_core->close();
                    }
                    mysqli_close($con_core);
                }

                $local_api = cfg_fetch_one($con, "SELECT numero_telefono, meta_app_id AS meta_telefono_id, meta_token FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$target_id]);
                cfg_sync_ventas_wsap($legacy_id, $local_api, $activo_val);

                registrar_auditoria(
                    'configuracion',
                    'ACTUALIZAR_SEDE',
                    "Sede actualizada: $razon_social (#$id_sede)",
                    $datos_anteriores,
                    [
                        'id' => $id_sede,
                        'nombre_sede' => $razon_social,
                        'rif' => $rif,
                        'telefono' => $telefono,
                        'email' => $email,
                        'direccion' => $direccion,
                        'ciudad' => $ciudad,
                        'estado_loc' => $estado_loc,
                        'codigo_postal' => $codigo_postal,
                        'tipo_sede' => $tipo_sede,
                        'estado' => $estado_sede
                    ],
                    $con,
                    null,
                    $id_sede
                );

                echo json_encode(['status' => 'success', 'message' => 'Sede actualizada correctamente.']);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando UPDATE sedes', ['id_sede' => $id_sede, 'sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar la sede.']);
            }
        }
        break;

    case 'get_sede':
        $id = intval($_POST['id'] ?? $_POST['id_sede'] ?? 0);
        if (!$es_master) {
            if ($user_sede <= 0 || $id !== $user_sede) {
                cfg_deny_sede_scope();
            }
        }
        $row = cfg_fetch_one($con, "SELECT * FROM sedes WHERE id = ? AND id_empresa = ? LIMIT 1", "ii", [$id, $id_empresa]);
        if ($row) {
            echo json_encode(['status' => 'success', 'data' => cfg_sanitize_sede_row($row)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Sede no encontrada.']);
        }
        break;

    case 'delete_sede':
        if (!$es_master) {
            cfg_deny_master_only();
        }
        $id = intval($_POST['id'] ?? $_POST['id_sede'] ?? 0);
        $datos_anteriores = cfg_fetch_one($con, "SELECT * FROM sedes WHERE id = ? AND id_empresa = ? LIMIT 1", "ii", [$id, $id_empresa]);

        $stmt_del_l = $con->prepare("DELETE FROM lineas_whatsapp WHERE id_sede = ?");
        if ($stmt_del_l) {
            $stmt_del_l->bind_param("i", $id);
            $stmt_del_l->execute();
            $stmt_del_l->close();
        }

        $stmt = $con->prepare("DELETE FROM sedes WHERE id = ? AND id_empresa = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $id, $id_empresa);
            if ($stmt->execute()) {
                $stmt->close();
                registrar_auditoria(
                    'configuracion',
                    'ELIMINAR_SEDE',
                    "Sede eliminada #$id",
                    $datos_anteriores,
                    null,
                    $con,
                    null,
                    $id
                );
                echo json_encode(['status' => 'success', 'message' => 'Sede eliminada.']);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando DELETE sedes', ['id' => $id, 'sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la sede.']);
            }
        } else {
            starfi_log_exception('configuracion', 'Error preparando DELETE sedes', ['sql_error' => $con->error]);
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la sede.']);
        }
        break;

    // --- GESTIÓN DE APIS (META GRAPH) ---
    case 'fetch_meta_apis':
        if (!$es_master) {
            cfg_deny_master_only();
        }
        $waba_id = trim($_POST['waba_id'] ?? '');
        $token = defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '';

        if (empty($waba_id) || empty($token)) {
            echo json_encode(['status' => 'error', 'message' => 'El WABA ID es obligatorio, y el Token Global debe estar configurado en .env.']);
            exit;
        }

        $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
        $url = "https://graph.facebook.com/{$api_ver_cfg}/{$waba_id}/phone_numbers";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res_data = json_decode((string)$response, true);

        if ($http_code == 200 && isset($res_data['data'])) {
            echo json_encode(['status' => 'success', 'data' => $res_data['data']]);
        } else {
            $error_msg = isset($res_data['error']['message']) ? $res_data['error']['message'] : 'Error desconocido al conectar con Meta.';
            starfi_log_exception('configuracion', 'Error en fetch_meta_apis', ['waba_id' => $waba_id, 'http_code' => $http_code]);
            echo json_encode(['status' => 'error', 'message' => $error_msg]);
        }
        break;

    case 'register_meta_phone':
        if (!$es_master) {
            cfg_deny_master_only();
        }
        $phone_id = trim($_POST['phone_id'] ?? '');
        $token = defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '';
        $pin = trim($_POST['pin'] ?? '');

        if (empty($phone_id) || empty($token) || empty($pin)) {
            echo json_encode(['status' => 'error', 'message' => 'El Phone ID y PIN son obligatorios, y el Token Global debe estar configurado.']);
            exit;
        }

        $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
        $url = "https://graph.facebook.com/{$api_ver_cfg}/{$phone_id}/register";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'messaging_product' => 'whatsapp',
            'pin' => $pin
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res_data = json_decode((string)$response, true);

        if ($http_code == 200 && isset($res_data['success']) && $res_data['success'] == true) {
            registrar_auditoria(
                'configuracion',
                'REGISTRAR_TELEFONO_META',
                "Alta de número telefónico en Meta Cloud API (Phone ID: $phone_id)",
                null,
                ['phone_id' => $phone_id],
                $con
            );
            echo json_encode(['status' => 'success', 'message' => 'El número de teléfono ha sido registrado y dado de alta exitosamente en Meta.']);
        } else {
            $error_msg = isset($res_data['error']['message']) ? $res_data['error']['message'] : 'Error desconocido al registrar en Meta.';
            starfi_log_exception('configuracion', 'Error en register_meta_phone', ['phone_id' => $phone_id, 'http_code' => $http_code]);
            echo json_encode(['status' => 'error', 'message' => $error_msg]);
        }
        break;

    // --- GESTIÓN DE APIS (LOCALES) ---
    case 'load_apis':
        if (!$es_master) {
            if ($user_sede <= 0) {
                cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
            }
            $rows = cfg_fetch_all(
                $con,
                "SELECT l.*, s.nombre_sede FROM lineas_whatsapp l LEFT JOIN sedes s ON l.id_sede = s.id WHERE s.id_empresa = ? AND l.id_sede = ?",
                "ii",
                [$id_empresa, $user_sede]
            );
        } else {
            $rows = cfg_fetch_all(
                $con,
                "SELECT l.*, s.nombre_sede FROM lineas_whatsapp l LEFT JOIN sedes s ON l.id_sede = s.id WHERE s.id_empresa = ?",
                "i",
                [$id_empresa]
            );
        }
        $data = [];
        foreach ($rows as $r) {
            $data[] = cfg_sanitize_api_row($r);
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'save_api':
        if (!$es_master) {
            cfg_deny_master_only();
        }
        $id_api_raw = trim((string)($_POST['id_api'] ?? ''));
        $id_api_num = ($id_api_raw === '') ? 0 : intval($id_api_raw);
        $id_sede = intval($_POST['id_sede'] ?? 0);
        $descripcion = trim((string)($_POST['descripcion'] ?? ''));
        $telefono = preg_replace('/[^0-9]/', '', $_POST['telefono'] ?? '');
        $telefono_meta = trim((string)($_POST['telefono_meta'] ?? ''));
        $submitted_meta_token = trim((string)($_POST['meta_token'] ?? $_POST['api_token_meta'] ?? ''));
        $id_negocio = trim((string)($_POST['id_negocio'] ?? ''));
        $estado = trim((string)($_POST['estado'] ?? 'ACTIVO'));
        $limite_solicitudes = intval(!empty($_POST['limite_solicitudes']) ? $_POST['limite_solicitudes'] : 1000);
        $observaciones = trim((string)($_POST['observaciones'] ?? ''));

        $datos_anteriores = null;
        if ($id_api_num > 0) {
            $datos_anteriores = cfg_fetch_one($con, "SELECT * FROM lineas_whatsapp WHERE id = ? LIMIT 1", "i", [$id_api_num]);
            if (!$datos_anteriores) {
                echo json_encode(['status' => 'error', 'message' => 'API no encontrada.']);
                exit;
            }
            if (starfi_is_masked_or_empty_secret($submitted_meta_token)) {
                $existing_tok = trim((string)($datos_anteriores['meta_token'] ?? ''));
                $token_meta = ($existing_tok !== '') ? $existing_tok : (defined('META_GLOBAL_TOKEN') ? trim((string)META_GLOBAL_TOKEN) : '');
            } else {
                $token_meta = $submitted_meta_token;
            }
        } else {
            if (starfi_is_masked_or_empty_secret($submitted_meta_token)) {
                $token_meta = defined('META_GLOBAL_TOKEN') ? trim((string)META_GLOBAL_TOKEN) : '';
            } else {
                $token_meta = $submitted_meta_token;
            }
        }

        if ($id_sede <= 0 || $descripcion === '' || $telefono === '' || $telefono_meta === '' || $token_meta === '') {
            echo json_encode(['status' => 'error', 'message' => 'Campos obligatorios incompletos o el Token de Meta no está configurado.']);
            exit;
        }

        if ($id_api_num <= 0) {
            $stmt = $con->prepare("INSERT INTO lineas_whatsapp (id_sede, descripcion, numero_telefono, meta_app_id, meta_token, id_negocio, estado_conexion, limite_solicitudes, observaciones, estado) VALUES (?, ?, ?, ?, ?, ?, 'CONECTADO', ?, ?, ?)");
            if (!$stmt) {
                starfi_log_exception('configuracion', 'Error preparando INSERT lineas_whatsapp', ['sql_error' => $con->error]);
                echo json_encode(['status' => 'error', 'message' => 'Error al registrar API.']);
                exit;
            }
            $stmt->bind_param("isssssiss", $id_sede, $descripcion, $telefono, $telefono_meta, $token_meta, $id_negocio, $limite_solicitudes, $observaciones, $estado);

            if ($stmt->execute()) {
                $new_api_id = intval($stmt->insert_id);
                $stmt->close();

                $legacy_id = ($id_sede === 23) ? 23 : ($id_sede - 2);
                $activo_val = ($estado === 'ACTIVO') ? 1 : 0;
                cfg_sync_ventas_wsap($legacy_id, [
                    'meta_token' => $token_meta,
                    'meta_telefono_id' => $telefono_meta,
                    'numero_telefono' => $telefono
                ], $activo_val);

                registrar_auditoria(
                    'configuracion',
                    'CREAR_API_WHATSAPP',
                    "API WhatsApp registrada (#$new_api_id) para sede #$id_sede",
                    null,
                    [
                        'id' => $new_api_id,
                        'id_sede' => $id_sede,
                        'descripcion' => $descripcion,
                        'numero_telefono' => $telefono,
                        'meta_app_id' => $telefono_meta,
                        'id_negocio' => $id_negocio,
                        'estado' => $estado
                    ],
                    $con,
                    null,
                    $id_sede
                );

                echo json_encode(['status' => 'success', 'message' => 'API WhatsApp registrada.']);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando INSERT lineas_whatsapp', ['sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al registrar API.']);
            }
        } else {
            $id_api = $id_api_num;
            $stmt = $con->prepare("UPDATE lineas_whatsapp SET id_sede=?, descripcion=?, numero_telefono=?, meta_app_id=?, meta_token=?, id_negocio=?, limite_solicitudes=?, observaciones=?, estado=? WHERE id=?");
            if (!$stmt) {
                starfi_log_exception('configuracion', 'Error preparando UPDATE lineas_whatsapp', ['sql_error' => $con->error]);
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar API.']);
                exit;
            }
            $stmt->bind_param("isssssissi", $id_sede, $descripcion, $telefono, $telefono_meta, $token_meta, $id_negocio, $limite_solicitudes, $observaciones, $estado, $id_api);

            if ($stmt->execute()) {
                $stmt->close();
                $legacy_id = ($id_sede === 23) ? 23 : ($id_sede - 2);
                $activo_val = ($estado === 'ACTIVO') ? 1 : 0;
                cfg_sync_ventas_wsap($legacy_id, [
                    'meta_token' => $token_meta,
                    'meta_telefono_id' => $telefono_meta,
                    'numero_telefono' => $telefono
                ], $activo_val);

                registrar_auditoria(
                    'configuracion',
                    'ACTUALIZAR_API_WHATSAPP',
                    "API WhatsApp #$id_api actualizada para sede #$id_sede",
                    $datos_anteriores,
                    [
                        'id' => $id_api,
                        'id_sede' => $id_sede,
                        'descripcion' => $descripcion,
                        'numero_telefono' => $telefono,
                        'meta_app_id' => $telefono_meta,
                        'id_negocio' => $id_negocio,
                        'limite_solicitudes' => $limite_solicitudes,
                        'estado' => $estado
                    ],
                    $con,
                    null,
                    $id_sede
                );

                echo json_encode(['status' => 'success', 'message' => 'API WhatsApp actualizada.']);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando UPDATE lineas_whatsapp', ['id_api' => $id_api, 'sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar API.']);
            }
        }
        break;

    case 'get_api_by_sede':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $row = cfg_fetch_one($con, "SELECT * FROM lineas_whatsapp WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);
        echo json_encode(['status' => 'success', 'data' => cfg_sanitize_api_row($row)]);
        break;

    case 'get_api_by_id':
        if (!$es_master && $user_sede <= 0) {
            cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
        }
        $id_api = intval($_POST['id_api'] ?? $_POST['id'] ?? 0);
        $row = cfg_fetch_one($con, "SELECT * FROM lineas_whatsapp WHERE id = ? LIMIT 1", "i", [$id_api]);
        if ($row) {
            if (!$es_master && intval($row['id_sede'] ?? 0) !== $user_sede) {
                cfg_deny_sede_scope();
            }
            echo json_encode(['status' => 'success', 'data' => cfg_sanitize_api_row($row)]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'API no encontrada.']);
        }
        break;

    case 'delete_api':
        if (!$es_master) {
            cfg_deny_master_only();
        }
        $id_api = intval($_POST['id'] ?? $_POST['id_api'] ?? 0);
        $datos_anteriores = cfg_fetch_one($con, "SELECT * FROM lineas_whatsapp WHERE id = ? LIMIT 1", "i", [$id_api]);
        $stmt = $con->prepare("DELETE FROM lineas_whatsapp WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id_api);
            if ($stmt->execute()) {
                $stmt->close();
                registrar_auditoria(
                    'configuracion',
                    'ELIMINAR_API_WHATSAPP',
                    "API WhatsApp #$id_api eliminada",
                    $datos_anteriores,
                    null,
                    $con,
                    null,
                    intval($datos_anteriores['id_sede'] ?? 0)
                );
                echo json_encode(['status' => 'success', 'message' => 'API eliminada exitosamente.']);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando DELETE lineas_whatsapp', ['id_api' => $id_api, 'sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la API.']);
            }
        } else {
            starfi_log_exception('configuracion', 'Error preparando DELETE lineas_whatsapp', ['sql_error' => $con->error]);
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la API.']);
        }
        break;

    case 'toggle_api_estado':
        if (!$es_master) {
            cfg_deny_master_only();
        }
        $id_api = intval($_POST['id_api'] ?? 0);
        $nuevo_estado = trim((string)($_POST['nuevo_estado'] ?? ''));

        if ($id_api <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'ID de API no válido.']);
            exit;
        }

        $datos_anteriores = cfg_fetch_one($con, "SELECT * FROM lineas_whatsapp WHERE id = ? LIMIT 1", "i", [$id_api]);
        $id_sede = intval($datos_anteriores['id_sede'] ?? 0);

        if ($nuevo_estado === '') {
            $nuevo_estado = (($datos_anteriores['estado'] ?? '') === 'ACTIVO') ? 'INACTIVO' : 'ACTIVO';
        } else {
            $nuevo_estado = (strtoupper($nuevo_estado) === 'ACTIVO') ? 'ACTIVO' : 'INACTIVO';
        }

        $stmt = $con->prepare("UPDATE lineas_whatsapp SET estado = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("si", $nuevo_estado, $id_api);
            if ($stmt->execute()) {
                $stmt->close();
                if ($id_sede > 0) {
                    $legacy_id = ($id_sede === 23) ? 23 : ($id_sede - 2);
                    $activo_val = ($nuevo_estado === 'ACTIVO') ? 1 : 0;
                    cfg_sync_ventas_wsap($legacy_id, null, $activo_val);
                }

                registrar_auditoria(
                    'configuracion',
                    'CAMBIAR_ESTADO_API',
                    "Estado de API WhatsApp #$id_api cambiado a $nuevo_estado",
                    ['id' => $id_api, 'estado' => $datos_anteriores['estado'] ?? null, 'id_sede' => $id_sede],
                    ['id' => $id_api, 'estado' => $nuevo_estado, 'id_sede' => $id_sede],
                    $con,
                    null,
                    $id_sede
                );

                $msg = ($nuevo_estado === 'ACTIVO') ? 'Sede / API de WhatsApp activada exitosamente.' : 'Sede / API de WhatsApp desactivada exitosamente (no enviará mensajes).';
                echo json_encode(['status' => 'success', 'message' => $msg, 'nuevo_estado' => $nuevo_estado]);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando toggle_api_estado', ['id_api' => $id_api, 'sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al cambiar el estado de la API.']);
            }
        } else {
            starfi_log_exception('configuracion', 'Error preparando toggle_api_estado', ['sql_error' => $con->error]);
            echo json_encode(['status' => 'error', 'message' => 'Error al cambiar el estado de la API.']);
        }
        break;

    case 'test_api':
        if (!$es_master && $user_sede <= 0) {
            cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
        }
        $id_api = intval($_POST['id_api'] ?? 0);
        $numero = trim((string)($_POST['numero'] ?? ''));
        $mensaje = trim((string)($_POST['mensaje'] ?? ''));

        if ($id_api <= 0 || $numero === '' || $mensaje === '') {
            echo json_encode(['status' => 'error', 'message' => 'Datos de prueba incompletos.']);
            exit;
        }

        $row = cfg_fetch_one($con, "SELECT id_sede, meta_app_id, meta_token FROM lineas_whatsapp WHERE id = ? LIMIT 1", "i", [$id_api]);
        if ($row) {
            if (!$es_master && intval($row['id_sede'] ?? 0) !== $user_sede) {
                cfg_deny_sede_scope();
            }
            $telefono_meta = $row['meta_app_id'];
            $token_meta = !empty($row['meta_token']) ? $row['meta_token'] : (defined('META_GLOBAL_TOKEN') ? META_GLOBAL_TOKEN : '');

            $api_ver_cfg = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $url = "https://graph.facebook.com/{$api_ver_cfg}/{$telefono_meta}/messages";
            $data = [
                'messaging_product' => 'whatsapp',
                'to' => str_replace(['+', ' '], '', $numero),
                'type' => 'template',
                'template' => [
                    'name' => 'hello_world',
                    'language' => ['code' => 'en_US']
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer {$token_meta}",
                "Content-Type: application/json"
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res_data = json_decode((string)$response, true);

            if ($http_code == 200 || $http_code == 201) {
                echo json_encode(['status' => 'success', 'message' => 'Plantilla de prueba enviada exitosamente.']);
            } else {
                $err = $res_data['error']['message'] ?? 'Error desconocido';
                echo json_encode(['status' => 'error', 'message' => 'Error de Meta: ' . $err]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'API no encontrada.']);
        }
        break;

    // --- LISTADOS AUXILIARES DE SEDES ---
    case 'load_users':
        if (!$es_master) {
            if ($user_sede <= 0) {
                cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
            }
            $data = cfg_fetch_all(
                $con,
                "SELECT u.id, COALESCE(up.nombre, u.usuario) AS nombre, r.nombre AS rol,
                        (SELECT nombre_sede FROM sedes WHERE id = u.id_sede LIMIT 1) AS sede,
                        u.limite_chats_simultaneos AS limite
                 FROM usuario u
                 LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
                 LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
                 WHERE u.id_empresa = ? AND u.id_sede = ? AND u.estado = 'ACTIVO'",
                "ii",
                [$id_empresa, $user_sede]
            );
        } else {
            $data = cfg_fetch_all(
                $con,
                "SELECT u.id, COALESCE(up.nombre, u.usuario) AS nombre, r.nombre AS rol,
                        (SELECT nombre_sede FROM sedes WHERE id = u.id_sede LIMIT 1) AS sede,
                        u.limite_chats_simultaneos AS limite
                 FROM usuario u
                 LEFT JOIN usuario_perfil up ON u.id = up.id_usuario
                 LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
                 WHERE u.id_empresa = ? AND u.estado = 'ACTIVO'",
                "i",
                [$id_empresa]
            );
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'get_sedes_list':
        if (!$es_master) {
            if ($user_sede <= 0) {
                cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
            }
            $data = cfg_fetch_all($con, "SELECT id, nombre_sede FROM sedes WHERE id_empresa = ? AND id = ? AND estado = 'ACTIVO'", "ii", [$id_empresa, $user_sede]);
        } else {
            $data = cfg_fetch_all($con, "SELECT id, nombre_sede FROM sedes WHERE id_empresa = ? AND estado = 'ACTIVO'", "i", [$id_empresa]);
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    // --- GESTIÓN DE RESPUESTAS RÁPIDAS ---
    case 'load_respuestas_rapidas':
        if (!$es_master) {
            if ($user_sede <= 0) {
                cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
            }
            $data = cfg_fetch_all(
                $con,
                "SELECT r.*, s.nombre_sede FROM respuestas_rapidas r LEFT JOIN sedes s ON r.id_sede = s.id WHERE s.id_empresa = ? AND r.id_sede = ? ORDER BY r.id DESC",
                "ii",
                [$id_empresa, $user_sede]
            );
        } else {
            $data = cfg_fetch_all(
                $con,
                "SELECT r.*, s.nombre_sede FROM respuestas_rapidas r LEFT JOIN sedes s ON r.id_sede = s.id WHERE s.id_empresa = ? ORDER BY r.id DESC",
                "i",
                [$id_empresa]
            );
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'save_respuesta_rapida':
        $id_respuesta_raw = trim((string)($_POST['id_respuesta'] ?? ''));
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $titulo = trim((string)($_POST['titulo'] ?? ''));
        $mensaje = trim((string)($_POST['mensaje'] ?? ''));

        if ($id_sede <= 0 || $titulo === '' || $mensaje === '') {
            echo json_encode(['status' => 'error', 'message' => 'Sede, título y mensaje son obligatorios.']);
            exit;
        }

        if ($id_respuesta_raw === '') {
            $stmt = $con->prepare("INSERT INTO respuestas_rapidas (id_sede, titulo, mensaje) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("iss", $id_sede, $titulo, $mensaje);
                if ($stmt->execute()) {
                    $new_resp_id = intval($stmt->insert_id);
                    $stmt->close();
                    registrar_auditoria(
                        'configuracion',
                        'CREAR_RESPUESTA_RAPIDA',
                        "Respuesta rápida '$titulo' registrada (#$new_resp_id)",
                        null,
                        ['id' => $new_resp_id, 'id_sede' => $id_sede, 'titulo' => $titulo, 'mensaje' => $mensaje],
                        $con,
                        null,
                        $id_sede
                    );
                    echo json_encode(['status' => 'success', 'message' => 'Respuesta rápida registrada.']);
                } else {
                    starfi_log_exception('configuracion', 'Error ejecutando INSERT respuestas_rapidas', ['sql_error' => $stmt->error]);
                    $stmt->close();
                    echo json_encode(['status' => 'error', 'message' => 'Error al registrar la respuesta.']);
                }
            } else {
                starfi_log_exception('configuracion', 'Error preparando INSERT respuestas_rapidas', ['sql_error' => $con->error]);
                echo json_encode(['status' => 'error', 'message' => 'Error al registrar la respuesta.']);
            }
        } else {
            $id_respuesta = intval($id_respuesta_raw);
            $datos_anteriores = cfg_fetch_one($con, "SELECT * FROM respuestas_rapidas WHERE id = ? LIMIT 1", "i", [$id_respuesta]);
            if (!$datos_anteriores) {
                echo json_encode(['status' => 'error', 'message' => 'Respuesta no encontrada.']);
                exit;
            }
            if (!$es_master && intval($datos_anteriores['id_sede'] ?? 0) !== $user_sede) {
                cfg_deny_sede_scope();
            }
            $stmt = $con->prepare("UPDATE respuestas_rapidas SET id_sede=?, titulo=?, mensaje=? WHERE id=?");
            if ($stmt) {
                $stmt->bind_param("issi", $id_sede, $titulo, $mensaje, $id_respuesta);
                if ($stmt->execute()) {
                    $stmt->close();
                    registrar_auditoria(
                        'configuracion',
                        'ACTUALIZAR_RESPUESTA_RAPIDA',
                        "Respuesta rápida #$id_respuesta actualizada",
                        $datos_anteriores,
                        ['id' => $id_respuesta, 'id_sede' => $id_sede, 'titulo' => $titulo, 'mensaje' => $mensaje],
                        $con,
                        null,
                        $id_sede
                    );
                    echo json_encode(['status' => 'success', 'message' => 'Respuesta rápida actualizada.']);
                } else {
                    starfi_log_exception('configuracion', 'Error ejecutando UPDATE respuestas_rapidas', ['id_respuesta' => $id_respuesta, 'sql_error' => $stmt->error]);
                    $stmt->close();
                    echo json_encode(['status' => 'error', 'message' => 'Error al actualizar la respuesta.']);
                }
            } else {
                starfi_log_exception('configuracion', 'Error preparando UPDATE respuestas_rapidas', ['sql_error' => $con->error]);
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar la respuesta.']);
            }
        }
        break;

    case 'get_respuesta_rapida':
        if (!$es_master && $user_sede <= 0) {
            cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
        }
        $id_respuesta = intval($_POST['id'] ?? 0);
        $row = cfg_fetch_one($con, "SELECT * FROM respuestas_rapidas WHERE id = ? LIMIT 1", "i", [$id_respuesta]);
        if ($row) {
            if (!$es_master && intval($row['id_sede'] ?? 0) !== $user_sede) {
                cfg_deny_sede_scope();
            }
            echo json_encode(['status' => 'success', 'data' => $row]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Respuesta no encontrada.']);
        }
        break;

    case 'delete_respuesta_rapida':
        if (!$es_master && $user_sede <= 0) {
            cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
        }
        $id_respuesta = intval($_POST['id'] ?? 0);
        $datos_anteriores = cfg_fetch_one($con, "SELECT * FROM respuestas_rapidas WHERE id = ? LIMIT 1", "i", [$id_respuesta]);
        if (!$datos_anteriores) {
            echo json_encode(['status' => 'error', 'message' => 'Respuesta no encontrada.']);
            exit;
        }
        if (!$es_master && intval($datos_anteriores['id_sede'] ?? 0) !== $user_sede) {
            cfg_deny_sede_scope();
        }
        $stmt = $con->prepare("DELETE FROM respuestas_rapidas WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id_respuesta);
            if ($stmt->execute()) {
                $stmt->close();
                registrar_auditoria(
                    'configuracion',
                    'ELIMINAR_RESPUESTA_RAPIDA',
                    "Respuesta rápida #$id_respuesta eliminada",
                    $datos_anteriores,
                    null,
                    $con,
                    null,
                    intval($datos_anteriores['id_sede'] ?? 0)
                );
                echo json_encode(['status' => 'success', 'message' => 'Respuesta rápida eliminada exitosamente.']);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando DELETE respuestas_rapidas', ['id_respuesta' => $id_respuesta, 'sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la respuesta.']);
            }
        } else {
            starfi_log_exception('configuracion', 'Error preparando DELETE respuestas_rapidas', ['sql_error' => $con->error]);
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la respuesta.']);
        }
        break;

    // --- CONFIGURACIÓN GEMA AI MULTI-SEDE ---
    case 'save_gema':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        if ($id_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Debe seleccionar una Sede válida.']);
            exit;
        }

        ensureConfiguracionIaSchema($con);

        $datos_anteriores = cfg_fetch_one($con, "SELECT * FROM configuraciones_ia WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);

        $agente_nombre = trim($_POST['agente_nombre'] ?? $_POST['nombre'] ?? 'Gema');
        $direccion_sede = trim($_POST['direccion_sede'] ?? $_POST['direccion'] ?? '');
        $link_gps = trim($_POST['link_gps'] ?? $_POST['gps'] ?? '');
        $agente_instrucciones = trim($_POST['agente_instrucciones'] ?? $_POST['prompt'] ?? '');
        $submitted_key = trim($_POST['gemini_api_key'] ?? $_POST['token'] ?? '');
        $modelo_ia = trim($_POST['modelo_ia'] ?? 'gemma-2-9b-it');
        $temperatura = floatval($_POST['temperatura'] ?? 0.4);

        if (starfi_is_masked_or_empty_secret($submitted_key)) {
            $existing_key = trim((string)($datos_anteriores['gemini_api_key'] ?? ''));
            if ($existing_key !== '') {
                $gemini_api_key = $existing_key;
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Debe ingresar una Gemini API Key válida para configurar el asistente de esta sede.']);
                exit;
            }
        } else {
            $gemini_api_key = $submitted_key;
        }

        $estado_raw = $_POST['estado_ia'] ?? $_POST['estado'] ?? 'ACTIVO';
        $estado_ia = ($estado_raw === 'ACTIVO' || $estado_raw === '1' || $estado_raw === 1) ? 'ACTIVO' : 'INACTIVO';

        $stmt = $con->prepare("
            INSERT INTO configuraciones_ia (id_empresa, id_sede, agente_nombre, direccion_sede, link_gps, agente_instrucciones, gemini_api_key, modelo_ia, temperatura, estado_ia)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                agente_nombre = VALUES(agente_nombre),
                direccion_sede = VALUES(direccion_sede),
                link_gps = VALUES(link_gps),
                agente_instrucciones = VALUES(agente_instrucciones),
                gemini_api_key = VALUES(gemini_api_key),
                modelo_ia = VALUES(modelo_ia),
                temperatura = VALUES(temperatura),
                estado_ia = VALUES(estado_ia)
        ");

        if ($stmt) {
            $stmt->bind_param("iissssssds", $id_empresa, $id_sede, $agente_nombre, $direccion_sede, $link_gps, $agente_instrucciones, $gemini_api_key, $modelo_ia, $temperatura, $estado_ia);
            if ($stmt->execute()) {
                $stmt->close();
                $bot_val = ($estado_ia === 'ACTIVO') ? 1 : 0;
                $stmt_s = $con->prepare("UPDATE sedes SET bot_activo = ?, direccion = ?, link_gps = ? WHERE id = ?");
                if ($stmt_s) {
                    $stmt_s->bind_param("issi", $bot_val, $direccion_sede, $link_gps, $id_sede);
                    $stmt_s->execute();
                    $stmt_s->close();
                }

                registrar_auditoria(
                    'configuracion',
                    'GUARDAR_CONFIG_IA',
                    "Configuración del Asistente IA actualizada para sede #$id_sede ($agente_nombre)",
                    $datos_anteriores,
                    [
                        'id_sede' => $id_sede,
                        'agente_nombre' => $agente_nombre,
                        'direccion_sede' => $direccion_sede,
                        'link_gps' => $link_gps,
                        'modelo_ia' => $modelo_ia,
                        'temperatura' => $temperatura,
                        'estado_ia' => $estado_ia,
                        'gemini_api_key' => $gemini_api_key
                    ],
                    $con,
                    null,
                    $id_sede
                );

                echo json_encode([
                    'status' => 'success',
                    'message' => 'Configuración de Asistente IA guardada correctamente para la sede.',
                    'has_api_key' => true,
                    'gemini_api_key_masked' => starfi_mask_secret($gemini_api_key)
                ]);
            } else {
                starfi_log_exception('configuracion', 'Error ejecutando save_gema', ['id_sede' => $id_sede, 'sql_error' => $stmt->error]);
                $stmt->close();
                echo json_encode(['status' => 'error', 'message' => 'Error al guardar la configuración en la base de datos.']);
            }
        } else {
            starfi_log_exception('configuracion', 'Error preparando save_gema', ['id_sede' => $id_sede, 'sql_error' => $con->error]);
            echo json_encode(['status' => 'error', 'message' => 'Error al preparar la consulta en la base de datos.']);
        }
        break;

    case 'load_gema':
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);

        if ($id_sede <= 0 && $es_master) {
            $row_f = cfg_fetch_one($con, "SELECT id FROM sedes WHERE id_empresa = ? AND estado = 'ACTIVO' LIMIT 1", "i", [$id_empresa]);
            if ($row_f) {
                $id_sede = intval($row_f['id']);
            }
        }

        if ($id_sede > 0) {
            ensureConfiguracionIaSchema($con);
            $row = cfg_fetch_one(
                $con,
                "SELECT c.*, s.direccion AS sede_direccion, s.link_gps AS sede_link_gps FROM configuraciones_ia c LEFT JOIN sedes s ON c.id_sede = s.id WHERE c.id_sede = ? LIMIT 1",
                "i",
                [$id_sede]
            );
            if ($row) {
                $rawKey = trim((string)($row['gemini_api_key'] ?? ''));
                $hasKey = ($rawKey !== '');
                $maskedKey = starfi_mask_secret($rawKey);

                echo json_encode([
                    'status' => 'success',
                    'id_sede' => $id_sede,
                    'data' => [
                        'id_sede' => $row['id_sede'],
                        'nombre' => $row['agente_nombre'],
                        'direccion_sede' => $row['direccion_sede'] ?? $row['sede_direccion'] ?? '',
                        'link_gps' => $row['link_gps'] ?? $row['sede_link_gps'] ?? '',
                        'prompt' => $row['agente_instrucciones'],
                        'token' => $maskedKey,
                        'gemini_api_key' => $maskedKey,
                        'gemini_api_key_masked' => $maskedKey,
                        'has_api_key' => $hasKey,
                        'has_token' => $hasKey,
                        'modelo_ia' => $row['modelo_ia'] ?? 'gemma-2-9b-it',
                        'temperatura' => floatval($row['temperatura'] ?? 0.4),
                        'estado' => ($row['estado_ia'] === 'ACTIVO') ? 1 : 0,
                        'estado_ia' => $row['estado_ia']
                    ]
                ]);
                exit;
            }
        }

        echo json_encode([
            'status' => 'success',
            'id_sede' => $id_sede,
            'data' => [
                'id_sede' => $id_sede,
                'nombre' => 'Gema',
                'prompt' => 'Eres Gema, la asistente virtual inteligente de STARFI CRM...',
                'token' => '',
                'gemini_api_key' => '',
                'gemini_api_key_masked' => '',
                'has_api_key' => false,
                'has_token' => false,
                'modelo_ia' => 'gemma-2-9b-it',
                'temperatura' => 0.4,
                'estado' => 1,
                'estado_ia' => 'ACTIVO'
            ]
        ]);
        break;

    case 'test_gema_connection':
        require_once __DIR__ . '/../../core/IaConnector.php';
        $id_sede = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? 0);
        $apiKey = trim((string)($_POST['api_key'] ?? $_POST['gemini_api_key'] ?? $_POST['token'] ?? ''));
        $modelo = trim((string)($_POST['modelo_ia'] ?? 'gemma-2-9b-it'));
        $nombre = trim((string)($_POST['nombre'] ?? $_POST['agente_nombre'] ?? 'Gema'));
        $temperatura = floatval($_POST['temperatura'] ?? 0.4);

        if (starfi_is_masked_or_empty_secret($apiKey)) {
            if ($id_sede > 0) {
                $stored = cfg_fetch_one($con, "SELECT gemini_api_key FROM configuraciones_ia WHERE id_sede = ? LIMIT 1", "i", [$id_sede]);
                $apiKey = trim((string)($stored['gemini_api_key'] ?? ''));
            } else {
                $apiKey = '';
            }
        }

        if ($apiKey === '') {
            echo json_encode(['status' => 'error', 'message' => 'Por favor introduce una Gemini API Key para realizar la prueba.']);
            exit;
        }

        $configTest = [
            'agente_nombre' => $nombre,
            'agente_instrucciones' => 'Responde muy brevemente confirmando que la conexión funciona.',
            'gemini_api_key' => $apiKey,
            'modelo_ia' => $modelo,
            'temperatura' => $temperatura
        ];

        $resTest = IaConnector::generarRespuestaConDetalles($configTest, [], "Hola, confirma la conexión de prueba en una frase corta.", "");

        if ($resTest['success']) {
            echo json_encode(['status' => 'success', 'message' => 'Conexión exitosa con la API de IA.', 'respuesta' => $resTest['text']]);
        } else {
            $errDetalle = $resTest['error'] ?? 'Error desconocido';
            echo json_encode(['status' => 'error', 'message' => 'Error de la API: ' . $errDetalle]);
        }
        break;

    case 'run_diagnostico':
        $diagnostico = [];

        if ($con && !$con->connect_error) {
            $diagnostico['database'] = ['status' => 'ok', 'message' => 'Conexión a la base de datos exitosa.'];

            $existing_tables = [];
            $res_all_tables = @$con->query("SHOW TABLES");
            if ($res_all_tables) {
                while ($rt = $res_all_tables->fetch_row()) {
                    if (!empty($rt[0])) {
                        $existing_tables[strtolower((string)$rt[0])] = true;
                    }
                }
            }

            $tablas = ['empresas', 'sedes', 'lineas_whatsapp', 'usuario', 'clientes_contactos', 'conversaciones', 'mensajes_y_eventos', 'notificacion_enviada'];
            $tablas_status = [];
            foreach ($tablas as $tabla) {
                $tablas_status[$tabla] = isset($existing_tables[strtolower($tabla)]);
            }
            $diagnostico['tables'] = ['status' => 'ok', 'data' => $tablas_status];

            if (!$es_master && $user_sede > 0) {
                $row_cnt = cfg_fetch_one($con, "SELECT COUNT(*) AS total FROM lineas_whatsapp WHERE estado = ? AND id_sede = ?", "si", ['ACTIVO', $user_sede]);
            } else {
                $row_cnt = cfg_fetch_one($con, "SELECT COUNT(*) AS total FROM lineas_whatsapp WHERE estado = ?", "s", ['ACTIVO']);
            }
            $total_lineas = $row_cnt ? intval($row_cnt['total']) : 0;
            $diagnostico['lineas_activas'] = [
                'status' => $total_lineas > 0 ? 'ok' : 'warning',
                'message' => $total_lineas > 0 ? "$total_lineas línea(s) de WhatsApp activas en el sistema." : "No hay líneas de WhatsApp activas en el sistema. Los envíos fallarán."
            ];
        } else {
            $diagnostico['database'] = ['status' => 'error', 'message' => 'Fallo en la conexión a la base de datos.'];
        }

        $archivos = [
            '../../config/database.php' => 'Configuración de base de datos',
            '../../api_notificaciones.php' => 'API de Notificaciones',
            '../../webhook.php' => 'Webhook de recepción de mensajes',
            '../../core/audit.php' => 'Motor de Auditoría Formal'
        ];
        $archivos_status = [];
        foreach ($archivos as $ruta => $nombre) {
            $ruta_real = __DIR__ . '/' . $ruta;
            $archivos_status[$nombre] = file_exists($ruta_real);
        }
        $diagnostico['files'] = ['status' => 'ok', 'data' => $archivos_status];

        echo json_encode(['status' => 'success', 'data' => $diagnostico]);
        break;

    case 'run_simulacion_entrante':
        if (!$es_master && $user_sede <= 0) {
            cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
        }
        define('WEBHOOK_NO_EXECUTE', true);
        require_once __DIR__ . '/../../webhook.php';
        if ($con && !$con->connect_error) {
            $numero_cliente = '584120000002';
            $nombre_cliente = 'Cliente Prueba Simulator';
            $mensaje_texto = 'Hola! Quiero probar la recepción de mensajes.';
            $phone_number_id = '123456789';

            save_mensaje($con, 'wamid.simulated_' . uniqid(), $numero_cliente, time(), $mensaje_texto, $nombre_cliente, $phone_number_id, '15551234567');
            registrar_auditoria(
                'configuracion',
                'SIMULACION_MENSAJE_ENTRANTE',
                'Simulación local de mensaje entrante ejecutada',
                null,
                ['numero_cliente' => $numero_cliente, 'nombre_cliente' => $nombre_cliente],
                $con
            );
            echo json_encode(['status' => 'success', 'message' => 'Simulación de mensaje entrante ejecutada localmente con éxito. Revisa la bandeja.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al conectar a la base de datos para simulación.']);
        }
        break;

    case 'run_envio_transaccional':
        if (!$es_master && $user_sede <= 0) {
            cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $raw_host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
        $host = ($raw_host !== '') ? $raw_host : '127.0.0.1';
        $base_uri = rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')))), '/');
        $url = $protocol . '://' . $host . $base_uri . '/api_notificaciones.php';
        $post_fields = [
            'telefono' => $_POST['telefono'] ?? '',
            'nombre_cliente' => $_POST['nombre_cliente'] ?? '',
            'monto_total' => $_POST['monto_total'] ?? '',
            'asesor_ventas' => $_POST['asesor_ventas'] ?? '',
            'correlativo' => $_POST['correlativo'] ?? '',
            'nombre_empresa' => $_POST['nombre_empresa'] ?? '',
            'telefono_asesor' => $_POST['telefono_asesor'] ?? ''
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_fields));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code == 200) {
            $res_data = json_decode((string)$response, true);
            if (isset($res_data['status_envio']) && $res_data['status_envio'] === '[EXITOSO]') {
                registrar_auditoria(
                    'configuracion',
                    'PRUEBA_ENVIO_TRANSACCIONAL',
                    'Notificación transaccional de prueba enviada',
                    null,
                    ['telefono' => $post_fields['telefono'], 'correlativo' => $post_fields['correlativo']],
                    $con
                );
                echo json_encode(['status' => 'success', 'message' => 'Notificación de prueba enviada con éxito.', 'details' => $res_data]);
            } else {
                $error_msg = isset($res_data['meta_response']['error']['message']) ? $res_data['meta_response']['error']['message'] : 'Error en la API de Meta.';
                echo json_encode(['status' => 'error', 'message' => 'No se pudo enviar la notificación: ' . $error_msg, 'details' => $res_data]);
            }
        } else {
            starfi_log_exception('configuracion', 'Error HTTP en run_envio_transaccional', ['http_code' => $http_code, 'url' => $url]);
            echo json_encode(['status' => 'error', 'message' => 'Error de conexión con la API de notificaciones (HTTP ' . $http_code . ').']);
        }
        break;

    // --- CONFIGURACIÓN DINÁMICA DE TIEMPOS SLA Y ENRUTAMIENTO ([COM-04]) ---
    case 'get_sla_settings':
        $ag_id = intval($agente['id'] ?? 0);
        $rol_u = strtoupper(trim((string)($agente['rol'] ?? '')));
        $is_admin = (!$es_master && in_array($rol_u, ['ADMINISTRADOR', 'ADMIN', 'GERENTE'], true));

        if (!$es_master && $user_sede <= 0) {
            cfg_deny_sede_scope('Su usuario no posee una sede asignada.');
        }

        $posted_sede = intval($_POST['id_sede'] ?? 0);
        if (!$es_master && $posted_sede > 0 && $posted_sede !== $user_sede) {
            cfg_deny_sede_scope();
        }
        $req_sede = $es_master ? max(0, $posted_sede) : $user_sede;
        $current_cfg = starfi_get_sla_config($con, $req_sede);

        $sedes_list = [];
        if (!$es_master) {
            $stmtS = $con->prepare("SELECT id, nombre_sede FROM sedes WHERE id = ? ORDER BY nombre_sede ASC");
            if ($stmtS) $stmtS->bind_param("i", $user_sede);
        } else {
            $stmtS = $con->prepare("SELECT id, nombre_sede FROM sedes WHERE estado = 'ACTIVO' ORDER BY nombre_sede ASC");
        }
        if ($stmtS && $stmtS->execute()) {
            $rS = $stmtS->get_result();
            while ($rs = $rS->fetch_assoc()) {
                $sedes_list[] = $rs;
            }
            $stmtS->close();
        }

        $rules = [];
        if (!$es_master) {
            $stmtR = $con->prepare("
                SELECT cs.id_sede, cs.verde_max_min, cs.amarillo_max_min, cs.round_robin_online_min, cs.alerta_sonora_activa,
                       IFNULL(s.nombre_sede, '🌎 Regla Global (Por defecto)') AS ambito_nombre
                FROM config_sla cs
                LEFT JOIN sedes s ON cs.id_sede = s.id
                WHERE cs.id_sede IN (0, ?)
                ORDER BY (cs.id_sede = 0) DESC, ambito_nombre ASC
            ");
            if ($stmtR) $stmtR->bind_param("i", $user_sede);
        } else {
            $stmtR = $con->prepare("
                SELECT cs.id_sede, cs.verde_max_min, cs.amarillo_max_min, cs.round_robin_online_min, cs.alerta_sonora_activa,
                       IFNULL(s.nombre_sede, '🌎 Regla Global (Por defecto)') AS ambito_nombre
                FROM config_sla cs
                LEFT JOIN sedes s ON cs.id_sede = s.id
                ORDER BY (cs.id_sede = 0) DESC, ambito_nombre ASC
            ");
        }
        if ($stmtR && $stmtR->execute()) {
            $rR = $stmtR->get_result();
            while ($rr = $rR->fetch_assoc()) {
                $rules[] = $rr;
            }
            $stmtR->close();
        }

        echo json_encode([
            'status'    => 'success',
            'is_master' => $es_master,
            'is_admin'  => $is_admin,
            'user_sede' => $user_sede,
            'config'    => $current_cfg,
            'sedes'     => $sedes_list,
            'rules'     => $rules,
        ]);
        break;

    case 'save_sla_settings':
        $ag_id = intval($agente['id'] ?? 0);
        $rol_u = strtoupper(trim((string)($agente['rol'] ?? '')));
        $is_admin = (!$es_master && in_array($rol_u, ['ADMINISTRADOR', 'ADMIN', 'GERENTE'], true));

        if (!$es_master && !$is_admin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'No tienes permisos para modificar los tiempos SLA.']);
            exit;
        }

        $posted_sede = isset($_POST['id_sede']) ? intval($_POST['id_sede']) : -1;
        if (!$es_master) {
            if ($user_sede <= 0) {
                cfg_deny_sede_scope('Tu usuario no posee una sede asignada.');
            }
            if ($posted_sede === 0) {
                cfg_deny_master_only();
            }
            if ($posted_sede > 0 && $posted_sede !== $user_sede) {
                cfg_deny_sede_scope();
            }
            $target_sede = $user_sede;
        } else {
            $target_sede = max(0, $posted_sede);
        }

        $datos_anteriores = starfi_get_sla_config($con, $target_sede);

        $v_min = intval($_POST['verde_max_min'] ?? 5);
        $a_min = intval($_POST['amarillo_max_min'] ?? 15);
        $rr_min = intval($_POST['round_robin_online_min'] ?? 5);
        $snd = intval($_POST['alerta_sonora_activa'] ?? 1);

        $saved = starfi_save_sla_config($con, $target_sede, $v_min, $a_min, $rr_min, $snd);
        if (!$saved['ok']) {
            echo json_encode(['status' => 'error', 'message' => $saved['message']]);
            exit;
        }

        registrar_auditoria(
            'configuracion',
            'GUARDAR_CONFIG_SLA',
            "Configuración SLA actualizada para ámbito sede #$target_sede",
            $datos_anteriores,
            $saved['config'],
            $con,
            $ag_id,
            $target_sede
        );

        echo json_encode([
            'status'  => 'success',
            'message' => $target_sede === 0
                ? 'Regla Global de tiempos SLA actualizada para todo el CRM.'
                : 'Tiempos SLA personalizados guardados para la sede seleccionada.',
            'config'  => $saved['config'],
        ]);
        break;

    case 'reset_sla_sede':
        $ag_id = intval($agente['id'] ?? 0);
        $rol_u = strtoupper(trim((string)($agente['rol'] ?? '')));
        $is_admin = (!$es_master && in_array($rol_u, ['ADMINISTRADOR', 'ADMIN', 'GERENTE'], true));

        if (!$es_master && !$is_admin) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Sin autorización.']);
            exit;
        }

        $posted_sede = intval($_POST['id_sede'] ?? 0);
        if (!$es_master) {
            if ($user_sede <= 0) {
                cfg_deny_sede_scope('Tu usuario no posee una sede asignada.');
            }
            if ($posted_sede > 0 && $posted_sede !== $user_sede) {
                cfg_deny_sede_scope();
            }
            $target_sede = $user_sede;
        } else {
            $target_sede = $posted_sede;
        }

        if ($target_sede <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'La Regla Global no puede eliminarse, solo editarse.']);
            exit;
        }
        $datos_anteriores = starfi_get_sla_config($con, $target_sede);
        $stmtDelSla = $con->prepare("DELETE FROM config_sla WHERE id_sede = ?");
        if ($stmtDelSla) {
            $stmtDelSla->bind_param("i", $target_sede);
            $stmtDelSla->execute();
            $stmtDelSla->close();
        }
        $new_sla_cfg = starfi_get_sla_config($con, $target_sede);
        registrar_auditoria(
            'configuracion',
            'RESTAURAR_SLA_SEDE',
            "Regla personalizada SLA eliminada para sede #$target_sede (heredando regla global)",
            $datos_anteriores,
            $new_sla_cfg,
            $con,
            $ag_id,
            $target_sede
        );
        echo json_encode([
            'status'  => 'success',
            'message' => 'La sede ahora hereda nuevamente la Regla Global de SLA.',
            'config'  => $new_sla_cfg,
        ]);
        break;

    // --- [IA-03] CONSTRUCTOR Y CLONADOR DE PLANTILLAS OFICIALES META (POST /{waba_id}/message_templates) ---
    case 'list_meta_templates_sede':
        require_once __DIR__ . '/../../core/services/MetaTemplateService.php';
        $id_sede_tpl = cfg_resolve_scoped_sede($es_master, $user_sede, $_POST['id_sede'] ?? ($_GET['id_sede'] ?? 0));
        if ($id_sede_tpl <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Seleccione una sede válida.']);
            exit;
        }
        $fetched = MetaTemplateService::fetchTemplatesForSede($con, $id_sede_tpl);
        echo json_encode([
            'status' => $fetched['ok'] ? 'success' : 'error',
            'id_sede' => $id_sede_tpl,
            'source' => $fetched['source'] ?? 'LOCAL_CACHE',
            'waba_id' => $fetched['waba_id'] ?? '',
            'templates' => $fetched['templates'] ?? [],
            'warning' => $fetched['meta_error'] ?? null
        ]);
        break;

    case 'create_official_meta_template':
    case 'clone_meta_template_sedes':
        require_once __DIR__ . '/../../core/services/MetaTemplateService.php';
        $ag_id_tpl = intval($agente['id'] ?? 1);
        $rawTargets = $_POST['target_sedes'] ?? [];
        if (is_string($rawTargets)) {
            $decT = json_decode($rawTargets, true);
            $rawTargets = is_array($decT) ? $decT : array_filter(array_map('intval', explode(',', $rawTargets)));
        }
        $targetSedes = is_array($rawTargets) ? array_values(array_unique(array_filter(array_map('intval', $rawTargets)))) : [];
        if (empty($targetSedes) && !empty($_POST['id_sede'])) {
            $targetSedes = [intval($_POST['id_sede'])];
        }
        if (!$es_master) {
            if ($user_sede <= 0) {
                cfg_deny_sede_scope('Tu usuario no posee una sede asignada.');
            }
            foreach ($targetSedes as $ts) {
                if ($ts !== $user_sede) {
                    cfg_deny_sede_scope('Solo usuarios Master pueden publicar o clonar plantillas hacia otras sedes.');
                }
            }
            $targetSedes = [$user_sede];
        }
        if (empty($targetSedes)) {
            echo json_encode(['status' => 'error', 'message' => 'Selecciona al menos una sede destino.']);
            exit;
        }

        $specTpl = [
            'name'            => $_POST['name'] ?? ($_POST['template_name'] ?? ''),
            'category'        => $_POST['category'] ?? 'MARKETING',
            'language'        => $_POST['language'] ?? 'es',
            'header_format'   => $_POST['header_format'] ?? 'NONE',
            'header_text'     => $_POST['header_text'] ?? '',
            'header_example'  => $_POST['header_example'] ?? '',
            'body'            => $_POST['body'] ?? '',
            'body_examples'   => isset($_POST['body_examples']) && is_string($_POST['body_examples']) ? (json_decode($_POST['body_examples'], true) ?: []) : ($_POST['body_examples'] ?? []),
            'footer'          => $_POST['footer'] ?? '',
            'buttons'         => isset($_POST['buttons']) && is_string($_POST['buttons']) ? (json_decode($_POST['buttons'], true) ?: []) : ($_POST['buttons'] ?? []),
        ];

        $pubRes = MetaTemplateService::publishTemplateToSedes($con, $targetSedes, $specTpl, $ag_id_tpl, $action === 'clone_meta_template_sedes' ? 'CLONAR' : 'CREAR');
        if (!$pubRes['ok']) {
            echo json_encode([
                'status' => 'error',
                'message' => $pubRes['message'] ?? 'No se pudo publicar la plantilla en Meta.',
                'results' => $pubRes['results'] ?? []
            ]);
            exit;
        }
        echo json_encode([
            'status' => 'success',
            'message' => "Plantilla '{$pubRes['template_name']}' procesada en {$pubRes['success_count']} sede(s).",
            'template_name' => $pubRes['template_name'],
            'success_count' => $pubRes['success_count'],
            'fail_count' => $pubRes['fail_count'],
            'results' => $pubRes['results']
        ]);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
        break;
}
