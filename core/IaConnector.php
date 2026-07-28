<?php
/**
 * Conector de IA y Gestión de Ventana Deslizante de Memoria
 * Asistente Virtual Multi-Sede - STARFI CRM
 */

class IaConnector {

    /**
     * Recupera los últimos N mensajes de la conversación y mapea a roles 'user' y 'model'.
     */
    public static function recuperarHistorialMensajes(mysqli $con, int $idConversacion, int $limite = 12): array {
        if ($idConversacion <= 0) return [];

        $sql = "SELECT origen, contenido, tipo 
                FROM mensajes_y_eventos 
                WHERE id_conversacion = $idConversacion AND tipo IN ('TEXTO', 'IMAGEN')
                ORDER BY id DESC 
                LIMIT $limite";

        $res = mysqli_query($con, $sql);
        if (!$res) return [];

        $filas = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $filas[] = $row;
        }

        // Revertir para mantener el orden cronológico
        $filas = array_reverse($filas);

        $historial = [];
        foreach ($filas as $msg) {
            $origenUpper = strtoupper($msg['origen']);
            $role = ($origenUpper === 'CLIENTE') ? 'user' : 'model';
            $texto = trim($msg['contenido'] ?? '');
            
            if (!empty($texto)) {
                $historial[] = [
                    'role' => $role,
                    'parts' => [['text' => $texto]]
                ];
            }
        }

        return $historial;
    }

    /**
     * Construye el payload JSON estándar compatible con Google Gemini / Gemma REST API.
     */
    public static function construirPayload(array $configIa, array $historialMensajes, string $mensajeActual, string $contextoJIT): array {
        $nombreAgente = !empty($configIa['agente_nombre']) ? $configIa['agente_nombre'] : 'Gema';
        $instruccionesBase = !empty($configIa['agente_instrucciones']) 
            ? $configIa['agente_instrucciones'] 
            : "Eres $nombreAgente, el asistente virtual atento e inteligente de STARFI CRM. Responde de forma amable, precisa y profesional.";

        $systemPrompt = "Tu nombre es " . $nombreAgente . ".\n" . $instruccionesBase;
        $systemPrompt .= "\n\nREGLAS OBLIGATORIAS:\n";
        $systemPrompt .= "1. Si el cliente solicita explícitamente ser atendido por un asesor humano o persona, responde amablemente indicándole que lo vas a transferir e incluye exactamente la etiqueta '[SOLICITAR_AGENTE_HUMANO]' al final de tu respuesta.\n";
        $systemPrompt .= "2. Si recibes datos en tiempo real de inventario, úsalos para responder sobre precios y existencias. No inventes precios ni stock que no aparezcan en el contexto.\n";
        $systemPrompt .= "3. Mantén un tono servicial y profesional.";

        // Payload array
        $contents = $historialMensajes;

        // Inyectar el mensaje actual con el contexto fresco de la sede
        $mensajeFinal = $mensajeActual;
        if (!empty($contextoJIT)) {
            $mensajeFinal .= "\n\n" . $contextoJIT;
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $mensajeFinal]]
        ];

        $temperature = isset($configIa['temperatura']) ? (float)$configIa['temperatura'] : 0.4;

        return [
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]]
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => 500,
                'topP' => 0.95
            ]
        ];
    }

    /**
     * Ejecuta la llamada cURL y retorna los detalles completos (texto o error con código HTTP).
     */
    public static function generarRespuestaConDetalles(array $configIa, array $historialMensajes, string $mensajeActual, string $contextoJIT): array {
        $apiKey = trim($configIa['gemini_api_key'] ?? '');
        if (empty($apiKey)) {
            if (defined('GEMINI_GLOBAL_API_KEY')) {
                $apiKey = GEMINI_GLOBAL_API_KEY;
            } else {
                return ['success' => false, 'error' => 'API Key de Gemini no proporcionada ni configurada.'];
            }
        }

        $modelo = !empty($configIa['modelo_ia']) ? $configIa['modelo_ia'] : 'gemini-3.6-flash';
        $payload = self::construirPayload($configIa, $historialMensajes, $mensajeActual, $contextoJIT);

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent?key={$apiKey}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curlError)) {
            if ($modelo !== 'gemini-flash-latest') {
                $configFallback = $configIa;
                $configFallback['modelo_ia'] = 'gemini-flash-latest';
                return self::generarRespuestaConDetalles($configFallback, $historialMensajes, $mensajeActual, $contextoJIT);
            }
            return ['success' => false, 'error' => "Error de conexión cURL: " . $curlError, 'http_code' => 0];
        }

        $responseData = json_decode($response, true);

        if ($httpCode === 200 && isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true, 
                'text' => trim($responseData['candidates'][0]['content']['parts'][0]['text']),
                'http_code' => 200
            ];
        }

        // Fallback automático si el modelo específico no está disponible para esa API Key
        if ($httpCode !== 200 && $modelo !== 'gemini-flash-latest') {
            $configFallback = $configIa;
            $configFallback['modelo_ia'] = 'gemini-flash-latest';
            $resFallback = self::generarRespuestaConDetalles($configFallback, $historialMensajes, $mensajeActual, $contextoJIT);
            if ($resFallback['success']) {
                return $resFallback;
            }
        }

        $msgError = $responseData['error']['message'] ?? 'Respuesta no válida de la API de Google (HTTP ' . $httpCode . ')';
        return ['success' => false, 'error' => $msgError, 'http_code' => $httpCode, 'raw' => $responseData];
    }

    /**
     * Método abreviado que retorna solo el texto o null.
     */
    public static function generarRespuesta(array $configIa, array $historialMensajes, string $mensajeActual, string $contextoJIT): ?string {
        $res = self::generarRespuestaConDetalles($configIa, $historialMensajes, $mensajeActual, $contextoJIT);
        return $res['success'] ? $res['text'] : null;
    }
}
