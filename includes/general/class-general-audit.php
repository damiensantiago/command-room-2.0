<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sección "Auditoría del sitio" de General (handoff 2026-09-24). Chequeos
 * reales sobre contenido real -- nada de datos de ejemplo. Corre sobre un
 * escaneo acotado (últimos 200 posts/páginas publicados, no todo el sitio
 * sin límite) y queda en caché 24h, con un botón "Actualizar auditoría"
 * para forzar el recálculo -- escanear contenido + parsear HTML de cada
 * entrada es demasiado caro para hacerlo en cada carga de General.
 *
 * Limitaciones a propósito, documentadas para no venderlas como más de lo
 * que son:
 * - "H1 ausente" mira el HTML de post_content, no la página ya renderizada
 *   por el tema -- muchos temas imprimen el H1 fuera del contenido (en la
 *   plantilla), así que puede haber falsos positivos si el tema pone su
 *   propio H1 con el título.
 * - "Enlace roto" solo revisa enlaces internos (mismo host) que no
 *   resuelven a un post/página/término real -- no hace peticiones HTTP a
 *   enlaces externos, sería demasiado lento en un escaneo de cientos de
 *   entradas.
 * - "Imagen sin alt" solo mira la imagen destacada, no cada `<img>` suelta
 *   dentro del contenido.
 */
class Cmdroom_General_Audit {

	const CACHE_TTL      = DAY_IN_SECONDS;
	const SCAN_LIMIT      = 200;
	const HISTORY_OPTION   = 'cmdroom_general_audit_history';

	// Explicación genérica por tipo -- pedido por el administrador 2026-09-24: la
	// tabla ya no lista cada URL suelta, agrupa por tipo de problema y
	// muestra un ejemplo + cuántas veces se repite. El texto de aquí es el
	// que se ve en la fila resumen; el detalle concreto (p. ej. hacia qué
	// URL apunta un enlace roto) se queda en cada fila expandida.
	const TYPE_LABELS = array(
		'missing_description' => array( 'label' => 'Meta descripción ausente', 'severity' => 'warning' ),
		'duplicate_title'     => array( 'label' => 'Título duplicado con otra entrada', 'severity' => 'critical' ),
		'missing_alt'         => array( 'label' => 'Imagen destacada sin texto alternativo', 'severity' => 'warning' ),
		'broken_link'         => array( 'label' => 'Enlace interno roto (apunta a una URL que no existe)', 'severity' => 'critical' ),
		'missing_h1'          => array( 'label' => 'H1 ausente en el contenido (revisar si el tema lo pinta aparte)', 'severity' => 'info' ),
		'url_params'          => array( 'label' => 'URL con parámetros indexada en Search Console', 'severity' => 'info' ),
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_refresh_audit', array( __CLASS__, 'handle_refresh' ) );
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_refresh_audit' );
		delete_transient( 'cmdroom_general_audit' );
		wp_safe_redirect( add_query_arg( 'cmdroom_audit_refreshed', '1', wp_get_referer() ) );
		exit;
	}

	public static function get_issues() {
		$cached = get_transient( 'cmdroom_general_audit' );
		if ( false !== $cached ) {
			return $cached;
		}

		$issues = array_merge(
			self::scan_content(),
			self::scan_gsc_url_params()
		);

		$issues = self::apply_history( $issues );

		usort( $issues, function ( $a, $b ) {
			$order = array( 'critical' => 0, 'warning' => 1, 'info' => 2 );
			return $order[ $a['severity'] ] <=> $order[ $b['severity'] ];
		} );

		set_transient( 'cmdroom_general_audit', $issues, self::CACHE_TTL );
		return $issues;
	}

	/**
	 * Asigna a cada problema la fecha en que se detectó por primera vez
	 * (no "ahora", que sería lo mismo cada vez que expira la caché) --
	 * usa una huella url+problema contra un histórico persistido en
	 * wp_options. Los problemas que ya no aparecen se sueltan solos (no
	 * hace falta limpiarlos a mano).
	 */
	private static function apply_history( $issues ) {
		$history = get_option( self::HISTORY_OPTION, array() );
		$today   = current_time( 'Y-m-d' );
		$seen    = array();

		foreach ( $issues as &$issue ) {
			$fingerprint = md5( $issue['url'] . '|' . $issue['type'] . '|' . $issue['detail'] );
			$seen[ $fingerprint ] = true;
			if ( ! isset( $history[ $fingerprint ] ) ) {
				$history[ $fingerprint ] = $today;
			}
			$issue['detected'] = $history[ $fingerprint ];
		}
		unset( $issue );

		// Solo se guardan las huellas que siguen vivas -- un problema
		// resuelto deja de ocupar la opción.
		$history = array_intersect_key( $history, $seen );
		update_option( self::HISTORY_OPTION, $history );

		return $issues;
	}

