<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Motor de variables para las plantillas JSON de Datos estructurados.
 * Mismo mecanismo de preg_replace_callback que Cmdroom_Meta_Variables, pero
 * con dos diferencias importantes:
 *
 *  1. Los tokens son %schema_*% -- namespace propio para no chocar con
 *     %title%/%excerpt%/etc. de Metas.
 *  2. Cada valor se pre-escapa para JSON con wp_json_encode() (quitando las
 *     comillas exteriores) antes de sustituirse, así una plantilla puede
 *     escribir "headline": "%schema_headline%" y el resultado sigue siendo
 *     JSON válido aunque el título real tenga comillas, backslashes o
 *     saltos de línea.
 *
 * Vive en su propia clase (en vez de un método más en Cmdroom_Meta_Variables)
 * porque el contrato de salida es distinto -- aquí el "valor de la
 * variable" no es el texto final, es texto-ya-escapado-para-JSON.
 */
class Cmdroom_Schema_Variables {

	public static function replace( $template, $context = array() ) {
		$template = (string) $template;
		if ( '' === trim( $template ) ) {
			return '';
		}

		$vars = self::build_vars( $context );

		// %schema_breadcrumb_items%, %schema_item_list_items% y
		// %schema_image_objects% son la misma excepción: no son valores
		// escapados para ir DENTRO de comillas, son arrays JSON ya
		// serializados (wp_json_encode()) que la plantilla inserta sin
		// comillas -- "image": %schema_image_objects%. Se sustituyen aparte
		// para no pasar por json_escape() en build_vars(), que los
		// convertiría en string y rompería el array.
		$breadcrumb_items = $vars['schema_breadcrumb_items'];
		$item_list_items  = $vars['schema_item_list_items'];
		$image_objects    = $vars['schema_image_objects'];
		unset( $vars['schema_breadcrumb_items'], $vars['schema_item_list_items'], $vars['schema_image_objects'] );

		$template = str_replace(
			array( '%schema_breadcrumb_items%', '%schema_item_list_items%', '%schema_image_objects%' ),
			array( $breadcrumb_items, $item_list_items, $image_objects ),
			$template
		);

		return preg_replace_callback(
			'/%(schema_[a-z_]+)%/',
			function ( $matches ) use ( $vars ) {
				return isset( $vars[ $matches[1] ] ) ? $vars[ $matches[1] ] : '';
			},
			$template
		);
	}

