// modules/directorio/funciones_directorio.js

let currentClientId = null;
let allClients = [];
let filteredClients = [];
let allSedes = [];
let selectedClientIds = new Set();
let currentPage = 1;
const pageSize = 10;
let currentSortCol = 'fecha';
let currentSortAsc = false;

// Estado Difusión Masiva
let bcMode = 'directorio'; // 'directorio' | 'manual' | 'prueba'
let bcLineas = [];
let bcTemplates = [];
let bcIsRunning = false;
let bcShouldStop = false;

$(document).ready(function() {
    loadSedes();
    loadClients();

    // Botón Abrir Centro de Difusión Inteligente
    $('#btnOpenBroadcast').on('click', function() {
        openBroadcastModal();
    });

    // Limpiar selección de checkboxes
    $('#btnClearSelection').on('click', function() {
        selectedClientIds.clear();
        $('#chkSelectAllClients').prop('checked', false);
        $('.chk-client-row').prop('checked', false);
        updateSelectionUI();
    });

    // Seleccionar todos los filtrados
    $('#chkSelectAllClients').on('change', function() {
        const checked = $(this).is(':checked');
        filteredClients.forEach(c => {
            if (checked) {
                selectedClientIds.add(parseInt(c.id));
            } else {
                selectedClientIds.delete(parseInt(c.id));
            }
        });
        renderTablePage();
        updateSelectionUI();
    });

    // Filtro por Sede en el Directorio
    $('#filterDirSede').on('change', function() {
        currentPage = 1;
        applyFiltersAndRender();
    });

    // Eventos del Modal de Difusión
    $('#bcSedeSelect').on('change', function() {
        updateBroadcastPreview();
        updateBroadcastRecipientCounters();
    });

    $('#bcDirectoryScope').on('change', function() {
        updateBroadcastRecipientCounters();
    });

    $('#bcManualList').on('input', function() {
        updateBroadcastRecipientCounters();
    });

    $('#bcCsvInput').on('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(evt) {
            const content = evt.target.result || '';
            const current = $('#bcManualList').val().trim();
            $('#bcManualList').val(current ? (current + '\n' + content.trim()) : content.trim());
            updateBroadcastRecipientCounters();
        };
        reader.readAsText(file);
        $(this).val('');
    });

    $('#bcParam2, #bcParam3, #bcDefaultName, #bcTestName').on('input', function() {
        updateBroadcastPreview();
    });

    $('#btnStartBroadcast').on('click', function() {
        startBroadcastExecution();
    });

    $('#btnStopBroadcast').on('click', function() {
        bcShouldStop = true;
        $('#bcStatusLabel').removeClass('bg-primary bg-success').addClass('bg-warning text-dark').text('Deteniendo...');
    });

    // Botón Nuevo Cliente
    $('#btnAddClient').on('click', function() {
        currentClientId = null; // Modo creación
        
        $('#profTitleId').text('NUEVO CLIENTE');
        $('#profTitleName').text('Crear Ficha');
        $('#profAvatarImg').attr('src', 'https://ui-avatars.com/api/?name=Nuevo&background=E85B14&color=fff');
        
        $('#profName').val('').prop('disabled', false);
        $('#profSede').val('').prop('disabled', false);
        $('#profPrefix').prop('disabled', false).val('58414');
        $('#profPhone').prop('disabled', false).val('');
        $('#profAddress').val('').prop('disabled', false);
        $('#profNotes').val('').prop('disabled', false);
        
        $('#btnEditProfile').hide();
        $('#btnSaveProfile').html('<i class="fa-solid fa-user-plus me-2"></i>Crear Cliente').show();
        
        $('#profileConversationsFeed').html('<div class="empty-timeline"><div><i class="fa-solid fa-comments"></i></div><h5>Sin conversaciones</h5><p>Este cliente aún no tiene conversaciones registradas.</p></div>');
        $('#profileSalesFeed').html('<div class="empty-timeline"><div><i class="fa-solid fa-receipt"></i></div><h5>Sin ventas</h5><p>Este cliente aún no registra ventas ni compras.</p></div>');
        
        $('#profileModal').modal('show');
    });

    // Botón Editar Perfil
    $('#btnEditProfile').on('click', function() {
        $('#profName, #profSede, #profPrefix, #profPhone, #profAddress, #profNotes').prop('disabled', false);
        $(this).hide();
        $('#btnSaveProfile').html('<i class="fa-solid fa-floppy-disk me-2"></i>Guardar Cambios').show();
        $('#profName').focus();
    });

    $('#btnSaveProfile').on('click', function() {
        saveProfile();
    });

    // Búsqueda en tiempo real
    $('#searchClient').on('keyup', function() {
        currentPage = 1;
        applyFiltersAndRender();
    });

    // Paginación
    $('#btnPrevPage').on('click', function() {
        if (currentPage > 1) {
            currentPage--;
            renderTablePage();
        }
    });

    $('#btnNextPage').on('click', function() {
        const totalPages = Math.ceil(filteredClients.length / pageSize);
        if (currentPage < totalPages) {
            currentPage++;
            renderTablePage();
        }
    });

    // Ordenamiento por cabeceras
    $('th.sortable').on('click', function() {
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

function loadSedes() {
    $.ajax({
        url: 'back_directorio.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_sedes' },
        success: function(res) {
            if (res.status === 'success') {
                allSedes = res.data;
                let select = $('#profSede');
                let filterSelect = $('#filterDirSede');
                select.empty();
                filterSelect.html('<option value="">📍 Todas las Sedes</option>');
                
                if (res.is_master) {
                    select.append('<option value="">General / Central</option>');
                    select.prop('disabled', false).css({'background-color': '', 'cursor': ''});
                } else {
                    select.prop('disabled', true).css({'background-color': '#F1F5F9', 'cursor': 'not-allowed'});
                }

                allSedes.forEach(s => {
                    select.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
                    filterSelect.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
                });

                if (!res.is_master && allSedes.length > 0) {
                    select.val(allSedes[0].id);
                }
            }
        }
    });
}

function loadClients() {
    $.ajax({
        url: 'back_directorio.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_clients' },
        success: function(res) {
            if (res.status === 'success') {
                allClients = res.data;
                applyFiltersAndRender();
                updateSelectionUI();
            }
        }
    });
}

