<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
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

    <!-- Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <img src="../../docs/identidad_visual/logos/isologo.png" alt="STARFI" style="height: 30px;">
                <span>STARFI CRM</span>
            </div>
            <button class="toggle-btn" id="toggleSidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
        </div>
        <nav class="sidebar-nav">
            <a href="../bandeja/bandeja.php" class="nav-item">
                <i class="fa-solid fa-inbox"></i>
                <span class="nav-text">Bandeja Omnicanal</span>
            </a>
            <a href="../directorio/directorio.php" class="nav-item">
                <i class="fa-solid fa-address-book"></i>
                <span class="nav-text">Directorio 360</span>
            </a>
            <a href="../dashboard/dashboard.php" class="nav-item active">
                <i class="fa-solid fa-chart-line"></i>
                <span class="nav-text">Métricas y KPIs</span>
            </a>
            <a href="../gestor_bots/gestor_bots.php" class="nav-item"><i class="fa-solid fa-robot"></i><span class="nav-text">Gestor de Bots</span></a>
            <a href="../configuracion/configuracion.php" class="nav-item">
                <i class="fa-solid fa-gear"></i>
                <span class="nav-text">Configuración</span>
            </a>
        </nav>
        
                <div class="sidebar-footer">
            <div class="agent-profile" style="display: flex; align-items: center; width: 100%;">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($nombre_agente) ?>&background=EBF4FF&color=1E3A8A" alt="Avatar">
                <div class="agent-info" style="flex-grow: 1;">
                    <span class="agent-name" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100px; display: inline-block;"><?= htmlspecialchars($nombre_agente) ?></span>
                    <span class="agent-status online">En línea</span>
                </div>
                <a href="/starfi_crm/logout.php" class="btn text-danger p-1 m-0" title="Cerrar Sesión" style="font-size: 1.1rem;">
                    <i class="fa-solid fa-power-off"></i>
                </a>
            </div>
        </div>
    </aside>

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
            <div class="filters-panel">
                <div class="filter-group">
                    <label>Sede / Sucursal</label>
                    <select class="filter-control">
                        <option>Todas las sedes</option>
                        <option>Caracas - Principal</option>
                        <option>Valencia - Norte</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Operador</label>
                    <select class="filter-control">
                        <option>Todos los operadores</option>
                        <option>Carlos Pérez</option>
                        <option>Ana García</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Rango de Fechas</label>
                    <input type="date" class="filter-control">
                </div>
                <button id="btnApplyFilters" class="btn btn-starfi-primary" style="height: 38px;">Aplicar Filtros</button>
            </div>

            <!-- KPIs Row -->
            <div class="row g-4 mb-4">
                <!-- KPI 1 -->
                <div class="col-md-4">
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
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <h3 class="kpi-title">T. Promedio 1ra Respuesta (FRT)</h3>
                            <div class="kpi-icon success"><i class="fa-solid fa-stopwatch"></i></div>
                        </div>
                        <div id="kpiAvgFrt" class="kpi-value">...</div>
                        <div class="kpi-trend up">
                            <i class="fa-solid fa-arrow-trend-down"></i> Estable
                        </div>
                    </div>
                </div>

                <!-- KPI 3 -->
                <div class="col-md-4">
                    <div class="kpi-card">
                        <div class="kpi-card-header">
                            <h3 class="kpi-title">Tiempo Promedio Resolución</h3>
                            <div class="kpi-icon dark"><i class="fa-solid fa-check-double"></i></div>
                        </div>
                        <div id="kpiAvgRes" class="kpi-value">...</div>
                        <div class="kpi-trend down">
                            <i class="fa-solid fa-arrow-trend-up"></i> Estable
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-4">
                <div class="col-md-8">
                    <div class="chart-card">
                        <h5 class="brand-font fw-bold text-starfi-dark mb-1">Volumen de Chats por Día</h5>
                        <p class="text-muted" style="font-size: 0.8rem;">Distribución de conversaciones en la semana actual</p>
                        
                        <div class="mock-chart">
                            <div class="bar" style="height: 40%;" data-val="120"></div>
                            <div class="bar" style="height: 60%;" data-val="180"></div>
                            <div class="bar" style="height: 85%; background-color: var(--starfi-dark);" data-val="250"></div>
                            <div class="bar" style="height: 50%;" data-val="150"></div>
                            <div class="bar" style="height: 70%;" data-val="210"></div>
                            <div class="bar" style="height: 30%; background-color: var(--text-muted);" data-val="90"></div>
                            <div class="bar" style="height: 20%; background-color: var(--text-muted);" data-val="60"></div>
                        </div>
                        <div class="d-flex justify-content-around mt-2 text-muted" style="font-size: 0.75rem;">
                            <span>Lun</span><span>Mar</span><span>Mie</span><span>Jue</span><span>Vie</span><span>Sab</span><span>Dom</span>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="chart-card h-100">
                        <h5 class="brand-font fw-bold text-starfi-dark mb-1">Desempeño Operadores</h5>
                        <p class="text-muted" style="font-size: 0.8rem;">Resolución de tickets</p>
                        
                        <div class="mt-4" id="operatorPerformanceContainer">
                            <!-- JS Inject -->
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
    <script src="funciones_dashboard.js"></script>
    <script>
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
    </script>
</body>
</html>




