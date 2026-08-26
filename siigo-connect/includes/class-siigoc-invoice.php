<?php
/**
 * Facturación: convierte pedidos de WooCommerce en facturas de Siigo,
 * con procesamiento en segundo plano y reintentos con backoff.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Invoice {

	const HOOK          = 'siigoc_process_invoice';
	const META_ID       = '_siigoc_invoice_id';
	const META_NUMBER   = '_siigoc_invoice_number';
	const META_NAME     = '_siigoc_invoice_name';
	const META_ERROR    = '_siigoc_invoice_error';
	const META_QUEUED   = '_siigoc_invoice_queued';
	const MAX_ATTEMPTS  = 5;

	/**
	 * Espera en segundos antes de cada reintento (índice = intento fallido).
	 *
	 * @var int[]
	 */
	private static $backoff = array( 300, 900, 3600, 21600, 86400 );

	public function __construct() {
		add_action( 'woocommerce_payment_complete', array( $this, 'maybe_queue_on_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'maybe_queue_on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_queue_on_completed' ) );
		add_action( self::HOOK, array( $this, 'process' ), 10, 2 );
	}

	/**
	 * Disparador "al pagarse".
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function maybe_queue_on_paid( $order_id ) {
		$settings = siigoc_get_settings();
		if ( 'paid' === $settings['trigger'] ) {
			self::queue( $order_id );
		}
	}

	/**
	 * Disparador "al completarse".
	 *
	 * @param int $order_id ID del pedido.
	 */
	public function maybe_queue_on_completed( $order_id ) {
		$settings = siigoc_get_settings();
		if ( 'completed' === $settings['trigger'] ) {
			self::queue( $order_id );
		}
	}

	/**
	 * Encola la facturación del pedido (se procesa por cron en segundos).
	 *
	 * @param int $order_id ID del pedido.
	 */
	public static function queue( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::META_ID ) ) {
			return; // Ya facturado.
		}

		if ( 'yes' === (string) $order->get_meta( self::META_QUEUED ) ) {
			return; // Ya en cola.
		}

		$order->update_meta_data( self::META_QUEUED, 'yes' );
		$order->save();

		wp_schedule_single_event( time() + 5, self::HOOK, array( (int) $order_id, 1 ) );

		$order->add_order_note( __( 'Siigo Connect: factura encolada para envío a Siigo.', 'siigo-connect' ) );
	}

	/**
	 * Procesa la creación de la factura (handler del cron y del botón manual).
	 *
	 * @param int $order_id ID del pedido.
	 * @param int $attempt  Número de intento (desde 1).
	 * @return true|WP_Error
	 */
	public function process( $order_id, $attempt = 1 ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'siigoc_no_order', __( 'Pedido no encontrado.', 'siigo-connect' ) );
		}

		if ( '' !== (string) $order->get_meta( self::META_ID ) ) {
			return true; // Ya facturado.
		}

		// Bloqueo contra ejecuciones concurrentes (cron + clic manual).
		$lock_key = 'siigoc_lock_' . $order_id;
		if ( get_transient( $lock_key ) ) {
			return new WP_Error( 'siigoc_locked', __( 'La factura de este pedido ya se está procesando.', 'siigo-connect' ) );
		}
		set_transient( $lock_key, 1, 60 );

		$result = $this->create( $order );

		delete_transient( $lock_key );

		if ( is_wp_error( $result ) ) {
			$this->handle_failure( $order, $result, (int) $attempt );
			return $result;
		}

		$order->update_meta_data( self::META_ID, isset( $result['id'] ) ? $result['id'] : 'ok' );
		$order->update_meta_data( self::META_NUMBER, isset( $result['number'] ) ? $result['number'] : '' );
		$order->update_meta_data( self::META_NAME, isset( $result['name'] ) ? $result['name'] : '' );
		$order->delete_meta_data( self::META_ERROR );
		$order->delete_meta_data( self::META_QUEUED );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: %s: número de la factura en Siigo. */
				__( 'Siigo Connect: factura creada en Siigo (%s).', 'siigo-connect' ),
				isset( $result['name'] ) && '' !== $result['name'] ? $result['name'] : ( isset( $result['number'] ) ? $result['number'] : '' )
			)
		);

		return true;
	}

	/**
	 * Registra el error y programa el siguiente reintento si quedan intentos.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param WP_Error $error   Error ocurrido.
	 * @param int      $attempt Intento actual.
	 */
	private function handle_failure( $order, $error, $attempt ) {
		$order->update_meta_data( self::META_ERROR, $error->get_error_message() );

		if ( $attempt < self::MAX_ATTEMPTS ) {
			$delay = self::$backoff[ min( $attempt - 1, count( self::$backoff ) - 1 ) ];
			wp_schedule_single_event( time() + $delay, self::HOOK, array( (int) $order->get_id(), $attempt + 1 ) );

			$order->add_order_note(
				sprintf(
					/* translators: 1: mensaje de error, 2: intento, 3: máximo de intentos, 4: minutos hasta el reintento. */
					__( 'Siigo Connect: error al facturar — %1$s (intento %2$d de %3$d, se reintentará en ~%4$d min).', 'siigo-connect' ),
					$error->get_error_message(),
					$attempt,
					self::MAX_ATTEMPTS,
					(int) ceil( $delay / 60 )
				)
			);
		} else {
			$order->delete_meta_data( self::META_QUEUED );
			$order->add_order_note(
				sprintf(
					/* translators: %s: mensaje de error. */
					__( 'Siigo Connect: facturación agotó los reintentos — %s. Corrige el problema y usa "Enviar a Siigo" en el pedido.', 'siigo-connect' ),
					$error->get_error_message()
				)
			);
		}

		$order->save();
	}

	/**
	 * Crea la factura en Siigo.
	 *
	 * @param WC_Order $order Pedido.
	 * @return array|WP_Error Respuesta de Siigo o error.
	 */
	private function create( $order ) {
		$settings = siigoc_get_settings();

		foreach ( array( 'document_type_id', 'seller_id', 'payment_type_id' ) as $required ) {
			if ( '' === (string) $settings[ $required ] ) {
				return new WP_Error(
					'siigoc_missing_settings',
					__( 'Faltan ajustes de facturación (tipo de comprobante, vendedor o forma de pago). Configúralos en WooCommerce → Siigo Connect → Facturación.', 'siigo-connect' )
				);
			}
		}

		$customer = Siigoc_Customer::find_or_create( $order );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}

		$items = $this->build_items( $order, $settings );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$payload = array(
			'document'     => array( 'id' => (int) $settings['document_type_id'] ),
			'date'         => current_time( 'Y-m-d' ),
			'customer'     => array(
				'identification' => $customer['identification'],
				'branch_office'  => 0,
			),
			'seller'       => (int) $settings['seller_id'],
			'observations' => sprintf(
				/* translators: %s: número del pedido. */
				__( 'Pedido WooCommerce #%s', 'siigo-connect' ),
				$order->get_order_number()
			),
			'items'        => $items,
			'payments'     => array(
				array(
					'id'       => (int) $settings['payment_type_id'],
					'value'    => round( (float) $order->get_total(), 2 ),
					'due_date' => current_time( 'Y-m-d' ),
				),
			),
		);

		if ( '' !== (string) $settings['cost_center_id'] ) {
			$payload['cost_center'] = (int) $settings['cost_center_id'];
		}

		if ( 'yes' === $settings['send_dian'] ) {
			$payload['stamp'] = array( 'send' => true );
		}

		if ( 'yes' === $settings['send_email'] ) {
			$payload['mail'] = array( 'send' => true );
		}

		/**
		 * Permite ajustar el payload de la factura antes de enviarlo a Siigo.
		 *
		 * @param array    $payload Payload de la factura.
		 * @param WC_Order $order   Pedido de origen.
		 */
		$payload = apply_filters( 'siigoc_invoice_payload', $payload, $order );

		return siigoc_api()->request( 'POST', '/v1/invoices', $payload );
	}

	/**
	 * Convierte las líneas del pedido en ítems de la factura.
	 *
	 * @param WC_Order $order    Pedido.
	 * @param array    $settings Ajustes.
	 * @return array|WP_Error
	 */
	private function build_items( $order, $settings ) {
		$items  = array();
		$tax_id = '' !== (string) $settings['tax_id'] ? (int) $settings['tax_id'] : 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$sku     = $product ? $product->get_sku() : '';

			if ( '' === (string) $sku ) {
				return new WP_Error(
					'siigoc_missing_sku',
					sprintf(
						/* translators: %s: nombre del producto. */
						__( 'El producto "%s" no tiene SKU. Siigo empareja por SKU/código: asígnale el mismo código que tiene en Siigo.', 'siigo-connect' ),
						$item->get_name()
					)
				);
			}

			$quantity = max( 1, (float) $item->get_quantity() );
			// Precio unitario sin IVA y con descuentos ya aplicados.
			$price = round( (float) $order->get_item_total( $item, false, false ), 2 );

			$line = array(
				'code'        => (string) $sku,
				'description' => wp_strip_all_tags( $item->get_name() ),
				'quantity'    => $quantity,
				'price'       => $price,
			);

			if ( $tax_id > 0 && (float) $item->get_total_tax() > 0 ) {
				$line['taxes'] = array( array( 'id' => $tax_id ) );
			}

			$items[] = $line;
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'siigoc_empty_order', __( 'El pedido no tiene productos para facturar.', 'siigo-connect' ) );
		}

		// Envío como ítem adicional (requiere un producto/servicio creado en Siigo).
		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			if ( '' === (string) $settings['shipping_sku'] ) {
				return new WP_Error(
					'siigoc_missing_shipping_sku',
					__( 'El pedido tiene costo de envío pero no hay un código de producto de envío configurado. Créalo en Siigo (ej. "ENVIO") y configúralo en Facturación.', 'siigo-connect' )
				);
			}

			$shipping_line = array(
				'code'        => (string) $settings['shipping_sku'],
				'description' => __( 'Costo de envío', 'siigo-connect' ),
				'quantity'    => 1,
				'price'       => round( $shipping_total, 2 ),
			);

			if ( $tax_id > 0 && (float) $order->get_shipping_tax() > 0 ) {
				$shipping_line['taxes'] = array( array( 'id' => $tax_id ) );
			}

			$items[] = $shipping_line;
		}

		return $items;
	}
}
