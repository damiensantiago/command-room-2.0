<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Control de robots.txt vía el filtro nativo `robots_txt` de WordPress —
 * el mismo mecanismo que usan Rank Math/Yoast. Deliberadamente NO escribe
 * un archivo físico: es la vía portable (funciona igual en cualquier sitio
 * WP) y evita depender de permisos de escritura en la raíz del hosting.
 *
 * Desde el rediseño de Claude Design (2026-09-23) esta pantalla absorbe
 * también el control de bots de IA (antes módulo aparte "Bots de IA"): un
 * único formulario edita el contenido base y la tabla de bots, y el bloque
 * generado de bots se previsualiza en vivo justo debajo del editor. Ya no
 * existe una pantalla de admin separada para bots — ver Cmdroom_Admin_Menu.
 *
 * OJO: si ya existe un robots.txt físico en el servidor, el servidor web lo
 * sirve directamente y WordPress no llega a ejecutarse para esa petición —
 * este filtro no tiene ningún efecto hasta que se borre o renombre ese
 * archivo. Se detecta y se avisa en pantalla; el borrado NO es automático.
 */
class Cmdroom_Robots_Settings {

	const OPTION = 'cmdroom_robots';

	/**
	 * Directivas de robots.txt reconocidas — cualquier otra línea no vacía
	 * y sin ":" se marca como error de sintaxis; una directiva desconocida
	 * se marca como aviso (no bloquea el guardado, por si el administrador necesita
	 * una directiva propietaria de algún bot).
	 */
	const KNOWN_DIRECTIVES = array( 'user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay', 'host' );

