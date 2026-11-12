<?php
/**
 * Gestor Central de Bases de Datos - STARFI CRM
 * Carga credenciales y secretos exclusivamente desde variables de entorno (.env).
 */

$envPath = dirname(__DIR__) . '/.env';
$env = file_exists($envPath) ? (parse_ini_file($envPath) ?: []) : [];

$envGet = function ($key, $default = '') use ($env) {
    if (isset($env[$key]) && $env[$key] !== '') {
        return $env[$key];
    }
    $sysVal = getenv($key);
    if ($sysVal !== false && $sysVal !== '') {
        return $sysVal;
    }
    return $default;
};

if (!defined('APP_ENV')) {
    define('APP_ENV', $envGet('APP_ENV', 'PRODUCCION'));
}

if (!defined('META_GLOBAL_TOKEN')) {
    define('META_GLOBAL_TOKEN', $envGet('META_GLOBAL_TOKEN', ''));
}

if (!defined('META_APP_SECRET')) {
    define('META_APP_SECRET', $envGet('META_APP_SECRET', ''));
}

function getDbConnection($tipo = 'core')
{
    global $env;
    date_default_timezone_set("America/Caracas");

    $lookup = function ($key, $default = '') use ($env) {
        if (is_array($env) && isset($env[$key]) && $env[$key] !== '') {
            return $env[$key];
        }
        $val = getenv($key);
        return ($val !== false && $val !== '') ? $val : $default;
    };

    $envPrefix = '';
    if (APP_ENV === 'SANDBOX' && $lookup('DB_SANDBOX_HOST', '') !== '') {
        $envPrefix = 'DB_SANDBOX_';
    } elseif (APP_ENV === 'LOCAL' && $lookup('DB_LOCAL_HOST', '') !== '') {
        $envPrefix = 'DB_LOCAL_';
    } else {
        $envPrefix = 'DB_';
    }

    $servidor = $lookup($envPrefix . 'HOST', $lookup('DB_HOST', ''));
    $usuario = $lookup($envPrefix . 'USER', $lookup('DB_USER', ''));
    $contrasenha = $lookup($envPrefix . 'PASS', $lookup('DB_PASS', ''));
    $bd = $lookup($envPrefix . 'NAME', $lookup('DB_NAME', 'starfi_crm'));

    if ($servidor === '' || $usuario === '') {
        error_log('[STARFI DB] Credenciales de base de datos no configuradas en .env');
        return false;
    }

    $con = @mysqli_connect($servidor, $usuario, $contrasenha, $bd);

    if (mysqli_connect_errno()) {
        error_log('[STARFI DB] Error de conexión MySQL (' . mysqli_connect_errno() . ')');
        return false;
    }

    mysqli_set_charset($con, "utf8mb4");

    // Forzar zona horaria de MySQL para que NOW() coincida con PHP
    mysqli_query($con, "SET time_zone = '-04:00'");

    return $con;
}

function getExternalDbConnection($tipo = 'core')
{
    return false;
}
?>
