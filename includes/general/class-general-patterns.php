<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sección "Patrones de éxito" de General (handoff 2026-09-24). No hay un
 * único algoritmo -- el propio handoff lo llama "lógica sugerida" y cada
 * una de las 4 filas de ejemplo compara cosas distintas, así que cada
 * patrón se calcula con la comparación que describe su propio texto:
 *
 * 1. Categoría   -- posición media actual vs. periodo anterior (mejora real en el tiempo)
 * 2. Año en título -- CTR de páginas con año reciente vs. sin él, EN LA MISMA franja de posición (no es evolución temporal)
 * 3. Bloque FAQ  -- impresiones de páginas con FAQ vs. sin FAQ, mismo periodo
 * 4. Intención de compra -- crecimiento de clics del propio grupo, periodo actual vs. anterior
 *
 * Todo corre sobre los datos que ya trae Cmdroom_Gsc_Dashboard (Top URLs +
 * páginas del periodo anterior) -- no hace peticiones nuevas a la API,
 * excepto una consulta extra de páginas para el periodo anterior. Cacheado
 * 6h junto con el resto de GSC.
 */
class Cmdroom_General_Patterns {

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	// Palabras que sugieren intención de compra en español -- lista fija,
	// no hay NLP real detrás. Es una aproximación a propósito, el propio
	// handoff lo describe como "lógica sugerida".
	const PURCHASE_INTENT_WORDS = array( 'comprar', 'precio', 'precios', 'mejor', 'mejores', 'dónde', 'donde', 'oferta', 'ofertas', 'barato', 'barata', 'opiniones', 'review', 'comparativa' );

	public static function get_patterns() {
		if ( ! Cmdroom_Gsc_Settings::is_connected() ) {
			return array();
		}

		$key    = 'cmdroom_general_patterns';
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$current  = Cmdroom_Gsc_Dashboard::get_pages();
		$previous = self::get_pages_previous();
		if ( is_wp_error( $current ) || is_wp_error( $previous ) ) {
			return array();
		}

		$resolved = self::resolve_pages( $current );

		$patterns   = array();
		$category   = self::pattern_category( $resolved, $current, $previous );
		$year       = self::pattern_year_in_title( $resolved, $current );
		$faq        = self::pattern_faq( $resolved, $current );
		$intent     = self::pattern_purchase_intent( $resolved, $current, $previous );
		foreach ( array( $category, $year, $faq, $intent ) as $p ) {
			if ( $p ) {
				$patterns[] = $p;
			}
		}

		// Orden por magnitud de la señal (cada patrón guarda su propio
		// "score" ya normalizado a "cuánto mejor va esto") -- las 4 filas
		// del handoff, en el orden que corresponda según los datos reales,
		// no un orden fijo de patrón.
		usort( $patterns, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );

		set_transient( $key, $patterns, self::CACHE_TTL );
		return $patterns;
	}

	private static function get_pages_previous() {
		$k = 'cmdroom_gsc_pages_previous';
		$c = get_transient( $k );
		if ( false !== $c ) {
			return $c;
		}
		$site_url = Cmdroom_Gsc_Settings::get_options()['site_url'];
		$rows     = Cmdroom_Gsc_Client::query_search_analytics(
			$site_url,
			array(
				'dimensions' => array( 'page' ),
				'start_date' => gmdate( 'Y-m-d', strtotime( '-56 days' ) ),
				'end_date'   => gmdate( 'Y-m-d', strtotime( '-28 days' ) ),
				'row_limit'  => 500,
			)
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		set_transient( $k, $rows, Cmdroom_General_Patterns::CACHE_TTL );
		return $rows;
	}

	/**
	 * Resuelve cada URL de GSC a post_id + título + grupo de Metas (mismo
	 * agrupador que usa Metas/Schema: home/contenido/corporativas). Solo
	 * páginas que resuelven a un post real de este sitio -- URLs externas o
	 * de archivo (categoría/tag) se quedan fuera de los patrones.
	 */
	private static function resolve_pages( $pages ) {
		$resolved = array();
		foreach ( $pages as $row ) {
			$url     = $row['keys'][0];
			$post_id = url_to_postid( $url );
			if ( ! $post_id ) {
				continue;
			}
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$resolved[ $url ] = array(
				'post_id'    => $post_id,
				'title'      => get_the_title( $post ),
				'group'      => 'page' === $post->post_type ? 'corporativas' : 'contenido',
				'categories' => 'post' === $post->post_type ? wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ) : array(),
			);
		}
		return $resolved;
	}

