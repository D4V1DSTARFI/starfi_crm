<?php
/**
 * core/services/QuickQuotePdfService.php
 * Motor Empresarial de Cotizador Rápido PDF en el Chat ([OMN-03] — OT-STARFI-2026-11 SPRINT 4 / Remediado OT-QA-CRM-2026-1126-06):
 * - Genera cotización comercial PDF membretada dinámicamente por Sede con FPDF 1.85.
 * - Aislamiento estricto Multi-Sede de `empresa_perfil` por `id_sede` (0 fugas de RIF/logo entre sedes — CHK-CLD-01)
 *   e insignia corporativa vectorial de respaldo cuando no existe archivo de logo en disco (CHK-UIX-03).
 * - Paginación determinista sin desfase entre Condiciones/Notas y Cuadro de Totales USD (CHK-UIX-05).
 * - Correlativo diario `MAX(...) + 1` a prueba de bajas, lock `FOR UPDATE` efectivo incluso en `id_sede = 0`
 *   y eliminación garantizada de PDF en `rollback()` (0 archivos huérfanos — CHK-SEC-05, CHK-ARC-07).
 */

require_once __DIR__ . '/../crm_sprint2_helper.php';
require_once __DIR__ . '/../audit.php';
require_once __DIR__ . '/../../includes/libs/pdf/fpdf.php';

if (!function_exists('starfi_quote_pdf_enc')) {
    function starfi_quote_pdf_enc($str): string
    {
        $clean = str_replace(
            ["\xE2\x82\xAC", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\xA2"],
            ['__STARFI_EURO__', '-', '-', '"', '"', "'", "'", '*'],
            (string)$str
        );
        if (function_exists('iconv')) {
            $conv = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $clean);
            if ($conv !== false) {
                return str_replace('__STARFI_EURO__', chr(128), $conv);
            }
        }
        $iso = utf8_decode($clean);
        return str_replace('__STARFI_EURO__', chr(128), $iso);
    }
}

