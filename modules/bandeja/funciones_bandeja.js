// modules/bandeja/funciones_bandeja.js

let activeChatId = null;
let activeClientId = null;
let currentFilter = 'todos';

$(document).ready(function () {
    // --- Sidebar Toggle ---
    $('#toggleSidebar').on('click', function() {
        $('#sidebar').toggleClass('collapsed');
    });

    // Interceptar errores de AJAX globales
    $(document).ajaxError(function (event, jqxhr, settings, thrownError) {
        if (jqxhr.status === 401) {
            try {
                let res = JSON.parse(jqxhr.responseText);
                window.location.href = res.redirect || '/starfi_crm/login.php';
            } catch (e) {
                window.location.href = '/starfi_crm/login.php';
            }
        }
    });

    loadChats();

    loadChats();

    // Tiempo Real con Server-Sent Events (SSE)
    function iniciarSSE() {
        const evtSource = new EventSource("sse_updates.php");

        evtSource.onmessage = function (event) {
            const data = JSON.parse(event.data);
            if (data.type === 'update') {
                if (activeChatId !== null && activeChatId !== 0) {
                    loadMessages(activeChatId, false);
                } else {
                    loadChats();
                }
            } else if (data.type === 'reconnect') {
                evtSource.close();
                iniciarSSE(); // Reconectar
            }
        };

        evtSource.onerror = function () {
            evtSource.close();
            setTimeout(iniciarSSE, 5000); // Reintentar en 5 seg
        };
    }

    iniciarSSE();

    $('#sendBtn').on('click', function () {
        sendMessage();
    });

    $('#chatInput').on('keypress', function (e) {
        if (e.which === 13 && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Búsqueda en tiempo real
    $('.search-bar input').on('keyup', function () {
        let val = $(this).val().toLowerCase();
        $('#chatList .chat-item').filter(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(val) > -1)
        });
    });

    // 1. Emoji Picker
    try {
        const picker = new EmojiButton({
            position: 'top-start'
        });
        const trigger = document.querySelector('#btnEmoji');
        if (trigger) {
            picker.on('emoji', selection => {
                const input = document.querySelector('#chatInput');
                input.value += selection.emoji;
                input.focus();
            });
            trigger.addEventListener('click', () => picker.togglePicker(trigger));
        }
    } catch (error) {
        console.warn("EmojiButton no disponible:", error);
        $('#btnEmoji').on('click', function () {
            Swal.fire({ icon: 'info', title: 'Próximamente', text: 'El panel de emojis requiere conexión externa.' });
        });
    }

    // 2. Templates
    $('#btnTemplates').on('click', function () {
        if (!activeChatId) {
            Swal.fire({ icon: 'warning', text: 'Selecciona una conversación primero.' });
            return;
        }
        $('#modalTemplates').modal('show');
    });

    window.selectTemplate = function (text) {
        $('#chatInput').val(text);
        $('#modalTemplates').modal('hide');
        $('#chatInput').focus();
    };

    // 3. Attachments
    $('#btnAttach').on('click', function () {
        if (!activeChatId && !activeClientId) {
            Swal.fire({ icon: 'warning', text: 'Selecciona una conversación primero.' });
            return;
        }
        $('#fileInput').click();
    });

    $('#fileInput').on('change', function () {
        let file = this.files[0];
        if (!file) return;

        let formData = new FormData();
        formData.append('action', 'upload_media');
        formData.append('conversacion_id', activeChatId || 0);
        formData.append('cliente_id', activeClientId || 0);
        formData.append('file', file);

        // UI loading
        const area = $('#messagesArea');
        area.append(`
            <div class="message bot-message" style="align-self: flex-end; background-color: #EFF6FF; border: 1px solid #BFDBFE;">
                <div class="msg-bubble" style="background-color: transparent; border:none; padding-bottom:5px;">
                    <p style="margin-bottom:0;"><i class="fa-solid fa-spinner fa-spin"></i> Subiendo archivo...</p>
                </div>
            </div>
        `);
        area.scrollTop(area[0].scrollHeight);

        $.ajax({
            url: 'back_bandeja.php',
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            dataType: 'json',
            success: function (response) {
                if (response.status === 'success') {
                    if (activeChatId == 0 && response.new_chat_id) {
                        activeChatId = response.new_chat_id;
                        $('.chat-item.active').attr('data-id', activeChatId);
                    }
                    loadMessages(activeChatId, true);
                } else {
                    Swal.fire('Error', response.message || 'Error desconocido', 'error');
                    loadMessages(activeChatId, true);
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo al subir archivo', 'error');
                loadMessages(activeChatId, true);
            }
        });

        $(this).val(''); // reset
    });

    // Delegación de eventos para la lista de chats dinámica (Respaldo)
    $('#chatList').on('click', '.chat-item', function () {
        const id = $(this).data('id');
        const cliente_id = $(this).data('cliente-id');
        const name = $(this).data('name');
        const phone = $(this).data('phone');
        const sede = $(this).data('sede');
        selectChat(id, cliente_id, name, phone, sede);
    });

    // Función global para clics móviles
    window.clickChat = function (element) {
        const id = $(element).data('id');
        const cliente_id = $(element).data('cliente-id');
        const name = $(element).data('name');
        const phone = $(element).data('phone');
        const sede = $(element).data('sede');
        selectChat(id, cliente_id, name, phone, sede);
    };

    // 1. Filtros de pestañas
    $('.tabs .tab').on('click', function () {
        $('.tabs .tab').removeClass('active');
        $(this).addClass('active');
        currentFilter = $(this).data('target');

        // No reseteamos el chat activo (a petición del usuario)
        // Solo recargamos la lista de chats de la izquierda
        loadChats();
    });

    // 2. Perfil 360
    $('#btnToggleProfile').on('click', function () {
        if (!activeClientId) {
            Swal.fire({ icon: 'warning', text: 'Selecciona una conversación primero.' });
            return;
        }
        $('#modalProfile360').modal('show');
        loadProfile360();
    });

    // 3. Cerrar Chat
    $('#btnCloseChat').on('click', function () {
        if (!activeChatId || activeChatId === 0) return;
        Swal.fire({
            title: '¿Cerrar Conversación?',
            text: "El chat se marcará como resuelto.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#E85B14',
            confirmButtonText: 'Sí, cerrar'
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'back_bandeja.php', type: 'POST', dataType: 'json',
                    data: { action: 'close_chat', conversacion_id: activeChatId },
                    success: function (res) {
                        if (res.status === 'success') {
                            activeChatId = null;
                            loadChats();
                            $('#activeChatView').hide();
                            $('#emptyState').removeClass('d-none').css('display', 'flex');
                            $('#modalProfile360').modal('hide');
                            Swal.fire('Cerrado', '', 'success');
                        }
                    }
                });
            }
        });
    });

    // 4. Reasignar Chat
    $('#btnReasign').on('click', function () {
        if (!activeChatId || activeChatId === 0) return;

        // Fetch agents first
        $.ajax({
            url: 'back_bandeja.php', type: 'POST', dataType: 'json',
            data: { action: 'get_agents' },
            success: function (res) {
                if (res.status === 'success') {
                    let options = {};
                    res.data.forEach(ag => { options[ag.id] = ag.nombre_completo; });

                    Swal.fire({
                        title: 'Reasignar Conversación',
                        input: 'select',
                        inputOptions: options,
                        inputPlaceholder: 'Selecciona un Agente',
                        showCancelButton: true,
                        confirmButtonText: 'Reasignar',
                        inputValidator: (value) => {
                            return new Promise((resolve) => {
                                if (value) resolve();
                                else resolve('Debes seleccionar un agente');
                            });
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            $.ajax({
                                url: 'back_bandeja.php', type: 'POST', dataType: 'json',
                                data: { action: 'reassign_chat', conversacion_id: activeChatId, nuevo_agente_id: result.value },
                                success: function (res2) {
                                    if (res2.status === 'success') {
                                        activeChatId = null;
                                        loadChats();
                                        $('#activeChatView').hide();
                                        $('#emptyState').removeClass('d-none').css('display', 'flex');
                                        $('#modalProfile360').modal('hide');
                                        Swal.fire('Reasignado', 'La conversación pasó a la cola del otro agente', 'success');
                                    }
                                }
                            });
                        }
                    });
                }
            }
        });
    });
});

