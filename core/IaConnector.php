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
    public static function construirPayload(array $configIa, array $historialMensajes, string $mensajeActual, string $contextoJIT, string $nombreCliente = ''): array {
        $nombreAgente = !empty($configIa['agente_nombre']) ? $configIa['agente_nombre'] : 'Gema';
        $instruccionesBase = !empty($configIa['agente_instrucciones']) 
            ? $configIa['agente_instrucciones'] 
            : "Eres $nombreAgente, el asistente virtual atento e inteligente de STARFI CRM. Responde de forma amable, precisa y profesional.";

        if (empty($nombreCliente) && !empty($configIa['nombre_cliente'])) {
            $nombreCliente = $configIa['nombre_cliente'];
        }

        $systemPrompt = "Tu nombre es " . $nombreAgente . ".\n" . $instruccionesBase;
        $systemPrompt .= "\n\nREGLAS DE IDENTIFICACIÓN Y ATENCIÓN PROFESIONAL (WHATSAPP):\n";

        if (!empty($nombreCliente) && strtolower($nombreCliente) !== 'usuario' && strtolower($nombreCliente) !== 'cliente') {
            $systemPrompt .= "1. IDENTIFICACIÓN DEL CLIENTE: Te estás comunicando con *" . $nombreCliente . "*. Dirígete a él/ella llamándole por su nombre de forma cercana, atenta y profesional.\n";
        } else {
            $systemPrompt .= "1. PRIMERO PREGUNTAR EL NOMBRE (REGLA OBLIGATORIA): Aún no tenemos el nombre de este cliente en el sistema. En tu PRIMERA respuesta, tu prioridad es dar la bienvenida, presentarte y PREGUNTAR PRIMERO SU NOMBRE de forma amable y directa (ejemplo: '¡Hola! Bienvenid@ a [Sede], soy " . $nombreAgente . ". Para poder atenderte mejor y registrar tu consulta, ¿cuál es tu nombre, por favor?'). Si el cliente ya hizo una pregunta sobre algún producto, dile con amabilidad que con todo gusto le darás todos los precios e inventario en cuanto te indique cómo se llama.\n";
            $systemPrompt .= "2. CAPTURA AUTOMÁTICA DE NOMBRE: En cuanto el cliente te indique cómo se llama (ejemplo: 'Me llamo Juan Pérez', 'Soy María', 'Carlos'), extrae únicamente su Nombre y Apellido e incluye al FINAL de tu respuesta la etiqueta EXACTA '[GUARDAR_NOMBRE: Nombre Y Apellido]' (ejemplo: '[GUARDAR_NOMBRE: Carlos Pérez]').\n";
        }

        $systemPrompt .= "3. FORMATO WHATSAPP: Usa negritas (*ejemplo*) para resaltar nombres de productos, precios y datos clave. Usa viñetas limpias (•) para listar artículos.\n";
        $systemPrompt .= "4. TONO Y ESTILO: Mantén un tono comercial cálido, servicial, profesional y entusiasta. Usa emojis sutiles y adecuados (ej. ✨, 📦, 💡, 📍, 🤝) para hacer la lectura agradable.\n";
        $systemPrompt .= "5. RESPUESTAS CONCISAS: Escribe párrafos cortos y directos, ideales para leer rápidamente en la pantalla de un teléfono celular.\n";
        $systemPrompt .= "6. DATOS DE INVENTARIO: Si se incluye información de inventario en tiempo real y el cliente ya indicó su nombre, muestra el producto en *negrita*, el *precio* en USD ($) y confirma si hay stock disponible. No inventes precios ni existencias que no figuren en el contexto proporcionado.\n";
        $systemPrompt .= "7. TRANSFERENCIA A ASESOR HUMANO: Si el cliente solicita explícitamente ser atendido por un asesor humano o una persona, responde con amabilidad confirmándole la transferencia e incluye EXACTAMENTE la etiqueta '[SOLICITAR_AGENTE_HUMANO]' al final de tu mensaje.\n";
        $systemPrompt .= "8. LLAMADA A LA ACCIÓN: Finaliza ofreciendo ayuda adicional o invitando a concretar la consulta de forma servicial.";

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
                'maxOutputTokens' => 1000,
                'topP' => 0.95
            ]
        ];
    }

    /**
     * Ejecuta la llamada cURL y retorna los detalles completos (texto o error con código HTTP).
     */
    /**
     * Ejecuta la llamada cURL y retorna los detalles completos (texto o error con código HTTP).
     */
    public static function generarRespuestaConDetalles(array $configIa, array $historialMensajes, string $mensajeActual, string $contextoJIT, string $nombreCliente = ''): array {
        $apiKey = trim($configIa['gemini_api_key'] ?? '');
        if (empty($apiKey)) {
            if (defined('GEMINI_GLOBAL_API_KEY')) {
                $apiKey = GEMINI_GLOBAL_API_KEY;
            } else {
                return ['success' => false, 'error' => 'API Key de Gemini no proporcionada ni configurada.'];
            }
        }

        $modelo = !empty($configIa['modelo_ia']) ? $configIa['modelo_ia'] : 'gemini-3.6-flash';
        $payload = self::construirPayload($configIa, $historialMensajes, $mensajeActual, $contextoJIT, $nombreCliente);

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
                return self::generarRespuestaConDetalles($configFallback, $historialMensajes, $mensajeActual, $contextoJIT, $nombreCliente);
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
            $resFallback = self::generarRespuestaConDetalles($configFallback, $historialMensajes, $mensajeActual, $contextoJIT, $nombreCliente);
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
    public static function generarRespuesta(array $configIa, array $historialMensajes, string $mensajeActual, string $contextoJIT, string $nombreCliente = ''): ?string {
        $res = self::generarRespuestaConDetalles($configIa, $historialMensajes, $mensajeActual, $contextoJIT, $nombreCliente);
        return $res['success'] ? $res['text'] : null;
    }
}
