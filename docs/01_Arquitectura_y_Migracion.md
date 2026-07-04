# 🚀 STARFI 2.0 (STARFI NEXT) - Guía de Arquitectura y Migración

Bienvenido al nuevo ecosistema de STARFI. Este documento sirve como punto de partida para entender cómo está estructurado el nuevo proyecto y cuáles son los pasos exactos para ir migrando los módulos antiguos (Caja, Ventas, etc.) hacia esta nueva arquitectura centralizada.

## 🏗️ 1. La Nueva Arquitectura (El Patrón Estrangulador)

El objetivo de STARFI 2.0 es centralizar recursos comunes (Login, Sesiones, Usuarios, Estilos) y mantener separados los datos operativos masivos.

### 🗄️ El Modelo de 3 Bases de Datos

El sistema ahora se conecta de forma inteligente e independiente a tres entornos de bases de datos a través de un único archivo: **`config/database.php`**.

1.  **`starfi_core`**: (NUEVA) El cerebro del ecosistema. Debe contener las tablas de `usuarios`, `roles`, `sedes`, y `configuracion_global`.
2.  **`starfi`**: (EXISTENTE) El motor de inventario, compras, gastos y kardex (Lo que antes era Caja V2).
3.  **`starfi_ventas`**: (EXISTENTE) El front-end de atención al cliente, comandas y facturación final.

### 🚪 El Flujo de Autenticación (SSO Interno)

1.  El usuario entra a `starfi2.0/login.php`.
2.  El sistema busca sus credenciales **únicamente** en `starfi_core.usuarios`.
3.  Si es válido, se crea una sesión global (`$_SESSION['usuario_id']` y `$_SESSION['sesion_sso'] = true`).
4.  El usuario es redirigido a `starfi2.0/index.php` (El Hub).
5.  Cualquier página dentro del nuevo ecosistema debe requerir el archivo `core/auth.php` y llamar a `requireAuth()` para estar protegida.

---

## 📂 2. Estructura de Directorios

Entender dónde va cada cosa es vital para mantener el código limpio:

```text
starfi2.0/
├── assets/         <-- (NUEVO) CSS, JS (Bootstrap local), Imágenes globales.
├── config/         <-- (NUEVO) Configuraciones maestras (ej. database.php).
├── core/           <-- (NUEVO) Lógica central de seguridad (auth.php, roles).
├── docs/           <-- (NUEVO) Carpeta de contexto y documentación técnica.
├── includes/       <-- (NUEVO) Componentes UI que se repiten (navbars, sidebars).
├── modules/        <-- (MIGRACIÓN) Aquí traerás los módulos antiguos progresivamente.
│   ├── caja/       <-- (Lo que antes era php/caja_v_2)
│   ├── ventas/     <-- (Lo que antes era php/ventas)
│   └── admin/      <-- (NUEVO) Gestión exclusiva de starfi_core (usuarios, roles).
├── index.php       <-- El Hub Principal de navegación.
└── login.php       <-- Pantalla única de inicio de sesión.
```

---

## 🛠️ 3. Guía Paso a Paso para Migrar un Archivo

Cuando decidas mover un archivo antiguo (ej. `back_facturacion.php`) al nuevo proyecto, debes seguir este checklist:

### Paso A: Mover y Ubicar
1.  Copia el archivo a su nueva ubicación dentro de `starfi2.0/modules/nombre_del_modulo/`.

### Paso B: Actualizar Conexión a Base de Datos
1.  **Elimina** el antiguo `require 'conexion.php';`.
2.  **Añade** el nuevo gestor de base de datos apuntando hacia atrás (dependiendo de la profundidad de la carpeta):
    ```php
    require_once __DIR__ . '/../../config/database.php';
    // Si el archivo necesita leer de Caja:
    $con = getDbConnection('caja'); 
    // Si el archivo necesita leer de Ventas:
    $con = getDbConnection('ventas');
    ```

### Paso C: Integrar la Seguridad Global
1.  Justo después de la base de datos, protege el archivo llamando al Core:
    ```php
    require_once __DIR__ . '/../../core/auth.php';
    requireAuth(); // Esto expulsa al usuario a login.php si no tiene sesión activa
    $usuario_actual = getUserInfo(); // Opcional: Para obtener el array con datos del usuario ('usuario', 'rol', etc.)
    ```

### Paso D: Actualizar UI (Bootstrap 5)
1.  Elimina las referencias CDN antiguas de Bootstrap 4 o estilos sueltos.
2.  Apunta a los archivos locales del nuevo proyecto:
    ```html
    <link href="/starfi2.0/assets/css/bootstrap.min.css" rel="stylesheet">
    <!-- ... tu código ... -->
    <script src="/starfi2.0/assets/js/bootstrap.bundle.min.js"></script>
    ```

### Paso E: Revisar Consultas SQL (El Mayor Reto)
1.  Si tu viejo código de Caja (`starfi`) buscaba el nombre del vendedor haciendo un `JOIN` con la tabla `usuarios` en la *misma base de datos*, **ese código fallará** ahora que `usuarios` está en `starfi_core`.
2.  **Solución Constelación:** Dado que tu usuario de MySQL tiene acceso a todas las bases de datos en el servidor, simplemente especifica el prefijo en las consultas:
    ```sql
    /* Antes: */
    SELECT facturas.id, usuarios.nombre FROM facturas JOIN usuarios ON facturas.id_usuario = usuarios.id
    
    /* Ahora (En STARFI 2.0): */
    SELECT facturas.id, starfi_core.usuarios.usuario 
    FROM facturas 
    JOIN starfi_core.usuarios ON facturas.id_usuario = starfi_core.usuarios.id
    ```

---

## 🔜 4. Próximos Pasos Recomendados

Para poner a andar el proyecto en tu entorno local:

1.  Abre PhpMyAdmin (o tu cliente SQL).
2.  Crea la base de datos vacía llamada `starfi_core`.
3.  Crea la tabla `usuarios` (id, usuario, clave, rol, sede, status).
4.  Crea manualmente tu primer usuario allí.
5.  Abre `localhost/starfi2.0/login.php` y prueba iniciar sesión.