	/**
	 * Bots de IA reconocidos, en el orden en que se pintan en la tabla.
	 * `blocked_default` son los que solo sirven para entrenar modelos, sin
	 * ningún beneficio de tráfico/citas a cambio — mismo criterio que tenía
	 * el módulo "Bots de IA" antes de fusionarse aquí.
	 */
	const KNOWN_BOTS = array(
		array(
			'ua'              => 'OAI-SearchBot',
			'company'         => 'OpenAI',
			'desc'            => 'Usado por ChatGPT Search para citar fuentes.',
			'blocked_default' => false,
		),
		array(
			'ua'              => 'GPTBot',
			'company'         => 'OpenAI',
			'desc'            => 'Entrena modelos con el contenido rastreado.',
			'blocked_default' => true,
		),
		array(
			'ua'              => 'ChatGPT-User',
			'company'         => 'OpenAI',
			'desc'            => 'Navegación en vivo cuando un usuario lo pide dentro de ChatGPT.',
			'blocked_default' => false,
		),
		array(
			'ua'              => 'ClaudeBot',
			'company'         => 'Anthropic',
			'desc'            => 'Entrena modelos con el contenido rastreado.',
			'blocked_default' => true,
		),
		array(
			'ua'              => 'Claude-Web',
			'company'         => 'Anthropic',
			'desc'            => 'Navegación en vivo de Claude.',
			'blocked_default' => false,
		),
		array(
			'ua'              => 'PerplexityBot',
			'company'         => 'Perplexity',
			'desc'            => 'Indexa para responder con citas.',
			'blocked_default' => false,
		),
		array(
			'ua'              => 'Google-Extended',
			'company'         => 'Google',
			'desc'            => 'Permite/bloquea el uso del contenido en Gemini/Vertex AI sin afectar a Google Search.',
			'blocked_default' => true,
		),
		array(
			'ua'              => 'CCBot',
			'company'         => 'Common Crawl',
			'desc'            => 'Dataset abierto que muchos LLM usan para entrenar.',
			'blocked_default' => true,
		),
		array(
			'ua'              => 'Bytespider',
			'company'         => 'ByteDance',
			'desc'            => 'Rastreo para entrenamiento de modelos propios (TikTok).',
			'blocked_default' => true,
		),
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_robots', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots' ), 20, 1 );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || 'cmdroom-robots' !== $_GET['page'] ) {
			return;
		}
		wp_enqueue_style( 'cmdroom-meta-editor', CMDROOM_URL . 'assets/css/meta-editor.css', array(), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-robots-editor', CMDROOM_URL . 'assets/css/robots-editor.css', array( 'cmdroom-meta-editor' ), CMDROOM_VERSION );
		wp_enqueue_script( 'cmdroom-robots-editor', CMDROOM_URL . 'assets/js/robots-editor.js', array(), CMDROOM_VERSION, true );
	}

	public static function filter_robots( $default_output ) {
		$opts    = self::get_options();
		$content = isset( $opts['content'] ) ? $opts['content'] : '';
		$base    = '' !== trim( $content ) ? $content : $default_output;
		$block   = self::build_block();

		return rtrim( $base ) . "\n\n" . $block;
	}

	public static function has_physical_file() {
		return file_exists( ABSPATH . 'robots.txt' );
	}

	/**
	 * Validación básica de sintaxis: cada línea no vacía y no comentario (#)
	 * debe tener forma "Directiva: valor". Distingue error (línea sin ":",
	 * imposible de interpretar) de aviso (directiva no reconocida, pero con
	 * forma válida — se guarda igualmente). Solo se aplica al contenido base
	 * que escribe el administrador; el bloque de bots se genera y nunca se valida como
	 * texto libre.
	 */
	public static function validate( $content ) {
		$errors   = array();
		$warnings = array();
		$lines    = preg_split( '/\r\n|\r|\n/', (string) $content );

		foreach ( $lines as $n => $line ) {
			$trimmed = trim( $line );
			if ( '' === $trimmed || '#' === substr( $trimmed, 0, 1 ) ) {
				continue;
			}

			if ( false === strpos( $trimmed, ':' ) ) {
				/* translators: 1: line number, 2: line content */
				$errors[] = sprintf( __( 'Línea %1$d: falta ":" — "%2$s" no tiene forma de directiva.', 'command-room' ), $n + 1, $trimmed );
				continue;
			}

			list( $directive, $value ) = array_map( 'trim', explode( ':', $trimmed, 2 ) );
			$directive_key = strtolower( $directive );

			if ( ! in_array( $directive_key, self::KNOWN_DIRECTIVES, true ) ) {
				/* translators: 1: line number, 2: directive name */
				$warnings[] = sprintf( __( 'Línea %1$d: directiva "%2$s" no reconocida (se guarda igual, revisa que no sea un error tipográfico).', 'command-room' ), $n + 1, $directive );
				continue;
			}

			if ( in_array( $directive_key, array( 'allow', 'disallow' ), true ) && '' !== $value && '/' !== substr( $value, 0, 1 ) ) {
				/* translators: 1: line number, 2: directive name */
				$warnings[] = sprintf( __( 'Línea %1$d: "%2$s" normalmente empieza por "/".', 'command-room' ), $n + 1, $directive );
			}
		}

		return array( 'errors' => $errors, 'warnings' => $warnings );
	}

	private static function known_bots_index() {
		$out = array();
		foreach ( self::KNOWN_BOTS as $bot ) {
			$out[ strtolower( $bot['ua'] ) ] = $bot;
		}
		return $out;
	}

	/**
	 * Rutas separadas por coma → array saneado: recorta espacios, fuerza "/"
	 * inicial, permite letras/números/"/-_.*$" y elimina vacíos/duplicados.
	 */
	private static function sanitize_paths( $raw ) {
		$parts = explode( ',', (string) $raw );
		$out   = array();

		foreach ( $parts as $part ) {
			$path = trim( $part );
			if ( '' === $path ) {
				continue;
			}
			if ( '/' !== substr( $path, 0, 1 ) ) {
				$path = '/' . ltrim( $path, '/' );
			}
			$path = preg_replace( '/[^A-Za-z0-9\/_\-.*$]/', '', $path );
			if ( '' !== $path && ! in_array( $path, $out, true ) ) {
				$out[] = $path;
			}
		}

		return $out;
	}

	/**
	 * Junta los predefinidos (código) con los overrides guardados (blocked +
	 * paths por "ua") y los bots custom (registro completo) en una sola
	 * lista, en el orden en que se pintan: predefinidos primero, custom
	 * detrás — igual que en la tabla.
	 */
	private static function merge_bots( $predefined_saved, $custom ) {
		$bots = array();

		foreach ( self::KNOWN_BOTS as $bot ) {
			$ua    = $bot['ua'];
			$saved = isset( $predefined_saved[ $ua ] ) ? $predefined_saved[ $ua ] : array();
			$bots[] = array(
				'ua'      => $ua,
				'company' => $bot['company'],
				'desc'    => $bot['desc'],
				'blocked' => array_key_exists( 'blocked', $saved ) ? (bool) $saved['blocked'] : $bot['blocked_default'],
				'paths'   => isset( $saved['paths'] ) && is_array( $saved['paths'] ) ? $saved['paths'] : array(),
				'custom'  => false,
			);
		}

		foreach ( (array) $custom as $c ) {
			if ( empty( $c['ua'] ) ) {
				continue;
			}
			$bots[] = array(
				'ua'      => $c['ua'],
				'company' => isset( $c['company'] ) && '' !== $c['company'] ? $c['company'] : '—',
				'desc'    => isset( $c['desc'] ) && '' !== $c['desc'] ? $c['desc'] : __( 'Añadido manualmente.', 'command-room' ),
				'blocked' => ! empty( $c['blocked'] ),
				'paths'   => isset( $c['paths'] ) && is_array( $c['paths'] ) ? $c['paths'] : array(),
				'custom'  => true,
			);
		}

		return $bots;
	}

	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		return array(
			'content'    => isset( $saved['content'] ) ? $saved['content'] : self::default_content(),
			'predefined' => isset( $saved['predefined'] ) && is_array( $saved['predefined'] ) ? $saved['predefined'] : array(),
			'custom'     => isset( $saved['custom'] ) && is_array( $saved['custom'] ) ? $saved['custom'] : array(),
		);
	}

	public static function get_bots() {
		$opts = self::get_options();
		return self::merge_bots( $opts['predefined'], $opts['custom'] );
	}

	/**
	 * Bloque "User-agent: X\nDisallow: /\nAllow: ..." (bloqueado, con
	 * excepciones permitidas) o "User-agent: X\nDisallow: ..." (abierto,
	 * bloqueado solo en ciertas rutas) por cada bot con reglas — uno por
	 * grupo, separados por una línea en blanco. Si ningún bot genera reglas
	 * (todos abiertos sin excepciones), devuelve un comentario informativo
	 * en vez de una cadena vacía.
	 */
	public static function build_block( $bots = null ) {
		if ( null === $bots ) {
			$bots = self::get_bots();
		}

		$groups = array();
		foreach ( $bots as $bot ) {
			$lines = array();
			if ( ! empty( $bot['blocked'] ) ) {
				$lines[] = 'User-agent: ' . $bot['ua'];
				$lines[] = 'Disallow: /';
				foreach ( $bot['paths'] as $path ) {
					$lines[] = 'Allow: ' . $path;
				}
			} elseif ( ! empty( $bot['paths'] ) ) {
				$lines[] = 'User-agent: ' . $bot['ua'];
				foreach ( $bot['paths'] as $path ) {
					$lines[] = 'Disallow: ' . $path;
				}
			}
			if ( $lines ) {
				$groups[] = implode( "\n", $lines );
			}
		}

		return $groups ? implode( "\n\n", $groups ) : '# ' . __( 'Ningún bot de IA bloqueado', 'command-room' );
	}

	private static function parse_bots_from_post( $rows ) {
		$known      = self::known_bots_index();
		$predefined = array();
		$custom     = array();
		$seen       = array();

		foreach ( (array) $rows as $row ) {
			$ua = isset( $row['ua'] ) ? trim( $row['ua'] ) : '';
			if ( '' === $ua || ! preg_match( '/^[A-Za-z0-9._\-\/ ]+$/', $ua ) ) {
				continue;
			}

			$key = strtolower( $ua );
			// Duplicado (sin distinguir mayúsculas): se ignora, ya sea un
			// custom repetido o un intento de suplantar a un predefinido.
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$blocked = ! empty( $row['blocked'] );
			$paths   = self::sanitize_paths( isset( $row['paths'] ) ? $row['paths'] : '' );

			if ( isset( $known[ $key ] ) ) {
				$predefined[ $known[ $key ]['ua'] ] = array( 'blocked' => $blocked, 'paths' => $paths );
			} else {
				$custom[] = array(
					'ua'      => $ua,
					'company' => isset( $row['company'] ) ? sanitize_text_field( $row['company'] ) : '',
					'desc'    => isset( $row['desc'] ) ? sanitize_text_field( $row['desc'] ) : '',
					'blocked' => $blocked,
					'paths'   => $paths,
				);
			}
		}

		return array( $predefined, $custom );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_robots' );

		$content = isset( $_POST['robots_content'] ) ? sanitize_textarea_field( wp_unslash( $_POST['robots_content'] ) ) : '';
		$result  = self::validate( $content );

		$posted_bots = isset( $_POST['bots'] ) && is_array( $_POST['bots'] ) ? wp_unslash( $_POST['bots'] ) : array();
		list( $predefined, $custom ) = self::parse_bots_from_post( $posted_bots );

		if ( ! empty( $result['errors'] ) ) {
			set_transient( 'cmdroom_robots_draft', array(
				'content'    => $content,
				'predefined' => $predefined,
				'custom'     => $custom,
			), 5 * MINUTE_IN_SECONDS );
			set_transient( 'cmdroom_robots_errors', $result, 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'cmdroom_robots_invalid', '1', wp_get_referer() ) );
			exit;
		}

		update_option( self::OPTION, array(
			'content'    => $content,
			'predefined' => $predefined,
			'custom'     => $custom,
		) );
		delete_transient( 'cmdroom_robots_draft' );
		delete_transient( 'cmdroom_robots_errors' );

		// LiteSpeed Cache guarda /robots.txt con el TTL por defecto (p. ej.
		// una semana): sin esto el cambio no se ve hasta que caduca.
		// Pasó el 2026-09-29 -- corregido el sitemap de dev en el robots,
		// seguía saliendo el viejo. Sin LiteSpeed activo no hace nada.
		do_action( 'litespeed_purge_url', home_url( '/robots.txt' ) );

		if ( ! empty( $result['warnings'] ) ) {
			set_transient( 'cmdroom_robots_errors', $result, 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( add_query_arg( 'cmdroom_saved_with_warnings', '1', wp_get_referer() ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	private static function default_content() {
		$lines = array( 'User-agent: *', 'Allow: /' );
		if ( class_exists( 'Cmdroom_Sitemap_Settings' ) && Cmdroom_Sitemap_Settings::is_live_output_enabled() ) {
			$lines[] = '';
			$lines[] = 'Sitemap: ' . home_url( '/sitemap_index.xml' );
		}
		return implode( "\n", $lines );
	}

	public static function render_page() {
		$draft = get_transient( 'cmdroom_robots_draft' );
		if ( false !== $draft ) {
			$content = $draft['content'];
			$bots    = self::merge_bots( $draft['predefined'], $draft['custom'] );
		} else {
			$opts    = self::get_options();
			$content = $opts['content'];
			$bots    = self::merge_bots( $opts['predefined'], $opts['custom'] );
		}
		$validation = get_transient( 'cmdroom_robots_errors' );
		?>
		<div class="wrap cmdroom-wrap cmdroom-metadata-wrap cmdroom-robots-wrap">

			<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
			<?php elseif ( isset( $_GET['cmdroom_saved_with_warnings'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Guardado — con avisos de sintaxis (revisa abajo).', 'command-room' ); ?></p></div>
			<?php elseif ( isset( $_GET['cmdroom_robots_invalid'] ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'No se ha guardado: hay líneas con sintaxis inválida en el contenido base. Corrígelas y vuelve a guardar (los bots no se han perdido).', 'command-room' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $validation && ! empty( $validation['errors'] ) ) : ?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Errores de sintaxis:', 'command-room' ); ?></strong></p>
					<ul style="list-style:disc;margin-left:1.5em;">
						<?php foreach ( $validation['errors'] as $err ) : ?>
							<li><?php echo esc_html( $err ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( $validation && ! empty( $validation['warnings'] ) ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Avisos:', 'command-room' ); ?></strong></p>
					<ul style="list-style:disc;margin-left:1.5em;">
						<?php foreach ( $validation['warnings'] as $warn ) : ?>
							<li><?php echo esc_html( $warn ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<h1 class="cmdroom-md-h1"><?php esc_html_e( 'Robots.txt', 'command-room' ); ?></h1>

			<div class="cmdroom-md-container">

				<?php if ( self::has_physical_file() ) : ?>
					<div class="notice notice-warning">
						<p>
							<strong><?php esc_html_e( 'Hay un robots.txt físico en el servidor', 'command-room' ); ?></strong> —
							<?php esc_html_e( 'el servidor lo sirve directamente y este control no tendrá efecto hasta que se borre o renombre ese archivo. No lo he tocado: pídemelo explícitamente cuando quieras que lo haga.', 'command-room' ); ?>
						</p>
					</div>
				<?php endif; ?>

				<p class="cmdroom-md-intro"><?php esc_html_e( 'Configura todas las reglas para bloquear archivos, carpetas, urls concretas o bots de IA:', 'command-room' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'cmdroom_save_robots' ); ?>
					<input type="hidden" name="action" value="cmdroom_save_robots" />

					<div class="cmdroom-md-block">
						<label class="cmdroom-md-block-label" for="cmdroom-robots-textarea"><?php esc_html_e( 'Contenido de /robots.txt', 'command-room' ); ?></label>
						<div class="cmdroom-md-terminal">
							<div class="cmdroom-md-terminal-bar">
								<span class="cmdroom-md-dot cmdroom-md-dot-red"></span>
								<span class="cmdroom-md-dot cmdroom-md-dot-amber"></span>
								<span class="cmdroom-md-dot cmdroom-md-dot-green"></span>
							</div>
							<textarea id="cmdroom-robots-textarea" name="robots_content" class="cmdroom-robots-textarea" spellcheck="false"><?php echo esc_textarea( $content ); ?></textarea>
							<div class="cmdroom-robots-auto">
								<span class="cmdroom-robots-auto-label"><?php esc_html_e( 'AÑADIDO AUTOMÁTICAMENTE · BOTS DE IA', 'command-room' ); ?></span>
								<pre class="cmdroom-robots-preview"><?php echo esc_html( self::build_block( $bots ) ); ?></pre>
							</div>
						</div>
					</div>

					<div class="cmdroom-robots-actions">
						<?php submit_button( __( 'Guardar', 'command-room' ), 'cmdroom-md-save', 'submit', false ); ?>
						<a class="cmdroom-robots-view-link" href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank"><?php printf( esc_html__( 'Ver %s ↗', 'command-room' ), esc_html( home_url( '/robots.txt' ) ) ); ?></a>
					</div>

					<div class="cmdroom-robots-section">
						<h2 class="cmdroom-robots-section-title"><?php esc_html_e( 'Bots de IA', 'command-room' ); ?></h2>
						<p class="cmdroom-robots-section-help"><?php esc_html_e( 'Marca los crawlers de IA que quieres bloquear. Cada uno se añade al final de /robots.txt como "Disallow: /" para su user-agent.', 'command-room' ); ?></p>

						<div class="cr-card cmdroom-robots-table-wrap">
							<table class="cmdroom-robots-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Bloquear', 'command-room' ); ?></th>
										<th><?php esc_html_e( 'User-agent', 'command-room' ); ?></th>
										<th><?php esc_html_e( 'Empresa', 'command-room' ); ?></th>
										<th><?php esc_html_e( 'Qué hace', 'command-room' ); ?></th>
										<th>
											<?php esc_html_e( 'Excepciones', 'command-room' ); ?>
											<span class="cmdroom-robots-th-help"><?php esc_html_e( 'Rutas separadas por coma. Bloqueado: rutas que sí puede rastrear. Abierto: rutas que no puede.', 'command-room' ); ?></span>
										</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $bots as $i => $bot ) : ?>
										<tr class="cmdroom-robots-row<?php echo $bot['blocked'] ? ' is-blocked' : ''; ?>" data-index="<?php echo (int) $i; ?>">
											<td><input type="checkbox" class="cmdroom-robots-blocked" name="bots[<?php echo (int) $i; ?>][blocked]" value="1" <?php checked( $bot['blocked'] ); ?> /></td>
											<td>
												<input type="hidden" class="cmdroom-robots-ua-value" name="bots[<?php echo (int) $i; ?>][ua]" value="<?php echo esc_attr( $bot['ua'] ); ?>" />
												<code class="cmdroom-robots-ua-text"><?php echo esc_html( $bot['ua'] ); ?></code>
											</td>
											<td class="cmdroom-robots-company-cell">
												<?php if ( $bot['custom'] ) : ?>
													<input type="hidden" name="bots[<?php echo (int) $i; ?>][company]" value="<?php echo esc_attr( $bot['company'] ); ?>" />
												<?php endif; ?>
												<span class="cmdroom-robots-company-text"><?php echo esc_html( $bot['company'] ); ?></span>
											</td>
											<td class="cmdroom-robots-desc-cell">
												<?php if ( $bot['custom'] ) : ?>
													<input type="hidden" name="bots[<?php echo (int) $i; ?>][desc]" value="<?php echo esc_attr( $bot['desc'] ); ?>" />
												<?php endif; ?>
												<span class="cmdroom-robots-desc-text"><?php echo esc_html( $bot['desc'] ); ?></span>
											</td>
											<td>
												<label class="cmdroom-robots-paths-label"><?php echo $bot['blocked'] ? esc_html__( 'Permitir solo en', 'command-room' ) : esc_html__( 'Bloquear solo en', 'command-room' ); ?></label>
												<input type="text" class="cmdroom-robots-paths" name="bots[<?php echo (int) $i; ?>][paths]" value="<?php echo esc_attr( implode( ', ', $bot['paths'] ) ); ?>" placeholder="<?php echo $bot['blocked'] ? esc_attr__( '/blog/, /guias/', 'command-room' ) : esc_attr__( '/area-clientes/, /privado/', 'command-room' ); ?>" />
											</td>
										</tr>
									<?php endforeach; ?>
									<tr class="cmdroom-robots-add-row">
										<td><button type="button" class="button button-primary cmdroom-robots-add-btn">+ <?php esc_html_e( 'Añadir', 'command-room' ); ?></button></td>
										<td><input type="text" class="cmdroom-robots-new-ua" placeholder="User-agent" /></td>
										<td><input type="text" class="cmdroom-robots-new-company" placeholder="<?php esc_attr_e( 'Empresa', 'command-room' ); ?>" /></td>
										<td><input type="text" class="cmdroom-robots-new-desc" placeholder="<?php esc_attr_e( 'Qué hace (opcional)', 'command-room' ); ?>" /></td>
										<td></td>
									</tr>
								</tbody>
							</table>
						</div>

						<template id="cmdroom-robots-row-template">
							<tr class="cmdroom-robots-row is-blocked" data-index="__INDEX__">
								<td><input type="checkbox" class="cmdroom-robots-blocked" name="bots[__INDEX__][blocked]" value="1" checked /></td>
								<td>
									<input type="hidden" class="cmdroom-robots-ua-value" name="bots[__INDEX__][ua]" value="" />
									<code class="cmdroom-robots-ua-text"></code>
								</td>
								<td class="cmdroom-robots-company-cell">
									<input type="hidden" class="cmdroom-robots-company-value" name="bots[__INDEX__][company]" value="" />
									<span class="cmdroom-robots-company-text"></span>
								</td>
								<td class="cmdroom-robots-desc-cell">
									<input type="hidden" class="cmdroom-robots-desc-value" name="bots[__INDEX__][desc]" value="" />
									<span class="cmdroom-robots-desc-text"></span>
								</td>
								<td>
									<label class="cmdroom-robots-paths-label"><?php esc_html_e( 'Permitir solo en', 'command-room' ); ?></label>
									<input type="text" class="cmdroom-robots-paths" name="bots[__INDEX__][paths]" value="" placeholder="<?php esc_attr_e( '/blog/, /guias/', 'command-room' ); ?>" />
								</td>
							</tr>
						</template>
					</div>

				</form>

				<div class="cmdroom-md-footer">
					<h2 class="cmdroom-md-footer-title"><?php esc_html_e( 'Más información', 'command-room' ); ?></h2>
					<p class="cmdroom-md-footer-text"><?php esc_html_e( 'El bloque de Bots de IA se añade automáticamente al final de este contenido en /robots.txt — no hace falta escribirlo aquí a mano.', 'command-room' ); ?></p>
				</div>

			</div>
		</div>
		<?php
	}
}