function loadChats() {
    $.ajax({
        url: 'back_bandeja.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_chats', filter: currentFilter },
        success: function (response) {
            if (response.status === 'success') {
                renderChatList(response.data);
            } else {
                $('#chatList').html(`<div class="p-3 text-danger text-center"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${response.message}</div>`);
            }
        },
        error: function (xhr, status, error) {
            if (xhr.status === 401) return; // Se maneja globalmente
            console.error("AJAX Error:", status, error, xhr.responseText);
            let resp = xhr.responseText ? xhr.responseText.replace(/</g, '&lt;').substring(0, 200) : error;
            $('#chatList').html(`<div class="p-3 text-danger text-center" style="word-break: break-word;"><i class="fa-solid fa-database d-block mb-2 fs-3"></i> <b>Error de Servidor:</b><br><small>${resp}</small></div>`);
        }
    });
}

function renderChatList(chats) {
    const list = $('#chatList');

    let totalUnread = 0;

    if (chats.length === 0) {
        list.html('<div class="p-4 text-center text-muted" style="font-size:0.85rem;"><i class="fa-solid fa-mug-hot fs-3 mb-2 d-block"></i> No hay conversaciones aquí.</div>');
        $('#badgeNoLeidos').hide();
        return;
    }

    list.empty();
    chats.forEach(chat => {
        let name = chat.cliente_nombre ? chat.cliente_nombre : chat.numero_whatsapp;
        let badge = '';
        // Eliminamos las etiquetas "En Espera" y "Nuevo" para no confundir,
        // ya que ahora nos guiamos por el punto naranja de No Leídos.

        let unreadDot = '';
        if (chat.no_leidos > 0) {
            totalUnread++;
            unreadDot = `<div style="width:10px;height:10px;background:#e85b14;border-radius:50%;margin-left:auto;"></div>`;
        }

        let isActiveClass = (chat.id == activeChatId && chat.id_cliente == activeClientId) ? 'active' : '';

        let html = `
            <article class="chat-item ${isActiveClass}" onclick="window.clickChat(this)" style="cursor: pointer;" data-id="${chat.id}" data-cliente-id="${chat.id_cliente}" data-name="${name.replace(/"/g, '&quot;')}" data-phone="${chat.numero_whatsapp}" data-sede="${chat.nombre_sede || ''}">
                <div class="chat-avatar">
                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F3F4F6&color=37414A" alt="Avatar">
                </div>
                <div class="chat-summary">
                    <div class="chat-top">
                        <h4>${name}</h4>
                        <span class="time">${formatTime(chat.fecha_inicio)}</span>
                    </div>
                    <div style="font-size: 0.75rem; margin-top: 2px; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                        <span class="badge bg-dark rounded-pill px-2 py-0.5 text-white" style="font-size: 0.65rem; background-color: #37414A !important; font-family: var(--font-heading); font-weight: 500;"><i class="fa-solid fa-store me-1"></i> ${chat.nombre_sede || 'Sede Principal'}</span>
                    </div>
                    <div class="chat-bottom" style="display:flex; align-items:center;">
                        <p class="preview" style="margin-right:10px;">Haz clic para ver la conversación</p>
                        ${badge}
                        ${unreadDot}
                    </div>
                </div>
            </article>
        `;
        list.append(html);
    });

    if (currentFilter === 'no-leido') {
        if (totalUnread > 0) $('#badgeNoLeidos').text(totalUnread).show();
        else $('#badgeNoLeidos').hide();
    }
}

