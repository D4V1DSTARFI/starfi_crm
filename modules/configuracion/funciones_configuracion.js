function escapeHtmlConfig(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
    });
}
const escapeHtml = escapeHtmlConfig;

const cfgInFlight = new Set();
function acquireCfgLock(key, $btn, loadingHtml) {
    if (cfgInFlight.has(key)) return false;
    if ($btn && $btn.length && $btn.prop('disabled')) return false;
    cfgInFlight.add(key);
    if ($btn && $btn.length) {
        $btn.data('orig-html', $btn.html());
        $btn.prop('disabled', true);
        if (loadingHtml) {
            $btn.html(loadingHtml);
        }
    }
    return true;
}

function releaseCfgLock(key, $btn, customRestoreHtml) {
    cfgInFlight.delete(key);
    if ($btn && $btn.length) {
        const orig = customRestoreHtml !== undefined ? customRestoreHtml : $btn.data('orig-html');
        $btn.prop('disabled', false);
        if (orig !== undefined) {
            $btn.html(orig);
        }
    }
}

let cachedSedesList = [];
let cachedApisList = [];
let isMasterConfigUser = true;

$(document).ready(function () {
    const bootEl = document.getElementById('configBootData');
    if (bootEl) {
        isMasterConfigUser = bootEl.getAttribute('data-is-master') === '1';
    }
    if (!isMasterConfigUser) {
        $('#btnAddSede, #btnAddAPI').hide();
    }

    const toggleSidebarBtn = document.getElementById('toggleSidebar');
    if (toggleSidebarBtn) {
        toggleSidebarBtn.addEventListener('click', function () {
            const sb = document.getElementById('sidebar');
            if (sb) sb.classList.toggle('collapsed');
        });
    }

    $(document).ajaxError(function (event, jqxhr) {
        if (jqxhr && jqxhr.status === 401) {
            window.location.href = '../../login.php';
        }
    });

    // Cargar datos iniciales desde BD
    loadSedes();
    loadAPIs();
    loadRespuestasRapidas();

    // Filtros locales de Sedes
    $(document).on('input change', '#searchSede, #filterEstadoSede, #filterApiSede', function () {
        renderSedesCards(cachedSedesList);
    });

    // Filtros locales de APIs
    $(document).on('input change', '#filterApiSedeSelect, #filterApiEstado, #searchApi', function () {
        renderApisCards(cachedApisList);
    });
    $('#btnSearchApi').on('click', function () {
        renderApisCards(cachedApisList);
    });

    // 1. Añadir Sede
    $('#btnAddSede').on('click', function () {
        if ($('#formSede').length) {
            $('#formSede')[0].reset();
        }
        $('#id_sede').val('');
        $('#modalSede .modal-title').html('<i class="fa-solid fa-building me-2 text-starfi-primary"></i>Nueva Sede e Integración');
        const modalEl = document.getElementById('modalSede');
        if (modalEl) {
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }
    });

    $('#btnSaveSede').on('click', function () {
        const $btn = $(this);
        const razon_social = ($('#razon_social').val() || '').trim();
        const rif = ($('#rif').val() || '').trim();
        if (!razon_social || !rif) {
            Swal.fire('Error', 'Razón Social y RIF son obligatorios', 'warning');
            return;
        }
        if (!acquireCfgLock('save_sede', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...')) return;

        const data = {
            action: 'save_sede',
            id_sede: $('#id_sede').val(),
            razon_social: razon_social,
            rif: rif,
            telefono: ($('#telefono').val() || '').trim(),
            email: ($('#email').val() || '').trim(),
            direccion: ($('#direccion').val() || '').trim(),
            ciudad: ($('#ciudad').val() || '').trim(),
            estado_loc: ($('#estado_loc').val() || '').trim(),
            codigo_postal: ($('#codigo_postal').val() || '').trim(),
            estado_sede: $('#estado_sede').val(),
            tipo_sede: $('#tipo_sede').val(),
            observaciones: ($('#observaciones').val() || '').trim()
        };

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (res) {
                if (res && res.status === 'success') {
                    const inst = bootstrap.Modal.getInstance(document.getElementById('modalSede'));
                    if (inst) inst.hide();
                    Swal.fire('Éxito', res.message, 'success');
                    loadSedes();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo guardar la sede.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error de conexión al guardar la sede.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('save_sede', $btn, 'Guardar Sede');
            }
        });
    });

    // 1.5. Añadir API WhatsApp
    $('#btnAddAPI').on('click', function () {
        const $btn = $(this);
        if (!acquireCfgLock('open_add_api', $btn)) return;

        if ($('#formAPI').length) {
            $('#formAPI')[0].reset();
        }
        $('#id_api').val('');
        resetSecretPasswordInput('api_token_meta', false, '', 'Deje vacío para usar Token Global (.env) o ingrese EAA...');
        $('#modalAPI .modal-title').html('<i class="fa-brands fa-whatsapp me-2"></i>Nueva API WhatsApp');

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_sedes_list' },
            success: function (res) {
                if (res && res.status === 'success') {
                    const select = $('#api_sede');
                    select.html('<option value="">Seleccione una sede...</option>');
                    (res.data || []).forEach(s => {
                        select.append(`<option value="${parseInt(s.id, 10) || 0}">${escapeHtmlConfig(s.nombre_sede)}</option>`);
                    });
                    const modalEl = document.getElementById('modalAPI');
                    if (modalEl) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'No se pudieron cargar las sedes.', 'error');
                }
            },
            complete: function () {
                releaseCfgLock('open_add_api', $btn);
            }
        });
    });

    $('#btnSaveAPI').on('click', function () {
        const $btn = $(this);
        if (!$('#api_sede').val() || !$('#api_descripcion').val() || !$('#api_telefono').val() || !$('#api_telefono_meta').val()) {
            Swal.fire('Error', 'Complete los campos obligatorios (*)', 'warning');
            return;
        }
        if (!acquireCfgLock('save_api', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...')) return;

        const data = {
            action: 'save_api',
            id_api: $('#id_api').val(),
            id_sede: $('#api_sede').val(),
            descripcion: ($('#api_descripcion').val() || '').trim(),
            telefono: ($('#api_telefono').val() || '').trim(),
            telefono_meta: ($('#api_telefono_meta').val() || '').trim(),
            meta_token: ($('#api_token_meta').val() || '').trim(),
            id_negocio: ($('#api_id_negocio').val() || '').trim(),
            estado: $('#api_estado').val(),
            limite_solicitudes: ($('#api_limite').val() || '').trim(),
            observaciones: ($('#api_observacion').val() || '').trim()
        };

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: data,
            success: function (res) {
                if (res && res.status === 'success') {
                    const inst = bootstrap.Modal.getInstance(document.getElementById('modalAPI'));
                    if (inst) inst.hide();
                    $('#api_token_meta').val('');
                    Swal.fire('Éxito', res.message, 'success');
                    loadAPIs();
                    loadSedes();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo guardar la API.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error de conexión al guardar la API.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('save_api', $btn, 'Guardar API');
            }
        });
    });

    // 2. Configuración GEMA AI Multi-Sede
    cargarSedesEnGemaSelect();

    $('#gema_id_sede').on('change', function () {
        const id_sede = $(this).val();
        if (id_sede) {
            loadGemaConfig(id_sede);
        }
    });

    $('#btnSaveGema').on('click', function () {
        const $btn = $(this);
        const id_sede = $('#gema_id_sede').val();
        const prompt = ($('#gema_prompt').val() || '').trim();
        const nombre = ($('#gema_nombre').val() || '').trim();
        const direccion = $('#gema_direccion').length ? ($('#gema_direccion').val() || '').trim() : '';
        const link_gps = ($('#gema_link_gps').val() || '').trim();
        const token = ($('#gema_token').val() || '').trim();
        const modelo = $('#gema_modelo_ia').val();
        const temperatura = $('#gema_temperatura').val();
        const estado = $('#gema_estado').is(':checked') ? 'ACTIVO' : 'INACTIVO';

        if (!id_sede) {
            Swal.fire('Error', 'Seleccione una Sede', 'warning');
            return;
        }
        if (!prompt) {
            Swal.fire('Error', 'El Prompt de Instrucciones es obligatorio', 'warning');
            return;
        }
        if (!token && !$('#gema_token').data('has-key')) {
            Swal.fire('Error', 'Ingrese una Gemini API Key válida para configurar esta sede.', 'warning');
            return;
        }

        if (!acquireCfgLock('save_gema', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...')) return;

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
            success: function (res) {
                if (res && res.status === 'success') {
                    const masked = res.gemini_api_key_masked || '••••••••';
                    resetSecretPasswordInput('gema_token', true, masked, 'AIzaSy...................');
                    Swal.fire('Guardado', res.message, 'success');
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo guardar.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error de red al guardar la configuración IA.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('save_gema', $btn, '<i class="fa-solid fa-floppy-disk me-2"></i> Guardar Configuración IA');
            }
        });
    });

    $('#btnTestGema').on('click', function () {
        const $btn = $(this);
        const id_sede = $('#gema_id_sede').val();
        const token = ($('#gema_token').val() || '').trim();
        const modelo = $('#gema_modelo_ia').val();
        const nombre = ($('#gema_nombre').val() || '').trim();
        const temperatura = $('#gema_temperatura').val();

        if (!token && !$('#gema_token').data('has-key')) {
            Swal.fire('Atención', 'Introduce una API Key para realizar la prueba.', 'warning');
            return;
        }

        if (!acquireCfgLock('test_gema', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Probando...')) return;

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'test_gema_connection',
                id_sede: id_sede,
                api_key: token,
                token: token,
                modelo_ia: modelo,
                nombre: nombre,
                temperatura: temperatura
            },
            success: function (res) {
                if (res && res.status === 'success') {
                    $('#testGemaContent').text(res.respuesta || '');
                    $('#testGemaLog').removeClass('d-none');
                    Swal.fire('¡Conexión Exitosa!', 'La API de IA respondió correctamente.', 'success');
                } else {
                    $('#testGemaLog').addClass('d-none');
                    Swal.fire('Error de Conexión', (res && res.message) ? res.message : 'Fallo en la prueba.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Fallo al comunicarse con el servidor.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('test_gema', $btn, '<i class="fa-solid fa-bolt me-2 text-warning"></i> Probar Conexión API');
            }
        });
    });

    // Delegación segura de botones dinámicos (Cero onclick inline con datos dinámicos)
    $(document).on('click', '.btn-sede-edit', function () {
        editarSede(parseInt($(this).attr('data-id') || '0', 10), $(this));
    });
    $(document).on('click', '.btn-sede-api', function () {
        configurarAPI(parseInt($(this).attr('data-id') || '0', 10), $(this));
    });
    $(document).on('click', '.btn-sede-tpls', function () {
        gestionarPlantillas(parseInt($(this).attr('data-id') || '0', 10));
    });
    $(document).on('click', '.btn-sede-token', function () {
        generarToken(parseInt($(this).attr('data-id') || '0', 10), $(this));
    });
    $(document).on('click', '.btn-sede-del', function () {
        borrarSede(parseInt($(this).attr('data-id') || '0', 10), $(this));
    });

    $(document).on('click', '.btn-api-edit', function () {
        editarAPI(parseInt($(this).attr('data-id') || '0', 10), $(this));
    });
    $(document).on('click', '.btn-api-test', function () {
        abrirPruebaAPI(parseInt($(this).attr('data-id') || '0', 10), $(this).attr('data-numero') || '');
    });
    $(document).on('click', '.btn-api-toggle', function () {
        toggleEstadoAPI(
            parseInt($(this).attr('data-id') || '0', 10),
            $(this).attr('data-estado') || '',
            $(this).attr('data-sede-nombre') || '',
            $(this)
        );
    });
    $(document).on('click', '.btn-api-del', function () {
        borrarAPI(parseInt($(this).attr('data-id') || '0', 10), $(this));
    });

    $(document).on('click', '.btn-delete-meta-tpl', function () {
        eliminarPlantilla($(this).attr('data-name') || '', $(this));
    });

    $(document).on('click', '.btn-resp-edit', function () {
        editarRespuestaRapida(parseInt($(this).attr('data-id') || '0', 10), $(this));
    });
    $(document).on('click', '.btn-resp-del', function () {
        borrarRespuestaRapida(parseInt($(this).attr('data-id') || '0', 10), $(this));
    });

    $(document).on('click', '.sla-rule-row', function () {
        const sid = parseInt($(this).attr('data-sede-id') || '0', 10);
        window.selectSlaRuleRow(sid);
    });
});

function resetSecretPasswordInput(fieldId, hasSecret, maskedValue, defaultPlaceholder) {
    const $input = $('#' + fieldId);
    const $eye = $('#' + fieldId + '_eye');
    if (!$input.length) return;
    $input.attr('type', 'password').val('');
    if ($eye.length) {
        $eye.removeClass('fa-eye-slash').addClass('fa-eye');
    }
    $input.data('has-key', !!hasSecret);
    if (hasSecret) {
        const maskLabel = maskedValue || '••••••••';
        $input.attr('placeholder', `${maskLabel} (configurada — deje vacío para mantener)`);
    } else {
        $input.attr('placeholder', defaultPlaceholder || '');
    }
}

function cargarSedesEnGemaSelect() {
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function (res) {
            if (res && res.status === 'success' && Array.isArray(res.data)) {
                const select = $('#gema_id_sede');
                select.empty();
                res.data.forEach(s => {
                    const sid = parseInt(s.id, 10) || 0;
                    select.append(`<option value="${sid}">${escapeHtmlConfig(s.nombre_sede)}</option>`);
                });
                if (res.data.length > 0) {
                    const firstId = parseInt(res.data[0].id, 10) || 0;
                    select.val(String(firstId));
                    if (!isMasterConfigUser && res.data.length === 1) {
                        select.prop('disabled', true);
                    }
                    loadGemaConfig(firstId);
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
        success: function (res) {
            if (res && res.status === 'success' && res.data) {
                const d = res.data;
                if (d.prompt !== undefined) $('#gema_prompt').val(d.prompt);
                if (d.nombre !== undefined) $('#gema_nombre').val(d.nombre);
                if ($('#gema_direccion').length && d.direccion_sede !== undefined) $('#gema_direccion').val(d.direccion_sede);
                if (d.link_gps !== undefined) $('#gema_link_gps').val(d.link_gps);

                const hasKey = !!(d.has_api_key || d.has_token);
                const masked = d.gemini_api_key_masked || d.gemini_api_key || d.token || '••••••••';
                resetSecretPasswordInput('gema_token', hasKey, masked, 'AIzaSy...................');

                if (d.modelo_ia !== undefined) $('#gema_modelo_ia').val(d.modelo_ia);
                if (d.temperatura !== undefined) $('#gema_temperatura').val(d.temperatura);
                $('#gema_estado').prop('checked', d.estado == 1 || d.estado_ia === 'ACTIVO');
                updateGemaLivePreview();
            }
        }
    });
}

$(document).on('input change', '#gema_nombre, #gema_link_gps, #gema_id_sede, #gema_estado', function () {
    updateGemaLivePreview();
});

function updateGemaLivePreview() {
    const nombre = ($('#gema_nombre').val() || '').trim() || 'Gema';
    const linkGps = ($('#gema_link_gps').val() || '').trim() || 'geo:10.48060,-66.90360';
    const sedeNombre = $('#gema_id_sede option:selected').text() || 'Sede STARFI';
    const isActivo = $('#gema_estado').is(':checked');

    $('#prev_bot_name').text(nombre);
    $('#preview_agent_name_display').text(nombre + ' (IA)');
    $('#preview_bubble_name').text(nombre + ' Bot');

    let displayGps = linkGps;
    if (displayGps.length > 55) {
        displayGps = displayGps.substring(0, 52) + '...';
    }
    $('#prev_bot_gps').html(`<span class="text-primary text-decoration-underline fw-bold" style="word-break: break-all; overflow-wrap: anywhere;">${escapeHtmlConfig(displayGps)}</span>`);

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

// ============================================================================
// Selector Interactivo 100% Offline LAN en HTML5 <canvas> Nativo (Venezuela)
// Cero dependencias externas, cero CDNs (CHK-LAN-01)
// ============================================================================
let selectedLat = 10.48060;
let selectedLng = -66.90360;
let mapCatalogPopulated = false;

const offlineCanvasState = {
    initialized: false,
    centerLat: 7.4,
    centerLng: -66.5,
    zoom: 1.0,
    isDraggingPin: false,
    isPanning: false,
    didMove: false,
    dragStartX: 0,
    dragStartY: 0,
    panStartLat: 7.4,
    panStartLng: -66.5,
    hoveredCityIdx: -1
};

const CATALOGO_CIUDADES_VE = [
    { nombre: 'Caracas (Centro)', estado: 'Distrito Capital', lat: 10.48060, lng: -66.90360 },
    { nombre: 'Altamira / Chacao', estado: 'Miranda', lat: 10.49605, lng: -66.85292 },
    { nombre: 'Las Mercedes', estado: 'Miranda', lat: 10.48012, lng: -66.86084 },
    { nombre: 'Sabana Grande', estado: 'Distrito Capital', lat: 10.49450, lng: -66.87780 },
    { nombre: 'Los Teques', estado: 'Miranda', lat: 10.34447, lng: -67.04325 },
    { nombre: 'Guarenas', estado: 'Miranda', lat: 10.47027, lng: -66.61667 },
    { nombre: 'Guatire', estado: 'Miranda', lat: 10.47620, lng: -66.54270 },
    { nombre: 'Charallave', estado: 'Miranda', lat: 10.24240, lng: -66.85720 },
    { nombre: 'La Guaira / Maiquetía', estado: 'La Guaira', lat: 10.60156, lng: -66.93293 },
    { nombre: 'Valencia', estado: 'Carabobo', lat: 10.16202, lng: -68.00765 },
    { nombre: 'Naguanagua', estado: 'Carabobo', lat: 10.26080, lng: -68.01080 },
    { nombre: 'San Diego', estado: 'Carabobo', lat: 10.25690, lng: -67.95360 },
    { nombre: 'Puerto Cabello', estado: 'Carabobo', lat: 10.47306, lng: -68.01250 },
    { nombre: 'Maracay', estado: 'Aragua', lat: 10.24694, lng: -67.59583 },
    { nombre: 'Turmero', estado: 'Aragua', lat: 10.22850, lng: -67.47420 },
    { nombre: 'Cagua', estado: 'Aragua', lat: 10.18630, lng: -67.45930 },
    { nombre: 'La Victoria', estado: 'Aragua', lat: 10.22780, lng: -67.33360 },
    { nombre: 'Barquisimeto', estado: 'Lara', lat: 10.06470, lng: -69.35703 },
    { nombre: 'Cabudare', estado: 'Lara', lat: 10.03310, lng: -69.26330 },
    { nombre: 'Maracaibo', estado: 'Zulia', lat: 10.66663, lng: -71.61245 },
    { nombre: 'San Francisco', estado: 'Zulia', lat: 10.55390, lng: -71.64440 },
    { nombre: 'Cabimas', estado: 'Zulia', lat: 10.38830, lng: -71.43990 },
    { nombre: 'Ciudad Ojeda', estado: 'Zulia', lat: 10.19630, lng: -71.30820 },
    { nombre: 'Puerto La Cruz', estado: 'Anzoátegui', lat: 10.21382, lng: -64.63279 },
    { nombre: 'Barcelona', estado: 'Anzoátegui', lat: 10.13625, lng: -64.68618 },
    { nombre: 'Lechería', estado: 'Anzoátegui', lat: 10.19160, lng: -64.68970 },
    { nombre: 'El Tigre', estado: 'Anzoátegui', lat: 8.88750, lng: -64.24540 },
    { nombre: 'Anaco', estado: 'Anzoátegui', lat: 9.42950, lng: -64.46940 },
    { nombre: 'Maturín', estado: 'Monagas', lat: 9.74569, lng: -63.18323 },
    { nombre: 'Ciudad Guayana (Puerto Ordaz)', estado: 'Bolívar', lat: 8.29690, lng: -62.73580 },
    { nombre: 'Ciudad Bolívar', estado: 'Bolívar', lat: 8.12923, lng: -63.54086 },
    { nombre: 'San Cristóbal', estado: 'Táchira', lat: 7.76694, lng: -72.22500 },
    { nombre: 'Mérida', estado: 'Mérida', lat: 8.58972, lng: -71.15611 },
    { nombre: 'Barinas', estado: 'Barinas', lat: 8.62261, lng: -70.20749 },
    { nombre: 'Punto Fijo', estado: 'Falcón', lat: 11.69152, lng: -70.19957 },
    { nombre: 'Coro', estado: 'Falcón', lat: 11.40450, lng: -69.67340 },
    { nombre: 'Porlamar (Isla de Margarita)', estado: 'Nueva Esparta', lat: 10.95796, lng: -63.84906 },
    { nombre: 'Cumaná', estado: 'Sucre', lat: 10.45397, lng: -64.18256 },
    { nombre: 'Carúpano', estado: 'Sucre', lat: 10.66780, lng: -63.25850 },
    { nombre: 'Acarigua / Araure', estado: 'Portuguesa', lat: 9.55451, lng: -69.19564 },
    { nombre: 'Guanare', estado: 'Portuguesa', lat: 9.04180, lng: -69.74210 },
    { nombre: 'Valera', estado: 'Trujillo', lat: 9.31778, lng: -70.60361 },
    { nombre: 'San Felipe', estado: 'Yaracuy', lat: 10.33990, lng: -68.74250 },
    { nombre: 'San Juan de los Morros', estado: 'Guárico', lat: 9.91150, lng: -67.35380 },
    { nombre: 'San Carlos', estado: 'Cojedes', lat: 9.66120, lng: -68.58270 },
    { nombre: 'San Fernando de Apure', estado: 'Apure', lat: 7.88780, lng: -67.47240 },
    { nombre: 'Tucupita', estado: 'Delta Amacuro', lat: 9.05806, lng: -62.05000 },
    { nombre: 'Puerto Ayacucho', estado: 'Amazonas', lat: 5.66049, lng: -67.58343 }
];

const CONTORNO_VENEZUELA = [
    [12.19, -71.32], [11.75, -70.22], [11.45, -69.65], [10.52, -68.02],
    [10.62, -66.93], [10.63, -66.15], [10.15, -64.68], [10.68, -63.25],
    [10.72, -61.88], [9.95, -61.85], [8.55, -60.02], [6.10, -61.35],
    [4.55, -61.10], [3.90, -62.80], [3.85, -64.60], [1.95, -66.50],
    [1.20, -66.88], [2.20, -67.15], [3.95, -67.72], [6.18, -67.48],
    [7.02, -70.72], [7.38, -72.05], [7.88, -72.48], [9.12, -72.78],
    [10.95, -72.42], [11.85, -71.35], [12.19, -71.32]
];

function normalizarTextoMapa(str) {
    return String(str || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
}

function poblarCatalogoMapaOffline() {
    if (mapCatalogPopulated) return;
    const $dl = $('#venezuelaCitiesDatalist');
    const $sel = $('#mapCityQuickSelect');
    $dl.empty();
    $sel.html('<option value="">Catálogo rápido de ciudades...</option>');
    CATALOGO_CIUDADES_VE.forEach((c, idx) => {
        const label = `${c.nombre}, ${c.estado}`;
        $dl.append(`<option value="${escapeHtmlConfig(label)}"></option>`);
        $sel.append(`<option value="${idx}">${escapeHtmlConfig(label)}</option>`);
    });
    mapCatalogPopulated = true;
}

function resolverReferenciaLocal(lat, lng) {
    let best = null;
    let bestDistKm = Infinity;
    const toRad = Math.PI / 180;
    CATALOGO_CIUDADES_VE.forEach(c => {
        const dLat = (c.lat - lat) * toRad;
        const dLng = (c.lng - lng) * toRad;
        const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                  Math.cos(lat * toRad) * Math.cos(c.lat * toRad) *
                  Math.sin(dLng / 2) * Math.sin(dLng / 2);
        const dist = 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        if (dist < bestDistKm) {
            bestDistKm = dist;
            best = c;
        }
    });
    if (!best) return 'Posición personalizada';
    if (bestDistKm <= 3.5) {
        return `${best.nombre}, ${best.estado}`;
    }
    return `Ref. cercana: ${best.nombre}, ${best.estado} (~${bestDistKm.toFixed(1)} km)`;
}

function extraerCoordenadasDeTexto(texto) {
    if (!texto) return null;
    const m = String(texto).match(/(-?\d{1,2}\.\d+)\s*[,;\s]\s*(-?\d{1,3}\.\d+)/);
    if (m && m[1] && m[2]) {
        const lat = parseFloat(m[1]);
        const lng = parseFloat(m[2]);
        if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) {
            return { lat, lng };
        }
    }
    return null;
}

function getCanvasViewportMetrics(canvas) {
    const w = canvas.clientWidth || 720;
    const h = canvas.clientHeight || 360;
    const spanLng = 15.0 / offlineCanvasState.zoom;
    const spanLat = spanLng * (h / Math.max(1, w));
    return {
        w,
        h,
        minLng: offlineCanvasState.centerLng - spanLng / 2,
        maxLng: offlineCanvasState.centerLng + spanLng / 2,
        minLat: offlineCanvasState.centerLat - spanLat / 2,
        maxLat: offlineCanvasState.centerLat + spanLat / 2,
        spanLng,
        spanLat
    };
}

function projectLatLngToCanvas(lat, lng, metrics) {
    const x = ((lng - metrics.minLng) / metrics.spanLng) * metrics.w;
    const y = ((metrics.maxLat - lat) / metrics.spanLat) * metrics.h;
    return { x, y };
}

function unprojectCanvasToLatLng(x, y, metrics) {
    const lng = metrics.minLng + (x / metrics.w) * metrics.spanLng;
    const lat = metrics.maxLat - (y / metrics.h) * metrics.spanLat;
    return {
        lat: Math.max(-90, Math.min(90, lat)),
        lng: Math.max(-180, Math.min(180, lng))
    };
}

function renderOfflineCanvasMap() {
    const canvas = document.getElementById('offlineMapCanvas');
    if (!canvas) return;
    const dpr = window.devicePixelRatio || 1;
    const cssW = canvas.clientWidth || 720;
    const cssH = canvas.clientHeight || 360;
    if (canvas.width !== Math.round(cssW * dpr) || canvas.height !== Math.round(cssH * dpr)) {
        canvas.width = Math.round(cssW * dpr);
        canvas.height = Math.round(cssH * dpr);
    }

    const ctx = canvas.getContext('2d');
    ctx.save();
    ctx.scale(dpr, dpr);

    const m = getCanvasViewportMetrics(canvas);

    // 1. Fondo oceánico/retícula LAN
    ctx.fillStyle = '#DBEAFE';
    ctx.fillRect(0, 0, m.w, m.h);

    const degStep = offlineCanvasState.zoom >= 4 ? 0.25 : (offlineCanvasState.zoom >= 2 ? 0.5 : 2.0);
    ctx.strokeStyle = 'rgba(100, 116, 139, 0.22)';
    ctx.lineWidth = 1;
    ctx.fillStyle = '#64748B';
    ctx.font = '10px monospace';

    const startLng = Math.floor(m.minLng / degStep) * degStep;
    for (let gLng = startLng; gLng <= m.maxLng; gLng += degStep) {
        const pt = projectLatLngToCanvas(m.centerLat, gLng, m);
        ctx.beginPath();
        ctx.moveTo(pt.x, 0);
        ctx.lineTo(pt.x, m.h);
        ctx.stroke();
        ctx.fillText(`${gLng.toFixed(degStep < 1 ? 2 : 0)}°`, pt.x + 3, m.h - 6);
    }

    const startLat = Math.floor(m.minLat / degStep) * degStep;
    for (let gLat = startLat; gLat <= m.maxLat; gLat += degStep) {
        const pt = projectLatLngToCanvas(gLat, m.centerLng, m);
        ctx.beginPath();
        ctx.moveTo(0, pt.y);
        ctx.lineTo(m.w, pt.y);
        ctx.stroke();
        ctx.fillText(`${gLat.toFixed(degStep < 1 ? 2 : 0)}°`, 6, pt.y - 3);
    }

    // 2. Silueta vectorial de Venezuela
    ctx.beginPath();
    CONTORNO_VENEZUELA.forEach((pair, idx) => {
        const pt = projectLatLngToCanvas(pair[0], pair[1], m);
        if (idx === 0) ctx.moveTo(pt.x, pt.y);
        else ctx.lineTo(pt.x, pt.y);
    });
    ctx.closePath();
    ctx.fillStyle = '#F8FAFC';
    ctx.fill();
    ctx.strokeStyle = '#0F172A';
    ctx.lineWidth = 2;
    ctx.stroke();

    // 3. Puntos de las 48 ciudades del catálogo offline
    CATALOGO_CIUDADES_VE.forEach((c, idx) => {
        const pt = projectLatLngToCanvas(c.lat, c.lng, m);
        if (pt.x < -20 || pt.x > m.w + 20 || pt.y < -20 || pt.y > m.h + 20) return;

        const isHovered = (idx === offlineCanvasState.hoveredCityIdx);
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, isHovered ? 6.5 : 4.5, 0, Math.PI * 2);
        ctx.fillStyle = isHovered ? '#EA580C' : '#FF7A33';
        ctx.fill();
        ctx.strokeStyle = '#FFFFFF';
        ctx.lineWidth = 1.5;
        ctx.stroke();

        if (isHovered || offlineCanvasState.zoom >= 2.3) {
            ctx.font = isHovered ? 'bold 11px sans-serif' : '10px sans-serif';
            ctx.fillStyle = '#1E293B';
            ctx.fillText(c.nombre, pt.x + 8, pt.y + 3);
        }
    });

    // 4. Pin seleccionado (con sombra y etiqueta)
    const pinPt = projectLatLngToCanvas(selectedLat, selectedLng, m);
    ctx.save();
    ctx.translate(pinPt.x, pinPt.y);

    // Sombra en base
    ctx.beginPath();
    ctx.ellipse(0, 2, 6, 3, 0, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(15, 23, 42, 0.28)';
    ctx.fill();

    // Cuerpo de gota del marcador GPS
    ctx.beginPath();
    ctx.moveTo(0, 0);
    ctx.bezierCurveTo(-11, -12, -13, -26, 0, -28);
    ctx.bezierCurveTo(13, -26, 11, -12, 0, 0);
    ctx.closePath();
    ctx.fillStyle = '#DC2626';
    ctx.fill();
    ctx.strokeStyle = '#FFFFFF';
    ctx.lineWidth = 2;
    ctx.stroke();

    ctx.beginPath();
    ctx.arc(0, -18, 4.2, 0, Math.PI * 2);
    ctx.fillStyle = '#FFFFFF';
    ctx.fill();
    ctx.restore();

    ctx.restore();
}

function initOfflineCanvasMapPicker() {
    const canvas = document.getElementById('offlineMapCanvas');
    if (!canvas) return;

    offlineCanvasState.centerLat = selectedLat;
    offlineCanvasState.centerLng = selectedLng;
    if (offlineCanvasState.zoom < 1.8) {
        offlineCanvasState.zoom = 2.2;
    }

    if (!offlineCanvasState.initialized) {
        offlineCanvasState.initialized = true;

        const getCanvasCoords = function (evt) {
            const rect = canvas.getBoundingClientRect();
            const clientX = (evt.touches && evt.touches[0]) ? evt.touches[0].clientX : evt.clientX;
            const clientY = (evt.touches && evt.touches[0]) ? evt.touches[0].clientY : evt.clientY;
            return { x: clientX - rect.left, y: clientY - rect.top };
        };

        canvas.addEventListener('mousedown', function (e) {
            const pos = getCanvasCoords(e);
            const m = getCanvasViewportMetrics(canvas);
            const pinPt = projectLatLngToCanvas(selectedLat, selectedLng, m);
            const distToPin = Math.hypot(pos.x - pinPt.x, pos.y - (pinPt.y - 14));

            offlineCanvasState.didMove = false;
            offlineCanvasState.dragStartX = pos.x;
            offlineCanvasState.dragStartY = pos.y;

            if (distToPin <= 18) {
                offlineCanvasState.isDraggingPin = true;
                canvas.style.cursor = 'grabbing';
            } else {
                offlineCanvasState.isPanning = true;
                offlineCanvasState.panStartLat = offlineCanvasState.centerLat;
                offlineCanvasState.panStartLng = offlineCanvasState.centerLng;
                canvas.style.cursor = 'grabbing';
            }
        });

        window.addEventListener('mousemove', function (e) {
            if (!document.getElementById('modalMapPicker')?.classList.contains('show')) return;
            const pos = getCanvasCoords(e);
            const m = getCanvasViewportMetrics(canvas);

            if (offlineCanvasState.isDraggingPin) {
                offlineCanvasState.didMove = true;
                const coords = unprojectCanvasToLatLng(pos.x, pos.y, m);
                updateSelectedCoords(coords.lat, coords.lng);
                return;
            }

            if (offlineCanvasState.isPanning) {
                const dx = pos.x - offlineCanvasState.dragStartX;
                const dy = pos.y - offlineCanvasState.dragStartY;
                if (Math.hypot(dx, dy) > 3) {
                    offlineCanvasState.didMove = true;
                }
                offlineCanvasState.centerLng = Math.max(-76, Math.min(-57, offlineCanvasState.panStartLng - (dx / m.w) * m.spanLng));
                offlineCanvasState.centerLat = Math.max(0, Math.min(14, offlineCanvasState.panStartLat + (dy / m.h) * m.spanLat));
                renderOfflineCanvasMap();
                return;
            }

            if (pos.x >= 0 && pos.x <= m.w && pos.y >= 0 && pos.y <= m.h) {
                let foundIdx = -1;
                for (let i = 0; i < CATALOGO_CIUDADES_VE.length; i++) {
                    const cPt = projectLatLngToCanvas(CATALOGO_CIUDADES_VE[i].lat, CATALOGO_CIUDADES_VE[i].lng, m);
                    if (Math.hypot(pos.x - cPt.x, pos.y - cPt.y) <= 8) {
                        foundIdx = i;
                        break;
                    }
                }
                if (foundIdx !== offlineCanvasState.hoveredCityIdx) {
                    offlineCanvasState.hoveredCityIdx = foundIdx;
                    canvas.style.cursor = foundIdx >= 0 ? 'pointer' : 'crosshair';
                    renderOfflineCanvasMap();
                }
            }
        });

        window.addEventListener('mouseup', function (e) {
            if (!offlineCanvasState.isDraggingPin && !offlineCanvasState.isPanning) return;
            const wasPanning = offlineCanvasState.isPanning;
            const moved = offlineCanvasState.didMove;
            offlineCanvasState.isDraggingPin = false;
            offlineCanvasState.isPanning = false;
            canvas.style.cursor = 'crosshair';

            if (wasPanning && !moved) {
                const pos = getCanvasCoords(e);
                const m = getCanvasViewportMetrics(canvas);
                if (pos.x >= 0 && pos.x <= m.w && pos.y >= 0 && pos.y <= m.h) {
                    if (offlineCanvasState.hoveredCityIdx >= 0) {
                        const c = CATALOGO_CIUDADES_VE[offlineCanvasState.hoveredCityIdx];
                        updateSelectedCoords(c.lat, c.lng, `${c.nombre}, ${c.estado}`);
                    } else {
                        const coords = unprojectCanvasToLatLng(pos.x, pos.y, m);
                        updateSelectedCoords(coords.lat, coords.lng);
                    }
                }
            }
        });

        canvas.addEventListener('wheel', function (e) {
            e.preventDefault();
            const factor = e.deltaY < 0 ? 1.22 : 0.82;
            ajustarZoomMapaOffline(factor);
        }, { passive: false });

        window.addEventListener('resize', function () {
            if (document.getElementById('modalMapPicker')?.classList.contains('show')) {
                renderOfflineCanvasMap();
            }
        });
    }

    updateSelectedCoords(selectedLat, selectedLng);
    renderOfflineCanvasMap();
}

function ajustarZoomMapaOffline(factor) {
    offlineCanvasState.zoom = Math.max(0.9, Math.min(12.0, offlineCanvasState.zoom * factor));
    renderOfflineCanvasMap();
}

function resetVistaMapaOffline() {
    offlineCanvasState.centerLat = 7.2;
    offlineCanvasState.centerLng = -66.4;
    offlineCanvasState.zoom = 1.0;
    renderOfflineCanvasMap();
}

function abrirModalMapaGPS() {
    poblarCatalogoMapaOffline();
    const existingVal = ($('#gema_link_gps').val() || '').trim();
    const parsed = extraerCoordenadasDeTexto(existingVal);
    if (parsed) {
        selectedLat = parsed.lat;
        selectedLng = parsed.lng;
    }

    const modalEl = document.getElementById('modalMapPicker');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    $('#modalMapPicker').off('shown.bs.modal').on('shown.bs.modal', function () {
        initOfflineCanvasMapPicker();
    });
    modal.show();
}

function updateSelectedCoords(lat, lng, customLabel) {
    selectedLat = Math.max(-90, Math.min(90, parseFloat(lat) || 0));
    selectedLng = Math.max(-180, Math.min(180, parseFloat(lng) || 0));
    $('#selectedCoordsText').text(`${selectedLat.toFixed(5)}, ${selectedLng.toFixed(5)}`);
    $('#mapLatInput').val(selectedLat.toFixed(5));
    $('#mapLngInput').val(selectedLng.toFixed(5));
    $('#reverseGeocodeText').text(customLabel || resolverReferenciaLocal(selectedLat, selectedLng));
    renderOfflineCanvasMap();
}

function actualizarDesdeInputsManuales() {
    const lat = parseFloat($('#mapLatInput').val());
    const lng = parseFloat($('#mapLngInput').val());
    if (isNaN(lat) || isNaN(lng) || lat < -90 || lat > 90 || lng < -180 || lng > 180) {
        return;
    }
    selectedLat = lat;
    selectedLng = lng;
    offlineCanvasState.centerLat = selectedLat;
    offlineCanvasState.centerLng = selectedLng;
    $('#selectedCoordsText').text(`${selectedLat.toFixed(5)}, ${selectedLng.toFixed(5)}`);
    $('#reverseGeocodeText').text(resolverReferenciaLocal(selectedLat, selectedLng));
    renderOfflineCanvasMap();
}

function seleccionarCiudadCatalogo(idxStr) {
    if (idxStr === '' || idxStr === null || idxStr === undefined) return;
    const idx = parseInt(idxStr, 10);
    const ciudad = CATALOGO_CIUDADES_VE[idx];
    if (!ciudad) return;
    offlineCanvasState.centerLat = ciudad.lat;
    offlineCanvasState.centerLng = ciudad.lng;
    offlineCanvasState.zoom = 4.2;
    updateSelectedCoords(ciudad.lat, ciudad.lng, `${ciudad.nombre}, ${ciudad.estado}`);
}

function buscarEnMapa() {
    const rawQuery = ($('#mapSearchInput').val() || '').trim();
    if (!rawQuery) return;

    const coordsDirectas = extraerCoordenadasDeTexto(rawQuery);
    if (coordsDirectas) {
        offlineCanvasState.centerLat = coordsDirectas.lat;
        offlineCanvasState.centerLng = coordsDirectas.lng;
        offlineCanvasState.zoom = 4.5;
        updateSelectedCoords(coordsDirectas.lat, coordsDirectas.lng);
        return;
    }

    const qNorm = normalizarTextoMapa(rawQuery);
    const match = CATALOGO_CIUDADES_VE.find(c => {
        const full = normalizarTextoMapa(`${c.nombre} ${c.estado}`);
        const cityOnly = normalizarTextoMapa(c.nombre);
        return full.indexOf(qNorm) !== -1 || qNorm.indexOf(cityOnly) !== -1;
    });

    if (match) {
        offlineCanvasState.centerLat = match.lat;
        offlineCanvasState.centerLng = match.lng;
        offlineCanvasState.zoom = 4.2;
        updateSelectedCoords(match.lat, match.lng, `${match.nombre}, ${match.estado}`);
    } else {
        Swal.fire('Búsqueda Local', 'No se encontró esa localidad en el catálogo offline. Puedes seleccionar una ciudad de la lista o ingresar Latitud y Longitud manualmente.', 'info');
    }
}

function confirmarUbicacionMapa() {
    const geoUri = `geo:${selectedLat.toFixed(5)},${selectedLng.toFixed(5)}`;
    $('#gema_link_gps').val(geoUri).trigger('change');

    const modalEl = document.getElementById('modalMapPicker');
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    Swal.fire({
        icon: 'success',
        title: 'Ubicación GPS Seleccionada',
        text: `Se han fijado las coordenadas locales: ${geoUri}`,
        timer: 2000,
        showConfirmButton: false
    });
}

// ============================================================================
// GESTIÓN DE SEDES
// ============================================================================
function loadSedes() {
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_sedes' },
        success: function (res) {
            if (res && res.status === 'success') {
                cachedSedesList = Array.isArray(res.data) ? res.data : [];
                renderSedesCards(cachedSedesList);
                populateApiSedeFilter(cachedSedesList);
            }
        }
    });
}

function populateApiSedeFilter(sedes) {
    const $sel = $('#filterApiSedeSelect');
    if (!$sel.length) return;
    const cur = $sel.val() || '';
    $sel.html('<option value="">Todas las sedes</option>');
    (sedes || []).forEach(s => {
        const sid = parseInt(s.id, 10) || 0;
        $sel.append(`<option value="${sid}">${escapeHtmlConfig(s.nombre_sede)}</option>`);
    });
    if (cur) $sel.val(cur);
}

function renderSedesCards(sedes) {
    const container = $('#sedesCardContainer');
    container.empty();

    const q = ($('#searchSede').val() || '').trim().toLowerCase();
    const estFilter = ($('#filterEstadoSede').val() || '').trim();
    const apiFilter = ($('#filterApiSede').val() || '').trim();

    const filtered = (sedes || []).filter(s => {
        if (estFilter && String(s.estado || '') !== estFilter) return false;
        if (apiFilter === 'CON_API' && !s.tiene_api) return false;
        if (apiFilter === 'SIN_API' && s.tiene_api) return false;
        if (q) {
            const hay = `${s.nombre_sede || ''} ${s.rif || ''} ${s.direccion || ''} ${s.ciudad || ''}`.toLowerCase();
            if (hay.indexOf(q) === -1) return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        container.append('<div class="col-12 text-center text-muted p-4">No hay sedes registradas para los filtros seleccionados.</div>');
        return;
    }

    filtered.forEach(s => {
        const sid = parseInt(s.id, 10) || 0;
        const estadoBadge = s.estado === 'ACTIVO'
            ? '<span class="badge bg-success">Activo</span>'
            : '<span class="badge bg-secondary">Inactivo</span>';

        const apiBadge = s.tiene_api
            ? '<span class="badge bg-success text-white"><i class="fa-solid fa-check me-1"></i>Con API</span>'
            : '<span class="badge bg-warning text-dark"><i class="fa-solid fa-triangle-exclamation me-1"></i>Sin API</span>';

        const tokenBadge = s.has_token
            ? `<div class="mt-2 small text-muted"><i class="fa-solid fa-key text-warning me-1"></i>Token POS/ERP: <code class="text-dark">${escapeHtmlConfig(s.api_token_masked || '••••••••')}</code></div>`
            : '<div class="mt-2 small text-muted"><i class="fa-solid fa-key text-secondary me-1"></i>Token POS/ERP: <span class="fst-italic">Sin generar</span></div>';

        const masterBtns = isMasterConfigUser
            ? `
                <button type="button" class="btn btn-link text-success text-decoration-none flex-fill border-end rounded-0 py-2 action-btn btn-sede-api" data-id="${sid}" title="WhatsApp API"><i class="fa-brands fa-whatsapp"></i></button>
                <button type="button" class="btn btn-link text-info text-decoration-none flex-fill border-end rounded-0 py-2 action-btn btn-sede-tpls" data-id="${sid}" title="Plantillas Meta"><i class="fa-solid fa-layer-group"></i></button>
                <button type="button" class="btn btn-link text-warning text-decoration-none flex-fill border-end rounded-0 py-2 action-btn btn-sede-token" data-id="${sid}" title="Generar Token Externo"><i class="fa-solid fa-key"></i></button>
                <button type="button" class="btn btn-link text-danger text-decoration-none flex-fill rounded-0 py-2 action-btn btn-sede-del" data-id="${sid}" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
              `
            : `
                <button type="button" class="btn btn-link text-info text-decoration-none flex-fill rounded-0 py-2 action-btn btn-sede-tpls" data-id="${sid}" title="Plantillas Meta"><i class="fa-solid fa-layer-group"></i></button>
              `;

        const card = `
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0" style="border-radius: 10px; overflow: hidden;">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-3 pb-0">
                    <h6 class="mb-0 fw-bold text-primary"><i class="fa-solid fa-building me-2"></i>${escapeHtmlConfig(s.nombre_sede)}</h6>
                    ${estadoBadge}
                </div>
                <div class="card-body">
                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-regular fa-id-card me-2" style="width: 16px; text-align: center;"></i><strong class="text-dark">RIF:</strong> ${escapeHtmlConfig(s.rif || 'N/A')}</div>
                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-phone me-2" style="width: 16px; text-align: center;"></i><strong class="text-dark">Teléfono:</strong> ${escapeHtmlConfig(s.telefono || 'N/A')}</div>
                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-regular fa-envelope me-2" style="width: 16px; text-align: center;"></i><strong class="text-dark">Email:</strong> ${escapeHtmlConfig(s.email || 'N/A')}</div>
                    <div class="mb-3 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-location-dot me-2" style="width: 16px; text-align: center;"></i><strong class="text-dark">Ciudad:</strong> ${escapeHtmlConfig(s.ciudad || 'N/A')}</div>

                    <div class="d-flex gap-2 mb-2 flex-wrap">
                        <span class="badge bg-info text-white">${escapeHtmlConfig(s.tipo_sede || 'SUCURSAL')}</span>
                        ${apiBadge}
                    </div>
                    ${tokenBadge}
                </div>
                <div class="card-footer bg-white border-top-0 p-0">
                    <div class="d-flex border-top">
                        <button type="button" class="btn btn-link text-primary text-decoration-none flex-fill border-end rounded-0 py-2 action-btn btn-sede-edit" data-id="${sid}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                        ${masterBtns}
                    </div>
                </div>
            </div>
        </div>`;
        container.append(card);
    });
}

function editarSede(id, $btn) {
    if (!acquireCfgLock('edit_sede_' + id, $btn)) return;
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_sede', id: id },
        success: function (res) {
            if (res && res.status === 'success' && res.data) {
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
                const modalEl = document.getElementById('modalSede');
                if (modalEl) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                }
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo cargar la sede.', 'error');
            }
        },
        complete: function () {
            releaseCfgLock('edit_sede_' + id, $btn);
        }
    });
}

