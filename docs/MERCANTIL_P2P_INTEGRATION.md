# Integración P2P - API Mercantil Banco (STARFI 2.0)

## Propósito
Este documento detalla el comportamiento oficial, las reglas estructurales y los candados de seguridad implementados en STARFI 2.0 para la verificación de Pagos Móviles (P2P), específicamente para lidiar con las particularidades de la API de Mercantil y evitar el error **0180 (Número de Factura en Nulo)**.

## Diferencia Clave: P2P vs C2P
A diferencia de los ejemplos oficiales en la documentación de Mercantil (que cubren el flujo C2P o "Cobro a Persona"), en los flujos P2P es **el cliente quien origina los fondos** y **nuestro comercio quien los recibe**. 

Debido a esto, la asignación en la trama JSON DEBE ser:
- `origin_mobile_number`: El número de teléfono del Cliente (Pagador).
- `destination_mobile_number`: El número de teléfono de la Empresa (Beneficiario / MERCANTIL-GLOBAL).

Invertir estos números asumiendo la lógica C2P causará indefectiblemente un rechazo del pago.

## El Error "0180: Numero de factura en nulo"
El error *0180* es la respuesta de fallo genérica (fallback) que dispara el parser JSON de Mercantil en dos casos clave:
1. **La transacción pertenece a otro banco (ej. Banesco) y la estructura de los datos enviada no es matemáticamente idéntica.**
2. **Se produce una ruptura (crash) del parser del Banco por formato incorrecto.**

### Resolución del Error (La regla del Y-m-d)
La causa raíz documentada en nuestra auditoría que provocaba el error fantasma `0180` en interbancarios era **el formato de fecha latino (`d/m/Y`)**.
El parser de búsqueda de Mercantil entra en error crítico cuando procesa las barras diagonales (`/`) para Pagos P2P foráneos, resultando en un 0180 falso.

**Estándar Obligatorio STARFI 2.0:**
Toda petición de búsqueda de Pagos Móviles DEBE pasar la fecha en estándar ISO 8601 estricto (`Y-m-d`) (Ej: `2026-04-23`). Del mismo modo, el número de teléfono debe enviarse estrictamente en formato de 12 dígitos, prefijado por `58` (Ej: `58414XXXXXXX`), excluyendo el cero local.

## Candados Anti-Fraude (Falsos Positivos)
La validación consta de cuatro candados verificables implementados en `PagoMovilService.php` y `back_api_admin.php`:

1. **Cruce Estricto de Teléfonos (Origin vs Destination):**
   La API no devuelve transacciones "sueltas" aleatorias. Los parámetros de encriptado bloquean los intentos si el Destino del voucher no pertenece a la credencial asociada al `$config_id` seleccionado en la interfaz.

2. **Validación Algorítmica de Monto:**
   El motor recupera `$trx['amount']` devuelto de manera confiable por el banco. Nuestro código le aplica un parseo (`floatval`) y lo contrasta localmente contra el monto tipeado (`$requestedAmount`). Cualquier discrepancia abortará el proceso.

3. **Status de Aprobación:**
   Se descartan transacciones latentes, congeladas o revertidas filtrando explícitamente aquellas cuyo `trx_status` no sea estrictamente `approved`.

4. **Validación Estricta de Referencia por Fracción (Sufijo) [NUEVO PARCHE]:**
   Previene **Ataques de Replay** y *Falso Positivos*. Si la API del banco realiza una búsqueda difusa y retorna un pago basándose solo en teléfono y monto, este candado exige que el número de referencia retornado por el banco **termine exactamente** en los dígitos ingresados por el cajero (generalmente 4 dígitos para P2P o 8 para Transferencias). Si el sufijo no coincide (ignorando ceros a la izquierda), se rechaza.

5. **Barrera Anti-Duplicidad Local (WalletManager):**
   El método `$wallet->checkDuplicateReference()` barre las tablas de documentos y notas basándose de la tupla única generada entre: P2P, la referencia numérica y el banco emisor. Esto impide inyectar la misma referencia cobrada por el banco múltiples veces en distintos turnos de caja.

## Archivos de Integración Principal
- **Core / Formateador:** `modules/api_admin/back_api_admin.php`
- **Generador Payload / Encrypter:** `modules/caja/services/PagoMovilService.php`
- **Configurador Físico / Keys:** `modules/caja/services/ConfigPagoMovil.php`
