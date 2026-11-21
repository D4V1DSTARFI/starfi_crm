<?php
// core/auth.php
// Guardián del sistema: Controla sesiones, timeouts y lectura de datos del operador

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/crm_sprint2_helper.php';

// Iniciar sesión segura si no existe
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Calcula la ruta relativa hacia la raíz del proyecto desde el script actual (sin fijar /starfi_crm/).
 */
function starfi_base_rel_prefix() {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_FILENAME'] ?? __DIR__));
    $rootDir = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: dirname(__DIR__));
    if ($rootDir !== '' && strpos($scriptDir, $rootDir) === 0) {
        $sub = trim(substr($scriptDir, strlen($rootDir)), '/');
        if ($sub === '') {
            return '';
        }
        $depth = substr_count($sub, '/') + 1;
        return str_repeat('../', $depth);
    }
    return '';
}

/**
 * Detecta si la petición espera respuesta JSON (fetch con Accept: application/json, XHR o controlador back_*.php).
 */
function starfi_expects_json_response() {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (strpos($accept, 'application/json') !== false) {
        return true;
    }
    $scriptBase = basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    if (strpos($scriptBase, 'back_') === 0) {
        return true;
    }
    foreach (headers_list() as $hdr) {
        if (stripos($hdr, 'Content-Type:') === 0 && stripos($hdr, 'application/json') !== false) {
            return true;
        }
    }
    return false;
}

function requireAuth() {
    // Límite de inactividad: 30 minutos (1800 segundos)
    $timeout_duration = 1800; 

    $is_json = starfi_expects_json_response();
    $login_url = starfi_base_rel_prefix() . 'login.php';

    // 1. Validar si existe el ID de agente en la sesión
    if (!isset($_SESSION['agente_id']) || empty($_SESSION['agente_id'])) {
        if ($is_json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'no_session', 'redirect' => $login_url]);
            exit();
        } else {
            header("Location: " . $login_url);
            exit();
        }
    }

    // 2. Validar Timeout por inactividad
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
        session_unset();
        session_destroy();
        if ($is_json) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'session_expired', 'redirect' => $login_url . '?error=expired']);
            exit();
        } else {
            header("Location: " . $login_url . "?error=expired");
            exit();
        }
    }

    // 3. Verificar en tiempo real que el usuario exista y permanezca ACTIVO en BD
    $uid_check = intval($_SESSION['agente_id']);
    $con_hb = getDbConnection('core');
    if ($con_hb) {
        $stmt_st = $con_hb->prepare("SELECT estado FROM usuario WHERE id = ? LIMIT 1");
        if ($stmt_st) {
            $stmt_st->bind_param("i", $uid_check);
            $stmt_st->execute();
            $res_st = $stmt_st->get_result();
            $row_st = $res_st ? $res_st->fetch_assoc() : null;
            $stmt_st->close();
            if (!$row_st || strtoupper(trim((string)($row_st['estado'] ?? ''))) !== 'ACTIVO') {
                session_unset();
                session_destroy();
                if ($is_json) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'account_inactive', 'redirect' => $login_url . '?state=inactive']);
                    exit();
                } else {
                    header("Location: " . $login_url . "?state=inactive");
                    exit();
                }
            }
        }
        if ($uid_check > 1) {
            starfi_touch_agent_presence($con_hb, $uid_check, false);
        }
    }

    // 4. Actualizar marca de tiempo de la última actividad
    $_SESSION['last_activity'] = time();
}

