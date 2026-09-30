<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fuente única de las migas de pan: el mismo cálculo alimenta el
 * BreadcrumbList del schema y el HTML visual (shortcode/función de tema).
 * No depende de funciones de migas del tema (suelen pintar un array que
 * cada plantilla construye a mano). Aquí se recalcula con el criterio estándar de
 * WordPress para que sirva igual en cualquier sitio.
 */
class Cmdroom_Breadcrumbs {

	public static function init() {
		add_shortcode( 'cmdroom_breadcrumbs', array( __CLASS__, 'shortcode' ) );
	}

	public static function get_items_for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$opts  = Cmdroom_Breadcrumb_Settings::get_options();
		$items = self::maybe_home_item();

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$ancestors = array_reverse( get_post_ancestors( $post ) );
			foreach ( $ancestors as $ancestor_id ) {
				$items[] = array( 'name' => get_the_title( $ancestor_id ), 'url' => get_permalink( $ancestor_id ) );
			}
		} elseif ( ! empty( $opts['cat'] ) ) {
			$primary = self::get_primary_category( $post );
			if ( $primary ) {
				$items[] = array( 'name' => $primary->name, 'url' => get_term_link( $primary ) );
			}
		}

		$items[] = array( 'name' => get_the_title( $post ), 'url' => get_permalink( $post ) );

		return $items;
	}

	/**
	 * Categoría principal de un post: `_cr_primary_term` (aún no la escribe
	 * ningún editor — punto de enganche para cuando el módulo de Metas tenga
	 * un selector de categoría principal) o, si no existe, la primera por
	 * term_order.
	 */
	private static function get_primary_category( WP_Post $post ) {
		$primary_id = get_post_meta( $post->ID, '_cr_primary_term', true );
		if ( $primary_id ) {
			$term = get_term( (int) $primary_id, 'category' );
			if ( $term && ! is_wp_error( $term ) ) {
				return $term;
			}
		}
		$terms = get_the_terms( $post, 'category' );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
	}

	public static function get_items_for_term( WP_Term $term ) {
		$items = self::maybe_home_item();

		$ancestors = array_reverse( get_ancestors( $term->term_id, $term->taxonomy ) );
		foreach ( $ancestors as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				$items[] = array( 'name' => $ancestor->name, 'url' => get_term_link( $ancestor ) );
			}
		}

		$items[] = array( 'name' => $term->name, 'url' => get_term_link( $term ) );

		return $items;
	}

	public static function get_items_for_home() {
		$opts = Cmdroom_Breadcrumb_Settings::get_options();
		return array( array( 'name' => $opts['home_text'], 'url' => home_url( '/' ) ) );
	}

	public static function get_items_for_search( $query ) {
		$opts  = Cmdroom_Breadcrumb_Settings::get_options();
		$items = self::maybe_home_item();
		$items[] = array( 'name' => trim( $opts['search_prefix'] . ' "' . $query . '"' ), 'url' => '' );
		return $items;
	}

	public static function get_items_for_404() {
		$opts  = Cmdroom_Breadcrumb_Settings::get_options();
		$items = self::maybe_home_item();
		$items[] = array( 'name' => $opts['label_404'], 'url' => '' );
		return $items;
	}

	/**
	 * Autodetecta el contexto de la página actual — para el shortcode y la
	 * función de tema. El schema (JSON-LD) no usa esto: cada módulo llama
	 * directamente al método que le corresponde con su propio objeto.
	 * Devuelve null entero si "Activar breadcrumbs" está OFF.
	 */
	public static function get_current_items() {
		if ( ! Cmdroom_Breadcrumb_Settings::is_enabled() ) {
			return null;
		}

		if ( is_front_page() && ! is_singular() ) {
			return self::get_items_for_home();
		}
		if ( is_singular() ) {
			return self::get_items_for_post( get_queried_object_id() );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return self::get_items_for_term( get_queried_object() );
		}
		if ( is_search() ) {
			return self::get_items_for_search( get_search_query() );
		}
		if ( is_404() ) {
			return self::get_items_for_404();
		}
		return null;
	}

	private static function maybe_home_item() {
		$opts = Cmdroom_Breadcrumb_Settings::get_options();
		return ! empty( $opts['home'] ) ? array( array( 'name' => $opts['home_text'], 'url' => home_url( '/' ) ) ) : array();
	}

	public static function shortcode() {
		$items = self::get_current_items();
		return $items ? self::render_html( $items ) : '';
	}

	public static function render_html( $items ) {
		if ( empty( $items ) ) {
			return '';
		}

		$opts      = Cmdroom_Breadcrumb_Settings::get_options();
		$last      = count( $items ) - 1;
		$separator = '<span class="cmdroom-crumb-sep" aria-hidden="true">' . esc_html( $opts['sep'] ) . '</span>';

		$html = '<nav class="cmdroom-breadcrumbs" aria-label="' . esc_attr__( 'Migas de pan', 'command-room' ) . '">';

		if ( '' !== trim( $opts['prefix'] ) ) {
			$html .= '<span class="cmdroom-crumb-prefix">' . esc_html( $opts['prefix'] ) . ' </span>';
		}

		foreach ( $items as $i => $item ) {
			if ( $i > 0 ) {
				$html .= $separator;
			}

			$is_current = ( $i === $last );

			if ( $is_current && $opts['bold_last'] ) {
				$html .= '<span class="cmdroom-crumb-current" aria-current="page">' . esc_html( $item['name'] ) . '</span>';
			} elseif ( empty( $item['url'] ) ) {
				$html .= '<span>' . esc_html( $item['name'] ) . '</span>';
			} else {
				$html .= '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['name'] ) . '</a>';
			}
		}

		$html .= '</nav>';

		return $html;
	}
}

/**
 * Función de tema — la forma directa de imprimir las migas sin pasar por
 * el shortcode, para temas que quieran engancharla en su plantilla.
 */
function cmdroom_the_breadcrumbs() {
	$items = Cmdroom_Breadcrumbs::get_current_items();
	if ( $items ) {
		echo Cmdroom_Breadcrumbs::render_html( $items ); // ya viene escapado en render_html()
	}
}
