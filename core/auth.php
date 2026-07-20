<?php
// core/auth.php
// Guardián del sistema: Controla sesiones, timeouts y lectura de datos del operador

require_once __DIR__ . '/../config/database.php';

// Iniciar sesión segura si no existe
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function requireAuth() {
    // Límite de inactividad: 30 minutos (1800 segundos)
    $timeout_duration = 1800; 

    // Helper para detectar si es una petición AJAX
    $is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // 1. Validar si existe el ID de agente en la sesión
    if (!isset($_SESSION['agente_id']) || empty($_SESSION['agente_id'])) {
        if ($is_ajax) {
            header('HTTP/1.1 401 Unauthorized');
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'no_session', 'redirect' => '/starfi_crm/login.php']);
            exit();
        } else {
            // Redirigir al login
            header("Location: /starfi_crm/login.php");
            exit();
        }
    }

    // 2. Validar Timeout por inactividad
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
        session_unset();
        session_destroy();
        if ($is_ajax) {
            header('HTTP/1.1 401 Unauthorized');
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'session_expired', 'redirect' => '/starfi_crm/login.php?error=expired']);
            exit();
        } else {
            header("Location: /starfi_crm/login.php?error=expired");
            exit();
        }
    }

    // 3. Actualizar marca de tiempo de la última actividad
    $_SESSION['last_activity'] = time();
}

function getAgenteInfo() {
    if (!isset($_SESSION['agente_id'])) return null;
    
    $con = getDbConnection();
    $id = intval($_SESSION['agente_id']);
    
    if ($id === 1) {
        return [
            'id' => 1,
            'nombre_completo' => 'Acceso Master',
            'email' => 'master',
            'rol' => 'ADMIN',
            'id_sede' => 0,
            'limite_chats_simultaneos' => 999
        ];
    }
    
    // Primero buscar en el nuevo sistema de usuarios registrados
    $stmt_new = $con->prepare("SELECT u.id, up.nombre AS nombre_completo, up.correo AS email, 'AGENTE' AS rol, NULL AS id_sede, 5 AS limite_chats_simultaneos FROM usuario u JOIN usuario_perfil up ON u.id = up.id_usuario WHERE u.id = ?");
    if ($stmt_new) {
        $stmt_new->bind_param("i", $id);
        $stmt_new->execute();
        $res_new = $stmt_new->get_result();
        if ($row_new = $res_new->fetch_assoc()) {
            $stmt_new->close();
            return $row_new;
        }
        $stmt_new->close();
    }
    
    // Fallback: Obtener los datos frescos de la BD para agentes heredados
    $stmt = $con->prepare("SELECT id, nombre_completo, email, rol, id_sede, limite_chats_simultaneos FROM usuarios_agentes WHERE id = ? AND estado = 'ACTIVO'");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            return $row;
        }
        $stmt->close();
    }
    
    // Si no lo encuentra o no está ACTIVO, forzamos cierre de sesión
    session_unset();
    session_destroy();
    header("Location: /starfi_crm/login.php");
    exit();
}

/**
 * Renderiza el sidebar de navegación dinámica para todos los módulos de STARFI CRM.
 * @param string $active El identificador del módulo activo.
 */
function renderSidebar($active = '') {
    global $nombre_agente;
    if (!isset($nombre_agente) || empty($nombre_agente)) {
        $agente = getAgenteInfo();
        $nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
    }
    
    $items = [
        'dashboard_main' => ['link' => '../../index.php', 'icon' => 'fa-solid fa-house', 'label' => 'Volver al Dashboard'],
        'bandeja' => ['link' => '../bandeja/bandeja.php', 'icon' => 'fa-solid fa-inbox', 'label' => 'Bandeja Omnicanal'],
        'directorio' => ['link' => '../directorio/directorio.php', 'icon' => 'fa-solid fa-address-book', 'label' => 'Directorio 360'],
        'dashboard' => ['link' => '../dashboard/dashboard.php', 'icon' => 'fa-solid fa-chart-line', 'label' => 'Métricas y KPIs'],
        'gestor_bots' => ['link' => '../gestor_bots/gestor_bots.php', 'icon' => 'fa-solid fa-robot', 'label' => 'Gestor de Bots'],
        'configuracion' => ['link' => '../configuracion/configuracion.php', 'icon' => 'fa-solid fa-gear', 'label' => 'Configuración']
    ];
    
    echo '    <aside class="sidebar" id="sidebar">';
    echo '        <div class="sidebar-header">';
    echo '            <a href="../../index.php" class="logo" style="cursor: pointer; text-decoration: none; display: flex; align-items: center; gap: 10px; color: inherit;">';
    echo '                <img src="../../docs/identidad_visual/logos/isologo.png" alt="STARFI" style="height: 30px;">';
    echo '                <span>STARFI CRM</span>';
    echo '            </a>';
    echo '        </div>';
    echo '        <nav class="sidebar-nav">';
    
    foreach ($items as $key => $info) {
        $activeClass = ($key === $active) ? ' active' : '';
        echo '            <a href="' . $info['link'] . '" class="nav-item' . $activeClass . '">';
        echo '                <i class="' . $info['icon'] . '"></i>';
        echo '                <span class="nav-text">' . $info['label'] . '</span>';
        echo '            </a>';
    }
    
    echo '        </nav>';
    echo '        <div class="sidebar-footer">';
    echo '            <div class="agent-profile" style="display: flex; align-items: center; width: 100%; gap: 10px; padding: 5px;">';
    echo '                <img src="https://ui-avatars.com/api/?name=' . urlencode($nombre_agente) . '&background=EBF4FF&color=1E3A8A" alt="Avatar" style="width: 32px; height: 32px; border-radius: 50%;">';
    echo '                <div class="agent-info" style="flex-grow: 1; overflow: hidden; display: flex; flex-direction: column;">';
    echo '                    <span class="agent-name" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 90px; display: inline-block; font-size: 0.85rem; font-weight: 600; color: #1E293B; text-align: left;">' . htmlspecialchars($nombre_agente) . '</span>';
    echo '                    <span class="agent-status online" style="font-size: 0.75rem; color: #10B981; display: block; text-align: left;">En línea</span>';
    echo '                </div>';
    echo '                <a href="/starfi_crm/logout.php" class="btn text-danger p-1 m-0" title="Cerrar Sesión" style="font-size: 1.1rem; background: transparent; border: none; padding: 0;">';
    echo '                    <i class="fa-solid fa-power-off"></i>';
    echo '                </a>';
    echo '            </div>';
    echo '        </div>';
    echo '    </aside>';
}

