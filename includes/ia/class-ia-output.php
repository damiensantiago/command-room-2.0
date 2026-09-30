<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sirve /llms.txt y las URLs *.md de cada entrada/página. Mismo patrón que
 * Cmdroom_Sitemap_Rewrite: intercepta en template_redirect comparando el
 * REQUEST_URI a mano, sin add_rewrite_rule()/flush_rewrite_rules() — este
 * hosting ya ha dado problemas con el refresco de reglas de reescritura.
 */
class Cmdroom_Ia_Output {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
		// Prioridad 1 a propósito -- pedido explícito del administrador 2026-09-25:
		// el <link> de markdown tiene que salir justo detrás de las metas
		// (Cmdroom_Meta_Output::print_meta_tags(), prioridad 1) y del RSS
		// (Cmdroom_Feed_Links::print_feed_links_extra(), prioridad 1) y
		// antes de cualquier otra cosa (schema en prioridad 2, breadcrumbs
		// en 3, y todos los <link> nativos de WordPress en 10 por defecto:
		// RSD, wlwmanifest, oEmbed...). Dentro del mismo cubo de prioridad
		// gana el orden de registro, y Cmdroom_Ia_Output::init() se llama
		// después de Meta_Output/Feed_Links en el bootstrap
		// (command-room.php), así que corre justo a continuación de los dos.
		add_action( 'wp_head', array( __CLASS__, 'maybe_print_link_tag' ), 1 );
	}

	public static function maybe_serve() {
		$path = untrailingslashit( wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

		if ( '/llms.txt' === $path ) {
			if ( ! Cmdroom_Ia_Settings::is_llms_enabled() || Cmdroom_Ia_Settings::has_physical_llms_file() ) {
				return;
			}
			self::output_text( self::build_llms_txt(), 'text/markdown; charset=UTF-8' );
		}

		if ( '.md' === substr( $path, -3 ) ) {
			self::maybe_serve_markdown( $path );
		}
	}

	private static function maybe_serve_markdown( $path ) {
		if ( ! Cmdroom_Ia_Settings::is_markdown_enabled() ) {
			return;
		}

		$without_md = substr( $path, 0, -3 );
		$post_id    = url_to_postid( home_url( $without_md ) );
		if ( ! $post_id ) {
			$post_id = url_to_postid( home_url( $without_md . '/' ) );
		}
		if ( ! $post_id ) {
			return; // deja que WordPress siga su curso normal (404)
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! Cmdroom_Ia_Settings::is_markdown_post_type( $post->post_type ) ) {
			return;
		}

		if ( class_exists( 'Cmdroom_Meta_Resolver' ) ) {
			$resolved = Cmdroom_Meta_Resolver::resolve_for_post( $post );
			if ( $resolved && ! empty( $resolved['noindex'] ) ) {
				return; // noindex nunca se sirve en markdown
			}
		}

		self::output_text( self::build_post_markdown( $post ), 'text/markdown; charset=UTF-8' );
	}

	private static function output_text( $body, $content_type ) {
		// WP::handle_404() ya ha marcado la petición como 404 (y mandado esa
		// cabecera) mucho antes de llegar a template_redirect, porque estas
		// URLs no casan con ningún post/página real -- hay que revertirlo
		// explícitamente o el contenido sale con status 200... con cabecera
		// 404, que muchos crawlers sí miran.
		status_header( 200 );
		header( 'Content-Type: ' . $content_type );
		echo $body; // ya viene como texto plano/markdown, sin HTML que escapar
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* llms.txt                                                          */
	/* ------------------------------------------------------------------ */

	private static function build_llms_txt() {
		$opts = Cmdroom_Ia_Settings::get_options();
		$llms = $opts['llms'];

		$intro = '' !== trim( $llms['intro'] ) ? $llms['intro'] : ( get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : get_bloginfo( 'name' ) );

		$lines   = array();
		$lines[] = '# ' . get_bloginfo( 'name' );
		$lines[] = '';
		$lines[] = '> ' . $intro;

		if ( '' !== trim( $llms['body'] ) ) {
			$lines[] = '';
			$lines[] = trim( $llms['body'] );
		}

		if ( ! empty( $llms['include_pages'] ) ) {
			$pages = self::indexable_items( 'page', 0 );
			if ( $pages ) {
				$lines[] = '';
				$lines[] = '## ' . __( 'Páginas', 'command-room' );
				foreach ( $pages as $item ) {
					$lines[] = $item;
				}
			}
		}

		if ( ! empty( $llms['include_posts'] ) && $llms['posts_count'] > 0 ) {
			$posts = self::indexable_items( 'post', (int) $llms['posts_count'] );
			if ( $posts ) {
				$lines[] = '';
				$lines[] = '## ' . __( 'Entradas recientes', 'command-room' );
				foreach ( $posts as $item ) {
					$lines[] = $item;
				}
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Líneas "- [Título](url): descripción" para un tipo de contenido,
	 * saltando lo marcado noindex. $limit = 0 -> todas las páginas
	 * publicadas (no suelen ser muchas); en posts siempre se pasa un
	 * límite real.
	 */
	private static function indexable_items( $post_type, $limit ) {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'orderby'        => 'page' === $post_type ? 'menu_order title' : 'date',
			'order'          => 'ASC',
			'posts_per_page' => $limit > 0 ? $limit * 2 : -1, // margen para los que se descarten por noindex
		);
		if ( 'post' === $post_type ) {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		$query = new WP_Query( $args );
		$out   = array();

		foreach ( $query->posts as $post ) {
			if ( $limit > 0 && count( $out ) >= $limit ) {
				break;
			}

			$noindex = false;
			$title   = get_the_title( $post );
			$desc    = '';
			if ( class_exists( 'Cmdroom_Meta_Resolver' ) ) {
				$resolved = Cmdroom_Meta_Resolver::resolve_for_post( $post );
				if ( $resolved ) {
					$noindex = ! empty( $resolved['noindex'] );
					$title   = $resolved['title'] ? $resolved['title'] : $title;
					$desc    = $resolved['description'];
				}
			}
			if ( $noindex ) {
				continue;
			}

			$line = '- [' . self::md_escape( $title ) . '](' . get_permalink( $post ) . ')';
			if ( $desc ) {
				$line .= ': ' . self::md_escape( wp_strip_all_tags( $desc ) );
			}
			$out[] = $line;
		}

		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Markdown por página                                               */
	/* ------------------------------------------------------------------ */

	private static function build_post_markdown( $post ) {
		$title = get_the_title( $post );
		$desc  = '';
		if ( class_exists( 'Cmdroom_Meta_Resolver' ) ) {
			$resolved = Cmdroom_Meta_Resolver::resolve_for_post( $post );
			if ( $resolved ) {
				$title = $resolved['title'] ? $resolved['title'] : $title;
				$desc  = $resolved['description'];
			}
		}

		$lines   = array();
		$lines[] = '# ' . self::md_escape( $title );
		$lines[] = '';
		if ( $desc ) {
			$lines[] = '> ' . self::md_escape( wp_strip_all_tags( $desc ) );
			$lines[] = '';
		}
		$lines[] = self::html_to_markdown( self::content_html( $post ) );
		$lines[] = '';
		$lines[] = '---';
		$lines[] = __( 'Fuente:', 'command-room' ) . ' ' . get_permalink( $post );

		return implode( "\n", $lines ) . "\n";
	}

	private static function content_html( $post ) {
		$backup          = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		$content = apply_filters( 'the_content', $post->post_content );

		if ( $backup ) {
			$GLOBALS['post'] = $backup;
			setup_postdata( $backup );
		} else {
			wp_reset_postdata();
		}

		return $content;
	}

	private static function md_escape( $text ) {
		return str_replace( array( '[', ']' ), array( '\\[', '\\]' ), trim( (string) $text ) );
	}

	/* ------------------------------------------------------------------ */
	/* Conversor HTML -> Markdown                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Conversor deliberadamente simple (sin librería externa): cubre lo que
	 * genera el editor de bloques de WordPress en un post normal
	 * (encabezados, párrafos, listas, enlaces, negrita/cursiva, citas,
	 * código, imágenes). No pretende ser un conversor HTML->MD genérico.
	 */
	public static function html_to_markdown( $html ) {
		if ( '' === trim( (string) $html ) ) {
			return '';
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8"?><div id="cmdroom-root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();

		$root = $dom->getElementById( 'cmdroom-root' );
		if ( ! $root ) {
			return trim( wp_strip_all_tags( $html ) );
		}

		$md = self::node_to_markdown( $root );
		// Colapsa 3+ saltos de línea seguidos que puedan quedar del recorrido.
		$md = preg_replace( "/\n{3,}/", "\n\n", $md );

		return trim( $md );
	}

	private static function node_to_markdown( DOMNode $node, $list_depth = 0 ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::single_node_to_markdown( $child, $list_depth );
		}
		return $out;
	}

	private static function children_inline( DOMNode $node ) {
		$out = '';
		foreach ( $node->childNodes as $child ) {
			$out .= self::single_node_to_markdown( $child, 0, true );
		}
		return trim( preg_replace( '/\s+/', ' ', $out ) );
	}

	private static function single_node_to_markdown( DOMNode $node, $list_depth = 0, $inline_only = false ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return $node->textContent;
		}

		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}

		$tag = strtolower( $node->nodeName );

		switch ( $tag ) {
			case 'script':
			case 'style':
			case 'nav':
			case 'noscript':
				return '';

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );
				return "\n" . str_repeat( '#', $level ) . ' ' . self::children_inline( $node ) . "\n\n";

			case 'p':
				$text = self::children_inline( $node );
				return '' !== $text ? $text . "\n\n" : '';

			case 'br':
				return "\n";

			case 'hr':
				return "\n---\n\n";

			case 'strong':
			case 'b':
				$inner = self::children_inline( $node );
				return '' !== $inner ? '**' . $inner . '**' : '';

			case 'em':
			case 'i':
				$inner = self::children_inline( $node );
				return '' !== $inner ? '*' . $inner . '*' : '';

			case 'a':
				$href = $node->getAttribute( 'href' );
				$text = self::children_inline( $node );
				if ( '' === $text ) {
					return '';
				}
				return $href ? '[' . $text . '](' . $href . ')' : $text;

			case 'img':
				$alt = $node->getAttribute( 'alt' );
				$src = $node->getAttribute( 'src' );
				return $src ? "\n" . '![' . $alt . '](' . $src . ')' . "\n\n" : '';

			case 'blockquote':
				$inner = trim( self::node_to_markdown( $node, $list_depth ) );
				if ( '' === $inner ) {
					return '';
				}
				$quoted = implode( "\n", array_map( function ( $line ) {
					return '> ' . $line;
				}, explode( "\n", $inner ) ) );
				return "\n" . $quoted . "\n\n";

			case 'pre':
				$code = $node->textContent;
				return "\n```\n" . rtrim( $code ) . "\n```\n\n";

			case 'code':
				return '`' . $node->textContent . '`';

			case 'ul':
			case 'ol':
				$out = "\n";
				$i   = 1;
				foreach ( $node->childNodes as $child ) {
					if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
						continue;
					}
					$prefix = 'ol' === $tag ? ( $i++ . '. ' ) : '- ';
					$indent = str_repeat( '  ', $list_depth );
					$text   = trim( self::children_inline( $child ) );
					$out   .= $indent . $prefix . $text . "\n";

					// Listas anidadas dentro del <li>.
					foreach ( $child->childNodes as $grandchild ) {
						if ( XML_ELEMENT_NODE === $grandchild->nodeType && in_array( strtolower( $grandchild->nodeName ), array( 'ul', 'ol' ), true ) ) {
							$out .= self::single_node_to_markdown( $grandchild, $list_depth + 1 );
						}
					}
				}
				return $out . "\n";

			case 'table':
				return self::table_to_markdown( $node );

			case 'figure':
			case 'figcaption':
			case 'div':
			case 'span':
			case 'section':
			case 'article':
				return $inline_only ? self::children_inline( $node ) : self::node_to_markdown( $node, $list_depth );

			default:
				return $inline_only ? self::children_inline( $node ) : self::node_to_markdown( $node, $list_depth );
		}
	}

	private static function table_to_markdown( DOMNode $table ) {
		$rows = array();
		foreach ( $table->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( $tr->childNodes as $cell ) {
				if ( XML_ELEMENT_NODE === $cell->nodeType && in_array( strtolower( $cell->nodeName ), array( 'td', 'th' ), true ) ) {
					$cells[] = trim( self::children_inline( $cell ) );
				}
			}
			if ( $cells ) {
				$rows[] = $cells;
			}
		}

		if ( ! $rows ) {
			return '';
		}

		$out = "\n" . '| ' . implode( ' | ', $rows[0] ) . " |\n";
		$out .= '| ' . implode( ' | ', array_fill( 0, count( $rows[0] ), '---' ) ) . " |\n";
		for ( $i = 1; $i < count( $rows ); $i++ ) {
			$out .= '| ' . implode( ' | ', $rows[ $i ] ) . " |\n";
		}

		return $out . "\n";
	}

	/* ------------------------------------------------------------------ */

	public static function maybe_print_link_tag() {
		if ( ! is_singular() || ! Cmdroom_Ia_Settings::is_markdown_enabled() ) {
			return;
		}
		$opts = Cmdroom_Ia_Settings::get_options();
		if ( empty( $opts['markdown']['link_tag'] ) ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post || ! Cmdroom_Ia_Settings::is_markdown_post_type( $post->post_type ) ) {
			return;
		}
		printf( '<link rel="alternate" type="text/markdown" href="%s" />' . "\n", esc_url( self::markdown_url( $post ) ) );
	}

	/**
	 * URL .md de un post/página. Caso especial: la portada estática
	 * (page_on_front) -- get_permalink() le devuelve home_url('/') tal
	 * cual (ver _get_page_link() del core), así que untrailingslashit()
	 * se come la ÚNICA barra de la URL y "dominio.md" deja de ser una
	 * ruta del sitio, apunta a otro host -- bug real encontrado el
	 * 2026-09-25 al verificar el <link> en vivo. Aquí se sirve como
	 * "dominio/.md" en vez de "dominio.md", igual que ya espera
	 * maybe_serve_markdown() (quita ".md" y resuelve "/" como portada).
	 */
	public static function markdown_url( $post ) {
		$permalink = untrailingslashit( get_permalink( $post ) );
		$path      = wp_parse_url( $permalink, PHP_URL_PATH );
		if ( ! $path ) {
			return home_url( '/.md' );
		}
		return $permalink . '.md';
	}
}
