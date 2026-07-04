$(document).ready(function() {
    // Cargar datos iniciales desde BD
    loadSedes();
    loadAPIs();
    loadUsers();

    // 1. Añadir Sede
    $('#btnAddSede').on('click', function() {
        $('#sedeNombre').val('');
        $('#sedeDireccion').val('');
        $('#sedeNumero').val('');
        $('#sedeAppId').val('');
        var myModal = new bootstrap.Modal(document.getElementById('modalSede'));
        myModal.show();
    });

    $('#btnSaveSede').on('click', function() {
        const razon_social = $('#razon_social').val().trim();
        const rif = $('#rif').val().trim();
        if(!razon_social || !rif) { Swal.fire('Error', 'Razón Social y RIF son obligatorios', 'warning'); return; }
        
        const data = {
            action: 'save_sede',
            id_sede: $('#id_sede').val(),
            razon_social: razon_social,
            rif: rif,
            telefono: $('#telefono').val().trim(),
            email: $('#email').val().trim(),
            direccion: $('#direccion').val().trim(),
            ciudad: $('#ciudad').val().trim(),
            estado_loc: $('#estado_loc').val().trim(),
            codigo_postal: $('#codigo_postal').val().trim(),
            estado_sede: $('#estado_sede').val(),
            tipo_sede: $('#tipo_sede').val(),
            observaciones: $('#observaciones').val().trim()
        };

        $.ajax({
            url: 'back_configuracion.php', type: 'POST', dataType: 'json',
            data: data,
            success: function(res) {
                if(res.status === 'success'){
                    bootstrap.Modal.getInstance(document.getElementById('modalSede')).hide();
                    Swal.fire('Éxito', res.message, 'success');
                    loadSedes();
                } else { Swal.fire('Error', res.message, 'error'); }
            }
        });
    });

    // 1.5. Añadir API WhatsApp
    $('#btnAddAPI').on('click', function() {
        $('#formAPI')[0].reset();
        $('#id_api').val('');
        
        // Cargar sedes en el select
        $.ajax({
            url: 'back_configuracion.php', type: 'POST', dataType: 'json',
            data: { action: 'get_sedes_list' },
            success: function(res) {
                if(res.status === 'success'){
                    let select = $('#api_sede');
                    select.html('<option value="">Seleccione una sede...</option>');
                    res.data.forEach(s => {
                        select.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
                    });
                    var myModal = new bootstrap.Modal(document.getElementById('modalAPI'));
                    myModal.show();
                }
            }
        });
    });

    $('#btnSaveAPI').on('click', function() {
        if (!$('#api_sede').val() || !$('#api_descripcion').val() || !$('#api_telefono').val() || !$('#api_telefono_meta').val() || !$('#api_token_meta').val()) {
            Swal.fire('Error', 'Complete los campos obligatorios (*)', 'warning'); return;
        }

        const data = {
            action: 'save_api',
            id_api: $('#id_api').val(),
            id_sede: $('#api_sede').val(),
            descripcion: $('#api_descripcion').val().trim(),
            telefono: $('#api_telefono').val().trim(),
            telefono_meta: $('#api_telefono_meta').val().trim(),
            token_meta: $('#api_token_meta').val().trim(),
            id_negocio: $('#api_id_negocio').val().trim(),
            estado: $('#api_estado').val(),
            limite_solicitudes: $('#api_limite').val().trim(),
            observaciones: $('#api_observacion').val().trim()
        };

        $.ajax({
            url: 'back_configuracion.php', type: 'POST', dataType: 'json',
            data: data,
            success: function(res) {
                if(res.status === 'success'){
                    bootstrap.Modal.getInstance(document.getElementById('modalAPI')).hide();
                    Swal.fire('Éxito', res.message, 'success');
                    loadAPIs();
                    loadSedes(); // Para actualizar el badge en la vista de Sedes
                } else { Swal.fire('Error', res.message, 'error'); }
            }
        });
    });

    // 2. Nuevo Operador
    $('#btnAddUser').on('click', function() {
        $('#opNombre').val('');
        $('#opEmail').val('');
        $('#opRol').val('AGENTE');
        $('#opLimite').val(5);
        
        // Cargar lista de sedes para el select
        $.ajax({
            url: 'back_configuracion.php', type: 'POST', dataType: 'json',
            data: { action: 'get_sedes_list' },
            success: function(res) {
                if(res.status === 'success'){
                    let select = $('#opSede');
                    select.html('<option value="0">Global (Todas)</option>');
                    res.data.forEach(s => {
                        select.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
                    });
                    var myModal = new bootstrap.Modal(document.getElementById('modalOperador'));
                    myModal.show();
                }
            }
        });
    });

    $('#btnSaveOperador').on('click', function() {
        const nombre = $('#opNombre').val().trim();
        const email = $('#opEmail').val().trim();
        if(!nombre || !email) { Swal.fire('Error', 'Nombre y email son obligatorios', 'warning'); return; }
        
        $.ajax({
            url: 'back_configuracion.php', type: 'POST', dataType: 'json',
            data: {
                action: 'add_user',
                nombre: nombre,
                email: email,
                rol: $('#opRol').val(),
                sede_id: $('#opSede').val(),
                limite: $('#opLimite').val()
            },
            success: function(res) {
                if(res.status === 'success'){
                    bootstrap.Modal.getInstance(document.getElementById('modalOperador')).hide();
                    Swal.fire('Éxito', res.message, 'success');
                    loadUsers();
                } else { Swal.fire('Error', res.message, 'error'); }
            }
        });
    });

    // Eventos de botones de acción genéricos para Users/Operadores
    $('#usersTableBody').on('click', '.action-btn', function() {
        Swal.fire({
            icon: 'warning',
            title: 'Acción Restringida',
            text: 'No tienes permisos de SuperAdmin para modificar este registro.',
            timer: 2000,
            showConfirmButton: false
        });
    });

    // 3. Configuración GEMA AI
    $('#btnSaveGema').on('click', function() {
        const prompt = $('#gema_prompt').val().trim();
        const nombre = $('#gema_nombre').val().trim();
        const token = $('#gema_token').val().trim();
        const estado = $('#gema_estado').is(':checked') ? 1 : 0;
        
        if(!prompt) {
            Swal.fire('Error', 'El Prompt Inicial es obligatorio', 'warning');
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');

        $.ajax({
            url: 'back_configuracion.php', 
            type: 'POST', 
            dataType: 'json',
            data: {
                action: 'save_gema',
                prompt: prompt,
                nombre: nombre,
                token: token,
                estado: estado
            },
            success: function(res) {
                if(res.status === 'success'){
                    Swal.fire('Guardado', res.message, 'success');
                } else { 
                    Swal.fire('Error', res.message, 'error'); 
                }
            },
            complete: function() {
                btn.prop('disabled', false).html('<i class="fa-solid fa-save me-1"></i> Guardar Configuración IA');
            }
        });
    });

    // Cargar config Gema si existe
    loadGemaConfig();

});

function loadGemaConfig() {
    $.ajax({
        url: 'back_configuracion.php', 
        type: 'POST', 
        dataType: 'json',
        data: { action: 'load_gema' },
        success: function(res) {
            if(res.status === 'success' && res.data) {
                if (res.data.prompt) $('#gema_prompt').val(res.data.prompt);
                if (res.data.nombre) $('#gema_nombre').val(res.data.nombre);
                if (res.data.token) $('#gema_token').val(res.data.token);
                $('#gema_estado').prop('checked', res.data.estado == 1);
            }
        }
    });
}

function loadSedes() {
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'load_sedes' },
        success: function(res) {
            if(res.status === 'success') {
                let container = $('#sedesCardContainer');
                container.empty();
                if(res.data.length === 0){
                    container.append('<div class="col-12 text-center text-muted p-4">No hay sedes registradas.</div>');
                    return;
                }
                res.data.forEach(s => {
                    let estadoBadge = s.estado === 'ACTIVO' ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>';
                    
                    let apiBadge = s.tiene_api 
                        ? '<span class="badge bg-success text-white"><i class="fa-solid fa-check me-1"></i>Con API</span>'
                        : '<span class="badge bg-warning text-dark"><i class="fa-solid fa-triangle-exclamation me-1"></i>Sin API</span>';
                    
                    let card = `
                    <div class="col-md-4">
                        <div class="card h-100 shadow-sm border-0" style="border-radius: 10px; overflow: hidden;">
                            <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-3 pb-0">
                                <h6 class="mb-0 fw-bold text-primary"><i class="fa-solid fa-building me-2"></i>${s.nombre_sede}</h6>
                                ${estadoBadge}
                            </div>
                            <div class="card-body">
                                <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-regular fa-id-card me-2" style="width: 16px; text-align: center;"></i><strong class="text-dark">RIF:</strong> ${s.rif || 'N/A'}</div>
                                <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-phone me-2" style="width: 16px; text-align: center;"></i><strong class="text-dark">Teléfono:</strong> ${s.telefono || 'N/A'}</div>
                                <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-regular fa-envelope me-2" style="width: 16px; text-align: center;"></i><strong class="text-dark">Email:</strong> ${s.email || 'N/A'}</div>
                                <div class="mb-3 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-location-dot me-2" style="width: 16px; text-align: center;"></i><strong class="text-dark">Ciudad:</strong> ${s.ciudad || 'N/A'}</div>
                                
                                <div class="d-flex gap-2 mb-3">
                                    <span class="badge bg-info text-white">${s.tipo_sede || 'SUCURSAL'}</span>
                                    ${apiBadge}
                                </div>
                                
                                <small class="text-muted"><i class="fa-solid fa-user-group me-1"></i>0 vendedores</small>
                            </div>
                            <div class="card-footer bg-white border-top-0 p-0">
                                <div class="d-flex border-top">
                                    <button class="btn btn-link text-primary text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Editar" onclick="editarSede(${s.id})"><i class="fa-solid fa-pen"></i></button>
                                    <button class="btn btn-link text-success text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="WhatsApp API" onclick="configurarAPI(${s.id})"><i class="fa-brands fa-whatsapp"></i></button>
                                    <button class="btn btn-link text-info text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Métricas" onclick="mostrarEstadisticas(${s.id})"><i class="fa-solid fa-chart-line"></i></button>
                                    <button class="btn btn-link text-danger text-decoration-none flex-fill rounded-0 py-2 action-btn" title="Eliminar" onclick="borrarSede(${s.id})"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>`;
                    container.append(card);
                });
            }
        }
    });
}

