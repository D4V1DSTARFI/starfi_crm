// modules/gestor_bots/funciones_gestor_bots.js
// Controlador JS unificado (Tabla + Árbol Visual Drawflow + Vendedores) | STARFI CRM

let allRules = [];
let filteredRules = [];
let currentPage = 1;
const pageSize = 10;
let currentSortCol = 'disparador';
let currentSortAsc = true;
let allContactos = [];

// Estado Drawflow integrado (cápsula estricta de 4 archivos)
let editor = null;
let currentViewMode = 'table'; // 'table' | 'visual'

// Guards anti-doble-clic (QA-BUG-CRM3-07)
let isSavingBotRule = false;
let isDeletingBotRule = false;
let isTogglingBotStatus = false;
let isSavingDrawflow = false;
let isSavingContacto = false;
let isDeletingContacto = false;

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, function (m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[m];
    });
}
const escapeBotHtml = escapeHtml;

$(document).ready(function () {
    loadBotRules();

    $('#enableButtons').on('change', function () {
        if ($(this).is(':checked')) {
            $('#buttonsContainer').slideDown();
        } else {
            $('#buttonsContainer').slideUp();
            $('#buttonsList input').val('');
        }
    });

    $('#botModal').on('hidden.bs.modal', function () {
        $('#buttonsContainer').hide();
        $('#enableButtons').prop('checked', false);
        $('#buttonsList input').val('');
        $('#mediaFields').hide();
        $('#locationFields').hide();
    });

    $('#botForm').on('submit', function (e) {
        e.preventDefault();
        saveBotRule(document.getElementById('btnSaveBotRule'));
    });

    $('#searchRule').on('keyup', function () {
        currentPage = 1;
        applyFiltersAndRender();
    });

    $('#btnPrevPage').on('click', function () {
        if (currentPage > 1) {
            currentPage--;
            renderTablePage();
        }
    });

    $('#btnNextPage').on('click', function () {
        const totalPages = Math.ceil(filteredRules.length / pageSize);
        if (currentPage < totalPages) {
            currentPage++;
            renderTablePage();
        }
    });

    $('th.sortable').on('click', function () {
        const col = $(this).data('sort');
        if (currentSortCol === col) {
            currentSortAsc = !currentSortAsc;
        } else {
            currentSortCol = col;
            currentSortAsc = true;
        }

        $('th.sortable').removeClass('asc desc');
        $('th.sortable i').removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');

        let icon = currentSortAsc ? 'fa-sort-up' : 'fa-sort-down';
        $(this).addClass(currentSortAsc ? 'asc' : 'desc');
        $(this).find('i').removeClass('fa-sort').addClass(icon);

        currentPage = 1;
        applyFiltersAndRender();
    });
});

let botModalObj = null;

function toggleFormatFields() {
    const fmt = $('#ruleFormat').val();
    if (fmt === 'IMAGEN' || fmt === 'DOCUMENTO') {
        $('#mediaFields').slideDown();
        $('#locationFields').slideUp();
    } else if (fmt === 'UBICACION') {
        $('#locationFields').slideDown();
        $('#mediaFields').slideUp();
    } else {
        $('#mediaFields').slideUp();
        $('#locationFields').slideUp();
    }
}

function handleFileUpload(input) {
    if (input && input.files && input.files[0]) {
        $('#ruleMediaUrl').val('uploads/bots/' + input.files[0].name);
    }
}

function loadBotRules() {
    let selectObj = $('#sedeFilter');
    let idSede = selectObj.val();

    if (!idSede || idSede === '0') {
        $('#botToggleContainer').hide();
        $('#botContentContainer').hide();
        return;
    }

    let botActivo = selectObj.find('option:selected').data('bot-activo');
    $('#botStatusToggle').prop('checked', botActivo == 1);

    $('#botToggleContainer').css('display', 'flex');
    $('#botContentContainer').fadeIn();

    $.ajax({
        url: 'back_gestor_bots.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_rules', id_sede: idSede },
        success: function (response) {
            if (response.status === 'success') {
                allRules = response.data || [];
                applyFiltersAndRender();
                if (currentViewMode === 'visual' && editor !== null) {
                    renderNodesFromRules();
                }
            } else {
                Swal.fire('Error', response.message || 'No se pudieron cargar las reglas.', 'error');
            }
        }
    });
}

