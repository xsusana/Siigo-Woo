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
	const DOC_TYPES_KEY = 'siigoc_document_types';
	const TAXES_KEY     = 'siigoc_taxes_catalog';
	const CATALOG_TTL   = 21600; // 6 horas.

	const TAX_META      = '_siigoc_tax_ids';
	const TAX_META_AT   = '_siigoc_tax_ids_at';
	const TAX_TTL       = 43200; // 12 horas: pasado ese tiempo se vuelve a consultar Siigo.

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

		$document_type = $this->get_document_type( (int) $settings['document_type_id'] );
		if ( is_wp_error( $document_type ) ) {
			return $document_type;
		}

		if ( ! empty( $document_type['cost_center_mandatory'] ) && '' === (string) $settings['cost_center_id'] ) {
			return new WP_Error(
				'siigoc_missing_cost_center',
				__( 'El tipo de comprobante exige centro de costo. Selecciónalo en WooCommerce → Siigo Connect → Facturación.', 'siigo-connect' )
			);
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

		// Comprobantes con "vendedor por ítem": Siigo exige el vendedor en cada línea
		// y rechaza la factura si solo viene en la raíz.
		if ( ! empty( $document_type['seller_by_item'] ) ) {
			unset( $payload['seller'] );
			foreach ( $payload['items'] as $index => $line ) {
				$payload['items'][ $index ]['seller'] = (int) $settings['seller_id'];
			}
		}

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

		$result = siigoc_api()->request( 'POST', '/v1/invoices', $payload );

		if ( self::is_payment_mismatch( $result ) && 1 === count( $payload['payments'] ) ) {
			$result = $this->retry_with_siigo_total( $order, $payload, $result );
		}

		return $result;
	}

	/**
	 * ¿Siigo rechazó la factura porque el pago no coincide con su total?
	 *
	 * @param mixed $result Respuesta de la API.
	 * @return bool
	 */
	private static function is_payment_mismatch( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		$data   = $result->get_error_data();
		$errors = isset( $data['body']['Errors'] ) && is_array( $data['body']['Errors'] ) ? $data['body']['Errors'] : array();

		foreach ( $errors as $error ) {
			if ( isset( $error['Code'] ) && 'invalid_total_payments' === $error['Code'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reintenta con el total que Siigo calcula para las líneas enviadas.
	 *
	 * Con precios con IVA incluido y varias tarifas, al separar base e IVA el
	 * total puede quedar a centavos de lo pagado y Siigo rechaza la factura. Siigo
	 * no documenta cómo redondea, así que se prueban los totales de las formas de
	 * redondeo posibles. Un intento rechazado no crea factura ni llega a la DIAN.
	 *
	 * @param WC_Order $order   Pedido.
	 * @param array    $payload Payload rechazado.
	 * @param WP_Error $error   Error original.
	 * @return array|WP_Error
	 */
	private function retry_with_siigo_total( $order, $payload, $error ) {
		$sent       = (float) $payload['payments'][0]['value'];
		$candidates = $this->total_candidates( $payload['items'] );

		if ( is_wp_error( $candidates ) ) {
			return $error;
		}

		// Solo diferencias de redondeo: nunca más de 1 peso por línea.
		$tolerance = max( 1, count( $payload['items'] ) );

		foreach ( $candidates as $total ) {
			if ( abs( $total - $sent ) < 0.005 || abs( $total - $sent ) > $tolerance ) {
				continue;
			}

			$payload['payments'][0]['value'] = $total;

			$result = siigoc_api()->request( 'POST', '/v1/invoices', $payload );

			if ( ! self::is_payment_mismatch( $result ) ) {
				if ( ! is_wp_error( $result ) ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: total pagado, 2: total de la factura. */
							__( 'Siigo Connect: el pago de la factura se ajustó de %1$s a %2$s por redondeo del IVA en Siigo.', 'siigo-connect' ),
							wc_format_decimal( $sent, 2 ),
							wc_format_decimal( $total, 2 )
						)
					);
				}
				return $result;
			}
		}

		return $this->explain_mismatch( $payload['items'], $error );
	}

	/**
	 * Si el descuadre no es de redondeo, lo más probable es que un producto
	 * tenga retenciones configuradas en Siigo (Siigo las resta del total).
	 *
	 * @param array    $items Ítems enviados.
	 * @param WP_Error $error Error de Siigo.
	 * @return WP_Error
	 */
	private function explain_mismatch( $items, $error ) {
		$rates = $this->tax_rates_by_id();
		if ( is_wp_error( $rates ) ) {
			return $error;
		}

		$codes = array();
		foreach ( $items as $item ) {
			foreach ( isset( $item['taxes'] ) && is_array( $item['taxes'] ) ? $item['taxes'] : array() as $tax ) {
				$tax_id = isset( $tax['id'] ) ? (int) $tax['id'] : 0;
				if ( isset( $rates[ $tax_id ] ) && $rates[ $tax_id ]['withholding'] ) {
					$codes[] = $item['code'];
				}
			}
		}

		if ( empty( $codes ) ) {
			return $error;
		}

		return new WP_Error(
			'siigoc_withholding_mismatch',
			sprintf(
				/* translators: %s: códigos de producto. */
				__( 'El total no cuadra con lo pagado porque estos productos tienen retenciones configuradas en Siigo, que se restan del total de la factura: %s. En ventas de la tienda al consumidor normalmente no aplican; quítalas del producto en Siigo y reintenta.', 'siigo-connect' ),
				implode( ', ', array_unique( $codes ) )
			),
			$error->get_error_data()
		);
	}

	/**
	 * Totales posibles de la factura según cómo redondee Siigo el IVA.
	 *
	 * @param array $items Ítems enviados.
	 * @return float[]|WP_Error
	 */
	private function total_candidates( $items ) {
		$rates = $this->tax_rates_by_id();
		if ( is_wp_error( $rates ) ) {
			return $rates;
		}

		$totals = array();

		// Medio centavo hacia arriba, o al par (redondeo bancario).
		foreach ( array( PHP_ROUND_HALF_UP, PHP_ROUND_HALF_EVEN ) as $mode ) {
			$exact     = 0.0; // Sin redondeos intermedios.
			$per_line  = 0.0; // Base e impuesto redondeados por línea.
			$per_tax   = 0.0; // Base por línea; impuesto redondeado por tarifa.
			$tax_bases = array();

			foreach ( $items as $item ) {
				$raw_base = (float) $item['price'] * (float) $item['quantity'];
				$base     = self::round_money( $raw_base, $mode );

				$exact    += $raw_base;
				$per_line += $base;
				$per_tax  += $base;

				$tax_list = isset( $item['taxes'] ) && is_array( $item['taxes'] ) ? $item['taxes'] : array();

				foreach ( $tax_list as $tax ) {
					$tax_id = isset( $tax['id'] ) ? (int) $tax['id'] : 0;
					if ( ! isset( $rates[ $tax_id ] ) || $rates[ $tax_id ]['withholding'] ) {
						continue;
					}

					$exact    += $raw_base * $rates[ $tax_id ]['rate'] / 100;
					$per_line += self::round_money( $base * $rates[ $tax_id ]['rate'] / 100, $mode );

					$tax_bases[ $tax_id ] = ( isset( $tax_bases[ $tax_id ] ) ? $tax_bases[ $tax_id ] : 0 ) + $base;
				}
			}

			foreach ( $tax_bases as $tax_id => $tax_base ) {
				$per_tax += self::round_money( $tax_base * $rates[ $tax_id ]['rate'] / 100, $mode );
			}

			foreach ( array( $exact, $per_line, $per_tax ) as $total ) {
				$totals[] = self::round_money( $total, $mode );
			}
		}

		return array_values( array_unique( $totals, SORT_REGULAR ) );
	}

	/**
	 * Redondeo a centavos sin el ruido de los flotantes (41.345 no debe
	 * convertirse en 41.34 por venir como 41.344999999).
	 *
	 * @param float $value Valor.
	 * @param int   $mode  PHP_ROUND_HALF_UP o PHP_ROUND_HALF_EVEN.
	 * @return float
	 */
	private static function round_money( $value, $mode = PHP_ROUND_HALF_UP ) {
		return round( round( $value, 6 ), 2, $mode );
	}

	/**
	 * Tarifas del catálogo de impuestos de Siigo.
	 *
	 * @return array<int, array{rate: float, withholding: bool}>|WP_Error
	 */
	private function tax_rates_by_id() {
		$catalog = self::get_catalog( self::TAXES_KEY, 'get_taxes' );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$rates = array();
		foreach ( $catalog as $tax ) {
			if ( ! isset( $tax['id'] ) ) {
				continue;
			}

			$type = isset( $tax['type'] ) ? strtolower( (string) $tax['type'] ) : '';

			$rates[ (int) $tax['id'] ] = array(
				'rate'        => isset( $tax['percentage'] ) ? (float) $tax['percentage'] : 0.0,
				// Las retenciones las descuenta quien compra: no forman parte del
				// precio que pagó el cliente.
				'withholding' => false !== strpos( $type, 'rete' ) || false !== strpos( $type, 'autorre' ) || false !== strpos( $type, 'autore' ),
			);
		}

		return $rates;
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

			$quantity = round( max( 1, (float) $item->get_quantity() ), 2 );
			// Precio unitario con descuentos aplicados; sin IVA si WooCommerce lo calculó.
			$price = (float) $order->get_item_total( $item, false, false );

			// Impuestos del propio producto en Siigo (IVA 19%, 5%, exento, etc.).
			$product_taxes = $this->get_product_tax_ids( (string) $sku, $product );

			if ( null === $product_taxes && (float) $item->get_total_tax() <= 0 ) {
				// Precio con IVA incluido y sin saber qué IVA lleva: facturar así saldría
				// sin impuestos ante la DIAN. Mejor fallar y reintentar.
				return self::unknown_taxes_error( (string) $sku, $item->get_name() );
			}

			$price = $this->net_price( $price, (float) $item->get_total_tax(), $product_taxes );
			if ( is_wp_error( $price ) ) {
				return $price;
			}

			$line = array(
				'code'        => (string) $sku,
				'description' => wp_strip_all_tags( $item->get_name() ),
				'quantity'    => $quantity,
				'price'       => $price,
			);

			if ( is_array( $product_taxes ) ) {
				// Se conoce la configuración del producto en Siigo: se respeta tal cual
				// (lista vacía = producto sin impuestos, p. ej. excluido).
				foreach ( $product_taxes as $product_tax_id ) {
					$line['taxes'][] = array( 'id' => $product_tax_id );
				}
			} elseif ( $tax_id > 0 && (float) $item->get_total_tax() > 0 ) {
				// No se pudo consultar el producto: impuesto por defecto como respaldo.
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

			$shipping_taxes = $this->get_product_tax_ids( (string) $settings['shipping_sku'], null );

			if ( null === $shipping_taxes && (float) $order->get_shipping_tax() <= 0 ) {
				return self::unknown_taxes_error( (string) $settings['shipping_sku'], __( 'Costo de envío', 'siigo-connect' ) );
			}

			$shipping_price = $this->net_price( $shipping_total, (float) $order->get_shipping_tax(), $shipping_taxes );
			if ( is_wp_error( $shipping_price ) ) {
				return $shipping_price;
			}

			$shipping_line = array(
				'code'        => (string) $settings['shipping_sku'],
				'description' => __( 'Costo de envío', 'siigo-connect' ),
				'quantity'    => 1,
				'price'       => $shipping_price,
			);

			if ( is_array( $shipping_taxes ) ) {
				foreach ( $shipping_taxes as $shipping_tax_id ) {
					$shipping_line['taxes'][] = array( 'id' => $shipping_tax_id );
				}
			} elseif ( $tax_id > 0 && (float) $order->get_shipping_tax() > 0 ) {
				$shipping_line['taxes'] = array( array( 'id' => $tax_id ) );
			}

			$items[] = $shipping_line;
		}

		return $items;
	}

	/**
	 * Error cuando no se pudieron leer los impuestos de un producto en Siigo.
	 *
	 * @param string $sku  Código del producto.
	 * @param string $name Nombre para el mensaje.
	 * @return WP_Error
	 */
	private static function unknown_taxes_error( $sku, $name ) {
		return new WP_Error(
			'siigoc_unknown_product_taxes',
			sprintf(
				/* translators: 1: nombre del producto, 2: código. */
				__( 'No se pudo consultar en Siigo el IVA de "%1$s" (código %2$s). Verifica que el producto exista en Siigo con ese código; si existe, fue un fallo temporal y se reintentará.', 'siigo-connect' ),
				$name,
				$sku
			)
		);
	}

	/**
	 * Precio unitario sin IVA que se envía a Siigo.
	 *
	 * Si WooCommerce ya separó el impuesto de la línea, el precio recibido es la
	 * base. Si no (impuestos desactivados en la tienda o producto sin clase de
	 * impuesto) el cliente pagó el precio final: se le quita el IVA/impoconsumo
	 * que el producto tiene en Siigo para que el total de la factura coincida con
	 * lo pagado. Se envían 6 decimales (máximo que acepta Siigo) para que el total
	 * no se desvíe por redondeo al multiplicar por la cantidad.
	 *
	 * @param float      $price    Precio unitario tomado de WooCommerce.
	 * @param float      $woo_tax  Impuesto que WooCommerce calculó para la línea.
	 * @param int[]|null $tax_ids  Impuestos del producto en Siigo (null = desconocidos).
	 * @return float|WP_Error
	 */
	private function net_price( $price, $woo_tax, $tax_ids ) {
		if ( $woo_tax <= 0 && is_array( $tax_ids ) && ! empty( $tax_ids ) ) {
			$rate = $this->included_tax_rate( $tax_ids );
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
			if ( $rate > 0 ) {
				$price = $price / ( 1 + $rate / 100 );
			}
		}

		return round( $price, 6 );
	}

	/**
	 * Porcentaje total de los impuestos que van incluidos en el precio al
	 * consumidor (IVA e impoconsumo), según el catálogo de impuestos de Siigo.
	 *
	 * @param int[] $tax_ids IDs de impuestos de Siigo.
	 * @return float|WP_Error
	 */
	private function included_tax_rate( $tax_ids ) {
		$rates = $this->tax_rates_by_id();
		if ( is_wp_error( $rates ) ) {
			return $rates;
		}

		$rate = 0.0;
		foreach ( $tax_ids as $tax_id ) {
			if ( ! isset( $rates[ (int) $tax_id ] ) ) {
				delete_transient( self::TAXES_KEY ); // Puede ser un impuesto recién creado.
				return new WP_Error(
					'siigoc_unknown_tax',
					sprintf(
						/* translators: %d: ID del impuesto. */
						__( 'El impuesto con ID %d no aparece en el catálogo de impuestos de Siigo.', 'siigo-connect' ),
						(int) $tax_id
					)
				);
			}

			// Todo impuesto porcentual va incluido en el precio al consumidor (IVA de
			// cualquier tarifa, impoconsumo, ICUI...), salvo las retenciones.
			if ( ! $rates[ (int) $tax_id ]['withholding'] ) {
				$rate += $rates[ (int) $tax_id ]['rate'];
			}
		}

		return $rate;
	}

	/**
	 * Configuración del tipo de comprobante en Siigo (vendedor por ítem, centro
	 * de costo obligatorio, etc.).
	 *
	 * @param int $document_type_id ID del comprobante.
	 * @return array|WP_Error
	 */
	private function get_document_type( $document_type_id ) {
		$catalog = self::get_catalog( self::DOC_TYPES_KEY, 'get_document_types' );
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		foreach ( $catalog as $type ) {
			if ( isset( $type['id'] ) && (int) $type['id'] === $document_type_id ) {
				return $type;
			}
		}

		delete_transient( self::DOC_TYPES_KEY );

		return new WP_Error(
			'siigoc_unknown_document_type',
			__( 'El tipo de comprobante configurado no existe en Siigo. Vuelve a seleccionarlo en WooCommerce → Siigo Connect → Facturación.', 'siigo-connect' )
		);
	}

	/**
	 * Lista de la API (comprobantes, impuestos) cacheada unas horas.
	 *
	 * @param string $cache_key Transient.
	 * @param string $method    Método de Siigoc_Api_Client.
	 * @return array|WP_Error
	 */
	private static function get_catalog( $cache_key, $method ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$result = siigoc_api()->$method();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Algunos endpoints devuelven { results: [...] }, otros la lista directa.
		$list = isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : $result;

		if ( ! empty( $list ) ) {
			set_transient( $cache_key, $list, self::CATALOG_TTL );
		}

		return $list;
	}

	/**
	 * Borra los catálogos cacheados (al cambiar ajustes o credenciales).
	 */
	public static function flush_catalogs() {
		delete_transient( self::DOC_TYPES_KEY );
		delete_transient( self::TAXES_KEY );
	}

	/**
	 * IDs de los impuestos configurados en Siigo para un producto.
	 *
	 * Orden de resolución: meta guardada por la sincronización → caché temporal →
	 * consulta a la API (y se cachea). Devuelve null si no se pudo determinar
	 * (producto inexistente en Siigo o error de red): en ese caso el llamador
	 * decide el respaldo.
	 *
	 * @param string          $sku     SKU / código del producto.
	 * @param WC_Product|null $product Producto de WooCommerce, si existe.
	 * @return int[]|null
	 */
	private function get_product_tax_ids( $sku, $product ) {
		if ( $product ) {
			$meta    = $product->get_meta( self::TAX_META );
			$meta_at = (int) $product->get_meta( self::TAX_META_AT );
			// Solo si es reciente: si cambiaron el IVA del producto en Siigo, se nota en horas.
			if ( is_array( $meta ) && ( time() - $meta_at ) < self::TAX_TTL ) {
				return array_map( 'intval', $meta );
			}
		}

		$cache_key = 'siigoc_taxes_' . md5( $sku );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$response = siigoc_api()->get_product_by_code( $sku );
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$results = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
		if ( empty( $results ) ) {
			return null; // El producto no existe en Siigo con ese código.
		}

		$ids = self::extract_tax_ids( $results[0] );

		set_transient( $cache_key, $ids, self::TAX_TTL );

		if ( $product ) {
			$product->update_meta_data( self::TAX_META, $ids );
			$product->update_meta_data( self::TAX_META_AT, time() );
			$product->save();
		}

		return $ids;
	}

	/**
	 * Extrae los IDs de impuestos de un producto según la API de Siigo.
	 *
	 * @param array $siigo_product Producto de la API.
	 * @return int[]
	 */
	public static function extract_tax_ids( $siigo_product ) {
		$ids = array();

		if ( isset( $siigo_product['taxes'] ) && is_array( $siigo_product['taxes'] ) ) {
			foreach ( $siigo_product['taxes'] as $tax ) {
				if ( isset( $tax['id'] ) ) {
					$ids[] = (int) $tax['id'];
				}
			}
		}

		return $ids;
	}
}
