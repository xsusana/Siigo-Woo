<?php
/**
 * Sincronización de productos, precios e inventario: Siigo → WooCommerce.
 *
 * Recorre el catálogo de Siigo por páginas; si una corrida se alarga, programa
 * su continuación como evento único para no agotar el tiempo de ejecución.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Product_Sync {

	const HOOK_CRON     = 'siigoc_sync_cron';
	const HOOK_CONTINUE = 'siigoc_sync_continue';
	const LAST_RUN_OPT  = 'siigoc_last_sync';
	const PAGE_SIZE     = 100;
	const TIME_BUDGET   = 20; // Segundos por corrida antes de continuar en otro evento.

	public function __construct() {
		add_filter( 'cron_schedules', array( $this, 'register_interval' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( 'update_option_siigoc_settings', array( $this, 'reschedule' ), 10, 0 );

		add_action( self::HOOK_CRON, array( $this, 'run' ) );
		add_action( self::HOOK_CONTINUE, array( $this, 'run' ), 10, 2 );

		add_action( 'admin_post_siigoc_sync_now', array( $this, 'handle_sync_now' ) );
	}

	/**
	 * Intervalo de 15 minutos para el cron.
	 *
	 * @param array $schedules Intervalos registrados.
	 * @return array
	 */
	public function register_interval( $schedules ) {
		$schedules['siigoc_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Cada 15 minutos (Siigo Connect)', 'siigo-connect' ),
		);

		return $schedules;
	}

	/**
	 * ¿Hay algo que sincronizar según los ajustes?
	 *
	 * @return bool
	 */
	private function is_enabled() {
		$settings = siigoc_get_settings();
		return 'yes' === $settings['sync_products'] || 'yes' === $settings['sync_stock'];
	}

	/**
	 * Mantiene el evento recurrente alineado con los ajustes.
	 */
	public function ensure_scheduled() {
		$scheduled = wp_next_scheduled( self::HOOK_CRON );

		if ( $this->is_enabled() && ! $scheduled ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, siigoc_get_settings()['sync_interval'], self::HOOK_CRON );
		} elseif ( ! $this->is_enabled() && $scheduled ) {
			wp_unschedule_hook( self::HOOK_CRON );
		}
	}

	/**
	 * Al guardar ajustes: reprogramar con el intervalo nuevo.
	 */
	public function reschedule() {
		wp_unschedule_hook( self::HOOK_CRON );
		if ( $this->is_enabled() ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, siigoc_get_settings()['sync_interval'], self::HOOK_CRON );
		}
	}

	/**
	 * Botón "Sincronizar ahora" de los ajustes.
	 */
	public function handle_sync_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'siigo-connect' ) );
		}
		check_admin_referer( 'siigoc_sync_now' );

		$this->run();

		wp_safe_redirect(
			siigoc_admin_url(
				'siigo-connect',
				array(
					'tab'    => 'sync',
					'synced' => '1',
				)
			)
		);
		exit;
	}

	/**
	 * Corrida de sincronización.
	 *
	 * @param int        $page  Página inicial (para continuaciones).
	 * @param array|null $carry Contadores acumulados de la corrida anterior.
	 */
	public function run( $page = 1, $carry = null ) {
		if ( ! $this->is_enabled() || ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$settings = siigoc_get_settings();
		$api      = siigoc_api();
		$start    = time();

		$stats = is_array( $carry ) ? $carry : array(
			'updated' => 0,
			'created' => 0,
			'skipped' => 0,
			'errors'  => 0,
		);

		$page = max( 1, (int) $page );

		while ( true ) {
			$response = $api->get_products( $page, self::PAGE_SIZE );

			if ( is_wp_error( $response ) ) {
				$stats['errors']++;
				Siigoc_Logger::log( 'error', 'SYNC', 'products', null, $response->get_error_message(), 0 );
				break;
			}

			$products = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : array();
			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $siigo_product ) {
				$this->process_product( $siigo_product, $settings, $stats );
			}

			$total       = isset( $response['pagination']['total_results'] ) ? (int) $response['pagination']['total_results'] : 0;
			$total_pages = $total > 0 ? (int) ceil( $total / self::PAGE_SIZE ) : $page;

			if ( $page >= $total_pages ) {
				break;
			}

			$page++;

			// Presupuesto de tiempo agotado: continuar en un evento nuevo.
			if ( ( time() - $start ) > self::TIME_BUDGET ) {
				wp_schedule_single_event( time() + 30, self::HOOK_CONTINUE, array( $page, $stats ) );
				update_option(
					self::LAST_RUN_OPT,
					array(
						'time'      => time(),
						'stats'     => $stats,
						'partial'   => true,
						'next_page' => $page,
					),
					false
				);
				Siigoc_Logger::log( 'info', 'SYNC', 'products', null, sprintf( 'Corrida parcial, continúa en página %d.', $page ), 0 );
				return;
			}
		}

		update_option(
			self::LAST_RUN_OPT,
			array(
				'time'    => time(),
				'stats'   => $stats,
				'partial' => false,
			),
			false
		);

		Siigoc_Logger::log(
			'info',
			'SYNC',
			'products',
			null,
			sprintf(
				'Sincronización terminada: %d actualizados, %d creados, %d omitidos, %d errores.',
				$stats['updated'],
				$stats['created'],
				$stats['skipped'],
				$stats['errors']
			),
			0
		);
	}

	/**
	 * Aplica un producto de Siigo sobre WooCommerce.
	 *
	 * @param array $siigo_product Producto según la API de Siigo.
	 * @param array $settings      Ajustes del plugin.
	 * @param array $stats         Contadores (por referencia).
	 */
	private function process_product( $siigo_product, $settings, &$stats ) {
		$code = isset( $siigo_product['code'] ) ? (string) $siigo_product['code'] : '';
		if ( '' === $code ) {
			$stats['skipped']++;
			return;
		}

		$price = null;
		if ( isset( $siigo_product['prices'][0]['price_list'][0]['value'] ) ) {
			$price = (float) $siigo_product['prices'][0]['price_list'][0]['value'];
		}

		$quantity = isset( $siigo_product['available_quantity'] ) ? (float) $siigo_product['available_quantity'] : null;

		$product_id = wc_get_product_id_by_sku( $code );
		$tax_ids    = Siigoc_Invoice::extract_tax_ids( $siigo_product );

		if ( $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				$stats['skipped']++;
				return;
			}

			$changed = false;

			// Impuestos del producto en Siigo, para que la facturación los use sin consultar la API.
			if ( $product->get_meta( Siigoc_Invoice::TAX_META ) !== $tax_ids ) {
				$product->update_meta_data( Siigoc_Invoice::TAX_META, $tax_ids );
				$changed = true;
			}
			$product->update_meta_data( Siigoc_Invoice::TAX_META_AT, time() );

			if ( 'yes' === $settings['sync_products'] && null !== $price ) {
				$product->set_regular_price( (string) $price );
				$changed = true;
			}

			if ( 'yes' === $settings['sync_stock'] && null !== $quantity ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $quantity );
				$changed = true;
			}

			if ( $changed ) {
				$product->save();
				$stats['updated']++;
			} else {
				$product->save_meta_data(); // Solo la fecha de verificación de impuestos.
				$stats['skipped']++;
			}

			return;
		}

		// Producto nuevo: crearlo solo si está habilitado y el producto está activo en Siigo.
		if ( 'yes' !== $settings['sync_create_products'] || empty( $siigo_product['active'] ) ) {
			$stats['skipped']++;
			return;
		}

		$product = new WC_Product_Simple();
		$product->set_name( isset( $siigo_product['name'] ) ? (string) $siigo_product['name'] : $code );
		$product->set_sku( $code );
		$product->update_meta_data( Siigoc_Invoice::TAX_META, $tax_ids );
		$product->update_meta_data( Siigoc_Invoice::TAX_META_AT, time() );
		// Se crean como borrador para que el tendero revise antes de publicar.
		$product->set_status( apply_filters( 'siigoc_new_product_status', 'draft' ) );

		if ( null !== $price ) {
			$product->set_regular_price( (string) $price );
		}

		if ( 'yes' === $settings['sync_stock'] && null !== $quantity ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $quantity );
		}

		$product->save();
		$stats['created']++;
	}
}
