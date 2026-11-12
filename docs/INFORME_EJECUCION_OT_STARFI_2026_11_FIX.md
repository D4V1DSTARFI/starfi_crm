# INFORME TÉCNICO DE EJECUCIÓN — `OT-STARFI-2026-11-FIX`

**Proyecto:** STARFI CRM (Ecosistema Omnicanal WhatsApp Cloud API Multi-Sede)  
**Orden de Trabajo:** `OT-STARFI-2026-11-FIX` — *Estabilización de Bandeja Omnicanal, Blindaje de Webhook, Seguridad Multi-Sede y Estandarización WABA v23.0*  
**Fecha de Ejecución:** 12 de noviembre de 2026  
**Rama de Trabajo:** `development`  
**Estado Final:** **100% Completado y Verificado (`54 / 54` pruebas en vivo aprobadas)**

---

## 1. Resumen Ejecutivo (Antes vs. Después)

| Dimensión | Estado Anterior | Estado Actual (Cómo Quedó) |
| :--- | :--- | :--- |
| **Bandeja Omnicanal & SSE** | Sesión leída tras `session_write_close()`, sin filtro estricto de sede en SSE, checks de lectura/error requerían `F5`, `load_chats` bloqueaba filas con `UPDATE` masivo y mezclaba difusiones en *Ventas*. | SSE aislado por rol/sede, actualización en vivo de checks (`ENTREGADO`, `LEIDO`, `FALLIDO`) vía firma `CRC32`, `load_chats` **100% lectura** (`330 ms`) con trigger O(1) en MySQL y separación estricta *Ventas* vs. *Campañas*. |
| **Motor Webhook WABA** | Sin control idempotente por `wamid` antes de guardar, 7 subconsultas `COUNT(*)` correlacionadas + `SHOW TABLES` en cada evento, y espera síncrona de IA/contactos antes de responder a Meta. | Idempotencia inmediata por `idx_meta_wamid` (`50 ms`), recálculo de embudo en **1 sola pasada agregada**, y cierre temprano HTTP `200 OK` (`< 300 ms`) antes de ejecutar Gemini IA o envíos de contactos. |
| **Seguridad & Multi-Sede** | `get_media.php` accesible sin sesión y con SQL concatenado, vulnerabilidad IDOR cruzada entre sedes en Bandeja/Bots, `.env` y 53 logs versionados en Git, tokens/IPs/claves en duro, `SSL_VERIFYPEER = false` y 13 scripts de prueba públicos en raíz. | `get_media.php` exige sesión (`401`) y valida sede (`403`) con `prepare/bind_param`, bloqueo IDOR multi-sede en Bandeja y Gestor de Bots (`403`), secretos exclusivamente en `.env` (ignorado en Git + `.env.example`), SSL activo en todo cURL, raíz depurada y bloqueo `.htaccess` (`403`) en directorios sensibles. |
| **Estandarización & KPIs** | Mezcla de Graph API `v19.0` y `v23.0`, apellido fijo `"SuperFormica"` en contactos de asesores, KPIs mock (`15 min` y `chats * 0.10`) en Dashboard, y código duplicado de campañas en Directorio. | **100% unificado en Meta Graph API `v23.0`** (`META_API_VERSION`), marca dinámica desde `sedes.nombre_sede` en vCards, KPIs y CAC **100% reales** desde BD optimizados a `721 ms` (`106 ms` filtrado), y difusión heredada en Directorio deprecada (`HTTP 410`) hacia Campañas. |

---

## 2. Historial de Commits Atómicos en `development`

