<?php
/**
 * Registro de llamadas a la API y eventos del plugin en una tabla propia.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Logger {

	const TABLE = 'siigoc_log';

	/**
	 * Nombre completo de la tabla con prefijo.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Guarda una entrada en el log.
	 *
	 * @param string            $level    info|error.
	 * @param string            $method   Método HTTP o etiqueta del evento.
	 * @param string            $endpoint Ruta de la API o contexto.
	 * @param array|string|null $request  Cuerpo enviado.
	 * @param string            $response Respuesta o mensaje.
	 * @param int               $http_code Código HTTP (0 si no aplica).
	 */
	public static function log( $level, $method, $endpoint, $request, $response, $http_code = 0 ) {
		global $wpdb;

		if ( is_array( $request ) ) {
			$request = self::redact( $request );
			$request = Siigoc_Api_Client::encode_json( $request );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'level'      => substr( (string) $level, 0, 10 ),
				'method'     => substr( (string) $method, 0, 10 ),
				'endpoint'   => substr( (string) $endpoint, 0, 190 ),
				'request'    => (string) $request,
				'response'   => (string) $response,
				'http_code'  => (int) $http_code,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Oculta datos sensibles antes de guardarlos en el log.
	 *
	 * @param array $data Cuerpo de la petición.
	 * @return array
	 */
	private static function redact( $data ) {
		foreach ( array( 'access_key', 'password', 'token' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = '***';
			}
		}
		return $data;
	}

	/**
	 * Lee entradas del log, paginadas y de la más reciente a la más antigua.
	 *
	 * @param int $page     Página desde 1.
	 * @param int $per_page Entradas por página.
	 * @return array{items: array, total: int}
	 */
	public static function get_entries( $page = 1, $per_page = 50 ) {
		global $wpdb;

		$table  = self::table_name();
		$offset = max( 0, ( $page - 1 ) * $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$items = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Vacía el log.
	 */
	public static function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . self::table_name() );
	}

	/**
	 * Borra entradas con más de 30 días (se llama por cron en fases siguientes).
	 */
	public static function purge_old() {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
	}
}
