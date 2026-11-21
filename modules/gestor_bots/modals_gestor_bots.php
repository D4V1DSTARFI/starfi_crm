<?php
/**
 * Modales del Módulo Gestor de Bots - STARFI CRM
 */
?>
<!-- Modal Vendedores -->
<div class="modal fade" id="modalContactos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title brand-font fw-bold"><i class="fa-solid fa-address-book text-starfi-primary"></i> Vendedores del Bot</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p class="text-muted mb-0" style="font-size:0.9rem;">Estas tarjetas se envían cuando el bot menciona asesores.</p>
                    <button class="btn btn-primary btn-sm rounded-pill px-3" onclick="newContacto()"><i class="fa-solid fa-plus"></i> Nuevo</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-modern">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Teléfono</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="contactosTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Formulario Contacto -->
<div class="modal fade" id="modalContactoForm" tabindex="-1" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-light border-0">
                <h5 class="modal-title fw-bold" id="contactoModalTitle">Nuevo Vendedor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="contactoForm" autocomplete="off">
                    <input type="hidden" id="contacto_id" name="id">
                    <input type="hidden" id="contacto_sede" name="id_sede" value="0">
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Nombre Completo</label>
                        <input type="text" class="form-control bg-light" id="contacto_nombre" name="nombre" required placeholder="Ej. Gabriel Benitez">
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Número de WhatsApp</label>
                        <input type="text" class="form-control bg-light" id="contacto_telefono" name="telefono" required placeholder="Ej. +584120000000">
                    </div>
                    <div class="mb-4">
                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Estado</label>
                        <select class="form-select bg-light" id="contacto_estado" name="estado">
                            <option value="ACTIVO">Activo</option>
                            <option value="INACTIVO">Inactivo</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 rounded-pill py-2 fw-bold">Guardar Vendedor</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal Formulario Bot Premium -->
