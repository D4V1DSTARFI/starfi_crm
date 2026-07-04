# Handover Técnico: Módulo de Pedidos y Billeteras (Caja STARFI 2.0)

📅 **Fecha de Corte:** 18 de Marzo de 2026
📍 **Estado General:** Frontend Visual Estructurado (Esperando Conexión a Base de Datos Local para integrar Backend).

Este documento registra el progreso exacto y el contexto técnico para poder retomar el flujo de trabajo en una nueva sesión o ventana de chat sin perder la inercia del desarrollo.

---

## 1. Lo que ya está completado y probado
*Se resolvieron todos los conflictos críticos de latencia y consistencia de datos en el sistema Multi-Moneda.*

1. **Migración de Pedidos a Caja Central:**
   - La interfaz ahora soporta Pedidos. El controlador `anular_documento` en `back_caja.php` fue reescrito para distinguir entre `VENTA` y `PEDIDO`.
   - Se crearon los callbacks en `funciones_caja.js` para recargar los paneles al procesar Pedidos.
2. **Arquitectura Estructural de Billeteras (Kardex):**
   - Se crearon las tablas operativas `caja_billetera_saldos` (Cabecera O(1)) y `caja_billetera_kardex` (Histórico) para gestionar los abonos a favor por Pedidos cancelados o no entregados.
   - **Corrección Clave:** Se parchó el error fatal de `mysqli_stmt::bind_param` (string "iddddd" a "idddd") en la línea 1421 de `back_caja.php` que rompía la inserción atómica de los saldos.
3. **Data Recovery y Limpieza:**
   - Se corrieron con éxito los scripts de saneamiento `tmp_resync.php` y `tmp_fix_mixta.php` para limpiar la historia de ingresos heredados oscuros (MIXTA) y recalcular milimétricamente el balance real actual (USD/VES) de los clientes huérfanos.
4. **UX / Interfaz de Pestañas (Efecto Camaleón):**
   - El HTML original obligaba a usar CSS inline quemado sobre la pestaña "Notas". Se inyectó un listener asíncrono en `funciones_caja.js` (`shown.bs.tab`) que transfiere robóticamente el estilo blanco brillante a la pestaña que el cajero pulse en el momento.

---

## 2. Dónde nos quedamos (Trabajo en curso)
*Se diseñó en duro (Static Mockup) la arquitectura gráfica de las Billeteras porque la conexión a `192.168.0.71` sufrió un timeout por trabajar fuera de la oficina.*

1. **HTML en `caja.php`:**
   - Se intercaló el Tab Link `Billetera` en el Navbar Principal.
   - Se anexó el `div#panel-billetera`. Consta de un Col-9 (Tabla Maestra de Portafolio: USD Totales y Fianza en Bs por Cliente) y un Col-3 lateral estático (Tarjeta Resumen "Riesgo Financiero / Deuda a Clientes").
2. **Modales en `modals_caja.php`:**
   - Se adjuntó al ultimísimo final del archivo el contenedor `modalHistorialBilletera` (modal-xl). Sirve para exponer el Kardex histórico individual una vez que se pulsa un cliente.
3. **Mocks en `funciones_caja.js`:**
   - Al final de este archivo están sembradas dos funciones "Dummy": `cargarBilleteras()` y `verHistorialBilletera(id)`. Estas inyectan filas JSON de mentira hacia las tablas usando un `setTimeout(400ms)` para simular una petición AJAX y que puedas ver la estética del front.

---

## 3. Próximos Pasos Exactos (Next Steps para la IA)
*Al reabrir el entorno con MySQL online, la nueva Inteligencia Artificial debe ejecutar exclusivamente estos pasos:*

1. **Test Visual:** Pedirle al usuario que inicie sesión en la Caja y abra la pestaña Billeteras para que valide que el CSS, los Modales y las Tablas Mockups lucen según su gusto.
2. **Desenchufe del Mocking (Frontend):** En `funciones_caja.js`, reemplazar los Arrays simulados de `cargarBilleteras()` y `verHistorialBilletera()` por verdaderas llamadas `$.ajax` dirigidas a `back_caja.php` con las acciones pertinentes (ej: `accion: 'listar_billeteras_activas'`).
3. **Conexión SQL (Backend):** 
   - Configurar en `back_caja.php` el enrutador para la lista maestra (extrayendo todos los registros de `starfi_caja.caja_billetera_saldos` y cruzándolos con los Nombres en `starfi_ventas.clientes`).
   - Configurar el enrutador del Detalle Kardex buscando con un simple filtro por `id_cliente` sobre la bitácora cronológica de `caja_billetera_kardex`.
4. **Acoplamiento de Seguridad:** Asegurar que los perfiles con nivel de cajero estándar no puedan alterar saldos de billeteras manualmente, solo la gerencia/auditoría maestra.

> **Fin del Handover:** Cierra este documento y provee esta hoja de ruta textual al nuevo sistema cognitivo de contexto.
