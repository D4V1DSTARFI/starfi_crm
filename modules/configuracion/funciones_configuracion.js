$(document).ready(function() {
    // Cargar datos iniciales desde BD
    loadSedes();
    loadAPIs();
    loadUsers();
    loadRespuestasRapidas();

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
        if (!$('#api_sede').val() || !$('#api_descripcion').val() || !$('#api_telefono').val() || !$('#api_telefono_meta').val()) {
            Swal.fire('Error', 'Complete los campos obligatorios (*)', 'warning'); return;
        }

        const data = {
            action: 'save_api',
            id_api: $('#id_api').val(),
            id_sede: $('#api_sede').val(),
            descripcion: $('#api_descripcion').val().trim(),
            telefono: $('#api_telefono').val().trim(),
            telefono_meta: $('#api_telefono_meta').val().trim(),
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

    // 3. Configuración GEMA AI Multi-Sede
    cargarSedesEnGemaSelect();

    $('#gema_id_sede').on('change', function() {
        const id_sede = $(this).val();
        if (id_sede) {
            loadGemaConfig(id_sede);
        }
    });

    $('#btnSaveGema').on('click', function() {
        const id_sede = $('#gema_id_sede').val();
        const prompt = $('#gema_prompt').val().trim();
        const nombre = $('#gema_nombre').val().trim();
        const direccion = $('#gema_direccion').length ? ($('#gema_direccion').val() || '').trim() : '';
        const link_gps = $('#gema_link_gps').val().trim();
        const token = $('#gema_token').val().trim();
        const modelo = $('#gema_modelo_ia').val();
        const temperatura = $('#gema_temperatura').val();
        const estado = $('#gema_estado').is(':checked') ? 'ACTIVO' : 'INACTIVO';
        
        if(!id_sede) {
            Swal.fire('Error', 'Seleccione una Sede', 'warning');
            return;
        }

        if(!prompt) {
            Swal.fire('Error', 'El Prompt de Instrucciones es obligatorio', 'warning');
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
                id_sede: id_sede,
                agente_nombre: nombre,
                direccion_sede: direccion,
                link_gps: link_gps,
                agente_instrucciones: prompt,
                gemini_api_key: token,
                modelo_ia: modelo,
                temperatura: temperatura,
                estado_ia: estado
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

    $('#btnTestGema').on('click', function() {
        const token = $('#gema_token').val().trim();
        const modelo = $('#gema_modelo_ia').val();
        const nombre = $('#gema_nombre').val().trim();
        const temperatura = $('#gema_temperatura').val();

        if(!token) {
            Swal.fire('Atención', 'Introduce una API Key para realizar la prueba.', 'warning');
            return;
        }

        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Probando...');

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'test_gema_connection',
                token: token,
                modelo_ia: modelo,
                nombre: nombre,
                temperatura: temperatura
            },
            success: function(res) {
                if(res.status === 'success') {
                    $('#testGemaContent').text(res.respuesta);
                    $('#testGemaLog').removeClass('d-none');
                    Swal.fire('¡Conexión Exitosa!', 'La API de IA respondió correctamente.', 'success');
                } else {
                    $('#testGemaLog').addClass('d-none');
                    Swal.fire('Error de Conexión', res.message, 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Fallo al comunicarse con el servidor.', 'error');
            },
            complete: function() {
                btn.prop('disabled', false).html('<i class="fa-solid fa-bolt me-2 text-warning"></i> Probar Conexión IA');
            }
        });
    });

});

function cargarSedesEnGemaSelect() {
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function(res) {
            if(res.status === 'success' && res.data) {
                let select = $('#gema_id_sede');
                select.empty();
                res.data.forEach(s => {
                    select.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
                });
                if (res.data.length > 0) {
                    select.val(res.data[0].id);
                    loadGemaConfig(res.data[0].id);
                }
            }
        }
    });
}

function loadGemaConfig(id_sede) {
    $.ajax({
        url: 'back_configuracion.php', 
        type: 'POST', 
        dataType: 'json',
        data: { action: 'load_gema', id_sede: id_sede },
        success: function(res) {
            if(res.status === 'success' && res.data) {
                if (res.data.prompt !== undefined) $('#gema_prompt').val(res.data.prompt);
                if (res.data.nombre !== undefined) $('#gema_nombre').val(res.data.nombre);
                if ($('#gema_direccion').length && res.data.direccion_sede !== undefined) $('#gema_direccion').val(res.data.direccion_sede);
                if (res.data.link_gps !== undefined) $('#gema_link_gps').val(res.data.link_gps);
                if (res.data.token !== undefined) $('#gema_token').val(res.data.token);
                if (res.data.modelo_ia !== undefined) $('#gema_modelo_ia').val(res.data.modelo_ia);
                if (res.data.temperatura !== undefined) $('#gema_temperatura').val(res.data.temperatura);
                $('#gema_estado').prop('checked', res.data.estado == 1 || res.data.estado_ia === 'ACTIVO');
                updateGemaLivePreview();
            }
        }
    });
}

// Escuchadores de eventos para actualización en tiempo real de la vista previa de WhatsApp
$(document).on('input change', '#gema_nombre, #gema_link_gps, #gema_id_sede, #gema_estado', function() {
    updateGemaLivePreview();
});

function updateGemaLivePreview() {
    const nombre = $('#gema_nombre').val().trim() || 'Gema';
    const linkGps = $('#gema_link_gps').val().trim() || 'https://www.google.com/maps?q=10.48060,-66.90360';
    const sedeNombre = $('#gema_id_sede option:selected').text() || 'Sede STARFI';
    const isActivo = $('#gema_estado').is(':checked');

    $('#prev_bot_name').text(nombre);
    $('#preview_agent_name_display').text(nombre + ' (IA)');
    $('#preview_bubble_name').text(nombre + ' Bot');
    
    let displayGps = linkGps;
    if (displayGps.length > 55) {
        displayGps = displayGps.substring(0, 52) + '...';
    }
    $('#prev_bot_gps').html(`<a href="${linkGps}" target="_blank" class="text-primary text-decoration-underline fw-bold" style="word-break: break-all; overflow-wrap: anywhere;">${displayGps}</a>`);
    
    $('#prev_sede_name').text(sedeNombre);
    $('#preview_sede_name_display').text(sedeNombre + ' • En línea');

    if (isActivo) {
        $('#gema_estado_badge').removeClass('bg-secondary bg-opacity-10 text-secondary').addClass('bg-success bg-opacity-10 text-success').html('<i class="fa-solid fa-circle-check me-1"></i> BOT ACTIVO');
    } else {
        $('#gema_estado_badge').removeClass('bg-success bg-opacity-10 text-success').addClass('bg-secondary bg-opacity-10 text-secondary').html('<i class="fa-solid fa-circle-xmark me-1"></i> BOT INACTIVO');
    }
}

function togglePasswordVisibility(fieldId) {
    const input = $('#' + fieldId);
    const eye = $('#' + fieldId + '_eye');
    if (input.attr('type') === 'password') {
        input.attr('type', 'text');
        eye.removeClass('fa-eye').addClass('fa-eye-slash');
    } else {
        input.attr('type', 'password');
        eye.removeClass('fa-eye-slash').addClass('fa-eye');
    }
}

// Variables y lógica para el Selector Interactivo de Mapa GPS
let mapPicker = null;
let mapMarker = null;
let selectedLat = 10.4806;
let selectedLng = -66.9036;

function abrirModalMapaGPS() {
    const existingUrl = $('#gema_link_gps').val().trim();
    if (existingUrl) {
        const match = existingUrl.match(/q=(-?\d+\.\d+),(-?\d+\.\d+)/);
        if (match && match[1] && match[2]) {
            selectedLat = parseFloat(match[1]);
            selectedLng = parseFloat(match[2]);
        }
    }

    const modalEl = document.getElementById('modalMapPicker');
    if (!modalEl) return;

    const modal = new bootstrap.Modal(modalEl);
    modal.show();

    $('#modalMapPicker').off('shown.bs.modal').on('shown.bs.modal', function () {
        initLeafletMapPicker();
    });
}

function initLeafletMapPicker() {
    if (typeof L === 'undefined') {
        Swal.fire('Error', 'No se pudo cargar la librería de mapas.', 'error');
        return;
    }

    if (!mapPicker) {
        mapPicker = L.map('leafletMapContainer').setView([selectedLat, selectedLng], 14);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap'
        }).addTo(mapPicker);

        mapMarker = L.marker([selectedLat, selectedLng], { draggable: true }).addTo(mapPicker);

        mapMarker.on('dragend', function () {
            const position = mapMarker.getLatLng();
            updateSelectedCoords(position.lat, position.lng);
        });

        mapPicker.on('click', function (e) {
            mapMarker.setLatLng(e.latlng);
            updateSelectedCoords(e.latlng.lat, e.latlng.lng);
        });
    } else {
        mapPicker.setView([selectedLat, selectedLng], 14);
        mapMarker.setLatLng([selectedLat, selectedLng]);
        setTimeout(() => mapPicker.invalidateSize(), 200);
    }

    updateSelectedCoords(selectedLat, selectedLng);
}

