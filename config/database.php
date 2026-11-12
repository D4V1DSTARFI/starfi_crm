<?php
/**
 * Gestor Central de Bases de Datos - STARFI CRM
 * Carga credenciales y secretos exclusivamente desde variables de entorno (.env).
 */

if (!function_exists('starfi_parse_env_file')) {
    function starfi_parse_env_file($path) {
        if (!is_string($path) || !file_exists($path) || !is_readable($path)) {
            return [];
        }
        $parsed = @parse_ini_file($path, false, INI_SCANNER_RAW);
        if (is_array($parsed) && !empty($parsed)) {
            $clean = [];
            foreach ($parsed as $k => $v) {
                $clean[trim((string)$k)] = trim((string)$v, " \t\n\r\0\x0B\"'");
            }
            return $clean;
        }
        $result = [];
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '#' || $trim[0] === ';') {
                continue;
            }
            $eqPos = strpos($trim, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($trim, 0, $eqPos));
            $val = trim(substr($trim, $eqPos + 1));
            if ($key !== '') {
                $result[$key] = trim($val, "\"'");
            }
        }
        return $result;
    }
}

$envPath = dirname(__DIR__) . '/.env';
$env = starfi_parse_env_file($envPath);

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

if (!defined('META_API_VERSION')) {
    define('META_API_VERSION', $envGet('META_API_VERSION', 'v23.0'));
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
