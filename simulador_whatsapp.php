<?php
// simulador_whatsapp.php
// Este script simula un mensaje entrante de Meta (WhatsApp) hacia tu webhook.php

$url_webhook = 'http://localhost/starfi_crm/webhook.php'; // Cambia si tu ruta es diferente

$numero_cliente = '584120000002'; // El número "falso" del cliente que te escribe
$nombre_cliente = 'Cliente Prueba Simulator';
$mensaje_texto = 'Hola! Quiero probar la recepción de mensajes.';
$phone_number_id = '123456789'; // El Meta App ID de tu línea en la BD (ej. el de la Sede Principal)

// Payload idéntico al que envía Meta API
$payload = [
    'object' => 'whatsapp_business_account',
    'entry' => [
        [
            'id' => '123456',
            'changes' => [
                [
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => [
                            'display_phone_number' => '15551234567',
                            'phone_number_id' => $phone_number_id
                        ],
                        'contacts' => [
                            [
                                'profile' => [
                                    'name' => $nombre_cliente
                                ],
                                'wa_id' => $numero_cliente
                            ]
                        ],
                        'messages' => [
                            [
                                'from' => $numero_cliente,
                                'id' => 'wamid.' . uniqid(),
                                'timestamp' => time(),
                                'text' => [
                                    'body' => $mensaje_texto
                                ],
                                'type' => 'text'
                            ]
                        ]
                    ],
                    'field' => 'messages'
                ]
            ]
        ]
    ]
];

$json_payload = json_encode($payload);

$ch = curl_init($url_webhook);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Content-Length: ' . strlen($json_payload)
]);

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "<h3>Simulación de Mensaje Entrante</h3>";
echo "Se envió un mensaje de prueba al webhook.<br>";
echo "<strong>Código HTTP:</strong> " . $httpcode . "<br>";
echo "<strong>Respuesta:</strong> " . htmlspecialchars($response) . "<br><br>";
echo "¡Ve a tu <b>Bandeja Omnicanal</b>, revisa la pestaña <b>No Leído</b> o <b>Todos</b> y deberías ver el mensaje al instante!";
?>
