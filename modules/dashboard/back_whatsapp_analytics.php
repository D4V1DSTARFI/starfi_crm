<?php
date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireAuth();

$action = $_POST['action'] ?? '';
$id_empresa = $_SESSION['id_empresa'] ?? 0;

if ($action === 'get_analytics') {
    $id_sede = intval($_POST['id_sede'] ?? 0);
    $fecha_desde = $_POST['fecha_desde'] ?? date('Y-m-01');
    $fecha_hasta = $_POST['fecha_hasta'] ?? date('Y-m-t');

    // Convert to Unix Timestamps
    $start_ts = strtotime($fecha_desde . " 00:00:00");
    $end_ts = strtotime($fecha_hasta . " 23:59:59");

    $id_plantilla = $_POST['id_plantilla'] ?? '';

    $con = getDbConnection();
    
    if ($id_sede > 0) {
        $res = $con->query("SELECT id_negocio FROM lineas_whatsapp WHERE id_sede = $id_sede LIMIT 1");
    } else {
        $res = $con->query("SELECT id_negocio FROM lineas_whatsapp WHERE id_negocio IS NOT NULL AND id_negocio != '' LIMIT 1");
    }

    if ($res && $row = $res->fetch_assoc()) {
        $waba_id = $row['id_negocio'];
        if (empty($waba_id)) {
            echo json_encode(['status' => 'error', 'message' => 'WABA ID no configurado']);
            exit;
        }

        // Meta Endpoint base (costos, mensajes y lista de plantillas)
        $fields = "conversation_analytics.start($start_ts).end($end_ts).granularity(DAILY),analytics.start($start_ts).end($end_ts).granularity(DAY),message_templates";
        
        // Si hay una plantilla seleccionada, solicitamos también sus métricas
        if (!empty($id_plantilla)) {
            $fields .= ",template_analytics.start($fecha_desde).end($fecha_hasta).granularity(DAILY).template_ids(['$id_plantilla'])";
        }
        
        $url = "https://graph.facebook.com/v23.0/$waba_id?fields=$fields";
        
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . META_GLOBAL_TOKEN, "Content-Type: application/json"]);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $raw_exec = curl_exec($curl);
        $resp = json_decode($raw_exec, true);
        curl_close($curl);
        
        echo json_encode(['status' => 'success', 'data' => $resp]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No hay líneas de WhatsApp configuradas']);
    }
}
