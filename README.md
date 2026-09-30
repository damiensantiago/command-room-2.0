# Command Room

Suite de SEO técnico todo en uno para WordPress: metas, datos estructurados, sitemaps, redirecciones, robots.txt, control de bots de IA, monitor de 404, limpieza HTTP/permalinks, breadcrumbs, atributos automáticos de imágenes, componentes de front-end orientados a SEO, e integración opcional con Google Search Console y Chrome UX Report (CrUX) — todo en un único panel de administración.

- **Requiere:** WordPress 6.2+, PHP 7.4+
- **Licencia:** GPLv2 o posterior
- **Idioma:** el plugin y este documento están en español; no hay archivos `.pot`/i18n todavía, todo el texto está en el código.

Este documento tiene dos partes: una **guía de uso** pantalla por pantalla (para quien va a administrar el plugin desde wp-admin) y una **guía técnica** de arquitectura (para quien vaya a mantener o extender el código).

---

## Índice

1. [Qué resuelve y por qué existe](#qué-resuelve-y-por-qué-existe)
2. [Instalación](#instalación)
3. [El concepto clave: de la IA de pago dentro del plugin, a un plugin gratuito conectado con tu agente de IA](#el-concepto-clave-de-la-ia-de-pago-dentro-del-plugin-a-un-plugin-gratuito-conectado-con-tu-agente-de-ia)
4. [Guía de uso — pantalla por pantalla](#guía-de-uso--pantalla-por-pantalla)
5. [Arquitectura (para desarrolladores)](#arquitectura-para-desarrolladores)
6. [Estado del proyecto y limitaciones conocidas](#estado-del-proyecto-y-limitaciones-conocidas)

---

## Qué resuelve y por qué existe

Command Room resuelve, sobre todo, un problema de **conexión entre el SEO y las herramientas de IA**. El plugin está construido para que cualquier agente de IA de programación (Claude Code, Cursor, Copilot o el que uses) pueda leerlo y modificarlo directamente: cada plantilla de metas es un único bloque de HTML editable, cada bloque de schema es JSON plano, cada ajuste vive en una opción con nombre claro, y cada módulo sigue el mismo patrón de dos piezas (una que guarda, otra que imprime). Ese código limpio y predecible es lo que permite mantener siempre el control real sobre lo que se imprime en el sitio, aunque la interfaz de administración esté deliberadamente simplificada frente a Rank Math o Yoast — a pesar de tener bastantes más opciones en abierto y gratis (bots de IA, Core Web Vitals vía CrUX, componentes de front-end, importadores, etc.).

Esa simplicidad no es una limitación: es lo que hace que el plugin sea **fiable** y **flexible** de verdad. Al no depender de decenas de pantallas ni de una estructura de datos opaca, puedes cederle a tu agente de programación tareas como "añade esta meta", "crea este bloque de JSON-LD" o "monta un módulo nuevo" sin miedo a que rompa algo en tu web — el propio diseño del plugin (opciones simples, un patrón repetido en todos los módulos, sin lógica escondida) está pensado para que ese trabajo sea seguro. Con instalar el plugin y darle a tu agente unas pocas indicaciones concretas, la optimización de un sitio pasa de ser sesiones enteras de configuración manual a cuestión de minutos.

## Instalación

1. Sube la carpeta `command-room` a `/wp-content/plugins/` (por FTP/SSH, o subiendo el `.zip` desde "Plugins → Añadir nuevo → Subir plugin").
2. Actívalo desde "Plugins".
3. Entra en el nuevo menú lateral "Command Room" para configurar cada módulo.
4. Si vienes de Rank Math o Yoast: usa el importador en **Configuración → Herramientas** antes de activar nada. Trae plantillas de metas y redirecciones sin borrar ni tocar los datos originales del otro plugin.
5. Activa **"Salida en el sitio"** módulo a módulo (dentro de **Configuración → Herramientas**) solo cuando hayas verificado cada uno con su vista previa.
6. Antes de dar el salto real, apaga Rank Math/Yoast — ver la siguiente sección, es el paso que más problemas evita.

## El concepto clave: de la IA de pago dentro del plugin, a un plugin gratuito conectado con tu agente de IA

Rank Math, Yoast y la mayoría de plugins de SEO grandes han empezado a vender su propia capa de IA como función de pago: generación de metas, sugerencias de contenido, "asistentes" varios, casi siempre por créditos o suscripción, y siempre como una caja cerrada — no ves ni puedes tocar cómo decide lo que decide. Command Room parte de la idea contraria: en vez de meter un asistente de IA de pago **dentro** del plugin, el plugin entero está diseñado para que **cualquier agente de IA que ya uses para programar** pueda leerlo, entenderlo y modificarlo directamente. Gratis, sin marketplace de créditos, sin caja negra.

Esto cambia quién resuelve tus necesidades de SEO. No es una función empaquetada, limitada a lo que el fabricante del plugin decidió ofrecer este trimestre: es tu propio agente, con contexto completo de tu sitio, escribiendo exactamente el meta, el bloque de schema o el módulo que necesitas, sobre una base de código pensada específicamente para que eso sea seguro. Es la diferencia entre pagar por una IA genérica metida dentro de un SaaS ajeno, y tener un plugin gratuito que es, en sí mismo, terreno fácil de trabajar para la IA que ya tienes corriendo en tu editor o tu terminal.

En la práctica, esto significa que Command Room está pensado para **sustituir** a Rank Math/Yoast, no para convivir con ellos indefinidamente. El camino de migración es:

1. Instala Command Room y usa el importador (**Configuración → Herramientas**) para traer tus metas, plantillas y redirecciones desde Rank Math o Yoast sin perder nada.
2. Ajusta lo que haga falta — a mano o pidiéndoselo a tu agente de IA — con la salida real todavía apagada, comparando en paralelo contra lo que ya tenías.
3. Cuando todo cuadre, activa la salida real y desactiva el otro plugin de SEO.

Ese último paso es el único punto delicado de la migración: si activas la salida real de Command Room **sin haber desactivado antes** el otro plugin, ambos imprimen su propio `<title>`, `<meta description>` y `<link rel="canonical">` en la misma página — HTML inválido y una señal confusa para los buscadores. Para que eso no pase por descuido, **Configuración → Herramientas → "Salida en el sitio"** muestra siempre, arriba del todo, una tarjeta de estado: si Rank Math o Yoast siguen activos, aparece un aviso con un botón **"Desactivar Rank Math"** / **"Desactivar Yoast SEO"** que los apaga con un clic (con confirmación), sin salir del admin ni tocar SSH/WP-CLI — el último empujón para completar la sustitución sin dejar HTML duplicado por el camino.

Los módulos que no dependen de un plugin de SEO de terceros para lo mismo (Robots.txt, Servidor/Limpieza, Componentes, Código, Auto-Image SEO, Breadcrumbs) no tienen este interruptor — se aplican en cuanto guardas, porque no hay un "otro plugin" con el que puedan chocar de la misma forma.

## Guía de uso — pantalla por pantalla

El menú lateral "Command Room" tiene diez entradas. Las que agrupan varias pantallas antiguas lo dicen explícitamente.

### General

El dashboard. Siete bloques, cada uno opcional/colapsable:

1. **Rendimiento GSC** — gráfica de clics/impresiones/CTR/posición con selector 7/28/90 días, si has conectado Google Search Console (ver "Configuración → Herramientas" más abajo).
2. **Patrones de éxito** — cuatro comparaciones automáticas sobre tus propios datos de Search Console (evolución de posición por categoría, CTR de páginas con año en el título, impresiones de páginas con FAQ, crecimiento de páginas con intención de compra). Cada patrón compara una cosa distinta; no es un único algoritmo.
3. **Tabla de módulos** — resumen de todos los módulos del plugin con su toggle de visibilidad en el menú.
4. **Top URLs** — tus páginas con más clics, vía Search Console.
5. **Auditoría del sitio** — chequeos reales sobre contenido real (meta descripción ausente, título duplicado, imagen destacada sin alt, enlaces internos rotos, H1 ausente, URLs con parámetros indexadas). Escanea hasta 200 entradas, con caché de 24h y botón "Actualizar auditoría". Documentado con sus propios falsos positivos conocidos (p. ej. "H1 ausente" puede saltar si tu tema pinta el H1 fuera del contenido).
6. **Análisis y legibilidad** — heurísticas (no un analizador lingüístico real) sobre la última entrada publicada: densidad aproximada de palabra clave, estructura H2/H3, enlaces entrantes, longitud de frases/párrafos, voz pasiva.
7. **Problemas recientes + Privacidad** — la píldora "Sin llamadas externas" solo aparece si ni GSC ni CrUX están conectados.

Nada de esto requiere cuenta de Google salvo los puntos 1 y 2 (Search Console) — son completamente opcionales.

### Metas

Plantillas de `<title>`, meta descripción, canonical, robots, Open Graph y Twitter Cards, en un único bloque de HTML editable por grupo de página (no campo por campo): **Home, Contenido, Categorías, Tags, Autor, Corporativas, General** (aplicado como base a todo lo que no tenga su propio grupo). Cada bloque usa variables tipo `%title%`, `%sitename%`, `%sep%`, `%author_name%`, `%url%`, `%robots%`, `%image%`, etc. — el listado completo y qué significa cada una está en la pestaña **Variables** del menú (no lo dupliques buscándolo en el código). Permite override manual por entrada individual desde el metabox del editor.

### Datos estructurados

Constructor de JSON-LD (`@graph`) por los mismos 7 grupos de página que Metas. Cada grupo admite varios bloques a la vez (p. ej. una entrada puede llevar `Article` + `FAQPage` + `HowTo` juntos, todos dentro del mismo `@graph`, nunca como `<script>` sueltos). Incluye un editor de Organización/Negocio (Organization/LocalBusiness) reutilizado por la variable `%organization%` de Metas.

### Sitemaps

Generación de sitemaps XML on-demand, con caché, sin depender del rewrite API de WordPress. Cada sitemap se define como "un tipo de contenido, opcionalmente acotado por taxonomía/términos, con un límite de URLs" — puedes montar uno de blog, otro de páginas corporativas, otro de transaccionales, etc. Soporta imágenes, vídeo (`<video:video>`) y Google News (`<news:news>`), con idioma de Google News configurable por sitemap individual.

### Robots.txt

Editor del contenido de `/robots.txt` vía el filtro nativo `robots_txt` de WordPress (mismo mecanismo que Rank Math/Yoast — no escribe un archivo físico, así que funciona igual en cualquier hosting). Incluye control de bots de IA conocidos (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot, Bytespider…) con vista previa en vivo del bloque generado. **Importante:** si ya existe un `robots.txt` físico en el servidor, el servidor web lo sirve directo y este módulo no hace nada hasta que se borre — el plugin lo detecta y avisa, pero no lo borra automáticamente.

### Servidor

Agrupa tres pestañas antes sueltas, resueltas en PHP sin tocar `.htaccess`:

- **Redirecciones** — gestor con diálogo de alta/edición de una regla a la vez, origen exacto o por regex, códigos 301/302/307/410/451, herramienta de prueba de ruta e historial de disparos.
- **Monitor 404** — registro de URLs rotas ordenado por frecuencia, con acción rápida para convertir cualquiera en una redirección 301 sin salir de la pantalla.
- **Limpieza HTTP/permalinks** — reglas independientes activables una a una: forzar HTTPS, dominio canónico con/sin `www`, barra final según tu estructura de permalinks, quitar `/category/` de la URL, redirigir páginas de adjunto, ignorar parámetros de campaña (`utm_*`, `fbclid`, `gclid`), eliminar `?replytocom`, y tres reglas de hardening heredadas (cabecera `X-Pingback`, meta generator, versión de WP en assets). Estas reglas **no dependen del toggle de "Salida en el sitio"** — se aplican en cuanto las activas.

### Código

Dos pestañas:

- **Listado** — inventario real de scripts JS y CSS que carga el sitio de verdad: pide por HTTP el HTML servido en 5 tipos de página representativos y parsea lo que encuentra (no una lista fija). Documentado con sus límites: solo ve esos 5 tipos de página, no ejecuta JS, y lo que no reconoce lo marca como "sin identificar".
- **Inyección** — pega HTML/JS directo en `<head>` o `<body>`/`<footer>` sin tocar el tema. Se imprime siempre que haya contenido guardado, sin passar por sanitización agresiva (es contenido de admin de confianza, protegido por `manage_options` + nonce) — ⚠️ si aquí metes un snippet de analítica (GA, GTM, Meta Pixel) y tu sitio ya lo inyecta por otra vía (p. ej. Site Kit), acabarás con el tag duplicado; revisa antes de activar.

### Variables

Glosario y valores editables de las variables "estáticas" que no dependen de la página (nombre del sitio, separador, favicon, idioma, imagen de respaldo…). Es el sitio de referencia para saber qué significa cada `%variable%` usada en Metas/Schema — si no editas nada aquí, cada variable cae al comportamiento nativo de WordPress de siempre (Ajustes → General, Site Icon, etc.), así que instalar el plugin no cambia nada hasta que edites algo explícitamente.

### Componentes

Bloques de front-end activables uno a uno, gestionados desde una vista "Ver todos" con toggle instantáneo. Del catálogo de 12, **solo el Ticker está totalmente construido**; el resto son placeholders "Próximamente":

| Componente | Estado |
|---|---|
| Ticker (barra de mensajes en movimiento) | ✅ Funcional |
| Carrusel de recirculación | Próximamente |
| Sitemap HTML navegable | Próximamente |
| Módulo de autor (E-E-A-T) | Próximamente |
| Fuentes / bibliografía | Próximamente |
| Carrusel de shorts | Próximamente |
| Carrusel de reviews de Google | Próximamente |
| Carruseles por temáticas (tags/autores/categorías) | Próximamente |
| Preguntas frecuentes (FAQ + FAQPage schema) | Próximamente |
| TLDR / resumen | Próximamente |
| Conversión en blog (ayuda/WhatsApp para ecommerce) | Próximamente |
| Tabla de precios | Próximamente |

El Ticker admite tres orígenes de mensajes intercambiables sin perder los ajustes de los otros dos: **Automático** (últimas entradas, ofertas/envío gratis/cupones de WooCommerce si está activo), **Configurado** (mensajes manuales, opcionalmente por día de la semana) y **Mixto** (intercala los dos con una proporción configurable). Cambiar de vista no cambia el modo en uso — eso requiere la acción explícita "Usar este modo".

### Configuración

Agrupa siete pestañas:

- **Archivos y taxonomías** — reglas de noindex para archivos/taxonomías de bajo valor SEO (autor, fecha, paginación, tags, formato, búsqueda, términos vacíos). El resultado se combina con el override manual del post y sale impreso dentro del bloque de Metas (vía `%robots%`), no como una etiqueta aparte.
- **Breadcrumbs** — migas de pan en HTML + su `BreadcrumbList` en el schema. Toggle de activación, inclusión opcional de la categoría principal, shortcode `[cmdroom_breadcrumbs]` y función de tema `cmdroom_the_breadcrumbs()`.
- **Auto-Image SEO** — plantillas con variables (`%image_name%`, `%title%`, `%sitename%`) para generar automáticamente `alt`/`title` de imágenes, tanto al subir como en la salida (imágenes ya existentes en la biblioteca). Incluye renombrado físico de archivo como regla independiente.
- **IA** — dos funciones: `/llms.txt` (resumen del sitio en markdown al estilo "robots.txt para LLMs", spec de llmstxt.org) y versión `.md` de cada entrada/página en la misma URL + `.md`, para que los crawlers de IA lean markdown limpio sin parsear el HTML del tema. Ambas se regeneran solas en cada visita.
- **Tags** — tres sub-pestañas: listado completo con fusión de duplicadas (con redirección 301) y borrado de vacías; etiquetado masivo por filtro (tipo de contenido, categoría, fechas, búsqueda); y el límite recomendado de etiquetas por post.
- **RSS** — configuración del feed RSS "estilo Google News" por categoría (`/rss/googlenews/{categoría}.xml`), con la misma estructura de campos que un feed real de producción, en vez del feed nativo de WordPress.
- **Herramientas** — conexión con Google Search Console (OAuth, opcional) y API key de Chrome UX Report/CrUX (opcional), importadores desde Rank Math/Yoast, y la tarjeta de **"Salida en el sitio"** con los toggles maestros de Metas/Schema/Sitemaps/Redirecciones y el aviso de coexistencia con otros plugins SEO descrito arriba.

## Arquitectura (para desarrolladores)

### Bootstrap

`command-room.php` es el único punto de entrada: define constantes (`CMDROOM_VERSION`, `CMDROOM_DIR`, `CMDROOM_URL`), incluye cada clase de `includes/` con `require_once` (sin autoloader) y las inicializa todas en `cmdroom_bootstrap()` (hook `plugins_loaded`). No hay Composer ni build step — es PHP + hooks de WordPress puros, JS/CSS vanilla sin bundler.

### Patrón por módulo: Settings + Output

Cada módulo se divide en dos responsabilidades, casi siempre en dos clases:

- **`*_Settings`** (o `*_Admin`): pinta el formulario en wp-admin y guarda en `wp_options` vía `admin_post` + nonce + `current_user_can( 'manage_options' )`.
- **`*_Output`** (o `*_Render`): se engancha a los hooks reales del front-end (`wp_head`, `template_redirect`, `robots_txt`, etc.) y decide qué imprimir, leyendo lo que guardó el Settings.

Esto permite que la pantalla de admin y la salida real nunca se desincronicen, y que se pueda previsualizar sin imprimir nada real (el patrón de "Salida en el sitio").

### Nomenclatura de `wp_options`

Todas las opciones usan el prefijo `cmdroom_`. Las principales:

| Opción | Contenido |
|---|---|
| `cmdroom_meta_options` | Plantillas de Metas por grupo + separador + `live_output` |
| `cmdroom_schema_options` | Bloques JSON-LD por grupo + Organización/Negocio |
| `cmdroom_sitemap_options` | Definiciones de sitemap |
| `cmdroom_robots` | Contenido de robots.txt + bots de IA |
| `cmdroom_servidor` | Redirecciones + Monitor 404 + Limpieza (clave `clean` para las reglas de limpieza) |
| `cmdroom_config` | Toggles de menú/salida agregados de Archivos/Breadcrumbs/Auto-Image |
| `cmdroom_ia` | Ajustes de llms.txt y markdown por página |
| `cmdroom_tags` | Ajustes del módulo Tags |
| `cmdroom_variables` | Overrides de variables estáticas |
| `cmdroom_components` | Catálogo activo + ajustes del Ticker |
| `cmdroom_code_injection_options` | HTML/JS de inyección en head/body/footer |
| `cmdroom_gsc` / `cmdroom_crux` | Conexión OAuth de Search Console / API key de CrUX |
| `cmdroom_menu_visibility` | Qué entradas de menú se muestran |

**Importante para quien despliegue esto en un sitio nuevo o distinto:** estas opciones viven en la base de datos de WordPress, no en el código del plugin. Copiar los archivos PHP a otro sitio (staging → producción, por ejemplo) **no migra la configuración** — hay que exportar/importar estas opciones explícitamente (`wp option get <clave> --format=json` / `wp option update <clave> --format=json`), revisando antes que ningún valor haga referencia al dominio de origen (URLs, snippets de analítica con dominios hardcodeados, etc.).

### El cliente OAuth de Google Search Console

`CMDROOM_GSC_CLIENT_ID` / `CMDROOM_GSC_CLIENT_SECRET` son constantes que **nunca deben definirse dentro del propio código del plugin** (este repo se comparte públicamente). Si quieres usar la integración con Search Console, defínelas en el `wp-config.php` de tu propio sitio:

```php
define( 'CMDROOM_GSC_CLIENT_ID', 'tu-client-id.apps.googleusercontent.com' );
define( 'CMDROOM_GSC_CLIENT_SECRET', 'tu-client-secret' );
```

Sin esas constantes, el botón de conexión aparece desactivado con un aviso — el resto del plugin funciona exactamente igual.

### Sistema de coexistencia con Rank Math/Yoast

`Cmdroom_Seo_Coexistence` (`includes/config/class-seo-coexistence.php`) detecta si `seo-by-rank-math/rank-math.php` o `wordpress-seo/wp-seo.php` están activos y ofrece desactivarlos con un clic desde la propia pantalla de "Salida en el sitio". Es puramente informativo/asistido — el plugin nunca desactiva nada automáticamente sin que el usuario pulse el botón.

### Cómo añadir un módulo nuevo

1. Carpeta propia en `includes/<módulo>/` con `class-<módulo>-settings.php` (formulario + guardado) y, si toca el front-end, `class-<módulo>-output.php` o similar.
2. `require_once` en `command-room.php`, e inicialización (`::init()`) en `cmdroom_bootstrap()`.
3. Si necesita su propia pantalla de menú, añadirla en `Cmdroom_Admin_Menu::get_submenus()`; si es una pestaña de una pantalla existente (Servidor/Configuración), añadirla a las constantes `TABS`/`MERGED_*_SLUGS` de la clase contenedora correspondiente.
4. Si imprime algo en el front-end de forma no trivial, considera si necesita su propio toggle de "Salida en el sitio" (siguiendo el patrón de Metas/Schema/Sitemaps/Redirecciones) o si puede aplicarse directo (como Limpieza/Robots/Auto-Image, que no compiten con ningún otro plugin de SEO instalado).

## Estado del proyecto y limitaciones conocidas

- **11 de los 12 Componentes** son placeholders "Próximamente" sin funcionalidad real todavía (solo el Ticker está construido).
- **Auditoría del sitio**, **Análisis y legibilidad** y **Código → Listado** son heurísticas documentadas como aproximadas, no herramientas de análisis exhaustivo — cada una explica sus propios falsos positivos conocidos en su docblock.
- Bugs abiertos conocidos a fecha de este README:
  - Algunas páginas del grupo "Corporativas" pueden renderizar `<title>` con una variable sin resolver si esa página no encaja bien en el grupo esperado — revisar la plantilla de Metas del grupo correspondiente si ves un título con un hueco vacío.
  - `/sitemap.xml` (sin el `_index`) puede redirigir al sitemap nativo de WordPress (`/wp-sitemap.xml`) y dar 404 si los sitemaps core de WP están desactivados; el sitemap real y funcional del plugin es siempre `/sitemap_index.xml` (el que se referencia en `/robots.txt`).
- No hay tests automatizados ni CI configurado — la validación hasta ahora ha sido manual, contra contenido real, en un entorno de staging antes de cada cambio a producción.
