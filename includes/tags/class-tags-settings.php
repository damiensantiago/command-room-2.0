<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pestaña "Tags" de la pantalla "Configuración" — tres sub-pestañas
 * (pedidas por el administrador el 2026-09-23, sobre la v0.17.0 que solo tenía
 * límite + limpieza en una única vista):
 *  - Listado completo: todas las etiquetas con su nº de entradas, fusión
 *    de duplicadas (con redirección 301) y borrado de vacías.
 *  - Etiquetado masivo: añadir/quitar una o varias etiquetas a todos los
 *    posts que coincidan con un filtro (tipo de contenido, categoría,
 *    rango de fechas, búsqueda por título), sin tener que abrirlos uno
 *    a uno.
 *  - Configuración: el límite recomendado de etiquetas por post.
 *
 * Las plantillas de título/meta de las páginas de archivo de tags NO viven
 * aquí — ya existen en Metas → pestaña "Tags" (Cmdroom_Meta_Settings, grupo
 * 'tags') desde antes de esta pantalla; aquí solo se enlaza a ellas para no
 * duplicar el mismo ajuste en dos sitios.
 */
class Cmdroom_Tags_Settings {

	const OPTION = 'cmdroom_tags';

	const DEFAULTS = array(
		'max_per_post' => 0, // 0 = sin límite
	);

	const VIEWS = array(
		'listado' => 'listado',
		'masivo'  => 'masivo',
		'config'  => 'config',
	);

