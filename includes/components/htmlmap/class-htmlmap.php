<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Command Room -- "Sitemap HTML" (clave de catálogo `htmlmap`).
 *
 * Capa de opciones + datos reales (WP_Query/$wpdb en modo lectura). La
 * consumen por igual la vista previa del admin (Cmdroom_Htmlmap_Settings)
 * y las páginas públicas (Cmdroom_Htmlmap_Frontend), así que admin y
 * front-end nunca se desincronizan.
 */
class Cmdroom_Htmlmap {

	const OPTION = 'cmdroom_htmlmap';

	const TOP_BY = array( 'views', 'comments', 'links' );

	const MESES = array(
		1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
		5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
		9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
	);

	const MESES_ABREV = array(
		1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr',
		5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago',
		9 => 'sept', 10 => 'oct', 11 => 'nov', 12 => 'dic',
	);

	const DEFAULTS = array(
		// Apagado de fábrica: al activarlo se publican URLs nuevas en el
		// sitio (/{slug}/...) y el enlace del footer.
		'enabled'     => false,
		'slug'        => 'site',
		'blocks'      => array(
			'd7'    => true,
			'd30'   => true,
			'm6'    => true,
			'years' => true,
			'top'   => true,
			'tags'  => true,
		),
		'top_by'      => 'views',
		'top_n'       => 10,
		'tag_n'       => 10,
		'per_day'     => 10,
		'empty'       => 'hide',
		'date_robots' => 'noindex',
		'post_types'  => array(
			'post'    => true,
			'page'    => false,
			'product' => false,
		),
		'footer_link' => true,
		'tags_link'   => true,
	);

