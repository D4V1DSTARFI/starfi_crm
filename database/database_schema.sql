-- Arquitectura de Base de Datos Multitenancy STARFI CRM

CREATE TABLE IF NOT EXISTS empresas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_comercial VARCHAR(255) NOT NULL,
    razon_social VARCHAR(255),
    documento_identidad VARCHAR(50),
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sedes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_empresa INT NOT NULL,
    nombre_sede VARCHAR(255) NOT NULL,
    direccion TEXT,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    estado ENUM('ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lineas_whatsapp (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_sede INT NOT NULL,
    numero_telefono VARCHAR(50) NOT NULL,
    meta_app_id VARCHAR(255) NOT NULL,
    meta_token TEXT NOT NULL,
    webhook_verify_token VARCHAR(255),
    estado_conexion ENUM('CONECTADO', 'DESCONECTADO', 'REQUIERE_VERIFICACION') DEFAULT 'REQUIERE_VERIFICACION',
    FOREIGN KEY (id_sede) REFERENCES sedes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios_agentes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_empresa INT NOT NULL,
    id_sede INT, -- Si es null, podría ser un admin global de la empresa
    nombre_completo VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol ENUM('ADMIN', 'SUPERVISOR', 'AGENTE') DEFAULT 'AGENTE',
    limite_chats_simultaneos INT DEFAULT 5,
    estado ENUM('ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (id_sede) REFERENCES sedes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clientes_contactos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_empresa INT NOT NULL,
    numero_whatsapp VARCHAR(50) NOT NULL,
    nombre VARCHAR(255),
    direccion TEXT,
    etiquetas JSON, -- Array de tags como ['VIP', 'Lead Frío']
    notas_internas TEXT,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    ultimo_contacto DATETIME,
    estado ENUM('ACTIVO', 'INACTIVO', 'BLOQUEADO') DEFAULT 'ACTIVO',
    FOREIGN KEY (id_empresa) REFERENCES empresas(id) ON DELETE CASCADE,
    UNIQUE KEY (id_empresa, numero_whatsapp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_linea INT NOT NULL,
    id_cliente INT NOT NULL,
    id_agente INT, -- Puede ser null si el bot está atendiendo
    estado ENUM('BOT_RECOPILANDO', 'ESPERA_ASIGNACION', 'ATENDIENDO', 'RESUELTO', 'CERRADO') DEFAULT 'BOT_RECOPILANDO',
    mensajes_no_leidos INT DEFAULT 0,
    fecha_inicio DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_primera_respuesta DATETIME, -- Para calcular el SLA FRT
    fecha_resolucion DATETIME,
    FOREIGN KEY (id_linea) REFERENCES lineas_whatsapp(id) ON DELETE CASCADE,
    FOREIGN KEY (id_cliente) REFERENCES clientes_contactos(id) ON DELETE CASCADE,
    FOREIGN KEY (id_agente) REFERENCES usuarios_agentes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mensajes_y_eventos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_conversacion INT NOT NULL,
    id_mensaje_meta VARCHAR(255),
    tipo ENUM('TEXTO', 'IMAGEN', 'DOCUMENTO', 'EVENTO_SISTEMA') DEFAULT 'TEXTO',
    origen ENUM('CLIENTE', 'BOT', 'AGENTE', 'API_TRANSACCIONAL') NOT NULL,
    contenido TEXT,
    estado_envio ENUM('ENVIADO', 'ENTREGADO', 'LEIDO', 'FALLIDO') DEFAULT NULL,
    url_archivo VARCHAR(500),
    mime_type VARCHAR(100),
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_conversacion) REFERENCES conversaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payload_json JSON NOT NULL,
    procesado BOOLEAN DEFAULT FALSE,
    error_log TEXT,
    fecha_recepcion DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
