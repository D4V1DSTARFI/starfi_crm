# Normativa de Identidad Visual para Documentos (STARFI)

Este documento establece las directrices visuales y gráficas oficiales para todos los documentos, reportes y exportaciones generadas por la plataforma STARFI 2.0.

## 1. Preservación del Logotipo Corporativo
El sistema siempre dará predominancia al logotipo principal o perfil de la empresa (Ej. Logo del Cliente), configurado centralmente dentro de la base de datos `empresa_perfil`. Se dibuja primordialmente en el encabezado izquierdo del documento.

## 2. Indicador "Generado por Starfi" (Isologo)

Para certificar y dar estatus de que una plantilla ha sido autogenerada por nuestro software, se añade nuestro distintivo discretamente en los documentos.

### Archivo a utilizar
- **Ruta Oficial:** `docs/identidad_visual/logos/isologo_ni.png`
- **Por qué `isologo_ni.png` (Non-Interlaced):** El archivo original provisto `isologo.png` contenía un preformato entrelazado (Interlaced) no soportado por el motor PHP de FPDF y generaba un error (Fatal Exception). Se creó una copia `isologo_ni.png` purificada a nivel binario compatible que preserva la misma nitidez sin causar quiebres en la aplicación.

## 3. Pautas de Posicionamiento y Tamaño

### PDF (FPDF)
- **Logotipo Empresa:** Encabezado, alineado a la izquierda estructuralmente.
- **Logotipo Starfi:** Ubicado exclusivamente en la zona del pie de página (Footer) de la hoja en esquina inferior derecha (`X = GetPageWidth() - 25`), adjunto a la Fecha y Paginación, garantizando sofisticación.

### Excel (PhpSpreadsheet)
- **Logotipo Empresa:** Celda `A1`, altura = 50px de la pre-vía.
- **Logotipo Starfi:** Encabezado superior derecho acoplado a las celdas laterales (ej. `$colAntesFin . '1'`). Su altura exacta de anclaje es **57px**, con un ajuste horizontal (OffsetX) de **12px** y vertical (OffsetY) de **7px** para abarcar limpiezamente el espacio de las filas 1 y 2.

---
_Esta directriz asegura la elegancia en nuestros perfiles al mismo tiempo que promociona la autoría del sistema._
