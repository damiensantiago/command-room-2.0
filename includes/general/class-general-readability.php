<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sección "Análisis y legibilidad" de General (handoff 2026-09-24).
 * Decisión del administrador 2026-09-24: analiza la última entrada/página
 * publicada, no todo el sitio -- tiene sentido como "vistazo rápido" en un
 * dashboard sitewide y se actualiza sola con cada publicación nueva.
 *
 * Todo se calcula sobre texto real (post_content de esa entrada), nada de
 * datos de ejemplo -- pero son heurísticas con regex sobre HTML/texto, no
 * un analizador lingüístico de verdad (lo mismo que hace Yoast/Rank Math
 * por debajo, en el fondo). La "palabra clave" no existe como campo propio
 * en Command Room, así que se aproxima con la palabra significativa más
 * repetida del contenido -- documentado tal cual en el admin, no se vende
 * como palabra clave elegida a mano.
 */
class Cmdroom_General_Readability {

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	const STOPWORDS_ES = array( 'de', 'la', 'que', 'el', 'en', 'y', 'a', 'los', 'del', 'se', 'las', 'por', 'un', 'para', 'con', 'no', 'una', 'su', 'al', 'lo', 'como', 'más', 'pero', 'sus', 'le', 'ya', 'o', 'este', 'sí', 'porque', 'esta', 'entre', 'cuando', 'muy', 'sin', 'sobre', 'también', 'me', 'hasta', 'hay', 'donde', 'quien', 'desde', 'todo', 'nos', 'durante', 'todos', 'uno', 'les', 'ni', 'contra', 'otros', 'ese', 'eso', 'ante', 'ellos', 'e', 'esto', 'mí', 'antes', 'algunos', 'qué', 'unos', 'yo', 'otro', 'otras', 'otra', 'él', 'tanto', 'esa', 'estos', 'mucho', 'quienes', 'nada', 'muchos', 'cual', 'poco', 'ella', 'estar', 'estas', 'algunas', 'algo', 'nosotros' );

	public static function get_analysis() {
		$cached = get_transient( 'cmdroom_general_readability' );
		if ( false !== $cached ) {
			return $cached;
		}

		$post = self::latest_post();
		if ( ! $post ) {
			$result = null;
			set_transient( 'cmdroom_general_readability', $result, self::CACHE_TTL );
			return $result;
		}

		$text = wp_strip_all_tags( $post->post_content );

		$result = array(
			'post_title' => get_the_title( $post ),
			'post_url'   => get_permalink( $post ),
			'seo'        => self::seo_checks( $post, $text ),
			'readability' => self::readability_checks( $post, $text ),
		);

		set_transient( 'cmdroom_general_readability', $result, self::CACHE_TTL );
		return $result;
	}

