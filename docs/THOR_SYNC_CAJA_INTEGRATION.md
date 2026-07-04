# Documentación Técnica: Integración de Thor Sync en Cierre de Caja (STARFI 2.0)

## 1. Visión General
El motor central de sincronización "Thor Sync" se ha integrado de manera segura y transparente dentro del flujo de **Cierre General de Caja** (`funciones_caja.js`). Esta integración permite asegurar que la actualización masiva del catálogo local (nuevos productos, cambios de precio y nuevas imágenes) se ejecute **únicamente cuando las operaciones financieras del día hayan concluido**, previniendo inconsistencias, conflictos de ventas y alteraciones matemáticas en el sistema.

## 2. Componentes Arquitectónicos

### 2.1 Backend de Extracción Macro (Caché Local Localizado)
*   **Archivo Principal:** `modules/ventas/sync_thor/ajax/sync_agent_local.php`
*   **Problema Histórico Resulto:** La API Remota de Thor en `starfi_thor_api` devolvía timeouts intermitentes o duplicación de offsets al procesar grandes volúmenes de datos directamente al cliente.
*   **La Solución V2:** El backend de STARFI 2.0 implementa ahora una técnica de **Macro-Extracción**. Al iniciar el proceso (`offset = 0`), el servidor descarga en una sola petición a alta velocidad el catálogo y lo escribe localmente en un archivo puente temporal (`temp_sync_{usuario_id}.json`).
*   **Paginación Off-grid:** La interfaz del navegador ya no golpea a la nube, sino que golpea a este archivo puente solicitando *slices* (fracciones de arreglo vía `array_slice()`), permitiendo procesar lotes fluidos, limpios y evitando 100% el Timeout remoto.

### 2.2 Bloque Visual y Barra de Progreso (`funciones_caja.js`)
Se incrustó de forma dinámica una nueva función `generarBloqueThorSyncCaja(isUnlocked)` al pie de las validaciones formales del cierre global.

*   **Variables Analíticas:** El porcentaje progresivo se computa obteniendo la variable directa `total_db` enviada por el motor de backend. 
*   **Prevención de Congelamiento Mental Visual:** Al calcular basándonos en la respuesta de `total_db`, los reportes de volumen y porcentaje reflejan exactamente cuántos lotes de 100 en 100 se procesan sobre la meta técnica total, previniendo que la barra se tranque en *99%* por incertidumbre matemática.
*   **Autonomía Segura:** El usuario puede cancelar/parar la página sin miedo. Gracias al uso de `register_shutdown_function` y un *Watermark Segregado* en la base de datos `starfi_core.thor_client_config`, solo los lotes efectivamente procesados actualizarán el umbral de sincronización.

## 3. Comportamiento en Producción y Prevenciones de Seguridad

1.  **Botones Disable-Locked por Defecto:** Mientras el usuario no valide las Cajas con la opción de Cierre Global, **los botones Thor Sync estarán inhabilitados visualmente**.
2.  **No Modificar APIs Remotas:** Las apis remotas no fueron manipuladas en lo absoluto debido al alto impacto lateral con las demás sucursales. La capa de adaptabilidad que procesa los recálculos en base a la paginación es **estrictamente sobre el cliente (STARFI 2.0)**.
3.  **Vaciado de Imágenes Físcas:** Las imágenes procesadas durante la estabilización y pruebas fueron depuradas de `modules/producto/producto_imagen` para que en producción el motor pueda arrancarlas formalmente según correspondan a un catálogo legítimo y limpio.

## 4. Archivos Modificados Notables en STARFI 2.0
*   `modules/caja/funciones_caja.js` (Inyección de Bloque Visual e Inyección del Ciclo `loopSync()`)
*   `modules/ventas/sync_thor/ajax/sync_agent_local.php` (Adopción de macro-json, transmisión de total indexado).
*   `modules/ventas/sync_thor/js/sync_thor.js` (Estandarización de Variables para el panel manual de Ventas).