	private static function index_by_url( $rows ) {
		$by = array();
		foreach ( $rows as $row ) {
			$by[ $row['keys'][0] ] = $row;
		}
		return $by;
	}

	private static function pattern_category( $resolved, $current, $previous ) {
		$cur_by_url  = self::index_by_url( $current );
		$prev_by_url = self::index_by_url( $previous );

		$by_cat = array(); // cat => array( cur_pos_weighted_sum, cur_impr, prev_pos_weighted_sum, prev_impr )
		foreach ( $resolved as $url => $meta ) {
			foreach ( $meta['categories'] as $cat ) {
				if ( ! isset( $by_cat[ $cat ] ) ) {
					$by_cat[ $cat ] = array( 0.0, 0, 0.0, 0 );
				}
				$cur = $cur_by_url[ $url ] ?? null;
				if ( $cur ) {
					$by_cat[ $cat ][0] += $cur['position'] * $cur['impressions'];
					$by_cat[ $cat ][1] += $cur['impressions'];
				}
				$prev = $prev_by_url[ $url ] ?? null;
				if ( $prev ) {
					$by_cat[ $cat ][2] += $prev['position'] * $prev['impressions'];
					$by_cat[ $cat ][3] += $prev['impressions'];
				}
			}
		}

		$best = null;
		foreach ( $by_cat as $cat => $d ) {
			list( $cur_sum, $cur_impr, $prev_sum, $prev_impr ) = $d;
			if ( $cur_impr < 10 || $prev_impr < 10 ) {
				continue; // muestra insuficiente para fiarse de la comparación
			}
			$cur_pos  = $cur_sum / $cur_impr;
			$prev_pos = $prev_sum / $prev_impr;
			$improvement = $prev_pos - $cur_pos; // positivo = mejora (menos es mejor en posición)
			if ( null === $best || $improvement > $best['score'] ) {
				$best = array(
					'title'       => sprintf( __( 'Categoría %s', 'command-room' ), $cat ),
					'description' => __( 'Es la que más sube en posición en el periodo.', 'command-room' ),
					'pill1'       => sprintf( __( 'Pos. %s', 'command-room' ), number_format_i18n( $cur_pos, 1 ) ),
					'pill2'       => sprintf( '▲ %s', number_format_i18n( $improvement, 1 ) ),
					'score'       => $improvement,
				);
			}
		}
		return ( $best && $best['score'] > 0 ) ? $best : null;
	}

	private static function pattern_year_in_title( $resolved, $current ) {
		$cur_by_url = self::index_by_url( $current );
		$year_now   = (int) gmdate( 'Y' );
		$years      = array( (string) $year_now, (string) ( $year_now - 1 ) );

		$with = array( 'clicks' => 0, 'impr' => 0 );
		$without = array( 'clicks' => 0, 'impr' => 0 );

		foreach ( $resolved as $url => $meta ) {
			$row = $cur_by_url[ $url ] ?? null;
			if ( ! $row || $row['position'] < 4 || $row['position'] > 20 ) {
				continue; // "en la misma posición" -- se compara en una franja comparable, no todo el catálogo
			}
			$has_year = false;
			foreach ( $years as $y ) {
				if ( false !== strpos( $meta['title'], $y ) ) {
					$has_year = true;
					break;
				}
			}
			if ( $has_year ) {
				$with['clicks'] += $row['clicks'];
				$with['impr']   += $row['impressions'];
			} else {
				$without['clicks'] += $row['clicks'];
				$without['impr']   += $row['impressions'];
			}
		}

		if ( $with['impr'] < 20 || $without['impr'] < 20 ) {
			return null;
		}

		$ctr_with    = $with['clicks'] / $with['impr'] * 100;
		$ctr_without = $without['clicks'] / $without['impr'] * 100;
		$diff_pp     = $ctr_with - $ctr_without;

		if ( $diff_pp <= 0 ) {
			return null;
		}

		return array(
			'title'       => __( 'Año en el título', 'command-room' ),
			'description' => __( 'Los títulos con el año actual superan a los que no lo llevan en la misma posición.', 'command-room' ),
			'pill1'       => sprintf( '+%s pp CTR', number_format_i18n( $diff_pp, 1 ) ),
			'pill2'       => sprintf( '%d URLs', count( array_filter( $resolved, function ( $m ) use ( $years ) {
				foreach ( $years as $y ) { if ( false !== strpos( $m['title'], $y ) ) { return true; } }
				return false;
			} ) ) ),
			'score'       => $diff_pp / 5, // en la misma escala aprox. que las demás señales (pp pequeños, no deben dominar el ranking)
		);
	}

