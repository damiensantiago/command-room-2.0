<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sirve /sitemap_index.xml y /sitemap-{slug}.xml interceptando la petición
 * directamente en template_redirect, comparando contra REQUEST_URI —
 * deliberadamente SIN usar add_rewrite_rule()/flush_rewrite_rules(). En este
 * hosting el refresco de reglas de reescritura ya ha dado problemas (categorías
 * que compiten por la URL con páginas), así que un sitemap no depende de esa maquinaria en absoluto.
 *
 * Gated por "Salida en el sitio": mientras esté apagado, estas URLs las
 * sigue sirviendo Rank Math sin ninguna interferencia.
 */
class Cmdroom_Sitemap_Rewrite {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
		add_filter( 'wp_sitemaps_enabled', array( __CLASS__, 'maybe_disable_core_sitemaps' ) );
	}

	/**
	 * Con la salida en el sitio activada, /wp-sitemap.xml (el sitemap
	 * nativo de WordPress, desde 5.5) se apaga -- no compite con Rank Math
	 * (vive en otra URL) pero sí puede acabar indexado como un sitemap
	 * paralelo al de Command Room, con su propio criterio de qué incluye.
	 */
	public static function maybe_disable_core_sitemaps( $enabled ) {
		return Cmdroom_Sitemap_Settings::is_live_output_enabled() ? false : $enabled;
	}

	public static function maybe_serve() {
		if ( ! Cmdroom_Sitemap_Settings::is_live_output_enabled() ) {
			return;
		}

		$path = wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );

		if ( '/sitemap_index.xml' === untrailingslashit( $path ) ) {
			self::output( Cmdroom_Sitemap_Render::render_index() );
		}

		if ( preg_match( '#^/sitemap-([a-z0-9-]+)\.xml$#', untrailingslashit( $path ), $m ) ) {
			$def = Cmdroom_Sitemap_Settings::get_definition( $m[1] );
			if ( ! $def ) {
				return; // deja que WordPress siga su curso normal (404)
			}
			self::output( Cmdroom_Sitemap_Render::render_definition( $def ), 'news' === $def['format'] ? Cmdroom_Sitemap_Render::NEWS_CACHE_TTL : null );
		}
	}

	private static function output( $xml, $page_cache_ttl = null ) {
		// Sin esto sale con cabecera 404 -- estas URLs no casan con ningún
		// post/página real, así que WP::handle_404() ya ha marcado la
		// petición como no encontrada antes de llegar a template_redirect
		// (mismo problema visto en /llms.txt, ver Cmdroom_Ia_Output). El
		// contenido salía correcto, solo la cabecera estaba mal -- lo
		// detectó el administrador 2026-09-24 probando la vista previa en la URL real.
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		// La caché de página (LiteSpeed) trata estas URLs como 404 -- ver
		// arriba -- y les aplica el TTL de los 404 (p. ej. 1 h). Para
		// News eso deja fuera durante una hora los posts recién publicados,
		// así que se le pide el mismo TTL que el transient del XML. Sin
		// LiteSpeed Cache activo la acción no tiene ningún efecto.
		if ( $page_cache_ttl ) {
			do_action( 'litespeed_control_set_ttl', (int) $page_cache_ttl );
		}
		echo $xml; // ya viene escapado como XML en Cmdroom_Sitemap_Render
		exit;
	}
}
