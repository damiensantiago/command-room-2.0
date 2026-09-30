<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Construye el @graph de datos estructurados. Un único array de nodos por
 * página -- nunca dos <script type="application/ld+json"> sueltos -- para no
 * repetir el error de @type incorrecto dentro de @graph que se documentó en
 * una auditoría SEO real.
 *
 * Desde el rediseño de Claude Design de esta pantalla, cada nodo del @graph
 * -- incluidos Organization y WebSite, que antes se construían a mano en PHP
 * (organization_node()/website_node(), ya retirados) -- es uno de los
 * bloques JSON-LD editables de Cmdroom_Schema_Settings::get_group_blocks().
 * Cada página imprime SIEMPRE los bloques del grupo "general" (sitewide,
 * normalmente Organization) más los del grupo específico de esa página
 * (home/categorias/contenido/autor/corporativas/tags) -- nunca más de esos
 * dos grupos, y puede haber varios bloques dentro de cada uno (p. ej.
 * Contenido con Article + FAQPage a la vez).
 *
 * Si el JSON de un bloque no es válido -- typo al editar a mano -- NO se
 * rompe la página: se omite ese nodo y el resto del @graph sigue saliendo.
 */
class Cmdroom_Schema_Builder {

	/**
	 * Errores de JSON inválido (si los hubo) al resolver los nodos de la
	 * página actual -- lo usa la vista previa de Herramientas para avisar
	 * sin filtrar el error al frontend. String con un mensaje por línea si
	 * ha fallado más de un bloque.
	 */
	public static $last_error = '';

	public static function build_for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		self::$last_error = '';

		$group   = 'page' === $post->post_type ? 'corporativas' : 'contenido';
		$context = array( 'post' => $post );

		$graph = array_merge(
			self::resolve_group_nodes( 'general', $context ),
			self::resolve_group_nodes( $group, $context )
		);

		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	public static function build_for_term( $term ) {
		if ( ! ( $term instanceof WP_Term ) ) {
			return null;
		}

		self::$last_error = '';

		$group   = 'post_tag' === $term->taxonomy ? 'tags' : 'categorias';
		$context = array( 'term' => $term );

		$graph = array_merge(
			self::resolve_group_nodes( 'general', $context ),
			self::resolve_group_nodes( $group, $context )
		);

		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	public static function build_for_home() {
		self::$last_error = '';

		$context = array( 'is_home' => true );

		$graph = array_merge(
			self::resolve_group_nodes( 'general', $context ),
			self::resolve_group_nodes( 'home', $context )
		);

		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	public static function build_for_author( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return null;
		}

		self::$last_error = '';

		$context = array( 'author' => $user );

		$graph = array_merge(
			self::resolve_group_nodes( 'general', $context ),
			self::resolve_group_nodes( 'autor', $context )
		);

		return array( '@context' => 'https://schema.org', '@graph' => $graph );
	}

	/**
	 * Resuelve todos los bloques de un grupo con el mismo contexto (variables
	 * %schema_*%), omitiendo los que queden vacíos o no sean JSON válido.
	 */
	private static function resolve_group_nodes( $group, $context ) {
		$nodes = array();
		foreach ( Cmdroom_Schema_Settings::get_group_blocks( $group ) as $block ) {
			if ( empty( $block['json'] ) || '' === trim( (string) $block['json'] ) ) {
				continue;
			}
			$label = $group . ' → ' . ( ! empty( $block['type'] ) ? $block['type'] : '(sin tipo)' );
			$node  = self::resolve_node( $block['json'], $context, $label );
			if ( null !== $node ) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	/**
	 * Resuelve el bloque JSON editable de un nodo: sustituye variables
	 * %schema_*% (ya escapadas para JSON por Cmdroom_Schema_Variables) y
	 * decodifica. Si el JSON resultante no es válido, no rompe la página --
	 * se omite el nodo, se deja un error_log() y se acumula el motivo en
	 * self::$last_error para que la vista previa de Herramientas pueda
	 * avisar (nunca se imprime ese aviso en el frontend).
	 */
	private static function resolve_node( $template, $context, $label ) {
		$resolved = Cmdroom_Schema_Variables::replace( $template, $context );

		$node = json_decode( $resolved, true );

		if ( ! is_array( $node ) ) {
			$msg = sprintf(
				'El bloque de datos estructurados de %1$s no es JSON válido: %2$s',
				$label,
				json_last_error_msg()
			);
			self::$last_error = self::$last_error ? self::$last_error . "\n" . $msg : $msg;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- aviso intencional de plantilla de admin mal formada, no un error de programación.
			error_log( '[Command Room] ' . $msg );
			return null;
		}

		return array_filter( $node );
	}

	/**
	 * Pública porque el módulo 19 (breadcrumbs JSON-LD globales,
	 * includes/breadcrumbs/class-breadcrumbs-jsonld.php) la reutiliza para
	 * no duplicar la forma del nodo BreadcrumbList en dos sitios -- es una
	 * salida independiente del @graph de esta pantalla, con su propio
	 * toggle en Ajustes → Breadcrumbs. Si el administrador además añade el bloque
	 * "BreadcrumbList" de la Librería a un grupo de esta pantalla, puede
	 * salir un BreadcrumbList duplicado; no es nuevo de este rediseño -- ya
	 * podía pasar antes con el breadcrumb que se inyectaba siempre.
	 */
	public static function breadcrumb_node( $items ) {
		$list_items = array();
		foreach ( $items as $i => $item ) {
			$list_items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
		}

		$current_url = ! empty( $items ) ? end( $items )['url'] : home_url( '/' );

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $current_url . '#breadcrumb',
			'itemListElement' => $list_items,
		);
	}
}
