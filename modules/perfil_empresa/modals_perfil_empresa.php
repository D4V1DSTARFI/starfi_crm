<?php if (!defined('STARFI_MODALS_ALLOWED')) { http_response_code(403); exit('403 Forbidden'); } ?>
<!-- MODAL MANUAL / GUÍA -->
<div class="modal fade" id="modalManual" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-book-open text-info me-2"></i>Guía de Uso: Perfil de Empresa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-3">
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="rounded-circle bg-light p-2 text-primary fw-bold">1</div>
                    <div>
                        <h6 class="fw-bold mb-1">Registrar Empresa</h6>
                        <p class="text-muted small mb-0">Haz clic en <strong>"Registrar Empresa"</strong> para ingresar el RIF, Razón Social, teléfono y logo corporativo.</p>
                    </div>
                </div>
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="rounded-circle bg-light p-2 text-primary fw-bold">2</div>
                    <div>
                        <h6 class="fw-bold mb-1">Gestionar las 4 Tablas Legales</h6>
                        <p class="text-muted small mb-0">Haz clic en <strong>"Gestionar Tablas"</strong> sobre la empresa creada para navegar por las pestañas de Datos Generales, Representantes, Registro Mercantil y Expedientes.</p>
                    </div>
                </div>
                <div class="d-flex align-items-start gap-3 mb-3">
                    <div class="rounded-circle bg-light p-2 text-primary fw-bold">3</div>
                    <div>
                        <h6 class="fw-bold mb-1">Adjuntar Expedientes Digitales</h6>
                        <p class="text-muted small mb-0">Sube copias en PDF o imagen de RIF, Actas Constitutivas y Solvencias para disponer de descargas rápidas en cualquier momento.</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" data-bs-dismiss="modal">Entendido</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 1: PERFIL EMPRESA -->
<div class="modal fade" id="modalPerfilEmpresa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="modalPerfilTitle">Registrar Perfil Corporativo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="formPerfilEmpresa" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" id="perfilId" name="id" value="0">
                    <input type="hidden" id="perfilSede" name="id_sede" value="1">

                    <div class="row g-3 mb-3">
                        <div class="col-8">
                            <label class="form-label fw-semibold small">Razón Social</label>
                            <input type="text" class="form-control" name="razon_social" id="inputRazonSocial" placeholder="Ej: Inversiones Starfi C.A." required>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold small">Letra RIF</label>
                            <select class="form-select" name="letra" id="inputLetra">
                                <option value="J" selected>J (Jurídico)</option>
                                <option value="V">V (Venezolano)</option>
                                <option value="G">G (Gubernamental)</option>
                                <option value="E">E (Extranjero)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">RIF (Sin Letra)</label>
                            <input type="text" class="form-control" name="rif" id="inputRif" placeholder="Ej: 12345678-9" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Condición Fiscal</label>
                            <select class="form-select" name="condicion" id="inputCondicion">
                                <option value="[ORDINARIO]" selected>Ordinario</option>
                                <option value="[ESPECIAL]">Especial (Contribuyente Especial)</option>
                            </select>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Correo Electrónico</label>
                            <input type="email" class="form-control" name="email" id="inputEmail" placeholder="contacto@empresa.com">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Teléfono</label>
                            <input type="text" class="form-control" name="telefono" id="inputTelefonoPerfil" placeholder="+58 412 0000000">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Sitio Web</label>
                            <input type="text" class="form-control" name="web" id="inputWeb" placeholder="www.miempresa.com">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Instagram</label>
                            <input type="text" class="form-control" name="instagram" id="inputInstagram" placeholder="@miempresa">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Dirección Fiscal</label>
                        <textarea class="form-control" name="direccion" id="inputDireccionPerfil" rows="2" placeholder="Dirección física completa..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Logo de la Empresa</label>
                        <input type="file" class="form-control" name="logo" accept="image/*">
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnSavePerfilEmpresa" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="savePerfilEmpresa(this)">Guardar Perfil</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 2: FIRMANTE -->
<div class="modal fade" id="modalFirmante" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="modalFirmanteTitle">Agregar Firmante / Representante Legal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="formFirmante" autocomplete="off">
                    <input type="hidden" id="firmanteId" name="id" value="0">
                    <input type="hidden" id="firmanteEmpresaId" name="id_empresa" value="0">
                    <input type="hidden" id="firmanteSedeId" name="id_sede" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nombre Completo</label>
                        <input type="text" class="form-control" name="nombre" id="inputFirmanteNombre" required placeholder="Ej: Carlos Mendoza">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Cédula</label>
                            <input type="text" class="form-control" name="cedula" id="inputFirmanteCedula" required placeholder="Ej: V-15678901">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Cargo</label>
                            <input type="text" class="form-control" name="cargo" id="inputFirmanteCargo" placeholder="Ej: Director General">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Teléfono</label>
                            <input type="text" class="form-control" name="telefono" id="inputFirmanteTelefono" placeholder="+58 414 1112233">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Correo Electrónico</label>
                            <input type="email" class="form-control" name="email" id="inputFirmanteEmail" placeholder="firmante@correo.com">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Dirección Habitación / Trabajo</label>
                        <textarea class="form-control" name="direccion" id="inputFirmanteDireccion" rows="2" placeholder="Dirección..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnSaveFirmante" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="saveFirmante(this)">Guardar Firmante</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 3: REGISTRO MERCANTIL -->
<div class="modal fade" id="modalRegistro" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold" id="modalRegistroTitle">Agregar Inscripción Mercantil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="formRegistro" autocomplete="off">
                    <input type="hidden" id="registroId" name="id" value="0">
                    <input type="hidden" id="registroEmpresaId" name="id_empresa" value="0">
                    <input type="hidden" id="registroSedeId" name="id_sede" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Fecha de Inscripción</label>
                        <input type="date" class="form-control" name="fecha" id="inputRegistroFecha" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">N° de Registro</label>
                            <input type="text" class="form-control" name="n_registro" id="inputRegistroNum" required placeholder="Ej: 1452">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">N° de Tomo</label>
                            <input type="text" class="form-control" name="n_tomo" id="inputRegistroTomo" required placeholder="Ej: 42-A">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Notaría / Registro Mercantil / Descripción</label>
                        <textarea class="form-control" name="descripcion" id="inputRegistroDesc" rows="2" placeholder="Ej: Registro Mercantil Primero del Distrito Capital..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnSaveRegistro" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="saveRegistro(this)">Guardar Registro</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL 4: EXPEDIENTE DIGITAL -->
<div class="modal fade" id="modalExpediente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Adjuntar Documento al Expediente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-4">
                <form id="formExpediente" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" id="expedienteEmpresaId" name="id_empresa" value="0">
                    <input type="hidden" id="expedienteSedeId" name="id_sede" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Fecha del Documento</label>
                        <input type="date" class="form-control" name="fecha" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Nombre / Descripción del Documento</label>
                        <input type="text" class="form-control" name="descripcion" required placeholder="Ej: Acta Constitutiva, RIF Actualizado, Solvencia...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Archivo (PDF / Imagen)</label>
                        <input type="file" class="form-control" name="archivo" required accept=".pdf,image/*">
                    </div>
                </form>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnSaveExpediente" class="btn btn-primary px-4 fw-semibold" style="background-color: var(--primary); border-color: var(--primary);" onclick="saveExpediente(this)">Adjuntar Archivo</button>
            </div>
        </div>
    </div>
</div>
