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

    // 1. Emoji Picker (Nativo Ligero sin dependencias)
    const emojis = ["😀","😂","🤣","😊","😍","🥰","😘","😜","🤪","😎","🤩","🥳","😏","😒","😞","😔","😢","😭","😠","😡","🤬","🤯","😳","🥵","🥶","😱","😨","😓","🤗","🤔","🤫","🤥","😶","😐","😑","😬","🙄","😯","😦","😧","😮","😲","🥱","😴","🤤","😪","😵","🤐","🥴","🤢","🤮","🤧","😷","🤒","🤕","🤑","🤠","😈","👿","👹","👺","🤡","💩","👻","💀","👽","👾","🤖","🎃","😺","😸","😹","😻","😼","😽","🙀","😿","😾","👍","👎","👏","🙌","👐","🤲","🤝","🙏","✍️","💅","🤳","💪","🦾","🦵","🦿","🦶","👂","🦻","👃","🧠","🦷","🦴","👀","👁️","👅","👄","💋","🩸","❤️","💔","💯","🔥","✨","🎉","🎊","🎈"];
    
    const emojiPicker = $('#emojiPicker');
    const input = $('#chatInput');
    
    // Poblar el picker
    emojis.forEach(e => {
        emojiPicker.append(`<button class="emoji-btn">${e}</button>`);
    });
    
    // Toggle picker
    $('#btnEmoji').on('click', function (e) {
        e.stopPropagation();
        emojiPicker.toggleClass('show');
    });
    
    // Click emoji
    emojiPicker.on('click', '.emoji-btn', function(e) {
        e.stopPropagation();
        const cursor = input[0].selectionStart;
        const text = input.val();
        const selected = $(this).text();
        input.val(text.slice(0, cursor) + selected + text.slice(cursor));
        input[0].selectionStart = input[0].selectionEnd = cursor + selected.length;
        input.focus();
    });
    
    // Cerrar al hacer clic afuera
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#emojiPicker, #btnEmoji').length) {
            emojiPicker.removeClass('show');
        }
    });

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

    // 4. Internal Note Toggle
    $('#btnInternalNote').on('click', function() {
        $(this).toggleClass('note-mode');
        if ($(this).hasClass('note-mode')) {
            $(this).css({ 'color': '#E85B14', 'background-color': 'rgba(232, 91, 20, 0.1)' });
            $('#inputBoxContainer').css({ 'background-color': '#FEF3C7', 'border-color': '#FDE68A' });
            $('#chatInput').attr('placeholder', 'Escribiendo nota interna (oculta al cliente)...');
        } else {
            $(this).css({ 'color': '', 'background-color': '' });
            $('#inputBoxContainer').css({ 'background-color': '', 'border-color': '' });
            $('#chatInput').attr('placeholder', 'Escribe un mensaje...');
        }
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
        loadChats();
    });

    // Filtro por Sede
    $(document).on('change', '#filterSede', function () {
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
    const id_sede = $('#filterSede').val() || '';
    $.ajax({
        url: 'back_bandeja.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_chats', filter: currentFilter, id_sede: id_sede },
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
        let unreadDot = '';
        if (chat.no_leidos > 0) {
            totalUnread++;
            unreadDot = `<div style="width:10px;height:10px;background:#e85b14;border-radius:50%;margin-left:auto;"></div>`;
        }

        let isActiveClass = (chat.id == activeChatId && chat.id_cliente == activeClientId) ? 'active' : '';

        let lastTime = chat.ultimo_mensaje_ts ? chat.ultimo_mensaje_ts : chat.fecha_inicio;
        let html = `
            <article class="chat-item ${isActiveClass}" onclick="window.clickChat(this)" style="cursor: pointer;" data-id="${chat.id}" data-cliente-id="${chat.id_cliente}" data-name="${name.replace(/"/g, '&quot;')}" data-phone="${chat.numero_whatsapp}" data-sede="${chat.nombre_sede || ''}">
                <div class="chat-avatar">
                    <img src="https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F3F4F6&color=37414A" alt="Avatar">
                </div>
                <div class="chat-summary">
                    <div class="chat-top">
                        <h4>${name}</h4>
                        <span class="time">${formatTime(lastTime)}</span>
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

    if (id === 0) {
        $(`.chat-item[data-cliente-id="${cliente_id}"]`).addClass('active');
    } else {
        $(`.chat-item[data-id="${id}"]`).addClass('active');
    }

    $('#emptyState').hide();
    $('#activeChatView').css('display', 'flex');

    $('#chatHeaderName').text(name);
    $('#chatHeaderPhone').text(`+${phone}`);
    $('#chatHeaderImg').attr('src', `https://ui-avatars.com/api/?name=${encodeURIComponent(name)}&background=F3F4F6&color=37414A`);

    if (nombre_sede) {
        $('#chatHeaderSede span').text(nombre_sede);
        $('#chatHeaderSede').css('display', 'inline-block');
    } else {
        $('#chatHeaderSede').hide();
    }

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

        let mediaHtml = '';
        if (msg.tipo === 'IMAGEN' && msg.url_archivo) {
            let realUrl = msg.url_archivo.indexOf('/') === -1 ? `../../get_media.php?id=${msg.url_archivo}&chat_id=${activeChatId}` : msg.url_archivo;
            let isSticker = realUrl.endsWith('.webp');
            let imgStyle = isSticker 
                ? 'max-width: 120px; height: auto; display: block; margin: 0 auto; filter: drop-shadow(0px 4px 6px rgba(0,0,0,0.1)); cursor: pointer; transition: transform 0.2s;' 
                : 'max-height: 200px; width: auto; max-width: 100%; border-radius: 8px; display: block; cursor: pointer; transition: transform 0.2s;';
            let containerStyle = isSticker
                ? 'margin-bottom:8px; padding: 10px; text-align: center;'
                : 'margin-bottom:8px; border-radius:8px; overflow:hidden; background:#E5E7EB; display:flex; align-items:center; justify-content:center;';
            
            mediaHtml = `<div style="${containerStyle}"><img src="${realUrl}" style="${imgStyle}" alt="Archivo adjunto" loading="lazy" class="zoomable-media" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'" onclick="Swal.fire({imageUrl: '${realUrl}', imageAlt: 'Archivo adjunto', width: 'auto', padding: 0, showConfirmButton: false, customClass: { popup: 'bg-transparent' }, backdrop: 'rgba(0,0,0,0.8)'})"></div>`;
        } else if (msg.tipo === 'DOCUMENTO' && msg.url_archivo) {
            let realUrl = msg.url_archivo.indexOf('/') === -1 ? `../../get_media.php?id=${msg.url_archivo}&chat_id=${activeChatId}` : msg.url_archivo;
            mediaHtml = `<div style="margin-bottom:8px; padding:10px; border-radius:8px; background:#E5E7EB; display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-file-pdf text-danger fs-3"></i> <a href="${realUrl}" target="_blank" style="text-decoration:none; font-weight:bold; color:#111827;">Documento Adjunto</a></div>`;
        } else if (msg.tipo === 'AUDIO' && msg.url_archivo) {
            let realUrl = msg.url_archivo.indexOf('/') === -1 ? `../../get_media.php?id=${msg.url_archivo}&chat_id=${activeChatId}` : msg.url_archivo;
            mediaHtml = `<div style="margin-bottom:8px;"><audio controls src="${realUrl}" style="max-width: 250px;"></audio></div>`;
        }
        let replyBtn = '';
        if (msg.id_mensaje_meta && (msg.tipo === 'TEXTO' || msg.tipo === 'IMAGEN' || msg.tipo === 'DOCUMENTO' || msg.tipo === 'AUDIO')) {
            let safeText = (msg.contenido || 'Archivo multimedia').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            replyBtn = `<i class="fa-solid fa-reply reply-btn" data-meta-id="${msg.id_mensaje_meta}" data-text="${safeText}" style="cursor:pointer; color:#9CA3AF; margin-right:8px;" title="Responder"></i>`;
        }

        if (msg.origen === 'CLIENTE') {
            msgHtml = `
                <div class="message client-message" style="align-self: flex-start; background-color: white; border: 1px solid #E5E7EB; border-radius: 18px 18px 18px 2px; padding: 12px 16px; margin-bottom: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); max-width: 80%;">
                    ${mediaHtml}
                    ${msg.contenido && msg.contenido !== 'Imagen recibida' && msg.contenido !== 'Documento recibido' && msg.contenido !== 'Audio recibido' ? `<p style="margin-bottom:0; color: #111827;">${msg.contenido}</p>` : ''}
                    <div style="text-align:right;">${replyBtn}<span class="msg-time">${timeLabel}</span></div>
                </div>
            `;
        } else if (msg.origen === 'NOTA_INTERNA') {
            let noteAgent = msg.nombre_agente ? `<div style="font-size: 0.7rem; color: #92400E; font-weight: bold; margin-bottom: 4px;">Por: ${msg.nombre_agente}</div>` : '';
            msgHtml = `
                <div class="message bot-message" style="align-self: flex-end; margin-bottom: 15px; padding-right: 10px;">
                    <div class="msg-bubble" style="
                        background-color: #FDFBAA; 
                        border: none; 
                        box-shadow: 3px 5px 12px rgba(0,0,0,0.15); 
                        border-radius: 2px 2px 18px 2px; 
                        padding: 12px 16px; 
                        font-family: 'Comic Sans MS', 'Chalkboard SE', 'Marker Felt', cursive, sans-serif;
                        color: #333333;
                        max-width: 320px;
                        transform: rotate(-1.5deg);
                        position: relative;
                    ">
                        <!-- Sombra interna superior para dar volumen -->
                        <div style="position:absolute; top:0; left:0; width:100%; height:15px; background: linear-gradient(rgba(0,0,0,0.06), transparent); border-radius: 2px 2px 0 0;"></div>
                        
                        <!-- Chincheta (Pushpin) -->
                        <div style="position:absolute; top:-8px; left:50%; transform:translateX(-50%); text-align:center;">
                            <i class="fa-solid fa-thumbtack" style="color:#EF4444; font-size: 1.2rem; filter: drop-shadow(0px 2px 2px rgba(0,0,0,0.3));"></i>
                        </div>
                        
                        <div style="font-size:0.7rem; color:#D97706; margin-bottom:2px; margin-top:8px; font-family: 'Inter', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fa-solid fa-user-secret"></i> Nota Privada
                        </div>
                        ${noteAgent}
                        
                        <p style="margin-bottom:8px; font-size: 0.95rem; line-height: 1.4;">${msg.contenido}</p>
                        
                        <div style="text-align: right; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 4px;">
                            <span class="msg-time" style="color:#92400E; font-family: 'Inter', sans-serif; font-size: 0.7rem;">${timeLabel}</span>
                        </div>
                    </div>
                </div>
            `;
        } else if (msg.origen === 'BOT' || msg.origen === 'EVENTO_SISTEMA') {
            if (msg.tipo === 'CONTACTO') {
                let contactName = msg.contenido.substring(18, msg.contenido.indexOf('(')).trim();
                let contactPhone = msg.contenido.substring(msg.contenido.indexOf('(') + 1, msg.contenido.indexOf(')'));

                msgHtml = `
                <div class="message bot-message" style="align-self: flex-end; background-color: white; border: 1px solid #E5E7EB; width: 250px;">
                    <div class="msg-bubble" style="background-color: transparent; border:none; padding: 10px;">
                        <div style="display:flex; align-items:center; border-bottom:1px solid #eee; padding-bottom:10px; margin-bottom:10px;">
                            <div style="width:40px;height:40px;border-radius:50%;background:#F3F4F6;display:flex;align-items:center;justify-content:center;color:#374151;font-weight:bold;margin-right:10px;"><i class="fa-solid fa-user"></i></div>
                            <div>
                                <h6 style="margin:0;font-size:0.9rem;color:#111827;">${contactName}</h6>
                                <small style="color:#6B7280;">Sede</small>
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
            // AGENTE or API_TRANSACCIONAL
            let colorStlye = msg.origen === 'API_TRANSACCIONAL' ? 'background-color: #ffffff; border: 1px solid #E5E7EB;' : 'background-color: #EFF6FF; border: 1px solid #BFDBFE;';
            let icon = msg.origen === 'API_TRANSACCIONAL' ? '<i class="fa-solid fa-robot text-muted me-1"></i> ' : '';
            
            let displayContent = msg.contenido || '';
            let agentNameHtml = (msg.origen === 'AGENTE' && msg.nombre_agente) ? `<div style="font-size: 0.65rem; color: #6B7280; margin-bottom: 6px; border-bottom: 1px solid #BFDBFE; padding-bottom: 2px;"><i class="fa-solid fa-headset"></i> ${msg.nombre_agente}</div>` : '';
            
            // Intelligent Visual Rendering for Templates
            if (msg.origen === 'API_TRANSACCIONAL' && msg.contenido && msg.contenido.startsWith('Envío dinámico de plantilla:')) {
                try {
                    let match = msg.contenido.match(/plantilla:\s*([^.]+)\.\s*Params:\s*(\[.*\])/);
                    if (match) {
                        let tplName = match[1];
                        let params = JSON.parse(match[2]);
                        let paramsHtml = params.map((p, i) => `
                            <div style="display:flex; align-items:baseline; margin-bottom:2px;">
                                <span style="color:#9CA3AF; font-size:0.75rem; width:15px;">${i+1}.</span> 
                                <span style="font-weight:600; color:#374151;">${p}</span>
                            </div>
                        `).join('');
                        
                        displayContent = `
                            <div style="border-left: 3px solid #E85B14; padding-left: 12px; margin-bottom: 8px;">
                                <div style="font-size:0.7rem; color:#E85B14; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; display:flex; align-items:center; gap:5px;">
                                    <i class="fa-solid fa-bolt"></i> Plantilla: ${tplName.replace(/_/g, ' ')}
                                </div>
                                <div style="font-size:0.85rem; line-height:1.4;">
                                    ${paramsHtml}
                                </div>
                            </div>
                            <div style="font-size:0.7rem; color:#9CA3AF; margin-top:8px; display:flex; align-items:center; gap:4px; border-top:1px solid #F3F4F6; padding-top:6px;">
                                <i class="fa-solid fa-shield-check text-success"></i> Mensaje de Sistema
                            </div>
                        `;
                        icon = ''; 
                    }
                } catch(e) {}
            }

            let replyHtml = '';
            if (msg.reply_to_text) {
                replyHtml = `
                    <div style="background-color: #DBEAFE; border-left: 3px solid #3B82F6; padding: 6px 10px; border-radius: 4px; margin-bottom: 8px; font-size: 0.8rem; color: #1E3A8A;">
                        <div style="font-weight: bold; margin-bottom: 2px;"><i class="fa-solid fa-reply"></i> Respondiste a:</div>
                        <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${msg.reply_to_text}</div>
                    </div>
                `;
            }

            // Icono de Doble Check
            let estadoIcon = '<i class="fa-solid fa-clock ms-1" style="color: #9CA3AF;"></i>'; // default
            if (msg.estado_envio === 'ENVIADO') estadoIcon = '<i class="fa-solid fa-check ms-1" style="color: #9CA3AF;"></i>';
            if (msg.estado_envio === 'ENTREGADO') estadoIcon = '<i class="fa-solid fa-check-double ms-1" style="color: #9CA3AF;"></i>';
            if (msg.estado_envio === 'LEIDO') estadoIcon = '<i class="fa-solid fa-check-double ms-1" style="color: #60A5FA;"></i>';
            if (msg.estado_envio === 'FALLIDO') {
                estadoIcon = `<i class="fa-solid fa-circle-exclamation ms-1" style="color: #EF4444;"></i>
                              <i class="fa-solid fa-rotate-right retry-btn ms-1" data-id="${msg.id}" style="cursor:pointer; color:#EF4444;" title="Reintentar Envío"></i>`;
            }

            msgHtml = `
                <div class="message bot-message" style="align-self: flex-end; ${colorStlye}">
                    <div class="msg-bubble" style="background-color: transparent; border:none; padding-bottom:5px;">
                        ${agentNameHtml}
                        ${replyHtml}
                        ${mediaHtml}
                        <div style="margin-bottom:0;">${icon}${displayContent}</div>
                        <div style="text-align:right; margin-top:2px;">
                            ${replyBtn}
                            <span class="msg-time">${timeLabel} ${estadoIcon}</span>
                        </div>
                    </div>
                </div>
            `;
        }
        area.append(msgHtml);
    });
    if (scrollToBottom) area.scrollTop(area[0].scrollHeight);
}

// Global variable para la respuesta
window.currentReplyMetaId = null;

$(document).on('click', '.reply-btn', function(e) {
    e.stopPropagation();
    window.currentReplyMetaId = $(this).data('meta-id');
    let text = $(this).data('text');
    if (text.length > 50) text = text.substring(0, 47) + '...';
    
    if ($('#replyContextUI').length === 0) {
        $('.input-area').prepend(`
            <div id="replyContextUI" style="background-color: #F3F4F6; padding: 8px 12px; border-left: 4px solid #3B82F6; border-radius: 4px 4px 0 0; display: flex; justify-content: space-between; align-items: center; font-size: 0.85rem; color: #4B5563;">
                <div><i class="fa-solid fa-reply me-2 text-primary"></i> Respondiendo a: <b><span id="replyTextPreview"></span></b></div>
                <i class="fa-solid fa-times" id="cancelReplyBtn" style="cursor: pointer; color: #9CA3AF;"></i>
            </div>
        `);
    }
    $('#replyTextPreview').text(text);
    $('#chatInput').focus();
});

$(document).on('click', '#cancelReplyBtn', function() {
    window.currentReplyMetaId = null;
    $('#replyContextUI').remove();
});
// Reintentar Envío de Mensajes Fallidos
$(document).on('click', '.retry-btn', function(e) {
    e.stopPropagation();
    const msgId = $(this).data('id');
    
    Swal.fire({
        title: '¿Reintentar envío?',
        text: 'Se intentará enviar este mensaje de nuevo a WhatsApp. (Nota: Si es un sticker inválido, volverá a fallar).',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, reintentar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.ajax({
                url: 'back_bandeja.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'retry_message', msg_id: msgId },
                success: function (response) {
                    if (response.status === 'success') {
                        loadMessages(activeChatId, true);
                        Swal.fire('Reenviado', 'El mensaje se ha enviado a la cola.', 'success');
                    } else {
                        Swal.fire('Aviso', response.message || 'Error al reintentar.', 'warning');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Fallo al comunicarse con el servidor.', 'error');
                }
            });
        }
    });
});

function sendMessage() {
    if (activeChatId === null) {
        Swal.fire({ icon: 'warning', text: 'Debes seleccionar una conversación primero.' });
        return;
    }
    const input = $('#chatInput');
    const text = input.val().trim();
    if (text === '') return;
    
    const isInternal = $('#btnInternalNote').hasClass('note-mode') ? 1 : 0;
    const replyMetaId = window.currentReplyMetaId;
    const replyText = $('#replyTextPreview').text() || '';

    const area = $('#messagesArea');
    
    if (isInternal) {
        area.append(`
            <div class="message bot-message" style="align-self: flex-end; margin-bottom: 15px; padding-right: 10px;">
                <div class="msg-bubble" style="
                    background-color: #FDFBAA; 
                    border: none; 
                    box-shadow: 3px 5px 12px rgba(0,0,0,0.15); 
                    border-radius: 2px 2px 18px 2px; 
                    padding: 12px 16px; 
                    font-family: 'Comic Sans MS', 'Chalkboard SE', 'Marker Felt', cursive, sans-serif;
                    color: #333333;
                    max-width: 320px;
                    transform: rotate(-1.5deg);
                    position: relative;
                ">
                    <div style="position:absolute; top:0; left:0; width:100%; height:15px; background: linear-gradient(rgba(0,0,0,0.06), transparent); border-radius: 2px 2px 0 0;"></div>
                    <div style="position:absolute; top:-8px; left:50%; transform:translateX(-50%); text-align:center;">
                        <i class="fa-solid fa-thumbtack" style="color:#EF4444; font-size: 1.2rem; filter: drop-shadow(0px 2px 2px rgba(0,0,0,0.3));"></i>
                    </div>
                    <div style="font-size:0.7rem; color:#D97706; margin-bottom:6px; margin-top:8px; font-family: 'Inter', sans-serif; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                        <i class="fa-solid fa-user-secret"></i> Nota Privada
                    </div>
                    <p style="margin-bottom:8px; font-size: 0.95rem; line-height: 1.4;">${text}</p>
                    <div style="text-align: right; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 4px;">
                        <span class="msg-time" style="color:#92400E; font-family: 'Inter', sans-serif; font-size: 0.7rem;"><i class="fa-regular fa-clock"></i> Guardando...</span>
                    </div>
                </div>
            </div>
        `);
    } else {
        let replyHtml = replyText ? `
            <div style="background-color: #DBEAFE; border-left: 3px solid #3B82F6; padding: 6px 10px; border-radius: 4px; margin-bottom: 8px; font-size: 0.8rem; color: #1E3A8A;">
                <div style="font-weight: bold; margin-bottom: 2px;"><i class="fa-solid fa-reply"></i> Respondiste a:</div>
                <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${replyText}</div>
            </div>
        ` : '';
        area.append(`
            <div class="message bot-message" style="align-self: flex-end; background-color: #EFF6FF; border: 1px solid #BFDBFE;">
                <div class="msg-bubble" style="background-color: transparent; border:none; padding-bottom:5px;">
                    ${replyHtml}
                    <p style="margin-bottom:0;">${text}</p>
                    <span class="msg-time"><i class="fa-regular fa-clock"></i> Enviando...</span>
                </div>
            </div>
        `);
    }
    area.scrollTop(area[0].scrollHeight);
    input.val('');
    
    // Clear reply UI immediately
    if (replyMetaId) {
        window.currentReplyMetaId = null;
        $('#replyContextUI').remove();
    }

    $.ajax({
        url: 'back_bandeja.php', type: 'POST', dataType: 'json',
        data: { action: 'send_message', conversacion_id: activeChatId, cliente_id: activeClientId, contenido: text, is_internal: isInternal, reply_to_meta_id: replyMetaId || '', reply_to_text: replyText },
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
