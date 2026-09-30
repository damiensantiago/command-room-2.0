<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes del componente Ticker -- rediseño 2026-09-23 sobre el handoff
 * "Componentes + Ticker": tres orígenes de mensajes (Automático/Configurado/
 * Mixto) intercambiables sin perder los ajustes de los otros dos, más
 * apariencia común. La resolución de mensajes (qué sale en cada modo) vive
 * en Cmdroom_Ticker_Resolver -- esta clase solo pinta el formulario y
 * guarda lo que se envía.
 *
 * "Ver una subpestaña no cambia el modo en uso" (handoff): el segmentado
 * de Origen y las pestañas de día son enlaces normales que cambian
 * ?view=/&day= (recargan la página); el modo real solo cambia con "Usar
 * este modo" (acción GET propia, con nonce) o guardando desde la
 * subpestaña que ya es el modo en uso.
 */
class Cmdroom_Ticker_Settings {

	const KEY = 'ticker';

	const MODES = array( 'auto', 'manual', 'mixed' );

	const MODE_LABELS = array(
		'auto'   => 'Automático',
		'manual' => 'Configurado',
		'mixed'  => 'Mixto',
	);

	const MODE_DESCRIPTIONS = array(
		'auto'   => 'Command Room genera los mensajes a partir del contenido del sitio: entradas nuevas, ofertas, envío gratis y cupones. No hay que mantener nada a mano.',
		'manual' => 'Tú escribes cada mensaje. Opcionalmente, una lista distinta para cada día de la semana.',
		'mixed'  => 'Combina tus mensajes fijos con los automáticos en una sola rotación.',
	);

	const AUTO_SOURCES = array(
		'posts'    => array(
			'label'   => 'Últimas entradas del blog',
			'desc'    => 'Cada entrada nueva entra en el ticker durante los días de vigencia.',
			'example' => array( '%title%' => 'Guía de SEO local' ),
		),
		'sale'     => array(
			'label'   => 'Productos en oferta',
			'desc'    => 'Productos de WooCommerce con precio rebajado activo. Desaparecen solos al terminar la oferta.',
			'example' => array( '%product_name%' => 'Zapatilla running azul', '%discount%' => '30' ),
		),
		'shipping' => array(
			'label'   => 'Umbral de envío gratis',
			'desc'    => 'Lee el importe mínimo de la zona de envío por defecto de WooCommerce.',
			'example' => array( '%free_shipping_min%' => '50,00 €' ),
		),
		'coupons'  => array(
			'label'   => 'Cupones públicos activos',
			'desc'    => 'Cupones publicados y dentro de fecha.',
			'example' => array( '%coupon_code%' => 'BIENVENIDO10', '%coupon_amount%' => '10%' ),
		),
	);

