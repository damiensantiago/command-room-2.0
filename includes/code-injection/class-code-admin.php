<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Código" — antes "Inyección de código" a secas (petición de
 * el administrador 2026-09-25). Ahora tiene dos pestañas: "Listado" (nueva, la que
 * se ve por defecto) con el inventario real de scripts JS y hojas de
 * estilo del sitio (Cmdroom_Code_Assets), e "Inyección" con lo que antes
 * era toda la pantalla (Cmdroom_Code_Injection, pegar código a mano en
 * head/footer). Cascarón fino, mismo patrón que Cmdroom_Server_Admin/
 * Cmdroom_Config_Admin: solo pinta H1 + intro + pestañas y delega el
 * contenido y el guardado de cada una a la clase dueña.
 */
class Cmdroom_Code_Admin {

	const SLUG = 'cmdroom-code';

	const TABS = array(
		'listado'   => 'listado',
		'inyeccion' => 'inyeccion',
	);

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		Cmdroom_Code_Assets::init();
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		wp_enqueue_style( 'cmdroom-meta-editor', CMDROOM_URL . 'assets/css/meta-editor.css', array(), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-servidor-editor', CMDROOM_URL . 'assets/css/servidor-editor.css', array( 'cmdroom-meta-editor' ), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-code-editor', CMDROOM_URL . 'assets/css/code-editor.css', array( 'cmdroom-servidor-editor' ), CMDROOM_VERSION );
		// Engancha el contenteditable de los cuadros de código de la pestaña
		// "Inyección" (mismo .cmdroom-md-code que Metas/Datos estructurados) --
		// ver Cmdroom_Code_Injection::render_code_block().
		wp_enqueue_script( 'cmdroom-meta-editor', CMDROOM_URL . 'assets/js/meta-editor.js', array(), CMDROOM_VERSION, true );
	}

	public static function get_active_tab() {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'listado';
		return isset( self::TABS[ $requested ] ) ? $requested : 'listado';
	}

	public static function tab_url( $tab ) {
		return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
	}

	public static function render_page() {
		$active_tab = self::get_active_tab();
		$labels     = array(
			'listado'   => __( 'Listado', 'command-room' ),
			'inyeccion' => __( 'Inyección', 'command-room' ),
		);
		?>
		<div class="wrap cmdroom-wrap cmdroom-metadata-wrap cmdroom-code-wrap">
			<h1 class="cmdroom-md-h1"><?php esc_html_e( 'Código', 'command-room' ); ?></h1>

			<div class="cmdroom-md-container">
				<p class="cmdroom-md-intro"><?php esc_html_e( 'Qué scripts y hojas de estilo carga el sitio de verdad, y edición manual de código propio en head/footer.', 'command-room' ); ?></p>

				<nav class="cr-tabs">
					<?php foreach ( $labels as $tab => $label ) : ?>
						<a class="cr-tab<?php echo $active_tab === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::tab_url( $tab ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>

				<?php
				if ( 'inyeccion' === $active_tab ) {
					Cmdroom_Code_Injection::render_tab();
				} else {
					Cmdroom_Code_Assets::render_tab();
				}
				?>
			</div>
		</div>
		<?php
	}
}
