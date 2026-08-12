-- Script de migración para enriquecer la trazabilidad de analítica y facturación de Meta WhatsApp

ALTER TABLE `mensajes_y_eventos`
  ADD COLUMN `categoria_meta` VARCHAR(50) DEFAULT NULL AFTER `reply_to_text`,
  ADD COLUMN `es_pagado` TINYINT(1) DEFAULT 0 AFTER `categoria_meta`,
  ADD COLUMN `conversation_id_meta` VARCHAR(255) DEFAULT NULL AFTER `es_pagado`,
  ADD COLUMN `costo_meta_estimado` DECIMAL(10,4) DEFAULT 0.0000 AFTER `conversation_id_meta`;

ALTER TABLE `waba_ordenes_detalles`
  ADD COLUMN `categoria` VARCHAR(50) DEFAULT 'MARKETING' AFTER `nombre_plantilla`,
  ADD COLUMN `mensajes_gratuitos` INT DEFAULT 0 AFTER `volumen`,
  ADD COLUMN `mensajes_pagados` INT DEFAULT 0 AFTER `mensajes_gratuitos`;
