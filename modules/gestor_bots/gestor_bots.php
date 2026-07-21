<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('gestor_bots');
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
    <link rel="stylesheet" href="../../assets/css/drawflow.min.css">
    
    <style>
        /* Drawflow overrides */
        #drawflow {
            width: 100%;
            height: 600px;
            background: #F8FAFC;
            background-size: 25px 25px;
            background-image: linear-gradient(to right, #E2E8F0 1px, transparent 1px), linear-gradient(to bottom, #E2E8F0 1px, transparent 1px);
            border-radius: 12px;
            border: 1px solid #CBD5E1;
            position: relative;
        }
        .drawflow .drawflow-node {
            background: white;
            border: 1px solid #CBD5E1;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            width: 250px;
            z-index: 2;
        }
        .drawflow .drawflow-node .title-box {
            height: 35px;
            line-height: 35px;
            background: #EFF6FF;
            border-bottom: 1px solid #BFDBFE;
            border-radius: 8px 8px 0 0;
            padding: 0 10px;
            font-weight: 600;
            color: #1E3A8A;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .drawflow .drawflow-node .box {
            padding: 10px;
            font-size: 0.8rem;
            color: #475569;
        }
        .drawflow .connection .main-path {
            stroke: #3B82F6;
            stroke-width: 3px;
        }
        .drawflow .drawflow-node.selected {
            border: 2px solid #E85B14;
        }
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
                        <?php
                        $con = getDbConnection();
                        $rol = $agente['rol'] ?? 'AGENTE';
                        $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;
                        
                        $query_s = "SELECT id, nombre_sede, bot_activo FROM sedes WHERE id_empresa = 1";
                        if ($rol !== 'MASTER' && $user_sede > 0) {
                            $query_s .= " AND id = $user_sede";
                        }
                        ?>
                        <select id="sedeFilter" class="form-select bg-light" style="width: auto; border: 1px solid #E2E8F0; border-radius: 10px; margin-left: 20px; <?= ($rol !== 'MASTER' && $user_sede > 0) ? 'display: none !important;' : '' ?>" onchange="loadBotRules()">
                            <?php if ($rol === 'MASTER' || $user_sede === 0): ?>
                            <option value="0">Seleccionar Sede...</option>
                            <?php endif; ?>
                            <?php
                            $s_res = $con->query($query_s);
                            if($s_res) {
                                while($s_row = $s_res->fetch_assoc()) {
                                    $selected = ($rol !== 'MASTER' && $user_sede === (int)$s_row['id']) ? 'selected' : '';
                                    echo '<option value="'.$s_row['id'].'" data-bot-activo="'.$s_row['bot_activo'].'" '.$selected.'>'.$s_row['nombre_sede'].'</option>';
                                }
                            }
                            ?>
                        </select>
                        <div id="botToggleContainer" class="form-check form-switch ms-3" style="display: none; align-items: center;">
                            <input class="form-check-input" type="checkbox" id="botStatusToggle" style="cursor: pointer; transform: scale(1.2);" onchange="toggleBotStatus()">
                            <label class="form-check-label fw-bold text-muted ms-2" for="botStatusToggle" style="font-size: 0.85rem; cursor: pointer;">Robot Activado</label>
                        </div>
                    </div>
                </div>
                
                <div id="botContentContainer" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                        <div class="search-bar-modern">
                            <i class="fa-solid fa-search"></i>
                            <input type="text" id="searchRule" placeholder="Buscar por disparador o mensaje...">
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-outline-secondary" id="btnToggleView" onclick="toggleViewMode()" style="border-radius: 30px; font-weight: 600; padding: 8px 20px;">
                                <i class="fa-solid fa-project-diagram me-1"></i> Modo Visual
                            </button>
                            <button class="btn btn-starfi-primary" onclick="openBotModal()" style="border-radius: 30px; font-weight: 600; padding: 8px 20px; box-shadow: 0 4px 12px rgba(232, 91, 20, 0.25);">
                                <i class="fa-solid fa-plus me-1"></i> Nueva Respuesta
                            </button>
                        </div>
                    </div>
                    
                    <!-- VISTA DRAWFLOW -->
                    <div id="drawflowContainer" style="display: none; padding: 20px;">
                        <div class="d-flex justify-content-between mb-3">
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-code-branch text-primary me-2"></i>Árbol Conversacional</h5>
                                <small class="text-muted">Arrastra conexiones entre las salidas y entradas de los nodos. Haz doble clic en un nodo para editarlo.</small>
                            </div>
                            <button class="btn btn-success fw-bold px-4 shadow-sm" onclick="saveDrawflowNetwork()"><i class="fa-solid fa-save me-1"></i> Guardar Red</button>
                        </div>
                        <div id="drawflow"></div>
                    </div>

                    <!-- VISTA TABLA -->
                    <div class="table-responsive" id="tableContainer">
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

                </div>
                <!-- Paginación -->
                <div id="paginationContainer">
                <div class="pagination-container">
                    <span class="page-info" id="pageInfo">Mostrando 0 - 0 de 0 reglas</span>
                    <div class="d-flex gap-2">
                        <button class="page-btn" id="btnPrevPage" disabled><i class="fa-solid fa-chevron-left me-1"></i> Anterior</button>
                        <button class="page-btn" id="btnNextPage" disabled>Siguiente <i class="fa-solid fa-chevron-right ms-1"></i></button>
                    </div>
                </div>
                </div>
                </div> <!-- End botContentContainer -->
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
                        <input type="hidden" id="parentId" value="">
                        
                        <div id="parentRuleBadge" style="display:none; margin-bottom: 15px; background-color: #EFF6FF; border: 1px solid #BFDBFE; padding: 10px; border-radius: 8px;">
                            <span style="font-size: 0.75rem; color: #1E3A8A; font-weight: bold;"><i class="fa-solid fa-level-down-alt me-2"></i>Creando sub-opción para:</span>
                            <div id="parentRuleText" style="font-size: 0.85rem; color: #1E40AF; font-weight: 600; margin-top: 4px;"></div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Tipo de Regla</label>
                                <select class="form-select bg-light" id="ruleType" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" required>
                                    <option value="EVENTO_SISTEMA">Evento General (Ej: Bienvenida)</option>
                                    <option value="PALABRA_CLAVE">Palabra Clave / Opción</option>
                                    <option value="CIERRE_CSAT">Cerrar Chat + Enviar CSAT</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Formato</label>
                                <select class="form-select bg-light" id="ruleFormat" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" required onchange="toggleFormatFields()">
                                    <option value="TEXTO">Texto Múltiple</option>
                                    <option value="IMAGEN">Imagen</option>
                                    <option value="DOCUMENTO">Documento (PDF)</option>
                                    <option value="UBICACION">Ubicación GPS</option>
                                    <option value="CONTACTOS">Tarjeta de Contacto</option>
                                    <option value="CONTACTOS_SEDE">Contactos de Asesores (Sede)</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-4">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Estado de Regla</label>
                                <select class="form-select bg-light" id="ruleState" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;">
                                    <option value="ACTIVO">✅ Activo</option>
                                    <option value="INACTIVO">⏸️ Inactivo</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Disparador / Evento</label>
                            <input type="text" class="form-control bg-light" id="ruleTrigger" style="border: 1px solid #E2E8F0; border-radius: 10px; padding: 12px; transition: all 0.2s;" placeholder="Ej: SALUDO_NUEVO, precio, ubicacion, 1, 2..." required>
                            <small class="text-muted mt-2 d-block" style="font-size: 0.75rem;"><i class="fa-solid fa-circle-info me-1"></i>Para palabras clave, separa con comas (ej: precio, costo, valor). Si es un menú, usa números (1, 2, 3).</small>
                        </div>
                        
                        <!-- Campos Multimedia Dinámicos -->
                        <div id="mediaFields" class="mb-4" style="display:none; background-color: #F8FAFC; border: 1px dashed #CBD5E1; padding: 15px; border-radius: 10px;">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;">Archivo Adjunto (URL o Subir)</label>
                            <div class="input-group">
                                <input type="text" class="form-control bg-light" id="ruleMediaUrl" placeholder="https://ejemplo.com/imagen.jpg o ruta local">
                                <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('fileUpload').click()"><i class="fa-solid fa-upload"></i> Subir</button>
                            </div>
                            <input type="file" id="fileUpload" style="display:none;" onchange="handleFileUpload(this)">
                        </div>
                        
                        <!-- Campos Ubicación Dinámicos -->
                        <div id="locationFields" class="mb-4 row" style="display:none; background-color: #F8FAFC; border: 1px dashed #CBD5E1; padding: 15px; border-radius: 10px; margin-left: 0; margin-right: 0;">
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Latitud</label>
                                <input type="text" class="form-control bg-light" id="ruleLat" placeholder="Ej: 10.4806">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem;">Longitud</label>
                                <input type="text" class="form-control bg-light" id="ruleLng" placeholder="Ej: -66.9036">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label text-muted fw-bold text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px;" id="messageLabel">Mensaje de Respuesta / Texto que acompaña</label>
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
                            
                            <div class="form-check form-switch mb-3 p-3 bg-white rounded border d-flex justify-content-between align-items-center" style="cursor: pointer;">
                                <div>
                                    <label class="form-check-label fw-bold text-dark mb-1" for="enableWait" style="cursor: pointer;">¿Es un Menú de Opciones? (Esperar Respuesta)</label>
                                    <p class="text-muted m-0" style="font-size: 0.75rem;">Si activas esto, el bot pausará las reglas globales y esperará que el cliente seleccione una opción (1, 2, 3...) de las sub-reglas que crees a partir de esta.</p>
                                </div>
                                <input class="form-check-input ms-3" type="checkbox" id="enableWait" style="cursor: pointer; transform: scale(1.3);">
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
        <script src="../../assets/js/drawflow.min.js"></script>
    <script src="funciones_gestor_bots.js"></script>
    <script src="drawflow_integration.js"></script>
    <script>
        document.getElementById('toggleSidebar').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('collapsed');
        });
    </script>
    </div>
</body>
</html>
