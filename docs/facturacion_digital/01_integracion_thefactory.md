---
title: "Documentación: Integración API TheFactory HKA (STARFI 2.0)"
author: "STARFI 2.0 Core Team"
date: "Marzo 2026"
---

# 📚 Documentación Técnica: Facturación Digital (TheFactory HKA)

Esta documentación sirve como referencia arquitectónica y manual de mantenimiento para el módulo de facturación en la nube integrado en **STARFI 2.0**.

## 1. Arquitectura del Servicio (API-As-A-Service)
El núcleo transaccional reside en la clase `FacturacionDigitalAPI` (`php/servicios/FacturacionDigitalAPI.php`).
Este componente trabaja como un **intermediario dinámico** aislando la red externa de la Base de Datos nativa.

* **Cero Hardcoding:** Todas las credenciales maestras (URL, RIF de la Sucursal, Tokens de Autorización) se extraen **en tiempo real** de la tabla `thefactory_api_config` alojada en la BD `ventas` maestra del proyecto.
* **Separación de Responsabilidades:** La clase no escribe en la base de datos (a excepción del log). Solo recibe el `$id_doc` comercial, parsea, y contacta a la API gubernamental, retornando un array `['status', 'nro_control', 'message']`.

## 2. Modelado de Datos (Esquema SQL)
El flujo completo de vida de toda factura o nota de crédito digital altera tres tablas esenciales:

1. **`documentos_comerciales` (BD Caja):**
   * El estado actual de la nube se refleja en `estado_digital` *(ENVIADO, PENDIENTE, RECHAZADO, ANULADO)*.
   * Se almacena permanentemente el *Nro de Control Oficial* en `numero_control_digital` y la URL para consultar el ticket al consumidor final en `url_consulta_digital`.
   
2. **`facturacion_digital_logs` (BD Caja):**
   * **Auditoría Técnica Total:** Toda petición saliente se escribe aquí, guardando los payloads íntegros (`request_json` y `response_json`) y el código `http_status` nativo. 
   * Es la fuente de la verdad para debugear caídas de red o la máquina de "Línea de Tiempo Operacional".

## 3. Peculiaridades de la Nube (Casos Especiales de TheFactory)
Durante el desarrollo se lidiaron y se parchearon comportamientos muy específicos de la plataforma de la imprenta para las **Notas de Crédito (Tipo 02)**:

> [!WARNING]
> Reglas Críticas implementadas en el Proxy para que el documento NO sea rechazado:
> 
> * **Prohibido Símbolo Negativo:** Toda anulación u operación en negativo se limpia usando la función `abs()` a la hora de estructurar el JSON (como la matriz de pago). TheFactory exige valores en absoluto aunque el módulo lo perciba negativo localmente; ellos aplican el reverso internamente según el tipo `02`.
> * **Colapso de Prefixado Cero:** El código local almacena las facturas y copias bajo formatos legibles (e.j. `NC-00000005`, `DIG-00...`). TheFactory es intolerante a abecedarios o guiones. La clase cuenta con un limpiador `str_replace()` dedicado y vital.
> * **Cuadre Moneda Extranjera IGTF:** Las Notas de Crédito, si carecen de formas de pago simuladas, deben inyectar obligatoriamente la sumatoria del IGTF de la caja originaria (`$doc['igtf_ves']`) para cuadrar con `TotalAPagar`.

### El Falso Positivo del Código HTTP
TheFactory responde con estatus de conexión **HTTP 200 (Éxito)** incluso cuando la lógica del documento *falla en sus filtros internos*.
Para solucionar esto, el código no confía en la capa de red. Analiza el JSON crudo en búsqueda de `{"codigo": "00"}` o `{"codigo": "200"}`. El resto son asumidos como rechazos y se revierten para proteger el ledger contable.

## 4. Diseño y UI Reactiva

* **Proxy PDF Universal (`back_caja.php?accion=proxy_visor_pdf_digital`):** 
  Dado que los navegadores bloquean CORS y PDFs empotrados si los sirve TheFactory directamente, el Backend actúa de puente. Descarga el Base64 inyectando los parámetros `nro_doc`, `nro_ctrl` y su `tipo_doc` (01 para Facturas, 02 para NC's) bajo esquemas dinámicos para empotrar los resultados localmente en el visor flotante del usuario sin sacar al cajero de su ventana.
* **Historial Analítico (Línea de Vida):** Interfaz auto-generada construida para compilar todo el Timeline del documento mediante cruzamiento visual. Mapea íconos de colores automáticamente permitiendo invocar la previsualización interactiva.
