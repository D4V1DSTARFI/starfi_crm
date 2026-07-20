-- MariaDB dump 10.19  Distrib 10.4.25-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: starfi_caja
-- ------------------------------------------------------
-- Server version	10.4.25-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `starfi_caja`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `starfi_caja` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

USE `starfi_caja`;

--
-- Table structure for table `admin_procesos_token`
--

DROP TABLE IF EXISTS `admin_procesos_token`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `admin_procesos_token` (
  `codigo_proceso` varchar(100) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `requiere_token` int(11) DEFAULT 0,
  PRIMARY KEY (`codigo_proceso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `admin_procesos_token`
--

LOCK TABLES `admin_procesos_token` WRITE;
/*!40000 ALTER TABLE `admin_procesos_token` DISABLE KEYS */;
INSERT INTO `admin_procesos_token` VALUES ('CAJA_ANULAR_DOCUMENTO','Permite invalidar un recibo, devolviendo los items y suprimiendo sus pagos.',0),('CAJA_BILLETERA_EGRESO','Retiro / Egreso manual de fondos de Billetera de Clientes',1),('CAJA_DESCUENTO_MANUAL','Procesar Montos de Descuento Abiertos',1),('CAJA_ELIMINAR_GASTO','Eliminar o Reversar un Gasto de Cierre de Caja',1),('CAJA_ELIMINAR_PAGO','Eliminar un Pago Individual Vinculado a Factura o Nota',0),('CAJA_LIQUIDAR_PRESTAMO','Autorización para liquidación de Préstamos de Personal.',0),('CAJA_LOTE_MULTIPLE','Ejecutar Cuadre y Liberación de Lotes Lote POS',0),('CAJA_MODIFICAR_CORRELATIVO','Editar Contadores o Correlativos Manuales',1),('CAJA_PAGO_ANTICIPO','Autorización para liquidación de Anticipos de Nómina.',0),('CAJA_REGISTRO_CXC','Aprobar Cuentas por Cobrar (Crédito)',1),('CAJA_USO_INTERNO','Aprobar Consumo de Uso Interno (Merma / Muestra)',1);
/*!40000 ALTER TABLE `admin_procesos_token` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_billetera_kardex`
--

DROP TABLE IF EXISTS `caja_billetera_kardex`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_billetera_kardex` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_cliente` int(11) NOT NULL,
  `modulo` varchar(50) NOT NULL,
  `tipo_movimiento` enum('INGRESO','EGRESO') NOT NULL,
  `monto_usd` decimal(20,2) NOT NULL,
  `monto_ves` decimal(20,2) NOT NULL,
  `tasa_cambio` decimal(20,2) NOT NULL,
  `moneda` varchar(15) DEFAULT 'USD',
  `control` varchar(100) DEFAULT NULL,
  `comentario` text DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `id_usuario` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_billetera_kardex`
--

LOCK TABLES `caja_billetera_kardex` WRITE;
/*!40000 ALTER TABLE `caja_billetera_kardex` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_billetera_kardex` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_billetera_saldos`
--

DROP TABLE IF EXISTS `caja_billetera_saldos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_billetera_saldos` (
  `id_cliente` int(11) NOT NULL,
  `saldo_usd` decimal(20,2) NOT NULL DEFAULT 0.00,
  `saldo_ves` decimal(20,4) DEFAULT 0.0000,
  `ultima_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_cliente`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_billetera_saldos`
--

LOCK TABLES `caja_billetera_saldos` WRITE;
/*!40000 ALTER TABLE `caja_billetera_saldos` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_billetera_saldos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_cierres_diarios`
--

DROP TABLE IF EXISTS `caja_cierres_diarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_cierres_diarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha_operacion` date NOT NULL,
  `fecha_registro` datetime NOT NULL,
  `id_caja` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `total_ingresos_usd` decimal(18,2) DEFAULT 0.00,
  `total_ingresos_ves` decimal(18,2) DEFAULT 0.00,
  `total_egresos_usd` decimal(18,2) DEFAULT 0.00,
  `total_egresos_ves` decimal(18,2) DEFAULT 0.00,
  `total_vueltos_usd` decimal(18,2) DEFAULT 0.00,
  `total_vueltos_ves` decimal(18,2) DEFAULT 0.00,
  `total_cxc_usd` decimal(18,2) DEFAULT 0.00,
  `total_notas_sin_facturar` decimal(18,2) DEFAULT 0.00,
  `total_pedidos_registrados` decimal(18,2) DEFAULT 0.00,
  `total_facturado_ves` decimal(18,2) DEFAULT 0.00,
  `total_reporte_z_ves` decimal(18,2) DEFAULT 0.00,
  `saldo_efectivo_usd` decimal(18,2) DEFAULT 0.00,
  `saldo_efectivo_ves` decimal(18,2) DEFAULT 0.00,
  `estado` tinyint(1) DEFAULT 1,
  `observaciones` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_cierres_diarios`
--

LOCK TABLES `caja_cierres_diarios` WRITE;
/*!40000 ALTER TABLE `caja_cierres_diarios` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_cierres_diarios` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_gastos`
--

DROP TABLE IF EXISTS `caja_gastos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_gastos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_caja` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `id_tipo_gasto` int(11) DEFAULT 0,
  `id_autorizado` int(11) DEFAULT 0,
  `concepto` varchar(255) NOT NULL,
  `beneficiario` varchar(255) NOT NULL,
  `monto_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
  `monto_ves` decimal(12,2) NOT NULL DEFAULT 0.00,
  `moneda` varchar(10) DEFAULT 'USD',
  `tasa` decimal(10,2) NOT NULL DEFAULT 0.00,
  `metodo_pago` varchar(100) NOT NULL COMMENT 'EFECTIVO USD, EFECTIVO VES, PAGO MOVIL, BILLETERA',
  `nro_referencia` varchar(100) DEFAULT NULL,
  `fecha` datetime NOT NULL,
  `fecha_registro` datetime DEFAULT NULL,
  `estatus` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=Activo, 0=Anulado',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_gastos`
--

LOCK TABLES `caja_gastos` WRITE;
/*!40000 ALTER TABLE `caja_gastos` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_gastos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_lotes_pos`
--

DROP TABLE IF EXISTS `caja_lotes_pos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_lotes_pos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `fecha_registro` datetime DEFAULT NULL,
  `id_cuenta_bancaria` int(11) NOT NULL,
  `n_lote` varchar(100) NOT NULL,
  `monto_ves` decimal(18,2) NOT NULL,
  `observacion` text DEFAULT NULL,
  `id_caja` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_lotes_pos`
--

LOCK TABLES `caja_lotes_pos` WRITE;
/*!40000 ALTER TABLE `caja_lotes_pos` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_lotes_pos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_notas_debito`
--

DROP TABLE IF EXISTS `caja_notas_debito`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_notas_debito` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_cliente` int(11) NOT NULL,
  `id_venta` int(11) NOT NULL,
  `id_pedido` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `hora` time NOT NULL,
  `motivo` text NOT NULL,
  `total` decimal(20,2) NOT NULL,
  `moneda` varchar(5) NOT NULL DEFAULT 'USD',
  `status` varchar(20) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_notas_debito`
--

LOCK TABLES `caja_notas_debito` WRITE;
/*!40000 ALTER TABLE `caja_notas_debito` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_notas_debito` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_pagomovil_auditoria`
--

DROP TABLE IF EXISTS `caja_pagomovil_auditoria`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_pagomovil_auditoria` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` datetime DEFAULT current_timestamp(),
  `id_cliente` int(11) NOT NULL,
  `banco_origen` varchar(50) DEFAULT NULL,
  `banco_receptor` varchar(50) DEFAULT 'API',
  `referencia` varchar(100) NOT NULL,
  `monto_ves` decimal(18,2) DEFAULT 0.00,
  `monto_usd` decimal(18,4) DEFAULT 0.0000,
  `tasa` decimal(18,4) DEFAULT 0.0000,
  `moneda_destino` varchar(10) DEFAULT 'VES',
  `tipo_operacion` varchar(30) DEFAULT NULL,
  `estado` varchar(30) DEFAULT 'ACREDITADO_WALLET',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_pagomovil_auditoria`
--

LOCK TABLES `caja_pagomovil_auditoria` WRITE;
/*!40000 ALTER TABLE `caja_pagomovil_auditoria` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_pagomovil_auditoria` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_pagomovil_config`
--

DROP TABLE IF EXISTS `caja_pagomovil_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_pagomovil_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `banco_codigo` varchar(20) DEFAULT 'MERCANTIL',
  `nombre` varchar(50) DEFAULT 'MERCANTIL',
  `regla_promocion` tinyint(1) DEFAULT 2,
  `merchant_id` varchar(50) DEFAULT '',
  `terminal_id` varchar(50) DEFAULT '1',
  `integrator_id` varchar(50) DEFAULT '1',
  `api_key` varchar(255) DEFAULT '',
  `origin_mobile` varchar(20) DEFAULT '',
  `client_id` varchar(100) DEFAULT '',
  `transfer_merchant_id` varchar(50) DEFAULT '',
  `transfer_api_key` varchar(255) DEFAULT '',
  `transfer_client_id` varchar(100) DEFAULT '',
  `transfer_account` varchar(30) DEFAULT '',
  `transfer_integrator_id` varchar(10) DEFAULT '1',
  `transfer_terminal_id` varchar(20) DEFAULT '1',
  `activo` tinyint(1) DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_pagomovil_config`
--

LOCK TABLES `caja_pagomovil_config` WRITE;
/*!40000 ALTER TABLE `caja_pagomovil_config` DISABLE KEYS */;
INSERT INTO `caja_pagomovil_config` VALUES (1,'MERCANTIL','MERCANTIL',2,'225381','1','1','A11366628503920201015AA26','584126310095','cbf3a45d-c813-4c35-ae25-1775bffdcfb5','11366628','0011366628J000000411047112201707120000','b348671fc6381f92149be0759f07e4dd','01050035461035562251','1','1',1,'2026-03-26 19:54:48');
/*!40000 ALTER TABLE `caja_pagomovil_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_perfil_fiscal`
--

DROP TABLE IF EXISTS `caja_perfil_fiscal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_perfil_fiscal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tipo_contribuyente` enum('ORDINARIO','ESPECIAL') DEFAULT 'ORDINARIO',
  `maquina_fiscal` tinyint(1) DEFAULT 0,
  `facturacion_digital` tinyint(1) DEFAULT 0,
  `facturacion_manual` tinyint(1) DEFAULT 0,
  `facturacion_pedidos` tinyint(1) DEFAULT 1,
  `fecha_actualizacion` datetime DEFAULT NULL,
  `permitir_vueltos_ves` int(11) NOT NULL DEFAULT 0,
  `permitir_vueltos_usd` int(11) NOT NULL DEFAULT 0,
  `permitir_vueltos_pm` int(11) NOT NULL DEFAULT 0,
  `limite_reserva_empleado` decimal(10,2) DEFAULT 0.00,
  `dias_holgura_nomina` int(11) DEFAULT 5,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_perfil_fiscal`
--

LOCK TABLES `caja_perfil_fiscal` WRITE;
/*!40000 ALTER TABLE `caja_perfil_fiscal` DISABLE KEYS */;
INSERT INTO `caja_perfil_fiscal` VALUES (1,'ESPECIAL',1,1,1,1,'2026-05-28 13:52:04',1,1,1,100.00,5);
/*!40000 ALTER TABLE `caja_perfil_fiscal` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_reservas`
--

DROP TABLE IF EXISTS `caja_reservas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_reservas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_retiro` int(11) NOT NULL,
  `id_caja` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `periodo` varchar(50) DEFAULT NULL,
  `monto_usd` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ret` (`id_retiro`),
  KEY `idx_caja` (`id_caja`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_reservas`
--

LOCK TABLES `caja_reservas` WRITE;
/*!40000 ALTER TABLE `caja_reservas` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_reservas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_retiros`
--

DROP TABLE IF EXISTS `caja_retiros`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_retiros` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_caja` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha` datetime NOT NULL,
  `fecha_registro` datetime DEFAULT NULL,
  `persona` varchar(100) NOT NULL,
  `monto_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `monto_ves` decimal(12,2) NOT NULL DEFAULT 0.00,
  `moneda_retiro` varchar(5) NOT NULL,
  `tasa` decimal(12,2) NOT NULL DEFAULT 1.00,
  `comentario` text DEFAULT NULL,
  `estatus` int(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_retiros`
--

LOCK TABLES `caja_retiros` WRITE;
/*!40000 ALTER TABLE `caja_retiros` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_retiros` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_tipos_gasto`
--

DROP TABLE IF EXISTS `caja_tipos_gasto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_tipos_gasto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estatus` int(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_tipos_gasto`
--

LOCK TABLES `caja_tipos_gasto` WRITE;
/*!40000 ALTER TABLE `caja_tipos_gasto` DISABLE KEYS */;
INSERT INTO `caja_tipos_gasto` VALUES (1,'ALMUERZOS Y COMIDAS',1),(2,'PAGO DE PROVEEDORES',1),(3,'LOGISTICA Y PASAJES',1),(4,'USO INTERNO',1),(5,'REPARACIONES',1),(6,'BONO VACACIONAL',1);
/*!40000 ALTER TABLE `caja_tipos_gasto` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `caja_vueltos_absorbidos`
--

DROP TABLE IF EXISTS `caja_vueltos_absorbidos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `caja_vueltos_absorbidos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta` int(11) NOT NULL,
  `monto_ves` decimal(10,2) NOT NULL,
  `fecha` datetime NOT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `id_sede` int(11) DEFAULT NULL,
  `id_caja` varchar(100) DEFAULT NULL,
  `monto_usd` decimal(20,2) DEFAULT 0.00,
  `moneda` varchar(10) DEFAULT 'VES',
  `tipo_vuelto` enum('EFECTIVO_USD','EFECTIVO_VES','PAGO_MOVIL','BILLETERA_USD','BILLETERA_VES') DEFAULT 'EFECTIVO_USD',
  `id_cliente` int(11) DEFAULT NULL,
  `tasa_cambio` decimal(20,2) DEFAULT 0.00,
  `estado_vuelto` enum('PENDIENTE_DESTINO','EFECTIVO_ENTREGADO','BILLETERA_USD','BILLETERA_VES','PAGO_MOVIL','ANULADO') DEFAULT 'PENDIENTE_DESTINO',
  `es_ingreso` tinyint(1) DEFAULT 1 COMMENT '1=excedente recibido 0=destino aplicado',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `caja_vueltos_absorbidos`
--

LOCK TABLES `caja_vueltos_absorbidos` WRITE;
/*!40000 ALTER TABLE `caja_vueltos_absorbidos` DISABLE KEYS */;
/*!40000 ALTER TABLE `caja_vueltos_absorbidos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_cajas`
--

DROP TABLE IF EXISTS `cat_cajas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_cajas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `correlativo_fiscal` int(11) DEFAULT 0,
  `serial_impresora` varchar(50) DEFAULT NULL,
  `id_sede` int(11) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `estatus` tinyint(1) DEFAULT 1,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_cajas`
--

LOCK TABLES `cat_cajas` WRITE;
/*!40000 ALTER TABLE `cat_cajas` DISABLE KEYS */;
INSERT INTO `cat_cajas` VALUES (1,'CAJA1',23657,'Z7C7025214',1,2,1,'2026-03-17 11:01:31'),(4,'CAJA2',21706,NULL,1,3,1,'2026-03-17 11:25:30');
/*!40000 ALTER TABLE `cat_cajas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_forma_pago`
--

DROP TABLE IF EXISTS `cat_forma_pago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_forma_pago` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) NOT NULL,
  `descripcion` varchar(100) NOT NULL,
  `estatus` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_forma_pago`
--

LOCK TABLES `cat_forma_pago` WRITE;
/*!40000 ALTER TABLE `cat_forma_pago` DISABLE KEYS */;
INSERT INTO `cat_forma_pago` VALUES (1,'EFECTIVO','EFECTIVO FÍSICO',1),(2,'PUNTO','PUNTO DE VENTA',1),(3,'TRANSFERENCIA','TRANSFERENCIA BANCARIA',1),(4,'ELECTRONICO','PAGO ELECTRÓNICO (ZELLE, BINANCE)',1),(5,'OTROS','OTROS MÉTODOS',1);
/*!40000 ALTER TABLE `cat_forma_pago` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_moneda`
--

DROP TABLE IF EXISTS `cat_moneda`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_moneda` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(10) NOT NULL,
  `descripcion` varchar(50) NOT NULL,
  `simbolo` varchar(5) NOT NULL,
  `estatus` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_moneda`
--

LOCK TABLES `cat_moneda` WRITE;
/*!40000 ALTER TABLE `cat_moneda` DISABLE KEYS */;
INSERT INTO `cat_moneda` VALUES (1,'VES','BOLÍVARES','Bs',1),(2,'USD','DÓLARES','$',1),(3,'EUR','EUROS','€',1),(4,'COP','PESOS COLOMBIANOS','$',0);
/*!40000 ALTER TABLE `cat_moneda` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `control_correlativo_factura`
--

DROP TABLE IF EXISTS `control_correlativo_factura`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `control_correlativo_factura` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `correlativo_interno` int(11) DEFAULT 0,
  `correlativo_manual` int(11) DEFAULT 0,
  `correlativo_digital` int(11) NOT NULL DEFAULT 0,
  `fecha_actualizacion` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `control_correlativo_factura`
--

LOCK TABLES `control_correlativo_factura` WRITE;
/*!40000 ALTER TABLE `control_correlativo_factura` DISABLE KEYS */;
/*!40000 ALTER TABLE `control_correlativo_factura` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cuenta_por_cobrar`
--

DROP TABLE IF EXISTS `cuenta_por_cobrar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cuenta_por_cobrar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_documento_comercial` int(11) NOT NULL,
  `id_venta` int(11) DEFAULT NULL,
  `cliente_identificador` varchar(255) DEFAULT NULL,
  `total_deuda_ves` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tasa_bcv_emision` decimal(10,2) NOT NULL DEFAULT 1.00,
  `total_deuda_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_abonado_ves` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_abonado_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `saldo_pendiente_ves` decimal(15,2) NOT NULL DEFAULT 0.00,
  `saldo_pendiente_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `estado` varchar(50) NOT NULL DEFAULT '[POR PAGAR]',
  `fecha_registro` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_doc_idx` (`id_documento_comercial`),
  KEY `estado_idx` (`estado`),
  KEY `id_venta_idx` (`id_venta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cuenta_por_cobrar`
--

LOCK TABLES `cuenta_por_cobrar` WRITE;
/*!40000 ALTER TABLE `cuenta_por_cobrar` DISABLE KEYS */;
/*!40000 ALTER TABLE `cuenta_por_cobrar` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cuentas_bancarias`
--

DROP TABLE IF EXISTS `cuentas_bancarias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cuentas_bancarias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_banco` varchar(100) NOT NULL,
  `forma_pago` enum('EFECTIVO','PUNTO','TRANSFERENCIA','ELECTRONICO','OTROS') DEFAULT 'OTROS',
  `id_banco_destino` int(11) DEFAULT NULL,
  `titular` varchar(150) DEFAULT NULL,
  `id_sede` varchar(50) DEFAULT NULL,
  `metodo_pago_id` int(11) NOT NULL,
  `moneda` varchar(5) NOT NULL,
  `estatus` int(11) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `metodo_pago_id` (`metodo_pago_id`),
  CONSTRAINT `cuentas_bancarias_ibfk_1` FOREIGN KEY (`metodo_pago_id`) REFERENCES `metodos_pago` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cuentas_bancarias`
--

LOCK TABLES `cuentas_bancarias` WRITE;
/*!40000 ALTER TABLE `cuentas_bancarias` DISABLE KEYS */;
INSERT INTO `cuentas_bancarias` VALUES (1,'PUNTO BANESCO','PUNTO',10,'SUPERFORMICA','1',3,'VES',1),(2,'PUNTO MERCANTIL','PUNTO',4,'SUPERFORMICA','1',3,'VES',1),(3,'PUNTO VENEZUELA','PUNTO',2,'SUPERFORMICA','1',3,'VES',1),(4,'TRANSFERENCIA BANESCO','TRANSFERENCIA',10,'SUPERFORMICA','1',14,'VES',1),(5,'TRANSFERENCIA VENEZUELA','TRANSFERENCIA',2,'SUPERFORMICA','1',14,'VES',1),(6,'TRANSFERENCIA MERCANTIIL','TRANSFERENCIA',4,'SUPERFORMICA','1',14,'VES',1),(7,'ZELLE JC','ELECTRONICO',NULL,'JUAN ITRIAGO','1',4,'USD',1),(8,'ZELLE MC','ELECTRONICO',NULL,'MERSHA CAMPOS','1',4,'USD',1),(9,'ZINLI','ELECTRONICO',NULL,'YOSIBEL LADINO','1',4,'USD',1),(10,'DOLAR DIGITAL','ELECTRONICO',NULL,'USDT','1',4,'VES',1);
/*!40000 ALTER TABLE `cuentas_bancarias` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `documentos_comerciales`
--

DROP TABLE IF EXISTS `documentos_comerciales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documentos_comerciales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_documento_origen` int(11) DEFAULT NULL,
  `tipo_documento` enum('PEDIDO','FACTURA','NOTA_CREDITO','NOTA_ENTREGA') NOT NULL DEFAULT 'NOTA_ENTREGA',
  `id_venta` int(11) DEFAULT NULL,
  `correlativo_interno` varchar(50) DEFAULT NULL,
  `id_sede` varchar(50) DEFAULT NULL,
  `id_caja` varchar(50) DEFAULT NULL,
  `id_usuario_cajero` int(11) DEFAULT NULL,
  `base_imponible_ves` decimal(20,2) NOT NULL DEFAULT 0.00,
  `base_exenta_ves` decimal(20,2) NOT NULL DEFAULT 0.00,
  `descuento_ves` decimal(20,2) DEFAULT 0.00,
  `iva_ves` decimal(20,2) NOT NULL DEFAULT 0.00,
  `igtf_ves` decimal(20,2) NOT NULL DEFAULT 0.00,
  `total_ves` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fecha_emision` timestamp NOT NULL DEFAULT current_timestamp(),
  `tasa_cambio` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `base_imponible_usd` decimal(20,2) NOT NULL DEFAULT 0.00,
  `base_exenta_usd` decimal(20,2) NOT NULL DEFAULT 0.00,
  `descuento_usd` decimal(20,2) DEFAULT 0.00,
  `iva_usd` decimal(20,2) NOT NULL DEFAULT 0.00,
  `igtf_usd` decimal(20,2) NOT NULL DEFAULT 0.00,
  `total_usd` decimal(20,2) NOT NULL DEFAULT 0.00,
  `numero_fiscal` varchar(50) DEFAULT NULL,
  `estado` varchar(50) DEFAULT NULL,
  `estado_digital` enum('N/A','PENDIENTE','ENVIADO','RECHAZADO') NOT NULL DEFAULT 'N/A',
  `numero_control_digital` varchar(100) DEFAULT NULL,
  `url_consulta_digital` text DEFAULT NULL,
  `url_visual_pdf` text DEFAULT NULL,
  `errores_digitales` text DEFAULT NULL,
  `motivo_anulacion` varchar(255) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documentos_comerciales`
--

LOCK TABLES `documentos_comerciales` WRITE;
/*!40000 ALTER TABLE `documentos_comerciales` DISABLE KEYS */;
/*!40000 ALTER TABLE `documentos_comerciales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `documentos_items`
--

DROP TABLE IF EXISTS `documentos_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documentos_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `documento_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `precio_unitario_usd` decimal(20,4) NOT NULL,
  `precio_unitario_ves_fiscal` decimal(20,2) NOT NULL,
  `tasa_aplicada` decimal(10,4) NOT NULL,
  `es_exento` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `documento_id` (`documento_id`),
  CONSTRAINT `documentos_items_ibfk_1` FOREIGN KEY (`documento_id`) REFERENCES `documentos_comerciales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documentos_items`
--

LOCK TABLES `documentos_items` WRITE;
/*!40000 ALTER TABLE `documentos_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `documentos_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fact_manual`
--

DROP TABLE IF EXISTS `fact_manual`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fact_manual` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `fecha_registro` datetime DEFAULT NULL,
  `factura_i` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `factura_f` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `subt_venta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `igtf_venta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `iva_venta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_venta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sede` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fact_manual`
--

LOCK TABLES `fact_manual` WRITE;
/*!40000 ALTER TABLE `fact_manual` DISABLE KEYS */;
/*!40000 ALTER TABLE `fact_manual` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `factura`
--

DROP TABLE IF EXISTS `factura`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `factura` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `n_factura` varchar(50) COLLATE utf8mb4_spanish_ci NOT NULL,
  `iva` decimal(14,2) NOT NULL DEFAULT 0.00,
  `iva_pr` decimal(14,2) NOT NULL DEFAULT 0.00,
  `iva_retenido` decimal(14,2) NOT NULL DEFAULT 0.00,
  `iva_percibido` decimal(14,2) NOT NULL DEFAULT 0.00,
  `efectivo_iva` decimal(14,2) NOT NULL DEFAULT 0.00,
  `punto_iva` decimal(14,2) NOT NULL DEFAULT 0.00,
  `zelle_iva` decimal(14,2) NOT NULL DEFAULT 0.00,
  `transferencia_iva` decimal(14,2) NOT NULL DEFAULT 0.00,
  `nc_iva` float NOT NULL,
  `igtf` decimal(14,2) NOT NULL DEFAULT 0.00,
  `efectivo_igtf` decimal(14,2) NOT NULL DEFAULT 0.00,
  `punto_igtf` decimal(14,2) NOT NULL DEFAULT 0.00,
  `zelle_igtf` decimal(14,2) NOT NULL DEFAULT 0.00,
  `transferencia_igtf` decimal(14,2) NOT NULL DEFAULT 0.00,
  `nc_igtf` float NOT NULL,
  `estado` varchar(50) COLLATE utf8mb4_spanish_ci NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_spanish_ci NOT NULL,
  `caja` int(11) NOT NULL,
  `id_nota` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id` (`id`,`fecha`,`n_factura`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `factura`
--

LOCK TABLES `factura` WRITE;
/*!40000 ALTER TABLE `factura` DISABLE KEYS */;
/*!40000 ALTER TABLE `factura` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `factura_emitidas`
--

DROP TABLE IF EXISTS `factura_emitidas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `factura_emitidas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `numero` int(11) NOT NULL,
  `base` decimal(14,2) NOT NULL DEFAULT 0.00,
  `iva` decimal(14,2) NOT NULL DEFAULT 0.00,
  `iva_retenido` decimal(14,2) NOT NULL DEFAULT 0.00,
  `igtf` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` varchar(50) NOT NULL,
  `metodo` varchar(50) NOT NULL,
  `caja` varchar(50) NOT NULL,
  `modulo` varchar(50) NOT NULL,
  `cliente` int(11) NOT NULL,
  `vendedor` int(11) NOT NULL,
  `tasa` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `n_fiscal` int(11) NOT NULL,
  `doc_referencia` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `factura_emitidas`
--

LOCK TABLES `factura_emitidas` WRITE;
/*!40000 ALTER TABLE `factura_emitidas` DISABLE KEYS */;
/*!40000 ALTER TABLE `factura_emitidas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facturacion_digital_config`
--

DROP TABLE IF EXISTS `facturacion_digital_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `facturacion_digital_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url_api` varchar(255) NOT NULL,
  `token_api` text NOT NULL,
  `token_password` varchar(255) DEFAULT NULL,
  `entorno` enum('PRUEBA','PRODUCCION') NOT NULL DEFAULT 'PRUEBA',
  `activa` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_modificacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facturacion_digital_config`
--

LOCK TABLES `facturacion_digital_config` WRITE;
/*!40000 ALTER TABLE `facturacion_digital_config` DISABLE KEYS */;
INSERT INTO `facturacion_digital_config` VALUES (1,'https://demoemisionv2.thefactoryhka.com.ve/api/EmisionDocumento','zzqupzrlktlx_tfhka','KNKQK?QNQmk;','PRUEBA',1,'2026-03-27 18:19:42');
/*!40000 ALTER TABLE `facturacion_digital_config` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `facturacion_digital_logs`
--

DROP TABLE IF EXISTS `facturacion_digital_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `facturacion_digital_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_documento_comercial` int(11) NOT NULL,
  `tipo_operacion` varchar(50) NOT NULL DEFAULT 'EMISION',
  `request_json` longtext DEFAULT NULL,
  `response_json` longtext DEFAULT NULL,
  `http_status` int(4) DEFAULT NULL,
  `fecha_peticion` datetime DEFAULT current_timestamp(),
  `estado_final` enum('EXITO','ERROR','TIMEOUT') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_doc` (`id_documento_comercial`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `facturacion_digital_logs`
--

LOCK TABLES `facturacion_digital_logs` WRITE;
/*!40000 ALTER TABLE `facturacion_digital_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `facturacion_digital_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `metodos_pago`
--

DROP TABLE IF EXISTS `metodos_pago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `metodos_pago` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `moneda_base` enum('USD','VES') NOT NULL,
  `comando_hka` varchar(5) NOT NULL,
  `aplica_igtf` tinyint(1) NOT NULL DEFAULT 0,
  `forzar_facturacion` tinyint(1) DEFAULT 0,
  `activo_promociones` tinyint(1) DEFAULT 1,
  `permitir_gastos` tinyint(1) DEFAULT 0,
  `permitir_factura` tinyint(1) DEFAULT 1,
  `permitir_nota` tinyint(1) DEFAULT 1,
  `permitir_pedido` tinyint(1) DEFAULT 1,
  `permitir_cxc` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `metodos_pago`
--

LOCK TABLES `metodos_pago` WRITE;
/*!40000 ALTER TABLE `metodos_pago` DISABLE KEYS */;
INSERT INTO `metodos_pago` VALUES (1,'EFECTIVO USD','USD','220',1,0,1,1,1,1,1,1),(2,'EFECTIVO VES','VES','201',0,0,0,1,1,1,1,1),(3,'PUNTO DE VENTA','VES','202',0,1,0,0,1,1,1,1),(4,'ZELLE USD','USD','208',0,1,1,0,1,1,1,1),(5,'PAGO MOVIL VES','VES','204',0,0,0,1,1,1,1,1),(6,'CUENTA POR COBRAR (CXC)','USD','201',0,0,1,0,1,1,1,1),(8,'DESCUENTO','USD','201',0,0,1,0,1,1,1,1),(10,'RETENCION IVA 100%','VES','206',0,0,1,0,1,1,1,1),(11,'RETENCION IVA 75%','VES','206',0,0,1,0,1,1,1,1),(12,'BILLETERA USD','USD','201',0,0,1,0,1,1,1,1),(13,'BILLETERA VES','VES','202',0,0,0,0,1,1,1,1),(14,'TRANSFERENCIA BANCARIA','VES','203',0,1,0,0,1,1,1,1);
/*!40000 ALTER TABLE `metodos_pago` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notas_liberadas_caja`
--

DROP TABLE IF EXISTS `notas_liberadas_caja`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notas_liberadas_caja` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_venta` int(11) NOT NULL,
  `tipo_doc` varchar(50) NOT NULL,
  `fecha_liberacion` datetime NOT NULL,
  `estatus` varchar(50) DEFAULT '[PENDIENTE]',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notas_liberadas_caja`
--

LOCK TABLES `notas_liberadas_caja` WRITE;
/*!40000 ALTER TABLE `notas_liberadas_caja` DISABLE KEYS */;
/*!40000 ALTER TABLE `notas_liberadas_caja` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notas_uso_interno`
--

DROP TABLE IF EXISTS `notas_uso_interno`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notas_uso_interno` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `documento_id` int(11) NOT NULL COMMENT 'FK → documentos_comerciales.id',
  `id_venta` int(11) NOT NULL COMMENT 'Correlativo de la nota (id_venta)',
  `monto_usd` decimal(20,2) NOT NULL DEFAULT 0.00,
  `monto_ves` decimal(20,2) NOT NULL DEFAULT 0.00,
  `concepto` varchar(255) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_documento` (`documento_id`),
  KEY `idx_fecha` (`fecha_registro`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Notas de uso interno — excluidas de reportes de venta';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notas_uso_interno`
--

LOCK TABLES `notas_uso_interno` WRITE;
/*!40000 ALTER TABLE `notas_uso_interno` DISABLE KEYS */;
/*!40000 ALTER TABLE `notas_uso_interno` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recibos_pago`
--

DROP TABLE IF EXISTS `recibos_pago`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recibos_pago` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `documento_id` int(11) NOT NULL,
  `metodo_pago_id` int(11) NOT NULL,
  `cuenta_id` int(11) DEFAULT NULL,
  `referencia_pago` varchar(100) DEFAULT NULL,
  `monto_moneda_original` decimal(20,2) NOT NULL,
  `tasa_cambio_pago` decimal(10,4) NOT NULL,
  `monto_convertido_ves` decimal(20,2) NOT NULL,
  `igtf_generado_ves` decimal(20,2) NOT NULL DEFAULT 0.00,
  `id_caja` int(11) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  `fecha_operacion` date DEFAULT NULL,
  `base_atribuida_usd` decimal(12,4) DEFAULT NULL COMMENT 'Fracción base imponible atribuida a este recibo',
  `iva_atribuido_usd` decimal(12,4) DEFAULT NULL COMMENT 'Fracción IVA causado atribuida a este recibo',
  `igtf_atribuido_usd` decimal(12,4) DEFAULT NULL COMMENT 'Fracción IGTF causado atribuida a este recibo',
  `pct_pago` decimal(7,4) DEFAULT NULL COMMENT '% del total del documento aportado por este recibo',
  `tipo_cobro` enum('BASE','IVA','IGTF','TOTAL') NOT NULL DEFAULT 'TOTAL' COMMENT 'Indica si este pago es por la base imponible, el IVA, el IGTF, o no clasificado',
  PRIMARY KEY (`id`),
  KEY `documento_id` (`documento_id`),
  KEY `metodo_pago_id` (`metodo_pago_id`),
  KEY `idx_rp_metodo_atrib` (`metodo_pago_id`,`base_atribuida_usd`),
  CONSTRAINT `recibos_pago_ibfk_1` FOREIGN KEY (`documento_id`) REFERENCES `documentos_comerciales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `recibos_pago_ibfk_2` FOREIGN KEY (`metodo_pago_id`) REFERENCES `metodos_pago` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recibos_pago`
--

LOCK TABLES `recibos_pago` WRITE;
/*!40000 ALTER TABLE `recibos_pago` DISABLE KEYS */;
/*!40000 ALTER TABLE `recibos_pago` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reporte_z`
--

DROP TABLE IF EXISTS `reporte_z`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reporte_z` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `fecha_registro` datetime DEFAULT NULL,
  `id_caja` int(11) DEFAULT NULL,
  `id_mf` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `n_reporte` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `subt_venta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `igtf_venta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `iva_venta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `total_venta` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sede` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reporte_z`
--

LOCK TABLES `reporte_z` WRITE;
/*!40000 ALTER TABLE `reporte_z` DISABLE KEYS */;
/*!40000 ALTER TABLE `reporte_z` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tasa_conversion`
--

DROP TABLE IF EXISTS `tasa_conversion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasa_conversion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `tasa` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `id_moneda` int(11) DEFAULT 2,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fecha` (`fecha`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tasa_conversion`
--

LOCK TABLES `tasa_conversion` WRITE;
/*!40000 ALTER TABLE `tasa_conversion` DISABLE KEYS */;
INSERT INTO `tasa_conversion` VALUES (1,'2026-04-08',549.7000,3),(2,'2026-04-09',556.5200,3),(3,'2026-04-10',556.6600,3),(4,'2026-04-11',560.0500,3),(5,'2026-04-13',560.0500,3),(6,'2026-04-14',559.2100,3),(7,'2026-04-15',564.9800,3),(8,'2026-04-16',565.9800,3),(9,'2026-04-17',565.4100,3),(10,'2026-04-18',568.5200,3),(11,'2026-04-20',568.5200,3),(12,'2026-04-21',567.5800,3),(13,'2026-04-22',567.7100,3),(14,'2026-04-23',567.0100,3),(15,'2026-04-24',566.8400,3),(16,'2026-04-25',567.4000,3),(17,'2026-04-27',567.4000,3),(18,'2026-04-28',569.3000,3),(19,'2026-04-29',569.4300,3),(20,'2026-05-01',574.1900,3),(21,'2026-05-05',574.0400,3),(22,'2026-05-06',577.5200,3),(23,'2026-05-08',588.1000,3),(24,'2026-05-11',589.2700,3),(25,'2026-05-12',595.0500,3),(26,'2026-05-13',596.6000,3),(27,'2026-05-19',602.1900,3),(28,'2026-05-28',633.4800,3);
/*!40000 ALTER TABLE `tasa_conversion` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-17 15:18:34
