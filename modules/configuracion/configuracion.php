<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('configuracion');
$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración | CRM STARFI</title>
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
    <!-- Leaflet CSS for Interactive Map Picker -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <style>
        .config-container {
            flex: 1;
            padding: 30px;
            background-color: var(--bg-main);
            overflow-y: auto;
        }

        .config-card {
            background-color: var(--bg-surface);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }

        .config-card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--starfi-dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .form-control {
            font-size: 0.9rem;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background-color: #F8FAFC;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(232, 91, 20, 0.25);
            background-color: #fff;
        }
        
        .var-tag {
            display: inline-block;
            background-color: #E2E8F0;
            color: var(--text-main);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-family: monospace;
            cursor: pointer;
            margin-right: 5px;
            margin-bottom: 5px;
            transition: background-color 0.2s;
        }
        .var-tag:hover {
            background-color: #CBD5E1;
        }

        .table-config th {
            font-size: 0.8rem;
            color: var(--text-muted);
            text-transform: uppercase;
            background-color: #F8FAFC;
        }
        .table-config td {
            vertical-align: middle;
            font-size: 0.9rem;
        }

        .action-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            transition: color 0.2s;
            padding: 5px;
        }
        .action-btn:hover { color: var(--primary); }
        .action-btn.danger:hover { color: var(--starfi-danger); }

        /* Estilos Premium Pestañas y Modales */
        .nav-tabs .nav-link {
            border: none;
            color: #64748B;
            font-weight: 600;
            padding: 12px 24px;
            border-radius: 10px 10px 0 0;
            transition: all 0.3s ease;
        }
        .nav-tabs .nav-link:hover {
            color: var(--starfi-dark);
            background-color: #F8FAFC;
        }
        .nav-tabs .nav-link.active {
            color: var(--primary);
            background-color: transparent;
            border-bottom: 3px solid var(--primary);
        }
        .modal-content-premium {
            border-radius: 20px;
            border: none;
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }
        .modal-header-premium {
            padding: 20px 30px;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
            background-color: #F8FAFC;
            border-bottom: none;
        }
        .form-control-premium, .form-select-premium {
            border: 1px solid #E2E8F0;
            border-radius: 10px;
            padding: 12px;
            transition: all 0.2s;
            background-color: #F8FAFC;
        }
        .form-control-premium:focus, .form-select-premium:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.1);
            background-color: #ffffff;
        }
    </style>
