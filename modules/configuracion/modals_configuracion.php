    <!-- Modal Añadir Sede -->
    <div class="modal fade" id="modalSede" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium">
                    <h5 class="modal-title brand-font fw-bold text-starfi-dark mb-0"><i class="fa-solid fa-building me-2 text-starfi-primary"></i>Nueva Sede e Integración</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formSede" autocomplete="off">
                        <input type="hidden" id="id_sede" name="id_sede">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Razón Social <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="razon_social" name="razon_social" required placeholder="Ej: Caracas - Principal">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">RIF <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="rif" name="rif" required placeholder="J-12345678-9">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Teléfono Principal</label>
                                <input type="text" class="form-control form-control-premium" id="telefono" name="telefono" placeholder="+58 412 1234567">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Email</label>
                                <input type="email" class="form-control form-control-premium" id="email" name="email" placeholder="sede@empresa.com">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Dirección Completa</label>
                                <textarea class="form-control form-control-premium" id="direccion" name="direccion" rows="2"></textarea>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Ciudad</label>
                                <input type="text" class="form-control form-control-premium" id="ciudad" name="ciudad">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Estado</label>
                                <input type="text" class="form-control form-control-premium" id="estado_loc" name="estado_loc">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Código Postal</label>
                                <input type="text" class="form-control form-control-premium" id="codigo_postal" name="codigo_postal">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Estado de la Sede</label>
                                <select class="form-select form-select-premium" id="estado_sede" name="estado_sede">
                                    <option value="ACTIVO">Activo</option>
                                    <option value="INACTIVO">Inactivo</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Tipo de Sede</label>
                                <select class="form-select form-select-premium" id="tipo_sede" name="tipo_sede">
                                    <option value="PRINCIPAL">Principal</option>
                                    <option value="SUCURSAL">Sucursal</option>
                                    <option value="ALMACEN">Almacén</option>
                                    <option value="OFICINA">Oficina</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Observaciones</label>
                                <textarea class="form-control form-control-premium" id="observaciones" name="observaciones" rows="2"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-top-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-starfi-primary fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" id="btnSaveSede">Guardar Sede</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Añadir API WhatsApp -->
    <div class="modal fade" id="modalAPI" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium" style="background-color: #F0FDF4;">
                    <h5 class="modal-title brand-font fw-bold text-success mb-0"><i class="fa-brands fa-whatsapp me-2"></i>Nueva API WhatsApp</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formAPI" autocomplete="off">
                        <input type="hidden" id="id_api" name="id_api">
                        
                        <!-- Toggle de Experiencia -->
                        <div class="row g-3 mb-3 pb-3 border-bottom" id="api_experience_toggle">
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-question me-1"></i> ¿Estado de la línea en Meta?</label>
                                <div class="d-flex gap-4 mt-2 p-3 rounded" style="background-color: #F8FAFC; border: 1px dashed #CBD5E1;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="api_type_meta" id="api_type_existing" value="existing" checked onchange="toggleApiExperience()">
                                        <label class="form-check-label fw-bold text-dark cursor-pointer" for="api_type_existing">
                                            Ya registrado y verificado
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="api_type_meta" id="api_type_new" value="new" onchange="toggleApiExperience()">
                                        <label class="form-check-label fw-bold text-dark cursor-pointer" for="api_type_new">
                                            Nuevo (Requiere PIN/Alta)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sección de Autenticación Meta -->
                        <div class="row g-3 mb-3 p-3 rounded" style="background-color: #F0FDF4; border: 1px solid #BBF7D0;">
                            <div class="col-12" id="waba_container">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">WABA ID (WhatsApp Business Account ID)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control form-control-premium" id="api_id_negocio" name="api_id_negocio" placeholder="1111084364465615">
                                    <button class="btn btn-success fw-bold" type="button" id="btnFetchMeta" onclick="fetchMetaApis()" style="border-radius: 0 10px 10px 0;"><i class="fa-solid fa-cloud-arrow-down me-1"></i> Buscar Líneas</button>
                                </div>
                                <small class="text-muted d-block mt-1">Requerido para autocompletar números.</small>
                            </div>
                            
                            <div class="col-12 d-none">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Token de Meta (Permanente) <span class="text-danger">*</span></label>
                                <textarea class="form-control form-control-premium" id="api_token_meta" name="api_token_meta" rows="2" placeholder="EAAxxxxxxxxxx..." style="font-family: monospace;"></textarea>
                            </div>

                            <!-- Campos exclusivos para Alta (Número Nuevo) -->
                            <div class="col-md-6" id="phone_id_manual_container" style="display: none;">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">ID de Teléfono (Meta) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="api_telefono_meta_manual" placeholder="123456789012345">
                            </div>
                            <div class="col-md-6" id="pin_container" style="display: none;">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">PIN de 6 dígitos <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control form-control-premium" id="api_pin_meta" placeholder="123456" maxlength="6">
                                    <button class="btn btn-primary fw-bold" type="button" id="btnRegisterMeta" onclick="registerMetaPhone()" style="border-radius: 0 10px 10px 0;"><i class="fa-solid fa-check-circle me-1"></i> Dar Alta</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selección de Número Encontrado (Para Existing) -->
                        <div class="row g-3 mb-3" id="select_number_container" style="display: none;">
                            <div class="col-12">
                                <label class="form-label text-success fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fa-solid fa-list-check me-1"></i> Números Encontrados en Meta</label>
                                <select class="form-select form-select-premium border-success" id="api_select_meta" onchange="autoFillMetaNumber()">
                                    <option value="">Seleccione un número de la lista...</option>
                                </select>
                            </div>
                        </div>

                        <!-- Campos Finales de Registro Local CRM -->
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Sede Asignada <span class="text-danger">*</span></label>
                                <select class="form-select form-select-premium" id="api_sede" name="api_sede" required>
                                    <option value="">Seleccione una sede...</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Descripción Interna <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="api_descripcion" name="api_descripcion" required placeholder="Ej: Ventas - Sede Central">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Teléfono de Negocio <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="api_telefono" name="api_telefono" required placeholder="+58 412 1234567">
                            </div>
                            <div class="col-md-6" id="phone_id_readonly_container">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">ID de Teléfono (Meta) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium bg-light" id="api_telefono_meta" name="api_telefono_meta" required readonly placeholder="Se autocompleta">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Estado Operativo</label>
                                <select class="form-select form-select-premium" id="api_estado" name="api_estado">
                                    <option value="ACTIVO">Activo (Conectado)</option>
                                    <option value="INACTIVO">Inactivo</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Límite Solicitudes (Opcional)</label>
                                <input type="number" class="form-control form-control-premium" id="api_limite" name="api_limite" placeholder="1000">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Observaciones</label>
                                <textarea class="form-control form-control-premium" id="api_observacion" name="api_observacion" rows="2"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-top-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" id="btnSaveAPI">Guardar API</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Probar Conexión -->
    <div class="modal fade" id="modalProbarAPI" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium">
                    <h5 class="modal-title brand-font fw-bold text-starfi-dark mb-0"><i class="fa-solid fa-lightning text-warning me-2"></i> Probar Conexión API</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formProbarAPI" autocomplete="off" onsubmit="event.preventDefault(); ejecutarPruebaAPI();">
                        <input type="hidden" id="id_api_test">
                        
                        <div class="mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Número de Prueba</label>
                            <input type="text" class="form-control form-control-premium" id="telefono_test" placeholder="+58 412 1234567" value="+58 414 1209548">
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Mensaje de Prueba</label>
                            <textarea class="form-control form-control-premium" id="mensaje_test" rows="3">🧪 Mensaje de prueba desde STARFI WhatsApp API.
