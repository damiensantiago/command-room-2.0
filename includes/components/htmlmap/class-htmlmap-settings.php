<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes del componente "Sitemap HTML" -- pantalla admin + vista previa
 * interactiva. Sigue el patrón de Cmdroom_Affiliate_Settings/Cmdroom_Reco_Settings:
 * el <form> principal guarda por admin-post (blocks, tipos de contenido,
 * paginación, criterio de top, enlazado), y el toggle "Activar sitemap
 * HTML" vive fuera de ese <form> con AJAX instantáneo (Cmdroom_Htmlmap::
 * handle_toggle_enabled) para no repetir el bug de Afiliación (guardar el
 * form principal pisando `enabled` a false).
 *
 * La vista previa (nivel/migas/celdas/paginación) es la pieza nueva de
 * este componente: navega dentro del propio wp-admin por AJAX
 * (wp_ajax_cmdroom_htmlmap_preview), sin recargar la página y sin tocar
 * ninguna URL pública -- todo el estado de navegación (view/y/m/d/page)
 * vive en el cliente y se resuelve contra datos reales del sitio en cada
 * petición. Esta clase solo pinta admin.php?page=command-room-componentes&tab=htmlmap;
 * las páginas públicas, el shortcode y el enlace del footer están en
 * Cmdroom_Htmlmap_Frontend.
 */
class Cmdroom_Htmlmap_Settings {

