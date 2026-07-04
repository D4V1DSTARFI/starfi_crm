# Protocolo de Cuadre Fiscal "VES-First" y Sincronización de Tasas

**Fecha de Implementación:** Mayo 2026  
**Objetivo:** Garantizar la paridad matemática exacta (al céntimo) entre la Interfaz de Usuario (Carrito), la Base de Datos (Backend), la Emisión de Notas (PDFs) y la Impresora Fiscal (HKA80 / TheFactory).

---

## 1. El Problema del Desfase de Céntimos (Falso Positivo de IVA)

Históricamente, los sistemas duales (USD / VES) tienden a sufrir desfases de redondeo cuando se calcula el total en una moneda (USD) y al final se multiplica por la tasa para obtener el total en Bolívares.

**Ejemplo del error (Práctica Prohibida):**
```php
// ¡NUNCA HACER ESTO! Destruye el cuadre fiscal:
$iva_usd = round($b_imponible_ves / $tasa, 2); 
$iva_bs_roto = $iva_usd * $tasa; // <- Esto genera el desfase de 1.15 Bs
```
La impresora fiscal **HKA80** rechaza o descuadra este tipo de cálculos porque ella no sabe de dólares, ella evalúa matemáticamente cada artículo enviado en bolívares y suma sus resultados individuales.

---

## 2. El Estándar "VES-First" (Bolívar Primero)

Para que el sistema de Starfi 2.0 hable el mismo idioma que la impresora fiscal, todos los módulos (Carrito JS, Carrito PHP, y PDF) deben seguir estrictamente el siguiente orden operacional:

1. **Precio Unitario (VES):** Se toma el precio del producto en USD, se multiplica por la Tasa oficial, y se **trunca/redondea a 2 decimales** inmediatamente.
2. **Subtotal por Ítem (VES):** Se multiplica el Precio Unitario (VES) ya redondeado por la cantidad comprada. El resultado se vuelve a fijar a 2 decimales.
3. **Segregación:** Se agrupan los Subtotales (VES) en dos canastas separadas: `Base Imponible` (IVA 16%) y `Base Exenta` (IVA 0%).
4. **Cálculo de Impuestos (VES):** Se suma el total absoluto de la canasta `Base Imponible` y se multiplica por `0.16`. El resultado final se redondea a 2 decimales.

**Ejemplo de Código Correcto (Referencia en `funciones_carrito.php`):**
```javascript
let precio_ves_trunc = parseFloat((item.precio * tasa).toFixed(2));
let subtotal_ves = parseFloat((precio_ves_trunc * item.cant).toFixed(2));
let iva_ves = parseFloat((b_imponible * 0.16).toFixed(2));
```

---

## 3. Arquitectura de Sincronización de Tasa

Para garantizar que el carrito de compras y la caja registren exactamente lo mismo, la **Tasa de Conversión** es unificada y proviene exclusivamente del módulo de CAJA (`starfi_caja.tasa_conversion`).

*   **Frontend (UI del Carrito):** 
    En `carrito.php` se inyecta la consulta directa a `getDbConnection('caja')` antes de cargar el modal. El valor numérico se incrusta en la etiqueta HTML `<span id="lblTasaCart">`. El Javascript de la vista lee ese DOM para realizar los cálculos interactivos mostrados al cliente.
*   **Backend (Guardado de Pedidos/Notas):**
    El archivo `back_carrito.php` **ignora** la tasa que envíe el Javascript. Por seguridad, consulta en tiempo real la BD `starfi_caja` para tasar la factura antes del guardado.
*   **Generación de PDF (Notas/Pedidos/Presupuestos):**
    Los archivos `generar_pdf.php` recalculan línea por línea bajo el estándar "VES-First", asegurando que el papel impreso coincida exactamente con la transacción guardada en BD.
*   **Generación Masiva:**
    Los archivos como `documento_nota_masiva.php` están enrutados a la conexión `$conCaja` para asegurar que el procesamiento en lote se alimente de la misma fuente que la facturación individual.

---

## 4. Troubleshooting (Auditoría Futura)

Si en el futuro existe una queja de que *"El PDF muestra un monto en Bs distinto a lo que cobró la Caja"*:
1. Revisa `generar_pdf.php` en la carpeta correspondiente (`nota_entrega`, `pedido`, `presupuesto`).
2. Confirma que la variable `$iva_bs` y `$total_bs` se estén construyendo a partir de `$b_imponible_ves`. 
3. Asegúrate de que **NADIE** haya vuelto a agregar líneas de código que re-calculen los totales en Bolívares usando multiplicaciones desde las variables `_usd`. La conversión de monedas siempre debe ser unidireccional: **(USD * Tasa) -> Base Matemática Central -> División para mostrar en USD referencial**.
