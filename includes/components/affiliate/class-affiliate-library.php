<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vista "Biblioteca de productos" (view=lib) del Módulo de afiliación:
 * catálogo reutilizable de productos + "Recomendaciones automáticas por
 * categoría". Comparte la fila superior A con Cmdroom_Affiliate_Settings
 * (Cmdroom_Affiliate_Settings::render_top_row()) -- "ver la biblioteca no
 * cambia el formato guardado" (handoff), así que este enlace nunca toca
 * cr_affiliate.format, solo cr_affiliate_library / cr_affiliate_rules.
 *
 * Las categorías por fila se guardan como una sola lista de IDs separada por
 * comas en un input oculto (`lib_cats[]`, uno por fila) en vez de un array
 * anidado por índice de fila -- así añadir/quitar filas en el cliente sigue
 * el mismo patrón de "arrays paralelos" que ya usa Ticker con
 * msg_text[]/msg_url[] y Ajustes con p_name[]/p_asin[], sin tener que
 * renumerar nombres de campo anidados en JS.
 */
class Cmdroom_Affiliate_Library {

	public static function init() {
		add_action( 'admin_post_cmdroom_save_affiliate_library', array( __CLASS__, 'handle_save' ) );
	}

	/* — Guardado — */

	private static function sanitize_asin( $raw ) {
		$asin = strtoupper( sanitize_text_field( wp_unslash( $raw ) ) );
		return preg_replace( '/[^A-Z0-9]/', '', $asin );
	}

	private static function sanitize_library_post() {
		$ids    = isset( $_POST['lib_id'] ) ? (array) wp_unslash( $_POST['lib_id'] ) : array();
		$names  = isset( $_POST['lib_name'] ) ? (array) wp_unslash( $_POST['lib_name'] ) : array();
		$badges = isset( $_POST['lib_badge'] ) ? (array) wp_unslash( $_POST['lib_badge'] ) : array();
		$asins  = isset( $_POST['lib_asin'] ) ? (array) $_POST['lib_asin'] : array();
		$descs  = isset( $_POST['lib_desc'] ) ? (array) wp_unslash( $_POST['lib_desc'] ) : array();
		$longs  = isset( $_POST['lib_long'] ) ? (array) wp_unslash( $_POST['lib_long'] ) : array();
		$ratings  = isset( $_POST['lib_rating'] ) ? (array) $_POST['lib_rating'] : array();
		$reviews  = isset( $_POST['lib_reviews'] ) ? (array) $_POST['lib_reviews'] : array();
		$cats_raw = isset( $_POST['lib_cats'] ) ? (array) wp_unslash( $_POST['lib_cats'] ) : array();

		$out = array();
		foreach ( $names as $i => $name ) {
			$name = sanitize_text_field( $name );
			$asin = isset( $asins[ $i ] ) ? self::sanitize_asin( $asins[ $i ] ) : '';
			if ( '' === $name && '' === $asin ) {
				continue; // fila vacía descartada al guardar.
			}
			$id = isset( $ids[ $i ] ) ? sanitize_key( $ids[ $i ] ) : '';
			if ( '' === $id ) {
				$id = 'aff' . substr( md5( microtime( true ) . wp_rand() . $name ), 0, 10 );
			}
			$cats = isset( $cats_raw[ $i ] ) ? array_filter( array_map( 'intval', explode( ',', $cats_raw[ $i ] ) ) ) : array();
			$rating  = isset( $ratings[ $i ] ) && '' !== trim( (string) $ratings[ $i ] ) ? max( 0, min( 5, (float) $ratings[ $i ] ) ) : null;
			$reviews_n = isset( $reviews[ $i ] ) && '' !== trim( (string) $reviews[ $i ] ) ? max( 0, (int) $reviews[ $i ] ) : null;
			$out[] = array(
				'id'      => $id,
				'name'    => $name,
				'badge'   => isset( $badges[ $i ] ) ? sanitize_text_field( $badges[ $i ] ) : '',
				'asin'    => $asin,
				'desc'    => isset( $descs[ $i ] ) ? sanitize_text_field( $descs[ $i ] ) : '',
				'long'    => isset( $longs[ $i ] ) ? sanitize_textarea_field( $longs[ $i ] ) : '',
				'rating'  => $rating,
				'reviews' => $reviews_n,
				'cats'    => array_values( $cats ),
			);
		}
		return $out;
	}

