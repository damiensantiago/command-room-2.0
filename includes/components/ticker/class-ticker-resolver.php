<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Motor de resolución de mensajes del Ticker -- única fuente de verdad para
 * los tres modos (Automático/Configurado/Mixto), usada tanto por la vista
 * previa del admin (Cmdroom_Ticker_Settings) como por la salida real en el
 * sitio (Cmdroom_Ticker). Vive separada de ambas para que la vista previa
 * sea garantizadamente igual al resultado real -- ninguna de las dos
 * reimplementa el algoritmo por su cuenta.
 *
 * Las fuentes de WooCommerce (sale/shipping/coupons) usan la API CRUD de
 * WooCommerce (WC_Coupon, wc_get_product) en vez de leer postmeta a mano --
 * es la única forma soportada de no romper si WooCommerce cambia su
 * almacenamiento interno.
 */
class Cmdroom_Ticker_Resolver {

	const DAY_KEYS = array( 'lun', 'mar', 'mie', 'jue', 'vie', 'fds' );

	public static function day_labels() {
		return array(
			'lun' => __( 'Lunes', 'command-room' ),
			'mar' => __( 'Martes', 'command-room' ),
			'mie' => __( 'Miércoles', 'command-room' ),
			'jue' => __( 'Jueves', 'command-room' ),
			'vie' => __( 'Viernes', 'command-room' ),
			'fds' => __( 'Sábado y domingo', 'command-room' ),
		);
	}

	/**
	 * lun..vie = días 1-5 de current_time('N'); sábado(6)/domingo(7) = 'fds'.
	 */
	public static function current_day_key() {
		$n     = (int) current_time( 'N' );
		$order = array( 1 => 'lun', 2 => 'mar', 3 => 'mie', 4 => 'jue', 5 => 'vie', 6 => 'fds', 7 => 'fds' );
		return isset( $order[ $n ] ) ? $order[ $n ] : 'lun';
	}

	public static function has_woocommerce() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Público -- también lo usa Cmdroom_Ticker_Settings para la línea
	 * "Sale como: …" de cada fuente Automática (con valores de ejemplo, no
	 * datos reales, para no depender de que exista contenido/producto).
	 */
	public static function apply_vars( $tpl, $vars ) {
		$search  = array_keys( $vars );
		$replace = array_values( $vars );
		return str_replace( $search, $replace, (string) $tpl );
	}

	/**
	 * Catálogo de variables propias del modo Automático -- se documentan en
	 * su propia sección del Glosario de variables (no en el catálogo
	 * general de Metas: solo tienen sentido dentro de las plantillas del
	 * Ticker, igual que las de Auto-Image SEO tienen las suyas).
	 */
	public static function variables_catalog() {
		return array(
			array( 'tag' => '%title%', 'desc' => __( 'Título de la entrada nueva.', 'command-room' ) ),
			array( 'tag' => '%product_name%', 'desc' => __( 'Nombre del producto en oferta.', 'command-room' ) ),
			array( 'tag' => '%discount%', 'desc' => __( 'Porcentaje de descuento, sin el símbolo % (la plantilla ya lo trae).', 'command-room' ) ),
			array( 'tag' => '%free_shipping_min%', 'desc' => __( 'Importe mínimo de envío gratis, formateado con el precio de WooCommerce.', 'command-room' ) ),
			array( 'tag' => '%coupon_code%', 'desc' => __( 'Código del cupón.', 'command-room' ) ),
			array( 'tag' => '%coupon_amount%', 'desc' => __( 'Importe del cupón: "10%" o "5 €" según su tipo.', 'command-room' ) ),
		);
	}

	/** Techo de artículos por fuente que se ofrecen a la vista previa en vivo del admin -- ver raw_auto_sources(). */
	const PREVIEW_RAW_CAP = 20;

	/** Ventana de días, generosa, para el fetch en bruto de posts que alimenta la vista previa en vivo (JS re-filtra por la "vigencia" real que esté escrita en el campo en ese momento). */
	const PREVIEW_POSTS_WINDOW_DAYS = 365;

