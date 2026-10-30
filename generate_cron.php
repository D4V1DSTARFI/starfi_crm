<?php
$content = file_get_contents('C:\xampp\htdocs\starfi_crm\modules\dashboard\back_waba_billing.php');
$startString = "function emitir_orden_sede";
$startIndex = strpos($content, $startString);
$endIndex = strpos($content, "if (\$action === 'generate_order')", $startIndex);
if ($startIndex !== false && $endIndex !== false) {
    $funcContent = substr($content, $startIndex, $endIndex - $startIndex);
    
    $cronCode = "<?php\n";
    $cronCode .= "require_once __DIR__ . '/core/auth.php';\n";
    $cronCode .= "\$con = getDbConnection();\n\n";
    $cronCode .= "// Este script se ejecuta por CRON (Task Scheduler)\n";
    $cronCode .= "echo \"Iniciando revisión de límites de facturación automática WABA...\\n\";\n\n";
    $cronCode .= $funcContent . "\n\n";
    
    $cronCode .= "
// Lógica Principal del CRON
\$q = \"SELECT t.id_sede, t.limite_facturacion, l.id_negocio as waba_id 
        FROM waba_tarifas_sede t 
        JOIN lineas_whatsapp l ON t.id_sede = l.id_sede 
        WHERE t.limite_facturacion > 0 AND l.estado = 'ACTIVO'\";
\$res = \$con->query(\$q);

if (!\$res || \$res->num_rows === 0) {
    echo \"No hay sedes con límite de facturación configurado.\\n\";
    exit;
}

while (\$row = \$res->fetch_assoc()) {
    \$id_sede = intval(\$row['id_sede']);
    \$limite = floatval(\$row['limite_facturacion']);
    \$waba_id = \$row['waba_id'];
    
    echo \"Revisando sede ID: \$id_sede (Límite: \$\".\$limite.\")\\n\";
    
    // Buscar la fecha de la última orden de cobro
    \$stmt = \$con->prepare(\"SELECT fecha_hasta FROM waba_ordenes_cobro WHERE id_sede = ? AND estado != 'ANULADA' ORDER BY id DESC LIMIT 1\");
    \$stmt->bind_param(\"i\", \$id_sede);
    \$stmt->execute();
    \$res_last = \$stmt->get_result();
    
    \$fecha_desde = date('Y-m-01');
    if (\$last_order = \$res_last->fetch_assoc()) {
        \$fecha_desde = date('Y-m-d', strtotime(\$last_order['fecha_hasta'] . ' + 1 day'));
    }
    \$stmt->close();
    
    \$fecha_hasta = date('Y-m-d', strtotime('-1 day')); // Revisar hasta ayer
    
    if (strtotime(\$fecha_desde) > strtotime(\$fecha_hasta)) {
        echo \" - Ya facturado hasta la fecha actual.\\n\";
        continue;
    }
    
    // Consultar el consumo a Meta para este periodo
    \$start_ts = strtotime(\$fecha_desde . \" 00:00:00\");
    \$end_ts = strtotime(\$fecha_hasta . \" 23:59:59\");
    
    \$fields = \"pricing_analytics.start(\$start_ts).end(\$end_ts).granularity(DAILY).dimensions(PRICING_CATEGORY)\";
    \$url = \"https://graph.facebook.com/v23.0/\$waba_id?fields=\$fields\";
    
    \$curl = curl_init();
    curl_setopt(\$curl, CURLOPT_URL, \$url);
    curl_setopt(\$curl, CURLOPT_HTTPHEADER, [\"Authorization: Bearer \" . META_GLOBAL_TOKEN, \"Content-Type: application/json\"]);
    curl_setopt(\$curl, CURLOPT_RETURNTRANSFER, true);
    \$resp_json = curl_exec(\$curl);
    \$resp = json_decode(\$resp_json, true);
    curl_close(\$curl);
    
    \$costo_acumulado = 0;
    if(isset(\$resp['pricing_analytics']['data'][0]['data_points'])) {
        foreach(\$resp['pricing_analytics']['data'][0]['data_points'] as \$dp) {
            \$costo_acumulado += floatval(\$dp['cost'] ?? 0);
        }
    }
    
    echo \" - Consumo Acumulado (\".\$fecha_desde.\" al \".\$fecha_hasta.\"): \$\".round(\$costo_acumulado, 2).\"\\n\";
    
    if (\$costo_acumulado >= \$limite) {
        echo \" - ¡Límite superado! Generando orden de cobro...\\n\";
        // Llamar la función de emisión (la que extrajimos)
        \$resultado = emitir_orden_sede(\$con, \$id_sede, \$fecha_desde, \$fecha_hasta, null);
        if (\$resultado['status'] === 'success') {
            echo \" - Orden #{\$resultado['id_orden']} generada con éxito por \${\$resultado['monto_total']} USD.\\n\";
        } else {
            echo \" - ERROR al generar orden: {\$resultado['message']}\\n\";
        }
    } else {
        echo \" - Límite no superado aún.\\n\";
    }
}
echo \"Revisión finalizada.\\n\";
";
    
    file_put_contents('C:\xampp\htdocs\starfi_crm\cron_waba_billing.php', $cronCode);
    echo "cron_waba_billing.php created successfully.";
} else {
    echo "Could not parse emitir_orden_sede";
}
