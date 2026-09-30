<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definiciones de sitemap: cada una es "un tipo de contenido, opcionalmente
 * acotado por taxonomía/términos, con un límite de URLs". Así el administrador monta
 * un sitemap de blog, otro de transaccionales (categoría con varios
 * términos), otro de páginas corporativas, etc. — sin tocar código.
 *
 * Desde el rediseño de Claude Design cada definición lleva además:
 *  - 'lang': idioma de Google News PROPIO de esa definición (antes era un
 *    único idioma global para todo el sitio -- no tenía sentido si dos
 *    sitemaps News apuntan a audiencias distintas).
 *  - 'videos': igual que 'images' pero para <video:video>.
 *  - 'vars': de qué variable sale cada campo del XML (título, fechas,
 *    imagen...) -- ver VAR_OPTIONS/VAR_DEFAULTS y
 *    Cmdroom_Sitemap_Render::resolve_var().
 */
class Cmdroom_Sitemap_Settings {

	const OPTION = 'cmdroom_sitemap_options';

	/**
	 * Opciones válidas por campo del panel "Variables" -- un select por
	 * fila, nunca texto libre, para no tener que validar plantillas
	 * arbitrarias en un campo pensado para un puñado de tokens conocidos.
	 */
	const VAR_OPTIONS = array(
		'title'       => array( '%title%', '%schema_headline%', '%term_title%', '%sitename%' ),
		'pub_date'    => array( '%schema_date_published%', '%schema_date_modified%', '%date%' ),
		'lastmod'     => array( '%schema_date_modified%', '%schema_date_published%' ),
		'image'       => array( '%image%', '%schema_image%', '%og_image%' ),
		'image_title' => array( '%title%', '%schema_headline%', '%excerpt%', '' ),
	);

	const VAR_DEFAULTS = array(
		'title'       => '%title%',
		'pub_date'    => '%schema_date_published%',
		'lastmod'     => '%schema_date_modified%',
		'image'       => '%image%',
		'image_title' => '%title%',
	);

	const VAR_LABELS = array(
		'title'       => 'Título',
		'pub_date'    => 'Fecha de publicación',
		'lastmod'     => 'Última modificación',
		'image'       => 'Imagen',
		'image_title' => 'Título de imagen',
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_sitemap_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cmdroom_save_sitemap_general', array( __CLASS__, 'handle_save_general' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * URL definitiva del sitemap, sin parámetros -- pedido por el administrador
	 * 2026-09-24 ("necesito tener la URL y poder abrirlo"; el intento
	 * anterior con ?cmdroom_preview=1 para verla sin activar "Salida en el
	 * sitio" tampoco le valía, quería la URL de verdad o nada). Solo
	 * funciona cuando "Salida en el sitio" está encendida -- si está
	 * apagada, esta misma URL la sigue sirviendo Rank Math (o 404, si no
	 * hay nada) y eso es correcto, no un fallo de esta función.
	 */
	public static function preview_url( $slug ) {
		$file = 'index' === $slug ? 'sitemap_index.xml' : 'sitemap-' . $slug . '.xml';
		return home_url( '/' . $file );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || 'cmdroom-sitemaps' !== $_GET['page'] ) {
			return;
		}
		wp_enqueue_style( 'cmdroom-meta-editor', CMDROOM_URL . 'assets/css/meta-editor.css', array(), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-sitemaps-editor', CMDROOM_URL . 'assets/css/sitemaps-editor.css', array( 'cmdroom-meta-editor' ), CMDROOM_VERSION );
		wp_enqueue_script( 'cmdroom-sitemaps-editor', CMDROOM_URL . 'assets/js/sitemaps-editor.js', array(), CMDROOM_VERSION, true );
	}

	public static function is_live_output_enabled() {
		$opts = self::get_options();
		return ! empty( $opts['live_output'] );
	}

	public static function get_options() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::defaults() );
	}

