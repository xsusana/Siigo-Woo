<?php
/**
 * Página de registro (log) de la API.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Logs_Page {

	const PAGE_SLUG = 'siigo-connect-logs';
	const PER_PAGE  = 50;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_siigoc_clear_logs', array( $this, 'handle_clear' ) );
	}

	public function add_menu() {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';

		add_submenu_page(
			$parent,
			__( 'Registro de Siigo Connect', 'siigo-connect' ),
			__( 'Siigo — Registro', 'siigo-connect' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'siigo-connect' ) );
		}
		check_admin_referer( 'siigoc_clear_logs' );

		Siigoc_Logger::clear();

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'cleared' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$data    = Siigoc_Logger::get_entries( $page, self::PER_PAGE );
		$pages   = max( 1, (int) ceil( $data['total'] / self::PER_PAGE ) );
		$cleared = isset( $_GET['cleared'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap siigoc-wrap">
			<h1><?php esc_html_e( 'Siigo Connect — Registro', 'siigo-connect' ); ?></h1>

			<?php if ( $cleared ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Registro vaciado.', 'siigo-connect' ); ?></p></div>
			<?php endif; ?>

			<p>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'siigo-connect' ), admin_url( 'admin.php' ) ) ); ?>">
					&larr; <?php esc_html_e( 'Volver a ajustes', 'siigo-connect' ); ?>
				</a>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
				<input type="hidden" name="action" value="siigoc_clear_logs" />
				<?php wp_nonce_field( 'siigoc_clear_logs' ); ?>
				<?php submit_button( __( 'Vaciar registro', 'siigo-connect' ), 'delete', 'submit', false ); ?>
			</form>

			<table class="widefat striped siigoc-log-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Fecha (UTC)', 'siigo-connect' ); ?></th>
						<th><?php esc_html_e( 'Nivel', 'siigo-connect' ); ?></th>
						<th><?php esc_html_e( 'Llamada', 'siigo-connect' ); ?></th>
						<th><?php esc_html_e( 'HTTP', 'siigo-connect' ); ?></th>
						<th><?php esc_html_e( 'Detalle', 'siigo-connect' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $data['items'] ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Sin entradas todavía. Prueba la conexión desde los ajustes para generar la primera.', 'siigo-connect' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $data['items'] as $entry ) : ?>
							<tr class="<?php echo 'error' === $entry['level'] ? 'siigoc-log-error' : ''; ?>">
								<td><?php echo esc_html( $entry['created_at'] ); ?></td>
								<td><?php echo esc_html( $entry['level'] ); ?></td>
								<td><code><?php echo esc_html( $entry['method'] . ' ' . $entry['endpoint'] ); ?></code></td>
								<td><?php echo esc_html( $entry['http_code'] ? $entry['http_code'] : '—' ); ?></td>
								<td>
									<details>
										<summary><?php echo esc_html( wp_html_excerpt( (string) $entry['response'], 80, '…' ) ); ?></summary>
										<?php if ( '' !== (string) $entry['request'] ) : ?>
											<p><strong><?php esc_html_e( 'Petición:', 'siigo-connect' ); ?></strong></p>
											<pre><?php echo esc_html( $entry['request'] ); ?></pre>
										<?php endif; ?>
										<p><strong><?php esc_html_e( 'Respuesta:', 'siigo-connect' ); ?></strong></p>
										<pre><?php echo esc_html( $entry['response'] ); ?></pre>
									</details>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							)
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
