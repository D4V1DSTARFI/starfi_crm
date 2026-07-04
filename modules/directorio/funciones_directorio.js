// modules/directorio/funciones_directorio.js

let currentClientId = null;
let allClients = [];
let filteredClients = [];
let currentPage = 1;
const pageSize = 10;
let currentSortCol = 'fecha';
let currentSortAsc = false;

$(document).ready(function() {
    loadClients();

    // Botón Nuevo Cliente
    $('#btnAddClient').on('click', function() {
        currentClientId = null; // Modo creación
        
        // UI form reset
        $('#profTitleId').text('NUEVO CLIENTE');
        $('#profTitleName').text('Crear Ficha');
        $('#profAvatarImg').attr('src', 'https://ui-avatars.com/api/?name=Nuevo&background=E85B14&color=fff');
        
        $('#profName').val('');
        $('#profPrefix').prop('disabled', false).val('58414');
        $('#profPhone').prop('disabled', false).val('');
        $('#profAddress').val('');
        $('#profNotes').val('');
        
        $('#btnSaveProfile').text('Crear Cliente');
        
        // Empty timeline
        $('#profileTimeline').html('<div class="empty-timeline"><div><i class="fa-solid fa-folder-open"></i></div><h5>Sin historial</h5><p>Este cliente aún no tiene eventos registrados.</p></div>');
        
        // Abrir panel (Modal)
        $('#profileModal').modal('show');
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
            currentSortAsc = !currentSortAsc; // Toggle direction
        } else {
            currentSortCol = col;
            currentSortAsc = true;
        }
        
        // Actualizar iconos de cabecera
        $('th.sortable').removeClass('asc desc');
        $('th.sortable i').removeClass('fa-sort-up fa-sort-down').addClass('fa-sort');
        
        let icon = currentSortAsc ? 'fa-sort-up' : 'fa-sort-down';
        $(this).addClass(currentSortAsc ? 'asc' : 'desc');
        $(this).find('i').removeClass('fa-sort').addClass(icon);
        
        currentPage = 1;
        applyFiltersAndRender();
    });
});

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
            }
        }
    });
}

