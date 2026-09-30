<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tabla propia de redirecciones. Se crea comprobando una versión guardada
 * en options en cada carga (no en register_activation_hook): el plugin ya
 * está activo desde la Fase 0 y ese hook no vuelve a disparar solo por
 * subir código nuevo.
 */
class Cmdroom_Redirect_Table {

	// 1.1: añade cmdroom_redirect_hits (historial de disparos: fecha + código
	// HTTP servido en cada uno) — la columna "hits" de la tabla principal
	// sigue siendo el contador agregado rápido, el historial es para detalle.
	const DB_VERSION = '1.1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_create_table' ) );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cmdroom_redirects';
	}

	public static function hits_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'cmdroom_redirect_hits';
	}

	public static function maybe_create_table() {
		if ( get_option( 'cmdroom_redirect_db_version' ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$hits_table_name = self::hits_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(255) NOT NULL,
			source_type VARCHAR(10) NOT NULL DEFAULT 'exact',
			destination VARCHAR(500) NOT NULL,
			redirect_type SMALLINT UNSIGNED NOT NULL DEFAULT 301,
			status TINYINT UNSIGNED NOT NULL DEFAULT 1,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY source (source),
			KEY status (status)
		) $charset_collate;";

		dbDelta( $sql );

		$sql_hits = "CREATE TABLE $hits_table_name (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			redirect_id BIGINT UNSIGNED NOT NULL,
			hit_at DATETIME NOT NULL,
			status_code SMALLINT UNSIGNED NOT NULL,
			requested_path VARCHAR(500) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY redirect_id (redirect_id),
			KEY hit_at (hit_at)
		) $charset_collate;";

		dbDelta( $sql_hits );

		update_option( 'cmdroom_redirect_db_version', self::DB_VERSION );
	}

	public static function get_all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC', ARRAY_A );
	}

	public static function get_by_source( $source ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_name() . ' WHERE source = %s AND source_type = %s AND status = 1 LIMIT 1',
				$source,
				'exact'
			),
			ARRAY_A
		);
	}

	public static function get_active_regex_rules() {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_name() . ' WHERE source_type = %s AND status = 1', 'regex' ),
			ARRAY_A
		);
	}

	public static function insert( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$wpdb->insert( self::table_name(), array_merge( $data, array(
			'created_at' => $now,
			'updated_at' => $now,
		) ) );
		return $wpdb->insert_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table_name(), array( 'id' => $id ) );
	}

	public static function increment_hits( $id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table_name() . ' SET hits = hits + 1 WHERE id = %d', $id ) );
	}

	/**
	 * Registra un disparo concreto en el historial — con qué código HTTP se
	 * sirvió y qué ruta lo provocó (útil cuando una regla regex captura
	 * rutas distintas cada vez).
	 */
	public static function log_hit( $id, $status_code, $requested_path = '' ) {
		global $wpdb;
		$wpdb->insert( self::hits_table_name(), array(
			'redirect_id'    => (int) $id,
			'hit_at'         => current_time( 'mysql' ),
			'status_code'    => (int) $status_code,
			'requested_path' => substr( (string) $requested_path, 0, 500 ),
		) );
	}

	public static function get_recent_hits( $limit = 20 ) {
		global $wpdb;
		$table      = self::table_name();
		$hits_table = self::hits_table_name();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.*, r.source, r.destination
				FROM $hits_table h
				LEFT JOIN $table r ON r.id = h.redirect_id
				ORDER BY h.id DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}
}