function applyFiltersAndRender() {
    let query = $('#searchClient').val().toLowerCase().trim();
    let sedeFilter = $('#filterDirSede').val();
    
    // 1. Filtrar
    filteredClients = allClients.filter(c => {
        if (sedeFilter && String(c.id_sede || '') !== String(sedeFilter)) {
            return false;
        }
        if (!query) return true;
        let name = c.nombre ? c.nombre.toLowerCase() : '';
        let phone = c.numero_whatsapp ? c.numero_whatsapp.toLowerCase() : '';
        let sede = c.nombre_sede ? c.nombre_sede.toLowerCase() : '';
        let tags = c.etiquetas ? c.etiquetas.toLowerCase() : '';
        return name.includes(query) || phone.includes(query) || sede.includes(query) || tags.includes(query);
    });

    // 2. Ordenar
    filteredClients.sort((a, b) => {
        let valA, valB;
        if (currentSortCol === 'nombre') {
            valA = a.nombre ? a.nombre.toLowerCase() : '';
            valB = b.nombre ? b.nombre.toLowerCase() : '';
        } else if (currentSortCol === 'telefono') {
            valA = a.numero_whatsapp || '';
            valB = b.numero_whatsapp || '';
        } else if (currentSortCol === 'sede') {
            valA = a.nombre_sede ? a.nombre_sede.toLowerCase() : '';
            valB = b.nombre_sede ? b.nombre_sede.toLowerCase() : '';
        } else if (currentSortCol === 'estado') {
            valA = a.estado || '';
            valB = b.estado || '';
        } else if (currentSortCol === 'fecha') {
            valA = new Date(a.fecha_registro).getTime();
            valB = new Date(b.fecha_registro).getTime();
        }

        if (valA < valB) return currentSortAsc ? -1 : 1;
        if (valA > valB) return currentSortAsc ? 1 : -1;
        return 0;
    });

    // 3. Renderizar Paginado
    renderTablePage();
}

