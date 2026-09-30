<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "IA" de la pantalla "Configuración" — dos bloques nuevos pedidos
 * por el administrador el 2026-09-23: /llms.txt (el archivo de contexto estilo
 * "robots.txt para LLMs", spec en llmstxt.org) y una versión .md de cada
 * entrada/página para que los crawlers de IA lean markdown limpio en vez
 * de tener que parsear el HTML del tema.
 *
 * Esta clase solo guarda los ajustes y pinta la pestaña. La salida real
 * (servir /llms.txt y las URLs *.md) vive en Cmdroom_Ia_Output — mismo
 * reparto de responsabilidades que Ticker (Settings guarda, Output sirve).
 */
class Cmdroom_Ia_Settings {

	const OPTION = 'cmdroom_ia';

	const DEFAULTS = array(
		'llms' => array(
			'enabled'        => false,
			'intro'          => '',
			'body'           => '',
			'include_pages'  => true,
			'include_posts'  => true,
			'posts_count'    => 20,
		),
		'markdown' => array(
			'enabled'      => false,
			'post_types'   => array( 'post', 'page' ),
			'link_tag'     => true,
		),
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_ia', array( __CLASS__, 'handle_save' ) );
	}

	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		return array(
			'llms'     => array_merge( self::DEFAULTS['llms'], isset( $saved['llms'] ) && is_array( $saved['llms'] ) ? $saved['llms'] : array() ),
			'markdown' => array_merge( self::DEFAULTS['markdown'], isset( $saved['markdown'] ) && is_array( $saved['markdown'] ) ? $saved['markdown'] : array() ),
		);
	}

	public static function is_llms_enabled() {
		$opts = self::get_options();
		return ! empty( $opts['llms']['enabled'] );
	}

	public static function is_markdown_enabled() {
		$opts = self::get_options();
		return ! empty( $opts['markdown']['enabled'] );
	}

	public static function is_markdown_post_type( $post_type ) {
		$opts = self::get_options();
		return in_array( $post_type, (array) $opts['markdown']['post_types'], true );
	}

	public static function has_physical_llms_file() {
		return file_exists( ABSPATH . 'llms.txt' );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_ia' );

		$known_post_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$posted_types      = isset( $_POST['markdown_post_types'] ) && is_array( $_POST['markdown_post_types'] ) ? wp_unslash( $_POST['markdown_post_types'] ) : array();
		$post_types        = array_values( array_intersect( $known_post_types, $posted_types ) );

		$opts = array(
			'llms' => array(
				'enabled'       => ! empty( $_POST['llms_enabled'] ),
				'intro'         => isset( $_POST['llms_intro'] ) ? sanitize_text_field( wp_unslash( $_POST['llms_intro'] ) ) : '',
				'body'          => isset( $_POST['llms_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['llms_body'] ) ) : '',
				'include_pages' => ! empty( $_POST['llms_include_pages'] ),
				'include_posts' => ! empty( $_POST['llms_include_posts'] ),
				'posts_count'   => isset( $_POST['llms_posts_count'] ) ? max( 0, min( 100, (int) $_POST['llms_posts_count'] ) ) : 20,
			),
			'markdown' => array(
				'enabled'    => ! empty( $_POST['markdown_enabled'] ),
				'post_types' => $post_types,
				'link_tag'   => ! empty( $_POST['markdown_link_tag'] ),
			),
		);

		update_option( self::OPTION, $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	private static function default_intro() {
		$desc = get_bloginfo( 'description' );
		return $desc ? $desc : get_bloginfo( 'name' );
	}

	public static function render_tab() {
		$opts = self::get_options();
		$llms = $opts['llms'];
		$md   = $opts['markdown'];
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmdroom_save_ia' ); ?>
			<input type="hidden" name="action" value="cmdroom_save_ia" />

			<div class="cr-card cmdroom-ia-block">
				<div class="cmdroom-ia-block-head">
					<div>
						<h2 class="cmdroom-ia-block-title"><?php esc_html_e( 'llms.txt', 'command-room' ); ?></h2>
						<p class="cmdroom-config-rule-desc"><?php esc_html_e( 'Archivo de contexto en /llms.txt: resumen del sitio en markdown para que los LLM lo lean antes de rastrear nada, con enlaces a páginas y entradas.', 'command-room' ); ?></p>
					</div>
					<button type="button" class="cr-toggle<?php echo $llms['enabled'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="llms_enabled" value="1" <?php checked( $llms['enabled'] ); ?> /></button>
				</div>

				<?php if ( self::has_physical_llms_file() ) : ?>
					<div class="notice notice-warning inline">
						<p><strong><?php esc_html_e( 'Hay un llms.txt físico en el servidor', 'command-room' ); ?></strong> — <?php esc_html_e( 'el servidor lo sirve directamente y este control no tendrá efecto hasta que se borre. No lo he tocado.', 'command-room' ); ?></p>
					</div>
				<?php endif; ?>

				<div class="cmdroom-ia-field">
					<label class="cr-label" for="cmdroom-llms-intro"><?php esc_html_e( 'Resumen (una línea)', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-llms-intro" class="cr-input" name="llms_intro" value="<?php echo esc_attr( $llms['intro'] ); ?>" placeholder="<?php echo esc_attr( self::default_intro() ); ?>" />
					<p class="cmdroom-config-note"><?php esc_html_e( 'Vacío = usa la descripción del sitio (Ajustes → Generales).', 'command-room' ); ?></p>
				</div>

				<div class="cmdroom-ia-field">
					<label class="cr-label" for="cmdroom-llms-body"><?php esc_html_e( 'Contexto adicional (markdown libre, opcional)', 'command-room' ); ?></label>
					<textarea id="cmdroom-llms-body" class="cr-input cmdroom-ia-textarea" name="llms_body" rows="4" placeholder="<?php esc_attr_e( 'Qué es este sitio, a quién sirve, qué NO es...', 'command-room' ); ?>"><?php echo esc_textarea( $llms['body'] ); ?></textarea>
				</div>

				<div class="cmdroom-config-grid">
					<div class="cmdroom-ia-field">
						<label class="cmdroom-ia-check"><input type="checkbox" name="llms_include_pages" value="1" <?php checked( $llms['include_pages'] ); ?> /> <?php esc_html_e( 'Incluir listado de páginas', 'command-room' ); ?></label>
					</div>
					<div class="cmdroom-ia-field">
						<label class="cmdroom-ia-check"><input type="checkbox" name="llms_include_posts" value="1" <?php checked( $llms['include_posts'] ); ?> /> <?php esc_html_e( 'Incluir entradas recientes', 'command-room' ); ?></label>
					</div>
					<div class="cmdroom-ia-field">
						<label class="cr-label" for="cmdroom-llms-count"><?php esc_html_e( 'Nº de entradas a listar', 'command-room' ); ?></label>
						<input type="number" id="cmdroom-llms-count" class="cr-input" name="llms_posts_count" min="0" max="100" value="<?php echo esc_attr( $llms['posts_count'] ); ?>" style="max-width:110px;" />
					</div>
				</div>

				<p class="cmdroom-config-note"><?php esc_html_e( 'Las páginas/entradas marcadas como noindex no se listan. El listado y el resumen se regeneran solos en cada visita, no hay que volver a guardar cuando publiques contenido nuevo.', 'command-room' ); ?></p>

				<?php if ( $llms['enabled'] ) : ?>
					<a class="cmdroom-robots-view-link" href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank"><?php printf( esc_html__( 'Ver %s ↗', 'command-room' ), esc_html( home_url( '/llms.txt' ) ) ); ?></a>
				<?php endif; ?>
			</div>

			<div class="cr-card cmdroom-ia-block">
				<div class="cmdroom-ia-block-head">
					<div>
						<h2 class="cmdroom-ia-block-title"><?php esc_html_e( 'Markdown de páginas', 'command-room' ); ?></h2>
						<p class="cmdroom-config-rule-desc"><?php esc_html_e( 'Sirve una copia en markdown de cada entrada/página en la misma URL + ".md" (ej. /mi-articulo.md), para que los crawlers de IA lean el contenido limpio sin parsear el HTML del tema.', 'command-room' ); ?></p>
					</div>
					<button type="button" class="cr-toggle<?php echo $md['enabled'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="markdown_enabled" value="1" <?php checked( $md['enabled'] ); ?> /></button>
				</div>

				<div class="cmdroom-ia-field">
					<span class="cr-label"><?php esc_html_e( 'Tipos de contenido', 'command-room' ); ?></span>
					<div class="cmdroom-config-grid">
						<?php foreach ( $post_types as $pt ) : ?>
							<label class="cmdroom-ia-check"><input type="checkbox" name="markdown_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $md['post_types'], true ) ); ?> /> <?php echo esc_html( $pt->label ); ?></label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="cmdroom-ia-field">
					<label class="cmdroom-ia-check"><input type="checkbox" name="markdown_link_tag" value="1" <?php checked( $md['link_tag'] ); ?> /> <?php esc_html_e( 'Anunciar la versión markdown en el <head> (<link rel="alternate" type="text/markdown">)', 'command-room' ); ?></label>
				</div>

				<p class="cmdroom-config-note"><?php esc_html_e( 'El contenido noindex nunca se sirve en markdown (devuelve 404). La conversión de HTML a markdown se hace al vuelo, sin guardar archivos físicos.', 'command-room' ); ?></p>

				<?php
				// La URL .md se construye con Cmdroom_Ia_Output::markdown_url(),
				// no a mano -- la portada estática es un caso especial
				// (get_permalink() le devuelve home_url('/') tal cual, un
				// untrailingslashit()+'.md' ingenuo produce "dominio.md", que
				// apunta a otro host en vez de a la portada; bug real
				// encontrado el 2026-09-25).
				$example_id = get_option( 'page_on_front' ) ? get_option( 'page_on_front' ) : self::first_public_post_id();
				if ( $example_id ) :
					?>
					<a class="cmdroom-robots-view-link" href="<?php echo esc_url( Cmdroom_Ia_Output::markdown_url( $example_id ) ); ?>" target="_blank"><?php esc_html_e( 'Ver un ejemplo ↗', 'command-room' ); ?></a>
				<?php endif; ?>
			</div>

			<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:8px;' ) ); ?>
		</form>
		<?php
	}

	private static function first_public_post_id() {
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
		return $posts ? $posts[0] : 0;
	}
}
