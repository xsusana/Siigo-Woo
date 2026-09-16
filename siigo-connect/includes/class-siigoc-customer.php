<?php
/**
 * Creación y búsqueda de terceros (clientes) en Siigo a partir del pedido.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Customer {

	/**
	 * Mapa departamentos WooCommerce (ISO 3166-2:CO) => código DANE.
	 *
	 * @var array
	 */
	private static $state_to_dane = array(
		'AMA' => '91',
		'ANT' => '05',
		'ARA' => '81',
		'ATL' => '08',
		'BOL' => '13',
		'BOY' => '15',
		'CAL' => '17',
		'CAQ' => '18',
		'CAS' => '85',
		'CAU' => '19',
		'CES' => '20',
		'CHO' => '27',
		'COR' => '23',
		'CUN' => '25',
		'DC'  => '11',
		'GUA' => '94',
		'GUV' => '95',
		'HUI' => '41',
		'LAG' => '44',
		'MAG' => '47',
		'MET' => '50',
		'NAR' => '52',
		'NSA' => '54',
		'PUT' => '86',
		'QUI' => '63',
		'RIS' => '66',
		'SAN' => '68',
		'SAP' => '88',
		'SUC' => '70',
		'TOL' => '73',
		'VAC' => '76',
		'VAU' => '97',
		'VID' => '99',
	);

	/**
	 * Se asegura de que el tercero del pedido exista en Siigo (lo crea si falta).
	 *
	 * @param WC_Order $order Pedido.
	 * @return array{identification: string}|WP_Error
	 */
	public static function find_or_create( $order ) {
		$document = Siigoc_Checkout_Fields::get_document( $order );

		if ( '' === $document['number'] ) {
			return new WP_Error(
				'siigoc_no_document',
				__( 'El pedido no tiene número de documento del cliente. Complétalo en la sección de facturación del pedido y reintenta.', 'siigo-connect' )
			);
		}

		$api = siigoc_api();

		$existing = $api->request( 'GET', '/v1/customers', null, array( 'identification' => $document['number'] ) );
		if ( ! is_wp_error( $existing ) ) {
			$results = isset( $existing['results'] ) && is_array( $existing['results'] ) ? $existing['results'] : array();
			if ( ! empty( $results ) ) {
				return array( 'identification' => $document['number'] );
			}
		}

		$payload = self::build_payload( $order, $document );

		/**
		 * Permite ajustar el payload del tercero antes de enviarlo a Siigo.
		 *
		 * @param array    $payload Payload del cliente.
		 * @param WC_Order $order   Pedido de origen.
		 */
		$payload = apply_filters( 'siigoc_customer_payload', $payload, $order );

		$created = $api->request( 'POST', '/v1/customers', $payload );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		return array( 'identification' => $document['number'] );
	}

	/**
	 * Construye el payload del tercero para la API de Siigo.
	 *
	 * @param WC_Order $order    Pedido.
	 * @param array    $document Tipo y número de documento.
	 * @return array
	 */
	private static function build_payload( $order, $document ) {
		$settings   = siigoc_get_settings();
		$is_company = '31' === $document['type'];
		$company    = trim( (string) $order->get_billing_company() );
		$first      = trim( (string) $order->get_billing_first_name() );
		$last       = trim( (string) $order->get_billing_last_name() );

		if ( $is_company && '' === $company ) {
			// NIT sin razón social: usar el nombre de la persona como razón social.
			$company = trim( $first . ' ' . $last );
		}

		$payload = array(
			'person_type'    => $is_company ? 'Company' : 'Person',
			'id_type'        => $document['type'],
			'identification' => $document['number'],
			'name'           => $is_company ? array( $company ) : array( ( '' !== $first ? $first : '.' ), ( '' !== $last ? $last : '.' ) ),
			'active'         => true,
			'vat_responsible' => false,
			'address'        => array(
				'address' => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
				'city'    => self::resolve_city( $order, $settings ),
			),
			'contacts'       => array(
				array(
					'first_name' => '' !== $first ? $first : ( '' !== $company ? $company : 'Cliente' ),
					'last_name'  => '' !== $last ? $last : '.',
					'email'      => (string) $order->get_billing_email(),
				),
			),
		);

		if ( $is_company ) {
			$payload['check_digit'] = (string) self::nit_check_digit( $document['number'] );
			if ( '' !== $company ) {
				$payload['commercial_name'] = $company;
			}
		}

		$phone = self::sanitize_phone( (string) $order->get_billing_phone() );
		if ( '' !== $phone ) {
			$payload['phones'] = array( array( 'number' => $phone ) );
		}

		return $payload;
	}

	/**
	 * Deja el teléfono como lo acepta Siigo: solo dígitos, sin indicativo +57,
	 * entre 7 y 10 dígitos. Si no cumple se devuelve vacío y no se envía.
	 *
	 * @param string $phone Teléfono tal como lo escribió el cliente.
	 * @return string
	 */
	public static function sanitize_phone( $phone ) {
		$number = preg_replace( '/\D+/', '', $phone );

		if ( strlen( $number ) > 10 && '57' === substr( $number, 0, 2 ) ) {
			$number = substr( $number, 2 );
		}

		$length = strlen( $number );

		return ( $length >= 7 && $length <= 10 ) ? $number : '';
	}

	/**
	 * Resuelve los códigos DANE de la ciudad del pedido.
	 *
	 * Del departamento de Woo se deriva el código DANE; para la ciudad se usa
	 * el código configurado por defecto (Siigo exige código de municipio y no
	 * es derivable del nombre libre que escribe el cliente).
	 *
	 * @param WC_Order $order    Pedido.
	 * @param array    $settings Ajustes del plugin.
	 * @return array
	 */
	private static function resolve_city( $order, $settings ) {
		$state = strtoupper( str_replace( 'CO-', '', (string) $order->get_billing_state() ) );

		$state_code = isset( self::$state_to_dane[ $state ] ) ? self::$state_to_dane[ $state ] : $settings['default_state_code'];
		$city_code  = $settings['default_city_code'];

		// Si el departamento del pedido no coincide con el del código de ciudad
		// por defecto, usar la capital de departamento sería adivinar: se envía
		// el default y se deja el ajuste fino al filtro.
		if ( substr( $city_code, 0, 2 ) !== $state_code ) {
			$state_code = $settings['default_state_code'];
		}

		$city = array(
			'country_code' => 'Co',
			'state_code'   => $state_code,
			'city_code'    => $city_code,
		);

		/**
		 * Permite mapear la ciudad del pedido a códigos DANE propios.
		 *
		 * @param array    $city  country_code / state_code / city_code.
		 * @param WC_Order $order Pedido.
		 */
		return apply_filters( 'siigoc_customer_city', $city, $order );
	}

	/**
	 * Dígito de verificación de un NIT (algoritmo DIAN).
	 *
	 * @param string $nit NIT sin DV.
	 * @return int
	 */
	public static function nit_check_digit( $nit ) {
		$weights = array( 3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71 );
		$digits  = array_reverse( str_split( preg_replace( '/\D/', '', $nit ) ) );

		$sum = 0;
		foreach ( $digits as $index => $digit ) {
			if ( ! isset( $weights[ $index ] ) ) {
				break;
			}
			$sum += (int) $digit * $weights[ $index ];
		}

		$remainder = $sum % 11;

		return $remainder > 1 ? 11 - $remainder : $remainder;
	}
}