function borrarSede(id, $btn) {
    if (cfgInFlight.has('del_sede_' + id)) return;
    Swal.fire({
        title: '¿Estás seguro?',
        text: '¡No podrás revertir esto! Se eliminará la sede.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, borrar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('del_sede_' + id, $btn)) return;
        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'delete_sede', id: id },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Eliminado', res.message, 'success');
                    loadSedes();
                    loadAPIs();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo eliminar.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'No se pudo eliminar la sede.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('del_sede_' + id, $btn);
            }
        });
    });
}

function generarToken(id_sede, $btn) {
    if (cfgInFlight.has('gen_tok_' + id_sede)) return;
    Swal.fire({
        title: '¿Generar nuevo Token?',
        text: 'Esto creará un token único de 64 caracteres para integraciones externas con esta Sede. Solo se mostrará una vez y el anterior quedará invalidado.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#E85B14',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, generar'
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('gen_tok_' + id_sede, $btn)) return;
        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'generate_api_token', id_sede: id_sede },
            success: function (res) {
                if (res && res.status === 'success' && res.token) {
                    const safeToken = escapeHtmlConfig(res.token);
                    loadSedes();
                    Swal.fire({
                        title: 'Token Generado (Copia Única)',
                        html: `<p class="small text-muted mb-2">Copia y guarda este token ahora en el sistema POS/ERP. Por seguridad no volverá a mostrarse en texto plano:</p>
                               <div class="p-2 rounded border bg-light font-monospace fw-bold text-dark" style="word-break: break-all;">${safeToken}</div>`,
                        icon: 'success',
                        confirmButtonText: 'Copiar y Cerrar',
                        confirmButtonColor: '#E85B14'
                    }).then(() => {
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(String(res.token)).catch(() => {});
                        }
                    });
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'Error al generar el token.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error al generar el token.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('gen_tok_' + id_sede, $btn);
            }
        });
    });
}

