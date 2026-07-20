<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
$agente = getAgenteInfo();

if ($agente['rol'] !== 'MASTER') {
    die("Acceso Denegado. Solo MASTER puede ver este panel.");
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
                        <!-- Filled via AJAX -->
                    </tbody>
                </table>
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
    cargarOrdenes();

    function cargarOrdenes() {
        $.post('back_waba_billing.php', { action: 'get_orders' }, function(res) {
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
                                <button class="btn btn-outline-info btn-detalle" data-id="${o.id}" data-monto="${o.monto_total}" title="Ver Detalles"><i class="fas fa-list"></i></button>
                                ${o.estado === 'PENDIENTE' ? `<button class="btn btn-outline-primary btn-notificar" data-id="${o.id}" title="Reenviar Notificación"><i class="fas fa-paper-plane"></i></button>` : ''}
                                <button class="btn btn-outline-success btn-pagar" data-id="${o.id}" ${o.estado !== 'PENDIENTE' ? 'disabled' : ''} title="Marcar Pagado"><i class="fas fa-check"></i></button>
                            </div>
                        </td>
                    </tr>`;
                    tbody.append(tr);
                });
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
        // A placeholder for the Mark as Paid functionality
        Swal.fire({
            title: 'Marcar como Pagado',
            text: "Esta acción marcará la orden #" + id_orden + " como PAGADA. (Funcionalidad en desarrollo).",
            icon: 'info'
        });
    });
});
</script>
    </main>
    </div>
</body>
</html>
