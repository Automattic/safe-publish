<?php
/**
 * Imported Posts Page class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the Imported Posts admin page and enqueues its bundle.
 *
 * The listing is driven by the import_items table — one row per post_id at its
 * most recent import event, not by post meta. Pure local query — no source
 * roundtrip on listing.
 */
final class Imported_Posts_Page {

	/**
	 * Renders the admin page.
	 */
	public function render(): void {
		?>
		<div class="wrap" id="safe-publish-imported-page">
			<h1><?php esc_html_e( 'Imported Posts', 'safe-publish' ); ?></h1>

			<div class="safe-publish-admin-container">
				<div class="safe-publish-dataviews-section">
					<div id="safe-publish-imported-container">
						<div class="safe-publish-loading">
							<p><?php esc_html_e( 'Loading imported posts…', 'safe-publish' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueues the Imported Posts page assets.
	 */
	public function enqueue_assets(): void {
		if ( ! is_admin() ) {
			return;
		}

		$asset_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/imported.asset.php';
		$script_url      = plugin_dir_url( dirname( __DIR__ ) ) . 'build/imported.js';
		$script_path     = plugin_dir_path( dirname( __DIR__ ) ) . 'build/imported.js';

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
						printf(
							/* translators: %s: the "npm run build" command. */
							esc_html__( 'Run %s to generate them.', 'safe-publish' ),
							'<code>npm run build</code>'
						);
						echo '</p></div>';
					}
				}
			);

			return;
		}

		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Path is built from plugin_dir_path() and a hardcoded filename.
		$asset_file     = include $asset_file_path;
		$script_version = $asset_file['version'];

		wp_enqueue_script(
			'safe-publish-imported-script',
			$script_url,
			$asset_file['dependencies'],
			$script_version,
			true
		);

		// Reuse the shared design tokens enqueued by the Source Posts page.
		wp_enqueue_style(
			'safe-publish-tokens',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/tokens.css',
			array(),
			$script_version
		);

		// @wordpress/scripts merges the SCSS imports from every entry into a
		// single style-index.css via splitChunks, so the Imported Posts page
		// enqueues the same shared bundle as the Source Posts page.
		$style_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/style-index.css';
		$style_file_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'build/style-index.css';

		if ( file_exists( $style_file_path ) ) {
			wp_enqueue_style(
				'safe-publish-imported-style',
				$style_file_url,
				array( 'wp-components', 'safe-publish-tokens' ),
				$script_version
			);
		}

		wp_enqueue_style(
			'safe-publish-admin-style',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/admin.css',
			array( 'safe-publish-tokens' ),
			$script_version
		);

		wp_enqueue_style(
			'safe-publish-react-components-style',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/react-components.css',
			array( 'wp-components', 'safe-publish-tokens' ),
			$script_version
		);

		$json_data = wp_json_encode(
			array(
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'settingsUrl' => admin_url( 'admin.php?page=safe-publish-settings' ),
				'nonce'       => wp_create_nonce( 'safe_publish_ajax_nonce' ),
				'containerId' => 'safe-publish-imported-container',
			)
		);

		if ( false === $json_data || '' === $json_data ) {
			$json_data = '{}';
		}

		wp_add_inline_script(
			'safe-publish-imported-script',
			sprintf( 'window.safePublishImportedData = %s;', $json_data ),
			'before'
		);
	}
}
