<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
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

    <!-- MODAL 4: [IA-03] CONSTRUCTOR Y CLONADOR DE PLANTILLAS OFICIALES META (POST /{waba_id}/message_templates) -->
    <div class="modal fade" id="modalMetaTemplateBuilder" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 18px;">
                <div class="modal-header bg-dark text-white" style="border-radius: 18px 18px 0 0;">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-brands fa-meta text-info fs-4"></i>
                        <div>
                            <h6 class="modal-title fw-bold mb-0">Constructor y Clonador Oficial de Plantillas Meta WABA</h6>
                            <small class="text-white-50">Publica vía <code>POST /{waba_id}/message_templates</code> y clona plantillas entre sedes sin salir del CRM</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <ul class="nav nav-pills mb-3 gap-2" id="tabsMetaBuilder" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active fw-bold btn-sm px-3" id="tabBtnCrearTpl" data-bs-toggle="pill" data-bs-target="#paneCrearMetaTpl" type="button" role="tab">
                                <i class="fa-solid fa-plus-circle me-1"></i>1. Crear Nueva Plantilla Oficial
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link fw-bold btn-sm px-3" id="tabBtnClonarTpl" data-bs-toggle="pill" data-bs-target="#paneClonarMetaTpl" type="button" role="tab" onclick="cargarPlantillasOrigenClonador()">
                                <i class="fa-solid fa-clone me-1"></i>2. Clonar Plantilla entre Sedes
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- PESTAÑA 1: CREAR NUEVA PLANTILLA OFICIAL META -->
                        <div class="tab-pane fade show active" id="paneCrearMetaTpl" role="tabpanel">
                            <form id="formMetaTemplateBuilder" autocomplete="off">
                                <div class="row g-3">
                                    <div class="col-12 col-lg-7">
                                        <div class="row g-2 mb-2">
                                            <div class="col-12 col-md-5">
                                                <label class="form-label small fw-bold mb-1">Nombre Técnico (snake_case) *</label>
                                                <input type="text" id="mtbName" autocomplete="off" class="form-control form-control-sm font-monospace" placeholder="ej: promo_fin_de_mes" oninput="normalizarInputNombrePlantilla(this); actualizarPreviewConstructorMeta();">
                                            </div>
                                            <div class="col-6 col-md-4">
                                                <label class="form-label small fw-bold mb-1">Categoría Meta *</label>
                                                <select id="mtbCategory" class="form-select form-select-sm" onchange="actualizarPreviewConstructorMeta()">
                                                    <option value="MARKETING" selected>MARKETING (Promocional)</option>
                                                    <option value="UTILITY">UTILITY (Transaccional / Seguimiento)</option>
                                                </select>
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <label class="form-label small fw-bold mb-1">Idioma *</label>
                                                <select id="mtbLanguage" class="form-select form-select-sm">
                                                    <option value="es" selected>Español (es)</option>
                                                    <option value="es_LA">Español LATAM (es_LA)</option>
                                                    <option value="en_US">Inglés (en_US)</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="row g-2 mb-2">
                                            <div class="col-12 col-md-4">
                                                <label class="form-label small fw-bold mb-1">Cabecera (Header)</label>
                                                <select id="mtbHeaderFormat" class="form-select form-select-sm" onchange="actualizarPreviewConstructorMeta()">
                                                    <option value="NONE" selected>Sin cabecera</option>
                                                    <option value="TEXT">Texto corto (máx. 60 car.)</option>
                                                </select>
                                            </div>
                                            <div class="col-12 col-md-8 d-none" id="mtbHeaderTextWrap">
                                                <label class="form-label small fw-bold mb-1">Texto de Cabecera</label>
                                                <input type="text" id="mtbHeaderText" autocomplete="off" class="form-control form-control-sm" maxlength="60" placeholder="Ej: Novedades STARFI {{1}}" oninput="actualizarPreviewConstructorMeta()">
                                            </div>
                                        </div>

                                        <div class="mb-2">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
                                                <label class="form-label small fw-bold mb-0">Cuerpo del Mensaje (Body) *</label>
                                                <div class="d-flex align-items-center gap-1">
                                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:0.7rem;" onclick="insertarVariableMtb('{{1}}')">+ {{1}}</button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:0.7rem;" onclick="insertarVariableMtb('{{2}}')">+ {{2}}</button>
                                                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:0.7rem;" onclick="insertarVariableMtb('{{3}}')">+ {{3}}</button>
                                                    <button type="button" id="btnMtbDraftIa" class="btn btn-sm py-0 px-2 text-white fw-bold" style="font-size:0.7rem; background: linear-gradient(135deg, #6D28D9, #8B5CF6);" onclick="redactarCuerpoPlantillaConIa()">
                                                        <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Redactar con IA
                                                    </button>
                                                </div>
                                            </div>
                                            <textarea id="mtbBody" autocomplete="off" class="form-control form-control-sm" rows="4" placeholder="Hola {{1}}, te escribimos de {{2}} para informarte sobre {{3}}." oninput="renderizarInputsEjemplosMtb(); actualizarPreviewConstructorMeta();">Hola {{1}}, te saludamos de {{2}}. Queremos compartirte {{3}}. Responde este mensaje si deseas atención inmediata.</textarea>
                                            <small class="text-muted" style="font-size:0.7rem;">Meta exige no colocar variables <code>{{1}}</code> ni al inicio exacto ni al final exacto de la oración.</small>
                                        </div>

                                        <div id="mtbExamplesBox" class="p-2.5 px-3 rounded-3 border bg-light mb-2"></div>

                                        <div class="row g-2 mb-2">
                                            <div class="col-12 col-md-5">
                                                <label class="form-label small fw-bold mb-1">Pie de Mensaje (Footer opcional)</label>
                                                <input type="text" id="mtbFooter" autocomplete="off" class="form-control form-control-sm" maxlength="60" placeholder="Ej: Responde STOP para baja" oninput="actualizarPreviewConstructorMeta()">
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <label class="form-label small fw-bold mb-1">Botón Interactivo</label>
                                                <select id="mtbBtn1Type" class="form-select form-select-sm" onchange="actualizarPreviewConstructorMeta()">
                                                    <option value="NONE">Sin botón</option>
                                                    <option value="QUICK_REPLY" selected>Respuesta Rápida</option>
                                                    <option value="URL">Enlace Web (https)</option>
                                                </select>
                                            </div>
                                            <div class="col-6 col-md-4" id="mtbBtn1TextWrap">
                                                <label class="form-label small fw-bold mb-1">Texto del Botón</label>
                                                <input type="text" id="mtbBtn1Text" autocomplete="off" class="form-control form-control-sm" maxlength="25" value="Quiero más info" oninput="actualizarPreviewConstructorMeta()">
                                            </div>
                                            <div class="col-12 d-none" id="mtbBtn1UrlWrap">
                                                <label class="form-label small fw-bold mb-1">URL Destino (https://)</label>
                                                <input type="text" id="mtbBtn1Url" autocomplete="off" class="form-control form-control-sm" placeholder="https://tuempresa.com/catalogo">
                                            </div>
                                        </div>

                                        <div class="p-2.5 px-3 rounded-3 border bg-light">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small fw-bold text-dark mb-0"><i class="fa-solid fa-store text-primary me-1"></i>Sedes WABA Destino donde se creará:</label>
                                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-bold" style="font-size:0.72rem;" id="btnMtbSelectAllSedes" onclick="toggleTodasSedesMetaBuilder('mtbTargetSedesList')">Seleccionar todas</button>
                                            </div>
                                            <div id="mtbTargetSedesList" class="d-flex flex-wrap gap-2 mt-1"></div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-lg-5">
                                        <div class="p-3 rounded-3 border bg-light h-100 d-flex flex-column justify-content-between">
                                            <div>
                                                <div class="small fw-bold text-secondary mb-2"><i class="fa-brands fa-whatsapp text-success me-1"></i>Vista Previa Oficial WhatsApp</div>
                                                <div class="wa-phone-mockup">
                                                    <div class="wa-phone-header">
                                                        <div class="wa-phone-avatar"><i class="fa-brands fa-whatsapp"></i></div>
                                                        <div>
                                                            <div class="fw-bold" style="font-size:0.82rem;">Vista Previa Plantilla</div>
                                                            <div style="font-size:0.68rem; opacity:0.85;">Graph API v23.0</div>
                                                        </div>
                                                    </div>
                                                    <div class="wa-preview-frame">
                                                        <div class="wa-bubble" id="mtbLivePreviewBubble"></div>
                                                    </div>
                                                </div>
                                                <div id="mtbCreateResultsBox" class="mt-3"></div>
                                            </div>
                                            <div class="mt-3 d-grid">
                                                <button type="button" id="btnSubmitCreateMetaTpl" class="btn fw-bold text-white py-2" style="background-color:#E85B14; border-radius:10px;" onclick="enviarNuevaPlantillaOficialMeta()">
                                                    <i class="fa-solid fa-cloud-arrow-up me-1"></i>Enviar Plantilla a Revisión en Meta
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>

                        <!-- PESTAÑA 2: CLONAR PLANTILLA EXISTENTE ENTRE SEDES -->
                        <div class="tab-pane fade" id="paneClonarMetaTpl" role="tabpanel">
                            <form id="formMetaTemplateCloner" autocomplete="off">
                                <div class="row g-3">
                                    <div class="col-12 col-lg-6">
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold mb-1">1. Sede Origen (de donde se copiará la plantilla)</label>
                                            <div class="input-group input-group-sm">
                                                <select id="mtcSourceSede" class="form-select" onchange="cargarPlantillasOrigenClonador()"></select>
                                                <button type="button" class="btn btn-outline-secondary" onclick="cargarPlantillasOrigenClonador()" title="Sincronizar con Meta">
                                                    <i class="fa-solid fa-rotate"></i>
                                                </button>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold mb-1">2. Plantilla Oficial a Clonar</label>
                                            <select id="mtcSourceTemplate" class="form-select form-select-sm" onchange="seleccionarPlantillaClonador()"></select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small fw-bold mb-1">3. Nombre en Sedes Destino (snake_case)</label>
                                            <input type="text" id="mtcTargetName" autocomplete="off" class="form-control form-control-sm font-monospace" oninput="normalizarInputNombrePlantilla(this)">
                                        </div>
                                        <div class="p-2.5 px-3 rounded-3 border bg-light">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <label class="form-label small fw-bold text-dark mb-0">4. Sedes Destino donde se clonará:</label>
                                                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-bold" style="font-size:0.72rem;" onclick="toggleTodasSedesMetaBuilder('mtcTargetSedesList')">Seleccionar todas</button>
                                            </div>
                                            <div id="mtcTargetSedesList" class="d-flex flex-wrap gap-2 mt-1"></div>
                                        </div>
                                    </div>
                                    <div class="col-12 col-lg-6">
                                        <div class="p-3 rounded-3 border bg-light h-100 d-flex flex-column justify-content-between">
                                            <div>
                                                <div class="small fw-bold text-secondary mb-2"><i class="fa-solid fa-eye text-primary me-1"></i>Estructura Detectada a Clonar</div>
                                                <div id="mtcClonePreviewBox" class="p-3 rounded-3 bg-white border small text-dark" style="min-height:130px;">
                                                    Seleccione una sede y plantilla origen para inspeccionar su estructura.
                                                </div>
                                                <div id="mtcResultsContainer" class="mt-3"></div>
                                            </div>
                                            <div class="mt-3 d-grid">
                                                <button type="button" id="btnSubmitCloneMetaTpl" class="btn btn-primary fw-bold py-2" style="border-radius:10px;" onclick="ejecutarClonacionPlantillaMeta()">
                                                    <i class="fa-solid fa-clone me-1"></i>Clonar Plantilla en Sedes Seleccionadas
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 5: [IA-04] CONFIGURAR Y EJECUTAR REGLA DRIP REMARKETING EN CAMPAÑA -->
    <div class="modal fade" id="modalConfigDripCampana" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <form id="formConfigDripCampana" autocomplete="off">
                    <input type="hidden" id="mdlDripCampanaId" value="0">
                    <div class="modal-header bg-dark text-white" style="border-radius: 16px 16px 0 0;">
                        <div>
                            <h6 class="modal-title fw-bold mb-0"><i class="fa-solid fa-arrows-spin text-info me-2"></i>Secuencia Drip Remarketing</h6>
                            <small class="text-white-50" id="mdlDripSubtituloCampana">Seguimiento automático a leídos sin respuesta</small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="form-check form-switch mb-3 p-2.5 px-3 rounded-3 border bg-light">
                            <input class="form-check-input ms-0 me-2" type="checkbox" id="mdlDripActivo">
                            <label class="form-check-label fw-bold small text-dark" for="mdlDripActivo">
                                Activar Regla Automática en Cron para esta campaña
                            </label>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-5">
                                <label class="form-label small fw-bold mb-1">Horas sin respuesta tras LEÍDO</label>
                                <input type="number" id="mdlDripHoras" autocomplete="off" class="form-control form-control-sm fw-bold" min="1" max="720" value="24">
                            </div>
                            <div class="col-7">
                                <label class="form-label small fw-bold mb-1">Acción al cumplirse el plazo</label>
                                <select id="mdlDripAccion" class="form-select form-select-sm fw-semibold">
                                    <option value="ASIGNAR_ASESOR">👤 Asignar a Vendedor (Round-Robin)</option>
                                    <option value="REIMPACTAR_PLANTILLA">📲 Re-impactar con Plantilla Meta</option>
                                    <option value="AMBOS">🚀 Re-impactar + Asignar a Vendedor</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold mb-1">Plantilla Oficial de Re-impacto</label>
                            <select id="mdlDripTemplate" class="form-select form-select-sm"></select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small fw-bold mb-1">Mensaje de Seguimiento ({{2}})</label>
                            <textarea id="mdlDripParamTexto" autocomplete="off" class="form-control form-control-sm" rows="2" placeholder="vimos que leíste nuestra información y queremos saber si podemos ayudarte con precios o disponibilidad..."></textarea>
                        </div>
                        <div id="mdlDripResultadoBox" class="mt-2"></div>
                    </div>
                    <div class="modal-footer bg-light d-flex justify-content-between">
                        <button type="button" id="btnEjecutarDripAhoraModal" class="btn btn-outline-primary btn-sm fw-bold" onclick="ejecutarDripCampanaAhora()">
                            <i class="fa-solid fa-bolt me-1"></i>Evaluar Vencidos Ahora
                        </button>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                            <button type="button" id="btnGuardarReglaDripModal" class="btn btn-sm fw-bold text-white" style="background-color:#E85B14;" onclick="guardarReglaDripModal()">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Guardar Regla Drip
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- CONTENEDOR DE TOASTS CORPORATIVOS -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="starfiToastContainer" style="z-index: 1090;"></div>
