<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calcula el paquete final de metas (bloque de <head> editable, título/
 * descripción en bruto, canonical, robots, imagen) para un post, un
 * término, home o un archivo de autor: override manual si existe, si no la
 * plantilla del tipo de contenido/taxonomía. Es el único sitio donde se
 * decide esto -- lo usan tanto la salida real en wp_head como la vista
 * previa de Herramientas y Datos estructurados, para que nunca diverjan.
 *
 * Desde 0.11.0 el bloque de <head> ("head_html") cubre TODO -- title,
 * description, keywords, robots, canonical y los bloques Open Graph/
 * Twitter -- porque Cmdroom_Meta_Output ya no imprime nada de eso aparte
 * (ver su docblock). Para que %url%/%robots%/%image% del bloque HTML y el
 * resto del plugin (Tools, Datos estructurados) usen siempre el mismo
 * cálculo, ese cálculo vive aquí en tres métodos reutilizables:
 *
 *  - resolve_canonical_for_context(): la URL canónica del contexto.
 *  - resolve_robots_for_context(): el directive de robots final, juntando
 *    el override manual del post con las reglas del módulo 18 (Archivos y
 *    taxonomías: noindex de autor/fecha/paginación/términos vacíos).
 *  - get_og_image_for_context() / get_og_image(): la imagen destacada del
 *    post, con fallback al logo del negocio (Datos estructurados) si no
 *    hay imagen o el contexto no es un post.
 *
 * Cmdroom_Meta_Variables::build_vars() llama a los tres para rellenar
 * %url%/%robots%/%image%/%og_image% -- así el bloque HTML del administrador y el
 * resto de consumidores nunca pueden divergir sobre qué es "el canonical"
 * o "el robots" de una página.
 *
 * El array de retorno de cada resolve_for_*() sigue separando dos cosas:
 *  - 'head_html': el bloque editable, ya con variables sustituidas Y
 *    escapadas (esc_attr()/esc_url() según la variable), que se imprime
 *    literalmente en el <head>.
 *  - 'title'/'description': el título/extracto REAL del contenido (o el
 *    override manual del metabox si existe) SIN escapar -- lo sigue
 *    consumiendo Datos estructurados (Cmdroom_Schema_Variables), que aplica
 *    su propio escapado para JSON y se rompería si le llegara ya escapado
 *    para HTML.
 */
class Cmdroom_Meta_Resolver {

