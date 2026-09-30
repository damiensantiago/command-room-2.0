<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "Limpieza HTTP/permalinks" de la pantalla "Servidor". Cada regla
 * es un toggle independiente; la ejecución real vive en Cmdroom_Cleanup.
 *
 * Desde el rediseño "Servidor" (2026-09-23) los ajustes viven en la opción
 * compartida `cmdroom_servidor` (clave 'clean'), junto a los de Monitor 404
 * — ver Cmdroom_Server_Admin::get_option_value()/update_option_key(). Se
 * añaden 5 reglas nuevas que pedía el handoff (https, www, catbase, attach,
 * utm) y se conservan las 3 que ya había y que el mockup no cubre
 * (remove_x_pingback, remove_generator, remove_wp_version_strings) como
 * filas extra al final, para no perder ese hardening ya construido.
 */
class Cmdroom_Cleanup_Settings {

	/**
	 * Orden y contenido de cada fila. 'chip' es el ejemplo de la derecha;
	 * 'default' es el valor de fábrica. 'www' no tiene título fijo -- se
	 * calcula en runtime según el host de home_url() (ver render_tab()).
	 */
	const RULES = array(
		'https'                     => array(
			'title'   => 'Forzar HTTPS',
			'desc'    => 'Redirige con 301 cualquier petición http:// a su versión https://.',
			'chip'    => 'http:// → https://',
			'default' => true,
		),
		'www'                       => array(
			'title'   => 'Dominio canónico sin www',
			'desc'    => 'Unifica www y sin www en el dominio configurado en Ajustes → Generales.',
			'chip'    => 'www. → ∅',
			'default' => false,
		),
		'slash'                     => array(
			'title'   => 'Barra final en permalinks',
			'desc'    => 'Añade o quita la barra final según la estructura de enlaces permanentes.',
			'chip'    => '/post → /post/',
			'default' => true,
		),
		'catbase'                   => array(
			'title'   => 'Quitar /category/ de la URL',
			'desc'    => 'Las categorías cuelgan de la raíz. Las URLs antiguas redirigen con 301.',
			'chip'    => '/category/x → /x',
			'default' => false,
		),
		'authorfront'               => array(
			'title'   => 'Autores en /author/',
			'desc'    => 'Con enlaces permanentes con prefijo (p. ej. /blog/%postname%/) WordPress cuelga los autores de /blog/author/x. Esto los deja en /author/x, como Rank Math.',
			'chip'    => '/blog/author/x → /author/x',
			'default' => false,
		),
		'attach'                    => array(
			'title'   => 'Redirigir páginas de adjunto',
			'desc'    => 'Las páginas de adjunto de WordPress redirigen al archivo o a la entrada padre.',
			'chip'    => '?attachment_id',
			'default' => true,
		),
		'utm'                       => array(
			'title'   => 'Ignorar parámetros de campaña',
			'desc'    => 'utm_*, fbclid y gclid no generan URLs nuevas: la canónica se imprime sin ellos.',
			'chip'    => '?utm_source=',
			'default' => true,
		),
		'strip_replytocom'          => array(
			'title'   => 'Eliminar ?replytocom',
			'desc'    => 'Evita que cada respuesta a un comentario cree una URL indexable.',
			'chip'    => '?replytocom=',
			'default' => true,
		),
		// Reglas heredadas del módulo suelto anterior — no estaban en el
		// mockup, se mantienen como filas extra para no perder hardening ya
		// construido y probado.
		'remove_x_pingback'         => array(
			'title'   => 'Cabecera X-Pingback',
			'desc'    => 'Quita la cabecera HTTP X-Pingback y el enlace <link rel="pingback"> — XML-RPC ya no se usa y solo sirve de huella para escaneos automatizados.',
			'chip'    => 'X-Pingback',
			'default' => true,
		),
		'remove_generator'          => array(
			'title'   => 'Meta generator',
			'desc'    => 'Quita <meta name="generator"> (revela la versión de WordPress en el HTML).',
			'chip'    => '<meta name=generator>',
			'default' => true,
		),
		'remove_wp_version_strings' => array(
			'title'   => 'Versión de WP en assets',
			'desc'    => 'Quita el ?ver=X.Y de scripts y estilos propios de WordPress core (no toca los de plugins/tema).',
			'chip'    => '?ver=X.Y',
			'default' => true,
		),
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_cleanup', array( __CLASS__, 'handle_save' ) );
	}

	public static function get_options() {
		$saved = Cmdroom_Server_Admin::get_option_value( 'clean', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$defaults = array();
		foreach ( self::RULES as $key => $rule ) {
			$defaults[ $key ] = $rule['default'];
		}
		return wp_parse_args( $saved, $defaults );
	}

	public static function is_enabled( $rule ) {
		$opts = self::get_options();
		return ! empty( $opts[ $rule ] );
	}

	/**
	 * Dirección de la barra final según la estructura de enlaces
	 * permanentes del sitio: si termina en "/", se añade; si no, se quita.
	 * Sustituye al antiguo modo manual OFF/STRIP/ADD.
	 */
	public static function wants_trailing_slash() {
		return '/' === substr( (string) get_option( 'permalink_structure' ), -1 );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_cleanup' );

		$previous = self::get_options();
		$opts     = array();
		foreach ( self::RULES as $key => $rule ) {
			$opts[ $key ] = ! empty( $_POST[ $key ] );
		}

		Cmdroom_Server_Admin::update_option_key( 'clean', $opts );

		// "Quitar /category/" toca las reglas de reescritura reales del
		// sitio (ver Cmdroom_Cleanup::maybe_register_catbase_rewrite()) --
		// solo se hace flush si de verdad cambió, un flush innecesario es
		// una operación cara en sitios con muchos posts/términos.
		if ( ! empty( $previous['catbase'] ) !== ! empty( $opts['catbase'] )
			|| ! empty( $previous['authorfront'] ) !== ! empty( $opts['authorfront'] ) ) {
			delete_option( 'category_base' );
			flush_rewrite_rules();
		}

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function render_tab() {
		$opts = self::get_options();
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$home_has_www = $home_host && 0 === stripos( $home_host, 'www.' );
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmdroom_save_cleanup' ); ?>
			<input type="hidden" name="action" value="cmdroom_save_cleanup" />

			<div class="cr-card cmdroom-servidor-clean-list">
				<?php foreach ( self::RULES as $key => $rule ) :
					$title = 'www' === $key && $home_has_www
						? __( 'Dominio canónico con www', 'command-room' )
						: $rule['title'];
					$on = ! empty( $opts[ $key ] );
					?>
					<div class="cmdroom-servidor-clean-row">
						<button type="button" class="cr-toggle<?php echo $on ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $on ); ?> /></button>
						<div class="cmdroom-servidor-clean-body">
							<p class="cmdroom-servidor-clean-title"><?php echo esc_html( $title ); ?></p>
							<p class="cmdroom-servidor-clean-desc"><?php echo esc_html( $rule['desc'] ); ?></p>
						</div>
						<span class="cr-chip cmdroom-servidor-clean-chip"><?php echo esc_html( $rule['chip'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
		</form>
		<?php
	}
}
