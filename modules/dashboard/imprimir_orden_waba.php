<?php
// modules/dashboard/imprimir_orden_waba.php
// Plantilla Institucional de Impresión / PDF de Órdenes WABA — STARFI CRM (QA-STD-GLOBAL-2.1)
// 100% mysqli_prepare(), logo del cliente en encabezado superior izquierdo y sello oficial
// "Generado por Starfi" con isologo_ni.png en pie inferior derecho.

@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireAuth();
$agente = getAgenteInfo();
$es_master = (($agente['rol'] ?? '') === 'MASTER' || intval($agente['id'] ?? 0) === 1);
$id_sede_agente = intval($agente['id_sede'] ?? 0);

session_write_close();

$id_orden = intval($_GET['id'] ?? 0);
if ($id_orden <= 0) {
    die("ID de Orden no válido.");
}

$con = getDbConnection();
if (!$con) {
    die("Error de conexión a la base de datos.");
}

// 1. Obtener datos de la orden vía mysqli_prepare()
$stmtOrd = $con->prepare("SELECT * FROM waba_ordenes_cobro WHERE id = ? LIMIT 1");
$o = null;
if ($stmtOrd) {
    $stmtOrd->bind_param("i", $id_orden);
    $stmtOrd->execute();
    $res_o = $stmtOrd->get_result();
    $o = ($res_o && $res_o->num_rows > 0) ? $res_o->fetch_assoc() : null;
    $stmtOrd->close();
}

if (!$o) {
    die("Orden no encontrada.");
}

$id_sede_orden = intval($o['id_sede'] ?? 0);
if (!$es_master && $id_sede_orden !== $id_sede_agente) {
    http_response_code(403);
    die("Acceso Denegado. No tiene autorización para visualizar órdenes de otra sede.");
}

// 2. Obtener datos de la sede vía mysqli_prepare()
$sede = [];
$stmtSede = $con->prepare("SELECT * FROM sedes WHERE id = ? LIMIT 1");
if ($stmtSede) {
    $stmtSede->bind_param("i", $id_sede_orden);
    $stmtSede->execute();
    $resSede = $stmtSede->get_result();
    $sede = ($resSede && $resSede->num_rows > 0) ? $resSede->fetch_assoc() : [];
    $stmtSede->close();
}

// 3. Consultar perfil corporativo del cliente (empresa_perfil y empresas) vía mysqli_prepare()
$id_empresa_orden = max(1, intval($sede['id_empresa'] ?? 1));
$emp_base = [];
$stmtEmp = $con->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
if ($stmtEmp) {
    $stmtEmp->bind_param("i", $id_empresa_orden);
    $stmtEmp->execute();
    $resEmp = $stmtEmp->get_result();
    $emp_base = ($resEmp && $resEmp->num_rows > 0) ? $resEmp->fetch_assoc() : [];
    $stmtEmp->close();
}

$perfil_emp = [];
$empPerfilCols = [];
$resPerfilCols = @$con->prepare("SHOW COLUMNS FROM empresa_perfil");
if ($resPerfilCols && $resPerfilCols->execute()) {
    $rc = $resPerfilCols->get_result();
    while ($rc && ($col = $rc->fetch_assoc())) {
        $empPerfilCols[$col['Field']] = true;
    }
    $resPerfilCols->close();
}

