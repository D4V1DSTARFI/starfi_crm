<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('buzon_correos');

$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
$es_master = (intval($agente['id'] ?? 0) === 1 || strtoupper(trim((string)($agente['rol'] ?? ''))) === 'MASTER');
if (!$es_master) {
    http_response_code(403);
    exit('403 Forbidden');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buzón de Correos SMTP | STARFI CRM</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        .buzon-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            background-color: var(--bg-main);
        }
        .kpi-smtp-card {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            padding: 18px 20px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
        }
    </style>
</head>
<body>
    <?php renderHeader('Buzón de Correos del Sistema'); ?>
    <div class="app-container" id="buzonCorreosRoot" data-es-master="1">
        <?php renderSidebar('buzon_correos'); ?>

        <main class="main-content">
            <div class="buzon-scroll">
                <div class="container-fluid px-0">
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                        <div>
                            <h2 class="mb-0 fw-bold text-starfi-dark">
                                <i class="fas fa-envelope-open-text text-starfi-primary me-2"></i>Buzón de Salida y Auditoría SMTP
                            </h2>
                            <p class="text-muted mb-0 small">Monitoreo exclusivo MASTER de la cola de notificaciones y correos transaccionales</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" id="btnProcesarColaSmtp" class="btn btn-starfi-primary btn-sm px-3 py-2 fw-semibold">
                                <i class="fas fa-paper-plane me-1"></i> Procesar Cola Pendiente
                            </button>
                            <a href="../../index.php" class="btn btn-outline-secondary btn-sm px-3 py-2 fw-semibold bg-white">
                                <i class="fas fa-arrow-left me-1"></i> Volver al Inicio
                            </a>
                        </div>
                    </div>

                    <!-- Tarjetas Resumen -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="kpi-smtp-card" style="border-left: 4px solid #16a34a;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted text-uppercase fw-bold">Enviados</small>
                                        <h2 class="mb-0 fw-bold text-success mt-1" id="statEnviados">0</h2>
                                    </div>
                                    <i class="fas fa-circle-check fa-2x text-success opacity-75"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="kpi-smtp-card" style="border-left: 4px solid #dc2626;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted text-uppercase fw-bold">Con Error</small>
                                        <h2 class="mb-0 fw-bold text-danger mt-1" id="statErrores">0</h2>
                                    </div>
                                    <i class="fas fa-triangle-exclamation fa-2x text-danger opacity-75"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="kpi-smtp-card" style="border-left: 4px solid #d97706;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <small class="text-muted text-uppercase fw-bold">Pendientes en Cola</small>
                                        <h2 class="mb-0 fw-bold text-warning mt-1" id="statPendientes">0</h2>
                                    </div>
                                    <i class="fas fa-clock fa-2x text-warning opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros -->
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-body py-3">
                            <form id="formFiltrosBuzonCorreos" class="row g-2 align-items-end" autocomplete="off">
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold text-secondary mb-1" for="filtroEstadoCorreo">Estado</label>
                                    <select id="filtroEstadoCorreo" class="form-select">
                                        <option value="">Todos los estados</option>
                                        <option value="Enviado">Enviado</option>
                                        <option value="Pendiente">Pendiente</option>
                                        <option value="Error">Error</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-semibold text-secondary mb-1" for="filtroBusquedaCorreo">Buscar Destinatario o Asunto</label>
                                    <input type="text" id="filtroBusquedaCorreo" class="form-control" placeholder="Ej: correo@empresa.com o Estado de Cuenta...">
                                </div>
                                <div class="col-md-3 d-flex gap-2">
                                    <button type="submit" id="btnFiltrarCorreos" class="btn btn-primary w-100 fw-semibold">
                                        <i class="fas fa-search me-1"></i> Filtrar
                                    </button>
                                    <button type="button" id="btnLimpiarCorreos" class="btn btn-outline-secondary w-100">
                                        <i class="fas fa-rotate me-1"></i> Actualizar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Tabla de Cola de Correos -->
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" id="tablaColaCorreos">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="ps-3">ID</th>
                                            <th>Destinatario</th>
                                            <th>Asunto</th>
                                            <th>Estado</th>
                                            <th>Fecha Registro</th>
                                            <th>Fecha Envío</th>
                                            <th class="text-end pe-3">Acciones / Diagnóstico</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyColaCorreos">
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">Cargando historial de correos...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <?php
    define('STARFI_MODALS_ALLOWED', true);
    require __DIR__ . '/modals_buzon_correos.php';
    ?>

    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_buzon_correos.js"></script>
</body>
</html>
