<?php
date_default_timezone_set('America/Caracas');
define('WABA_BILLING_LIB_ONLY', true);
require_once __DIR__ . '/back_waba_billing.php';

requireAuth();
$agente = getAgenteInfo();
$es_master = (($agente['rol'] ?? '') === 'MASTER');
$id_sede_agente = intval($agente['id_sede'] ?? 0);

$id_orden = intval($_GET['id'] ?? 0);
if ($id_orden <= 0) {
    die("ID de Orden no válido.");
}

$con = getDbConnection();
ensureWabaBillingSchema($con);

// Obtener datos de la orden y sede
$stmt = $con->prepare("
    SELECT o.*, s.nombre_sede, s.rif, s.direccion, s.telefono, s.email, s.gerente_nombre, s.gerente_email 
    FROM waba_ordenes_cobro o 
    JOIN sedes s ON o.id_sede = s.id 
    WHERE o.id = ?
");
$stmt->bind_param("i", $id_orden);
$stmt->execute();
$res_o = $stmt->get_result();
$o = $res_o ? $res_o->fetch_assoc() : null;
$stmt->close();

if (!$o) {
    die("Orden no encontrada.");
}

// [FAC-04] Control de Acceso por Sede: No-MASTER solo puede ver e imprimir órdenes de su propia sede
if (!$es_master && intval($o['id_sede']) !== $id_sede_agente) {
    die("Acceso Denegado. No tiene autorización para visualizar órdenes de otra sede.");
}

// Obtener detalles de la orden
$res_det = $con->query("SELECT * FROM waba_ordenes_detalles WHERE id_orden = $id_orden ORDER BY id ASC");
$detalles = [];
if ($res_det) {
    while ($row = $res_det->fetch_assoc()) {
        $detalles[] = $row;
    }
}

$detalles_validos = [];
$margen_total = 0.0;
$costo_meta_total = 0.0;
$tot_ent = 0;
$tot_gra = 0;
$tot_pag = 0;

foreach ($detalles as $d) {
    if (($d['categoria'] ?? '') === 'ADMIN') {
        $margen_total += floatval($d['costo_base']);
    } else {
        $costo_meta_total += floatval($d['costo_base']);
        $detalles_validos[] = $d;
        $tot_ent += intval($d['volumen']);
        $tot_gra += intval($d['mensajes_gratuitos'] ?? 0);
        $tot_pag += intval($d['mensajes_pagados'] ?? 0);
    }
}

if ($margen_total <= 0 && floatval($o['margen_ganancia'] ?? 0) > 0) {
    $margen_total = floatval($o['margen_ganancia']);
}
if ($costo_meta_total <= 0 && floatval($o['costo_meta'] ?? 0) > 0) {
    $costo_meta_total = floatval($o['costo_meta']);
}

// [FAC-04] REGLA DE ORO DE PRIVACIDAD:
// Factor para integrar el margen proporcionalmente en el Precio Final Unitario y Total de cada línea
$factor = 1.0;
if ($costo_meta_total > 0) {
    $factor = ($costo_meta_total + $margen_total) / $costo_meta_total;
}

$subtotal = floatval($o['monto_total']);
$impuesto = 0.0;
$total = floatval($o['monto_total']);

$fecha_emision = !empty($o['fecha_emision']) ? date('Y-m-d', strtotime($o['fecha_emision'])) : date('Y-m-d');
$fecha_vencimiento = !empty($o['fecha_vencimiento']) ? date('Y-m-d', strtotime($o['fecha_vencimiento'])) : date('Y-m-d', strtotime($fecha_emision . ' + 5 days'));

$logo_path = realpath(__DIR__ . '/../../docs/identidad_visual/logos/logo_starfi.png');
$logo_base64 = '';
if ($logo_path && file_exists($logo_path)) {
    $type = pathinfo($logo_path, PATHINFO_EXTENSION);
    $data = file_get_contents($logo_path);
    $logo_base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
}

// [FAC-02] Preparar miniatura base64 del comprobante de pago si es imagen
$comprobante_base64 = '';
$comprobante_es_pdf = false;
if (!empty($o['comprobante_path'])) {
    $comp_full = realpath(__DIR__ . '/../../' . $o['comprobante_path']);
    if ($comp_full && file_exists($comp_full)) {
        $ext_c = strtolower(pathinfo($comp_full, PATHINFO_EXTENSION));
        if (in_array($ext_c, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $mime_c = ($ext_c === 'jpg') ? 'jpeg' : $ext_c;
            $comprobante_base64 = 'data:image/' . $mime_c . ';base64,' . base64_encode(file_get_contents($comp_full));
        } elseif ($ext_c === 'pdf') {
            $comprobante_es_pdf = true;
        }
    }
}

$estado_orden = strtoupper($o['estado'] ?? 'PENDIENTE');
$titulo_doc = ($estado_orden === 'PAGADO') ? 'Recibo de Pago WABA' : 'Aviso de Cobro';
$color_estado = '#d69e2e';
$texto_estado = $estado_orden;
if ($estado_orden === 'PAGADO') {
    $color_estado = '#16a34a';
    $texto_estado = 'PAGADO (VERIFICADO)';
} elseif ($estado_orden === 'EN_REVISION') {
    $color_estado = '#0284c7';
    $texto_estado = 'PAGADA (PENDIENTE POR REVISIÓN)';
} elseif ($estado_orden === 'PENDIENTE') {
    $color_estado = '#c53030';
    $texto_estado = 'PENDIENTE DE PAGO';
}

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_doc) ?> #<?= $id_orden ?></title>
    <style>
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12.5px;
            color: #333333;
            margin: 0;
            padding: 18px;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .header-table td {
            vertical-align: top;
        }
        .logo {
            max-width: 155px;
            height: auto;
        }
        .title {
            font-size: 19px;
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
            margin-bottom: 15px;
            min-height: 98px;
        }
        .two-column {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .two-column td {
            width: 50%;
            vertical-align: top;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .items-table th {
            background-color: #2b6cb0;
            color: #ffffff;
            padding: 7px 8px;
            text-align: left;
            font-size: 11.5px;
        }
        .items-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 7px 8px;
        }
        .totals-table {
            width: 44%;
            margin-left: auto;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .totals-table td {
            padding: 5px 8px;
        }
        .totals-table .total-row {
            font-weight: bold;
            font-size: 14.5px;
            background-color: #edf2f7;
            border-top: 2px solid #cbd5e0;
        }
        .payment-methods {
            background-color: #f1f5f9;
            border-left: 4px solid #2b6cb0;
            padding: 10px 12px;
            margin-top: 12px;
            font-size: 11px;
        }
        .payment-receipt-box {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-left: 4px solid #16a34a;
            border-radius: 6px;
            padding: 10px 12px;
            margin-top: 12px;
            font-size: 11.5px;
        }
        .payment-review-box {
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            border-left: 4px solid #0284c7;
            border-radius: 6px;
            padding: 10px 12px;
            margin-top: 12px;
            font-size: 11.5px;
        }
        .master-audit-box {
            background-color: #faf5ff;
            border: 1px solid #e9d5ff;
            border-left: 4px solid #7c3aed;
            border-radius: 6px;
            padding: 9px 12px;
            margin-bottom: 14px;
            font-size: 11.5px;
        }
        .footer {
            margin-top: 22px;
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
            border-spacing: 8px 0;
            text-align: center;
        }
        .stats-table td {
            width: 25%;
            padding: 8px;
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
                <div class="title"><?= htmlspecialchars($titulo_doc) ?></div>
                <p style="margin: 4px 0;"><strong>Nº de Documento:</strong> NC-<?= date('Y', strtotime($fecha_emision)) ?>-<?= str_pad($id_orden, 4, '0', STR_PAD_LEFT) ?></p>
                <p style="margin: 4px 0;"><strong>Fecha de Emisión:</strong> <?= $fecha_emision ?></p>
                <p style="margin: 4px 0;"><strong>Fecha Vencimiento:</strong> <span style="color: #c53030; font-weight: bold;"><?= $fecha_vencimiento ?></span></p>
                <p style="margin: 4px 0;"><strong>Estado:</strong> <span style="color: <?= $color_estado ?>; font-weight: bold;"><?= htmlspecialchars($texto_estado) ?></span></p>
            </td>
        </tr>
    </table>

    <!-- Datos del Cliente y Periodo -->
    <table class="two-column">
        <tr>
            <td>
                <div class="info-box">
                    <strong>DATOS DE LA SEDE / CLIENTE:</strong><br>
                    <strong>Sede / Sucursal:</strong> <?= htmlspecialchars($o['nombre_sede']) ?><br>
                    <strong>Identificación (RIF/ID):</strong> <?= htmlspecialchars($o['rif'] ?? 'N/A') ?><br>
                    <strong>Atención a:</strong> <?= htmlspecialchars($o['gerente_nombre'] ?: 'Administración') ?><br>
                    <strong>Correo:</strong> <?= htmlspecialchars(($o['gerente_email'] ?: $o['email']) ?: 'No registrado') ?>
                </div>
            </td>
            <td style="padding-left: 15px;">
                <div class="info-box" style="border-color: #bee3f8; background-color: #ebf8ff;">
                    <strong>DETALLE DEL PERIODO FACTURADO:</strong><br><br>
                    <span>Periodo Correspondiente:</span><br>
                    <span class="badge-periodo"><?= htmlspecialchars($o['fecha_desde']) ?> al <?= htmlspecialchars($o['fecha_hasta']) ?></span><br><br>
                    <small>Liquidación consolidada por consumo de mensajería y servicios operativos de infraestructura WhatsApp Business API.</small>
                </div>
            </td>
        </tr>
    </table>

    <!-- Estadísticas -->
    <div style="margin-bottom: 16px;">
        <strong style="color: #2b6cb0; font-size: 12.5px; padding-left: 5px;">ESTADÍSTICAS DEL PERIODO:</strong>
        <table class="stats-table">
            <tr>
                <td style="background-color: #f7fafc;">
                    <strong style="color: #2b6cb0;">Mensajes Totales</strong><br>
                    <div style="font-size: 15px; font-weight: bold; margin-top: 4px;"><?= number_format($o['mensajes_totales']) ?></div>
                </td>
                <td style="background-color: #f0fff4;">
                    <strong style="color: #38a169;">Entregados</strong><br>
                    <div style="font-size: 15px; font-weight: bold; margin-top: 4px;"><?= number_format($tot_ent > 0 ? $tot_ent : $o['mensajes_totales']) ?></div>
                </td>
                <td style="background-color: #e6fffa;">
                    <strong style="color: #319795;">Gratuitos</strong><br>
                    <div style="font-size: 15px; font-weight: bold; margin-top: 4px;"><?= number_format($tot_gra) ?></div>
                </td>
                <td style="background-color: #fffff0;">
                    <strong style="color: #d69e2e;">Pagados</strong><br>
                    <div style="font-size: 15px; font-weight: bold; margin-top: 4px;"><?= number_format($tot_pag > 0 ? $tot_pag : $o['mensajes_totales']) ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- [FAC-04] Tabla de Conceptos con Precio Final Unitario y Total (comisión integrada) -->
    <table class="items-table">
        <thead>
            <tr>
                <th>Descripción del Servicio / Categoría</th>
                <th style="text-align: center; width: 58px;">Volumen</th>
                <th style="text-align: center; width: 58px;">Gratuitos</th>
                <th style="text-align: center; width: 58px;">Pagados</th>
                <th style="text-align: right; width: 85px;">Precio Unit.</th>
                <th style="text-align: right; width: 90px;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($detalles_validos)): ?>
                <?php $is_first = true; ?>
                <?php foreach ($detalles_validos as $d): ?>
                    <?php
                        $costo_final_item = floatval($d['costo_base']) * $factor;
                        if ($costo_meta_total == 0 && $is_first) {
                            $costo_final_item = $subtotal;
                        }
                        $is_first = false;
                        $vol_item = intval($d['volumen']);
                        $grat_item = intval($d['mensajes_gratuitos'] ?? 0);
                        $pagados = intval($d['mensajes_pagados'] ?? 0);
                        if ($pagados <= 0 && $vol_item > 0 && $grat_item == 0) {
                            $pagados = $vol_item;
                        }
                        $divisor = $pagados > 0 ? $pagados : ($vol_item > 0 ? $vol_item : 1);
                        $precio_unit_final = $costo_final_item / $divisor;
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($d['nombre_plantilla']) ?></strong>
                            <?php if (!empty($d['categoria'])): ?>
                                <br><small style="color: #718096;">Categoría WhatsApp API: <?= htmlspecialchars($d['categoria']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center;"><?= number_format($vol_item) ?></td>
                        <td style="text-align: center;"><?= number_format($grat_item) ?></td>
                        <td style="text-align: center;"><?= number_format($pagados) ?></td>
                        <td style="text-align: right;">$<?= number_format($precio_unit_final, 4) ?></td>
                        <td style="text-align: right; font-weight: bold;">$<?= number_format($costo_final_item, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td>
                        <strong>Servicios de Mensajería Consolidada WhatsApp API</strong><br>
                        <small style="color: #718096;">Consumo operativo del periodo facturado.</small>
                    </td>
                    <td style="text-align: center;"><?= number_format($o['mensajes_totales']) ?></td>
                    <td style="text-align: center;">0</td>
                    <td style="text-align: center;"><?= number_format($o['mensajes_totales']) ?></td>
                    <td style="text-align: right;">$<?= number_format($o['mensajes_totales'] > 0 ? ($subtotal / $o['mensajes_totales']) : $subtotal, 4) ?></td>
                    <td style="text-align: right; font-weight: bold;">$<?= number_format($subtotal, 2) ?></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Totales Finales -->
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
            <td>Total Final:</td>
            <td style="text-align: right;">$<?= number_format($total, 2) ?> USD</td>
        </tr>
    </table>

    <?php if ($es_master && (!isset($_GET['modo']) || $_GET['modo'] !== 'cliente')): ?>
    <!-- [FAC-04] Desglose Financiero Interno Exclusivo MASTER -->
    <div class="master-audit-box">
        <strong style="color: #5b21b6;">AUDITORÍA FINANCIERA INTERNA (EXCLUSIVO ROL MASTER):</strong><br>
        <table style="width: 100%; margin-top: 5px; border-collapse: collapse;">
            <tr>
                <td><strong>Costo Base Meta:</strong> $<?= number_format($costo_meta_total, 2) ?> USD</td>
                <td><strong>Margen CRM:</strong> $<?= number_format($margen_total, 2) ?> USD</td>
                <td><strong>Total a Cobrar:</strong> $<?= number_format($total, 2) ?> USD</td>
            </tr>
        </table>
    </div>
    <?php endif; ?>

    <!-- [FAC-02] Sección de Registro y Reporte de Pago en el Recibo PDF -->
    <?php if (!empty($o['metodo_pago']) || $estado_orden === 'PAGADO' || $estado_orden === 'EN_REVISION'): ?>
        <div class="<?= $estado_orden === 'PAGADO' ? 'payment-receipt-box' : 'payment-review-box' ?>">
            <strong style="color: <?= $estado_orden === 'PAGADO' ? '#166534' : '#0369a1' ?>; font-size: 12px;">
                <?= $estado_orden === 'PAGADO' ? 'CONSTANCIA DE PAGO REGISTRADO Y VERIFICADO' : 'REPORTE DE PAGO (PENDIENTE POR VERIFICACIÓN MASTER)' ?>
            </strong>
            <table style="width: 100%; margin-top: 6px; border-collapse: collapse;">
                <tr>
                    <td style="width: 65%; vertical-align: top;">
                        <p style="margin: 3px 0;"><strong>Método de Pago:</strong> <?= htmlspecialchars($o['metodo_pago'] ?: 'No especificado') ?></p>
                        <p style="margin: 3px 0;"><strong>Referencia / TxID:</strong> <?= htmlspecialchars($o['referencia_pago'] ?: 'N/A') ?></p>
                        <p style="margin: 3px 0;"><strong>Fecha de Pago:</strong> <?= htmlspecialchars(!empty($o['fecha_pago']) ? date('Y-m-d', strtotime($o['fecha_pago'])) : 'N/A') ?></p>
                        <?php if (!empty($o['notas_pago'])): ?>
                            <p style="margin: 3px 0;"><strong>Observaciones / Notas:</strong> <?= nl2br(htmlspecialchars($o['notas_pago'])) ?></p>
                        <?php endif; ?>
                        <?php if ($comprobante_es_pdf): ?>
                            <p style="margin: 3px 0; color: #475569;"><em>* Soporte digital en formato PDF adjunto en el expediente de la orden.</em></p>
                        <?php endif; ?>
                    </td>
                    <td style="width: 35%; text-align: right; vertical-align: top;">
                        <?php if (!empty($comprobante_base64)): ?>
                            <div style="font-size: 10px; color: #475569; margin-bottom: 3px;">Comprobante Adjunto:</div>
                            <img src="<?= $comprobante_base64 ?>" style="max-width: 140px; max-height: 110px; border: 1px solid #cbd5e1; border-radius: 4px;" alt="Comprobante">
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    <?php else: ?>
        <!-- Métodos de Pago e Instrucciones (cuando aún está PENDIENTE) -->
        <div class="payment-methods">
            <strong>INSTRUCCIONES Y MÉTODOS DE PAGO:</strong><br>
            <p style="margin: 5px 0 0 0;">Por favor, realice el pago antes de la fecha de vencimiento indicando el número de documento <strong>NC-<?= date('Y', strtotime($fecha_emision)) ?>-<?= str_pad($id_orden, 4, '0', STR_PAD_LEFT) ?></strong> en el concepto.</p>
            <ul style="margin: 5px 0 0 0; padding-left: 20px;">
                <li><strong>Métodos habilitados:</strong> Binance (GONZALEZ.1705@GMAIL.COM), Zelle, Pago Móvil, Transferencia o Efectivo.</li>
            </ul>
            <p style="margin: 6px 0 0 0; font-style: italic;">Una vez realizado el pago, regístrelo adjuntando el comprobante desde el Panel de Órdenes WABA en STARFI CRM.</p>
        </div>
    <?php endif; ?>

    <!-- Pie de página -->
    <div class="footer">
        <p>Documento generado por el módulo financiero WABA de STARFI CRM. <?= $estado_orden === 'PAGADO' ? 'Pago conciliado y verificado por administración.' : 'Sujeto a recepción y verificación de fondos.' ?></p>
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// Generar PDF usando DOMPDF
$autoload_candidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../includes/libs/vendor/autoload.php',
    'C:/xampp/htdocs/starfi2.0/includes/libs/vendor/autoload.php'
];
foreach ($autoload_candidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        break;
    }
}

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

$prefix_file = ($estado_orden === 'PAGADO') ? "Recibo_Pago_NC-" : "Aviso_Cobro_NC-";
$dompdf->stream($prefix_file . date('Y', strtotime($fecha_emision)) . "-" . str_pad($id_orden, 4, '0', STR_PAD_LEFT) . ".pdf", ["Attachment" => false]);
?>
