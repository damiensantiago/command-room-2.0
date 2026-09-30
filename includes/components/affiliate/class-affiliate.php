<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Componente "Módulo de afiliación" — handoff 2026-09-28. Tres opciones wp
 * propias en vez de vivir bajo Cmdroom_Components_Admin::OPTION (a
 * diferencia de Ticker): el propio handoff las nombra literalmente
 * (cr_affiliate / cr_affiliate_library / cr_affiliate_rules), así que se
 * respetan tal cual en vez de anidarlas.
 *
 * Esta clase concentra: opciones + defaults, resolución de productos/URL de
 * afiliado, y render() -- la ÚNICA función que pinta el marcado del módulo
 * (grid/único/carrusel), reutilizada tanto por la vista previa del admin
 * (Cmdroom_Affiliate_Settings) como por el front-end real (shortcode,
 * inserción automática, bloque). Mismo patrón que Cmdroom_Ticker::render_bar().
 *
 * SIN PA-API real (sin integración con Amazon Associates todavía): 'rating'/'reviews' del Product son campos editables a
 * mano en el admin (el handoff los describe como "de solo lectura, de la
 * sincronización" -- eso queda pendiente hasta que exista integración real).
 * Cuando están vacíos, el front-end oculta las estrellas (tal como pide el
 * handoff para "sin datos de Amazon").
 */
class Cmdroom_Affiliate {

	const OPTION_SETTINGS = 'cr_affiliate';
	const OPTION_LIBRARY  = 'cr_affiliate_library';
	const OPTION_RULES    = 'cr_affiliate_rules';

	const SHORTCODE = 'cmdroom_afiliacion';

	const MARKETS = array( 'amazon.es', 'amazon.com', 'amazon.fr' );

	const EMPTY_PRODUCT = array(
		'name'    => '',
		'badge'   => '',
		'asin'    => '',
		'desc'    => '',
		'long'    => '',
		'rating'  => null,
		'reviews' => null,
	);

	const DEFAULTS = array(
		'enabled'  => false,
		'format'   => 'grid',
		'featured' => 0,
		'products' => array(
			array(
				'name'    => '',
				'badge'   => '',
				'asin'    => '',
				'desc'    => '',
				'long'    => '',
				'rating'  => null,
				'reviews' => null,
			),
		),
		'texts'    => array(
			'eyebrow'    => 'Producto recomendado por %author%',
			'title'      => 'Productos que recomendamos',
			'disclosure' => 'Enlaces de afiliación · comisión sin coste para ti',
			'button'     => 'Ver en Amazon',
			'foot'       => '',
			'foot_link'  => '',
			'foot_url'   => '',
		),
		'links'    => array(
			'market'  => 'amazon.es',
			'tag'     => '', // ID de afiliado de Amazon (p. ej. "misitio-21") -- vacío hasta configurarlo
			'new_tab' => true,
		),
		'placement' => array(
			'where' => 'end',
			'para'  => 4,
		),
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcode_and_block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_frontend' ) );
		add_filter( 'the_content', array( __CLASS__, 'maybe_autoinsert' ), 20 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_metabox' ) );
		add_action( 'save_post', array( __CLASS__, 'save_metabox' ) );
		add_action( 'wp_ajax_cmdroom_affiliate_toggle_enabled', array( __CLASS__, 'handle_toggle_enabled' ) );
	}

	/**
	 * Toggle instantáneo "Activar módulo en el sitio" -- vive en la fila
	 * superior común a las tres vistas (Grid/Único/Biblioteca), así que no
	 * puede depender del <form> de guardado de una vista concreta.
	 */
	public static function handle_toggle_enabled() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cmdroom_affiliate_toggle_enabled', '_wpnonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$opts            = self::get_options();
		$opts['enabled'] = ! empty( $_POST['value'] );
		self::update_options( $opts );
		wp_send_json_success( array( 'enabled' => $opts['enabled'] ) );
	}

	/* — Opciones — */

	public static function get_options() {
		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$opts = array_replace_recursive( self::DEFAULTS, $saved );
		// array_replace_recursive no vacía listas -- si el administrador borra todos
		// los productos a propósito, respetar la lista vacía en vez de
		// rellenarla con el producto de fábrica (igual que hace Ticker con
		// sus listas de mensajes por día).
		if ( isset( $saved['products'] ) && is_array( $saved['products'] ) ) {
			$opts['products'] = $saved['products'];
		}
		return $opts;
	}

	public static function update_options( $opts ) {
		update_option( self::OPTION_SETTINGS, $opts );
	}

	public static function is_enabled() {
		return ! empty( self::get_options()['enabled'] );
	}

	public static function get_library() {
		$lib = get_option( self::OPTION_LIBRARY, array() );
		return is_array( $lib ) ? array_values( $lib ) : array();
	}

	public static function update_library( $lib ) {
		update_option( self::OPTION_LIBRARY, array_values( $lib ) );
	}

	public static function get_rules() {
		$rules = get_option( self::OPTION_RULES, array() );
		return is_array( $rules ) ? $rules : array();
	}

	public static function update_rules( $rules ) {
		update_option( self::OPTION_RULES, $rules );
	}

	public static function get_rule_for_term( $term_id ) {
		$rules = self::get_rules();
		if ( isset( $rules[ $term_id ] ) ) {
			return array_replace( array( 'on' => false, 'format' => 'grid' ), $rules[ $term_id ] );
		}
		return array( 'on' => false, 'format' => 'grid' );
	}

	/* — Resolución de productos/URL — */

	public static function market_domain( $market ) {
		$map = array(
			'amazon.es'  => 'www.amazon.es',
			'amazon.com' => 'www.amazon.com',
			'amazon.fr'  => 'www.amazon.fr',
		);
		return isset( $map[ $market ] ) ? $map[ $market ] : $map['amazon.es'];
	}

	public static function build_product_url( $asin, $links ) {
		if ( '' === trim( (string) $asin ) ) {
			return '';
		}
		$domain = self::market_domain( isset( $links['market'] ) ? $links['market'] : 'amazon.es' );
		$tag    = isset( $links['tag'] ) ? $links['tag'] : '';
		$url    = sprintf( 'https://%s/dp/%s', $domain, rawurlencode( $asin ) );
		if ( '' !== $tag ) {
			$url .= '?tag=' . rawurlencode( $tag );
		}
		return $url;
	}

	/**
	 * Resuelve %author% contra el autor de la entrada -- refuerzo E-E-A-T
	 * pedido por el handoff. Fuera del contexto de una entrada (p. ej. vista
	 * previa del admin) usa el propio display_name del usuario actual como
	 * ejemplo razonable.
	 */
	public static function resolve_author_name( $post_id = 0 ) {
		$post_id = $post_id ? $post_id : get_the_ID();
		if ( $post_id ) {
			$author_id = (int) get_post_field( 'post_author', $post_id );
			if ( $author_id ) {
				$name = get_the_author_meta( 'display_name', $author_id );
				if ( $name ) {
					return $name;
				}
			}
		}
		$current = wp_get_current_user();
		return $current && $current->display_name ? $current->display_name : get_bloginfo( 'name' );
	}

	public static function apply_text_vars( $text, $post_id = 0 ) {
		return str_replace( '%author%', self::resolve_author_name( $post_id ), (string) $text );
	}

	/**
	 * Título de entrada sobrescribible por meta `_cr_aff_title`.
	 */
	public static function resolve_title( $texts, $post_id = 0 ) {
		$post_id = $post_id ? $post_id : get_the_ID();
		if ( $post_id ) {
			$override = get_post_meta( $post_id, '_cr_aff_title', true );
			if ( '' !== trim( (string) $override ) ) {
				return $override;
			}
		}
		return isset( $texts['title'] ) ? $texts['title'] : '';
	}

	/* — Front-end: registro — */

	public static function register_shortcode_and_block() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode_handler' ) );
		self::register_block();
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return; // WP < 5.0, degradar con seguridad -- el shortcode sigue funcionando.
		}
		wp_register_script(
			'cmdroom-affiliate-block',
			CMDROOM_URL . 'assets/js/affiliate-block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-server-side-render' ),
			CMDROOM_VERSION,
			true
		);
		register_block_type( 'command-room/afiliacion', array(
			'editor_script'   => 'cmdroom-affiliate-block',
			'render_callback' => array( __CLASS__, 'block_render_callback' ),
			'attributes'      => array(
				'formato'   => array( 'type' => 'string', 'default' => 'grid' ),
				'productos' => array( 'type' => 'string', 'default' => '' ),
				'producto'  => array( 'type' => 'string', 'default' => '' ),
				'asins'     => array( 'type' => 'string', 'default' => '' ),
			),
		) );
	}

	public static function block_render_callback( $attrs ) {
		$atts = array(
			'formato'   => isset( $attrs['formato'] ) ? $attrs['formato'] : 'grid',
			'productos' => isset( $attrs['productos'] ) ? $attrs['productos'] : '',
			'producto'  => isset( $attrs['producto'] ) ? $attrs['producto'] : '',
			'asins'     => isset( $attrs['asins'] ) ? $attrs['asins'] : '',
		);
		return self::shortcode_handler( $atts );
	}

	/**
	 * [cmdroom_afiliacion formato="grid|unico|carrusel" productos="1,2,3"
	 * producto="N" asins="B0…,B0…"] -- 'asins' tiene prioridad y toma los
	 * productos de la biblioteca por ASIN; si no, 'productos'/'producto' son
	 * índices (1-based, como en el admin) del módulo global cr_affiliate.
	 */
	public static function shortcode_handler( $atts ) {
		if ( ! self::is_enabled() ) {
			return '';
		}
		$atts = shortcode_atts( array(
			'formato'   => 'grid',
			'productos' => '',
			'producto'  => '',
			'asins'     => '',
		), $atts, self::SHORTCODE );

		$opts   = self::get_options();
		$format = in_array( $atts['formato'], array( 'grid', 'unico', 'carrusel' ), true ) ? $atts['formato'] : 'grid';

		if ( '' !== trim( $atts['asins'] ) ) {
			$asins    = array_filter( array_map( 'trim', explode( ',', $atts['asins'] ) ) );
			$library  = self::get_library();
			$products = array();
			foreach ( $asins as $asin ) {
				foreach ( $library as $lib_product ) {
					if ( isset( $lib_product['asin'] ) && strtoupper( $lib_product['asin'] ) === strtoupper( $asin ) ) {
						$products[] = $lib_product;
						break;
					}
				}
			}
			$featured = 0;
		} elseif ( 'unico' === $format && '' !== trim( $atts['producto'] ) ) {
			$idx      = max( 1, (int) $atts['producto'] ) - 1;
			$products = isset( $opts['products'][ $idx ] ) ? array( $opts['products'][ $idx ] ) : array();
			$featured = 0;
		} elseif ( '' !== trim( $atts['productos'] ) ) {
			$idxs     = array_filter( array_map( 'trim', explode( ',', $atts['productos'] ) ) );
			$products = array();
			foreach ( $idxs as $idx ) {
				$i = max( 1, (int) $idx ) - 1;
				if ( isset( $opts['products'][ $i ] ) ) {
					$products[] = $opts['products'][ $i ];
				}
			}
			$featured = 0;
		} else {
			$products = $opts['products'];
			$featured = isset( $opts['featured'] ) ? (int) $opts['featured'] : 0;
		}

		if ( empty( $products ) ) {
			return ''; // "sin productos válidos" -- no se imprime nada.
		}

		return self::render( $format, $products, array(
			'texts'    => $opts['texts'],
			'links'    => $opts['links'],
			'featured' => $featured,
			'post_id'  => get_the_ID(),
		) );
	}

	/* — Front-end: inserción automática — */

	public static function maybe_enqueue_frontend() {
		// Se encola siempre que el módulo esté activo -- barato (una sola
		// hoja pequeña) y evita tener que adivinar en qué entradas concretas
		// va a aparecer antes de saber si hay shortcode/bloque manual.
		if ( self::is_enabled() ) {
			wp_enqueue_style( 'cmdroom-affiliate-frontend', CMDROOM_URL . 'assets/css/affiliate-frontend.css', array(), CMDROOM_VERSION );
		}
	}

	/**
	 * Inserción automática en el contenido. Prioridad real:
	 * 1. Shortcode o bloque puesto a mano en el propio contenido -- ya se
	 *    renderiza solo (do_shortcode/render_block corren en 'the_content'
	 *    antes que este filtro), así que aquí solo hay que NO añadir nada
	 *    más para no duplicar.
	 * 2. Módulo global (cr_affiliate) si está activo y su "Dónde se
	 *    inserta" es "Al final" o "Tras un párrafo" -- es la ubicación
	 *    descrita en la sección B4 del handoff.
	 * 3. Si el módulo global está en "Solo con shortcode" o desactivado,
	 *    la categoría principal de la entrada puede tener una regla activa
	 *    (Biblioteca → Recomendaciones automáticas) -- se usa esa en su
	 *    lugar. Es una interpretación explícita: el handoff no dice qué
	 *    pasa cuando ambos mecanismos aplicarían a la vez, así que las
	 *    reglas por categoría solo entran cuando el módulo global no se
	 *    imprime ya por su cuenta, para nunca imprimir dos <aside> en la
	 *    misma entrada.
	 * 4. Nada.
	 */
	public static function maybe_autoinsert( $content ) {
		if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content; // el handoff habla de "entradas" del blog, no de páginas estáticas.
		}
		if ( self::content_has_manual_module( $content ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		$opts    = self::get_options();

		if ( ! empty( $opts['enabled'] ) && in_array( $opts['placement']['where'], array( 'end', 'para' ), true ) ) {
			$html = self::render( $opts['format'], $opts['products'], array(
				'texts'    => $opts['texts'],
				'links'    => $opts['links'],
				'featured' => (int) $opts['featured'],
				'post_id'  => $post_id,
			) );
			if ( '' === $html ) {
				return $content;
			}
			if ( 'para' === $opts['placement']['where'] ) {
				return self::insert_after_paragraph( $content, (int) $opts['placement']['para'], $html );
			}
			return $content . $html;
		}

		// Módulo global desactivado o en "Solo con shortcode": probar la
		// regla automática de la categoría principal.
		$rule_html = self::render_rule_for_post( $post_id );
		if ( '' !== $rule_html ) {
			return $content . $rule_html;
		}

		return $content;
	}

	/**
	 * Este filtro corre en 'the_content' a prioridad 20 -- DESPUÉS de
	 * do_blocks (9) y do_shortcode (11), así que un shortcode o bloque
	 * puesto a mano ya se ha expandido a HTML en $content en este punto y
	 * el texto literal "[cmdroom_afiliacion" o el comentario de bloque ya no
	 * existen para buscarlos. Se comprueba en su lugar si el propio marcado
	 * ya renderizado (<aside class="cr-aff...) está presente -- funciona
	 * igual venga de shortcode, de bloque o de una futura tercera vía, y
	 * evita imprimir el módulo dos veces en la misma entrada.
	 */
	private static function content_has_manual_module( $content ) {
		return false !== strpos( $content, 'class="cr-aff' );
	}

	private static function insert_after_paragraph( $content, $n, $html ) {
		$n = max( 1, $n );
		$closing_p = '</p>';
		$pos       = -1;
		for ( $i = 0; $i < $n; $i++ ) {
			$next = strpos( $content, $closing_p, $pos + 1 );
			if ( false === $next ) {
				return $content . $html; // menos párrafos de los pedidos -- va al final.
			}
			$pos = $next;
		}
		$cut = $pos + strlen( $closing_p );
		return substr( $content, 0, $cut ) . $html . substr( $content, $cut );
	}

	/**
	 * Recomendación automática por categoría (biblioteca). Usa la primera
	 * categoría de la entrada con una regla activa -- el orden es el que
	 * devuelve get_the_category() (WordPress lo da normalmente ordenado por
	 * nombre; no hay concepto de "categoría principal" nativo sin Yoast/
	 * Rank Math).
	 */
	public static function render_rule_for_post( $post_id ) {
		$cats = get_the_category( $post_id );
		if ( empty( $cats ) ) {
			return '';
		}
		$library = self::get_library();
		foreach ( $cats as $cat ) {
			$rule = self::get_rule_for_term( $cat->term_id );
			if ( empty( $rule['on'] ) ) {
				continue;
			}
			$products = array_values( array_filter( $library, function ( $p ) use ( $cat ) {
				return ! empty( $p['cats'] ) && in_array( $cat->term_id, array_map( 'intval', $p['cats'] ), true );
			} ) );
			if ( empty( $products ) ) {
				continue;
			}
			$opts   = self::get_options();
			$format = 'carrusel' === $rule['format'] ? 'carrusel' : ( 'unico' === $rule['format'] ? 'unico' : 'grid' );
			if ( 'grid' === $format ) {
				$products = array_slice( $products, 0, 3 );
			} elseif ( 'unico' === $format ) {
				$products = array_slice( $products, 0, 1 );
			}
			return self::render( $format, $products, array(
				'texts'    => $opts['texts'],
				'links'    => $opts['links'],
				'featured' => 0,
				'post_id'  => $post_id,
			) );
		}
		return '';
	}

	/* — Meta box: título de entrada — */

	public static function register_metabox() {
		$types = get_post_types( array( 'public' => true ) );
		foreach ( $types as $type ) {
			add_meta_box( 'cmdroom-affiliate-title', __( 'Módulo de afiliación', 'command-room' ), array( __CLASS__, 'render_metabox' ), $type, 'side', 'default' );
		}
	}

	public static function render_metabox( $post ) {
		wp_nonce_field( 'cmdroom_save_aff_title', 'cmdroom_aff_title_nonce' );
		$title = get_post_meta( $post->ID, '_cr_aff_title', true );
		?>
		<p>
			<label for="cmdroom_aff_title"><strong><?php esc_html_e( 'Sobrescribir título del módulo', 'command-room' ); ?></strong></label><br />
			<input type="text" id="cmdroom_aff_title" name="cmdroom_aff_title" value="<?php echo esc_attr( $title ); ?>" class="widefat" placeholder="<?php echo esc_attr( self::get_options()['texts']['title'] ); ?>" />
		</p>
		<p class="description"><?php esc_html_e( 'En blanco: usa el título configurado en Componentes → Módulo de afiliación.', 'command-room' ); ?></p>
		<?php
	}

	public static function save_metabox( $post_id ) {
		if ( ! isset( $_POST['cmdroom_aff_title_nonce'] ) || ! wp_verify_nonce( $_POST['cmdroom_aff_title_nonce'], 'cmdroom_save_aff_title' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$title = isset( $_POST['cmdroom_aff_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cmdroom_aff_title'] ) ) : '';
		if ( '' === $title ) {
			delete_post_meta( $post_id, '_cr_aff_title' );
		} else {
			update_post_meta( $post_id, '_cr_aff_title', $title );
		}
	}

	/* — Render compartido (admin + front-end) — */

	private static function stars_html( $rating ) {
		if ( null === $rating || '' === $rating ) {
			return '';
		}
		$full  = (int) round( (float) $rating );
		$full  = max( 0, min( 5, $full ) );
		return str_repeat( '★', $full ) . str_repeat( '☆', 5 - $full );
	}

	private static function rel_attr() {
		return 'sponsored nofollow noopener';
	}

	/**
	 * Única función que pinta el marcado del módulo -- la usan el shortcode,
	 * la inserción automática, el bloque de Gutenberg Y la vista previa del
	 * admin (Cmdroom_Affiliate_Settings::render_preview()), para que nunca
	 * puedan desincronizarse. $format: grid|unico|carrusel.
	 */
	public static function render( $format, $products, $ctx = array() ) {
		$texts    = isset( $ctx['texts'] ) ? $ctx['texts'] : self::DEFAULTS['texts'];
		$links    = isset( $ctx['links'] ) ? $ctx['links'] : self::DEFAULTS['links'];
		$featured = isset( $ctx['featured'] ) ? (int) $ctx['featured'] : 0;
		$post_id  = isset( $ctx['post_id'] ) ? $ctx['post_id'] : 0;
		$new_tab  = ! empty( $links['new_tab'] );

		$products = array_values( array_filter( $products, function ( $p ) {
			return ! empty( $p['asin'] ) || ! empty( $p['name'] );
		} ) );
		if ( empty( $products ) ) {
			return '';
		}

		// $title_attr sin escapar todavía -- se usa tanto en el aria-label
		// (esc_attr) como en el <h3> (esc_html); escaparlo una sola vez aquí
		// arriba y reutilizarlo en los dos contextos produciría un doble
		// escapado de entidades (p. ej. "&" -> "&amp;" -> "&amp;amp;") en el
		// aria-label.
		$title_raw  = self::apply_text_vars( self::resolve_title( $texts, $post_id ), $post_id );
		$title      = esc_html( $title_raw );
		$eyebrow    = esc_html( self::apply_text_vars( isset( $texts['eyebrow'] ) ? $texts['eyebrow'] : '', $post_id ) );
		$disclosure = esc_html( isset( $texts['disclosure'] ) ? $texts['disclosure'] : '' );
		$button_txt = isset( $texts['button'] ) ? $texts['button'] : '';

		ob_start();
		if ( 'unico' === $format ) {
			self::render_single( $products, $featured, $links, $texts, $title_raw, $title, $eyebrow, $disclosure, $button_txt, $new_tab );
		} elseif ( 'carrusel' === $format ) {
			self::render_carousel( $products, $links, $texts, $title_raw, $title, $eyebrow, $disclosure, $button_txt, $new_tab );
		} else {
			self::render_grid( array_slice( $products, 0, 3 ), $featured, $links, $texts, $title_raw, $title, $eyebrow, $disclosure, $button_txt, $new_tab );
		}
		return ob_get_clean();
	}

	private static function target_attr( $new_tab ) {
		return $new_tab ? ' target="_blank"' : '';
	}

	private static function render_grid( $products, $featured, $links, $texts, $title_raw, $title, $eyebrow, $disclosure, $button_txt, $new_tab ) {
		?>
		<aside class="cr-aff cr-aff--grid" aria-label="<?php echo esc_attr( $title_raw ); ?>">
			<?php if ( $eyebrow ) : ?><p class="cr-aff-eyebrow"><?php echo $eyebrow; ?></p><?php endif; ?>
			<?php if ( $title ) : ?><h3 class="cr-aff-title"><?php echo $title; ?></h3><?php endif; ?>
			<?php if ( $disclosure ) : ?><p class="cr-aff-disclosure"><?php echo $disclosure; ?></p><?php endif; ?>
			<div class="cr-aff-grid">
				<?php foreach ( $products as $i => $p ) :
					$is_featured = $i === $featured;
					$url         = self::build_product_url( $p['asin'], $links );
					?>
					<div class="cr-aff-card<?php echo $is_featured ? ' cr-aff-card--featured' : ''; ?>">
						<a class="cr-aff-card-media" <?php echo $url ? 'href="' . esc_url( $url ) . '"' : ''; ?> rel="<?php echo esc_attr( self::rel_attr() ); ?>"<?php echo self::target_attr( $new_tab ); ?>>
							<span class="cr-aff-img-placeholder" aria-hidden="true"></span>
						</a>
						<div class="cr-aff-card-body">
							<?php if ( ! empty( $p['badge'] ) ) : ?><span class="cr-aff-badge"><?php echo esc_html( $p['badge'] ); ?></span><?php endif; ?>
							<p class="cr-aff-name"><?php echo esc_html( $p['name'] ); ?></p>
							<p class="cr-aff-desc"><?php echo esc_html( ! empty( $p['desc'] ) ? $p['desc'] : '' ); ?></p>
							<?php if ( ! empty( $p['rating'] ) ) : ?>
								<p class="cr-aff-rating"><span class="cr-aff-stars"><?php echo esc_html( self::stars_html( $p['rating'] ) ); ?></span> <?php echo esc_html( number_format_i18n( (float) $p['rating'], 1 ) . ( ! empty( $p['reviews'] ) ? ' · ' . number_format_i18n( (int) $p['reviews'] ) : '' ) ); ?></p>
							<?php endif; ?>
							<a class="cr-aff-btn<?php echo $is_featured ? ' cr-aff-btn--accent' : ''; ?>" <?php echo $url ? 'href="' . esc_url( $url ) . '"' : ''; ?> rel="<?php echo esc_attr( self::rel_attr() ); ?>"<?php echo self::target_attr( $new_tab ); ?>><?php echo esc_html( $button_txt ); ?></a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $texts['foot'] ) ) : ?>
				<p class="cr-aff-foot">
					<?php echo esc_html( $texts['foot'] ); ?>
					<?php if ( ! empty( $texts['foot_link'] ) && ! empty( $texts['foot_url'] ) ) : ?>
						<a href="<?php echo esc_url( $texts['foot_url'] ); ?>"><?php echo esc_html( $texts['foot_link'] ); ?></a>
					<?php endif; ?>
				</p>
			<?php endif; ?>
		</aside>
		<?php
	}

	private static function render_single( $products, $featured, $links, $texts, $title_raw, $title, $eyebrow, $disclosure, $button_txt, $new_tab ) {
		$p = isset( $products[ $featured ] ) ? $products[ $featured ] : $products[0];
		$url = self::build_product_url( $p['asin'], $links );
		$desc = ! empty( $p['long'] ) ? $p['long'] : ( ! empty( $p['desc'] ) ? $p['desc'] : '' );
		?>
		<aside class="cr-aff cr-aff--single" aria-label="<?php echo esc_attr( $title_raw ); ?>">
			<div class="cr-aff-single-media">
				<a <?php echo $url ? 'href="' . esc_url( $url ) . '"' : ''; ?> rel="<?php echo esc_attr( self::rel_attr() ); ?>"<?php echo self::target_attr( $new_tab ); ?>>
					<span class="cr-aff-img-placeholder" aria-hidden="true"></span>
				</a>
			</div>
			<div class="cr-aff-single-body">
				<?php if ( $eyebrow ) : ?><p class="cr-aff-eyebrow"><?php echo $eyebrow; ?></p><?php endif; ?>
				<p class="cr-aff-name cr-aff-name--lg"><?php echo esc_html( $p['name'] ); ?></p>
				<?php if ( $desc ) : ?><p class="cr-aff-desc cr-aff-desc--lg"><?php echo esc_html( $desc ); ?></p><?php endif; ?>
				<div class="cr-aff-single-row">
					<?php if ( ! empty( $p['rating'] ) ) : ?>
						<p class="cr-aff-rating"><span class="cr-aff-stars"><?php echo esc_html( self::stars_html( $p['rating'] ) ); ?></span> <?php echo esc_html( number_format_i18n( (float) $p['rating'], 1 ) . ( ! empty( $p['reviews'] ) ? ' · ' . number_format_i18n( (int) $p['reviews'] ) . ' valoraciones' : '' ) ); ?></p>
					<?php endif; ?>
					<a class="cr-aff-btn cr-aff-btn--accent cr-aff-btn--lg" <?php echo $url ? 'href="' . esc_url( $url ) . '"' : ''; ?> rel="<?php echo esc_attr( self::rel_attr() ); ?>"<?php echo self::target_attr( $new_tab ); ?>><?php echo esc_html( $button_txt ); ?></a>
				</div>
				<?php if ( $disclosure ) : ?><p class="cr-aff-pill-disclosure"><?php echo $disclosure; ?></p><?php endif; ?>
			</div>
		</aside>
		<?php
	}

	private static function render_carousel( $products, $links, $texts, $title_raw, $title, $eyebrow, $disclosure, $button_txt, $new_tab ) {
		?>
		<aside class="cr-aff cr-aff--carousel" aria-label="<?php echo esc_attr( $title_raw ); ?>">
			<?php if ( $eyebrow ) : ?><p class="cr-aff-eyebrow"><?php echo $eyebrow; ?></p><?php endif; ?>
			<?php if ( $title ) : ?><h3 class="cr-aff-title"><?php echo $title; ?></h3><?php endif; ?>
			<?php if ( $disclosure ) : ?><p class="cr-aff-disclosure"><?php echo $disclosure; ?></p><?php endif; ?>
			<div class="cr-aff-carousel-track">
				<?php foreach ( $products as $p ) :
					$url = self::build_product_url( $p['asin'], $links );
					?>
					<div class="cr-aff-card cr-aff-carousel-item">
						<a class="cr-aff-card-media" <?php echo $url ? 'href="' . esc_url( $url ) . '"' : ''; ?> rel="<?php echo esc_attr( self::rel_attr() ); ?>"<?php echo self::target_attr( $new_tab ); ?>>
							<span class="cr-aff-img-placeholder" aria-hidden="true"></span>
						</a>
						<div class="cr-aff-card-body">
							<?php if ( ! empty( $p['badge'] ) ) : ?><span class="cr-aff-badge"><?php echo esc_html( $p['badge'] ); ?></span><?php endif; ?>
							<p class="cr-aff-name"><?php echo esc_html( $p['name'] ); ?></p>
							<a class="cr-aff-btn" <?php echo $url ? 'href="' . esc_url( $url ) . '"' : ''; ?> rel="<?php echo esc_attr( self::rel_attr() ); ?>"<?php echo self::target_attr( $new_tab ); ?>><?php echo esc_html( $button_txt ); ?></a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</aside>
		<?php
	}

	/** Catálogo de %author% para el Glosario de variables (Cmdroom_Variables_Glossary). */
	public static function variables_catalog() {
		return array(
			array( 'tag' => '%author%', 'desc' => __( 'Autor de la entrada donde se imprime el módulo de afiliación (display_name) -- refuerzo E-E-A-T.', 'command-room' ) ),
		);
	}
}
