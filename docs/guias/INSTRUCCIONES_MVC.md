# Modelo de Programación "MVC Parcial" STARFI 2.0

Este documento establece las normativas y pautas arquitectónicas para el desarrollo y mantenimiento del sistema STARFI 2.0. El objetivo es mantener una estructura modular, escalable y ordenada, siguiendo el modelo "MVC Parcial" implementado exitosamente en `STARFI/PHP/VENTAS`. Este documento sirve como referencia tanto para **Programadores Humanos** como para **Asistentes de Inteligencia Artificial (IA)**.

## 1. Concepto Central: MVC Parcial Modular

El modelo se basa en la **separación de responsabilidades** dentro del contexto de un entorno LAMP/XAMPP tradicional, pero organizando el código en **Módulos Independientes**. Cada módulo representa una entidad de negocio (por ejemplo, `producto`, `clientes`, `ventas`) y tiene su propia carpeta.

En lugar de tener un único controlador central o un enrutador complejo, cada módulo funciona como una aplicación encapsulada que consta de 4 tipos de archivos interdependientes:

### Estructura de Directorios

```text
/modules/
└── /nombre_del_modulo/
    ├── nombre_del_modulo.php         # VISTA PRINCIPAL (HTML/PHP)
    ├── modals_nombre_del_modulo.php  # VISTA SECUNDARIA (Modales Bootstrap)
    ├── funciones_nombre_del_modulo.js # CONTROLADOR FRONTEND (JS/jQuery/AJAX)
    ├── back_nombre_del_modulo.php    # CONTROLADOR BACKEND / MODELO (PHP/SQL)
    ├── logs_nombre_del_modulo.log    # LOGS DEL MÓDULO (Registro de eventos o errores)
    └── /test_ia/                     # PRUEBAS Y EXPERIMENTOS (Para IA y Devs. Mantiene la raíz limpia)
```

## 2. Descripción de Archivos por Módulo

### A. Archivo de Vista Principal (`nombre_del_modulo.php`)
**Propósito:** Renderizar la interfaz de usuario de la vista del módulo.
- **Acciones permitidas:**
  - Incluir cabeceras, menús y pies de página (Layout principal). Toda vista principal HTML debe, obligatoriamente, incluir en su bloque `<head>` la etiqueta del favicon: `<link rel="icon" href="RUTARELATIVA/docs/identidad_visual/logos/isologo.ico" type="image/x-icon">`.
  - Consultas SQL **solo** para carga inicial de datos estáticos (ej. llenar un `<select>` que no cambia).
  - Incluir el archivo de modales mediante `include()`.
  - Importar el archivo de funciones JavaScript (controlador frontend).
- **Reglas:** No debe contener lógica de procesamiento de formularios (inserciones, actualizaciones o eliminaciones en BD).

### B. Archivo de Modales (`modals_nombre_del_modulo.php`)
**Propósito:** Contener todo el código HTML de las ventanas emergentes (Modals de Bootstrap).
- **Acciones permitidas:** 
  - Estructuración de formularios para Crear, Editar y Eliminar dentro de los `<div class="modal">`.
- **Reglas:** Aislar los modales de la vista principal mantiene el archivo principal limpio y fácilmente legible. 

### C. Archivo de Funciones de Frontend (`funciones_nombre_del_modulo.js` o `.php`)
**Propósito:** Actuar como el intermediario (Controlador Frontend) entre la interfaz del usuario y el servidor.
- **Acciones permitidas:**
  - Lógica de la interfaz: Manejar eventos de click, submit, validaciones de formularios con jQuery.
  - Comunicación asíncrona: Empaquetar datos de formularios (ej. `FormData`) e invocar peticiones **AJAX** hacia el archivo de backend.
  - Procesamiento de respuestas: Recibir el JSON o HTML desde el servidor y actualizar el DOM de forma dinámica (ej. usando SweetAlert, recargar DataTables, o actualizar campos).
- **Reglas:** 
  - Todas las interacciones de mutación de datos (Insert, Update, Delete) **deben** hacerse vía AJAX, evitando recargas de página completas (`form actions` tradicionales).
  - Todo formulario (etiqueta `<form>`) o input debe llevar la propiedad `autocomplete="off"` por defecto para evitar que el navegador sugiera datos previos (salvo en casos muy específicos donde sí se requiera).

