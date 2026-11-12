<?php
require_once __DIR__ . '/../../core/auth.php';
requireAuth();
requirePermission('bandeja');
$agente = getAgenteInfo();
$nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Centro de Mensajes | CRM STARFI</title>
    <link rel="icon" href="../../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
    <!-- CSS Local de Bootstrap -->
    <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- Iconos de Bootstrap (Local) -->
    <link rel="stylesheet" href="../../assets/icons/bootstrap-icons/font/bootstrap-icons.min.css">
    <!-- Tema Global STARFI -->
    <link href="../../assets/css/starfi_theme.css" rel="stylesheet">

    <!-- FontAwesome Local -->
    <link rel="stylesheet" href="../../assets/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css?v=<?= time() + 3 ?>">
    <!-- Emojis Nativos (Sin dependencias externas) -->
    <style>
        /* Modernización Premium Bandeja Omnicanal */
        .chats-panel {
            background-color: #F8FAFC !important;
            border-right: 1px solid #E2E8F0 !important;
        }
        .chats-header {
            background-color: #ffffff !important;
            padding: 20px !important;
            border-bottom: 1px solid #E2E8F0 !important;
        }
        .chats-header h2 {
            font-size: 1.25rem !important;
            font-weight: 700 !important;
            color: var(--starfi-dark) !important;
        }
        .tabs {
            display: flex !important;
            gap: 4px !important;
            overflow-x: auto !important;
            white-space: nowrap !important;
            padding-bottom: 4px !important;
            scrollbar-width: thin;
        }
        .tabs::-webkit-scrollbar {
            height: 3px;
        }
        .tabs::-webkit-scrollbar-thumb {
            background-color: #CBD5E1;
            border-radius: 4px;
        }
        .tabs .tab {
            font-weight: 600 !important;
            color: #64748B !important;
            border-radius: 20px !important;
            padding: 5px 12px !important;
            font-size: 0.8rem !important;
            transition: all 0.2s ease !important;
            border: none !important;
            background: transparent !important;
            flex-shrink: 0 !important;
        }
        .tabs .tab.active {
            background-color: rgba(232, 91, 20, 0.1) !important;
            color: var(--primary) !important;
        }
        .tabs .tab:hover:not(.active) {
            background-color: #F1F5F9 !important;
        }
        
        .search-bar {
            background-color: #F1F5F9 !important;
            border-radius: 30px !important;
            padding: 8px 16px !important;
            border: 1px solid transparent !important;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .search-bar:focus-within {
            background-color: #ffffff !important;
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.1);
        }
        .search-bar i {
            color: #64748B;
            font-size: 0.9rem;
        }
        .search-bar input {
            border: none !important;
            background: transparent !important;
            outline: none !important;
            width: 100%;
            font-size: 0.9rem;
            color: #1E293B;
        }
        .search-bar input::placeholder {
            color: #94A3B8;
        }

        /* Conversation list items */
        .chat-item {
            border-radius: 12px !important;
            margin: 8px 12px !important;
            padding: 12px 16px !important;
            transition: all 0.2s ease !important;
            border: 1px solid transparent !important;
            background-color: #ffffff;
        }
        .chat-item:hover {
            background-color: #F1F5F9 !important;
            transform: translateY(-1px);
        }
        .chat-item.active {
            background-color: #ffffff !important;
            border-color: var(--primary) !important;
            box-shadow: 0 4px 15px rgba(232, 91, 20, 0.1) !important;
        }

        /* Right Panel Premium */
        .conversation-panel {
            background-color: #ffffff !important;
        }
        .conv-header {
            background-color: rgba(255, 255, 255, 0.95) !important;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid #E2E8F0 !important;
            padding: 15px 30px !important;
            box-shadow: 0 2px 10px rgba(0,0,0,0.02) !important;
        }
        .client-info img {
            border-radius: 50% !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .icon-btn {
            background-color: #F8FAFC !important;
            border: 1px solid #E2E8F0 !important;
            border-radius: 50% !important;
            width: 40px !important;
            height: 40px !important;
            color: #64748B !important;
            transition: all 0.3s ease !important;
        }
        .icon-btn:hover {
            background-color: var(--primary) !important;
            color: white !important;
            border-color: var(--primary) !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(232, 91, 20, 0.2);
        }

        /* Chat Bubbles */
        .messages-area {
            background-color: #F8FAFC !important;
            background-image: url('data:image/svg+xml,%3Csvg width="20" height="20" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"%3E%3Cg fill="%23CBD5E1" fill-opacity="0.2" fill-rule="evenodd"%3E%3Ccircle cx="3" cy="3" r="3"/%3E%3Ccircle cx="13" cy="13" r="3"/%3E%3C/g%3E%3C/svg%3E') !important;
            padding: 30px !important;
        }
        .msg-bubble {
            border-radius: 18px !important;
            padding: 12px 18px !important;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05) !important;
            font-size: 0.95rem !important;
            line-height: 1.5 !important;
        }
        .msg.sent .msg-bubble {
            background: linear-gradient(135deg, #E85B14 0%, #ff7a33 100%) !important;
            color: white !important;
            border-bottom-right-radius: 4px !important;
        }
        .msg.received .msg-bubble {
            background-color: #ffffff !important;
            color: var(--text-main) !important;
            border-bottom-left-radius: 4px !important;
            border: 1px solid #E2E8F0;
        }

        /* Input Area Premium */
        .input-area {
            background-color: #ffffff !important;
            border-top: 1px solid #E2E8F0 !important;
            padding: 20px 30px !important;
        }
        .input-box {
            background-color: #F8FAFC !important;
            border-radius: 24px !important;
            padding: 5px 10px !important;
            border: 1px solid #E2E8F0 !important;
            display: flex;
            align-items: center;
        }
        .input-box textarea {
            background: transparent !important;
            border: none !important;
            padding: 10px 15px !important;
            font-size: 0.95rem !important;
        }
        .send-btn {
            background-color: var(--primary) !important;
            border-radius: 50% !important;
            width: 40px !important;
            height: 40px !important;
            box-shadow: 0 4px 10px rgba(232, 91, 20, 0.3) !important;
            transition: transform 0.2s;
        }
        .send-btn:hover {
            transform: scale(1.05);
        }

        /* Profile Sidebar Premium */
        .profile-preview {
            border-left: 1px solid #E2E8F0 !important;
            background-color: #F8FAFC !important;
            box-shadow: -5px 0 15px rgba(0,0,0,0.03) !important;
        }
        .profile-card {
            background-color: #ffffff !important;
            border-radius: 16px !important;
            padding: 30px 20px !important;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03) !important;
            border: 1px solid #E2E8F0;
        }
        .profile-details .detail-group {
            background-color: #ffffff !important;
            border-radius: 12px !important;
            padding: 15px !important;
            border: 1px solid #E2E8F0;
            margin-bottom: 10px;
        }
        .emoji-picker {
            display: none;
            position: absolute;
            bottom: 80px;
            left: 20px;
            background: #ffffff;
            border: 1px solid #E2E8F0;
            border-radius: 12px;
            width: 280px;
            max-height: 200px;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            z-index: 1000;
            padding: 10px;
            flex-wrap: wrap;
            gap: 5px;
        }
        .emoji-picker.show {
            display: flex;
        }
        .emoji-btn {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            border-radius: 8px;
            padding: 5px;
            transition: transform 0.2s;
        }
        .emoji-btn:hover {
            transform: scale(1.2);
            background: #F1F5F9;
        }

        /* [COM-04] Semáforo SLA en vivo */
        @keyframes pulseSlaRed {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.45); }
            70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .sla-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.2px;
        }
        .sla-pill.sla-green {
            background-color: #ECFDF5;
            color: #047857;
            border: 1px solid #A7F3D0;
        }
        .sla-pill.sla-yellow {
            background-color: #FFFBEB;
            color: #B45309;
            border: 1px solid #FDE68A;
        }
        .sla-pill.sla-red {
            background-color: #FEF2F2;
            color: #DC2626;
            border: 1px solid #FECACA;
            animation: pulseSlaRed 1.8s infinite;
        }
        .sla-pill.sla-replied {
            background-color: #F8FAFC;
            color: #64748B;
            border: 1px solid #E2E8F0;
            font-weight: 600;
        }
        .chat-item.sla-item-yellow {
            border-left: 3.5px solid #F59E0B !important;
        }
        .chat-item.sla-item-red {
            border-left: 4px solid #EF4444 !important;
            background-color: #FFFDFD !important;
        }

        /* [COM-01] Contador regresivo Ventana Meta 24h y Bloqueo Automático */
        .meta-window-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 4px 12px;
            font-size: 0.76rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            transition: all 0.2s ease;
        }
        .meta-window-pill.win-open {
            background-color: #ECFDF5;
            color: #059669;
            border: 1px solid #A7F3D0;
        }
        .meta-window-pill.win-warn {
            background-color: #FFFBEB;
            color: #D97706;
            border: 1px solid #FDE68A;
        }
        .meta-window-pill.win-crit {
            background-color: #FEF2F2;
            color: #DC2626;
            border: 1px solid #FECACA;
            animation: pulseSlaRed 1.5s infinite;
        }
        .meta-window-pill.win-expired {
            background-color: #FEF2F2;
            color: #B91C1C;
            border: 1px solid #FCA5A5;
        }
        .expired-window-banner {
            display: none;
            background: linear-gradient(135deg, #FFF7ED 0%, #FEF2F2 100%);
            border: 1px solid #FDBA74;
            border-radius: 16px;
            padding: 12px 16px;
            margin-bottom: 10px;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .expired-window-banner.active {
            display: flex;
        }
        .input-box.locked-24h {
            opacity: 0.55;
            background-color: #F1F5F9 !important;
            pointer-events: none;
        }
        .deal-header-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 999px;
            padding: 4px 11px;
            font-size: 0.74rem;
            font-weight: 700;
            background-color: #EFF6FF;
            color: #1D4ED8;
            border: 1px solid #BFDBFE;
            cursor: pointer;
            transition: all 0.2s;
        }
        .deal-header-chip:hover {
            background-color: #DBEAFE;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <?php renderHeader('Centro de Mensajes'); ?>
    <div class="app-container">

    <!-- Sidebar Navigation -->

    <!-- Main Layout -->
    <main class="main-content">
        
        <!-- Chats Panel (Left Column) -->
        <section class="chats-panel">
            <header class="chats-header">
                <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-1">
                    <h2 class="mb-0">Conversaciones</h2>
                    <div class="d-flex align-items-center gap-1">
                        <?php if (intval($agente['id'] ?? 0) > 1): ?>
                        <button type="button" id="btnAvailabilityToggle" class="btn btn-sm rounded-pill px-2 py-1 fw-bold" style="font-size: 0.7rem; background:#ECFDF5; color:#059669; border:1px solid #A7F3D0;" title="Estado para Enrutamiento Automático Round-Robin">
                            <i class="fa-solid fa-circle me-1" style="font-size:0.5rem;"></i> <span>En línea</span>
                        </button>
                        <?php endif; ?>
                        <button type="button" id="btnSlaSoundToggle" class="btn btn-sm btn-light border rounded-pill px-2 py-1 fw-semibold" style="font-size: 0.72rem; color:#475569;" title="Activar/Silenciar Alerta Sonora SLA (>15 min)">
                            <i class="fa-solid fa-volume-high text-success"></i>
                        </button>
                        <?php if (hasPermission('pipeline')): ?>
                        <a href="../pipeline/pipeline.php" class="btn btn-sm rounded-pill px-2.5 py-1 fw-bold text-decoration-none" style="font-size: 0.72rem; background:rgba(232,91,20,0.1); color:#E85B14; border:1px solid rgba(232,91,20,0.25);" title="Abrir Tablero Kanban de Embudo de Ventas">
                            <i class="fa-solid fa-filter-circle-dollar me-1"></i> Kanban
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Search -->
                <div class="search-bar mb-2">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" placeholder="Buscar cliente, número...">
                </div>
                
                <!-- Filter Sede -->
                <?php
                $con = getDbConnection('core');
                $sedes = [];
                if ($con) {
                    $resSedes = mysqli_query($con, "SELECT id, nombre_sede FROM sedes WHERE estado = 'ACTIVO'");
                    if ($resSedes) {
                        while ($row = mysqli_fetch_assoc($resSedes)) {
                            $sedes[] = $row;
                        }
                    }
                }
                $agente_actual = getAgenteInfo();
                $user_assigned_sede = isset($agente_actual['id_sede']) ? intval($agente_actual['id_sede']) : 0;
                ?>
                <div class="mb-2">
                    <?php 
                    $rol_agente = strtoupper(trim($agente_actual['rol'] ?? ''));
                    $agente_id_num = intval($agente_actual['id'] ?? 0);
                    $is_master = ($agente_id_num === 1 || $rol_agente === 'MASTER' || $rol_agente === 'MASTER CI');
                    $is_operador = (!$is_master && ($rol_agente === 'OPERADOR' || $rol_agente === 'AGENTE' || $rol_agente === '3'));
                    
                    if ($is_master): 
                        // Únicamente los usuarios Master ven el selector desplegable de Sedes
                    ?>
                        <select id="filterSede" class="form-select form-select-sm mb-2" style="border-radius: 20px; padding: 6px 15px; border-color: #E2E8F0; font-size: 0.85rem; color: #475569; background-color: #F8FAFC;">
                            <option value="">Todas las Sedes</option>
                            <?php foreach ($sedes as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre_sede']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" id="filterSede" value="<?= $user_assigned_sede ?>">
                    <?php endif; ?>

                    <?php if (!$is_operador): ?>
                        <select id="filterOperador" class="form-select form-select-sm" style="border-radius: 20px; padding: 6px 15px; border-color: #E2E8F0; font-size: 0.85rem; color: #475569; background-color: #F8FAFC;">
                            <option value="">Todos los Operadores</option>
                        </select>
                    <?php endif; ?>
                </div>

                <!-- [COM-04] Resumen Semáforo SLA en vivo -->
                <div id="slaSummaryStrip" class="d-flex align-items-center justify-content-between px-2 py-1 mb-2 rounded-3" style="background:#F8FAFC; border:1px solid #E2E8F0; font-size:0.7rem;">
                    <span class="text-muted fw-semibold"><i class="fa-solid fa-traffic-light me-1"></i> SLA:</span>
                    <span class="text-success fw-bold" title="Menos de 5 min sin respuesta humana">🟢 <span id="slaCountGreen">0</span> <small class="fw-normal">&lt;5m</small></span>
                    <span class="text-warning-emphasis fw-bold" title="Entre 5 y 15 min sin respuesta humana">🟡 <span id="slaCountYellow">0</span> <small class="fw-normal">5-15m</small></span>
                    <span class="text-danger fw-bold" style="cursor:pointer;" id="slaQuickFilterRed" title="Más de 15 min sin respuesta humana (clic para filtrar)">🔴 <span id="slaCountRed">0</span> <small class="fw-normal">&gt;15m</small></span>
                </div>

                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab active" data-target="todos">Todos</button>
                    <?php if (!$is_operador): ?>
                        <button class="tab" data-target="clientes"><i class="fa-solid fa-user me-1"></i> Clientes</button>
                        <button class="tab" data-target="campanas"><i class="fa-solid fa-bullhorn me-1"></i> Difusión</button>
                        <button class="tab" data-target="ventas"><i class="fa-solid fa-shopping-bag me-1"></i> Ventas</button>
                    <?php endif; ?>
                    <button class="tab" data-target="sla-critico" title="Conversaciones con más de 15 min sin respuesta humana"><i class="fa-solid fa-bell text-danger me-1"></i> SLA &gt;15m <span class="badge bg-danger ms-1" id="badgeSlaCritico" style="display:none;">0</span></button>
                    <button class="tab" data-target="no-leido">No Leído <span class="badge" id="badgeNoLeidos" style="display:none;">0</span></button>
                    <button class="tab" data-target="cerrados">Cerrados</button>
                </div>
            </header>

            <!-- Chat List -->
            <div class="chat-list" id="chatList">
                <div class="text-center p-4 mt-4">
                    <div class="spinner-border text-secondary mb-2" role="status"></div>
                    <p class="text-muted" style="font-size: 0.85rem;">Conectando al servidor...</p>
                </div>
            </div>
        </section>

        <!-- Active Conversation Panel (Right Column) -->
        <section class="conversation-panel" style="position: relative;">
            
            <!-- Empty State -->
            <div id="emptyState" style="display:flex; flex-direction:column; align-items:center; justify-content:center; width:100%; height:100%;" class="text-muted">
                <i class="fa-solid fa-comments fs-1 mb-3 opacity-50"></i>
                <h5>Bienvenido al Centro de Mensajes</h5>
                <p>Selecciona una conversación de la lista para comenzar a chatear.</p>
            </div>

            <!-- Active Chat View (Oculto hasta seleccionar) -->
            <div id="activeChatView" style="display:none; flex-direction:column; height:100%; width:100%;">
                <!-- Header -->
                <header class="conv-header">
                    <div class="client-info">
                        <button class="mobile-back-btn" id="btnBackToChats"><i class="fa-solid fa-arrow-left"></i></button>
                        <img id="chatHeaderImg" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="Avatar" style="background-color: #F3F4F6;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <h3 id="chatHeaderName" style="margin: 0; font-size: 1.15rem; font-weight: 700;">...</h3>
                                <span id="chatHeaderSede" class="badge bg-dark rounded-pill px-2 py-0.5 text-white" style="font-size: 0.7rem; background-color: #37414A !important; display: none;"><i class="fa-solid fa-store me-1"></i> <span>...</span></span>
                                <!-- [COM-01] Contador regresivo Ventana Meta 24h -->
                                <span id="metaWindowBadge" class="meta-window-pill win-open" style="display:none;" title="Tiempo restante de la Ventana Gratuita de 24h de Meta">
                                    <i class="fa-regular fa-clock"></i> <span id="metaWindowTimerText">24:00:00</span>
                                </span>
                                <!-- [COM-02] Chip rápido de Embudo y Monto USD -->
                                <span id="btnQuickDeal" class="deal-header-chip" title="Actualizar Etapa del Embudo y Monto Estimado USD">
                                    <i class="fa-solid fa-sack-dollar"></i> <span id="chatDealChipText">Nuevo Lead · $0.00</span>
                                </span>
                            </div>
                            <span id="chatHeaderPhone">...</span>
                        </div>
                    </div>
                    <div class="conv-actions">
                        <button class="icon-btn" title="Reactivar con Plantilla Oficial Meta" id="btnHeaderMetaTemplate" style="color:#E85B14 !important;"><i class="fa-brands fa-whatsapp"></i></button>
                        <button class="icon-btn" title="Ver Perfil 360" id="btnToggleProfile"><i class="fa-solid fa-id-card"></i></button>
                        <button class="icon-btn" title="Reasignar" id="btnReasign"><i class="fa-solid fa-user-plus"></i></button>
                        <button class="icon-btn" title="Cerrar Chat" id="btnCloseChat"><i class="fa-solid fa-check"></i></button>
                    </div>
                </header>

                <div class="messages-area" id="messagesArea">
                    <!-- Mensajes cargan aquí -->
                </div>

                <!-- Input Area -->
                <footer class="input-area" style="position:relative;">
                    <!-- [COM-01] Bloqueo automático al expirar Ventana Meta 24h -->
                    <div id="expiredWindowBanner" class="expired-window-banner">
                        <div class="d-flex align-items-center gap-2" style="flex: 1; min-width: 240px;">
                            <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; background: rgba(239,68,68,0.12); color: #DC2626;">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <div style="line-height: 1.35;">
                                <div class="fw-bold text-dark" style="font-size: 0.85rem;">Ventana Gratuita Meta de 24h Expirada</div>
                                <div class="text-muted" style="font-size: 0.76rem;">El envío libre está bloqueado por políticas de WhatsApp Cloud API. Envía una Plantilla Oficial aprobada para reactivar el chat (o escribe una Nota Interna).</div>
                            </div>
                        </div>
                        <button type="button" id="btnReactivateTemplate" class="btn btn-sm fw-bold text-white px-3 py-2 shadow-sm" style="background: linear-gradient(135deg, #E85B14, #FF7A33); border: none; border-radius: 999px; font-size: 0.8rem;">
                            <i class="fa-brands fa-whatsapp me-1"></i> Reactivar con Plantilla Oficial Meta
                        </button>
                    </div>

                    <div id="emojiPicker" class="emoji-picker">
                        <!-- Populated by JS -->
                    </div>
                    <div class="tools">
                        <input type="file" id="fileInput" style="display:none;" accept="image/*,application/pdf,video/mp4,image/webp">
                        <button class="tool-btn" id="btnAttach" title="Adjuntar"><i class="fa-solid fa-paperclip"></i></button>
                        <button class="tool-btn" id="btnTemplates" title="Respuestas Rápidas"><i class="fa-solid fa-bolt"></i></button>
                        <button class="tool-btn" id="btnEmoji" title="Emoji"><i class="fa-regular fa-face-smile"></i></button>
                        <button class="tool-btn" id="btnInternalNote" title="Nota Interna (Sólo Agentes)" style="margin-left: 10px; color: #9CA3AF;"><i class="fa-solid fa-user-secret"></i></button>
                    </div>
                    <div class="input-box" id="inputBoxContainer">
                        <textarea placeholder="Escribe un mensaje..." rows="1" id="chatInput"></textarea>
                        <button class="send-btn" id="sendBtn"><i class="fa-solid fa-paper-plane"></i></button>
                    </div>
                </footer>
            </div>
        </section>

    <!-- Modal Perfil 360 -->
    <div class="modal fade" id="modalProfile360" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
          <div class="modal-header border-0" style="background-color: #F8FAFC; border-radius: 20px 20px 0 0; padding: 20px 25px;">
            <h5 class="modal-title fw-bold text-starfi-dark"><i class="fa-solid fa-id-card text-primary me-2"></i>Perfil 360</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4 text-center" style="background-color: #F8FAFC; border-radius: 0 0 20px 20px;">
                <div class="profile-card bg-white p-4 mb-3" style="border-radius: 16px; border: 1px solid #E2E8F0; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                    <img id="profPrevImg" src="https://ui-avatars.com/api/?name=Cliente&background=F3F4F6&size=128" alt="Avatar" style="border-radius: 50%; margin-bottom: 15px; width: 100px; height: 100px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); object-fit: cover;">
                    <div class="input-group mb-2">
                        <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-user text-muted"></i></span>
                        <input type="text" id="profPrevNameInput" class="form-control border-start-0 fw-bold text-center" placeholder="Nombre del Cliente">
                    </div>
                    <p class="text-muted small mb-0">Cliente CRM</p>
                </div>
                <div class="profile-details text-start">
                    <div class="detail-group bg-white p-3 mb-2" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-1 d-block"><i class="fa-solid fa-phone me-1"></i> Teléfono</label>
                        <p id="profPrevPhone" class="mb-0 fw-semibold text-dark fs-5">+58 412 9876543</p>
                    </div>
                    
                    <div class="detail-group bg-white p-3 mb-2" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-1 d-block"><i class="fa-solid fa-envelope me-1"></i> Correo Electrónico</label>
                        <input type="email" id="profPrevEmailInput" class="form-control form-control-sm" placeholder="cliente@correo.com">
                    </div>

                    <div class="detail-group bg-white p-3 mb-3" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-2 d-block"><i class="fa-solid fa-tags me-1"></i> CENTRO DE ETIQUETAS</label>
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="newTagInput" class="form-control" placeholder="Añadir o seleccionar etiqueta..." list="existingTagsList">
                            <datalist id="existingTagsList"></datalist>
                            <button class="btn btn-outline-primary" type="button" id="btnAddTag"><i class="fa-solid fa-plus"></i></button>
                        </div>
                        <div class="tags" id="profTagsContainer">
                            <!-- Tags injected via JS -->
                        </div>
                    </div>
                    
                    <button class="btn btn-primary w-100 fw-bold shadow-sm" id="btnSaveProfile" style="border-radius: 10px; padding: 10px;"><i class="fa-solid fa-save me-2"></i> Guardar Cambios</button>
                </div>
          </div>
        </div>
      </div>
    </div>

    </main>

    <!-- Modal Plantillas Rápidas -->
    <div class="modal fade" id="modalTemplates" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
          <div class="modal-header border-0" style="background-color: #F8FAFC; border-radius: 20px 20px 0 0; padding: 20px 25px; display: flex; justify-content: space-between; align-items: center;">
            <h5 class="modal-title fw-bold text-starfi-dark m-0"><i class="fa-solid fa-bolt text-warning me-2"></i>Respuestas Rápidas</h5>
            <div>
                <button type="button" class="btn btn-sm btn-outline-primary fw-bold rounded-pill px-3 me-2" onclick="createNewQuickReply()"><i class="fa-solid fa-plus me-1"></i> Nueva</button>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
          </div>
          <div class="modal-body p-3" style="background-color: #F8FAFC; border-radius: 0 0 20px 20px; max-height: 70vh; overflow-y: auto;">
            <div class="mb-4">
                <h6 class="fw-bold text-muted mb-3"><i class="fa-solid fa-bolt me-1 text-warning"></i> Respuestas Rápidas</h6>
                <div class="list-group list-group-flush gap-2" id="templatesList">
                    <div class="text-center p-3 text-muted">
                        <i class="fa-solid fa-spinner fa-spin me-2"></i>Cargando respuestas...
                    </div>
                </div>
            </div>
            
            <div class="border-top pt-3">
                <h6 class="fw-bold text-muted mb-3"><i class="fa-solid fa-robot me-1 text-primary"></i> Respuestas Automáticas (Bot)</h6>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle" style="font-size: 0.85rem; border-collapse: separate; border-spacing: 0 4px;">
                        <thead class="table-light">
                            <tr>
                                <th style="border: none;">Disparador</th>
                                <th style="border: none;">Mensaje</th>
                                <th style="border: none; text-align: right;">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="botAnswersList">
                            <tr>
                                <td colspan="3" class="text-center text-muted py-3">Cargando respuestas automáticas...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- [COM-01] Modal Reactivar Conversación con Plantilla Oficial Meta -->
    <div class="modal fade" id="modalReactivateMeta" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,0.18);">
          <div class="modal-header border-0" style="background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%); color: white; border-radius: 20px 20px 0 0; padding: 18px 24px;">
            <div>
              <h5 class="modal-title fw-bold mb-0"><i class="fa-brands fa-whatsapp text-success me-2"></i>Reactivar Conversación con Plantilla Oficial Meta</h5>
              <small class="text-white-50" style="font-size: 0.78rem;">Envío homologado por WhatsApp Business Cloud API v23.0 para clientes fuera de la ventana de 24h</small>
            </div>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4" style="background-color: #F8FAFC;">
            <div class="row g-3">
              <div class="col-12 col-md-7">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <label class="form-label fw-bold text-dark small mb-0">1. Selecciona Plantilla Oficial Aprobada</label>
                  <button type="button" id="btnRefreshMetaTpls" class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold" style="font-size:0.75rem; color:#E85B14;">
                    <i class="fa-solid fa-rotate me-1"></i> Sincronizar con Meta
                  </button>
                </div>
                <select id="selectMetaOfficialTpl" class="form-select mb-3" style="border-radius: 12px;"></select>

                <div id="metaTplMetaBadges" class="d-flex gap-2 mb-3 flex-wrap"></div>

                <div id="metaTplVarsContainer">
                  <!-- Inputs dinámicos {{1}}, {{2}}... -->
                </div>
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label fw-bold text-dark small mb-1">Vista Previa en WhatsApp</label>
                <div class="p-3 rounded-4" style="background-color: #ECE5DD; min-height: 210px; border: 1px solid #E2E8F0;">
                  <div class="bg-white p-3 shadow-sm" style="border-radius: 14px 14px 4px 14px; font-size: 0.86rem; color: #111827; white-space: pre-line; line-height: 1.45;" id="metaTplLivePreview">
                    Selecciona una plantilla para previsualizar el mensaje...
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer border-0 bg-white" style="border-radius: 0 0 20px 20px; padding: 14px 24px;">
            <button type="button" class="btn btn-light border rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" id="btnSendMetaReactivate" class="btn text-white fw-bold rounded-pill px-4" style="background: linear-gradient(135deg, #10B981, #059669); border: none;">
              <i class="fa-solid fa-paper-plane me-1"></i> Enviar Plantilla Oficial
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- [COM-02] Modal Rápido de Oportunidad / Embudo Kanban USD -->
    <div class="modal fade" id="modalQuickDeal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; border: none; box-shadow: 0 15px 40px rgba(0,0,0,0.15);">
          <div class="modal-header border-0" style="background-color: #F8FAFC; border-radius: 20px 20px 0 0; padding: 18px 24px;">
            <h5 class="modal-title fw-bold text-starfi-dark"><i class="fa-solid fa-filter-circle-dollar text-primary me-2"></i>Oportunidad en Embudo de Ventas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-4">
            <div class="mb-3">
              <label class="form-label fw-bold small text-muted">Título / Producto de Interés</label>
              <input type="text" id="qdTitulo" class="form-control" placeholder="Ej: Cotización Láminas PVC / Mobiliario" style="border-radius: 10px;">
            </div>
            <div class="row g-3 mb-3">
              <div class="col-7">
                <label class="form-label fw-bold small text-muted">Etapa del Embudo</label>
                <select id="qdEtapa" class="form-select" style="border-radius: 10px;">
                  <option value="NUEVO_LEAD">📥 Nuevo Lead</option>
                  <option value="COTIZADO">📄 Cotizado</option>
                  <option value="EN_NEGOCIACION">🤝 En Negociación</option>
                  <option value="PAGO_PENDIENTE">💳 Pago Pendiente</option>
                  <option value="GANADO">🏆 Ganado (Venta Cerrada)</option>
                  <option value="PERDIDO">❌ Perdido</option>
                </select>
              </div>
              <div class="col-5">
                <label class="form-label fw-bold small text-muted">Monto Estimado (USD)</label>
                <div class="input-group">
                  <span class="input-group-text fw-bold">$</span>
                  <input type="number" step="0.01" min="0" id="qdMontoUsd" class="form-control fw-bold" value="0.00">
                </div>
              </div>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2">
              <a href="../pipeline/pipeline.php" class="text-decoration-none small fw-bold" style="color:#E85B14;">
                <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> Ir al Tablero Kanban completo
              </a>
              <button type="button" id="btnSaveQuickDeal" class="btn btn-primary fw-bold rounded-pill px-4" style="background-color:#E85B14; border-color:#E85B14;">
                <i class="fa-solid fa-floppy-disk me-1"></i> Guardar en Embudo
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toastContainer" class="toast-container"></div>

    <!-- JavaScript Local Bootstrap -->
    <script src="../../assets/js/bootstrap.bundle.min.js"></script>
    <!-- Dependencias globales para modales (jQuery y SweetAlert2) -->
    <script src="../../assets/js/jquery-3.7.1.min.js"></script>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>

    <script>
        window.userRole = "<?= addslashes(strtoupper(trim($agente['rol'] ?? 'AGENTE'))) ?>";
        window.isUserAdmin = <?= (in_array(strtoupper(trim($agente['rol'] ?? '')), ['MASTER', 'MASTER CI', 'ADMINISTRADOR', 'ADMIN', 'GERENTE']) || intval($agente['id'] ?? 0) === 1) ? 'true' : 'false' ?>;
        window.initialDisponibleChat = <?= intval($agente['disponible_chat'] ?? 1) ?>;
    </script>
    <script src="funciones_bandeja.js?v=<?= time() ?>"></script>
    </div>
</body>
</html>