	const DEFAULTS = array(
		'enabled' => false,
		'mode'    => 'manual',
		'auto'    => array(
			'sources' => array(
				'posts'    => array( 'on' => true, 'tpl' => 'Nuevo en el blog: %title%' ),
				'sale'     => array( 'on' => true, 'tpl' => '%product_name% · -%discount%%' ),
				'shipping' => array( 'on' => true, 'tpl' => 'Envío gratis desde %free_shipping_min%' ),
				'coupons'  => array( 'on' => false, 'tpl' => 'Código %coupon_code%: %coupon_amount% de descuento' ),
			),
			'days'           => 7,
			'max_per_source' => 3,
		),
		'manual'  => array(
			'by_day' => false,
			'all'    => array(
				array( 'text' => 'Envío gratis desde 50 €', 'url' => '/envios/' ),
				array( 'text' => 'Oferta de otoño: hasta -30% en calzado', 'url' => '/ofertas/' ),
				array( 'text' => 'Devoluciones gratis durante 30 días', 'url' => '' ),
			),
			'lun'    => array(),
			'mar'    => array(),
			'mie'    => array(),
			'jue'    => array(),
			'vie'    => array(),
			'fds'    => array(),
		),
		'mixed'   => array( 'order' => 'interleave', 'ratio' => 2, 'max' => 8 ),
		'style'   => array(
			'speed'          => 'normal',
			'position'       => 'below_menu',
			'separator'      => '·',
			'bg'             => '#201e1d',
			'fg'             => '#ffffff',
			'pause_on_hover' => true,
		),
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_ticker', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cmdroom_ticker_use_mode', array( __CLASS__, 'handle_use_mode' ) );
	}

	public static function get_options() {
		$saved = Cmdroom_Components_Admin::get_option_value( self::KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$opts = array_replace_recursive( self::DEFAULTS, $saved );
		// array_replace_recursive no vacía listas -- si el administrador guarda una
		// lista de mensajes vacía a propósito, respétala en vez de
		// rellenarla con los 3 mensajes de ejemplo de fábrica.
		foreach ( array( 'all', 'lun', 'mar', 'mie', 'jue', 'vie', 'fds' ) as $day ) {
			if ( isset( $saved['manual'][ $day ] ) ) {
				$opts['manual'][ $day ] = $saved['manual'][ $day ];
			}
		}
		return $opts;
	}

	public static function is_enabled() {
		return ! empty( self::get_options()['enabled'] );
	}

	private static function get_view() {
		$requested = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( in_array( $requested, self::MODES, true ) ) {
			return $requested;
		}
		$mode = self::get_options()['mode'];
		return in_array( $mode, self::MODES, true ) ? $mode : 'manual';
	}

	private static function get_day() {
		$requested = isset( $_GET['day'] ) ? sanitize_key( wp_unslash( $_GET['day'] ) ) : '';
		return in_array( $requested, Cmdroom_Ticker_Resolver::DAY_KEYS, true ) ? $requested : Cmdroom_Ticker_Resolver::current_day_key();
	}

	private static function view_url( $view, $extra = array() ) {
		return add_query_arg( array_merge( array( 'view' => $view ), $extra ), Cmdroom_Components_Admin::tab_url( 'ticker' ) );
	}

	/* — Guardado — */

	public static function handle_use_mode() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			wp_die( esc_html__( 'Modo no válido.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_ticker_use_mode_' . $mode );

		$opts         = self::get_options();
		$opts['mode'] = $mode;
		Cmdroom_Components_Admin::update_option_key( self::KEY, $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', self::view_url( $mode ) ) );
		exit;
	}

	private static function sanitize_messages_post() {
		$texts = isset( $_POST['msg_text'] ) ? (array) wp_unslash( $_POST['msg_text'] ) : array();
		$urls  = isset( $_POST['msg_url'] ) ? (array) wp_unslash( $_POST['msg_url'] ) : array();
		$out   = array();
		foreach ( $texts as $i => $text ) {
			$text = sanitize_text_field( $text );
			if ( '' === $text ) {
				continue;
			}
			$url   = isset( $urls[ $i ] ) ? trim( $urls[ $i ] ) : '';
			$out[] = array(
				'text' => $text,
				'url'  => '' !== $url ? esc_url_raw( $url ) : '',
			);
		}
		return $out;
	}

	private static function sanitize_auto_post() {
		$sources = array();
		foreach ( self::AUTO_SOURCES as $key => $meta ) {
			$sources[ $key ] = array(
				'on'  => ! empty( $_POST[ 'src_' . $key . '_on' ] ),
				'tpl' => isset( $_POST[ 'src_' . $key . '_tpl' ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'src_' . $key . '_tpl' ] ) ) : '',
			);
		}
		return array(
			'sources'        => $sources,
			'days'           => isset( $_POST['days'] ) ? max( 1, (int) $_POST['days'] ) : self::DEFAULTS['auto']['days'],
			'max_per_source' => isset( $_POST['max_per_source'] ) ? max( 1, (int) $_POST['max_per_source'] ) : self::DEFAULTS['auto']['max_per_source'],
		);
	}

	private static function sanitize_mixed_post() {
		$order = isset( $_POST['mixed_order'] ) ? sanitize_key( wp_unslash( $_POST['mixed_order'] ) ) : 'interleave';
		return array(
			'order' => in_array( $order, array( 'interleave', 'fixed', 'auto' ), true ) ? $order : 'interleave',
			'ratio' => isset( $_POST['mixed_ratio'] ) ? max( 1, (int) $_POST['mixed_ratio'] ) : self::DEFAULTS['mixed']['ratio'],
			'max'   => isset( $_POST['mixed_max'] ) ? max( 1, (int) $_POST['mixed_max'] ) : self::DEFAULTS['mixed']['max'],
		);
	}

	private static function sanitize_style_post() {
		$speed    = isset( $_POST['speed'] ) ? sanitize_key( wp_unslash( $_POST['speed'] ) ) : 'normal';
		$position = isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : 'below_menu';
		$bg       = isset( $_POST['bg_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['bg_color'] ) ) : self::DEFAULTS['style']['bg'];
		$fg       = isset( $_POST['text_color'] ) ? sanitize_hex_color( wp_unslash( $_POST['text_color'] ) ) : self::DEFAULTS['style']['fg'];
		return array(
			'speed'          => in_array( $speed, array( 'slow', 'normal', 'fast' ), true ) ? $speed : 'normal',
			'position'       => in_array( $position, array( 'below_menu', 'bottom' ), true ) ? $position : 'below_menu',
			'separator'      => isset( $_POST['separator'] ) ? sanitize_text_field( wp_unslash( $_POST['separator'] ) ) : self::DEFAULTS['style']['separator'],
			'bg'             => $bg ? $bg : self::DEFAULTS['style']['bg'],
			'fg'             => $fg ? $fg : self::DEFAULTS['style']['fg'],
			'pause_on_hover' => ! empty( $_POST['pause_on_hover'] ),
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_ticker' );

		$view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'manual';
		if ( ! in_array( $view, self::MODES, true ) ) {
			$view = 'manual';
		}
		$day = isset( $_POST['day'] ) ? sanitize_key( wp_unslash( $_POST['day'] ) ) : '';
		if ( ! in_array( $day, Cmdroom_Ticker_Resolver::DAY_KEYS, true ) ) {
			$day = Cmdroom_Ticker_Resolver::current_day_key();
		}

		$opts            = self::get_options();
		$opts['enabled'] = ! empty( $_POST['enabled'] );

		switch ( $view ) {
			case 'auto':
				$opts['auto'] = self::sanitize_auto_post();
				break;
			case 'mixed':
				$opts['mixed'] = self::sanitize_mixed_post();
				break;
			case 'manual':
			default:
				$by_day                  = ! empty( $_POST['by_day'] );
				$opts['manual']['by_day'] = $by_day;
				$target_key              = $by_day ? $day : 'all';
				$opts['manual'][ $target_key ] = self::sanitize_messages_post();
				break;
		}

		$opts['style'] = self::sanitize_style_post();

		Cmdroom_Components_Admin::update_option_key( self::KEY, $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', self::view_url( $view, array( 'day' => $day ) ) ) );
		exit;
	}

	/* — Render — */

	const PREVIEW_EMPTY_TEXT = 'Sin mensajes para este modo';

	/**
	 * La vista previa reacciona en vivo a los cambios del formulario (sin
	 * guardar) -- ver components-editor.js::initTickerPreview(). Este método
	 * solo pinta el estado inicial (SSR, con lo ya guardado); ambas
	 * posiciones (below_menu/bottom) se renderizan siempre, una oculta con
	 * `hidden`, para que JS solo tenga que mostrar/ocultar + reescribir el
	 * contenido del slot activo en vez de reconstruir el marco entero.
	 */
	private static function render_preview( $opts, $view, $day ) {
		$show_day = 'manual' === $view && ! empty( $opts['manual']['by_day'] );
		$messages = Cmdroom_Ticker_Resolver::resolve_for_mode( $opts, $view, $day );
		$labels   = Cmdroom_Ticker_Resolver::day_labels();
		$caption  = sprintf( 'Modo %s', self::MODE_LABELS[ $view ] );
		if ( $show_day ) {
			$caption .= ' · ' . $labels[ $day ];
		}
		$caption .= sprintf( ' · %d mensaje%s', count( $messages ), 1 === count( $messages ) ? '' : 's' );
		?>
		<div
			class="cmdroom-ticker-preview"
			data-cr-ticker-preview
			data-view="<?php echo esc_attr( $view ); ?>"
			data-day="<?php echo esc_attr( $day ); ?>"
			data-mode-label="<?php echo esc_attr( self::MODE_LABELS[ $view ] ); ?>"
			data-day-label="<?php echo esc_attr( $show_day ? $labels[ $day ] : '' ); ?>"
			data-empty-text="<?php echo esc_attr( self::PREVIEW_EMPTY_TEXT ); ?>"
		>
			<div class="cmdroom-ticker-preview-head">
				<span class="cr-label"><?php esc_html_e( 'Vista previa', 'command-room' ); ?></span>
				<span class="cmdroom-ticker-preview-caption" data-cr-ticker-caption><?php echo esc_html( $caption ); ?></span>
			</div>
			<div class="cmdroom-ticker-preview-frame">
				<div class="cmdroom-ticker-preview-header">
					<span class="cmdroom-ticker-preview-sitename"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
					<span class="cmdroom-ticker-preview-nav"><?php esc_html_e( 'Tienda · Blog · Contacto', 'command-room' ); ?></span>
				</div>
				<div data-cr-ticker-bar-slot="below_menu" <?php echo 'below_menu' !== $opts['style']['position'] ? 'hidden' : ''; ?>>
					<?php Cmdroom_Ticker::render_bar( $messages, $opts['style'], array( 'empty_text' => self::PREVIEW_EMPTY_TEXT ) ); ?>
				</div>
				<div class="cmdroom-ticker-preview-body">
					<span class="cmdroom-ticker-preview-fake-bar cmdroom-ticker-preview-fake-bar--55"></span>
					<span class="cmdroom-ticker-preview-fake-bar cmdroom-ticker-preview-fake-bar--80"></span>
				</div>
				<div data-cr-ticker-bar-slot="bottom" <?php echo 'bottom' !== $opts['style']['position'] ? 'hidden' : ''; ?>>
					<?php Cmdroom_Ticker::render_bar( $messages, $opts['style'], array( 'empty_text' => self::PREVIEW_EMPTY_TEXT ) ); ?>
				</div>
			</div>
		</div>

		<?php self::render_preview_data_script( $opts, $view ); ?>
		<?php
	}

	/**
	 * Datos en bruto para que la vista previa en vivo recalcule en el
	 * navegador sin volver a pedir nada al servidor:
	 * - 'auto': pool en bruto (sin plantilla) de las 4 fuentes -- JS aplica
	 *   plantilla/on-off/vigencia/máximo tal como estén escritos en ese momento.
	 * - 'mixed': los mensajes YA resueltos de Fijo (Configurado, día actual)
	 *   y Automático (con los ajustes guardados) -- JS solo reproduce el
	 *   algoritmo de intercalado con el orden/proporción/límite en vivo.
	 * 'manual' no necesita nada: JS lee directamente los campos de texto.
	 */
	private static function render_preview_data_script( $opts, $view ) {
		$data = array();
		if ( 'auto' === $view ) {
			$data['auto'] = Cmdroom_Ticker_Resolver::raw_auto_sources();
		} elseif ( 'mixed' === $view ) {
			$day           = Cmdroom_Ticker_Resolver::current_day_key();
			$data['fixed'] = Cmdroom_Ticker_Resolver::resolve_manual( $opts['manual'], $day );
			$data['auto']  = Cmdroom_Ticker_Resolver::resolve_auto( $opts['auto'] );
		}
		?>
		<script type="application/json" id="cmdroom-ticker-preview-data"><?php echo wp_json_encode( $data ); ?></script>
		<?php
	}

	private static function render_origin_and_activation( $opts, $view ) {
		?>
		<div class="cmdroom-ticker-origin-row">
			<div>
				<span class="cr-label"><?php esc_html_e( 'Origen de los mensajes', 'command-room' ); ?></span>
				<div class="cr-seg cr-seg-lg">
					<?php foreach ( self::MODE_LABELS as $key => $label ) : ?>
						<a class="cr-seg-opt<?php echo $view === $key ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::view_url( $key ) ); ?>">
							<?php echo esc_html( $label ); ?>
							<?php if ( $opts['mode'] === $key ) : ?>
								<span class="cmdroom-ticker-mode-dot" title="<?php esc_attr_e( 'Modo en uso', 'command-room' ); ?>"></span>
							<?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="cr-card cmdroom-ticker-activation">
				<button type="button" class="cr-toggle<?php echo $opts['enabled'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="enabled" value="1" <?php checked( $opts['enabled'] ); ?> /></button>
				<div>
					<p class="cmdroom-ticker-activation-title"><?php esc_html_e( 'Activar ticker en el sitio', 'command-room' ); ?></p>
					<p class="cmdroom-ticker-activation-note">
						<?php if ( $opts['enabled'] ) : ?>
							<?php printf( esc_html__( 'Se imprime en todas las páginas con el modo «%s».', 'command-room' ), esc_html( self::MODE_LABELS[ $opts['mode'] ] ) ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Mientras esté apagado no se imprime nada.', 'command-room' ); ?>
						<?php endif; ?>
					</p>
				</div>
			</div>
		</div>

		<div class="cmdroom-ticker-mode-desc-row">
			<p class="cmdroom-ticker-mode-desc"><?php echo esc_html( self::MODE_DESCRIPTIONS[ $view ] ); ?></p>
			<?php if ( $opts['mode'] === $view ) : ?>
				<span class="cr-pill cr-pill-other cmdroom-ticker-mode-pill">● <?php esc_html_e( 'Modo en uso', 'command-room' ); ?></span>
			<?php else : ?>
				<a
					class="cr-btn-secondary cr-btn-compact"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmdroom_ticker_use_mode&mode=' . $view ), 'cmdroom_ticker_use_mode_' . $view ) ); ?>"
				><?php esc_html_e( 'Usar este modo', 'command-room' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_auto_subtab( $opts ) {
		$auto = $opts['auto'];
		?>
		<div class="cr-card cmdroom-ticker-auto-list">
			<?php foreach ( self::AUTO_SOURCES as $key => $meta ) :
				if ( in_array( $key, array( 'sale', 'shipping', 'coupons' ), true ) && ! Cmdroom_Ticker_Resolver::has_woocommerce() ) {
					continue; // se ocultan si WooCommerce no está activo.
				}
				$cfg     = isset( $auto['sources'][ $key ] ) ? $auto['sources'][ $key ] : array( 'on' => false, 'tpl' => '' );
				$on      = ! empty( $cfg['on'] );
				$example = Cmdroom_Ticker_Resolver::apply_vars( $cfg['tpl'], $meta['example'] );
				?>
				<div class="cmdroom-ticker-auto-row<?php echo $on ? '' : ' is-off'; ?>">
					<button type="button" class="cr-toggle<?php echo $on ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="src_<?php echo esc_attr( $key ); ?>_on" value="1" <?php checked( $on ); ?> /></button>
					<div class="cmdroom-ticker-auto-row-body">
						<p class="cmdroom-ticker-auto-row-title"><?php echo esc_html( $meta['label'] ); ?></p>
						<p class="cmdroom-ticker-auto-row-desc"><?php echo esc_html( $meta['desc'] ); ?></p>
						<input type="text" class="cr-input cmdroom-ticker-auto-tpl" name="src_<?php echo esc_attr( $key ); ?>_tpl" value="<?php echo esc_attr( $cfg['tpl'] ); ?>" />
						<p class="cmdroom-ticker-auto-row-example"><?php esc_html_e( 'Sale como:', 'command-room' ); ?> <strong><?php echo esc_html( $example ); ?></strong></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="cmdroom-ticker-auto-grid">
			<div class="cr-dialog-field">
				<label class="cr-label" for="cmdroom-ticker-days"><?php esc_html_e( 'Vigencia de entradas nuevas', 'command-room' ); ?></label>
				<input type="number" min="1" id="cmdroom-ticker-days" name="days" class="cr-input" value="<?php echo esc_attr( $auto['days'] ); ?>" /> <span class="description"><?php esc_html_e( 'días', 'command-room' ); ?></span>
			</div>
			<div class="cr-dialog-field">
				<label class="cr-label" for="cmdroom-ticker-max"><?php esc_html_e( 'Máximo por fuente', 'command-room' ); ?></label>
				<input type="number" min="1" id="cmdroom-ticker-max" name="max_per_source" class="cr-input" value="<?php echo esc_attr( $auto['max_per_source'] ); ?>" /> <span class="description"><?php esc_html_e( 'mensajes', 'command-room' ); ?></span>
			</div>
		</div>

		<p class="description cmdroom-ticker-auto-note">
			<?php esc_html_e( 'Las plantillas aceptan las variables de', 'command-room' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cmdroom-variables' ) ); ?>"><?php esc_html_e( 'Variables', 'command-room' ); ?></a>.
			<?php esc_html_e( 'Cada mensaje enlaza a su entrada, producto o página de origen.', 'command-room' ); ?>
		</p>
		<?php
	}

	private static function render_manual_subtab( $opts, $day ) {
		$by_day = ! empty( $opts['manual']['by_day'] );
		$key    = $by_day ? $day : 'all';
		$msgs   = isset( $opts['manual'][ $key ] ) ? $opts['manual'][ $key ] : array();
		?>
		<div class="cmdroom-ticker-byday-row">
			<button type="button" class="cr-toggle<?php echo $by_day ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="by_day" value="1" <?php checked( $by_day ); ?> /></button>
			<span class="cmdroom-ticker-byday-label"><?php esc_html_e( 'Programar por día de la semana', 'command-room' ); ?></span>
			<span class="cmdroom-ticker-byday-note">· <?php esc_html_e( 'sábado y domingo comparten lista', 'command-room' ); ?></span>
		</div>

		<?php if ( $by_day ) : ?>
			<nav class="cr-tabs cmdroom-ticker-day-tabs">
				<?php foreach ( Cmdroom_Ticker_Resolver::day_labels() as $dkey => $dlabel ) : ?>
					<a class="cr-tab<?php echo $day === $dkey ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::view_url( 'manual', array( 'day' => $dkey ) ) ); ?>"><?php echo esc_html( $dlabel ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<input type="hidden" name="day" value="<?php echo esc_attr( $day ); ?>" />

		<div class="cmdroom-ticker-msg-grid" data-cr-ticker-messages>
			<div class="cmdroom-ticker-msg-head">
				<span></span>
				<span><?php esc_html_e( 'Mensaje', 'command-room' ); ?></span>
				<span><?php esc_html_e( 'URL (opcional)', 'command-room' ); ?></span>
				<span></span>
			</div>
			<?php foreach ( $msgs as $i => $msg ) : ?>
				<div class="cmdroom-ticker-msg-row">
					<span class="cmdroom-ticker-msg-num"><?php printf( esc_html__( 'Mensaje %d', 'command-room' ), $i + 1 ); ?></span>
					<input type="text" class="cr-input" name="msg_text[]" value="<?php echo esc_attr( $msg['text'] ); ?>" placeholder="<?php esc_attr_e( 'Envío gratis desde 50 €', 'command-room' ); ?>" />
					<input type="text" class="cr-input" name="msg_url[]" value="<?php echo esc_attr( $msg['url'] ); ?>" placeholder="/envios/" />
					<button type="button" class="cr-btn-secondary cmdroom-ticker-msg-remove" data-cr-ticker-remove-row>×</button>
				</div>
			<?php endforeach; ?>
		</div>
		<button type="button" class="cr-btn-secondary cr-btn-compact" data-cr-ticker-add-row><?php esc_html_e( '+ Añadir mensaje', 'command-room' ); ?></button>

		<p class="description cmdroom-ticker-manual-note">
			<?php esc_html_e( 'Con URL, el mensaje sale como enlace; en blanco, como texto suelto.', 'command-room' ); ?>
			<?php echo $by_day
				? esc_html__( 'Cada día sale automáticamente su lista; nadie tiene que entrar a cambiarla.', 'command-room' )
				: esc_html__( 'La misma lista se muestra todos los días.', 'command-room' ); ?>
		</p>
		<?php
	}

	private static function render_mixed_subtab( $opts ) {
		$mixed = $opts['mixed'];
		$day   = Cmdroom_Ticker_Resolver::current_day_key();
		$fixed = Cmdroom_Ticker_Resolver::resolve_manual( $opts['manual'], $day );
		$auto  = Cmdroom_Ticker_Resolver::resolve_auto( $opts['auto'] );
		$rot   = Cmdroom_Ticker_Resolver::resolve_mixed( $mixed, $fixed, $auto );
		?>
		<div class="cmdroom-ticker-mixed-grid">
			<div class="cr-dialog-field">
				<span class="cr-label"><?php esc_html_e( 'Orden', 'command-room' ); ?></span>
				<div class="cr-seg">
					<?php
					$orders = array( 'interleave' => 'Intercalar', 'fixed' => 'Fijos primero', 'auto' => 'Automáticos primero' );
					foreach ( $orders as $okey => $olabel ) :
						?>
						<button type="button" class="cr-seg-opt<?php echo $mixed['order'] === $okey ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $okey ); ?>"><?php echo esc_html( $olabel ); ?></button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="mixed_order" class="cr-seg-value" value="<?php echo esc_attr( $mixed['order'] ); ?>" />
			</div>
			<div class="cr-dialog-field">
				<label class="cr-label" for="cmdroom-ticker-ratio"><?php esc_html_e( 'Proporción (solo con Intercalar)', 'command-room' ); ?></label>
				<span class="cmdroom-ticker-ratio-field">1 fijo cada <input type="number" min="1" id="cmdroom-ticker-ratio" name="mixed_ratio" class="cr-input" value="<?php echo esc_attr( $mixed['ratio'] ); ?>" /> automáticos</span>
			</div>
			<div class="cr-dialog-field">
				<label class="cr-label" for="cmdroom-ticker-maxrot"><?php esc_html_e( 'Límite en rotación', 'command-room' ); ?></label>
				<input type="number" min="1" id="cmdroom-ticker-maxrot" name="mixed_max" class="cr-input" value="<?php echo esc_attr( $mixed['max'] ); ?>" /> <span class="description"><?php esc_html_e( 'mensajes', 'command-room' ); ?></span>
			</div>
		</div>

		<div class="cr-card cmdroom-ticker-rotation">
			<span class="cr-label"><?php esc_html_e( 'Rotación resultante', 'command-room' ); ?></span>
			<?php if ( empty( $rot ) ) : ?>
				<p class="description"><?php esc_html_e( 'Sin mensajes todavía en Configurado ni en Automático.', 'command-room' ); ?></p>
			<?php else : ?>
				<?php foreach ( $rot as $i => $msg ) : ?>
					<div class="cmdroom-ticker-rotation-row">
						<span class="cmdroom-ticker-rotation-num"><?php echo (int) ( $i + 1 ); ?></span>
						<span class="cr-pill <?php echo 'fixed' === $msg['source'] ? 'cr-pill-other' : 'cr-pill-301'; ?>"><?php echo 'fixed' === $msg['source'] ? esc_html__( 'Fijo', 'command-room' ) : esc_html__( 'Auto', 'command-room' ); ?></span>
						<span><?php echo esc_html( $msg['text'] ); ?></span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<p class="description cmdroom-ticker-mixed-note">
			<?php
			printf(
				/* translators: 1: link a Configurado, 2: link a Automático */
				esc_html__( 'Los fijos salen de la pestaña %1$s y los automáticos de %2$s. Si un día no tiene fijos, el ticker sigue solo con automáticos.', 'command-room' ),
				'<a href="' . esc_url( self::view_url( 'manual' ) ) . '">' . esc_html__( 'Configurado', 'command-room' ) . '</a>',
				'<a href="' . esc_url( self::view_url( 'auto' ) ) . '">' . esc_html__( 'Automático', 'command-room' ) . '</a>'
			);
			?>
		</p>
		<?php
	}

	private static function render_appearance( $opts ) {
		$style = $opts['style'];
		?>
		<h3 class="cmdroom-ticker-appearance-heading"><?php esc_html_e( 'Apariencia · común a los tres modos', 'command-room' ); ?></h3>
		<div class="cmdroom-ticker-appearance-grid">
			<div class="cr-dialog-field">
				<span class="cr-label"><?php esc_html_e( 'Velocidad', 'command-room' ); ?></span>
				<div class="cr-seg">
					<?php foreach ( array( 'slow' => 'Lenta', 'normal' => 'Normal', 'fast' => 'Rápida' ) as $skey => $slabel ) : ?>
						<button type="button" class="cr-seg-opt<?php echo $style['speed'] === $skey ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $skey ); ?>"><?php echo esc_html( $slabel ); ?></button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="speed" class="cr-seg-value" value="<?php echo esc_attr( $style['speed'] ); ?>" />
			</div>
			<div class="cr-dialog-field">
				<span class="cr-label"><?php esc_html_e( 'Posición', 'command-room' ); ?></span>
				<div class="cr-seg">
					<?php foreach ( array( 'below_menu' => 'Debajo del menú', 'bottom' => 'Abajo del sitio' ) as $pkey => $plabel ) : ?>
						<button type="button" class="cr-seg-opt<?php echo $style['position'] === $pkey ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $pkey ); ?>"><?php echo esc_html( $plabel ); ?></button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="position" class="cr-seg-value" value="<?php echo esc_attr( $style['position'] ); ?>" />
			</div>
			<div class="cr-dialog-field">
				<label class="cr-label" for="cmdroom-ticker-sep"><?php esc_html_e( 'Separador', 'command-room' ); ?></label>
				<input type="text" id="cmdroom-ticker-sep" name="separator" class="cr-input cmdroom-ticker-sep-input" maxlength="5" value="<?php echo esc_attr( $style['separator'] ); ?>" />
			</div>
			<div class="cr-dialog-field">
				<label class="cr-label"><?php esc_html_e( 'Fondo / Texto', 'command-room' ); ?></label>
				<div class="cmdroom-ticker-colors">
					<input type="color" name="bg_color" class="cmdroom-ticker-color" value="<?php echo esc_attr( $style['bg'] ); ?>" />
					<input type="color" name="text_color" class="cmdroom-ticker-color" value="<?php echo esc_attr( $style['fg'] ); ?>" />
				</div>
			</div>
		</div>

		<div class="cmdroom-ticker-row" style="margin-top:14px;">
			<button type="button" class="cr-toggle<?php echo $style['pause_on_hover'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="pause_on_hover" value="1" <?php checked( $style['pause_on_hover'] ); ?> /></button>
			<div class="cmdroom-ticker-row-body">
				<p class="cmdroom-ticker-row-title"><?php esc_html_e( 'Pausar al pasar el ratón por encima', 'command-room' ); ?></p>
			</div>
		</div>
		<?php
	}

	public static function render_tab() {
		$opts = self::get_options();
		$view = self::get_view();
		$day  = self::get_day();
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cmdroom-ticker-screen">
			<?php self::render_preview( $opts, $view, $day ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cr-ticker-form>
				<?php wp_nonce_field( 'cmdroom_save_ticker' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_ticker" />
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />
				<?php if ( 'manual' !== $view ) : ?>
					<input type="hidden" name="day" value="<?php echo esc_attr( $day ); ?>" />
				<?php endif; ?>

				<?php self::render_origin_and_activation( $opts, $view ); ?>

				<?php
				switch ( $view ) {
					case 'auto':
						self::render_auto_subtab( $opts );
						break;
					case 'mixed':
						self::render_mixed_subtab( $opts );
						break;
					default:
						self::render_manual_subtab( $opts, $day );
						break;
				}
				?>

				<?php self::render_appearance( $opts ); ?>

				<?php submit_button( __( 'Guardar ticker', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
			</form>
		</div>
		<?php
	}
}
