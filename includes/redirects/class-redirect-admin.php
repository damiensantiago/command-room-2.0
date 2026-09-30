<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administración de redirecciones — pestaña "Redirecciones" de la pantalla
 * "Servidor" (antes página propia). Desde el rediseño Claude Design
 * (2026-09-23) el alta/edición es un diálogo de una redirección a la vez en
 * vez de la tabla-formulario de filas en blanco que usan Metas/Sitemaps —
 * así lo pide el handoff, y de paso se acerca al patrón habitual de un
 * gestor de redirecciones (Redirection, Rank Math).
 *
 * Sigue soportando lo que ya tenía antes del rediseño y que el mockup no
 * cubre pero que no hay que perder: origen por regex además de exacto,
 * 410/451 (Gone / no disponible), herramienta de prueba de ruta e historial
 * de los últimos disparos.
 */
class Cmdroom_Redirect_Admin {

	const OPTION = 'cmdroom_redirect_options';
	const HTTP_TYPES = array( 301, 302, 307, 410, 451 );

	public static function init() {
		add_action( 'admin_post_cmdroom_save_redirect', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cmdroom_delete_redirect', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_cmdroom_save_redirects_general', array( __CLASS__, 'handle_save_general' ) );
	}

	public static function is_live_output_enabled() {
		$opts = get_option( self::OPTION, array() );
		return ! empty( $opts['live_output'] );
	}

	public static function handle_save_general() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_redirects_general' );

