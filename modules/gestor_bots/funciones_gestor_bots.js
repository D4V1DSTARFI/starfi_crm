let allRules = [];
let filteredRules = [];
let currentPage = 1;
const pageSize = 10;
let currentSortCol = 'disparador';
let currentSortAsc = true;

$(document).ready(function() {
    loadBotRules();

    // Toggle de Botones Interactivos (Meta API)
    $('#enableButtons').on('change', function() {
        if ($(this).is(':checked')) {
            $('#buttonsContainer').slideDown();
        } else {
            $('#buttonsContainer').slideUp();
            $('#buttonsList input').val(''); // Limpiar los inputs
        }
    });
    
    // Limpiar al cerrar modal
    $('#botModal').on('hidden.bs.modal', function () {
        $('#buttonsContainer').hide();
        $('#enableButtons').prop('checked', false);
        $('#buttonsList input').val('');
    });

    // Búsqueda en tiempo real
    $('#searchRule').on('keyup', function() {
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
        const totalPages = Math.ceil(filteredRules.length / pageSize);
        if (currentPage < totalPages) {
            currentPage++;
            renderTablePage();
        }
    });

    // Ordenamiento por cabeceras
    $('th.sortable').on('click', function() {
        const col = $(this).data('sort');
        if (currentSortCol === col) {
            currentSortAsc = !currentSortAsc; // Toggle direction
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

function loadBotRules() {
    let selectObj = $('#sedeFilter');
    let idSede = selectObj.val();
    
    if (!idSede || idSede === "0") {
        $('#botToggleContainer').hide();
        $('#botContentContainer').hide();
        return;
    }
    
    // Set current bot status
    let botActivo = selectObj.find('option:selected').data('bot-activo');
    $('#botStatusToggle').prop('checked', botActivo == 1);
    
    $('#botToggleContainer').css('display', 'flex');
    $('#botContentContainer').fadeIn();

    $.ajax({
        url: 'back_gestor_bots.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_rules', id_sede: idSede },
        success: function(response) {
            if (response.status === 'success') {
                allRules = response.data;
                applyFiltersAndRender();
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        }
    });
}

function toggleBotStatus() {
    let idSede = $('#sedeFilter').val();
    let isActive = $('#botStatusToggle').is(':checked') ? 1 : 0;
    
    $.ajax({
        url: 'back_gestor_bots.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'toggle_bot', id_sede: idSede, status: isActive },
        success: function(response) {
            if (response.status === 'success') {
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
                $('#botStatusToggle').prop('checked', !isActive);
                Swal.fire('Error', response.message, 'error');
            }
        }
    });
}

function applyFiltersAndRender() {
    let query = $('#searchRule').val().toLowerCase().trim();
    
    // Filtrar
    filteredRules = allRules.filter(r => {
        let disp = r.disparador ? r.disparador.toLowerCase() : '';
        let msg = r.mensaje ? r.mensaje.toLowerCase() : '';
        return disp.includes(query) || msg.includes(query);
    });

    // Ordenar
    filteredRules.sort((a, b) => {
        let valA, valB;
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

    if(paginatedItems.length === 0) {
        tbody.append('<tr><td colspan="5" class="text-center text-muted p-5"><i class="fa-solid fa-robot fs-2 mb-3 d-block"></i>No se encontraron reglas.</td></tr>');
    } else {
        paginatedItems.forEach(rule => {
            let tipoBadge = '';
            if (rule.tipo === 'EVENTO_SISTEMA') {
                tipoBadge = '<span class="badge bg-secondary">Evento</span>';
            } else if (rule.tipo === 'CIERRE_CSAT') {
                tipoBadge = '<span class="badge bg-danger"><i class="fa-solid fa-star me-1"></i>CSAT & Cierre</span>';
            } else {
                tipoBadge = '<span class="badge bg-primary">Palabra Clave</span>';
            }
                
            let estadoBadge = rule.estado === 'ACTIVO'
                ? '<span class="badge bg-success">Activo</span>'
                : '<span class="badge bg-light text-dark border">Inactivo</span>';

            let html = `
                <tr>
                    <td style="padding-left: 24px;">${tipoBadge}</td>
                    <td class="fw-bold">${rule.disparador}</td>
                    <td><small class="text-muted text-wrap" style="max-width: 300px; display: block;">${rule.mensaje}</small></td>
                    <td>${estadoBadge}</td>
                    <td style="text-align: right; padding-right: 24px;">
                        <button class="btn btn-sm btn-light text-primary me-1" onclick="editBotRule(${rule.id}, '${rule.tipo}', '${rule.disparador}', '${rule.mensaje.replace(/'/g, "\\'").replace(/\n/g, "\\n")}', '${rule.estado}')">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <button class="btn btn-sm btn-light text-danger" onclick="deleteBotRule(${rule.id})">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
            tbody.append(html);
        });
    }

    // Actualizar botones de paginación
    let displayEnd = Math.min(endIndex, totalItems);
    let displayStart = totalItems === 0 ? 0 : startIndex + 1;
    
    $('#pageInfo').text(`Mostrando ${displayStart} - ${displayEnd} de ${totalItems} reglas`);
    $('#btnPrevPage').prop('disabled', currentPage === 1);
    $('#btnNextPage').prop('disabled', currentPage === totalPages || totalItems === 0);
}

function openBotModal() {
    let idSede = $('#sedeFilter').val();
    if (!idSede || idSede === "0") {
        Swal.fire('Atención', 'Por favor, selecciona una sede en el menú desplegable primero.', 'warning');
        return;
    }
    $('#botForm')[0].reset();
    $('#ruleId').val('0');
    $('#botModalTitle').text('Nueva Respuesta Automática');
    
    if(!botModalObj) botModalObj = new bootstrap.Modal(document.getElementById('botModal'));
    botModalObj.show();
}

function editBotRule(id, tipo, disparador, mensaje, estado) {
    $('#ruleId').val(id);
    $('#ruleType').val(tipo);
    $('#ruleTrigger').val(disparador);
    $('#ruleMessage').val(mensaje);
    $('#ruleState').val(estado);
    $('#botModalTitle').text('Editar Respuesta Automática');
    
    if(!botModalObj) botModalObj = new bootstrap.Modal(document.getElementById('botModal'));
    botModalObj.show();
}

function saveBotRule() {
    const data = {
        action: 'save_rule',
        id: $('#ruleId').val(),
        id_sede: $('#sedeFilter').val(),
        tipo: $('#ruleType').val(),
        disparador: $('#ruleTrigger').val(),
        mensaje: $('#ruleMessage').val(),
        estado: $('#ruleState').val()
    };

    if (!data.disparador || !data.mensaje) {
        Swal.fire('Atención', 'El disparador y el mensaje son obligatorios.', 'warning');
        return;
    }

    $.ajax({
        url: 'back_gestor_bots.php',
        type: 'POST',
        dataType: 'json',
        data: data,
        success: function(response) {
            if (response.status === 'success') {
                botModalObj.hide();
                Swal.fire({
                    icon: 'success',
                    title: '¡Guardado!',
                    text: response.message,
                    timer: 1500,
                    showConfirmButton: false
                });
                loadBotRules();
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        }
    });
}

function deleteBotRule(id) {
    Swal.fire({
        title: '¿Estás seguro?',
        text: "¡No podrás revertir esto!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'back_gestor_bots.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'delete_rule', id: id },
                success: function(response) {
                    if (response.status === 'success') {
                        loadBotRules();
                        Swal.fire('Eliminada', 'La regla ha sido eliminada.', 'success');
                    } else {
                        Swal.fire('Error', response.message, 'error');
                    }
                }
            });
        }
    });
}


// ==========================================
// GESTION DE VENDEDORES (CONTACTOS)
// ==========================================
let modalContactos, modalContactoForm;

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('modalContactos')) {
        modalContactos = new bootstrap.Modal(document.getElementById('modalContactos'));
        modalContactoForm = new bootstrap.Modal(document.getElementById('modalContactoForm'));
        
        document.getElementById('contactoForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'save_contacto');
            formData.append('id_sede', document.getElementById('sedeFilter').value);
            
            fetch('back_gestor_bots.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if (res.status === 'success') {
                    showToast('Éxito', res.message, 'success');
                    modalContactoForm.hide();
                    loadContactos();
                } else {
                    showToast('Error', res.message, 'danger');
                }
            })
            .catch(err => {
                showToast('Error', 'Error de red', 'danger');
            });
        });
    }
});

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
        if (res.status === 'success') {
            const tbody = document.getElementById('contactosTableBody');
            tbody.innerHTML = '';
            if (res.data.length === 0) {
                tbody.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-4">No hay vendedores registrados.</td></tr>`;
                return;
            }
            res.data.forEach(c => {
                const badgeClass = c.estado === 'ACTIVO' ? 'bg-success' : 'bg-danger';
                tbody.innerHTML += `
                    <tr>
                        <td class="fw-bold text-dark">${c.nombre}</td>
                        <td>${c.telefono}</td>
                        <td><span class="badge ${badgeClass}">${c.estado}</span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-light text-primary me-2" onclick='editContacto(${JSON.stringify(c)})'><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-sm btn-light text-danger" onclick="deleteContacto(${c.id})"><i class="fa-solid fa-trash"></i></button>
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
    modalContactoForm.show();
}

function editContacto(c) {
    document.getElementById('contacto_id').value = c.id;
    document.getElementById('contacto_nombre').value = c.nombre;
    document.getElementById('contacto_telefono').value = c.telefono;
    document.getElementById('contacto_estado').value = c.estado;
    modalContactoForm.show();
}

function deleteContacto(id) {
    if (!confirm('¿Estás seguro de eliminar este vendedor?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_contacto');
    fd.append('id', id);

    fetch('back_gestor_bots.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            showToast('Eliminado', res.message, 'success');
            loadContactos();
        } else {
            showToast('Error', res.message, 'danger');
        }
    });
}