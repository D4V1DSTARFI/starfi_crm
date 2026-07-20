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
    <title>Gestor de Bots | CRM STARFI</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <!-- CSS Local de Bootstrap -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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

        /* Buscador y Paginación Premium */
        .table-toolbar {
            padding: 16px 24px;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            background-color: #ffffff;
            border-radius: 10px 10px 0 0;
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
            width: 300px;
        }
        .search-bar-modern:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.1);
        }
        .search-bar-modern i {
            color: #94A3B8;
        }
        .search-bar-modern input {
            width: 100%;
            border: none;
            background: transparent;
            padding: 4px 12px;
            font-size: 0.95rem;
            outline: none;
        }
        
        th.sortable {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }
        th.sortable:hover {
            background-color: #F8FAFC;
        }
        th.sortable i {
            margin-left: 5px;
            color: #CBD5E1;
            font-size: 0.8em;
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
            border-bottom-left-radius: 10px;
            border-bottom-right-radius: 10px;
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
            padding: 6px 16px;
            border-radius: 20px;
            color: #475569;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .page-btn:hover:not(:disabled) {
            background-color: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }
        .page-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background-color: #F8FAFC;
        }
    </style>
</head>
<body>
    <?php renderHeader('Gestor de Bots'); ?>
    <div class="app-container">

    <!-- Sidebar Navigation -->

    <!-- Main Content -->
    <main class="main-content">
        <div class="config-container">
            <div class="mb-4">
                <h2 class="brand-font mb-1" style="font-weight: 600;">Gestor de Bots</h2>
                <p class="text-muted" style="font-size: 0.9rem;">Configura los flujos y respuestas automáticas</p>
            </div>

            <div class="config-card" style="padding: 0;">
                <div class="table-toolbar d-flex justify-content-between align-items-center">
                    <div class="d-flex align-items-center gap-3">
                        <h4 class="config-card-title m-0" style="border: none; padding: 0;"><i class="fa-solid fa-robot text-starfi-primary"></i> Respuestas Automáticas</h4>
                        <div class="search-bar-modern" style="margin-left: 20px;">
                            <i class="fa-solid fa-search"></i>
                            <input type="text" id="searchRule" placeholder="Buscar por disparador o mensaje...">
                        </div>
                    </div>
                    <button class="btn btn-starfi-primary" onclick="openBotModal()" style="border-radius: 30px; font-weight: 600; padding: 8px 20px; box-shadow: 0 4px 12px rgba(232, 91, 20, 0.25);">
                        <i class="fa-solid fa-plus me-1"></i> Nueva Respuesta
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle table-borderless mb-0">
                        <thead style="background-color: #F8FAFC; color: var(--text-muted); font-size: 0.85rem; text-transform: uppercase;">
                            <tr>
                                <th class="sortable" data-sort="tipo" style="padding-left: 24px;">Tipo <i class="fa-solid fa-sort"></i></th>
                                <th class="sortable" data-sort="disparador">Disparador / Evento <i class="fa-solid fa-sort"></i></th>
                                <th>Mensaje</th>
                                <th class="sortable" data-sort="estado">Estado <i class="fa-solid fa-sort"></i></th>
                                <th style="text-align: right; padding-right: 24px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="botRulesTable" style="font-size: 0.9rem;">
                            <!-- Dynamic Content -->
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <div class="pagination-container">
                    <span class="page-info" id="pageInfo">Mostrando 0 - 0 de 0 reglas</span>
                    <div class="d-flex gap-2">
                        <button class="page-btn" id="btnPrevPage" disabled><i class="fa-solid fa-chevron-left me-1"></i> Anterior</button>
                        <button class="page-btn" id="btnNextPage" disabled>Siguiente <i class="fa-solid fa-chevron-right ms-1"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal Formulario Bot Premium -->
    <div class="modal fade" id="botModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
                <div class="modal-header border-0 bg-light" style="padding: 20px 30px; border-top-left-radius: 20px; border-top-right-radius: 20px;">
                    <h5 class="modal-title fw-bold text-dark mb-0" id="botModalTitle"><i class="fa-solid fa-wand-magic-sparkles text-starfi-primary me-2"></i>Nueva Respuesta Automática</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" style="padding: 30px;">
                    <form id="botForm">
                        <input type="hidden" id="ruleId">
                        
                        <div class="row">
                            <div class="col-md-6 mb-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Tipo de Regla</label>
                                <select class="form-select bg-light" id="ruleType" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" required>
                                    <option value="EVENTO_SISTEMA">Evento General (Ej: Bienvenida)</option>
                                    <option value="PALABRA_CLAVE">Palabra Clave (Ej: "Precio")</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Estado de Regla</label>
                                <select class="form-select bg-light" id="ruleState" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;">
                                    <option value="ACTIVO">✅ Activo</option>
                                    <option value="INACTIVO">⏸️ Inactivo</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Disparador / Evento</label>
                            <input type="text" class="form-control bg-light" id="ruleTrigger" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" placeholder="Ej: SALUDO_NUEVO, precio, ubicacion..." required>
                            <small class="text-muted mt-2 d-block" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info me-1"></i>Para palabras clave, separa con comas (ej: precio, costo, valor)</small>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Mensaje de Respuesta</label>
                            <textarea class="form-control bg-light" id="ruleMessage" rows="4" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" placeholder="Escribe la respuesta del bot aquí..." required></textarea>
                            <div class="mt-2 d-flex align-items-center gap-2">
                                <span class="text-muted" style="font-size: 0.75rem;">Variables Mágicas:</span>
                                <span class="var-tag shadow-sm border">{{nombre}}</span>
                            </div>
                        </div>

                        <!-- Sección de Funciones Avanzadas (Botones, Multimedia) -->
                        <div class="advanced-bot-features p-4" style="background-color: #F8FAFC; border-radius: 12px; border: 1px dashed #CBD5E1;">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="m-0 fw-bold text-dark"><i class="fa-solid fa-bolt text-warning me-2"></i>Funciones Avanzadas</h6>
                            </div>
                            
                            <!-- Toggle Botones -->
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="enableButtons" style="cursor: pointer;">
                                <label class="form-check-label fw-bold text-muted" for="enableButtons" style="font-size: 0.85rem; cursor: pointer;">Añadir Botones Interactivos (Meta API)</label>
                            </div>
                            
                            <!-- Botones Container -->
                            <div id="buttonsContainer" style="display: none;">
                                <div class="row" id="buttonsList">
                                    <div class="col-md-4 mb-2">
                                        <input type="text" class="form-control form-control-sm" placeholder="Botón 1 (ej. Ver Plan)" maxlength="20">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <input type="text" class="form-control form-control-sm" placeholder="Botón 2 (Opcional)" maxlength="20">
                                    </div>
                                    <div class="col-md-4 mb-2">
                                        <input type="text" class="form-control form-control-sm" placeholder="Botón 3 (Opcional)" maxlength="20">
                                    </div>
                                </div>
                                <small class="text-muted" style="font-size: 0.7rem;">WhatsApp permite un máximo de 3 botones de 20 caracteres cada uno.</small>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-0 p-4 pt-0">
                    <button type="button" class="btn btn-light fw-bold" style="border-radius: 10px; padding: 10px 20px;" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-starfi-primary px-4 fw-bold shadow-sm" style="border-radius: 10px; padding: 10px 20px;" onclick="saveBotRule()">Guardar Regla Mágica</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Local Bootstrap -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="funciones_gestor_bots.js"></script>
    <script>
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
    </script>
    </div>
</body>
</html>
