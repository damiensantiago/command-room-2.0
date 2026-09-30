<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importa la configuración de metas de Yoast SEO a command-room, con la
 * misma estructura que Cmdroom_Rankmath_Importer (4 casillas, mismo
 * transient de informe `cmdroom_import_report`, misma tarjeta reutilizada
 * en Herramientas):
 * - Plantillas globales (wpseo_titles) → cmdroom_meta_options
 * - Meta por post (_yoast_wpseo_title/metadesc/canonical/meta-robots-*) → _cmdroom_*
 *
 * Diferencia clave con Rank Math: Yoast usa variables %%variable%% (doble
 * porcentaje) con nombres propios, mientras que Command Room usa %variable%
 * (uno solo, sintaxis heredada de Rank Math) -- translate_yoast_template()
 * traduce las que tienen equivalente y retira las que no, en vez de dejar
 * "%%" sueltos en el resultado.
 *
 * No toca ni borra nada de Yoast. Solo lee y copia. Es idempotente: un post
 * que ya tiene _cmdroom_title puesto a mano no se sobrescribe.
 */
class Cmdroom_Yoast_Importer {

	/**
	 * Traducción de variables de Yoast (%%x%%) a las de Command Room (%x%).
	 * Solo las que tienen equivalente real -- el resto se retira en
	 * translate_yoast_template() en vez de adivinar.
	 */
	const VAR_MAP = array(
		'title'             => 'title',
		'sitename'          => 'sitename',
		'sitedesc'          => 'sitedesc',
		'tagline'           => 'sitedesc',
		'sep'               => 'sep',
		'page'              => 'page',
		'currentyear'       => 'currentyear',
		'excerpt'           => 'excerpt',
		'excerpt_only'      => 'excerpt_only',
		'category'          => 'category',
		'primary_category'  => 'category',
		'tag'               => 'keywords',
		'name'              => 'author_name',
		'term_title'        => 'term_title',
		'term_description'  => 'term_description',
		'date'              => 'date',
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_import_yoast', array( __CLASS__, 'handle_import' ) );
	}

	public static function is_yoast_active() {
		return function_exists( 'is_plugin_active' )
			? is_plugin_active( 'wordpress-seo/wp-seo.php' )
			: defined( 'WPSEO_VERSION' );
	}

