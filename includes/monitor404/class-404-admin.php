<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "Monitor 404" de la pantalla "Servidor". Listado de 404s
 * ordenado por frecuencia, con una acción rápida por fila para convertir
 * esa URL rota en una redirección — reutiliza el diálogo del módulo de
 * Redirecciones en vez de tener su propio formulario "crear 301" (antes
 * del rediseño Claude Design cada pestaña tenía su propio mini-formulario).
 */
class Cmdroom_404_Admin {

	public static function init() {
		add_action( 'admin_post_cmdroom_404_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_cmdroom_404_clear_all', array( __CLASS__, 'handle_clear_all' ) );
		add_action( 'wp_ajax_cmdroom_toggle_log404', array( __CLASS__, 'handle_toggle_log404' ) );
	}

	public static function is_logging_enabled() {
		return (bool) Cmdroom_Server_Admin::get_option_value( 'log404', true );
	}

	public static function handle_toggle_log404() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cmdroom_toggle_log404', '_wpnonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		Cmdroom_Server_Admin::update_option_key( 'log404', ! empty( $_POST['value'] ) );
		wp_send_json_success();
	}

	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_404_delete' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			Cmdroom_404_Table::delete( $id );
		}

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function handle_clear_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_404_clear_all' );

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Cmdroom_404_Table::table_name() );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * "Hace 2 h" / "Ayer" / "Hace 3 días" — formato relativo compacto que
	 * pide el handoff, sin depender de human_time_diff() de WP (que
	 * devuelve "hace 2 horas" en un formato más largo).
	 */
	private static function relative_time( $mysql_datetime ) {
		$then = strtotime( $mysql_datetime . ' UTC' );
		$now  = time();
		$diff = max( 0, $now - $then );

		if ( $diff < HOUR_IN_SECONDS ) {
			$mins = max( 1, round( $diff / MINUTE_IN_SECONDS ) );
			/* translators: %d: minutes */
			return sprintf( _n( 'Hace %d min', 'Hace %d min', $mins, 'command-room' ), $mins );
		}
		if ( $diff < DAY_IN_SECONDS ) {
			$hours = round( $diff / HOUR_IN_SECONDS );
			/* translators: %d: hours */
			return sprintf( _n( 'Hace %d h', 'Hace %d h', $hours, 'command-room' ), $hours );
		}
		$days = round( $diff / DAY_IN_SECONDS );
		if ( 1 === (int) $days ) {
			return __( 'Ayer', 'command-room' );
		}
		/* translators: %d: days */
		return sprintf( _n( 'Hace %d día', 'Hace %d días', $days, 'command-room' ), $days );
	}

	public static function render_tab() {
		$rows    = Cmdroom_404_Table::get_all( 200 );
		$logging = self::is_logging_enabled();
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cmdroom-servidor-404-header">
			<div class="cmdroom-servidor-404-toggle-row" data-cr-instant-toggle data-action-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'cmdroom_toggle_log404' ) ); ?>">
				<button type="button" class="cr-toggle<?php echo $logging ? ' is-on' : ''; ?>"><span class="cr-toggle-knob"></span><input type="checkbox" <?php checked( $logging ); ?> /></button>
				<span class="cmdroom-servidor-404-toggle-label"><?php esc_html_e( 'Registrar errores 404', 'command-room' ); ?></span>
				<span class="cmdroom-servidor-404-toggle-note">· <?php esc_html_e( 'se guardan los últimos 30 días', 'command-room' ); ?></span>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cr-confirm="<?php echo esc_attr__( '¿Vaciar todo el registro de 404?', 'command-room' ); ?>">
				<?php wp_nonce_field( 'cmdroom_404_clear_all' ); ?>
				<input type="hidden" name="action" value="cmdroom_404_clear_all" />
				<button type="submit" class="cr-btn-secondary"><?php esc_html_e( 'Vaciar registro', 'command-room' ); ?></button>
			</form>
		</div>

		<div class="<?php echo $logging ? '' : 'cmdroom-servidor-404-paused'; ?>">
			<?php if ( ! $logging ) : ?>
				<p class="cmdroom-servidor-404-paused-note"><?php esc_html_e( 'El registro está pausado.', 'command-room' ); ?></p>
			<?php endif; ?>

			<div class="cr-card cr-table-wrap">
				<table class="cr-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL solicitada', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Referente', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Visitas', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Última vez', 'command-room' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<tr class="cmdroom-servidor-empty"><td colspan="5"><?php esc_html_e( 'Sin 404s registrados todavía.', 'command-room' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $rows as $row ) :
								$referrer_host = $row['referrer'] ? wp_parse_url( $row['referrer'], PHP_URL_HOST ) : '';
								?>
								<tr>
									<td class="cmdroom-servidor-source"><?php echo esc_html( $row['request_path'] ); ?></td>
									<td class="cmdroom-servidor-404-referrer"><?php echo $referrer_host ? esc_html( $referrer_host ) : '—'; ?></td>
									<td><strong><?php echo esc_html( $row['hits'] ); ?></strong></td>
									<td class="cmdroom-servidor-404-lastseen"><?php echo esc_html( self::relative_time( $row['last_seen'] ) ); ?></td>
									<td class="cmdroom-servidor-actions-cell">
										<a
											class="cr-btn-secondary cr-btn-compact"
											href="<?php echo esc_url( add_query_arg( array( 'tab' => 'redirects', 'prefill_source' => rawurlencode( trim( $row['request_path'], '/' ) ) ), Cmdroom_Server_Admin::tab_url( 'redirects' ) ) ); ?>"
										><?php esc_html_e( 'Redirigir', 'command-room' ); ?></a>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
											<?php wp_nonce_field( 'cmdroom_404_delete' ); ?>
											<input type="hidden" name="action" value="cmdroom_404_delete" />
											<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
											<button type="submit" class="cr-btn-secondary cr-btn-compact"><?php esc_html_e( 'Descartar', 'command-room' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>

		<p class="cmdroom-servidor-404-note"><?php esc_html_e( '"Redirigir" abre una nueva redirección con la URL como origen. Al guardarla, la entrada sale del registro.', 'command-room' ); ?></p>
		<?php
	}
}
