<?php
/**
 * Admin Page class
 *
 * @package CCP
 */

namespace CCP\Admin;

use CCP\API\External_Posts_API;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Page Class
 */
class Admin_Page {

	/**
	 * External Posts API instance
	 *
	 * @var External_Posts_API
	 */
	private $api;

	/**
	 * Constructor
	 *
	 * @param External_Posts_API $api External Posts API instance.
	 */
	public function __construct( External_Posts_API $api ) {
		$this->api = $api;
	}

	/**
	 * Render the admin page
	 */
	public function render(): void {
		$site_url        = get_option( 'ccp_external_site_url', '' );
		$number_of_posts = get_option( 'ccp_number_of_posts', 10 );

		?>
		<div class="wrap" id="ccp-admin-page">
			<h1><?php esc_html_e( 'Compliant Content Publisher', 'ccp' ); ?></h1>

			<div class="ccp-admin-container">

				<div class="ccp-tools-section">
					<h2><?php esc_html_e( 'Tools', 'ccp' ); ?></h2>

					<!-- React Admin Tools component will be mounted here -->
					<div id="ccp-admin-tools-container">
						<div class="ccp-loading">
							<p><?php esc_html_e( 'Loading tools...', 'ccp' ); ?></p>
						</div>
					</div>

					<!-- Legacy results containers for compatibility -->
					<div id="ccp-test-results" class="ccp-results" style="display: none;"></div>
					<div id="ccp-preview-results" class="ccp-results" style="display: none;"></div>
				</div>

				<div class="ccp-dataviews-section">
					<h2><?php esc_html_e( 'Recent Posts from Non-Prod Site', 'ccp' ); ?></h2>

					<?php if ( empty( $site_url ) ) : ?>
						<div class="notice notice-warning">
							<p>
								<?php
								printf(
									/* translators: %s: Settings page URL */
									esc_html__( 'Please configure the non-prod site URL in the %s to see posts.', 'ccp' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=ccp-settings' ) ) . '">' . esc_html__( 'settings page', 'ccp' ) . '</a>'
								);
								?>
							</p>
						</div>
					<?php else : ?>
						<div id="ccp-dataviews-container">
							<div class="ccp-loading">
								<p><?php esc_html_e( 'Loading posts...', 'ccp' ); ?></p>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets with VIP compatibility
	 */
	public function enqueue_assets(): void {
		// Early return if not in admin or wrong page.
		if ( ! is_admin() ) {
			return;
		}

		// VIP-specific environment check.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
			// Add VIP-specific asset handling if needed.
			$this->enqueue_vip_safe_assets();
		} else {
			$this->enqueue_standard_assets();
		}
	}

	/**
	 * Enqueue assets with VIP-specific optimizations
	 */
	private function enqueue_vip_safe_assets(): void {
		$this->enqueue_standard_assets();
	}

	/**
	 * Enqueue standard assets
	 */
	private function enqueue_standard_assets(): void {
		// Get posts data for localization.
		$site_url        = get_option( 'ccp_external_site_url', '' );
		$number_of_posts = get_option( 'ccp_number_of_posts', 10 );
		$posts_data      = array();

		if ( ! empty( $site_url ) ) {
			// Pass authentication credentials if they exist.
			$auth_credentials = array();

			// VIP-safe authentication methods (prioritized).
			$shared_secret = get_option( 'ccp_shared_secret', '' );

			if ( ! empty( $shared_secret ) ) {
				$auth_credentials['shared_secret'] = $shared_secret;
			} elseif ( \ccp_is_development_environment() ) {
				// Fallback to Basic auth in development environments only.
				$username = get_option( 'ccp_username', '' );
				$password = get_option( 'ccp_password', '' );

				if ( ! empty( $username ) && ! empty( $password ) ) {
					$auth_credentials['username'] = $username;
					$auth_credentials['password'] = $password;
				}
			}

			$posts = $this->api->fetch_posts( $site_url, $number_of_posts, $auth_credentials );

			// Handle API errors.
			if ( ! is_wp_error( $posts ) ) {
				$posts_data = $posts;
			}
		}

		// Get the asset file for proper dependencies (TypeScript build).
		$asset_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/index.asset.php';

		// VIP-safe file inclusion with proper validation.
		$plugin_dir      = plugin_dir_path( dirname( __DIR__ ) );
		$real_plugin_dir = realpath( $plugin_dir );
		$real_asset_file = realpath( $asset_file_path );

		// Validate that the file exists and is within the plugin directory.
		if ( $real_asset_file &&
			file_exists( $asset_file_path ) &&
			0 === strpos( $real_asset_file, $real_plugin_dir ) &&
			'.php' === substr( $asset_file_path, -4 ) ) {
			// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Safe file inclusion within plugin directory with validation
			$asset_file = include $asset_file_path;
		} else {
			$asset_file = array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-dataviews' ),
				'version'      => '1.1.0',
			);
		}

		// VIP-safe dependency validation - ensure required scripts are available.
		$required_deps  = array( 'wp-element', 'wp-components' );
		$available_deps = array();

		// In VIP, ensure these core scripts are loaded first.
		foreach ( $required_deps as $dep ) {
			// Force register/enqueue core dependencies in VIP.
			if ( ! wp_script_is( $dep, 'registered' ) ) {
				wp_enqueue_script( $dep );
			}
			if ( wp_script_is( $dep, 'registered' ) || wp_script_is( $dep, 'enqueued' ) ) {
				$available_deps[] = $dep;
			}
		}

		// Add wp-dataviews if available, otherwise fallback gracefully.
		if ( wp_script_is( 'wp-dataviews', 'registered' ) ) {
			$available_deps[] = 'wp-dataviews';
		}

		// Use validated dependencies or fallback to minimal safe dependencies.
		$script_deps = ! empty( $available_deps ) ? $available_deps : array( 'wp-element' );

		// Enqueue the TypeScript DataViews script.
		$script_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'build/index.js';
		$script_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/index.js';

		// VIP-safe version handling - use plugin version as fallback.
		$script_version = $asset_file['version'];

		// In VIP environment, use plugin version instead of filemtime for better caching.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
			// Get plugin version for VIP environment.
			$plugin_data    = get_file_data( dirname( dirname( __DIR__ ) ) . '/compliant-content-publisher.php', array( 'Version' => 'Version' ) );
			$script_version = ! empty( $plugin_data['Version'] ) ? $plugin_data['Version'] : '1.1.0';
		} elseif ( file_exists( $script_path ) && function_exists( 'filemtime' ) ) {
			// Only use filemtime if available (not always available in VIP).
			$script_version = filemtime( $script_path );
		}

