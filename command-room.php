<?php
/**
 * Plugin Name: Command Room
 * Description: Suite de SEO técnico todo en uno: metas, datos estructurados, sitemaps, redirecciones, robots.txt, control de bots de IA, monitor de 404 y más, en un solo panel.
 * Version: 2.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Damien Santiago
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: command-room
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CMDROOM_VERSION', '2.0.0' );
define( 'CMDROOM_FILE', __FILE__ );
define( 'CMDROOM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CMDROOM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Cliente OAuth "Aplicación web" compartido para la conexión de Google
 * Search Console (ver includes/gsc/) -- mismo par en todos los sitios
 * de un mismo propietario, creado una sola vez en su proyecto de Google
 * Cloud, así "Conectar con Google" es un único botón sin formulario de
 * credenciales por sitio. Lo único que sigue siendo manual por sitio es
 * añadir su redirect URI (visible en Configuración → Herramientas) a la
 * lista de orígenes autorizados de este cliente en Google Cloud Console.
 *
 * IMPORTANTE -- este archivo se comparte por GitHub (repo público/del
 * plugin): el Client ID/Secret real NUNCA se pegan aquí, solo en el
 * wp-config.php de cada sitio (fuera del repo, nunca se
 * commitea). Por eso quedan vacíos por defecto -- así, si el plugin se
 * comparte, se instala en un sitio de un cliente, o se abre este código en
 * cualquier otra web, no hay ninguna credencial que filtrar; el
 * botón de conectar aparece simplemente desactivado con un aviso hasta que
 * ese sitio defina las suyas propias.
 */
if ( ! defined( 'CMDROOM_GSC_CLIENT_ID' ) ) {
	define( 'CMDROOM_GSC_CLIENT_ID', '' );
}
if ( ! defined( 'CMDROOM_GSC_CLIENT_SECRET' ) ) {
	define( 'CMDROOM_GSC_CLIENT_SECRET', '' );
}