	private static function pattern_faq( $resolved, $current ) {
		$cur_by_url = self::index_by_url( $current );
		$schema     = Cmdroom_Schema_Settings::get_options();

		$with    = array( 'impr' => 0, 'n' => 0 );
		$without = array( 'impr' => 0, 'n' => 0 );

		foreach ( $resolved as $url => $meta ) {
			$row = $cur_by_url[ $url ] ?? null;
			if ( ! $row ) {
				continue;
			}
			$has_faq = self::group_has_faq( $schema, $meta['group'] );
			if ( $has_faq ) {
				$with['impr'] += $row['impressions'];
				$with['n']++;
			} else {
				$without['impr'] += $row['impressions'];
				$without['n']++;
			}
		}

		if ( 0 === $with['n'] || 0 === $without['n'] ) {
			return null; // el sitio no tiene FAQ activado en ningún grupo, o en todos -- no hay comparación posible
		}

		$avg_with    = $with['impr'] / $with['n'];
		$avg_without = $without['impr'] / $without['n'];
		if ( $avg_without <= 0 || $avg_with <= $avg_without ) {
			return null;
		}
		$ratio = $avg_with / $avg_without;

		return array(
			'title'       => __( 'Artículos con bloque FAQ', 'command-room' ),
			'description' => __( 'Las entradas con FAQ y su schema reciben más impresiones.', 'command-room' ),
			'pill1'       => sprintf( '×%s impresiones', number_format_i18n( $ratio, 1 ) ),
			'pill2'       => sprintf( '%d URLs', $with['n'] ),
			'score'       => min( $ratio, 5 ), // acotado para que un ratio disparado por poca muestra no domine el ranking
		);
	}

	private static function group_has_faq( $schema_opts, $group ) {
		$blocks = $schema_opts['groups'][ $group ] ?? array();
		foreach ( $blocks as $block ) {
			if ( isset( $block['type'] ) && 'FAQPage' === $block['type'] ) {
				return true;
			}
		}
		return false;
	}

	private static function pattern_purchase_intent( $resolved, $current, $previous ) {
		$cur_by_url  = self::index_by_url( $current );
		$prev_by_url = self::index_by_url( $previous );

		$cur_clicks  = 0;
		$prev_clicks = 0;
		$matched_urls = array();

		foreach ( $resolved as $url => $meta ) {
			if ( ! self::looks_like_purchase_intent( $meta['title'] . ' ' . $url ) ) {
				continue;
			}
			$matched_urls[] = $url;
			if ( isset( $cur_by_url[ $url ] ) ) {
				$cur_clicks += $cur_by_url[ $url ]['clicks'];
			}
			if ( isset( $prev_by_url[ $url ] ) ) {
				$prev_clicks += $prev_by_url[ $url ]['clicks'];
			}
		}

		if ( count( $matched_urls ) < 2 || $prev_clicks < 5 ) {
			return null;
		}

		$growth = ( $cur_clicks - $prev_clicks ) / $prev_clicks * 100;
		if ( $growth <= 0 ) {
			return null;
		}

		return array(
			'title'       => __( 'Guías con intención de compra', 'command-room' ),
			'description' => __( 'Las URLs con intención de compra concentran los clics nuevos.', 'command-room' ),
			'pill1'       => sprintf( '+%s%% clics', number_format_i18n( $growth, 0 ) ),
			'pill2'       => sprintf( '%d URLs', count( $matched_urls ) ),
			'score'       => $growth / 20,
		);
	}

	private static function looks_like_purchase_intent( $text ) {
		$text = mb_strtolower( $text );
		foreach ( self::PURCHASE_INTENT_WORDS as $word ) {
			if ( false !== mb_strpos( $text, $word ) ) {
				return true;
			}
		}
		return false;
	}
}
