<?php
/**
 * Módulo Gestor de Bots - STARFI CRM
 * Vista principal MVC desacoplada en cápsula de 4 archivos.
 */
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
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/vendor/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css">
    <link rel="stylesheet" href="../../assets/css/drawflow.min.css">

    <style>
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
    <div class="app-container" id="botsAppRoot" data-agente="<?= htmlspecialchars($nombre_agente, ENT_QUOTES, 'UTF-8') ?>">

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
                            $rol_raw = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
                            $es_master_view = (intval($agente['id'] ?? 0) === 1 || $rol_raw === 'MASTER' || $rol_raw === 'MASTER CI');
                            $user_sede = isset($agente['id_sede']) ? intval($agente['id_sede']) : 0;

                            $sedes_rows = [];
                            if ($con) {
                                if (!$es_master_view && $user_sede > 0) {
                                    $stmt_s = $con->prepare("SELECT id, nombre_sede, bot_activo FROM sedes WHERE id_empresa = 1 AND id = ?");
                                    if ($stmt_s) {
                                        $stmt_s->bind_param("i", $user_sede);
                                        $stmt_s->execute();
                                        $s_res = $stmt_s->get_result();
                                        while ($s_row = $s_res->fetch_assoc()) {
                                            $sedes_rows[] = $s_row;
                                        }
                                        $stmt_s->close();
                                    }
                                } else {
                                    $stmt_s = $con->prepare("SELECT id, nombre_sede, bot_activo FROM sedes WHERE id_empresa = 1");
                                    if ($stmt_s) {
                                        $stmt_s->execute();
                                        $s_res = $stmt_s->get_result();
                                        while ($s_row = $s_res->fetch_assoc()) {
                                            $sedes_rows[] = $s_row;
                                        }
                                        $stmt_s->close();
                                    }
                                }
                            }
                            ?>
                            <button class="btn btn-outline-secondary btn-sm" onclick="openContactosModal()" title="Gestionar Vendedores">
                                <i class="fa-solid fa-address-book"></i> Vendedores
                            </button>
                            <select id="sedeFilter" class="form-select bg-light" style="width: auto; border: 1px solid #E2E8F0; border-radius: 10px; margin-left: 20px;" <?= (!$es_master_view && $user_sede > 0) ? 'disabled' : '' ?> onchange="loadBotRules()">
                                <?php
                                $first = true;
                                foreach ($sedes_rows as $s_row) {
                                    $sid = (int)$s_row['id'];
                                    $bot_act = (int)$s_row['bot_activo'];
                                    $selected = '';
                                    if (!$es_master_view && $user_sede === $sid) {
                                        $selected = 'selected';
                                    } elseif ($es_master_view && $first) {
                                        $selected = 'selected';
                                        $first = false;
                                    }
                                    echo '<option value="' . $sid . '" data-bot-activo="' . $bot_act . '" ' . $selected . '>' . htmlspecialchars((string)$s_row['nombre_sede'], ENT_QUOTES, 'UTF-8') . '</option>';
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
                                <tbody id="botRulesTable" style="font-size: 0.9rem;"></tbody>
                            </table>
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
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php require __DIR__ . '/modals_gestor_bots.php'; ?>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="../../assets/js/drawflow.min.js"></script>
    <script src="funciones_gestor_bots.js"></script>
    <script src="drawflow_integration.js"></script>
</body>
</html>