function getAgenteInfo() {
    if (!isset($_SESSION['agente_id'])) return null;
    
    $con = getDbConnection('core');
    $id = intval($_SESSION['agente_id']);
    
    if ($con) {
        starfi_ensure_sprint2_schema($con);
    }

    // Consultar usuario exigiendo estrictamente estado = 'ACTIVO' (LEFT JOIN a usuario_perfil para cubrir id=1 y agentes)
    $stmt_new = $con ? $con->prepare("SELECT u.id, u.usuario, up.nombre AS nombre_perfil, up.correo AS email_perfil,
                                      r.nombre AS rol, u.id_sede, u.id_empresa, u.estado,
                                      IFNULL(u.limite_chats_simultaneos, 0) AS limite_chats_simultaneos,
                                      IFNULL(u.disponible_chat, 1) AS disponible_chat,
                                      (SELECT nombre_sede FROM sedes WHERE id = u.id_sede LIMIT 1) AS sede_nombre
                               FROM usuario u 
                               LEFT JOIN usuario_perfil up ON u.id = up.id_usuario 
                               LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id
                               WHERE u.id = ? AND u.estado = 'ACTIVO'
                               LIMIT 1") : false;
    if ($stmt_new) {
        $stmt_new->bind_param("i", $id);
        $stmt_new->execute();
        $res_new = $stmt_new->get_result();
        if ($row_new = $res_new->fetch_assoc()) {
            $stmt_new->close();
            if (strtoupper(trim((string)($row_new['estado'] ?? ''))) !== 'ACTIVO') {
                $row_new = null;
            } else {
                $row_new['nombre_completo'] = !empty($row_new['nombre_perfil'])
                    ? $row_new['nombre_perfil']
                    : (!empty($row_new['usuario']) ? $row_new['usuario'] : 'Acceso Master');
                $row_new['email'] = !empty($row_new['email_perfil'])
                    ? $row_new['email_perfil']
                    : ($row_new['usuario'] ?? '');
                if ((int)$row_new['id'] === 1 && empty($row_new['rol'])) {
                    $row_new['rol'] = 'MASTER';
                } elseif (empty($row_new['rol'])) {
                    $row_new['rol'] = 'SIN_ROL';
                }
                if ((int)$row_new['id'] === 1 && (int)$row_new['limite_chats_simultaneos'] <= 0) {
                    $row_new['limite_chats_simultaneos'] = 999;
                }
                return $row_new;
            }
        } else {
            $stmt_new->close();
        }
    }
    
    // Si no lo encuentra o está INACTIVO, revocamos inmediatamente la sesión
    session_unset();
    session_destroy();
    $login_url = starfi_base_rel_prefix() . 'login.php?state=inactive';
    if (starfi_expects_json_response()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'account_inactive', 'redirect' => $login_url]);
        exit();
    }
    header("Location: " . $login_url);
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
    
    $con = getDbConnection('core');
    if (!$con) {
        return false;
    }
    
    $id = intval($_SESSION['agente_id']);
    
    // Obtener rol y estado activo del usuario
    $stmt = $con->prepare("SELECT u.rol, u.estado, r.nombre AS rol_nombre 
                           FROM usuario u 
                           LEFT JOIN roles r ON CAST(u.rol AS UNSIGNED) = r.id 
                           WHERE u.id = ? AND u.estado = 'ACTIVO'
                           LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $stmt->close();

            // El home/dashboard principal siempre está permitido para usuarios activos
            if ($modulo === 'dashboard_main') {
                return true;
            }
            
            // MASTER puro o ID=1 activo tiene bypass global
            if ($id === 1 || $row['rol_nombre'] === 'MASTER') {
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

            if ($modulo === 'campanas') {
                return hasPermission('directorio');
            }

            if ($modulo === 'pipeline') {
                return hasPermission('bandeja');
            }
        } else {
            $stmt->close();
        }
    }
    
    return false;
}

/**
 * Bloquea el acceso con HTTP 403 si el usuario no tiene permisos para el módulo especificado.
 * @param string $modulo Nombre identificador del módulo.
 */
function requirePermission($modulo) {
    if (!hasPermission($modulo)) {
        http_response_code(403);
        if (starfi_expects_json_response()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'status' => 'error',
                'message' => 'Acceso denegado: su rol no posee autorización para el módulo ' . $modulo . '.'
            ]);
            exit();
        }
        $prefix = starfi_base_rel_prefix();
        echo '<!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <title>Acceso Denegado | STARFI CRM</title>
            <link rel="icon" href="' . htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') . 'docs/identidad_visual/logos/isologo.ico" type="image/x-icon">
            <link href="' . htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') . 'assets/css/bootstrap.min.css" rel="stylesheet">
            <link href="' . htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') . 'assets/icons/bootstrap-icons/font/bootstrap-icons.min.css" rel="stylesheet">
            <link href="' . htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') . 'assets/css/starfi_theme.css" rel="stylesheet">
            <style>
                body { background-color: #F8FAFC; height: 100vh; display: flex; align-items: center; justify-content: center; font-family: var(--font-family, sans-serif); }
                .error-card { border-radius: 16px; padding: 40px; text-align: center; max-width: 480px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #E2E8F0; background-color: white; }
            </style>
        </head>
        <body>
            <div class="error-card">
                <div class="mx-auto rounded-circle d-flex align-items-center justify-content-center mb-4" style="background-color: rgba(239, 68, 68, 0.1); width: 80px; height: 80px; color: #EF4444;">
                    <i class="bi bi-shield-lock-fill" style="font-size: 2.5rem;"></i>
                </div>
                <h4 class="fw-bold text-dark mb-3">Acceso Restringido</h4>
                <p class="text-muted mb-4" style="font-size: 0.95rem; line-height: 1.6;">
                    Su rol actual no cuenta con autorización para acceder al módulo de <strong>' . htmlspecialchars(ucwords(str_replace('_', ' ', $modulo)), ENT_QUOTES, 'UTF-8') . '</strong>.
                </p>
                <a href="' . htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') . 'index.php" class="btn btn-primary px-5 py-2.5 fw-semibold text-white" style="background-color: var(--primary); border-color: var(--primary); border-radius: 30px; text-decoration: none;">
                    Volver al Dashboard
                </a>
            </div>
        </body>
        </html>';
        exit();
    }
}

