<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cliente HTTP mínimo para la Chrome UX Report API (CrUX) v1 --
 * records:queryHistoryRecord: histórico de p75 por periodo de recogida
 * (ventana móvil de 28 días, un punto nuevo cada semana, hasta ~25
 * semanas). Solo lectura, autenticación por API key simple en la query
 * string (sin OAuth) -- ver Cmdroom_Crux_Settings.
 */
class Cmdroom_Crux_Client {

	const API_BASE = 'https://chromeuxreport.googleapis.com/v1/records:queryHistoryRecord';

	const METRICS = array(
		'largest_contentful_paint',
		'interaction_to_next_paint',
		'cumulative_layout_shift',
	);

	/**
	 * @param string $url Origen (https://ejemplo.com) o URL exacta de una
	 *                     página. CrUX no acepta patrones/comodines.
	 * @return array|WP_Error {
	 *     @type string[] $periods Fecha de cierre (lastDate) de cada periodo, YYYY-MM-DD.
	 *     @type array    $lcp     p75 de LCP en ms por periodo (mismo índice que $periods, null si falta).
	 *     @type array    $inp     p75 de INP en ms por periodo.
	 *     @type array    $cls     p75 de CLS (unitless) por periodo.
	 * }
	 */
	public static function query_history( $url ) {
		$api_key = Cmdroom_Crux_Settings::get_api_key();
		if ( ! $api_key ) {
			return new WP_Error( 'cmdroom_crux_not_configured', __( 'Falta la API key de CrUX.', 'command-room' ) );
		}

		$body                                   = array( 'metrics' => self::METRICS );
		$body[ self::looks_like_origin( $url ) ? 'origin' : 'url' ] = untrailingslashit( $url );

		$response = wp_remote_post(
			add_query_arg( 'key', rawurlencode( $api_key ), self::API_BASE ),
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// 404 es la respuesta normal de CrUX cuando esa URL/origen no tiene
		// tráfico suficiente para tener muestra -- no es un fallo de la
		// petición, el llamante lo trata como "sin datos", no como error.
		if ( 404 === $code ) {
			return new WP_Error( 'cmdroom_crux_no_data', __( 'Sin datos suficientes de CrUX para esta URL.', 'command-room' ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			$reason = isset( $data['error']['message'] ) ? $data['error']['message'] : wp_remote_retrieve_body( $response );
			return new WP_Error( 'cmdroom_crux_api_error', $reason );
		}

		return self::parse_record( $data );
	}

	private static function looks_like_origin( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' === trim( $path, '/' );
	}

	private static function parse_record( $data ) {
		$periods_raw = isset( $data['record']['collectionPeriods'] ) ? $data['record']['collectionPeriods'] : array();
		$metrics     = isset( $data['record']['metrics'] ) ? $data['record']['metrics'] : array();

		$periods = array();
		foreach ( $periods_raw as $period ) {
			$last      = isset( $period['lastDate'] ) ? $period['lastDate'] : null;
			$periods[] = $last ? sprintf( '%04d-%02d-%02d', $last['year'], $last['month'], $last['day'] ) : '';
		}

		return array(
			'periods' => $periods,
			'lcp'     => self::extract_p75( $metrics, 'largest_contentful_paint', 0 ),
			'inp'     => self::extract_p75( $metrics, 'interaction_to_next_paint', 0 ),
			'cls'     => self::extract_p75( $metrics, 'cumulative_layout_shift', 3 ),
		);
	}

	private static function extract_p75( $metrics, $key, $decimals ) {
		$series = isset( $metrics[ $key ]['percentilesTimeseries']['p75s'] ) ? $metrics[ $key ]['percentilesTimeseries']['p75s'] : array();
		return array_map(
			function ( $v ) use ( $decimals ) {
				return null === $v ? null : round( (float) $v, $decimals );
			},
			$series
		);
	}
}
