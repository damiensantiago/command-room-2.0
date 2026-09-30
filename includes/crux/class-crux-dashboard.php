<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sección "Core Web Vitals (CrUX)" en "General", debajo del dashboard de
 * GSC -- pedido por el administrador 2026-09-24: una curva por tipo de contenido
 * (mismos grupos que ya usa Metas: home/contenido/corporativas/
 * categorias/tags) para ver de un vistazo si algo mejora o empeora,
 * con datos de campo reales (CrUX), no de laboratorio (eso ya lo cubre
 * Probe con Lighthouse). Cada punto de la curva es la media móvil de 28
 * días que da CrUX, actualizada una vez por semana -- no sirve para
 * detectar una regresión del mismo día, sirve para la tendencia.
 *
 * CrUX no tiene un endpoint "dame todas las URLs de este tipo" ni acepta
 * patrones -- para cada grupo se cogen unas pocas URLs representativas
 * (las más recientes/con más contenido) y se promedia el p75 de las que
 * sí tengan muestra suficiente. En un sitio pequeño o mediano es
 * esperable que varias URLs individuales no tengan datos -- se
 * descartan en silencio, no es un error.
 */
class Cmdroom_Crux_Dashboard {

	const CACHE_TTL      = DAY_IN_SECONDS;
	const URLS_PER_GROUP = 8;

	const GROUP_LABELS = array(
		'home'         => 'Home',
		'contenido'    => 'Contenido',
		'corporativas' => 'Páginas corporativas',
		'categorias'   => 'Categorías',
		'tags'         => 'Tags',
	);

