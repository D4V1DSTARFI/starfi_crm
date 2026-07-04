ALTER TABLE conversaciones 
ADD COLUMN IF NOT EXISTS mensajes_no_leidos INT DEFAULT 0 AFTER estado;

ALTER TABLE mensajes_y_eventos 
ADD COLUMN IF NOT EXISTS id_mensaje_meta VARCHAR(255) AFTER id_conversacion,
ADD COLUMN IF NOT EXISTS estado_envio ENUM('ENVIADO', 'ENTREGADO', 'LEIDO', 'FALLIDO') DEFAULT NULL AFTER contenido,
ADD COLUMN IF NOT EXISTS url_archivo VARCHAR(500) AFTER estado_envio,
ADD COLUMN IF NOT EXISTS mime_type VARCHAR(100) AFTER url_archivo;

CREATE TABLE IF NOT EXISTS auditoria_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payload_json JSON NOT NULL,
    procesado BOOLEAN DEFAULT FALSE,
    error_log TEXT,
    fecha_recepcion DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
