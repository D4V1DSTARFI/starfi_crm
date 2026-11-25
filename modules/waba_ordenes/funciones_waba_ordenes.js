// modules/waba_ordenes/funciones_waba_ordenes.js
// Controlador Frontend de Órdenes de Cobro WABA — STARFI CRM (QA-STD-GLOBAL-2.1)
// 100% escapeHtmlWaba() contra DOM-XSS y bloqueo anti-doble-clic en el primer clic.

function escapeHtmlWaba(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

$(document).ready(function () {
    const $root = $('#wabaOrdenesRoot');
    const isMaster = String($root.attr('data-es-master') || '0') === '1';
    let currentPage = 1;
    let currentOrdersMap = {};

    const toggleBtn = document.getElementById('toggleSidebar');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const sb = document.getElementById('sidebar');
            if (sb) sb.classList.toggle('collapsed');
        });
    }

    cargarOrdenes(1);

    $('#formFiltrosOrdenesWaba').on('submit', function (e) {
        e.preventDefault();
        const $btn = $('#btnFiltrar');
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);
        cargarOrdenes(1, function () {
            $btn.prop('disabled', false);
        });
    });

    $('#filter_estado, #filter_sede').on('change', function () {
        cargarOrdenes(1);
    });

    $('#btnLimpiar').on('click', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);
        if (isMaster) {
            $('#filter_sede').val('');
        }
        $('#filter_estado').val('');
        $('#filter_fecha_desde').val('');
        $('#filter_fecha_hasta').val('');
        cargarOrdenes(1, function () {
            $btn.prop('disabled', false);
        });
    });

    $(document).on('click', '.page-link-btn', function (e) {
        e.preventDefault();
        const page = parseInt($(this).attr('data-page'), 10);
        if (page > 0) {
            cargarOrdenes(page);
        }
    });

    function renderEstadoBadge(estadoRaw) {
        const estado = String(estadoRaw || '').toUpperCase();
        if (estado === 'PENDIENTE') {
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>PENDIENTE</span>';
        }
        if (estado === 'EN_REVISION') {
            return '<span class="badge bg-info text-dark"><i class="fas fa-magnifying-glass-dollar me-1"></i>PAGADA (PEND. REVISIÓN)</span>';
        }
        if (estado === 'PAGADO') {
            return '<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>PAGADO</span>';
        }
        if (estado === 'FUSIONADO') {
            return '<span class="badge bg-secondary"><i class="fas fa-code-merge me-1"></i>FUSIONADO</span>';
        }
        return '<span class="badge bg-light text-dark border">' + escapeHtmlWaba(estado) + '</span>';
    }

    function cargarOrdenes(page, onDone) {
        currentPage = page || 1;

        const reqData = {
            action: 'get_orders',
            page: currentPage,
            id_sede: $('#filter_sede').val() || '',
            estado: $('#filter_estado').val() || '',
            fecha_desde: $('#filter_fecha_desde').val() || '',
            fecha_hasta: $('#filter_fecha_hasta').val() || ''
        };

        $.ajax({
            url: 'back_waba_ordenes.php',
            type: 'POST',
            dataType: 'json',
            data: reqData,
            success: function (res) {
                if (res && res.status === 'success') {
                    if (res.kpis) {
                        $('#kpiTotalFacturado').text('$' + (parseFloat(res.kpis.total_facturado) || 0).toFixed(2));
                        $('#kpiTotalCobrado').text('$' + (parseFloat(res.kpis.total_cobrado) || 0).toFixed(2));
                        $('#kpiPorCobrar').text('$' + (parseFloat(res.kpis.por_cobrar) || 0).toFixed(2));
                        $('#kpiEnRevisionSub').text('En revisión: $' + (parseFloat(res.kpis.en_revision) || 0).toFixed(2));
                        if (isMaster && res.kpis.margen_neto !== undefined) {
                            $('#kpiMargenNeto').text('$' + (parseFloat(res.kpis.margen_neto) || 0).toFixed(2));
                            $('#kpiMargenCobradoSub').text('Margen cobrado: $' + (parseFloat(res.kpis.margen_cobrado) || 0).toFixed(2));
                        }
                    }

                    const $tbody = $('#tablaOrdenes tbody');
                    $tbody.empty();
                    currentOrdersMap = {};

                    const colSpan = isMaster ? 11 : 8;
                    if (!Array.isArray(res.data) || res.data.length === 0) {
                        $tbody.append('<tr><td colspan="' + colSpan + '" class="text-center py-4 text-muted">No se encontraron órdenes para los filtros seleccionados</td></tr>');
                        $('#paginationContainer').attr('style', 'display: none !important;');
                        return;
                    }

                    res.data.forEach(function (o) {
                        const orderId = parseInt(o.id, 10) || 0;
                        currentOrdersMap[orderId] = o;
                        const badge = renderEstadoBadge(o.estado);

                        let pagoResumen = '<span class="text-muted small">-</span>';
                        if (o.metodo_pago) {
                            pagoResumen =
                                '<span class="fw-semibold small">' + escapeHtmlWaba(o.metodo_pago) + '</span><br>' +
                                '<small class="text-muted">Ref: ' + escapeHtmlWaba(o.referencia_pago || '-') + '</small>';
                        }

                        let colsPrecios = '';
                        if (isMaster) {
                            const cMeta = (parseFloat(o.costo_meta) || 0).toFixed(2);
                            const cMargen = (parseFloat(o.margen_ganancia) || 0).toFixed(2);
                            const cTotal = (parseFloat(o.monto_total) || 0).toFixed(2);
                            colsPrecios =
                                '<td>$' + escapeHtmlWaba(cMeta) + '</td>' +
                                '<td class="text-info fw-semibold">$' + escapeHtmlWaba(cMargen) + '</td>' +
                                '<td class="fw-bold text-primary">$' + escapeHtmlWaba(cTotal) + '</td>';
                        } else {
                            const cTotal = (parseFloat(o.monto_total) || 0).toFixed(2);
                            colsPrecios = '<td class="fw-bold text-primary">$' + escapeHtmlWaba(cTotal) + '</td>';
                        }

                        let colNotif = '';
                        if (isMaster) {
                            const cantNotif = parseInt(o.notificacion_enviada, 10) || 0;
                            const notifBadge = cantNotif > 0
                                ? '<span class="badge bg-info text-dark"><i class="fas fa-check-double"></i> Enviada (' + escapeHtmlWaba(cantNotif) + ')</span>'
                                : '<span class="badge bg-light text-dark border"><i class="fas fa-clock"></i> Pendiente</span>';
                            colNotif = '<td>' + notifBadge + '</td>';
                        }

                        const pdfHref = '../../imprimir_orden_waba.php?id=' + encodeURIComponent(orderId);
                        let btns = '<a href="' + escapeHtmlWaba(pdfHref) + '" target="_blank" class="btn btn-outline-secondary" title="Imprimir Recibo / Aviso PDF"><i class="fas fa-file-pdf me-1"></i>PDF</a>';
                        btns += '<button type="button" class="btn btn-outline-info btn-detalle" data-id="' + escapeHtmlWaba(orderId) + '" title="Ver Desglose"><i class="fas fa-list me-1"></i>Desglose</button>';

                        const estadoUpper = String(o.estado || '').toUpperCase();
                        if (estadoUpper === 'PENDIENTE') {
                            btns += '<button type="button" class="btn btn-success text-white fw-semibold btn-pagar" data-id="' + escapeHtmlWaba(orderId) + '" title="Reportar / Registrar Pago"><i class="fas fa-hand-holding-dollar me-1"></i>Reportar Pago</button>';
                        } else if (estadoUpper === 'EN_REVISION') {
                            if (isMaster) {
                                btns += '<button type="button" class="btn btn-warning text-dark fw-bold btn-ver-pago" data-id="' + escapeHtmlWaba(orderId) + '" title="Verificar Pago Reportado"><i class="fas fa-magnifying-glass-dollar me-1"></i>Verificar Pago</button>';
                            } else {
                                btns += '<button type="button" class="btn btn-outline-dark btn-ver-pago" data-id="' + escapeHtmlWaba(orderId) + '" title="Ver Comprobante Enviado"><i class="fas fa-receipt me-1"></i>Ver Pago</button>';
                                btns += '<button type="button" class="btn btn-outline-success btn-pagar" data-id="' + escapeHtmlWaba(orderId) + '" title="Actualizar Reporte de Pago"><i class="fas fa-pen me-1"></i>Editar Pago</button>';
                            }
                        } else if (estadoUpper === 'PAGADO') {
                            btns += '<button type="button" class="btn btn-outline-dark fw-semibold btn-ver-pago" data-id="' + escapeHtmlWaba(orderId) + '" title="Ver Soporte y Auditar Pago"><i class="fas fa-receipt me-1"></i>Auditar Pago</button>';
                        }

                        if (isMaster) {
                            if (estadoUpper === 'PENDIENTE') {
                                btns += '<button type="button" class="btn btn-outline-primary btn-notificar" data-id="' + escapeHtmlWaba(orderId) + '" title="Reenviar Notificación"><i class="fas fa-paper-plane"></i></button>';
                            }
                            btns += '<button type="button" class="btn btn-outline-danger btn-eliminar" data-id="' + escapeHtmlWaba(orderId) + '" title="Eliminar Orden"><i class="fas fa-trash"></i></button>';
                        }

                        const codigoLabel = o.codigo_orden ? ('<br><small class="text-muted">' + escapeHtmlWaba(o.codigo_orden) + '</small>') : '';
                        const tr =
                            '<tr>' +
                                '<td><b>#' + escapeHtmlWaba(orderId) + '</b>' + codigoLabel + '</td>' +
                                '<td>' + escapeHtmlWaba(o.nombre_sede || '') + '</td>' +
                                '<td>' + escapeHtmlWaba(o.fecha_desde || '') + '<br><small class="text-muted">al ' + escapeHtmlWaba(o.fecha_hasta || '') + '</small></td>' +
                                '<td>' + escapeHtmlWaba(parseInt(o.mensajes_totales, 10) || 0) + '</td>' +
                                colsPrecios +
                                '<td>' + badge + '</td>' +
                                '<td>' + pagoResumen + '</td>' +
                                colNotif +
                                '<td class="text-end"><div class="btn-group btn-group-sm">' + btns + '</div></td>' +
                            '</tr>';
                        $tbody.append(tr);
                    });

                    const totalPages = parseInt(res.total_pages, 10) || 0;
                    const curPage = parseInt(res.current_page, 10) || 1;
                    const totalItems = parseInt(res.total_records, 10) || 0;

                    if (totalPages > 1) {
                        $('#paginationContainer').attr('style', 'display: flex !important;');
                        $('#lblTotalRegistros').text('Mostrando pág ' + curPage + ' de ' + totalPages + ' (' + totalItems + ' registros)');

                        const $ul = $('#ulPagination');
                        $ul.empty();

                        const prevDisabled = (curPage <= 1) ? 'disabled' : '';
                        $ul.append('<li class="page-item ' + prevDisabled + '"><a class="page-link page-link-btn" href="#" data-page="' + (curPage - 1) + '">Anterior</a></li>');

                        for (let i = 1; i <= totalPages; i++) {
                            const active = (i === curPage) ? 'active' : '';
                            $ul.append('<li class="page-item ' + active + '"><a class="page-link page-link-btn" href="#" data-page="' + i + '">' + i + '</a></li>');
                        }

                        const nextDisabled = (curPage >= totalPages) ? 'disabled' : '';
                        $ul.append('<li class="page-item ' + nextDisabled + '"><a class="page-link page-link-btn" href="#" data-page="' + (curPage + 1) + '">Siguiente</a></li>');
                    } else {
                        $('#paginationContainer').attr('style', 'display: none !important;');
                    }
                } else {
                    Swal.fire('Error', (res && res.message) ? String(res.message) : 'No se pudieron cargar las órdenes', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de comunicación al cargar las órdenes WABA.', 'error');
            },
            complete: function () {
                if (typeof onDone === 'function') onDone();
            }
        });
    }

    // Desglose de Orden
    $(document).on('click', '.btn-detalle', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);

        const idOrden = parseInt($btn.attr('data-id'), 10) || 0;
        $('#lblOrdenId').text(String(idOrden));

        $.ajax({
            url: 'back_waba_ordenes.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_order_details', id_orden: idOrden },
            success: function (res) {
                if (res && res.status === 'success') {
                    const s = res.summary || {};
                    $('#lblOrdenSede').text(s.nombre_sede ? ('(' + String(s.nombre_sede) + ')') : '');
                    $('#lblTotalCobrar').text('$' + (parseFloat(s.monto_total) || 0).toFixed(2));

                    if (isMaster) {
                        $('#lblDetalleCostoMeta').text('$' + (parseFloat(s.costo_meta) || 0).toFixed(2) + ' USD');
                        $('#lblDetalleMargen').text('$' + (parseFloat(s.margen_ganancia) || 0).toFixed(2) + ' USD');
                    }

                    const $tbody = $('#tablaDesglose tbody');
                    $tbody.empty();
                    const colsCount = isMaster ? 7 : 6;

                    if (!Array.isArray(res.data) || res.data.length === 0) {
                        $tbody.append('<tr><td colspan="' + colsCount + '" class="text-center text-muted">No hay desglose detallado para esta orden</td></tr>');
                    } else {
                        res.data.forEach(function (d) {
                            const colCostoBase = isMaster
                                ? '<td class="text-end text-muted">$' + escapeHtmlWaba((parseFloat(d.costo_base) || 0).toFixed(4)) + '</td>'
                                : '';
                            $tbody.append(
                                '<tr>' +
                                    '<td>' +
                                        '<strong>' + escapeHtmlWaba(d.nombre_plantilla || '') + '</strong>' +
                                        '<br><small class="text-muted">Categoría: ' + escapeHtmlWaba(d.categoria || 'MARKETING') + '</small>' +
                                    '</td>' +
                                    '<td class="text-center">' + escapeHtmlWaba(parseInt(d.volumen, 10) || 0) + '</td>' +
                                    '<td class="text-center">' + escapeHtmlWaba(parseInt(d.mensajes_gratuitos, 10) || 0) + '</td>' +
                                    '<td class="text-center">' + escapeHtmlWaba(parseInt(d.mensajes_pagados, 10) || 0) + '</td>' +
                                    colCostoBase +
                                    '<td class="text-end">$' + escapeHtmlWaba((parseFloat(d.precio_unitario_final) || 0).toFixed(4)) + '</td>' +
                                    '<td class="text-end fw-bold">$' + escapeHtmlWaba((parseFloat(d.subtotal_final) || 0).toFixed(2)) + '</td>' +
                                '</tr>'
                            );
                        });
                    }
                    $('#modalDetalleOrden').modal('show');
                } else {
                    Swal.fire('Error', (res && res.message) ? String(res.message) : 'No se pudo obtener el detalle', 'error');
                }
            },
            error: function () {
                Swal.fire('Error', 'Error de red al consultar el desglose de la orden.', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });

    function actualizarCamposMetodoPago(metodo) {
        if (!metodo) {
            $('#avisoSeleccionarMetodo').show();
            $('#bloqueCamposDinamicosPago').hide();
            $('#pago_referencia, #pago_banco_emisor, #pago_titular_emisor, #pago_telefono_emisor').prop('required', false);
            return;
        }

        $('#avisoSeleccionarMetodo').hide();
        $('#bloqueCamposDinamicosPago').fadeIn(150);

        $('#wrapBanco, #wrapTitular, #wrapTelefonoEmisor, #wrapMontoBs').hide();
        $('#pago_banco_emisor, #pago_titular_emisor, #pago_telefono_emisor, #pago_referencia').prop('required', false);
        $('#wrapReferencia').show();

        if (metodo === 'Binance') {
            $('#lblPagoReferencia').html('TxID / ID de Orden Binance <span class="text-danger">*</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: 284910293847102938 / TxID').prop('required', true);

            $('#wrapTitular').show();
            $('#lblPagoTitular').html('Correo o Binance ID Emisor <span class="text-danger">*</span>');
            $('#pago_titular_emisor').attr('placeholder', 'Ej: usuario@correo.com o Pay ID').prop('required', true);

            $('#lblPagoComprobante').text('Captura de Pantalla Binance (Imagen o PDF)');
        } else if (metodo === 'Zelle') {
            $('#lblPagoReferencia').html('Código de Confirmación Zelle <span class="text-danger">*</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: 094812394 / Confirmation #').prop('required', true);

            $('#wrapTitular').show();
            $('#lblPagoTitular').html('Titular de la Cuenta / Correo Zelle <span class="text-danger">*</span>');
            $('#pago_titular_emisor').attr('placeholder', 'Ej: Juan Pérez (juan@correo.com)').prop('required', true);

            $('#lblPagoComprobante').text('Captura del Comprobante Zelle (Imagen o PDF)');
        } else if (metodo === 'Pago Móvil') {
            $('#wrapBanco').show();
            $('#pago_banco_emisor').prop('required', true);

            $('#lblPagoReferencia').html('Número de Referencia <span class="text-danger">*</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: 004829103842 (últimos 6-12 dígitos)').prop('required', true);

            $('#wrapTelefonoEmisor').show();
            $('#lblPagoTelefono').html('Teléfono y Cédula/RIF Emisor <span class="text-danger">*</span>');
            $('#pago_telefono_emisor').attr('placeholder', 'Ej: 0414-1234567 / V-12345678').prop('required', true);

            $('#wrapMontoBs').show();
            $('#lblPagoComprobante').text('Captura de Pago Móvil (Imagen o PDF)');
        } else if (metodo === 'Transferencia') {
            $('#wrapBanco').show();
            $('#pago_banco_emisor').prop('required', true);

            $('#lblPagoReferencia').html('Número de Referencia / Operación <span class="text-danger">*</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: 984512369').prop('required', true);

            $('#wrapTitular').show();
            $('#lblPagoTitular').html('Titular / RIF de la Cuenta Emisora <span class="text-danger">*</span>');
            $('#pago_titular_emisor').attr('placeholder', 'Ej: Inversiones XYZ, C.A. / J-12345678-9').prop('required', true);

            $('#wrapMontoBs').show();
            $('#lblPagoComprobante').text('Comprobante de Transferencia (Imagen o PDF)');
        } else if (metodo === 'Efectivo') {
            $('#wrapTitular').show();
            $('#lblPagoTitular').html('Entregado por / Recibido por <span class="text-danger">*</span>');
            $('#pago_titular_emisor').attr('placeholder', 'Ej: Entregado por Gerente a Administración').prop('required', true);

            $('#lblPagoReferencia').html('Nº de Recibo o Seriales de Billetes <span class="text-muted fw-normal">(Opcional)</span>');
            $('#pago_referencia').attr('placeholder', 'Ej: Recibo #045 / Seriales USD').prop('required', false);

            $('#lblPagoComprobante').text('Foto de Recibo o Billetes (Opcional)');
        }
    }

    $('#pago_metodo').on('change', function () {
        actualizarCamposMetodoPago($(this).val());
    });

    // Abrir Modal para Registrar / Reportar Pago
    $(document).on('click', '.btn-pagar', function () {
        const idOrden = parseInt($(this).attr('data-id'), 10) || 0;
        const o = currentOrdersMap[idOrden];
        if (!o) return;

        $('#formPagoOrden')[0].reset();
        $('#pago_id_orden').val(orderIdStr(o.id));
        $('#lblPagoOrdenId').text(orderIdStr(o.id));
        $('#lblPagoSedeNombre').text(String(o.nombre_sede || '-'));
        $('#lblPagoMontoTotal').text('$' + (parseFloat(o.monto_total) || 0).toFixed(2) + ' USD');

        if (o.metodo_pago) $('#pago_metodo').val(o.metodo_pago);
        if (o.referencia_pago && o.referencia_pago !== 'Entrega en Efectivo') {
            $('#pago_referencia').val(o.referencia_pago);
        }
        if (o.fecha_pago) $('#pago_fecha').val(String(o.fecha_pago).substring(0, 10));
        if (o.notas_pago) $('#pago_notas').val(o.notas_pago);

        actualizarCamposMetodoPago($('#pago_metodo').val());
        $('#modalReportarPago').modal('show');
    });

    function orderIdStr(idVal) {
        return String(parseInt(idVal, 10) || 0);
    }

    // Envío de Formulario de Pago con bloqueo anti-doble-clic inmediato
    $('#formPagoOrden').on('submit', function (e) {
        e.preventDefault();
        const $btnSubmit = $('#btnSubmitPago');
        if ($btnSubmit.prop('disabled')) return;

        const fileInput = document.getElementById('pago_comprobante');
        if (fileInput && fileInput.files && fileInput.files.length > 0) {
            if (fileInput.files[0].size > 5 * 1024 * 1024) {
                Swal.fire('Archivo demasiado grande', 'El comprobante no debe superar el tamaño máximo de 5 MB.', 'warning');
                return;
            }
        }

        $btnSubmit.prop('disabled', true);
        const formData = new FormData(this);

        Swal.fire({
            title: 'Procesando pago...',
            text: 'Guardando datos y comprobante adjunto',
            allowOutsideClick: false,
            didOpen: function () { Swal.showLoading(); }
        });

        $.ajax({
            url: 'back_waba_ordenes.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (res && res.status === 'success') {
                    $('#modalReportarPago').modal('hide');
                    Swal.fire('¡Registrado!', String(res.message || 'Pago reportado.'), 'success');
                    cargarOrdenes(currentPage);
                } else {
                    Swal.fire('Atención', (res && res.message) ? String(res.message) : 'Error al registrar el pago', 'warning');
                }
            },
            error: function () {
                Swal.fire('Error', 'Error de comunicación con el servidor.', 'error');
            },
            complete: function () {
                $btnSubmit.prop('disabled', false);
            }
        });
    });

    // Abrir Modal de Verificación / Consulta de Comprobante
    $(document).on('click', '.btn-ver-pago', function () {
        const idOrden = parseInt($(this).attr('data-id'), 10) || 0;
        const o = currentOrdersMap[idOrden];
        if (!o) return;

        $('#verPagoOrdenId').text(orderIdStr(o.id));
        $('#verPagoSede').text(String(o.nombre_sede || '-'));
        $('#verPagoMonto').text('$' + (parseFloat(o.monto_total) || 0).toFixed(2) + ' USD');
        $('#verPagoEstadoBadge').html(renderEstadoBadge(o.estado));
        $('#verPagoMetodo').text(String(o.metodo_pago || 'No especificado'));
        $('#verPagoReferencia').text(String(o.referencia_pago || 'N/A'));
        $('#verPagoFecha').text(String(o.fecha_pago || 'N/A'));
        $('#verPagoNotas').text(String(o.notas_pago || 'Sin observaciones'));
        $('#txtNotaVerificacion').val('');
        $('#btnImprimirReciboModal').attr('href', '../../imprimir_orden_waba.php?id=' + encodeURIComponent(orderIdStr(o.id)));

        if (isMaster) {
            $('#masterVerifyPanel').attr('data-id-orden', orderIdStr(o.id));
            $('#masterVerifyPanel').show();
            if (String(o.estado || '').toUpperCase() === 'PAGADO') {
                $('#btnAprobarPagoMaster').hide();
                $('#btnPonerEnRevisionMaster').show();
            } else {
                $('#btnAprobarPagoMaster').show();
                $('#btnPonerEnRevisionMaster').hide();
            }
        }

        const $cont = $('#verPagoComprobanteContainer');
        $cont.empty();
        const compRel = String(o.comprobante_ruta || o.comprobante_path || '');
        if (compRel !== '') {
            const fileBase = compRel.replace(/\\/g, '/').split('/').pop() || '';
            const safeUrl = '../../ver_documento.php?tipo=comprobante_waba&file=' + encodeURIComponent(fileBase);
            const ext = (fileBase.split('.').pop() || '').toLowerCase();
            if (ext === 'pdf') {
                $cont.html(
                    '<div class="py-4">' +
                        '<i class="fas fa-file-pdf fa-4x text-danger mb-3"></i>' +
                        '<p class="mb-2 fw-semibold">Documento PDF Adjunto</p>' +
                        '<a href="' + escapeHtmlWaba(safeUrl) + '" target="_blank" class="btn btn-sm btn-primary">' +
                            '<i class="fas fa-external-link-alt me-1"></i> Abrir Comprobante PDF' +
                        '</a>' +
                    '</div>'
                );
            } else {
                $cont.html(
                    '<a href="' + escapeHtmlWaba(safeUrl) + '" target="_blank" title="Clic para abrir imagen completa">' +
                        '<img src="' + escapeHtmlWaba(safeUrl) + '" alt="Comprobante de Pago">' +
                    '</a>' +
                    '<div class="mt-2">' +
                        '<a href="' + escapeHtmlWaba(safeUrl) + '" target="_blank" class="btn btn-sm btn-outline-primary">' +
                            '<i class="fas fa-magnifying-glass-plus me-1"></i> Ver en tamaño completo' +
                        '</a>' +
                    '</div>'
                );
            }
        } else {
            $cont.html('<div class="py-5 text-muted"><i class="fas fa-file-circle-xmark fa-2x mb-2 d-block"></i>No se adjuntó archivo de comprobante</div>');
        }

        $('#modalVerificarPago').modal('show');
    });

    function ejecutarDecisionVerificacion($btnTrigger, decision, confirmOpts) {
        if ($btnTrigger.prop('disabled')) return;
        const idOrden = parseInt($('#masterVerifyPanel').attr('data-id-orden'), 10) || 0;
        const notas = $('#txtNotaVerificacion').val() || '';

        const doPost = function () {
            $btnTrigger.prop('disabled', true);
            $('#btnAprobarPagoMaster, #btnPonerEnRevisionMaster, #btnRechazarPagoMaster').prop('disabled', true);

            $.ajax({
                url: 'back_waba_ordenes.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'verify_payment',
                    id_orden: idOrden,
                    decision: decision,
                    notas_verificacion: notas
                },
                success: function (r) {
                    if (r && r.status === 'success') {
                        $('#modalVerificarPago').modal('hide');
                        Swal.fire('Actualizado', String(r.message || 'Estado actualizado.'), decision === 'APROBAR' ? 'success' : 'info');
                        cargarOrdenes(currentPage);
                    } else {
                        Swal.fire('Error', (r && r.message) ? String(r.message) : 'No se pudo actualizar la orden.', 'error');
                    }
                },
                error: function () {
                    Swal.fire('Error', 'Fallo de red al verificar el pago.', 'error');
                },
                complete: function () {
                    $('#btnAprobarPagoMaster, #btnPonerEnRevisionMaster, #btnRechazarPagoMaster').prop('disabled', false);
                }
            });
        };

        if (!confirmOpts) {
            doPost();
            return;
        }

        $btnTrigger.prop('disabled', true);
        Swal.fire(confirmOpts).then(function (res) {
            $btnTrigger.prop('disabled', false);
            if (res.isConfirmed) {
                doPost();
            }
        });
    }

    $('#btnAprobarPagoMaster').on('click', function () {
        const $btn = $(this);
        const idOrden = parseInt($('#masterVerifyPanel').attr('data-id-orden'), 10) || 0;
        ejecutarDecisionVerificacion($btn, 'APROBAR', {
            title: '¿Confirmar ingreso del pago?',
            text: 'La orden #' + idOrden + ' pasará a estado PAGADO definitivo.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            confirmButtonText: 'Sí, confirmar pago',
            cancelButtonText: 'Cancelar'
        });
    });

    $('#btnPonerEnRevisionMaster').on('click', function () {
        ejecutarDecisionVerificacion($(this), 'EN_REVISION', null);
    });

    $('#btnRechazarPagoMaster').on('click', function () {
        const $btn = $(this);
        const idOrden = parseInt($('#masterVerifyPanel').attr('data-id-orden'), 10) || 0;
        ejecutarDecisionVerificacion($btn, 'RECHAZAR', {
            title: '¿Rechazar reporte de pago?',
            text: 'La orden #' + idOrden + ' será devuelta a estado PENDIENTE.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'Sí, devolver a PENDIENTE',
            cancelButtonText: 'Cancelar'
        });
    });

    $(document).on('click', '.btn-notificar', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);

        const idOrden = parseInt($btn.attr('data-id'), 10) || 0;
        Swal.fire({
            title: '¿Reenviar Notificación?',
            text: 'Se enviará un recordatorio de pago al Gerente de esta sucursal por WhatsApp/Email.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, enviar',
            cancelButtonText: 'Cancelar'
        }).then(function (result) {
            if (!result.isConfirmed) {
                $btn.prop('disabled', false);
                return;
            }
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
            $.ajax({
                url: 'back_waba_ordenes.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'resend_notification', id_orden: idOrden },
                success: function (res) {
                    if (res && res.status === 'success') {
                        Swal.fire('¡Enviado!', String(res.message || 'Notificación enviada.'), 'success');
                        cargarOrdenes(currentPage);
                    } else {
                        Swal.fire('Error', (res && res.message) ? String(res.message) : 'No se pudo enviar la notificación.', 'error');
                    }
                },
                error: function () {
                    Swal.fire('Error', 'Fallo de red al reenviar la notificación.', 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        });
    });

    $(document).on('click', '.btn-eliminar', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);

        const idOrden = parseInt($btn.attr('data-id'), 10) || 0;
        Swal.fire({
            title: '¿Eliminar Orden?',
            text: 'Esta acción anulará y eliminará esta orden de cobro permanentemente. ¿Está seguro?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then(function (result) {
            if (!result.isConfirmed) {
                $btn.prop('disabled', false);
                return;
            }
            Swal.fire({ title: 'Eliminando...', allowOutsideClick: false, didOpen: function () { Swal.showLoading(); } });
            $.ajax({
                url: 'back_waba_ordenes.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'delete_order', id_orden: idOrden },
                success: function (res) {
                    if (res && res.status === 'success') {
                        Swal.fire('¡Eliminado!', String(res.message || 'Orden eliminada.'), 'success');
                        cargarOrdenes(currentPage);
                    } else {
                        Swal.fire('Error', (res && res.message) ? String(res.message) : 'No se pudo eliminar la orden.', 'error');
                    }
                },
                error: function () {
                    Swal.fire('Error', 'Fallo de comunicación al eliminar la orden.', 'error');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                }
            });
        });
    });
});
