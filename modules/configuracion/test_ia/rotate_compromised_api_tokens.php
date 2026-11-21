<?php
/**
 * modules/configuracion/test_ia/rotate_compromised_api_tokens.php
 * Herramienta CLI de Seguridad (QA-BUG-CRM2-01 / CHK-SEC-08):
 * Detecta por comparación de digest SHA-256 (sin almacenar ningún token plano en código)
 * si alguna sede en `sedes` conserva alguno de los `api_token` expuestos históricamente
 * y los rota criptográficamente con bin2hex(random_bytes(32)), registrando el evento en auditoría.
 *
 * Uso:
 *   php modules/configuracion/test_ia/rotate_compromised_api_tokens.php           (ejecuta rotación)
 *   php modules/configuracion/test_ia/rotate_compromised_api_tokens.php --dry-run (solo audita sin mutar)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

@ini_set('display_errors', '0');
date_default_timezone_set('America/Caracas');

$root = realpath(__DIR__ . '/../../..');
require_once $root . '/config/database.php';
require_once $root . '/core/audit.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);

// Digests SHA-256 unidireccionales de los api_token comprometidos (cero secretos planos en el código)
$compromisedSha256 = [
    '544949a273d8adf181f0604cedc0f20b7dec6fb24c3e4f9bfdc1be8fe15c99d6',
    '327a50b1cd41c95c8f279af676bedac6e75ed5f17ca8603d710dd29a3fcb88fd',
    '187acbc47af176ff81d06c15c436b691c014cc3c98704ccefb09906d585a74ae',
    '7858f63a0d53e44ee8931f0dc192d5a8c368b1d820a90859ecc08e9d98b31948',
    'c7b900f90f899e2495660852ae14890ce4e53d390c1d35d2912254322941666c',
    '62fc591713afd3a6fc4714132fc2997461a0a9d98470dca3c029f35f8bebacf7',
    '9712feb5bcf42459807700272764b19e1338e918136cdbab3dcc152788ce7b9d',
    'd872049e885a9579a603f5dac286331c3003a9088afcfb11e6ab34982bdc16e3',
    '81d98e6f18fce9c0ac32195b36995cd6f5fd75c562c25da23e7eca721cccfd40',
    '16679a8c7176af56fe25caea72485760283a2aaa852a7e5dcd83f148848d78be',
    '9d3ad916ee147720d2454b80086267d552c6c6ebab2200104791c2884741c976',
    'c3561f8641575e46981311f2217b5a84405f516f7527823225c1496da24c468e',
    '853a6d24092aa837c07f9f1d505d5b43b66d9da6dacd66f4216e88531b087c3a',
    '4612a426d708aee370440df3666785ea5d8b91fff871431d1335246e383d7098',
    'd938688290462ac3dae30d499aa3d355252d33358266159684d6ba3f719f15dd',
    '5676dddc250fd829234ff4083beb4dd34c77ff7948818c8f8ecb68170e10f0a9',
    'd3793452dfd8d43ba61a5238103f4f59340317b7b2c83e2df2a23aa795462379',
    '8cd5eb089d2fbb15576d15e2ba97c0539b27328b620bcb0150a00c979c34516c',
    '67de68e15c32b0890d17aa1fa4ddd2e253ac47cf0453aaeb5600f8c7dfc6c923',
    '2517d3933a1a54005f0f981191637850d01a56bd19bafad22b50810eade3cb9e',
    'd7fd31833138383504b23bc8a4204a26b63ead22f2cabc28261628c957eba3f5',
    '7bcca46372b4c06af5c122a6d21ff9b57e377cdd1279ecd072e427158dcbd0e0',
    '3e1599285806fd4566bb417c202fe90eabb0c399fa40136ac43f0605b5c67221',
    'b3f54b99515b08bf8ef2b18c47da49843b54116759103c55ebf67013ec2c7284',
];
$compromisedLookup = array_fill_keys($compromisedSha256, true);

$con = getDbConnection('core');
if (!$con) {
    fwrite(STDERR, "[ERROR] No se pudo conectar a la base de datos core.\n");
    exit(1);
}

$stmtSel = $con->prepare("SELECT id, nombre_sede, api_token FROM sedes WHERE api_token IS NOT NULL AND api_token != ''");
if (!$stmtSel || !$stmtSel->execute()) {
    starfi_log_exception('configuracion', 'Fallo al consultar sedes para rotación de api_token');
    fwrite(STDERR, "[ERROR] No se pudo consultar la tabla sedes.\n");
    exit(1);
}

$res = $stmtSel->get_result();
$targets = [];
while ($row = $res->fetch_assoc()) {
    $tok = strtolower(trim((string)$row['api_token']));
    if ($tok === '') {
        continue;
    }
    $digest = hash('sha256', $tok);
    if (isset($compromisedLookup[$digest])) {
        $targets[] = [
            'id' => (int)$row['id'],
            'nombre_sede' => (string)$row['nombre_sede'],
            'old_sha256' => $digest,
        ];
    }
}
$stmtSel->close();

if (empty($targets)) {
    echo "[OK] 0 sedes con api_token comprometido detectadas.\n";
    $con->close();
    exit(0);
}

echo sprintf("[ALERTA] Se detectaron %d sede(s) con api_token comprometido.%s\n", count($targets), $dryRun ? ' (MODO DRY-RUN)' : '');

$rotated = 0;
$stmtUpd = $con->prepare("UPDATE sedes SET api_token = ? WHERE id = ?");
if (!$stmtUpd) {
    starfi_log_exception('configuracion', 'No se pudo preparar UPDATE para rotación de api_token');
    $con->close();
    exit(1);
}

foreach ($targets as $item) {
    $sedeId = $item['id'];
    $sedeNombre = $item['nombre_sede'];
    if ($dryRun) {
        echo sprintf("  - [PENDIENTE ROTAR] Sede #%d (%s) sha256_anterior=%s\n", $sedeId, $sedeNombre, substr($item['old_sha256'], 0, 16) . '...');
        continue;
    }

    $newToken = bin2hex(random_bytes(32));
    $newSha256 = hash('sha256', $newToken);
    $stmtUpd->bind_param("si", $newToken, $sedeId);
    if ($stmtUpd->execute()) {
        $rotated++;
        registrar_auditoria(
            'configuracion',
            'ROTACION_API_TOKEN_COMPROMETIDO',
            "Rotación de emergencia de api_token comprometido en sede #{$sedeId} ({$sedeNombre})",
            ['id_sede' => $sedeId, 'nombre_sede' => $sedeNombre, 'api_token_sha256' => $item['old_sha256']],
            ['id_sede' => $sedeId, 'nombre_sede' => $sedeNombre, 'api_token_sha256' => $newSha256],
            $con,
            1,
            $sedeId
        );
        echo sprintf("  - [ROTADO] Sede #%d (%s) -> nuevo api_token generado (sha256=%s...)\n", $sedeId, $sedeNombre, substr($newSha256, 0, 16));
    } else {
        starfi_log_exception('configuracion', 'Error al rotar api_token en sede #' . $sedeId);
    }
}

$stmtUpd->close();
$con->close();

if (!$dryRun) {
    echo sprintf("[COMPLETADO] Sedes rotadas exitosamente: %d/%d.\n", $rotated, count($targets));
}
exit(0);
