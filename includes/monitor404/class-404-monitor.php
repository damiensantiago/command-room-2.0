<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detecta 404s reales (después de que WordPress ya haya resuelto la
 * query — así no cuenta rutas que en realidad sí resuelven a algo) y los
 * registra en la tabla propia. No toca la respuesta ni el contenido de la
 * página 404: solo observa.
 *
 * Desde el rediseño "Servidor" (2026-09-23): los bots conocidos ya no se
 * registran en absoluto (antes se guardaban con un flag `is_bot`) — el
 * listado deja de tener esa columna y pasa a representar solo tráfico real
 * roto. Se añade un rate-limit de 60s por ruta para no escribir en cada
 * petición de un bot desconocido que insiste sobre la misma URL, y un
 * toggle "Registrar errores 404" (Cmdroom_Cleanup... no, ver
 * Cmdroom_404_Admin::is_logging_enabled()) que corta el registro entero.
 */
class Cmdroom_404_Monitor {

	/**
	 * user-agents que identifican crawlers conocidos (buscadores, bots de
	 * IA del módulo Robots.txt, herramientas SEO habituales) — no se
	 * registran nunca, para que el listado represente tráfico humano roto.
	 */
	const BOT_SIGNATURES = array(
		'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'preview',
		'gptbot', 'oai-searchbot', 'chatgpt-user', 'claudebot', 'claude-web',
		'perplexitybot', 'ccbot', 'bytespider', 'ahrefsbot', 'semrushbot', 'mj12bot',
	);

	/**
	 * Sufijos de ruta que nunca interesan en el listado — ficheros que
	 * navegadores/herramientas piden solos (mapas de fuente, favicon) y que
	 * no representan un enlace roto de verdad.
	 */
	const IGNORED_SUFFIXES = array( '.map', 'favicon.ico' );

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_record' ), 99 );
	}

	public static function maybe_record() {
		if ( ! is_404() || is_admin() ) {
			return;
		}
		if ( ! Cmdroom_404_Admin::is_logging_enabled() ) {
			return;
		}

		$path = self::normalize( wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
		if ( '' === $path || '/' === $path ) {
			return;
		}

		foreach ( self::IGNORED_SUFFIXES as $suffix ) {
			if ( $suffix === substr( $path, -strlen( $suffix ) ) ) {
				return;
			}
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		if ( self::is_bot( $user_agent ) ) {
			return;
		}

		// Rate-limit de 60s por ruta: si la misma URL rota se repite muy
		// seguido (bot desconocido sin firma reconocida, doble carga del
		// navegador), solo se escribe una vez por minuto.
		$rate_key = 'cmdroom_404_rl_' . md5( $path );
		if ( get_transient( $rate_key ) ) {
			return;
		}
		set_transient( $rate_key, 1, MINUTE_IN_SECONDS );

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 500 ) : '';

		Cmdroom_404_Table::record( $path, $referrer, $user_agent, false );
	}

	private static function is_bot( $user_agent ) {
		$ua = strtolower( $user_agent );
		if ( '' === $ua ) {
			return false;
		}
		foreach ( self::BOT_SIGNATURES as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalize( $path ) {
		return '/' . trim( (string) $path, '/' );
	}
}
