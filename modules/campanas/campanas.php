<?php
// modules/campanas/campanas.php
// Ecosistema Independiente de Campañas de Difusión Masiva | STARFI CRM
date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('campanas');

$agente = getAgenteInfo();
$id_agente = intval($agente['id'] ?? 0);
$nombre_agente = $agente['nombre_completo'] ?? 'Operador';
$rol_agente = strtoupper(trim($agente['rol'] ?? 'AGENTE'));
$id_sede_agente = intval($agente['id_sede'] ?? 0);
$es_global = ($id_agente === 1 || $rol_agente === 'MASTER' || $rol_agente === 'MASTER CI' || ($rol_agente === 'ADMIN' && $id_sede_agente === 0));
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

        /* Vista Previa de WhatsApp Oficial (Mockup Teléfono Estilo Wati / Respond.io) */
        .wa-phone-mockup {
            background: #0F172A;
            border-radius: 26px;
            padding: 8px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.18);
            border: 2px solid #334155;
        }

        .wa-phone-header {
            background: #075E54;
            color: #FFFFFF;
            border-radius: 18px 18px 0 0;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .wa-phone-avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #128C7E;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 0.9rem;
            color: #FFFFFF;
            flex-shrink: 0;
        }

        .wa-preview-frame {
            background-color: #EFEAE2;
            background-image: radial-gradient(#CBD5E1 1px, transparent 1px);
            background-size: 16px 16px;
            border-radius: 0 0 18px 18px;
            padding: 14px;
        }

        .wa-bubble {
            background: #FFFFFF;
            border-radius: 0 12px 12px 12px;
            padding: 10px 12px;
            color: #111827;
            font-size: 0.88rem;
            line-height: 1.45;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
            max-width: 100%;
            position: relative;
        }

        /* Wizard por Pasos (Estilo Wati / Respond.io) */
        .wizard-stepper {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            background: #F1F5F9;
            padding: 6px;
            border-radius: 14px;
            margin-bottom: 18px;
        }

        .wizard-step-btn {
            border: none;
            background: transparent;
            border-radius: 10px;
            padding: 9px 10px;
            font-size: 0.8rem;
            font-weight: 700;
            color: #64748B;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            transition: all 0.2s;
            cursor: pointer;
        }

        .wizard-step-btn .step-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #CBD5E1;
            color: #334155;
            font-size: 0.72rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .wizard-step-btn.active {
            background: #FFFFFF;
            color: #0F172A;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
        }

        .wizard-step-btn.active .step-num {
            background: #E85B14;
            color: #FFFFFF;
        }

        .wizard-step-btn.completed .step-num {
            background: #10B981;
            color: #FFFFFF;
        }

        /* Píldoras de Segmentación Rápida */
        .segment-pill-btn {
            border: 1px solid #CBD5E1;
            background: #FFFFFF;
            color: #334155;
            border-radius: 999px;
            padding: 4px 11px;
            font-size: 0.74rem;
            font-weight: 600;
            transition: all 0.15s;
        }

        .segment-pill-btn:hover, .segment-pill-btn.active {
            background: #0F172A;
            color: #FFFFFF;
            border-color: #0F172A;
        }

        /* Timeline de Tandas */
        .tanda-timeline-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: #FFFFFF;
            border: 1px solid #FED7AA;
            border-radius: 10px;
            margin-bottom: 6px;
            font-size: 0.8rem;
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

        /* Botones de Acción Compactos Solo Icono con Leyenda Flotante (Tooltip) */
        .camp-action-icon-btn {
            position: relative;
            width: 32px;
            height: 32px;
            padding: 0;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            transition: all 0.18s ease;
            flex-shrink: 0;
        }

        .camp-action-icon-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(15, 23, 42, 0.12);
        }

        .camp-action-icon-btn[data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: calc(100% + 7px);
            right: 0;
            background: #0F172A;
            color: #FFFFFF;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 4px 9px;
            border-radius: 6px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transform: translateY(4px);
            transition: all 0.15s ease;
            pointer-events: none;
            z-index: 1050;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.25);
        }

        .camp-action-icon-btn[data-tooltip]:hover::after {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .camp-action-badge-dot {
            position: absolute;
            top: -5px;
            right: -5px;
            min-width: 16px;
            height: 16px;
            padding: 0 4px;
            border-radius: 999px;
            font-size: 0.62rem;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #EF4444;
            color: #FFFFFF;
            border: 1.5px solid #FFFFFF;
        }

        /* Bloqueo del Ecosistema hasta Seleccionar Sede + Overlay de Carga */
        .eco-workspace-wrapper {
            position: relative;
            min-height: 520px;
        }

        .eco-workspace-wrapper.sede-locked > .eco-workspace-content {
            filter: blur(2.5px) grayscale(18%);
            opacity: 0.45;
            pointer-events: none;
            user-select: none;
            transition: all 0.25s ease;
        }

        .eco-sede-lock-overlay,
        .eco-sede-loading-overlay {
            position: absolute;
            inset: 0;
            z-index: 40;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding-top: 70px;
            background: rgba(248, 250, 252, 0.35);
            backdrop-filter: blur(2px);
            border-radius: 18px;
        }

        .eco-lock-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-top: 4px solid #E85B14;
            border-radius: 18px;
            padding: 28px 32px;
            max-width: 460px;
            width: 92%;
            text-align: center;
            box-shadow: 0 20px 45px rgba(15, 23, 42, 0.14);
        }

        /* Modales de Confirmación y Alertas STARFI */
        .starfi-dialog-card {
            border-radius: 20px;
            overflow: hidden;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.35);
        }

        .starfi-dialog-header {
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
            padding: 20px 24px;
            color: #FFFFFF;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .starfi-dialog-icon-circle {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }

        .starfi-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 14px 0;
        }

        .starfi-summary-item {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            padding: 10px 14px;
        }

        .starfi-summary-item .label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748B;
            font-weight: 700;
            margin-bottom: 2px;
        }

        .starfi-summary-item .value {
            font-size: 0.95rem;
            font-weight: 800;
            color: #0F172A;
        }
    </style>
