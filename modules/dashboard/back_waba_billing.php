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
?>
