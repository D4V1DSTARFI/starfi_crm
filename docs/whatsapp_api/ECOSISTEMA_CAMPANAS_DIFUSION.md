# Ecosistema de Campañas de Difusión Masiva (WABA v23.0) — STARFI CRM

## 1. Descripción General
El módulo **Campañas de Difusión Masiva** (`modules/campanas/`) permite gestionar, cotizar, programar y despachar difusiones de WhatsApp Business API (Meta Cloud API v23.0) por sede emisora o de forma global multi-sede (para usuarios Master/Administradores), con trazabilidad completa del embudo de conversión en tiempo real (**Enviados, Entregados, Leídos, Respondidos y Fallidos**) e integración directa con la **Bandeja Omnicanal** (`modules/bandeja/`).

---

## 2. Arquitectura de Archivos
- **Vista Principal (3 Paneles)**: `modules/campanas/campanas.php`
  - **Panel Izquierdo**: Wizard de 3 pasos (`1. Mensaje & Flyer`, `2. Audiencia`, `3. Plan & Lanzar`).
  - **Panel Central**: Simulador de WhatsApp en tiempo real (vista previa dinámica de cabecera, cuerpo con variables, pie y botones + visualización en vivo destinatario por destinatario durante el envío).
  - **Panel Derecho**: Historial paginado de campañas con filtros por estado, acciones rápidas con íconos/tooltips y acceso al modal **Embudo de Conversión & Auditoría 360°**.
- **Controlador Backend**: `modules/campanas/back_campanas.php`
  - Auto-migración de tablas y columnas (`ensureCampaignTables()`).
  - Sincronización de plantillas aprobadas desde Meta Graph API (`get_sede_templates`).
  - Cálculo de tarifas por sede integrando automáticamente la comisión configurada en `waba_tarifas_sede` (`calcularPrecioFinalUnitarioSede()`).
  - Encolado por tandas diarias (`create_campaign_with_queue`) y despacho con subida automática de imagen/flyer a Meta Media API (`dispatch_queue_item`).
- **Lógica Frontend**: `modules/campanas/funciones_campanas.js`
- **Piloto Automático (Cron / Demonio)**: `cron_campanas_autopilot.php`
- **Receptor de Estados y Respuestas Meta**: `webhook.php`
- **Integración con Bandeja Omnicanal**: `modules/bandeja/back_bandeja.php` y `modules/bandeja/funciones_bandeja.js`

---

## 3. Funcionalidades Principales

### 3.1. Bloqueo Inicial por Sede y Control de Acceso
- **Usuarios Master / Globales**: Al ingresar al módulo, ningún número está preseleccionado (`id_sede = 0`) y el área de trabajo permanece bloqueada con un overlay hasta que se seleccione la **Sede Emisora**. Al cambiar de sede, se muestra un spinner de sincronización.
- **Gerentes de Sede**: Quedan vinculados exclusivamente a su sede asignada, visualizando únicamente su línea, su directorio, sus campañas y el precio final por mensaje (con la comisión ya integrada).

### 3.2. Formulario Dinámico por Especificación de Plantilla Meta (Paso 1)
Al seleccionar cualquier plantilla en **Plantilla Oficial Meta** (`#campTemplate`):
1. Se inspeccionan sus componentes reales (`HEADER`, `BODY`, `FOOTER`, `BUTTONS`) y su categoría (`MARKETING` o `UTILITY`).
2. Se generan dinámicamente los campos para cada variable `{{1}}..{{N}}` del cuerpo (o un aviso de texto fijo si la plantilla no usa variables).
3. Cada variable permite alternar entre:
   - **Nombre Cliente** (`@NOMBRE_CLIENTE`, resuelto automáticamente por destinatario al despachar).
   - **Sede** (inserta el nombre de la sede emisora).
   - **Texto libre** (con botón **Usar ejemplo** para autocompletar con los ejemplos registrados en Meta).
4. El cargador de **Imagen / Flyer de Cabecera** se muestra únicamente si la plantilla tiene cabecera `IMAGE`; si es `TEXT` con variable muestra el campo de texto de cabecera, y si es `NONE` se oculta.

### 3.3. Audiencia, Escudo Anti-Fatiga y Difusión Global con Cuota por Sede (Paso 2 y Paso 3)
- **Escudo Anti-Fatiga Meta 24h**: Omite automáticamente contactos que ya recibieron una campaña en las últimas 24 horas y aún no han respondido, previniendo el error `131049` de Meta.
- **Difusión Global Master — Cuota por Sede**: Los usuarios Master pueden lanzar una difusión desde una línea seleccionada tomando:
  - **Solo Sede Activa**
  - **Cartera Completa (Global)** (todas las sedes sin importar la sede)
  - **Cuota exacta por cada sede** (`50/sede`, `100/sede`, `250/sede`, `500/sede` o cualquier cantidad personalizada `/Sede`), tanto desde el Paso 2 como desde el Paso 3, con desglose en vivo de cuántos contactos aporta cada sede.

### 3.4. Tarifas por Sede, Costo Estimado vs. Costo Real y Tandas Diarias
- **Tarifas**: Se toma el costo base (`MARKETING` o `UTILITY`) más la comisión configurada para la sede en `waba_tarifas_sede`.
- **Costo Estimado (`Est.`) vs. Costo Real (`Real`)**:
  - `Est.`: Proyección sobre el total de destinatarios encolados.
  - `Real`: Suma únicamente los mensajes efectivamente enviados/entregados y **descuenta los mensajes fallidos** (costo `$0.00` en fallos de Meta).
- **Límite y Ritmo Diario**: Configurable por sede (`limite_diario_mensajes` y `cadencia_ms`) para dividir campañas grandes en múltiples tandas diarias automáticas.

### 3.5. Embudo de Conversión, Webhook y Redirección Directa al Chat
- **Webhook (`webhook.php`)**: Actualiza en tiempo real los estados `sent` (`ENVIADO`), `delivered` (`ENTREGADO`), `read` (`LEIDO`), `failed` (`FALLIDO` con detalle del código Meta) y marca `respondido = 1` + `respuesta_texto` cuando el cliente responde.
- **Bandeja Omnicanal**: Cada mensaje de campaña crea o actualiza la conversación del cliente en `conversaciones` y registra el texto completo renderizado en `mensajes_y_eventos`. El botón **`Chat`** dentro del modal de auditoría redirige a `bandeja.php` y abre automáticamente la conversación del destinatario.

---

## 4. Esquema de Base de Datos (Auto-Migrado)
- `campanas_difusion`: Cabecera de campaña (`id_sede`, `template_name`, `template_language`, `template_category`, `header_format`, `template_params_json`, `header_imagen_path`, `header_media_id`, `costo_unitario_usd`, `costo_estimado_usd`, `costo_real_usd`, `cuota_diaria_programada`, `total_tandas`, `piloto_automatico`, `hora_programada`, `estado`, métricas consolidadas).
- `campanas_difusion_destinatarios`: Cola e historial por contacto (`id_campana`, `id_sede`, `id_cliente`, `id_conversacion`, `nombre`, `telefono`, `numero_tanda`, `fecha_programada`, `estado_envio`, `id_mensaje_meta`, `respondido`, `respuesta_texto`, `costo_usd`, `error_detalle`, timestamps de envío/entrega/lectura/respuesta).
- `campanas_cuotas_sede`: Parámetros de protección anti-bloqueo por sede (`id_sede`, `limite_diario_mensajes`, `cadencia_ms`).
