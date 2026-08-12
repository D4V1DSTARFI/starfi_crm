<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('whatsapp_analytics');
$agente = getAgenteInfo();

// Cargar sedes activas para el filtro
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
    <title>Métricas WhatsApp | CRM STARFI</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <!-- CSS Local de Bootstrap -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Tema Global STARFI -->
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #f0f2f5; margin: 0; padding: 0; overflow-x: hidden; }
        .app-container { min-height: 100vh; display: flex; flex-direction: column; overflow-x: hidden; }
        .main-content { flex: 1; display: flex; flex-direction: column; overflow-y: auto; }
        .dashboard-container { padding: 20px; width: 100%; max-width: 1200px; margin: 0 auto; }
        
        .filters-panel { background-color: #fff; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .filter-group { flex: 1; min-width: 200px; }
        .filter-group label { font-size: 0.8rem; color: #606770; margin-bottom: 5px; display: block; font-weight: 600; }
        .filter-control { width: 100%; padding: 8px 12px; border: 1px solid #ccd0d5; border-radius: 6px; font-size: 0.9rem; color: #1c1e21; outline: none; }
        .filter-control:focus { border-color: #1877f2; }
        #btnApplyFilters { min-width: 120px; }
        
        .meta-card { background-color: #fff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.1); border: 1px solid #dadde1; margin-bottom: 20px; overflow: hidden; }
        .meta-card-header { padding: 16px 20px; border-bottom: 1px solid #dadde1; background-color: #fff; }
        .meta-card-title { font-size: 1.1rem; font-weight: 600; color: #1c1e21; margin: 0; display: flex; align-items: center; gap: 8px; }
        
        .cost-row { display: flex; flex-wrap: wrap; padding: 10px; }
        .cost-box { flex: 1; min-width: 200px; padding: 15px; border-right: 1px solid #dadde1; }
        .cost-box:last-child { border-right: none; }
        .cost-label { font-size: 0.85rem; color: #606770; font-weight: 600; margin-bottom: 5px; }
        .cost-value { font-size: 1.8rem; font-weight: 700; color: #1c1e21; }
        
        @media (max-width: 768px) {
            .cost-box { border-right: none; border-bottom: 1px solid #dadde1; }
            .cost-box:last-child { border-bottom: none; }
            .dashboard-container { padding: 10px; }
        }
        
        .nav-tabs-meta { display: flex; padding: 0 20px; gap: 20px; border-bottom: 1px solid #dadde1; overflow-x: auto; }
        .nav-tab-meta { padding: 15px 0; font-size: 0.95rem; font-weight: 600; color: #606770; cursor: pointer; position: relative; white-space: nowrap; }
        .nav-tab-meta.active { color: #1877f2; }
        .nav-tab-meta.active::after { content: ''; position: absolute; bottom: -1px; left: 0; right: 0; height: 3px; background-color: #1877f2; border-radius: 3px 3px 0 0; }
        
        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; padding: 20px; }
        .metric-card { border: 1px solid #dadde1; border-radius: 8px; padding: 15px; cursor: pointer; transition: all 0.2s; }
        .metric-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .metric-label { font-size: 0.85rem; color: #1c1e21; font-weight: 600; margin-bottom: 8px; }
        .metric-value-container { display: flex; align-items: baseline; gap: 10px; }
        .metric-value { font-size: 1.6rem; font-weight: 400; color: #1c1e21; }
        .metric-trend { font-size: 0.85rem; font-weight: 500; }
        .trend-up { color: #31a24c; }
        .trend-down { color: #fa383e; }
        .trend-neutral { color: #606770; }
        
        .chart-container { padding: 20px; height: 400px; position: relative; width: 100%; }
        
        /* Modificadores estéticos (Colores de las líneas) */
        .color-sent { color: #fa383e; } /* Rojo similar a la imagen */
        .color-delivered { color: #5a2e70; } /* Morado */
        .color-read { color: #008080; } /* Verde Azulado */
        .color-replies { color: #004d40; } /* Verde oscuro */
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php renderHeader('Dashboard Facturación WhatsApp'); ?>
    <div class="app-container">
    <main class="main-content">
        <div class="dashboard-container">
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0" style="font-size: 1.5rem; font-weight: 700; color: #1c1e21;">
                    <i class="fa-brands fa-whatsapp text-success me-2"></i> Rendimiento de la API
                </h2>
                <div>
                    <?php if ($agente['rol'] === 'MASTER'): ?>
                    <button class="btn btn-success shadow-sm me-2" id="btnEmitirOrden">
                        <i class="fa-solid fa-file-invoice me-2"></i>Emitir Orden de Cobro
                    </button>
                    <button class="btn btn-primary bg-primary border-primary me-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTarifasSede">
                        <i class="fa-solid fa-file-invoice-dollar me-2"></i>Configurar Tarifas
                    </button>
                    <?php endif; ?>
                    <a href="dashboard.php" class="btn btn-outline-secondary bg-white text-dark border-secondary shadow-sm">
                        <i class="fa-solid fa-chart-line"></i> Dashboard General
                    </a>
                </div>
            </div>

            <!-- Filtros -->
            <div class="filters-panel">
                <div class="filter-group">
                    <label>Sede / Sucursal</label>
                    <select id="filterSede" class="filter-control">
                        <option value="0">Global (Todas las sedes)</option>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre_sede']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Plantilla (Opcional)</label>
                    <select id="filterPlantilla" class="filter-control">
                        <option value="">Todas las plantillas</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Fecha Desde</label>
                    <input type="date" id="filterFechaDesde" class="filter-control" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
                </div>
                <div class="filter-group">
                    <label>Fecha Hasta</label>
                    <input type="date" id="filterFechaHasta" class="filter-control" value="<?= date('Y-m-d') ?>">
                </div>
                <button id="btnApplyFilters" class="btn btn-primary px-4 fw-bold shadow-sm" style="height: 38px; background-color: #1877f2; border-color: #1877f2;">
                    <i class="fa-solid fa-sync me-2"></i>Actualizar
                </button>
            </div>

            <div id="loaderData" class="text-center py-5" style="display: none;">
                <i class="fa-solid fa-circle-notch fa-spin fa-3x text-primary mb-3"></i>
                <p class="text-muted fw-bold">Obteniendo métricas de Meta Business...</p>
            </div>

            <!-- Placeholder State -->
            <div id="placeholderData" class="text-center py-5">
                <i class="fa-solid fa-chart-bar fa-3x text-muted mb-3"></i>
                <h4 class="text-secondary fw-bold">Seleccione sus filtros</h4>
                <p class="text-muted">Por favor, elija una sede y una plantilla, luego presione "Actualizar" para cargar las métricas.</p>
            </div>

            <!-- Dashboard Content -->
            <div id="contentData" style="display: none;">
                
                <!-- Nota de ayuda -->
                <div class="mb-3 p-2 text-muted" style="font-size: 0.8rem; background-color: #fff; border-radius: 6px; border: 1px solid #dadde1;">
                    <i class="fa-solid fa-info-circle text-primary me-1"></i> Nota: Todos los datos de estadísticas son aproximados y consolidados entre la API de Meta y las métricas locales de CRM.
                </div>

                <div class="row g-3 mb-4">
                    <!-- Tarjeta 1: Todos los mensajes -->
                    <div class="col-md-4 col-lg">
                        <div class="card h-100 shadow-sm border-1" style="border-radius: 8px;">
                            <div class="card-body p-3">
                                <div class="fw-bold border-bottom pb-2 mb-2 text-dark" style="font-size: 0.9rem;">
                                    <i class="fa-solid fa-comments me-1 text-primary"></i> Todos los mensajes
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Mensajes enviados</span>
                                    <span class="fw-bold text-dark" id="metaValEnviados">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Mensajes entregados</span>
                                    <span class="fw-bold text-dark" id="metaValEntregados">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Mensajes recibidos</span>
                                    <span class="fw-bold text-dark" id="metaValRecibidos">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjeta 2: Mensajes entregados por categoría -->
                    <div class="col-md-4 col-lg">
                        <div class="card h-100 shadow-sm border-1" style="border-radius: 8px;">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2 text-dark" style="font-size: 0.9rem;">
                                    <span class="fw-bold"><i class="fa-solid fa-paper-plane me-1 text-success"></i> Entregados</span>
                                    <span class="fw-bold fs-6" id="metaTotalEntregadosCat">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Marketing</span>
                                    <span class="fw-semibold" id="metaEntMkt">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Utilidad</span>
                                    <span class="fw-semibold" id="metaEntUtil">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Autenticación</span>
                                    <span class="fw-semibold" id="metaEntAuth">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Servicio</span>
                                    <span class="fw-semibold" id="metaEntServ">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjeta 3: Mensajes gratuitos entregados -->
                    <div class="col-md-4 col-lg">
                        <div class="card h-100 shadow-sm border-1" style="border-radius: 8px;">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2 text-dark" style="font-size: 0.9rem;">
                                    <span class="fw-bold"><i class="fa-solid fa-gift me-1 text-info"></i> Gratuitos</span>
                                    <span class="fw-bold fs-6" id="metaTotalGratuitos">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Atención al cliente</span>
                                    <span class="fw-semibold" id="metaGratAtencion">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Punto de acceso</span>
                                    <span class="fw-semibold" id="metaGratAcceso">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjeta 4: Mensajes pagados entregados -->
                    <div class="col-md-4 col-lg">
                        <div class="card h-100 shadow-sm border-1" style="border-radius: 8px;">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2 text-dark" style="font-size: 0.9rem;">
                                    <span class="fw-bold"><i class="fa-solid fa-receipt me-1 text-warning"></i> Pagados</span>
                                    <span class="fw-bold fs-6" id="metaTotalPagados">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Marketing</span>
                                    <span class="fw-semibold" id="metaPagMkt">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Utilidad</span>
                                    <span class="fw-semibold" id="metaPagUtil">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Autenticación</span>
                                    <span class="fw-semibold" id="metaPagAuth">0</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Servicio</span>
                                    <span class="fw-semibold" id="metaPagServ">0</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Tarjeta 5: Cargos totales aproximados -->
                    <div class="col-md-4 col-lg">
                        <div class="card h-100 shadow-sm border-1" style="border-radius: 8px;">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-2 text-dark" style="font-size: 0.9rem;">
                                    <span class="fw-bold"><i class="fa-solid fa-dollar-sign me-1 text-primary"></i> Cargos aprox.</span>
                                    <span class="fw-bold fs-6 text-primary" id="metaCargosTotales">$0,00</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Marketing</span>
                                    <span class="fw-semibold" id="metaCargoMkt">$0,00</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Utilidad</span>
                                    <span class="fw-semibold" id="metaCargoUtil">$0,00</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Autenticación</span>
                                    <span class="fw-semibold" id="metaCargoAuth">$0,00</span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center py-1" style="font-size: 0.85rem;">
                                    <span class="text-muted">Servicio</span>
                                    <span class="fw-semibold" id="metaCargoServ">$0,00</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Caja Rendimiento -->
                <div class="meta-card">
                    <div class="meta-card-header">
                        <h3 class="meta-card-title">Rendimiento <i class="fa-solid fa-circle-info text-muted ms-1" style="font-size: 0.9rem;"></i></h3>
                    </div>
                    <div class="nav-tabs-meta">
                        <div class="nav-tab-meta active">Tendencia</div>
                        <div class="nav-tab-meta">Embudo</div>
                    </div>
                    
                    <div class="metrics-grid">
                        <div class="metric-card" style="border-bottom: 3px solid #fa383e;">
                            <div class="metric-label">Mensajes enviados <i class="fa-solid fa-circle-info text-muted ms-1"></i></div>
                            <div class="metric-value-container">
                                <div class="metric-value" id="kpiEnviados">0</div>
                                <div class="metric-trend trend-neutral" id="trendEnviados">-</div>
                            </div>
                        </div>
                        <div class="metric-card" style="border-bottom: 3px solid #5a2e70;">
                            <div class="metric-label">Mensajes entregados <i class="fa-solid fa-circle-info text-muted ms-1"></i></div>
                            <div class="metric-value-container">
                                <div class="metric-value" id="kpiEntregados">0</div>
                                <div class="metric-trend trend-neutral" id="trendEntregados">-</div>
                            </div>
                        </div>
                        <div class="metric-card" style="border-bottom: 3px solid #008080;">
                            <div class="metric-label">Mensajes leídos <i class="fa-solid fa-circle-info text-muted ms-1"></i></div>
                            <div class="metric-value-container">
                                <div class="metric-value" id="kpiLeidos">0</div>
                                <div class="metric-trend trend-neutral" id="trendLeidos">-</div>
                            </div>
                        </div>
                        <div class="metric-card" style="border-bottom: 3px solid #004d40;">
                            <div class="metric-label">Respuestas únicas <i class="fa-solid fa-circle-info text-muted ms-1"></i></div>
                            <div class="metric-value-container">
                                <div class="metric-value" id="kpiRespuestas">0</div>
                            </div>
                        </div>
                    </div>

                    <!-- Chart -->
                    <div class="chart-container">
                        <canvas id="metaChart"></canvas>
                    </div>
                </div>

                <!-- Debug -->
                <div class="accordion" id="debugAccordion">
                    <div class="accordion-item border-0 bg-transparent">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed bg-transparent shadow-none fw-bold text-muted" type="button" data-bs-toggle="collapse" data-bs-toggle="collapse" data-bs-target="#debugCollapse">
                                <i class="fa-solid fa-code me-2"></i> Ver respuesta en bruto de Meta Graph API
                            </button>
                        </h2>
                        <div id="debugCollapse" class="accordion-collapse collapse">
                            <div class="accordion-body p-0 pt-3">
                                <pre id="rawApiResponse" class="bg-dark text-light p-3 rounded" style="font-size: 0.8rem; max-height: 400px; overflow-y: auto;"></pre>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
            
        </div>
    </main>

    <!-- Modal Tarifas -->
    <div class="modal fade" id="modalTarifasSede" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-light">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-store me-2"></i>Configuración de Facturación por Sede</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Seleccione Sede a Configurar:</label>
                        <select id="selConfigSede" class="form-select">
                            <option value="">Seleccione...</option>
                            <?php foreach ($sedes as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre_sede']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="configSedePanel" style="display:none;">
                        <h6 class="border-bottom pb-2 mb-3 mt-4 text-primary fw-bold">Reglas de Negocio (Márgenes de Ganancia)</h6>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Tipo de Tarifa</label>
                                <select id="configTipoTarifa" class="form-select">
                                    <option value="PORCENTAJE">Porcentaje Sobre Costo (Ej: +10%)</option>
                                    <option value="FIJA">Tarifa Fija (Ej: $0.05 por msj)</option>
                                    <option value="PORCENTAJE_VOLUMEN">Porcentaje Dinámico (Volumen)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Valor Numérico</label>
                                <input type="number" id="configValorTarifa" class="form-control" step="0.01" value="10.00">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notas Internas de Negociación</label>
                            <textarea id="configNotas" class="form-control" rows="2" placeholder="Ej: Se acordó un 15% hasta 1000 mensajes..."></textarea>
                        </div>

                        <h6 class="border-bottom pb-2 mb-3 mt-4 text-primary fw-bold">Contacto de Notificación de Pagos</h6>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Nombre del Gerente</label>
                                <input type="text" id="configNombreGerente" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Teléfono (WhatsApp)</label>
                                <input type="text" id="configTelGerente" class="form-control" placeholder="584141234567">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email</label>
                                <input type="email" id="configEmailGerente" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Vía Preferida de Notificación</label>
                            <select id="configPrefNotif" class="form-select">
                                <option value="WHATSAPP">WhatsApp</option>
                                <option value="EMAIL">Email</option>
                                <option value="AMBOS">WhatsApp y Email</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" id="btnGuardarConfigSede" style="display:none;"><i class="fa-solid fa-save me-2"></i>Guardar Cambios</button>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script>
        const userRole = "<?= htmlspecialchars($agente['rol'] ?? 'AGENTE') ?>";
        let metaChartInstance = null;
        let templatesLoaded = false;

        $(document).ready(function() {
            $('#btnApplyFilters').click(function() { 
                if ($('#filterSede').val() == '0' || $('#filterPlantilla').val() === '') {
                    Swal.fire('Atención', 'Por favor seleccione una Sede y una Plantilla antes de actualizar.', 'warning');
                    return;
                }
                loadAnalytics(); 
            });
            fetchTemplatesOnLoad();

            // Lógica Modal Tarifas
            $('#selConfigSede').change(function() {
                let id_sede = $(this).val();
                if (!id_sede) {
                    $('#configSedePanel').hide();
                    $('#btnGuardarConfigSede').hide();
                    return;
                }
                
                // Mostrar spinner
                Swal.fire({ title: 'Cargando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                
                $.post('back_waba_billing.php', { action: 'get_sede_config', id_sede: id_sede }, function(res) {
                    Swal.close();
                    if(res.status === 'success') {
                        $('#configTipoTarifa').val(res.tarifa.tipo_tarifa);
                        $('#configValorTarifa').val(res.tarifa.valor);
                        $('#configNotas').val(res.tarifa.notas_negociacion);
                        $('#configNombreGerente').val(res.sede.gerente_nombre || '');
                        $('#configTelGerente').val(res.sede.gerente_telefono || '');
                        $('#configEmailGerente').val(res.sede.gerente_email || '');
                        $('#configPrefNotif').val(res.sede.pref_not_cobro || 'WHATSAPP');
                        
                        $('#configSedePanel').fadeIn();
                        $('#btnGuardarConfigSede').show();
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }, 'json');
            });

            $('#btnGuardarConfigSede').click(function() {
                let id_sede = $('#selConfigSede').val();
                let data = {
                    action: 'save_sede_config',
                    id_sede: id_sede,
                    tipo_tarifa: $('#configTipoTarifa').val(),
                    valor: $('#configValorTarifa').val(),
                    notas: $('#configNotas').val(),
                    gerente_nombre: $('#configNombreGerente').val(),
                    gerente_telefono: $('#configTelGerente').val(),
                    gerente_email: $('#configEmailGerente').val(),
                    pref_not_cobro: $('#configPrefNotif').val()
                };

                Swal.fire({ title: 'Guardando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                
                $.post('back_waba_billing.php', data, function(res) {
                    if (res.status === 'success') {
                        Swal.fire('Guardado', res.message, 'success');
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }, 'json');
            });

            // Lógica Emitir Orden
            $('#btnEmitirOrden').click(function() {
                let id_sede = $('#filterSede').val();
                let fecha_hasta_filtro = $('#filterFechaHasta').val();
                
                if (!id_sede || id_sede == '0') {
                    Swal.fire('Atención', 'Por favor seleccione una sede específica en los filtros antes de emitir una orden.', 'warning');
                    return;
                }

                Swal.fire({ title: 'Analizando deudas...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });

                $.post('back_waba_billing.php', { action: 'check_last_order', id_sede: id_sede, fecha_hasta: fecha_hasta_filtro }, function(res) {
                    Swal.close();
                    if (res.status === 'success') {
                        let msg = `Se generará una orden desde el <b>${res.fecha_desde}</b> hasta el <b>${res.fecha_hasta}</b>.`;
                        let btnText = 'Sí, Generar Orden';
                        let actionToCall = 'generate_order';
                        
                        if (res.tiene_pendiente) {
                            msg = `Existe una orden PENDIENTE del periodo anterior (ID #${res.pendiente_id}).<br><br>¿Deseas <b>fusionar</b> esa deuda en esta nueva orden global hasta el <b>${res.fecha_hasta}</b>?`;
                            btnText = 'Sí, Fusionar y Generar';
                        }

                        Swal.fire({
                            title: 'Emisión de Orden de Cobro',
                            html: msg,
                            icon: 'question',
                            showCancelButton: true,
                            confirmButtonText: btnText,
                            cancelButtonText: 'Cancelar'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                Swal.fire({ title: 'Procesando...', text: 'Consultando a Meta y emitiendo factura', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                                
                                $.post('back_waba_billing.php', { 
                                    action: actionToCall, 
                                    id_sede: id_sede, 
                                    fecha_desde: res.fecha_desde, 
                                    fecha_hasta: res.fecha_hasta,
                                    orden_pendiente_id: res.pendiente_id
                                }, function(orderRes) {
                                    if (orderRes.status === 'success') {
                                        Swal.fire('Orden Creada', `Monto Total: ${orderRes.monto_total} USD<br>La orden #${orderRes.id_orden} ha sido generada exitosamente.`, 'success');
                                    } else {
                                        Swal.fire('Error', orderRes.message, 'error');
                                    }
                                }, 'json');
                            }
                        });
                    } else {
                        Swal.fire('Error', res.message, 'error');
                    }
                }, 'json');
            });

            // Recargar plantillas al cambiar de sede
            $('#filterSede').change(function() {
                templatesLoaded = false;
                $('#filterPlantilla').html('<option value="">Todas las plantillas</option>');
                fetchTemplatesOnLoad();
            });
        });

        function fetchTemplatesOnLoad() {
            let id_sede = $('#filterSede').val();
            let desde = $('#filterFechaDesde').val();
            let hasta = $('#filterFechaHasta').val();

            $.ajax({
                url: 'back_whatsapp_analytics.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'get_analytics', id_sede: id_sede, fecha_desde: desde, fecha_hasta: hasta },
                success: function(res) {
                    if(res.status === 'success') {
                        if (!templatesLoaded && res.data.message_templates && res.data.message_templates.data) {
                            let select = $('#filterPlantilla');
                            res.data.message_templates.data.forEach(tpl => {
                                select.append(`<option value="${tpl.id}">${tpl.name} (${tpl.language})</option>`);
                            });
                            templatesLoaded = true;
                        }
                    }
                }
            });
        }

        function loadAnalytics() {
            $('#placeholderData').hide();
            let id_sede = $('#filterSede').val();
            let desde = $('#filterFechaDesde').val();
            let hasta = $('#filterFechaHasta').val();
            let id_plantilla = $('#filterPlantilla').val();

            $('#contentData').hide();
            $('#loaderData').show();

            $.ajax({
                url: 'back_whatsapp_analytics.php',
                type: 'POST',
                dataType: 'json',
                data: { action: 'get_analytics', id_sede: id_sede, fecha_desde: desde, fecha_hasta: hasta, id_plantilla: id_plantilla },
                success: function(res) {
                    $('#loaderData').hide();
                    $('#rawApiResponse').text(JSON.stringify(res, null, 2));

                    if(res.status === 'success') {
                        $('#contentData').fadeIn();
                        
                        // Poblar Dropdown de Plantillas si no se ha hecho
                        if (!templatesLoaded && res.data.message_templates && res.data.message_templates.data) {
                            let select = $('#filterPlantilla');
                            res.data.message_templates.data.forEach(tpl => {
                                select.append(`<option value="${tpl.id}">${tpl.name} (${tpl.language})</option>`);
                            });
                            templatesLoaded = true;
                        }

                        procesarYGraficar(res.data, desde, hasta, id_plantilla, res.tarifa_config, res.local_audit);
                    } else {
                        Swal.fire('Error', res.message || 'Error desconocido', 'error');
                        $('#contentData').show();
                    }
                },
                error: function() {
                    $('#loaderData').hide();
                    Swal.fire('Error', 'Fallo de conexión al consultar Meta', 'error');
                }
            });
        }

        function procesarYGraficar(data, desde, hasta, id_plantilla, tarifaConfig, localAudit) {
            let totalCost = 0;
            let mktCost = 0, utilCost = 0, authCost = 0, servCost = 0;
            let mktCount = 0, utilCount = 0, authCount = 0, servCount = 0;
            
            // Analizar Costos de Meta (pricing_analytics y conversation_analytics)
            if(data.pricing_analytics && data.pricing_analytics.data) {
                let pricing = data.pricing_analytics.data[0]?.data_points || [];
                pricing.forEach(dp => {
                    if (dp.cost) {
                        let c = parseFloat(dp.cost);
                        totalCost += c;
                        let cat = (dp.category || dp.conversation_category || '').toUpperCase();
                        if (cat === 'MARKETING') mktCost += c;
                        else if (cat === 'UTILITY') utilCost += c;
                        else if (cat === 'AUTHENTICATION') authCost += c;
                        else if (cat === 'SERVICE') servCost += c;
                        else mktCost += c; // fallback
                    }
                });
            }

            if(data.conversation_analytics && data.conversation_analytics.data) {
                let convs = data.conversation_analytics.data[0]?.data_points || [];
                convs.forEach(dp => {
                    let cat = (dp.conversation_category || '').toUpperCase();
                    let cnt = parseInt(dp.conversation || 0);
                    if (cat === 'MARKETING') mktCount += cnt;
                    else if (cat === 'UTILITY') utilCount += cnt;
                    else if (cat === 'AUTHENTICATION') authCount += cnt;
                    else if (cat === 'SERVICE') servCount += cnt;
                });
            }

            // Analizar Mensajes (analytics o template_analytics)
            let enviados = 0, entregados = 0, leidos = 0, respuestas = 0;
            let labels = [];
            let dsEnviados = [], dsEntregados = [], dsLeidos = [], dsRespuestas = [];

            let dataSource = null;
            if (id_plantilla && data.template_analytics && data.template_analytics.data) {
                dataSource = data.template_analytics.data[0]?.data_points || [];
            } else if (data.analytics && data.analytics.data) {
                dataSource = data.analytics.data[0]?.data_points || [];
            }

            if(dataSource && dataSource.length > 0) {
                let msgs = dataSource;
                msgs.sort((a,b) => a.start - b.start);
                
                msgs.forEach(dp => {
                    let d = new Date(dp.start * 1000);
                    labels.push(d.getDate() + ' de ' + d.toLocaleString('es-ES', { month: 'short' }));
                    
                    let env = dp.sent || 0;
                    let ent = dp.delivered || 0;
                    let lei = dp.read || 0;
                    let resps = dp.replies || 0;

                    enviados += parseInt(env);
                    entregados += parseInt(ent);
                    leidos += parseInt(lei);
                    respuestas += parseInt(resps);

                    dsEnviados.push(env);
                    dsEntregados.push(ent);
                    dsLeidos.push(lei);
                    dsRespuestas.push(resps);
                });
            } else {
                labels = [desde, hasta];
                dsEnviados = [0,0]; dsEntregados = [0,0]; dsLeidos = [0,0]; dsRespuestas = [0,0];
            }

            // Usar auditoría local si Meta devuelve ceros por falta de permisos o desfase
            if (localAudit) {
                if (enviados === 0 && localAudit.enviados > 0) enviados = localAudit.enviados;
                if (entregados === 0 && localAudit.entregados > 0) entregados = localAudit.entregados;
                if (leidos === 0 && localAudit.leidos > 0) leidos = localAudit.leidos;
                
                if (mktCount === 0) mktCount = localAudit.por_categoria?.MARKETING || 0;
                if (utilCount === 0) utilCount = localAudit.por_categoria?.UTILITY || 0;
                if (authCount === 0) authCount = localAudit.por_categoria?.AUTHENTICATION || 0;
                if (servCount === 0) servCount = localAudit.por_categoria?.SERVICE || 0;
            }

            // Aplicar Modelo de Negocio CRM (Margen)
            let valorTarifa = parseFloat(tarifaConfig ? tarifaConfig.valor : 10.00);
            let tipoTarifa = tarifaConfig ? tarifaConfig.tipo_tarifa : 'PORCENTAJE';
            
            let factorMarkup = 1.10;
            if (tipoTarifa === 'PORCENTAJE') {
                factorMarkup = 1 + (valorTarifa / 100);
            }

            let costoFinalFacturado = totalCost * factorMarkup;
            let mktCargoFinal = mktCost * factorMarkup;
            let utilCargoFinal = utilCost * factorMarkup;
            let authCargoFinal = authCost * factorMarkup;
            let servCargoFinal = servCost * factorMarkup;

            // Si Meta no reportó desglose exacto de costos pero hay entregados, prorratear el costo total
            let totalEntregadosCat = mktCount + utilCount + authCount + servCount;
            if (totalEntregadosCat === 0 && entregados > 0) {
                mktCount = entregados;
                totalEntregadosCat = entregados;
            }
            if (mktCost === 0 && utilCost === 0 && authCost === 0 && servCost === 0 && totalCost > 0) {
                mktCargoFinal = costoFinalFacturado;
            }

            let recibidos = localAudit ? localAudit.recibidos : 0;
            let gratuitos = localAudit ? localAudit.gratuitos : 0;
            let pagados = localAudit ? localAudit.pagados : (totalEntregadosCat - gratuitos);
            if (pagados < 0) pagados = totalEntregadosCat;

            // Poblado de Tarjeta 1: Todos los mensajes
            $('#metaValEnviados').text(enviados);
            $('#metaValEntregados').text(entregados);
            $('#metaValRecibidos').text(recibidos);

            // Poblado de Tarjeta 2: Mensajes entregados por categoría
            $('#metaTotalEntregadosCat').text(totalEntregadosCat);
            $('#metaEntMkt').text(mktCount);
            $('#metaEntUtil').text(utilCount);
            $('#metaEntAuth').text(authCount);
            $('#metaEntServ').text(servCount);

            // Poblado de Tarjeta 3: Mensajes gratuitos
            $('#metaTotalGratuitos').text(gratuitos);
            $('#metaGratAtencion').text(gratuitos);
            $('#metaGratAcceso').text(0);

            // Poblado de Tarjeta 4: Mensajes pagados
            $('#metaTotalPagados').text(pagados);
            $('#metaPagMkt').text(mktCount);
            $('#metaPagUtil').text(utilCount);
            $('#metaPagAuth').text(authCount);
            $('#metaPagServ').text(servCount);

            // Poblado de Tarjeta 5: Cargos totales aproximados
            $('#metaCargosTotales').text('$' + costoFinalFacturado.toFixed(2).replace('.', ','));
            $('#metaCargoMkt').text('$' + mktCargoFinal.toFixed(2).replace('.', ','));
            $('#metaCargoUtil').text('$' + utilCargoFinal.toFixed(2).replace('.', ','));
            $('#metaCargoAuth').text('$' + authCargoFinal.toFixed(2).replace('.', ','));
            $('#metaCargoServ').text('$' + servCargoFinal.toFixed(2).replace('.', ','));

            // Actualizar KPIs de la sección Rendimiento
            $('#kpiEnviados').text(enviados);
            $('#kpiEntregados').text(entregados);
            $('#kpiLeidos').text(leidos);
            $('#kpiRespuestas').text(respuestas);

            if (enviados > 0) {
                let pctEnt = ((entregados/enviados)*100).toFixed(1);
                $('#trendEntregados').html(`<i class="fa-solid fa-arrow-right"></i> ${pctEnt}%`).removeClass('trend-neutral').addClass('trend-up');
            }
            if (entregados > 0) {
                let pctLei = ((leidos/entregados)*100).toFixed(1);
                $('#trendLeidos').html(`(${pctLei}%)`).addClass('trend-neutral');
            }

            // Destruir Chart previo
            if(metaChartInstance) metaChartInstance.destroy();

            // Dibujar Chart.js
            let ctx = document.getElementById('metaChart').getContext('2d');
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
                        legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 10, font: { size: 11, family: 'Inter' } } }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#f0f2f5' }, ticks: { stepSize: 1, color: '#606770' }, border: { display: false } },
                        x: { grid: { display: false }, ticks: { color: '#606770' }, border: { display: true, color: '#dadde1' } }
                    },
                    interaction: { mode: 'index', intersect: false }
                }
            });
        }
    </script>
    </div>
</body>
</html>
