<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventario real de scripts JS y hojas de estilo CSS del sitio — pestaña
 * "Listado" de "Código" (petición del administrador 2026-09-25: antes de decidir
 * qué limpiar hay que ver qué se carga de verdad y dónde, no una lista
 * fija). Escaneo automático de verdad: pide por HTTP el HTML real servido
 * en 5 tipos de página representativos (mismos grupos que Metas/CrUX:
 * home/contenido/corporativas/categorias/tags, una URL por grupo) y
 * parsea `<script>`, `<link rel="stylesheet">` y `<style>` con regex sobre
 * el HTML devuelto — detecta lo que WordPress, el tema y cualquier plugin
 * imprimen de verdad, externos o inline, no solo lo que sabe Command Room.
 *
 * Limitaciones a propósito, documentadas para no venderlas como más de lo
 * que son:
 * - Solo ve lo que se imprime en los 5 tipos de página escaneados — un
 *   script que solo cargue en búsqueda, carrito o una plantilla no
 *   muestreada no aparece.
 * - No ejecuta el JS ni ve lo que un script añade al DOM después de
 *   cargar (p. ej. un tag manager que inyecta más scripts en runtime).
 * - La "descripción" es una aproximación por patrones conocidos (core de
 *   WordPress, plugins por su ruta, servicios habituales tipo GA/GTM) —
 *   lo que no reconoce se marca explícitamente como "sin identificar".
 *
 * Caché de 24h (misma TTL que Auditoría/CrUX) con botón "Actualizar
 * escaneo" -- 5 peticiones HTTP en cada carga de la pantalla sería
 * demasiado lento.
 */
class Cmdroom_Code_Assets {

	const CACHE_KEY   = 'cmdroom_code_assets_scan';
	const CACHE_TTL   = DAY_IN_SECONDS;
	const OPTION_AUTH = 'cmdroom_code_assets_httpauth';

	// Mismos 5 grupos que Cmdroom_Crux_Dashboard/Metas -- una sola URL
	// representativa por grupo (no hasta 8 como CrUX: aquí solo hace falta
	// saber en qué tipos de página aparece cada asset, no promediar nada).
	const GROUP_LABELS = array(
		'home'         => 'Home',
		'contenido'    => 'Entrada',
		'corporativas' => 'Página',
		'categorias'   => 'Categoría',
		'tags'         => 'Tag',
	);

	const JS_PATTERNS = array(
		'#/wp-includes/js/jquery/jquery-migrate#i' => 'jQuery Migrate — compatibilidad de jQuery con plugins antiguos.',
		'#/wp-includes/js/jquery/jquery#i'         => 'jQuery — librería base de WordPress.',
		'#/wp-includes/js/wp-embed#i'              => 'Embeds de WordPress (oEmbed) para insertar contenido de otros sitios.',
		'#/wp-includes/js/dist/#i'                 => 'Script del núcleo de WordPress (bloques, barra de administración, heartbeat...).',
		'#googletagmanager\.com/gtag/js#i'         => 'Google Analytics / Google Tag Manager (gtag.js).',
		'#googletagmanager\.com/gtm\.js#i'         => 'Google Tag Manager (contenedor GTM).',
		'#google-analytics\.com#i'                 => 'Google Analytics (analytics.js/ga.js, formato antiguo).',
		'#connect\.facebook\.net#i'                => 'Meta Pixel / SDK de Facebook.',
		'#static\.hotjar\.com#i'                   => 'Hotjar — grabación de sesiones y mapas de calor.',
		'#cdn\.jsdelivr\.net#i'                    => 'Librería servida desde jsDelivr (CDN público).',
		'#cdnjs\.cloudflare\.com#i'                => 'Librería servida desde cdnjs (CDN público).',
	);

	const CSS_PATTERNS = array(
		'#fonts\.googleapis\.com#i' => 'Google Fonts — tipografías cargadas desde Google.',
		'#/wp-includes/css/#i'      => 'Hoja de estilos del núcleo de WordPress.',
	);