function selectChat(id, cliente_id, name, phone, nombre_sede) {
    activeChatId = id;
    activeClientId = cliente_id;
    $('.chat-item').removeClass('active');

    // Si el chat es nuevo (id = 0), seleccionamos por ID de cliente para evitar seleccionar varios
    if (id === 0) {
        $(`.chat-item[data-cliente-id="${cliente_id}"]`).addClass('active');
    } else {
        $(`.chat-item[data-id="${id}"]`).addClass('active');
    }

    // Switch views
    $('#emptyState').hide();
    $('#activeChatView').css('display', 'flex');

    // Update Header
    $('#chatHeaderName').text(name);
    $('#chatHeaderPhone').text(`+${phone}`);
    $('#chatHeaderImg').attr('src', `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F3F4F6&color=37414A`);

    if (nombre_sede) {
        $('#chatHeaderSede span').text(nombre_sede);
        $('#chatHeaderSede').css('display', 'inline-block');
    } else {
        $('#chatHeaderSede').hide();
    }

    // If profile is open, update it
    if ($('#modalProfile360').hasClass('show')) {
        loadProfile360();
    }

    loadMessages(id, true);
}

function loadProfile360() {
    $.ajax({
        url: 'back_bandeja.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_profile', cliente_id: activeClientId },
        success: function (res) {
            if (res.status === 'success') {
                const client = res.data;
                let cName = client.nombre || client.numero_whatsapp;
                $('#profPrevName').text(cName);
                $('#profPrevPhone').text('+' + client.numero_whatsapp);
                $('#profPrevImg').attr('src', `https://ui-avatars.com/api/?name=${encodeURIComponent(cName)}&background=F3F4F6&size=128`);
            }
        }
    });
}