</head>
<body>
    <?php renderHeader('Configuración del Sistema'); ?>
    <div class="app-container">

    <!-- Sidebar Navigation -->

    <!-- Main Content -->
    <main class="main-content">
        <div class="config-container">
            <div class="mb-4">
                <h2 class="brand-font mb-1" style="font-weight: 600;">Configuración del Sistema</h2>
                <p class="text-muted" style="font-size: 0.9rem;">Gestión de parámetros, sedes y flujos de automatización</p>
            </div>

            <ul class="nav nav-tabs mb-4" id="configTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold" id="sedes-tab" data-bs-toggle="tab" data-bs-target="#sedes" type="button" role="tab">Gestión de Sedes</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="apis-tab" data-bs-toggle="tab" data-bs-target="#apis" type="button" role="tab">Gestión de APIs WhatsApp</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="gema-tab" data-bs-toggle="tab" data-bs-target="#gema" type="button" role="tab"><i class="fa-solid fa-wand-magic-sparkles text-starfi-primary me-1"></i> Agente IA</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="pruebas-tab" data-bs-toggle="tab" data-bs-target="#pruebas" type="button" role="tab"><i class="fa-solid fa-flask text-danger me-1"></i> Pruebas y Diagnóstico</button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="respuestas-tab" data-bs-toggle="tab" data-bs-target="#respuestas" type="button" role="tab"><i class="fa-solid fa-bolt text-warning me-1"></i> Respuestas Rápidas</button>
                </li>
            </ul>

            <div class="tab-content" id="configTabsContent">
                
                <!-- GESTIÓN DE SEDES -->
                <div class="tab-pane fade show active" id="sedes" role="tabpanel">
                    <div class="config-card" style="padding: 0;">
                        <div class="d-flex justify-content-between align-items-center" style="padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,0.04);">
                            <h4 class="config-card-title border-0 pb-0 mb-0"><i class="fa-solid fa-building text-primary me-2"></i> Gestión de Sedes</h4>
                            <button id="btnAddSede" class="btn btn-starfi-primary" style="border-radius: 30px; font-weight: 600; padding: 8px 20px; box-shadow: 0 4px 12px rgba(232, 91, 20, 0.25);">
                                <i class="fa-solid fa-plus me-1"></i> Nueva Sede
                            </button>
                        </div>
                        
                        <!-- Filters Bar (like the screenshot) -->
                        <div class="p-3" style="background-color: #F8FAFC; border-bottom: 1px solid rgba(0,0,0,0.04);">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-search text-muted"></i></span>
                                        <input type="text" class="form-control border-start-0" id="searchSede" placeholder="Buscar por nombre, RIF, dirección...">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="filterEstadoSede">
                                        <option value="">Todos los estados</option>
                                        <option value="ACTIVO">Activos</option>
                                        <option value="INACTIVO">Inactivos</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="filterApiSede">
                                        <option value="">Todas las sedes</option>
                                        <option value="CON_API">Con API configurada</option>
                                        <option value="SIN_API">Sin API configurada</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Cards Container -->
                        <div class="p-4" style="background-color: #F1F5F9; min-height: 400px;">
                            <div class="row g-4" id="sedesCardContainer">
                                <!-- JS Inject -->
                                <div class="col-12 text-center text-muted p-4">Cargando sedes...</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GESTIÓN DE APIS WHATSAPP -->
                <div class="tab-pane fade" id="apis" role="tabpanel">
                    <div class="config-card" style="padding: 0;">
                        <div class="d-flex justify-content-between align-items-center" style="padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,0.04);">
                            <h4 class="config-card-title border-0 pb-0 mb-0"><i class="fa-brands fa-whatsapp text-success me-2"></i> Gestión de APIs WhatsApp</h4>
                            <button id="btnAddAPI" class="btn btn-success" style="border-radius: 30px; font-weight: 600; padding: 8px 20px; box-shadow: 0 4px 12px rgba(25, 135, 84, 0.25);">
                                <i class="fa-solid fa-plus me-1"></i> Nueva API
                            </button>
                        </div>
                               <!-- API Stats & Filters -->
                        <div class="p-3" style="background-color: #F8FAFC; border-bottom: 1px solid rgba(0,0,0,0.04);">
                            <div class="row g-2 mb-3">
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Filtrar por Sede</label>
                                    <select class="form-select" id="filterApiSedeSelect">
                                        <option value="">Todas las sedes</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Estado</label>
                                    <select class="form-select" id="filterApiEstado">
                                        <option value="">Todos</option>
                                        <option value="ACTIVO">Activos</option>
                                        <option value="INACTIVO">Inactivos</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small text-muted mb-1">Búsqueda</label>
                                    <input type="text" class="form-control" id="searchApi" placeholder="Descripción, teléfono...">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button class="btn btn-primary w-100" id="btnSearchApi"><i class="fa-solid fa-search me-2"></i>Buscar</button>
                                </div>
                            </div>
                            
                            <!-- Stats Cards -->
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <div class="card border-success text-center py-2 h-100 shadow-sm" style="border-radius: 8px;">
                                        <h4 class="text-success fw-bold mb-0" id="statApiTotal">0</h4>
                                        <small class="text-muted">API totales</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card border-primary text-center py-2 h-100 shadow-sm" style="border-radius: 8px;">
                                        <h4 class="text-primary fw-bold mb-0" id="statApiActivas">0</h4>
                                        <small class="text-muted">Activas</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card border-warning text-center py-2 h-100 shadow-sm" style="border-radius: 8px;">
                                        <h4 class="text-warning fw-bold mb-0" id="statApiInactivas">0</h4>
                                        <small class="text-muted">Inactivas</small>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="card border-info text-center py-2 h-100 shadow-sm" style="border-radius: 8px;">
                                        <h4 class="text-info fw-bold mb-0" id="statApiSedes">0</h4>
                                        <small class="text-muted">Sedes con API</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Cards Container -->
                        <div class="p-4" style="background-color: #F1F5F9; min-height: 400px;">
                            <div class="row g-4" id="apisCardContainer">
                                <!-- JS Inject -->
                                <div class="col-12 text-center text-muted p-4">Cargando APIs...</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- GEMA AI -->
                <div class="tab-pane fade" id="gema" role="tabpanel">
                    <div class="config-card" style="padding: 0; background: transparent; border: none; box-shadow: none; margin-bottom: 0;">
                        <div class="card border-0 shadow-sm overflow-hidden" style="border-radius: 16px; background-color: #FFFFFF;">
                        
                        <!-- Header Banner Gradient -->
                        <div class="p-4 text-white d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);">
                            <div class="d-flex align-items-center gap-3">
                                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 52px; height: 52px; background: rgba(232, 91, 20, 0.15); border: 2px solid #E85B14;">
                                    <i class="fa-solid fa-wand-magic-sparkles fs-4 text-warning"></i>
                                </div>
                                <div>
                                    <h4 class="fw-bold mb-1 text-white" style="font-size: 1.25rem;">Asistente de Inteligencia Artificial (IA) Multi-Sede</h4>
                                    <p class="text-white-50 small mb-0"><i class="fa-solid fa-shield-halved text-success me-1"></i> Configura la identidad, dirección física e instrucciones independientes por cada sede.</p>
                                </div>
                            </div>
                            <!-- Sede Selector Top Bar -->
                            <div class="d-flex align-items-center gap-2 bg-white bg-opacity-10 p-2 rounded-3" style="backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.15);">
                                <label class="text-white small fw-bold text-nowrap mb-0 ps-1"><i class="fa-solid fa-building me-1 text-warning"></i> SEDE:</label>
                                <select class="form-select form-select-sm fw-bold border-0 text-dark" id="gema_id_sede" style="min-width: 220px; border-radius: 6px;">
                                    <option value="">Cargando sedes...</option>
                                </select>
                            </div>
                        </div>

                        <!-- Card Body Content -->
                        <div class="p-4" style="background-color: #F8FAFC; min-height: 500px;">
                            
                            <!-- Banner Toggle Estado Activo -->
                            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border-left: 5px solid #E85B14 !important; background: #FFFFFF;">
                                <div class="card-body p-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="form-check form-switch ps-0 mb-0">
                                            <input class="form-check-input mt-0 me-2 ms-0" type="checkbox" id="gema_estado" style="width: 3.2rem; height: 1.6rem; cursor: pointer;" checked>
                                        </div>
                                        <div>
                                            <h6 class="fw-bold text-dark mb-0" style="font-size: 1rem;">Estado del Bot Conversacional para esta Sede</h6>
                                            <p class="text-muted small mb-0">Habilita la atención automática por IA en las líneas de WhatsApp asociadas a esta tienda.</p>
                                        </div>
                                    </div>
                                    <span id="gema_estado_badge" class="badge bg-success bg-opacity-10 text-success px-3 py-2 rounded-pill fw-bold fs-7">
                                        <i class="fa-solid fa-circle-check me-1"></i> BOT ACTIVO
                                    </span>
                                </div>
                            </div>

                            <div class="row g-4">
                                <!-- Columna Izquierda: Formulario Principales (col-lg-7) -->
                                <div class="col-lg-7">
                                    
                                    <!-- Card 1: Identidad y Ubicación -->
                                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border: 1px solid #E2E8F0 !important;">
                                        <div class="card-header bg-white border-bottom border-light py-3 px-4">
                                            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-robot text-primary me-2"></i> 1. Identidad y Ubicación del Asistente</h6>
                                        </div>
                                        <div class="card-body p-4">
                                            <div class="row g-3">
                                                <!-- Nombre del Asistente -->
                                                <div class="col-12">
                                                    <label class="form-label text-dark fw-bold small text-uppercase mb-1">NOMBRE DE LA INTELIGENCIA ARTIFICIAL (IA)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light text-muted border-end-0"><i class="fa-solid fa-id-badge"></i></span>
                                                        <input type="text" class="form-control form-control-premium border-start-0" id="gema_nombre" placeholder="Ej: BB, Gema, Asistente STARFI" value="Gema">
                                                    </div>
                                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;">Nombre para su saludo inicial (ej: <i>'Hola, soy BB la asistente virtual...'</i>).</small>
                                                </div>
                                                <!-- Enlace de Ubicación GPS (Google Maps) -->
                                                <div class="col-12 mt-2">
                                                    <label class="form-label text-dark fw-bold small text-uppercase mb-1">ENLACE DE UBICACIÓN GPS (GOOGLE MAPS)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light text-muted border-end-0"><i class="fa-solid fa-map-location-dot text-success fs-5" style="cursor: pointer;" onclick="abrirModalMapaGPS()" title="Abrir selector de mapa"></i></span>
                                                        <input type="url" class="form-control form-control-premium border-start-0" id="gema_link_gps" placeholder="Ej: https://www.google.com/maps?q=10.4806,-66.9036">
                                                        <button type="button" class="btn btn-primary fw-bold px-3" onclick="abrirModalMapaGPS()" style="border-top-right-radius: 8px; border-bottom-right-radius: 8px;">
                                                            <i class="fa-solid fa-earth-americas me-1 text-warning"></i> Seleccionar en Mapa
                                                        </button>
                                                    </div>
                                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;"><i class="fa-solid fa-hand-pointer text-primary me-1"></i> Haz clic en <strong>'Seleccionar en Mapa'</strong> para fijar la ubicación moviéndote libremente por el mapa GPS interactivo.</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Card 2: Motor de IA y API Keys -->
                                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border: 1px solid #E2E8F0 !important;">
                                        <div class="card-header bg-white border-bottom border-light py-3 px-4">
                                            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-microchip text-warning me-2"></i> 2. Configuración del Motor de Inteligencia Artificial</h6>
                                        </div>
                                        <div class="card-body p-4">
                                            <div class="row g-3">
                                                <!-- Modelo de IA -->
                                                <div class="col-md-6">
                                                    <label class="form-label text-dark fw-bold small text-uppercase mb-1">MODELO DE IA</label>
                                                    <select class="form-select form-select-premium" id="gema_modelo_ia">
                                                        <option value="gemini-3.6-flash" selected>Gemini 3.6 Flash (Recomendado)</option>
                                                        <option value="gemini-flash-latest">Gemini Flash Latest</option>
                                                        <option value="gemini-2.0-flash">Gemini 2.0 Flash</option>
                                                        <option value="gemma-4-31b-it">Gemma 4 31B IT</option>
                                                        <option value="gemini-3.5-flash">Gemini 3.5 Flash</option>
                                                    </select>
                                                </div>

                                                <!-- Temperatura -->
                                                <div class="col-md-6">
                                                    <label class="form-label text-dark fw-bold small text-uppercase mb-1">TEMPERATURA (0.0 - 1.0)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light text-muted border-end-0"><i class="fa-solid fa-sliders"></i></span>
                                                        <input type="number" class="form-control form-control-premium border-start-0" id="gema_temperatura" min="0.0" max="1.0" step="0.1" value="0.4">
                                                    </div>
                                                </div>

                                                <!-- API Key Google Gemini -->
                                                <div class="col-12 mt-3">
                                                    <label class="form-label text-dark fw-bold small text-uppercase mb-1">GEMINI API KEY (GOOGLE AI STUDIO)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-light text-muted border-end-0"><i class="fa-solid fa-key text-success"></i></span>
                                                        <input type="password" class="form-control form-control-premium border-start-0 border-end-0" id="gema_token" placeholder="AIzaSy...................">
                                                        <button class="btn btn-outline-secondary" type="button" onclick="togglePasswordVisibility('gema_token')">
                                                            <i class="fa-solid fa-eye" id="gema_token_eye"></i>
                                                        </button>
                                                    </div>
                                                    <small class="text-muted d-block mt-1" style="font-size: 0.75rem;"><i class="fa-solid fa-lock me-1"></i> Credencial encriptada. Temperatura 0.4 es óptima para respuestas precisas.</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Card 3: Reglas de Comportamiento (Prompt de Sistema) -->
                                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; border: 1px solid #E2E8F0 !important;">
                                        <div class="card-header bg-white border-bottom border-light py-3 px-4 d-flex justify-content-between align-items-center">
                                            <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-terminal text-info me-2"></i> 3. Reglas de Comportamiento (Prompt de Sistema)</h6>
                                        </div>
                                        <div class="card-body p-4">
                                            <textarea class="form-control form-control-premium font-monospace" id="gema_prompt" rows="7" placeholder="Eres el asistente virtual inteligente de esta sede..." style="font-size: 0.88rem; line-height: 1.5; resize: vertical; background-color: #FAFAFA;"></textarea>
                                            <div class="d-flex justify-content-between align-items-center mt-2">
                                                <small class="text-muted" style="font-size: 0.78rem;"><i class="fa-solid fa-circle-info me-1"></i> Define tono, saludos y políticas comerciales para las líneas de esta sede.</small>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Botones de Acción principales -->
                                    <div class="d-flex flex-wrap gap-3 p-3 bg-white shadow-sm rounded-3 border" style="border-color: #E2E8F0 !important;">
                                        <button id="btnSaveGema" class="btn btn-starfi-primary fw-bold px-4 py-2" style="border-radius: 8px; box-shadow: 0 4px 10px rgba(232, 91, 20, 0.25);">
                                            <i class="fa-solid fa-floppy-disk me-2"></i> Guardar Configuración IA
                                        </button>
                                        <button id="btnTestGema" class="btn btn-outline-secondary fw-bold px-3 py-2" style="border-radius: 8px;">
                                            <i class="fa-solid fa-bolt me-2 text-warning"></i> Probar Conexión API
                                        </button>
                                    </div>

                                </div>
                                
                                <!-- Columna Derecha: Vista Previa en Vivo WhatsApp + Ayuda (col-lg-5) -->
                                <div class="col-lg-5">
                                    
                                    <!-- WhatsApp Live Preview Card -->
                                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 16px; overflow: hidden; border: 1px solid #CBD5E1 !important;">
                                        <!-- WhatsApp Header Bar -->
                                        <div class="p-3 d-flex align-items-center gap-3" style="background-color: #075E54; color: white;">
                                            <div class="position-relative">
                                                <div class="rounded-circle bg-white text-success fw-bold d-flex align-items-center justify-content-center" style="width: 42px; height: 42px; font-size: 1.1rem;">
                                                    <i class="fa-solid fa-robot text-teal"></i>
                                                </div>
                                                <span class="position-absolute bottom-0 end-0 bg-success border border-white rounded-circle" style="width: 10px; height: 10px;"></span>
                                            </div>
                                            <div>
                                                <h6 class="fw-bold mb-0 text-white" id="preview_agent_name_display" style="font-size: 0.95rem;">Gema (IA)</h6>
                                                <small class="text-white-50" id="preview_sede_name_display" style="font-size: 0.75rem;">Sede STARFI CODE • En línea</small>
                                            </div>
                                        </div>

                                        <!-- WhatsApp Chat Background & Bubbles -->
                                        <div class="p-3" style="background-color: #E5DDD5; background-image: radial-gradient(#CBD5E1 1px, transparent 1px); background-size: 16px 16px; min-height: 280px;">
                                            
                                            <!-- Message 1: Customer -->
                                            <div class="d-flex justify-content-start mb-3">
                                                <div class="p-3 rounded-3 shadow-sm bg-white text-dark" style="max-width: 85%; border-top-left-radius: 0 !important; font-size: 0.85rem;">
                                                    <span class="fw-bold text-primary d-block mb-1" style="font-size: 0.75rem;">Cliente</span>
                                                    ¡Hola! ¿Dónde están ubicados?
                                                    <span class="d-block text-end text-muted mt-1" style="font-size: 0.65rem;">10:42 AM</span>
                                                </div>
                                            </div>

                                            <!-- Message 2: IA Live Response Preview -->
                                            <div class="d-flex justify-content-end mb-2">
                                                <div class="p-3 rounded-3 shadow-sm text-dark" style="background-color: #DCF8C6; max-width: 90%; word-break: break-word; overflow-wrap: anywhere; border-top-right-radius: 0 !important; font-size: 0.85rem; line-height: 1.4;">
                                                    <span class="fw-bold text-success d-block mb-1" style="font-size: 0.75rem;" id="preview_bubble_name">Gema Bot</span>
                                                    <span id="preview_chat_response" style="word-break: break-word; overflow-wrap: anywhere;">
                                                        ¡Buenas tardes! 🖐️✨ Soy <strong id="prev_bot_name">Gema</strong>, la asistente virtual de <strong id="prev_sede_name">STARFI CODE</strong>.<br><br>
                                                        🗺️ <strong>Ubicación GPS (Google Maps):</strong> <span id="prev_bot_gps" class="text-primary fw-bold" style="word-break: break-all; overflow-wrap: anywhere;">https://www.google.com/maps?q=10.48060,-66.90360</span><br><br>
                                                        ¿En qué te puedo colaborar el día de hoy?
                                                    </span>
                                                    <span class="d-block text-end text-muted mt-1" style="font-size: 0.65rem;">10:42 AM <i class="fa-solid fa-check-double text-primary"></i></span>
                                                </div>
                                            </div>

                                        </div>

                                        <div class="p-2 text-center bg-light border-top">
                                            <small class="text-muted fw-semibold" style="font-size: 0.72rem;"><i class="fa-solid fa-eye text-primary me-1"></i> Vista Previa en Vivo de la Respuesta de WhatsApp</small>
                                        </div>
                                    </div>

                                    <!-- Card de Guía y Consejos -->
                                    <div class="card shadow-sm border-0" style="border-radius: 12px; background-color: #FFFFFF; border: 1px solid #E2E8F0 !important;">
                                        <div class="card-body p-4">
                                            <h6 class="fw-bold text-dark mb-3" style="font-size: 0.95rem;">
                                                <i class="fa-regular fa-lightbulb text-warning me-2 fs-5"></i> Consejos de Operación por Sede
                                            </h6>
                                            
                                            <ul class="list-unstyled mb-0" style="font-size: 0.83rem; color: #475569; line-height: 1.6;">
                                                <li class="mb-3 d-flex align-items-start">
                                                    <i class="fa-solid fa-circle-check text-success mt-1 me-2"></i>
                                                    <div><strong>Aislamiento por Sede:</strong> Cada tienda funciona de forma independiente con sus propias líneas de WhatsApp.</div>
                                                </li>
                                                <li class="mb-3 d-flex align-items-start">
                                                    <i class="fa-solid fa-headset text-primary mt-1 me-2"></i>
                                                    <div><strong>Transferencia a Vendedor:</strong> Al solicitar atención humana, la IA detendrá el bot y transferirá el chat a la bandeja.</div>
                                                </li>
                                                <li class="d-flex align-items-start">
                                                    <i class="fa-solid fa-bell text-warning mt-1 me-2"></i>
                                                    <div><strong>Notificaciones al Administrador:</strong> Cuando un cliente escriba, la plantilla de notificación llegará directamente a tu WhatsApp.</div>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                    <!-- Log de respuesta de prueba -->
                                    <div id="testGemaLog" class="card shadow-sm d-none mt-3" style="border-radius: 12px; background-color: #F0FDF4; border: 1px solid #BBF7D0 !important;">
                                        <div class="card-body p-3">
                                            <h6 class="fw-bold text-success mb-2" style="font-size: 0.9rem;"><i class="fa-solid fa-circle-check me-1"></i> Respuesta de Prueba:</h6>
                                            <p id="testGemaContent" class="mb-0 text-dark" style="font-size: 0.85rem; white-space: pre-line;"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

                <!-- PRUEBAS Y DIAGNÓSTICO -->
                <div class="tab-pane fade" id="pruebas" role="tabpanel">
                    <div class="config-card" style="padding: 24px;">
                        <h4 class="config-card-title"><i class="fa-solid fa-flask text-danger me-2"></i> Módulo de Pruebas y Diagnóstico</h4>
                        <p class="text-muted" style="font-size: 0.9rem;">Ejecuta pruebas en tiempo real y diagnostica el estado del sistema.</p>

                        <div class="row g-4 mt-2">
                            <!-- Card 1: Diagnóstico de Sistema -->
                            <div class="col-md-6">
                                <div class="card h-100 shadow-sm border-0" style="border-radius: 12px; border: 1px solid #E2E8F0 !important;">
                                    <div class="card-body p-4">
                                        <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-circle-check text-success me-2"></i> Auto-Diagnóstico de Base de Datos y Archivos</h5>
                                        <p class="text-muted small mb-4">Verifica que las tablas críticas existan en la base de datos, que los controladores estén en su lugar y comprueba la conectividad del sistema.</p>
                                        <button class="btn btn-outline-success fw-bold" onclick="ejecutarDiagnostico()" style="border-radius: 8px;">
                                            <i class="fa-solid fa-circle-play me-2"></i> Ejecutar Diagnóstico
                                        </button>
                                        <div id="resultadoDiagnostico" class="mt-3"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Card 2: Simulador de Mensaje Entrante -->
                            <div class="col-md-6">
                                <div class="card h-100 shadow-sm border-0" style="border-radius: 12px; border: 1px solid #E2E8F0 !important;">
                                    <div class="card-body p-4">
                                        <h5 class="fw-bold text-dark mb-3"><i class="fa-brands fa-whatsapp text-primary me-2"></i> Simular Mensaje Entrante (Bandeja)</h5>
                                        <p class="text-muted small mb-4">Simula que un cliente ha enviado un mensaje a tu webhook de WhatsApp. Esto te permite verificar la recepción y visualización instantánea en el Centro de Mensajes.</p>
                                        <button class="btn btn-outline-primary fw-bold" onclick="ejecutarSimulador()" style="border-radius: 8px;">
                                            <i class="fa-solid fa-paper-plane me-2"></i> Simular Recepción de Mensaje
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Card 3: Formulario de Envío de Notificación de Prueba (Salida) -->
                            <div class="col-12">
                                <div class="card shadow-sm border-0" style="border-radius: 12px; border: 1px solid #E2E8F0 !important;">
                                    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                                        <h5 class="fw-bold text-dark mb-1"><i class="fa-solid fa-paper-plane text-warning me-2"></i> Enviar Notificación Transaccional de Prueba (Salida)</h5>
                                        <p class="text-muted small">Esta prueba envía una plantilla real de confirmación de compra usando la API externa configurada en el sistema.</p>
                                    </div>
                                    <div class="card-body p-4">
                                        <form id="formNotifPrueba">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Teléfono Destino *</label>
                                                    <input type="text" class="form-control form-control-premium" id="notif_telefono" required placeholder="Ej: 584241660944" value="584241660944">
                                                    <small class="text-muted">Código de país sin el signo '+' ni espacios (ej: 584241660944).</small>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Nombre Cliente *</label>
                                                    <input type="text" class="form-control form-control-premium" id="notif_cliente" required value="Cliente de Prueba">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Monto Total *</label>
                                                    <input type="text" class="form-control form-control-premium" id="notif_monto" required value="250.00">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Correlativo Factura *</label>
                                                    <input type="text" class="form-control form-control-premium" id="notif_correlativo" required value="TEST-99999">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Asesor de Ventas *</label>
                                                    <input type="text" class="form-control form-control-premium" id="notif_asesor" required value="Asesor Test">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Teléfono Asesor *</label>
                                                    <input type="text" class="form-control form-control-premium" id="notif_tel_asesor" required value="584120000000">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Nombre Empresa *</label>
                                                    <input type="text" class="form-control form-control-premium" id="notif_empresa" required value="STARFI CRM">
                                                </div>
                                            </div>
                                            <div class="mt-4">
                                                <button type="button" class="btn btn-starfi-primary fw-bold" onclick="enviarNotificacionPrueba()" style="border-radius: 8px;">
                                                    <i class="fa-solid fa-paper-plane me-2"></i> Enviar Notificación de Prueba
                                                </button>
                                            </div>
                                        </form>
                                        <div id="resultadoNotifPrueba" class="mt-3"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RESPUESTAS RÁPIDAS -->
                <div class="tab-pane fade" id="respuestas" role="tabpanel">
                    <div class="config-card" style="padding: 0;">
                        <div class="d-flex justify-content-between align-items-center" style="padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,0.04);">
                            <h4 class="config-card-title border-0 pb-0 mb-0"><i class="fa-solid fa-bolt text-warning me-2"></i> Gestión de Respuestas Rápidas</h4>
                            <button class="btn btn-warning text-dark fw-bold" style="border-radius: 30px; padding: 8px 20px;" onclick="addRespuestaRapida()">
                                <i class="fa-solid fa-plus me-1"></i> Nueva Respuesta
                            </button>
                        </div>
                        <div class="p-4" style="background-color: #F1F5F9; min-height: 400px;">
                            <div class="row g-4" id="respuestasContainer">
                                <!-- Respuestas se cargarán aquí vía JS -->
                                <div class="col-12 text-center text-muted">Cargando respuestas rápidas...</div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- Modal Añadir Sede -->
    <div class="modal fade" id="modalSede" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium">
                    <h5 class="modal-title brand-font fw-bold text-starfi-dark mb-0"><i class="fa-solid fa-building me-2 text-starfi-primary"></i>Nueva Sede e Integración</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formSede">
                        <input type="hidden" id="id_sede" name="id_sede">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Razón Social <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="razon_social" name="razon_social" required placeholder="Ej: Caracas - Principal">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">RIF <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="rif" name="rif" required placeholder="J-12345678-9">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Teléfono Principal</label>
                                <input type="text" class="form-control form-control-premium" id="telefono" name="telefono" placeholder="+58 412 1234567">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Email</label>
                                <input type="email" class="form-control form-control-premium" id="email" name="email" placeholder="sede@empresa.com">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Dirección Completa</label>
                                <textarea class="form-control form-control-premium" id="direccion" name="direccion" rows="2"></textarea>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Ciudad</label>
                                <input type="text" class="form-control form-control-premium" id="ciudad" name="ciudad">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Estado</label>
                                <input type="text" class="form-control form-control-premium" id="estado_loc" name="estado_loc">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Código Postal</label>
                                <input type="text" class="form-control form-control-premium" id="codigo_postal" name="codigo_postal">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Estado de la Sede</label>
                                <select class="form-select form-select-premium" id="estado_sede" name="estado_sede">
                                    <option value="ACTIVO">Activo</option>
                                    <option value="INACTIVO">Inactivo</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Tipo de Sede</label>
                                <select class="form-select form-select-premium" id="tipo_sede" name="tipo_sede">
                                    <option value="PRINCIPAL">Principal</option>
                                    <option value="SUCURSAL">Sucursal</option>
                                    <option value="ALMACEN">Almacén</option>
                                    <option value="OFICINA">Oficina</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Observaciones</label>
                                <textarea class="form-control form-control-premium" id="observaciones" name="observaciones" rows="2"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-top-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-starfi-primary fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" id="btnSaveSede">Guardar Sede</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Modal Añadir API WhatsApp -->
    <div class="modal fade" id="modalAPI" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium" style="background-color: #F0FDF4;">
                    <h5 class="modal-title brand-font fw-bold text-success mb-0"><i class="fa-brands fa-whatsapp me-2"></i>Nueva API WhatsApp</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formAPI">
                        <input type="hidden" id="id_api" name="id_api">
                        
                        <!-- Toggle de Experiencia -->
                        <div class="row g-3 mb-3 pb-3 border-bottom" id="api_experience_toggle">
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-question me-1"></i> ¿Estado de la línea en Meta?</label>
                                <div class="d-flex gap-4 mt-2 p-3 rounded" style="background-color: #F8FAFC; border: 1px dashed #CBD5E1;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="api_type_meta" id="api_type_existing" value="existing" checked onchange="toggleApiExperience()">
                                        <label class="form-check-label fw-bold text-dark cursor-pointer" for="api_type_existing">
                                            Ya registrado y verificado
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="api_type_meta" id="api_type_new" value="new" onchange="toggleApiExperience()">
                                        <label class="form-check-label fw-bold text-dark cursor-pointer" for="api_type_new">
                                            Nuevo (Requiere PIN/Alta)
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Sección de Autenticación Meta -->
                        <div class="row g-3 mb-3 p-3 rounded" style="background-color: #F0FDF4; border: 1px solid #BBF7D0;">
                            <div class="col-12" id="waba_container">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">WABA ID (WhatsApp Business Account ID)</label>
                                <div class="input-group">
                                    <input type="text" class="form-control form-control-premium" id="api_id_negocio" name="api_id_negocio" placeholder="1111084364465615">
                                    <button class="btn btn-success fw-bold" type="button" id="btnFetchMeta" onclick="fetchMetaApis()" style="border-radius: 0 10px 10px 0;"><i class="fa-solid fa-cloud-arrow-down me-1"></i> Buscar Líneas</button>
                                </div>
                                <small class="text-muted d-block mt-1">Requerido para autocompletar números.</small>
                            </div>
                            
                            <div class="col-12 d-none">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Token de Meta (Permanente) <span class="text-danger">*</span></label>
                                <textarea class="form-control form-control-premium" id="api_token_meta" name="api_token_meta" rows="2" placeholder="EAAxxxxxxxxxx..." style="font-family: monospace;"></textarea>
                            </div>

                            <!-- Campos exclusivos para Alta (Número Nuevo) -->
                            <div class="col-md-6" id="phone_id_manual_container" style="display: none;">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">ID de Teléfono (Meta) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="api_telefono_meta_manual" placeholder="123456789012345">
                            </div>
                            <div class="col-md-6" id="pin_container" style="display: none;">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">PIN de 6 dígitos <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="text" class="form-control form-control-premium" id="api_pin_meta" placeholder="123456" maxlength="6">
                                    <button class="btn btn-primary fw-bold" type="button" id="btnRegisterMeta" onclick="registerMetaPhone()" style="border-radius: 0 10px 10px 0;"><i class="fa-solid fa-check-circle me-1"></i> Dar Alta</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selección de Número Encontrado (Para Existing) -->
                        <div class="row g-3 mb-3" id="select_number_container" style="display: none;">
                            <div class="col-12">
                                <label class="form-label text-success fw-bold text-uppercase" style="font-size: 0.75rem;"><i class="fa-solid fa-list-check me-1"></i> Números Encontrados en Meta</label>
                                <select class="form-select form-select-premium border-success" id="api_select_meta" onchange="autoFillMetaNumber()">
                                    <option value="">Seleccione un número de la lista...</option>
                                </select>
                            </div>
                        </div>

                        <!-- Campos Finales de Registro Local CRM -->
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Sede Asignada <span class="text-danger">*</span></label>
                                <select class="form-select form-select-premium" id="api_sede" name="api_sede" required>
                                    <option value="">Seleccione una sede...</option>
                                </select>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Descripción Interna <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="api_descripcion" name="api_descripcion" required placeholder="Ej: Ventas - Sede Central">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Teléfono de Negocio <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="api_telefono" name="api_telefono" required placeholder="+58 412 1234567">
                            </div>
                            <div class="col-md-6" id="phone_id_readonly_container">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">ID de Teléfono (Meta) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium bg-light" id="api_telefono_meta" name="api_telefono_meta" required readonly placeholder="Se autocompleta">
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Estado Operativo</label>
                                <select class="form-select form-select-premium" id="api_estado" name="api_estado">
                                    <option value="ACTIVO">Activo (Conectado)</option>
                                    <option value="INACTIVO">Inactivo</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Límite Solicitudes (Opcional)</label>
                                <input type="number" class="form-control form-control-premium" id="api_limite" name="api_limite" placeholder="1000">
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Observaciones</label>
                                <textarea class="form-control form-control-premium" id="api_observacion" name="api_observacion" rows="2"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-top-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" id="btnSaveAPI">Guardar API</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Probar Conexión -->
    <div class="modal fade" id="modalProbarAPI" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium">
                    <h5 class="modal-title brand-font fw-bold text-starfi-dark mb-0"><i class="fa-solid fa-lightning text-warning me-2"></i> Probar Conexión API</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" id="id_api_test">
                    
                    <div class="mb-4">
                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Número de Prueba</label>
                        <input type="text" class="form-control form-control-premium" id="telefono_test" placeholder="+58 412 1234567" value="+58 414 1209548">
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Mensaje de Prueba</label>
                        <textarea class="form-control form-control-premium" id="mensaje_test" rows="3">🧪 Mensaje de prueba desde STARFI WhatsApp API.