### D. Archivo de Backend (`back_nombre_del_modulo.php`)
**Propósito:** Actuar como Controlador de Backend y Modelo de Datos.
- **Acciones permitidas:**
  - Recibir peticiones `POST` o `GET` enviadas desde las funciones JS.
  - Saneamiento y validación de los datos recibidos.
  - Ejecutar operaciones SQL (Consultas preparadas, CRUD) contra la base de datos.
  - Retornar respuestas al cliente, **estrictamente en formato JSON** `echo json_encode(['status' => 'success', 'message' => '...'])`, o en su defecto fragmentos HTML si se requiere popular una tabla específica dinámicamente.
- **Reglas:** No debe contener etiquetas HTML (salvo que sea un retorno específico de un template para DataTables) ni redirecciones `header()`. Terminar la ejecución de los switches/caminos condicionales con un `die()` o `exit;`.

## 3. Ejemplo de Interacción (Flujo de Datos)

1. **Usuario hace clic** en el botón "Nuevo Producto" en la `nombre_del_modulo.php`.
2. Bootstrap abre el modal definido en `modals_nombre_del_modulo.php`.
3. El usuario llena el formulario y hace clic en "Guardar".
4. jQuery en `funciones_nombre_del_modulo.js` intercepta el evento de submit, previene su comportamiento por defecto (`e.preventDefault()`), recolecta los datos y hace una petición AJAX (tipo POST) hacia `back_nombre_del_modulo.php`.
5. El servidor en `back_nombre_del_modulo.php` inyecta los datos en la base de datos MySQL y responde con `{"status": "success", "msg": "Producto guardado"}`.
6. La petición AJAX recibe la respuesta y muestra una alerta (ej. Toast o SweetAlert), luego cierra el modal y recarga u actualiza la porción de la tabla visible sin recargar la pantalla.

## 4. Instrucciones Específicas para Inteligencias Artificiales (IA)

- **Contexto:** Al momento de recibir instrucciones sobre modificar o crear un módulo en la plataforma STARFI 2.0, **siempre buscar el directorio propio del módulo**.
- **Consistencia:** Respetar la nomenclatura estricta. Si el módulo se llama `pagos`, los archivos involucrados serán: `pagos.php`, `modals_pagos.php`, `funciones_pagos.js` y `back_pagos.php`.
- **Registro de Actividad (Logs Locales):** Todo módulo **debe tener** en su creación automática un archivo `logs_nombre_del_modulo.log`. El backend PHP deberá escribir en este archivo los errores de `try-catch` o fallas no controladas usando `error_log()`.
- **Auditoría y Trazabilidad (Global):** Es **obligatorio** que toda acción que muta datos (Insert, Update, Delete) o acciones críticas de seguridad (Login, Recuperación) sea registrada utilizando la función global `registrar_auditoria()` ubicada en `core/audit.php`. Se debe capturar el estado anterior y nuevo para permitir el control de cambios visual.
- **Pruebas y Experimentos:** Se debe crear una subcarpeta llamada `/test_ia/` dentro de cada módulo. Aquí se guardarán simuladores, mockups rápidos, o archivos temporales generados por la Inteligencia Artificial o programadores. La interfaz del módulo (los archivos raíz) deben mantenerse limpios de artefactos temporales.
- **Comunicación JSON:** Todo `back_` debe responder estructuradamente para facilitar el manejo de errores en el lado cliente.
- **Evitar Spaguetti:** NO anidar funciones de backend (PHP) directamente dentro de eventos `POST` en el archivo de vista `nombre_del_modulo.php`. La vista solo se dibuja, el back solo procesa.
- **CENTRALIZACIÓN MATEMÁTICA OBLIGATORIA:** TODAS las funciones matemáticas u operaciones de cálculos matemáticos (cálculo de impuestos, descuentos, IGTF, cuotas, cierres de caja, tasas de cambio) DEBEN estar ESTRICTAMENTE en el BACKEND manejados por el PHP (`back_nombre_del_modulo.php`). Queda **terminantemente prohibido** delegar cálculos aritméticos sensibles al Frontend mediante JavaScript. El JS debe comportarse como un visualizador pasivo ("Dumb UI") que recibe los montos ya computados en el Payload JSON.
- **Dependencias Locales Estrictas:** Todas las dependencias (CSS, JS, Fuentes, Iconos) DEBEN alojarse y servirse localmente en la carpeta `assets/`. Queda estrictamente prohibido el uso de CDNs externos para garantizar la carga ultrarrápida en entornos de red local (LAN) o sin acceso a Internet.