function toggleBotStatus() {
    if (isTogglingBotStatus) return;
    isTogglingBotStatus = true;

    const $toggle = $('#botStatusToggle');
    let idSede = $('#sedeFilter').val();
    let isActive = $toggle.is(':checked') ? 1 : 0;
    $toggle.prop('disabled', true);

    $.ajax({
        url: 'back_gestor_bots.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'toggle_bot', id_sede: idSede, status: isActive },
        success: function (response) {
            if (response && response.status === 'success') {
                $('#sedeFilter').find('option:selected').data('bot-activo', isActive);
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Estado del Bot Actualizado',
                    showConfirmButton: false,
                    timer: 2000
                });
            } else {
                $toggle.prop('checked', !isActive);
                Swal.fire('Error', (response && response.message) || 'Error al actualizar el bot.', 'error');
            }
        },
        error: function () {
            $toggle.prop('checked', !isActive);
            Swal.fire('Error', 'Fallo de conexión al actualizar el bot.', 'error');
        },
        complete: function () {
            isTogglingBotStatus = false;
            $toggle.prop('disabled', false);
        }
    });
}

function applyFiltersAndRender() {
    let query = ($('#searchRule').val() || '').toLowerCase().trim();

    filteredRules = allRules.filter(r => {
        let disp = r.disparador ? r.disparador.toLowerCase() : '';
        let msg = r.mensaje ? r.mensaje.toLowerCase() : '';
        return disp.includes(query) || msg.includes(query);
    });

    filteredRules.sort((a, b) => {
        let valA = '', valB = '';
        if (currentSortCol === 'tipo') {
            valA = a.tipo || ''; valB = b.tipo || '';
        } else if (currentSortCol === 'disparador') {
            valA = a.disparador || ''; valB = b.disparador || '';
        } else if (currentSortCol === 'estado') {
            valA = a.estado || ''; valB = b.estado || '';
        }

        if (valA < valB) return currentSortAsc ? -1 : 1;
        if (valA > valB) return currentSortAsc ? 1 : -1;
        return 0;
    });

    renderTablePage();
}