function updateSelectedCoords(lat, lng) {
    selectedLat = lat;
    selectedLng = lng;
    $('#selectedCoordsText').text(`${lat.toFixed(5)}, ${lng.toFixed(5)}`);
}

function buscarEnMapa() {
    const query = $('#mapSearchInput').val().trim();
    if (!query) return;

    $('#reverseGeocodeText').text('Buscando en mapa...');
    $.getJSON(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`, function (data) {
        if (data && data.length > 0) {
            const lat = parseFloat(data[0].lat);
            const lon = parseFloat(data[0].lon);
            mapPicker.setView([lat, lon], 15);
            mapMarker.setLatLng([lat, lon]);
            updateSelectedCoords(lat, lon);
            $('#reverseGeocodeText').text(data[0].display_name);
        } else {
            Swal.fire('Sin resultados', 'No se encontró la zona o dirección especificada.', 'info');
        }
    });
}

function confirmarUbicacionMapa() {
    const googleMapsUrl = `https://www.google.com/maps?q=${selectedLat.toFixed(5)},${selectedLng.toFixed(5)}`;
    $('#gema_link_gps').val(googleMapsUrl).trigger('change');
    
    const modalEl = document.getElementById('modalMapPicker');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    Swal.fire({
        icon: 'success',
        title: 'Ubicación GPS Seleccionada',
        text: `Se ha vinculado el enlace Google Maps: ${googleMapsUrl}`,
        timer: 2000,
        showConfirmButton: false
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
                                    <button class="btn btn-link text-info text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Plantillas Meta" onclick="gestionarPlantillas(${s.id})"><i class="fa-solid fa-layer-group"></i></button>
                                    <button class="btn btn-link text-warning text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Generar Token Externo" onclick="generarToken(${s.id})"><i class="fa-solid fa-key"></i></button>
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
                
                $('#modalSede .modal-title').html('<i class="fa-solid fa-building me-2 text-starfi-primary"></i>Editar Sede');
                
                var modalEl = document.getElementById('modalSede');
                var myModal = bootstrap.Modal.getOrCreateInstance(modalEl);
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

function generarToken(id_sede) {
    Swal.fire({
        title: '¿Generar nuevo Token?',
        text: "Esto creará un token de acceso de 64 caracteres para integraciones externas con esta Sede. Si ya existía uno, quedará invalidado.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#E85B14',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, generar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'back_configuracion.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'generate_api_token', id_sede: id_sede },
                success: function(res) {
                    if (res.status === 'success') {
                        Swal.fire({
                            title: 'Token Generado',
                            html: `<p>Usa este token en el sistema POS o ERP del cliente:</p>
                                   <div style="background:#f4f4f4; padding:10px; border-radius:5px; word-break: break-all; font-family: monospace; font-weight:bold;">${res.token}</div>`,
                            icon: 'success'
                        });
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

function gestionarPlantillas(id_sede) {
    $('#plantillas_id_sede').val(id_sede);
    mostrarListaPlantillas();
    
    var modalEl = document.getElementById('modalPlantillasMeta');
    var myModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    myModal.show();
    
    cargarPlantillasDeMeta();
}

function mostrarCrearPlantilla() {
    $('#vistaListaPlantillas').hide();
    $('#vistaCrearPlantilla').fadeIn();
    $('#new_template_name').val('');
    $('#new_template_body').val('');
}

function mostrarListaPlantillas() {
    $('#vistaCrearPlantilla').hide();
    $('#vistaListaPlantillas').fadeIn();
}

function cargarPlantillasDeMeta() {
    let id_sede = $('#plantillas_id_sede').val();
    let tbody = $('#tablaPlantillasMeta tbody');
    tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando plantillas desde Meta...</td></tr>');
    
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_meta_templates', id_sede: id_sede },
        success: function(res) {
            if(res.status === 'success') {
                tbody.empty();
                if(!res.data || res.data.length === 0) {
                    tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted">No hay plantillas registradas en Meta.</td></tr>');
                    return;
                }
                
                res.data.forEach(t => {
                    let badgeColor = 'bg-secondary';
                    if (t.status === 'APPROVED') badgeColor = 'bg-success';
                    else if (t.status === 'REJECTED') badgeColor = 'bg-danger';
                    else if (t.status === 'PENDING') badgeColor = 'bg-warning text-dark';
                    
                    let html = `
                        <tr>
                            <td class="fw-bold text-dark">${t.name}</td>
                            <td><span class="badge bg-light text-dark border">${t.category}</span></td>
                            <td>${t.language}</td>
                            <td><span class="badge ${badgeColor}">${t.status}</span></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-danger" onclick="eliminarPlantilla('${t.name}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
                    tbody.append(html);
                });
            } else {
                tbody.html(`<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>${res.message}</td></tr>`);
            }
        },
        error: function() {
            tbody.html('<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Error de conexión al cargar plantillas.</td></tr>');
        }
    });
}

function sincronizarPlantillasMeta() {
    let id_sede = $('#plantillas_id_sede').val();
    let tbody = $('#tablaPlantillasMeta tbody');
    tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Sincronizando con Meta...</td></tr>');
    
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'sync_meta_templates', id_sede: id_sede },
        success: function(res) {
            if(res.status === 'success') {
                tbody.empty();
                if(!res.data || res.data.length === 0) {
                    tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted">No hay plantillas registradas en Meta.</td></tr>');
                    return;
                }
                
                res.data.forEach(t => {
                    let badgeColor = 'bg-secondary';
                    if (t.status === 'APPROVED') badgeColor = 'bg-success';
                    else if (t.status === 'REJECTED') badgeColor = 'bg-danger';
                    else if (t.status === 'PENDING') badgeColor = 'bg-warning text-dark';
                    
                    let html = `
                        <tr>
                            <td class="fw-bold text-dark">${t.name}</td>
                            <td><span class="badge bg-light text-dark border">${t.category}</span></td>
                            <td>${t.language}</td>
                            <td><span class="badge ${badgeColor}">${t.status}</span></td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-danger" onclick="eliminarPlantilla('${t.name}')" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
                    tbody.append(html);
                });
            } else {
                tbody.html(`<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>${res.message}</td></tr>`);
            }
        },
        error: function() {
            tbody.html('<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Error de conexión al sincronizar plantillas.</td></tr>');
        }
    });
}

function guardarNuevaPlantilla() {
    let id_sede = $('#plantillas_id_sede').val();
    let name = $('#new_template_name').val().trim();
    let category = $('#new_template_category').val();
    let lang = $('#new_template_lang').val();
    let body = $('#new_template_body').val().trim();
    
    if(!name || !body) {
        Swal.fire('Atención', 'El nombre y el cuerpo son obligatorios', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Creando Plantilla',
        html: 'Enviando a revisión a Meta...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });
    
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { 
            action: 'create_meta_template', 
            id_sede: id_sede,
            name: name,
            category: category,
            language: lang,
            body: body
        },
        success: function(res) {
            if(res.status === 'success') {
                Swal.fire('¡Éxito!', 'Plantilla creada y enviada a revisión.', 'success');
                mostrarListaPlantillas();
                cargarPlantillasDeMeta();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}

function crearPlantillaCSAT() {
    let id_sede = $('#plantillas_id_sede').val();
    
    Swal.fire({
        title: '¿Crear Plantilla CSAT?',
        text: "Se enviará automáticamente a Meta una plantilla estandarizada llamada 'starfi_csat_survey' donde se le pedirá al cliente calificar la atención del 1 al 5.",
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#E85B14',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, crear'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Creando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            $.ajax({
                url: 'back_configuracion.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'create_csat_template', id_sede: id_sede },
                success: function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Plantilla Creada', 'La plantilla CSAT ha sido enviada a revisión. En breve estará disponible para su uso.', 'success');
                        cargarPlantillasDeMeta();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Fallo de conexión.', 'error');
                }
            });
        }
    });
}

function crearPlantillaNotificacionInterna() {
    let id_sede = $('#plantillas_id_sede').val();
    
    Swal.fire({
        title: '¿Crear Plantilla Notificación Interna?',
        text: "Se enviará automáticamente a Meta una plantilla estandarizada de tipo UTILITY llamada 'starfi_notificacion_interna' con una variable genérica {{1}} para enviar cualquier aviso interno a operadores.",
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#0dcaf0',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, crear'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({ title: 'Creando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            $.ajax({
                url: 'back_configuracion.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'create_internal_notification_template', id_sede: id_sede },
                success: function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Plantilla Creada', 'La plantilla starfi_notificacion_interna ha sido enviada a revisión a Meta. En breve estará disponible para su uso.', 'success');
                        sincronizarPlantillasMeta();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Fallo de conexión.', 'error');
                }
            });
        }
    });
}

function eliminarPlantilla(name) {
    Swal.fire({
        title: '¿Eliminar Plantilla?',
        text: `Se eliminará la plantilla '${name}' de Meta de forma permanente.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, eliminar'
    }).then((result) => {
        if (result.isConfirmed) {
            let id_sede = $('#plantillas_id_sede').val();
            
            Swal.fire({ title: 'Eliminando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            $.ajax({
                url: 'back_configuracion.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'delete_meta_template', id_sede: id_sede, name: name },
                success: function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Eliminada', 'Plantilla eliminada de Meta.', 'success');
                        cargarPlantillasDeMeta();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}

function configurarAPI(id_sede) {
    // 1. Cargar las sedes primero para asegurar que existan los options en el select
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function(resSedes) {
            if(resSedes.status === 'success'){
                let select = $('#api_sede');
                select.html('<option value="">Seleccione una sede...</option>');
                resSedes.data.forEach(s => {
                    select.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
                });
                
                // 2. Buscar si ya hay una API configurada para esta sede, sino abre el modal en blanco
                $.ajax({
                    url: 'back_configuracion.php', type: 'POST', dataType: 'json',
                    data: { action: 'get_api_by_sede', id_sede: id_sede },
                    success: function(res) {
                        $('#formAPI')[0].reset();
                        $('#api_sede').val(id_sede);
                        
                        if(res.status === 'success' && res.data) {
                            // Hay API
                            const a = res.data;
                            $('#id_api').val(a.id);
                            $('#api_descripcion').val(a.descripcion);
                            $('#api_telefono').val(a.numero_telefono);
                            $('#api_telefono_meta').val(a.meta_app_id);
                            $('#api_token_meta').val(a.meta_token);
                            $('#api_id_negocio').val(a.id_negocio);
                            $('#api_estado').val(a.estado);
                            $('#api_limite').val(a.limite_solicitudes);
                            $('#api_observacion').val(a.observaciones);
                            
                            $('#modalAPI .modal-title').html('<i class="fa-brands fa-whatsapp me-2"></i>Editar API WhatsApp');
                        } else {
                            // Nueva API para esta sede
                            $('#id_api').val('');
                            $('#modalAPI .modal-title').html('<i class="fa-brands fa-whatsapp me-2"></i>Nueva API WhatsApp');
                        }
                        
                        // 3. Mostrar modal
                        var modalEl = document.getElementById('modalAPI');
                        var myModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                        myModal.show();
                    }
                });
            }
        }
    });
}

function mostrarEstadisticas(id_sede) {
    window.location.href = '../dashboard/dashboard.php?sede=' + id_sede;
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
                        
                        let badge = a.estado === 'ACTIVO' ? '<span class="badge bg-success" style="cursor: pointer;" title="Haz clic para desactivar sede"><i class="fa-solid fa-circle-check me-1"></i>Activo</span>' : '<span class="badge bg-secondary" style="cursor: pointer;" title="Haz clic para activar sede"><i class="fa-solid fa-circle-xmark me-1"></i>Inactivo</span>';
                        
                        let token_trunc = a.meta_token ? a.meta_token.substring(0, 20) + '...' : 'N/A';
                        let nombreSedeEscaped = (a.nombre_sede || a.descripcion || '').replace(/'/g, "\\'");
                        
                        let card = `
                        <div class="col-md-4">
                            <div class="card h-100 shadow-sm border-0" style="border-radius: 10px; overflow: hidden;">
                                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-3 pb-0">
                                    <h6 class="mb-0 fw-bold text-muted"><i class="fa-solid fa-circle ${a.estado === 'ACTIVO' ? 'text-success' : 'text-secondary'} me-2" style="font-size: 0.6rem;"></i>${a.descripcion || 'Sin descripción'}</h6>
                                    <div onclick="toggleEstadoAPI(${a.id}, '${a.estado}', '${nombreSedeEscaped}')">
                                        ${badge}
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3 text-muted" style="font-size: 0.85rem;"><i class="fa-solid fa-building me-2"></i>${a.nombre_sede || 'Sin sede asignada'}</div>
                                    
                                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-phone me-2" style="width: 16px;"></i><strong class="text-dark">Teléfono:</strong><br>${a.numero_telefono || 'N/A'}</div>
                                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-hashtag me-2" style="width: 16px;"></i><strong class="text-dark">ID Meta:</strong><br>${a.meta_app_id || 'N/A'}</div>
                                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-key me-2" style="width: 16px;"></i><strong class="text-dark">Metadatos del token:</strong><br><span style="font-family: monospace; font-size: 0.8rem;">${token_trunc}</span></div>
                                    <div class="mb-3 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-briefcase me-2" style="width: 16px;"></i><strong class="text-dark">ID de WABA:</strong><br>${a.id_negocio || 'N/A'}</div>
                                    
                                    <small class="text-muted"><i class="fa-solid fa-check-double me-1 text-success"></i>Hoy: 0 mensajes</small>
                                </div>
                                <div class="card-footer bg-white border-top-0 p-0">
                                    <div class="d-flex border-top">
                                        <button class="btn btn-link text-primary text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Editar API" onclick="editarAPI(${a.id})"><i class="fa-solid fa-pen"></i></button>
                                        <button class="btn btn-link text-info text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Probar API" onclick="abrirPruebaAPI(${a.id}, '${a.numero_telefono}')"><i class="fa-solid fa-bolt"></i></button>
                                        <button class="btn btn-link ${a.estado === 'ACTIVO' ? 'text-warning' : 'text-success'} text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="${a.estado === 'ACTIVO' ? 'Desactivar Sede (No enviar mensajes)' : 'Activar Sede'}" onclick="toggleEstadoAPI(${a.id}, '${a.estado}', '${nombreSedeEscaped}')"><i class="fa-solid fa-power-off"></i></button>
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
                $('#api_telefono_meta').val(a.meta_app_id);
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

function toggleEstadoAPI(id_api, estado_actual, nombre_sede) {
    const esActivo = estado_actual === 'ACTIVO';
    const nuevoEstado = esActivo ? 'INACTIVO' : 'ACTIVO';
    const titulo = esActivo ? '¿Desactivar esta sede?' : '¿Activar esta sede?';
    const texto = esActivo 
        ? `Al desactivar ${nombre_sede || 'esta sede'}, no se enviarán más mensajes automáticos ni notificaciones.`
        : `Al activar ${nombre_sede || 'esta sede'}, se reanudará el envío de mensajes de WhatsApp.`;
    const btnTexto = esActivo ? 'Sí, desactivar' : 'Sí, activar';
    const btnColor = esActivo ? '#ffc107' : '#198754';

    Swal.fire({
        title: titulo,
        text: texto,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: btnColor,
        cancelButtonColor: '#6c757d',
        confirmButtonText: btnTexto,
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'back_configuracion.php', type: 'POST', dataType: 'json',
                data: { action: 'toggle_api_estado', id_api: id_api, nuevo_estado: nuevoEstado },
                success: function(res) {
                    if (res.status === 'success') {
                        Swal.fire('Estado actualizado', res.message, 'success');
                        loadAPIs();
                        loadSedes();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'No se pudo procesar la solicitud.', 'error');
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

// --- NUEVAS FUNCIONES DE INTEGRACIÓN DIRECTA CON META GRAPH ---
function toggleApiExperience() {
    const isNew = document.getElementById('api_type_new').checked;
    
    if (isNew) {
        $('#waba_container').hide();
        $('#select_number_container').hide();
        $('#phone_id_readonly_container').hide();
        $('#api_telefono').prop('readonly', false);
        $('#api_telefono_meta').prop('readonly', false).val('');
        
        $('#phone_id_manual_container').show();
        $('#pin_container').show();
    } else {
        $('#waba_container').show();
        $('#phone_id_manual_container').hide();
        $('#pin_container').hide();
        
        $('#phone_id_readonly_container').show();
        $('#api_telefono').prop('readonly', true);
        $('#api_telefono_meta').prop('readonly', true);
    }
}

function fetchMetaApis() {
    const waba_id = $('#api_id_negocio').val();
    
    if(!waba_id) {
        Swal.fire('Atención', 'Debes ingresar el WABA ID de Meta', 'warning');
        return;
    }
    
    $('#btnFetchMeta').html('<i class="fa-solid fa-spinner fa-spin"></i>').prop('disabled', true);
    
    $.post('back_configuracion.php', {
        action: 'fetch_meta_apis',
        waba_id: waba_id
    }, function(res) {
        $('#btnFetchMeta').html('<i class="fa-solid fa-cloud-arrow-down me-1"></i> Buscar Líneas').prop('disabled', false);
        
        if(res.status === 'success' && res.data && res.data.length > 0) {
            let options = '<option value="">Seleccione un número de la lista...</option>';
            window.lastFetchedMetaNumbers = res.data; // save to global for autofill
            
            res.data.forEach(num => {
                options += `<option value="${num.id}">${num.display_phone_number} (${num.quality_rating})</option>`;
            });
            
            $('#api_select_meta').html(options);
            $('#select_number_container').slideDown();
            Swal.fire('¡Éxito!', `Se encontraron ${res.data.length} líneas en Meta.`, 'success');
        } else {
            Swal.fire('Error', res.message || 'No se encontraron números o el token es inválido.', 'error');
        }
    }, 'json').fail(function() {
        $('#btnFetchMeta').html('<i class="fa-solid fa-cloud-arrow-down me-1"></i> Buscar Líneas').prop('disabled', false);
        Swal.fire('Error', 'Error de conexión con el servidor local.', 'error');
    });
}

function autoFillMetaNumber() {
    const selectedId = $('#api_select_meta').val();
    if(!selectedId || !window.lastFetchedMetaNumbers) return;
    
    const numData = window.lastFetchedMetaNumbers.find(n => n.id === selectedId);
    if(numData) {
        $('#api_telefono').val(numData.display_phone_number);
        $('#api_telefono_meta').val(numData.id);
    }
}

function registerMetaPhone() {
    const phone_id = $('#api_telefono_meta_manual').val();
    const pin = $('#api_pin_meta').val();
    
    if(!phone_id || !pin) {
        Swal.fire('Atención', 'Completa el Phone ID y el PIN de 6 dígitos.', 'warning');
        return;
    }
    
    if(pin.length !== 6) {
        Swal.fire('Atención', 'El PIN debe ser exactamente de 6 dígitos numéricos.', 'warning');
        return;
    }
    
    $('#btnRegisterMeta').html('<i class="fa-solid fa-spinner fa-spin"></i>').prop('disabled', true);
    
    $.post('back_configuracion.php', {
        action: 'register_meta_phone',
        phone_id: phone_id,
        pin: pin
    }, function(res) {
        $('#btnRegisterMeta').html('<i class="fa-solid fa-check-circle me-1"></i> Dar Alta').prop('disabled', false);
        
        if(res.status === 'success') {
            Swal.fire('¡Dado de Alta!', res.message, 'success');
            // Auto fill the local CRM registration fields below
            $('#api_telefono_meta').val(phone_id);
            // Switch to existing mode since it's now registered
            $('#api_type_existing').prop('checked', true);
            toggleApiExperience();
        } else {
            Swal.fire('Error', res.message || 'Error al registrar el número en Meta.', 'error');
        }
    }, 'json').fail(function() {
        $('#btnRegisterMeta').html('<i class="fa-solid fa-check-circle me-1"></i> Dar Alta').prop('disabled', false);
        Swal.fire('Error', 'Error de conexión con el servidor local.', 'error');
    });
}

// --- GESTIÓN DE RESPUESTAS RÁPIDAS ---
function loadRespuestasRapidas() {
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'load_respuestas_rapidas' },
        success: function(res) {
            if(res.status === 'success') {
                let container = $('#respuestasContainer');
                container.empty();
                if(res.data.length === 0){
                    container.append('<div class="col-12 text-center text-muted p-4">No hay respuestas rápidas registradas.</div>');
                    return;
                }
                res.data.forEach(r => {
                    let card = `
                    <div class="col-md-4">
                        <div class="card h-100 shadow-sm border-0" style="border-radius: 10px;">
                            <div class="card-header bg-white border-0 pt-3 pb-0">
                                <h6 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-bolt text-warning me-2"></i>${r.titulo}</h6>
                                <small class="text-muted"><i class="fa-solid fa-building me-1"></i>${r.nombre_sede || 'Sede no asignada'}</small>
                            </div>
                            <div class="card-body">
                                <p class="text-muted mb-0" style="font-size: 0.9rem; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">${r.mensaje}</p>
                            </div>
                            <div class="card-footer bg-white border-top-0 p-0">
                                <div class="d-flex border-top">
                                    <button class="btn btn-link text-primary text-decoration-none flex-fill border-end rounded-0 py-2 action-btn" title="Editar" onclick="editarRespuestaRapida(${r.id})"><i class="fa-solid fa-pen"></i></button>
                                    <button class="btn btn-link text-danger text-decoration-none flex-fill rounded-0 py-2 action-btn" title="Eliminar" onclick="borrarRespuestaRapida(${r.id})"><i class="fa-solid fa-trash"></i></button>
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

function addRespuestaRapida() {
    $('#formRespuesta')[0].reset();
    $('#id_respuesta').val('');
    
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function(res) {
            if(res.status === 'success'){
                let select = $('#resp_id_sede');
                select.html('<option value="">Seleccione una sede...</option>');
                res.data.forEach(s => {
                    select.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
                });
                
                $('#modalRespuestaRapida .modal-title').html('<i class="fa-solid fa-bolt text-warning me-2"></i>Nueva Respuesta Rápida');
                var myModal = new bootstrap.Modal(document.getElementById('modalRespuestaRapida'));
                myModal.show();
            }
        }
    });
}

function editarRespuestaRapida(id) {
    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function(resSedes) {
            if(resSedes.status === 'success'){
                let select = $('#resp_id_sede');
                select.html('<option value="">Seleccione una sede...</option>');
                resSedes.data.forEach(s => {
                    select.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
                });
                
                $.ajax({
                    url: 'back_configuracion.php', type: 'POST', dataType: 'json',
                    data: { action: 'get_respuesta_rapida', id: id },
                    success: function(res) {
                        if(res.status === 'success' && res.data) {
                            const r = res.data;
                            $('#id_respuesta').val(r.id);
                            $('#resp_id_sede').val(r.id_sede);
                            $('#resp_titulo').val(r.titulo);
                            $('#resp_mensaje').val(r.mensaje);
                            
                            $('#modalRespuestaRapida .modal-title').html('<i class="fa-solid fa-bolt text-warning me-2"></i>Editar Respuesta Rápida');
                            var myModal = new bootstrap.Modal(document.getElementById('modalRespuestaRapida'));
                            myModal.show();
                        }
                    }
                });
            }
        }
    });
}

$('#btnSaveRespuesta').on('click', function() {
    if (!$('#resp_id_sede').val() || !$('#resp_titulo').val() || !$('#resp_mensaje').val()) {
        Swal.fire('Error', 'Complete todos los campos obligatorios (*)', 'warning'); return;
    }

    const data = {
        action: 'save_respuesta_rapida',
        id_respuesta: $('#id_respuesta').val(),
        id_sede: $('#resp_id_sede').val(),
        titulo: $('#resp_titulo').val().trim(),
        mensaje: $('#resp_mensaje').val().trim()
    };

    $.ajax({
        url: 'back_configuracion.php', type: 'POST', dataType: 'json',
        data: data,
        success: function(res) {
            if(res.status === 'success'){
                bootstrap.Modal.getInstance(document.getElementById('modalRespuestaRapida')).hide();
                Swal.fire('Éxito', res.message, 'success');
                loadRespuestasRapidas();
            } else { Swal.fire('Error', res.message, 'error'); }
        }
    });
});

function borrarRespuestaRapida(id) {
    Swal.fire({
        title: '¿Estás seguro?',
        text: "Esta respuesta rápida se eliminará permanentemente.",
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
                data: { action: 'delete_respuesta_rapida', id: id },
                success: function(res) {
                    if(res.status === 'success') {
                        Swal.fire('Eliminado', res.message, 'success');
                        loadRespuestasRapidas();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }
            });
        }
    });
}
