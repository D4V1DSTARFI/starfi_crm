<?php
/**
 * Modales del Módulo de Gestión de Roles y Permisos - STARFI CRM
 */
?>
<div class="modal fade" id="modalRoleInfo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-user-shield text-primary me-2"></i>Crear Nuevo Rol</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="createRoleForm" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small" for="inputRoleNombre">Nombre del Rol</label>
                        <input type="text" class="form-control py-2" id="inputRoleNombre" name="nombre" placeholder="Ej: JEFE DE VENTAS, SUPERVISOR..." required>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="submitRoleModal()">Crear Rol</button>
            </div>
        </div>
    </div>
</div>
