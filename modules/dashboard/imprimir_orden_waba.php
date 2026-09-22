<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
$agente = getAgenteInfo();

if ($agente['rol'] !== 'MASTER') {
    die("Acceso Denegado. Solo MASTER puede ver este panel.");
}

$id_orden = intval($_GET['id'] ?? 0);
if ($id_orden <= 0) {
    die("ID de Orden no válido.");
}

$con = getDbConnection();

// Obtener datos de la orden y sede
$q_orden = "
    SELECT o.*, s.nombre_sede, s.rif, s.direccion, s.telefono, s.email, s.gerente_nombre, s.gerente_email 
    FROM waba_ordenes_cobro o 
    JOIN sedes s ON o.id_sede = s.id 
    WHERE o.id = $id_orden
";
$res_o = $con->query($q_orden);
if (!$res_o || !($o = $res_o->fetch_assoc())) {
    die("Orden no encontrada.");
}

// Obtener detalles de la orden
$res_det = $con->query("SELECT * FROM waba_ordenes_detalles WHERE id_orden = $id_orden");
$detalles = [];
while ($row = $res_det->fetch_assoc()) {
    $detalles[] = $row;
}

$detalles_validos = [];
$margen_total = 0;
$costo_meta_total = 0;
$tot_ent = 0;
$tot_gra = 0;
$tot_pag = 0;

foreach ($detalles as $d) {
    if ($d['categoria'] === 'ADMIN') {
        $margen_total += floatval($d['costo_base']);
    } else {
        $costo_meta_total += floatval($d['costo_base']);
        $detalles_validos[] = $d;
        $tot_ent += intval($d['volumen']);
        $tot_gra += intval($d['mensajes_gratuitos']);
        $tot_pag += intval($d['mensajes_pagados']);
    }
}

// Factor para distribuir el margen proporcionalmente sin que el cliente lo vea separado
$factor = 1;
if ($costo_meta_total > 0) {
    $factor = ($costo_meta_total + $margen_total) / $costo_meta_total;
}

// Calcular montos finales
$subtotal = $o['monto_total']; // Ya incluye el margen
$impuesto = 0; 
$total = $o['monto_total'];

$fecha_emision = date('Y-m-d', strtotime($o['fecha_emision']));
$fecha_vencimiento = !empty($o['fecha_vencimiento']) ? date('Y-m-d', strtotime($o['fecha_vencimiento'])) : date('Y-m-d', strtotime($o['fecha_emision'] . ' + 5 days'));