1. **`4a46f26`** — `fix(bandeja,campanas): FASE 1 (BAN-01..BAN-04) - SSE por rol/sede y estados en vivo, filtro Ventas sin difusiones y load_chats 100% lectura`
2. **`6917bd3`** — `perf(webhook): FASE 2 (WHK-01..WHK-03) - idempotencia por id_mensaje_meta, embudo en 1 pasada y HTTP 200 inmediato antes de IA`
3. **`7b50669`** — `sec(fase-3): blindaje IDOR multi-sede, externalizacion de secretos .env y limpieza publica (SEC-01..04)`
4. **`388c718`** — `refactor(fase-4): unificacion Meta Graph API v23.0, sede dinamica vCard, KPIs reales y limpieza Directorio (STD-01..04)`
5. **`6397187`** — `perf(dashboard): consolidar KPIs en 1 lectura e indexar idx_msg_pagado_ts (8.8s -> 720ms)`

---

## 3. Detalle Técnico de las Correcciones Realizadas

### FASE 1 — Estabilización de Bandeja Omnicanal y Campañas

#### `BAN-01` — Flujo SSE en tiempo real por Rol/Sede y Checks de Estado
- **Archivos:** `modules/bandeja/sse_updates.php`, `modules/bandeja/funciones_bandeja.js`
- **Problema:** `getAgenteInfo()` se invocaba después de cerrar sesión o sin cruzar `lineas_whatsapp.id_sede`; los Administradores/Gerentes no recibían eventos de chats en `ATENDIENDO` de su sede, y los cambios de estado de envío (`ENTREGADO`, `LEIDO` doble check azul, `FALLIDO`) no disparaban actualización al no cambiar el `MAX(id)`.
- **Solución:**
  - Captura de identidad, rol e `id_sede` antes de `session_write_close()` para no bloquear el archivo de sesión PHP durante el bucle SSE.
  - Aislamiento por `JOIN lineas_whatsapp l ON c.id_linea = l.id` forzando `l.id_sede = $user_sede` en roles no-Master; cobertura completa de sede para `ADMIN`/`GERENTE` y filtro `c.id_agente = $agente_id OR c.estado = 'ESPERA_ASIGNACION'` para `OPERADOR`.
  - Firma de cambios de estado (`BIT_XOR(CRC32(...))` + `SUM(CRC32(...))`) sobre la ventana activa de mensajes para refrescar en vivo tanto la barra lateral como la conversación abierta mediante `loadChats(..., true)` silencioso sin recargar la página.

#### `BAN-02` — Separación Estricta entre Filtro Ventas y Difusiones Masivas
- **Archivo:** `modules/bandeja/back_bandeja.php`
- **Problema:** Los mensajes salientes de campañas masivas (`origen = 'API_TRANSACCIONAL'`) activaban el badge y la pestaña de **Ventas**.
- **Solución:** Se excluyeron explícitamente las plantillas de marketing/difusión (`contenido NOT LIKE '%[DIFUSIÓN:%' AND contenido NOT LIKE '%| Texto:%' AND IFNULL(categoria_meta, '') != 'MARKETING'`) tanto del filtro `$filter === 'ventas'` como del indicador `flag_venta`, clasificándolas únicamente bajo **Campañas** (`flag_campana`).

#### `BAN-03` — Desacoplamiento de Escritura en `load_chats` (100% `SELECT`)
- **Archivos:** `modules/bandeja/back_bandeja.php`, `modules/campanas/back_campanas.php`, `cron_campanas_autopilot.php`, `webhook.php`, `api_notificaciones.php`
- **Problema:** Cada llamada a `load_chats` ejecutaba un `UPDATE conversaciones c JOIN (...) SET ultimo_msg_id = ...` provocando contención de bloqueos InnoDB cuando múltiples operadores tenían abierta la bandeja.
- **Solución:**
  - Se eliminó por completo el `UPDATE` dentro de `load_chats`, dejándolo 100% de solo lectura.
  - Se instaló el trigger automático `trg_mensajes_ultimo_msg_ai` (`AFTER INSERT ON mensajes_y_eventos`) que actualiza `conversaciones.ultimo_msg_id = GREATEST(IFNULL(ultimo_msg_id, 0), NEW.id)` en O(1), respaldado además por la función auxiliar `sync_ultimo_msg_conversacion()` en cada inserción PHP.
  - Se reemplazaron las llamadas posteriores a `session_write_close()` por `$agente_info_global` cacheado al inicio del request.