- **Módulos Inteligentes (Auto-despliegue):** Al crear un módulo nuevo que requiera modificaciones en la estructura de la base de datos (nuevas tablas, columnas, etc.), el módulo DEBE contar con una instrucción o script inteligente que verifique y ejecute estas alteraciones estructurales de forma automática al abrirse o desplegarse por primera vez. Esto previene errores de "Tabla no encontrada" en instalaciones limpias.
- **Documentación en Código (Inline Documentation):** Es estrictamente obligatoria la inclusión de documentación real, clara y eficaz dentro del código para cada módulo y funcionalidad nueva. Cada archivo creado o modificado debe contener comentarios estructurados (ej. PHPDoc, JSDoc) explicando la lógica de negocio y el propósito de las funciones. Esto garantiza que programadores humanos o IAs futuras puedan comprender el contexto original y realizar correcciones finales con precisión.
- **Tecnologías Admisibles:** Todo módulo a desarrollarse debe construirse **exclusivamente** con las tecnologías, librerías y componentes ya disponibles en el proyecto. No se deben introducir nuevas tecnologías externas.
- **Diseño Responsivo (Responsive Design):** Todos los módulos deben ser desarrollados con diseño responsivo, para garantizar que la interfaz sea óptima y pueda ser usada en todo tipo de dispositivos (escritorio, tablets y móviles).

## 5. Catálogo de Tecnologías y Librerías Disponibles

A continuación, se detalla el stack tecnológico base del proyecto STARFI 2.0 y la ruta relativa (desde la raíz del proyecto) de las librerías principales de terceros, disponibles para ser importadas en los nuevos módulos:

### Entorno Frontend (UI y Experiencia de Usuario)
- **Framework CSS:** Bootstrap 5 (Local) 
  - CSS: `assets/css/bootstrap.min.css`
  - JS: `assets/js/bootstrap.bundle.min.js`
- **Iconografía:** Bootstrap Icons y FontAwesome (Local)
  - Ruta base de iconos: `assets/icons/`
- **Interacciones y Alertas:** SweetAlert2
  - JS: `assets/js/sweetalert2.all.min.js` (o similar en `assets/js/`)
- **Manejo del DOM y AJAX:** jQuery
  - JS: `assets/js/jquery.min.js` (o equivalente en `assets/js/`)
- **Tablas Dinámicas:** DataTables (Si estuviese incluido en el proyecto base)
- **Estilos Propios Personalizados:**
  - CSS: `assets/css/starfi_theme.css`

### Entorno Backend (PHP)
- **Generación de Archivos PDF:** FPDF
  - Ruta de Inclusión: `includes/libs/pdf/fpdf.php`
- **Lectura/Escritura de Archivos Excel:** PhpSpreadsheet
  - Ruta de Inclusión (Autoloader): `includes/libs/excel/vendor/autoload.php`
- **Base de Datos y Sesión (Core):**
  - Conexión BD: `config/database.php`
  - Autenticación y Seguridad: `core/auth.php`

**Nota de Implementación:** Las librerías del Frontend generalmente ya vienen incluidas en el layout principal / header del sistema, por lo que no es necesario reconvocarlas en cada vista. Las librerías de Backend (`fpdf`, `excel`, etc.) deben adjuntarse mediante `require_once` **únicamente** en los archivos tipo `back_nombre_del_modulo.php` cuando la funcionalidad lo requiera explícitamente.

---

**Nota de Actualización Histórica:**
- **Fecha:** 03 de Marzo de 2026
- **Hora:** 09:14 AM
- **Acción:** Instalación formal y configuración de la librería `PhpSpreadsheet` (v1.29) mediante `Composer` en el directorio `includes/libs/excel/`. Se habilitó el autoloader para su uso en los módulos del sistema, comenzando por el reporte de Auditoría para eliminar advertencias de formato en Excel.

*(Fin del Documento)*
