CREATE TABLE IF NOT EXISTS bot_respuestas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_empresa INT NOT NULL DEFAULT 1,
    tipo VARCHAR(50) NOT NULL,
    disparador VARCHAR(255) NOT NULL,
    mensaje TEXT NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO bot_respuestas (tipo, disparador, mensaje) VALUES 
('EVENTO_SISTEMA', 'SALUDO_NUEVO', '¡Hola! Bienvenido a STARFI Corp. Para brindarte una mejor atención, por favor indícanos tu nombre y apellido.'),
('EVENTO_SISTEMA', 'SALUDO_RECURRENTE', '¡Hola {{nombre}}! Qué bueno tenerte de vuelta. ¿En qué podemos ayudarte el día de hoy?'),
('EVENTO_SISTEMA', 'ESPERA_HUMANO', 'Gracias por la información. En breves momentos un asesor humano te atenderá.')
ON DUPLICATE KEY UPDATE id=id;
