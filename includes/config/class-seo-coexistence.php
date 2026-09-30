<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aviso + botón explícito para desactivar Rank Math o Yoast SEO desde la
 * propia pantalla de "Salida en el sitio" (Configuración → Herramientas).
 *
 * Pedido por el administrador 2026-09-25 tras un incidente real: se activó
 * "Salida en el sitio" con Rank Math todavía activo y la home sirvió
 * <title>/<meta description>/<link rel=canonical> DUPLICADOS (uno de Rank
 * Math, otro de Command Room) hasta que se desactivó Rank Math a mano por
 * SSH. El plugin ya avisaba de esto en el copy del checkbox de cada módulo
 * ("déjalo apagado mientras comparas contra Rank Math"), pero no había
 * ninguna acción para resolverlo sin salir del admin -- este bloque lo
 * hace explícito: detecta si Rank Math y/o Yoast SEO siguen activos y
 * ofrece desactivarlos con un solo clic, justo encima de los toggles de
 * salida para que sea lo primero que se vea al activarlos.
 */
class Cmdroom_Seo_Coexistence {

	const PLUGINS = array(
		'seo-by-rank-math/rank-math.php' => 'Rank Math',
		'wordpress-seo/wp-seo.php'       => 'Yoast SEO',
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_deactivate_seo_plugin', array( __CLASS__, 'handle_deactivate' ) );
	}

	private static function get_active_conflicts() {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = array();
		foreach ( self::PLUGINS as $file => $label ) {
			if ( is_plugin_active( $file ) ) {
				$active[ $file ] = $label;
			}
		}
		return $active;
	}

	public static function handle_deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_deactivate_seo_plugin' );

		$file = isset( $_POST['plugin_file'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_file'] ) ) : '';
		if ( isset( self::PLUGINS[ $file ] ) ) {
			deactivate_plugins( $file );
		}

		wp_safe_redirect( add_query_arg( 'cmdroom_seo_deactivated', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Se pinta siempre (activo o no) al principio de "Salida en el sitio",
	 * para que el estado de coexistencia sea lo primero que se ve antes de
	 * tocar ningún toggle de módulo.
	 */
	public static function render() {
		$conflicts = self::get_active_conflicts();
		?>
		<?php if ( isset( $_GET['cmdroom_seo_deactivated'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Plugin desactivado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cr-card cmdroom-seo-coexist<?php echo $conflicts ? ' is-warning' : ''; ?>">
			<h2 class="cmdroom-seo-coexist-title">
				<?php esc_html_e( 'Coexistencia con otro plugin SEO', 'command-room' ); ?>
			</h2>

			<?php if ( ! $conflicts ) : ?>
				<p class="cmdroom-seo-coexist-ok">
					<?php esc_html_e( 'Ni Rank Math ni Yoast SEO están activos -- sin riesgo de doble output al activar "Salida en el sitio".', 'command-room' ); ?>
				</p>
			<?php else : ?>
				<p class="cmdroom-seo-coexist-warn">
					<?php esc_html_e( 'Si activas "Salida en el sitio" con uno de estos plugins todavía activo, el sitio servirá metas/schema/canonical DUPLICADOS (uno de cada plugin) en la misma página. Desactívalo antes de activar la salida real, o inmediatamente si ya la activaste.', 'command-room' ); ?>
				</p>
				<?php foreach ( $conflicts as $file => $label ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmdroom-seo-coexist-row">
						<?php wp_nonce_field( 'cmdroom_deactivate_seo_plugin' ); ?>
						<input type="hidden" name="action" value="cmdroom_deactivate_seo_plugin" />
						<input type="hidden" name="plugin_file" value="<?php echo esc_attr( $file ); ?>" />
						<span class="cmdroom-seo-coexist-badge">
							<?php
							printf(
								/* translators: %s: plugin name (Rank Math or Yoast SEO) */
								esc_html__( '%s -- ACTIVO', 'command-room' ),
								esc_html( $label )
							);
							?>
						</span>
						<button type="submit" class="button button-primary cmdroom-seo-coexist-btn" onclick="return confirm('<?php echo esc_js( sprintf( __( '¿Desactivar %s ahora? Command Room pasará a ser la única fuente de metas/schema/sitemaps en el sitio.', 'command-room' ), $label ) ); ?>');">
							<?php
							printf(
								/* translators: %s: plugin name (Rank Math or Yoast SEO) */
								esc_html__( 'Desactivar %s', 'command-room' ),
								esc_html( $label )
							);
							?>
						</button>
					</form>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
