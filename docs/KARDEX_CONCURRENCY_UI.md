# STARFI 2.0 - Arquitectura de Blindaje de Kardex y UX

## Contexto y Problema Resuelto
El mÃ³dulo de Venta MÃ³vil presentaba anomalÃ­as contables llamadas 'Phantom Reads' (Condiciones de carrera) impulsadas por mÃºltiples terminales y asesores insertando facturas simultÃ¡neamente en una brecha menor a 1 segundo. Al referenciar variables no bloqueadas desde la tabla producto_existencia, la cadena de custodia del Kardex se rompÃ­a, generando saltos de stock ilÃ³gicos (Ej. Existencia Anterior: 228 -> Venta: 1 -> Existencia Nueva: 228).

## 1. SoluciÃ³n en Capa Backend (Candado Transaccional Absoluto)

Se reemplazÃ³ la dependencia de subconsultas dÃ©biles por una tÃ©cnica de extracciÃ³n dura hacia el top-tier histÃ³rico del Kardex aplicando FOR UPDATE. 

### Rutinas Afectadas
- modules/venta_movil/nota_entrega/carrito/back_carrito.php
- modules/venta_movil/gestion_pedidos/back_gestion_pedidos.php
- modules/venta_movil/back_pos.php

**CÃ³digo de Regla EstÃ¡ndar:**
$qKardex = "SELECT existencia FROM kardex WHERE id_producto = ? AND id_almacen = ? ORDER BY fecha DESC, hora DESC, id DESC LIMIT 1 FOR UPDATE";

Este estÃ¡ndar es OBLIGATORIO en cualquier futura integraciÃ³n o API (Ej. Entradas/Salidas de Compras) para el Ãºltimo cÃ¡lculo matemÃ¡tico de ext_ant garantizando que siempre se apunte al registro mÃ¡s actual de manera atÃ³mica.

---

## 2. OptimizaciÃ³n UI / UX Frontend en Kardex

Se reescribiÃ³ transversalmente la lÃ³gica de carga en el modal de Historial del AlmacÃ©n.

### Mejoras Inyectadas (modal_info_productos.php & unciones_info_productos.js)
- **Filtro de Fechas y Rango**: Controles Desde / Hasta evalÃºan fechas con JavaScript nativo, permitiendo cortes instantÃ¡neos del ciclo.
- **Selector de Operaciones**: ExtracciÃ³n auto-poblada via [...new Set()] de los tipos de concepto existentes dentro del listado obtenido, previniendo errores de tipeo al buscar.
- **PaginaciÃ³n en Memoria**: Para prevenir colapsos del DOM en productos de alto movimiento, los arrays de informaciÃ³n es.data se conservan globalmente para particionar la visualizaciÃ³n de 10 en 10, con cÃ¡lculo asÃ­ncrono en enderTablaKardex().

### ExportaciÃ³n Excel con JS
Se empleÃ³ la migraciÃ³n de trabajo a Cliente mediante SheetJS (xlsx). El motor consolida el reporte bajo las restricciones activas en la interfaz del usuario. Esta caracterÃ­stica mejora la escalabilidad del sistema impidiendo cuellos de botella del I/O de red en PHP / PHPSpreadSheet.
