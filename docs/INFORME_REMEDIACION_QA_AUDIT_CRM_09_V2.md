# INFORME DE REMEDIACIÓN INTEGRAL Y CERTIFICACIÓN PRE-GO — STARFI CRM (`QA-AUDIT-2026-1120-CRM-09`)

- **Auditoría Atendida:** `QA-AUDIT-2026-1120-CRM-09` (V2 — Norma `QA-STD-GLOBAL-2.1`)
- **Fecha de Remediación:** `2026-11-21`
- **Total de Hallazgos Resueltos:** `16 / 16` (`9 Críticos S0`, `4 Mayores S1`, `2 Medios S2`, `1 Menor S3`)
- **Suite Automatizada de Certificación:** `modules/pipeline/test_ia/test_sprint2_qa.php` (`100% PASS` con `ROLLBACK` transaccional)

---

## 1. Matriz de Trazabilidad de las 8 Tareas Obligatorias (`QA-BUG-CRM-01` al `QA-BUG-CRM-16`)

| Tarea | Hallazgos Cubiertos | Archivos Intervenidos | Resumen Técnico de la Solución Aplicada |
| :---: | :--- | :--- | :--- |
| **1** | `CRM-11`, `CRM-15` *(S0)* | `login.php`, `recover_password.php`, `install.php`, `register.php` | Eliminado backdoor `'1234'`, `md5()` y login sin BD; `recover_password.php` exige `recovery_verified_user_id` en `send_code`, genera OTP con `random_int(100000, 999999)`, elimina `'codigo_simulado'`, aplica rate-limit y contraseña `>= 8` caracteres; `install.php` bloquea con `HTTP 403` ante `.env` / `config/.env.local` o tabla `usuario` poblada y escapa valores `.env`; `register.php` elimina `ALTER TABLE` en caliente y cifra respuestas con `PASSWORD_BCRYPT`. |
| **2** | `CRM-12`, `CRM-16` *(S0/S1)* | `modules/perfil_empresa/back_empresa.php`, `modules/bandeja/back_bandeja.php`, `uploads/.htaccess`, `assets/docs/.htaccess`, `ver_documento.php`, `.gitignore` | Validación estricta con `finfo(FILEINFO_MIME_TYPE)`, límite `<= 5 MB` y extensión asignada exclusivamente desde mapa MIME verificado; creados `.htaccess` anti-ejecución en `uploads/` y `assets/docs/` + controlador autenticado por `id_sede` (`ver_documento.php`); actualizado `.gitignore` y ejecutado `git rm --cached` sobre los 80 archivos sensibles/binarios/volcados reales. |
| **3** | `CRM-13` *(S0)* | `core/auth.php`, `back_usuarios.php`, `back_roles.php`, `back_empresa.php`, `back_configuracion.php`, `back_bandeja.php`, `back_directorio.php`, `back_gestor_bots.php`, `back_dashboard.php`, `back_waba_billing.php`, `back_whatsapp_analytics.php` | `requirePermission('<modulo>')` activo con respuesta `HTTP 403` en todos los `back_*.php`; `getAgenteInfo()`, `requireAuth()` y `hasPermission()` exigen `u.estado = 'ACTIVO'` en tiempo real; blindados `id = 1` y `role_id = 1` (`MASTER`), prohibida asignación de `MASTER` / `MASTER CI` por no-Master y acotamiento estricto por `id_sede`. |
| **4** | `CRM-14` *(S0)* | `api_notificaciones.php`, `webhook.php`, `cron_campanas_autopilot.php`, `cron_waba_billing.php` | `api_notificaciones.php` exige `api_token` hexadecimal de 64 caracteres de sede activa en `sync_linea`, `get_info` y despacho transaccional (cero fallbacks anónimos, cero tokens hardcodeados, CORS restringido y cero `$con->error`); `webhook.php` exige `META_APP_SECRET` y firma `X-Hub-Signature-256` (`HTTP 403`); `cron_*.php` restringidos a `PHP_SAPI === 'cli'`. |
| **5** | `CRM-01` *(S0)* | `assets/vendor/fontawesome/*`, `assets/vendor/leaflet/*`, `assets/fonts/*`, `assets/css/starfi_theme.css`, todas las vistas `.php`/`.js` | **100% Cero CDN (`CHK-LAN-01`):** FontAwesome 6.4.0, Leaflet 1.9.4 y tipografía `Gotham`/`Mont` servidos localmente desde `assets/`; `ui-avatars.com` sustituido por generador local SVG `data:image/svg+xml`. |
| **6** | `CRM-02`, `CRM-03`, `CRM-04` *(S0)* | `core/crm_sprint2_helper.php`, `modules/bandeja/back_bandeja.php`, `api_notificaciones.php` | Aislamiento estricto por `id_sede` en `starfi_extract_deal_enrichment()`, `get_conversacion_deal_info()` y `$deals_by_client_map` (excluyendo `GANADO`/`PERDIDO` en hilos nuevos); transacciones `$con->begin_transaction()` + `SELECT ... FOR UPDATE` en `starfi_assign_round_robin_advisor()` y `starfi_ensure_pipeline_lead()`; protección de cotizaciones manuales en `COTIZADO` y corrección de 3 decimales en `starfi_parse_usd_number('$1.250 USD' => 1.25)`. |
| **7** | `CRM-05`, `CRM-06`, `CRM-07` *(S1)* | `core/audit.php`, `back_pipeline.php`, `back_configuracion.php`, `back_bandeja.php`, `back_usuarios.php`, `back_roles.php`, `funciones_configuracion.js`, `funciones_bandeja.js`, `webhook.php` | Creado `core/audit.php` (`registrar_auditoria()` + `logs_<modulo>.log`), `@ini_set('display_errors','0')`, migración a `mysqli_prepare()`, sanitización DOM XSS con `escapeHtml()` y banderas `.flag` con `md5($dbName)`. |
| **8** | `CRM-08`, `CRM-09`, `CRM-10` *(S2/S3)* | `modules/pipeline/modals_pipeline.php`, `modules/pipeline/pipeline.php`, `database/tools/simulador_whatsapp.php`, `core/auth.php`, `modules/pipeline/test_ia/test_sprint2_qa.php` | Subvista `modals_pipeline.php` separada, `simulador_whatsapp.php` retirado de raíz pública hacia CLI, `401 JSON` en `requireAuth()` ante `Accept: application/json` con rutas relativas, bloqueo anti-doble clic en `#btnResetSlaOverride`, `autocomplete="off"` en formularios, Gris Pizarra `#37414A` y suite `/modules/pipeline/test_ia/test_sprint2_qa.php`. |

