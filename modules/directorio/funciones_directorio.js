// modules/directorio/funciones_directorio.js

let currentClientId = null;
let allClients = [];
let filteredClients = [];
let allSedes = [];
let currentPage = 1;
const pageSize = 10;
let currentSortCol = 'fecha';
let currentSortAsc = false;

$(document).ready(function() {
    loadSedes();
    loadClients();

    // Botón Nuevo Cliente
    $('#btnAddClient').on('click', function() {
        currentClientId = null; // Modo creación
        
        // UI form reset
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
        
        // Empty feeds
        $('#profileConversationsFeed').html('<div class="empty-timeline"><div><i class="fa-solid fa-comments"></i></div><h5>Sin conversaciones</h5><p>Este cliente aún no tiene conversaciones registradas.</p></div>');
        $('#profileSalesFeed').html('<div class="empty-timeline"><div><i class="fa-solid fa-receipt"></i></div><h5>Sin ventas</h5><p>Este cliente aún no registra ventas ni compras.</p></div>');
        
        // Abrir panel (Modal)
        $('#profileModal').modal('show');
    });

    // Botón Editar Perfil
    $('#btnEditProfile').on('click', function() {
        $('#profName, #profSede, #profPrefix, #profPhone, #profAddress, #profNotes').prop('disabled', false);
        $(this).hide();
        $('#btnSaveProfile').html('<i class="fa-solid fa-floppy-disk me-2"></i>Guardar Cambios').show();
        
        // Enfocar el primer campo para llamar la atención del usuario
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
                select.empty();
                
                if (res.is_master) {
                    select.append('<option value="">General / Central</option>');
                    select.prop('disabled', false).css({'background-color': '', 'cursor': ''});
                } else {
                    // Si no es MASTER, deshabilitar selector de sede y fijar su única sede asignada
                    select.prop('disabled', true).css({'background-color': '#F1F5F9', 'cursor': 'not-allowed'});
                }

                allSedes.forEach(s => {
                    select.append(`<option value="${s.id}">${s.nombre_sede}</option>`);
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
        let sede = c.nombre_sede ? c.nombre_sede.toLowerCase() : '';
        return name.includes(query) || phone.includes(query) || sede.includes(query);
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

    if(paginatedItems.length === 0) {
        tbody.append('<tr><td colspan="6" class="text-center text-muted p-5"><i class="fa-solid fa-users-slash fs-2 mb-3 d-block"></i>No se encontraron clientes.</td></tr>');
    } else {
        paginatedItems.forEach(c => {
            let statusBadge = c.estado === 'ACTIVO' ? 
                '<span class="badge bg-success bg-opacity-10 text-success border border-success">Activo</span>' : 
                '<span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary">Inactivo</span>';
            
            let dateObj = new Date(c.fecha_registro);
            let formattedDate = dateObj.toLocaleDateString();
            
            let sedeName = c.nombre_sede ? `<span class="fw-bold" style="color:#4a5568;">${c.nombre_sede}</span>` : '<span class="text-muted fst-italic">General</span>';

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
                    <td>${sedeName}</td>
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

                // Update Form Fields (Deshabilitados por defecto)
                $('#profName').val(client.nombre).prop('disabled', true);
                $('#profSede').val(client.id_sede || '').prop('disabled', true);
                $('#profAddress').val(client.direccion || '').prop('disabled', true);
                $('#profNotes').val(client.notas_internas || '').prop('disabled', true);
                
                // Parse Phone Number
                let phoneStr = client.numero_whatsapp || '';
                let prefix = phoneStr.substring(0, 5);
                let num = phoneStr.substring(5);
                
                if ($(`#profPrefix option[value='${prefix}']`).length === 0) {
                    $('#profPrefix').append(`<option value="${prefix}">${prefix}</option>`);
                }
                $('#profPrefix').val(prefix).prop('disabled', true);
                $('#profPhone').val(num).prop('disabled', true);
                
                // Visibilidad de botones: Mostrar 'Editar Perfil', ocultar 'Guardar'
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
                
                // Reset tab to first tab (Conversaciones)
                $('#tab-conv-btn').tab('show');

                // Open Panel (Modal)
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
        // Es nuevo cliente, validamos anti-duplicados primero
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
