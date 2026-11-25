<?php
// imprimir_orden_waba.php
// Motor Oficial de Emisión PDF Binaria (FPDF 1.85) y Vista Imprimible de Órdenes WABA — STARFI CRM (QA-STD-GLOBAL-2.1)
// 100% mysqli_prepare(), identidad corporativa del cliente (superior izquierda) y sello institucional
// "Generado por Starfi" + isologo_ni.png (inferior derecha). Cero exposición de costos base Meta o márgenes internos.

@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');

require_once __DIR__ . '/includes/libs/pdf/fpdf.php';

if (!function_exists('starfi_pdf_txt')) {
    /**
     * Convierte texto UTF-8 a codificación compatible con FPDF (ISO-8859-1 / WinAnsi),
     * preservando el símbolo Euro (€) mediante chr(128).
     */
    function starfi_pdf_txt($utf8): string {
        $str = (string)$utf8;
        if ($str === '') {
            return '';
        }
        $replacements = [
            '€' => '__STARFI_EURO__',
            '—' => '-',
            '–' => '-',
            '•' => '-',
            '“' => '"',
            '”' => '"',
            '‘' => "'",
            '’' => "'",
            '…' => '...',
        ];
        $prepared = strtr($str, $replacements);
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $prepared);
        if ($converted === false) {
            $converted = utf8_decode($prepared);
        }
        return str_replace('__STARFI_EURO__', chr(128), $converted);
    }
}

if (!function_exists('starfi_pdf_ellipsis')) {
    /**
     * Trunca texto largo de forma limpia respetando límites de palabra y añadiendo '...' cuando supera $maxChars.
     */
    function starfi_pdf_ellipsis($text, int $maxChars = 50): string {
        $str = trim((string)$text);
        if ($str === '' || mb_strlen($str, 'UTF-8') <= $maxChars) {
            return $str;
        }
        $cut = mb_substr($str, 0, max(1, $maxChars - 3), 'UTF-8');
        $lastSpace = mb_strrpos($cut, ' ', 0, 'UTF-8');
        if ($lastSpace !== false && $lastSpace > intval($maxChars * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace, 'UTF-8');
        }
        return rtrim($cut, " \t\n\r\0\x0B.,;:-/") . '...';
    }
}

if (!function_exists('starfi_resolve_pdf_embeddable_image')) {
    /**
     * Valida que una ruta de imagen exista bajo la raíz del proyecto y sea PNG/JPEG integrable en FPDF
     * (convirtiendo WEBP temporalmente a JPEG si GD lo soporta).
     */
    function starfi_resolve_pdf_embeddable_image(string $absPath, array &$tempFilesCollector): array {
        if ($absPath === '' || !is_file($absPath)) {
            return ['path' => '', 'type' => ''];
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower(trim((string)@$finfo->file($absPath)));

        if ($mime === 'image/png') {
            return ['path' => $absPath, 'type' => 'PNG'];
        }
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            return ['path' => $absPath, 'type' => 'JPG'];
        }
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp') && function_exists('imagejpeg')) {
            $im = @imagecreatefromwebp($absPath);
            if ($im) {
                $w = imagesx($im);
                $h = imagesy($im);
                $canvas = imagecreatetruecolor(max(1, $w), max(1, $h));
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefilledrectangle($canvas, 0, 0, max(1, $w), max(1, $h), $white);
                imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
                $tmpJpg = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_waba_img_' . bin2hex(random_bytes(6)) . '.jpg';
                if (@imagejpeg($canvas, $tmpJpg, 90)) {
                    imagedestroy($im);
                    imagedestroy($canvas);
                    $tempFilesCollector[] = $tmpJpg;
                    return ['path' => $tmpJpg, 'type' => 'JPG'];
                }
                imagedestroy($im);
                imagedestroy($canvas);
            }
        }
        return ['path' => '', 'type' => ''];
    }
}