	private static function build_vars( $context ) {
		$post    = isset( $context['post'] ) ? $context['post'] : null;
		$term    = isset( $context['term'] ) ? $context['term'] : null;
		$author  = isset( $context['author'] ) ? $context['author'] : null;
		$is_home = ! empty( $context['is_home'] );

		$vars = array(
			// Independientes del contexto -- disponibles en cualquier bloque
			// (p. ej. el nodo Organization de la pestaña "General", que se
			// resuelve con el contexto de la página actual pero nunca debe
			// variar de una página a otra).
			'schema_organization_id' => home_url( '/#organization' ),
			'schema_website_id'      => home_url( '/#website' ),
			// Revisor clínico/editorial del sitio (E-E-A-T, "reviewedBy") --
			// independiente del contexto igual que schema_organization_id,
			// porque es UN único profesional que revisa todo el contenido
			// del sitio, no algo por post (ver Cmdroom_Variables_Settings).
			'schema_reviewer_name'      => class_exists( 'Cmdroom_Variables_Settings' ) ? Cmdroom_Variables_Settings::resolve_reviewer_name() : '',
			'schema_reviewer_url'       => class_exists( 'Cmdroom_Variables_Settings' ) ? Cmdroom_Variables_Settings::resolve_reviewer_url() : '',
			'schema_reviewer_job_title' => class_exists( 'Cmdroom_Variables_Settings' ) ? Cmdroom_Variables_Settings::resolve_reviewer_job_title() : '',
			'schema_reviewer_image'     => class_exists( 'Cmdroom_Variables_Settings' ) ? Cmdroom_Variables_Settings::resolve_reviewer_image_url() : '',
			// %schema_lang%/%schema_sitename%: overrides propios desde la
			// pestaña "Variables" (2026-09-25, Cmdroom_Variables_Settings).
			// %schema_sitename% vacío cae a %sitename% (que a su vez puede
			// tener su propio override), no directo a get_bloginfo().
			'schema_lang'            => class_exists( 'Cmdroom_Variables_Settings' ) ? Cmdroom_Variables_Settings::resolve_schema_lang() : get_bloginfo( 'language' ),
			'schema_sitename'        => class_exists( 'Cmdroom_Variables_Settings' ) ? Cmdroom_Variables_Settings::resolve_schema_sitename() : get_bloginfo( 'name' ),
			'schema_site_url'        => home_url( '/' ),
			'schema_headline'        => '',
			'schema_description'     => '',
			'schema_url'             => '',
			'schema_date_published'  => '',
			'schema_date_modified'   => '',
			'schema_author_name'        => '',
			'schema_author_url'         => '',
			'schema_author_description' => '',
			'schema_author_job_title'   => '',
			'schema_author_image'       => '',
			'schema_image'           => '',
			'schema_word_count'      => '',
			'schema_time_required'   => '',
			'schema_keywords'        => '',
			'schema_category'        => '',
			'schema_article_body'    => '',
		);

		if ( $post instanceof WP_Post ) {
			$meta = Cmdroom_Meta_Resolver::resolve_for_post( $post );

			$vars['schema_headline']       = wp_trim_words( get_the_title( $post ), 20, '' );
			$vars['schema_description']    = $meta ? $meta['description'] : '';
			$vars['schema_url']            = get_permalink( $post );
			$vars['schema_date_published'] = get_post_time( 'c', false, $post );
			$vars['schema_date_modified']  = get_post_modified_time( 'c', false, $post );
			$vars['schema_author_name']        = get_the_author_meta( 'display_name', $post->post_author );
			$vars['schema_author_url']         = get_author_posts_url( $post->post_author );
			$vars['schema_author_description'] = get_the_author_meta( 'description', $post->post_author );
			$vars['schema_author_job_title']   = get_the_author_meta( 'db_author_role', $post->post_author );
			$vars['schema_author_image']       = self::get_author_avatar_url( $post->post_author );
			$vars['schema_image']              = self::get_post_image( $post );

			$content    = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			$word_count = str_word_count( $content );
			$minutes    = max( 1, (int) ceil( $word_count / 200 ) );
			$tags       = get_the_terms( $post, 'post_tag' );
			$keywords   = ( $tags && ! is_wp_error( $tags ) ) ? wp_list_pluck( $tags, 'name' ) : array();

			$vars['schema_word_count']    = (string) $word_count;
			$vars['schema_time_required'] = 'PT' . $minutes . 'M';
			$vars['schema_keywords']      = $keywords ? implode( ', ', $keywords ) : '';
			$vars['schema_category']      = self::get_primary_category_name( $post );
			$vars['schema_article_body']  = $content;
		} elseif ( $term instanceof WP_Term ) {
			$meta = Cmdroom_Meta_Resolver::resolve_for_term( $term );

			$vars['schema_headline']    = $term->name;
			$vars['schema_description'] = $meta ? $meta['description'] : '';
			$vars['schema_url']         = get_term_link( $term );
		} elseif ( $is_home ) {
			$meta = Cmdroom_Meta_Resolver::resolve_for_home();

			$vars['schema_headline']    = get_bloginfo( 'name' );
			$vars['schema_description'] = $meta ? $meta['description'] : get_bloginfo( 'description' );
			$vars['schema_url']         = home_url( '/' );
		} elseif ( $author instanceof WP_User ) {
			$meta = Cmdroom_Meta_Resolver::resolve_for_author( $author );

			$vars['schema_headline']    = $author->display_name;
			$vars['schema_description'] = $meta ? $meta['description'] : '';
			$vars['schema_url']         = get_author_posts_url( $author->ID );
			$vars['schema_author_name']        = $author->display_name;
			$vars['schema_author_url']         = get_author_posts_url( $author->ID );
			$vars['schema_author_description'] = get_the_author_meta( 'description', $author->ID );
			$vars['schema_author_job_title']   = get_the_author_meta( 'db_author_role', $author->ID );
			$vars['schema_author_image']       = self::get_author_avatar_url( $author->ID );
		}

		// %schema_breadcrumb_items%, %schema_item_list_items% y
		// %schema_image_objects%: arrays JSON en crudo (ver docblock de
		// replace()), calculados ANTES del escapado de abajo -- no son
		// strings, no deben pasar por json_escape().
		$vars['schema_breadcrumb_items'] = self::get_breadcrumb_items_json( $context );
		$vars['schema_item_list_items']  = self::get_item_list_items_json( $context );
		$vars['schema_image_objects']    = self::get_image_objects_json( $post );

		// Pre-escapa todos los valores para poder insertarlos dentro de
		// comillas JSON sin romper el documento.
		$raw_keys = array( 'schema_breadcrumb_items', 'schema_item_list_items', 'schema_image_objects' );
		foreach ( $vars as $key => $value ) {
			if ( in_array( $key, $raw_keys, true ) ) {
				continue;
			}
			$vars[ $key ] = self::json_escape( $value );
		}

		return $vars;
	}

