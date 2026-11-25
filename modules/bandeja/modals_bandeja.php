<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
    <!-- Modal Perfil 360 -->
    <div class="modal fade" id="modalProfile360" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
          <div class="modal-header border-0" style="background-color: #F8FAFC; border-radius: 20px 20px 0 0; padding: 20px 25px;">
            <h5 class="modal-title fw-bold text-starfi-dark"><i class="fa-solid fa-id-card text-primary me-2"></i>Perfil 360</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4 text-center" style="background-color: #F8FAFC; border-radius: 0 0 20px 20px;">
            <form id="formProfile360" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="profile-card bg-white p-4 mb-3" style="border-radius: 16px; border: 1px solid #E2E8F0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                    <img id="profPrevImg" src="<?= htmlspecialchars(starfi_local_avatar_data_uri('Cliente', '#F1F5F9', '#37414A'), ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" style="border-radius: 50%; margin-bottom: 15px; width: 100px; height: 100px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); object-fit: cover;">
                    <div class="input-group mb-2">
                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-user text-muted"></i></span>
                        <input type="text" id="profPrevNameInput" autocomplete="off" class="form-control border-start-0 fw-bold text-center" placeholder="Nombre del Cliente">
                    </div>
                    <p class="text-muted small mb-0">Cliente CRM</p>
                </div>
                <div class="profile-details text-start">
                    <div class="detail-group bg-white p-3 mb-2" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-1 d-block"><i class="fa-solid fa-phone me-1"></i> Teléfono</label>
                        <p id="profPrevPhone" class="mb-0 fw-semibold text-dark fs-5">+58 412 9876543</p>
                    </div>
                    
                    <div class="detail-group bg-white p-3 mb-2" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-1 d-block"><i class="fa-solid fa-envelope me-1"></i> Correo Electrónico</label>
                        <input type="email" id="profPrevEmailInput" autocomplete="off" class="form-control form-control-sm" placeholder="cliente@correo.com">
                    </div>

                    <div class="detail-group bg-white p-3 mb-3" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-2 d-block"><i class="fa-solid fa-tags me-1"></i> CENTRO DE ETIQUETAS</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="newTagInput" autocomplete="off" class="form-control" placeholder="Añadir o seleccionar etiqueta..." list="existingTagsList">
                            <datalist id="existingTagsList"></datalist>
                            <button class="btn btn-outline-primary" type="button" id="btnAddTag"><i class="fa-solid fa-plus"></i></button>
                        </div>
                        <div class="tags" id="profTagsContainer"></div>
                    </div>
                    
                    <button type="button" class="btn btn-primary w-100 fw-bold shadow-sm" id="btnSaveProfile" style="border-radius: 10px; padding: 10px;"><i class="fa-solid fa-save me-2"></i> Guardar Cambios</button>
                </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Plantillas Rápidas -->
    <div class="modal fade" id="modalTemplates" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
          <div class="modal-header border-0" style="background-color: #F8FAFC; border-radius: 20px 20px 0 0; padding: 20px 25px; display: flex; justify-content: space-between; align-items: center;">
            <h5 class="modal-title fw-bold text-starfi-dark m-0"><i class="fa-solid fa-bolt text-warning me-2"></i>Respuestas Rápidas</h5>
            <div>
                <button type="button" class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-3 me-2" onclick="createNewQuickReply()"><i class="fa-solid fa-plus me-1"></i> Nueva</button>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
          </div>
          <div class="modal-body p-3" style="background-color: #F8FAFC; border-radius: 0 0 20px 20px; max-height: 70vh; overflow-y: auto;">
            <div class="mb-4">
                <h6 class="fw-bold text-muted mb-3"><i class="fa-solid fa-bolt me-1 text-warning"></i> Respuestas Rápidas</h6>
                <div class="list-group list-group-flush gap-2" id="templatesList">
                    <div class="text-center p-3 text-muted">
                        <i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando respuestas...
                    </div>
                </div>
            </div>
            
            <div class="border-top pt-3">
                <h6 class="fw-bold text-muted mb-3"><i class="fa-solid fa-robot me-1 text-primary"></i> Respuestas Automáticas (Bot)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle" style="font-size: 0.85rem; border-collapse: separate; border-spacing: 0 4px;">
                        <thead class="table-light">
                            <tr>
                                <th style="border: none;">Disparador</th>
                                <th style="border: none;">Mensaje</th>
                                <th style="border: none; text-align: right;">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="botAnswersList">
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">Cargando respuestas automáticas...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- [COM-01] Modal Reactivar Conversación con Plantilla Oficial Meta -->
    <div class="modal fade" id="modalReactivateMeta" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.18);">
          <div class="modal-header border-0" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); color: white; border-radius: 20px 20px 0 0; padding: 18px 24px;">
            <div>
              <h5 class="modal-title fw-bold mb-0"><i class="fa-brands fa-whatsapp text-success me-2"></i>Reactivar Conversación con Plantilla Oficial Meta</h5>
              <small class="text-white-50" style="font-size: 0.78rem;">Envío homologado por WhatsApp Business Cloud API v23.0 para clientes fuera de la ventana de 24h</small>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4" style="background-color: #F8FAFC;">
            <form id="formReactivateMeta" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="row g-3">
                  <div class="col-12 col-md-7">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                      <label class="form-label fw-bold text-dark small mb-0">1. Selecciona Plantilla Oficial Aprobada</label>
                      <button type="button" id="btnRefreshMetaTpls" class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold" style="font-size:0.75rem; color:#E85B14;">
                        <i class="fa-solid fa-rotate me-1"></i> Sincronizar con Meta
                      </button>
                    </div>
                    <select id="selectMetaOfficialTpl" class="form-select mb-3" style="border-radius: 12px;"></select>

                    <div id="metaTplMetaBadges" class="d-flex gap-2 mb-3 flex-wrap"></div>

                    <div id="metaTplVarsContainer"></div>
                  </div>
                  <div class="col-12 col-md-5">
                    <label class="form-label fw-bold text-dark small mb-1">Vista Previa en WhatsApp</label>
                    <div class="p-3 rounded-4" style="background-color: #ECE5DD; min-height: 210px; border: 1px solid #E2E8F0;">
                      <div class="bg-white p-3 shadow-sm" style="border-radius: 14px 14px 4px 14px; font-size: 0.86rem; color: #111827; white-space: pre-line; line-height: 1.45;" id="metaTplLivePreview">
                        Selecciona una plantilla para previsualizar el mensaje...
                      </div>
                    </div>
                  </div>
                </div>
            </form>
          </div>
          <div class="modal-footer border-0 bg-white" style="border-radius: 0 0 20px 20px; padding: 14px 24px;">
            <button type="button" class="btn btn-light border rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" id="btnSendMetaReactivate" class="btn text-white fw-bold rounded-pill px-4" style="background: linear-gradient(135deg, #10B981, #059669); border: none;">
              <i class="fa-solid fa-paper-plane me-1"></i> Enviar Plantilla Oficial
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- [COM-02] Modal Rápido de Oportunidad / Embudo Kanban USD -->
    <div class="modal fade" id="modalQuickDeal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
          <div class="modal-header border-0" style="background-color: #F8FAFC; border-radius: 20px 20px 0 0; padding: 18px 24px;">
            <h5 class="modal-title fw-bold text-starfi-dark"><i class="fa-solid fa-filter-circle-dollar text-primary me-2"></i>Oportunidad en Embudo de Ventas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <form id="formQuickDeal" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="mb-3">
                  <label class="form-label fw-bold small text-muted">Título / Producto de Interés</label>
                  <input type="text" id="qdTitulo" autocomplete="off" class="form-control" placeholder="Ej: Cotización Láminas PVC / Mobiliario" style="border-radius: 10px;">
                </div>
                <div class="row g-3 mb-3">
                  <div class="col-7">
                    <label class="form-label fw-bold small text-muted">Etapa del Embudo</label>
                    <select id="qdEtapa" class="form-select" style="border-radius: 10px;">
                      <option value="NUEVO_LEAD">📥 Nuevo Lead</option>
                      <option value="COTIZADO">📄 Cotizado</option>
                      <option value="EN_NEGOCIACION">🤝 En Negociación</option>
                      <option value="PAGO_PENDIENTE">💳 Pago Pendiente</option>
                      <option value="GANADO">🏆 Ganado (Venta Cerrada)</option>
                      <option value="PERDIDO">❌ Perdido</option>
                    </select>
                  </div>
                  <div class="col-5">
                    <label class="form-label fw-bold small text-muted">Monto Estimado (USD)</label>
                    <div class="input-group">
                      <span class="input-group-text fw-bold">$</span>
                      <input type="number" step="0.01" min="0" id="qdMontoUsd" autocomplete="off" class="form-control fw-bold" value="0.00">
                    </div>
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2">
                  <a href="../pipeline/pipeline.php" class="text-decoration-none small fw-bold" style="color:#E85B14;">
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Ir al Tablero Kanban completo
                  </a>
                  <button type="button" id="btnSaveQuickDeal" class="btn btn-primary fw-bold rounded-pill px-4" style="background-color:#E85B14; border-color:#E85B14;">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Guardar en Embudo
                  </button>
                </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- [COM-04] Modal Rápido de Configuración de Tiempos SLA (Master/Admin) -->
    <?php if (!$is_operador): ?>
    <div class="modal fade" id="modalQuickSlaConfig" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 18px 45px rgba(15,23,42,0.18);">
          <div class="modal-header border-0" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); color: #fff; border-radius: 20px 20px 0 0; padding: 18px 24px;">
            <div>
              <h5 class="modal-title fw-bold mb-0"><i class="bi bi-speedometer2 text-warning me-2"></i>Configurar Tiempos del Semáforo SLA</h5>
              <small class="text-white-50" id="quickSlaScopeSubtitle" style="font-size:0.75rem;">Ajusta los minutos para Verde, Amarillo y Rojo Crítico</small>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4" style="background:#F8FAFC;">
            <form id="formQuickSlaConfig" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="p-3 mb-3 rounded-3 bg-white border d-flex align-items-center justify-content-around" style="font-size:0.82rem;">
                  <span class="text-success fw-bold">🟢 &lt;<span id="prevQuickVerde"><?= $v_min_init ?></span>m</span>
                  <span class="text-warning-emphasis fw-bold">🟡 <span id="prevQuickAmarillo"><?= $v_min_init ?>-<?= $a_min_init ?></span>m</span>
                  <span class="text-danger fw-bold">🔴 &gt;<span id="prevQuickRojo"><?= $a_min_init ?></span>m (Alerta)</span>
                </div>
                <div class="row g-3">
                  <div class="col-6">
                    <label class="form-label small fw-bold text-success mb-1">🟢 Máximo en Verde (min)</label>
                    <input type="number" min="1" max="1440" id="quickSlaVerdeInput" autocomplete="off" class="form-control fw-bold" value="<?= $v_min_init ?>">
                    <small class="text-muted" style="font-size:0.7rem;">Ej: 5 min</small>
                  </div>
                  <div class="col-6">
                    <label class="form-label small fw-bold text-danger mb-1">🔴 Límite Rojo Crítico (min)</label>
                    <input type="number" min="2" max="2880" id="quickSlaAmarilloInput" autocomplete="off" class="form-control fw-bold" value="<?= $a_min_init ?>">
                    <small class="text-muted" style="font-size:0.7rem;">Dispara alerta sonora (Ej: 15 min)</small>
                  </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-4 pt-2 border-top">
                  <?php if (hasPermission('configuracion')): ?>
                  <a href="../configuracion/configuracion.php?tab=sla" class="text-decoration-none small fw-bold" style="color:#E85B14;">
                    <i class="bi bi-sliders me-1"></i> Ir al módulo completo de SLA por Sedes
                  </a>
                  <?php else: ?>
                  <span></span>
                  <?php endif; ?>
                  <button type="button" id="btnSaveQuickSla" class="btn text-white fw-bold rounded-pill px-4" style="background:linear-gradient(135deg,#E85B14,#FF7A33); border:none;">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Guardar Tiempos
                  </button>
                </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- [SPRINT 4: OMN-02] Modal de Tareas, Recordatorios y Agenda Comercial por Cliente -->
    <div class="modal fade" id="modalAgendaCliente" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 50px rgba(15,23,42,0.18);">
          <div class="modal-header border-0" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); color: #ffffff; border-radius: 20px 20px 0 0; padding: 18px 24px;">
            <div>
              <h5 class="modal-title fw-bold mb-0"><i class="fa-solid fa-calendar-check me-2" style="color:#38BDF8;"></i>Agenda Comercial y Recordatorios del Cliente</h5>
              <small class="text-white-50" id="agendaClientSubtitle" style="font-size:0.78rem;">Programa seguimientos con alertas en tiempo real en la barra superior</small>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4" style="background-color: #F8FAFC;">
            <div class="row g-3">
              <div class="col-12 col-lg-5">
                <div class="bg-white p-3 rounded-4 border shadow-sm">
                  <h6 class="fw-bold text-dark mb-3" style="font-size:0.9rem;"><i class="fa-solid fa-plus-circle text-primary me-1"></i> Nueva Tarea / Recordatorio</h6>
                  <form id="formCreateClientTask" autocomplete="off" onsubmit="event.preventDefault();">
                    <div class="mb-2">
                      <label class="form-label small fw-bold text-secondary mb-1">Título del Seguimiento</label>
                      <input type="text" id="agTaskTitulo" autocomplete="off" class="form-control form-control-sm" maxlength="200" placeholder="Ej: Confirmar transferencia / despacho">
                    </div>
                    <div class="row g-2 mb-2">
                      <div class="col-6">
                        <label class="form-label small fw-bold text-secondary mb-1">Tipo</label>
                        <select id="agTaskTipo" class="form-select form-select-sm">
                          <option value="SEGUIMIENTO">💬 Seguimiento</option>
                          <option value="LLAMADA">📞 Llamada</option>
                          <option value="COTIZACION">📄 Cotización</option>
                          <option value="REUNION">🤝 Reunión</option>
                          <option value="COBRO">💳 Cobro</option>
                          <option value="OTRO">📌 Otro</option>
                        </select>
                      </div>
                      <div class="col-6">
                        <label class="form-label small fw-bold text-secondary mb-1">Prioridad</label>
                        <select id="agTaskPrioridad" class="form-select form-select-sm">
                          <option value="MEDIA">🟡 Media</option>
                          <option value="ALTA">🟠 Alta</option>
                          <option value="URGENTE">🔴 Urgente</option>
                          <option value="BAJA">🟢 Baja</option>
                        </select>
                      </div>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small fw-bold text-secondary mb-1">Fecha y Hora Límite</label>
                      <input type="datetime-local" id="agTaskFechaVenc" autocomplete="off" class="form-control form-control-sm">
                    </div>
                    <div class="mb-2">
                      <label class="form-label small fw-bold text-secondary mb-1">Alertar en Barra Superior</label>
                      <select id="agTaskRecordatorioMin" class="form-select form-select-sm">
                        <option value="15">15 minutos antes</option>
                        <option value="5">5 minutos antes</option>
                        <option value="30">30 minutos antes</option>
                        <option value="60">1 hora antes</option>
                        <option value="0">Al momento exacto</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label small fw-bold text-secondary mb-1">Notas / Detalles (Opcional)</label>
                      <textarea id="agTaskDescripcion" autocomplete="off" class="form-control form-control-sm" rows="2" placeholder="Acuerdos con el cliente, condiciones, montos..."></textarea>
                    </div>
                    <button type="button" id="btnSaveClientTask" class="btn btn-sm w-100 text-white fw-bold py-2 rounded-pill shadow-sm" style="background: linear-gradient(135deg, #E85B14, #FF7A33); border: none;">
                      <i class="fa-solid fa-bell me-1"></i> Agendar Recordatorio
                    </button>
                  </form>
                </div>
              </div>
              <div class="col-12 col-lg-7">
                <div class="bg-white p-3 rounded-4 border shadow-sm h-100 d-flex flex-column">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="fw-bold text-dark mb-0" style="font-size:0.9rem;"><i class="fa-solid fa-list-check text-primary me-1"></i> Historial y Pendientes del Cliente</h6>
                    <span class="badge bg-light text-dark border rounded-pill" id="agClientTasksSummaryBadge">0 pendientes</span>
                  </div>
                  <div id="agClientTasksList" style="max-height: 360px; overflow-y: auto; flex: 1;">
                    <div class="text-center text-muted py-4 small">Selecciona un cliente para ver su agenda.</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- [SPRINT 4: OMN-03] Modal Cotizador Rápido PDF Membretado por Sede en el Chat -->
    <div class="modal fade" id="modalQuickQuotePdf" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 22px 55px rgba(15,23,42,0.22);">
          <div class="modal-header border-0" style="background: linear-gradient(135deg, #0F172A 0%, #37414A 100%); color: #ffffff; border-radius: 20px 20px 0 0; padding: 18px 26px;">
            <div>
              <h5 class="modal-title fw-bold mb-0"><i class="fa-solid fa-file-invoice-dollar me-2" style="color:#FF8A4D;"></i>Cotizador Rápido PDF en el Chat</h5>
              <small class="text-white-50" id="qqHeaderSubtitle" style="font-size:0.78rem;">Genera cotización oficial membretada por sede, envíala al WhatsApp del cliente y actualiza el Pipeline en USD</small>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4" style="background-color: #F8FAFC;">
            <form id="formQuickQuotePdf" autocomplete="off" onsubmit="event.preventDefault();">
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div class="small text-secondary fw-semibold">
                  <i class="fa-solid fa-user-check text-primary me-1"></i> Cliente: <strong class="text-dark" id="qqClientDisplay">-</strong>
                  <span class="mx-2">|</span>
                  <i class="fa-solid fa-store text-primary me-1"></i> Sede Emisora: <strong class="text-dark" id="qqSedeDisplay">Automática según línea</strong>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3" id="btnAddQuoteItemRow">
                  <i class="fa-solid fa-plus me-1"></i> Agregar Ítem
                </button>
              </div>

              <div class="table-responsive bg-white rounded-4 border shadow-sm mb-3">
                <table class="table table-sm align-middle mb-0" id="qqItemsTable">
                  <thead class="table-light" style="font-size:0.78rem; text-transform:uppercase; color:#475569;">
                    <tr>
                      <th style="padding:10px 14px; min-width:260px;">Descripción del Producto o Servicio</th>
                      <th style="width:110px;">Cantidad</th>
                      <th style="width:155px;">Precio Unit. (USD)</th>
                      <th style="width:140px; text-align:right;">Subtotal USD</th>
                      <th style="width:50px; text-align:center;"></th>
                    </tr>
                  </thead>
                  <tbody id="qqItemsTbody"></tbody>
                </table>
              </div>

              <div class="row g-3">
                <div class="col-12 col-lg-7">
                  <div class="bg-white p-3 rounded-4 border shadow-sm h-100">
                    <div class="row g-2 mb-2">
                      <div class="col-4">
                        <label class="form-label small fw-bold text-secondary mb-1">Validez (Días)</label>
                        <input type="number" min="1" max="90" id="qqValidezDias" autocomplete="off" class="form-control form-control-sm fw-bold" value="7">
                      </div>
                      <div class="col-4">
                        <label class="form-label small fw-bold text-secondary mb-1">Descuento (USD)</label>
                        <input type="number" step="0.01" min="0" id="qqDescuentoUsd" autocomplete="off" class="form-control form-control-sm" value="0.00">
                      </div>
                      <div class="col-4 d-flex align-items-end">
                        <div class="form-check mb-1">
                          <input class="form-check-input" type="checkbox" id="qqAplicaIva" value="1">
                          <label class="form-check-label small fw-bold text-dark" for="qqAplicaIva">Aplicar IVA (16%)</label>
                        </div>
                      </div>
                    </div>
                    <div class="mb-2">
                      <label class="form-label small fw-bold text-secondary mb-1">Condiciones Comerciales</label>
                      <input type="text" id="qqCondiciones" autocomplete="off" class="form-control form-control-sm" maxlength="350" value="Precios expresados en USD. Cotización sujeta a disponibilidad de inventario al momento del pago.">
                    </div>
                    <div>
                      <label class="form-label small fw-bold text-secondary mb-1">Notas Adicionales para el Cliente (Opcional)</label>
                      <textarea id="qqNotas" autocomplete="off" class="form-control form-control-sm" rows="2" placeholder="Ej: Incluye entrega inmediata en tienda o despacho coordinado..."></textarea>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-lg-5">
                  <div class="bg-white p-3 rounded-4 border shadow-sm h-100 d-flex flex-column justify-content-between">
                    <div>
                      <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Subtotal:</span>
                        <strong class="text-dark" id="qqSumSubtotal">$0.00 USD</strong>
                      </div>
                      <div class="d-flex justify-content-between small text-danger mb-1">
                        <span>Descuento:</span>
                        <strong id="qqSumDescuento">-$0.00 USD</strong>
                      </div>
                      <div class="d-flex justify-content-between small text-muted mb-2">
                        <span>IVA (16%):</span>
                        <strong class="text-dark" id="qqSumIva">$0.00 USD</strong>
                      </div>
                      <div class="p-2.5 rounded-3 d-flex justify-content-between align-items-center mt-2" style="padding:10px 14px; background:linear-gradient(135deg,#FFF7ED,#FFEDD5); border:1px solid #FDBA74;">
                        <span class="fw-bold text-dark">TOTAL COTIZADO:</span>
                        <span class="fw-bold fs-5" style="color:#C2410C;" id="qqSumTotal">$0.00 USD</span>
                      </div>
                    </div>
                    <div class="mt-3 pt-2 border-top small text-muted">
                      <i class="fa-solid fa-bolt text-warning me-1"></i> Al confirmar: se genera el PDF membretado de la sede, se envía al WhatsApp del cliente y se actualiza el Embudo a <strong>Cotizado</strong>.
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </div>
          <div class="modal-footer border-0 bg-white" style="border-radius: 0 0 20px 20px; padding: 14px 26px;">
            <button type="button" class="btn btn-light border rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" id="btnEmitAndSendQuotePdf" class="btn text-white fw-bold rounded-pill px-4 shadow-sm" style="background: linear-gradient(135deg, #E85B14, #FF7A33); border: none;">
              <i class="fa-brands fa-whatsapp me-1"></i> Generar PDF, Enviar a WhatsApp y Registrar en Pipeline
            </button>
          </div>
        </div>
      </div>
    </div>