/**
 * Renderiza el encabezado superior (Navbar) en todos los módulos de STARFI CRM.
 * @param string $title Título del módulo para mostrar en la cabecera.
 */
function renderHeader($title = '') {
    global $nombre_agente, $rol_agente;
    if (!isset($nombre_agente) || empty($nombre_agente)) {
        $agente = getAgenteInfo();
        $nombre_agente = $agente['nombre_completo'] ?? 'Usuario';
    }
    if (!isset($rol_agente) || empty($rol_agente)) {
        $agente = getAgenteInfo();
        $rol_agente = $agente['rol'] ?? 'AGENTE';
    }
    
    echo '    <nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom py-2 px-4" style="z-index: 100; height: 60px; box-shadow: 0 2px 10px rgba(0,0,0,0.02);">';
    echo '        <div class="container-fluid d-flex align-items-center justify-content: space-between; padding: 0;">';
    echo '            <div class="d-flex align-items-center gap-3">';
    echo '                <a class="navbar-brand d-flex align-items-center p-0 m-0" href="../../index.php">';
    echo '                    <img src="../../docs/identidad_visual/logos/logo_starfi.png" alt="STARFI CRM" style="height: 32px;">';
    echo '                </a>';
    echo '                <span class="text-muted d-none d-md-inline">|</span>';
    echo '                <h5 class="mb-0 fw-bold text-starfi-dark d-none d-md-inline" style="font-size: 1.1rem;">' . htmlspecialchars($title) . '</h5>';
    echo '            </div>';
    echo '            <div class="d-flex align-items-center gap-3 ms-auto">';
    echo '                <a href="../../index.php" class="btn btn-outline-secondary btn-sm px-3 py-1.5 fw-semibold d-flex align-items-center gap-1" style="border-radius: 8px; font-size: 0.85rem; border-color: #CBD5E1; color: #475569;">';
    echo '                    <i class="fa-solid fa-arrow-left"></i> Volver al Dashboard';
    echo '                </a>';
    echo '                <div class="d-flex align-items-center gap-2 border-start ps-3">';
    echo '                    <div style="width: 32px; height: 32px; border-radius: 50%; background: linear-gradient(135deg, #E85B14, #FF8A4D); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; box-shadow: 0 2px 5px rgba(232, 91, 20, 0.2);">';
    echo                           strtoupper(substr($nombre_agente, 0, 1));
    echo '                    </div>';
    echo '                    <div class="d-none d-lg-block text-start lh-1">';
    echo '                        <div class="fw-bold mb-0" style="font-size: 0.85rem; color: #1E293B;">' . htmlspecialchars(explode(' ', $nombre_agente)[0]) . '</div>';
    echo '                        <span class="text-muted" style="font-size: 0.7rem;">' . htmlspecialchars($rol_agente) . '</span>';
    echo '                    </div>';
    echo '                </div>';
    echo '                <a href="/starfi_crm/logout.php" class="btn btn-outline-danger btn-sm px-2.5 py-1.5 fw-semibold d-flex align-items-center gap-1" style="border-radius: 8px; font-size: 0.85rem;">';
    echo '                    <i class="fa-solid fa-power-off"></i> <span class="d-none d-sm-inline">Salir</span>';
    echo '                </a>';
    echo '            </div>';
    echo '        </div>';
    echo '    </nav>';
}
?>