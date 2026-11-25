<?php
/**
 * Conector de IA, Copiloto de Bandeja ([IA-01], [IA-02]) y Gestión de Ventana Deslizante de Memoria
 * Asistente Virtual Multi-Sede — STARFI CRM (QA-STD-GLOBAL-2.1)
 * - Aislamiento estricto por id_sede sobre configuraciones_ia (CHK-CLD-01).
 * - 100% sentencias preparadas mysqli_prepare() y timeouts cURL <= 8s (CHK-SEC-02, CHK-LAN-05).
 */

class IaConnector {

    /**
     * Obtiene la configuración de IA perteneciente exclusivamente a la sede indicada (sin fallback cruzado).
     */
    public static function obtenerConfigIaSede(mysqli $con, int $idSede, bool $soloBotActivo = false): ?array {
        if ($idSede <= 0) {
            return null;
        }
        $sql = $soloBotActivo
            ? "SELECT * FROM configuraciones_ia WHERE id_sede = ? AND estado_ia = 'ACTIVO' LIMIT 1"
            : "SELECT * FROM configuraciones_ia WHERE id_sede = ? LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $idSede);
        $row = null;
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) {
                $row = $res->fetch_assoc();
            }
        }
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Normaliza un código de sentimiento a los 3 estados oficiales de Sprint 3 ([IA-02]):
     * SATISFECHO | NEUTRO | URGENTE_MOLESTO
     */
    public static function normalizarSentimiento(?string $raw): string {
        $val = strtoupper(trim((string)$raw));
        if (in_array($val, ['SATISFECHO', 'POSITIVO', 'FELIZ', 'CONTENTO'], true)) {
            return 'SATISFECHO';
        }
        if (in_array($val, ['URGENTE_MOLESTO', 'URGENTE', 'MOLESTO', 'NEGATIVO', 'CRITICO', 'ENOJADO'], true)) {
            return 'URGENTE_MOLESTO';
        }
        return 'NEUTRO';
    }

    /**
     * Etiqueta legible oficial asociada a cada estado de sentimiento ([IA-02]).
     */
    public static function etiquetaSentimiento(string $sentimiento): string {
        $norm = self::normalizarSentimiento($sentimiento);
        if ($norm === 'SATISFECHO') {
            return 'Satisfecho';
        }
        if ($norm === 'URGENTE_MOLESTO') {
            return 'Urgente/Molesto';
        }
        return 'Neutro';
    }

    public static function etiquetaHumanaSentimiento(string $sentimiento): string {
        return self::etiquetaSentimiento($sentimiento);
    }

    /**
     * Clasificador léxico ultrarrápido (< 1ms, 100% Offline LAN) de sentimiento en español comercial.
     * Sirve tanto en el hilo crítico del Webhook como de respaldo determinista cuando no hay conexión externa.
     */
    public static function clasificarSentimientoTexto(string $texto): string {
        $clean = mb_strtolower(trim($texto), 'UTF-8');
        if ($clean === '') {
            return 'NEUTRO';
        }

        $patronesUrgenteMolesto = [
            'urgente', 'emergencia', 'molesto', 'molesta', 'indignado', 'indignada',
            'pesimo', 'pésimo', 'mal servicio', 'mala atencion', 'mala atención',
            'reclamo', 'queja', 'estafa', 'engaño', 'engano', 'nadie responde',
            'no me responden', 'llevo horas', 'llevo dias', 'llevo días', 'demora',
            'devolucion', 'devolución', 'reembolso', 'defectuoso', 'dañado', 'danado',
            'roto', 'incumplimiento', 'inaceptable', 'horrible', 'fatal', 'denuncia',
            'cancelar pedido', 'quiero mi dinero', '😡', '🤬', '😤', '👎'
        ];

        $patronesSatisfecho = [
            'muchas gracias', 'mil gracias', 'excelente', 'perfecto', 'genial',
            'buenisimo', 'buenísimo', 'maravilloso', 'muy amable', 'super bien',
            'súper bien', 'encantado', 'encantada', 'satisfecho', 'satisfecha',
            'feliz', 'recomendado', 'todo llego bien', 'todo llegó bien',
            'excelente atencion', 'excelente atención', '5 estrellas', 'bendiciones',
            'agradecido', 'agradecida', '😍', '🙌', '👏', '🎉', '❤️', '⭐⭐⭐⭐⭐'
        ];

        $scoreNeg = 0;
        foreach ($patronesUrgenteMolesto as $kw) {
            if (mb_strpos($clean, $kw, 0, 'UTF-8') !== false) {
                $scoreNeg += 2;
            }
        }
        if ( substr_count($texto, '!') >= 3 || substr_count($texto, '¡') >= 3 ) {
            $scoreNeg += 1;
        }

        $scorePos = 0;
        foreach ($patronesSatisfecho as $kw) {
            if (mb_strpos($clean, $kw, 0, 'UTF-8') !== false) {
                $scorePos += 2;
            }
        }
        if (mb_strpos($clean, 'gracias', 0, 'UTF-8') !== false && $scoreNeg === 0) {
            $scorePos += 1;
        }

        if ($scoreNeg >= 2 && $scoreNeg >= $scorePos) {
            return 'URGENTE_MOLESTO';
        }
        if ($scorePos >= 2 && $scorePos > $scoreNeg) {
            return 'SATISFECHO';
        }
        return 'NEUTRO';
    }

    /**
     * Evalúa el sentimiento consolidado de los últimos mensajes del cliente en una conversación
     * y sincroniza tanto `conversaciones.sentimiento_cliente` como la etiqueta automática en `clientes_contactos.etiquetas`.
     */
    public static function actualizarSentimientoConversacion(mysqli $con, int $idConversacion, ?string $forzarSentimiento = null): string {
        if ($idConversacion <= 0) {
            return 'NEUTRO';
        }

        if ($forzarSentimiento !== null && trim($forzarSentimiento) !== '') {
            $sentimiento = self::normalizarSentimiento($forzarSentimiento);
        } else {
            $stmtMsgs = $con->prepare("
                SELECT contenido
                FROM mensajes_y_eventos
                WHERE id_conversacion = ? AND origen = 'CLIENTE' AND tipo = 'TEXTO'
                ORDER BY id DESC
                LIMIT 5
            ");
            $textos = [];
            if ($stmtMsgs) {
                $stmtMsgs->bind_param("i", $idConversacion);
                if ($stmtMsgs->execute()) {
                    $res = $stmtMsgs->get_result();
                    while ($res && ($r = $res->fetch_assoc())) {
                        $t = trim((string)($r['contenido'] ?? ''));
                        if ($t !== '') {
                            $textos[] = $t;
                        }
                    }
                }
                $stmtMsgs->close();
            }
            if (empty($textos)) {
                $sentimiento = 'NEUTRO';
            } else {
                // Dar prioridad al último mensaje del cliente, o al acumulado reciente
                $ultimo = self::clasificarSentimientoTexto($textos[0]);
                if ($ultimo !== 'NEUTRO') {
                    $sentimiento = $ultimo;
                } else {
                    $sentimiento = self::clasificarSentimientoTexto(implode(' . ', array_slice($textos, 0, 3)));
                }
            }
        }

        // Persistir en conversaciones si la columna existe
        $stmtUpd = @$con->prepare("UPDATE conversaciones SET sentimiento_cliente = ?, sentimiento_actualizado = NOW() WHERE id = ?");
        if ($stmtUpd) {
            $stmtUpd->bind_param("si", $sentimiento, $idConversacion);
            @$stmtUpd->execute();
            $stmtUpd->close();
        }

        // Sincronizar etiqueta automática de sentimiento en clientes_contactos.etiquetas
        $stmtCli = $con->prepare("
            SELECT cl.id, cl.etiquetas
            FROM conversaciones c
            INNER JOIN clientes_contactos cl ON cl.id = c.id_cliente
            WHERE c.id = ?
            LIMIT 1
        ");
        if ($stmtCli) {
            $stmtCli->bind_param("i", $idConversacion);
            if ($stmtCli->execute()) {
                $resCli = $stmtCli->get_result();
                if ($resCli && ($cliRow = $resCli->fetch_assoc())) {
                    $idCliente = intval($cliRow['id']);
                    $rawTags = trim((string)($cliRow['etiquetas'] ?? ''));
                    $parsedTags = [];
                    if ($rawTags !== '') {
                        $decJson = json_decode($rawTags, true);
                        if (is_array($decJson)) {
                            $parsedTags = array_map('strval', $decJson);
                        } else {
                            $parsedTags = explode(',', $rawTags);
                        }
                    }
                    $tagsArr = array_values(array_filter(array_map('trim', $parsedTags), static function ($t) {
                        if ($t === '') return false;
                        $up = mb_strtoupper($t, 'UTF-8');
                        return !in_array($up, [
                            'SATISFECHO', 'NEUTRO', 'URGENTE/MOLESTO', 'URGENTE_MOLESTO',
                            'SENTIMIENTO: SATISFECHO', 'SENTIMIENTO: NEUTRO', 'SENTIMIENTO: URGENTE/MOLESTO'
                        ], true);
                    }));

                    if ($sentimiento === 'URGENTE_MOLESTO') {
                        $tagsArr[] = 'Urgente/Molesto';
                    } elseif ($sentimiento === 'SATISFECHO') {
                        $tagsArr[] = 'Satisfecho';
                    } else {
                        $tagsArr[] = 'Neutro';
                    }

                    $uniqueTags = array_values(array_unique($tagsArr));
                    $newTagsJson = (string)json_encode($uniqueTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($newTagsJson !== $rawTags) {
                        $stmtUpTag = $con->prepare("UPDATE clientes_contactos SET etiquetas = ? WHERE id = ?");
                        if ($stmtUpTag) {
                            $stmtUpTag->bind_param("si", $newTagsJson, $idCliente);
                            if (!@$stmtUpTag->execute()) {
                                $csvFallback = implode(',', $uniqueTags);
                                $stmtUpTag->bind_param("si", $csvFallback, $idCliente);
                                @$stmtUpTag->execute();
                            }
                            $stmtUpTag->close();
                        }
                    }
                }
            }
            $stmtCli->close();
        }

        return $sentimiento;
    }

    /**
     * Recupera transcripción cronológica legible (Cliente / Asesor / Bot) para tareas del Copiloto en Bandeja.
     */
    public static function obtenerTranscripcionLegible(mysqli $con, int $idConversacion, int $limite = 25): array {
        if ($idConversacion <= 0) {
            return [];
        }
        $lim = max(1, min(60, $limite));
        $stmt = $con->prepare("
            SELECT origen, tipo, contenido, timestamp
            FROM mensajes_y_eventos
            WHERE id_conversacion = ?
              AND origen IN ('CLIENTE', 'AGENTE', 'BOT', 'API_TRANSACCIONAL', 'NOTA_INTERNA')
            ORDER BY id DESC
            LIMIT ?
        ");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("ii", $idConversacion, $lim);
        $rows = [];
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($res && ($r = $res->fetch_assoc())) {
                $rows[] = $r;
            }
        }
        $stmt->close();
        $rows = array_reverse($rows);

        $lineas = [];
        foreach ($rows as $m) {
            $orig = strtoupper(trim((string)($m['origen'] ?? '')));
            $txt = trim((string)($m['contenido'] ?? ''));
            if ($txt === '') {
                continue;
            }
            if (strpos($txt, '[TEMPLATE:') === 0 && preg_match('/^\[TEMPLATE:([^:]+):(.*)\]$/s', $txt, $mt)) {
                $txt = "Plantilla oficial enviada ({$mt[1]}): " . str_replace('|', ' · ', $mt[2]);
            } elseif (strpos($txt, 'Envío dinámico de plantilla:') === 0 && preg_match('/plantilla:\s*([^.]+)\.\s*Params:\s*(\[.*\])/s', $txt, $mp)) {
                $decoded = json_decode($mp[2], true);
                $txt = "Plantilla transaccional ({$mp[1]}): " . (is_array($decoded) ? implode(' · ', $decoded) : '');
            }
            $actor = 'ASESOR';
            if ($orig === 'CLIENTE') {
                $actor = 'CLIENTE';
            } elseif ($orig === 'BOT') {
                $actor = 'BOT IA';
            } elseif ($orig === 'NOTA_INTERNA') {
                $actor = 'NOTA INTERNA';
            } elseif ($orig === 'API_TRANSACCIONAL') {
                $actor = 'SISTEMA ERP';
            }
            $lineas[] = [
                'actor' => $actor,
                'origen' => $orig,
                'texto' => mb_substr($txt, 0, 500, 'UTF-8'),
                'timestamp' => (string)($m['timestamp'] ?? ''),
            ];
        }
        return $lineas;
    }

    /**
     * Ejecuta una instrucción puntual sobre Gemini usando exclusivamente la API Key de la sede.
     */
    public static function ejecutarPromptCopilotoSede(array $configIa, string $systemInstruction, string $userPrompt, float $temperature = 0.3, int $maxTokens = 450): array {
        $apiKey = trim((string)($configIa['gemini_api_key'] ?? ''));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'La sede no posee una Gemini API Key configurada en Agente IA.'];
        }
        $modelo = !empty($configIa['modelo_ia']) ? trim((string)$configIa['modelo_ia']) : 'gemini-2.0-flash';
        $payload = [
            'system_instruction' => [
                'parts' => [['text' => self::utf8Clean($systemInstruction)]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => self::utf8Clean($userPrompt)]],
                ],
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => $maxTokens,
                'topP' => 0.9,
            ],
        ];

        $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        if ($jsonPayload === false) {
            return ['success' => false, 'error' => 'Error codificando solicitud JSON para IA.'];
        }

        $modelosIntentar = array_values(array_unique([$modelo, 'gemini-2.0-flash']));
        $lastErr = 'No se obtuvo respuesta válida del modelo IA.';

        foreach ($modelosIntentar as $modTry) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($modTry) . ":generateContent?key=" . rawurlencode($apiKey);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $resp = curl_exec($ch);
            $httpCode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
            $cErr = curl_error($ch);
            curl_close($ch);

            if ($resp === false || $cErr !== '') {
                $lastErr = 'Error de red con proveedor IA: ' . $cErr;
                continue;
            }
            $data = json_decode((string)$resp, true);
            if ($httpCode === 200 && !empty($data['candidates'][0]['content']['parts'])) {
                $outTxt = '';
                foreach ($data['candidates'][0]['content']['parts'] as $part) {
                    if (empty($part['thought']) && !empty($part['text'])) {
                        $outTxt .= $part['text'] . "\n";
                    }
                }
                if (trim($outTxt) === '') {
                    $lastPart = end($data['candidates'][0]['content']['parts']);
                    $outTxt = (string)($lastPart['text'] ?? '');
                }
                if (trim($outTxt) !== '') {
                    return ['success' => true, 'text' => trim($outTxt), 'model' => $modTry];
                }
            }
            $lastErr = (string)($data['error']['message'] ?? ('HTTP ' . $httpCode));
        }

        return ['success' => false, 'error' => $lastErr];
    }

    /**
     * [IA-01] Genera un Resumen Ejecutivo en exactamente 3 viñetas de la conversación antes de que el asesor intervenga.
     * Si la sede posee Gemini API Key activa y conectada usa IA generativa; de lo contrario construye un resumen
     * extractivo local determinista de 3 viñetas para continuidad 100% Offline LAN.
     */
    public static function resumirConversacionEjecutiva(mysqli $con, int $idSede, int $idConversacion, string $nombreCliente = ''): array {
        $lineas = self::obtenerTranscripcionLegible($con, $idConversacion, 25);
        if (empty($lineas)) {
            return [
                'ok' => true,
                'success' => true,
                'bullets' => [
                    'Conversación recién iniciada sin historial previo de mensajes de texto.',
                    'Aún no se registran acuerdos comerciales, montos ni cotizaciones previas.',
                    'Acción sugerida: Saludar cordialmente al cliente e indagar su requerimiento principal.',
                ],
                'sentimiento' => 'NEUTRO',
                'sentimiento_label' => self::etiquetaHumanaSentimiento('NEUTRO'),
                'source' => 'LOCAL_EXTRACTIVE',
            ];
        }

        $sentimientoLexico = self::actualizarSentimientoConversacion($con, $idConversacion);
        $configIa = self::obtenerConfigIaSede($con, $idSede, false);

        if ($configIa && !empty(trim((string)($configIa['gemini_api_key'] ?? '')))) {
            $transcripcionStr = '';
            foreach ($lineas as $l) {
                $transcripcionStr .= "[{$l['actor']}]: {$l['texto']}\n";
            }
            $sys = "Eres un Copiloto Supervisor de Ventas y Atención al Cliente de STARFI CRM. "
                 . "Tu tarea es resumir una conversación de WhatsApp para un asesor humano que está a punto de intervenir.\n"
                 . "REGLAS ESTRICTAS DE SALIDA:\n"
                 . "1. Responde ÚNICAMENTE en formato JSON válido sin bloques markdown, con esta estructura exacta:\n"
                 . "{\"bullets\":[\"Viñeta 1\",\"Viñeta 2\",\"Viñeta 3\"],\"sentimiento\":\"SATISFECHO|NEUTRO|URGENTE_MOLESTO\"}\n"
                 . "2. El arreglo 'bullets' debe tener EXACTAMENTE 3 elementos cortos, ejecutivos y accionables:\n"
                 . "   - Viñeta 1: Qué solicita o necesita el cliente.\n"
                 . "   - Viñeta 2: Qué se le ha respondido, cotizado o acordado hasta ahora.\n"
                 . "   - Viñeta 3: Próximo paso inmediato recomendado para el asesor humano.\n"
                 . "3. No inventes montos ni datos que no estén en la transcripción.";

            $usr = "Cliente: " . ($nombreCliente !== '' ? $nombreCliente : 'Cliente WhatsApp') . "\n"
                 . "Transcripción reciente:\n" . $transcripcionStr;

            $iaRes = self::ejecutarPromptCopilotoSede($configIa, $sys, $usr, 0.25, 420);
            if ($iaRes['success']) {
                $rawOut = trim((string)$iaRes['text']);
                $rawJson = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $rawOut);
                $decoded = json_decode((string)$rawJson, true);
                if (is_array($decoded) && !empty($decoded['bullets']) && is_array($decoded['bullets'])) {
                    $bullets = [];
                    foreach (array_slice($decoded['bullets'], 0, 3) as $b) {
                        $cleanB = trim(preg_replace('/^[\-\•\*0-9\.\)\s]+/u', '', (string)$b));
                        if ($cleanB !== '') {
                            $bullets[] = $cleanB;
                        }
                    }
                    while (count($bullets) < 3) {
                        $bullets[] = 'Retomar contacto directo con el cliente para confirmar su solicitud.';
                    }
                    $sentIa = !empty($decoded['sentimiento'])
                        ? self::actualizarSentimientoConversacion($con, $idConversacion, (string)$decoded['sentimiento'])
                        : $sentimientoLexico;

                    $resumenGuardar = (string)json_encode(array_values($bullets), JSON_UNESCAPED_UNICODE);
                    $stmtSave = @$con->prepare("UPDATE conversaciones SET resumen_ia = ?, resumen_ia_actualizado = NOW() WHERE id = ?");
                    if ($stmtSave) {
                        $stmtSave->bind_param("si", $resumenGuardar, $idConversacion);
                        @$stmtSave->execute();
                        $stmtSave->close();
                    }

                    return [
                        'ok' => true,
                        'success' => true,
                        'bullets' => $bullets,
                        'sentimiento' => $sentIa,
                        'sentimiento_label' => self::etiquetaHumanaSentimiento($sentIa),
                        'source' => 'GEMINI_IA',
                    ];
                }
            }
        }

        // Respaldo extractivo determinista de 3 viñetas (continuidad operativa 100% Offline LAN)
        $msgsCliente = [];
        $msgsAgente = [];
        foreach ($lineas as $l) {
            if ($l['origen'] === 'CLIENTE') {
                $msgsCliente[] = $l['texto'];
            } elseif (in_array($l['origen'], ['AGENTE', 'BOT', 'API_TRANSACCIONAL'], true)) {
                $msgsAgente[] = $l['texto'];
            }
        }
        $primerCliente = !empty($msgsCliente) ? mb_substr($msgsCliente[0], 0, 140, 'UTF-8') : 'Sin mensaje textual directo del cliente.';
        $ultimoCliente = count($msgsCliente) > 1 ? mb_substr((string)end($msgsCliente), 0, 140, 'UTF-8') : '';
        $ultimoAgente = !empty($msgsAgente) ? mb_substr((string)end($msgsAgente), 0, 140, 'UTF-8') : 'Pendiente de primera intervención de asesor humano.';

        $b1 = 'Solicitud del cliente: "' . $primerCliente . '"' . ($ultimoCliente !== '' ? ' (Último mensaje: "' . $ultimoCliente . '")' : '');
        $b2 = 'Última gestión registrada: ' . $ultimoAgente;
        $b3 = ($sentimientoLexico === 'URGENTE_MOLESTO')
            ? 'Prioridad ALTA (Urgente/Molesto): Atender con empatía inmediata, validar el reclamo y ofrecer solución concreta.'
            : (($sentimientoLexico === 'SATISFECHO')
                ? 'Cliente receptivo/satisfecho: Agradecer la confianza y avanzar al cierre comercial o confirmación de pedido.'
                : 'Acción recomendada: Saludar por su nombre, despejar dudas puntuales y guiar hacia cotización o cierre.');

        $bullets = [$b1, $b2, $b3];
        $resumenGuardar = (string)json_encode(array_values($bullets), JSON_UNESCAPED_UNICODE);
        $stmtSave = @$con->prepare("UPDATE conversaciones SET resumen_ia = ?, resumen_ia_actualizado = NOW() WHERE id = ?");
        if ($stmtSave) {
            $stmtSave->bind_param("si", $resumenGuardar, $idConversacion);
            @$stmtSave->execute();
            $stmtSave->close();
        }

        return [
            'ok' => true,
            'success' => true,
            'bullets' => $bullets,
            'sentimiento' => $sentimientoLexico,
            'sentimiento_label' => self::etiquetaHumanaSentimiento($sentimientoLexico),
            'source' => 'LOCAL_EXTRACTIVE',
        ];
    }

    /**
     * [IA-02] Sugiere una respuesta contextual lista para enviar (si el borrador está vacío)
     * o mejora el tono/redacción del borrador escrito por el asesor humano, devolviendo también el sentimiento.
     */
    public static function asistirRespuestaAsesor(
        mysqli $con,
        int $idSede,
        int $idConversacion,
        string $borrador = '',
        string $tono = 'profesional',
        string $nombreCliente = ''
    ): array {
        $borradorTrim = trim($borrador);
        $esMejora = ($borradorTrim !== '');
        $tonoNorm = strtolower(trim($tono)) ?: 'profesional';
        $sentimientoActual = self::actualizarSentimientoConversacion($con, $idConversacion);
        $lineas = self::obtenerTranscripcionLegible($con, $idConversacion, 15);
        $configIa = self::obtenerConfigIaSede($con, $idSede, false);

        $nombreSaludo = '';
        $nomClean = trim($nombreCliente);
        if ($nomClean !== '' && !in_array(mb_strtolower($nomClean, 'UTF-8'), ['usuario', 'cliente'], true) && !preg_match('/^\+?[0-9\s\-]+$/', $nomClean)) {
            $partesNom = preg_split('/\s+/u', $nomClean, -1, PREG_SPLIT_NO_EMPTY);
            $nombreSaludo = $partesNom[0] ?? $nomClean;
        }

        if ($configIa && !empty(trim((string)($configIa['gemini_api_key'] ?? '')))) {
            $transcripcionStr = '';
            foreach ($lineas as $l) {
                $transcripcionStr .= "[{$l['actor']}]: {$l['texto']}\n";
            }
            $instruccionesSede = trim((string)($configIa['agente_instrucciones'] ?? ''));
            $dirSede = trim((string)($configIa['direccion_sede'] ?? ''));

            $mapaTonos = [
                'profesional' => 'profesional, claro, cordial y ejecutivo',
                'empatico'    => 'altamente empático, calmado, resolutivo y orientado a desescalar cualquier molestia',
                'cierre'      => 'comercial, persuasivo, entusiasta y enfocado en concretar la venta o pedido hoy mismo',
                'conciso'     => 'breve, directo al grano y fácil de leer en 2 líneas de WhatsApp',
            ];
            $descTono = $mapaTonos[$tonoNorm] ?? $mapaTonos['profesional'];

            $sys = "Eres el Copiloto de Redacción Comercial en WhatsApp para asesores humanos de STARFI CRM.\n"
                 . ($instruccionesSede !== '' ? "Contexto comercial de esta sede: {$instruccionesSede}\n" : "")
                 . ($dirSede !== '' ? "Dirección de esta sede: {$dirSede}\n" : "")
                 . "REGLAS ESTRICTAS:\n"
                 . "1. Devuelve ÚNICAMENTE el texto final del mensaje listo para enviar al cliente por WhatsApp (sin comillas envolventes, sin explicaciones previas, sin etiquetas internas como [SOLICITAR_AGENTE_HUMANO]).\n"
                 . "2. Usa tono {$descTono}.\n"
                 . "3. NO inventes precios, números de cuenta ni existencias que no figuren en el borrador o conversación.";

            if ($esMejora) {
                $usr = "Cliente: " . ($nombreSaludo !== '' ? $nombreSaludo : 'Cliente') . " (Sentimiento actual: {$sentimientoActual})\n"
                     . "Contexto reciente:\n{$transcripcionStr}\n"
                     . "Borrador escrito por el asesor que debes mejorar y pulir con tono {$descTono}:\n\"{$borradorTrim}\"";
            } else {
                $usr = "Cliente: " . ($nombreSaludo !== '' ? $nombreSaludo : 'Cliente') . " (Sentimiento actual: {$sentimientoActual})\n"
                     . "Conversación reciente:\n{$transcripcionStr}\n"
                     . "Redacta la mejor respuesta sugerida para que el asesor humano responda ahora mismo al cliente.";
            }

            $iaRes = self::ejecutarPromptCopilotoSede($configIa, $sys, $usr, 0.35, 350);
            if ($iaRes['success']) {
                $sugerencia = trim((string)$iaRes['text']);
                $sugerencia = preg_replace('/^["“”]+|["“”]+$/u', '', $sugerencia);
                $sugerencia = trim(str_replace(['[SOLICITAR_AGENTE_HUMANO]'], '', $sugerencia));
                if ($sugerencia !== '') {
                    return [
                        'ok'                => true,
                        'success'           => true,
                        'mode'              => $esMejora ? 'IMPROVE_TONE' : 'SUGGEST_REPLY',
                        'tone'              => $tonoNorm,
                        'suggestion'        => $sugerencia,
                        'sentimiento'       => $sentimientoActual,
                        'sentimiento_label' => self::etiquetaHumanaSentimiento($sentimientoActual),
                        'source'            => 'GEMINI_IA',
                    ];
                }
            }
        }

        // Respaldo inteligente local cuando la sede aún no tiene Gemini API Key o opera en LAN sin salida
        $saludo = $nombreSaludo !== '' ? "¡Hola {$nombreSaludo}!" : "¡Hola!";
        if ($esMejora) {
            $base = ucfirst(rtrim($borradorTrim, ". \t\n\r"));
            if ($tonoNorm === 'empatico' || $sentimientoActual === 'URGENTE_MOLESTO') {
                $sugerencia = "{$saludo} Comprendemos totalmente tu solicitud y lamentamos cualquier inconveniente. {$base}. Quedo atento personalmente para resolverlo de inmediato. 🤝";
            } elseif ($tonoNorm === 'cierre') {
                $sugerencia = "{$saludo} ✨ {$base}. ¿Deseas que procesemos tu pedido de una vez para reservarte disponibilidad hoy mismo?";
            } elseif ($tonoNorm === 'conciso') {
                $sugerencia = "{$saludo} {$base}. Quedo atento a tu confirmación.";
            } else {
                $sugerencia = "{$saludo} Con mucho gusto te informo: {$base}. Quedo a tu completa disposición para cualquier detalle adicional.";
            }
        } else {
            if ($sentimientoActual === 'URGENTE_MOLESTO') {
                $sugerencia = "{$saludo} Te saluda tu asesor asignado. Lamento mucho el inconveniente presentado; ya estoy revisando tu caso con prioridad para darte solución inmediata. ¿Me confirmas por favor algún detalle adicional para agilizarlo? 🤝";
            } elseif ($sentimientoActual === 'SATISFECHO') {
                $sugerencia = "{$saludo} ¡Muchas gracias por escribirnos! 😊 Me alegra atenderte personalmente. ¿Cómo te gustaría avanzar con tu solicitud el día de hoy?";
            } else {
                $sugerencia = "{$saludo} Te atiende tu asesor comercial. Ya revisé tu consulta y con todo gusto te ayudo personalmente. ¿Qué modelos, medidas o cantidades necesitas cotizar hoy? ✨";
            }
        }

        return [
            'ok'                => true,
            'success'           => true,
            'mode'              => $esMejora ? 'IMPROVE_TONE' : 'SUGGEST_REPLY',
            'tone'              => $tonoNorm,
            'suggestion'        => $sugerencia,
            'sentimiento'       => $sentimientoActual,
            'sentimiento_label' => self::etiquetaHumanaSentimiento($sentimientoActual),
            'source'            => 'LOCAL_SMART_TEMPLATE',
        ];
    }

    /**
     * Recupera los últimos N mensajes de la conversación y mapea a roles 'user' y 'model'.
     */
    public static function recuperarHistorialMensajes(mysqli $con, int $idConversacion, int $limite = 12): array {
        if ($idConversacion <= 0) return [];
        $lim = max(1, min(50, $limite));

        $stmt = $con->prepare("SELECT origen, contenido, tipo 
                FROM mensajes_y_eventos 
                WHERE id_conversacion = ? AND tipo IN ('TEXTO', 'IMAGEN', 'EVENTO_SISTEMA')
                ORDER BY id DESC 
                LIMIT ?");
        if (!$stmt) return [];
        $stmt->bind_param("ii", $idConversacion, $lim);
        if (!$stmt->execute()) {
            $stmt->close();
            return [];
        }
        $res = $stmt->get_result();
        $filas = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $filas[] = $row;
            }
        }
        $stmt->close();

        $filas = array_reverse($filas);

        $historial = [];
        foreach ($filas as $msg) {
            $origenUpper = strtoupper($msg['origen']);
            $texto = trim($msg['contenido'] ?? '');
            if (empty($texto)) continue;

            if ($msg['tipo'] === 'EVENTO_SISTEMA' && strpos($texto, 'Envío dinámico de plantilla:') === false && strpos($texto, 'Difusión enviada:') === false) {
                continue;
            }

            if (strpos($texto, 'Envío dinámico de plantilla:') === 0) {
                if (preg_match('/plantilla:\s*([^.]+)\.\s*Params:\s*(\[.*\])/s', $texto, $m)) {
                    $tplName = trim($m[1]);
                    $params = json_decode($m[2], true);
                    if (is_array($params)) {
                        if ($tplName === 'starfi_difusion_directa' && count($params) >= 3) {
                            $texto = "👋¡Hola {$params[0]}!\nHoy queremos {$params[1]}.\nRecuerda que en {$params[2]}.\n📲 Responde a este mensaje para obtener más información👇";
                        } else {
                            $texto = "Mensaje de plantilla ($tplName) enviado al cliente: " . implode(' | ', $params);
                        }
                    }
                }
            } elseif (strpos($texto, '[TEMPLATE:') === 0 && preg_match('/^\[TEMPLATE:([^:]+):(.*)\]$/s', $texto, $m)) {
                $tplName = trim($m[1]);
                $params = explode('|', $m[2]);
                if ($tplName === 'starfi_difusion_directa' && count($params) >= 3) {
                    $texto = "👋¡Hola {$params[0]}!\nHoy queremos {$params[1]}.\nRecuerda que en {$params[2]}.\n📲 Responde a este mensaje para obtener más información👇";
                } else {
                    $texto = "Mensaje de plantilla ($tplName) enviado al cliente: " . implode(' | ', $params);
                }
            }

            $role = ($origenUpper === 'CLIENTE') ? 'user' : 'model';
            $historial[] = [
                'role' => $role,
                'parts' => [['text' => $texto]]
            ];
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

        $nombreValido = false;
        if (!empty($nombreCliente)) {
            $nombreClean = strtolower(trim($nombreCliente));
            if ($nombreClean !== 'usuario' && $nombreClean !== 'cliente' && !preg_match('/^\+?[0-9\s\-]+$/', $nombreClean)) {
                $nombreValido = true;
            }
        }

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
            $systemPrompt .= "   - Si la conversación comenzó con una difusión o invitación enviada por nosotros, responde directamente en contexto con esa invitación/promoción de forma cálida y personalizada.\n";
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

        $contents = [];
        $lastRole = null;

        if (is_array($historialMensajes)) {
            foreach ($historialMensajes as $msg) {
                $role = ($msg['role'] === 'model') ? 'model' : 'user';
                $text = self::utf8Clean(trim($msg['parts'][0]['text'] ?? ''));
                if (empty($text)) continue;

                if (empty($contents) && $role === 'model') {
                    $contents[] = [
                        'role' => 'user',
                        'parts' => [['text' => '[Inicio de conversación por campaña de difusión oficial]']]
                    ];
                    $lastRole = 'user';
                }

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

        $modelo = !empty($configIa['modelo_ia']) ? $configIa['modelo_ia'] : 'gemini-2.0-flash';
        $payload = self::construirPayload($configIa, $historialMensajes, $mensajeActual, $contextoJIT, $nombreCliente);

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
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

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
    public static function generarRespuesta(array $configIa, array $historialMensajes, string $mensajeActual, string $contextoJIT, string $nombreCliente = ''): ?string {
        $res = self::generarRespuestaConDetalles($configIa, $historialMensajes, $mensajeActual, $contextoJIT, $nombreCliente);
        return $res['success'] ? $res['text'] : null;
    }
}
