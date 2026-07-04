# Documentación de Validación Dual: Prevención de Déficit en Billetera de Clientes

## Contexto y Problema
En el flujo tradicional del Sistema de Caja (STARFI 2.0), los pagos anexados con billetera virtual (USD ID: 12 y VES ID: 13) se empujaban confiando en el dato insertado manualmente en el frontend, lo cual creaba fallas de "Déficit Contable" por fondos menores a los solicitados, o errores nulos al descontar el Kardex de saldo de billetera (`caja_billetera_saldos` y `caja_billetera_kardex`). 

Para garantizar consistencia bancaria, se requiere una **Validación Cero Confianza (Zero-Trust)**:
1. **Frontend (En caliente)**: Prevención de carga y mejora de UX.
2. **Backend (Security Guard)**: Cerrar las tuberías de transacción SQL a nivel lógico si un endpoint es invocado bajo inyección.

---

## 1. Implementación Frontend (`funciones_caja.js`)

Se inyectó una verificación AJAX del saldo antes de que el pago ingrese al arreglo local en memoria (`pagos[]`, `abonosPosterior[]`, `abonosCXC[]`).

### Endpoint Consolidado
Se reutiliza la ruta: `back_admin_billeteras.php?action=GET_SALDO_POR_DOCUMENTO&id_documento={ID}&modulo={MODULO}` donde la API de tesorería cruza el cliente del documento actual y resuelve sus saldos `usd` y `ves`.

### Procesos Intervenidos:
1. **Facturación Inmediata (`#form-add-pago`)**: Previene la inyección al grifo inicial.
2. **Facturación Posterior (`#form-posterior-pago`)**: Cubre escenarios tardíos de cobro asíncrono.
3. **Abonos CxC (`#form-abono-cxc`)**: Previene el amortizado falso de balances de deuda.

### Tolerancia y Conversión:
A diferencia del bolsillo USD que se contrasta `USD vs USD`, cuando se usa Billetera VES (ID 13), el sistema evalúa equivalentemente: `requerido = monto_ves / TASA_BCV` comparando contra la cuota nativa alojada en el `saldo_ves`.
Se estableció un margen de error tolerante por *coma flotante* (`+ 0.01`).

---

## 2. Implementación Backend (`back_caja.php`)

Se agregó el Security Gate interponiéndose inmediatamente antes de inicializar la tubería final (`TRY { getDbConnection('caja')->begin_transaction() }`).

### Patrón Pre-Flight Billetera:
Si en la matriz codificada de `$pagos` se detecta algún método 12 o 13, el bloque detiene la ejecución, instancia globalmente a la clase `WalletManager` (ubicada en `services/WalletManager.php`) y compara el requerimiento real evaluado.

#### Endpoints Asegurados (Controladores Centrales)
No basta con asilar el formulario Frontend, ya que atacantes o fallos en red pueden forzar una escritura directa en el backend. Los endpoints donde se escribió el bloque son:
* `accion === 'facturar'`
* `accion === 'procesar_factura_posterior'`
* `accion === 'save_pagos_cxc'`

```php
// Ejemplo Arquitectónico Abstracto del Guard
require_once __DIR__ . '/services/WalletManager.php';
$wm = new WalletManager($con);
$saldoUsd = $wm->getBalance($idCliente);

if ($requerido_usd > ($saldoUsd + 0.01)) {
    echo json_encode(['status' => 'error', 'message' => "¡Déficit Billetera! Requiere $$requerido_usd y posee $$saldoUsd."]);
    exit; // Frena MySQL Begin Transaction.
}
```

## Pruebas Mínimas de Regresión a Futuro
Al realizar modificaciones futuras en métodos en `funciones_caja.js`, se deben garantizar las siguientes reglas de negocio:
1. Ningún botón `.submit` puede procesar y meter al DOM inmediatamente si el `metodo_pago` es `12` o `13`. **Debe ser asíncrono.**
2. La instancia `$wm = new WalletManager($con)` requiere la Base de Datos `Caja`, por lo tanto debe pasársele `getDbConnection('caja')` explícitamente y tener disponible la ID numérica limpia del cliente resolviéndose mediante los ID documentales y tablas de Venta / Facturación cruzadas.
