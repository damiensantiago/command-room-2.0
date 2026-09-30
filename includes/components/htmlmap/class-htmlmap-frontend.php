<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Command Room -- "Sitemap HTML", front-end público.
 *
 * Sirve /{slug}/, /{slug}/{año}/, /{slug}/{año}/{mes}/,
 * /{slug}/{año}/{mes}/{día}/ (+ /page/N/) y /{slug}/tags/ con los mismos
 * métodos de datos que la vista previa del admin (Cmdroom_Htmlmap), el
 * shortcode [cr_sitemap_html] (bloques de la portada dentro de cualquier
 * página) y el enlace "Mapa del sitio" del footer.
 *
 * Mismo patrón que Cmdroom_Sitemap_Rewrite y Cmdroom_Ia_Output: se
 * intercepta el REQUEST_URI en template_redirect, SIN add_rewrite_rule()/
 * flush_rewrite_rules() -- este hosting ya ha dado problemas con el refresco
 * de reglas, y así cambiar el slug o apagar el componente no deja reglas
 * huérfanas en la tabla de rewrites. WordPress ya ha marcado la petición
 * como 404 cuando llega aquí, así que se desmarca y la página se pinta con
 * la cabecera y el pie del propio tema vía template_include.
 *
 * Gated por dos interruptores: el componente activo en "Ver todos"
 * (Cmdroom_Components_Admin) y el toggle "Activar sitemap HTML". Con
 * cualquiera de los dos apagado, estas URLs vuelven a dar el 404 normal.
 */
class Cmdroom_Htmlmap_Frontend {

	/** Estado de la petición actual (view/y/m/d/page) o false si no es nuestra. */
	private static $state = null;

