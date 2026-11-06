# Orden de Trabajo Ejecutiva: `OT-STARFI-2026-11 - SPRINT 1`
## Blindaje y Optimización del Módulo Financiero WABA

**Fecha de Ejecución:** 06 de Noviembre de 2026  
**Estado:** Completado (5/5 Subtareas — 100%)

---

## 1. Objetivo General y Regla de Oro de Privacidad

Blindar integralmente el ecosistema de facturación, analítica y órdenes de cobro de WhatsApp Business API (WABA) en **STARFI CRM**, garantizando trazabilidad contable, conciliación híbrida multi-fuente y separación estricta de visibilidad financiera según el rol del usuario.

> [!IMPORTANT]
> **Regla de Oro de Privacidad (`MASTER` vs Sede):**
> Las sedes únicamente visualizan su **Precio Final Unitario** y **Monto Total** (con la comisión administrativa ya integrada proporcionalmente). Exclusivamente el rol `MASTER` puede visualizar el **Costo Base Meta**, el **Margen Administrativo CRM** y el **Total a Cobrar**.

---

## 2. Archivos Intervenidos

| Archivo | Rol en la Arquitectura |
| :--- | :--- |
| [`modules/dashboard/back_waba_billing.php`](file:///c:/xampp/htdocs/starfi_crm/modules/dashboard/back_waba_billing.php) | Controlador backend, auto-migración de esquema BD, motor unificado de conciliación híbrida (`conciliar_consumo_sede`, `emitir_orden_sede`), API de KPIs y flujo de pagos/auditoría. |
| [`modules/dashboard/waba_ordenes.php`](file:///c:/xampp/htdocs/starfi_crm/modules/dashboard/waba_ordenes.php) | Panel Administrativo y de Sede con las 4 tarjetas KPI financieras, filtro por estado, blindaje de columnas por rol y modales de reporte y verificación de pagos. |
| [`modules/dashboard/imprimir_orden_waba.php`](file:///c:/xampp/htdocs/starfi_crm/modules/dashboard/imprimir_orden_waba.php) | Generador de Aviso de Cobro / Recibo de Pago en PDF (Dompdf) con blindaje por sede, precio unitario con comisión integrada y bloque de soporte de pago con comprobante. |
| [`modules/dashboard/whatsapp_analytics.php`](file:///c:/xampp/htdocs/starfi_crm/modules/dashboard/whatsapp_analytics.php) | Dashboard de rendimiento de la API con carga automática del consolidado, navegación directa a órdenes y assets locales. |
| [`modules/dashboard/back_whatsapp_analytics.php`](file:///c:/xampp/htdocs/starfi_crm/modules/dashboard/back_whatsapp_analytics.php) | Backend de analítica enriquecido con el cruce híbrido de campañas de difusión y eventos de mensajería local. |
| [`cron_waba_billing.php`](file:///c:/xampp/htdocs/starfi_crm/cron_waba_billing.php) | Demonio programado (Task Scheduler) que consume directamente las funciones centralizadas de `back_waba_billing.php` sin duplicación de código. |

---

## 3. Detalle Técnico por Subtarea

### `[FAC-01]` Dashboard Financiero en `waba_ordenes.php` + Filtro por Estado
- **4 Tarjetas KPI Dinámicas**:
  1. **Total Facturado (`$kpiTotalFacturado`)**: Suma de `monto_total` de las órdenes activas del filtro (excluyendo órdenes en estado `FUSIONADO` para evitar duplicidad contable).
  2. **Total Cobrado (`$kpiTotalCobrado`)**: Suma de `monto_total` de las órdenes verificadas en estado `PAGADO`.
  3. **Por Cobrar (`$kpiPorCobrar`)**: Suma de `monto_total` de las órdenes en estado `PENDIENTE` y `EN_REVISION`, incluyendo un sub-indicador específico del monto **En revisión**.
  4. **Margen Neto CRM (`$kpiMargenNeto`)**: *(Exclusivo para rol `MASTER` tanto en renderizado HTML como en respuesta JSON)* Suma de `margen_ganancia` total y sub-indicador de margen efectivamente cobrado.
- **Filtro por Estado**:
  - Se incorporó el selector `#filter_estado` (`Todos los Estados`, `PENDIENTE`, `PAGADA (PEND. REVISIÓN)`, `PAGADO (VERIFICADO)`, `FUSIONADO`) sincronizado vía AJAX con `get_orders`.

---

### `[FAC-02]` Flujo de Registro, Reporte y Verificación de Pagos + Recibo PDF
- **Auto-migración de Base de Datos (`ensureWabaBillingSchema()`)**:
  - Ajuste automático de la columna `estado` en `waba_ordenes_cobro` a `VARCHAR(30)` para admitir el estado intermedio `'EN_REVISION'`.
  - Creación automática de las columnas:
    - `metodo_pago` (`VARCHAR(60)`)
    - `referencia_pago` (`VARCHAR(120)`)
    - `fecha_pago` (`DATETIME`)
    - `notas_pago` (`TEXT`)
    - `comprobante_path` (`VARCHAR(255)`)
    - `reportado_por` (`INT`), `verificado_por` (`INT`), `fecha_verificacion` (`DATETIME`)
- **Flujo de Dos Pasos (Reporte -> Verificación `MASTER`)**:
  ```mermaid
  stateDiagram-v2
      [*] --> PENDIENTE: Emisión de Orden
      PENDIENTE --> EN_REVISION: Reportar Pago (Método, Ref/TxID, Fecha, Notas, Comprobante)
      EN_REVISION --> PAGADO: MASTER Verifica Ingreso y Aprueba
      EN_REVISION --> PENDIENTE: MASTER Rechaza Reporte
      PAGADO --> EN_REVISION: MASTER Revierte a Revisión
  ```
- **Modal de Registro / Reporte de Pago (`#modalPagoOrden`)**:
  - Soporta **Método de Pago** (`Binance`, `Zelle`, `Pago Móvil`, `Transferencia`, `Efectivo`), **Referencia / TxID**, **Fecha del Pago**, **Notas / Observaciones** y carga de archivo **Comprobante Adjunto** (`JPG`, `PNG`, `WEBP`, `PDF`) almacenado en `assets/uploads/comprobantes_waba/`.
- **Modal de Soporte y Verificación (`#modalVerPago`)**:
  - Muestra los metadatos del pago y la vista previa interactiva del comprobante adjunto.
  - Exclusivamente el rol `MASTER` dispone de los controles de auditoría para **Confirmar Ingreso y Marcar `PAGADO`**, **Pasar a `PENDIENTE POR REVISIÓN`** o **Rechazar y Devolver a `PENDIENTE`**.
- **Impresión en Recibo PDF (`imprimir_orden_waba.php`)**:
  - Adapta el encabezado dinámicamente (*"Recibo de Pago WABA"* cuando está `PAGADO`) e imprime el bloque de **Constancia / Reporte de Pago** con método, referencia, fecha, observaciones e imagen en miniatura (base64) del comprobante adjunto.

---

### `[FAC-03]` Motor Unificado de Conciliación Híbrida por Sede
- **Centralización**:
  - Se unificó toda la lógica en `conciliar_consumo_sede()` y `emitir_orden_sede()` dentro de `back_waba_billing.php`.
  - `cron_waba_billing.php` importa `back_waba_billing.php` mediante la constante `WABA_BILLING_LIB_ONLY`, eliminando la duplicación de código previa.
- **Cruce Híbrido de 3 Fuentes + Tarifa**:
  1. **Meta Graph API por Línea (`lineas_whatsapp`)**: Consulta `pricing_analytics` (con dimensión `PRICING_CATEGORY,PHONE` y fallback automático) y `analytics`, aislando los números telefónicos de la sede cuando varias sucursales comparten un mismo `id_negocio` (`WABA ID`).
  2. **Campañas de Difusión (`campanas_difusion_destinatarios` + `campanas_difusion`)**: Contabiliza destinatarios enviados/entregados/leídos/respondidos de la sede en el periodo y su costo asociado por categoría.
  3. **Tráfico Conversacional y Transaccional (`mensajes_y_eventos`)**: Contabiliza mensajes salientes de bandeja, bots y API transaccional, excluyendo por `id_mensaje_meta` (`wamid`) los mensajes ya contados en campañas con colación explícita (`WHERE d2.id_mensaje_meta COLLATE utf8mb4_unicode_ci = m.id_mensaje_meta COLLATE utf8mb4_unicode_ci`) para evitar colisiones entre `utf8mb4_general_ci` y `utf8mb4_unicode_ci` y prevenir doble cobro.
  4. **Tarifario por Sede (`waba_tarifas_sede`)**: Aplica la regla configurada (`PORCENTAJE`, `FIJA` o `PORCENTAJE_VOLUMEN`) para determinar `costo_meta`, `margen_ganancia` y `monto_total`.

---

### `[FAC-04]` Blindaje de Privacidad de Precios (`MASTER` vs Sede)
- **Control de Acceso por Sede**:
  - Usuarios no-`MASTER` tienen su filtro de sede bloqueado en su propia sucursal (`$agente['id_sede']`) tanto en el frontend como en las consultas SQL de `back_waba_billing.php`, `back_whatsapp_analytics.php` e `imprimir_orden_waba.php`.
- **Ocultamiento de Costos Base y Márgenes**:
  - En `waba_ordenes.php`: Los usuarios de sede no ven la tarjeta de *Margen Neto CRM* ni las columnas *Costo Base Meta* y *Margen CRM*.
  - En `get_order_details` y `imprimir_orden_waba.php`: Se excluye la fila de categoría `'ADMIN'` (*Margen Administrativo CRM*) y se aplica el factor de distribución proporcional:
    $$\text{Factor} = \frac{\text{Costo Base Meta} + \text{Margen CRM}}{\text{Costo Base Meta}}$$
    De modo que cada categoría muestra directamente su **Precio Unitario Final** y **Subtotal Final** con comisión integrada.
  - En `imprimir_orden_waba.php`: El bloque *"Auditoría Financiera Interna (Costo Base Meta / Margen CRM / Total a Cobrar)"* solo se renderiza para el rol `MASTER` (y puede omitirse pasando `?modo=cliente`).

---

### `[FAC-05]` Optimización UX en `whatsapp_analytics.php` y Migración a Assets Locales
- **Carga Automática del Consolidado**:
  - Al ingresar a `whatsapp_analytics.php`, se ejecuta automáticamente `loadAnalytics()` cargando el consolidado de **"Todas las plantillas"** sin requerir selección manual previa.
  - Al cambiar de sede o de plantilla, los datos se refrescan de forma reactiva.
- **Navegación Directa Bidireccional**:
  - Botón **Órdenes de Cobro** en la cabecera de `whatsapp_analytics.php` hacia `waba_ordenes.php`.
  - Botón **Estadísticas WABA** en la cabecera de `waba_ordenes.php` hacia `whatsapp_analytics.php`.
- **Migración de CDNs a Assets Locales**:
  - `../../assets/js/chart.umd.min.js`
  - `../../assets/js/jquery-3.7.1.min.js`
  - `../../assets/js/bootstrap.bundle.min.js`
  - `../../assets/js/sweetalert2.all.min.js`

