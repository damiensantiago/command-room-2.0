<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ejecuta las reglas de limpieza configuradas en Cmdroom_Cleanup_Settings.
 * Los hooks se registran siempre y cada callback comprueba su propio toggle
 * al vuelo — así un cambio de ajustes se aplica en la siguiente petición
 * sin depender de cuándo se cargó init().
 *
 * Desde el rediseño "Servidor" (2026-09-23): https, www, /category/,
 * adjuntos y barra final se resuelven todos en un único paso
 * (maybe_normalize_request(), template_redirect prioridad -10, ANTES que
 * Cmdroom_Redirect_Matcher en prioridad 0) que calcula la URL final
 * completa y redirige una sola vez — nunca se encadenan varios
 * wp_redirect() para la misma petición. Los parámetros de campaña (utm,
 * fbclid, gclid) no redirigen: se limpian solo del canonical nativo de WP.
 * Las tres reglas heredadas
 * (X-Pingback, generator, versión en assets) siguen igual que antes.
 */
class Cmdroom_Cleanup {

	const UTM_PARAMS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_normalize_request' ), -10 );

		add_action( 'init', array( __CLASS__, 'maybe_register_catbase_rewrite' ) );
		add_filter( 'category_link', array( __CLASS__, 'maybe_strip_category_base' ) );
		add_action( 'init', array( __CLASS__, 'maybe_register_author_rewrite' ) );
		add_filter( 'author_link', array( __CLASS__, 'maybe_strip_author_front' ) );
		add_action( 'created_category', array( __CLASS__, 'schedule_catbase_flush' ) );
		add_action( 'edited_category', array( __CLASS__, 'schedule_catbase_flush' ) );
		add_action( 'delete_category', array( __CLASS__, 'schedule_catbase_flush' ) );
		add_filter( 'get_canonical_url', array( __CLASS__, 'maybe_strip_utm_from_canonical' ) );

