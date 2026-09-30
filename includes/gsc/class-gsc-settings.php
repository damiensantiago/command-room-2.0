<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conexión OAuth con Google Search Console. Pedido por el administrador 2026-09-23:
 * la configuración/conexión vive en "Configuración → Herramientas"
 * (render_tab(), llamada desde Cmdroom_Tools_Admin); los datos en sí
 * (evolución de tráfico, URLs top/con potencial) se muestran en "General"
 * vía Cmdroom_Gsc_Dashboard, que pide los datos a la API a través de
 * Cmdroom_Gsc_Client.
 *
 * Simplificado 2026-09-23 a petición del administrador ("no puede ser un login con
 * Google?"): un único cliente OAuth "Aplicación web" compartido (un
 * proyecto propio en Google Cloud), definido como constante SOLO en el
 * wp-config.php de cada sitio -- nunca en el código del
 * plugin, que se comparte por GitHub y puede acabar instalado en sitios de
 * clientes o de terceros -- en vez de pedir Client ID/Secret por sitio.
 * Cada instalación solo guarda
 * su refresh_token y la propiedad elegida -- "Conectar con Google" es un
 * único botón, sin formulario de credenciales. Lo único que sigue siendo
 * manual por sitio es añadir su redirect_uri() a la lista de orígenes
 * autorizados del cliente compartido en Google Cloud Console (Google no
 * permite saltarse esto).
 *
 * Nota sobre caducidad: si el cliente OAuth en Google Cloud sigue en modo
 * "Testing" (no publicado), Google revoca el refresh_token a los 7 días
 * SIN USO. keepalive() hace un ping diario por
 * wp_cron para que la conexión no muera sola aunque nadie abra "General"
 * en una semana.
 */
class Cmdroom_Gsc_Settings {