	private static $footer_link_printed = false;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_take_over' ), 0 );
		add_shortcode( 'cr_sitemap_html', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'maybe_print_footer_fallback' ), 5 );
	}

	public static function is_live() {
		$opts = Cmdroom_Htmlmap::get_options();
		return ! empty( $opts['enabled'] ) && in_array( 'htmlmap', Cmdroom_Components_Admin::get_enabled_keys(), true );
	}

	/* ---------------------------------------------------------------- */
	/* URLs                                                              */
	/* ---------------------------------------------------------------- */

	public static function url( $view = 'home', $y = 0, $m = 0, $d = 0, $page = 1 ) {
		$opts = Cmdroom_Htmlmap::get_options();
		$path = $opts['slug'];
		switch ( $view ) {
			case 'tags':
				$path .= '/tags';
				break;
			case 'year':
				$path .= '/' . (int) $y;
				break;
			case 'month':
				$path .= sprintf( '/%d/%02d', $y, $m );
				break;
			case 'day':
				$path .= sprintf( '/%d/%02d/%02d', $y, $m, $d );
				if ( $page > 1 ) {
					$path .= '/page/' . (int) $page;
				}
				break;
		}
		return home_url( user_trailingslashit( $path ) );
	}

	/* ---------------------------------------------------------------- */
	/* Resolución de la petición                                         */
	/* ---------------------------------------------------------------- */

	/**
	 * Traduce el REQUEST_URI a un estado válido, o false. Una fecha sin
	 * contenido, por delante de hoy o una página fuera de rango devuelve
	 * false (404), igual que un archivo de fecha vacío de WordPress: desde
	 * el propio sitemap nunca se enlazan.
	 */
	private static function parse_request() {
		$opts      = Cmdroom_Htmlmap::get_options();
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path      = (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = trim( $path, '/' );
		$slug = preg_quote( $opts['slug'], '#' );

		if ( preg_match( '#^' . $slug . '/tags$#', $path ) ) {
			return array( 'view' => 'tags', 'y' => 0, 'm' => 0, 'd' => 0, 'page' => 1 );
		}
		if ( ! preg_match( '#^' . $slug . '(?:/(\d{4})(?:/(\d{2})(?:/(\d{2}))?)?)?(?:/page/(\d+))?$#', $path, $mt ) ) {
			return false;
		}

		$y    = isset( $mt[1] ) && '' !== $mt[1] ? (int) $mt[1] : 0;
		$m    = isset( $mt[2] ) && '' !== $mt[2] ? (int) $mt[2] : 0;
		$d    = isset( $mt[3] ) && '' !== $mt[3] ? (int) $mt[3] : 0;
		$page = isset( $mt[4] ) && '' !== $mt[4] ? (int) $mt[4] : 1;

		$view = $d ? 'day' : ( $m ? 'month' : ( $y ? 'year' : 'home' ) );
		if ( 'day' !== $view && isset( $mt[4] ) && '' !== $mt[4] ) {
			return false; // /page/N/ solo existe en el nivel Día
		}
		if ( $page < 1 ) {
			return false;
		}

		$types = Cmdroom_Htmlmap::enabled_post_types( $opts );
		list( $ty, $tm, $td ) = Cmdroom_Htmlmap::today();

		if ( 'year' === $view ) {
			if ( $y > $ty || ! Cmdroom_Htmlmap::count_year( $types, $y ) ) {
				return false;
			}
		} elseif ( 'month' === $view ) {
			if ( $m < 1 || $m > 12 || mktime( 0, 0, 0, $m, 1, $y ) > mktime( 0, 0, 0, $tm, 1, $ty ) || ! Cmdroom_Htmlmap::count_month( $types, $y, $m ) ) {
				return false;
			}
		} elseif ( 'day' === $view ) {
			if ( ! checkdate( $m, $d, $y ) || mktime( 0, 0, 0, $m, $d, $y ) > mktime( 0, 0, 0, $tm, $td, $ty ) ) {
				return false;
			}
			$n = Cmdroom_Htmlmap::count_day( $types, $y, $m, $d );
			if ( ! $n || $page > (int) ceil( $n / max( 1, (int) $opts['per_day'] ) ) ) {
				return false;
			}
		}

		return compact( 'view', 'y', 'm', 'd', 'page' );
	}

	public static function maybe_take_over() {
		if ( is_admin() || ! self::is_live() ) {
			return;
		}
		$state = self::parse_request();
		if ( ! $state ) {
			return; // deja que WordPress siga su curso normal (404)
		}

		// Forma canónica con o sin barra final, según los enlaces permanentes.
		$canonical = self::url( $state['view'], $state['y'], $state['m'], $state['d'], $state['page'] );
		$req_path  = (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
		if ( $req_path !== (string) wp_parse_url( $canonical, PHP_URL_PATH ) ) {
			wp_safe_redirect( $canonical, 301 );
			exit;
		}

		self::$state = $state;

		// WP::handle_404() ya ha marcado la petición como no encontrada y ha
		// mandado cabeceras 404 + no-cache (mismo caso que los sitemaps, ver
		// Cmdroom_Sitemap_Rewrite::output()). Se deshace antes de pintar.
		global $wp_query;
		$wp_query->is_404 = false;
		status_header( 200 );
		header_remove( 'Cache-Control' );
		header_remove( 'Expires' );
		header_remove( 'Pragma' );
		// LiteSpeed trata estas URLs como 404 y les aplica ese TTL; se fija
		// una hora explícita para que "Últimos 7 días" no se quede viejo.
		do_action( 'litespeed_control_set_ttl', HOUR_IN_SECONDS );

		remove_action( 'template_redirect', 'redirect_canonical' );
		add_filter( 'template_include', array( __CLASS__, 'template' ), 99 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 99 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 10000 ); // después de Cmdroom_Meta_Output::silence_native_robots (9999)
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'print_head' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/* ---------------------------------------------------------------- */
	/* <head>                                                            */
	/* ---------------------------------------------------------------- */

	private static function title_subtitle() {
		$opts  = Cmdroom_Htmlmap::get_options();
		$types = Cmdroom_Htmlmap::enabled_post_types( $opts );
		return Cmdroom_Htmlmap_Settings::head_title_subtitle( $opts, self::$state, $types );
	}

	public static function document_title() {
		list( $title ) = self::title_subtitle();
		$parts = array( $title );
		if ( 'home' !== self::$state['view'] ) {
			$parts[] = __( 'Mapa del sitio', 'command-room' );
		}
		if ( self::$state['page'] > 1 ) {
			$parts[] = sprintf( __( 'Página %d', 'command-room' ), self::$state['page'] );
		}
		$parts[] = get_bloginfo( 'name' );
		return implode( ' · ', $parts );
	}

	/**
	 * Portada y Tags siempre index; Año/Mes/Día según el ajuste
	 * "Archivos por fecha" (noindex, follow de fábrica). follow siempre:
	 * el valor de estas páginas para el rastreo son sus enlaces.
	 */
	public static function robots() {
		$opts    = Cmdroom_Htmlmap::get_options();
		$is_date = in_array( self::$state['view'], array( 'year', 'month', 'day' ), true );
		if ( $is_date && 'noindex' === $opts['date_robots'] ) {
			return array( 'noindex' => true, 'follow' => true );
		}
		return array( 'index' => true, 'follow' => true, 'max-image-preview' => 'large' );
	}

	public static function body_class( $classes ) {
		$classes[] = 'cmdroom-htmlmap-page';
		$classes[] = 'cmdroom-htmlmap-' . self::$state['view'];
		return $classes;
	}

	public static function print_head() {
		// Con la "Salida en el sitio" de Metas activa, Cmdroom_Meta_Output ya
		// ha quitado el <title> nativo (wp_head prioridad 0) y aquí no
		// resuelve nada -- estas URLs no son ningún contexto de WordPress.
		if ( ! has_action( 'wp_head', '_wp_render_title_tag' ) ) {
			printf( "<title>%s</title>\n", esc_html( self::document_title() ) );
		}
		list( , $subtitle ) = self::title_subtitle();
		printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $subtitle ) );
		printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( self::url( self::$state['view'], self::$state['y'], self::$state['m'], self::$state['d'], self::$state['page'] ) ) );
	}

	public static function enqueue() {
		wp_enqueue_style( 'cmdroom-htmlmap-frontend', CMDROOM_URL . 'assets/css/htmlmap-frontend.css', array(), CMDROOM_VERSION );
	}

	/* ---------------------------------------------------------------- */
	/* Plantilla -- cabecera y pie del tema                              */
	/* ---------------------------------------------------------------- */

	public static function template() {
		return CMDROOM_DIR . 'includes/components/htmlmap/template-htmlmap.php';
	}

	/**
	 * Tema clásico con header.php → get_header(). Temas que cargan la
	 * cabecera como template part (p. ej. template-parts/header.php) →
	 * esa. Temas de bloques o sin ninguna de las dos → esqueleto mínimo con
	 * wp_head() y la parte "header" del editor de sitio si existe.
	 */
	/**
	 * file_exists() en vez de locate_template(): locate_template() cae en
	 * wp-includes/theme-compat/ cuando el tema no tiene el archivo, y esa
	 * cabecera obsoleta imprime su propio <title> y un <head> ajeno al tema.
	 */
	private static function theme_has( $file ) {
		return file_exists( get_stylesheet_directory() . '/' . $file ) || file_exists( get_template_directory() . '/' . $file );
	}

	public static function load_header() {
		if ( self::theme_has( 'header.php' ) ) {
			get_header();
		} elseif ( self::theme_has( 'template-parts/header.php' ) ) {
			get_template_part( 'template-parts/header' );
		} else {
			?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
			wp_body_open();
			if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
				block_template_part( 'header' );
			}
		}
	}

	public static function load_footer() {
		if ( self::theme_has( 'footer.php' ) ) {
			get_footer();
		} elseif ( self::theme_has( 'template-parts/footer.php' ) ) {
			get_template_part( 'template-parts/footer' );
		} else {
			if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
				block_template_part( 'footer' );
			}
			wp_footer();
			echo "</body>\n</html>\n";
		}
	}

	/* ---------------------------------------------------------------- */
	/* Cuerpo                                                            */
	/* ---------------------------------------------------------------- */

	public static function render_page() {
		$opts  = Cmdroom_Htmlmap::get_options();
		$types = Cmdroom_Htmlmap::enabled_post_types( $opts );
		$state = self::$state;
		list( $title, $subtitle ) = self::title_subtitle();
		?>
		<div class="cr-hm">
			<?php self::render_breadcrumbs( $state ); ?>
			<header class="cr-hm__head">
				<h1 class="cr-hm__title"><?php echo esc_html( $title ); ?></h1>
				<p class="cr-hm__sub"><?php echo esc_html( $subtitle ); ?></p>
			</header>
			<?php
			switch ( $state['view'] ) {
				case 'home':
					self::render_home_blocks( $opts, $types );
					break;
				case 'year':
					self::render_year_grid( $opts, $types, $state['y'] );
					self::render_extra( $opts, $types, true );
					break;
				case 'month':
					self::render_month_grid( $opts, $types, $state['y'], $state['m'] );
					self::render_extra( $opts, $types, true );
					break;
				case 'day':
					self::render_day_listing( $opts, $types, $state['y'], $state['m'], $state['d'], $state['page'] );
					self::render_extra( $opts, $types, true );
					break;
				case 'tags':
					self::render_tags_az();
					self::render_extra( $opts, $types, false );
					break;
			}
			?>
		</div>
		<?php
	}

	private static function fmt_count( $n ) {
		return sprintf( _n( '%s contenido', '%s contenidos', $n, 'command-room' ), number_format_i18n( $n ) );
	}

	private static function render_breadcrumbs( $state ) {
		$crumbs = array( array( __( 'Mapa del sitio', 'command-room' ), self::url() ) );
		if ( in_array( $state['view'], array( 'year', 'month', 'day' ), true ) ) {
			$crumbs[] = array( (string) $state['y'], self::url( 'year', $state['y'] ) );
		}
		if ( in_array( $state['view'], array( 'month', 'day' ), true ) ) {
			$crumbs[] = array( Cmdroom_Htmlmap::MESES[ $state['m'] ], self::url( 'month', $state['y'], $state['m'] ) );
		}
		if ( 'day' === $state['view'] ) {
			$crumbs[] = array( (string) $state['d'], self::url( 'day', $state['y'], $state['m'], $state['d'] ) );
		}
		if ( 'tags' === $state['view'] ) {
			$crumbs[] = array( __( 'Tags', 'command-room' ), self::url( 'tags' ) );
		}
		if ( 1 === count( $crumbs ) ) {
			return; // en la portada la miga única sería el propio título
		}
		?>
		<nav class="cr-hm__crumbs" aria-label="<?php esc_attr_e( 'Migas de pan', 'command-room' ); ?>">
			<?php foreach ( $crumbs as $i => $c ) :
				$is_last = ( $i === count( $crumbs ) - 1 );
				?>
				<?php if ( $i > 0 ) : ?><span class="cr-hm__crumb-sep" aria-hidden="true">/</span><?php endif; ?>
				<?php if ( $is_last ) : ?>
					<span aria-current="page"><?php echo esc_html( $c[0] ); ?></span>
				<?php else : ?>
					<a href="<?php echo esc_url( $c[1] ); ?>"><?php echo esc_html( $c[0] ); ?></a>
				<?php endif; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/* — Bloques de la portada (también los usa el shortcode) — */

	private static function render_home_blocks( $opts, $types ) {
		$order  = array( 'd7', 'd30', 'm6', 'years', 'top', 'tags' );
		$active = array_filter( $order, function ( $k ) use ( $opts ) { return ! empty( $opts['blocks'][ $k ] ); } );
		if ( empty( $active ) ) {
			return;
		}
		echo '<div class="cr-hm__blocks">';
		foreach ( $active as $key ) {
			switch ( $key ) {
				case 'd7': self::render_block_d7( $types ); break;
				case 'd30': self::render_block_d30( $types ); break;
				case 'm6': self::render_block_m6( $types ); break;
				case 'years': self::render_block_years( $types ); break;
				case 'top': self::render_block_top( $opts, $types ); break;
				case 'tags': self::render_block_tags( $opts ); break;
			}
		}
		echo '</div>';
	}

	private static function block_open( $title, $meta = '', $wide = false ) {
		?>
		<section class="cr-hm__block<?php echo $wide ? ' cr-hm__block--wide' : ''; ?>">
			<div class="cr-hm__block-head">
				<h2 class="cr-hm__block-title"><?php echo esc_html( $title ); ?></h2>
				<?php if ( '' !== $meta ) : ?><span class="cr-hm__meta"><?php echo esc_html( $meta ); ?></span><?php endif; ?>
			</div>
		<?php
	}

	private static function render_block_d7( $types ) {
		$items = Cmdroom_Htmlmap::recent_week_items( $types );
		if ( empty( $items ) ) {
			return;
		}
		self::block_open( __( 'Últimos 7 días', 'command-room' ), self::fmt_count( count( $items ) ), true );
		?>
			<ul class="cr-hm__list">
				<?php foreach ( $items as $it ) : ?>
					<li class="cr-hm__row">
						<a href="<?php echo esc_url( get_permalink( $it['id'] ) ); ?>"><?php echo esc_html( $it['title'] ); ?></a>
						<span class="cr-hm__meta"><?php echo esc_html( $it['date'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function render_block_d30( $types ) {
		$days = Cmdroom_Htmlmap::recent_days_with_content( $types, 30 );
		if ( empty( $days ) ) {
			return;
		}
		self::block_open( __( 'Últimos 30 días', 'command-room' ), sprintf( _n( '%d día', '%d días', count( $days ), 'command-room' ), count( $days ) ), true );
		?>
			<ul class="cr-hm__chips">
				<?php foreach ( $days as $day ) : ?>
					<li><a class="cr-hm__chip" href="<?php echo esc_url( self::url( 'day', $day['y'], $day['m'], $day['d'] ) ); ?>">
						<span><?php echo esc_html( Cmdroom_Htmlmap::short_date( $day['y'], $day['m'], $day['d'] ) ); ?></span>
						<span class="cr-hm__meta"><?php echo esc_html( $day['c'] ); ?></span>
					</a></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function render_block_m6( $types ) {
		$months = Cmdroom_Htmlmap::recent_months_with_counts( $types, 6 );
		self::block_open( __( 'Últimos 6 meses', 'command-room' ) );
		?>
			<ul class="cr-hm__list">
				<?php foreach ( $months as $mo ) : ?>
					<li class="cr-hm__row">
						<?php if ( $mo['c'] ) : ?>
							<a href="<?php echo esc_url( self::url( 'month', $mo['y'], $mo['m'] ) ); ?>"><?php echo esc_html( Cmdroom_Htmlmap::month_year( $mo['y'], $mo['m'] ) ); ?></a>
						<?php else : ?>
							<span class="cr-hm__dim"><?php echo esc_html( Cmdroom_Htmlmap::month_year( $mo['y'], $mo['m'] ) ); ?></span>
						<?php endif; ?>
						<span class="cr-hm__meta"><?php echo esc_html( self::fmt_count( $mo['c'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function render_block_years( $types ) {
		$years = Cmdroom_Htmlmap::years_with_counts( $types );
		if ( empty( $years ) ) {
			return;
		}
		self::block_open( __( 'Por año', 'command-room' ) );
		?>
			<ul class="cr-hm__cells">
				<?php foreach ( $years as $y => $c ) : ?>
					<li><a class="cr-hm__cell" href="<?php echo esc_url( self::url( 'year', $y ) ); ?>">
						<span class="cr-hm__cell-label"><?php echo esc_html( $y ); ?></span>
						<span class="cr-hm__meta"><?php echo esc_html( self::fmt_count( $c ) ); ?></span>
					</a></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	private static function render_block_top( $opts, $types ) {
		$res = Cmdroom_Htmlmap::top_content( $types, $opts['top_by'], $opts['top_n'] );
		if ( empty( $res['items'] ) ) {
			return;
		}
		self::block_open( __( 'Top contenidos', 'command-room' ) );
		?>
			<ol class="cr-hm__list cr-hm__list--num">
				<?php foreach ( $res['items'] as $i => $it ) : ?>
					<li class="cr-hm__row">
						<span class="cr-hm__num"><?php echo esc_html( str_pad( $i + 1, 2, '0', STR_PAD_LEFT ) ); ?></span>
						<a href="<?php echo esc_url( get_permalink( $it['id'] ) ); ?>"><?php echo esc_html( $it['title'] ); ?></a>
						<span class="cr-hm__meta"><?php echo esc_html( $it['category'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
	}

	private static function render_block_tags( $opts ) {
		$tags = Cmdroom_Htmlmap::top_tags( $opts['tag_n'] );
		if ( empty( $tags ) ) {
			return;
		}
		$total = count( Cmdroom_Htmlmap::all_tags_with_counts() );
		self::block_open( __( 'Top tags', 'command-room' ) );
		?>
			<ul class="cr-hm__chips cr-hm__chips--wrap">
				<?php foreach ( $tags as $tag ) : ?>
					<li><a class="cr-hm__chip" href="<?php echo esc_url( get_term_link( (int) $tag['id'], 'post_tag' ) ); ?>">
						<span><?php echo esc_html( $tag['name'] ); ?></span>
						<span class="cr-hm__meta"><?php echo esc_html( $tag['count'] ); ?></span>
					</a></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $opts['tags_link'] ) : ?>
				<a class="cr-hm__more" href="<?php echo esc_url( self::url( 'tags' ) ); ?>"><?php printf( esc_html__( 'Ver todos los tags (%d) →', 'command-room' ), $total ); ?></a>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Top contenidos + Top tags debajo de los niveles internos (como en la vista previa). */
	private static function render_extra( $opts, $types, $with_tags ) {
		echo '<div class="cr-hm__blocks cr-hm__blocks--extra">';
		self::render_block_top( $opts, $types );
		if ( $with_tags ) {
			self::render_block_tags( $opts );
		}
		echo '</div>';
	}

	/* — Año / Mes — */

	private static function render_year_grid( $opts, $types, $y ) {
		$months = Cmdroom_Htmlmap::months_with_counts( $types, $y );
		echo '<ul class="cr-hm__cells cr-hm__cells--grid">';
		foreach ( $months as $m => $c ) {
			if ( 0 === $c && 'hide' === $opts['empty'] ) {
				continue;
			}
			$label = '<span class="cr-hm__cell-label">' . esc_html( Cmdroom_Htmlmap::MESES[ $m ] ) . '</span><span class="cr-hm__meta">' . esc_html( $c ? self::fmt_count( $c ) : '—' ) . '</span>';
			if ( $c ) {
				printf( '<li><a class="cr-hm__cell" href="%s">%s</a></li>', esc_url( self::url( 'month', $y, $m ) ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- $label ya escapado
			} else {
				printf( '<li><span class="cr-hm__cell is-empty">%s</span></li>', $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- $label ya escapado
			}
		}
		echo '</ul>';
	}

	private static function render_month_grid( $opts, $types, $y, $m ) {
		$days = Cmdroom_Htmlmap::days_with_counts( $types, $y, $m );
		echo '<ul class="cr-hm__cells cr-hm__cells--days">';
		foreach ( $days as $d => $c ) {
			if ( 0 === $c && 'hide' === $opts['empty'] ) {
				continue;
			}
			$label = '<span class="cr-hm__cell-label">' . esc_html( $d ) . '</span><span class="cr-hm__meta">' . esc_html( $c ? $c : '—' ) . '</span>';
			if ( $c ) {
				printf( '<li><a class="cr-hm__cell" href="%s">%s</a></li>', esc_url( self::url( 'day', $y, $m, $d ) ), $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- $label ya escapado
			} else {
				printf( '<li><span class="cr-hm__cell is-empty">%s</span></li>', $label ); // phpcs:ignore WordPress.Security.EscapeOutput -- $label ya escapado
			}
		}
		echo '</ul>';
	}

	/* — Día — */

	private static function render_day_listing( $opts, $types, $y, $m, $d, $page ) {
		$res   = Cmdroom_Htmlmap::day_posts( $types, $y, $m, $d, $page, $opts['per_day'] );
		$pages = max( 1, $res['pages'] );
		$prev  = Cmdroom_Htmlmap::nearest_day_with_content( $types, $y, $m, $d, -1 );
		$next  = Cmdroom_Htmlmap::nearest_day_with_content( $types, $y, $m, $d, 1 );
		?>
		<ol class="cr-hm__list cr-hm__list--num cr-hm__daylist" start="<?php echo esc_attr( ( ( $page - 1 ) * $opts['per_day'] ) + 1 ); ?>">
			<?php foreach ( $res['items'] as $i => $it ) :
				$num = ( ( $page - 1 ) * $opts['per_day'] ) + $i + 1;
				?>
				<li class="cr-hm__row">
					<span class="cr-hm__num"><?php echo esc_html( str_pad( $num, 2, '0', STR_PAD_LEFT ) ); ?></span>
					<a href="<?php echo esc_url( get_permalink( $it['id'] ) ); ?>"><?php echo esc_html( $it['title'] ); ?></a>
					<span class="cr-hm__meta"><?php echo esc_html( $it['tag'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<nav class="cr-hm__daynav" aria-label="<?php esc_attr_e( 'Navegación por días', 'command-room' ); ?>">
			<?php if ( $prev ) : ?>
				<a rel="prev" href="<?php echo esc_url( self::url( 'day', $prev[0], $prev[1], $prev[2] ) ); ?>">← <?php echo esc_html( Cmdroom_Htmlmap::short_date( $prev[0], $prev[1], $prev[2] ) ); ?></a>
			<?php else : ?>
				<span></span>
			<?php endif; ?>

			<?php if ( $pages > 1 ) : ?>
				<span class="cr-hm__pager">
					<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
						<?php if ( $p === $page ) : ?>
							<span class="cr-hm__pager-btn is-active" aria-current="page"><?php echo esc_html( $p ); ?></span>
						<?php else : ?>
							<a class="cr-hm__pager-btn" href="<?php echo esc_url( self::url( 'day', $y, $m, $d, $p ) ); ?>"><?php echo esc_html( $p ); ?></a>
						<?php endif; ?>
					<?php endfor; ?>
				</span>
			<?php endif; ?>

			<?php if ( $next ) : ?>
				<a rel="next" href="<?php echo esc_url( self::url( 'day', $next[0], $next[1], $next[2] ) ); ?>"><?php echo esc_html( Cmdroom_Htmlmap::short_date( $next[0], $next[1], $next[2] ) ); ?> →</a>
			<?php else : ?>
				<span></span>
			<?php endif; ?>
		</nav>
		<?php
	}

	/* — Tags A-Z — */

	private static function render_tags_az() {
		$groups = Cmdroom_Htmlmap::tags_by_letter();
		if ( empty( $groups ) ) {
			return;
		}
		?>
		<div class="cr-hm__az">
			<?php foreach ( $groups as $letter => $tags ) : ?>
				<div class="cr-hm__az-row">
					<h2 class="cr-hm__az-letter"><?php echo esc_html( $letter ); ?></h2>
					<ul class="cr-hm__chips cr-hm__chips--wrap">
						<?php foreach ( $tags as $tag ) : ?>
							<li><a class="cr-hm__chip" href="<?php echo esc_url( get_term_link( (int) $tag['id'], 'post_tag' ) ); ?>">
								<span><?php echo esc_html( $tag['name'] ); ?></span>
								<span class="cr-hm__meta"><?php echo esc_html( $tag['count'] ); ?></span>
							</a></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- */
	/* Shortcode [cr_sitemap_html]                                       */
	/* ---------------------------------------------------------------- */

	/**
	 * Bloques de la portada dentro de cualquier página, sin título ni
	 * migas (los pone la propia página). Los enlaces llevan a las URLs
	 * reales de /{slug}/. Apagado → no imprime nada.
	 */
	public static function shortcode() {
		if ( ! self::is_live() ) {
			return '';
		}
		self::enqueue(); // encolado tardío: WordPress lo imprime en el footer
		$opts  = Cmdroom_Htmlmap::get_options();
		$types = Cmdroom_Htmlmap::enabled_post_types( $opts );
		ob_start();
		echo '<div class="cr-hm cr-hm--embed">';
		self::render_home_blocks( $opts, $types );
		echo '</div>';
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------- */
	/* Enlace "Mapa del sitio" del footer                                */
	/* ---------------------------------------------------------------- */

	private static function footer_link_enabled() {
		$opts = Cmdroom_Htmlmap::get_options();
		return ! empty( $opts['footer_link'] ) && self::is_live();
	}

	/**
	 * Template tag para que el tema coloque el enlace donde encaje en su
	 * footer (p. ej. fila inferior, junto a Privacidad). Si el tema no
	 * lo llama, maybe_print_footer_fallback() lo pinta en wp_footer.
	 */
	public static function footer_link( $class = '' ) {
		self::$footer_link_printed = true;
		if ( ! self::footer_link_enabled() ) {
			return;
		}
		printf(
			'<a class="cr-hm-footlink%s" href="%s">%s</a>',
			$class ? ' ' . esc_attr( $class ) : '',
			esc_url( self::url() ),
			esc_html__( 'Mapa del sitio', 'command-room' )
		);
	}

	public static function maybe_print_footer_fallback() {
		if ( self::$footer_link_printed || ! self::footer_link_enabled() ) {
			return;
		}
		self::enqueue();
		printf(
			'<nav class="cr-hm-footnav" aria-label="%s"><a class="cr-hm-footlink" href="%s">%s</a></nav>' . "\n",
			esc_attr__( 'Mapa del sitio', 'command-room' ),
			esc_url( self::url() ),
			esc_html__( 'Mapa del sitio', 'command-room' )
		);
	}
}

/**
 * Template tag público -- ver Cmdroom_Htmlmap_Frontend::footer_link().
 * Uso en el tema: <?php if ( function_exists( 'cmdroom_htmlmap_footer_link' ) ) { cmdroom_htmlmap_footer_link(); } ?>
 */
function cmdroom_htmlmap_footer_link( $class = '' ) {
	Cmdroom_Htmlmap_Frontend::footer_link( $class );
}
