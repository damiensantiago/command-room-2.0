<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Componente "Carrusel de recomendación de contenidos" -- handoff
 * 2026-09-28. Tres lógicas (site/section/friends), mismo patrón que
 * Cmdroom_Affiliate: esta clase concentra opciones + defaults, resolución de
 * contenidos y render() -- la ÚNICA función que pinta el marcado
 * (<section class="cmdroom-reco">...), reutilizada por la vista previa del
 * admin (Cmdroom_Reco_Settings) y por el front-end real (shortcode,
 * inserción automática). Mismo patrón que Cmdroom_Affiliate::render().
 *
 * "Webs amigas" (red recíproca): de fábrica no hay ningún partner
 * configurado -- el estado inicial
 * de network.friends es un array vacío. Con la lista vacía o sin amigas
 * activas, resolve_items_friends() devuelve un array vacío y render() no
 * imprime nada (fallback elegido: mejor no imprimir un carrusel roto/vacío
 * que mostrarlo sin contenido). El endpoint /network/invite de ESTE sitio
 * es público (lo llama otra instalación), así que NO auto-activa nada: una
 * invitación bien formada se guarda en pausa (status ok, enabled false) y
 * sus entradas no salen en el carrusel hasta que el administrador la
 * activa a mano en la lista de webs amigas. La lista tiene un tope
 * (MAX_FRIENDS) para que invitaciones en masa no inflen la opción; si el
 * payload es inválido, la invitación no se guarda. Esto no se ha podido probar de extremo a extremo
 * (no hay una segunda instalación real con la que reciprocar todavía).
 */
class Cmdroom_Reco {

	const OPTION    = 'cr_reco';
	const SHORTCODE = 'cmdroom_relacionados';
	const REST_NS   = 'cmdroom/v1';
	const MAX_FRIENDS = 50; // tope de la lista: una invitación no puede crecerla sin límite
	const MODES     = array( 'site', 'section', 'friends' );
	const RELS      = array( 'nofollow', 'follow' );

	const DEFAULTS = array(
		'enabled'   => true,
		'mode'      => 'site',
		'title'     => 'También te puede interesar',
		'placement' => 'end', // end|shortcode
		'new_tab'   => false,
		'pins_site'    => array( 0, 0, 0 ), // post_id, 0 = automático
		'pins_section' => array(), // term_id => array( post_id, post_id, post_id )
		'section_fill' => true,
		'active_section' => 0, // último term_id visto en el admin (conveniencia de UI)
		'network' => array(
			'mix'          => 'friends', // friends|alternate
			'rel'          => 'nofollow',
			'shared_terms' => array(),
			'friends'      => array(), // array( array( domain, status(ok|pending), enabled, token, note ) )
		),
	);

