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

if (!function_exists('starfi_resolve_env_path')) {
    function starfi_resolve_env_path($rootDir = null) {
        $base = $rootDir ?: dirname(__DIR__);
        $candidates = [
            $base . '/.env',
            $base . '/config/.env.local',
            $base . '/assets/uploads/.env.local',
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return $base . '/.env';
    }
}

$envPath = starfi_resolve_env_path(dirname(__DIR__));
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
    $rawMetaGlobal = $envGet('META_GLOBAL_TOKEN', '');
    if ($rawMetaGlobal === 'replace_with_meta_system_user_token') {
        $rawMetaGlobal = '';
    }
    define('META_GLOBAL_TOKEN', $rawMetaGlobal);
}

if (!defined('META_APP_SECRET')) {
    $rawMetaSecret = $envGet('META_APP_SECRET', '');
    if ($rawMetaSecret === 'replace_with_meta_app_secret') {
        $rawMetaSecret = '';
    }
    define('META_APP_SECRET', $rawMetaSecret);
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

    if ($servidor === '' || $usuario === '' || $usuario === 'your_db_user') {
        $GLOBALS['STARFI_DB_LAST_ERROR'] = 'Archivo .env ausente o con valores de plantilla en el servidor.';
        error_log('[STARFI DB] Credenciales de base de datos no configuradas en .env');
        return false;
    }

    $con = @mysqli_connect($servidor, $usuario, $contrasenha, $bd);

    // Si el servidor web y MySQL residen en el mismo host y la IP LAN rechaza loopback, probar 127.0.0.1 / localhost con las mismas credenciales del .env
    if (!$con) {
        $serverAddr = trim((string)($_SERVER['SERVER_ADDR'] ?? ''));
        $httpHost = preg_replace('/:\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        if (($serverAddr !== '' && $servidor === $serverAddr) || ($httpHost !== '' && $servidor === $httpHost)) {
            foreach (['127.0.0.1', 'localhost'] as $localHostCandidate) {
                if ($servidor !== $localHostCandidate) {
                    $con = @mysqli_connect($localHostCandidate, $usuario, $contrasenha, $bd);
                    if ($con) {
                        break;
                    }
                }
            }
        }
    }

    if (!$con || mysqli_connect_errno()) {
        $GLOBALS['STARFI_DB_LAST_ERROR'] = 'Error de conexión MySQL (' . mysqli_connect_errno() . '): ' . mysqli_connect_error();
        error_log('[STARFI DB] Error de conexión MySQL (' . mysqli_connect_errno() . ')');
        return false;
    }

    $GLOBALS['STARFI_DB_LAST_ERROR'] = '';
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