if (!empty($empPerfilCols)) {
    if (isset($empPerfilCols['id_sede'])) {
        $stmtProf = $con->prepare("SELECT * FROM empresa_perfil WHERE id_sede = ? OR id = ? ORDER BY (id_sede = ?) DESC, id ASC LIMIT 1");
        if ($stmtProf) {
            $stmtProf->bind_param("iii", $id_sede_orden, $id_empresa_orden, $id_sede_orden);
            $stmtProf->execute();
            $resProf = $stmtProf->get_result();
            $perfil_emp = ($resProf && $resProf->num_rows > 0) ? $resProf->fetch_assoc() : [];
            $stmtProf->close();
        }
    } elseif (isset($empPerfilCols['id_empresa'])) {
        $stmtProf = $con->prepare("SELECT * FROM empresa_perfil WHERE id_empresa = ? ORDER BY id ASC LIMIT 1");
        if ($stmtProf) {
            $stmtProf->bind_param("i", $id_empresa_orden);
            $stmtProf->execute();
            $resProf = $stmtProf->get_result();
            $perfil_emp = ($resProf && $resProf->num_rows > 0) ? $resProf->fetch_assoc() : [];
            $stmtProf->close();
        }
    } else {
        $stmtProf = $con->prepare("SELECT * FROM empresa_perfil ORDER BY id ASC LIMIT 1");
        if ($stmtProf) {
            $stmtProf->execute();
            $resProf = $stmtProf->get_result();
            $perfil_emp = ($resProf && $resProf->num_rows > 0) ? $resProf->fetch_assoc() : [];
            $stmtProf->close();
        }
    }
}

// 4. Obtener detalles de la orden vía mysqli_prepare()
$detalles = [];
$stmtDet = $con->prepare("SELECT * FROM waba_ordenes_detalles WHERE id_orden = ? ORDER BY id ASC");
if ($stmtDet) {
    $stmtDet->bind_param("i", $id_orden);
    $stmtDet->execute();
    $res_det = $stmtDet->get_result();
    while ($res_det && ($row = $res_det->fetch_assoc())) {
        $detalles[] = $row;
    }
    $stmtDet->close();
}

$mensajes_totales_ord = intval($o['mensajes_totales'] ?? ($o['total_mensajes'] ?? 0));
$costo_meta_ord       = floatval($o['costo_meta'] ?? ($o['costo_meta_usd'] ?? 0));
$monto_total_ord      = floatval($o['monto_total'] ?? ($o['monto_total_usd'] ?? 0));
$margen_ord           = isset($o['margen_ganancia']) ? floatval($o['margen_ganancia']) : max(0.0, $monto_total_ord - $costo_meta_ord);
$notas_pago_ord       = (string)($o['notas_pago'] ?? ($o['observaciones_pago'] ?? ''));
$comprobante_rel      = trim((string)($o['comprobante_ruta'] ?? ($o['comprobante_path'] ?? '')));

$detalles_validos = [];
$margen_total = 0.0;
$costo_meta_total = 0.0;
$tot_ent = 0;
$tot_gra = 0;
$tot_pag = 0;

foreach ($detalles as $d) {
    $cBaseD = isset($d['costo_base']) ? floatval($d['costo_base']) : floatval($d['subtotal_usd'] ?? 0);
    $d['_costo_base'] = $cBaseD;
    if (($d['categoria'] ?? '') === 'ADMIN') {
        $margen_total += $cBaseD;
    } else {
        $costo_meta_total += $cBaseD;
        $detalles_validos[] = $d;
        $tot_ent += intval($d['volumen']);
        $tot_gra += intval($d['mensajes_gratuitos'] ?? 0);
        $tot_pag += intval($d['mensajes_pagados'] ?? 0);
    }
}

if ($margen_total <= 0 && $margen_ord > 0) {
    $margen_total = $margen_ord;
}
if ($costo_meta_total <= 0 && $costo_meta_ord > 0) {
    $costo_meta_total = $costo_meta_ord;
}

$factor = ($costo_meta_total > 0) ? (($costo_meta_total + $margen_total) / $costo_meta_total) : 1.0;
$subtotal = $monto_total_ord;
$impuesto = 0.0;
$total    = $monto_total_ord;

$fecha_emision_raw = $o['fecha_emision'] ?? ($o['created_at'] ?? date('Y-m-d'));
$fecha_emision     = date('Y-m-d', strtotime((string)$fecha_emision_raw));
$fecha_vencimiento = !empty($o['fecha_vencimiento'])
    ? date('Y-m-d', strtotime((string)$o['fecha_vencimiento']))
    : date('Y-m-d', strtotime($fecha_emision . ' + 5 days'));
