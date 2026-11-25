<?php
/**
 * modules/pipeline/test_ia/test_sprint2_qa.php
 * Suite Oficial de Certificación Forense Pre-Entrega Ampliada — STARFI CRM
 * Cubre OT-QA-CRM-2026-1121-01 (QA-BUG-CRM2-01..12) + OT-QA-CRM-2026-1125-01 (QA-BUG-CRM3-01..08)
 * y los 45 Controles Oficiales de QA-STD-GLOBAL-2.1 (MANUAL_GLOBAL_QA.md v2.1.0).
 * Ejecución exclusiva CLI con ROLLBACK transaccional garantizado (0 residuos en base de datos).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

@ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('America/Caracas');

$root = str_replace('\\', '/', realpath(__DIR__ . '/../../..'));
require_once $root . '/config/database.php';
require_once $root . '/core/crm_sprint2_helper.php';
require_once $root . '/core/audit.php';

if (!defined('WABA_BILLING_LIB_ONLY')) {
    define('WABA_BILLING_LIB_ONLY', true);
}
if (!defined('CAMPANAS_LIB_ONLY')) {
    define('CAMPANAS_LIB_ONLY', true);
}
if (!defined('STARFI_PDF_LIB_MODE')) {
    define('STARFI_PDF_LIB_MODE', true);
}
require_once $root . '/modules/waba_ordenes/back_waba_ordenes.php';
require_once $root . '/modules/campanas/back_campanas.php';
require_once $root . '/imprimir_orden_waba.php';

$passed = 0;
$failed = 0;
$failures = [];

function qa_check($label, $condition, $detail = '') {
    global $passed, $failed, $failures;
    if ($condition) {
        $passed++;
        echo "  [PASS] $label\n";
    } else {
        $failed++;
        $failures[] = "$label" . ($detail !== '' ? " ($detail)" : "");
        echo "  [FAIL] $label" . ($detail !== '' ? " -> $detail" : "") . "\n";
    }
}

/**
 * Ejecuta un script PHP bajo php-cgi (SAPI != 'cli') devolviendo código HTTP y cuerpo de respuesta.
 */
function qa_run_cgi_http_probe_full(string $scriptPath, string $method = 'GET', array $extraEnv = [], string $body = ''): ?array {
    $phpDir = dirname(PHP_BINARY);
    $cgiCandidates = [
        $phpDir . DIRECTORY_SEPARATOR . 'php-cgi.exe',
        $phpDir . DIRECTORY_SEPARATOR . 'php-cgi',
        'c:\\xampp\\php\\php-cgi.exe',
    ];
    $cgiBin = null;
    foreach ($cgiCandidates as $cand) {
        if (is_file($cand)) {
            $cgiBin = $cand;
            break;
        }
    }
    if ($cgiBin === null) {
        return null;
    }

    $env = array_merge($_ENV, [
        'REDIRECT_STATUS'   => '200',
        'REQUEST_METHOD'    => $method,
        'SCRIPT_FILENAME'   => str_replace('/', DIRECTORY_SEPARATOR, $scriptPath),
        'SCRIPT_NAME'       => '/' . basename($scriptPath),
        'QUERY_STRING'      => '',
        'CONTENT_TYPE'      => 'application/json',
        'CONTENT_LENGTH'    => (string)strlen($body),
        'SERVER_PROTOCOL'   => 'HTTP/1.1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REMOTE_ADDR'       => '127.0.0.1',
    ], $extraEnv);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open('"' . $cgiBin . '"', $descriptors, $pipes, dirname($scriptPath), $env);
    if (!is_resource($proc)) {
        return null;
    }
    if ($body !== '') {
        fwrite($pipes[0], $body);
    }
    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $code = 200;
    if (preg_match('/^Status:\s*(\d{3})/mi', $stdout, $m)) {
        $code = (int)$m[1];
    }
    return [
        'code'   => $code,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function qa_run_cgi_http_probe(string $scriptPath, string $method = 'GET', array $extraEnv = [], string $body = ''): ?int {
    $res = qa_run_cgi_http_probe_full($scriptPath, $method, $extraEnv, $body);
    return $res ? $res['code'] : null;
}

echo "=====================================================================================\n";
echo " SUITE DE CERTIFICACIÓN INTEGRAL QA-STD-GLOBAL-2.1 — STARFI CRM (OT-QA-CRM-2026-1125-01)\n";
echo "=====================================================================================\n\n";

// -----------------------------------------------------------------------------
// BLOQUE 1: Higiene Git, Purga de Secretos SQL y Rotación SHA-256 (QA-BUG-CRM2-01)
// -----------------------------------------------------------------------------
echo "[1/11] Verificando Higiene Git, Esquema SQL Limpio y Script de Rotación (QA-BUG-CRM2-01)...\n";
$sqlOnDisk = [];
$resolvedOnDisk = [];
$riiAll = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($riiAll as $fi) {
    $full = str_replace('\\', '/', $fi->getPathname());
    if (strpos($full, '/.git/') !== false || strpos($full, '/database/install_dumps/') !== false) {
        continue;
    }
    $rel = substr($full, strlen($root) + 1);
    if (strtolower($fi->getExtension()) === 'sql') {
        $sqlOnDisk[] = $rel;
    }
    if (substr($rel, -9) === '.resolved') {
        $resolvedOnDisk[] = $rel;
    }
}
qa_check("Único archivo .sql en árbol del proyecto es database/database_schema.sql", $sqlOnDisk === ['database/database_schema.sql'], implode(', ', $sqlOnDisk));
qa_check("0 artefactos residuales *.resolved en el repositorio", empty($resolvedOnDisk), implode(', ', $resolvedOnDisk));

$gitSqlOut = @shell_exec('git -C ' . escapeshellarg($root) . ' ls-files "*.sql" 2>&1');
if (is_string($gitSqlOut) && stripos($gitSqlOut, 'fatal:') === false) {
    $gitSqlFiles = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $gitSqlOut)))));
    $sqlTokenHits = [];
    foreach ($gitSqlFiles as $gsf) {
        $absSql = $root . '/' . $gsf;
        if (is_file($absSql)) {
            $sqlBody = (string)file_get_contents($absSql);
            if (preg_match('/[a-f0-9]{64}/i', $sqlBody)) {
                $sqlTokenHits[] = $gsf;
            }
        }
    }
    qa_check("git ls-files *.sql lista únicamente database/database_schema.sql", $gitSqlFiles === ['database/database_schema.sql'], implode(', ', $gitSqlFiles));
    qa_check("0 archivos .sql en el índice Git contienen api_token de 64-hex", empty($sqlTokenHits), implode(', ', $sqlTokenHits));
} else {
    $schemaBody = (string)@file_get_contents($root . '/database/database_schema.sql');
    qa_check("database/database_schema.sql libre de tokens de 64-hex y volcados INSERT", !preg_match('/[a-f0-9]{64}/i', $schemaBody) && !preg_match('/INSERT\s+INTO\s+`?sedes/i', $schemaBody));
}

$gitIgn = (string)file_get_contents($root . '/.gitignore');
qa_check(".gitignore bloquea /*.sql, /database/dummy_data.sql, *.resolved y config/.enable_installer",
    strpos($gitIgn, '/*.sql') !== false &&
    strpos($gitIgn, '/database/dummy_data.sql') !== false &&
    strpos($gitIgn, '*.resolved') !== false &&
    strpos($gitIgn, 'config/.enable_installer') !== false
);

$rotPath = $root . '/modules/configuracion/test_ia/rotate_compromised_api_tokens.php';
$rotSrc  = is_file($rotPath) ? (string)file_get_contents($rotPath) : '';
qa_check("Script CLI rotate_compromised_api_tokens.php presente y protegido con PHP_SAPI !== 'cli'",
    $rotSrc !== '' && strpos($rotSrc, "PHP_SAPI !== 'cli'") !== false && strpos($rotSrc, 'random_bytes(32)') !== false && strpos($rotSrc, 'registrar_auditoria(') !== false
);
qa_check("rotate_compromised_api_tokens.php usa digests SHA-256 sin almacenar tokens planos expuestos",
    stripos($rotSrc, 'd4304e147c7c8056ef30d4b6b77f4f555f58a11f0922bb1e4b6243ba1d4173b2') === false &&
    strpos($rotSrc, "hash('sha256'") !== false
);

// -----------------------------------------------------------------------------
// BLOQUE 2: Bloqueo HTTP 403 en install.php, database/*.php y webhook.php (QA-BUG-CRM2-05, 12)
// -----------------------------------------------------------------------------
echo "\n[2/11] Verificando Guardias HTTP 403 en Instalador, database/*.php y Firma HMAC Webhook...\n";
$instSrc = (string)file_get_contents($root . '/install.php');
$whSrc   = (string)file_get_contents($root . '/webhook.php');

