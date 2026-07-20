<?php
/**
 * Gestor Central de Bases de Datos - STARFI_NEXT
 * Permite conexiones modulares según el sub-sistema requerido.
 */

// Cargar variables de entorno desde el archivo .env en la raíz
$envPath = dirname(__DIR__) . '/.env';
$env = file_exists($envPath) ? parse_ini_file($envPath) : [];

// Si no está configurado el archivo .env, redirigir al asistente de instalación
if (empty($env['DB_HOST']) || empty($env['DB_USER'])) {
    if (php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF']) !== 'install.php') {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'error',
                'message' => 'El sistema no está configurado. Ejecute install.php para configurarlo.',
                'redirect' => '/starfi_crm/install.php'
            ]);
            exit();
        } else {
            header("Location: /starfi_crm/install.php");
            exit();
        }
    }
}

// Selector de entorno (por compatibilidad heredada)
if (!defined('APP_ENV')) {
    define('APP_ENV', 'LOCAL');
}

function getDbConnection($tipo = 'core')
{
    global $env;
    date_default_timezone_set("America/Caracas");

    // Intentar cargar credenciales del archivo .env primero
    if (!empty($env['DB_HOST'])) {
        $servidor = $env['DB_HOST'];
        $usuario = $env['DB_USER'];
        $contrasenha = $env['DB_PASS'] ?? '';
    } else {
        // Configuración fallback según el entorno
        switch (APP_ENV) {
            case 'SANDBOX':
                $servidor = "192.168.0.71";
                $usuario = "starfi_v2_user";
                $contrasenha = md5("PARALELEPIPEDO3312");
                break;
            case 'PRODUCCION':
                $servidor = "192.168.8.121";
                $usuario = "starfi_user";
                $contrasenha = md5("PARALELEPIPEDO3312");
                break;
            case 'LOCAL':
            default:
                $servidor = "localhost";
                $usuario = "starfi_user";
                $contrasenha = md5("PARALELEPIPEDO3312");
                break;
        }
    }

    // Determinar Base de Datos según el tipo solicitado
    $bd = "";
    switch (strtolower($tipo)) {
        case 'core':
            $bd = "starfi_crm";
            break;
        case 'caja':
            $bd = "starfi_caja";
            break;
        case 'ventas':
            $bd = "starfi_ventas";
            break;
        case 'nomina':
            $bd = "starfi_nomina";
            break;
        default:
            die("Error Crítico: El sistema intentó conectar a un entorno no válido ('$tipo').");
    }

    $con = @mysqli_connect($servidor, $usuario, $contrasenha, $bd);

    if (mysqli_connect_errno()) {
        $err = mysqli_connect_error();

        if (strtolower($tipo) !== 'core') {
            return false;
        }

        // Si la conexión falla en el navegador, redirigir al instalador con el detalle del error
        if (php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF']) !== 'install.php') {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                header('Content-Type: application/json');
                die(json_encode([
                    'status' => 'error',
                    'message' => "Fallo de conexión a la base de datos '$bd'."
                ]));
            } else {
                header("Location: /starfi_crm/install.php?error=" . urlencode("Fallo de conexión a la base de datos $bd: $err"));
                exit();
            }
        } else {
            // Si estamos en install.php o CLI, simplemente retornamos false para que el script lo maneje
            return false;
        }
    }

    mysqli_set_charset($con, "utf8mb4");
    return $con;
}

function getExternalDbConnection($tipo = 'core')
{
    global $env;
    date_default_timezone_set("America/Caracas");

    if (!empty($env['DB_HOST'])) {
        $servidor = $env['DB_HOST'];
        $usuario = $env['DB_USER'];
        $contrasenha = $env['DB_PASS'] ?? '';
    } else {
        switch (APP_ENV) {
            case 'SANDBOX':
                $servidor = "192.168.0.71";
                $usuario = "starfi_v2_user";
                $contrasenha = md5("PARALELEPIPEDO3312");
                break;
            case 'PRODUCCION':
                $servidor = "192.168.8.121";
                $usuario = "starfi_user";
                $contrasenha = md5("PARALELEPIPEDO3312");
                break;
            case 'LOCAL':
            default:
                $servidor = "localhost";
                $usuario = "starfi_user";
                $contrasenha = md5("PARALELEPIPEDO3312");
                break;
        }
    }

    $bd = "";
    switch (strtolower($tipo)) {
        case 'core':
            $bd = "starfi";
            break;
        case 'caja':
            $bd = "starfi_caja";
            break;
        case 'ventas':
            $bd = "starfi_ventas";
            break;
        case 'nomina':
            $bd = "starfi_nomina";
            break;
        default:
            die("Error Crítico: El sistema intentó conectar a un entorno externo no válido ('$tipo').");
    }

    $con = @mysqli_connect($servidor, $usuario, $contrasenha, $bd);
    if (mysqli_connect_errno()) {
        return false;
    }
    mysqli_set_charset($con, "utf8mb4");
    return $con;
}
?>