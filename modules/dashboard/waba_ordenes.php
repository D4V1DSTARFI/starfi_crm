<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();

$agente = getAgenteInfo();
$es_master = (($agente['rol'] ?? '') === 'MASTER');
$id_sede_agente = intval($agente['id_sede'] ?? 0);

// Permitir acceso a usuarios con permiso waba_ordenes, whatsapp_analytics o pertenecientes a una sede activa [FAC-04]
if (!$es_master && !hasPermission('waba_ordenes') && !hasPermission('whatsapp_analytics') && $id_sede_agente <= 0) {
    requirePermission('waba_ordenes');
}

$con = getDbConnection();
if ($es_master) {
    $res_sedes = $con->query("SELECT id, nombre_sede FROM sedes WHERE estado = 'ACTIVO' ORDER BY nombre_sede ASC");
} else {
    $res_sedes = $con->query("SELECT id, nombre_sede FROM sedes WHERE id = $id_sede_agente LIMIT 1");
}

$sedes = [];
if ($res_sedes) {
    while ($row = $res_sedes->fetch_assoc()) {
        $sedes[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Órdenes de Cobro WABA | STARFI CRM</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <!-- Bootstrap CSS Local -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; margin: 0; padding: 0; overflow-x: hidden; }
        .app-container { min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; padding: 20px; }
        .kpi-card {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }
        .kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .kpi-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 700;
        }
        .kpi-value {
            font-size: 1.55rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        .comprobante-preview-box {
            max-height: 360px;
            overflow: auto;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #f8fafc;
            padding: 10px;
            text-align: center;
        }
        .comprobante-preview-box img {
            max-width: 100%;
            max-height: 320px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <?php renderHeader('Panel de Órdenes WABA'); ?>
    <div class="app-container">
    <main class="main-content">

<div class="container-fluid mt-2">
    <!-- Cabecera y Navegación Directa [FAC-05] -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div>
            <h2 class="mb-0 fw-bold" style="font-size: 1.45rem; color: #1e293b;">
                <i class="fas fa-file-invoice-dollar text-primary me-2"></i>
                <?= $es_master ? 'Panel Administrativo - Órdenes de Cobro WhatsApp' : 'Mis Órdenes de Cobro WhatsApp API' ?>
            </h2>
            <small class="text-muted">
                <?= $es_master ? 'Control de facturación, conciliación de pagos y auditoría financiera por sede.' : 'Consulta de estados de cuenta, desglose de consumo y reporte de pagos.' ?>
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="whatsapp_analytics.php" class="btn btn-primary shadow-sm">
                <i class="fas fa-chart-line me-1"></i> Estadísticas WABA
            </a>
            <a href="../../index.php" class="btn btn-outline-secondary bg-white shadow-sm">
                <i class="fas fa-arrow-left me-1"></i> Volver al Inicio
            </a>
        </div>
    </div>

    <!-- [FAC-01] Dashboard Financiero: 4 Tarjetas KPI (Margen Neto exclusivo MASTER) -->
    <div class="row g-3 mb-4">
        <div class="<?= $es_master ? 'col-md-6 col-xl-3' : 'col-md-4' ?>">
            <div class="kpi-card p-3 shadow-sm h-100" style="border-left: 4px solid #2563eb;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="kpi-label">Total Facturado</div>
                        <div class="kpi-value mt-1" id="kpiTotalFacturado">$0.00</div>
                        <small class="text-muted" style="font-size: 0.75rem;">Acumulado del filtro activo</small>
                    </div>
                    <div class="kpi-icon" style="background: rgba(37, 99, 235, 0.1); color: #2563eb;">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="<?= $es_master ? 'col-md-6 col-xl-3' : 'col-md-4' ?>">
            <div class="kpi-card p-3 shadow-sm h-100" style="border-left: 4px solid #16a34a;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="kpi-label">Total Cobrado</div>
                        <div class="kpi-value mt-1 text-success" id="kpiTotalCobrado">$0.00</div>
                        <small class="text-muted" style="font-size: 0.75rem;">Pagos verificados (PAGADO)</small>
                    </div>
                    <div class="kpi-icon" style="background: rgba(22, 163, 74, 0.1); color: #16a34a;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="<?= $es_master ? 'col-md-6 col-xl-3' : 'col-md-4' ?>">
            <div class="kpi-card p-3 shadow-sm h-100" style="border-left: 4px solid #d97706;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="kpi-label">Por Cobrar</div>
                        <div class="kpi-value mt-1 text-warning" id="kpiPorCobrar" style="color: #d97706 !important;">$0.00</div>
                        <small class="text-muted" id="kpiEnRevisionSub" style="font-size: 0.75rem;">En revisión: $0.00</small>
                    </div>
                    <div class="kpi-icon" style="background: rgba(217, 119, 6, 0.1); color: #d97706;">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($es_master): ?>
        <div class="col-md-6 col-xl-3">
            <div class="kpi-card p-3 shadow-sm h-100" style="border-left: 4px solid #7c3aed;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="kpi-label">Margen Neto CRM <span class="badge bg-dark ms-1" style="font-size:0.65rem;">MASTER</span></div>
                        <div class="kpi-value mt-1" id="kpiMargenNeto" style="color: #7c3aed;">$0.00</div>
                        <small class="text-muted" id="kpiMargenCobradoSub" style="font-size: 0.75rem;">Margen cobrado: $0.00</small>
                    </div>
                    <div class="kpi-icon" style="background: rgba(124, 58, 237, 0.1); color: #7c3aed;">
                        <i class="fas fa-sack-dollar"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Barra de Filtros con Filtro por Estado [FAC-01] -->
    <div class="card shadow-sm mb-3 border-0">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label mb-1 fw-semibold small text-secondary">Sede / Sucursal</label>
                    <select id="filter_sede" class="form-select" <?= !$es_master ? 'disabled' : '' ?>>
                        <?php if ($es_master): ?>
                            <option value="">Todas las Sedes</option>
                        <?php endif; ?>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (!$es_master && intval($s['id']) === $id_sede_agente) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['nombre_sede']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1 fw-semibold small text-secondary">Estado</label>
                    <select id="filter_estado" class="form-select">
                        <option value="">Todos los Estados</option>
                        <option value="PENDIENTE">PENDIENTE</option>
                        <option value="EN_REVISION">PAGADA (PEND. REVISIÓN)</option>
                        <option value="PAGADO">PAGADO (VERIFICADO)</option>
                        <option value="FUSIONADO">FUSIONADO</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1 fw-semibold small text-secondary">Fecha Desde</label>
                    <input type="date" id="filter_fecha_desde" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label mb-1 fw-semibold small text-secondary">Fecha Hasta</label>
                    <input type="date" id="filter_fecha_hasta" class="form-control">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button id="btnFiltrar" class="btn btn-primary w-100 fw-semibold"><i class="fas fa-search me-1"></i>Buscar</button>
                    <button id="btnLimpiar" class="btn btn-outline-secondary w-100"><i class="fas fa-eraser me-1"></i>Limpiar</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla Principal de Órdenes con Blindaje de Privacidad [FAC-04] -->
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle mb-0" id="tablaOrdenes">
                    <thead class="table-dark">
                        <tr>
                            <th># Orden</th>
                            <th>Sede / Sucursal</th>
                            <th>Periodo</th>
                            <th>Mensajes</th>
                            <?php if ($es_master): ?>
                                <th>Costo Base Meta</th>
                                <th>Margen CRM</th>
                                <th>Total a Cobrar</th>
                            <?php else: ?>
                                <th>Total a Pagar</th>
                            <?php endif; ?>
                            <th>Estado</th>
                            <th>Método / Ref.</th>
                            <?php if ($es_master): ?>
                                <th>Notificaciones</th>
                            <?php endif; ?>
                            <th class="text-end">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div class="d-flex justify-content-between align-items-center mt-3" id="paginationContainer" style="display:none !important;">
                <span class="text-muted small" id="lblTotalRegistros">Mostrando 0 registros</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="ulPagination">
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Modal Detalles de Orden [FAC-04] -->
<div class="modal fade" id="modalDetalleOrden" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-list-check text-primary me-2"></i>Desglose de Orden #<span id="lblOrdenId"></span>
                    <small class="text-muted ms-2 fw-normal" id="lblOrdenSede"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle" id="tablaDesglose">
                        <thead class="table-light">
                            <tr>
                                <th>Concepto / Categoría</th>
                                <th class="text-center">Volumen</th>
                                <th class="text-center">Gratuitos</th>
                                <th class="text-center">Pagados</th>
                                <?php if ($es_master): ?>
                                    <th class="text-end">Costo Base Meta</th>
                                <?php endif; ?>
                                <th class="text-end">Precio Unit. Final</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>

                <?php if ($es_master): ?>
                <div class="row g-2 mt-2 p-2 bg-light rounded border">
                    <div class="col-md-4 text-center border-end">
                        <div class="small text-muted fw-semibold">Costo Base Meta</div>
                        <div class="fw-bold text-secondary fs-6" id="lblDetalleCostoMeta">$0.00 USD</div>
                    </div>
                    <div class="col-md-4 text-center border-end">
                        <div class="small text-muted fw-semibold">Margen Administrativo CRM</div>
                        <div class="fw-bold text-info fs-6" id="lblDetalleMargen">$0.00 USD</div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="small text-muted fw-semibold">Total a Cobrar</div>
                        <div class="fw-bold text-primary fs-5" id="lblTotalCobrar">$0.00 USD</div>
                    </div>
                </div>
                <?php else: ?>
                <div class="mt-3 text-end">
                    <h5 class="mb-0">Total a Pagar: <span id="lblTotalCobrar" class="text-primary fw-bold"></span> USD</h5>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- [FAC-02] Modal Registro / Reporte de Pago -->
<div class="modal fade" id="modalPagoOrden" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="formPagoOrden" enctype="multipart/form-data">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-money-check-dollar me-2"></i>
                        <?= $es_master ? 'Registrar / Confirmar Pago de Orden' : 'Reportar Pago de Orden' ?> #<span id="lblPagoOrdenId"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="register_payment">
                    <input type="hidden" name="id_orden" id="pago_id_orden">

                    <div class="alert alert-light border d-flex justify-content-between align-items-center py-2 mb-3">
                        <div>
                            <small class="text-muted d-block">Sede:</small>
                            <strong id="lblPagoSedeNombre">-</strong>
                        </div>
                        <div class="text-end">
                            <small class="text-muted d-block">Monto de la Orden:</small>
                            <strong class="text-primary fs-5" id="lblPagoMontoTotal">$0.00 USD</strong>
                        </div>
                    </div>

                    <?php if (!$es_master): ?>
                    <div class="alert alert-info py-2 small">
                        <i class="fas fa-info-circle me-1"></i> Al enviar los datos y el comprobante, su orden pasará a estado <strong>Pagada (Pendiente por Revisión)</strong> para que el equipo administrativo valide el ingreso.
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Método de Pago <span class="text-danger">*</span></label>
                            <select name="metodo_pago" id="pago_metodo" class="form-select" required>
                                <option value="">Seleccione la forma de pago...</option>
                                <option value="Binance">Binance (USDT / Binance Pay)</option>
                                <option value="Zelle">Zelle (USD)</option>
                                <option value="Pago Móvil">Pago Móvil (Bs.)</option>
                                <option value="Transferencia">Transferencia Bancaria</option>
                                <option value="Efectivo">Efectivo (Divisas / Taquilla)</option>
                            </select>
                        </div>
                    </div>

                    <div id="avisoSeleccionarMetodo" class="text-center text-muted border rounded-3 py-4 mt-3 bg-light">
                        <i class="fas fa-hand-pointer fa-lg mb-2 d-block text-secondary"></i>
                        <span class="small">Seleccione primero el <strong>Método de Pago</strong> para desplegar los campos requeridos.</span>
                    </div>

                    <div id="bloqueCamposDinamicosPago" class="row g-3 mt-1" style="display: none;">
                        <!-- Campo dinámico: Banco Emisor (Pago Móvil / Transferencia) -->
                        <div class="col-md-6" id="wrapBanco" style="display: none;">
                            <label class="form-label fw-semibold small" id="lblPagoBanco">Banco Emisor <span class="text-danger">*</span></label>
                            <select name="banco_emisor" id="pago_banco_emisor" class="form-select">
                                <option value="">Seleccione banco...</option>
                                <option value="Banesco (0134)">Banesco (0134)</option>
                                <option value="Banco de Venezuela (0102)">Banco de Venezuela (0102)</option>
                                <option value="Mercantil (0105)">Mercantil (0105)</option>
                                <option value="BBVA Provincial (0108)">BBVA Provincial (0108)</option>
                                <option value="BNC (0191)">BNC (0191)</option>
                                <option value="Bancamiga (0172)">Bancamiga (0172)</option>
                                <option value="Banplus (0174)">Banplus (0174)</option>
                                <option value="BFC (0151)">BFC Fondo Común (0151)</option>
                                <option value="Venezolano de Crédito (0104)">Venezolano de Crédito (0104)</option>
                                <option value="Bancaribe (0114)">Bancaribe (0114)</option>
                                <option value="Banco Exterior (0115)">Banco Exterior (0115)</option>
                                <option value="Otro Banco">Otro Banco</option>
                            </select>
                        </div>

                        <!-- Campo dinámico: Referencia / TxID / Confirmación -->
                        <div class="col-md-6" id="wrapReferencia">
                            <label class="form-label fw-semibold small" id="lblPagoReferencia">Referencia / TxID <span class="text-danger">*</span></label>
                            <input type="text" name="referencia_pago" id="pago_referencia" class="form-control" placeholder="Ej: 984512369 / TxID">
                        </div>

                        <!-- Campo dinámico: Titular / Correo / Entregado por -->
                        <div class="col-md-6" id="wrapTitular" style="display: none;">
                            <label class="form-label fw-semibold small" id="lblPagoTitular">Titular / Cuenta Emisora <span class="text-danger">*</span></label>
                            <input type="text" name="titular_emisor" id="pago_titular_emisor" class="form-control" placeholder="Nombre del titular o correo">
                        </div>

                        <!-- Campo dinámico: Teléfono y Cédula/RIF (Pago Móvil) -->
                        <div class="col-md-6" id="wrapTelefonoEmisor" style="display: none;">
                            <label class="form-label fw-semibold small" id="lblPagoTelefono">Teléfono y Cédula/RIF Emisor <span class="text-danger">*</span></label>
                            <input type="text" name="telefono_emisor" id="pago_telefono_emisor" class="form-control" placeholder="Ej: 0414-1234567 / V-12345678">
                        </div>

                        <!-- Campo dinámico: Monto en Bolívares (Pago Móvil / Transferencia) -->
                        <div class="col-md-6" id="wrapMontoBs" style="display: none;">
                            <label class="form-label fw-semibold small" id="lblPagoMontoBs">Monto Pagado en Bs. <span class="text-muted fw-normal">(Opcional)</span></label>
                            <input type="text" name="monto_bs" id="pago_monto_bs" class="form-control" placeholder="Ej: 1.450,00 Bs">
                        </div>

                        <!-- Fecha del Pago -->
                        <div class="col-md-6" id="wrapFechaPago">
                            <label class="form-label fw-semibold small">Fecha del Pago <span class="text-danger">*</span></label>
                            <input type="date" name="fecha_pago" id="pago_fecha" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <?php if ($es_master): ?>
                        <div class="col-md-6" id="wrapEstadoDestino">
                            <label class="form-label fw-semibold small">Estado Inicial del Reporte</label>
                            <select name="estado_destino" id="pago_estado_destino" class="form-select">
                                <option value="EN_REVISION" selected>PAGADA - PEND. REVISIÓN (Verificar ingreso luego)</option>
                                <option value="PAGADO">PAGADO (Confirmar directo)</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <label class="form-label fw-semibold small" id="lblPagoComprobante">Comprobante Adjunto (Imagen o PDF)</label>
                            <input type="file" name="comprobante" id="pago_comprobante" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
                            <small class="text-muted">Formatos aceptados: JPG, PNG, WEBP o PDF (Máx. 10MB).</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold small">Notas / Observaciones Adicionales</label>
                            <textarea name="notas_pago" id="pago_notas" class="form-control" rows="2" placeholder="Comentarios adicionales sobre el pago..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold" id="btnSubmitPago">
                        <i class="fas fa-paper-plane me-1"></i> <?= $es_master ? 'Guardar Pago' : 'Enviar Reporte de Pago' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- [FAC-02] Modal Verificación / Consulta de Comprobante y Pago -->
<div class="modal fade" id="modalVerPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-receipt me-2"></i>Soporte y Verificación de Pago — Orden #<span id="verPagoOrdenId"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <div class="p-3 bg-light rounded border h-100">
                            <h6 class="fw-bold text-primary border-bottom pb-2 mb-3">Datos Reportados</h6>
                            <p class="mb-2"><strong>Sede:</strong> <span id="verPagoSede">-</span></p>
                            <p class="mb-2"><strong>Monto:</strong> <span class="fw-bold text-success" id="verPagoMonto">$0.00 USD</span></p>
                            <p class="mb-2"><strong>Estado Actual:</strong> <span id="verPagoEstadoBadge"></span></p>
                            <p class="mb-2"><strong>Método de Pago:</strong> <span id="verPagoMetodo">-</span></p>
                            <p class="mb-2"><strong>Referencia / TxID:</strong> <code class="fs-6" id="verPagoReferencia">-</code></p>
                            <p class="mb-2"><strong>Fecha de Pago:</strong> <span id="verPagoFecha">-</span></p>
                            <div class="mt-2">
                                <strong>Notas:</strong>
                                <div class="p-2 bg-white border rounded small mt-1" id="verPagoNotas" style="white-space: pre-wrap; min-height: 48px;">-</div>
                            </div>

                            <?php if ($es_master): ?>
                            <div id="masterVerifyPanel" class="mt-3 pt-3 border-top">
                                <label class="form-label small fw-bold text-dark">Control de Auditoría MASTER:</label>
                                <textarea id="txtNotaVerificacion" class="form-control form-control-sm mb-2" rows="2" placeholder="Ej: Fondos confirmados en cuenta Binance / Zelle..."></textarea>
                                <div class="d-grid gap-2">
                                    <button type="button" class="btn btn-success btn-sm fw-bold" id="btnAprobarPagoMaster">
                                        <i class="fas fa-check-double me-1"></i> Confirmar Ingreso y Marcar PAGADO
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm fw-bold text-dark" id="btnPonerEnRevisionMaster" style="display:none;">
                                        <i class="fas fa-magnifying-glass-dollar me-1"></i> Pasar a PENDIENTE POR REVISIÓN
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm fw-semibold" id="btnRechazarPagoMaster">
                                        <i class="fas fa-rotate-left me-1"></i> Rechazar y Devolver a PENDIENTE
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <h6 class="fw-bold text-secondary mb-2">Comprobante Adjunto</h6>
                        <div class="comprobante-preview-box" id="verPagoComprobanteContainer">
                            <span class="text-muted">Sin comprobante adjunto</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <a href="#" target="_blank" id="btnImprimirReciboModal" class="btn btn-outline-primary">
                    <i class="fas fa-file-pdf me-1"></i> Descargar Recibo PDF
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<!-- [FAC-05] Assets JS Locales -->
<script src="../../assets/js/jquery-3.7.1.min.js"></script>
<script src="../../assets/js/bootstrap.bundle.min.js"></script>
<script src="../../assets/js/sweetalert2.all.min.js"></script>
<script>
$(document).ready(function() {
    const isMaster = <?= $es_master ? 'true' : 'false' ?>;
    let currentPage = 1;
    let currentOrdersMap = {};

    cargarOrdenes(1);

    $('#btnFiltrar').click(function() {
        cargarOrdenes(1);
    });

    $('#filter_estado, #filter_sede').change(function() {
        cargarOrdenes(1);
    });

    $('#btnLimpiar').click(function() {
        if (isMaster) {
            $('#filter_sede').val('');
        }
        $('#filter_estado').val('');
        $('#filter_fecha_desde').val('');
        $('#filter_fecha_hasta').val('');
        cargarOrdenes(1);
    });

    $(document).on('click', '.page-link-btn', function(e) {
        e.preventDefault();
        let page = $(this).data('page');
        if (page) {
            cargarOrdenes(page);
        }
    });

    function renderEstadoBadge(estado) {
        if (estado === 'PENDIENTE') {
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>PENDIENTE</span>';
        } else if (estado === 'EN_REVISION') {
            return '<span class="badge bg-info text-dark"><i class="fas fa-magnifying-glass-dollar me-1"></i>PAGADA (PEND. REVISIÓN)</span>';
        } else if (estado === 'PAGADO') {
            return '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>PAGADO</span>';
        } else if (estado === 'FUSIONADO') {
            return '<span class="badge bg-secondary"><i class="fas fa-code-merge me-1"></i>FUSIONADO</span>';
        }
        return `<span class="badge bg-light text-dark border">${estado}</span>`;
    }

    function cargarOrdenes(page = 1) {
        currentPage = page;

        let reqData = {
            action: 'get_orders',
            page: page,
            id_sede: $('#filter_sede').val(),
            estado: $('#filter_estado').val(),
            fecha_desde: $('#filter_fecha_desde').val(),
            fecha_hasta: $('#filter_fecha_hasta').val()
        };

        $.post('back_waba_billing.php', reqData, function(res) {
            if (res.status === 'success') {
                // Actualizar Tarjetas KPI [FAC-01]
                if (res.kpis) {
                    $('#kpiTotalFacturado').text('$' + parseFloat(res.kpis.total_facturado || 0).toFixed(2));
                    $('#kpiTotalCobrado').text('$' + parseFloat(res.kpis.total_cobrado || 0).toFixed(2));
                    $('#kpiPorCobrar').text('$' + parseFloat(res.kpis.por_cobrar || 0).toFixed(2));
                    $('#kpiEnRevisionSub').text('En revisión: $' + parseFloat(res.kpis.en_revision || 0).toFixed(2));
                    if (isMaster && res.kpis.margen_neto !== undefined) {
                        $('#kpiMargenNeto').text('$' + parseFloat(res.kpis.margen_neto || 0).toFixed(2));
                        $('#kpiMargenCobradoSub').text('Margen cobrado: $' + parseFloat(res.kpis.margen_cobrado || 0).toFixed(2));
                    }
                }

                let tbody = $('#tablaOrdenes tbody');
                tbody.empty();
                currentOrdersMap = {};

                let colSpan = isMaster ? 11 : 8;
                if (!res.data || res.data.length === 0) {
                    tbody.append(`<tr><td colspan="${colSpan}" class="text-center py-4 text-muted">No se encontraron órdenes para los filtros seleccionados</td></tr>`);
                    $('#paginationContainer').attr('style', 'display: none !important;');
                    return;
                }

                res.data.forEach(o => {
                    currentOrdersMap[o.id] = o;
                    let badge = renderEstadoBadge(o.estado);

                    let pagoResumen = '<span class="text-muted small">-</span>';
                    if (o.metodo_pago) {
                        pagoResumen = `<span class="fw-semibold small">${o.metodo_pago}</span><br><small class="text-muted">Ref: ${o.referencia_pago || '-'}</small>`;
                    }

                    let colsPrecios = '';
                    if (isMaster) {
                        let cMeta = parseFloat(o.costo_meta || 0).toFixed(2);
                        let cMargen = parseFloat(o.margen_ganancia || 0).toFixed(2);
                        let cTotal = parseFloat(o.monto_total || 0).toFixed(2);
                        colsPrecios = `
                            <td>$${cMeta}</td>
                            <td class="text-info fw-semibold">$${cMargen}</td>
                            <td class="fw-bold text-primary">$${cTotal}</td>
                        `;
                    } else {
                        let cTotal = parseFloat(o.monto_total || 0).toFixed(2);
                        colsPrecios = `<td class="fw-bold text-primary">$${cTotal}</td>`;
                    }

                    let colNotif = '';
                    if (isMaster) {
                        let notifBadge = o.notificacion_enviada > 0
                            ? `<span class="badge bg-info text-dark"><i class="fas fa-check-double"></i> Enviada (${o.notificacion_enviada})</span>`
                            : `<span class="badge bg-light text-dark border"><i class="fas fa-clock"></i> Pendiente</span>`;
                        colNotif = `<td>${notifBadge}</td>`;
                    }

                    // Botones de Acción con etiquetas visibles según estado y rol
                    let btns = `<a href="imprimir_orden_waba.php?id=${o.id}" target="_blank" class="btn btn-outline-secondary" title="Imprimir Recibo / Aviso PDF"><i class="fas fa-file-pdf me-1"></i>PDF</a>`;
                    btns += `<button class="btn btn-outline-info btn-detalle" data-id="${o.id}" title="Ver Desglose"><i class="fas fa-list me-1"></i>Desglose</button>`;

                    if (o.estado === 'PENDIENTE') {
                        btns += `<button class="btn btn-success text-white fw-semibold btn-pagar" data-id="${o.id}" title="Reportar / Registrar Pago"><i class="fas fa-hand-holding-dollar me-1"></i>Reportar Pago</button>`;
                    } else if (o.estado === 'EN_REVISION') {
                        if (isMaster) {
                            btns += `<button class="btn btn-warning text-dark fw-bold btn-ver-pago" data-id="${o.id}" title="Verificar Pago Reportado"><i class="fas fa-magnifying-glass-dollar me-1"></i>Verificar Pago</button>`;
                        } else {
                            btns += `<button class="btn btn-outline-dark btn-ver-pago" data-id="${o.id}" title="Ver Comprobante Enviado"><i class="fas fa-receipt me-1"></i>Ver Pago</button>`;
                            btns += `<button class="btn btn-outline-success btn-pagar" data-id="${o.id}" title="Actualizar Reporte de Pago"><i class="fas fa-pen me-1"></i>Editar Pago</button>`;
                        }
                    } else if (o.estado === 'PAGADO') {
                        btns += `<button class="btn btn-outline-dark fw-semibold btn-ver-pago" data-id="${o.id}" title="Ver Soporte y Auditar Pago"><i class="fas fa-receipt me-1"></i>Auditar Pago</button>`;
                    }

                    if (isMaster) {
                        if (o.estado === 'PENDIENTE') {
                            btns += `<button class="btn btn-outline-primary btn-notificar" data-id="${o.id}" title="Reenviar Notificación"><i class="fas fa-paper-plane"></i></button>`;
                        }
                        btns += `<button class="btn btn-outline-danger btn-eliminar" data-id="${o.id}" title="Eliminar Orden"><i class="fas fa-trash"></i></button>`;
                    }

                    let tr = `<tr>
                        <td><b>#${o.id}</b></td>
                        <td>${o.nombre_sede}</td>
                        <td>${o.fecha_desde} <br> <small class="text-muted">al ${o.fecha_hasta}</small></td>
                        <td>${o.mensajes_totales}</td>
                        ${colsPrecios}
                        <td>${badge}</td>
                        <td>${pagoResumen}</td>
                        ${colNotif}
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                ${btns}
                            </div>
                        </td>
                    </tr>`;
                    tbody.append(tr);
                });

                // Construir Paginación
                if (res.total_pages > 1) {
                    $('#paginationContainer').attr('style', 'display: flex !important;');
                    let totalItems = res.total_records || 0;
                    $('#lblTotalRegistros').text(`Mostrando pág ${res.current_page} de ${res.total_pages} (${totalItems} registros)`);

                    let ul = $('#ulPagination');
                    ul.empty();

                    let prevDisabled = (res.current_page == 1) ? 'disabled' : '';
                    ul.append(`<li class="page-item ${prevDisabled}"><a class="page-link page-link-btn" href="#" data-page="${res.current_page - 1}">Anterior</a></li>`);

                    for (let i = 1; i <= res.total_pages; i++) {
                        let active = (i == res.current_page) ? 'active' : '';
                        ul.append(`<li class="page-item ${active}"><a class="page-link page-link-btn" href="#" data-page="${i}">${i}</a></li>`);
                    }

                    let nextDisabled = (res.current_page == res.total_pages) ? 'disabled' : '';
                    ul.append(`<li class="page-item ${nextDisabled}"><a class="page-link page-link-btn" href="#" data-page="${res.current_page + 1}">Siguiente</a></li>`);
                } else {
                    $('#paginationContainer').attr('style', 'display: none !important;');
                }
            } else {
                Swal.fire('Error', res.message || 'No se pudieron cargar las órdenes', 'error');
            }
        }, 'json');
    }

    // Desglose de Orden con Blindaje de Privacidad [FAC-04]
    $(document).on('click', '.btn-detalle', function() {
        let id_orden = $(this).data('id');
        $('#lblOrdenId').text(id_orden);

        $.post('back_waba_billing.php', { action: 'get_order_details', id_orden: id_orden }, function(res) {
            if (res.status === 'success') {
                let s = res.summary || {};
                $('#lblOrdenSede').text(s.nombre_sede ? `(${s.nombre_sede})` : '');
                $('#lblTotalCobrar').text('$' + parseFloat(s.monto_total || 0).toFixed(2));

                if (isMaster) {
                    $('#lblDetalleCostoMeta').text('$' + parseFloat(s.costo_meta || 0).toFixed(2) + ' USD');
                    $('#lblDetalleMargen').text('$' + parseFloat(s.margen_ganancia || 0).toFixed(2) + ' USD');
                }

                let tbody = $('#tablaDesglose tbody');
                tbody.empty();
                let colsCount = isMaster ? 7 : 6;

                if (!res.data || res.data.length === 0) {
                    tbody.append(`<tr><td colspan="${colsCount}" class="text-center text-muted">No hay desglose detallado para esta orden</td></tr>`);
                } else {
                    res.data.forEach(d => {
                        let colCostoBase = isMaster ? `<td class="text-end text-muted">$${parseFloat(d.costo_base || 0).toFixed(4)}</td>` : '';
                        tbody.append(`<tr>
                            <td>
                                <strong>${d.nombre_plantilla}</strong>
                                <br><small class="text-muted">Categoría: ${d.categoria || 'MARKETING'}</small>
                            </td>
                            <td class="text-center">${d.volumen}</td>
                            <td class="text-center">${d.mensajes_gratuitos || 0}</td>
                            <td class="text-center">${d.mensajes_pagados || 0}</td>
                            ${colCostoBase}
                            <td class="text-end">$${parseFloat(d.precio_unitario_final || 0).toFixed(4)}</td>
                            <td class="text-end fw-bold">$${parseFloat(d.subtotal_final || 0).toFixed(2)}</td>
                        </tr>`);
                    });
                }
                $('#modalDetalleOrden').modal('show');
            } else {
                Swal.fire('Error', res.message || 'No se pudo obtener el detalle', 'error');
            }
        }, 'json');
    });

    // Lógica de Campos Dinámicos según Forma de Pago [FAC-02]
    function actualizarCamposMetodoPago(metodo) {
        if (!metodo) {
            $('#avisoSeleccionarMetodo').show();
            $('#bloqueCamposDinamicosPago').hide();
            $('#pago_referencia, #pago_banco_emisor, #pago_titular_emisor, #pago_telefono_emisor').prop('required', false);
            return;
        }

        $('#avisoSeleccionarMetodo').hide();
        $('#bloqueCamposDinamicosPago').fadeIn(150);

        // Resetear visibilidad y obligatoriedad de campos específicos
        $('#wrapBanco, #wrapTitular, #wrapTelefonoEmisor, #wrapMontoBs').hide();
        $('#pago_banco_emisor, #pago_titular_emisor, #pago_telefono_emisor, #pago_referencia').prop('required', false);
        $('#wrapReferencia').show();

        if (metodo === 'Binance') {
            $('#lblPagoReferencia').html('TxID / ID de Orden Binance <span class="text-danger">*</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: 284910293847102938 / TxID').prop('required', true);

            $('#wrapTitular').show();
            $('#lblPagoTitular').html('Correo o Binance ID Emisor <span class="text-danger">*</span>');
            $('#pago_titular_emisor').attr('placeholder', 'Ej: usuario@gmail.com o Pay ID').prop('required', true);

            $('#lblPagoComprobante').text('Captura de Pantalla Binance (Imagen o PDF)');
        } else if (metodo === 'Zelle') {
            $('#lblPagoReferencia').html('Código de Confirmación Zelle <span class="text-danger">*</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: 094812394 / Confirmation #').prop('required', true);

            $('#wrapTitular').show();
            $('#lblPagoTitular').html('Titular de la Cuenta / Correo Zelle <span class="text-danger">*</span>');
            $('#pago_titular_emisor').attr('placeholder', 'Ej: Juan Pérez (juan@email.com)').prop('required', true);

            $('#lblPagoComprobante').text('Captura del Comprobante Zelle (Imagen o PDF)');
        } else if (metodo === 'Pago Móvil') {
            $('#wrapBanco').show();
            $('#pago_banco_emisor').prop('required', true);

            $('#lblPagoReferencia').html('Número de Referencia <span class="text-danger">*</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: 004829103842 (últimos 6-12 dígitos)').prop('required', true);

            $('#wrapTelefonoEmisor').show();
            $('#lblPagoTelefono').html('Teléfono y Cédula/RIF Emisor <span class="text-danger">*</span>');
            $('#pago_telefono_emisor').attr('placeholder', 'Ej: 0414-1234567 / V-12345678').prop('required', true);

            $('#wrapMontoBs').show();
            $('#lblPagoComprobante').text('Captura de Pago Móvil (Imagen o PDF)');
        } else if (metodo === 'Transferencia') {
            $('#wrapBanco').show();
            $('#pago_banco_emisor').prop('required', true);

            $('#lblPagoReferencia').html('Número de Referencia / Operación <span class="text-danger">*</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: 984512369').prop('required', true);

            $('#wrapTitular').show();
            $('#lblPagoTitular').html('Titular / RIF de la Cuenta Emisora <span class="text-danger">*</span>');
            $('#pago_titular_emisor').attr('placeholder', 'Ej: Inversiones XYZ, C.A. / J-12345678-9').prop('required', true);

            $('#wrapMontoBs').show();
            $('#lblPagoComprobante').text('Comprobante de Transferencia (Imagen o PDF)');
        } else if (metodo === 'Efectivo') {
            $('#wrapTitular').show();
            $('#lblPagoTitular').html('Entregado por / Recibido por <span class="text-danger">*</span>');
            $('#pago_titular_emisor').attr('placeholder', 'Ej: Entregado por Gerente a Administración').prop('required', true);

            $('#lblPagoReferencia').html('Nº de Recibo o Seriales de Billetes <span class="text-muted fw-normal">(Opcional)</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: Recibo #045 / Seriales USD').prop('required', false);

            $('#lblPagoComprobante').text('Foto de Recibo o Billetes (Opcional)');
        }
    }

    $('#pago_metodo').on('change', function() {
        actualizarCamposMetodoPago($(this).val());
    });

    // Abrir Modal para Registrar / Reportar Pago [FAC-02]
    $(document).on('click', '.btn-pagar', function() {
        let id_orden = $(this).data('id');
        let o = currentOrdersMap[id_orden];
        if (!o) return;

        $('#formPagoOrden')[0].reset();
        $('#pago_id_orden').val(o.id);
        $('#lblPagoOrdenId').text(o.id);
        $('#lblPagoSedeNombre').text(o.nombre_sede);
        $('#lblPagoMontoTotal').text('$' + parseFloat(o.monto_total || 0).toFixed(2) + ' USD');

        if (o.metodo_pago) $('#pago_metodo').val(o.metodo_pago);
        if (o.referencia_pago && o.referencia_pago !== 'Entrega en Efectivo') {
            $('#pago_referencia').val(o.referencia_pago);
        }
        if (o.fecha_pago) $('#pago_fecha').val(o.fecha_pago.substring(0, 10));
        if (o.notas_pago) $('#pago_notas').val(o.notas_pago);

        actualizarCamposMetodoPago($('#pago_metodo').val());
        $('#modalPagoOrden').modal('show');
    });

    // Envío de Formulario de Pago con Comprobante Adjunto [FAC-02]
    $('#formPagoOrden').submit(function(e) {
        e.preventDefault();
        let formData = new FormData(this);

        Swal.fire({
            title: 'Procesando pago...',
            text: 'Guardando datos y comprobante adjunto',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });

        $.ajax({
            url: 'back_waba_billing.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function(res) {
                if (res.status === 'success') {
                    $('#modalPagoOrden').modal('hide');
                    Swal.fire('¡Registrado!', res.message, 'success');
                    cargarOrdenes(currentPage);
                } else {
                    Swal.fire('Atención', res.message || 'Error al registrar el pago', 'warning');
                }
            },
            error: function() {
                Swal.fire('Error', 'Error de comunicación con el servidor.', 'error');
            }
        });
    });

    // Abrir Modal de Verificación / Consulta de Comprobante [FAC-02]
    $(document).on('click', '.btn-ver-pago', function() {
        let id_orden = $(this).data('id');
        let o = currentOrdersMap[id_orden];
        if (!o) return;

        $('#verPagoOrdenId').text(o.id);
        $('#verPagoSede').text(o.nombre_sede);
        $('#verPagoMonto').text('$' + parseFloat(o.monto_total || 0).toFixed(2) + ' USD');
        $('#verPagoEstadoBadge').html(renderEstadoBadge(o.estado));
        $('#verPagoMetodo').text(o.metodo_pago || 'No especificado');
        $('#verPagoReferencia').text(o.referencia_pago || 'N/A');
        $('#verPagoFecha').text(o.fecha_pago || 'N/A');
        $('#verPagoNotas').text(o.notas_pago || 'Sin observaciones');
        $('#txtNotaVerificacion').val('');
        $('#btnImprimirReciboModal').attr('href', 'imprimir_orden_waba.php?id=' + o.id);

        if (isMaster) {
            $('#masterVerifyPanel').data('id-orden', o.id);
            $('#masterVerifyPanel').show();
            if (o.estado === 'PAGADO') {
                $('#btnAprobarPagoMaster').hide();
                $('#btnPonerEnRevisionMaster').show();
            } else {
                $('#btnAprobarPagoMaster').show();
                $('#btnPonerEnRevisionMaster').hide();
            }
        }

        let cont = $('#verPagoComprobanteContainer');
        cont.empty();
        if (o.comprobante_path) {
            let fileUrl = '../../' + o.comprobante_path;
            let ext = o.comprobante_path.split('.').pop().toLowerCase();
            if (ext === 'pdf') {
                cont.html(`
                    <div class="py-4">
                        <i class="fas fa-file-pdf fa-4x text-danger mb-3"></i>
                        <p class="mb-2 fw-semibold">Documento PDF Adjunto</p>
                        <a href="${fileUrl}" target="_blank" class="btn btn-sm btn-primary">
                            <i class="fas fa-external-link-alt me-1"></i> Abrir Comprobante PDF
                        </a>
                    </div>
                `);
            } else {
                cont.html(`
                    <a href="${fileUrl}" target="_blank" title="Clic para abrir imagen completa">
                        <img src="${fileUrl}" alt="Comprobante de Pago">
                    </a>
                    <div class="mt-2">
                        <a href="${fileUrl}" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-magnifying-glass-plus me-1"></i> Ver en tamaño completo
                        </a>
                    </div>
                `);
            }
        } else {
            cont.html('<div class="py-5 text-muted"><i class="fas fa-file-circle-xmark fa-2x mb-2 d-block"></i>No se adjuntó archivo de comprobante</div>');
        }

        $('#modalVerPago').modal('show');
    });

    // Acciones exclusivas MASTER para Aprobar o Rechazar un pago en revisión
    $('#btnAprobarPagoMaster').click(function() {
        let id_orden = $('#masterVerifyPanel').data('id-orden');
        let notas = $('#txtNotaVerificacion').val();

        Swal.fire({
            title: '¿Confirmar ingreso del pago?',
            text: `La orden #${id_orden} pasará a estado PAGADO definitivo.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            confirmButtonText: 'Sí, confirmar pago',
            cancelButtonText: 'Cancelar'
        }).then((res) => {
            if (res.isConfirmed) {
                $.post('back_waba_billing.php', {
                    action: 'verify_payment',
                    id_orden: id_orden,
                    decision: 'APROBAR',
                    notas_verificacion: notas
                }, function(r) {
                    if (r.status === 'success') {
                        $('#modalVerPago').modal('hide');
                        Swal.fire('¡Pago Verificado!', r.message, 'success');
                        cargarOrdenes(currentPage);
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                }, 'json');
            }
        });
    });

    $('#btnPonerEnRevisionMaster').click(function() {
        let id_orden = $('#masterVerifyPanel').data('id-orden');
        let notas = $('#txtNotaVerificacion').val();

        $.post('back_waba_billing.php', {
            action: 'verify_payment',
            id_orden: id_orden,
            decision: 'EN_REVISION',
            notas_verificacion: notas
        }, function(r) {
            if (r.status === 'success') {
                $('#modalVerPago').modal('hide');
                Swal.fire('En Revisión', r.message, 'info');
                cargarOrdenes(currentPage);
            } else {
                Swal.fire('Error', r.message, 'error');
            }
        }, 'json');
    });

    $('#btnRechazarPagoMaster').click(function() {
        let id_orden = $('#masterVerifyPanel').data('id-orden');
        let notas = $('#txtNotaVerificacion').val();

        Swal.fire({
            title: '¿Rechazar reporte de pago?',
            text: `La orden #${id_orden} será devuelta a estado PENDIENTE.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'Sí, devolver a PENDIENTE',
            cancelButtonText: 'Cancelar'
        }).then((res) => {
            if (res.isConfirmed) {
                $.post('back_waba_billing.php', {
                    action: 'verify_payment',
                    id_orden: id_orden,
                    decision: 'RECHAZAR',
                    notas_verificacion: notas
                }, function(r) {
                    if (r.status === 'success') {
                        $('#modalVerPago').modal('hide');
                        Swal.fire('Reporte Rechazado', r.message, 'info');
                        cargarOrdenes(currentPage);
                    } else {
                        Swal.fire('Error', r.message, 'error');
                    }
                }, 'json');
            }
        });
    });

    $(document).on('click', '.btn-notificar', function() {
        let id_orden = $(this).data('id');
        Swal.fire({
            title: '¿Reenviar Notificación?',
            text: "Se enviará un recordatorio de pago al Gerente de esta sucursal por WhatsApp/Email.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, enviar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                $.post('back_waba_billing.php', { action: 'resend_notification', id_orden: id_orden }, function(res) {
                    if (res.status === 'success') {
                        Swal.fire('¡Enviado!', res.message, 'success');
                        cargarOrdenes(currentPage);
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }, 'json');
            }
        });
    });

    $(document).on('click', '.btn-eliminar', function() {
        let id_orden = $(this).data('id');
        Swal.fire({
            title: '¿Eliminar Orden?',
            text: "Esta acción anulará y eliminará esta orden de cobro permanentemente. ¿Estás seguro?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Eliminando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                $.post('back_waba_billing.php', { action: 'delete_order', id_orden: id_orden }, function(res) {
                    if (res.status === 'success') {
                        Swal.fire('¡Eliminado!', res.message, 'success');
                        cargarOrdenes(currentPage);
                    } else {
                        Swal.fire('Error', res.message || 'Error desconocido', 'error');
                    }
                }, 'json');
            }
        });
    });
});
</script>
    </main>
    </div>
</body>
</html>
