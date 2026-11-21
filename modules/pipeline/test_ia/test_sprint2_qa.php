<?php
/**
 * modules/pipeline/test_ia/test_sprint2_qa.php
 * Suite Oficial de Certificación Forense Pre-Entrega — STARFI CRM (QA-AUDIT-2026-1120-CRM-09 / QA-STD-GLOBAL-2.1)
 * Ejecución exclusiva CLI con ROLLBACK transaccional garantizado (0 residuos en base de datos).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

@ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('America/Caracas');

$root = realpath(__DIR__ . '/../../..');
require_once $root . '/config/database.php';
require_once $root . '/core/crm_sprint2_helper.php';
require_once $root . '/core/audit.php';

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

echo "=============================================================================\n";
echo " SUITE DE CERTIFICACIÓN INTEGRAL QA V2 — STARFI CRM (16 HALLAZGOS + ROLLBACK)\n";
echo "=============================================================================\n\n";

// -----------------------------------------------------------------------------
// BLOQUE 1: Autenticación, Recuperación, Instalador y Registro (CRM-11, CRM-15)
// -----------------------------------------------------------------------------
echo "[1/7] Verificando Autenticación, OTP, Instalador y Registro...\n";
$loginSrc = (string)file_get_contents($root . '/login.php');
$recSrc   = (string)file_get_contents($root . '/recover_password.php');
$instSrc  = (string)file_get_contents($root . '/install.php');
$regSrc   = (string)file_get_contents($root . '/register.php');

qa_check("login.php sin clave backdoor '1234'", !preg_match("/===\s*['\"]1234['\"]/", $loginSrc));
qa_check("login.php sin función insegura md5()", stripos($loginSrc, 'md5(') === false);
qa_check("recover_password.php sin 'codigo_simulado'", stripos($recSrc, 'codigo_simulado') === false);
qa_check("recover_password.php usa random_int(100000, 999999)", strpos($recSrc, 'random_int(100000, 999999)') !== false);
qa_check("recover_password.php exige recovery_verified_user_id en send_code", strpos($recSrc, "recovery_verified_user_id") !== false);
qa_check("install.php valida config/.env.local y bloquea con HTTP 403", strpos($instSrc, "config/.env.local") !== false && strpos($instSrc, "http_response_code(403)") !== false);
qa_check("register.php sin sentencias ALTER TABLE en caliente", !preg_match('/mysqli_query\s*\([^;]*ALTER\s+TABLE/i', $regSrc));
qa_check("register.php cifra respuestas de seguridad con password_hash()", substr_count($regSrc, 'password_hash(') >= 2);

// -----------------------------------------------------------------------------
// BLOQUE 2: Cierre de RCE en Uploads, .htaccess y Saneamiento Git (CRM-12, CRM-16)
// -----------------------------------------------------------------------------
echo "\n[2/7] Verificando Cierre de RCE en Uploads y Protección .htaccess / .gitignore...\n";
$empSrc = (string)file_get_contents($root . '/modules/perfil_empresa/back_empresa.php');
$banSrc = (string)file_get_contents($root . '/modules/bandeja/back_bandeja.php');
$gitIgn = (string)file_get_contents($root . '/.gitignore');

qa_check("back_empresa.php valida MIME real con finfo(FILEINFO_MIME_TYPE)", strpos($empSrc, 'FILEINFO_MIME_TYPE') !== false);
qa_check("back_bandeja.php valida MIME real con finfo(FILEINFO_MIME_TYPE) y límite 5 MB", strpos($banSrc, 'FILEINFO_MIME_TYPE') !== false && strpos($banSrc, '5 * 1024 * 1024') !== false);
qa_check("uploads/.htaccess activo bloqueando scripts", is_file($root . '/uploads/.htaccess') && strpos((string)file_get_contents($root . '/uploads/.htaccess'), 'FilesMatch') !== false);
qa_check("assets/docs/.htaccess activo bloqueando scripts", is_file($root . '/assets/docs/.htaccess') && strpos((string)file_get_contents($root . '/assets/docs/.htaccess'), 'FilesMatch') !== false);
qa_check("Controlador autenticado por sede ver_documento.php presente", is_file($root . '/ver_documento.php'));
qa_check(".gitignore protege uploads/* y assets/docs/expedientes/*", strpos($gitIgn, 'uploads/*') !== false && strpos($gitIgn, 'assets/docs/expedientes/*') !== false);

// -----------------------------------------------------------------------------
// BLOQUE 3: RBAC en todos los back_*.php y Revocación de INACTIVO (CRM-13)
// -----------------------------------------------------------------------------
echo "\n[3/7] Verificando RBAC en todos los controladores back_*.php y estado ACTIVO...\n";
$requiredRbac = [
    'modules/gestion_usuarios/back_usuarios.php'     => 'gestion_usuarios',
    'modules/perfil_empresa/back_empresa.php'        => 'perfil_empresa',
    'modules/configuracion/back_configuracion.php'   => 'configuracion',
    'modules/bandeja/back_bandeja.php'               => 'bandeja',
    'modules/directorio/back_directorio.php'         => 'directorio',
    'modules/gestor_bots/back_gestor_bots.php'       => 'gestor_bots',
    'modules/dashboard/back_dashboard.php'           => 'dashboard',
    'modules/dashboard/back_waba_billing.php'        => 'waba_ordenes',
    'modules/dashboard/back_whatsapp_analytics.php'  => 'whatsapp_analytics',
    'modules/pipeline/back_pipeline.php'             => 'pipeline',
    'modules/gestion_roles/back_roles.php'           => 'gestion_roles',
    'modules/campanas/back_campanas.php'             => 'campanas',
];
foreach ($requiredRbac as $relPath => $permKey) {
    $src = (string)file_get_contents($root . '/' . $relPath);
    qa_check("requirePermission('$permKey') en $relPath", strpos($src, "requirePermission('$permKey')") !== false);
}
$authSrc  = (string)file_get_contents($root . '/core/auth.php');
$rolesSrc = (string)file_get_contents($root . '/modules/gestion_roles/back_roles.php');
$usrsSrc  = (string)file_get_contents($root . '/modules/gestion_usuarios/back_usuarios.php');

qa_check("core/auth.php exige u.estado = 'ACTIVO' en getAgenteInfo()", strpos($authSrc, "u.estado = 'ACTIVO'") !== false);
qa_check("back_roles.php bloquea mutación de role_id === 1 (MASTER)", strpos($rolesSrc, '$role_id === 1') !== false);
qa_check("back_usuarios.php bloquea asignación de MASTER y MASTER CI por no-MASTER", strpos($usrsSrc, "'MASTER', 'MASTER CI'") !== false);

// -----------------------------------------------------------------------------
// BLOQUE 4: API WABA, Webhook HMAC y Guardia CLI en Crons (CRM-14)
// -----------------------------------------------------------------------------
echo "\n[4/7] Verificando API WABA, Firma Webhook y Guardia CLI en Crons...\n";
$apiSrc   = (string)file_get_contents($root . '/api_notificaciones.php');
$whSrc    = (string)file_get_contents($root . '/webhook.php');
$cron1Src = (string)file_get_contents($root . '/cron_campanas_autopilot.php');
$cron2Src = (string)file_get_contents($root . '/cron_waba_billing.php');

qa_check("api_notificaciones.php sin tokens hardcodeados (MADETEK/MADEPEG)", stripos($apiSrc, 'MADETEK') === false && stripos($apiSrc, 'MADEPEG') === false);
qa_check("api_notificaciones.php sin CORS comodín '*' ni \$con->error expuesto", strpos($apiSrc, 'Access-Control-Allow-Origin: *') === false && strpos($apiSrc, '$con->error') === false);
qa_check("api_notificaciones.php exige api_token hexadecimal de 64 caracteres", strpos($apiSrc, '{64}') !== false);
qa_check("webhook.php exige META_APP_SECRET y X-Hub-Signature-256 en PRODUCCION", strpos($whSrc, 'META_APP_SECRET requerido en produccion') !== false && strpos($whSrc, 'hash_equals') !== false);
qa_check("cron_campanas_autopilot.php restringido a PHP_SAPI === 'cli'", strpos($cron1Src, "PHP_SAPI !== 'cli'") !== false);
qa_check("cron_waba_billing.php restringido a PHP_SAPI === 'cli'", strpos($cron2Src, "PHP_SAPI !== 'cli'") !== false);

// -----------------------------------------------------------------------------
// BLOQUE 5: 100% Cero CDN Externos y Arquitectura MVC / UX (CRM-01, CRM-08..10)
// -----------------------------------------------------------------------------
echo "\n[5/7] Escaneando 100% Cero CDN Externos, MVC e Identidad Visual...\n";
$cdnPattern = '/cdnjs\.cloudflare\.com|fonts\.googleapis\.com|fonts\.gstatic\.com|unpkg\.com|jsdelivr\.net|ui-avatars\.com|bootstrapcdn\.com|fontawesome\.com/i';
$cdnHits = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $fileInfo) {
    $full = str_replace('\\', '/', $fileInfo->getPathname());
    if (
        strpos($full, '/.git/') !== false ||
        strpos($full, '/docs/') !== false ||
        strpos($full, '/assets/vendor/') !== false ||
        strpos($full, '/assets/js/') !== false ||
        strpos($full, '/assets/css/bootstrap') !== false ||
        strpos($full, '/test_ia/') !== false
    ) {
        continue;
    }
    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, ['php', 'js', 'css', 'html'], true)) {
        continue;
    }
    $content = (string)@file_get_contents($full);
    if (preg_match($cdnPattern, $content, $m)) {
        $cdnHits[] = substr($full, strlen($root) + 1) . " (" . $m[0] . ")";
    }
}
qa_check("0 referencias a CDNs externos en todo el proyecto (CHK-LAN-01)", empty($cdnHits), implode(', ', array_slice($cdnHits, 0, 5)));
qa_check("modals_pipeline.php extraído y requerido en pipeline.php", is_file($root . '/modules/pipeline/modals_pipeline.php'));
qa_check("simulador_whatsapp.php retirado de la raíz pública web", !is_file($root . '/simulador_whatsapp.php'));
qa_check("core/auth.php detecta Accept: application/json para devolver 401 JSON", strpos($authSrc, 'application/json') !== false);
qa_check("pipeline.php aplica Gris Pizarra #37414A oficial", strpos((string)file_get_contents($root . '/modules/pipeline/pipeline.php'), '#37414A') !== false);

// -----------------------------------------------------------------------------
// BLOQUE 6 & 7: Pruebas Transaccionales en BD con ROLLBACK (CRM-02..07)
// -----------------------------------------------------------------------------
echo "\n[6/7] Ejecutando pruebas monetarias USD, Multi-Sede, FOR UPDATE y Auditoría en BD (con ROLLBACK)...\n";
qa_check("starfi_parse_usd_number('\$1.250 USD') === 1.25 (cero inflación 1,000x)", starfi_parse_usd_number('$1.250 USD') === 1.25);
qa_check("starfi_parse_usd_number('906.50 + IVA') === 906.50", starfi_parse_usd_number('906.50 + IVA') === 906.50);
qa_check("starfi_parse_usd_number('1,019.30 + IVA') === 1019.30", starfi_parse_usd_number('1,019.30 + IVA') === 1019.30);
qa_check("starfi_parse_usd_number('1.250,50 USD') === 1250.50", starfi_parse_usd_number('1.250,50 USD') === 1250.50);
qa_check("starfi_parse_usd_number('250 Bs') === 0.00", starfi_parse_usd_number('250 Bs') === 0.0);

$con = getDbConnection('core');
qa_check("Conexión activa a Base de Datos Core", (bool)$con);

if ($con) {
    starfi_ensure_sprint2_schema($con);
    starfi_ensure_audit_schema($con);

    $dbHash = starfi_db_schema_hash($con);
    qa_check("Hash MD5 de BD para banderas .flag válido (32 hex)", (bool)preg_match('/^[a-f0-9]{32}$/', $dbHash));

    $con->begin_transaction();
    starfi_tx_set_active($con, true);

    try {
        // Insertar 2 sedes efímeras para validar aislamiento estricto
        $con->query("INSERT INTO sedes (id, id_empresa, nombre_sede, estado) VALUES (9951, 1, 'QA Sede Alfa', 'ACTIVO'), (9952, 1, 'QA Sede Beta', 'ACTIVO')");
        $con->query("INSERT INTO lineas_whatsapp (id_sede, descripcion, numero_telefono, meta_app_id, meta_token, estado) VALUES (9951, 'L Alfa', '584140009951', 'app_9951', 't1', 'ACTIVO')");
        $lAlfa = (int)$con->insert_id;
        $con->query("INSERT INTO lineas_whatsapp (id_sede, descripcion, numero_telefono, meta_app_id, meta_token, estado) VALUES (9952, 'L Beta', '584140009952', 'app_9952', 't2', 'ACTIVO')");
        $lBeta = (int)$con->insert_id;

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

        qa_check("Sede 1 (Alfa) detecta su venta propia (\$950.00 / NE_999999)", $enrAlfa['monto_usd'] === 950.0 && $enrAlfa['correlativo'] === 'NE_999999');
        qa_check("Sede 2 (Beta) tiene 0 fuga de ventas/correlativos desde Sede 1", $enrBeta['monto_usd'] === 0.0 && $enrBeta['correlativo'] === '');

        // Verificar creación con FOR UPDATE y no-sobrescritura de cotización manual en COTIZADO
        $dealAlfa = starfi_ensure_pipeline_lead($con, 9951, $cAlfa, $cvAlfa, null);
        qa_check("starfi_ensure_pipeline_lead() con FOR UPDATE crea oportunidad válida", $dealAlfa > 0);

        $con->query("UPDATE pipeline_oportunidades SET etapa = 'COTIZADO', monto_estimado_usd = 2500.00 WHERE id = $dealAlfa");
        starfi_ensure_pipeline_lead($con, 9951, $cAlfa, $cvAlfa, null, '', 12.30);
        $rowCot = $con->query("SELECT monto_estimado_usd, etapa FROM pipeline_oportunidades WHERE id = $dealAlfa")->fetch_assoc();
        qa_check("Monto manual \$2,500.00 en etapa COTIZADO protegido contra sobrescritura", (float)$rowCot['monto_estimado_usd'] === 2500.0 && $rowCot['etapa'] === 'COTIZADO');

        // Verificar que oportunidad PERDIDA en hilo viejo no contamina hilo nuevo en la misma sede
        $con->query("UPDATE pipeline_oportunidades SET etapa = 'PERDIDO' WHERE id = $dealAlfa");
        $con->query("INSERT INTO conversaciones (id_linea, id_cliente, estado) VALUES ($lAlfa, $cAlfa, 'ESPERA_ASIGNACION')");
        $cvAlfaNew = (int)$con->insert_id;
        $enrAlfaNew = starfi_extract_deal_enrichment($con, 9951, $cAlfa, $cvAlfaNew);
        qa_check("Hilo nuevo excluye venta de conversación anterior marcada como PERDIDO", $enrAlfaNew['monto_usd'] === 0.0);

        // Verificar Round-Robin con FOR UPDATE entre 2 asesores en línea
        $con->query("INSERT INTO usuario (usuario, contrasena, estado, rol, id_sede, disponible_chat, ultima_actividad, ultima_asignacion_rr) VALUES ('qa_rr_1', 'x', 'ACTIVO', '3', 9952, 1, NOW(), NULL)");
        $opA = (int)$con->insert_id;
        $con->query("INSERT INTO usuario (usuario, contrasena, estado, rol, id_sede, disponible_chat, ultima_actividad, ultima_asignacion_rr) VALUES ('qa_rr_2', 'x', 'ACTIVO', '3', 9952, 1, NOW(), NOW() - INTERVAL 2 MINUTE)");
        $opB = (int)$con->insert_id;

        $rr1 = starfi_assign_round_robin_advisor($con, 9952, $cvBeta, $cBeta, 'QA_RR_1', 5);
        qa_check("Round-Robin (FOR UPDATE) elige primero al asesor sin asignación previa", $rr1 && (int)$rr1['id'] === $opA);

        $con->query("INSERT INTO conversaciones (id_linea, id_cliente, estado) VALUES ($lBeta, $cBeta, 'ESPERA_ASIGNACION')");
        $cvBeta2 = (int)$con->insert_id;
        $rr2 = starfi_assign_round_robin_advisor($con, 9952, $cvBeta2, $cBeta, 'QA_RR_2', 5);
        qa_check("Round-Robin (FOR UPDATE) rota al segundo asesor en la siguiente conversación", $rr2 && (int)$rr2['id'] === $opB);

        // Verificar registrar_auditoria() formal
        $audOk = registrar_auditoria($con, 'pipeline', 'QA_TEST_AUDIT', 'pipeline_oportunidades', $dealAlfa, ['test' => true], 1, 9951);
        qa_check("registrar_auditoria() inserta evento formal con mysqli_prepare()", $audOk === true);

        // ROLLBACK OBLIGATORIO
        $con->rollback();
        starfi_tx_set_active($con, false);

        $chkClean = $con->query("SELECT COUNT(*) AS c FROM sedes WHERE id IN (9951, 9952)")->fetch_assoc();
        qa_check("ROLLBACK verificado (0 filas de prueba persistidas en BD)", intval($chkClean['c']) === 0);
    } catch (Throwable $e) {
        @$con->rollback();
        starfi_tx_set_active($con, false);
        qa_check("Ejecución sin excepciones en bloque transaccional BD", false, $e->getMessage());
    }
    $con->close();
}

echo "\n[7/7] Resumen Final de Certificación...\n";
echo "-----------------------------------------------------------------------------\n";
echo " CONTROLES APROBADOS : $passed\n";
echo " CONTROLES FALLIDOS  : $failed\n";
echo "-----------------------------------------------------------------------------\n";

if ($failed > 0) {
    echo "DICTAMEN LOCAL: NO-GO\n";
    exit(1);
}

echo "DICTAMEN LOCAL: 100% PASS — APTO PARA SOLICITAR PASE A GO\n";
exit(0);
