// modules/bandeja/funciones_bandeja.js

let activeChatId = null;
let activeClientId = null;
let currentFilter = 'todos';

// --- [SPRINT 2] Estado global para Ventana Meta 24h, Semáforo SLA, Embudo USD y Round-Robin ---
let serverClockOffsetSec = 0;
let activeWindowExpiresAtEpoch = 0;
let activeDealInfo = null;
let metaTemplatesCache = [];
let metaTemplatesSedeName = 'Nuestra Sede';
let slaAlertedChatIds = new Set();
let slaSoundEnabled = (localStorage.getItem('starfi_sla_sound') !== '0');
let agentAvailable = (typeof window.initialDisponibleChat !== 'undefined') ? (parseInt(window.initialDisponibleChat, 10) === 1) : true;

function syncServerClock(serverEpoch) {
    const ep = parseInt(serverEpoch || 0, 10);
    if (ep > 1700000000) {
        serverClockOffsetSec = ep - Math.floor(Date.now() / 1000);
    }
}

function getServerNowEpoch() {
    return Math.floor(Date.now() / 1000) + serverClockOffsetSec;
}

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

    loadOperadoresFilter();
    loadChats();

    // Tiempo Real con Server-Sent Events (SSE)
    function iniciarSSE() {
        const evtSource = new EventSource("sse_updates.php");

        evtSource.onmessage = function (event) {
            const data = JSON.parse(event.data);
            if (data.type === 'update') {
                if (data.reason === 'status_change') {
                    if (activeChatId !== null && activeChatId !== 0) {
                        loadMessages(activeChatId, false);
                    }
                    loadChats(false, currentChatPage, true);
                } else {
                    loadChats(false, currentChatPage, true);
                    if (activeChatId !== null && activeChatId !== 0) {
                        loadMessages(activeChatId, false);
                    }
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

    // Búsqueda híbrida: filtrado visual inmediato + búsqueda en servidor (con debounce de 350ms)
    let searchDebounceTimer = null;
    $('.search-bar input').on('input', function () {
        let val = $(this).val().trim();
        let valLower = val.toLowerCase();
        $('#chatList .chat-item').filter(function () {
            $(this).toggle($(this).text().toLowerCase().indexOf(valLower) > -1);
        });
        clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(function () {
            loadChats(false, 1);
        }, 350);
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
        
        // Cargar respuestas rápidas
        $('#templatesList').html('<div class="text-center p-3 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando respuestas...</div>');
        $.ajax({
            url: 'back_bandeja.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_respuestas_rapidas' },
            success: function(res) {
                if (res.status === 'success') {
                    let list = $('#templatesList');
                    list.empty();
                    if (res.data.length === 0) {
                        list.html('<div class="text-center p-3 text-muted">No hay respuestas rápidas configuradas.</div>');
                    } else {
                        res.data.forEach(r => {
                            let textEscaped = r.mensaje.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                            let btn = `
                            <button type="button" class="list-group-item list-group-item-action p-3" style="border-radius: 12px; border: 1px solid #E2E8F0; margin-bottom: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);" onclick="selectTemplate('${textEscaped}')">
                                <strong class="text-primary"><i class="fa-solid fa-bolt me-1"></i> ${r.titulo}</strong><br>
                                <small class="text-muted mt-1 d-block">${r.mensaje}</small>
                            </button>`;
                            list.append(btn);
                        });
                    }
                }
            }
        });

        // Cargar respuestas automáticas (Bot)
        $('#botAnswersList').html('<tr><td colspan="3" class="text-center text-muted py-3"><i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando respuestas automáticas...</td></tr>');
        $.ajax({
            url: 'back_bandeja.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_bot_respuestas' },
            success: function(res) {
                if (res.status === 'success') {
                    let list = $('#botAnswersList');
                    list.empty();
                    if (res.data.length === 0) {
                        list.html('<tr><td colspan="3" class="text-center text-muted py-3">No hay respuestas del bot configuradas.</td></tr>');
                    } else {
                        res.data.forEach(r => {
                            let textEscaped = r.mensaje.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                            let row = `
                            <tr>
                                <td class="fw-bold"><span class="badge bg-light text-dark border">${r.disparador}</span></td>
                                <td><small class="text-muted d-block text-wrap" style="max-width: 250px;">${r.mensaje}</small></td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn btn-sm btn-light text-primary" onclick="selectTemplate('${textEscaped}')">
                                        <i class="fa-solid fa-paper-plane"></i>
                                    </button>
                                </td>
                            </tr>`;
                            list.append(row);
                        });
                    }
                }
            }
        });
    });

    window.selectTemplate = function (text) {
        $('#chatInput').val(text);
        $('#modalTemplates').modal('hide');
        $('#chatInput').focus();
    };

    window.createNewQuickReply = function() {
        // Close Bootstrap modal first so it doesn't steal focus
        $('#modalTemplates').modal('hide');
        
        setTimeout(() => {
            Swal.fire({
                title: 'Nueva Respuesta Rápida',
                html: `
                    <input type="text" id="swal-qr-title" class="swal2-input" placeholder="Título (ej: Saludo)">
                    <textarea id="swal-qr-msg" class="swal2-textarea" placeholder="Mensaje a enviar..."></textarea>
                `,
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonText: 'Guardar',
                cancelButtonText: 'Cancelar',
                preConfirm: () => {
                    const titulo = document.getElementById('swal-qr-title').value.trim();
                    const mensaje = document.getElementById('swal-qr-msg').value.trim();
                    if (!titulo || !mensaje) {
                        Swal.showValidationMessage(`Por favor ingrese título y mensaje`);
                    }
                    return { titulo: titulo, mensaje: mensaje }
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    $.post('back_bandeja.php', {
                        action: 'create_quick_reply',
                        titulo: result.value.titulo,
                        mensaje: result.value.mensaje
                    }, function(res) {
                        if (res.status === 'success') {
                            Swal.fire({ icon: 'success', title: 'Guardada', toast: true, position: 'top-end', showConfirmButton: false, timer: 2000 });
                        } else {
                            Swal.fire('Error', res.message, 'error');
                        }
                    }, 'json');
                } else {
                    // Re-open templates modal if they canceled
                    $('#modalTemplates').modal('show');
                }
            });
        }, 400); // Wait for Bootstrap modal transition to finish
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
                } else if (response.code === 'WINDOW_24H_EXPIRED') {
                    activeWindowExpiresAtEpoch = 0;
                    updateActiveWindow24hUI();
                    loadMessages(activeChatId, true);
                    promptWindow24hExpiredModal(response.message);
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
        updateActiveWindow24hUI();
    });

    // Delegación de eventos para la lista de chats dinámica (Respaldo)
    $('#chatList').on('click', '.chat-item', function () {
        const id = $(this).data('id');
        const cliente_id = $(this).data('cliente-id');
        const name = $(this).data('name');
        const phone = $(this).data('phone');
        const sede = $(this).data('sede');
        const asesor = $(this).data('asesor');
        const no_leidos = parseInt($(this).data('no-leidos') || 0);
        requestSwitchChat(id, cliente_id, name, phone, sede, asesor, no_leidos);
    });

    // Función global para clics móviles y clicks de chat
    window.clickChat = function (element) {
        const id = $(element).data('id');
        const cliente_id = $(element).data('cliente-id');
        const name = $(element).data('name');
        const phone = $(element).data('phone');
        const sede = $(element).data('sede');
        const asesor = $(element).data('asesor');
        const no_leidos = parseInt($(element).data('no-leidos') || 0);
        requestSwitchChat(id, cliente_id, name, phone, sede, asesor, no_leidos);
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
        loadOperadoresFilter();
        loadChats();
    });

    // Filtro por Operador
    $(document).on('change', '#filterOperador', function () {
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

    // 3. Cerrar Chat con Métricas
    $('#btnCloseChat').on('click', function () {
        if (!activeChatId || activeChatId === 0) return;
        
        Swal.fire({
            title: 'Cerrar Conversación',
            html: `
                <div class="text-start mt-3">
                    <label class="form-label text-muted fw-bold" style="font-size: 0.85rem;">Motivo del Cierre</label>
                    <select id="motivo_cierre_modal" class="form-select mb-2" onchange="if(this.value==='OTRO'){document.getElementById('motivo_cierre_custom').style.display='block';}else{document.getElementById('motivo_cierre_custom').style.display='none';}">
                        <option value="VENTA_CERRADA">Venta Cerrada (Éxito)</option>
                        <option value="DUDA_RESUELTA">Duda / Consulta Resuelta</option>
                        <option value="NO_INTERESADO">No Interesado / Spam</option>
                        <option value="OTRO">Otro... (Especificar)</option>
                    </select>
                    <input type="text" id="motivo_cierre_custom" class="form-control mb-3" placeholder="Escribe el nuevo motivo..." style="display:none;">

                    <label class="form-label text-muted fw-bold" style="font-size: 0.85rem;">Calificación del Cliente (Lead Scoring)</label>
                    <div class="d-flex justify-content-between mb-3">
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="calidad_lead" id="cal1" value="1">
                            <label class="form-check-label" for="cal1">⭐ 1</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="calidad_lead" id="cal2" value="2">
                            <label class="form-check-label" for="cal2">⭐ 2</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="calidad_lead" id="cal3" value="3" checked>
                            <label class="form-check-label" for="cal3">⭐ 3</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="calidad_lead" id="cal4" value="4">
                            <label class="form-check-label" for="cal4">⭐ 4</label>
                        </div>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="radio" name="calidad_lead" id="cal5" value="5">
                            <label class="form-check-label" for="cal5">⭐ 5</label>
                        </div>
                    </div>

                    <label class="form-label text-muted fw-bold" style="font-size: 0.85rem;">Observación (Opcional)</label>
                    <textarea id="observacion_cierre_modal" class="form-control" rows="2" placeholder="Deja una reseña sobre el cliente o detalles de la atención..."></textarea>
                    
                    <div class="form-check mt-3 mb-1 text-start">
                        <input class="form-check-input" type="checkbox" id="enviar_csat" value="1" checked>
                        <label class="form-check-label text-muted fw-bold" for="enviar_csat" style="font-size: 0.85rem;">
                            Enviar encuesta de satisfacción (CSAT)
                        </label>
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#E85B14',
            confirmButtonText: 'Guardar y Cerrar',
            preConfirm: () => {
                let motivo = document.getElementById('motivo_cierre_modal').value;
                if(motivo === 'OTRO') {
                    motivo = document.getElementById('motivo_cierre_custom').value.trim();
                    if(!motivo) {
                        Swal.showValidationMessage('Debes especificar el motivo de cierre.');
                        return false;
                    }
                }
                return {
                    motivo: motivo,
                    calidad: document.querySelector('input[name="calidad_lead"]:checked').value,
                    observacion: document.getElementById('observacion_cierre_modal').value,
                    enviar_csat: document.getElementById('enviar_csat').checked ? 1 : 0
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                $.ajax({
                    url: 'back_bandeja.php', type: 'POST', dataType: 'json',
                    data: { 
                        action: 'close_chat', 
                        conversacion_id: activeChatId,
                        cliente_id: activeClientId,
                        motivo_cierre: result.value.motivo,
                        calificacion_calidad: result.value.calidad,
                        observacion: result.value.observacion,
                        enviar_csat: result.value.enviar_csat
                    },
                    success: function (res) {
                        if (res.status === 'success') {
                            activeChatId = null;
                            activeClientId = null;
                            loadChats();
                            $('#activeChatView').hide();
                            $('#emptyState').removeClass('d-none').css('display', 'flex');
                            $('#modalProfile360').modal('hide');
                            Swal.fire('Cerrado', 'Métricas guardadas correctamente', 'success');
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
            data: { action: 'get_agents', conversacion_id: activeChatId },
            success: function (res) {
                if (res.status === 'success') {
                    let options = {};
                    res.data.forEach(ag => {
                        const onlineTag = (parseInt(ag.en_linea || 0, 10) === 1) ? '🟢 En línea' : '⚪ Desconectado';
                        options[ag.id] = `${ag.nombre_completo} (${onlineTag})`;
                    });

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

    // 5. Inicializar herramientas Enterprise Sprint 2 ([COM-01], [COM-02], [COM-03], [COM-04])
    initSprint2EnterpriseTools();
});

function loadOperadoresFilter() {
    const filterOperador = $('#filterOperador');
    if (!filterOperador.length || filterOperador.is(':disabled')) return;

    const id_sede = $('#filterSede').val() || '';
    $.ajax({
        url: 'back_bandeja.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'get_agents', id_sede: id_sede },
        success: function (res) {
            if (res.status === 'success') {
                const currentVal = filterOperador.val();
                let html = '<option value="">Todos los Operadores</option>';
                res.data.forEach(ag => {
                    html += `<option value="${ag.id}">${ag.nombre_completo}</option>`;
                });
                filterOperador.html(html);
                if (currentVal && filterOperador.find(`option[value="${currentVal}"]`).length) {
                    filterOperador.val(currentVal);
                } else {
                    filterOperador.val('');
                }
            }
        }
    });
}

let currentChatPage = 1;
let hasMoreChats = false;
let isLoadingChats = false;
let currentChatsXHR = null;

// Generador local de avatares SVG en memoria (evita 20 peticiones HTTP externas a ui-avatars.com)
function getLocalAvatarSvg(name) {
    let clean = String(name || 'C').trim();
    let parts = clean.split(/\s+/);
    let initials = '';
    if (parts.length >= 2) {
        initials = (parts[0].charAt(0) + parts[1].charAt(0)).toUpperCase();
    } else {
        initials = clean.substring(0, 2).toUpperCase();
    }
    initials = initials.replace(/[^A-Z0-9ÁÉÍÓÚÑ+]/gi, '') || 'CL';
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64"><rect width="64" height="64" rx="32" fill="#F1F5F9"/><text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="#37414A" font-family="Arial, sans-serif" font-size="24" font-weight="bold">${initials}</text></svg>`;
    return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}

let pendingDeepLinkChat = (function () {
    const sp = new URLSearchParams(window.location.search);
    const search = (sp.get('search') || '').trim();
    const convId = parseInt(sp.get('conv_id') || '0', 10);
    const clienteId = parseInt(sp.get('cliente_id') || '0', 10);
    if (!search && !convId && !clienteId) return null;
    return { search, convId, clienteId };
})();

function loadChats(append = false, targetPage = null, silent = false) {
    // Si hay una petición en curso y el usuario cambió de filtro/página/búsqueda, abortar la anterior
    if (currentChatsXHR && currentChatsXHR.readyState !== 4) {
        if (!append && !silent) {
            currentChatsXHR.abort();
            isLoadingChats = false;
        } else {
            return;
        }
    }

    if (!append) {
        currentChatPage = (targetPage && targetPage > 0) ? targetPage : 1;
        if (!silent) {
            $('#chatList').html('<div class="text-center p-4"><div class="spinner-border text-secondary mb-2" role="status"></div><p class="text-muted" style="font-size: 0.85rem;">Cargando conversaciones recientes...</p></div>');
        }
    } else {
        currentChatPage++;
    }

    isLoadingChats = true;
    const id_sede = $('#filterSede').val() || '';
    const id_agente = $('#filterOperador').val() || '';
    const search_query = ($('.search-bar input').val() || '').trim();

    currentChatsXHR = $.ajax({
        url: 'back_bandeja.php',
        type: 'POST',
        dataType: 'json',
        data: { 
            action: 'load_chats', 
            filter: currentFilter, 
            id_sede: id_sede, 
            id_agente: id_agente,
            page: currentChatPage,
            limit: 20,
            search_query: search_query,
            target_conv_id: pendingDeepLinkChat ? (pendingDeepLinkChat.convId || 0) : 0,
            search_phone: pendingDeepLinkChat ? (pendingDeepLinkChat.search || '') : ''
        },
        success: function (response) {
            isLoadingChats = false;
            if (response.status === 'success') {
                if (response.server_now_epoch) {
                    syncServerClock(response.server_now_epoch);
                }
                hasMoreChats = !!response.has_more;
                renderChatList(response.data, append, hasMoreChats);
                if (!append && !silent) {
                    $('#chatList').scrollTop(0);
                }
                if (pendingDeepLinkChat) {
                    const dl = pendingDeepLinkChat;
                    pendingDeepLinkChat = null;
                    if (dl.search) {
                        $('.search-bar input').val(dl.search);
                    }
                    let targetItem = null;
                    if (dl.convId > 0) {
                        targetItem = $(`#chatList .chat-item[data-id="${dl.convId}"]`).first();
                    }
                    if ((!targetItem || !targetItem.length) && dl.clienteId > 0) {
                        targetItem = $(`#chatList .chat-item[data-cliente-id="${dl.clienteId}"]`).first();
                    }
                    if ((!targetItem || !targetItem.length) && dl.search) {
                        const cleanPhone = dl.search.replace(/[^0-9]/g, '');
                        targetItem = $(`#chatList .chat-item`).filter(function () {
                            return String($(this).data('phone') || '').indexOf(cleanPhone) !== -1;
                        }).first();
                    }
                    if (targetItem && targetItem.length) {
                        window.clickChat(targetItem[0]);
                    }
                }
            } else {
                if (!append) {
                    $('#chatList').html(`<div class="p-3 text-danger text-center"><i class="fa-solid fa-triangle-exclamation"></i> Error: ${response.message}</div>`);
                }
            }
        },
        error: function (xhr, status, error) {
            isLoadingChats = false;
            if (status === 'abort' || xhr.status === 401) return;
            console.error("AJAX Error:", status, error, xhr.responseText);
            if (!append) {
                let resp = xhr.responseText ? xhr.responseText.replace(/</g, '&lt;').substring(0, 200) : error;
                $('#chatList').html(`<div class="p-3 text-danger text-center" style="word-break: break-word;"><i class="fa-solid fa-database d-block mb-2 fs-3"></i> <b>Error de Servidor:</b><br><small>${resp}</small></div>`);
            }
        }
    });
}

function renderChatList(chats, append = false, has_more = false) {
    const list = $('#chatList');
    $('#btnLoadMoreContainer').remove();

    let totalUnread = 0;

    if (!append) {
        if (chats.length === 0) {
            let emptyHtml = '<div class="p-4 text-center text-muted" style="font-size:0.85rem;"><i class="fa-solid fa-mug-hot fs-3 mb-2 d-block"></i> No hay conversaciones aquí.</div>';
            if (currentChatPage > 1) {
                emptyHtml += `<div class="px-3 text-center"><button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="loadChats(false, ${currentChatPage - 1})"><i class="fa-solid fa-chevron-left me-1"></i> Volver a página ${currentChatPage - 1}</button></div>`;
            }
            list.html(emptyHtml);
            $('#badgeNoLeidos').hide();
            refreshLiveSlaSemaphores(false);
            return;
        }
        list.empty();
    }

    chats.forEach(chat => {
        let name = chat.cliente_nombre ? chat.cliente_nombre : chat.numero_whatsapp;
        let badge = '';
        let unreadDot = '';
        if (chat.no_leidos > 0) {
            totalUnread++;
            unreadDot = `<span class="badge bg-danger rounded-pill" style="margin-left:auto; font-size: 0.7rem;">${chat.no_leidos}</span>`;
        }

        let isActiveClass = (chat.id == activeChatId && chat.id_cliente == activeClientId) ? 'active' : '';

        let lastTime = chat.ultimo_mensaje_ts ? chat.ultimo_mensaje_ts : chat.fecha_inicio;
        let asesorBadge = chat.nombre_asesor ? `<span class="badge bg-light text-dark rounded-pill px-2 py-0.5 border" style="font-size: 0.65rem; margin-left: 4px;"><i class="fa-solid fa-headset text-muted me-1"></i> ${chat.nombre_asesor}</span>` : '';
        
        let starsHtml = '';
        if (chat.calificacion_calidad && chat.calificacion_calidad > 0) {
            let numStars = parseInt(chat.calificacion_calidad);
            let stars = '';
            for (let i = 1; i <= 5; i++) {
                if (i <= numStars) stars += '<i class="fa-solid fa-star text-warning"></i>';
                else stars += '<i class="fa-regular fa-star text-warning"></i>';
            }
            starsHtml = `<span class="badge bg-light text-dark rounded-pill px-2 py-0.5 border" style="font-size: 0.65rem; margin-left: 4px;" title="Lead Scoring: ${numStars}">${stars}</span>`;
        }
        
        let estadoLabel = chat.estado;
        let estadoColor = 'bg-secondary';
        if (estadoLabel === 'ESPERA_ASIGNACION' || estadoLabel === 'BOT_RECOPILANDO') estadoColor = 'bg-warning text-dark';
        else if (estadoLabel === 'EN_PROCESO') estadoColor = 'bg-primary';
        else if (estadoLabel === 'CERRADO' || estadoLabel === 'RESUELTO') estadoColor = 'bg-success';
        
        let estadoBadge = `<span class="badge ${estadoColor} rounded-pill px-2 py-0.5" style="font-size: 0.6rem; margin-left: 4px;">${estadoLabel.replace('_', ' ')}</span>`;
        
        let tipoBadge = '';
        if (chat.es_campana == 1 || chat.es_campana === '1') {
            tipoBadge = `<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle rounded-pill px-2 py-0.5" style="font-size: 0.65rem; margin-left: 4px;"><i class="fa-solid fa-bullhorn me-1"></i> DIFUSIÓN</span>`;
        } else if (chat.es_venta == 1 || chat.es_venta === '1') {
            tipoBadge = `<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-0.5" style="font-size: 0.65rem; margin-left: 4px;"><i class="fa-solid fa-shopping-bag me-1"></i> VENTA</span>`;
        } else {
            tipoBadge = `<span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill px-2 py-0.5" style="font-size: 0.65rem; margin-left: 4px;"><i class="fa-solid fa-user me-1"></i> CLIENTE</span>`;
        }

        let previewText = 'Haz clic para ver la conversación';
        if (chat.ultimo_mensaje_texto) {
            let rawLast = String(chat.ultimo_mensaje_texto);
            if (rawLast.indexOf('| Texto:') !== -1) {
                previewText = '📢 ' + rawLast.substring(rawLast.indexOf('| Texto:') + 8).trim().replace(/\s+/g, ' ');
            } else if (rawLast.indexOf('starfi_difusion_directa') !== -1) {
                try {
                    let m = rawLast.match(/Params:\s*(\[.*\])/);
                    if (m) {
                        let p = JSON.parse(m[1]);
                        previewText = `📢 Difusión: Hola ${p[0] || ''}, hoy queremos ${p[1] || ''}...`;
                    } else {
                        previewText = '📢 Mensaje de campaña de difusión enviado';
                    }
                } catch (e) {
                    previewText = '📢 Mensaje de campaña de difusión enviado';
                }
            } else if (rawLast.startsWith('Envío dinámico de plantilla:')) {
                previewText = '⚡ ' + rawLast.replace('Envío dinámico de plantilla:', 'Plantilla:').split('.')[0];
            } else {
                previewText = rawLast;
            }
            if (previewText.length > 68) {
                previewText = previewText.substring(0, 65) + '...';
            }
        }

        let dealBadgeHtml = '';
        if (chat.deal_etapa) {
            const montoNum = parseFloat(chat.deal_monto_usd || 0);
            const montoStr = montoNum > 0 ? ` · $${montoNum.toFixed(2)}` : '';
            dealBadgeHtml = `<span class="badge rounded-pill px-2 py-0.5" style="font-size: 0.63rem; margin-left: 4px; background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE;"><i class="fa-solid fa-sack-dollar me-1"></i>${formatEtapaShort(chat.deal_etapa)}${montoStr}</span>`;
        }

        const slaSince = parseInt(chat.sla_wait_since_epoch || 0, 10);
        const slaReplied = parseInt(chat.sla_human_replied || 0, 10);
        const lastCliEp = parseInt(chat.last_client_epoch || 0, 10);

        let html = `
            <article class="chat-item ${isActiveClass}" onclick="window.clickChat(this)" style="cursor: pointer;" data-id="${chat.id}" data-cliente-id="${chat.id_cliente}" data-name="${name.replace(/"/g, '&quot;')}" data-phone="${chat.numero_whatsapp}" data-sede="${chat.nombre_sede || ''}" data-asesor="${chat.nombre_asesor || ''}" data-no-leidos="${chat.no_leidos || 0}" data-sla-since="${slaSince}" data-sla-replied="${slaReplied}" data-last-cli="${lastCliEp}" data-deal-etapa="${chat.deal_etapa || ''}" data-deal-monto="${chat.deal_monto_usd !== null && chat.deal_monto_usd !== undefined ? chat.deal_monto_usd : ''}">
                <div class="chat-avatar">
                    <img src="${getLocalAvatarSvg(name)}" alt="Avatar">
                </div>
                <div class="chat-summary">
                    <div class="chat-top">
                        <h4>${name}</h4>
                        <span class="time" title="${formatFullDateTime(lastTime)}">${formatChatListDate(lastTime)}</span>
                    </div>
                    <div style="font-size: 0.75rem; margin-top: 2px; margin-bottom: 4px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap;">
                        <span class="badge bg-dark rounded-pill px-2 py-0.5 text-white" style="font-size: 0.65rem; background-color: #37414A !important; font-family: var(--font-heading); font-weight: 500;"><i class="fa-solid fa-store me-1"></i> ${chat.nombre_sede || 'Sede Principal'}</span>
                        <span class="sla-slot"></span>
                        ${tipoBadge}
                        ${estadoBadge}
                        ${dealBadgeHtml}
                        ${asesorBadge}
                        ${starsHtml}
                    </div>
                    <div class="chat-bottom" style="display:flex; align-items:center;">
                        <p class="preview" style="margin-right:10px;">${previewText.replace(/</g, '&lt;')}</p>
                        ${badge}
                        ${unreadDot}
                    </div>
                </div>
            </article>
        `;
        list.append(html);
    });

    // Barra de Paginación (20 por página) + opción de acumular en lista
    if (currentChatPage > 1 || has_more) {
        let prevDisabled = (currentChatPage <= 1) ? 'disabled' : '';
        let nextDisabled = (!has_more) ? 'disabled' : '';
        let loadMoreBtnHtml = has_more ? `
            <button id="btnLoadMoreChats" class="btn btn-sm btn-light border text-secondary w-100 mt-2 py-1" style="border-radius: 16px; font-size: 0.78rem; font-weight: 600;">
                <i class="fa-solid fa-plus-circle me-1"></i> Cargar 20 más en esta misma lista
            </button>
        ` : '';

        list.append(`
            <div id="btnLoadMoreContainer" class="p-3 text-center border-top bg-white mx-2 my-2 rounded-3 shadow-sm">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <button type="button" id="btnPrevChatPage" class="btn btn-sm btn-outline-secondary px-3" style="border-radius: 16px; font-weight: 600; font-size: 0.8rem;" ${prevDisabled}>
                        <i class="fa-solid fa-chevron-left me-1"></i> Anterior
                    </button>
                    <span class="badge bg-light text-dark border px-3 py-2" style="font-size: 0.78rem;">
                        Pág. <strong>${currentChatPage}</strong> <span class="text-muted fw-normal">(20/pág)</span>
                    </span>
                    <button type="button" id="btnNextChatPage" class="btn btn-sm btn-outline-primary px-3" style="border-radius: 16px; font-weight: 600; font-size: 0.8rem;" ${nextDisabled}>
                        Siguiente <i class="fa-solid fa-chevron-right ms-1"></i>
                    </button>
                </div>
                ${loadMoreBtnHtml}
            </div>
        `);

        $('#chatList').off('click', '#btnPrevChatPage').on('click', '#btnPrevChatPage', function() {
            if (currentChatPage > 1) {
                loadChats(false, currentChatPage - 1);
            }
        });

        $('#chatList').off('click', '#btnNextChatPage').on('click', '#btnNextChatPage', function() {
            if (hasMoreChats) {
                loadChats(false, currentChatPage + 1);
            }
        });

        $('#chatList').off('click', '#btnLoadMoreChats').on('click', '#btnLoadMoreChats', function() {
            $(this).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Cargando 20 más...').prop('disabled', true);
            loadChats(true);
        });
    }

    if (currentFilter === 'no-leido') {
        if (totalUnread > 0) $('#badgeNoLeidos').text(totalUnread).show();
        else $('#badgeNoLeidos').hide();
    }

    // Actualizar en vivo el Semáforo SLA y disparar alerta sonora si algún chat cruza >15m
    refreshLiveSlaSemaphores(true);
}

// Variables de estado para modal de salida de mensajes no leídos (Administrador)
let pendingExitUnreadChat = null;

function requestSwitchChat(id, cliente_id, name, phone, sede, asesor, no_leidos) {
    if (window.isUserAdmin && pendingExitUnreadChat && pendingExitUnreadChat.id !== id) {
        promptExitUnreadChat(pendingExitUnreadChat, function () {
            selectChat(id, cliente_id, name, phone, sede, asesor, no_leidos);
        });
    } else {
        selectChat(id, cliente_id, name, phone, sede, asesor, no_leidos);
    }
}

function promptExitUnreadChat(chatCtx, onDone) {
    Swal.fire({
        title: `¿Qué deseas hacer con esta conversación?`,
        html: `
            <div class="text-center mb-3">
                <div class="rounded-circle bg-warning bg-opacity-10 text-warning d-inline-flex align-items-center justify-content-center mb-2" style="width: 54px; height: 54px;">
                    <i class="fa-solid fa-envelope-open-text fs-3"></i>
                </div>
                <h6 class="fw-bold text-dark mb-1" style="font-size: 1.05rem;">${chatCtx.name}</h6>
                <p class="text-muted small mb-0">Revisaste esta conversación que tiene mensajes sin leer.</p>
            </div>
            
            <div class="d-grid gap-2 text-start mt-3">
                <button type="button" id="btnOptKeepUnread" class="btn btn-outline-secondary py-2 px-3 text-start fw-semibold d-flex align-items-center justify-content-between" style="border-radius: 10px; font-size: 0.88rem;">
                    <span><i class="fa-solid fa-arrow-left me-2 text-secondary"></i> 1. Salir y dejar como está</span>
                    <span class="badge bg-light text-dark border">Sin cambios</span>
                </button>
                <button type="button" id="btnOptMarkRead" class="btn btn-primary py-2 px-3 text-start fw-semibold d-flex align-items-center justify-content-between" style="border-radius: 10px; font-size: 0.88rem; background-color: #0284C7; border-color: #0284C7;">
                    <span><i class="fa-solid fa-check-double me-2 text-white"></i> 2. Marcar como leído</span>
                    <span class="badge bg-white text-primary">Leído</span>
                </button>
                <button type="button" id="btnOptAssignVendor" class="btn btn-success py-2 px-3 text-start fw-semibold d-flex align-items-center justify-content-between" style="border-radius: 10px; font-size: 0.88rem; background-color: #10B981; border-color: #10B981;">
                    <span><i class="fa-solid fa-user-plus me-2 text-white"></i> 3. Asignar a un operador</span>
                    <span class="badge bg-white text-success">Vendedor</span>
                </button>
            </div>
        `,
        showConfirmButton: false,
        showCancelButton: false,
        allowOutsideClick: false,
        didOpen: () => {
            document.getElementById('btnOptKeepUnread').addEventListener('click', () => {
                Swal.close();
                pendingExitUnreadChat = null;
                if (onDone) onDone();
            });

            document.getElementById('btnOptMarkRead').addEventListener('click', () => {
                $.post('back_bandeja.php', { action: 'mark_as_read', conversacion_id: chatCtx.id }, function () {
                    loadChats();
                }, 'json');
                Swal.close();
                pendingExitUnreadChat = null;
                if (onDone) onDone();
            });

            document.getElementById('btnOptAssignVendor').addEventListener('click', () => {
                Swal.close();
                promptAssignOperator(chatCtx, function () {
                    pendingExitUnreadChat = null;
                    if (onDone) onDone();
                });
            });
        }
    });
}

function promptAssignOperator(chatCtx, onDone) {
    $.ajax({
        url: 'back_bandeja.php', type: 'POST', dataType: 'json',
        data: { action: 'get_agents', conversacion_id: chatCtx.id },
        success: function (res) {
            if (res.status === 'success') {
                let options = '<option value="">-- Selecciona un vendedor/operador --</option>';
                res.data.forEach(ag => {
                    const onlineTag = (parseInt(ag.en_linea || 0, 10) === 1) ? '🟢 En línea' : '⚪ Desconectado';
                    options += `<option value="${ag.id}">${ag.nombre_completo} (${onlineTag})</option>`;
                });

                Swal.fire({
                    title: `Asignar Conversación`,
                    html: `
                        <p class="text-muted small mb-2">Selecciona el vendedor para asignar el chat de <strong>${chatCtx.name}</strong>:</p>
                        <select id="swal_assign_agent" class="form-select mb-3">${options}</select>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Asignar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#10B981',
                    preConfirm: () => {
                        const val = document.getElementById('swal_assign_agent').value;
                        if (!val) {
                            Swal.showValidationMessage('Debes seleccionar un operador.');
                            return false;
                        }
                        return val;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.post('back_bandeja.php', {
                            action: 'reassign_chat',
                            conversacion_id: chatCtx.id,
                            nuevo_agente_id: result.value
                        }, function (r) {
                            if (r.status === 'success') {
                                // También marcar como leído al asignar
                                $.post('back_bandeja.php', { action: 'mark_as_read', conversacion_id: chatCtx.id });
                                Swal.fire({ icon: 'success', title: 'Asignado', text: 'Se ha asignado el chat y notificado al vendedor.', timer: 1800, showConfirmButton: false });
                                loadChats();
                            }
                            if (onDone) onDone();
                        }, 'json');
                    } else {
                        if (onDone) onDone();
                    }
                });
            }
        }
    });
}

function selectChat(id, cliente_id, name, phone, nombre_sede, asesor, no_leidos) {
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
    $('.main-content').addClass('mobile-chat-active');

    $('#chatHeaderName').text(name);
    $('#chatHeaderPhone').text(`+${phone}`);
    $('#chatHeaderImg').attr('src', getLocalAvatarSvg(name));

    if (nombre_sede) {
        $('#chatHeaderSede span').text(nombre_sede);
        $('#chatHeaderSede').css('display', 'inline-block');
    } else {
        $('#chatHeaderSede').hide();
    }
    
    // Asesor asignado
    if ($('#chatHeaderAsesor').length === 0) {
        $('#chatHeaderName').after(`<div id="chatHeaderAsesor" style="display:none; font-size: 0.75rem; margin-top: 2px;"><span class="badge bg-light text-dark rounded-pill px-2 py-0.5 border"><i class="fa-solid fa-headset text-muted me-1"></i> <span></span></span></div>`);
    }
    
    if (asesor) {
        $('#chatHeaderAsesor span span').text(asesor);
        $('#chatHeaderAsesor').css('display', 'block');
    } else {
        $('#chatHeaderAsesor').hide();
    }

    if ($('#modalProfile360').hasClass('show')) {
        loadProfile360();
    }

    // [COM-01] & [COM-02] Pre-sincronizar Ventana 24h y chip de Embudo desde la fila seleccionada sin parpadeo
    const activeItem = $('.chat-item.active').first();
    if (activeItem.length) {
        const lastCliEp = parseInt(activeItem.attr('data-last-cli') || '0', 10);
        activeWindowExpiresAtEpoch = lastCliEp > 0 ? (lastCliEp + 86400) : 0;
        const dEtapa = activeItem.attr('data-deal-etapa') || '';
        const dMonto = activeItem.attr('data-deal-monto');
        activeDealInfo = dEtapa ? { etapa: dEtapa, monto_estimado_usd: parseFloat(dMonto || 0), titulo: '' } : null;
    } else {
        activeWindowExpiresAtEpoch = 0;
        activeDealInfo = null;
    }
    updateActiveWindow24hUI();
    updateHeaderDealChip(activeDealInfo);

    // Registrar si el Administrador abrió un chat NO LEÍDO
    const unreadCount = parseInt(no_leidos || 0);
    if (window.isUserAdmin && unreadCount > 0) {
        pendingExitUnreadChat = {
            id: id,
            cliente_id: cliente_id,
            name: name,
            phone: phone,
            nombre_sede: nombre_sede,
            asesor: asesor,
            no_leidos: unreadCount
        };
    } else {
        pendingExitUnreadChat = null;
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
                $('#profPrevNameInput').val(client.nombre || '');
                $('#profPrevEmailInput').val(client.email || '');
                $('#profPrevPhone').text('+' + client.numero_whatsapp);
                $('#profPrevImg').attr('src', `https://ui-avatars.com/api/?name=${encodeURIComponent(cName)}&background=F3F4F6&size=128`);
                
                // Tags
                renderTags(client.etiquetas);
            }
        }
    });

    // Populate datalist with existing tags
    $.post('back_bandeja.php', { action: 'get_all_tags' }, function(res) {
        if (res.status === 'success' && res.data) {
            let datalist = $('#existingTagsList');
            datalist.empty();
            res.data.forEach(tag => {
                datalist.append(`<option value="${tag}">`);
            });
        }
    }, 'json');
}

function renderTags(tagsData) {
    let container = $('#profTagsContainer');
    container.empty();
    if (!tagsData) return;
    
    let tags = [];
    if (typeof tagsData === 'string') {
        try {
            tags = JSON.parse(tagsData);
        } catch(e) {
            // Fallback for legacy comma-separated
            tags = tagsData.split(',').map(t => t.trim()).filter(t => t);
        }
    } else if (Array.isArray(tagsData)) {
        tags = tagsData;
    }
    
    tags.forEach(t => {
        let tagHtml = `<span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 border border-primary me-1 mb-1">
            ${t} <i class="fa-solid fa-times ms-1" style="cursor:pointer;" onclick="removeTag('${t}')"></i>
        </span>`;
        container.append(tagHtml);
    });
}

window.removeTag = function(tagToRemove) {
    Swal.fire({
        title: '¿Quitar etiqueta?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, quitar'
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('back_bandeja.php', { action: 'remove_tag', cliente_id: activeClientId, tag: tagToRemove }, function(res) {
                if (res.status === 'success') loadProfile360();
            }, 'json');
        }
    });
};

$('#btnAddTag').on('click', function() {
    let newTag = $('#newTagInput').val().trim();
    if (!newTag) return;
    
    $.post('back_bandeja.php', { action: 'add_tag', cliente_id: activeClientId, tag: newTag }, function(res) {
        if (res.status === 'success') {
            $('#newTagInput').val('');
            loadProfile360();
        } else {
            Swal.fire('Error', res.message, 'error');
        }
    }, 'json');
});

$('#newTagInput').on('keypress', function(e) {
    if (e.which == 13) {
        e.preventDefault();
        $('#btnAddTag').click();
    }
});

$('#btnSaveProfile').on('click', function() {
    let nombre = $('#profPrevNameInput').val().trim();
    let email = $('#profPrevEmailInput').val().trim();
    
    $(this).prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i> Guardando...');
    
    $.post('back_bandeja.php', { action: 'save_profile', cliente_id: activeClientId, nombre: nombre, email: email }, function(res) {
        $('#btnSaveProfile').prop('disabled', false).html('<i class="fa-solid fa-save me-2"></i> Guardar Cambios');
        if (res.status === 'success') {
            Swal.fire({
                icon: 'success',
                title: 'Perfil guardado',
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000
            });
            // Update UI
            $('#chatHeaderName').text(nombre || '+' + $('#profPrevPhone').text().replace('+', ''));
            loadChats(); // Refresh the sidebar list to show updated names
        } else {
            Swal.fire('Error', res.message || 'Ocurrió un error al guardar', 'error');
        }
    }, 'json').fail(function(xhr) {
        $('#btnSaveProfile').prop('disabled', false).html('<i class="fa-solid fa-save me-2"></i> Guardar Cambios');
        Swal.fire('Error', 'Error de red o servidor al guardar el perfil.', 'error');
    });
});

function loadMessages(id, scrollToBottom = true) {
    $.ajax({
        url: 'back_bandeja.php',
        type: 'POST',
        dataType: 'json',
        data: { action: 'load_messages', conversacion_id: id, cliente_id: activeClientId || 0 },
        success: function (response) {
            if (response.status === 'success') {
                if (response.window_24h) {
                    syncServerClock(response.window_24h.server_now_epoch);
                    activeWindowExpiresAtEpoch = parseInt(response.window_24h.expires_at_epoch || 0, 10);
                    updateActiveWindow24hUI();
                }
                if (typeof response.deal !== 'undefined') {
                    activeDealInfo = response.deal || null;
                    updateHeaderDealChip(activeDealInfo);
                }
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
    let lastDateKey = null;
    messages.forEach(msg => {
        let msgHtml = '';
        let timeLabel = formatTime(msg.timestamp);

        let parsedDate = parseDateTime(msg.timestamp);
        let dateKey = parsedDate ? `${parsedDate.getFullYear()}-${String(parsedDate.getMonth()+1).padStart(2,'0')}-${String(parsedDate.getDate()).padStart(2,'0')}` : null;

        if (dateKey && dateKey !== lastDateKey) {
            lastDateKey = dateKey;
            let dividerHtml = `
                <div class="date-divider text-center my-3 w-100" style="display: flex; align-items: center; justify-content: center; gap: 10px; margin-top: 15px; margin-bottom: 15px;">
                    <div style="flex: 1; height: 1px; background: #E5E7EB;"></div>
                    <span class="badge bg-light text-secondary border px-3 py-1.5 rounded-pill" style="font-size: 0.75rem; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,0.03);">
                        <i class="fa-regular fa-calendar-days me-1 text-primary"></i> ${formatDateDivider(msg.timestamp)}
                    </span>
                    <div style="flex: 1; height: 1px; background: #E5E7EB;"></div>
                </div>
            `;
            area.append(dividerHtml);
        }

        let mediaHtml = '';
        if (msg.tipo === 'IMAGEN' && msg.url_archivo) {
            msg.url_archivo = msg.url_archivo.replace(/\\\\/g, '/');
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
            msg.url_archivo = msg.url_archivo.replace(/\\\\/g, '/');
            let realUrl = msg.url_archivo.indexOf('/') === -1 ? `../../get_media.php?id=${msg.url_archivo}&chat_id=${activeChatId}` : msg.url_archivo;
            mediaHtml = `<div style="margin-bottom:8px; padding:10px; border-radius:8px; background:#E5E7EB; display:flex; align-items:center; gap:10px;"><i class="fa-solid fa-file-pdf text-danger fs-3"></i> <a href="${realUrl}" target="_blank" style="text-decoration:none; font-weight:bold; color:#111827;">Documento Adjunto</a></div>`;
        } else if (msg.tipo === 'AUDIO' && msg.url_archivo) {
            msg.url_archivo = msg.url_archivo.replace(/\\\\/g, '/');
            let realUrl = `../../get_media.php?id=${encodeURIComponent(msg.url_archivo)}&chat_id=${activeChatId}`;
            mediaHtml = `<div style="margin-bottom:8px;"><audio controls data-src="${realUrl}" class="async-audio" style="max-width: 250px;">Cargando audio...</audio></div>`;
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
        } else if (msg.origen === 'BOT') {
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
                    <div class="message bot-message" style="align-self: flex-end; background-color: #FEF3C7; border: 1px solid #FDE68A; max-width: 80%; border-radius: 18px 18px 2px 18px; padding: 10px 14px; margin-bottom: 10px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <div style="font-size: 0.65rem; color: #D97706; margin-bottom: 6px; border-bottom: 1px solid #FDE68A; padding-bottom: 3px; font-weight: bold; display: flex; align-items: center; gap: 4px;">
                            <i class="fa-solid fa-robot"></i> Asistente Virtual
                        </div>
                        <p style="margin-bottom:0; color: #92400E; font-size: 0.95rem; line-height: 1.4;">${msg.contenido}</p>
                        <div style="text-align: right; margin-top: 4px;">
                            <span class="msg-time" style="color: #B45309; font-size: 0.7rem;">${timeLabel} <i class="fa-solid fa-check-double ms-1" style="color: #60A5FA;"></i></span>
                        </div>
                    </div>
                `;
            }
        } else if (msg.origen === 'EVENTO_SISTEMA') {
            msgHtml = `
                <div class="system-event">
                    <div class="event-pill">
                        <i class="fa-solid fa-info-circle"></i> ${msg.contenido} (${timeLabel})
                    </div>
                </div>
            `;
        } else {
            // AGENTE or API_TRANSACCIONAL
            let colorStlye = msg.origen === 'API_TRANSACCIONAL' ? 'background-color: #ffffff; border: 1px solid #E5E7EB;' : 'background-color: #EFF6FF; border: 1px solid #BFDBFE;';
            let icon = msg.origen === 'API_TRANSACCIONAL' ? '<i class="fa-solid fa-robot text-muted me-1"></i> ' : '';
            
            let displayContent = msg.contenido || '';
            let agentNameHtml = (msg.origen === 'AGENTE' && msg.nombre_agente) ? `<div style="font-size: 0.65rem; color: #6B7280; margin-bottom: 6px; border-bottom: 1px solid #BFDBFE; padding-bottom: 2px;"><i class="fa-solid fa-headset"></i> ${msg.nombre_agente}</div>` : '';
            
            // Intelligent Visual Rendering for Templates
            if (msg.contenido && (msg.contenido.startsWith('Envío dinámico de plantilla:') || msg.contenido.startsWith('[TEMPLATE:'))) {
                try {
                    let tplName = '';
                    let params = [];
                    if (msg.contenido.startsWith('Envío dinámico de plantilla:')) {
                        let match = msg.contenido.match(/plantilla:\s*([^.]+)\.\s*Params:\s*(\[.*?\])/s);
                        if (match) {
                            tplName = match[1].trim();
                            params = JSON.parse(match[2]);
                        }
                    } else if (msg.contenido.startsWith('[TEMPLATE:')) {
                        let match2 = msg.contenido.match(/^\[TEMPLATE:([^:]+):(.*)\]$/s);
                        if (match2) {
                            tplName = match2[1].trim();
                            params = match2[2].split('|');
                        }
                    }
                    if (tplName && Array.isArray(params)) {
                        let idxRendered = String(msg.contenido).indexOf('| Texto:');
                        if (idxRendered !== -1) {
                            let renderedBodyText = String(msg.contenido).substring(idxRendered + 8).trim();
                            displayContent = `
                                <div style="border-left: 3px solid #E85B14; padding-left: 12px; margin-bottom: 8px;">
                                    <div style="font-size:0.7rem; color:#E85B14; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; display:flex; align-items:center; gap:5px;">
                                        <i class="fa-solid fa-bullhorn"></i> Difusión: ${tplName}
                                    </div>
                                    <div style="font-size:0.9rem; line-height:1.5; color:#1E293B; white-space:pre-line;">${renderedBodyText}</div>
                                </div>
                                <div style="font-size:0.7rem; color:#9CA3AF; margin-top:6px; display:flex; align-items:center; justify-content:space-between; border-top:1px solid #F3F4F6; padding-top:6px;">
                                    <span>Servicio de mensajería inteligente de STARFI CRM</span>
                                    <i class="fa-solid fa-paper-plane text-primary"></i>
                                </div>
                            `;
                        } else if (tplName === 'starfi_difusion_directa' && params.length >= 3) {
                            displayContent = `
                                <div style="border-left: 3px solid #E85B14; padding-left: 12px; margin-bottom: 8px;">
                                    <div style="font-size:0.7rem; color:#E85B14; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:6px; display:flex; align-items:center; gap:5px;">
                                        <i class="fa-solid fa-bullhorn"></i> Difusión: ${tplName}
                                    </div>
                                    <div style="font-size:0.9rem; line-height:1.5; color:#1E293B; white-space:pre-line;">👋¡Hola <strong>${params[0]}</strong>!
Hoy queremos ${params[1]}.
Recuerda que en ${params[2]}.
📲 <strong>Responde a este mensaje para obtener más información</strong>👇</div>
                                </div>
                                <div style="font-size:0.7rem; color:#9CA3AF; margin-top:6px; display:flex; align-items:center; justify-content:space-between; border-top:1px solid #F3F4F6; padding-top:6px;">
                                    <span>Servicio de mensajería inteligente de STARFI CRM</span>
                                    <i class="fa-solid fa-paper-plane text-primary"></i>
                                </div>
                            `;
                        } else {
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
                        }
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
                let errorDetail = msg.error_detalle ? msg.error_detalle.replace(/"/g, '&quot;') : 'No se pudo entregar el mensaje a través de Meta WhatsApp Cloud API.';
                let safeDetailJs = errorDetail.replace(/'/g, "\\'").replace(/\n/g, ' ');
                
                estadoIcon = `<i class="fa-solid fa-circle-exclamation ms-1 text-danger cursor-pointer" onclick="window.showErrorDetail('${safeDetailJs}')" title="${errorDetail}" style="cursor:pointer; font-size: 0.9rem;"></i>
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
    
    // Async audio fetcher to bypass Cloudflare streaming restrictions
    setTimeout(() => {
        document.querySelectorAll('.async-audio').forEach(audio => {
            if (!audio.src && audio.dataset.src && !audio.dataset.fetching) {
                audio.dataset.fetching = 'true';
                fetch(audio.dataset.src)
                    .then(res => res.blob())
                    .then(blob => {
                        audio.src = URL.createObjectURL(blob);
                        audio.innerHTML = '';
                    })
                    .catch(err => {
                        console.error('Error fetching audio:', err);
                        audio.innerHTML = 'Error al cargar audio';
                    });
            }
        });
    }, 100);

    if (scrollToBottom) area.scrollTop(area[0].scrollHeight);
}

window.showErrorDetail = function (detail) {
    Swal.fire({
        icon: 'error',
        title: 'Motivo del Fallo de Envío',
        html: `
            <div class="text-start p-2" style="font-size: 0.9rem;">
                <p class="mb-2"><b>Información técnica devuelta por Meta / Servidor:</b></p>
                <div class="alert alert-danger mb-3" style="font-size: 0.85rem; word-break: break-word; line-height: 1.5;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> ${detail || 'No se pudo entregar el mensaje a través de Meta WhatsApp Cloud API.'}
                </div>
                <div class="p-2 rounded bg-light border" style="font-size: 0.8rem; color: #4B5563;">
                    <p class="fw-bold mb-1"><i class="fa-solid fa-lightbulb text-warning me-1"></i> Recomendaciones:</p>
                    <ul class="mb-0 ps-3">
                        <li><b>Si excedió las 24 horas:</b> Solicita al cliente que envíe un mensaje para reabrir la ventana o envía una plantilla de WhatsApp.</li>
                        <li><b>Si fue rechazado por el número:</b> Verifique que el número posea WhatsApp activo y prefijo de país correcto (+58).</li>
                        <li><b>Si fue rechazado por Meta:</b> Revisa la configuración de la línea en Meta Business Manager.</li>
                    </ul>
                </div>
            </div>
        `,
        confirmButtonText: 'Entendido',
        confirmButtonColor: '#E85B14'
    });
};

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

    // [COM-01] Bloqueo preventivo en cliente si expiró la ventana de 24h y no es Nota Interna
    if (!isInternal && activeWindowExpiresAtEpoch <= getServerNowEpoch()) {
        updateActiveWindow24hUI();
        promptWindow24hExpiredModal('La ventana gratuita de 24h de Meta ha expirado. Envía una Plantilla Oficial Meta para reactivar la conversación.');
        return;
    }

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
                if (!isInternal) {
                    loadChats(false, currentChatPage, true);
                }
            } else if (response.code === 'WINDOW_24H_EXPIRED') {
                activeWindowExpiresAtEpoch = 0;
                updateActiveWindow24hUI();
                loadMessages(activeChatId, true);
                promptWindow24hExpiredModal(response.message);
            } else {
                Swal.fire('Error', response.message, 'error');
            }
        }
    });
}

function parseDateTime(datetimeStr) {
    if (!datetimeStr) return null;
    let str = String(datetimeStr).replace(' ', 'T');
    let d = new Date(str);
    if (isNaN(d.getTime())) {
        d = new Date(datetimeStr);
    }
    return isNaN(d.getTime()) ? null : d;
}

function formatChatListDate(datetimeStr) {
    const d = parseDateTime(datetimeStr);
    if (!d) return '';

    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    const msgDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const timeStr = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    if (msgDate.getTime() === today.getTime()) {
        return timeStr;
    } else if (msgDate.getTime() === yesterday.getTime()) {
        return 'Ayer';
    } else {
        const day = String(d.getDate()).padStart(2, '0');
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const year = d.getFullYear();
        if (year === now.getFullYear()) {
            return `${day}/${month}`;
        }
        return `${day}/${month}/${year}`;
    }
}

function formatDateDivider(datetimeStr) {
    const d = parseDateTime(datetimeStr);
    if (!d) return '';

    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    const msgDate = new Date(d.getFullYear(), d.getMonth(), d.getDate());

    const months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    const days = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

    const dayName = days[d.getDay()];
    const dayNum = d.getDate();
    const monthName = months[d.getMonth()];
    const year = d.getFullYear();

    if (msgDate.getTime() === today.getTime()) {
        return `Hoy - ${dayNum} de ${monthName}`;
    } else if (msgDate.getTime() === yesterday.getTime()) {
        return `Ayer - ${dayNum} de ${monthName}`;
    } else {
        if (year === now.getFullYear()) {
            return `${dayName}, ${dayNum} de ${monthName}`;
        }
        return `${dayName}, ${dayNum} de ${monthName} de ${year}`;
    }
}

function formatFullDateTime(datetimeStr) {
    const d = parseDateTime(datetimeStr);
    if (!d) return '';
    const day = String(d.getDate()).padStart(2, '0');
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const year = d.getFullYear();
    const timeStr = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return `${day}/${month}/${year} ${timeStr}`;
}

function formatTime(datetimeStr) {
    const d = parseDateTime(datetimeStr);
    if (!d) return '';
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

// Back to chats list on mobile
$(document).on('click', '#btnBackToChats', function() {
    $('.main-content').removeClass('mobile-chat-active');
    activeChatId = null;
    activeClientId = null;
    $('.chat-item').removeClass('active');
});

// ============================================================================
// [SPRINT 2] HERRAMIENTAS ENTERPRISE DE CIERRE COMERCIAL Y CONTROL SLA
// [COM-01] Contador regresivo Ventana Meta 24h + Bloqueo + Plantilla Oficial
// [COM-02] Integración rápida con Embudo de Ventas Kanban (USD)
// [COM-03] Disponibilidad y Presencia en Línea para Enrutamiento Round-Robin
// [COM-04] Semáforo SLA en vivo (<5m, 5-15m, >15m) con Alerta Sonora
// ============================================================================

function initSprint2EnterpriseTools() {
    renderSlaSoundButton();
    renderAvailabilityButton();

    // Reloj maestro en vivo (1 seg) para cuenta regresiva 24h y Semáforo SLA
    setInterval(function () {
        if (activeChatId !== null) {
            updateActiveWindow24hUI();
        }
        refreshLiveSlaSemaphores(true);
    }, 1000);

    // Heartbeat de presencia cada 45 seg para mantener al asesor elegible en Round-Robin ([COM-03])
    setInterval(function () {
        $.post('back_bandeja.php', { action: 'heartbeat' }, function (res) {
            if (res && res.status === 'success') {
                if (res.server_now_epoch) syncServerClock(res.server_now_epoch);
                if (typeof res.disponible_chat !== 'undefined') {
                    agentAvailable = (parseInt(res.disponible_chat, 10) === 1);
                    renderAvailabilityButton();
                }
            }
        }, 'json');
    }, 45000);

    // [COM-03] Toggle En Línea / Pausado para Round-Robin
    $('#btnAvailabilityToggle').on('click', function () {
        const nextVal = agentAvailable ? 0 : 1;
        $(this).prop('disabled', true);
        $.post('back_bandeja.php', { action: 'toggle_availability', disponible: nextVal }, function (res) {
            $('#btnAvailabilityToggle').prop('disabled', false);
            if (res && res.status === 'success') {
                agentAvailable = (parseInt(res.disponible_chat, 10) === 1);
                renderAvailabilityButton();
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: agentAvailable ? 'success' : 'info',
                    title: agentAvailable
                        ? 'En línea: recibirás leads automáticos por Round-Robin'
                        : 'Pausado: no recibirás nuevas asignaciones automáticas',
                    showConfirmButton: false,
                    timer: 2600
                });
            }
        }, 'json').fail(function () {
            $('#btnAvailabilityToggle').prop('disabled', false);
        });
    });

    // [COM-04] Toggle Alerta Sonora SLA
    $('#btnSlaSoundToggle').on('click', function () {
        slaSoundEnabled = !slaSoundEnabled;
        localStorage.setItem('starfi_sla_sound', slaSoundEnabled ? '1' : '0');
        renderSlaSoundButton();
        if (slaSoundEnabled) {
            playSlaAlertChime();
        }
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: 'info',
            title: slaSoundEnabled ? 'Alerta sonora SLA (>15 min) activada' : 'Alerta sonora SLA silenciada',
            showConfirmButton: false,
            timer: 1800
        });
    });

    // [COM-04] Clic directo en contador rojo filtra pestaña SLA >15m
    $('#slaQuickFilterRed').on('click', function () {
        const tabCrit = $('.tabs .tab[data-target="sla-critico"]');
        if (tabCrit.length) {
            tabCrit.trigger('click');
        }
    });

    // [COM-01] Abrir modal de Reactivación con Plantilla Oficial Meta
    $('#btnReactivateTemplate, #btnHeaderMetaTemplate').on('click', function () {
        if (activeChatId === null && !activeClientId) {
            Swal.fire({ icon: 'warning', text: 'Selecciona una conversación primero.' });
            return;
        }
        openMetaReactivateModal(false);
    });

    $('#btnRefreshMetaTpls').on('click', function () {
        openMetaReactivateModal(true);
    });

    $('#selectMetaOfficialTpl').on('change', function () {
        const idx = parseInt($(this).val() || '0', 10);
        renderMetaTplSelection(idx);
    });

    $(document).on('input', '.meta-tpl-var-input, #metaTplHeaderInput', function () {
        const idx = parseInt($('#selectMetaOfficialTpl').val() || '0', 10);
        const tpl = metaTemplatesCache[idx];
        if (tpl) {
            $('#metaTplLivePreview').text(buildMetaTplPreviewText(tpl));
        }
    });

    $('#btnSendMetaReactivate').on('click', function () {
        submitMetaTemplateReactivation();
    });

    // [COM-02] Modal rápido de Embudo y Monto USD desde cabecera del chat
    $('#btnQuickDeal').on('click', function () {
        if (activeChatId === null && !activeClientId) return;
        const clientName = ($('#chatHeaderName').text() || '').trim();
        $('#qdTitulo').val((activeDealInfo && activeDealInfo.titulo) ? activeDealInfo.titulo : (clientName ? `Oportunidad - ${clientName}` : 'Oportunidad Comercial'));
        $('#qdEtapa').val((activeDealInfo && activeDealInfo.etapa) ? activeDealInfo.etapa : 'NUEVO_LEAD');
        $('#qdMontoUsd').val((activeDealInfo && activeDealInfo.monto_estimado_usd !== undefined) ? parseFloat(activeDealInfo.monto_estimado_usd).toFixed(2) : '0.00');
        $('#modalQuickDeal').modal('show');
    });

    $('#btnSaveQuickDeal').on('click', function () {
        const btn = $(this);
        const etapa = $('#qdEtapa').val() || 'NUEVO_LEAD';
        const monto = parseFloat($('#qdMontoUsd').val() || '0') || 0;
        const titulo = ($('#qdTitulo').val() || '').trim();

        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');
        $.post('back_bandeja.php', {
            action: 'quick_save_deal',
            conversacion_id: activeChatId || 0,
            cliente_id: activeClientId || 0,
            etapa: etapa,
            monto_estimado_usd: monto.toFixed(2),
            titulo: titulo
        }, function (res) {
            btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar en Embudo');
            if (res && res.status === 'success') {
                activeDealInfo = res.deal || { etapa: etapa, monto_estimado_usd: monto, titulo: titulo };
                updateHeaderDealChip(activeDealInfo);
                $('#modalQuickDeal').modal('hide');
                if (activeChatId) loadMessages(activeChatId, true);
                loadChats(false, currentChatPage, true);
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Embudo actualizado ($' + monto.toFixed(2) + ' USD)',
                    showConfirmButton: false,
                    timer: 2200
                });
            } else {
                Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo actualizar el embudo.', 'error');
            }
        }, 'json').fail(function () {
            btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar en Embudo');
            Swal.fire('Error', 'Fallo de red al guardar la oportunidad.', 'error');
        });
    });
}

function renderAvailabilityButton() {
    const btn = $('#btnAvailabilityToggle');
    if (!btn.length) return;
    if (agentAvailable) {
        btn.css({ background: '#ECFDF5', color: '#059669', borderColor: '#A7F3D0' });
        btn.find('span').text('En línea');
    } else {
        btn.css({ background: '#FEF2F2', color: '#DC2626', borderColor: '#FECACA' });
        btn.find('span').text('Pausado');
    }
}

function renderSlaSoundButton() {
    const btn = $('#btnSlaSoundToggle');
    if (!btn.length) return;
    if (slaSoundEnabled) {
        btn.html('<i class="fa-solid fa-volume-high text-success"></i>');
    } else {
        btn.html('<i class="fa-solid fa-volume-xmark text-muted"></i>');
    }
}

function formatCountdownHMS(totalSeconds) {
    const sec = Math.max(0, Math.floor(totalSeconds));
    const h = String(Math.floor(sec / 3600)).padStart(2, '0');
    const m = String(Math.floor((sec % 3600) / 60)).padStart(2, '0');
    const s = String(sec % 60).padStart(2, '0');
    return `${h}:${m}:${s}`;
}

function updateActiveWindow24hUI() {
    const badge = $('#metaWindowBadge');
    const timerText = $('#metaWindowTimerText');
    const banner = $('#expiredWindowBanner');
    const inputBox = $('#inputBoxContainer');
    const chatInput = $('#chatInput');
    const sendBtn = $('#sendBtn');
    const btnAttach = $('#btnAttach');
    const isNoteMode = $('#btnInternalNote').hasClass('note-mode');

    if (activeChatId === null && !activeClientId) {
        badge.hide();
        banner.removeClass('active');
        inputBox.removeClass('locked-24h');
        chatInput.prop('disabled', false);
        sendBtn.prop('disabled', false);
        btnAttach.prop('disabled', false).css('opacity', '');
        return;
    }

    const remaining = Math.max(0, activeWindowExpiresAtEpoch - getServerNowEpoch());
    badge.removeClass('win-open win-warn win-crit win-expired').css('display', 'inline-flex');

    if (remaining > 0) {
        const hms = formatCountdownHMS(remaining);
        if (remaining <= 1800) {
            badge.addClass('win-crit');
            timerText.text(`24h · ${hms}`);
        } else if (remaining <= 7200) {
            badge.addClass('win-warn');
            timerText.text(`24h · ${hms}`);
        } else {
            badge.addClass('win-open');
            timerText.text(`24h · ${hms}`);
        }

        banner.removeClass('active');
        btnAttach.prop('disabled', false).css('opacity', '');
        inputBox.removeClass('locked-24h');
        chatInput.prop('disabled', false);
        sendBtn.prop('disabled', false);
        if (!isNoteMode) {
            chatInput.attr('placeholder', 'Escribe un mensaje...');
        }
    } else {
        badge.addClass('win-expired');
        timerText.text('Ventana 24h Expirada');
        banner.addClass('active');
        btnAttach.prop('disabled', true).css('opacity', '0.45');

        if (isNoteMode) {
            // Permitir escribir Notas Internas privadas aunque la ventana de Meta haya expirado
            inputBox.removeClass('locked-24h');
            chatInput.prop('disabled', false).attr('placeholder', 'Escribiendo nota interna (oculta al cliente)...');
            sendBtn.prop('disabled', false);
        } else {
            inputBox.addClass('locked-24h');
            chatInput.prop('disabled', true).attr('placeholder', 'Ventana Meta 24h cerrada — Reactiva con Plantilla Oficial Meta o usa Nota Interna...');
            sendBtn.prop('disabled', true);
        }
    }
}

function promptWindow24hExpiredModal(msg) {
    Swal.fire({
        icon: 'warning',
        title: 'Ventana Meta de 24h Expirada',
        text: msg || 'Han pasado más de 24 horas desde el último mensaje del cliente. Para volver a contactarlo debes enviar una Plantilla Oficial aprobada por Meta.',
        showCancelButton: true,
        confirmButtonColor: '#E85B14',
        confirmButtonText: '<i class="fa-brands fa-whatsapp me-1"></i> Reactivar con Plantilla Oficial',
        cancelButtonText: 'Cancelar'
    }).then((res) => {
        if (res.isConfirmed) {
            openMetaReactivateModal(false);
        }
    });
}

function openMetaReactivateModal(forceSync) {
    $('#modalReactivateMeta').modal('show');
    $('#selectMetaOfficialTpl').html('<option value="">Cargando plantillas aprobadas de Meta...</option>').prop('disabled', true);
    $('#metaTplVarsContainer').html('<div class="text-muted small py-3"><i class="fa-solid fa-spinner fa-spin me-2"></i>Consultando catálogo oficial de Meta...</div>');
    $('#metaTplMetaBadges').empty();
    $('#metaTplLivePreview').text('Cargando vista previa...');
    $('#btnSendMetaReactivate').prop('disabled', true);

    $.post('back_bandeja.php', {
        action: 'get_meta_templates_bandeja',
        conversacion_id: activeChatId || 0,
        cliente_id: activeClientId || 0,
        force_sync: forceSync ? 1 : 0
    }, function (res) {
        if (res && res.status === 'success' && Array.isArray(res.templates) && res.templates.length > 0) {
            metaTemplatesCache = res.templates;
            metaTemplatesSedeName = res.nombre_sede || 'Nuestra Sede';
            let opts = '';
            metaTemplatesCache.forEach((tpl, idx) => {
                opts += `<option value="${idx}">${tpl.name} (${tpl.language} · ${tpl.category})</option>`;
            });
            $('#selectMetaOfficialTpl').html(opts).prop('disabled', false);
            $('#btnSendMetaReactivate').prop('disabled', false);
            renderMetaTplSelection(0);
        } else {
            $('#selectMetaOfficialTpl').html('<option value="">Sin plantillas disponibles</option>');
            $('#metaTplVarsContainer').html(`<div class="alert alert-warning small mb-0">${(res && res.message) ? res.message : 'No se encontraron plantillas aprobadas para esta sede.'}</div>`);
        }
    }, 'json').fail(function () {
        $('#metaTplVarsContainer').html('<div class="alert alert-danger small mb-0">Error de red al consultar las plantillas oficiales de Meta.</div>');
    });
}

function extractPlaceholderNumbers(text) {
    if (!text) return [];
    const matches = String(text).match(/\{\{(\d+)\}\}/g) || [];
    const nums = matches.map(m => parseInt(m.replace(/\D/g, ''), 10)).filter(n => n > 0);
    return Array.from(new Set(nums)).sort((a, b) => a - b);
}

function renderMetaTplSelection(idx) {
    const tpl = metaTemplatesCache[idx];
    if (!tpl) return;

    let badgesHtml = `
        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1"><i class="fa-solid fa-check-circle me-1"></i>APROBADA</span>
        <span class="badge bg-light text-dark border rounded-pill px-2.5 py-1">${tpl.category || 'UTILITY'}</span>
        <span class="badge bg-light text-secondary border rounded-pill px-2.5 py-1">Idioma: ${tpl.language || 'es'}</span>
    `;
    if (tpl.header_format && tpl.header_format !== 'NONE') {
        badgesHtml += `<span class="badge bg-info-subtle text-info-emphasis border rounded-pill px-2.5 py-1">Encabezado: ${tpl.header_format}</span>`;
    }
    $('#metaTplMetaBadges').html(badgesHtml);

    const container = $('#metaTplVarsContainer');
    container.empty();

    const rawClientName = ($('#chatHeaderName').text() || '').trim();
    const cleanClientName = (rawClientName && !/^\+?\d+$/.test(rawClientName)) ? rawClientName : 'Cliente';

    // Si el encabezado es TEXT y tiene {{1}}
    if (tpl.header_format === 'TEXT' && tpl.header_text && tpl.header_text.indexOf('{{1}}') !== -1) {
        container.append(`
            <div class="mb-2">
                <label class="form-label small fw-bold text-secondary mb-1">Variable de Encabezado {{1}}</label>
                <input type="text" id="metaTplHeaderInput" class="form-control form-control-sm" value="${cleanClientName.replace(/"/g, '&quot;')}">
            </div>
        `);
    }

    const bodyVars = extractPlaceholderNumbers(tpl.body || '');
    if (bodyVars.length === 0) {
        container.append('<div class="alert alert-light border small text-muted mb-0"><i class="fa-solid fa-circle-check text-success me-1"></i>Esta plantilla no requiere variables adicionales. Lista para enviar.</div>');
    } else {
        container.append('<label class="form-label fw-bold text-dark small mb-2">2. Completa los parámetros de la plantilla</label>');
        bodyVars.forEach((varNum, i) => {
            let defVal = '';
            if (varNum === 1) {
                defVal = cleanClientName;
            } else if (tpl.name === 'starfi_difusion_directa' && varNum === 2) {
                defVal = 'retomar tu solicitud pendiente y brindarte atención inmediata';
            } else if (tpl.name === 'starfi_difusion_directa' && varNum === 3) {
                defVal = `${metaTemplatesSedeName} contamos con disponibilidad inmediata para ayudarte`;
            } else if (Array.isArray(tpl.body_examples) && tpl.body_examples[i]) {
                defVal = tpl.body_examples[i];
            }
            container.append(`
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text fw-bold bg-white text-primary">{{${varNum}}}</span>
                    <input type="text" class="form-control meta-tpl-var-input" data-var="${varNum}" value="${String(defVal).replace(/"/g, '&quot;')}" placeholder="Valor para {{${varNum}}}...">
                </div>
            `);
        });
    }

    $('#metaTplLivePreview').text(buildMetaTplPreviewText(tpl));
}

function buildMetaTplPreviewText(tpl) {
    let headerPart = '';
    if (tpl.header_format === 'TEXT' && tpl.header_text) {
        headerPart = tpl.header_text;
        const hVal = ($('#metaTplHeaderInput').val() || '').trim();
        if (headerPart.indexOf('{{1}}') !== -1) {
            headerPart = headerPart.replace(/\{\{1\}\}/g, hVal || '{{1}}');
        }
        headerPart += '\n\n';
    }
    let bodyPart = String(tpl.body || '');
    $('.meta-tpl-var-input').each(function () {
        const vNum = $(this).data('var');
        const vVal = ($(this).val() || '').trim();
        const regex = new RegExp('\\{\\{' + vNum + '\\}\\}', 'g');
        bodyPart = bodyPart.replace(regex, vVal || `{{${vNum}}}`);
    });
    const footerPart = tpl.footer ? `\n\n${tpl.footer}` : '';
    return `${headerPart}${bodyPart}${footerPart}`.trim();
}

function submitMetaTemplateReactivation() {
    const idx = parseInt($('#selectMetaOfficialTpl').val() || '0', 10);
    const tpl = metaTemplatesCache[idx];
    if (!tpl) return;

    const params = [];
    let missing = false;
    $('.meta-tpl-var-input').each(function () {
        const val = ($(this).val() || '').trim();
        if (!val) missing = true;
        params.push(val);
    });
    if (missing) {
        Swal.fire({ icon: 'warning', text: 'Completa todas las variables {{...}} de la plantilla antes de enviarla.' });
        return;
    }

    const headerParam = ($('#metaTplHeaderInput').val() || '').trim();
    const previewText = buildMetaTplPreviewText(tpl);
    const btn = $('#btnSendMetaReactivate');
    btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Enviando a Meta...');

    $.post('back_bandeja.php', {
        action: 'send_meta_template_reactivate',
        conversacion_id: activeChatId || 0,
        cliente_id: activeClientId || 0,
        template_name: tpl.name,
        template_language: tpl.language || 'es',
        template_category: tpl.category || 'UTILITY',
        header_format: tpl.header_format || 'NONE',
        header_param_text: headerParam,
        body_params: JSON.stringify(params),
        rendered_preview: previewText
    }, function (res) {
        btn.prop('disabled', false).html('<i class="fa-solid fa-paper-plane me-1"></i> Enviar Plantilla Oficial');
        if (res && res.new_chat_id && (!activeChatId || activeChatId === 0)) {
            activeChatId = parseInt(res.new_chat_id, 10);
            $('.chat-item.active').attr('data-id', activeChatId);
        }
        if (res && res.status === 'success') {
            $('#modalReactivateMeta').modal('hide');
            if (activeChatId) loadMessages(activeChatId, true);
            loadChats(false, currentChatPage, true);
            Swal.fire({
                icon: 'success',
                title: 'Plantilla Oficial Enviada',
                text: res.message || 'Plantilla entregada a WhatsApp Cloud API. Al responder el cliente se reabrirán las 24h gratuitas.',
                confirmButtonColor: '#10B981'
            });
        } else {
            if (activeChatId) loadMessages(activeChatId, true);
            Swal.fire('Aviso de Meta Cloud API', (res && res.message) ? res.message : 'No se pudo entregar la plantilla.', 'error');
        }
    }, 'json').fail(function () {
        btn.prop('disabled', false).html('<i class="fa-solid fa-paper-plane me-1"></i> Enviar Plantilla Oficial');
        Swal.fire('Error', 'Fallo de conexión al enviar la plantilla oficial.', 'error');
    });
}

function formatEtapaShort(etapa) {
    const map = {
        'NUEVO_LEAD': 'Nuevo Lead',
        'COTIZADO': 'Cotizado',
        'EN_NEGOCIACION': 'En Negociación',
        'PAGO_PENDIENTE': 'Pago Pendiente',
        'GANADO': 'Ganado',
        'PERDIDO': 'Perdido'
    };
    return map[String(etapa || '').toUpperCase()] || 'Nuevo Lead';
}

function updateHeaderDealChip(deal) {
    const chipText = $('#chatDealChipText');
    if (!chipText.length) return;
    if (deal && deal.etapa) {
        const monto = parseFloat(deal.monto_estimado_usd || 0) || 0;
        chipText.text(`${formatEtapaShort(deal.etapa)} · $${monto.toFixed(2)} USD`);
    } else {
        chipText.text('Nuevo Lead · $0.00 USD');
    }
}

function formatSlaDuration(sec) {
    const s = Math.max(0, Math.floor(sec));
    if (s < 60) return '<1m';
    const mins = Math.floor(s / 60);
    if (mins < 60) return `${mins}m`;
    const hrs = Math.floor(mins / 60);
    const remM = mins % 60;
    if (hrs < 24) return remM > 0 ? `${hrs}h ${remM}m` : `${hrs}h`;
    const days = Math.floor(hrs / 24);
    return `${days}d ${hrs % 24}h`;
}

function refreshLiveSlaSemaphores(canTriggerAudio) {
    const nowEp = getServerNowEpoch();
    let greenCount = 0;
    let yellowCount = 0;
    let redCount = 0;
    const newlyBreached = [];

    $('#chatList .chat-item').each(function () {
        const $item = $(this);
        const chatId = parseInt($item.attr('data-id') || '0', 10);
        const slaSince = parseInt($item.attr('data-sla-since') || '0', 10);
        const slaReplied = parseInt($item.attr('data-sla-replied') || '0', 10);
        const $slot = $item.find('.sla-slot');

        $item.removeClass('sla-item-yellow sla-item-red');

        if (slaSince > 0) {
            const waitSec = Math.max(0, nowEp - slaSince);
            const dur = formatSlaDuration(waitSec);
            if (waitSec < 300) {
                // Verde (<5 min)
                greenCount++;
                $slot.html(`<span class="sla-pill sla-green" title="SLA Verde (<5 min sin respuesta humana)">🟢 ${dur}</span>`);
                if (chatId > 0) slaAlertedChatIds.delete(chatId);
            } else if (waitSec <= 900) {
                // Amarillo (5-15 min)
                yellowCount++;
                $item.addClass('sla-item-yellow');
                $slot.html(`<span class="sla-pill sla-yellow" title="SLA Amarillo (5–15 min sin respuesta humana)">🟡 ${dur}</span>`);
                if (chatId > 0) slaAlertedChatIds.delete(chatId);
            } else {
                // Rojo (>15 min sin respuesta humana)
                redCount++;
                $item.addClass('sla-item-red');
                $slot.html(`<span class="sla-pill sla-red" title="SLA Rojo Crítico (>15 min sin respuesta humana)">🔴 ${dur}</span>`);
                if (chatId > 0 && !slaAlertedChatIds.has(chatId)) {
                    slaAlertedChatIds.add(chatId);
                    newlyBreached.push($item.attr('data-name') || (`Chat #${chatId}`));
                }
            }
        } else if (slaReplied === 1) {
            $slot.html('<span class="sla-pill sla-replied" title="Respondido por asesor humano">✓ Al día</span>');
            if (chatId > 0) slaAlertedChatIds.delete(chatId);
        } else {
            $slot.empty();
        }
    });

    $('#slaCountGreen').text(greenCount);
    $('#slaCountYellow').text(yellowCount);
    $('#slaCountRed').text(redCount);

    const badgeCrit = $('#badgeSlaCritico');
    if (redCount > 0) {
        badgeCrit.text(redCount).show();
    } else {
        badgeCrit.hide();
    }

    if (canTriggerAudio && newlyBreached.length > 0) {
        if (slaSoundEnabled) {
            playSlaAlertChime();
        }
        const sampleNames = newlyBreached.slice(0, 2).join(', ');
        const extra = newlyBreached.length > 2 ? ` (+${newlyBreached.length - 2} más)` : '';
        Swal.fire({
            toast: true,
            position: 'bottom-end',
            icon: 'warning',
            title: `🔴 Alerta SLA (>15 min): ${sampleNames}${extra}`,
            showConfirmButton: false,
            timer: 4500
        });
    }
}

let sharedSlaAudioCtx = null;
function playSlaAlertChime() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        if (!sharedSlaAudioCtx) {
            sharedSlaAudioCtx = new AudioCtx();
        }
        if (sharedSlaAudioCtx.state === 'suspended') {
            sharedSlaAudioCtx.resume().catch(() => {});
        }
        const now = sharedSlaAudioCtx.currentTime;
        const notes = [
            { freq: 880, start: 0.0, dur: 0.13 },
            { freq: 1174.66, start: 0.16, dur: 0.16 },
            { freq: 880, start: 0.35, dur: 0.20 }
        ];
        notes.forEach(n => {
            const osc = sharedSlaAudioCtx.createOscillator();
            const gain = sharedSlaAudioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(n.freq, now + n.start);
            gain.gain.setValueAtTime(0.0001, now + n.start);
            gain.gain.exponentialRampToValueAtTime(0.18, now + n.start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + n.start + n.dur);
            osc.connect(gain);
            gain.connect(sharedSlaAudioCtx.destination);
            osc.start(now + n.start);
            osc.stop(now + n.start + n.dur + 0.02);
        });
    } catch (e) {
        // Ignorar bloqueo de autoplay del navegador antes de la primera interacción
    }
}