	public static function init() {
		// Toggle instantáneo "Activar sitemap HTML", fuera del <form> de
		// guardado (mismo patrón que Afiliación/Reco). Enciende/apaga las
		// páginas públicas al momento: no hay rewrite rules que refrescar
		// (ver Cmdroom_Htmlmap_Frontend).
		add_action( 'wp_ajax_cmdroom_htmlmap_toggle_enabled', array( __CLASS__, 'handle_toggle_enabled' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Opciones                                                          */
	/* ---------------------------------------------------------------- */

	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$opts = array_replace_recursive( self::DEFAULTS, $saved );
		return $opts;
	}

	public static function update_options( $opts ) {
		update_option( self::OPTION, $opts );
	}

	/**
	 * Toggle instantáneo "Activar sitemap HTML" -- solo persiste `enabled`,
	 * que Cmdroom_Htmlmap_Frontend lee en cada petición.
	 */
	public static function handle_toggle_enabled() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cmdroom_htmlmap_toggle_enabled', '_wpnonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$opts            = self::get_options();
		$opts['enabled'] = ! empty( $_POST['value'] );
		self::update_options( $opts );
		wp_send_json_success( array( 'enabled' => $opts['enabled'] ) );
	}

	/* ---------------------------------------------------------------- */
	/* Tipos de contenido incluidos                                      */
	/* ---------------------------------------------------------------- */

	public static function enabled_post_types( $opts ) {
		$types = array();
		foreach ( (array) $opts['post_types'] as $slug => $on ) {
			if ( $on && post_type_exists( $slug ) ) {
				$types[] = $slug;
			}
		}
		return $types;
	}

	/* ---------------------------------------------------------------- */
	/* Fechas en español                                                 */
	/* ---------------------------------------------------------------- */

	public static function today() {
		$ts = current_time( 'timestamp' ); // hora del sitio (Europe/Madrid).
		return array( (int) date( 'Y', $ts ), (int) date( 'n', $ts ), (int) date( 'j', $ts ) );
	}

	public static function long_date( $y, $m, $d ) {
		return sprintf( '%d de %s de %d', $d, strtolower( self::MESES[ $m ] ), $y );
	}

	public static function month_year( $y, $m ) {
		return self::MESES[ $m ] . ' ' . $y;
	}

	public static function short_date( $y, $m, $d ) {
		return $d . ' ' . self::MESES_ABREV[ $m ];
	}

	/* ---------------------------------------------------------------- */
	/* Conteos reales (WP_Query / $wpdb, solo lectura)                   */
	/* ---------------------------------------------------------------- */

	private static function count_by_date_query( $types, $date_query ) {
		if ( empty( $types ) ) {
			return 0;
		}
		$q = new WP_Query( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'date_query'     => array( $date_query ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
			'ignore_sticky_posts' => true,
		) );
		return (int) $q->found_posts;
	}

	public static function count_year( $types, $y ) {
		return self::count_by_date_query( $types, array( 'year' => $y ) );
	}

	public static function count_month( $types, $y, $m ) {
		return self::count_by_date_query( $types, array( 'year' => $y, 'month' => $m ) );
	}

	public static function count_day( $types, $y, $m, $d ) {
		return self::count_by_date_query( $types, array( 'year' => $y, 'month' => $m, 'day' => $d ) );
	}

	/**
	 * Años con contenido, del más reciente al más antiguo, con su total.
	 * Una sola consulta agregada -- más barato que iterar year por year.
	 */
	public static function years_with_counts( $types ) {
		global $wpdb;
		if ( empty( $types ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql  = "SELECT YEAR(post_date) AS y, COUNT(*) AS c FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type IN ($placeholders)
				 GROUP BY y ORDER BY y DESC LIMIT 20";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $types ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->y ] = (int) $row->c;
		}
		return $out;
	}

	public static function months_with_counts( $types, $y ) {
		global $wpdb;
		$out = array_fill( 1, 12, 0 );
		if ( empty( $types ) ) {
			return $out;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql  = "SELECT MONTH(post_date) AS m, COUNT(*) AS c FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type IN ($placeholders) AND YEAR(post_date) = %d
				 GROUP BY m";
		$args = array_merge( $types, array( $y ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->m ] = (int) $row->c;
		}
		return $out;
	}

	public static function days_with_counts( $types, $y, $m ) {
		global $wpdb;
		$days_in_month = (int) date( 't', mktime( 0, 0, 0, $m, 1, $y ) );
		$out = array_fill( 1, $days_in_month, 0 );
		if ( empty( $types ) ) {
			return $out;
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql  = "SELECT DAY(post_date) AS d, COUNT(*) AS c FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type IN ($placeholders) AND YEAR(post_date) = %d AND MONTH(post_date) = %d
				 GROUP BY d";
		$args = array_merge( $types, array( $y, $m ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->d ] = (int) $row->c;
		}
		return $out;
	}

	/**
	 * Contenidos publicados en un día concreto, paginados (para el nivel
	 * Día de la vista previa).
	 */
	public static function day_posts( $types, $y, $m, $d, $page, $per_page ) {
		if ( empty( $types ) ) {
			return array( 'items' => array(), 'total' => 0, 'pages' => 0 );
		}
		$q = new WP_Query( array(
			'post_type'           => $types,
			'post_status'         => 'publish',
			'date_query'          => array( array( 'year' => $y, 'month' => $m, 'day' => $d ) ),
			'posts_per_page'      => $per_page,
			'paged'               => max( 1, (int) $page ),
			'orderby'             => 'date',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
		) );
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'tag'   => self::first_tag_name( $post->ID ),
			);
		}
		return array( 'items' => $items, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages );
	}

	/**
	 * Salta al día más cercano con contenido, sin pasar de hoy ni de más de
	 * 90 días de rango. Devuelve null si no hay ninguno en ese rango.
	 */
	public static function nearest_day_with_content( $types, $y, $m, $d, $direction ) {
		if ( empty( $types ) ) {
			return null;
		}
		list( $ty, $tm, $td ) = self::today();
		$today_ts = mktime( 0, 0, 0, $tm, $td, $ty );
		$cursor   = mktime( 0, 0, 0, $m, $d, $y );
		for ( $i = 1; $i <= 90; $i++ ) {
			$cursor += $direction * DAY_IN_SECONDS;
			if ( $cursor > $today_ts ) {
				return null;
			}
			$cy = (int) date( 'Y', $cursor );
			$cm = (int) date( 'n', $cursor );
			$cd = (int) date( 'j', $cursor );
			if ( self::count_day( $types, $cy, $cm, $cd ) > 0 ) {
				return array( $cy, $cm, $cd );
			}
		}
		return null;
	}

	/* — Portada: bloques — */

	public static function recent_week_items( $types, $limit = 50 ) {
		if ( empty( $types ) ) {
			return array();
		}
		$q = new WP_Query( array(
			'post_type'           => $types,
			'post_status'         => 'publish',
			'date_query'          => array( array( 'after' => '7 days ago' ) ),
			'posts_per_page'      => $limit,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		) );
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'date'  => self::short_date( (int) get_the_date( 'Y', $post ), (int) get_the_date( 'n', $post ), (int) get_the_date( 'j', $post ) ),
			);
		}
		return $items;
	}