function renderTablePage() {
    let tbody = $('#clientsTableBody');
    tbody.empty();
    
    let totalItems = filteredClients.length;
    let totalPages = Math.ceil(totalItems / pageSize) || 1;
    
    if (currentPage > totalPages) currentPage = totalPages;
    if (currentPage < 1) currentPage = 1;

    let startIndex = (currentPage - 1) * pageSize;
    let endIndex = startIndex + pageSize;
    let paginatedItems = filteredClients.slice(startIndex, endIndex);

    if (paginatedItems.length === 0) {
        tbody.append('<tr><td colspan="7" class="text-center text-muted p-5"><i class="fa-solid fa-users-slash fs-2 mb-3 d-block"></i>No se encontraron clientes.</td></tr>');
    } else {
        paginatedItems.forEach(c => {
            let cid = parseInt(c.id);
            let isChecked = selectedClientIds.has(cid) ? 'checked' : '';
            let displayName = c.nombre || 'Cliente sin nombre';

            let statusBadge = c.estado === 'ACTIVO' ? 
                '<span class="badge bg-success bg-opacity-10 text-success border border-success">Activo</span>' : 
                '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary">Inactivo</span>';
            
            let dateObj = new Date(c.fecha_registro);
            let formattedDate = isNaN(dateObj.getTime()) ? '-' : dateObj.toLocaleDateString();
            
            let sedeName = c.nombre_sede ? `<span class="fw-bold" style="color:#4a5568;">${c.nombre_sede}</span>` : '<span class="text-muted fst-italic">General</span>';

            let tagsHtml = '<span class="tag text-muted small">Sin tags</span>';
            if (c.etiquetas) {
                try {
                    let parsedTags = JSON.parse(c.etiquetas);
                    if (Array.isArray(parsedTags) && parsedTags.length > 0) {
                        tagsHtml = parsedTags.map(t => `<span class="badge bg-primary bg-opacity-10 text-primary border border-primary me-1">${t}</span>`).join('');
                    }
                } catch (e) {}
            }

            let tr = `
                <tr onclick="loadProfile(${c.id}, this)">
                    <td style="text-align: center;" onclick="event.stopPropagation();">
                        <input type="checkbox" class="form-check-input chk-client-row" data-id="${c.id}" ${isChecked} onchange="toggleClientSelection(${c.id}, this.checked)">
                    </td>
                    <td>
                        <div class="client-cell">
                            <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(displayName)}&background=F3F4F6&color=37414A" alt="Avatar">
                            <div class="client-cell-info">
                                <h6>${displayName}</h6>
                                <small>ID: CLI-${c.id}</small>
                            </div>
                        </div>
                    </td>
                    <td>+${c.numero_whatsapp}</td>
                    <td>${sedeName}</td>
                    <td>${statusBadge}</td>
                    <td>${tagsHtml}</td>
                    <td class="text-muted">${formattedDate}</td>
                </tr>
            `;
            tbody.append(tr);
        });
    }

    let displayEnd = Math.min(endIndex, totalItems);
    let displayStart = totalItems === 0 ? 0 : startIndex + 1;
    
    $('#pageInfo').text(`Mostrando ${displayStart} - ${displayEnd} de ${totalItems} clientes`);
    $('#btnPrevPage').prop('disabled', currentPage === 1);
    $('#btnNextPage').prop('disabled', currentPage === totalPages || totalItems === 0);
}

window.toggleClientSelection = function(id, checked) {
    const cid = parseInt(id);
    if (checked) {
        selectedClientIds.add(cid);
    } else {
        selectedClientIds.delete(cid);
        $('#chkSelectAllClients').prop('checked', false);
    }
    updateSelectionUI();
};

function updateSelectionUI() {
    const count = selectedClientIds.size;
    if (count > 0) {
        $('#selectedCountPill').text(count).show();
        $('#selectionSummaryText').html(`<strong class="text-primary">${count} contacto(s)</strong> seleccionado(s) para difusión`);
        $('#btnClearSelection').show();
    } else {
        $('#selectedCountPill').hide();
        $('#selectionSummaryText').text('Selecciona contactos o lanza una difusión por sede/lista');
        $('#btnClearSelection').hide();
    }
    updateBroadcastRecipientCounters();
}

// =========================================================================
// CENTRO DE DIFUSIÓN INTELIGENTE (META CLOUD API)
// =========================================================================

function openBroadcastModal() {
    if (selectedClientIds.size > 0) {
        $('#bcDirectoryScope').val('selected');
    } else {
        $('#bcDirectoryScope').val('sede');
    }
    loadBroadcastConfig();
    updateBroadcastPreview();
    $('#broadcastModal').modal('show');
}

