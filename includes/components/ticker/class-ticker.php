<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Salida en el sitio del Ticker. render_bar() es el único sitio que pinta
 * la barra -- lo usa tanto el front-end real como la vista previa del
 * admin (Cmdroom_Ticker_Settings::render_preview()), así que nunca pueden
 * desincronizarse visualmente.
 *
 * "Debajo del menú" se engancha a wp_body_open (prioridad alta = temprano)
 * como fallback, y además expone do_action('command_room_ticker') para que
 * el tema lo coloque justo después de su cabecera si wp_body_open no basta
 * -- con guardia para no imprimir dos veces si el tema llama a las dos.
 * "Abajo del sitio" usa position:fixed en wp_footer + padding-bottom fijo
 * en el body (ver ticker-frontend.css), no JS de medición.
 */
class Cmdroom_Ticker {

	/** Segundos por mensaje según velocidad -- duración = max(8, n_mensajes × esto). */
	const SEC_PER_MSG = array(
		'slow'   => 9,
		'normal' => 6,
		'fast'   => 3.5,
	);

	const BOTTOM_BAR_HEIGHT = 40;

	private static $printed = false;

	public static function init() {
		if ( is_admin() ) {
			return;
		}
		add_action( 'wp', array( __CLASS__, 'maybe_hook_output' ) );

		// Invalidación del caché de mensajes automáticos (ver
		// get_cached_auto_messages()) -- el handoff pide explícitamente
		// save_post, woocommerce_update_product y guardar cupones; los tres
		// llaman al mismo borrado, sin más lógica.
		add_action( 'save_post', array( __CLASS__, 'invalidate_auto_cache' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'invalidate_auto_cache' ) );
		add_action( 'save_post_shop_coupon', array( __CLASS__, 'invalidate_auto_cache' ) );
	}

	public static function invalidate_auto_cache() {
		delete_transient( 'cmdroom_ticker_auto_cache' );
	}

	private static function get_cached_auto_messages( $auto_opts ) {
		$cached = get_transient( 'cmdroom_ticker_auto_cache' );
		if ( false !== $cached ) {
			return $cached;
		}
		$messages = Cmdroom_Ticker_Resolver::resolve_auto( $auto_opts );
		set_transient( 'cmdroom_ticker_auto_cache', $messages, 6 * HOUR_IN_SECONDS );
		return $messages;
	}

	private static function resolve_current_messages( $opts ) {
		if ( 'auto' === $opts['mode'] ) {
			return self::get_cached_auto_messages( $opts['auto'] );
		}
		if ( 'mixed' === $opts['mode'] ) {
			$fixed = Cmdroom_Ticker_Resolver::resolve_manual( $opts['manual'] );
			$auto  = self::get_cached_auto_messages( $opts['auto'] );
			return Cmdroom_Ticker_Resolver::resolve_mixed( $opts['mixed'], $fixed, $auto );
		}
		return Cmdroom_Ticker_Resolver::resolve_manual( $opts['manual'] );
	}

