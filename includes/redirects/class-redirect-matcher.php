<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aplica las redirecciones por template_redirect — nunca por .htaccess.
 * Gated por "Salida en el sitio" para no chocar con el gestor de
 * redirecciones de Rank Math mientras conviven.
 */
class Cmdroom_Redirect_Matcher {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	public static function maybe_redirect() {
		if ( ! Cmdroom_Redirect_Admin::is_live_output_enabled() ) {
			return;
		}

		$path = self::normalize( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
		if ( '' === $path ) {
			return;
		}

		$row = Cmdroom_Redirect_Table::get_by_source( $path );

		if ( ! $row ) {
			foreach ( Cmdroom_Redirect_Table::get_active_regex_rules() as $rule ) {
				if ( @preg_match( '#' . $rule['source'] . '#i', $path ) ) {
					$row = $rule;
					break;
				}
			}
		}

		if ( ! $row ) {
			return;
		}

		$code = (int) $row['redirect_type'];

		Cmdroom_Redirect_Table::increment_hits( $row['id'] );
		Cmdroom_Redirect_Table::log_hit( $row['id'], $code, $path );

		// 410 (Gone) y 451 (no disponible por motivos legales) no son
		// redirecciones de verdad: no hay Location, solo el código y una
		// página de aviso — usamos la plantilla 404 del tema para no
		// depender de una plantilla propia que quizá no exista.
		if ( in_array( $code, array( 410, 451 ), true ) ) {
			status_header( $code );
			nocache_headers();
			global $wp_query;
			if ( $wp_query instanceof WP_Query ) {
				$wp_query->set_404();
			}
			$template = get_query_template( '404' );
			if ( $template ) {
				include $template;
			} else {
				// Temas FSE (bloques) no siempre traen 404.php clásico —
				// mensaje mínimo en vez de un include() vacío.
				echo '<h1>' . esc_html( 410 === $code ? __( 'Contenido eliminado', 'command-room' ) : __( 'No disponible por motivos legales', 'command-room' ) ) . '</h1>';
			}
			exit;
		}

		wp_redirect( self::resolve_destination( $row, $path ), $code );
		exit;
	}

	/**
	 * En una regla "exacto" el destino siempre es el texto tal cual. En
	 * "regex" además admite $1, $2... para reusar lo capturado por el
	 * patrón de origen -- así una carpeta entera puede redirigir a otra
	 * conservando la subruta (origen "^carpeta-vieja/(.*)$", destino
	 * "/carpeta-nueva/$1") en vez de mandar todo a una única URL fija.
	 */
	private static function resolve_destination( $row, $path ) {
		if ( 'regex' !== $row['source_type'] ) {
			return $row['destination'];
		}
		$resolved = @preg_replace( '#' . $row['source'] . '#i', $row['destination'], $path );
		return null === $resolved ? $row['destination'] : $resolved;
	}

	private static function normalize( $path ) {
		return trim( (string) $path, '/' );
	}
}
