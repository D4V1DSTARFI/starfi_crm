// modules/pipeline/funciones_pipeline.js
// Controlador Drag & Drop para Tablero Kanban de Embudo de Ventas (USD) | STARFI CRM [COM-02]

const PIPELINE_STAGES = ['NUEVO_LEAD', 'COTIZADO', 'EN_NEGOCIACION', 'PAGO_PENDIENTE', 'GANADO', 'PERDIDO'];
const STAGE_PROB = {
    'NUEVO_LEAD': 20,
    'COTIZADO': 45,
    'EN_NEGOCIACION': 70,
    'PAGO_PENDIENTE': 90,
    'GANADO': 100,
    'PERDIDO': 0
};

let dealsById = {};
let draggedDealId = null;
let searchDebounce = null;
let clientSearchDebounce = null;

$(document).ready(function () {
    $(document).ajaxError(function (event, jqxhr) {
        if (jqxhr.status === 401) {
            window.location.href = '/starfi_crm/login.php';
        }
    });

    loadPipelineBoard();

    // Filtros superiores
    $('#pipeFilterSede').on('change', function () {
        $('#pipeFilterAgente').val('0');
        loadPipelineBoard();
    });

    $('#pipeFilterAgente, #pipeFilterPeriodo').on('change', function () {
        loadPipelineBoard();
    });

    $('#pipeSearchInput').on('input', function () {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(loadPipelineBoard, 320);
    });

    // Sincronizar conversaciones activas de WhatsApp al Embudo
    $('#btnSyncChats').on('click', function () {
        const btn = $(this);
        btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Sincronizando...');
        $.post('back_pipeline.php', {
            action: 'sync_active_chats',
            id_sede: $('#pipeFilterSede').val() || 0
        }, function (res) {
            btn.prop('disabled', false).html('<i class="fa-solid fa-arrows-rotate me-1 text-primary"></i> Sincronizar Chats');
            if (res && res.status === 'success') {
                loadPipelineBoard();
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: res.message || 'Sincronización completada',
                    showConfirmButton: false,
                    timer: 2600
                });
            } else {
                Swal.fire('Aviso', (res && res.message) ? res.message : 'Error al sincronizar.', 'warning');
            }
        }, 'json').fail(function () {
            btn.prop('disabled', false).html('<i class="fa-solid fa-arrows-rotate me-1 text-primary"></i> Sincronizar Chats');
            Swal.fire('Error', 'Error de red al sincronizar conversaciones.', 'error');
        });
    });

    // Abrir modal Nueva Oportunidad
    $('#btnNewDeal').on('click', function () {
        openCreateDealModal();
    });

    $('#chkNuevoProspecto').on('change', function () {
        if ($(this).is(':checked')) {
            $('#existingClientPane').hide();
            $('#newClientPane').show();
        } else {
            $('#newClientPane').hide();
            $('#existingClientPane').show();
        }
    });

    $('#searchClientInput').on('input', function () {
        const q = $(this).val().trim();
        clearTimeout(clientSearchDebounce);
        clientSearchDebounce = setTimeout(function () {
            fetchClientsForModal(q);
        }, 280);
    });

    $('#dealSedeSelect').on('change', function () {
        if ($('#dealIdInput').val() === '0') {
            fetchClientsForModal($('#searchClientInput').val().trim());
        }
    });

    $('#dealEtapaSelect').on('change', function () {
        if ($(this).val() === 'PERDIDO') {
            $('#dealMotivoPerdidaWrap').slideDown(150);
        } else {
            $('#dealMotivoPerdidaWrap').slideUp(150);
        }
    });

    $('#btnSaveDealModal').on('click', function () {
        saveDealFromModal();
    });

    $('#btnDeleteDeal').on('click', function () {
        const did = parseInt($('#dealIdInput').val() || '0', 10);
        if (did <= 0) return;
        Swal.fire({
            title: '¿Eliminar esta oportunidad?',
            text: 'Se quitará la tarjeta del Embudo Kanban (la conversación de WhatsApp se conserva intacta).',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#DC2626',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((r) => {
            if (r.isConfirmed) {
                $.post('back_pipeline.php', { action: 'delete_deal', deal_id: did }, function (res) {
                    if (res && res.status === 'success') {
                        $('#modalDealKanban').modal('hide');
                        loadPipelineBoard();
                        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Oportunidad eliminada', showConfirmButton: false, timer: 1800 });
                    } else {
                        Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo eliminar.', 'error');
                    }
                }, 'json');
            }
        });
    });

    // Inicializar eventos HTML5 Drag & Drop sobre las columnas
    initKanbanDragAndDrop();
});

