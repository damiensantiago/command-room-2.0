<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Variables" -- rediseño "Claude Design" 2026-09-25 sobre el
 * handoff de alta fidelidad del administrador (ver README de esa sesión). Glosario
 * de las variables %algo% agrupado por dónde se usan (Metas/Datos
 * estructurados/Componentes/Open Graph) + control para asignar valor a las
 * variables "estáticas" (nombre del sitio, separador, favicon...).
 *
 * Las filas se construyen SIEMPRE a partir de los catálogos reales
 * (Cmdroom_Meta_Variables::catalog(), Cmdroom_Schema_Variables::catalog(),
 * Cmdroom_Ticker_Resolver::variables_catalog()) -- esta clase solo añade
 * metadatos de presentación (grupo, alias, "sin comillas", qué propiedad de
 * Open Graph alimenta) que no tiene sentido meter en esos catálogos porque
 * son propios de cómo se PINTA esta pantalla, no de cómo se RESUELVE la
 * variable. Si mañana se añade una variable nueva a un catálogo y no
 * aparece aquí agrupada, cae fuera de los grupos definidos (no se pierde:
 * ver build_rows(), que añade cualquier tag no agrupado a un grupo "Otras").
 *
 * Dos adaptaciones deliberadas frente al mock del handoff (que trataba
 * %organization% como un campo de texto editable más):
 * - %organization% es de SOLO LECTURA aquí. Su valor real vive dentro del
 *   bloque JSON-LD Organization/LocalBusiness de Datos estructurados →
 *   General (Cmdroom_Schema_Settings::get_business()) -- editarlo desde
 *   Variables exigiría parsear y reescribir ese JSON a mano, con riesgo
 *   real de pisar una edición manual del administrador en ese bloque. Se enlaza a
 *   dónde editarlo de verdad en vez de duplicar el dato.
 * - El guardado es un POST normal (admin-post.php + redirect), no AJAX sin
 *   recarga -- mismo patrón que el resto del plugin (Metas, Servidor...).
 *   El seguimiento de "cambios sin guardar" sigue siendo 100% en vivo en
 *   el cliente (JS), solo el propio guardado hace un viaje al servidor.
 */
class Cmdroom_Variables_Glossary {

	const SLUG = 'cmdroom-variables';

	/** Orden de contexto para las 4 columnas/botones -- home primero. */
	const CONTEXT_ORDER = array( 'home', 'term', 'post', 'author_archive' );

	const CONTEXT_LABELS = array(
		'home'           => 'Home',
		'term'           => 'Categorías y tags',
		'post'           => 'Posts y páginas',
		'author_archive' => 'Páginas de autor',
	);

	const CONTEXT_SHORT = array(
		'home'           => 'Home',
		'term'           => 'Tax.',
		'post'           => 'Posts',
		'author_archive' => 'Autor',
	);

	/**
	 * tag (sin %) => tag del que es alias. Presentación pura -- ya se sabía
	 * por el texto de label/description de los catálogos, aquí se hace
	 * explícito para poder pintar la píldora y decidir la celda de Valor.
	 */
	const ALIASES = array(
		'excerpt_only' => 'excerpt',
		'author'       => 'author_name',
		'term'         => 'term_title',
		'og_image'     => 'image',
	);

	/** tags que se insertan SIN comillas en la plantilla (arrays/números JSON). */
	const NO_QUOTES = array( 'schema_breadcrumb_items', 'schema_item_list_items', 'schema_image_objects', 'schema_word_count' );

