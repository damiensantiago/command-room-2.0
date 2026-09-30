<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes del componente "Carrusel de recomendación de contenidos" --
 * mismo patrón que Cmdroom_Affiliate_Settings: el modo (site/section/friends)
 * se cambia con un enlace admin-post que guarda y redirige ANTES de pintar
 * la vista (como el segmentado Formato de Afiliación), así cambiar de lógica
 * refleja al momento la descripción, la nota de vista previa y el
 * shortcode -- sin depender de JS para eso.
 */
class Cmdroom_Reco_Settings {

	public static function init() {
		add_action( 'admin_post_cmdroom_reco_switch_mode', array( __CLASS__, 'handle_switch_mode' ) );
		add_action( 'admin_post_cmdroom_save_reco', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cmdroom_reco_invite_friend', array( __CLASS__, 'handle_invite_friend' ) );
		add_action( 'admin_post_cmdroom_reco_remove_friend', array( __CLASS__, 'handle_remove_friend' ) );
	}

	public static function tab_url( $extra = array() ) {
		return add_query_arg( $extra, Cmdroom_Components_Admin::tab_url( 'reco' ) );
	}

	private static function get_mode() {
		return Cmdroom_Reco::get_options()['mode'];
	}

	/* — Sección seleccionada en el admin (para el modo "section") — */

	private static function wp_categories() {
		$cats = get_categories( array( 'hide_empty' => false ) );
		return $cats ? $cats : array();
	}

	private static function current_section_id( $opts, $cats ) {
		$requested = isset( $_GET['sec'] ) ? (int) $_GET['sec'] : 0;
		$ids       = wp_list_pluck( $cats, 'term_id' );
		if ( $requested && in_array( $requested, $ids, true ) ) {
			return $requested;
		}
		if ( ! empty( $opts['active_section'] ) && in_array( (int) $opts['active_section'], $ids, true ) ) {
			return (int) $opts['active_section'];
		}
		foreach ( $cats as $c ) {
			if ( $c->count > 0 ) {
				return (int) $c->term_id;
			}
		}
		return $ids ? (int) $ids[0] : 0;
	}

	/* — Cambiar lógica (site/section/friends) — */

	public static function handle_switch_mode() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '';
		if ( ! in_array( $mode, Cmdroom_Reco::MODES, true ) ) {
			wp_die( esc_html__( 'Lógica no válida.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_reco_switch_mode_' . $mode );

		$opts         = Cmdroom_Reco::get_options();
		$opts['mode'] = $mode;
		Cmdroom_Reco::update_options( $opts );

		wp_safe_redirect( self::tab_url() );
		exit;
	}

	/* — Guardado — */

	private static function sanitize_pins_post( $field ) {
		$raw  = isset( $_POST[ $field ] ) ? (array) $_POST[ $field ] : array();
		$pins = array( 0, 0, 0 );
		foreach ( array_slice( array_values( $raw ), 0, 3 ) as $i => $val ) {
			$id = (int) $val;
			$pins[ $i ] = ( $id && 'publish' === get_post_status( $id ) ) ? $id : 0;
		}
		// Una entrada fijada en dos posiciones a la vez no tiene sentido --
		// se conserva solo la primera aparición, el resto vuelve a automático.
		$seen = array();
		foreach ( $pins as $i => $id ) {
			if ( $id && in_array( $id, $seen, true ) ) {
				$pins[ $i ] = 0;
			} elseif ( $id ) {
				$seen[] = $id;
			}
		}
		return $pins;
	}

	private static function sanitize_friends_post() {
		$domains = isset( $_POST['friend_domain'] ) ? (array) wp_unslash( $_POST['friend_domain'] ) : array();
		$status  = isset( $_POST['friend_status'] ) ? (array) wp_unslash( $_POST['friend_status'] ) : array();
		$enabled = isset( $_POST['friend_enabled'] ) ? (array) $_POST['friend_enabled'] : array();
		$tokens  = isset( $_POST['friend_token'] ) ? (array) wp_unslash( $_POST['friend_token'] ) : array();
		$notes   = isset( $_POST['friend_note'] ) ? (array) wp_unslash( $_POST['friend_note'] ) : array();

		$out = array();
		foreach ( $domains as $i => $domain ) {
			$domain = sanitize_text_field( $domain );
			if ( '' === $domain ) {
				continue;
			}
			$out[] = array(
				'domain'  => $domain,
				'status'  => in_array( ( isset( $status[ $i ] ) ? $status[ $i ] : 'pending' ), array( 'ok', 'pending' ), true ) ? $status[ $i ] : 'pending',
				'enabled' => ! empty( $enabled[ $i ] ),
				'token'   => isset( $tokens[ $i ] ) ? $tokens[ $i ] : '',
				'note'    => isset( $notes[ $i ] ) ? $notes[ $i ] : '',
			);
		}
		return $out;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_save_reco' );

		$opts              = Cmdroom_Reco::get_options();
		$mode              = $opts['mode']; // el <form> solo pinta los campos del modo activo.
		$opts['title']     = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : Cmdroom_Reco::DEFAULTS['title'];
		$placement         = isset( $_POST['placement'] ) ? sanitize_key( wp_unslash( $_POST['placement'] ) ) : 'end';
		$opts['placement'] = in_array( $placement, array( 'end', 'shortcode' ), true ) ? $placement : 'end';
		$opts['new_tab']   = ! empty( $_POST['new_tab'] );

		$redirect_extra = array( 'cmdroom_saved' => '1' );

		if ( 'site' === $mode ) {
			$opts['pins_site'] = self::sanitize_pins_post( 'pin_site' );
		} elseif ( 'section' === $mode ) {
			$term_id = isset( $_POST['sec'] ) ? (int) $_POST['sec'] : 0;
			if ( $term_id ) {
				$opts['pins_section'][ $term_id ] = self::sanitize_pins_post( 'pin_section' );
				$opts['active_section']           = $term_id;
				$redirect_extra['sec']            = $term_id;
			}
			$opts['section_fill'] = ! empty( $_POST['section_fill'] );
		} elseif ( 'friends' === $mode ) {
			$mix  = isset( $_POST['mix'] ) ? sanitize_key( wp_unslash( $_POST['mix'] ) ) : 'friends';
			$rel  = isset( $_POST['rel'] ) ? sanitize_key( wp_unslash( $_POST['rel'] ) ) : 'nofollow';
			$opts['network']['mix'] = in_array( $mix, array( 'friends', 'alternate' ), true ) ? $mix : 'friends';
			$opts['network']['rel'] = in_array( $rel, Cmdroom_Reco::RELS, true ) ? $rel : 'nofollow';
			$shared_raw = isset( $_POST['shared_terms'] ) ? sanitize_text_field( wp_unslash( $_POST['shared_terms'] ) ) : '';
			$opts['network']['shared_terms'] = array_values( array_filter( array_map( 'intval', explode( ',', $shared_raw ) ) ) );
			$opts['network']['friends']      = self::sanitize_friends_post();
		}

		Cmdroom_Reco::update_options( $opts );

		wp_safe_redirect( add_query_arg( $redirect_extra, self::tab_url() ) );
		exit;
	}

	/* — Invitar / quitar web amiga — */

	public static function handle_invite_friend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_reco_invite_friend' );

		$domain = isset( $_POST['invite_domain'] ) ? sanitize_text_field( wp_unslash( $_POST['invite_domain'] ) ) : '';
		$domain = preg_replace( '#^https?://#', '', $domain );
		$domain = trim( $domain, "/ \t\n\r\0\x0B" );

		if ( '' !== $domain && false !== strpos( $domain, '.' ) ) {
			$opts    = Cmdroom_Reco::get_options();
			$friends = $opts['network']['friends'];
			$exists  = false;
			foreach ( $friends as $f ) {
				if ( isset( $f['domain'] ) && strtolower( $f['domain'] ) === strtolower( $domain ) ) {
					$exists = true;
					break;
				}
			}
			if ( ! $exists ) {
				$token = Cmdroom_Reco::send_invite( $domain );
				$friends[] = array(
					'domain'  => $domain,
					'status'  => 'pending',
					'enabled' => false,
					'token'   => $token,
					'note'    => sprintf( __( 'Invitación enviada el %s', 'command-room' ), date_i18n( 'd/m/Y' ) ),
				);
				$opts['network']['friends'] = $friends;
				Cmdroom_Reco::update_options( $opts );
			}
		}

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', self::tab_url() ) );
		exit;
	}

	public static function handle_remove_friend() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'command-room' ) );
		}
		check_admin_referer( 'cmdroom_reco_remove_friend' );

		$domain = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
		$opts   = Cmdroom_Reco::get_options();
		$opts['network']['friends'] = array_values( array_filter( $opts['network']['friends'], function ( $f ) use ( $domain ) {
			return ! isset( $f['domain'] ) || strtolower( $f['domain'] ) !== strtolower( $domain );
		} ) );
		Cmdroom_Reco::update_options( $opts );

		wp_safe_redirect( add_query_arg( 'cmdroom_saved', '1', self::tab_url() ) );
		exit;
	}

	/* — A. Fila superior — */

	private static function mode_label( $mode ) {
		$labels = array( 'site' => __( 'Últimos de la web', 'command-room' ), 'section' => __( 'Últimos de la sección', 'command-room' ), 'friends' => __( 'Webs amigas', 'command-room' ) );
		return isset( $labels[ $mode ] ) ? $labels[ $mode ] : $mode;
	}

	private static function mode_description( $mode ) {
		$desc = array(
			'site'    => __( 'Lo más reciente del sitio. Fija hasta 3; el resto se rellena solo.', 'command-room' ),
			'section' => __( 'Lo más reciente de la categoría del artículo. Hasta 3 fijados por sección.', 'command-room' ),
			'friends' => __( 'Lo último de tu red. Es recíproco: tus entradas salen en sus carruseles.', 'command-room' ),
		);
		return isset( $desc[ $mode ] ) ? $desc[ $mode ] : '';
	}

	private static function render_top_row( $opts ) {
		$mode = $opts['mode'];
		?>
		<div class="cmdroom-aff-top-row">
			<div>
				<span class="cr-label"><?php esc_html_e( 'Lógica del carrusel', 'command-room' ); ?></span>
				<div class="cr-seg cr-seg-lg cmdroom-aff-format-seg">
					<?php foreach ( Cmdroom_Reco::MODES as $m ) : ?>
						<a
							class="cr-seg-opt<?php echo $mode === $m ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmdroom_reco_switch_mode&mode=' . $m ), 'cmdroom_reco_switch_mode_' . $m ) ); ?>"
						><?php echo esc_html( self::mode_label( $m ) ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>
			<div
				class="cr-card cmdroom-aff-activation"
				data-cr-reco-toggle-enabled
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'cmdroom_reco_toggle_enabled' ) ); ?>"
			>
				<button type="button" class="cr-toggle<?php echo $opts['enabled'] ? ' is-on' : ''; ?>"><span class="cr-toggle-knob"></span><input type="checkbox" <?php checked( $opts['enabled'] ); ?> /></button>
				<div>
					<p class="cmdroom-aff-activation-title"><?php esc_html_e( 'Activar carrusel en el sitio', 'command-room' ); ?></p>
					<p class="cmdroom-aff-activation-note" data-cr-reco-activation-note>
						<?php echo $opts['enabled']
							? esc_html__( 'Se imprime según la ubicación configurada.', 'command-room' )
							: esc_html__( 'Mientras esté apagado no se imprime nada.', 'command-room' ); ?>
					</p>
				</div>
			</div>
		</div>
		<p class="cmdroom-reco-mode-desc"><?php echo esc_html( self::mode_description( $mode ) ); ?></p>
		<?php
	}

	/* — B. Vista previa — */

	private static function render_preview( $opts, $cats, $sec_id ) {
		$mode = $opts['mode'];

		if ( 'friends' === $mode ) {
			$active = Cmdroom_Reco::active_friends();
			$note   = sprintf( __( '%1$d webs activas · %2$s', 'command-room' ), count( $active ), 'friends' === $opts['network']['mix'] ? __( 'solo webs amigas', 'command-room' ) : __( 'alternando con las tuyas', 'command-room' ) );
			$items  = Cmdroom_Reco::resolve_items_friends( 0 );
		} elseif ( 'section' === $mode ) {
			$sec_name = $sec_id ? get_term( $sec_id, 'category' )->name : '';
			$slots    = Cmdroom_Reco::admin_slots( 'section', $sec_id );
			$pinned_n = count( array_filter( $slots, function ( $s ) { return $s && ! empty( $s['pinned'] ); } ) );
			$note     = sprintf( __( 'Entrada de ejemplo en %1$s · %2$d fijados · %3$d automáticos', 'command-room' ), $sec_name, $pinned_n, 3 - $pinned_n );
			$items    = Cmdroom_Reco::resolve_items_section( 0, $sec_id );
		} else {
			$slots    = Cmdroom_Reco::admin_slots( 'site' );
			$pinned_n = count( array_filter( $slots, function ( $s ) { return $s && ! empty( $s['pinned'] ); } ) );
			$note     = sprintf( __( '%1$d fijado(s) · %2$d automáticos', 'command-room' ), $pinned_n, 3 - $pinned_n );
			$items    = Cmdroom_Reco::resolve_items_site( 0 );
		}

		$html = Cmdroom_Reco::render( $mode, $items, array(
			'title'         => $opts['title'],
			'new_tab'       => $opts['new_tab'],
			'rel'           => $opts['network']['rel'],
			'admin_preview' => true,
		) );
		?>
		<div class="cmdroom-aff-preview cmdroom-reco-preview">
			<div class="cmdroom-aff-preview-head">
				<span class="cr-label"><?php esc_html_e( 'Vista previa', 'command-room' ); ?></span>
				<span class="cmdroom-aff-preview-note"><?php echo esc_html( $note ); ?></span>
			</div>
			<div class="cmdroom-aff-preview-frame">
				<div class="cmdroom-aff-preview-box" data-cr-reco-preview>
					<?php
					if ( '' !== $html ) {
						echo $html; // ya escapado dentro de Cmdroom_Reco::render().
					} elseif ( 'friends' === $mode ) {
						echo '<p class="cmdroom-aff-preview-empty">' . esc_html__( 'Todavía no hay webs amigas activas -- el carrusel no se imprime hasta que haya al menos una.', 'command-room' ) . '</p>';
					} else {
						echo '<p class="cmdroom-aff-preview-empty">' . esc_html__( 'Sin contenido suficiente para mostrar una vista previa todavía.', 'command-room' ) . '</p>';
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/* — C. Contenidos fijados (site/section) — */

	private static function render_pin_select( $field, $index, $slot, $options_source, $used_ids ) {
		$current = $slot && ! empty( $slot['pinned'] ) ? $slot['id'] : 0;
		?>
		<div class="cr-card cmdroom-reco-pin-row">
			<div class="cmdroom-reco-pin-col1">
				<span class="cmdroom-aff-product-num"><?php printf( esc_html__( 'Posición %d', 'command-room' ), $index + 1 ); ?></span>
				<span class="cr-pill <?php echo $current ? 'cmdroom-aff-pill-accent' : 'cmdroom-aff-pill-muted'; ?>"><?php echo $current ? esc_html__( 'Fijado', 'command-room' ) : esc_html__( 'Automático', 'command-room' ); ?></span>
			</div>
			<div class="cmdroom-reco-pin-col2">
				<select class="cr-input" name="<?php echo esc_attr( $field ); ?>[]">
					<option value="0"><?php esc_html_e( 'Automático · último publicado', 'command-room' ); ?></option>
					<?php foreach ( $options_source as $id => $label ) :
						if ( in_array( $id, $used_ids, true ) && $id !== $current ) {
							continue; // ya fijada en otra posición.
						}
						?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $current ) : ?>
					<p class="cmdroom-reco-pin-now">
						<?php echo $slot
							? esc_html( sprintf( __( 'Ahora mismo: %s', 'command-room' ), get_the_title( $slot['id'] ) ) )
							: esc_html__( 'Sin contenido suficiente.', 'command-room' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_pins_section( $opts, $mode, $cats, $sec_id ) {
		$slots = Cmdroom_Reco::admin_slots( $mode, $sec_id );
		$pinned_ids = array_values( array_filter( array_map( function ( $s ) { return $s && ! empty( $s['pinned'] ) ? $s['id'] : null; }, $slots ) ) );

		if ( 'site' === $mode ) {
			$pool_ids = Cmdroom_Reco::resolve_site_pool( 0, 30 );
			$field    = 'pin_site';
			$note     = sprintf( __( '%1$d de 3 posiciones fijadas', 'command-room' ), count( $pinned_ids ) );
		} else {
			$pool_ids = Cmdroom_Reco::resolve_section_pool( $sec_id, 0, 30 );
			$field    = 'pin_section';
			$sec_name = $sec_id ? get_term( $sec_id, 'category' )->name : '';
			$note     = sprintf( __( '%1$d de 3 posiciones fijadas en %2$s', 'command-room' ), count( $pinned_ids ), $sec_name );
		}
		$options_source = array();
		foreach ( $pool_ids as $id ) {
			$cat = Cmdroom_Reco::primary_category( $id );
			$options_source[ $id ] = 'site' === $mode
				? ( ( $cat && ! is_wp_error( $cat ) ? $cat->name . ' · ' : '' ) . get_the_title( $id ) )
				: get_the_title( $id );
		}
		// Las ya fijadas en otras posiciones deben poder seguir apareciendo en su
		// propio select -- se filtran en render_pin_select() comparando contra el
		// valor actual de esa fila, no de forma global.
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Contenidos fijados', 'command-room' ); ?></span>
			<span class="cmdroom-aff-section-note"><?php echo esc_html( $note ); ?></span>
		</div>

		<?php if ( 'section' === $mode ) : ?>
			<div class="cmdroom-reco-sec-picker" style="margin-bottom:12px;">
				<span class="cr-label"><?php esc_html_e( 'Sección', 'command-room' ); ?></span>
				<div class="cr-seg cmdroom-reco-sec-seg">
					<?php foreach ( $cats as $c ) :
						$c_pins  = isset( $opts['pins_section'][ $c->term_id ] ) ? $opts['pins_section'][ $c->term_id ] : array();
						$c_count = count( array_filter( $c_pins ) );
						?>
						<a class="cr-seg-opt<?php echo (int) $sec_id === (int) $c->term_id ? ' is-active' : ''; ?>" href="<?php echo esc_url( self::tab_url( array( 'sec' => $c->term_id ) ) ); ?>">
							<?php echo esc_html( $c->name ); ?><?php if ( $c_count ) : ?> · <?php echo (int) $c_count; ?><?php endif; ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<input type="hidden" name="sec" value="<?php echo esc_attr( $sec_id ); ?>" />
		<?php endif; ?>

		<div class="cmdroom-aff-products">
			<?php foreach ( $slots as $i => $slot ) :
				self::render_pin_select( $field, $i, $slot, $options_source, $pinned_ids );
			endforeach; ?>
		</div>
		<p class="cmdroom-aff-note"><?php esc_html_e( 'Las posiciones sin fijar muestran lo último publicado en la web o sección del artículo. Nunca se muestra la entrada que se está leyendo.', 'command-room' ); ?></p>

		<?php if ( 'section' === $mode ) : ?>
			<div class="cmdroom-ticker-row" style="margin-top:14px;">
				<button type="button" class="cr-toggle<?php echo $opts['section_fill'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="section_fill" value="1" <?php checked( $opts['section_fill'] ); ?> /></button>
				<p class="cmdroom-ticker-row-title"><?php esc_html_e( 'Completar con lo último de la web si la sección no tiene suficientes', 'command-room' ); ?></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/* — D. Webs amigas — */

	private static function render_friends_section( $opts, $cats ) {
		$friends = $opts['network']['friends'];
		?>
		<div class="cmdroom-reco-network-notice">
			<p class="cmdroom-reco-network-notice-title"><?php esc_html_e( 'Red recíproca', 'command-room' ); ?></p>
			<p class="cmdroom-reco-network-notice-text"><?php esc_html_e( 'Tus entradas salen en el carrusel de tus webs amigas y las suyas en el tuyo. Desactivar esta lógica deja de exponer tu feed a la red.', 'command-room' ); ?></p>
		</div>

		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php printf( esc_html__( 'Webs amigas · %d conectadas', 'command-room' ), count( $friends ) ); ?></span>
			<button type="button" class="cr-btn-secondary" data-cr-reco-invite-toggle><?php esc_html_e( '+ Invitar web', 'command-room' ); ?></button>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cr-card cmdroom-reco-invite-form" data-cr-reco-invite-form hidden>
			<?php wp_nonce_field( 'cmdroom_reco_invite_friend' ); ?>
			<input type="hidden" name="action" value="cmdroom_reco_invite_friend" />
			<label class="cr-label"><?php esc_html_e( 'Dominio de la web amiga', 'command-room' ); ?></label>
			<div class="cmdroom-reco-invite-row">
				<input type="text" class="cr-input cmdroom-aff-mono" name="invite_domain" placeholder="ejemplo.com" />
				<button type="submit" class="cr-btn-primary"><?php esc_html_e( 'Enviar invitación', 'command-room' ); ?></button>
			</div>
		</form>

		<div class="cr-card cmdroom-reco-friends-list" data-cr-reco-friends-rows>
			<?php if ( empty( $friends ) ) : ?>
				<p class="cmdroom-aff-lib-empty"><?php esc_html_e( 'Todavía no hay webs amigas conectadas.', 'command-room' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $friends as $f ) :
				$status_label = 'pending' === $f['status'] ? __( 'Pendiente', 'command-room' ) : ( empty( $f['enabled'] ) ? __( 'En pausa', 'command-room' ) : __( 'Activa', 'command-room' ) );
				$is_pending   = 'pending' === $f['status'];
				?>
				<div class="cmdroom-reco-friend-row<?php echo $is_pending ? ' cmdroom-reco-friend-row--pending' : ''; ?>">
					<button type="button" class="cr-toggle<?php echo ! empty( $f['enabled'] ) ? ' is-on' : ''; ?>" data-cr-toggle <?php echo $is_pending ? 'disabled' : ''; ?>><span class="cr-toggle-knob"></span><input type="checkbox" name="friend_enabled[]" value="1" <?php checked( ! empty( $f['enabled'] ) ); ?> <?php disabled( $is_pending ); ?> /></button>
					<div class="cmdroom-reco-friend-body">
						<p class="cmdroom-aff-lib-name"><?php echo esc_html( $f['domain'] ); ?></p>
						<p class="cmdroom-aff-lib-meta"><?php echo esc_html( isset( $f['note'] ) ? $f['note'] : '' ); ?></p>
					</div>
					<span class="cr-pill <?php echo $is_pending ? 'cmdroom-aff-pill-muted' : ( empty( $f['enabled'] ) ? 'cmdroom-aff-pill-muted' : 'cmdroom-aff-pill-accent' ); ?>"><?php echo esc_html( $status_label ); ?></span>
					<input type="hidden" name="friend_domain[]" value="<?php echo esc_attr( $f['domain'] ); ?>" />
					<input type="hidden" name="friend_status[]" value="<?php echo esc_attr( $f['status'] ); ?>" />
					<input type="hidden" name="friend_token[]" value="<?php echo esc_attr( $f['token'] ); ?>" />
					<input type="hidden" name="friend_note[]" value="<?php echo esc_attr( $f['note'] ); ?>" />
					<a class="cr-btn-secondary cmdroom-aff-remove" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=cmdroom_reco_remove_friend&domain=' . rawurlencode( $f['domain'] ) ), 'cmdroom_reco_remove_friend' ) ); ?>">×</a>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="cmdroom-aff-section-head" style="margin-top:24px;">
			<span class="cr-label"><?php esc_html_e( 'Intercambio', 'command-room' ); ?></span>
		</div>
		<div class="cmdroom-reco-exchange-grid">
			<div>
				<span class="cr-label"><?php esc_html_e( 'Qué se muestra en tu web', 'command-room' ); ?></span>
				<div class="cr-seg">
					<button type="button" class="cr-seg-opt<?php echo 'friends' === $opts['network']['mix'] ? ' is-active' : ''; ?>" data-value="friends"><?php esc_html_e( 'Solo webs amigas', 'command-room' ); ?></button>
					<button type="button" class="cr-seg-opt<?php echo 'alternate' === $opts['network']['mix'] ? ' is-active' : ''; ?>" data-value="alternate"><?php esc_html_e( 'Alternar con las mías', 'command-room' ); ?></button>
				</div>
				<input type="hidden" name="mix" class="cr-seg-value" value="<?php echo esc_attr( $opts['network']['mix'] ); ?>" />
			</div>
			<div>
				<span class="cr-label"><?php esc_html_e( 'Atributo rel de los enlaces', 'command-room' ); ?></span>
				<div class="cr-seg">
					<?php foreach ( Cmdroom_Reco::RELS as $r ) : ?>
						<button type="button" class="cr-seg-opt cmdroom-aff-mono<?php echo $opts['network']['rel'] === $r ? ' is-active' : ''; ?>" data-value="<?php echo esc_attr( $r ); ?>"><?php echo esc_html( $r ); ?></button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="rel" class="cr-seg-value" value="<?php echo esc_attr( $opts['network']['rel'] ); ?>" />
			</div>
			<div>
				<span class="cr-label"><?php esc_html_e( 'Categorías que compartes con la red', 'command-room' ); ?></span>
				<div class="cmdroom-aff-cat-picker" data-cr-reco-cat-picker>
					<?php foreach ( $cats as $c ) :
						$active = in_array( (int) $c->term_id, array_map( 'intval', $opts['network']['shared_terms'] ), true );
						?>
						<button type="button" class="cr-pill cmdroom-aff-cat-btn<?php echo $active ? ' is-active' : ''; ?>" data-term="<?php echo esc_attr( $c->term_id ); ?>"><?php echo esc_html( $c->name ); ?></button>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="shared_terms" value="<?php echo esc_attr( implode( ',', array_map( 'intval', $opts['network']['shared_terms'] ) ) ); ?>" data-cr-reco-shared-input />
			</div>
		</div>
		<p class="cmdroom-aff-note"><?php esc_html_e( 'Máximo una entrada por web amiga en cada carrusel. Recomendado nofollow: los enlaces cruzados entre sitios pueden interpretarse como intercambio de enlaces.', 'command-room' ); ?></p>
		<?php
	}

	/* — E. Texto y ubicación (común) — */

	private static function render_common_section( $opts, $mode ) {
		?>
		<div class="cmdroom-aff-section-head">
			<span class="cr-label"><?php esc_html_e( 'Texto y ubicación', 'command-room' ); ?></span>
		</div>
		<div class="cmdroom-aff-texts-grid">
			<div>
				<label class="cr-label"><?php esc_html_e( 'Título', 'command-room' ); ?></label>
				<input type="text" class="cr-input" name="title" value="<?php echo esc_attr( $opts['title'] ); ?>" data-cr-reco-title-input />
			</div>
			<div>
				<span class="cr-label"><?php esc_html_e( 'Dónde se inserta', 'command-room' ); ?></span>
				<div class="cr-seg">
					<button type="button" class="cr-seg-opt<?php echo 'end' === $opts['placement'] ? ' is-active' : ''; ?>" data-value="end"><?php esc_html_e( 'Al final del artículo', 'command-room' ); ?></button>
					<button type="button" class="cr-seg-opt<?php echo 'shortcode' === $opts['placement'] ? ' is-active' : ''; ?>" data-value="shortcode"><?php esc_html_e( 'Solo con shortcode', 'command-room' ); ?></button>
				</div>
				<input type="hidden" name="placement" class="cr-seg-value" value="<?php echo esc_attr( $opts['placement'] ); ?>" />
			</div>
		</div>
		<div class="cmdroom-ticker-row" style="margin:14px 0;">
			<button type="button" class="cr-toggle<?php echo $opts['new_tab'] ? ' is-on' : ''; ?>" data-cr-toggle><span class="cr-toggle-knob"></span><input type="checkbox" name="new_tab" value="1" <?php checked( $opts['new_tab'] ); ?> /></button>
			<p class="cmdroom-ticker-row-title"><?php esc_html_e( 'Abrir enlaces en pestaña nueva', 'command-room' ); ?></p>
		</div>
		<div class="cmdroom-aff-shortcode-box">
			<label class="cr-label"><?php esc_html_e( 'Shortcode', 'command-room' ); ?></label>
			<code class="cmdroom-aff-shortcode-code" data-cr-reco-shortcode>
				<?php
				$logica = 'section' === $mode ? 'seccion' : ( 'friends' === $mode ? 'amigas' : 'web' );
				echo esc_html( '[cmdroom_relacionados logica="' . $logica . '"]' );
				?>
			</code>
		</div>
		<?php
	}

	/* — Render principal — */

	public static function render_tab() {
		$opts   = Cmdroom_Reco::get_options();
		$mode   = $opts['mode'];
		$cats   = self::wp_categories();
		$sec_id = 'section' === $mode ? self::current_section_id( $opts, $cats ) : 0;
		?>
		<?php if ( isset( $_GET['cmdroom_saved'] ) ) : ?>
			<div class="notice notice-success"><p><?php esc_html_e( 'Guardado.', 'command-room' ); ?></p></div>
		<?php endif; ?>

		<div class="cmdroom-aff-screen cmdroom-reco-screen">
			<?php self::render_top_row( $opts ); ?>
			<?php self::render_preview( $opts, $cats, $sec_id ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-cr-reco-form>
				<?php wp_nonce_field( 'cmdroom_save_reco' ); ?>
				<input type="hidden" name="action" value="cmdroom_save_reco" />

				<?php if ( 'friends' === $mode ) : ?>
					<?php self::render_friends_section( $opts, $cats ); ?>
				<?php else : ?>
					<?php self::render_pins_section( $opts, $mode, $cats, $sec_id ); ?>
				<?php endif; ?>

				<?php self::render_common_section( $opts, $mode ); ?>

				<?php submit_button( __( 'Guardar carrusel de recomendación', 'command-room' ), 'cr-btn-primary', 'submit', false, array( 'style' => 'margin-top:18px;' ) ); ?>
			</form>
		</div>
		<?php
	}
}
