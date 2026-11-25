<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
<?php if (!empty($es_master)): ?>
<!-- Modal Emisión Masiva -->
<div class="modal fade" id="modalEmisionMasiva" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form id="formEmisionMasiva" autocomplete="off">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-layer-group me-2"></i>Emisión Masiva de Órdenes</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fa-solid fa-circle-info me-2"></i> Esta acción generará una orden de cobro nueva e independiente para cada sede seleccionada, utilizando el motor híbrido de conciliación y validación anti-solapamiento.
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="masivaFechaDesde">Fecha Desde</label>
                            <input type="date" id="masivaFechaDesde" class="form-control" value="<?= htmlspecialchars(date('Y-m-01'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold" for="masivaFechaHasta">Fecha Hasta</label>
                            <input type="date" id="masivaFechaHasta" class="form-control" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>

                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <label class="form-label fw-bold mb-0">Seleccionar Sedes:</label>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnSelTodasSedes">Seleccionar Todas</button>
                    </div>

                    <div class="border rounded p-3" style="max-height: 250px; overflow-y: auto;">
                        <?php foreach ($sedes as $s): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input chk-sede-masiva" type="checkbox" value="<?= intval($s['id']) ?>" id="chkMasiva_<?= intval($s['id']) ?>">
                            <label class="form-check-label" for="chkMasiva_<?= intval($s['id']) ?>">
                                <?= htmlspecialchars((string)$s['nombre_sede'], ENT_QUOTES, 'UTF-8') ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning text-dark fw-bold" id="btnGenerarMasiva"><i class="fa-solid fa-bolt me-2"></i>Generar Masivamente</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Tarifas por Sede -->
<div class="modal fade" id="modalTarifasSede" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form id="formTarifasSede" autocomplete="off">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-store me-2"></i>Configuración de Facturación por Sede</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold" for="selConfigSede">Seleccione Sede a Configurar:</label>
                        <select id="selConfigSede" class="form-select">
                            <option value="">Seleccione...</option>
                            <?php foreach ($sedes as $s): ?>
                                <option value="<?= intval($s['id']) ?>"><?= htmlspecialchars((string)$s['nombre_sede'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="configSedePanel" style="display:none;">
                        <h6 class="border-bottom pb-2 mb-3 mt-4 text-primary fw-bold">Reglas de Negocio (Márgenes de Ganancia)</h6>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="configTipoTarifa">Tipo de Tarifa</label>
                                <select id="configTipoTarifa" class="form-select">
                                    <option value="PORCENTAJE">Porcentaje Sobre Costo (Ej: +10%)</option>
                                    <option value="FIJA">Tarifa Fija (Ej: $0.05 por msj)</option>
                                    <option value="PORCENTAJE_VOLUMEN">Porcentaje Dinámico (Volumen)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="configValorTarifa">Valor Numérico</label>
                                <input type="number" id="configValorTarifa" class="form-control" step="0.01" value="10.00">
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="configLimiteFacturacion">Límite Facturación Automática (USD)</label>
                                <input type="number" id="configLimiteFacturacion" class="form-control" step="0.01" value="0.00" title="Si se supera este costo Meta, se factura automáticamente.">
                                <small class="text-muted">0 = Desactivado</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="configNotas">Notas Internas de Negociación</label>
                            <textarea id="configNotas" class="form-control" rows="2" placeholder="Ej: Se acordó un 15% hasta 1000 mensajes..."></textarea>
                        </div>

                        <h6 class="border-bottom pb-2 mb-3 mt-4 text-primary fw-bold">Contacto de Notificación de Pagos</h6>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label" for="configNombreGerente">Nombre del Gerente</label>
                                <input type="text" id="configNombreGerente" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="configTelGerente">Teléfono (WhatsApp)</label>
                                <input type="text" id="configTelGerente" class="form-control" placeholder="584141234567">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="configEmailGerente">Email</label>
                                <input type="email" id="configEmailGerente" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="configPrefNotif">Vía Preferida de Notificación</label>
                            <select id="configPrefNotif" class="form-select">
                                <option value="WHATSAPP">WhatsApp</option>
                                <option value="EMAIL">Email</option>
                                <option value="AMBOS">WhatsApp y Email</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-primary" id="btnGuardarConfigSede" style="display:none;"><i class="fa-solid fa-save me-2"></i>Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Informativo CLI para Instalación Segura de Cron -->
<div class="modal fade" id="modalCronCliInfo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-terminal me-2"></i>Instalación Segura de Auto-Facturación (CLI)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">
                    Por políticas de endurecimiento de seguridad (<code>QA-STD-GLOBAL-2.1</code>), el registro de tareas del sistema operativo está restringido exclusivamente a consola administrativa (CLI).
                </p>
                <label class="form-label fw-bold small text-dark">Ejecute este comando como Administrador en el servidor:</label>
                <div class="bg-dark text-light p-3 rounded font-monospace small">
                    php modules/waba_ordenes/test_ia/install_cron_waba.php
                </div>
                <small class="text-muted d-block mt-2">
                    Esta tarea ejecutará diariamente (01:00 AM) el conciliador híbrido <code>cron_waba_billing.php</code>.
                </small>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Entendido</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