	public static function maybe_hook_output() {
		if ( ! Cmdroom_Ticker_Settings::is_enabled() ) {
			return;
		}
		$opts     = Cmdroom_Ticker_Settings::get_options();
		$messages = self::resolve_current_messages( $opts );
		if ( empty( $messages ) ) {
			return;
		}

		if ( 'bottom' === $opts['style']['position'] ) {
			add_action( 'wp_footer', array( __CLASS__, 'render_bottom' ), 100 );
			// Clase en <body> en vez de un padding-bottom inline -- así el
			// CSS del padding vive junto al de la barra (ticker-frontend.css)
			// y solo se aplica en los sitios/páginas donde de verdad hay barra.
			add_filter( 'body_class', array( __CLASS__, 'add_bottom_body_class' ) );
		} else {
			add_action( 'wp_body_open', array( __CLASS__, 'render_below_menu' ), 5 );
			add_action( 'command_room_ticker', array( __CLASS__, 'render_below_menu' ) );
		}
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function add_bottom_body_class( $classes ) {
		$classes[] = 'cmdroom-ticker-bottom-active';
		return $classes;
	}

	public static function enqueue_assets() {
		wp_enqueue_style( 'cmdroom-ticker-frontend', CMDROOM_URL . 'assets/css/ticker-frontend.css', array(), CMDROOM_VERSION );
	}

	public static function render_below_menu() {
		if ( self::$printed ) {
			return; // por si el tema llama a wp_body_open Y command_room_ticker.
		}
		self::$printed = true;
		$opts          = Cmdroom_Ticker_Settings::get_options();
		self::render_bar( self::resolve_current_messages( $opts ), $opts['style'] );
	}

	public static function render_bottom() {
		$opts = Cmdroom_Ticker_Settings::get_options();
		self::render_bar( self::resolve_current_messages( $opts ), $opts['style'], array( 'fixed_bottom' => true ) );
	}

	/**
	 * Resuelve una URL relativa contra home_url() -- guardada tal cual
	 * (esc_url_raw en el guardado no fuerza absoluta) para que los ajustes
	 * sean portables entre dev/producción.
	 */
	private static function resolve_url( $url ) {
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		return home_url( '/' . ltrim( $url, '/' ) );
	}

	/**
	 * Único sitio que pinta el marcado de la barra -- front-end real y
	 * vista previa del admin. $args: fixed_bottom (bool), empty_text
	 * (string, solo lo pasa la vista previa).
	 */
	public static function render_bar( $messages, $style, $args = array() ) {
		$fixed_bottom = ! empty( $args['fixed_bottom'] );
		$empty_text   = isset( $args['empty_text'] ) ? $args['empty_text'] : '';

		if ( empty( $messages ) ) {
			if ( '' === $empty_text ) {
				return;
			}
			?>
			<div class="cmdroom-ticker<?php echo $fixed_bottom ? ' cmdroom-ticker--fixed-bottom' : ''; ?>" style="--cmdroom-ticker-bg:<?php echo esc_attr( $style['bg'] ); ?>;--cmdroom-ticker-color:<?php echo esc_attr( $style['fg'] ); ?>;">
				<span class="cmdroom-ticker-empty"><?php echo esc_html( $empty_text ); ?></span>
			</div>
			<?php
			return;
		}

		$sec_per_msg = isset( self::SEC_PER_MSG[ $style['speed'] ] ) ? self::SEC_PER_MSG[ $style['speed'] ] : self::SEC_PER_MSG['normal'];
		$duration    = max( 8, count( $messages ) * $sec_per_msg );
		$pauseable   = ! empty( $style['pause_on_hover'] );
		$css_style   = sprintf(
			'--cmdroom-ticker-bg:%s;--cmdroom-ticker-color:%s;--cmdroom-ticker-duration:%ss;--cmdroom-ticker-rm-count:%d;',
			esc_attr( $style['bg'] ),
			esc_attr( $style['fg'] ),
			esc_attr( str_replace( ',', '.', (string) $duration ) ),
			count( $messages )
		);
		?>
		<div
			class="cmdroom-ticker<?php echo $pauseable ? ' cmdroom-ticker--pauseable' : ''; ?><?php echo $fixed_bottom ? ' cmdroom-ticker--fixed-bottom' : ''; ?>"
			style="<?php echo esc_attr( $css_style ); ?>"
			role="marquee"
			aria-live="off"
		>
			<div class="cmdroom-ticker-track">
				<?php for ( $pass = 0; $pass < 2; $pass++ ) : ?>
					<div class="cmdroom-ticker-content" <?php echo 1 === $pass ? 'aria-hidden="true"' : ''; ?>>
						<?php foreach ( $messages as $i => $msg ) : ?>
							<?php if ( $i > 0 ) : ?>
								<span class="cmdroom-ticker-sep" aria-hidden="true"><?php echo esc_html( $style['separator'] ); ?></span>
							<?php endif; ?>
							<span class="cmdroom-ticker-item" style="animation-delay:<?php echo esc_attr( $i * 5 ); ?>s;">
								<?php
								$url = self::resolve_url( $msg['url'] );
								if ( $url ) :
									?>
									<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $msg['text'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $msg['text'] ); ?>
								<?php endif; ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endfor; ?>
			</div>
		</div>
		<?php
	}
}