// ============================================================================
// GESTIÓN DE PLANTILLAS META
// ============================================================================
function gestionarPlantillas(id_sede) {
    $('#plantillas_id_sede').val(id_sede);
    mostrarListaPlantillas();

    const modalEl = document.getElementById('modalPlantillasMeta');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
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

function renderMetaTemplatesRows(tbody, list) {
    tbody.empty();
    if (!Array.isArray(list) || list.length === 0) {
        tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted">No hay plantillas registradas en Meta.</td></tr>');
        return;
    }

    list.forEach(t => {
        const rawStatus = String(t.status || '');
        let badgeColor = 'bg-secondary';
        if (rawStatus === 'APPROVED') badgeColor = 'bg-success';
        else if (rawStatus === 'REJECTED') badgeColor = 'bg-danger';
        else if (rawStatus === 'PENDING') badgeColor = 'bg-warning text-dark';

        const safeName = escapeHtmlConfig(t.name);
        const safeCat = escapeHtmlConfig(t.category);
        const safeLang = escapeHtmlConfig(t.language);
        const safeStatus = escapeHtmlConfig(rawStatus);

        tbody.append(`
            <tr>
                <td class="fw-bold text-dark">${safeName}</td>
                <td><span class="badge bg-light text-dark border">${safeCat}</span></td>
                <td>${safeLang}</td>
                <td><span class="badge ${badgeColor}">${safeStatus}</span></td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger btn-delete-meta-tpl" data-name="${safeName}" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
        `);
    });
}

function cargarPlantillasDeMeta() {
    const id_sede = $('#plantillas_id_sede').val();
    const tbody = $('#tablaPlantillasMeta tbody');
    tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando plantillas desde Meta...</td></tr>');

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_meta_templates', id_sede: id_sede },
        success: function (res) {
            if (res && res.status === 'success') {
                renderMetaTemplatesRows(tbody, res.data || []);
            } else {
                const msg = escapeHtmlConfig((res && res.message) ? res.message : 'Error al consultar plantillas.');
                tbody.html(`<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>${msg}</td></tr>`);
            }
        },
        error: function () {
            tbody.html('<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Error de conexión al cargar plantillas.</td></tr>');
        }
    });
}

