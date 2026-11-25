<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('dashboard');

$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
$rol_agente = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
$es_master = ($rol_agente === 'MASTER' || intval($agente['id'] ?? 0) === 1);
$id_sede_agente = intval($agente['id_sede'] ?? 0);

$con = getDbConnection();
$sedes = [];
if ($con) {
    if ($es_master) {
        $stmt_sedes = $con->prepare("SELECT id, nombre_sede FROM sedes WHERE estado = 'ACTIVO' ORDER BY nombre_sede ASC");
    } else {
        $stmt_sedes = $con->prepare("SELECT id, nombre_sede FROM sedes WHERE id = ? AND estado = 'ACTIVO' LIMIT 1");
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

$selected_sede_get = isset($_GET['sede']) ? trim((string)$_GET['sede']) : 'all';
$can_waba_analytics = hasPermission('whatsapp_analytics');
$can_waba_ordenes   = hasPermission('waba_ordenes');
$can_buzon_correos  = hasPermission('buzon_correos');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard &amp; KPIs | CRM STARFI</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        .dashboard-container {
            flex: 1;
            padding: 30px;
            background-color: var(--bg-main);
            overflow-y: auto;
        }

        .dashboard-header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 28px;
        }

        .kpi-card {
            background-color: var(--bg-surface);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .kpi-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .kpi-title {
            color: var(--text-muted);
            font-size: 0.85rem;
            text-transform: uppercase;
            font-weight: 600;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .kpi-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .kpi-icon.primary { background-color: rgba(232, 91, 20, 0.1); color: var(--primary); }
        .kpi-icon.success { background-color: rgba(16, 185, 129, 0.1); color: var(--sla-green); }
        .kpi-icon.dark { background-color: rgba(55, 65, 74, 0.1); color: var(--starfi-dark); }

        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-main);
            font-family: var(--font-heading);
            margin-bottom: 5px;
        }

        .kpi-trend {
            font-size: 0.8rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .kpi-trend.up { color: var(--sla-green); }
        .kpi-trend.down { color: var(--starfi-danger); }

        .chart-card {
            background-color: var(--bg-surface);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
            margin-bottom: 25px;
        }

        .filters-panel {
            background-color: var(--bg-surface);
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 25px;
            border: 1px solid var(--border-color);
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 180px;
        }

        .filter-group label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 5px;
            display: block;
            font-weight: 600;
        }

        .filter-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
            background-color: #F8FAFC;
            color: var(--text-main);
        }

        .filter-control:focus {
            outline: none;
            border-color: var(--primary);
        }
    </style>
