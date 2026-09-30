<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "Archivos y taxonomías" de la pantalla "Configuración". Reglas de
 * noindex para archivos/taxonomías de bajo valor SEO. Solo guarda la
 * configuración — quien decide el meta robots real es
 * Cmdroom_Meta_Resolver::resolve_robots_for_context() (módulo de Metas),
 * que combina estas reglas con el override manual del post; el resultado
 * sale impreso dentro del bloque de <head> editable (vía %robots%), no
 * como un <meta name="robots"> aparte.
 *
 * Desde el rediseño "Configuración" (2026-09-23) las claves cambian de
 * `noindex_author` a `author`, etc. (más cortas, iguales al handoff) y se
 * añaden 3 reglas nuevas: `tags`, `format` y `search` — search no tenía
 * ni resolver propio antes (is_search() caía sin plantilla ni robots en
 * Cmdroom_Meta_Output::resolve_current()), se añade uno mínimo. `paged`
 * pasa de ON a OFF por defecto (criterio del handoff: solo afecta a follow,
 * no a rastreo, así que no urgía tanto como los demás).
 */
class Cmdroom_Archive_Optimization_Settings {

	const OPTION = 'cmdroom_archive_optimization_options';

	const RULES = array(
		'tags'      => array(
			'title'   => 'Noindex en etiquetas',
			'desc'    => 'Las etiquetas suelen duplicar el contenido de las categorías.',
			'chip'    => '/tag/',
			'default' => true,
		),
		'author'    => array(
			'title'   => 'Noindex en archivos de autor',
			'desc'    => 'Recomendado en sitios con un solo autor: el archivo repite el blog.',
			'chip'    => '/author/',
			'default' => true,
		),
		'date'      => array(
			'title'   => 'Noindex en archivos por fecha',
			'desc'    => 'Los archivos diarios, mensuales y anuales no aportan contenido propio.',
			'chip'    => '/2026/09/',
			'default' => true,
		),
		'format'    => array(
			'title'   => 'Noindex en formatos de entrada',
			'desc'    => 'Archivos de formato (galería, vídeo, cita…) generados por el tema.',
			'chip'    => '/type/gallery/',
			'default' => true,
		),
		'search'    => array(
			'title'   => 'Noindex en resultados de búsqueda',
			'desc'    => 'Evita que las búsquedas internas se indexen como páginas.',
			'chip'    => '?s=',
			'default' => true,
		),
		'paged'     => array(
			'title'   => 'Noindex en páginas 2 y siguientes',
			'desc'    => 'Solo la primera página del archivo es indexable; el resto mantiene follow.',
			'chip'    => '/page/2/',
			'default' => false,
		),
		'emptyterm' => array(
			'title'   => 'Noindex en términos vacíos',
			'desc'    => 'Categorías y etiquetas sin entradas publicadas.',
			'chip'    => '0 posts',
			'default' => true,
		),
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_archive_optimization', array( __CLASS__, 'handle_save' ) );
	}

	public static function get_options() {
		$defaults = array();
		foreach ( self::RULES as $key => $rule ) {
			$defaults[ $key ] = $rule['default'];
		}
		return wp_parse_args( get_option( self::OPTION, array() ), $defaults );
	}

	public static function is_enabled( $rule ) {
		$opts = self::get_options();
		return ! empty( $opts[ $rule ] );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_archive_optimization' );

		$opts = array();
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

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmdroom_save_archive_optimization' ); ?>
			<input type="hidden" name="action" value="cmdroom_save_archive_optimization" />

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
						<span class="cr-chip cmdroom-config-rule-chip"><?php echo esc_html( $rule['chip'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="cmdroom-config-note"><?php esc_html_e( 'Activado = noindex, follow. Los archivos marcados salen también del sitemap XML.', 'command-room' ); ?></p>

			<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
		</form>
		<?php
	}
}
