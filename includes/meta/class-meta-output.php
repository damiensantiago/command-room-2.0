<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imprime en wp_head el bloque de <head> editable ("head_html") calculado
 * por Cmdroom_Meta_Resolver -- pero SOLO si "Salida en el sitio" está
 * activado en Ajustes. Apagado por defecto para poder convivir con Rank
 * Math mientras se verifica cada plantilla, sin duplicar metas en las
 * páginas de dev.
 *
 * Desde 0.11.0 el bloque de <head> de cada elemento (Ajustes → Metas) ya
 * cubre TODO -- title, description, keywords, robots, canonical y los
 * bloques Open Graph/Twitter completos, usando %url%/%robots%/%image%/
 * %keywords% -- así que esta clase deja de calcular e imprimir nada de eso
 * por su cuenta. Antes de 0.11.0 canonical/robots/OG vivían aquí con su
 * propia lógica dinámica (noindex de términos vacíos, paginación, etc.,
 * módulo 18); ese cálculo no ha desaparecido, se ha movido a
 * Cmdroom_Meta_Resolver::resolve_robots_for_context()/
 * resolve_canonical_for_context(), que alimentan tanto el head_html (vía
 * %robots%/%url%) como el resto del plugin.
 */
class Cmdroom_Meta_Output {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'maybe_unhook_native_tags' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'start_core_style_capture' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'end_core_style_capture' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'print_meta_tags' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'silence_native_robots' ), 9999 );
	}

	private static function is_active() {
		return ! is_admin() && Cmdroom_Meta_Settings::is_live_output_enabled();
	}

	/**
	 * WordPress core imprime un <style> propio en wp_head (desde 6.7.1,
	 * wp_print_auto_sizes_contain_css_fix() en wp-includes/media.php --
	 * ver docblock de end_core_style_capture()) con indentación fija que no
	 * es segura de tocar en el archivo core -- se perdería en el próximo
	 * update de WordPress. El administrador pidió el 2026-09-24 que el <head> saliera
	 * alineado a la izquierda, incluida esa línea y la que viene justo
	 * después (el bloque de metas de Command Room, que heredaba el tab
	 * colgante que deja esa función core tras su propio cierre). Se resuelve
	 * capturando SOLO la salida de esa función concreta en un buffer y
	 * reimprimiéndola ya recortada -- nunca tocamos wp-includes.
	 *
	 * Coincide a propósito con la prioridad 1 de esa función core
	 * (wp-includes/default-filters.php) -- como ese archivo se carga en el
	 * arranque de WordPress, siempre se registra antes que cualquier
	 * callback de un plugin, así que dentro del mismo cubo de prioridad "1"
	 * ella corre primero y end_core_style_capture() (registrada después, en
	 * este mismo init()) la captura justo a continuación, antes de que
	 * print_meta_tags() imprima lo nuestro.
	 */
	public static function start_core_style_capture() {
		if ( ! self::is_active() ) {
			return;
		}
		ob_start();
	}

	/**
	 * Recorta la salida capturada (espacios/tabs al principio y al final) y
	 * la reimprime en una sola línea limpia -- si el filtro
	 * `wp_img_tag_add_auto_sizes` está desactivado, wp_print_auto_sizes_contain_css_fix()
	 * no imprime nada y aquí simplemente no hay nada que reimprimir.
	 */
	public static function end_core_style_capture() {
		if ( ! self::is_active() ) {
			return;
		}
		$buffered = trim( ob_get_clean() );
		if ( '' !== $buffered ) {
			echo $buffered . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- es la salida nativa de WordPress core, solo se le quita la indentación
		}
	}

	/**
	 * El bloque de <head> editable trae su propio <title> (y, desde
	 * 0.11.0, su propio <link rel="canonical">) con formato arbitrario, así
	 * que hay que quitar los que imprime WordPress nativamente para no
	 * duplicar:
	 *
	 *  - _wp_render_title_tag(): el <title>, colgado en wp_head con
	 *    prioridad 1 (solo en temas con soporte "title-tag" -- el estándar
	 *    moderno). Un tema que imprima <title> a mano en header.php seguiría
	 *    duplicando -- no se resuelve aquí.
	 *  - rel_canonical(): el <link rel="canonical">, colgado en wp_head con
	 *    prioridad 10. Ya duplicaba contra el canonical que imprimía esta
	 *    clase antes de 0.10.0; sigue existiendo el mismo duplicado ahora
	 *    que canonical vive dentro del head_html (si el administrador lo pone, que es
	 *    lo que traen los defaults desde 0.11.0).
	 *  - wp_robots(): WordPress 5.7+ imprime su propio <meta name="robots">
	 *    nativo (por defecto solo trae `max-image-preview:large`) a través
	 *    del filtro `wp_robots` -- nada que ver con Rank Math, sigue ahí
	 *    aunque Rank Math esté desactivado. Detectado el 2026-09-18: salía
	 *    duplicado junto al robots real del head_html. Se filtra a vacío en
	 *    vez de con remove_action porque wp_robots() no cuelga como acción
	 *    fija de core -- varios plugins añaden sus propios filtros al mismo
	 *    array y quitar la acción entera se cargaría también esos.
	 */
	public static function maybe_unhook_native_tags() {
		if ( ! self::is_active() ) {
			return;
		}
		remove_action( 'wp_head', '_wp_render_title_tag', 1 );
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Vacía el array de robots nativo de WordPress core (filtro `wp_robots`)
	 * para que `wp_robots()` no imprima nada -- el robots real ya viene
	 * dentro del head_html. Hookeado en init() con prioridad muy alta para
	 * ejecutarse después de cualquier otro filtro (Site Kit, etc.) y dejar
	 * el array realmente vacío.
	 */
	public static function silence_native_robots( $robots ) {
		if ( ! self::is_active() ) {
			return $robots;
		}
		return array();
	}

	public static function print_meta_tags() {
		if ( ! self::is_active() ) {
			return;
		}

		$data = self::resolve_current();
		if ( ! $data ) {
			return;
		}

		if ( ! empty( $data['head_html'] ) ) {
			// Bloque de admin de confianza -- se imprime tal cual, sin
			// esc_html(), mismo criterio que el módulo de inyección de
			// código (includes/code-injection/class-code-injection.php):
			// permite <title>/<meta>/OG/Twitter con formato arbitrario. Las
			// variables que trae dentro ya salieron escapadas de
			// Cmdroom_Meta_Variables::replace() con $escape = true -- ver
			// Cmdroom_Meta_Resolver::resolve_for_*().
			echo $data['head_html'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- intencional, ver docblock de la clase
			return;
		}

		// Red de seguridad: si el bloque se deja vacío (plantilla borrada
		// por error, o un contexto sin plantilla propia como los archivos
		// de fecha), no dejamos la página sin <title> -- pero ya no se
		// reconstruye aquí todo el bloque viejo de canonical/robots/OG,
		// sería redundante con lo que el bloque por defecto de cada
		// elemento ya trae de fábrica (Cmdroom_Meta_Settings::defaults()).
		if ( ! empty( $data['title'] ) ) {
			printf( '<title>%s</title>' . "\n", esc_html( $data['title'] ) );
		}
	}

	private static function resolve_current() {
		// is_front_page() va ANTES que is_singular(): una portada estática
		// (show_on_front = 'page') es a la vez is_singular() Y is_front_page()
		// -- si se comprobara is_singular() primero, la portada resolvería
		// como una página normal (plantilla de "Páginas corporativas") en vez
		// de como "Home", que es justo el caso que se rompía antes de esto.
		// La página de entradas (is_home() sin ser portada, p. ej.
		// /blog/) se trata como la página que es, no como la home: si no,
		// heredaba título, descripción y canonical de la portada.
		if ( is_home() && ! is_front_page() && get_option( 'page_for_posts' ) ) {
			return Cmdroom_Meta_Resolver::resolve_for_post( (int) get_option( 'page_for_posts' ) );
		}
		if ( is_front_page() || is_home() ) {
			return Cmdroom_Meta_Resolver::resolve_for_home();
		}

		if ( is_singular() ) {
			return Cmdroom_Meta_Resolver::resolve_for_post( get_queried_object_id() );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			return Cmdroom_Meta_Resolver::resolve_for_term( get_queried_object() );
		}

		if ( is_author() ) {
			return Cmdroom_Meta_Resolver::resolve_for_author( get_queried_object() );
		}

		if ( is_date() ) {
			return self::resolve_for_generic_archive( array( 'is_date' => true ) );
		}

		// Hasta el rediseño "Configuración" (2026-09-23) is_search() caía
		// aquí sin plantilla ni robots propios -- con "Salida en el sitio"
		// activado, maybe_unhook_native_tags() ya había quitado el <title>
		// nativo y esta función devolvía null, así que la página de
		// resultados se quedaba sin <title> en absoluto. Usa el mismo
		// fallback mínimo que los archivos de fecha.
		if ( is_search() ) {
			return self::resolve_for_generic_archive( array( 'is_search' => true ) );
		}

		return null;
	}

	/**
	 * Paquete de metas mínimo para archivos de fecha -- no tienen plantilla
	 * propia en el módulo de Metas, así que no hay 'head_html' y
	 * print_meta_tags() cae siempre en el fallback de solo-título. El
	 * robots sigue calculándose con la misma regla centralizada del módulo
	 * 18 (noindex_date) por si algún día se decide darle plantilla propia.
	 */
	private static function resolve_for_generic_archive( $context = array() ) {
		if ( ! empty( $context['is_search'] ) ) {
			/* translators: %s: search query */
			$title     = sprintf( __( 'Resultados de búsqueda para: %s', 'command-room' ), get_search_query() );
			$canonical = home_url( '/?s=' . urlencode( get_search_query() ) );
		} else {
			$title = wp_strip_all_tags( get_the_archive_title() );
			global $wp;
			$canonical = home_url( add_query_arg( array(), $wp->request ) );
		}
		$robots = Cmdroom_Meta_Resolver::resolve_robots_for_context( $context );

		return array(
			'title'       => $title,
			'description' => '',
			'head_html'   => '',
			'canonical'   => $canonical,
			'noindex'     => $robots['noindex'],
			'nofollow'    => $robots['nofollow'],
			'og_type'     => 'website',
			'og_title'    => $title,
			'og_desc'     => '',
			'og_image'    => '',
		);
	}
}