function sincronizarPlantillasMeta() {
    const $btn = $('#btnSyncPlantillasMeta');
    if (!acquireCfgLock('sync_meta_tpls', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Sincronizando...')) return;

    const id_sede = $('#plantillas_id_sede').val();
    const tbody = $('#tablaPlantillasMeta tbody');
    tbody.html('<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Sincronizando con Meta...</td></tr>');

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'sync_meta_templates', id_sede: id_sede },
        success: function (res) {
            if (res && res.status === 'success') {
                renderMetaTemplatesRows(tbody, res.data || []);
            } else {
                const msg = escapeHtmlConfig((res && res.message) ? res.message : 'Error al sincronizar plantillas.');
                tbody.html(`<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>${msg}</td></tr>`);
            }
        },
        error: function () {
            tbody.html('<tr><td colspan="5" class="text-center py-4 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Error de conexión al sincronizar plantillas.</td></tr>');
        },
        complete: function () {
            releaseCfgLock('sync_meta_tpls', $btn, '<i class="fa-solid fa-rotate me-1"></i> Sincronizar Plantillas');
        }
    });
}

function guardarNuevaPlantilla() {
    const $btn = $('#btnGuardarNuevaPlantilla');
    const id_sede = $('#plantillas_id_sede').val();
    const name = ($('#new_template_name').val() || '').trim();
    const category = $('#new_template_category').val();
    const lang = $('#new_template_lang').val();
    const body = ($('#new_template_body').val() || '').trim();

    if (!name || !body) {
        Swal.fire('Atención', 'El nombre y el cuerpo son obligatorios', 'warning');
        return;
    }
    if (!acquireCfgLock('create_meta_tpl', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Enviando...')) return;

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
        success: function (res) {
            if (res && res.status === 'success') {
                Swal.fire('¡Éxito!', 'Plantilla creada y enviada a revisión.', 'success');
                mostrarListaPlantillas();
                cargarPlantillasDeMeta();
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo crear la plantilla.', 'error');
            }
        },
        error: function () {
            Swal.fire('Error', 'Fallo de conexión al crear la plantilla.', 'error');
        },
        complete: function () {
            releaseCfgLock('create_meta_tpl', $btn, 'Enviar a Meta');
        }
    });
}

