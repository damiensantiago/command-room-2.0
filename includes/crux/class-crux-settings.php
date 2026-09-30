<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API key de CrUX (Chrome UX Report) -- a diferencia de GSC, esta API no
 * usa OAuth, solo una API key simple de Google Cloud (se puede crear en el
 * mismo proyecto de Google Cloud que el cliente OAuth de GSC, habilitando la
 * "Chrome UX Report API"). Vive en "Configuración → Herramientas", junto a
 * la conexión de GSC -- los datos en sí (curvas de LCP/INP/CLS por tipo de
 * contenido) se muestran en "General" vía Cmdroom_Crux_Dashboard.
 */
class Cmdroom_Crux_Settings {

	const OPTION = 'cmdroom_crux';

	const DEFAULTS = array(
		'api_key' => '',
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_crux_save_key', array( __CLASS__, 'handle_save_key' ) );
	}

	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::DEFAULTS );
	}

	public static function get_api_key() {
		$opts = self::get_options();
		return $opts['api_key'];
	}

	public static function is_configured() {
		return ! empty( self::get_api_key() );
	}

	public static function handle_save_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_crux_save_key' );

		$opts            = self::get_options();
		$opts['api_key'] = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		update_option( self::OPTION, $opts );

		// La clave puede haber cambiado -- fuera la caché de series vieja,
		// que si no se quedaría sirviendo (o fallando) con la clave antigua
		// hasta que expire sola a las 24h.
		delete_transient( 'cmdroom_crux_series' );

		wp_safe_redirect( add_query_arg( 'cmdroom_crux_key_saved', '1', Cmdroom_Config_Admin::tab_url( 'tools' ) ) );
		exit;
	}

	/**
	 * Tarjeta de "Herramientas". Mismo patrón sin envoltorio propio que
	 * Cmdroom_Gsc_Settings::render_tab() -- el llamante la mete dentro de
	 * un .cmdroom-config-extra.
	 */
	public static function render_tab() {
		$opts = self::get_options();
		?>
		<p class="description"><?php esc_html_e( 'Curvas de Core Web Vitals (LCP, INP, CLS) por tipo de contenido en la pantalla General, con datos reales de usuarios (CrUX). No usa OAuth -- solo una API key de Google Cloud con la Chrome UX Report API habilitada (se puede crear en el mismo proyecto que Sheets/Drive/GSC).', 'command-room' ); ?></p>

		<?php if ( isset( $_GET['cmdroom_crux_key_saved'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Clave guardada.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmdroom_crux_save_key' ); ?>
			<input type="hidden" name="action" value="cmdroom_crux_save_key" />
			<table class="form-table" style="margin-top:0;">
				<tr>
					<th><label for="cmdroom_crux_api_key"><?php esc_html_e( 'API key de CrUX', 'command-room' ); ?></label></th>
					<td>
						<input type="text" id="cmdroom_crux_api_key" name="api_key" value="<?php echo esc_attr( $opts['api_key'] ); ?>" class="regular-text" autocomplete="off" />
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Guardar clave', 'command-room' ), 'cr-btn-secondary', 'submit', false ); ?>
		</form>
		<?php
	}
}
