<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Override manual por post: título, descripción, canonical y robots.
 * Si se deja en blanco, gana la plantilla del tipo de contenido.
 */
class Cmdroom_Meta_Metabox {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ) );
	}

	public static function register() {
		foreach ( Cmdroom_Meta_Settings::public_post_types() as $pt ) {
			add_meta_box(
				'cmdroom-meta',
				__( 'Command Room', 'command-room' ),
				array( __CLASS__, 'render' ),
				$pt->name,
				'side',
				'default'
			);
		}
	}

	public static function render( $post ) {
		wp_nonce_field( 'cmdroom_save_meta', 'cmdroom_meta_nonce' );

		$title     = get_post_meta( $post->ID, '_cmdroom_title', true );
		$desc      = get_post_meta( $post->ID, '_cmdroom_description', true );
		$canonical = get_post_meta( $post->ID, '_cmdroom_canonical', true );
		$noindex   = (bool) get_post_meta( $post->ID, '_cmdroom_noindex', true );
		$nofollow  = (bool) get_post_meta( $post->ID, '_cmdroom_nofollow', true );

		// El placeholder muestra el título/extracto REAL del post (lo que
		// alimentaría Open Graph si se deja en blanco) -- ya no depende del
		// bloque de <head> editable del tipo de contenido, que desde 0.10.0
		// vive como un único textarea en Ajustes → Metas.
		$vars = Cmdroom_Meta_Variables::get_vars( array( 'post' => $post ) );
		?>
		<p>
			<label for="cmdroom_title"><strong><?php esc_html_e( 'Título SEO', 'command-room' ); ?></strong></label><br />
			<input type="text" id="cmdroom_title" name="cmdroom_title" value="<?php echo esc_attr( $title ); ?>" class="widefat" placeholder="<?php echo esc_attr( isset( $vars['title'] ) ? $vars['title'] : '' ); ?>" />
		</p>
		<p>
			<label for="cmdroom_description"><strong><?php esc_html_e( 'Meta descripción', 'command-room' ); ?></strong></label><br />
			<textarea id="cmdroom_description" name="cmdroom_description" class="widefat" rows="3" placeholder="<?php echo esc_attr( isset( $vars['excerpt'] ) ? $vars['excerpt'] : '' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
		</p>
		<p class="description"><?php esc_html_e( 'Desde 0.11.0: si rellenas esto, %title%/%excerpt% dentro del bloque de <head> de ESTE post (Ajustes → Metas) usan este override en vez del título/extracto real -- el resto del bloque (keywords, robots, canonical, OG, Twitter) sigue viniendo de la plantilla del tipo de contenido.', 'command-room' ); ?></p>
		<p>
			<label for="cmdroom_canonical"><strong><?php esc_html_e( 'URL canónica', 'command-room' ); ?></strong></label><br />
			<input type="text" id="cmdroom_canonical" name="cmdroom_canonical" value="<?php echo esc_attr( $canonical ); ?>" class="widefat" placeholder="<?php echo esc_attr( get_permalink( $post ) ); ?>" />
		</p>
		<p>
			<label><input type="checkbox" name="cmdroom_noindex" value="1" <?php checked( $noindex ); ?> /> <?php esc_html_e( 'noindex', 'command-room' ); ?></label><br />
			<label><input type="checkbox" name="cmdroom_nofollow" value="1" <?php checked( $nofollow ); ?> /> <?php esc_html_e( 'nofollow', 'command-room' ); ?></label>
		</p>
		<?php
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['cmdroom_meta_nonce'] ) || ! wp_verify_nonce( $_POST['cmdroom_meta_nonce'], 'cmdroom_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::save_field( $post_id, '_cmdroom_title', isset( $_POST['cmdroom_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cmdroom_title'] ) ) : '' );
		self::save_field( $post_id, '_cmdroom_description', isset( $_POST['cmdroom_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cmdroom_description'] ) ) : '' );
		self::save_field( $post_id, '_cmdroom_canonical', isset( $_POST['cmdroom_canonical'] ) ? esc_url_raw( wp_unslash( $_POST['cmdroom_canonical'] ) ) : '' );
		update_post_meta( $post_id, '_cmdroom_noindex', ! empty( $_POST['cmdroom_noindex'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_cmdroom_nofollow', ! empty( $_POST['cmdroom_nofollow'] ) ? 1 : 0 );
	}

	private static function save_field( $post_id, $key, $value ) {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}