		update_option( self::OPTION, array( 'live_output' => ! empty( $_POST['live_output'] ) ) );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * "Salida en el sitio" vive en Configuración → Herramientas (ver
	 * Cmdroom_Config_Admin::render_salida_tab()), mismo criterio que
	 * Metas/Datos estructurados/Sitemaps — la pantalla propia del módulo
	 * ya no la incluye.
	 */
	public static function render_general_section() {
		$live = self::is_live_output_enabled();
		?>
		<h2><?php esc_html_e( 'Redirecciones (Servidor)', 'command-room' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmdroom_save_redirects_general' ); ?>
			<input type="hidden" name="action" value="cmdroom_save_redirects_general" />
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Salida en el sitio', 'command-room' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="live_output" value="1" <?php checked( $live ); ?> />
							<?php esc_html_e( 'Aplicar estas redirecciones de verdad (déjalo apagado mientras compares contra el gestor de Rank Math)', 'command-room' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Guardar', 'command-room' ) ); ?>
		</form>
		<?php
	}

	private static function normalize_source( $source ) {
		$source = sanitize_text_field( $source );
		// Si pegan la URL completa del propio dominio, se queda solo con la
		// ruta -- evita redirecciones "fantasma" que nunca casan porque
		// llevan el host delante.
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$parsed    = wp_parse_url( $source );
		if ( ! empty( $parsed['host'] ) && $home_host && strtolower( $parsed['host'] ) === strtolower( $home_host ) ) {
			$source = isset( $parsed['path'] ) ? $parsed['path'] : '';
		}
		return trim( strtolower( $source ), '/' );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_redirect' );

		$id            = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$source        = isset( $_POST['source'] ) ? self::normalize_source( wp_unslash( $_POST['source'] ) ) : '';
		$source_type   = ( isset( $_POST['source_type'] ) && 'regex' === $_POST['source_type'] ) ? 'regex' : 'exact';
		$redirect_type = isset( $_POST['redirect_type'] ) ? absint( $_POST['redirect_type'] ) : 301;
		if ( ! in_array( $redirect_type, self::HTTP_TYPES, true ) ) {
			$redirect_type = 301;
		}
		$is_gone = in_array( $redirect_type, array( 410, 451 ), true );

		$errors = array();
		if ( '' === $source ) {
			$errors[] = __( 'La URL de origen no puede estar vacía.', 'command-room' );
		}
		// "Exacto": no puede empezar por "/" tras normalizar (ya se ha
		// recortado), y en regex se admite cualquier patrón salvo vacío.
		if ( '' !== $source && 'exact' === $source_type ) {
			$existing = Cmdroom_Redirect_Table::get_by_source( $source );
			if ( $existing && (int) $existing['id'] !== $id ) {
				$errors[] = __( 'Ya existe una redirección exacta con ese origen.', 'command-room' );
			}
		}

		$destination = '';
		if ( ! $is_gone ) {
			$destination = isset( $_POST['destination'] ) ? esc_url_raw( wp_unslash( $_POST['destination'] ) ) : '';
			if ( '' === $destination ) {
				$errors[] = __( 'La URL de destino no puede estar vacía (salvo en 410/451).', 'command-room' );
			}
			$dest_path = self::normalize_source( wp_parse_url( $destination, PHP_URL_PATH ) ? wp_parse_url( $destination, PHP_URL_PATH ) : $destination );
			if ( '' !== $source && $dest_path === $source ) {
				$errors[] = __( 'El destino no puede ser igual que el origen.', 'command-room' );
			}
		} else {
			$destination = '—';
		}

		if ( ! empty( $errors ) ) {
			set_transient( 'cmdroom_redirect_errors', $errors, MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'cmdroom_redirect_invalid', '1', wp_get_referer() ) );
			exit;
		}

		$data = array(
			'source'        => $source,
			'source_type'   => $source_type,
			'destination'   => $destination,
			'redirect_type' => $redirect_type,
			'status'        => ! empty( $_POST['enabled'] ) ? 1 : 0,
		);

		if ( $id ) {
			Cmdroom_Redirect_Table::update( $id, $data );
		} else {
			Cmdroom_Redirect_Table::insert( $data );
		}

		// Si esta URL estaba registrada como 404, ya tiene destino: sale del
		// registro (mismo comportamiento tanto si la redirección nace del
		// botón "Redirigir" de Monitor 404 como si se ha creado a mano con
		// ese mismo origen).
		Cmdroom_404_Table::delete_by_path( '/' . $source );

		delete_transient( 'cmdroom_redirect_errors' );
		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_delete_redirect' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			Cmdroom_Redirect_Table::delete( $id );
		}

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	private static function pill_class( $type ) {
		return 301 === (int) $type ? 'cr-pill-301' : 'cr-pill-other';
	}

	public static function render_tab() {
		$redirects = Cmdroom_Redirect_Table::get_all();
		$errors    = get_transient( 'cmdroom_redirect_errors' );
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php elseif ( isset( $_GET['cmdroom_redirect_invalid'] ) && $errors ) : ?>
			<div class="notice notice-error">
				<p><strong><?php esc_html_e( 'No se ha guardado:', 'command-room' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:1.5em;">
					<?php foreach ( $errors as $err ) : ?>
						<li><?php echo esc_html( $err ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<div class="cmdroom-servidor-toolbar">
			<button type="button" class="cr-btn-primary" data-cr-open-dialog><?php esc_html_e( 'Nueva redirección', 'command-room' ); ?></button>
		</div>

		<div class="cr-card cr-table-wrap">
			<table class="cr-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Origen', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Destino', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Tipo', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Visitas', 'command-room' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $redirects ) ) : ?>
						<tr class="cmdroom-servidor-empty"><td colspan="5"><?php esc_html_e( 'Aún no hay redirecciones.', 'command-room' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $redirects as $r ) : ?>
							<tr>
								<td class="cmdroom-servidor-source">/<?php echo esc_html( $r['source'] ); ?><?php echo 'regex' === $r['source_type'] ? ' <span class="cr-chip">regex</span>' : ''; ?><?php echo empty( $r['status'] ) ? ' <span class="cr-chip">' . esc_html__( 'pausada', 'command-room' ) . '</span>' : ''; ?></td>
								<td class="cmdroom-servidor-dest"><?php echo esc_html( $r['destination'] ); ?></td>
								<td><span class="cr-pill <?php echo esc_attr( self::pill_class( $r['redirect_type'] ) ); ?>"><?php echo esc_html( $r['redirect_type'] ); ?></span></td>
								<td class="cmdroom-servidor-hits"><?php echo esc_html( $r['hits'] ); ?></td>
								<td class="cmdroom-servidor-actions-cell">
									<button
										type="button"
										class="cr-btn-secondary cr-btn-compact"
										data-cr-open-dialog
										data-id="<?php echo (int) $r['id']; ?>"
										data-source="<?php echo esc_attr( $r['source'] ); ?>"
										data-destination="<?php echo esc_attr( '—' === $r['destination'] ? '' : $r['destination'] ); ?>"
										data-source-type="<?php echo esc_attr( $r['source_type'] ); ?>"
										data-http-type="<?php echo esc_attr( $r['redirect_type'] ); ?>"
										data-enabled="<?php echo (int) $r['status']; ?>"
									><?php esc_html_e( 'Editar', 'command-room' ); ?></button>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" data-cr-confirm="<?php echo esc_attr__( '¿Eliminar esta redirección?', 'command-room' ); ?>">
										<?php wp_nonce_field( 'cmdroom_delete_redirect' ); ?>
										<input type="hidden" name="action" value="cmdroom_delete_redirect" />
										<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
										<button type="submit" class="cr-btn-secondary cr-btn-compact"><?php esc_html_e( 'Eliminar', 'command-room' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

		<?php self::render_dialog(); ?>

		<div class="cmdroom-servidor-extra">
			<h3><?php esc_html_e( 'Probar una ruta', 'command-room' ); ?></h3>
			<form method="get">
				<input type="hidden" name="page" value="cmdroom-servidor" />
				<input type="hidden" name="tab" value="redirects" />
				<label for="cmdroom_test_path" class="cr-label"><?php esc_html_e( 'Ruta (sin dominio)', 'command-room' ); ?></label>
				<input type="text" id="cmdroom_test_path" name="cmdroom_test_path" class="cr-input" style="max-width:320px;" value="<?php echo isset( $_GET['cmdroom_test_path'] ) ? esc_attr( wp_unslash( $_GET['cmdroom_test_path'] ) ) : ''; ?>" placeholder="ecografia" />
				<?php submit_button( __( 'Probar', 'command-room' ), 'cr-btn-secondary', '', false ); ?>
			</form>
			<?php if ( ! empty( $_GET['cmdroom_test_path'] ) ) :
				$path  = trim( sanitize_text_field( wp_unslash( $_GET['cmdroom_test_path'] ) ), '/' );
				$match = Cmdroom_Redirect_Table::get_by_source( $path );
				if ( ! $match ) {
					foreach ( Cmdroom_Redirect_Table::get_active_regex_rules() as $rule ) {
						if ( @preg_match( '#' . $rule['source'] . '#i', $path ) ) {
							$match = $rule;
							break;
						}
					}
				}
				?>
				<?php if ( $match ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: HTTP code, 2: destination URL */
							esc_html__( '%1$d → %2$s', 'command-room' ),
							(int) $match['redirect_type'],
							esc_html( $match['destination'] )
						);
						?>
					</p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Sin coincidencia: esa ruta no redirige a ningún sitio.', 'command-room' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<h3 style="margin-top:28px;"><?php esc_html_e( 'Historial reciente', 'command-room' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Últimos 20 disparos, con la ruta exacta solicitada y el código HTTP servido en ese momento — útil para reglas regex, donde una misma fila responde a rutas distintas.', 'command-room' ); ?></p>
			<?php $recent = Cmdroom_Redirect_Table::get_recent_hits( 20 ); ?>
			<?php if ( $recent ) : ?>
				<div class="cr-card cr-table-wrap">
					<table class="cr-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Fecha', 'command-room' ); ?></th>
								<th><?php esc_html_e( 'Ruta solicitada', 'command-room' ); ?></th>
								<th><?php esc_html_e( 'Regla (origen)', 'command-room' ); ?></th>
								<th><?php esc_html_e( 'HTTP', 'command-room' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent as $hit ) : ?>
								<tr>
									<td><?php echo esc_html( $hit['hit_at'] ); ?></td>
									<td><code><?php echo esc_html( $hit['requested_path'] ); ?></code></td>
									<td><code><?php echo esc_html( $hit['source'] ?? '—' ); ?></code></td>
									<td><?php echo esc_html( $hit['status_code'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Todavía no se ha disparado ninguna redirección.', 'command-room' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_dialog() {
		?>
		<div class="cr-dialog-backdrop">
			<div class="cr-dialog" role="dialog" aria-modal="true">
				<h2 class="cr-dialog-title"><?php esc_html_e( 'Nueva redirección', 'command-room' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'cmdroom_save_redirect' ); ?>
					<input type="hidden" name="action" value="cmdroom_save_redirect" />
					<input type="hidden" name="id" value="" />

					<div class="cr-dialog-field">
						<label class="cr-label" for="cmdroom-redirect-source"><?php esc_html_e( 'URL de origen', 'command-room' ); ?></label>
						<input type="text" id="cmdroom-redirect-source" name="source" class="cr-input" placeholder="/pagina-antigua" />
					</div>

					<div class="cr-dialog-field">
						<label class="cr-label" for="cmdroom-redirect-destination"><?php esc_html_e( 'URL de destino', 'command-room' ); ?></label>
						<input type="text" id="cmdroom-redirect-destination" name="destination" class="cr-input" placeholder="/pagina-nueva" />
					</div>

					<div class="cr-dialog-field">
						<span class="cr-label"><?php esc_html_e( 'Tipo de origen', 'command-room' ); ?></span>
						<div class="cr-seg cr-seg-source-type">
							<button type="button" class="cr-seg-opt is-active" data-value="exact"><?php esc_html_e( 'Exacto', 'command-room' ); ?></button>
							<button type="button" class="cr-seg-opt" data-value="regex"><?php esc_html_e( 'Regex', 'command-room' ); ?></button>
						</div>
						<input type="hidden" name="source_type" class="cr-seg-value" value="exact" />

						<details class="cr-dialog-help">
							<summary><?php esc_html_e( '¿Cómo funciona Regex?', 'command-room' ); ?></summary>
							<p><?php esc_html_e( '"Exacto" redirige una URL concreta a otra. "Regex" usa un patrón para mover una carpeta entera a otra conservando el resto de la ruta, sin crear una regla por cada página:', 'command-room' ); ?></p>
							<p>
								<?php esc_html_e( 'Origen:', 'command-room' ); ?> <code>^carpeta-vieja/(.*)$</code><br />
								<?php esc_html_e( 'Destino:', 'command-room' ); ?> <code>/carpeta-nueva/$1</code>
							</p>
							<p><?php esc_html_e( 'Con esa regla, /carpeta-vieja/pagina-x pasa a /carpeta-nueva/pagina-x automáticamente — el $1 recupera lo que capturaron los paréntesis del origen.', 'command-room' ); ?></p>
						</details>
					</div>

					<div class="cr-dialog-field">
						<span class="cr-label"><?php esc_html_e( 'Tipo', 'command-room' ); ?></span>
						<div class="cr-seg cr-seg-http-type">
							<?php foreach ( self::HTTP_TYPES as $i => $code ) : ?>
								<button type="button" class="cr-seg-opt<?php echo 0 === $i ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $code ); ?></button>
							<?php endforeach; ?>
						</div>
						<input type="hidden" name="redirect_type" class="cr-seg-value" value="301" />
					</div>

					<div class="cr-dialog-field">
						<label>
							<input type="checkbox" name="enabled" value="1" checked />
							<?php esc_html_e( 'Activa', 'command-room' ); ?>
						</label>
					</div>

					<div class="cr-dialog-actions">
						<button type="button" class="cr-btn-secondary" data-cr-close-dialog><?php esc_html_e( 'Cancelar', 'command-room' ); ?></button>
						<button type="submit" class="cr-btn-primary"><?php esc_html_e( 'Guardar', 'command-room' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}