function loadMessages(id, scrollToBottom = true) {
    $.ajax({
        url: 'back_bandeja.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_messages', conversacion_id: id },
        success: function (response) {
            if (response.status === 'success') {
                renderMessages(response.data, scrollToBottom);
                // Si el chat tenía un punto naranja (no leído), recargamos la lista para limpiarlo
                if (currentFilter === 'no-leido' || $('#chatList').find('.chat-item[data-id="' + id + '"] div[style*="background:#e85b14"]').length > 0) {
                    loadChats();
                }
            }
        },
        error: function (xhr, status, error) {
            Swal.fire('Error', 'No se pudieron cargar los mensajes. Servidor no responde.', 'error');
        }
    });
}

function renderMessages(messages, scrollToBottom) {
    const area = $('#messagesArea');
    area.empty();
    if (messages.length === 0) {
        area.append('<div class="text-center text-muted mt-5">Inicia la conversación.</div>');
        return;
    }
    messages.forEach(msg => {
        let msgHtml = '';
        let timeLabel = formatTime(msg.timestamp);

        if (msg.origen === 'CLIENTE') {
            msgHtml = `
                <div class="message client-message">
                    <div class="msg-bubble">
                        <p>${msg.contenido}</p>
                        <span class="msg-time">${timeLabel}</span>
                    </div>
                </div>
            `;
        } else if (msg.origen === 'BOT' || msg.origen === 'EVENTO_SISTEMA') {
            if (msg.tipo === 'CONTACTO') {
                // Parse contact
                let contactName = msg.contenido.substring(18, msg.contenido.indexOf('(')).trim();
                let contactPhone = msg.contenido.substring(msg.contenido.indexOf('(') + 1, msg.contenido.indexOf(')'));

                msgHtml = `
                <div class="message bot-message" style="align-self: flex-end; background-color: white; border: 1px solid #E5E7EB; width: 250px;">
                    <div class="msg-bubble" style="background-color: transparent; border:none; padding: 10px;">
                        <div style="display:flex; align-items:center; border-bottom:1px solid #eee; padding-bottom:10px; margin-bottom:10px;">
                            <div style="width:40px;height:40px;border-radius:50%;background:#F3F4F6;display:flex;align-items:center;justify-content:center;color:#374151;font-weight:bold;margin-right:10px;"><i class="fa-solid fa-user"></i></div>
                            <div>
                                <h6 style="margin:0;font-size:0.9rem;color:#111827;">${contactName}</h6>
                                <small style="color:#6B7280;">SuperFormica</small>
                            </div>
                        </div>
                        <p style="margin-bottom:0;text-align:center;"><a href="tel:${contactPhone}" style="color:#25D366;text-decoration:none;font-weight:600;"><i class="fa-brands fa-whatsapp"></i> ${contactPhone}</a></p>
                        <div style="text-align:right; margin-top:5px;"><span class="msg-time" style="font-size:0.7rem;">${timeLabel} <i class="fa-solid fa-check-double ms-1" style="color: #60A5FA;"></i></span></div>
                    </div>
                </div>`;
            } else {
                msgHtml = `
                    <div class="system-event">
                        <div class="event-pill">
                            <i class="fa-solid fa-info-circle"></i> ${msg.contenido} (${timeLabel})
                        </div>
                    </div>
                `;
            }
        } else {
            let colorStlye = msg.origen === 'API_TRANSACCIONAL' ? 'background-color: #fff3cd;' : 'background-color: #EFF6FF; border: 1px solid #BFDBFE;';
            let icon = msg.origen === 'API_TRANSACCIONAL' ? '<i class="fa-solid fa-bolt text-warning"></i> ' : '';

            // Icono de Doble Check
            let estadoIcon = '<i class="fa-solid fa-clock ms-1" style="color: #9CA3AF;"></i>'; // default
            if (msg.estado_envio === 'ENVIADO') estadoIcon = '<i class="fa-solid fa-check ms-1" style="color: #9CA3AF;"></i>';
            if (msg.estado_envio === 'ENTREGADO') estadoIcon = '<i class="fa-solid fa-check-double ms-1" style="color: #9CA3AF;"></i>';
            if (msg.estado_envio === 'LEIDO') estadoIcon = '<i class="fa-solid fa-check-double ms-1" style="color: #60A5FA;"></i>';
            if (msg.estado_envio === 'FALLIDO') estadoIcon = '<i class="fa-solid fa-circle-exclamation ms-1" style="color: #EF4444;"></i>';

            // Soporte Multimedia
            let mediaHtml = '';
            if (msg.tipo === 'IMAGEN' && msg.url_archivo) {
                mediaHtml = `<div style="margin-bottom:8px; border-radius:8px; overflow:hidden; background:#E5E7EB; display:flex; align-items:center; justify-content:center; height:150px;"><i class="fa-solid fa-image fs-1 text-muted"></i></div>`;
            } else if (msg.tipo === 'DOCUMENTO' && msg.url_archivo) {
                mediaHtml = `<div style="margin-bottom:8px; padding:10px; border-radius:8px; background:#E5E7EB; display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-file-pdf text-danger fs-3"></i> <b>Documento Adjunto</b></div>`;
            }

            msgHtml = `
                <div class="message bot-message" style="align-self: flex-end; ${colorStlye}">
                    <div class="msg-bubble" style="background-color: transparent; border:none; padding-bottom:5px;">
                        ${mediaHtml}
                        <p style="margin-bottom:0;">${icon}${msg.contenido}</p>
                        <span class="msg-time">${timeLabel} ${estadoIcon}</span>
                    </div>
                </div>
            `;
        }
        area.append(msgHtml);
    });
    if (scrollToBottom) area.scrollTop(area[0].scrollHeight);
}