#### `BAN-04` — Parseo Defensivo de Respuestas en Campañas
- **Archivo:** `modules/campanas/funciones_campanas.js`
- **Solución:** Se verificó y preservó en `recargarEcosistema()` la lectura por `await resp.text()` seguida de bloque `try / catch` con `JSON.parse(rawText)`, evitando caídas silenciosas por `SyntaxError: Unexpected token '<'` ante errores de red o proxy.

---

### FASE 2 — Blindaje y Rendimiento del Motor Webhook

#### `WHK-01` — Idempotencia Estricta por `id_mensaje_meta` (`wamid`)
- **Archivo:** `webhook.php`
- **Problema:** Ante reintentos de entrega de Meta (red lenta o timeouts previos), el mismo `wamid` podía duplicar mensajes entrantes, disparar dos veces el bot/IA o duplicar respuestas CSAT.
- **Solución:** Se aseguró el índice `idx_meta_wamid` sobre `mensajes_y_eventos(id_mensaje_meta)` y se incorporó validación previa `SELECT id FROM mensajes_y_eventos USE INDEX (idx_meta_wamid) WHERE id_mensaje_meta = ? LIMIT 1`. Si el `wamid` ya fue procesado, el webhook responde `HTTP 200 EVENT_RECEIVED` y finaliza en ~50 ms sin duplicar registros.

#### `WHK-02` — Recálculo Agregado del Embudo de Campañas en 1 Sola Pasada
- **Archivo:** `webhook.php`
- **Problema:** En cada acuse de recibo (`sent`, `delivered`, `read`, `failed`) o respuesta de cliente, el webhook ejecutaba `SHOW TABLES LIKE 'campanas_difusion_destinatarios'` y 7 subconsultas `SELECT COUNT(*)` independientes sobre la tabla de destinatarios.
- **Solución:** Se eliminaron los `SHOW TABLES` repetitivos y se implementó `recalcular_embudo_campana_webhook($con, $id_camp)` que consolida `pendientes`, `enviados`, `entregados`, `leidos`, `respondidos` y `fallidos` en un único `UPDATE ... INNER JOIN (SELECT ... SUM(...) GROUP BY id_campana)` ejecutado una sola vez por campaña afectada al cierre del lote.

#### `WHK-03` — Respuesta Temprana HTTP `200 OK` (< 300 ms) antes de IA y Contactos
- **Archivo:** `webhook.php`
- **Problema:** La generación de respuesta con Gemini (`IaConnector::generarRespuesta`, 2–8 s) y el bucle de envío de tarjetas de asesores (`usleep(500000)` por contacto) retenían abierta la conexión HTTP con Meta, exponiendo el endpoint a reintentos por timeout.
- **Solución:** Se implementó `responder_http_200_y_continuar()` (`ignore_user_abort(true)`, `fastcgi_finish_request()` o cabeceras `Connection: close` + `Content-Length` + `flush()`), liberando a Meta con `HTTP 200 OK` inmediatamente después de guardar el mensaje entrante en MySQL mientras PHP completa en segundo plano el marcado de lectura, la inferencia IA y el despacho de contactos.

---

### FASE 3 — Seguridad Multi-Sede, Secretos e Higiene Pública

#### `SEC-01` — Autenticación Obligatoria, Control de Sede y Consultas Preparadas en `get_media.php`
- **Archivo:** `get_media.php`
- **Solución:**
  - Integración con `core/auth.php`: rechazo inmediato `HTTP 401 Unauthorized` cuando no existe sesión válida o expiró por inactividad.
  - Verificación de autorización multi-sede/operador sobre `chat_id` antes de servir archivos locales, caché o descargas desde Meta (`HTTP 403 Forbidden` si el chat pertenece a otra sede).
  - Conversión del 100% de consultas SQL a sentencias preparadas (`prepare` / `bind_param`), externalización de `CENTRAL_MEDIA_BASE_URL` al `.env` y activación de `CURLOPT_SSL_VERIFYPEER = true`.

