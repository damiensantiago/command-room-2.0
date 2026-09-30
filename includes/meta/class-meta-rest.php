<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expone los campos de meta en la REST API desde el primer día.
 * Rank Math no lo hace (rank_math_title/description/focus_keyword no
 * están en REST), y hace falta un snippet aparte para que una automatización
 * externa pueda escribir el SEO editorial.
 */
class Cmdroom_Meta_Rest {

	const FIELDS = array(
		'_cmdroom_title'       => 'string',
		'_cmdroom_description' => 'string',
		'_cmdroom_canonical'   => 'string',
		'_cmdroom_noindex'     => 'boolean',
		'_cmdroom_nofollow'    => 'boolean',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		foreach ( Cmdroom_Meta_Settings::public_post_types() as $pt ) {
			foreach ( self::FIELDS as $key => $type ) {
				register_post_meta(
					$pt->name,
					$key,
					array(
						'type'          => $type,
						'single'        => true,
						'show_in_rest'  => true,
						'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', $post_id );
						},
					)
				);
			}
		}
	}
}
