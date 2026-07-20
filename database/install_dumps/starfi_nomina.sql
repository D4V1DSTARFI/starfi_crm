-- MariaDB dump 10.19  Distrib 10.4.25-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: starfi_nomina
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
-- Current Database: `starfi_nomina`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `starfi_nomina` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

USE `starfi_nomina`;

--
-- Table structure for table `archivo_asistencia`
--

DROP TABLE IF EXISTS `archivo_asistencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `archivo_asistencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `ruta` varchar(100) NOT NULL,
  `status` varchar(100) NOT NULL,
  `periodo` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `archivo_asistencia`
--

LOCK TABLES `archivo_asistencia` WRITE;
/*!40000 ALTER TABLE `archivo_asistencia` DISABLE KEYS */;
/*!40000 ALTER TABLE `archivo_asistencia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asistencia_configuracion_reglas`
--

DROP TABLE IF EXISTS `asistencia_configuracion_reglas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asistencia_configuracion_reglas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cedula` varchar(50) DEFAULT NULL COMMENT 'If NULL, applies to ALL. Si no, aplica a un solo empleado',
  `tipo_infraccion` varchar(100) NOT NULL COMMENT 'MARCAJE INCOMPLETO, SALIDA ANTICIPADA, etc',
  `accion_automatica` varchar(100) NOT NULL COMMENT 'PERDONAR, DESCONTAR_DIA, APLICAR_MULTA',
  `id_concepto_multa` int(11) DEFAULT NULL,
  `comentario` varchar(255) DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asistencia_configuracion_reglas`
--

LOCK TABLES `asistencia_configuracion_reglas` WRITE;
/*!40000 ALTER TABLE `asistencia_configuracion_reglas` DISABLE KEYS */;
/*!40000 ALTER TABLE `asistencia_configuracion_reglas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asistencia_excepciones`
--

DROP TABLE IF EXISTS `asistencia_excepciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `asistencia_excepciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_asistencia` int(11) NOT NULL COMMENT 'ID from historial_asistencia',
  `cedula` varchar(50) NOT NULL,
  `fecha` date NOT NULL,
  `tipo_excepcion` varchar(100) NOT NULL COMMENT 'JUSTIFICADO, PERDONADO, DEDUCCION',
  `id_concepto_deduccion` int(11) DEFAULT NULL COMMENT 'If type is DEDUCCION, which logic or concept rule applies',
  `comentario` text DEFAULT NULL,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_asistencia` (`id_asistencia`),
  KEY `cedula` (`cedula`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asistencia_excepciones`
--

LOCK TABLES `asistencia_excepciones` WRITE;
/*!40000 ALTER TABLE `asistencia_excepciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `asistencia_excepciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `autogestion_solicitudes`
--

DROP TABLE IF EXISTS `autogestion_solicitudes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `autogestion_solicitudes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `seccion` varchar(50) NOT NULL,
  `datos_json` longtext NOT NULL,
  `estatus` int(11) DEFAULT 0 COMMENT '0: Pendiente, 1: Aprobado, 2: Rechazado, 3: Cancelado',
  `motivo_rechazo` text DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `fecha_solicitud` datetime DEFAULT current_timestamp(),
  `fecha_resolucion` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `autogestion_solicitudes`
--

LOCK TABLES `autogestion_solicitudes` WRITE;
/*!40000 ALTER TABLE `autogestion_solicitudes` DISABLE KEYS */;
/*!40000 ALTER TABLE `autogestion_solicitudes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `base_calculo_pf`
--

DROP TABLE IF EXISTS `base_calculo_pf`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `base_calculo_pf` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `salario_mensual` float NOT NULL,
  `salario_semanal` float NOT NULL,
  `salario_diario` float NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `base_calculo_pf`
--

LOCK TABLES `base_calculo_pf` WRITE;
/*!40000 ALTER TABLE `base_calculo_pf` DISABLE KEYS */;
/*!40000 ALTER TABLE `base_calculo_pf` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `calendario_feriados`
--

DROP TABLE IF EXISTS `calendario_feriados`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calendario_feriados` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `recurrente` tinyint(1) DEFAULT 0 COMMENT '1 si se repite todos los años en el mismo mes y dia',
  `tipo` enum('FIJO_NACIONAL','CUSTOM_EMPRESA') DEFAULT 'CUSTOM_EMPRESA',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `calendario_feriados`
--

LOCK TABLES `calendario_feriados` WRITE;
/*!40000 ALTER TABLE `calendario_feriados` DISABLE KEYS */;
/*!40000 ALTER TABLE `calendario_feriados` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cargo`
--

DROP TABLE IF EXISTS `cargo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cargo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_sede` int(11) NOT NULL,
  `descripcion` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cargo`
--

LOCK TABLES `cargo` WRITE;
/*!40000 ALTER TABLE `cargo` DISABLE KEYS */;
/*!40000 ALTER TABLE `cargo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_comisiones_tipos`
--

DROP TABLE IF EXISTS `cat_comisiones_tipos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_comisiones_tipos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `tipo_origen` varchar(50) NOT NULL DEFAULT 'MANUAL',
  `id_referencia_origen` varchar(50) DEFAULT NULL COMMENT 'ID del producto o servicio en starfi_ventas',
  `valor_unitario` decimal(10,2) DEFAULT 0.00 COMMENT 'Valor a pagar por cada unidad vendida (si aplica)',
  `estatus` tinyint(1) NOT NULL DEFAULT 1,
  `participantes_habituales` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_comisiones_tipos`
--

LOCK TABLES `cat_comisiones_tipos` WRITE;
/*!40000 ALTER TABLE `cat_comisiones_tipos` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_comisiones_tipos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_comisiones_vendedores`
--

DROP TABLE IF EXISTS `cat_comisiones_vendedores`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_comisiones_vendedores` (
  `id_vendedor` int(11) NOT NULL,
  `aplica_comision` tinyint(1) DEFAULT 1,
  `porcentaje_comision` decimal(5,2) DEFAULT 0.50,
  `bono_por_articulo` decimal(5,2) DEFAULT 0.00,
  `bono_por_delivery` decimal(5,2) DEFAULT 0.00,
  PRIMARY KEY (`id_vendedor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_comisiones_vendedores`
--

LOCK TABLES `cat_comisiones_vendedores` WRITE;
/*!40000 ALTER TABLE `cat_comisiones_vendedores` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_comisiones_vendedores` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_concepto_nomina_tipo`
--

DROP TABLE IF EXISTS `cat_concepto_nomina_tipo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_concepto_nomina_tipo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_concepto_nomina_tipo`
--

LOCK TABLES `cat_concepto_nomina_tipo` WRITE;
/*!40000 ALTER TABLE `cat_concepto_nomina_tipo` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_concepto_nomina_tipo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_contacto_tipo`
--

DROP TABLE IF EXISTS `cat_contacto_tipo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_contacto_tipo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion_contacto` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_contacto_tipo`
--

LOCK TABLES `cat_contacto_tipo` WRITE;
/*!40000 ALTER TABLE `cat_contacto_tipo` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_contacto_tipo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_contrato_status`
--

DROP TABLE IF EXISTS `cat_contrato_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_contrato_status` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion_status` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_contrato_status`
--

LOCK TABLES `cat_contrato_status` WRITE;
/*!40000 ALTER TABLE `cat_contrato_status` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_contrato_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_contrato_tipo`
--

DROP TABLE IF EXISTS `cat_contrato_tipo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_contrato_tipo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion_contrato` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_contrato_tipo`
--

LOCK TABLES `cat_contrato_tipo` WRITE;
/*!40000 ALTER TABLE `cat_contrato_tipo` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_contrato_tipo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_estado_civil`
--

DROP TABLE IF EXISTS `cat_estado_civil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_estado_civil` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_estado_civil`
--

LOCK TABLES `cat_estado_civil` WRITE;
/*!40000 ALTER TABLE `cat_estado_civil` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_estado_civil` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_estado_laboral`
--

DROP TABLE IF EXISTS `cat_estado_laboral`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_estado_laboral` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_estado_laboral`
--

LOCK TABLES `cat_estado_laboral` WRITE;
/*!40000 ALTER TABLE `cat_estado_laboral` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_estado_laboral` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_genero`
--

DROP TABLE IF EXISTS `cat_genero`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_genero` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `genero_descripcion` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_genero`
--

LOCK TABLES `cat_genero` WRITE;
/*!40000 ALTER TABLE `cat_genero` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_genero` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_justificacion`
--

DROP TABLE IF EXISTS `cat_justificacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_justificacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(255) NOT NULL,
  `masiva` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_justificacion`
--

LOCK TABLES `cat_justificacion` WRITE;
/*!40000 ALTER TABLE `cat_justificacion` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_justificacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_nivel_educativo`
--

DROP TABLE IF EXISTS `cat_nivel_educativo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_nivel_educativo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_nivel_educativo`
--

LOCK TABLES `cat_nivel_educativo` WRITE;
/*!40000 ALTER TABLE `cat_nivel_educativo` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_nivel_educativo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_parentesco`
--

DROP TABLE IF EXISTS `cat_parentesco`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_parentesco` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_parentesco`
--

LOCK TABLES `cat_parentesco` WRITE;
/*!40000 ALTER TABLE `cat_parentesco` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_parentesco` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_reglas_comision`
--

DROP TABLE IF EXISTS `cat_reglas_comision`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_reglas_comision` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_vendedor` int(11) NOT NULL,
  `tipo_regla` varchar(50) NOT NULL COMMENT 'VENTAS, PEDIDOS, SERVICIOS, ARTICULO',
  `id_producto` int(11) DEFAULT NULL,
  `modo_calculo` varchar(20) NOT NULL DEFAULT 'PORCENTAJE' COMMENT 'PORCENTAJE, MONTO_FIJO',
  `valor` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_regla` (`id_vendedor`,`tipo_regla`,`id_producto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_reglas_comision`
--

LOCK TABLES `cat_reglas_comision` WRITE;
/*!40000 ALTER TABLE `cat_reglas_comision` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_reglas_comision` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_status_asistencia`
--

DROP TABLE IF EXISTS `cat_status_asistencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_status_asistencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_status_asistencia`
--

LOCK TABLES `cat_status_asistencia` WRITE;
/*!40000 ALTER TABLE `cat_status_asistencia` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_status_asistencia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cat_tipo_cuenta`
--

DROP TABLE IF EXISTS `cat_tipo_cuenta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cat_tipo_cuenta` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cat_tipo_cuenta`
--

LOCK TABLES `cat_tipo_cuenta` WRITE;
/*!40000 ALTER TABLE `cat_tipo_cuenta` DISABLE KEYS */;
/*!40000 ALTER TABLE `cat_tipo_cuenta` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comisiones_asignaciones`
--

DROP TABLE IF EXISTS `comisiones_asignaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comisiones_asignaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_tipo_comision` int(11) NOT NULL,
  `periodo_nomina` varchar(50) NOT NULL COMMENT 'Ej: 1QMAY26',
  `monto_total_generado` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cantidad_base` decimal(10,2) DEFAULT 0.00 COMMENT 'Cantidad de cortes/descargas encontradas en el periodo',
  `fecha_calculo` datetime NOT NULL DEFAULT current_timestamp(),
  `estatus` enum('BORRADOR','CERRADO','APLICADO') NOT NULL DEFAULT 'BORRADOR',
  PRIMARY KEY (`id`),
  KEY `idx_periodo` (`periodo_nomina`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comisiones_asignaciones`
--

LOCK TABLES `comisiones_asignaciones` WRITE;
/*!40000 ALTER TABLE `comisiones_asignaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `comisiones_asignaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comisiones_participantes`
--

DROP TABLE IF EXISTS `comisiones_participantes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comisiones_participantes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_asignacion` int(11) NOT NULL,
  `id_personal` int(11) NOT NULL,
  `monto_tajada` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_asignacion` (`id_asignacion`),
  KEY `idx_personal` (`id_personal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comisiones_participantes`
--

LOCK TABLES `comisiones_participantes` WRITE;
/*!40000 ALTER TABLE `comisiones_participantes` DISABLE KEYS */;
/*!40000 ALTER TABLE `comisiones_participantes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comisiones_transacciones_vinculadas`
--

DROP TABLE IF EXISTS `comisiones_transacciones_vinculadas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comisiones_transacciones_vinculadas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_asignacion` int(11) NOT NULL,
  `id_transaccion_kardex` varchar(100) NOT NULL,
  `tipo_transaccion` varchar(50) NOT NULL,
  `fecha_vinculacion` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_asignacion` (`id_asignacion`),
  KEY `idx_transaccion` (`id_transaccion_kardex`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comisiones_transacciones_vinculadas`
--

LOCK TABLES `comisiones_transacciones_vinculadas` WRITE;
/*!40000 ALTER TABLE `comisiones_transacciones_vinculadas` DISABLE KEYS */;
/*!40000 ALTER TABLE `comisiones_transacciones_vinculadas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracion_carnets`
--

DROP TABLE IF EXISTS `configuracion_carnets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `configuracion_carnets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_plantilla` varchar(100) NOT NULL,
  `canvas_json` longtext NOT NULL,
  `canvas_back_json` longtext DEFAULT NULL,
  `activa` tinyint(1) DEFAULT 0,
  `fecha_actualizacion` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_carnets`
--

LOCK TABLES `configuracion_carnets` WRITE;
/*!40000 ALTER TABLE `configuracion_carnets` DISABLE KEYS */;
/*!40000 ALTER TABLE `configuracion_carnets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `configuracion_nomina`
--

DROP TABLE IF EXISTS `configuracion_nomina`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `configuracion_nomina` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_sede` int(11) NOT NULL,
  `frecuencia_pago` enum('semanal','quincenal','mensual') NOT NULL,
  `dias_laborables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`dias_laborables`)),
  `dias_pago_habiles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`dias_pago_habiles`)),
  `inicio_semana` int(11) DEFAULT NULL COMMENT 'Para frecuencia semanal (e.g. 1=Lunes)',
  `quincena_corte_1` int(11) DEFAULT NULL,
  `quincena_corte_2` int(11) DEFAULT NULL,
  `regla_findesemana` enum('previo_habil','siguiente_habil','mismo_dia') NOT NULL,
  `moneda_referencia` varchar(50) DEFAULT 'tasa_dolar',
  `multiplo_billetes_usd` int(11) DEFAULT 1,
  `fecha_ultima_config` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tarifa_anticipo_dia` decimal(10,2) NOT NULL DEFAULT 4.50 COMMENT 'Tarifa diaria en USD para calculo de anticipos',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_sede` (`id_sede`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `configuracion_nomina`
--

LOCK TABLES `configuracion_nomina` WRITE;
/*!40000 ALTER TABLE `configuracion_nomina` DISABLE KEYS */;
/*!40000 ALTER TABLE `configuracion_nomina` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contrato`
--

DROP TABLE IF EXISTS `contrato`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contrato` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `id_departamento` int(11) DEFAULT NULL,
  `cargo` varchar(100) NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date DEFAULT NULL,
  `salario_base` float NOT NULL,
  `tiket` float NOT NULL,
  `tipo_contrato` int(11) NOT NULL,
  `status` int(11) NOT NULL,
  `id_sede` int(11) NOT NULL,
  `comentario` varchar(200) DEFAULT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  `fecha_baja` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contrato`
--

LOCK TABLES `contrato` WRITE;
/*!40000 ALTER TABLE `contrato` DISABLE KEYS */;
/*!40000 ALTER TABLE `contrato` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contrato_historial`
--

DROP TABLE IF EXISTS `contrato_historial`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `contrato_historial` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_contrato` int(11) NOT NULL,
  `id_sede` int(11) NOT NULL,
  `id_cargo` int(11) NOT NULL,
  `salario_base` decimal(15,2) DEFAULT 0.00,
  `cestaticket` decimal(15,2) DEFAULT 0.00,
  `tipo_contrato` int(11) DEFAULT 1,
  `fecha_fin` date DEFAULT NULL,
  `motivo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT 'CONTRATO ORIGINAL',
  `fecha_efectiva` date NOT NULL,
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contrato_historial`
--

LOCK TABLES `contrato_historial` WRITE;
/*!40000 ALTER TABLE `contrato_historial` DISABLE KEYS */;
/*!40000 ALTER TABLE `contrato_historial` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `control_nomina`
--

DROP TABLE IF EXISTS `control_nomina`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `control_nomina` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha_calculo` date NOT NULL,
  `periodo` varchar(50) NOT NULL,
  `status` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `control_nomina`
--

LOCK TABLES `control_nomina` WRITE;
/*!40000 ALTER TABLE `control_nomina` DISABLE KEYS */;
/*!40000 ALTER TABLE `control_nomina` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `correo_enviado`
--

DROP TABLE IF EXISTS `correo_enviado`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `correo_enviado` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `periodo` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `respuesta` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `correo_enviado`
--

LOCK TABLES `correo_enviado` WRITE;
/*!40000 ALTER TABLE `correo_enviado` DISABLE KEYS */;
/*!40000 ALTER TABLE `correo_enviado` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `correo_enviado_amonestacion`
--

DROP TABLE IF EXISTS `correo_enviado_amonestacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `correo_enviado_amonestacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `periodo` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `respuesta` varchar(200) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `correo_enviado_amonestacion`
--

LOCK TABLES `correo_enviado_amonestacion` WRITE;
/*!40000 ALTER TABLE `correo_enviado_amonestacion` DISABLE KEYS */;
/*!40000 ALTER TABLE `correo_enviado_amonestacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `delivery`
--

DROP TABLE IF EXISTS `delivery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `delivery` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `nota` int(11) NOT NULL,
  `monto` float NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `comision` float NOT NULL,
  `cliente` int(11) NOT NULL,
  `vendedor` int(11) NOT NULL,
  `sede` int(11) NOT NULL,
  `codigo` varchar(100) NOT NULL,
  `metodo` varchar(10) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `delivery`
--

LOCK TABLES `delivery` WRITE;
/*!40000 ALTER TABLE `delivery` DISABLE KEYS */;
/*!40000 ALTER TABLE `delivery` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departamento`
--

DROP TABLE IF EXISTS `departamento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_sede` int(11) NOT NULL,
  `descripcion` varchar(200) NOT NULL,
  `id_jefe` int(11) DEFAULT NULL,
  `estado` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departamento`
--

LOCK TABLES `departamento` WRITE;
/*!40000 ALTER TABLE `departamento` DISABLE KEYS */;
/*!40000 ALTER TABLE `departamento` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `documento_amonestacion`
--

DROP TABLE IF EXISTS `documento_amonestacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documento_amonestacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `periodo` varchar(100) NOT NULL,
  `nombre_documento` varchar(200) NOT NULL,
  `tipo` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `documento_amonestacion`
--

LOCK TABLES `documento_amonestacion` WRITE;
/*!40000 ALTER TABLE `documento_amonestacion` DISABLE KEYS */;
/*!40000 ALTER TABLE `documento_amonestacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado`
--

DROP TABLE IF EXISTS `empleado`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `nacionalidad` varchar(50) DEFAULT NULL,
  `documento_tipo` varchar(20) DEFAULT NULL,
  `cedula` varchar(50) DEFAULT NULL,
  `lugar_nacimiento` varchar(100) DEFAULT NULL,
  `fecha_nacimiento` date DEFAULT NULL,
  `edad` int(11) DEFAULT NULL,
  `estado_civil` varchar(50) DEFAULT NULL,
  `genero` int(11) NOT NULL,
  `direccion_habitacion` varchar(255) DEFAULT NULL,
  `ciudad` int(11) DEFAULT NULL,
  `estado` int(11) DEFAULT NULL,
  `id_sede` int(11) DEFAULT NULL,
  `estado_laboral` int(11) DEFAULT 1,
  `foto_carnet` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cedula` (`cedula`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado`
--

LOCK TABLES `empleado` WRITE;
/*!40000 ALTER TABLE `empleado` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_antropometrico`
--

DROP TABLE IF EXISTS `empleado_antropometrico`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_antropometrico` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `talla_camisa` varchar(20) DEFAULT NULL,
  `talla_pantalon` varchar(20) DEFAULT NULL,
  `talla_zapato` varchar(20) DEFAULT NULL,
  `empleado_altura` float DEFAULT NULL,
  `empleado_peso` float DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_antropometrico`
--

LOCK TABLES `empleado_antropometrico` WRITE;
/*!40000 ALTER TABLE `empleado_antropometrico` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_antropometrico` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_bancarizacion`
--

DROP TABLE IF EXISTS `empleado_bancarizacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_bancarizacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `banco` varchar(100) NOT NULL,
  `numero_cuenta` varchar(50) NOT NULL,
  `tipo_cuenta` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_bancarizacion`
--

LOCK TABLES `empleado_bancarizacion` WRITE;
/*!40000 ALTER TABLE `empleado_bancarizacion` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_bancarizacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_borrador`
--

DROP TABLE IF EXISTS `empleado_borrador`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_borrador` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_usuario` int(11) NOT NULL,
  `titulo_borrador` varchar(150) DEFAULT NULL,
  `datos_json` longtext DEFAULT NULL,
  `fecha_guardado` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_usuario` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_borrador`
--

LOCK TABLES `empleado_borrador` WRITE;
/*!40000 ALTER TABLE `empleado_borrador` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_borrador` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_carga_familiar`
--

DROP TABLE IF EXISTS `empleado_carga_familiar`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_carga_familiar` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `parentesco` varchar(50) NOT NULL,
  `familiar_nombre` varchar(100) NOT NULL,
  `familiar_nacionalidad` varchar(50) DEFAULT NULL,
  `familiar_tipo_doc` varchar(20) DEFAULT NULL,
  `familiar_cedula` varchar(50) DEFAULT NULL,
  `familiar_fecha_nacimiento` date DEFAULT NULL,
  `familiar_ocupacion` varchar(100) DEFAULT NULL,
  `dependiente` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_carga_familiar`
--

LOCK TABLES `empleado_carga_familiar` WRITE;
/*!40000 ALTER TABLE `empleado_carga_familiar` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_carga_familiar` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_contacto`
--

DROP TABLE IF EXISTS `empleado_contacto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_contacto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `tipo_contacto` varchar(50) NOT NULL,
  `descripcion_contacto` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_contacto`
--

LOCK TABLES `empleado_contacto` WRITE;
/*!40000 ALTER TABLE `empleado_contacto` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_contacto` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_curso`
--

DROP TABLE IF EXISTS `empleado_curso`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_curso` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `denominacion` varchar(150) NOT NULL,
  `fecha` date DEFAULT NULL,
  `institucion` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_curso`
--

LOCK TABLES `empleado_curso` WRITE;
/*!40000 ALTER TABLE `empleado_curso` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_curso` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_departamento`
--

DROP TABLE IF EXISTS `empleado_departamento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_departamento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `id_departamento` int(11) NOT NULL,
  `id_sede` int(11) NOT NULL,
  `fecha_asignacion` date NOT NULL,
  `status` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_departamento`
--

LOCK TABLES `empleado_departamento` WRITE;
/*!40000 ALTER TABLE `empleado_departamento` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_departamento` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_documento`
--

DROP TABLE IF EXISTS `empleado_documento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_documento` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `nombre_documento` varchar(100) NOT NULL,
  `ruta_archivo` varchar(255) NOT NULL,
  `tipo_archivo` varchar(50) DEFAULT 'application/pdf',
  `peso_kb` int(11) DEFAULT 0,
  `fecha_subida` datetime DEFAULT current_timestamp(),
  `status` tinyint(4) DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_documento`
--

LOCK TABLES `empleado_documento` WRITE;
/*!40000 ALTER TABLE `empleado_documento` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_documento` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_educacion`
--

DROP TABLE IF EXISTS `empleado_educacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_educacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `nivel_instruccion` varchar(100) DEFAULT NULL,
  `anos_cursados` int(11) DEFAULT 0,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `a_cursados` varchar(50) DEFAULT NULL,
  `titulo_obtenido` varchar(150) DEFAULT NULL,
  `centro_ensenanza` varchar(150) DEFAULT NULL,
  `lugar` varchar(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_educacion`
--

LOCK TABLES `empleado_educacion` WRITE;
/*!40000 ALTER TABLE `empleado_educacion` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_educacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_experiencia`
--

DROP TABLE IF EXISTS `empleado_experiencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_experiencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `empresa` varchar(150) DEFAULT NULL,
  `empresa_telefono` varchar(50) DEFAULT NULL,
  `lugar` varchar(150) DEFAULT NULL,
  `fecha_ingreso` date DEFAULT NULL,
  `fecha_egreso` date DEFAULT NULL,
  `ultimo_cargo` varchar(100) DEFAULT NULL,
  `ultima_remuneracion` float DEFAULT NULL,
  `nombre_supervisor_inm` varchar(150) DEFAULT NULL,
  `cargo_supervisor_inm` varchar(100) DEFAULT NULL,
  `desc_funciones` text DEFAULT NULL,
  `motivo_retiro` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_experiencia`
--

LOCK TABLES `empleado_experiencia` WRITE;
/*!40000 ALTER TABLE `empleado_experiencia` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_experiencia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_foto_perfil`
--

DROP TABLE IF EXISTS `empleado_foto_perfil`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_foto_perfil` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `ruta` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_foto_perfil`
--

LOCK TABLES `empleado_foto_perfil` WRITE;
/*!40000 ALTER TABLE `empleado_foto_perfil` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_foto_perfil` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `empleado_ref_personal`
--

DROP TABLE IF EXISTS `empleado_ref_personal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `empleado_ref_personal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `nombre` varchar(150) NOT NULL,
  `parentesco` varchar(50) DEFAULT NULL,
  `ref_telefono` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `empleado_ref_personal`
--

LOCK TABLES `empleado_ref_personal` WRITE;
/*!40000 ALTER TABLE `empleado_ref_personal` DISABLE KEYS */;
/*!40000 ALTER TABLE `empleado_ref_personal` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `entes_pf`
--

DROP TABLE IF EXISTS `entes_pf`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `entes_pf` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ente` varchar(100) NOT NULL,
  `aporte_empleado` float NOT NULL,
  `aporte_patrono` float NOT NULL,
  `total_aporte` float NOT NULL,
  `referencia` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `entes_pf`
--

LOCK TABLES `entes_pf` WRITE;
/*!40000 ALTER TABLE `entes_pf` DISABLE KEYS */;
/*!40000 ALTER TABLE `entes_pf` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expediente_documentacion`
--

DROP TABLE IF EXISTS `expediente_documentacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expediente_documentacion` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `id_personal` int(11) NOT NULL,
  `tipo_documento` varchar(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `extension` varchar(20) NOT NULL,
  `tamano` varchar(20) NOT NULL,
  `token` varchar(500) NOT NULL,
  `status` varchar(100) NOT NULL,
  `sede` int(11) NOT NULL,
  `observacion` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expediente_documentacion`
--

LOCK TABLES `expediente_documentacion` WRITE;
/*!40000 ALTER TABLE `expediente_documentacion` DISABLE KEYS */;
/*!40000 ALTER TABLE `expediente_documentacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grupo_pf`
--

DROP TABLE IF EXISTS `grupo_pf`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grupo_pf` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grupo_pf`
--

LOCK TABLES `grupo_pf` WRITE;
/*!40000 ALTER TABLE `grupo_pf` DISABLE KEYS */;
/*!40000 ALTER TABLE `grupo_pf` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_anticipo`
--

DROP TABLE IF EXISTS `historial_anticipo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_anticipo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `periodo` varchar(100) NOT NULL,
  `fecha` date NOT NULL,
  `monto` float NOT NULL,
  `observacion` varchar(100) NOT NULL,
  `status` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_anticipo`
--

LOCK TABLES `historial_anticipo` WRITE;
/*!40000 ALTER TABLE `historial_anticipo` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_anticipo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_asistencia`
--

DROP TABLE IF EXISTS `historial_asistencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_asistencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cedula` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `h_ingreso` time NOT NULL,
  `h_egreso` time NOT NULL,
  `f_reg` date NOT NULL,
  `id_status_entrada` int(11) DEFAULT NULL,
  `id_status_salida` int(11) DEFAULT NULL,
  `periodo` varchar(100) NOT NULL,
  `jornada` float NOT NULL,
  `horas_extras` float NOT NULL,
  `id_subida` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_asistencia`
--

LOCK TABLES `historial_asistencia` WRITE;
/*!40000 ALTER TABLE `historial_asistencia` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_asistencia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_bono_vacacional`
--

DROP TABLE IF EXISTS `historial_bono_vacacional`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_bono_vacacional` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  `id_historial_vacaciones` int(11) NOT NULL,
  `salario_base` decimal(15,2) NOT NULL DEFAULT 0.00,
  `dias_bono` int(11) NOT NULL DEFAULT 15,
  `salario_diario_normal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `alicuota_vacacional` decimal(15,2) NOT NULL DEFAULT 0.00,
  `alicuota_utilidades` decimal(15,2) NOT NULL DEFAULT 0.00,
  `salario_integral_diario` decimal(15,2) NOT NULL DEFAULT 0.00,
  `monto_total_bono` decimal(15,2) NOT NULL DEFAULT 0.00,
  `base_prestaciones_mensual` decimal(15,2) NOT NULL DEFAULT 0.00,
  `estatus` varchar(50) NOT NULL DEFAULT 'PENDIENTE',
  `fecha_registro` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_personal_bono` (`id_personal`),
  KEY `fk_historial_vac` (`id_historial_vacaciones`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_bono_vacacional`
--

LOCK TABLES `historial_bono_vacacional` WRITE;
/*!40000 ALTER TABLE `historial_bono_vacacional` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_bono_vacacional` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_comisiones`
--

DROP TABLE IF EXISTS `historial_comisiones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_comisiones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_asignacion` int(11) DEFAULT NULL,
  `id_personal` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `periodo` varchar(100) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `monto` float NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_comisiones`
--

LOCK TABLES `historial_comisiones` WRITE;
/*!40000 ALTER TABLE `historial_comisiones` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_comisiones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_feriado`
--

DROP TABLE IF EXISTS `historial_feriado`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_feriado` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `periodo` varchar(200) NOT NULL,
  `id_personal` int(11) NOT NULL,
  `cantidad` float NOT NULL,
  `sede` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_feriado`
--

LOCK TABLES `historial_feriado` WRITE;
/*!40000 ALTER TABLE `historial_feriado` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_feriado` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_nomina`
--

DROP TABLE IF EXISTS `historial_nomina`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_nomina` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `periodo` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `id_personal` int(11) DEFAULT NULL,
  `elemento` varchar(200) COLLATE utf8_spanish_ci NOT NULL,
  `tipo_elemento` varchar(100) COLLATE utf8_spanish_ci NOT NULL,
  `cantidad` float NOT NULL,
  `monto` float NOT NULL,
  `total` float NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_nomina`
--

LOCK TABLES `historial_nomina` WRITE;
/*!40000 ALTER TABLE `historial_nomina` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_nomina` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_nomina_bs`
--

DROP TABLE IF EXISTS `historial_nomina_bs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_nomina_bs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `periodo` varchar(100) NOT NULL,
  `fecha` date NOT NULL,
  `monto` float NOT NULL,
  `monot_usd` float NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_nomina_bs`
--

LOCK TABLES `historial_nomina_bs` WRITE;
/*!40000 ALTER TABLE `historial_nomina_bs` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_nomina_bs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_prestamo`
--

DROP TABLE IF EXISTS `historial_prestamo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_prestamo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` varchar(50) DEFAULT NULL,
  `fecha_solicitud` date NOT NULL,
  `monto_total` decimal(15,2) NOT NULL,
  `cuotas_totales` int(11) NOT NULL DEFAULT 1,
  `cuotas_pagadas` int(11) NOT NULL DEFAULT 0,
  `frecuencia_pago` enum('QUINCENAL','SEMANAL','MENSUAL') DEFAULT 'QUINCENAL',
  `motivo` text DEFAULT NULL,
  `status` enum('PENDIENTE','APROBADO','RECHAZADO','ACTIVO','PAGADO') DEFAULT 'PENDIENTE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_prestamo`
--

LOCK TABLES `historial_prestamo` WRITE;
/*!40000 ALTER TABLE `historial_prestamo` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_prestamo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_prestamo_legacy`
--

DROP TABLE IF EXISTS `historial_prestamo_legacy`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_prestamo_legacy` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  `periodo` varchar(100) NOT NULL,
  `fecha` date NOT NULL,
  `monto` float NOT NULL,
  `cuotas` int(11) NOT NULL,
  `monto_cuota` float NOT NULL,
  `observacion` varchar(100) NOT NULL,
  `status` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_prestamo_legacy`
--

LOCK TABLES `historial_prestamo_legacy` WRITE;
/*!40000 ALTER TABLE `historial_prestamo_legacy` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_prestamo_legacy` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_prestamo_pagos`
--

DROP TABLE IF EXISTS `historial_prestamo_pagos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_prestamo_pagos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_prestamo` int(11) NOT NULL,
  `monto` decimal(15,2) NOT NULL,
  `numero_cuota` int(11) DEFAULT NULL,
  `fecha_pago` date NOT NULL,
  `metodo` varchar(100) DEFAULT NULL,
  `observacion` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_prestamo_pagos_modern` (`id_prestamo`),
  CONSTRAINT `fk_prestamo_pagos_modern` FOREIGN KEY (`id_prestamo`) REFERENCES `historial_prestamo` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_prestamo_pagos`
--

LOCK TABLES `historial_prestamo_pagos` WRITE;
/*!40000 ALTER TABLE `historial_prestamo_pagos` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_prestamo_pagos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_resto_nomina`
--

DROP TABLE IF EXISTS `historial_resto_nomina`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_resto_nomina` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `periodo` varchar(100) COLLATE utf8_spanish2_ci NOT NULL,
  `id_personal` int(11) DEFAULT NULL,
  `monto` float NOT NULL,
  `tipo` varchar(100) COLLATE utf8_spanish2_ci NOT NULL,
  `status` varchar(50) COLLATE utf8_spanish2_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_resto_nomina`
--

LOCK TABLES `historial_resto_nomina` WRITE;
/*!40000 ALTER TABLE `historial_resto_nomina` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_resto_nomina` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_subidas_asistencia`
--

DROP TABLE IF EXISTS `historial_subidas_asistencia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_subidas_asistencia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nombre_archivo` varchar(255) NOT NULL,
  `fecha_periodo` date NOT NULL,
  `descripcion` text DEFAULT NULL,
  `f_reg` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_subidas_asistencia`
--

LOCK TABLES `historial_subidas_asistencia` WRITE;
/*!40000 ALTER TABLE `historial_subidas_asistencia` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_subidas_asistencia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `historial_vacaciones`
--

DROP TABLE IF EXISTS `historial_vacaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `historial_vacaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `anio_correspondiente` int(11) NOT NULL COMMENT 'El año de servicio al que corresponden',
  `fecha_teorica` date NOT NULL COMMENT 'Aniversario del contrato',
  `fecha_inicio_real` date NOT NULL COMMENT 'Fecha acordada',
  `fecha_fin_real` date NOT NULL COMMENT 'Fecha calculada de fin saltando fines de semana y feriados',
  `dias_habiles_disfrute` int(11) NOT NULL,
  `estatus` enum('PROGRAMADO','EN_CURSO','CULMINADO') DEFAULT 'PROGRAMADO',
  `tipo_inicio` enum('TEORICO','NEGOCIADO') NOT NULL DEFAULT 'TEORICO',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `id_empleado` (`id_personal`),
  CONSTRAINT `historial_vacaciones_ibfk_1` FOREIGN KEY (`id_personal`) REFERENCES `empleado` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `historial_vacaciones`
--

LOCK TABLES `historial_vacaciones` WRITE;
/*!40000 ALTER TABLE `historial_vacaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `historial_vacaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `interes_bcv`
--

DROP TABLE IF EXISTS `interes_bcv`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `interes_bcv` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ano` int(11) NOT NULL,
  `mes` int(11) NOT NULL,
  `promedio` float NOT NULL,
  `activa` float NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `interes_bcv`
--

LOCK TABLES `interes_bcv` WRITE;
/*!40000 ALTER TABLE `interes_bcv` DISABLE KEYS */;
/*!40000 ALTER TABLE `interes_bcv` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nomina_ari`
--

DROP TABLE IF EXISTS `nomina_ari`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_ari` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `porcentaje_retencion` float NOT NULL,
  `fecha_desde` date DEFAULT NULL,
  `fecha_hasta` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ari_personal` (`id_personal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nomina_ari`
--

LOCK TABLES `nomina_ari` WRITE;
/*!40000 ALTER TABLE `nomina_ari` DISABLE KEYS */;
/*!40000 ALTER TABLE `nomina_ari` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nomina_ari_detalles`
--

DROP TABLE IF EXISTS `nomina_ari_detalles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_ari_detalles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  `ingresos_proyectados` decimal(20,2) NOT NULL DEFAULT 0.00,
  `ingresos_extra` decimal(20,2) NOT NULL DEFAULT 0.00,
  `tipo_desgravamen` enum('unico','detallado') COLLATE utf8mb4_spanish_ci NOT NULL DEFAULT 'unico',
  `gastos_educacion` decimal(20,2) NOT NULL DEFAULT 0.00,
  `gastos_hcm` decimal(20,2) NOT NULL DEFAULT 0.00,
  `gastos_medicos` decimal(20,2) NOT NULL DEFAULT 0.00,
  `gastos_vivienda` decimal(20,2) NOT NULL DEFAULT 0.00,
  `cargas_familiares` int(11) NOT NULL DEFAULT 0,
  `impuestos_retenidos_demas` decimal(20,2) NOT NULL DEFAULT 0.00,
  `fecha_actualizacion` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_personal` (`id_personal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nomina_ari_detalles`
--

LOCK TABLES `nomina_ari_detalles` WRITE;
/*!40000 ALTER TABLE `nomina_ari_detalles` DISABLE KEYS */;
/*!40000 ALTER TABLE `nomina_ari_detalles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nomina_concepto_asignacion`
--

DROP TABLE IF EXISTS `nomina_concepto_asignacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_concepto_asignacion` (
  `id_asignacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_concepto` int(11) NOT NULL,
  `tipo_objetivo` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `id_objetivo` varchar(50) COLLATE utf8_spanish_ci DEFAULT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `estado` int(11) DEFAULT 1,
  `fecha_registro` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id_asignacion`),
  KEY `fk_concepto_asignacion` (`id_concepto`),
  CONSTRAINT `fk_concepto_asignacion` FOREIGN KEY (`id_concepto`) REFERENCES `nomina_conceptos` (`id_concepto`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nomina_concepto_asignacion`
--

LOCK TABLES `nomina_concepto_asignacion` WRITE;
/*!40000 ALTER TABLE `nomina_concepto_asignacion` DISABLE KEYS */;
/*!40000 ALTER TABLE `nomina_concepto_asignacion` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nomina_conceptos`
--

DROP TABLE IF EXISTS `nomina_conceptos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_conceptos` (
  `id_concepto` int(11) NOT NULL AUTO_INCREMENT,
  `tipo` enum('asignacion','deduccion') COLLATE utf8_spanish_ci NOT NULL,
  `nombre` varchar(100) COLLATE utf8_spanish_ci NOT NULL,
  `moneda` enum('bs','usd') COLLATE utf8_spanish_ci NOT NULL DEFAULT 'bs',
  `monto_fijo` decimal(15,2) DEFAULT 0.00,
  `es_porcentaje` tinyint(1) DEFAULT 0,
  `valor_porcentaje` decimal(10,2) DEFAULT NULL,
  `base_calculo` varchar(50) COLLATE utf8_spanish_ci DEFAULT NULL,
  `frecuencia_pago` enum('semanal','quincenal','mensual','unico') COLLATE utf8_spanish_ci DEFAULT 'quincenal',
  `incidencia_salarial` tinyint(1) DEFAULT 0,
  `estatus` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `usa_asistencia` tinyint(1) DEFAULT 0,
  `sincronizacion_asistencia` enum('entrada','salida','horas_extras') COLLATE utf8_spanish_ci DEFAULT NULL,
  PRIMARY KEY (`id_concepto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nomina_conceptos`
--

LOCK TABLES `nomina_conceptos` WRITE;
/*!40000 ALTER TABLE `nomina_conceptos` DISABLE KEYS */;
/*!40000 ALTER TABLE `nomina_conceptos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nomina_empleado_turnos`
--

DROP TABLE IF EXISTS `nomina_empleado_turnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_empleado_turnos` (
  `id_asignacion` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `id_turno` int(11) NOT NULL,
  `hora_entrada_especifica` time DEFAULT NULL,
  `fecha_asignacion` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_asignacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nomina_empleado_turnos`
--

LOCK TABLES `nomina_empleado_turnos` WRITE;
/*!40000 ALTER TABLE `nomina_empleado_turnos` DISABLE KEYS */;
/*!40000 ALTER TABLE `nomina_empleado_turnos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nomina_liquidaciones`
--

DROP TABLE IF EXISTS `nomina_liquidaciones`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_liquidaciones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  `fecha_egreso` date NOT NULL,
  `fecha_inicio_calculo` date DEFAULT NULL,
  `motivo` varchar(100) NOT NULL,
  `salario_base` decimal(15,2) NOT NULL DEFAULT 0.00,
  `salario_integral` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tasa_cambio` decimal(15,2) NOT NULL DEFAULT 0.00,
  `antiguedad_anos` int(11) NOT NULL DEFAULT 0,
  `antiguedad_meses` int(11) NOT NULL DEFAULT 0,
  `antiguedad_dias` int(11) NOT NULL DEFAULT 0,
  `prestaciones_acumuladas` decimal(15,2) NOT NULL DEFAULT 0.00,
  `intereses_acumulados` decimal(15,2) NOT NULL DEFAULT 0.00,
  `asignaciones_json` text DEFAULT NULL,
  `deducciones_json` text DEFAULT NULL,
  `neto_pagar` decimal(15,2) NOT NULL DEFAULT 0.00,
  `status` enum('Borrador','Pendiente Caja','Pagado') NOT NULL DEFAULT 'Borrador',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tipo_liquidacion` varchar(50) DEFAULT 'Definitiva',
  `metodo_pago_info` varchar(100) DEFAULT NULL,
  `moneda_pago_info` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_id_personal` (`id_personal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nomina_liquidaciones`
--

LOCK TABLES `nomina_liquidaciones` WRITE;
/*!40000 ALTER TABLE `nomina_liquidaciones` DISABLE KEYS */;
/*!40000 ALTER TABLE `nomina_liquidaciones` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nomina_lote_empleado_status`
--

DROP TABLE IF EXISTS `nomina_lote_empleado_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_lote_empleado_status` (
  `id_lote` int(11) NOT NULL,
  `id_personal` int(11) NOT NULL,
  `estatus` enum('abierto','concretado') DEFAULT 'abierto',
  `data_resumen` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_resumen`)),
  PRIMARY KEY (`id_lote`,`id_personal`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nomina_lote_empleado_status`
--

LOCK TABLES `nomina_lote_empleado_status` WRITE;
/*!40000 ALTER TABLE `nomina_lote_empleado_status` DISABLE KEYS */;
/*!40000 ALTER TABLE `nomina_lote_empleado_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `nomina_lotes`
--

DROP TABLE IF EXISTS `nomina_lotes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nomina_lotes` (
  `id_lote` int(11) NOT NULL AUTO_INCREMENT,
  `codigo_periodo` varchar(50) DEFAULT NULL,
  `frecuencia` enum('semanal','quincenal','mensual') NOT NULL,
  `tipo_nomina` enum('regular','vacaciones','liquidacion','extraordinaria') NOT NULL DEFAULT 'regular',
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `fecha_pago` date NOT NULL,
  `estatus` enum('borrador','aprobado','pagado') NOT NULL DEFAULT 'borrador',
  `aplica_asistencia` tinyint(1) DEFAULT 0 COMMENT 'Si se activó cruzar con inasistencias/retardos',
  `nota` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_lote`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `nomina_lotes`
--

LOCK TABLES `nomina_lotes` WRITE;
/*!40000 ALTER TABLE `nomina_lotes` DISABLE KEYS */;
/*!40000 ALTER TABLE `nomina_lotes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `periodos_nomina`
--

DROP TABLE IF EXISTS `periodos_nomina`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `periodos_nomina` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_sede` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL COMMENT 'Ej: 1SEMENERO26',
  `frecuencia` enum('semanal','quincenal','mensual') NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `fecha_pago_efectiva` date NOT NULL,
  `estado` enum('abierto','procesando','cerrado') DEFAULT 'abierto',
  `fecha_creacion` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_codigo_sede` (`id_sede`,`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `periodos_nomina`
--

LOCK TABLES `periodos_nomina` WRITE;
/*!40000 ALTER TABLE `periodos_nomina` DISABLE KEYS */;
/*!40000 ALTER TABLE `periodos_nomina` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_pago_prestamo`
--

DROP TABLE IF EXISTS `personal_pago_prestamo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_pago_prestamo` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  `id_prestamo` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `monto` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_pago_prestamo`
--

LOCK TABLES `personal_pago_prestamo` WRITE;
/*!40000 ALTER TABLE `personal_pago_prestamo` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_pago_prestamo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_turno`
--

DROP TABLE IF EXISTS `personal_turno`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_turno` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  `id_turno` int(11) NOT NULL,
  `id_validador` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_turno`
--

LOCK TABLES `personal_turno` WRITE;
/*!40000 ALTER TABLE `personal_turno` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_turno` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_carnet`
--

DROP TABLE IF EXISTS `qr_carnet`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_carnet` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  `ruta` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_carnet`
--

LOCK TABLES `qr_carnet` WRITE;
/*!40000 ALTER TABLE `qr_carnet` DISABLE KEYS */;
/*!40000 ALTER TABLE `qr_carnet` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `relacion_personal_cortes`
--

DROP TABLE IF EXISTS `relacion_personal_cortes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `relacion_personal_cortes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `relacion_personal_cortes`
--

LOCK TABLES `relacion_personal_cortes` WRITE;
/*!40000 ALTER TABLE `relacion_personal_cortes` DISABLE KEYS */;
/*!40000 ALTER TABLE `relacion_personal_cortes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `relacion_personal_pf`
--

DROP TABLE IF EXISTS `relacion_personal_pf`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `relacion_personal_pf` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_personal` int(11) DEFAULT NULL,
  `id_base` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `relacion_personal_pf`
--

LOCK TABLES `relacion_personal_pf` WRITE;
/*!40000 ALTER TABLE `relacion_personal_pf` DISABLE KEYS */;
/*!40000 ALTER TABLE `relacion_personal_pf` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `renuncia`
--

DROP TABLE IF EXISTS `renuncia`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `renuncia` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cedula` int(11) NOT NULL,
  `fecha_renuncia` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `motivo` varchar(500) NOT NULL,
  `control` varchar(50) NOT NULL,
  `status` varchar(50) NOT NULL,
  `observacion` varchar(500) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `renuncia`
--

LOCK TABLES `renuncia` WRITE;
/*!40000 ALTER TABLE `renuncia` DISABLE KEYS */;
/*!40000 ALTER TABLE `renuncia` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `reserva_nomina`
--

DROP TABLE IF EXISTS `reserva_nomina`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reserva_nomina` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `periodo` varchar(100) COLLATE utf8_spanish2_ci NOT NULL,
  `caja` int(11) NOT NULL,
  `monto` decimal(14,2) NOT NULL DEFAULT 0.00,
  `tipo` varchar(100) COLLATE utf8_spanish2_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `reserva_nomina`
--

LOCK TABLES `reserva_nomina` WRITE;
/*!40000 ALTER TABLE `reserva_nomina` DISABLE KEYS */;
/*!40000 ALTER TABLE `reserva_nomina` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ret_parafiscal_bs`
--

DROP TABLE IF EXISTS `ret_parafiscal_bs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ret_parafiscal_bs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fecha` date NOT NULL,
  `periodo` varchar(50) NOT NULL,
  `id_personal` int(11) NOT NULL,
  `sso` decimal(14,2) NOT NULL,
  `faov` decimal(14,2) NOT NULL,
  `inces` decimal(14,2) NOT NULL,
  `islr` decimal(14,2) NOT NULL,
  `rpe` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ret_parafiscal_bs`
--

LOCK TABLES `ret_parafiscal_bs` WRITE;
/*!40000 ALTER TABLE `ret_parafiscal_bs` DISABLE KEYS */;
/*!40000 ALTER TABLE `ret_parafiscal_bs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `temp_anticipo`
--

DROP TABLE IF EXISTS `temp_anticipo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `temp_anticipo` (
  `id` int(11) NOT NULL,
  `id_personal` int(11) NOT NULL,
  `periodo` varchar(100) COLLATE utf8_spanish2_ci NOT NULL,
  `fecha` date NOT NULL,
  `monto` float NOT NULL,
  `observacion` varchar(100) COLLATE utf8_spanish2_ci NOT NULL,
  `status` varchar(50) COLLATE utf8_spanish2_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `temp_anticipo`
--

LOCK TABLES `temp_anticipo` WRITE;
/*!40000 ALTER TABLE `temp_anticipo` DISABLE KEYS */;
/*!40000 ALTER TABLE `temp_anticipo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `temp_ficha_analisis`
--

DROP TABLE IF EXISTS `temp_ficha_analisis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `temp_ficha_analisis` (
  `id` int(11) NOT NULL,
  `nombre` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `nacionalidad` varchar(10) COLLATE utf8_spanish_ci NOT NULL,
  `cedula` varchar(10) COLLATE utf8_spanish_ci NOT NULL,
  `lugar_nac` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `fecha_nac` date NOT NULL,
  `edad` int(11) NOT NULL,
  `edo_civil` varchar(10) COLLATE utf8_spanish_ci NOT NULL,
  `direccion` varchar(200) COLLATE utf8_spanish_ci NOT NULL,
  `email` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `tlf_fijo` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `tlf_cel` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `tlf_otro` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `talla_camisa` varchar(5) COLLATE utf8_spanish_ci NOT NULL,
  `talla_pantalon` int(11) NOT NULL,
  `talla_zapato` int(11) NOT NULL,
  `padre_nombre` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `padre_cedula` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `padre_fn` date NOT NULL,
  `padre_ocup` varchar(20) COLLATE utf8_spanish_ci NOT NULL,
  `padre_cargo` varchar(2) COLLATE utf8_spanish_ci NOT NULL,
  `madre_nombre` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `madre_cedula` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `madre_fn` date NOT NULL,
  `madre_ocup` varchar(20) COLLATE utf8_spanish_ci NOT NULL,
  `madre_cargo` varchar(2) COLLATE utf8_spanish_ci NOT NULL,
  `conyuge_nombre` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `conyuge_cedula` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `conyuge_fn` date NOT NULL,
  `conyuge_ocup` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `conyuge_cargo` varchar(21) COLLATE utf8_spanish_ci NOT NULL,
  `hijo1_nombre` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `hijo1_cedula` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `hijo1_fn` date NOT NULL,
  `hijo1_ocup` varchar(20) COLLATE utf8_spanish_ci NOT NULL,
  `hijo1_cargo` varchar(2) COLLATE utf8_spanish_ci NOT NULL,
  `hijo2_nombre` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `hijo2_cedula` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `hijo2_fn` date NOT NULL,
  `hijo2_ocup` varchar(20) COLLATE utf8_spanish_ci NOT NULL,
  `hijo2_cargo` varchar(2) COLLATE utf8_spanish_ci NOT NULL,
  `hijo3_nombre` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `hijo3_cedula` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `hijo3_fn` date NOT NULL,
  `hijo3_ocup` varchar(20) COLLATE utf8_spanish_ci NOT NULL,
  `hijo3_cargo` varchar(2) COLLATE utf8_spanish_ci NOT NULL,
  `hijo4_nombre` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `hijo4_cedula` varchar(12) COLLATE utf8_spanish_ci NOT NULL,
  `hijo4_fn` date NOT NULL,
  `hijo4_ocup` varchar(20) COLLATE utf8_spanish_ci NOT NULL,
  `hijo4_cargo` varchar(2) COLLATE utf8_spanish_ci NOT NULL,
  `grado_instruccion` varchar(20) COLLATE utf8_spanish_ci NOT NULL,
  `titutlo` varchar(20) COLLATE utf8_spanish_ci NOT NULL,
  `banco` varchar(50) COLLATE utf8_spanish_ci NOT NULL,
  `cuenta` varchar(22) COLLATE utf8_spanish_ci NOT NULL,
  `tipocta` varchar(10) COLLATE utf8_spanish_ci NOT NULL,
  `sexo` varchar(5) COLLATE utf8_spanish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `temp_ficha_analisis`
--

LOCK TABLES `temp_ficha_analisis` WRITE;
/*!40000 ALTER TABLE `temp_ficha_analisis` DISABLE KEYS */;
/*!40000 ALTER TABLE `temp_ficha_analisis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `temp_prestamo`
--

DROP TABLE IF EXISTS `temp_prestamo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `temp_prestamo` (
  `id` int(11) NOT NULL,
  `id_personal` int(11) NOT NULL,
  `periodo` varchar(100) COLLATE utf8_spanish2_ci NOT NULL,
  `fecha` date NOT NULL,
  `monto` float NOT NULL,
  `cuotas` int(11) NOT NULL,
  `monto_cuota` float NOT NULL,
  `observacion` varchar(100) COLLATE utf8_spanish2_ci NOT NULL,
  `status` varchar(50) COLLATE utf8_spanish2_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish2_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `temp_prestamo`
--

LOCK TABLES `temp_prestamo` WRITE;
/*!40000 ALTER TABLE `temp_prestamo` DISABLE KEYS */;
/*!40000 ALTER TABLE `temp_prestamo` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `temp_prestamo_a`
--

DROP TABLE IF EXISTS `temp_prestamo_a`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `temp_prestamo_a` (
  `id` int(11) NOT NULL,
  `id_prestamo` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `monto` float NOT NULL,
  `control` varchar(100) COLLATE utf8_spanish_ci NOT NULL,
  `status` varchar(50) COLLATE utf8_spanish_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_spanish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `temp_prestamo_a`
--

LOCK TABLES `temp_prestamo_a` WRITE;
/*!40000 ALTER TABLE `temp_prestamo_a` DISABLE KEYS */;
/*!40000 ALTER TABLE `temp_prestamo_a` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `turnos`
--

DROP TABLE IF EXISTS `turnos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `turnos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `descripcion` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `turnos`
--

LOCK TABLES `turnos` WRITE;
/*!40000 ALTER TABLE `turnos` DISABLE KEYS */;
/*!40000 ALTER TABLE `turnos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `turnos_dias`
--

DROP TABLE IF EXISTS `turnos_dias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `turnos_dias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_turno` int(11) NOT NULL,
  `dia_semana` tinyint(4) NOT NULL COMMENT '1=Lun, 7=Dom',
  `h_inicio` time DEFAULT NULL,
  `h_tarde` time DEFAULT NULL,
  `h_fin` time DEFAULT NULL,
  `h_extra` time DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_turno` (`id_turno`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `turnos_dias`
--

LOCK TABLES `turnos_dias` WRITE;
/*!40000 ALTER TABLE `turnos_dias` DISABLE KEYS */;
/*!40000 ALTER TABLE `turnos_dias` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-17 15:18:36
