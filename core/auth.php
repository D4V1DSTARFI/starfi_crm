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
    
    // Obtener los datos frescos de la BD para inyectarlos en la UI (Ej. Nombre, Foto, Límites)
    $stmt = $con->prepare("SELECT id, nombre_completo, email, rol, id_sede, limite_chats_simultaneos FROM usuarios_agentes WHERE id = ? AND estado = 'ACTIVO'");
    if ($stmt) {
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return $row;
        }
    }
    
    // Si no lo encuentra o no está ACTIVO, forzamos cierre de sesión
    session_unset();
    session_destroy();
    header("Location: /starfi_crm/login.php");
    exit();
}
?>