function sendMessage() {
    if (activeChatId === null) {
        Swal.fire({ icon: 'warning', text: 'Debes seleccionar una conversación primero.' });
        return;
    }
    const input = $('#chatInput');
    const text = input.val().trim();
    if (text === '') return;

    const area = $('#messagesArea');
    area.append(`
        <div class="message bot-message" style="align-self: flex-end; background-color: #EFF6FF; border: 1px solid #BFDBFE;">
            <div class="msg-bubble" style="background-color: transparent; border:none; padding-bottom:5px;">
                <p style="margin-bottom:0;">${text}</p>
                <span class="msg-time"><i class="fa-regular fa-clock"></i> Enviando...</span>
            </div>
        </div>
    `);
    area.scrollTop(area[0].scrollHeight);
    input.val('');

    $.ajax({
        url: 'back_bandeja.php', type: 'POST', dataType: 'json',
        data: { action: 'send_message', conversacion_id: activeChatId, cliente_id: activeClientId, contenido: text },
        success: function (response) {
            if (response.status === 'success') {
                if (activeChatId == 0 && response.new_chat_id) {
                    activeChatId = response.new_chat_id;
                    // Actualizar el atributo data-id en el DOM para el chat activo
                    $('.chat-item.active').attr('data-id', activeChatId);
                }
                loadMessages(activeChatId, true);
            }
            else Swal.fire('Error', response.message, 'error');
        }
    });
}

function formatTime(datetimeStr) {
    if (!datetimeStr) return '';
    const d = new Date(datetimeStr);
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
