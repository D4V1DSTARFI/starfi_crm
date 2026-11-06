<?php
// cron_waba_billing.php
// Este script se ejecuta por CRON (Task Scheduler) y utiliza el motor unificado de conciliación híbrida [FAC-03]
date_default_timezone_set('America/Caracas');
define('WABA_BILLING_LIB_ONLY', true);

require_once __DIR__ . '/modules/dashboard/back_waba_billing.php';

$con = getDbConnection();
if (!$con) {
    echo "Error de conexión a la base de datos.\n";
    exit(1);
}

ensureWabaBillingSchema($con);

echo "Iniciando revisión de límites de facturación automática WABA (Motor Híbrido)...\n";

$q = "SELECT DISTINCT t.id_sede, t.limite_facturacion 
      FROM waba_tarifas_sede t 
      JOIN lineas_whatsapp l ON t.id_sede = l.id_sede 
      WHERE t.limite_facturacion > 0 AND l.estado = 'ACTIVO'";
$res = $con->query($q);

if (!$res || $res->num_rows === 0) {
    echo "No hay sedes con límite de facturación configurado.\n";
    exit;
}

while ($row = $res->fetch_assoc()) {
    $id_sede = intval($row['id_sede']);
    $limite  = floatval($row['limite_facturacion']);

    echo "Revisando sede ID: $id_sede (Límite: $" . number_format($limite, 2) . ")\n";

    // Buscar la fecha de la última orden de cobro válida
    $stmt = $con->prepare("SELECT fecha_hasta FROM waba_ordenes_cobro WHERE id_sede = ? AND estado != 'ANULADA' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $res_last = $stmt->get_result();

    $fecha_desde = date('Y-m-01');
    if ($last_order = $res_last->fetch_assoc()) {
        $fecha_desde = date('Y-m-d', strtotime($last_order['fecha_hasta'] . ' + 1 day'));
    }
    $stmt->close();

    $fecha_hasta = date('Y-m-d', strtotime('-1 day')); // Revisar hasta ayer

    if (strtotime($fecha_desde) > strtotime($fecha_hasta)) {
        echo " - Ya facturado hasta la fecha actual.\n";
        continue;
    }

    // Conciliar el consumo del periodo mediante el Motor Híbrido Unificado
    $conciliacion = conciliar_consumo_sede($con, $id_sede, $fecha_desde, $fecha_hasta);
    if ($conciliacion['status'] !== 'success') {
        echo " - No se pudo conciliar sede $id_sede: " . ($conciliacion['message'] ?? 'Error') . "\n";
        continue;
    }

    $costo_acumulado = floatval($conciliacion['costo_meta']);
    $monto_estimado  = floatval($conciliacion['costo_final']);

    echo " - Consumo Base Acumulado ($fecha_desde al $fecha_hasta): $" . number_format($costo_acumulado, 2) . " (Total a cobrar: $" . number_format($monto_estimado, 2) . ")\n";

    if ($costo_acumulado >= $limite) {
        echo " - ¡Límite superado! Generando orden de cobro...\n";
        $resultado = emitir_orden_sede($con, $id_sede, $fecha_desde, $fecha_hasta, null);
        if ($resultado['status'] === 'success') {
            echo " - Orden #{$resultado['id_orden']} generada con éxito por \${$resultado['monto_total']} USD.\n";
        } else {
            echo " - ERROR al generar orden: {$resultado['message']}\n";
        }
    } else {
        echo " - Límite no superado aún.\n";
    }
}

echo "Revisión finalizada.\n";
?>
