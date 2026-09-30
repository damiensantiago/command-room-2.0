<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes de datos estructurados: una lista de bloques JSON-LD `{ id, type,
 * json }` por cada uno de los 7 grupos de tipo de página (general, home,
 * categorias, contenido, autor, corporativas, tags) -- varios bloques por
 * grupo son válidos (p. ej. Contenido puede llevar Article + FAQPage +
 * HowTo a la vez), todos dentro del único @graph que construye
 * Cmdroom_Schema_Builder (nunca un <script type="application/ld+json">
 * independiente).
 *
 * Los grupos son los mismos 7 de Metas (Cmdroom_Meta_Settings), por el mismo
 * motivo: nunca un nodo por post type/taxonomía real del sitio -- eso fue lo
 * que creó bloques sueltos para 'attachment' o taxonomías propias del tema
 * (marca, tipo...) en la versión anterior de esta pantalla, antes de tener
 * su propio rediseño de Claude Design.
 */
class Cmdroom_Schema_Settings {

	const OPTION = 'cmdroom_schema_options';

	// Overrides/tipos nuevos de la Librería -- pedido por el administrador 2026-09-24:
	// poder editar el JSON de partida de un tipo existente y añadir tipos
	// propios. Guarda solo lo que se ha tocado (type => label/description/
	// json), nunca los 21 tipos enteros -- library() hace el merge con
	// builtin_library() en caliente, así que una actualización del plugin
	// que cambie un tipo de fábrica no queda tapada por un override viejo
	// que nadie llegó a editar.
	const OPTION_LIBRARY = 'cmdroom_schema_library';

	/**
	 * Grupo => etiqueta de pestaña, en el orden exacto del handoff (General
	 * primero, Librería no entra aquí porque no guarda bloques propios).
	 */
	const GROUPS = array(
		'general'      => 'General',
		'home'         => 'Home',
		'categorias'   => 'Categorías',
		'contenido'    => 'Contenido',
		'autor'        => 'Página de autor',
		'corporativas' => 'Páginas corporativas',
		'tags'         => 'Tags',
	);

	/** Grupo => @type de la librería con el que arranca ese grupo. */
	const GROUP_DEFAULT_TYPE = array(
		'general'      => 'Organization',
		'home'         => 'WebSite',
		'categorias'   => 'CollectionPage',
		'contenido'    => 'Article',
		'autor'        => 'Person',
		'corporativas' => 'WebPage',
		'tags'         => 'CollectionPage',
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_schema_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cmdroom_save_schema_general', array( __CLASS__, 'handle_save_general' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( $hook ) {
		if ( ! isset( $_GET['page'] ) || 'cmdroom-schema' !== $_GET['page'] ) {
			return;
		}
		// Reutiliza el terminal/chip base de Metas (mismo sistema de diseño,
		// "idéntico al de Meta data" según el handoff) y añade encima lo
		// propio de esta pantalla (chips múltiples, menú "+ Añadir",
		// Librería).
		wp_enqueue_style( 'cmdroom-meta-editor', CMDROOM_URL . 'assets/css/meta-editor.css', array(), CMDROOM_VERSION );
		wp_enqueue_style( 'cmdroom-schema-editor', CMDROOM_URL . 'assets/css/schema-editor.css', array( 'cmdroom-meta-editor' ), CMDROOM_VERSION );
		wp_enqueue_script( 'cmdroom-schema-editor', CMDROOM_URL . 'assets/js/schema-editor.js', array(), CMDROOM_VERSION, true );
	}

	public static function is_live_output_enabled() {
		$opts = self::get_options();
		return ! empty( $opts['live_output'] );
	}