window.loadBroadcastConfig = function() {
    $.ajax({
        url: 'back_directorio.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_broadcast_config' },
        success: function(res) {
            if (res.status === 'success') {
                bcLineas = res.lineas || [];
                bcTemplates = res.plantillas || [];

                // 1. Poblar selector de Sedes / Líneas Activas
                let sedeSel = $('#bcSedeSelect');
                let prevVal = sedeSel.val();
                sedeSel.empty();
                if (bcLineas.length === 0) {
                    sedeSel.append('<option value="">⚠️ Sin líneas de WhatsApp activas</option>');
                } else {
                    bcLineas.forEach(l => {
                        sedeSel.append(`<option value="${l.id_sede}" data-nombre="${l.nombre_sede}" data-phone="${l.numero_telefono}">${l.nombre_sede} (+${l.numero_telefono})</option>`);
                    });
                    if (prevVal && sedeSel.find(`option[value="${prevVal}"]`).length > 0) {
                        sedeSel.val(prevVal);
                    }
                }

                // 2. Poblar selector de Plantillas
                let tplSel = $('#bcTemplateSelect');
                tplSel.empty();
                bcTemplates.forEach(t => {
                    tplSel.append(`<option value="${t.name}" data-lang="${t.language || 'es'}">${t.name} (${t.language || 'es'})</option>`);
                });
                tplSel.val('starfi_difusion_directa');

                // 3. Poblar tabla de Historial de Campañas
                renderBroadcastHistory(res.campanas || []);

                updateBroadcastPreview();
                updateBroadcastRecipientCounters();
            }
        }
    });
};

window.setBroadcastRecipientMode = function(mode) {
    bcMode = mode;
    $('.mode-pill-btn').removeClass('active');
    $(`.mode-pill-btn[data-mode="${mode}"]`).addClass('active');

    $('#bcBoxDirectorio, #bcBoxManual, #bcBoxPrueba').hide();
    if (mode === 'directorio') $('#bcBoxDirectorio').fadeIn(150);
    if (mode === 'manual') $('#bcBoxManual').fadeIn(150);
    if (mode === 'prueba') $('#bcBoxPrueba').fadeIn(150);

    updateBroadcastPreview();
    updateBroadcastRecipientCounters();
};

window.applyBroadcastPreset = function(preset) {
    if (preset === 'inauguracion') {
        $('#bcCampaignName').val('Gran Inauguración Carpintero Express');
        $('#bcParam2').val('invitarte a la próxima inauguración de nuestra tienda Carpintero Express, donde tendremos grandes ofertas especiales de apertura para ti');
        $('#bcParam3').val('Carpintero Express estamos cada día más cerca de ti, somos tu parada obligatoria');
    } else if (preset === 'ofertas') {
        $('#bcCampaignName').val('Ofertas Especiales Carpintero Express');
        $('#bcParam2').val('compartirte nuestras ofertas especiales de la semana en tableros, herrajes y materiales disponibles para ti');
        $('#bcParam3').val('Carpintero Express estamos cada día más cerca de ti, somos tu parada obligatoria');
    }
    updateBroadcastPreview();
};

function sanitizePreviewParam(text, stripDot) {
    let clean = String(text || '').replace(/[\r\n\t]+/g, ' ').replace(/\s{2,}/g, ' ').trim();
    if (stripDot) {
        clean = clean.replace(/\.+$/, '').trim();
    }
    return clean;
}

function updateBroadcastPreview() {
    let selectedOption = $('#bcSedeSelect option:selected');
    let sedeNombre = selectedOption.data('nombre') || 'Carpintero Express';
    $('#waHeaderTitle').text(sedeNombre);
    $('#bcPreviewSedeBadge').text(sedeNombre);

    let sampleName = (bcMode === 'prueba') ? ($('#bcTestName').val().trim() || 'Julián') : ($('#bcDefaultName').val().trim() || 'Carlos');
    let p2 = sanitizePreviewParam($('#bcParam2').val(), true) || '...';
    let p3 = sanitizePreviewParam($('#bcParam3').val(), true) || '...';

    $('#bcParam2Counter').text(`${p2.length} caracteres`);

    let previewHtml = `👋¡Hola <strong>${sampleName}</strong>!\nHoy queremos ${p2}.\nRecuerda que en ${p3}.\n📲 <strong>Responde a este mensaje para obtener más información</strong>👇`;
    $('#waPreviewBodyText').html(previewHtml);
}

function normalizePhoneJS(raw) {
    let tel = String(raw || '').replace(/[^0-9]/g, '');
    if (tel.length === 11 && tel.startsWith('0')) {
        tel = '58' + tel.substring(1);
    } else if (tel.length === 13 && tel.startsWith('580')) {
        tel = '58' + tel.substring(3);
    } else if (tel.length === 10 && /^(414|424|412|416|426|2[0-9]{2})[0-9]{7}$/.test(tel)) {
        tel = '58' + tel;
    }
    return tel;
}

