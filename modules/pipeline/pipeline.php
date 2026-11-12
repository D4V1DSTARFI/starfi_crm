<?php
// modules/pipeline/pipeline.php
// Tablero Kanban de Embudo de Ventas Enterprise (Estilo Kommo / Respond.io) | STARFI CRM [COM-02]

date_default_timezone_set('America/Caracas');
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('pipeline');

$agente = getAgenteInfo();
$agente_id = intval($agente['id'] ?? 0);
$rol_agente = strtoupper(trim((string)($agente['rol'] ?? 'AGENTE')));
$id_sede_agente = intval($agente['id_sede'] ?? 0);

$is_master = ($agente_id === 1 || $rol_agente === 'MASTER' || $rol_agente === 'MASTER CI' || ($rol_agente === 'ADMIN' && $id_sede_agente === 0));
$is_admin = (!$is_master && in_array($rol_agente, ['ADMINISTRADOR', 'ADMIN', 'GERENTE'], true));
$is_operador = (!$is_master && !$is_admin);

$con = getDbConnection('core');
$sedes = [];
$nombre_sede_propia = 'Sede Principal';
if ($con) {
    starfi_ensure_sprint2_schema($con);
    $rS = $con->query("SELECT id, nombre_sede FROM sedes WHERE estado = 'ACTIVO' ORDER BY nombre_sede ASC");
    if ($rS) {
        while ($row = $rS->fetch_assoc()) {
            $sedes[] = $row;
            if (intval($row['id']) === $id_sede_agente) {
                $nombre_sede_propia = $row['nombre_sede'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Embudo de Ventas (Kanban USD) | STARFI CRM</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
    <style>
        body {
            background-color: #F1F5F9;
            overflow-x: hidden;
        }
        .pipeline-wrapper {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 66px);
            overflow: hidden;
            padding: 16px 20px 12px 20px;
        }
        .pipeline-command-bar {
            background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
            border-radius: 16px;
            padding: 14px 20px;
            color: #FFFFFF;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
            margin-bottom: 12px;
            flex-shrink: 0;
        }
        .pipeline-filter-control {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #FFFFFF;
            border-radius: 10px;
            font-size: 0.82rem;
            font-weight: 600;
            padding: 6px 12px;
        }
        .pipeline-filter-control::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }
        .pipeline-filter-control:focus {
            background: rgba(255, 255, 255, 0.16);
            color: #FFFFFF;
            border-color: #E85B14;
            box-shadow: none;
        }
        .pipeline-filter-control option {
            color: #0F172A;
            background: #FFFFFF;
        }

        /* KPI Strip */
        .pipeline-kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
            gap: 12px;
            margin-bottom: 12px;
            flex-shrink: 0;
        }
        .pipeline-kpi-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 14px;
            padding: 11px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
        }
        .pipeline-kpi-card .kpi-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #64748B;
            font-weight: 700;
        }
        .pipeline-kpi-card .kpi-val {
            font-size: 1.2rem;
            font-weight: 800;
            color: #0F172A;
            font-variant-numeric: tabular-nums;
            line-height: 1.2;
        }
        .pipeline-kpi-card .kpi-sub {
            font-size: 0.72rem;
            color: #64748B;
        }
        .pipeline-kpi-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        /* Kanban Board */
        .kanban-board-scroll {
            display: flex;
            gap: 14px;
            overflow-x: auto;
            overflow-y: hidden;
            flex: 1;
            padding-bottom: 8px;
            align-items: stretch;
        }
        .kanban-board-scroll::-webkit-scrollbar {
            height: 9px;
        }
        .kanban-board-scroll::-webkit-scrollbar-track {
            background: #E2E8F0;
            border-radius: 99px;
        }
        .kanban-board-scroll::-webkit-scrollbar-thumb {
            background: #94A3B8;
            border-radius: 99px;
        }

        .kanban-column {
            min-width: 288px;
            width: 288px;
            max-width: 310px;
            flex: 1 0 288px;
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            max-height: 100%;
            transition: border-color 0.2s, background-color 0.2s, box-shadow 0.2s;
        }
        .kanban-column.drag-over {
            background: #FFF7ED;
            border: 2px dashed #E85B14;
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.08);
        }
        .kanban-col-header {
            padding: 12px 14px 10px 14px;
            border-bottom: 1px solid #E2E8F0;
            background: #FFFFFF;
            border-radius: 16px 16px 0 0;
            position: relative;
        }
        .kanban-col-accent {
            height: 4px;
            border-radius: 16px 16px 0 0;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
        }
        .kanban-col-title {
            font-size: 0.86rem;
            font-weight: 800;
            color: #1E293B;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 2px;
        }
        .kanban-col-usd {
            font-size: 0.78rem;
            font-weight: 800;
            color: #0F172A;
            margin-top: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-variant-numeric: tabular-nums;
        }
        .kanban-cards-list {
            padding: 10px;
            overflow-y: auto;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 120px;
        }
        .kanban-cards-list::-webkit-scrollbar {
            width: 5px;
        }
        .kanban-cards-list::-webkit-scrollbar-thumb {
            background: #CBD5E1;
            border-radius: 99px;
        }

        /* Kanban Card */
        .kanban-card {
            background: #FFFFFF;
            border: 1px solid #E2E8F0;
            border-radius: 13px;
            padding: 12px 13px;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.04);
            cursor: grab;
            transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
            position: relative;
        }
        .kanban-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
            border-color: #CBD5E1;
        }
        .kanban-card:active {
            cursor: grabbing;
        }
        .kanban-card.dragging {
            opacity: 0.45;
            transform: rotate(1.5deg) scale(0.98);
        }
        .kanban-card-usd {
            background: #ECFDF5;
            color: #047857;
            border: 1px solid #A7F3D0;
            border-radius: 999px;
            padding: 2px 9px;
            font-size: 0.74rem;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }
        .kanban-card-usd.zero {
            background: #F1F5F9;
            color: #64748B;
            border-color: #E2E8F0;
        }
        .kanban-empty-drop {
            border: 1.5px dashed #CBD5E1;
            border-radius: 12px;
            padding: 22px 12px;
            text-align: center;
            color: #94A3B8;
            font-size: 0.78rem;
        }

        @media (max-width: 992px) {
            .pipeline-wrapper {
                height: auto;
                overflow: visible;
            }
            .pipeline-kpi-strip {
                grid-template-columns: repeat(2, 1fr);
            }
            .kanban-board-scroll {
                min-height: 540px;
            }
        }
    </style>
