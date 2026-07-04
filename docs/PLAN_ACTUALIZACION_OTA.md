# Plan de Desarrollo: Sistema de Actualización OTA (Over-The-Air) vía Git

## Contexto y Visión General
Para mantener múltiples clientes y servidores locales de STARFI 2.0 sincronizados con la rama de producción (`main`), se desarrollará una herramienta de **Auto-Actualización (OTA Updater)**. Esta herramienta utilizará Git como motor de transferencia diferencial, envuelto por una capa de control en PHP y Javascript que permitirá a los administradores del sistema o a procesos automáticos (como el Cierre de Caja) descargar y aplicar parches de código sin intervención de un desarrollador.

---

## Fases del Plan de Desarrollo

### FASE 1: Preparación del Entorno Cliente (Infraestructura)
Esta fase se realiza una sola vez en la PC del cliente (XAMPP).
1. **Instalación de Motor:** Instalar Git for Windows en el servidor local.
2. **Seguridad y Credenciales (Deploy Keys):** En lugar de usar usuarios/contraseñas personales, se generará un **Personal Access Token (PAT)** o una llave SSH de *Solo Lectura* (Read-Only) hacia la rama `main` de GitHub. Esto garantiza que si el cliente es vulnerado, el código fuente no puede ser borrado ni alterado remotamente.
3. **Aislamiento de Archivos Locales (`.gitignore`):** Es **crítico** asegurar que archivos locales como `config/database.php`, directorios temporales, imágenes subidas de productos (`/img/`) y logs estén estrictamente en el `.gitignore`. Si no lo están, la actualización los aplastará.

### FASE 2: Motor de Actualización Backend (PHP)
Se creará un módulo aislado (ej. `modules/sistema/back_updater.php`).
1. **Ejecución Segura:** Se utilizará la función `shell_exec()` o un archivo `.bat` intermediario para invocar a Git desde PHP.
2. **Estrategia "Hard Reset":** Dado que el cliente *no debería* escribir código nuevo, la estrategia de actualización no será un simple `git pull` (que puede fallar por conflictos). Será una sobreescritura forzosa y limpia:
   ```bash
   git fetch origin main
   git reset --hard origin/main
   git clean -fd
   ```
   *Esta secuencia descarga los últimos cambios, aplasta cualquier modificación accidental local y elimina archivos residuales no rastreados (excepto los ignorados en el .gitignore).*
3. **Respuesta Estructurada:** El script parseará la salida de la consola y la devolverá en un JSON legible para que el usuario sepa qué archivos cambiaron.

### FASE 3: Interfaz Gráfica (Integración Cierre de Caja)
En lugar de una pantalla aislada, el control de actualizaciones de código se anclará directamente en el **Dashboard de Cierre de Caja**, compartiendo ecosistema con la **Sincronización Thor (Catálogo)**.
1. **Bloque Visual Compartido:** Se añadirá una nueva alerta o sección debajo de "Sincronización Thor" indicando si hay commits nuevos en la nube de GitHub listos para descargar.
2. **Botón Manual / Semi-Automático:** Un botón de "Actualizar Sistema (Código)" que acompañe al flujo de cierre.
3. **Control de Versiones:** Leer el último `git log -1` para mostrar en pantalla en qué commit/fecha se encuentra el sistema actualmente, brindando transparencia al supervisor.

### FASE 4: Automatización y Disparadores (Triggers)
¿Cuándo se actualizará el sistema?
1. **Flujo de Cierre (Recomendado):** Al igual que Thor Sync, la descarga de código se activará cuando las cajas estén cerradas, garantizando que no se interrumpan ventas.
2. **Bajo Demanda:** El botón visual mencionado en la Fase 3, que puede requerir autorización RFID si se ejecuta fuera de horario.
3. **Tarea Programada (Opcional):** Configurar el *Windows Task Scheduler* para que ejecute el script PHP a las 3:00 AM todos los días de forma transparente.

### FASE 5: Protocolos de Seguridad (Fail-Safes)
1. **Bloqueo de Actividad:** El motor de PHP verificará si existen carritos de compras o sesiones activas. Si detecta operaciones en curso, abortará la actualización para evitar crashear transacciones de red.
2. **Rollback Rápido:** Si la actualización `main` generase un error imprevisto (ej. pantalla blanca), dejar documentado un comando para retroceder un commit hacia atrás en casos de emergencia (`git reset --hard HEAD~1`).
