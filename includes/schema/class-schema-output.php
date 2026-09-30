<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imprime el @graph JSON-LD en wp_head — gated por "Salida en el sitio",
 * igual que el módulo de Metas, para convivir con Rank Math + EEAT Author
 * mientras se compara plantilla a plantilla.
 *
 * Vivía en wp_footer hasta el 2026-09-24 (Datos estructurados al final del
 * documento, después de todo lo demás). El administrador pidió que todos los bloques
 * de datos estructurados salgan juntos y seguidos -- pero DESPUÉS del bloque
 * de metas, no antes -- así que se mueve aquí con prioridad 2, justo
 * detrás de Cmdroom_Meta_Output::print_meta_tags() (colgado en 0/1), y
 * Cmdroom_Breadcrumbs_Jsonld va justo a continuación (prioridad 3) para que
 * los dos <script type="application/ld+json"> queden seguidos entre sí, sin
 * nada intercalado.
 */
class Cmdroom_Schema_Output {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_schema' ), 2 );
	}

	/**
	 * Tipos que, en un post, salen en su PROPIO <script> -- separado del
	 * @graph general y primero de todos -- en vez de ir dentro del @graph
	 * como un nodo más. Pedido explícito del administrador el 2026-09-24. Solo
	 * Article/NewsArticle (el nodo del propio contenido): Organization,
	 * BreadcrumbList, etc. siguen juntos en el @graph normal, justo después.
	 */
	const STANDALONE_TYPES = array( 'Article', 'NewsArticle' );

	private static function is_active() {
		return ! is_admin() && Cmdroom_Schema_Settings::is_live_output_enabled();
	}

	public static function print_schema() {
		if ( ! self::is_active() ) {
			return;
		}

		$graph = self::resolve_current();
		if ( ! $graph || empty( $graph['@graph'] ) ) {
			return;
		}

		// En un post, el nodo Article/NewsArticle sale primero y en su
		// propio <script> -- como array [ {...} ] con su propio @context
		// dentro de cada elemento, igual que el ejemplo real de un gran
		// medio usado de referencia el 2026-09-24, no como objeto envuelto
		// en @graph -- y el resto (Organization, BreadcrumbList si se
		// añadió como bloque, etc.) va justo detrás en el @graph normal.
		if ( is_singular( 'post' ) ) {
			$standalone = array();
			$rest       = array();
			foreach ( $graph['@graph'] as $node ) {
				if ( isset( $node['@type'] ) && in_array( $node['@type'], self::STANDALONE_TYPES, true ) ) {
					$standalone[] = array_merge( array( '@context' => 'https://schema.org' ), $node );
				} else {
					$rest[] = $node;
				}
			}
			if ( $standalone ) {
				self::print_script( $standalone );
			}
			$graph['@graph'] = $rest;
		}

		if ( ! empty( $graph['@graph'] ) ) {
			self::print_script( $graph );
		}
	}

	private static function print_script( $data ) {
		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
		);
	}

	public static function resolve_current() {
		// Mismo orden que Cmdroom_Meta_Output::resolve_current() y por la
		// misma razón: una portada estática es is_singular() Y
		// is_front_page() a la vez -- is_front_page() tiene que ir primero.
		// La página de entradas (is_home() sin ser portada, p. ej.
		// /blog/) se trata como la página que es, no como la home: si no,
		// heredaba título, descripción y canonical de la portada.
		if ( is_home() && ! is_front_page() && get_option( 'page_for_posts' ) ) {
			return Cmdroom_Schema_Builder::build_for_post( (int) get_option( 'page_for_posts' ) );
		}
		if ( is_front_page() || is_home() ) {
			return Cmdroom_Schema_Builder::build_for_home();
		}
		if ( is_singular() ) {
			return Cmdroom_Schema_Builder::build_for_post( get_queried_object_id() );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return Cmdroom_Schema_Builder::build_for_term( get_queried_object() );
		}
		if ( is_author() ) {
			return Cmdroom_Schema_Builder::build_for_author( get_queried_object() );
		}
		return null;
	}
}