	/**
	 * Recuento rápido para el texto de estado antes de importar -- misma
	 * lógica que Cmdroom_Rankmath_Importer::count_importable_posts() pero
	 * mirando las claves de meta de Yoast.
	 */
	public static function count_importable_posts() {
		$post_types = wp_list_pluck( Cmdroom_Meta_Settings::public_post_types(), 'name' );
		$query      = new WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_yoast_wpseo_title', 'compare' => 'EXISTS' ),
				array( 'key' => '_yoast_wpseo_metadesc', 'compare' => 'EXISTS' ),
			),
		) );
		return count( $query->posts );
	}

	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_import_yoast' );

		$do_meta      = ! empty( $_POST['import_meta'] );
		$do_robots    = ! empty( $_POST['import_robots'] );
		$do_redirects = ! empty( $_POST['import_redirects'] );
		$do_schema    = ! empty( $_POST['import_schema'] );

		$report = array();

		if ( $do_meta ) {
			$report['templates'] = self::import_templates();
		}
		if ( $do_meta || $do_robots ) {
			$report['posts'] = self::import_post_meta( $do_meta, $do_robots );
		}
		if ( $do_redirects ) {
			$report['redirects'] = self::import_redirects();
		}
		if ( $do_schema ) {
			$report['schema'] = self::log_unmapped_schema();
		}

		set_transient( 'cmdroom_import_report_yoast', $report, MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'cmdroom_imported_yoast', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Yoast no tiene un override de schema JSON por post como Rank Math --
	 * tiene selectores de tipo de página/artículo (_yoast_wpseo_schema_*).
	 * Igual que con Rank Math, en vez de fingir una importación que no
	 * existe, se cuenta y se deja constancia en el informe.
	 */
	private static function log_unmapped_schema() {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ('_yoast_wpseo_schema_page_type', '_yoast_wpseo_schema_article_type')"
		);
		return array( 'unmapped' => $count );
	}

	/**
	 * Sustituye cada %%variable%% de Yoast con equivalente conocido por su
	 * %variable% de Command Room; las que no tienen equivalente se retiran
	 * en vez de dejarlas sueltas en el título/descripción resultante.
	 */
	private static function translate_yoast_template( $tpl ) {
		$tpl = (string) $tpl;
		if ( '' === trim( $tpl ) ) {
			return '';
		}

		foreach ( self::VAR_MAP as $yoast_var => $cr_var ) {
			$tpl = str_replace( '%%' . $yoast_var . '%%', '%' . $cr_var . '%', $tpl );
		}
		$tpl = preg_replace( '/%%[a-z0-9_-]+%%/i', '', $tpl );

		return trim( preg_replace( '/\s{2,}/', ' ', $tpl ) );
	}

	/**
	 * Desde el rediseño de Metas (2026-09-22) ya no hay una plantilla por
	 * post type/taxonomía real, sino un único bloque por grupo de página
	 * (home/contenido/corporativas/categorias/tags) -- ver el docblock de
	 * Cmdroom_Meta_Settings::content_post_types(). Se mapea cada campo de
	 * Yoast a su grupo: 'contenido' usa 'post' como representante (con
	 * datos en casi cualquier sitio real) y si no tiene plantilla prueba
	 * con el resto de tipos públicos no-page; 'corporativas' es 'page';
	 * 'categorias' es la taxonomía 'category'; 'tags' es 'post_tag'.
	 */
	private static function import_templates() {
		$yoast = get_option( 'wpseo_titles', array() );
		if ( empty( $yoast ) || ! is_array( $yoast ) ) {
			return array( 'imported' => false, 'reason' => 'No se encontró wpseo_titles.' );
		}

		$opts    = Cmdroom_Meta_Settings::get_options();
		$touched = array();

		$content_source = null;
		$other_types    = array_diff( array_keys( Cmdroom_Meta_Settings::public_post_types() ), array( 'post', 'page' ) );
		foreach ( array_merge( array( 'post' ), $other_types ) as $pt_name ) {
			if ( ! empty( $yoast[ "title-{$pt_name}" ] ) || ! empty( $yoast[ "metadesc-{$pt_name}" ] ) ) {
				$content_source = $pt_name;
				break;
			}
		}
		if ( $content_source ) {
			$opts['contenido']['html'] = self::yoast_block(
				! empty( $yoast[ "title-{$content_source}" ] ) ? self::translate_yoast_template( $yoast[ "title-{$content_source}" ] ) : '%title% %sep% %sitename%',
				! empty( $yoast[ "metadesc-{$content_source}" ] ) ? self::translate_yoast_template( $yoast[ "metadesc-{$content_source}" ] ) : '%excerpt%'
			);
			$touched[] = 'contenido (bloque de <head>)';
		}

		if ( ! empty( $yoast['title-page'] ) || ! empty( $yoast['metadesc-page'] ) ) {
			$opts['corporativas']['html'] = self::yoast_block(
				! empty( $yoast['title-page'] ) ? self::translate_yoast_template( $yoast['title-page'] ) : '%title% %sep% %sitename%',
				! empty( $yoast['metadesc-page'] ) ? self::translate_yoast_template( $yoast['metadesc-page'] ) : '%excerpt%'
			);
			$touched[] = 'corporativas (bloque de <head>)';
		}

		if ( ! empty( $yoast['title-tax-category'] ) || ! empty( $yoast['metadesc-tax-category'] ) ) {
			$opts['categorias']['html'] = self::yoast_block(
				! empty( $yoast['title-tax-category'] ) ? self::translate_yoast_template( $yoast['title-tax-category'] ) : '%term_title% %sep% %sitename%',
				! empty( $yoast['metadesc-tax-category'] ) ? self::translate_yoast_template( $yoast['metadesc-tax-category'] ) : '%excerpt%'
			);
			$touched[] = 'categorias (bloque de <head>)';
		}

		if ( ! empty( $yoast['title-tax-post_tag'] ) || ! empty( $yoast['metadesc-tax-post_tag'] ) ) {
			$opts['tags']['html'] = self::yoast_block(
				! empty( $yoast['title-tax-post_tag'] ) ? self::translate_yoast_template( $yoast['title-tax-post_tag'] ) : '%term_title% %sep% %sitename%',
				! empty( $yoast['metadesc-tax-post_tag'] ) ? self::translate_yoast_template( $yoast['metadesc-tax-post_tag'] ) : '%excerpt%'
			);
			$touched[] = 'tags (bloque de <head>)';
		}

		if ( ! empty( $yoast['title-home-wpseo'] ) || ! empty( $yoast['metadesc-home-wpseo'] ) ) {
			$opts['home']['html'] = self::yoast_block(
				! empty( $yoast['title-home-wpseo'] ) ? self::translate_yoast_template( $yoast['title-home-wpseo'] ) : '%sitename% %sep% %sitedesc%',
				! empty( $yoast['metadesc-home-wpseo'] ) ? self::translate_yoast_template( $yoast['metadesc-home-wpseo'] ) : '%sitedesc%'
			);
			$touched[] = 'home (bloque de <head>)';
		}

		update_option( Cmdroom_Meta_Settings::OPTION, $opts );

		return array( 'imported' => true, 'campos' => $touched );
	}

	private static function yoast_block( $title, $description ) {
		return sprintf( "<title>%s</title>\n<meta name=\"description\" content=\"%s\" />", $title, $description );
	}

	/**
	 * $do_meta controla título/descripción, $do_robots controla canonical +
	 * noindex/nofollow -- mismo reparto que Cmdroom_Rankmath_Importer.
	 * Yoast codifica noindex/nofollow como '1' = activado en
	 * `_yoast_wpseo_meta-robots-noindex`/`-nofollow` ('' o '2' = por
	 * defecto/index, según el caso -- solo '1' significa "sí, aplicar").
	 */
	private static function import_post_meta( $do_meta = true, $do_robots = true ) {
		$post_types = wp_list_pluck( Cmdroom_Meta_Settings::public_post_types(), 'name' );

		$query = new WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_yoast_wpseo_title', 'compare' => 'EXISTS' ),
				array( 'key' => '_yoast_wpseo_metadesc', 'compare' => 'EXISTS' ),
			),
		) );

		$imported = 0;
		$skipped  = 0;

		foreach ( $query->posts as $post_id ) {
			$already_has_override = get_post_meta( $post_id, '_cmdroom_title', true )
				|| get_post_meta( $post_id, '_cmdroom_description', true );

			if ( $already_has_override ) {
				$skipped++;
				continue;
			}

			if ( $do_meta ) {
				$yoast_title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
				$yoast_desc  = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
				if ( $yoast_title ) {
					update_post_meta( $post_id, '_cmdroom_title', self::translate_yoast_template( $yoast_title ) );
				}
				if ( $yoast_desc ) {
					update_post_meta( $post_id, '_cmdroom_description', self::translate_yoast_template( $yoast_desc ) );
				}
			}

			if ( $do_robots ) {
				$yoast_canon = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
				if ( $yoast_canon ) {
					update_post_meta( $post_id, '_cmdroom_canonical', $yoast_canon );
				}
				$noindex  = '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
				$nofollow = '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
				update_post_meta( $post_id, '_cmdroom_noindex', $noindex ? 1 : 0 );
				update_post_meta( $post_id, '_cmdroom_nofollow', $nofollow ? 1 : 0 );
			}

			$imported++;
		}

		return array( 'imported' => $imported, 'skipped' => $skipped, 'total_encontrados' => count( $query->posts ) );
	}

	/**
	 * Las redirecciones son una función de Yoast SEO Premium (el gratuito no
	 * las tiene), guardadas en la opción `wpseo-premium-redirects` como un
	 * array de reglas. A diferencia del importador de Rank Math (tabla
	 * propia con esquema fijo y documentado), esto es best-effort: se
	 * reconocen los nombres de campo habituales ('origin'/'url' o
	 * 'target'/'format') y cualquier fila con forma inesperada se cuenta
	 * como omitida en vez de fallar. Si la opción no existe (lo normal si
	 * el sitio no tiene Yoast Premium), se informa de ello sin importar nada.
	 */
	private static function import_redirects() {
		$rows = get_option( 'wpseo-premium-redirects', null );

		if ( ! is_array( $rows ) || ! $rows ) {
			return array( 'imported' => 0, 'omitted' => 0, 'reason' => 'No se encontró la opción de redirecciones de Yoast Premium (wpseo-premium-redirects).' );
		}

		$imported = 0;
		$omitted  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$origin = isset( $row['origin'] ) ? $row['origin'] : ( isset( $row['url'] ) ? $row['url'] : '' );
			$target = isset( $row['target'] ) ? $row['target'] : ( isset( $row['url_to'] ) ? $row['url_to'] : '' );
			$type   = isset( $row['type'] ) ? (int) $row['type'] : 301;
			$format = isset( $row['format'] ) ? $row['format'] : 'plain';

			$origin = trim( (string) $origin, '/' );

			if ( '' === $origin || '' === $target ) {
				$omitted[] = $origin ? $origin : '(vacío)';
				continue;
			}

			Cmdroom_Redirect_Table::insert( array(
				'source'        => $origin,
				'source_type'   => 'regex' === $format ? 'regex' : 'exact',
				'destination'   => esc_url_raw( $target ),
				'redirect_type' => $type ? $type : 301,
				'status'        => 1,
				'hits'          => 0,
			) );
			$imported++;
		}

		return array( 'imported' => $imported, 'omitted' => $omitted );
	}
}
