<?php
/**
 * Campos de checkout para Colombia: tipo y número de documento.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Checkout_Fields {

	/**
	 * Tipos de documento DIAN soportados (código Siigo => etiqueta).
	 *
	 * @return array
	 */
	public static function document_types() {
		return array(
			'13' => __( 'Cédula de ciudadanía', 'siigo-connect' ),
			'31' => __( 'NIT', 'siigo-connect' ),
			'22' => __( 'Cédula de extranjería', 'siigo-connect' ),
			'41' => __( 'Pasaporte', 'siigo-connect' ),
			'42' => __( 'Documento de identificación extranjero', 'siigo-connect' ),
		);
	}

	public function __construct() {
		// Checkout clásico (shortcode).
		add_filter( 'woocommerce_billing_fields', array( $this, 'add_billing_fields' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_fields' ), 10, 2 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );

		// Checkout por bloques (Woo 8.9+).
		add_action( 'woocommerce_init', array( $this, 'register_block_fields' ) );

		// Mostrar/editar en el admin del pedido.
		add_filter( 'woocommerce_admin_billing_fields', array( $this, 'admin_billing_fields' ) );
	}

	/**
	 * Campos en el checkout clásico, después de la empresa.
	 *
	 * @param array $fields Campos de facturación.
	 * @return array
	 */
	public function add_billing_fields( $fields ) {
		$fields['billing_siigoc_id_type'] = array(
			'type'     => 'select',
			'label'    => __( 'Tipo de documento', 'siigo-connect' ),
			'required' => true,
			'class'    => array( 'form-row-first' ),
			'options'  => self::document_types(),
			'default'  => '13',
			'priority' => 31,
		);

		$fields['billing_siigoc_id_number'] = array(
			'type'     => 'text',
			'label'    => __( 'Número de documento', 'siigo-connect' ),
			'required' => true,
			'class'    => array( 'form-row-last' ),
			'priority' => 32,
		);

		return $fields;
	}

	/**
	 * Campos en el checkout por bloques, si la API existe.
	 */
	public function register_block_fields() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$options = array();
		foreach ( self::document_types() as $value => $label ) {
			$options[] = array(
				'value' => $value,
				'label' => $label,
			);
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'siigo-connect/id-type',
				'label'    => __( 'Tipo de documento', 'siigo-connect' ),
				'location' => 'address',
				'type'     => 'select',
				'required' => true,
				'options'  => $options,
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'siigo-connect/id-number',
				'label'    => __( 'Número de documento', 'siigo-connect' ),
				'location' => 'address',
				'type'     => 'text',
				'required' => true,
			)
		);
	}

	/**
	 * Validación en el checkout clásico.
	 *
	 * @param array    $data   Datos enviados.
	 * @param WP_Error $errors Errores acumulados.
	 */
	public function validate( $data, $errors ) {
		$number = isset( $_POST['billing_siigoc_id_number'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['billing_siigoc_id_number'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' !== $number && ! preg_match( '/^[0-9]{4,15}$/', str_replace( array( '.', '-', ' ' ), '', $number ) ) ) {
			$errors->add( 'siigoc_id_number', __( 'El número de documento solo debe contener dígitos (sin puntos ni el dígito de verificación).', 'siigo-connect' ) );
		}
	}

	/**
	 * Copia de respaldo de los campos al pedido (Woo ya guarda los billing_* como meta).
	 *
	 * @param WC_Order $order Pedido.
	 * @param array    $data  Datos del checkout.
	 */
	public function save_fields( $order, $data ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['billing_siigoc_id_type'] ) ) {
			$order->update_meta_data( '_billing_siigoc_id_type', sanitize_text_field( wp_unslash( $_POST['billing_siigoc_id_type'] ) ) );
		}
		if ( isset( $_POST['billing_siigoc_id_number'] ) ) {
			$order->update_meta_data( '_billing_siigoc_id_number', sanitize_text_field( wp_unslash( $_POST['billing_siigoc_id_number'] ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Campos editables en la sección de facturación del pedido en el admin.
	 *
	 * @param array $fields Campos del admin.
	 * @return array
	 */
	public function admin_billing_fields( $fields ) {
		$fields['siigoc_id_type'] = array(
			'label'   => __( 'Tipo de documento', 'siigo-connect' ),
			'type'    => 'select',
			'options' => array( '' => '—' ) + self::document_types(),
			'show'    => false,
		);
		$fields['siigoc_id_number'] = array(
			'label' => __( 'Número de documento', 'siigo-connect' ),
			'show'  => false,
		);

		return $fields;
	}

	/**
	 * Lee tipo y número de documento de un pedido (checkout clásico o por bloques).
	 *
	 * @param WC_Order $order Pedido.
	 * @return array{type: string, number: string}
	 */
	public static function get_document( $order ) {
		$type   = (string) $order->get_meta( '_billing_siigoc_id_type' );
		$number = (string) $order->get_meta( '_billing_siigoc_id_number' );

		// Claves del checkout por bloques.
		if ( '' === $number ) {
			$type   = (string) $order->get_meta( '_wc_billing/siigo-connect/id-type' );
			$number = (string) $order->get_meta( '_wc_billing/siigo-connect/id-number' );
		}

		$number = str_replace( array( '.', '-', ' ' ), '', $number );

		if ( '' === $type ) {
			$type = '13';
		}

		return array(
			'type'   => $type,
			'number' => $number,
		);
	}
}