if (!class_exists('StarfiOrdenWabaPDF')) {
    class StarfiOrdenWabaPDF extends FPDF {
        public $clientLogoPath = '';
        public $clientLogoType = '';
        public $clientCommercialName = '';
        public $clientLegalName = '';
        public $clientRif = '';
        public $clientAddress = '';
        public $sedeLabel = '';
        public $docTitle = '';
        public $docCode = '';
        public $fechaEmision = '';
        public $fechaVencimiento = '';
        public $estadoTexto = '';
        public $estadoRgb = [214, 158, 46];
        public $footerNote = '';
        public $sealLogoPath = '';

        public function Header() {
            $leftX = 12;
            $topY  = 10;
            $infoStartY = $topY;

            // Encabezado Superior Izquierdo: Logo corporativo del cliente (X = 12, Y = 10, alto = 16)
            if ($this->clientLogoPath !== '' && is_file($this->clientLogoPath)) {
                $this->Image($this->clientLogoPath, 12, 10, 0, 16, $this->clientLogoType);
                $infoStartY = 27.5;
            } else {
                $badgeTxt = starfi_pdf_txt(mb_substr($this->clientCommercialName ?: 'EMPRESA CLIENTE', 0, 38, 'UTF-8'));
                $this->SetFont('helvetica', 'B', 10);
                $this->SetFillColor(30, 41, 59);
                $this->SetDrawColor(232, 91, 20);
                $this->SetLineWidth(0.6);
                $badgeW = min(98, max(48, $this->GetStringWidth($badgeTxt) + 12));
                $this->Rect(12, 10, $badgeW, 10, 'F');
                $this->SetFillColor(232, 91, 20);
                $this->Rect(12, 10, 2.2, 10, 'F');
                $this->SetXY(16, 12);
                $this->SetTextColor(255, 255, 255);
                $this->Cell($badgeW - 6, 6, $badgeTxt, 0, 1, 'L');
                $infoStartY = 22;
            }

            // Razón social, RIF y Sede debajo del logo del cliente
            $this->SetXY($leftX, $infoStartY);
            $this->SetFont('helvetica', 'B', 9.2);
            $this->SetTextColor(30, 41, 59);
            $this->Cell(102, 4.5, starfi_pdf_txt(mb_substr($this->clientLegalName, 0, 56, 'UTF-8')), 0, 1, 'L');

            $this->SetX($leftX);
            $this->SetFont('helvetica', '', 8.2);
            $this->SetTextColor(71, 85, 105);
            $this->Cell(102, 4.2, starfi_pdf_txt('RIF / ID Fiscal: ' . $this->clientRif . '  |  Sede: ' . $this->sedeLabel), 0, 1, 'L');

            if ($this->clientAddress !== '') {
                $this->SetX($leftX);
                $this->SetFont('helvetica', '', 7.6);
                $this->Cell(102, 3.8, starfi_pdf_txt(mb_substr($this->clientAddress, 0, 72, 'UTF-8')), 0, 1, 'L');
            }

            $leftEndY = $this->GetY();

            // Encabezado Superior Derecho: Insignia del Documento (RECIBO DE PAGO / AVISO DE COBRO)
            $rightBoxW = 78;
            $rightBoxX = $this->GetPageWidth() - 12 - $rightBoxW;
            $this->SetFillColor(248, 250, 252);
            $this->SetDrawColor(203, 213, 225);
            $this->SetLineWidth(0.25);
            $this->Rect($rightBoxX, 10, $rightBoxW, 27, 'DF');

            $this->SetXY($rightBoxX + 3, 11.5);
            $this->SetFont('helvetica', 'B', 11.5);
            $this->SetTextColor(26, 54, 93);
            $this->Cell($rightBoxW - 6, 5.5, starfi_pdf_txt(strtoupper($this->docTitle)), 0, 1, 'R');

            $this->SetX($rightBoxX + 3);
            $this->SetFont('helvetica', 'B', 9);
            $this->SetTextColor(55, 65, 74);
            $this->Cell($rightBoxW - 6, 4.5, starfi_pdf_txt('Nro. Documento: ' . $this->docCode), 0, 1, 'R');

            $this->SetX($rightBoxX + 3);
            $this->SetFont('helvetica', '', 8);
            $this->SetTextColor(71, 85, 105);
            $this->Cell($rightBoxW - 6, 4, starfi_pdf_txt('Emisión: ' . $this->fechaEmision . '  |  Vence: ' . $this->fechaVencimiento), 0, 1, 'R');

            $this->SetX($rightBoxX + 3);
            $this->SetFont('helvetica', 'B', 8.2);
            $this->SetTextColor($this->estadoRgb[0], $this->estadoRgb[1], $this->estadoRgb[2]);
            $this->Cell($rightBoxW - 6, 4.5, starfi_pdf_txt('Estado: ' . $this->estadoTexto), 0, 1, 'R');

            $dividerY = max($leftEndY + 2.5, 40.0);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.35);
            $this->Line(12, $dividerY, $this->GetPageWidth() - 12, $dividerY);
            $this->SetY($dividerY + 4);
        }

        public function Footer() {
            $pageW = $this->GetPageWidth();
            $pageH = $this->GetPageHeight();
            $lineY = $pageH - 18.5;

            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.25);
            $this->Line(12, $lineY, $pageW - 12, $lineY);

            // Nota legal a la izquierda
            $this->SetXY(12, $pageH - 14.5);
            $this->SetFont('helvetica', '', 7.6);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(92, 5, starfi_pdf_txt($this->footerNote), 0, 0, 'L');

            // Numeración central
            $this->SetXY(104, $pageH - 14.5);
            $this->SetFont('helvetica', '', 7.6);
            $this->Cell(34, 5, starfi_pdf_txt('Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'C');

            // Sello institucional oficial en esquina inferior derecha: "Generado por Starfi" + isologo_ni.png
            $sealX = $this->GetPageWidth() - 25;
            $sealY = $this->GetPageHeight() - 16;
            $sealTxt = starfi_pdf_txt('Generado por Starfi');

            $this->SetFont('helvetica', 'B', 8.2);
            $this->SetTextColor(55, 65, 74);
            $sealTxtWidth = $this->GetStringWidth($sealTxt);
            $this->SetXY($sealX - $sealTxtWidth - 2.5, $sealY + 3.0);
            $this->Cell($sealTxtWidth, 5, $sealTxt, 0, 0, 'R');

            if ($this->sealLogoPath !== '' && is_file($this->sealLogoPath)) {
                $this->Image($this->sealLogoPath, $sealX, $sealY, 11);
            }
        }
    }
}