function isValidVzlaPhoneJS(tel) {
    return /^58(414|424|412|416|426|2[0-9]{2})[0-9]{7}$/.test(tel);
}

function getTargetRecipientsList() {
    const defaultName = $('#bcDefaultName').val().trim() || 'Amigo(a)';
    let list = [];
    let seenPhones = new Set();

    if (bcMode === 'prueba') {
        let name = $('#bcTestName').val().trim() || defaultName;
        let phone = normalizePhoneJS($('#bcTestPhone').val());
        if (isValidVzlaPhoneJS(phone)) {
            list.push({ nombre: name, telefono: phone });
        }
        return list;
    }

    if (bcMode === 'manual') {
        let rawLines = ($('#bcManualList').val() || '').split(/\r?\n/);
        rawLines.forEach(line => {
            let trimmed = line.trim();
            if (!trimmed) return;

            let name = defaultName;
            let phonePart = trimmed;

            // Soportar "Nombre, Telefono" o "Nombre; Telefono" o "Nombre \t Telefono"
            if (trimmed.includes(',') || trimmed.includes(';') || trimmed.includes('\t')) {
                let parts = trimmed.split(/[,;\t]+/);
                if (parts.length >= 2) {
                    // Detectar cuál columna tiene el teléfono
                    let p0 = normalizePhoneJS(parts[0]);
                    let p1 = normalizePhoneJS(parts[1]);
                    if (isValidVzlaPhoneJS(p1)) {
                        name = parts[0].trim() || defaultName;
                        phonePart = p1;
                    } else if (isValidVzlaPhoneJS(p0)) {
                        name = parts[1].trim() || defaultName;
                        phonePart = p0;
                    }
                }
            } else {
                phonePart = normalizePhoneJS(trimmed);
            }

            if (isValidVzlaPhoneJS(phonePart) && !seenPhones.has(phonePart)) {
                seenPhones.add(phonePart);
                list.push({ nombre: name, telefono: phonePart });
            }
        });
        return list;
    }

    // Modo 'directorio'
    let scope = $('#bcDirectoryScope').val();
    let targetSede = $('#bcSedeSelect').val();

    allClients.forEach(c => {
        if (c.estado && c.estado !== 'ACTIVO') return;
        let cid = parseInt(c.id);

        if (scope === 'selected' && !selectedClientIds.has(cid)) return;
        if (scope === 'sede' && String(c.id_sede || '') !== String(targetSede || '')) return;

        let phone = normalizePhoneJS(c.numero_whatsapp);
        if (!isValidVzlaPhoneJS(phone) || seenPhones.has(phone)) return;

        seenPhones.add(phone);
        let cleanName = (c.nombre && c.nombre !== 'Usuario' && c.nombre !== 'Cliente' && !/^\+?[0-9\s\-]+$/.test(c.nombre))
            ? c.nombre.trim().split(/\s+/)[0]
            : defaultName;

        list.push({ nombre: cleanName, telefono: phone });
    });

    return list;
}

function updateBroadcastRecipientCounters() {
    let recipients = getTargetRecipientsList();
    $('#bcDirCountBadge').text(`${recipients.length} destinatario(s) listos`);
    $('#bcManualCountBadge').text(`${recipients.length} número(s) válido(s)`);
    if (!bcIsRunning) {
        $('#bcMetricTotal').text(recipients.length);
    }
}

function appendBroadcastLog(htmlLine) {
    const logBox = $('#bcLiveLog');
    logBox.append(`<div>${htmlLine}</div>`);
    logBox.scrollTop(logBox[0].scrollHeight);
}

