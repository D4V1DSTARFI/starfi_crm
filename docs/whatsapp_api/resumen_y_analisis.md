# Resumen de la API de WhatsApp Cloud (Meta) y Análisis del Código de Starfi CRM

## 1. Resumen de la Plataforma (WhatsApp Business Platform)
La plataforma de WhatsApp basada en la nube (Cloud API) permite a las empresas enviar y recibir mensajes a través de endpoints REST en la Graph API de Meta. 

### Componentes Principales:
- **Cloud API (Envío):** Endpoints (`/messages`) para enviar mensajes de texto, multimedia, interactivos (botones/listas) y plantillas preaprobadas.
- **Webhooks (Recepción):** Notificaciones HTTP asíncronas para recibir mensajes entrantes de clientes, actualizaciones de estado (enviado, entregado, leído, fallido) y eventos de facturación/conversación.
- **Business Management API:** Para administrar cuentas de WABA, plantillas de mensajes y números de teléfono.

### Tipos de Mensajes Soportados:
1. **Mensajes de Sesión (Atención al cliente):** Mensajes de forma libre que se pueden enviar dentro de una ventana de 24 horas después del último mensaje del usuario.
2. **Mensajes de Plantilla (Templates):** Requeridos para iniciar conversaciones fuera de la ventana de 24 horas. Deben ser pre-aprobados.
3. **Interactivos:** Botones de respuesta rápida, listas de selección, mensajes de catálogo de productos.
4. **Multimedia:** Imágenes, audios, documentos, videos, stickers, ubicación.

---

## 2. Análisis del Código Actual de Starfi CRM (`webhook.php` y base)

He analizado tu archivo `webhook.php`. El código es muy sólido en su estructura básica, gestionando colas, perfiles de clientes y estados. Sin embargo, comparado con la documentación completa de Meta, he detectado lo que tenemos y lo que **nos falta**:

### ✅ Lo que ya tenemos implementado correctamente:
1. **Verificación de Webhook:** Manejas perfectamente el `hub_challenge` y `hub_verify_token`.
2. **Recepción de Tipos Básicos:** Tu código ya intercepta y procesa:
   - Texto (`text`)
   - Imágenes (`image`)
   - Documentos (`document`)
   - Audio (`audio`)
   - Interactivos (`interactive` -> `button_reply`, `list_reply`)
3. **Tracking de Estados:** Interceptas correctamente los `statuses` (`sent`, `delivered`, `read`, `failed`) para actualizar tu tabla `mensajes_y_eventos`.
4. **Envío de Respuesta Libre:** Usas correctamente la API Graph `v23.0` (la más reciente) para enviar mensajes de texto y contactos (formato vCard).

### ❌ Lo que nos falta (Brechas de Seguridad y Funcionalidad):

#### A. Seguridad Crítica (Firma X-Hub-Signature-256)
Actualmente lees `php://input` y lo guardas directamente. Según la documentación de Meta, **cualquiera que conozca tu URL podría enviar peticiones falsas simulando ser WhatsApp**. 
* **Qué hacer:** Debemos implementar la validación del header `HTTP_X_HUB_SIGNATURE_256` utilizando tu App Secret de Meta y la función `hash_hmac('sha256')` de PHP. Si no coincide, rechazar la petición con un error 403.

#### B. Marcar como leído (Doble check azul)
Cuando un cliente escribe al bot, en el celular del cliente solo aparece el "doble check gris" (entregado). 
* **Qué hacer:** Debemos hacer una petición a la API enviando `"status": "read"` y el ID del mensaje recibido, para que al cliente se le pinte de azul (doble check azul), dando una mejor experiencia de atención.

#### C. Soporte para Mensajes No Controlados (Stickers, Ubicación, Reacciones)
Si un cliente envía un sticker, una ubicación o reacciona con un emoji a un mensaje, tu webhook actual lo ignora y detiene la ejecución (cae en los `if` pero no hace nada).
* **Qué hacer:** Agregar un tipo `TIPO_DESCONOCIDO`, `UBICACION`, `STICKER` o `REACCION` para registrar en la BD que el cliente interactuó, o devolver un mensaje automático: *"Por el momento nuestro sistema no procesa este tipo de archivo."*

#### D. Mensajes de Plantilla (Templates)
Para contactar a un cliente después de 24 horas o enviar notificaciones proactivas, necesitamos usar `type: template`.
* **Qué hacer:** Crear una función genérica en PHP similar a `enviar_mensaje_texto_api` pero adaptada para `enviar_plantilla_api` que incluya el nombre de la plantilla, idioma y componentes dinámicos (variables).

#### E. Manejo de Errores y Límites de Tasa (Rate Limiting)
Tu cURL actual hace la petición, pero si Facebook devuelve un error `429 Too Many Requests` (cuando envías muchos mensajes de golpe) o un error temporal, el mensaje se pierde.
* **Qué hacer:** Debemos capturar bien la respuesta JSON de cURL, verificar si hay un objeto `error`, y si es así, loguearlo o encolar el mensaje para reintentarlo.

## 3. Siguientes Pasos Recomendados

Si estás de acuerdo, el orden de prioridad para mejorar en esta rama `development` sería:

1. **Blindar el Webhook:** Implementar la verificación SHA-256 (`x-hub-signature-256`) para que sea invulnerable.
2. **Añadir el "Visto" automático:** Cuando procesemos un mensaje entrante, marcarlo como "read" en Meta.
3. **Manejar tipos faltantes:** Que no se ignoren ubicaciones ni stickers.
4. **Preparar función para Plantillas:** Necesaria para envíos masivos o reactivación de clientes inactivos.
