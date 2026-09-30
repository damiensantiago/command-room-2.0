<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajusta el <link rel="alternate" type="application/rss+xml"> que WordPress
 * imprime nativamente vía feed_links_extra() (wp-includes/default-filters.php,
 * prioridad 3 de wp_head) -- pedido por el administrador el 2026-09-24:
 *
 *  1. Posición: pedido por el administrador el 2026-09-24 -- debe salir justo después
 *     del <link rel="apple-touch-icon-precomposed"> del propio bloque de
 *     Metas (Cmdroom_Meta_Output::print_meta_tags(), colgado en wp_head con
 *     prioridad 1), antes de los scripts de datos estructurados. Se quita el
 *     hook nativo (prioridad 3) y se vuelve a enganchar a prioridad 1,
 *     registrado DESPUÉS de Cmdroom_Meta_Output::init() en el bootstrap
 *     (command-room.php) -- dentro del mismo cubo de prioridad, el orden de
 *     ejecución es el de registro, así que corre justo a continuación del
 *     bloque de Metas.
 *  2. Contenido: por defecto WordPress enlaza (a) en una entrada, el feed de
 *     COMENTARIOS de esa entrada concreta (get_post_comments_feed_link()), y
 *     (b) en un archivo de categoría, el feed nativo /categoria/feed/.
 *     Pedido explícito del administrador: ambos deben apuntar al feed "estilo Google
 *     News" de Cmdroom_Googlenews_Feed (URL /rss/googlenews/...xml, ver esa
 *     clase) -- en una entrada, el de su categoría principal (mismo criterio
 *     de "categoría principal" que ya usan Cmdroom_Meta_Variables/
 *     Cmdroom_Schema_Variables: primera categoría asignada al post); en un
 *     archivo de categoría, el de esa misma categoría.
 *
 * El resto de contextos (etiqueta, autor, búsqueda, CPT...) no se tocan --
 * feed_links_extra() nativo se reutiliza tal cual para todos ellos, solo se
 * mueve de sitio.
 *
 * Todo esto depende del toggle "Activar" de Configuración → RSS
 * (Cmdroom_Rss_Settings) -- si está desactivado, se cae de vuelta al
 * feed_links_extra() nativo de WordPress sin tocar nada.
 */
class Cmdroom_Feed_Links {

	public static function init() {
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		add_action( 'wp_head', array( __CLASS__, 'print_feed_links_extra' ), 1 );
	}

	public static function print_feed_links_extra() {
		if ( ! Cmdroom_Rss_Settings::is_enabled() ) {
			feed_links_extra();
			return;
		}
		if ( is_singular( 'post' ) ) {
			self::print_googlenews_link_for_post();
			return;
		}
		if ( is_category() ) {
			self::print_googlenews_link_for_category( get_queried_object() );
			return;
		}
		feed_links_extra();
	}

	private static function print_googlenews_link_for_post() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$terms = get_the_terms( $post, 'category' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return;
		}

		self::print_googlenews_link_for_category( $terms[0] );
	}

	private static function print_googlenews_link_for_category( $category ) {
		if ( ! ( $category instanceof WP_Term ) ) {
			return;
		}

		$title = sprintf(
			/* translators: 1: nombre del sitio, 2: separador, 3: nombre de la categoría */
			__( '%1$s %2$s %3$s Feed', 'command-room' ),
			get_bloginfo( 'name' ),
			'&raquo;',
			$category->name
		);

		printf(
			'<link rel="alternate" type="%s" title="%s" href="%s" />' . "\n",
			feed_content_type(),
			esc_attr( $title ),
			esc_url( Cmdroom_Googlenews_Feed::get_feed_url( $category ) )
		);
	}
}