$codigo_documento  = !empty($o['codigo_orden'])
    ? (string)$o['codigo_orden']
    : ('NC-' . date('Y', strtotime($fecha_emision)) . '-' . str_pad((string)$id_orden, 4, '0', STR_PAD_LEFT));

function imageToFastPdfBase64($file_path, $max_w = 320, $max_h = 240, $bg_rgb = [255, 255, 255]) {
    if (!$file_path || !file_exists($file_path)) {
        return '';
    }
    if (function_exists('getimagesize') && function_exists('imagecreatetruecolor')) {
        $info = @getimagesize($file_path);
        if ($info && !empty($info[0]) && !empty($info[1])) {
            $src_w = intval($info[0]);
            $src_h = intval($info[1]);
            $mime  = strtolower($info['mime'] ?? '');
            $src_img = null;
            if ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
                $src_img = @imagecreatefrompng($file_path);
            } elseif (($mime === 'image/jpeg' || $mime === 'image/jpg') && function_exists('imagecreatefromjpeg')) {
                $src_img = @imagecreatefromjpeg($file_path);
            } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
                $src_img = @imagecreatefromwebp($file_path);
            }
            if ($src_img) {
                $ratio = min($max_w / max($src_w, 1), $max_h / max($src_h, 1), 1.0);
                $dst_w = max(1, intval(round($src_w * $ratio)));
                $dst_h = max(1, intval(round($src_h * $ratio)));
                $dst_img = imagecreatetruecolor($dst_w, $dst_h);
                $bg = imagecolorallocate($dst_img, $bg_rgb[0], $bg_rgb[1], $bg_rgb[2]);
                imagefilledrectangle($dst_img, 0, 0, $dst_w, $dst_h, $bg);
                imagealphablending($dst_img, true);
                imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
                ob_start();
                imagejpeg($dst_img, null, 88);
                $jpeg_bytes = ob_get_clean();
                imagedestroy($src_img);
                imagedestroy($dst_img);
                if (!empty($jpeg_bytes)) {
                    return 'data:image/jpeg;base64,' . base64_encode($jpeg_bytes);
                }
            }
        }
    }
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
    $mime_fallback = ($ext === 'jpg') ? 'jpeg' : $ext;
    return 'data:image/' . $mime_fallback . ';base64,' . base64_encode((string)file_get_contents($file_path));
}

// [CHK-UIX-03] Identidad del Cliente en encabezado superior izquierdo (empresa_perfil.logo_ruta o insignia tipográfica)
$cliente_nombre_comercial = trim((string)(
    $perfil_emp['nombre_comercial']
    ?? ($emp_base['nombre_comercial'] ?? ($perfil_emp['razon_social'] ?? ($sede['nombre_sede'] ?? 'Empresa Cliente')))
));
$cliente_razon_social = trim((string)(
    $perfil_emp['razon_social']
    ?? ($emp_base['razon_social'] ?? $cliente_nombre_comercial)
));
$cliente_rif_raw = trim((string)($perfil_emp['rif'] ?? ($sede['rif'] ?? ($sede['rif_sede'] ?? ($emp_base['documento_identidad'] ?? 'N/A')))));
if (!empty($perfil_emp['letra']) && $cliente_rif_raw !== '' && strpos($cliente_rif_raw, '-') === false) {
    $cliente_rif = trim((string)$perfil_emp['letra']) . '-' . $cliente_rif_raw;
} else {
    $cliente_rif = $cliente_rif_raw !== '' ? $cliente_rif_raw : 'N/A';
}
$cliente_direccion = trim((string)($perfil_emp['direccion_fiscal'] ?? ($perfil_emp['direccion'] ?? ($sede['direccion'] ?? ''))));
$cliente_correo    = trim((string)($perfil_emp['correo_corporativo'] ?? ($perfil_emp['email'] ?? ($sede['gerente_email'] ?? ($sede['correo_notificaciones'] ?? ($sede['email'] ?? ''))))));