	/**
	 * Variables estáticas con control de valor propio. 'control':
	 * text | select | image | readonly. 'home_tab': en qué pestaña vive el
	 * control real -- en cualquier otra pestaña donde reaparezca el mismo
	 * tag (p. ej. %sitename% también sale en Open Graph) se pinta una caja
	 * de solo lectura que remite a esa pestaña, nunca un segundo <input>
	 * con el mismo name.
	 */
	const EDITABLE = array(
		'sitename'        => array( 'control' => 'text', 'home_tab' => 'metas', 'note' => 'Por defecto: Ajustes → General → Título del sitio.' ),
		'sitedesc'        => array( 'control' => 'text', 'home_tab' => 'metas', 'note' => 'Por defecto: Ajustes → General → Eslogan.' ),
		'sep'             => array( 'control' => 'select', 'home_tab' => 'metas', 'options' => array( '-', '–', '—', '|', '·', '»', '/' ), 'note' => 'Se usa en todas las plantillas de título.' ),
		'organization'    => array( 'control' => 'readonly', 'home_tab' => 'metas', 'note' => 'Se edita en Datos estructurados → General → bloque Organization/LocalBusiness (JSON-LD) -- no se edita aquí para no arriesgar una edición manual tuya en ese bloque.' ),
		'favicon'         => array( 'control' => 'image', 'home_tab' => 'metas', 'note' => 'Por defecto: Icono del sitio de WordPress.' ),
		'og_locale'       => array( 'control' => 'select', 'home_tab' => 'metas', 'options' => array( 'es_ES', 'es_MX', 'es_AR', 'en_US', 'en_GB', 'fr_FR', 'pt_BR' ), 'note' => 'Por defecto: idioma de WordPress.' ),
		'charset'         => array( 'control' => 'select', 'home_tab' => 'metas', 'options' => array( 'UTF-8', 'ISO-8859-1' ), 'note' => 'Por defecto: Ajustes → General → Codificación.' ),
		'image'           => array( 'control' => 'image', 'home_tab' => 'metas', 'note' => 'Imagen de respaldo cuando la página no tiene destacada.' ),
		'schema_sitename' => array( 'control' => 'text', 'home_tab' => 'schema', 'placeholder_is_sitename' => true, 'note' => 'Vacío = usa %sitename%.' ),
		'schema_lang'     => array( 'control' => 'select', 'home_tab' => 'schema', 'options' => array( 'es-ES', 'es-MX', 'es-AR', 'en-US', 'en-GB', 'fr-FR', 'pt-BR' ), 'note' => 'Por defecto: idioma de WordPress.' ),
		'schema_site_url' => array( 'control' => 'readonly', 'home_tab' => 'schema', 'note' => 'home_url("/") -- no editable.' ),
		'schema_organization_id' => array( 'control' => 'readonly', 'home_tab' => 'schema', 'note' => 'Fijo -- no editable.' ),
		'schema_website_id'      => array( 'control' => 'readonly', 'home_tab' => 'schema', 'note' => 'Fijo -- no editable.' ),
		'schema_reviewer_name'      => array( 'control' => 'text', 'home_tab' => 'schema', 'note' => 'Profesional que revisa el contenido del sitio (E-E-A-T) -- un único revisor para todo el sitio, no por post. Vacío = "reviewedBy" sale sin rellenar.' ),
		'schema_reviewer_url'       => array( 'control' => 'text', 'home_tab' => 'schema', 'note' => 'Enlace al perfil del revisor (p. ej. su ficha de autor).' ),
		'schema_reviewer_job_title' => array( 'control' => 'text', 'home_tab' => 'schema', 'note' => 'P. ej. "Director Clínico -- Fisioterapeuta colegiado".' ),
		'schema_reviewer_image'     => array( 'control' => 'image', 'home_tab' => 'schema', 'note' => 'Foto del revisor.' ),
	);

	const METAS_GROUPS = array(
		'Contenido'         => array( 'title', 'excerpt', 'excerpt_only', 'category', 'keywords', 'page' ),
		'Sitio'             => array( 'sitename', 'sitedesc', 'sep', 'organization', 'favicon', 'og_locale', 'charset', 'currentyear' ),
		'Taxonomías'        => array( 'term_title', 'term', 'term_description' ),
		'Autor'             => array( 'author_name', 'author' ),
		'Fechas'            => array( 'date', 'date_iso', 'date_modified_iso', 'last_modified' ),
		'URL e indexación'  => array( 'url', 'robots' ),
		'Imagen'            => array( 'image', 'og_image', 'image_width', 'image_height' ),
	);

	const SCHEMA_GROUPS = array(
		'Sitio y nodos'  => array( 'schema_sitename', 'schema_site_url', 'schema_organization_id', 'schema_website_id', 'schema_lang' ),
		'Página actual'  => array( 'schema_headline', 'schema_description', 'schema_url' ),
		'Artículo'       => array( 'schema_date_published', 'schema_date_modified', 'schema_image', 'schema_image_objects', 'schema_word_count', 'schema_time_required', 'schema_keywords', 'schema_category', 'schema_article_body' ),
		'Autor'          => array( 'schema_author_name', 'schema_author_url', 'schema_author_description', 'schema_author_job_title', 'schema_author_image' ),
		'Revisor médico' => array( 'schema_reviewer_name', 'schema_reviewer_url', 'schema_reviewer_job_title', 'schema_reviewer_image' ),
		'Listados'       => array( 'schema_breadcrumb_items', 'schema_item_list_items' ),
	);