	public static function init() {
		add_action( 'admin_post_cmdroom_save_htmlmap', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_cmdroom_htmlmap_preview', array( __CLASS__, 'handle_preview_ajax' ) );
	}

	public static function tab_url( $extra = array() ) {
		return add_query_arg( $extra, Cmdroom_Components_Admin::tab_url( 'htmlmap' ) );
	}

	/* ---------------------------------------------------------------- */
	/* Guardado                                                          */
	/* ---------------------------------------------------------------- */

	private static function sanitize_slug_post() {
		$raw  = isset( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '';
		$slug = strtolower( $raw );
		$slug = preg_replace( '/[^a-z0-9-]/', '', $slug );
		return '' !== $slug ? $slug : Cmdroom_Htmlmap::DEFAULTS['slug'];
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_htmlmap' );

		// 'enabled' NO se toca aquí -- vive fuera de este <form> (toggle
		// instantáneo por AJAX, ver Cmdroom_Htmlmap::handle_toggle_enabled).
		$opts = Cmdroom_Htmlmap::get_options();

		$opts['slug'] = self::sanitize_slug_post();

		foreach ( array_keys( $opts['blocks'] ) as $key ) {
			$opts['blocks'][ $key ] = ! empty( $_POST[ 'block_' . $key ] );
		}

		$top_by         = isset( $_POST['top_by'] ) ? sanitize_key( wp_unslash( $_POST['top_by'] ) ) : 'views';
		$opts['top_by'] = in_array( $top_by, Cmdroom_Htmlmap::TOP_BY, true ) ? $top_by : 'views';
		$opts['top_n']  = isset( $_POST['top_n'] ) ? max( 1, min( 20, (int) $_POST['top_n'] ) ) : 10;
		$opts['tag_n']  = isset( $_POST['tag_n'] ) ? max( 1, min( 20, (int) $_POST['tag_n'] ) ) : 10;

		$per_day          = isset( $_POST['per_day'] ) ? (int) $_POST['per_day'] : 10;
		$opts['per_day']  = in_array( $per_day, array( 5, 10 ), true ) ? $per_day : 10;

		$empty          = isset( $_POST['empty'] ) ? sanitize_key( wp_unslash( $_POST['empty'] ) ) : 'hide';
		$opts['empty']  = in_array( $empty, array( 'hide', 'show' ), true ) ? $empty : 'hide';

		$robots               = isset( $_POST['date_robots'] ) ? sanitize_key( wp_unslash( $_POST['date_robots'] ) ) : 'noindex';
		$opts['date_robots']  = in_array( $robots, array( 'noindex', 'index' ), true ) ? $robots : 'noindex';

		foreach ( array_keys( $opts['post_types'] ) as $slug ) {
			$opts['post_types'][ $slug ] = ! empty( $_POST[ 'pt_' . $slug ] );
		}

		$opts['footer_link'] = ! empty( $_POST['footer_link'] );
		$opts['tags_link']   = ! empty( $_POST['tags_link'] );

		Cmdroom_Htmlmap::update_options( $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', self::tab_url() ) );
		exit;
	}

	/* ---------------------------------------------------------------- */
	/* Estado de navegación de la vista previa (no se guarda)            */
	/* ---------------------------------------------------------------- */

	private static function normalize_state( $raw, $opts ) {
		list( $ty, $tm, $td ) = Cmdroom_Htmlmap::today();

		$view = isset( $raw['view'] ) ? sanitize_key( $raw['view'] ) : 'home';
		if ( ! in_array( $view, array( 'home', 'year', 'month', 'day', 'tags' ), true ) ) {
			$view = 'home';
		}

		$y = isset( $raw['y'] ) && (int) $raw['y'] > 2000 ? (int) $raw['y'] : $ty;
		$m = isset( $raw['m'] ) && (int) $raw['m'] >= 1 && (int) $raw['m'] <= 12 ? (int) $raw['m'] : $tm;
		$d = isset( $raw['d'] ) && (int) $raw['d'] >= 1 && (int) $raw['d'] <= 31 ? (int) $raw['d'] : $td;
		$page = isset( $raw['page'] ) ? max( 1, (int) $raw['page'] ) : 1;

		// Nunca fechas por delante de hoy.
		$today_ts   = mktime( 0, 0, 0, $tm, $td, $ty );
		$picked_ts  = mktime( 0, 0, 0, $m, min( $d, (int) date( 't', mktime( 0, 0, 0, $m, 1, $y ) ) ), $y );
		if ( $picked_ts > $today_ts ) {
			list( $y, $m, $d ) = array( $ty, $tm, $td );
		}
		$d = min( $d, (int) date( 't', mktime( 0, 0, 0, $m, 1, $y ) ) );

		return compact( 'view', 'y', 'm', 'd', 'page' );
	}

	/* ---------------------------------------------------------------- */
	/* AJAX -- navegación de la vista previa                             */
	/* ---------------------------------------------------------------- */

	public static function handle_preview_ajax() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cmdroom_htmlmap_preview', '_wpnonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$opts  = Cmdroom_Htmlmap::get_options();
		$state = self::normalize_state( $_POST, $opts );

		ob_start();
		self::render_preview_root( $opts, $state );
		$html = ob_get_clean();

		wp_send_json_success( array( 'html' => $html ) );
	}

	/* ---------------------------------------------------------------- */
	/* A. Fila superior                                                  */
	/* ---------------------------------------------------------------- */

	private static function render_top_row( $opts ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		?>
		<div class="cmdroom-aff-top-row">
			<div class="cmdroom-htmlmap-urlfield">
				<label class="cr-label" for="cmdroom-htmlmap-slug"><?php esc_html_e( 'URL base', 'command-room' ); ?></label>
				<div class="cmdroom-htmlmap-urlfield-box">
					<span class="cmdroom-htmlmap-urlfield-prefix"><?php echo esc_html( $host . '/' ); ?></span>
					<input
						type="text"
						id="cmdroom-htmlmap-slug"
						class="cmdroom-htmlmap-urlfield-input cmdroom-aff-mono"
						name="slug"
						form="cmdroom-htmlmap-form"
						value="<?php echo esc_attr( $opts['slug'] ); ?>"
						maxlength="40"
					/>
					<span class="cmdroom-htmlmap-urlfield-suffix">/año/mes/día/</span>
				</div>
			</div>
			<div
				class="cr-card cmdroom-aff-activation"
				data-cr-htmlmap-toggle-enabled
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'cmdroom_htmlmap_toggle_enabled' ) ); ?>"
			>
				<button type="button" class="cr-toggle<?php echo $opts['enabled'] ? ' is-on' : ''; ?>"><span class="cr-toggle-knob"></span><input type="checkbox" <?php checked( $opts['enabled'] ); ?> /></button>
				<div>
					<p class="cmdroom-aff-activation-title"><?php esc_html_e( 'Activar sitemap HTML', 'command-room' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* B1. Barra de niveles                                              */
	/* ---------------------------------------------------------------- */

	private static function levels_meta( $opts, $state ) {
		$n_blocks = count( array_filter( $opts['blocks'] ) );
		return array(
			'home'  => array( 'num' => '01 →', 'label' => __( 'Portada', 'command-room' ), 'ruta' => '/{slug}/', 'desc' => sprintf( _n( '%d bloque', '%d bloques', $n_blocks, 'command-room' ), $n_blocks ) ),
			'year'  => array( 'num' => '02 →', 'label' => __( 'Año', 'command-room' ), 'ruta' => '/{slug}/' . $state['y'] . '/', 'desc' => __( 'Meses del año', 'command-room' ) ),
			'month' => array( 'num' => '03 →', 'label' => __( 'Mes', 'command-room' ), 'ruta' => '/{slug}/' . $state['y'] . '/' . str_pad( $state['m'], 2, '0', STR_PAD_LEFT ) . '/', 'desc' => __( 'Días del mes', 'command-room' ) ),
			'day'   => array( 'num' => '04', 'label' => __( 'Día', 'command-room' ), 'ruta' => '/{slug}/' . $state['y'] . '/' . str_pad( $state['m'], 2, '0', STR_PAD_LEFT ) . '/' . str_pad( $state['d'], 2, '0', STR_PAD_LEFT ) . '/', 'desc' => sprintf( __( '%d contenidos por página', 'command-room' ), $opts['per_day'] ) ),
			'tags'  => array( 'num' => '05', 'label' => __( 'Tags', 'command-room' ), 'ruta' => '/{slug}/tags/', 'desc' => __( 'Todos, de la A a la Z', 'command-room' ) ),
		);
	}

	private static function render_level_bar( $opts, $state ) {
		$levels = self::levels_meta( $opts, $state );
		?>
		<div class="cmdroom-htmlmap-levelbar">
			<?php foreach ( $levels as $key => $meta ) :
				$ruta = str_replace( '{slug}', $opts['slug'], $meta['ruta'] );
				?>
				<button
					type="button"
					class="cmdroom-htmlmap-level<?php echo $state['view'] === $key ? ' is-active' : ''; ?>"
					data-cr-htmlmap-nav
					data-view="<?php echo esc_attr( $key ); ?>"
					data-y="<?php echo esc_attr( $state['y'] ); ?>"
					data-m="<?php echo esc_attr( $state['m'] ); ?>"
					data-d="<?php echo esc_attr( $state['d'] ); ?>"
				>
					<span class="cmdroom-htmlmap-level-num"><?php echo esc_html( $meta['num'] ); ?></span>
					<span class="cmdroom-htmlmap-level-name"><?php echo esc_html( $meta['label'] ); ?></span>
					<span class="cmdroom-htmlmap-level-ruta"><?php echo esc_html( $ruta ); ?></span>
					<span class="cmdroom-htmlmap-level-desc"><?php echo esc_html( $meta['desc'] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* B2. Marco del navegador + migas + título                          */
	/* ---------------------------------------------------------------- */

	private static function build_url_bar( $opts, $state ) {
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$levels = self::levels_meta( $opts, $state );
		$ruta  = str_replace( '{slug}', $opts['slug'], $levels[ $state['view'] ]['ruta'] );
		$url   = $host . $ruta;
		if ( 'day' === $state['view'] && $state['page'] > 1 ) {
			$url = rtrim( $url, '/' ) . '/page/' . $state['page'] . '/';
		}
		return $url;
	}

	private static function render_breadcrumbs( $opts, $state ) {
		$crumbs = array( array( 'label' => __( 'Mapa del sitio', 'command-room' ), 'view' => 'home' ) );
		if ( in_array( $state['view'], array( 'year', 'month', 'day' ), true ) ) {
			$crumbs[] = array( 'label' => (string) $state['y'], 'view' => 'year', 'y' => $state['y'] );
		}
		if ( in_array( $state['view'], array( 'month', 'day' ), true ) ) {
			$crumbs[] = array( 'label' => Cmdroom_Htmlmap::MESES[ $state['m'] ], 'view' => 'month', 'y' => $state['y'], 'm' => $state['m'] );
		}
		if ( 'day' === $state['view'] ) {
			$crumbs[] = array( 'label' => (string) $state['d'], 'view' => 'day', 'y' => $state['y'], 'm' => $state['m'], 'd' => $state['d'] );
		}
		if ( 'tags' === $state['view'] ) {
			$crumbs[] = array( 'label' => __( 'Tags', 'command-room' ), 'view' => 'tags' );
		}
		?>
		<nav class="cmdroom-htmlmap-crumbs">
			<?php foreach ( $crumbs as $i => $c ) :
				$is_last = ( $i === count( $crumbs ) - 1 );
				?>
				<?php if ( $i > 0 ) : ?><span class="cmdroom-htmlmap-crumb-sep">/</span><?php endif; ?>
				<?php if ( $is_last ) : ?>
					<span class="cmdroom-htmlmap-crumb is-current"><?php echo esc_html( $c['label'] ); ?></span>
				<?php else : ?>
					<button
						type="button"
						class="cmdroom-htmlmap-crumb"
						data-cr-htmlmap-nav
						data-view="<?php echo esc_attr( $c['view'] ); ?>"
						data-y="<?php echo esc_attr( isset( $c['y'] ) ? $c['y'] : $state['y'] ); ?>"
						data-m="<?php echo esc_attr( isset( $c['m'] ) ? $c['m'] : $state['m'] ); ?>"
						data-d="<?php echo esc_attr( isset( $c['d'] ) ? $c['d'] : $state['d'] ); ?>"
					><?php echo esc_html( $c['label'] ); ?></button>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	public static function head_title_subtitle( // pública: la reutiliza Cmdroom_Htmlmap_Frontend
		 $opts, $state, $types ) {
		$site_name = get_bloginfo( 'name' );
		switch ( $state['view'] ) {
			case 'year':
				$n = Cmdroom_Htmlmap::count_year( $types, $state['y'] );
				return array( (string) $state['y'], sprintf( _n( '%1$d contenido publicado en %2$d.', '%1$d contenidos publicados en %2$d.', $n, 'command-room' ), $n, $state['y'] ) );
			case 'month':
				$n = Cmdroom_Htmlmap::count_month( $types, $state['y'], $state['m'] );
				return array( Cmdroom_Htmlmap::month_year( $state['y'], $state['m'] ), sprintf( __( '%1$d contenidos · un enlace por día.', 'command-room' ), $n ) );
			case 'day':
				$n = Cmdroom_Htmlmap::count_day( $types, $state['y'], $state['m'], $state['d'] );
				$per_page = $opts['per_day'];
				$pages = max( 1, (int) ceil( $n / max( 1, $per_page ) ) );
				$sub = sprintf( _n( '%d contenido', '%d contenidos', $n, 'command-room' ), $n );
				if ( $pages > 1 ) {
					$sub .= ' · ' . sprintf( __( 'página %1$d de %2$d', 'command-room' ), $state['page'], $pages );
				}
				return array( Cmdroom_Htmlmap::long_date( $state['y'], $state['m'], $state['d'] ), $sub );
			case 'tags':
				$n = count( Cmdroom_Htmlmap::all_tags_with_counts() );
				return array( __( 'Tags', 'command-room' ), sprintf( _n( '%d tag, de la A a la Z.', '%d tags, de la A a la Z.', $n, 'command-room' ), $n ) );
			default:
				return array( __( 'Mapa del sitio', 'command-room' ), sprintf( __( 'Todo el contenido de %s, por fecha y por tema.', 'command-room' ), $site_name ) );
		}
	}

	private static function fmt_count( $n ) {
		return sprintf( _n( '%s contenido', '%s contenidos', $n, 'command-room' ), number_format_i18n( $n ) );
	}

	/* — Bloques reutilizables (portada + niveles internos) — */

	private static function render_block_d7( $types ) {
		$items = Cmdroom_Htmlmap::recent_week_items( $types );
		$total = count( $items );
		$shown = array_slice( $items, 0, 8 );
		?>
		<div class="cmdroom-htmlmap-block">
			<div class="cmdroom-htmlmap-block-head">
				<span class="cmdroom-htmlmap-block-title"><?php esc_html_e( 'Últimos 7 días', 'command-room' ); ?></span>
				<span class="cmdroom-htmlmap-block-count"><?php echo esc_html( self::fmt_count( $total ) ); ?></span>
			</div>
			<?php if ( empty( $shown ) ) : ?>
				<p class="cmdroom-htmlmap-empty-note"><?php esc_html_e( 'Sin contenido en los últimos 7 días.', 'command-room' ); ?></p>
			<?php else : ?>
				<div class="cmdroom-htmlmap-list">
					<?php foreach ( $shown as $it ) : ?>
						<div class="cmdroom-htmlmap-list-row">
							<a class="cmdroom-htmlmap-list-title" href="<?php echo esc_url( get_permalink( $it['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ); ?></a>
							<span class="cmdroom-htmlmap-list-date"><?php echo esc_html( $it['date'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $total > 8 ) : ?>
					<p class="cmdroom-htmlmap-note"><?php printf( esc_html__( 'Vista previa recortada: en la página salen los %d.', 'command-room' ), $total ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_block_d30( $types ) {
		$days = Cmdroom_Htmlmap::recent_days_with_content( $types, 30 );
		?>
		<div class="cmdroom-htmlmap-block">
			<div class="cmdroom-htmlmap-block-head">
				<span class="cmdroom-htmlmap-block-title"><?php esc_html_e( 'Últimos 30 días', 'command-room' ); ?></span>
				<span class="cmdroom-htmlmap-block-count"><?php printf( esc_html( _n( '%d día', '%d días', count( $days ), 'command-room' ) ), count( $days ) ); ?></span>
			</div>
			<?php if ( empty( $days ) ) : ?>
				<p class="cmdroom-htmlmap-empty-note"><?php esc_html_e( 'Sin contenido en los últimos 30 días.', 'command-room' ); ?></p>
			<?php else : ?>
				<div class="cmdroom-htmlmap-carousel">
					<button type="button" class="cmdroom-htmlmap-car-arrow" data-cr-htmlmap-car="prev">←</button>
					<div class="cmdroom-htmlmap-car-track">
						<?php foreach ( $days as $day ) : ?>
							<button
								type="button"
								class="cmdroom-htmlmap-car-cell"
								data-cr-htmlmap-nav
								data-view="day"
								data-y="<?php echo esc_attr( $day['y'] ); ?>"
								data-m="<?php echo esc_attr( $day['m'] ); ?>"
								data-d="<?php echo esc_attr( $day['d'] ); ?>"
							>
								<span class="cmdroom-htmlmap-car-cell-date"><?php echo esc_html( Cmdroom_Htmlmap::short_date( $day['y'], $day['m'], $day['d'] ) ); ?></span>
								<span class="cmdroom-htmlmap-car-cell-num"><?php echo esc_html( $day['c'] ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
					<button type="button" class="cmdroom-htmlmap-car-arrow" data-cr-htmlmap-car="next">→</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_block_m6( $types ) {
		$months = Cmdroom_Htmlmap::recent_months_with_counts( $types, 6 );
		?>
		<div class="cmdroom-htmlmap-block">
			<div class="cmdroom-htmlmap-block-head">
				<span class="cmdroom-htmlmap-block-title"><?php esc_html_e( 'Últimos 6 meses', 'command-room' ); ?></span>
			</div>
			<div class="cmdroom-htmlmap-list">
				<?php foreach ( $months as $mo ) : ?>
					<div class="cmdroom-htmlmap-list-row">
						<button type="button" class="cmdroom-htmlmap-list-title cmdroom-htmlmap-list-title--btn" data-cr-htmlmap-nav data-view="month" data-y="<?php echo esc_attr( $mo['y'] ); ?>" data-m="<?php echo esc_attr( $mo['m'] ); ?>">
							<?php echo esc_html( Cmdroom_Htmlmap::month_year( $mo['y'], $mo['m'] ) ); ?>
						</button>
						<span class="cmdroom-htmlmap-list-date"><?php echo esc_html( self::fmt_count( $mo['c'] ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function render_block_years( $types ) {
		$years = Cmdroom_Htmlmap::years_with_counts( $types );
		?>
		<div class="cmdroom-htmlmap-block">
			<div class="cmdroom-htmlmap-block-head">
				<span class="cmdroom-htmlmap-block-title"><?php esc_html_e( 'Por año', 'command-room' ); ?></span>
			</div>
			<?php if ( empty( $years ) ) : ?>
				<p class="cmdroom-htmlmap-empty-note"><?php esc_html_e( 'Todavía no hay años con contenido.', 'command-room' ); ?></p>
			<?php else : ?>
				<div class="cmdroom-htmlmap-yeargrid">
					<?php foreach ( $years as $y => $c ) : ?>
						<button type="button" class="cmdroom-htmlmap-yearcell" data-cr-htmlmap-nav data-view="year" data-y="<?php echo esc_attr( $y ); ?>">
							<span class="cmdroom-htmlmap-yearcell-label"><?php echo esc_html( $y ); ?></span>
							<span class="cmdroom-htmlmap-yearcell-meta"><?php echo esc_html( self::fmt_count( $c ) ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_block_top( $opts, $types ) {
		$res = Cmdroom_Htmlmap::top_content( $types, $opts['top_by'], $opts['top_n'] );
		?>
		<div class="cmdroom-htmlmap-block">
			<div class="cmdroom-htmlmap-block-head">
				<span class="cmdroom-htmlmap-block-title"><?php esc_html_e( 'Top contenidos', 'command-room' ); ?></span>
			</div>
			<?php if ( 'views' === $opts['top_by'] ) : ?>
				<p class="cmdroom-htmlmap-note"><?php esc_html_e( '"Más visitados" no tiene infraestructura de analítica propia todavía -- esta vista previa usa "más recientes" como sustituto.', 'command-room' ); ?></p>
			<?php endif; ?>
			<?php if ( empty( $res['items'] ) ) : ?>
				<p class="cmdroom-htmlmap-empty-note"><?php esc_html_e( 'Sin contenido suficiente todavía.', 'command-room' ); ?></p>
			<?php else : ?>
				<ol class="cmdroom-htmlmap-toplist">
					<?php foreach ( $res['items'] as $i => $it ) : ?>
						<li class="cmdroom-htmlmap-toplist-row">
							<span class="cmdroom-htmlmap-toplist-num"><?php echo esc_html( str_pad( $i + 1, 2, '0', STR_PAD_LEFT ) ); ?></span>
							<a class="cmdroom-htmlmap-list-title" href="<?php echo esc_url( get_permalink( $it['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ); ?></a>
							<span class="cmdroom-htmlmap-list-date"><?php echo esc_html( $it['category'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_block_tags_carousel( $opts ) {
		$tags = Cmdroom_Htmlmap::top_tags( $opts['tag_n'] );
		$total = count( Cmdroom_Htmlmap::all_tags_with_counts() );
		?>
		<div class="cmdroom-htmlmap-block">
			<div class="cmdroom-htmlmap-block-head">
				<span class="cmdroom-htmlmap-block-title"><?php esc_html_e( 'Top tags', 'command-room' ); ?></span>
			</div>
			<?php if ( empty( $tags ) ) : ?>
				<p class="cmdroom-htmlmap-empty-note"><?php esc_html_e( 'Todavía no hay tags con contenido.', 'command-room' ); ?></p>
			<?php else : ?>
				<div class="cmdroom-htmlmap-carousel">
					<button type="button" class="cmdroom-htmlmap-car-arrow" data-cr-htmlmap-car="prev">←</button>
					<div class="cmdroom-htmlmap-car-track">
						<?php foreach ( $tags as $tag ) : ?>
							<a class="cmdroom-htmlmap-car-cell" href="<?php echo esc_url( get_term_link( (int) $tag['id'], 'post_tag' ) ); ?>" target="_blank" rel="noopener">
								<span class="cmdroom-htmlmap-car-cell-date"><?php echo esc_html( $tag['name'] ); ?></span>
								<span class="cmdroom-htmlmap-car-cell-num"><?php echo esc_html( $tag['count'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
					<button type="button" class="cmdroom-htmlmap-car-arrow" data-cr-htmlmap-car="next">→</button>
				</div>
				<?php if ( $opts['tags_link'] ) : ?>
					<button type="button" class="cmdroom-htmlmap-tagslink" data-cr-htmlmap-nav data-view="tags">
						<?php printf( esc_html__( 'Ver todos los tags (%d) →', 'command-room' ), $total ); ?>
					</button>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_home_blocks( $opts, $types ) {
		$order = array( 'd7', 'd30', 'm6', 'years', 'top', 'tags' );
		$active = array_filter( $order, function ( $k ) use ( $opts ) { return ! empty( $opts['blocks'][ $k ] ); } );
		if ( empty( $active ) ) {
			echo '<p class="cmdroom-htmlmap-empty-note">' . esc_html__( 'No hay bloques activos en la portada.', 'command-room' ) . '</p>';
			return;
		}
		echo '<div class="cmdroom-htmlmap-blocks">';
		foreach ( $active as $key ) {
			switch ( $key ) {
				case 'd7': self::render_block_d7( $types ); break;
				case 'd30': self::render_block_d30( $types ); break;
				case 'm6': self::render_block_m6( $types ); break;
				case 'years': self::render_block_years( $types ); break;
				case 'top': self::render_block_top( $opts, $types ); break;
				case 'tags': self::render_block_tags_carousel( $opts ); break;
			}
		}
		echo '</div>';
	}

	/* — B4. Año / Mes — celdas — */

	private static function render_year_grid( $opts, $types, $y ) {
		$months = Cmdroom_Htmlmap::months_with_counts( $types, $y );
		?>
		<div class="cmdroom-htmlmap-cellgrid cmdroom-htmlmap-cellgrid--year">
			<?php foreach ( $months as $m => $c ) :
				if ( 0 === $c && 'hide' === $opts['empty'] ) { continue; }
				$dim = ( 0 === $c );
				?>
				<button
					type="button"
					class="cmdroom-htmlmap-cell<?php echo $dim ? ' is-empty' : ''; ?>"
					<?php echo $dim ? '' : 'data-cr-htmlmap-nav data-view="month" data-y="' . esc_attr( $y ) . '" data-m="' . esc_attr( $m ) . '"'; ?>
				>
					<span class="cmdroom-htmlmap-cell-label"><?php echo esc_html( Cmdroom_Htmlmap::MESES[ $m ] ); ?></span>
					<span class="cmdroom-htmlmap-cell-meta"><?php echo esc_html( $c ? self::fmt_count( $c ) : '—' ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_month_grid( $opts, $types, $y, $m ) {
		$days = Cmdroom_Htmlmap::days_with_counts( $types, $y, $m );
		?>
		<div class="cmdroom-htmlmap-cellgrid cmdroom-htmlmap-cellgrid--month">
			<?php foreach ( $days as $d => $c ) :
				if ( 0 === $c && 'hide' === $opts['empty'] ) { continue; }
				$dim = ( 0 === $c );
				?>
				<button
					type="button"
					class="cmdroom-htmlmap-cell<?php echo $dim ? ' is-empty' : ''; ?>"
					<?php echo $dim ? '' : 'data-cr-htmlmap-nav data-view="day" data-y="' . esc_attr( $y ) . '" data-m="' . esc_attr( $m ) . '" data-d="' . esc_attr( $d ) . '"'; ?>
				>
					<span class="cmdroom-htmlmap-cell-label"><?php echo esc_html( $d ); ?></span>
					<span class="cmdroom-htmlmap-cell-meta"><?php echo esc_html( $c ? $c : '—' ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* — B5. Día — listado paginado — */

	private static function render_day_listing( $opts, $types, $y, $m, $d, $page ) {
		$res   = Cmdroom_Htmlmap::day_posts( $types, $y, $m, $d, $page, $opts['per_day'] );
		$pages = max( 1, $res['pages'] );
		?>
		<div class="cmdroom-htmlmap-daylist">
			<?php if ( empty( $res['items'] ) ) : ?>
				<p class="cmdroom-htmlmap-empty-note"><?php esc_html_e( 'Sin contenido publicado este día.', 'command-room' ); ?></p>
			<?php else : ?>
				<?php foreach ( $res['items'] as $i => $it ) :
					$num = ( ( $page - 1 ) * $opts['per_day'] ) + $i + 1;
					?>
					<div class="cmdroom-htmlmap-dayrow">
						<span class="cmdroom-htmlmap-dayrow-num"><?php echo esc_html( str_pad( $num, 2, '0', STR_PAD_LEFT ) ); ?></span>
						<a class="cmdroom-htmlmap-dayrow-title" href="<?php echo esc_url( get_permalink( $it['id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $it['title'] ); ?></a>
						<span class="cmdroom-htmlmap-dayrow-tag"><?php echo esc_html( $it['tag'] ); ?></span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php
			$prev = Cmdroom_Htmlmap::nearest_day_with_content( $types, $y, $m, $d, -1 );
			$next = Cmdroom_Htmlmap::nearest_day_with_content( $types, $y, $m, $d, 1 );
			?>
			<div class="cmdroom-htmlmap-dayfoot">
				<?php if ( $prev ) : ?>
					<button type="button" class="cmdroom-htmlmap-daynav" data-cr-htmlmap-nav data-view="day" data-y="<?php echo esc_attr( $prev[0] ); ?>" data-m="<?php echo esc_attr( $prev[1] ); ?>" data-d="<?php echo esc_attr( $prev[2] ); ?>">
						← <?php echo esc_html( Cmdroom_Htmlmap::short_date( $prev[0], $prev[1], $prev[2] ) ); ?>
					</button>
				<?php else : ?>
					<span class="cmdroom-htmlmap-daynav is-disabled">←</span>
				<?php endif; ?>

				<?php if ( $pages > 1 ) : ?>
					<div class="cmdroom-htmlmap-pager">
						<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
							<button type="button" class="cmdroom-htmlmap-pager-btn<?php echo $p === $page ? ' is-active' : ''; ?>" data-cr-htmlmap-nav data-view="day" data-y="<?php echo esc_attr( $y ); ?>" data-m="<?php echo esc_attr( $m ); ?>" data-d="<?php echo esc_attr( $d ); ?>" data-page="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $p ); ?></button>
						<?php endfor; ?>
					</div>
				<?php endif; ?>

				<?php if ( $next ) : ?>
					<button type="button" class="cmdroom-htmlmap-daynav" data-cr-htmlmap-nav data-view="day" data-y="<?php echo esc_attr( $next[0] ); ?>" data-m="<?php echo esc_attr( $next[1] ); ?>" data-d="<?php echo esc_attr( $next[2] ); ?>">
						<?php echo esc_html( Cmdroom_Htmlmap::short_date( $next[0], $next[1], $next[2] ) ); ?> →
					</button>
				<?php else : ?>
					<span class="cmdroom-htmlmap-daynav is-disabled">→</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* — B6. Tags A-Z — */

	private static function render_tags_az() {
		$groups = Cmdroom_Htmlmap::tags_by_letter();
		?>
		<div class="cmdroom-htmlmap-az">
			<?php if ( empty( $groups ) ) : ?>
				<p class="cmdroom-htmlmap-empty-note"><?php esc_html_e( 'Todavía no hay tags con contenido.', 'command-room' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $groups as $letter => $tags ) : ?>
				<div class="cmdroom-htmlmap-az-row">
					<span class="cmdroom-htmlmap-az-letter"><?php echo esc_html( $letter ); ?></span>
					<div class="cmdroom-htmlmap-az-tags">
						<?php foreach ( $tags as $tag ) : ?>
							<a class="cmdroom-htmlmap-az-tag" href="<?php echo esc_url( get_term_link( (int) $tag['id'], 'post_tag' ) ); ?>" target="_blank" rel="noopener">
								<?php echo esc_html( $tag['name'] ); ?> <span class="cmdroom-htmlmap-az-tag-num"><?php echo esc_html( $tag['count'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* — B8. Footer simulado — */

	private static function render_frame_footer( $opts ) {
		if ( ! $opts['enabled'] || ! $opts['footer_link'] ) {
			return;
		}
		$site_name = get_bloginfo( 'name' );
		?>
		<div class="cmdroom-htmlmap-framefooter">
			<span>&copy; <?php echo esc_html( date_i18n( 'Y' ) . ' ' . $site_name ); ?></span>
			<span>Aviso legal</span>
			<span>Privacidad</span>
			<button type="button" class="cmdroom-htmlmap-framefooter-map" data-cr-htmlmap-nav data-view="home"><?php esc_html_e( 'Mapa del sitio', 'command-room' ); ?></button>
		</div>
		<?php
	}

	/* — Cuerpo del marco según nivel — */

	private static function render_frame_body( $opts, $state, $types ) {
		list( $title, $subtitle ) = self::head_title_subtitle( $opts, $state, $types );
		?>
		<div class="cmdroom-htmlmap-body">
			<?php self::render_breadcrumbs( $opts, $state ); ?>
			<h1 class="cmdroom-htmlmap-h1"><?php echo esc_html( $title ); ?></h1>
			<p class="cmdroom-htmlmap-sub"><?php echo esc_html( $subtitle ); ?></p>

			<?php
			switch ( $state['view'] ) {
				case 'home':
					self::render_home_blocks( $opts, $types );
					break;
				case 'year':
					self::render_year_grid( $opts, $types, $state['y'] );
					echo '<div class="cmdroom-htmlmap-extra">';
					self::render_block_top( $opts, $types );
					self::render_block_tags_carousel( $opts );
					echo '</div>';
					break;
				case 'month':
					self::render_month_grid( $opts, $types, $state['y'], $state['m'] );
					echo '<div class="cmdroom-htmlmap-extra">';
					self::render_block_top( $opts, $types );
					self::render_block_tags_carousel( $opts );
					echo '</div>';
					break;
				case 'day':
					self::render_day_listing( $opts, $types, $state['y'], $state['m'], $state['d'], $state['page'] );
					echo '<div class="cmdroom-htmlmap-extra">';
					self::render_block_top( $opts, $types );
					self::render_block_tags_carousel( $opts );
					echo '</div>';
					break;
				case 'tags':
					self::render_tags_az();
					echo '<div class="cmdroom-htmlmap-extra">';
					self::render_block_top( $opts, $types );
					echo '</div>';
					break;
			}
			?>
		</div>
		<?php
		self::render_frame_footer( $opts );
	}

	private static function render_browser_frame( $opts, $state, $types ) {
		$url = self::build_url_bar( $opts, $state );
		?>
		<div class="cmdroom-htmlmap-frame">
			<div class="cmdroom-htmlmap-framebar">
				<span class="cmdroom-htmlmap-framedot"></span>
				<span class="cmdroom-htmlmap-framedot"></span>
				<span class="cmdroom-htmlmap-framedot"></span>
				<span class="cmdroom-htmlmap-frameurl"><?php echo esc_html( $url ); ?></span>
			</div>
			<?php if ( ! $opts['enabled'] ) : ?>
				<div class="cmdroom-htmlmap-frame-off">
					<?php esc_html_e( 'Desactivado: estas URLs devuelven 404 y no se imprime el enlace del footer.', 'command-room' ); ?>
				</div>
			<?php endif; ?>
			<?php self::render_frame_body( $opts, $state, $types ); ?>
		</div>
		<?php
	}

	/* — B. Raíz de la vista previa (lo que se reemplaza por AJAX) — */

	private static function render_preview_root( $opts, $state ) {
		$types = Cmdroom_Htmlmap::enabled_post_types( $opts );
		?>
		<div id="cmdroom-htmlmap-preview-root" data-view="<?php echo esc_attr( $state['view'] ); ?>" data-y="<?php echo esc_attr( $state['y'] ); ?>" data-m="<?php echo esc_attr( $state['m'] ); ?>" data-d="<?php echo esc_attr( $state['d'] ); ?>" data-page="<?php echo esc_attr( $state['page'] ); ?>">
			<?php self::render_level_bar( $opts, $state ); ?>
			<?php self::render_browser_frame( $opts, $state, $types ); ?>
		</div>
		<?php
	}

	private static function render_preview_section( $opts ) {
		list( $ty, $tm, $td ) = Cmdroom_Htmlmap::today();
		$state = array( 'view' => 'home', 'y' => $ty, 'm' => $tm, 'd' => $td, 'page' => 1 );
		?>
		<div>
			<span class="cr-label"><?php esc_html_e( 'Estructura y vista previa', 'command-room' ); ?></span>
			<p class="cmdroom-htmlmap-hint"><?php esc_html_e( 'Haz clic en un nivel o en cualquier enlace de la vista previa.', 'command-room' ); ?></p>
		</div>
		<div
			class="cmdroom-htmlmap-preview-wrap"
			data-cr-htmlmap-preview
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'cmdroom_htmlmap_preview' ) ); ?>"
		>
			<?php self::render_preview_root( $opts, $state ); ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* C. Bloques de la portada (configuración)                          */
	/* ---------------------------------------------------------------- */

	private static function block_catalog() {
		return array(
			'd7'    => array( __( 'Últimos 7 días', 'command-room' ), __( 'Todos los contenidos publicados en la última semana.', 'command-room' ) ),
			'd30'   => array( __( 'Últimos 30 días', 'command-room' ), __( 'Un enlace por día a su página de archivo, con el número de contenidos.', 'command-room' ) ),
			'm6'    => array( __( 'Últimos 6 meses', 'command-room' ), __( 'Un enlace por mes a su página de archivo.', 'command-room' ) ),
			'years' => array( __( 'Años de creación', 'command-room' ), __( 'Todos los años con contenido, del más reciente al más antiguo.', 'command-room' ) ),
			'top'   => array( __( 'Top contenidos', 'command-room' ), __( 'Los contenidos que mejor funcionan según el criterio elegido abajo.', 'command-room' ) ),
			'tags'  => array( __( 'Top tags', 'command-room' ), __( 'Los tags con más contenidos. El listado completo vive en /{slug}/tags/.', 'command-room' ) ),
		);
	}

	private static function render_blocks_config( $opts ) {
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Bloques de la portada', 'command-room' ); ?></span>
		</div>
		<div class="cr-card cmdroom-htmlmap-blockrows">
			<?php foreach ( self::block_catalog() as $key => $meta ) :
				list( $label, $desc ) = $meta;
				$on   = ! empty( $opts['blocks'][ $key ] );
				$desc = str_replace( '{slug}', $opts['slug'], $desc );
				?>
				<div class="cmdroom-htmlmap-blockrow">
					<button type="button" class="cr-toggle<?php echo $on ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="block_<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $on ); ?> /></button>
					<div class="cmdroom-htmlmap-blockrow-body">
						<p class="cmdroom-htmlmap-blockrow-title"><?php echo esc_html( $label ); ?></p>
						<p class="cmdroom-htmlmap-blockrow-desc"><?php echo esc_html( $desc ); ?></p>
					</div>
					<?php if ( in_array( $key, array( 'top', 'tags' ), true ) ) : ?>
						<div class="cmdroom-htmlmap-blockrow-n">
							<label class="cr-label" style="margin:0;"><?php esc_html_e( 'Mostrar', 'command-room' ); ?></label>
							<input type="number" class="cr-input" style="width:64px;" min="1" max="20" name="<?php echo esc_attr( 'top' === $key ? 'top_n' : 'tag_n' ); ?>" value="<?php echo esc_attr( 'top' === $key ? $opts['top_n'] : $opts['tag_n'] ); ?>" />
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>

		<div style="margin-top:16px;">
			<span class="cr-label"><?php esc_html_e( 'Criterio de Top contenidos', 'command-room' ); ?></span>
			<div class="cr-seg">
				<button type="button" class="cr-seg-opt<?php echo 'views' === $opts['top_by'] ? ' is-active' : ''; ?>" data-value="views"><?php esc_html_e( 'Más visitados · 30 días', 'command-room' ); ?></button>
				<button type="button" class="cr-seg-opt<?php echo 'comments' === $opts['top_by'] ? ' is-active' : ''; ?>" data-value="comments"><?php esc_html_e( 'Más comentados', 'command-room' ); ?></button>
				<button type="button" class="cr-seg-opt<?php echo 'links' === $opts['top_by'] ? ' is-active' : ''; ?>" data-value="links"><?php esc_html_e( 'Más enlazados internamente', 'command-room' ); ?></button>
			</div>
			<input type="hidden" name="top_by" class="cr-seg-value" value="<?php echo esc_attr( $opts['top_by'] ); ?>" />
			<?php if ( 'views' === $opts['top_by'] ) : ?>
				<p class="cmdroom-aff-note"><?php esc_html_e( 'Sin infraestructura de analítica propia (ni tabla de estadísticas ni Jetpack): mientras esté seleccionado, la vista previa usa "más recientes" como sustituto.', 'command-room' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* D. Páginas de archivo (configuración)                             */
	/* ---------------------------------------------------------------- */

	private static function render_archive_config( $opts ) {
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Páginas de archivo', 'command-room' ); ?></span>
		</div>
		<div class="cmdroom-htmlmap-archivegrid">
			<div>
				<span class="cr-label"><?php esc_html_e( 'Contenidos por página (día)', 'command-room' ); ?></span>
				<div class="cr-seg">
					<button type="button" class="cr-seg-opt<?php echo 5 === (int) $opts['per_day'] ? ' is-active' : ''; ?>" data-value="5"><?php esc_html_e( '5 por página', 'command-room' ); ?></button>
					<button type="button" class="cr-seg-opt<?php echo 10 === (int) $opts['per_day'] ? ' is-active' : ''; ?>" data-value="10"><?php esc_html_e( '10 por página', 'command-room' ); ?></button>
				</div>
				<input type="hidden" name="per_day" class="cr-seg-value" value="<?php echo esc_attr( $opts['per_day'] ); ?>" />
			</div>
			<div>
				<span class="cr-label"><?php esc_html_e( 'Días y meses sin contenido', 'command-room' ); ?></span>
				<div class="cr-seg">
					<button type="button" class="cr-seg-opt<?php echo 'hide' === $opts['empty'] ? ' is-active' : ''; ?>" data-value="hide"><?php esc_html_e( 'Ocultar', 'command-room' ); ?></button>
					<button type="button" class="cr-seg-opt<?php echo 'show' === $opts['empty'] ? ' is-active' : ''; ?>" data-value="show"><?php esc_html_e( 'Mostrar sin enlace', 'command-room' ); ?></button>
				</div>
				<input type="hidden" name="empty" class="cr-seg-value" value="<?php echo esc_attr( $opts['empty'] ); ?>" />
			</div>
			<div>
				<span class="cr-label"><?php esc_html_e( 'Indexación de las páginas por fecha', 'command-room' ); ?></span>
				<div class="cr-seg">
					<button type="button" class="cr-seg-opt cmdroom-aff-mono<?php echo 'noindex' === $opts['date_robots'] ? ' is-active' : ''; ?>" data-value="noindex">noindex, follow</button>
					<button type="button" class="cr-seg-opt cmdroom-aff-mono<?php echo 'index' === $opts['date_robots'] ? ' is-active' : ''; ?>" data-value="index">index, follow</button>
				</div>
				<input type="hidden" name="date_robots" class="cr-seg-value" value="<?php echo esc_attr( $opts['date_robots'] ); ?>" />
			</div>
			<div>
				<span class="cr-label"><?php esc_html_e( 'Tipos de contenido incluidos', 'command-room' ); ?></span>
				<div class="cmdroom-aff-cat-picker" data-cr-htmlmap-pt-picker>
					<?php
					$labels = array( 'post' => __( 'Entradas', 'command-room' ), 'page' => __( 'Páginas', 'command-room' ), 'product' => __( 'Productos', 'command-room' ) );
					foreach ( $opts['post_types'] as $slug => $on ) :
						?>
						<button type="button" class="cr-pill cmdroom-aff-cat-btn<?php echo $on ? ' is-active' : ''; ?>" data-cr-toggle-class="is-active" data-checkbox="pt_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug ); ?></button>
						<input type="checkbox" name="pt_<?php echo esc_attr( $slug ); ?>" value="1" <?php checked( $on ); ?> hidden data-checkbox-input="pt_<?php echo esc_attr( $slug ); ?>" />
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<p class="cmdroom-aff-note">
			<?php if ( 'noindex' === $opts['date_robots'] ) : ?>
				<?php esc_html_e( 'Recomendado: las páginas de año, mes y día no compiten en buscadores, pero Google sigue sus enlaces y descubre todo el contenido. La portada y Tags siempre son index.', 'command-room' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Las páginas por fecha se indexan. Con poco contenido por día pueden considerarse páginas de poco valor; úsalo solo si publicas mucho a diario.', 'command-room' ); ?>
			<?php endif; ?>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* E. Enlazado (configuración)                                       */
	/* ---------------------------------------------------------------- */

	private static function render_linking_config( $opts ) {
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Enlazado', 'command-room' ); ?></span>
		</div>
		<div class="cmdroom-ticker-row" style="margin-bottom:12px;">
			<button type="button" class="cr-toggle<?php echo $opts['footer_link'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="footer_link" value="1" <?php checked( $opts['footer_link'] ); ?> /></button>
			<p class="cmdroom-ticker-row-title"><?php esc_html_e( 'Añadir «Mapa del sitio» al footer de todas las páginas', 'command-room' ); ?></p>
		</div>
		<div class="cmdroom-ticker-row" style="margin-bottom:14px;">
			<button type="button" class="cr-toggle<?php echo $opts['tags_link'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="tags_link" value="1" <?php checked( $opts['tags_link'] ); ?> /></button>
			<p class="cmdroom-ticker-row-title"><?php esc_html_e( 'Enlace «Ver todos los tags» debajo de Top tags', 'command-room' ); ?></p>
		</div>
		<div class="cmdroom-aff-shortcode-box">
			<label class="cr-label"><?php esc_html_e( 'Shortcode para insertarlo en otra página', 'command-room' ); ?></label>
			<code class="cmdroom-aff-shortcode-code">[cr_sitemap_html]</code>
			<p class="cmdroom-aff-note" style="margin-top:6px;"><?php esc_html_e( 'Todavía no está activo -- se registra en la tarea de front-end.', 'command-room' ); ?></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* Render principal                                                  */
	/* ---------------------------------------------------------------- */

	public static function render_tab() {
		$opts = Cmdroom_Htmlmap::get_options();
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cmdroom-aff-screen cmdroom-htmlmap-screen">
			<?php self::render_top_row( $opts ); ?>
			<?php self::render_preview_section( $opts ); ?>

			<form id="cmdroom-htmlmap-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cr-reco-form>
				<?php wp_nonce_field( 'cmdroom_save_htmlmap' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_htmlmap" />

				<?php self::render_blocks_config( $opts ); ?>
				<?php self::render_archive_config( $opts ); ?>
				<?php self::render_linking_config( $opts ); ?>

				<?php submit_button( __( 'Guardar sitemap HTML', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
			</form>
		</div>
		<?php
	}
}
