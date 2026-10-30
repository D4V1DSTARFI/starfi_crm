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

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css">
    <style>
        /* Estilos específicos para el Directorio 360 */
        .directory-layout {
            display: flex;
            flex: 1;
            overflow: hidden;
        }
        
        /* Lista General de Directorio */
        .directory-list {
            flex: 1;
            padding: 20px;
            background-color: var(--bg-main);
            overflow-y: auto;
            border-right: 1px solid var(--border-color);
                   /* Buscador moderno en tabla (Premium) */
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
            
            /* Botón Nuevo Cliente Premium */
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

            /* Sortable headers */
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
            
            /* Paginación Premium */
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
            }: var(--text-muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 15px;
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

        /* Panel Ficha Cliente */
        .client-profile-panel {
            width: 600px;
            background-color: var(--bg-surface);
            display: flex;
            flex-direction: column;
            transform: translateX(100%);
            transition: transform 0.3s ease;
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            box-shadow: -5px 0 15px rgba(0,0,0,0.05);
            z-index: 100;
        }

        .client-profile-panel.open {
            transform: translateX(0);
            position: relative;
        }

        .profile-header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            background-color: #F8FAFC;
        }

        .profile-body-scroll {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: row; /* 2 Columnas */
        }

        /* Columna Izquierda: Datos */
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

        /* Inputs Deshabilitados (Modo Lectura - Gris Suave) */
        .form-control-custom:disabled,
        .form-select:disabled {
            background-color: #F1F5F9 !important;
            border: 1px border-solid #CBD5E1 !important;
            color: #64748B !important;
            font-weight: 500;
            cursor: not-allowed;
            opacity: 0.85;
            box-shadow: none !important;
        }

        /* Inputs Habilitados (Modo Edición Activo - Borde Naranja Destacado) */
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
        
        /* Inputs Bootstrap en el modal */
        #profPhone, #profPrefix {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        /* Columna Derecha: Timeline */
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

        .timeline-header {
            font-weight: 700;
            margin-bottom: 25px;
            color: #0F172A;
            padding-bottom: 15px;
            font-size: 1.1rem;
            border-bottom: 1px solid #E2E8F0;
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

        /* Estilos Centro de Difusión Inteligente */
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

    <!-- Sidebar Navigation -->

    <!-- Main Content -->
    <main class="main-content">
        <div class="directory-layout">
            
            <!-- Lista General del Directorio -->
            <section class="directory-list">
                <div class="directory-header flex-wrap gap-3">
                    <div>
                        <h2 class="brand-font mb-1" style="font-weight: 600;">Directorio de Clientes & Difusión</h2>
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
                    <!-- Toolbar de la Tabla -->
                    <div class="table-toolbar d-flex justify-content-between align-items-center flex-wrap gap-3 border-bottom">
                        <div class="d-flex align-items-center gap-3 flex-wrap">
                            <div class="search-bar-modern" style="width: 340px;">
                                <i class="fa-solid fa-search text-muted"></i>
                                <input type="text" id="searchClient" placeholder="Buscar por nombre, número o etiqueta...">
                            </div>
                            <select id="filterDirSede" class="form-select" style="width: 220px; border-radius: 20px; font-size: 0.88rem; font-weight: 600; border-color: #E2E8F0;">
                                <option value="">📍 Todas las Sedes</option>
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
                            <!-- JS Inject -->
                        </tbody>
                    </table>
                    
                    <!-- Paginación -->
                    <div class="pagination-container">
                        <span class="page-info" id="pageInfo">Mostrando 0 - 0 de 0 clientes</span>
                        <div class="d-flex gap-2">
                            <button class="page-btn" id="btnPrevPage" disabled><i class="fa-solid fa-chevron-left me-1"></i> Anterior</button>
                            <button class="page-btn" id="btnNextPage" disabled>Siguiente <i class="fa-solid fa-chevron-right ms-1"></i></button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Modal Ficha de Cliente (Layout 2 Columnas) -->
            <div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1350px;">
                    <div class="modal-content overflow-hidden">
                        <div class="modal-header border-0 bg-light position-relative text-center d-flex justify-content-center align-items-center">
                            <h5 class="modal-title mb-0 brand-font fw-bold text-starfi-dark text-center w-100"><i class="fa-solid fa-id-card-clip me-2 text-starfi-primary"></i>Ficha de Cliente 360</h5>
                            <button type="button" class="btn-close position-absolute end-0 me-3" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        
                        <div class="modal-body p-0">
                            <div class="profile-body-scroll d-flex flex-row" style="height: 780px;">
                    <!-- Columna Izquierda: Datos -->
                    <div class="profile-data-col d-flex flex-column h-100 p-0" style="width: 45%; background-color: #ffffff; border-right: 1px solid rgba(0,0,0,0.06);">
                        <div class="p-4 flex-grow-1 overflow-auto">
                            <div class="profile-avatar-large">
                                <img id="profAvatarImg" src="https://ui-avatars.com/api/?name=User&background=E85B14&color=fff" alt="Avatar">
                                <div>
                                    <h4 class="brand-font fw-bold mb-1" id="profTitleName" style="color: #0F172A;">Nombre Cliente</h4>
                                    <span class="badge bg-light text-secondary border" id="profTitleId">ID: CLI-000</span>
                                </div>
                            </div>

                            <div class="form-group mt-3">
                                <label>Nombre Comercial / Razón Social</label>
                                <input type="text" id="profName" class="form-control-custom" placeholder="Ej. Empresa S.A.">
                            </div>
                            <div class="form-group">
                                <label>Sede Asociada</label>
                                <select id="profSede" class="form-control-custom">
                                    <option value="">General / Central</option>
                                    <!-- JS Inject Sedes -->
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Número de WhatsApp</label>
                                <div class="d-flex gap-2">
                                    <select class="form-select" id="profPrefix" style="max-width: 120px;">
                                        <option value="58414">0414</option>
                                        <option value="58424">0424</option>
                                        <option value="58412">0412</option>
                                        <option value="58416">0416</option>
                                        <option value="58426">0426</option>
                                    </select>
                                    <input type="text" id="profPhone" class="form-control-custom" style="flex: 1;" placeholder="1234567">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Dirección</label>
                                <textarea id="profAddress" class="form-control-custom" rows="2" placeholder="Ubicación física..."></textarea>
                            </div>
                            <div class="form-group">
                                <label>Etiquetas (Tags)</label>
                                <div class="d-flex gap-2 flex-wrap mt-1">
                                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary px-3 py-2 rounded-pill" style="cursor: pointer;">+ Añadir Etiqueta</span>
                                </div>
                            </div>
                            <div class="form-group mb-2">
                                <label>Notas Internas</label>
                                <textarea id="profNotes" class="form-control-custom" rows="3" placeholder="Información relevante solo para agentes..."></textarea>
                            </div>
                        </div>

                        <!-- Sticky Footer para Botones (Siempre Visible) -->
                        <div class="p-3 border-top bg-white">
                            <button id="btnEditProfile" type="button" class="btn btn-outline-primary w-100 py-2.5 fw-bold" style="border-radius: 10px;">
                                <i class="fa-solid fa-pen-to-square me-2"></i>Editar Perfil
                            </button>
                            <button id="btnSaveProfile" type="button" class="btn btn-starfi-primary w-100 py-2.5 fw-bold" style="border-radius: 10px; box-shadow: 0 4px 12px rgba(232, 91, 20, 0.2); display: none;">
                                <i class="fa-solid fa-floppy-disk me-2"></i>Guardar Cambios
                            </button>
                        </div>
                    </div>

                    <!-- Columna Derecha: Pestañas de Historial (Conversaciones y Ventas) -->
                    <div class="profile-timeline-col">
                        <ul class="nav nav-tabs profile-nav-tabs mb-3" id="profileTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-conv-btn" data-bs-toggle="tab" data-bs-target="#tab-conv-pane" type="button" role="tab">
                                    <i class="fa-solid fa-comments me-2 text-starfi-primary"></i>Conversaciones
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-ventas-btn" data-bs-toggle="tab" data-bs-target="#tab-ventas-pane" type="button" role="tab">
                                    <i class="fa-solid fa-cart-shopping me-2 text-success"></i>Historial de Ventas
                                </button>
                            </li>
                        </ul>
                        
                        <div class="tab-content flex-grow-1 overflow-auto" id="profileTabsContent">
                            <div class="tab-pane fade show active" id="tab-conv-pane" role="tabpanel">
                                <div class="timeline-feed" id="profileConversationsFeed">
                                    <!-- JS Inject Conversaciones -->
                                </div>
                            </div>
                            <div class="tab-pane fade" id="tab-ventas-pane" role="tabpanel">
                                <div class="timeline-feed" id="profileSalesFeed">
                                    <!-- JS Inject Ventas -->
                                </div>
                            </div>
                        </div>
                    </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Centro de Difusión Inteligente (Meta Cloud API) -->
            <div class="modal fade" id="broadcastModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
                <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 1380px;">
                    <div class="modal-content overflow-hidden" style="border-radius: 16px; border: none; box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);">
                        <!-- Cabecera Oscura Ejecutiva -->
                        <div class="modal-header border-0 px-4 py-3" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); color: #fff;">
                            <div class="d-flex align-items-center gap-3">
                                <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(232, 91, 20, 0.2); border: 1px solid #E85B14; display: flex; align-items: center; justify-content: center; font-size: 1.25rem; color: #FF8A4D;">
                                    <i class="fa-solid fa-bullhorn"></i>
                                </div>
                                <div>
                                    <h5 class="modal-title mb-0 fw-bold text-white">Centro de Difusión Inteligente • STARFI CRM</h5>
                                    <small style="color: #94A3B8;">Despacho oficial vía Meta WhatsApp Cloud API conectado con el Asistente IA de cada sede</small>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <ul class="nav nav-pills bg-dark bg-opacity-50 p-1 rounded-pill" id="bcTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active rounded-pill px-3 py-1 small fw-bold text-white" id="bc-new-tab" data-bs-toggle="pill" data-bs-target="#bc-new-pane" type="button" role="tab">
                                            <i class="fa-solid fa-paper-plane me-1"></i> Nueva Difusión
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link rounded-pill px-3 py-1 small fw-bold text-white" id="bc-history-tab" data-bs-toggle="pill" data-bs-target="#bc-history-pane" type="button" role="tab">
                                            <i class="fa-solid fa-clock-rotate-left me-1"></i> Historial de Campañas
                                        </button>
                                    </li>
                                </ul>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                        </div>

                        <div class="modal-body p-0">
                            <div class="tab-content">
                                <!-- PESTAÑA 1: NUEVA CAMPAÑA DE DIFUSIÓN -->
                                <div class="tab-pane fade show active" id="bc-new-pane" role="tabpanel">
                                    <div class="row g-0" style="min-height: 700px;">
                                        <!-- Columna Izquierda: Configuración de Campaña y Variables -->
                                        <div class="col-12 col-lg-7 p-4 border-end" style="background: #FFFFFF; max-height: 760px; overflow-y: auto;">
                                            <div class="row g-3 mb-3">
                                                <div class="col-12 col-md-6">
                                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1">1. Sede / Línea Emisora de WhatsApp</label>
                                                    <select id="bcSedeSelect" class="form-select fw-semibold" style="border-radius: 10px; padding: 10px 14px;">
                                                        <option value="">Cargando líneas activas...</option>
                                                    </select>
                                                </div>
                                                <div class="col-12 col-md-6">
                                                    <label class="form-label small fw-bold text-uppercase text-muted mb-1">Nombre Interno de Campaña</label>
                                                    <input type="text" id="bcCampaignName" class="form-control" style="border-radius: 10px; padding: 10px 14px;" placeholder="Ej. Inauguración Sede Carpintero Express">
                                                </div>
                                            </div>

                                            <!-- 2. Selección de Destinatarios -->
                                            <div class="mb-3">
                                                <label class="form-label small fw-bold text-uppercase text-muted mb-2">2. Origen de los Destinatarios</label>
                                                <div class="d-flex gap-2 mb-3">
                                                    <div class="mode-pill-btn active" data-mode="directorio" onclick="setBroadcastRecipientMode('directorio')">
                                                        <i class="fa-solid fa-users me-1"></i> Directorio CRM
                                                    </div>
                                                    <div class="mode-pill-btn" data-mode="manual" onclick="setBroadcastRecipientMode('manual')">
                                                        <i class="fa-solid fa-file-csv me-1"></i> Pegar Lista / Excel / CSV
                                                    </div>
                                                    <div class="mode-pill-btn" data-mode="prueba" onclick="setBroadcastRecipientMode('prueba')">
                                                        <i class="fa-solid fa-flask me-1"></i> Envío de Prueba (1 Número)
                                                    </div>
                                                </div>

                                                <!-- Caja Modo Directorio -->
                                                <div id="bcBoxDirectorio" class="p-3 rounded-3 border" style="background: #F8FAFC;">
                                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                        <div>
                                                            <select id="bcDirectoryScope" class="form-select form-select-sm fw-semibold" style="min-width: 280px; border-radius: 8px;">
                                                                <option value="selected">Enviar a los contactos seleccionados en la tabla</option>
                                                                <option value="sede">Enviar a TODOS los contactos de la Sede emisora</option>
                                                                <option value="all">Enviar a TODO el Directorio Activo</option>
                                                            </select>
                                                        </div>
                                                        <span class="badge bg-primary px-3 py-2 rounded-pill" id="bcDirCountBadge" style="font-size: 0.82rem;">0 destinatarios listos</span>
                                                    </div>
                                                </div>

                                                <!-- Caja Modo Lista Manual / CSV -->
                                                <div id="bcBoxManual" class="p-3 rounded-3 border" style="background: #F8FAFC; display: none;">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <small class="text-muted fw-semibold">Escribe o pega desde Excel un contacto por línea: <code>Nombre, Teléfono</code> o solo <code>Teléfono</code></small>
                                                        <div>
                                                            <input type="file" id="bcCsvInput" accept=".csv,.txt" style="display:none;">
                                                            <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3" onclick="$('#bcCsvInput').click()">
                                                                <i class="fa-solid fa-upload me-1"></i> Subir CSV / TXT
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <textarea id="bcManualList" class="form-control font-monospace" rows="4" style="font-size: 0.85rem; border-radius: 8px;" placeholder="Carlos Pérez, 04141234567&#10;María Gómez, 04249876543&#10;04125554433"></textarea>
                                                    <div class="d-flex justify-content-between align-items-center mt-2">
                                                        <small class="text-muted">Los números <code>0414...</code>, <code>414...</code> o <code>58414...</code> se formatean automáticamente.</small>
                                                        <span class="badge bg-success px-3 py-1 rounded-pill" id="bcManualCountBadge">0 números válidos detectados</span>
                                                    </div>
                                                </div>

                                                <!-- Caja Modo Prueba Individual -->
                                                <div id="bcBoxPrueba" class="p-3 rounded-3 border" style="background: #FFFBEB; border-color: #FDE68A !important; display: none;">
                                                    <div class="row g-2 align-items-end">
                                                        <div class="col-12 col-md-5">
                                                            <label class="small fw-bold text-dark mb-1">Nombre de Prueba ({{1}})</label>
                                                            <input type="text" id="bcTestName" class="form-control form-control-sm" value="Julián" placeholder="Ej. Julián">
                                                        </div>
                                                        <div class="col-12 col-md-7">
                                                            <label class="small fw-bold text-dark mb-1">Teléfono WhatsApp de Prueba</label>
                                                            <input type="text" id="bcTestPhone" class="form-control form-control-sm" placeholder="Ej. 04141234567">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 3. Plantilla y Variables -->
                                            <div class="p-3 rounded-3 border mb-3" style="background: #FFFFFF;">
                                                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 pb-2 border-bottom">
                                                    <div class="d-flex align-items-center gap-2">
                                                        <span class="badge bg-success"><i class="fa-solid fa-check-circle me-1"></i> ACTIVA EN META</span>
                                                        <select id="bcTemplateSelect" class="form-select form-select-sm fw-bold text-dark" style="width: auto; min-width: 230px; border-radius: 8px;">
                                                            <option value="starfi_difusion_directa" data-lang="es">starfi_difusion_directa (es)</option>
                                                        </select>
                                                    </div>
                                                    <div class="d-flex gap-1 flex-wrap">
                                                        <span class="preset-chip" onclick="applyBroadcastPreset('inauguracion')">🎉 Preset: Inauguración</span>
                                                        <span class="preset-chip" onclick="applyBroadcastPreset('ofertas')">🔥 Preset: Ofertas Carpintero Express</span>
                                                    </div>
                                                </div>

                                                <!-- Variable {{1}} -->
                                                <div class="row g-2 align-items-center mb-3">
                                                    <div class="col-12 col-md-7">
                                                        <label class="small fw-bold text-dark mb-0"><span class="badge bg-dark me-1">{{1}}</span> Saludo Personalizado (Automático)</label>
                                                        <small class="d-block text-muted">Se inserta automáticamente el primer nombre de cada cliente.</small>
                                                    </div>
                                                    <div class="col-12 col-md-5">
                                                        <label class="small text-muted mb-1">Si el número no tiene nombre usar:</label>
                                                        <input type="text" id="bcDefaultName" class="form-control form-control-sm fw-semibold" value="Amigo(a)">
                                                    </div>
                                                </div>

                                                <!-- Variable {{2}} -->
                                                <div class="mb-3">
                                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                                        <label class="small fw-bold text-dark mb-0"><span class="badge bg-primary me-1">{{2}}</span> Cuerpo del Mensaje <span class="text-muted fw-normal">("Hoy queremos <strong>{{2}}</strong>.")</span></label>
                                                        <small class="text-muted" id="bcParam2Counter">0 caracteres</small>
                                                    </div>
                                                    <textarea id="bcParam2" class="form-control" rows="3" style="border-radius: 10px; font-size: 0.92rem;" placeholder="invitarte a la próxima inauguración de nuestra tienda Carpintero Express en nuestra nueva sede, con ofertas especiales para ti">invitarte a la próxima inauguración de nuestra tienda Carpintero Express, donde tendremos grandes ofertas especiales de apertura para ti</textarea>
                                                    <small class="text-muted" style="font-size: 0.75rem;">💡 No necesitas poner punto final ni saltos de línea; el sistema lo acopla limpio con <em>"Hoy queremos ..."</em>.</small>
                                                </div>

                                                <!-- Variable {{3}} -->
                                                <div class="mb-1">
                                                    <label class="small fw-bold text-dark mb-1"><span class="badge bg-warning text-dark me-1">{{3}}</span> Eslogan Dinámico <span class="text-muted fw-normal">("Recuerda que en <strong>{{3}}</strong>.")</span></label>
                                                    <input type="text" id="bcParam3" class="form-control" style="border-radius: 10px; font-size: 0.92rem;" value="Carpintero Express estamos cada día más cerca de ti, somos tu parada obligatoria" placeholder="Carpintero Express estamos cada día más cerca de ti, somos tu parada obligatoria">
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Columna Derecha: Simulador WhatsApp en Vivo + Consola de Despacho -->
                                        <div class="col-12 col-lg-5 p-4 d-flex flex-column justify-content-between" style="background: #F8FAFC; max-height: 760px; overflow-y: auto;">
                                            <div>
                                                <div class="d-flex justify-content-between align-items-center mb-2">
                                                    <span class="small fw-bold text-uppercase text-muted"><i class="fa-brands fa-whatsapp text-success me-1"></i> Vista Previa en Vivo (WhatsApp)</span>
                                                    <span class="badge bg-light text-dark border" id="bcPreviewSedeBadge">Sede Oficial</span>
                                                </div>

                                                <!-- Simulador WhatsApp -->
                                                <div class="wa-preview-frame mb-4">
                                                    <div class="wa-preview-header">
                                                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #ffffff; color: #075E54; display: flex; align-items: center; justify-content: center; font-weight: 800;">
                                                            <i class="fa-solid fa-store"></i>
                                                        </div>
                                                        <div class="lh-sm">
                                                            <div class="fw-bold" style="font-size: 0.92rem;" id="waHeaderTitle">Carpintero Express</div>
                                                            <small style="font-size: 0.72rem; color: #D1FAE5;"><i class="fa-solid fa-circle-check me-1"></i>Cuenta de empresa oficial</small>
                                                        </div>
                                                    </div>
                                                    <div class="p-2">
                                                        <div class="wa-preview-bubble">
                                                            <div id="waPreviewBodyText" style="white-space: pre-line;"></div>
                                                            <div class="wa-preview-footer">
                                                                <span>Servicio de mensajería inteligente de STARFI CRM</span>
                                                                <span>10:00 a. m.</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>

                                                <!-- Consola de Ejecución en Tiempo Real -->
                                                <div class="p-3 rounded-3 border bg-white shadow-sm">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <span class="small fw-bold text-dark"><i class="fa-solid fa-bolt text-warning me-1"></i> Consola de Despacho por Lotes</span>
                                                        <span class="badge bg-secondary" id="bcStatusLabel">Listo para enviar</span>
                                                    </div>

                                                    <!-- Barra de Progreso -->
                                                    <div class="progress mb-3" style="height: 12px; border-radius: 999px; background-color: #E2E8F0;">
                                                        <div id="bcProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" role="progressbar" style="width: 0%;"></div>
                                                    </div>

                                                    <!-- Contadores -->
                                                    <div class="row g-2 text-center mb-3">
                                                        <div class="col-4">
                                                            <div class="p-2 rounded bg-light border">
                                                                <div class="small text-muted fw-semibold" style="font-size: 0.72rem;">EN COLA</div>
                                                                <div class="fw-bold fs-5 text-dark" id="bcMetricTotal">0</div>
                                                            </div>
                                                        </div>
                                                        <div class="col-4">
                                                            <div class="p-2 rounded border" style="background: #ECFDF5; border-color: #A7F3D0 !important;">
                                                                <div class="small text-success fw-semibold" style="font-size: 0.72rem;">ENVIADOS</div>
                                                                <div class="fw-bold fs-5 text-success" id="bcMetricSent">0</div>
                                                            </div>
                                                        </div>
                                                        <div class="col-4">
                                                            <div class="p-2 rounded border" style="background: #FEF2F2; border-color: #FECACA !important;">
                                                                <div class="small text-danger fw-semibold" style="font-size: 0.72rem;">FALLIDOS</div>
                                                                <div class="fw-bold fs-5 text-danger" id="bcMetricFailed">0</div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Bitácora en Vivo -->
                                                    <div id="bcLiveLog" class="border rounded p-2 font-monospace" style="height: 145px; overflow-y: auto; background: #0F172A; color: #E2E8F0; font-size: 0.76rem;">
                                                        <div class="text-secondary">&gt; Esperando orden de disparo...</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Botones de Disparo -->
                                            <div class="d-flex gap-2 mt-3 pt-3 border-top">
                                                <button type="button" id="btnStartBroadcast" class="btn btn-starfi-primary flex-grow-1 py-2.5 fw-bold d-flex align-items-center justify-content-center gap-2" style="border-radius: 10px; font-size: 1rem; box-shadow: 0 6px 20px rgba(232, 91, 20, 0.3);">
                                                    <i class="fa-solid fa-paper-plane"></i> Iniciar Difusión Ahora
                                                </button>
                                                <button type="button" id="btnStopBroadcast" class="btn btn-danger py-2.5 px-4 fw-bold" style="border-radius: 10px; display: none;">
                                                    <i class="fa-solid fa-stop me-1"></i> Detener
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- PESTAÑA 2: HISTORIAL DE CAMPAÑAS -->
                                <div class="tab-pane fade p-4" id="bc-history-pane" role="tabpanel" style="min-height: 550px;">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="fw-bold mb-0"><i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Últimas Campañas de Difusión Ejecutadas</h6>
                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="loadBroadcastConfig()">
                                            <i class="fa-solid fa-rotate me-1"></i> Actualizar
                                        </button>
                                    </div>
                                    <div class="table-responsive border rounded-3 bg-white">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Fecha</th>
                                                    <th>Campaña</th>
                                                    <th>Sede Emisora</th>
                                                    <th>Plantilla</th>
                                                    <th>Mensaje ({{2}})</th>
                                                    <th class="text-center">Total</th>
                                                    <th class="text-center">Enviados</th>
                                                    <th class="text-center">Fallidos</th>
                                                    <th>Estado</th>
                                                </tr>
                                            </thead>
                                            <tbody id="bcHistoryTableBody">
                                                <tr><td colspan="10" class="text-center py-4 text-muted">Sin campañas registradas.</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
    </main>

    <!-- JavaScript Local Bootstrap -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_directorio.js"></script>
    <script>
        // Toggle Sidebar Nav
        const toggleBtn = document.getElementById('toggleSidebar');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const sb = document.getElementById('sidebar');
                if (sb) sb.classList.toggle('collapsed');
            });
        }
    </script>
    </div>
</body>
</html>




