<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
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
    <link rel="stylesheet" href="../../css/styles.css">
    
    <style>
        .dashboard-container { padding: 30px; background-color: var(--bg-main); overflow-y: auto; flex: 1; }
        .dashboard-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .kpi-card { background-color: var(--bg-surface); border-radius: 10px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid var(--border-color); display: flex; flex-direction: column; }
        .kpi-title { color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase; font-weight: 600; margin-bottom: 15px; }
        .kpi-value { font-size: 2rem; font-weight: 700; color: var(--text-main); font-family: var(--font-heading); margin-bottom: 5px; }
        .filters-panel { background-color: var(--bg-surface); border-radius: 10px; padding: 15px 20px; margin-bottom: 25px; border: 1px solid var(--border-color); display: flex; gap: 15px; align-items: flex-end; }
        .filter-group { flex: 1; }
        .filter-group label { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 5px; display: block; font-weight: 600; }
        .filter-control { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 6px; font-size: 0.9rem; background-color: #F8FAFC; }
    </style>
</head>
<body>
    <?php renderHeader('Dashboard Facturación WhatsApp'); ?>
    <div class="app-container">
    <main class="main-content">
        <div class="dashboard-container">
            
            <div class="dashboard-header">
                <div>
                    <h2 class="brand-font mb-1" style="font-weight: 600;"><i class="fa-brands fa-whatsapp text-success me-2"></i>Métricas de Consumo</h2>
                    <p class="text-muted" style="font-size: 0.9rem;">Analíticas oficiales de WhatsApp Business API</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="dashboard.php" class="btn btn-outline-secondary d-flex align-items-center gap-2">
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
                    <label>Fecha Desde</label>
                    <input type="date" id="filterFechaDesde" class="filter-control" value="<?= date('Y-m-01') ?>">
                </div>
                <div class="filter-group">
                    <label>Fecha Hasta</label>
                    <input type="date" id="filterFechaHasta" class="filter-control" value="<?= date('Y-m-t') ?>">
                </div>
                <button id="btnApplyFilters" class="btn btn-success text-white fw-bold shadow-sm" style="height: 38px;">
                    <i class="fa-solid fa-sync me-2"></i>Cargar
                </button>
            </div>

            <div id="loaderData" class="text-center py-5" style="display: none;">
                <i class="fa-solid fa-spinner fa-spin fa-3x text-muted mb-3"></i>
                <p class="text-muted">Consultando métricas en Facebook Meta...</p>
            </div>

            <!-- KPIs -->
            <div id="contentData" style="display: none;">
                <div class="row g-4 mb-4">
                    <div class="col-md-3">
                        <div class="kpi-card border-success">
                            <h3 class="kpi-title"><i class="fa-solid fa-money-bill-wave text-success me-2"></i>Costo Estimado</h3>
                            <div id="kpiCosto" class="kpi-value text-success">USD 0.00</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <h3 class="kpi-title"><i class="fa-solid fa-paper-plane text-primary me-2"></i>Conversaciones Totales</h3>
                            <div id="kpiTotal" class="kpi-value">0</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <h3 class="kpi-title"><i class="fa-solid fa-bullhorn text-warning me-2"></i>Marketing</h3>
                            <div id="kpiMarketing" class="kpi-value">0</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="kpi-card">
                            <h3 class="kpi-title"><i class="fa-solid fa-receipt text-info me-2"></i>Utilidad (Recibos)</h3>
                            <div id="kpiUtility" class="kpi-value">0</div>
                        </div>
                    </div>
                </div>
                
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body p-4">
                        <h6 class="fw-bold mb-3"><i class="fa-solid fa-code text-muted me-2"></i>Raw API Response (Debug Meta)</h6>
                        <pre id="rawApiResponse" class="bg-dark text-light p-3 rounded" style="font-size: 0.8rem; max-height: 400px; overflow-y: auto;"></pre>
                    </div>
                </div>
            </div>
            
        </div>
    </main>

    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#btnApplyFilters').click(function() {
                loadAnalytics();
            });
            
            // Cargar inicial
            loadAnalytics();
        });

        function loadAnalytics() {
            let id_sede = $('#filterSede').val();
            let desde = $('#filterFechaDesde').val();
            let hasta = $('#filterFechaHasta').val();

            $('#contentData').hide();
            $('#loaderData').show();

            $.ajax({
                url: 'back_whatsapp_analytics.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'get_analytics',
                    id_sede: id_sede,
                    fecha_desde: desde,
                    fecha_hasta: hasta
                },
                success: function(res) {
                    $('#loaderData').hide();
                    if(res.status === 'success') {
                        $('#contentData').fadeIn();
                        
                        // Parsear datos
                        // Meta retorna structure: res.data.conversation_analytics.data
                        let dataStr = JSON.stringify(res.data, null, 2);
                        $('#rawApiResponse').text(dataStr);
                        
                        let totalCost = 0;
                        let mktCount = 0;
                        let utilCount = 0;
                        let totalConversations = 0;
                        let currency = 'USD';

                        if(res.data && res.data.conversation_analytics && res.data.conversation_analytics.data) {
                            let convs = res.data.conversation_analytics.data[0].data_points ?? [];
                            convs.forEach(dp => {
                                if (dp.cost) totalCost += parseFloat(dp.cost);
                                if (dp.conversation) totalConversations += parseInt(dp.conversation);
                                if (dp.conversation_category === 'MARKETING' && dp.conversation) {
                                    mktCount += parseInt(dp.conversation);
                                }
                                if (dp.conversation_category === 'UTILITY' && dp.conversation) {
                                    utilCount += parseInt(dp.conversation);
                                }
                            });
                        }
                        
                        $('#kpiCosto').text(currency + ' ' + totalCost.toFixed(2));
                        $('#kpiTotal').text(totalConversations);
                        $('#kpiMarketing').text(mktCount);
                        $('#kpiUtility').text(utilCount);

                    } else {
                        Swal.fire('Error', res.message || 'Error desconocido', 'error');
                        $('#rawApiResponse').text(JSON.stringify(res, null, 2));
                        $('#contentData').show();
                    }
                },
                error: function() {
                    $('#loaderData').hide();
                    Swal.fire('Error', 'Fallo de conexión al consultar Meta', 'error');
                }
            });
        }
    </script>
    </div>
</body>
</html>
