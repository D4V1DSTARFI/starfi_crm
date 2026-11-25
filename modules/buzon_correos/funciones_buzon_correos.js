// modules/buzon_correos/funciones_buzon_correos.js
// Controlador Frontend del Buzón SMTP Exclusivo MASTER — STARFI CRM (QA-STD-GLOBAL-2.1)
// 100% escapeHtml() contra DOM-XSS + iframe sandbox="" + bloqueo anti-doble-clic.

function escapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

$(document).ready(function () {
    let activeEmailModalId = 0;

    const toggleBtn = document.getElementById('toggleSidebar');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function () {
            const sb = document.getElementById('sidebar');
            if (sb) sb.classList.toggle('collapsed');
        });
    }

    cargarColaCorreos();

    $('#formFiltrosBuzonCorreos').on('submit', function (e) {
        e.preventDefault();
        const $btn = $('#btnFiltrarCorreos');
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);
        cargarColaCorreos(function () {
            $btn.prop('disabled', false);
        });
    });

    $('#filtroEstadoCorreo').on('change', function () {
        cargarColaCorreos();
    });

    $('#btnLimpiarCorreos').on('click', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);
        $('#filtroEstadoCorreo').val('');
        $('#filtroBusquedaCorreo').val('');
        cargarColaCorreos(function () {
            $btn.prop('disabled', false);
        });
    });

    $('#btnProcesarColaSmtp').on('click', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);

        Swal.fire({
            title: 'Procesando cola SMTP...',
            text: 'Despachando correos pendientes y reintentables',
            allowOutsideClick: false,
            didOpen: function () { Swal.showLoading(); }
        });

        $.ajax({
            url: 'back_buzon_correos.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'process_queue' },
            success: function (res) {
                if (res && res.status === 'success') {
                    Swal.fire('Cola Procesada', String(res.message || 'Proceso completado.'), 'success');
                    cargarColaCorreos();
                } else {
                    Swal.fire('Atención', (res && res.message) ? String(res.message) : 'No se pudo procesar la cola SMTP.', 'warning');
                }
            },
            error: function () {
                Swal.fire('Error', 'Error de red al procesar la cola SMTP.', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });

    function renderBadgeCorreo(estadoRaw) {
        const est = String(estadoRaw || '');
        if (est === 'Enviado') {
            return '<span class="badge bg-success">Enviado</span>';
        }
        if (est === 'Error') {
            return '<span class="badge bg-danger">Error</span>';
        }
        return '<span class="badge bg-warning text-dark">' + escapeHtml(est || 'Pendiente') + '</span>';
    }

    function cargarColaCorreos(onDone) {
        const estado = $('#filtroEstadoCorreo').val() || '';
        const q = $('#filtroBusquedaCorreo').val() || '';

        $.ajax({
            url: 'back_buzon_correos.php',
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'list_emails',
                estado: estado,
                q: q
            },
            success: function (res) {
                if (!res || res.status !== 'success') {
                    Swal.fire('Error', (res && res.message) ? String(res.message) : 'No se pudo cargar el buzón SMTP.', 'error');
                    return;
                }

                const stats = res.stats || {};
                $('#statEnviados').text(String(parseInt(stats.Enviado, 10) || 0));
                $('#statErrores').text(String(parseInt(stats.Error, 10) || 0));
                $('#statPendientes').text(String(parseInt(stats.Pendiente, 10) || 0));

                const $tbody = $('#tbodyColaCorreos');
                $tbody.empty();

                const rows = Array.isArray(res.data) ? res.data : [];
                if (rows.length === 0) {
                    $tbody.append('<tr><td colspan="7" class="text-center py-4 text-muted">No se encontraron correos registrados para los filtros activos.</td></tr>');
                    return;
                }

                rows.forEach(function (c) {
                    const id = parseInt(c.id, 10) || 0;
                    const intentos = parseInt(c.intentos, 10) || 0;
                    const hasErr = Boolean(c.error_detalle && String(c.error_detalle).trim() !== '');

                    let actionBtns =
                        '<button type="button" class="btn btn-sm btn-outline-dark btn-ver-correo" data-id="' + escapeHtml(id) + '">' +
                            '<i class="fas fa-eye me-1"></i>Inspeccionar' +
                        '</button>';

                    if (hasErr) {
                        actionBtns +=
                            ' <button type="button" class="btn btn-sm btn-outline-danger btn-ver-error-rapido" data-error="' + escapeHtml(c.error_detalle) + '" data-intentos="' + escapeHtml(intentos) + '">' +
                                'Error (' + escapeHtml(intentos) + ')' +
                            '</button>';
                    }

                    if (String(c.estado) !== 'Enviado') {
                        actionBtns +=
                            ' <button type="button" class="btn btn-sm btn-outline-primary btn-reintentar-correo" data-id="' + escapeHtml(id) + '" title="Reencolar para envío">' +
                                '<i class="fas fa-rotate-right"></i>' +
                            '</button>';
                    }

                    const tr =
                        '<tr>' +
                            '<td class="ps-3">#' + escapeHtml(id) + '</td>' +
                            '<td>' +
                                '<strong>' + escapeHtml(c.destinatario_nombre || '') + '</strong><br>' +
                                '<small class="text-muted">' + escapeHtml(c.destinatario || '') + '</small>' +
                            '</td>' +
                            '<td>' + escapeHtml(c.asunto || '') + '</td>' +
                            '<td>' + renderBadgeCorreo(c.estado) + '</td>' +
                            '<td><small>' + escapeHtml(c.fecha_creacion || '') + '</small></td>' +
                            '<td><small>' + escapeHtml(c.sent_at || '---') + '</small></td>' +
                            '<td class="text-end pe-3"><div class="d-inline-flex gap-1 flex-wrap justify-content-end">' + actionBtns + '</div></td>' +
                        '</tr>';
                    $tbody.append(tr);
                });
            },
            error: function () {
                Swal.fire('Error', 'Fallo de comunicación al consultar la cola SMTP.', 'error');
            },
            complete: function () {
                if (typeof onDone === 'function') onDone();
            }
        });
    }

    $(document).on('click', '.btn-ver-error-rapido', function () {
        const errTxt = String($(this).attr('data-error') || 'Sin detalle');
        const intentos = parseInt($(this).attr('data-intentos'), 10) || 0;
        Swal.fire({
            title: 'Detalle del Error SMTP (Intentos: ' + intentos + ')',
            text: errTxt,
            icon: 'error'
        });
    });

    $(document).on('click', '.btn-ver-correo', function () {
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true);

        const idCorreo = parseInt($btn.attr('data-id'), 10) || 0;
        activeEmailModalId = idCorreo;

        $.ajax({
            url: 'back_buzon_correos.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'get_email_detail', id: idCorreo },
            success: function (res) {
                if (!res || res.status !== 'success' || !res.email) {
                    Swal.fire('Error', (res && res.message) ? String(res.message) : 'No se encontró el correo solicitado.', 'error');
                    return;
                }
                const em = res.email;
                $('#detCorreoId').text(String(parseInt(em.id, 10) || 0));
                $('#detCorreoDestinatario').text(String((em.destinatario_nombre ? em.destinatario_nombre + ' <' + em.destinatario + '>' : em.destinatario) || '-'));
                $('#detCorreoEstado').html(renderBadgeCorreo(em.estado));
                $('#detCorreoAsunto').text(String(em.asunto || '-'));
                $('#detCorreoCreado').text(String(em.fecha_creacion || '-'));
                $('#detCorreoEnviado').text(String(em.sent_at || '---'));

                if (em.error_detalle) {
                    $('#detCorreoErrorTexto').text(String(em.error_detalle));
                    $('#wrapDetCorreoError').show();
                } else {
                    $('#detCorreoErrorTexto').text('');
                    $('#wrapDetCorreoError').hide();
                }

                const iframeEl = document.getElementById('iframeVistaCorreo');
                if (iframeEl) {
                    iframeEl.srcdoc = String(em.cuerpo_html || '<p style="font-family:sans-serif;color:#64748b;">Sin contenido HTML.</p>');
                }

                if (String(em.estado) !== 'Enviado') {
                    $('#btnReintentarDesdeModal').show();
                } else {
                    $('#btnReintentarDesdeModal').hide();
                }

                $('#modalDetalleCorreo').modal('show');
            },
            error: function () {
                Swal.fire('Error', 'Fallo de conexión al obtener el detalle del correo.', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });

    function reintentarCorreoPorId($btn, idCorreo) {
        if ($btn.prop('disabled') || idCorreo <= 0) return;
        $btn.prop('disabled', true);

        $.ajax({
            url: 'back_buzon_correos.php',
            type: 'POST',
            dataType: 'json',
            data: { action: 'retry_email', id: idCorreo },
            success: function (res) {
                if (res && res.status === 'success') {
                    $('#modalDetalleCorreo').modal('hide');
                    Swal.fire('Actualizado', String(res.message || 'Correo reencolado.'), 'success');
                    cargarColaCorreos();
                } else {
                    Swal.fire('Atención', (res && res.message) ? String(res.message) : 'No se pudo reintentar el correo.', 'warning');
                }
            },
            error: function () {
                Swal.fire('Error', 'Fallo de red al reintentar el correo.', 'error');
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    }

    $(document).on('click', '.btn-reintentar-correo', function () {
        const $btn = $(this);
        const idCorreo = parseInt($btn.attr('data-id'), 10) || 0;
        reintentarCorreoPorId($btn, idCorreo);
    });

    $('#btnReintentarDesdeModal').on('click', function () {
        reintentarCorreoPorId($(this), activeEmailModalId);
    });
});
