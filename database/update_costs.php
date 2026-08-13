<?php
require_once __DIR__ . '/../config/database.php';

function update_existing_costs($envName) {
    echo "--- Actualizando costos en entorno: $envName ---\n";
    $servidor = ($envName === 'SANDBOX') ? "192.168.0.71" : (($envName === 'PRODUCCION') ? "192.168.0.80" : "localhost");
    $usuario = ($envName === 'LOCAL') ? "starfi_user" : "starfi_v2_user";
    $contrasenha = md5("PARALELEPIPEDO3312");
    $bd = "starfi_crm";

    $con = @mysqli_connect($servidor, $usuario, $contrasenha, $bd);
    if (!$con) {
        echo "No se pudo conectar a $envName ($servidor): " . mysqli_connect_error() . "\n";
        return;
    }

    $sql = "UPDATE mensajes_y_eventos 
            SET costo_meta_estimado = CASE 
                WHEN UPPER(categoria_meta) = 'UTILITY' THEN 0.0150 
                WHEN UPPER(categoria_meta) = 'AUTHENTICATION' THEN 0.0150 
                WHEN UPPER(categoria_meta) = 'SERVICE' THEN 0.0120 
                ELSE 0.0700 
            END 
            WHERE es_pagado = 1 AND (costo_meta_estimado IS NULL OR costo_meta_estimado = 0.0000)";

    if (mysqli_query($con, $sql)) {
        $affected = mysqli_affected_rows($con);
        echo "[OK] Registros actualizados en $servidor: $affected filas.\n";
    } else {
        echo "[FAIL] Error en $servidor: " . mysqli_error($con) . "\n";
    }
}

update_existing_costs('PRODUCCION');