function formatUsd(val) {
    const n = parseFloat(val || 0) || 0;
    return '$' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(str) {
    return String(str || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function loadPipelineBoard() {
    const sede = $('#pipeFilterSede').val() || 0;
    const agente = $('#pipeFilterAgente').val() || 0;
    const periodo = $('#pipeFilterPeriodo').val() || '90d';
    const search = ($('#pipeSearchInput').val() || '').trim();

    $.post('back_pipeline.php', {
        action: 'get_board',
        id_sede: sede,
        id_agente: agente,
        periodo: periodo,
        search: search
    }, function (res) {
        if (!res || res.status !== 'success') {
            Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo cargar el tablero Kanban.', 'error');
            return;
        }

        // Poblar asesores en filtro y en modal
        if (Array.isArray(res.agentes) && !window.pipelineIsOperador) {
            const curFilterAg = $('#pipeFilterAgente').val() || '0';
            let filterHtml = '<option value="0">👥 Todos los Asesores</option>';
            let modalAgHtml = '<option value="0">-- Sin asignar --</option>';
            res.agentes.forEach(a => {
                filterHtml += `<option value="${a.id}">${escapeHtml(a.nombre)}</option>`;
                modalAgHtml += `<option value="${a.id}">${escapeHtml(a.nombre)}</option>`;
            });
            $('#pipeFilterAgente').html(filterHtml);
            if ($('#pipeFilterAgente option[value="' + curFilterAg + '"]').length) {
                $('#pipeFilterAgente').val(curFilterAg);
            }
            $('#dealAgenteSelect').html(modalAgHtml);
        }

        dealsById = {};
        PIPELINE_STAGES.forEach(st => {
            const list = (res.stages && Array.isArray(res.stages[st])) ? res.stages[st] : [];
            const $col = $(`#col-${st}`);
            $col.empty();

            if (list.length === 0) {
                $col.append('<div class="kanban-empty-drop"><i class="fa-solid fa-arrows-up-down-left-right d-block mb-1 opacity-50"></i>Arrastra una oportunidad aquí</div>');
            } else {
                list.forEach(deal => {
                    dealsById[deal.id] = deal;
                    $col.append(renderDealCardHtml(deal));
                });
            }
        });

        recalculateBoardTotalsFromDom();
    }, 'json').fail(function () {
        Swal.fire('Error', 'Fallo de comunicación con el servidor al cargar el Embudo.', 'error');
    });
}

function renderDealCardHtml(deal) {
    const monto = parseFloat(deal.monto_estimado_usd || 0) || 0;
    const usdClass = monto > 0 ? '' : 'zero';
    const cliName = deal.cliente_nombre ? deal.cliente_nombre : ('+' + deal.numero_whatsapp);
    const unreadBadge = (parseInt(deal.chat_no_leidos || 0, 10) > 0)
        ? `<span class="badge bg-danger rounded-pill ms-1" style="font-size:0.64rem;" title="Mensajes sin leer en Bandeja">${deal.chat_no_leidos}</span>`
        : '';

    const chatUrl = deal.id_conversacion > 0
        ? `../bandeja/bandeja.php?conv_id=${deal.id_conversacion}&cliente_id=${deal.id_cliente}`
        : `../bandeja/bandeja.php?cliente_id=${deal.id_cliente}&search=${encodeURIComponent(deal.numero_whatsapp || '')}`;

    const notasSnippet = deal.notas
        ? `<div class="text-muted mt-1" style="font-size:0.72rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">${escapeHtml(deal.notas)}</div>`
        : '';

    const motivoSnippet = (deal.etapa === 'PERDIDO' && deal.motivo_perdida)
        ? `<div class="mt-1 px-2 py-1 rounded-2 bg-danger-subtle text-danger" style="font-size:0.68rem;"><i class="fa-solid fa-circle-info me-1"></i>${escapeHtml(deal.motivo_perdida)}</div>`
        : '';

    const updDate = deal.fecha_actualizacion ? String(deal.fecha_actualizacion).substring(0, 16).replace('T', ' ') : '';

    return `
        <article class="kanban-card" draggable="true" data-deal-id="${deal.id}" data-stage="${deal.etapa}" data-monto="${monto.toFixed(2)}">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                <div class="fw-bold text-dark" style="font-size:0.84rem; line-height:1.25; cursor:pointer;" onclick="openEditDealModal(${deal.id})">
                    ${escapeHtml(deal.titulo)}
                </div>
                <span class="kanban-card-usd ${usdClass}">${formatUsd(monto)}</span>
            </div>

            <div class="d-flex align-items-center justify-content-between mt-1">
                <div class="small fw-semibold text-secondary text-truncate" style="max-width:185px; font-size:0.76rem;" title="${escapeHtml(cliName)} (+${escapeHtml(deal.numero_whatsapp)})">
                    <i class="fa-regular fa-user me-1 text-muted"></i>${escapeHtml(cliName)} ${unreadBadge}
                </div>
                <span class="badge bg-light text-secondary border rounded-pill" style="font-size:0.62rem;">+${escapeHtml(deal.numero_whatsapp)}</span>
            </div>

            ${notasSnippet}
            ${motivoSnippet}

            <div class="d-flex align-items-center justify-content-between flex-wrap gap-1 mt-2 pt-2 border-top" style="border-color:#F1F5F9 !important;">
                <div class="d-flex align-items-center gap-1 flex-wrap">
                    <span class="badge rounded-pill text-white" style="background:#37414A; font-size:0.6rem;">
                        <i class="fa-solid fa-store me-1"></i>${escapeHtml(deal.nombre_sede)}
                    </span>
                    <span class="badge bg-light text-dark border rounded-pill" style="font-size:0.6rem;">
                        <i class="fa-solid fa-headset text-muted me-1"></i>${escapeHtml(deal.asesor_nombre)}
                    </span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-sm btn-light border py-0 px-2" style="border-radius:8px; font-size:0.7rem;" onclick="openEditDealModal(${deal.id})" title="Editar monto, notas o etapa">
                        <i class="fa-solid fa-pen text-secondary"></i>
                    </button>
                    <a href="${chatUrl}" class="btn btn-sm py-0 px-2 text-white fw-semibold" style="background:#10B981; border-radius:8px; font-size:0.7rem;" title="Abrir conversación en Bandeja (${escapeHtml(updDate)})">
                        <i class="fa-brands fa-whatsapp"></i> Chat
                    </a>
                </div>
            </div>
        </article>
    `;
}

function recalculateBoardTotalsFromDom() {
    let totalDeals = 0;
    let totalAllUsd = 0;
    let activoUsd = 0;
    let ponderadoUsd = 0;
    let ganadoUsd = 0;
    let ganadoCount = 0;
    let perdidoUsd = 0;
    let perdidoCount = 0;

    PIPELINE_STAGES.forEach(st => {
        const $col = $(`#col-${st}`);
        const $cards = $col.find('.kanban-card');
        let colSum = 0;

        if ($cards.length === 0) {
            if ($col.find('.kanban-empty-drop').length === 0) {
                $col.html('<div class="kanban-empty-drop"><i class="fa-solid fa-arrows-up-down-left-right d-block mb-1 opacity-50"></i>Arrastra una oportunidad aquí</div>');
            }
        } else {
            $col.find('.kanban-empty-drop').remove();
        }

        $cards.each(function () {
            const m = parseFloat($(this).attr('data-monto') || '0') || 0;
            colSum += m;
            totalDeals++;
            totalAllUsd += m;
        });

        $(`#count-${st}`).text($cards.length);
        $(`#sum-${st}`).text(`${formatUsd(colSum)} USD`);

        if (st === 'GANADO') {
            ganadoUsd += colSum;
            ganadoCount += $cards.length;
        } else if (st === 'PERDIDO') {
            perdidoUsd += colSum;
            perdidoCount += $cards.length;
        } else {
            activoUsd += colSum;
            const prob = STAGE_PROB[st] || 20;
            ponderadoUsd += colSum * (prob / 100.0);
        }
    });

    const ticketProm = totalDeals > 0 ? (totalAllUsd / totalDeals) : 0;
    const cerrados = ganadoCount + perdidoCount;
    const tasa = cerrados > 0
        ? ((ganadoCount / cerrados) * 100.0)
        : (totalDeals > 0 ? ((ganadoCount / totalDeals) * 100.0) : 0);

    $('#kpiActivoUsd').text(formatUsd(activoUsd));
    $('#kpiPonderadoUsd').text(formatUsd(ponderadoUsd));
    $('#kpiGanadoUsd').text(formatUsd(ganadoUsd));
    $('#kpiGanadoCount').text(ganadoCount);
    $('#kpiPerdidoUsd').text(formatUsd(perdidoUsd));
    $('#kpiPerdidoCount').text(perdidoCount);
    $('#kpiTasaCierre').text(tasa.toFixed(1) + '%');
    $('#kpiTicketPromedio').text(formatUsd(ticketProm));
    $('#kpiTotalDeals').text(totalDeals);
}

function initKanbanDragAndDrop() {
    $(document).on('dragstart', '.kanban-card', function (e) {
        draggedDealId = parseInt($(this).attr('data-deal-id') || '0', 10);
        $(this).addClass('dragging');
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            e.originalEvent.dataTransfer.setData('text/plain', String(draggedDealId));
        }
    });

    $(document).on('dragend', '.kanban-card', function () {
        $(this).removeClass('dragging');
        $('.kanban-column').removeClass('drag-over');
    });

    $('.kanban-column').on('dragover', function (e) {
        e.preventDefault();
        if (e.originalEvent && e.originalEvent.dataTransfer) {
            e.originalEvent.dataTransfer.dropEffect = 'move';
        }
        $(this).addClass('drag-over');
    });

    $('.kanban-column').on('dragleave', function (e) {
        if (!this.contains(e.relatedTarget)) {
            $(this).removeClass('drag-over');
        }
    });

    $('.kanban-column').on('drop', function (e) {
        e.preventDefault();
        $(this).removeClass('drag-over');

        let dealId = draggedDealId;
        if (!dealId && e.originalEvent && e.originalEvent.dataTransfer) {
            dealId = parseInt(e.originalEvent.dataTransfer.getData('text/plain') || '0', 10);
        }
        draggedDealId = null;
        if (!dealId || !dealsById[dealId]) return;

        const targetStage = $(this).attr('data-stage');
        const $card = $(`.kanban-card[data-deal-id="${dealId}"]`);
        if (!$card.length || !targetStage) return;

        const prevStage = dealsById[dealId].etapa;
        const $targetList = $(`#col-${targetStage}`);

        // Si se suelta en PERDIDO desde otra etapa, solicitar opcionalmente motivo de pérdida
        if (targetStage === 'PERDIDO' && prevStage !== 'PERDIDO') {
            Swal.fire({
                title: 'Marcar Oportunidad como Perdida',
                input: 'text',
                inputLabel: 'Motivo de pérdida (opcional)',
                inputPlaceholder: 'Ej: Precio, sin stock, dejó de responder...',
                showCancelButton: true,
                confirmButtonColor: '#EF4444',
                confirmButtonText: 'Mover a Perdido',
                cancelButtonText: 'Cancelar'
            }).then((r) => {
                if (r.isConfirmed) {
                    applyStageDrop($card, $targetList, dealId, targetStage, (r.value || '').trim());
                }
            });
            return;
        }

        applyStageDrop($card, $targetList, dealId, targetStage, '');
    });
}

function applyStageDrop($card, $targetList, dealId, targetStage, motivoPerdida) {
    const deal = dealsById[dealId];
    if (!deal) return;

    $targetList.find('.kanban-empty-drop').remove();
    $card.attr('data-stage', targetStage);
    $targetList.prepend($card);

    deal.etapa = targetStage;
    deal.probabilidad = STAGE_PROB[targetStage] || 20;
    if (motivoPerdida) deal.motivo_perdida = motivoPerdida;

    recalculateBoardTotalsFromDom();

    const orderedIds = [];
    $targetList.find('.kanban-card').each(function () {
        orderedIds.push(parseInt($(this).attr('data-deal-id') || '0', 10));
    });

    $.post('back_pipeline.php', {
        action: 'move_stage',
        deal_id: dealId,
        nueva_etapa: targetStage,
        motivo_perdida: motivoPerdida,
        ordered_ids: JSON.stringify(orderedIds)
    }, function (res) {
        if (!res || res.status !== 'success') {
            Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo mover la etapa.', 'error');
            loadPipelineBoard();
        } else if (targetStage === 'GANADO') {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: `🏆 ¡Venta Ganada! (${formatUsd(deal.monto_estimado_usd)} USD)`,
                showConfirmButton: false,
                timer: 2500
            });
        }
    }, 'json').fail(function () {
        loadPipelineBoard();
    });
}