function editarSede(id) {
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'get_sede', id: id },
        success: function(res) {
            if(res.status === 'success') {
                const s = res.data;
                $('#id_sede').val(s.id);
                $('#razon_social').val(s.nombre_sede);
                $('#rif').val(s.rif);
                $('#telefono').val(s.telefono);
                $('#email').val(s.email);
                $('#direccion').val(s.direccion);
                $('#ciudad').val(s.ciudad);
                $('#estado_loc').val(s.estado_loc);
                $('#codigo_postal').val(s.codigo_postal);
                $('#estado_sede').val(s.estado);
                $('#tipo_sede').val(s.tipo_sede);
                $('#observaciones').val(s.observaciones);
                
                var myModal = new bootstrap.Modal(document.getElementById('modalSede'));
                myModal.show();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}

function borrarSede(id) {
    Swal.fire({
        title: '¿Estás seguro?',
        text: "¡No podrás revertir esto! Se eliminará la sede.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, borrar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'back_configuracion.php', type: 'POST', dataType: 'json',
                data: { action: 'delete_sede', id: id },
                success: function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Eliminado', res.message, 'success');
                        loadSedes();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

function configurarAPI(id_sede) {
    // Busca si ya hay una API configurada para esta sede, sino abre el modal en blanco
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'get_api_by_sede', id_sede: id_sede },
        success: function(res) {
            $('#formAPI')[0].reset();
            // Aseguramos que la sede seleccionada sea la de la tarjeta
            $('#api_sede').val(id_sede);
            
            if(res.status === 'success' && res.data) {
                // Hay API
                const a = res.data;
                $('#id_api').val(a.id);
                $('#api_descripcion').val(a.descripcion);
                $('#api_telefono').val(a.numero_telefono);
                $('#api_telefono_meta').val(a.meta_telefono_id);
                $('#api_token_meta').val(a.meta_token);
                $('#api_id_negocio').val(a.id_negocio);
                $('#api_estado').val(a.estado);
                $('#api_limite').val(a.limite_solicitudes);
                $('#api_observacion').val(a.observaciones);
            } else {
                // Nueva API para esta sede
                $('#id_api').val('');
            }
            var myModal = new bootstrap.Modal(document.getElementById('modalAPI'));
            myModal.show();
        }
    });
}

function mostrarEstadisticas(id_sede) {
    Swal.fire({
        icon: 'info',
        title: 'Métricas de la Sede',
        text: 'El módulo de métricas y estadísticas detalladas por sede estará disponible en Starfi 2.0. ¡Próximamente!'
    });
}

function loadAPIs() {
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'load_apis' },
        success: function(res) {
            if(res.status === 'success') {
                let container = $('#apisCardContainer');
                container.empty();
                
                let total = res.data.length;
                let activas = 0;
                let inactivas = 0;
                let sedesConAPI = new Set();
                
                if(total === 0){
                    container.append('<div class="col-12 text-center text-muted p-4">No hay APIs configuradas.</div>');
                } else {
                    res.data.forEach(a => {
                        if (a.estado === 'ACTIVO') {
                            activas++;
                        } else {
                            inactivas++;
                        }
                        if (a.id_sede) sedesConAPI.add(a.id_sede);
                        
                        let badge = a.estado === 'ACTIVO' ? '<span class="badge bg-secondary">Inactivo</span>' : '<span class="badge bg-success">Activo</span>';
                        // Fix logic based on state: 
                        badge = a.estado === 'ACTIVO' ? '<span class="badge bg-success">Activo</span>' : '<span class="badge bg-secondary">Inactivo</span>';
                        
                        let token_trunc = a.meta_token ? a.meta_token.substring(0, 20) + '...' : 'N/A';
                        
                        let card = `
                        <div class="col-md-4">
                            <div class="card h-100 shadow-sm border-0" style="border-radius: 10px; overflow: hidden;">
                                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-3 pb-0">
                                    <h6 class="mb-0 fw-bold text-muted"><i class="fa-solid fa-circle text-secondary me-2" style="font-size: 0.6rem;"></i>${a.descripcion || 'Sin descripción'}</h6>
                                    ${badge}
                                </div>
                                <div class="card-body">
                                    <div class="mb-3 text-muted" style="font-size: 0.85rem;"><i class="fa-solid fa-building me-2"></i>${a.nombre_sede || 'Sin sede asignada'}</div>
                                    
                                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-phone me-2" style="width: 16px;"></i><strong class="text-dark">Teléfono:</strong><br>${a.numero_telefono || 'N/A'}</div>
                                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-hashtag me-2" style="width: 16px;"></i><strong class="text-dark">ID Meta:</strong><br>${a.meta_telefono_id || 'N/A'}</div>
                                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-key me-2" style="width: 16px;"></i><strong class="text-dark">Metadatos del token:</strong><br><span style="font-family: monospace; font-size: 0.8rem;">${token_trunc}</span></div>
                                    <div class="mb-3 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-briefcase me-2" style="width: 16px;"></i><strong class="text-dark">ID de WABA:</strong><br>${a.id_negocio || 'N/A'}</div>
                                    
                                    <small class="text-muted"><i class="fa-solid fa-check-double me-1 text-success"></i>Hoy: 0 mensajes</small>
                                </div>
                                <div class="card-footer bg-white border-top-0 p-0">
                                    <div class="d-flex border-top">
                                        <button class="btn btn-link text-primary text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Editar API" onclick="editarAPI(${a.id})"><i class="fa-solid fa-pen"></i></button>
                                        <button class="btn btn-link text-info text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Probar API" onclick="abrirPruebaAPI(${a.id}, '${a.numero_telefono}')"><i class="fa-solid fa-bolt"></i></button>
                                        <button class="btn btn-link text-success text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Métricas" onclick="mostrarEstadisticasAPI(${a.id})"><i class="fa-solid fa-circle-info"></i></button>
                                        <button class="btn btn-link text-danger text-decoration-none flex-fill rounded-0 py-2 action-btn" title="Eliminar API" onclick="borrarAPI(${a.id})"><i class="fa-solid fa-trash"></i></button>
                                    </div>
                                </div>
                            </div>
                        </div>`;
                        container.append(card);
                    });
                }
                
                // Actualizar métricas visuales
                $('#statApiTotal').text(total);
                $('#statApiActivas').text(activas);
                $('#statApiInactivas').text(inactivas);
                $('#statApiSedes').text(sedesConAPI.size);
            }
        }
    });
}