	const OPTION   = 'cmdroom_gsc';
	const SCOPE     = 'https://www.googleapis.com/auth/webmasters.readonly';
	const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	const DEFAULTS = array(
		'refresh_token' => '',
		'site_url'      => '',
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_gsc_connect', array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_cmdroom_gsc_callback', array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_cmdroom_gsc_save_site', array( __CLASS__, 'handle_save_site' ) );
		add_action( 'admin_post_cmdroom_gsc_disconnect', array( __CLASS__, 'handle_disconnect' ) );

		add_action( 'cmdroom_gsc_keepalive', array( __CLASS__, 'keepalive' ) );
		if ( ! wp_next_scheduled( 'cmdroom_gsc_keepalive' ) ) {
			wp_schedule_event( time(), 'daily', 'cmdroom_gsc_keepalive' );
		}
	}

	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::DEFAULTS );
	}

	/**
	 * true solo si el cliente compartido está definido -- sin esto no tiene
	 * sentido mostrar el botón "Conectar con Google" a nadie.
	 */
	public static function has_shared_client() {
		return defined( 'CMDROOM_GSC_CLIENT_ID' ) && CMDROOM_GSC_CLIENT_ID
			&& defined( 'CMDROOM_GSC_CLIENT_SECRET' ) && CMDROOM_GSC_CLIENT_SECRET;
	}

	public static function is_connected() {
		$opts = self::get_options();
		return ! empty( $opts['refresh_token'] ) && ! empty( $opts['site_url'] );
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=cmdroom_gsc_callback' );
	}

	public static function keepalive() {
		if ( self::is_connected() ) {
			Cmdroom_Gsc_Client::get_access_token();
		}
	}

	public static function handle_connect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_gsc_connect' );

		if ( ! self::has_shared_client() ) {
			wp_die( esc_html__( 'Falta configurar CMDROOM_GSC_CLIENT_ID / CMDROOM_GSC_CLIENT_SECRET en el wp-config.php de este sitio.', 'command-room' ) );
		}

		$state = wp_generate_password( 20, false );
		set_transient( 'cmdroom_gsc_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( CMDROOM_GSC_CLIENT_ID ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'response_type' => 'code',
				'scope'         => rawurlencode( self::SCOPE ),
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			),
			self::AUTH_URL
		);

		wp_redirect( $url ); // phpcs:ignore -- destino externo (Google), no interno.
		exit;
	}

	public static function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}

		$state       = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$saved_state = get_transient( 'cmdroom_gsc_oauth_state' );
		delete_transient( 'cmdroom_gsc_oauth_state' );

		if ( empty( $state ) || ! $saved_state || ! hash_equals( (string) $saved_state, $state ) ) {
			wp_die( esc_html__( 'Estado OAuth inválido -- vuelve a intentar la conexión desde Herramientas.', 'command-room' ) );
		}

		if ( ! empty( $_GET['error'] ) ) {
			wp_safe_redirect( add_query_arg( 'cmdroom_gsc_error', rawurlencode( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ), Cmdroom_Config_Admin::tab_url( 'tools' ) ) );
			exit;
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( ! $code ) {
			wp_die( esc_html__( 'Google no devolvió un código de autorización.', 'command-room' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => array(
					'code'          => $code,
					'client_id'     => CMDROOM_GSC_CLIENT_ID,
					'client_secret' => CMDROOM_GSC_CLIENT_SECRET,
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( add_query_arg( 'cmdroom_gsc_error', rawurlencode( $response->get_error_message() ), Cmdroom_Config_Admin::tab_url( 'tools' ) ) );
			exit;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['refresh_token'] ) ) {
			// Google solo manda refresh_token la primera vez que el usuario
			// concede acceso a esta app -- access_type=offline + prompt=consent
			// fuerza que llegue casi siempre, pero si ya se había conectado
			// antes y Google decide no repetirlo, hay que revocar el acceso en
			// myaccount.google.com/permissions y reconectar.
			wp_safe_redirect( add_query_arg( 'cmdroom_gsc_error', 'no_refresh_token', Cmdroom_Config_Admin::tab_url( 'tools' ) ) );
			exit;
		}

		$opts                  = self::get_options();
		$opts['refresh_token'] = $body['refresh_token'];
		update_option( self::OPTION, $opts );
		delete_transient( 'cmdroom_gsc_access_token' );

		wp_safe_redirect( add_query_arg( 'cmdroom_gsc_connected', '1', Cmdroom_Config_Admin::tab_url( 'tools' ) ) );
		exit;
	}

	public static function handle_save_site() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_gsc_save_site' );

		$opts             = self::get_options();
		$opts['site_url'] = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		update_option( self::OPTION, $opts );

		delete_transient( 'cmdroom_gsc_evolution' );
		delete_transient( 'cmdroom_gsc_pages' );

		wp_safe_redirect( add_query_arg( 'cmdroom_gsc_site_saved', '1', Cmdroom_Config_Admin::tab_url( 'tools' ) ) );
		exit;
	}

	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_gsc_disconnect' );

		$opts                  = self::get_options();
		$opts['refresh_token'] = '';
		$opts['site_url']      = '';
		update_option( self::OPTION, $opts );

		delete_transient( 'cmdroom_gsc_access_token' );
		delete_transient( 'cmdroom_gsc_evolution' );
		delete_transient( 'cmdroom_gsc_pages' );

		wp_safe_redirect( add_query_arg( 'cmdroom_gsc_disconnected', '1', Cmdroom_Config_Admin::tab_url( 'tools' ) ) );
		exit;
	}

	/**
	 * Tarjeta de "Herramientas". Sin envoltorio .cr-card propio -- el
	 * llamante (Cmdroom_Tools_Admin::render_tab()) la mete dentro de un
	 * .cmdroom-config-extra, igual que la vista previa por ID.
	 */
	public static function render_tab() {
		$opts      = self::get_options();
		$connected = self::is_connected();
		$has_client = self::has_shared_client();
		?>
		<p class="description"><?php esc_html_e( 'Analiza tu tráfico conectando tu cuenta de Google Search Console', 'command-room' ); ?></p>

		<?php if ( isset( $_GET['cmdroom_gsc_connected'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Conectado. Elige abajo qué propiedad de Search Console corresponde a este sitio.', 'command-room' ); ?></p></div>
		<?php elseif ( isset( $_GET['cmdroom_gsc_error'] ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( sprintf( __( 'Error de conexión: %s', 'command-room' ), sanitize_text_field( wp_unslash( $_GET['cmdroom_gsc_error'] ) ) ) ); ?></p></div>
		<?php elseif ( isset( $_GET['cmdroom_gsc_disconnected'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Desconectado.', 'command-room' ); ?></p></div>
		<?php elseif ( isset( $_GET['cmdroom_gsc_site_saved'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Propiedad guardada.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! $has_client ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php esc_html_e( 'Cliente OAuth compartido no configurado todavía -- añade CMDROOM_GSC_CLIENT_ID / CMDROOM_GSC_CLIENT_SECRET en el wp-config.php de este sitio. Redirect URI a registrar para este sitio:', 'command-room' ); ?>
					<code><?php echo esc_html( self::redirect_uri() ); ?></code>
				</p>
			</div>
		<?php endif; ?>

		<div class="cmdroom-config-tools-action-row">
			<?php if ( ! $connected ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'cmdroom_gsc_connect' ); ?>
					<input type="hidden" name="action" value="cmdroom_gsc_connect" />
					<?php submit_button( __( 'Conectar con Google', 'command-room' ), 'cr-btn-primary', 'submit', false, $has_client ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
				<?php if ( $has_client ) : ?>
					<span class="cmdroom-config-tools-status">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: redirect URI */
								__( 'Si esta es la primera vez que conectas este sitio, añade antes %s como redirect URI autorizado del cliente compartido.', 'command-room' ),
								self::redirect_uri()
							)
						);
						?>
					</span>
				<?php endif; ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'cmdroom_gsc_disconnect' ); ?>
					<input type="hidden" name="action" value="cmdroom_gsc_disconnect" />
					<?php submit_button( __( 'Desconectar', 'command-room' ), 'cr-btn-secondary', 'submit', false ); ?>
				</form>
				<span class="cmdroom-config-tools-status"><?php esc_html_e( 'Conectado.', 'command-room' ); ?></span>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $opts['refresh_token'] ) ) : self::render_site_picker( $opts ); endif; ?>
		<?php
	}

	private static function render_site_picker( $opts ) {
		$sites = Cmdroom_Gsc_Client::list_sites();
		?>
		<table class="form-table" style="margin-top:8px;">
			<tr>
				<th><label for="cmdroom_gsc_site_url"><?php esc_html_e( 'Propiedad de Search Console', 'command-room' ); ?></label></th>
				<td>
					<?php if ( is_wp_error( $sites ) ) : ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'No se pudo listar propiedades: %s', 'command-room' ), $sites->get_error_message() ) ); ?></p>
					<?php elseif ( empty( $sites ) ) : ?>
						<p class="description"><?php esc_html_e( 'Esa cuenta de Google no tiene ninguna propiedad en Search Console.', 'command-room' ); ?></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'cmdroom_gsc_save_site' ); ?>
							<input type="hidden" name="action" value="cmdroom_gsc_save_site" />
							<select name="site_url" id="cmdroom_gsc_site_url" class="cr-input">
								<option value=""><?php esc_html_e( '-- Elegir --', 'command-room' ); ?></option>
								<?php foreach ( $sites as $site ) : ?>
									<option value="<?php echo esc_attr( $site['siteUrl'] ); ?>" <?php selected( $opts['site_url'], $site['siteUrl'] ); ?>><?php echo esc_html( $site['siteUrl'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php submit_button( __( 'Guardar propiedad', 'command-room' ), 'cr-btn-secondary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}
}
