# Ciclo de Vida de Pedidos y Lógica de Emisión en Venta-Móvil

El módulo 'Venta-Móvil' proporciona un espacio de trabajo especializado para crear y editar Pedidos que eventualmente se emiten como Notas de Entrega/Venta.

## 1. Patrón de Edición Temporal

Para permitir la edición multi-paso sin afectar las tablas autoritativas `pedido` y `pedido_producto` durante sesiones de usuario activas, el sistema utiliza un **Patrón de Copia Temporal**:

1. **Inicialización**: Cuando se abre un pedido para ver detalles o editar, el sistema verifica si existen registros temporales.
2. **Duplicación**: Crea una copia fiel del pedido en las tablas `pedido_temporal` y `pedido_producto_temporal` basándose en el ID único del Pedido.
3. **Persistencia de Sesión**: Las ediciones en cantidades y lista de productos se aplican *únicamente* a las tablas `_temporal`.
4. **Recálculo**: El monto `base` en `pedido_temporal` se actualiza cada vez que se edita o remueve un producto, garantizando que el usuario visualice un subtotal exacto en pantalla.

## 2. Proceso de Emisión (De Pedido a Venta)

La transición de un Pedido a una Nota de Venta se da en varios contextos, empleando dos controladores backend principales:
- **Gestor de Pedidos (`back_gestion_pedido.php` y `pos_gestion_pedidos.php`)**: Gestiona la emisión de notas desde pedidos existentes (y potencialmente editados) a través del motor del frontend JS.
- **Emisión Inicial (`back_pedido.php`)**: Controla la creación y emisión directa de nuevos pedidos provenientes del carrito de compras.

### Cadena de Éxito
1. **Verificación de Existencias**: Un chequeo previo asegura que los renglones cuentan con stock suficiente antes de procesar el despacho.
2. **Creación de Cabecera**: Se inserta un registro en la tabla `venta` que hereda los metadatos del pedido original.
3. **Transferencia de Productos**: Todos los productos se enlazan al ID recién generado de la Nota de Venta.
4. **Cálculos Finales**: El importe total derivado de los productos migrados se almacena en `$total_nota`.
5. **Impacto en el Kardex**: Se registran los movimientos de inventario vinculándolos obligatoriamente a la Nota de Venta.
6. **Transición de Estado**: El registro original en `pedido` avanza a `[NOTA GENERADA]` o `[EMITIDO]`.
7. **Deducción de Billetera/Crédito**: Si aplica (y está prepagado), se descuentan los fondos en `caja_billetera_saldos`.

## 3. Flujo de Reembolsos e Integración de Billetera (V2)

Cuando el procesamiento de un Pedido exige devolver dinero a favor del cliente (ya sea por eliminación total del pedido o descuento parcial de productos no despachados durante la confirmación), el sistema se ampara en una sólida integración entre la **Billetera V2** y la arquitectura de **Notas de Débito** (`caja_notas_debito`).

### Separación Estricta entre Emisión y Reembolsos
1. **Emisión Parcial (`PARCIAL`)**: 
   - Exige la creación explícita de una Nota de Venta física.
   - Solo se verifican y transfieren los productos admitidos y se consumen las cantidades correctas de inventario.
   - El dinero excedente, remanente de los productos caídos o eliminados, permanece atrapado silenciosamente dentro del "Saldo" de la Billetera (La regla `suficiente_billetera` está diseñada para comparar el total original prepagado vs. consumo exacto de despacho real).

2. **Reembolso Total (`REEMBOLSAR_TOTAL`)**:
   - Omite y puentea completamente la creación de un inserto en la tabla `venta`. Evita la contaminación con "Facturas / Entregas en blanco (Fantasma)".
   - Genera orgánicamente una "Nota de Débito" contable que representa y rescata, dentro de la Caja, el excedente exacto del dinero sobrante basándose en el pedido original.
   - Al finalizar, avanza el `pedido` a `[NOTA GENERADA]` estrictamente para finiquitar la historia del documento y limpiar/extinguir en lote la tabla base de `pedido_producto`.

### Interoperabilidad de la Nota de Débito: Trampas Matemáticas
En la comunicación transaccional a nivel base de datos entre `STARFI VENTAS` y `STARFI CAJA`, rigen leyes estrictas e inquebrantables:
- **Etiquetado de Estatus**: El Backend de Ventas TIENE QUE insertar la nota bajo el estado string `status = '[PENDIENTE]'`, o de lo contrario el visor dinámico del Módulo de Caja la mantendrá invisible para el Cajero a la hora de procesar el reintegro.
- **Formato Estricto Divisas (`total = Monto Frío`)**: La columna `total` de la tabla `caja_notas_debito` SIEMPRE debe ser declarada en Equivalente base a Dólar (ej. $21.36). 
    > [!WARNING]
    > **Riesgo Matemático Grave**: Nunca se debe pre-multiplicar el excedente por `$tasa` en el `INSERT` hacia `caja_notas_debito`. La lógica interna en `back_caja.php` ya aglomera todo el dinero como USD por defecto y maneja la macro-conversión a Bolívares en caliente durante la renderización web: `round($row['total'] * $tasa_bcv, 2)`. Multiplicar previamente el `INSERT` dispara exponenciales inflacionarios ficticios induciendo Notas de Débito Multimillonarias en caja (Millones de Dólares).
