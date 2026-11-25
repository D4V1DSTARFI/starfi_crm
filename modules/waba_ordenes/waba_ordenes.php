<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('waba_ordenes');

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
    <title>Órdenes de Cobro WABA | STARFI CRM</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        .waba-ordenes-scroll {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            background-color: var(--bg-main);
        }
        .kpi-card {
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            background: #ffffff;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
        }
        .kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .kpi-label {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #64748b;
            font-weight: 700;
        }
        .kpi-value {
            font-size: 1.55rem;
            font-weight: 800;
            color: #0f172a;
            line-height: 1.2;
        }
        .comprobante-preview-box {
            max-height: 360px;
            overflow: auto;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #f8fafc;
            padding: 10px;
            text-align: center;
        }
        .comprobante-preview-box img {
            max-width: 100%;
            max-height: 320px;
            border-radius: 6px;
        }
    </style>
</head>
<body>
    <?php renderHeader('Panel de Órdenes WABA'); ?>
    <div class="app-container" id="wabaOrdenesRoot" data-es-master="<?= $es_master ? '1' : '0' ?>" data-id-sede="<?= $id_sede_agente ?>">
        <?php renderSidebar('waba_ordenes'); ?>

        <main class="main-content">
            <div class="waba-ordenes-scroll">
                <div class="container-fluid px-0">
                    <!-- Cabecera y Navegación Directa -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
                        <div>
                            <h2 class="mb-0 fw-bold" style="font-size: 1.45rem; color: #1e293b;">
                                <i class="fas fa-file-invoice-dollar text-primary me-2"></i>
                                <?= $es_master ? 'Panel Administrativo - Órdenes de Cobro WhatsApp' : 'Mis Órdenes de Cobro WhatsApp API' ?>
                            </h2>
                            <small class="text-muted">
                                <?= $es_master ? 'Control de facturación, conciliación de pagos y auditoría financiera por sede.' : 'Consulta de estados de cuenta, desglose de consumo y reporte de pagos.' ?>
                            </small>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if (hasPermission('whatsapp_analytics')): ?>
                            <a href="../whatsapp_analytics/whatsapp_analytics.php" class="btn btn-primary shadow-sm">
                                <i class="fas fa-chart-line me-1"></i> Estadísticas WABA
                            </a>
                            <?php endif; ?>
                            <a href="../../index.php" class="btn btn-outline-secondary bg-white shadow-sm">
                                <i class="fas fa-arrow-left me-1"></i> Volver al Inicio
                            </a>
                        </div>
                    </div>

                    <!-- Tarjetas KPI Financieras -->
                    <div class="row g-3 mb-4">
                        <div class="<?= $es_master ? 'col-md-6 col-xl-3' : 'col-md-4' ?>">
                            <div class="kpi-card p-3 shadow-sm h-100" style="border-left: 4px solid #2563eb;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="kpi-label">Total Facturado</div>
                                        <div class="kpi-value mt-1" id="kpiTotalFacturado">$0.00</div>
                                        <small class="text-muted" style="font-size: 0.75rem;">Acumulado del filtro activo</small>
                                    </div>
                                    <div class="kpi-icon" style="background: rgba(37, 99, 235, 0.1); color: #2563eb;">
                                        <i class="fas fa-file-invoice"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="<?= $es_master ? 'col-md-6 col-xl-3' : 'col-md-4' ?>">
                            <div class="kpi-card p-3 shadow-sm h-100" style="border-left: 4px solid #16a34a;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="kpi-label">Total Cobrado</div>
                                        <div class="kpi-value mt-1 text-success" id="kpiTotalCobrado">$0.00</div>
                                        <small class="text-muted" style="font-size: 0.75rem;">Pagos verificados (PAGADO)</small>
                                    </div>
                                    <div class="kpi-icon" style="background: rgba(22, 163, 74, 0.1); color: #16a34a;">
                                        <i class="fas fa-check-circle"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="<?= $es_master ? 'col-md-6 col-xl-3' : 'col-md-4' ?>">
                            <div class="kpi-card p-3 shadow-sm h-100" style="border-left: 4px solid #d97706;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="kpi-label">Por Cobrar</div>
                                        <div class="kpi-value mt-1 text-warning" id="kpiPorCobrar" style="color: #d97706 !important;">$0.00</div>
                                        <small class="text-muted" id="kpiEnRevisionSub" style="font-size: 0.75rem;">En revisión: $0.00</small>
                                    </div>
                                    <div class="kpi-icon" style="background: rgba(217, 119, 6, 0.1); color: #d97706;">
                                        <i class="fas fa-clock"></i>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($es_master): ?>
                        <div class="col-md-6 col-xl-3">
                            <div class="kpi-card p-3 shadow-sm h-100" style="border-left: 4px solid #7c3aed;">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="kpi-label">Margen Neto CRM <span class="badge bg-dark ms-1" style="font-size:0.65rem;">MASTER</span></div>
                                        <div class="kpi-value mt-1" id="kpiMargenNeto" style="color: #7c3aed;">$0.00</div>
                                        <small class="text-muted" id="kpiMargenCobradoSub" style="font-size: 0.75rem;">Margen cobrado: $0.00</small>
                                    </div>
                                    <div class="kpi-icon" style="background: rgba(124, 58, 237, 0.1); color: #7c3aed;">
                                        <i class="fas fa-sack-dollar"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Barra de Filtros -->
                    <div class="card shadow-sm mb-3 border-0">
                        <div class="card-body py-3">
                            <form id="formFiltrosOrdenesWaba" class="row g-2 align-items-end" autocomplete="off">
                                <div class="col-md-3">
                                    <label for="filter_sede" class="form-label mb-1 fw-semibold small text-secondary">Sede / Sucursal</label>
                                    <select id="filter_sede" class="form-select" <?= !$es_master ? 'disabled' : '' ?>>
                                        <?php if ($es_master): ?>
                                            <option value="">Todas las Sedes</option>
                                        <?php endif; ?>
                                        <?php foreach ($sedes as $s): ?>
                                            <option value="<?= intval($s['id']) ?>" <?= (!$es_master && intval($s['id']) === $id_sede_agente) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string)$s['nombre_sede'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="filter_estado" class="form-label mb-1 fw-semibold small text-secondary">Estado</label>
                                    <select id="filter_estado" class="form-select">
                                        <option value="">Todos los Estados</option>
                                        <option value="PENDIENTE">PENDIENTE</option>
                                        <option value="EN_REVISION">PAGADA (PEND. REVISIÓN)</option>
                                        <option value="PAGADO">PAGADO (VERIFICADO)</option>
                                        <option value="FUSIONADO">FUSIONADO</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="filter_fecha_desde" class="form-label mb-1 fw-semibold small text-secondary">Fecha Desde</label>
                                    <input type="date" id="filter_fecha_desde" class="form-control">
                                </div>
                                <div class="col-md-2">
                                    <label for="filter_fecha_hasta" class="form-label mb-1 fw-semibold small text-secondary">Fecha Hasta</label>
                                    <input type="date" id="filter_fecha_hasta" class="form-control">
                                </div>
                                <div class="col-md-3 d-flex gap-2">
                                    <button type="submit" id="btnFiltrar" class="btn btn-primary w-100 fw-semibold"><i class="fas fa-search me-1"></i>Buscar</button>
                                    <button type="button" id="btnLimpiar" class="btn btn-outline-secondary w-100"><i class="fas fa-eraser me-1"></i>Limpiar</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Tabla Principal de Órdenes -->
                    <div class="card shadow-sm border-0">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover table-striped align-middle mb-0" id="tablaOrdenes">
                                    <thead class="table-dark">
                                        <tr>
                                            <th># Orden</th>
                                            <th>Sede / Sucursal</th>
                                            <th>Periodo</th>
                                            <th>Mensajes</th>
                                            <?php if ($es_master): ?>
                                                <th>Costo Base Meta</th>
                                                <th>Margen CRM</th>
                                                <th>Total a Cobrar</th>
                                            <?php else: ?>
                                                <th>Total a Pagar</th>
                                            <?php endif; ?>
                                            <th>Estado</th>
                                            <th>Método / Ref.</th>
                                            <?php if ($es_master): ?>
                                                <th>Notificaciones</th>
                                            <?php endif; ?>
                                            <th class="text-end">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    </tbody>
                                </table>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-3" id="paginationContainer" style="display:none !important;">
                                <span class="text-muted small" id="lblTotalRegistros">Mostrando 0 registros</span>
                                <nav>
                                    <ul class="pagination pagination-sm mb-0" id="ulPagination">
                                    </ul>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php
    define('STARFI_MODALS_ALLOWED', true);
    require __DIR__ . '/modals_waba_ordenes.php';
    ?>

    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_waba_ordenes.js"></script>
</body>
</html>
