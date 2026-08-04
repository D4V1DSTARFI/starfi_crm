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

        $nombreValido = false;
        if (!empty($nombreCliente)) {
            $nombreClean = strtolower(trim($nombreCliente));
            if ($nombreClean !== 'usuario' && $nombreClean !== 'cliente' && !preg_match('/^\+?[0-9\s\-]+$/', $nombreClean)) {
                $nombreValido = true;
            }
        }

        // Determinar saludo dinámico según la hora del día (Venezuela GMT-4)
        date_default_timezone_set("America/Caracas");
        $horaActual = intval(date('H'));
        if ($horaActual >= 5 && $horaActual < 12) {
            $saludoTemporal = "¡Buenos días!";
        } elseif ($horaActual >= 12 && $horaActual < 18) {
            $saludoTemporal = "¡Buenas tardes!";
        } else {
            $saludoTemporal = "¡Buenas noches!";
        }

        $direccionSede = !empty($configIa['direccion_sede']) ? trim($configIa['direccion_sede']) : '';
        $linkGps = !empty($configIa['link_gps']) ? trim($configIa['link_gps']) : '';

        $systemPrompt = "Tu nombre es " . $nombreAgente . ".\n" . $instruccionesBase;
        if (!empty($direccionSede)) {
            $systemPrompt .= "\n\nDIRECCIÓN FÍSICA DE ESTA SEDE: *" . $direccionSede . "*.";
        }
        if (!empty($linkGps)) {
            $systemPrompt .= "\nUBICACIÓN GPS DIRECTA (GOOGLE MAPS): *" . $linkGps . "*.";
        }
        if (!empty($direccionSede) || !empty($linkGps)) {
            $systemPrompt .= "\nREGLA DE UBICACIÓN: Si el cliente pregunta por la ubicación, dirección, dónde están o cómo llegar, envíale la dirección escrita e inclúyele obligatoriamente el enlace GPS de Google Maps para que pueda abrirlo en su teléfono con 1 clic.\n";
        }
        $systemPrompt .= "\n\nHORA ACTUAL DEL SERVIDOR: Usar preferentemente el saludo de tiempo actual: *" . $saludoTemporal . "*.\n";
        $systemPrompt .= "\nREGLAS OBLIGATORIAS DE FLUJO DE ATENCIÓN Y CAPTURA DE NOMBRE (WHATSAPP):\n";

        if ($nombreValido) {
            $systemPrompt .= "1. CLIENTE IDENTIFICADO Y REGISTRADO (*" . $nombreCliente . "*): El cliente ya existe en el sistema y su nombre es *" . $nombreCliente . "*.\n";
            $systemPrompt .= "   - Salúdalo cordialmente combinando el saludo según la hora del día (" . $saludoTemporal . ") y su nombre (ejemplo: '" . $saludoTemporal . " " . $nombreCliente . ", ¿en qué te puedo colaborar el día de hoy?' o '¡Hola " . $nombreCliente . ", " . strtolower($saludoTemporal) . "! ¿En qué te puedo ayudar hoy?').\n";
            $systemPrompt .= "   - Atiende sus dudas e inquietudes sobre servicios e información de la sede de forma directa, amable y personalizada.\n";
        } else {
            $systemPrompt .= "1. PRIMER MENSAJE DE CLIENTE NUEVO (SOLICITAR NOMBRE OBLIGATORIAMENTE): Aún NO conocemos el nombre de este cliente.\n";
            $systemPrompt .= "   - En tu PRIMER mensaje tu objetivo es únicamente: dar la bienvenida usando el saludo según la hora del día ('" . $saludoTemporal . " 🖐️'), presentarte (ej. 'Soy " . $nombreAgente . ", la asistente virtual de STARFI.') y PREGUNTAR SU NOMBRE (ej. 'Para poder atenderte mejor, ¿por favor me indicas tu nombre?').\n";
            $systemPrompt .= "   - ESTRICTAMENTE PROHIBIDO: NO preguntes '¿En qué te puedo ayudar el día de hoy?' ni intentes dar información en este primer mensaje. NUNCA pases a atenderlo sin antes pedir su nombre.\n";
            $systemPrompt .= "   - Si el cliente en su primer mensaje ya hizo una consulta, dile con amabilidad: '" . $saludoTemporal . " Con todo gusto te atenderé, pero primero ¿podrías indicarme tu nombre para registrarte en nuestro sistema, por favor?'.\n";
            $systemPrompt .= "2. SEGUNDO MENSAJE (CUANDO EL CLIENTE RESPONDE SU NOMBRE):\n";
            $systemPrompt .= "   - En cuanto el cliente te diga cómo se llama en su mensaje (ej. 'Me llamo Carlos', 'Soy María', 'Carlos Pérez', 'Carlos'):\n";
            $systemPrompt .= "     a) Extrae únicamente su Nombre y Apellido e incluye al FINAL de tu respuesta la etiqueta EXACTA '[GUARDAR_NOMBRE: Nombre Y Apellido]' (ejemplo: '[GUARDAR_NOMBRE: Carlos Pérez]').\n";
            $systemPrompt .= "     b) Salúdalo llamándolo por su nombre usando el saludo según la hora del día (ejemplo: '" . $saludoTemporal . " Carlos, mucho gusto en saludarte.').\n";
            $systemPrompt .= "     c) AHÍ SÍ PREGÚNTALE en qué le puedes colaborar el día de hoy y responde a las consultas que haya formulado.\n";
        }

        $systemPrompt .= "3. FORMATO WHATSAPP: Usa negritas (*ejemplo*) para resaltar datos clave. Usa viñetas limpias (•) para listar información.\n";
        $systemPrompt .= "4. TONO Y ESTILO: Mantén un tono comercial cálido, servicial, profesional y entusiasta. Usa emojis sutiles y adecuados (ej. ✨, 💡, 📍, 🤝) para hacer la lectura agradable.\n";
        $systemPrompt .= "5. RESPUESTAS CONCISAS: Escribe párrafos cortos y directos, ideales para leer rápidamente en la pantalla de un teléfono celular.\n";
        $systemPrompt .= "6. SIN INFORMACIÓN DE INVENTARIO O PRODUCTOS: La IA no proporciona existencias, catálogo ni precios de productos. Si el cliente consulta sobre productos, compras o cotizaciones, indícale amablemente que un asesor de ventas le atenderá personalmente y transfiérelo agregando EXACTAMENTE la etiqueta '[SOLICITAR_AGENTE_HUMANO]'.\n";
        $systemPrompt .= "7. TRANSFERENCIA A ASESOR HUMANO: Si el cliente solicita ser atendido por un asesor humano o persona, responde con amabilidad confirmándole la transferencia e incluye EXACTAMENTE la etiqueta '[SOLICITAR_AGENTE_HUMANO]' al final de tu mensaje.\n";
        $systemPrompt .= "8. LLAMADA A LA ACCIÓN: Finaliza ofreciendo ayuda adicional o invitando a concretar la consulta de forma servicial.";

        $systemPrompt = self::utf8Clean($systemPrompt);

        // Normalizar historial para garantizar alternancia estricta de roles (user / model) que exige la API de Gemini
        $contents = [];
        $lastRole = null;

        if (is_array($historialMensajes)) {
            foreach ($historialMensajes as $msg) {
                $role = ($msg['role'] === 'model') ? 'model' : 'user';
                $text = self::utf8Clean(trim($msg['parts'][0]['text'] ?? ''));
                if (empty($text)) continue;

                if ($role === $lastRole && !empty($contents)) {
                    $idx = count($contents) - 1;
                    $contents[$idx]['parts'][0]['text'] .= "\n" . $text;
                } else {
                    $contents[] = [
                        'role' => $role,
                        'parts' => [['text' => $text]]
                    ];
                    $lastRole = $role;
                }
            }
        }

        // Inyectar el mensaje actual con el contexto fresco de la sede
        $mensajeFinal = self::utf8Clean(trim($mensajeActual));
        if (!empty($contextoJIT)) {
            $mensajeFinal .= "\n\n" . self::utf8Clean($contextoJIT);
        }

        if (!empty($mensajeFinal)) {
            if ($lastRole === 'user' && !empty($contents)) {
                $idx = count($contents) - 1;
                $contents[$idx]['parts'][0]['text'] .= "\n" . $mensajeFinal;
            } else {
                $contents[] = [
                    'role' => 'user',
                    'parts' => [['text' => $mensajeFinal]]
                ];
            }
        }

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
     * Limpia y convierte cualquier string a UTF-8 válido.
     */
    public static function utf8Clean($data) {
        if (!is_string($data) || empty($data)) return $data;
        if (!mb_check_encoding($data, 'UTF-8')) {
            $data = mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
        }
        return $data;
    }

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
        $payload = self::construirPayload($configIa, $historialMensajes, $mensajeActual, $contextoJIT);

        $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return ['success' => false, 'error' => 'Error al codificar JSON payload: ' . json_last_error_msg()];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modelo}:generateContent?key={$apiKey}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
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
            if ($modelo !== 'gemini-2.0-flash') {
                $configFallback = $configIa;
                $configFallback['modelo_ia'] = 'gemini-2.0-flash';
                return self::generarRespuestaConDetalles($configFallback, $historialMensajes, $mensajeActual, $contextoJIT, $nombreCliente);
            }
            return ['success' => false, 'error' => "Error de conexión cURL: " . $curlError, 'http_code' => 0];
        }

        $responseData = json_decode($response, true);

        if ($httpCode === 200 && !empty($responseData['candidates'][0]['content']['parts'])) {
            $parts = $responseData['candidates'][0]['content']['parts'];
            $textoFinal = '';
            foreach ($parts as $p) {
                if (empty($p['thought']) && !empty($p['text'])) {
                    $textoFinal .= $p['text'] . "\n";
                }
            }
            if (empty(trim($textoFinal))) {
                $lastPart = end($parts);
                $textoFinal = $lastPart['text'] ?? '';
            }

            if (!empty(trim($textoFinal))) {
                return [
                    'success' => true, 
                    'text' => trim($textoFinal),
                    'http_code' => 200
                ];
            }
        }

        // Fallback automático hacia gemini-2.0-flash si el modelo configurado falla o alcanza cuota
        if ($httpCode !== 200 && $modelo !== 'gemini-2.0-flash') {
            $configFallback = $configIa;
            $configFallback['modelo_ia'] = 'gemini-2.0-flash';
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
    public static function generarRespuesta(array $configIa, array $historialMensajes, string $mensajeActual, string $contextoJIT): ?string {
        $res = self::generarRespuestaConDetalles($configIa, $historialMensajes, $mensajeActual, $contextoJIT);
        return $res['success'] ? $res['text'] : null;
    }
}