</head>
<body>
    <?php renderHeader('Embudo de Ventas (Pipeline Kanban)'); ?>

    <div class="pipeline-wrapper">
        <!-- Barra de Comando y Filtros -->
        <div class="pipeline-command-bar">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <span class="badge rounded-pill px-3 py-1.5" style="background:#E85B14; font-size:0.73rem;">
                        <i class="fa-solid fa-filter-circle-dollar me-1"></i> PIPELINE USD
                    </span>
                    <?php if ($is_master): ?>
                        <select id="pipeFilterSede" class="form-select form-select-sm pipeline-filter-control" style="width:auto; min-width:190px;">
                            <option value="0">🌎 Todas las Sedes</option>
                            <?php foreach ($sedes as $s): ?>
                                <option value="<?= intval($s['id']) ?>"><?= htmlspecialchars($s['nombre_sede']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" id="pipeFilterSede" value="<?= intval($id_sede_agente) ?>">
                        <span class="badge bg-light text-dark rounded-pill px-3 py-1.5 fw-bold" style="font-size:0.75rem;">
                            <i class="fa-solid fa-store text-primary me-1"></i> <?= htmlspecialchars($nombre_sede_propia) ?>
                        </span>
                    <?php endif; ?>

                    <?php if (!$is_operador): ?>
                        <select id="pipeFilterAgente" class="form-select form-select-sm pipeline-filter-control" style="width:auto; min-width:185px;">
                            <option value="0">👥 Todos los Asesores</option>
                        </select>
                    <?php else: ?>
                        <input type="hidden" id="pipeFilterAgente" value="<?= intval($agente_id) ?>">
                    <?php endif; ?>

                    <select id="pipeFilterPeriodo" class="form-select form-select-sm pipeline-filter-control" style="width:auto;">
                        <option value="30d">Últimos 30 días</option>
                        <option value="90d" selected>Últimos 90 días</option>
                        <option value="mes">Este mes</option>
                        <option value="todo">Histórico completo</option>
                    </select>

                    <div class="position-relative">
                        <input type="text" id="pipeSearchInput" class="form-control form-control-sm pipeline-filter-control ps-4" placeholder="Buscar cliente, teléfono, producto..." style="min-width:230px;">
                        <i class="fa-solid fa-magnifying-glass position-absolute" style="left:11px; top:50%; transform:translateY(-50%); font-size:0.75rem; opacity:0.7;"></i>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <a href="../bandeja/bandeja.php" class="btn btn-sm btn-outline-light rounded-pill px-3 fw-semibold" style="font-size:0.78rem;">
                        <i class="fa-brands fa-whatsapp me-1 text-success"></i> Ir a Bandeja
                    </a>
                    <button type="button" id="btnSyncChats" class="btn btn-sm btn-light rounded-pill px-3 fw-bold text-dark" style="font-size:0.78rem;" title="Importar conversaciones activas de WhatsApp al Embudo">
                        <i class="fa-solid fa-arrows-rotate me-1 text-primary"></i> Sincronizar Chats
                    </button>
                    <button type="button" id="btnNewDeal" class="btn btn-sm text-white rounded-pill px-3 fw-bold shadow-sm" style="background:linear-gradient(135deg,#E85B14,#FF7A33); border:none; font-size:0.8rem;">
                        <i class="fa-solid fa-plus me-1"></i> Nueva Oportunidad
                    </button>
                </div>
            </div>
        </div>

        <!-- KPIs Ejecutivos en USD -->
        <div class="pipeline-kpi-strip">
            <div class="pipeline-kpi-card">
                <div>
                    <div class="kpi-label">Pipeline Activo (USD)</div>
                    <div class="kpi-val text-primary" id="kpiActivoUsd">$0.00</div>
                    <div class="kpi-sub">Ponderado: <strong id="kpiPonderadoUsd">$0.00</strong></div>
                </div>
                <div class="pipeline-kpi-icon" style="background:#EFF6FF; color:#2563EB;">
                    <i class="fa-solid fa-sack-dollar"></i>
                </div>
            </div>
            <div class="pipeline-kpi-card">
                <div>
                    <div class="kpi-label">Ventas Ganadas (USD)</div>
                    <div class="kpi-val text-success" id="kpiGanadoUsd">$0.00</div>
                    <div class="kpi-sub"><strong id="kpiGanadoCount">0</strong> cierres exitosos</div>
                </div>
                <div class="pipeline-kpi-icon" style="background:#ECFDF5; color:#059669;">
                    <i class="fa-solid fa-trophy"></i>
                </div>
            </div>
            <div class="pipeline-kpi-card">
                <div>
                    <div class="kpi-label">Tasa de Cierre</div>
                    <div class="kpi-val" style="color:#E85B14;" id="kpiTasaCierre">0.0%</div>
                    <div class="kpi-sub">Perdidos: <span id="kpiPerdidoUsd">$0.00</span> (<span id="kpiPerdidoCount">0</span>)</div>
                </div>
                <div class="pipeline-kpi-icon" style="background:#FFF7ED; color:#EA580C;">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
            </div>
            <div class="pipeline-kpi-card">
                <div>
                    <div class="kpi-label">Ticket Promedio (USD)</div>
                    <div class="kpi-val text-dark" id="kpiTicketPromedio">$0.00</div>
                    <div class="kpi-sub">Total tarjetas: <strong id="kpiTotalDeals">0</strong></div>
                </div>
                <div class="pipeline-kpi-icon" style="background:#F1F5F9; color:#334155;">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
            </div>
        </div>

        <!-- Tablero Kanban Drag & Drop (Nuevo Lead, Cotizado, En Negociación, Pago Pendiente, Ganado / Perdido) -->
        <div class="kanban-board-scroll" id="kanbanBoard">
            <!-- 1. Nuevo Lead -->
            <div class="kanban-column" data-stage="NUEVO_LEAD">
                <div class="kanban-col-header">
                    <div class="kanban-col-accent" style="background:#3B82F6;"></div>
                    <div class="kanban-col-title">
                        <span><i class="fa-solid fa-inbox text-primary me-1"></i> Nuevo Lead</span>
                        <span class="badge bg-light text-dark border rounded-pill" id="count-NUEVO_LEAD">0</span>
                    </div>
                    <div class="kanban-col-usd">
                        <span class="text-muted fw-semibold" style="font-size:0.7rem;">Prob. 20%</span>
                        <span class="text-primary" id="sum-NUEVO_LEAD">$0.00 USD</span>
                    </div>
                </div>
                <div class="kanban-cards-list" id="col-NUEVO_LEAD" data-stage="NUEVO_LEAD"></div>
            </div>

            <!-- 2. Cotizado -->
            <div class="kanban-column" data-stage="COTIZADO">
                <div class="kanban-col-header">
                    <div class="kanban-col-accent" style="background:#8B5CF6;"></div>
                    <div class="kanban-col-title">
                        <span><i class="fa-solid fa-file-invoice-dollar me-1" style="color:#8B5CF6;"></i> Cotizado</span>
                        <span class="badge bg-light text-dark border rounded-pill" id="count-COTIZADO">0</span>
                    </div>
                    <div class="kanban-col-usd">
                        <span class="text-muted fw-semibold" style="font-size:0.7rem;">Prob. 45%</span>
                        <span style="color:#7C3AED;" id="sum-COTIZADO">$0.00 USD</span>
                    </div>
                </div>
                <div class="kanban-cards-list" id="col-COTIZADO" data-stage="COTIZADO"></div>
            </div>

            <!-- 3. En Negociación -->
            <div class="kanban-column" data-stage="EN_NEGOCIACION">
                <div class="kanban-col-header">
                    <div class="kanban-col-accent" style="background:#F59E0B;"></div>
                    <div class="kanban-col-title">
                        <span><i class="fa-solid fa-handshake text-warning me-1"></i> En Negociación</span>
                        <span class="badge bg-light text-dark border rounded-pill" id="count-EN_NEGOCIACION">0</span>
                    </div>
                    <div class="kanban-col-usd">
                        <span class="text-muted fw-semibold" style="font-size:0.7rem;">Prob. 70%</span>
                        <span style="color:#B45309;" id="sum-EN_NEGOCIACION">$0.00 USD</span>
                    </div>
                </div>
                <div class="kanban-cards-list" id="col-EN_NEGOCIACION" data-stage="EN_NEGOCIACION"></div>
            </div>

            <!-- 4. Pago Pendiente -->
            <div class="kanban-column" data-stage="PAGO_PENDIENTE">
                <div class="kanban-col-header">
                    <div class="kanban-col-accent" style="background:#E85B14;"></div>
                    <div class="kanban-col-title">
                        <span><i class="fa-solid fa-credit-card me-1" style="color:#E85B14;"></i> Pago Pendiente</span>
                        <span class="badge bg-light text-dark border rounded-pill" id="count-PAGO_PENDIENTE">0</span>
                    </div>
                    <div class="kanban-col-usd">
                        <span class="text-muted fw-semibold" style="font-size:0.7rem;">Prob. 90%</span>
                        <span style="color:#E85B14;" id="sum-PAGO_PENDIENTE">$0.00 USD</span>
                    </div>
                </div>
                <div class="kanban-cards-list" id="col-PAGO_PENDIENTE" data-stage="PAGO_PENDIENTE"></div>
            </div>

            <!-- 5A. Ganado (Cierre Exitoso) -->
            <div class="kanban-column" data-stage="GANADO">
                <div class="kanban-col-header">
                    <div class="kanban-col-accent" style="background:#10B981;"></div>
                    <div class="kanban-col-title">
                        <span><i class="fa-solid fa-trophy text-success me-1"></i> Ganado</span>
                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill" id="count-GANADO">0</span>
                    </div>
                    <div class="kanban-col-usd">
                        <span class="text-muted fw-semibold" style="font-size:0.7rem;">Venta Cerrada (100%)</span>
                        <span class="text-success" id="sum-GANADO">$0.00 USD</span>
                    </div>
                </div>
                <div class="kanban-cards-list" id="col-GANADO" data-stage="GANADO"></div>
            </div>

            <!-- 5B. Perdido (Cierre Descartado) -->
            <div class="kanban-column" data-stage="PERDIDO">
                <div class="kanban-col-header">
                    <div class="kanban-col-accent" style="background:#EF4444;"></div>
                    <div class="kanban-col-title">
                        <span><i class="fa-solid fa-circle-xmark text-danger me-1"></i> Perdido</span>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill" id="count-PERDIDO">0</span>
                    </div>
                    <div class="kanban-col-usd">
                        <span class="text-muted fw-semibold" style="font-size:0.7rem;">No concretado (0%)</span>
                        <span class="text-danger" id="sum-PERDIDO">$0.00 USD</span>
                    </div>
                </div>
                <div class="kanban-cards-list" id="col-PERDIDO" data-stage="PERDIDO"></div>
            </div>
        </div>
    </div>

    <!-- Modal Crear / Editar Oportunidad Comercial -->
    <div class="modal fade" id="modalDealKanban" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 50px rgba(15,23,42,0.18);">
                <div class="modal-header border-0" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); color: white; border-radius: 20px 20px 0 0; padding: 18px 24px;">
                    <h5 class="modal-title fw-bold mb-0" id="modalDealTitle"><i class="fa-solid fa-sack-dollar text-warning me-2"></i>Nueva Oportunidad Comercial</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" style="background:#F8FAFC;">
                    <input type="hidden" id="dealIdInput" value="0">
                    <div class="row g-3">
                        <div class="col-12 col-md-8">
                            <label class="form-label small fw-bold text-dark">Título / Producto Cotizado *</label>
                            <input type="text" id="dealTituloInput" class="form-control" placeholder="Ej: 25 Láminas Mármol PVC + Instalación">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold text-dark">Monto Estimado (USD) *</label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold bg-white text-success">$</span>
                                <input type="number" step="0.01" min="0" id="dealMontoInput" class="form-control fw-bold" value="0.00">
                                <span class="input-group-text small text-muted bg-light">USD</span>
                            </div>
                        </div>

                        <!-- Bloque Cliente (visible al crear) -->
                        <div class="col-12" id="dealClientBlock">
                            <div class="p-3 bg-white rounded-3 border">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <label class="form-label small fw-bold text-dark mb-0"><i class="fa-brands fa-whatsapp text-success me-1"></i>Cliente Vinculado</label>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" id="chkNuevoProspecto">
                                        <label class="form-check-label small fw-semibold text-muted" for="chkNuevoProspecto">Registrar número nuevo</label>
                                    </div>
                                </div>
                                <div id="existingClientPane">
                                    <input type="text" id="searchClientInput" class="form-control form-control-sm mb-2" placeholder="Escribe nombre o WhatsApp para filtrar clientes...">
                                    <select id="dealClienteSelect" class="form-select form-select-sm"></select>
                                </div>
                                <div id="newClientPane" style="display:none;">
                                    <div class="row g-2">
                                        <div class="col-12 col-md-6">
                                            <input type="text" id="dealNuevoNombre" class="form-control form-control-sm" placeholder="Nombre del cliente (Ej: María Pérez)">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <input type="text" id="dealNuevoTelefono" class="form-control form-control-sm" placeholder="WhatsApp con código país (Ej: 584121234567)">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold text-dark">Etapa del Embudo</label>
                            <select id="dealEtapaSelect" class="form-select">
                                <option value="NUEVO_LEAD">📥 Nuevo Lead (20%)</option>
                                <option value="COTIZADO">📄 Cotizado (45%)</option>
                                <option value="EN_NEGOCIACION">🤝 En Negociación (70%)</option>
                                <option value="PAGO_PENDIENTE">💳 Pago Pendiente (90%)</option>
                                <option value="GANADO">🏆 Ganado (100%)</option>
                                <option value="PERDIDO">❌ Perdido (0%)</option>
                            </select>
                        </div>

                        <?php if ($is_master): ?>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold text-dark">Sede</label>
                            <select id="dealSedeSelect" class="form-select">
                                <?php foreach ($sedes as $s): ?>
                                    <option value="<?= intval($s['id']) ?>"><?= htmlspecialchars($s['nombre_sede']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" id="dealSedeSelect" value="<?= intval($id_sede_agente) ?>">
                        <?php endif; ?>

                        <?php if (!$is_operador): ?>
                        <div class="col-12 col-md-4">
                            <label class="form-label small fw-bold text-dark">Asesor Responsable</label>
                            <select id="dealAgenteSelect" class="form-select">
                                <option value="0">-- Sin asignar --</option>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" id="dealAgenteSelect" value="<?= intval($agente_id) ?>">
                        <?php endif; ?>

                        <div class="col-12" id="dealMotivoPerdidaWrap" style="display:none;">
                            <label class="form-label small fw-bold text-danger">Motivo de Pérdida</label>
                            <input type="text" id="dealMotivoPerdidaInput" class="form-control" placeholder="Ej: Precio fuera de presupuesto / Compró en otra tienda / Sin respuesta">
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-bold text-dark">Notas Comerciales / Seguimiento</label>
                            <textarea id="dealNotasInput" class="form-control" rows="2" placeholder="Detalles de medidas, condiciones de pago, fecha tentativa de compra..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-white d-flex justify-content-between" style="border-radius: 0 0 20px 20px; padding: 14px 24px;">
                    <div>
                        <?php if (!$is_operador): ?>
                        <button type="button" id="btnDeleteDeal" class="btn btn-outline-danger btn-sm rounded-pill px-3" style="display:none;">
                            <i class="fa-solid fa-trash-can me-1"></i> Eliminar
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light border rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" id="btnSaveDealModal" class="btn text-white fw-bold rounded-pill px-4" style="background:linear-gradient(135deg,#E85B14,#FF7A33); border:none;">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Guardar Oportunidad
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script>
        window.pipelineIsOperador = <?= $is_operador ? 'true' : 'false' ?>;
        window.pipelineDefaultSede = <?= intval($id_sede_agente) ?>;
    </script>
    <script src="funciones_pipeline.js?v=<?= time() ?>"></script>
</body>
</html>

