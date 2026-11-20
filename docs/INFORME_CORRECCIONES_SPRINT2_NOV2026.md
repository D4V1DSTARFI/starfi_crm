# Informe de Correcciones y Mejoras — Post-Sprint 2 (`Noviembre 2026`)

**Proyecto:** STARFI CRM  
**Rama Git:** `development`  
**Fecha:** 2026-11-20  
**Estado:** Completado y verificado en base de datos y sintaxis PHP (`0` errores)

---

## 1. Resumen Ejecutivo de Correcciones

| # | Corrección / Mejora Solicitada | Qué ocurría antes | Cómo quedó ahora |
| :---: | :--- | :--- | :--- |
| **1** | **Auto-detección del Monto Estimado (USD) desde los mensajes** | Las oportunidades importadas o sincronizadas aparecían con monto fijo `$0.00 USD`, obligando a llenar a mano un dato que ya venía en el mensaje de venta/cotización. | Se extrae automáticamente el monto USD desde las plantillas transaccionales (`Params[2]`, ej. `"12.30 + IVA"` → `$12.30`) o cotizaciones en USD del chat, junto con el correlativo (`NE_...`), notas y asesor responsable. Se actualizaron retroactivamente **282 oportunidades** existentes. |
| **2** | **Módulo de Configuración Dinámica de Tiempos SLA** | Los tiempos del Semáforo SLA estaban fijos en código (`<5m` Verde, `5-15m` Amarillo, `>15m` Rojo). | Se creó la pestaña **`⏱️ Tiempos SLA`** en `Configuración del Sistema` (con soporte Global y por Sede) **más** un botón rápido **`⚙️`** en la propia barra `SLA:` de la Bandeja (exclusivo para Master/Admin) que actualiza etiquetas, colores, SQL y alerta sonora en vivo. |
| **3** | **Enlace de Colores entre Kanban y Conversaciones + Etiqueta Venta** | En Bandeja el chip de etapa era siempre azul fijo, el SLA pintaba un borde rojo/amarillo confuso a la izquierda, y `send_confirmacion_compra` salía como `DIFUSIÓN`. | Se unificó la paleta oficial de 6 colores entre las columnas/tarjetas del **Tablero Kanban** y las conversaciones de **Bandeja** (borde izquierdo + pastilla en lista + chip en cabecera), dejando al SLA solo en su pastilla y clasificando `send_confirmacion_compra` como **`🛍️ VENTA`**. |
| **4** | **Corrección de Iconografía en Bandeja Omnicanal** | Algunos íconos de botones y cabecera no se visualizaban por referencia rota a `assets/css/all.min.css` e íconos no incluidos. | Se corrigió la carga de **FontAwesome 6.4.0** y **Bootstrap Icons local**, dejando visibles todos los íconos de cabecera, herramientas, sonido SLA y acceso a Kanban. |

---

## 2. Detalle Técnico por Corrección

### 2.1. Extracción Inteligente y Sincronización de Monto Estimado USD (`[COM-02]`)

- **Motor de parseo monetario (`starfi_parse_usd_number` en `core/crm_sprint2_helper.php`)**:
  - Convierte con precisión formatos mixtos como `"12.30 + IVA"`, `"906.50 + IVA"`, `"1,019.30 + IVA"`, `"1.019,30 USD"` o `"$ 236.40"` a `DECIMAL(12,2)` en USD.
  - Descarta automáticamente montos expresados en Bolívares (`Bs`, `VES`, `Bolívares`) sin referencia a dólares, evitando falsos positivos.
