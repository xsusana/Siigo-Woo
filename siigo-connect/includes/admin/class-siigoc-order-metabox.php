<?php
/**
 * Caja de estado de facturación Siigo en la pantalla del pedido.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Order_Metabox {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		add_action( 'admin_post_siigoc_invoice_now', array( $this, 'handle_invoice_now' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	/**
	 * Registra la caja tanto en la pantalla clásica como en la de HPOS.
	 */
	public function add_metabox() {
		$screens = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'siigoc-order-box',
				__( 'Siigo Connect', 'siigo-connect' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * @param WP_Post|WC_Order $post_or_order Objeto de la pantalla.
	 */
	public function render( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$invoice_id   = (string) $order->get_meta( Siigoc_Invoice::META_ID );
		$invoice_name = (string) $order->get_meta( Siigoc_Invoice::META_NAME );
		$number       = (string) $order->get_meta( Siigoc_Invoice::META_NUMBER );
		$error        = (string) $order->get_meta( Siigoc_Invoice::META_ERROR );
		$queued       = 'yes' === (string) $order->get_meta( Siigoc_Invoice::META_QUEUED );

		if ( '' !== $invoice_id ) {
			echo '<p><strong>' . esc_html__( 'Factura creada en Siigo.', 'siigo-connect' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Comprobante:', 'siigo-connect' ) . ' <code>' . esc_html( '' !== $invoice_name ? $invoice_name : $number ) . '</code></p>';
			return;
		}

		if ( '' !== $error ) {
			echo '<p style="color:#b32d2e;"><strong>' . esc_html__( 'Último error:', 'siigo-connect' ) . '</strong><br />' . esc_html( $error ) . '</p>';
		} elseif ( $queued ) {
			echo '<p>' . esc_html__( 'Factura en cola de envío a Siigo…', 'siigo-connect' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Este pedido aún no se ha facturado en Siigo.', 'siigo-connect' ) . '</p>';
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'siigoc_invoice_now',
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			'siigoc_invoice_now_' . $order->get_id()
		);

		echo '<p><a href="' . esc_url( $url ) . '" class="button button-primary">' . esc_html( '' !== $error ? __( 'Reintentar ahora', 'siigo-connect' ) : __( 'Enviar a Siigo', 'siigo-connect' ) ) . '</a></p>';
	}

	/**
	 * Botón "Enviar a Siigo": factura el pedido de inmediato.
	 */
	public function handle_invoice_now() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'siigo-connect' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'siigoc_invoice_now_' . $order_id );

		$invoice = new Siigoc_Invoice();
		$result  = $invoice->process( $order_id, Siigoc_Invoice::MAX_ATTEMPTS ); // Sin más reintentos automáticos: acción manual.

		$order = wc_get_order( $order_id );
		$back  = $order ? $order->get_edit_order_url() : admin_url( 'edit.php?post_type=shop_order' );

		wp_safe_redirect( add_query_arg( 'siigoc_result', is_wp_error( $result ) ? 'error' : 'ok', $back ) );
		exit;
	}

	/**
	 * Aviso tras la acción manual.
	 */
	public function maybe_notice() {
		if ( ! isset( $_GET['siigoc_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$ok = 'ok' === $_GET['siigoc_result']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>';
		echo $ok
			? esc_html__( 'Siigo Connect: factura creada correctamente.', 'siigo-connect' )
			: esc_html__( 'Siigo Connect: no se pudo crear la factura. Revisa el detalle en las notas del pedido y en WooCommerce → Siigo — Registro.', 'siigo-connect' );
		echo '</p></div>';
	}
}