	public static function init() {
		add_action( 'admin_post_cmdroom_save_tags', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_cmdroom_tags_merge', array( __CLASS__, 'handle_merge' ) );
		add_action( 'wp_ajax_cmdroom_tags_delete_empty', array( __CLASS__, 'handle_delete_empty' ) );
		add_action( 'wp_ajax_cmdroom_tags_delete_one', array( __CLASS__, 'handle_delete_one' ) );
		add_action( 'wp_ajax_cmdroom_tags_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'wp_ajax_cmdroom_tags_save_meta', array( __CLASS__, 'handle_save_meta' ) );
		add_action( 'wp_ajax_cmdroom_tags_bulk_preview', array( __CLASS__, 'handle_bulk_preview' ) );
		add_action( 'wp_ajax_cmdroom_tags_bulk_apply', array( __CLASS__, 'handle_bulk_apply' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_over_limit' ) );
	}

	public static function get_options() {
		return wp_parse_args( get_option( self::OPTION, array() ), self::DEFAULTS );
	}

	public static function get_max_per_post() {
		$opts = self::get_options();
		return (int) $opts['max_per_post'];
	}

	public static function get_active_view() {
		$requested = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'listado';
		return isset( self::VIEWS[ $requested ] ) ? $requested : 'listado';
	}

	public static function view_url( $view ) {
		return add_query_arg( array( 'page' => 'cmdroom-config', 'tab' => 'tags', 'view' => $view ), admin_url( 'admin.php' ) );
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_tags' );

		update_option( self::OPTION, array(
			'max_per_post' => isset( $_POST['max_per_post'] ) ? max( 0, (int) $_POST['max_per_post'] ) : 0,
		) );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', wp_get_referer() ) );
		exit;
	}

	/**
	 * Aviso no bloqueante en el editor de entradas cuando el post supera el
	 * límite recomendado -- nunca recorta tags solo, la decisión de qué
	 * quitar es del administrador.
	 */
	public static function maybe_notice_over_limit() {
		$max = self::get_max_per_post();
		if ( 0 === $max ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || 'post' !== $screen->post_type ) {
			return;
		}
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $post_id ) {
			return;
		}
		$count = wp_count_terms( array( 'taxonomy' => 'post_tag', 'object_ids' => $post_id ) );
		if ( is_wp_error( $count ) || $count <= $max ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: número de etiquetas del post, 2: límite configurado */
				__( 'Este post tiene %1$d etiquetas — el límite recomendado en Command Room → Configuración → Tags es %2$d.', 'command-room' ),
				$count,
				$max
			) )
		);
	}

	private static function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para hacer esto.', 'command-room' ) ), 403 );
		}
		check_ajax_referer( 'cmdroom_tags_actions', 'nonce' );
	}

	/* ------------------------------------------------------------------ */
	/* Listado completo                                                  */
	/* ------------------------------------------------------------------ */

	public static function get_all_tags_with_counts() {
		$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'orderby' => 'count', 'order' => 'ASC' ) );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Reasigna todos los posts de $from a $to, borra $from y (si el módulo
	 * de Redirecciones está disponible) deja un 301 de la URL de archivo
	 * vieja a la nueva -- mismo criterio que cualquier fusión de contenido
	 * en el sitio: nunca dejar una URL indexada muerta sin redirigir.
	 */
	public static function handle_merge() {
		self::verify_ajax();

		$from_id = isset( $_POST['from'] ) ? (int) $_POST['from'] : 0;
		$to_id   = isset( $_POST['to'] ) ? (int) $_POST['to'] : 0;

		$from = get_term( $from_id, 'post_tag' );
		$to   = get_term( $to_id, 'post_tag' );

		if ( ! $from || is_wp_error( $from ) || ! $to || is_wp_error( $to ) || $from_id === $to_id ) {
			wp_send_json_error( array( 'message' => __( 'Etiquetas no válidas.', 'command-room' ) ), 400 );
		}

		$from_link = get_term_link( $from );
		$to_link   = get_term_link( $to );

		$posts = get_posts( array(
			'post_type'   => 'any',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			'tax_query'   => array( array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $from_id ) ),
		) );

		foreach ( $posts as $post_id ) {
			wp_set_post_terms( $post_id, array( $to_id ), 'post_tag', true );
		}

		wp_delete_term( $from_id, 'post_tag' );

		if ( class_exists( 'Cmdroom_Redirect_Table' ) && ! is_wp_error( $from_link ) && ! is_wp_error( $to_link ) ) {
			Cmdroom_Redirect_Table::insert( array(
				'source'        => wp_parse_url( $from_link, PHP_URL_PATH ),
				'source_type'   => 'exact',
				'destination'   => $to_link,
				'redirect_type' => 301,
				'status'        => 1,
				'hits'          => 0,
			) );
		}

		wp_send_json_success( array(
			'message'       => sprintf(
				/* translators: 1: nombre de la etiqueta fusionada, 2: nombre de la etiqueta destino, 3: nº de posts movidos */
				__( '"%1$s" fusionada en "%2$s" (%3$d posts movidos).', 'command-room' ),
				$from->name,
				$to->name,
				count( $posts )
			),
			'redirect_added' => class_exists( 'Cmdroom_Redirect_Table' ),
		) );
	}

	public static function handle_delete_empty() {
		self::verify_ajax();

		$terms = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false, 'number' => 0 ) );
		$count = 0;
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( 0 === (int) $term->count ) {
					wp_delete_term( $term->term_id, 'post_tag' );
					$count++;
				}
			}
		}

		wp_send_json_success( array(
			/* translators: %d: nº de etiquetas vacías eliminadas */
			'message' => sprintf( __( '%d etiquetas vacías eliminadas.', 'command-room' ), $count ),
			'count'   => $count,
		) );
	}

	public static function handle_delete_one() {
		self::verify_ajax();

		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$term    = get_term( $term_id, 'post_tag' );

		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'Etiqueta no válida.', 'command-room' ) ), 400 );
		}

		wp_delete_term( $term_id, 'post_tag' );

		wp_send_json_success( array(
			/* translators: %s: nombre de la etiqueta eliminada */
			'message' => sprintf( __( 'Etiqueta "%s" eliminada.', 'command-room' ), $term->name ),
		) );
	}

	/**
	 * Crear una etiqueta nueva directamente desde el Listado completo, sin
	 * pasar por el editor de una entrada -- pedido por el administrador 2026-09-25.
	 * El JS recarga la página al terminar (igual que las demás acciones de
	 * esta pestaña no reconstruyen filas a mano, salvo la fila que borran).
	 */
	public static function handle_create() {
		self::verify_ajax();

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Escribe un nombre para la etiqueta.', 'command-room' ) ), 400 );
		}

		$result = wp_insert_term( $name, 'post_tag' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array(
			/* translators: %s: nombre de la etiqueta creada */
			'message' => sprintf( __( 'Etiqueta "%s" creada.', 'command-room' ), $name ),
		) );
	}

	/**
	 * Override de título/meta descripción para UNA etiqueta concreta --
	 * pedido por el administrador 2026-09-25 ("poder aplicar lógicas de metas
	 * diferentes" por tag, no solo la plantilla global de Metas → Tags).
	 * Mismo mecanismo que el override por post (_cmdroom_title/
	 * _cmdroom_description), pero en term meta -- lo consume
	 * Cmdroom_Meta_Resolver::resolve_for_term(). Vacío = sin override, cae a
	 * la plantilla de la taxonomía como hasta ahora.
	 */
	public static function handle_save_meta() {
		self::verify_ajax();

		$term_id = isset( $_POST['term_id'] ) ? (int) $_POST['term_id'] : 0;
		$term    = get_term( $term_id, 'post_tag' );

		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'Etiqueta no válida.', 'command-room' ) ), 400 );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$desc  = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		self::save_term_meta_field( $term_id, '_cmdroom_title', $title );
		self::save_term_meta_field( $term_id, '_cmdroom_description', $desc );

		wp_send_json_success( array(
			'message'    => __( 'Metas de la etiqueta guardadas.', 'command-room' ),
			'has_custom' => ( '' !== $title || '' !== $desc ),
		) );
	}

	private static function save_term_meta_field( $term_id, $key, $value ) {
		if ( '' === $value ) {
			delete_term_meta( $term_id, $key );
		} else {
			update_term_meta( $term_id, $key, $value );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Etiquetado masivo                                                 */
	/* ------------------------------------------------------------------ */

	private static function sanitize_bulk_filters( $data ) {
		$public_types = array_keys( get_post_types( array( 'public' => true ), 'names' ) );
		$post_type    = isset( $data['post_type'] ) && in_array( $data['post_type'], $public_types, true ) ? $data['post_type'] : 'post';

		return array(
			'post_type' => $post_type,
			'category'  => isset( $data['category'] ) ? (int) $data['category'] : 0,
			'date_from' => isset( $data['date_from'] ) ? sanitize_text_field( $data['date_from'] ) : '',
			'date_to'   => isset( $data['date_to'] ) ? sanitize_text_field( $data['date_to'] ) : '',
			'search'    => isset( $data['search'] ) ? sanitize_text_field( $data['search'] ) : '',
		);
	}

	private static function bulk_query_args( $filters ) {
		$args = array(
			'post_type'      => $filters['post_type'],
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		);

		if ( $filters['category'] && is_object_in_taxonomy( $filters['post_type'], 'category' ) ) {
			$args['tax_query'] = array( array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $filters['category'] ) );
		}

		if ( '' !== $filters['search'] ) {
			$args['s'] = $filters['search'];
		}

		if ( '' !== $filters['date_from'] || '' !== $filters['date_to'] ) {
			$date_query = array( 'inclusive' => true );
			if ( '' !== $filters['date_from'] ) {
				$date_query['after'] = $filters['date_from'];
			}
			if ( '' !== $filters['date_to'] ) {
				$date_query['before'] = $filters['date_to'];
			}
			$args['date_query'] = array( $date_query );
		}

		return $args;
	}

	private static function parse_tag_list( $raw ) {
		$parts = explode( ',', (string) $raw );
		$out   = array();
		foreach ( $parts as $part ) {
			$name = trim( $part );
			if ( '' !== $name && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	public static function handle_bulk_preview() {
		self::verify_ajax();

		$filters = self::sanitize_bulk_filters( wp_unslash( $_POST ) );
		$query   = new WP_Query( self::bulk_query_args( $filters ) );

		wp_send_json_success( array( 'count' => (int) $query->found_posts ) );
	}

	public static function handle_bulk_apply() {
		self::verify_ajax();

		$filters     = self::sanitize_bulk_filters( wp_unslash( $_POST ) );
		$add_tags    = self::parse_tag_list( isset( $_POST['add_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['add_tags'] ) ) : '' );
		$remove_tags = self::parse_tag_list( isset( $_POST['remove_tags'] ) ? sanitize_text_field( wp_unslash( $_POST['remove_tags'] ) ) : '' );

		if ( ! $add_tags && ! $remove_tags ) {
			wp_send_json_error( array( 'message' => __( 'Indica al menos una etiqueta para añadir o quitar.', 'command-room' ) ), 400 );
		}

		$query    = new WP_Query( self::bulk_query_args( $filters ) );
		$post_ids = $query->posts;

		foreach ( $post_ids as $post_id ) {
			if ( $add_tags ) {
				wp_set_post_terms( $post_id, $add_tags, 'post_tag', true );
			}
			if ( $remove_tags ) {
				wp_remove_object_terms( $post_id, $remove_tags, 'post_tag' );
			}
		}

		wp_send_json_success( array(
			/* translators: %d: nº de posts afectados */
			'message' => sprintf( __( 'Aplicado a %d posts.', 'command-room' ), count( $post_ids ) ),
			'count'   => count( $post_ids ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                            */
	/* ------------------------------------------------------------------ */

	public static function render_tab() {
		$active_view = self::get_active_view();
		$labels      = array(
			'listado' => __( 'Listado completo', 'command-room' ),
			'masivo'  => __( 'Etiquetado masivo', 'command-room' ),
			'config'  => __( 'Configuración', 'command-room' ),
		);
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<nav class="cr-tabs cmdroom-tags-subtabs">
			<?php foreach ( $labels as $view => $label ) : ?>
				<a class="cr-tab<?php echo $active_view === $view ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::view_url( $view ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $active_view ) {
			case 'masivo':
				self::render_masivo_subtab();
				break;
			case 'config':
				self::render_config_subtab();
				break;
			default:
				self::render_listado_subtab();
				break;
		}
	}

	private static function render_listado_subtab() {
		$tags = self::get_all_tags_with_counts();
		?>
		<div class="cr-card cmdroom-ia-block" data-cr-tags-cleanup data-nonce="<?php echo esc_attr( wp_create_nonce( 'cmdroom_tags_actions' ) ); ?>">
			<div class="cmdroom-ia-block-head">
				<div>
					<h2 class="cmdroom-ia-block-title"><?php esc_html_e( 'Listado completo', 'command-room' ); ?></h2>
					<p class="cmdroom-config-rule-desc"><?php esc_html_e( 'Ordenadas de menos a más usadas. Fusiona duplicados, borra las que no tienen ninguna entrada, o configura un título y descripción propios para una en concreto.', 'command-room' ); ?></p>
				</div>
				<div class="cmdroom-tags-header-actions">
					<input type="text" class="cr-input" data-cr-tags-create-name placeholder="<?php esc_attr_e( 'Nombre de la etiqueta nueva', 'command-room' ); ?>" />
					<button type="button" class="cr-btn-secondary" data-cr-tags-create><?php esc_html_e( 'Crear', 'command-room' ); ?></button>
					<button type="button" class="cr-btn-secondary" data-cr-tags-delete-empty><?php esc_html_e( 'Eliminar todas las vacías', 'command-room' ); ?></button>
				</div>
			</div>

			<p class="cmdroom-tags-status" data-cr-tags-status hidden></p>

			<?php if ( ! $tags ) : ?>
				<p class="cmdroom-config-note"><?php esc_html_e( 'No hay etiquetas todavía.', 'command-room' ); ?></p>
			<?php else : ?>
				<table class="cmdroom-tags-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Etiqueta', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Entradas', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Fusionar en…', 'command-room' ); ?></th>
							<th><?php esc_html_e( 'Metas', 'command-room' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tags as $tag ) :
							$custom_title = get_term_meta( $tag->term_id, '_cmdroom_title', true );
							$custom_desc  = get_term_meta( $tag->term_id, '_cmdroom_description', true );
							$has_custom   = ( '' !== $custom_title || '' !== $custom_desc );
							?>
							<tr class="cmdroom-tags-row<?php echo 0 === (int) $tag->count ? ' is-empty' : ''; ?>" data-term-id="<?php echo (int) $tag->term_id; ?>">
								<td><?php echo esc_html( $tag->name ); ?></td>
								<td><span class="cr-chip"><?php echo (int) $tag->count; ?></span></td>
								<td>
									<select class="cmdroom-tags-merge-select">
										<option value=""><?php esc_html_e( '— elegir etiqueta —', 'command-room' ); ?></option>
										<?php foreach ( $tags as $other ) : ?>
											<?php if ( $other->term_id === $tag->term_id ) { continue; } ?>
											<option value="<?php echo (int) $other->term_id; ?>"><?php echo esc_html( $other->name ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="button" class="cr-btn-secondary" data-cr-tags-merge><?php esc_html_e( 'Fusionar', 'command-room' ); ?></button>
								</td>
								<td>
									<button
										type="button"
										class="cr-btn-secondary cr-btn-compact"
										data-cr-tags-configure
										data-title="<?php echo esc_attr( $custom_title ); ?>"
										data-description="<?php echo esc_attr( $custom_desc ); ?>"
									><?php esc_html_e( 'Configurar', 'command-room' ); ?></button>
									<span class="cr-pill cr-pill-other cmdroom-tags-custom-pill" data-cr-tags-custom-pill <?php echo $has_custom ? '' : 'hidden'; ?>><?php esc_html_e( 'Propias', 'command-room' ); ?></span>
								</td>
								<td>
									<?php if ( 0 === (int) $tag->count ) : ?>
										<button type="button" class="cmdroom-tags-delete-btn" data-cr-tags-delete-one><?php esc_html_e( 'Eliminar', 'command-room' ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="cr-dialog-backdrop" data-cr-tags-dialog>
			<div class="cr-dialog" role="dialog" aria-modal="true">
				<h2 class="cr-dialog-title"><?php esc_html_e( 'Metas de la etiqueta', 'command-room' ); ?></h2>
				<p class="cmdroom-config-note"><?php esc_html_e( 'Vacío = usa la plantilla global de Metas → Tags para esta etiqueta.', 'command-room' ); ?></p>

				<div class="cr-dialog-field">
					<label class="cr-label" for="cmdroom-tags-dialog-title"><?php esc_html_e( 'Título', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-tags-dialog-title" class="cr-input" data-cr-tags-dialog-title />
				</div>

				<div class="cr-dialog-field">
					<label class="cr-label" for="cmdroom-tags-dialog-desc"><?php esc_html_e( 'Meta descripción', 'command-room' ); ?></label>
					<textarea id="cmdroom-tags-dialog-desc" class="cr-input cmdroom-ia-textarea" rows="3" data-cr-tags-dialog-desc></textarea>
				</div>

				<div class="cr-dialog-actions">
					<button type="button" class="cr-btn-secondary" data-cr-close-dialog><?php esc_html_e( 'Cancelar', 'command-room' ); ?></button>
					<button type="button" class="cr-btn-primary" data-cr-tags-dialog-save><?php esc_html_e( 'Guardar', 'command-room' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_masivo_subtab() {
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		$categories = get_categories( array( 'hide_empty' => false ) );
		?>
		<div class="cr-card cmdroom-ia-block" data-cr-tags-bulk data-nonce="<?php echo esc_attr( wp_create_nonce( 'cmdroom_tags_actions' ) ); ?>">
			<h2 class="cmdroom-ia-block-title"><?php esc_html_e( 'Etiquetado masivo', 'command-room' ); ?></h2>
			<p class="cmdroom-config-rule-desc"><?php esc_html_e( 'Filtra los posts y añade o quita una o varias etiquetas a todos los que coincidan de una vez. Las etiquetas que no existan se crean solas.', 'command-room' ); ?></p>

			<div class="cmdroom-config-grid">
				<div class="cmdroom-ia-field">
					<label class="cr-label" for="cmdroom-bulk-post-type"><?php esc_html_e( 'Tipo de contenido', 'command-room' ); ?></label>
					<select id="cmdroom-bulk-post-type" class="cr-input" data-cr-bulk-filter="post_type">
						<?php foreach ( $post_types as $pt ) : ?>
							<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( 'post', $pt->name ); ?>><?php echo esc_html( $pt->label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="cmdroom-ia-field">
					<label class="cr-label" for="cmdroom-bulk-category"><?php esc_html_e( 'Categoría', 'command-room' ); ?></label>
					<select id="cmdroom-bulk-category" class="cr-input" data-cr-bulk-filter="category">
						<option value=""><?php esc_html_e( 'Todas', 'command-room' ); ?></option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?> (<?php echo (int) $cat->count; ?>)</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="cmdroom-ia-field">
					<label class="cr-label" for="cmdroom-bulk-date-from"><?php esc_html_e( 'Publicado desde', 'command-room' ); ?></label>
					<input type="date" id="cmdroom-bulk-date-from" class="cr-input" data-cr-bulk-filter="date_from" />
				</div>
				<div class="cmdroom-ia-field">
					<label class="cr-label" for="cmdroom-bulk-date-to"><?php esc_html_e( 'Hasta', 'command-room' ); ?></label>
					<input type="date" id="cmdroom-bulk-date-to" class="cr-input" data-cr-bulk-filter="date_to" />
				</div>
				<div class="cmdroom-ia-field" style="grid-column: 1 / -1;">
					<label class="cr-label" for="cmdroom-bulk-search"><?php esc_html_e( 'Buscar en el título', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-bulk-search" class="cr-input" data-cr-bulk-filter="search" placeholder="<?php esc_attr_e( 'Opcional', 'command-room' ); ?>" />
				</div>
			</div>

			<p class="cmdroom-tags-bulk-count" data-cr-bulk-count><?php esc_html_e( 'Ajusta los filtros para ver cuántos posts coinciden.', 'command-room' ); ?></p>

			<div class="cmdroom-config-grid">
				<div class="cmdroom-ia-field">
					<label class="cr-label" for="cmdroom-bulk-add"><?php esc_html_e( 'Etiquetas a añadir (separadas por coma)', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-bulk-add" class="cr-input" placeholder="<?php esc_attr_e( 'nike, running', 'command-room' ); ?>" />
				</div>
				<div class="cmdroom-ia-field">
					<label class="cr-label" for="cmdroom-bulk-remove"><?php esc_html_e( 'Etiquetas a quitar (separadas por coma)', 'command-room' ); ?></label>
					<input type="text" id="cmdroom-bulk-remove" class="cr-input" placeholder="<?php esc_attr_e( 'sin-categorizar', 'command-room' ); ?>" />
				</div>
			</div>

			<button type="button" class="cr-btn-primary" data-cr-bulk-apply><?php esc_html_e( 'Aplicar', 'command-room' ); ?></button>
			<p class="cmdroom-tags-status" data-cr-bulk-status hidden></p>
		</div>
		<?php
	}

	private static function render_config_subtab() {
		$opts = self::get_options();
		?>
		<div class="cr-card cmdroom-ia-block">
			<h2 class="cmdroom-ia-block-title"><?php esc_html_e( 'Límite de etiquetas por post', 'command-room' ); ?></h2>
			<p class="cmdroom-config-rule-desc"><?php esc_html_e( 'Aviso en el editor cuando un post supera este número de etiquetas — no se aplica solo, tú decides qué quitar.', 'command-room' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cmdroom-tags-limit-form">
				<?php wp_nonce_field( 'cmdroom_save_tags' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_tags" />
				<label class="cr-label" for="cmdroom-tags-max"><?php esc_html_e( 'Máximo recomendado (0 = sin límite)', 'command-room' ); ?></label>
				<div class="cmdroom-tags-limit-row">
					<input type="number" id="cmdroom-tags-max" class="cr-input" name="max_per_post" min="0" max="50" value="<?php echo esc_attr( $opts['max_per_post'] ); ?>" style="max-width:110px;" />
					<?php submit_button( __( 'Guardar', 'command-room' ), 'cr-btn-primary', 'submit', false ); ?>
				</div>
			</form>

			<p class="cmdroom-config-note">
				<?php
				printf(
					/* translators: %s: enlace a Metas → Tags */
					wp_kses( __( 'Las plantillas de título y meta descripción de las páginas de archivo de tags se configuran en %s.', 'command-room' ), array( 'a' => array( 'href' => array() ) ) ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=cmdroom-metas&tab=tags' ) ) . '">' . esc_html__( 'Metas → Tags', 'command-room' ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
