<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inyección de código en wp_head/wp_footer — pensado para snippets de
 * verificación (Search Console, Bing Webmaster) o analítica. El contenido
 * se imprime SIN esc_html() a propósito: es HTML/JS intencional. La única
 * barrera está en el guardado, que exige manage_options + nonce, igual que
 * cualquier otro ajuste del plugin — nadie que no sea admin puede escribir
 * aquí, así que no hay una superficie de XSS nueva.
 */
class Cmdroom_Code_Injection {

	const OPTION = 'cmdroom_code_injection_options';

	public static function init() {
		add_action( 'admin_post_cmdroom_save_code_injection', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 99 );
		add_action( 'wp_footer', array( __CLASS__, 'print_footer' ), 99 );
	}

	public static function get_options() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	private static function defaults() {
		return array(
			'head'   => '',
			'footer' => '',
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_code_injection' );

		// Guardado deliberadamente sin sanitizar el HTML/JS: es el propósito
		// del módulo. La barrera es el capability check de arriba, no el
		// contenido en sí — igual que el editor de temas/plugins nativo de WP.
		$opts = array(
			'head'   => isset( $_POST['cmdroom_head_code'] ) ? wp_unslash( $_POST['cmdroom_head_code'] ) : '',
			'footer' => isset( $_POST['cmdroom_footer_code'] ) ? wp_unslash( $_POST['cmdroom_footer_code'] ) : '',
		);

		update_option( self::OPTION, $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function print_head() {
		$opts = self::get_options();
		if ( '' !== trim( $opts['head'] ) ) {
			echo "\n<!-- Command Room: código en head -->\n" . $opts['head'] . "\n<!-- /Command Room -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- intencional, ver docblock de la clase
		}
	}

	public static function print_footer() {
		$opts = self::get_options();
		if ( '' !== trim( $opts['footer'] ) ) {
			echo "\n<!-- Command Room: código en footer -->\n" . $opts['footer'] . "\n<!-- /Command Room -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- intencional, ver docblock de la clase
		}
	}

	/**
	 * Antes era render_page() con su propio <div class="wrap">/H1 — desde
	 * que "Inyección" pasó a ser una pestaña de "Código"
	 * (Cmdroom_Code_Admin), el wrap y el H1 los pinta el cascarón, esta
	 * clase solo aporta el contenido de su pestaña.
	 */
	public static function render_tab() {
		$opts = self::get_options();
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cr-card cmdroom-code-injection-card">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdroom_save_code_injection' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_code_injection" />

				<div class="cmdroom-md-block">
					<h2><?php esc_html_e( 'Antes de &lt;/head&gt;', 'command-room' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Se imprime al final de wp_head — después de las metas y el schema de Command Room.', 'command-room' ); ?></p>
					<?php self::render_code_block( 'cmdroom_head_code', $opts['head'] ); ?>
				</div>

				<div class="cmdroom-md-block">
					<h2><?php esc_html_e( 'Antes de &lt;/body&gt;', 'command-room' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Se imprime al final de wp_footer.', 'command-room' ); ?></p>
					<?php self::render_code_block( 'cmdroom_footer_code', $opts['footer'] ); ?>
				</div>

				<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Mismo bloque "terminal" (barra estilo macOS + contenteditable con
	 * textarea oculta real) que Metas/Datos estructurados -- ver
	 * Cmdroom_Meta_Settings::render_code_block(). Se duplica en vez de
	 * reusar esa clase porque no hay una relación natural entre ambas; el
	 * enganche compartido es puramente visual (misma CSS/JS, ver
	 * assets/js/meta-editor.js:initEditor(), que engancha cualquier
	 * .cmdroom-md-code de la página sin necesidad de JS propio aquí).
	 */
	private static function render_code_block( $name, $html ) {
		?>
		<div class="cmdroom-md-terminal">
			<div class="cmdroom-md-terminal-bar">
				<span class="cmdroom-md-dot cmdroom-md-dot-red"></span>
				<span class="cmdroom-md-dot cmdroom-md-dot-amber"></span>
				<span class="cmdroom-md-dot cmdroom-md-dot-green"></span>
			</div>
			<div class="cmdroom-md-terminal-body">
				<div class="cmdroom-md-code" contenteditable="true" spellcheck="false"><?php echo self::render_highlighted( $html ); // phpcs:ignore -- ya escapado dentro ?></div>
				<textarea name="<?php echo esc_attr( $name ); ?>" class="cmdroom-md-code-source"><?php echo esc_textarea( $html ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * No hay variables %algo% en este módulo (es HTML/JS libre, no metas),
	 * pero se reusa el mismo resaltado que Metas para que el primer
	 * renderizado en servidor sea idéntico a lo que meta-editor.js produce
	 * en el primer 'input' -- si alguna vez se pegara un literal "%algo%"
	 * se resaltaría en naranja como en cualquier otra pantalla, sin efecto
	 * en el valor guardado.
	 */
	private static function render_highlighted( $html ) {
		$escaped = esc_html( $html );
		return preg_replace( '/(%[a-z_]+%)/i', '<span class="cmdroom-md-var">$1</span>', $escaped );
	}
}