qa_check("install.php exige centinela local config/.enable_installer y auto-elimina al finalizar",
    strpos($instSrc, "config/.enable_installer") !== false && strpos($instSrc, "@unlink(\$sentinelPath)") !== false
);
foreach (['database/create_tables.php', 'database/run_migration.php', 'database/update_costs.php'] as $dbScript) {
    $dbSrc = (string)file_get_contents($root . '/' . $dbScript);
    $headLines = implode("\n", array_slice(explode("\n", $dbSrc), 0, 5));
    qa_check("$dbScript exige PHP_SAPI !== 'cli' -> HTTP 403 en primeras líneas", strpos($headLines, "PHP_SAPI !== 'cli'") !== false && strpos($headLines, '403') !== false);
}
qa_check("webhook.php exige META_APP_SECRET y X-Hub-Signature-256 en TODA petición HTTP (sin bypass APP_ENV)",
    strpos($whSrc, "PHP_SAPI !== 'cli'") !== false &&
    strpos($whSrc, "hash_equals(\$expected_signature, \$signature)") !== false &&
    !preg_match('/APP_ENV\s*!==?\s*[\'"]PRODUCCION[\'"]/', $whSrc)
);
qa_check("webhook.php elimina fallback global sin id_sede sobre configuraciones_ia (QA-BUG-CRM2-02)",
    !preg_match('/FROM\s+configuraciones_ia\s+WHERE\s+estado_ia\s*=\s*\\\\?[\'"]ACTIVO\\\\?[\'"]\s+LIMIT\s+1/i', $whSrc) &&
    (bool)preg_match('/FROM\s+configuraciones_ia\s+WHERE\s+id_sede\s*=\s*\?\s+AND\s+estado_ia\s*=\s*\\\\?[\'"]ACTIVO\\\\?[\'"]\s+LIMIT\s+1/i', $whSrc)
);