Si recibes este mensaje, la configuración es correcta.</textarea>
                        </div>
                        
                        <div id="resultadoTest"></div>
                    </form>
                </div>
                <div class="modal-footer border-top-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-info text-white fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" onclick="ejecutarPruebaAPI()">
                        <i class="fa-solid fa-paper-plane me-1"></i> Enviar Prueba
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Gestión de Plantillas Meta -->
    <div class="modal fade" id="modalPlantillasMeta" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium bg-starfi-primary text-white">
                    <h5 class="modal-title brand-font fw-bold mb-0"><i class="fa-solid fa-layer-group me-2"></i> Plantillas de Meta (WhatsApp)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" id="plantillas_id_sede">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list text-muted me-2"></i>Lista de Plantillas Sincronizadas</h6>
                        <div>
                            <button type="button" class="btn btn-success shadow-sm rounded-pill fw-bold me-2" onclick="sincronizarPlantillasMeta()">
                                <i class="fa-solid fa-rotate me-1"></i> Sincronizar Plantillas
                            </button>
                            <button type="button" class="btn btn-warning shadow-sm rounded-pill fw-bold me-2" onclick="crearPlantillaCSAT()">
                                <i class="fa-solid fa-star me-1"></i> Auto-Crear Plantilla CSAT
                            </button>
                            <button type="button" class="btn btn-info text-white shadow-sm rounded-pill fw-bold me-2" onclick="crearPlantillaNotificacionInterna()">
                                <i class="fa-solid fa-bell me-1"></i> Auto-Crear Plantilla Notificación Interna
                            </button>
                            <button type="button" class="btn btn-dark shadow-sm rounded-pill fw-bold me-2" onclick="crearPlantillaDifusionDirecta()">
                                <i class="fa-solid fa-bullhorn text-warning me-1"></i> Auto-Crear Plantilla Difusión
                            </button>
                            <button type="button" class="btn btn-primary shadow-sm rounded-pill fw-bold" onclick="mostrarCrearPlantilla()">
                                <i class="fa-solid fa-plus me-1"></i> Nueva Plantilla
                            </button>
                        </div>
                    </div>
                    <div id="vistaListaPlantillas">
                        <div class="table-responsive">
                            <table class="table table-hover table-borderless align-middle bg-white rounded-3 shadow-sm" id="tablaPlantillasMeta">
                                <thead class="table-light text-muted" style="font-size: 0.85rem;">
                                    <tr>
                                        <th class="py-3 px-4 rounded-start">NOMBRE</th>
                                        <th class="py-3">CATEGORÍA</th>
                                        <th class="py-3">IDIOMA</th>
                                        <th class="py-3">ESTADO</th>
                                        <th class="py-3 text-center rounded-end">ACCIONES</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">Cargando plantillas desde Meta...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- VISTA: Crear Nueva Plantilla -->
                    <div id="vistaCrearPlantilla" style="display: none;">
                        <div class="card border-0 shadow-sm rounded-3">
                            <div class="card-body p-4">
                                <form id="formNuevaPlantillaMeta" autocomplete="off" onsubmit="event.preventDefault(); guardarNuevaPlantilla();">
                                    <h6 class="fw-bold mb-4 border-bottom pb-2">Diseñador Básico de Plantilla</h6>
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Nombre de la Plantilla</label>
                                            <input type="text" class="form-control form-control-premium" id="new_template_name" placeholder="ej: confirmacion_compra" oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '')">
                                            <small class="text-muted">Solo minúsculas, números y guiones bajos (_).</small>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Categoría</label>
                                            <select class="form-select form-select-premium" id="new_template_category">
                                                <option value="UTILITY">Utilidad (Ej: Recibos)</option>
                                                <option value="MARKETING">Marketing</option>
                                                <option value="AUTHENTICATION">Autenticación (OTP)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Idioma</label>
                                            <select class="form-select form-select-premium" id="new_template_lang">
                                                <option value="es">Español</option>
                                                <option value="en_US">Inglés (US)</option>
                                            </select>
                                        </div>
                                        
                                        <div class="col-12 mt-4">
                                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Cuerpo del Mensaje (Body)</label>
                                            <textarea class="form-control form-control-premium" id="new_template_body" rows="4" placeholder="Hola {{1}}, tu pedido {{2}} ha sido enviado."></textarea>
                                            <small class="text-muted">Usa {{1}}, {{2}} para definir variables dinámicas.</small>
                                        </div>
                                    </div>
                                    <div class="mt-4 pt-3 border-top text-end">
                                        <button type="button" class="btn btn-light rounded-pill px-4 me-2 fw-bold" onclick="mostrarListaPlantillas()">Cancelar</button>
                                        <button type="button" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="guardarNuevaPlantilla()">Enviar a Meta</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Añadir Respuesta Rápida -->
    <div class="modal fade" id="modalRespuestaRapida" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium">
                    <h5 class="modal-title brand-font fw-bold text-starfi-dark mb-0"><i class="fa-solid fa-bolt text-warning me-2"></i>Nueva Respuesta Rápida</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formRespuesta" autocomplete="off">
                        <input type="hidden" id="id_respuesta" name="id_respuesta">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Sede <span class="text-danger">*</span></label>
                                <select class="form-select form-select-premium" id="resp_id_sede" name="id_sede" required>
                                    <option value="">Seleccione una sede...</option>
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Título Corto <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="resp_titulo" name="titulo" required placeholder="Ej: Saludo inicial">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Mensaje Completo <span class="text-danger">*</span></label>
                                <textarea class="form-control form-control-premium" id="resp_mensaje" name="mensaje" rows="4" required placeholder="Hola, soy el asesor asignado. ¿En qué puedo ayudarte?"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-top-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning text-dark fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" id="btnSaveRespuesta">Guardar Respuesta</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Selector Local Offline de Coordenadas GPS Venezuela (100% LAN) -->
    <div class="modal fade" id="modalMapPicker" tabindex="-1" aria-labelledby="modalMapPickerLabel" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <div class="modal-header text-white p-3 px-4" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-map-location-dot fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-white mb-0" id="modalMapPickerLabel" style="font-size: 1.1rem;">Selector Local de Coordenadas GPS (Offline LAN)</h5>
                            <small class="text-white-50" style="font-size: 0.75rem;">Busca una ciudad de Venezuela, haz clic en el plano o edita Latitud y Longitud directamente.</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body p-0 position-relative">
                    <form id="formMapPicker" autocomplete="off" onsubmit="event.preventDefault(); buscarEnMapa();">
                        <div class="p-3 bg-light border-bottom">
                            <div class="row g-2">
                                <div class="col-md-7">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white text-muted border-end-0"><i class="fa-solid fa-search"></i></span>
                                        <input type="text" class="form-control border-start-0" id="mapSearchInput" list="venezuelaCitiesDatalist" placeholder="Buscar ciudad o estado (ej: Caracas, Valencia, Maracaibo...)">
                                        <datalist id="venezuelaCitiesDatalist"></datalist>
                                        <button class="btn btn-primary fw-bold px-3" type="button" onclick="buscarEnMapa()">
                                            <i class="fa-solid fa-magnifying-glass me-1"></i> Buscar
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-5">
                                    <select class="form-select" id="mapCityQuickSelect" onchange="seleccionarCiudadCatalogo(this.value)">
                                        <option value="">Catálogo rápido de ciudades...</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Contenedor Interactivo Local (Canvas Grid + Venezuela) -->
                        <div id="leafletMapContainer" style="height: 360px; width: 100%; z-index: 1; background-color: #E2E8F0;"></div>

                        <!-- Barra de Coordenadas Editables en Vivo -->
                        <div class="p-3 bg-white border-top" style="font-size: 0.85rem;">
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-4">
                                    <label class="form-label small fw-bold text-muted mb-1">Latitud</label>
                                    <input type="number" step="0.00001" min="-90" max="90" class="form-control form-control-sm font-monospace fw-bold" id="mapLatInput" value="10.48060" oninput="actualizarDesdeInputsManuales()">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label small fw-bold text-muted mb-1">Longitud</label>
                                    <input type="number" step="0.00001" min="-180" max="180" class="form-control form-control-sm font-monospace fw-bold" id="mapLngInput" value="-66.90360" oninput="actualizarDesdeInputsManuales()">
                                </div>
                                <div class="col-sm-4">
                                    <div class="small fw-bold text-dark mb-1">
                                        <i class="fa-solid fa-location-pin text-danger me-1"></i> Punto Seleccionado:
                                    </div>
                                    <span id="selectedCoordsText" class="badge bg-light text-dark border font-monospace" style="font-size: 0.82rem;">10.48060, -66.90360</span>
                                    <div class="text-muted small text-truncate mt-1" title="Referencia geográfica local">
                                        <i class="fa-solid fa-building me-1 text-primary"></i> <span id="reverseGeocodeText">Caracas, Distrito Capital</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-outline-secondary fw-bold px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success fw-bold px-4" id="btnConfirmMapLocation" onclick="confirmarUbicacionMapa()">
                        <i class="fa-solid fa-check me-2"></i> Usar esta Ubicación GPS
                    </button>
                </div>
            </div>
        </div>
    </div>