<div class="modal fade" id="botModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
            <div class="modal-header border-0 bg-light" style="padding: 20px 30px; border-top-left-radius: 20px; border-top-right-radius: 20px;">
                <h5 class="modal-title fw-bold text-dark mb-0" id="botModalTitle"><i class="fa-solid fa-wand-magic-sparkles text-starfi-primary me-2"></i>Nueva Respuesta Automática</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" style="padding: 30px;">
                <form id="botForm" autocomplete="off">
                    <input type="hidden" id="ruleId">
                    <input type="hidden" id="parentId" value="">

                    <div id="parentRuleBadge" style="display:none; margin-bottom: 15px; background-color: #EFF6FF; border: 1px solid #BFDBFE; padding: 10px; border-radius: 8px;">
                        <span style="font-size: 0.75rem; color: #1E3A8A; font-weight: bold;"><i class="fa-solid fa-level-down-alt me-2"></i>Creando sub-opción para:</span>
                        <div id="parentRuleText" style="font-size: 0.85rem; color: #1E40AF; font-weight: 600; margin-top: 4px;"></div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Tipo de Regla</label>
                            <select class="form-select bg-light" id="ruleType" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" required>
                                <option value="EVENTO_SISTEMA">Evento General (Ej: Bienvenida)</option>
                                <option value="PALABRA_CLAVE">Palabra Clave / Opción</option>
                                <option value="CIERRE_CSAT">Cerrar Chat + Enviar CSAT</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Formato</label>
                            <select class="form-select bg-light" id="ruleFormat" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" required onchange="toggleFormatFields()">
                                <option value="TEXTO">Texto Múltiple</option>
                                <option value="IMAGEN">Imagen</option>
                                <option value="DOCUMENTO">Documento (PDF)</option>
                                <option value="UBICACION">Ubicación GPS</option>
                                <option value="CONTACTOS">Tarjeta de Contacto</option>
                                <option value="CONTACTOS_SEDE">Contactos de Asesores (Sede)</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Estado de Regla</label>
                            <select class="form-select bg-light" id="ruleState" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;">
                                <option value="ACTIVO">✅ Activo</option>
                                <option value="INACTIVO">⏸️ Inactivo</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Disparador / Evento</label>
                        <input type="text" class="form-control bg-light" id="ruleTrigger" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" placeholder="Ej: SALUDO_NUEVO, precio, ubicacion, 1, 2..." required>
                        <small class="text-muted mt-2 d-block" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info me-1"></i>Para palabras clave, separa con comas (ej: precio, costo, valor). Si es un menú, usa números (1, 2, 3).</small>
                    </div>

                    <!-- Campos Multimedia Dinámicos -->
                    <div id="mediaFields" class="mb-4" style="display:none; background-color: #F8FAFC; border: 1px dashed #CBD5E1; padding: 15px; border-radius: 10px;">
                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Archivo Adjunto (Ruta Local)</label>
                        <div class="input-group">
                            <input type="text" class="form-control bg-light" id="ruleMediaUrl" placeholder="uploads/bots/imagen.jpg o ruta local">
                            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('fileUpload').click()"><i class="fa-solid fa-upload"></i> Subir</button>
                        </div>
                        <input type="file" id="fileUpload" style="display:none;" onchange="handleFileUpload(this)">
                    </div>

                    <!-- Campos Ubicación Dinámicos -->
                    <div id="locationFields" class="mb-4 row" style="display:none; background-color: #F8FAFC; border: 1px dashed #CBD5E1; padding: 15px; border-radius: 10px; margin-left: 0; margin-right: 0;">
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Latitud</label>
                            <input type="text" class="form-control bg-light" id="ruleLat" placeholder="Ej: 10.4806">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Longitud</label>
                            <input type="text" class="form-control bg-light" id="ruleLng" placeholder="Ej: -66.9036">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;" id="messageLabel">Mensaje de Respuesta / Texto que acompaña</label>
                        <textarea class="form-control bg-light" id="ruleMessage" rows="4" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" placeholder="Escribe la respuesta del bot aquí..." required></textarea>
                        <div class="mt-2 d-flex align-items-center gap-2">
                            <span class="text-muted" style="font-size: 0.75rem;">Variables Mágicas:</span>
                            <span class="var-tag shadow-sm border">{{nombre}}</span>
                        </div>
                    </div>

                    <!-- Sección de Funciones Avanzadas -->
                    <div class="advanced-bot-features p-4" style="background-color: #F8FAFC; border-radius: 12px; border: 1px dashed #CBD5E1;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="m-0 fw-bold text-dark"><i class="fa-solid fa-bolt text-warning me-2"></i>Funciones Avanzadas</h6>
                        </div>

                        <div class="form-check form-switch mb-3 p-3 bg-white rounded border d-flex justify-content-between align-items-center" style="cursor: pointer;">
                            <div>
                                <label class="form-check-label fw-bold text-dark mb-1" for="enableWait" style="cursor: pointer;">¿Es un Menú de Opciones? (Esperar Respuesta)</label>
                                <p class="text-muted m-0" style="font-size: 0.75rem;">Si activas esto, el bot pausará las reglas globales y esperará que el cliente seleccione una opción (1, 2, 3...) de las sub-reglas que crees a partir de esta.</p>
                            </div>
                            <input class="form-check-input ms-3" type="checkbox" id="enableWait" style="cursor: pointer; transform: scale(1.3);">
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="enableButtons" style="cursor: pointer;">
                            <label class="form-check-label fw-bold text-muted" for="enableButtons" style="font-size: 0.85rem; cursor: pointer;">Añadir Botones Interactivos (Meta API)</label>
                        </div>

                        <div id="buttonsContainer" style="display: none;">
                            <div class="row" id="buttonsList">
                                <div class="col-md-4 mb-2">
                                    <input type="text" class="form-control form-control-sm" placeholder="Botón 1 (ej. Ver Plan)" maxlength="20">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <input type="text" class="form-control form-control-sm" placeholder="Botón 2 (Opcional)" maxlength="20">
                                </div>
                                <div class="col-md-4 mb-2">
                                    <input type="text" class="form-control form-control-sm" placeholder="Botón 3 (Opcional)" maxlength="20">
                                </div>
                            </div>
                            <small class="text-muted" style="font-size: 0.7rem;">WhatsApp permite un máximo de 3 botones de 20 caracteres cada uno.</small>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 p-4 pt-0">
                <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-starfi-primary px-4 fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" onclick="saveBotRule()">Guardar Regla Mágica</button>
            </div>
        </div>
    </div>
</div>