</head>
<body>
    <?php renderHeader('Dashboard y Reportes'); ?>
    <div class="app-container" id="dashboardRoot" data-es-master="<?= $es_master ? '1' : '0' ?>">
        <?php renderSidebar('dashboard'); ?>

        <main class="main-content">
            <div class="dashboard-container">
                <div class="dashboard-header">
                    <div>
                        <h2 class="brand-font mb-1" style="font-weight: 600;">Panel de Supervisión</h2>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">Métricas ejecutivas de rendimiento comercial y atención al cliente</p>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <?php if ($can_waba_analytics): ?>
                            <a href="../whatsapp_analytics/whatsapp_analytics.php" class="btn btn-outline-success btn-sm d-flex align-items-center gap-2 px-3 py-2 fw-semibold">
                                <i class="fa-brands fa-whatsapp"></i> Estadísticas WABA
                            </a>
                        <?php endif; ?>
                        <?php if ($can_waba_ordenes): ?>
                            <a href="../waba_ordenes/waba_ordenes.php" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-2 px-3 py-2 fw-semibold">
                                <i class="fa-solid fa-file-invoice-dollar"></i> Órdenes WABA
                            </a>
                        <?php endif; ?>
                        <?php if ($can_buzon_correos && $es_master): ?>
                            <a href="../buzon_correos/buzon_correos.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-2 px-3 py-2 fw-semibold">
                                <i class="fa-solid fa-envelope-open-text"></i> Buzón SMTP
                            </a>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-dark btn-sm d-flex align-items-center gap-2 px-3 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#modalDetalleExportDashboard">
                            <i class="fa-solid fa-sliders"></i> Opciones de Reporte
                        </button>
                        <button type="button" id="btnExport" class="btn btn-starfi-dark d-flex align-items-center gap-2">
                            <i class="fa-solid fa-file-excel"></i> Exportar Reporte (.xlsx)
                        </button>
                    </div>
                </div>

                <!-- Filtros de Auditoría -->
                <form id="formFiltrosDashboard" class="filters-panel" autocomplete="off">
                    <div class="filter-group">
                        <label for="filterSede">Sede / Sucursal</label>
                        <select id="filterSede" class="filter-control" <?= !$es_master ? 'disabled' : '' ?>>
                            <?php if ($es_master): ?>
                                <option value="all" <?= $selected_sede_get === 'all' ? 'selected' : '' ?>>Todas las sedes</option>
                            <?php endif; ?>
                            <?php foreach ($sedes as $s): ?>
                                <?php
                                $s_id = intval($s['id']);
                                $isSelected = !$es_master
                                    ? ($s_id === $id_sede_agente)
                                    : ((string)$s_id === $selected_sede_get);
                                ?>
                                <option value="<?= $s_id ?>" <?= $isSelected ? 'selected' : '' ?>><?= htmlspecialchars((string)$s['nombre_sede'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
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
                    <button type="submit" id="btnApplyFilters" class="btn btn-starfi-primary" style="height: 38px;">
                        <i class="fa-solid fa-filter me-1"></i> Aplicar Filtros
                    </button>
                </form>

                <!-- KPIs Row -->
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-card-header">
                                <h3 class="kpi-title">Volumen de Chats</h3>
                                <div class="kpi-icon primary"><i class="fa-solid fa-comments"></i></div>
                            </div>
                            <div id="kpiTotalChats" class="kpi-value">...</div>
                            <div class="kpi-trend up">
                                <i class="fa-solid fa-arrow-trend-up"></i> Conversaciones en rango
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-card-header">
                                <h3 class="kpi-title">Ciclo de Ventas Promedio</h3>
                                <div class="kpi-icon dark"><i class="fa-solid fa-stopwatch"></i></div>
                            </div>
                            <div id="kpiAvgRes" class="kpi-value">...</div>
                            <div class="kpi-trend down">
                                <i class="fa-solid fa-clock"></i> Tiempo medio de cierre
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-card-header">
                                <h3 class="kpi-title">Tasa de Conversión</h3>
                                <div class="kpi-icon success"><i class="fa-solid fa-bullseye"></i></div>
                            </div>
                            <div id="kpiConversion" class="kpi-value">...</div>
                            <div class="kpi-trend up">
                                <i class="fa-solid fa-arrow-trend-up"></i> Basado en chats cerrados
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-card-header">
                                <h3 class="kpi-title">Costo Adquisición (CAC)</h3>
                                <div class="kpi-icon text-white" style="background-color: #E85B14;"><i class="fa-solid fa-sack-dollar"></i></div>
                            </div>
                            <div id="kpiCAC" class="kpi-value">...</div>
                            <div class="kpi-trend text-muted">
                                <i class="fa-solid fa-info-circle"></i> Costo WABA / Ventas
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row g-4 mb-4">
                    <div class="col-md-5">
                        <div class="chart-card h-100">
                            <h5 class="brand-font fw-bold text-starfi-dark mb-1">Motivos de Cierre (Embudo)</h5>
                            <p class="text-muted" style="font-size: 0.8rem;">Distribución de resultados comerciales</p>
                            <div class="chart-container" style="position: relative; height:250px; width:100%; margin-top:20px;">
                                <canvas id="motivosChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="chart-card h-100">
                            <h5 class="brand-font fw-bold text-starfi-dark mb-1">Volumen de Chats</h5>
                            <p class="text-muted" style="font-size: 0.8rem;">Distribución de conversaciones por día</p>
                            <div class="chart-container" style="position: relative; height:250px; width:100%; margin-top:20px;">
                                <canvas id="chatsChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="chart-card h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h5 class="brand-font fw-bold text-starfi-dark mb-1">Calidad (Scores)</h5>
                                <p class="text-muted" style="font-size: 0.8rem;">Evaluaciones del servicio</p>
                            </div>

                            <div class="mt-2 text-center pb-2 border-bottom">
                                <small class="text-muted fw-bold d-block mb-1">Lead Scoring (Operador)</small>
                                <h2 class="display-5 fw-bold text-warning mb-0" id="kpiLeadScore">...</h2>
                                <div class="text-warning fs-5 mb-1" id="leadStarsContainer">
                                    <i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i>
                                </div>
                            </div>

                            <div class="mt-2 text-center pt-2">
                                <small class="text-muted fw-bold d-block mb-1">CSAT (Cliente)</small>
                                <h2 class="display-5 fw-bold text-success mb-0" id="kpiCsatScore">...</h2>
                                <div class="text-success fs-5 mb-1" id="csatStarsContainer">
                                    <i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rendimiento por Operador -->
                <div class="row g-4">
                    <div class="col-12">
                        <div class="chart-card mb-0">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div>
                                    <h5 class="brand-font fw-bold text-starfi-dark mb-1">Rendimiento por Agente / Operador</h5>
                                    <p class="text-muted mb-0" style="font-size: 0.8rem;">Top operadores con mayor volumen de conversaciones atendidas en el periodo</p>
                                </div>
                            </div>
                            <div id="operatorPerformanceContainer">
                                <p class="text-muted text-center my-3">Cargando métricas de operadores...</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <?php
    define('STARFI_MODALS_ALLOWED', true);
    require __DIR__ . '/modals_dashboard.php';
    ?>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="../../assets/js/chart.umd.min.js"></script>
    <script src="../../assets/js/xlsx.full.min.js"></script>
    <script src="funciones_dashboard.js"></script>
</body>
</html>
