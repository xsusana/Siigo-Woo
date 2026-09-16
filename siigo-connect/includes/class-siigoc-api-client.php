<?php
/**
 * Cliente HTTP para la API de Siigo Nube.
 *
 * Documentación: https://siigoapi.docs.apiary.io / https://developer.siigo.com
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Api_Client {

	const BASE_URL        = 'https://api.siigo.com';
	const TOKEN_TRANSIENT = 'siigoc_access_token';

	/** @var string */
	private $username;

	/** @var string */
	private $access_key;

	/** @var string */
	private $partner_id;

	public function __construct( $username, $access_key, $partner_id = 'SiigoConnectWP' ) {
		$this->username   = $username;
		$this->access_key = $access_key;
		$this->partner_id = $partner_id ? $partner_id : 'SiigoConnectWP';
	}

	/**
	 * ¿Hay credenciales configuradas?
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->username && '' !== $this->access_key;
	}

	/**
	 * Obtiene un token de acceso, usando el cacheado si sigue vigente.
	 *
	 * @param bool $force Forzar una autenticación nueva ignorando el caché.
	 * @return string|WP_Error Token de acceso o error.
	 */
	public function get_token( $force = false ) {
		if ( ! $this->has_credentials() ) {
			return new WP_Error( 'siigoc_no_credentials', __( 'Configura el usuario y el access key de Siigo en los ajustes.', 'siigo-connect' ) );
		}

		if ( ! $force ) {
			$cached = get_transient( self::TOKEN_TRANSIENT );
			if ( is_array( $cached ) && ! empty( $cached['token'] ) && $cached['username'] === $this->username ) {
				return $cached['token'];
			}
		}

		$response = wp_remote_post(
			self::BASE_URL . '/auth',
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Partner-Id'   => $this->partner_id,
				),
				'body'    => wp_json_encode(
					array(
						'username'   => $this->username,
						'access_key' => $this->access_key,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Siigoc_Logger::log( 'error', 'POST', '/auth', '', $response->get_error_message(), 0 );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$message = $this->extract_error_message( $body, $code );
			Siigoc_Logger::log( 'error', 'POST', '/auth', '', $message, $code );
			return new WP_Error( 'siigoc_auth_failed', sprintf( __( 'Autenticación con Siigo fallida: %s', 'siigo-connect' ), $message ) );
		}

		// El token de Siigo dura 24 horas; se cachea con un margen de seguridad.
		$expires_in = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : DAY_IN_SECONDS;
		set_transient(
			self::TOKEN_TRANSIENT,
			array(
				'token'    => $body['access_token'],
				'username' => $this->username,
			),
			max( 300, $expires_in - 600 )
		);

		Siigoc_Logger::log( 'info', 'POST', '/auth', '', __( 'Autenticación exitosa.', 'siigo-connect' ), $code );

		return $body['access_token'];
	}

	/**
	 * Petición genérica a la API con autenticación, logging y un reintento ante 401.
	 *
	 * @param string     $method GET|POST|PUT|DELETE.
	 * @param string     $path   Ruta, ej. '/v1/invoices'.
	 * @param array|null $body   Cuerpo a enviar como JSON.
	 * @param array      $query  Parámetros de query string.
	 * @return array|WP_Error Cuerpo de la respuesta decodificado, o error.
	 */
	public function request( $method, $path, $body = null, $query = array() ) {
		$result = $this->do_request( $method, $path, $body, $query, false );

		// Si el token cacheado expiró, reautenticar una vez y reintentar.
		if ( is_wp_error( $result ) && 'siigoc_http_401' === $result->get_error_code() ) {
			$result = $this->do_request( $method, $path, $body, $query, true );
		}

		return $result;
	}

	/**
	 * @param bool $force_auth Forzar autenticación nueva antes de la petición.
	 * @return array|WP_Error
	 */
	private function do_request( $method, $path, $body, $query, $force_auth ) {
		$token = $this->get_token( $force_auth );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = self::BASE_URL . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 45,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $token,
				'Partner-Id'    => $this->partner_id,
			),
		);

		if ( null !== $body ) {
			$args['body'] = self::encode_json( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			Siigoc_Logger::log( 'error', $method, $path, $body, $response->get_error_message(), 0 );
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$raw     = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			Siigoc_Logger::log( 'info', $method, $path, $body, $raw, $code );
			return is_array( $decoded ) ? $decoded : array();
		}

		$message = $this->extract_error_message( $decoded, $code );
		Siigoc_Logger::log( 'error', $method, $path, $body, $raw, $code );

		return new WP_Error(
			'siigoc_http_' . $code,
			$message,
			array(
				'status' => $code,
				'body'   => $decoded,
			)
		);
	}

	/**
	 * Codifica el cuerpo en JSON con los decimales tal cual se redondearon.
	 *
	 * Si el servidor tiene serialize_precision en 17 (configuración antigua de
	 * PHP), json_encode convierte 41932.77 en 41932.769999999997 y Siigo responde
	 * "price amount is invalid". Con -1 se usa la representación más corta.
	 *
	 * @param array $data Datos a codificar.
	 * @return string
	 */
	public static function encode_json( $data ) {
		$can_change = function_exists( 'ini_get' ) && function_exists( 'ini_set' );
		$previous   = $can_change ? ini_get( 'serialize_precision' ) : false;

		if ( $can_change ) {
			ini_set( 'serialize_precision', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		$json = wp_json_encode( $data );

		if ( $can_change && false !== $previous ) {
			ini_set( 'serialize_precision', $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		return (string) $json;
	}

	/**
	 * Convierte la estructura de errores de Siigo en un mensaje legible.
	 *
	 * @param array|null $body Cuerpo decodificado de la respuesta.
	 * @param int        $code Código HTTP.
	 * @return string
	 */
	private function extract_error_message( $body, $code ) {
		if ( is_array( $body ) ) {
			// Formato típico: { "Errors": [ { "Code": "...", "Message": "..." } ] }.
			foreach ( array( 'Errors', 'errors' ) as $key ) {
				if ( ! empty( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
					$messages = array();
					foreach ( $body[ $key ] as $error ) {
						$messages[] = isset( $error['Message'] ) ? $error['Message'] : ( isset( $error['message'] ) ? $error['message'] : wp_json_encode( $error ) );
					}
					return implode( ' | ', $messages );
				}
			}
			if ( ! empty( $body['message'] ) ) {
				return $body['message'];
			}
		}

		return sprintf( __( 'Error HTTP %d de la API de Siigo.', 'siigo-connect' ), $code );
	}

	/**
	 * Prueba la conexión: autentica y consulta los tipos de comprobante.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		delete_transient( self::TOKEN_TRANSIENT );

		$result = $this->get_document_types();

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Tipos de comprobante de factura de venta (FV).
	 *
	 * @return array|WP_Error
	 */
	public function get_document_types() {
		return $this->request( 'GET', '/v1/document-types', null, array( 'type' => 'FV' ) );
	}

	/**
	 * Usuarios de Siigo (vendedores).
	 *
	 * @return array|WP_Error
	 */
	public function get_users() {
		return $this->request( 'GET', '/v1/users' );
	}

	/**
	 * Centros de costo.
	 *
	 * @return array|WP_Error
	 */
	public function get_cost_centers() {
		return $this->request( 'GET', '/v1/cost-centers' );
	}

	/**
	 * Formas de pago para facturas de venta.
	 *
	 * @return array|WP_Error
	 */
	public function get_payment_types() {
		return $this->request( 'GET', '/v1/payment-types', null, array( 'document_type' => 'FV' ) );
	}

	/**
	 * Impuestos configurados en Siigo.
	 *
	 * @return array|WP_Error
	 */
	public function get_taxes() {
		return $this->request( 'GET', '/v1/taxes' );
	}

	/**
	 * Un producto por su código (SKU).
	 *
	 * @param string $code Código del producto en Siigo.
	 * @return array|WP_Error
	 */
	public function get_product_by_code( $code ) {
		return $this->request( 'GET', '/v1/products', null, array( 'code' => (string) $code ) );
	}

	/**
	 * Productos, paginados.
	 *
	 * @param int $page      Página (desde 1).
	 * @param int $page_size Tamaño de página (máx. 100).
	 * @return array|WP_Error
	 */
	public function get_products( $page = 1, $page_size = 100 ) {
		return $this->request(
			'GET',
			'/v1/products',
			null,
			array(
				'page'      => (string) $page,
				'page_size' => (string) $page_size,
			)
		);
	}
}
