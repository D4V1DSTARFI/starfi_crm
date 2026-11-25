<?php
// cron_waba_billing.php
// Este script se ejecuta por CRON (Task Scheduler) y utiliza el motor unificado de conciliación híbrida [FAC-03]
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');
define('WABA_BILLING_LIB_ONLY', true);

require_once __DIR__ . '/core/audit.php';
require_once __DIR__ . '/modules/waba_ordenes/back_waba_ordenes.php';

$con = getDbConnection();
if (!$con) {
    echo "Error de conexión a la base de datos.\n";
    exit(1);
}

ensureWabaBillingSchema($con);

echo "Iniciando revisión de límites de facturación automática WABA (Motor Híbrido)...\n";

$estado_activo = 'ACTIVO';
$min_limite = 0.0;
$stmtSedes = $con->prepare("
    SELECT DISTINCT t.id_sede, t.limite_facturacion
    FROM waba_tarifas_sede t
    JOIN lineas_whatsapp l ON t.id_sede = l.id_sede
    WHERE t.limite_facturacion > ? AND l.estado = ?
");

if (!$stmtSedes) {
    starfi_log_exception('waba_ordenes', 'Error preparando consulta de sedes en cron_waba_billing.php');
    exit(1);
}
$stmtSedes->bind_param("ds", $min_limite, $estado_activo);
$stmtSedes->execute();
$res = $stmtSedes->get_result();

if (!$res || $res->num_rows === 0) {
    $stmtSedes->close();
    registrar_auditoria(
        'waba_ordenes',
        'CRON_REVISION_FACTURACION_WABA',
        'Ejecución automática de revisión WABA sin sedes activas con límite configurado.',
        null,
        ['sedes_evaluadas' => 0, 'ordenes_generadas' => 0],
        $con,
        null,
        null
    );
    echo "No hay sedes con límite de facturación configurado.\n";
    exit;
}

$sedes_rows = [];
while ($r = $res->fetch_assoc()) {
    $sedes_rows[] = $r;
}
$stmtSedes->close();

$sedes_evaluadas = 0;
$ordenes_generadas = 0;

foreach ($sedes_rows as $row) {
    $sedes_evaluadas++;
    $id_sede = intval($row['id_sede']);
    $limite  = floatval($row['limite_facturacion']);

    echo "Revisando sede ID: $id_sede (Límite: $" . number_format($limite, 2) . ")\n";

    // Buscar la fecha de la última orden de cobro válida
    $stmt = $con->prepare("SELECT fecha_hasta FROM waba_ordenes_cobro WHERE id_sede = ? AND estado != 'ANULADA' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("i", $id_sede);
    $stmt->execute();
    $res_last = $stmt->get_result();

    $fecha_desde = date('Y-m-01');
    if ($res_last && ($last_order = $res_last->fetch_assoc())) {
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
    if (($conciliacion['status'] ?? '') !== 'success') {
        echo " - No se pudo conciliar sede $id_sede: " . ($conciliacion['message'] ?? 'Error') . "\n";
        continue;
    }

    $costo_acumulado = floatval($conciliacion['costo_meta']);
    $monto_estimado  = floatval($conciliacion['costo_final']);

    echo " - Consumo Base Acumulado ($fecha_desde al $fecha_hasta): $" . number_format($costo_acumulado, 2) . " (Total a cobrar: $" . number_format($monto_estimado, 2) . ")\n";

    if ($costo_acumulado >= $limite) {
        echo " - ¡Límite superado! Generando orden de cobro...\n";
        $resultado = emitir_orden_sede($con, $id_sede, $fecha_desde, $fecha_hasta, null);
        if (($resultado['status'] ?? '') === 'success') {
            $ordenes_generadas++;
            registrar_auditoria(
                'waba_ordenes',
                'CRON_EMISION_ORDEN_WABA',
                "Orden automática #{$resultado['id_orden']} emitida por límite de facturación en sede #{$id_sede}",
                null,
                [
                    'id_orden' => $resultado['id_orden'],
                    'codigo_orden' => $resultado['codigo_orden'] ?? null,
                    'id_sede' => $id_sede,
                    'fecha_desde' => $fecha_desde,
                    'fecha_hasta' => $fecha_hasta,
                    'monto_total' => $resultado['monto_total'],
                ],
                $con,
                null,
                $id_sede
            );
            echo " - Orden #{$resultado['id_orden']} generada con éxito por \${$resultado['monto_total']} USD.\n";
        } else {
            echo " - ERROR al generar orden: " . ($resultado['message'] ?? 'Error desconocido') . "\n";
        }
    } else {
        echo " - Límite no superado aún.\n";
    }
}

registrar_auditoria(
    'waba_ordenes',
    'CRON_REVISION_FACTURACION_WABA',
    "Ejecución completada de revisión automática WABA ({$sedes_evaluadas} sedes evaluadas, {$ordenes_generadas} órdenes emitidas).",
    null,
    ['sedes_evaluadas' => $sedes_evaluadas, 'ordenes_generadas' => $ordenes_generadas],
    $con,
    null,
    null
);

echo "Revisión finalizada.\n";
