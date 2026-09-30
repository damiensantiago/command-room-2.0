<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes del componente "Módulo de afiliación" -- vistas Grid comparativa y
 * Producto único (la Biblioteca vive en Cmdroom_Affiliate_Library, pero
 * comparte con esta clase la fila superior A, común a las tres vistas).
 *
 * "Ver la biblioteca no cambia el formato guardado; elegir Grid o Producto
 * único sí lo cambia" (handoff): por eso los dos primeros elementos del
 * segmentado "Formato" son enlaces a una acción admin-post que actualiza
 * `format` en cr_affiliate ANTES de redirigir a la vista -- "Biblioteca" es
 * un enlace normal a `&view=lib` que no toca esa opción.
 */
class Cmdroom_Affiliate_Settings {

	const VIEWS = array( 'grid', 'single', 'lib' );

	public static function init() {
		add_action( 'admin_post_cmdroom_affiliate_switch_format', array( __CLASS__, 'handle_switch_format' ) );
		add_action( 'admin_post_cmdroom_save_affiliate', array( __CLASS__, 'handle_save' ) );
	}

	public static function get_view() {
		$requested = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		if ( in_array( $requested, self::VIEWS, true ) ) {
			return $requested;
		}
		$opts = Cmdroom_Affiliate::get_options();
		return 'single' === $opts['format'] ? 'single' : 'grid';
	}

	public static function view_url( $view, $extra = array() ) {
		return add_query_arg( array_merge( array( 'view' => $view ), $extra ), Cmdroom_Components_Admin::tab_url( 'affiliate' ) );
	}

	/* — Cambiar formato (Grid/Único) — */

	public static function handle_switch_format() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
		if ( ! in_array( $format, array( 'grid', 'single' ), true ) ) {
			wp_die( esc_html__( 'Formato no válido.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_affiliate_switch_format_' . $format );

		$opts             = Cmdroom_Affiliate::get_options();
		$opts['format']   = $format;
		Cmdroom_Affiliate::update_options( $opts );

		wp_safe_redirect( self::view_url( $format ) );
		exit;
	}

	/* — Guardado de Productos/Textos/Enlaces — */

	private static function sanitize_asin( $raw ) {
		$asin = strtoupper( sanitize_text_field( wp_unslash( $raw ) ) );
		return preg_replace( '/[^A-Z0-9]/', '', $asin );
	}

	private static function sanitize_products_post() {
		$names  = isset( $_POST['p_name'] ) ? (array) wp_unslash( $_POST['p_name'] ) : array();
		$badges = isset( $_POST['p_badge'] ) ? (array) wp_unslash( $_POST['p_badge'] ) : array();
		$asins  = isset( $_POST['p_asin'] ) ? (array) $_POST['p_asin'] : array();
		$descs  = isset( $_POST['p_desc'] ) ? (array) wp_unslash( $_POST['p_desc'] ) : array();
		$longs  = isset( $_POST['p_long'] ) ? (array) wp_unslash( $_POST['p_long'] ) : array();
		$ratings = isset( $_POST['p_rating'] ) ? (array) $_POST['p_rating'] : array();
		$reviews = isset( $_POST['p_reviews'] ) ? (array) $_POST['p_reviews'] : array();

		$out = array();
		foreach ( $names as $i => $name ) {
			$name = sanitize_text_field( $name );
			$asin = isset( $asins[ $i ] ) ? self::sanitize_asin( $asins[ $i ] ) : '';
			if ( '' === $name && '' === $asin ) {
				continue; // fila totalmente vacía -- se descarta al guardar.
			}
			$rating  = isset( $ratings[ $i ] ) && '' !== trim( (string) $ratings[ $i ] ) ? max( 0, min( 5, (float) $ratings[ $i ] ) ) : null;
			$reviews_n = isset( $reviews[ $i ] ) && '' !== trim( (string) $reviews[ $i ] ) ? max( 0, (int) $reviews[ $i ] ) : null;
			$out[] = array(
				'name'    => $name,
				'badge'   => isset( $badges[ $i ] ) ? sanitize_text_field( $badges[ $i ] ) : '',
				'asin'    => $asin,
				'desc'    => isset( $descs[ $i ] ) ? sanitize_text_field( $descs[ $i ] ) : '',
				'long'    => isset( $longs[ $i ] ) ? sanitize_textarea_field( $longs[ $i ] ) : '',
				'rating'  => $rating,
				'reviews' => $reviews_n,
			);
		}
		// Máximo 3 productos (regla del handoff).
		return array_slice( $out, 0, 3 );
	}

	private static function sanitize_texts_post() {
		$defaults = Cmdroom_Affiliate::DEFAULTS['texts'];
		return array(
			'eyebrow'    => isset( $_POST['t_eyebrow'] ) ? sanitize_text_field( wp_unslash( $_POST['t_eyebrow'] ) ) : $defaults['eyebrow'],
			'title'      => isset( $_POST['t_title'] ) ? sanitize_text_field( wp_unslash( $_POST['t_title'] ) ) : $defaults['title'],
			'disclosure' => isset( $_POST['t_disclosure'] ) ? sanitize_text_field( wp_unslash( $_POST['t_disclosure'] ) ) : $defaults['disclosure'],
			'button'     => isset( $_POST['t_button'] ) ? sanitize_text_field( wp_unslash( $_POST['t_button'] ) ) : $defaults['button'],
			'foot'       => isset( $_POST['t_foot'] ) ? sanitize_text_field( wp_unslash( $_POST['t_foot'] ) ) : $defaults['foot'],
			'foot_link'  => isset( $_POST['t_foot_link'] ) ? sanitize_text_field( wp_unslash( $_POST['t_foot_link'] ) ) : $defaults['foot_link'],
			'foot_url'   => isset( $_POST['t_foot_url'] ) ? esc_url_raw( wp_unslash( $_POST['t_foot_url'] ) ) : $defaults['foot_url'],
		);
	}

	private static function sanitize_links_post() {
		$market = isset( $_POST['l_market'] ) ? sanitize_key( wp_unslash( $_POST['l_market'] ) ) : 'amazon.es';
		$tag    = isset( $_POST['l_tag'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['l_tag'] ) ) ) : '';
		$tag    = preg_replace( '/[^a-z0-9-]/', '', $tag );
		return array(
			'market'  => in_array( $market, Cmdroom_Affiliate::MARKETS, true ) ? $market : 'amazon.es',
			'tag'     => $tag,
			'new_tab' => ! empty( $_POST['l_new_tab'] ),
		);
	}

