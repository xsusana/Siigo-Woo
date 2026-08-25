<?php
/**
 * Rutinas de activación del plugin.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Install {

	/**
	 * Crea la tabla de log y guarda la versión instalada.
	 */
	public static function activate() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = Siigoc_Logger::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				level VARCHAR(10) NOT NULL DEFAULT 'info',
				method VARCHAR(10) NOT NULL DEFAULT '',
				endpoint VARCHAR(190) NOT NULL DEFAULT '',
				request LONGTEXT NULL,
				response LONGTEXT NULL,
				http_code SMALLINT NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY level (level)
			) {$charset_collate};"
		);

		update_option( 'siigoc_version', SIIGOC_VERSION );
	}
}