		// VIP environment: Ensure script file exists before enqueueing.
		if ( ! file_exists( $script_path ) ) {
			// Show admin notice for missing build files only in admin context.
			add_action(
				'admin_notices',
				function () {
					// Only show notices in admin context, not during REST API requests.
					if ( ! \is_admin() || \wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && \constant( 'REST_REQUEST' ) ) ) {
						return;
					}

					if ( defined( 'WP_DEBUG' ) && \constant( 'WP_DEBUG' ) ) {
						echo '<div class="notice notice-error"><p>';
						echo '<strong>Compliant Content Publisher:</strong> Build assets are missing. ';
						echo 'Run <code>npm run build</code> and commit the build files for VIP deployment.';
						echo '</p></div>';
					}
				}
			);

			return; // Exit early if script file doesn't exist.
		}

		wp_enqueue_script(
			'ccp-admin-dataviews-script',
			$script_url,
			$script_deps,
			$script_version,
			true
		);

		// Enqueue DataViews styles with VIP-safe versioning.
		$style_file_path = plugin_dir_path( dirname( __DIR__ ) ) . 'build/style-index.css';
		$style_file_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'build/style-index.css';

		if ( file_exists( $style_file_path ) ) {
			// Use same versioning strategy as scripts.
			$style_version = $script_version;

			wp_enqueue_style(
				'ccp-admin-dataviews-style',
				$style_file_url,
				array( 'wp-components' ),
				$style_version
			);
		}

		// Enqueue admin styles with VIP-safe versioning.
		$admin_css_path = plugin_dir_path( dirname( __DIR__ ) ) . 'assets/css/admin.css';
		$admin_css_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/admin.css';

		if ( file_exists( $admin_css_path ) ) {
			wp_enqueue_style(
				'ccp-admin-style',
				$admin_css_url,
				array(),
				$script_version
			);
		}

		// Enqueue React components styles.
		$react_css_path = plugin_dir_path( dirname( __DIR__ ) ) . 'assets/css/react-components.css';
		$react_css_url  = plugin_dir_url( dirname( __DIR__ ) ) . 'assets/css/react-components.css';

		if ( file_exists( $react_css_path ) ) {
			wp_enqueue_style(
				'ccp-react-components-style',
				$react_css_url,
				array( 'wp-components' ),
				$script_version
			);
		}

		// Localize scripts with data including posts.
		wp_localize_script(
			'ccp-admin-dataviews-script',
			'ccpAdminData',
			array(
				'ajaxurl'      => admin_url( 'admin-ajax.php' ),
				'settingsUrl'  => admin_url( 'admin.php?page=ccp-settings' ),
				'nonce'        => wp_create_nonce( 'ccp_ajax_nonce' ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'siteUrl'      => $site_url,
				'numPosts'     => $number_of_posts,
				'containerId' => 'ccp-dataviews-container',
				'postsData'    => $posts_data,
				'strings'     => array(
					'loading'      => __( 'Loading posts...', 'ccp' ),
					'error'        => __( 'Error loading posts.', 'ccp' ),
					'noResults'    => __( 'No posts found.', 'ccp' ),
					'title'        => __( 'Title', 'ccp' ),
					'lastModified' => __( 'Last Modified', 'ccp' ),
					'link'         => __( 'Link', 'ccp' ),
				),
			)
		);

		// Add hook to verify script was loaded properly in VIP.
		add_action( 'admin_print_footer_scripts', array( $this, 'verify_script_loaded' ), 999 );
	}

	/**
	 * Verify that scripts loaded properly (useful for VIP debugging).
	 */
	public function verify_script_loaded(): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && wp_script_is( 'ccp-admin-dataviews-script', 'done' ) ) {
			echo '<!-- CCP: Admin script loaded successfully -->';
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			echo '<!-- CCP: Admin script failed to load -->';
		}
	}
}
