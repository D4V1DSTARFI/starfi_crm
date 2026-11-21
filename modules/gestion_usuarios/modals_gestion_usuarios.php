<?php
/**
 * Modales del Módulo de Gestión de Usuarios - STARFI CRM
 */
?>
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="userModalTitle">Configurar Cuenta de Usuario</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="userForm" autocomplete="off">
                    <input type="hidden" id="userId" name="id" value="0">
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nombre Completo</label>
                        <input type="text" class="form-control py-2" id="inputNombre" name="nombre" placeholder="Ej: Juan Pérez" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Nombre de Usuario</label>
                            <input type="text" class="form-control py-2" id="inputUsuario" name="usuario" placeholder="Ej: jperez" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Cédula / Documento</label>
                            <input type="text" class="form-control py-2" id="inputCedula" name="cedula" placeholder="Ej: 12345678">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Correo Electrónico</label>
                            <input type="email" class="form-control py-2" id="inputCorreo" name="correo" placeholder="correo@ejemplo.com" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Teléfono</label>
                            <input type="text" class="form-control py-2" id="inputTelefono" name="telefono" placeholder="+58 412 0000000">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Contraseña <span id="passHint" class="text-muted fw-normal">(Requerida para nuevos)</span></label>
                        <input type="password" class="form-control py-2" id="inputContrasena" name="contrasena" placeholder="******">
                    </div>

                    <hr class="my-3 text-muted">
                    <h6 class="fw-bold text-dark mb-3"><i class="fa-solid fa-user-shield text-primary me-2"></i>Asignación de Rol y Sede</h6>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Rol de Usuario</label>
                            <select class="form-select py-2" id="inputRol" name="role_id_select">
                                <option value="0">Sin Rol (Pendiente)</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Sede Asignada</label>
                            <select class="form-select py-2" id="inputSede" name="id_sede">
                                <option value="0">Sin Sede (Sin Asignar)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Estatus del Usuario</label>
                        <select class="form-select py-2" id="inputEstado" name="estado">
                            <option value="INACTIVO">Inactivo (Acceso bloqueado)</option>
                            <option value="ACTIVO">Activo (Permitir inicio de sesión)</option>
                        </select>
                    </div>
                    <div id="modalAlert" class="alert alert-danger d-none py-2 small mb-0"></div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnGuardarUser" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="saveUser()">Guardar Configuración</button>
            </div>
        </div>
    </div>
</div>