function crearPlantillaCSAT() {
    const $btn = $('#btnAutoCrearCSAT');
    if (cfgInFlight.has('create_csat_tpl')) return;
    const id_sede = $('#plantillas_id_sede').val();

    Swal.fire({
        title: '¿Crear Plantilla CSAT?',
        text: "Se enviará automáticamente a Meta una plantilla estandarizada llamada 'starfi_csat_survey' donde se le pedirá al cliente calificar la atención del 1 al 5.",
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#E85B14',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, crear'
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('create_csat_tpl', $btn)) return;
        Swal.fire({ title: 'Creando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'create_csat_template', id_sede: id_sede },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Plantilla Creada', 'La plantilla CSAT ha sido enviada a revisión.', 'success');
                    cargarPlantillasDeMeta();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'Error al crear.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de conexión.', 'error');
            },
            complete: function () {
                releaseCfgLock('create_csat_tpl', $btn);
            }
        });
    });
}

function crearPlantillaNotificacionInterna() {
    const $btn = $('#btnAutoCrearNotifInterna');
    if (cfgInFlight.has('create_internal_tpl')) return;
    const id_sede = $('#plantillas_id_sede').val();

    Swal.fire({
        title: '¿Crear Plantilla Notificación Interna?',
        text: "Se enviará automáticamente a Meta una plantilla estandarizada de tipo UTILITY llamada 'starfi_notificacion_interna'.",
        icon: 'info',
        showCancelButton: true,
        confirmButtonColor: '#0dcaf0',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, crear'
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('create_internal_tpl', $btn)) return;
        Swal.fire({ title: 'Creando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'create_internal_notification_template', id_sede: id_sede },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Plantilla Creada', 'La plantilla starfi_notificacion_interna ha sido enviada a revisión a Meta.', 'success');
                    sincronizarPlantillasMeta();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'Error al crear.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de conexión.', 'error');
            },
            complete: function () {
                releaseCfgLock('create_internal_tpl', $btn);
            }
        });
    });
}

function crearPlantillaDifusionDirecta() {
    const $btn = $('#btnAutoCrearDifusion');
    if (cfgInFlight.has('create_broadcast_tpl')) return;
    const id_sede = $('#plantillas_id_sede').val();

    Swal.fire({
        title: '¿Auto-Crear Plantilla de Difusión (starfi_difusion_directa)?',
        text: "Se enviará a Meta la plantilla oficial 'starfi_difusion_directa' con 3 variables ({{1}}, {{2}}, {{3}}).",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#E85B14',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, crear en Meta'
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('create_broadcast_tpl', $btn)) return;
        Swal.fire({ title: 'Enviando a Meta...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'create_broadcast_template', id_sede: id_sede },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('¡Plantilla Creada!', 'La plantilla starfi_difusion_directa fue registrada en Meta exitosamente.', 'success');
                    sincronizarPlantillasMeta();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'Error al crear.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de conexión.', 'error');
            },
            complete: function () {
                releaseCfgLock('create_broadcast_tpl', $btn);
            }
        });
    });
}

function eliminarPlantilla(name, $btn) {
    if (!name || cfgInFlight.has('del_tpl_' + name)) return;
    Swal.fire({
        title: '¿Eliminar Plantilla?',
        text: `Se eliminará la plantilla '${name}' de Meta de forma permanente.`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, eliminar'
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('del_tpl_' + name, $btn)) return;
        const id_sede = $('#plantillas_id_sede').val();

        Swal.fire({ title: 'Eliminando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'delete_meta_template', id_sede: id_sede, name: name },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Eliminada', 'Plantilla eliminada de Meta.', 'success');
                    cargarPlantillasDeMeta();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'Error al eliminar.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de conexión al eliminar.', 'error');
            },
            complete: function () {
                releaseCfgLock('del_tpl_' + name, $btn);
            }
        });
    });
}

// ============================================================================
// GESTIÓN DE APIS WHATSAPP
// ============================================================================
function configurarAPI(id_sede, $btn) {
    if (!acquireCfgLock('cfg_api_' + id_sede, $btn)) return;
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function (resSedes) {
            if (resSedes && resSedes.status === 'success') {
                const select = $('#api_sede');
                select.html('<option value="">Seleccione una sede...</option>');
                (resSedes.data || []).forEach(s => {
                    select.append(`<option value="${parseInt(s.id, 10) || 0}">${escapeHtmlConfig(s.nombre_sede)}</option>`);
                });

                $.ajax({
                    url: 'back_configuracion.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'get_api_by_sede', id_sede: id_sede },
                    success: function (res) {
                        if ($('#formAPI').length) $('#formAPI')[0].reset();
                        $('#api_sede').val(String(id_sede));

                        if (res && res.status === 'success' && res.data) {
                            const a = res.data;
                            $('#id_api').val(a.id);
                            $('#api_descripcion').val(a.descripcion);
                            $('#api_telefono').val(a.numero_telefono);
                            $('#api_telefono_meta').val(a.meta_app_id);
                            resetSecretPasswordInput(
                                'api_token_meta',
                                !!a.has_token,
                                a.meta_token_masked || a.meta_token || '••••••••',
                                'Deje vacío para usar Token Global (.env) o ingrese EAA...'
                            );
                            $('#api_id_negocio').val(a.id_negocio);
                            $('#api_estado').val(a.estado);
                            $('#api_limite').val(a.limite_solicitudes);
                            $('#api_observacion').val(a.observaciones);
                            $('#modalAPI .modal-title').html('<i class="fa-brands fa-whatsapp me-2"></i>Editar API WhatsApp');
                        } else {
                            $('#id_api').val('');
                            resetSecretPasswordInput(
                                'api_token_meta',
                                false,
                                '',
                                'Deje vacío para usar Token Global (.env) o ingrese EAA...'
                            );
                            $('#modalAPI .modal-title').html('<i class="fa-brands fa-whatsapp me-2"></i>Nueva API WhatsApp');
                        }

                        const modalEl = document.getElementById('modalAPI');
                        if (modalEl) {
                            bootstrap.Modal.getOrCreateInstance(modalEl).show();
                        }
                    },
                    complete: function () {
                        releaseCfgLock('cfg_api_' + id_sede, $btn);
                    }
                });
            } else {
                releaseCfgLock('cfg_api_' + id_sede, $btn);
            }
        },
        error: function () {
            releaseCfgLock('cfg_api_' + id_sede, $btn);
        }
    });
}

