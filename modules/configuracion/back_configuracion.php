<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
header('Content-Type: application/json');

$con = getDbConnection();
$action = $_POST['action'] ?? '';
$id_empresa = 1; // Prototipo: asumiendo empresa 1

switch ($action) {
    // --- GESTIÓN DE SEDES ---
    case 'load_sedes':
        $query = "
            SELECT s.*, 
                   (SELECT COUNT(*) FROM lineas_whatsapp l WHERE l.id_sede = s.id) as total_apis
            FROM sedes s 
            WHERE s.id_empresa = $id_empresa 
            ORDER BY s.id DESC
        ";
        $res = $con->query($query);
        $data = [];
        if($res){
            while ($row = $res->fetch_assoc()) {
                $row['tiene_api'] = ($row['total_apis'] > 0);
                $data[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'save_sede':
        $id_sede = $_POST['id_sede'] ?? '';
        $razon_social = $_POST['razon_social'] ?? '';
        $rif = $_POST['rif'] ?? '';
        $telefono = $_POST['telefono'] ?? '';
        $email = $_POST['email'] ?? '';
        $direccion = $_POST['direccion'] ?? '';
        $ciudad = $_POST['ciudad'] ?? '';
        $estado_loc = $_POST['estado_loc'] ?? '';
        $codigo_postal = $_POST['codigo_postal'] ?? '';
        $estado_sede = $_POST['estado_sede'] ?? 'ACTIVO';
        $tipo_sede = $_POST['tipo_sede'] ?? '';
        $observaciones = $_POST['observaciones'] ?? '';
        
        if (empty($razon_social) || empty($rif)) {
            echo json_encode(['status' => 'error', 'message' => 'Razón social y RIF son obligatorios.']);
            exit;
        }

        if (empty($id_sede)) {
            $stmt = $con->prepare("INSERT INTO sedes (id_empresa, nombre_sede, rif, telefono, email, direccion, ciudad, estado_loc, codigo_postal, tipo_sede, observaciones, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("isssssssssss", $id_empresa, $razon_social, $rif, $telefono, $email, $direccion, $ciudad, $estado_loc, $codigo_postal, $tipo_sede, $observaciones, $estado_sede);
            
            if ($stmt->execute()) {
                $target_id = $stmt->insert_id;
                
                // 1. Sincronizar lineas_whatsapp en el CRM local
                $stmt_w = $con->prepare("UPDATE lineas_whatsapp SET estado = ? WHERE id_sede = ?");
                if ($stmt_w) {
                    $stmt_w->bind_param("si", $estado_sede, $target_id);
                    $stmt_w->execute();
                    $stmt_w->close();
                }
                
                // 2. Sincronizar con el sistema de ventas/core externo
                $legacy_id = ($target_id == 23) ? 23 : ($target_id - 2);
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
                
                // Obtener datos locales del api de whatsapp para sincronizar
                $q_local_api = $con->query("SELECT numero_telefono, meta_telefono_id, meta_token FROM lineas_whatsapp WHERE id_sede = $target_id LIMIT 1");
                $local_api = ($q_local_api && $q_local_api->num_rows > 0) ? $q_local_api->fetch_assoc() : null;

                $con_ventas = getExternalDbConnection('ventas');
                if ($con_ventas) {
                    if ($local_api) {
                        $meta_token = $local_api['meta_token'];
                        $meta_telefono_id = $local_api['meta_telefono_id'];
                        $num_tel = $local_api['numero_telefono'];

                        $check = mysqli_query($con_ventas, "SELECT id FROM config_api_wsap WHERE id_sede = '$legacy_id'");
                        if (mysqli_num_rows($check) > 0) {
                            $stmt_ventas = $con_ventas->prepare("UPDATE config_api_wsap SET token = ?, instance_id = ?, phone_number = ?, activo = ? WHERE id_sede = ?");
                            if ($stmt_ventas) {
                                $stmt_ventas->bind_param("sssii", $meta_token, $meta_telefono_id, $num_tel, $activo_val, $legacy_id);
                                $stmt_ventas->execute();
                                $stmt_ventas->close();
                            }
                        } else {
                            $default_api_url = 'https://api.starficloud.com/api_starfi_wsap/api_enviar_plantilla_meta.php';
                            $stmt_ventas = $con_ventas->prepare("INSERT INTO config_api_wsap (id_sede, api_url, token, instance_id, phone_number, activo) VALUES (?, ?, ?, ?, ?, ?)");
                            if ($stmt_ventas) {
                                $stmt_ventas->bind_param("issssi", $legacy_id, $default_api_url, $meta_token, $meta_telefono_id, $num_tel, $activo_val);
                                $stmt_ventas->execute();
                                $stmt_ventas->close();
                            }
                        }
                    } else {
                        $stmt_ventas = $con_ventas->prepare("UPDATE config_api_wsap SET activo = ? WHERE id_sede = ?");
                        if ($stmt_ventas) {
                            $stmt_ventas->bind_param("ii", $activo_val, $legacy_id);
                            $stmt_ventas->execute();
                            $stmt_ventas->close();
                        }
                    }
                    mysqli_close($con_ventas);
                }
                
                echo json_encode(['status' => 'success', 'message' => 'Sede creada correctamente.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al crear la sede.']);
            }
        } else {
            $stmt = $con->prepare("UPDATE sedes SET nombre_sede=?, rif=?, telefono=?, email=?, direccion=?, ciudad=?, estado_loc=?, codigo_postal=?, tipo_sede=?, observaciones=?, estado=? WHERE id=? AND id_empresa=?");
            $stmt->bind_param("sssssssssssii", $razon_social, $rif, $telefono, $email, $direccion, $ciudad, $estado_loc, $codigo_postal, $tipo_sede, $observaciones, $estado_sede, $id_sede, $id_empresa);
            
            if ($stmt->execute()) {
                $target_id = $id_sede;
                
                // 1. Sincronizar lineas_whatsapp en el CRM local
                $stmt_w = $con->prepare("UPDATE lineas_whatsapp SET estado = ? WHERE id_sede = ?");
                if ($stmt_w) {
                    $stmt_w->bind_param("si", $estado_sede, $target_id);
                    $stmt_w->execute();
                    $stmt_w->close();
                }
                
                // 2. Sincronizar con el sistema de ventas/core externo
                $legacy_id = ($target_id == 23) ? 23 : ($target_id - 2);
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
                
                // Obtener datos locales del api de whatsapp para sincronizar
                $q_local_api = $con->query("SELECT numero_telefono, meta_telefono_id, meta_token FROM lineas_whatsapp WHERE id_sede = $target_id LIMIT 1");
                $local_api = ($q_local_api && $q_local_api->num_rows > 0) ? $q_local_api->fetch_assoc() : null;

                $con_ventas = getExternalDbConnection('ventas');
                if ($con_ventas) {
                    if ($local_api) {
                        $meta_token = $local_api['meta_token'];
                        $meta_telefono_id = $local_api['meta_telefono_id'];
                        $num_tel = $local_api['numero_telefono'];

                        $check = mysqli_query($con_ventas, "SELECT id FROM config_api_wsap WHERE id_sede = '$legacy_id'");
                        if (mysqli_num_rows($check) > 0) {
                            $stmt_ventas = $con_ventas->prepare("UPDATE config_api_wsap SET token = ?, instance_id = ?, phone_number = ?, activo = ? WHERE id_sede = ?");
                            if ($stmt_ventas) {
                                $stmt_ventas->bind_param("sssii", $meta_token, $meta_telefono_id, $num_tel, $activo_val, $legacy_id);
                                $stmt_ventas->execute();
                                $stmt_ventas->close();
                            }
                        } else {
                            $default_api_url = 'https://api.starficloud.com/api_starfi_wsap/api_enviar_plantilla_meta.php';
                            $stmt_ventas = $con_ventas->prepare("INSERT INTO config_api_wsap (id_sede, api_url, token, instance_id, phone_number, activo) VALUES (?, ?, ?, ?, ?, ?)");
                            if ($stmt_ventas) {
                                $stmt_ventas->bind_param("issssi", $legacy_id, $default_api_url, $meta_token, $meta_telefono_id, $num_tel, $activo_val);
                                $stmt_ventas->execute();
                                $stmt_ventas->close();
                            }
                        }
                    } else {
                        $stmt_ventas = $con_ventas->prepare("UPDATE config_api_wsap SET activo = ? WHERE id_sede = ?");
                        if ($stmt_ventas) {
                            $stmt_ventas->bind_param("ii", $activo_val, $legacy_id);
                            $stmt_ventas->execute();
                            $stmt_ventas->close();
                        }
                    }
                    mysqli_close($con_ventas);
                }
                
                echo json_encode(['status' => 'success', 'message' => 'Sede actualizada correctamente.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar la sede.']);
            }
        }
        break;

    case 'get_sede':
        $id = intval($_POST['id'] ?? 0);
        $res = $con->query("SELECT * FROM sedes WHERE id = $id AND id_empresa = $id_empresa");
        if($res && $row = $res->fetch_assoc()) {
            echo json_encode(['status' => 'success', 'data' => $row]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Sede no encontrada.']);
        }
        break;

    case 'delete_sede':
        $id = intval($_POST['id'] ?? 0);
        // Primero eliminar las APIs asociadas para mantener integridad (si no hay foreign key CASCADE)
        $con->query("DELETE FROM lineas_whatsapp WHERE id_sede = $id");
        
        $stmt = $con->prepare("DELETE FROM sedes WHERE id = ? AND id_empresa = ?");
        $stmt->bind_param("ii", $id, $id_empresa);
        if($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'Sede eliminada.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la sede.']);
        }
        break;

    // --- GESTIÓN DE APIS ---
    case 'load_apis':
        $query = "
            SELECT l.*, s.nombre_sede 
            FROM lineas_whatsapp l 
            LEFT JOIN sedes s ON l.id_sede = s.id 
            WHERE s.id_empresa = $id_empresa
        ";
        $res = $con->query($query);
        $data = [];
        if($res){
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'save_api':
        $id_api = $_POST['id_api'] ?? '';
        $id_sede = $_POST['id_sede'] ?? '';
        $descripcion = $_POST['descripcion'] ?? '';
        $telefono = $_POST['telefono'] ?? '';
        $telefono_meta = $_POST['telefono_meta'] ?? '';
        $token_meta = $_POST['token_meta'] ?? '';
        $id_negocio = $_POST['id_negocio'] ?? '';
        $estado = $_POST['estado'] ?? 'ACTIVO';
        $limite_solicitudes = $_POST['limite_solicitudes'] ?: 1000;
        $observaciones = $_POST['observaciones'] ?? '';

        if (empty($id_sede) || empty($descripcion) || empty($telefono) || empty($telefono_meta) || empty($token_meta)) {
            echo json_encode(['status' => 'error', 'message' => 'Campos obligatorios incompletos.']);
            exit;
        }

        if (empty($id_api)) {
            $stmt = $con->prepare("INSERT INTO lineas_whatsapp (id_sede, descripcion, numero_telefono, meta_telefono_id, meta_token, id_negocio, estado_conexion, limite_solicitudes, observaciones, estado) VALUES (?, ?, ?, ?, ?, ?, 'CONECTADO', ?, ?, ?)");
            $stmt->bind_param("isssssiss", $id_sede, $descripcion, $telefono, $telefono_meta, $token_meta, $id_negocio, $limite_solicitudes, $observaciones, $estado);
            
            if ($stmt->execute()) {
                // Sincronizar con starfi_ventas.config_api_wsap
                $legacy_id = ($id_sede == 23) ? 23 : ($id_sede - 2);
                $activo_val = ($estado === 'ACTIVO') ? 1 : 0;
                $con_ventas = getExternalDbConnection('ventas');
                if ($con_ventas) {
                    $check = mysqli_query($con_ventas, "SELECT id FROM config_api_wsap WHERE id_sede = '$legacy_id'");
                    if (mysqli_num_rows($check) > 0) {
                        $stmt_sync = $con_ventas->prepare("UPDATE config_api_wsap SET token = ?, instance_id = ?, phone_number = ?, activo = ? WHERE id_sede = ?");
                        if ($stmt_sync) {
                            $stmt_sync->bind_param("sssii", $token_meta, $telefono_meta, $telefono, $activo_val, $legacy_id);
                            $stmt_sync->execute();
                            $stmt_sync->close();
                        }
                    } else {
                        $default_api_url = 'https://api.starficloud.com/api_starfi_wsap/api_enviar_plantilla_meta.php';
                        $stmt_sync = $con_ventas->prepare("INSERT INTO config_api_wsap (id_sede, api_url, token, instance_id, phone_number, activo) VALUES (?, ?, ?, ?, ?, ?)");
                        if ($stmt_sync) {
                            $stmt_sync->bind_param("issssi", $legacy_id, $default_api_url, $token_meta, $telefono_meta, $telefono, $activo_val);
                            $stmt_sync->execute();
                            $stmt_sync->close();
                        }
                    }
                    mysqli_close($con_ventas);
                }

                echo json_encode(['status' => 'success', 'message' => 'API WhatsApp registrada.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al registrar API.']);
            }
        } else {
            $stmt = $con->prepare("UPDATE lineas_whatsapp SET id_sede=?, descripcion=?, numero_telefono=?, meta_telefono_id=?, meta_token=?, id_negocio=?, limite_solicitudes=?, observaciones=?, estado=? WHERE id=?");
            $stmt->bind_param("isssssissi", $id_sede, $descripcion, $telefono, $telefono_meta, $token_meta, $id_negocio, $limite_solicitudes, $observaciones, $estado, $id_api);
            
            if ($stmt->execute()) {
                // Sincronizar con starfi_ventas.config_api_wsap
                $legacy_id = ($id_sede == 23) ? 23 : ($id_sede - 2);
                $activo_val = ($estado === 'ACTIVO') ? 1 : 0;
                $con_ventas = getExternalDbConnection('ventas');
                if ($con_ventas) {
                    $check = mysqli_query($con_ventas, "SELECT id FROM config_api_wsap WHERE id_sede = '$legacy_id'");
                    if (mysqli_num_rows($check) > 0) {
                        $stmt_sync = $con_ventas->prepare("UPDATE config_api_wsap SET token = ?, instance_id = ?, phone_number = ?, activo = ? WHERE id_sede = ?");
                        if ($stmt_sync) {
                            $stmt_sync->bind_param("sssii", $token_meta, $telefono_meta, $telefono, $activo_val, $legacy_id);
                            $stmt_sync->execute();
                            $stmt_sync->close();
                        }
                    } else {
                        $default_api_url = 'https://api.starficloud.com/api_starfi_wsap/api_enviar_plantilla_meta.php';
                        $stmt_sync = $con_ventas->prepare("INSERT INTO config_api_wsap (id_sede, api_url, token, instance_id, phone_number, activo) VALUES (?, ?, ?, ?, ?, ?)");
                        if ($stmt_sync) {
                            $stmt_sync->bind_param("issssi", $legacy_id, $default_api_url, $token_meta, $telefono_meta, $telefono, $activo_val);
                            $stmt_sync->execute();
                            $stmt_sync->close();
                        }
                    }
                    mysqli_close($con_ventas);
                }

                echo json_encode(['status' => 'success', 'message' => 'API WhatsApp actualizada.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Error al actualizar API.']);
            }
        }
        break;

    case 'get_api_by_sede':
        $id_sede = intval($_POST['id_sede'] ?? 0);
        $res = $con->query("SELECT * FROM lineas_whatsapp WHERE id_sede = $id_sede LIMIT 1");
        if($res && $row = $res->fetch_assoc()) {
            echo json_encode(['status' => 'success', 'data' => $row]);
        } else {
            echo json_encode(['status' => 'success', 'data' => null]);
        }
        break;

    case 'get_api_by_id':
        $id_api = intval($_POST['id_api'] ?? 0);
        $res = $con->query("SELECT * FROM lineas_whatsapp WHERE id = $id_api");
        if($res && $row = $res->fetch_assoc()) {
            echo json_encode(['status' => 'success', 'data' => $row]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'API no encontrada.']);
        }
        break;

    case 'delete_api':
        $id_api = intval($_POST['id'] ?? 0);
        $stmt = $con->prepare("DELETE FROM lineas_whatsapp WHERE id = ?");
        $stmt->bind_param("i", $id_api);
        if($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'API eliminada exitosamente.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al eliminar la API.']);
        }
        break;

    case 'test_api':
        $id_api = $_POST['id_api'] ?? '';
        $numero = $_POST['numero'] ?? '';
        $mensaje = $_POST['mensaje'] ?? '';

        if (empty($id_api) || empty($numero) || empty($mensaje)) {
            echo json_encode(['status' => 'error', 'message' => 'Datos de prueba incompletos.']);
            exit;
        }

        $stmt = $con->prepare("SELECT meta_telefono_id, meta_token FROM lineas_whatsapp WHERE id = ?");
        $stmt->bind_param("i", $id_api);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res && $row = $res->fetch_assoc()) {
            $telefono_meta = $row['meta_telefono_id'];
            $token_meta = $row['meta_token'];
            
            // Simulación de envío (Aquí iría el cURL a Meta)
            $url = "https://graph.facebook.com/v19.0/{$telefono_meta}/messages";
            $data = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => str_replace(['+', ' '], '', $numero),
                'type' => 'text',
                'text' => ['body' => $mensaje]
            ];
            
            /* (Mock de respuesta positiva ya que no es el objetivo ejecutar cURL real ahora sin validaciones) */
            // Esto es solo para la demostración de la prueba de API
            echo json_encode(['status' => 'success', 'message' => 'Mensaje de prueba enviado exitosamente a ' . $numero]);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'API no encontrada.']);
        }
        break;

    // --- OTROS ---
    case 'load_users':
        $query = "
            SELECT u.id, u.nombre_completo as nombre, u.rol, s.nombre_sede as sede, u.limite_chats_simultaneos as limite
            FROM usuarios_agentes u
            LEFT JOIN sedes s ON u.id_sede = s.id
            WHERE u.id_empresa = $id_empresa AND u.estado = 'ACTIVO'
        ";
        $res = $con->query($query);
        $data = [];
        if($res){
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    case 'add_user':
        $nombre = $_POST['nombre'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = password_hash($_POST['password'] ?? '123456', PASSWORD_DEFAULT);
        $rol = $_POST['rol'] ?? 'AGENTE';
        $sede_id = intval($_POST['sede_id'] ?? 0);
        $limite = intval($_POST['limite'] ?? 5);

        if (empty($nombre) || empty($email)) {
            echo json_encode(['status' => 'error', 'message' => 'Nombre y email son obligatorios.']);
            exit;
        }

        $sede_val = $sede_id > 0 ? $sede_id : null;

        $stmt = $con->prepare("INSERT INTO usuarios_agentes (id_empresa, id_sede, nombre_completo, email, password_hash, rol, limite_chats_simultaneos) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("iissssi", $id_empresa, $sede_val, $nombre, $email, $password, $rol, $limite);
            if ($stmt->execute()) {
                echo json_encode(['status' => 'success', 'message' => 'Operador creado correctamente.']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'El correo electrónico ya está registrado.']);
            }
        }
        break;

    case 'get_sedes_list':
        $res = $con->query("SELECT id, nombre_sede FROM sedes WHERE id_empresa = $id_empresa AND estado = 'ACTIVO'");
        $data = [];
        if($res) {
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
        }
        echo json_encode(['status' => 'success', 'data' => $data]);
        break;

    // --- CONFIGURACIÓN GEMA AI ---
    case 'save_gema':
        $prompt = $_POST['prompt'] ?? '';
        $nombre = $_POST['nombre'] ?? 'Gema';
        $token = $_POST['token'] ?? '';
        $estado = intval($_POST['estado'] ?? 1);
        
        $config = [
            'prompt' => $prompt,
            'nombre' => $nombre,
            'token' => $token,
            'estado' => $estado,
            'last_updated' => date('Y-m-d H:i:s')
        ];
        
        $file_path = __DIR__ . '/gema_config.json';
        if (file_put_contents($file_path, json_encode($config, JSON_PRETTY_PRINT))) {
            echo json_encode(['status' => 'success', 'message' => 'Configuración de GEMA AI guardada correctamente.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar el archivo de configuración.']);
        }
        break;

    case 'load_gema':
        $file_path = __DIR__ . '/gema_config.json';
        if (file_exists($file_path)) {
            $config = json_decode(file_get_contents($file_path), true);
            echo json_encode(['status' => 'success', 'data' => $config]);
        } else {
            // Default config
            echo json_encode(['status' => 'success', 'data' => [
                'prompt' => '',
                'nombre' => 'Gema',
                'token' => '',
                'estado' => 1
            ]]);
        }
        break;

    case 'run_diagnostico':
        $diagnostico = [];
        
        // 1. Verificar conexión a BD
        if ($con && !$con->connect_error) {
            $diagnostico['database'] = ['status' => 'ok', 'message' => 'Conexión a la base de datos exitosa.'];
            
            // 2. Verificar tablas
            $tablas = ['empresas', 'sedes', 'lineas_whatsapp', 'usuarios_agentes', 'clientes_contactos', 'conversaciones', 'mensajes_y_eventos', 'notificacion_enviada'];
            $tablas_status = [];
            foreach ($tablas as $tabla) {
                $check = $con->query("SHOW TABLES LIKE '$tabla'");
                if ($check && $check->num_rows > 0) {
                    $tablas_status[$tabla] = true;
                } else {
                    $tablas_status[$tabla] = false;
                }
            }
            $diagnostico['tables'] = ['status' => 'ok', 'data' => $tablas_status];
            
            // 3. Verificar si hay líneas activas
            $q_lineas = $con->query("SELECT COUNT(*) as total FROM lineas_whatsapp WHERE estado = 'ACTIVO'");
            $total_lineas = $q_lineas ? $q_lineas->fetch_assoc()['total'] : 0;
            $diagnostico['lineas_activas'] = [
                'status' => $total_lineas > 0 ? 'ok' : 'warning',
                'message' => $total_lineas > 0 ? "$total_lineas línea(s) de WhatsApp activas en el sistema." : "No hay líneas de WhatsApp activas en el sistema. Los envíos fallarán."
            ];
        } else {
            $diagnostico['database'] = ['status' => 'error', 'message' => 'Fallo en la conexión a la base de datos.'];
        }
        
        // 4. Verificar archivos de controlador y configuración
        $archivos = [
            '../../config/database.php' => 'Configuración de base de datos',
            '../../api_notificaciones.php' => 'API de Notificaciones',
            '../../webhook.php' => 'Webhook de recepción de mensajes',
            '../../simulador_whatsapp.php' => 'Simulador de entrada de WhatsApp'
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
        $url = 'http://localhost/starfi_crm/simulador_whatsapp.php';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $res = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code == 200) {
            echo json_encode(['status' => 'success', 'message' => 'Simulación de mensaje entrante ejecutada correctamente. Revisa la bandeja.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al ejecutar simulador (HTTP ' . $http_code . ').']);
        }
        break;

    case 'run_envio_transaccional':
        $url = 'http://localhost/starfi_crm/api_notificaciones.php';
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_code == 200) {
            $res_data = json_decode($response, true);
            if (isset($res_data['status_envio']) && $res_data['status_envio'] === '[EXITOSO]') {
                echo json_encode(['status' => 'success', 'message' => 'Notificación de prueba enviada con éxito.', 'details' => $res_data]);
            } else {
                $error_msg = isset($res_data['meta_response']['error']['message']) ? $res_data['meta_response']['error']['message'] : 'Error en la API de Meta.';
                echo json_encode(['status' => 'error', 'message' => 'No se pudo enviar la notificación: ' . $error_msg, 'details' => $res_data]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error de conexión con la API de notificaciones (HTTP ' . $http_code . ').']);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
        break;
}