- **Enriquecimiento en dos niveles (`starfi_extract_deal_enrichment`)**:
  1. **Prioridad 1 — Mensajes `API_TRANSACCIONAL` (ERP / Administrativo):** consulta indexada por `idx_conv_origen_ts` sobre las conversaciones del cliente (excluyendo campañas de difusión `starfi_difusion_directa`, encuestas CSAT y notificaciones internas). Extrae:
     - Monto USD (`Params[2]` o `monto total:`)
     - Correlativo del documento (`Params[5]`, ej. `NE_117500`)
     - Vendedor ERP (`Params[3]`) y vinculación automática con `usuario.id` activo de la sede si coincide el nombre
     - Fecha del documento (`Params[1]`)
  2. **Prioridad 2 — Cotización en conversación (`AGENTE` / `BOT`):** si no existe plantilla transaccional, detecta montos cotizados en USD dentro del hilo activo.
- **Sincronización en vivo y retroactiva (`starfi_backfill_zero_deals`)**:
  - Conectado en `api_notificaciones.php` (al entrar nuevas ventas/cotizaciones), `modules/pipeline/back_pipeline.php` (`get_board` con control de caché de 60s y `sync_active_chats`) y `modules/bandeja/back_bandeja.php` (`get_conversacion_deal_info`).
  - Respeta intacta cualquier oportunidad cuyo monto ya haya sido editado manualmente (`monto_estimado_usd > 0`).

---

### 2.2. Configuración Dinámica de Tiempos SLA y Ventana Round-Robin (`[COM-04]` / `[COM-03]`)

- **Nueva tabla `config_sla` (creación automática e idempotente en `core/crm_sprint2_helper.php`)**:
  - `id_sede INT PRIMARY KEY` (`0` = Regla Global para todo el CRM; `>0` = Regla personalizada para una Sede específica).
  - `verde_max_min INT DEFAULT 5` (tiempo máximo en 🟢 Verde).
  - `amarillo_max_min INT DEFAULT 15` (límite para pasar a 🔴 Rojo Crítico y disparar alerta sonora; el tramo intermedio es 🟡 Amarillo).
  - `round_robin_online_min INT DEFAULT 5` (minutos máximos de actividad reciente para considerar en línea a un asesor en Round-Robin y reasignación).
  - `alerta_sonora_activa TINYINT(1) DEFAULT 1`.
- **Pestaña completa en Configuración del Sistema (`modules/configuracion/`)**:
  - Nueva pestaña **`⏱️ Tiempos SLA`** en `configuracion.php` (soportando enlace directo `?tab=sla`).
  - Selector de ámbito (**Regla Global** para `MASTER` o **por Sede**), vista previa interactiva en vivo de la barra `SLA:`, formulario con validaciones (`amarillo_max_min > verde_max_min`), botón para restaurar una sede a la Regla Global y tabla resumen clicable de todas las reglas activas.
- **Acceso rápido `⚙️` desde Bandeja Omnicanal (`modules/bandeja/`)**:
  - Botón de engranaje `#btnQuickSlaConfig` integrado en `#slaSummaryStrip` (visible solo para `MASTER`, `ADMINISTRADOR` y `GERENTE`; oculto y bloqueado con **HTTP 403** para operadores).
  - Al guardar en `#modalQuickSlaConfig` (`action: 'save_sla_quick'`), actualiza sin recargar la página las etiquetas `<Xm`, `X-Ym`, `>Ym`, la pestaña `SLA >Ym`, el filtro SQL `INTERVAL X MINUTE` en `back_bandeja.php` y el cálculo segundo a segundo en `funciones_bandeja.js`.

---

### 2.3. Unificación de Colores entre Kanban y Conversaciones + Clasificación de Ventas

- **Paleta oficial única por etapa comercial**:

