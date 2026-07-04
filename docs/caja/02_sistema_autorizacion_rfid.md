# Sistema de Autorización RFID (STARFI 2.0)

Este documento describe cómo integrar, utilizar y auditar el sistema de Autorización con Tarjetas RFID en cualquier módulo del ecosistema STARFI 2.0 (Valija, Inventario, Pedidos, etc.).

## 1. Arquitectura del Sistema

El sistema utiliza un enfoque Cliente-Servidor Desacoplado con auditoría pasiva:
1. **Frontend (`solicitarAutorizacionRfid`)**: Muestra el modal invasivo con Focus-Trap, captura el hash y se bloquea hasta obtener respuesta.
2. **Backend de Validación (`ValidacionToken.php`)**: Compara el hash MD5 contra la tabla `usuario_autorizacion` (`con_core`).
3. **Historial de Auditoría (`log_autorizaciones_rfid`)**: Guarda un registro en la base de datos `core` inmediatamente después de una validación exitosa en Frontend.

---

## 2. Inyección de Código (Frontend)

Para integrar la validación en un nuevo módulo, solo necesitas copiar y pegar el wrapper de Javascript. La función envolverá cualquier acción sensible y ejecutará tu lógica solo si se otorga el acceso.

```javascript
// 1. Identificar la variable de proceso según admin_procesos_token
let codigoDeSeguridad = 'INVENTARIO_ELIMINAR_KARDEX';

// 2. Envolver tu función
solicitarAutorizacionRfid(codigoDeSeguridad, function(id_autorizador) {
    
    // Este código SÓLO se ejecutará si la tarjeta es válida y tiene permisos
    let dataAjax = new FormData();
    dataAjax.append('accion', 'delete_kardex');
    dataAjax.append('id_autorizador_rfid', id_autorizador);
    
    fetch('back_inventario.php', { method: "POST", body: dataAjax })
       .then(r => r.json())
       .then(res => {
           // Éxito real!
       });
       
});
```

> [!TIP]
> Si en el Archivo `back_XXX.php` envías un `$codigoDeSeguridad` que no existe en la base de datos, o tiene `requiere_token = 0`, el sistema dejará pasar la acción SIN MOSTRAR el modal. Así funciona por diseño para mantener fluidez.

---

## 3. Registrar Mecanismos Nuevos en Base de Datos

Cada nuevo mecanismo debe existir en la tabla `admin_procesos_token` (Base de Datos: `caja` o la correspondiente del módulo si se migra en el futuro).

| Campo | Tipo | Ejemplo | Descripción |
|---|---|---|---|
| `codigo_proceso` | VARCHAR(100) | `CAJA_ANULAR_DOCUMENTO` | El string exacto usado en Javascript. |
| `titulo` | VARCHAR(100) | `Anulación de Recibo` | Título legible para el dashboard de Admin. |
| `requiere_token` | INT(1) | `1` o `0` | `1` = Requiere Tarjeta, `0` = Abierto al público. |

---

## 4. Auditoría Automática (Historial)

Al realizar una validación exitosa desde `funciones_caja.js` u otros módulos usando su función equivalente, se inyecta pasivamente un log en la tabla global:

**Base de Datos**: `starfi2.0_core`
**Tabla**: `log_autorizaciones_rfid`

| id | id_autorizador | codigo_proceso | detalles | ip_origen | fecha_hora |
|---|---|---|---|---|---|
| 1 | 48 (Administrador) | `CAJA_ANULAR_DOCUMENTO` | Autorizado vía UI (Caja) | 127.0.0.1 | `2026-03-31 16:50:00` |

### Registrar Logs Customizados desde PHP (Backend)

Si deseas registrar rastro de un evento particular (Ej: El usuario anuló la factura "N°1234"), puedes invocar directamente el archivo Core desde tu PHP backend:

```php
require_once __DIR__ . '/../../core/ValidacionToken.php';

// Asume que tu POST recibió el $id_autorizador que JS le pasó
registrar_auditoria_rfid(
    $id_autorizador_rfid, 
    'CAJA_ANULAR_DOCUMENTO', 
    'Se anuló la factura #1234 por motivos de devolución'
);
```

## 5. Visualizadores y Reportes (UI)

Para auditar y monitorear la actividad de autorizaciones RFID registradas en la tabla log_autorizaciones_rfid, el ecosistema de STARFI 2.0 cuenta con dos interfaces gerenciales disponibles:

### A. Panel de Monitoreo Local (Módulo Caja)
- **Ubicación:** Dentro de las configuraciones de seguridad (dmin_caja.php).
- **Propósito:** Mostrar las 50 transacciones más recientes atadas al entorno actual (CAJA_%), brindando al jefe de tienda un vistazo veloz en sitio sin abandonar sus labores.
- **Backend:** Usa JQuery AJAX contra ack_admin_caja.php (acción: cargar_auditoria_caja).

### B. Módulo Forense / Gerencial (Global)
- **Ubicación:** /modules/auditoria/auditoria_rfid.php 
- **Propósito:** Ofrece una pantalla inmersiva e independiente con filtrado multi-paramétrico (Fechas, Supervisor responsable y el tipo de proceso desbloqueado).
- **Backend:** Accede transversalmente a la base de datos core (getDbConnection('core')) mediante consultas PHP puras para generar reportes profundos sobre cualquier suceso biométrico o por tarjeta.