- **Conexión Directa a Caja (`$conCaja`)**: La tabla puente `nota_de_debito` en el esquema de Ventas fue dada de baja. El insert se debe comunicar transversalmente apuntando a `caja_notas_debito` bajo el contexto e instancia real de caja SQL, asegurando el puente natural entre cajero y devolución.

## 4. Lineamientos y Blindajes Generales de Implementación

- **El Peligro del Try-Catch en PHP 8**: En caso de un fallo en tiempo de ejecución originado por Variables Indefinidas (Ej: `$id_usr` encapsulado fuera de su bloque léxico idóneo) o advertencias de Strings interpolados obsoletos/vacíos (`$db_core_dinamico`), la rigidez de PHP 8 hace estrellar y eyectar la traza arruinando la salida silenciosa programada por AJAX JSON. Esto rompe brúscamente la aplicación JS desatando alertas de _"Error de Conexión"_ engañosas en el monitor frontal, todo esto en pleno avance de una base de datos sin un Rollback efectivo e induciendo al Descuadre (Desincronización).
- **Sincronización Mandatoria por ID**: Un error sistémico grave fue descubierto en el core; ciertas sentencias SQL UPDATE amarraban órdenes buscando el `token` de sesión. En el caso crítico de existir múltiples pedidos (Normal versus Promoción) que comparten y nacen del mismo token maestro de la caja, ambos quedaban machacados. **Actualizar el estatus imperativamente filtrando su primary Clave "ID"**.
- **Equilibrio de la Moneda Nativa**: La Billetera no deduce saldos en un bolsillo genérico. Separa estructuralmente divisas (`saldo_usd`) y bolívares (`saldo_ves`). Todo ingreso o restitución automatizada debe discernir lógicamente el tipo de moneda original (`moneda`) bajo la cual operó la transacción madre para resarcirla. Restituir sin discernimiento causará inmediatamente que el sistema reporte Falso Positivo de `[BILLETERA SIN FONDOS]`.

## 5. Prevenci�n de P�rdida de Datos: Persistencia del Carrito (LocalStorage)

Para proteger al usuario de recargas accidentales (F5) o cierres de pesta�a durante el armado de un documento (Nota, Pedido o Presupuesto), el sistema emplea una estrategia de **Persistencia Frontend H�brida**: 

1. **Aislamiento por Cliente**: Cada variable de guardado incluye el identificador del cliente (STARFI_CART_NOTA_V21...). Evitando que si el vendedor cambia de cliente en el dashboard, se mezclen los carritos.
2. **Almacenamiento Continuo**: En cada detenci�n del ciclo ctualizarBadgeCarrito(), todo el vector JSON temporal de productos pre-cargados se codifica y se env�a bajo cifrado al LocalStorage del navegador.
3. **Carga Segura por DOMContentLoaded**: Al ingresar al m�dulo, Vanilla Javascript revisa la existencia de carritos pre-guardados e inyecta la informaci�n recargando nativamente las tablas gr�ficas, **sin chocar ni depender** de si \jQuery\ carg� antes o no.
4. **Prevenci�n de Colisiones de Sesi�n**: Cuando el backend interviene el ciclo para recuperar un **Borrador desde la Base de Datos ([EN_ESPERA])**, inyecta en el servidor PHP una variable global \\['cart_espera']\. Para que el LocalStorage anterior no sabotee esta inyecci�n al cargarse el DOM, existe un \localStorage.removeItem()\ expl�cito que purga el navegador forz�ndolo a utilizar el carrito que manda el servidor.

## 6. Clonaci�n Segura: Edici�n de Presupuestos Emitidos 

En STARFI, la edici�n de un documento comercial debe mantener trazabilidad (Auditor�a Cero Fallas). Por ello, al editar un Presupuesto ya \[EMITIDO]\ (v�lido estrictamente para *el mismo d�a*), el ecosistema realiza un **Ciclo de Clonaci�n Destructiva**: 

1. **Marcar no Destruir**: Mediante la opci�n A dictaminada, el BackEnd \ack_movil.php\ (case \editar_presupuesto\) toma el n�mero de correlativo base (ej. #450) y lo actualiza a \status = '[ANULADO]'\. Jam�s ejecuta una sentencia DELETE; de esta manera, auditor�a puede registrar que *existi�* un Presupuesto defectuoso pero que fue corregido.
2. **Migraci�n a Sesi�n**:  Los renglones extra�dos �ntegramente de la Base de Datos se formatean y encapsulan en la variable \\['cart_espera_presupuesto']\.
3. **Clonaci�n**: Todo el flujo redirige autom�ticamente a la pantalla de presupuesto nuevamente, donde el sistema procesa como si fuera una venta reci�n cargada. Si el usuario procede a grabarlo confirm�ndolo, el sistema generar� limpiamente el correlativo #451 para su impresi�n, sin generar choques transaccionales.

