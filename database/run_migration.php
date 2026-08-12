<?php
require_once __DIR__ . '/../config/database.php';

$con = getDbConnection();
if (!$con) {
    echo "Error de conexión a la BD\n";
    exit(1);
}

// Verificar si las columnas ya existen antes de agregarlas
$check_msg = mysqli_query($con, "SHOW COLUMNS FROM `mensajes_y_eventos` LIKE 'categoria_meta'");
if ($check_msg && mysqli_num_rows($check_msg) == 0) {
    $sql1 = "ALTER TABLE `mensajes_y_eventos`
      ADD COLUMN `categoria_meta` VARCHAR(50) DEFAULT NULL AFTER `reply_to_text`,
      ADD COLUMN `es_pagado` TINYINT(1) DEFAULT 0 AFTER `categoria_meta`,
      ADD COLUMN `conversation_id_meta` VARCHAR(255) DEFAULT NULL AFTER `es_pagado`,
      ADD COLUMN `costo_meta_estimado` DECIMAL(10,4) DEFAULT 0.0000 AFTER `conversation_id_meta`";
    if (mysqli_query($con, $sql1)) {
        echo "Columnas agregadas a mensajes_y_eventos con éxito.\n";
    } else {
        echo "Error alterando mensajes_y_eventos: " . mysqli_error($con) . "\n";
    }
} else {
    echo "mensajes_y_eventos ya posee las columnas de auditoría.\n";
}

$check_ord = mysqli_query($con, "SHOW COLUMNS FROM `waba_ordenes_detalles` LIKE 'categoria'");
if ($check_ord && mysqli_num_rows($check_ord) == 0) {
    $sql2 = "ALTER TABLE `waba_ordenes_detalles`
      ADD COLUMN `categoria` VARCHAR(50) DEFAULT 'MARKETING' AFTER `nombre_plantilla`,
      ADD COLUMN `mensajes_gratuitos` INT DEFAULT 0 AFTER `volumen`,
      ADD COLUMN `mensajes_pagados` INT DEFAULT 0 AFTER `mensajes_gratuitos`";
    if (mysqli_query($con, $sql2)) {
        echo "Columnas agregadas a waba_ordenes_detalles con éxito.\n";
    } else {
        echo "Error alterando waba_ordenes_detalles: " . mysqli_error($con) . "\n";
    }
} else {
    echo "waba_ordenes_detalles ya posee las columnas necesarias.\n";
}

echo "Migración completada.\n";
