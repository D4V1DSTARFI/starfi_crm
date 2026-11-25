<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
<!-- Modal Ficha de Cliente (Layout 2 Columnas) -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1350px;">
        <div class="modal-content overflow-hidden">
            <div class="modal-header border-0 bg-light position-relative text-center d-flex justify-content-center align-items-center">
                <h5 class="modal-title mb-0 brand-font fw-bold text-starfi-dark text-center w-100">
                    <i class="fa-solid fa-id-card-clip me-2 text-starfi-primary"></i>Ficha de Cliente 360
                </h5>
                <button type="button" class="btn-close position-absolute end-0 me-3" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-0">
                <div class="profile-body-scroll d-flex flex-row" style="height: 780px;">
                    <!-- Columna Izquierda: Datos -->
                    <form id="formProfileDirectorio" autocomplete="off" onsubmit="return false;" class="profile-data-col d-flex flex-column h-100 p-0" style="width: 45%; background-color: #ffffff; border-right: 1px solid rgba(0,0,0,0.06);">
                        <div class="p-4 flex-grow-1 overflow-auto">
                            <div class="profile-avatar-large">
                                <img id="profAvatarImg" src="../../docs/identidad_visual/logos/isologo.png" alt="Avatar">
                                <div>
                                    <h4 class="brand-font fw-bold mb-1" id="profTitleName" style="color: #37414A;">Nombre Cliente</h4>
                                    <span class="badge bg-light text-secondary border" id="profTitleId">ID: CLI-000</span>
                                </div>
                            </div>

                            <div class="form-group mt-3">
                                <label>Nombre Comercial / Razón Social</label>
                                <input type="text" id="profName" class="form-control-custom" autocomplete="off" placeholder="Ej. Empresa S.A.">
                            </div>
                            <div class="form-group">
                                <label>Sede Asociada</label>
                                <select id="profSede" class="form-control-custom">
                                    <option value="">General / Central</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Número de WhatsApp</label>
                                <div class="d-flex gap-2">
                                    <select class="form-select" id="profPrefix" style="max-width: 120px;">
                                        <option value="58414">0414</option>
                                        <option value="58424">0424</option>
                                        <option value="58412">0412</option>
                                        <option value="58416">0416</option>
                                        <option value="58426">0426</option>
                                    </select>
                                    <input type="text" id="profPhone" class="form-control-custom" style="flex: 1;" autocomplete="off" placeholder="1234567">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Dirección</label>
                                <textarea id="profAddress" class="form-control-custom" rows="2" autocomplete="off" placeholder="Ubicación física..."></textarea>
                            </div>
                            <div class="form-group">
                                <label>Etiquetas (Tags)</label>
                                <div class="d-flex gap-2 flex-wrap mt-1" id="profTagsContainer">
                                    <button type="button" id="btnAddProfileTag" class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2 rounded-pill" style="cursor: pointer;" onclick="addProfileTag(this)">+ Añadir Etiqueta</button>
                                </div>
                            </div>
                            <div class="form-group mb-2">
                                <label>Notas Internas</label>
                                <textarea id="profNotes" class="form-control-custom" rows="3" autocomplete="off" placeholder="Información relevante solo para agentes..."></textarea>
                            </div>
                        </div>

                        <div class="p-3 border-top bg-white">
                            <button id="btnEditProfile" type="button" class="btn btn-outline-primary w-100 py-2.5 fw-bold" style="border-radius: 10px;">
                                <i class="fa-solid fa-pen-to-square me-2"></i>Editar Perfil
                            </button>
                            <button id="btnSaveProfile" type="button" class="btn btn-starfi-primary w-100 py-2.5 fw-bold" style="border-radius: 10px; box-shadow: 0 4px 12px rgba(232, 91, 20, 0.2); display: none;">
                                <i class="fa-solid fa-floppy-disk me-2"></i>Guardar Cambios
                            </button>
                        </div>
                    </form>

                    <!-- Columna Derecha: Pestañas de Historial (Conversaciones y Ventas) -->
                    <div class="profile-timeline-col">
                        <ul class="nav nav-tabs profile-nav-tabs mb-3" id="profileTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-conv-btn" data-bs-toggle="tab" data-bs-target="#tab-conv-pane" type="button" role="tab">
                                    <i class="fa-solid fa-comments me-2 text-starfi-primary"></i>Conversaciones
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-ventas-btn" data-bs-toggle="tab" data-bs-target="#tab-ventas-pane" type="button" role="tab">
                                    <i class="fa-solid fa-cart-shopping me-2 text-success"></i>Historial de Ventas
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content flex-grow-1 overflow-auto" id="profileTabsContent">
                            <div class="tab-pane fade show active" id="tab-conv-pane" role="tabpanel">
                                <div class="timeline-feed" id="profileConversationsFeed"></div>
                            </div>
                            <div class="tab-pane fade" id="tab-ventas-pane" role="tabpanel">
                                <div class="timeline-feed" id="profileSalesFeed"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Centro de Difusión Inteligente -->