#### `SEC-02` — Cierre de Vulnerabilidades IDOR Multi-Sede en Bandeja y Gestor de Bots
- **Archivos:** `modules/bandeja/back_bandeja.php`, `modules/gestor_bots/back_gestor_bots.php`
- **Solución:**
  - En `back_bandeja.php`: funciones `authorize_conversacion_access()` y `authorize_cliente_access()` aplicadas en `load_messages`, `send_message`, `upload_media`, `retry_message`, `close_chat`, `mark_as_read`, `reassign_chat` (validando además que el agente destino pertenezca a la misma sede), `load_profile`, `save_profile`, `add_tag`, `remove_tag`, `get_all_tags` y `get_agents`.
  - En `back_gestor_bots.php`: reescritura integral con sentencias preparadas y validación estricta `AND id_sede = ?` en `load_rules`, `save_rule`, `delete_rule`, `update_network` (incluyendo validación de sede del nodo padre), `load_contactos`, `save_contacto`, `delete_contacto` y `toggle_bot`.

#### `SEC-03` — Externalización de Credenciales a `.env` y Verificación SSL Activa
- **Archivos:** `config/database.php`, `.env.example`, `.gitignore`, `webhook.php`, `install.php`, `database/update_costs.php`, `modules/configuracion/gema_config.json`, `core/IaConnector.php`, `cron_campanas_autopilot.php`, `modules/campanas/back_campanas.php`, `modules/dashboard/back_waba_billing.php`, `modules/dashboard/back_whatsapp_analytics.php`
- **Solución:**
  - Eliminación total de tokens de Meta, IPs internas y contraseñas codificadas en `config/database.php`, `webhook.php`, `install.php`, `database/update_costs.php` y `gema_config.json`.
  - Validación de `hub_verify_token` en `webhook.php` usando `hash_equals()` exclusivamente contra `WEBHOOK_VERIFY_TOKEN` de `.env` (bloqueando con `403` tokens vacíos o inválidos).
  - Desindexación de `.env` del repositorio (`git rm --cached .env`) conservando el archivo funcional en el servidor, actualización de `.gitignore` y creación de la plantilla documentada `.env.example`.
  - Activación de `CURLOPT_SSL_VERIFYPEER = true` (y `CURLOPT_SSL_VERIFYHOST = 2` en `IaConnector.php`) en todas las peticiones salientes hacia Meta y Google Gemini.

#### `SEC-04` — Higiene de Raíz Pública, Logs y Reglas `.htaccess`
- **Archivos creados/modificados:** `.htaccess`, `logs/.htaccess`, `config/.htaccess`, `core/.htaccess`, `database/.htaccess`, `api_notificaciones.php`, `simulador_whatsapp.php`
- **Archivos temporales eliminados (13):** `export_databases.php`, `print_all_rows.php`, `print_sandbox_sedes.php`, `cleanup_empresa_perfil.php`, `scratch_check_sede_1.php`, `scratch_db.php`, `test.php`, `test_api.php`, `test_pdf.php`, `test_fn.txt`, `test_meta_payload.log`, `generate_cron.php`, `update_sidebars.php` (más desindexación en Git de los 53 archivos históricos en `logs/`).
- **Solución:** Desactivado el volcado en texto plano `test_meta_payload.log` dentro de `api_notificaciones.php`, protegido `simulador_whatsapp.php` con `requireAuth()`, deshabilitado el listado de directorios (`Options -Indexes`) y bloqueado con `HTTP 403 Forbidden` cualquier intento de acceso HTTP directo a `.env`, `.sql`, `.log`, `.exe`, `/logs/`, `/config/`, `/core/` y `/database/`.

---

### FASE 4 — Estandarización Graph API `v23.0`, Marca Dinámica y KPIs Reales