function fetchClientsForModal(queryStr) {
    const sede = $('#dealSedeSelect').val() || $('#pipeFilterSede').val() || 0;
    $.post('back_pipeline.php', {
        action: 'search_clients',
        q: queryStr || '',
        id_sede: sede
    }, function (res) {
        const $sel = $('#dealClienteSelect');
        $sel.empty();
        if (res && res.status === 'success' && Array.isArray(res.data) && res.data.length > 0) {
            res.data.forEach(c => {
                const label = c.nombre ? `${c.nombre} (+${c.numero_whatsapp})` : `+${c.numero_whatsapp}`;
                $sel.append(`<option value="${c.id}">${escapeHtml(label)}</option>`);
            });
        } else {
            $sel.append('<option value="0">-- Sin coincidencias (activa "Registrar número nuevo") --</option>');
        }
    }, 'json');
}

function openCreateDealModal() {
    $('#dealIdInput').val('0');
    $('#modalDealTitle').html('<i class="fa-solid fa-sack-dollar text-warning me-2"></i>Nueva Oportunidad Comercial');
    $('#dealTituloInput').val('');
    $('#dealMontoInput').val('0.00');
    $('#dealEtapaSelect').val('NUEVO_LEAD');
    $('#dealNotasInput').val('');
    $('#dealMotivoPerdidaInput').val('');
    $('#dealMotivoPerdidaWrap').hide();
    $('#btnDeleteDeal').hide();

    $('#dealClientBlock').show();
    $('#chkNuevoProspecto').prop('checked', false).trigger('change');
    $('#searchClientInput').val('');
    $('#dealNuevoNombre').val('');
    $('#dealNuevoTelefono').val('');

    const curSede = parseInt($('#pipeFilterSede').val() || '0', 10);
    if (curSede > 0 && $('#dealSedeSelect option[value="' + curSede + '"]').length) {
        $('#dealSedeSelect').val(String(curSede));
    }

    fetchClientsForModal('');
    $('#modalDealKanban').modal('show');
}