function applyFiltersAndRender() {
    let query = $('#searchClient').val().toLowerCase().trim();
    
    // 1. Filtrar
    filteredClients = allClients.filter(c => {
        let name = c.nombre ? c.nombre.toLowerCase() : '';
        let phone = c.numero_whatsapp ? c.numero_whatsapp.toLowerCase() : '';
        return name.includes(query) || phone.includes(query);
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

    if(paginatedItems.length === 0) {
        tbody.append('<tr><td colspan="5" class="text-center text-muted p-5"><i class="fa-solid fa-users-slash fs-2 mb-3 d-block"></i>No se encontraron clientes.</td></tr>');
    } else {
        paginatedItems.forEach(c => {
            let statusBadge = c.estado === 'ACTIVO' ? 
                '<span class="badge bg-success bg-opacity-10 text-success border border-success">Activo</span>' : 
                '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary">Inactivo</span>';
            
            let dateObj = new Date(c.fecha_registro);
            let formattedDate = dateObj.toLocaleDateString();

            let tr = `
                <tr onclick="loadProfile(${c.id}, this)">
                    <td>
                        <div class="client-cell">
                            <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(c.nombre)}&background=F3F4F6&color=37414A" alt="Avatar">
                            <div class="client-cell-info">
                                <h6>${c.nombre}</h6>
                                <small>ID: CLI-${c.id}</small>
                            </div>
                        </div>
                    </td>
                    <td>+${c.numero_whatsapp}</td>
                    <td>${statusBadge}</td>
                    <td><span class="tag text-muted">Sin tags</span></td>
                    <td class="text-muted">${formattedDate}</td>
                </tr>
            `;
            tbody.append(tr);
        });
    }

    // Actualizar botones y texto de paginación
    let displayEnd = Math.min(endIndex, totalItems);
    let displayStart = totalItems === 0 ? 0 : startIndex + 1;
    
    $('#pageInfo').text(`Mostrando ${displayStart} - ${displayEnd} de ${totalItems} clientes`);
    $('#btnPrevPage').prop('disabled', currentPage === 1);
    $('#btnNextPage').prop('disabled', currentPage === totalPages || totalItems === 0);
}

function loadProfile(id, rowElement) {
    currentClientId = id;
    
    // UI active state
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
                
                // Update UI Avatar & Title
                $('#profTitleName').text(client.nombre);
                $('#profTitleId').text(`ID: CLI-${client.id}`);
                $('#profAvatarImg').attr('src', `https://ui-avatars.com/api/?name=${encodeURIComponent(client.nombre)}&background=E85B14&color=fff`);

                // Update Form Fields
                $('#profName').val(client.nombre);
                $('#profAddress').val(client.direccion || '');
                $('#profNotes').val(client.notas_internas || '');
                
                // Parse Phone Number
                let phoneStr = client.numero_whatsapp || '';
                let prefix = phoneStr.substring(0, 5);
                let num = phoneStr.substring(5);
                
                if ($(`#profPrefix option[value='${prefix}']`).length === 0) {
                    $('#profPrefix').append(`<option value="${prefix}">${prefix}</option>`);
                }
                $('#profPrefix').val(prefix).prop('disabled', true);
                $('#profPhone').val(num).prop('disabled', true);
                
                $('#btnSaveProfile').text('Guardar Cambios');
                
                // Update Timeline
                let timeline = $('#profileTimeline');
                timeline.empty();
                
                if (res.data.events.length === 0) {
                    timeline.append('<div class="empty-timeline"><div><i class="fa-solid fa-folder-open"></i></div><h5>Sin historial</h5><p>Este cliente aún no tiene eventos registrados.</p></div>');
                } else {
                    res.data.events.forEach(ev => {
                        let iconClass = ev.origen === 'BOT' ? 'icon-bot' : (ev.origen === 'API_TRANSACCIONAL' ? 'icon-api' : 'icon-agent');
                        let iconFa = ev.origen === 'BOT' ? 'fa-robot' : (ev.origen === 'API_TRANSACCIONAL' ? 'fa-bolt' : 'fa-info');
                        
                        let dateStr = new Date(ev.timestamp).toLocaleString();

                        let item = `
                            <div class="timeline-item">
                                <div class="timeline-icon ${iconClass}"><i class="fa-solid ${iconFa}"></i></div>
                                <div class="timeline-content">
                                    <span class="timeline-time">${dateStr}</span>
                                    <p class="timeline-text fw-bold text-starfi-dark">${ev.origen}</p>
                                    <p class="timeline-text text-muted">${ev.contenido}</p>
                                </div>
                            </div>
                        `;
                        timeline.append(item);
                    });
                }
                
                // Open Panel (Modal)
                $('#profileModal').modal('show');
            }
        }
    });
}

function saveProfile() {
    const nombre = $('#profName').val().trim();
    const direccion = $('#profAddress').val().trim();
    const notas = $('#profNotes').val().trim();
    
    let action = currentClientId ? 'save_profile' : 'create_profile';
    let data = { action: action, nombre: nombre, direccion: direccion, notas: notas };
    
    if (currentClientId) {
        data.id = currentClientId;
    } else {
        const prefix = $('#profPrefix').val();
        const phone = $('#profPhone').val().trim();
        if(!phone || !nombre) {
            Swal.fire('Atención', 'El nombre y el número son obligatorios.', 'warning');
            return;
        }
        data.numero_whatsapp = prefix + phone;
    }
    
    if (currentClientId === null) {
        // Es nuevo cliente, validamos anti-duplicados primero
        $.ajax({
            url: 'back_directorio.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'check_duplicate', numero_whatsapp: data.numero_whatsapp },
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
                    // No está duplicado, procedemos a crear
                    executeSaveProfile(data);
                }
            }
        });
    } else {
        // Es una edición, procedemos directo
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
                loadClients(); // Reload list
            } else {
                Swal.fire('Error', res.message, 'error');
            }
        }
    });
}