	/**
	 * Array `itemListElement` de un BreadcrumbList, ya serializado a JSON,
	 * para el bloque de la librería "BreadcrumbList" -- misma forma que
	 * construye Cmdroom_Schema_Builder::breadcrumb_node(), para no tener dos
	 * versiones del mismo cálculo. El archivo de autor no tiene
	 * Cmdroom_Breadcrumbs::get_items_for_author() todavía (limitación
	 * conocida, ver class-schema-builder.php) -- se resuelve como array
	 * vacío, no rompe nada.
	 */
	private static function get_breadcrumb_items_json( $context ) {
		if ( isset( $context['post'] ) && $context['post'] instanceof WP_Post ) {
			$items = Cmdroom_Breadcrumbs::get_items_for_post( $context['post'] );
		} elseif ( isset( $context['term'] ) && $context['term'] instanceof WP_Term ) {
			$items = Cmdroom_Breadcrumbs::get_items_for_term( $context['term'] );
		} elseif ( ! empty( $context['is_home'] ) ) {
			$items = Cmdroom_Breadcrumbs::get_items_for_home();
		} else {
			$items = array();
		}

		$list_items = array();
		foreach ( $items as $i => $item ) {
			$list_items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
		}

		return wp_json_encode( $list_items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Array `itemListElement` con los posts reales que salen en la página de
	 * listado actual (título + URL, sin duplicar el breadcrumb de navegación
	 * de arriba) -- el administrador pidió el 2026-09-24 que el CollectionPage de
	 * cualquier grupo que sea un listado (Categorías, Tags, Home -- el blog
	 * index -- y Página de autor) traiga un mainEntity propio con el
	 * contenido real de la página, separado del BreadcrumbList (que solo
	 * lleva la ruta de navegación). "Contenido"/"Páginas corporativas" no
	 * entran aquí -- son páginas singulares (Article/WebPage), no listados.
	 *
	 * Usa $wp_query global en vez de volver a consultar: en wp_head la
	 * consulta principal ya se ha ejecutado, así que $wp_query ya trae
	 * exactamente los posts de ESA página (respeta paginación y cualquier
	 * filtro que aplique el tema), sin duplicar esa lógica aquí. "position"
	 * es absoluta (offset de la página + índice), no se reinicia en 1 en
	 * cada página siguiente.
	 *
	 * Excepción: Home como portada estática (front-page.php),
	 * no el índice de blog nativo -- $wp_query en ese caso trae la Page en
	 * sí (is_singular), no una lista de posts. Y el propio front-page.php
	 * no tiene "la" consulta de la portada: pinta hero/últimos/tendencia/
	 * por categoría con varias llamadas a get_posts()/WP_Query distintas
	 * (ver el archivo del tema). Reconstruir eso aquí acoplaría el plugin a
	 * la maquetación del tema y se rompería en cuanto cambiara. Se usa una
	 * aproximación honesta -- los últimos posts publicados del sitio, con el
	 * mismo posts_per_page de Ajustes → Lectura -- en vez de un intento
	 * frágil de replicar cada módulo curado de la portada.
	 */
	private static function get_item_list_items_json( $context ) {
		$is_term   = isset( $context['term'] ) && $context['term'] instanceof WP_Term;
		$is_home   = ! empty( $context['is_home'] );
		$is_author = isset( $context['author'] ) && $context['author'] instanceof WP_User;

		if ( ! $is_term && ! $is_home && ! $is_author ) {
			return wp_json_encode( array() );
		}

		$per_page = (int) get_option( 'posts_per_page' );
		$paged    = max( 1, (int) get_query_var( 'paged' ) );

		if ( $is_home ) {
			// Portada estática -- consulta propia de "últimos posts", ver
			// docblock de arriba. ignore_sticky_posts para que el orden
			// coincida con "más reciente primero" sin sorpresas.
			$home_query = new WP_Query( array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => $per_page,
				'paged'               => $paged,
				'ignore_sticky_posts' => true,
			) );
			$posts = $home_query->posts;
		} else {
			global $wp_query;
			$posts = ( $wp_query instanceof WP_Query ) ? $wp_query->posts : array();
			if ( $wp_query instanceof WP_Query && (int) $wp_query->get( 'posts_per_page' ) > 0 ) {
				$per_page = (int) $wp_query->get( 'posts_per_page' );
			}
			if ( $wp_query instanceof WP_Query ) {
				$paged = max( 1, (int) $wp_query->get( 'paged' ) );
			}
		}

		$offset = ( $paged - 1 ) * $per_page;

		$list_items = array();
		foreach ( $posts as $i => $post ) {
			if ( ! ( $post instanceof WP_Post ) ) {
				continue;
			}
			$list_items[] = array(
				'@type'    => 'ListItem',
				'position' => $offset + $i + 1,
				'url'      => get_permalink( $post ),
				'name'     => get_the_title( $post ),
			);
		}

		return wp_json_encode( $list_items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * wp_json_encode() de un string siempre lo envuelve en comillas dobles
	 * (p. ej. 'Título "citado"' -> '"Título \"citado\""'); nos quedamos solo
	 * con el contenido interior ya escapado para que la plantilla pueda
	 * escribir "campo": "%token%" sin duplicar las comillas.
	 */
	private static function json_escape( $value ) {
		$encoded = wp_json_encode( (string) $value, JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || strlen( $encoded ) < 2 ) {
			return '';
		}
		return substr( $encoded, 1, -1 );
	}

	/**
	 * Foto real del autor para el Person del NewsArticle -- prioriza el
	 * avatar local (plugin "Simple Local Avatars") sobre Gravatar, que para un autor sin
	 * cuenta de Gravatar solo devuelve un icono genérico por defecto.
	 */
	private static function get_author_avatar_url( $author_id ) {
		$local = get_user_meta( $author_id, 'simple_local_avatar', true );
		if ( ! empty( $local['full'] ) ) {
			return $local['full'];
		}
		$url = get_avatar_url( $author_id );
		return $url ? $url : '';
	}

	private static function get_post_image( WP_Post $post ) {
		if ( has_post_thumbnail( $post ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'full' );
			if ( $src ) {
				return $src[0];
			}
		}
		return '';
	}

	/**
	 * Array `image` con un único ImageObject (url + dimensiones reales) --
	 * el ejemplo real de referencia para NewsArticle trae 3 recortes (16:9,
	 * 4:3, 1:1), pero WordPress no genera esos recortes adicionales (solo la
	 * imagen destacada tal cual se subió), así que replicar 3 entradas
	 * sería inventar URLs que no existen. Un solo ImageObject con las
	 * dimensiones reales es honesto y sigue siendo válido para NewsArticle.
	 */
	private static function get_image_objects_json( $post ) {
		if ( ! ( $post instanceof WP_Post ) || ! has_post_thumbnail( $post ) ) {
			return wp_json_encode( array() );
		}

		$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'full' );
		if ( ! $src ) {
			return wp_json_encode( array() );
		}

		$images = array(
			array(
				'@type' => 'ImageObject',
				'url'   => $src[0],
				'width' => (int) $src[1],
				'height' => (int) $src[2],
			),
		);

		return wp_json_encode( $images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Misma lógica que Cmdroom_Meta_Variables::get_primary_category_name()
	 * -- la primera categoría asignada al post -- pero vive también aquí
	 * (en vez de llamar a la otra clase) porque el contrato de escapado es
	 * distinto (ver docblock de la clase).
	 */
	private static function get_primary_category_name( WP_Post $post ) {
		$terms = get_the_terms( $post, 'category' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}
		return $terms[0]->name;
	}

	/**
	 * Catálogo de variables de schema -- lo lee el glosario de variables del
	 * admin (includes/class-variables-glossary.php) para documentarlas junto
	 * a las de Metas.
	 */
	public static function catalog() {
		return array(
			array( 'tag' => '%schema_sitename%', 'label' => __( 'Nombre del sitio', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Independiente del contexto -- para el "name" del nodo Organization/WebSite, que no debe variar de una página a otra.', 'command-room' ) ),
			array( 'tag' => '%schema_site_url%', 'label' => __( 'URL del sitio', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Independiente del contexto -- home_url("/"), para el "url" de Organization/WebSite.', 'command-room' ) ),
			array( 'tag' => '%schema_breadcrumb_items%', 'label' => __( 'Items del breadcrumb (array)', 'command-room' ), 'contexts' => array( 'post', 'term', 'home' ), 'description' => __( 'Solo para el bloque BreadcrumbList de la Librería -- se inserta SIN comillas: "itemListElement": %schema_breadcrumb_items%. Vacío en la página de autor (sin soporte todavía).', 'command-room' ) ),
			array( 'tag' => '%schema_item_list_items%', 'label' => __( 'Contenidos de la página (array)', 'command-room' ), 'contexts' => array( 'term', 'home', 'author_archive' ), 'description' => __( 'Solo para el bloque CollectionPage de Categorías/Tags/Home/Página de autor -- se inserta SIN comillas dentro de "mainEntity": {"@type": "ItemList", "itemListElement": %schema_item_list_items%}. Título + URL de los posts reales que salen en esa página de listado, respetando paginación. Vacío fuera de una página de listado (categoría, etiqueta, home, autor).', 'command-room' ) ),
			array( 'tag' => '%schema_headline%', 'label' => __( 'Titular', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Título real del post/término/sitio/autor (sin formato SEO) -- para headline/name.', 'command-room' ) ),
			array( 'tag' => '%schema_description%', 'label' => __( 'Descripción', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'El mismo extracto en bruto que usa Metas para Open Graph.', 'command-room' ) ),
			array( 'tag' => '%schema_url%', 'label' => __( 'URL', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Permalink/URL canónica del contexto actual.', 'command-room' ) ),
			array( 'tag' => '%schema_lang%', 'label' => __( 'Idioma', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'get_bloginfo("language") -- p. ej. "es-ES".', 'command-room' ) ),
			array( 'tag' => '%schema_date_published%', 'label' => __( 'Fecha de publicación', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'Formato ISO 8601 (\'c\').', 'command-room' ) ),
			array( 'tag' => '%schema_date_modified%', 'label' => __( 'Fecha de modificación', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'Formato ISO 8601 (\'c\').', 'command-room' ) ),
			array( 'tag' => '%schema_author_name%', 'label' => __( 'Nombre del autor', 'command-room' ), 'contexts' => array( 'post', 'author_archive' ), 'description' => __( 'Autor del post, o el propio archivo de autor.', 'command-room' ) ),
			array( 'tag' => '%schema_author_url%', 'label' => __( 'URL del autor', 'command-room' ), 'contexts' => array( 'post', 'author_archive' ), 'description' => __( 'Enlace al archivo de autor correspondiente.', 'command-room' ) ),
			array( 'tag' => '%schema_author_description%', 'label' => __( 'Bio del autor', 'command-room' ), 'contexts' => array( 'post', 'author_archive' ), 'description' => __( 'Biografía del perfil de WordPress -- vacía si el autor no la rellenó.', 'command-room' ) ),
			array( 'tag' => '%schema_author_job_title%', 'label' => __( 'Puesto del autor', 'command-room' ), 'contexts' => array( 'post', 'author_archive' ), 'description' => __( 'Campo propio del tema (db_author_role, p. ej. "Redactora de Running & Gear") -- vacío si ese autor no lo tiene relleno.', 'command-room' ) ),
			array( 'tag' => '%schema_author_image%', 'label' => __( 'Foto del autor', 'command-room' ), 'contexts' => array( 'post', 'author_archive' ), 'description' => __( 'Avatar real (Simple Local Avatars) o, si no tiene, el de Gravatar.', 'command-room' ) ),
			array( 'tag' => '%schema_image%', 'label' => __( 'Imagen destacada', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'URL de la imagen destacada, vacío si no hay.', 'command-room' ) ),
			array( 'tag' => '%schema_word_count%', 'label' => __( 'Número de palabras', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'Úsalo sin comillas -- es un número: "wordCount": %schema_word_count%', 'command-room' ) ),
			array( 'tag' => '%schema_time_required%', 'label' => __( 'Tiempo de lectura', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'Formato ISO 8601 de duración, p. ej. "PT4M".', 'command-room' ) ),
			array( 'tag' => '%schema_keywords%', 'label' => __( 'Etiquetas', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'Nombres de las etiquetas del post separados por coma.', 'command-room' ) ),
			array( 'tag' => '%schema_category%', 'label' => __( 'Categoría principal', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'Nombre de la primera categoría asignada al post -- para "articleSection".', 'command-room' ) ),
			array( 'tag' => '%schema_article_body%', 'label' => __( 'Cuerpo del artículo', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'Contenido completo del post en texto plano (sin HTML ni shortcodes) -- para "articleBody". Puede ser largo.', 'command-room' ) ),
			array( 'tag' => '%schema_image_objects%', 'label' => __( 'Imagen destacada (array)', 'command-room' ), 'contexts' => array( 'post' ), 'description' => __( 'Solo para NewsArticle/Article -- se inserta SIN comillas: "image": %schema_image_objects%. Array con un ImageObject (url + width + height reales de la imagen destacada), vacío si no hay imagen.', 'command-room' ) ),
			array( 'tag' => '%schema_organization_id%', 'label' => __( '@id de Organization', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Siempre home_url("/#organization") -- para "publisher": {"@id": "%schema_organization_id%"}.', 'command-room' ) ),
			array( 'tag' => '%schema_website_id%', 'label' => __( '@id de WebSite', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Siempre home_url("/#website") -- para "isPartOf": {"@id": "%schema_website_id%"}.', 'command-room' ) ),
			array( 'tag' => '%schema_reviewer_name%', 'label' => __( 'Nombre del revisor', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Independiente del contexto -- el profesional que revisa el contenido del sitio (E-E-A-T), para "reviewedBy". Se edita en Variables, no por post. Vacío hasta que se rellene.', 'command-room' ) ),
			array( 'tag' => '%schema_reviewer_url%', 'label' => __( 'URL del revisor', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Enlace al perfil del revisor (p. ej. su ficha de autor o de equipo).', 'command-room' ) ),
			array( 'tag' => '%schema_reviewer_job_title%', 'label' => __( 'Puesto del revisor', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'P. ej. "Director Clínico -- Fisioterapeuta colegiado".', 'command-room' ) ),
			array( 'tag' => '%schema_reviewer_image%', 'label' => __( 'Foto del revisor', 'command-room' ), 'contexts' => array( 'post', 'term', 'home', 'author_archive' ), 'description' => __( 'Imagen elegida en Variables para el revisor.', 'command-room' ) ),
		);
	}
}