function editarAPI(id_api) {
    // Buscar los datos de esta API para editar
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'get_api_by_id', id_api: id_api },
        success: function(res) {
            if(res.status === 'success' && res.data) {
                const a = res.data;
                $('#id_api').val(a.id);
                $('#api_sede').val(a.id_sede);
                $('#api_descripcion').val(a.descripcion);
                $('#api_telefono').val(a.numero_telefono);
                $('#api_telefono_meta').val(a.meta_telefono_id);
                $('#api_token_meta').val(a.meta_token);
                $('#api_id_negocio').val(a.id_negocio);
                $('#api_estado').val(a.estado);
                $('#api_limite').val(a.limite_solicitudes);
                $('#api_observacion').val(a.observaciones);
                
                var myModal = new bootstrap.Modal(document.getElementById('modalAPI'));
                myModal.show();
            } else {
                Swal.fire('Error', 'No se pudo cargar la API.', 'error');
            }
        }
    });
}

function borrarAPI(id) {
    Swal.fire({
        title: '¿Estás seguro?',
        text: "¡No podrás revertir esto! Se eliminará la configuración de la API.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, borrar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'back_configuracion.php', type: 'POST', dataType: 'json',
                data: { action: 'delete_api', id: id },
                success: function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Eliminado', res.message, 'success');
                        loadAPIs();
                        loadSedes(); // Por si cambió el estado de "Con API" en la sede
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

function mostrarEstadisticasAPI(id) {
    Swal.fire({
        icon: 'info',
        title: 'Métricas de la API',
        text: 'Las estadísticas detalladas de esta línea estarán en Starfi 2.0.'
    });
}

function abrirPruebaAPI(id, numero) {
    $('#id_api_test').val(id);
    $('#resultadoTest').html('');
    var myModal = new bootstrap.Modal(document.getElementById('modalProbarAPI'));
    myModal.show();
}

function ejecutarPruebaAPI() {
    const id_api = $('#id_api_test').val();
    const numero = $('#telefono_test').val().trim();
    const mensaje = $('#mensaje_test').val().trim();
    
    if(!numero || !mensaje) {
        Swal.fire('Atención', 'Ingrese un número y un mensaje', 'warning');
        return;
    }
    
    $('#resultadoTest').html('<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Enviando prueba...</div>');
    
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: {
            action: 'test_api',
            id_api: id_api,
            numero: numero,
            mensaje: mensaje
        },
        success: function(res) {
            if(res.status === 'success') {
                $('#resultadoTest').html(`<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> ${res.message}</div>`);
            } else {
                $('#resultadoTest').html(`<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${res.message}</div>`);
            }
        },
        error: function() {
            $('#resultadoTest').html('<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> Error de conexión con el servidor.</div>');
        }
    });
}

function loadUsers() {
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'load_users' },
        success: function(res) {
            if(res.status === 'success') {
                let tbody = $('#usersTableBody');
                tbody.empty();
                if(res.data.length === 0){
                    tbody.append('<tr><td colspan="5" class="text-center text-muted">No hay operadores registrados.</td></tr>');
                    return;
                }
                res.data.forEach(u => {
                    let rolBadge = u.rol === 'ADMIN' ? '<span class="badge bg-danger">Admin</span>' : 
                                  (u.rol === 'SUPERVISOR' ? '<span class="badge bg-primary">Supervisor</span>' : '<span class="badge bg-secondary">Agente</span>');
                    let sedeTxt = u.sede || 'Global';
                    let tr = `<tr>
                        <td class="fw-bold" style="padding-left: 24px;"><i class="fa-solid fa-user-circle text-muted me-2"></i> ${u.nombre}</td>
                        <td>${rolBadge}</td>
                        <td class="text-muted">${sedeTxt}</td>
                        <td>${u.limite} chats simultáneos</td>
                        <td style="text-align: right; padding-right: 24px;">
                            <button class="action-btn" title="Editar Permisos"><i class="fa-solid fa-shield-halved"></i></button>
                            <button class="action-btn danger" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>`;
                    tbody.append(tr);
                });
            }
        }
    });
}

