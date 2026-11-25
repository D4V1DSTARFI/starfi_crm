// modules/whatsapp_analytics/funciones_whatsapp_analytics.js
// Controlador Frontend de Métricas y Facturación WABA — STARFI CRM (QA-STD-GLOBAL-2.1)
// 100% escapeHtml() contra DOM-XSS y bloqueo inmediato anti-doble-clic.

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

let metaChartInstance = null;
let templatesLoaded = false;

$(document).ready(function () {
    const toggleBtn = document.getElementById('toggleSidebar');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const sb = document.getElementById('sidebar');
            if (sb) sb.classList.toggle('collapsed');
        });
    }

    loadAnalytics();

    $('#formFiltrosWhatsappAnalytics').on('submit', function (e) {
        e.preventDefault();
        loadAnalytics();
    });

    $('#filterSede').on('change', function () {
        templatesLoaded = false;
        $('#filterPlantilla').html('<option value="">Todas las plantillas (Consolidado)</option>');
        loadAnalytics();
    });

    $('#filterPlantilla').on('change', function () {
        loadAnalytics();
    });

    // Configuración de Tarifas por Sede
    $('#selConfigSede').on('change', function () {
        const $sel = $(this);
        const idSede = $sel.val();
        if (!idSede) {
            $('#configSedePanel').hide();
            $('#btnGuardarConfigSede').hide();
            return;
        }

        $sel.prop('disabled', true);
        Swal.fire({ title: 'Cargando...', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });

        $.ajax({
            url: '../waba_ordenes/back_waba_ordenes.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_sede_config', id_sede: idSede },
            success: function (res) {
                Swal.close();
                if (res && res.status === 'success') {
                    const tarifa = res.tarifa || {};
                    const sede = res.sede || {};
                    $('#configTipoTarifa').val(tarifa.tipo_tarifa || 'PORCENTAJE');
                    $('#configValorTarifa').val(tarifa.valor !== undefined ? tarifa.valor : '10.00');
                    $('#configLimiteFacturacion').val(tarifa.limite_facturacion || '0.00');
                    $('#configNotas').val(tarifa.notas_negociacion || '');
                    $('#configNombreGerente').val(sede.gerente_nombre || '');
                    $('#configTelGerente').val(sede.gerente_telefono || '');
                    $('#configEmailGerente').val(sede.gerente_email || '');
                    $('#configPrefNotif').val(sede.pref_not_cobro || 'WHATSAPP');

                    $('#configSedePanel').fadeIn();
                    $('#btnGuardarConfigSede').show();
                } else {
                    Swal.fire('Error', (res && res.message) ? String(res.message) : 'No se pudo cargar la configuración.', 'error');
                }
            },
            error: function () {
                Swal.close();
                Swal.fire('Error', 'Fallo de red al consultar la configuración de la sede.', 'error');
            },
            complete: function () {
                $sel.prop('disabled', false);
            }
        });
    });

    $('#formTarifasSede').on('submit', function (e) {
        e.preventDefault();
        const $btn = $('#btnGuardarConfigSede');
        if ($btn.prop('disabled')) return;

        const idSede = $('#selConfigSede').val();
        if (!idSede) return;

        $btn.prop('disabled', true);
        const payload = {
            action: 'save_sede_config',
            id_sede: idSede,
            tipo_tarifa: $('#configTipoTarifa').val(),
            valor: $('#configValorTarifa').val(),
            limite_facturacion: $('#configLimiteFacturacion').val(),
            notas: $('#configNotas').val(),
            gerente_nombre: $('#configNombreGerente').val(),
            gerente_telefono: $('#configTelGerente').val(),
            gerente_email: $('#configEmailGerente').val(),
            pref_not_cobro: $('#configPrefNotif').val()
        };

        Swal.fire({ title: 'Guardando...', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });

        $.ajax({
            url: '../waba_ordenes/back_waba_ordenes.php',
            type: 'POST',
            dataType: 'json',
            data: payload,
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Guardado', String(res.message || 'Configuración actualizada.'), 'success');
                } else {
                    Swal.fire('Error', (res && res.message) ? String(res.message) : 'Error al guardar.', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de conexión al guardar la configuración.', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });

    // Emisión Individual de Orden de Cobro
    $('#btnEmitirOrden').on('click', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;

        const idSede = $('#filterSede').val();
        const fechaHastaFiltro = $('#filterFechaHasta').val();

        if (!idSede || idSede === '0') {
            Swal.fire('Atención', 'Por favor seleccione una sede específica en los filtros antes de emitir una orden individual.', 'warning');
            return;
        }

        $btn.prop('disabled', true);
        Swal.fire({ title: 'Analizando deudas...', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });

        $.ajax({
            url: '../waba_ordenes/back_waba_ordenes.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'check_last_order', id_sede: idSede, fecha_hasta: fechaHastaFiltro },
            success: function (res) {
                Swal.close();
                if (!res || res.status !== 'success') {
                    $btn.prop('disabled', false);
                    Swal.fire('Error', (res && res.message) ? String(res.message) : 'Error al verificar historial.', 'error');
                    return;
                }

                const safeDesde = escapeHtml(res.fecha_desde || '');
                const safeHasta = escapeHtml(res.fecha_hasta || '');
                const safePendId = escapeHtml(res.pendiente_id || '');

                let msg = 'Se generará una orden desde el <b>' + safeDesde + '</b> hasta el <b>' + safeHasta + '</b>.';
                let btnText = 'Sí, Generar Orden';

                if (res.tiene_pendiente) {
                    msg = 'Existe una orden PENDIENTE del periodo anterior (ID #' + safePendId + ').<br><br>¿Desea <b>fusionar</b> esa deuda en esta nueva orden global hasta el <b>' + safeHasta + '</b>?';
                    btnText = 'Sí, Fusionar y Generar';
                }

                Swal.fire({
                    title: 'Emisión de Orden de Cobro',
                    html: msg,
                    icon: 'question',
                    showCancelButton: true,
                    showDenyButton: Boolean(res.tiene_pendiente),
                    confirmButtonText: btnText,
                    denyButtonText: 'Generar Nueva (Ignorar pendiente)',
                    cancelButtonText: 'Cancelar'
                }).then(function (result) {
                    if (!result.isConfirmed && !result.isDenied) {
                        $btn.prop('disabled', false);
                        return;
                    }

                    const ordenPendienteId = result.isConfirmed ? (res.pendiente_id || null) : null;
                    Swal.fire({
                        title: 'Procesando...',
                        text: 'Conciliando consumo híbrido y emitiendo orden',
                        allowOutsideClick: false,
                        didOpen: function () { Swal.showLoading(); }
                    });

                    $.ajax({
                        url: '../waba_ordenes/back_waba_ordenes.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'generate_order',
                            id_sede: idSede,
                            fecha_desde: res.fecha_desde,
                            fecha_hasta: res.fecha_hasta,
                            orden_pendiente_id: ordenPendienteId
                        },
                        success: function (orderRes) {
                            if (orderRes && orderRes.status === 'success') {
                                const safeMonto = escapeHtml(orderRes.monto_total);
                                const safeIdOrd = escapeHtml(orderRes.id_orden);
                                Swal.fire({
                                    title: 'Orden Creada',
                                    html: 'Monto Total: <b>$' + safeMonto + ' USD</b><br>La orden #' + safeIdOrd + ' ha sido generada exitosamente.',
                                    icon: 'success',
                                    showCancelButton: true,
                                    confirmButtonText: 'Ir a Órdenes de Cobro',
                                    cancelButtonText: 'Cerrar'
                                }).then(function (go) {
                                    if (go.isConfirmed) {
                                        window.location.href = '../waba_ordenes/waba_ordenes.php';
                                    }
                                });
                            } else {
                                Swal.fire('Atención', (orderRes && orderRes.message) ? String(orderRes.message) : 'No se pudo generar la orden.', 'warning');
                            }
                        },
                        error: function () {
                            Swal.fire('Error', 'Fallo de conexión al emitir la orden de cobro.', 'error');
                        },
                        complete: function () {
                            $btn.prop('disabled', false);
                        }
                    });
                });
            },
            error: function () {
                Swal.close();
                $btn.prop('disabled', false);
                Swal.fire('Error', 'No se pudo consultar el estado de órdenes previas.', 'error');
            }
        });
    });

    // Emisión Masiva
    $('#btnSelTodasSedes').on('click', function () {
        $('.chk-sede-masiva').prop('checked', true);
    });

    $('#formEmisionMasiva').on('submit', function (e) {
        e.preventDefault();
        const $btn = $('#btnGenerarMasiva');
        if ($btn.prop('disabled')) return;

        const sedes = [];
        $('.chk-sede-masiva:checked').each(function () {
            sedes.push($(this).val());
        });

        if (sedes.length === 0) {
            Swal.fire('Atención', 'Debe seleccionar al menos una sede para la emisión masiva.', 'warning');
            return;
        }

        const fechaDesde = $('#masivaFechaDesde').val();
        const fechaHasta = $('#masivaFechaHasta').val();
        if (!fechaDesde || !fechaHasta) {
            Swal.fire('Atención', 'Debe seleccionar las fechas desde y hasta.', 'warning');
            return;
        }

        $btn.prop('disabled', true);

        Swal.fire({
            title: 'Confirmar Emisión Masiva',
            html: '¿Está seguro de generar órdenes de cobro para <b>' + escapeHtml(sedes.length) + '</b> sede(s)?<br><br>Se verificará anti-solapamiento por sede y se enviarán notificaciones.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, Generar Todas',
            cancelButtonText: 'Cancelar'
        }).then(function (result) {
            if (!result.isConfirmed) {
                $btn.prop('disabled', false);
                return;
            }

            $('#modalEmisionMasiva').modal('hide');
            Swal.fire({
                title: 'Procesando...',
                html: 'Generando órdenes y enviando notificaciones.<br>Por favor espere...',
                allowOutsideClick: false,
                didOpen: function () { Swal.showLoading(); }
            });

            $.ajax({
                url: '../waba_ordenes/back_waba_ordenes.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'generate_massive_orders',
                    sedes: sedes,
                    fecha_desde: fechaDesde,
                    fecha_hasta: fechaHasta
                },
                success: function (res) {
                    const warnList = (res && Array.isArray(res.warnings) && res.warnings.length > 0)
                        ? '<div class="text-start small mt-2 p-2 bg-light border rounded">' + res.warnings.map(escapeHtml).join('<br>') + '</div>'
                        : '';
                    if (res && res.status === 'success') {
                        Swal.fire({
                            title: 'Proceso Completado',
                            html: escapeHtml(res.message || 'Emisión completada.') + warnList,
                            icon: 'success'
                        }).then(function () {
                            window.location.href = '../waba_ordenes/waba_ordenes.php';
                        });
                    } else {
                        Swal.fire({
                            title: 'Atención',
                            html: escapeHtml((res && res.message) ? res.message : 'No se generaron órdenes.') + warnList,
                            icon: 'info'
                        });
                    }
                },
                error: function () {
                    Swal.fire('Error', 'Ocurrió un error en la conexión con el servidor.', 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        });
    });
});

function loadAnalytics() {
    const $btn = $('#btnApplyFilters');
    if ($btn.prop('disabled')) return;
    $btn.prop('disabled', true);

    $('#placeholderData').hide();
    const idSede = $('#filterSede').val();
    const desde = $('#filterFechaDesde').val();
    const hasta = $('#filterFechaHasta').val();
    const idPlantilla = $('#filterPlantilla').val();

    $('#contentData').hide();
    $('#loaderData').show();

    $.ajax({
        url: 'back_whatsapp_analytics.php',
        type: 'POST',
        dataType: 'json',
        data: {
            action: 'get_analytics',
            id_sede: idSede,
            fecha_desde: desde,
            fecha_hasta: hasta,
            id_plantilla: idPlantilla
        },
        success: function (res) {
            $('#loaderData').hide();
            if ($('#rawApiResponse').length) {
                $('#rawApiResponse').text(JSON.stringify(res, null, 2));
            }

            if (res && res.status === 'success') {
                $('#contentData').fadeIn();

                if (!templatesLoaded && res.data && res.data.message_templates && Array.isArray(res.data.message_templates.data)) {
                    const $select = $('#filterPlantilla');
                    const currentVal = $select.val();
                    $select.html('<option value="">Todas las plantillas (Consolidado)</option>');
                    res.data.message_templates.data.forEach(function (tpl) {
                        const safeId = escapeHtml(tpl.id || '');
                        const safeName = escapeHtml(tpl.name || '');
                        const safeLang = escapeHtml(tpl.language || '');
                        $select.append('<option value="' + safeId + '">' + safeName + ' (' + safeLang + ')</option>');
                    });
                    if (currentVal) {
                        $select.val(currentVal);
                    }
                    templatesLoaded = true;
                }

                procesarYGraficar(res.data || {}, desde, hasta, idPlantilla, res.tarifa_config, res.local_audit);
            } else {
                Swal.fire('Error', (res && res.message) ? String(res.message) : 'Error desconocido', 'error');
                $('#contentData').show();
            }
        },
        error: function () {
            $('#loaderData').hide();
            Swal.fire('Error', 'Fallo de conexión al consultar métricas', 'error');
        },
        complete: function () {
            $btn.prop('disabled', false);
        }
    });
}

function procesarYGraficar(data, desde, hasta, idPlantilla, tarifaConfig, localAudit) {
    let mktCost = 0, utilCost = 0, authCost = 0, servCost = 0;
    let mktCount = 0, utilCount = 0, authCount = 0, servCount = 0;

    if (data.pricing_analytics && Array.isArray(data.pricing_analytics.data)) {
        const pricing = (data.pricing_analytics.data[0] && Array.isArray(data.pricing_analytics.data[0].data_points))
            ? data.pricing_analytics.data[0].data_points
            : [];
        pricing.forEach(function (dp) {
            if (dp.cost) {
                const c = parseFloat(dp.cost) || 0;
                const cat = String(dp.pricing_category || dp.category || dp.conversation_category || '').toUpperCase();
                if (cat === 'MARKETING') mktCost += c;
                else if (cat === 'UTILITY') utilCost += c;
                else if (cat === 'AUTHENTICATION') authCost += c;
                else if (cat === 'SERVICE') servCost += c;
                else mktCost += c;
            }
        });
    }

    if (data.conversation_analytics && Array.isArray(data.conversation_analytics.data)) {
        const convs = (data.conversation_analytics.data[0] && Array.isArray(data.conversation_analytics.data[0].data_points))
            ? data.conversation_analytics.data[0].data_points
            : [];
        convs.forEach(function (dp) {
            const cat = String(dp.conversation_category || '').toUpperCase();
            const cnt = parseInt(dp.conversation || 0, 10) || 0;
            if (cat === 'MARKETING') mktCount += cnt;
            else if (cat === 'UTILITY') utilCount += cnt;
            else if (cat === 'AUTHENTICATION') authCount += cnt;
            else if (cat === 'SERVICE') servCount += cnt;
        });
    }

    let enviados = 0, entregados = 0, leidos = 0, respuestas = 0;
    let labels = [];
    let dsEnviados = [], dsEntregados = [], dsLeidos = [], dsRespuestas = [];

    let dataSource = null;
    if (idPlantilla && data.template_analytics && Array.isArray(data.template_analytics.data)) {
        dataSource = (data.template_analytics.data[0] && Array.isArray(data.template_analytics.data[0].data_points))
            ? data.template_analytics.data[0].data_points
            : [];
    } else if (data.analytics && Array.isArray(data.analytics.data)) {
        dataSource = (data.analytics.data[0] && Array.isArray(data.analytics.data[0].data_points))
            ? data.analytics.data[0].data_points
            : [];
    } else if (data.analytics && Array.isArray(data.analytics.data_points)) {
        dataSource = data.analytics.data_points;
    }

    if (dataSource && dataSource.length > 0) {
        const msgs = dataSource.slice().sort(function (a, b) { return (a.start || 0) - (b.start || 0); });

        msgs.forEach(function (dp) {
            const d = new Date((dp.start || 0) * 1000);
            labels.push(d.getDate() + ' de ' + d.toLocaleString('es-ES', { month: 'short' }));

            const env = parseInt(dp.sent || 0, 10) || 0;
            const ent = parseInt(dp.delivered || 0, 10) || 0;
            const lei = parseInt(dp.read || 0, 10) || 0;
            const resps = parseInt(dp.replies || 0, 10) || 0;

            enviados += env;
            entregados += ent;
            leidos += lei;
            respuestas += resps;

            dsEnviados.push(env);
            dsEntregados.push(ent);
            dsLeidos.push(lei);
            dsRespuestas.push(resps);
        });
    } else {
        labels = [String(desde || ''), String(hasta || '')];
        dsEnviados = [0, 0];
        dsEntregados = [0, 0];
        dsLeidos = [0, 0];
        dsRespuestas = [0, 0];
    }

    if (localAudit) {
        if ((localAudit.enviados || 0) > enviados) enviados = localAudit.enviados;
        if ((localAudit.entregados || 0) > entregados) entregados = localAudit.entregados;
        if ((localAudit.leidos || 0) > leidos) leidos = localAudit.leidos;

        if (localAudit.por_categoria) {
            mktCount = Math.max(mktCount, localAudit.por_categoria.MARKETING || 0);
            utilCount = Math.max(utilCount, localAudit.por_categoria.UTILITY || 0);
            authCount = Math.max(authCount, localAudit.por_categoria.AUTHENTICATION || 0);
            servCount = Math.max(servCount, localAudit.por_categoria.SERVICE || 0);
        }

        if (localAudit.costo_local_por_categoria) {
            mktCost = Math.max(mktCost, parseFloat(localAudit.costo_local_por_categoria.MARKETING || 0));
            utilCost = Math.max(utilCost, parseFloat(localAudit.costo_local_por_categoria.UTILITY || 0));
            authCost = Math.max(authCost, parseFloat(localAudit.costo_local_por_categoria.AUTHENTICATION || 0));
            servCost = Math.max(servCost, parseFloat(localAudit.costo_local_por_categoria.SERVICE || 0));
        }
    }

    const totalCost = mktCost + utilCost + authCost + servCost;
    const valorTarifa = parseFloat(tarifaConfig ? tarifaConfig.valor : 10.00) || 0;
    const tipoTarifa = tarifaConfig ? tarifaConfig.tipo_tarifa : 'PORCENTAJE';

    let factorMarkup = 1.10;
    if (tipoTarifa === 'PORCENTAJE') {
        factorMarkup = 1 + (valorTarifa / 100);
    } else if (tipoTarifa === 'PORCENTAJE_VOLUMEN') {
        const p = enviados > 1000 ? (valorTarifa / 2) : valorTarifa;
        factorMarkup = 1 + (p / 100);
    }

    let costoFinalFacturado = totalCost * factorMarkup;
    let mktCargoFinal = mktCost * factorMarkup;
    let utilCargoFinal = utilCost * factorMarkup;
    let authCargoFinal = authCost * factorMarkup;
    let servCargoFinal = servCost * factorMarkup;

    if (tipoTarifa === 'FIJA' && enviados > 0) {
        const margenFijo = enviados * valorTarifa;
        costoFinalFacturado = totalCost + margenFijo;
        const ratioFijo = totalCost > 0 ? (costoFinalFacturado / totalCost) : 1;
        mktCargoFinal = mktCost * ratioFijo;
        utilCargoFinal = utilCost * ratioFijo;
        authCargoFinal = authCost * ratioFijo;
        servCargoFinal = servCost * ratioFijo;
    }

    let totalEntregadosCat = mktCount + utilCount + authCount + servCount;
    if (totalEntregadosCat === 0 && entregados > 0) {
        mktCount = entregados;
        totalEntregadosCat = entregados;
    }
    if (mktCost === 0 && utilCost === 0 && authCost === 0 && servCost === 0 && totalCost > 0) {
        mktCargoFinal = costoFinalFacturado;
    }

    const recibidos = localAudit ? (localAudit.recibidos || 0) : 0;
    const gratuitos = localAudit ? (localAudit.gratuitos || 0) : 0;
    let pagados = localAudit ? (localAudit.pagados || 0) : (totalEntregadosCat - gratuitos);
    if (pagados < 0) pagados = totalEntregadosCat;

    $('#metaValEnviados').text(String(enviados));
    $('#metaValEntregados').text(String(entregados));
    $('#metaValRecibidos').text(String(recibidos));

    $('#metaTotalEntregadosCat').text(String(totalEntregadosCat));
    $('#metaEntMkt').text(String(mktCount));
    $('#metaEntUtil').text(String(utilCount));
    $('#metaEntAuth').text(String(authCount));
    $('#metaEntServ').text(String(servCount));

    $('#metaTotalGratuitos').text(String(gratuitos));
    $('#metaGratAtencion').text(String(gratuitos));
    $('#metaGratAcceso').text('0');

    $('#metaTotalPagados').text(String(pagados));
    $('#metaPagMkt').text(String(mktCount));
    $('#metaPagUtil').text(String(utilCount));
    $('#metaPagAuth').text(String(authCount));
    $('#metaPagServ').text(String(servCount));

    $('#metaCargosTotales').text('$' + costoFinalFacturado.toFixed(2).replace('.', ','));
    $('#metaCargoMkt').text('$' + mktCargoFinal.toFixed(2).replace('.', ','));
    $('#metaCargoUtil').text('$' + utilCargoFinal.toFixed(2).replace('.', ','));
    $('#metaCargoAuth').text('$' + authCargoFinal.toFixed(2).replace('.', ','));
    $('#metaCargoServ').text('$' + servCargoFinal.toFixed(2).replace('.', ','));

    $('#kpiEnviados').text(String(enviados));
    $('#kpiEntregados').text(String(entregados));
    $('#kpiLeidos').text(String(leidos));
    $('#kpiRespuestas').text(String(respuestas));

    if (enviados > 0) {
        const pctEnt = ((entregados / enviados) * 100).toFixed(1);
        $('#trendEntregados').html('<i class="fa-solid fa-arrow-right"></i> ' + escapeHtml(pctEnt) + '%').removeClass('trend-neutral').addClass('trend-up');
    } else {
        $('#trendEntregados').text('-').removeClass('trend-up').addClass('trend-neutral');
    }

    if (entregados > 0) {
        const pctLei = ((leidos / entregados) * 100).toFixed(1);
        $('#trendLeidos').text('(' + pctLei + '%)').addClass('trend-neutral');
    } else {
        $('#trendLeidos').text('-');
    }

    if (metaChartInstance) {
        metaChartInstance.destroy();
    }

    const canvas = document.getElementById('metaChart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');

    metaChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Mensajes enviados', data: dsEnviados, borderColor: '#fa383e', backgroundColor: '#fa383e', fill: false, tension: 0.1, borderWidth: 2, pointRadius: 0 },
                { label: 'Mensajes entregados', data: dsEntregados, borderColor: '#5a2e70', backgroundColor: '#5a2e70', fill: false, tension: 0.1, borderWidth: 2, pointRadius: 0 },
                { label: 'Mensajes leídos', data: dsLeidos, borderColor: '#008080', backgroundColor: '#008080', fill: false, tension: 0.1, borderWidth: 2, pointRadius: 0 },
                { label: 'Respuestas únicas', data: dsRespuestas, borderColor: '#004d40', backgroundColor: '#004d40', fill: false, tension: 0.1, borderWidth: 2, pointRadius: 0 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 10, font: { size: 11, family: 'Gotham' } } }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#f0f2f5' }, ticks: { stepSize: 1, color: '#606770' }, border: { display: false } },
                x: { grid: { display: false }, ticks: { color: '#606770' }, border: { display: true, color: '#dadde1' } }
            },
            interaction: { mode: 'index', intersect: false }
        }
    });
}