		add_filter( 'wp_headers', array( __CLASS__, 'maybe_remove_x_pingback_header' ) );
		add_action( 'init', array( __CLASS__, 'maybe_remove_generator' ) );
		add_filter( 'style_loader_src', array( __CLASS__, 'maybe_remove_core_version_query_arg' ), 9999 );
		add_filter( 'script_loader_src', array( __CLASS__, 'maybe_remove_core_version_query_arg' ), 9999 );
	}

	/**
	 * "Quitar /category/ de la URL" sin vaciar category_base a ciegas (eso
	 * puede provocar colisiones de slugs de término contra páginas).
	 *
	 * Una regla explícita por categoría existente (listado, paginación y
	 * feed), como hace Rank Math con su "Strip Category Base". Hasta
	 * 2026-09-26 era una única regla genérica '^([^/]+)/?$' en 'bottom', pero
	 * con una estructura de enlaces con prefijo (p. ej.
	 * /blog/%postname%/) las reglas de páginas de WordPress la tapaban, y además no
	 * cubría /cat-x/page/2/ ni /cat-x/feed/ -- visto al apagar Rank Math en
	 * un sitio de pruebas. Al coincidir solo con slugs de categoría reales no
	 * puede comerse una página con otro slug.
	 */
	public static function maybe_register_catbase_rewrite() {
		if ( ! Cmdroom_Cleanup_Settings::is_enabled( 'catbase' ) ) {
			return;
		}
		$terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
		if ( is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			$path = $term->slug;
			foreach ( get_ancestors( $term->term_id, 'category', 'taxonomy' ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, 'category' );
				if ( $ancestor && ! is_wp_error( $ancestor ) ) {
					$path = $ancestor->slug . '/' . $path;
				}
			}
			$quoted = preg_quote( $path, '#' );
			add_rewrite_rule( '^(' . $quoted . ')/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$', 'index.php?category_name=$matches[1]&feed=$matches[2]', 'top' );
			add_rewrite_rule( '^(' . $quoted . ')/page/?([0-9]{1,})/?$', 'index.php?category_name=$matches[1]&paged=$matches[2]', 'top' );
			add_rewrite_rule( '^(' . $quoted . ')/?$', 'index.php?category_name=$matches[1]', 'top' );
		}
	}

	/**
	 * Las reglas de arriba dependen de la lista de categorías: al crear,
	 * renombrar o borrar una hay que regenerarlas (lo mismo que hace Rank
	 * Math). El flush se aplaza al final de la petición para hacerlo una
	 * sola vez aunque se toquen varias categorías seguidas.
	 */
	public static function schedule_catbase_flush() {
		if ( ! Cmdroom_Cleanup_Settings::is_enabled( 'catbase' ) ) {
			return;
		}
		update_option( 'cmdroom_flush_rewrites', 1 );
	}

	public static function maybe_strip_category_base( $link ) {
		if ( ! Cmdroom_Cleanup_Settings::is_enabled( 'catbase' ) ) {
			return $link;
		}
		// Quita también el prefijo de la estructura de enlaces: con
		// /blog/%postname%/ WordPress genera /blog/category/x/, y quitar solo
		// /category/ dejaba /blog/x/, que da 404 (visto al apagar Rank
		// Math, 2026-09-26).
		$base   = get_option( 'category_base' ) ? trim( get_option( 'category_base' ), '/' ) : 'category';
		$prefix = home_url( self::permalink_front() . $base . '/' );
		return 0 === strpos( $link, $prefix ) ? home_url( '/' ) . substr( $link, strlen( $prefix ) ) : str_replace( '/' . $base . '/', '/', $link );
	}

	/**
	 * Prefijo fijo de la estructura de enlaces (p. ej. '/blog/', '/' si
	 * no hay), el mismo que WordPress antepone a categorías y autores.
	 */
	private static function permalink_front() {
		global $wp_rewrite;
		return ( $wp_rewrite && $wp_rewrite->front ) ? $wp_rewrite->front : '/';
	}

	public static function maybe_register_author_rewrite() {
		if ( ! Cmdroom_Cleanup_Settings::is_enabled( 'authorfront' ) || '/' === self::permalink_front() ) {
			return;
		}
		add_rewrite_rule( '^author/([^/]+)/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$', 'index.php?author_name=$matches[1]&feed=$matches[2]', 'top' );
		add_rewrite_rule( '^author/([^/]+)/page/?([0-9]{1,})/?$', 'index.php?author_name=$matches[1]&paged=$matches[2]', 'top' );
		add_rewrite_rule( '^author/([^/]+)/?$', 'index.php?author_name=$matches[1]', 'top' );
	}

	public static function maybe_strip_author_front( $link ) {
		$front = self::permalink_front();
		if ( ! Cmdroom_Cleanup_Settings::is_enabled( 'authorfront' ) || '/' === $front ) {
			return $link;
		}
		return str_replace( home_url( $front . 'author/' ), home_url( '/author/' ), $link );
	}

	public static function maybe_strip_utm_from_canonical( $canonical ) {
		if ( ! $canonical || ! Cmdroom_Cleanup_Settings::is_enabled( 'utm' ) ) {
			return $canonical;
		}
		return remove_query_arg( self::UTM_PARAMS, $canonical );
	}

	/**
	 * Paso único de normalización de la petición: https, www canónico,
	 * adjuntos, /category/ legado y barra final. Si el resultado difiere de
	 * la URL actual, UN solo redirect 301 -- nunca varios.
	 */
	public static function maybe_normalize_request() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_doing_cron() ) {
			return;
		}

		$clean = array();
		foreach ( array( 'https', 'www', 'attach', 'catbase', 'slash', 'strip_replytocom' ) as $rule ) {
			$clean[ $rule ] = Cmdroom_Cleanup_Settings::is_enabled( $rule );
		}
		if ( ! array_filter( $clean ) ) {
			return;
		}

		// Caso especial: página de adjunto -- redirige a una URL
		// completamente distinta (el archivo o la entrada padre); el resto
		// de reglas de transporte (https/www) se aplican sobre ESA url
		// antes de redirigir, y ahí termina el proceso para esta petición.
		if ( $clean['attach'] && is_attachment() ) {
			$target = self::attachment_target_url();
			if ( $target ) {
				self::redirect_to( self::apply_scheme_and_host( $target, $clean ) );
			}
			return;
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		$transport_changed = false;

		if ( $clean['https'] && ! is_ssl() ) {
			$scheme = 'https';
			$transport_changed = true;
		}

		if ( $clean['www'] ) {
			$desired_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $desired_host && $host && strtolower( $host ) !== strtolower( $desired_host ) ) {
				$host = $desired_host;
				$transport_changed = true;
			}
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
		$parts = explode( '?', $uri, 2 );
		$path  = $parts[0];
		$query = isset( $parts[1] ) ? $parts[1] : '';
		$path_changed = false;

		if ( $clean['catbase'] && 0 === strpos( $path, '/category/' ) ) {
			$path = substr( $path, strlen( '/category' ) );
			$path_changed = true;
		}

		if ( $clean['slash'] && '' === $query && ! preg_match( '/\.[a-z0-9]{1,6}$/i', $path ) ) {
			$wants_slash = Cmdroom_Cleanup_Settings::wants_trailing_slash();
			$has_slash   = '/' === substr( $path, -1 );
			if ( $wants_slash && ! $has_slash && '' !== $path ) {
				$path .= '/';
				$path_changed = true;
			} elseif ( ! $wants_slash && $has_slash && '/' !== $path ) {
				$path = untrailingslashit( $path );
				$path_changed = true;
			}
		}

		$query_changed = false;
		if ( $clean['strip_replytocom'] && $query && false !== strpos( $query, 'replytocom' ) ) {
			parse_str( $query, $query_args );
			if ( isset( $query_args['replytocom'] ) ) {
				unset( $query_args['replytocom'] );
				$query = http_build_query( $query_args );
				$query_changed = true;
			}
		}

		if ( ! $transport_changed && ! $path_changed && ! $query_changed ) {
			return;
		}

		self::redirect_to( $scheme . '://' . $host . $path . ( $query ? '?' . $query : '' ) );
	}

	private static function attachment_target_url() {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$file_url = wp_get_attachment_url( $post->ID );
		if ( $file_url ) {
			return $file_url;
		}
		if ( $post->post_parent ) {
			$parent_link = get_permalink( $post->post_parent );
			if ( $parent_link ) {
				return $parent_link;
			}
		}
		return home_url( '/' );
	}

	private static function apply_scheme_and_host( $url, $clean ) {
		if ( ! empty( $clean['https'] ) ) {
			$url = set_url_scheme( $url, 'https' );
		}
		if ( ! empty( $clean['www'] ) ) {
			$desired_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$parts        = wp_parse_url( $url );
			if ( $desired_host && ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== strtolower( $desired_host ) ) {
				$url = preg_replace( '#^(https?://)[^/]+#i', '$1' . $desired_host, $url );
			}
		}
		return $url;
	}

	/**
	 * wp_redirect() a propósito, no wp_safe_redirect(): el host de destino
	 * (cuando cambia por "www") viene de home_url(), no de input del
	 * usuario, así que ya está validado -- wp_safe_redirect() lo rechazaría
	 * por no coincidir con el host de la petición actual.
	 */
	private static function redirect_to( $url ) {
		wp_redirect( $url, 301 );
		exit;
	}

	public static function maybe_remove_x_pingback_header( $headers ) {
		if ( Cmdroom_Cleanup_Settings::is_enabled( 'remove_x_pingback' ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}

	public static function maybe_remove_generator() {
		if ( Cmdroom_Cleanup_Settings::is_enabled( 'remove_generator' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
	}

	public static function maybe_remove_core_version_query_arg( $src ) {
		if ( ! Cmdroom_Cleanup_Settings::is_enabled( 'remove_wp_version_strings' ) ) {
			return $src;
		}
		if ( $src && false !== strpos( $src, 'ver=' . get_bloginfo( 'version' ) ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}
}
