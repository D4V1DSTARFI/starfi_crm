# Modalidad de Facturación Parcial Segmentada

## Arquitectura y Propósito
El modo de "Facturación Parcial" en la Caja V2 de STARFI 2.0 es un modelo contable de emergencia utilizado cuando el cliente requiere pagar de forma híbrida (Efectivo Físico + Método Bancario), y el operador decide que **fiscalmente (para el reporte Z de la máquina HKA80)** solo se declarará el monto transaccionado de forma electrónica, omitiendo los dólares interbancarios o billetes depositados físicamente, para evitar desbordar o sobreestimar los ingresos reportados artificialmente.

## UI: Intervención Dinámica y Activador
El sistema se encuentra "Dormido" bajo la modalidad clásica de Facturación, pero se activa mediante un disparador silencioso implementado en capa de usuario:

- **Activación:** Se habilita al detectar un evento lógico de "Doble Clic" (`dblclick`) sobre el letrero *“Detalle de Cobro”* dentro del Modal de Pago.
- **Ciclo de Vida del Contexto (Estado Reversible):** Al cierre natural (Cancelación, Cierre de Pestañas) o impresión exitosa del modal, el estado booleano dinámico (`window.modo_parcial_activo`) regresa sistemáticamente a `false` para asegurar que las ventas subsecuentes se emitan íntegramente de manera normal.

## Lógica Backend y Componentes Principales
El núcleo controlador reside en las funciones alojadas en `modules/caja/funciones_caja.js` (Lado del Cliente) emparejadas estrechamente con el receptor en el archivo de ruta de ejecución `modules/caja/back_caja.php`.

### 1. Extracción de Base Reactiva (Lado del Cliente y Predicciones UI):
Cuando se detecta transaccionalidad Parcial, el componente JS que invoca recursivamente un AJAX (`accion: calcular_totales_caja`) manda la orden directa `emitir_factura_parcial_ui: 1`. 

En la predicción de este backend, ocurre el **Anclaje de Base**:
La base total original de la nota se blinda (se muestra en UI intacta) para asegurar el cobro completo. La lógica aísla los agregados generados en el array de `pagos` cuya correspondencia sea puramente `tipo_cobro === 'BASE'` excluyendo cualquier método 'EFECTIVO'. A estos abonos no se les ejecuta división extrayendo IVA, puesto que la interfaz asume el llenado explícitamente segmentado.
Por lo tanto, la suma agregada se multiplica directamente por `0.16` reconstruyendo un IVA dinámico que eleva exactamente la cantidad adosada en tiempo real. 

### 2. Bloqueo al Impuesto Dolarizado (IGTF Bypass):
Se aplica un blindaje limitador de `$0.00` sobrescribiendo artificialmente `$aplica_igtf = 0`. Esto anula el impuesto frente al cliente, evadiendo la cuota impositiva a dólares reportados físicamente durante la simulación de cobro; los dólares aportados se asumen netamente al capital de libre comercio.

### 3. Ejecución Fáctica en la Máquina Fiscal y Prevención de Pánico (Error 500):
En el tramo final de confirmación `accion: facturar`, el backend compila el array consolidado (uniendo pagos que ahora combinan BASE e IVA ingresados por el usuario). Al fusionarse, esta cifra bancaria ahora sí representa una **estructura hibridada de subtotal+iva** a ojos del Fiscalizador. 

Para lograr lo que la Impresora TheFactory HKA asimila como Base e IVA, la ejecución emplea una ingeniería de desempaquetado:
`$base_imponible_ves = round($suma_bancaria_total_pagada / 1.16, 2);` 
`$iva = round($base_imponible_ves * 0.16, 2);`

Todo el contenido material de compra del ticket original se suprime temporalmente del array transaccional para evitar fallos de montos, y se envía en crudo la Referencia Única:
`TOTAL COMPRA DE REF INTERNA NRO-XXXX`

Por último, se intercala un bypass lógico de validación contra manipulación de montos. Naturalmente el sistema crashea en Error de Excepción Crítica (`Error 500 Interno del Servidor`) si detecta que la `Factura Final Envíada` no equivale a los `Pagos Totales Recibidos`. Dado que el Efectivo reportado genera este sobrante en el reporte global en la modalidad parcial, se anula esta alerta, permitiendo registrar silenciosamente el remanente en la Base de Datos mientras a la impresora llega tan solo la información limitada y controlada.

## Resumen de Responsabilidad Estricta
Dada la bifurcación obligatoria impuesta por el ecosistema STARFI V2 en "Cobros Segmentados de Pantalla", el módulo comprende y diferencia la matriz `BASE` de su contraparte `IVA`, de lo contrario, la retroalimentación formaría un círculo vicioso, engordando ambos dígitos a la eternidad. Todo ajuste de algoritmo sobre `back_caja.php > calcular_totales_caja` debe preservar un cuidado metódico para esquivar la inyección paralela de la variable `$tipo_cobro`.
