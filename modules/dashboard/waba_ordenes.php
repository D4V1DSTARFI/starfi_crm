<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('waba_ordenes');
$agente = getAgenteInfo();

$con = getDbConnection();
$res_sedes = $con->query("SELECT id, nombre_sede FROM sedes WHERE estado = 'ACTIVO' ORDER BY nombre_sede ASC");
$sedes = [];
if ($res_sedes) {
    while ($row = $res_sedes->fetch_assoc()) {
        $sedes[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Órdenes de Cobro | STARFI CRM</title>
    <!-- Bootstrap CSS -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; margin: 0; padding: 0; overflow-x: hidden; }
        .app-container { min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; padding: 20px; }
    </style>
</head>
<body>
    <?php renderHeader('Panel de Órdenes WABA'); ?>
    <div class="app-container">
    <main class="main-content">
        
<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Panel Administrativo - Órdenes de Cobro WhatsApp</h2>
        <a href="../../index.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left"></i> Volver al Inicio
        </a>
    </div>

    <!-- Filtros -->
    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label mb-1">Filtrar por Sede</label>
                    <select id="filter_sede" class="form-select">
                        <option value="">Todas las Sedes</option>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre_sede']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">Fecha Desde</label>
                    <input type="date" id="filter_fecha_desde" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-1">Fecha Hasta</label>
                    <input type="date" id="filter_fecha_hasta" class="form-control">
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button id="btnFiltrar" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i>Buscar</button>
                    <button id="btnLimpiar" class="btn btn-outline-secondary w-100"><i class="fas fa-eraser me-1"></i>Limpiar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-striped align-middle" id="tablaOrdenes">
                    <thead class="table-dark">
                        <tr>
                            <th># Orden</th>
                            <th>Sede / Sucursal</th>
                            <th>Periodo</th>
                            <th>Mensajes</th>
                            <th>Costo Meta</th>
                            <th>A Cobrar</th>
                            <th>Estado</th>
                            <th>Notificaciones</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginación -->
            <div class="d-flex justify-content-between align-items-center mt-3" id="paginationContainer" style="display:none !important;">
                <span class="text-muted" id="lblTotalRegistros">Mostrando 0 registros</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="ulPagination">
                        <!-- Filled via AJAX -->
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<!-- Modal Detalles -->
<div class="modal fade" id="modalDetalleOrden" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Desglose de Orden #<span id="lblOrdenId"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <table class="table table-sm table-bordered" id="tablaDesglose">
                    <thead class="table-light">
                        <tr>
                            <th>Plantilla</th>
                            <th>Volumen</th>
                            <th>Costo Base Estimado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Filled via AJAX -->
                    </tbody>
                </table>
                <div class="mt-3 text-end">
                    <h5>Total a Pagar: <span id="lblTotalCobrar" class="text-primary fw-bold"></span> USD</h5>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(document).ready(function() {
    let currentPage = 1;

    cargarOrdenes(1);

    $('#btnFiltrar').click(function() {
        cargarOrdenes(1);
    });

    $('#btnLimpiar').click(function() {
        $('#filter_sede').val('');
        $('#filter_fecha_desde').val('');
        $('#filter_fecha_hasta').val('');
        cargarOrdenes(1);
    });

    $(document).on('click', '.page-link-btn', function(e) {
        e.preventDefault();
        let page = $(this).data('page');
        if (page) {
            cargarOrdenes(page);
        }
    });

    function cargarOrdenes(page = 1) {
        currentPage = page;
        
        let reqData = {
            action: 'get_orders',
            page: page,
            id_sede: $('#filter_sede').val(),
            fecha_desde: $('#filter_fecha_desde').val(),
            fecha_hasta: $('#filter_fecha_hasta').val()
        };

        $.post('back_waba_billing.php', reqData, function(res) {
            if (res.status === 'success') {
                let tbody = $('#tablaOrdenes tbody');
                tbody.empty();
                if (res.data.length === 0) {
                    tbody.append('<tr><td colspan="9" class="text-center">No hay órdenes generadas</td></tr>');
                    return;
                }
                
                res.data.forEach(o => {
                    let badge = '';
                    if (o.estado === 'PENDIENTE') badge = '<span class="badge bg-warning text-dark">PENDIENTE</span>';
                    else if (o.estado === 'PAGADO') badge = '<span class="badge bg-success">PAGADO</span>';
                    else if (o.estado === 'FUSIONADO') badge = '<span class="badge bg-secondary">FUSIONADO</span>';
                    
                    let notifBadge = o.notificacion_enviada > 0 
                        ? `<span class="badge bg-info"><i class="fas fa-check-double"></i> Enviada (${o.notificacion_enviada})</span>`
                        : `<span class="badge bg-light text-dark border"><i class="fas fa-clock"></i> Pendiente</span>`;

                    let tr = `<tr>
                        <td><b>#${o.id}</b></td>
                        <td>${o.nombre_sede}</td>
                        <td>${o.fecha_desde} <br> <small class="text-muted">al ${o.fecha_hasta}</small></td>
                        <td>${o.mensajes_totales}</td>
                        <td>$${parseFloat(o.costo_meta).toFixed(2)}</td>
                        <td class="fw-bold text-primary">$${parseFloat(o.monto_total).toFixed(2)}</td>
                        <td>${badge}</td>
                        <td>${notifBadge}</td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="imprimir_orden_waba.php?id=${o.id}" target="_blank" class="btn btn-outline-secondary" title="Imprimir / PDF"><i class="fas fa-file-pdf"></i></a>
                                <button class="btn btn-outline-info btn-detalle" data-id="${o.id}" data-monto="${o.monto_total}" title="Ver Detalles"><i class="fas fa-list"></i></button>
                                ${o.estado === 'PENDIENTE' ? `<button class="btn btn-outline-primary btn-notificar" data-id="${o.id}" title="Reenviar Notificación"><i class="fas fa-paper-plane"></i></button>` : ''}
                                <button class="btn btn-outline-success btn-pagar" data-id="${o.id}" ${o.estado !== 'PENDIENTE' ? 'disabled' : ''} title="Marcar Pagado"><i class="fas fa-check"></i></button>
                                <button class="btn btn-outline-danger btn-eliminar" data-id="${o.id}" title="Eliminar Orden"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>`;
                    tbody.append(tr);
                });

                // Construir Paginación
                if (res.total_pages > 1) {
                    $('#paginationContainer').attr('style', 'display: flex !important;');
                    let totalItems = res.total_records || 0;
                    $('#lblTotalRegistros').text(`Mostrando pág ${res.current_page} de ${res.total_pages} (${totalItems} registros)`);
                    
                    let ul = $('#ulPagination');
                    ul.empty();
                    
                    let prevDisabled = (res.current_page == 1) ? 'disabled' : '';
                    ul.append(`<li class="page-item ${prevDisabled}"><a class="page-link page-link-btn" href="#" data-page="${res.current_page - 1}">Anterior</a></li>`);
                    
                    for (let i = 1; i <= res.total_pages; i++) {
                        let active = (i == res.current_page) ? 'active' : '';
                        ul.append(`<li class="page-item ${active}"><a class="page-link page-link-btn" href="#" data-page="${i}">${i}</a></li>`);
                    }
                    
                    let nextDisabled = (res.current_page == res.total_pages) ? 'disabled' : '';
                    ul.append(`<li class="page-item ${nextDisabled}"><a class="page-link page-link-btn" href="#" data-page="${res.current_page + 1}">Siguiente</a></li>`);
                } else {
                    $('#paginationContainer').attr('style', 'display: none !important;');
                }

            } else {
                Swal.fire('Error', 'No se pudieron cargar las órdenes', 'error');
            }
        }, 'json');
    }

    $(document).on('click', '.btn-detalle', function() {
        let id_orden = $(this).data('id');
        let monto = $(this).data('monto');
        
        $('#lblOrdenId').text(id_orden);
        $('#lblTotalCobrar').text(parseFloat(monto).toFixed(2));
        
        $.post('back_waba_billing.php', { action: 'get_order_details', id_orden: id_orden }, function(res) {
            if (res.status === 'success') {
                let tbody = $('#tablaDesglose tbody');
                tbody.empty();
                if (res.data.length === 0) {
                    tbody.append('<tr><td colspan="3" class="text-center text-muted">No hay desglose detallado para esta orden</td></tr>');
                } else {
                    res.data.forEach(d => {
                        tbody.append(`<tr>
                            <td>${d.nombre_plantilla}</td>
                            <td>${d.volumen}</td>
                            <td>$${parseFloat(d.costo_base).toFixed(2)}</td>
                        </tr>`);
                    });
                }
                $('#modalDetalleOrden').modal('show');
            }
        }, 'json');
    });

    $(document).on('click', '.btn-notificar', function() {
        let id_orden = $(this).data('id');
        Swal.fire({
            title: '¿Reenviar Notificación?',
            text: "Se enviará un recordatorio de pago al Gerente de esta sucursal por WhatsApp/Email.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, enviar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                $.post('back_waba_billing.php', { action: 'resend_notification', id_orden: id_orden }, function(res) {
                    if (res.status === 'success') {
                        Swal.fire('¡Enviado!', res.message, 'success');
                        cargarOrdenes();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }, 'json');
            }
        });
    });

    $(document).on('click', '.btn-pagar', function() {
        let id_orden = $(this).data('id');
        Swal.fire({
            title: '¿Marcar como Pagado?',
            text: "Esta acción marcará la orden #" + id_orden + " como PAGADA de forma definitiva.",
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, marcar pagado',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Procesando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                $.post('back_waba_billing.php', { action: 'mark_paid', id_orden: id_orden }, function(res) {
                    if (res.status === 'success') {
                        Swal.fire('¡Éxito!', res.message, 'success');
                        cargarOrdenes();
                    } else {
                        Swal.fire('Error', res.message || 'Error al procesar el pago', 'error');
                    }
                }, 'json');
            }
        });
    });

    $(document).on('click', '.btn-eliminar', function() {
        let id_orden = $(this).data('id');
        Swal.fire({
            title: '¿Eliminar Orden?',
            text: "Esta acción anulará y eliminará esta orden de cobro permanentemente. ¿Estás seguro?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire({ title: 'Eliminando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                $.post('back_waba_billing.php', { action: 'delete_order', id_orden: id_orden }, function(res) {
                    if (res.status === 'success') {
                        Swal.fire('¡Eliminado!', res.message, 'success');
                        cargarOrdenes();
                    } else {
                        Swal.fire('Error', res.message || 'Error desconocido', 'error');
                    }
                }, 'json');
            }
        });
    });
});
</script>
    </main>
    </div>
</body>
</html>
