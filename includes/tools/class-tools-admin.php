<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "Herramientas" de la pantalla "Configuración". Orden pedido por
 * el administrador 2026-09-25: Google Search Console, Core Web Vitals (CrUX),
 * Importar desde Rank Math / Yoast SEO -- el cuadro "Salida en el sitio" va
 * después, lo pinta Cmdroom_Config_Admin.
 *
 * La vista previa de metas/schema (por URL vía REST y por ID de
 * post/término) que vivía aquí se quitó ese mismo día a petición del administrador
 * -- ya no hay endpoint /command-room/v1/preview ni el card/sección
 * correspondientes.
 */
class Cmdroom_Tools_Admin {

	public static function render_tab() {
		?>
		<div class="cmdroom-config-extra cmdroom-config-extra-first">
			<h3><?php esc_html_e( 'Google Search Console', 'command-room' ); ?></h3>
			<?php Cmdroom_Gsc_Settings::render_tab(); ?>
		</div>

		<div class="cmdroom-config-extra">
			<h3><?php esc_html_e( 'Core Web Vitals (CrUX)', 'command-room' ); ?></h3>
			<?php Cmdroom_Crux_Settings::render_tab(); ?>
		</div>

		<div class="cmdroom-config-tools-grid">
			<?php self::render_import_card(); ?>
			<?php self::render_import_card_yoast(); ?>
		</div>
		<?php
	}

	private static function render_import_card() {
		$active = Cmdroom_Rankmath_Importer::is_rankmath_active();
		$report = get_transient( 'cmdroom_import_report' );
		?>
		<div class="cr-card cmdroom-config-tools-card">
			<h3 class="cmdroom-config-tools-title"><?php esc_html_e( 'Importar desde Rank Math', 'command-room' ); ?></h3>
			<p class="cmdroom-config-tools-text"><?php esc_html_e( 'Copia títulos, descripciones, canonicals, robots y redirecciones. Los datos de Rank Math no se borran.', 'command-room' ); ?></p>

			<?php if ( isset( $_GET['cmdroom_imported'] ) && $report ) : ?>
				<div class="notice notice-success inline">
					<p>
						<?php if ( isset( $report['posts'] ) ) : ?>
							<?php
							printf(
								/* translators: 1: imported posts, 2: skipped posts */
								esc_html__( '%1$d entradas importadas (%2$d omitidas, ya tenían override propio).', 'command-room' ),
								(int) $report['posts']['imported'],
								(int) $report['posts']['skipped']
							);
							?><br />
						<?php endif; ?>
						<?php if ( isset( $report['redirects'] ) ) : ?>
							<?php
							printf(
								/* translators: %d: imported redirects */
								esc_html__( '%d redirecciones importadas.', 'command-room' ),
								(int) $report['redirects']['imported']
							);
							?><br />
						<?php endif; ?>
						<?php if ( isset( $report['schema'] ) && $report['schema']['unmapped'] > 0 ) : ?>
							<?php
							printf(
								/* translators: %d: posts with schema */
								esc_html__( '%d entradas con schema propio en Rank Math -- Command Room aún no tiene override de schema por entrada, no se ha importado nada de eso.', 'command-room' ),
								(int) $report['schema']['unmapped']
							);
							?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdroom_import_rankmath' ); ?>
				<input type="hidden" name="action" value="cmdroom_import_rankmath" />

				<div class="cmdroom-config-tools-checks">
					<label><input type="checkbox" name="import_meta" value="1" checked <?php disabled( ! $active ); ?> /> <?php esc_html_e( 'Metas y plantillas', 'command-room' ); ?></label>
					<label><input type="checkbox" name="import_robots" value="1" checked <?php disabled( ! $active ); ?> /> <?php esc_html_e( 'Robots y canonicals', 'command-room' ); ?></label>
					<label><input type="checkbox" name="import_redirects" value="1" checked <?php disabled( ! $active ); ?> /> <?php esc_html_e( 'Redirecciones', 'command-room' ); ?></label>
					<label><input type="checkbox" name="import_schema" value="1" checked <?php disabled( ! $active ); ?> /> <?php esc_html_e( 'Schema', 'command-room' ); ?></label>
				</div>

				<div class="cmdroom-config-tools-action-row">
					<?php submit_button( $report ? __( 'Importar de nuevo', 'command-room' ) : __( 'Importar', 'command-room' ), 'cr-btn-primary', 'submit', false, $active ? array() : array( 'disabled' => 'disabled' ) ); ?>
					<span class="cmdroom-config-tools-status">
						<?php if ( ! $active ) : ?>
							<?php esc_html_e( 'Rank Math no detectado.', 'command-room' ); ?>
						<?php elseif ( $report ) : ?>
							<?php
							$n = isset( $report['posts'] ) ? (int) $report['posts']['imported'] : 0;
							$r = isset( $report['redirects'] ) ? (int) $report['redirects']['imported'] : 0;
							printf(
								/* translators: 1: entries, 2: redirects */
								esc_html__( '%1$d entradas y %2$d redirecciones importadas.', 'command-room' ),
								$n,
								$r
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %d: importable entries */
								esc_html__( 'Rank Math detectado · %d entradas con datos.', 'command-room' ),
								(int) Cmdroom_Rankmath_Importer::count_importable_posts()
							);
							?>
						<?php endif; ?>
					</span>
				</div>
			</form>
		</div>
		<?php
	}

