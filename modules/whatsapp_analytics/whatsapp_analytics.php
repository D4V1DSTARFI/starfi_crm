<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('whatsapp_analytics');

$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
$rol_agente = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
$es_master = (intval($agente['id'] ?? 0) === 1 || $rol_agente === 'MASTER');
$id_sede_agente = intval($agente['id_sede'] ?? 0);

$con = getDbConnection();
$sedes = [];
if ($con) {
    if ($es_master) {
        $stmt_sedes = $con->prepare("SELECT id, nombre_sede FROM sedes WHERE estado = 'ACTIVO' ORDER BY nombre_sede ASC");
    } else {
        $stmt_sedes = $con->prepare("SELECT id, nombre_sede FROM sedes WHERE id = ? LIMIT 1");
        if ($stmt_sedes) {
            $stmt_sedes->bind_param("i", $id_sede_agente);
        }
    }
    if ($stmt_sedes) {
        $stmt_sedes->execute();
        $res_sedes = $stmt_sedes->get_result();
        while ($res_sedes && ($row = $res_sedes->fetch_assoc())) {
            $sedes[] = $row;
        }
        $stmt_sedes->close();
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
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        .waba-analytics-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 22px;
            background-color: var(--bg-main);
        }
        .dashboard-container { width: 100%; max-width: 1260px; margin: 0 auto; }

        .filters-panel { background-color: #fff; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.08); display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap; }
        .filter-group { flex: 1; min-width: 200px; }
        .filter-group label { font-size: 0.8rem; color: #606770; margin-bottom: 5px; display: block; font-weight: 600; }
        .filter-control { width: 100%; padding: 8px 12px; border: 1px solid #ccd0d5; border-radius: 6px; font-size: 0.9rem; color: #1c1e21; outline: none; }
        .filter-control:focus { border-color: #1877f2; }
        #btnApplyFilters { min-width: 130px; }

        .meta-card { background-color: #fff; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.08); border: 1px solid #dadde1; margin-bottom: 20px; overflow: hidden; }
        .meta-card-header { padding: 16px 20px; border-bottom: 1px solid #dadde1; background-color: #fff; }
        .meta-card-title { font-size: 1.1rem; font-weight: 600; color: #1c1e21; margin: 0; display: flex; align-items: center; gap: 8px; }

        .nav-tabs-meta { display: flex; padding: 0 20px; gap: 20px; border-bottom: 1px solid #dadde1; overflow-x: auto; }
        .nav-tab-meta { padding: 15px 0; font-size: 0.95rem; font-weight: 600; color: #606770; cursor: pointer; position: relative; white-space: nowrap; }
        .nav-tab-meta.active { color: #1877f2; }
        .nav-tab-meta.active::after { content: ''; position: absolute; bottom: -1px; left: 0; right: 0; height: 3px; background-color: #1877f2; border-radius: 3px 3px 0 0; }

        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; padding: 20px; }
        .metric-card { border: 1px solid #dadde1; border-radius: 8px; padding: 15px; transition: all 0.2s; }
        .metric-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .metric-label { font-size: 0.85rem; color: #1c1e21; font-weight: 600; margin-bottom: 8px; }
        .metric-value-container { display: flex; align-items: baseline; gap: 10px; }
        .metric-value { font-size: 1.6rem; font-weight: 600; color: #1c1e21; }
        .metric-trend { font-size: 0.85rem; font-weight: 500; }
        .trend-up { color: #31a24c; }
        .trend-down { color: #fa383e; }
        .trend-neutral { color: #606770; }

        .chart-container { padding: 20px; height: 400px; position: relative; width: 100%; }
    </style>
</head>
<body>
    <?php renderHeader('Dashboard Facturación WhatsApp'); ?>
    <div class="app-container" id="whatsappAnalyticsRoot" data-es-master="<?= $es_master ? '1' : '0' ?>" data-id-sede="<?= $id_sede_agente ?>">
        <?php renderSidebar('whatsapp_analytics'); ?>

        <main class="main-content">
            <div class="waba-analytics-scroll">
                <div class="dashboard-container">

                    <!-- Cabecera y Navegación Directa -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                        <h2 class="mb-0" style="font-size: 1.5rem; font-weight: 700; color: #1c1e21;">
                            <i class="fa-brands fa-whatsapp text-success me-2"></i> Rendimiento y Facturación WABA
                        </h2>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="../waba_ordenes/waba_ordenes.php" class="btn btn-dark shadow-sm">
                                <i class="fa-solid fa-file-invoice-dollar me-2"></i>Órdenes de Cobro
                            </a>
                            <?php if ($es_master): ?>
                            <button type="button" class="btn btn-success shadow-sm" id="btnEmitirOrden">
                                <i class="fa-solid fa-file-invoice me-2"></i>Emitir Orden de Cobro
                            </button>
                            <button type="button" class="btn btn-warning shadow-sm text-dark" data-bs-toggle="modal" data-bs-target="#modalEmisionMasiva">
                                <i class="fa-solid fa-layer-group me-2"></i>Emisión Masiva
                            </button>
                            <button type="button" class="btn btn-info shadow-sm text-white" data-bs-toggle="modal" data-bs-target="#modalCronCliInfo" title="Instrucciones CLI para Auto-Facturación">
                                <i class="fa-solid fa-terminal me-2"></i>Auto-Facturación CLI
                            </button>
                            <button type="button" class="btn btn-primary bg-primary border-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#modalTarifasSede">
                                <i class="fa-solid fa-gear me-2"></i>Configurar Tarifas
                            </button>
                            <?php endif; ?>
                            <a href="../dashboard/dashboard.php" class="btn btn-outline-secondary bg-white text-dark border-secondary shadow-sm">
                                <i class="fa-solid fa-chart-line me-1"></i> Dashboard General
                            </a>
                        </div>
                    </div>

                    <!-- Filtros -->
                    <form id="formFiltrosWhatsappAnalytics" class="filters-panel" autocomplete="off">
                        <div class="filter-group">
                            <label for="filterSede">Sede / Sucursal</label>
                            <select id="filterSede" class="filter-control" <?= !$es_master ? 'disabled' : '' ?>>
                                <?php if ($es_master): ?>
                                    <option value="0">Global (Todas las sedes)</option>
                                <?php endif; ?>
                                <?php foreach ($sedes as $s): ?>
                                    <option value="<?= intval($s['id']) ?>" <?= (!$es_master && intval($s['id']) === $id_sede_agente) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$s['nombre_sede'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterPlantilla">Plantilla (Opcional)</label>
                            <select id="filterPlantilla" class="filter-control">
                                <option value="">Todas las plantillas (Consolidado)</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label for="filterFechaDesde">Fecha Desde</label>
                            <input type="date" id="filterFechaDesde" class="filter-control" value="<?= htmlspecialchars(date('Y-m-d', strtotime('-7 days')), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="filter-group">
                            <label for="filterFechaHasta">Fecha Hasta</label>
                            <input type="date" id="filterFechaHasta" class="filter-control" value="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <button type="submit" id="btnApplyFilters" class="btn btn-primary px-4 fw-bold shadow-sm" style="height: 38px; background-color: #1877f2; border-color: #1877f2;">
                            <i class="fa-solid fa-sync me-2"></i>Actualizar
                        </button>
                    </form>

                    <div id="loaderData" class="text-center py-5" style="display: none;">
                        <i class="fa-solid fa-circle-notch fa-spin fa-3x text-primary mb-3"></i>
                        <p class="text-muted fw-bold">Obteniendo métricas consolidadas de Meta Business y CRM...</p>
                    </div>

                    <div id="placeholderData" class="text-center py-5" style="display: none;">
                        <i class="fa-solid fa-chart-bar fa-3x text-muted mb-3"></i>
                        <h4 class="text-secondary fw-bold">Seleccione sus filtros</h4>
                        <p class="text-muted">Elija un rango de fechas o plantilla y presione "Actualizar" para consultar las métricas.</p>
                    </div>

                    <div id="contentData" style="display: none;">
                        <div class="mb-3 p-2 text-muted" style="font-size: 0.8rem; background-color: #fff; border-radius: 6px; border: 1px solid #dadde1;">
                            <i class="fa-solid fa-info-circle text-primary me-1"></i> Nota: Todos los datos de estadísticas son aproximados y consolidados entre la API de Meta, las campañas de difusión y las métricas locales de CRM.
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
                            </div>

                            <div class="metrics-grid">
                                <div class="metric-card" style="border-bottom: 3px solid #fa383e;">
                                    <div class="metric-label">Mensajes enviados</div>
                                    <div class="metric-value-container">
                                        <div class="metric-value" id="kpiEnviados">0</div>
                                        <div class="metric-trend trend-neutral" id="trendEnviados">-</div>
                                    </div>
                                </div>
                                <div class="metric-card" style="border-bottom: 3px solid #5a2e70;">
                                    <div class="metric-label">Mensajes entregados</div>
                                    <div class="metric-value-container">
                                        <div class="metric-value" id="kpiEntregados">0</div>
                                        <div class="metric-trend trend-neutral" id="trendEntregados">-</div>
                                    </div>
                                </div>
                                <div class="metric-card" style="border-bottom: 3px solid #008080;">
                                    <div class="metric-label">Mensajes leídos</div>
                                    <div class="metric-value-container">
                                        <div class="metric-value" id="kpiLeidos">0</div>
                                        <div class="metric-trend trend-neutral" id="trendLeidos">-</div>
                                    </div>
                                </div>
                                <div class="metric-card" style="border-bottom: 3px solid #004d40;">
                                    <div class="metric-label">Respuestas únicas</div>
                                    <div class="metric-value-container">
                                        <div class="metric-value" id="kpiRespuestas">0</div>
                                    </div>
                                </div>
                            </div>

                            <div class="chart-container">
                                <canvas id="metaChart"></canvas>
                            </div>
                        </div>

                        <?php if ($es_master): ?>
                        <div class="accordion" id="debugAccordion">
                            <div class="accordion-item border-0 bg-transparent">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed bg-transparent shadow-none fw-bold text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#debugCollapse">
                                        <i class="fa-solid fa-code me-2"></i> Ver respuesta en bruto de Meta Graph API (Exclusivo MASTER)
                                    </button>
                                </h2>
                                <div id="debugCollapse" class="accordion-collapse collapse">
                                    <div class="accordion-body p-0 pt-3">
                                        <pre id="rawApiResponse" class="bg-dark text-light p-3 rounded" style="font-size: 0.8rem; max-height: 400px; overflow-y: auto;"></pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php
    define('STARFI_MODALS_ALLOWED', true);
    require __DIR__ . '/modals_whatsapp_analytics.php';
    ?>

    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="../../assets/js/chart.umd.min.js"></script>
    <script src="funciones_whatsapp_analytics.js"></script>
</body>
</html>
