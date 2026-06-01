<?php
/**
 * Shared admin asset enqueueing helper.
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
 * Enqueues a built admin bundle plus the shared style/data wiring.
 *
 * The Source Posts, Imports, and Exports admin pages each enqueue the same
 * shape: one entry script, the design tokens, the bundle's split-out
 * style-index.css, the static admin/react-components stylesheets, and an
 * inline `window.safePublishAdminData = {...}` for the React side. This
 * helper centralizes that wiring so a new page can opt in by calling
 * {@see self::enqueue_bundle()} with its own entry name and inline data.
 */
final class Admin_Assets {

	/**
	 * Enqueues a built entry script, the shared stylesheets, and the page's
	 * inline admin-data global.
	 *
	 * Returns early when the build output is missing — fresh checkouts that
	 * haven't run `npm run build` would otherwise enqueue a 404. Surfaces a
	 * one-time admin notice in `WP_DEBUG` so developers know what's missing.
	 *
	 * @param string               $entry         Bundle entry name (`index`/`imports`/`exports`),
	 *                                            locating `build/{entry}.js` and
	 *                                            `build/{entry}.asset.php`.
	 * @param string               $script_handle Handle to register the entry script under.
	 * @param string               $style_handle  Handle to register the bundle's split-out
	 *                                            style-index.css under, when that file exists.
	 * @param array<string, mixed> $inline_data   JSON-encodable payload assigned to
	 *                                            `window.safePublishAdminData`.
	 */
	public static function enqueue_bundle(
		string $entry,
		string $script_handle,
		string $style_handle,
		array $inline_data
	): void {
		$base_path = plugin_dir_path( dirname( __DIR__ ) );
		$base_url  = plugin_dir_url( dirname( __DIR__ ) );

		$asset_file_path = $base_path . 'build/' . $entry . '.asset.php';
		$script_url      = $base_url . 'build/' . $entry . '.js';
		$script_path     = $base_path . 'build/' . $entry . '.js';

		if ( ! file_exists( $script_path ) || ! file_exists( $asset_file_path ) ) {
			self::queue_missing_build_notice();
			return;
		}

		// Path is built from plugin_dir_path() and a hardcoded filename.
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		$asset_file     = include $asset_file_path;
		$script_version = $asset_file['version'];

		wp_enqueue_script(
			$script_handle,
			$script_url,
			$asset_file['dependencies'],
			$script_version,
			true
		);

		wp_enqueue_style(
			'safe-publish-tokens',
			$base_url . 'assets/css/tokens.css',
			array(),
			$script_version
		);

		// @wordpress/scripts merges the SCSS imports from every entry into a
		// single style-index.css via splitChunks; load it when present so the
		// page picks up component styles like .safe-publish-status-badge.
		$style_file_path = $base_path . 'build/style-index.css';
		$style_file_url  = $base_url . 'build/style-index.css';

		if ( file_exists( $style_file_path ) ) {
			wp_enqueue_style(
				$style_handle,
				$style_file_url,
				array( 'wp-components', 'safe-publish-tokens' ),
				$script_version
			);
		}

		wp_enqueue_style(
			'safe-publish-admin-style',
			$base_url . 'assets/css/admin.css',
			array( 'safe-publish-tokens' ),
			$script_version
		);

		wp_enqueue_style(
			'safe-publish-react-components-style',
			$base_url . 'assets/css/react-components.css',
			array( 'wp-components', 'safe-publish-tokens' ),
			$script_version
		);

		$json_data = wp_json_encode( $inline_data );

		if ( false === $json_data || '' === $json_data ) {
			$json_data = '{}';
		}

		wp_add_inline_script(
			$script_handle,
			sprintf( 'window.safePublishAdminData = %s;', $json_data ),
			'before'
		);
	}

	/**
	 * Surfaces a "Build assets are missing" notice when WP_DEBUG is on.
	 *
	 * Skips REST/AJAX so the notice only renders on real admin pageviews.
	 */
	private static function queue_missing_build_notice(): void {
		add_action(
			'admin_notices',
			static function (): void {
				if (
					! is_admin()
					|| wp_doing_ajax()
					|| ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) )
				) {
					return;
				}

				if ( ! defined( 'WP_DEBUG' ) || ! constant( 'WP_DEBUG' ) ) {
					return;
				}

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
		);
	}
}
