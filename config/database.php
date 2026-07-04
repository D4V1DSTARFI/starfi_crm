<?php

use PhpOffice\PhpSpreadsheet\Reader\Xls\MD5;
/**
 * Gestor Central de Bases de Datos - STARFI_NEXT
 * Permite conexiones modulares según el sub-sistema requerido.
 */

// =========================================================================
// SELECTOR DE ENTORNO
// Opciones válidas: 'LOCAL', 'SANDBOX', 'PRODUCCION'
// LOCAL
define('APP_ENV', 'LOCAL'); // <-- CAMBIA ESTO PARA CAMBIAR DE ENTORNO

function getDbConnection($tipo = 'core')
{
    date_default_timezone_set("America/Caracas");

    // Configuración según el entorno
    switch (APP_ENV) {
        case 'LOCAL':
            $servidor = "localhost";
            $usuario = "starfi_user";
            $contrasenha = md5("PARALELEPIPEDO3312");
            break;
        case 'SANDBOX':
            $servidor = "192.168.0.71"; // Cambiar por la IP de tu entorno Sandbox
            $usuario = "starfi_v2_user";
            $contrasenha = md5("PARALELEPIPEDO3312");
            break;
        case 'PRODUCCION':
        default:
            $servidor = "192.168.8.121";
            $usuario = "starfi_user";
            $contrasenha = MD5("PARALELEPIPEDO3312");
            break;
    }

    // Determinar Base de Datos según el tipo solicitado
    $bd = "";
    switch (strtolower($tipo)) {
        case 'core':
            $bd = "starfi_crm"; // Sistema unificado: usuarios, roles, configuraciones globales
            break;
        case 'caja':
            $bd = "starfi_caja"; // Módulo Caja / Inventario
            break;
        case 'ventas':
            $bd = "starfi_ventas";
            break;

        case 'nomina':
            $bd = "starfi_nomina"; // Módulo Nomina
            break;
        default:
            die("Error Crítico: El sistema intentó conectar a un entorno no válido ('$tipo').");
    }

    // El arroba (@) suprime el warning nativo de PHP para controlarlo nosotros
    $con = @mysqli_connect($servidor, $usuario, $contrasenha, $bd);

    if (mysqli_connect_errno()) {
        $err = mysqli_connect_error();

        // Detección de petición AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            die(json_encode([
                'status' => 'error',
                'message' => "Fallo de conexión a la base de datos '$bd'. El servidor no responde."
            ]));
        } else {
            // Salida HTML elegante para peticiones estándar
            die("<div style='font-family: system-ui, -apple-system, sans-serif; text-align:center; padding:40px 20px; background-color:#f8f9fa; height:100vh; box-sizing:border-box;'>
                    <div style='max-width:500px; margin:0 auto; background:#fff; padding:30px; border-radius:10px; box-shadow:0 4px 6px rgba(0,0,0,0.05); border-top: 4px solid #dc3545;'>
                        <h3 style='color:#dc3545; margin-top:0;'>Error de Conexión</h3>
                        <p style='color:#495057; font-size:15px;'>El sistema no pudo establecer comunicación con el módulo de datos <b>$bd</b>.</p>
                        <p style='color:#6c757d; font-size:14px; margin-bottom:20px;'>Es posible que el servidor esté saturado o haya una inestabilidad en la red. Por favor, reintente en unos momentos.</p>
                        <div style='background-color:#f1f3f5; padding:10px; border-radius:5px; font-size:12px; color:#868e96; text-align:left; overflow-wrap:break-word;'>
                            <b>Detalle técnico:</b> $err
                        </div>
                    </div>
                 </div>");
        }
    }

    mysqli_set_charset($con, "utf8mb4");
    return $con;
}

function getExternalDbConnection($tipo = 'core')
{
    date_default_timezone_set("America/Caracas");

    switch (APP_ENV) {
        case 'LOCAL':
            $servidor = "localhost";
            $usuario = "starfi_user";
            $contrasenha = md5("PARALELEPIPEDO3312");
            break;
        case 'SANDBOX':
            $servidor = "192.168.0.71";
            $usuario = "starfi_v2_user";
            $contrasenha = md5("PARALELEPIPEDO3312");
            break;
        case 'PRODUCCION':
        default:
            $servidor = "192.168.8.121";
            $usuario = "starfi_user";
            $contrasenha = md5("PARALELEPIPEDO3312");
            break;
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
        die("Error de conexión a BD externa '$bd': " . mysqli_connect_error());
    }
    mysqli_set_charset($con, "utf8mb4");
    return $con;
}