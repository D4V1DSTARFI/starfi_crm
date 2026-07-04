# Análisis Técnico: LOTTT (2012) aplicado a STARFI 2.0

## Documento de Origen
**Archivo analizado:** `2012_leyorgtrabajo_ven.pdf` (Ley Orgánica del Trabajo, los Trabajadores y las Trabajadoras - Gaceta Oficial N° 6.076, Mayo 2012).

---

## 1. Parámetros Legales (LOTTT) vs. Estado Actual del Sistema (STARFI 2.0)

A continuación, se presenta una tabla de estado técnico que compara las obligaciones que establece la Ley frente a los módulos que actualmente posee el sistema en el ecosistema `starfi2.0/nomina/`.

| Concepto / Artículo LOTTT | Exigencia de la Ley | Estado en STARFI 2.0 | ¿Qué falta por programar? |
| :--- | :--- | :--- | :--- |
| **Jornada Laboral (Art. 173)** | Diurna (40h), Nocturna (35h), Mixta (37.5h) con 2 días libres continuos. | 🟡 **Parcialmente Cumplido:** El módulo `asistencia` lleva el registro de entradas y salidas. | Falta un bloqueador que impida agendar más horas del tope semanal por tipo de turno, y el control estricto de los 2 días de descanso. |
| **Horas Extras (Art. 118)** | Recargo del **50%**. Máximo 10 horas semanales / 100 anuales. | 🟢 **Cumplido:** `back_auditoria.php` detecta las horas extras y `conceptos.php` permite sincronizarlas. | Falta añadir la alerta en el frontend cuando se superan las 100 horas extras anuales. |
| **Bono Nocturno (Art. 117)** | Recargo del **30%** (7:00pm - 5:00am). | 🔴 **Pendiente:** No existen funciones nativas para detectar automáticamente este recargo. | Crear en `back_asistencia.php` una función que cruce las horas marcadas contra el reloj nocturno e inyecte el concepto del 30% automáticamente. |
| **Vacaciones y Bono (Arts. 190, 192)** | 15 días hábiles + 1 por año adicional. Bono de 15 días + 1 por año adicional. | 🟡 **Parcialmente Cumplido:** `conceptos.php` contempla el concepto de "vacaciones" de forma plana. | Implementar un cronograma o *Job* que calcule automáticamente los días acumulados basados en la `fecha_ingreso` del empleado. |
| **Utilidades (Arts. 131, 132)** | Mínimo 30 días, máximo 120 días sobre el salario promedio. | 🟡 **Parcialmente Cumplido:** `conceptos.php` tiene el tipo "Utilidades / Aguinaldos". | Falta el algoritmo que promedie los salarios devengados en el año (horas extras incluidas) para calcular el monto exacto por día. |
| **Prestaciones Sociales (Art. 142)** | 15 días trimestrales (Fideicomiso) vs. 30 días por año al final (Retroactivo). Se paga el mayor. | 🔴 **Pendiente:** El sistema actual no lleva el control de la Garantía de Prestaciones Sociales. | Es imperativo crear la tabla `prestaciones_sociales` y un motor trimestral que genere los 15 días de abono automáticamente con el **Salario Integral**. |
| **Salario Integral (Art. 122)** | Base = Salario Normal + Alícuota Utilidades + Alícuota Bono Vacacional. | 🔴 **Pendiente:** Los pagos se basan en tarifa plana. | Crear un motor que calcule la alícuota en tiempo real para usarla exclusivamente en Prestaciones. |

---

## 2. Hoja de Ruta de Desarrollo Recomendada

Dado el análisis anterior, para que el módulo de Nómina de **STARFI 2.0** sea 100% auditable y cumpla a cabalidad con la LOTTT sin riesgos de multas, recomiendo el siguiente plan de acción en orden de prioridad:

### Fase 1: Automatización de Recargos Básicos
1. Modificar `nomina/asistencia/back_asistencia.php` para calcular automáticamente el **Bono Nocturno (30%)** y el **Feriado (150%)**.
2. Agregar un validador en la asignación de turnos para que no excedan las 40h (Diurnas) o 35h (Nocturnas).

### Fase 2: Motor de Acumulados Legales
1. Crear una sub-pestaña "Acumulados" en el perfil del empleado (`empleados.php`).
2. Programar una rutina que calcule el **Salario Integral** dividiendo el acumulado de bonos del último año entre 360 días.

### Fase 3: Prestaciones Sociales (El más crítico)
1. Desarrollar la tabla `prestaciones_fideicomiso`.
2. Programar el "Disparador Trimestral": Cada 90 días, el sistema debe tomar el *Salario Integral* de ese mes, multiplicarlo por 15 y guardarlo en el libro contable de prestaciones del trabajador.

> *Este documento debe ser usado como referencia técnica principal al momento de realizar actualizaciones en los motores de pago (back_nomina.php).*

---

## 3. Análisis de Anticipos y Préstamos (Nómina vs. Caja)

He analizado los procesos actuales en `nomina/prestamos/` (`back_anticipos.php` y `back_prestamos.php`) para evaluar la viabilidad de su integración con el módulo de **Caja**.

### Estado del Proceso Actual
1.  **Anticipos:** Se basan en una tarifa diaria por días transcurridos. El flujo termina cuando el administrador marca como "FINALIZADO". No hay movimiento contable real.
2.  **Préstamos:** Se basan en cupos por antigüedad y salario. El flujo queda en "ACTIVO" tras la aprobación. La amortización se lleva en una tabla interna de nómina.

### Viabilidad de Integración con "Caja"
La integración es **altamente viable y recomendada** para garantizar la integridad del flujo de efectivo. Actualmente existe un "divorcio" entre la aprobación administrativa y el desembolso físico del dinero.

### Propuesta de Flujo Integrado (Liquidación en Caja)

1.  **Aprobación en Nómina:** El administrador de RRHH aprueba la solicitud. El estatus cambia a `PENDIENTE DE LIQUIDACIÓN`.
2.  **Módulo de Caja (Nueva Sección):** Se propone crear una vista en el módulo de Caja llamada **"Egresos de Nómina"**. 
    *   El cajero visualiza las solicitudes aprobadas por RRHH.
    *   Al hacer clic en "Liquidar", el cajero elige el método (Efectivo/Zelle).
3.  **Ejecución Contable Automatizada:** 
    *   Al liquidar desde Caja, el sistema debe crear automáticamente un registro en la tabla de egresos (`caja_gastos` o similar) con la categoría "PRÉSTAMO EMPLEADO".
    *   Simultáneamente, el estatus en el módulo de Nómina cambia a `ACTIVO` (Préstamo) o `PAGADO` (Anticipo).
    *   Se genera automáticamente un **Comprobante de Egreso** para firma del trabajador.

### Beneficios Técnicos
*   **Cuadre de Caja:** Evita discrepancias entre el dinero físico y los registros administrativos.
*   **Trazabilidad:** Permite saber exactamente quién entregó el dinero, a qué hora y desde qué caja.
*   **Seguridad:** RRHH aprueba el *derecho* al préstamo, pero Tesorería/Caja controla el *flujo* del dinero.

> [!TIP]
> Podemos empezar creando un *endpoint* en `back_caja.php` que reciba el `id_prestamo` y genere el egreso contable vinculando ambas bases de datos.
