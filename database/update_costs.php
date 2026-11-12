<?php
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../core/auth.php';
    requireAuth();
}
require_once __DIR__ . '/../config/database.php';

function update_existing_costs() {
    echo "--- Actualizando costos en entorno activo (" . APP_ENV . ") ---\n";
    $con = getDbConnection();
    if (!$con) {
        echo "No se pudo conectar a la base de datos configurada en .env.\n";
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
        echo "[OK] Registros actualizados: $affected filas.\n";
    } else {
        echo "[FAIL] Error SQL: " . mysqli_error($con) . "\n";
    }
}

update_existing_costs();
