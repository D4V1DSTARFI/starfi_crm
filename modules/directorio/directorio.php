<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('directorio');
$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Directorio 360 | CRM STARFI</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <!-- CSS Local de Bootstrap -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Iconos de Bootstrap (Local) -->
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <!-- Tema Global STARFI -->
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <!-- FontAwesome Local -->
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        .directory-layout {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        .directory-list {
            flex: 1;
            padding: 20px;
            background-color: var(--bg-main);
            overflow-y: auto;
            border-right: 1px solid var(--border-color);
        }
        .table-toolbar {
            background-color: #ffffff;
            padding: 16px 24px;
            border-bottom: 1px solid rgba(0,0,0,0.04);
        }
        .search-bar-modern {
            display: flex;
            align-items: center;
            background-color: #ffffff;
            border-radius: 30px;
            padding: 8px 20px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            transition: all 0.3s ease;
        }
        .search-bar-modern:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.1);
            transform: translateY(-1px);
        }
        .search-bar-modern i {
            color: #94A3B8;
            font-size: 1rem;
            transition: color 0.3s;
        }
        .search-bar-modern:focus-within i {
            color: var(--primary);
        }
        .search-bar-modern input {
            width: 100%;
            border: none;
            background: transparent;
            padding: 4px 12px;
            font-size: 0.95rem;
            color: #334155;
        }
        .search-bar-modern input::placeholder {
            color: #94A3B8;
            font-weight: 400;
        }
        .search-bar-modern input:focus {
            outline: none;
        }
        #btnAddClient {
            border-radius: 30px !important;
            padding: 10px 24px;
            font-weight: 600;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 12px rgba(232, 91, 20, 0.25);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        #btnAddClient:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(232, 91, 20, 0.35);
        }
        th.sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            transition: background-color 0.2s;
        }
        th.sortable:hover {
            background-color: #F8FAFC;
        }
        th.sortable i {
            margin-left: 5px;
            color: #CBD5E1;
            font-size: 0.8em;
            transition: color 0.2s;
        }
        th.sortable.asc i.fa-sort-up,
        th.sortable.desc i.fa-sort-down {
            color: var(--primary);
        }
        .pagination-container {
            padding: 16px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid rgba(0,0,0,0.04);
            background-color: #FCFDFD;
            border-bottom-left-radius: 8px;
            border-bottom-right-radius: 8px;
        }
        .page-info {
            font-size: 0.85rem;
            color: #64748B;
            font-weight: 500;
            background: #F1F5F9;
            padding: 6px 14px;
            border-radius: 20px;
            border: 1px solid #E2E8F0;
        }
        .page-btn {
            border: 1px solid #E2E8F0;
            background-color: #ffffff;
            padding: 8px 20px;
            border-radius: 20px;
            color: #475569;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .page-btn:hover:not(:disabled) {
            background-color: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            box-shadow: 0 4px 10px rgba(232, 91, 20, 0.2);
            transform: translateY(-1px);
        }
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background-color: #F8FAFC;
        }
        .directory-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .client-table {
            background-color: var(--bg-surface);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .table {
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        .table th {
            background-color: #F8FAFC;
            color: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 15px;
            border-bottom: 2px solid var(--border-color);
        }
        .table td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .table tbody tr:hover td {
            background-color: #F8FAFC;
        }
        .table tbody tr.active td {
            background-color: #EFF6FF;
            border-left: 3px solid var(--primary);
        }
        .client-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .client-cell img {
            width: 36px;
            height: 36px;
            border-radius: 6px;
        }
        .client-cell-info h6 {
            margin: 0;
            font-weight: 600;
            color: var(--text-main);
        }
        .client-cell-info small {
            color: var(--text-muted);
        }
        .profile-body-scroll {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: row;
        }
        .profile-data-col {
            width: 45%;
            padding: 30px 40px;
            border-right: 1px solid rgba(0,0,0,0.04);
            background-color: #ffffff;
            overflow-y: auto;
        }
        .profile-avatar-large {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #E2E8F0;
        }
        .profile-avatar-large img {
            width: 70px;
            height: 70px;
            border-radius: 14px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            object-fit: cover;
            margin-bottom: 0;
        }
        .profile-avatar-large h4 {
            font-size: 1.25rem;
            line-height: 1.35;
            margin-bottom: 4px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            font-size: 0.8rem;
            color: #64748B;
            margin-bottom: 8px;
            display: block;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .form-control-custom {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            font-size: 0.95rem;
            color: #1E293B;
            background-color: #F8FAFC;
            transition: all 0.3s ease;
        }
        .form-control-custom:disabled,
        .form-select:disabled {
            background-color: #F1F5F9 !important;
            border: 1px solid #CBD5E1 !important;
            color: #64748B !important;
            font-weight: 500;
            cursor: not-allowed;
            opacity: 0.85;
            box-shadow: none !important;
        }
        .form-control-custom:not(:disabled),
        .form-select:not(:disabled) {
            background-color: #FFFFFF !important;
            border: 2px solid var(--primary) !important;
            color: #0F172A !important;
            font-weight: 600;
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.15) !important;
        }
        .form-control-custom:focus {
            outline: none;
            border-color: var(--primary);
            background-color: #ffffff;
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.25) !important;
        }
        #profPhone, #profPrefix {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        .profile-timeline-col {
            width: 55%;
            padding: 30px 40px;
            background-color: #F8FAFC;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }
        .profile-nav-tabs .nav-link {
            border: 1px solid transparent;
            color: #64748B !important;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.2s ease;
            background-color: #E2E8F0;
            border-radius: 8px 8px 0 0 !important;
            margin-right: 4px;
        }
        .profile-nav-tabs .nav-link.active {
            color: var(--primary) !important;
            border-color: #CBD5E1 #CBD5E1 #ffffff #CBD5E1;
            background-color: #ffffff !important;
        }
        .timeline-feed {
            flex: 1;
            overflow-y: auto;
            position: relative;
        }
        .timeline-feed::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 20px;
            width: 2px;
            background-color: var(--border-color);
        }
        .timeline-item {
            position: relative;
            padding-left: 45px;
            margin-bottom: 20px;
        }
        .timeline-icon {
            position: absolute;
            left: 8px;
            top: 0;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.75rem;
            z-index: 2;
            border: 2px solid var(--bg-main);
        }
        .icon-bot { background-color: var(--starfi-dark); }
        .icon-api { background-color: var(--primary); }
        .icon-agent { background-color: var(--sla-green); }
        .timeline-content {
            background-color: var(--bg-surface);
            padding: 12px 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .timeline-time {
            font-size: 0.75rem;
            color: #94A3B8;
            margin-bottom: 5px;
            display: block;
            font-weight: 500;
        }
        .timeline-text {
            font-size: 0.9rem;
            margin: 0;
            line-height: 1.5;
            color: #334155;
        }
        .empty-timeline {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #94A3B8;
            text-align: center;
        }
        .empty-timeline i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #CBD5E1;
        }
        .btn-broadcast-hero {
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
            color: #ffffff !important;
            border: 1px solid #334155;
            border-radius: 30px !important;
            padding: 10px 22px;
            font-weight: 700;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.2);
            transition: all 0.25s ease;
        }
        .btn-broadcast-hero:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(232, 91, 20, 0.3);
            border-color: var(--primary);
        }
        .wa-preview-frame {
            background-color: #EFEAE2;
            background-image: radial-gradient(rgba(0,0,0,0.04) 1px, transparent 0);
            background-size: 18px 18px;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #CBD5E1;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
        }
        .wa-preview-header {
            background-color: #075E54;
            color: #ffffff;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .wa-preview-bubble {
            background-color: #FFFFFF;
            border-radius: 0 12px 12px 12px;
            padding: 12px 14px;
            margin: 18px 16px;
            max-width: 92%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            font-size: 0.9rem;
            color: #111B21;
            line-height: 1.5;
            position: relative;
        }
        .wa-preview-footer {
            font-size: 0.75rem;
            color: #8696A0;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px solid #F0F2F5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .mode-pill-btn {
            border: 1px solid #CBD5E1;
            background: #F8FAFC;
            color: #475569;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            flex: 1;
            text-align: center;
        }
        .mode-pill-btn.active {
            background: #FFF7ED;
            border-color: var(--primary);
            color: var(--primary);
            box-shadow: 0 0 0 3px rgba(232, 91, 20, 0.12);
        }
        .preset-chip {
            font-size: 0.76rem;
            padding: 5px 12px;
            border-radius: 999px;
            background: #F1F5F9;
            border: 1px solid #CBD5E1;
            color: #334155;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .preset-chip:hover {
            background: #E85B14;
            color: #ffffff;
            border-color: #E85B14;
        }
    </style>
