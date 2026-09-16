<?php
/**
 * Actualizaciones del plugin desde las releases de GitHub.
 *
 * WordPress consulta la última release publicada del repositorio y, si su
 * versión es mayor que la instalada, muestra "Actualizar ahora" en Plugins
 * (también funcionan las actualizaciones automáticas). La release debe llevar
 * adjunto el archivo siigo-connect.zip.
 *
 * Si el repositorio es privado hace falta un token de GitHub de solo lectura,
 * en la pestaña Conexión o con la constante SIIGOC_GITHUB_TOKEN en wp-config.php.
 *
 * @package Siigo_Connect
 */

defined( 'ABSPATH' ) || exit;

class Siigoc_Updater {

	const REPO       = 'xsusana/Siigo-Woo';
	const ASSET_NAME = 'siigo-connect.zip';
	const SLUG       = 'siigo-connect';
	const CACHE_KEY  = 'siigoc_update_release';
	const CACHE_TTL  = 21600; // 6 horas.
	const ERROR_TTL  = 3600;  // Tras un error, reintentar en 1 hora.

	/** @var string */
	private $basename;

	public function __construct() {
		$this->basename = plugin_basename( SIIGOC_PLUGIN_FILE );

		// Requiere la cabecera "Update URI: https://github.com/..." del plugin.
		add_filter( 'update_plugins_github.com', array( $this, 'check_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_private_package' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_siigoc_check_updates', array( $this, 'handle_check_now' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	/**
	 * Token de GitHub (constante de wp-config.php o ajuste del plugin).
	 *
	 * @return string
	 */
	public static function get_token() {
		if ( defined( 'SIIGOC_GITHUB_TOKEN' ) && '' !== (string) SIIGOC_GITHUB_TOKEN ) {
			return (string) SIIGOC_GITHUB_TOKEN;
		}

		$settings = siigoc_get_settings();

		return (string) $settings['github_token'];
	}

	/**
	 * Última release publicada en GitHub, cacheada.
	 *
	 * @param bool $force Ignorar el caché.
	 * @return array{version: string, url: string, package: string, changelog: string, published: string}|WP_Error
	 */
	public static function get_release( $force = false ) {
		$cached = get_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return isset( $cached['error'] ) ? new WP_Error( 'siigoc_update_check', $cached['error'] ) : $cached;
		}

		$release = self::fetch_release();

		if ( is_wp_error( $release ) ) {
			set_transient( self::CACHE_KEY, array( 'error' => $release->get_error_message() ), self::ERROR_TTL );
		} else {
			set_transient( self::CACHE_KEY, $release, self::CACHE_TTL );
		}

		return $release;
	}

	/**
	 * Consulta la API de GitHub.
	 *
	 * @return array|WP_Error
	 */
	private static function fetch_release() {
		$token = self::get_token();

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => self::headers( 'application/vnd.github+json', $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return new WP_Error(
				'siigoc_update_not_found',
				'' === $token
					? __( 'No se encontró ninguna release en GitHub. Si el repositorio es privado, configura el token de GitHub en la pestaña Conexión.', 'siigo-connect' )
					: __( 'No se encontró ninguna release en GitHub, o el token no tiene acceso al repositorio.', 'siigo-connect' )
			);
		}

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			$message = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : sprintf( 'HTTP %d', $code );
			/* translators: %s: mensaje de GitHub. */
			return new WP_Error( 'siigoc_update_http', sprintf( __( 'GitHub respondió con error: %s', 'siigo-connect' ), $message ) );
		}

		$package = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && self::ASSET_NAME === $asset['name'] ) {
					// Repo privado: el archivo se descarga por la API con el token.
					$package = '' !== $token ? (string) $asset['url'] : (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		if ( '' === $package ) {
			return new WP_Error(
				'siigoc_update_no_asset',
				/* translators: 1: etiqueta de la release, 2: nombre del archivo. */
				sprintf( __( 'La release %1$s de GitHub no tiene adjunto el archivo %2$s.', 'siigo-connect' ), $body['tag_name'], self::ASSET_NAME )
			);
		}

		return array(
			'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
			'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : 'https://github.com/' . self::REPO,
			'package'   => $package,
			'changelog' => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);
	}

	/**
	 * Cabeceras para la API de GitHub.
	 *
	 * @param string $accept Tipo aceptado.
	 * @param string $token  Token (opcional).
	 * @return array
	 */
	private static function headers( $accept, $token ) {
		$headers = array(
			'Accept'     => $accept,
			'User-Agent' => 'Siigo-Connect/' . SIIGOC_VERSION,
		);

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Informa a WordPress de la versión disponible (filtro update_plugins_{host}).
	 *
	 * @param array|false $update      Datos de actualización.
	 * @param array       $plugin_data Cabeceras del plugin.
	 * @param string      $plugin_file Plugin evaluado.
	 * @return array|false
	 */
	public function check_update( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}

		$release = self::get_release();
		if ( is_wp_error( $release ) ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);
	}

	/**
	 * Ventana "Ver detalles" de la actualización.
	 *
	 * @param false|object|array $result Resultado.
	 * @param string             $action Acción consultada.
	 * @param object             $args   Argumentos.
	 * @return false|object|array
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = self::get_release();
		if ( is_wp_error( $release ) ) {
			return $result;
		}

		$changelog = '' !== trim( $release['changelog'] )
			? wpautop( esc_html( $release['changelog'] ) )
			: '<p>' . esc_html__( 'Consulta los cambios en GitHub.', 'siigo-connect' ) . '</p>';

		return (object) array(
			'name'          => 'Siigo Connect para WooCommerce',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'Susana Pérez',
			'homepage'      => $release['url'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Conecta WooCommerce con Siigo Nube: facturación automática (electrónica o interna), sincronización de productos, inventario y clientes.', 'siigo-connect' ) . '</p>',
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * Descarga del paquete desde un repo privado.
	 *
	 * La API de GitHub responde con una redirección a un enlace firmado de
	 * descarga; ese enlace se descarga sin el token (si se reenvía, falla).
	 *
	 * @param false|string|WP_Error $reply   Respuesta previa.
	 * @param string                $package URL del paquete.
	 * @return false|string|WP_Error Ruta del archivo descargado.
	 */
	public function download_private_package( $reply, $package ) {
		$prefix = 'https://api.github.com/repos/' . self::REPO . '/releases/assets/';

		if ( false !== $reply || 0 !== strpos( (string) $package, $prefix ) ) {
			return $reply;
		}

		$token = self::get_token();
		if ( '' === $token ) {
			return new WP_Error( 'siigoc_update_no_token', __( 'Falta el token de GitHub para descargar la actualización.', 'siigo-connect' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => self::headers( 'application/octet-stream', $token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 300 && $code < 400 ) {
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			return '' !== $location ? download_url( $location, 300 ) : new WP_Error( 'siigoc_update_download', __( 'GitHub no devolvió el enlace de descarga.', 'siigo-connect' ) );
		}

		if ( 200 === $code ) {
			$file = wp_tempnam( self::ASSET_NAME );
			if ( ! $file || false === file_put_contents( $file, wp_remote_retrieve_body( $response ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new WP_Error( 'siigoc_update_download', __( 'No se pudo guardar el paquete descargado.', 'siigo-connect' ) );
			}
			return $file;
		}

		/* translators: %d: código HTTP. */
		return new WP_Error( 'siigoc_update_download', sprintf( __( 'No se pudo descargar la actualización desde GitHub (HTTP %d).', 'siigo-connect' ), $code ) );
	}

	/**
	 * Garantiza que el zip se instale en la misma carpeta que el plugin actual.
	 *
	 * @param string      $source        Carpeta extraída.
	 * @param string      $remote_source Carpeta temporal padre.
	 * @param WP_Upgrader $upgrader      Instancia del actualizador.
	 * @param array       $hook_extra    Datos extra (plugin actualizado).
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename || ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . dirname( $this->basename );

		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( ! $wp_filesystem->move( $source, $desired, true ) ) {
			return new WP_Error( 'siigoc_update_rename', __( 'No se pudo preparar la carpeta de la actualización.', 'siigo-connect' ) );
		}

		return trailingslashit( $desired );
	}

	/**
	 * Enlace "Buscar actualizaciones" en la fila del plugin.
	 *
	 * @param array  $links Enlaces de la fila.
	 * @param string $file  Plugin de la fila.
	 * @return array
	 */
	public function row_meta( $links, $file ) {
		if ( $file === $this->basename && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( self::check_now_url() ) . '">' . esc_html__( 'Buscar actualizaciones', 'siigo-connect' ) . '</a>';
		}

		return $links;
	}

	/**
	 * URL de la acción "Buscar actualizaciones".
	 *
	 * @return string
	 */
	public static function check_now_url() {
		return wp_nonce_url( add_query_arg( 'action', 'siigoc_check_updates', admin_url( 'admin-post.php' ) ), 'siigoc_check_updates' );
	}

	/**
	 * Fuerza la consulta a GitHub y vuelve a la página de plugins.
	 */
	public function handle_check_now() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Sin permisos.', 'siigo-connect' ) );
		}
		check_admin_referer( 'siigoc_check_updates' );

		self::get_release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( add_query_arg( 'siigoc_update_checked', '1', admin_url( 'plugins.php' ) ) );
		exit;
	}

	/**
	 * Resultado de "Buscar actualizaciones".
	 */
	public function maybe_notice() {
		if ( ! isset( $_GET['siigoc_update_checked'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$release = self::get_release();

		if ( is_wp_error( $release ) ) {
			$class   = 'notice-error';
			$message = sprintf(
				/* translators: %s: error. */
				__( 'Siigo Connect: no se pudo buscar actualizaciones — %s', 'siigo-connect' ),
				$release->get_error_message()
			);
		} elseif ( version_compare( $release['version'], SIIGOC_VERSION, '>' ) ) {
			$class   = 'notice-info';
			$message = sprintf(
				/* translators: %s: versión nueva. */
				__( 'Siigo Connect: hay una nueva versión (%s). Usa "Actualizar ahora" en la fila del plugin.', 'siigo-connect' ),
				$release['version']
			);
		} else {
			$class   = 'notice-success';
			$message = sprintf(
				/* translators: %s: versión instalada. */
				__( 'Siigo Connect: ya tienes la última versión (%s).', 'siigo-connect' ),
				SIIGOC_VERSION
			);
		}

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}
}
