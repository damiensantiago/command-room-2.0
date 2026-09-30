<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sección "Rendimiento en Google Search Console" en "General" -- rediseño
 * completo 2026-09-24 sobre el handoff "Pantalla General" (alta fidelidad,
 * Modernist): tarjetas de métrica activables, gráfica de doble eje con
 * hover, selector de periodo 7/28/90 días. El selector de periodo y el
 * toggle de métricas son 100% cliente (ver assets/js/general-editor.js) --
 * PHP solo trae una única serie de 180 días (90 del rango máximo + 90 para
 * poder comparar "periodo anterior" incluso en el rango de 90 días) y la
 * embebe como JSON; el navegador recorta/agrega/dibuja.
 *
 * La tabla de Top URLs (sección 4 del handoff) sigue viviendo aquí porque
 * comparte la misma consulta de páginas (`get_pages()`) que ya existía.
 */
class Cmdroom_Gsc_Dashboard {

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	const METRICS = array(
		'clicks' => array( 'label' => 'Clics totales', 'color' => '#2563eb' ),
		'impr'   => array( 'label' => 'Impresiones totales', 'color' => '#7c3aed' ),
		'ctr'    => array( 'label' => 'CTR medio', 'color' => '#0d9488' ),
		'pos'    => array( 'label' => 'Posición media', 'color' => '#ea580c' ),
	);

	// Rango de posición media que se considera "con potencial" -- ya no se
	// usa en el propio dashboard (el handoff no lo pide), pero Cmdroom_General_Audit
	// lo reutiliza para las URLs con parámetros/posición floja.
	const POTENTIAL_MIN_POSITION    = 4;
	const POTENTIAL_MAX_POSITION    = 20;
	const POTENTIAL_MIN_IMPRESSIONS = 10;

	public static function render_general_section() {
		if ( ! Cmdroom_Gsc_Settings::is_connected() ) {
			?>
			<div class="cr-card cmdroom-gsc-card">
				<div class="cmdroom-gsc-head">
					<h2><?php esc_html_e( 'Rendimiento en Google Search Console', 'command-room' ); ?></h2>
				</div>
				<p class="description" style="padding:14px 18px;">
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s: URL to the Herramientas tab */
							__( 'No conectado. Configúralo en <a href="%s">Configuración → Herramientas</a>.', 'command-room' ),
							esc_url( Cmdroom_Config_Admin::tab_url( 'tools' ) )
						),
						array( 'a' => array( 'href' => array() ) )
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		$series = self::get_series();
		$pages  = self::get_pages();

		if ( is_wp_error( $series ) || is_wp_error( $pages ) ) {
			$error = is_wp_error( $series ) ? $series : $pages;
			?>
			<div class="cr-card cmdroom-gsc-card">
				<div class="cmdroom-gsc-head"><h2><?php esc_html_e( 'Rendimiento en Google Search Console', 'command-room' ); ?></h2></div>
				<p class="description" style="padding:14px 18px;"><?php echo esc_html( sprintf( __( 'No se pudieron traer datos: %s', 'command-room' ), $error->get_error_message() ) ); ?></p>
			</div>
			<?php
			return;
		}

		$payload = array(
			'series'  => $series,
			'metrics' => self::METRICS,
		);
		?>
		<div class="cr-card cmdroom-gsc-card" id="cmdroom-gsc-card">
			<div class="cmdroom-gsc-head">
				<div class="cmdroom-gsc-head-left">
					<h2><?php esc_html_e( 'Rendimiento en Google Search Console', 'command-room' ); ?></h2>
					<span class="cmdroom-gsc-range-label" data-cr-gsc-range-label></span>
				</div>
				<div class="cr-seg cmdroom-gsc-range-seg" role="group">
					<button type="button" class="cr-seg-opt" data-cr-gsc-range="7">7 días</button>
					<button type="button" class="cr-seg-opt is-active" data-cr-gsc-range="28">28 días</button>
					<button type="button" class="cr-seg-opt" data-cr-gsc-range="90">3 meses</button>
				</div>
			</div>

			<div class="cmdroom-gsc-cards" data-cr-gsc-cards>
				<?php foreach ( self::METRICS as $key => $m ) : ?>
					<button type="button" class="cmdroom-gsc-metric-card<?php echo in_array( $key, array( 'clicks', 'impr' ), true ) ? ' is-active' : ''; ?>" data-cr-gsc-metric="<?php echo esc_attr( $key ); ?>" style="--cmdroom-gsc-metric-color:<?php echo esc_attr( $m['color'] ); ?>">
						<span class="cmdroom-gsc-metric-label"><span class="cmdroom-gsc-metric-box"></span><?php echo esc_html( $m['label'] ); ?></span>
						<span class="cmdroom-gsc-metric-value" data-cr-gsc-value></span>
						<span class="cmdroom-gsc-metric-delta" data-cr-gsc-delta></span>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="cmdroom-gsc-chart-wrap">
				<div class="cmdroom-gsc-axis-labels">
					<span data-cr-gsc-axis-left></span>
					<span data-cr-gsc-axis-right></span>
				</div>
				<div class="cmdroom-gsc-chart-body">
					<div class="cmdroom-gsc-axis-col cmdroom-gsc-axis-col-left" data-cr-gsc-ticks-left></div>
					<div class="cmdroom-gsc-chart-area" data-cr-gsc-chart-area>
						<svg data-cr-gsc-svg viewBox="0 0 1000 240" preserveAspectRatio="none"></svg>
						<div class="cmdroom-gsc-tooltip" data-cr-gsc-tooltip hidden></div>
					</div>
					<div class="cmdroom-gsc-axis-col cmdroom-gsc-axis-col-right" data-cr-gsc-ticks-right></div>
				</div>
				<div class="cmdroom-gsc-xaxis" data-cr-gsc-xaxis></div>
			</div>
		</div>

		<script type="application/json" id="cmdroom-gsc-data"><?php echo wp_json_encode( $payload ); ?></script>
		<?php
	}