	/* — Opciones — */

	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$opts = array_replace_recursive( self::DEFAULTS, $saved );
		// array_replace_recursive no vacía listas -- si se borran a propósito
		// todos los pins de una posición o todas las webs amigas, respetar la
		// lista vacía (mismo criterio que Cmdroom_Affiliate::get_options() con
		// 'products').
		if ( isset( $saved['pins_site'] ) && is_array( $saved['pins_site'] ) ) {
			$opts['pins_site'] = $saved['pins_site'];
		}
		if ( isset( $saved['pins_section'] ) && is_array( $saved['pins_section'] ) ) {
			$opts['pins_section'] = $saved['pins_section'];
		}
		if ( isset( $saved['network']['shared_terms'] ) && is_array( $saved['network']['shared_terms'] ) ) {
			$opts['network']['shared_terms'] = $saved['network']['shared_terms'];
		}
		if ( isset( $saved['network']['friends'] ) && is_array( $saved['network']['friends'] ) ) {
			$opts['network']['friends'] = $saved['network']['friends'];
		}
		return $opts;
	}

	public static function update_options( $opts ) {
		update_option( self::OPTION, $opts );
	}

	public static function is_enabled() {
		return ! empty( self::get_options()['enabled'] );
	}

	/* — Init — */

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_shortcode' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_frontend' ) );
		add_filter( 'the_content', array( __CLASS__, 'maybe_autoinsert' ), 20 );
		add_action( 'save_post', array( __CLASS__, 'invalidate_cache_on_save' ) );
		add_action( 'wp_ajax_cmdroom_reco_toggle_enabled', array( __CLASS__, 'handle_toggle_enabled' ) );
	}

	public static function handle_toggle_enabled() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'cmdroom_reco_toggle_enabled', '_wpnonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$opts            = self::get_options();
		$opts['enabled'] = ! empty( $_POST['value'] );
		self::update_options( $opts );
		wp_send_json_success( array( 'enabled' => $opts['enabled'] ) );
	}

	/* — Categoría principal (aproximación: no hay plugin de "primary category" en este sitio) — */

	public static function primary_category_id( $post_id ) {
		$ids = wp_get_post_categories( $post_id, array( 'orderby' => 'term_id', 'order' => 'ASC' ) );
		return $ids ? (int) $ids[0] : 0;
	}

	public static function primary_category( $post_id ) {
		$term_id = self::primary_category_id( $post_id );
		return $term_id ? get_term( $term_id, 'category' ) : null;
	}

	/* — Pools — */

	private static function query_pool( $args, $exclude_id ) {
		$defaults = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'ignore_sticky_posts' => true,
		);
		if ( $exclude_id ) {
			$defaults['post__not_in'] = array( $exclude_id );
		}
		$q = new WP_Query( array_merge( $defaults, $args ) );
		return $q->posts ? $q->posts : array();
	}

	public static function resolve_site_pool( $exclude_id = 0, $limit = 12 ) {
		return self::query_pool( array( 'posts_per_page' => $limit ), $exclude_id );
	}

	public static function resolve_section_pool( $term_id, $exclude_id = 0, $limit = 12 ) {
		if ( ! $term_id ) {
			return array();
		}
		return self::query_pool( array(
			'posts_per_page' => $limit,
			'cat'            => $term_id,
		), $exclude_id );
	}

	/**
	 * Rellena 3 posiciones: los pins (en su orden) se respetan; los huecos se
	 * completan con la cola de $pool, sin repetir ids ya usados y sin incluir
	 * jamás $exclude_id (la propia entrada). $strip_empty=false conserva las
	 * 3 posiciones aunque queden huecos sin contenido (lo usa admin_slots()
	 * para poder decir "posición 3: sin contenido suficiente" en el admin).
	 */
	private static function fill_positions( $pins, $pool, $exclude_id, $strip_empty = true ) {
		$pins  = array_values( array_pad( (array) $pins, 3, 0 ) );
		$used  = array();
		$items = array();

		foreach ( $pins as $pin_id ) {
			$pin_id = (int) $pin_id;
			if ( $pin_id && $pin_id !== (int) $exclude_id && 'publish' === get_post_status( $pin_id ) && ! in_array( $pin_id, $used, true ) ) {
				$items[] = array( 'id' => $pin_id, 'pinned' => true );
				$used[]  = $pin_id;
			} else {
				$items[] = null; // hueco a rellenar
			}
		}

		$queue = array_values( array_diff( $pool, $used, array( (int) $exclude_id ) ) );
		foreach ( $items as $i => $slot ) {
			if ( null === $slot ) {
				$next = array_shift( $queue );
				if ( $next ) {
					$items[ $i ] = array( 'id' => (int) $next, 'pinned' => false );
					$used[]      = (int) $next;
				}
			}
		}

		return $strip_empty ? array_values( array_filter( $items ) ) : $items;
	}

	public static function resolve_items_site( $post_id = 0 ) {
		$opts  = self::get_options();
		$pool  = self::resolve_site_pool( $post_id, 20 );
		$slots = self::fill_positions( $opts['pins_site'], $pool, $post_id );
		return array_map( array( __CLASS__, 'build_item_from_post' ), $slots );
	}

	public static function resolve_items_section( $post_id = 0, $term_id = 0 ) {
		$opts  = self::get_options();
		$pins  = isset( $opts['pins_section'][ $term_id ] ) ? $opts['pins_section'][ $term_id ] : array( 0, 0, 0 );
		$pool  = self::resolve_section_pool( $term_id, $post_id, 20 );
		$slots = self::fill_positions( $pins, $pool, $post_id );

		if ( ! empty( $opts['section_fill'] ) && count( $slots ) < 3 ) {
			$used       = wp_list_pluck( $slots, 'id' );
			$site_pool  = array_values( array_diff( self::resolve_site_pool( $post_id, 20 ), $used ) );
			while ( count( $slots ) < 3 && $site_pool ) {
				$next = array_shift( $site_pool );
				$slots[] = array( 'id' => (int) $next, 'pinned' => false );
			}
		}

		return array_map( array( __CLASS__, 'build_item_from_post' ), $slots );
	}

	/**
	 * Igual que resolve_items_site()/resolve_items_section() pero conserva
	 * exactamente 3 posiciones (con huecos `null` si no hay contenido
	 * suficiente) -- lo usa la pantalla de ajustes para pintar "Posición N"
	 * con su select y el texto "Ahora mismo: {título}" o "sin contenido
	 * suficiente", sin depender de un post real (post_id=0, vista de admin).
	 */
	public static function admin_slots( $mode, $term_id = 0 ) {
		$opts = self::get_options();
		if ( 'section' === $mode ) {
			$pins  = isset( $opts['pins_section'][ $term_id ] ) ? $opts['pins_section'][ $term_id ] : array( 0, 0, 0 );
			$pool  = self::resolve_section_pool( $term_id, 0, 20 );
			$slots = self::fill_positions( $pins, $pool, 0, false );

			if ( ! empty( $opts['section_fill'] ) ) {
				$used      = array_filter( wp_list_pluck( array_filter( $slots ), 'id' ) );
				$site_pool = array_values( array_diff( self::resolve_site_pool( 0, 20 ), $used ) );
				foreach ( $slots as $i => $slot ) {
					if ( null === $slot && $site_pool ) {
						$next    = array_shift( $site_pool );
						$slots[ $i ] = array( 'id' => (int) $next, 'pinned' => false );
					}
				}
			}
			return $slots;
		}
		$pool = self::resolve_site_pool( 0, 20 );
		return self::fill_positions( $opts['pins_site'], $pool, 0, false );
	}

	private static function build_item_from_post( $slot ) {
		$post_id = $slot['id'];
		$cat     = self::primary_category( $post_id );
		return array(
			'title'  => get_the_title( $post_id ),
			'url'    => get_permalink( $post_id ),
			'cat'    => $cat && ! is_wp_error( $cat ) ? $cat->name : '',
			'image'  => get_the_post_thumbnail_url( $post_id, 'medium_large' ),
			'pinned' => ! empty( $slot['pinned'] ),
			'domain' => null,
		);
	}

	/* — Webs amigas (red recíproca) — */

	public static function active_friends() {
		$opts = self::get_options();
		return array_values( array_filter( $opts['network']['friends'], function ( $f ) {
			return ! empty( $f['enabled'] ) && 'ok' === ( isset( $f['status'] ) ? $f['status'] : '' );
		} ) );
	}

	private static function fetch_friend_items( $friend, $shared_terms ) {
		$cats = get_terms( array( 'taxonomy' => 'category', 'include' => $shared_terms, 'fields' => 'slugs', 'hide_empty' => false ) );
		$cats = is_wp_error( $cats ) ? array() : $cats;
		$key  = 'cr_reco_friend_' . md5( $friend['domain'] . implode( ',', $cats ) );

		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = 'https://' . $friend['domain'] . '/wp-json/' . self::REST_NS . '/network/latest';
		if ( $cats ) {
			$url = add_query_arg( 'cats', implode( ',', $cats ), $url );
		}
		$response = wp_safe_remote_get( $url, array( 'timeout' => 3 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $key, array(), HOUR_IN_SECONDS ); // no reintentar cada carga si el sitio amigo no responde
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$items = array();
		if ( is_array( $body ) ) {
			foreach ( $body as $row ) {
				if ( empty( $row['title'] ) || empty( $row['url'] ) ) {
					continue;
				}
				$items[] = array(
					'title'  => sanitize_text_field( $row['title'] ),
					'url'    => esc_url_raw( $row['url'] ),
					'cat'    => isset( $row['cat'] ) ? sanitize_text_field( $row['cat'] ) : '',
					'image'  => isset( $row['image'] ) ? esc_url_raw( $row['image'] ) : '',
					'pinned' => false,
					'domain' => $friend['domain'],
				);
			}
		}
		set_transient( $key, $items, HOUR_IN_SECONDS );
		return $items;
	}

	/**
	 * mix=friends: solo entradas de las webs amigas activas. mix=alternate:
	 * intercala amiga/propia/amiga. Sin amigas activas o sin respuesta válida
	 * de ninguna -- array vacío (degradación segura, ver nota de cabecera).
	 */
	public static function resolve_items_friends( $post_id = 0 ) {
		$opts    = self::get_options();
		$friends = self::active_friends();
		if ( empty( $friends ) ) {
			return array();
		}

		$shared = array_map( 'intval', $opts['network']['shared_terms'] );
		$pool   = array();
		foreach ( $friends as $friend ) {
			$pool = array_merge( $pool, self::fetch_friend_items( $friend, $shared ) );
		}
		$pool = array_slice( $pool, 0, 9 );
		if ( empty( $pool ) ) {
			return array();
		}

		if ( 'alternate' === $opts['network']['mix'] ) {
			$own_pool = self::resolve_site_pool( $post_id, 6 );
			$own      = array_map( array( __CLASS__, 'build_item_from_post' ), array_map( function ( $id ) {
				return array( 'id' => $id, 'pinned' => false );
			}, $own_pool ) );
			$mixed = array();
			for ( $i = 0; $i < 3; $i++ ) {
				if ( isset( $pool[ $i ] ) ) {
					$mixed[] = $pool[ $i ];
				}
				if ( 'alternate' === $opts['network']['mix'] && isset( $own[ $i ] ) && count( $mixed ) < 3 ) {
					$mixed[] = $own[ $i ];
				}
			}
			return array_slice( $mixed, 0, 3 );
		}

		return array_slice( $pool, 0, 3 );
	}

	/* — Resolución según modo activo — */

	public static function resolve_items( $mode, $post_id = 0, $term_id = 0 ) {
		switch ( $mode ) {
			case 'section':
				return self::resolve_items_section( $post_id, $term_id );
			case 'friends':
				return self::resolve_items_friends( $post_id );
			default:
				return self::resolve_items_site( $post_id );
		}
	}

	/* — Front-end: registro — */

	public static function register_shortcode() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode_handler' ) );
	}

	public static function maybe_enqueue_frontend() {
		if ( self::is_enabled() ) {
			wp_enqueue_style( 'cmdroom-reco-frontend', CMDROOM_URL . 'assets/css/reco-frontend.css', array(), CMDROOM_VERSION );
		}
	}

	/**
	 * [cmdroom_relacionados logica="web|seccion|amigas"] -- por defecto usa la
	 * lógica activa configurada; el atributo permite forzar otra puntualmente.
	 */
	public static function shortcode_handler( $atts ) {
		if ( ! self::is_enabled() ) {
			return '';
		}
		$atts = shortcode_atts( array( 'logica' => '' ), $atts, self::SHORTCODE );
		$map  = array( 'web' => 'site', 'seccion' => 'section', 'amigas' => 'friends' );
		$opts = self::get_options();
		$mode = isset( $map[ $atts['logica'] ] ) ? $map[ $atts['logica'] ] : $opts['mode'];

		$post_id = get_the_ID();
		$term_id = 'section' === $mode ? self::primary_category_id( $post_id ) : 0;
		$items   = self::resolve_items( $mode, $post_id, $term_id );

		return self::render( $mode, $items, array( 'title' => $opts['title'], 'new_tab' => $opts['new_tab'], 'rel' => $opts['network']['rel'] ) );
	}

	/**
	 * Igual que Cmdroom_Affiliate::maybe_autoinsert(): corre en 'the_content'
	 * a prioridad 20, DESPUÉS de que un shortcode/bloque puesto a mano ya se
	 * haya expandido -- por eso la comprobación de duplicado mira el marcado
	 * YA renderizado (class="cmdroom-reco) y no el texto crudo del shortcode.
	 */
	public static function maybe_autoinsert( $content ) {
		if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( false !== strpos( $content, 'class="cmdroom-reco' ) ) {
			return $content;
		}

		$opts = self::get_options();
		if ( empty( $opts['enabled'] ) || 'end' !== $opts['placement'] ) {
			return $content;
		}

		$post_id = get_the_ID();
		$html    = self::get_cached_html( $post_id, $opts );
		if ( '' === $html ) {
			return $content;
		}
		return $content . $html;
	}

	/**
	 * La versión va en la clave: al actualizar el plugin el marcado cacheado
	 * con la versión anterior (clases, estructura) se descarta solo, en vez
	 * de servirse hasta una hora contra el CSS nuevo.
	 */
	private static function html_cache_key( $post_id ) {
		return 'cr_reco_html_' . CMDROOM_VERSION . '_' . $post_id;
	}

	private static function get_cached_html( $post_id, $opts ) {
		$key    = self::html_cache_key( $post_id );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$term_id = 'section' === $opts['mode'] ? self::primary_category_id( $post_id ) : 0;
		$items   = self::resolve_items( $opts['mode'], $post_id, $term_id );
		$html    = self::render( $opts['mode'], $items, array( 'title' => $opts['title'], 'new_tab' => $opts['new_tab'], 'rel' => $opts['network']['rel'] ) );
		set_transient( $key, $html, HOUR_IN_SECONDS );
		return $html;
	}

	public static function invalidate_cache_on_save( $post_id ) {
		delete_transient( self::html_cache_key( $post_id ) );
	}

	/* — Render compartido (admin + front-end) — */

	/**
	 * $ctx: title, new_tab, rel, admin_preview (bool -- añade el pill
	 * "Fijado"/dominio con el mismo marcado que pide la vista previa del
	 * handoff; en el front-end real ese pill nunca se imprime porque
	 * admin_preview siempre es false ahí -- sigue siendo una única función de
	 * render, la única diferencia es un flag de contexto, igual que
	 * Cmdroom_Affiliate::render() usa $featured para decorar la tarjeta).
	 */
	public static function render( $mode, $items, $ctx = array() ) {
		$items = array_values( array_filter( (array) $items ) );
		if ( empty( $items ) ) {
			return '';
		}
		$title    = isset( $ctx['title'] ) ? $ctx['title'] : self::DEFAULTS['title'];
		$new_tab  = ! empty( $ctx['new_tab'] );
		$rel      = isset( $ctx['rel'] ) && in_array( $ctx['rel'], self::RELS, true ) ? $ctx['rel'] : 'nofollow';
		$admin_preview = ! empty( $ctx['admin_preview'] );
		$target   = $new_tab ? ' target="_blank" rel="' . esc_attr( $rel ) . ' noopener"' : ' rel="' . esc_attr( $rel ) . '"';

		ob_start();
		?>
		<section class="cmdroom-reco" data-reco-mode="<?php echo esc_attr( $mode ); ?>">
			<h2 class="cr-reco-title"><?php echo esc_html( $title ); ?></h2>
			<ul class="cr-reco-list">
				<?php foreach ( array_slice( $items, 0, 3 ) as $item ) : ?>
					<li class="cr-reco-item">
						<a class="cr-reco-link" href="<?php echo esc_url( $item['url'] ); ?>"<?php echo $target; ?>>
							<span class="cr-reco-media">
								<?php if ( ! empty( $item['image'] ) ) : ?>
									<img src="<?php echo esc_url( $item['image'] ); ?>" alt="" loading="lazy" />
								<?php else : ?>
									<span class="cr-reco-img-placeholder" aria-hidden="true"></span>
								<?php endif; ?>
							</span>
							<span class="cr-reco-body">
								<span class="cr-reco-meta-row">
									<?php if ( ! empty( $item['cat'] ) ) : ?><span class="cr-reco-cat"><?php echo esc_html( $item['cat'] ); ?></span><?php endif; ?>
									<?php if ( $admin_preview && ! empty( $item['pinned'] ) ) : ?><span class="cr-pill cr-reco-pill-pinned"><?php esc_html_e( 'Fijado', 'command-room' ); ?></span><?php endif; ?>
								</span>
								<span class="cr-reco-item-title"><?php echo esc_html( $item['title'] ); ?></span>
								<?php if ( ! empty( $item['domain'] ) ) : ?><span class="cr-reco-domain"><?php echo esc_html( $item['domain'] ); ?> ↗</span><?php endif; ?>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
		return ob_get_clean();
	}

	/* — REST: red de webs amigas — */

	public static function register_rest_routes() {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}
		register_rest_route( self::REST_NS, '/network/latest', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'rest_network_latest' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'cats' => array( 'type' => 'string', 'default' => '' ) ),
		) );
		register_rest_route( self::REST_NS, '/network/invite', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_network_invite' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Expone las últimas entradas de las categorías que ESTE sitio ha marcado
	 * como compartidas (network.shared_terms) -- nunca más allá de eso, y
	 * nada si el módulo/lógica de amigas está desactivada (desactivar
	 * 'friends' deja de exponer el feed, tal como pide el handoff). Cacheado
	 * en transient 1h.
	 */
	public static function rest_network_latest( WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return new WP_REST_Response( array(), 200 );
		}
		$opts   = self::get_options();
		$shared = array_map( 'intval', $opts['network']['shared_terms'] );
		if ( empty( $shared ) ) {
			return new WP_REST_Response( array(), 200 );
		}

		$requested_slugs = array_filter( array_map( 'sanitize_title', explode( ',', (string) $request->get_param( 'cats' ) ) ) );
		$cache_key = 'cr_reco_expose_' . md5( implode( ',', $shared ) . '|' . implode( ',', $requested_slugs ) );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$term_ids = $shared;
		if ( $requested_slugs ) {
			$matching = get_terms( array( 'taxonomy' => 'category', 'slug' => $requested_slugs, 'fields' => 'ids', 'hide_empty' => false ) );
			$matching = is_wp_error( $matching ) ? array() : array_map( 'intval', $matching );
			$term_ids = array_values( array_intersect( $shared, $matching ) );
		}
		if ( empty( $term_ids ) ) {
			set_transient( $cache_key, array(), HOUR_IN_SECONDS );
			return new WP_REST_Response( array(), 200 );
		}

		$ids = self::query_pool( array( 'posts_per_page' => 12, 'cat' => implode( ',', $term_ids ) ), 0 );
		$out = array();
		foreach ( $ids as $id ) {
			$cat = self::primary_category( $id );
			$out[] = array(
				'title' => get_the_title( $id ),
				'url'   => get_permalink( $id ),
				'cat'   => $cat && ! is_wp_error( $cat ) ? $cat->name : '',
				'image' => get_the_post_thumbnail_url( $id, 'medium_large' ),
			);
		}
		set_transient( $cache_key, $out, HOUR_IN_SECONDS );
		return new WP_REST_Response( $out, 200 );
	}

	/**
	 * Recibe una invitación de otro sitio con Command Room. El endpoint es
	 * público, así que cualquiera puede llamarlo: la invitación se guarda EN
	 * PAUSA (enabled false) y no pinta nada en el carrusel hasta que el
	 * administrador la activa -- ver nota de cabecera de la clase. Payload
	 * inválido -- 400; lista llena -- 429; en ambos casos no se guarda nada.
	 */
	public static function rest_network_invite( WP_REST_Request $request ) {
		$domain = sanitize_text_field( (string) $request->get_param( 'domain' ) );
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = trim( $domain, "/ \t\n\r\0\x0B" );
		$domain = strtolower( $domain );
		if ( '' === $domain || strlen( $domain ) > 253 || ! preg_match( '/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_domain' ), 400 );
		}
		$token = substr( sanitize_text_field( (string) $request->get_param( 'token' ) ), 0, 64 );

		$opts    = self::get_options();
		$friends = $opts['network']['friends'];
		foreach ( $friends as $f ) {
			if ( isset( $f['domain'] ) && strtolower( $f['domain'] ) === strtolower( $domain ) ) {
				return new WP_REST_Response( array( 'status' => 'already_connected' ), 200 );
			}
		}
		if ( count( $friends ) >= self::MAX_FRIENDS ) {
			return new WP_REST_Response( array( 'error' => 'too_many_friends' ), 429 );
		}
		$friends[] = array(
			'domain'  => $domain,
			'status'  => 'ok',
			'enabled' => false, // en pausa hasta que el administrador la active
			'token'   => $token,
			'note'    => sprintf( __( 'Invitación recibida el %s', 'command-room' ), date_i18n( 'd/m/Y' ) ),
		);
		$opts['network']['friends'] = $friends;
		self::update_options( $opts );

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Envía nuestra invitación al endpoint del sitio amigo. Best-effort: si
	 * el sitio remoto no responde o no tiene el plugin, la web se guarda
	 * igualmente como 'pending' en nuestra lista -- el propio handoff no
	 * puede probarse de extremo a extremo sin un segundo sitio real.
	 */
	public static function send_invite( $domain ) {
		$token = wp_generate_password( 24, false );
		wp_safe_remote_post( 'https://' . $domain . '/wp-json/' . self::REST_NS . '/network/invite', array(
			'timeout' => 3,
			'body'    => array(
				'domain' => wp_parse_url( home_url(), PHP_URL_HOST ),
				'token'  => $token,
			),
		) );
		return $token;
	}
}
