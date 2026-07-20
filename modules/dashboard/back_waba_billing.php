<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
$agente = getAgenteInfo();

if ($agente['rol'] !== 'MASTER') {
    echo json_encode(['status' => 'error', 'message' => 'Acceso denegado. Solo MASTER.']);
    exit;
}

$con = getDbConnection();
$action = $_POST['action'] ?? '';

if ($action === 'get_sede_config') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    
    // Obtener info de sede
    $stmt = $con->prepare("SELECT gerente_nombre, gerente_telefono, gerente_email, pref_not_cobro FROM sedes WHERE id = ?");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $sede_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Obtener tarifa
    $stmt = $con->prepare("SELECT tipo_tarifa, valor, notas_negociacion FROM waba_tarifas_sede WHERE id_sede = ?");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $tarifa_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$tarifa_info) {
        $tarifa_info = [
            'tipo_tarifa' => 'PORCENTAJE',
            'valor' => 10.00,
            'notas_negociacion' => ''
        ];
    }
    
    echo json_encode(['status' => 'success', 'sede' => $sede_info, 'tarifa' => $tarifa_info]);
    exit;
}

if ($action === 'save_sede_config') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $gerente_nombre = $_POST['gerente_nombre'] ?? '';
    $gerente_telefono = $_POST['gerente_telefono'] ?? '';
    $gerente_email = $_POST['gerente_email'] ?? '';
    $pref_not_cobro = $_POST['pref_not_cobro'] ?? 'WHATSAPP';
    
    $tipo_tarifa = $_POST['tipo_tarifa'] ?? 'PORCENTAJE';
    $valor = floatval($_POST['valor'] ?? 10.00);
    $notas = $_POST['notas'] ?? '';
    
    // Update sedes
    $stmt = $con->prepare("UPDATE sedes SET gerente_nombre = ?, gerente_telefono = ?, gerente_email = ?, pref_not_cobro = ? WHERE id = ?");
    $stmt->bind_param("ssssi", $gerente_nombre, $gerente_telefono, $gerente_email, $pref_not_cobro, $id_sede);
    $stmt->execute();
    $stmt->close();
    
    // Update or Insert waba_tarifas_sede
    $stmt = $con->prepare("SELECT id FROM waba_tarifas_sede WHERE id_sede = ?");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    
    if ($res->num_rows > 0) {
        $stmt = $con->prepare("UPDATE waba_tarifas_sede SET tipo_tarifa = ?, valor = ?, notas_negociacion = ? WHERE id_sede = ?");
        $stmt->bind_param("sdsi", $tipo_tarifa, $valor, $notas, $id_sede);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $con->prepare("INSERT INTO waba_tarifas_sede (id_sede, tipo_tarifa, valor, notas_negociacion) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isds", $id_sede, $tipo_tarifa, $valor, $notas);
        $stmt->execute();
        $stmt->close();
    }
    
    echo json_encode(['status' => 'success', 'message' => 'Configuración guardada exitosamente.']);
    exit;
}

if ($action === 'check_last_order') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $fecha_hasta = date('Y-m-d');
    $fecha_desde = date('Y-m-01');
    $tiene_pendiente = false;
    $pendiente_id = null;

    $stmt = $con->prepare("SELECT id, estado, fecha_desde, fecha_hasta FROM waba_ordenes_cobro WHERE id_sede = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if ($row['estado'] === 'PENDIENTE') {
            $tiene_pendiente = true;
            $pendiente_id = $row['id'];
            $fecha_desde = $row['fecha_desde'];
        } else {
            $fecha_desde = date('Y-m-d', strtotime($row['fecha_hasta'] . ' + 1 day'));
        }
    }
    $stmt->close();
    
    echo json_encode([
        'status' => 'success', 
        'fecha_desde' => $fecha_desde, 
        'fecha_hasta' => $fecha_hasta, 
        'tiene_pendiente' => $tiene_pendiente,
        'pendiente_id' => $pendiente_id
    ]);
    exit;
}