Si recibes este mensaje, la configuración es correcta.</textarea>
                    </div>
                    
                    <div id="resultadoTest"></div>
                </div>
                <div class="modal-footer border-top-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-info text-white fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" onclick="ejecutarPruebaAPI()">
                        <i class="fa-solid fa-paper-plane me-1"></i> Enviar Prueba
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Gestión de Plantillas Meta -->
    <div class="modal fade" id="modalPlantillasMeta" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium bg-starfi-primary text-white">
                    <h5 class="modal-title brand-font fw-bold mb-0"><i class="fa-solid fa-layer-group me-2"></i> Plantillas de Meta (WhatsApp)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light">
                    <input type="hidden" id="plantillas_id_sede">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 class="fw-bold text-dark mb-0"><i class="fa-solid fa-list text-muted me-2"></i>Lista de Plantillas Sincronizadas</h6>
                        <div>
                            <button type="button" class="btn btn-success shadow-sm rounded-pill fw-bold me-2" onclick="sincronizarPlantillasMeta()">
                                <i class="fa-solid fa-rotate me-1"></i> Sincronizar Plantillas
                            </button>
                            <button type="button" class="btn btn-warning shadow-sm rounded-pill fw-bold me-2" onclick="crearPlantillaCSAT()">
                                <i class="fa-solid fa-star me-1"></i> Auto-Crear Plantilla CSAT
                            </button>
                            <button type="button" class="btn btn-info text-white shadow-sm rounded-pill fw-bold me-2" onclick="crearPlantillaNotificacionInterna()">
                                <i class="fa-solid fa-bell me-1"></i> Auto-Crear Plantilla Notificación Interna
                            </button>
                            <button type="button" class="btn btn-primary shadow-sm rounded-pill fw-bold" onclick="mostrarCrearPlantilla()">
                                <i class="fa-solid fa-plus me-1"></i> Nueva Plantilla
                            </button>
                        </div>
                    </div>
                    <div id="vistaListaPlantillas">
                        <div class="table-responsive">
                            <table class="table table-hover table-borderless align-middle bg-white rounded-3 shadow-sm" id="tablaPlantillasMeta">
                                <thead class="table-light text-muted" style="font-size: 0.85rem;">
                                    <tr>
                                        <th class="py-3 px-4 rounded-start">NOMBRE</th>
                                        <th class="py-3">CATEGORÍA</th>
                                        <th class="py-3">IDIOMA</th>
                                        <th class="py-3">ESTADO</th>
                                        <th class="py-3 text-center rounded-end">ACCIONES</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="5" class="text-center py-4 text-muted">Cargando plantillas desde Meta...</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- VISTA: Crear Nueva Plantilla -->
                    <div id="vistaCrearPlantilla" style="display: none;">
                        <div class="card border-0 shadow-sm rounded-3">
                            <div class="card-body p-4">
                                <h6 class="fw-bold mb-4 border-bottom pb-2">Diseñador Básico de Plantilla</h6>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Nombre de la Plantilla</label>
                                        <input type="text" class="form-control form-control-premium" id="new_template_name" placeholder="ej: confirmacion_compra" oninput="this.value = this.value.toLowerCase().replace(/[^a-z0-9_]/g, '')">
                                        <small class="text-muted">Solo minúsculas, números y guiones bajos (_).</small>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Categoría</label>
                                        <select class="form-select form-select-premium" id="new_template_category">
                                            <option value="UTILITY">Utilidad (Ej: Recibos)</option>
                                            <option value="MARKETING">Marketing</option>
                                            <option value="AUTHENTICATION">Autenticación (OTP)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Idioma</label>
                                        <select class="form-select form-select-premium" id="new_template_lang">
                                            <option value="es">Español</option>
                                            <option value="en_US">Inglés (US)</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-12 mt-4">
                                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Cuerpo del Mensaje (Body)</label>
                                        <textarea class="form-control form-control-premium" id="new_template_body" rows="4" placeholder="Hola {{1}}, tu pedido {{2}} ha sido enviado."></textarea>
                                        <small class="text-muted">Usa {{1}}, {{2}} para definir variables dinámicas.</small>
                                    </div>
                                </div>
                                <div class="mt-4 pt-3 border-top text-end">
                                    <button type="button" class="btn btn-light rounded-pill px-4 me-2 fw-bold" onclick="mostrarListaPlantillas()">Cancelar</button>
                                    <button type="button" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm" onclick="guardarNuevaPlantilla()">Enviar a Meta</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- Modal Añadir Respuesta Rápida -->
    <div class="modal fade" id="modalRespuestaRapida" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content modal-content-premium">
                <div class="modal-header modal-header-premium">
                    <h5 class="modal-title brand-font fw-bold text-starfi-dark mb-0"><i class="fa-solid fa-bolt text-warning me-2"></i>Nueva Respuesta Rápida</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formRespuesta">
                        <input type="hidden" id="id_respuesta" name="id_respuesta">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Sede <span class="text-danger">*</span></label>
                                <select class="form-select form-select-premium" id="resp_id_sede" name="id_sede" required>
                                    <option value="">Seleccione una sede...</option>
                                    <!-- Options injected via JS -->
                                </select>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Título Corto <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-premium" id="resp_titulo" name="titulo" required placeholder="Ej: Saludo inicial">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Mensaje Completo <span class="text-danger">*</span></label>
                                <textarea class="form-control form-control-premium" id="resp_mensaje" name="mensaje" rows="4" required placeholder="Hola, soy el asesor asignado. ¿En qué puedo ayudarte?"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-top-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning text-dark fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" id="btnSaveRespuesta">Guardar Respuesta</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Selector de Ubicación GPS en Mapa Interactivo -->
    <div class="modal fade" id="modalMapPicker" tabindex="-1" aria-labelledby="modalMapPickerLabel" aria-hidden="true" style="z-index: 1060;">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <!-- Modal Header -->
                <div class="modal-header text-white p-3 px-4" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                            <i class="fa-solid fa-map-location-dot fs-5"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold text-white mb-0" id="modalMapPickerLabel" style="font-size: 1.1rem;">Seleccionar Ubicación GPS en el Mapa</h5>
                            <small class="text-white-50" style="font-size: 0.75rem;">Haz clic o arrastra el marcador en el mapa para fijar el punto exacto de la tienda.</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <!-- Modal Body -->
                <div class="modal-body p-0 position-relative">
                    <!-- Search bar inside map -->
                    <div class="p-3 bg-light border-bottom">
                        <div class="input-group">
                            <span class="input-group-text bg-white text-muted border-end-0"><i class="fa-solid fa-search"></i></span>
                            <input type="text" class="form-control border-start-0" id="mapSearchInput" placeholder="Buscar zona o ciudad (ej: Los Teques, Altamira, Maracay...)" onkeypress="if(event.key==='Enter'){ event.preventDefault(); buscarEnMapa(); }">
                            <button class="btn btn-primary fw-bold px-3" type="button" onclick="buscarEnMapa()">
                                <i class="fa-solid fa-magnifying-glass me-1"></i> Buscar
                            </button>
                        </div>
                    </div>

                    <!-- Leaflet Map Container -->
                    <div id="leafletMapContainer" style="height: 400px; width: 100%; z-index: 1;"></div>

                    <!-- Coords Live Bar -->
                    <div class="p-3 bg-white border-top d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2" style="font-size: 0.85rem;">
                        <div>
                            <span class="fw-bold text-dark"><i class="fa-solid fa-location-pin text-danger me-1"></i> Coordenadas GPS:</span>
                            <span id="selectedCoordsText" class="badge bg-light text-dark border font-monospace ms-1" style="font-size: 0.85rem;">10.48060, -66.90360</span>
                        </div>
                        <div class="text-muted small text-truncate" style="max-width: 360px;">
                            <i class="fa-solid fa-building me-1 text-primary"></i> <span id="reverseGeocodeText">Mueve el pin marcador para fijar la posición</span>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-outline-secondary fw-bold px-3" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success fw-bold px-4" id="btnConfirmMapLocation" onclick="confirmarUbicacionMapa()">
                        <i class="fa-solid fa-check me-2"></i> Usar esta Ubicación GPS
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Local Bootstrap -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <!-- Leaflet JS for Interactive Map -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="funciones_configuracion.js?v=<?= time() ?>"></script>
    <script>
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
    </script>
    </div>
</body>
</html>