	private static function latest_post() {
		$posts = get_posts( array(
			'post_type'      => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		return $posts ? $posts[0] : null;
	}

	private static function seo_checks( $post, $text ) {
		$checks = array();

		$keyword = self::guess_keyword( $text );
		if ( $keyword ) {
			$density = self::keyword_density( $text, $keyword );
			$checks[] = array(
				'state' => ( $density >= 0.5 && $density <= 2.5 ) ? 'good' : 'ok',
				'text'  => sprintf(
					/* translators: 1: keyword, 2: density percentage */
					__( 'Densidad de "%1$s" (aproximada): %2$s%%', 'command-room' ),
					$keyword,
					number_format_i18n( $density, 1 )
				),
			);
		}

		$h2_count = preg_match_all( '/<h2[\s>]/i', $post->post_content );
		$checks[] = array(
			'state' => $h2_count > 0 ? 'good' : 'bad',
			'text'  => $h2_count > 0
				? __( 'Encabezados H2/H3 presentes en el contenido', 'command-room' )
				: __( 'No hay ningún H2 en el contenido', 'command-room' ),
		);

		$has_inbound = self::has_inbound_internal_link( get_permalink( $post ), $post->ID );
		$checks[] = array(
			'state' => $has_inbound ? 'good' : 'bad',
			'text'  => $has_inbound
				? __( 'Otras entradas recientes enlazan hacia esta', 'command-room' )
				: __( 'Faltan enlaces internos hacia esta entrada (entre las últimas 50 publicadas)', 'command-room' ),
		);

		$thumb_id = get_post_thumbnail_id( $post );
		$has_alt  = $thumb_id && trim( (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ) !== '';
		$checks[] = array(
			'state' => ! $thumb_id ? 'ok' : ( $has_alt ? 'good' : 'bad' ),
			'text'  => ! $thumb_id
				? __( 'Sin imagen destacada', 'command-room' )
				: ( $has_alt ? __( 'La imagen destacada tiene texto alternativo', 'command-room' ) : __( 'La imagen destacada no tiene texto alternativo', 'command-room' ) ),
		);

		return $checks;
	}

	private static function readability_checks( $post, $text ) {
		$checks    = array();
		$paragraphs = self::extract_paragraphs( $post->post_content );
		$sentences  = self::split_sentences( $text );
		$word_count = str_word_count( $text );

		$long_paragraphs = array_filter( $paragraphs, function ( $p ) {
			return str_word_count( $p ) >= 150;
		} );
		$checks[] = array(
			'state' => empty( $long_paragraphs ) ? 'good' : 'bad',
			'text'  => empty( $long_paragraphs )
				? __( 'Párrafos cortos (menos de 150 palabras)', 'command-room' )
				: sprintf( __( '%d párrafo(s) superan las 150 palabras', 'command-room' ), count( $long_paragraphs ) ),
		);

		$long_sentences = array_filter( $sentences, function ( $s ) {
			return str_word_count( $s ) > 25;
		} );
		$checks[] = array(
			'state' => count( $long_sentences ) > 3 ? 'bad' : ( count( $long_sentences ) > 0 ? 'ok' : 'good' ),
			'text'  => count( $long_sentences ) > 0
				? sprintf( __( '%d frase(s) superan las 25 palabras', 'command-room' ), count( $long_sentences ) )
				: __( 'Ninguna frase supera las 25 palabras', 'command-room' ),
		);

		$heading_count = preg_match_all( '/<h[23][\s>]/i', $post->post_content );
		$words_per_heading = $heading_count > 0 && $word_count > 0 ? $word_count / $heading_count : $word_count;
		$checks[] = array(
			'state' => $words_per_heading <= 300 ? 'good' : 'bad',
			'text'  => $heading_count > 0
				? sprintf( __( 'Un subtítulo cada %d palabras aprox.', 'command-room' ), round( $words_per_heading ) )
				: __( 'El contenido no tiene subtítulos (H2/H3)', 'command-room' ),
		);

		$passive_ratio = self::passive_voice_ratio( $sentences );
		$checks[] = array(
			'state' => $passive_ratio <= 10 ? 'good' : ( $passive_ratio <= 20 ? 'ok' : 'bad' ),
			'text'  => sprintf( __( 'Voz pasiva (aproximada) en el %s%% de las frases', 'command-room' ), number_format_i18n( $passive_ratio, 0 ) ),
		);

		return $checks;
	}

	private static function guess_keyword( $text ) {
		$words = preg_split( '/[^\p{L}]+/u', mb_strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$freq  = array();
		foreach ( $words as $w ) {
			if ( mb_strlen( $w ) < 4 || in_array( $w, self::STOPWORDS_ES, true ) ) {
				continue;
			}
			$freq[ $w ] = ( $freq[ $w ] ?? 0 ) + 1;
		}
		if ( ! $freq ) {
			return '';
		}
		arsort( $freq );
		return array_key_first( $freq );
	}

	private static function keyword_density( $text, $keyword ) {
		$total = str_word_count( $text );
		if ( 0 === $total ) {
			return 0.0;
		}
		$count = substr_count( mb_strtolower( $text ), $keyword );
		return $count / $total * 100;
	}

	private static function extract_paragraphs( $html ) {
		preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $html, $m );
		return array_map( 'wp_strip_all_tags', $m[1] ?? array() );
	}

	private static function split_sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?])\s+/u', trim( $text ) );
		return array_values( array_filter( $parts, function ( $s ) { return '' !== trim( $s ); } ) );
	}

	/**
	 * Heurística de voz pasiva en español: "ser/estar" conjugado seguido
	 * de un participio (terminación -ado/-ada/-ados/-adas/-ido/-ida/-idos/
	 * -idas) dentro de la misma frase. Aproximado a propósito -- no hay
	 * analizador gramatical real detrás.
	 */
	private static function passive_voice_ratio( $sentences ) {
		if ( ! $sentences ) {
			return 0.0;
		}
		$passive_count = 0;
		foreach ( $sentences as $s ) {
			if ( preg_match( '/\b(es|son|fue|fueron|era|eran|ha sido|han sido|será|serán)\b\s+\w*(ad[oa]s?|id[oa]s?)\b/iu', $s ) ) {
				$passive_count++;
			}
		}
		return $passive_count / count( $sentences ) * 100;
	}

	private static function has_inbound_internal_link( $url, $exclude_post_id ) {
		$recent = get_posts( array(
			'post_type'      => array_values( get_post_types( array( 'public' => true ), 'names' ) ),
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'exclude'        => array( $exclude_post_id ),
		) );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		foreach ( $recent as $p ) {
			if ( false !== strpos( $p->post_content, $path ) ) {
				return true;
			}
		}
		return false;
	}
}
