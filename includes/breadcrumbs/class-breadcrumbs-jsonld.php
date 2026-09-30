<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Módulo 19: imprime el BreadcrumbList JSON-LD en TODAS las páginas del
 * sitio (posts, términos, home, búsqueda, 404 — lo que cubra
 * Cmdroom_Breadcrumbs::get_current_items()), a diferencia del @graph
 * completo del módulo de Datos estructurados, que solo cubre post/término/
 * home. Gated por su propio toggle en Ajustes → Breadcrumbs, independiente
 * del toggle del @graph completo, porque puede querer activarse antes.
 *
 * El separador visual configurado en Breadcrumbs es solo eso — visual: no
 * participa en el JSON-LD, que usa "name"/"item" sin ningún carácter de
 * separación (confirmado revisando Cmdroom_Breadcrumbs::render_html(), que
 * es la única pieza que lo usa).
 *
 * Prioridad 3 desde el 2026-09-24 (antes 5, brevemente -99): el administrador pidió
 * que todos los bloques de datos estructurados salgan juntos y seguidos,
 * pero DESPUÉS del bloque de metas. Va justo detrás de
 * Cmdroom_Schema_Output::print_schema() (prioridad 2) para que los dos
 * <script type="application/ld+json"> queden seguidos entre sí, sin nada
 * intercalado.
 */
class Cmdroom_Breadcrumbs_Jsonld {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_jsonld' ), 3 );
	}

	private static function is_active() {
		return ! is_admin() && Cmdroom_Breadcrumb_Settings::is_jsonld_live_output_enabled();
	}

	public static function print_jsonld() {
		if ( ! self::is_active() ) {
			return;
		}

		$items = Cmdroom_Breadcrumbs::get_current_items();
		if ( ! $items ) {
			return;
		}

		$node = Cmdroom_Schema_Builder::breadcrumb_node( $items );

		$payload = array_merge( array( '@context' => 'https://schema.org' ), $node );

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
		);
	}
}