	/**
	 * @return array Msg[] de la fuente "Últimas entradas del blog".
	 */
	private static function resolve_posts_source( $cfg, $days, $max ) {
		$cutoff = time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS );
		$out    = array();
		foreach ( self::raw_posts_source() as $raw ) {
			if ( $raw['ts'] < $cutoff ) {
				continue; // raw_posts_source() trae un pool amplio (365 días); aquí se aplica la vigencia real.
			}
			if ( count( $out ) >= max( 1, (int) $max ) ) {
				break;
			}
			$out[] = array(
				'text' => self::apply_vars( $cfg['tpl'], $raw['vars'] ),
				'url'  => $raw['url'],
			);
		}
		return $out;
	}

	/**
	 * Pool amplio (365 días, hasta 20 items) sin plantilla aplicada -- cada
	 * item lleva sus variables en bruto + fecha. Lo usan tanto
	 * resolve_posts_source() (que filtra por la vigencia real) como la vista
	 * previa en vivo del admin, que re-filtra/re-aplica plantilla en el
	 * navegador sin volver a consultar el servidor -- ver
	 * Cmdroom_Ticker_Settings::render_preview_data_script().
	 */
	private static function raw_posts_source() {
		$q = new WP_Query( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => self::PREVIEW_RAW_CAP,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'date_query'     => array( array( 'after' => sprintf( '%d days ago', self::PREVIEW_POSTS_WINDOW_DAYS ) ) ),
			'no_found_rows'  => true,
			'ignore_sticky_posts' => true,
		) );
		$out = array();
		foreach ( $q->posts as $post ) {
			$out[] = array(
				'vars' => array( '%title%' => get_the_title( $post ) ),
				'url'  => get_permalink( $post ),
				'ts'   => get_post_time( 'U', true, $post ),
			);
		}
		return $out;
	}

	private static function resolve_sale_source( $cfg, $max ) {
		$out = array();
		foreach ( array_slice( self::raw_sale_source(), 0, max( 1, (int) $max ) ) as $raw ) {
			$out[] = array(
				'text' => self::apply_vars( $cfg['tpl'], $raw['vars'] ),
				'url'  => $raw['url'],
			);
		}
		return $out;
	}

	private static function raw_sale_source() {
		if ( ! self::has_woocommerce() || ! function_exists( 'wc_get_product_ids_on_sale' ) ) {
			return array();
		}
		$ids = array_slice( (array) wc_get_product_ids_on_sale(), 0, self::PREVIEW_RAW_CAP );
		$out = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$regular  = (float) $product->get_regular_price();
			$sale     = (float) $product->get_sale_price();
			$discount = $regular > 0 ? (int) round( ( $regular - $sale ) / $regular * 100 ) : 0;
			$out[]    = array(
				'vars' => array(
					'%product_name%' => $product->get_name(),
					'%discount%'     => (string) $discount,
				),
				'url'  => get_permalink( $id ),
			);
		}
		return $out;
	}

	/**
	 * WooCommerce no tiene una "página de envíos" propia -- el mensaje sale
	 * sin enlace salvo que el tema o el administrador filtre `cmdroom_ticker_shipping_url`.
	 */
	private static function resolve_shipping_source( $cfg ) {
		$out = array();
		foreach ( self::raw_shipping_source() as $raw ) {
			$out[] = array(
				'text' => self::apply_vars( $cfg['tpl'], $raw['vars'] ),
				'url'  => $raw['url'],
			);
		}
		return $out;
	}

	private static function raw_shipping_source() {
		if ( ! self::has_woocommerce() || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}
		$min = self::get_free_shipping_min();
		if ( null === $min ) {
			return array();
		}
		$amount = function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $min ) ) : number_format_i18n( $min, 2 );
		return array( array(
			'vars' => array( '%free_shipping_min%' => $amount ),
			'url'  => apply_filters( 'cmdroom_ticker_shipping_url', '' ),
		) );
	}

	/**
	 * Busca el primer método "Envío gratis" activo, empezando por las zonas
	 * definidas y terminando por la zona 0 (resto del mundo).
	 */
	private static function get_free_shipping_min() {
		$zones   = WC_Shipping_Zones::get_zones();
		$zone_ids = wp_list_pluck( $zones, 'zone_id' );
		$zone_ids[] = 0;
		foreach ( $zone_ids as $zone_id ) {
			$zone = new WC_Shipping_Zone( $zone_id );
			foreach ( $zone->get_shipping_methods( true ) as $method ) {
				if ( 'free_shipping' === $method->id && 'yes' === $method->enabled ) {
					$min = $method->get_option( 'min_amount' );
					if ( '' !== $min && null !== $min ) {
						return (float) $min;
					}
				}
			}
		}
		return null;
	}

	private static function resolve_coupons_source( $cfg, $max ) {
		$out = array();
		foreach ( array_slice( self::raw_coupons_source(), 0, max( 1, (int) $max ) ) as $raw ) {
			$out[] = array(
				'text' => self::apply_vars( $cfg['tpl'], $raw['vars'] ),
				'url'  => $raw['url'],
			);
		}
		return $out;
	}

	private static function raw_coupons_source() {
		if ( ! self::has_woocommerce() ) {
			return array();
		}
		$q = new WP_Query( array(
			'post_type'      => 'shop_coupon',
			'post_status'    => 'publish',
			'posts_per_page' => self::PREVIEW_RAW_CAP,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $q->posts as $post ) {
			$coupon  = new WC_Coupon( $post->ID );
			$expires = $coupon->get_date_expires();
			if ( $expires && $expires->getTimestamp() < time() ) {
				continue; // caducado -- "dentro de fecha" del handoff.
			}
			$amount = 'percent' === $coupon->get_discount_type()
				? $coupon->get_amount() . '%'
				: ( function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $coupon->get_amount() ) ) : $coupon->get_amount() );
			$out[] = array(
				'vars' => array(
					'%coupon_code%'   => $coupon->get_code(),
					'%coupon_amount%' => $amount,
				),
				'url'  => '',
			);
		}
		return $out;
	}

	/**
	 * Junta el pool en bruto de las 4 fuentes -- lo usa la vista previa en
	 * vivo del admin (Automático) para recalcular en el navegador sin volver
	 * a pedir nada al servidor cuando el administrador cambia plantilla/on-off/
	 * vigencia/máximo. 'posts' lleva 'ts' (fecha) para poder re-filtrar por
	 * vigencia en JS; las demás no tienen ventana de días.
	 */
	public static function raw_auto_sources() {
		return array(
			'posts'    => self::raw_posts_source(),
			'sale'     => self::raw_sale_source(),
			'shipping' => self::raw_shipping_source(),
			'coupons'  => self::raw_coupons_source(),
		);
	}

	/**
	 * @param array $auto ['sources' => ['posts'=>['on','tpl'], 'sale'=>...], 'days', 'max_per_source']
	 * @return array Msg[]
	 */
	public static function resolve_auto( $auto ) {
		$sources = isset( $auto['sources'] ) ? $auto['sources'] : array();
		$max     = isset( $auto['max_per_source'] ) ? (int) $auto['max_per_source'] : 3;
		$days    = isset( $auto['days'] ) ? (int) $auto['days'] : 7;
		$out     = array();

		if ( ! empty( $sources['posts']['on'] ) ) {
			$out = array_merge( $out, self::resolve_posts_source( $sources['posts'], $days, $max ) );
		}
		if ( ! empty( $sources['sale']['on'] ) ) {
			$out = array_merge( $out, self::resolve_sale_source( $sources['sale'], $max ) );
		}
		if ( ! empty( $sources['shipping']['on'] ) ) {
			$out = array_merge( $out, self::resolve_shipping_source( $sources['shipping'] ) );
		}
		if ( ! empty( $sources['coupons']['on'] ) ) {
			$out = array_merge( $out, self::resolve_coupons_source( $sources['coupons'], $max ) );
		}
		return $out;
	}

	/**
	 * @param array       $manual  ['by_day'=>bool, 'all'=>Msg[], 'lun'=>Msg[]...]
	 * @param string|null $day_key Si es null, se calcula con current_day_key().
	 * @return array Msg[]
	 */
	public static function resolve_manual( $manual, $day_key = null ) {
		if ( empty( $manual['by_day'] ) ) {
			return isset( $manual['all'] ) ? $manual['all'] : array();
		}
		$day_key = $day_key ? $day_key : self::current_day_key();
		return isset( $manual[ $day_key ] ) ? $manual[ $day_key ] : array();
	}

	/**
	 * @param array $mixed  ['order'=>'interleave'|'fixed'|'auto', 'ratio'=>int, 'max'=>int]
	 * @param array $fixed  Msg[] (de Configurado)
	 * @param array $auto   Msg[] (de Automático)
	 * @return array Msg[]
	 */
	public static function resolve_mixed( $mixed, $fixed, $auto ) {
		$order = isset( $mixed['order'] ) ? $mixed['order'] : 'interleave';
		$ratio = max( 1, isset( $mixed['ratio'] ) ? (int) $mixed['ratio'] : 2 );
		$max   = max( 1, isset( $mixed['max'] ) ? (int) $mixed['max'] : 8 );

		$tag = function ( $msgs, $source ) {
			return array_map( function ( $m ) use ( $source ) {
				$m['source'] = $source;
				return $m;
			}, $msgs );
		};

		if ( 'fixed' === $order ) {
			$out = array_merge( $tag( $fixed, 'fixed' ), $tag( $auto, 'auto' ) );
		} elseif ( 'auto' === $order ) {
			$out = array_merge( $tag( $auto, 'auto' ), $tag( $fixed, 'fixed' ) );
		} else {
			// Intercalar: 1 fijo + N automáticos, repetido hasta agotar
			// ambas listas.
			$out = array();
			$fi  = 0;
			$ai  = 0;
			while ( $fi < count( $fixed ) || $ai < count( $auto ) ) {
				if ( $fi < count( $fixed ) ) {
					$msg           = $fixed[ $fi ];
					$msg['source'] = 'fixed';
					$out[]         = $msg;
					$fi++;
				}
				for ( $k = 0; $k < $ratio && $ai < count( $auto ); $k++ ) {
					$msg           = $auto[ $ai ];
					$msg['source'] = 'auto';
					$out[]         = $msg;
					$ai++;
				}
			}
		}

		return array_slice( $out, 0, $max );
	}

	/**
	 * Punto de entrada único -- resuelve los mensajes finales para el modo
	 * indicado. $day_key solo se usa si $mode es 'manual' con by_day, o
	 * 'mixed' con un Configurado por días.
	 */
	public static function resolve_for_mode( $opts, $mode, $day_key = null ) {
		switch ( $mode ) {
			case 'auto':
				return self::resolve_auto( $opts['auto'] );
			case 'mixed':
				$fixed = self::resolve_manual( $opts['manual'], $day_key );
				$auto  = self::resolve_auto( $opts['auto'] );
				return self::resolve_mixed( $opts['mixed'], $fixed, $auto );
			case 'manual':
			default:
				return self::resolve_manual( $opts['manual'], $day_key );
		}
	}
}
