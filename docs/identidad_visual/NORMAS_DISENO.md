# Identidad Visual y Normas de Diseño - STARFI 2.0

Este documento establece las pautas de diseño visual, tipografía y paleta de colores reales establecidas para el sistema STARFI 2.0, basadas en la identidad visual oficial.

## 1. Paleta de Colores

Basándonos en la guía de estilo gráfica provista, la interfaz se regirá por la siguiente combinación principal:

*   **Gris Pizarra Oscuro:** `#37414A` - Usado para fondos oscuros de barra de navegación, paneles laterales (Sidebar) y textos en formato de alto contraste inferior.
*   **Naranja/Mandarina Brillante:** `#E85B14` - **Color Principal (Primary)**, usado en botones de acción principal, estado activo, indicadores hover (por ejemplo, menú activo) e iconografía destacada.
*   **Rojo Carmesí Oscuro:** `#CC2919` - **Acento y Peligro (Danger)**, excelente para resaltar errores o para botones críticos (Eliminar, Cancelar).
*   **Rojo Terracota:** `#D23D26` - **Variante (Secondary Hover)**, se debe utilizar como color hover/active para acciones rojas o botones contextuales secundarios llamativos.
*   **Negro Puro:** `#000000` - Para alto contraste en logos, cabeceras grandes y textos de título que requieran extrema solidez.
*   **Fondos y Textos Adicionales (Standard UI):**
    *   **Fondo General:** `#f4f6f9` o similar off-white.
    *   **Texto Body:** `#212529` sobre fondos claros o `#ffffff` sobre el Gris Pizarra.

## 2. Tipografía

El sistema utilizará las siguientes dos familias de fuentes oficiales:

### **Gotham** (Fuente Principal para Lectura o Datos Regulares)
*   **Regular/Book Italic:** Usado en descripciones, subtítulos contextuales o notas al pie dentro del software.
*   **Medium:** Usado comúnmente para el cuerpo de texto estructurado, campos de formularios, o datos dentro de DataTables.

### **Mont** (Fuente de Acento y Títulos)
*   **ExtraLight:** Para encabezados grandes o elementos estilísticos que no requieran gran pesadez visual (ej. H1 principales en Dashboards modernos).
*   **Heavy / Bold:** Para títulos destacados (H2-H3), y elementos visuales de la interfaz de alto impacto y jerarquía, donde se necesita transmitir peso visual.

*Consideraciones de Uso:* Utilizar `Mont` preferiblemente para Identidad Corporativa / Títulos y `Gotham` para Textos de lectura de la aplicación (Data).

## 3. Uso de Logos

Los logos autorizados del proyecto están en `docs/identidad_visual/logos/` e incluyen:

*Normativas Generales del Logo:*
*   **Variante Principal (Full Color):** Para fondos claros o documentos PDF/Excel.
*   **Variante Negativa (Blanco):** Para superponer en color sólido oscuro (ej. Menú Lateral izquierdo o Navbar superior oscuro).
*   **Isotipo/Icono (Favicon):** Versión reducida al símbolo para la pestaña del navegador o para representar la aplicación en accesos directos. **REGLA OBLIGATORIA:** Todo archivo HTML o PHP principal (vista de módulo o pantalla aislada) debe incluir en su cabecera `<head>` la etiqueta `<link rel="icon" href=".../docs/identidad_visual/logos/isologo.ico" type="image/x-icon">`.
*   **Márgenes:** Respetar siempre al menos un 10% del ancho del logo como margen exterior para legibilidad.

*   `isologo.ico`: Formato icono, para la pestaña del navegador (Favicon).
*   `logo_starfi.png`: Logo a todo color con texto, para encabezados grandes de inicio de sesión o documentos exportados (PDF/Excel).
*   `isologo.png`: Isotipo simplificado (el símbolo), ideal para la barra de navegación lateral minimizada o para menús superiores que requieran compactar el espacio.

## 4. Estilos de Componentes (UI)

*   **Botones (Buttons):** Bordes más redondeados y amigables. Color dominante debe ser el *Naranja Brillante* para guardar.
*   **Tarjetas (Cards) y Modales:** Fondo blanco limpio (`#fff`) con sombras sutiles. Cabeceras modales pueden aplicar la fuente *Mont Heavy* para los títulos principales.
*   **Iconografía:** Consistente en toda la plataforma. Aplicar el color secundario Gris y cambiar a Naranja al pasar el ratón (Hover) en elementos no botonados.

---

*(Estas normas son referenciales y dictan el desarrollo estilístico de Módulos MVC en STARFI 2.0)*