$logo_path = realpath(__DIR__ . '/../../docs/identidad_visual/logos/logo_starfi.png');
$logo_base64 = '';
if ($logo_path && file_exists($logo_path)) {
    $type = pathinfo($logo_path, PATHINFO_EXTENSION);
    $data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
}

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Aviso de Cobro #<?= $id_orden ?></title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 13px;
            color: #333333;
            margin: 0;
            padding: 20px;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .header-table td {
            vertical-align: top;
        }
        .logo {
            max-width: 160px;
            height: auto;
        }
        .title {
            font-size: 20px;
            font-weight: bold;
            color: #1a365d;
            text-align: right;
            text-transform: uppercase;
        }
        .info-box {
            background-color: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 20px;
            height: 110px;
        }
        .two-column {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .two-column td {
            width: 50%;
            vertical-align: top;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        .items-table th {
            background-color: #2b6cb0;
            color: #ffffff;
            padding: 8px;
            text-align: left;
            font-size: 12px;
        }
        .items-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 8px;
        }
        .totals-table {
            width: 40%;
            margin-left: auto;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .totals-table td {
            padding: 6px 8px;
        }
        .totals-table .total-row {
            font-weight: bold;
            font-size: 15px;
            background-color: #edf2f7;
            border-top: 2px solid #cbd5e0;
        }
        .payment-methods {
            background-color: #f1f5f9;
            border-left: 4px solid #2b6cb0;
            padding: 12px;
            margin-top: 15px;
            font-size: 11px;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #718096;
        }
        .badge-periodo {
            display: inline-block;
            background-color: #ebf8ff;
            color: #2b6cb0;
            padding: 3px 6px;
            border-radius: 4px;
            font-weight: bold;
        }
        .stats-table {
            width: 100%;
            margin-top: 5px;
            border-collapse: separate;
            border-spacing: 10px 0;
            text-align: center;
        }
        .stats-table td {
            width: 25%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <!-- Encabezado con Logo y Título -->
    <table class="header-table">
        <tr>
            <td>
                <?php if ($logo_base64): ?>
                <img src="<?= $logo_base64 ?>" class="logo" alt="Logo Starfi">
                <?php endif; ?>
                <br><br>
                <strong>STARFI CODE C.A</strong><br>
                Soporte Operacional API WhatsApp<br>
                Venezuela<br>
                STARFICODE@GMAIL.COM
            </td>
            <td style="text-align: right;">
                <div class="title">Aviso de Cobro</div>
                <p><strong>Nº de Documento:</strong> NC-<?= date('Y') ?>-<?= str_pad($id_orden, 4, '0', STR_PAD_LEFT) ?></p>
                <p><strong>Fecha de Emisión:</strong> <?= $fecha_emision ?></p>
                <p><strong>Fecha Vencimiento:</strong> <span style="color: #c53030; font-weight: bold;"><?= $fecha_vencimiento ?></span></p>
                <p><strong>Estado:</strong> <?= $o['estado'] ?></p>
            </td>
        </tr>
    </table>

    <!-- Datos del Cliente y Periodo -->
    <table class="two-column">
        <tr>
            <td>
                <div class="info-box">
                    <strong>DATOS DEL CLIENTE:</strong><br>
                    <strong>Sede / Cliente:</strong> <?= htmlspecialchars($o['nombre_sede']) ?><br>
                    <strong>Identificación (RIF/ID):</strong> <?= htmlspecialchars($o['rif'] ?? 'N/A') ?><br>
                    <strong>Atención a:</strong> <?= htmlspecialchars($o['gerente_nombre'] ?? 'Administración') ?><br>
                    <strong>Correo:</strong> <?= htmlspecialchars($o['email'] ?? 'No registrado') ?>
                </div>
            </td>
            <td style="padding-left: 15px;">
                <div class="info-box" style="border-color: #bee3f8; background-color: #ebf8ff;">
                    <strong>DETALLE DEL PERIODO:</strong><br><br>
                    <span>Periodo Correspondiente:</span><br>
                    <span class="badge-periodo"><?= $o['fecha_desde'] ?> al <?= $o['fecha_hasta'] ?></span><br><br>
                    <small>Este documento sirve como notificación previa de cobro por los servicios de infraestructura WhatsApp durante el rango de fechas indicado.</small>
                </div>
            </td>
        </tr>
    </table>

    <!-- Estadísticas -->
    <div style="margin-bottom: 20px;">
        <strong style="color: #2b6cb0; font-size: 13px; padding-left: 5px;">ESTADÍSTICAS DEL PERIODO:</strong>
        <table class="stats-table">
            <tr>
                <td style="background-color: #f7fafc;">
                    <strong style="color: #2b6cb0;">Mensajes Totales</strong><br>
                    <div style="font-size: 16px; font-weight: bold; margin-top: 5px;"><?= number_format($o['mensajes_totales']) ?></div>
                </td>
                <td style="background-color: #f0fff4;">
                    <strong style="color: #38a169;">Entregados</strong><br>
                    <div style="font-size: 16px; font-weight: bold; margin-top: 5px;"><?= number_format($tot_ent > 0 ? $tot_ent : $o['mensajes_totales']) ?></div>
                </td>
                <td style="background-color: #e6fffa;">
                    <strong style="color: #319795;">Gratuitos</strong><br>
                    <div style="font-size: 16px; font-weight: bold; margin-top: 5px;"><?= number_format($tot_gra) ?></div>
                </td>
                <td style="background-color: #fffff0;">
                    <strong style="color: #d69e2e;">Pagados</strong><br>
                    <div style="font-size: 16px; font-weight: bold; margin-top: 5px;"><?= number_format($tot_pag > 0 ? $tot_pag : $o['mensajes_totales']) ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Detalle de Conceptos / Servicios -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Descripción del Servicio / Categoría</th>
                <th style="text-align: center; width: 60px;">Volumen</th>
                <th style="text-align: center; width: 60px;">Gratuitos</th>
                <th style="text-align: center; width: 60px;">Pagados</th>
                <th style="text-align: right; width: 70px;">Costo Unit.</th>
                <th style="text-align: right; width: 80px;">Monto</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($detalles_validos)): ?>
                <?php $is_first = true; ?>
                <?php foreach ($detalles_validos as $d): ?>
                    <?php 
                        $costo_final_item = $d['costo_base'] * $factor; 
                        if ($costo_meta_total == 0 && $is_first) {
                            $costo_final_item = $margen_total;
                        }
                        $is_first = false;
                        $pagados = intval($d['mensajes_pagados'] ?? 0);
                        $costo_unit = $pagados > 0 ? ($costo_final_item / $pagados) : 0;
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($d['nombre_plantilla']) ?></strong>
                            <?php if (!empty($d['categoria'])): ?>
                                <br><small style="color: #718096;">Categoría WhatsApp API Meta: <?= htmlspecialchars($d['categoria']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;"><?= number_format($d['volumen']) ?></td>
                        <td style="text-align: center;"><?= number_format($d['mensajes_gratuitos'] ?? 0) ?></td>
                        <td style="text-align: center;"><?= number_format($pagados) ?></td>
                        <td style="text-align: right;">$<?= number_format($costo_unit, 4) ?></td>
                        <td style="text-align: right;">$<?= number_format($costo_final_item, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td>
                        <strong>Servicios de Mensajería Consolidada Meta (API)</strong><br>
                        <small style="color: #718096;">Incluye consumo Meta y costos operativos.</small>
                    </td>
                    <td style="text-align: center;"><?= $o['mensajes_totales'] ?></td>
                    <td style="text-align: center;">0</td>
                    <td style="text-align: center;"><?= $o['mensajes_totales'] ?></td>
                    <td style="text-align: right;">$<?= number_format($o['mensajes_totales'] > 0 ? $subtotal / $o['mensajes_totales'] : 0, 4) ?></td>
                    <td style="text-align: right;">$<?= number_format($subtotal, 2) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Totales -->
    <table class="totals-table">
        <tr>
            <td>Subtotal:</td>
            <td style="text-align: right;">$<?= number_format($subtotal, 2) ?></td>
        </tr>
        <tr>
            <td>Impuestos (0%):</td>
            <td style="text-align: right;">$<?= number_format($impuesto, 2) ?></td>
        </tr>
        <tr class="total-row">
            <td>Monto Total:</td>
            <td style="text-align: right;">$<?= number_format($total, 2) ?> USD</td>
        </tr>
    </table>

    <!-- Métodos de Pago e Instrucciones -->
    <div class="payment-methods">
        <strong>INSTRUCCIONES Y MÉTODOS DE PAGO:</strong><br>
        <p style="margin: 5px 0 0 0;">Por favor, realice el pago antes de la fecha de vencimiento indicando el número de aviso <strong>NC-<?= date('Y') ?>-<?= str_pad($id_orden, 4, '0', STR_PAD_LEFT) ?></strong> en el concepto.</p>
        <ul style="margin: 5px 0 0 0; padding-left: 20px;">
            <li><strong>Binance:</strong> GONZALEZ.1705@GMAIL.COM</li>
        </ul>
        <p style="margin: 8px 0 0 0; font-style: italic;">Una vez realizado el pago, favor enviar el comprobante respondiendo a la notificación de WhatsApp o correo electrónico.</p>
    </div>

    <!-- Pie de página -->
    <div class="footer">
        <p>Este documento es una notificación de cobro emitida por el sistema STARFI CRM. No constituye una factura fiscal formal hasta la recepción y validación del pago.</p>
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// Generar PDF usando DOMPDF
require_once 'C:/xampp/htdocs/starfi2.0/includes/libs/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Enviar el PDF directamente al navegador para su visualización/descarga
$dompdf->stream("Aviso_Cobro_NC-2026-" . str_pad($id_orden, 4, '0', STR_PAD_LEFT) . ".pdf", array("Attachment" => false));
?>
