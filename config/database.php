<?php
/**
 * Gestor Central de Bases de Datos - STARFI_NEXT
 * Permite conexiones modulares según el sub-sistema requerido.
 */

// Cargar variables de entorno desde el archivo .env en la raíz
$envPath = dirname(__DIR__) . '/.env';
$env = file_exists($envPath) ? parse_ini_file($envPath) : [];

// (Redirección al instalador eliminada. El sistema leerá las credenciales de la parte inferior)

// Selector de entorno (por compatibilidad heredada)
if (!defined('APP_ENV')) {
    define('APP_ENV', 'PRODUCCION');
}

// Token Global de Meta (System User Token)
if (!defined('META_GLOBAL_TOKEN')) {
    define('META_GLOBAL_TOKEN', 'EAAqFHnS2hXABSCZBdOi3Yn3ZAvCL2bpWFpazU1v23jp6tJZBfappUkx6WD1uAcbGAY9gvI5aMiwUQTkhk9gBMaUPoOgyk7YX6LNrNE5BQ7cFxZC8k6HuUJ2M8p5ZBZBhGzTQ5yZBeZAhEp1qZAUglVxVsWOpeJRANwaRW8mlV604TbYYulZCqKMkE3OnAwuiZCen9oqxAZDZD');
}

function getDbConnection($tipo = 'core')
{
    global $env;
    date_default_timezone_set("America/Caracas");

    // Configuración dura según el entorno (Ignorando DB_HOST del .env)
    switch (APP_ENV) {
        case 'SANDBOX':
            $servidor = "192.168.0.71";
            $usuario = "starfi_v2_user";
            $contrasenha = md5("PARALELEPIPEDO3312");
            break;
        case 'PRODUCCION':
            $servidor = "192.168.0.80";
            $usuario = "starfi_v2_user";
            $contrasenha = md5("PARALELEPIPEDO3312");
            break;
        case 'LOCAL':
        default:
            $servidor = "localhost";
            $usuario = "starfi_user";
            $contrasenha = md5("PARALELEPIPEDO3312");
            break;
    }

    // Usar la base de datos core (starfi_crm) para todo
    $bd = "starfi_crm";

    $con = @mysqli_connect($servidor, $usuario, $contrasenha, $bd);

    if (mysqli_connect_errno()) {
        $err = mysqli_connect_error();

        // Ya no redirigimos al instalador, simplemente retornamos false o lanzamos error
        return false;
    }

    mysqli_set_charset($con, "utf8mb4");
    return $con;
}

function getExternalDbConnection($tipo = 'core')
{
    return false;
}
?>