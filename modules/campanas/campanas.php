<?php
// modules/campanas/campanas.php
// Ecosistema Independiente de Campañas de Difusión Masiva | STARFI CRM
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('campanas');

$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Operador';
$rol_agente = $agente['rol'] ?? 'AGENTE';
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campañas de Difusión | STARFI CRM</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <style>
        .main-content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow-y: auto;
            background-color: #F8FAFC;
        }

        /* Banner Superior de Sede y Línea Asignada */
        .sede-command-bar {
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
            border-radius: 16px;
            padding: 20px 24px;
            color: #FFFFFF;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sede-selector-pill {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.22);
            color: #FFFFFF;
            font-weight: 600;
            border-radius: 10px;
            padding: 8px 14px;
        }

        .sede-selector-pill option {
            color: #0F172A;
            background: #FFFFFF;
        }

        .whatsapp-line-badge {
            background: rgba(16, 185, 129, 0.16);
            border: 1px solid rgba(16, 185, 129, 0.45);
            color: #34D399;
            padding: 6px 14px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .whatsapp-line-badge.offline {
            background: rgba(239, 68, 68, 0.16);
            border-color: rgba(239, 68, 68, 0.45);
            color: #F87171;
        }

        /* KPI Cards */
        .kpi-card {
            background: #FFFFFF;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
            height: 100%;
            transition: transform 0.2s;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
        }

        .kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .kpi-value {
            font-size: 1.65rem;
            font-weight: 800;
            color: #0F172A;
            line-height: 1.15;
        }

        /* Paneles del Ecosistema */
        .eco-card {
            background: #FFFFFF;
            border-radius: 16px;
            border: 1px solid #E2E8F0;
            box-shadow: 0 4px 15px rgba(15, 23, 42, 0.03);
            overflow: hidden;
        }

        .eco-card-header {
            padding: 16px 22px;
            border-bottom: 1px solid #E2E8F0;
            background: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* Vista Previa de WhatsApp Oficial */
        .wa-preview-frame {
            background-color: #EFEAE2;
            background-image: radial-gradient(#CBD5E1 1px, transparent 1px);
            background-size: 16px 16px;
            border-radius: 14px;
            padding: 18px;
            border: 1px solid #CBD5E1;
        }

        .wa-bubble {
            background: #DCF8C6;
            border-radius: 12px 12px 12px 2px;
            padding: 14px 16px;
            color: #111827;
            font-size: 0.92rem;
            line-height: 1.5;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            max-width: 100%;
        }

        /* Calculadora de Presupuesto */
        .budget-calculator-box {
            background: linear-gradient(135deg, #FFF7ED 0%, #FFEDD5 100%);
            border: 1px solid #FDBA74;
            border-radius: 14px;
            padding: 16px;
        }

        /* Selector de Destinatarios */
        .recipient-scroll-box {
            max-height: 290px;
            overflow-y: auto;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            background: #FFFFFF;
        }

        .recipient-item {
            padding: 8px 14px;
            border-bottom: 1px solid #F1F5F9;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.86rem;
            cursor: pointer;
            transition: background 0.15s;
        }

        .recipient-item:hover {
            background: #F8FAFC;
        }

        /* Embudo Visual en Tabla */
        .funnel-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 0.78rem;
        }
    </style>
</head>

<body>
    <div class="app-container">
        <?php renderSidebar('campanas'); ?>

        <main class="main-content">
            <?php renderHeader('Ecosistema de Campañas de Difusión Masiva'); ?>

            <div class="container-fluid p-4">

                <!-- 1. BARRA DE COMANDO DE SEDE + NÚMERO OFICIAL + CONTROL DE FLUJO DIARIO -->
                <div class="sede-command-bar mb-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-12 col-lg-5">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="badge" style="background-color: #E85B14; font-size: 0.72rem;">ECOSISTEMA WABA v23.0</span>
                                <span class="text-white-50 small">Seleccione la Sede Emisora de la Campaña</span>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                                <select id="selectSedeActiva" class="form-select sede-selector-pill" style="max-width: 280px;" onchange="cambiarSedeActiva()">
                                    <option value="0">Cargando sedes...</option>
                                </select>
                                <div id="badgeLineaSede" class="whatsapp-line-badge">
                                    <i class="fa-brands fa-whatsapp"></i>
                                    <span id="txtNumeroSede">Verificando línea...</span>
                                </div>
                            </div>
                        </div>

                        <!-- Medidor de Control de Flujo Diario Anti-Bloqueo -->
                        <div class="col-12 col-lg-5">
                            <div class="p-3 rounded-3" style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12);">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-semibold text-white">
                                        <i class="fa-solid fa-shield-halved text-warning me-1"></i>
                                        Control de Flujo Diario Anti-Bloqueo (Sede)
                                    </span>
                                    <span class="badge bg-dark border border-secondary" id="lblCuotaDiariaResumen">0 / 250 hoy</span>
                                </div>
                                <div class="progress" style="height: 9px; background: rgba(255,255,255,0.15); border-radius: 99px;">
                                    <div id="barCuotaDiariaSede" class="progress-bar bg-success" role="progressbar" style="width: 0%;"></div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1 small text-white-50" style="font-size: 0.76rem;">
                                    <span id="lblDisponiblesHoy">Disponibles hoy: 250 mensajes</span>
                                    <span id="lblCadenciaSede">Cadencia: 1.0s / msg</span>
                                </div>
                            </div>
                        </div>

                        <!-- Botones de Acción Rápida -->
                        <div class="col-12 col-lg-2 text-lg-end">
                            <button class="btn btn-outline-light btn-sm w-100 mb-2 fw-semibold" onclick="abrirModalFlujoSede()">
                                <i class="fa-solid fa-sliders me-1"></i> Flujo y Costos Meta
                            </button>
                            <button class="btn btn-sm w-100 fw-bold text-white" style="background-color: #E85B14;" onclick="recargarEcosistema()">
                                <i class="fa-solid fa-rotate me-1"></i> Actualizar Métricas
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 2. TARJETAS KPI DEL EMBUDO DE DIFUSIÓN (ENVIADOS, ENTREGADOS, LEÍDOS, RESPONDIDOS, PRESUPUESTO) -->
                <div class="row g-3 mb-4">
                    <!-- Enviados & Entregados -->
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="kpi-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="text-muted small fw-semibold text-uppercase mb-1">Mensajes Enviados</div>
                                    <div class="kpi-value" id="kpiTotalEnviados">0</div>
                                    <div class="small text-muted mt-1">
                                        Entregados: <strong class="text-dark" id="kpiTotalEntregados">0</strong>
                                        (<span id="kpiTasaEntrega" class="text-primary fw-bold">0%</span>)
                                    </div>
                                </div>
                                <div class="kpi-icon" style="background: rgba(59, 130, 246, 0.12); color: #2563EB;">
                                    <i class="fa-solid fa-paper-plane"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Leídos (Open Rate / Doble Check Azul) -->
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="kpi-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="text-muted small fw-semibold text-uppercase mb-1">Mensajes Leídos</div>
                                    <div class="kpi-value text-info" id="kpiTotalLeidos">0</div>
                                    <div class="small text-muted mt-1">
                                        Tasa de Apertura: <strong class="text-info" id="kpiTasaLectura">0%</strong>
                                    </div>
                                </div>
                                <div class="kpi-icon" style="background: rgba(14, 165, 233, 0.12); color: #0284C7;">
                                    <i class="fa-solid fa-check-double"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Respondidos (Conversión Real) -->
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="kpi-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="text-muted small fw-semibold text-uppercase mb-1">Clientes Respondieron</div>
                                    <div class="kpi-value text-success" id="kpiTotalRespondidos">0</div>
                                    <div class="small text-muted mt-1">
                                        Conversión Chat: <strong class="text-success" id="kpiTasaRespuesta">0%</strong>
                                    </div>
                                </div>
                                <div class="kpi-icon" style="background: rgba(16, 185, 129, 0.12); color: #059669;">
                                    <i class="fa-solid fa-reply-all"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Inversión / Costo Meta USD -->
                    <div class="col-12 col-sm-6 col-xl-3">
                        <div class="kpi-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="text-muted small fw-semibold text-uppercase mb-1">Inversión Meta (USD)</div>
                                    <div class="kpi-value" style="color: #E85B14;" id="kpiCostoTotalUsd">$0.00</div>
                                    <div class="small text-muted mt-1">
                                        Tarifa Sede: <strong class="text-dark" id="kpiTarifaUnitariaSede">$0.0700</strong> / msg
                                    </div>
                                </div>
                                <div class="kpi-icon" style="background: rgba(232, 91, 20, 0.12); color: #E85B14;">
                                    <i class="fa-solid fa-sack-dollar"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 3. WORKFLOW PRINCIPAL: LANZADOR DE CAMPAÑA (IZQUIERDA) + ANALÍTICA DE CAMPAÑAS (DERECHA) -->
                <div class="row g-4">

                    <!-- COLUMNA IZQUIERDA: CREADOR DE CAMPAÑA, PRESUPUESTO Y SELECCIÓN DE AUDIENCIA -->
                    <div class="col-12 col-xl-5">
                        <div class="eco-card mb-4">
                            <div class="eco-card-header">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-rocket text-starfi-orange fs-5"></i>
                                    <h6 class="mb-0 fw-bold text-dark">Nueva Campaña de Difusión</h6>
                                </div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <i class="fa-solid fa-circle-check me-1"></i>Plantilla Aprobada
                                </span>
                            </div>

                            <div class="p-4">
                                <!-- Paso 1: Identificación y Plantilla -->
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-7">
                                        <label class="form-label small fw-bold text-secondary mb-1">Nombre de la Campaña *</label>
                                        <input type="text" id="campNombre" class="form-control form-control-sm" placeholder="Ej: Promo Fibra Octubre - Sede Principal">
                                    </div>
                                    <div class="col-12 col-md-5">
                                        <label class="form-label small fw-bold text-secondary mb-1">Plantilla Oficial Meta</label>
                                        <select id="campTemplate" class="form-select form-select-sm" onchange="actualizarVistaPreviaCampana()">
                                            <option value="starfi_difusion_directa" data-lang="es">starfi_difusion_directa (ES)</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Paso 2: Variables Dinámicas de starfi_difusion_directa -->
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-secondary mb-1">
                                        Variable <code>{{2}}</code> — "Hoy queremos..." *
                                    </label>
                                    <textarea id="campParam2" class="form-control form-control-sm" rows="2" placeholder="Ej: informarte que activamos 50% de descuento en instalación de Fibra Óptica de 400 Mbps en tu zona" oninput="actualizarVistaPreviaCampana()">informarte que tenemos una promoción exclusiva de actualización a Planes Ultra-Rápidos de Fibra Óptica</textarea>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-secondary mb-1">
                                        Variable <code>{{3}}</code> — "Recuerda que en..." *
                                    </label>
                                    <input type="text" id="campParam3" class="form-control form-control-sm" placeholder="Ej: STARFI siempre estamos conectados contigo con soporte 24/7" value="STARFI garantizamos estabilidad, velocidad simétrica y atención inmediata" oninput="actualizarVistaPreviaCampana()">
                                </div>

                                <!-- Vista Previa en Vivo estilo WhatsApp -->
                                <div class="wa-preview-frame mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge bg-dark bg-opacity-75 text-white" style="font-size: 0.7rem;">
                                            <i class="fa-brands fa-whatsapp text-success me-1"></i>Vista Previa del Mensaje
                                        </span>
                                        <span class="text-muted" style="font-size: 0.72rem;"><code>{{1}}</code> = Primer nombre del cliente</span>
                                    </div>
                                    <div class="wa-bubble" id="waBubblePreview"></div>
                                </div>

                                <!-- Paso 3: Selección de Audiencia (Directorio 360 o Pegado Manual) -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-bold text-secondary mb-0">
                                            <i class="fa-solid fa-users me-1"></i>Audiencia / Destinatarios
                                        </label>
                                        <span class="badge bg-primary rounded-pill" id="badgeTotalSeleccionados">0 seleccionados</span>
                                    </div>

                                    <!-- Filtros rápidos de audiencia -->
                                    <div class="row g-2 mb-2">
                                        <div class="col-12 col-sm-5">
                                            <select id="filtroAudienciaOrigen" class="form-select form-select-sm" onchange="filtrarAudiencia()">
                                                <option value="SEDE">Contactos de esta Sede</option>
                                                <option value="TODOS" selected>Todo el Directorio 360</option>
                                                <option value="MANUAL">Ingresar lista manual (CSV/Texto)</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-4">
                                            <select id="filtroAudienciaEtiqueta" class="form-select form-select-sm" onchange="filtrarAudiencia()">
                                                <option value="">Todas las etiquetas</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-3">
                                            <input type="text" id="filtroAudienciaBuscar" class="form-control form-control-sm" placeholder="Buscar..." oninput="filtrarAudiencia()">
                                        </div>
                                    </div>

                                    <!-- Contenedor Lista Directorio -->
                                    <div id="boxAudienciaDirectorio">
                                        <div class="d-flex justify-content-between align-items-center bg-light px-3 py-1.5 border rounded-top small">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox" id="chkSelectAllAudience" onchange="toggleSeleccionarTodosVisibles(this.checked)">
                                                <label class="form-check-label fw-semibold" for="chkSelectAllAudience" style="font-size: 0.8rem;">
                                                    Seleccionar visibles (<span id="lblCountVisibles">0</span>)
                                                </label>
                                            </div>
                                            <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" style="font-size: 0.78rem;" onclick="seleccionarHastaCuotaDiaria()">
                                                <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Ajustar a cuota de hoy
                                            </button>
                                        </div>
                                        <div class="recipient-scroll-box rounded-top-0" id="listaContactosCheck">
                                            <!-- Renderizado dinámico -->
                                        </div>
                                    </div>

                                    <!-- Contenedor Lista Manual -->
                                    <div id="boxAudienciaManual" class="d-none">
                                        <textarea id="txtAudienciaManual" class="form-control form-control-sm font-monospace" rows="5" placeholder="Formato por línea: Nombre, Telefono (o solo Telefono)&#10;Ejemplo:&#10;Carlos Perez, 584121234567&#10;Maria Gomez, 04149876543" oninput="recalcularPresupuestoCampana()"></textarea>
                                        <small class="text-muted" style="font-size: 0.75rem;">Los números <code>0412/0414/0424/0416</code> se normalizan automáticamente a <code>584...</code></small>
                                    </div>
                                </div>

                                <!-- Paso 4: Calculadora de Presupuesto Meta & Control de Flujo -->
                                <div class="budget-calculator-box mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="fw-bold small" style="color: #9A3412;">
                                            <i class="fa-solid fa-calculator me-1"></i>Presupuesto de Campaña & Costos Meta
                                        </span>
                                        <span class="badge bg-warning text-dark" id="badgeCostoUnitario">Marketing: $0.0700 / msg</span>
                                    </div>

                                    <div class="row g-2 align-items-center">
                                        <div class="col-6">
                                            <label class="form-label small text-muted mb-1" style="font-size: 0.76rem;">Presupuesto Máx. USD (Opcional)</label>
                                            <div class="input-group input-group-sm">
                                                <span class="input-group-text">$</span>
                                                <input type="number" step="0.50" min="0" id="campPresupuestoMax" class="form-control" placeholder="Sin límite" oninput="recalcularPresupuestoCampana()">
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small text-muted mb-1" style="font-size: 0.76rem;">Costo Estimado Campaña</label>
                                            <div class="p-1.5 px-3 bg-white rounded border fw-bold text-end" style="font-size: 1rem; color: #EA580C;" id="lblCostoEstimadoCampana">
                                                $0.0000 USD
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-2 pt-2 border-top border-warning-subtle small" style="font-size: 0.78rem; color: #7C2D12;" id="lblResumenFlujoYPresupuesto">
                                        Seleccione destinatarios para calcular el impacto en cuota diaria y presupuesto.
                                    </div>
                                </div>

                                <!-- Consola de Progreso de Envío en Vivo -->
                                <div id="boxConsolaEnvio" class="p-3 rounded-3 bg-dark text-white mb-3 d-none">
                                    <div class="d-flex justify-content-between align-items-center mb-1 small">
                                        <span id="lblEstadoConsola" class="fw-bold text-warning">
                                            <i class="fa-solid fa-spinner fa-spin me-1"></i>Despachando con Control de Flujo...
                                        </span>
                                        <span id="lblContadorConsola" class="badge bg-secondary">0 / 0</span>
                                    </div>
                                    <div class="progress mb-2" style="height: 8px; background: rgba(255,255,255,0.15);">
                                        <div id="barProgresoConsola" class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width: 0%;"></div>
                                    </div>
                                    <div id="logConsolaEnvio" class="font-monospace small text-white-50" style="max-height: 110px; overflow-y: auto; font-size: 0.74rem;"></div>
                                </div>

                                <div class="d-flex gap-2">
                                    <button type="button" id="btnDetenerCola" class="btn btn-danger fw-bold w-50 d-none" onclick="detenerColaEnvio()">
                                        <i class="fa-solid fa-stop me-1"></i>Pausar Envío
                                    </button>
                                    <button type="button" id="btnLanzarCampana" class="btn fw-bold text-white w-100 py-2" style="background-color: #E85B14; border-radius: 10px;" onclick="iniciarLanzamientoCampana()">
                                        <i class="fa-solid fa-paper-plane me-2"></i>Iniciar Difusión Controlada
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- COLUMNA DERECHA: EMBUDO DE CAMPAÑAS, TRAZABILIDAD DE LECTURAS Y RESPUESTAS -->
                    <div class="col-12 col-xl-7">
                        <div class="eco-card">
                            <div class="eco-card-header">
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">
                                        <i class="fa-solid fa-chart-pie text-primary me-2"></i>Historial de Campañas & Embudo de Conversión
                                    </h6>
                                    <small class="text-muted">Mide en tiempo real cuántos mensajes fueron enviados, entregados, leídos y respondidos</small>
                                </div>
                                <span class="badge bg-light text-dark border" id="badgeTotalCampanas">0 campañas</span>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" style="font-size: 0.86rem;">
                                    <thead class="table-light text-secondary small text-uppercase">
                                        <tr>
                                            <th class="ps-3">Campaña / Sede</th>
                                            <th class="text-center">Audiencia</th>
                                            <th class="text-center">Enviados</th>
                                            <th class="text-center">Leídos</th>
                                            <th class="text-center">Respondidos</th>
                                            <th class="text-end">Costo USD</th>
                                            <th class="text-center">Estado</th>
                                            <th class="text-end pe-3">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyCampanas">
                                        <tr>
                                            <td colspan="8" class="text-center py-5 text-muted">
                                                <i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando ecosistema de campañas...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <!-- MODAL 1: CONFIGURACIÓN DE CONTROL DE FLUJO DIARIO Y COSTOS META POR SEDE -->
    <div class="modal fade" id="modalFlujoSede" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-dark text-white" style="border-radius: 16px 16px 0 0;">
                    <h6 class="modal-title fw-bold">
                        <i class="fa-solid fa-shield-halved text-warning me-2"></i>Control de Flujo Diario & Costos Meta
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info small py-2 mb-3">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Proteja la reputación del número oficial de la sede definiendo un tope de mensajes diarios y la pausa entre envíos para evitar bloqueos de Meta (Error 131049).
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Límite Máximo de Mensajes Diarios para esta Sede</label>
                        <input type="number" id="cfgLimiteDiario" class="form-control" min="10" max="100000" value="250">
                        <small class="text-muted">Recomendado por nivel WABA: Tier Inicial = <code>250/día</code>, Nivel Estándar = <code>1,000/día</code>.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Cadencia / Ritmo entre Mensajes (Anti-Spam)</label>
                        <select id="cfgCadenciaMs" class="form-select">
                            <option value="500">Rápido (0.5 segundos por mensaje)</option>
                            <option value="1000" selected>Seguro Recomendado (1.0 segundo por mensaje)</option>
                            <option value="2000">Conservador Anti-Bloqueo (2.0 segundos por mensaje)</option>
                            <option value="3500">Ultra-Cuidadoso (3.5 segundos por mensaje)</option>
                        </select>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">Costo Meta Marketing (USD)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.0001" id="cfgCostoMarketing" class="form-control" value="0.0700">
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">Costo Meta Utilidad (USD)</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.0001" id="cfgCostoUtility" class="form-control" value="0.0150">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm fw-bold" style="background-color: #E85B14; border-color: #E85B14;" onclick="guardarConfiguracionFlujoSede()">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Guardar Parámetros de Sede
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 2: AUDITORÍA 360° DE DESTINATARIOS DE UNA CAMPAÑA (ENVIADOS, LEÍDOS, RESPUESTAS) -->
    <div class="modal fade" id="modalDetalleCampana" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-dark text-white" style="border-radius: 16px 16px 0 0;">
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="detTituloCampana">Detalle de Campaña</h6>
                        <small class="text-white-50" id="detSubtituloCampana"></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Filtros rápidos dentro del modal -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-dark active" onclick="filtrarTablaDestinatariosModal('TODOS', this)">Todos</button>
                            <button type="button" class="btn btn-outline-success" onclick="filtrarTablaDestinatariosModal('RESPONDIDO', this)">Respondidos</button>
                            <button type="button" class="btn btn-outline-info" onclick="filtrarTablaDestinatariosModal('LEIDO', this)">Leídos</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="filtrarTablaDestinatariosModal('PENDIENTE', this)">Pendientes</button>
                            <button type="button" class="btn btn-outline-danger" onclick="filtrarTablaDestinatariosModal('FALLIDO', this)">Fallidos</button>
                        </div>
                        <span class="small text-muted" id="detResumenModal"></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle" style="font-size: 0.84rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Cliente</th>
                                    <th>Teléfono</th>
                                    <th class="text-center">Estado</th>
                                    <th>Enviado</th>
                                    <th>Leído (Apertura)</th>
                                    <th>Respuesta del Cliente</th>
                                    <th class="text-end">Costo USD</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyDetalleDestinatarios"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="funciones_campanas.js?v=<?= time() ?>"></script>
</body>

</html>