window.openEditDealModal = function (dealId) {
    const deal = dealsById[dealId];
    if (!deal) return;

    $('#dealIdInput').val(String(deal.id));
    $('#modalDealTitle').html('<i class="fa-solid fa-pen-to-square text-warning me-2"></i>Editar Oportunidad #' + deal.id);
    $('#dealTituloInput').val(deal.titulo || '');
    $('#dealMontoInput').val((parseFloat(deal.monto_estimado_usd || 0) || 0).toFixed(2));
    $('#dealEtapaSelect').val(deal.etapa || 'NUEVO_LEAD');
    $('#dealNotasInput').val(deal.notas || '');
    $('#dealMotivoPerdidaInput').val(deal.motivo_perdida || '');

    if (deal.etapa === 'PERDIDO') {
        $('#dealMotivoPerdidaWrap').show();
    } else {
        $('#dealMotivoPerdidaWrap').hide();
    }

    if ($('#dealSedeSelect').is('select') && deal.id_sede) {
        $('#dealSedeSelect').val(String(deal.id_sede));
    }
    if ($('#dealAgenteSelect').is('select')) {
        $('#dealAgenteSelect').val(String(deal.id_agente || 0));
    }

    $('#dealClientBlock').hide();
    $('#btnDeleteDeal').show();
    $('#modalDealKanban').modal('show');
};