	private static function scan_content() {
		$posts = get_posts( array(
			'post_type'      => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
			'post_status'    => 'publish',
			'posts_per_page' => self::SCAN_LIMIT,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		$issues     = array();
		$titles_seen = array(); // title => primera URL con ese título

		foreach ( $posts as $post ) {
			$url   = get_permalink( $post );
			$title = get_the_title( $post );

			// Meta descripción ausente.
			$resolved = Cmdroom_Meta_Resolver::resolve_for_post( $post );
			if ( $resolved && '' === trim( (string) $resolved['description'] ) ) {
				$issues[] = self::issue( $url, 'missing_description' );
			}

			// Título duplicado.
			if ( '' !== trim( $title ) ) {
				if ( isset( $titles_seen[ $title ] ) ) {
					$issues[] = self::issue( $url, 'duplicate_title', sprintf( __( 'mismo título que %s', 'command-room' ), $titles_seen[ $title ] ) );
				} else {
					$titles_seen[ $title ] = $url;
				}
			}

			// Imagen destacada sin alt.
			$thumb_id = get_post_thumbnail_id( $post );
			if ( $thumb_id ) {
				$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
				if ( '' === trim( (string) $alt ) ) {
					$issues[] = self::issue( $url, 'missing_alt' );
				}
			}

			// H1 ausente en el contenido (ver limitación en el docblock).
			if ( false === stripos( $post->post_content, '<h1' ) ) {
				$issues[] = self::issue( $url, 'missing_h1' );
			}

			// Enlaces internos rotos dentro del contenido.
			foreach ( self::broken_internal_links( $post->post_content ) as $broken_url ) {
				$issues[] = self::issue( $url, 'broken_link', sprintf( __( 'hacia %s', 'command-room' ), $broken_url ) );
			}
		}

		return $issues;
	}

	private static function broken_internal_links( $content ) {
		if ( ! $content || false === stripos( $content, '<a ' ) ) {
			return array();
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$broken    = array();

		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $matches ) ) {
			return array();
		}

		foreach ( array_unique( $matches[1] ) as $href ) {
			if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			// Sin host = ruta relativa -- es interna. Con host, solo nos
			// interesa si es el mismo dominio (enlaces externos no se
			// comprueban, ver docblock de la clase).
			if ( $host && $host !== $home_host ) {
				continue;
			}
			if ( 0 === url_to_postid( $href ) && ! self::matches_known_term( $href ) ) {
				$broken[] = $href;
			}
		}

		return array_slice( $broken, 0, 3 ); // como mucho 3 enlaces rotos por entrada, para no inundar la tabla
	}

	/**
	 * Comparar solo contra get_term_link() (la URL "canónica") daba falsos
	 * positivos reales: en sitios con category_base vacío la ruta
	 * plana (/seccion/subseccion/) Y la ruta con prefijo /category/
	 * siguen resolviendo las dos. Comparar solo el último segmento de la ruta contra los
	 * slugs de término conocidos es más permisivo pero evita marcar como
	 * "roto" un enlace que en realidad funciona.
	 */
	private static function matches_known_term( $url ) {
		$path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
		if ( '' === $path ) {
			return true; // la home siempre es válida
		}
		$segments = explode( '/', $path );
		$last     = end( $segments );

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $tax ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'slugs', 'number' => 0 ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			if ( in_array( $last, $terms, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * URLs que Search Console ya ha indexado con parámetros de consulta --
	 * señal de que algo (paginación, filtros, tracking) se está colando en
	 * el índice sin querer.
	 */
	private static function scan_gsc_url_params() {
		if ( ! Cmdroom_Gsc_Settings::is_connected() ) {
			return array();
		}
		$pages = Cmdroom_Gsc_Dashboard::get_pages();
		if ( is_wp_error( $pages ) ) {
			return array();
		}
		$issues = array();
		foreach ( $pages as $row ) {
			$url = $row['keys'][0];
			if ( false !== strpos( $url, '?' ) ) {
				$issues[] = self::issue( $url, 'url_params' );
			}
		}
		return array_slice( $issues, 0, 10 );
	}

	private static function issue( $url, $type, $detail = '' ) {
		$meta = self::TYPE_LABELS[ $type ];
		return array(
			'url'      => $url,
			'type'     => $type,
			'label'    => $meta['label'],
			'detail'   => $detail,
			'severity' => $meta['severity'],
		);
	}

	/**
	 * Agrupa por tipo de problema (no por URL) -- pedido por el administrador
	 * 2026-09-24: la tabla ya no lista cada aparición suelta, muestra un
	 * ejemplo por tipo + cuántas veces se repite ("recurrencia"), y el
	 * resto se queda plegado detrás de un botón "Ver todos". Orden: más
	 * severo y más repetido primero.
	 */
	public static function get_grouped_issues() {
		$issues = self::get_issues();
		$groups = array();

		foreach ( $issues as $issue ) {
			$type = $issue['type'];
			if ( ! isset( $groups[ $type ] ) ) {
				$groups[ $type ] = array(
					'type'     => $type,
					'label'    => $issue['label'],
					'severity' => $issue['severity'],
					'items'    => array(),
				);
			}
			$groups[ $type ]['items'][] = $issue;
		}

		foreach ( $groups as &$group ) {
			usort( $group['items'], function ( $a, $b ) { return strcmp( $a['detected'], $b['detected'] ); } );
			$group['count']    = count( $group['items'] );
			$group['detected'] = $group['items'][0]['detected'];
		}
		unset( $group );

		$groups = array_values( $groups );
		$order  = array( 'critical' => 0, 'warning' => 1, 'info' => 2 );
		usort( $groups, function ( $a, $b ) use ( $order ) {
			$sev = $order[ $a['severity'] ] <=> $order[ $b['severity'] ];
			return 0 !== $sev ? $sev : ( $b['count'] <=> $a['count'] );
		} );

		return $groups;
	}
}