$root_real = realpath(__DIR__ . '/../..');
$cliente_logo_rel_raw = trim((string)($perfil_emp['logo_ruta'] ?? ($perfil_emp['logo_empresa'] ?? '')));
$cliente_logo_rel_url = '';
$cliente_logo_abs     = '';

if ($cliente_logo_rel_raw !== '' && $root_real) {
    $cleanRel = ltrim(str_replace('\\', '/', $cliente_logo_rel_raw), '/');
    // Prohibir cualquier uso accidental de logos corporativos de Starfi como logo del cliente
    if (stripos($cleanRel, 'identidad_visual/logos/') === false) {
        $candidates = [
            $cleanRel,
            'uploads/empresa/' . basename($cleanRel),
        ];
        foreach ($candidates as $candRel) {
            $candAbs = realpath($root_real . '/' . $candRel);
            if ($candAbs && strpos($candAbs, $root_real) === 0 && is_file($candAbs)) {
                $cliente_logo_abs     = $candAbs;
                $cliente_logo_rel_url = '../../' . $candRel;
                break;
            }
        }
    }
}
$cliente_logo_base64 = $cliente_logo_abs !== '' ? imageToFastPdfBase64($cliente_logo_abs, 310, 140, [255, 255, 255]) : '';

// [CHK-UIX-03] Sello institucional oficial en esquina inferior derecha ("Generado por Starfi" + isologo_ni.png)
$seal_rel_url = '../../docs/identidad_visual/logos/isologo_ni.png';
$seal_abs     = realpath(__DIR__ . '/' . $seal_rel_url);
$seal_base64  = ($seal_abs && is_file($seal_abs)) ? imageToFastPdfBase64($seal_abs, 90, 90, [255, 255, 255]) : '';

