<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importa la configuración de metas de Rank Math a command-room:
 * - Plantillas globales (rank-math-options-titles) → cmdroom_meta_options
 * - Meta por post (rank_math_title/description/canonical_url/robots) → _cmdroom_*
 *
 * No toca ni borra nada de Rank Math. Solo lee y copia. Es idempotente:
 * un post que ya tiene _cmdroom_title puesto a mano no se sobrescribe.
 */
class Cmdroom_Rankmath_Importer {

	public static function init() {
		add_action( 'admin_post_cmdroom_import_rankmath', array( __CLASS__, 'handle_import' ) );
	}

	public static function is_rankmath_active() {
		return function_exists( 'is_plugin_active' )
			? is_plugin_active( 'seo-by-rank-math/rank-math.php' )
			: defined( 'RANK_MATH_VERSION' );
	}

	/**
	 * Recuento rápido para el texto de estado antes de importar ("Rank Math
	 * detectado · N entradas con datos") -- misma condición que usa la query
	 * real de import_post_meta(), pero con fields=ids y sin procesar nada.
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
				array( 'key' => 'rank_math_title', 'compare' => 'EXISTS' ),
				array( 'key' => 'rank_math_description', 'compare' => 'EXISTS' ),
			),
		) );
		return count( $query->posts );
	}

	/**
	 * Una sola acción para las 4 casillas del handoff ("Metas y plantillas",
	 * "Robots y canonicals", "Redirecciones", "Schema") en vez de las dos
	 * acciones sueltas que había antes (una para metas+plantillas, otra para
	 * redirecciones) -- cada casilla activa/desactiva su propio bloque de
	 * trabajo, todas comparten el mismo informe final.
	 *
	 * Sigue siendo síncrona (sin AJAX por lotes): con los volúmenes reales
	 * de los sitios del administrador (cientos de entradas, no decenas de miles) un
	 * POST normal tarda bien dentro del timeout de PHP -- el progreso por
	 * lotes del prototipo se deja para si algún día hiciera falta.
	 */
	public static function handle_import() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_import_rankmath' );

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