	public static function get_enabled_definitions() {
		$opts = self::get_options();
		return array_filter( $opts['definitions'], function ( $def ) use ( $opts ) {
			if ( empty( $def['enabled'] ) || empty( $def['slug'] ) ) {
				return false;
			}
			// Filtro global de post types/taxonomías: aunque una definición
			// esté activa, si su tipo de contenido/taxonomía está excluido
			// globalmente no participa en el índice.
			if ( 'terms' === ( $def['source'] ?? 'posts' ) ) {
				if ( ! empty( $def['taxonomy'] ) && isset( $opts['taxonomies_enabled'][ $def['taxonomy'] ] ) && ! $opts['taxonomies_enabled'][ $def['taxonomy'] ] ) {
					return false;
				}
			} else {
				if ( ! empty( $def['post_type'] ) && isset( $opts['post_types_enabled'][ $def['post_type'] ] ) && ! $opts['post_types_enabled'][ $def['post_type'] ] ) {
					return false;
				}
			}
			return true;
		} );
	}

	public static function get_definition( $slug ) {
		foreach ( self::get_enabled_definitions() as $def ) {
			if ( $def['slug'] === $slug ) {
				return $def;
			}
		}
		return null;
	}

	private static function defaults() {
		$post_types_enabled = array();
		foreach ( self::public_post_types_for_toggle() as $pt ) {
			$post_types_enabled[ $pt->name ] = true;
		}

		$taxonomies_enabled = array();
		foreach ( Cmdroom_Meta_Settings::public_taxonomies() as $tax ) {
			$taxonomies_enabled[ $tax->name ] = true;
		}

		return array(
			'live_output'           => false,
			'news_publication_name' => get_bloginfo( 'name' ),
			'post_types_enabled'    => $post_types_enabled,
			'taxonomies_enabled'    => $taxonomies_enabled,
			'definitions'           => array(
				self::default_definition( 'paginas', 'Páginas', 'page' ),
				self::default_definition( 'blog', 'Blog', 'post' ),
			),
		);
	}

	private static function default_definition( $slug, $label, $post_type ) {
		return array(
			'slug'      => $slug,
			'label'     => $label,
			'enabled'   => true,
			'source'    => 'posts',
			'format'    => 'standard',
			'post_type' => $post_type,
			'taxonomy'  => '',
			'terms'     => '',
			'lang'      => substr( get_bloginfo( 'language' ), 0, 2 ) ?: 'es',
			'limit'     => 1000,
			'images'    => false,
			'videos'    => false,
			'vars'      => self::VAR_DEFAULTS,
		);
	}

	public static function public_post_types_for_toggle() {
		return array_filter( Cmdroom_Meta_Settings::public_post_types(), function ( $pt ) {
			return 'attachment' !== $pt->name;
		} );
	}

	public static function get_news_publication_name() {
		$opts = self::get_options();
		return $opts['news_publication_name'];
	}

	public static function handle_save_general() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_sitemap_general' );

		$opts                = self::get_options();
		$opts['live_output'] = ! empty( $_POST['live_output'] );

