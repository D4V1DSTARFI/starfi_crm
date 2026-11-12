# Informe de Ejecución — `OT-STARFI-2026-11 - SPRINT 2`

**Objetivo:** Dotar a la Bandeja Omnicanal (`modules/bandeja/`) de herramientas de cierre comercial de nivel Enterprise (estilo Kommo / Respond.io) y control de tiempos de respuesta.  
**Rama Git:** `development`  
**Estado:** Completado (`4/4` subtareas implementadas y verificadas)  
**Fecha:** 2026-11-12

---

## 1. Resumen Ejecutivo

| Código | Subtarea | Estado | Módulo(s) Principal(es) |
| :--- | :--- | :---: | :--- |
| **`[COM-01]`** | Contador regresivo de Ventana Meta 24h en cabecera del chat, bloqueo automático y botón para Reactivar conversación usando Plantilla Oficial Meta | ✅ Completado | `modules/bandeja/` |
| **`[COM-02]`** | Tablero Kanban de Embudo de Ventas (`modules/pipeline/`): Columnas Drag & Drop (`Nuevo Lead`, `Cotizado`, `En Negociación`, `Pago Pendiente`, `Ganado`, `Perdido`) con monto estimado en USD | ✅ Completado | `modules/pipeline/`, `modules/bandeja/`, `core/auth.php`, `index.php` |
| **`[COM-03]`** | Enrutamiento Automático Round-Robin al transferir desde IA (`[SOLICITAR_AGENTE_HUMANO]`) entre asesores en línea de la sede | ✅ Completado | `core/crm_sprint2_helper.php`, `webhook.php`, `modules/bandeja/`, `login.php`, `logout.php` |
| **`[COM-04]`** | Semáforo SLA en vivo en la lista de conversaciones: Verde (`<5 min`), Amarillo (`5–15 min`), Rojo (`>15 min sin respuesta humana`) con alerta sonora | ✅ Completado | `modules/bandeja/` |

---

## 2. Detalle Técnico de Cambios Realizados

### 2.1. `[COM-01]` Ventana Meta 24h, Bloqueo Automático y Plantilla Oficial Meta

- **Sincronización horaria con servidor MySQL (`server_now_epoch`)**:
  - Tanto `load_chats` como `load_messages` en `modules/bandeja/back_bandeja.php` devuelven `UNIX_TIMESTAMP(NOW())` para evitar desfases si el reloj o zona horaria del navegador del asesor difiere del servidor.
  - La función `get_conversacion_window_24h()` calcula las 24 horas (86,400 segundos) desde el último mensaje con `origen = 'CLIENTE'`.
- **Contador en vivo en la cabecera (`HH:MM:SS`)**:
  - `modules/bandeja/bandeja.php` agrega el badge `#metaWindowBadge` en la cabecera del chat activo con cuatro estados visuales:
    - Verde (`.win-open`): ventana abierta (`> 2h` restantes).
    - Ámbar (`.win-warn`): menos de 2 horas restantes.
    - Rojo pulsante (`.win-crit`): menos de 30 minutos restantes.
    - Expirada (`.win-expired`): `Ventana 24h Expirada`.
- **Bloqueo automático doble (Frontend + Backend)**:
  - Al llegar a `0` segundos, `modules/bandeja/funciones_bandeja.js` muestra `#expiredWindowBanner`, deshabilita `#chatInput`, `#sendBtn` y `#btnAttach`, y presenta el botón **Reactivar con Plantilla Oficial Meta** (`#btnReactivateTemplate`).
  - Las **Notas Internas privadas** (`#btnInternalNote.note-mode`) permanecen habilitadas aun con la ventana expirada para no interrumpir la coordinación interna del equipo.
  - En `modules/bandeja/back_bandeja.php`, tanto `send_message` (cuando `is_internal !== 1`) como `upload_media` validan la ventana en servidor y rechazan cualquier envío libre expirado con `code: 'WINDOW_24H_EXPIRED'`.
- **Modal de Reactivación Oficial Meta (`v23.0`)**:
  - La acción `get_meta_templates_bandeja` consulta las plantillas aprobadas (`APPROVED`/`ACTIVE`) desde Meta Graph API `/{waba_id}/message_templates` (con caché de 5 minutos en temp y respaldo local automático desde la tabla `plantillas_whatsapp`).
  - El modal `#modalReactivateMeta` genera dinámicamente los campos `{{1}}..{{N}}` (autocompletando `{{1}}` con el nombre del cliente), muestra una vista previa en vivo estilo WhatsApp y envía la plantilla mediante `send_meta_template_reactivate` (`type: "template"`).

---

### 2.2. `[COM-02]` Tablero Kanban de Embudo de Ventas (`modules/pipeline/`) en USD