---

## 2. Checklist Pre-Entrega Certificado (`7 / 7 CUMPLIDOS`)

- [x] `login.php` sin clave `'1234'` ni `md5()`; `recover_password.php` exige preguntas verificadas y **no expone `'codigo_simulado'`**.
- [x] `install.php` bloqueado (`HTTP 403`); `register.php` sin `ALTER TABLE` y con `password_hash()` en respuestas de seguridad.
- [x] `back_empresa.php` y `back_bandeja.php` validan `finfo` + extensión derivada del MIME (`<= 5 MB`); `uploads/.htaccess` y `assets/docs/.htaccess` activos; `uploads/*` en `.gitignore` y expedientes/`cloudflared.exe` fuera del índice Git.
- [x] `requirePermission(...)` activo en todos los `back_*.php`; `OPERADOR` bloqueado con `403` en `back_usuarios.php`; usuarios `INACTIVO` expulsados en `getAgenteInfo()`.
- [x] `api_notificaciones.php` exige `api_token` en todas sus ramas (cero tokens hardcodeados); `webhook.php` exige `X-Hub-Signature-256`; `cron_*.php` restringidos a `CLI`.
- [x] `0` referencias a CDNs externos en todo el repositorio (`CHK-LAN-01`).
- [x] `0` fuga de ventas entre Sede 1 y Sede 2, `FOR UPDATE` en Round-Robin/Kanban, montos manuales en `COTIZADO` intactos y suite `/modules/pipeline/test_ia/test_sprint2_qa.php` al **100% PASS** con `ROLLBACK`.
