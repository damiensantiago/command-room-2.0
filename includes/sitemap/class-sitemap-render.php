<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Genera el XML de cada sitemap on-demand, con caché en transient (no
 * escribe archivos: muchos hostings compartidos limitan la
 * escritura en disco). La caché se invalida sola al publicar/editar/
 * borrar contenido.
 */
class Cmdroom_Sitemap_Render {

	const CACHE_TTL      = 12 * HOUR_IN_SECONDS;
	const NEWS_CACHE_TTL = 5 * MINUTE_IN_SECONDS; // News cambia rápido: 12h dejaría servir un sitemap con artículos ya fuera de la ventana de 48h. 5 min (el administrador, 2026-09-29): un post nuevo aparece como mucho 5 min después
	const NEWS_WINDOW_HOURS = 48; // límite que exige Google News: fuera de ahí el artículo sale del sitemap (sigue existiendo, solo deja de anunciarse como noticia)

	public static function init() {
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}
	}

	/**
	 * La versión del plugin va en la clave: al actualizar, los sitemaps se
	 * regeneran en vez de servir hasta CACHE_TTL el XML de la versión
	 * anterior (p. ej. con URLs que la nueva ya excluye).
	 */
	private static function cache_key( $slug ) {
		return 'cmdroom_sitemap_' . $slug . '_' . CMDROOM_VERSION;
	}

	public static function flush_cache() {
		delete_transient( self::cache_key( 'index' ) );
		foreach ( Cmdroom_Sitemap_Settings::get_enabled_definitions() as $def ) {
			delete_transient( self::cache_key( $def['slug'] ) );
		}
	}

	public static function render_index() {
		$cached = get_transient( self::cache_key( 'index' ) );
		if ( false !== $cached ) {
			return $cached;
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( Cmdroom_Sitemap_Settings::get_enabled_definitions() as $def ) {
			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . esc_xml( home_url( '/sitemap-' . $def['slug'] . '.xml' ) ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . esc_xml( self::latest_modified( $def ) ) . "</lastmod>\n";
			$xml .= "\t</sitemap>\n";
		}

		$xml .= '</sitemapindex>';

		set_transient( self::cache_key( 'index' ), $xml, self::CACHE_TTL );
		return $xml;
	}

	public static function render_definition( $def ) {
		if ( 'news' === ( $def['format'] ?? 'standard' ) ) {
			return self::render_news_definition( $def );
		}

		$cache_key = self::cache_key( $def['slug'] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$entries = 'terms' === ( $def['source'] ?? 'posts' )
			? self::query_term_entries( $def )
			: self::query_post_entries( $def );

		$with_images = ! empty( $def['images'] );
		$with_videos = ! empty( $def['videos'] );
		$namespaces  = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		if ( $with_images ) {
			$namespaces .= ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		}
		if ( $with_videos ) {
			$namespaces .= ' xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"';
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset ' . $namespaces . '>' . "\n";

		foreach ( $entries as $entry ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_xml( $entry['url'] ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . esc_xml( $entry['lastmod'] ) . "</lastmod>\n";

			if ( $with_images && ! empty( $entry['images'] ) ) {
				foreach ( $entry['images'] as $image ) {
					$xml .= "\t\t<image:image>\n";
					$xml .= "\t\t\t<image:loc>" . esc_xml( $image['url'] ) . "</image:loc>\n";
					if ( ! empty( $image['title'] ) ) {
						$xml .= "\t\t\t<image:title>" . esc_xml( $image['title'] ) . "</image:title>\n";
					}
					$xml .= "\t\t</image:image>\n";
				}
			}

			if ( $with_videos && ! empty( $entry['videos'] ) ) {
				foreach ( $entry['videos'] as $video ) {
					$xml .= "\t\t<video:video>\n";
					$xml .= "\t\t\t<video:thumbnail_loc>" . esc_xml( $video['thumbnail'] ) . "</video:thumbnail_loc>\n";
					$xml .= "\t\t\t<video:title>" . esc_xml( $video['title'] ) . "</video:title>\n";
					$xml .= "\t\t\t<video:description>" . esc_xml( $video['description'] ) . "</video:description>\n";
					if ( ! empty( $video['content_loc'] ) ) {
						$xml .= "\t\t\t<video:content_loc>" . esc_xml( $video['content_loc'] ) . "</video:content_loc>\n";
					}
					if ( ! empty( $video['player_loc'] ) ) {
						$xml .= "\t\t\t<video:player_loc>" . esc_xml( $video['player_loc'] ) . "</video:player_loc>\n";
					}
					$xml .= "\t\t</video:video>\n";
				}
			}

			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>';

		set_transient( $cache_key, $xml, self::CACHE_TTL );
		return $xml;
	}

	/**
	 * Sitemap de Google News: namespace news:news, solo posts publicados en
	 * las últimas 48h (fuera de esa ventana Google pide sacarlos del
	 * sitemap — el artículo sigue existiendo, solo deja de "anunciarse").
	 * Ignora "terms" como origen: un archivo de categoría no es una noticia.
	 *
	 * Idioma por definición (`$def['lang']`) desde el rediseño de Claude
	 * Design -- antes era una única opción global para todo el sitio, lo
	 * que no tenía sentido si dos sitemaps News apuntan a audiencias en
	 * idiomas distintos.
	 */
	private static function render_news_definition( $def ) {
		$cache_key = self::cache_key( $def['slug'] );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$publication_name = Cmdroom_Sitemap_Settings::get_news_publication_name();
		$language         = ! empty( $def['lang'] ) ? $def['lang'] : 'es';
		$vars             = wp_parse_args( isset( $def['vars'] ) ? $def['vars'] : array(), Cmdroom_Sitemap_Settings::VAR_DEFAULTS );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ( self::query_news_post_ids( $def ) as $post_id ) {
			$context = array( 'post' => get_post( $post_id ) );

			$headline = self::resolve_var( $vars['title'], $context );
			if ( '' === $headline ) {
				$headline = wp_trim_words( get_the_title( $post_id ), 20, '' );
			}

			$pub_date = self::resolve_var( $vars['pub_date'], $context );
			if ( '' === $pub_date ) {
				$pub_date = get_the_date( 'c', $post_id );
			}

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_xml( get_permalink( $post_id ) ) . "</loc>\n";
			$xml .= "\t\t<news:news>\n";
			$xml .= "\t\t\t<news:publication>\n";
			$xml .= "\t\t\t\t<news:name>" . esc_xml( $publication_name ) . "</news:name>\n";
			$xml .= "\t\t\t\t<news:language>" . esc_xml( $language ) . "</news:language>\n";
			$xml .= "\t\t\t</news:publication>\n";
			$xml .= "\t\t\t<news:publication_date>" . esc_xml( $pub_date ) . "</news:publication_date>\n";
			$xml .= "\t\t\t<news:title>" . esc_xml( $headline ) . "</news:title>\n";
			$xml .= "\t\t</news:news>\n";
			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>';

		set_transient( $cache_key, $xml, self::NEWS_CACHE_TTL );
		return $xml;
	}

	private static function query_news_post_ids( $def ) {
		$args = array(
			'post_type'      => $def['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => min( (int) $def['limit'], 1000 ), // 1000 es el máximo que admite Google News por sitemap
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'date_query'     => array(
				array( 'after' => self::NEWS_WINDOW_HOURS . ' hours ago' ),
			),
		);

		if ( ! empty( $def['taxonomy'] ) && ! empty( $def['terms'] ) ) {
			$terms = array_filter( array_map( 'trim', explode( ',', $def['terms'] ) ) );
			if ( $terms ) {
				$args['tax_query'] = array( array(
					'taxonomy' => $def['taxonomy'],
					'field'    => 'slug',
					'terms'    => $terms,
				) );
			}
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}

	/**
	 * Modo "posts": URLs de entradas/páginas de un post type, opcionalmente
	 * acotadas a los términos de una taxonomía. `lastmod`, la imagen
	 * principal y el vídeo salen de las variables configuradas en la
	 * pestaña "Variables" de la definición (ver resolve_var()).
	 */
	private static function query_post_entries( $def ) {
		$with_images = ! empty( $def['images'] );
		$with_videos = ! empty( $def['videos'] );
		$vars        = wp_parse_args( isset( $def['vars'] ) ? $def['vars'] : array(), Cmdroom_Sitemap_Settings::VAR_DEFAULTS );

		$entries = array();
		foreach ( self::query_post_ids( $def ) as $post_id ) {
			$context = array( 'post' => get_post( $post_id ) );

			// Fuera del sitemap lo que no se quiere indexado: noindex (del
			// post o de las reglas de Metas) y URLs que redirigen con una
			// regla propia -- Rank Math también las excluía. Visto en una
			// migración real (2026-09-26): salían las páginas legales y 4
			// páginas viejas redirigidas.
			$robots = Cmdroom_Meta_Resolver::resolve_robots_for_context( $context );
			if ( ! empty( $robots['noindex'] ) ) {
				continue;
			}
			$path = trim( (string) wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH ), '/' );
			if ( '' !== $path && Cmdroom_Redirect_Admin::is_live_output_enabled() && Cmdroom_Redirect_Table::get_by_source( $path ) ) {
				continue;
			}

			$lastmod = self::resolve_var( $vars['lastmod'], $context );
			if ( '' === $lastmod ) {
				$lastmod = get_post_modified_time( 'c', false, $post_id );
			}

			$entry = array(
				'url'     => get_permalink( $post_id ),
				'lastmod' => $lastmod,
			);

			if ( $with_images ) {
				$entry['images'] = self::get_post_image_urls(
					$post_id,
					self::resolve_var( $vars['image'], $context ),
					self::resolve_var( $vars['image_title'], $context )
				);
			}

			if ( $with_videos ) {
				$entry['videos'] = self::get_post_video_entries( $post_id, $vars, $context );
			}

			$entries[] = $entry;
		}
		return $entries;
	}

	/**
	 * Imagen "principal" (la que resuelve la variable elegida en Variables
	 * → Imagen/Título de imagen) + imagen destacada + imágenes embebidas en
	 * el contenido (hasta un límite razonable) -- cubre el caso típico de
	 * posts sin imagen destacada asignada pero con fotos dentro del cuerpo.
	 * Solo la principal lleva <image:title>; las demás no tienen un título
	 * propio que resolver.
	 *
	 * @return array[] Lista de ['url' => ..., 'title' => ...].
	 */
	private static function get_post_image_urls( $post_id, $primary_url, $primary_title ) {
		$images = array();

		if ( $primary_url ) {
			$images[] = array( 'url' => $primary_url, 'title' => $primary_title );
		}

		$thumb_id = get_post_thumbnail_id( $post_id );
		if ( $thumb_id ) {
			$thumb_url = wp_get_attachment_image_url( $thumb_id, 'full' );
			if ( $thumb_url && ! self::images_contain( $images, $thumb_url ) ) {
				$images[] = array( 'url' => $thumb_url, 'title' => '' );
			}
		}

		$post = get_post( $post_id );
		if ( $post && preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches ) ) {
			foreach ( array_slice( $matches[1], 0, 10 ) as $src ) {
				if ( ! self::images_contain( $images, $src ) ) {
					$images[] = array( 'url' => $src, 'title' => '' );
				}
			}
		}

		return array_slice( $images, 0, 20 );
	}

	private static function images_contain( $images, $url ) {
		foreach ( $images as $image ) {
			if ( $image['url'] === $url ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Vídeos embebidos en el contenido: <video><source src="..."> propio
	 * (content_loc) y iframes de YouTube/Vimeo (player_loc) -- hasta 5 por
	 * post. thumbnail_loc/title/description son obligatorios en el esquema
	 * de vídeo de Google; si no hay ninguna miniatura disponible (ni la
	 * variable configurada ni la imagen destacada), se omite el vídeo
	 * entero en vez de emitir un <video:video> incompleto/inválido.
	 */
	private static function get_post_video_entries( $post_id, $vars, $context ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$thumbnail = self::resolve_var( $vars['image'], $context );
		if ( '' === $thumbnail ) {
			$thumbnail = self::get_post_thumbnail_url( $post );
		}
		if ( '' === $thumbnail ) {
			return array();
		}

		$title = self::resolve_var( $vars['title'], $context );
		if ( '' === $title ) {
			$title = get_the_title( $post );
		}

		$description = self::resolve_var( '%excerpt%', $context );
		if ( '' === $description ) {
			$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 30 );
		}

		$sources = array();

		if ( preg_match_all( '/<video[^>]*>.*?<source[^>]+src=["\']([^"\']+)["\']/is', $post->post_content, $m ) ) {
			foreach ( $m[1] as $src ) {
				$sources[] = array( 'content_loc' => $src );
			}
		}

		if ( preg_match_all( '#<iframe[^>]+src=["\']([^"\']*(?:youtube\.com/embed|youtu\.be|player\.vimeo\.com)[^"\']*)["\']#i', $post->post_content, $m ) ) {
			foreach ( $m[1] as $src ) {
				$sources[] = array( 'player_loc' => $src );
			}
		}

		$videos = array();
		foreach ( array_slice( $sources, 0, 5 ) as $source ) {
			$videos[] = array_merge(
				array( 'thumbnail' => $thumbnail, 'title' => $title, 'description' => $description ),
				$source
			);
		}

		return $videos;
	}

	private static function get_post_thumbnail_url( $post ) {
		if ( ! has_post_thumbnail( $post ) ) {
			return '';
		}
		$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'full' );
		return $src ? $src[0] : '';
	}

	/**
	 * Modo "términos": URLs de archivo de una taxonomía en sí mismas —
	 * p. ej. un sitio donde las categorías transaccionales SON la
	 * página (plantilla propia por template_include), no una etiqueta
	 * sobre posts. Sin lista de slugs, entran todos los términos con
	 * contenido de esa taxonomía. Los términos no tienen fecha de
	 * modificación propia en WordPress core -- si la variable de lastmod
	 * configurada no resuelve nada en contexto de término (p. ej.
	 * %schema_date_published%, pensada para posts), se usa la hora actual.
	 */
	private static function query_term_entries( $def ) {
		$args = array(
			'taxonomy'   => $def['taxonomy'],
			'hide_empty' => false,
		);

		$slugs = array_filter( array_map( 'trim', explode( ',', (string) $def['terms'] ) ) );
		if ( $slugs ) {
			$args['slug'] = $slugs;
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$vars = wp_parse_args( isset( $def['vars'] ) ? $def['vars'] : array(), Cmdroom_Sitemap_Settings::VAR_DEFAULTS );
		$now  = date_i18n( 'c' );

		$entries = array();
		foreach ( array_slice( $terms, 0, $def['limit'] ) as $term ) {
			$context = array( 'term' => $term );
			$lastmod = self::resolve_var( $vars['lastmod'], $context );
			$entries[] = array( 'url' => get_term_link( $term ), 'lastmod' => '' !== $lastmod ? $lastmod : $now );
		}
		return $entries;
	}

	private static function query_post_ids( $def ) {
		$args = array(
			'post_type'      => $def['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => $def['limit'],
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		);

		if ( ! empty( $def['taxonomy'] ) && ! empty( $def['terms'] ) ) {
			$terms = array_filter( array_map( 'trim', explode( ',', $def['terms'] ) ) );
			if ( $terms ) {
				$args['tax_query'] = array( array(
					'taxonomy' => $def['taxonomy'],
					'field'    => 'slug',
					'terms'    => $terms,
				) );
			}
		}

		$query = new WP_Query( $args );
		return $query->posts;
	}

	private static function latest_modified( $def ) {
		if ( 'terms' === ( $def['source'] ?? 'posts' ) ) {
			// Los términos de taxonomía no tienen una fecha de modificación
			// propia en WordPress core: se usa la hora actual como aproximación.
			return date_i18n( 'c' );
		}

		$post_ids = self::query_post_ids( array_merge( $def, array( 'limit' => 1 ) ) );
		if ( empty( $post_ids ) ) {
			return date_i18n( 'c' );
		}
		return get_post_modified_time( 'c', false, $post_ids[0] );
	}

	/**
	 * Traduce un token de %variable% (uno de los de
	 * Cmdroom_Sitemap_Settings::VAR_OPTIONS) a su valor real EN CRUDO -- sin
	 * pasar por Cmdroom_Meta_Variables::replace() ni
	 * Cmdroom_Schema_Variables::replace() directamente, porque ambas
	 * escapan su salida para el formato de destino (HTML/atributo o
	 * JSON-string) y aquí el valor va a un nodo de texto XML, que ya se
	 * escapa aparte con esc_xml() al montar el documento -- un doble
	 * escapado (o el escapado equivocado) produciría entidades/backslashes
	 * literales en el sitemap.
	 *
	 * @param string $token   Uno de los tokens permitidos (con %), o '' ("— Ninguno —").
	 * @param array  $context ['post' => WP_Post] o ['term' => WP_Term].
	 * @return string Valor en crudo, o '' si el token no aplica al contexto.
	 */
	private static function resolve_var( $token, $context ) {
		$post = isset( $context['post'] ) && $context['post'] instanceof WP_Post ? $context['post'] : null;
		$term = isset( $context['term'] ) && $context['term'] instanceof WP_Term ? $context['term'] : null;

		switch ( $token ) {
			case '%title%':
				if ( $post ) {
					$meta = Cmdroom_Meta_Resolver::resolve_for_post( $post );
					return $meta ? $meta['title'] : get_the_title( $post );
				}
				if ( $term ) {
					$meta = Cmdroom_Meta_Resolver::resolve_for_term( $term );
					return $meta ? $meta['title'] : $term->name;
				}
				return '';

			case '%schema_headline%':
				if ( $post ) {
					return wp_trim_words( get_the_title( $post ), 20, '' );
				}
				return $term ? $term->name : '';

			case '%term_title%':
				return $term ? $term->name : '';

			case '%sitename%':
				return get_bloginfo( 'name' );

			case '%excerpt%':
				if ( $post ) {
					$meta = Cmdroom_Meta_Resolver::resolve_for_post( $post );
					return $meta ? $meta['description'] : '';
				}
				if ( $term ) {
					$meta = Cmdroom_Meta_Resolver::resolve_for_term( $term );
					return $meta ? $meta['description'] : '';
				}
				return '';

			case '%schema_date_published%':
				return $post ? get_post_time( 'c', false, $post ) : '';

			case '%schema_date_modified%':
				return $post ? get_post_modified_time( 'c', false, $post ) : '';

			case '%date%':
				// %date% de Metas trae el formato humano de Ajustes → General
				// (p. ej. "22 de septiembre de 2026"), inválido para un campo
				// que exige ISO 8601 -- aquí se resuelve directo a la fecha
				// de publicación en formato 'c'.
				return $post ? get_post_time( 'c', false, $post ) : '';

			case '%image%':
			case '%og_image%':
				return Cmdroom_Meta_Resolver::get_og_image_for_context( $context );

			case '%schema_image%':
				// Sin el fallback al logo del negocio que sí aplica %image%/
				// %og_image% -- misma diferencia que hay entre ambas en Metas
				// y Datos estructurados.
				return $post ? self::get_post_thumbnail_url( $post ) : '';

			default:
				return '';
		}
	}
}