- **Nuevo módulo independiente**:
  - `modules/pipeline/index.php` y `modules/pipeline/pipeline.php`: interfaz Kanban horizontal con barra de comando, filtros por Sede (para `MASTER`), Asesor, Periodo y buscador en vivo.
  - **KPIs en USD**: Pipeline Activo (`$ USD`), Pipeline Ponderado por probabilidad, Ventas Ganadas (`$ USD`), Tasa de Cierre (`%`) y Ticket Promedio (`$ USD`).
  - **6 Columnas Drag & Drop**:
    1. `NUEVO_LEAD` (Prob. 20%)
    2. `COTIZADO` (Prob. 45%)
    3. `EN_NEGOCIACION` (Prob. 70%)
    4. `PAGO_PENDIENTE` (Prob. 90%)
    5. `GANADO` (Prob. 100% — Venta Cerrada)
    6. `PERDIDO` (Prob. 0% — con registro opcional de `motivo_perdida`)
- **Backend y Seguridad Multi-Sede (`modules/pipeline/back_pipeline.php`)**:
  - Acciones implementadas: `get_board`, `save_deal`, `move_stage`, `delete_deal`, `sync_active_chats` y `search_clients`.
  - Aislamiento estricto por sede y rol (`MASTER` global; `ADMINISTRADOR`/`ADMIN`/`GERENTE` restringidos a su `id_sede`; `OPERADOR` restringido a su sede y sus propias oportunidades), bloqueando accesos cruzados con **HTTP 403**.
- **Sincronización bidireccional con Bandeja**:
  - Mover una oportunidad a `GANADO` marca automáticamente `conversaciones.resultado_comercial = 'VENTA_CERRADA'` y `fecha_cierre_venta = NOW()`, registrando un `EVENTO_SISTEMA` en el chat.
  - Desde `bandeja.php` se agregó el acceso directo **Kanban** y el chip editable `#btnQuickDeal` (`quick_save_deal`) para actualizar etapa y monto USD sin salir del chat.
  - Desde cada tarjeta Kanban, el botón **Chat** abre directamente la conversación en `bandeja.php?conv_id=...&cliente_id=...`.
- **Permisos y Navegación**:
  - Registrado el módulo `pipeline` (**Embudo de Ventas (Kanban)**) en `index.php`, `core/auth.php` (con herencia desde `bandeja` si aún no existe regla explícita), `modules/gestion_roles/back_roles.php` y `modules/gestion_usuarios/back_usuarios.php`.

---

### 2.3. `[COM-03]` Enrutamiento Automático Round-Robin desde IA

- **Motor Round-Robin por Sede (`core/crm_sprint2_helper.php`)**:
  - Función `starfi_assign_round_robin_advisor($con, $id_sede, $id_conversacion, $id_cliente, $motivo_origen, $online_window_minutes = 5)`:
    - Selecciona asesores activos (`OPERADOR`/`ASESOR`/`AGENTE`/`VENDEDOR`) de la misma sede con `disponible_chat = 1` y `ultima_actividad` dentro de los últimos 5 minutos.
    - Prioriza a quienes están por debajo de `limite_chats_simultaneos`.
    - Ordena estrictamente por `(ultima_asignacion_rr IS NULL) DESC, ultima_asignacion_rr ASC, chats_activos ASC, id ASC` para rotación circular justa.
    - Actualiza `usuario.ultima_asignacion_rr = NOW()`, asigna `conversaciones.id_agente` en estado `ESPERA_ASIGNACION`, registra un `EVENTO_SISTEMA` de trazabilidad y crea automáticamente la tarjeta `NUEVO_LEAD` en `pipeline_oportunidades`.
    - Si no hay asesores en línea en la sede, deja la conversación en cola `ESPERA_ASIGNACION` sin pérdida del lead.
- **Integración en `webhook.php`**:
  - Conectado en ambos puntos de transferencia a humano: cuando el cliente solicita hablar con un agente (`$solicita_humano`) y cuando Gemini emite `[SOLICITAR_AGENTE_HUMANO]`, notificando al asesor asignado por Round-Robin.
- **Presencia y Disponibilidad en Tiempo Real**:
  - `login.php` marca presencia inmediata al iniciar sesión (`starfi_touch_agent_presence(..., true)`).
  - `logout.php` limpia `ultima_actividad = NULL` al cerrar sesión para sacar al asesor inmediatamente del pool.
  - `core/auth.php` y `modules/bandeja/sse_updates.php` mantienen viva la presencia mientras el usuario opera o tiene la Bandeja abierta.
  - `modules/bandeja/bandeja.php` y `funciones_bandeja.js` añaden un heartbeat cada 45 segundos y el botón **En línea / Pausado** (`toggle_availability`), además de mostrar el indicador `🟢 En línea` en el selector de reasignación (`get_agents`).

---

### 2.4. `[COM-04]` Semáforo SLA en Vivo con Alerta Sonora