if (!class_exists('StarfiQuickQuotePDF')) {
    class StarfiQuickQuotePDF extends FPDF
    {
        public $sedeHeader = [];
        public $quoteMeta = [];
        public $sealAbsPath = '';

        public function clipTextToWidth(string $text, float $maxWidthMm): string
        {
            $raw = trim(preg_replace('/\s+/u', ' ', $text));
            $enc = starfi_quote_pdf_enc($raw);
            if ($this->GetStringWidth($enc) <= $maxWidthMm) {
                return $enc;
            }
            $suffix = '...';
            $len = mb_strlen($raw, 'UTF-8');
            while ($len > 1) {
                $len--;
                $cand = starfi_quote_pdf_enc(rtrim(mb_substr($raw, 0, $len, 'UTF-8')) . $suffix);
                if ($this->GetStringWidth($cand) <= $maxWidthMm) {
                    return $cand;
                }
            }
            return $suffix;
        }

        /**
         * Calcula cuántas líneas reales ocupará un texto en un MultiCell de ancho $maxWidthMm
         * usando la fuente activa (CHK-PDF-03: llamar siempre después de SetFont).
         */
        public function estimateWrappedLines(float $maxWidthMm, string $utf8Text): int
        {
            $usableW = max(10.0, $maxWidthMm - ($this->cMargin * 2.0));
            $paragraphs = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($utf8Text)));
            $totalLines = 0;

            foreach ($paragraphs as $para) {
                $paraTrim = trim($para);
                if ($paraTrim === '') {
                    $totalLines++;
                    continue;
                }
                $words = preg_split('/\s+/u', $paraTrim, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $curLine = '';
                foreach ($words as $word) {
                    $candidate = ($curLine === '') ? $word : ($curLine . ' ' . $word);
                    if ($this->GetStringWidth(starfi_quote_pdf_enc($candidate)) <= $usableW) {
                        $curLine = $candidate;
                    } else {
                        if ($curLine !== '') {
                            $totalLines++;
                        }
                        // Si una sola palabra es más larga que la columna, estimar sus quiebres forzosos
                        $wordW = $this->GetStringWidth(starfi_quote_pdf_enc($word));
                        if ($wordW > $usableW) {
                            $extraWraps = (int)floor($wordW / $usableW);
                            $totalLines += max(1, $extraWraps);
                            $curLine = '';
                        } else {
                            $curLine = $word;
                        }
                    }
                }
                if ($curLine !== '') {
                    $totalLines++;
                }
            }

            return max(1, $totalLines);
        }

        public function Header()
        {
            $margin = 14.0;
            $pageW = $this->GetPageWidth();

            // Franja superior corporativa
            $this->SetFillColor(55, 65, 74);
            $this->Rect(0, 0, $pageW, 4.5, 'F');
            $this->SetFillColor(232, 91, 20);
            $this->Rect(0, 4.5, 68, 1.5, 'F');

            $yTop = 11.0;
            $xText = $margin;
            $logoPath = (string)($this->sedeHeader['logo_abs'] ?? '');
            $logoDrawn = false;

            if ($logoPath !== '' && is_file($logoPath)) {
                $imgInfo = @getimagesize($logoPath);
                if (is_array($imgInfo) && !empty($imgInfo[0]) && !empty($imgInfo[1])) {
                    $maxW = 28.0;
                    $maxH = 16.0;
                    $ratio = min($maxW / $imgInfo[0], $maxH / $imgInfo[1]);
                    $drawW = max(8.0, $imgInfo[0] * $ratio);
                    $drawH = max(8.0, $imgInfo[1] * $ratio);
                    @$this->Image($logoPath, $margin, $yTop, $drawW, $drawH);
                    $xText = $margin + $drawW + 4.0;
                    $logoDrawn = true;
                }
            }

            // CHK-UIX-03: Insignia corporativa limpia arriba a la izquierda si la sede no posee archivo físico de logo
            if (!$logoDrawn) {
                $badgeW = 24.0;
                $badgeH = 15.5;
                $this->SetFillColor(30, 41, 59);
                $this->Rect($margin, $yTop, $badgeW, $badgeH, 'F');
                $this->SetFillColor(232, 91, 20);
                $this->Rect($margin, $yTop, 2.2, $badgeH, 'F');

                $rawBrand = trim((string)($this->sedeHeader['empresa_nombre'] ?? ($this->sedeHeader['nombre_sede'] ?? 'STARFI')));
                $words = preg_split('/\s+/u', $rawBrand, -1, PREG_SPLIT_NO_EMPTY) ?: ['ST'];
                $monogram = count($words) >= 2
                    ? mb_strtoupper(mb_substr($words[0], 0, 1, 'UTF-8') . mb_substr($words[1], 0, 1, 'UTF-8'), 'UTF-8')
                    : mb_strtoupper(mb_substr($rawBrand, 0, 3, 'UTF-8'), 'UTF-8');

                $this->SetXY($margin + 2.2, $yTop + 2.8);
                $this->SetFont('Helvetica', 'B', 11.5);
                $this->SetTextColor(255, 255, 255);
                $this->Cell($badgeW - 2.2, 5.5, $this->clipTextToWidth($monogram, $badgeW - 3.5), 0, 1, 'C');

                $this->SetX($margin + 2.2);
                $this->SetFont('Helvetica', 'B', 5.8);
                $this->SetTextColor(255, 138, 77);
                $this->Cell($badgeW - 2.2, 3.8, starfi_quote_pdf_enc('SEDE OFICIAL'), 0, 0, 'C');

                $xText = $margin + $badgeW + 4.0;
            }

            // Bloque Izquierdo: Membrete Corporativo + Sede Específica (estrictamente aislada por id_sede)
            $leftMaxW = 108.0 - ($xText - $margin);
            $this->SetXY($xText, $yTop);
            $this->SetFont('Helvetica', 'B', 10.8);
            $this->SetTextColor(15, 23, 42);
            $empresaNom = (string)($this->sedeHeader['empresa_nombre'] ?? 'STARFI CRM');
            $this->Cell($leftMaxW, 5.0, $this->clipTextToWidth($empresaNom, $leftMaxW), 0, 1, 'L');

            $this->SetX($xText);
            $this->SetFont('Helvetica', 'B', 8.4);
            $this->SetTextColor(232, 91, 20);
            $sedeNom = 'SEDE: ' . strtoupper((string)($this->sedeHeader['nombre_sede'] ?? 'PRINCIPAL'));
            $this->Cell($leftMaxW, 4.3, $this->clipTextToWidth($sedeNom, $leftMaxW), 0, 1, 'L');

            $this->SetX($xText);
            $this->SetFont('Helvetica', '', 7.6);
            $this->SetTextColor(71, 85, 105);
            $rifLine = 'RIF: ' . (string)($this->sedeHeader['rif'] ?? 'N/A');
            if (!empty($this->sedeHeader['telefono_sede'])) {
                $rifLine .= '  |  WhatsApp: +' . preg_replace('/[^0-9]/', '', (string)$this->sedeHeader['telefono_sede']);
            }
            $this->Cell($leftMaxW, 3.9, $this->clipTextToWidth($rifLine, $leftMaxW), 0, 1, 'L');

            if (!empty($this->sedeHeader['direccion'])) {
                $this->SetX($xText);
                $this->SetFont('Helvetica', '', 7.2);
                $this->Cell($leftMaxW, 3.7, $this->clipTextToWidth((string)$this->sedeHeader['direccion'], $leftMaxW), 0, 1, 'L');
            }

            // Bloque Derecho: Identificación de la Cotización
            $boxW = 68.0;
            $boxX = $pageW - $margin - $boxW;
            $this->SetFillColor(248, 250, 252);
            $this->SetDrawColor(226, 232, 240);
            $this->Rect($boxX, $yTop - 1.0, $boxW, 20.5, 'DF');

            $this->SetXY($boxX + 3.0, $yTop + 0.8);
            $this->SetFont('Helvetica', 'B', 9.5);
            $this->SetTextColor(55, 65, 74);
            $this->Cell($boxW - 6.0, 4.8, starfi_quote_pdf_enc('COTIZACIÓN COMERCIAL'), 0, 1, 'R');

            $this->SetX($boxX + 3.0);
            $this->SetFont('Helvetica', 'B', 9.0);
            $this->SetTextColor(232, 91, 20);
            $this->Cell($boxW - 6.0, 4.6, $this->clipTextToWidth((string)($this->quoteMeta['codigo'] ?? 'COT-0001'), $boxW - 6.0), 0, 1, 'R');

            $this->SetX($boxX + 3.0);
            $this->SetFont('Helvetica', '', 7.4);
            $this->SetTextColor(100, 116, 139);
            $emisionStr = 'Emisión: ' . (string)($this->quoteMeta['fecha_emision'] ?? date('d/m/Y'));
            $this->Cell($boxW - 6.0, 3.8, starfi_quote_pdf_enc($emisionStr), 0, 1, 'R');

            $this->SetX($boxX + 3.0);
            $venceStr = 'Válida hasta: ' . (string)($this->quoteMeta['fecha_validez'] ?? date('d/m/Y'));
            $this->Cell($boxW - 6.0, 3.8, starfi_quote_pdf_enc($venceStr), 0, 1, 'R');

            $this->SetDrawColor(226, 232, 240);
            $this->Line($margin, 35.0, $pageW - $margin, 35.0);
            $this->SetY(38.5);
        }

        public function Footer()
        {
            $pageW = $this->GetPageWidth();
            $margin = 14.0;
            $this->SetY(-19);
            $this->SetDrawColor(226, 232, 240);
            $this->Line($margin, $this->GetY(), $pageW - $margin, $this->GetY());

            $this->SetY(-15.5);
            $this->SetX($margin);
            $this->SetFont('Helvetica', '', 7.3);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(95, 5, starfi_quote_pdf_enc('Documento comercial sujeto a disponibilidad · Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'L');

            // Sello obligatorio QA-STD-GLOBAL-2.1 abajo a la derecha: "Generado por Starfi" + isologo_ni.png en GetPageWidth() - 25
            $sealX = $pageW - 25.0;
            $this->SetFont('Helvetica', 'B', 7.2);
            $this->SetTextColor(71, 85, 105);
            $labelTxt = starfi_quote_pdf_enc('Generado por Starfi');
            $lblW = $this->GetStringWidth($labelTxt);
            $this->SetXY($sealX - $lblW - 2.2, -15.2);
            $this->Cell($lblW + 1.0, 5.0, $labelTxt, 0, 0, 'R');

            if ($this->sealAbsPath !== '' && is_file($this->sealAbsPath)) {
                @$this->Image($this->sealAbsPath, $sealX, $this->GetPageHeight() - 16.5, 10.5, 0);
            }
        }
    }
}

class QuickQuotePdfService
{
    /**
     * Garantiza las tablas `cotizaciones_rapidas` y `cotizaciones_rapidas_items` fuera de transacciones activas.
     */
    public static function ensureSchema($con): void
    {
        if (!$con) {
            return;
        }
        if (function_exists('starfi_tx_is_active') && starfi_tx_is_active($con)) {
            return;
        }

        $dbHash = function_exists('starfi_db_schema_hash') ? starfi_db_schema_hash($con) : 'default';
        $flag = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_sprint4_quotes_v2_' . $dbHash . '.flag';
        if (@file_exists($flag) && (time() - @filemtime($flag)) < 86400) {
            $chk = @mysqli_query($con, "SHOW TABLES LIKE 'cotizaciones_rapidas'");
            if ($chk && mysqli_num_rows($chk) > 0) {
                return;
            }
        }

        @mysqli_query($con, "CREATE TABLE IF NOT EXISTS `cotizaciones_rapidas` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `codigo_cotizacion` VARCHAR(40) NOT NULL,
            `id_empresa` INT NOT NULL DEFAULT 1,
            `id_sede` INT NOT NULL DEFAULT 0,
            `id_cliente` INT NOT NULL DEFAULT 0,
            `id_conversacion` INT DEFAULT NULL,
            `id_oportunidad` INT DEFAULT NULL,
            `id_agente` INT NOT NULL DEFAULT 0,
            `moneda` VARCHAR(10) NOT NULL DEFAULT 'USD',
            `subtotal_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `descuento_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `aplica_iva` TINYINT(1) NOT NULL DEFAULT 0,
            `iva_pct` DECIMAL(5,2) NOT NULL DEFAULT 16.00,
            `iva_monto_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `total_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `validez_dias` INT NOT NULL DEFAULT 7,
            `condiciones` TEXT DEFAULT NULL,
            `notas` TEXT DEFAULT NULL,
            `pdf_ruta` VARCHAR(255) NOT NULL,
            `id_mensaje_meta` VARCHAR(120) DEFAULT NULL,
            `id_mensaje_local` INT DEFAULT NULL,
            `estado` VARCHAR(25) NOT NULL DEFAULT 'EMITIDA',
            `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY `uniq_codigo_cotizacion` (`codigo_cotizacion`),
            INDEX `idx_sede_fecha` (`id_sede`, `fecha_creacion`),
            INDEX `idx_cliente` (`id_cliente`),
            INDEX `idx_conversacion` (`id_conversacion`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @mysqli_query($con, "CREATE TABLE IF NOT EXISTS `cotizaciones_rapidas_items` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `id_cotizacion` INT NOT NULL,
            `orden` INT NOT NULL DEFAULT 1,
            `descripcion` VARCHAR(255) NOT NULL,
            `cantidad` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            `precio_unitario_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `subtotal_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            INDEX `idx_cotizacion` (`id_cotizacion`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @file_put_contents($flag, date('Y-m-d H:i:s'));
    }

    /**
     * Normaliza y calcula la matriz de ítems y totales financieros en USD (100% server-side).
     */
    public static function calculateQuoteTotals(array $rawItems, float $descuentoUsd = 0.0, bool $aplicaIva = false, float $ivaPct = 16.0): array
    {
        $cleanItems = [];
        $subtotal = 0.0;
        $idx = 1;

        foreach ($rawItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $desc = trim((string)($it['descripcion'] ?? ($it['concepto'] ?? '')));
            $qty = round(floatval($it['cantidad'] ?? 0), 2);
            $unit = round(floatval($it['precio_unitario_usd'] ?? ($it['precio_unit'] ?? ($it['precio'] ?? 0))), 2);
            if ($desc === '' || $qty <= 0 || $unit < 0) {
                continue;
            }
            $lineSub = round($qty * $unit, 2);
            $subtotal = round($subtotal + $lineSub, 2);
            $cleanItems[] = [
                'orden'               => $idx++,
                'descripcion'         => mb_substr($desc, 0, 250, 'UTF-8'),
                'cantidad'            => $qty,
                'precio_unitario_usd' => $unit,
                'subtotal_usd'        => $lineSub,
            ];
        }

        if (empty($cleanItems)) {
            return ['ok' => false, 'message' => 'Debes agregar al menos un ítem válido con cantidad mayor a cero.'];
        }

        $descuento = max(0.0, min($subtotal, round($descuentoUsd, 2)));
        $baseImponible = max(0.0, round($subtotal - $descuento, 2));
        $pct = $aplicaIva ? max(0.0, min(100.0, round($ivaPct, 2))) : 0.0;
        $ivaMonto = $aplicaIva ? round($baseImponible * ($pct / 100.0), 2) : 0.00;
        $total = round($baseImponible + $ivaMonto, 2);

        return [
            'ok'            => true,
            'items'         => $cleanItems,
            'subtotal_usd'  => $subtotal,
            'descuento_usd' => $descuento,
            'aplica_iva'    => $aplicaIva ? 1 : 0,
            'iva_pct'       => $aplicaIva ? $pct : 0.00,
            'iva_monto_usd' => $ivaMonto,
            'total_usd'     => $total,
        ];
    }

    /**
     * Resuelve los datos fiscales y de membrete estrictamente para la Sede emisora (QA-BUG-CRM6-02 / CHK-CLD-01).
     * Jamás adjudica el RIF, dirección ni logo de otra sede si la sede actual no posee fila propia en `empresa_perfil`.
     */
    public static function resolveSedeLetterhead($con, int $idSede, int $idEmpresa = 1, int $agenteId = 0): array
    {
        $sedeRow = [];
        if ($idSede > 0) {
            $stmtS = $con->prepare("SELECT * FROM sedes WHERE id = ? LIMIT 1");
            if ($stmtS) {
                $stmtS->bind_param("i", $idSede);
                $stmtS->execute();
                $rS = $stmtS->get_result();
                $sedeRow = ($rS && $rS->num_rows > 0) ? $rS->fetch_assoc() : [];
                $stmtS->close();
            }
        }
        if (!empty($sedeRow['id_empresa'])) {
            $idEmpresa = intval($sedeRow['id_empresa']);
        }

        // Respaldo opcional de nombre comercial matriz en `empresas` (sin cruzar RIF entre sedes)
        $empBaseRow = [];
        $chkEmpTbl = @$con->prepare("SHOW TABLES LIKE 'empresas'");
        if ($chkEmpTbl && $chkEmpTbl->execute()) {
            $rEmpTbl = $chkEmpTbl->get_result();
            $hasEmpTbl = ($rEmpTbl && $rEmpTbl->num_rows > 0);
            $chkEmpTbl->close();
            if ($hasEmpTbl && $idEmpresa > 0) {
                $stmtEmp = @$con->prepare("SELECT * FROM empresas WHERE id = ? LIMIT 1");
                if ($stmtEmp) {
                    $stmtEmp->bind_param("i", $idEmpresa);
                    if ($stmtEmp->execute()) {
                        $rEmp = $stmtEmp->get_result();
                        $empBaseRow = ($rEmp && $rEmp->num_rows > 0) ? $rEmp->fetch_assoc() : [];
                    }
                    $stmtEmp->close();
                }
            }
        }

        // Consultar `empresa_perfil` inspeccionando sus columnas reales y filtrando ESTRICTAMENTE por `id_sede = ?`
        $perfilRow = [];
        $empPerfilCols = [];
        $stmtCols = @$con->prepare("SHOW COLUMNS FROM empresa_perfil");
        if ($stmtCols && $stmtCols->execute()) {
            $rc = $stmtCols->get_result();
            while ($rc && ($col = $rc->fetch_assoc())) {
                $empPerfilCols[$col['Field']] = true;
            }
            $stmtCols->close();
        }

        if (!empty($empPerfilCols)) {
            if (isset($empPerfilCols['id_sede'])) {
                if ($idSede > 0) {
                    $stmtP = $con->prepare("SELECT * FROM empresa_perfil WHERE id_sede = ? ORDER BY id ASC LIMIT 1");
                    if ($stmtP) {
                        $stmtP->bind_param("i", $idSede);
                        $stmtP->execute();
                        $rP = $stmtP->get_result();
                        $perfilRow = ($rP && $rP->num_rows > 0) ? $rP->fetch_assoc() : [];
                        $stmtP->close();
                    }
                }
            } elseif (isset($empPerfilCols['id_empresa']) && $idEmpresa > 0) {
                $stmtP = $con->prepare("SELECT * FROM empresa_perfil WHERE id_empresa = ? ORDER BY id ASC LIMIT 1");
                if ($stmtP) {
                    $stmtP->bind_param("i", $idEmpresa);
                    $stmtP->execute();
                    $rP = $stmtP->get_result();
                    $perfilRow = ($rP && $rP->num_rows > 0) ? $rP->fetch_assoc() : [];
                    $stmtP->close();
                }
            }
        }

        $lineaPhone = '';
        if ($idSede > 0) {
            $stmtL = $con->prepare("SELECT numero_telefono FROM lineas_whatsapp WHERE id_sede = ? ORDER BY (estado = 'ACTIVO') DESC, id ASC LIMIT 1");
            if ($stmtL) {
                $stmtL->bind_param("i", $idSede);
                $stmtL->execute();
                $rL = $stmtL->get_result();
                if ($rL && ($rwL = $rL->fetch_assoc())) {
                    $lineaPhone = (string)($rwL['numero_telefono'] ?? '');
                }
                $stmtL->close();
            }
        }

        $asesorNombre = 'Asesor Comercial';
        if ($agenteId > 0) {
            $stmtA = $con->prepare("SELECT COALESCE(up.nombre, u.usuario) AS nom FROM usuario u LEFT JOIN usuario_perfil up ON u.id = up.id_usuario WHERE u.id = ? LIMIT 1");
            if ($stmtA) {
                $stmtA->bind_param("i", $agenteId);
                $stmtA->execute();
                $rA = $stmtA->get_result();
                if ($rA && ($rwA = $rA->fetch_assoc()) && !empty($rwA['nom'])) {
                    $asesorNombre = trim((string)$rwA['nom']);
                }
                $stmtA->close();
            }
        }

        $nombreSede = trim((string)($sedeRow['nombre_sede'] ?? 'Sede Principal'));
        $empresaNombre = trim((string)(
            $perfilRow['nombre_comercial']
            ?? ($perfilRow['razon_social']
            ?? ($sedeRow['razon_social']
            ?? ($empBaseRow['nombre_comercial']
            ?? ($empBaseRow['razon_social']
            ?? $nombreSede))))
        ));
        if ($empresaNombre === '') {
            $empresaNombre = 'STARFI COMERCIAL';
        }

        // RIF y dirección provienen exclusivamente del perfil propio de la sede o de la fila de `sedes`
        $rifRaw = trim((string)($perfilRow['rif'] ?? ($sedeRow['rif'] ?? ($sedeRow['rif_sede'] ?? ''))));
        if (!empty($perfilRow['letra']) && $rifRaw !== '' && $rifRaw !== 'N/A' && strpos($rifRaw, '-') === false) {
            $rif = trim((string)$perfilRow['letra']) . '-' . $rifRaw;
        } else {
            $rif = ($rifRaw !== '') ? $rifRaw : 'N/A';
        }

        $direccion = trim((string)(
            $perfilRow['direccion_fiscal']
            ?? ($perfilRow['direccion']
            ?? ($sedeRow['direccion'] ?? ''))
        ));

        $rootReal = realpath(__DIR__ . '/../..');
        $logoAbs = '';
        $logoCandRaw = trim((string)($perfilRow['logo_ruta'] ?? ($perfilRow['logo_url'] ?? ($perfilRow['logo_empresa'] ?? ''))));
        if ($logoCandRaw !== '' && $rootReal) {
            $cleanRel = ltrim(str_replace('\\', '/', $logoCandRaw), './');
            if (stripos($cleanRel, 'identidad_visual/logos/') === false) {
                $candidates = [
                    $cleanRel,
                    'uploads/empresa/' . basename($cleanRel),
                    'assets/uploads/empresa/' . basename($cleanRel),
                ];
                foreach ($candidates as $cRel) {
                    $cAbs = realpath($rootReal . '/' . $cRel);
                    if ($cAbs && strpos($cAbs, $rootReal) === 0 && is_file($cAbs)) {
                        $logoAbs = $cAbs;
                        break;
                    }
                }
            }
        }

        return [
            'id_empresa'     => max(1, $idEmpresa),
            'id_sede'        => $idSede,
            'empresa_nombre' => $empresaNombre,
            'nombre_sede'    => $nombreSede,
            'rif'            => $rif,
            'direccion'      => $direccion,
            'telefono_sede'  => $lineaPhone,
            'asesor_nombre'  => $asesorNombre,
            'logo_abs'       => $logoAbs,
        ];
    }

    /**
     * Prepara una ruta de logo corporativo segura y compatible con FPDF 1.85
     * (convirtiendo automáticamente PNGs entrelazados/16-bit o WEBP a PNG/JPG plano no-entrelazado).
     */
    private static function prepareFpdfSafeLogo(string $logoAbs, array &$cleanupFiles): string
    {
        if ($logoAbs === '' || !is_file($logoAbs) || !is_readable($logoAbs)) {
            return '';
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower(trim((string)@$finfo->file($logoAbs)));
        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            return $logoAbs;
        }
        if ($mime === 'image/png') {
            $hdr = (string)@file_get_contents($logoAbs, false, null, 0, 32);
            $bitDepth = strlen($hdr) >= 29 ? ord($hdr[24]) : 8;
            $interlace = strlen($hdr) >= 29 ? ord($hdr[28]) : 0;
            if ($bitDepth <= 8 && $interlace === 0) {
                return $logoAbs;
            }
            if (function_exists('imagecreatefrompng') && function_exists('imagepng')) {
                $im = @imagecreatefrompng($logoAbs);
                if ($im) {
                    $w = max(1, imagesx($im));
                    $h = max(1, imagesy($im));
                    $canvas = imagecreatetruecolor($w, $h);
                    $white = imagecolorallocate($canvas, 255, 255, 255);
                    imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
                    imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
                    imageinterlace($canvas, false);
                    $tmpPng = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_qlogo_' . bin2hex(random_bytes(5)) . '.png';
                    if (@imagepng($canvas, $tmpPng)) {
                        imagedestroy($im);
                        imagedestroy($canvas);
                        $cleanupFiles[] = $tmpPng;
                        return $tmpPng;
                    }
                    imagedestroy($im);
                    imagedestroy($canvas);
                }
            }
            return '';
        }
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp') && function_exists('imagejpeg')) {
            $im = @imagecreatefromwebp($logoAbs);
            if ($im) {
                $w = max(1, imagesx($im));
                $h = max(1, imagesy($im));
                $canvas = imagecreatetruecolor($w, $h);
                $white = imagecolorallocate($canvas, 255, 255, 255);
                imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
                imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
                $tmpJpg = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_qlogo_' . bin2hex(random_bytes(5)) . '.jpg';
                if (@imagejpeg($canvas, $tmpJpg, 92)) {
                    imagedestroy($im);
                    imagedestroy($canvas);
                    $cleanupFiles[] = $tmpJpg;
                    return $tmpJpg;
                }
                imagedestroy($im);
                imagedestroy($canvas);
            }
        }
        return '';
    }

    /**
     * Renderiza físicamente el binario PDF (%PDF-1.x) de la cotización membretada por sede.
     */
    public static function renderQuotePdfBinary(array $letterhead, array $clientInfo, array $quoteMeta, array $calc): string
    {
        $rootReal = realpath(__DIR__ . '/../..');
        $sealAbs = $rootReal ? (realpath($rootReal . '/docs/identidad_visual/logos/isologo_ni.png') ?: '') : '';

        $tmpLogoCleanup = [];
        if (!empty($letterhead['logo_abs'])) {
            $letterhead['logo_abs'] = self::prepareFpdfSafeLogo((string)$letterhead['logo_abs'], $tmpLogoCleanup);
        }

        try {
            $pdf = new StarfiQuickQuotePDF('P', 'mm', 'Letter');
            $pdf->sedeHeader = $letterhead;
            $pdf->quoteMeta = $quoteMeta;
            $pdf->sealAbsPath = $sealAbs;
            $pdf->AliasNbPages();
            // CHK-PDF-02: SetTitle antes de SetAutoPageBreak
            $pdf->SetTitle('Cotizacion ' . ($quoteMeta['codigo'] ?? 'STARFI'), true);
            $pdf->SetAuthor('STARFI CRM', true);
            $pdf->SetMargins(14, 14, 14);
            $pdf->SetAutoPageBreak(true, 24);
            $pdf->AddPage();

            $margin = 14.0;
            $contentW = $pdf->GetPageWidth() - ($margin * 2.0);

            // Tarjeta de Datos del Cliente y Asesor
            $boxStartY = $pdf->GetY();
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->Rect($margin, $boxStartY, $contentW, 21.0, 'DF');

            $colW = ($contentW - 8.0) / 2.0;
            $pdf->SetXY($margin + 3.5, $boxStartY + 2.5);
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($colW, 4.0, starfi_quote_pdf_enc('PREPARADO PARA EL CLIENTE:'), 0, 1, 'L');

            $pdf->SetX($margin + 3.5);
            $pdf->SetFont('Helvetica', 'B', 9.8);
            $pdf->SetTextColor(15, 23, 42);
            $cliName = trim((string)($clientInfo['nombre'] ?? 'Cliente Comercial')) ?: 'Cliente Comercial';
            $pdf->Cell($colW, 4.8, $pdf->clipTextToWidth($cliName, $colW), 0, 1, 'L');

            $pdf->SetX($margin + 3.5);
            $pdf->SetFont('Helvetica', '', 8.0);
            $pdf->SetTextColor(71, 85, 105);
            $cliSub = 'WhatsApp: +' . preg_replace('/[^0-9]/', '', (string)($clientInfo['numero_whatsapp'] ?? ''));
            if (!empty($clientInfo['email'])) {
                $cliSub .= '  |  ' . trim((string)$clientInfo['email']);
            }
            $pdf->Cell($colW, 4.2, $pdf->clipTextToWidth($cliSub, $colW), 0, 1, 'L');

            // Columna derecha: Atención comercial en sede
            $rightX = $margin + 4.5 + $colW;
            $pdf->SetXY($rightX, $boxStartY + 2.5);
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($colW, 4.0, starfi_quote_pdf_enc('ATENCIÓN COMERCIAL EN SEDE:'), 0, 1, 'L');

            $pdf->SetX($rightX);
            $pdf->SetFont('Helvetica', 'B', 9.0);
            $pdf->SetTextColor(15, 23, 42);
            $asesorLine = 'Asesor: ' . (string)($letterhead['asesor_nombre'] ?? 'Asesor Comercial');
            $pdf->Cell($colW, 4.8, $pdf->clipTextToWidth($asesorLine, $colW), 0, 1, 'L');

            $pdf->SetX($rightX);
            $pdf->SetFont('Helvetica', '', 8.0);
            $pdf->SetTextColor(71, 85, 105);
            $monedaLine = 'Moneda: USD ($)  |  Validez: ' . intval($quoteMeta['validez_dias'] ?? 7) . ' días continuos';
            $pdf->Cell($colW, 4.2, $pdf->clipTextToWidth($monedaLine, $colW), 0, 1, 'L');

            $pdf->SetY($boxStartY + 25.0);

            // Encabezado de Tabla de Ítems
            $wIdx = 12.0;
            $wQty = 22.0;
            $wUnit = 34.0;
            $wSub = 36.0;
            $wDesc = $contentW - ($wIdx + $wQty + $wUnit + $wSub);

            $drawTableHeader = function () use ($pdf, $wIdx, $wDesc, $wQty, $wUnit, $wSub) {
                $pdf->SetFillColor(55, 65, 74);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetDrawColor(55, 65, 74);
                $pdf->SetFont('Helvetica', 'B', 8.2);
                $pdf->Cell($wIdx, 7.5, '#', 1, 0, 'C', true);
                $pdf->Cell($wDesc, 7.5, starfi_quote_pdf_enc('  Descripción del Producto / Servicio'), 1, 0, 'L', true);
                $pdf->Cell($wQty, 7.5, 'Cant.', 1, 0, 'C', true);
                $pdf->Cell($wUnit, 7.5, 'Precio Unit. USD', 1, 0, 'R', true);
                $pdf->Cell($wSub, 7.5, 'Subtotal USD', 1, 1, 'R', true);
            };

            $drawTableHeader();

            $rowAlt = false;
            foreach (($calc['items'] ?? []) as $item) {
                if ($pdf->GetY() > ($pdf->GetPageHeight() - 36.0)) {
                    $pdf->AddPage();
                    $drawTableHeader();
                }
                if ($rowAlt) {
                    $pdf->SetFillColor(248, 250, 252);
                } else {
                    $pdf->SetFillColor(255, 255, 255);
                }
                $pdf->SetDrawColor(226, 232, 240);
                $pdf->SetTextColor(30, 41, 59);
                $pdf->SetFont('Helvetica', '', 8.4);

                $qtyFmt = (floor($item['cantidad']) == $item['cantidad'])
                    ? number_format($item['cantidad'], 0)
                    : number_format($item['cantidad'], 2);

                $pdf->Cell($wIdx, 7.0, (string)intval($item['orden']), 1, 0, 'C', true);
                $pdf->Cell($wDesc, 7.0, '  ' . $pdf->clipTextToWidth((string)$item['descripcion'], $wDesc - 4.0), 1, 0, 'L', true);
                $pdf->Cell($wQty, 7.0, $qtyFmt, 1, 0, 'C', true);
                $pdf->Cell($wUnit, 7.0, '$' . number_format((float)$item['precio_unitario_usd'], 2) . ' ', 1, 0, 'R', true);
                $pdf->SetFont('Helvetica', 'B', 8.4);
                $pdf->Cell($wSub, 7.0, '$' . number_format((float)$item['subtotal_usd'], 2) . ' ', 1, 1, 'R', true);

                $rowAlt = !$rowAlt;
            }

            // QA-BUG-CRM6-02: Pre-calcular alturas reales de Condiciones/Notas y Cuadro de Totales USD
            // para que ambos arranquen sincronizados en la misma coordenada Y sin desfases multipágina.
            $notesW = $contentW - 76.0;
            $totW = 70.0;
            $totX = $margin + $contentW - $totW;

            $condText = trim((string)($quoteMeta['condiciones'] ?? ''));
            $notasText = trim((string)($quoteMeta['notas'] ?? ''));
            $combinedNotes = trim($condText . ($condText !== '' && $notasText !== '' ? "\n" : '') . $notasText);

            $notesHeight = 0.0;
            if ($combinedNotes !== '') {
                $pdf->SetFont('Helvetica', '', 7.8);
                $wrappedLines = $pdf->estimateWrappedLines($notesW, $combinedNotes);
                $notesHeight = 4.8 + ($wrappedLines * 4.2) + 2.0;
            }

            $totalsLineRows = 1; // Subtotal
            if ((float)$calc['descuento_usd'] > 0) {
                $totalsLineRows++;
            }
            if (!empty($calc['aplica_iva']) && (float)$calc['iva_monto_usd'] > 0) {
                $totalsLineRows++;
            }
            $totalsHeight = ($totalsLineRows * 6.2) + 7.8 + 2.0;

            $maxPrintableY = $pdf->GetPageHeight() - 25.0;
            // Si el bloque combinado cabe en una hoja limpia pero no cabe en el remanente actual, saltar de hoja ANTES de dibujar ambos
            $effectiveBlockH = max($totalsHeight, min($notesHeight, 165.0));
            if (($pdf->GetY() + 4.0 + $effectiveBlockH) > $maxPrintableY) {
                $pdf->AddPage();
            }

            $ySummaryTop = $pdf->GetY() + 4.0;

            // 1) Dibujar primero el Cuadro Fijo de Totales USD en ($totX, $ySummaryTop) de la página activa
            $pdf->SetXY($totX, $ySummaryTop);
            $pdf->SetFont('Helvetica', '', 8.4);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->SetFillColor(248, 250, 252);
            $pdf->Cell(38, 6.2, 'Subtotal:', 1, 0, 'R', true);
            $pdf->SetFont('Helvetica', 'B', 8.4);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(32, 6.2, '$' . number_format((float)$calc['subtotal_usd'], 2) . ' ', 1, 1, 'R', true);

            if ((float)$calc['descuento_usd'] > 0) {
                $pdf->SetX($totX);
                $pdf->SetFont('Helvetica', '', 8.4);
                $pdf->SetTextColor(185, 28, 28);
                $pdf->Cell(38, 6.2, 'Descuento:', 1, 0, 'R', true);
                $pdf->SetFont('Helvetica', 'B', 8.4);
                $pdf->SetTextColor(185, 28, 28);
                $pdf->Cell(32, 6.2, '-$' . number_format((float)$calc['descuento_usd'], 2) . ' ', 1, 1, 'R', true);
            }

            if (!empty($calc['aplica_iva']) && (float)$calc['iva_monto_usd'] > 0) {
                $pdf->SetX($totX);
                $pdf->SetFont('Helvetica', '', 8.4);
                $pdf->SetTextColor(71, 85, 105);
                $pdf->Cell(38, 6.2, 'IVA (' . number_format((float)$calc['iva_pct'], 0) . '%):', 1, 0, 'R', true);
                $pdf->SetFont('Helvetica', 'B', 8.4);
                $pdf->SetTextColor(15, 23, 42);
                $pdf->Cell(32, 6.2, '$' . number_format((float)$calc['iva_monto_usd'], 2) . ' ', 1, 1, 'R', true);
            }

            $pdf->SetX($totX);
            $pdf->SetFillColor(232, 91, 20);
            $pdf->SetDrawColor(232, 91, 20);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Helvetica', 'B', 9.2);
            $pdf->Cell(38, 7.8, 'TOTAL USD:', 1, 0, 'R', true);
            $pdf->Cell(32, 7.8, '$' . number_format((float)$calc['total_usd'], 2) . ' ', 1, 1, 'R', true);
            $totalsEndPage = $pdf->PageNo();
            $totalsEndY = $pdf->GetY();

            // 2) Dibujar Condiciones / Observaciones a la izquierda comenzando exactamente en $ySummaryTop
            if ($combinedNotes !== '') {
                $pdf->SetXY($margin, $ySummaryTop);
                $pdf->SetFont('Helvetica', 'B', 8.0);
                $pdf->SetTextColor(55, 65, 74);
                $pdf->Cell($notesW, 4.8, starfi_quote_pdf_enc('CONDICIONES COMERCIALES Y OBSERVACIONES:'), 0, 1, 'L');
                $pdf->SetX($margin);
                $pdf->SetFont('Helvetica', '', 7.8);
                $pdf->SetTextColor(71, 85, 105);
                $pdf->MultiCell($notesW, 4.2, starfi_quote_pdf_enc($combinedNotes), 0, 'L');
            }

            if ($pdf->PageNo() === $totalsEndPage && $pdf->GetY() < $totalsEndY) {
                $pdf->SetY($totalsEndY);
            }

            return $pdf->Output('S');
        } finally {
            foreach ($tmpLogoCleanup as $tf) {
                if (is_string($tf) && $tf !== '' && is_file($tf)) {
                    @unlink($tf);
                }
            }
        }
    }

    /**
     * Genera la cotización PDF membretada por sede, la despacha al WhatsApp del cliente (si aplica),
     * registra el mensaje DOCUMENTO en el chat y actualiza automáticamente el Embudo Kanban (COTIZADO + monto USD).
     */
    public static function createAndDispatchQuickQuote($con, array $agenteInfo, array $payload, bool $sendWhatsapp = true): array
    {
        if (!$con) {
            return ['ok' => false, 'http_code' => 500, 'message' => 'Sin conexión a base de datos.'];
        }
        self::ensureSchema($con);
        starfi_ensure_sprint2_schema($con);

        $agenteId = intval($agenteInfo['id'] ?? 0);
        $rol = strtoupper(trim((string)($agenteInfo['rol'] ?? 'AGENTE')));
        $userSede = intval($agenteInfo['id_sede'] ?? 0);
        $isMaster = ($agenteId === 1 || $rol === 'MASTER' || $rol === 'MASTER CI' || ($rol === 'ADMIN' && $userSede === 0));
        $isAdmin = (!$isMaster && in_array($rol, ['ADMINISTRADOR', 'ADMIN', 'GERENTE'], true));

        if ($agenteId <= 0) {
            return ['ok' => false, 'http_code' => 401, 'message' => 'Sesión expirada o inválida.'];
        }

        $conversacionId = intval($payload['conversacion_id'] ?? 0);
        $clienteId = intval($payload['cliente_id'] ?? 0);
        if ($conversacionId <= 0 && $clienteId <= 0) {
            return ['ok' => false, 'http_code' => 400, 'message' => 'Debes seleccionar una conversación o cliente para emitir la cotización.'];
        }

        $rawItems = $payload['items'] ?? [];
        if (is_string($rawItems)) {
            $rawItems = json_decode($rawItems, true);
        }
        if (!is_array($rawItems)) {
            $rawItems = [];
        }

        $descuentoUsd = floatval($payload['descuento_usd'] ?? 0);
        $aplicaIva = !empty($payload['aplica_iva']) && intval($payload['aplica_iva']) === 1;
        $ivaPct = isset($payload['iva_pct']) ? floatval($payload['iva_pct']) : 16.0;

        $calc = self::calculateQuoteTotals($rawItems, $descuentoUsd, $aplicaIva, $ivaPct);
        if (empty($calc['ok'])) {
            return ['ok' => false, 'http_code' => 400, 'message' => $calc['message']];
        }

        // Resolver contexto de conversación/cliente y validar RBAC Multi-Sede
        $chatRow = null;
        if ($conversacionId > 0) {
            $stmtC = $con->prepare("
                SELECT c.id, c.id_cliente, c.id_agente, c.estado,
                       cl.nombre AS cliente_nombre, cl.numero_whatsapp, cl.email AS cliente_email, cl.id_empresa,
                       l.id AS id_linea, l.id_sede, l.meta_token, l.meta_app_id AS phone_number_id
                FROM conversaciones c
                JOIN clientes_contactos cl ON c.id_cliente = cl.id
                JOIN lineas_whatsapp l ON c.id_linea = l.id
                WHERE c.id = ?
                LIMIT 1
            ");
            if ($stmtC) {
                $stmtC->bind_param("i", $conversacionId);
                $stmtC->execute();
                $rC = $stmtC->get_result();
                $chatRow = ($rC && $rC->num_rows > 0) ? $rC->fetch_assoc() : null;
                $stmtC->close();
            }
            if (!$chatRow) {
                return ['ok' => false, 'http_code' => 404, 'message' => 'Conversación no encontrada.'];
            }
            $chatSede = intval($chatRow['id_sede'] ?? 0);
            if (!$isMaster) {
                if ($userSede <= 0 || $chatSede !== $userSede) {
                    return ['ok' => false, 'http_code' => 403, 'message' => 'No tienes autorización para cotizar en una conversación de otra sede.'];
                }
                if (!$isAdmin) {
                    $convAg = intval($chatRow['id_agente'] ?? 0);
                    if ($convAg > 0 && $convAg !== $agenteId && ($chatRow['estado'] ?? '') !== 'ESPERA_ASIGNACION') {
                        return ['ok' => false, 'http_code' => 403, 'message' => 'Esta conversación pertenece a otro asesor.'];
                    }
                }
            }
            $clienteId = intval($chatRow['id_cliente']);
        } else {
            $stmtCl = $con->prepare("SELECT id, id_empresa, id_sede, nombre AS cliente_nombre, numero_whatsapp, email AS cliente_email FROM clientes_contactos WHERE id = ? LIMIT 1");
            $cliOnly = null;
            if ($stmtCl) {
                $stmtCl->bind_param("i", $clienteId);
                $stmtCl->execute();
                $rCl = $stmtCl->get_result();
                $cliOnly = ($rCl && $rCl->num_rows > 0) ? $rCl->fetch_assoc() : null;
                $stmtCl->close();
            }
            if (!$cliOnly) {
                return ['ok' => false, 'http_code' => 404, 'message' => 'Cliente no encontrado.'];
            }
            $cliSede = intval($cliOnly['id_sede'] ?? 0);
            if ($cliSede <= 0) {
                // Intentar resolver sede desde la conversación más reciente del cliente
                $stmtCvS = $con->prepare("SELECT l.id_sede FROM conversaciones c JOIN lineas_whatsapp l ON c.id_linea = l.id WHERE c.id_cliente = ? ORDER BY c.id DESC LIMIT 1");
                if ($stmtCvS) {
                    $stmtCvS->bind_param("i", $clienteId);
                    $stmtCvS->execute();
                    $rCvS = $stmtCvS->get_result();
                    if ($rCvS && ($rwCvS = $rCvS->fetch_assoc())) {
                        $cliSede = intval($rwCvS['id_sede'] ?? 0);
                    }
                    $stmtCvS->close();
                }
            }
            if ($cliSede <= 0 && $userSede > 0) {
                $cliSede = $userSede;
            }
            if (!$isMaster && ($userSede <= 0 || ($cliSede > 0 && $cliSede !== $userSede))) {
                return ['ok' => false, 'http_code' => 403, 'message' => 'No tienes autorización para cotizar a un cliente de otra sede.'];
            }
            $chatRow = [
                'id'              => 0,
                'id_cliente'      => intval($cliOnly['id']),
                'id_empresa'      => intval($cliOnly['id_empresa'] ?? 1),
                'id_sede'         => max(0, $cliSede),
                'cliente_nombre'  => $cliOnly['cliente_nombre'] ?? '',
                'numero_whatsapp' => $cliOnly['numero_whatsapp'] ?? '',
                'cliente_email'   => $cliOnly['cliente_email'] ?? '',
                'meta_token'      => '',
                'phone_number_id' => '',
            ];
        }

        // Si se solicita despacho WhatsApp directo, verificar ventana abierta de 24h cuando hay conversación
        if ($sendWhatsapp && $conversacionId > 0) {
            $stmtW = $con->prepare("SELECT UNIX_TIMESTAMP(MAX(timestamp)) AS last_cli FROM mensajes_y_eventos WHERE id_conversacion = ? AND origen = 'CLIENTE'");
            $lastCliEp = 0;
            if ($stmtW) {
                $stmtW->bind_param("i", $conversacionId);
                $stmtW->execute();
                $rW = $stmtW->get_result();
                if ($rW && ($rwW = $rW->fetch_assoc()) && !empty($rwW['last_cli'])) {
                    $lastCliEp = intval($rwW['last_cli']);
                }
                $stmtW->close();
            }
            if ($lastCliEp <= 0 || ($lastCliEp + 86400) <= time()) {
                return [
                    'ok'        => false,
                    'http_code' => 200,
                    'code'      => 'WINDOW_24H_EXPIRED',
                    'message'   => 'La ventana gratuita de 24h de Meta está cerrada. Reactiva primero el chat con una Plantilla Oficial Meta antes de enviar el PDF por WhatsApp.',
                ];
            }
        }

        $idSede = max(0, intval($chatRow['id_sede'] ?? 0));
        $idEmpresa = max(1, intval($chatRow['id_empresa'] ?? 1));
        $validezDias = max(1, min(90, intval($payload['validez_dias'] ?? 7)));
        $condiciones = trim((string)($payload['condiciones'] ?? 'Precios expresados en USD. Cotización sujeta a disponibilidad de inventario al momento del pago.'));
        $notas = trim((string)($payload['notas'] ?? ''));

        $letterhead = self::resolveSedeLetterhead($con, $idSede, $idEmpresa, $agenteId);

        // Transacción atómica compatible con transacciones externas de certificación QA (SAVEPOINT literal sin interpolación)
        $ownTx = !(function_exists('starfi_tx_is_active') && starfi_tx_is_active($con));
        if ($ownTx) {
            $con->begin_transaction();
            if (function_exists('starfi_tx_set_active')) {
                starfi_tx_set_active($con, true);
            }
        } else {
            @$con->query('SAVEPOINT sp_starfi_quick_quote');
        }

        // QA-BUG-CRM6-03: Inicializar $pdfAbsPath fuera del try para limpieza garantizada ante rollback/excepción
        $pdfAbsPath = '';

        try {
            // Bloqueo pesimista efectivo (CHK-SEC-05): sobre `sedes` si id_sede > 0, con fallback garantizado sobre `config_sla` id_sede = 0
            $lockedRows = 0;
            if ($idSede > 0) {
                $stmtLock = $con->prepare("SELECT id FROM sedes WHERE id = ? FOR UPDATE");
                if ($stmtLock) {
                    $stmtLock->bind_param("i", $idSede);
                    $stmtLock->execute();
                    $rLock = $stmtLock->get_result();
                    $lockedRows = $rLock ? $rLock->num_rows : 0;
                    $stmtLock->close();
                }
            }
            if ($lockedRows === 0) {
                $stmtLockZero = $con->prepare("SELECT id_sede FROM config_sla WHERE id_sede = 0 FOR UPDATE");
                if ($stmtLockZero) {
                    $stmtLockZero->execute();
                    $rZero = $stmtLockZero->get_result();
                    if ($rZero) {
                        $rZero->free();
                    }
                    $stmtLockZero->close();
                }
            }

            // QA-BUG-CRM6-03: Correlativo diario MAX(...) + 1 inmune a eliminaciones intermedias
            $prefixDay = 'COT-S' . $idSede . '-' . date('Ymd') . '-';
            $likeDay = $prefixDay . '%';
            $seqNext = 1;
            $stmtSeq = $con->prepare("
                SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo_cotizacion, '-', -1) AS UNSIGNED)), 0) AS max_seq
                FROM cotizaciones_rapidas
                WHERE id_sede = ? AND codigo_cotizacion LIKE ?
            ");
            if ($stmtSeq) {
                $stmtSeq->bind_param("is", $idSede, $likeDay);
                $stmtSeq->execute();
                $rSeq = $stmtSeq->get_result();
                if ($rSeq && ($rwSeq = $rSeq->fetch_assoc())) {
                    $seqNext = intval($rwSeq['max_seq'] ?? 0) + 1;
                }
                $stmtSeq->close();
            }
            $codigoCot = $prefixDay . str_pad((string)$seqNext, 4, '0', STR_PAD_LEFT);

            $quoteMeta = [
                'codigo'        => $codigoCot,
                'fecha_emision' => date('d/m/Y'),
                'fecha_validez' => date('d/m/Y', strtotime("+{$validezDias} days")),
                'validez_dias'  => $validezDias,
                'condiciones'   => $condiciones,
                'notas'         => $notas,
            ];
            $clientInfo = [
                'nombre'          => $chatRow['cliente_nombre'] ?: ('+' . $chatRow['numero_whatsapp']),
                'numero_whatsapp' => $chatRow['numero_whatsapp'] ?? '',
                'email'           => $chatRow['cliente_email'] ?? '',
            ];

            $pdfBinary = self::renderQuotePdfBinary($letterhead, $clientInfo, $quoteMeta, $calc);
            if (substr($pdfBinary, 0, 5) !== '%PDF-') {
                throw new RuntimeException('El motor FPDF no generó un binario PDF válido.');
            }

            $uploadsDir = realpath(__DIR__ . '/../../assets/uploads') ?: (__DIR__ . '/../../assets/uploads');
            if (!is_dir($uploadsDir)) {
                @mkdir($uploadsDir, 0775, true);
            }
            $safeCodeSlug = preg_replace('/[^A-Za-z0-9_-]/', '_', $codigoCot);
            $pdfFilename = 'media_cot_' . $safeCodeSlug . '_' . bin2hex(random_bytes(3)) . '.pdf';
            $pdfAbsPath = rtrim($uploadsDir, '/\\') . DIRECTORY_SEPARATOR . $pdfFilename;
            if (@file_put_contents($pdfAbsPath, $pdfBinary) === false) {
                $pdfAbsPath = '';
                throw new RuntimeException('No se pudo escribir el archivo PDF de cotización en disco.');
            }
            $pdfRelDb = '../../assets/uploads/' . $pdfFilename;

            if (PHP_SAPI === 'cli' && !empty($payload['__qa_force_rollback_after_pdf'])) {
                throw new RuntimeException('Fallo transaccional simulado post-escritura PDF para prueba QA.');
            }

            // Sincronizar oportunidad en Embudo de Ventas (pipeline_oportunidades) con SELECT ... FOR UPDATE
            $totalUsd = (float)$calc['total_usd'];
            $tituloDeal = mb_substr("Cotización {$codigoCot} - " . ($clientInfo['nombre'] ?: 'Cliente'), 0, 195, 'UTF-8');
            $convNullable = $conversacionId > 0 ? $conversacionId : null;

            $dealBefore = null;
            $dealId = 0;
            $stmtDealLock = $con->prepare("
                SELECT * FROM pipeline_oportunidades
                WHERE id_sede = ? AND ((? > 0 AND id_conversacion = ?) OR (? > 0 AND id_cliente = ?))
                ORDER BY (etapa NOT IN ('GANADO','PERDIDO')) DESC, id DESC
                LIMIT 1
                FOR UPDATE
            ");
            if ($stmtDealLock) {
                $stmtDealLock->bind_param("iiiii", $idSede, $conversacionId, $conversacionId, $clienteId, $clienteId);
                $stmtDealLock->execute();
                $rDL = $stmtDealLock->get_result();
                if ($rDL && ($rwDL = $rDL->fetch_assoc())) {
                    $dealBefore = $rwDL;
                    $dealId = intval($rwDL['id']);
                }
                $stmtDealLock->close();
            }

            $etapaNueva = 'COTIZADO';
            $probNueva = 45;
            if ($dealId > 0) {
                $stmtUpDeal = $con->prepare("
                    UPDATE pipeline_oportunidades
                    SET etapa = ?, monto_estimado_usd = ?, probabilidad = ?, titulo = ?, id_conversacion = IFNULL(id_conversacion, ?), fecha_cierre = NULL
                    WHERE id = ?
                ");
                if ($stmtUpDeal) {
                    $stmtUpDeal->bind_param("sdisii", $etapaNueva, $totalUsd, $probNueva, $tituloDeal, $convNullable, $dealId);
                    $stmtUpDeal->execute();
                    $stmtUpDeal->close();
                }
            } else {
                $stmtInDeal = $con->prepare("
                    INSERT INTO pipeline_oportunidades (id_sede, id_cliente, id_conversacion, id_agente, titulo, etapa, monto_estimado_usd, probabilidad)
                    VALUES (?, ?, ?, ?, ?, 'COTIZADO', ?, 45)
                ");
                if ($stmtInDeal) {
                    $stmtInDeal->bind_param("iiiisd", $idSede, $clienteId, $convNullable, $agenteId, $tituloDeal, $totalUsd);
                    $stmtInDeal->execute();
                    $dealId = intval($stmtInDeal->insert_id);
                    $stmtInDeal->close();
                }
            }

            // Persistir cabecera y líneas de cotización
            $estadoCot = 'EMITIDA';
            $aplicaIvaInt = intval($calc['aplica_iva']);
            $subUsd = (float)$calc['subtotal_usd'];
            $descUsd = (float)$calc['descuento_usd'];
            $ivaPctVal = (float)$calc['iva_pct'];
            $ivaMontoVal = (float)$calc['iva_monto_usd'];

            $stmtInsCot = $con->prepare("
                INSERT INTO cotizaciones_rapidas (
                    codigo_cotizacion, id_empresa, id_sede, id_cliente, id_conversacion, id_oportunidad, id_agente,
                    moneda, subtotal_usd, descuento_usd, aplica_iva, iva_pct, iva_monto_usd, total_usd,
                    validez_dias, condiciones, notas, pdf_ruta, estado
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'USD', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmtInsCot) {
                throw new RuntimeException('Error preparando registro de cotización.');
            }
            $dealNullable = $dealId > 0 ? $dealId : null;
            $stmtInsCot->bind_param(
                "siiiiiiddidddissss",
                $codigoCot,
                $idEmpresa,
                $idSede,
                $clienteId,
                $convNullable,
                $dealNullable,
                $agenteId,
                $subUsd,
                $descUsd,
                $aplicaIvaInt,
                $ivaPctVal,
                $ivaMontoVal,
                $totalUsd,
                $validezDias,
                $condiciones,
                $notas,
                $pdfRelDb,
                $estadoCot
            );
            if (!$stmtInsCot->execute()) {
                $errSql = $stmtInsCot->error;
                $stmtInsCot->close();
                throw new RuntimeException('No se pudo guardar la cotización (' . $errSql . ').');
            }
            $idCotizacion = intval($stmtInsCot->insert_id);
            $stmtInsCot->close();

            $stmtItem = $con->prepare("
                INSERT INTO cotizaciones_rapidas_items (id_cotizacion, orden, descripcion, cantidad, precio_unitario_usd, subtotal_usd)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            if ($stmtItem) {
                foreach ($calc['items'] as $itRow) {
                    $ord = intval($itRow['orden']);
                    $dsc = (string)$itRow['descripcion'];
                    $qty = (float)$itRow['cantidad'];
                    $unt = (float)$itRow['precio_unitario_usd'];
                    $sbt = (float)$itRow['subtotal_usd'];
                    $stmtItem->bind_param("iisddd", $idCotizacion, $ord, $dsc, $qty, $unt, $sbt);
                    $stmtItem->execute();
                }
                $stmtItem->close();
            }

            if ($ownTx) {
                $con->commit();
                if (function_exists('starfi_tx_set_active')) {
                    starfi_tx_set_active($con, false);
                }
            } else {
                @$con->query('RELEASE SAVEPOINT sp_starfi_quick_quote');
            }
        } catch (Throwable $e) {
            if ($ownTx) {
                @$con->rollback();
                if (function_exists('starfi_tx_set_active')) {
                    starfi_tx_set_active($con, false);
                }
            } else {
                @$con->query('ROLLBACK TO SAVEPOINT sp_starfi_quick_quote');
            }
            if ($pdfAbsPath !== '' && is_file($pdfAbsPath)) {
                @unlink($pdfAbsPath);
            }
            starfi_log_exception('bandeja', 'Error en QuickQuotePdfService::createAndDispatchQuickQuote', ['error' => $e->getMessage()]);
            return ['ok' => false, 'http_code' => 500, 'message' => 'No se pudo generar la cotización PDF en este momento.'];
        }

        // Despacho por WhatsApp Cloud API v23.0 (fuera del lock FOR UPDATE para no retener filas en MySQL durante red externa)
        $wamid = null;
        $estadoEnvioMsg = 'ENVIADO';
        $errorDetalleMsg = null;
        $metaToken = trim((string)($chatRow['meta_token'] ?? ''));
        $phoneId = trim((string)($chatRow['phone_number_id'] ?? ''));
        $destinoWa = preg_replace('/[^0-9]/', '', (string)($chatRow['numero_whatsapp'] ?? ''));
        $totalFmt = number_format($totalUsd, 2);
        $captionWa = "📄 Cotización {$codigoCot} — {$letterhead['nombre_sede']} · Total: \${$totalFmt} USD (Válida por {$validezDias} días).";

        if ($sendWhatsapp && $metaToken !== '' && $metaToken !== 'temp_token' && $phoneId !== '' && $destinoWa !== '') {
            $apiVer = defined('META_API_VERSION') ? META_API_VERSION : 'v23.0';
            $chMedia = curl_init("https://graph.facebook.com/{$apiVer}/{$phoneId}/media");
            curl_setopt_array($chMedia, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => [
                    'messaging_product' => 'whatsapp',
                    'type'              => 'application/pdf',
                    'file'              => new CURLFile($pdfAbsPath, 'application/pdf', $codigoCot . '.pdf'),
                ],
                CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $metaToken],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => 12,
            ]);
            $mediaRaw = curl_exec($chMedia);
            $mediaCode = curl_getinfo($chMedia, CURLINFO_HTTP_CODE);
            curl_close($chMedia);
            $mediaJson = json_decode((string)$mediaRaw, true);

            if (!empty($mediaJson['id'])) {
                $docPayload = [
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => 'individual',
                    'to'                => $destinoWa,
                    'type'              => 'document',
                    'document'          => [
                        'id'       => $mediaJson['id'],
                        'filename' => $codigoCot . '.pdf',
                        'caption'  => $captionWa,
                    ],
                ];
                $chMsg = curl_init("https://graph.facebook.com/{$apiVer}/{$phoneId}/messages");
                curl_setopt_array($chMsg, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($docPayload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $metaToken,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    CURLOPT_TIMEOUT        => 12,
                ]);
                $msgRaw = curl_exec($chMsg);
                $msgCode = curl_getinfo($chMsg, CURLINFO_HTTP_CODE);
                curl_close($chMsg);
                $msgJson = json_decode((string)$msgRaw, true);
                if (!empty($msgJson['messages'][0]['id'])) {
                    $wamid = (string)$msgJson['messages'][0]['id'];
                    $estadoEnvioMsg = 'ENVIADO';
                } else {
                    $estadoEnvioMsg = 'FALLIDO';
                    $errorDetalleMsg = isset($msgJson['error']['message'])
                        ? ('Meta API (' . $msgCode . '): ' . $msgJson['error']['message'])
                        : ('Fallo HTTP ' . $msgCode . ' enviando documento PDF a WhatsApp.');
                }
            } else {
                $estadoEnvioMsg = 'FALLIDO';
                $errorDetalleMsg = isset($mediaJson['error']['message'])
                    ? ('Meta Media (' . $mediaCode . '): ' . $mediaJson['error']['message'])
                    : ('Fallo HTTP ' . $mediaCode . ' subiendo PDF a Meta.');
            }
        }

        // Registrar mensaje DOCUMENTO y evento de Pipeline en la conversación
        $localMsgId = 0;
        if ($conversacionId > 0) {
            $docLabel = "📄 Cotización {$codigoCot} (\${$totalFmt} USD)";
            $mimePdf = 'application/pdf';
            $stmtDocMsg = $con->prepare("
                INSERT INTO mensajes_y_eventos (
                    id_conversacion, id_mensaje_meta, id_agente, tipo, origen,
                    contenido, url_archivo, mime_type, estado_envio, error_detalle
                ) VALUES (?, ?, ?, 'DOCUMENTO', 'AGENTE', ?, ?, ?, ?, ?)
            ");
            if ($stmtDocMsg) {
                $stmtDocMsg->bind_param(
                    "isisssss",
                    $conversacionId,
                    $wamid,
                    $agenteId,
                    $docLabel,
                    $pdfRelDb,
                    $mimePdf,
                    $estadoEnvioMsg,
                    $errorDetalleMsg
                );
                if ($stmtDocMsg->execute()) {
                    $localMsgId = intval($stmtDocMsg->insert_id);
                }
                $stmtDocMsg->close();
            }

            $evPipe = "💼 Cotización {$codigoCot} generada por \${$totalFmt} USD · Embudo actualizado automáticamente a [COTIZADO].";
            $stmtEvP = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, 'EVENTO_SISTEMA', 'EVENTO_SISTEMA', ?)");
            $lastInsertedId = $localMsgId;
            if ($stmtEvP) {
                $stmtEvP->bind_param("is", $conversacionId, $evPipe);
                if ($stmtEvP->execute()) {
                    $lastInsertedId = max($lastInsertedId, intval($stmtEvP->insert_id));
                }
                $stmtEvP->close();
            }

            $stmtTakeC = $con->prepare("
                UPDATE conversaciones
                SET estado = CASE WHEN estado = 'ESPERA_ASIGNACION' THEN 'ATENDIENDO' ELSE estado END,
                    id_agente = IFNULL(id_agente, ?),
                    fecha_primera_respuesta = IFNULL(fecha_primera_respuesta, NOW()),
                    mensajes_no_leidos = 0,
                    ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), ?)
                WHERE id = ?
            ");
            if ($stmtTakeC) {
                $stmtTakeC->bind_param("iii", $agenteId, $lastInsertedId, $conversacionId);
                $stmtTakeC->execute();
                $stmtTakeC->close();
            }
        }

        $estadoFinalCot = ($wamid !== null) ? 'ENVIADA_WA' : 'EMITIDA';
        $stmtUpCot = $con->prepare("UPDATE cotizaciones_rapidas SET id_mensaje_meta = ?, id_mensaje_local = ?, estado = ? WHERE id = ?");
        if ($stmtUpCot) {
            $msgLocNull = $localMsgId > 0 ? $localMsgId : null;
            $stmtUpCot->bind_param("sisi", $wamid, $msgLocNull, $estadoFinalCot, $idCotizacion);
            $stmtUpCot->execute();
            $stmtUpCot->close();
        }

        $dealAfter = [
            'id'                 => $dealId,
            'id_sede'            => $idSede,
            'id_cliente'         => $clienteId,
            'id_conversacion'    => $conversacionId > 0 ? $conversacionId : null,
            'titulo'             => $tituloDeal,
            'etapa'              => 'COTIZADO',
            'monto_estimado_usd' => $totalUsd,
            'probabilidad'       => 45,
        ];

        registrar_auditoria(
            'bandeja',
            'EMITIR_COTIZACION_PDF',
            "Cotización rápida {$codigoCot} generada (\${$totalFmt} USD · Sede #{$idSede})",
            $dealBefore,
            [
                'id_cotizacion'     => $idCotizacion,
                'codigo_cotizacion' => $codigoCot,
                'total_usd'         => $totalUsd,
                'items_count'       => count($calc['items']),
                'pdf_filename'      => $pdfFilename,
                'estado_envio_wa'   => $estadoEnvioMsg,
                'wamid'             => $wamid,
                'deal_id'           => $dealId,
                'etapa_pipeline'    => 'COTIZADO',
            ],
            $con,
            $agenteId,
            $idSede ?: null
        );

        return [
            'ok'                => true,
            'id_cotizacion'     => $idCotizacion,
            'codigo_cotizacion' => $codigoCot,
            'total_usd'         => $totalUsd,
            'subtotal_usd'      => (float)$calc['subtotal_usd'],
            'descuento_usd'     => (float)$calc['descuento_usd'],
            'iva_monto_usd'     => (float)$calc['iva_monto_usd'],
            'pdf_filename'      => $pdfFilename,
            'pdf_abs_path'      => $pdfAbsPath,
            'pdf_rel_url'       => $pdfRelDb,
            'download_url'      => ($conversacionId > 0)
                ? ('../../get_media.php?id=' . rawurlencode($pdfFilename) . '&chat_id=' . $conversacionId)
                : '',
            'message_id'        => $localMsgId,
            'wamid'             => $wamid,
            'estado_envio'      => $estadoEnvioMsg,
            'wa_warning'        => ($estadoEnvioMsg === 'FALLIDO' ? $errorDetalleMsg : null),
            'deal'              => $dealAfter,
            'message'           => "Cotización {$codigoCot} (\${$totalFmt} USD) generada y registrada en el Embudo.",
        ];
    }
}