if ($action === 'generate_order') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $fecha_desde = $_POST['fecha_desde'] ?? '';
    $fecha_hasta = $_POST['fecha_hasta'] ?? '';
    $orden_pendiente_id = $_POST['orden_pendiente_id'] ?? null;
    
    if (!$id_sede || !$fecha_desde || !$fecha_hasta) {
        echo json_encode(['status' => 'error', 'message' => 'Faltan parámetros']);
        exit;
    }

    $start_ts = strtotime($fecha_desde);
    $end_ts = strtotime($fecha_hasta);
    
    // Obtener WABA ID
    $res = $con->query("SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = $id_sede LIMIT 1");
    if (!$res || !($row = $res->fetch_assoc()) || empty($row['id_negocio'])) {
        echo json_encode(['status' => 'error', 'message' => 'Sede sin WABA configurada']);
        exit;
    }
    $waba_id = $row['id_negocio'];

    // Obtener Tarifa
    $tipo_tarifa = 'PORCENTAJE';
    $valor_tarifa = 10.00;
    $res = $con->query("SELECT tipo_tarifa, valor FROM waba_tarifas_sede WHERE id_sede = $id_sede");
    if ($res && $row = $res->fetch_assoc()) {
        $tipo_tarifa = $row['tipo_tarifa'];
        $valor_tarifa = floatval($row['valor']);
    }
    
    // API Call
    $fields = "pricing_analytics.start($start_ts).end($end_ts).granularity(DAILY),analytics.start($start_ts).end($end_ts).granularity(DAY)";
    $url = "https://graph.facebook.com/v23.0/$waba_id?fields=$fields";
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_GLOBAL_TOKEN, "Content-Type: application/json"]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $raw_exec = curl_exec($curl);
    $resp = json_decode($raw_exec, true);
    curl_close($curl);
    
    if (isset($resp['error'])) {
        echo json_encode(['status' => 'error', 'message' => 'Error de Meta API: ' . $resp['error']['message']]);
        exit;
    }
    
    $costo_meta = 0;
    if(isset($resp['pricing_analytics']['data'][0]['data_points'])) {
        foreach($resp['pricing_analytics']['data'][0]['data_points'] as $dp) {
            if (isset($dp['cost'])) $costo_meta += floatval($dp['cost']);
        }
    }
    
    $mensajes_totales = 0;
    if(isset($resp['analytics']['data_points'])) {
        foreach($resp['analytics']['data_points'] as $dp) {
            if (isset($dp['sent'])) $mensajes_totales += intval($dp['sent']);
        }
    } else if(isset($resp['analytics']['data'][0]['data_points'])) {
        foreach($resp['analytics']['data'][0]['data_points'] as $dp) {
            if (isset($dp['sent'])) $mensajes_totales += intval($dp['sent']);
        }
    }
    
    $costo_final = 0;
    $margen = 0;
    
    if ($tipo_tarifa === 'PORCENTAJE') {
        $costo_final = $costo_meta * (1 + ($valor_tarifa / 100));
        $margen = $costo_final - $costo_meta;
    } else if ($tipo_tarifa === 'FIJA') {
        $margen = $mensajes_totales * $valor_tarifa;
        $costo_final = $costo_meta + $margen;
    } else if ($tipo_tarifa === 'PORCENTAJE_VOLUMEN') {
        $p = $mensajes_totales > 1000 ? ($valor_tarifa / 2) : $valor_tarifa;
        $costo_final = $costo_meta * (1 + ($p / 100));
        $margen = $costo_final - $costo_meta;
    }
    
    // Insert order
    $con->begin_transaction();
    try {
        if ($orden_pendiente_id) {
            // Update old to fused
            $con->query("UPDATE waba_ordenes_cobro SET estado = 'FUSIONADO' WHERE id = " . intval($orden_pendiente_id));
        }
        
        if ($orden_pendiente_id) {
            $stmt = $con->prepare("INSERT INTO waba_ordenes_cobro (id_sede, fecha_desde, fecha_hasta, mensajes_totales, costo_meta, margen_ganancia, monto_total, orden_padre_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issidddi", $id_sede, $fecha_desde, $fecha_hasta, $mensajes_totales, $costo_meta, $margen, $costo_final, $orden_pendiente_id);
        } else {
            $stmt = $con->prepare("INSERT INTO waba_ordenes_cobro (id_sede, fecha_desde, fecha_hasta, mensajes_totales, costo_meta, margen_ganancia, monto_total) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issiddd", $id_sede, $fecha_desde, $fecha_hasta, $mensajes_totales, $costo_meta, $margen, $costo_final);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Error al insertar orden: " . $stmt->error);
        }
        $new_order_id = $stmt->insert_id;
        $stmt->close();
        
        // Insert template details if present
        if(isset($resp['template_analytics']['data'])) {
            $stmt_det = $con->prepare("INSERT INTO waba_ordenes_detalles (id_orden, nombre_plantilla, volumen, costo_base) VALUES (?, ?, ?, ?)");
            foreach($resp['template_analytics']['data'] as $tpl_data) {
                $tpl_name = $tpl_data['name'] ?? 'Desconocida';
                $tpl_vol = 0;
                $tpl_cost = 0; // Meta doesnt give cost per template easily, we estimate or put 0 for now
                if (isset($tpl_data['data_points'])) {
                    foreach($tpl_data['data_points'] as $tdp) {
                        if (isset($tdp['sent'])) $tpl_vol += intval($tdp['sent']);
                    }
                }
                if ($tpl_vol > 0) {
                    $stmt_det->bind_param("isid", $new_order_id, $tpl_name, $tpl_vol, $tpl_cost);
                    $stmt_det->execute();
                }
            }
            $stmt_det->close();
        }
        
        $con->commit();
        echo json_encode(['status' => 'success', 'id_orden' => $new_order_id, 'monto_total' => round($costo_final, 2)]);
    } catch (Exception $e) {
        $con->rollback();
        echo json_encode(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()]);
    }
    exit;
}
?>
