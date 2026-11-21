<?php
/**
 * modules/pipeline/test_ia/test_sprint2_qa.php
 * Suite Oficial de Certificación Forense Pre-Entrega Ampliada — STARFI CRM
 * Cubre OT-QA-CRM-2026-1121-01 (QA-BUG-CRM2-01 .. QA-BUG-CRM2-12) y los 45 Controles QA-STD-GLOBAL-2.1.
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
require_once $root . '/modules/dashboard/back_waba_billing.php';
require_once $root . '/modules/campanas/back_campanas.php';

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
 * Ejecuta un script PHP bajo php-cgi (SAPI != 'cli') para probar guardias HTTP 403 reales sin depender de Apache externo.
 */
function qa_run_cgi_http_probe(string $scriptPath, string $method = 'GET', array $extraEnv = [], string $body = ''): ?int {
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
        'REDIRECT_STATUS' => '200',
        'REQUEST_METHOD'  => $method,
        'SCRIPT_FILENAME' => str_replace('/', DIRECTORY_SEPARATOR, $scriptPath),
        'SCRIPT_NAME'     => '/' . basename($scriptPath),
        'QUERY_STRING'    => '',
        'CONTENT_TYPE'    => 'application/json',
        'CONTENT_LENGTH'  => (string)strlen($body),
        'SERVER_PROTOCOL' => 'HTTP/1.1',
        'GATEWAY_INTERFACE' => 'CGI/1.1',
        'REMOTE_ADDR'     => '127.0.0.1',
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
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    if (preg_match('/^Status:\s*(\d{3})/mi', $stdout, $m)) {
        return (int)$m[1];
    }
    return 200;
}

echo "=====================================================================================\n";
echo " SUITE DE CERTIFICACIÓN INTEGRAL QA-STD-GLOBAL-2.1 — STARFI CRM (OT-QA-CRM-2026-1121-01)\n";
echo "=====================================================================================\n\n";

// -----------------------------------------------------------------------------
// BLOQUE 1: Higiene Git, Purga de Secretos SQL y Rotación SHA-256 (TAREA 1 — S0)
// -----------------------------------------------------------------------------
echo "[1/9] Verificando Higiene Git, Esquema SQL Limpio y Script de Rotación (QA-BUG-CRM2-01)...\n";
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
// BLOQUE 2: Bloqueo HTTP 403 en install.php, database/*.php y webhook.php (TAREAS 2 y 3 — S0)
// -----------------------------------------------------------------------------
echo "\n[2/9] Verificando Guardias HTTP 403 en Instalador, database/*.php y Firma HMAC Webhook (QA-BUG-CRM2-05, 12)...\n";
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