require_once CMDROOM_DIR . 'includes/class-admin-menu.php';
require_once CMDROOM_DIR . 'includes/variables/class-variables-settings.php';
require_once CMDROOM_DIR . 'includes/class-variables-glossary.php';
require_once CMDROOM_DIR . 'includes/meta/class-meta-variables.php';
require_once CMDROOM_DIR . 'includes/meta/class-meta-settings.php';
require_once CMDROOM_DIR . 'includes/meta/class-meta-resolver.php';
require_once CMDROOM_DIR . 'includes/meta/class-meta-metabox.php';
require_once CMDROOM_DIR . 'includes/meta/class-meta-rest.php';
require_once CMDROOM_DIR . 'includes/meta/class-meta-output.php';
require_once CMDROOM_DIR . 'includes/meta/class-rss-settings.php';
require_once CMDROOM_DIR . 'includes/meta/class-googlenews-feed.php';
require_once CMDROOM_DIR . 'includes/meta/class-feed-links.php';
require_once CMDROOM_DIR . 'includes/migration/class-rankmath-importer.php';
require_once CMDROOM_DIR . 'includes/migration/class-yoast-importer.php';
require_once CMDROOM_DIR . 'includes/breadcrumbs/class-breadcrumb-settings.php';
require_once CMDROOM_DIR . 'includes/breadcrumbs/class-breadcrumbs.php';
require_once CMDROOM_DIR . 'includes/schema/class-schema-settings.php';
require_once CMDROOM_DIR . 'includes/schema/class-schema-variables.php';
require_once CMDROOM_DIR . 'includes/schema/class-schema-builder.php';
require_once CMDROOM_DIR . 'includes/schema/class-schema-output.php';
require_once CMDROOM_DIR . 'includes/sitemap/class-sitemap-settings.php';
require_once CMDROOM_DIR . 'includes/sitemap/class-sitemap-render.php';
require_once CMDROOM_DIR . 'includes/sitemap/class-sitemap-rewrite.php';
require_once CMDROOM_DIR . 'includes/redirects/class-redirect-table.php';
require_once CMDROOM_DIR . 'includes/redirects/class-redirect-admin.php';
require_once CMDROOM_DIR . 'includes/redirects/class-redirect-matcher.php';
require_once CMDROOM_DIR . 'includes/robots/class-robots-settings.php';
require_once CMDROOM_DIR . 'includes/sitemap/class-sitemap-ping.php';
require_once CMDROOM_DIR . 'includes/monitor404/class-404-table.php';
require_once CMDROOM_DIR . 'includes/monitor404/class-404-monitor.php';
require_once CMDROOM_DIR . 'includes/monitor404/class-404-admin.php';
require_once CMDROOM_DIR . 'includes/code-injection/class-code-injection.php';
require_once CMDROOM_DIR . 'includes/code-injection/class-code-assets.php';
require_once CMDROOM_DIR . 'includes/code-injection/class-code-admin.php';
require_once CMDROOM_DIR . 'includes/image-seo/class-image-seo-settings.php';
require_once CMDROOM_DIR . 'includes/image-seo/class-image-seo.php';
require_once CMDROOM_DIR . 'includes/archive-optimization/class-archive-optimization-settings.php';
require_once CMDROOM_DIR . 'includes/breadcrumbs/class-breadcrumbs-jsonld.php';
require_once CMDROOM_DIR . 'includes/cleanup/class-cleanup-settings.php';
require_once CMDROOM_DIR . 'includes/cleanup/class-cleanup.php';
require_once CMDROOM_DIR . 'includes/server/class-server-admin.php';
require_once CMDROOM_DIR . 'includes/tools/class-tools-admin.php';
require_once CMDROOM_DIR . 'includes/config/class-config-admin.php';
require_once CMDROOM_DIR . 'includes/config/class-seo-coexistence.php';
require_once CMDROOM_DIR . 'includes/ia/class-ia-settings.php';
require_once CMDROOM_DIR . 'includes/ia/class-ia-output.php';
require_once CMDROOM_DIR . 'includes/tags/class-tags-settings.php';
require_once CMDROOM_DIR . 'includes/gsc/class-gsc-client.php';
require_once CMDROOM_DIR . 'includes/gsc/class-gsc-settings.php';
require_once CMDROOM_DIR . 'includes/gsc/class-gsc-dashboard.php';
require_once CMDROOM_DIR . 'includes/general/class-general-patterns.php';
require_once CMDROOM_DIR . 'includes/general/class-general-audit.php';
require_once CMDROOM_DIR . 'includes/general/class-general-readability.php';
require_once CMDROOM_DIR . 'includes/general/class-general-dashboard.php';
require_once CMDROOM_DIR . 'includes/crux/class-crux-settings.php';
require_once CMDROOM_DIR . 'includes/crux/class-crux-client.php';
require_once CMDROOM_DIR . 'includes/crux/class-crux-dashboard.php';
require_once CMDROOM_DIR . 'includes/components/ticker/class-ticker-resolver.php';
require_once CMDROOM_DIR . 'includes/components/ticker/class-ticker-settings.php';
require_once CMDROOM_DIR . 'includes/components/ticker/class-ticker.php';
require_once CMDROOM_DIR . 'includes/components/affiliate/class-affiliate.php';
require_once CMDROOM_DIR . 'includes/components/affiliate/class-affiliate-settings.php';
require_once CMDROOM_DIR . 'includes/components/affiliate/class-affiliate-library.php';
require_once CMDROOM_DIR . 'includes/components/reco/class-reco.php';
require_once CMDROOM_DIR . 'includes/components/reco/class-reco-settings.php';
require_once CMDROOM_DIR . 'includes/components/htmlmap/class-htmlmap.php';
require_once CMDROOM_DIR . 'includes/components/htmlmap/class-htmlmap-settings.php';
require_once CMDROOM_DIR . 'includes/components/htmlmap/class-htmlmap-frontend.php';
require_once CMDROOM_DIR . 'includes/components/class-components-admin.php';

/**
 * Fase 1 (Metas) + Fase 2 (Datos estructurados) + Fase 3 (Sitemaps) +
 * Fase 4 (Redirecciones), más las mejoras posteriores: glosario de
 * variables, sitemap de Google News y control de robots.txt. Y los 10
 * módulos nuevos: bots de IA, sitemaps v2 (imágenes + ping), redirecciones
 * v2 (regex + 410/451 + historial), monitor de 404, inyección de código,
 * Auto-Image SEO, noindex de archivos, breadcrumbs JSON-LD globales y
 * limpieza de cabeceras/permalinks.
 */