// Comprobante adjunto (miniatura local segura)
$comprobante_base64 = '';
$comprobante_es_pdf = false;
if ($comprobante_rel !== '' && $root_real) {
    $comp_full = realpath($root_real . '/' . ltrim(str_replace('\\', '/', $comprobante_rel), '/'));
    if ($comp_full && strpos($comp_full, $root_real) === 0 && is_file($comp_full)) {
        $finfoComp = new finfo(FILEINFO_MIME_TYPE);
        $compMime  = strtolower((string)$finfoComp->file($comp_full));
        if (in_array($compMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $bg_comp = (strtoupper($o['estado'] ?? '') === 'PAGADO') ? [240, 253, 244] : [240, 249, 255];
            $comprobante_base64 = imageToFastPdfBase64($comp_full, 280, 220, $bg_comp);
        } elseif ($compMime === 'application/pdf') {
            $comprobante_es_pdf = true;
        }
    }
}

$estado_orden = strtoupper($o['estado'] ?? 'PENDIENTE');
$titulo_doc   = ($estado_orden === 'PAGADO') ? 'Recibo de Pago WABA' : 'Aviso de Cobro';
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

$nombre_sede_lbl = (string)($sede['nombre_sede'] ?? ('Sede #' . $id_sede_orden));
$atencion_lbl    = (string)($sede['gerente_nombre'] ?? '') ?: 'Administración';
$correo_sede_lbl = (string)($sede['gerente_email'] ?? ($sede['correo_notificaciones'] ?? ($sede['email'] ?? ''))) ?: ($cliente_correo ?: 'No registrado');

ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($titulo_doc) ?> #<?= $id_orden ?></title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
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
            max-height: 72px;
            height: auto;
            display: block;
            margin-bottom: 6px;
        }
        .client-brand-badge {
            display: inline-block;
            background-color: #1e293b;
            color: #ffffff;
            padding: 8px 14px;
            border-radius: 6px;
            border-left: 4px solid #E85B14;
            font-size: 14px;
            font-weight: bold;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
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
            background-color: #37414A;
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
            border-left: 4px solid #37414A;
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
        .footer-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }
        .footer-note {
            font-size: 10px;
            color: #718096;
            vertical-align: middle;
        }
        .footer-seal-cell {
            text-align: right;
            vertical-align: middle;
            white-space: nowrap;
        }
        .seal-text {
            font-size: 10.5px;
            font-weight: bold;
            color: #37414A;
            vertical-align: middle;
            margin-right: 6px;
        }
        .seal-isologo {
            height: 22px;
            width: auto;
            vertical-align: middle;
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
    <!-- Encabezado Institucional: Logo / Identidad de la Empresa Cliente (Superior Izquierda) -->
    <table class="header-table">
        <tr>
            <td>
                <?php if ($cliente_logo_rel_url !== ''): ?>
                    <img src="<?= htmlspecialchars($cliente_logo_rel_url, ENT_QUOTES, 'UTF-8') ?>" class="logo" alt="<?= htmlspecialchars($cliente_nombre_comercial, ENT_QUOTES, 'UTF-8') ?>">
                <?php else: ?>
                    <div class="client-brand-badge"><?= htmlspecialchars($cliente_nombre_comercial, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div>
                    <strong><?= htmlspecialchars($cliente_razon_social, ENT_QUOTES, 'UTF-8') ?></strong><br>
                    RIF / ID Fiscal: <?= htmlspecialchars($cliente_rif, ENT_QUOTES, 'UTF-8') ?><br>
                    <?php if ($cliente_direccion !== ''): ?>
                        <?= htmlspecialchars($cliente_direccion, ENT_QUOTES, 'UTF-8') ?><br>
                    <?php endif; ?>
                    Sede: <?= htmlspecialchars($nombre_sede_lbl, ENT_QUOTES, 'UTF-8') ?>
                </div>
            </td>
            <td style="text-align: right;">
                <div class="title"><?= htmlspecialchars($titulo_doc) ?></div>
                <p style="margin: 4px 0;"><strong>Nº de Documento:</strong> <?= htmlspecialchars($codigo_documento) ?></p>
                <p style="margin: 4px 0;"><strong>Fecha de Emisión:</strong> <?= htmlspecialchars($fecha_emision) ?></p>
                <p style="margin: 4px 0;"><strong>Fecha Vencimiento:</strong> <span style="color: #c53030; font-weight: bold;"><?= htmlspecialchars($fecha_vencimiento) ?></span></p>
                <p style="margin: 4px 0;"><strong>Estado:</strong> <span style="color: <?= $color_estado ?>; font-weight: bold;"><?= htmlspecialchars($texto_estado) ?></span></p>
            </td>
        </tr>
    </table>

    <!-- Datos de la Sede y Periodo -->
    <table class="two-column">
        <tr>
            <td>
                <div class="info-box">
                    <strong>DATOS DE LA SEDE / CLIENTE:</strong><br>
                    <strong>Sede / Sucursal:</strong> <?= htmlspecialchars($nombre_sede_lbl) ?><br>
                    <strong>Identificación (RIF/ID):</strong> <?= htmlspecialchars($cliente_rif) ?><br>
                    <strong>Atención a:</strong> <?= htmlspecialchars($atencion_lbl) ?><br>
                    <strong>Correo:</strong> <?= htmlspecialchars($correo_sede_lbl) ?>
                </div>
            </td>
            <td style="padding-left: 15px;">
                <div class="info-box" style="border-color: #bee3f8; background-color: #ebf8ff;">
                    <strong>DETALLE DEL PERIODO FACTURADO:</strong><br><br>
                    <span>Periodo Correspondiente:</span><br>
                    <span class="badge-periodo"><?= htmlspecialchars((string)$o['fecha_desde']) ?> al <?= htmlspecialchars((string)$o['fecha_hasta']) ?></span><br><br>
                    <small>Liquidación consolidada por consumo de mensajería y servicios operativos de infraestructura WhatsApp Business API.</small>
                </div>
            </td>
        </tr>
    </table>

    <!-- Estadísticas -->
    <div style="margin-bottom: 16px;">
        <strong style="color: #37414A; font-size: 12.5px; padding-left: 5px;">ESTADÍSTICAS DEL PERIODO:</strong>
        <table class="stats-table">
            <tr>
                <td style="background-color: #f7fafc;">
                    <strong style="color: #2b6cb0;">Mensajes Totales</strong><br>
                    <div style="font-size: 15px; font-weight: bold; margin-top: 4px;"><?= number_format($mensajes_totales_ord) ?></div>
                </td>
                <td style="background-color: #f0fff4;">
                    <strong style="color: #38a169;">Entregados</strong><br>
                    <div style="font-size: 15px; font-weight: bold; margin-top: 4px;"><?= number_format($tot_ent > 0 ? $tot_ent : $mensajes_totales_ord) ?></div>
                </td>
                <td style="background-color: #e6fffa;">
                    <strong style="color: #319795;">Gratuitos</strong><br>
                    <div style="font-size: 15px; font-weight: bold; margin-top: 4px;"><?= number_format($tot_gra) ?></div>
                </td>
                <td style="background-color: #fffff0;">
                    <strong style="color: #d69e2e;">Pagados</strong><br>
                    <div style="font-size: 15px; font-weight: bold; margin-top: 4px;"><?= number_format($tot_pag > 0 ? $tot_pag : $mensajes_totales_ord) ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- Tabla de Conceptos -->
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
                        $costo_final_item = floatval($d['_costo_base']) * $factor;
                        if ($costo_meta_total == 0 && $is_first) {
                            $costo_final_item = $subtotal;
                        }
                        $is_first = false;
                        $vol_item = intval($d['volumen']);
                        $grat_item = intval($d['mensajes_gratuitos'] ?? 0);
                        $pagados = intval($d['mensajes_pagados'] ?? 0);
                        if ($pagados <= 0 && $vol_item > 0 && $grat_item === 0) {
                            $pagados = $vol_item;
                        }
                        $divisor = $pagados > 0 ? $pagados : ($vol_item > 0 ? $vol_item : 1);
                        $precio_unit_final = $costo_final_item / $divisor;
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string)$d['nombre_plantilla']) ?></strong>
                            <?php if (!empty($d['categoria'])): ?>
                                <br><small style="color: #718096;">Categoría WhatsApp API: <?= htmlspecialchars((string)$d['categoria']) ?></small>
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
                    <td style="text-align: center;"><?= number_format($mensajes_totales_ord) ?></td>
                    <td style="text-align: center;">0</td>
                    <td style="text-align: center;"><?= number_format($mensajes_totales_ord) ?></td>
                    <td style="text-align: right;">$<?= number_format($mensajes_totales_ord > 0 ? ($subtotal / $mensajes_totales_ord) : $subtotal, 4) ?></td>
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

    <!-- Sección de Registro y Reporte de Pago -->
    <?php if (!empty($o['metodo_pago']) || $estado_orden === 'PAGADO' || $estado_orden === 'EN_REVISION'): ?>
        <div class="<?= $estado_orden === 'PAGADO' ? 'payment-receipt-box' : 'payment-review-box' ?>">
            <strong style="color: <?= $estado_orden === 'PAGADO' ? '#166534' : '#0369a1' ?>; font-size: 12px;">
                <?= $estado_orden === 'PAGADO' ? 'CONSTANCIA DE PAGO REGISTRADO Y VERIFICADO' : 'REPORTE DE PAGO (PENDIENTE POR VERIFICACIÓN MASTER)' ?>
            </strong>
            <table style="width: 100%; margin-top: 6px; border-collapse: collapse;">
                <tr>
                    <td style="width: 65%; vertical-align: top;">
                        <p style="margin: 3px 0;"><strong>Método de Pago:</strong> <?= htmlspecialchars((string)($o['metodo_pago'] ?: 'No especificado')) ?></p>
                        <p style="margin: 3px 0;"><strong>Referencia / TxID:</strong> <?= htmlspecialchars((string)($o['referencia_pago'] ?: 'N/A')) ?></p>
                        <p style="margin: 3px 0;"><strong>Fecha de Pago:</strong> <?= htmlspecialchars(!empty($o['fecha_pago']) ? date('Y-m-d', strtotime((string)$o['fecha_pago'])) : 'N/A') ?></p>
                        <?php if ($notas_pago_ord !== ''): ?>
                            <p style="margin: 3px 0;"><strong>Observaciones / Notas:</strong> <?= nl2br(htmlspecialchars($notas_pago_ord)) ?></p>
                        <?php endif; ?>
                        <?php if ($comprobante_es_pdf): ?>
                            <p style="margin: 3px 0; color: #475569;"><em>* Soporte digital en formato PDF adjunto en el expediente autenticado de la orden.</em></p>
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
        <div class="payment-methods">
            <strong>INSTRUCCIONES Y MÉTODOS DE PAGO:</strong><br>
            <p style="margin: 5px 0 0 0;">Por favor, realice el pago antes de la fecha de vencimiento indicando el número de documento <strong><?= htmlspecialchars($codigo_documento) ?></strong> en el concepto.</p>
            <ul style="margin: 5px 0 0 0; padding-left: 20px;">
                <li><strong>Métodos habilitados:</strong> Binance, Zelle, Pago Móvil, Transferencia Bancaria o Efectivo.</li>
            </ul>
            <p style="margin: 6px 0 0 0; font-style: italic;">Una vez realizado el pago, regístrelo adjuntando el comprobante desde el Panel de Órdenes WABA.</p>
        </div>
    <?php endif; ?>

    <!-- Pie de página institucional: Sello oficial "Generado por Starfi" + isologo_ni.png en extremo inferior derecho -->
    <table class="footer-table">
        <tr>
            <td class="footer-note">
                <?= $estado_orden === 'PAGADO' ? 'Pago conciliado y verificado por administración.' : 'Documento sujeto a recepción y verificación de fondos.' ?>
            </td>
            <td class="footer-seal-cell">
                <span class="seal-text">Generado por Starfi</span>
                <img src="../../docs/identidad_visual/logos/isologo_ni.png" class="seal-isologo" alt="Generado por Starfi">
            </td>
        </tr>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

$autoload_candidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../includes/libs/vendor/autoload.php',
];
foreach ($autoload_candidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        break;
    }
}

if (class_exists('\\Dompdf\\Dompdf') && class_exists('\\Dompdf\\Options') && (($_GET['format'] ?? '') !== 'html')) {
    $pdfHtml = $html;
    if ($cliente_logo_rel_url !== '' && $cliente_logo_base64 !== '') {
        $pdfHtml = str_replace('src="' . htmlspecialchars($cliente_logo_rel_url, ENT_QUOTES, 'UTF-8') . '"', 'src="' . $cliente_logo_base64 . '"', $pdfHtml);
    }
    if ($seal_base64 !== '') {
        $pdfHtml = str_replace('src="../../docs/identidad_visual/logos/isologo_ni.png"', 'src="' . $seal_base64 . '"', $pdfHtml);
    }

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');
    $dompdf = new \Dompdf\Dompdf($options);

    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $prefix_file = ($estado_orden === 'PAGADO') ? "Recibo_Pago_" : "Aviso_Cobro_";
    $dompdf->stream($prefix_file . $codigo_documento . ".pdf", ["Attachment" => false]);
    exit;
}

echo $html;
