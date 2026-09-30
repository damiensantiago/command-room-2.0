<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Componentes" — rediseño 2026-09-23 sobre el handoff
 * "Componentes + Ticker". Dos vistas: una pestaña por componente activo
 * (Ticker y el Módulo de afiliación son los dos funcionales, el resto
 * placeholder "Próximamente"), y "Ver todos" con el interruptor de
 * activación de los 13 (el "Carrusel de tags" del handoff original se
 * retiró el 2026-09-25 -- lo cubre el "Carruseles por temáticas" que
 * reemplazó a "Carrusel de autores"; "Módulo de afiliación" se añadió el
 * 2026-09-28 como 13º componente, handoff propio).
 *
 * Slug fijo `command-room-componentes` (lo pide el handoff, en vez del
 * derivado `cmdroom-components` que tenían el resto de pantallas nuevas) —
 * ver Cmdroom_Admin_Menu::SLUG_OVERRIDES. LEGACY_REDIRECTS cubre el slug
 * `cmdroom-components` con el que se publicó esta pantalla hace un rato en
 * esta misma sesión, para no romper el enlace que ya se probó en vivo.
 */
class Cmdroom_Components_Admin {

	const SLUG = 'command-room-componentes';

	const LEGACY_REDIRECTS = array(
		'cmdroom-components' => 'ticker',
	);

	/**
	 * Opción compartida entre todos los componentes — cada uno guarda sus
	 * ajustes bajo su propia clave, igual que cmdroom_servidor/cmdroom_config.
	 */
	const OPTION = 'cmdroom_components';

	public static function get_option_value( $key, $default = null ) {
		$opts = get_option( self::OPTION, array() );
		return is_array( $opts ) && array_key_exists( $key, $opts ) ? $opts[ $key ] : $default;
	}

	public static function update_option_key( $key, $value ) {
		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		$opts[ $key ] = $value;
		update_option( self::OPTION, $opts );
	}