	/** tag => propiedad(es) de Open Graph/Twitter que alimenta. */
	const OG_GROUPS = array(
		'Básicas' => array(
			'title'     => 'og:title',
			'excerpt'   => 'og:description',
			'url'       => 'og:url',
			'sitename'  => 'og:site_name',
			'og_locale' => 'og:locale',
		),
		'Imagen' => array(
			'image'        => 'og:image · twitter:image',
			'og_image'     => 'og:image',
			'image_width'  => 'og:image:width · twitter:image:width',
			'image_height' => 'og:image:height · twitter:image:height',
		),
		'Artículo' => array(
			'date_iso'          => 'article:published_time',
			'date_modified_iso' => 'article:modified_time · og:updated_time',
			'last_modified'     => 'og:updated_time (listados)',
		),
	);

	const FOOTER_NOTES = array(
		'schema' => 'Los valores vacíos se omiten del JSON-LD final para no emitir propiedades sin contenido.',
		'ticker' => 'Cada fuente automática acepta solo las variables que le corresponden; el resto se imprime vacío.',
		'og' => 'Son las mismas variables de Metas, ordenadas por la propiedad og:* o twitter:* para la que están pensadas.',
	);

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'cmdroom-meta-editor', CMDROOM_URL . 'assets/css/meta-editor.css', array(), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-servidor-editor', CMDROOM_URL . 'assets/css/servidor-editor.css', array( 'cmdroom-meta-editor' ), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-variables-editor', CMDROOM_URL . 'assets/css/variables-editor.css', array( 'cmdroom-servidor-editor' ), CMDROOM_VERSION );
		wp_enqueue_script( 'cmdroom-variables-editor', CMDROOM_URL . 'assets/js/variables-editor.js', array(), CMDROOM_VERSION, true );
	}

	private static function catalog_by_tag( $catalog ) {
		$out = array();
		foreach ( $catalog as $row ) {
			$out[ trim( $row['tag'], '%' ) ] = $row;
		}
		return $out;
	}

	/**
	 * @param array $groups tag => label del grupo (METAS_GROUPS/SCHEMA_GROUPS)
	 * @param array $by_tag catálogo real indexado por tag sin %
	 * @param array $og_props Solo para la vista Open Graph: tag => propiedad(es).
	 */
	private static function build_rows( $groups, $by_tag, $og_props = null ) {
		$result   = array();
		$assigned = array();

		foreach ( $groups as $group_label => $tags ) {
			$rows = array();
			foreach ( $tags as $tag ) {
				if ( ! isset( $by_tag[ $tag ] ) ) {
					continue; // catálogo desincronizado -- se ignora en vez de fatal.
				}
				$assigned[ $tag ] = true;
				$rows[]           = self::build_row( $tag, $by_tag[ $tag ], $og_props );
			}
			if ( $rows ) {
				$result[ $group_label ] = $rows;
			}
		}

		// Cualquier tag del catálogo real que no se haya agrupado a mano
		// arriba (variable nueva añadida al motor sin actualizar esta
		// pantalla) -- se muestra igualmente, no se pierde.
		$leftover = array();
		foreach ( $by_tag as $tag => $entry ) {
			if ( empty( $assigned[ $tag ] ) && ( ! $og_props || isset( $og_props[ $tag ] ) ) ) {
				$leftover[] = self::build_row( $tag, $entry, $og_props );
			}
		}
		if ( $leftover ) {
			$result[ __( 'Otras', 'command-room' ) ] = $leftover;
		}

		return $result;
	}

	private static function build_row( $tag, $entry, $og_props ) {
		return array(
			'tag'         => $tag,
			'name'        => $og_props ? $og_props[ $tag ] : $entry['label'],
			'description' => $entry['description'],
			'contexts'    => $entry['contexts'],
			'alias_of'    => isset( self::ALIASES[ $tag ] ) ? self::ALIASES[ $tag ] : null,
			'no_quotes'   => in_array( $tag, self::NO_QUOTES, true ),
		);
	}

	public static function render_page() {
		$meta_by_tag   = self::catalog_by_tag( Cmdroom_Meta_Variables::catalog() );
		$schema_by_tag = class_exists( 'Cmdroom_Schema_Variables' ) ? self::catalog_by_tag( Cmdroom_Schema_Variables::catalog() ) : array();

		$metas_rows  = self::build_rows( self::METAS_GROUPS, $meta_by_tag );
		$schema_rows = $schema_by_tag ? self::build_rows( self::SCHEMA_GROUPS, $schema_by_tag ) : array();
		$og_rows     = array();
		foreach ( self::OG_GROUPS as $group_label => $tags_with_prop ) {
			foreach ( $tags_with_prop as $tag => $prop ) {
				if ( ! isset( $meta_by_tag[ $tag ] ) ) {
					continue;
				}
				$og_rows[ $group_label ][] = self::build_row( $tag, $meta_by_tag[ $tag ], self::flatten_og_props() );
			}
		}

		$ticker_rows = class_exists( 'Cmdroom_Ticker_Resolver' ) ? Cmdroom_Ticker_Resolver::variables_catalog() : array();
		// %author% del Módulo de afiliación vive en la misma subpestaña
		// "Componentes" que las del Ticker -- ambos catálogos son propios de
		// plantillas de un componente, no de Metas/Datos estructurados.
		if ( class_exists( 'Cmdroom_Affiliate' ) ) {
			$ticker_rows = array_merge( $ticker_rows, Cmdroom_Affiliate::variables_catalog() );
		}

		$counts = array(
			'metas'  => self::count_rows( $metas_rows ),
			'schema' => self::count_rows( $schema_rows ),
			'ticker' => count( $ticker_rows ),
			'og'     => self::count_rows( $og_rows ),
		);
		?>
		<div class="wrap cmdroom-wrap cmdroom-metadata-wrap cmdroom-variables-wrap">
			<h1 class="cmdroom-md-h1"><?php esc_html_e( 'Variables', 'command-room' ); ?></h1>
			<p class="cmdroom-md-intro"><?php esc_html_e( 'Variables que se sustituyen por su valor al imprimir la página. Haz clic en cualquiera para copiarla.', 'command-room' ); ?></p>

			<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Valores guardados.', 'command-room' ); ?></p></div>
			<?php endif; ?>

			<div class="cmdroom-md-tabs" role="tablist">
				<button type="button" class="cmdroom-md-tab is-active" data-tab="metas"><?php esc_html_e( 'Metas', 'command-room' ); ?> <span class="cr-var-count"><?php echo (int) $counts['metas']; ?></span></button>
				<button type="button" class="cmdroom-md-tab" data-tab="schema"><?php esc_html_e( 'Datos estructurados', 'command-room' ); ?> <span class="cr-var-count"><?php echo (int) $counts['schema']; ?></span></button>
				<button type="button" class="cmdroom-md-tab" data-tab="ticker"><?php esc_html_e( 'Componentes', 'command-room' ); ?> <span class="cr-var-count"><?php echo (int) $counts['ticker']; ?></span></button>
				<button type="button" class="cmdroom-md-tab" data-tab="og"><?php esc_html_e( 'Open Graph', 'command-room' ); ?> <span class="cr-var-count"><?php echo (int) $counts['og']; ?></span></button>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cmdroom-variables-form">
				<?php wp_nonce_field( 'cmdroom_save_variables' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_variables" />

				<div class="cmdroom-md-panel" data-tab="metas">
					<?php self::render_toolbar( 'metas' ); ?>
					<?php self::render_grid( $metas_rows, 'metas' ); ?>
				</div>

				<div class="cmdroom-md-panel" data-tab="schema" hidden>
					<?php self::render_toolbar( 'schema' ); ?>
					<?php self::render_grid( $schema_rows, 'schema' ); ?>
					<?php self::render_footer( 'schema' ); ?>
				</div>

				<div class="cmdroom-md-panel" data-tab="ticker" hidden>
					<div class="cr-vars-subtabs">
						<button type="button" class="cr-vars-subtab is-active" data-subtab="ticker"><?php esc_html_e( 'Ticker', 'command-room' ); ?> <span class="cr-var-count"><?php echo (int) $counts['ticker']; ?></span></button>
					</div>
					<?php self::render_ticker_grid( $ticker_rows ); ?>
					<?php self::render_footer( 'ticker' ); ?>
				</div>

				<div class="cmdroom-md-panel" data-tab="og" hidden>
					<?php self::render_toolbar( 'og' ); ?>
					<?php self::render_grid( $og_rows, 'og' ); ?>
					<?php self::render_footer( 'og' ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	private static function count_rows( $grouped ) {
		$n = 0;
		foreach ( $grouped as $rows ) {
			$n += count( $rows );
		}
		return $n;
	}

	private static function flatten_og_props() {
		$flat = array();
		foreach ( self::OG_GROUPS as $tags_with_prop ) {
			$flat += $tags_with_prop;
		}
		return $flat;
	}

	private static function render_toolbar( $tab ) {
		?>
		<div class="cr-vars-toolbar">
			<div class="cr-vars-ctx-filter">
				<span class="cr-vars-ctx-label"><?php esc_html_e( 'En uso en', 'command-room' ); ?></span>
				<div class="cr-seg cr-vars-ctx-seg">
					<?php foreach ( self::CONTEXT_ORDER as $ctx ) : ?>
						<button type="button" class="cr-seg-opt" data-ctx="<?php echo esc_attr( $ctx ); ?>"><?php echo esc_html( self::CONTEXT_LABELS[ $ctx ] ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="cr-vars-save">
				<span class="cr-vars-save-status" data-cr-vars-status></span>
				<button type="submit" class="cr-btn-primary cr-vars-save-btn" data-cr-vars-submit disabled><?php esc_html_e( 'Guardar valores', 'command-room' ); ?></button>
			</div>
		</div>
		<?php
	}

	private static function render_footer( $tab ) {
		if ( empty( self::FOOTER_NOTES[ $tab ] ) ) {
			return;
		}
		?>
		<p class="cmdroom-md-footer-text cr-vars-footnote"><?php echo esc_html( self::FOOTER_NOTES[ $tab ] ); ?></p>
		<?php
	}

	private static function render_grid( $grouped, $tab ) {
		if ( empty( $grouped ) ) {
			echo '<p class="description">' . esc_html__( 'Sin variables que mostrar.', 'command-room' ) . '</p>';
			return;
		}
		?>
		<div class="cr-card cr-vars-card">
			<div class="cr-vars-grid-wrap">
				<div class="cr-vars-grid">
					<div class="cr-vars-row cr-vars-head">
						<div><?php esc_html_e( 'Variable', 'command-room' ); ?></div>
						<div><?php esc_html_e( 'Descripción', 'command-room' ); ?></div>
						<div><?php esc_html_e( 'Valor', 'command-room' ); ?></div>
						<?php foreach ( self::CONTEXT_ORDER as $ctx ) : ?>
							<div class="cr-vars-ctx-head" data-ctx-col="<?php echo esc_attr( $ctx ); ?>" title="<?php echo esc_attr( self::CONTEXT_LABELS[ $ctx ] ); ?>"><?php echo esc_html( self::CONTEXT_SHORT[ $ctx ] ); ?></div>
						<?php endforeach; ?>
					</div>

					<?php foreach ( $grouped as $group_label => $rows ) : ?>
						<div class="cr-vars-group-head">
							<span class="cr-vars-group-name"><?php echo esc_html( $group_label ); ?></span>
							<span class="cr-vars-group-count"><?php echo (int) count( $rows ); ?></span>
						</div>
						<?php foreach ( $rows as $row ) : ?>
							<?php self::render_row( $row, $tab ); ?>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="cr-vars-empty" hidden>
				<p><?php esc_html_e( 'Ninguna variable coincide con estos filtros.', 'command-room' ); ?></p>
				<button type="button" class="cr-btn-secondary" data-cr-vars-clear><?php esc_html_e( 'Limpiar filtros', 'command-room' ); ?></button>
			</div>
		</div>
		<?php
	}

	private static function render_row( $row, $tab ) {
		$contexts_attr = implode( ' ', $row['contexts'] );
		?>
		<div class="cr-vars-row" data-contexts="<?php echo esc_attr( $contexts_attr ); ?>">
			<div class="cr-vars-cell-var">
				<?php self::render_copy_button( $row['tag'] ); ?>
			</div>
			<div class="cr-vars-cell-desc">
				<div class="cr-vars-name">
					<?php echo esc_html( $row['name'] ); ?>
					<?php if ( $row['alias_of'] ) : ?>
						<span class="cr-pill cr-vars-pill-alias"><?php echo esc_html( sprintf( __( 'Alias de %%%s%%', 'command-room' ), $row['alias_of'] ) ); ?></span>
					<?php endif; ?>
					<?php if ( $row['no_quotes'] ) : ?>
						<span class="cr-pill cr-vars-pill-noquotes"><?php esc_html_e( 'Sin comillas', 'command-room' ); ?></span>
					<?php endif; ?>
				</div>
				<p class="cr-vars-desc-text"><?php echo esc_html( $row['description'] ); ?></p>
			</div>
			<div class="cr-vars-cell-value">
				<?php self::render_value_cell( $row['tag'], $tab ); ?>
			</div>
			<?php foreach ( self::CONTEXT_ORDER as $ctx ) : ?>
				<div class="cr-vars-ctx-cell" data-ctx-col="<?php echo esc_attr( $ctx ); ?>" title="<?php echo esc_attr( sprintf( '%1$s: %2$s', self::CONTEXT_LABELS[ $ctx ], in_array( $ctx, $row['contexts'], true ) ? __( 'disponible', 'command-room' ) : __( 'no disponible', 'command-room' ) ) ); ?>">
					<span class="cr-vars-dot<?php echo in_array( $ctx, $row['contexts'], true ) ? ' is-on' : ''; ?>"></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_copy_button( $tag ) {
		?>
		<button type="button" class="cr-vars-copy" data-cr-copy="%<?php echo esc_attr( $tag ); ?>%" title="<?php echo esc_attr( sprintf( __( 'Copiar %%%s%%', 'command-room' ), $tag ) ); ?>">
			<code>%<?php echo esc_html( $tag ); ?>%</code>
			<span class="cr-vars-copy-icon" aria-hidden="true"></span>
			<span class="screen-reader-text"><?php esc_html_e( 'Copiar', 'command-room' ); ?></span>
		</button>
		<?php
	}

	private static function render_value_cell( $tag, $tab ) {
		// Alias editable (hoy solo %og_image% -> %image%): caja de solo
		// lectura con el valor real de la variable canónica.
		if ( isset( self::ALIASES[ $tag ] ) && isset( self::EDITABLE[ self::ALIASES[ $tag ] ] ) ) {
			$canonical = self::ALIASES[ $tag ];
			self::render_readonly_value( self::current_value( $canonical ), sprintf( __( 'Mismo valor que %%%s%%.', 'command-room' ), $canonical ) );
			return;
		}

		if ( ! isset( self::EDITABLE[ $tag ] ) ) {
			echo '<span class="cr-vars-auto">' . esc_html__( 'AUTOMÁTICO', 'command-room' ) . '</span>';
			return;
		}

		$def = self::EDITABLE[ $tag ];

		if ( $def['home_tab'] !== $tab ) {
			$home_label = 'metas' === $def['home_tab'] ? __( 'Metas', 'command-room' ) : __( 'Datos estructurados', 'command-room' );
			self::render_readonly_value( self::current_value( $tag ), sprintf( __( 'Se edita en la pestaña %s.', 'command-room' ), $home_label ) );
			return;
		}

		switch ( $def['control'] ) {
			case 'text':
				self::render_text_control( $tag, $def );
				break;
			case 'select':
				self::render_select_control( $tag, $def );
				break;
			case 'image':
				self::render_image_control( $tag, $def );
				break;
			case 'readonly':
			default:
				self::render_readonly_value( self::current_value( $tag ), $def['note'] );
				break;
		}
	}

	private static function render_readonly_value( $value, $note ) {
		?>
		<div class="cr-vars-value" data-cr-vars-static>
			<div class="cr-vars-readonly"><?php echo esc_html( $value ? $value : __( '(vacío)', 'command-room' ) ); ?></div>
			<p class="cr-vars-help"><?php echo esc_html( $note ); ?></p>
		</div>
		<?php
	}

	/**
	 * Valor REALMENTE activo ahora mismo (override si lo hay, si no el
	 * cálculo de siempre) -- se usa para precargar los controles (el mock
	 * del administrador pide texto/select ya rellenos con el valor real, no una
	 * caja vacía) y para las cajas de solo lectura.
	 */
	private static function current_value( $tag ) {
		switch ( $tag ) {
			case 'sitename':
				return Cmdroom_Variables_Settings::resolve_sitename();
			case 'sitedesc':
				return Cmdroom_Variables_Settings::resolve_sitedesc();
			case 'sep':
				return Cmdroom_Meta_Settings::get_separator();
			case 'organization':
				$business = class_exists( 'Cmdroom_Schema_Settings' ) ? Cmdroom_Schema_Settings::get_business() : array();
				return isset( $business['name'] ) ? $business['name'] : get_bloginfo( 'name' );
			case 'favicon':
				return Cmdroom_Variables_Settings::resolve_favicon_url();
			case 'og_locale':
				return Cmdroom_Variables_Settings::resolve_og_locale();
			case 'charset':
				return Cmdroom_Variables_Settings::resolve_charset();
			case 'image':
			case 'og_image':
				return Cmdroom_Variables_Settings::resolve_backup_image_url();
			case 'schema_sitename':
				return Cmdroom_Variables_Settings::resolve_schema_sitename();
			case 'schema_lang':
				return Cmdroom_Variables_Settings::resolve_schema_lang();
			case 'schema_site_url':
				return home_url( '/' );
			case 'schema_organization_id':
				return home_url( '/#organization' );
			case 'schema_website_id':
				return home_url( '/#website' );
			case 'schema_reviewer_name':
				return Cmdroom_Variables_Settings::resolve_reviewer_name();
			case 'schema_reviewer_url':
				return Cmdroom_Variables_Settings::resolve_reviewer_url();
			case 'schema_reviewer_job_title':
				return Cmdroom_Variables_Settings::resolve_reviewer_job_title();
			case 'schema_reviewer_image':
				return Cmdroom_Variables_Settings::resolve_reviewer_image_url();
		}
		return '';
	}

	/**
	 * Valor NATIVO por defecto, ignorando cualquier override guardado --
	 * a donde vuelve el enlace "Restablecer". Distinto de current_value():
	 * una vez hay un override guardado, current_value() ya no devuelve el
	 * default nativo, así que hace falta calcularlo aparte. %schema_sitename%
	 * es la única excepción -- su "default" es la cadena vacía (cae a
	 * %sitename%, no a un valor nativo de WordPress).
	 */
	private static function default_value( $tag ) {
		switch ( $tag ) {
			case 'sitename':
				return get_bloginfo( 'name' );
			case 'sitedesc':
				return get_bloginfo( 'description' );
			case 'sep':
				return '-';
			case 'og_locale':
				return get_locale();
			case 'charset':
				return get_bloginfo( 'charset' );
			case 'schema_lang':
				return get_bloginfo( 'language' );
			case 'schema_sitename':
				return '';
		}
		return '';
	}

	private static function render_text_control( $tag, $def ) {
		$opts             = Cmdroom_Variables_Settings::get_options();
		$uses_placeholder = ! empty( $def['placeholder_is_sitename'] );
		// %schema_sitename% es la excepción documentada en el handoff: campo
		// vacío con el valor de %sitename% como placeholder, para que se
		// note a simple vista que cae a otra variable si se deja en blanco.
		// El resto se precarga con el valor real que está activo ahora.
		$value       = $uses_placeholder ? ( isset( $opts[ $tag ] ) ? $opts[ $tag ] : '' ) : self::current_value( $tag );
		$placeholder = $uses_placeholder ? Cmdroom_Variables_Settings::resolve_sitename() : '';
		?>
		<div class="cr-vars-value" data-cr-vars-field data-default="<?php echo esc_attr( self::default_value( $tag ) ); ?>">
			<input type="text" class="cr-input" name="cmdroom_var_<?php echo esc_attr( $tag ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" data-cr-vars-input data-cr-vars-saved="<?php echo esc_attr( $value ); ?>" />
			<?php self::render_help_with_reset( $def['note'] ); ?>
		</div>
		<?php
	}

	private static function render_select_control( $tag, $def ) {
		$current = self::current_value( $tag );
		$default = self::default_value( $tag );
		$options = $def['options'];
		foreach ( array( $current, $default ) as $extra ) {
			if ( '' !== $extra && ! in_array( $extra, $options, true ) ) {
				$options[] = $extra;
			}
		}
		$field_name = 'sep' === $tag ? 'cmdroom_var_sep' : 'cmdroom_var_' . $tag;
		?>
		<div class="cr-vars-value" data-cr-vars-field data-default="<?php echo esc_attr( $default ); ?>">
			<select class="cr-input" name="<?php echo esc_attr( $field_name ); ?>" data-cr-vars-input data-cr-vars-saved="<?php echo esc_attr( $current ); ?>">
				<?php foreach ( $options as $opt ) : ?>
					<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( $current, $opt ); ?>><?php echo esc_html( $opt ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php self::render_help_with_reset( $def['note'] ); ?>
		</div>
		<?php
	}

	private static function render_image_control( $tag, $def ) {
		$opts = Cmdroom_Variables_Settings::get_options();
		if ( 'favicon' === $tag ) {
			$id_key     = 'favicon_id';
			$field_name = 'cmdroom_var_favicon_id';
		} elseif ( 'schema_reviewer_image' === $tag ) {
			$id_key     = 'reviewer_image_id';
			$field_name = 'cmdroom_var_reviewer_image_id';
		} else {
			$id_key     = 'image_id';
			$field_name = 'cmdroom_var_image_id';
		}
		$attachment_id = (int) ( isset( $opts[ $id_key ] ) ? $opts[ $id_key ] : 0 );
		$thumb      = $attachment_id ? wp_get_attachment_image_src( $attachment_id, 'thumbnail' ) : false;
		$filename   = $attachment_id ? basename( get_attached_file( $attachment_id ) ) : '';
		?>
		<div class="cr-vars-value" data-cr-vars-field>
			<div class="cr-vars-image-box">
				<span class="cr-vars-image-thumb" data-cr-vars-thumb>
					<?php if ( $thumb ) : ?>
						<img src="<?php echo esc_url( $thumb[0] ); ?>" alt="" />
					<?php endif; ?>
				</span>
				<span class="cr-vars-image-name" data-cr-vars-filename><?php echo esc_html( $filename ? $filename : __( '(usa el valor por defecto)', 'command-room' ) ); ?></span>
				<button type="button" class="cr-vars-image-btn" data-cr-vars-image-pick data-frame-title="<?php echo esc_attr__( 'Elegir imagen', 'command-room' ); ?>"><?php echo $attachment_id ? esc_html__( 'Cambiar', 'command-room' ) : esc_html__( 'Elegir', 'command-room' ); ?></button>
				<button type="button" class="cr-vars-image-remove" data-cr-vars-image-remove <?php echo $attachment_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Quitar', 'command-room' ); ?></button>
				<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $attachment_id ); ?>" data-cr-vars-input data-cr-vars-saved="<?php echo esc_attr( $attachment_id ); ?>" data-cr-vars-image-id />
			</div>
			<p class="cr-vars-help"><?php echo esc_html( $def['note'] ); ?></p>
		</div>
		<?php
	}

	/**
	 * El botón "Restablecer" se renderiza siempre -- su visibilidad
	 * (valor actual == default o no) la decide variables-editor.js en
	 * vivo, en cada tecla/cambio, no solo en la carga de la página.
	 */
	private static function render_help_with_reset( $note ) {
		?>
		<p class="cr-vars-help">
			<span data-cr-vars-help-text><?php echo esc_html( $note ); ?></span>
			<button type="button" class="cr-vars-reset" data-cr-vars-reset hidden><?php esc_html_e( 'Restablecer', 'command-room' ); ?></button>
		</p>
		<?php
	}

	private static function render_ticker_grid( $rows ) {
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'Sin variables que mostrar.', 'command-room' ) . '</p>';
			return;
		}
		?>
		<div class="cr-card cr-vars-card">
			<div class="cr-vars-grid-wrap">
				<div class="cr-vars-grid cr-vars-grid-simple">
					<div class="cr-vars-row cr-vars-head">
						<div><?php esc_html_e( 'Variable', 'command-room' ); ?></div>
						<div><?php esc_html_e( 'Descripción', 'command-room' ); ?></div>
					</div>
					<?php foreach ( $rows as $row ) : ?>
						<div class="cr-vars-row">
							<div class="cr-vars-cell-var">
								<?php self::render_copy_button( trim( $row['tag'], '%' ) ); ?>
							</div>
							<div class="cr-vars-cell-desc">
								<p class="cr-vars-desc-text"><?php echo esc_html( $row['desc'] ); ?></p>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}
}
