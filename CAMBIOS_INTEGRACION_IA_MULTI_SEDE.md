# LISTA DE VERIFICACIÓN E INSTALACIÓN: ASISTENTES VIRTUALES IA MULTI-SEDE (STARFI CRM)

Este documento contiene la lista detallada de componentes, estructuras SQL y archivos requeridos para instalar o verificar el módulo de **Asistente Virtual IA Multi-Sede** en cualquier servidor o equipo.

---

## 📋 Checklist de Componentes a Verificar / Instalar

### 1. Modificaciones en Base de Datos (MySQL)

Si la base de datos de destino no posee la estructura multi-sede para IA, ejecutar el script de migración SQL ubicado en `database/migrate_configuraciones_ia_multisede.sql`:

```sql
-- A. Adaptar la tabla configuraciones_ia para soporte por id_sede
ALTER TABLE `configuraciones_ia` 
DROP PRIMARY KEY,
ADD COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT FIRST,
ADD COLUMN `id_sede` INT(11) NOT NULL AFTER `id_empresa`,
ADD COLUMN `modelo_ia` VARCHAR(50) DEFAULT 'gemini-3.6-flash' AFTER `agente_nombre`,
ADD COLUMN `temperatura` DECIMAL(2,1) DEFAULT 0.4 AFTER `modelo_ia`,
ADD PRIMARY KEY (`id`),
ADD UNIQUE KEY `uk_sede_ia` (`id_sede`),
ADD CONSTRAINT `fk_config_ia_sede` FOREIGN KEY (`id_sede`) REFERENCES `sedes` (`id`) ON DELETE CASCADE;

-- B. Crear la tabla de inventario de productos acotada por sede
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
```

---

### 2. Archivos Nuevos a Incluir en el Proyecto

Verificar que existan los siguientes archivos en la carpeta `core/`:

1. **`core/IaContextEngine.php`**: Motor RAG JIT de consulta de inventario por sede.
2. **`core/IaConnector.php`**: Conector con la API de Google Gemini (`gemini-3.6-flash`), manejo de cURL y ventana deslizante de memoria.

---

### 3. Archivos del Proyecto a Actualizar / Reemplazar

Asegurar que los siguientes archivos contengan las últimas versiones:

1. **`modules/configuracion/configuracion.php`**: Interfaz visual con selector de Sede, Modelo de IA y botón de prueba.
2. **`modules/configuracion/funciones_configuracion.js`**: Funciones AJAX por sede (`loadGemaConfig`, `saveGemaConfig`, `test_gema_connection`).
3. **`modules/configuracion/back_configuracion.php`**: Controlador backend con `save_gema`, `load_gema` por `id_sede` y `test_gema_connection`.
4. **`webhook.php`**: Orquestador principal de WhatsApp con detección de Handover humano, inyección de contexto RAG e integración de IA.

---

### 4. Configuración Manual desde la Interfaz Web

En caso de requerir activar la IA en una sede:
1. Abrir la URL: `http://localhost/starfi_crm/modules/configuracion/configuracion.php`
2. Hacer clic en la pestaña **Agente IA**.
3. Seleccionar la **Sede**.
4. Introducir la **Gemini API Key** de Google AI Studio.
5. Seleccionar el Modelo **Gemini 3.6 Flash**.
6. Hacer clic en **Guardar Configuración IA**.
7. Probar la conexión con el botón **Probar Conexión IA**.
