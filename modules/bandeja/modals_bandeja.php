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
                        <input type="text" id="profPrevNameInput" class="form-control border-start-0 fw-bold text-center" placeholder="Nombre del Cliente">
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
                        <input type="email" id="profPrevEmailInput" class="form-control form-control-sm" placeholder="cliente@correo.com">
                    </div>

                    <div class="detail-group bg-white p-3 mb-3" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-2 d-block"><i class="fa-solid fa-tags me-1"></i> CENTRO DE ETIQUETAS</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="newTagInput" class="form-control" placeholder="Añadir o seleccionar etiqueta..." list="existingTagsList">
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
                  <input type="text" id="qdTitulo" class="form-control" placeholder="Ej: Cotización Láminas PVC / Mobiliario" style="border-radius: 10px;">
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
                      <input type="number" step="0.01" min="0" id="qdMontoUsd" class="form-control fw-bold" value="0.00">
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
                    <input type="number" min="1" max="1440" id="quickSlaVerdeInput" class="form-control fw-bold" value="<?= $v_min_init ?>">
                    <small class="text-muted" style="font-size:0.7rem;">Ej: 5 min</small>
                  </div>
                  <div class="col-6">
                    <label class="form-label small fw-bold text-danger mb-1">🔴 Límite Rojo Crítico (min)</label>
                    <input type="number" min="2" max="2880" id="quickSlaAmarilloInput" class="form-control fw-bold" value="<?= $a_min_init ?>">
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
