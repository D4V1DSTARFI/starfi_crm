<?php
/**
 * database/tools/simulador_whatsapp.php
 * Herramienta CLI de simulación local de recepción de mensajes Meta (fuera de raíz web pública).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../config/database.php';

$secret = defined('META_APP_SECRET') ? META_APP_SECRET : '';
$url_webhook = 'http://localhost/starfi_crm/webhook.php';

$payload = [
    'object' => 'whatsapp_business_account',
    'entry' => [[
        'id' => '123456',
        'changes' => [[
            'value' => [
                'messaging_product' => 'whatsapp',
                'metadata' => ['display_phone_number' => '15551234567', 'phone_number_id' => '123456789'],
                'contacts' => [['profile' => ['name' => 'Cliente Prueba CLI'], 'wa_id' => '584120000002']],
                'messages' => [[
                    'from' => '584120000002',
                    'id' => 'wamid.' . uniqid(),
                    'timestamp' => time(),
                    'text' => ['body' => 'Prueba CLI local'],
                    'type' => 'text'
                ]]
            ],
            'field' => 'messages'
        ]]
    ]]
];

$json_payload = json_encode($payload);
$headers = ['Content-Type: application/json'];
if ($secret !== '') {
    $headers[] = 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $json_payload, $secret);
}

$ch = curl_init($url_webhook);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$resp = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP $http: $resp\n";