	/**
	 * Sección 4 del handoff -- va DESPUÉS de la tabla de módulos, no pegada
	 * a la sección de GSC de arriba, por eso vive en un método propio.
	 */
	public static function render_top_urls_section() {
		if ( ! Cmdroom_Gsc_Settings::is_connected() ) {
			return;
		}
		$pages = self::get_pages();
		if ( is_wp_error( $pages ) ) {
			return;
		}
		?>
		<div class="cr-card cmdroom-gsc-top-urls">
			<h3><?php esc_html_e( 'Top URLs', 'command-room' ); ?></h3>
			<?php self::render_pages_table( self::top_pages( $pages, 5 ) ); ?>
		</div>
		<?php
	}

	/**
	 * Serie diaria de 180 días (excluyendo los últimos 2-3 sin consolidar).
	 * 180 en vez de los 90 que pedía el handoff literalmente -- para poder
	 * calcular "variación vs. periodo anterior" también en el rango de 90
	 * días hace falta otro tramo de 90 días antes. El rango 7/28/90 y la
	 * comparación de periodo los recorta todo JS sobre esta única serie.
	 */
	public static function get_series() {
		$key    = 'cmdroom_gsc_series_180';
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$site_url = Cmdroom_Gsc_Settings::get_options()['site_url'];
		$rows     = Cmdroom_Gsc_Client::query_search_analytics(
			$site_url,
			array(
				'dimensions' => array( 'date' ),
				'start_date' => gmdate( 'Y-m-d', strtotime( '-180 days' ) ),
				'end_date'   => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
				'row_limit'  => 180,
			)
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$series = array();
		foreach ( $rows as $row ) {
			$series[] = array(
				'date'   => $row['keys'][0],
				'clicks' => (int) $row['clicks'],
				'impr'   => (int) $row['impressions'],
				'ctr'    => round( $row['ctr'] * 100, 2 ),
				'pos'    => round( $row['position'], 2 ),
			);
		}

		set_transient( $key, $series, self::CACHE_TTL );
		return $series;
	}

	public static function get_pages() {
		$key    = 'cmdroom_gsc_pages';
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$site_url = Cmdroom_Gsc_Settings::get_options()['site_url'];
		$rows     = Cmdroom_Gsc_Client::query_search_analytics(
			$site_url,
			array(
				'dimensions' => array( 'page' ),
				'row_limit'  => 500,
			)
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		set_transient( $key, $rows, self::CACHE_TTL );
		return $rows;
	}

	public static function top_pages( $pages, $limit ) {
		$sorted = $pages;
		usort(
			$sorted,
			function ( $a, $b ) {
				return $b['clicks'] <=> $a['clicks'];
			}
		);
		return array_slice( $sorted, 0, $limit );
	}

	public static function potential_pages( $pages, $limit = 10 ) {
		$filtered = array_values(
			array_filter(
				$pages,
				function ( $row ) {
					return $row['position'] >= self::POTENTIAL_MIN_POSITION
						&& $row['position'] <= self::POTENTIAL_MAX_POSITION
						&& $row['impressions'] >= self::POTENTIAL_MIN_IMPRESSIONS;
				}
			)
		);
		usort(
			$filtered,
			function ( $a, $b ) {
				return $b['impressions'] <=> $a['impressions'];
			}
		);
		return array_slice( $filtered, 0, $limit );
	}

	private static function render_pages_table( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'Sin datos todavía.', 'command-room' ) . '</p>';
			return;
		}
		?>
		<table class="cmdroom-gsc-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'URL', 'command-room' ); ?></th>
					<th><?php esc_html_e( 'Clics', 'command-room' ); ?></th>
					<th><?php esc_html_e( 'Impresiones', 'command-room' ); ?></th>
					<th><?php esc_html_e( 'CTR', 'command-room' ); ?></th>
					<th><?php esc_html_e( 'Pos. media', 'command-room' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $row['keys'][0] ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $row['keys'][0] ); ?></a></td>
						<td><?php echo esc_html( number_format_i18n( $row['clicks'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['impressions'] ) ); ?></td>
						<td><?php echo esc_html( round( $row['ctr'] * 100, 1 ) ); ?>%</td>
						<td><?php echo esc_html( round( $row['position'], 1 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
