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

// Calcular montos
$subtotal = $o['costo_meta'] + $o['margen_ganancia'];
$impuesto = 0; // Aquí puedes ajustar lógica fiscal si en el futuro se aplica IVA
$total = $o['monto_total'];

$fecha_emision = date('Y-m-d', strtotime($o['fecha_creacion']));
$fecha_vencimiento = date('Y-m-d', strtotime($o['fecha_creacion'] . ' + 5 days')); // 5 días de crédito por ejemplo

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Notificación de Cobro #<?= $id_orden ?></title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 14px;
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
            max-width: 180px;
            height: auto;
        }
        .title {
            font-size: 22px;
            font-weight: bold;
            color: #1a365d;
            text-align: right;
            text-transform: uppercase;
        }
        .info-box {
            background-color: #f7fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 20px;
            height: 120px;
        }
        .two-column {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .two-column td {
            width: 50%;
            vertical-align: top;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .items-table th {
            background-color: #2b6cb0;
            color: #ffffff;
            padding: 10px;
            text-align: left;
            font-size: 13px;
        }
        .items-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 10px;
        }
        .totals-table {
            width: 40%;
            margin-left: auto;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .totals-table td {
            padding: 6px 10px;
        }
        .totals-table .total-row {
            font-weight: bold;
            font-size: 16px;
            background-color: #edf2f7;
            border-top: 2px solid #cbd5e0;
        }
        .payment-methods {
            background-color: #f1f5f9;
            border-left: 4px solid #2b6cb0;
            padding: 12px;
            margin-top: 20px;
            font-size: 12px;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 11px;
            color: #718096;
        }
        .badge-periodo {
            display: inline-block;
            background-color: #ebf8ff;
            color: #2b6cb0;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
        }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: right; margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background-color: #2b6cb0; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;">Imprimir / Guardar PDF</button>
        <button onclick="window.close()" style="padding: 10px 20px; background-color: #718096; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; margin-left: 10px;">Cerrar</button>
    </div>

    <!-- Encabezado con Logo y Título -->
    <table class="header-table">
        <tr>
            <td>
                <!-- Cambia por la URL de tu logo -->
                <img src="../../docs/identidad_visual/logos/logo_starfi_crm.png" class="logo" alt="Logo Starfi">
                <br><br>
                <strong>STARFI INC / STARFI LLC</strong><br>
                Soporte Operacional API WhatsApp<br>
                Venezuela / USA<br>
                facturacion@starficloud.com
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
                    <strong>Atención a:</strong> <?= htmlspecialchars($o['gerente_nombre']) ?><br>
                    <strong>Correo:</strong> <?= htmlspecialchars($o['gerente_email'] ?? $o['email']) ?>
                </div>
            </td>
            <td style="padding-left: 15px;">
                <div class="info-box" style="border-color: #bee3f8; background-color: #ebf8ff;">
                    <strong>DETALLE DEL PERIODO:</strong><br><br>
                    <span>Periodo Correspondiente:</span><br>
                    <span class="badge-periodo"><?= $o['fecha_desde'] ?> al <?= $o['fecha_hasta'] ?></span><br><br>
                    <small>Este documento sirve como notificación previa de cobro por los servicios de infraestructura WhatsApp y márgenes asociados durante el rango de fechas indicado.</small>
                </div>
            </td>
        </tr>
    </table>

    <!-- Detalle de Conceptos / Servicios -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Descripción del Servicio / Concepto</th>
                <th style="text-align: center; width: 100px;">Volumen</th>
                <th style="text-align: right; width: 120px;">Precio Ref.</th>
                <th style="text-align: right; width: 120px;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($detalles as $d): ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($d['nombre_plantilla']) ?></strong><br>
                </td>
                <td style="text-align: center;"><?= $d['volumen'] ?></td>
                <td style="text-align: right;">$<?= number_format( ($d['volumen'] > 0 ? $d['costo_base'] / $d['volumen'] : $d['costo_base']), 4) ?></td>
                <td style="text-align: right;">$<?= number_format($d['costo_base'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
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
            <li><strong>Banco Nacional (Bs):</strong> Consultar tasa del BCV del día con su asesor antes de transferir.</li>
            <li><strong>Zelle / Binance Pay:</strong> pagos@starficloud.com</li>
            <li><strong>Efectivo (USD):</strong> En caja central indicando su nombre de sede y número de orden.</li>
        </ul>
        <p style="margin: 8px 0 0 0; font-style: italic;">Una vez realizado el pago, favor enviar el comprobante respondiendo a la notificación de WhatsApp o correo electrónico.</p>
    </div>

    <!-- Pie de página -->
    <div class="footer">
        <p>Este documento es una notificación de cobro emitida por el sistema STARFI CRM. No constituye una factura fiscal formal hasta la recepción y validación del pago.</p>
    </div>

</body>
</html>