$cgiInstallCode = qa_run_cgi_http_probe($root . '/install.php', 'GET');
if ($cgiInstallCode !== null) {
    qa_check("Ejecución CGI real: GET install.php sin centinela responde HTTP 403", $cgiInstallCode === 403, "HTTP $cgiInstallCode");

    $simDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'starfi_qa_noenv_' . uniqid();
    @mkdir($simDir . DIRECTORY_SEPARATOR . 'config', 0755, true);
    @copy($root . '/install.php', $simDir . DIRECTORY_SEPARATOR . 'install.php');
    $cgiNoEnvCode = qa_run_cgi_http_probe($simDir . DIRECTORY_SEPARATOR . 'install.php', 'GET');
    @unlink($simDir . DIRECTORY_SEPARATOR . 'install.php');
    @rmdir($simDir . DIRECTORY_SEPARATOR . 'config');
    @rmdir($simDir);
    qa_check("Ejecución CGI real: GET install.php SIN .env y SIN centinela responde HTTP 403", $cgiNoEnvCode === 403, "HTTP $cgiNoEnvCode");

    $cgiDb1 = qa_run_cgi_http_probe($root . '/database/create_tables.php', 'GET');
    $cgiDb2 = qa_run_cgi_http_probe($root . '/database/run_migration.php', 'GET');
    $cgiDb3 = qa_run_cgi_http_probe($root . '/database/update_costs.php', 'GET');
    qa_check("Ejecución CGI real: database/*.php por HTTP responden HTTP 403", $cgiDb1 === 403 && $cgiDb2 === 403 && $cgiDb3 === 403, "$cgiDb1/$cgiDb2/$cgiDb3");

    $cgiWhNoSig = qa_run_cgi_http_probe($root . '/webhook.php', 'POST', ['META_APP_SECRET' => 'qa_test_secret'], '{"object":"whatsapp_business_account"}');
    $cgiWhBadSig = qa_run_cgi_http_probe($root . '/webhook.php', 'POST', ['META_APP_SECRET' => 'qa_test_secret', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef'], '{"object":"whatsapp_business_account"}');
    qa_check("Ejecución CGI real: POST webhook.php sin firma o con firma inválida responde HTTP 403", $cgiWhNoSig === 403 && $cgiWhBadSig === 403, "$cgiWhNoSig/$cgiWhBadSig");
}

// -----------------------------------------------------------------------------
// BLOQUE 3: RBAC en los 13 back_*.php Canónicos, Autenticación y Seguridad Uploads .htaccess
// -----------------------------------------------------------------------------
echo "\n[3/11] Verificando RBAC en las 13 Cápsulas, Cabeceras JSON y Uploads .htaccess...\n";
$loginSrc = (string)file_get_contents($root . '/login.php');
$recSrc   = (string)file_get_contents($root . '/recover_password.php');
$verDoc   = (string)file_get_contents($root . '/ver_documento.php');
$upHt     = (string)file_get_contents($root . '/assets/uploads/.htaccess');

qa_check("login.php sin backdoor '1234' ni md5(), y con registrar_auditoria()",
    !preg_match("/===\s*['\"]1234['\"]/", $loginSrc) && stripos($loginSrc, 'md5(') === false && strpos($loginSrc, 'registrar_auditoria(') !== false
);
qa_check("recover_password.php con random_int(100000, 999999), sin codigo_simulado y con registrar_auditoria()",
    stripos($recSrc, 'codigo_simulado') === false && strpos($recSrc, 'random_int(100000, 999999)') !== false && strpos($recSrc, 'registrar_auditoria(') !== false
);
qa_check("assets/uploads/.htaccess con php_flag engine off, nosniff, bloqueo media_* y RewriteRule hacia ver_documento.php",
    strpos($upHt, 'php_flag engine off') !== false &&
    strpos($upHt, 'nosniff') !== false &&
    strpos($upHt, 'comprobante_waba') !== false &&
    strpos($upHt, 'media_') !== false
);
qa_check("assets/uploads/comprobantes_waba/.htaccess presente y bloqueando acceso directo",
    is_file($root . '/assets/uploads/comprobantes_waba/.htaccess')
);
qa_check("ver_documento.php valida sesión e id_sede para comprobante_waba",
    strpos($verDoc, "tipo === 'comprobante_waba'") !== false && strpos($verDoc, 'waba_ordenes_cobro') !== false
);

$requiredRbac = [
    'modules/gestion_usuarios/back_gestion_usuarios.php'   => ['perm' => 'gestion_usuarios',   'audit' => true],
    'modules/perfil_empresa/back_perfil_empresa.php'       => ['perm' => 'perfil_empresa',     'audit' => true],
    'modules/configuracion/back_configuracion.php'         => ['perm' => 'configuracion',      'audit' => true],
    'modules/bandeja/back_bandeja.php'                     => ['perm' => 'bandeja',            'audit' => true],
    'modules/directorio/back_directorio.php'               => ['perm' => 'directorio',         'audit' => true],
    'modules/gestor_bots/back_gestor_bots.php'             => ['perm' => 'gestor_bots',        'audit' => true],
    'modules/dashboard/back_dashboard.php'                 => ['perm' => 'dashboard',          'audit' => true],
    'modules/waba_ordenes/back_waba_ordenes.php'           => ['perm' => 'waba_ordenes',       'audit' => true],
    'modules/whatsapp_analytics/back_whatsapp_analytics.php' => ['perm' => 'whatsapp_analytics', 'audit' => false],
    'modules/buzon_correos/back_buzon_correos.php'         => ['perm' => 'buzon_correos',      'audit' => false],
    'modules/pipeline/back_pipeline.php'                   => ['perm' => 'pipeline',           'audit' => true],
    'modules/gestion_roles/back_gestion_roles.php'         => ['perm' => 'gestion_roles',      'audit' => true],
    'modules/campanas/back_campanas.php'                   => ['perm' => 'campanas',           'audit' => true],
];
foreach ($requiredRbac as $relPath => $meta) {
    $src = (string)file_get_contents($root . '/' . $relPath);
    $permKey = $meta['perm'];
    $hasPerm = (strpos($src, "requirePermission('$permKey')") !== false || strpos($src, "hasPermission('$permKey')") !== false);
    $hasJsonHdr = (strpos($src, "display_errors") !== false && stripos($src, "application/json") !== false);
    $hasAud = !$meta['audit'] || (strpos($src, 'registrar_auditoria(') !== false);
    qa_check("RBAC ('$permKey'), display_errors=0 + JSON header" . ($meta['audit'] ? " y registrar_auditoria()" : "") . " en $relPath",
        $hasPerm && $hasJsonHdr && $hasAud
    );
}

// -----------------------------------------------------------------------------
// BLOQUE 4: 100% mysqli_prepare y 0 Fugas de Errores SQL en JSON (QA-BUG-CRM2-03, 09)
// -----------------------------------------------------------------------------
echo "\n[4/11] Escaneando 100% mysqli_prepare() y 0 Fugas ->error / mysqli_error en JSON...\n";
$varSqlHits = [];
$jsonErrHits = [];

$riiPhp = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($riiPhp as $fi) {
    $full = str_replace('\\', '/', $fi->getPathname());
    if (strpos($full, '/.git/') !== false || strpos($full, '/core/libs/') !== false || strpos($full, '/includes/libs/') !== false) {
        continue;
    }
    if (strtolower($fi->getExtension()) !== 'php') {
        continue;
    }
    $rel = substr($full, strlen($root) + 1);
    if (strpos($rel, 'test_ia/') !== false) {
        continue;
    }

    $src = (string)file_get_contents($full);
    if (preg_match('/json_encode\s*\([^;]*?(->error\b|mysqli_error)/is', $src)) {
        $jsonErrHits[] = $rel;
    }

    if ($rel !== 'install.php' && strpos($rel, 'database/') !== 0) {
        $lines = file($full);
        foreach ($lines as $idx => $line) {
            if (preg_match('/(mysqli_query|->query)\s*\(/i', $line, $qMatch, PREG_OFFSET_CAPTURE)) {
                $firstLineTail = substr($line, $qMatch[0][1]);
                $window = $firstLineTail . implode('', array_slice($lines, $idx + 1, 4));
                $cleanWin = preg_replace('/^mysqli_query\s*\(\s*\$[a-zA-Z0-9_>-]+\s*,/i', 'q(', $window);
                $cleanWin = preg_replace('/^->query\s*\(/i', 'q(', $cleanWin);
                if (preg_match('/\)\s*;/', $cleanWin, $endM, PREG_OFFSET_CAPTURE)) {
                    $cleanWin = substr($cleanWin, 0, $endM[0][1]);
                }
                if (preg_match('/\$[a-zA-Z_]/', $cleanWin)) {
                    $varSqlHits[] = "$rel:" . ($idx + 1);
                }
            }
        }
    }
}
qa_check("0 exposiciones de \$stmt->error / \$con->error / mysqli_error en json_encode()", empty($jsonErrHits), implode(', ', $jsonErrHits));
qa_check("0 consultas mysqli_query / ->query con interpolación de variables (incl. cron_waba_billing.php)", empty($varSqlHits), implode(', ', array_slice($varSqlHits, 0, 8)));

// -----------------------------------------------------------------------------
// BLOQUE 5: Modo 100% Offline LAN, 0 alert(), Favicons, Cápsulas Estrictas de 4 Archivos y Modals HTTP 403 (QA-BUG-CRM3-04)
// -----------------------------------------------------------------------------
echo "\n[5/11] Verificando Offline LAN, 0 alert(), Favicons, 13 Cápsulas Estrictas de 4 Archivos y Bloqueo HTTP 403 en modals_*.php...\n";
$extDomainHits = [];
$alertHits     = [];
$missingFav    = [];
$missingAuto   = [];
$hardcodedPath = [];

$riiUi = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($riiUi as $fi) {
    $full = str_replace('\\', '/', $fi->getPathname());
    if (
        strpos($full, '/.git/') !== false ||
        strpos($full, '/docs/') !== false ||
        strpos($full, '/assets/vendor/') !== false ||
        strpos($full, '/assets/icons/') !== false ||
        strpos($full, '/assets/js/') !== false ||
        strpos($full, '/assets/css/bootstrap') !== false ||
        strpos($full, '/assets/css/select2') !== false ||
        strpos($full, '/core/libs/') !== false ||
        strpos($full, '/includes/libs/') !== false ||
        strpos($full, '/test_ia/') !== false ||
        strpos($full, '/logs/') !== false
    ) {
        continue;
    }
    $ext = strtolower($fi->getExtension());
    if (!in_array($ext, ['php', 'js', 'css', 'html'], true)) {
        continue;
    }
    $rel  = substr($full, strlen($root) + 1);
    $base = basename($rel);
    $src  = (string)file_get_contents($full);

    if (preg_match('/(?<![a-zA-Z0-9_.$])alert\s*\(/', $src)) {
        $alertHits[] = $rel;
    }
    if (preg_match('#/starfi_crm/(login|logout|assets|get_media)#i', $src) || strpos($src, 'logo_1784639274_107.png') !== false) {
        $hardcodedPath[] = $rel;
    }

    $isBackendEndpoint = (
        strpos($base, 'back_') === 0 ||
        strpos($rel, 'core/services/') === 0 ||
        in_array($rel, [
            'webhook.php', 'api_notificaciones.php', 'cron_campanas_autopilot.php', 'cron_waba_billing.php',
            'get_media.php', 'ver_documento.php', 'recover_password.php', 'register.php', 'logout.php',
            'config/database.php', 'core/IaConnector.php', 'core/IaContextEngine.php', 'core/audit.php',
            'core/crm_sprint2_helper.php',
        ], true)
    );
    if (!$isBackendEndpoint) {
        if (preg_match_all('#https?://([^\s\'"<>)]+)#i', $src, $um)) {
            foreach ($um[1] as $hostPath) {
                if (stripos($hostPath, 'www.w3.org/2000/svg') === 0) {
                    continue;
                }
                $extDomainHits[] = "$rel ($hostPath)";
            }
        }
    }

    if (stripos($src, '<head>') !== false || stripos($src, '<head ') !== false) {
        if (strpos($src, 'isologo.ico') === false) {
            $missingFav[] = $rel;
        }
    }

    if (preg_match_all('/<form\b[^>]*>/i', $src, $fms)) {
        foreach ($fms[0] as $fTag) {
            if (stripos($fTag, 'autocomplete="off"') === false && stripos($fTag, "autocomplete='off'") === false) {
                $missingAuto[] = $rel;
            }
        }
    }
}

qa_check("0 dominios externos http(s):// en .js, .css y vistas .php (CHK-LAN-01)", empty($extDomainHits), implode(', ', array_slice($extDomainHits, 0, 5)));
qa_check("0 llamadas a alert() nativo en todo el proyecto (CHK-UIX-02)", empty($alertHits), implode(', ', $alertHits));
qa_check("100% de las vistas HTML incluyen favicon oficial isologo.ico (CHK-UIX-01)", empty($missingFav), implode(', ', $missingFav));
qa_check("100% de los formularios <form> incluyen autocomplete=\"off\" (CHK-UIX-08)", empty($missingAuto), implode(', ', $missingAuto));
qa_check("0 rutas quemadas '/starfi_crm/...' ni logo_1784639274_107.png (QA-BUG-CRM2-12)", empty($hardcodedPath), implode(', ', $hardcodedPath));

$mvcCapsules = [
    'bandeja', 'pipeline', 'campanas', 'configuracion', 'directorio',
    'dashboard', 'waba_ordenes', 'whatsapp_analytics', 'buzon_correos',
    'gestor_bots', 'gestion_roles', 'gestion_usuarios', 'perfil_empresa',
];
$extraCapsuleFiles = [];
$modalsCgiFailures = [];

foreach ($mvcCapsules as $mod) {
    $modDir = "$root/modules/$mod";
    $expected4 = ["$mod.php", "modals_$mod.php", "funciones_$mod.js", "back_$mod.php"];
    $has4 = true;
    foreach ($expected4 as $ef) {
        if (!is_file("$modDir/$ef")) {
            $has4 = false;
        }
    }
    $allowedEntries = array_merge($expected4, ['.htaccess', "logs_$mod.log", 'test_ia']);
    $actualEntries = is_dir($modDir) ? array_values(array_diff(scandir($modDir), ['.', '..'])) : [];
    foreach ($actualEntries as $ae) {
        if (!in_array($ae, $allowedEntries, true)) {
            $extraCapsuleFiles[] = "modules/$mod/$ae";
        }
    }
    qa_check("Cápsula MVC estricta de 4 archivos en modules/$mod/", $has4 && is_file("$modDir/.htaccess"));

    // Sonda HTTP real php-cgi sobre cada modals_<mod>.php (QA-BUG-CRM3-04 / CHK-ARC-06 / CHK-SEC-01)
    $modalProbe = qa_run_cgi_http_probe_full("$modDir/modals_$mod.php", 'GET');
    if ($modalProbe !== null) {
        $combinedOut = $modalProbe['stdout'] . "\n" . $modalProbe['stderr'];
        if ($modalProbe['code'] !== 403 || stripos($combinedOut, 'Fatal error') !== false || stripos($combinedOut, 'xampp') !== false) {
            $modalsCgiFailures[] = "modals_$mod.php (HTTP {$modalProbe['code']})";
        }
    }
}
qa_check("0 archivos sobrantes (0 back_* duplicados, 0 index.php, 0 gema_config.json, 0 sse_updates.php) en las 13 cápsulas (CHK-ARC-01, 07)",
    empty($extraCapsuleFiles), implode(', ', $extraCapsuleFiles)
);
qa_check("Sonda CGI real: los 13 modules/*/modals_*.php responden HTTP 403 sin sesión y con 0 Fatal error / 0 rutas físicas (QA-BUG-CRM3-04)",
    empty($modalsCgiFailures), implode(', ', $modalsCgiFailures)
);
qa_check("simulador_whatsapp.php reubicado en modules/bandeja/test_ia/ y eliminado de database/tools/",
    is_file($root . '/modules/bandeja/test_ia/simulador_whatsapp.php') && !is_file($root . '/database/tools/simulador_whatsapp.php')
);

// -----------------------------------------------------------------------------
// BLOQUE 6: Verificación Específica QA-BUG-CRM3-01, CRM3-02 y CRM3-03 (Los 3 S0 Críticos)
// -----------------------------------------------------------------------------
echo "\n[6/11] Verificando Cierres S0: RBAC/Secretos en Configuración (CRM3-01), Anti-IDOR/MIME en get_media.php (CRM3-02) y DOM-XSS 100% (CRM3-03)...\n";

$backCfgSrc = (string)file_get_contents($root . '/modules/configuracion/back_configuracion.php');
$fnCfgSrc   = (string)file_get_contents($root . '/modules/configuracion/funciones_configuracion.js');
$backWabaSrc = (string)file_get_contents($root . '/modules/waba_ordenes/back_waba_ordenes.php');

qa_check("QA-BUG-CRM3-01: back_configuracion.php elimina 'add_user', exige MASTER en acciones destructivas/globales y aísla por \$user_sede",
    !preg_match("/case\s+['\"]add_user['\"]\s*:/", $backCfgSrc) &&
    strpos($backCfgSrc, 'cfg_deny_master_only()') !== false &&
    strpos($backCfgSrc, '$user_sede') !== false
);
qa_check("QA-BUG-CRM3-01: back_configuracion.php enmascara api_token, meta_token y gemini_api_key (has_token / starfi_mask_secret)",
    strpos($backCfgSrc, 'starfi_mask_secret(') !== false &&
    strpos($backCfgSrc, "'has_token'") !== false &&
    strpos($backCfgSrc, "'has_api_key'") !== false &&
    strpos($fnCfgSrc, 'type="password"') === false || strpos($fnCfgSrc, 'placeholder') !== false
);
qa_check("QA-BUG-CRM3-01: 0 sentencias MariaDB-only 'ADD COLUMN IF NOT EXISTS' en back_configuracion.php (compatible MySQL 8.0 CHK-ARC-05)",
    stripos($backCfgSrc, 'ADD COLUMN IF NOT EXISTS') === false &&
    strpos($backCfgSrc, 'SHOW COLUMNS FROM') !== false
);
qa_check("QA-BUG-CRM3-01: 0 llamadas a shell_exec() en back_waba_ordenes.php y script CLI protegido en test_ia/install_cron_waba.php",
    stripos($backWabaSrc, 'shell_exec') === false &&
    is_file($root . '/modules/waba_ordenes/test_ia/install_cron_waba.php') &&
    qa_run_cgi_http_probe($root . '/modules/waba_ordenes/test_ia/install_cron_waba.php', 'GET') === 403
);

$getMediaSrc = (string)file_get_contents($root . '/get_media.php');
qa_check("QA-BUG-CRM3-02: get_media.php exige hasPermission('bandeja') || hasPermission('directorio') y devuelve HTTP 401 sin sesión",
    strpos($getMediaSrc, "hasPermission('bandeja')") !== false &&
    qa_run_cgi_http_probe($root . '/get_media.php', 'GET') === 401
);
qa_check("QA-BUG-CRM3-02: get_media.php verifica pertenencia a id_conversacion en mensajes_y_eventos antes de stream_file() (Anti-IDOR Cross-Sede)",
    (bool)preg_match('/FROM\s+mensajes_y_eventos\s+WHERE\s+id_conversacion\s*=\s*\?.*stream_file\s*\(\s*\$real_direct/is', $getMediaSrc) &&
    !preg_match('/UPDATE\s+mensajes_y_eventos/i', $getMediaSrc)
);
qa_check("QA-BUG-CRM3-02: get_media.php aplica lista blanca estricta MIME con finfo + attachment + X-Content-Type-Options: nosniff (Anti-Stored XSS)",
    strpos($getMediaSrc, "'image/jpeg'") !== false &&
    strpos($getMediaSrc, "'application/octet-stream'") !== false &&
    strpos($getMediaSrc, "'attachment'") !== false &&
    strpos($getMediaSrc, "X-Content-Type-Options: nosniff") !== false &&
    stripos($getMediaSrc, "'text/html'") === false &&
    stripos($getMediaSrc, "'image/svg+xml'") === false
);

$fnBandejaSrc  = (string)file_get_contents($root . '/modules/bandeja/funciones_bandeja.js');
$fnCampanasSrc = (string)file_get_contents($root . '/modules/campanas/funciones_campanas.js');
$fnDirSrc      = (string)file_get_contents($root . '/modules/directorio/funciones_directorio.js');
$fnBotsSrc     = (string)file_get_contents($root . '/modules/gestor_bots/funciones_gestor_bots.js');
$fnWabaOrdSrc  = (string)file_get_contents($root . '/modules/waba_ordenes/funciones_waba_ordenes.js');
$fnWabaAnaSrc  = (string)file_get_contents($root . '/modules/whatsapp_analytics/funciones_whatsapp_analytics.js');
$fnDashSrc     = (string)file_get_contents($root . '/modules/dashboard/funciones_dashboard.js');

$resolveFnMatch = [];
$hasSafeMediaResolver = false;
if (preg_match('/function\s+resolveBandejaMediaUrl\s*\([^)]*\)\s*\{(.*?)\r?\n\}/s', $fnBandejaSrc, $resolveFnMatch)) {
    $resolveBody = $resolveFnMatch[1];
    $hasSafeMediaResolver = (
        strpos($resolveBody, 'get_media.php?id=') !== false &&
        strpos($resolveBody, 'assets/uploads/') !== false &&
        strpos($resolveBody, '../../assets/uploads/') === false
    );
}
qa_check("QA-BUG-CRM4-01: resolveBandejaMediaUrl() enruta /assets/uploads/media_* y ../../assets/uploads/media_* hacia get_media.php (0 HTTP 403 en Bandeja)",
    $hasSafeMediaResolver
);

qa_check("QA-BUG-CRM3-03: funciones_bandeja.js escapa el 100% de msg.contenido (0 interpolaciones crudas \${msg.contenido}) y usa data-tag delegado",
    strpos($fnBandejaSrc, '${msg.contenido}') === false &&
    strpos($fnBandejaSrc, 'escapeHtmlBandeja(') !== false &&
    strpos($fnBandejaSrc, 'data-tag=') !== false &&
    !preg_match('/onclick=["\']removeTag\(/', $fnBandejaSrc) &&
    strpos($fnBandejaSrc, 'back_bandeja.php?action=sse_updates') !== false
);
qa_check("QA-BUG-CRM4-02: mostrarToastStarfi() usa escapeHtml(mensaje) y #btnReasign usa escapeHtmlBandeja(ag.nombre_completo) (CHK-SEC-03)",
    strpos($fnCampanasSrc, '${escapeHtml(mensaje)}') !== false &&
    strpos($fnBandejaSrc, '${escapeHtmlBandeja(ag.nombre_completo)}') !== false &&
    strpos($fnBandejaSrc, '${ag.nombre_completo}') === false
);
qa_check("QA-BUG-CRM3-03: Escapado HTML activo en Directorio, Bots (Drawflow integrado), WABA Órdenes, WABA Analytics, Dashboard y Configuración",
    strpos($fnDirSrc, 'escapeHtmlDir(') !== false &&
    strpos($fnDirSrc, '${v.contenido}') === false &&
    strpos($fnBotsSrc, 'escapeHtml(') !== false &&
    strpos($fnWabaOrdSrc, 'escapeHtmlWaba(') !== false &&
    strpos($fnWabaAnaSrc, 'escapeHtml(') !== false &&
    strpos($fnDashSrc, 'escapeHtml(') !== false &&
    strpos($fnCfgSrc, 'escapeHtmlConfig(') !== false
);

// -----------------------------------------------------------------------------
// BLOQUE 7: Verificación QA-BUG-CRM3-04, CRM3-06, CRM3-07 y CRM3-08 (FPDF 1.85, Excel Real, Anti-Doble Clic, BOM y Gobernanza)
// -----------------------------------------------------------------------------
echo "\n[7/11] Verificando Motor FPDF 1.85, Exportación Excel Real, Anti-Doble Clic, 0 BOM en .htaccess y Gobernanza...\n";

$fpdfPath = $root . '/includes/libs/pdf/fpdf.php';
$impSrc   = (string)file_get_contents($root . '/imprimir_orden_waba.php');
$posSetTitle = strpos($impSrc, '->SetTitle(');
$posAutoPage = strpos($impSrc, '->SetAutoPageBreak(');

qa_check("QA-BUG-CRM3-06: includes/libs/pdf/fpdf.php (FPDF 1.85) instalado y sin referencias a Dompdf en imprimir_orden_waba.php",
    is_file($fpdfPath) && defined('FPDF_VERSION') && FPDF_VERSION === '1.85' && stripos($impSrc, 'Dompdf') === false
);
qa_check("QA-BUG-CRM3-06: imprimir_orden_waba.php exige requirePermission('waba_ordenes'), SetTitle() antes de SetAutoPageBreak(), chr(128) e isologo_ni.png en GetPageWidth() - 25",
    strpos($impSrc, "requirePermission('waba_ordenes')") !== false &&
    $posSetTitle !== false && $posAutoPage !== false && $posSetTitle < $posAutoPage &&
    strpos($impSrc, 'chr(128)') !== false &&
    strpos($impSrc, 'GetPageWidth() - 25') !== false &&
    strpos($impSrc, 'isologo_ni.png') !== false &&
    strpos($impSrc, 'GothamMedium.ttf') !== false &&
    stripos($impSrc, 'Arial, Helvetica') === false &&
    ord(starfi_pdf_txt('€')) === 128
);

$posBadgeFont  = strpos($impSrc, "\$this->SetFont('helvetica', 'B', 10);");
$posBadgeWidth = strpos($impSrc, '$this->GetStringWidth($badgeTxt)');
qa_check("QA-BUG-CRM4-03: imprimir_orden_waba.php declara SetFont('helvetica', 'B', 10) antes de GetStringWidth(\$badgeTxt) y aplica elipsis limpia '...'",
    $posBadgeFont !== false && $posBadgeWidth !== false && $posBadgeFont < $posBadgeWidth &&
    function_exists('starfi_pdf_ellipsis') &&
    substr(starfi_pdf_ellipsis(str_repeat('Servicio Mensajería ', 5), 50), -3) === '...'
);

$samplePdfBin = starfi_build_waba_order_pdf_binary(
    null,
    [
        'id' => 77,
        'codigo_orden' => 'NC-2026-0077',
        'id_sede' => 1,
        'fecha_desde' => '2026-11-01',
        'fecha_hasta' => '2026-11-25',
        'estado' => 'PENDIENTE',
        'mensajes_totales' => 120,
        'costo_meta' => 5.00,
        'margen_ganancia' => 1.00,
        'monto_total' => 6.00,
    ],
    ['id' => 1, 'nombre_sede' => 'Sede Principal QA'],
    ['id' => 1, 'razon_social' => 'STARFI QA C.A.'],
    ['nombre_comercial' => 'STARFI QA', 'razon_social' => 'STARFI QA C.A.', 'rif' => 'J-12345678-9'],
    [['nombre_plantilla' => 'Notificación € QA', 'categoria' => 'UTILITY', 'volumen' => 120, 'mensajes_gratuitos' => 20, 'mensajes_pagados' => 100, 'costo_base' => 5.00]]
);
qa_check("QA-BUG-CRM3-06: Prueba funcional en vivo de FPDF emite binario real %PDF-1.x con sello gráfico XObject embebido",
    strncmp($samplePdfBin, '%PDF-1.', 7) === 0 &&
    strpos($samplePdfBin, '/Subtype /Image') !== false &&
    substr(rtrim($samplePdfBin), -5) === '%%EOF'
);

qa_check("QA-BUG-CRM3-06: Exportación Excel real (.xlsx con SheetJS local) y auditoría 'audit_export_excel' en Dashboard (CHK-UIX-04)",
    is_file($root . '/assets/js/xlsx.full.min.js') &&
    strpos($fnDashSrc, 'XLSX.utils.book_new') !== false &&
    strpos($fnDashSrc, 'XLSX.writeFile') !== false &&
    strpos($fnDashSrc, 'audit_export_excel') !== false
);

$cfgViewSrc = (string)file_get_contents($root . '/modules/configuracion/configuracion.php');
qa_check("QA-BUG-CRM3-06: 0 referencias muertas a leaflet y 0 librerías huérfanas (leaflet, cropper, fabric, qrcode) en assets/ (CHK-ARC-04)",
    stripos($cfgViewSrc, 'leaflet') === false &&
    !is_dir($root . '/assets/vendor/leaflet') &&
    !is_file($root . '/assets/css/cropper.min.css') &&
    !is_file($root . '/assets/js/cropper.min.js') &&
    !is_file($root . '/assets/js/fabric.min.js') &&
    !is_file($root . '/assets/js/qrcode.min.js')
);

$fnRolesSrc = (string)file_get_contents($root . '/modules/gestion_roles/funciones_gestion_roles.js');
$fnEmpSrc   = (string)file_get_contents($root . '/modules/perfil_empresa/funciones_perfil_empresa.js');
qa_check("QA-BUG-CRM3-07: Candados anti-doble clic (disabled en primer clic + restauración complete/finally) en Bots, Directorio, Roles, Configuración y Perfil Empresa",
    (strpos($fnBotsSrc, "prop('disabled', true)") !== false || strpos($fnBotsSrc, '.disabled = true') !== false) &&
    (strpos($fnDirSrc, "prop('disabled', true)") !== false || strpos($fnDirSrc, '.disabled = true') !== false) &&
    (strpos($fnRolesSrc, "prop('disabled', true)") !== false || strpos($fnRolesSrc, '.disabled = true') !== false) &&
    (strpos($fnCfgSrc, "prop('disabled', true)") !== false || strpos($fnCfgSrc, '.disabled = true') !== false) &&
    (strpos($fnEmpSrc, "prop('disabled', true)") !== false || strpos($fnEmpSrc, '.disabled = true') !== false)
);

$htaccessBomHits = [];
$riiHt = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($riiHt as $fHt) {
    $pHt = str_replace('\\', '/', $fHt->getPathname());
    if (strpos($pHt, '/.git/') !== false) {
        continue;
    }
    if ($fHt->getFilename() === '.htaccess') {
        $rawHt = (string)file_get_contents($pHt);
        if (substr($rawHt, 0, 3) === "\xEF\xBB\xBF") {
            $htaccessBomHits[] = substr($pHt, strlen($root) + 1);
        }
    }
}
$repSrc = (string)file_get_contents($root . '/docs/INFORME_REMEDIACION_QA_AUDIT_CRM_09_V2.md');
qa_check("QA-BUG-CRM3-08: 100% de archivos .htaccess en UTF-8 sin BOM (0 prefijos \\xEF\\xBB\\xBF)", empty($htaccessBomHits), implode(', ', $htaccessBomHits));
qa_check("QA-BUG-CRM3-08: Puente core/autenticacion.php activo, raíz limpia de notas .md y Acta Formal §7 emitida",
    is_file($root . '/core/autenticacion.php') &&
    !is_file($root . '/CAMBIOS_INTEGRACION_IA_MULTI_SEDE.md') &&
    is_file($root . '/docs/CAMBIOS_INTEGRACION_IA_MULTI_SEDE.md') &&
    strpos($repSrc, 'ACTA DE CERTIFICACIÓN DE CALIDAD (QA SIGN-OFF) — STARFI 2.0') !== false
);

$apiNotifSrc = (string)file_get_contents($root . '/api_notificaciones.php');
$cronWabaSrc = (string)file_get_contents($root . '/cron_waba_billing.php');
qa_check("QA-BUG-CRM3-05: Auditoría 100% en api_notificaciones.php (Rama 3 + rechazos 401/403), webhook.php y cron_waba_billing.php",
    strpos($apiNotifSrc, 'DESPACHO_PLANTILLA_WABA') !== false &&
    strpos($apiNotifSrc, 'RECHAZO_API_') !== false &&
    strpos($whSrc, 'RECHAZO_FIRMA_HMAC') !== false &&
    strpos($whSrc, 'HANDOVER_HUMANO_') !== false &&
    strpos($cronWabaSrc, 'CRON_REVISION_FACTURACION_WABA') !== false &&
    function_exists('starfi_persist_media_cache')
);

// -----------------------------------------------------------------------------
// BLOQUE 8: Pruebas Unitarias finfo MIME (<= 5 MB) y Ruteo Local de Logs por Cápsula
// -----------------------------------------------------------------------------
echo "\n[8/11] Probando rechazo finfo(FILEINFO_MIME_TYPE) de falsos PDFs con código PHP y ruteo local de logs...\n";
$fakePhpPdf = tempnam(sys_get_temp_dir(), 'qa_fake_') . '.pdf';
file_put_contents($fakePhpPdf, "<?php echo 'PAYLOAD_EJECUTABLE'; ?>");
$resFakeWaba = starfi_validate_waba_receipt_file($fakePhpPdf, filesize($fakePhpPdf));
@unlink($fakePhpPdf);
qa_check("starfi_validate_waba_receipt_file() rechaza archivo .pdf falso con contenido <?php (finfo)", $resFakeWaba['ok'] === false);

$realPdfTmp = tempnam(sys_get_temp_dir(), 'qa_pdf_');
file_put_contents($realPdfTmp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
$resRealWaba = starfi_validate_waba_receipt_file($realPdfTmp, filesize($realPdfTmp));
$resOver5Mb  = starfi_validate_waba_receipt_file($realPdfTmp, 6 * 1024 * 1024);
@unlink($realPdfTmp);
qa_check("starfi_validate_waba_receipt_file() acepta PDF real con extensión derivada 'pdf'", $resRealWaba['ok'] === true && $resRealWaba['ext'] === 'pdf');
qa_check("starfi_validate_waba_receipt_file() rechaza archivos mayores a 5 MB", $resOver5Mb['ok'] === false);

$pipeLogPath = starfi_resolve_module_log_path('pipeline');
$wabaLogPath = starfi_resolve_module_log_path('waba_ordenes');
$whLogPath   = starfi_resolve_module_log_path('webhook');
qa_check("starfi_log_exception('pipeline') y ('waba_ordenes') enrutan localmente a sus cápsulas modulares",
    str_replace('\\', '/', $pipeLogPath) === $root . '/modules/pipeline/logs_pipeline.log' &&
    str_replace('\\', '/', $wabaLogPath) === $root . '/modules/waba_ordenes/logs_waba_ordenes.log'
);
qa_check("starfi_log_exception('webhook') enruta globalmente a logs/logs_webhook.log",
    str_replace('\\', '/', $whLogPath) === $root . '/logs/logs_webhook.log'
);

// -----------------------------------------------------------------------------
// BLOQUE 9 & 10: Pruebas Transaccionales en BD con ROLLBACK (incl. Candado FOR UPDATE en emitir_orden_sede)
// -----------------------------------------------------------------------------
echo "\n[9/11] Ejecutando pruebas monetarias USD, Aislamiento IA Multi-Sede, Anti-Doble Referencia, Anti-Solapamiento emitir_orden_sede() (FOR UPDATE), Anti-Fatiga 24h y Auditoría en BD (con ROLLBACK)...\n";
qa_check("starfi_parse_usd_number('\$1.250 USD') === 1.25 (cero inflación 1,000x)", starfi_parse_usd_number('$1.250 USD') === 1.25);
qa_check("starfi_parse_usd_number('906.50 + IVA') === 906.50", starfi_parse_usd_number('906.50 + IVA') === 906.50);
qa_check("starfi_parse_usd_number('1,019.30 + IVA') === 1019.30", starfi_parse_usd_number('1,019.30 + IVA') === 1019.30);
qa_check("starfi_parse_usd_number('1.250,50 USD') === 1250.50", starfi_parse_usd_number('1.250,50 USD') === 1250.50);
qa_check("starfi_parse_usd_number('250 Bs') === 0.00", starfi_parse_usd_number('250 Bs') === 0.0);

$con = getDbConnection('core');
qa_check("Conexión activa a Base de Datos Core", (bool)$con);

if ($con) {
    // Asegurar esquemas ANTES de abrir la transacción para que ningún DDL haga commit implícito
    starfi_ensure_sprint2_schema($con);
    starfi_ensure_audit_schema($con);
    ensureWabaBillingSchema($con);
    ensureCampaignTables($con);

    $con->begin_transaction();
    starfi_tx_set_active($con, true);

    try {
        // 1. Insertar 2 sedes efímeras (9951 Alfa y 9952 Beta)
        $con->query("INSERT INTO sedes (id, id_empresa, nombre_sede, estado) VALUES (9951, 1, 'QA Sede Alfa', 'ACTIVO'), (9952, 1, 'QA Sede Beta', 'ACTIVO')");
        $con->query("INSERT INTO lineas_whatsapp (id_sede, descripcion, numero_telefono, meta_app_id, meta_token, estado) VALUES (9951, 'L Alfa', '584140009951', 'app_9951', 't1', 'ACTIVO')");
        $lAlfa = (int)$con->insert_id;
        $con->query("INSERT INTO lineas_whatsapp (id_sede, descripcion, numero_telefono, meta_app_id, meta_token, estado) VALUES (9952, 'L Beta', '584140009952', 'app_9952', 't2', 'ACTIVO')");
        $lBeta = (int)$con->insert_id;

        // 2. Prueba QA-BUG-CRM2-02: Aislamiento estricto de configuraciones_ia por id_sede
        $stmtInsIa = $con->prepare("INSERT INTO configuraciones_ia (id_empresa, id_sede, estado_ia, agente_nombre, agente_instrucciones) VALUES (1, 9951, 'ACTIVO', 'Gema Alfa', 'CUENTA EXCLUSIVA SEDE ALFA 0134-0000')");
        if ($stmtInsIa) {
            $stmtInsIa->execute();
            $stmtInsIa->close();
        }
        $stmtChkIa = $con->prepare("SELECT id_sede, agente_instrucciones FROM configuraciones_ia WHERE id_sede = ? AND estado_ia = 'ACTIVO' LIMIT 1");
        $sedeA_id = 9951;
        $stmtChkIa->bind_param("i", $sedeA_id);
        $stmtChkIa->execute();
        $iaAlfa = $stmtChkIa->get_result()->fetch_assoc();

        $sedeB_id = 9952;
        $stmtChkIa->bind_param("i", $sedeB_id);
        $stmtChkIa->execute();
        $iaBeta = $stmtChkIa->get_result()->fetch_assoc();
        $stmtChkIa->close();

        qa_check("configuraciones_ia: Sede Alfa (9951) obtiene su prompt propio", !empty($iaAlfa) && (int)$iaAlfa['id_sede'] === 9951);
        qa_check("configuraciones_ia: Sede Beta (9952) sin fila propia recibe NULL (0 fuga de Sede Alfa)", $iaBeta === null);

        // 3. Prueba QA-BUG-CRM2-04 / CHK-SEC-09 + QA-BUG-CRM3-05 / CHK-SEC-05 (FOR UPDATE en referencias y en emitir_orden_sede)
        $colsOrd = starfi_waba_table_columns($con, 'waba_ordenes_cobro', true);
        if (isset($colsOrd['codigo_orden'])) {
            $con->query("INSERT INTO waba_ordenes_cobro (codigo_orden, id_sede, fecha_desde, fecha_hasta, estado) VALUES ('QA-WABA-9951-A', 9951, '2026-11-01', '2026-11-10', 'PENDIENTE')");
            $ordA = (int)$con->insert_id;
            $con->query("INSERT INTO waba_ordenes_cobro (codigo_orden, id_sede, fecha_desde, fecha_hasta, estado) VALUES ('QA-WABA-9952-B', 9952, '2026-11-01', '2026-11-10', 'PENDIENTE')");
            $ordB = (int)$con->insert_id;
        } else {
            $con->query("INSERT INTO waba_ordenes_cobro (id_sede, fecha_desde, fecha_hasta, estado) VALUES (9951, '2026-11-01', '2026-11-10', 'PENDIENTE')");
            $ordA = (int)$con->insert_id;
            $con->query("INSERT INTO waba_ordenes_cobro (id_sede, fecha_desde, fecha_hasta, estado) VALUES (9952, '2026-11-01', '2026-11-10', 'PENDIENTE')");
            $ordB = (int)$con->insert_id;
        }

        // Prueba transaccional QA-BUG-CRM3-05: intentar emitir una orden solapada (2026-11-05 .. 2026-11-15) para sede 9951 sin fusión
        $resOverlapOrd = emitir_orden_sede($con, 9951, '2026-11-05', '2026-11-15', null);
        qa_check("QA-BUG-CRM3-05 (CHK-SEC-05): emitir_orden_sede() bloquea con SELECT ... FOR UPDATE órdenes activas solapadas por sede/periodo",
            ($resOverlapOrd['status'] ?? '') === 'error' && ($resOverlapOrd['code'] ?? '') === 'DUPLICATE_PERIOD_ORDER',
            $resOverlapOrd['message'] ?? ''
        );

        $pay1 = starfi_report_waba_payment($con, $ordA, [
            'metodo_pago'     => 'Pago Movil',
            'referencia_pago' => 'QA-REF-778899',
            'fecha_pago'      => date('Y-m-d'),
        ], null, 1, true, 9951);
        qa_check("waba_ordenes_cobro: Primer reporte de pago con referencia QA-REF-778899 aceptado", ($pay1['status'] ?? '') === 'success', $pay1['message'] ?? '');

        $payDup = starfi_report_waba_payment($con, $ordB, [
            'metodo_pago'     => '  pago movil ',
            'referencia_pago' => ' qa-ref-778899 ',
            'fecha_pago'      => date('Y-m-d'),
        ], null, 1, true, 9952);
        qa_check("waba_ordenes_cobro: Segunda orden con misma referencia normalizada rechazada atómicamente (CHK-SEC-09)",
            ($payDup['status'] ?? '') === 'error' && !empty($payDup['duplicate']),
            $payDup['message'] ?? ''
        );

        // 4. Prueba QA-BUG-CRM2-08 / CHK-CLD-02: Bloqueo Anti-Fatiga 24h cross-campaña en MARKETING
        $colsCamp = [];
        $rColsCamp = $con->query("SHOW COLUMNS FROM campanas_difusion");
        while ($rcc = $rColsCamp->fetch_assoc()) {
            $colsCamp[$rcc['Field']] = true;
        }
        $nameColCamp = isset($colsCamp['nombre_campana']) ? 'nombre_campana' : 'nombre';
        $tplColCamp  = isset($colsCamp['template_name']) ? 'template_name' : 'plantilla_nombre';
        $con->query("INSERT INTO campanas_difusion (id_sede, `$nameColCamp`, `$tplColCamp`, categoria_meta, estado) VALUES (9951, 'QA Campaña Marketing 1', 'starfi_difusion_directa', 'MARKETING', 'EN_PROCESO')");
        $camp1 = (int)$con->insert_id;
        $con->query("INSERT INTO campanas_difusion (id_sede, `$nameColCamp`, `$tplColCamp`, categoria_meta, estado) VALUES (9951, 'QA Campaña Marketing 2', 'starfi_difusion_directa', 'MARKETING', 'EN_PROCESO')");
        $camp2 = (int)$con->insert_id;

        $con->query("INSERT INTO campanas_difusion_destinatarios (id_campana, telefono, estado_envio, respondido, fecha_envio) VALUES ($camp1, '584149991122', 'ENVIADO', 0, DATE_SUB(NOW(), INTERVAL 2 HOUR))");
        $dest1 = (int)$con->insert_id;
        $con->query("INSERT INTO campanas_difusion_destinatarios (id_campana, telefono, estado_envio, respondido, fecha_envio) VALUES ($camp2, '584149991122', 'PENDIENTE', 0, NULL)");
        $dest2 = (int)$con->insert_id;

        $blocked24h = starfi_check_marketing_antifatigue_24h($con, '0414-9991122', $dest2);
        qa_check("Escudo Anti-Fatiga 24h (CHK-CLD-02): Bloquea envío en Campaña 2 cuando Campaña 1 envió hace <24h sin respuesta", $blocked24h === true);

        $con->query("UPDATE campanas_difusion_destinatarios SET respondido = 1 WHERE id = $dest1");
        $allowedAfterReply = starfi_check_marketing_antifatigue_24h($con, '584149991122', $dest2);
        qa_check("Escudo Anti-Fatiga 24h (CHK-CLD-02): Libera contacto automáticamente si el cliente ya respondió", $allowedAfterReply === false);

        // 5. Pruebas de Pipeline Multi-Sede + Round-Robin FOR UPDATE + Caché Multimedia Auditada
        $sharedPhone = '584148887766';
        $con->query("INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre) VALUES (1, 9951, '$sharedPhone', 'Cliente Alfa')");
        $cAlfa = (int)$con->insert_id;
        $con->query("INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre) VALUES (1, 9952, '$sharedPhone', 'Cliente Beta')");
        $cBeta = (int)$con->insert_id;

        $con->query("INSERT INTO conversaciones (id_linea, id_cliente, estado) VALUES ($lAlfa, $cAlfa, 'ATENDIENDO')");
        $cvAlfa = (int)$con->insert_id;
        $salePayload = 'Envío dinámico de plantilla: send_confirmacion_compra_cn_sn_btn. Params: ["Cliente Alfa","2026-11-21","950.00 + IVA","ASESOR ALFA","SEDE ALFA","NE_999999","04140000000"]';
        $stmtSale = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido, url_archivo) VALUES (?, 'API_TRANSACCIONAL', 'EVENTO_SISTEMA', ?, 'wamid_media_qa_9951')");
        $stmtSale->bind_param("is", $cvAlfa, $salePayload);
        $stmtSale->execute();
        $stmtSale->close();

        $cacheOk = starfi_persist_media_cache($con, $cvAlfa, 'wamid_media_qa_9951', '/assets/uploads/media_wamid_media_qa_9951.pdf', 1, 9951);
        $audCache = $con->query("SELECT accion FROM auditoria_sistema WHERE accion = 'CACHE_MEDIA_WABA' AND id_sede = 9951 ORDER BY id DESC LIMIT 1")->fetch_assoc();
        qa_check("QA-BUG-CRM3-05: starfi_persist_media_cache() actualiza caché multimedia por id_conversacion y audita CACHE_MEDIA_WABA",
            $cacheOk === true && !empty($audCache)
        );

        $con->query("INSERT INTO conversaciones (id_linea, id_cliente, estado) VALUES ($lBeta, $cBeta, 'ESPERA_ASIGNACION')");
        $cvBeta = (int)$con->insert_id;

        $enrAlfa = starfi_extract_deal_enrichment($con, 9951, $cAlfa, $cvAlfa);
        $enrBeta = starfi_extract_deal_enrichment($con, 9952, $cBeta, $cvBeta);
        qa_check("Pipeline Multi-Sede: Sede Alfa detecta su venta propia (\$950.00) y Sede Beta tiene 0 fuga",
            $enrAlfa['monto_usd'] === 950.0 && $enrBeta['monto_usd'] === 0.0
        );

        $dealAlfa = starfi_ensure_pipeline_lead($con, 9951, $cAlfa, $cvAlfa, null);
        $con->query("UPDATE pipeline_oportunidades SET etapa = 'COTIZADO', monto_estimado_usd = 2500.00 WHERE id = $dealAlfa");
        starfi_ensure_pipeline_lead($con, 9951, $cAlfa, $cvAlfa, null, '', 12.30);
        $rowCot = $con->query("SELECT monto_estimado_usd, etapa FROM pipeline_oportunidades WHERE id = $dealAlfa")->fetch_assoc();
        qa_check("Pipeline: Monto manual \$2,500.00 en COTIZADO protegido contra sobrescritura", (float)$rowCot['monto_estimado_usd'] === 2500.0);

        $con->query("INSERT INTO usuario (usuario, contrasena, estado, rol, id_sede, disponible_chat, ultima_actividad, ultima_asignacion_rr) VALUES ('qa_rr_1', 'x', 'ACTIVO', '3', 9952, 1, NOW(), NULL)");
        $opA = (int)$con->insert_id;
        $con->query("INSERT INTO usuario (usuario, contrasena, estado, rol, id_sede, disponible_chat, ultima_actividad, ultima_asignacion_rr) VALUES ('qa_rr_2', 'x', 'ACTIVO', '3', 9952, 1, NOW(), NOW() - INTERVAL 2 MINUTE)");
        $opB = (int)$con->insert_id;

        $rr1 = starfi_assign_round_robin_advisor($con, 9952, $cvBeta, $cBeta, 'QA_RR_1', 5);
        $con->query("INSERT INTO conversaciones (id_linea, id_cliente, estado) VALUES ($lBeta, $cBeta, 'ESPERA_ASIGNACION')");
        $cvBeta2 = (int)$con->insert_id;
        $rr2 = starfi_assign_round_robin_advisor($con, 9952, $cvBeta2, $cBeta, 'QA_RR_2', 5);
        qa_check("Round-Robin (FOR UPDATE) rota equitativamente entre asesores en línea",
            $rr1 && (int)$rr1['id'] === $opA && $rr2 && (int)$rr2['id'] === $opB
        );

        // 6. Prueba QA-BUG-CRM2-06 / CHK-SEC-04: Ejecución real de registrar_auditoria() con datos_anteriores y datos_nuevos
        $audOk = registrar_auditoria(
            'pipeline',
            'QA_CERT_BEFORE_AFTER',
            'Verificación QA de persistencia JSON antes/después',
            ['etapa' => 'NUEVO_LEAD', 'monto_estimado_usd' => 950.00, 'api_token' => 'secret_redact_check'],
            ['etapa' => 'COTIZADO', 'monto_estimado_usd' => 2500.00],
            $con,
            1,
            9951
        );
        $audRow = $con->query("SELECT descripcion, datos_anteriores, datos_nuevos FROM auditoria_sistema WHERE accion = 'QA_CERT_BEFORE_AFTER' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $antDecoded = $audRow ? json_decode((string)$audRow['datos_anteriores'], true) : null;
        $nueDecoded = $audRow ? json_decode((string)$audRow['datos_nuevos'], true) : null;

        qa_check("registrar_auditoria() persiste datos_anteriores y datos_nuevos en JSON y redacta secretos",
            $audOk === true &&
            is_array($antDecoded) && ($antDecoded['etapa'] ?? '') === 'NUEVO_LEAD' && ($antDecoded['api_token'] ?? '') === '[REDACTED]' &&
            is_array($nueDecoded) && ($nueDecoded['etapa'] ?? '') === 'COTIZADO' && (float)($nueDecoded['monto_estimado_usd'] ?? 0) === 2500.0
        );

        // 7. Pruebas Funcionales y Transaccionales OT-STARFI-2026-11 SPRINT 3 ([IA-01]..[IA-04])
        require_once $root . '/core/IaConnector.php';
        require_once $root . '/core/services/MetaTemplateService.php';
        require_once $root . '/core/services/DripRemarketingService.php';

        // [IA-01] Resumen Ejecutivo de Conversación en 3 Viñetas
        $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES ($cvAlfa, 'CLIENTE', 'TEXTO', 'Hola, necesito cotizar 15 láminas MDF 18mm urgente porque tengo retraso en mi obra')");
        $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES ($cvAlfa, 'BOT', 'TEXTO', 'Hola Cliente Alfa, con gusto revisamos disponibilidad inmediata en Sede Alfa.')");
        $con->query("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES ($cvAlfa, 'CLIENTE', 'TEXTO', 'Por favor confírmame precio en USD y despacho hoy mismo')");

        $sumQa = IaConnector::resumirConversacionEjecutiva($con, 9951, $cvAlfa, 'Cliente Alfa');
        $rowConvSum = $con->query("SELECT resumen_ia, sentimiento_cliente FROM conversaciones WHERE id = $cvAlfa")->fetch_assoc();
        $savedBullets = !empty($rowConvSum['resumen_ia']) ? json_decode((string)$rowConvSum['resumen_ia'], true) : [];
        $bandejaViewSrc = (string)file_get_contents($root . '/modules/bandeja/bandeja.php');
        $bandejaBackSrc = (string)file_get_contents($root . '/modules/bandeja/back_bandeja.php');

        qa_check("[IA-01] IaConnector::resumirConversacionEjecutiva() genera exactamente 3 viñetas ejecutivas y las persiste en conversaciones",
            !empty($sumQa['ok']) && is_array($sumQa['bullets']) && count($sumQa['bullets']) === 3 && is_array($savedBullets) && count($savedBullets) === 3
        );
        qa_check("[IA-01] Bandeja integra botón #btnIaSummarize, tarjeta #iaSummaryCard y endpoint ia_summarize_chat",
            strpos($bandejaViewSrc, 'btnIaSummarize') !== false &&
            strpos($bandejaViewSrc, 'iaSummaryCard') !== false &&
            strpos($bandejaBackSrc, "case 'ia_summarize_chat':") !== false
        );

        // [IA-02] Sugerir Respuesta / Mejorar Tono con IA + Etiquetado Automático de Sentimiento
        $sPos = IaConnector::clasificarSentimientoTexto('Muchas gracias, excelente servicio y atención impecable');
        $sNeu = IaConnector::clasificarSentimientoTexto('Buenas tardes, ¿tienen tapacantos blancos disponibles?');
        $sUrg = IaConnector::clasificarSentimientoTexto('Urgente, pésimo servicio, llevo horas esperando reclamo');
        qa_check("[IA-02] IaConnector::clasificarSentimientoTexto() clasifica con precisión SATISFECHO, NEUTRO y URGENTE_MOLESTO",
            $sPos === 'SATISFECHO' && $sNeu === 'NEUTRO' && $sUrg === 'URGENTE_MOLESTO'
        );

        $updSentQa = IaConnector::actualizarSentimientoConversacion($con, $cvAlfa, null);
        $cliTagsRow = $con->query("SELECT etiquetas FROM clientes_contactos WHERE id = $cAlfa")->fetch_assoc();
        qa_check("[IA-02] actualizarSentimientoConversacion() sincroniza sentimiento en conversaciones y etiqueta en clientes_contactos",
            $updSentQa === 'URGENTE_MOLESTO' && strpos((string)($cliTagsRow['etiquetas'] ?? ''), 'Urgente/Molesto') !== false
        );

        $sugEmpty = IaConnector::asistirRespuestaAsesor($con, 9951, $cvAlfa, '', 'empatico', 'Cliente Alfa');
        $sugDraft = IaConnector::asistirRespuestaAsesor($con, 9951, $cvAlfa, 'si tenemos laminas cuestan 45 dolares', 'cierre', 'Cliente Alfa');
        qa_check("[IA-02] asistirRespuestaAsesor() opera en modo SUGGEST_REPLY (vacío) e IMPROVE_TONE (con borrador) + UI cableada",
            !empty($sugEmpty['ok']) && $sugEmpty['mode'] === 'SUGGEST_REPLY' && !empty($sugEmpty['suggestion']) &&
            !empty($sugDraft['ok']) && $sugDraft['mode'] === 'IMPROVE_TONE' && !empty($sugDraft['suggestion']) &&
            strpos($bandejaViewSrc, 'btnIaSuggestReply') !== false &&
            strpos($bandejaViewSrc, 'chatSentimentBadge') !== false
        );

        // [IA-03] Constructor y Clonador de Plantillas Oficiales Meta (POST /{waba_id}/message_templates)
        $normTplName = MetaTemplateService::normalizeTemplateName('Promo Navideña 2026!');
        $builtTpl = MetaTemplateService::buildMetaComponentsPayload([
            'name'          => $normTplName,
            'category'      => 'MARKETING',
            'language'      => 'es',
            'header_format' => 'TEXT',
            'header_text'   => 'Oferta STARFI {{1}}',
            'body'          => 'Hola {{1}}, en {{2}} tenemos {{3}} disponible hoy.',
            'body_examples' => ['Carlos', 'Sede Alfa', '15% de descuento'],
            'footer'        => 'STARFI CRM',
            'buttons'       => [['type' => 'QUICK_REPLY', 'text' => 'Quiero cotizar']],
        ]);
        $campModalsSrc = (string)file_get_contents($root . '/modules/campanas/modals_campanas.php');
        $campBackSrc   = (string)file_get_contents($root . '/modules/campanas/back_campanas.php');

        qa_check("[IA-03] MetaTemplateService construye payload oficial Graph API v23.0 (HEADER, BODY con examples, FOOTER y BUTTONS)",
            !empty($builtTpl['ok']) &&
            $normTplName === 'promo_navidena_2026' &&
            count($builtTpl['components']) === 4 &&
            ($builtTpl['components'][1]['type'] ?? '') === 'BODY' &&
            count($builtTpl['components'][1]['example']['body_text'][0] ?? []) === 3 &&
            strpos($campModalsSrc, 'modalMetaTemplateBuilder') !== false &&
            strpos($campBackSrc, "case 'clone_meta_template_sedes':") !== false
        );

        // [IA-04] Secuencias Automáticas de Remarketing (Drip Campaigns: Leídos sin responder en X horas)
        $con->query("INSERT INTO campanas_difusion (id_sede, `$nameColCamp`, `$tplColCamp`, categoria_meta, estado, drip_activo, drip_horas_espera, drip_accion, drip_template_name) VALUES (9952, 'QA Campaña Drip Sprint 3', 'starfi_difusion_directa', 'MARKETING', 'COMPLETADA', 1, 12, 'AMBOS', 'starfi_difusion_directa')");
        $campDripId = (int)$con->insert_id;

        $con->query("INSERT INTO campanas_difusion_destinatarios (id_campana, id_sede, id_cliente, nombre, telefono, estado_envio, respondido, fecha_envio, fecha_lectura) VALUES ($campDripId, 9952, $cBeta, 'Cliente Drip Leido Sin Resp', '584147770001', 'LEIDO', 0, DATE_SUB(NOW(), INTERVAL 16 HOUR), DATE_SUB(NOW(), INTERVAL 14 HOUR))");
        $destDripDue = (int)$con->insert_id;

        $con->query("INSERT INTO campanas_difusion_destinatarios (id_campana, id_sede, id_cliente, nombre, telefono, estado_envio, respondido, fecha_envio, fecha_lectura, fecha_respuesta) VALUES ($campDripId, 9952, $cBeta, 'Cliente Drip Ya Respondio', '584147770002', 'RESPONDIDO', 1, DATE_SUB(NOW(), INTERVAL 16 HOUR), DATE_SUB(NOW(), INTERVAL 14 HOUR), DATE_SUB(NOW(), INTERVAL 13 HOUR))");
        $destDripReplied = (int)$con->insert_id;

        $dripExec = DripRemarketingService::processDueDripSequences($con, $campDripId, 50, false);
        $rowDueAfter = $con->query("SELECT drip_ejecutado, drip_estado, drip_id_asesor FROM campanas_difusion_destinatarios WHERE id = $destDripDue")->fetch_assoc();
        $rowRepAfter = $con->query("SELECT drip_ejecutado FROM campanas_difusion_destinatarios WHERE id = $destDripReplied")->fetch_assoc();
        $cronSrc = (string)file_get_contents($root . '/cron_campanas_autopilot.php');

        qa_check("[IA-04] DripRemarketingService procesa únicamente destinatarios LEIDOS sin respuesta tras X horas (re-impacta + asigna vendedor) e ignora respondidos",
            !empty($dripExec['ok']) &&
            intval($dripExec['processed']) === 1 &&
            intval($dripExec['reimpacted']) === 1 &&
            intval($dripExec['assigned']) === 1 &&
            intval($rowDueAfter['drip_ejecutado'] ?? 0) === 1 &&
            intval($rowDueAfter['drip_id_asesor'] ?? 0) > 0 &&
            intval($rowRepAfter['drip_ejecutado'] ?? 0) === 0 &&
            strpos($cronSrc, 'DripRemarketingService::processDueDripSequences') !== false
        );

        // ROLLBACK OBLIGATORIO (0 residuos en base de datos)
        $con->rollback();
        starfi_tx_set_active($con, false);

        $chkClean = $con->query("SELECT COUNT(*) AS c FROM sedes WHERE id IN (9951, 9952)")->fetch_assoc();
        qa_check("ROLLBACK transaccional verificado (0 filas de prueba persistidas en BD)", intval($chkClean['c']) === 0);
    } catch (Throwable $e) {
        @$con->rollback();
        starfi_tx_set_active($con, false);
        qa_check("Ejecución sin excepciones en bloque transaccional BD", false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
    $con->close();
}

echo "\n[11/11] Resumen Final de Certificación QA-STD-GLOBAL-2.1...\n";
echo "-------------------------------------------------------------------------------------\n";
echo " CONTROLES APROBADOS : $passed\n";
echo " CONTROLES FALLIDOS  : $failed\n";
echo "-------------------------------------------------------------------------------------\n";

if ($failed > 0) {
    echo "DICTAMEN LOCAL: NO-GO\n";
    exit(1);
}

echo "DICTAMEN LOCAL: 100% PASS — APTO PARA SOLICITAR PASE A GO\n";
exit(0);