	private static function render_import_card_yoast() {
		$active = Cmdroom_Yoast_Importer::is_yoast_active();
		$report = get_transient( 'cmdroom_import_report_yoast' );
		?>
		<div class="cr-card cmdroom-config-tools-card">
			<h3 class="cmdroom-config-tools-title"><?php esc_html_e( 'Importar desde Yoast SEO', 'command-room' ); ?></h3>
			<p class="cmdroom-config-tools-text"><?php esc_html_e( 'Copia títulos, descripciones, canonicals, robots y redirecciones (Premium). Los datos de Yoast no se borran.', 'command-room' ); ?></p>

			<?php if ( isset( $_GET['cmdroom_imported_yoast'] ) && $report ) : ?>
				<div class="notice notice-success inline">
					<p>
						<?php if ( isset( $report['posts'] ) ) : ?>
							<?php
							printf(
								/* translators: 1: imported posts, 2: skipped posts */
								esc_html__( '%1$d entradas importadas (%2$d omitidas, ya tenían override propio).', 'command-room' ),
								(int) $report['posts']['imported'],
								(int) $report['posts']['skipped']
							);
							?><br />
						<?php endif; ?>
						<?php if ( isset( $report['redirects'] ) ) : ?>
							<?php if ( ! empty( $report['redirects']['reason'] ) ) : ?>
								<?php echo esc_html( $report['redirects']['reason'] ); ?><br />
							<?php else : ?>
								<?php
								printf(
									/* translators: %d: imported redirects */
									esc_html__( '%d redirecciones importadas.', 'command-room' ),
									(int) $report['redirects']['imported']
								);
								?><br />
							<?php endif; ?>
						<?php endif; ?>
						<?php if ( isset( $report['schema'] ) && $report['schema']['unmapped'] > 0 ) : ?>
							<?php
							printf(
								/* translators: %d: posts with schema */
								esc_html__( '%d entradas con tipo de schema propio en Yoast -- Command Room aún no tiene override de schema por entrada, no se ha importado nada de eso.', 'command-room' ),
								(int) $report['schema']['unmapped']
							);
							?>
						<?php endif; ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdroom_import_yoast' ); ?>
				<input type="hidden" name="action" value="cmdroom_import_yoast" />

				<div class="cmdroom-config-tools-checks">
					<label><input type="checkbox" name="import_meta" value="1" checked <?php disabled( ! $active ); ?> /> <?php esc_html_e( 'Metas y plantillas', 'command-room' ); ?></label>
					<label><input type="checkbox" name="import_robots" value="1" checked <?php disabled( ! $active ); ?> /> <?php esc_html_e( 'Robots y canonicals', 'command-room' ); ?></label>
					<label><input type="checkbox" name="import_redirects" value="1" checked <?php disabled( ! $active ); ?> /> <?php esc_html_e( 'Redirecciones', 'command-room' ); ?></label>
					<label><input type="checkbox" name="import_schema" value="1" checked <?php disabled( ! $active ); ?> /> <?php esc_html_e( 'Schema', 'command-room' ); ?></label>
				</div>

				<div class="cmdroom-config-tools-action-row">
					<?php submit_button( $report ? __( 'Importar de nuevo', 'command-room' ) : __( 'Importar', 'command-room' ), 'cr-btn-primary', 'submit', false, $active ? array() : array( 'disabled' => 'disabled' ) ); ?>
					<span class="cmdroom-config-tools-status">
						<?php if ( ! $active ) : ?>
							<?php esc_html_e( 'Yoast SEO no detectado.', 'command-room' ); ?>
						<?php elseif ( $report ) : ?>
							<?php
							$n = isset( $report['posts'] ) ? (int) $report['posts']['imported'] : 0;
							$r = isset( $report['redirects'] ) ? (int) $report['redirects']['imported'] : 0;
							printf(
								/* translators: 1: entries, 2: redirects */
								esc_html__( '%1$d entradas y %2$d redirecciones importadas.', 'command-room' ),
								$n,
								$r
							);
							?>
						<?php else : ?>
							<?php
							printf(
								/* translators: %d: importable entries */
								esc_html__( 'Yoast SEO detectado · %d entradas con datos.', 'command-room' ),
								(int) Cmdroom_Yoast_Importer::count_importable_posts()
							);
							?>
						<?php endif; ?>
					</span>
				</div>
			</form>
		</div>
		<?php
	}
}
