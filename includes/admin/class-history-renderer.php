<?php
/**
 * History Renderer class for import history display logic
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Event_Table;
use Safe_Publish\Utils\Options;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * History Renderer Class.
 *
 * Handles all display and rendering operations for import history.
 */
final class History_Renderer {

	/**
	 * Renders the import history page.
	 */
	public function render_history_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'safe-publish' ) );
		}

		// Enqueue necessary scripts and styles for React.
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_script( 'wp-components' );
		wp_enqueue_script( 'wp-i18n' );

		// Try to enqueue wp-dataviews if available.
		if ( wp_script_is( 'wp-dataviews', 'registered' ) ) {
			wp_enqueue_script( 'wp-dataviews' );
		}

		// Enqueue custom CSS.
		$css_file = plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/history.css';
		wp_enqueue_style( 'safe-publish-history', $css_file, array(), '1.0.0' );

		// Enqueue DataViews styles with VIP-safe versioning.
		$style_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/style-index.css';
		$style_file_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'build/style-index.css';

		if ( file_exists( $style_file_path ) ) {
			wp_enqueue_style(
				'safe-publish-admin-dataviews-style',
				$style_file_url,
				array( 'wp-components' ),
				filemtime( $style_file_path )
			);
		}

		// Enqueue the compiled history JavaScript.
		$js_file       = plugin_dir_url( dirname( __DIR__ ) ) . 'build/history.js';
		$js_asset_file = dirname( __DIR__ ) . '/build/history.asset.php';

		$asset_data = array(
			'dependencies' => array( 'wp-element', 'wp-components', 'wp-i18n' ),
			'version'      => '1.0.0',
		);

		if ( file_exists( $js_asset_file ) ) {
			$asset_data = require $js_asset_file;
		}

		wp_enqueue_script(
			'safe-publish-history',
			$js_file,
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		$json_data = wp_json_encode(
			array(
				'ajaxurl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'safe_publish_ajax_nonce' ),
				'restNonce'         => wp_create_nonce( 'wp_rest' ),
				'showImportHistory' => $this->should_show_import_history(),
				'showExportHistory' => $this->should_show_export_history(),
			)
		);

		if ( false === $json_data || '' === $json_data ) {
			$json_data = '{}';
		}

		wp_add_inline_script(
			'safe-publish-history',
			sprintf( 'window.safePublishAdminData = %s;', $json_data ),
			'before'
		);

		?>
		<div class="wrap" id="safe-publish-history">
			<h1><?php esc_html_e( 'History', 'safe-publish' ); ?></h1>

			<!-- React component will be rendered here -->
			<div id="safe-publish-history-container">
				<div class="safe-publish-loading">
					<p><?php esc_html_e( 'Loading history…', 'safe-publish' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Whether to show the import history tab.
	 *
	 * True when the site is configured to receive content, or when there are
	 * existing import session records (e.g. the site was previously bidirectional).
	 *
	 * @return bool Whether import history should be shown.
	 */
	private function should_show_import_history(): bool {
		$sync_mode = get_option( Options::OPTION_SYNC_MODE, '' );

		if ( in_array( $sync_mode, array( Options::SYNC_MODE_RECEIVE, Options::SYNC_MODE_BOTH ), true ) ) {
			return true;
		}

		$counts = wp_count_posts( History_Repository::SESSION_POST_TYPE );
		return isset( $counts->publish ) && $counts->publish > 0;
	}

	/**
	 * Whether to show the export history tab.
	 *
	 * True when the site is configured to send content, or when there are
	 * existing export event records (e.g. the site was previously bidirectional).
	 *
	 * @return bool Whether export history should be shown.
	 */
	private function should_show_export_history(): bool {
		$sync_mode = get_option( Options::OPTION_SYNC_MODE, '' );

		if ( in_array( $sync_mode, array( Options::SYNC_MODE_SEND, Options::SYNC_MODE_BOTH ), true ) ) {
			return true;
		}

		return Event_Table::count( array( 'channel' => 'export' ) ) > 0;
	}

	/**
	 * Generates comprehensive HTML diff between old and new content.
	 *
	 * @param string $old_title   Old title.
	 * @param string $new_title   New title.
	 * @param string $old_excerpt Old excerpt.
	 * @param string $new_excerpt New excerpt.
	 * @param string $old_content Old content.
	 * @param string $new_content New content.
	 * @return string HTML diff.
	 */
	public function generate_comprehensive_diff_html(
		string $old_title,
		string $new_title,
		string $old_excerpt,
		string $new_excerpt,
		string $old_content,
		string $new_content
	): string {
		$diff_html = '<div class="safe-publish-diff-container">';

		// Title diff.
		if ( $old_title !== $new_title ) {
			$diff_html .= '<div class="safe-publish-diff-section">';
			$diff_html .= '<h4>' . __( 'Title Changes', 'safe-publish' ) . '</h4>';
			$diff_html .= '<div class="safe-publish-diff-comparison">';
			$diff_html .= '<div class="safe-publish-diff-before" style="background: #f8d7da; padding: 10px; margin-bottom: 10px; border-radius: 4px;">';
			$diff_html .= '<strong>' . __( 'Before:', 'safe-publish' ) . '</strong><br>';
			$diff_html .= esc_html( $old_title );
			$diff_html .= '</div>';
			$diff_html .= '<div class="safe-publish-diff-after" style="background: #d4edda; padding: 10px; border-radius: 4px;">';
			$diff_html .= '<strong>' . __( 'After:', 'safe-publish' ) . '</strong><br>';
			$diff_html .= esc_html( $new_title );
			$diff_html .= '</div>';
			$diff_html .= '</div>';
			$diff_html .= '</div>';
		}

		// Excerpt diff.
		if ( $old_excerpt !== $new_excerpt ) {
			$diff_html .= '<div class="safe-publish-diff-section" style="margin-top: 20px;">';
			$diff_html .= '<h4>' . __( 'Excerpt Changes', 'safe-publish' ) . '</h4>';
			$diff_html .= '<div class="safe-publish-diff-comparison">';
			$diff_html .= '<div class="safe-publish-diff-before" style="background: #f8d7da; padding: 10px; margin-bottom: 10px; border-radius: 4px;">';
			$diff_html .= '<strong>' . __( 'Before:', 'safe-publish' ) . '</strong><br>';
			$diff_html .= '<pre>' . esc_html( $old_excerpt ) . '</pre>';
			$diff_html .= '</div>';
			$diff_html .= '<div class="safe-publish-diff-after" style="background: #d4edda; padding: 10px; border-radius: 4px;">';
			$diff_html .= '<strong>' . __( 'After:', 'safe-publish' ) . '</strong><br>';
			$diff_html .= '<pre>' . esc_html( $new_excerpt ) . '</pre>';
			$diff_html .= '</div>';
			$diff_html .= '</div>';
			$diff_html .= '</div>';
		}

		// Content diff.
		$diff_html .= '<div class="safe-publish-diff-section" style="margin-top: 20px;">';
		$diff_html .= '<h4>' . __( 'Content Changes', 'safe-publish' ) . '</h4>';
		$diff_html .= '<div class="safe-publish-diff-comparison">';
		$diff_html .= '<div class="safe-publish-diff-before" style="background: #f8d7da; padding: 10px; margin-bottom: 10px; border-radius: 4px;">';
		$diff_html .= '<strong>' . __( 'Before (Original Content):', 'safe-publish' ) . '</strong><br>';
		$diff_html .= '<pre style="white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow-y: auto;">' . esc_html( $old_content ) . '</pre>';
		$diff_html .= '</div>';
		$diff_html .= '<div class="safe-publish-diff-after" style="background: #d4edda; padding: 10px; border-radius: 4px;">';
		$diff_html .= '<strong>' . __( 'After (Imported Content):', 'safe-publish' ) . '</strong><br>';
		$diff_html .= '<pre style="white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow-y: auto;">' . esc_html( $new_content ) . '</pre>';
		$diff_html .= '</div>';
		$diff_html .= '</div>';
		$diff_html .= '</div>';

		$diff_html .= '</div>';

		return $diff_html;
	}

	/**
	 * Generates a no-diff message when previous content is unavailable.
	 *
	 * @param string $new_title   New title.
	 * @param string $new_excerpt New excerpt.
	 * @param string $new_content New content.
	 * @return string HTML for no-diff message.
	 */
	public function generate_no_diff_message(
		string $new_title,
		string $new_excerpt,
		string $new_content
	): string {
		$diff_html  = '<div class="safe-publish-no-diff-message" style="padding: 20px; text-align: center; background: #f9f9f9; border-radius: 4px;">';
		$diff_html .= '<h4>' . __( 'No Previous Content Available', 'safe-publish' ) . '</h4>';
		$diff_html .= '<p>' . __( 'This import was processed before content change tracking was enabled. Only the current content is available.', 'safe-publish' ) . '</p>';
		$diff_html .= '<div style="background: #fff; padding: 15px; border-radius: 4px; margin-top: 15px; text-align: left;">';
		$diff_html .= '<h5>' . __( 'Current Content:', 'safe-publish' ) . '</h5>';

		if ( $new_title ) {
			$diff_html .= '<p><strong>' . __( 'Title:', 'safe-publish' ) . '</strong> ' . esc_html( $new_title ) . '</p>';
		}

		if ( $new_excerpt ) {
			$diff_html .= '<p><strong>' . __( 'Excerpt:', 'safe-publish' ) . '</strong></p>';
			$diff_html .= '<div style="background: #f8f8f8; padding: 10px; border-radius: 3px; margin: 5px 0;"><pre style="white-space: pre-wrap; margin: 0;">' . esc_html( $new_excerpt ) . '</pre></div>';
		}

		$diff_html .= '<p><strong>' . __( 'Content:', 'safe-publish' ) . '</strong></p>';
		$diff_html .= '<div style="background: #f8f8f8; padding: 10px; border-radius: 3px; max-height: 300px; overflow-y: auto;"><pre style="white-space: pre-wrap; margin: 0;">' . esc_html( $new_content ) . '</pre></div>';
		$diff_html .= '</div>';
		$diff_html .= '</div>';

		return $diff_html;
	}
}
