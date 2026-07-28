-- Script de Migración para Asistente Virtual Multi-Sede en STARFI CRM
-- Fecha: Julio 2026

-- 1. Modificar la tabla configuraciones_ia para vinculación por id_sede
ALTER TABLE `configuraciones_ia` 
DROP PRIMARY KEY,
ADD COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT FIRST,
ADD COLUMN `id_sede` INT(11) NOT NULL AFTER `id_empresa`,
ADD COLUMN `modelo_ia` VARCHAR(50) DEFAULT 'gemma-2-9b-it' AFTER `agente_nombre`,
ADD COLUMN `temperatura` DECIMAL(2,1) DEFAULT 0.4 AFTER `modelo_ia`,
ADD PRIMARY KEY (`id`),
ADD UNIQUE KEY `uk_sede_ia` (`id_sede`),
ADD CONSTRAINT `fk_config_ia_sede` FOREIGN KEY (`id_sede`) REFERENCES `sedes` (`id`) ON DELETE CASCADE;

-- 2. Asegurar que exista la tabla de inventario de productos acotada por sede
CREATE TABLE IF NOT EXISTS `inventario_productos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_sede` int(11) NOT NULL,
  `codigo` varchar(50) DEFAULT NULL,
  `nombre_producto` varchar(255) NOT NULL,
  `precio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock` int(11) NOT NULL DEFAULT 0,
  `garantia` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sede_stock` (`id_sede`, `stock`),
  CONSTRAINT `fk_inv_prod_sede` FOREIGN KEY (`id_sede`) REFERENCES `sedes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
