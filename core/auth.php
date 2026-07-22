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
            'rol' => 'MASTER',
            'id_sede' => 0,
            'limite_chats_simultaneos' => 999
        ];
    }
    
    // Primero buscar en el nuevo sistema de usuarios registrados
    $stmt_new = $con->prepare("SELECT u.id, up.nombre AS nombre_completo, up.correo AS email, r.nombre AS rol, u.id_sede, u.estado, 
                                      (SELECT nombre_sede FROM sedes WHERE id = u.id_sede LIMIT 1) AS sede_nombre
                               FROM usuario u 
                               JOIN usuario_perfil up ON u.id = up.id_usuario 
                               LEFT JOIN roles r ON u.rol = r.id
                               WHERE u.id = ?");
    if ($stmt_new) {
        $stmt_new->bind_param("i", $id);
        $stmt_new->execute();
        $res_new = $stmt_new->get_result();
        if ($row_new = $res_new->fetch_assoc()) {
            $stmt_new->close();
            if (empty($row_new['rol'])) {
                $row_new['rol'] = 'SIN_ROL';
            }
            return $row_new;
        }
        $stmt_new->close();
    }
    
    // Si no lo encuentra o no está ACTIVO, forzamos cierre de sesión
    session_unset();
    session_destroy();
    header("Location: /starfi_crm/login.php");
    exit();
}

/**
 * Verifica si el usuario actual tiene permiso para acceder a un módulo específico.
 * @param string $modulo Nombre identificador del módulo.
 * @return bool
 */
function hasPermission($modulo) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['agente_id'])) {
        return false;
    }
    
    // El home/dashboard principal siempre está permitido
    if ($modulo === 'dashboard_main') {
        return true;
    }
    
    $con = getDbConnection('core');
    if (!$con) {
        return false;
    }
    
    $id = intval($_SESSION['agente_id']);
    
    // Obtener rol del usuario
    $stmt = $con->prepare("SELECT u.rol, r.nombre AS rol_nombre 
                           FROM usuario u 
                           LEFT JOIN roles r ON u.rol = r.id 
                           WHERE u.id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();
            
            // MASTER tiene bypass global (únicamente la palabra exacta 'MASTER')
            if ($row['rol_nombre'] === 'MASTER') {
                return true;
            }
            
            $rol_id = intval($row['rol']);
            
            // Consultar permisos_roles
            $stmt_perm = $con->prepare("SELECT permitido FROM permisos_roles WHERE id_rol = ? AND modulo = ?");
            if ($stmt_perm) {
                $stmt_perm->bind_param("is", $rol_id, $modulo);
                $stmt_perm->execute();
                $res_perm = $stmt_perm->get_result();
                if ($row_perm = $res_perm->fetch_assoc()) {
                    $stmt_perm->close();
                    return (intval($row_perm['permitido']) === 1);
                }
                $stmt_perm->close();
            }
        } else {
            $stmt->close();
        }
    }
    
    // Fallback por defecto si no hay regla explícita
    return false;
}

/**
 * Bloquea el acceso a la página actual si el usuario no tiene permisos para el módulo especificado.
 * @param string $modulo Nombre identificador del módulo.
 */
function requirePermission($modulo) {
    if (!hasPermission($modulo)) {
        // Redirigir a una pantalla de error o mostrar mensaje
        echo '<!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Acceso Denegado | STARFI CRM</title>
            <link href="../../assets/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
            <link href="../../assets/css/starfi_theme.css" rel="stylesheet">
            <style>
                body { background-color: #F8FAFC; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: "Inter", sans-serif; }
                .error-card { background: #html; border-radius: 16px; padding: 40px; text-align: center; max-width: 480px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #E2E8F0; background-color: white; }
            </style>
        </head>
        <body>
            <div class="error-card">
                <div class="mx-auto rounded-circle d-flex align-items-center justify-content-center mb-4" style="background-color: rgba(239, 68, 68, 0.1); width: 80px; height: 80px; color: #EF4444;">
                    <i class="fa-solid fa-shield-halved" style="font-size: 2.5rem;"></i>
                </div>
                <h4 class="fw-bold text-dark mb-3">Acceso Restringido</h4>
                <p class="text-muted mb-4" style="font-size: 0.95rem; line-height: 1.6;">
                    Su rol actual no cuenta con autorización para acceder al módulo de <strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $modulo))) . '</strong>.
                </p>
                <a href="../../index.php" class="btn btn-primary px-5 py-2.5 fw-semibold text-white" style="background-color: var(--primary); border-color: var(--primary); border-radius: 30px; text-decoration: none;">
                    Volver al Dashboard
                </a>
            </div>
        </body>
        </html>';
        exit();
    }
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
        'perfil_empresa' => ['link' => '../perfil_empresa/index.php', 'icon' => 'fa-solid fa-building', 'label' => 'Perfil Empresa'],
        'directorio' => ['link' => '../directorio/directorio.php', 'icon' => 'fa-solid fa-address-book', 'label' => 'Directorio 360'],
        'gestion_usuarios' => ['link' => '../gestion_usuarios/index.php', 'icon' => 'fa-solid fa-users', 'label' => 'Gestión Usuarios'],
        'gestion_roles' => ['link' => '../gestion_roles/index.php', 'icon' => 'fa-solid fa-user-shield', 'label' => 'Roles y Permisos'],
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
        if (!hasPermission($key)) {
            continue;
        }
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
    echo '            </div>';
    echo '        </div>';
    echo '    </nav>';
}
?>