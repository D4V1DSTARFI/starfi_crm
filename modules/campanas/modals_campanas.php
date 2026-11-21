<?php
// modules/campanas/modals_campanas.php
// Modales Corporativos del Ecosistema de Campañas de Difusión Masiva | STARFI CRM
?>
    <!-- MODAL 1: CONFIGURACIÓN DE LÍMITE Y RITMO DIARIO POR SEDE -->
    <div class="modal fade" id="modalFlujoSede" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <form id="formFlujoSede" autocomplete="off">
                    <div class="modal-header bg-dark text-white" style="border-radius: 16px 16px 0 0;">
                        <h6 class="modal-title fw-bold">
                            <i class="fa-solid fa-shield-halved text-warning me-2"></i>Límite y Ritmo Diario de la Sede
                        </h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info small py-2 mb-3">
                            <i class="fa-solid fa-circle-info me-1"></i>
                            Proteja la reputación del número oficial de la sede definiendo un tope de mensajes diarios y la
                            pausa entre envíos para evitar bloqueos de Meta (Error 131049).
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold" for="cfgLimiteDiario">Límite Máximo de Mensajes Diarios para esta Sede</label>
                            <input type="number" id="cfgLimiteDiario" class="form-control" min="10" max="100000"
                                value="250" autocomplete="off">
                            <small class="text-muted">Recomendado por nivel WABA: Tier Inicial = <code>250/día</code>, Nivel
                                Estándar = <code>1,000/día</code>.</small>
                        </div>

                        <div class="mb-0">
                            <label class="form-label small fw-bold" for="cfgCadenciaMs">Cadencia / Ritmo entre Mensajes (Anti-Spam)</label>
                            <select id="cfgCadenciaMs" class="form-select" autocomplete="off">
                                <option value="500">Rápido (0.5 segundos por mensaje)</option>
                                <option value="1000" selected>Seguro Recomendado (1.0 segundo por mensaje)</option>
                                <option value="2000">Conservador Anti-Bloqueo (2.0 segundos por mensaje)</option>
                                <option value="3500">Ultra-Cuidadoso (3.5 segundos por mensaje)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary btn-sm fw-bold"
                            style="background-color: #E85B14; border-color: #E85B14;"
                            onclick="guardarConfiguracionFlujoSede()">
                            <i class="fa-solid fa-floppy-disk me-1"></i>Guardar Límite y Ritmo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL 2: AUDITORÍA 360° DE DESTINATARIOS DE UNA CAMPAÑA (ENVIADOS, LEÍDOS, RESPUESTAS) -->
    <div class="modal fade" id="modalDetalleCampana" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-dark text-white" style="border-radius: 16px 16px 0 0;">
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="detTituloCampana">Detalle de Campaña</h6>
                        <small class="text-white-50" id="detSubtituloCampana"></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Embudo Visual de Conversión dentro del Modal -->
                    <div class="row g-2 mb-3" id="detFunnelResumenRow">
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Enviados</div>
                                <div class="fs-5 fw-bold text-primary" id="detKpiEnviados">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Entregados</div>
                                <div class="fs-5 fw-bold text-dark" id="detKpiEntregados">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Leídos (Open)</div>
                                <div class="fs-5 fw-bold text-info" id="detKpiLeidos">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Respondidos</div>
                                <div class="fs-5 fw-bold text-success" id="detKpiRespondidos">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Fallidos</div>
                                <div class="fs-5 fw-bold text-danger" id="detKpiFallidos">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Inversión USD</div>
                                <div class="fs-5 fw-bold" style="color: #E85B14;" id="detKpiCosto">$0.00</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros rápidos dentro del modal + Buscador + Exportar CSV -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div class="btn-group btn-group-sm" role="group" id="detGrupoFiltrosEstadoModal">
                            <button type="button" class="btn btn-outline-dark active"
                                onclick="filtrarTablaDestinatariosModal('TODOS', this)">Todos</button>
                            <button type="button" class="btn btn-outline-success"
                                onclick="filtrarTablaDestinatariosModal('RESPONDIDO', this)">Respondidos</button>
                            <button type="button" class="btn btn-outline-info"
                                onclick="filtrarTablaDestinatariosModal('LEIDO', this)">Leídos</button>
                            <button type="button" class="btn btn-outline-secondary"
                                onclick="filtrarTablaDestinatariosModal('PENDIENTE', this)">Pendientes</button>
                            <button type="button" class="btn btn-outline-danger"
                                onclick="filtrarTablaDestinatariosModal('FALLIDO', this)">Fallidos</button>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2 ms-auto">
                            <div class="input-group input-group-sm" style="width: 240px;">
                                <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" id="detInputBuscarModal" class="form-control" autocomplete="off" placeholder="Buscar cliente, tlf o sede..." oninput="buscarEnDestinatariosModal(this.value)">
                            </div>
                            <select id="detSelectPageSizeModal" class="form-select form-select-sm" style="width: auto;" autocomplete="off" onchange="cambiarPageSizeDestinatariosModal(this.value)">
                                <option value="10" selected>10 / pág.</option>
                                <option value="25">25 / pág.</option>
                                <option value="50">50 / pág.</option>
                                <option value="100">100 / pág.</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline-success fw-semibold" onclick="exportarDestinatariosCampanaCsv()">
                                <i class="fa-solid fa-file-csv me-1"></i>Exportar CSV
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.84rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Cliente</th>
                                    <th>Sede Cliente</th>
                                    <th>Teléfono</th>
                                    <th class="text-center">Estado</th>
                                    <th>Enviado / Tanda</th>
                                    <th>Leído (Apertura)</th>
                                    <th>Respuesta / Detalle Meta</th>
                                    <th class="text-end">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyDetalleDestinatarios"></tbody>
                        </table>
                    </div>

                    <!-- Barra de Paginación del Modal de Destinatarios -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center pt-3 mt-2 border-top gap-2" style="font-size: 0.8rem;">
                        <span class="text-muted fw-semibold" id="detResumenModal">Mostrando 0 de 0 destinatarios</span>
                        <div class="d-flex align-items-center gap-1" id="detControlesPaginacionModal"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 3: DIÁLOGO CORPORATIVO DE CONFIRMACIÓN Y NOTIFICACIONES STARFI -->
    <div class="modal fade" id="modalStarfiDialog" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content starfi-dialog-card">
                <div class="starfi-dialog-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div id="starfiDialogIcon" class="starfi-dialog-icon-circle" style="background: rgba(232, 91, 20, 0.2); color: #FB923C;">
                            <i class="fa-solid fa-bullhorn"></i>
                        </div>
                        <div>
                            <span id="starfiDialogBadge" class="badge mb-1" style="background-color: #E85B14; font-size: 0.68rem;">CONFIRMACIÓN DE CAMPAÑA</span>
                            <h6 class="mb-0 fw-bold text-white" id="starfiDialogTitle">Confirmar Operación</h6>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" id="btnStarfiDialogCloseX"></button>
                </div>
                <div class="modal-body p-4" id="starfiDialogBody" style="font-size: 0.9rem; color: #334155;">
                </div>
                <div class="modal-footer bg-light border-top px-4 py-3 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary fw-semibold px-3" id="btnStarfiDialogCancel" style="border-radius: 10px;">
                        Cancelar
                    </button>
                    <button type="button" class="btn text-white fw-bold px-4" id="btnStarfiDialogConfirm" style="background-color: #E85B14; border-radius: 10px;">
                        Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENEDOR DE TOASTS CORPORATIVOS -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="starfiToastContainer" style="z-index: 1090;"></div>
