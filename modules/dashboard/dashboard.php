<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';

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
    <title>Dashboard & KPIs | CRM STARFI</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <!-- CSS Local de Bootstrap -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Iconos de Bootstrap (Local) -->
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <!-- Tema Global STARFI -->
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .kpi-card {
            background-color: var(--bg-surface);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
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
            gap: 15px;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
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

        .mock-chart {
            height: 250px;
            background: linear-gradient(to top, rgba(232, 91, 20, 0.1) 0%, transparent 100%);
            border-bottom: 2px solid var(--primary);
            position: relative;
            margin-top: 20px;
            display: flex;
            align-items: flex-end;
            justify-content: space-around;
            padding: 0 20px;
        }

        .bar {
            width: 40px;
            background-color: var(--primary);
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
            position: relative;
        }

        .bar::after {
            content: attr(data-val);
            position: absolute;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
        }
    </style>
</head>
<body>
    <?php renderHeader('Dashboard y Reportes'); ?>
    <div class="app-container">

    <!-- Sidebar Navigation -->

    <!-- Main Content -->
    <main class="main-content">
        <div class="dashboard-container">
            
            <div class="dashboard-header">
                <div>
                    <h2 class="brand-font mb-1" style="font-weight: 600;">Panel de Supervisión</h2>
                    <p class="text-muted" style="font-size: 0.9rem;">Métricas de rendimiento de atención al cliente</p>
                </div>
                <button id="btnExport" class="btn btn-starfi-dark d-flex align-items-center gap-2">
                    <i class="fa-solid fa-download"></i> Exportar Reporte
                </button>
            </div>

            <!-- Filtros de Auditoría -->
            <?php
            $selected_sede_get = $_GET['sede'] ?? 'all';
            ?>
            <div class="filters-panel">
                <div class="filter-group">
                    <label>Sede / Sucursal</label>
                    <select id="filterSede" class="filter-control">
                        <option value="all" <?= $selected_sede_get === 'all' ? 'selected' : '' ?>>Todas las sedes</option>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= (string)$s['id'] === (string)$selected_sede_get ? 'selected' : '' ?>><?= htmlspecialchars($s['nombre_sede']) ?></option>
                        <?php endforeach; ?>
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
                <button id="btnApplyFilters" class="btn btn-starfi-primary" style="height: 38px;">Aplicar Filtros</button>
            </div>

            <!-- KPIs Row -->
            <div class="row g-4 mb-4">
                <!-- KPI 1 -->
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <h3 class="kpi-title">Volumen de Chats</h3>
                            <div class="kpi-icon primary"><i class="fa-solid fa-comments"></i></div>
                        </div>
                        <div id="kpiTotalChats" class="kpi-value">...</div>
                        <div class="kpi-trend up">
                            <i class="fa-solid fa-arrow-trend-up"></i> +0% vs mes anterior
                        </div>
                    </div>
                </div>

                <!-- KPI 2 -->
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <h3 class="kpi-title">Ciclo de Ventas Promedio</h3>
                            <div class="kpi-icon dark"><i class="fa-solid fa-stopwatch"></i></div>
                        </div>
                        <div id="kpiAvgRes" class="kpi-value">...</div>
                        <div class="kpi-trend down">
                            <i class="fa-solid fa-arrow-trend-up"></i> Estable
                        </div>
                    </div>
                </div>

                <!-- KPI 3: Tasa de Conversión -->
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

                <!-- KPI 4: CAC -->
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
            <div class="row g-4">
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
                    <div class="chart-card">
                        <h5 class="brand-font fw-bold text-starfi-dark mb-1">Volumen de Chats</h5>
                        <p class="text-muted" style="font-size: 0.8rem;">Distribución de conversaciones en los días</p>
                        
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
                                <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i>
                            </div>
                        </div>

                        <div class="mt-2 text-center pt-2">
                            <small class="text-muted fw-bold d-block mb-1">CSAT (Cliente)</small>
                            <h2 class="display-5 fw-bold text-success mb-0" id="kpiCsatScore">...</h2>
                            <div class="text-success fs-5 mb-1" id="csatStarsContainer">
                                <i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-regular fa-star"></i><i class="fa-regular fa-star"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <!-- JavaScript -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="funciones_dashboard.js?v=<?= time() ?>"></script>
    <script>
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
    </script>
    </div>
</body>
</html>




