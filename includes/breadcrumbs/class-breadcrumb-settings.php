<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "Breadcrumbs" de la pantalla "Configuración". Guarda la
 * configuración; Cmdroom_Breadcrumbs es quien calcula y pinta las migas de
 * verdad (shortcode `[cmdroom_breadcrumbs]`, función de tema
 * `cmdroom_the_breadcrumbs()` — se mantienen esos nombres, ya usados en
 * plantillas reales, en vez del `[cr_breadcrumbs]` del prototipo).
 *
 * Desde el rediseño "Configuración" (2026-09-23): 'enabled' es un toggle
 * nuevo (antes las migas estaban siempre "activas" de facto, sin forma de
 * apagarlas del todo); 'cat' es nuevo (antes get_items_for_post() incluía
 * la categoría principal siempre, sin opción); 'schema' sustituye a
 * 'jsonld_live_output'; 'sep'/'home_text' sustituyen a 'separator'/
 * 'home_label'; 'prefix' es nuevo. 'search_prefix'/'label_404'/'bold_last'
 * se mantienen como campos heredados (el mockup no los cubre pero
 * get_items_for_search()/get_items_for_404()/render_html() ya dependían de
 * ellos).
 */
class Cmdroom_Breadcrumb_Settings {

	const OPTION = 'cmdroom_breadcrumb_options';

	const RULES = array(
		'enabled' => array(
			'title'   => 'Activar breadcrumbs',
			'desc'    => 'Habilita el shortcode, el bloque y la función de plantilla.',
			'default' => true,
		),
		'home'    => array(
			'title'   => 'Mostrar enlace de inicio',
			'desc'    => 'El primer elemento enlaza a la portada con el texto configurado.',
			'default' => true,
		),
		'cat'     => array(
			'title'   => 'Incluir categoría principal',
			'desc'    => 'En entradas, añade la categoría principal (o la primera) antes del título.',
			'default' => true,
		),
		'schema'  => array(
			'title'   => 'Salida BreadcrumbList en el schema',
			'desc'    => 'Añade la jerarquía al JSON-LD aunque el tema no muestre las migas.',
			// OFF de fábrica (decisión 2026-09-18): con Rank Math aún activo
			// duplicaría su BreadcrumbList. Visto en una instalación nueva
			// el 2026-09-26.
			'default' => false,
		),
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_breadcrumbs', array( __CLASS__, 'handle_save' ) );
	}

	public static function get_options() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	private static function defaults() {
		$defaults = array(
			'sep'       => '›',
			'home_text' => __( 'Inicio', 'command-room' ),
			'prefix'    => '',
			// Heredados del módulo suelto anterior, sin fila propia en el
			// mockup pero necesarios para que get_items_for_search()/
			// get_items_for_404()/render_html() sigan funcionando.
			'bold_last'     => true,
			'search_prefix' => __( 'Resultados para:', 'command-room' ),
			'label_404'     => __( 'Página no encontrada', 'command-room' ),
		);
		foreach ( self::RULES as $key => $rule ) {
			$defaults[ $key ] = $rule['default'];
		}
		return $defaults;
	}

	public static function is_enabled() {
		$opts = self::get_options();
		return ! empty( $opts['enabled'] );
	}