	// Patrones sobre el CONTENIDO de scripts inline (sin src) -- solo para
	// JS, un bloque de <style> inline no suele delatar su origen igual.
	const JS_CONTENT_PATTERNS = array(
		'/\bgtag\s*\(/i'             => 'Google Analytics / Google Tag Manager (gtag.js) — configuración inline.',
		'/dataLayer\s*=/i'           => 'Google Tag Manager — inicialización de dataLayer.',
		'/\bfbq\s*\(/i'              => 'Meta Pixel — evento o inicialización inline.',
		'/_wpemojiSettings/i'        => 'Emoji nativos de WordPress (wp-emoji-release).',
		'/googlesitekit/i'           => 'Site Kit by Google — configuración inline.',
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_refresh_code_assets', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_post_cmdroom_save_code_assets_auth', array( __CLASS__, 'handle_save_auth' ) );
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_refresh_code_assets' );
		delete_transient( self::CACHE_KEY );
		wp_safe_redirect( add_query_arg( 'cmdroom_assets_refreshed', '1', wp_get_referer() ) );
		exit;
	}

	public static function handle_save_auth() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_code_assets_auth' );

		$user = isset( $_POST['cmdroom_httpauth_user'] ) ? sanitize_text_field( wp_unslash( $_POST['cmdroom_httpauth_user'] ) ) : '';
		$pass = isset( $_POST['cmdroom_httpauth_pass'] ) ? wp_unslash( $_POST['cmdroom_httpauth_pass'] ) : '';
		// La contraseña guardada nunca se imprime en el formulario: campo
		// vacío = conservar la actual. Vaciar el usuario borra las dos.
		if ( '' === $pass && '' !== $user ) {
			$prev = get_option( self::OPTION_AUTH, array() );
			$pass = isset( $prev['pass'] ) ? $prev['pass'] : '';
		}
		$auth = array(
			'user' => $user,
			'pass' => '' === $user ? '' : $pass,
		);
		update_option( self::OPTION_AUTH, $auth );
		// Credenciales nuevas -- el escaneo cacheado pudo haber fallado por
		// esto, se descarta para que "Guardar" ya dispare un reintento.
		delete_transient( self::CACHE_KEY );

		wp_safe_redirect( add_query_arg( 'cmdroom_assets_auth_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function get_inventory() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}
		$result = self::scan();
		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	private static function scan() {
		$js        = array();
		$css       = array();
		$scanned   = array();
		$failed    = array();
		$injection = class_exists( 'Cmdroom_Code_Injection' ) ? Cmdroom_Code_Injection::get_options() : array( 'head' => '', 'footer' => '' );

		foreach ( self::build_group_urls() as $group => $url ) {
			$response = wp_remote_get( $url, self::request_args() );

			if ( is_wp_error( $response ) ) {
				$failed[ $group ] = $response->get_error_message();
				continue;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				$failed[ $group ] = sprintf( 'HTTP %d', $code );
				continue;
			}

			$html = wp_remote_retrieve_body( $response );
			self::parse_html( $html, $group, $injection, $js, $css );
			$scanned[] = $group;
		}

		return array(
			'scanned_at'     => $scanned ? current_time( 'timestamp' ) : 0,
			'groups_scanned' => $scanned,
			'groups_failed'  => $failed,
			'js'             => array_values( $js ),
			'css'            => array_values( $css ),
		);
	}

	private static function request_args() {
		$args = array(
			'timeout'    => 20,
			'redirection' => 3,
		);
		$auth = get_option( self::OPTION_AUTH, array() );
		if ( ! empty( $auth['user'] ) ) {
			$args['headers'] = array(
				'Authorization' => 'Basic ' . base64_encode( $auth['user'] . ':' . ( isset( $auth['pass'] ) ? $auth['pass'] : '' ) ),
			);
		}
		return $args;
	}

	/**
	 * Una URL representativa por grupo -- home siempre, el resto solo si
	 * el sitio tiene contenido de ese tipo (un sitio sin tags, por
	 * ejemplo, simplemente no aporta ese grupo al escaneo).
	 */
	private static function build_group_urls() {
		$groups = array( 'home' => home_url( '/' ) );

		$content_types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'page', 'attachment' ) ) );
		if ( $content_types ) {
			$recent = get_posts( array(
				'post_type'      => $content_types,
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			) );
			if ( $recent ) {
				$groups['contenido'] = get_permalink( $recent[0] );
			}
		}

		$pages = get_posts( array(
			'post_type'      => 'page',
			'posts_per_page' => 1,
			'post_status'    => 'publish',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		) );
		if ( $pages ) {
			$groups['corporativas'] = get_permalink( $pages[0] );
		}

		$cats = get_categories( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 1 ) );
		if ( $cats ) {
			$link = get_term_link( $cats[0] );
			if ( ! is_wp_error( $link ) ) {
				$groups['categorias'] = $link;
			}
		}

		$tags = get_tags( array( 'orderby' => 'count', 'order' => 'DESC', 'number' => 1 ) );
		if ( $tags ) {
			$link = get_term_link( $tags[0] );
			if ( ! is_wp_error( $link ) ) {
				$groups['tags'] = $link;
			}
		}

		return $groups;
	}

	private static function parse_html( $html, $group, $injection, &$js, &$css ) {
		$head_end = strpos( $html, '</head>' );
		if ( false === $head_end ) {
			$head_end = PHP_INT_MAX;
		}

		// <script> -- externo (src) o inline (contenido). Se ignoran los
		// scripts con un type que no sea JS ejecutable (application/ld+json
		// del propio schema de Command Room, application/json de Ticker...)
		// -- no son "scripts que hacen algo", son datos.
		if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$attrs  = $match[1][0];
				$offset = $match[0][1];
				$inner  = trim( $match[2][0] );
				$type   = strtolower( self::attr( $attrs, 'type' ) );

				if ( $type && ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module' ), true ) ) {
					continue;
				}

				$src      = self::attr( $attrs, 'src' );
				$position = $offset < $head_end ? 'head' : 'footer';

				if ( $src ) {
					$key = 'ext:' . self::normalize_key( $src );
					if ( ! isset( $js[ $key ] ) ) {
						$js[ $key ] = array(
							'type'        => 'external',
							'src'         => $src,
							'excerpt'     => '',
							'description' => self::describe_asset( $src, '', $injection, false ),
							'position'    => $position,
							'groups'      => array(),
						);
					}
					$js[ $key ]['groups'][ $group ] = true;
				} elseif ( '' !== $inner ) {
					$key = 'inline:' . md5( $inner );
					if ( ! isset( $js[ $key ] ) ) {
						$js[ $key ] = array(
							'type'        => 'inline',
							'src'         => null,
							'excerpt'     => self::excerpt( $inner ),
							'description' => self::describe_asset( '', $inner, $injection, false ),
							'position'    => $position,
							'groups'      => array(),
						);
					}
					$js[ $key ]['groups'][ $group ] = true;
				}
			}
		}

		// <link rel="stylesheet"> -- solo hojas reales, no preload/prefetch.
		if ( preg_match_all( '/<link\b([^>]*)\/?>/i', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$attrs = $match[1][0];
				$rel   = strtolower( self::attr( $attrs, 'rel' ) );
				if ( false === strpos( $rel, 'stylesheet' ) ) {
					continue;
				}
				$href = self::attr( $attrs, 'href' );
				if ( ! $href ) {
					continue;
				}
				$offset   = $match[0][1];
				$position = $offset < $head_end ? 'head' : 'footer';
				$key      = 'ext:' . self::normalize_key( $href );
				if ( ! isset( $css[ $key ] ) ) {
					$css[ $key ] = array(
						'type'        => 'external',
						'src'         => $href,
						'excerpt'     => '',
						'description' => self::describe_asset( $href, '', $injection, true ),
						'position'    => $position,
						'groups'      => array(),
					);
				}
				$css[ $key ]['groups'][ $group ] = true;
			}
		}

		// <style> inline.
		if ( preg_match_all( '/<style\b([^>]*)>(.*?)<\/style>/is', $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$inner = trim( $match[2][0] );
				if ( '' === $inner ) {
					continue;
				}
				$offset   = $match[0][1];
				$position = $offset < $head_end ? 'head' : 'footer';
				$key      = 'inline:' . md5( $inner );
				if ( ! isset( $css[ $key ] ) ) {
					$css[ $key ] = array(
						'type'        => 'inline',
						'src'         => null,
						'excerpt'     => self::excerpt( $inner ),
						'description' => self::describe_asset( '', $inner, $injection, true ),
						'position'    => $position,
						'groups'      => array(),
					);
				}
				$css[ $key ]['groups'][ $group ] = true;
			}
		}
	}

	/**
	 * \b no basta para el límite izquierdo del nombre de atributo: "-" es
	 * no-palabra y "s" es palabra, así que \bsrc también casaría dentro de
	 * "data-src" (habitual en lazy-load) y devolvería ese valor en vez de
	 * "sin src" -- con un lookbehind a inicio de cadena o espacio se evita.
	 */
	private static function attr( $attr_string, $name ) {
		if ( preg_match( '/(?<=^|\s)' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $attr_string, $m ) ) {
			return html_entity_decode( $m[2], ENT_QUOTES );
		}
		return '';
	}

	private static function normalize_key( $src ) {
		$src = remove_query_arg( 'ver', (string) $src );
		return untrailingslashit( $src );
	}

	private static function excerpt( $content ) {
		$flat = preg_replace( '/\s+/', ' ', $content );
		return mb_strlen( $flat ) > 90 ? mb_substr( $flat, 0, 90 ) . '…' : $flat;
	}

	/**
	 * Descripción por mejor esfuerzo: primero si el propio Command Room lo
	 * insertó (pestaña Inyección), luego patrones de servicios conocidos,
	 * luego de dónde lo carga (plugin/tema por su ruta), y si nada
	 * coincide se marca explícitamente como sin identificar en vez de
	 * inventar una descripción.
	 */
	private static function describe_asset( $src, $content, $injection, $is_css ) {
		$needle = $src ? $src : $content;
		if ( $needle
			&& ( ( ! empty( $injection['head'] ) && false !== strpos( $injection['head'], $needle ) )
				|| ( ! empty( $injection['footer'] ) && false !== strpos( $injection['footer'], $needle ) ) )
		) {
			return __( 'Insertado a mano desde Código → Inyección.', 'command-room' );
		}

		if ( $src ) {
			$patterns = $is_css ? self::CSS_PATTERNS : self::JS_PATTERNS;
			foreach ( $patterns as $regex => $label ) {
				if ( preg_match( $regex, $src ) ) {
					return $label;
				}
			}
			if ( preg_match( '#/wp-content/plugins/([^/]+)/#', $src, $m ) ) {
				return sprintf( __( 'Cargado por el plugin «%s».', 'command-room' ), $m[1] );
			}
			if ( preg_match( '#/wp-content/themes/([^/]+)/#', $src, $m ) ) {
				return sprintf( __( 'Cargado por el tema «%s».', 'command-room' ), $m[1] );
			}
			$host = wp_parse_url( $src, PHP_URL_HOST );
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $host && $host !== $home_host ) {
				return sprintf( __( 'Recurso externo desde %s — revisar manualmente para qué sirve.', 'command-room' ), $host );
			}
			return __( 'Recurso propio del sitio — revisar el código para identificar su función.', 'command-room' );
		}

		if ( ! $is_css ) {
			foreach ( self::JS_CONTENT_PATTERNS as $regex => $label ) {
				if ( preg_match( $regex, $content ) ) {
					return $label;
				}
			}
		}

		return $is_css
			? __( 'Bloque de estilos insertado directamente en el HTML — origen sin identificar automáticamente.', 'command-room' )
			: __( 'Bloque de código insertado directamente en el HTML — origen sin identificar automáticamente.', 'command-room' );
	}

	// ---- Render ----

	public static function render_tab() {
		if ( isset( $_GET['cmdroom_assets_refreshed'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Escaneo actualizado.', 'command-room' ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['cmdroom_assets_auth_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Credenciales guardadas.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<p class="cmdroom-md-intro"><?php esc_html_e( 'Revisa el código fuente y lo que podría impactar al rendimiento o medición de tu web:', 'command-room' ); ?></p>

		<?php self::render_auth_form(); ?>

		<?php $inventory = self::get_inventory(); ?>

		<div class="cmdroom-code-scan-meta">
			<span>
				<?php
				if ( $inventory['scanned_at'] ) {
					printf( esc_html__( 'Último escaneo: %s', 'command-room' ), esc_html( wp_date( 'd/m/Y H:i', $inventory['scanned_at'] ) ) );
				} else {
					esc_html_e( 'Sin escanear todavía.', 'command-room' );
				}
				?>
			</span>
			<?php if ( ! empty( $inventory['groups_scanned'] ) ) : ?>
				<span><?php printf( esc_html__( 'Leído: %s', 'command-room' ), esc_html( self::labels_for( $inventory['groups_scanned'] ) ) ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $inventory['groups_failed'] ) ) : ?>
				<span class="cmdroom-code-scan-error">
					<?php foreach ( $inventory['groups_failed'] as $group => $reason ) : ?>
						<?php printf( esc_html__( '%1$s no se pudo leer (%2$s). ', 'command-room' ), esc_html( self::GROUP_LABELS[ $group ] ?? $group ), esc_html( $reason ) ); ?>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cmdroom_refresh_code_assets' ); ?>
				<input type="hidden" name="action" value="cmdroom_refresh_code_assets" />
				<button type="submit" class="cr-btn-secondary cr-btn-compact"><?php esc_html_e( 'Actualizar escaneo', 'command-room' ); ?></button>
			</form>
		</div>

		<div class="cr-card cmdroom-code-card">
			<h2><?php esc_html_e( 'Scripts JS', 'command-room' ); ?></h2>
			<?php self::render_table( $inventory['js'], $inventory['groups_scanned'], false ); ?>
		</div>

		<div class="cr-card cmdroom-code-card">
			<h2><?php esc_html_e( 'Hojas de estilo CSS', 'command-room' ); ?></h2>
			<?php self::render_table( $inventory['css'], $inventory['groups_scanned'], true ); ?>
		</div>
		<?php
	}

	private static function render_auth_form() {
		$auth = get_option( self::OPTION_AUTH, array( 'user' => '', 'pass' => '' ) );
		?>
		<details class="cmdroom-md-vars cmdroom-code-auth">
			<summary><?php esc_html_e( 'Autenticación HTTP básica para el escaneo (solo si el sitio está protegido, p. ej. en staging)', 'command-room' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmdroom-code-auth-form">
				<?php wp_nonce_field( 'cmdroom_save_code_assets_auth' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_code_assets_auth" />
				<span>
					<label class="cr-label" for="cmdroom_httpauth_user"><?php esc_html_e( 'Usuario', 'command-room' ); ?></label>
					<input type="text" id="cmdroom_httpauth_user" name="cmdroom_httpauth_user" class="cr-input" value="<?php echo esc_attr( $auth['user'] ); ?>" autocomplete="off" />
				</span>
				<span>
					<label class="cr-label" for="cmdroom_httpauth_pass"><?php esc_html_e( 'Contraseña', 'command-room' ); ?></label>
					<input type="password" id="cmdroom_httpauth_pass" name="cmdroom_httpauth_pass" class="cr-input" value="" placeholder="<?php echo '' !== $auth['pass'] ? esc_attr__( '•••••••• (guardada — déjalo vacío para conservarla)', 'command-room' ) : ''; ?>" autocomplete="new-password" />
				</span>
				<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-secondary cr-btn-compact', 'submit', false ); ?>
			</form>
		</details>
		<?php
	}

	private static function render_table( $items, $scanned_groups, $is_css ) {
		if ( empty( $items ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html( $is_css
					? __( 'No se ha detectado ninguna hoja de estilos en el último escaneo.', 'command-room' )
					: __( 'No se ha detectado ningún script en el último escaneo.', 'command-room' ) )
			);
			return;
		}

		usort( $items, function ( $a, $b ) {
			return count( $b['groups'] ) <=> count( $a['groups'] );
		} );
		?>
		<div class="cr-table-wrap">
			<table class="cr-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Origen', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Descripción', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Posición', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Dónde está activo', 'command-room' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $items as $item ) : ?>
						<tr>
							<td>
								<?php if ( 'external' === $item['type'] ) : ?>
									<a href="<?php echo esc_url( $item['src'] ); ?>" target="_blank" rel="noreferrer" class="cmdroom-code-src"><?php echo esc_html( self::short_src( $item['src'] ) ); ?></a>
								<?php else : ?>
									<span class="cr-chip"><?php esc_html_e( 'Inline', 'command-room' ); ?></span>
									<code class="cmdroom-code-excerpt"><?php echo esc_html( $item['excerpt'] ); ?></code>
								<?php endif; ?>
							</td>
							<td class="cmdroom-code-desc"><?php echo esc_html( $item['description'] ); ?></td>
							<td><?php echo 'head' === $item['position'] ? esc_html__( 'head', 'command-room' ) : esc_html__( 'footer/body', 'command-room' ); ?></td>
							<td><?php self::render_scope_pill( $item['groups'], $scanned_groups ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_scope_pill( $groups, $scanned ) {
		$seen = array_keys( $groups );
		if ( $scanned && count( array_intersect( $scanned, $seen ) ) === count( $scanned ) ) {
			echo '<span class="cr-pill cr-pill-sitewide">' . esc_html__( 'Todo el sitio', 'command-room' ) . '</span>';
			return;
		}
		$labels = array_map( function ( $g ) {
			return isset( self::GROUP_LABELS[ $g ] ) ? self::GROUP_LABELS[ $g ] : $g;
		}, $seen );
		echo '<span class="cr-pill cr-pill-scoped">' . esc_html( implode( ', ', $labels ) ) . '</span>';
	}

	private static function short_src( $src ) {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host      = wp_parse_url( $src, PHP_URL_HOST );
		if ( $host === $home_host ) {
			$path = wp_parse_url( $src, PHP_URL_PATH );
			return $path ? $path : $src;
		}
		return $src;
	}

	private static function labels_for( $groups ) {
		$labels = array_map( function ( $g ) {
			return isset( self::GROUP_LABELS[ $g ] ) ? self::GROUP_LABELS[ $g ] : $g;
		}, $groups );
		return implode( ', ', $labels );
	}
}
