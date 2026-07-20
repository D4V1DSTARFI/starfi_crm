<?php
/**
 * STARFI CRM - Database Exporter Script
 * Exports the databases: starfi_crm, starfi, starfi_ventas, starfi_caja, starfi_nomina, starfi_wsap
 * into the database/install_dumps/ folder as self-contained SQL files.
 */

// Only allow execution from CLI or local access for security
if (php_sapi_name() !== 'cli' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    die("Acceso denegado. Este script solo puede ser ejecutado localmente o por CLI.");
}

$envPath = __DIR__ . '/.env';
$env = file_exists($envPath) ? parse_ini_file($envPath) : [];

$host = $env['DB_HOST'] ?? 'localhost';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';

$databases = ['starfi_crm', 'starfi', 'starfi_ventas', 'starfi_caja', 'starfi_nomina', 'starfi_wsap'];
$dumpDir = __DIR__ . '/database/install_dumps';

if (!is_dir($dumpDir)) {
    if (!mkdir($dumpDir, 0777, true)) {
        die("Error: No se pudo crear el directorio de exportación: $dumpDir\n");
    }
}

echo "=== INICIANDO EXPORTACION DE BASES DE DATOS STARFI ===\n";
echo "Host: $host | Usuario: $user | Directorio: $dumpDir\n\n";

// Detect mysqldump path
$mysqldump = "c:\\xampp\\mysql\\bin\\mysqldump.exe";
if (!file_exists($mysqldump)) {
    $mysqldump = "mysqldump"; // Fallback to PATH
}

foreach ($databases as $db) {
    echo "Exportando base de datos: $db...\n";
    $outputFile = $dumpDir . '/' . $db . '.sql';
    
    // Attempt using mysqldump first
    $passOption = !empty($pass) ? "--password=" . escapeshellarg($pass) : "";
    $cmd = sprintf(
        '"%s" --host=%s --user=%s %s --databases %s > %s',
        $mysqldump,
        escapeshellarg($host),
        escapeshellarg($user),
        $passOption,
        escapeshellarg($db),
        escapeshellarg($outputFile)
    );
    
    $output = [];
    $resultCode = -1;
    @exec($cmd, $output, $resultCode);
    
    if ($resultCode === 0 && file_exists($outputFile) && filesize($outputFile) > 100) {
        echo "  [OK] Exportada con mysqldump (" . round(filesize($outputFile) / 1024, 2) . " KB)\n";
    } else {
        echo "  [FALLBACK] mysqldump falló o no está disponible. Usando volcado PHP nativo...\n";
        if (exportDbNativePHP($host, $user, $pass, $db, $outputFile)) {
            echo "  [OK] Exportada con volcado PHP nativo (" . round(filesize($outputFile) / 1024, 2) . " KB)\n";
        } else {
            echo "  [ERROR] No se pudo exportar la base de datos: $db\n";
        }
    }
}

echo "\n=== PROCESO TERMINADO ===\n";

/**
 * Native PHP fallback to dump a database structure and data
 */
function exportDbNativePHP($host, $user, $pass, $db, $outputFile) {
    $conn = @mysqli_connect($host, $user, $pass);
    if (!$conn) {
        return false;
    }
    
    if (!@mysqli_select_db($conn, $db)) {
        mysqli_close($conn);
        return false;
    }
    
    $fp = fopen($outputFile, 'w');
    if (!$fp) {
        mysqli_close($conn);
        return false;
    }
    
    // Write headers
    fwrite($fp, "-- Volcado PHP Nativo para STARFI\n");
    fwrite($fp, "-- Servidor: $host\n");
    fwrite($fp, "-- Base de datos: $db\n");
    fwrite($fp, "-- Fecha: " . date('Y-m-d H:i:s') . "\n\n");
    
    fwrite($fp, "CREATE DATABASE IF NOT EXISTS `$db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n");
    fwrite($fp, "USE `$db`;\n\n");
    
    // Get all tables
    $tablesRes = mysqli_query($conn, "SHOW TABLES");
    $tables = [];
    while ($row = mysqli_fetch_row($tablesRes)) {
        $tables[] = $row[0];
    }
    
    foreach ($tables as $table) {
        // Disable foreign keys temporarily
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
        
        // Structure
        $createRes = mysqli_query($conn, "SHOW CREATE TABLE `$table`");
        $createRow = mysqli_fetch_assoc($createRes);
        fwrite($fp, $createRow['Create Table'] . ";\n\n");
        
        // Data
        $dataRes = mysqli_query($conn, "SELECT * FROM `$table`");
        $fieldCount = mysqli_num_fields($dataRes);
        
        while ($row = mysqli_fetch_row($dataRes)) {
            $insertQuery = "INSERT INTO `$table` VALUES(";
            for ($i = 0; $i < $fieldCount; $i++) {
                if (isset($row[$i])) {
                    // Escape string
                    $val = mysqli_real_escape_string($conn, $row[$i]);
                    $insertQuery .= "'" . $val . "'";
                } else {
                    $insertQuery .= "NULL";
                }
                if ($i < ($fieldCount - 1)) {
                    $insertQuery .= ",";
                }
            }
            $insertQuery .= ");\n";
            fwrite($fp, $insertQuery);
        }
        
        fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n\n");
    }
    
    fclose($fp);
    mysqli_close($conn);
    return true;
}
?>
