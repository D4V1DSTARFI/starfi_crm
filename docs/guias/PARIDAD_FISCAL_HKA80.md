# Patente Matemática HKA80 - Regla de Cuadratura al Céntimo

**Fecha de Implementación:** Marzo 2026
**Ubicación Principal de la Lógica:** `modules/caja/back_caja.php` (`facturar`, `calcular_totales_caja`)

## El Síndrome de la "Pérdida por Round-Trip"
En arquitecturas multi-moneda que se comunican con hardware fiscal TheFactory (HKA80), existe una peligrosa discrepancia natural en el cálculo de totales si el sistema intenta multiplicar totales macro:

**El Problema Original:**
Si una factura totaliza $100.25 USD, el programador común multiplica:
`$100.25 * Tasa (39.29) = Bs. 3938.8225 (Base Imponible General)`

Sin embargo, el microprocesador interno de la HKA80 **siempre evalúa ítem por ítem** truncando hacia 2 decimales en el camino:
Ítem 1 ($50.12 * 39.29 = 1969.21)
Ítem 2 ($50.13 * 39.29 = 1969.60)
`Suma de los ítems trunco-evaluados = Bs. 3938.81`

**El resultado era un desfase de Bs. 0.01 (y de hasta varios centavos en facturas largas).**
Esto, a su vez, originaba que el IGTF (Impuesto del 3%) también sufriera un efecto "mariposa", facturando montos asincrónicos en el sistema vs la impresora HKA.

## La Fórmula de Paridad ("Inamovible")
Para solucionar este desfase arquitectónico se implementó el calculador en espejo o **Patente Matemática HKA80**:

**1. Evaluación Genuina:**
Antes de generar pagos o calcular IVA/IGTF, el backend debe leer y simular obligatoriamente la sumatoria de todos los ítems de la nota usando la misma mecánica HKA80: `Sumatoria de ( round(precio_usd * tasa_cambio, 2) * cantidad )`.

**2. Destruyendo la Base Temporal:**
Se abandona por completo el total en dólares que viene del Frontend o de la orden comercial. El resultado de la sumatoria genuina pasa a reescribir la Base Imponible en Bolívares Oficial del ticket. Todo descuento, IVA o IGTF nacerá obligatoriamente a partir de aquí.

**3. Prohibido el "Cent Ajust":**
Queda estrictamente prohibido intentar engañar a la máquina sumándole mágicamente `0.01` al último ítem facturado. Esta antigua práctica rompía severamente las facturas donde la dosis del último ítem era mayor a 1 unidad.

```php
// =================================================================================
// [PATENTE MATEMÁTICA HKA80 - EJEMPLO RECTOR]
// =================================================================================
$id_nota_target = $nota_data['id'];
$conVentasQuery = getDbConnection('ventas');
$qItemsTrunco = mysqli_query($conVentasQuery, "SELECT cantidad, precio_producto FROM venta_producto WHERE id_venta = '$id_nota_target'");

$sum_items_ves = 0;
while($item = mysqli_fetch_assoc($qItemsTrunco)) {
    $precio_ves = round(($item['precio_producto'] * $tasa), 2);
    $sum_items_ves += round($precio_ves * $item['cantidad'], 2);
}

// Sustitución radical de base bruta para encadenar impuestos perfectos
$base_imponible_ves = round($sum_items_ves - $descuento_global_ves, 2);
$iva = round($base_imponible_ves * 0.16, 2);
...
```

**ADVERTENCIA PARA FUTUROS DESARROLLADORES (AGENTES IA O HUMANOS):**
Bajo ninguna circunstancia intente refactorizar o reordenar estos bloques en la cabecera del script de faturación (`back_caja.php`). Si la interpelación trunca se ejecuta en una fase inferior, el IGTF nacerá desviado y la caja rechazará los cierres por descuadre de céntimos.