	/**
	 * Canonical autorreferente en /page/N/ de un listado (como Rank Math y
	 * lo que recomienda Google), en vez de apuntar todas a la página 1.
	 */
	private static function with_pagination( $url ) {
		global $wp_rewrite;
		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 2 || ! $url ) {
			return $url;
		}
		return user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $paged, 'paged' );
	}

	public static function resolve_for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$override_title = get_post_meta( $post->ID, '_cmdroom_title', true );
		$override_desc  = get_post_meta( $post->ID, '_cmdroom_description', true );

		$template = Cmdroom_Meta_Settings::get_post_type_template( $post->post_type );
		$context  = array( 'post' => $post );
		$vars     = Cmdroom_Meta_Variables::get_vars( $context );

		// El override del metabox sigue funcionando -- alimenta el título/
		// descripción "en bruto" que consume Datos estructurados. Los
		// overrides también pasan por el motor de variables: algunos
		// títulos importados de Rank Math guardan %sep%/%sitename% sin
		// resolver.
		$title = $override_title ? Cmdroom_Meta_Variables::replace( $override_title, $context ) : ( isset( $vars['title'] ) ? $vars['title'] : get_the_title( $post ) );
		$desc  = $override_desc ? Cmdroom_Meta_Variables::replace( $override_desc, $context ) : ( isset( $vars['excerpt'] ) ? $vars['excerpt'] : '' );

		// Nuevo en 0.11.0: el override también alimenta %title%/%excerpt%
		// DENTRO del bloque de <head> editable de este post concreto (antes
		// solo alimentaba Open Graph, porque ese bloque no existía todavía).
		// Se inyecta ya resuelto -- no como plantilla -- para que
		// build_vars() no tenga que volver a llamar a replace() sobre el
		// mismo contexto (evita recursión) y listo.
		$context['title_override']   = $title;
		$context['excerpt_override'] = $desc;
		// Para %seo_title%: distingue un override real de la caída al título
		// del post (title_override se rellena en los dos casos).
		$context['has_title_override'] = (bool) $override_title;

		// $escape = true: este HTML lo escribe el administrador a mano y puede meter
		// %title% (o cualquier variable) dentro de un atributo, de un nodo
		// de texto, donde sea -- ver docblock de Cmdroom_Meta_Variables::replace().
		$head_html = Cmdroom_Meta_Variables::replace( $template['html'], $context, true );

		$canonical = self::resolve_canonical_for_context( $context );
		$robots    = self::resolve_robots_for_context( $context );
		$image     = self::get_og_image( $post );

		return array(
			'title'       => $title,
			'description' => $desc,
			'head_html'   => $head_html,
			'canonical'   => $canonical,
			'noindex'     => $robots['noindex'],
			'nofollow'    => $robots['nofollow'],
			// og_type/og_title/og_desc/og_image se conservan por
			// compatibilidad (Datos estructurados y futuras integraciones
			// pueden seguir leyéndolos) aunque desde 0.11.0
			// Cmdroom_Meta_Output ya no los imprime por su cuenta -- el
			// propio head_html ya trae su bloque Open Graph completo.
			'og_type'     => 'article',
			'og_title'    => $title,
			'og_desc'     => $desc,
			'og_image'    => $image,
		);
	}

	public static function resolve_for_term( $term ) {
		if ( ! ( $term instanceof WP_Term ) ) {
			return null;
		}

		// Override por término (2026-09-25, pedido por el administrador para "Listado
		// completo" de Tags): mismo mecanismo que el override de post
		// (_cmdroom_title/_cmdroom_description), pero en term meta -- cae a
		// la plantilla de la taxonomía si no se ha configurado nada, igual
		// que un post sin override cae a la plantilla de su tipo.
		$override_title = get_term_meta( $term->term_id, '_cmdroom_title', true );
		$override_desc  = get_term_meta( $term->term_id, '_cmdroom_description', true );

		$template = Cmdroom_Meta_Settings::get_taxonomy_template( $term->taxonomy );
		$context  = array( 'term' => $term );
		$vars     = Cmdroom_Meta_Variables::get_vars( $context );

		$title = $override_title ? Cmdroom_Meta_Variables::replace( $override_title, $context ) : ( isset( $vars['title'] ) ? $vars['title'] : $term->name );
		$desc  = $override_desc ? Cmdroom_Meta_Variables::replace( $override_desc, $context ) : ( isset( $vars['excerpt'] ) ? $vars['excerpt'] : '' );

		// Igual que en resolve_for_post(): se inyecta ya resuelto para que
		// %title%/%excerpt% dentro del bloque de <head> de la taxonomía
		// también reflejen el override de este término concreto.
		$context['title_override']   = $title;
		$context['excerpt_override'] = $desc;
		$context['has_title_override'] = (bool) $override_title;

		$head_html = Cmdroom_Meta_Variables::replace( $template['html'], $context, true );

		$robots = self::resolve_robots_for_context( $context );

		return array(
			'title'       => $title,
			'description' => $desc,
			'head_html'   => $head_html,
			'canonical'   => self::resolve_canonical_for_context( $context ),
			'noindex'     => $robots['noindex'],
			'nofollow'    => $robots['nofollow'],
			'og_type'     => 'website',
			'og_title'    => $title,
			'og_desc'     => $desc,
			'og_image'    => self::get_business_logo_fallback(),
		);
	}

	public static function resolve_for_home() {
		$template = Cmdroom_Meta_Settings::get_home_template();
		$context  = array( 'is_home' => true );
		if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
			$front_desc = get_post_meta( (int) get_option( 'page_on_front' ), '_cmdroom_description', true );
			if ( $front_desc ) {
				$context['excerpt_override'] = Cmdroom_Meta_Variables::replace( $front_desc, array( 'post' => get_post( (int) get_option( 'page_on_front' ) ) ) );
			}
		}
		$vars     = Cmdroom_Meta_Variables::get_vars( $context );

		$title = isset( $vars['title'] ) ? $vars['title'] : get_bloginfo( 'name' );
		$desc  = isset( $vars['excerpt'] ) ? $vars['excerpt'] : get_bloginfo( 'description' );

		$head_html = Cmdroom_Meta_Variables::replace( $template['html'], $context, true );

		$robots = self::resolve_robots_for_context( $context );

		return array(
			'title'       => $title,
			'description' => $desc,
			'head_html'   => $head_html,
			'canonical'   => self::resolve_canonical_for_context( $context ),
			'noindex'     => $robots['noindex'],
			'nofollow'    => $robots['nofollow'],
			'og_type'     => 'website',
			'og_title'    => $title,
			'og_desc'     => $desc,
			'og_image'    => self::get_business_logo_fallback(),
		);
	}

	/**
	 * Nuevo en 0.10.0: antes is_author() caía en
	 * Cmdroom_Meta_Output::resolve_for_generic_archive(), un fallback sin
	 * plantilla propia. Ahora que 'author_archive' tiene su propio bloque de
	 * <head> en Ajustes, le damos un resolver real, análogo a
	 * resolve_for_home().
	 */
	public static function resolve_for_author( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return null;
		}

		$template = Cmdroom_Meta_Settings::get_author_archive_template();
		$context  = array( 'author' => $user );
		$vars     = Cmdroom_Meta_Variables::get_vars( $context );

		$title = isset( $vars['title'] ) ? $vars['title'] : $user->display_name;
		$desc  = isset( $vars['excerpt'] ) ? $vars['excerpt'] : '';

		$head_html = Cmdroom_Meta_Variables::replace( $template['html'], $context, true );

		$robots = self::resolve_robots_for_context( $context );

		return array(
			'title'       => $title,
			'description' => $desc,
			'head_html'   => $head_html,
			'canonical'   => self::resolve_canonical_for_context( $context ),
			'noindex'     => $robots['noindex'],
			'nofollow'    => $robots['nofollow'],
			'og_type'     => 'website',
			'og_title'    => $title,
			'og_desc'     => $desc,
			'og_image'    => self::get_business_logo_fallback(),
		);
	}

	/**
	 * URL canónica del contexto -- fuente única para 'canonical' (en el
	 * paquete de metas) y %url% (en el bloque de <head>). Recibe el mismo
	 * array de contexto que build_vars()/resolve_for_*(): 'post', 'term',
	 * 'author' o 'is_home'.
	 */
	public static function resolve_canonical_for_context( $context = array() ) {
		if ( isset( $context['post'] ) && $context['post'] instanceof WP_Post ) {
			$post      = $context['post'];
			$canonical = get_post_meta( $post->ID, '_cmdroom_canonical', true );
			if ( $canonical ) {
				return $canonical;
			}
			$is_posts_page = (int) get_option( 'page_for_posts' ) === (int) $post->ID;
			return $is_posts_page ? self::with_pagination( get_permalink( $post ) ) : get_permalink( $post );
		}

		if ( isset( $context['term'] ) && $context['term'] instanceof WP_Term ) {
			$link = get_term_link( $context['term'] );
			return is_wp_error( $link ) ? '' : self::with_pagination( $link );
		}

		if ( isset( $context['author'] ) && $context['author'] instanceof WP_User ) {
			return self::with_pagination( get_author_posts_url( $context['author']->ID ) );
		}

		if ( ! empty( $context['is_home'] ) ) {
			return home_url( '/' );
		}

		return '';
	}

	/**
	 * Directive final de robots ("index, follow", "noindex, follow"...)
	 * para el contexto dado -- fuente única para 'noindex'/'nofollow' (en el
	 * paquete de metas) y %robots% (en el bloque de <head>). Centraliza la
	 * lógica que antes vivía repartida entre el override manual del post
	 * (_cmdroom_noindex/_cmdroom_nofollow) y las reglas del módulo 18
	 * (Cmdroom_Archive_Optimization_Settings::is_enabled()), que hasta
	 * 0.10.0 se aplicaban dentro de Cmdroom_Meta_Output::resolve_current().
	 *
	 * @return array{noindex: bool, nofollow: bool, directive: string}
	 */
	public static function resolve_robots_for_context( $context = array() ) {
		$noindex  = false;
		$nofollow = false;

		if ( isset( $context['post'] ) && $context['post'] instanceof WP_Post ) {
			$post_id  = $context['post']->ID;
			$noindex  = (bool) get_post_meta( $post_id, '_cmdroom_noindex', true );
			$nofollow = (bool) get_post_meta( $post_id, '_cmdroom_nofollow', true );
		} elseif ( isset( $context['term'] ) && $context['term'] instanceof WP_Term ) {
			$term = $context['term'];
			if ( self::archive_rule_enabled( 'emptyterm' ) && 0 === (int) $term->count ) {
				$noindex = true;
			}
			if ( 'post_tag' === $term->taxonomy && self::archive_rule_enabled( 'tags' ) ) {
				$noindex = true;
			}
			if ( 'post_format' === $term->taxonomy && self::archive_rule_enabled( 'format' ) ) {
				$noindex = true;
			}
		} elseif ( isset( $context['author'] ) && $context['author'] instanceof WP_User ) {
			if ( self::archive_rule_enabled( 'author' ) ) {
				$noindex = true;
			}
		} elseif ( ! empty( $context['is_date'] ) ) {
			if ( self::archive_rule_enabled( 'date' ) ) {
				$noindex = true;
			}
		} elseif ( ! empty( $context['is_search'] ) ) {
			if ( self::archive_rule_enabled( 'search' ) ) {
				$noindex = true;
			}
		}
		// 'is_home': sin reglas propias -- la home nunca lleva noindex por
		// este módulo.

		// La paginación aplica por encima de lo anterior, en cualquier
		// contexto (incluye singulares con <!--nextpage-->, no solo
		// archivos) -- mismo comportamiento que tenía
		// Cmdroom_Meta_Output::resolve_current() antes de 0.11.0.
		if ( self::archive_rule_enabled( 'paged' ) && self::is_paginated_request() ) {
			$noindex = true;
		}

		// max-image-preview:large: la misma directiva que traía por defecto
		// el <meta name="robots"> nativo de WordPress (wp_robots(), WP 5.7+)
		// que se silenció en Cmdroom_Meta_Output -- no era solo "ruido
		// duplicado", era una directiva real (recomendada por Google para
		// que las imágenes se puedan mostrar a tamaño completo en
		// resultados de búsqueda). Se conserva aquí para no perderla.
		return array(
			'noindex'   => $noindex,
			'nofollow'  => $nofollow,
			'directive' => ( $noindex ? 'noindex' : 'index' ) . ', ' . ( $nofollow ? 'nofollow' : 'follow' ) . ', max-image-preview:large',
		);
	}

	private static function archive_rule_enabled( $rule ) {
		return class_exists( 'Cmdroom_Archive_Optimization_Settings' ) && Cmdroom_Archive_Optimization_Settings::is_enabled( $rule );
	}

	private static function is_paginated_request() {
		$paged = (int) get_query_var( 'paged' );
		if ( ! $paged ) {
			$paged = (int) get_query_var( 'page' );
		}
		return $paged > 1;
	}

	/**
	 * Imagen para %image%/%og_image% según el contexto -- solo los posts
	 * tienen "imagen destacada"; en el resto de contextos (home, término,
	 * autor) se cae directo al logo del negocio.
	 */
	public static function get_og_image_for_context( $context = array() ) {
		if ( isset( $context['post'] ) && $context['post'] instanceof WP_Post ) {
			return self::get_og_image( $context['post'] );
		}
		return self::get_business_logo_fallback();
	}

	/**
	 * Imagen destacada del post con fallback al logo del negocio (Datos
	 * estructurados) si no tiene -- decisión de 0.11.0, documentada en el
	 * resumen de sesión: antes devolvía '' sin más si no había destacada.
	 */
	public static function get_og_image( WP_Post $post ) {
		if ( has_post_thumbnail( $post ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'full' );
			if ( $src ) {
				return $src[0];
			}
		}
		return self::get_business_logo_fallback();
	}

	/**
	 * Orden: logo del negocio (Datos estructurados) primero -- ya es lo que
	 * cumple este papel hoy en los sitios que lo tienen configurado -- y
	 * solo si está vacío se prueba la imagen de respaldo de la pestaña
	 * "Variables" (2026-09-25, Cmdroom_Variables_Settings). Anteponerla
	 * habría desplazado el logo en cualquier sitio que ya dependa de él sin
	 * haber tocado nada nuevo.
	 */
	private static function get_business_logo_fallback() {
		if ( class_exists( 'Cmdroom_Schema_Settings' ) ) {
			$business = Cmdroom_Schema_Settings::get_business();
			if ( ! empty( $business['logo'] ) ) {
				return $business['logo'];
			}
		}
		if ( class_exists( 'Cmdroom_Variables_Settings' ) ) {
			$backup = Cmdroom_Variables_Settings::resolve_backup_image_url();
			if ( $backup ) {
				return $backup;
			}
		}
		return '';
	}
}
