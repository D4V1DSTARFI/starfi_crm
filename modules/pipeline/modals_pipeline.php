<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
    <!-- Modal Crear / Editar Oportunidad Comercial -->
    <div class="modal fade" id="modalDealKanban" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content"
                style="border-radius: 20px; border: none; box-shadow: 0 20px 50px rgba(55,65,74,0.22);">
                <div class="modal-header border-0"
                    style="background: linear-gradient(135deg, #37414A 0%, #262D34 100%); color: white; border-radius: 20px 20px 0 0; padding: 18px 24px;">
                    <h5 class="modal-title fw-bold mb-0" id="modalDealTitle"><i
                            class="fa-solid fa-sack-dollar text-warning me-2"></i>Nueva Oportunidad Comercial</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                        aria-label="Close"></button>
                </div>
                <form id="formDealKanban" autocomplete="off" onsubmit="return false;">
                    <div class="modal-body p-4" style="background:#F8FAFC;">
                        <input type="hidden" id="dealIdInput" value="0">
                        <div class="row g-3">
                            <div class="col-12 col-md-8">
                                <label class="form-label small fw-bold text-dark">Título / Producto Cotizado *</label>
                                <input type="text" id="dealTituloInput" class="form-control" autocomplete="off"
                                    placeholder="Ej: 25 Láminas Mármol PVC + Instalación">
                            </div>
                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-bold text-dark">Monto Estimado (USD) *</label>
                                <div class="input-group">
                                    <span class="input-group-text fw-bold bg-white text-success">$</span>
                                    <input type="number" step="0.01" min="0" id="dealMontoInput" autocomplete="off"
                                        class="form-control fw-bold" value="0.00">
                                    <span class="input-group-text small text-muted bg-light">USD</span>
                                </div>
                            </div>

                            <!-- Bloque Cliente (visible al crear) -->
                            <div class="col-12" id="dealClientBlock">
                                <div class="p-3 bg-white rounded-3 border">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-bold text-dark mb-0"><i
                                                class="fa-brands fa-whatsapp text-success me-1"></i>Cliente
                                            Vinculado</label>
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" id="chkNuevoProspecto">
                                            <label class="form-check-label small fw-semibold text-muted"
                                                for="chkNuevoProspecto">Registrar número nuevo</label>
                                        </div>
                                    </div>
                                    <div id="existingClientPane">
                                        <input type="text" id="searchClientInput" class="form-control form-control-sm mb-2"
                                            autocomplete="off"
                                            placeholder="Escribe nombre o WhatsApp para filtrar clientes...">
                                        <select id="dealClienteSelect" class="form-select form-select-sm"></select>
                                    </div>
                                    <div id="newClientPane" style="display:none;">
                                        <div class="row g-2">
                                            <div class="col-12 col-md-6">
                                                <input type="text" id="dealNuevoNombre" class="form-control form-control-sm"
                                                    autocomplete="off"
                                                    placeholder="Nombre del cliente (Ej: María Pérez)">
                                            </div>
                                            <div class="col-12 col-md-6">
                                                <input type="text" id="dealNuevoTelefono"
                                                    class="form-control form-control-sm" autocomplete="off"
                                                    placeholder="WhatsApp con código país (Ej: 584121234567)">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-md-4">
                                <label class="form-label small fw-bold text-dark">Etapa del Embudo</label>
                                <select id="dealEtapaSelect" class="form-select">
                                    <option value="NUEVO_LEAD">📥 Nuevo Lead (20%)</option>
                                    <option value="COTIZADO">📄 Cotizado (45%)</option>
                                    <option value="EN_NEGOCIACION">🤝 En Negociación (70%)</option>
                                    <option value="PAGO_PENDIENTE">💳 Pago Pendiente (90%)</option>
                                    <option value="GANADO">🏆 Ganado (100%)</option>
                                    <option value="PERDIDO">❌ Perdido (0%)</option>
                                </select>
                            </div>

                            <?php if ($is_master): ?>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-bold text-dark">Sede</label>
                                    <select id="dealSedeSelect" class="form-select">
                                        <?php foreach ($sedes as $s): ?>
                                            <option value="<?= intval($s['id']) ?>"><?= htmlspecialchars($s['nombre_sede']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" id="dealSedeSelect" value="<?= intval($id_sede_agente) ?>">
                            <?php endif; ?>

                            <?php if (!$is_operador): ?>
                                <div class="col-12 col-md-4">
                                    <label class="form-label small fw-bold text-dark">Asesor Responsable</label>
                                    <select id="dealAgenteSelect" class="form-select">
                                        <option value="0">-- Sin asignar --</option>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" id="dealAgenteSelect" value="<?= intval($agente_id) ?>">
                            <?php endif; ?>

                            <div class="col-12" id="dealMotivoPerdidaWrap" style="display:none;">
                                <label class="form-label small fw-bold text-danger">Motivo de Pérdida</label>
                                <input type="text" id="dealMotivoPerdidaInput" class="form-control" autocomplete="off"
                                    placeholder="Ej: Precio fuera de presupuesto / Compró en otra tienda / Sin respuesta">
                            </div>

                            <div class="col-12">
                                <label class="form-label small fw-bold text-dark">Notas Comerciales / Seguimiento</label>
                                <textarea id="dealNotasInput" class="form-control" rows="2" autocomplete="off"
                                    placeholder="Detalles de medidas, condiciones de pago, fecha tentativa de compra..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer border-0 bg-white d-flex justify-content-between"
                        style="border-radius: 0 0 20px 20px; padding: 14px 24px;">
                        <div>
                            <?php if (!$is_operador): ?>
                                <button type="button" id="btnDeleteDeal" class="btn btn-outline-danger btn-sm rounded-pill px-3"
                                    style="display:none;">
                                    <i class="fa-solid fa-trash-can me-1"></i> Eliminar
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light border rounded-pill px-4"
                                data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" id="btnSaveDealModal" class="btn text-white fw-bold rounded-pill px-4"
                                style="background:linear-gradient(135deg,#E85B14,#FF7A33); border:none;">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Guardar Oportunidad
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