function confirmLogout(event) {
    event.preventDefault();
    Swal.fire({
        title: '¿Cerrar Sesión?',
        text: "Tendrás que volver a ingresar tus credenciales.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#E85B14',
        cancelButtonColor: '#94A3B8',
        confirmButtonText: 'Sí, salir',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = '/starfi_crm/logout.php';
        }
    });
}

function ejecutarDiagnostico() {
    const resDiv = $('#resultadoDiagnostico');
    resDiv.html('<div class="alert alert-info py-2"><i class="fa-solid fa-spinner fa-spin me-2"></i> Ejecutando auto-diagnóstico...</div>');

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'run_diagnostico' },
        success: function(res) {
            if (res.status === 'success') {
                const data = res.data;
                let html = '<div class="card bg-light border-0 p-3 mt-2" style="border-radius: 8px;">';
                
                // Conexión BD
                const dbStatus = data.database.status === 'ok' 
                    ? '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> Conectado</span>' 
                    : '<span class="text-danger"><i class="fa-solid fa-circle-xmark me-1"></i> Error</span>';
                html += `<div class="mb-2"><strong>Base de Datos:</strong> ${dbStatus} - ${data.database.message}</div>`;
                
                // Líneas activas
                const lineasStatus = data.lineas_activas.status === 'ok' 
                    ? '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> OK</span>' 
                    : '<span class="text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> Advertencia</span>';
                html += `<div class="mb-2"><strong>Líneas Activas:</strong> ${lineasStatus} - ${data.lineas_activas.message}</div>`;
                
                // Tablas
                html += '<div class="mb-2"><strong>Estructura de Tablas:</strong><ul class="list-unstyled ps-3 mt-1 row">';
                for (const [tabla, existe] of Object.entries(data.tables.data)) {
                    const icono = existe 
                        ? '<i class="fa-solid fa-check text-success me-1"></i>' 
                        : '<i class="fa-solid fa-xmark text-danger me-1"></i>';
                    const label = existe ? `<code>${tabla}</code>` : `<code class="text-danger">${tabla} (Falta)</code>`;
                    html += `<li class="col-6 mb-1">${icono} ${label}</li>`;
                }
                html += '</ul></div>';

                // Archivos
                html += '<div class="mb-0"><strong>Archivos de Control:</strong><ul class="list-unstyled ps-3 mt-1 row">';
                for (const [archivo, existe] of Object.entries(data.files.data)) {
                    const icono = existe 
                        ? '<i class="fa-solid fa-check text-success me-1"></i>' 
                        : '<i class="fa-solid fa-xmark text-danger me-1"></i>';
                    const label = existe ? `<span>${archivo}</span>` : `<span class="text-danger">${archivo} (No encontrado)</span>`;
                    html += `<li class="col-12 mb-1">${icono} ${label}</li>`;
                }
                html += '</ul></div>';

                html += '</div>';
                resDiv.html(html);
            } else {
                resDiv.html(`<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> Error: ${res.message}</div>`);
            }
        },
        error: function() {
            resDiv.html('<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> Error de comunicación con el servidor.</div>');
        }
    });
}