/**
 * Genera un avatar SVG data:URI 100% local sin consultas externas (cumple regla Cero CDN CHK-LAN-01).
 */
function starfi_local_avatar_data_uri($name, $bg = '#37414A', $fg = '#FFFFFF') {
    $clean = trim((string)$name);
    if ($clean === '') {
        $clean = 'US';
    }
    $parts = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($parts) && count($parts) >= 2) {
        $initials = mb_strtoupper(mb_substr($parts[0], 0, 1, 'UTF-8') . mb_substr($parts[1], 0, 1, 'UTF-8'), 'UTF-8');
    } else {
        $initials = mb_strtoupper(mb_substr($clean, 0, 2, 'UTF-8'), 'UTF-8');
    }
    $initials = preg_replace('/[^A-Z0-9ÁÉÍÓÚÑ]/u', '', $initials) ?: 'ST';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
        . '<rect width="64" height="64" rx="32" fill="' . htmlspecialchars($bg, ENT_QUOTES, 'UTF-8') . '"/>'
        . '<text x="50%" y="54%" dominant-baseline="middle" text-anchor="middle" fill="' . htmlspecialchars($fg, ENT_QUOTES, 'UTF-8') . '" font-family="Gotham, Arial, sans-serif" font-size="24" font-weight="700">'
        . htmlspecialchars($initials, ENT_QUOTES, 'UTF-8')
        . '</text></svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
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
        'pipeline' => ['link' => '../pipeline/pipeline.php', 'icon' => 'fa-solid fa-filter-circle-dollar', 'label' => 'Embudo de Ventas'],
        'campanas' => ['link' => '../campanas/campanas.php', 'icon' => 'fa-solid fa-bullhorn', 'label' => 'Campañas Difusión'],
        'perfil_empresa' => ['link' => '../perfil_empresa/perfil_empresa.php', 'icon' => 'fa-solid fa-building', 'label' => 'Perfil Empresa'],
        'directorio' => ['link' => '../directorio/directorio.php', 'icon' => 'fa-solid fa-address-book', 'label' => 'Directorio 360'],
        'gestion_usuarios' => ['link' => '../gestion_usuarios/gestion_usuarios.php', 'icon' => 'fa-solid fa-users', 'label' => 'Gestión Usuarios'],
        'gestion_roles' => ['link' => '../gestion_roles/gestion_roles.php', 'icon' => 'fa-solid fa-user-shield', 'label' => 'Roles y Permisos'],
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
    $avatarUri = starfi_local_avatar_data_uri($nombre_agente, '#37414A', '#FFFFFF');
    $logoutRel = starfi_base_rel_prefix() . 'logout.php';
    echo '        <div class="sidebar-footer">';
    echo '            <div class="agent-profile" style="display: flex; align-items: center; width: 100%; gap: 10px; padding: 5px;">';
    echo '                <img src="' . htmlspecialchars($avatarUri, ENT_QUOTES, 'UTF-8') . '" alt="Avatar" style="width: 32px; height: 32px; border-radius: 50%;">';
    echo '                <div class="agent-info" style="flex-grow: 1; overflow: hidden; display: flex; flex-direction: column;">';
    echo '                    <span class="agent-name" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 90px; display: inline-block; font-size: 0.85rem; font-weight: 600; color: #37414A; text-align: left;">' . htmlspecialchars($nombre_agente) . '</span>';
    echo '                    <span class="agent-status online" style="font-size: 0.75rem; color: #10B981; display: block; text-align: left;">En línea</span>';
    echo '                </div>';
    echo '                <a href="' . htmlspecialchars($logoutRel, ENT_QUOTES, 'UTF-8') . '" class="btn text-danger p-1 m-0" title="Cerrar Sesión" style="font-size: 1.1rem; background: transparent; border: none; padding: 0;">';
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