	private static function sanitize_rules_post() {
		$on     = isset( $_POST['rule_on'] ) ? (array) wp_unslash( $_POST['rule_on'] ) : array();
		$format = isset( $_POST['rule_format'] ) ? (array) wp_unslash( $_POST['rule_format'] ) : array();
		$out    = array();
		foreach ( $format as $term_id => $fmt ) {
			$term_id = (int) $term_id;
			if ( ! $term_id ) {
				continue;
			}
			$fmt = sanitize_key( $fmt );
			$out[ $term_id ] = array(
				'on'     => ! empty( $on[ $term_id ] ),
				'format' => in_array( $fmt, array( 'grid', 'unico', 'carrusel' ), true ) ? $fmt : 'grid',
			);
		}
		return $out;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_affiliate_library' );

		Cmdroom_Affiliate::update_library( self::sanitize_library_post() );
		Cmdroom_Affiliate::update_rules( self::sanitize_rules_post() );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', Cmdroom_Affiliate_Settings::view_url( 'lib' ) ) );
		exit;
	}

	/* — Uso: en cuántas entradas aparece un ASIN (calculado, no se guarda) — */

	private static function usage_count( $asin ) {
		static $cache = array();
		if ( '' === $asin ) {
			return 0;
		}
		if ( isset( $cache[ $asin ] ) ) {
			return $cache[ $asin ];
		}
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s",
			'%' . $wpdb->esc_like( $asin ) . '%'
		) );
		$cache[ $asin ] = $count;
		return $count;
	}

	/* — Render — */

	private static function render_row( $p, $wp_cats ) {
		$cats_selected = array_map( 'intval', $p['cats'] );
		$usage         = self::usage_count( $p['asin'] );
		?>
		<div class="cmdroom-aff-lib-row" data-cr-aff-lib-row data-search="<?php echo esc_attr( strtolower( $p['name'] . ' ' . $p['asin'] ) ); ?>">
			<div class="cmdroom-aff-lib-row-main">
				<div class="cmdroom-aff-lib-cell-product">
					<p class="cmdroom-aff-lib-name"><?php echo esc_html( $p['name'] ? $p['name'] : __( '(sin nombre)', 'command-room' ) ); ?></p>
					<p class="cmdroom-aff-lib-meta">
						<?php echo esc_html( $p['asin'] ? $p['asin'] : '—' ); ?>
						<?php if ( $p['rating'] ) : ?> · ★ <?php echo esc_html( number_format_i18n( (float) $p['rating'], 1 ) ); ?><?php if ( $p['reviews'] ) : ?> · <?php echo esc_html( number_format_i18n( (int) $p['reviews'] ) ); ?><?php endif; endif; ?>
					</p>
				</div>
				<div class="cmdroom-aff-lib-cell-cats">
					<?php if ( empty( $cats_selected ) ) : ?>
						<span class="cr-pill cmdroom-aff-pill-muted"><?php esc_html_e( 'Sin categoría', 'command-room' ); ?></span>
					<?php else : ?>
						<?php foreach ( $wp_cats as $c ) :
							if ( ! in_array( (int) $c->term_id, $cats_selected, true ) ) {
								continue;
							}
							?>
							<span class="cr-pill cmdroom-aff-pill-muted"><?php echo esc_html( $c->name ); ?></span>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
				<div class="cmdroom-aff-lib-cell-usage">
					<?php echo $usage ? esc_html( sprintf( _n( 'En %d entrada', 'En %d entradas', $usage, 'command-room' ), $usage ) ) : esc_html__( 'Sin usar', 'command-room' ); ?>
				</div>
				<div class="cmdroom-aff-lib-cell-actions">
					<button type="button" class="cr-btn-secondary cr-btn-compact" data-cr-aff-lib-edit-toggle><?php esc_html_e( 'Editar', 'command-room' ); ?></button>
					<button type="button" class="cr-btn-secondary cmdroom-aff-remove" data-cr-aff-lib-remove>×</button>
				</div>
			</div>
			<div class="cmdroom-aff-lib-edit" data-cr-aff-lib-edit hidden>
				<input type="hidden" name="lib_id[]" value="<?php echo esc_attr( $p['id'] ); ?>" />
				<div class="cmdroom-aff-product-grid">
					<div>
						<label class="cr-label"><?php esc_html_e( 'Nombre', 'command-room' ); ?></label>
						<input type="text" class="cr-input" name="lib_name[]" value="<?php echo esc_attr( $p['name'] ); ?>" data-cr-aff-lib-name-input />
					</div>
					<div>
						<label class="cr-label"><?php esc_html_e( 'Etiqueta', 'command-room' ); ?></label>
						<input type="text" class="cr-input" name="lib_badge[]" value="<?php echo esc_attr( $p['badge'] ); ?>" placeholder="<?php esc_attr_e( 'Nuestra elección', 'command-room' ); ?>" />
					</div>
					<div>
						<label class="cr-label"><?php esc_html_e( 'ASIN', 'command-room' ); ?></label>
						<input type="text" class="cr-input cmdroom-aff-mono" name="lib_asin[]" value="<?php echo esc_attr( $p['asin'] ); ?>" placeholder="B0XXXXXXXX" data-cr-aff-lib-asin-input />
					</div>
				</div>
				<div>
					<label class="cr-label"><?php esc_html_e( 'Descripción corta', 'command-room' ); ?></label>
					<input type="text" class="cr-input" name="lib_desc[]" value="<?php echo esc_attr( $p['desc'] ); ?>" />
				</div>
				<div>
					<label class="cr-label"><?php esc_html_e( 'Descripción completa', 'command-room' ); ?></label>
					<textarea class="cr-input" name="lib_long[]" rows="3"><?php echo esc_textarea( $p['long'] ); ?></textarea>
				</div>
				<div class="cmdroom-aff-product-grid cmdroom-aff-product-grid--ratings">
					<div>
						<label class="cr-label"><?php esc_html_e( 'Valoración (1-5)', 'command-room' ); ?></label>
						<input type="number" step="0.1" min="0" max="5" class="cr-input" name="lib_rating[]" value="<?php echo esc_attr( null === $p['rating'] ? '' : $p['rating'] ); ?>" placeholder="—" />
					</div>
					<div>
						<label class="cr-label"><?php esc_html_e( 'Nº de opiniones', 'command-room' ); ?></label>
						<input type="number" step="1" min="0" class="cr-input" name="lib_reviews[]" value="<?php echo esc_attr( null === $p['reviews'] ? '' : $p['reviews'] ); ?>" placeholder="—" />
					</div>
				</div>
				<div>
					<label class="cr-label"><?php esc_html_e( 'Categorías de la web', 'command-room' ); ?></label>
					<div class="cmdroom-aff-cat-picker" data-cr-aff-cat-picker>
						<?php foreach ( $wp_cats as $c ) :
							$active = in_array( (int) $c->term_id, $cats_selected, true );
							?>
							<button type="button" class="cr-pill cmdroom-aff-cat-btn<?php echo $active ? ' is-active' : ''; ?>" data-term="<?php echo esc_attr( $c->term_id ); ?>"><?php echo esc_html( $c->name ); ?></button>
						<?php endforeach; ?>
					</div>
					<input type="hidden" name="lib_cats[]" value="<?php echo esc_attr( implode( ',', $cats_selected ) ); ?>" data-cr-aff-cats-input />
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_library_section( $library, $wp_cats ) {
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php printf( esc_html__( 'Biblioteca de productos · %d productos', 'command-room' ), count( $library ) ); ?></span>
			<span class="cmdroom-aff-lib-tools">
				<input type="search" class="cr-input cmdroom-aff-lib-search" placeholder="<?php esc_attr_e( 'Buscar por nombre o ASIN', 'command-room' ); ?>" data-cr-aff-lib-search />
				<button type="button" class="cr-btn-secondary cr-btn-compact" data-cr-aff-lib-add><?php esc_html_e( '+ Añadir producto', 'command-room' ); ?></button>
			</span>
		</div>
		<p class="cmdroom-aff-note"><?php esc_html_e( 'Guarda aquí los productos que sueles recomendar:', 'command-room' ); ?></p>

		<div class="cr-card cmdroom-aff-lib-table">
			<div class="cmdroom-aff-lib-head">
				<span><?php esc_html_e( 'Producto', 'command-room' ); ?></span>
				<span><?php esc_html_e( 'Categorías de la web', 'command-room' ); ?></span>
				<span><?php esc_html_e( 'Uso', 'command-room' ); ?></span>
				<span></span>
			</div>
			<div data-cr-aff-lib-rows>
				<?php foreach ( $library as $p ) : ?>
					<?php self::render_row( $p, $wp_cats ); ?>
				<?php endforeach; ?>
			</div>
			<p class="cmdroom-aff-lib-empty" data-cr-aff-lib-empty <?php echo $library ? 'hidden' : ''; ?>><?php esc_html_e( 'Todavía no hay productos en la biblioteca.', 'command-room' ); ?></p>
		</div>
		<?php
	}

	private static function render_rules_section( $wp_cats, $rules, $library ) {
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Recomendaciones automáticas por categoría', 'command-room' ); ?></span>
		</div>
		<p class="cmdroom-aff-note"><?php esc_html_e( 'En cada entrada de la categoría se inserta un bloque con los productos de la biblioteca asignados a ella, salvo que la entrada ya tenga uno puesto a mano.', 'command-room' ); ?></p>

		<div class="cr-card cmdroom-aff-rules-list">
			<?php foreach ( $wp_cats as $c ) :
				$rule     = isset( $rules[ $c->term_id ] ) ? array_replace( array( 'on' => false, 'format' => 'grid' ), $rules[ $c->term_id ] ) : array( 'on' => false, 'format' => 'grid' );
				$assigned = count( array_filter( $library, function ( $p ) use ( $c ) {
					return ! empty( $p['cats'] ) && in_array( (int) $c->term_id, array_map( 'intval', $p['cats'] ), true );
				} ) );
				?>
				<div class="cmdroom-aff-rule-row<?php echo $rule['on'] ? '' : ' cmdroom-aff-rule-row--off'; ?>">
					<button type="button" class="cr-toggle<?php echo $rule['on'] ? ' is-on' : ''; ?>" data-cr-toggle data-cr-aff-rule-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="rule_on[<?php echo esc_attr( $c->term_id ); ?>]" value="1" <?php checked( $rule['on'] ); ?> /></button>
					<div class="cmdroom-aff-rule-body">
						<p class="cmdroom-aff-rule-name">
							<?php echo esc_html( $c->name ); ?>
							<span class="cmdroom-aff-rule-count" data-cr-aff-rule-count="<?php echo esc_attr( $c->term_id ); ?>"><?php printf( esc_html__( '%d productos asignados', 'command-room' ), $assigned ); ?></span>
						</p>
					</div>
					<div class="cr-seg cmdroom-aff-rule-format<?php echo $rule['on'] ? '' : ' cmdroom-aff-rule-format--disabled'; ?>">
						<?php
						$formats = array( 'grid' => __( 'Grid', 'command-room' ), 'unico' => __( 'Único', 'command-room' ), 'carrusel' => __( 'Carrusel', 'command-room' ) );
						foreach ( $formats as $fkey => $flabel ) :
							?>
							<button type="button" class="cr-seg-opt<?php echo $rule['format'] === $fkey ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $fkey ); ?>"><?php echo esc_html( $flabel ); ?></button>
						<?php endforeach; ?>
					</div>
					<input type="hidden" name="rule_format[<?php echo esc_attr( $c->term_id ); ?>]" class="cr-seg-value" value="<?php echo esc_attr( $rule['format'] ); ?>" />
				</div>
			<?php endforeach; ?>
		</div>
		<p class="cmdroom-aff-note"><?php esc_html_e( 'El grid y el producto único usan como máximo 3 y 1 productos; el carrusel muestra todos los de la categoría. El orden sigue el de la biblioteca.', 'command-room' ); ?></p>
		<?php
	}

	public static function render_view() {
		$opts    = Cmdroom_Affiliate::get_options();
		$library = Cmdroom_Affiliate::get_library();
		$rules   = Cmdroom_Affiliate::get_rules();
		$wp_cats = get_categories( array( 'hide_empty' => false ) );
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cmdroom-aff-screen">
			<?php Cmdroom_Affiliate_Settings::render_top_row( $opts, 'lib' ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cr-aff-lib-form>
				<?php wp_nonce_field( 'cmdroom_save_affiliate_library' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_affiliate_library" />

				<?php self::render_library_section( $library, $wp_cats ); ?>
				<?php self::render_rules_section( $wp_cats, $rules, $library ); ?>

				<?php submit_button( __( 'Guardar biblioteca', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
			</form>
		</div>
		<?php
	}
}