function saveDealFromModal() {
    const dealId = parseInt($('#dealIdInput').val() || '0', 10);
    const titulo = ($('#dealTituloInput').val() || '').trim();
    const monto = parseFloat($('#dealMontoInput').val() || '0') || 0;
    const etapa = $('#dealEtapaSelect').val() || 'NUEVO_LEAD';
    const sede = $('#dealSedeSelect').val() || 0;
    const agente = $('#dealAgenteSelect').val() || 0;
    const notas = ($('#dealNotasInput').val() || '').trim();
    const motivo = ($('#dealMotivoPerdidaInput').val() || '').trim();

    if (!titulo) {
        Swal.fire({ icon: 'warning', text: 'Ingresa el título o producto cotizado.' });
        return;
    }

    let idCliente = 0;
    let nuevoNombre = '';
    let nuevoTel = '';

    if (dealId === 0) {
        if ($('#chkNuevoProspecto').is(':checked')) {
            nuevoNombre = ($('#dealNuevoNombre').val() || '').trim();
            nuevoTel = ($('#dealNuevoTelefono').val() || '').trim();
            if (!nuevoTel) {
                Swal.fire({ icon: 'warning', text: 'Ingresa el número de WhatsApp del nuevo cliente.' });
                return;
            }
        } else {
            idCliente = parseInt($('#dealClienteSelect').val() || '0', 10);
            if (idCliente <= 0) {
                Swal.fire({ icon: 'warning', text: 'Selecciona un cliente de la lista o activa "Registrar número nuevo".' });
                return;
            }
        }
    }

    const btn = $('#btnSaveDealModal');
    btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');

    $.post('back_pipeline.php', {
        action: 'save_deal',
        deal_id: dealId,
        titulo: titulo,
        monto_estimado_usd: monto.toFixed(2),
        etapa: etapa,
        id_sede: sede,
        id_agente: agente,
        notas: notas,
        motivo_perdida: motivo,
        id_cliente: idCliente,
        nuevo_nombre: nuevoNombre,
        nuevo_telefono: nuevoTel
    }, function (res) {
        btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Oportunidad');
        if (res && res.status === 'success') {
            $('#modalDealKanban').modal('hide');
            loadPipelineBoard();
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: res.message || 'Guardado',
                showConfirmButton: false,
                timer: 2200
            });
        } else {
            Swal.fire('Error', (res && res.message) ? res.message : 'No se pudo guardar.', 'error');
        }
    }, 'json').fail(function () {
        btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Oportunidad');
        Swal.fire('Error', 'Fallo de conexión al guardar.', 'error');
    });
}

