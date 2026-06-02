<?php
/**
 * Imports Page class
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
 * Renders the Imports admin page and enqueues its bundle.
 *
 * The PHP side only outputs the wrapper + mount container and seeds initial
 * state (ajaxurl, nonces, initial tab) via the inline `safePublishAdminData`
 * global. The tab UI, data fetching, and listing live entirely in React
 * (see `ImportsApp.tsx`).
 */
final class Imports_Page {

	/**
	 * Renders the admin page.
	 */
	public function render(): void {
		?>
		<div class="wrap" id="safe-publish-imports-page">
			<h1><?php esc_html_e( 'Imports', 'safe-publish' ); ?></h1>

			<div class="safe-publish-admin-container">
				<div class="safe-publish-dataviews-section">
					<div id="safe-publish-imports-container">
						<div class="safe-publish-loading">
							<p><?php esc_html_e( 'Loading imports…', 'safe-publish' ); ?></p>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueues the Imports page assets.
	 */
	public function enqueue_assets(): void {
		if ( ! is_admin() ) {
			return;
		}

		$asset_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/imports.asset.php';
		$script_url      = plugin_dir_url( dirname( __DIR__ ) ) . 'build/imports.js';
		$script_path     = plugin_dir_path( dirname( __DIR__ ) ) . 'build/imports.js';

		if ( ! file_exists( $script_path ) || ! file_exists( $asset_file_path ) ) {
			add_action(
				'admin_notices',
				function () {
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
			'safe-publish-imports-script',
			$script_url,
			$asset_file['dependencies'],
			$script_version,
			true
		);

		wp_enqueue_style(
			'safe-publish-tokens',
			plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/tokens.css',
			array(),
			$script_version
		);

		// @wordpress/scripts merges the SCSS imports from every entry into a
		// single style-index.css via splitChunks.
		$style_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/style-index.css';
		$style_file_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'build/style-index.css';

		if ( file_exists( $style_file_path ) ) {
			wp_enqueue_style(
				'safe-publish-imports-style',
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

		// Read ?tab=... from the URL so React can pre-apply it without an
		// extra roundtrip. ?batch=N is read directly client-side so a
		// tab-switch re-mount picks up an in-page Clear that updated the URL
		// but not this inline global.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$initial_tab_raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$initial_tab     = in_array( $initial_tab_raw, array( 'posts', 'failures' ), true )
			? $initial_tab_raw
			: 'posts';

		$json_data = wp_json_encode(
			array(
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'settingsUrl' => admin_url( 'admin.php?page=safe-publish-settings' ),
				'nonce'       => wp_create_nonce( 'safe_publish_ajax_nonce' ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
				'containerId' => 'safe-publish-imports-container',
				'initialTab'  => $initial_tab,
			)
		);

		if ( false === $json_data || '' === $json_data ) {
			$json_data = '{}';
		}

		// Same global the Source Posts page and the shared modals/diff hooks
		// read, so Update/Diff/Delete work here without page-specific wiring.
		wp_add_inline_script(
			'safe-publish-imports-script',
			sprintf( 'window.safePublishAdminData = %s;', $json_data ),
			'before'
		);
	}
}
