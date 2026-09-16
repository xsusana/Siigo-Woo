<?php
/**
 * Plugin Name:       Siigo Connect para WooCommerce
 * Plugin URI:        https://github.com/xsusana/Siigo-Woo
 * Description:       Conecta WooCommerce con Siigo Nube: facturación automática (electrónica o interna), sincronización de productos, inventario y clientes.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Susana Pérez
 * Author URI:        https://github.com/xsusana
 * License:           GPL-2.0-or-later
 * Text Domain:       siigo-connect
 * Domain Path:       /languages
 * Update URI:        https://github.com/xsusana/Siigo-Woo
 */

defined( 'ABSPATH' ) || exit;

define( 'SIIGOC_VERSION', '1.2.1' );
define( 'SIIGOC_PLUGIN_FILE', __FILE__ );
define( 'SIIGOC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIIGOC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-logger.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-api-client.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-install.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-checkout-fields.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-customer.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-invoice.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-product-sync.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/class-siigoc-updater.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/admin/class-siigoc-settings.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/admin/class-siigoc-logs-page.php';
require_once SIIGOC_PLUGIN_DIR . 'includes/admin/class-siigoc-order-metabox.php';

register_activation_hook( __FILE__, array( 'Siigoc_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Siigoc_Install', 'deactivate' ) );

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
		'github_token'     => '',
		// Facturación.
		'document_type_id' => '',
		'trigger'          => 'paid', // paid | completed | manual.
		'seller_id'        => '',
		'cost_center_id'   => '',
		'payment_type_id'  => '',
		'tax_id'           => '',
		'shipping_sku'     => '',
		'send_dian'        => 'yes',
		'send_email'       => 'no',
		// Códigos DANE por defecto para terceros (11 / 11001 = Bogotá).
		'default_state_code' => '11',
		'default_city_code'  => '11001',
		// Sincronización.
		'sync_products'    => 'no',
		'sync_stock'       => 'no',
		'sync_create_products' => 'no',
		'sync_interval'    => 'hourly',
	);

	$settings = get_option( 'siigoc_settings', array() );

	return wp_parse_args( is_array( $settings ) ? $settings : array(), $defaults );
}

/**
 * URL de una página del plugin, según el menú padre disponible.
 *
 * @param string $page Slug de la página.
 * @param array  $args Parámetros extra de la URL.
 * @return string
 */
function siigoc_admin_url( $page = 'siigo-connect', $args = array() ) {
	$base = class_exists( 'WooCommerce' ) ? 'admin.php' : 'options-general.php';

	return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( $base ) );
}

// Enlace "Ajustes" en la fila del plugin (Plugins → Plugins instalados).
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( siigoc_admin_url() ) . '">' . esc_html__( 'Ajustes', 'siigo-connect' ) . '</a>'
		);
		return $links;
	}
);

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

	// Tras actualizar desde WordPress no se ejecuta el hook de activación:
	// aplicar aquí las rutinas de instalación de la versión nueva.
	if ( get_option( 'siigoc_version' ) !== SIIGOC_VERSION ) {
		Siigoc_Install::activate();
		Siigoc_Invoice::flush_catalogs();
	}

	// Actualizaciones desde GitHub (también corren por cron, fuera del admin).
	new Siigoc_Updater();

	if ( is_admin() ) {
		new Siigoc_Settings();
		new Siigoc_Logs_Page();
		add_action( 'admin_notices', 'siigoc_external_payload_filter_notice' );
	}

	// Limpieza diaria del log (entradas de más de 30 días).
	add_action( 'siigoc_purge_logs', array( 'Siigoc_Logger', 'purge_old' ) );
	if ( ! wp_next_scheduled( 'siigoc_purge_logs' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'siigoc_purge_logs' );
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'siigoc_woocommerce_missing_notice' );
		return;
	}

	new Siigoc_Checkout_Fields();
	new Siigoc_Invoice();
	new Siigoc_Product_Sync();

	if ( is_admin() ) {
		new Siigoc_Order_Metabox();
	}
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

/**
 * Aviso si otro código (p. ej. un snippet) modifica el payload de la factura.
 *
 * Desde la 1.2.0 el plugin ya incluye las correcciones que antes se aplicaban
 * con el snippet "Siigo Connect — Correcciones de Integración"; si sigue activo
 * le quitaría el IVA dos veces al precio.
 */
function siigoc_external_payload_filter_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! has_filter( 'siigoc_invoice_payload' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Siigo Connect:', 'siigo-connect' ) . '</strong> ';
	echo esc_html__( 'hay código externo modificando la factura (filtro siigoc_invoice_payload). Si es el snippet "Siigo Connect — Correcciones de Integración", desactívalo: esta versión ya incluye esas correcciones (vendedor por ítem, IVA incluido, decimales y teléfonos) y con el snippet activo el IVA se descontaría dos veces.', 'siigo-connect' );
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
