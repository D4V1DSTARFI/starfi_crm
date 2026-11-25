-- =============================================================================
-- STARFI CRM — Esquema Base Oficial Limpio (QA-STD-GLOBAL-2.1)
-- Solo sentencias DDL idempotentes (CREATE TABLE IF NOT EXISTS).
-- Cero tokens reales, cero teléfonos reales y cero datos productivos.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `starfi_crm` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `starfi_crm`;

CREATE TABLE IF NOT EXISTS `empresas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre_comercial` VARCHAR(255) NOT NULL,
    `razon_social` VARCHAR(255) DEFAULT NULL,
    `documento_identidad` VARCHAR(50) DEFAULT NULL,
    `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `estado` ENUM('ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `empresa_perfil` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `razon_social` VARCHAR(255) DEFAULT NULL,
    `nombre_comercial` VARCHAR(255) DEFAULT NULL,
    `rif` VARCHAR(30) DEFAULT NULL,
    `direccion_fiscal` TEXT DEFAULT NULL,
    `telefono_principal` VARCHAR(50) DEFAULT NULL,
    `correo_corporativo` VARCHAR(150) DEFAULT NULL,
    `sitio_web` VARCHAR(255) DEFAULT NULL,
    `logo_ruta` VARCHAR(255) DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_empresa` (`id_empresa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `empresa_expediente` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `id_sede` INT DEFAULT NULL,
    `tipo_documento` VARCHAR(100) NOT NULL,
    `nombre_documento` VARCHAR(255) NOT NULL,
    `ruta` VARCHAR(500) NOT NULL,
    `fecha_vencimiento` DATE DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_emp_sede` (`id_empresa`, `id_sede`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sedes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `nombre_sede` VARCHAR(255) NOT NULL,
    `rif_sede` VARCHAR(30) DEFAULT NULL,
    `direccion` TEXT DEFAULT NULL,
    `ciudad` VARCHAR(120) DEFAULT NULL,
    `latitud` VARCHAR(40) DEFAULT NULL,
    `longitud` VARCHAR(40) DEFAULT NULL,
    `link_gps` VARCHAR(500) DEFAULT NULL,
    `horario_atencion` TEXT DEFAULT NULL,
    `api_token` VARCHAR(64) DEFAULT NULL,
    `correo_notificaciones` VARCHAR(255) DEFAULT NULL,
    `telefono_notificaciones` VARCHAR(50) DEFAULT NULL,
    `fecha_creacion` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `estado` ENUM('ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
    UNIQUE KEY `uk_sedes_api_token` (`api_token`),
    CONSTRAINT `fk_sedes_empresa` FOREIGN KEY (`id_empresa`) REFERENCES `empresas`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nombre` VARCHAR(100) NOT NULL UNIQUE,
    `descripcion` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `permisos_roles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_rol` INT NOT NULL,
    `modulo` VARCHAR(100) NOT NULL,
    `permitido` TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uk_rol_modulo` (`id_rol`, `modulo`),
    CONSTRAINT `fk_permisos_rol` FOREIGN KEY (`id_rol`) REFERENCES `roles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuario` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `usuario` VARCHAR(255) NOT NULL UNIQUE,
    `contrasena` VARCHAR(255) NOT NULL,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'INACTIVO',
    `rol` VARCHAR(20) DEFAULT NULL,
    `id_empresa` INT DEFAULT 1,
    `id_sede` INT DEFAULT NULL,
    `limite_chats_simultaneos` INT NOT NULL DEFAULT 5,
    `disponible_chat` TINYINT(1) NOT NULL DEFAULT 1,
    `ultima_actividad` DATETIME DEFAULT NULL,
    `ultima_asignacion_rr` DATETIME DEFAULT NULL,
    `codigo_recuperacion` VARCHAR(20) DEFAULT NULL,
    `expiracion_codigo` DATETIME DEFAULT NULL,
    `fecha_creacion` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sede_online_rr` (`id_sede`, `estado`, `disponible_chat`, `ultima_actividad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuario_perfil` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_usuario` INT NOT NULL,
    `nombre` VARCHAR(255) NOT NULL,
    `cedula` VARCHAR(50) NOT NULL,
    `direccion` TEXT DEFAULT NULL,
    `telefono` VARCHAR(50) DEFAULT NULL,
    `correo` VARCHAR(255) NOT NULL,
    `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_perfil_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `preguntas_seguridad` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_usuario` INT NOT NULL,
    `pregunta` VARCHAR(255) NOT NULL,
    `respuesta` VARCHAR(255) NOT NULL,
    `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_preguntas_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lineas_whatsapp` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_sede` INT NOT NULL,
    `descripcion` VARCHAR(255) DEFAULT NULL,
    `numero_telefono` VARCHAR(50) NOT NULL,
    `id_negocio` VARCHAR(255) DEFAULT NULL,
    `meta_app_id` VARCHAR(255) NOT NULL,
    `meta_token` TEXT NOT NULL,
    `webhook_verify_token` VARCHAR(255) DEFAULT NULL,
    `estado` VARCHAR(30) DEFAULT 'ACTIVO',
    `estado_conexion` ENUM('CONECTADO', 'DESCONECTADO', 'REQUIERE_VERIFICACION') DEFAULT 'REQUIERE_VERIFICACION',
    INDEX `idx_linea_sede` (`id_sede`),
    CONSTRAINT `fk_linea_sede` FOREIGN KEY (`id_sede`) REFERENCES `sedes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clientes_contactos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `id_sede` INT DEFAULT NULL,
    `numero_whatsapp` VARCHAR(50) NOT NULL,
    `nombre` VARCHAR(255) DEFAULT NULL,
    `correo` VARCHAR(255) DEFAULT NULL,
    `documento` VARCHAR(50) DEFAULT NULL,
    `direccion` TEXT DEFAULT NULL,
    `etiquetas` JSON DEFAULT NULL,
    `notas_internas` TEXT DEFAULT NULL,
    `fecha_registro` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `ultimo_contacto` DATETIME DEFAULT NULL,
    `estado` ENUM('ACTIVO', 'INACTIVO', 'BLOQUEADO') DEFAULT 'ACTIVO',
    INDEX `idx_cli_sede_tel` (`id_sede`, `numero_whatsapp`),
    UNIQUE KEY `uk_empresa_whatsapp_sede` (`id_empresa`, `numero_whatsapp`, `id_sede`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conversaciones` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_linea` INT NOT NULL,
    `id_cliente` INT NOT NULL,
    `id_agente` INT DEFAULT NULL,
    `estado` ENUM('BOT_RECOPILANDO', 'ESPERA_ASIGNACION', 'ATENDIENDO', 'RESUELTO', 'CERRADO') DEFAULT 'BOT_RECOPILANDO',
    `mensajes_no_leidos` INT DEFAULT 0,
    `ultimo_msg_id` BIGINT DEFAULT 0,
    `calificacion_csat` TINYINT DEFAULT NULL,
    `fecha_inicio` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `fecha_primera_respuesta` DATETIME DEFAULT NULL,
    `fecha_resolucion` DATETIME DEFAULT NULL,
    INDEX `idx_conv_linea_estado` (`id_linea`, `estado`),
    INDEX `idx_conv_cliente` (`id_cliente`),
    CONSTRAINT `fk_conv_linea` FOREIGN KEY (`id_linea`) REFERENCES `lineas_whatsapp`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_conv_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes_contactos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `mensajes_y_eventos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_conversacion` INT NOT NULL,
    `id_mensaje_meta` VARCHAR(255) DEFAULT NULL,
    `tipo` VARCHAR(40) DEFAULT 'TEXTO',
    `origen` ENUM('CLIENTE', 'BOT', 'AGENTE', 'API_TRANSACCIONAL') NOT NULL,
    `id_agente` INT DEFAULT NULL,
    `contenido` TEXT DEFAULT NULL,
    `reply_to_meta_id` VARCHAR(255) DEFAULT NULL,
    `reply_to_text` TEXT DEFAULT NULL,
    `categoria_meta` VARCHAR(50) DEFAULT NULL,
    `es_pagado` TINYINT(1) DEFAULT 0,
    `conversation_id_meta` VARCHAR(255) DEFAULT NULL,
    `costo_meta_estimado` DECIMAL(10,4) DEFAULT 0.0000,
    `estado_envio` VARCHAR(30) DEFAULT NULL,
    `error_detalle` TEXT DEFAULT NULL,
    `url_archivo` VARCHAR(500) DEFAULT NULL,
    `mime_type` VARCHAR(100) DEFAULT NULL,
    `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_conv_origen_ts` (`id_conversacion`, `origen`, `timestamp`),
    INDEX `idx_meta_msg_id` (`id_mensaje_meta`),
    CONSTRAINT `fk_msg_conv` FOREIGN KEY (`id_conversacion`) REFERENCES `conversaciones`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `configuraciones_ia` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `id_sede` INT NOT NULL,
    `estado_ia` ENUM('ACTIVO', 'INACTIVO') DEFAULT 'INACTIVO',
    `agente_nombre` VARCHAR(100) DEFAULT 'Gema',
    `modelo_ia` VARCHAR(80) DEFAULT 'gemini-2.0-flash',
    `temperatura` DECIMAL(3,2) DEFAULT 0.40,
    `gemini_api_key` VARCHAR(255) DEFAULT NULL,
    `agente_instrucciones` LONGTEXT DEFAULT NULL,
    `direccion_sede` TEXT DEFAULT NULL,
    `link_gps` VARCHAR(500) DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_sede_ia` (`id_sede`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_respuestas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `id_sede` INT DEFAULT NULL,
    `palabra_clave` VARCHAR(255) NOT NULL,
    `tipo_coincidencia` VARCHAR(50) DEFAULT 'CONTIENE',
    `respuesta` TEXT NOT NULL,
    `accion` VARCHAR(50) DEFAULT 'NINGUNA',
    `activo` TINYINT(1) DEFAULT 1,
    `orden` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_bot_sede` (`id_sede`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pipeline_oportunidades` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_sede` INT NOT NULL DEFAULT 0,
    `id_cliente` INT NOT NULL DEFAULT 0,
    `id_conversacion` INT DEFAULT NULL,
    `id_agente` INT DEFAULT NULL,
    `titulo` VARCHAR(200) NOT NULL DEFAULT 'Oportunidad Comercial',
    `etapa` ENUM('NUEVO_LEAD','COTIZADO','EN_NEGOCIACION','PAGO_PENDIENTE','GANADO','PERDIDO') NOT NULL DEFAULT 'NUEVO_LEAD',
    `monto_estimado_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `probabilidad` INT NOT NULL DEFAULT 20,
    `notas` TEXT DEFAULT NULL,
    `motivo_perdida` VARCHAR(200) DEFAULT NULL,
    `orden_kanban` INT NOT NULL DEFAULT 0,
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `fecha_cierre` DATETIME DEFAULT NULL,
    INDEX `idx_sede_etapa` (`id_sede`, `etapa`, `orden_kanban`),
    INDEX `idx_conversacion` (`id_conversacion`),
    INDEX `idx_cliente` (`id_cliente`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `config_sla` (
    `id_sede` INT NOT NULL PRIMARY KEY DEFAULT 0,
    `verde_max_min` INT NOT NULL DEFAULT 5,
    `amarillo_max_min` INT NOT NULL DEFAULT 15,
    `round_robin_online_min` INT NOT NULL DEFAULT 5,
    `alerta_sonora_activa` TINYINT(1) NOT NULL DEFAULT 1,
    `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `campanas_difusion` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `id_sede` INT NOT NULL,
    `id_linea` INT NOT NULL,
    `id_creador` INT DEFAULT NULL,
    `nombre` VARCHAR(200) NOT NULL,
    `plantilla_nombre` VARCHAR(150) NOT NULL,
    `plantilla_idioma` VARCHAR(20) NOT NULL DEFAULT 'es',
    `categoria_meta` VARCHAR(40) NOT NULL DEFAULT 'MARKETING',
    `header_tipo` VARCHAR(30) DEFAULT 'NONE',
    `header_media_url` VARCHAR(500) DEFAULT NULL,
    `header_media_id` VARCHAR(255) DEFAULT NULL,
    `parametros_json` LONGTEXT DEFAULT NULL,
    `texto_preview` TEXT DEFAULT NULL,
    `total_destinatarios` INT NOT NULL DEFAULT 0,
    `enviados` INT NOT NULL DEFAULT 0,
    `entregados` INT NOT NULL DEFAULT 0,
    `leidos` INT NOT NULL DEFAULT 0,
    `respondidos` INT NOT NULL DEFAULT 0,
    `fallidos` INT NOT NULL DEFAULT 0,
    `costo_unitario_usd` DECIMAL(10,4) NOT NULL DEFAULT 0.0700,
    `presupuesto_estimado_usd` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    `estado` VARCHAR(40) NOT NULL DEFAULT 'BORRADOR',
    `fecha_programada` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_camp_sede_estado` (`id_sede`, `estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `campanas_difusion_destinatarios` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `id_campana` INT NOT NULL,
    `id_cliente` INT DEFAULT NULL,
    `telefono` VARCHAR(30) NOT NULL,
    `nombre` VARCHAR(200) DEFAULT NULL,
    `variables_json` TEXT DEFAULT NULL,
    `id_mensaje_meta` VARCHAR(255) DEFAULT NULL,
    `estado_envio` VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
    `respondido` TINYINT(1) NOT NULL DEFAULT 0,
    `error_detalle` TEXT DEFAULT NULL,
    `fecha_envio` DATETIME DEFAULT NULL,
    `fecha_actualizacion` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_dest_camp_estado` (`id_campana`, `estado_envio`),
    INDEX `idx_dest_tel_fecha` (`telefono`, `fecha_envio`, `estado_envio`, `respondido`),
    CONSTRAINT `fk_dest_campana` FOREIGN KEY (`id_campana`) REFERENCES `campanas_difusion`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `waba_tarifas_sede` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_sede` INT NOT NULL UNIQUE,
    `tarifa_marketing` DECIMAL(10,4) NOT NULL DEFAULT 0.0850,
    `tarifa_utility` DECIMAL(10,4) NOT NULL DEFAULT 0.0250,
    `tarifa_authentication` DECIMAL(10,4) NOT NULL DEFAULT 0.0250,
    `tarifa_service` DECIMAL(10,4) NOT NULL DEFAULT 0.0150,
    `limite_facturacion` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `waba_ordenes_cobro` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `codigo_orden` VARCHAR(50) NOT NULL UNIQUE,
    `id_sede` INT NOT NULL,
    `fecha_desde` DATE NOT NULL,
    `fecha_hasta` DATE NOT NULL,
    `total_mensajes` INT NOT NULL DEFAULT 0,
    `costo_meta_usd` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    `monto_total_usd` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    `estado` ENUM('PENDIENTE', 'EN_REVISION', 'PAGADO', 'ANULADA') NOT NULL DEFAULT 'PENDIENTE',
    `metodo_pago` VARCHAR(80) DEFAULT NULL,
    `referencia_pago` VARCHAR(120) DEFAULT NULL,
    `fecha_pago` DATE DEFAULT NULL,
    `monto_pagado_bs` VARCHAR(80) DEFAULT NULL,
    `comprobante_ruta` VARCHAR(500) DEFAULT NULL,
    `observaciones_pago` TEXT DEFAULT NULL,
    `verificado_por` INT DEFAULT NULL,
    `fecha_verificacion` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_waba_ord_sede_est` (`id_sede`, `estado`),
    INDEX `idx_waba_ord_ref` (`metodo_pago`, `referencia_pago`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `waba_ordenes_detalles` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_orden` INT NOT NULL,
    `nombre_plantilla` VARCHAR(150) NOT NULL,
    `categoria` VARCHAR(50) DEFAULT 'MARKETING',
    `volumen` INT NOT NULL DEFAULT 0,
    `mensajes_gratuitos` INT NOT NULL DEFAULT 0,
    `mensajes_pagados` INT NOT NULL DEFAULT 0,
    `tarifa_unitaria_usd` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `subtotal_usd` DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    CONSTRAINT `fk_waba_det_orden` FOREIGN KEY (`id_orden`) REFERENCES `waba_ordenes_cobro`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notificacion_enviada` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `template` VARCHAR(150) DEFAULT NULL,
    `fecha` DATE DEFAULT NULL,
    `hora` TIME DEFAULT NULL,
    `telefono_emisor` VARCHAR(60) DEFAULT NULL,
    `telefono_receptor` VARCHAR(60) DEFAULT NULL,
    `contenido` TEXT DEFAULT NULL,
    `status` VARCHAR(30) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `configuracion_correo` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `smtp_host` VARCHAR(255) DEFAULT NULL,
    `smtp_port` INT DEFAULT 587,
    `smtp_user` VARCHAR(255) DEFAULT NULL,
    `smtp_pass` VARCHAR(255) DEFAULT NULL,
    `smtp_secure` VARCHAR(50) DEFAULT 'tls',
    `remitente_nombre` VARCHAR(255) DEFAULT NULL,
    `remitente_email` VARCHAR(255) DEFAULT NULL,
    `activo` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cola_correos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `destinatario_email` VARCHAR(255) NOT NULL,
    `destinatario_nombre` VARCHAR(255) NOT NULL,
    `asunto` VARCHAR(255) NOT NULL,
    `cuerpo_html` TEXT NOT NULL,
    `adjunto_ruta` VARCHAR(500) DEFAULT NULL,
    `estado` ENUM('Pendiente', 'Enviado', 'Error') DEFAULT 'Pendiente',
    `intentos` INT DEFAULT 0,
    `error_mensaje` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `sent_at` TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auditoria_webhooks` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `payload_json` LONGTEXT NOT NULL,
    `procesado` TINYINT(1) DEFAULT 0,
    `error_log` TEXT DEFAULT NULL,
    `fecha_recepcion` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `auditoria_sistema` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `id_usuario` INT DEFAULT NULL,
    `usuario_nombre` VARCHAR(120) DEFAULT NULL,
    `id_sede` INT DEFAULT NULL,
    `modulo` VARCHAR(80) NOT NULL,
    `accion` VARCHAR(100) NOT NULL,
    `descripcion` TEXT DEFAULT NULL,
    `entidad` VARCHAR(100) DEFAULT NULL,
    `id_registro` VARCHAR(64) DEFAULT NULL,
    `datos_anteriores` LONGTEXT DEFAULT NULL,
    `datos_nuevos` LONGTEXT DEFAULT NULL,
    `detalle_json` LONGTEXT DEFAULT NULL,
    `ip_origen` VARCHAR(64) DEFAULT NULL,
    `fecha_evento` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_modulo_fecha` (`modulo`, `fecha_evento`),
    INDEX `idx_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SPRINT 4 (OT-STARFI-2026-11): AGENDA COMERCIAL ([OMN-02]) Y COTIZADOR PDF ([OMN-03])
-- =============================================================================
CREATE TABLE IF NOT EXISTS `tareas_agenda_comercial` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `id_sede` INT NOT NULL DEFAULT 0,
    `id_cliente` INT NOT NULL DEFAULT 0,
    `id_conversacion` INT DEFAULT NULL,
    `id_oportunidad` INT DEFAULT NULL,
    `id_agente_asignado` INT NOT NULL DEFAULT 0,
    `creado_por` INT NOT NULL DEFAULT 0,
    `titulo` VARCHAR(200) NOT NULL,
    `descripcion` TEXT DEFAULT NULL,
    `tipo` VARCHAR(30) NOT NULL DEFAULT 'SEGUIMIENTO',
    `prioridad` VARCHAR(20) NOT NULL DEFAULT 'MEDIA',
    `fecha_vencimiento` DATETIME NOT NULL,
    `recordatorio_min_antes` INT NOT NULL DEFAULT 15,
    `alerta_disparada` TINYINT(1) NOT NULL DEFAULT 0,
    `estado` VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    `fecha_completada` DATETIME DEFAULT NULL,
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_agente_estado_venc` (`id_agente_asignado`, `estado`, `fecha_vencimiento`),
    INDEX `idx_sede_estado_venc` (`id_sede`, `estado`, `fecha_vencimiento`),
    INDEX `idx_cliente_estado` (`id_cliente`, `estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cotizaciones_rapidas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `codigo_cotizacion` VARCHAR(40) NOT NULL,
    `id_empresa` INT NOT NULL DEFAULT 1,
    `id_sede` INT NOT NULL DEFAULT 0,
    `id_cliente` INT NOT NULL DEFAULT 0,
    `id_conversacion` INT DEFAULT NULL,
    `id_oportunidad` INT DEFAULT NULL,
    `id_agente` INT NOT NULL DEFAULT 0,
    `moneda` VARCHAR(10) NOT NULL DEFAULT 'USD',
    `subtotal_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `descuento_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `aplica_iva` TINYINT(1) NOT NULL DEFAULT 0,
    `iva_pct` DECIMAL(5,2) NOT NULL DEFAULT 16.00,
    `iva_monto_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `validez_dias` INT NOT NULL DEFAULT 7,
    `condiciones` TEXT DEFAULT NULL,
    `notas` TEXT DEFAULT NULL,
    `pdf_ruta` VARCHAR(255) NOT NULL,
    `id_mensaje_meta` VARCHAR(120) DEFAULT NULL,
    `id_mensaje_local` INT DEFAULT NULL,
    `estado` VARCHAR(25) NOT NULL DEFAULT 'EMITIDA',
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_codigo_cotizacion` (`codigo_cotizacion`),
    INDEX `idx_sede_fecha` (`id_sede`, `fecha_creacion`),
    INDEX `idx_cliente` (`id_cliente`),
    INDEX `idx_conversacion` (`id_conversacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cotizaciones_rapidas_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_cotizacion` INT NOT NULL,
    `orden` INT NOT NULL DEFAULT 1,
    `descripcion` VARCHAR(255) NOT NULL,
    `cantidad` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    `precio_unitario_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `subtotal_usd` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    INDEX `idx_cotizacion` (`id_cotizacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