- **Cálculo exacto de espera humana (`modules/bandeja/back_bandeja.php`)**:
  - Para cada conversación abierta en `load_chats`, se calcula `sla_wait_since_epoch` a partir del primer mensaje `CLIENTE` posterior al último mensaje humano `AGENTE` (los mensajes automáticos `BOT` o `EVENTO_SISTEMA` no reinician el SLA).
  - Si un humano ya respondió al último mensaje del cliente, marca `sla_human_replied = 1`.
- **Semáforo en tiempo real y Alerta Sonora (`modules/bandeja/bandeja.php`, `funciones_bandeja.js`)**:
  - Actualizado cada segundo en vivo en cada tarjeta de conversación:
    - 🟢 **Verde (`< 5 min`)**
    - 🟡 **Amarillo (`5–15 min`)** con borde izquierdo ámbar
    - 🔴 **Rojo (`> 15 min sin respuesta humana`)** con pulso visual rojo
    - `✓ Al día` cuando ya fue respondida por un asesor humano.
  - Barra resumen superior `#slaSummaryStrip` con conteos en vivo y filtro rápido hacia la pestaña **`SLA >15m`** (`filter = 'sla-critico'`).
  - Alerta sonora sintetizada mediante **Web Audio API** (`playSlaAlertChime()`) + notificación toast cuando una conversación cruza los 15 minutos sin respuesta humana, con botón `#btnSlaSoundToggle` persistido en `localStorage`.

---

## 3. Cambios de Esquema en Base de Datos (Migración Idempotente)

Ejecutados automáticamente por `starfi_ensure_sprint2_schema($con)` en `core/crm_sprint2_helper.php` (controlado con bandera `starfi_sprint2_schema_v2.flag`):

1. **Tabla `usuario`**:
   - `ultima_actividad DATETIME NULL`
   - `disponible_chat TINYINT(1) NOT NULL DEFAULT 1`
   - `ultima_asignacion_rr DATETIME NULL`
   - Índice `idx_sede_online_rr (id_sede, estado, disponible_chat, ultima_actividad, ultima_asignacion_rr)`
2. **Tabla `mensajes_y_eventos`**:
   - Índice `idx_conv_origen_ts (id_conversacion, origen, timestamp)`
3. **Nueva tabla `pipeline_oportunidades`**:
   - Campos: `id`, `id_sede`, `id_cliente`, `id_conversacion`, `id_agente`, `titulo`, `etapa` (`ENUM('NUEVO_LEAD','COTIZADO','EN_NEGOCIACION','PAGO_PENDIENTE','GANADO','PERDIDO')`), `monto_estimado_usd DECIMAL(12,2)`, `probabilidad`, `notas`, `motivo_perdida`, `orden_kanban`, `fecha_creacion`, `fecha_actualizacion`, `fecha_cierre`.
   - Índices: `idx_sede_etapa`, `idx_conv`, `idx_cliente`, `idx_agente`.

---

## 4. Inventario de Archivos Creados y Modificados

### Archivos Nuevos
- `core/crm_sprint2_helper.php`
- `modules/pipeline/index.php`
- `modules/pipeline/pipeline.php`
- `modules/pipeline/back_pipeline.php`
- `modules/pipeline/funciones_pipeline.js`
- `docs/INFORME_EJECUCION_OT_STARFI_2026_11_SPRINT2.md`

### Archivos Modificados
- `core/auth.php`
- `login.php`
- `logout.php`
- `index.php`
- `webhook.php`
- `modules/bandeja/bandeja.php`
- `modules/bandeja/back_bandeja.php`
- `modules/bandeja/funciones_bandeja.js`
- `modules/bandeja/sse_updates.php`
- `modules/gestion_roles/back_roles.php`
- `modules/gestion_usuarios/back_usuarios.php`

---

## 5. Verificación y Pruebas Ejecutadas

- **Lint de sintaxis PHP (`php -l`)**: `14/14` archivos PHP verificados sin errores de sintaxis.
- **Suite de Integración E2E contra Apache (`localhost`) y MySQL (`192.168.0.80`)**: **`25 PASS / 0 FAIL`**, cubriendo:
  - Migración idempotente de tablas e índices.
  - Rotación Round-Robin entre 2 asesores en línea, exclusión de desconectados (`>5 min`) y encolado cuando hay `0` asesores disponibles.
  - Ventana Meta 24h abierta vs. expirada, bloqueo `WINDOW_24H_EXPIRED` en texto libre, continuidad de Notas Internas privadas y catálogo de Plantillas Oficiales Meta.
  - Render HTTP 200 de `bandeja.php` y `pipeline.php`, creación de oportunidad en USD, movimiento Drag & Drop por todas las etapas hasta `GANADO`, recálculo de KPIs USD, sincronización `VENTA_CERRADA` y bloqueo **HTTP 403** Anti-IDOR entre sedes.
  - Clasificación SLA (`<5m` Verde, `5–15m` Amarillo ignorando respuestas del `BOT`, `>15m` Rojo, limpieza a `Al día` tras respuesta de `AGENTE` y filtro exclusivo `sla-critico`).