<div class="modal fade" id="broadcastModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1380px;">
        <div class="modal-content overflow-hidden" style="border-radius: 16px; border: none; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);">
            <div class="modal-header border-0 px-4 py-3" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); color: #fff;">
                <div class="d-flex align-items-center gap-3">
                    <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(232, 91, 20, 0.2); border: 1px solid #E85B14; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #FF8A4D;">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0 fw-bold text-white">Centro de Difusión Inteligente • STARFI CRM</h5>
                        <small style="color: #94A3B8;">Despacho oficial vía Meta WhatsApp Cloud API conectado con el Asistente IA de cada sede</small>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <ul class="nav nav-pills bg-dark bg-opacity-50 p-1 rounded-pill" id="bcTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active rounded-pill px-3 py-1 small fw-bold text-white" id="bc-new-tab" data-bs-toggle="pill" data-bs-target="#bc-new-pane" type="button" role="tab">
                                <i class="fa-solid fa-paper-plane me-1"></i> Nueva Difusión
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link rounded-pill px-3 py-1 small fw-bold text-white" id="bc-history-tab" data-bs-toggle="pill" data-bs-target="#bc-history-pane" type="button" role="tab">
                                <i class="fa-solid fa-clock-rotate-left me-1"></i> Historial de Campañas
                            </button>
                        </li>
                    </ul>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>

            <div class="modal-body p-0">
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="bc-new-pane" role="tabpanel">
                        <form id="formBroadcastDirectorio" autocomplete="off" onsubmit="return false;" class="row g-0" style="min-height: 700px;">
                            <div class="col-12 col-lg-7 p-4 border-end" style="background: #FFFFFF; max-height: 760px; overflow-y: auto;">
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-uppercase text-muted mb-1">1. Sede / Línea Emisora de WhatsApp</label>
                                        <select id="bcSedeSelect" class="form-select fw-semibold" style="border-radius: 10px; padding: 10px 14px;">
                                            <option value="">Cargando líneas activas...</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label small fw-bold text-uppercase text-muted mb-1">Nombre Interno de Campaña</label>
                                        <input type="text" id="bcCampaignName" class="form-control" autocomplete="off" style="border-radius: 10px; padding: 10px 14px;" placeholder="Ej. Inauguración Sede Principal">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-uppercase text-muted mb-2">2. Origen de los Destinatarios</label>
                                    <div class="d-flex gap-2 mb-3">
                                        <div class="mode-pill-btn active" data-mode="directorio" onclick="setBroadcastRecipientMode('directorio')">
                                            <i class="fa-solid fa-users me-1"></i> Directorio CRM
                                        </div>
                                        <div class="mode-pill-btn" data-mode="manual" onclick="setBroadcastRecipientMode('manual')">
                                            <i class="fa-solid fa-file-csv me-1"></i> Pegar Lista / Excel / CSV
                                        </div>
                                        <div class="mode-pill-btn" data-mode="prueba" onclick="setBroadcastRecipientMode('prueba')">
                                            <i class="fa-solid fa-flask me-1"></i> Envío de Prueba (1 Número)
                                        </div>
                                    </div>

                                    <div id="bcBoxDirectorio" class="p-3 rounded-3 border" style="background: #F8FAFC;">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                            <div>
                                                <select id="bcDirectoryScope" class="form-select form-select-sm fw-semibold" style="min-width: 280px; border-radius: 8px;">
                                                    <option value="selected">Enviar a los contactos seleccionados en la tabla</option>
                                                    <option value="sede">Enviar a TODOS los contactos de la Sede emisora</option>
                                                    <option value="all">Enviar a TODO el Directorio Activo</option>
                                                </select>
                                            </div>
                                            <span class="badge bg-primary px-3 py-2 rounded-pill" id="bcDirCountBadge" style="font-size: 0.82rem;">0 destinatarios listos</span>
                                        </div>
                                    </div>

                                    <div id="bcBoxManual" class="p-3 rounded-3 border" style="background: #F8FAFC; display: none;">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <small class="text-muted fw-semibold">Escribe o pega un contacto por línea: <code>Nombre, Teléfono</code> o solo <code>Teléfono</code></small>
                                            <div>
                                                <input type="file" id="bcCsvInput" accept=".csv,.txt" style="display:none;">
                                                <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3" onclick="$('#bcCsvInput').click()">
                                                    <i class="fa-solid fa-upload me-1"></i> Subir CSV / TXT
                                                </button>
                                            </div>
                                        </div>
                                        <textarea id="bcManualList" class="form-control font-monospace" rows="4" autocomplete="off" style="font-size: 0.85rem; border-radius: 8px;"></textarea>
                                        <div class="d-flex justify-content-between align-items-center mt-2">
                                            <small class="text-muted">Los números nacionales se formatean automáticamente.</small>
                                            <span class="badge bg-success px-3 py-1 rounded-pill" id="bcManualCountBadge">0 números válidos detectados</span>
                                        </div>
                                    </div>

                                    <div id="bcBoxPrueba" class="p-3 rounded-3 border" style="background: #FFFBEB; border-color: #FDE68A !important; display: none;">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-12 col-md-5">
                                                <label class="small fw-bold text-dark mb-1">Nombre de Prueba ({{1}})</label>
                                                <input type="text" id="bcTestName" class="form-control form-control-sm" autocomplete="off" value="Cliente" placeholder="Ej. Cliente">
                                            </div>
                                            <div class="col-12 col-md-7">
                                                <label class="small fw-bold text-dark mb-1">Teléfono WhatsApp de Prueba</label>
                                                <input type="text" id="bcTestPhone" class="form-control form-control-sm" autocomplete="off" placeholder="Ej. 04140000000">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="p-3 rounded-3 border mb-3" style="background: #FFFFFF;">
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 pb-2 border-bottom">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge bg-success"><i class="fa-solid fa-check-circle me-1"></i> ACTIVA EN META</span>
                                            <select id="bcTemplateSelect" class="form-select form-select-sm fw-bold text-dark" style="width: auto; min-width: 230px; border-radius: 8px;">
                                                <option value="starfi_difusion_directa" data-lang="es">starfi_difusion_directa (es)</option>
                                            </select>
                                        </div>
                                        <div class="d-flex gap-1 flex-wrap">
                                            <span class="preset-chip" onclick="applyBroadcastPreset('inauguracion')">Preset: Inauguración</span>
                                            <span class="preset-chip" onclick="applyBroadcastPreset('ofertas')">Preset: Ofertas</span>
                                        </div>
                                    </div>

                                    <div class="row g-2 align-items-center mb-3">
                                        <div class="col-12 col-md-7">
                                            <label class="small fw-bold text-dark mb-0"><span class="badge bg-dark me-1">{{1}}</span> Saludo Personalizado (Automático)</label>
                                            <small class="d-block text-muted">Se inserta automáticamente el primer nombre de cada cliente.</small>
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <label class="small text-muted mb-1">Si el número no tiene nombre usar:</label>
                                            <input type="text" id="bcDefaultName" class="form-control form-control-sm fw-semibold" autocomplete="off" value="Amigo(a)">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <label class="small fw-bold text-dark mb-0"><span class="badge bg-primary me-1">{{2}}</span> Cuerpo del Mensaje</label>
                                            <small class="text-muted" id="bcParam2Counter">0 caracteres</small>
                                        </div>
                                        <textarea id="bcParam2" class="form-control" rows="3" autocomplete="off" style="border-radius: 10px; font-size: 0.92rem;">compartirte nuestras novedades y promociones disponibles para ti</textarea>
                                    </div>

                                    <div class="mb-1">
                                        <label class="small fw-bold text-dark mb-1"><span class="badge bg-warning text-dark me-1">{{3}}</span> Eslogan Dinámico</label>
                                        <input type="text" id="bcParam3" class="form-control" autocomplete="off" style="border-radius: 10px; font-size: 0.92rem;" value="nuestra sede estamos cada día más cerca de ti">
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-lg-5 p-4 d-flex flex-column justify-content-between" style="background: #F8FAFC; max-height: 760px; overflow-y: auto;">
                                <div>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="small fw-bold text-uppercase text-muted"><i class="fa-brands fa-whatsapp text-success me-1"></i> Vista Previa en Vivo (WhatsApp)</span>
                                        <span class="badge bg-light text-dark border" id="bcPreviewSedeBadge">Sede Oficial</span>
                                    </div>

                                    <div class="wa-preview-frame mb-4">
                                        <div class="wa-preview-header">
                                            <div style="width: 36px; height: 36px; border-radius: 50%; background: #ffffff; color: #075E54; display: flex; align-items: center; justify-content: center; font-weight: 800;">
                                                <i class="fa-solid fa-store"></i>
                                            </div>
                                            <div class="lh-sm">
                                                <div class="fw-bold" style="font-size: 0.92rem;" id="waHeaderTitle">Sede Principal</div>
                                                <small style="font-size: 0.72rem; color: #D1FAE5;"><i class="fa-solid fa-circle-check me-1"></i>Cuenta de empresa oficial</small>
                                            </div>
                                        </div>
                                        <div class="p-2">
                                            <div class="wa-preview-bubble">
                                                <div id="waPreviewBodyText" style="white-space: pre-line;"></div>
                                                <div class="wa-preview-footer">
                                                    <span>Servicio de mensajería inteligente de STARFI CRM</span>
                                                    <span>10:00 a. m.</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="p-3 rounded-3 border bg-white shadow-sm">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="small fw-bold text-dark"><i class="fa-solid fa-bolt text-warning me-1"></i> Consola de Despacho por Lotes</span>
                                            <span class="badge bg-secondary" id="bcStatusLabel">Listo para enviar</span>
                                        </div>

                                        <div class="progress mb-3" style="height: 12px; border-radius: 999px; background-color: #E2E8F0;">
                                            <div id="bcProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%;"></div>
                                        </div>

                                        <div class="row g-2 text-center mb-3">
                                            <div class="col-4">
                                                <div class="p-2 rounded bg-light border">
                                                    <div class="small text-muted fw-semibold" style="font-size: 0.72rem;">EN COLA</div>
                                                    <div class="fw-bold fs-5 text-dark" id="bcMetricTotal">0</div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="p-2 rounded border" style="background: #ECFDF5; border-color: #A7F3D0 !important;">
                                                    <div class="small text-success fw-semibold" style="font-size: 0.72rem;">ENVIADOS</div>
                                                    <div class="fw-bold fs-5 text-success" id="bcMetricSent">0</div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="p-2 rounded border" style="background: #FEF2F2; border-color: #FECACA !important;">
                                                    <div class="small text-danger fw-semibold" style="font-size: 0.72rem;">FALLIDOS</div>
                                                    <div class="fw-bold fs-5 text-danger" id="bcMetricFailed">0</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div id="bcLiveLog" class="border rounded p-2 font-monospace" style="height: 145px; overflow-y: auto; background: #0F172A; color: #E2E8F0; font-size: 0.76rem;">
                                            <div class="text-secondary">&gt; Esperando orden de disparo...</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex gap-2 mt-3 pt-3 border-top">
                                    <button type="button" id="btnStartBroadcast" class="btn btn-starfi-primary flex-grow-1 py-2.5 fw-bold d-flex align-items-center justify-content-center gap-2" style="border-radius: 10px; font-size: 1rem; box-shadow: 0 6px 20px rgba(232, 91, 20, 0.3);">
                                        <i class="fa-solid fa-paper-plane"></i> Iniciar Difusión Ahora
                                    </button>
                                    <button type="button" id="btnStopBroadcast" class="btn btn-danger py-2.5 px-4 fw-bold" style="border-radius: 10px; display: none;">
                                        <i class="fa-solid fa-stop me-1"></i> Detener
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade p-4" id="bc-history-pane" role="tabpanel" style="min-height: 550px;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0"><i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Últimas Campañas de Difusión Ejecutadas</h6>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="loadBroadcastConfig()">
                                <i class="fa-solid fa-rotate me-1"></i> Actualizar
                            </button>
                        </div>
                        <div class="table-responsive border rounded-3 bg-white">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>ID</th>
                                        <th>Fecha</th>
                                        <th>Campaña</th>
                                        <th>Sede Emisora</th>
                                        <th>Plantilla</th>
                                        <th>Mensaje ({{2}})</th>
                                        <th class="text-center">Total</th>
                                        <th class="text-center">Enviados</th>
                                        <th class="text-center">Fallidos</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody id="bcHistoryTableBody">
                                    <tr><td colspan="10" class="text-center py-4 text-muted">Sin campañas registradas.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
