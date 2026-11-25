<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
<!-- Modal Inspección Segura de Correo SMTP -->
<div class="modal fade" id="modalDetalleCorreo" tabindex="-1" aria-labelledby="modalDetalleCorreoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form id="formDetalleCorreoModal" autocomplete="off">
                <div class="modal-header bg-starfi-dark text-white">
                    <h5 class="modal-title fw-bold" id="modalDetalleCorreoLabel">
                        <i class="fas fa-envelope-open me-2"></i>Detalle de Correo #<span id="detCorreoId">-</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2 mb-3 p-3 bg-light rounded border small">
                        <div class="col-md-6">
                            <strong>Destinatario:</strong> <span id="detCorreoDestinatario">-</span>
                        </div>
                        <div class="col-md-6">
                            <strong>Estado:</strong> <span id="detCorreoEstado">-</span>
                        </div>
                        <div class="col-md-12">
                            <strong>Asunto:</strong> <span id="detCorreoAsunto">-</span>
                        </div>
                        <div class="col-md-6">
                            <strong>Creado:</strong> <span id="detCorreoCreado">-</span>
                        </div>
                        <div class="col-md-6">
                            <strong>Enviado:</strong> <span id="detCorreoEnviado">-</span>
                        </div>
                    </div>

                    <div id="wrapDetCorreoError" class="alert alert-danger py-2 small mb-3" style="display: none;">
                        <strong><i class="fas fa-circle-exclamation me-1"></i>Detalle Técnico SMTP:</strong>
                        <div id="detCorreoErrorTexto" class="mt-1 font-monospace" style="white-space: pre-wrap;"></div>
                    </div>

                    <label class="form-label small fw-bold text-secondary mb-1">Vista Previa Aislada del Cuerpo HTML (Sandbox Estricto)</label>
                    <iframe
                        id="iframeVistaCorreo"
                        sandbox=""
                        title="Vista previa aislada del mensaje de correo"
                        style="width: 100%; height: 320px; border: 1px solid #cbd5e1; border-radius: 8px; background: #ffffff;">
                    </iframe>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-outline-primary fw-semibold" id="btnReintentarDesdeModal" style="display: none;">
                        <i class="fas fa-rotate-right me-1"></i> Reintentar Envío
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </form>
        </div>
    </div>
</div>