function loadAPIs() {
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_apis' },
        success: function (res) {
            if (res && res.status === 'success') {
                cachedApisList = Array.isArray(res.data) ? res.data : [];
                renderApisCards(cachedApisList);
            }
        }
    });
}

function renderApisCards(apis) {
    const container = $('#apisCardContainer');
    container.empty();

    const allApis = apis || [];
    let activas = 0;
    let inactivas = 0;
    const sedesConAPI = new Set();
    allApis.forEach(a => {
        if (a.estado === 'ACTIVO') activas++;
        else inactivas++;
        if (a.id_sede) sedesConAPI.add(a.id_sede);
    });
    $('#statApiTotal').text(allApis.length);
    $('#statApiActivas').text(activas);
    $('#statApiInactivas').text(inactivas);
    $('#statApiSedes').text(sedesConAPI.size);

    const sedeFilter = ($('#filterApiSedeSelect').val() || '').trim();
    const estFilter = ($('#filterApiEstado').val() || '').trim();
    const q = ($('#searchApi').val() || '').trim().toLowerCase();

    const filtered = allApis.filter(a => {
        if (sedeFilter && String(a.id_sede || '') !== sedeFilter) return false;
        if (estFilter && String(a.estado || '') !== estFilter) return false;
        if (q) {
            const hay = `${a.descripcion || ''} ${a.numero_telefono || ''} ${a.nombre_sede || ''} ${a.id_negocio || ''}`.toLowerCase();
            if (hay.indexOf(q) === -1) return false;
        }
        return true;
    });

    if (filtered.length === 0) {
        container.append('<div class="col-12 text-center text-muted p-4">No hay APIs configuradas.</div>');
        return;
    }

    filtered.forEach(a => {
        const aid = parseInt(a.id, 10) || 0;
        const safeEstado = escapeHtmlConfig(a.estado || 'INACTIVO');
        const safeNombreSede = escapeHtmlConfig(a.nombre_sede || a.descripcion || 'Sin sede asignada');
        const safeNumero = escapeHtmlConfig(a.numero_telefono || 'N/A');
        const safeMasked = a.has_token ? escapeHtmlConfig(a.meta_token_masked || '••••••••') : 'No configurado';

        const badge = a.estado === 'ACTIVO'
            ? '<span class="badge bg-success" style="cursor: pointer;" title="Estado de la línea"><i class="fa-solid fa-circle-check me-1"></i>Activo</span>'
            : '<span class="badge bg-secondary" style="cursor: pointer;" title="Estado de la línea"><i class="fa-solid fa-circle-xmark me-1"></i>Inactivo</span>';

        const actionFooter = isMasterConfigUser
            ? `
                <button type="button" class="btn btn-link text-primary text-decoration-none flex-fill border-end rounded-0 py-2 action-btn btn-api-edit" data-id="${aid}" title="Editar API"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="btn btn-link text-info text-decoration-none flex-fill border-end rounded-0 py-2 action-btn btn-api-test" data-id="${aid}" data-numero="${safeNumero}" title="Probar API"><i class="fa-solid fa-bolt"></i></button>
                <button type="button" class="btn btn-link ${a.estado === 'ACTIVO' ? 'text-warning' : 'text-success'} text-decoration-none flex-fill border-end rounded-0 py-2 action-btn btn-api-toggle" data-id="${aid}" data-estado="${safeEstado}" data-sede-nombre="${safeNombreSede}" title="${a.estado === 'ACTIVO' ? 'Desactivar Sede' : 'Activar Sede'}"><i class="fa-solid fa-power-off"></i></button>
                <button type="button" class="btn btn-link text-danger text-decoration-none flex-fill rounded-0 py-2 action-btn btn-api-del" data-id="${aid}" title="Eliminar API"><i class="fa-solid fa-trash"></i></button>
              `
            : `
                <button type="button" class="btn btn-link text-info text-decoration-none flex-fill rounded-0 py-2 action-btn btn-api-test" data-id="${aid}" data-numero="${safeNumero}" title="Probar API"><i class="fa-solid fa-bolt"></i></button>
              `;

        const headerToggleWrap = isMasterConfigUser
            ? `<div class="btn-api-toggle" data-id="${aid}" data-estado="${safeEstado}" data-sede-nombre="${safeNombreSede}">${badge}</div>`
            : `<div>${badge}</div>`;

        const card = `
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0" style="border-radius: 10px; overflow: hidden;">
                <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-3 pb-0">
                    <h6 class="mb-0 fw-bold text-muted"><i class="fa-solid fa-circle ${a.estado === 'ACTIVO' ? 'text-success' : 'text-secondary'} me-2" style="font-size: 0.6rem;"></i>${escapeHtmlConfig(a.descripcion || 'Sin descripción')}</h6>
                    ${headerToggleWrap}
                </div>
                <div class="card-body">
                    <div class="mb-3 text-muted" style="font-size: 0.85rem;"><i class="fa-solid fa-building me-2"></i>${escapeHtmlConfig(a.nombre_sede || 'Sin sede asignada')}</div>
                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-phone me-2" style="width: 16px;"></i><strong class="text-dark">Teléfono:</strong><br>${safeNumero}</div>
                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-hashtag me-2" style="width: 16px;"></i><strong class="text-dark">ID Meta:</strong><br>${escapeHtmlConfig(a.meta_app_id || 'N/A')}</div>
                    <div class="mb-1 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-key me-2" style="width: 16px;"></i><strong class="text-dark">Token Meta:</strong><br><span style="font-family: monospace; font-size: 0.8rem;">${safeMasked}</span></div>
                    <div class="mb-3 text-muted" style="font-size: 0.9rem;"><i class="fa-solid fa-briefcase me-2" style="width: 16px;"></i><strong class="text-dark">ID de WABA:</strong><br>${escapeHtmlConfig(a.id_negocio || 'N/A')}</div>
                    <small class="text-muted"><i class="fa-solid fa-check-double me-1 text-success"></i>Línea verificada</small>
                </div>
                <div class="card-footer bg-white border-top-0 p-0">
                    <div class="d-flex border-top">
                        ${actionFooter}
                    </div>
                </div>
            </div>
        </div>`;
        container.append(card);
    });
}

function editarAPI(id_api, $btn) {
    if (!acquireCfgLock('edit_api_' + id_api, $btn)) return;
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function (resSedes) {
            if (resSedes && resSedes.status === 'success') {
                const select = $('#api_sede');
                select.html('<option value="">Seleccione una sede...</option>');
                (resSedes.data || []).forEach(s => {
                    select.append(`<option value="${parseInt(s.id, 10) || 0}">${escapeHtmlConfig(s.nombre_sede)}</option>`);
                });
            }
            $.ajax({
                url: 'back_configuracion.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'get_api_by_id', id_api: id_api },
                success: function (res) {
                    if (res && res.status === 'success' && res.data) {
                        const a = res.data;
                        $('#id_api').val(a.id);
                        $('#api_sede').val(a.id_sede);
                        $('#api_descripcion').val(a.descripcion);
                        $('#api_telefono').val(a.numero_telefono);
                        $('#api_telefono_meta').val(a.meta_app_id);
                        resetSecretPasswordInput(
                            'api_token_meta',
                            !!a.has_token,
                            a.meta_token_masked || a.meta_token || '••••••••',
                            'Deje vacío para usar Token Global (.env) o ingrese EAA...'
                        );
                        $('#api_id_negocio').val(a.id_negocio);
                        $('#api_estado').val(a.estado);
                        $('#api_limite').val(a.limite_solicitudes);
                        $('#api_observacion').val(a.observaciones);
                        $('#modalAPI .modal-title').html('<i class="fa-brands fa-whatsapp me-2"></i>Editar API WhatsApp');

                        const modalEl = document.getElementById('modalAPI');
                        if (modalEl) {
                            bootstrap.Modal.getOrCreateInstance(modalEl).show();
                        }
                    } else {
                        Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo cargar la API.', 'error');
                    }
                },
                complete: function () {
                    releaseCfgLock('edit_api_' + id_api, $btn);
                }
            });
        },
        error: function () {
            releaseCfgLock('edit_api_' + id_api, $btn);
        }
    });
}

function borrarAPI(id, $btn) {
    if (cfgInFlight.has('del_api_' + id)) return;
    Swal.fire({
        title: '¿Estás seguro?',
        text: '¡No podrás revertir esto! Se eliminará la configuración de la API.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, borrar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('del_api_' + id, $btn)) return;
        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'delete_api', id: id },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Eliminado', res.message, 'success');
                    loadAPIs();
                    loadSedes();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'Error al eliminar la API.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error al eliminar la API.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('del_api_' + id, $btn);
            }
        });
    });
}

function toggleEstadoAPI(id_api, estado_actual, nombre_sede, $btn) {
    if (cfgInFlight.has('toggle_api_' + id_api)) return;
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
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('toggle_api_' + id_api, $btn)) return;
        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'toggle_api_estado', id_api: id_api, nuevo_estado: nuevoEstado },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Estado actualizado', res.message, 'success');
                    loadAPIs();
                    loadSedes();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo cambiar el estado.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'No se pudo procesar la solicitud.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('toggle_api_' + id_api, $btn);
            }
        });
    });
}

