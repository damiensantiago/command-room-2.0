<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tabla propia de 404s detectados. Igual que la tabla de redirecciones, se
 * crea comprobando una versión guardada en options en cada carga (init) en
 * vez de register_activation_hook, porque el plugin ya está activo.
 */
class Cmdroom_404_Table {

	const DB_VERSION = '1.0';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_create_table' ) );
		self::schedule_purge();
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cmdroom_404s';
	}

	public static function maybe_create_table() {
		if ( get_option( 'cmdroom_404_db_version' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_path VARCHAR(500) NOT NULL,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 1,
			referrer VARCHAR(500) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			is_bot TINYINT UNSIGNED NOT NULL DEFAULT 0,
			first_seen DATETIME NOT NULL,
			last_seen DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY request_path (request_path(191)),
			KEY hits (hits)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'cmdroom_404_db_version', self::DB_VERSION );
	}

	/**
	 * Upsert por ruta: si ya vimos esa ruta antes, solo sube el contador y
	 * refresca last_seen — así la tabla no crece sin límite con bots que
	 * repiten la misma URL rota miles de veces.
	 */
	public static function record( $path, $referrer, $user_agent, $is_bot ) {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM $table WHERE request_path = %s", $path )
		);

		if ( $existing_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET hits = hits + 1, last_seen = %s, referrer = %s, user_agent = %s, is_bot = %d WHERE id = %d",
					$now,
					$referrer,
					$user_agent,
					$is_bot ? 1 : 0,
					$existing_id
				)
			);
			return (int) $existing_id;
		}

		$wpdb->insert( $table, array(
			'request_path' => $path,
			'hits'         => 1,
			'referrer'     => $referrer,
			'user_agent'   => $user_agent,
			'is_bot'       => $is_bot ? 1 : 0,
			'first_seen'   => $now,
			'last_seen'    => $now,
		) );
		return (int) $wpdb->insert_id;
	}

	public static function get_all( $limit = 200 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' ORDER BY hits DESC, last_seen DESC LIMIT %d', $limit ),
			ARRAY_A
		);
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE id = %d', $id ),
			ARRAY_A
		);
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => $id ) );
	}

	/**
	 * Borra (si existe) la fila cuya request_path coincide con una ruta ya
	 * resuelta por una redirección nueva — se llama desde
	 * Cmdroom_Redirect_Admin::handle_save() para que un 404 arreglado
	 * desaparezca solo del registro, sin acción aparte.
	 */
	public static function delete_by_path( $path ) {
		global $wpdb;
		$path = '/' . trim( (string) $path, '/' );
		$wpdb->delete( self::table_name(), array( 'request_path' => $path ) );
	}

	public static function count_total() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table_name() );
	}

	/**
	 * Cron diario: borra entradas con más de 30 días sin verse (spec del
	 * rediseño "Servidor" 2026-09-23) — sin esto la tabla crecería sin
	 * límite en un sitio con tráfico roto constante.
	 */
	public static function schedule_purge() {
		if ( ! wp_next_scheduled( 'cmdroom_404_purge' ) ) {
			wp_schedule_event( time(), 'daily', 'cmdroom_404_purge' );
		}
		add_action( 'cmdroom_404_purge', array( __CLASS__, 'purge_old' ) );
	}

	public static function purge_old() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table_name() . ' WHERE last_seen < %s', gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ) ) );
	}
}