async function startBroadcastExecution() {
    if (bcIsRunning) return;

    const idSede = $('#bcSedeSelect').val();
    const templateName = $('#bcTemplateSelect').val() || 'starfi_difusion_directa';
    const langCode = $('#bcTemplateSelect option:selected').data('lang') || 'es';
    const param2 = sanitizePreviewParam($('#bcParam2').val(), true);
    const param3 = sanitizePreviewParam($('#bcParam3').val(), true);
    const campaignName = $('#bcCampaignName').val().trim() || ('Difusión ' + new Date().toLocaleDateString());

    if (!idSede) {
        Swal.fire('Atención', 'Selecciona una Sede con línea activa de WhatsApp.', 'warning');
        return;
    }
    if (!param2 || !param3) {
        Swal.fire('Atención', 'Completa las variables {{2}} (Cuerpo) y {{3}} (Eslogan) antes de enviar.', 'warning');
        return;
    }

    const recipients = getTargetRecipientsList();
    if (recipients.length === 0) {
        Swal.fire('Sin Destinatarios', 'No hay números de teléfono venezolanos válidos en la selección actual.', 'warning');
        return;
    }

    const confirmRes = await Swal.fire({
        title: '¿Iniciar Difusión Oficial?',
        html: `Se enviará la plantilla <b>${templateName}</b> a <b>${recipients.length} destinatario(s)</b> desde la sede seleccionada.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '🚀 Sí, enviar ahora',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#E85B14'
    });

    if (!confirmRes.isConfirmed) return;

    bcIsRunning = true;
    bcShouldStop = false;
    $('#btnStartBroadcast').prop('disabled', true);
    $('#btnStopBroadcast').show();
    $('#bcStatusLabel').removeClass('bg-secondary bg-success bg-danger').addClass('bg-primary').text('Enviando en vivo...');
    $('#bcProgressBar').css('width', '0%').removeClass('bg-danger').addClass('bg-success');
    $('#bcMetricTotal').text(recipients.length);
    $('#bcMetricSent').text('0');
    $('#bcMetricFailed').text('0');
    $('#bcLiveLog').empty();

    appendBroadcastLog(`<span class="text-info">[INICIO] Preparando envío a ${recipients.length} contacto(s)...</span>`);

    // 1. Registrar campaña en BD
    let campaignId = 0;
    try {
        const campRes = await $.ajax({
            url: 'back_directorio.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'create_broadcast_campaign',
                id_sede: idSede,
                nombre_campana: campaignName,
                template_name: templateName,
                param_2: param2,
                param_3: param3,
                total_destinatarios: recipients.length
            }
        });
        if (campRes.status === 'success') {
            campaignId = campRes.campaign_id;
        }
    } catch (e) {}

    let sentCount = 0;
    let failedCount = 0;

    // 2. Despachar uno por uno con pausa controlada (350ms)
    for (let i = 0; i < recipients.length; i++) {
        if (bcShouldStop) {
            appendBroadcastLog(`<span class="text-warning">[DETENIDO] Envío pausado por el usuario en el registro ${i + 1}/${recipients.length}.</span>`);
            break;
        }

        const item = recipients[i];
        try {
            const res = await $.ajax({
                url: 'back_directorio.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'send_broadcast_item',
                    campaign_id: campaignId,
                    id_sede: idSede,
                    template_name: templateName,
                    language_code: langCode,
                    nombre: item.nombre,
                    telefono: item.telefono,
                    param_2: param2,
                    param_3: param3
                }
            });

            if (res.status === 'success') {
                sentCount++;
                $('#bcMetricSent').text(sentCount);
                appendBroadcastLog(`<span class="text-success">✔ [${i + 1}/${recipients.length}] +${item.telefono} (${item.nombre}) — Entregado a Meta</span>`);
            } else {
                failedCount++;
                $('#bcMetricFailed').text(failedCount);
                appendBroadcastLog(`<span class="text-danger">✖ [${i + 1}/${recipients.length}] +${item.telefono} (${item.nombre}) — ${res.message || 'Fallo'}</span>`);
            }
        } catch (err) {
            failedCount++;
            $('#bcMetricFailed').text(failedCount);
            appendBroadcastLog(`<span class="text-danger">✖ [${i + 1}/${recipients.length}] +${item.telefono} — Error de red/servidor</span>`);
        }

        const pct = Math.round(((i + 1) / recipients.length) * 100);
        $('#bcProgressBar').css('width', `${pct}%`);

        if (i < recipients.length - 1 && !bcShouldStop) {
            await new Promise(r => setTimeout(r, 350));
        }
    }

    if (campaignId > 0) {
        $.post('back_directorio.php', {
            action: 'finish_broadcast_campaign',
            campaign_id: campaignId,
            estado: bcShouldStop ? 'CANCELADA' : 'COMPLETADA'
        });
    }

    bcIsRunning = false;
    $('#btnStartBroadcast').prop('disabled', false);
    $('#btnStopBroadcast').hide();
    $('#bcStatusLabel')
        .removeClass('bg-primary bg-warning text-dark')
        .addClass(bcShouldStop ? 'bg-warning text-dark' : 'bg-success')
        .text(bcShouldStop ? 'Campaña Detenida' : '¡Campaña Completada!');

    appendBroadcastLog(`<span class="text-warning fw-bold">[RESUMEN FINAL] Enviados: ${sentCount} | Fallidos: ${failedCount}</span>`);
    loadClients();
    loadBroadcastConfig();
}

function renderBroadcastHistory(campanas) {
    const tbody = $('#bcHistoryTableBody');
    tbody.empty();
    if (!campanas || campanas.length === 0) {
        tbody.append('<tr><td colspan="10" class="text-center py-4 text-muted">Aún no se han ejecutado campañas de difusión.</td></tr>');
        return;
    }

    campanas.forEach(c => {
        let badge = '<span class="badge bg-success">Completada</span>';
        if (c.estado === 'EN_PROCESO') badge = '<span class="badge bg-primary">En Proceso</span>';
        if (c.estado === 'CANCELADA') badge = '<span class="badge bg-warning text-dark">Cancelada</span>';

        tbody.append(`
            <tr>
                <td class="fw-bold">#${c.id}</td>
                <td class="small text-muted">${c.fecha_creacion}</td>
                <td class="fw-semibold">${c.nombre_campana}</td>
                <td><span class="badge bg-light text-dark border">${c.nombre_sede || 'Sede #' + c.id_sede}</span></td>
                <td><code>${c.template_name}</code></td>
                <td class="small text-truncate" style="max-width: 240px;" title="${c.param_cuerpo}">${c.param_cuerpo}</td>
                <td class="text-center fw-bold">${c.total_destinatarios}</td>
                <td class="text-center text-success fw-bold">${c.enviados}</td>
                <td class="text-center text-danger fw-bold">${c.fallidos}</td>
                <td>${badge}</td>
            </tr>
        `);
    });
}

function loadProfile(id, rowElement) {
    currentClientId = id;
    
    $('#clientsTableBody tr').removeClass('active');
    if (rowElement) $(rowElement).addClass('active');

    $.ajax({
        url: 'back_directorio.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_profile', id: id },
        success: function(res) {
            if (res.status === 'success') {
                const client = res.data.client;
                
                $('#profTitleName').text(client.nombre);
                $('#profTitleId').text(`ID: CLI-${client.id}`);
                $('#profAvatarImg').attr('src', `https://ui-avatars.com/api/?name=${encodeURIComponent(client.nombre)}&background=E85B14&color=fff`);

                $('#profName').val(client.nombre).prop('disabled', true);
                $('#profSede').val(client.id_sede || '').prop('disabled', true);
                $('#profAddress').val(client.direccion || '').prop('disabled', true);
                $('#profNotes').val(client.notas_internas || '').prop('disabled', true);
                
                let phoneStr = client.numero_whatsapp || '';
                let prefix = phoneStr.substring(0, 5);
                let num = phoneStr.substring(5);
                
                if ($(`#profPrefix option[value='${prefix}']`).length === 0) {
                    $('#profPrefix').append(`<option value="${prefix}">${prefix}</option>`);
                }
                $('#profPrefix').val(prefix).prop('disabled', true);
                $('#profPhone').val(num).prop('disabled', true);
                
                $('#btnEditProfile').show();
                $('#btnSaveProfile').hide();
                
                // 1. Render Historial de Conversaciones
                let convFeed = $('#profileConversationsFeed');
                convFeed.empty();
                
                let convs = res.data.conversaciones || [];
                if (convs.length === 0) {
                    convFeed.append('<div class="empty-timeline"><div><i class="fa-solid fa-comments"></i></div><h5>Sin conversaciones</h5><p>Este cliente aún no tiene conversaciones registradas.</p></div>');
                } else {
                    convs.forEach(c => {
                        let dateStr = c.fecha_inicio ? new Date(c.fecha_inicio).toLocaleString() : 'Sin fecha';
                        let badgeClass = c.estado === 'CERRADO' ? 'bg-secondary' : (c.estado === 'EN_CURSO' || c.estado === 'ATENDIENDO' ? 'bg-success' : 'bg-warning text-dark');
                        let resultadoHtml = c.resultado_comercial ? `<span class="badge bg-primary ms-2">${c.resultado_comercial}</span>` : '';
                        let msgPreview = c.ultimo_mensaje ? c.ultimo_mensaje : 'Sin mensajes';

                        let item = `
                            <div class="timeline-item">
                                <div class="timeline-icon icon-agent"><i class="fa-solid fa-comments"></i></div>
                                <div class="timeline-content">
                                    <span class="timeline-time">${dateStr}</span>
                                    <div class="d-flex align-items-center gap-1 mb-1">
                                        <span class="badge ${badgeClass}">${c.estado}</span>
                                        ${resultadoHtml}
                                    </div>
                                    <p class="timeline-text fw-bold text-starfi-dark mb-1">Asesor: ${c.agente_nombre}</p>
                                    <p class="timeline-text text-muted mb-2">${msgPreview}</p>
                                    <a href="../bandeja/bandeja.php?chat=${c.id}" class="btn btn-sm btn-outline-primary py-1 px-2" style="font-size: 0.8rem; border-radius: 6px;">
                                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Ir al Chat
                                    </a>
                                </div>
                            </div>
                        `;
                        convFeed.append(item);
                    });
                }

                // 2. Render Historial de Ventas
                let salesFeed = $('#profileSalesFeed');
                salesFeed.empty();
                
                let sales = res.data.ventas || [];
                if (sales.length === 0) {
                    salesFeed.append('<div class="empty-timeline"><div><i class="fa-solid fa-receipt"></i></div><h5>Sin ventas</h5><p>Este cliente aún no registra ventas ni compras.</p></div>');
                } else {
                    sales.forEach(v => {
                        let dateStr = v.timestamp ? new Date(v.timestamp).toLocaleString() : (v.fecha_cierre_venta ? new Date(v.fecha_cierre_venta).toLocaleString() : '');
                        let iconClass = 'icon-api';
                        let iconFa = 'fa-bolt';
                        
                        let item = `
                            <div class="timeline-item">
                                <div class="timeline-icon ${iconClass}" style="background-color: rgba(16, 185, 129, 0.1); color: #10B981;"><i class="fa-solid ${iconFa}"></i></div>
                                <div class="timeline-content">
                                    <span class="timeline-time">${dateStr}</span>
                                    <p class="timeline-text fw-bold text-success mb-1">${v.origen || 'VENTA'}</p>
                                    <p class="timeline-text text-muted">${v.contenido}</p>
                                </div>
                            </div>
                        `;
                        salesFeed.append(item);
                    });
                }
                
                $('#tab-conv-btn').tab('show');
                $('#profileModal').modal('show');
                $('.profile-data-col').scrollTop(0);
            }
        }
    });
}