function abrirPruebaAPI(id) {
    $('#id_api_test').val(id);
    $('#resultadoTest').html('');
    const modalEl = document.getElementById('modalProbarAPI');
    if (modalEl) {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function ejecutarPruebaAPI() {
    const $btn = $('#btnEjecutarPruebaAPI');
    const id_api = $('#id_api_test').val();
    const numero = ($('#telefono_test').val() || '').trim();
    const mensaje = ($('#mensaje_test').val() || '').trim();

    if (!numero || !mensaje) {
        Swal.fire('Atención', 'Ingrese un número y un mensaje', 'warning');
        return;
    }
    if (!acquireCfgLock('test_api', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Enviando...')) return;

    $('#resultadoTest').html('<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Enviando prueba...</div>');

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'test_api',
            id_api: id_api,
            numero: numero,
            mensaje: mensaje
        },
        success: function (res) {
            if (res && res.status === 'success') {
                $('#resultadoTest').html(`<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> ${escapeHtmlConfig(res.message)}</div>`);
            } else {
                $('#resultadoTest').html(`<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${escapeHtmlConfig((res && res.message) ? res.message : 'Fallo desconocido')}</div>`);
            }
        },
        error: function () {
            $('#resultadoTest').html('<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> Error de conexión con el servidor.</div>');
        },
        complete: function () {
            releaseCfgLock('test_api', $btn, '<i class="fa-solid fa-paper-plane me-1"></i> Enviar Prueba');
        }
    });
}

// ============================================================================
// DIAGNÓSTICO Y PRUEBAS
// ============================================================================
function ejecutarDiagnostico() {
    const $btn = $('#btnRunDiagnostico');
    if (!acquireCfgLock('run_diag', $btn, '<i class="fa-solid fa-spinner fa-spin me-2"></i> Ejecutando...')) return;

    const resDiv = $('#resultadoDiagnostico');
    resDiv.html('<div class="alert alert-info py-2"><i class="fa-solid fa-spinner fa-spin me-2"></i> Ejecutando auto-diagnóstico...</div>');

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'run_diagnostico' },
        success: function (res) {
            if (res && res.status === 'success' && res.data) {
                const data = res.data;
                let html = '<div class="card bg-light border-0 p-3 mt-2" style="border-radius: 8px;">';

                const dbStatus = data.database.status === 'ok'
                    ? '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> Conectado</span>'
                    : '<span class="text-danger"><i class="fa-solid fa-circle-xmark me-1"></i> Error</span>';
                html += `<div class="mb-2"><strong>Base de Datos:</strong> ${dbStatus} - ${escapeHtmlConfig(data.database.message)}</div>`;

                const lineasStatus = data.lineas_activas.status === 'ok'
                    ? '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> OK</span>'
                    : '<span class="text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i> Advertencia</span>';
                html += `<div class="mb-2"><strong>Líneas Activas:</strong> ${lineasStatus} - ${escapeHtmlConfig(data.lineas_activas.message)}</div>`;

                html += '<div class="mb-2"><strong>Estructura de Tablas:</strong><ul class="list-unstyled ps-3 mt-1 row">';
                for (const [tabla, existe] of Object.entries(data.tables.data || {})) {
                    const safeTabla = escapeHtmlConfig(tabla);
                    const icono = existe
                        ? '<i class="fa-solid fa-check text-success me-1"></i>'
                        : '<i class="fa-solid fa-xmark text-danger me-1"></i>';
                    const label = existe ? `<code>${safeTabla}</code>` : `<code class="text-danger">${safeTabla} (Falta)</code>`;
                    html += `<li class="col-6 mb-1">${icono} ${label}</li>`;
                }
                html += '</ul></div>';

                html += '<div class="mb-0"><strong>Archivos de Control:</strong><ul class="list-unstyled ps-3 mt-1 row">';
                for (const [archivo, existe] of Object.entries(data.files.data || {})) {
                    const safeArchivo = escapeHtmlConfig(archivo);
                    const icono = existe
                        ? '<i class="fa-solid fa-check text-success me-1"></i>'
                        : '<i class="fa-solid fa-xmark text-danger me-1"></i>';
                    const label = existe ? `<span>${safeArchivo}</span>` : `<span class="text-danger">${safeArchivo} (No encontrado)</span>`;
                    html += `<li class="col-12 mb-1">${icono} ${label}</li>`;
                }
                html += '</ul></div></div>';
                resDiv.html(html);
            } else {
                resDiv.html(`<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> Error: ${escapeHtmlConfig((res && res.message) ? res.message : 'Fallo desconocido')}</div>`);
            }
        },
        error: function () {
            resDiv.html('<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> Error de comunicación con el servidor.</div>');
        },
        complete: function () {
            releaseCfgLock('run_diag', $btn, '<i class="fa-solid fa-circle-play me-2"></i> Ejecutar Diagnóstico');
        }
    });
}

function ejecutarSimulador() {
    const $btn = $('#btnRunSimulador');
    if (!acquireCfgLock('run_sim', $btn, '<i class="fa-solid fa-spinner fa-spin me-2"></i> Simulando...')) return;

    Swal.fire({
        title: 'Ejecutando Simulación',
        text: 'Enviando payload de prueba al Webhook...',
        icon: 'info',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'run_simulacion_entrante' },
        success: function (res) {
            if (res && res.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: '¡Mensaje Simulado!',
                    text: res.message,
                    confirmButtonColor: '#E85B14'
                });
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'Error en simulación.', 'error');
            }
        },
        error: function () {
            Swal.fire('Error', 'No se pudo conectar al simulador.', 'error');
        },
        complete: function () {
            releaseCfgLock('run_sim', $btn, '<i class="fa-solid fa-paper-plane me-2"></i> Simular Recepción de Mensaje');
        }
    });
}

function enviarNotificacionPrueba() {
    const form = $('#formNotifPrueba')[0];
    if (form && !form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const $btn = $('#btnSendNotifPrueba');
    if (!acquireCfgLock('send_notif_test', $btn, '<i class="fa-solid fa-spinner fa-spin me-2"></i> Enviando...')) return;

    const resDiv = $('#resultadoNotifPrueba');
    resDiv.html('<div class="alert alert-info py-2"><i class="fa-solid fa-spinner fa-spin me-2"></i> Procesando y enviando notificación a Meta...</div>');

    const data = {
        action: 'run_envio_transaccional',
        telefono: ($('#notif_telefono').val() || '').trim(),
        nombre_cliente: ($('#notif_cliente').val() || '').trim(),
        monto_total: ($('#notif_monto').val() || '').trim(),
        correlativo: ($('#notif_correlativo').val() || '').trim(),
        asesor_ventas: ($('#notif_asesor').val() || '').trim(),
        telefono_asesor: ($('#notif_tel_asesor').val() || '').trim(),
        nombre_empresa: ($('#notif_empresa').val() || '').trim()
    };

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: data,
        success: function (res) {
            if (res && res.status === 'success') {
                const detailsObj = res.details || {};
                const metaId = (detailsObj.meta_response && Array.isArray(detailsObj.meta_response.messages) && detailsObj.meta_response.messages[0])
                    ? detailsObj.meta_response.messages[0].id
                    : 'N/A';
                resDiv.html(`
                    <div class="alert alert-success py-3">
                        <h6 class="fw-bold mb-2"><i class="fa-solid fa-check-circle me-1"></i> ${escapeHtmlConfig(res.message)}</h6>
                        <small class="d-block mb-1"><strong>Registro en Base de Datos:</strong> ${escapeHtmlConfig(detailsObj.registro_bd || '')}</small>
                        <small class="d-block mb-2"><strong>ID Mensaje Meta:</strong> ${escapeHtmlConfig(metaId)}</small>
                        <pre class="bg-dark text-light p-2 rounded small mb-0" style="max-height: 200px; overflow-y: auto;">${escapeHtmlConfig(JSON.stringify(detailsObj, null, 2))}</pre>
                    </div>
                `);
            } else {
                let errorDetails = '';
                if (res && res.details) {
                    errorDetails = `<pre class="bg-dark text-light p-2 rounded small mt-2 mb-0" style="max-height: 200px; overflow-y: auto;">${escapeHtmlConfig(JSON.stringify(res.details, null, 2))}</pre>`;
                }
                resDiv.html(`
                    <div class="alert alert-danger py-3">
                        <h6 class="fw-bold mb-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> Error en Envío</h6>
                        <p class="small mb-1">${escapeHtmlConfig((res && res.message) ? res.message : 'No se pudo enviar la notificación.')}</p>
                        ${errorDetails}
                    </div>
                `);
            }
        },
        error: function () {
            resDiv.html('<div class="alert alert-danger py-2"><i class="fa-solid fa-triangle-exclamation me-2"></i> Error de conexión con el servidor.</div>');
        },
        complete: function () {
            releaseCfgLock('send_notif_test', $btn, '<i class="fa-solid fa-paper-plane me-2"></i> Enviar Notificación de Prueba');
        }
    });
}

// ============================================================================
// INTEGRACIÓN DIRECTA CON META GRAPH
// ============================================================================
function toggleApiExperience() {
    const newEl = document.getElementById('api_type_new');
    const isNew = newEl && newEl.checked;

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
    const $btn = $('#btnFetchMeta');
    const waba_id = ($('#api_id_negocio').val() || '').trim();

    if (!waba_id) {
        Swal.fire('Atención', 'Debes ingresar el WABA ID de Meta', 'warning');
        return;
    }
    if (!acquireCfgLock('fetch_meta_apis', $btn, '<i class="fa-solid fa-spinner fa-spin"></i>')) return;

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'fetch_meta_apis', waba_id: waba_id },
        success: function (res) {
            if (res && res.status === 'success' && Array.isArray(res.data) && res.data.length > 0) {
                let options = '<option value="">Seleccione un número de la lista...</option>';
                window.lastFetchedMetaNumbers = res.data;

                res.data.forEach(num => {
                    options += `<option value="${escapeHtmlConfig(num.id)}">${escapeHtmlConfig(num.display_phone_number)} (${escapeHtmlConfig(num.quality_rating)})</option>`;
                });

                $('#api_select_meta').html(options);
                $('#select_number_container').slideDown();
                Swal.fire('¡Éxito!', `Se encontraron ${res.data.length} líneas en Meta.`, 'success');
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'No se encontraron números o el token es inválido.', 'error');
            }
        },
        error: function (xhr) {
            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error de conexión con el servidor local.';
            Swal.fire('Error', msg, 'error');
        },
        complete: function () {
            releaseCfgLock('fetch_meta_apis', $btn, '<i class="fa-solid fa-cloud-arrow-down me-1"></i> Buscar Líneas');
        }
    });
}

function autoFillMetaNumber() {
    const selectedId = $('#api_select_meta').val();
    if (!selectedId || !window.lastFetchedMetaNumbers) return;

    const numData = window.lastFetchedMetaNumbers.find(n => String(n.id) === String(selectedId));
    if (numData) {
        $('#api_telefono').val(numData.display_phone_number || '');
        $('#api_telefono_meta').val(numData.id || '');
    }
}

function registerMetaPhone() {
    const $btn = $('#btnRegisterMeta');
    const phone_id = ($('#api_telefono_meta_manual').val() || '').trim();
    const pin = ($('#api_pin_meta').val() || '').trim();

    if (!phone_id || !pin) {
        Swal.fire('Atención', 'Completa el Phone ID y el PIN de 6 dígitos.', 'warning');
        return;
    }
    if (pin.length !== 6) {
        Swal.fire('Atención', 'El PIN debe ser exactamente de 6 dígitos numéricos.', 'warning');
        return;
    }
    if (!acquireCfgLock('reg_meta_phone', $btn, '<i class="fa-solid fa-spinner fa-spin"></i>')) return;

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'register_meta_phone', phone_id: phone_id, pin: pin },
        success: function (res) {
            if (res && res.status === 'success') {
                Swal.fire('¡Dado de Alta!', res.message, 'success');
                $('#api_telefono_meta').val(phone_id);
                $('#api_type_existing').prop('checked', true);
                toggleApiExperience();
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'Error al registrar el número en Meta.', 'error');
            }
        },
        error: function (xhr) {
            const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Error de conexión con el servidor local.';
            Swal.fire('Error', msg, 'error');
        },
        complete: function () {
            releaseCfgLock('reg_meta_phone', $btn, '<i class="fa-solid fa-check-circle me-1"></i> Dar Alta');
        }
    });
}