#### `STD-01` — Unificación Global de Meta Graph API `v23.0`
- **Archivos:** `config/database.php`, `modules/bandeja/back_bandeja.php`, `webhook.php`, `modules/configuracion/back_configuracion.php`, `get_media.php`
- **Solución:** Definición central de la constante `META_API_VERSION` (`v23.0` por defecto o configurable desde `.env`) y migración de los 12 endpoints heredados que apuntaban a `v19.0`. Quedaron **`0` referencias a `v19.0`** en todo el código fuente.

#### `STD-02` — Nombre de Sede Dinámico en Tarjetas de Contacto (vCard)
- **Archivo:** `webhook.php` (`enviar_contactos_asesores()`)
- **Solución:** Se eliminó el apellido fijo `"SuperFormica"` y se reemplazó por consulta dinámica `SELECT nombre_sede FROM sedes WHERE id = $id_sede LIMIT 1` (con respaldo `'STARFI CRM'`), construyendo `formatted_name` y `last_name` con la identidad real de cada sucursal.

#### `STD-03` — KPIs y CAC 100% Reales y Optimizados en Dashboard
- **Archivo:** `modules/dashboard/back_dashboard.php`
- **Solución:**
  - Se eliminó el mock `if ($avg_res == 0) $avg_res = 15;` (si no hay ventas cerradas en el rango, reporta fielmente `0 min`).
  - Se eliminó la fórmula ficticia `$total_chats * 0.10` del CAC y se reemplazó por la suma real de consumo en base de datos (`campanas_difusion_destinatarios` + mensajes facturables `es_pagado = 1` en `mensajes_y_eventos`, descontando solapamientos por `idx_meta_wamid`).
  - Se consolidaron 4 escaneos de `conversaciones` en 1 sola consulta agregada y se añadió el índice compuesto `idx_msg_pagado_ts (es_pagado, timestamp)`, reduciendo el tiempo global sobre ~19,900 conversaciones de **8.8 s a 721 ms** (y **106 ms** con filtro de fechas).

#### `STD-04` — Deprecación de Lógica Duplicada de Difusión en Directorio
- **Archivo:** `modules/directorio/back_directorio.php`
- **Solución:** Se retiraron las funciones duplicadas (`ensureBroadcastTables`, `normalizeVenezuelanPhone`, `sanitizeMetaTemplateParam`) y los 325 renglones de envío masivo heredado (`get_broadcast_config`, `create_broadcast_campaign`, `send_broadcast_item`, `finish_broadcast_campaign`), respondiendo `HTTP 410 Gone` con redirección limpia hacia `modules/campanas/campanas.php`.

---

## 4. Objetos SQL Asegurados en Base de Datos

| Objeto | Tabla | Propósito |
| :--- | :--- | :--- |
| Trigger `trg_mensajes_ultimo_msg_ai` | `mensajes_y_eventos` -> `conversaciones` | Mantiene `conversaciones.ultimo_msg_id` al instante en cada `INSERT`, permitiendo que `load_chats` sea 100% `SELECT`. |
| Índice `idx_meta_wamid` | `mensajes_y_eventos (id_mensaje_meta)` | Verificación de idempotencia O(1) en `webhook.php` y cruce sin duplicidad de costos en Dashboard. |
| Índice `idx_msg_pagado_ts` | `mensajes_y_eventos (es_pagado, timestamp)` | Acelera el cálculo real de CAC y gasto WABA en `back_dashboard.php` de >7 s a ~100–260 ms. |

---

## 5. Resultados de Pruebas Integrales en Vivo (`54 / 54 PASS`)

Pruebas ejecutadas sobre `http://localhost/starfi_crm` conectando contra MySQL (`192.168.0.80`) y Meta Graph API `v23.0`:

- **Sintaxis PHP (`php -l`)**: `0` errores o advertencias en los 18 archivos PHP modificados.
- **Seguridad HTTP (`.htaccess` & Auth)**:
  - `GET /.env`, `/logs/`, `/config/`, `/core/`, `/database/` -> **`HTTP 403 Forbidden`** (`4.6–9.7 ms`).
  - `GET /get_media.php?id=1` sin sesión -> **`HTTP 401 Unauthorized`** (`13.0 ms`).
  - `GET /simulador_whatsapp.php` sin sesión -> **`HTTP 302`** hacia `login.php`.
- **Aislamiento Multi-Sede (Anti-IDOR)**:
  - Sesión real de operador (Sede 2) intentando consultar conversación/multimedia de Sede 3 o borrar regla ajena -> **`HTTP 403 Forbidden`** en `back_bandeja.php`, `back_gestor_bots.php` y `get_media.php`.
- **Webhook WABA**:
  - Verificación de token inválido -> **`HTTP 403`**; token válido desde `.env` -> **`HTTP 200`**.
  - Reenvío de `wamid` existente -> **`HTTP 200 EVENT_RECEIVED` en `50.3 ms`**, `0` duplicados creados.
- **Los 12 Módulos Operativos (Vistas HTML `200 OK` + Backend JSON válido)**:
  1. **Centro de Mensajes (`bandeja`)**: `load_chats` (`330 ms`), filtro Ventas con `0` difusiones infiltradas, `load_messages` (`51 ms`), `load_profile` (`52 ms`) y stream SSE activo (`200 text/event-stream`).
  2. **Gestor de Bots (`gestor_bots`)**: `load_rules` y `load_contactos` (`65–100 ms`).
  3. **Directorio de Clientes (`directorio`)**: 15,813 clientes cargados en `220 ms`, perfil en `60 ms` y deprecación `410` activa.
  4. **Campañas de Difusión (`campanas`)**: `get_ecosystem_data` sobre 18 sedes en `96.7 ms` y `check_autopilot_due` en `135 ms`.
  5. **Dashboard y Reportes (`dashboard`)**: histórico global real (19,908 chats, CAC real `$34.53`) en `721 ms` y rango sin ventas (`0 min`, `$0.00`) en `106 ms`.
  6. **Configuración del Sistema (`configuracion`)**: 18 sedes y 18 líneas WABA en `31–68 ms`.
  7. **Facturación WhatsApp (`whatsapp_analytics`)**: consulta en vivo a Meta Graph `v23.0` con SSL activo (`HTTP 200`, `status=success`).
  8. **Panel de Órdenes WABA (`waba_ordenes`)**: `get_orders` en `121.5 ms`.
  9. **Buzón de Salida SMTP (`buzon_correos`)**: vista renderizada en `102.1 ms`.
  10. **Gestión de Usuarios (`gestion_usuarios`)**: 27 usuarios en `137.7 ms`.
  11. **Perfil de Empresa (`perfil_empresa`)**: expediente cargado en `52.4 ms`.
  12. **Roles y Permisos (`gestion_roles`)**: matriz de 4 roles en `157.1 ms`.

---

## 6. Checklist para Paso a Producción (`main`)

1. **Archivo `.env` en el servidor destino:** Copiar `.env.example` a `.env` (si no existe) y completar `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME`, `WEBHOOK_VERIFY_TOKEN`, `META_GLOBAL_TOKEN` y `META_API_VERSION="v23.0"`.
2. **Rotación recomendada de Token Meta:** Generar un nuevo System User Token en Meta Business Suite y reemplazar `META_GLOBAL_TOKEN` en `.env` (y en `lineas_whatsapp` si alguna sede usa token individual antiguo).
3. **Apache `mod_rewrite` y `AllowOverride All`:** Confirmar en el `httpd.conf` / VirtualHost de producción que `AllowOverride All` esté activo para `/starfi_crm` de modo que las reglas `.htaccess` apliquen igual que en el entorno verificado.
4. **Fusión controlada:** Realizar merge de `development` hacia `main` una vez aprobado por dirección técnica.
