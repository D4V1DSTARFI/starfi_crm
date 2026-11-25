<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
<!-- Modal Detalles de Orden (#modalDetalleOrden) -->
<div class="modal fade" id="modalDetalleOrden" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-light">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-list-check text-primary me-2"></i>Desglose de Orden #<span id="lblOrdenId"></span>
                    <small class="text-muted ms-2 fw-normal" id="lblOrdenSede"></small>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
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
                                <?php if (!empty($es_master)): ?>
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

                <?php if (!empty($es_master)): ?>
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

<!-- Modal Registro / Reporte de Pago (#modalReportarPago) -->
<div class="modal fade" id="modalReportarPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="formPagoOrden" enctype="multipart/form-data" autocomplete="off">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold">
                        <i class="fas fa-money-check-dollar me-2"></i>
                        <?= !empty($es_master) ? 'Registrar / Confirmar Pago de Orden' : 'Reportar Pago de Orden' ?> #<span id="lblPagoOrdenId"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
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

                    <?php if (empty($es_master)): ?>
                    <div class="alert alert-info py-2 small">
                        <i class="fas fa-info-circle me-1"></i> Al enviar los datos y el comprobante, su orden pasará a estado <strong>Pagada (Pendiente por Revisión)</strong> para que el equipo administrativo valide el ingreso.
                    </div>
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold small" for="pago_metodo">Método de Pago <span class="text-danger">*</span></label>
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
                        <div class="col-md-6" id="wrapBanco" style="display: none;">
                            <label class="form-label fw-semibold small" id="lblPagoBanco" for="pago_banco_emisor">Banco Emisor <span class="text-danger">*</span></label>
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

                        <div class="col-md-6" id="wrapReferencia">
                            <label class="form-label fw-semibold small" id="lblPagoReferencia" for="pago_referencia">Referencia / TxID <span class="text-danger">*</span></label>
                            <input type="text" name="referencia_pago" id="pago_referencia" class="form-control" placeholder="Ej: 984512369 / TxID">
                        </div>

                        <div class="col-md-6" id="wrapTitular" style="display: none;">
                            <label class="form-label fw-semibold small" id="lblPagoTitular" for="pago_titular_emisor">Titular / Cuenta Emisora <span class="text-danger">*</span></label>
                            <input type="text" name="titular_emisor" id="pago_titular_emisor" class="form-control" placeholder="Nombre del titular o correo">
                        </div>

                        <div class="col-md-6" id="wrapTelefonoEmisor" style="display: none;">
                            <label class="form-label fw-semibold small" id="lblPagoTelefono" for="pago_telefono_emisor">Teléfono y Cédula/RIF Emisor <span class="text-danger">*</span></label>
                            <input type="text" name="telefono_emisor" id="pago_telefono_emisor" class="form-control" placeholder="Ej: 0414-1234567 / V-12345678">
                        </div>

                        <div class="col-md-6" id="wrapMontoBs" style="display: none;">
                            <label class="form-label fw-semibold small" id="lblPagoMontoBs" for="pago_monto_bs">Monto Pagado en Bs. <span class="text-muted fw-normal">(Opcional)</span></label>
                            <input type="text" name="monto_bs" id="pago_monto_bs" class="form-control" placeholder="Ej: 1.450,00 Bs">
                        </div>

                        <div class="col-md-6" id="wrapFechaPago">
                            <label class="form-label fw-semibold small" for="pago_fecha">Fecha del Pago <span class="text-danger">*</span></label>
                            <input type="date" name="fecha_pago" id="pago_fecha" class="form-control" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <?php if (!empty($es_master)): ?>
                        <div class="col-md-6" id="wrapEstadoDestino">
                            <label class="form-label fw-semibold small" for="pago_estado_destino">Estado Inicial del Reporte</label>
                            <select name="estado_destino" id="pago_estado_destino" class="form-select">
                                <option value="EN_REVISION" selected>PAGADA - PEND. REVISIÓN (Verificar ingreso luego)</option>
                                <option value="PAGADO">PAGADO (Confirmar directo)</option>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-12">
                            <label class="form-label fw-semibold small" id="lblPagoComprobante" for="pago_comprobante">Comprobante Adjunto (Imagen o PDF)</label>
                            <input type="file" name="comprobante" id="pago_comprobante" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf">
                            <small class="text-muted">Formatos aceptados: JPG, PNG, WEBP o PDF (Máx. 5MB).</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold small" for="pago_notas">Notas / Observaciones Adicionales</label>
                            <textarea name="notas_pago" id="pago_notas" class="form-control" rows="2" placeholder="Comentarios adicionales sobre el pago..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success fw-bold" id="btnSubmitPago">
                        <i class="fas fa-paper-plane me-1"></i> <?= !empty($es_master) ? 'Guardar Pago' : 'Enviar Reporte de Pago' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Verificación / Consulta de Comprobante y Pago (#modalVerificarPago) -->
<div class="modal fade" id="modalVerificarPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold">
                    <i class="fas fa-receipt me-2"></i>Soporte y Verificación de Pago — Orden #<span id="verPagoOrdenId"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
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

                            <?php if (!empty($es_master)): ?>
                            <form id="formVerificarPagoMaster" class="mt-3 pt-3 border-top" autocomplete="off">
                                <div id="masterVerifyPanel">
                                    <label class="form-label small fw-bold text-dark" for="txtNotaVerificacion">Control de Auditoría MASTER:</label>
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
                            </form>
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
