<?php
/**
 * Plugin Name: Bulk Plugin Installer
 * Description: Install multiple plugins from repository slugs, ZIP URLs, or uploaded ZIP files using a manifest text file.
 * Version: 1.0.0
 * Author: Novamira
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPI_Bulk_Plugin_Installer {
	const MENU_SLUG = 'bpi-bulk-plugin-installer';

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
	}

	/**
	 * Add admin page under Tools.
	 */
	public static function register_menu() {
		add_management_page(
			__( 'Bulk Plugin Installer', 'bulk-plugin-installer' ),
			__( 'Bulk Plugin Installer', 'bulk-plugin-installer' ),
			'install_plugins',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render admin UI and process submissions.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'bulk-plugin-installer' ) );
		}

		$results = array();

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['bpi_install_plugins'] ) ) {
			check_admin_referer( 'bpi_install_plugins_action', 'bpi_nonce' );

			$activate_after_install = ! empty( $_POST['bpi_activate_plugins'] );
			$parsed_input           = self::parse_manifest_from_upload();

			if ( is_wp_error( $parsed_input ) ) {
				$results[] = array(
					'item'   => __( 'Manifest', 'bulk-plugin-installer' ),
					'status' => 'error',
					'message' => $parsed_input->get_error_message(),
				);
			} else {
				$uploaded_zips = self::get_uploaded_zip_map();
				$results       = self::install_plugins_from_items( $parsed_input, $uploaded_zips, $activate_after_install );
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bulk Plugin Installer', 'bulk-plugin-installer' ); ?></h1>
			<p><?php esc_html_e( 'Install plugins from repository slugs, direct ZIP URLs, and uploaded ZIP files using a text manifest.', 'bulk-plugin-installer' ); ?></p>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'bpi_install_plugins_action', 'bpi_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bpi_manifest_file"><?php esc_html_e( 'Manifest text file', 'bulk-plugin-installer' ); ?></label></th>
						<td>
							<input type="file" name="bpi_manifest_file" id="bpi_manifest_file" accept=".txt,text/plain" required />
							<p class="description"><?php esc_html_e( 'Upload a .txt file with one source per line.', 'bulk-plugin-installer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bpi_zip_files"><?php esc_html_e( 'Local ZIP files (optional)', 'bulk-plugin-installer' ); ?></label></th>
						<td>
							<input type="file" name="bpi_zip_files[]" id="bpi_zip_files" accept=".zip,application/zip" multiple />
							<p class="description"><?php esc_html_e( 'Used for lines like zip:my-plugin.zip in your manifest.', 'bulk-plugin-installer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'bulk-plugin-installer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="bpi_activate_plugins" value="1" />
								<?php esc_html_e( 'Activate plugin after successful install', 'bulk-plugin-installer' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="bpi_install_plugins" class="button button-primary"><?php esc_html_e( 'Install Plugins', 'bulk-plugin-installer' ); ?></button>
				</p>
			</form>

			<?php self::render_manifest_help(); ?>
			<?php self::render_results( $results ); ?>
		</div>
		<?php
	}

	/**
	 * Parse the uploaded manifest into structured items.
	 *
	 * @return array|WP_Error
	 */
	private static function parse_manifest_from_upload() {
		if ( empty( $_FILES['bpi_manifest_file'] ) || empty( $_FILES['bpi_manifest_file']['tmp_name'] ) ) {
			return new WP_Error( 'missing_manifest', __( 'Please upload a manifest text file.', 'bulk-plugin-installer' ) );
		}

		$manifest = $_FILES['bpi_manifest_file'];

		if ( ! empty( $manifest['error'] ) ) {
			return new WP_Error( 'manifest_upload_error', __( 'There was an error uploading the manifest file.', 'bulk-plugin-installer' ) );
		}

		$contents = file_get_contents( $manifest['tmp_name'] );

		if ( false === $contents ) {
			return new WP_Error( 'manifest_read_error', __( 'Unable to read the manifest file.', 'bulk-plugin-installer' ) );
		}

		$lines = preg_split( '/\R/', $contents );
		$items = array();

		foreach ( $lines as $line_number => $line ) {
			$raw = trim( $line );

			if ( '' === $raw || 0 === strpos( $raw, '#' ) ) {
				continue;
			}

			$parsed = self::parse_manifest_line( $raw );

			if ( is_wp_error( $parsed ) ) {
				return new WP_Error(
					'invalid_manifest_line',
					sprintf(
						/* translators: 1: line number, 2: error message */
						__( 'Line %1$d: %2$s', 'bulk-plugin-installer' ),
						(int) $line_number + 1,
						$parsed->get_error_message()
					)
				);
			}

			$items[] = $parsed;
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'empty_manifest', __( 'The manifest file contains no installable items.', 'bulk-plugin-installer' ) );
		}

		return $items;
	}

	/**
	 * Parse a single manifest line.
	 *
	 * @param string $line Manifest line.
	 * @return array|WP_Error
	 */
	private static function parse_manifest_line( $line ) {
		$line = trim( $line );

		if ( preg_match( '/^repo:(.+)$/i', $line, $matches ) ) {
			$slug = strtolower( trim( $matches[1] ) );
			if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
				return new WP_Error( 'invalid_slug', __( 'Repository slug must contain only lowercase letters, numbers, and dashes.', 'bulk-plugin-installer' ) );
			}

			return array(
				'type'  => 'repo',
				'value' => $slug,
				'label' => 'repo:' . $slug,
			);
		}

		if ( preg_match( '/^url:(.+)$/i', $line, $matches ) ) {
			$url = esc_url_raw( trim( $matches[1] ) );
			if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
				return new WP_Error( 'invalid_url', __( 'Invalid URL source.', 'bulk-plugin-installer' ) );
			}

			return array(
				'type'  => 'url',
				'value' => $url,
				'label' => 'url:' . $url,
			);
		}

		if ( preg_match( '/^zip:(.+)$/i', $line, $matches ) ) {
			$filename = sanitize_file_name( trim( $matches[1] ) );
			if ( empty( $filename ) || ! preg_match( '/\.zip$/i', $filename ) ) {
				return new WP_Error( 'invalid_zip', __( 'zip: entries must end in .zip and match an uploaded ZIP filename.', 'bulk-plugin-installer' ) );
			}

			return array(
				'type'  => 'zip',
				'value' => $filename,
				'label' => 'zip:' . $filename,
			);
		}

		// Default to repository slug when no prefix is used.
		$slug = strtolower( $line );
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return new WP_Error( 'invalid_line', __( 'Unknown line format. Use slug, repo:, url:, or zip: prefixes.', 'bulk-plugin-installer' ) );
		}

		return array(
			'type'  => 'repo',
			'value' => $slug,
			'label' => $slug,
		);
	}

	/**
	 * Build a map of uploaded ZIP files keyed by sanitized filename.
	 *
	 * @return array<string, string>
	 */
	private static function get_uploaded_zip_map() {
		if ( empty( $_FILES['bpi_zip_files'] ) || ! is_array( $_FILES['bpi_zip_files']['name'] ) ) {
			return array();
		}

		$zip_map = array();
		$names   = $_FILES['bpi_zip_files']['name'];
		$tmp     = $_FILES['bpi_zip_files']['tmp_name'];
		$errors  = $_FILES['bpi_zip_files']['error'];

		foreach ( $names as $index => $original_name ) {
			if ( ! isset( $errors[ $index ] ) || UPLOAD_ERR_OK !== (int) $errors[ $index ] ) {
				continue;
			}

			if ( empty( $tmp[ $index ] ) ) {
				continue;
			}

			$sanitized = sanitize_file_name( $original_name );
			if ( ! preg_match( '/\.zip$/i', $sanitized ) ) {
				continue;
			}

			$zip_map[ $sanitized ] = $tmp[ $index ];
		}

		return $zip_map;
	}

	/**
	 * Install parsed items.
	 *
	 * @param array $items Parsed manifest items.
	 * @param array $uploaded_zips Uploaded ZIP map.
	 * @param bool  $activate_after_install Activate after install.
	 * @return array
	 */
	private static function install_plugins_from_items( $items, $uploaded_zips, $activate_after_install ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		wp_cache_flush();

		$results = array();

		foreach ( $items as $item ) {
			$install_target = '';

			switch ( $item['type'] ) {
				case 'repo':
					$download_link = self::get_repo_download_link( $item['value'] );
					if ( is_wp_error( $download_link ) ) {
						$results[] = array(
							'item'    => $item['label'],
							'status'  => 'error',
							'message' => $download_link->get_error_message(),
						);
						continue 2;
					}

					$install_target = $download_link;
					break;
				case 'url':
					$install_target = $item['value'];
					break;
				case 'zip':
					if ( empty( $uploaded_zips[ $item['value'] ] ) ) {
						$results[] = array(
							'item'    => $item['label'],
							'status'  => 'error',
							'message' => __( 'ZIP file not found in uploaded files.', 'bulk-plugin-installer' ),
						);
						continue 2;
					}
					$install_target = $uploaded_zips[ $item['value'] ];
					break;
			}

			$skin     = new Automatic_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$installed = $upgrader->install( $install_target );

			if ( is_wp_error( $installed ) ) {
				$results[] = array(
					'item'    => $item['label'],
					'status'  => 'error',
					'message' => $installed->get_error_message(),
				);
				continue;
			}

			if ( false === $installed ) {
				$message = __( 'Installation failed. Check server filesystem permissions or credentials.', 'bulk-plugin-installer' );
				if ( ! empty( $skin->result ) && is_wp_error( $skin->result ) ) {
					$message = $skin->result->get_error_message();
				}

				$results[] = array(
					'item'    => $item['label'],
					'status'  => 'error',
					'message' => $message,
				);
				continue;
			}

			$plugin_file = self::detect_installed_plugin_file( $upgrader, $item );

			if ( $activate_after_install && ! empty( $plugin_file ) ) {
				$activation = activate_plugin( $plugin_file );
				if ( is_wp_error( $activation ) ) {
					$results[] = array(
						'item'    => $item['label'],
						'status'  => 'warning',
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Installed, but activation failed: %s', 'bulk-plugin-installer' ),
							$activation->get_error_message()
						),
					);
					continue;
				}

				$results[] = array(
					'item'    => $item['label'],
					'status'  => 'success',
					'message' => __( 'Installed and activated.', 'bulk-plugin-installer' ),
				);
				continue;
			}

			$results[] = array(
				'item'    => $item['label'],
				'status'  => 'success',
				'message' => __( 'Installed successfully.', 'bulk-plugin-installer' ),
			);
		}

		return $results;
	}

	/**
	 * Resolve a WordPress.org plugin slug to its ZIP download URL.
	 *
	 * @param string $slug Repository slug.
	 * @return string|WP_Error
	 */
	private static function get_repo_download_link( $slug ) {
		$api = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'sections'       => false,
					'tested'         => false,
					'requires'       => false,
					'rating'         => false,
					'downloaded'     => false,
					'last_updated'   => false,
					'added'          => false,
					'tags'           => false,
					'compatibility'  => false,
					'donate_link'    => false,
					'short_description' => false,
				),
			)
		);

		if ( is_wp_error( $api ) ) {
			return new WP_Error(
				'repo_lookup_failed',
				sprintf(
					/* translators: 1: slug, 2: error */
					__( 'Unable to fetch repository plugin "%1$s": %2$s', 'bulk-plugin-installer' ),
					$slug,
					$api->get_error_message()
				)
			);
		}

		if ( empty( $api->download_link ) ) {
			return new WP_Error(
				'repo_no_download_link',
				sprintf(
					/* translators: %s: slug */
					__( 'Repository plugin "%s" has no download link.', 'bulk-plugin-installer' ),
					$slug
				)
			);
		}

		return $api->download_link;
	}

	/**
	 * Locate the newly installed plugin entry file where possible.
	 *
	 * @param Plugin_Upgrader $upgrader Upgrader instance.
	 * @param array           $item Source item.
	 * @return string
	 */
	private static function detect_installed_plugin_file( $upgrader, $item ) {
		if ( ! empty( $upgrader->plugin_info() ) ) {
			return $upgrader->plugin_info();
		}

		if ( 'repo' === $item['type'] ) {
			$plugins = get_plugins( '/' . $item['value'] );
			if ( ! empty( $plugins ) ) {
				$keys = array_keys( $plugins );
				return $item['value'] . '/' . $keys[0];
			}
		}

		return '';
	}

	/**
	 * Output manifest syntax examples.
	 */
	private static function render_manifest_help() {
		?>
		<h2><?php esc_html_e( 'Manifest Examples', 'bulk-plugin-installer' ); ?></h2>
		<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:900px;overflow:auto;"># Repository slug (implicit)
akismet

# Repository slug (explicit)
repo:classic-editor

# Direct ZIP URL
url:https://downloads.wordpress.org/plugin/query-monitor.3.16.0.zip

# Uploaded local ZIP file name
zip:my-custom-plugin.zip</pre>
		<?php
	}

	/**
	 * Render results table.
	 *
	 * @param array $results Installation results.
	 */
	private static function render_results( $results ) {
		if ( empty( $results ) ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Results', 'bulk-plugin-installer' ); ?></h2>
		<table class="widefat striped" style="max-width:1000px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Source', 'bulk-plugin-installer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'bulk-plugin-installer' ); ?></th>
					<th><?php esc_html_e( 'Message', 'bulk-plugin-installer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $result ) : ?>
					<tr>
						<td><?php echo esc_html( $result['item'] ); ?></td>
						<td>
							<?php
							$status = isset( $result['status'] ) ? $result['status'] : 'info';
							echo esc_html( ucfirst( $status ) );
							?>
						</td>
						<td><?php echo esc_html( $result['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

BPI_Bulk_Plugin_Installer::init();
