<?php
/**
 * Script para crear las tablas de registro en la base de datos starfi_crm.
 */

require_once dirname(__DIR__) . '/config/database.php';

$con = getDbConnection('core');
if (!$con) {
    die("Error: No se pudo conectar a la base de datos.\n");
}

echo "Conexión a la base de datos establecida correctamente.\n";

// Desactivar temporalmente revisión de llaves foráneas para evitar problemas al crearlas
mysqli_query($con, "SET FOREIGN_KEY_CHECKS = 0;");

// 1. Tabla: usuario
$sqlUsuario = "
CREATE TABLE IF NOT EXISTS usuario (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(255) UNIQUE NOT NULL,
    contrasena VARCHAR(255) NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if (mysqli_query($con, $sqlUsuario)) {
    echo "Tabla 'usuario' creada o ya existente.\n";
} else {
    echo "Error al crear la tabla 'usuario': " . mysqli_error($con) . "\n";
}

// 2. Tabla: usuario_perfil
$sqlPerfil = "
CREATE TABLE IF NOT EXISTS usuario_perfil (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    cedula VARCHAR(50) NOT NULL,
    direccion TEXT,
    telefono VARCHAR(50),
    correo VARCHAR(255) NOT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if (mysqli_query($con, $sqlPerfil)) {
    echo "Tabla 'usuario_perfil' creada o ya existente.\n";
} else {
    echo "Error al crear la tabla 'usuario_perfil': " . mysqli_error($con) . "\n";
}

// 3. Tabla: preguntas_seguridad
$sqlPreguntas = "
CREATE TABLE IF NOT EXISTS preguntas_seguridad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    pregunta VARCHAR(255) NOT NULL,
    respuesta VARCHAR(255) NOT NULL,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_usuario) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if (mysqli_query($con, $sqlPreguntas)) {
    echo "Tabla 'preguntas_seguridad' creada o ya existente.\n";
} else {
    echo "Error al crear la tabla 'preguntas_seguridad': " . mysqli_error($con) . "\n";
}

// Reactivar revisión de llaves foráneas
mysqli_query($con, "SET FOREIGN_KEY_CHECKS = 1;");

mysqli_close($con);
echo "Proceso finalizado.\n";
?>
