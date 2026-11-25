<?php
// modules/waba_ordenes/test_ia/install_cron_waba.php
// Instalador Administrativo Exclusivo por Consola (CLI) de la Tarea Programada de Facturación WABA — STARFI CRM (QA-BUG-CRM3-01)
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../core/audit.php';

$php_bin = PHP_BINARY;
$script_path = realpath(__DIR__ . '/../../../cron_waba_billing.php');
$task_name = 'Starfi_Waba_Billing_Cron';

if (!$script_path || !is_file($script_path)) {
    fwrite(STDERR, "[ERROR] No se encontró el archivo cron_waba_billing.php en la raíz del proyecto.\n");
    exit(1);
}

$tr_arg = '"' . str_replace('"', '', $php_bin) . '" -f "' . str_replace('"', '', $script_path) . '"';
$cmd = 'schtasks /create /tn "' . $task_name . '" /tr ' . escapeshellarg($tr_arg) . ' /sc daily /st 01:00 /f 2>&1';
$output = [];
$exit_code = 1;
exec($cmd, $output, $exit_code);
$output_str = trim(implode("\n", $output));

$okCron = ($exit_code === 0) || (
    stripos($output_str, 'success') !== false ||
    stripos($output_str, 'correcto') !== false ||
    stripos($output_str, 'éxito') !== false
);

$con = @getDbConnection();
registrar_auditoria(
    'waba_ordenes',
    'INSTALAR_CRON_CLI',
    'Ejecución por CLI de instalador de tarea programada Starfi_Waba_Billing_Cron',
    null,
    [
        'task_name' => $task_name,
        'exit_code' => $exit_code,
        'instalado' => $okCron,
    ],
    $con ?: null,
    null,
    null
);
if ($con instanceof mysqli) {
    @$con->close();
}

if ($okCron) {
    echo "[OK] Tarea programada '$task_name' registrada exitosamente (01:00 AM diario).\n";
    exit(0);
}

starfi_log_exception('waba_ordenes', 'Fallo registrando schtasks desde CLI', ['exit_code' => $exit_code, 'output' => $output_str]);
fwrite(STDERR, "[ERROR] No se pudo registrar la tarea programada en Windows. Ejecute la consola como Administrador.\nDetalle: $output_str\n");
exit(1);