	public static function get_options() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::defaults() );
	}

	/**
	 * @return array Lista de bloques `{ id, type, json }` del grupo, o array
	 *               vacío si el grupo no existe o no tiene ninguno.
	 */
	public static function get_group_blocks( $group ) {
		$opts = self::get_options();
		return isset( $opts['groups'][ $group ] ) && is_array( $opts['groups'][ $group ] ) ? $opts['groups'][ $group ] : array();
	}

	/**
	 * Nombre y logo del negocio para el fallback de %organization%/%image%
	 * de Metas (Cmdroom_Meta_Variables/Cmdroom_Meta_Resolver). Ya no es un
	 * formulario de campos propio -- se lee directamente del primer bloque
	 * Organization/LocalBusiness de la pestaña General, para no mantener el
	 * mismo dato en dos sitios. Si el administrador no ha rellenado "logo" en ese
	 * bloque, cae a '' (sin logo); si no ha tocado "name", cae al título del
	 * sitio.
	 */
	public static function get_business() {
		foreach ( self::get_group_blocks( 'general' ) as $block ) {
			if ( ! in_array( $block['type'], array( 'Organization', 'LocalBusiness' ), true ) ) {
				continue;
			}
			$resolved = Cmdroom_Schema_Variables::replace( $block['json'], array() );
			$node     = json_decode( $resolved, true );
			if ( ! is_array( $node ) ) {
				continue;
			}
			$logo = isset( $node['logo'] ) ? $node['logo'] : '';
			if ( is_array( $logo ) ) {
				$logo = isset( $logo['url'] ) ? $logo['url'] : '';
			}
			return array(
				'name' => ! empty( $node['name'] ) ? (string) $node['name'] : get_bloginfo( 'name' ),
				'logo' => (string) $logo,
			);
		}
		return array( 'name' => get_bloginfo( 'name' ), 'logo' => '' );
	}

	private static function defaults() {
		$groups = array();
		foreach ( array_keys( self::GROUPS ) as $group ) {
			$type            = self::GROUP_DEFAULT_TYPE[ $group ];
			$groups[ $group ] = array( self::new_block( $type ) );
		}

		return array(
			'live_output' => false,
			'groups'      => $groups,
		);
	}

	private static function new_block( $type ) {
		$library = self::library();
		return array(
			'id'   => wp_generate_uuid4(),
			'type' => $type,
			'json' => isset( $library[ $type ] ) ? $library[ $type ]['json'] : '{
  "@type": "' . $type . '"
}',
		);
	}

	public static function handle_save_general() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_schema_general' );

		$opts                = self::get_options();
		$opts['live_output'] = ! empty( $_POST['live_output'] );

		update_option( self::OPTION, $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	public static function render_general_section() {
		$opts = self::get_options();
		?>
		<h2><?php esc_html_e( 'Datos estructurados', 'command-room' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'cmdroom_save_schema_general' ); ?>
			<input type="hidden" name="action" value="cmdroom_save_schema_general" />
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Salida en el sitio', 'command-room' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="live_output" value="1" <?php checked( $opts['live_output'] ); ?> />
							<?php esc_html_e( 'Activar la impresión real del @graph JSON-LD (déjalo apagado mientras comparas contra Rank Math + EEAT Author)', 'command-room' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Guardar', 'command-room' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Guarda el estado completo (los 7 grupos, cada uno con su lista de
	 * bloques) desde el único campo `schema_state` -- un blob JSON que
	 * mantiene sincronizado assets/js/schema-editor.js en cada cambio
	 * (añadir/quitar/editar un bloque, ver su docblock). Un blob y no un
	 * campo por bloque porque el número de bloques por grupo es variable
	 * (0, 1 o varios) y no se conoce de antemano en el servidor.
	 *
	 * Si el blob no es JSON válido (fallo de JS o manipulación directa del
	 * POST) no se guarda nada -- mejor dejar la config anterior intacta que
	 * guardar a medias. Si el blob es válido pero un bloque concreto tiene
	 * un JSON-LD inválido, SÍ se guarda tal cual (igual que Metas guarda
	 * HTML tal cual) -- el error solo se ve al imprimirlo
	 * (Cmdroom_Schema_Builder::resolve_node() lo salta sin romper la
	 * página).
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_schema_settings' );

		if ( ! isset( $_POST['schema_state'] ) ) {
			wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
			exit;
		}

		$decoded = json_decode( wp_unslash( $_POST['schema_state'] ), true );

		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( add_query_arg( 'cmdroom_schema_error', '1', wp_get_referer() ) );
			exit;
		}

		$opts   = self::get_options();
		$groups = array();

		foreach ( array_keys( self::GROUPS ) as $group ) {
			$blocks = isset( $decoded[ $group ] ) && is_array( $decoded[ $group ] ) ? $decoded[ $group ] : array();
			$clean  = array();
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) || ! isset( $block['json'] ) ) {
					continue;
				}
				$clean[] = array(
					'id'   => isset( $block['id'] ) ? sanitize_key( $block['id'] ) : wp_generate_uuid4(),
					// El @type es metadato para el chip/la Librería, no pasa
					// por el JSON -- texto de admin de confianza, mismo
					// criterio que el propio bloque JSON.
					'type' => isset( $block['type'] ) ? sanitize_text_field( $block['type'] ) : '',
					// str_replace de \r\n -> \n por si el navegador coló saltos
					// de línea reales (p. ej. al pegar contenido con CRLF en el
					// terminal) -- este campo viaja en un input oculto, no un
					// <textarea>, así que normalmente no le pasa lo que a Metas
					// (ver Cmdroom_Meta_Settings::normalize_line_endings()),
					// pero es la misma corrección barata por si acaso.
					'json' => str_replace( array( "\r\n", "\r" ), "\n", (string) $block['json'] ),
				);
			}
			$groups[ $group ] = $clean;
		}

		$opts['groups'] = $groups;
		update_option( self::OPTION, $opts );

		self::save_library_overrides();

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * `library_state` solo trae los tipos que se han tocado en esta sesión
	 * de edición (nuevos o con el JSON/descripción editados) -- ver
	 * assets/js/schema-editor.js, libraryOverrides. Mismo criterio que
	 * schema_state: JSON crudo, sin sanitizar de más (contenido de admin de
	 * confianza, detrás de manage_options + nonce). Si no viene el campo
	 * (nadie tocó la Librería en este guardado) no se toca la opción.
	 */
	private static function save_library_overrides() {
		if ( ! isset( $_POST['library_state'] ) ) {
			return;
		}

		$decoded = json_decode( wp_unslash( $_POST['library_state'] ), true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		$overrides = self::get_library_overrides();
		foreach ( $decoded as $type => $entry ) {
			$type = sanitize_text_field( (string) $type );
			if ( '' === $type || ! is_array( $entry ) ) {
				continue;
			}
			$overrides[ $type ] = array(
				'label'       => isset( $entry['label'] ) ? sanitize_text_field( $entry['label'] ) : $type,
				'description' => isset( $entry['description'] ) ? sanitize_text_field( $entry['description'] ) : '',
				'json'        => isset( $entry['json'] ) ? str_replace( array( "\r\n", "\r" ), "\n", (string) $entry['json'] ) : '',
			);
		}

		update_option( self::OPTION_LIBRARY, $overrides );
	}

	/**
	 * Catálogo de los 21 tipos de la Librería: @type => label/descripción
	 * (texto del handoff) + un JSON de partida. Las variables %schema_*%
	 * cubren lo que varía por página; lo que es un dato fijo sin variable
	 * equivalente (precio, SKU, horario, salario...) se deja en blanco para
	 * que el administrador lo rellene a mano -- Cmdroom_Schema_Builder::resolve_node()
	 * quita del nodo cualquier campo que quede vacío.
	 */
	public static function get_library_overrides() {
		$saved = get_option( self::OPTION_LIBRARY, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Catálogo real que consume el admin (assets/js/schema-editor.js) y
	 * cualquier otro sitio del código que necesite resolver un tipo --
	 * los 21 de fábrica (builtin_library()) con los overrides del administrador
	 * encima. Un override puede tocar solo la descripción o el JSON de un
	 * tipo existente, o ser un tipo nuevo entero (no está en el catálogo de
	 * fábrica) -- en ese caso label() cae al propio nombre del tipo si no
	 * se guardó.
	 */
	public static function library() {
		$builtin = self::builtin_library();
		$library = $builtin;
		foreach ( self::get_library_overrides() as $type => $override ) {
			if ( ! is_array( $override ) ) {
				continue;
			}
			$base = isset( $library[ $type ] ) ? $library[ $type ] : array( 'label' => $type, 'description' => '', 'json' => '' );
			$library[ $type ] = array(
				'label'       => isset( $override['label'] ) ? $override['label'] : $base['label'],
				'description' => isset( $override['description'] ) ? $override['description'] : $base['description'],
				'json'        => isset( $override['json'] ) ? $override['json'] : $base['json'],
				// Un tipo es "custom" si no viene de fábrica -- sirve para
				// que el admin ofrezca borrarlo (a un tipo de fábrica solo
				// se le puede restaurar el original, nunca desaparece del
				// catálogo).
				'custom'      => ! isset( $builtin[ $type ] ),
			);
		}
		return $library;
	}

	private static function builtin_library() {
		return array(
			'Organization'        => array(
				'label'       => 'Organization',
				'description' => 'Identidad de la empresa: nombre, logo, redes sociales y contacto.',
				'json'        => self::pretty( array(
					'@type' => 'Organization',
					'@id'   => '%schema_organization_id%',
					'name'  => '%schema_sitename%',
					'url'   => '%schema_site_url%',
					'logo'  => '',
					'sameAs' => array(),
				) ),
			),
			'LocalBusiness'       => array(
				'label'       => 'LocalBusiness',
				'description' => 'Negocio físico con dirección, horario y geolocalización.',
				'json'        => self::pretty( array(
					'@type'      => 'LocalBusiness',
					'@id'        => '%schema_organization_id%',
					'name'       => '%schema_sitename%',
					'url'        => '%schema_site_url%',
					'telephone'  => '',
					'image'      => '',
					'address'    => array(
						'@type'           => 'PostalAddress',
						'streetAddress'   => '',
						'addressLocality' => '',
						'addressRegion'   => '',
						'postalCode'      => '',
						'addressCountry'  => 'ES',
					),
					'openingHours' => '',
				) ),
			),
			// Subtipo de LocalBusiness para negocios de salud/bienestar --
			// añadido 2026-09-27: un sitio real lo usaba en el bloque Organization de
			// la pestaña General (@type combinado con Organization) sin que
			// existiera en la Librería, rompiendo la regla de que todo tipo
			// usado en una página tiene que venir de aquí. Misma forma que
			// LocalBusiness (dirección, teléfono, horario).
			'HealthAndBeautyBusiness' => array(
				'label'       => 'HealthAndBeautyBusiness',
				'description' => 'Negocio de salud y bienestar (subtipo de LocalBusiness): dirección, horario y contacto.',
				'json'        => self::pretty( array(
					'@type'      => 'HealthAndBeautyBusiness',
					'@id'        => '%schema_organization_id%',
					'name'       => '%schema_sitename%',
					'url'        => '%schema_site_url%',
					'telephone'  => '',
					'image'      => '',
					'address'    => array(
						'@type'           => 'PostalAddress',
						'streetAddress'   => '',
						'addressLocality' => '',
						'addressRegion'   => '',
						'postalCode'      => '',
						'addressCountry'  => 'ES',
					),
					'openingHours' => '',
				) ),
			),
			// Subtipo de LocalBusiness para negocios sanitarios -- añadido
			// 2026-09-27 junto a HealthAndBeautyBusiness, mismo motivo: se
			// necesita en el bloque General (varios @type a la vez es válido
			// en schema.org) con la dirección real del negocio.
			'MedicalBusiness'         => array(
				'label'       => 'MedicalBusiness',
				'description' => 'Negocio sanitario (subtipo de LocalBusiness): dirección, horario y contacto.',
				'json'        => self::pretty( array(
					'@type'      => 'MedicalBusiness',
					'@id'        => '%schema_organization_id%',
					'name'       => '%schema_sitename%',
					'url'        => '%schema_site_url%',
					'telephone'  => '',
					'image'      => '',
					'address'    => array(
						'@type'           => 'PostalAddress',
						'streetAddress'   => '',
						'addressLocality' => '',
						'addressRegion'   => '',
						'postalCode'      => '',
						'addressCountry'  => 'ES',
					),
					'openingHours' => '',
				) ),
			),
			'WebSite'             => array(
				'label'       => 'WebSite',
				'description' => 'Sitio web completo, con caja de búsqueda (SearchAction).',
				'json'        => self::pretty( array(
					'@type'           => 'WebSite',
					'@id'             => '%schema_website_id%',
					'url'             => '%schema_site_url%',
					'name'            => '%schema_sitename%',
					'inLanguage'      => '%schema_lang%',
					'publisher'       => array( '@id' => '%schema_organization_id%' ),
					'potentialAction' => array(
						'@type'       => 'SearchAction',
						'target'      => array(
							'@type'       => 'EntryPoint',
							'urlTemplate' => '%schema_site_url%?s={search_term_string}',
						),
						'query-input' => 'required name=search_term_string',
					),
				) ),
			),
			'WebPage'             => array(
				'label'       => 'WebPage',
				'description' => 'Página genérica: título, descripción y fecha de actualización.',
				'json'        => self::pretty( array(
					'@type'             => 'WebPage',
					'@id'               => '%schema_url%#webpage',
					'name'              => '%schema_headline%',
					'description'       => '%schema_description%',
					'url'               => '%schema_url%',
					'inLanguage'        => '%schema_lang%',
					'isPartOf'          => array( '@id' => '%schema_website_id%' ),
					'mainEntityOfPage'  => '%schema_url%',
					'publisher'         => array( '@id' => '%schema_organization_id%' ),
				) ),
			),
			'CollectionPage'      => array(
				'label'       => 'CollectionPage',
				'description' => 'Listados: categorías, etiquetas y archivos.',
				'json'        => '{
  "@type": "CollectionPage",
  "@id": "%schema_url%#collectionpage",
  "name": "%schema_headline%",
  "description": "%schema_description%",
  "url": "%schema_url%",
  "inLanguage": "%schema_lang%",
  "isPartOf": {
    "@id": "%schema_website_id%"
  },
  "mainEntity": {
    "@type": "ItemList",
    "itemListElement": %schema_item_list_items%
  }
}',
			),
			'Article'             => array(
				'label'       => 'Article',
				'description' => 'Artículos y entradas de blog con autor y fecha.',
				'json'        => self::pretty( array(
					'@type'            => 'Article',
					'@id'              => '%schema_url%#article',
					'headline'         => '%schema_headline%',
					'name'             => '%schema_headline%',
					'description'      => '%schema_description%',
					'url'              => '%schema_url%',
					'inLanguage'       => '%schema_lang%',
					'datePublished'    => '%schema_date_published%',
					'dateModified'     => '%schema_date_modified%',
					'image'            => '%schema_image%',
					'isPartOf'         => array( '@id' => '%schema_website_id%' ),
					'mainEntityOfPage' => '%schema_url%',
					'publisher'        => array( '@id' => '%schema_organization_id%' ),
					'author'           => array(
						'@type' => 'Person',
						'@id'   => '%schema_author_url%#person',
						'name'  => '%schema_author_name%',
						'url'   => '%schema_author_url%',
					),
					// Revisor clínico/editorial del sitio (E-E-A-T) -- variable
					// de sitio (Variables → Revisor médico), no del post. Pensado
					// para "revisado por [profesional colegiado]" (2026-09-27).
					// Vacío hasta que se rellene ahí.
					'reviewedBy'       => array(
						'@type'    => 'Person',
						'name'     => '%schema_reviewer_name%',
						'url'      => '%schema_reviewer_url%',
						'jobTitle' => '%schema_reviewer_job_title%',
						'image'    => '%schema_reviewer_image%',
					),
				) ),
			),
			'NewsArticle'         => array(
				'label'       => 'NewsArticle',
				'description' => 'Noticias con fecha de publicación y editor.',
				// No usa self::pretty() (array PHP -> json_encode) porque
				// %schema_image_objects% tiene que insertarse SIN comillas
				// (es un array ya serializado, ver Cmdroom_Schema_Variables) --
				// mismo caso que el bloque BreadcrumbList de abajo. Estructura
				// adaptada de un ejemplo real de un gran medio (2026-09-24):
				// sin "video"/"license" (no todos los sitios tienen vídeo
				// embebido ni página de licencia) y con "isPartOf" apuntando al
				// WebSite del sitio en vez de un bundle de suscripción de
				// pago. El "author" SÍ se amplió como en el ejemplo
				// con description/jobTitle/image reales --
				// bio y avatar (Simple Local Avatars) del perfil del
				// autor, y jobTitle lee el campo propio del
				// tema "db_author_role" cuando el autor lo tiene relleno.
				// Sin sameAs (redes sociales) de fábrica -- se puede
				// añadir cuando los autores tengan esos perfiles.
				'json'        => '{
  "@type": "NewsArticle",
  "headline": "%schema_headline%",
  "alternativeHeadline": "%schema_headline%",
  "datePublished": "%schema_date_published%",
  "dateModified": "%schema_date_modified%",
  "publisher": {
    "@id": "%schema_organization_id%"
  },
  "description": "%schema_description%",
  "keywords": "%schema_keywords%",
  "articleSection": "%schema_category%",
  "articleBody": "%schema_article_body%",
  "mainEntityOfPage": {
    "@type": "WebPage",
    "@id": "%schema_url%"
  },
  "author": [
    {
      "@type": "Person",
      "name": "%schema_author_name%",
      "url": "%schema_author_url%",
      "description": "%schema_author_description%",
      "jobTitle": "%schema_author_job_title%",
      "image": "%schema_author_image%"
    }
  ],
  "reviewedBy": {
    "@type": "Person",
    "name": "%schema_reviewer_name%",
    "url": "%schema_reviewer_url%",
    "jobTitle": "%schema_reviewer_job_title%",
    "image": "%schema_reviewer_image%"
  },
  "image": %schema_image_objects%,
  "inLanguage": "%schema_lang%",
  "isAccessibleForFree": true,
  "isPartOf": {
    "@id": "%schema_website_id%"
  }
}',
			),
			'Person'              => array(
				'label'       => 'Person',
				'description' => 'Autor o persona: nombre, imagen y perfiles.',
				'json'        => self::pretty( array(
					'@type'       => 'Person',
					'@id'         => '%schema_url%#person',
					'name'        => '%schema_author_name%',
					'url'         => '%schema_url%',
					'description' => '%schema_description%',
					'image'       => '',
					'sameAs'      => array(),
				) ),
			),
			'BreadcrumbList'      => array(
				'label'       => 'BreadcrumbList',
				'description' => 'Migas de pan de la jerarquía de la página.',
				'json'        => '{
  "@type": "BreadcrumbList",
  "@id": "%schema_url%#breadcrumb",
  "itemListElement": %schema_breadcrumb_items%
}',
			),
			'FAQPage'             => array(
				'label'       => 'FAQPage',
				'description' => 'Preguntas frecuentes con sus respuestas.',
				'json'        => self::pretty( array(
					'@type'      => 'FAQPage',
					'@id'        => '%schema_url%#faq',
					'mainEntity' => array(
						array(
							'@type'          => 'Question',
							'name'           => '',
							'acceptedAnswer' => array( '@type' => 'Answer', 'text' => '' ),
						),
					),
				) ),
			),
			'HowTo'               => array(
				'label'       => 'HowTo',
				'description' => 'Guías paso a paso con herramientas y tiempo.',
				'json'        => self::pretty( array(
					'@type'       => 'HowTo',
					'@id'         => '%schema_url%#howto',
					'name'        => '%schema_headline%',
					'description' => '%schema_description%',
					'totalTime'   => '',
					'step'        => array(
						array( '@type' => 'HowToStep', 'name' => '', 'text' => '' ),
					),
				) ),
			),
			'Product'             => array(
				'label'       => 'Product',
				'description' => 'Producto con precio, SKU y disponibilidad.',
				'json'        => self::pretty( array(
					'@type'       => 'Product',
					'@id'         => '%schema_url%#product',
					'name'        => '%schema_headline%',
					'description' => '%schema_description%',
					'image'       => '%schema_image%',
					'sku'         => '',
					'brand'       => array( '@type' => 'Brand', 'name' => '' ),
				) ),
			),
			'Offer'               => array(
				'label'       => 'Offer',
				'description' => 'Oferta o precio asociado a un producto.',
				'json'        => self::pretty( array(
					'@type'         => 'Offer',
					'@id'           => '%schema_url%#offer',
					'url'           => '%schema_url%',
					'price'         => '',
					'priceCurrency' => 'EUR',
					'availability'  => 'https://schema.org/InStock',
				) ),
			),
			'Review'              => array(
				'label'       => 'Review',
				'description' => 'Reseña individual con valoración.',
				'json'        => self::pretty( array(
					'@type'        => 'Review',
					'@id'          => '%schema_url%#review',
					'author'       => array( '@type' => 'Person', 'name' => '' ),
					'reviewRating' => array( '@type' => 'Rating', 'ratingValue' => '', 'bestRating' => '5' ),
					'reviewBody'   => '',
				) ),
			),
			'AggregateRating'     => array(
				'label'       => 'AggregateRating',
				'description' => 'Valoración media a partir de varias reseñas.',
				'json'        => self::pretty( array(
					'@type'       => 'AggregateRating',
					'ratingValue' => '',
					'reviewCount' => '',
					'bestRating'  => '5',
				) ),
			),
			'Event'               => array(
				'label'       => 'Event',
				'description' => 'Evento con fecha, lugar y entradas.',
				'json'        => self::pretty( array(
					'@type'               => 'Event',
					'@id'                 => '%schema_url%#event',
					'name'                => '%schema_headline%',
					'startDate'           => '',
					'endDate'             => '',
					'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
					'eventStatus'         => 'https://schema.org/EventScheduled',
					'location'            => array( '@type' => 'Place', 'name' => '', 'address' => '' ),
				) ),
			),
			'VideoObject'         => array(
				'label'       => 'VideoObject',
				'description' => 'Vídeo con miniatura, duración y fecha.',
				'json'        => self::pretty( array(
					'@type'        => 'VideoObject',
					'@id'          => '%schema_url%#video',
					'name'         => '%schema_headline%',
					'description'  => '%schema_description%',
					'thumbnailUrl' => '%schema_image%',
					'uploadDate'   => '%schema_date_published%',
					'duration'     => '',
				) ),
			),
			'Recipe'              => array(
				'label'       => 'Recipe',
				'description' => 'Receta con ingredientes, tiempos y calorías.',
				'json'        => self::pretty( array(
					'@type'              => 'Recipe',
					'@id'                => '%schema_url%#recipe',
					'name'               => '%schema_headline%',
					'description'        => '%schema_description%',
					'image'              => '%schema_image%',
					'totalTime'          => '',
					'recipeYield'        => '',
					'recipeIngredient'   => array(),
					'recipeInstructions' => array(),
				) ),
			),
			'JobPosting'          => array(
				'label'       => 'JobPosting',
				'description' => 'Oferta de empleo con salario y ubicación.',
				'json'        => self::pretty( array(
					'@type'              => 'JobPosting',
					'@id'                => '%schema_url%#jobposting',
					'title'              => '%schema_headline%',
					'description'        => '%schema_description%',
					'datePosted'         => '%schema_date_published%',
					'employmentType'     => '',
					'hiringOrganization' => array( '@type' => 'Organization', '@id' => '%schema_organization_id%' ),
					'jobLocation'        => array( '@type' => 'Place', 'address' => '' ),
				) ),
			),
			'Course'              => array(
				'label'       => 'Course',
				'description' => 'Curso formativo con proveedor.',
				'json'        => self::pretty( array(
					'@type'       => 'Course',
					'@id'         => '%schema_url%#course',
					'name'        => '%schema_headline%',
					'description' => '%schema_description%',
					'provider'    => array( '@type' => 'Organization', '@id' => '%schema_organization_id%' ),
				) ),
			),
			'SoftwareApplication' => array(
				'label'       => 'SoftwareApplication',
				'description' => 'Aplicación o software con sistema operativo y precio.',
				'json'        => self::pretty( array(
					'@type'               => 'SoftwareApplication',
					'@id'                 => '%schema_url%#softwareapplication',
					'name'                => '%schema_headline%',
					'operatingSystem'     => '',
					'applicationCategory' => '',
					'offers'              => array( '@type' => 'Offer', 'price' => '', 'priceCurrency' => 'EUR' ),
				) ),
			),
		);
	}

	/**
	 * JSON legible (indentado, sin escapar barras/unicode) para los
	 * esqueletos de la Librería -- se generan una vez desde arrays PHP en
	 * vez de escribirse a mano como texto para no arrastrar comas/llaves mal
	 * cerradas en 21 bloques distintos.
	 */
	private static function pretty( $data ) {
		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * El shell es PHP (pestañas, cabecera, botón Guardar); el contenido de
	 * cada pestaña (chips, terminal, menú "+ Añadir", cuadrícula de
	 * Librería) lo pinta entero assets/js/schema-editor.js a partir de los
	 * dos bloques de datos que se embeben aquí -- el estado guardado
	 * (`cmdroom-schema-state`) y el catálogo de la Librería
	 * (`cmdroom-schema-library`), como JSON dentro de
	 * <script type="application/json">, nunca ejecutado, solo leído con
	 * JSON.parse(). No se sirve slashes sin escapar (sin
	 * JSON_UNESCAPED_SLASHES) a propósito: así un "</script>" dentro de un
	 * bloque guardado nunca puede cerrar la etiqueta antes de tiempo.
	 */
	public static function render_page() {
		$opts = self::get_options();

		$tabs = self::GROUPS;

		$variables = array(
			'%title%', '%sitename%', '%sitedesc%', '%sep%', '%excerpt%', '%category%',
			'%author_name%', '%date%', '%currentyear%', '%page%', '%term_title%',
			'%term_description%', '%url%', '%robots%', '%image%', '%keywords%',
		);
		?>
		<div class="wrap cmdroom-wrap cmdroom-metadata-wrap cmdroom-schema-wrap">
			<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Ajustes guardados.', 'command-room' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cmdroom_schema_error'] ) ) : ?>
				<div class="notice notice-error"><p><?php esc_html_e( 'No se ha podido guardar: el estado enviado no es JSON válido. Nada se ha sobrescrito.', 'command-room' ); ?></p></div>
			<?php endif; ?>

			<h1 class="cmdroom-md-h1"><?php esc_html_e( 'Datos estructurados', 'command-room' ); ?></h1>

			<div class="cmdroom-md-container">
				<p class="cmdroom-md-intro">
					<?php esc_html_e( 'El bloque JSON-LD se imprime en el <head> de cada página según su tipo de contenido. Configura los campos a continuación:', 'command-room' ); ?>
				</p>

				<details class="cmdroom-md-vars">
					<summary><?php esc_html_e( 'Ver variables disponibles', 'command-room' ); ?></summary>
					<div class="cmdroom-md-vars-row">
						<?php foreach ( $variables as $var ) : ?>
							<span class="cmdroom-md-chip"><?php echo esc_html( $var ); ?></span>
						<?php endforeach; ?>
					</div>
				</details>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cmdroom-schema-form">
					<?php wp_nonce_field( 'cmdroom_save_schema_settings' ); ?>
					<input type="hidden" name="action" value="cmdroom_save_schema_settings" />
					<input type="hidden" name="schema_state" id="cmdroom-schema-state" value="" />
					<input type="hidden" name="library_state" id="cmdroom-schema-library-state" value="" />

					<div class="cmdroom-schema-tabs" id="cmdroom-schema-tabs">
						<?php foreach ( $tabs as $key => $label ) : ?>
							<button type="button" class="cmdroom-md-tab" data-tab="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></button>
						<?php endforeach; ?>
						<button type="button" class="cmdroom-md-tab" data-tab="libreria"><?php esc_html_e( 'Librería', 'command-room' ); ?></button>
					</div>

					<div id="cmdroom-schema-content"></div>

					<?php submit_button( __( 'Guardar', 'command-room' ), 'cmdroom-md-save', 'submit', false ); ?>
				</form>

				<div class="cmdroom-md-footer">
					<h2 class="cmdroom-md-footer-title"><?php esc_html_e( 'Más información', 'command-room' ); ?></h2>
					<p class="cmdroom-md-footer-text">
						<?php esc_html_e( 'El JSON-LD se genera automáticamente a partir de estos campos y se inyecta como <script type="application/ld+json"> — sin necesidad de tocar el tema.', 'command-room' ); ?>
					</p>
				</div>
			</div>

			<script type="application/json" id="cmdroom-schema-state-data"><?php echo wp_json_encode( $opts['groups'], JSON_UNESCAPED_UNICODE ); ?></script>
			<script type="application/json" id="cmdroom-schema-library-data"><?php echo wp_json_encode( self::library(), JSON_UNESCAPED_UNICODE ); ?></script>
			<script type="application/json" id="cmdroom-schema-tabs-data"><?php echo wp_json_encode( $tabs, JSON_UNESCAPED_UNICODE ); ?></script>
		</div>
		<?php
	}
}
