<?php
/**
 * Plugin Name:       Siigo Connect para WooCommerce
 * Plugin URI:        https://github.com/danielserna/siigo-connect
 * Description:       Conecta WooCommerce con Siigo Nube: facturación automática (electrónica o interna), sincronización de productos, inventario y clientes.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Daniel Serna
 * License:           GPL-2.0-or-later
 * Text Domain:       siigo-connect
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SIIGOC_VERSION', '0.1.0' );
define( 'SIIGOC_PLUGIN_FILE', __FILE__ );
define( 'SIIGOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIIGOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-logger.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-api-client.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-install.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/admin/class-siigoc-settings.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/admin/class-siigoc-logs-page.php';

register_activation_hook( __FILE__, array( 'Siigoc_Install', 'activate' ) );

/**
 * Devuelve los ajustes del plugin con sus valores por defecto.
 *
 * @return array
 */
function siigoc_get_settings() {
	$defaults = array(
		// Conexión.
		'username'         => '',
		'access_key'       => '',
		'partner_id'       => 'SiigoConnectWP',
		// Facturación.
		'document_type_id' => '',
		'trigger'          => 'paid', // paid | completed | manual.
		'seller_id'        => '',
		'cost_center_id'   => '',
		'payment_type_id'  => '',
		// Sincronización.
		'sync_products'    => 'no',
		'sync_stock'       => 'no',
		'sync_interval'    => 'hourly',
	);

	$settings = get_option( 'siigoc_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

/**
 * Instancia compartida del cliente de la API.
 *
 * @return Siigoc_Api_Client
 */
function siigoc_api() {
	static $client = null;

	if ( null === $client ) {
		$settings = siigoc_get_settings();
		$client   = new Siigoc_Api_Client(
			$settings['username'],
			$settings['access_key'],
			$settings['partner_id']
		);
	}

	return $client;
}

/**
 * Arranque del plugin.
 */
function siigoc_init() {
	load_plugin_textdomain( 'siigo-connect', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( is_admin() ) {
		new Siigoc_Settings();
		new Siigoc_Logs_Page();
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'siigoc_woocommerce_missing_notice' );
		return;
	}

	// Los módulos que dependen de WooCommerce (facturación, checkout, sync)
	// se cargan aquí en las siguientes fases.
}
add_action( 'plugins_loaded', 'siigoc_init' );

/**
 * Aviso cuando WooCommerce no está activo.
 */
function siigoc_woocommerce_missing_notice() {
	echo '<div class="notice notice-warning"><p>';
	echo esc_html__( 'Siigo Connect: WooCommerce no está activo. Puedes configurar la conexión con Siigo, pero la facturación y la sincronización requieren WooCommerce.', 'siigo-connect' );
	echo '</p></div>';
}

// Compatibilidad con el almacenamiento de pedidos de alto rendimiento (HPOS) de WooCommerce.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