if (!function_exists('starfi_prepare_waba_order_view_model')) {
    /**
     * Consolida y normaliza todos los datos comerciales, fiscales y de líneas de cobro
     * ocultando el costo base Meta y el margen administrativo (integrados en el precio unitario final).
     */
    function starfi_prepare_waba_order_view_model(array $o, array $sede, array $emp_base, array $perfil_emp, array $detalles): array {
        $id_orden             = intval($o['id'] ?? 0);
        $id_sede_orden        = intval($o['id_sede'] ?? 0);
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
            if (strtoupper(trim((string)($d['categoria'] ?? ''))) === 'ADMIN') {
                $margen_total += $cBaseD;
            } else {
                $costo_meta_total += $cBaseD;
                $detalles_validos[] = $d;
                $tot_ent += intval($d['volumen'] ?? 0);
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

        $factor   = ($costo_meta_total > 0) ? (($costo_meta_total + $margen_total) / $costo_meta_total) : 1.0;
        $subtotal = $monto_total_ord;
        $impuesto = 0.0;
        $total    = $monto_total_ord;

        $items_pagados   = [];
        $items_gratuitos = [];

        if (!empty($detalles_validos)) {
            $is_first = true;
            foreach ($detalles_validos as $d) {
                $costo_final_item = floatval($d['_costo_base']) * $factor;
                if ($costo_meta_total == 0 && $is_first) {
                    $costo_final_item = $subtotal;
                }
                $is_first = false;

                $vol_item  = intval($d['volumen'] ?? 0);
                $grat_item = intval($d['mensajes_gratuitos'] ?? 0);
                $pagados   = intval($d['mensajes_pagados'] ?? 0);
                if ($pagados <= 0 && $vol_item > 0 && $grat_item === 0) {
                    $pagados = $vol_item;
                }

                $nomConcepto = trim((string)($d['nombre_plantilla'] ?? 'Servicio de Mensajería WhatsApp API'));
                $catConcepto = strtoupper(trim((string)($d['categoria'] ?? 'MARKETING')));

                if ($pagados > 0 || $costo_final_item > 0) {
                    $divUnit = $pagados > 0 ? $pagados : max(1, $vol_item);
                    $items_pagados[] = [
                        'concepto'    => $nomConcepto,
                        'categoria'   => $catConcepto,
                        'volumen'     => $pagados > 0 ? $pagados : $vol_item,
                        'gratuitos'   => 0,
                        'pagados'     => $pagados > 0 ? $pagados : $vol_item,
                        'precio_unit' => $costo_final_item / $divUnit,
                        'total_usd'   => $costo_final_item,
                    ];
                }

                if ($grat_item > 0) {
                    $items_gratuitos[] = [
                        'concepto'    => $nomConcepto . ' (Ventana Gratuita 24h / Servicio)',
                        'categoria'   => $catConcepto,
                        'volumen'     => $grat_item,
                        'gratuitos'   => $grat_item,
                        'pagados'     => 0,
                        'precio_unit' => 0.0,
                        'total_usd'   => 0.0,
                    ];
                }
            }
        }

        if (empty($items_pagados) && empty($items_gratuitos)) {
            $divConsolidado = $mensajes_totales_ord > 0 ? $mensajes_totales_ord : 1;
            $items_pagados[] = [
                'concepto'    => 'Servicios de Mensajería Consolidada WhatsApp API',
                'categoria'   => 'CONSOLIDADO',
                'volumen'     => $mensajes_totales_ord,
                'gratuitos'   => 0,
                'pagados'     => $mensajes_totales_ord,
                'precio_unit' => $subtotal / $divConsolidado,
                'total_usd'   => $subtotal,
            ];
        }

        $fecha_emision_raw = $o['fecha_emision'] ?? ($o['created_at'] ?? date('Y-m-d'));
        $fecha_emision     = date('Y-m-d', strtotime((string)$fecha_emision_raw));
        $fecha_vencimiento = !empty($o['fecha_vencimiento'])
            ? date('Y-m-d', strtotime((string)$o['fecha_vencimiento']))
            : date('Y-m-d', strtotime($fecha_emision . ' + 5 days'));
        $codigo_documento  = !empty($o['codigo_orden'])
            ? (string)$o['codigo_orden']
            : ('NC-' . date('Y', strtotime($fecha_emision)) . '-' . str_pad((string)$id_orden, 4, '0', STR_PAD_LEFT));

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

        $root_real = realpath(__DIR__);
        $cliente_logo_rel_raw = trim((string)($perfil_emp['logo_ruta'] ?? ($perfil_emp['logo_url'] ?? ($perfil_emp['logo_empresa'] ?? ''))));
        $cliente_logo_rel_url = '';
        $cliente_logo_abs     = '';

        if ($cliente_logo_rel_raw !== '' && $root_real) {
            $cleanRel = ltrim(str_replace('\\', '/', $cliente_logo_rel_raw), './');
            if (stripos($cleanRel, 'identidad_visual/logos/') === false) {
                $candidates = [
                    $cleanRel,
                    'uploads/empresa/' . basename($cleanRel),
                    'assets/uploads/empresa/' . basename($cleanRel),
                ];
                foreach ($candidates as $candRel) {
                    $candAbs = realpath($root_real . '/' . $candRel);
                    if ($candAbs && strpos($candAbs, $root_real) === 0 && is_file($candAbs)) {
                        $cliente_logo_abs     = $candAbs;
                        $cliente_logo_rel_url = $candRel;
                        break;
                    }
                }
            }
        }

        $seal_rel_url = 'docs/identidad_visual/logos/isologo_ni.png';
        $seal_abs     = realpath(__DIR__ . '/' . $seal_rel_url) ?: '';

        $comprobante_abs    = '';
        $comprobante_es_pdf = false;
        if ($comprobante_rel !== '' && $root_real) {
            $compClean = ltrim(str_replace('\\', '/', $comprobante_rel), './');
            $compFull  = realpath($root_real . '/' . $compClean);
            if ($compFull && strpos($compFull, $root_real) === 0 && is_file($compFull)) {
                $finfoComp = new finfo(FILEINFO_MIME_TYPE);
                $compMime  = strtolower(trim((string)@$finfoComp->file($compFull)));
                if (in_array($compMime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
                    $comprobante_abs = $compFull;
                } elseif ($compMime === 'application/pdf') {
                    $comprobante_es_pdf = true;
                }
            }
        }

        $estado_orden = strtoupper(trim((string)($o['estado'] ?? 'PENDIENTE')));
        $titulo_doc   = ($estado_orden === 'PAGADO') ? 'Recibo de Pago' : 'Aviso de Cobro';
        $color_estado = '#d69e2e';
        $estado_rgb   = [214, 158, 46];
        $texto_estado = $estado_orden;

        if ($estado_orden === 'PAGADO') {
            $color_estado = '#16a34a';
            $estado_rgb   = [22, 163, 74];
            $texto_estado = 'PAGADO (VERIFICADO)';
        } elseif ($estado_orden === 'EN_REVISION') {
            $color_estado = '#0284c7';
            $estado_rgb   = [2, 132, 199];
            $texto_estado = 'PAGADA (PEND. REVISIÓN)';
        } elseif ($estado_orden === 'PENDIENTE') {
            $color_estado = '#c53030';
            $estado_rgb   = [197, 48, 48];
            $texto_estado = 'PENDIENTE DE PAGO';
        }

        $nombre_sede_lbl = (string)($sede['nombre_sede'] ?? ('Sede #' . $id_sede_orden));
        $atencion_lbl    = trim((string)($sede['gerente_nombre'] ?? '')) ?: 'Administración';
        $correo_sede_lbl = trim((string)($sede['gerente_email'] ?? ($sede['correo_notificaciones'] ?? ($sede['email'] ?? '')))) ?: ($cliente_correo ?: 'No registrado');

        return [
            'id_orden'                 => $id_orden,
            'id_sede_orden'            => $id_sede_orden,
            'codigo_documento'         => $codigo_documento,
            'titulo_doc'               => $titulo_doc,
            'estado_orden'             => $estado_orden,
            'texto_estado'             => $texto_estado,
            'color_estado'             => $color_estado,
            'estado_rgb'               => $estado_rgb,
            'fecha_emision'            => $fecha_emision,
            'fecha_vencimiento'        => $fecha_vencimiento,
            'fecha_desde'              => (string)($o['fecha_desde'] ?? ''),
            'fecha_hasta'              => (string)($o['fecha_hasta'] ?? ''),
            'mensajes_totales_ord'     => $mensajes_totales_ord,
            'tot_entregados'           => ($tot_ent > 0 ? $tot_ent : $mensajes_totales_ord),
            'tot_gratuitos'            => $tot_gra,
            'tot_pagados'              => ($tot_pag > 0 ? $tot_pag : max(0, $mensajes_totales_ord - $tot_gra)),
            'items_pagados'            => $items_pagados,
            'items_gratuitos'          => $items_gratuitos,
            'subtotal'                 => $subtotal,
            'impuesto'                 => $impuesto,
            'total'                    => $total,
            'metodo_pago'              => trim((string)($o['metodo_pago'] ?? '')),
            'referencia_pago'          => trim((string)($o['referencia_pago'] ?? '')),
            'fecha_pago'               => !empty($o['fecha_pago']) ? date('Y-m-d', strtotime((string)$o['fecha_pago'])) : 'N/A',
            'notas_pago'               => $notas_pago_ord,
            'comprobante_abs'          => $comprobante_abs,
            'comprobante_es_pdf'       => $comprobante_es_pdf,
            'comprobante_rel'          => $comprobante_rel,
            'cliente_nombre_comercial' => $cliente_nombre_comercial,
            'cliente_razon_social'     => $cliente_razon_social,
            'cliente_rif'              => $cliente_rif,
            'cliente_direccion'        => $cliente_direccion,
            'nombre_sede_lbl'          => $nombre_sede_lbl,
            'atencion_lbl'             => $atencion_lbl,
            'correo_sede_lbl'          => $correo_sede_lbl,
            'cliente_logo_abs'         => $cliente_logo_abs,
            'cliente_logo_rel_url'     => $cliente_logo_rel_url,
            'seal_abs'                 => $seal_abs,
            'seal_rel_url'             => $seal_rel_url,
            'footer_note'              => ($estado_orden === 'PAGADO')
                ? 'Pago conciliado y verificado por administración.'
                : 'Documento sujeto a recepción y verificación de fondos.',
        ];
    }
}

if (!function_exists('starfi_create_waba_order_fpdf')) {
    /**
     * Construye y maqueta la instancia FPDF completa lista para Output('I') u Output('S').
     */
    function starfi_create_waba_order_fpdf(array $vm, array &$tempFilesCollector): StarfiOrdenWabaPDF {
        $clientLogoResolved = starfi_resolve_pdf_embeddable_image($vm['cliente_logo_abs'], $tempFilesCollector);
        $compImgResolved    = starfi_resolve_pdf_embeddable_image($vm['comprobante_abs'], $tempFilesCollector);

        $pdf = new StarfiOrdenWabaPDF('P', 'mm', 'A4');
        $pdf->clientLogoPath       = $clientLogoResolved['path'];
        $pdf->clientLogoType       = $clientLogoResolved['type'];
        $pdf->clientCommercialName = $vm['cliente_nombre_comercial'];
        $pdf->clientLegalName      = $vm['cliente_razon_social'];
        $pdf->clientRif            = $vm['cliente_rif'];
        $pdf->clientAddress        = $vm['cliente_direccion'];
        $pdf->sedeLabel            = $vm['nombre_sede_lbl'];
        $pdf->docTitle             = $vm['titulo_doc'];
        $pdf->docCode              = $vm['codigo_documento'];
        $pdf->fechaEmision         = $vm['fecha_emision'];
        $pdf->fechaVencimiento     = $vm['fecha_vencimiento'];
        $pdf->estadoTexto          = $vm['texto_estado'];
        $pdf->estadoRgb            = $vm['estado_rgb'];
        $pdf->footerNote           = $vm['footer_note'];
        $pdf->sealLogoPath         = $vm['seal_abs'];

        $pdf->AliasNbPages();
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetCreator('STARFI CRM', true);
        $pdf->SetAuthor('STARFI CRM', true);
        // ORDEN CRÍTICO FPDF (CHK-UIX-03): SetTitle() estrictamente ANTES de SetAutoPageBreak()
        $pdf->SetTitle($vm['titulo_doc'] . ' ' . $vm['codigo_documento'], true);
        $pdf->SetAutoPageBreak(true, 22);

        $pdf->AddPage();

        // 1. Bloques informativos en dos columnas (Datos Sede/Cliente vs Periodo Facturado)
        $boxY = $pdf->GetY();
        $colW = 91;
        $boxH = 26;

        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->SetLineWidth(0.25);
        $pdf->Rect(12, $boxY, $colW, $boxH, 'DF');

        $pdf->SetFillColor(235, 248, 255);
        $pdf->SetDrawColor(190, 227, 248);
        $pdf->Rect(12 + $colW + 4, $boxY, $colW, $boxH, 'DF');

        // Columna Izquierda
        $pdf->SetXY(15, $boxY + 2.5);
        $pdf->SetFont('helvetica', 'B', 8.2);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell($colW - 6, 4.5, starfi_pdf_txt('DATOS DE LA SEDE / CLIENTE'), 0, 1, 'L');

        $pdf->SetX(15);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(51, 65, 85);
        $pdf->Cell($colW - 6, 4.2, starfi_pdf_txt('Sede: ' . mb_substr($vm['nombre_sede_lbl'], 0, 42, 'UTF-8')), 0, 1, 'L');
        $pdf->SetX(15);
        $pdf->Cell($colW - 6, 4.2, starfi_pdf_txt('RIF / ID: ' . $vm['cliente_rif']), 0, 1, 'L');
        $pdf->SetX(15);
        $pdf->Cell($colW - 6, 4.2, starfi_pdf_txt('Atención a: ' . mb_substr($vm['atencion_lbl'], 0, 38, 'UTF-8')), 0, 1, 'L');
        $pdf->SetX(15);
        $pdf->Cell($colW - 6, 4.2, starfi_pdf_txt('Correo: ' . mb_substr($vm['correo_sede_lbl'], 0, 42, 'UTF-8')), 0, 1, 'L');

        // Columna Derecha
        $rightColX = 12 + $colW + 4 + 3;
        $pdf->SetXY($rightColX, $boxY + 2.5);
        $pdf->SetFont('helvetica', 'B', 8.2);
        $pdf->SetTextColor(30, 58, 138);
        $pdf->Cell($colW - 6, 4.5, starfi_pdf_txt('DETALLE DEL PERIODO FACTURADO'), 0, 1, 'L');

        $pdf->SetX($rightColX);
        $pdf->SetFont('helvetica', 'B', 9.2);
        $pdf->SetTextColor(29, 78, 216);
        $pdf->Cell($colW - 6, 5.5, starfi_pdf_txt('Del ' . $vm['fecha_desde'] . ' al ' . $vm['fecha_hasta']), 0, 1, 'L');

        $pdf->SetX($rightColX);
        $pdf->SetFont('helvetica', '', 7.6);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell($colW - 6, 3.8, starfi_pdf_txt('Liquidación consolidada por consumo de mensajería y servicios operativos de infraestructura WhatsApp Business API.'), 0, 'L');

        $pdf->SetY($boxY + $boxH + 5);

        // 2. Franja de Estadísticas del Periodo (4 tarjetas)
        $statY = $pdf->GetY();
        $statW = 44.5;
        $statGap = 2.66;
        $statsCards = [
            ['label' => 'Mensajes Totales', 'val' => number_format($vm['mensajes_totales_ord']), 'rgb' => [37, 99, 235]],
            ['label' => 'Entregados',       'val' => number_format($vm['tot_entregados']),       'rgb' => [22, 163, 74]],
            ['label' => 'Gratuitos (24h)',  'val' => number_format($vm['tot_gratuitos']),        'rgb' => [13, 148, 136]],
            ['label' => 'Facturables',      'val' => number_format($vm['tot_pagados']),          'rgb' => [217, 119, 6]],
        ];

        foreach ($statsCards as $idx => $sc) {
            $sx = 12 + ($idx * ($statW + $statGap));
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->Rect($sx, $statY, $statW, 14, 'DF');

            $pdf->SetXY($sx + 2, $statY + 2);
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetTextColor($sc['rgb'][0], $sc['rgb'][1], $sc['rgb'][2]);
            $pdf->Cell($statW - 4, 4, starfi_pdf_txt($sc['label']), 0, 1, 'C');

            $pdf->SetXY($sx + 2, $statY + 6.8);
            $pdf->SetFont('helvetica', 'B', 10.5);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell($statW - 4, 5, starfi_pdf_txt($sc['val']), 0, 0, 'C');
        }

        $pdf->SetY($statY + 18);

        // 3. Tabla de Conceptos Facturables vs Mensajes Gratuitos
        $wDesc = 86;
        $wCat  = 26;
        $wCant = 22;
        $wUnit = 26;
        $wSub  = 26;

        $renderTableHeader = static function (StarfiOrdenWabaPDF $p, string $sectionTitle, array $bgRgb) use ($wDesc, $wCat, $wCant, $wUnit, $wSub): void {
            $p->SetFont('helvetica', 'B', 8.5);
            $p->SetTextColor(55, 65, 74);
            $p->Cell(186, 5.5, starfi_pdf_txt($sectionTitle), 0, 1, 'L');

            $p->SetFillColor($bgRgb[0], $bgRgb[1], $bgRgb[2]);
            $p->SetTextColor(255, 255, 255);
            $p->SetDrawColor($bgRgb[0], $bgRgb[1], $bgRgb[2]);
            $p->SetFont('helvetica', 'B', 8);
            $p->Cell($wDesc, 6.5, starfi_pdf_txt('Concepto / Servicio'), 1, 0, 'L', true);
            $p->Cell($wCat,  6.5, starfi_pdf_txt('Categoría'), 1, 0, 'C', true);
            $p->Cell($wCant, 6.5, starfi_pdf_txt('Cantidad'), 1, 0, 'C', true);
            $p->Cell($wUnit, 6.5, starfi_pdf_txt('Precio Unit.'), 1, 0, 'R', true);
            $p->Cell($wSub,  6.5, starfi_pdf_txt('Total (USD)'), 1, 1, 'R', true);
        };

        $renderTableHeader($pdf, 'A. MENSAJES FACTURABLES (CON CARGO OPERATIVO INTEGRADO)', [55, 65, 74]);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->SetDrawColor(226, 232, 240);

        if (!empty($vm['items_pagados'])) {
            foreach ($vm['items_pagados'] as $idx => $it) {
                $fill = ($idx % 2 === 1);
                $pdf->SetFillColor(248, 250, 252);
                $pdf->Cell($wDesc, 6.2, starfi_pdf_txt(starfi_pdf_ellipsis($it['concepto'], 50)), 'LRB', 0, 'L', $fill);
                $pdf->Cell($wCat,  6.2, starfi_pdf_txt($it['categoria']), 'RB', 0, 'C', $fill);
                $pdf->Cell($wCant, 6.2, number_format(intval($it['pagados'])), 'RB', 0, 'C', $fill);
                $pdf->Cell($wUnit, 6.2, '$' . number_format(floatval($it['precio_unit']), 4), 'RB', 0, 'R', $fill);
                $pdf->SetFont('helvetica', 'B', 8);
                $pdf->Cell($wSub,  6.2, '$' . number_format(floatval($it['total_usd']), 2), 'RB', 1, 'R', $fill);
                $pdf->SetFont('helvetica', '', 8);
            }
        } else {
            $pdf->Cell(186, 6.2, starfi_pdf_txt('Sin mensajes con cargo en este periodo.'), 'LRB', 1, 'C');
        }

        if (!empty($vm['items_gratuitos'])) {
            $pdf->Ln(3);
            $renderTableHeader($pdf, 'B. MENSAJES GRATUITOS (VENTANA DE ATENCIÓN 24H — SIN CARGO)', [15, 118, 110]);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(51, 65, 85);
            $pdf->SetDrawColor(226, 232, 240);

            foreach ($vm['items_gratuitos'] as $idx => $it) {
                $fill = ($idx % 2 === 1);
                $pdf->SetFillColor(240, 253, 250);
                $pdf->Cell($wDesc, 6.0, starfi_pdf_txt(starfi_pdf_ellipsis($it['concepto'], 50)), 'LRB', 0, 'L', $fill);
                $pdf->Cell($wCat,  6.0, starfi_pdf_txt($it['categoria']), 'RB', 0, 'C', $fill);
                $pdf->Cell($wCant, 6.0, number_format(intval($it['gratuitos'])), 'RB', 0, 'C', $fill);
                $pdf->Cell($wUnit, 6.0, '$0.0000', 'RB', 0, 'R', $fill);
                $pdf->Cell($wSub,  6.0, '$0.00', 'RB', 1, 'R', $fill);
            }
        }

        // 4. Cuadro de Totales Finales
        $pdf->Ln(4);
        $totX = $pdf->GetPageWidth() - 12 - 78;
        $pdf->SetX($totX);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Cell(46, 5.5, starfi_pdf_txt('Subtotal Servicios WABA:'), 'LTB', 0, 'L');
        $pdf->Cell(32, 5.5, '$' . number_format($vm['subtotal'], 2), 'RTB', 1, 'R');

        $pdf->SetX($totX);
        $pdf->Cell(46, 5.5, starfi_pdf_txt('Impuestos Aplicables (0%):'), 'LB', 0, 'L');
        $pdf->Cell(32, 5.5, '$' . number_format($vm['impuesto'], 2), 'RB', 1, 'R');

        $pdf->SetX($totX);
        $pdf->SetFillColor(237, 242, 247);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(46, 7.5, starfi_pdf_txt('TOTAL FINAL (USD):'), 1, 0, 'L', true);
        $pdf->SetTextColor(232, 91, 20);
        $pdf->Cell(32, 7.5, '$' . number_format($vm['total'], 2) . ' USD', 1, 1, 'R', true);

        // 5. Bloque de Constancia de Pago / Comprobante o Instrucciones de Pago
        $pdf->Ln(5);
        $hasPaymentInfo = ($vm['metodo_pago'] !== '' || $vm['estado_orden'] === 'PAGADO' || $vm['estado_orden'] === 'EN_REVISION');

        if ($hasPaymentInfo) {
            $neededH = ($compImgResolved['path'] !== '') ? 42 : 28;
            if ($pdf->GetY() + $neededH > ($pdf->GetPageHeight() - 24)) {
                $pdf->AddPage();
            }

            $payY = $pdf->GetY();
            if ($vm['estado_orden'] === 'PAGADO') {
                $pdf->SetFillColor(240, 253, 244);
                $pdf->SetDrawColor(22, 163, 74);
                $hdrRgb = [22, 101, 52];
                $hdrTitle = 'CONSTANCIA DE PAGO REGISTRADO Y VERIFICADO';
            } else {
                $pdf->SetFillColor(240, 249, 255);
                $pdf->SetDrawColor(2, 132, 199);
                $hdrRgb = [3, 105, 161];
                $hdrTitle = 'REPORTE DE PAGO (PENDIENTE POR VERIFICACIÓN MASTER)';
            }

            $boxPayH = ($compImgResolved['path'] !== '') ? 40 : 28;
            $pdf->Rect(12, $payY, 186, $boxPayH, 'DF');

            $pdf->SetXY(15, $payY + 2.5);
            $pdf->SetFont('helvetica', 'B', 8.5);
            $pdf->SetTextColor($hdrRgb[0], $hdrRgb[1], $hdrRgb[2]);
            $pdf->Cell(125, 4.5, starfi_pdf_txt($hdrTitle), 0, 1, 'L');

            $pdf->SetX(15);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(125, 4.3, starfi_pdf_txt('Método de Pago: ' . ($vm['metodo_pago'] ?: 'No especificado') . '   |   Fecha: ' . $vm['fecha_pago']), 0, 1, 'L');

            $pdf->SetX(15);
            $pdf->Cell(125, 4.3, starfi_pdf_txt('Referencia / TxID: ' . ($vm['referencia_pago'] ?: 'N/A')), 0, 1, 'L');

            if ($vm['notas_pago'] !== '') {
                $notasOneLine = preg_replace('/\s+/u', ' ', trim($vm['notas_pago']));
                $pdf->SetX(15);
                $pdf->SetFont('helvetica', '', 7.5);
                $pdf->SetTextColor(71, 85, 105);
                $pdf->MultiCell(125, 3.8, starfi_pdf_txt('Observaciones: ' . mb_substr((string)$notasOneLine, 0, 150, 'UTF-8')), 0, 'L');
            } elseif ($vm['comprobante_es_pdf']) {
                $pdf->SetX(15);
                $pdf->SetFont('helvetica', 'I', 7.5);
                $pdf->SetTextColor(71, 85, 105);
                $pdf->Cell(125, 4.0, starfi_pdf_txt('* Soporte digital en formato PDF adjunto en el expediente autenticado.'), 0, 1, 'L');
            }

            if ($compImgResolved['path'] !== '' && is_file($compImgResolved['path'])) {
                $pdf->Image($compImgResolved['path'], 156, $payY + 4, 0, 32, $compImgResolved['type']);
            }
        } else {
            if ($pdf->GetY() + 26 > ($pdf->GetPageHeight() - 24)) {
                $pdf->AddPage();
            }
            $instY = $pdf->GetY();
            $pdf->SetFillColor(241, 245, 249);
            $pdf->SetDrawColor(55, 65, 74);
            $pdf->Rect(12, $instY, 186, 22, 'DF');

            $pdf->SetXY(15, $instY + 2.5);
            $pdf->SetFont('helvetica', 'B', 8.3);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(180, 4.5, starfi_pdf_txt('INSTRUCCIONES Y MÉTODOS DE PAGO'), 0, 1, 'L');

            $pdf->SetX(15);
            $pdf->SetFont('helvetica', '', 7.8);
            $pdf->SetTextColor(51, 65, 85);
            $pdf->MultiCell(
                180,
                4.0,
                starfi_pdf_txt(
                    'Realice el pago antes del vencimiento indicando el documento ' . $vm['codigo_documento'] .
                    ". Métodos habilitados: Binance, Zelle, Pago Móvil, Transferencia Bancaria o Efectivo.\n" .
                    'Una vez efectuado el pago, regístrelo adjuntando su comprobante en el módulo de Órdenes WABA.'
                ),
                0,
                'L'
            );
        }

        return $pdf;
    }
}

if (!function_exists('starfi_build_waba_order_pdf_binary')) {
    /**
     * Genera y devuelve el binario PDF (%PDF-1.x) en memoria para una orden de cobro WABA.
     */
    function starfi_build_waba_order_pdf_binary($con, array $o, array $sede, array $emp_base, array $perfil_emp, array $detalles): string {
        $tempFiles = [];
        try {
            $vm  = starfi_prepare_waba_order_view_model($o, $sede, $emp_base, $perfil_emp, $detalles);
            $pdf = starfi_create_waba_order_fpdf($vm, $tempFiles);
            return (string)$pdf->Output('S');
        } finally {
            foreach ($tempFiles as $tf) {
                if ($tf !== '' && is_file($tf)) {
                    @unlink($tf);
                }
            }
        }
    }
}

// Modo librería para pruebas unitarias / QA sin despacho HTTP inmediato
if (defined('STARFI_PDF_LIB_MODE') && STARFI_PDF_LIB_MODE === true) {
    return;
}

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/config/database.php';

requireAuth();
requirePermission('waba_ordenes');

$agente = getAgenteInfo();
$es_master = (intval($agente['id'] ?? 0) === 1 || strtoupper(trim((string)($agente['rol'] ?? ''))) === 'MASTER');
$id_sede_agente = intval($agente['id_sede'] ?? 0);

session_write_close();

$id_orden = intval($_GET['id'] ?? 0);
if ($id_orden <= 0) {
    http_response_code(400);
    exit('ID de Orden no válido.');
}

$con = getDbConnection();
if (!$con) {
    http_response_code(500);
    exit('Error de conexión a la base de datos.');
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
    http_response_code(404);
    exit('Orden no encontrada.');
}

$id_sede_orden = intval($o['id_sede'] ?? 0);
if (!$es_master && ($id_sede_agente <= 0 || $id_sede_orden !== $id_sede_agente)) {
    http_response_code(403);
    exit('403 Forbidden');
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
$stmtEmp = @$con->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
if ($stmtEmp) {
    $stmtEmp->bind_param("i", $id_empresa_orden);
    if ($stmtEmp->execute()) {
        $resEmp = $stmtEmp->get_result();
        $emp_base = ($resEmp && $resEmp->num_rows > 0) ? $resEmp->fetch_assoc() : [];
    }
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

$vm = starfi_prepare_waba_order_view_model($o, $sede, $emp_base, $perfil_emp, $detalles);

// 5. Salida binaria PDF por defecto vía FPDF 1.85
if (($_GET['format'] ?? '') !== 'html') {
    $tempFiles = [];
    try {
        $pdf = starfi_create_waba_order_fpdf($vm, $tempFiles);
        $prefix_file = ($vm['estado_orden'] === 'PAGADO') ? 'Recibo_Pago_' : 'Aviso_Cobro_';
        $pdf->Output('I', $prefix_file . $vm['codigo_documento'] . '.pdf');
    } finally {
        foreach ($tempFiles as $tf) {
            if ($tf !== '' && is_file($tf)) {
                @unlink($tf);
            }
        }
    }
    exit;
}

// 6. Modo vista imprimible HTML (?format=html) con tipografía local Gotham 100% libre de CDN
$comprobante_secure_url = '';
if ($vm['comprobante_rel'] !== '') {
    $baseComp = basename(str_replace('\\', '/', $vm['comprobante_rel']));
    if ($baseComp !== '' && $baseComp !== '.' && $baseComp !== '..') {
        $comprobante_secure_url = 'ver_documento.php?tipo=comprobante_waba&file=' . rawurlencode($baseComp);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($vm['titulo_doc'] . ' ' . $vm['codigo_documento'], ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <style>
        @font-face {
            font-family: 'Gotham';
            src: url('assets/fonts/GothamMedium.ttf') format('truetype');
            font-weight: 400 700;
            font-style: normal;
            font-display: swap;
        }
        body {
            font-family: 'Gotham', sans-serif;
            font-size: 12.5px;
            color: #1e293b;
            margin: 0;
            padding: 22px;
            background-color: #ffffff;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #e2e8f0;
        }
        .header-table td {
            vertical-align: top;
        }
        .logo {
            max-width: 160px;
            max-height: 64px;
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
            font-weight: 700;
            margin-bottom: 6px;
        }
        .title {
            font-size: 19px;
            font-weight: 700;
            color: #1a365d;
            text-align: right;
            text-transform: uppercase;
        }
        .two-column {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .two-column td {
            width: 50%;
            vertical-align: top;
        }
        .info-box {
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 12px;
            min-height: 92px;
        }
        .badge-periodo {
            display: inline-block;
            background-color: #dbeafe;
            color: #1d4ed8;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 700;
        }
        .stats-table {
            width: 100%;
            margin: 8px 0 16px 0;
            border-collapse: separate;
            border-spacing: 8px 0;
            text-align: center;
        }
        .stats-table td {
            width: 25%;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        .section-caption {
            font-size: 11.5px;
            font-weight: 700;
            color: #37414A;
            margin: 10px 0 5px 0;
            text-transform: uppercase;
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
        .items-table.free-items th {
            background-color: #0f766e;
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
            font-weight: 700;
            font-size: 14.5px;
            background-color: #edf2f7;
            border-top: 2px solid #cbd5e1;
        }
        .payment-methods {
            background-color: #f1f5f9;
            border-left: 4px solid #37414A;
            padding: 10px 12px;
            margin-top: 12px;
            font-size: 11.5px;
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
            font-size: 10.5px;
            color: #64748b;
            vertical-align: middle;
        }
        .footer-seal-cell {
            text-align: right;
            vertical-align: middle;
            white-space: nowrap;
        }
        .seal-text {
            font-size: 11px;
            font-weight: 700;
            color: #37414A;
            vertical-align: middle;
            margin-right: 6px;
        }
        .seal-isologo {
            height: 24px;
            width: auto;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <!-- Encabezado Superior Izquierdo: Logo Corporativo de la Empresa Cliente -->
    <table class="header-table">
        <tr>
            <td>
                <?php if ($vm['cliente_logo_rel_url'] !== ''): ?>
                    <img src="<?= htmlspecialchars($vm['cliente_logo_rel_url'], ENT_QUOTES, 'UTF-8') ?>" class="logo" alt="<?= htmlspecialchars($vm['cliente_nombre_comercial'], ENT_QUOTES, 'UTF-8') ?>">
                <?php else: ?>
                    <div class="client-brand-badge"><?= htmlspecialchars($vm['cliente_nombre_comercial'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div>
                    <strong><?= htmlspecialchars($vm['cliente_razon_social'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                    RIF / ID Fiscal: <?= htmlspecialchars($vm['cliente_rif'], ENT_QUOTES, 'UTF-8') ?><br>
                    <?php if ($vm['cliente_direccion'] !== ''): ?>
                        <?= htmlspecialchars($vm['cliente_direccion'], ENT_QUOTES, 'UTF-8') ?><br>
                    <?php endif; ?>
                    Sede: <?= htmlspecialchars($vm['nombre_sede_lbl'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </td>
            <td style="text-align: right;">
                <div class="title"><?= htmlspecialchars($vm['titulo_doc'], ENT_QUOTES, 'UTF-8') ?></div>
                <p style="margin: 4px 0;"><strong>Nº de Documento:</strong> <?= htmlspecialchars($vm['codigo_documento'], ENT_QUOTES, 'UTF-8') ?></p>
                <p style="margin: 4px 0;"><strong>Fecha de Emisión:</strong> <?= htmlspecialchars($vm['fecha_emision'], ENT_QUOTES, 'UTF-8') ?></p>
                <p style="margin: 4px 0;"><strong>Fecha Vencimiento:</strong> <span style="color: #c53030; font-weight: 700;"><?= htmlspecialchars($vm['fecha_vencimiento'], ENT_QUOTES, 'UTF-8') ?></span></p>
                <p style="margin: 4px 0;"><strong>Estado:</strong> <span style="color: <?= htmlspecialchars($vm['color_estado'], ENT_QUOTES, 'UTF-8') ?>; font-weight: 700;"><?= htmlspecialchars($vm['texto_estado'], ENT_QUOTES, 'UTF-8') ?></span></p>
            </td>
        </tr>
    </table>

    <!-- Datos de la Sede y Periodo -->
    <table class="two-column">
        <tr>
            <td style="padding-right: 8px;">
                <div class="info-box">
                    <strong>DATOS DE LA SEDE / CLIENTE:</strong><br>
                    <strong>Sede / Sucursal:</strong> <?= htmlspecialchars($vm['nombre_sede_lbl'], ENT_QUOTES, 'UTF-8') ?><br>
                    <strong>Identificación (RIF/ID):</strong> <?= htmlspecialchars($vm['cliente_rif'], ENT_QUOTES, 'UTF-8') ?><br>
                    <strong>Atención a:</strong> <?= htmlspecialchars($vm['atencion_lbl'], ENT_QUOTES, 'UTF-8') ?><br>
                    <strong>Correo:</strong> <?= htmlspecialchars($vm['correo_sede_lbl'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </td>
            <td style="padding-left: 8px;">
                <div class="info-box" style="border-color: #bfdbfe; background-color: #eff6ff;">
                    <strong>DETALLE DEL PERIODO FACTURADO:</strong><br><br>
                    <span class="badge-periodo"><?= htmlspecialchars($vm['fecha_desde'], ENT_QUOTES, 'UTF-8') ?> al <?= htmlspecialchars($vm['fecha_hasta'], ENT_QUOTES, 'UTF-8') ?></span><br><br>
                    <small>Liquidación consolidada por consumo de mensajería y servicios operativos de infraestructura WhatsApp Business API.</small>
                </div>
            </td>
        </tr>
    </table>

    <!-- Estadísticas -->
    <table class="stats-table">
        <tr>
            <td>
                <strong style="color: #2563eb;">Mensajes Totales</strong>
                <div style="font-size: 15px; font-weight: 700; margin-top: 4px;"><?= number_format($vm['mensajes_totales_ord']) ?></div>
            </td>
            <td>
                <strong style="color: #16a34a;">Entregados</strong>
                <div style="font-size: 15px; font-weight: 700; margin-top: 4px;"><?= number_format($vm['tot_entregados']) ?></div>
            </td>
            <td>
                <strong style="color: #0d9488;">Gratuitos (24h)</strong>
                <div style="font-size: 15px; font-weight: 700; margin-top: 4px;"><?= number_format($vm['tot_gratuitos']) ?></div>
            </td>
            <td>
                <strong style="color: #d97706;">Facturables</strong>
                <div style="font-size: 15px; font-weight: 700; margin-top: 4px;"><?= number_format($vm['tot_pagados']) ?></div>
            </td>
        </tr>
    </table>

    <!-- Tabla A: Mensajes Facturables -->
    <div class="section-caption">A. Mensajes Facturables (Con Cargo Operativo Integrado)</div>
    <table class="items-table">
        <thead>
            <tr>
                <th>Concepto / Servicio</th>
                <th style="text-align: center; width: 110px;">Categoría</th>
                <th style="text-align: center; width: 85px;">Cantidad</th>
                <th style="text-align: right; width: 105px;">Precio Unit.</th>
                <th style="text-align: right; width: 110px;">Total (USD)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vm['items_pagados'] as $it): ?>
            <tr>
                <td><strong><?= htmlspecialchars($it['concepto'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                <td style="text-align: center;"><?= htmlspecialchars($it['categoria'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align: center;"><?= number_format(intval($it['pagados'])) ?></td>
                <td style="text-align: right;">$<?= number_format(floatval($it['precio_unit']), 4) ?></td>
                <td style="text-align: right; font-weight: 700;">$<?= number_format(floatval($it['total_usd']), 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!empty($vm['items_gratuitos'])): ?>
    <!-- Tabla B: Mensajes Gratuitos -->
    <div class="section-caption">B. Mensajes Gratuitos (Ventana de Atención 24h — Sin Cargo)</div>
    <table class="items-table free-items">
        <thead>
            <tr>
                <th>Concepto / Servicio</th>
                <th style="text-align: center; width: 110px;">Categoría</th>
                <th style="text-align: center; width: 85px;">Cantidad</th>
                <th style="text-align: right; width: 105px;">Precio Unit.</th>
                <th style="text-align: right; width: 110px;">Total (USD)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vm['items_gratuitos'] as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['concepto'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align: center;"><?= htmlspecialchars($it['categoria'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="text-align: center;"><?= number_format(intval($it['gratuitos'])) ?></td>
                <td style="text-align: right;">$0.0000</td>
                <td style="text-align: right;">$0.00</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Totales Finales -->
    <table class="totals-table">
        <tr>
            <td>Subtotal Servicios WABA:</td>
            <td style="text-align: right;">$<?= number_format($vm['subtotal'], 2) ?></td>
        </tr>
        <tr>
            <td>Impuestos Aplicables (0%):</td>
            <td style="text-align: right;">$<?= number_format($vm['impuesto'], 2) ?></td>
        </tr>
        <tr class="total-row">
            <td>Total Final:</td>
            <td style="text-align: right; color: #E85B14;">$<?= number_format($vm['total'], 2) ?> USD</td>
        </tr>
    </table>

    <!-- Constancia de Pago o Instrucciones -->
    <?php if ($vm['metodo_pago'] !== '' || $vm['estado_orden'] === 'PAGADO' || $vm['estado_orden'] === 'EN_REVISION'): ?>
        <div class="<?= $vm['estado_orden'] === 'PAGADO' ? 'payment-receipt-box' : 'payment-review-box' ?>">
            <strong style="color: <?= $vm['estado_orden'] === 'PAGADO' ? '#166534' : '#0369a1' ?>;">
                <?= $vm['estado_orden'] === 'PAGADO' ? 'CONSTANCIA DE PAGO REGISTRADO Y VERIFICADO' : 'REPORTE DE PAGO (PENDIENTE POR VERIFICACIÓN MASTER)' ?>
            </strong>
            <table style="width: 100%; margin-top: 6px; border-collapse: collapse;">
                <tr>
                    <td style="width: 68%; vertical-align: top;">
                        <p style="margin: 3px 0;"><strong>Método de Pago:</strong> <?= htmlspecialchars($vm['metodo_pago'] ?: 'No especificado', ENT_QUOTES, 'UTF-8') ?></p>
                        <p style="margin: 3px 0;"><strong>Referencia / TxID:</strong> <?= htmlspecialchars($vm['referencia_pago'] ?: 'N/A', ENT_QUOTES, 'UTF-8') ?></p>
                        <p style="margin: 3px 0;"><strong>Fecha de Pago:</strong> <?= htmlspecialchars($vm['fecha_pago'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($vm['notas_pago'] !== ''): ?>
                            <p style="margin: 3px 0;"><strong>Observaciones:</strong> <?= nl2br(htmlspecialchars($vm['notas_pago'], ENT_QUOTES, 'UTF-8')) ?></p>
                        <?php endif; ?>
                    </td>
                    <td style="width: 32%; text-align: right; vertical-align: top;">
                        <?php if ($comprobante_secure_url !== '' && !$vm['comprobante_es_pdf']): ?>
                            <img src="<?= htmlspecialchars($comprobante_secure_url, ENT_QUOTES, 'UTF-8') ?>" style="max-width: 140px; max-height: 105px; border: 1px solid #cbd5e1; border-radius: 4px;" alt="Comprobante">
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    <?php else: ?>
        <div class="payment-methods">
            <strong>INSTRUCCIONES Y MÉTODOS DE PAGO:</strong>
            <p style="margin: 5px 0 0 0;">Realice el pago antes del vencimiento indicando el documento <strong><?= htmlspecialchars($vm['codigo_documento'], ENT_QUOTES, 'UTF-8') ?></strong>. Métodos habilitados: Binance, Zelle, Pago Móvil, Transferencia Bancaria o Efectivo.</p>
        </div>
    <?php endif; ?>

    <!-- Pie Institucional: Sello Oficial "Generado por Starfi" + isologo_ni.png en esquina inferior derecha -->
    <table class="footer-table">
        <tr>
            <td class="footer-note"><?= htmlspecialchars($vm['footer_note'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="footer-seal-cell">
                <span class="seal-text">Generado por Starfi</span>
                <img src="docs/identidad_visual/logos/isologo_ni.png" class="seal-isologo" alt="Generado por Starfi">
            </td>
        </tr>
    </table>
</body>
</html>