		set_transient( 'cmdroom_import_report', $report, MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'cmdroom_imported', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Command Room no tiene (todavía) un override de schema por post -- el
	 * módulo de Datos estructurados es por tipo de contenido/plantilla, no
	 * por entrada individual. En vez de fingir una importación que no existe,
	 * se cuenta cuántas entradas tienen schema propio en Rank Math y se deja
	 * constancia en el informe, tal como pide el handoff ("los que no tienen
	 * equivalente se registran en un log").
	 */
	private static function log_unmapped_schema() {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank_math_schema_%'"
		);
		return array( 'unmapped' => $count );
	}

	/**
	 * Desde el rediseño de Metas (2026-09-22) ya no hay una plantilla por
	 * post type/taxonomía real, sino un único bloque por grupo de página
	 * (home/contenido/corporativas/categorias/tags) -- ver el docblock de
	 * Cmdroom_Meta_Settings::content_post_types(). Se mapea cada campo de
	 * Rank Math a su grupo: 'contenido' usa 'post' como representante (con
	 * datos en casi cualquier sitio real) y si no tiene plantilla prueba
	 * con el resto de tipos públicos no-page; 'corporativas' es 'page';
	 * 'categorias' es la taxonomía 'category'; 'tags' es 'post_tag'.
	 *
	 * Bug corregido el 2026-09-23: hasta esta versión escribía en
	 * `$opts['post_types'][...]`/`$opts['taxonomies'][...]`, una estructura
	 * que Cmdroom_Meta_Settings ya no lee desde el rediseño por grupos --
	 * la importación no fallaba pero tampoco cambiaba nada real. Encontrado
	 * al construir el importador equivalente de Yoast y verificar en
	 * un sitio de pruebas que el bloque importado no aparecía en Metas.
	 */
	private static function import_templates() {
		$rm = get_option( 'rank-math-options-titles', array() );
		if ( empty( $rm ) || ! is_array( $rm ) ) {
			return array( 'imported' => false, 'reason' => 'No se encontró rank-math-options-titles.' );
		}

		$opts    = Cmdroom_Meta_Settings::get_options();
		$touched = array();

		$content_source = null;
		$other_types    = array_diff( array_keys( Cmdroom_Meta_Settings::public_post_types() ), array( 'post', 'page' ) );
		foreach ( array_merge( array( 'post' ), $other_types ) as $pt_name ) {
			if ( ! empty( $rm[ "pt_{$pt_name}_title" ] ) || ! empty( $rm[ "pt_{$pt_name}_description" ] ) ) {
				$content_source = $pt_name;
				break;
			}
		}
		if ( $content_source ) {
			$opts['contenido']['html'] = self::rankmath_block(
				! empty( $rm[ "pt_{$content_source}_title" ] ) ? $rm[ "pt_{$content_source}_title" ] : '%title% %sep% %sitename%',
				! empty( $rm[ "pt_{$content_source}_description" ] ) ? $rm[ "pt_{$content_source}_description" ] : '%excerpt%'
			);
			$touched[] = 'contenido (bloque de <head>)';
		}

		if ( ! empty( $rm['pt_page_title'] ) || ! empty( $rm['pt_page_description'] ) ) {
			$opts['corporativas']['html'] = self::rankmath_block(
				! empty( $rm['pt_page_title'] ) ? $rm['pt_page_title'] : '%title% %sep% %sitename%',
				! empty( $rm['pt_page_description'] ) ? $rm['pt_page_description'] : '%excerpt%'
			);
			$touched[] = 'corporativas (bloque de <head>)';
		}

		if ( ! empty( $rm['tax_category_title'] ) || ! empty( $rm['tax_category_description'] ) ) {
			$opts['categorias']['html'] = self::rankmath_block(
				! empty( $rm['tax_category_title'] ) ? $rm['tax_category_title'] : '%term_title% %sep% %sitename%',
				! empty( $rm['tax_category_description'] ) ? $rm['tax_category_description'] : '%excerpt%'
			);
			$touched[] = 'categorias (bloque de <head>)';
		}

		if ( ! empty( $rm['tax_post_tag_title'] ) || ! empty( $rm['tax_post_tag_description'] ) ) {
			$opts['tags']['html'] = self::rankmath_block(
				! empty( $rm['tax_post_tag_title'] ) ? $rm['tax_post_tag_title'] : '%term_title% %sep% %sitename%',
				! empty( $rm['tax_post_tag_description'] ) ? $rm['tax_post_tag_description'] : '%excerpt%'
			);
			$touched[] = 'tags (bloque de <head>)';
		}

		if ( ! empty( $rm['homepage_title'] ) || ! empty( $rm['homepage_description'] ) ) {
			$opts['home']['html'] = self::rankmath_block(
				! empty( $rm['homepage_title'] ) ? $rm['homepage_title'] : '%sitename% %sep% %sitedesc%',
				! empty( $rm['homepage_description'] ) ? $rm['homepage_description'] : '%sitedesc%'
			);
			$touched[] = 'home (bloque de <head>)';
		}

		update_option( Cmdroom_Meta_Settings::OPTION, $opts );

		return array( 'imported' => true, 'campos' => $touched );
	}

	/**
	 * Combina título+descripción de Rank Math en el formato de bloque único
	 * que usa Command Room desde 0.10.0 -- mismo patrón que
	 * Cmdroom_Meta_Settings::default_html_block() (no es pública, así que se
	 * repite aquí en vez de acoplar los dos módulos).
	 */
	private static function rankmath_block( $title, $description ) {
		return sprintf( "<title>%s</title>\n<meta name=\"description\" content=\"%s\" />", $title, $description );
	}

	/**
	 * $do_meta controla título/descripción, $do_robots controla canonical +
	 * noindex/nofollow -- son las casillas "Metas y plantillas" y "Robots y
	 * canonicals" del handoff, independientes entre sí pero comparten la
	 * misma query (ambas miran las mismas entradas con datos de Rank Math).
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
				array( 'key' => 'rank_math_title', 'compare' => 'EXISTS' ),
				array( 'key' => 'rank_math_description', 'compare' => 'EXISTS' ),
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
				$rm_title = get_post_meta( $post_id, 'rank_math_title', true );
				$rm_desc  = get_post_meta( $post_id, 'rank_math_description', true );
				if ( $rm_title ) {
					update_post_meta( $post_id, '_cmdroom_title', $rm_title );
				}
				if ( $rm_desc ) {
					update_post_meta( $post_id, '_cmdroom_description', $rm_desc );
				}
			}

			if ( $do_robots ) {
				$rm_canon  = get_post_meta( $post_id, 'rank_math_canonical_url', true );
				$rm_robots = get_post_meta( $post_id, 'rank_math_robots', true );
				if ( $rm_canon ) {
					update_post_meta( $post_id, '_cmdroom_canonical', $rm_canon );
				}
				$robots = is_array( $rm_robots ) ? $rm_robots : array();
				update_post_meta( $post_id, '_cmdroom_noindex', in_array( 'noindex', $robots, true ) ? 1 : 0 );
				update_post_meta( $post_id, '_cmdroom_nofollow', in_array( 'nofollow', $robots, true ) ? 1 : 0 );
			}

			$imported++;
		}

		return array( 'imported' => $imported, 'skipped' => $skipped, 'total_encontrados' => count( $query->posts ) );
	}

	/**
	 * Importa las reglas ACTIVAS (no "trashed") de wp_rank_math_redirections.
	 * Cada regla de Rank Math puede tener varios patrones de origen para un
	 * mismo destino (columna "sources", serializada) — se explota en una
	 * fila propia por patrón. Solo se importan comparaciones "exact" y
	 * "regex"; el resto ("contains", "start", "end") se deja fuera porque
	 * traducir su semántica exacta no es trivial y preferimos no adivinar.
	 * No borra ni modifica nada en Rank Math.
	 */
	private static function import_redirects() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array( 'imported' => 0, 'omitted' => 0, 'reason' => 'No existe la tabla de redirecciones de Rank Math.' );
		}

		$rows = $wpdb->get_results( "SELECT * FROM $table WHERE status = 'active'", ARRAY_A );

		$imported = 0;
		$omitted  = array();

		foreach ( $rows as $row ) {
			$sources = maybe_unserialize( $row['sources'] );
			if ( ! is_array( $sources ) ) {
				continue;
			}

			foreach ( $sources as $source ) {
				$pattern    = isset( $source['pattern'] ) ? trim( $source['pattern'], '/' ) : '';
				$comparison = isset( $source['comparison'] ) ? $source['comparison'] : '';

				if ( '' === $pattern ) {
					continue;
				}

				if ( ! in_array( $comparison, array( 'exact', 'regex' ), true ) ) {
					$omitted[] = $pattern . ' (comparación "' . $comparison . '" no soportada)';
					continue;
				}

				Cmdroom_Redirect_Table::insert( array(
					'source'        => $pattern,
					'source_type'   => $comparison,
					'destination'   => esc_url_raw( $row['url_to'] ),
					'redirect_type' => (int) $row['header_code'],
					'status'        => 1,
					'hits'          => (int) $row['hits'],
				) );
				$imported++;
			}
		}

		return array( 'imported' => $imported, 'omitted' => $omitted );
	}
}
