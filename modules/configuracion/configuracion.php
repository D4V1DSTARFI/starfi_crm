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
                    <div class="config-card" style="padding: 0;">
                        <div class="d-flex justify-content-between align-items-center" style="padding: 20px 24px; border-bottom: 1px solid rgba(0,0,0,0.04);">
                            <h4 class="config-card-title border-0 pb-0 mb-0"><i class="fa-solid fa-wand-magic-sparkles text-starfi-primary me-2"></i> Asistente de Inteligencia Artificial (Gema)</h4>
                        </div>

                        <div class="p-4" style="background-color: #F8FAFC; min-height: 400px;">
                            <div class="row g-4">
                                <!-- Columna Izquierda: Ajustes Principales -->
                                <div class="col-md-7">
                                    
                                    <!-- Toggle de activación -->
                                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 8px; border: 1px solid #E2E8F0 !important;">
                                        <div class="card-body">
                                            <div class="form-check form-switch d-flex align-items-start ps-0">
                                                <input class="form-check-input mt-1 me-3 ms-0" type="checkbox" id="gema_estado" style="width: 2.8rem; height: 1.4rem; cursor: pointer; flex-shrink: 0;" checked>
                                                <div>
                                                    <label class="form-check-label fw-bold text-dark mb-1" for="gema_estado" style="font-size: 0.95rem;">Activar Agente Conversacional IA (Gema)</label>
                                                    <p class="text-muted mb-0" style="font-size: 0.8rem; line-height: 1.4;">Cuando esté activo, el chatbot responderá automáticamente usando Inteligencia Artificial (Gemini) en WhatsApp en lugar del flujo rígido tradicional.</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Nombre -->
                                    <div class="mb-4">
                                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">NOMBRE DEL AGENTE VIRTUAL</label>
                                        <input type="text" class="form-control form-control-premium" id="gema_nombre" placeholder="Gema" value="Gema" style="background-color: #F8FAFC;">
                                    </div>
                                    
                                    <!-- API Key -->
                                    <div class="mb-4">
                                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">GEMINI API KEY</label>
                                        <input type="password" class="form-control form-control-premium" id="gema_token" placeholder="...................." style="background-color: #F8FAFC;">
                                        <small class="text-muted d-block mt-2" style="font-size: 0.8rem;"><i class="fa-solid fa-key me-1"></i> Introduce tu API Key de Google AI Studio para activar la IA.</small>
                                    </div>

                                    <!-- Prompt -->
                                    <div class="mb-4">
                                        <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">INSTRUCCIONES DE COMPORTAMIENTO (PROMPT DE SISTEMA)</label>
                                        <textarea class="form-control form-control-premium" id="gema_prompt" rows="8" placeholder="Eres Gema, la asistente virtual inteligente de STARFI CRM..." style="background-color: #F8FAFC; resize: vertical;"></textarea>
                                        <small class="text-muted d-block mt-2" style="font-size: 0.8rem;"><i class="fa-solid fa-circle-info me-1"></i> Dale contexto sobre tu negocio, horarios y formas de contacto.</small>
                                    </div>
                                    
                                    <!-- Botón Guardar -->
                                    <div>
                                        <button id="btnSaveGema" class="btn btn-starfi-primary" style="border-radius: 8px; font-weight: 600; padding: 10px 24px; box-shadow: 0 4px 6px rgba(232, 91, 20, 0.2);">
                                            <i class="fa-solid fa-save me-2"></i> Guardar Configuración IA
                                        </button>
                                    </div>

                                </div>
                                
                                <!-- Columna Derecha: Consejos -->
                                <div class="col-md-5">
                                    <div class="card shadow-sm" style="border-radius: 12px; background-color: #F8FAFC; border: 1px dashed #CBD5E1 !important;">
                                        <div class="card-body p-4">
                                            <h6 class="fw-bold text-dark mb-4" style="font-size: 1.05rem;">
                                                <i class="fa-regular fa-lightbulb text-warning me-2 fs-5"></i> Consejos para tu Agente
                                            </h6>
                                            
                                            <ul class="list-unstyled mb-0" style="font-size: 0.85rem; color: #475569; line-height: 1.6;">
                                                <li class="mb-3 d-flex">
                                                    <span class="me-2 text-muted">•</span>
                                                    <div><strong>Sé específico:</strong> Define claramente las reglas de negocio (ej. "No des precios exactos, invita a cotizar").</div>
                                                </li>
                                                <li class="mb-3 d-flex">
                                                    <span class="me-2 text-muted">•</span>
                                                    <div><strong>Personalidad:</strong> Gema puede ser amigable, formal, técnica o entusiasta. Escríbelo en las instrucciones.</div>
                                                </li>
                                                <li class="mb-3 d-flex">
                                                    <span class="me-2 text-muted">•</span>
                                                    <div><strong>Idiomas:</strong> Aunque responda en español por defecto, puedes indicarle que atienda en inglés si el cliente escribe en ese idioma.</div>
                                                </li>
                                                <li class="d-flex">
                                                    <span class="me-2 text-muted">•</span>
                                                    <div><strong>Contexto:</strong> Gema lee automáticamente los últimos mensajes de la conversación, por lo que recordará el nombre del cliente si este se lo indica.</div>
                                                </li>
                                            </ul>
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
                                        <p class="text-muted small mb-4">Simula que un cliente ha enviado un mensaje a tu webhook de WhatsApp. Esto te permite verificar la recepción y visualización instantánea en la Bandeja Omnicanal.</p>
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
                            <button type="button" class="btn btn-warning shadow-sm rounded-pill fw-bold me-2" onclick="crearPlantillaCSAT()">
                                <i class="fa-solid fa-star me-1"></i> Auto-Crear Plantilla CSAT
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



    <!-- JavaScript Local Bootstrap -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_configuracion.js?v=<?= time() ?>"></script>
    <script>
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
    </script>
    </div>
</body>
</html>




