<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "RSS" de la pantalla "Configuración" -- pedida por el administrador el
 * 2026-09-24 junto con Cmdroom_Googlenews_Feed (el feed RSS "estilo Google
 * News" por categoría, en /rss/googlenews/{categoría}.xml). Antes esos
 * valores estaban fijos en el código (límite de artículos hardcodeado en
 * Cmdroom_Googlenews_Feed::ITEMS_LIMIT, idioma tomado siempre de
 * get_bloginfo('language')) -- ahora son configurables aquí, mismo patrón de
 * guardado que Cmdroom_Tags_Settings (un único wp_option, admin_post +
 * nonce).
 *
 * El nombre de publicación de Google News NO se duplica aquí -- ya vive en
 * Sitemaps → Avanzado (Cmdroom_Sitemap_Settings::get_news_publication_name()),
 * y tanto el sitemap news:news como este feed RSS lo reutilizan tal cual.
 */
class Cmdroom_Rss_Settings {

	const OPTION = 'cmdroom_rss';

	const DEFAULTS = array(
		'enabled'     => true,
		'items_limit' => 20,
		'language'    => '', // vacío = idioma del sitio (get_bloginfo('language'))
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_rss', array( __CLASS__, 'handle_save' ) );
	}

	public static function get_options() {
		$opts = wp_parse_args( get_option( self::OPTION, array() ), self::DEFAULTS );
		$opts['enabled']     = (bool) $opts['enabled'];
		$opts['items_limit'] = max( 1, (int) $opts['items_limit'] );
		return $opts;
	}

	public static function is_enabled() {
		return self::get_options()['enabled'];
	}

	public static function get_items_limit() {
		return self::get_options()['items_limit'];
	}

	/**
	 * Idioma a usar en el <language> del feed -- el override guardado aquí,
	 * o si está vacío, el idioma del sitio (mismo fallback que tenía
	 * Cmdroom_Googlenews_Feed antes de existir esta pantalla).
	 */
	public static function get_language() {
		$language = self::get_options()['language'];
		return '' !== $language ? $language : get_bloginfo( 'language' );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_rss' );

		update_option( self::OPTION, array(
			'enabled'     => isset( $_POST['enabled'] ) ? 1 : 0,
			'items_limit' => isset( $_POST['items_limit'] ) ? max( 1, min( 1000, (int) $_POST['items_limit'] ) ) : self::DEFAULTS['items_limit'],
			'language'    => isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '',
		) );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function render_tab() {
		$opts = self::get_options();
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cr-card cmdroom-ia-block">
			<h2 class="cmdroom-ia-block-title"><?php esc_html_e( 'Feed RSS (Google News)', 'command-room' ); ?></h2>
			<p class="cmdroom-config-rule-desc">
				<?php esc_html_e( 'Feed RSS 2.0 propio por categoría, en /rss/googlenews/{categoría}.xml -- mismo formato que usan los grandes medios. Sustituye al <link> de feed que WordPress enlaza de forma nativa en cada entrada (feed de comentarios) y archivo de categoría (feed genérico /categoria/feed/).', 'command-room' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdroom_save_rss' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_rss" />

				<div class="cmdroom-config-grid">
					<div class="cmdroom-ia-field">
						<label class="cr-label">
							<input type="checkbox" name="enabled" value="1" <?php checked( $opts['enabled'] ); ?> />
							<?php esc_html_e( 'Activar', 'command-room' ); ?>
						</label>
						<p class="cmdroom-config-note"><?php esc_html_e( 'Si se desactiva: el <link> del <head> vuelve al feed nativo de WordPress y las URLs /rss/googlenews/*.xml dejan de responder (404).', 'command-room' ); ?></p>
					</div>

					<div class="cmdroom-ia-field">
						<label class="cr-label" for="cmdroom-rss-limit"><?php esc_html_e( 'Artículos por feed', 'command-room' ); ?></label>
						<input type="number" id="cmdroom-rss-limit" class="cr-input" name="items_limit" min="1" max="1000" value="<?php echo esc_attr( $opts['items_limit'] ); ?>" style="max-width:110px;" />
					</div>

					<div class="cmdroom-ia-field">
						<label class="cr-label" for="cmdroom-rss-language"><?php esc_html_e( 'Idioma del feed', 'command-room' ); ?></label>
						<input type="text" id="cmdroom-rss-language" class="cr-input" name="language" value="<?php echo esc_attr( $opts['language'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>" style="max-width:160px;" />
						<p class="cmdroom-config-note"><?php esc_html_e( 'Vacío = usa el idioma del sitio (Ajustes → General).', 'command-room' ); ?></p>
					</div>
				</div>

				<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-primary' ); ?>
			</form>

			<p class="cmdroom-config-note">
				<?php
				printf(
					wp_kses(
						/* translators: %s: enlace a Sitemaps → Avanzado */
						__( 'El nombre de publicación de Google News (usado también en este feed) se configura en %s.', 'command-room' ),
						array( 'a' => array( 'href' => array() ) )
					),
					'<a href="' . esc_url( admin_url( 'admin.php?page=cmdroom-sitemaps&tab=avanzado' ) ) . '">' . esc_html__( 'Sitemaps → Avanzado', 'command-room' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