</head>
<body>
    <?php renderHeader('Directorio de Clientes'); ?>
    <div class="app-container">
    <main class="main-content">
        <div class="directory-layout">
            <section class="directory-list">
                <div class="directory-header flex-wrap gap-3">
                    <div>
                        <h2 class="brand-font mb-1" style="font-weight: 600;">Directorio de Clientes &amp; Difusión</h2>
                        <p class="text-muted mb-0" style="font-size: 0.9rem;">Gestión centralizada de contactos, segmentación y campañas oficiales de WhatsApp (Meta API)</p>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button id="btnOpenBroadcast" class="btn btn-broadcast-hero d-flex align-items-center gap-2">
                            <i class="fa-solid fa-bullhorn text-warning"></i> Difusión Inteligente (Meta)
                            <span id="selectedCountPill" class="badge rounded-pill bg-warning text-dark ms-1" style="display:none;">0</span>
                        </button>
                        <button id="btnAddClient" class="btn btn-starfi-primary d-flex align-items-center gap-2" style="border-radius: 8px;">
                            <i class="fa-solid fa-user-plus"></i> Nuevo Cliente
                        </button>
                    </div>
                </div>

                <div class="client-table">
                    <div class="table-toolbar d-flex justify-content-between align-items-center flex-wrap gap-3 border-bottom">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="search-bar-modern" style="width: 340px;">
                                <i class="fa-solid fa-search text-muted"></i>
                                <input type="text" id="searchClient" autocomplete="off" placeholder="Buscar por nombre, número o etiqueta...">
                            </div>
                            <select id="filterDirSede" class="form-select" style="width: 220px; border-radius: 20px; font-size: 0.88rem; font-weight: 600; border-color: #E2E8F0;">
                                <option value="">Todas las Sedes</option>
                            </select>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted small fw-semibold" id="selectionSummaryText">Selecciona contactos o lanza una difusión por sede/lista</span>
                            <button type="button" id="btnClearSelection" class="btn btn-sm btn-outline-secondary rounded-pill px-3" style="display:none; font-size:0.78rem;">
                                Limpiar selección
                            </button>
                        </div>
                    </div>

                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width: 44px; text-align: center;">
                                    <input type="checkbox" class="form-check-input" id="chkSelectAllClients" title="Seleccionar todos los filtrados">
                                </th>
                                <th class="sortable" data-sort="nombre">Cliente <i class="fa-solid fa-sort"></i></th>
                                <th class="sortable" data-sort="telefono">Teléfono <i class="fa-solid fa-sort"></i></th>
                                <th class="sortable" data-sort="sede">Sede <i class="fa-solid fa-sort"></i></th>
                                <th class="sortable" data-sort="estado">Estado <i class="fa-solid fa-sort"></i></th>
                                <th>Etiquetas</th>
                                <th class="sortable" data-sort="fecha">Último Contacto <i class="fa-solid fa-sort"></i></th>
                            </tr>
                        </thead>
                        <tbody id="clientsTableBody">
                        </tbody>
                    </table>

                    <div class="pagination-container">
                        <span class="page-info" id="pageInfo">Mostrando 0 - 0 de 0 clientes</span>
                        <div class="d-flex gap-2">
                            <button class="page-btn" id="btnPrevPage" disabled><i class="fa-solid fa-chevron-left me-1"></i> Anterior</button>
                            <button class="page-btn" id="btnNextPage" disabled>Siguiente <i class="fa-solid fa-chevron-right ms-1"></i></button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <?php
        define('STARFI_MODALS_ALLOWED', true);
        require __DIR__ . '/modals_directorio.php';
        ?>
    </main>

    <!-- JavaScript Local -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_directorio.js"></script>
    </div>
</body>
</html>
