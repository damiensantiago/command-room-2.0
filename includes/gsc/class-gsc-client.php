<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente HTTP mínimo para la Search Console API v3: solo lectura
 * (scope webmasters.readonly) -- Command Room nunca escribe en Search
 * Console, ni sitemaps ni URL inspection. Dos llamadas nada más:
 * listado de propiedades (para el selector de Cmdroom_Gsc_Settings) y
 * searchAnalytics.query (para las tablas de Cmdroom_Gsc_Dashboard).
 */
class Cmdroom_Gsc_Client {

	const API_BASE = 'https://www.googleapis.com/webmasters/v3';

	public static function get_access_token() {
		$cached = get_transient( 'cmdroom_gsc_access_token' );
		if ( $cached ) {
			return $cached;
		}

		$opts = Cmdroom_Gsc_Settings::get_options();
		if ( empty( $opts['refresh_token'] ) || ! Cmdroom_Gsc_Settings::has_shared_client() ) {
			return new WP_Error( 'cmdroom_gsc_not_connected', __( 'Google Search Console no está conectado.', 'command-room' ) );
		}

		$response = wp_remote_post(
			Cmdroom_Gsc_Settings::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'refresh_token' => $opts['refresh_token'],
					'client_id'     => CMDROOM_GSC_CLIENT_ID,
					'client_secret' => CMDROOM_GSC_CLIENT_SECRET,
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			$reason = isset( $body['error_description'] ) ? $body['error_description'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'cmdroom_gsc_token_refresh_failed', $reason );
		}

		// -120s de margen para no usar un token que caduque a mitad de
		// petición; Google normalmente da 3600s.
		$ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 120 ) : 3300;
		set_transient( 'cmdroom_gsc_access_token', $body['access_token'], $ttl );

		return $body['access_token'];
	}

	private static function request( $url, $args = array() ) {
		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$args = wp_parse_args(
			$args,
			array(
				'timeout' => 15,
				'headers' => array(),
			)
		);
		$args['headers']['Authorization'] = 'Bearer ' . $token;

		if ( isset( $args['body_json'] ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['method']                  = 'POST';
			$args['body']                    = wp_json_encode( $args['body_json'] );
			unset( $args['body_json'] );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$reason = isset( $body['error']['message'] ) ? $body['error']['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'cmdroom_gsc_api_error', $reason );
		}

		return $body;
	}

	public static function list_sites() {
		$body = self::request( self::API_BASE . '/sites' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		return isset( $body['siteEntry'] ) ? $body['siteEntry'] : array();
	}

	/**
	 * @param string $site_url Propiedad tal cual la devuelve Search Console
	 *                         (sc-domain:ejemplo.com o https://ejemplo.com/).
	 * @param array  $args     dimensions, start_date, end_date, row_limit.
	 * @return array|WP_Error  Filas crudas de la API (keys/clicks/impressions/ctr/position).
	 */
	public static function query_search_analytics( $site_url, $args = array() ) {
		$defaults = array(
			'dimensions' => array( 'date' ),
			// Search Console tarda ~2-3 días en consolidar los datos más
			// recientes -- pedir hasta "ayer" deja huecos/ceros engañosos
			// al final de la serie.
			'start_date' => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
			'end_date'   => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
			'row_limit'  => 1000,
		);
		$args = wp_parse_args( $args, $defaults );

		$url = self::API_BASE . '/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';

		$body = self::request(
			$url,
			array(
				'body_json' => array(
					'startDate'  => $args['start_date'],
					'endDate'    => $args['end_date'],
					'dimensions' => $args['dimensions'],
					'rowLimit'   => $args['row_limit'],
				),
			)
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return isset( $body['rows'] ) ? $body['rows'] : array();
	}
}