</head>

<body>
    <?php renderHeader('Ecosistema de Campañas de Difusión Masiva'); ?>
    <div class="app-container">
        <main class="main-content">
            <div class="container-fluid p-4">

                <!-- 1. BARRA DE COMANDO DE SEDE + NÚMERO OFICIAL + CONTROL DE FLUJO DIARIO -->
                <div class="sede-command-bar mb-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-12 col-lg-5">
                            <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                                <span class="badge" style="background-color: #E85B14; font-size: 0.72rem;">ECOSISTEMA
                                    WABA v23.0</span>
                                <?php if ($es_global): ?>
                                    <span class="text-white-50 small">Seleccione la Sede Emisora de la Campaña</span>
                                <?php else: ?>
                                    <span class="badge bg-info bg-opacity-25 text-info border border-info border-opacity-50" style="font-size: 0.72rem;">
                                        <i class="fa-solid fa-lock me-1"></i>Modo Gerente de Sede (Exclusivo)
                                    </span>
                                <?php endif; ?>
                                <span class="badge bg-dark border border-secondary ms-auto" style="font-size: 0.72rem;" title="Hora oficial del servidor (America/Caracas)">
                                    <i class="fa-regular fa-clock text-warning me-1"></i><span id="lblRelojServidor"><?= date('h:i A') ?></span>
                                </span>
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-3 mt-2">
                                <div class="d-flex align-items-center gap-2">
                                    <select id="selectSedeActiva" class="form-select sede-selector-pill"
                                        style="max-width: 280px;" onchange="cambiarSedeActiva()" <?= !$es_global ? 'disabled title="Tu usuario está vinculado exclusivamente a tu sede asignada"' : '' ?>>
                                        <option value="0">-- Seleccione una Sede Emisora --</option>
                                    </select>
                                    <span id="spinnerSedeSelect" class="badge bg-warning text-dark d-none px-2.5 py-1.5 rounded-pill shadow-sm" style="font-size: 0.75rem;">
                                        <i class="fa-solid fa-spinner fa-spin me-1"></i>Cargando sede...
                                    </span>
                                </div>
                                <div id="badgeLineaSede" class="whatsapp-line-badge offline">
                                    <i class="fa-brands fa-whatsapp"></i>
                                    <span id="txtNumeroSede">Seleccione una sede...</span>
                                </div>
                            </div>
                        </div>

                        <!-- Medidor de Control de Flujo Diario Anti-Bloqueo -->
                        <div class="col-12 col-lg-5">
                            <div class="p-3 rounded-3"
                                style="background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12);">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="small fw-semibold text-white">
                                        <i class="fa-solid fa-shield-halved text-warning me-1"></i>
                                        Control de Flujo Diario Anti-Bloqueo (Sede)
                                    </span>
                                    <span class="badge bg-dark border border-secondary" id="lblCuotaDiariaResumen">0 /
                                        250 hoy</span>
                                </div>
                                <div class="progress"
                                    style="height: 9px; background: rgba(255,255,255,0.15); border-radius: 99px;">
                                    <div id="barCuotaDiariaSede" class="progress-bar bg-success" role="progressbar"
                                        style="width: 0%;"></div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1 small text-white-50"
                                    style="font-size: 0.76rem;">
                                    <span id="lblDisponiblesHoy">Disponibles hoy: 250 mensajes</span>
                                    <span id="lblCadenciaSede">Cadencia: 1.0s / msg</span>
                                </div>
                            </div>
                        </div>

                        <!-- Botones de Acción Rápida -->
                        <div class="col-12 col-lg-2 text-lg-end">
                            <button id="btnAbrirFlujoSede" class="btn btn-outline-light btn-sm w-100 mb-2 fw-semibold"
                                onclick="abrirModalFlujoSede()">
                                <i class="fa-solid fa-sliders me-1"></i> Límite y Ritmo Diario
                            </button>
                            <button id="btnActualizarMetricasTop" class="btn btn-sm w-100 fw-bold text-white" style="background-color: #E85B14;"
                                onclick="recargarEcosistema()">
                                <i class="fa-solid fa-rotate me-1"></i> Actualizar Métricas
                            </button>
                        </div>
                    </div>
                </div>

                <!-- CONTENEDOR DEL ECOSISTEMA CON BLOQUEO POR SEDE Y SPINNER DE CARGA -->
                <div class="eco-workspace-wrapper <?= $es_global ? 'sede-locked' : '' ?>" id="ecoWorkspaceWrapper">

                    <!-- OVERLAY 1: BLOQUEADO HASTA SELECCIONAR UNA SEDE -->
                    <div class="eco-sede-lock-overlay <?= $es_global ? '' : 'd-none' ?>" id="overlaySedeBloqueada">
                        <div class="eco-lock-card">
                            <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3"
                                style="width: 58px; height: 58px; background: rgba(232, 91, 20, 0.12); color: #E85B14; font-size: 1.5rem;">
                                <i class="fa-solid fa-store"></i>
                            </div>
                            <h5 class="fw-bold text-dark mb-2">Seleccione una Sede Emisora</h5>
                            <p class="text-muted small mb-3">
                                Para gestionar audiencias, configurar el mensaje con vista previa en vivo o consultar el historial de campañas, seleccione primero la <strong>Sede y Línea Oficial de WhatsApp</strong> en la barra superior.
                            </p>
                            <button type="button" class="btn btn-dark btn-sm fw-bold px-4 py-2" style="border-radius: 10px;"
                                onclick="document.getElementById('selectSedeActiva')?.focus()">
                                <i class="fa-solid fa-hand-pointer me-2 text-warning"></i>Elegir Sede Arriba
                            </button>
                        </div>
                    </div>

                    <!-- OVERLAY 2: SPINNER DE CARGA AL CAMBIAR DE SEDE -->
                    <div class="eco-sede-loading-overlay d-none" id="overlaySedeCargando">
                        <div class="eco-lock-card">
                            <div class="spinner-border mb-3" style="width: 2.6rem; height: 2.6rem; color: #E85B14;" role="status"></div>
                            <h6 class="fw-bold text-dark mb-1" id="lblTituloCargandoSede">Cargando datos de la sede...</h6>
                            <p class="text-muted small mb-0">Sincronizando línea WhatsApp, tarifas, contactos del directorio e historial de campañas.</p>
                        </div>
                    </div>

                    <div class="eco-workspace-content" id="ecoWorkspaceContent">
                        <!-- WORKFLOW PRINCIPAL EN 3 PANELES: CONFIGURACIÓN (IZQUIERDA) + SIMULADOR EN VIVO (CENTRO) + HISTORIAL (DERECHA) -->
                        <div class="row g-3 align-items-start">

                    <!-- COLUMNA 1 (IZQUIERDA): CREADOR DE CAMPAÑA, PRESUPUESTO Y SELECCIÓN DE AUDIENCIA -->
                    <div class="col-12 col-xl-4">
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
                                <!-- WIZARD STEPPER ESTILO WATI / RESPOND.IO -->
                                <div class="wizard-stepper">
                                    <button type="button" class="wizard-step-btn active" id="btnStep1" onclick="irPasoWizard(1)">
                                        <span class="step-num">1</span>
                                        <span>Mensaje & Flyer</span>
                                    </button>
                                    <button type="button" class="wizard-step-btn" id="btnStep2" onclick="irPasoWizard(2)">
                                        <span class="step-num">2</span>
                                        <span>Audiencia (<span id="lblStep2Count">0</span>)</span>
                                    </button>
                                    <button type="button" class="wizard-step-btn" id="btnStep3" onclick="irPasoWizard(3)">
                                        <span class="step-num">3</span>
                                        <span>Plan & Lanzar</span>
                                    </button>
                                </div>

                                <!-- ==================== PASO 1: CONTENIDO, VARIABLES Y FLYER ==================== -->
                                <div id="wizardStep1">
                                    <div class="row g-3 mb-2">
                                        <div class="col-12 col-md-7">
                                            <label class="form-label small fw-bold text-secondary mb-1">Nombre de la Campaña *</label>
                                            <input type="text" id="campNombre" class="form-control form-control-sm"
                                                placeholder="Ej: Gran Inauguración Carpintero Express Boleíta - 16 Nov"
                                                value="Gran Inauguración Carpintero Express Boleíta - 16 Nov">
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <label class="form-label small fw-bold text-secondary mb-1">Plantilla Oficial Meta</label>
                                            <select id="campTemplate" class="form-select form-select-sm"
                                                onchange="cambiarPlantillaSeleccionada()">
                                                <option value="starfi_difusion_directa" data-lang="es">
                                                    starfi_difusion_directa (ES)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- Ficha de Especificaciones de la Plantilla Seleccionada -->
                                    <div id="boxInfoPlantillaActiva" class="mb-3 p-2 px-3 rounded-3 border d-flex flex-wrap align-items-center justify-content-between gap-2" style="background: #F8FAFC; border-color: #E2E8F0 !important;">
                                        <div class="d-flex flex-wrap align-items-center gap-1" id="pillsEspecificacionPlantilla">
                                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle" style="font-size:0.68rem;">MARKETING</span>
                                            <span class="badge bg-light text-secondary border" style="font-size:0.68rem;">Idioma: ES</span>
                                            <span class="badge bg-light text-secondary border" style="font-size:0.68rem;">Cabecera: Imagen</span>
                                        </div>
                                        <button type="button" id="btnRellenarEjemploPlantilla" class="btn btn-sm btn-link text-decoration-none p-0 fw-semibold d-none" style="font-size: 0.72rem; color: #E85B14;" onclick="autocompletarEjemploPlantillaActiva()">
                                            <i class="fa-solid fa-wand-magic-sparkles me-1"></i>Usar texto de ejemplo
                                        </button>
                                    </div>

                                    <!-- Cabecera de Texto Dinámica (Solo si la plantilla tiene HEADER tipo TEXT con variables) -->
                                    <div id="boxCampHeaderTexto" class="mb-3 p-2.5 px-3 rounded-3 border d-none" style="background: #F8FAFC; border-color: #CBD5E1 !important;">
                                        <!-- Renderizado dinámico según plantilla -->
                                    </div>

                                    <!-- Contenedor Dinámico de Variables del Cuerpo ({{1}}, {{2}}, {{3}}, ...) según Plantilla -->
                                    <div id="contenedorVariablesPlantilla">
                                        <!-- Renderizado dinámico según la plantilla seleccionada -->
                                    </div>

                                    <!-- Selector de Imagen / Flyer de Cabecera con Vista Previa (Solo si la plantilla usa HEADER IMAGE) -->
                                    <div id="boxCampHeaderImagen" class="mb-3 p-3 rounded-3 border" style="background: #F8FAFC; border-color: #CBD5E1 !important;">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <label class="form-label small fw-bold text-dark mb-0" for="campHeaderImagen">
                                                <i class="fa-regular fa-image text-starfi-orange me-1"></i>Imagen / Flyer de Cabecera (WhatsApp Header)
                                            </label>
                                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle" id="badgeEstadoImagenHeader" style="font-size: 0.7rem;">
                                                Logo Oficial Automático
                                            </span>
                                        </div>
                                        <div class="d-flex gap-2 align-items-center">
                                            <input type="file" id="campHeaderImagen" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp" onchange="previsualizarImagenCabecera(this)">
                                            <button type="button" id="btnLimpiarImagenHeader" class="btn btn-sm btn-outline-danger d-none" title="Quitar imagen seleccionada" onclick="limpiarImagenCabecera()">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted d-block mt-1" style="font-size: 0.73rem;">
                                            Formatos soportados: <strong>JPG, PNG o WEBP</strong>. Si no seleccionas ninguna, se enviará el logo oficial de la sede automáticamente.
                                        </small>
                                    </div>

                                    <div class="d-flex justify-content-end mt-2">
                                        <button type="button" class="btn btn-dark btn-sm fw-bold px-4 py-2" style="border-radius: 10px;" onclick="irPasoWizard(2)">
                                            Siguiente: Seleccionar Audiencia <i class="fa-solid fa-arrow-right ms-1"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- ==================== PASO 2: AUDIENCIA, SEGMENTOS RÁPIDOS Y PROTECCIÓN ANTI-FATIGA ==================== -->
                                <div id="wizardStep2" class="d-none">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <label class="form-label small fw-bold text-secondary mb-0">
                                            <i class="fa-solid fa-users me-1"></i>Selección de Audiencia & Segmentación
                                        </label>
                                        <span class="badge bg-primary rounded-pill" id="badgeTotalSeleccionados">0 seleccionados</span>
                                    </div>

                                    <!-- Píldoras de Segmentación Rápida (Estilo Kommo / Respond.io) -->
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        <button type="button" class="segment-pill-btn" onclick="aplicarSegmentoRapido('TODOS_SEDE')">
                                            <i class="fa-solid fa-store me-1 text-primary"></i>Toda mi Sede
                                        </button>
                                        <?php if ($es_global): ?>
                                            <button type="button" class="segment-pill-btn" onclick="aplicarSegmentoRapido('CARTERA_GLOBAL')">
                                                <i class="fa-solid fa-earth-americas me-1 text-info"></i>Cartera Completa (Global)
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="segment-pill-btn" onclick="aplicarSegmentoRapido('RECIENTES_30D')">
                                            <i class="fa-solid fa-bolt me-1 text-warning"></i>Activos 30 días
                                        </button>
                                        <button type="button" class="segment-pill-btn" onclick="seleccionarHastaCuotaDiaria()">
                                            <i class="fa-solid fa-wand-magic-sparkles me-1 text-success"></i>Ajustar a Cuota Hoy
                                        </button>
                                        <button type="button" class="segment-pill-btn" onclick="aplicarSegmentoRapido('LIMPIAR')">
                                            <i class="fa-solid fa-eraser me-1 text-danger"></i>Limpiar
                                        </button>
                                    </div>

                                    <?php if ($es_global): ?>
                                    <!-- Panel Master / Admin: Difusión Global con Cuota por Sede -->
                                    <div class="p-2.5 px-3 rounded-3 mb-2 border" id="boxCuotaGlobalPorSede" style="background: linear-gradient(135deg, #F8FAFC 0%, #EFF6FF 100%); border-color: #BFDBFE !important;">
                                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1.5">
                                            <span class="small fw-bold text-dark" style="font-size: 0.76rem;">
                                                <i class="fa-solid fa-layer-group text-primary me-1"></i>Difusión Global Master — Selección de Cuota por Sede
                                            </span>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle" id="badgeResumenCuotaPorSede" style="font-size: 0.66rem;">
                                                Multisede desde línea activa
                                            </span>
                                        </div>
                                        <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                                            <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 fw-semibold" style="font-size: 0.72rem;" onclick="seleccionarCuotaPorCadaSede('TODOS')">
                                                <i class="fa-solid fa-globe me-1"></i>Toda la Cartera
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size: 0.72rem;" onclick="seleccionarCuotaPorCadaSede(50)">
                                                50 / sede
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size: 0.72rem;" onclick="seleccionarCuotaPorCadaSede(100)">
                                                100 / sede
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size: 0.72rem;" onclick="seleccionarCuotaPorCadaSede(250)">
                                                250 / sede
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size: 0.72rem;" onclick="seleccionarCuotaPorCadaSede(500)">
                                                500 / sede
                                            </button>
                                            <div class="input-group input-group-sm ms-auto" style="width: 145px;">
                                                <input type="number" id="inpCuotaPersonalizadaPorSede" class="form-control form-control-sm py-0 px-2" min="1" max="50000" value="100" placeholder="Ej: 100" style="font-size: 0.74rem;">
                                                <button type="button" class="btn btn-primary btn-sm py-0 px-2 fw-bold" style="font-size: 0.72rem;" onclick="seleccionarCuotaPorCadaSede(document.getElementById('inpCuotaPersonalizadaPorSede').value)">
                                                    Aplicar/Sede
                                                </button>
                                            </div>
                                        </div>
                                        <div id="desgloseCuotaPorSede" class="mt-1.5 d-none" style="font-size: 0.7rem; color: #334155;"></div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Escudo Anti-Fatiga Meta (Prevención Error 131049) -->
                                    <div class="p-2 px-3 rounded-3 mb-2 border d-flex align-items-center justify-content-between" style="background: #F0FDF4; border-color: #BBF7D0 !important;">
                                        <div class="form-check form-switch mb-0">
                                            <input class="form-check-input" type="checkbox" id="chkFiltroAntiFatiga" checked onchange="filtrarAudiencia()">
                                            <label class="form-check-label small fw-bold text-success" for="chkFiltroAntiFatiga" style="font-size: 0.76rem;">
                                                <i class="fa-solid fa-shield-heart me-1"></i>Escudo Anti-Fatiga Meta (Omitir contactados hace &lt;24h sin responder)
                                            </label>
                                        </div>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 0.68rem;">Evita Error 131049</span>
                                    </div>

                                    <!-- Filtros rápidos de audiencia -->
                                    <div class="row g-2 mb-2">
                                        <div class="col-12 col-sm-5">
                                            <select id="filtroAudienciaOrigen" class="form-select form-select-sm"
                                                onchange="filtrarAudiencia()">
                                                <option value="SEDE" selected>Clientes de esta Sede</option>
                                                <?php if ($es_global): ?>
                                                    <option value="TODOS">Todo el Directorio 360 (Global)</option>
                                                <?php endif; ?>
                                                <option value="MANUAL">Ingresar lista manual (CSV/Texto)</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-4">
                                            <select id="filtroAudienciaEtiqueta" class="form-select form-select-sm"
                                                onchange="filtrarAudiencia()">
                                                <option value="">Todas las etiquetas</option>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-3">
                                            <input type="text" id="filtroAudienciaBuscar"
                                                class="form-control form-control-sm" placeholder="Buscar..."
                                                oninput="filtrarAudiencia()">
                                        </div>
                                    </div>

                                    <!-- Contenedor Lista Directorio -->
                                    <div id="boxAudienciaDirectorio">
                                        <div class="d-flex justify-content-between align-items-center bg-light px-3 py-1.5 border rounded-top small">
                                            <div class="form-check mb-0">
                                                <input class="form-check-input" type="checkbox"
                                                    id="chkSelectAllAudience"
                                                    onchange="toggleSeleccionarTodosVisibles(this.checked)">
                                                <label class="form-check-label fw-semibold" for="chkSelectAllAudience"
                                                    style="font-size: 0.8rem;">
                                                    Seleccionar visibles (<span id="lblCountVisibles">0</span>)
                                                </label>
                                            </div>
                                            <span class="text-muted" style="font-size: 0.74rem;" id="lblOmitidosFatiga"></span>
                                        </div>
                                        <div class="recipient-scroll-box rounded-top-0" id="listaContactosCheck">
                                            <!-- Renderizado dinámico -->
                                        </div>
                                    </div>

                                    <!-- Contenedor Lista Manual -->
                                    <div id="boxAudienciaManual" class="d-none">
                                        <textarea id="txtAudienciaManual"
                                            class="form-control form-control-sm font-monospace" rows="5"
                                            placeholder="Formato por línea: Nombre, Telefono (o solo Telefono)&#10;Ejemplo:&#10;Carlos Perez, 584121234567&#10;Maria Gomez, 04149876543"
                                            oninput="recalcularPresupuestoCampana()"></textarea>
                                        <div class="d-flex justify-content-between align-items-center mt-1">
                                            <small class="text-muted" style="font-size: 0.73rem;">Los números <code>0412/0414/0424/0416</code> se normalizan a <code>584...</code></small>
                                            <span class="badge bg-light text-dark border" id="badgeResumenManual" style="font-size: 0.7rem;">0 válidos</span>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-3">
                                        <button type="button" class="btn btn-outline-secondary btn-sm fw-semibold px-3" style="border-radius: 10px;" onclick="irPasoWizard(1)">
                                            <i class="fa-solid fa-arrow-left me-1"></i>Paso 1
                                        </button>
                                        <button type="button" class="btn btn-dark btn-sm fw-bold px-4 py-2" style="border-radius: 10px;" onclick="irPasoWizard(3)">
                                            Siguiente: Programación & Costos <i class="fa-solid fa-arrow-right ms-1"></i>
                                        </button>
                                    </div>
                                </div>

                                <!-- ==================== PASO 3: PLANIFICACIÓN DE COSTOS, HORA, TANDAS Y LANZAMIENTO ==================== -->
                                <div id="wizardStep3" class="d-none">
                                    <div class="budget-calculator-box mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fw-bold small" style="color: #9A3412;">
                                                <i class="fa-solid fa-calendar-check me-1"></i>Cronograma de Tandas, Hora & Presupuesto Meta
                                            </span>
                                            <span class="badge bg-warning text-dark" id="badgeCostoUnitario">Marketing: $0.0700 / msg</span>
                                        </div>

                                        <?php if ($es_global): ?>
                                        <div class="p-2 px-2.5 rounded-3 mb-2 border" id="boxStep3CuotaGlobalMaster" style="background: rgba(255,255,255,0.85); border-color: #BFDBFE !important;">
                                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-1 mb-1">
                                                <span class="small fw-bold text-dark" style="font-size: 0.74rem;">
                                                    <i class="fa-solid fa-earth-americas text-primary me-1"></i>Alcance / Cuota de Cartera por Sede:
                                                </span>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle" id="badgeStep3AlcanceSedes" style="font-size: 0.66rem;">
                                                    Solo Sede Activa
                                                </span>
                                            </div>
                                            <div class="d-flex flex-wrap align-items-center gap-1">
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 fw-semibold" style="font-size: 0.7rem;" onclick="aplicarSegmentoRapido('TODOS_SEDE')">
                                                    Solo Sede Activa
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 fw-semibold" style="font-size: 0.7rem;" onclick="seleccionarCuotaPorCadaSede('TODOS')">
                                                    Cartera Completa
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size: 0.7rem;" onclick="seleccionarCuotaPorCadaSede(100)">
                                                    100/sede
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size: 0.7rem;" onclick="seleccionarCuotaPorCadaSede(250)">
                                                    250/sede
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-dark py-0 px-2 fw-semibold" style="font-size: 0.7rem;" onclick="seleccionarCuotaPorCadaSede(500)">
                                                    500/sede
                                                </button>
                                                <div class="input-group input-group-sm ms-auto" style="width: 130px;">
                                                    <input type="number" id="inpStep3CuotaPorSede" class="form-control form-control-sm py-0 px-1.5" min="1" max="50000" value="100" placeholder="Cuota/sede" style="font-size: 0.72rem;">
                                                    <button type="button" class="btn btn-primary btn-sm py-0 px-2 fw-bold" style="font-size: 0.7rem;" onclick="seleccionarCuotaPorCadaSede(document.getElementById('inpStep3CuotaPorSede').value)">
                                                        /Sede
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <div class="row g-2 align-items-center mb-2">
                                            <div class="col-12 col-sm-4">
                                                <label class="form-label small text-muted mb-1" style="font-size: 0.74rem;">Cuota Diaria (Msgs/Día)</label>
                                                <input type="number" min="1" max="100000" id="campCuotaDiariaProg"
                                                    class="form-control form-control-sm fw-bold" value="250"
                                                    oninput="this.dataset.touched='1'; recalcularPresupuestoCampana()">
                                            </div>
                                            <div class="col-12 col-sm-4">
                                                <label class="form-label small text-muted mb-1" style="font-size: 0.74rem;">
                                                    <i class="fa-regular fa-clock text-primary me-1"></i>Hora de Envío Diario
                                                </label>
                                                <input type="time" id="campHoraProgramada"
                                                    class="form-control form-control-sm fw-bold" value="09:00"
                                                    onchange="recalcularPresupuestoCampana()">
                                            </div>
                                            <div class="col-12 col-sm-4">
                                                <label class="form-label small text-muted mb-1" style="font-size: 0.74rem;">Presupuesto Máx. USD</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text">$</span>
                                                    <input type="number" step="0.50" min="0" id="campPresupuestoMax"
                                                        class="form-control" placeholder="Sin límite"
                                                        oninput="recalcularPresupuestoCampana()">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row g-2 align-items-center">
                                            <div class="col-6">
                                                <label class="form-label small text-muted mb-1" style="font-size: 0.74rem;">Tandas Diarias Estimadas</label>
                                                <div class="p-1.5 px-3 bg-white rounded border fw-bold text-center text-dark"
                                                    style="font-size: 0.92rem;" id="lblTotalTandasCampana">
                                                    1 tanda (1 día)
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label small text-muted mb-1" style="font-size: 0.74rem;">Costo Total Estimado (Meta)</label>
                                                <div class="p-1.5 px-3 bg-white rounded border fw-bold text-end"
                                                    style="font-size: 1rem; color: #EA580C;" id="lblCostoEstimadoCampana">
                                                    $0.0000 USD
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-check form-switch mt-2 pt-2 border-top border-warning-subtle">
                                            <input class="form-check-input" type="checkbox" id="campPilotoAutomatico"
                                                checked onchange="recalcularPresupuestoCampana()">
                                            <label class="form-check-label small fw-bold text-dark"
                                                for="campPilotoAutomatico" style="font-size: 0.78rem;">
                                                <i class="fa-solid fa-robot text-primary me-1"></i>Activar Piloto Automático Diario (despachar cuotas día a día tras aprobar)
                                            </label>
                                        </div>

                                        <div class="mt-2 pt-2 border-top border-warning-subtle small"
                                            style="font-size: 0.78rem; color: #7C2D12;" id="lblResumenFlujoYPresupuesto">
                                            Seleccione destinatarios para proyectar el costo total y el cronograma de tandas diarias.
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2 mb-2">
                                        <button type="button" class="btn btn-outline-secondary fw-semibold px-3" style="border-radius: 10px;" onclick="irPasoWizard(2)">
                                            <i class="fa-solid fa-arrow-left me-1"></i>Audiencia
                                        </button>
                                        <button type="button" id="btnLanzarCampana"
                                            class="btn fw-bold text-white flex-grow-1 py-2"
                                            style="background-color: #E85B14; border-radius: 10px;"
                                            onclick="iniciarLanzamientoCampana()">
                                            <i class="fa-solid fa-clipboard-check me-2"></i>Registrar Campaña (Pendiente)
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- COLUMNA 2 (CENTRO): VISTA PREVIA INTERACTIVA & SIMULADOR DE ENVÍO EN VIVO -->
                    <div class="col-12 col-xl-3">
                        <div class="eco-card mb-4" style="position: sticky; top: 16px;">
                            <div class="eco-card-header py-2.5 px-3">
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-mobile-screen-button text-success fs-5"></i>
                                    <div>
                                        <h6 class="mb-0 fw-bold text-dark" style="font-size: 0.9rem;">Simulador WhatsApp</h6>
                                        <small class="text-muted" style="font-size: 0.7rem;" id="lblSubtituloSimulador">Vista previa en tiempo real</small>
                                    </div>
                                </div>
                                <span class="badge bg-success-subtle text-success border border-success-subtle" id="badgeModoSimulador" style="font-size: 0.68rem;">
                                    <i class="fa-solid fa-eye me-1"></i>Diseño
                                </span>
                            </div>

                            <div class="p-3">
                                <!-- Cinta de Destinatario Actual (Cambia en vivo al ejecutar campaña) -->
                                <div class="p-2 px-2.5 mb-2 rounded-3 border d-flex align-items-center justify-content-between" style="background: #F8FAFC; font-size: 0.76rem;">
                                    <div class="text-truncate">
                                        <span class="text-muted">Destinatario <code>{{1}}</code>:</span>
                                        <strong class="text-dark ms-1" id="lblPreviewNombreCliente">Carlos (Ejemplo)</strong>
                                    </div>
                                    <code class="text-success fw-bold ms-2" id="lblPreviewTelefonoCliente">+58412...</code>
                                </div>

                                <!-- MOCKUP DE TELÉFONO WHATSAPP EN EL CENTRO -->
                                <div class="wa-phone-mockup">
                                    <div class="wa-phone-header">
                                        <div class="wa-phone-avatar">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <div class="fw-bold text-truncate" style="font-size: 0.82rem;" id="waPreviewSedeTitle">Sede Oficial STARFI</div>
                                            <div style="font-size: 0.68rem; opacity: 0.85;">
                                                <i class="fa-solid fa-circle-check text-info me-1"></i>Cuenta de empresa oficial
                                            </div>
                                        </div>
                                        <span class="badge bg-black bg-opacity-25 text-white" style="font-size: 0.64rem;" id="badgeHoraPhoneHeader">En línea</span>
                                    </div>
                                    <div class="wa-preview-frame">
                                        <div class="wa-bubble" id="waBubblePreview"></div>
                                    </div>
                                </div>

                                <!-- Consola de Progreso de Envío en Vivo integrada bajo el Teléfono Central -->
                                <div id="boxConsolaEnvio" class="p-3 rounded-3 bg-dark text-white mt-3 d-none">
                                    <div class="d-flex justify-content-between align-items-center mb-1 small">
                                        <span id="lblEstadoConsola" class="fw-bold text-warning" style="font-size: 0.76rem;">
                                            <i class="fa-solid fa-spinner fa-spin me-1"></i>Despachando Tanda...
                                        </span>
                                        <span id="lblContadorConsola" class="badge bg-secondary">0 / 0</span>
                                    </div>
                                    <div class="progress mb-2" style="height: 8px; background: rgba(255,255,255,0.15);">
                                        <div id="barProgresoConsola"
                                            class="progress-bar bg-success progress-bar-striped progress-bar-animated"
                                            style="width: 0%;"></div>
                                    </div>
                                    <div id="logConsolaEnvio" class="font-monospace small text-white-50 mb-2"
                                        style="max-height: 120px; overflow-y: auto; font-size: 0.72rem;"></div>
                                    <button type="button" id="btnDetenerCola" class="btn btn-danger btn-sm fw-bold w-100 d-none"
                                        onclick="detenerColaEnvio()">
                                        <i class="fa-solid fa-stop me-1"></i>Pausar Envío de Tanda
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- COLUMNA 3 (DERECHA): EMBUDO DE CAMPAÑAS, TRAZABILIDAD DE LECTURAS Y RESPUESTAS -->
                    <div class="col-12 col-xl-5">
                        <div class="eco-card">
                            <div class="eco-card-header flex-wrap gap-2">
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">
                                        <i class="fa-solid fa-chart-pie text-primary me-2"></i>Historial de Campañas &
                                        Embudo de Conversión
                                    </h6>
                                    <small class="text-muted">Mide en tiempo real cuántos mensajes fueron enviados,
                                        entregados, leídos y respondidos</small>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-dark active" onclick="filtrarHistorialCampanas('TODAS', this)">Todas</button>
                                        <button type="button" class="btn btn-outline-warning text-dark" onclick="filtrarHistorialCampanas('PENDIENTE_APROBACION', this)">Por Aprobar</button>
                                        <button type="button" class="btn btn-outline-info text-dark" onclick="filtrarHistorialCampanas('EN_CURSO', this)">En Curso</button>
                                        <button type="button" class="btn btn-outline-success" onclick="filtrarHistorialCampanas('COMPLETADA', this)">Completadas</button>
                                    </div>
                                    <span class="badge bg-light text-dark border" id="badgeTotalCampanas">0 campañas</span>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" style="font-size: 0.84rem;">
                                    <thead class="table-light text-secondary small text-uppercase">
                                        <tr>
                                            <th class="ps-3">Campaña / Sede</th>
                                            <th class="text-center">Progreso & Costo</th>
                                            <th class="text-center">Estado & Horario</th>
                                            <th class="text-end pe-3">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyCampanas">
                                        <tr>
                                            <td colspan="4" class="text-center py-5 text-muted">
                                                <i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando ecosistema de
                                                campañas...
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Barra de Paginación Compacta del Historial de Campañas -->
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-light" id="barPaginacionCampanas" style="border-radius: 0 0 16px 16px; font-size: 0.76rem;">
                                <span class="text-muted" id="lblResumenPaginacionCampanas">Mostrando 0 de 0</span>
                                <div class="d-flex align-items-center gap-1" id="controlesPaginacionCampanas"></div>
                            </div>
                        </div>
                    </div>

                </div>
                    </div><!-- /#ecoWorkspaceContent -->
                </div><!-- /#ecoWorkspaceWrapper -->
            </div>
        </main>
    </div>

    <!-- MODAL 1: CONFIGURACIÓN DE LÍMITE Y RITMO DIARIO POR SEDE -->
    <div class="modal fade" id="modalFlujoSede" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
                <div class="modal-header bg-dark text-white" style="border-radius: 16px 16px 0 0;">
                    <h6 class="modal-title fw-bold">
                        <i class="fa-solid fa-shield-halved text-warning me-2"></i>Límite y Ritmo Diario de la Sede
                    </h6>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="alert alert-info small py-2 mb-3">
                        <i class="fa-solid fa-circle-info me-1"></i>
                        Proteja la reputación del número oficial de la sede definiendo un tope de mensajes diarios y la
                        pausa entre envíos para evitar bloqueos de Meta (Error 131049).
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Límite Máximo de Mensajes Diarios para esta Sede</label>
                        <input type="number" id="cfgLimiteDiario" class="form-control" min="10" max="100000"
                            value="250">
                        <small class="text-muted">Recomendado por nivel WABA: Tier Inicial = <code>250/día</code>, Nivel
                            Estándar = <code>1,000/día</code>.</small>
                    </div>

                    <div class="mb-0">
                        <label class="form-label small fw-bold">Cadencia / Ritmo entre Mensajes (Anti-Spam)</label>
                        <select id="cfgCadenciaMs" class="form-select">
                            <option value="500">Rápido (0.5 segundos por mensaje)</option>
                            <option value="1000" selected>Seguro Recomendado (1.0 segundo por mensaje)</option>
                            <option value="2000">Conservador Anti-Bloqueo (2.0 segundos por mensaje)</option>
                            <option value="3500">Ultra-Cuidadoso (3.5 segundos por mensaje)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary btn-sm fw-bold"
                        style="background-color: #E85B14; border-color: #E85B14;"
                        onclick="guardarConfiguracionFlujoSede()">
                        <i class="fa-solid fa-floppy-disk me-1"></i>Guardar Límite y Ritmo
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
                    <!-- Embudo Visual de Conversión dentro del Modal (Estilo Wati / Respond.io) -->
                    <div class="row g-2 mb-3" id="detFunnelResumenRow">
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Enviados</div>
                                <div class="fs-5 fw-bold text-primary" id="detKpiEnviados">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Entregados</div>
                                <div class="fs-5 fw-bold text-dark" id="detKpiEntregados">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Leídos (Open)</div>
                                <div class="fs-5 fw-bold text-info" id="detKpiLeidos">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Respondidos</div>
                                <div class="fs-5 fw-bold text-success" id="detKpiRespondidos">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Fallidos</div>
                                <div class="fs-5 fw-bold text-danger" id="detKpiFallidos">0</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="p-2.5 px-3 rounded-3 border bg-light h-100">
                                <div class="text-muted small fw-bold text-uppercase" style="font-size: 0.66rem;">Inversión USD</div>
                                <div class="fs-5 fw-bold" style="color: #E85B14;" id="detKpiCosto">$0.00</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filtros rápidos dentro del modal + Buscador + Exportar CSV -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div class="btn-group btn-group-sm" role="group" id="detGrupoFiltrosEstadoModal">
                            <button type="button" class="btn btn-outline-dark active"
                                onclick="filtrarTablaDestinatariosModal('TODOS', this)">Todos</button>
                            <button type="button" class="btn btn-outline-success"
                                onclick="filtrarTablaDestinatariosModal('RESPONDIDO', this)">Respondidos</button>
                            <button type="button" class="btn btn-outline-info"
                                onclick="filtrarTablaDestinatariosModal('LEIDO', this)">Leídos</button>
                            <button type="button" class="btn btn-outline-secondary"
                                onclick="filtrarTablaDestinatariosModal('PENDIENTE', this)">Pendientes</button>
                            <button type="button" class="btn btn-outline-danger"
                                onclick="filtrarTablaDestinatariosModal('FALLIDO', this)">Fallidos</button>
                        </div>
                        <div class="d-flex flex-wrap align-items-center gap-2 ms-auto">
                            <div class="input-group input-group-sm" style="width: 240px;">
                                <span class="input-group-text bg-white text-muted"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="text" id="detInputBuscarModal" class="form-control" placeholder="Buscar cliente, tlf o sede..." oninput="buscarEnDestinatariosModal(this.value)">
                            </div>
                            <select id="detSelectPageSizeModal" class="form-select form-select-sm" style="width: auto;" onchange="cambiarPageSizeDestinatariosModal(this.value)">
                                <option value="10" selected>10 / pág.</option>
                                <option value="25">25 / pág.</option>
                                <option value="50">50 / pág.</option>
                                <option value="100">100 / pág.</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-outline-success fw-semibold" onclick="exportarDestinatariosCampanaCsv()">
                                <i class="fa-solid fa-file-csv me-1"></i>Exportar CSV
                            </button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0" style="font-size: 0.84rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Cliente</th>
                                    <th>Sede Cliente</th>
                                    <th>Teléfono</th>
                                    <th class="text-center">Estado</th>
                                    <th>Enviado / Tanda</th>
                                    <th>Leído (Apertura)</th>
                                    <th>Respuesta / Detalle Meta</th>
                                    <th class="text-end">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyDetalleDestinatarios"></tbody>
                        </table>
                    </div>

                    <!-- Barra de Paginación del Modal de Destinatarios -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center pt-3 mt-2 border-top gap-2" style="font-size: 0.8rem;">
                        <span class="text-muted fw-semibold" id="detResumenModal">Mostrando 0 de 0 destinatarios</span>
                        <div class="d-flex align-items-center gap-1" id="detControlesPaginacionModal"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL 3: DIÁLOGO CORPORATIVO DE CONFIRMACIÓN Y NOTIFICACIONES STARFI -->
    <div class="modal fade" id="modalStarfiDialog" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content starfi-dialog-card">
                <div class="starfi-dialog-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-3">
                        <div id="starfiDialogIcon" class="starfi-dialog-icon-circle" style="background: rgba(232, 91, 20, 0.2); color: #FB923C;">
                            <i class="fa-solid fa-bullhorn"></i>
                        </div>
                        <div>
                            <span id="starfiDialogBadge" class="badge mb-1" style="background-color: #E85B14; font-size: 0.68rem;">CONFIRMACIÓN DE CAMPAÑA</span>
                            <h6 class="mb-0 fw-bold text-white" id="starfiDialogTitle">Confirmar Operación</h6>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" id="btnStarfiDialogCloseX"></button>
                </div>
                <div class="modal-body p-4" id="starfiDialogBody" style="font-size: 0.9rem; color: #334155;">
                </div>
                <div class="modal-footer bg-light border-top px-4 py-3 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary fw-semibold px-3" id="btnStarfiDialogCancel" style="border-radius: 10px;">
                        Cancelar
                    </button>
                    <button type="button" class="btn text-white fw-bold px-4" id="btnStarfiDialogConfirm" style="background-color: #E85B14; border-radius: 10px;">
                        Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CONTENEDOR DE TOASTS CORPORATIVOS -->
    <div class="toast-container position-fixed bottom-0 end-0 p-3" id="starfiToastContainer" style="z-index: 1090;"></div>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="funciones_campanas.js?v=<?= time() ?>"></script>
</body>

</html>