| Etapa (`etapa`) | Color Hex | Fondo Pastilla | Texto Pastilla | Aplicado en |
| :--- | :---: | :---: | :---: | :--- |
| **`NUEVO_LEAD`** *(Nuevo Lead)* | `#3B82F6` | `#EFF6FF` | `#1D4ED8` | Columna/Tarjeta Kanban + Borde izquierdo chat + Pastilla lista + Chip cabecera |
| **`COTIZADO`** *(Cotizado)* | `#8B5CF6` | `#F5F3FF` | `#6D28D9` | Columna/Tarjeta Kanban + Borde izquierdo chat + Pastilla lista + Chip cabecera |
| **`EN_NEGOCIACION`** *(En Negociación)* | `#F59E0B` | `#FFFBEB` | `#B45309` | Columna/Tarjeta Kanban + Borde izquierdo chat + Pastilla lista + Chip cabecera |
| **`PAGO_PENDIENTE`** *(Pago Pendiente)* | `#E85B14` | `#FFF7ED` | `#C2410C` | Columna/Tarjeta Kanban + Borde izquierdo chat + Pastilla lista + Chip cabecera |
| **`GANADO`** *(Ganado)* | `#10B981` | `#ECFDF5` | `#047857` | Columna/Tarjeta Kanban + Borde izquierdo chat + Pastilla lista + Chip cabecera |
| **`PERDIDO`** *(Perdido)* | `#EF4444` | `#FEF2F2` | `#DC2626` | Columna/Tarjeta Kanban + Borde izquierdo chat + Pastilla lista + Chip cabecera |

- **Separación visual limpia con el SLA**:
  - Se retiraron las clases `.sla-item-yellow` y `.sla-item-red` sobre el borde izquierdo de `.chat-item` (y la superposición `::after` naranja al seleccionar el chat), de modo que el **borde lateral izquierdo representa exclusivamente la etapa del Embudo Kanban**, mientras el Semáforo SLA se muestra de forma clara en su propia pastilla (`🟢 / 🟡 / 🔴`).
- **Vinculación por conversación y respaldo por cliente (`back_bandeja.php`)**:
  - `load_chats` busca la oportunidad tanto por `id_conversacion` como por `id_cliente`, garantizando que si el cliente escribe en un nuevo hilo conserve su etapa y color del Kanban.
- **Corrección de bandera `VENTA` vs `DIFUSIÓN`**:
  - Las plantillas transaccionales `send_confirmacion_compra...` ahora se clasifican siempre como **`🛍️ VENTA`** (y nunca como `📢 DIFUSIÓN`), aun cuando Meta las facture bajo categoría `MARKETING`.

---

## 3. Inventario de Archivos Modificados en esta Ronda

1. `core/crm_sprint2_helper.php`
2. `api_notificaciones.php`
3. `modules/bandeja/bandeja.php`
4. `modules/bandeja/back_bandeja.php`
5. `modules/bandeja/funciones_bandeja.js`
6. `modules/pipeline/pipeline.php`
7. `modules/pipeline/back_pipeline.php`
8. `modules/configuracion/configuracion.php`
9. `modules/configuracion/back_configuracion.php`
10. `modules/configuracion/funciones_configuracion.js`
11. `docs/INFORME_CORRECCIONES_SPRINT2_NOV2026.md` *(este informe)*

---

## 4. Guía Rápida de Verificación en Servidor / Navegador

1. **Refrescar caché del navegador (`Ctrl + F5`)** en `modules/bandeja/bandeja.php` y `modules/pipeline/pipeline.php`.
2. **Verificar Montos USD**: abrir `Embudo de Ventas` y comprobar que las tarjetas muestran su monto real en USD, correlativo `NE_...` y notas del mensaje (ej. Oportunidad `#247` con `$12.30 USD`).
3. **Verificar Tiempos SLA**: hacer clic en el ícono **`⚙️`** junto a `SLA:` en la Bandeja o entrar a `Configuración → pestaña Tiempos SLA`, cambiar los minutos de prueba y verificar que la barra superior y la pestaña `SLA >Xm` cambian al instante.
4. **Verificar Enlace de Colores**: arrastrar una tarjeta en el Kanban a otra columna (ej. `Cotizado` morado o `En Negociación` ámbar) y comprobar en la Bandeja que tanto el borde izquierdo del chat como su pastilla de monto y el chip superior toman ese mismo color.