	// Colores fijos por grupo para que la leyenda sea consistente entre
	// los 3 gráficos (LCP/INP/CLS) -- paleta neutra, sin depender de
	// ningún sistema de diseño concreto.
	const GROUP_COLORS = array(
		'home'         => '#2271b1',
		'contenido'    => '#00a32a',
		'corporativas' => '#d63638',
		'categorias'   => '#dba617',
		'tags'         => '#8c8f94',
	);

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || Cmdroom_Admin_Menu::SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! Cmdroom_Crux_Settings::is_configured() ) {
			return;
		}
		// Primera dependencia externa de JS del plugin -- decisión de
		// el administrador 2026-09-24, solo para dibujar estas curvas en esta
		// pantalla, no toca el front-end del sitio. Empaquetada en
		// assets/js/vendor/ en vez de cargada desde un CDN: las guías de
		// revisión de WordPress.org (2026-09-24, de cara a la publicación
		// del plugin) prohíben cargar JS ejecutable desde un servidor de
		// terceros -- MIT license, compatible con la GPL del plugin.
		wp_enqueue_script( 'cmdroom-chart-js', CMDROOM_URL . 'assets/js/vendor/chart.umd.min.js', array(), '4.5.1', true );
		wp_enqueue_script( 'cmdroom-crux-charts', CMDROOM_URL . 'assets/js/crux-charts.js', array( 'cmdroom-chart-js' ), CMDROOM_VERSION, true );
	}

	public static function render_general_section() {
		if ( ! Cmdroom_Crux_Settings::is_configured() ) {
			?>
			<h2><?php esc_html_e( 'Core Web Vitals (CrUX)', 'command-room' ); ?></h2>
			<p class="description">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: URL to the Herramientas tab */
						__( 'No configurado. Añade la API key en <a href="%s">Configuración → Herramientas</a>.', 'command-room' ),
						esc_url( Cmdroom_Config_Admin::tab_url( 'tools' ) )
					),
					array( 'a' => array( 'href' => array() ) )
				);
				?>
			</p>
			<?php
			return;
		}

		$series = self::get_series();
		?>
		<h2><?php esc_html_e( 'Core Web Vitals (CrUX)', 'command-room' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Datos de campo reales (Chrome UX Report), no de laboratorio -- Probe ya cubre Lighthouse por separado. Cada punto es la media móvil de los últimos 28 días, un punto nuevo por semana: sirve para ver tendencia, no para detectar la regresión de hoy. Caché de 24h.', 'command-room' ); ?></p>

		<?php if ( empty( $series['groups'] ) ) : ?>
			<p class="description"><?php esc_html_e( 'Sin datos de CrUX todavía para ninguna URL representativa del sitio.', 'command-room' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<?php
		$metrics = array(
			'lcp' => __( 'LCP (ms)', 'command-room' ),
			'inp' => __( 'INP (ms)', 'command-room' ),
			'cls' => __( 'CLS', 'command-room' ),
		);
		foreach ( $metrics as $metric => $label ) :
			?>
			<h3 style="margin-top:24px;"><?php echo esc_html( $label ); ?></h3>
			<canvas class="cmdroom-crux-chart" data-cr-crux-metric="<?php echo esc_attr( $metric ); ?>" style="max-width:900px;max-height:280px;"></canvas>
		<?php endforeach; ?>

		<script type="application/json" id="cmdroom-crux-data"><?php echo wp_json_encode( $series ); ?></script>
		<?php
	}

	private static function get_series() {
		$cached = get_transient( 'cmdroom_crux_series' );
		if ( false !== $cached ) {
			return $cached;
		}

		$groups = array();
		foreach ( self::build_group_urls() as $group => $urls ) {
			$aggregated = self::aggregate_group( $urls );
			if ( $aggregated ) {
				$groups[ $group ] = array(
					'label' => self::GROUP_LABELS[ $group ],
					'color' => self::GROUP_COLORS[ $group ],
				) + $aggregated;
			}
		}

		$result = array( 'groups' => $groups );
		set_transient( 'cmdroom_crux_series', $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Mismos 5 grupos que Cmdroom_Meta_Settings (home/contenido/
	 * corporativas/categorias/tags) -- URLs representativas por grupo,
	 * no todo el sitio (CrUX no acepta patrones). Grupos sin ninguna URL
	 * (p. ej. "tags" en un sitio sin etiquetas) se descartan.
	 */
	private static function build_group_urls() {
		$groups = array(
			'home'         => array( home_url( '/' ) ),
			'contenido'    => array(),
			'corporativas' => array(),
			'categorias'   => array(),
			'tags'         => array(),
		);

		$content_types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'page', 'attachment' ) ) );
		if ( $content_types ) {
			$posts = get_posts(
				array(
					'post_type'      => $content_types,
					'posts_per_page' => self::URLS_PER_GROUP,
					'post_status'    => 'publish',
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
				)
			);
			foreach ( $posts as $post_id ) {
				$groups['contenido'][] = get_permalink( $post_id );
			}
		}

		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'posts_per_page' => self::URLS_PER_GROUP,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		foreach ( $pages as $post_id ) {
			$groups['corporativas'][] = get_permalink( $post_id );
		}

		$categories = get_categories(
			array(
				'orderby' => 'count',
				'order'   => 'DESC',
				'number'  => self::URLS_PER_GROUP,
			)
		);
		foreach ( $categories as $term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				$groups['categorias'][] = $link;
			}
		}

		$tags = get_tags(
			array(
				'orderby' => 'count',
				'order'   => 'DESC',
				'number'  => self::URLS_PER_GROUP,
			)
		);
		foreach ( $tags as $term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				$groups['tags'][] = $link;
			}
		}

		return array_filter( $groups );
	}

	/**
	 * Pide el histórico de cada URL del grupo y promedia por periodo las
	 * que sí tengan datos. Usa como calendario de referencia los
	 * "periods" de la primera URL con datos -- todas las peticiones se
	 * hacen en la misma ejecución, así que los periodos de recogida de
	 * CrUX coinciden entre URLs del mismo grupo.
	 */
	private static function aggregate_group( $urls ) {
		$records = array();
		foreach ( $urls as $url ) {
			$record = Cmdroom_Crux_Client::query_history( $url );
			if ( ! is_wp_error( $record ) ) {
				$records[] = $record;
			}
		}

		if ( ! $records ) {
			return null;
		}

		$periods = $records[0]['periods'];
		$count   = count( $periods );

		$result = array( 'periods' => $periods );
		foreach ( array( 'lcp', 'inp', 'cls' ) as $metric ) {
			$averaged = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$values = array();
				foreach ( $records as $record ) {
					if ( isset( $record[ $metric ][ $i ] ) && null !== $record[ $metric ][ $i ] ) {
						$values[] = $record[ $metric ][ $i ];
					}
				}
				$averaged[] = $values ? round( array_sum( $values ) / count( $values ), 2 ) : null;
			}
			$result[ $metric ] = $averaged;
		}

		return $result;
	}
}