function ejecutarSimulador() {
    Swal.fire({
        title: 'Ejecutando Simulación',
        text: 'Enviando payload de prueba al Webhook...',
        icon: 'info',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'run_simulacion_entrante' },
        success: function(res) {
            if (res.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: '¡Mensaje Simulado!',
                    text: res.message,
                    confirmButtonColor: '#E85B14'
                });
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'No se pudo conectar al simulador.', 'error');
        }
    });
}

function enviarNotificacionPrueba() {
    const form = $('#formNotifPrueba')[0];
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const resDiv = $('#resultadoNotifPrueba');
    resDiv.html('<div class="alert alert-info py-2"><i class="fa-solid fa-spinner fa-spin me-2"></i> Procesando y enviando notificación a Meta...</div>');

    const data = {
        action: 'run_envio_transaccional',
        telefono: $('#notif_telefono').val().trim(),
        nombre_cliente: $('#notif_cliente').val().trim(),
        monto_total: $('#notif_monto').val().trim(),
        correlativo: $('#notif_correlativo').val().trim(),
        asesor_ventas: $('#notif_asesor').val().trim(),
        telefono_asesor: $('#notif_tel_asesor').val().trim(),
        nombre_empresa: $('#notif_empresa').val().trim()
    };

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: data,
        success: function(res) {
            if (res.status === 'success') {
                resDiv.html(`
                    <div class="alert alert-success py-3">
                        <h6 class="fw-bold mb-2"><i class="fa-solid fa-check-circle me-1"></i> ${res.message}</h6>
                        <small class="d-block mb-1"><strong>Registro en Base de Datos:</strong> ${res.details.registro_bd}</small>
                        <small class="d-block mb-2"><strong>ID Mensaje Meta:</strong> ${res.details.meta_response.messages ? res.details.meta_response.messages[0].id : 'N/A'}</small>
                        <pre class="bg-dark text-light p-2 rounded small mb-0" style="max-height: 200px; overflow-y: auto;">${JSON.stringify(res.details, null, 2)}</pre>
                    </div>
                `);
            } else {
                let errorDetails = '';
                if (res.details) {
                    errorDetails = `<pre class="bg-dark text-light p-2 rounded small mt-2 mb-0" style="max-height: 200px; overflow-y: auto;">${JSON.stringify(res.details, null, 2)}</pre>`;
                }
                resDiv.html(`
                    <div class="alert alert-danger py-3">
                        <h6 class="fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Error en Envío</h6>
                        <p class="small mb-1">${res.message}</p>
                        ${errorDetails}
                    </div>
                `);
            }
        },
        error: function() {
            resDiv.html('<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> Error de conexión con el servidor.</div>');
        }
    });
}
