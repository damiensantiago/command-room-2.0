<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Feed RSS "estilo Google News" por categoría -- pedido por el administrador el
 * 2026-09-24, con URL fija tipo /rss/googlenews/{categoría-padre}/{categoría}.xml
 * (o /rss/googlenews/{categoría}.xml si la categoría no tiene padre), en vez
 * del feed nativo de WordPress (/categoria/feed/) que usaba antes
 * Cmdroom_Feed_Links.
 *
 * Estructura clonada campo a campo del feed Google News real de un gran
 * medio (2026-09-24):
 * mismos namespaces (media/dc/dcterms/atom/content), mismos elementos de
 * canal (title/link/description/language/copyright/pubDate/lastBuildDate/
 * category/ttl/atom:link self+hub) y mismos elementos de item (title/
 * description/dc:creator/link/category/media:description/media:title/
 * media:content/media:thumbnail/guid/pubDate/content:encoded), todos con
 * datos reales del sitio en vez de los del ejemplo. Sin <news:keywords> ni
 * namespace news: -- ese ejemplo real no los lleva (son del protocolo de
 * sitemaps.xml, no de este feed RSS).
 *
 * Reutiliza el nombre de publicación de Google News ya configurado en
 * Ajustes → Sitemaps (Cmdroom_Sitemap_Settings::get_news_publication_name())
 * para el <copyright> del canal.
 *
 * Solo cubre la taxonomía 'category' -- es lo único que pidió el administrador (el
 * ejemplo de referencia es sección/subsección, ambas categorías). Etiquetas y otras
 * taxonomías siguen con el feed nativo de WordPress sin cambios.
 */
class Cmdroom_Googlenews_Feed {

