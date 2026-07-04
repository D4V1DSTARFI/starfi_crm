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
            text-align: center;
            margin-bottom: 30px;
        }

        .profile-avatar-large img {
            width: 90px;
            height: 90px;
            border-radius: 20px;
            margin-bottom: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
            object-fit: cover;
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
            transition: all 0.2s ease;
        }

        .form-control-custom:focus {
            outline: none;
            border-color: var(--primary);
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(232, 91, 20, 0.1);
        }
        
        /* Inputs Bootstrap en el modal */
        #profPhone, #profPrefix {
            border: 1px solid #E2E8F0;
            background-color: #F8FAFC;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.95rem;
            color: #1E293B;
        }
        #profPrefix {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
            border-right: 0;
        }
        #profPhone {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        #profPhone:focus, #profPrefix:focus {
            border-color: var(--primary);
            box-shadow: none;
            background-color: #fff;
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
            <a href="../directorio/directorio.php" class="nav-item active">
                <i class="fa-solid fa-address-book"></i>
                <span class="nav-text">Directorio 360</span>
            </a>
            <a href="../dashboard/dashboard.php" class="nav-item">
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
        <div class="directory-layout">
            
            <!-- Lista General del Directorio -->
            <section class="directory-list">
                <div class="directory-header">
                    <div>
                        <h2 class="brand-font mb-1" style="font-weight: 600;">Directorio de Clientes</h2>
                        <p class="text-muted" style="font-size: 0.9rem;">Gestión centralizada de contactos y prospectos</p>
                    </div>
                </div>

                <div class="client-table">
                    <!-- Toolbar de la Tabla -->
                    <div class="table-toolbar d-flex justify-content-between align-items-center border-bottom">
                        <div class="search-bar-modern" style="width: 350px;">
                            <i class="fa-solid fa-search text-muted"></i>
                            <input type="text" id="searchClient" placeholder="Buscar por nombre o número...">
                        </div>
                        <button id="btnAddClient" class="btn btn-starfi-primary d-flex align-items-center gap-2" style="border-radius: 8px;">
                            <i class="fa-solid fa-user-plus"></i> Nuevo Cliente
                        </button>
                    </div>

                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="nombre">Cliente <i class="fa-solid fa-sort"></i></th>
                                <th class="sortable" data-sort="telefono">Teléfono <i class="fa-solid fa-sort"></i></th>
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
                <div class="modal-dialog modal-xl modal-dialog-centered">
                    <div class="modal-content overflow-hidden">
                        <div class="modal-header border-0 bg-light">
                            <h5 class="modal-title mb-0 brand-font fw-bold text-starfi-dark"><i class="fa-solid fa-id-card-clip me-2 text-starfi-primary"></i>Ficha de Cliente 360</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        
                        <div class="modal-body p-0">
                            <div class="profile-body-scroll d-flex flex-row" style="height: 650px;">
                    <!-- Columna Izquierda: Datos -->
                    <div class="profile-data-col">
                        <div class="profile-avatar-large">
                            <img id="profAvatarImg" src="https://ui-avatars.com/api/?name=User&background=E85B14&color=fff" alt="Avatar">
                            <h4 class="brand-font fw-bold mb-1" id="profTitleName" style="color: #0F172A;">Nombre Cliente</h4>
                            <span class="badge bg-light text-secondary border" id="profTitleId">ID: CLI-000</span>
                        </div>

                        <div class="form-group">
                            <label>Nombre Comercial / Razón Social</label>
                            <input type="text" id="profName" class="form-control-custom" placeholder="Ej. Empresa S.A.">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Número de WhatsApp</label>
                            <div class="input-group">
                                <select class="form-select" id="profPrefix" style="max-width: 100px; border-right: none;">
                                    <option value="58414">0414</option>
                                    <option value="58424">0424</option>
                                    <option value="58412">0412</option>
                                    <option value="58416">0416</option>
                                    <option value="58426">0426</option>
                                </select>
                                <input type="text" id="profPhone" class="form-control-custom" style="border-top-left-radius: 0; border-bottom-left-radius: 0;" placeholder="1234567">
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
                        <div class="form-group mb-4">
                            <label>Notas Internas</label>
                            <textarea id="profNotes" class="form-control-custom" rows="3" placeholder="Información relevante solo para agentes..."></textarea>
                        </div>
                        
                        <button id="btnSaveProfile" class="btn btn-starfi-primary w-100 py-3" style="border-radius: 12px; font-weight: 600; box-shadow: 0 4px 12px rgba(232, 91, 20, 0.2);">Guardar Cambios</button>
                    </div>

                    <!-- Columna Derecha: Línea de Tiempo (Eventos) -->
                    <div class="profile-timeline-col">
                        <div class="timeline-header">
                            <i class="fa-solid fa-clock-rotate-left me-2 text-starfi-primary"></i>Historial de Eventos
                        </div>
                        <div class="timeline-feed" id="profileTimeline">
                            <!-- JS Inject Timeline -->
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
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
    </script>
</body>
</html>




