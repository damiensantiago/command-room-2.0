<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orquesta las secciones nuevas de "General" que no tenían clase propia
 * (handoff 2026-09-24, alta fidelidad, Modernist): Patrones de éxito,
 * Auditoría del sitio, Análisis y legibilidad, Problemas recientes y
 * Privacidad. La sección de GSC sigue en Cmdroom_Gsc_Dashboard (ya
 * existía) y la tabla de módulos sigue en Cmdroom_Admin_Menu (acoplada a
 * su estado privado de visibilidad) -- esta clase solo pone el resto y
 * encola los assets nuevos (CSS/JS) de toda la pantalla.
 */
class Cmdroom_General_Dashboard {

	public static function init() {
		Cmdroom_General_Audit::init();
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || Cmdroom_Admin_Menu::SLUG !== $_GET['page'] ) {
			return;
		}
		wp_enqueue_style( 'cmdroom-general-editor', CMDROOM_URL . 'assets/css/general-editor.css', array(), CMDROOM_VERSION );
		wp_enqueue_script( 'cmdroom-general-editor', CMDROOM_URL . 'assets/js/general-editor.js', array(), CMDROOM_VERSION, true );
	}

	public static function render_patterns_section() {
		$patterns = Cmdroom_General_Patterns::get_patterns();
		?>
		<div class="cr-card cmdroom-general-patterns">
			<div class="cmdroom-general-section-head">
				<span class="cmdroom-general-eyebrow"><?php esc_html_e( 'Patrones de éxito', 'command-room' ); ?></span>
				<span class="cmdroom-general-section-sub"><?php esc_html_e( 'Últimos 28 días vs. periodo anterior', 'command-room' ); ?></span>
			</div>
			<p class="cmdroom-general-section-intro"><?php esc_html_e( 'Lo que mejor está funcionando últimamente en el sitio.', 'command-room' ); ?></p>

			<?php if ( empty( $patterns ) ) : ?>
				<p class="description"><?php esc_html_e( 'Todavía no hay suficiente historial de Search Console para detectar patrones fiables.', 'command-room' ); ?></p>
			<?php else : ?>
				<?php foreach ( array_slice( $patterns, 0, 4 ) as $i => $p ) : ?>
					<div class="cmdroom-general-pattern-row">
						<span class="cmdroom-general-pattern-num"><?php echo esc_html( sprintf( '%02d', $i + 1 ) ); ?></span>
						<div class="cmdroom-general-pattern-body">
							<div class="cmdroom-general-pattern-title"><?php echo esc_html( $p['title'] ); ?></div>
							<div class="cmdroom-general-pattern-desc"><?php echo esc_html( $p['description'] ); ?></div>
						</div>
						<div class="cmdroom-general-pattern-pills">
							<span class="cr-pill cmdroom-pill-green"><?php echo esc_html( $p['pill1'] ); ?></span>
							<span class="cr-pill cmdroom-pill-gray"><?php echo esc_html( $p['pill2'] ); ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_audit_section() {
		if ( isset( $_GET['cmdroom_audit_refreshed'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Auditoría actualizada.', 'command-room' ) . '</p></div>';
		}
		$groups = Cmdroom_General_Audit::get_grouped_issues();
		?>
		<h2 class="cmdroom-general-h2"><?php esc_html_e( 'Auditoría del sitio', 'command-room' ); ?></h2>

		<div class="cmdroom-general-audit-toolbar">
			<div class="cr-seg" data-cr-audit-filter>
				<button type="button" class="cr-seg-opt is-active" data-cr-audit-severity="all"><?php esc_html_e( 'Todos', 'command-room' ); ?></button>
				<button type="button" class="cr-seg-opt" data-cr-audit-severity="critical"><?php esc_html_e( 'Críticos', 'command-room' ); ?></button>
				<button type="button" class="cr-seg-opt" data-cr-audit-severity="warning"><?php esc_html_e( 'Advertencias', 'command-room' ); ?></button>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdroom_refresh_audit' ); ?>
				<input type="hidden" name="action" value="cmdroom_refresh_audit" />
				<?php submit_button( __( 'Actualizar auditoría', 'command-room' ), 'cr-btn-secondary', 'submit', false ); ?>
			</form>
		</div>

		<div class="cr-card cmdroom-general-audit-card">
			<?php if ( empty( $groups ) ) : ?>
				<p class="description" style="padding:14px 18px;"><?php esc_html_e( 'Sin problemas detectados en el último escaneo.', 'command-room' ); ?></p>
			<?php else : ?>
				<table class="cmdroom-gsc-table" data-cr-audit-table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'URL', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Problema', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Repetición', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Severidad', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Detectado', 'command-room' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $groups as $g ) :
							$example = $g['items'][0];
							$rest    = array_slice( $g['items'], 1 );
							?>
							<tr data-cr-audit-row data-severity="<?php echo esc_attr( $g['severity'] ); ?>">
								<td><?php echo esc_html( wp_parse_url( $example['url'], PHP_URL_PATH ) ?: $example['url'] ); ?></td>
								<td class="cmdroom-general-muted"><?php echo esc_html( $g['label'] ); ?><?php echo $example['detail'] ? ' — ' . esc_html( $example['detail'] ) : ''; ?></td>
								<td>
									<?php if ( $g['count'] > 1 ) : ?>
										<span class="cr-pill cmdroom-pill-gray"><?php echo esc_html( sprintf( _n( '%d URL', '%d URLs', $g['count'], 'command-room' ), $g['count'] ) ); ?></span>
										<button type="button" class="cmdroom-general-audit-toggle" data-cr-audit-toggle="<?php echo esc_attr( $g['type'] ); ?>" data-cr-audit-count="<?php echo esc_attr( $g['count'] ); ?>" aria-expanded="false">
											<?php echo esc_html( sprintf( __( 'Ver las %d ▾', 'command-room' ), $g['count'] ) ); ?>
										</button>
									<?php else : ?>
										<span class="cmdroom-general-faint">—</span>
									<?php endif; ?>
								</td>
								<td><?php echo self::severity_pill( $g['severity'] ); // phpcs:ignore -- ya escapado dentro de severity_pill() ?></td>
								<td class="cmdroom-general-faint"><?php echo esc_html( mysql2date( 'j M', $g['detected'] ) ); ?></td>
							</tr>
							<?php foreach ( $rest as $item ) : ?>
								<tr data-cr-audit-row data-cr-audit-extra="<?php echo esc_attr( $g['type'] ); ?>" data-severity="<?php echo esc_attr( $g['severity'] ); ?>" hidden>
									<td><?php echo esc_html( wp_parse_url( $item['url'], PHP_URL_PATH ) ?: $item['url'] ); ?></td>
									<td class="cmdroom-general-muted"><?php echo $item['detail'] ? esc_html( $item['detail'] ) : '—'; ?></td>
									<td></td>
									<td></td>
									<td class="cmdroom-general-faint"><?php echo esc_html( mysql2date( 'j M', $item['detected'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_readability_section() {
		$analysis = Cmdroom_General_Readability::get_analysis();
		?>
		<h2 class="cmdroom-general-h2"><?php esc_html_e( 'Análisis y legibilidad', 'command-room' ); ?></h2>
		<?php if ( ! $analysis ) : ?>
			<p class="description"><?php esc_html_e( 'Todavía no hay ninguna entrada publicada que analizar.', 'command-room' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<p class="description" style="margin:0 0 14px;">
			<?php
			printf(
				/* translators: %s: post title */
				esc_html__( 'Última entrada publicada: %s', 'command-room' ),
				'<a href="' . esc_url( $analysis['post_url'] ) . '" target="_blank" rel="noreferrer">' . esc_html( $analysis['post_title'] ) . '</a>'
			);
			?>
		</p>
		<div class="cmdroom-general-readability-grid">
			<div class="cr-card cmdroom-general-check-card">
				<div class="cmdroom-general-eyebrow"><?php esc_html_e( 'SEO', 'command-room' ); ?></div>
				<?php self::render_check_rows( $analysis['seo'] ); ?>
			</div>
			<div class="cr-card cmdroom-general-check-card">
				<div class="cmdroom-general-eyebrow"><?php esc_html_e( 'Legibilidad', 'command-room' ); ?></div>
				<?php self::render_check_rows( $analysis['readability'] ); ?>
			</div>
		</div>
		<?php
	}

	private static function render_check_rows( $checks ) {
		foreach ( $checks as $c ) {
			$dot = array( 'good' => array( '✓', 'cmdroom-dot-good' ), 'bad' => array( '!', 'cmdroom-dot-bad' ), 'ok' => array( '·', 'cmdroom-dot-ok' ) );
			list( $symbol, $class ) = $dot[ $c['state'] ];
			?>
			<div class="cmdroom-check-row">
				<span class="cmdroom-dot <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $symbol ); ?></span>
				<span><?php echo esc_html( $c['text'] ); ?></span>
			</div>
			<?php
		}
	}

	public static function render_recent_and_privacy_section() {
		$groups = array_slice( Cmdroom_General_Audit::get_grouped_issues(), 0, 4 );
		?>
		<div class="cmdroom-general-bottom-grid">
			<div class="cr-card cmdroom-general-recent-issues">
				<div class="cmdroom-general-eyebrow"><?php esc_html_e( 'Problemas recientes', 'command-room' ); ?></div>
				<?php if ( empty( $groups ) ) : ?>
					<p class="description"><?php esc_html_e( 'Nada que destacar.', 'command-room' ); ?></p>
				<?php else : ?>
					<?php foreach ( $groups as $g ) : ?>
						<div class="cmdroom-general-recent-row">
							<span>
								<?php
								echo $g['count'] > 1
									? esc_html( $g['label'] . ' — ' . sprintf( _n( '%d entrada', '%d entradas', $g['count'], 'command-room' ), $g['count'] ) )
									: esc_html( $g['label'] );
								?>
							</span>
							<?php echo self::severity_pill( $g['severity'] ); // phpcs:ignore -- ya escapado dentro de severity_pill() ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<div class="cr-card cmdroom-general-privacy">
				<div class="cmdroom-general-eyebrow"><?php esc_html_e( 'Privacidad', 'command-room' ); ?></div>
				<?php
				$gsc_on  = Cmdroom_Gsc_Settings::is_connected();
				$crux_on = Cmdroom_Crux_Settings::is_configured();
				if ( $gsc_on || $crux_on ) {
					$services = array();
					if ( $gsc_on ) {
						$services[] = 'Google Search Console';
					}
					if ( $crux_on ) {
						$services[] = 'Chrome UX Report';
					}
					printf(
						'<p>%s</p>',
						esc_html(
							sprintf(
								/* translators: %s: comma-separated list of connected services */
								__( 'Código abierto, autohospedado. Solo sale de tu instalación lo estrictamente necesario para las integraciones que has conectado tú mismo: %s.', 'command-room' ),
								implode( ', ', $services )
							)
						)
					);
					?>
					<span class="cr-pill cmdroom-pill-indigo"><?php esc_html_e( 'Llamadas externas solo a lo que conectaste', 'command-room' ); ?></span>
					<?php
				} else {
					?>
					<p><?php esc_html_e( 'Código abierto, autohospedado. Ningún dato se envía fuera de tu instalación de WordPress.', 'command-room' ); ?></p>
					<span class="cr-pill cmdroom-pill-indigo"><?php esc_html_e( 'Sin llamadas externas', 'command-room' ); ?></span>
					<?php
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function severity_pill( $severity ) {
		$map = array(
			'critical' => array( __( 'Crítico', 'command-room' ), 'cmdroom-pill-red' ),
			'warning'  => array( __( 'Advertencia', 'command-room' ), 'cmdroom-pill-amber' ),
			'info'     => array( __( 'Info', 'command-room' ), 'cmdroom-pill-gray' ),
		);
		list( $label, $class ) = $map[ $severity ] ?? array( $severity, 'cmdroom-pill-gray' );
		return '<span class="cr-pill ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</span>';
	}
}
