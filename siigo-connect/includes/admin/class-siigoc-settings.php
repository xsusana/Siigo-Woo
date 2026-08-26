<?php
/**
 * Página de ajustes del plugin.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Settings {

	const OPTION     = 'siigoc_settings';
	const PAGE_SLUG  = 'siigo-connect';
	const CACHE_KEY  = 'siigoc_catalog_cache';
	const CACHE_TTL  = 600; // 10 minutos.

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_siigoc_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Menú: bajo WooCommerce si existe, si no bajo Ajustes.
	 */
	public function add_menu() {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';

		add_submenu_page(
			$parent,
			__( 'Siigo Connect', 'siigo-connect' ),
			__( 'Siigo Connect', 'siigo-connect' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'siigoc_settings_group',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * Sanea y combina los ajustes enviados con los guardados
	 * (cada pestaña envía solo sus propios campos).
	 *
	 * @param array $input Datos del formulario.
	 * @return array
	 */
	public function sanitize( $input ) {
		$saved = siigoc_get_settings();
		$input = is_array( $input ) ? $input : array();

		$clean = $saved;

		$text_fields = array( 'username', 'partner_id', 'document_type_id', 'seller_id', 'cost_center_id', 'payment_type_id', 'tax_id', 'shipping_sku', 'default_state_code', 'default_city_code' );
		foreach ( $text_fields as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$clean[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}

		if ( array_key_exists( 'access_key', $input ) ) {
			$access_key = trim( (string) $input['access_key'] );
			// Campo vacío = conservar la clave guardada (no se muestra en el formulario).
			if ( '' !== $access_key ) {
				$clean['access_key'] = $access_key;
			}
		}

		if ( array_key_exists( 'trigger', $input ) ) {
			$clean['trigger'] = in_array( $input['trigger'], array( 'paid', 'completed', 'manual' ), true ) ? $input['trigger'] : 'paid';
		}

		// Checkboxes: solo se procesan los de la pestaña que se está guardando
		// (un checkbox sin marcar no llega en el POST).
		$tab = array_key_exists( '_tab', $input ) ? $input['_tab'] : '';

		if ( 'sync' === $tab ) {
			foreach ( array( 'sync_products', 'sync_stock', 'sync_create_products' ) as $field ) {
				$clean[ $field ] = ! empty( $input[ $field ] ) ? 'yes' : 'no';
			}
		}

		if ( 'invoicing' === $tab ) {
			foreach ( array( 'send_dian', 'send_email' ) as $field ) {
				$clean[ $field ] = ! empty( $input[ $field ] ) ? 'yes' : 'no';
			}
		}

		if ( array_key_exists( 'sync_interval', $input ) ) {
			$allowed                = array( 'siigoc_15min', 'hourly', 'twicedaily', 'daily' );
			$clean['sync_interval'] = in_array( $input['sync_interval'], $allowed, true ) ? $input['sync_interval'] : 'hourly';
		}

		// Si cambiaron las credenciales, invalidar token y catálogos cacheados.
		if ( $clean['username'] !== $saved['username'] || $clean['access_key'] !== $saved['access_key'] ) {
			delete_transient( Siigoc_Api_Client::TOKEN_TRANSIENT );
			delete_transient( self::CACHE_KEY );
		}

		return $clean;
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'siigoc-admin', SIIGOC_PLUGIN_URL . 'assets/css/admin.css', array(), SIIGOC_VERSION );
		wp_enqueue_script( 'siigoc-admin', SIIGOC_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), SIIGOC_VERSION, true );
		wp_localize_script(
			'siigoc-admin',
			'siigocAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'siigoc_admin' ),
				'i18n'    => array(
					'testing' => __( 'Probando conexión…', 'siigo-connect' ),
					'ok'      => __( 'Conexión exitosa con Siigo.', 'siigo-connect' ),
				),
			)
		);
	}

	/**
	 * AJAX: probar conexión con las credenciales guardadas.
	 */
	public function ajax_test_connection() {
		check_ajax_referer( 'siigoc_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sin permisos.', 'siigo-connect' ) ), 403 );
		}

		$result = siigoc_api()->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		delete_transient( self::CACHE_KEY );
		wp_send_json_success( array( 'message' => __( 'Conexión exitosa con Siigo.', 'siigo-connect' ) ) );
	}

	/**
	 * Catálogos de Siigo (tipos de comprobante, vendedores, etc.) con caché corto.
	 *
	 * @return array{document_types: array, users: array, cost_centers: array, payment_types: array, error: string}
	 */
	private function get_catalogs() {
		$empty = array(
			'document_types' => array(),
			'users'          => array(),
			'cost_centers'   => array(),
			'payment_types'  => array(),
			'taxes'          => array(),
			'error'          => '',
		);

		if ( ! siigoc_api()->has_credentials() ) {
			$empty['error'] = __( 'Guarda primero las credenciales en la pestaña Conexión.', 'siigo-connect' );
			return $empty;
		}

		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$catalogs = $empty;
		$api      = siigoc_api();

		$calls = array(
			'document_types' => 'get_document_types',
			'users'          => 'get_users',
			'cost_centers'   => 'get_cost_centers',
			'payment_types'  => 'get_payment_types',
			'taxes'          => 'get_taxes',
		);

		foreach ( $calls as $key => $method ) {
			$result = $api->$method();
			if ( is_wp_error( $result ) ) {
				$catalogs['error'] = $result->get_error_message();
				return $catalogs; // Sin caché: se reintenta en la próxima carga.
			}
			// Algunos endpoints devuelven { results: [...] }, otros la lista directa.
			$catalogs[ $key ] = isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : $result;
		}

		set_transient( self::CACHE_KEY, $catalogs, self::CACHE_TTL );

		return $catalogs;
	}

	/**
	 * Render de la página con pestañas.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs = array(
			'connection' => __( 'Conexión', 'siigo-connect' ),
			'invoicing'  => __( 'Facturación', 'siigo-connect' ),
			'sync'       => __( 'Sincronización', 'siigo-connect' ),
		);

		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'connection';
		}

		$settings = siigoc_get_settings();
		?>
		<div class="wrap siigoc-wrap">
			<h1><?php esc_html_e( 'Siigo Connect', 'siigo-connect' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
						class="nav-tab <?php echo $current === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'siigo-connect-logs' ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab">
					<?php esc_html_e( 'Registro', 'siigo-connect' ); ?>
				</a>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( 'siigoc_settings_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $current ); ?>" />

				<?php
				switch ( $current ) {
					case 'invoicing':
						$this->render_invoicing_tab( $settings );
						break;
					case 'sync':
						$this->render_sync_tab( $settings );
						break;
					default:
						$this->render_connection_tab( $settings );
				}
				?>

				<?php submit_button( __( 'Guardar cambios', 'siigo-connect' ) ); ?>
			</form>
		</div>
		<?php
	}

	private function render_connection_tab( $settings ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="siigoc-username"><?php esc_html_e( 'Usuario de la API', 'siigo-connect' ); ?></label></th>
				<td>
					<input name="<?php echo esc_attr( self::OPTION ); ?>[username]" id="siigoc-username" type="text" class="regular-text" value="<?php echo esc_attr( $settings['username'] ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Correo del usuario API creado en Siigo Nube (Configuración → Credenciales API).', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-access-key"><?php esc_html_e( 'Access key', 'siigo-connect' ); ?></label></th>
				<td>
					<input name="<?php echo esc_attr( self::OPTION ); ?>[access_key]" id="siigoc-access-key" type="password" class="regular-text" value="" autocomplete="new-password"
						placeholder="<?php echo '' !== $settings['access_key'] ? esc_attr__( '•••••••• (guardado — deja vacío para conservarlo)', 'siigo-connect' ) : ''; ?>" />
					<p class="description"><?php esc_html_e( 'El access key generado en Siigo. Por seguridad no se vuelve a mostrar; deja el campo vacío para no cambiarlo.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-partner-id"><?php esc_html_e( 'Partner ID', 'siigo-connect' ); ?></label></th>
				<td>
					<input name="<?php echo esc_attr( self::OPTION ); ?>[partner_id]" id="siigoc-partner-id" type="text" class="regular-text" value="<?php echo esc_attr( $settings['partner_id'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Identificador de aplicación que exige la API de Siigo. Puedes dejar el valor por defecto.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Probar conexión', 'siigo-connect' ); ?></th>
				<td>
					<button type="button" class="button" id="siigoc-test-connection"><?php esc_html_e( 'Probar conexión', 'siigo-connect' ); ?></button>
					<span id="siigoc-test-result"></span>
					<p class="description"><?php esc_html_e( 'Guarda las credenciales antes de probar.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_invoicing_tab( $settings ) {
		$catalogs = $this->get_catalogs();

		if ( '' !== $catalogs['error'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $catalogs['error'] ) . '</p></div>';
		}
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="siigoc-document-type"><?php esc_html_e( 'Tipo de comprobante', 'siigo-connect' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION ); ?>[document_type_id]" id="siigoc-document-type">
						<option value=""><?php esc_html_e( '— Selecciona —', 'siigo-connect' ); ?></option>
						<?php foreach ( $catalogs['document_types'] as $type ) : ?>
							<?php
							if ( ! isset( $type['id'] ) ) {
								continue;
							}
							$label = isset( $type['name'] ) ? $type['name'] : $type['id'];
							if ( ! empty( $type['electronic_type'] ) && 'NoElectronic' !== $type['electronic_type'] ) {
								$label .= ' — ' . __( 'Electrónica (DIAN)', 'siigo-connect' );
							}
							?>
							<option value="<?php echo esc_attr( $type['id'] ); ?>" <?php selected( (string) $settings['document_type_id'], (string) $type['id'] ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Los comprobantes marcados como electrónicos se envían a la DIAN al facturar.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-trigger"><?php esc_html_e( '¿Cuándo facturar?', 'siigo-connect' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION ); ?>[trigger]" id="siigoc-trigger">
						<option value="paid" <?php selected( $settings['trigger'], 'paid' ); ?>><?php esc_html_e( 'Al confirmarse el pago del pedido', 'siigo-connect' ); ?></option>
						<option value="completed" <?php selected( $settings['trigger'], 'completed' ); ?>><?php esc_html_e( 'Al marcar el pedido como completado', 'siigo-connect' ); ?></option>
						<option value="manual" <?php selected( $settings['trigger'], 'manual' ); ?>><?php esc_html_e( 'Solo manualmente desde el pedido', 'siigo-connect' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-seller"><?php esc_html_e( 'Vendedor por defecto', 'siigo-connect' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION ); ?>[seller_id]" id="siigoc-seller">
						<option value=""><?php esc_html_e( '— Selecciona —', 'siigo-connect' ); ?></option>
						<?php foreach ( $catalogs['users'] as $user ) : ?>
							<?php
							if ( ! isset( $user['id'] ) ) {
								continue;
							}
							$name = trim( ( isset( $user['first_name'] ) ? $user['first_name'] : '' ) . ' ' . ( isset( $user['last_name'] ) ? $user['last_name'] : '' ) );
							if ( '' === $name ) {
								$name = isset( $user['username'] ) ? $user['username'] : $user['id'];
							}
							?>
							<option value="<?php echo esc_attr( $user['id'] ); ?>" <?php selected( (string) $settings['seller_id'], (string) $user['id'] ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-cost-center"><?php esc_html_e( 'Centro de costo', 'siigo-connect' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION ); ?>[cost_center_id]" id="siigoc-cost-center">
						<option value=""><?php esc_html_e( '— Ninguno —', 'siigo-connect' ); ?></option>
						<?php foreach ( $catalogs['cost_centers'] as $center ) : ?>
							<?php
							if ( ! isset( $center['id'] ) ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $center['id'] ); ?>" <?php selected( (string) $settings['cost_center_id'], (string) $center['id'] ); ?>>
								<?php echo esc_html( isset( $center['name'] ) ? $center['name'] : $center['id'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-payment-type"><?php esc_html_e( 'Forma de pago por defecto', 'siigo-connect' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION ); ?>[payment_type_id]" id="siigoc-payment-type">
						<option value=""><?php esc_html_e( '— Selecciona —', 'siigo-connect' ); ?></option>
						<?php foreach ( $catalogs['payment_types'] as $payment ) : ?>
							<?php
							if ( ! isset( $payment['id'] ) ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $payment['id'] ); ?>" <?php selected( (string) $settings['payment_type_id'], (string) $payment['id'] ); ?>>
								<?php echo esc_html( isset( $payment['name'] ) ? $payment['name'] : $payment['id'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Se usará para registrar el pago de las facturas creadas desde WooCommerce.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-tax"><?php esc_html_e( 'Impuesto (IVA)', 'siigo-connect' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION ); ?>[tax_id]" id="siigoc-tax">
						<option value=""><?php esc_html_e( '— Sin impuesto —', 'siigo-connect' ); ?></option>
						<?php foreach ( $catalogs['taxes'] as $tax ) : ?>
							<?php
							if ( ! isset( $tax['id'] ) ) {
								continue;
							}
							$tax_label = isset( $tax['name'] ) ? $tax['name'] : $tax['id'];
							if ( isset( $tax['percentage'] ) ) {
								$tax_label .= ' (' . $tax['percentage'] . '%)';
							}
							?>
							<option value="<?php echo esc_attr( $tax['id'] ); ?>" <?php selected( (string) $settings['tax_id'], (string) $tax['id'] ); ?>><?php echo esc_html( $tax_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Se aplica a las líneas del pedido que tengan impuesto en WooCommerce. Los precios se envían a Siigo sin IVA y Siigo lo calcula con este impuesto.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-shipping-sku"><?php esc_html_e( 'Código del producto de envío', 'siigo-connect' ); ?></label></th>
				<td>
					<input name="<?php echo esc_attr( self::OPTION ); ?>[shipping_sku]" id="siigoc-shipping-sku" type="text" class="regular-text" value="<?php echo esc_attr( $settings['shipping_sku'] ); ?>" placeholder="ENVIO" />
					<p class="description"><?php esc_html_e( 'Código de un producto/servicio creado en Siigo para facturar el costo de envío como una línea más. Obligatorio si cobras envíos.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ciudad por defecto (DANE)', 'siigo-connect' ); ?></th>
				<td>
					<label><?php esc_html_e( 'Departamento', 'siigo-connect' ); ?>
						<input name="<?php echo esc_attr( self::OPTION ); ?>[default_state_code]" type="text" size="4" value="<?php echo esc_attr( $settings['default_state_code'] ); ?>" />
					</label>
					&nbsp;
					<label><?php esc_html_e( 'Municipio', 'siigo-connect' ); ?>
						<input name="<?php echo esc_attr( self::OPTION ); ?>[default_city_code]" type="text" size="7" value="<?php echo esc_attr( $settings['default_city_code'] ); ?>" />
					</label>
					<p class="description"><?php esc_html_e( 'Códigos DANE usados al crear terceros (ej. 11 y 11001 para Bogotá). Siigo exige código de municipio y no puede deducirse del texto libre que escribe el cliente; el filtro siigoc_customer_city permite un mapeo más fino.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Al emitir', 'siigo-connect' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[send_dian]" value="yes" <?php checked( $settings['send_dian'], 'yes' ); ?> />
						<?php esc_html_e( 'Enviar a la DIAN (solo aplica a comprobantes electrónicos).', 'siigo-connect' ); ?>
					</label>
					<br />
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[send_email]" value="yes" <?php checked( $settings['send_email'], 'yes' ); ?> />
						<?php esc_html_e( 'Enviar la factura por correo al cliente desde Siigo.', 'siigo-connect' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	private function render_sync_tab( $settings ) {
		$last_run = get_option( Siigoc_Product_Sync::LAST_RUN_OPT );

		if ( isset( $_GET['synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Sincronización ejecutada. Revisa el resumen abajo y el detalle en el Registro.', 'siigo-connect' ) . '</p></div>';
		}
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Sincronizar productos', 'siigo-connect' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[sync_products]" value="yes" <?php checked( $settings['sync_products'], 'yes' ); ?> />
						<?php esc_html_e( 'Traer productos y precios desde Siigo hacia WooCommerce (emparejados por SKU/código).', 'siigo-connect' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sincronizar inventario', 'siigo-connect' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[sync_stock]" value="yes" <?php checked( $settings['sync_stock'], 'yes' ); ?> />
						<?php esc_html_e( 'Actualizar el stock de WooCommerce con las existencias de Siigo.', 'siigo-connect' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Crear productos nuevos', 'siigo-connect' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[sync_create_products]" value="yes" <?php checked( $settings['sync_create_products'], 'yes' ); ?> />
						<?php esc_html_e( 'Si un producto de Siigo no existe en WooCommerce, crearlo como borrador (tú lo revisas y publicas).', 'siigo-connect' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="siigoc-sync-interval"><?php esc_html_e( 'Frecuencia', 'siigo-connect' ); ?></label></th>
				<td>
					<select name="<?php echo esc_attr( self::OPTION ); ?>[sync_interval]" id="siigoc-sync-interval">
						<option value="siigoc_15min" <?php selected( $settings['sync_interval'], 'siigoc_15min' ); ?>><?php esc_html_e( 'Cada 15 minutos', 'siigo-connect' ); ?></option>
						<option value="hourly" <?php selected( $settings['sync_interval'], 'hourly' ); ?>><?php esc_html_e( 'Cada hora', 'siigo-connect' ); ?></option>
						<option value="twicedaily" <?php selected( $settings['sync_interval'], 'twicedaily' ); ?>><?php esc_html_e( 'Dos veces al día', 'siigo-connect' ); ?></option>
						<option value="daily" <?php selected( $settings['sync_interval'], 'daily' ); ?>><?php esc_html_e( 'Una vez al día', 'siigo-connect' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Los cambios de frecuencia se aplican al guardar.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sincronización manual', 'siigo-connect' ); ?></th>
				<td>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'siigoc_sync_now', admin_url( 'admin-post.php' ) ), 'siigoc_sync_now' ) ); ?>">
						<?php esc_html_e( 'Sincronizar ahora', 'siigo-connect' ); ?>
					</a>
					<?php if ( is_array( $last_run ) && ! empty( $last_run['time'] ) ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: fecha, 2: actualizados, 3: creados, 4: omitidos, 5: errores. */
								esc_html__( 'Última corrida: %1$s — %2$d actualizados, %3$d creados, %4$d omitidos, %5$d errores.', 'siigo-connect' ),
								esc_html( wp_date( 'Y-m-d H:i', $last_run['time'] ) ),
								(int) $last_run['stats']['updated'],
								(int) $last_run['stats']['created'],
								(int) $last_run['stats']['skipped'],
								(int) $last_run['stats']['errors']
							);
							?>
						</p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Guarda los ajustes antes de sincronizar. El emparejamiento es por SKU de Woo = código del producto en Siigo.', 'siigo-connect' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
