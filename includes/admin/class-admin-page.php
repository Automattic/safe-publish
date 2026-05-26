<?php
/**
 * Admin Page class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Options;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Page Class.
 */
final class Admin_Page {

	/**
	 * Renders the admin page.
	 */
	public function render(): void {
		$source_site_url = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );

		?>
		<div class="wrap" id="safe-publish-admin-page">
			<h1><?php esc_html_e( 'Safe Publish', 'safe-publish' ); ?></h1>

			<div class="safe-publish-admin-container">
				<div class="safe-publish-dataviews-section">
					<h2>
					<?php
					if ( ! empty( $source_site_url ) ) {
						printf(
							/* translators: %s: source site URL */
							esc_html__( 'Posts from %s', 'safe-publish' ),
							esc_url( $source_site_url )
						);
					} else {
						esc_html_e( 'Posts from Source Site', 'safe-publish' );
					}
					?>
				</h2>

					<?php if ( empty( $source_site_url ) ) : ?>
						<div class="notice notice-warning">
							<p>
								<?php
								printf(
									/* translators: %s: Settings page URL */
									esc_html__( 'Please configure the connected site URL in the %s to see posts.', 'safe-publish' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=safe-publish-settings' ) ) . '">' . esc_html__( 'settings page', 'safe-publish' ) . '</a>'
								);
								?>
							</p>
						</div>
					<?php else : ?>
						<div id="safe-publish-dataviews-container">
							<div class="safe-publish-loading">
								<p><?php esc_html_e( 'Loading posts…', 'safe-publish' ); ?></p>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueues admin assets.
	 */
	public function enqueue_assets(): void {
		if ( ! is_admin() ) {
			return;
		}

		$asset_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/index.asset.php';
		$script_url      = plugin_dir_url( dirname( __DIR__ ) ) . 'build/index.js';
		$script_path     = plugin_dir_path( dirname( __DIR__ ) ) . 'build/index.js';

		if ( ! file_exists( $script_path ) || ! file_exists( $asset_file_path ) ) {
			add_action(
				'admin_notices',
				function () {
					// Skip during REST/AJAX so the notice only renders on real admin pageviews.
					if ( ! is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) ) {
						return;
					}

					if ( defined( 'WP_DEBUG' ) && constant( 'WP_DEBUG' ) ) {
						echo '<div class="notice notice-error"><p>';
						echo '<strong>' . esc_html__( 'Safe Publish:', 'safe-publish' ) . '</strong> ';
						echo esc_html__( 'Build assets are missing. ', 'safe-publish' );
						/* translators: npm run build is a command and should not be translated */
						echo esc_html__( 'Run <code>npm run build</code> to generate them.', 'safe-publish' );
						echo '</p></div>';
					}
				}
			);

			return;
		}

		$source_site_url = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path is built from plugin_dir_path() and a hardcoded filename.
		$asset_file     = include $asset_file_path;
		$script_version = $asset_file['version'];

		wp_enqueue_script(
			'safe-publish-admin-dataviews-script',
			$script_url,
			$asset_file['dependencies'],
			$script_version,
			true
		);

		// Enqueue shared design tokens before any plugin stylesheet.
		wp_enqueue_style(
			'safe-publish-tokens',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/tokens.css',
			array(),
			$script_version
		);

		// Enqueue DataViews styles.
		$style_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/style-index.css';
		$style_file_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'build/style-index.css';

		if ( file_exists( $style_file_path ) ) {
			wp_enqueue_style(
				'safe-publish-admin-dataviews-style',
				$style_file_url,
				array( 'wp-components', 'safe-publish-tokens' ),
				$script_version
			);
		}

		// Enqueue admin styles.
		wp_enqueue_style(
			'safe-publish-admin-style',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/admin.css',
			array( 'safe-publish-tokens' ),
			$script_version
		);

		// Enqueue React components styles.
		wp_enqueue_style(
			'safe-publish-react-components-style',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/react-components.css',
			array( 'wp-components', 'safe-publish-tokens' ),
			$script_version
		);

		$json_data = wp_json_encode(
			array(
				'ajaxurl'       => admin_url( 'admin-ajax.php' ),
				'settingsUrl'   => admin_url( 'admin.php?page=safe-publish-settings' ),
				'nonce'         => wp_create_nonce( 'safe_publish_ajax_nonce' ),
				'restNonce'     => wp_create_nonce( 'wp_rest' ),
				'sourceSiteUrl' => $source_site_url,
				'containerId'   => 'safe-publish-dataviews-container',
			)
		);

		if ( false === $json_data || '' === $json_data ) {
			$json_data = '{}';
		}

		wp_add_inline_script(
			'safe-publish-admin-dataviews-script',
			sprintf( 'window.safePublishAdminData = %s;', $json_data ),
			'before'
		);
	}
}
