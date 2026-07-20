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
    <title>Bandeja Omnicanal | CRM STARFI</title>
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/styles.css">
    <!-- Emoji Picker -->
    <script src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@4.6.0/dist/index.min.js"></script>
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
        .tabs .tab {
            font-weight: 600 !important;
            color: #64748B !important;
            border-radius: 20px !important;
            padding: 8px 16px !important;
            transition: all 0.3s ease !important;
            border: none !important;
            background: transparent !important;
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
            padding: 10px 20px !important;
            border: 1px solid transparent !important;
            transition: all 0.3s ease;
        }
        .search-bar:focus-within {
            background-color: #ffffff !important;
            border-color: var(--primary) !important;
            box-shadow: 0 0 0 4px rgba(232, 91, 20, 0.1);
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
    </style>
</head>
<body>

    <!-- Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo" id="toggleSidebar" style="cursor: pointer;">
                <img src="../../docs/identidad_visual/logos/isologo.png" alt="STARFI" style="height: 30px;">
                <span>STARFI CRM</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="../bandeja/bandeja.php" class="nav-item active"><i class="fa-solid fa-inbox"></i>
                <span class="nav-text">Bandeja Omnicanal</span>
            </a>
            <a href="../directorio/directorio.php" class="nav-item"><i class="fa-solid fa-address-book"></i>
                <span class="nav-text">Directorio 360</span>
            </a>
            <a href="../dashboard/dashboard.php" class="nav-item"><i class="fa-solid fa-chart-line"></i>
                <span class="nav-text">Métricas y KPIs</span>
            </a>
            <a href="../gestor_bots/gestor_bots.php" class="nav-item"><i class="fa-solid fa-robot"></i><span class="nav-text">Gestor de Bots</span></a>
            <a href="../configuracion/configuracion.php" class="nav-item"><i class="fa-solid fa-gear"></i>
                <span class="nav-text">Configuración</span>
            </a>
        </nav>
        
        <div class="sidebar-footer">
            <a href="/starfi_crm/logout.php" class="btn btn-danger w-100 py-2 d-flex align-items: center justify-content: center gap-2 fw-semibold" style="border-radius: 10px; font-size: 0.85rem; background-color: var(--starfi-danger) !important; border-color: var(--starfi-danger) !important;" title="Cerrar Sesión">
                <i class="fa-solid fa-power-off"></i>
                <span class="logout-text">Cerrar Sesión</span>
            </a>
        </div>
    </aside>

    <!-- Main Layout -->
    <main class="main-content">
        
        <!-- Chats Panel (Left Column) -->
        <section class="chats-panel">
            <header class="chats-header">
                <h2>Conversaciones</h2>
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab active" data-target="todos">Todos</button>
                    <button class="tab" data-target="no-leido">No Leído <span class="badge" id="badgeNoLeidos" style="display:none;">0</span></button>
                </div>
                <!-- Search -->
                <div class="search-bar">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" placeholder="Buscar cliente, número...">
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
                <h5>Bienvenido a tu Bandeja</h5>
                <p>Selecciona una conversación de la lista para comenzar a chatear.</p>
            </div>

            <!-- Active Chat View (Oculto hasta seleccionar) -->
            <div id="activeChatView" style="display:none; flex-direction:column; height:100%; width:100%;">
                <!-- Header -->
                <header class="conv-header">
                    <div class="client-info">
                        <img id="chatHeaderImg" src="data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=" alt="Avatar" style="background-color: #F3F4F6;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                <h3 id="chatHeaderName" style="margin: 0; font-size: 1.15rem; font-weight: 700;">...</h3>
                                <span id="chatHeaderSede" class="badge bg-dark rounded-pill px-2 py-0.5 text-white" style="font-size: 0.7rem; background-color: #37414A !important; display: none;"><i class="fa-solid fa-store me-1"></i> <span>...</span></span>
                            </div>
                            <span id="chatHeaderPhone">...</span>
                        </div>
                    </div>
                    <div class="conv-actions">
                        <button class="icon-btn" title="Ver Perfil 360" id="btnToggleProfile"><i class="fa-solid fa-id-card"></i></button>
                        <button class="icon-btn" title="Reasignar" id="btnReasign"><i class="fa-solid fa-user-plus"></i></button>
                        <button class="icon-btn" title="Cerrar Chat" id="btnCloseChat"><i class="fa-solid fa-check"></i></button>
                    </div>
                </header>

                <div class="messages-area" id="messagesArea">
                    <!-- Mensajes cargan aquí -->
                </div>

                <!-- Input Area -->
                <footer class="input-area">
                    <div class="tools">
                        <input type="file" id="fileInput" style="display:none;" accept="image/*,application/pdf,video/mp4">
                        <button class="tool-btn" id="btnAttach" title="Adjuntar"><i class="fa-solid fa-paperclip"></i></button>
                        <button class="tool-btn" id="btnTemplates" title="Plantillas"><i class="fa-solid fa-bolt"></i></button>
                        <button class="tool-btn" id="btnEmoji" title="Emoji"><i class="fa-regular fa-face-smile"></i></button>
                    </div>
                    <div class="input-box">
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
                    <img id="profPrevImg" src="https://ui-avatars.com/api/?name=Empresa+X&background=F3F4F6&size=128" alt="Avatar" style="border-radius: 50%; margin-bottom: 15px; width: 100px; height: 100px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); object-fit: cover;">
                    <h4 id="profPrevName" class="fw-bold mb-1">Empresa Corp. S.A.</h4>
                    <p class="text-muted small mb-0">Cliente</p>
                </div>
                <div class="profile-details text-start">
                    <div class="detail-group bg-white p-3 mb-2" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-1 d-block"><i class="fa-solid fa-phone me-1"></i> Teléfono</label>
                        <p id="profPrevPhone" class="mb-0 fw-semibold text-dark fs-5">+58 412 9876543</p>
                    </div>
                    <div class="detail-group bg-white p-3" style="border-radius: 12px; border: 1px solid #E2E8F0;">
                        <label class="text-muted small fw-bold mb-2 d-block"><i class="fa-solid fa-tags me-1"></i> Etiquetas</label>
                        <div class="tags">
                            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill px-3 py-2 border border-primary">VIP</span>
                        </div>
                    </div>
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
          <div class="modal-header border-0" style="background-color: #F8FAFC; border-radius: 20px 20px 0 0; padding: 20px 25px;">
            <h5 class="modal-title fw-bold text-starfi-dark"><i class="fa-solid fa-bolt text-warning me-2"></i>Respuestas Rápidas</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-3" style="background-color: #F8FAFC; border-radius: 0 0 20px 20px;">
            <div class="list-group list-group-flush gap-2" id="templatesList">
                <button type="button" class="list-group-item list-group-item-action p-3" style="border-radius: 12px; border: 1px solid #E2E8F0; margin-bottom: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);" onclick="selectTemplate('Hola, soy el asesor asignado. ¿En qué puedo ayudarte?')">
                    <strong class="text-primary"><i class="fa-solid fa-hand-wave me-1"></i> Saludo inicial</strong><br>
                    <small class="text-muted mt-1 d-block">Hola, soy el asesor asignado. ¿En qué puedo ayudarte?</small>
                </button>
                <button type="button" class="list-group-item list-group-item-action p-3" style="border-radius: 12px; border: 1px solid #E2E8F0; margin-bottom: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);" onclick="selectTemplate('Por favor envíame tu número de identificación para verificar en el sistema.')">
                    <strong class="text-primary"><i class="fa-solid fa-id-card me-1"></i> Solicitar ID</strong><br>
                    <small class="text-muted mt-1 d-block">Por favor envíame tu número de identificación...</small>
                </button>
                <button type="button" class="list-group-item list-group-item-action p-3" style="border-radius: 12px; border: 1px solid #E2E8F0; margin-bottom: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);" onclick="selectTemplate('Dame un momento mientras verifico la información. No te retires por favor.')">
                    <strong class="text-primary"><i class="fa-solid fa-clock me-1"></i> Espera un momento</strong><br>
                    <small class="text-muted mt-1 d-block">Dame un momento mientras verifico la información...</small>
                </button>
                <button type="button" class="list-group-item list-group-item-action p-3" style="border-radius: 12px; border: 1px solid #E2E8F0; margin-bottom: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);" onclick="selectTemplate('Tu solicitud ha sido procesada con éxito. ¡Gracias por contactarnos!')">
                    <strong class="text-primary"><i class="fa-solid fa-check-circle me-1"></i> Despedida / Éxito</strong><br>
                    <small class="text-muted mt-1 d-block">Tu solicitud ha sido procesada con éxito...</small>
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

    <script src="funciones_bandeja.js?v=<?= time() ?>"></script>
</body>
</html>