	private static function sanitize_placement_post() {
		$where = isset( $_POST['pl_where'] ) ? sanitize_key( wp_unslash( $_POST['pl_where'] ) ) : 'end';
		return array(
			'where' => in_array( $where, array( 'end', 'para', 'manual' ), true ) ? $where : 'end',
			'para'  => isset( $_POST['pl_para'] ) ? max( 1, (int) $_POST['pl_para'] ) : 4,
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_affiliate' );

		$view = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'grid';
		if ( ! in_array( $view, array( 'grid', 'single' ), true ) ) {
			$view = 'grid';
		}

		// 'enabled' NO se toca aquí -- vive fuera de este <form> (toggle
		// instantáneo por AJAX, ver Cmdroom_Affiliate::handle_toggle_enabled()),
		// así que leer $_POST['enabled'] siempre daría false y lo apagaría
		// en cada guardado de esta vista.
		$opts              = Cmdroom_Affiliate::get_options();
		$opts['format']    = $view;
		$opts['products']  = self::sanitize_products_post();
		$opts['featured']  = isset( $_POST['featured'] ) ? max( 0, min( 2, (int) $_POST['featured'] ) ) : 0;
		if ( ! isset( $opts['products'][ $opts['featured'] ] ) ) {
			$opts['featured'] = 0;
		}
		$opts['texts']     = self::sanitize_texts_post();
		$opts['links']     = self::sanitize_links_post();
		$opts['placement'] = self::sanitize_placement_post();

		Cmdroom_Affiliate::update_options( $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', self::view_url( $view ) ) );
		exit;
	}

	/* — Render — */

	/**
	 * Fila A: común a las tres vistas -- segmentado Formato a la izquierda,
	 * tarjeta con el toggle de activación a la derecha. La usa también
	 * Cmdroom_Affiliate_Library::render_view() para la vista Biblioteca.
	 */
	public static function render_top_row( $opts, $view ) {
		?>
		<div class="cmdroom-aff-top-row">
			<div>
				<span class="cr-label"><?php esc_html_e( 'Formato', 'command-room' ); ?></span>
				<div class="cr-seg cr-seg-lg cmdroom-aff-format-seg">
					<a
						class="cr-seg-opt<?php echo 'grid' === $view ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmdroom_affiliate_switch_format&format=grid' ), 'cmdroom_affiliate_switch_format_grid' ) ); ?>"
					><?php esc_html_e( 'Grid comparativa · hasta 3', 'command-room' ); ?></a>
					<a
						class="cr-seg-opt<?php echo 'single' === $view ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmdroom_affiliate_switch_format&format=single' ), 'cmdroom_affiliate_switch_format_single' ) ); ?>"
					><?php esc_html_e( 'Producto único', 'command-room' ); ?></a>
					<a
						class="cr-seg-opt<?php echo 'lib' === $view ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( self::view_url( 'lib' ) ); ?>"
					><?php esc_html_e( 'Biblioteca de productos', 'command-room' ); ?></a>
				</div>
			</div>
			<div
				class="cr-card cmdroom-aff-activation"
				data-cr-aff-toggle-enabled
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'cmdroom_affiliate_toggle_enabled' ) ); ?>"
			>
				<button type="button" class="cr-toggle<?php echo $opts['enabled'] ? ' is-on' : ''; ?>"><span class="cr-toggle-knob"></span><input type="checkbox" <?php checked( $opts['enabled'] ); ?> /></button>
				<div>
					<p class="cmdroom-aff-activation-title"><?php esc_html_e( 'Activar módulo en el sitio', 'command-room' ); ?></p>
					<p class="cmdroom-aff-activation-note" data-cr-aff-activation-note>
						<?php echo $opts['enabled']
							? esc_html__( 'Se imprime según la ubicación configurada.', 'command-room' )
							: esc_html__( 'Mientras esté apagado no se imprime nada (ni CSS ni JS).', 'command-room' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Marco de vista previa: reproduce el estilo del propio front-end (tema
	 * del sitio), NO los tokens Modernist del admin -- se logra encolando
	 * affiliate-frontend.css también en el admin (ver enqueue_assets en
	 * Cmdroom_Components_Admin) y reutilizando Cmdroom_Affiliate::render().
	 */
	private static function render_preview( $opts, $view ) {
		$count = count( $opts['products'] );
		$note  = 'single' === $view
			? sprintf( __( 'Producto %d · destacado', 'command-room' ), $opts['featured'] + 1 )
			: sprintf( __( '%1$d de 3 productos · CTA destacado', 'command-room' ), min( 3, $count ) );
		$format = 'single' === $view ? 'unico' : 'grid';
		$html   = Cmdroom_Affiliate::render( $format, $opts['products'], array(
			'texts'    => $opts['texts'],
			'links'    => $opts['links'],
			'featured' => $opts['featured'],
		) );
		?>
		<div class="cmdroom-aff-preview">
			<div class="cmdroom-aff-preview-head">
				<span class="cr-label"><?php esc_html_e( 'Vista previa', 'command-room' ); ?></span>
				<span class="cmdroom-aff-preview-note"><?php echo esc_html( $note ); ?></span>
			</div>
			<div class="cmdroom-aff-preview-frame">
				<div class="cmdroom-aff-preview-box" data-cr-aff-preview>
					<?php
					if ( '' !== $html ) {
						echo $html; // ya escapado dentro de Cmdroom_Affiliate::render().
					} else {
						echo '<p class="cmdroom-aff-preview-empty">' . esc_html__( 'Añade al menos un producto con nombre o ASIN para ver la vista previa.', 'command-room' ) . '</p>';
					}
					?>
				</div>
			</div>
			<p class="cmdroom-aff-preview-caption"><?php esc_html_e( 'El CTA destacado usa el color de acento del sitio.', 'command-room' ); ?></p>
		</div>
		<?php
	}

	private static function render_products_section( $opts, $view ) {
		$is_single = 'single' === $view;
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Productos', 'command-room' ); ?></span>
			<span class="cmdroom-aff-section-note">
				<?php echo $is_single
					? esc_html__( 'En producto único solo se muestra el destacado.', 'command-room' )
					: esc_html__( 'Máximo 3 para no canibalizar la conversión principal.', 'command-room' ); ?>
			</span>
		</div>
		<div class="cmdroom-aff-products">
			<?php foreach ( $opts['products'] as $i => $p ) :
				$is_featured = $i === $opts['featured'];
				$hidden_single = $is_single && ! $is_featured;
				?>
				<div class="cr-card cmdroom-aff-product<?php echo $hidden_single ? ' cmdroom-aff-product--dim' : ''; ?>">
					<div class="cmdroom-aff-product-top">
						<span class="cmdroom-aff-product-num">
							<?php printf( esc_html__( 'Producto %d', 'command-room' ), $i + 1 ); ?>
							<?php if ( $is_featured ) : ?><span class="cr-pill cmdroom-aff-pill-accent"><?php esc_html_e( 'CTA destacado', 'command-room' ); ?></span><?php endif; ?>
							<?php if ( $hidden_single ) : ?><span class="cr-pill cmdroom-aff-pill-muted"><?php esc_html_e( 'No se muestra en producto único', 'command-room' ); ?></span><?php endif; ?>
						</span>
						<span class="cmdroom-aff-product-actions">
							<?php if ( ! $is_featured ) : ?>
								<button type="button" class="cr-btn-secondary cr-btn-compact" data-cr-aff-feature="<?php echo esc_attr( $i ); ?>"><?php esc_html_e( 'Destacar', 'command-room' ); ?></button>
							<?php endif; ?>
							<button type="button" class="cr-btn-secondary cmdroom-aff-remove" data-cr-aff-remove-product>×</button>
						</span>
					</div>
					<input type="hidden" name="featured_candidate[]" value="<?php echo esc_attr( $i ); ?>" />
					<div class="cmdroom-aff-product-grid">
						<div>
							<label class="cr-label"><?php esc_html_e( 'Nombre', 'command-room' ); ?></label>
							<input type="text" class="cr-input" name="p_name[]" value="<?php echo esc_attr( $p['name'] ); ?>" />
						</div>
						<div>
							<label class="cr-label"><?php esc_html_e( 'Etiqueta', 'command-room' ); ?></label>
							<input type="text" class="cr-input" name="p_badge[]" value="<?php echo esc_attr( $p['badge'] ); ?>" placeholder="<?php esc_attr_e( 'Nuestra elección', 'command-room' ); ?>" />
						</div>
						<div>
							<label class="cr-label"><?php esc_html_e( 'ASIN', 'command-room' ); ?></label>
							<input type="text" class="cr-input cmdroom-aff-mono" name="p_asin[]" value="<?php echo esc_attr( $p['asin'] ); ?>" placeholder="B0XXXXXXXX" />
						</div>
					</div>
					<div>
						<label class="cr-label"><?php esc_html_e( 'Descripción corta · tarjeta', 'command-room' ); ?></label>
						<input type="text" class="cr-input" name="p_desc[]" value="<?php echo esc_attr( $p['desc'] ); ?>" />
					</div>
					<div>
						<label class="cr-label"><?php esc_html_e( 'Descripción completa · producto único', 'command-room' ); ?></label>
						<textarea class="cr-input" name="p_long[]" rows="3"><?php echo esc_textarea( $p['long'] ); ?></textarea>
						<?php if ( ! $is_featured ) : ?>
							<p class="description"><?php esc_html_e( 'Solo se usa si este producto pasa a ser el destacado.', 'command-room' ); ?></p>
						<?php endif; ?>
					</div>
					<div class="cmdroom-aff-product-grid cmdroom-aff-product-grid--ratings">
						<div>
							<label class="cr-label"><?php esc_html_e( 'Valoración (1-5)', 'command-room' ); ?></label>
							<input type="number" step="0.1" min="0" max="5" class="cr-input" name="p_rating[]" value="<?php echo esc_attr( null === $p['rating'] ? '' : $p['rating'] ); ?>" placeholder="—" />
						</div>
						<div>
							<label class="cr-label"><?php esc_html_e( 'Nº de opiniones', 'command-room' ); ?></label>
							<input type="number" step="1" min="0" class="cr-input" name="p_reviews[]" value="<?php echo esc_attr( null === $p['reviews'] ? '' : $p['reviews'] ); ?>" placeholder="—" />
						</div>
					</div>
					<p class="cmdroom-aff-link-line">
						<?php
						$domain = Cmdroom_Affiliate::market_domain( $opts['links']['market'] );
						printf(
							/* translators: 1: dominio, 2: ASIN, 3: tag */
							esc_html__( 'Enlace: https://%1$s/dp/%2$s?tag=%3$s · valoración y nº de opiniones se editan a mano hasta que haya credenciales de Amazon PA-API.', 'command-room' ),
							esc_html( $domain ),
							esc_html( $p['asin'] ? $p['asin'] : '{ASIN}' ),
							esc_html( $opts['links']['tag'] )
						);
						?>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
		<input type="hidden" name="featured" id="cmdroom-aff-featured-input" value="<?php echo esc_attr( $opts['featured'] ); ?>" />

		<?php if ( count( $opts['products'] ) < 3 ) :
			$library = Cmdroom_Affiliate::get_library();
			$used_asins = wp_list_pluck( $opts['products'], 'asin' );
			?>
			<div class="cmdroom-aff-add-row">
				<select class="cr-input" data-cr-aff-add-from-library>
					<option value=""><?php esc_html_e( '+ Añadir desde la biblioteca…', 'command-room' ); ?></option>
					<?php foreach ( $library as $lp ) :
						if ( in_array( $lp['asin'], $used_asins, true ) && '' !== $lp['asin'] ) {
							continue;
						}
						?>
						<option
							value="<?php echo esc_attr( $lp['asin'] ); ?>"
							data-name="<?php echo esc_attr( $lp['name'] ); ?>"
							data-badge="<?php echo esc_attr( $lp['badge'] ); ?>"
							data-desc="<?php echo esc_attr( $lp['desc'] ); ?>"
							data-long="<?php echo esc_attr( $lp['long'] ); ?>"
							data-rating="<?php echo esc_attr( null === $lp['rating'] ? '' : $lp['rating'] ); ?>"
							data-reviews="<?php echo esc_attr( null === $lp['reviews'] ? '' : $lp['reviews'] ); ?>"
						><?php echo esc_html( $lp['name'] ? $lp['name'] : $lp['asin'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="cr-btn-secondary cr-btn-compact" data-cr-aff-add-product><?php esc_html_e( '+ Producto nuevo', 'command-room' ); ?></button>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function render_texts_section( $opts ) {
		$t = $opts['texts'];
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Textos', 'command-room' ); ?></span>
		</div>
		<div class="cmdroom-aff-texts-grid">
			<div>
				<label class="cr-label"><?php esc_html_e( 'Antetítulo (mono)', 'command-room' ); ?></label>
				<input type="text" class="cr-input cmdroom-aff-mono" name="t_eyebrow" value="<?php echo esc_attr( $t['eyebrow'] ); ?>" />
			</div>
			<div>
				<label class="cr-label"><?php esc_html_e( 'Título', 'command-room' ); ?></label>
				<input type="text" class="cr-input" name="t_title" value="<?php echo esc_attr( $t['title'] ); ?>" />
			</div>
			<div>
				<label class="cr-label"><?php esc_html_e( 'Aviso de afiliación', 'command-room' ); ?></label>
				<input type="text" class="cr-input" name="t_disclosure" value="<?php echo esc_attr( $t['disclosure'] ); ?>" />
			</div>
			<div>
				<label class="cr-label"><?php esc_html_e( 'Texto del botón', 'command-room' ); ?></label>
				<input type="text" class="cr-input" name="t_button" value="<?php echo esc_attr( $t['button'] ); ?>" />
			</div>
			<div>
				<label class="cr-label"><?php esc_html_e( 'Nota final · solo grid', 'command-room' ); ?></label>
				<input type="text" class="cr-input" name="t_foot" value="<?php echo esc_attr( $t['foot'] ); ?>" />
			</div>
			<div class="cmdroom-aff-texts-2col">
				<div>
					<label class="cr-label"><?php esc_html_e( 'Texto del enlace', 'command-room' ); ?></label>
					<input type="text" class="cr-input" name="t_foot_link" value="<?php echo esc_attr( $t['foot_link'] ); ?>" />
				</div>
				<div>
					<label class="cr-label"><?php esc_html_e( 'URL', 'command-room' ); ?></label>
					<input type="text" class="cr-input" name="t_foot_url" value="<?php echo esc_attr( $t['foot_url'] ); ?>" />
				</div>
			</div>
		</div>
		<p class="cmdroom-aff-note"><?php esc_html_e( '%author% se resuelve con el autor de la entrada (refuerzo E-E-A-T). El título se puede sobrescribir en cada entrada.', 'command-room' ); ?></p>
		<?php
	}

	private static function render_links_section( $opts, $view ) {
		$l = $opts['links'];
		$pl = $opts['placement'];
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Enlaces y ubicación', 'command-room' ); ?></span>
		</div>
		<div class="cmdroom-aff-links-grid">
			<div>
				<span class="cr-label"><?php esc_html_e( 'Tienda', 'command-room' ); ?></span>
				<div class="cr-seg">
					<?php foreach ( Cmdroom_Affiliate::MARKETS as $market ) : ?>
						<button type="button" class="cr-seg-opt<?php echo $l['market'] === $market ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $market ); ?>"><?php echo esc_html( $market ); ?></button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="l_market" class="cr-seg-value" value="<?php echo esc_attr( $l['market'] ); ?>" />
			</div>
			<div>
				<label class="cr-label" for="cmdroom-aff-tag"><?php esc_html_e( 'ID de seguimiento (tag)', 'command-room' ); ?></label>
				<input type="text" id="cmdroom-aff-tag" class="cr-input cmdroom-aff-mono" name="l_tag" value="<?php echo esc_attr( $l['tag'] ); ?>" />
			</div>
			<div>
				<span class="cr-label"><?php esc_html_e( 'Atributo rel', 'command-room' ); ?></span>
				<div class="cmdroom-aff-rel-readonly"><?php echo esc_html( 'sponsored nofollow' ); ?></div>
			</div>
		</div>

		<div class="cmdroom-aff-placement-row">
			<div>
				<span class="cr-label"><?php esc_html_e( 'Dónde se inserta', 'command-room' ); ?></span>
				<div class="cr-seg">
					<?php
					$wheres = array( 'end' => __( 'Al final del artículo', 'command-room' ), 'para' => __( 'Tras un párrafo', 'command-room' ), 'manual' => __( 'Solo con shortcode', 'command-room' ) );
					foreach ( $wheres as $wkey => $wlabel ) :
						?>
						<button type="button" class="cr-seg-opt<?php echo $pl['where'] === $wkey ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $wkey ); ?>"><?php echo esc_html( $wlabel ); ?></button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="pl_where" class="cr-seg-value" value="<?php echo esc_attr( $pl['where'] ); ?>" />
			</div>
			<div class="cmdroom-aff-para-field" <?php echo 'para' === $pl['where'] ? '' : 'hidden'; ?> data-cr-aff-para-field>
				<label class="cr-label"><?php esc_html_e( 'Después del párrafo', 'command-room' ); ?></label>
				<input type="number" min="1" class="cr-input cmdroom-aff-para-input" name="pl_para" value="<?php echo esc_attr( $pl['para'] ); ?>" />
			</div>
		</div>

		<div class="cmdroom-ticker-row cmdroom-aff-newtab-row">
			<button type="button" class="cr-toggle<?php echo $l['new_tab'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="l_new_tab" value="1" <?php checked( $l['new_tab'] ); ?> /></button>
			<p class="cmdroom-ticker-row-title"><?php esc_html_e( 'Abrir enlaces en pestaña nueva', 'command-room' ); ?></p>
		</div>

		<div class="cmdroom-aff-shortcode-box">
			<label class="cr-label"><?php esc_html_e( 'Shortcode', 'command-room' ); ?></label>
			<code class="cmdroom-aff-shortcode-code">
				<?php echo 'single' === $view
					? esc_html( '[cmdroom_afiliacion formato="unico" producto="' . ( $opts['featured'] + 1 ) . '"]' )
					: esc_html( '[cmdroom_afiliacion formato="grid" productos="1,2,3"]' ); ?>
			</code>
		</div>
		<?php
	}

	public static function render_tab() {
		$view = self::get_view();

		if ( 'lib' === $view ) {
			Cmdroom_Affiliate_Library::render_view();
			return;
		}

		$opts = Cmdroom_Affiliate::get_options();
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cmdroom-aff-screen">
			<?php self::render_top_row( $opts, $view ); ?>
			<?php self::render_preview( $opts, $view ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cmdroom-aff-form" data-cr-aff-form>
				<?php wp_nonce_field( 'cmdroom_save_affiliate' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_affiliate" />
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>" />

				<?php self::render_products_section( $opts, $view ); ?>
				<?php self::render_texts_section( $opts ); ?>
				<?php self::render_links_section( $opts, $view ); ?>

				<?php submit_button( __( 'Guardar módulo de afiliación', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
			</form>
		</div>
		<?php
	}
}