function cmdroom_bootstrap() {
	Cmdroom_Admin_Menu::init();
	Cmdroom_Variables_Settings::init();
	Cmdroom_Variables_Glossary::init();
	Cmdroom_Meta_Settings::init();
	Cmdroom_Meta_Metabox::init();
	Cmdroom_Meta_Rest::init();
	Cmdroom_Meta_Output::init();
	Cmdroom_Rss_Settings::init();
	Cmdroom_Googlenews_Feed::init();
	Cmdroom_Feed_Links::init();
	Cmdroom_Rankmath_Importer::init();
	Cmdroom_Yoast_Importer::init();
	Cmdroom_Breadcrumb_Settings::init();
	Cmdroom_Breadcrumbs::init();
	Cmdroom_Schema_Settings::init();
	Cmdroom_Schema_Output::init();
	Cmdroom_Sitemap_Settings::init();
	Cmdroom_Sitemap_Render::init();
	Cmdroom_Sitemap_Rewrite::init();
	Cmdroom_Sitemap_Ping::init();
	Cmdroom_Redirect_Table::init();
	Cmdroom_Redirect_Admin::init();
	Cmdroom_Redirect_Matcher::init();
	Cmdroom_Robots_Settings::init();
	Cmdroom_404_Table::init();
	Cmdroom_404_Monitor::init();
	Cmdroom_404_Admin::init();
	Cmdroom_Code_Injection::init();
	Cmdroom_Code_Admin::init();
	Cmdroom_Image_Seo_Settings::init();
	Cmdroom_Image_Seo::init();
	Cmdroom_Archive_Optimization_Settings::init();
	Cmdroom_Breadcrumbs_Jsonld::init();
	Cmdroom_Cleanup_Settings::init();
	Cmdroom_Cleanup::init();
	Cmdroom_Server_Admin::init();
	Cmdroom_Config_Admin::init();
	Cmdroom_Seo_Coexistence::init();
	Cmdroom_Ia_Settings::init();
	Cmdroom_Ia_Output::init();
	Cmdroom_Tags_Settings::init();
	Cmdroom_Gsc_Settings::init();
	Cmdroom_General_Dashboard::init();
	Cmdroom_Crux_Settings::init();
	Cmdroom_Crux_Dashboard::init();
	Cmdroom_Ticker_Settings::init();
	Cmdroom_Ticker::init();
	Cmdroom_Affiliate::init();
	Cmdroom_Affiliate_Settings::init();
	Cmdroom_Affiliate_Library::init();
	Cmdroom_Reco::init();
	Cmdroom_Reco_Settings::init();
	Cmdroom_Htmlmap::init();
	Cmdroom_Htmlmap_Settings::init();
	Cmdroom_Htmlmap_Frontend::init();
	Cmdroom_Components_Admin::init();
}
add_action( 'plugins_loaded', 'cmdroom_bootstrap' );

register_activation_hook( __FILE__, function () {
	// No crea tablas ni migra nada. Solo marca que hay que regenerar las
	// reglas de rewrite: en la activación todavía no se han registrado las
	// del feed de Google News (se añaden en 'init'), así que el flush se
	// hace en la siguiente carga, ya con ellas dentro. Sin esto,
	// /rss/googlenews/*.xml da 404 en una instalación nueva.
	update_option( 'cmdroom_flush_rewrites', 1 );
} );

add_action( 'init', function () {
	if ( get_option( 'cmdroom_flush_rewrites' ) ) {
		delete_option( 'cmdroom_flush_rewrites' );
		flush_rewrite_rules();
	}
}, 99 );

register_deactivation_hook( __FILE__, function () {
	// El keepalive diario de la conexión GSC sí deja un cron huérfano si no
	// se limpia -- el resto del plugin no usa wp_cron.
	wp_clear_scheduled_hook( 'cmdroom_gsc_keepalive' );
} );