	/**
	 * Catálogo de los 12 componentes, en el orden del handoff. status:
	 * 'ready' (tiene clase propia) o 'planned' (placeholder). Claves de tab
	 * literales del handoff (?tab=reco, no "content-carousel").
	 */
	const CATALOG = array(
		'ticker'   => array( 'label' => 'Ticker', 'desc' => 'Barra de mensajes en movimiento debajo del menú: automáticos, configurados o mixtos.', 'status' => 'ready', 'default_enabled' => true ),
		'reco'     => array( 'label' => 'Carrusel recirculación', 'desc' => 'Carrusel de entradas relacionadas al final del artículo, para enlazado interno y tiempo en página.', 'status' => 'ready', 'default_enabled' => true ),
		'htmlmap'  => array( 'label' => 'Sitemap HTML', 'desc' => 'Página navegable con toda la estructura del sitio, pensada para usuarios y para rastreo.', 'status' => 'ready', 'default_enabled' => true ),
		'author'   => array( 'label' => 'Módulo de autor', 'desc' => 'Imagen, descripción y contenidos relacionados del autor. Se puede colocar arriba y abajo del artículo. Refuerza E-E-A-T.', 'status' => 'planned', 'default_enabled' => true ),
		'biblio'   => array( 'label' => 'Fuentes', 'desc' => 'Lista de fuentes y referencias citadas al final del artículo.', 'status' => 'planned', 'default_enabled' => true ),
		'shorts'   => array( 'label' => 'Carrusel de shorts', 'desc' => 'Carrusel de vídeos cortos verticales incrustados en el contenido.', 'status' => 'planned', 'default_enabled' => false ),
		'reviews'  => array( 'label' => 'Carrusel de reviews en Google', 'desc' => 'Reseñas de tu ficha de Google Business en formato carrusel.', 'status' => 'planned', 'default_enabled' => false ),
		'authors'  => array( 'label' => 'Carruseles por temáticas', 'desc' => 'Creación de carruseles de tags, autores o categorías según tus necesidades.', 'status' => 'planned', 'default_enabled' => false ),
		'faq'      => array( 'label' => 'Preguntas frecuentes', 'desc' => 'Bloque de preguntas y respuestas con su FAQPage en el schema.', 'status' => 'planned', 'default_enabled' => false ),
		'tldr'     => array( 'label' => 'TLDR (En resumen)', 'desc' => 'Resumen breve al inicio del artículo con los puntos clave.', 'status' => 'planned', 'default_enabled' => false ),
		'help'     => array( 'label' => 'Conversión en blog', 'desc' => 'Módulo pensado para blogs de ecommerce: al final o en mitad del artículo, lleva al lector a la ayuda de la tienda (contacto, WhatsApp, asesoramiento) y convierte tráfico informativo en venta.', 'status' => 'planned', 'default_enabled' => false ),
		'pricing'  => array( 'label' => 'Precios', 'desc' => 'Tabla de precios o planes incrustable en páginas y entradas.', 'status' => 'planned', 'default_enabled' => false ),
		'affiliate' => array( 'label' => 'Módulo de afiliación', 'desc' => 'Productos de afiliado de Amazon en la entrada: grid comparativa, producto único o biblioteca reutilizable con reglas por categoría.', 'status' => 'ready', 'default_enabled' => true ),
	);

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cmdroom_toggle_component', array( __CLASS__, 'handle_toggle_component' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		wp_enqueue_style( 'cmdroom-meta-editor', CMDROOM_URL . 'assets/css/meta-editor.css', array(), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-servidor-editor', CMDROOM_URL . 'assets/css/servidor-editor.css', array( 'cmdroom-meta-editor' ), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-components-editor', CMDROOM_URL . 'assets/css/components-editor.css', array( 'cmdroom-servidor-editor' ), CMDROOM_VERSION );
		// El CSS del propio Ticker (marquesina: overflow, nowrap, animación)
		// no se cargaba en el admin -- solo la carga Cmdroom_Ticker::enqueue_assets()
		// en el front-end real. Sin él, la caja de "Vista previa" pinta el
		// texto suelto, sin recortar ni desplazar. Se reutiliza el mismo
		// archivo que el sitio (misma clase .cmdroom-ticker en los dos sitios).
		wp_enqueue_style( 'cmdroom-ticker-frontend', CMDROOM_URL . 'assets/css/ticker-frontend.css', array( 'cmdroom-components-editor' ), CMDROOM_VERSION );
		// La vista previa del Módulo de afiliación reproduce el estilo del
		// propio front-end del sitio (no Modernist) -- se encola su hoja
		// real (misma que carga Cmdroom_Affiliate::maybe_enqueue_frontend()
		// en el sitio) para que admin y front-end nunca se desincronicen.
		wp_enqueue_style( 'cmdroom-affiliate-frontend', CMDROOM_URL . 'assets/css/affiliate-frontend.css', array( 'cmdroom-components-editor' ), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-affiliate-editor', CMDROOM_URL . 'assets/css/affiliate-editor.css', array( 'cmdroom-affiliate-frontend' ), CMDROOM_VERSION );
		// La vista previa del Carrusel de recomendación de contenidos reutiliza
		// las mismas convenciones .cmdroom-aff-* (affiliate-editor.css) más su
		// propia hoja de front-end real (mismo patrón que Ticker/Afiliación:
		// admin y front-end nunca se desincronizan porque los dos llaman a
		// Cmdroom_Reco::render()).
		wp_enqueue_style( 'cmdroom-reco-frontend', CMDROOM_URL . 'assets/css/reco-frontend.css', array( 'cmdroom-affiliate-editor' ), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-reco-editor', CMDROOM_URL . 'assets/css/reco-editor.css', array( 'cmdroom-reco-frontend' ), CMDROOM_VERSION );
		// El Sitemap HTML SÍ usa los tokens Modernist del propio admin (no el
		// tema del sitio, a diferencia de Afiliación/Reco) -- solo depende de
		// affiliate-editor.css por los tokens --cmdroom-accent-700/neutral-700
		// que define ahí, no por su look de front-end.
		wp_enqueue_style( 'cmdroom-htmlmap-editor', CMDROOM_URL . 'assets/css/htmlmap-editor.css', array( 'cmdroom-reco-editor' ), CMDROOM_VERSION );
		// servidor-editor.js aporta los comportamientos genéricos (toggles,
		// segmentados) -- ver el marcador cmdroom-servidor-wrap en render_page().
		wp_enqueue_script( 'cmdroom-servidor-editor', CMDROOM_URL . 'assets/js/servidor-editor.js', array(), CMDROOM_VERSION, true );
		wp_enqueue_script( 'cmdroom-components-editor', CMDROOM_URL . 'assets/js/components-editor.js', array( 'cmdroom-servidor-editor' ), CMDROOM_VERSION, true );
		wp_enqueue_script( 'cmdroom-affiliate-editor', CMDROOM_URL . 'assets/js/affiliate-editor.js', array( 'cmdroom-servidor-editor' ), CMDROOM_VERSION, true );
		wp_enqueue_script( 'cmdroom-reco-editor', CMDROOM_URL . 'assets/js/reco-editor.js', array( 'cmdroom-servidor-editor' ), CMDROOM_VERSION, true );
		wp_enqueue_script( 'cmdroom-htmlmap-editor', CMDROOM_URL . 'assets/js/htmlmap-editor.js', array( 'cmdroom-servidor-editor' ), CMDROOM_VERSION, true );
		wp_localize_script( 'cmdroom-components-editor', 'cmdroomComponents', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cmdroom_toggle_component' ),
		) );
		wp_localize_script( 'cmdroom-affiliate-editor', 'cmdroomAffiliate', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
		wp_localize_script( 'cmdroom-reco-editor', 'cmdroomReco', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
		wp_localize_script( 'cmdroom-htmlmap-editor', 'cmdroomHtmlmap', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		) );
	}

	public static function get_enabled_keys() {
		$saved = self::get_option_value( 'enabled', null );
		if ( null === $saved || ! is_array( $saved ) ) {
			$defaults = array();
			foreach ( self::CATALOG as $key => $meta ) {
				if ( ! empty( $meta['default_enabled'] ) ) {
					$defaults[] = $key;
				}
			}
			return $defaults;
		}
		return array_values( array_intersect( $saved, array_keys( self::CATALOG ) ) );
	}

	public static function is_component_enabled( $key ) {
		return in_array( $key, self::get_enabled_keys(), true );
	}

	public static function handle_toggle_component() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cmdroom_toggle_component', '_wpnonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! array_key_exists( $key, self::CATALOG ) ) {
			wp_send_json_error( null, 400 );
		}
		$enabled = self::get_enabled_keys();
		$on      = ! empty( $_POST['value'] );
		if ( $on && ! in_array( $key, $enabled, true ) ) {
			$enabled[] = $key;
		} elseif ( ! $on ) {
			$enabled = array_values( array_diff( $enabled, array( $key ) ) );
		}
		self::update_option_key( 'enabled', $enabled );
		wp_send_json_success( array( 'count' => count( $enabled ) ) );
	}

	public static function render_legacy_redirect( $old_slug ) {
		$tab = isset( self::LEGACY_REDIRECTS[ $old_slug ] ) ? self::LEGACY_REDIRECTS[ $old_slug ] : 'ticker';
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . $tab ), 301 );
		exit;
	}

	/**
	 * tab por defecto: 'ticker' si está activo, si no el primer activo del
	 * catálogo, si no 'all' (los 12 desactivados es un caso límite real:
	 * justo después de desactivarlos todos desde "Ver todos").
	 */
	private static function default_tab() {
		$enabled = self::get_enabled_keys();
		if ( in_array( 'ticker', $enabled, true ) ) {
			return 'ticker';
		}
		foreach ( self::CATALOG as $key => $meta ) {
			if ( in_array( $key, $enabled, true ) ) {
				return $key;
			}
		}
		return 'all';
	}

	public static function get_active_tab() {
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( 'all' === $requested ) {
			return 'all';
		}
		if ( $requested && array_key_exists( $requested, self::CATALOG ) && self::is_component_enabled( $requested ) ) {
			return $requested;
		}
		return self::default_tab();
	}

	public static function tab_url( $tab ) {
		return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
	}

	public static function render_page() {
		$active_tab = self::get_active_tab();
		$enabled    = self::get_enabled_keys();
		?>
		<div class="wrap cmdroom-wrap cmdroom-metadata-wrap cmdroom-servidor-wrap cmdroom-components-wrap">
			<h1 class="cmdroom-md-h1"><?php esc_html_e( 'Componentes', 'command-room' ); ?></h1>

			<div class="cmdroom-md-container">
				<p class="cmdroom-md-intro"><?php esc_html_e( 'Componentes orientados a SEO y conversión: activa y configura cada componente por separado.', 'command-room' ); ?></p>

				<div class="cmdroom-components-tabbar">
					<nav class="cmdroom-components-tabbar-scroll" data-cr-components-tabbar>
						<?php foreach ( self::CATALOG as $key => $meta ) : ?>
							<a
								class="cr-tab<?php echo $active_tab === $key ? ' is-active' : ''; ?>"
								href="<?php echo esc_url( self::tab_url( $key ) ); ?>"
								data-cr-component-tab="<?php echo esc_attr( $key ); ?>"
								<?php echo in_array( $key, $enabled, true ) ? '' : 'hidden'; ?>
							>
								<?php echo esc_html( $meta['label'] ); ?>
								<?php if ( 'planned' === $meta['status'] ) : ?>
									<span class="cr-pill cr-pill-301 cmdroom-components-tab-pill"><?php esc_html_e( 'Próx.', 'command-room' ); ?></span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</nav>
					<a class="cr-tab cmdroom-components-tab-all<?php echo 'all' === $active_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::tab_url( 'all' ) ); ?>">
						<?php printf( esc_html__( 'Ver todos (%d) →', 'command-room' ), count( self::CATALOG ) ); ?>
					</a>
				</div>

				<?php
				if ( 'all' === $active_tab ) {
					self::render_all_view( $enabled );
				} elseif ( 'ticker' === $active_tab ) {
					Cmdroom_Ticker_Settings::render_tab();
				} elseif ( 'affiliate' === $active_tab ) {
					Cmdroom_Affiliate_Settings::render_tab();
				} elseif ( 'reco' === $active_tab ) {
					Cmdroom_Reco_Settings::render_tab();
				} elseif ( 'htmlmap' === $active_tab ) {
					Cmdroom_Htmlmap_Settings::render_tab();
				} else {
					self::render_placeholder( $active_tab );
				}
				?>
			</div>
		</div>
		<?php
	}

	private static function render_all_view( $enabled ) {
		?>
		<p class="cmdroom-md-intro" style="margin-bottom:16px;"><?php esc_html_e( 'Activa los componentes que necesitas. Los activos aparecen como pestaña en la barra de arriba; los desactivados no se imprimen en el sitio.', 'command-room' ); ?></p>

		<div class="cr-card cmdroom-components-all-list">
			<?php foreach ( self::CATALOG as $key => $meta ) :
				$on = in_array( $key, $enabled, true );
				?>
				<div class="cmdroom-components-all-row">
					<div
						class="cmdroom-components-all-toggle"
						data-cr-component-toggle
						data-key="<?php echo esc_attr( $key ); ?>"
					>
						<button type="button" class="cr-toggle<?php echo $on ? ' is-on' : ''; ?>"><span class="cr-toggle-knob"></span><input type="checkbox" <?php checked( $on ); ?> /></button>
					</div>
					<div class="cmdroom-components-all-body">
						<p class="cmdroom-components-all-name">
							<?php echo esc_html( $meta['label'] ); ?>
							<span class="cr-pill <?php echo 'ready' === $meta['status'] ? 'cr-pill-other' : 'cr-pill-301'; ?>"><?php echo 'ready' === $meta['status'] ? esc_html__( 'Disponible', 'command-room' ) : esc_html__( 'Próximamente', 'command-room' ); ?></span>
						</p>
						<p class="cmdroom-components-all-desc"><?php echo esc_html( $meta['desc'] ); ?></p>
					</div>
					<a class="cr-btn-secondary cr-btn-compact cmdroom-components-all-configure" href="<?php echo esc_url( self::tab_url( $key ) ); ?>" <?php echo $on ? '' : 'hidden'; ?>><?php esc_html_e( 'Configurar', 'command-room' ); ?></a>
				</div>
			<?php endforeach; ?>
		</div>

		<p class="cmdroom-components-all-summary" data-cr-components-summary>
			<?php printf( esc_html__( '%1$d de %2$d componentes activos.', 'command-room' ), count( $enabled ), count( self::CATALOG ) ); ?>
		</p>
		<?php
	}

	private static function render_placeholder( $tab ) {
		$meta = isset( self::CATALOG[ $tab ] ) ? self::CATALOG[ $tab ] : null;
		if ( ! $meta ) {
			return;
		}
		?>
		<div class="cr-card cmdroom-components-placeholder">
			<span class="cmdroom-components-placeholder-kicker"><?php esc_html_e( 'PRÓXIMAMENTE', 'command-room' ); ?></span>
			<h2 class="cmdroom-components-placeholder-title"><?php echo esc_html( $meta['label'] ); ?></h2>
			<p class="cmdroom-components-placeholder-desc"><?php echo esc_html( $meta['desc'] ); ?></p>
		</div>
		<?php
	}
}