		update_option( self::OPTION, $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * "Activar sitemaps" + vista previa -- viven en Command Room → General
	 * desde el rediseño de esta pantalla, mismo motivo que Metas/Datos
	 * estructurados: la pantalla propia ya no los incluye.
	 */
	public static function render_general_section() {
		$opts = self::get_options();
		?>
		<h2><?php esc_html_e( 'Sitemaps', 'command-room' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmdroom_save_sitemap_general' ); ?>
			<input type="hidden" name="action" value="cmdroom_save_sitemap_general" />
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Salida en el sitio', 'command-room' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="live_output" value="1" <?php checked( $opts['live_output'] ); ?> />
							<?php esc_html_e( 'Activar /sitemap_index.xml y /sitemap-{slug}.xml (déjalo apagado mientras comparas contra Rank Math)', 'command-room' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Guardar', 'command-room' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Tabla de referencia con la URL definitiva de cada sitemap -- vivía
	 * pegada al toggle de "Salida en el sitio" (arriba, en
	 * render_general_section()) porque antes las dos cosas se veían en el
	 * mismo sitio. Desde que "Salida en el sitio" se unificó dentro de
	 * Configuración → Herramientas (2026-09-25) esta tabla se queda aquí,
	 * en Sitemaps → Configuración, que es donde el administrador la espera de verdad
	 * al estar editando las definiciones. Llamada desde render_page().
	 */
	public static function render_urls_table() {
		?>
		<h3 style="margin-top:32px;"><?php esc_html_e( 'URLs de los sitemaps', 'command-room' ); ?></h3>
		<p class="description"><?php esc_html_e( 'La URL definitiva de cada sitemap. Solo responde con el XML de Command Room mientras "Salida en el sitio" esté activada en Configuración → Herramientas -- si está apagada, esta misma URL la sigue sirviendo Rank Math (o da 404 si no hay nada).', 'command-room' ); ?></p>
		<table class="widefat striped" style="max-width:600px;margin-top:8px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Sitemap', 'command-room' ); ?></th>
					<th><?php esc_html_e( 'URL', 'command-room' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$rows = array( array( 'slug' => 'index', 'label' => __( 'Índice', 'command-room' ) ) );
				foreach ( self::get_enabled_definitions() as $def ) {
					$rows[] = array( 'slug' => $def['slug'], 'label' => $def['label'] );
				}
				foreach ( $rows as $row ) :
					$url = self::preview_url( $row['slug'] );
					?>
					<tr>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( $url ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Valida un token de %variable% contra la lista blanca del campo -- si
	 * lo que llega en el POST no es una opción real del select (manipulado
	 * a mano, o un campo desconocido), cae al valor por defecto de ese
	 * campo en vez de guardar basura.
	 */
	private static function sanitize_var( $field, $value ) {
		$options = isset( self::VAR_OPTIONS[ $field ] ) ? self::VAR_OPTIONS[ $field ] : array();
		return in_array( $value, $options, true ) ? $value : self::VAR_DEFAULTS[ $field ];
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_sitemap_settings' );

		// Parte de las opciones ya guardadas: este formulario ya no incluye
		// "Salida en el sitio" (se movió a General, ver
		// handle_save_general()) -- arrancar de defaults() lo resetearía a
		// false en cada guardado de esta pantalla.
		$opts = self::get_options();

		$opts['news_publication_name'] = isset( $_POST['news_publication_name'] ) ? sanitize_text_field( wp_unslash( $_POST['news_publication_name'] ) ) : get_bloginfo( 'name' );
		$opts['post_types_enabled']    = array();
		$opts['taxonomies_enabled']    = array();
		$opts['definitions']           = array();

		$posted_pt = isset( $_POST['post_types_enabled'] ) && is_array( $_POST['post_types_enabled'] ) ? wp_unslash( $_POST['post_types_enabled'] ) : array();
		foreach ( self::public_post_types_for_toggle() as $pt ) {
			$opts['post_types_enabled'][ $pt->name ] = in_array( $pt->name, $posted_pt, true );
		}

		$posted_tax = isset( $_POST['taxonomies_enabled'] ) && is_array( $_POST['taxonomies_enabled'] ) ? wp_unslash( $_POST['taxonomies_enabled'] ) : array();
		foreach ( Cmdroom_Meta_Settings::public_taxonomies() as $tax ) {
			$opts['taxonomies_enabled'][ $tax->name ] = in_array( $tax->name, $posted_tax, true );
		}

		$rows = isset( $_POST['definitions'] ) && is_array( $_POST['definitions'] ) ? wp_unslash( $_POST['definitions'] ) : array();

		foreach ( $rows as $row ) {
			$slug = isset( $row['slug'] ) ? sanitize_title( $row['slug'] ) : '';
			// Fila vacía o slug reservado: se descarta -- así también se
			// "borra" una definición existente quitándole el slug.
			if ( '' === $slug || 'index' === $slug ) {
				continue;
			}

			$posted_vars = isset( $row['vars'] ) && is_array( $row['vars'] ) ? $row['vars'] : array();
			$vars        = array();
			foreach ( array_keys( self::VAR_DEFAULTS ) as $field ) {
				$vars[ $field ] = self::sanitize_var( $field, isset( $posted_vars[ $field ] ) ? $posted_vars[ $field ] : '' );
			}

			$opts['definitions'][] = array(
				'slug'      => $slug,
				'label'     => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : $slug,
				'enabled'   => ! empty( $row['enabled'] ),
				'source'    => ( isset( $row['source'] ) && 'terms' === $row['source'] ) ? 'terms' : 'posts',
				'format'    => ( isset( $row['format'] ) && 'news' === $row['format'] ) ? 'news' : 'standard',
				'post_type' => isset( $row['post_type'] ) ? sanitize_key( $row['post_type'] ) : 'post',
				'taxonomy'  => isset( $row['taxonomy'] ) ? sanitize_key( $row['taxonomy'] ) : '',
				'terms'     => isset( $row['terms'] ) ? sanitize_text_field( $row['terms'] ) : '',
				// Código ISO 639 corto (es, en, zh-cn...) -- sin validar la
				// lista completa de códigos, solo forma y longitud razonable.
				'lang'      => isset( $row['lang'] ) && preg_match( '/^[a-z]{2}(-[a-z]{2})?$/i', trim( $row['lang'] ) ) ? strtolower( trim( $row['lang'] ) ) : 'es',
				'limit'     => isset( $row['limit'] ) ? max( 1, min( 50000, (int) $row['limit'] ) ) : 1000,
				'images'    => ! empty( $row['images'] ),
				'videos'    => ! empty( $row['videos'] ),
				'vars'      => $vars,
			);
		}

		update_option( self::OPTION, $opts );
		Cmdroom_Sitemap_Render::flush_cache();

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function render_page() {
		$opts        = self::get_options();
		$definitions = $opts['definitions'];
		$blank_rows  = 3;
		for ( $i = 0; $i < $blank_rows; $i++ ) {
			$definitions[] = self::default_definition( '', '', 'post' );
			$definitions[ count( $definitions ) - 1 ]['images'] = false;
		}

		$active_tab = Cmdroom_Admin_Menu::get_active_tab( array( 'config' => 'Configuración', 'avanzado' => 'Avanzado' ) );
		?>
		<div class="wrap cmdroom-wrap cmdroom-metadata-wrap cmdroom-sitemaps-wrap">
			<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Ajustes guardados.', 'command-room' ); ?></p></div>
			<?php elseif ( isset( $_GET['cmdroom_pinged'] ) ) : ?>
				<?php $ping_report = get_transient( 'cmdroom_sitemap_ping_report' ); ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Caché regenerada y ping enviado a los buscadores.', 'command-room' ); ?></p>
					<?php if ( $ping_report ) : ?>
						<ul style="list-style:disc;margin-left:1.5em;">
							<?php foreach ( $ping_report as $engine => $status ) : ?>
								<li><?php echo esc_html( $engine . ': ' . $status ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h1 class="cmdroom-md-h1"><?php esc_html_e( 'Sitemaps', 'command-room' ); ?></h1>

			<div class="cmdroom-md-container">
				<p class="cmdroom-md-intro">
					<?php if ( self::is_live_output_enabled() ) : ?>
						<?php esc_html_e( 'Gestiona y configura todos tus sitemaps desde un único sitio.', 'command-room' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'La salida en el sitio está desactivada: /sitemap_index.xml lo sigue sirviendo Rank Math. Usa la vista previa de Command Room → General para comprobar cada definición antes de activarlo.', 'command-room' ); ?>
					<?php endif; ?>
				</p>

				<div class="cmdroom-md-tabs">
					<button type="button" class="cmdroom-md-tab<?php echo 'config' === $active_tab ? ' is-active' : ''; ?>" data-tab="config"><?php esc_html_e( 'Configuración', 'command-room' ); ?></button>
					<button type="button" class="cmdroom-md-tab<?php echo 'avanzado' === $active_tab ? ' is-active' : ''; ?>" data-tab="avanzado"><?php esc_html_e( 'Avanzado', 'command-room' ); ?></button>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'cmdroom_save_sitemap_settings' ); ?>
					<input type="hidden" name="action" value="cmdroom_save_sitemap_settings" />

					<div class="cmdroom-sitemaps-panel" data-tab="config" <?php echo 'config' === $active_tab ? '' : 'hidden'; ?>>
						<?php self::render_definitions_table( $definitions ); ?>
						<?php self::render_urls_table(); ?>
					</div>

					<div class="cmdroom-sitemaps-panel" data-tab="avanzado" <?php echo 'avanzado' === $active_tab ? '' : 'hidden'; ?>>
						<?php self::render_advanced_tab( $opts ); ?>
					</div>

					<?php submit_button( __( 'Guardar', 'command-room' ), 'cmdroom-md-save', 'submit', false ); ?>
				</form>

				<div class="cmdroom-sitemaps-panel" data-tab="avanzado" <?php echo 'avanzado' === $active_tab ? '' : 'hidden'; ?>>
					<?php self::render_ping_form(); ?>
				</div>

				<div class="cmdroom-md-footer">
					<h2 class="cmdroom-md-footer-title"><?php esc_html_e( 'Más información', 'command-room' ); ?></h2>
					<p class="cmdroom-md-footer-text">
						<?php esc_html_e( 'Deja el slug en blanco para no guardar esa fila (así se "borra" una definición existente). "Imágenes" y "Vídeos" solo aplican a Origen = Posts.', 'command-room' ); ?>
					</p>
					<ul class="cmdroom-md-footer-list">
						<li><strong><?php esc_html_e( 'Formato:', 'command-room' ); ?></strong> <?php esc_html_e( 'News: solo últimas 48h.', 'command-room' ); ?></li>
						<li><strong><?php esc_html_e( 'Tipo:', 'command-room' ); ?></strong> <?php esc_html_e( 'solo si Origen = Posts.', 'command-room' ); ?></li>
						<li><strong><?php esc_html_e( 'Taxonomía:', 'command-room' ); ?></strong> <?php esc_html_e( 'obligatoria si Origen = URLs de archivo.', 'command-room' ); ?></li>
						<li><strong><?php esc_html_e( 'Términos:', 'command-room' ); ?></strong> <?php esc_html_e( 'slugs separados por coma; vacío = todos.', 'command-room' ); ?></li>
						<li><strong><?php esc_html_e( 'Idioma:', 'command-room' ); ?></strong> <?php esc_html_e( 'Google News.', 'command-room' ); ?></li>
					</ul>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_definitions_table( $definitions ) {
		?>
		<div class="cr-card cmdroom-sitemaps-table-wrap">
			<table class="cmdroom-sitemaps-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Activa', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Slug (URL)', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Nombre', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Origen', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Formato', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Tipo', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Taxonomía', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Términos', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Idioma', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Límite', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Imágenes', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Vídeos', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Variables', 'command-room' ); ?></th>
						<th><?php esc_html_e( 'Ver', 'command-room' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $definitions as $i => $def ) :
						$vars = wp_parse_args( isset( $def['vars'] ) ? $def['vars'] : array(), self::VAR_DEFAULTS );
						// URL definitiva -- ver Cmdroom_Sitemap_Settings::preview_url().
						$preview_url = $def['slug'] ? self::preview_url( $def['slug'] ) : '';
						?>
						<tr class="cmdroom-sitemaps-row<?php echo empty( $def['enabled'] ) ? ' is-inactive' : ''; ?>">
							<td><input type="checkbox" name="definitions[<?php echo (int) $i; ?>][enabled]" value="1" <?php checked( ! empty( $def['enabled'] ) ); ?> /></td>
							<td><input type="text" name="definitions[<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr( $def['slug'] ); ?>" placeholder="<?php esc_attr_e( 'p. ej. transaccionales', 'command-room' ); ?>" /></td>
							<td><input type="text" name="definitions[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $def['label'] ); ?>" /></td>
							<td>
								<select name="definitions[<?php echo (int) $i; ?>][source]">
									<option value="posts" <?php selected( $def['source'] ?? 'posts', 'posts' ); ?>><?php esc_html_e( 'Posts', 'command-room' ); ?></option>
									<option value="terms" <?php selected( $def['source'] ?? 'posts', 'terms' ); ?>><?php esc_html_e( 'URLs de archivo', 'command-room' ); ?></option>
								</select>
							</td>
							<td>
								<select name="definitions[<?php echo (int) $i; ?>][format]">
									<option value="standard" <?php selected( $def['format'] ?? 'standard', 'standard' ); ?>><?php esc_html_e( 'Estándar', 'command-room' ); ?></option>
									<option value="news" <?php selected( $def['format'] ?? 'standard', 'news' ); ?>><?php esc_html_e( 'News', 'command-room' ); ?></option>
								</select>
							</td>
							<td>
								<select name="definitions[<?php echo (int) $i; ?>][post_type]">
									<?php foreach ( Cmdroom_Meta_Settings::public_post_types() as $pt ) : ?>
										<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $def['post_type'], $pt->name ); ?>><?php echo esc_html( $pt->labels->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="definitions[<?php echo (int) $i; ?>][taxonomy]">
									<option value=""><?php esc_html_e( '— Sin filtro —', 'command-room' ); ?></option>
									<?php foreach ( Cmdroom_Meta_Settings::public_taxonomies() as $tax ) : ?>
										<option value="<?php echo esc_attr( $tax->name ); ?>" <?php selected( $def['taxonomy'], $tax->name ); ?>><?php echo esc_html( $tax->labels->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><input type="text" name="definitions[<?php echo (int) $i; ?>][terms]" value="<?php echo esc_attr( $def['terms'] ); ?>" placeholder="cat-fisioterapia, cat-osteopatia" /></td>
							<td><input type="text" name="definitions[<?php echo (int) $i; ?>][lang]" value="<?php echo esc_attr( isset( $def['lang'] ) ? $def['lang'] : 'es' ); ?>" class="cmdroom-sitemaps-lang" maxlength="5" placeholder="es" /></td>
							<td><input type="number" name="definitions[<?php echo (int) $i; ?>][limit]" value="<?php echo esc_attr( $def['limit'] ); ?>" min="1" max="50000" class="cmdroom-sitemaps-limit" /></td>
							<td><input type="checkbox" name="definitions[<?php echo (int) $i; ?>][images]" value="1" <?php checked( ! empty( $def['images'] ) ); ?> /></td>
							<td><input type="checkbox" name="definitions[<?php echo (int) $i; ?>][videos]" value="1" <?php checked( ! empty( $def['videos'] ) ); ?> /></td>
							<td><button type="button" class="button cmdroom-sitemaps-vars-toggle" data-row="<?php echo (int) $i; ?>"><?php esc_html_e( 'Configurar', 'command-room' ); ?></button></td>
							<td>
								<?php if ( $preview_url ) : ?>
									<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button cmdroom-sitemaps-view"><?php esc_html_e( 'Ver', 'command-room' ); ?></a>
								<?php else : ?>
									<span class="button cmdroom-sitemaps-view" aria-disabled="true"><?php esc_html_e( 'Ver', 'command-room' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
						<tr class="cmdroom-sitemaps-vars-row" data-row="<?php echo (int) $i; ?>" hidden>
							<td colspan="14">
								<div class="cmdroom-sitemaps-vars-panel">
									<p class="cmdroom-sitemaps-vars-title"><?php esc_html_e( 'Variables — de dónde sale cada campo del XML', 'command-room' ); ?></p>
									<div class="cmdroom-sitemaps-vars-grid">
										<?php foreach ( self::VAR_OPTIONS as $field => $options ) : ?>
											<div class="cmdroom-sitemaps-vars-field">
												<label><?php echo esc_html( self::VAR_LABELS[ $field ] ); ?></label>
												<select name="definitions[<?php echo (int) $i; ?>][vars][<?php echo esc_attr( $field ); ?>]">
													<?php foreach ( $options as $opt ) : ?>
														<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $vars[ $field ], $opt ); ?>><?php echo '' === $opt ? esc_html__( '— Ninguno —', 'command-room' ) : esc_html( $opt ); ?></option>
													<?php endforeach; ?>
												</select>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_advanced_tab( $opts ) {
		?>
		<div class="cmdroom-sitemaps-advanced-row">
			<label for="news_publication_name"><?php esc_html_e( 'Google News — Nombre', 'command-room' ); ?></label>
			<div>
				<input type="text" id="news_publication_name" name="news_publication_name" value="<?php echo esc_attr( $opts['news_publication_name'] ); ?>" class="cmdroom-sitemaps-news-name" />
				<span class="cmdroom-sitemaps-advanced-help"><?php esc_html_e( 'Tiene que coincidir exactamente con el nombre registrado en Google Publisher Center.', 'command-room' ); ?></span>
			</div>
		</div>

		<h3 class="cmdroom-sitemaps-section-title"><?php esc_html_e( 'Post types y taxonomías incluidos', 'command-room' ); ?></h3>
		<p class="cmdroom-md-footer-text"><?php esc_html_e( 'Si desmarcas un tipo de contenido o taxonomía aquí, ninguna configuración lo incluirá (útil para excluir de golpe).', 'command-room' ); ?></p>

		<div class="cmdroom-sitemaps-advanced-row cmdroom-sitemaps-advanced-row-bordered">
			<label><?php esc_html_e( 'Tipos de contenido', 'command-room' ); ?></label>
			<div class="cmdroom-sitemaps-checkbox-list">
				<?php foreach ( self::public_post_types_for_toggle() as $pt ) : ?>
					<label class="cmdroom-sitemaps-checkbox">
						<input type="checkbox" name="post_types_enabled[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( ! isset( $opts['post_types_enabled'][ $pt->name ] ) || $opts['post_types_enabled'][ $pt->name ] ); ?> />
						<?php echo esc_html( $pt->labels->name ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="cmdroom-sitemaps-advanced-row">
			<label><?php esc_html_e( 'Taxonomías', 'command-room' ); ?></label>
			<div class="cmdroom-sitemaps-checkbox-list">
				<?php foreach ( Cmdroom_Meta_Settings::public_taxonomies() as $tax ) : ?>
					<label class="cmdroom-sitemaps-checkbox">
						<input type="checkbox" name="taxonomies_enabled[]" value="<?php echo esc_attr( $tax->name ); ?>" <?php checked( ! isset( $opts['taxonomies_enabled'][ $tax->name ] ) || $opts['taxonomies_enabled'][ $tax->name ] ); ?> />
						<?php echo esc_html( $tax->labels->name ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
	}

	/**
	 * "Regenerar y hacer ping" vive en un <form> propio, hermano del de
	 * Guardar (nunca anidado -- HTML no admite <form> dentro de <form>),
	 * para que su nonce sea el de su propia acción
	 * (admin_post_cmdroom_ping_sitemaps, Cmdroom_Sitemap_Ping) y no arrastre
	 * los 13 campos por fila de la tabla de definiciones al enviarlo.
	 */
	private static function render_ping_form() {
		?>
		<h3 class="cmdroom-sitemaps-section-title" style="margin-top:24px;"><?php esc_html_e( 'Regenerar y avisar a los buscadores', 'command-room' ); ?></h3>
		<p class="cmdroom-md-footer-text"><?php esc_html_e( 'Invalida la caché de todos los sitemaps y hace ping a Google y Bing con la URL del índice.', 'command-room' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmdroom_ping_sitemaps' ); ?>
			<input type="hidden" name="action" value="cmdroom_ping_sitemaps" />
			<?php submit_button( __( 'Regenerar y hacer ping', 'command-room' ), 'secondary', 'submit', false, self::is_live_output_enabled() ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
		<?php
	}
}
