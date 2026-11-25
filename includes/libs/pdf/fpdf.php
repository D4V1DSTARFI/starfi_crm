<?php
/**
 * FPDF 1.85 — Motor Oficial de Generación de Documentos PDF Binarios (STARFI 2.0)
 * Basado en FPDF v1.85 por Olivier Plathey (Licencia Permisiva FPDF).
 * Incluye métricas Type1 estándar integradas (Helvetica, Courier, Times) y soporte nativo
 * para imágenes JPEG y PNG de 8 bits (Grayscale, Palette, RGB y RGBA con canal Alfa SMask).
 */

if (!defined('FPDF_VERSION')) {
    define('FPDF_VERSION', '1.85');
}

if (!class_exists('FPDF')) {
class FPDF
{
    protected $page;               // número de página actual
    protected $n;                  // número de objeto actual
    protected $offsets;            // arreglo de offsets de objetos PDF
    protected $buffer;             // buffer en memoria del PDF
    protected $pages;              // arreglo con el contenido de cada página
    protected $state;              // estado actual del documento
    protected $compress;           // compresión deflate activa
    protected $k;                  // factor de escala (puntos por unidad)
    protected $DefOrientation;     // orientación por defecto
    protected $CurOrientation;     // orientación actual
    protected $StdPageSizes;       // tamaños estándar de hoja
    protected $DefPageSize;        // tamaño por defecto
    protected $CurPageSize;        // tamaño actual
    protected $CurRotation;        // rotación actual
    protected $PageInfo;           // metadatos por página
    protected $wPt, $hPt;          // dimensiones actuales en puntos
    protected $w, $h;              // dimensiones actuales en unidad del usuario
    protected $lMargin;            // margen izquierdo
    protected $tMargin;            // margen superior
    protected $rMargin;            // margen derecho
    protected $bMargin;            // margen de salto de página
    protected $cMargin;            // margen interno de celda
    protected $x, $y;              // posición actual X, Y
    protected $lasth;              // altura de la última celda impresa
    protected $LineWidth;          // grosor de línea
    protected $fontpath;           // directorio de fuentes
    protected $CoreFonts;          // fuentes estándar PDF Type1
    protected $fonts;              // fuentes usadas
    protected $FontFiles;          // archivos de fuentes
    protected $encodings;          // codificaciones
    protected $cmaps;              // CMap
    protected $FontFamily;         // familia tipográfica actual
    protected $FontStyle;          // estilo actual ('', 'B', 'I', 'BI')
    protected $underline;          // subrayado activo
    protected $CurrentFont;        // información de fuente activa
    protected $FontSizePt;         // tamaño de fuente en puntos
    protected $FontSize;           // tamaño de fuente en unidades de usuario
    protected $DrawColor;          // comando de color de trazo
    protected $FillColor;          // comando de color de relleno
    protected $TextColor;          // comando de color de texto
    protected $ColorFlag;          // indica si FillColor != TextColor
    protected $WithAlpha;          // indica presencia de canal alfa PNG (PDF 1.4)
    protected $ws;                 // espaciado de palabras
    protected $images;             // imágenes embebidas
    protected $PageLinks;          // enlaces en páginas
    protected $links;              // enlaces internos
    protected $AutoPageBreak;      // salto de página automático
    protected $PageBreakTrigger;   // umbral Y de salto automático
    protected $InHeader;           // bandera renderizando Header()
    protected $InFooter;           // bandera renderizando Footer()
    protected $AliasNbPages;       // alias para total de páginas
    protected $ZoomMode;           // modo de zoom
    protected $LayoutMode;         // modo de vista
    protected $metadata;           // diccionario Info PDF
    protected $PDFVersion;         // versión PDF ('1.3' o '1.4')

    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4')
    {
        $this->page = 0;
        $this->n = 2;
        $this->buffer = '';
        $this->pages = [];
        $this->PageInfo = [];
        $this->fonts = [];
        $this->FontFiles = [];
        $this->encodings = [];
        $this->cmaps = [];
        $this->images = [];
        $this->links = [];
        $this->InHeader = false;
        $this->InFooter = false;
        $this->lasth = 0;
        $this->FontFamily = '';
        $this->FontStyle = '';
        $this->FontSizePt = 12;
        $this->underline = false;
        $this->DrawColor = '0 G';
        $this->FillColor = '0 g';
        $this->TextColor = '0 g';
        $this->ColorFlag = false;
        $this->WithAlpha = false;
        $this->ws = 0;
        $this->fontpath = __DIR__ . '/font/';

        $this->CoreFonts = [
            'courier'      => 'Courier',
            'courierB'     => 'Courier-Bold',
            'courierI'     => 'Courier-Oblique',
            'courierBI'    => 'Courier-BoldOblique',
            'helvetica'    => 'Helvetica',
            'helveticaB'   => 'Helvetica-Bold',
            'helveticaI'   => 'Helvetica-Oblique',
            'helveticaBI'  => 'Helvetica-BoldOblique',
            'times'        => 'Times-Roman',
            'timesB'       => 'Times-Bold',
            'timesI'       => 'Times-Italic',
            'timesBI'      => 'Times-BoldItalic',
            'symbol'       => 'Symbol',
            'zapfdingbats' => 'ZapfDingbats',
        ];

        if ($unit === 'pt') {
            $this->k = 1;
        } elseif ($unit === 'mm') {
            $this->k = 72 / 25.4;
        } elseif ($unit === 'cm') {
            $this->k = 72 / 2.54;
        } elseif ($unit === 'in') {
            $this->k = 72;
        } else {
            $this->Error('Unidad incorrecta: ' . $unit);
        }

        $this->StdPageSizes = [
            'a3'     => [841.89, 1190.55],
            'a4'     => [595.28, 841.89],
            'a5'     => [420.94, 595.28],
            'letter' => [612, 792],
            'legal'  => [612, 1008],
        ];
        $size = $this->_getpagesize($size);
        $this->DefPageSize = $size;
        $this->CurPageSize = $size;

        $orientation = strtolower((string)$orientation);
        if ($orientation === 'p' || $orientation === 'portrait') {
            $this->DefOrientation = 'P';
            $this->w = $size[0];
            $this->h = $size[1];
        } elseif ($orientation === 'l' || $orientation === 'landscape') {
            $this->DefOrientation = 'L';
            $this->w = $size[1];
            $this->h = $size[0];
        } else {
            $this->Error('Orientación incorrecta: ' . $orientation);
        }
        $this->CurOrientation = $this->DefOrientation;
        $this->wPt = $this->w * $this->k;
        $this->hPt = $this->h * $this->k;
        $this->CurRotation = 0;

        $margin = 28.35 / $this->k;
        $this->SetMargins($margin, $margin);
        $this->cMargin = $margin / 10;
        $this->LineWidth = .567 / $this->k;
        $this->SetAutoPageBreak(true, 2 * $margin);
        $this->SetDisplayMode('default');
        $this->SetCompression(true);
        $this->metadata = ['Producer' => 'FPDF ' . FPDF_VERSION];
        $this->PDFVersion = '1.3';
        $this->state = 0;
    }

    public function SetMargins($left, $top, $right = null)
    {
        $this->lMargin = $left;
        $this->tMargin = $top;
        if ($right === null) {
            $right = $left;
        }
        $this->rMargin = $right;
    }

    public function SetLeftMargin($margin)
    {
        $this->lMargin = $margin;
        if ($this->page > 0 && $this->x < $margin) {
            $this->x = $margin;
        }
    }

    public function SetTopMargin($margin)
    {
        $this->tMargin = $margin;
    }

    public function SetRightMargin($margin)
    {
        $this->rMargin = $margin;
    }

    public function SetAutoPageBreak($auto, $margin = 0)
    {
        $this->AutoPageBreak = (bool)$auto;
        $this->bMargin = $margin;
        $this->PageBreakTrigger = $this->h - $margin;
    }

    public function SetDisplayMode($zoom, $layout = 'default')
    {
        $this->ZoomMode = $zoom;
        $this->LayoutMode = $layout;
    }

    public function SetCompression($compress)
    {
        $this->compress = function_exists('gzcompress') ? (bool)$compress : false;
    }

    public function SetTitle($title, $isUTF8 = false)
    {
        $this->metadata['Title'] = $isUTF8 ? $title : $this->_utf8encode($title);
    }

    public function SetAuthor($author, $isUTF8 = false)
    {
        $this->metadata['Author'] = $isUTF8 ? $author : $this->_utf8encode($author);
    }

    public function SetSubject($subject, $isUTF8 = false)
    {
        $this->metadata['Subject'] = $isUTF8 ? $subject : $this->_utf8encode($subject);
    }

    public function SetKeywords($keywords, $isUTF8 = false)
    {
        $this->metadata['Keywords'] = $isUTF8 ? $keywords : $this->_utf8encode($keywords);
    }

    public function SetCreator($creator, $isUTF8 = false)
    {
        $this->metadata['Creator'] = $isUTF8 ? $creator : $this->_utf8encode($creator);
    }

    public function AliasNbPages($alias = '{nb}')
    {
        $this->AliasNbPages = $alias;
    }

    public function Error($msg)
    {
        throw new RuntimeException('FPDF error: ' . $msg);
    }

    public function Close()
    {
        if ($this->state === 3) {
            return;
        }
        if ($this->page === 0) {
            $this->AddPage();
        }
        $this->InFooter = true;
        $this->Footer();
        $this->InFooter = false;
        $this->_endpage();
        $this->_enddoc();
    }

    public function AddPage($orientation = '', $size = '', $rotation = 0)
    {
        if ($this->state === 3) {
            $this->Error('El documento está cerrado');
        }
        $family = $this->FontFamily;
        $style = $this->FontStyle . ($this->underline ? 'U' : '');
        $fontsize = $this->FontSizePt;
        $lw = $this->LineWidth;
        $dc = $this->DrawColor;
        $fc = $this->FillColor;
        $tc = $this->TextColor;
        $cf = $this->ColorFlag;
        if ($this->page > 0) {
            $this->InFooter = true;
            $this->Footer();
            $this->InFooter = false;
            $this->_endpage();
        }
        $this->_beginpage($orientation, $size, $rotation);
        $this->_out('2 J');
        $this->LineWidth = $lw;
        $this->_out(sprintf('%.2F w', $lw * $this->k));
        if ($family) {
            $this->SetFont($family, $style, $fontsize);
        }
        $this->DrawColor = $dc;
        if ($dc !== '0 G') {
            $this->_out($dc);
        }
        $this->FillColor = $fc;
        if ($fc !== '0 g') {
            $this->_out($fc);
        }
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
        $this->InHeader = true;
        $this->Header();
        $this->InHeader = false;
        if ($this->LineWidth != $lw) {
            $this->LineWidth = $lw;
            $this->_out(sprintf('%.2F w', $lw * $this->k));
        }
        if ($family) {
            $this->SetFont($family, $style, $fontsize);
        }
        if ($this->DrawColor != $dc) {
            $this->DrawColor = $dc;
            $this->_out($dc);
        }
        if ($this->FillColor != $fc) {
            $this->FillColor = $fc;
            $this->_out($fc);
        }
        $this->TextColor = $tc;
        $this->ColorFlag = $cf;
    }

    public function Header()
    {
    }

    public function Footer()
    {
    }

    public function PageNo()
    {
        return $this->page;
    }

    public function GetPageWidth()
    {
        return $this->w;
    }

    public function GetPageHeight()
    {
        return $this->h;
    }

    public function SetDrawColor($r, $g = null, $b = null)
    {
        if (($r === 0 && $g === 0 && $b === 0) || $g === null) {
            $this->DrawColor = sprintf('%.3F G', $r / 255);
        } else {
            $this->DrawColor = sprintf('%.3F %.3F %.3F RG', $r / 255, $g / 255, $b / 255);
        }
        if ($this->page > 0) {
            $this->_out($this->DrawColor);
        }
    }

    public function SetFillColor($r, $g = null, $b = null)
    {
        if (($r === 0 && $g === 0 && $b === 0) || $g === null) {
            $this->FillColor = sprintf('%.3F g', $r / 255);
        } else {
            $this->FillColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        }
        $this->ColorFlag = ($this->FillColor !== $this->TextColor);
        if ($this->page > 0) {
            $this->_out($this->FillColor);
        }
    }

    public function SetTextColor($r, $g = null, $b = null)
    {
        if (($r === 0 && $g === 0 && $b === 0) || $g === null) {
            $this->TextColor = sprintf('%.3F g', $r / 255);
        } else {
            $this->TextColor = sprintf('%.3F %.3F %.3F rg', $r / 255, $g / 255, $b / 255);
        }
        $this->ColorFlag = ($this->FillColor !== $this->TextColor);
    }

    public function GetStringWidth($s)
    {
        $s = (string)$s;
        $cw = &$this->CurrentFont['cw'];
        $w = 0;
        $l = strlen($s);
        for ($i = 0; $i < $l; $i++) {
            $ch = $s[$i];
            $w += isset($cw[$ch]) ? $cw[$ch] : 556;
        }
        return $w * $this->FontSize / 1000;
    }

    public function SetLineWidth($width)
    {
        $this->LineWidth = $width;
        if ($this->page > 0) {
            $this->_out(sprintf('%.2F w', $width * $this->k));
        }
    }

    public function Line($x1, $y1, $x2, $y2)
    {
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F l S', $x1 * $this->k, ($this->h - $y1) * $this->k, $x2 * $this->k, ($this->h - $y2) * $this->k));
    }

    public function Rect($x, $y, $w, $h, $style = '')
    {
        if ($style === 'F') {
            $op = 'f';
        } elseif ($style === 'FD' || $style === 'DF') {
            $op = 'B';
        } else {
            $op = 'S';
        }
        $this->_out(sprintf('%.2F %.2F %.2F %.2F re %s', $x * $this->k, ($this->h - $y) * $this->k, $w * $this->k, -$h * $this->k, $op));
    }

    public function SetFont($family, $style = '', $size = 0)
    {
        if ($family === '') {
            $family = $this->FontFamily;
        } else {
            $family = strtolower($family);
        }
        if ($family === 'arial' || $family === 'gotham' || $family === 'mont') {
            $family = 'helvetica';
        }
        $style = strtoupper((string)$style);
        if (strpos($style, 'U') !== false) {
            $this->underline = true;
            $style = str_replace('U', '', $style);
        } else {
            $this->underline = false;
        }
        if ($style === 'IB') {
            $style = 'BI';
        }
        if ($size == 0) {
            $size = $this->FontSizePt;
        }
        if ($this->FontFamily === $family && $this->FontStyle === $style && $this->FontSizePt == $size) {
            return;
        }

        $fontkey = $family . $style;
        if (!isset($this->CoreFonts[$fontkey])) {
            $family = 'helvetica';
            $fontkey = $family . $style;
            if (!isset($this->CoreFonts[$fontkey])) {
                $style = '';
                $fontkey = 'helvetica';
            }
        }
        if (!isset($this->fonts[$fontkey])) {
            $i = count($this->fonts) + 1;
            $this->fonts[$fontkey] = [
                'i'    => $i,
                'type' => 'core',
                'name' => $this->CoreFonts[$fontkey],
                'up'   => -100,
                'ut'   => 50,
                'cw'   => $this->_getCoreFontWidths($fontkey),
            ];
        }
        $this->FontFamily = $family;
        $this->FontStyle = $style;
        $this->FontSizePt = $size;
        $this->FontSize = $size / $this->k;
        $this->CurrentFont = &$this->fonts[$fontkey];
        if ($this->page > 0) {
            $this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
        }
    }

    public function SetFontSize($size)
    {
        if ($this->FontSizePt == $size) {
            return;
        }
        $this->FontSizePt = $size;
        $this->FontSize = $size / $this->k;
        if ($this->page > 0 && isset($this->CurrentFont['i'])) {
            $this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
        }
    }

    public function AddLink()
    {
        $n = count($this->links) + 1;
        $this->links[$n] = [0, 0];
        return $n;
    }

    public function SetLink($link, $y = 0, $page = -1)
    {
        if ($y == -1) {
            $y = $this->y;
        }
        if ($page == -1) {
            $page = $this->page;
        }
        $this->links[$link] = [$page, $y];
    }

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        $k = $this->k;
        if ($this->y + $h > $this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
            $x = $this->x;
            $ws = $this->ws;
            if ($ws > 0) {
                $this->ws = 0;
                $this->_out('0 Tw');
            }
            $this->AddPage($this->CurOrientation, $this->CurPageSize, $this->CurRotation);
            $this->x = $x;
            if ($ws > 0) {
                $this->ws = $ws;
                $this->_out(sprintf('%.3F Tw', $ws * $k));
            }
        }
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $s = '';
        if ($fill || $border === 1) {
            if ($fill) {
                $op = ($border === 1) ? 'B' : 'f';
            } else {
                $op = 'S';
            }
            $s = sprintf('%.2F %.2F %.2F %.2F re %s ', $this->x * $k, ($this->h - $this->y) * $k, $w * $k, -$h * $k, $op);
        }
        if (is_string($border)) {
            $x = $this->x;
            $y = $this->y;
            if (strpos($border, 'L') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y) * $k, $x * $k, ($this->h - ($y + $h)) * $k);
            }
            if (strpos($border, 'T') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - $y) * $k, ($x + $w) * $k, ($this->h - $y) * $k);
            }
            if (strpos($border, 'R') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', ($x + $w) * $k, ($this->h - $y) * $k, ($x + $w) * $k, ($this->h - ($y + $h)) * $k);
            }
            if (strpos($border, 'B') !== false) {
                $s .= sprintf('%.2F %.2F m %.2F %.2F l S ', $x * $k, ($this->h - ($y + $h)) * $k, ($x + $w) * $k, ($this->h - ($y + $h)) * $k);
            }
        }
        $txt = (string)$txt;
        if ($txt !== '') {
            if (!isset($this->CurrentFont)) {
                $this->SetFont('helvetica', '', 10);
            }
            if ($align === 'R') {
                $dx = $w - $this->cMargin - $this->GetStringWidth($txt);
            } elseif ($align === 'C') {
                $dx = ($w - $this->GetStringWidth($txt)) / 2;
            } else {
                $dx = $this->cMargin;
            }
            if ($this->ColorFlag) {
                $s .= 'q ' . $this->TextColor . ' ';
            }
            $s .= sprintf('BT %.2F %.2F Td (%s) Tj ET', ($this->x + $dx) * $k, ($this->h - ($this->y + .5 * $h + .3 * $this->FontSize)) * $k, $this->_escape($txt));
            if ($this->underline) {
                $s .= ' ' . $this->_dounderline($this->x + $dx, $this->y + .5 * $h + .3 * $this->FontSize, $txt);
            }
            if ($this->ColorFlag) {
                $s .= ' Q';
            }
        }
        if ($s !== '') {
            $this->_out($s);
        }
        $this->lasth = $h;
        if ($ln > 0) {
            $this->y += $h;
            if ($ln == 1) {
                $this->x = $this->lMargin;
            }
        } else {
            $this->x += $w;
        }
    }

    public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false)
    {
        if (!isset($this->CurrentFont)) {
            $this->SetFont('helvetica', '', 10);
        }
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string)$txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }
        $b = 0;
        if ($border) {
            if ($border === 1) {
                $border = 'LTRB';
                $b = 'LRT';
                $b2 = 'LR';
            } else {
                $b2 = '';
                if (strpos($border, 'L') !== false) {
                    $b2 .= 'L';
                }
                if (strpos($border, 'R') !== false) {
                    $b2 .= 'R';
                }
                $b = (strpos($border, 'T') !== false) ? $b2 . 'T' : $b2;
            }
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                if ($this->ws > 0) {
                    $this->ws = 0;
                    $this->_out('0 Tw');
                }
                $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl == 2) {
                    $b = $b2;
                }
                continue;
            }
            if ($c === ' ') {
                $sep = $i;
                $ls = $l;
                $ns++;
            }
            $l += isset($cw[$c]) ? $cw[$c] : 556;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                    if ($this->ws > 0) {
                        $this->ws = 0;
                        $this->_out('0 Tw');
                    }
                    $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                } else {
                    if ($align === 'J') {
                        $this->ws = ($ns > 1) ? ($wmax - $ls) / 1000 * $this->FontSize / ($ns - 1) : 0;
                        $this->_out(sprintf('%.3F Tw', $this->ws * $this->k));
                    }
                    $this->Cell($w, $h, substr($s, $j, $sep - $j), $b, 2, $align, $fill);
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl == 2) {
                    $b = $b2;
                }
            } else {
                $i++;
            }
        }
        if ($this->ws > 0) {
            $this->ws = 0;
            $this->_out('0 Tw');
        }
        if ($border && strpos((string)$border, 'B') !== false) {
            $b .= 'B';
        }
        $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
        $this->x = $this->lMargin;
    }

    public function Ln($h = null)
    {
        $this->x = $this->lMargin;
        if ($h === null) {
            $this->y += $this->lasth;
        } else {
            $this->y += $h;
        }
    }

    public function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '')
    {
        if ($file === '') {
            $this->Error('Ruta de imagen vacía');
        }
        if (!isset($this->images[$file])) {
            if ($type === '') {
                $pos = strrpos($file, '.');
                if ($pos === false) {
                    $this->Error('Archivo de imagen sin extensión: ' . $file);
                }
                $type = substr($file, $pos + 1);
            }
            $type = strtolower($type);
            if ($type === 'jpeg') {
                $type = 'jpg';
            }
            if ($type === 'jpg') {
                $info = $this->_parsejpg($file);
            } elseif ($type === 'png') {
                $info = $this->_parsepng($file);
            } else {
                $this->Error('Tipo de imagen no soportado: ' . $type);
            }
            $info['i'] = count($this->images) + 1;
            $this->images[$file] = $info;
        } else {
            $info = $this->images[$file];
        }

        if ($w == 0 && $h == 0) {
            $w = -96;
            $h = -96;
        }
        if ($w < 0) {
            $w = -$info['w'] * 72 / $w / $this->k;
        }
        if ($h < 0) {
            $h = -$info['h'] * 72 / $h / $this->k;
        }
        if ($w == 0) {
            $w = $h * $info['w'] / $info['h'];
        }
        if ($h == 0) {
            $h = $w * $info['h'] / $info['w'];
        }

        if ($y === null) {
            if ($this->y + $h > $this->PageBreakTrigger && !$this->InHeader && !$this->InFooter && $this->AcceptPageBreak()) {
                $x2 = $this->x;
                $this->AddPage($this->CurOrientation, $this->CurPageSize, $this->CurRotation);
                $this->x = $x2;
            }
            $y = $this->y;
            $this->y += $h;
        }
        if ($x === null) {
            $x = $this->x;
        }
        $this->_out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q', $w * $this->k, $h * $this->k, $x * $this->k, ($this->h - ($y + $h)) * $this->k, $info['i']));
    }

    public function GetX()
    {
        return $this->x;
    }

    public function SetX($x)
    {
        if ($x >= 0) {
            $this->x = $x;
        } else {
            $this->x = $this->w + $x;
        }
    }

    public function GetY()
    {
        return $this->y;
    }

    public function SetY($y, $resetX = true)
    {
        if ($y >= 0) {
            $this->y = $y;
        } else {
            $this->y = $this->h + $y;
        }
        if ($resetX) {
            $this->x = $this->lMargin;
        }
    }

    public function SetXY($x, $y)
    {
        $this->SetX($x);
        $this->SetY($y, false);
    }

    public function AcceptPageBreak()
    {
        return $this->AutoPageBreak;
    }

    public function Output($dest = '', $name = '', $isUTF8 = false)
    {
        $this->Close();
        if (strlen($name) === 1 && strlen($dest) !== 1) {
            $tmp = $dest;
            $dest = $name;
            $name = $tmp;
        }
        if ($dest === '') {
            $dest = 'I';
        }
        if ($name === '') {
            $name = 'doc.pdf';
        }
        $dest = strtoupper($dest);
        switch ($dest) {
            case 'I':
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    header('Content-Type: application/pdf');
                    header('X-Content-Type-Options: nosniff');
                    header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . strlen($this->buffer));
                }
                echo $this->buffer;
                break;
            case 'D':
                if (PHP_SAPI !== 'cli' && !headers_sent()) {
                    header('Content-Type: application/pdf');
                    header('X-Content-Type-Options: nosniff');
                    header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
                    header('Cache-Control: private, max-age=0, must-revalidate');
                    header('Pragma: public');
                    header('Content-Length: ' . strlen($this->buffer));
                }
                echo $this->buffer;
                break;
            case 'F':
                if (@file_put_contents($name, $this->buffer) === false) {
                    $this->Error('No se pudo crear el archivo de salida: ' . $name);
                }
                break;
            case 'S':
                return $this->buffer;
            default:
                $this->Error('Destino Output() inválido: ' . $dest);
        }
        return '';
    }

    protected function _getpagesize($size)
    {
        if (is_string($size)) {
            $size = strtolower($size);
            if (!isset($this->StdPageSizes[$size])) {
                $this->Error('Tamaño de página desconocido: ' . $size);
            }
            $a = $this->StdPageSizes[$size];
            return [$a[0] / $this->k, $a[1] / $this->k];
        }
        if ($size[0] > $size[1]) {
            return [$size[1], $size[0]];
        }
        return $size;
    }

    protected function _beginpage($orientation, $size, $rotation)
    {
        $this->page++;
        $this->pages[$this->page] = '';
        $this->PageLinks[$this->page] = [];
        $this->state = 2;
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->FontFamily = '';
        if ($orientation === '') {
            $orientation = $this->DefOrientation;
        } else {
            $orientation = strtoupper($orientation[0]);
        }
        if ($size === '') {
            $size = $this->DefPageSize;
        } else {
            $size = $this->_getpagesize($size);
        }
        if ($orientation != $this->CurOrientation || $size[0] != $this->CurPageSize[0] || $size[1] != $this->CurPageSize[1]) {
            if ($orientation === 'P') {
                $this->w = $size[0];
                $this->h = $size[1];
            } else {
                $this->w = $size[1];
                $this->h = $size[0];
            }
            $this->wPt = $this->w * $this->k;
            $this->hPt = $this->h * $this->k;
            $this->PageBreakTrigger = $this->h - $this->bMargin;
            $this->CurOrientation = $orientation;
            $this->CurPageSize = $size;
        }
        if ($orientation != $this->DefOrientation || $size[0] != $this->DefPageSize[0] || $size[1] != $this->DefPageSize[1]) {
            $this->PageInfo[$this->page]['size'] = [$this->wPt, $this->hPt];
        }
        $this->CurRotation = $rotation;
    }

    protected function _endpage()
    {
        $this->state = 1;
    }

    protected function _escape($s)
    {
        return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', '\\r'], (string)$s);
    }

    protected function _textstring($s)
    {
        if (!$this->_isascii($s)) {
            $s = $this->_UTF8toUTF16($s);
        }
        return '(' . $this->_escape($s) . ')';
    }

    protected function _isascii($s)
    {
        $nb = strlen($s);
        for ($i = 0; $i < $nb; $i++) {
            if (ord($s[$i]) > 127) {
                return false;
            }
        }
        return true;
    }

    protected function _utf8encode($s)
    {
        if (function_exists('iconv')) {
            $res = @iconv('ISO-8859-1', 'UTF-8', (string)$s);
            if ($res !== false) {
                return $res;
            }
        }
        return (string)$s;
    }

    protected function _UTF8toUTF16($s)
    {
        $res = "\xFE\xFF";
        $nb = strlen($s);
        $i = 0;
        while ($i < $nb) {
            $c1 = ord($s[$i++]);
            if ($c1 >= 224) {
                $c2 = ($i < $nb) ? ord($s[$i++]) : 0;
                $c3 = ($i < $nb) ? ord($s[$i++]) : 0;
                $res .= chr((($c1 & 0x0F) << 4) + (($c2 & 0x3C) >> 2));
                $res .= chr((($c2 & 0x03) << 6) + ($c3 & 0x3F));
            } elseif ($c1 >= 192) {
                $c2 = ($i < $nb) ? ord($s[$i++]) : 0;
                $res .= chr(($c1 & 0x1C) >> 2);
                $res .= chr((($c1 & 0x03) << 6) + ($c2 & 0x3F));
            } else {
                $res .= "\0" . chr($c1);
            }
        }
        return $res;
    }

    protected function _dounderline($x, $y, $txt)
    {
        $up = $this->CurrentFont['up'];
        $ut = $this->CurrentFont['ut'];
        $w = $this->GetStringWidth($txt) + $this->ws * substr_count($txt, ' ');
        return sprintf('%.2F %.2F %.2F %.2F re f', $x * $this->k, ($this->h - ($y - $up / 1000 * $this->FontSize)) * $this->k, $w * $this->k, -$ut / 1000 * $this->FontSizePt);
    }

    protected function _parsejpg($file)
    {
        $a = @getimagesize($file);
        if (!$a) {
            $this->Error('Imagen JPEG inválida o ausente: ' . $file);
        }
        if ($a[2] != 2) {
            $this->Error('El archivo no es un JPEG válido: ' . $file);
        }
        if (!isset($a['channels']) || $a['channels'] == 3) {
            $colspace = 'DeviceRGB';
        } elseif ($a['channels'] == 4) {
            $colspace = 'DeviceCMYK';
        } else {
            $colspace = 'DeviceGray';
        }
        $bpc = isset($a['bits']) ? $a['bits'] : 8;
        $data = file_get_contents($file);
        return ['w' => $a[0], 'h' => $a[1], 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'DCTDecode', 'data' => $data];
    }

    protected function _parsepng($file)
    {
        $f = @fopen($file, 'rb');
        if (!$f) {
            $this->Error('No se puede abrir la imagen PNG: ' . $file);
        }
        $info = $this->_parsepngstream($f, $file);
        fclose($f);
        return $info;
    }

    protected function _parsepngstream($f, $file)
    {
        if ($this->_readstream($f, 8) !== chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10)) {
            $this->Error('No es un archivo PNG válido: ' . $file);
        }
        $this->_readstream($f, 4);
        if ($this->_readstream($f, 4) !== 'IHDR') {
            $this->Error('Cabecera IHDR PNG incorrecta: ' . $file);
        }
        $w = $this->_readint($f);
        $h = $this->_readint($f);
        $bpc = ord($this->_readstream($f, 1));
        if ($bpc > 8) {
            $this->Error('Profundidad > 8 bits no soportada en PNG: ' . $file);
        }
        $ct = ord($this->_readstream($f, 1));
        if ($ct == 0 || $ct == 4) {
            $colspace = 'DeviceGray';
        } elseif ($ct == 2 || $ct == 6) {
            $colspace = 'DeviceRGB';
        } elseif ($ct == 3) {
            $colspace = 'Indexed';
        } else {
            $this->Error('Tipo de color PNG desconocido: ' . $file);
        }
        if (ord($this->_readstream($f, 1)) != 0) {
            $this->Error('Método de compresión PNG desconocido: ' . $file);
        }
        if (ord($this->_readstream($f, 1)) != 0) {
            $this->Error('Método de filtro PNG desconocido: ' . $file);
        }
        if (ord($this->_readstream($f, 1)) != 0) {
            $this->Error('PNG entrelazado no soportado por FPDF: ' . $file);
        }
        $this->_readstream($f, 4);
        $dp = '/Predictor 15 /Colors ' . ($colspace === 'DeviceRGB' ? 3 : 1) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w;

        $pal = '';
        $trns = '';
        $data = '';
        do {
            $n = $this->_readint($f);
            $type = $this->_readstream($f, 4);
            if ($type === 'PLTE') {
                $pal = $this->_readstream($f, $n);
                $this->_readstream($f, 4);
            } elseif ($type === 'tRNS') {
                $t = $this->_readstream($f, $n);
                if ($ct == 0) {
                    $trns = [ord(substr($t, 1, 1))];
                } elseif ($ct == 2) {
                    $trns = [ord(substr($t, 1, 1)), ord(substr($t, 3, 1)), ord(substr($t, 5, 1))];
                } else {
                    $pos = strpos($t, chr(0));
                    if ($pos !== false) {
                        $trns = [$pos];
                    }
                }
                $this->_readstream($f, 4);
            } elseif ($type === 'IDAT') {
                $data .= $this->_readstream($f, $n);
                $this->_readstream($f, 4);
            } elseif ($type === 'IEND') {
                break;
            } else {
                $this->_readstream($f, $n + 4);
            }
        } while ($n);

        if ($colspace === 'Indexed' && empty($pal)) {
            $this->Error('Falta paleta en PNG indexado: ' . $file);
        }
        $info = ['w' => $w, 'h' => $h, 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'FlateDecode', 'dp' => $dp, 'pal' => $pal, 'trns' => $trns];
        if ($ct >= 4) {
            if (!function_exists('gzuncompress')) {
                $this->Error('Se requiere la extensión Zlib para imágenes PNG con canal Alfa');
            }
            $data = gzuncompress($data);
            $color = '';
            $alpha = '';
            if ($ct == 4) {
                $len = 2 * $w;
                for ($i = 0; $i < $h; $i++) {
                    $pos = (1 + $len) * $i;
                    $color .= $data[$pos];
                    $alpha .= $data[$pos];
                    $line = substr($data, $pos + 1, $len);
                    $color .= preg_replace('/(.)./s', '$1', $line);
                    $alpha .= preg_replace('/.(.)/s', '$1', $line);
                }
            } else {
                $len = 4 * $w;
                for ($i = 0; $i < $h; $i++) {
                    $pos = (1 + $len) * $i;
                    $color .= $data[$pos];
                    $alpha .= $data[$pos];
                    $line = substr($data, $pos + 1, $len);
                    $color .= preg_replace('/(.{3})./s', '$1', $line);
                    $alpha .= preg_replace('/.{3}(.)/s', '$1', $line);
                }
            }
            unset($data);
            $data = gzcompress($color);
            $info['smask'] = gzcompress($alpha);
            $this->WithAlpha = true;
            if ($this->PDFVersion < '1.4') {
                $this->PDFVersion = '1.4';
            }
        }
        $info['data'] = $data;
        return $info;
    }

    protected function _readstream($f, $n)
    {
        $res = '';
        while ($n > 0 && !feof($f)) {
            $s = fread($f, $n);
            if ($s === false) {
                $this->Error('Error leyendo flujo de archivo');
            }
            $n -= strlen($s);
            $res .= $s;
        }
        if ($n > 0) {
            $this->Error('Fin de archivo inesperado al leer imagen');
        }
        return $res;
    }

    protected function _readint($f)
    {
        $a = unpack('Ni', $this->_readstream($f, 4));
        return $a['i'];
    }

    protected function _newobj($n = null)
    {
        if ($n === null) {
            $n = ++$this->n;
        }
        $this->offsets[$n] = strlen($this->buffer);
        $this->_out($n . ' 0 obj');
    }

    protected function _putstream($data)
    {
        $this->_out('stream');
        $this->_out($data);
        $this->_out('endstream');
    }

    protected function _putstreamobject($data)
    {
        if ($this->compress) {
            $entries = '/Filter /FlateDecode ';
            $data = gzcompress($data);
        } else {
            $entries = '';
        }
        $entries .= '/Length ' . strlen($data);
        $this->_newobj();
        $this->_out('<<' . $entries . '>>');
        $this->_putstream($data);
        $this->_out('endobj');
    }

    protected function _out($s)
    {
        if ($this->state == 2) {
            $this->pages[$this->page] .= $s . "\n";
        } else {
            $this->buffer .= $s . "\n";
        }
    }

    protected function _putpages()
    {
        $nb = $this->page;
        if (!empty($this->AliasNbPages)) {
            for ($n = 1; $n <= $nb; $n++) {
                $this->pages[$n] = str_replace($this->AliasNbPages, (string)$nb, $this->pages[$n]);
            }
        }
        if ($this->DefOrientation === 'P') {
            $wPt = $this->DefPageSize[0] * $this->k;
            $hPt = $this->DefPageSize[1] * $this->k;
        } else {
            $wPt = $this->DefPageSize[1] * $this->k;
            $hPt = $this->DefPageSize[0] * $this->k;
        }
        for ($n = 1; $n <= $nb; $n++) {
            $this->_newobj();
            $this->_out('<</Type /Page');
            $this->_out('/Parent 1 0 R');
            if (isset($this->PageInfo[$n]['size'])) {
                $this->_out(sprintf('/MediaBox [0 0 %.2F %.2F]', $this->PageInfo[$n]['size'][0], $this->PageInfo[$n]['size'][1]));
            }
            $this->_out('/Resources 2 0 R');
            if ($this->WithAlpha) {
                $this->_out('/Group <</Type /Group /S /Transparency /CS /DeviceRGB>>');
            }
            $this->_out('/Contents ' . ($this->n + 1) . ' 0 R>>');
            $this->_out('endobj');
            $this->_putstreamobject($this->pages[$n]);
        }
        $this->_newobj(1);
        $this->_out('<</Type /Pages');
        $kids = '/Kids [';
        for ($n = 1; $n <= $nb; $n++) {
            $kids .= (3 + 2 * ($n - 1)) . ' 0 R ';
        }
        $this->_out($kids . ']');
        $this->_out('/Count ' . $nb);
        $this->_out(sprintf('/MediaBox [0 0 %.2F %.2F]', $wPt, $hPt));
        $this->_out('>>');
        $this->_out('endobj');
    }

    protected function _putfonts()
    {
        foreach ($this->fonts as $k => $font) {
            $this->fonts[$k]['n'] = $this->n + 1;
            $this->_newobj();
            $this->_out('<</Type /Font');
            $this->_out('/BaseFont /' . $font['name']);
            $this->_out('/Subtype /Type1');
            if ($font['name'] !== 'Symbol' && $font['name'] !== 'ZapfDingbats') {
                $this->_out('/Encoding /WinAnsiEncoding');
            }
            $this->_out('>>');
            $this->_out('endobj');
        }
    }

    protected function _putimages()
    {
        foreach (array_keys($this->images) as $file) {
            $this->_putimage($this->images[$file]);
            unset($this->images[$file]['data']);
            unset($this->images[$file]['smask']);
        }
    }

    protected function _putimage(&$info)
    {
        $this->_newobj();
        $info['n'] = $this->n;
        $this->_out('<</Type /XObject');
        $this->_out('/Subtype /Image');
        $this->_out('/Width ' . $info['w']);
        $this->_out('/Height ' . $info['h']);
        if ($info['cs'] === 'Indexed') {
            $this->_out('/ColorSpace [/Indexed /DeviceRGB ' . (strlen($info['pal']) / 3 - 1) . ' ' . ($this->n + 1) . ' 0 R]');
        } else {
            $this->_out('/ColorSpace /' . $info['cs']);
            if ($info['cs'] === 'DeviceCMYK') {
                $this->_out('/Decode [1 0 1 0 1 0 1 0]');
            }
        }
        $this->_out('/BitsPerComponent ' . $info['bpc']);
        if (isset($info['f'])) {
            $this->_out('/Filter /' . $info['f']);
        }
        if (isset($info['dp'])) {
            $this->_out('/DecodeParms <<' . $info['dp'] . '>>');
        }
        if (isset($info['trns']) && is_array($info['trns'])) {
            $trns = '';
            for ($i = 0; $i < count($info['trns']); $i++) {
                $trns .= $info['trns'][$i] . ' ' . $info['trns'][$i] . ' ';
            }
            $this->_out('/Mask [' . $trns . ']');
        }
        if (isset($info['smask'])) {
            $this->_out('/SMask ' . ($this->n + 1) . ' 0 R');
        }
        $this->_out('/Length ' . strlen($info['data']) . '>>');
        $this->_putstream($info['data']);
        $this->_out('endobj');
        if (isset($info['smask'])) {
            $dp = '/Predictor 15 /Colors 1 /BitsPerComponent 8 /Columns ' . $info['w'];
            $smask = ['w' => $info['w'], 'h' => $info['h'], 'cs' => 'DeviceGray', 'bpc' => 8, 'f' => $info['f'], 'dp' => $dp, 'data' => $info['smask']];
            $this->_putimage($smask);
        }
        if ($info['cs'] === 'Indexed') {
            $this->_putstreamobject($info['pal']);
        }
    }

    protected function _putresources()
    {
        $this->_putfonts();
        $this->_putimages();
        $this->_newobj(2);
        $this->_out('<<');
        $this->_out('/ProcSet [/PDF /Text /ImageB /ImageC /ImageI]');
        $this->_out('/Font <<');
        foreach ($this->fonts as $font) {
            $this->_out('/F' . $font['i'] . ' ' . $font['n'] . ' 0 R');
        }
        $this->_out('>>');
        $this->_out('/XObject <<');
        foreach ($this->images as $image) {
            $this->_out('/I' . $image['i'] . ' ' . $image['n'] . ' 0 R');
        }
        $this->_out('>>');
        $this->_out('>>');
        $this->_out('endobj');
    }

    protected function _putinfo()
    {
        $this->metadata['CreationDate'] = 'D:' . @date('YmdHis');
        foreach ($this->metadata as $key => $value) {
            $this->_out('/' . $key . ' ' . $this->_textstring($value));
        }
    }

    protected function _putcatalog()
    {
        $n = 3;
        $this->_out('/Type /Catalog');
        $this->_out('/Pages 1 0 R');
        if ($this->ZoomMode === 'fullpage') {
            $this->_out('/OpenAction [' . $n . ' 0 R /Fit]');
        } elseif ($this->ZoomMode === 'fullwidth') {
            $this->_out('/OpenAction [' . $n . ' 0 R /FitH null]');
        } elseif ($this->ZoomMode === 'real') {
            $this->_out('/OpenAction [' . $n . ' 0 R /XYZ null null 1]');
        } elseif (!is_string($this->ZoomMode)) {
            $this->_out('/OpenAction [' . $n . ' 0 R /XYZ null null ' . sprintf('%.2F', $this->ZoomMode / 100) . ']');
        }
    }

    protected function _enddoc()
    {
        $this->state = 1;
        $this->_out('%PDF-' . $this->PDFVersion);
        $this->_putpages();
        $this->_putresources();
        $this->_newobj();
        $this->_out('<<');
        $this->_putinfo();
        $this->_out('>>');
        $this->_out('endobj');
        $info_obj = $this->n;
        $this->_newobj();
        $this->_out('<<');
        $this->_putcatalog();
        $this->_out('>>');
        $this->_out('endobj');
        $cat_obj = $this->n;
        $offset = strlen($this->buffer);
        $this->_out('xref');
        $this->_out('0 ' . ($this->n + 1));
        $this->_out('0000000000 65535 f ');
        for ($i = 1; $i <= $this->n; $i++) {
            $this->_out(sprintf('%010d 00000 n ', $this->offsets[$i]));
        }
        $this->_out('trailer');
        $this->_out('<<');
        $this->_out('/Size ' . ($this->n + 1));
        $this->_out('/Root ' . $cat_obj . ' 0 R');
        $this->_out('/Info ' . $info_obj . ' 0 R');
        $this->_out('>>');
        $this->_out('startxref');
        $this->_out((string)$offset);
        $this->_out('%%EOF');
        $this->state = 3;
    }

    protected function _getCoreFontWidths($fontkey)
    {
        if (strpos($fontkey, 'courier') === 0) {
            $cw = [];
            for ($i = 0; $i <= 255; $i++) {
                $cw[chr($i)] = 600;
            }
            return $cw;
        }
        $isBold = (strpos($fontkey, 'B') !== false);
        $def = $isBold ? 580 : 530;
        $cw = [];
        for ($i = 0; $i <= 255; $i++) {
            $cw[chr($i)] = $def;
        }
        $base = $isBold ? [
            ' ' => 278, '!' => 333, '"' => 474, '#' => 556, '$' => 556, '%' => 889, '&' => 722, '\'' => 278,
            '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
            '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
            '8' => 556, '9' => 556, ':' => 333, ';' => 333, '<' => 584, '=' => 584, '>' => 584, '?' => 611,
            '@' => 975, 'A' => 722, 'B' => 722, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
            'H' => 722, 'I' => 278, 'J' => 556, 'K' => 722, 'L' => 611, 'M' => 833, 'N' => 722, 'O' => 778,
            'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
            'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 333, '\\' => 278, ']' => 333, '^' => 584, '_' => 556,
            'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611, 'e' => 556, 'f' => 333, 'g' => 611, 'h' => 611,
            'i' => 278, 'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889, 'n' => 611, 'o' => 611, 'p' => 611,
            'q' => 611, 'r' => 389, 's' => 556, 't' => 333, 'u' => 611, 'v' => 556, 'w' => 778, 'x' => 556,
            'y' => 556, 'z' => 500, '{' => 389, '|' => 280, '}' => 389, '~' => 584, chr(128) => 556,
        ] : [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, '\'' => 191,
            '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278,
            '0' => 556, '1' => 556, '2' => 556, '3' => 556, '4' => 556, '5' => 556, '6' => 556, '7' => 556,
            '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556,
            '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
            'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778,
            'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944,
            'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
            'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556,
            'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556,
            'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500,
            'y' => 500, 'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584, chr(128) => 556,
        ];
        foreach ($base as $ch => $val) {
            $cw[$ch] = $val;
        }
        return $cw;
    }
}
}
