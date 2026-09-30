<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Servidor" — cascarón que junta tres módulos antes sueltos:
 * Redirecciones, Monitor 404 y Limpieza HTTP/permalinks. Cada pestaña
 * delega su contenido y su guardado a la clase dueña de ese módulo
 * (Cmdroom_Redirect_Admin, Cmdroom_404_Admin, Cmdroom_Cleanup_Settings) —
 * esta clase solo pinta el H1, la intro, la barra de pestañas y resuelve
 * las URLs antiguas de las tres pantallas que desaparecen.
 */
class Cmdroom_Server_Admin {

	const SLUG = 'cmdroom-servidor';

	/**
	 * Opción compartida entre las tres pestañas para los ajustes que no
	 * tienen tabla propia: `log404` (Monitor 404) y `clean` (Limpieza). Una
	 * sola opción con dos claves en vez de dos options sueltas, para que
	 * quede junto lo que se pinta junto — el acceso siempre pasa por
	 * get_option_value()/update_option_key(), que hacen merge en vez de
	 * pisarse una clave a la otra.
	 */
	const OPTION = 'cmdroom_servidor';

	public static function get_option_value( $key, $default = null ) {
		$opts = get_option( self::OPTION, array() );
		return is_array( $opts ) && array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	public static function update_option_key( $key, $value ) {
		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$opts[ $key ] = $value;
		update_option( self::OPTION, $opts );
	}

	const TABS = array(
		'redirects' => 'redirects',
		'404'       => '404',
		'clean'     => 'clean',
	);

	/**
	 * Slugs de página que existían antes de la fusión, y a qué pestaña de
	 * "Servidor" redirigen — para que los bookmarks/enlaces guardados no se
	 * rompan.
	 */
	const LEGACY_REDIRECTS = array(
		'cmdroom-redirects'  => 'redirects',
		'cmdroom-monitor404' => '404',
		'cmdroom-cleanup'    => 'clean',
	);

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		wp_enqueue_style( 'cmdroom-meta-editor', CMDROOM_URL . 'assets/css/meta-editor.css', array(), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-servidor-editor', CMDROOM_URL . 'assets/css/servidor-editor.css', array( 'cmdroom-meta-editor' ), CMDROOM_VERSION );
		wp_enqueue_script( 'cmdroom-servidor-editor', CMDROOM_URL . 'assets/js/servidor-editor.js', array(), CMDROOM_VERSION, true );
	}

	/**
	 * Un submenú oculto por cada slug antiguo (registrado sin padre, ver
	 * Cmdroom_Admin_Menu::register_legacy_redirects()) apunta aquí — solo
	 * reenvía a la pestaña equivalente de "Servidor", conservando el resto
	 * de la query string (por si llevaba un ?tab= propio o similar).
	 */
	public static function render_legacy_redirect( $old_slug ) {
		$tab = isset( self::LEGACY_REDIRECTS[ $old_slug ] ) ? self::LEGACY_REDIRECTS[ $old_slug ] : 'redirects';
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ), 301 );
		exit;
	}

	public static function get_active_tab() {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'redirects';
		return isset( self::TABS[ $requested ] ) ? $requested : 'redirects';
	}

	public static function tab_url( $tab ) {
		return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
	}

	public static function render_page() {
		$active_tab = self::get_active_tab();
		$labels     = array(
			'redirects' => __( 'Redirecciones', 'command-room' ),
			'404'       => __( 'Monitor 404', 'command-room' ),
			'clean'     => __( 'Limpieza HTTP/permalinks', 'command-room' ),
		);
		?>
		<div class="wrap cmdroom-wrap cmdroom-metadata-wrap cmdroom-servidor-wrap">
			<h1 class="cmdroom-md-h1"><?php esc_html_e( 'Servidor', 'command-room' ); ?></h1>

			<div class="cmdroom-md-container">
				<p class="cmdroom-md-intro"><?php esc_html_e( 'Gestiona tus redirecciones, los errores, la configuración de tus urls', 'command-room' ); ?></p>

				<nav class="cr-tabs">
					<?php foreach ( $labels as $tab => $label ) : ?>
						<a class="cr-tab<?php echo $active_tab === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::tab_url( $tab ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>

				<?php
				switch ( $active_tab ) {
					case '404':
						Cmdroom_404_Admin::render_tab();
						break;
					case 'clean':
						Cmdroom_Cleanup_Settings::render_tab();
						break;
					default:
						Cmdroom_Redirect_Admin::render_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}
}