	public static function recent_days_with_content( $types, $limit = 30 ) {
		global $wpdb;
		if ( empty( $types ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql  = "SELECT DATE(post_date) AS d, COUNT(*) AS c FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type IN ($placeholders) AND post_date >= %s
				 GROUP BY d ORDER BY d DESC";
		$since = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) - 29 * DAY_IN_SECONDS );
		$args  = array_merge( $types, array( $since ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$ts    = strtotime( $row->d );
			$out[] = array(
				'y' => (int) date( 'Y', $ts ),
				'm' => (int) date( 'n', $ts ),
				'd' => (int) date( 'j', $ts ),
				'c' => (int) $row->c,
			);
		}
		return array_slice( $out, 0, $limit );
	}

	public static function recent_months_with_counts( $types, $limit = 6 ) {
		list( $ty, $tm ) = self::today();
		$out = array();
		for ( $i = 0; $i < $limit; $i++ ) {
			$ts = mktime( 0, 0, 0, $tm - $i, 1, $ty );
			$y  = (int) date( 'Y', $ts );
			$m  = (int) date( 'n', $ts );
			$out[] = array( 'y' => $y, 'm' => $m, 'c' => self::count_month( $types, $y, $m ) );
		}
		return $out;
	}

	/* — Tags — */

	public static function all_tags_with_counts() {
		$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => true ) );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			$out[] = array( 'id' => $t->term_id, 'name' => $t->name, 'count' => (int) $t->count );
		}
		return $out;
	}

	public static function tags_by_letter() {
		$tags   = self::all_tags_with_counts();
		$groups = array();
		foreach ( $tags as $tag ) {
			$plain  = remove_accents( $tag['name'] );
			$letter = mb_strtoupper( mb_substr( $plain, 0, 1 ) );
			if ( ! isset( $groups[ $letter ] ) ) {
				$groups[ $letter ] = array();
			}
			$groups[ $letter ][] = $tag;
		}
		$letters = array_keys( $groups );
		usort( $letters, function ( $a, $b ) {
			return strcoll( $a, $b );
		} );
		$ordered = array();
		foreach ( $letters as $letter ) {
			$items = $groups[ $letter ];
			usort( $items, function ( $a, $b ) {
				return strcoll( remove_accents( $a['name'] ), remove_accents( $b['name'] ) );
			} );
			$ordered[ $letter ] = $items;
		}
		return $ordered;
	}

	public static function top_tags( $n ) {
		$tags = self::all_tags_with_counts();
		usort( $tags, function ( $a, $b ) {
			return $b['count'] - $a['count'];
		} );
		return array_slice( $tags, 0, max( 1, (int) $n ) );
	}

	/* — Top contenidos — */

	private static function first_tag_name( $post_id ) {
		$tags = get_the_tags( $post_id );
		return ( $tags && ! is_wp_error( $tags ) ) ? $tags[0]->name : '';
	}

	private static function primary_category_name( $post_id ) {
		$cats = get_the_category( $post_id );
		return ( $cats && ! empty( $cats[0] ) ) ? $cats[0]->name : '';
	}

	/**
	 * "Más enlazados internamente" -- no hay tabla propia de enlaces, así
	 * que se aproxima escaneando el contenido de los últimos N publicados
	 * en busca de enlaces a otras entradas del propio sitio (url_to_postid)
	 * y se cachea 12h en un transient para no repetir el escaneo en cada
	 * carga de la vista previa. Con volúmenes de contenido grandes (miles
	 * de posts) esto se queda corto a propósito -- se limita a los 300 más
	 * recientes en vez de escanear todo el sitio.
	 */
	private static function internal_links_ranking( $types ) {
		if ( empty( $types ) ) {
			return array();
		}
		$cache_key = 'cmdroom_htmlmap_links_' . md5( implode( ',', $types ) );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		$q = new WP_Query( array(
			'post_type'           => $types,
			'post_status'         => 'publish',
			'posts_per_page'      => 300,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		) );
		$tally = array();
		$home  = home_url();
		foreach ( $q->posts as $post ) {
			if ( ! preg_match_all( '/href=["\']([^"\']+)["\']/i', $post->post_content, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $href ) {
				if ( false === strpos( $href, $home ) ) {
					continue;
				}
				$target = url_to_postid( $href );
				if ( $target && $target !== $post->ID ) {
					$tally[ $target ] = isset( $tally[ $target ] ) ? $tally[ $target ] + 1 : 1;
				}
			}
		}
		arsort( $tally );
		set_transient( $cache_key, $tally, 12 * HOUR_IN_SECONDS );
		return $tally;
	}

	/**
	 * Top contenidos según el criterio elegido. "views" no tiene
	 * infraestructura real (sin tabla de estadísticas propia ni Jetpack) --
	 * cae a "más recientes" como sustituto razonable, y se marca en el
	 * `criterio_real` devuelto para que el admin pueda avisarlo si hace
	 * falta.
	 */
	public static function top_content( $types, $by, $n ) {
		$n = max( 1, (int) $n );
		if ( empty( $types ) ) {
			return array( 'items' => array(), 'criterio_real' => $by );
		}

		if ( 'comments' === $by ) {
			$q = new WP_Query( array(
				'post_type'           => $types,
				'post_status'         => 'publish',
				'posts_per_page'      => $n,
				'orderby'             => 'comment_count',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
			) );
			$posts = $q->posts;
			$real  = 'comments';
		} elseif ( 'links' === $by ) {
			$tally = self::internal_links_ranking( $types );
			if ( empty( $tally ) ) {
				// Sin enlaces internos detectados todavía -- sustituto: recientes.
				$q     = new WP_Query( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => $n, 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true ) );
				$posts = $q->posts;
				$real  = 'date';
			} else {
				$ids   = array_slice( array_keys( $tally ), 0, $n );
				$posts = array_filter( array_map( 'get_post', $ids ) );
				$real  = 'links';
			}
		} else {
			// 'views': sin infraestructura real -- sustituto: más recientes.
			$q     = new WP_Query( array( 'post_type' => $types, 'post_status' => 'publish', 'posts_per_page' => $n, 'orderby' => 'date', 'order' => 'DESC', 'ignore_sticky_posts' => true ) );
			$posts = $q->posts;
			$real  = 'date (sustituto de "views", sin infraestructura de analítica propia)';
		}

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'category' => self::primary_category_name( $post->ID ),
			);
		}
		return array( 'items' => $items, 'criterio_real' => $real );
	}
}
