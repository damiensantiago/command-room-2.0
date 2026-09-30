<?php
/**
 * Plantilla de las páginas públicas del Sitemap HTML -- ver
 * Cmdroom_Htmlmap_Frontend::template(). Cabecera y pie son los del tema.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Cmdroom_Htmlmap_Frontend::load_header();
Cmdroom_Htmlmap_Frontend::render_page();
Cmdroom_Htmlmap_Frontend::load_footer();
