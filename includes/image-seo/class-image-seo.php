<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ejecuta las reglas de Auto-Image SEO. Desde el rediseño "Configuración"
 * (2026-09-23) hay tres puntos de aplicación, no solo el momento de subida:
 *  - `add_attachment`: escribe el alt en la biblioteca de medios (regla
 *    'upload'), como antes.
 *  - `wp_get_attachment_image_attributes`: rellena alt/title cuando el tema
 *    pinta una imagen con wp_get_attachment_image() (galerías, thumbnails,
 *    imágenes destacadas) aunque la imagen ya llevara tiempo en la
 *    biblioteca.
 *  - `the_content`: rellena alt/title de las etiquetas <img> sueltas dentro
 *    del contenido, vía WP_HTML_Tag_Processor.
 * En los tres casos, nunca se sobrescribe un alt/title que ya tenga valor.
 */
class Cmdroom_Image_Seo {

	public static function init() {
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'maybe_rename_file' ) );
		add_action( 'add_attachment', array( __CLASS__, 'maybe_save_alt_on_upload' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'filter_image_attributes' ), 10, 2 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content_images' ), 20 );
	}

	public static function maybe_rename_file( $file ) {
		if ( ! Cmdroom_Image_Seo_Settings::is_enabled( 'rename_file' ) ) {
			return $file;
		}

		// Solo imágenes — no tocar PDFs, docs, etc.
		if ( empty( $file['type'] ) || 0 !== strpos( $file['type'], 'image/' ) ) {
			return $file;
		}

		$post_id = self::guess_context_post_id();
		if ( ! $post_id ) {
			return $file; // sin post de contexto no hay slug fiable que usar
		}

		$post = get_post( $post_id );
		if ( ! $post || ! $post->post_name ) {
			return $file;
		}

		$ext = '';
		if ( isset( $file['name'] ) && false !== strrpos( $file['name'], '.' ) ) {
			$ext = strtolower( substr( $file['name'], strrpos( $file['name'], '.' ) ) );
		}

		// wp_unique_filename() añade -1, -2... automáticamente si ya existe
		// un archivo con ese nombre en el mes/año de subida — así se cumple
		// el patrón {post-slug}-{n} sin tener que calcular el contador aquí.
		$file['name'] = sanitize_file_name( $post->post_name . $ext );

		return $file;
	}

	public static function maybe_save_alt_on_upload( $attachment_id ) {
		if ( ! Cmdroom_Image_Seo_Settings::is_enabled( 'upload' ) || ! Cmdroom_Image_Seo_Settings::is_enabled( 'alt' ) ) {
			return;
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
			return;
		}
		if ( '' !== get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
			return; // ya tiene alt -- nunca se sobrescribe
		}

		$alt = self::resolve_alt( $attachment_id );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
	}

	public static function filter_image_attributes( $attr, $attachment ) {
		if ( Cmdroom_Image_Seo_Settings::is_enabled( 'alt' ) && empty( $attr['alt'] ) ) {
			$alt = self::resolve_alt( $attachment->ID );
			if ( '' !== $alt ) {
				$attr['alt'] = $alt;
			}
		}
		if ( Cmdroom_Image_Seo_Settings::is_enabled( 'title' ) && empty( $attr['title'] ) ) {
			$title = self::resolve_title( $attachment->ID );
			if ( '' !== $title ) {
				$attr['title'] = $title;
			}
		}
		return $attr;
	}

	public static function filter_content_images( $content ) {
		$do_alt   = Cmdroom_Image_Seo_Settings::is_enabled( 'alt' );
		$do_title = Cmdroom_Image_Seo_Settings::is_enabled( 'title' );
		if ( ( ! $do_alt && ! $do_title ) || false === strpos( $content, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}

		$p = new WP_HTML_Tag_Processor( $content );
		while ( $p->next_tag( 'img' ) ) {
			$src = $p->get_attribute( 'src' );
			if ( ! $src ) {
				continue;
			}
			$attachment_id = attachment_url_to_postid( $src );
			if ( ! $attachment_id ) {
				continue;
			}

			if ( $do_alt && ! $p->get_attribute( 'alt' ) ) {
				$alt = self::resolve_alt( $attachment_id );
				if ( '' !== $alt ) {
					$p->set_attribute( 'alt', $alt );
				}
			}
			if ( $do_title && ! $p->get_attribute( 'title' ) ) {
				$title = self::resolve_title( $attachment_id );
				if ( '' !== $title ) {
					$p->set_attribute( 'title', $title );
				}
			}
		}

		return $p->get_updated_html();
	}

	public static function resolve_alt( $attachment_id ) {
		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== $existing ) {
			return $existing;
		}
		$opts = Cmdroom_Image_Seo_Settings::get_options();
		return self::replace_vars( $opts['alt_tpl'], $attachment_id );
	}

	public static function resolve_title( $attachment_id ) {
		$opts = Cmdroom_Image_Seo_Settings::get_options();
		return self::replace_vars( $opts['title_tpl'], $attachment_id );
	}

	public static function replace_vars( $template, $attachment_id ) {
		return strtr( $template, array(
			'%image_name%' => self::image_name_var( $attachment_id ),
			'%title%'      => self::title_var( $attachment_id ),
			'%sitename%'   => get_bloginfo( 'name' ),
		) );
	}

	public static function image_name_var( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		$name = $file ? basename( $file ) : get_the_title( $attachment_id );
		$name = preg_replace( '/\.[^.]+$/', '', $name );

		if ( Cmdroom_Image_Seo_Settings::is_enabled( 'clean' ) ) {
			$name = preg_replace( '/-scaled$/', '', $name );
			$name = preg_replace( '/-\d+x\d+$/', '', $name );
			$name = preg_replace( '/-\d+$/', '', $name );
		}

		$name = str_replace( array( '-', '_' ), ' ', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		return $name ? ucfirst( $name ) : '';
	}

	private static function title_var( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return '';
		}
		if ( $attachment->post_parent ) {
			$parent = get_post( $attachment->post_parent );
			if ( $parent && $parent->post_title ) {
				return $parent->post_title;
			}
		}
		return $attachment->post_title ? $attachment->post_title : self::image_name_var( $attachment_id );
	}

	/**
	 * En qué post se está subiendo esta imagen — cubre el flujo típico del
	 * editor de bloques ($_REQUEST['post_id']) y el uploader clásico de
	 * medios dentro de un post ($_REQUEST['post_id'] también). Si la subida
	 * viene de Medios → Añadir nuevo (sin post asociado), devuelve 0.
	 */
	private static function guess_context_post_id() {
		if ( ! empty( $_REQUEST['post_id'] ) ) {
			return absint( $_REQUEST['post_id'] );
		}
		return 0;
	}
}
