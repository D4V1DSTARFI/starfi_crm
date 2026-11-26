# INFORME DE EJECUCIÓN TÉCNICA — `OT-STARFI-2026-11 - SPRINT 4` + REMEDIACIÓN `OT-QA-CRM-2026-1126-06`

- **Proyecto:** STARFI CRM (`c:/xampp/htdocs/starfi_crm`)
- **Rama de Trabajo:** `development`
- **Estándar de Calidad y Seguridad:** `QA-STD-GLOBAL-2.1` (`MANUAL_GLOBAL_QA.md` v2.1.0)
- **Objetivo Ejecutivo:** Completar las herramientas operativas de venta rápida en el chat, limpiar el repositorio principal de `starfi_crm` y certificar al `100%` el cierre de los 4 hallazgos de auditoría forense (`QA-BUG-CRM6-01` .. `QA-BUG-CRM6-04`).

---

## 1. Matriz de Subtareas Ejecutadas (`4 / 4` — `100%`)

| Código | Subtarea Oficial | Archivos Principales Intervenidos | Estado |
| :--- | :--- | :--- | :---: |
| **`[OMN-01]`** | Grabación y envío de Notas de Voz desde el navegador en la Bandeja Omnicanal usando `MediaRecorder API` | `modules/bandeja/bandeja.php`, `modules/bandeja/funciones_bandeja.js`, `modules/bandeja/back_bandeja.php`, `core/crm_sprint2_helper.php`, `get_media.php` | **COMPLETADO** |
| **`[OMN-02]`** | Sistema de Tareas, Recordatorios y Agenda Comercial por cliente con alertas en tiempo real en la barra superior | `core/services/CommercialAgendaService.php`, `api_notificaciones.php`, `core/auth.php`, `index.php`, `assets/js/starfi_topbar_agenda.js`, `modules/bandeja/bandeja.php`, `modules/bandeja/modals_bandeja.php`, `modules/bandeja/funciones_bandeja.js`, `modules/bandeja/back_bandeja.php` | **COMPLETADO** |
| **`[OMN-03]`** | Cotizador Rápido PDF en el Chat: Generar cotización membretada por sede en segundos, enviarla al WhatsApp del cliente y registrar monto en el Pipeline | `core/services/QuickQuotePdfService.php`, `modules/bandeja/bandeja.php`, `modules/bandeja/modals_bandeja.php`, `modules/bandeja/funciones_bandeja.js`, `modules/bandeja/back_bandeja.php` | **COMPLETADO** |
| **`[OMN-04]`** | Higiene de Repositorio de Producción: Eliminar o reubicar scripts temporales de prueba en la raíz de `c:/xampp/htdocs/starfi_crm/` | `.htaccess`, `.gitignore`, `core/services/.htaccess` + depuración de artefactos temporales (`scratch/`, `cloudflared.exe`, `logs/debug_phone_id.txt`) y limpieza BOM en `docs/KARDEX_CONCURRENCY_UI.md` | **COMPLETADO** |

---

## 2. Matriz de Remediación Forense `OT-QA-CRM-2026-1126-06` (`4 / 4` Cerrados)

| Código QA | Sev. | Hallazgo Detectado en `feeddc4` | Solución Quirúrgica Aplicada | Estado |
| :--- | :---: | :--- | :--- | :---: |
| **`QA-BUG-CRM6-01`** | **`S0`** | Polling cada `25 s` hacia `index.php` renovaba `$_SESSION['last_activity']` y presencia Round-Robin, respondía `302 HTML` sin sesión y exponía agenda de terceros a `ADMIN` sin permisos comerciales. | Endpoint movido de `index.php` a `api_notificaciones.php` (`RAMA 0` con `Content-Type: application/json` previo). `requireAuth($touchActivity = true)` soporta modo pasivo (`requireAuth(false)` en `poll_alerts`), conservando intacto el timeout de `1800 s` y la presencia Round-Robin real. `starfi_topbar_agenda.js` envía `Accept: application/json` + `X-Requested-With: XMLHttpRequest` y detiene/redirige ante `HTTP 401`. `getTopbarAlertsAndAgenda()` exige permiso `bandeja`, `pipeline` o `directorio` antes de mostrar tareas de otros asesores. | **CERRADO** |
| **`QA-BUG-CRM6-02`** | **`S1`** | `resolveSedeLetterhead()` consultaba `empresa_perfil.id_empresa` (inexistente) cayendo al registro `id = 1` y cruzando RIF entre sedes; `TOTAL USD` se desfasaba en saltos multipágina de `MultiCell()`. | `resolveSedeLetterhead()` inspecciona `SHOW COLUMNS FROM empresa_perfil` y filtra estrictamente por `WHERE id_sede = ?` sin cruzar jamás el RIF/logo de otra sede, dibujando una insignia corporativa vectorial limpia cuando no hay logo en disco. `renderQuotePdfBinary()` pre-calcula `estimateWrappedLines()` y alturas reales, salta de hoja antes de desbordar y estampa el cuadro `TOTAL USD` sincronizado con `Condiciones` sin desfases multipágina. | **CERRADO** |
| **`QA-BUG-CRM6-03`** | **`S1`** | Correlativo `COUNT(*) + 1` colisionaba tras bajas, lock `FOR UPDATE` nulo en `id_sede = 0` y PDFs huérfanos ante `rollback()`. | Correlativo migrado a `COALESCE(MAX(CAST(SUBSTRING_INDEX(codigo_cotizacion, '-', -1) AS UNSIGNED)), 0) + 1`, lock `FOR UPDATE` garantizado sobre `sedes` con fallback a `config_sla WHERE id_sede = 0 FOR UPDATE`, `SAVEPOINT` para transacciones anidadas y `@unlink($pdfAbsPath)` en `catch (Throwable)`. | **CERRADO** |
| **`QA-BUG-CRM6-04`** | **`S2`** | Envíos `audio/webm` y `audio/wav` no soportados por Meta Cloud API `v23.0`, falta de bloqueo UI en ventana 24h / Nota Interna, 2 inputs JS sin `autocomplete="off"` y BOM en `.md`. | `starfi_prepare_meta_compatible_voice_audio()` transcodifica `webm`/`wav` a `audio/ogg` Opus vía `proc_open([ffmpeg, ...])` si existe FFmpeg local o rechaza preventivamente en líneas con token Meta real antes de generar errores `#100`. `updateActiveWindow24hUI()` e `isNoteMode` bloquean/ocultan `#btnVoiceRecord` y `#btnQuickQuotePdf` cancelando grabaciones activas. Añadido `autocomplete="off"` en `#swal-qr-title`/`#swal-qr-msg` y eliminado BOM de `docs/KARDEX_CONCURRENCY_UI.md`. | **CERRADO** |

---

## 3. Comando de Certificación Integral (`QA-STD-GLOBAL-2.1`)
```powershell
c:\xampp\php\php.exe modules/pipeline/test_ia/test_sprint2_qa.php
```
