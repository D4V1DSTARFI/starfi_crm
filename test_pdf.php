<?php
require 'C:/xampp/htdocs/starfi2.0/includes/libs/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;
$options = new Options();
$dompdf = new Dompdf($options);
$dompdf->loadHtml('<h1>Test PDF</h1>');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
echo "PDF rendered successfully. Size: " . strlen($dompdf->output());
