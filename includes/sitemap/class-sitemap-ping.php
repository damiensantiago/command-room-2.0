<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Botón "Regenerar y hacer ping": invalida la caché de sitemaps (misma
 * rutina que ya dispara save_post/deleted_post) y avisa a Google/Bing de
 * que hay contenido nuevo. Solo tiene efecto real si la salida en el sitio
 * de Sitemaps está activada — si no, /sitemap_index.xml sigue siendo el de
 * Rank Math y el ping no serviría de nada.
 */
class Cmdroom_Sitemap_Ping {

	public static function init() {
		add_action( 'admin_post_cmdroom_ping_sitemaps', array( __CLASS__, 'handle_ping' ) );
	}

	public static function handle_ping() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_ping_sitemaps' );

		Cmdroom_Sitemap_Render::flush_cache();

		$report = array();

		if ( Cmdroom_Sitemap_Settings::is_live_output_enabled() ) {
			$sitemap_url = home_url( '/sitemap_index.xml' );

			$report['Google'] = self::ping( 'https://www.google.com/ping?sitemap=' . rawurlencode( $sitemap_url ) );
			$report['Bing']   = self::ping( 'https://www.bing.com/ping?sitemap=' . rawurlencode( $sitemap_url ) );
		} else {
			$report[ __( 'Aviso', 'command-room' ) ] = __( 'Salida en el sitio desactivada — solo se ha limpiado la caché, no se ha hecho ping.', 'command-room' );
		}

		set_transient( 'cmdroom_sitemap_ping_report', $report, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( add_query_arg( 'cmdroom_pinged', '1', wp_get_referer() ) );
		exit;
	}

	private static function ping( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return 'error — ' . $response->get_error_message();
		}

		$code = wp_remote_retrieve_response_code( $response );
		return (int) $code . ' ' . get_status_header_desc( $code );
	}
}
