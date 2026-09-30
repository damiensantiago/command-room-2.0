=== Command Room ===
Contributors: damiensantiago
Tags: seo, sitemap, redirects, schema, structured data
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Suite de SEO técnico todo en uno: metas, datos estructurados, sitemaps, redirecciones, robots.txt, control de bots de IA, monitor de 404 y más.

== Description ==

Command Room centraliza el control técnico de un sitio WordPress en un solo panel de administración:

* **Metas** — título, descripción, canonical, robots (index/noindex, follow/nofollow), Open Graph y Twitter Cards, con plantillas por tipo de contenido y override por entrada.
* **Datos estructurados** — constructor de `@graph` JSON-LD configurable, catálogo de tipos schema.org, Organization/WebSite editables.
* **Sitemaps** — XML sitemaps generados on-demand con caché, sin depender del rewrite API de WordPress, incluye soporte de imágenes, vídeo y Google News.
* **Redirecciones** — gestor con soporte de expresiones regulares, códigos 410/451, historial de uso por regla.
* **Robots.txt y control de bots de IA** — editor con validación de sintaxis y checkboxes por crawler conocido (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, y más).
* **Servidor** — monitor de errores 404, limpieza de cabeceras HTTP y permalinks (trailing slash, generator, X-Pingback, parámetros de tracking).
* **Configuración** — noindex de archivos y taxonomías vacías, breadcrumbs (HTML + JSON-LD), atributos automáticos de imágenes (alt/title), IA (`/llms.txt` y markdown por página), gestión de etiquetas.
* **Importadores** — trae metas, plantillas y redirecciones desde Rank Math o Yoast SEO sin tocar ni borrar los datos originales, para migrar sin perder trabajo hecho.
* **Vista previa antes de activar** — compara lo que serviría Command Room contra lo que sirve tu plugin de SEO actual antes de dar el salto; toda la salida real al sitio está apagada por defecto.

Todos los módulos son opcionales y conviven con otros plugins de SEO mientras decides cuándo activar la salida real de cada uno.

== Installation ==

1. Sube la carpeta `command-room` a `/wp-content/plugins/` o instala el .zip desde "Plugins → Añadir nuevo → Subir plugin".
2. Activa el plugin desde el menú "Plugins".
3. Ve a "Command Room" en el menú de administración para configurar cada módulo.
4. Si vienes de Rank Math o Yoast SEO, usa el importador en "Configuración → Herramientas" antes de activar la salida real.
5. Activa "Salida en el sitio" módulo a módulo (en "Configuración → Salida en el sitio") cuando hayas verificado cada uno con la vista previa.

== Frequently Asked Questions ==

= ¿Sustituye a Rank Math o Yoast automáticamente? =

No. Command Room convive con tu plugin de SEO actual hasta que actives manualmente la salida real de cada módulo (metas, datos estructurados, sitemaps, redirecciones). Así puedes comparar antes de cortar.

= ¿Necesito una cuenta de Google o alguna API externa? =

No para el núcleo del plugin. La integración con Google Search Console y las curvas de Core Web Vitals (Chrome UX Report) son opcionales y requieren tus propias credenciales de Google Cloud.

= ¿Qué pasa si desactivo el plugin? =

Nada se pierde: las opciones quedan guardadas y tu plugin de SEO anterior sigue sirviendo como lo hacía antes si no llegaste a activar la salida real de Command Room.

== Screenshots ==

1. Panel General con resumen de módulos.
2. Editor de metas por tipo de contenido.
3. Constructor de datos estructurados (schema.org).
4. Gestor de redirecciones.

== Changelog ==

= 2.0.0 =
* Primera versión pública genérica: sin referencias a sitios concretos en código, textos ni valores por defecto.
* Shortcodes con prefijo propio: [cmdroom_afiliacion] y [cmdroom_relacionados]. Namespace REST de webs amigas: cmdroom/v1. Clase CSS del carrusel: .cmdroom-reco.
* Webs amigas: las invitaciones recibidas entran en pausa hasta que el administrador las activa; tope de 50 webs y validación del dominio.
* Afiliación: sin ID de afiliado ni textos de ejemplo por defecto.
* La contraseña de HTTP auth ya no se imprime en el formulario del admin.

= 1.0.0 =
* Primera versión pública: metas, datos estructurados, sitemaps, redirecciones, robots.txt y control de bots de IA, monitor de 404, limpieza de cabeceras/permalinks, breadcrumbs, atributos automáticos de imágenes, importadores desde Rank Math y Yoast SEO, integración opcional con Google Search Console y Core Web Vitals (CrUX).

== Upgrade Notice ==

= 1.0.0 =
Primera versión pública.
