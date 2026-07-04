-- Datos de prueba para STARFI CRM (Ejecutar en PhpMyAdmin sobre starfi_crm)

-- 1. Crear la empresa matriz
INSERT INTO empresas (id, nombre_comercial, razon_social, documento_identidad) 
VALUES (1, 'Empresa Corp. S.A.', 'Empresas Corporativas C.A.', 'J-12345678-9');

-- 2. Crear una Sede y Línea
INSERT INTO sedes (id, id_empresa, nombre_sede) VALUES (1, 1, 'Sede Principal');
INSERT INTO lineas_whatsapp (id, id_sede, numero_telefono, meta_app_id, meta_token, estado_conexion) 
VALUES (1, 1, '+584140000000', '123456789', 'TokenFalso', 'CONECTADO');

-- 3. Crear el Operador (ID 1) para que el Login temporal funcione correctamente
INSERT INTO usuarios_agentes (id, id_empresa, id_sede, nombre_completo, email, password_hash, rol) 
VALUES (1, 1, 1, 'Administrador Root', 'admin@starfi.com', 'dummy_hash', 'ADMIN');

-- 4. Crear Clientes
INSERT INTO clientes_contactos (id, id_empresa, numero_whatsapp, nombre) 
VALUES (1, 1, '584120000001', 'Marta Gómez');
INSERT INTO clientes_contactos (id, id_empresa, numero_whatsapp, nombre) 
VALUES (2, 1, '584240000002', 'Juan Pérez');

-- 5. Crear Conversaciones
INSERT INTO conversaciones (id, id_linea, id_cliente, id_agente, estado, fecha_inicio) 
VALUES (1, 1, 1, 1, 'ATENDIENDO', DATE_SUB(NOW(), INTERVAL 1 HOUR));

INSERT INTO conversaciones (id, id_linea, id_cliente, id_agente, estado, fecha_inicio) 
VALUES (2, 1, 2, NULL, 'ESPERA_ASIGNACION', DATE_SUB(NOW(), INTERVAL 10 MINUTE));

-- 6. Insertar Mensajes
-- Mensajes Conversación 1 (Atendida por humano)
INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido, timestamp) 
VALUES (1, 'CLIENTE', 'Hola, necesito saber el precio de las licencias.', DATE_SUB(NOW(), INTERVAL 59 MINUTE));

INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido, timestamp) 
VALUES (1, 'EVENTO_SISTEMA', 'Lead creado por Bot de Onboarding', DATE_SUB(NOW(), INTERVAL 58 MINUTE));

INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido, timestamp) 
VALUES (1, 'AGENTE', '¡Hola Marta! Con gusto, las licencias corporativas cuestan $50/mes.', DATE_SUB(NOW(), INTERVAL 55 MINUTE));

-- Mensajes Conversación 2 (En Espera por asignación, intervenida por la API de Ventas)
INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido, timestamp) 
VALUES (2, 'CLIENTE', 'Acabo de hacer una transferencia por mi compra web.', DATE_SUB(NOW(), INTERVAL 9 MINUTE));

INSERT INTO mensajes_y_eventos (id_conversacion, origen, contenido, timestamp) 
VALUES (2, 'API_TRANSACCIONAL', 'Pago confirmado por sistema ERP (Ref: #5942)', DATE_SUB(NOW(), INTERVAL 8 MINUTE));