	const QUERY_VAR = 'cmdroom_googlenews_feed';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'skip_canonical_redirect' ) );
	}

	/**
	 * Sin esto, WordPress trata /rss/googlenews/.../categoria.xml como una
	 * URL "no reconocida" y la redirige (301) a la misma URL con barra final
	 * (redirect_canonical() en wp-includes/canonical.php) -- rompe la .xml
	 * de la ruta. Se desactiva solo para peticiones que ya resolvieron a
	 * este query var.
	 */
	public static function skip_canonical_redirect( $redirect_url ) {
		if ( get_query_var( self::QUERY_VAR ) ) {
			return false;
		}
		return $redirect_url;
	}

	public static function add_rewrite_rules() {
		add_rewrite_rule(
			'^rss/googlenews/([^/]+)/([^/]+)\.xml$',
			'index.php?' . self::QUERY_VAR . '=1&cmdroom_gn_parent=$matches[1]&cmdroom_gn_slug=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^rss/googlenews/([^/]+)\.xml$',
			'index.php?' . self::QUERY_VAR . '=1&cmdroom_gn_slug=$matches[1]',
			'top'
		);
	}

	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		$vars[] = 'cmdroom_gn_parent';
		$vars[] = 'cmdroom_gn_slug';
		return $vars;
	}

	/**
	 * URL canónica del feed de una categoría -- la usa también
	 * Cmdroom_Feed_Links para el <link rel="alternate"> del <head>, así las
	 * dos clases nunca pueden desincronizarse sobre qué URL le corresponde a
	 * cada categoría.
	 */
	public static function get_feed_url( WP_Term $category ) {
		if ( $category->parent ) {
			$parent = get_term( $category->parent, 'category' );
			$parent_slug = ( $parent && ! is_wp_error( $parent ) ) ? $parent->slug : '';
			return home_url( sprintf( '/rss/googlenews/%s/%s.xml', $parent_slug, $category->slug ) );
		}
		return home_url( sprintf( '/rss/googlenews/%s.xml', $category->slug ) );
	}

	public static function maybe_render() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		if ( ! Cmdroom_Rss_Settings::is_enabled() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		$slug = sanitize_title( get_query_var( 'cmdroom_gn_slug' ) );
		$term = get_term_by( 'slug', $slug, 'category' );
		if ( ! $term || is_wp_error( $term ) ) {
			status_header( 404 );
			exit;
		}

		$parent_slug = get_query_var( 'cmdroom_gn_parent' );
		if ( $parent_slug ) {
			$parent = $term->parent ? get_term( $term->parent, 'category' ) : null;
			if ( ! $parent || is_wp_error( $parent ) || $parent->slug !== sanitize_title( $parent_slug ) ) {
				status_header( 404 );
				exit;
			}
		} elseif ( $term->parent ) {
			// Tiene categoría padre pero se pidió sin ella en la ruta -- no
			// es la URL canónica de esta categoría, 404 en vez de servir un
			// duplicado bajo dos URLs distintas.
			status_header( 404 );
			exit;
		}

		self::render( $term );
	}

	/**
	 * CDATA a prueba de contenido que ya trae un "]]>" literal dentro --
	 * pasaría muy raro (contenido copiado de otro XML), pero rompería el
	 * documento entero si ocurriera y no se separara así.
	 *
	 * $pad_spaces: el feed real usado como referencia
	 * (2026-09-24) envuelve el contenido de cada CDATA con espacios
	 * literales -- 2 a cada lado en title/description/copyright/category/
	 * dc:creator, 1 en media:description/media:title/content:encoded.
	 * Se replica tal cual para clonar la estructura al detalle, aunque no
	 * aporte nada semánticamente.
	 */
	private static function cdata( $value, $pad_spaces = 2 ) {
		$padding = str_repeat( ' ', max( 0, (int) $pad_spaces ) );
		$safe    = str_replace( ']]>', ']]]]><![CDATA[>', (string) $value );
		return '<![CDATA[' . $padding . $safe . $padding . ']]>';
	}

	private static function render( WP_Term $term ) {
		header( 'Content-Type: application/rss+xml; charset=' . get_bloginfo( 'charset' ) );

		$posts = get_posts(
			array(
				'category'            => $term->term_id,
				'posts_per_page'      => Cmdroom_Rss_Settings::get_items_limit(),
				'post_status'         => 'publish',
				'ignore_sticky_posts' => true,
			)
		);

		$publication_name = Cmdroom_Sitemap_Settings::get_news_publication_name();
		$language         = Cmdroom_Rss_Settings::get_language();
		$now_gmt          = current_time( 'mysql', true );
		$channel_pub_date = $posts ? $posts[0]->post_date_gmt : $now_gmt;

		echo '<?xml version="1.0" encoding="' . esc_attr( get_bloginfo( 'charset' ) ) . '"?>' . "\n";
		echo '<rss xmlns:media="http://search.yahoo.com/mrss/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:content="http://purl.org/rss/1.0/modules/content/" version="2.0">' . "\n";
		echo "\t<channel>\n";
		printf( "\t\t<title>%s</title>\n", self::cdata( $term->name ) );
		printf( "\t\t<link>%s</link>\n", esc_url( get_term_link( $term ) ) );
		printf( "\t\t<description>%s</description>\n", self::cdata( $term->description ? $term->description : $term->name ) );
		printf( "\t\t<language>%s</language>\n", esc_xml( $language ) );
		/* translators: %s: nombre de publicación */
		printf( "\t\t<copyright>%s</copyright>\n", self::cdata( sprintf( __( '(c) %1$d, %2$s', 'command-room' ), (int) current_time( 'Y' ), $publication_name ) ) );
		printf( "\t\t<pubDate>%s</pubDate>\n", esc_xml( mysql2date( 'D, d M Y H:i:s O', $channel_pub_date, false ) ) );
		printf( "\t\t<lastBuildDate>%s</lastBuildDate>\n", esc_xml( mysql2date( 'D, d M Y H:i:s O', $now_gmt, false ) ) );
		printf( "\t\t<category>%s</category>\n", self::cdata( $term->name ) );
		echo "\t\t<ttl>60</ttl>\n";
		printf( "\t\t<atom:link rel=\"self\" type=\"application/rss+xml\" href=\"%s\"/>\n", esc_url( self::get_feed_url( $term ) ) );
		echo "\t\t<atom:link rel=\"hub\" href=\"https://pubsubhubbub.appspot.com\"/>\n";

		foreach ( $posts as $post ) {
			self::render_item( $post, $term );
		}

		echo "\t</channel>\n";
		echo '</rss>';
		exit;
	}

	private static function render_item( WP_Post $item_post, WP_Term $term ) {
		global $post;
		$post = $item_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- necesario para que the_content aplique shortcodes/blocks en el contexto del post correcto
		setup_postdata( $post );

		$author_name = get_the_author_meta( 'display_name', $post->post_author );
		$content     = apply_filters( 'the_content', $post->post_content );

		echo "\t\t<item>\n";
		printf( "\t\t\t<title>%s</title>\n", self::cdata( get_the_title( $post ) ) );
		printf( "\t\t\t<description>%s</description>\n", self::cdata( wp_strip_all_tags( get_the_excerpt( $post ) ) ) );
		printf( "\t\t\t<dc:creator>%s</dc:creator>\n", self::cdata( $author_name ) );
		printf( "\t\t\t<link>%s</link>\n", esc_url( get_permalink( $post ) ) );
		printf( "\t\t\t<category>%s</category>\n", self::cdata( $term->name ) );

		$thumbnail_id = get_post_thumbnail_id( $post );
		if ( $thumbnail_id ) {
			$full      = wp_get_attachment_image_src( $thumbnail_id, 'full' );
			$thumbnail = wp_get_attachment_image_src( $thumbnail_id, 'thumbnail' );
			$mime      = get_post_mime_type( $thumbnail_id );
			$caption   = get_the_title( $thumbnail_id );

			if ( $full ) {
				printf( "\t\t\t<media:description type=\"html\">%s</media:description>\n", self::cdata( $caption, 1 ) );
				printf( "\t\t\t<media:title type=\"html\">%s</media:title>\n", self::cdata( $caption, 1 ) );
				printf(
					"\t\t\t<media:content type=\"%s\" medium=\"image\" url=\"%s\" width=\"%d\" height=\"%d\"/>\n",
					esc_attr( $mime ? $mime : 'image/jpeg' ),
					esc_url( $full[0] ),
					(int) $full[1],
					(int) $full[2]
				);
			}
			if ( $thumbnail ) {
				printf(
					"\t\t\t<media:thumbnail url=\"%s\" width=\"%d\" height=\"%d\"/>\n",
					esc_url( $thumbnail[0] ),
					(int) $thumbnail[1],
					(int) $thumbnail[2]
				);
			}
		}

		printf( "\t\t\t<guid isPermaLink=\"true\">%s</guid>\n", esc_url( get_permalink( $post ) ) );
		printf( "\t\t\t<pubDate>%s</pubDate>\n", esc_xml( mysql2date( 'D, d M Y H:i:s O', $post->post_date_gmt, false ) ) );
		printf( "\t\t\t<content:encoded>%s</content:encoded>\n", self::cdata( $content, 1 ) );
		echo "\t\t</item>\n";

		wp_reset_postdata();
	}
}