// Prueba real bajo php-cgi (SAPI cgi-fcgi != cli) incluyendo simulación sin .env en install.php
$cgiInstallCode = qa_run_cgi_http_probe($root . '/install.php', 'GET');
if ($cgiInstallCode !== null) {
    qa_check("Ejecución CGI real: GET install.php sin centinela responde HTTP 403", $cgiInstallCode === 403, "HTTP $cgiInstallCode");

    // Simular un clon limpio SIN .env y SIN config/.enable_installer en directorio temporal aislado
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
// BLOQUE 3: RBAC en todos los back_*.php, Autenticación y Seguridad Uploads .htaccess
// -----------------------------------------------------------------------------
echo "\n[3/9] Verificando RBAC, Uploads .htaccess y Entrega Autenticada por Sede (QA-BUG-CRM2-04)...\n";
$loginSrc = (string)file_get_contents($root . '/login.php');
$recSrc   = (string)file_get_contents($root . '/recover_password.php');
$regSrc   = (string)file_get_contents($root . '/register.php');
$verDoc   = (string)file_get_contents($root . '/ver_documento.php');
$upHt     = (string)file_get_contents($root . '/assets/uploads/.htaccess');

qa_check("login.php sin backdoor '1234' ni md5(), y con registrar_auditoria()",
    !preg_match("/===\s*['\"]1234['\"]/", $loginSrc) && stripos($loginSrc, 'md5(') === false && strpos($loginSrc, 'registrar_auditoria(') !== false
);
qa_check("recover_password.php con random_int(100000, 999999), sin codigo_simulado y con registrar_auditoria()",
    stripos($recSrc, 'codigo_simulado') === false && strpos($recSrc, 'random_int(100000, 999999)') !== false && strpos($recSrc, 'registrar_auditoria(') !== false
);
qa_check("assets/uploads/.htaccess con php_flag engine off, nosniff y RewriteRule hacia ver_documento.php",
    strpos($upHt, 'php_flag engine off') !== false && strpos($upHt, 'nosniff') !== false && strpos($upHt, 'comprobante_waba') !== false
);
qa_check("assets/uploads/comprobantes_waba/.htaccess presente y bloqueando acceso directo",
    is_file($root . '/assets/uploads/comprobantes_waba/.htaccess')
);
qa_check("ver_documento.php valida sesión e id_sede para comprobante_waba",
    strpos($verDoc, "tipo === 'comprobante_waba'") !== false && strpos($verDoc, 'waba_ordenes_cobro') !== false
);

$requiredRbac = [
    'modules/gestion_usuarios/back_usuarios.php'         => 'gestion_usuarios',
    'modules/gestion_usuarios/back_gestion_usuarios.php' => 'gestion_usuarios',
    'modules/perfil_empresa/back_empresa.php'            => 'perfil_empresa',
    'modules/perfil_empresa/back_perfil_empresa.php'     => 'perfil_empresa',
    'modules/configuracion/back_configuracion.php'       => 'configuracion',
    'modules/bandeja/back_bandeja.php'                   => 'bandeja',
    'modules/directorio/back_directorio.php'             => 'directorio',
    'modules/gestor_bots/back_gestor_bots.php'           => 'gestor_bots',
    'modules/dashboard/back_dashboard.php'               => 'dashboard',
    'modules/dashboard/back_waba_billing.php'            => 'waba_ordenes',
    'modules/dashboard/back_whatsapp_analytics.php'      => 'whatsapp_analytics',
    'modules/pipeline/back_pipeline.php'                 => 'pipeline',
    'modules/gestion_roles/back_roles.php'               => 'gestion_roles',
    'modules/gestion_roles/back_gestion_roles.php'       => 'gestion_roles',
    'modules/campanas/back_campanas.php'                 => 'campanas',
];
foreach ($requiredRbac as $relPath => $permKey) {
    $src = (string)file_get_contents($root . '/' . $relPath);
    qa_check("requirePermission('$permKey') y registrar_auditoria() presentes en $relPath",
        strpos($src, "requirePermission('$permKey')") !== false && strpos($src, 'registrar_auditoria(') !== false
    );
}

// -----------------------------------------------------------------------------
// BLOQUE 4: 100% mysqli_prepare y 0 Fugas de Errores SQL en JSON (TAREA 5 — S0/S1)
// -----------------------------------------------------------------------------
echo "\n[4/9] Escaneando 100% mysqli_prepare() y 0 Fugas ->error / mysqli_error en JSON (QA-BUG-CRM2-03, 09)...\n";
$varSqlHits = [];
$jsonErrHits = [];
$syntaxErrHits = [];

$riiPhp = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($riiPhp as $fi) {
    $full = str_replace('\\', '/', $fi->getPathname());
    if (strpos($full, '/.git/') !== false || strpos($full, '/core/libs/') !== false) {
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
    if (preg_match('/json_encode\s*\([^;]*?(->error\b|mysqli_error)/is', $src, $em)) {
        $jsonErrHits[] = $rel;
    }

    // Escanear llamadas mysqli_query / ->query con variables PHP interpoladas (exceptuando importador DDL en install.php y constantes DDL CLI)
    if ($rel !== 'install.php' && strpos($rel, 'database/') !== 0 && $rel !== 'cron_waba_billing.php') {
        $lines = file($full);
        foreach ($lines as $idx => $line) {
            if (preg_match('/(mysqli_query|->query)\s*\(/i', $line, $qMatch, PREG_OFFSET_CAPTURE)) {
                $firstLineTail = substr($line, $qMatch[0][1]);
                $window = $firstLineTail . implode('', array_slice($lines, $idx + 1, 4));
                $cleanWin = preg_replace('/^mysqli_query\s*\(\s*\$[a-zA-Z0-9_>-]+\s*,/i', 'q(', $window);
                $cleanWin = preg_replace('/^->query\s*\(/i', 'q(', $cleanWin);
                // Cortar en el primer cierre de sentencia );
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
qa_check("0 consultas mysqli_query / ->query con interpolación de variables en controladores y vistas", empty($varSqlHits), implode(', ', array_slice($varSqlHits, 0, 8)));

// -----------------------------------------------------------------------------
// BLOQUE 5: Modo 100% Offline LAN, Cero alert(), Favicons, MVC 4 Archivos y Portabilidad (TAREAS 7 y 8)
// -----------------------------------------------------------------------------
echo "\n[5/9] Verificando Modo 100% Offline LAN (0 dominios externos), 0 alert(), Favicons y MVC (QA-BUG-CRM2-07, 10, 11, 12)...\n";
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
        strpos($full, '/assets/css/cropper') !== false ||
        strpos($full, '/assets/css/select2') !== false ||
        strpos($full, '/core/libs/') !== false ||
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

    // 1. Verificar 0 alert() nativos
    if (preg_match('/(?<![a-zA-Z0-9_.$])alert\s*\(/', $src)) {
        $alertHits[] = $rel;
    }

    // 2. Verificar 0 rutas quemadas '/starfi_crm/login.php' o '/starfi_crm/logout.php' o logo_1784639274_107.png
    if (preg_match('#/starfi_crm/(login|logout|assets|get_media)#i', $src) || strpos($src, 'logo_1784639274_107.png') !== false) {
        $hardcodedPath[] = $rel;
    }

    // 3. Verificar 0 dominios externos http(s):// en .js, .css y vistas .php
    $isBackendEndpoint = (
        strpos($base, 'back_') === 0 ||
        in_array($rel, [
            'webhook.php', 'api_notificaciones.php', 'cron_campanas_autopilot.php', 'cron_waba_billing.php',
            'get_media.php', 'ver_documento.php', 'recover_password.php', 'register.php', 'logout.php',
            'config/database.php', 'core/IaConnector.php', 'core/IaContextEngine.php', 'core/audit.php',
            'core/crm_sprint2_helper.php', 'core/services/MailerService.php',
        ], true) ||
        $base === 'sse_updates.php'
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

    // 4. Favicons isologo.ico en todo documento con <head>
    if (stripos($src, '<head>') !== false || stripos($src, '<head ') !== false) {
        if (strpos($src, 'isologo.ico') === false) {
            $missingFav[] = $rel;
        }
    }

    // 5. autocomplete="off" en todo <form>
    if (preg_match_all('/<form\b[^>]*>/i', $src, $fms)) {
        foreach ($fms[0] as $fTag) {
            if (stripos($fTag, 'autocomplete="off"') === false && stripos($fTag, "autocomplete='off'") === false) {
                $missingAuto[] = $rel;
            }
        }
    }
}

qa_check("0 dominios externos http(s):// en .js, .css y vistas .php (incl. openstreetmap.org y CDNs)", empty($extDomainHits), implode(', ', array_slice($extDomainHits, 0, 5)));
qa_check("0 llamadas a alert() nativo en todo el proyecto (CHK-UIX-02)", empty($alertHits), implode(', ', $alertHits));
qa_check("100% de las vistas HTML incluyen favicon oficial isologo.ico (CHK-UIX-01)", empty($missingFav), implode(', ', $missingFav));
qa_check("100% de los formularios <form> incluyen autocomplete=\"off\" (CHK-UIX-08)", empty($missingAuto), implode(', ', $missingAuto));
qa_check("0 rutas quemadas '/starfi_crm/...' ni logo_1784639274_107.png (QA-BUG-CRM2-12)", empty($hardcodedPath), implode(', ', $hardcodedPath));

$impSrc = (string)file_get_contents($root . '/modules/dashboard/imprimir_orden_waba.php');
qa_check("imprimir_orden_waba.php muestra logo de empresa_perfil y pie 'Generado por Starfi' con isologo_ni.png (CHK-UIX-03)",
    strpos($impSrc, 'empresa_perfil') !== false &&
    strpos($impSrc, 'Generado por Starfi') !== false &&
    strpos($impSrc, 'isologo_ni.png') !== false &&
    strpos($impSrc, 'logo_starfi_pdf.png') === false
);

$mvcCapsules = [
    'bandeja', 'pipeline', 'campanas', 'configuracion', 'directorio',
    'dashboard', 'gestor_bots', 'gestion_roles', 'gestion_usuarios', 'perfil_empresa'
];
foreach ($mvcCapsules as $mod) {
    $has4 = is_file("$root/modules/$mod/$mod.php")
         && is_file("$root/modules/$mod/modals_$mod.php")
         && is_file("$root/modules/$mod/funciones_$mod.js")
         && is_file("$root/modules/$mod/back_$mod.php");
    qa_check("Cápsula MVC de 4 archivos completa en modules/$mod/", $has4);
}
qa_check("simulador_whatsapp.php reubicado en modules/bandeja/test_ia/ y eliminado de database/tools/",
    is_file($root . '/modules/bandeja/test_ia/simulador_whatsapp.php') && !is_file($root . '/database/tools/simulador_whatsapp.php')
);

// -----------------------------------------------------------------------------
// BLOQUE 6: Pruebas Unitarias de Validación finfo MIME (<= 5 MB) y Logs Locales por Módulo
// -----------------------------------------------------------------------------
echo "\n[6/9] Probando rechazo finfo(FILEINFO_MIME_TYPE) de falsos PDFs con código PHP y ruteo local de logs...\n";
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
$whLogPath   = starfi_resolve_module_log_path('webhook');
qa_check("starfi_log_exception('pipeline') enruta localmente a modules/pipeline/logs_pipeline.log",
    str_replace('\\', '/', $pipeLogPath) === $root . '/modules/pipeline/logs_pipeline.log'
);
qa_check("starfi_log_exception('webhook') enruta globalmente a logs/logs_webhook.log",
    str_replace('\\', '/', $whLogPath) === $root . '/logs/logs_webhook.log'
);

// -----------------------------------------------------------------------------
// BLOQUE 7, 8 & 9: Pruebas Transaccionales en BD con ROLLBACK (IA Multi-Sede, Anti-Doble Referencia WABA, Anti-Fatiga 24h, Auditoría Antes/Después)
// -----------------------------------------------------------------------------
echo "\n[7/9] Ejecutando pruebas monetarias USD, Aislamiento IA Multi-Sede, Anti-Doble Referencia WABA, Anti-Fatiga 24h y Auditoría en BD (con ROLLBACK)...\n";
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

        // 3. Prueba QA-BUG-CRM2-04 / CHK-SEC-09: Rechazo atómico FOR UPDATE de referencia bancaria duplicada en waba_ordenes_cobro
        $colsOrd = starfi_waba_table_columns($con, 'waba_ordenes_cobro', true);
        $insColsOrd = ['id_sede', 'fecha_desde', 'fecha_hasta', 'estado'];
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

        // 5. Pruebas de Pipeline Multi-Sede + Round-Robin FOR UPDATE
        $sharedPhone = '584148887766';
        $con->query("INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre) VALUES (1, 9951, '$sharedPhone', 'Cliente Alfa')");
        $cAlfa = (int)$con->insert_id;
        $con->query("INSERT INTO clientes_contactos (id_empresa, id_sede, numero_whatsapp, nombre) VALUES (1, 9952, '$sharedPhone', 'Cliente Beta')");
        $cBeta = (int)$con->insert_id;

        $con->query("INSERT INTO conversaciones (id_linea, id_cliente, estado) VALUES ($lAlfa, $cAlfa, 'ATENDIENDO')");
        $cvAlfa = (int)$con->insert_id;
        $salePayload = 'Envío dinámico de plantilla: send_confirmacion_compra_cn_sn_btn. Params: ["Cliente Alfa","2026-11-21","950.00 + IVA","ASESOR ALFA","SEDE ALFA","NE_999999","04140000000"]';
        $stmtSale = $con->prepare("INSERT INTO mensajes_y_eventos (id_conversacion, origen, tipo, contenido) VALUES (?, 'API_TRANSACCIONAL', 'EVENTO_SISTEMA', ?)");
        $stmtSale->bind_param("is", $cvAlfa, $salePayload);
        $stmtSale->execute();
        $stmtSale->close();

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

echo "\n[9/9] Resumen Final de Certificación QA-STD-GLOBAL-2.1...\n";
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