// ============================================================================
// GESTIÓN DE RESPUESTAS RÁPIDAS
// ============================================================================
function loadRespuestasRapidas() {
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_respuestas_rapidas' },
        success: function (res) {
            if (res && res.status === 'success') {
                const container = $('#respuestasContainer');
                container.empty();
                const list = res.data || [];
                if (list.length === 0) {
                    container.append('<div class="col-12 text-center text-muted p-4">No hay respuestas rápidas registradas.</div>');
                    return;
                }
                list.forEach(r => {
                    const rid = parseInt(r.id, 10) || 0;
                    const card = `
                    <div class="col-md-4">
                        <div class="card h-100 shadow-sm border-0" style="border-radius: 10px;">
                            <div class="card-header bg-white border-0 pt-3 pb-0">
                                <h6 class="mb-0 fw-bold text-dark"><i class="fa-solid fa-bolt text-warning me-2"></i>${escapeHtmlConfig(r.titulo)}</h6>
                                <small class="text-muted"><i class="fa-solid fa-building me-1"></i>${escapeHtmlConfig(r.nombre_sede || 'Sede no asignada')}</small>
                            </div>
                            <div class="card-body">
                                <p class="text-muted mb-0" style="font-size: 0.9rem; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">${escapeHtmlConfig(r.mensaje)}</p>
                            </div>
                            <div class="card-footer bg-white border-top-0 p-0">
                                <div class="d-flex border-top">
                                    <button type="button" class="btn btn-link text-primary text-decoration-none flex-fill border-end rounded-0 py-2 action-btn btn-resp-edit" data-id="${rid}" title="Editar"><i class="fa-solid fa-pen"></i></button>
                                    <button type="button" class="btn btn-link text-danger text-decoration-none flex-fill rounded-0 py-2 action-btn btn-resp-del" data-id="${rid}" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
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
    if (!acquireCfgLock('open_add_resp')) return;
    if ($('#formRespuesta').length) $('#formRespuesta')[0].reset();
    $('#id_respuesta').val('');

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function (res) {
            if (res && res.status === 'success') {
                const select = $('#resp_id_sede');
                select.html('<option value="">Seleccione una sede...</option>');
                (res.data || []).forEach(s => {
                    select.append(`<option value="${parseInt(s.id, 10) || 0}">${escapeHtmlConfig(s.nombre_sede)}</option>`);
                });
                if (!isMasterConfigUser && (res.data || []).length === 1) {
                    select.val(String(res.data[0].id));
                }

                $('#modalRespuestaRapida .modal-title').html('<i class="fa-solid fa-bolt text-warning me-2"></i>Nueva Respuesta Rápida');
                const modalEl = document.getElementById('modalRespuestaRapida');
                if (modalEl) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).show();
                }
            }
        },
        complete: function () {
            releaseCfgLock('open_add_resp');
        }
    });
}

function editarRespuestaRapida(id, $btn) {
    if (!acquireCfgLock('edit_resp_' + id, $btn)) return;
    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_sedes_list' },
        success: function (resSedes) {
            if (resSedes && resSedes.status === 'success') {
                const select = $('#resp_id_sede');
                select.html('<option value="">Seleccione una sede...</option>');
                (resSedes.data || []).forEach(s => {
                    select.append(`<option value="${parseInt(s.id, 10) || 0}">${escapeHtmlConfig(s.nombre_sede)}</option>`);
                });

                $.ajax({
                    url: 'back_configuracion.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'get_respuesta_rapida', id: id },
                    success: function (res) {
                        if (res && res.status === 'success' && res.data) {
                            const r = res.data;
                            $('#id_respuesta').val(r.id);
                            $('#resp_id_sede').val(r.id_sede);
                            $('#resp_titulo').val(r.titulo);
                            $('#resp_mensaje').val(r.mensaje);

                            $('#modalRespuestaRapida .modal-title').html('<i class="fa-solid fa-bolt text-warning me-2"></i>Editar Respuesta Rápida');
                            const modalEl = document.getElementById('modalRespuestaRapida');
                            if (modalEl) {
                                bootstrap.Modal.getOrCreateInstance(modalEl).show();
                            }
                        } else {
                            Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo cargar la respuesta.', 'error');
                        }
                    },
                    complete: function () {
                        releaseCfgLock('edit_resp_' + id, $btn);
                    }
                });
            } else {
                releaseCfgLock('edit_resp_' + id, $btn);
            }
        },
        error: function () {
            releaseCfgLock('edit_resp_' + id, $btn);
        }
    });
}

$(document).on('click', '#btnSaveRespuesta', function () {
    const $btn = $(this);
    if (!$('#resp_id_sede').val() || !($('#resp_titulo').val() || '').trim() || !($('#resp_mensaje').val() || '').trim()) {
        Swal.fire('Error', 'Complete todos los campos obligatorios (*)', 'warning');
        return;
    }
    if (!acquireCfgLock('save_resp', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...')) return;

    const data = {
        action: 'save_respuesta_rapida',
        id_respuesta: $('#id_respuesta').val(),
        id_sede: $('#resp_id_sede').val(),
        titulo: ($('#resp_titulo').val() || '').trim(),
        mensaje: ($('#resp_mensaje').val() || '').trim()
    };

    $.ajax({
        url: 'back_configuracion.php',
        type: 'POST',
        dataType: 'json',
        data: data,
        success: function (res) {
            if (res && res.status === 'success') {
                const inst = bootstrap.Modal.getInstance(document.getElementById('modalRespuestaRapida'));
                if (inst) inst.hide();
                Swal.fire('Éxito', res.message, 'success');
                loadRespuestasRapidas();
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'Error al guardar.', 'error');
            }
        },
        error: function () {
            Swal.fire('Error', 'Fallo de red al guardar la respuesta rápida.', 'error');
        },
        complete: function () {
            releaseCfgLock('save_resp', $btn, 'Guardar Respuesta');
        }
    });
});

function borrarRespuestaRapida(id, $btn) {
    if (cfgInFlight.has('del_resp_' + id)) return;
    Swal.fire({
        title: '¿Estás seguro?',
        text: 'Esta respuesta rápida se eliminará permanentemente.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, borrar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!acquireCfgLock('del_resp_' + id, $btn)) return;
        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'delete_respuesta_rapida', id: id },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Eliminado', res.message, 'success');
                    loadRespuestasRapidas();
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo eliminar.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de conexión al eliminar.', 'error');
            },
            complete: function () {
                releaseCfgLock('del_resp_' + id, $btn);
            }
        });
    });
}

// ============================================================================
// PESTAÑA DE CONFIGURACIÓN DE TIEMPOS SLA Y ENRUTAMIENTO ([COM-04])
// ============================================================================
let slaSedesPopulated = false;

$(document).ready(function () {
    loadSlaSettingsModule(0);

    const sp = new URLSearchParams(window.location.search);
    if (sp.get('tab') === 'sla') {
        const slaTabEl = document.getElementById('sla-tab');
        if (slaTabEl && window.bootstrap && bootstrap.Tab) {
            new bootstrap.Tab(slaTabEl).show();
        }
    }

    $('#cfgSlaSedeSelect').on('change', function () {
        loadSlaSettingsModule(parseInt($(this).val() || '0', 10));
    });

    $('#cfgSlaVerdeMin, #cfgSlaAmarilloMin').on('input', function () {
        updateCfgSlaPreview();
    });

    $('#btnSaveSlaSettings').on('click', function () {
        const $btn = $(this);
        const sedeId = parseInt($('#cfgSlaSedeSelect').val() || '0', 10);
        const verdeMin = parseInt($('#cfgSlaVerdeMin').val() || '5', 10);
        const amarilloMin = parseInt($('#cfgSlaAmarilloMin').val() || '15', 10);
        const rrMin = parseInt($('#cfgSlaRrOnlineMin').val() || '5', 10);
        const snd = $('#cfgSlaSoundDefault').is(':checked') ? 1 : 0;

        if (verdeMin < 1 || amarilloMin <= verdeMin) {
            Swal.fire('Validación SLA', 'El límite para Rojo Crítico debe ser mayor que el tiempo máximo en Verde.', 'warning');
            return;
        }
        if (!acquireCfgLock('save_sla', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...')) return;

        $.ajax({
            url: 'back_configuracion.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'save_sla_settings',
                id_sede: sedeId,
                verde_max_min: verdeMin,
                amarillo_max_min: amarilloMin,
                round_robin_online_min: rrMin,
                alerta_sonora_activa: snd
            },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Guardado', text: res.message, timer: 2200, showConfirmButton: false });
                    loadSlaSettingsModule(sedeId);
                } else {
                    Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo guardar.', 'error');
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Fallo de red al guardar los tiempos SLA.';
                Swal.fire('Error', msg, 'error');
            },
            complete: function () {
                releaseCfgLock('save_sla', $btn, '<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Configuración SLA');
            }
        });
    });

    $('#btnResetSlaOverride').on('click', function () {
        const $btn = $(this);
        if (cfgInFlight.has('reset_sla') || $btn.prop('disabled')) return;
        const sedeId = parseInt($('#cfgSlaSedeSelect').val() || '0', 10);
        if (sedeId <= 0) return;

        Swal.fire({
            title: '¿Restaurar a Regla Global?',
            text: 'Esta sede volverá a usar los tiempos SLA globales del sistema.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, restaurar',
            cancelButtonText: 'Cancelar'
        }).then((r) => {
            if (!r.isConfirmed) return;
            if (!acquireCfgLock('reset_sla', $btn, '<i class="fa-solid fa-spinner fa-spin me-1"></i> Restaurando...')) return;
            $.ajax({
                url: 'back_configuracion.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'reset_sla_sede', id_sede: sedeId },
                success: function (res) {
                    if (res && res.status === 'success') {
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: res.message, showConfirmButton: false, timer: 2200 });
                        loadSlaSettingsModule(sedeId);
                    } else {
                        Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo restaurar.', 'error');
                    }
                },
                complete: function () {
                    releaseCfgLock('reset_sla', $btn, '<i class="fa-solid fa-rotate-left me-1"></i> Restaurar a Regla Global');
                }
            });
        });
    });
});

function updateCfgSlaPreview() {
    const v = Math.max(1, parseInt($('#cfgSlaVerdeMin').val() || '5', 10));
    const a = Math.max(v + 1, parseInt($('#cfgSlaAmarilloMin').val() || '15', 10));
    $('#cfgPrevVerde').text(v);
    $('#cfgPrevAmarillo').text(`${v}-${a}`);
    $('#cfgPrevRojo').text(a);
}

window.selectSlaRuleRow = function (sedeId) {
    $('#cfgSlaSedeSelect').val(String(sedeId)).trigger('change');
};

function loadSlaSettingsModule(targetSede) {
    if (!$('#cfgSlaVerdeMin').length) return;
    $.post('back_configuracion.php', {
        action: 'get_sla_settings',
        id_sede: targetSede || 0
    }, function (res) {
        if (!res || res.status !== 'success') return;

        if (!slaSedesPopulated) {
            const $sel = $('#cfgSlaSedeSelect');
            $sel.empty();
            if (res.is_master) {
                $sel.append('<option value="0">🌎 Regla Global por Defecto (Todas las Sedes)</option>');
            }
            (res.sedes || []).forEach(s => {
                $sel.append(`<option value="${parseInt(s.id, 10) || 0}">🏢 ${escapeHtmlConfig(s.nombre_sede)}</option>`);
            });
            if (!res.is_master && res.user_sede > 0) {
                $sel.val(String(res.user_sede)).prop('disabled', true);
            }
            slaSedesPopulated = true;
        }

        const cfg = res.config || {};
        const reqSede = parseInt(cfg.id_sede_solicitada || 0, 10);
        if ($('#cfgSlaSedeSelect option[value="' + reqSede + '"]').length) {
            $('#cfgSlaSedeSelect').val(String(reqSede));
        }

        $('#cfgSlaVerdeMin').val(cfg.verde_max_min || 5);
        $('#cfgSlaAmarilloMin').val(cfg.amarillo_max_min || 15);
        $('#cfgSlaRrOnlineMin').val(cfg.round_robin_online_min || 5);
        $('#cfgSlaSoundDefault').prop('checked', parseInt(cfg.alerta_sonora_activa ?? 1, 10) === 1);
        updateCfgSlaPreview();

        const $badge = $('#slaRuleTypeBadge');
        if (reqSede === 0) {
            $badge.attr('class', 'badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-2').text('Regla Global Activa');
            $('#btnResetSlaOverride').hide();
        } else if (cfg.es_personalizado) {
            $badge.attr('class', 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-3 py-2').text('Personalizado en esta Sede');
            $('#btnResetSlaOverride').show();
        } else {
            $badge.attr('class', 'badge bg-light text-secondary border rounded-pill px-3 py-2').text('Heredando Regla Global');
            $('#btnResetSlaOverride').hide();
        }

        const $tbody = $('#slaRulesSummaryBody');
        $tbody.empty();
        (res.rules || []).forEach(r => {
            const sid = parseInt(r.id_sede || 0, 10);
            const v = parseInt(r.verde_max_min || 5, 10);
            const a = parseInt(r.amarillo_max_min || 15, 10);
            const activeCls = (sid === reqSede) ? 'table-active fw-bold' : '';
            $tbody.append(`
                <tr class="sla-rule-row ${activeCls}" data-sede-id="${sid}" style="cursor:pointer;">
                    <td>${escapeHtmlConfig(r.ambito_nombre)}</td>
                    <td class="text-center text-success fw-bold">&lt;${v}m</td>
                    <td class="text-center text-warning-emphasis fw-bold">${v}-${a}m</td>
                    <td class="text-center text-danger fw-bold">&gt;${a}m</td>
                </tr>
            `);
        });
    }, 'json');
}