function renderTablePage() {
    let tbody = $('#botRulesTable');
    tbody.empty();

    let totalItems = filteredRules.length;
    let totalPages = Math.ceil(totalItems / pageSize) || 1;

    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    let startIndex = (currentPage - 1) * pageSize;
    let endIndex = startIndex + pageSize;
    let paginatedItems = filteredRules.slice(startIndex, endIndex);

    if (paginatedItems.length === 0) {
        tbody.append('<tr><td colspan="5" class="text-center text-muted p-5"><i class="fa-solid fa-robot fs-2 mb-3 d-block"></i>No se encontraron reglas.</td></tr>');
    } else {
        paginatedItems.forEach(rule => {
            const safeId = Number(rule.id) || 0;
            let tipoBadge = '';
            if (rule.tipo === 'EVENTO_SISTEMA') {
                tipoBadge = '<span class="badge bg-secondary">Evento</span>';
            } else if (rule.tipo === 'CIERRE_CSAT') {
                tipoBadge = '<span class="badge bg-danger"><i class="fa-solid fa-star me-1"></i>CSAT &amp; Cierre</span>';
            } else {
                tipoBadge = '<span class="badge bg-primary">Palabra Clave</span>';
            }

            let estadoBadge = rule.estado === 'ACTIVO'
                ? '<span class="badge bg-success">Activo</span>'
                : '<span class="badge bg-light text-dark border">Inactivo</span>';

            let html = `
                <tr>
                    <td style="padding-left: 24px;">${tipoBadge}</td>
                    <td class="fw-bold">${escapeHtml(rule.disparador)}</td>
                    <td><small class="text-muted text-wrap" style="max-width: 300px; display: block;">${escapeHtml(rule.mensaje)}</small></td>
                    <td>${estadoBadge}</td>
                    <td style="text-align: right; padding-right: 24px;">
                        <button class="btn btn-sm btn-light text-primary me-1" onclick="editBotRule(${safeId})">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="btn btn-sm btn-light text-danger" onclick="deleteBotRule(${safeId}, this)">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            tbody.append(html);
        });
    }

    let displayEnd = Math.min(endIndex, totalItems);
    let displayStart = totalItems === 0 ? 0 : startIndex + 1;

    $('#pageInfo').text(`Mostrando ${displayStart} - ${displayEnd} de ${totalItems} reglas`);
    $('#btnPrevPage').prop('disabled', currentPage === 1);
    $('#btnNextPage').prop('disabled', currentPage === totalPages || totalItems === 0);
}

function openBotModal() {
    let idSede = $('#sedeFilter').val();
    if (!idSede || idSede === '0') {
        Swal.fire('Atención', 'Por favor, selecciona una sede en el menú desplegable primero.', 'warning');
        return;
    }
    $('#botForm')[0].reset();
    $('#ruleId').val('0');
    $('#parentId').val('');
    $('#botModalTitle').text('Nueva Respuesta Automática');
    $('#btnSaveBotRule').prop('disabled', false);
    toggleFormatFields();

    if (!botModalObj) botModalObj = new bootstrap.Modal(document.getElementById('botModal'));
    botModalObj.show();
}

function editBotRule(id, tipo, disparador, mensaje, estado) {
    let rule = allRules.find(r => Number(r.id) === Number(id));
    $('#ruleId').val(id);
    $('#ruleType').val(tipo !== undefined ? tipo : (rule ? rule.tipo : 'PALABRA_CLAVE'));
    $('#ruleFormat').val(rule && rule.formato_respuesta ? rule.formato_respuesta : 'TEXTO');
    $('#ruleTrigger').val(disparador !== undefined ? disparador : (rule ? rule.disparador : ''));
    $('#ruleMessage').val(mensaje !== undefined ? mensaje : (rule ? rule.mensaje : ''));
    $('#ruleState').val(estado !== undefined ? estado : (rule ? rule.estado : 'ACTIVO'));
    $('#parentId').val(rule && rule.id_padre ? rule.id_padre : '');
    $('#ruleMediaUrl').val(rule && rule.media_url ? rule.media_url : '');
    $('#ruleLat').val(rule && rule.latitud ? rule.latitud : '');
    $('#ruleLng').val(rule && rule.longitud ? rule.longitud : '');
    $('#enableWait').prop('checked', !!(rule && Number(rule.espera_respuesta) === 1));
    $('#botModalTitle').text('Editar Respuesta Automática');
    $('#btnSaveBotRule').prop('disabled', false);
    toggleFormatFields();

    if (!botModalObj) botModalObj = new bootstrap.Modal(document.getElementById('botModal'));
    botModalObj.show();
}

function saveBotRule(btnEl) {
    if (isSavingBotRule) return;

    const data = {
        action: 'save_rule',
        id: $('#ruleId').val(),
        id_sede: $('#sedeFilter').val(),
        id_padre: $('#parentId').val(),
        tipo: $('#ruleType').val(),
        formato_respuesta: $('#ruleFormat').val(),
        disparador: ($('#ruleTrigger').val() || '').trim(),
        mensaje: ($('#ruleMessage').val() || '').trim(),
        media_url: $('#ruleMediaUrl').val(),
        latitud: $('#ruleLat').val(),
        longitud: $('#ruleLng').val(),
        espera_respuesta: $('#enableWait').is(':checked') ? '1' : '0',
        estado: $('#ruleState').val()
    };

    if (!data.disparador || !data.mensaje) {
        Swal.fire('Atención', 'El disparador y el mensaje son obligatorios.', 'warning');
        return;
    }

    isSavingBotRule = true;
    const $btn = btnEl ? $(btnEl) : $('#btnSaveBotRule');
    $btn.prop('disabled', true);

    $.ajax({
        url: 'back_gestor_bots.php',
        type: 'POST',
        dataType: 'json',
        data: data,
        success: function (response) {
            if (response && response.status === 'success') {
                if (botModalObj) botModalObj.hide();
                Swal.fire({
                    icon: 'success',
                    title: '¡Guardado!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                });
                loadBotRules();
            } else {
                Swal.fire('Error', (response && response.message) || 'No se pudo guardar la regla.', 'error');
            }
        },
        error: function () {
            Swal.fire('Error', 'Fallo de conexión al guardar la regla.', 'error');
        },
        complete: function () {
            isSavingBotRule = false;
            $btn.prop('disabled', false);
        }
    });
}

function deleteBotRule(id, btnEl) {
    if (isDeletingBotRule) return;
    Swal.fire({
        title: '¿Estás seguro?',
        text: '¡No podrás revertir esto!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (!result.isConfirmed || isDeletingBotRule) return;
        isDeletingBotRule = true;
        const $btn = btnEl ? $(btnEl) : $();
        $btn.prop('disabled', true);

        $.ajax({
            url: 'back_gestor_bots.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'delete_rule', id: id },
            success: function (response) {
                if (response && response.status === 'success') {
                    loadBotRules();
                    Swal.fire('Eliminada', 'La regla ha sido eliminada.', 'success');
                } else {
                    Swal.fire('Error', (response && response.message) || 'No se pudo eliminar.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de conexión al eliminar la regla.', 'error');
            },
            complete: function () {
                isDeletingBotRule = false;
                $btn.prop('disabled', false);
            }
        });
    });
}

function showToast(title, message, type) {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: type === 'danger' ? 'error' : type,
        title: title,
        text: message,
        showConfirmButton: false,
        timer: 3000
    });
}

// ==========================================
// ÁRBOL CONVERSACIONAL VISUAL (DRAWFLOW)
// ==========================================

function toggleViewMode() {
    if (currentViewMode === 'table') {
        $('#tableContainer').hide();
        $('#paginationContainer').hide();
        $('#drawflowContainer').fadeIn();
        $('#btnToggleView').html('<i class="fa-solid fa-list me-1"></i> Modo Lista');
        currentViewMode = 'visual';
        initDrawflow();
    } else {
        $('#drawflowContainer').hide();
        $('#tableContainer').fadeIn();
        $('#paginationContainer').fadeIn();
        $('#btnToggleView').html('<i class="fa-solid fa-project-diagram me-1"></i> Modo Visual');
        currentViewMode = 'table';
    }
}

function initDrawflow() {
    if (editor !== null) {
        editor.clearModuleSelected();
        renderNodesFromRules();
        return;
    }

    const container = document.getElementById('drawflow');
    if (!container || typeof Drawflow === 'undefined') return;
    editor = new Drawflow(container);
    editor.reroute = true;
    editor.reroute_fix_curvature = true;
    editor.start();

    renderNodesFromRules();
}

function renderNodesFromRules() {
    if (!editor) return;
    editor.clearModuleSelected();

    let dbIdToDfId = {};
    let x = 100;
    let y = 100;

    allRules.forEach((rule, index) => {
        const safeId = Number(rule.id) || 0;
        let icon = '<i class="fa-solid fa-comment text-primary"></i>';
        if (rule.formato_respuesta === 'IMAGEN') icon = '<i class="fa-solid fa-image text-success"></i>';
        if (rule.formato_respuesta === 'UBICACION') icon = '<i class="fa-solid fa-map-marker-alt text-danger"></i>';
        if (rule.formato_respuesta === 'CONTACTOS_SEDE' || rule.formato_respuesta === 'CONTACTOS') icon = '<i class="fa-solid fa-address-book text-info"></i>';
        if (rule.tipo === 'CIERRE_CSAT') icon = '<i class="fa-solid fa-star text-warning"></i>';

        let headerColor = Number(rule.espera_respuesta) === 1 ? '#FEF3C7' : '#EFF6FF';
        let headerBorder = Number(rule.espera_respuesta) === 1 ? '#FDE68A' : '#BFDBFE';

        let html = `
            <div ondblclick="editBotRule(${safeId})">
                <div class="title-box" style="background: ${headerColor}; border-bottom: 1px solid ${headerBorder};">
                    <span>${icon} ${escapeHtml(rule.disparador)}</span>
                    <span class="badge bg-dark">${safeId}</span>
                </div>
                <div class="box">
                    <p style="margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.75rem;">${escapeHtml(rule.mensaje)}</p>
                </div>
            </div>
        `;

        let posX = x + (index % 4) * 300;
        let posY = y + Math.floor(index / 4) * 150;

        let dfId = editor.addNode('rule_' + safeId, 1, 1, posX, posY, 'rule-node', { db_id: safeId }, html);
        dbIdToDfId[safeId] = dfId;
    });

    allRules.forEach(rule => {
        const safeId = Number(rule.id) || 0;
        const parentId = Number(rule.id_padre) || 0;
        if (parentId && dbIdToDfId[parentId] && dbIdToDfId[safeId]) {
            editor.addConnection(dbIdToDfId[parentId], dbIdToDfId[safeId], 'output_1', 'input_1');
        }
    });
}

function saveDrawflowNetwork(btnEl) {
    if (!editor || isSavingDrawflow) return;

    let exportData = editor.export();
    let nodes = (exportData && exportData.drawflow && exportData.drawflow.Home && exportData.drawflow.Home.data)
        ? exportData.drawflow.Home.data
        : {};

    let updates = [];
    Object.keys(nodes).forEach(nodeKey => {
        let node = nodes[nodeKey];
        let db_id = node.data ? node.data.db_id : null;
        if (!db_id) return;

        let inputConnections = (node.inputs && node.inputs.input_1 && node.inputs.input_1.connections)
            ? node.inputs.input_1.connections
            : [];
        let id_padre = null;

        if (inputConnections.length > 0) {
            let parentDfId = inputConnections[0].node;
            let parentNode = nodes[parentDfId];
            if (parentNode && parentNode.data) {
                id_padre = parentNode.data.db_id;
            }
        }

        updates.push({
            id: db_id,
            id_padre: id_padre
        });
    });

    if (updates.length === 0) {
        Swal.fire('Info', 'No hay nodos para guardar.', 'info');
        return;
    }

    isSavingDrawflow = true;
    const $btn = btnEl ? $(btnEl) : $('#btnSaveDrawflow');
    $btn.prop('disabled', true);

    $.ajax({
        url: 'back_gestor_bots.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'update_network',
            nodes: JSON.stringify(updates)
        },
        success: function (response) {
            if (response && response.status === 'success') {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Árbol Guardado Correctamente',
                    showConfirmButton: false,
                    timer: 2000
                });
                loadBotRules();
            } else {
                Swal.fire('Error', (response && response.message) || 'Error al guardar la red.', 'error');
            }
        },
        error: function () {
            Swal.fire('Error', 'Fallo de conexión al guardar la red.', 'error');
        },
        complete: function () {
            isSavingDrawflow = false;
            $btn.prop('disabled', false);
        }
    });
}

// ==========================================
// GESTIÓN DE VENDEDORES (CONTACTOS)
// ==========================================
let modalContactos, modalContactoForm;

document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('toggleSidebar');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) sidebar.classList.toggle('collapsed');
        });
    }

    if (document.getElementById('modalContactos')) {
        modalContactos = new bootstrap.Modal(document.getElementById('modalContactos'));
        modalContactoForm = new bootstrap.Modal(document.getElementById('modalContactoForm'));

        const cForm = document.getElementById('contactoForm');
        if (cForm) {
            cForm.addEventListener('submit', function (e) {
                e.preventDefault();
                saveContacto();
            });
        }
    }
});

function saveContacto(btnEl) {
    if (isSavingContacto) return;
    const form = document.getElementById('contactoForm');
    if (!form) return;
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    isSavingContacto = true;
    const submitBtn = btnEl || document.getElementById('btnSaveContacto') || form.querySelector('button[type="submit"]');
    if (submitBtn) $(submitBtn).prop('disabled', true);

    const formData = new FormData(form);
    formData.append('action', 'save_contacto');
    formData.append('id_sede', document.getElementById('sedeFilter').value);

    fetch('back_gestor_bots.php', {
        method: 'POST',
        body: formData
    })
        .then(r => r.json())
        .then(res => {
            if (res && res.status === 'success') {
                showToast('Éxito', res.message, 'success');
                if (modalContactoForm) modalContactoForm.hide();
                loadContactos();
            } else {
                showToast('Error', (res && res.message) || 'No se pudo guardar el vendedor.', 'danger');
            }
        })
        .catch(() => {
            showToast('Error', 'Error de red', 'danger');
        })
        .finally(() => {
            isSavingContacto = false;
            if (submitBtn) $(submitBtn).prop('disabled', false);
        });
}

function openContactosModal() {
    loadContactos();
    modalContactos.show();
}

function loadContactos() {
    const sedeId = document.getElementById('sedeFilter').value;
    const fd = new FormData();
    fd.append('action', 'load_contactos');
    fd.append('id_sede', sedeId);

    fetch('back_gestor_bots.php', {
        method: 'POST',
        body: fd
    })
        .then(r => r.json())
        .then(res => {
            if (res && res.status === 'success') {
                allContactos = res.data || [];
                const tbody = document.getElementById('contactosTableBody');
                tbody.innerHTML = '';
                if (allContactos.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-4">No hay vendedores registrados.</td></tr>';
                    return;
                }
                allContactos.forEach(c => {
                    const cid = Number(c.id) || 0;
                    const badgeClass = c.estado === 'ACTIVO' ? 'bg-success' : 'bg-danger';
                    tbody.innerHTML += `
                        <tr>
                            <td class="fw-bold text-dark">${escapeHtml(c.nombre)}</td>
                            <td>${escapeHtml(c.telefono)}</td>
                            <td><span class="badge ${badgeClass}">${escapeHtml(c.estado)}</span></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-light text-primary me-2" onclick="editContactoById(${cid})"><i class="fa-solid fa-pen"></i></button>
                                <button class="btn btn-sm btn-light text-danger" onclick="deleteContacto(${cid}, this)"><i class="fa-solid fa-trash"></i></button>
                            </td>
                        </tr>
                    `;
                });
            }
        });
}

function newContacto() {
    document.getElementById('contactoForm').reset();
    document.getElementById('contacto_id').value = '';
    const submitBtn = document.getElementById('btnSaveContacto');
    if (submitBtn) submitBtn.disabled = false;
    modalContactoForm.show();
}

function editContactoById(id) {
    const c = allContactos.find(x => Number(x.id) === Number(id));
    if (c) editContacto(c);
}

function editContacto(c) {
    document.getElementById('contacto_id').value = c.id;
    document.getElementById('contacto_nombre').value = c.nombre || '';
    document.getElementById('contacto_telefono').value = c.telefono || '';
    document.getElementById('contacto_estado').value = c.estado || 'ACTIVO';
    const submitBtn = document.getElementById('btnSaveContacto');
    if (submitBtn) submitBtn.disabled = false;
    modalContactoForm.show();
}

function deleteContacto(id, btnEl) {
    if (isDeletingContacto) return;
    Swal.fire({
        title: '¿Eliminar vendedor?',
        text: 'Se eliminará este contacto de la lista de asesores del bot.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Sí, eliminar'
    }).then(result => {
        if (!result.isConfirmed || isDeletingContacto) return;
        isDeletingContacto = true;
        if (btnEl) $(btnEl).prop('disabled', true);

        const fd = new FormData();
        fd.append('action', 'delete_contacto');
        fd.append('id', id);

        fetch('back_gestor_bots.php', {
            method: 'POST',
            body: fd
        })
            .then(r => r.json())
            .then(res => {
                if (res && res.status === 'success') {
                    showToast('Eliminado', res.message, 'success');
                    loadContactos();
                } else {
                    showToast('Error', (res && res.message) || 'No se pudo eliminar el vendedor.', 'danger');
                }
            })
            .catch(() => {
                showToast('Error', 'Error de red al eliminar vendedor.', 'danger');
            })
            .finally(() => {
                isDeletingContacto = false;
                if (btnEl) $(btnEl).prop('disabled', false);
            });
    });
}
