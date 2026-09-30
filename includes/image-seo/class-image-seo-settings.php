<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "Auto-Image SEO" de la pantalla "Configuración". Cada regla es un
 * toggle independiente, las lee Cmdroom_Image_Seo para decidir qué aplicar
 * tanto al subir como en la salida (wp_get_attachment_image_attributes +
 * the_content).
 *
 * Desde el rediseño "Configuración" (2026-09-23): antes solo existían
 * `alt_from_parent_title`/`alt_from_filename`/`title_auto`/`rename_file`,
 * fijos y aplicados solo en add_attachment (imágenes nuevas). Ahora hay
 * plantillas con variables (`%image_name%`/`%title%`/`%sitename%`) y las
 * reglas se aplican también en la salida (imágenes que ya estaban en la
 * biblioteca antes de instalar el plugin, o subidas sin pasar por el
 * editor de un post). `rename_file` se mantiene como fila extra — el
 * mockup no la cubre pero ya funcionaba y no había motivo para quitarla.
 */
class Cmdroom_Image_Seo_Settings {

	const OPTION = 'cmdroom_image_seo_options';

	const RULES = array(
		'alt'    => array(
			'title'   => 'Rellenar alt vacíos',
			'desc'    => 'Aplica la plantilla solo a las imágenes sin texto alternativo. Nunca sobrescribe uno escrito a mano.',
			'default' => true,
		),
		'title'  => array(
			'title'   => 'Rellenar atributo title',
			'desc'    => 'Añade title a las imágenes que no lo tienen.',
			'default' => false,
		),
		'clean'  => array(
			'title'   => 'Limpiar nombre de archivo',
			'desc'    => 'Quita guiones, extensiones y sufijos como -scaled o -1024x768 al generar %image_name%.',
			'default' => true,
		),
		'upload' => array(
			'title'   => 'Guardar al subir',
			'desc'    => 'Escribe el alt en la biblioteca de medios al subir la imagen, no solo en la salida HTML.',
			'default' => true,
		),
		// Heredada del módulo suelto anterior -- no está en el mockup, se
		// mantiene como fila extra para no perder una función ya construida
		// y probada.
		'rename_file' => array(
			'title'   => 'Renombrar archivo físico',
			'desc'    => 'Renombra el archivo al patrón {slug-del-post}.ext al subirlo dentro del editor de un post (WordPress añade -1, -2… si ya existe otro con ese nombre). Sin post de contexto, no renombra.',
			'default' => true,
		),
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_image_seo', array( __CLASS__, 'handle_save' ) );
	}

	public static function get_options() {
		$defaults = array(
			'alt_tpl'   => '%image_name% - %title%',
			'title_tpl' => '%image_name%',
		);
		foreach ( self::RULES as $key => $rule ) {
			$defaults[ $key ] = $rule['default'];
		}
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	public static function is_enabled( $rule ) {
		$opts = self::get_options();
		return ! empty( $opts[ $rule ] );
	}

	private static function sanitize_template( $raw ) {
		$tpl = sanitize_text_field( wp_unslash( $raw ) );
		// Variables desconocidas fuera de la lista blanca se eliminan --
		// nunca se guarda un %algo% que no vaya a resolverse nunca.
		return preg_replace_callback( '/%[a-z_]+%/', function ( $m ) {
			return in_array( $m[0], array( '%image_name%', '%title%', '%sitename%' ), true ) ? $m[0] : '';
		}, $tpl );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_image_seo' );

		$opts = array(
			'alt_tpl'   => isset( $_POST['alt_tpl'] ) ? self::sanitize_template( $_POST['alt_tpl'] ) : '%image_name% - %title%',
			'title_tpl' => isset( $_POST['title_tpl'] ) ? self::sanitize_template( $_POST['title_tpl'] ) : '%image_name%',
		);
		foreach ( self::RULES as $key => $rule ) {
			$opts[ $key ] = ! empty( $_POST[ $key ] );
		}

		update_option( self::OPTION, $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function render_tab() {
		$opts = self::get_options();
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cr-imgseo-form>
			<?php wp_nonce_field( 'cmdroom_save_image_seo' ); ?>
			<input type="hidden" name="action" value="cmdroom_save_image_seo" />

			<div class="cmdroom-config-grid">
				<div class="cr-dialog-field">
					<label class="cr-label" for="cmdroom-imgseo-alt-tpl"><?php esc_html_e( 'Plantilla de alt', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-imgseo-alt-tpl" name="alt_tpl" class="cr-input" value="<?php echo esc_attr( $opts['alt_tpl'] ); ?>" data-cr-imgseo-field="alt" />
				</div>
				<div class="cr-dialog-field">
					<label class="cr-label" for="cmdroom-imgseo-title-tpl"><?php esc_html_e( 'Plantilla de title', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-imgseo-title-tpl" name="title_tpl" class="cr-input" value="<?php echo esc_attr( $opts['title_tpl'] ); ?>" data-cr-imgseo-field="title" />
				</div>
			</div>

			<p class="cmdroom-config-vars">
				<?php esc_html_e( 'Variables:', 'command-room' ); ?>
				<?php foreach ( array( '%image_name%', '%title%', '%sitename%' ) as $var ) : ?>
					<button type="button" class="cr-chip" data-cr-imgseo-var="<?php echo esc_attr( $var ); ?>"><?php echo esc_html( $var ); ?></button>
				<?php endforeach; ?>
			</p>

			<label class="cr-label"><?php esc_html_e( 'Vista previa · zapatilla-running-azul-1024x768.jpg', 'command-room' ); ?></label>
			<div class="cr-card cmdroom-config-imgseo-preview" data-cr-imgseo-preview></div>

			<div class="cr-card cmdroom-config-rule-list">
				<?php foreach ( self::RULES as $key => $rule ) :
					$on = ! empty( $opts[ $key ] );
					?>
					<div class="cmdroom-config-rule-row">
						<button type="button" class="cr-toggle<?php echo $on ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $on ); ?> /></button>
						<div class="cmdroom-config-rule-body">
							<p class="cmdroom-config-rule-title"><?php echo esc_html( $rule['title'] ); ?></p>
							<p class="cmdroom-config-rule-desc"><?php echo esc_html( $rule['desc'] ); ?></p>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
		</form>
		<?php
	}
}