function saveProfile() {
    const nombre = $('#profName').val().trim();
    const direccion = $('#profAddress').val().trim();
    const notas = $('#profNotes').val().trim();
    const id_sede = $('#profSede').val();
    const prefix = $('#profPrefix').val();
    const phone = $('#profPhone').val().trim();
    
    if(!phone || !nombre) {
        Swal.fire('Atención', 'El nombre y el número son obligatorios.', 'warning');
        return;
    }

    const full_whatsapp = prefix + phone;
    let action = currentClientId ? 'save_profile' : 'create_profile';
    let data = { 
        action: action, 
        nombre: nombre, 
        numero_whatsapp: full_whatsapp, 
        direccion: direccion, 
        notas: notas, 
        id_sede: id_sede 
    };
    
    if (currentClientId) {
        data.id = currentClientId;
    }
    
    if (currentClientId === null) {
        $.ajax({
            url: 'back_directorio.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'check_duplicate', numero_whatsapp: data.numero_whatsapp, id_sede: data.id_sede },
            success: function(res) {
                if (res.status === 'exists') {
                    Swal.fire({
                        title: 'Número ya registrado',
                        text: `El número ${data.numero_whatsapp} ya pertenece al cliente "${res.client.nombre}". ¿Deseas abrir su ficha para editarlo?`,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, abrir ficha',
                        cancelButtonText: 'No, cancelar',
                        confirmButtonColor: '#E85B14'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            $('#profileModal').modal('hide');
                            setTimeout(() => { loadProfile(res.client.id, null); }, 400);
                        }
                    });
                } else {
                    executeSaveProfile(data);
                }
            }
        });
    } else {
        executeSaveProfile(data);
    }
}

function executeSaveProfile(data) {
    $.ajax({
        url: 'back_directorio.php',
        type: 'POST',
        dataType: 'json',
        data: data,
        success: function(res) {
            if (res.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: '¡Guardado!',
                    text: 'Los datos del cliente se actualizaron correctamente.',
                    timer: 1500,
                    showConfirmButton: false
                });
                $('#profileModal').modal('hide');
                loadClients();
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}