	public static function is_jsonld_live_output_enabled() {
		$opts = self::get_options();
		return ! empty( $opts['enabled'] ) && ! empty( $opts['schema'] );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_breadcrumbs' );

		$opts = array(
			'sep'           => isset( $_POST['sep'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['sep'] ) ), 0, 5 ) : '›',
			'home_text'     => isset( $_POST['home_text'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['home_text'] ) ), 0, 60 ) : __( 'Inicio', 'command-room' ),
			'prefix'        => isset( $_POST['prefix'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['prefix'] ) ), 0, 60 ) : '',
			'bold_last'     => ! empty( $_POST['bold_last'] ),
			'search_prefix' => isset( $_POST['search_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['search_prefix'] ) ) : '',
			'label_404'     => isset( $_POST['label_404'] ) ? sanitize_text_field( wp_unslash( $_POST['label_404'] ) ) : '',
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

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cr-breadcrumbs-form>
			<?php wp_nonce_field( 'cmdroom_save_breadcrumbs' ); ?>
			<input type="hidden" name="action" value="cmdroom_save_breadcrumbs" />

			<label class="cr-label"><?php esc_html_e( 'Vista previa', 'command-room' ); ?></label>
			<div class="cr-card cmdroom-config-crumbs-preview" data-cr-crumbs-preview></div>

			<div class="cmdroom-config-grid">
				<div class="cr-dialog-field">
					<label class="cr-label" for="cmdroom-bc-sep"><?php esc_html_e( 'Separador', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-bc-sep" name="sep" class="cr-input" maxlength="5" value="<?php echo esc_attr( $opts['sep'] ); ?>" data-cr-crumbs-field="sep" />
				</div>
				<div class="cr-dialog-field">
					<label class="cr-label" for="cmdroom-bc-home-text"><?php esc_html_e( 'Texto de inicio', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-bc-home-text" name="home_text" class="cr-input" maxlength="60" value="<?php echo esc_attr( $opts['home_text'] ); ?>" data-cr-crumbs-field="home_text" />
				</div>
				<div class="cr-dialog-field">
					<label class="cr-label" for="cmdroom-bc-prefix"><?php esc_html_e( 'Prefijo', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-bc-prefix" name="prefix" class="cr-input" maxlength="60" placeholder="<?php esc_attr_e( 'Estás en:', 'command-room' ); ?>" value="<?php echo esc_attr( $opts['prefix'] ); ?>" data-cr-crumbs-field="prefix" />
				</div>
			</div>

			<div class="cr-card cmdroom-config-rule-list">
				<?php foreach ( self::RULES as $key => $rule ) :
					$on = ! empty( $opts[ $key ] );
					?>
					<div class="cmdroom-config-rule-row">
						<button type="button" class="cr-toggle<?php echo $on ? ' is-on' : ''; ?>" data-cr-toggle data-cr-crumbs-field="<?php echo esc_attr( $key ); ?>"><span class="cr-toggle-knob"></span><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $on ); ?> /></button>
						<div class="cmdroom-config-rule-body">
							<p class="cmdroom-config-rule-title"><?php echo esc_html( $rule['title'] ); ?></p>
							<p class="cmdroom-config-rule-desc"><?php echo esc_html( $rule['desc'] ); ?></p>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="cmdroom-config-note">
				<?php
				printf(
					/* translators: %s: shortcode chip */
					esc_html__( 'Inserta las migas en tu tema con el shortcode %s o la función cmdroom_the_breadcrumbs() en la plantilla.', 'command-room' ),
					'<span class="cr-chip">[cmdroom_breadcrumbs]</span>'
				);
				?>
			</p>

			<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
		</form>

		<div class="cmdroom-config-extra">
			<h3><?php esc_html_e( 'Otros ajustes', 'command-room' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Etiqueta en búsquedas y en 404, y si el último elemento va en negrita. No están en el diseño principal pero las sigue usando el cálculo de las migas.', 'command-room' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdroom_save_breadcrumbs' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_breadcrumbs" />
				<?php foreach ( array( 'sep', 'home_text', 'prefix' ) as $carry ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $carry ); ?>" value="<?php echo esc_attr( $opts[ $carry ] ); ?>" />
				<?php endforeach; ?>
				<?php foreach ( array_keys( self::RULES ) as $carry ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $carry ); ?>" value="<?php echo $opts[ $carry ] ? '1' : '0'; ?>" />
				<?php endforeach; ?>
				<table class="form-table">
					<tr>
						<th><label for="cmdroom-bc-search-prefix"><?php esc_html_e( 'Prefijo en búsquedas', 'command-room' ); ?></label></th>
						<td><input type="text" id="cmdroom-bc-search-prefix" name="search_prefix" value="<?php echo esc_attr( $opts['search_prefix'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><label for="cmdroom-bc-label-404"><?php esc_html_e( 'Etiqueta en 404', 'command-room' ); ?></label></th>
						<td><input type="text" id="cmdroom-bc-label-404" name="label_404" value="<?php echo esc_attr( $opts['label_404'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Último elemento', 'command-room' ); ?></th>
						<td><label><input type="checkbox" name="bold_last" value="1" <?php checked( $opts['bold_last'] ); ?> /> <?php esc_html_e( 'En negrita y sin enlace (es la página actual)', 'command-room' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-secondary', 'submit', false ); ?>
			</form>

			<h3 style="margin-top:28px;"><?php esc_html_e( 'Vista previa por post real', 'command-room' ); ?></h3>
			<form method="get">
				<input type="hidden" name="page" value="cmdroom-config" />
				<input type="hidden" name="tab" value="breadcrumbs" />
				<label for="cmdroom_preview_id" class="cr-label"><?php esc_html_e( 'ID de post', 'command-room' ); ?></label>
				<input type="number" id="cmdroom_preview_id" name="cmdroom_preview_id" class="cr-input" style="max-width:160px;" value="<?php echo isset( $_GET['cmdroom_preview_id'] ) ? esc_attr( absint( $_GET['cmdroom_preview_id'] ) ) : ''; ?>" />
				<?php submit_button( __( 'Ver', 'command-room' ), 'cr-btn-secondary', '', false ); ?>
			</form>
			<?php if ( ! empty( $_GET['cmdroom_preview_id'] ) ) :
				$items = Cmdroom_Breadcrumbs::get_items_for_post( absint( $_GET['cmdroom_preview_id'] ) );
				?>
				<?php if ( $items ) : ?>
					<div class="cr-card" style="padding:14px 16px;margin-top:12px;max-width:800px;">
						<?php echo Cmdroom_Breadcrumbs::render_html( $items ); // ya viene escapado ?>
					</div>
					<pre style="max-width:800px;overflow:auto;background:#1c1c1c;color:#e5e5e5;padding:1em;margin-top:1em;"><?php echo esc_html( wp_json_encode( $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
				<?php else : ?>
					<p><?php esc_html_e( 'No se encontró ese post.', 'command-room' ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
