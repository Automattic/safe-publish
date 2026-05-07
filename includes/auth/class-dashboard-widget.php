<?php
/**
 * Dashboard Widget class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Auth;

use Safe_Publish\Utils\Audit_Log_Table;

/**
 * Manages the Safe Publish admin dashboard widget and related admin UI.
 *
 * Registers and renders the authentication status dashboard widget,
 * admin notices, the Site Health test, and MU-plugin display styles.
 */
class Dashboard_Widget {

	/**
	 * Shared secret for authentication status display.
	 *
	 * @var string
	 */
	private string $shared_secret;

	/**
	 * Constructor.
	 *
	 * @param string $shared_secret Shared secret for authentication status display.
	 */
	public function __construct( string $shared_secret ) {
		$this->shared_secret = $shared_secret;
		add_action( 'wp_dashboard_setup', array( $this, 'register' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notice' ) );
		add_filter( 'site_status_tests', array( $this, 'register_site_health_test' ) );
		add_filter( 'show_advanced_plugins', array( $this, 'enhance_mu_plugins_display' ), 10, 2 );
	}

	/**
	 * Registers the authentication status dashboard widget.
	 */
	public function register(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'safe_publish_auth_status',
			'Safe Publish Authentication Status',
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the dashboard widget content.
	 */
	public function render(): void {
		$secret_length = strlen( $this->shared_secret );
		$recent_events = Audit_Log_Table::get_events(
			array(
				'channel' => 'auth',
				'limit'   => 10,
			)
		);

		echo '<div class="safe-publish-dashboard-widget">';
		$this->render_status_section( $this->shared_secret, $secret_length );
		$this->render_recent_events_section( $recent_events );
		echo '<hr style="margin: 15px 0;">';
		$this->render_debug_section();
		echo '<hr style="margin: 15px 0;">';
		echo '<p><small>' . esc_html__( 'MU-Plugin: Safe Publish VIP Authentication Handler with Enhanced Logging v1.1.0', 'safe-publish' ) . '</small></p>';
		echo '</div>';
	}

	/**
	 * Renders the admin notice about authentication configuration status.
	 *
	 * Only displayed to administrators on the dashboard and plugins pages.
	 */
	public function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}

		$secret_length = strlen( $this->shared_secret );

		if ( empty( $this->shared_secret ) ) {
			wp_admin_notice(
				__( 'Safe Publish Authentication: Shared secret not configured. Set the <code>SAFE_PUBLISH_SHARED_SECRET</code> environment variable in VIP dashboard to enable Safe Publish authentication.', 'safe-publish' ),
				array( 'type' => 'warning' )
			);
		} elseif ( $secret_length < 32 ) {
			wp_admin_notice(
				sprintf(
					/* translators: %d: Length of the shared secret in characters */
					__( 'Safe Publish Authentication: Shared secret is too short ( %d character secret). Use at least 32 characters for security.', 'safe-publish' ),
					absint( $secret_length )
				),
				array( 'type' => 'warning' )
			);
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			wp_admin_notice(
				sprintf(
					/* translators: %d: Length of the shared secret in characters */
					__( 'Safe Publish Authentication: Configured successfully ✅ ( %d character secret).', 'safe-publish' ),
					absint( $secret_length )
				),
				array(
					'dismissible' => true,
					'type'        => 'warning',
				)
			);
		}
	}

	/**
	 * Registers the Safe Publish authentication test with WordPress Site Health.
	 *
	 * @param array $tests Existing Site Health tests.
	 * @return array Modified tests array with Safe Publish auth test added.
	 */
	public function register_site_health_test( array $tests ): array {
		$tests['direct']['safe_publish_auth'] = array(
			'label' => __( 'Safe Publish Authentication Configuration', 'safe-publish' ),
			'test'  => array( $this, 'site_health_test' ),
		);

		return $tests;
	}

	/**
	 * Returns the Site Health test result for authentication configuration.
	 *
	 * @return array Site Health test result.
	 */
	public function site_health_test(): array {
		$secret_length = strlen( $this->shared_secret );

		if ( empty( $this->shared_secret ) ) {
			return array(
				'label'       => __( 'Safe Publish Authentication not configured', 'safe-publish' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Safe Publish', 'safe-publish' ),
					'color' => 'orange',
				),
				'description' => sprintf(
					'<p>%s</p>',
					__( 'The Safe Publish shared secret is not configured. If you plan to use Safe Publish, set the SAFE_PUBLISH_SHARED_SECRET environment variable.', 'safe-publish' )
				),
				'test'        => 'safe_publish_auth',
			);
		}

		if ( $secret_length < 32 ) {
			return array(
				'label'       => __( 'Safe Publish Authentication secret too short', 'safe-publish' ),
				'status'      => 'critical',
				'badge'       => array(
					'label' => __( 'Safe Publish', 'safe-publish' ),
					'color' => 'red',
				),
				'description' => sprintf(
					'<p>%s</p>',
					/* translators: %d: length of the shared secret in characters */
					sprintf( __( 'The Safe Publish shared secret is only %d characters long. For security, use at least 32 characters.', 'safe-publish' ), $secret_length )
				),
				'test'        => 'safe_publish_auth',
			);
		}

		return array(
			'label'       => __( 'Safe Publish Authentication configured correctly', 'safe-publish' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Safe Publish', 'safe-publish' ),
				'color' => 'green',
			),
			'description' => sprintf(
				'<p>%s</p>',
				/* translators: %d: length of the shared secret in characters */
				sprintf( __( 'Safe Publish authentication is properly configured with a %d-character shared secret.', 'safe-publish' ), $secret_length )
			),
			'test'        => 'safe_publish_auth',
		);
	}

	/**
	 * Enhances the MU-plugins display with custom styles for Safe Publish.
	 *
	 * @param bool   $show_advanced_plugins Whether to show advanced plugins.
	 * @param string $type                  Plugin type ('mustuse', 'dropins').
	 * @return bool Show advanced plugins value, unchanged.
	 */
	public function enhance_mu_plugins_display( bool $show_advanced_plugins, string $type ): bool {
		if ( 'mustuse' === $type && current_user_can( 'manage_options' ) ) {
			add_action( 'admin_footer', array( $this, 'add_mu_plugin_styles' ) );
		}

		return $show_advanced_plugins;
	}

	/**
	 * Outputs custom styles for MU-plugin display.
	 */
	public function add_mu_plugin_styles(): void {
		?>
		<style>
		.mu-plugin[data-plugin="safe-publish-auth.php"] {
			background-color: #f0f6fc;
			border-left: 4px solid #0073aa;
			padding: 10px;
		}
		.mu-plugin[data-plugin="safe-publish-auth.php"] .plugin-title strong {
			color: #0073aa;
		}
		.safe-publish-dashboard-widget {
			font-size: 13px;
		}
		.safe-publish-dashboard-widget code {
			background: #f1f1f1;
			padding: 2px 4px;
			border-radius: 3px;
		}
		</style>
		<?php
	}

	// =========================================================================
	// Private rendering helpers
	// =========================================================================

	/**
	 * Renders the authentication status section of the dashboard widget.
	 *
	 * @param string $shared_secret The shared secret value.
	 * @param int    $secret_length Length of the shared secret.
	 */
	private function render_status_section( string $shared_secret, int $secret_length ): void {
		if ( empty( $shared_secret ) ) {
			echo '<p><span style="color: #d63638;">❌</span> <strong>' . esc_html__( 'Not Configured', 'safe-publish' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Set the SAFE_PUBLISH_SHARED_SECRET environment variable in VIP dashboard.', 'safe-publish' ) . '</p>';
			echo '<p><a href="https://dashboard.wpvip.com/" target="_blank">' . esc_html__( 'Open VIP Dashboard →', 'safe-publish' ) . '</a></p>';
			return;
		}

		if ( $secret_length < 32 ) {
			echo '<p><span style="color: #dba617;">⚠️</span> <strong>' . esc_html__( 'Secret Too Short', 'safe-publish' ) . '</strong></p>';
			echo '<p>' . sprintf(
				/* translators: %d: current secret length in characters */
				esc_html__( 'Current length: %d characters. Recommend 32+ for security.', 'safe-publish' ),
				absint( $secret_length )
			) . '</p>';
			return;
		}

		echo '<p><span style="color: #00a32a;">✅</span> <strong>' . esc_html__( 'Properly Configured', 'safe-publish' ) . '</strong></p>';
		echo '<p><strong>✅ ' . esc_html__( 'Secret length:', 'safe-publish' ) . '</strong> ' . sprintf(
			/* translators: %d: secret length in characters */
			esc_html__( '%d characters', 'safe-publish' ),
			absint( $secret_length )
		) . '</p>';
		echo '<p><strong>✅ ' . esc_html__( 'VIP 2FA Compliant:', 'safe-publish' ) . '</strong> ' . esc_html__( 'Uses capability-based authentication (no user creation)', 'safe-publish' ) . '</p>';
		echo '<p><strong>✅ ' . esc_html__( 'Editing Permissions:', 'safe-publish' ) . '</strong> ' . esc_html__( 'Enabled for Safe Publish authenticated requests', 'safe-publish' ) . '</p>';

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			echo '<p><a href="/wp-json/safe-publish/v1/auth-test" target="_blank">' . esc_html__( 'Test Authentication →', 'safe-publish' ) . '</a></p>';
		}
	}

	/**
	 * Renders the recent authentication events section of the dashboard widget.
	 *
	 * @param array $recent_events Recent authentication events from options.
	 */
	private function render_recent_events_section( array $recent_events ): void {
		if ( empty( $recent_events ) ) {
			return;
		}

		echo '<hr style="margin: 15px 0;">';
		echo '<h4 style="margin: 10px 0;">' . esc_html__( '📋 Recent Authentication Events', 'safe-publish' ) . '</h4>';
		echo '<div style="max-height: 200px; overflow-y: auto; font-size: 12px;">';

		foreach ( $recent_events as $event ) {
			$this->render_event_item( $event );
		}

		echo '</div>';
	}

	/**
	 * Renders a single authentication event item.
	 *
	 * @param array $event Authentication event data.
	 */
	private function render_event_item( array $event ): void {
		$event_type    = $event['event'];
		$timestamp_gmt = (string) $event['created_at_gmt'];
		$timestamp     = wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $timestamp_gmt . ' UTC' )
		);

		$icon  = '•';
		$color = '#666';

		if ( strpos( $event_type, 'SUCCESS' ) !== false ) {
			$icon  = '✅';
			$color = '#00a32a';
		} elseif ( strpos( $event_type, 'INVALID' ) !== false || strpos( $event_type, 'EXPIRED' ) !== false ) {
			$icon  = '❌';
			$color = '#d63638';
		} elseif ( strpos( $event_type, 'NO_SECRET' ) !== false ) {
			$icon  = '⚠️';
			$color = '#dba617';
		}

		echo '<div style="margin-bottom: 5px; padding: 5px; background: #f9f9f9; border-left: 3px solid ' . esc_attr( $color ) . ';">';
		echo '<span style="color: ' . esc_attr( $color ) . ';">' . esc_html( $icon ) . '</span> ';
		echo '<strong>' . esc_html( $event_type ) . '</strong>';
		echo '<br><small>' . esc_html( $timestamp ) . '</small>';

		if ( isset( $event['data']['route'] ) ) {
			echo '<br><small><code>' . esc_html( $event['data']['route'] ) . '</code></small>';
		}

		if ( isset( $event['data']['method'] ) ) {
			echo ' <small><em>' . esc_html( $event['data']['method'] ) . '</em></small>';
		}

		echo '</div>';
	}

	/**
	 * Renders the debug information section of the dashboard widget.
	 */
	private function render_debug_section(): void {
		echo '<details style="margin-top: 10px;">';
		echo '<summary style="cursor: pointer; font-weight: bold;">' . esc_html__( '🔧 Debug Information', 'safe-publish' ) . '</summary>';
		echo '<div style="margin-top: 10px; font-size: 12px;">';

		$env_label = ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV )
			? esc_html__( 'VIP Production', 'safe-publish' )
			: esc_html__( 'Development/Staging', 'safe-publish' );
		echo '<p><strong>' . esc_html__( 'Environment:', 'safe-publish' ) . '</strong> ' . esc_html( $env_label ) . '</p>';

		$debug_label = ( defined( 'WP_DEBUG' ) && WP_DEBUG )
			? esc_html__( 'Enabled', 'safe-publish' )
			: esc_html__( 'Disabled', 'safe-publish' );
		echo '<p><strong>' . esc_html__( 'Debug Mode:', 'safe-publish' ) . '</strong> ' . esc_html( $debug_label ) . '</p>';

		echo '<p><strong>' . esc_html__( 'Secret Source:', 'safe-publish' ) . '</strong> ';
		echo esc_html( $this->get_secret_source_label() ) . '</p>';

		$this->render_log_locations();

		echo '</div>';
		echo '</details>';
	}

	/**
	 * Renders the log locations list in the debug section.
	 */
	private function render_log_locations(): void {
		echo '<p><strong>' . esc_html__( 'Log Locations:', 'safe-publish' ) . '</strong></p>';
		echo '<ul style="margin-left: 20px; font-size: 11px;">';
		echo '<li>' . esc_html__( 'VIP Error Log:', 'safe-publish' ) . ' <code>/tmp/error_log</code></li>';
		echo '<li>' . esc_html__( 'WordPress Debug Log:', 'safe-publish' ) . ' <code>/wp-content/debug.log</code></li>';
		echo '<li>' . esc_html__( 'Database Audit Log:', 'safe-publish' ) . ' <code>' . esc_html( Audit_Log_Table::table_name() ) . '</code></li>';
		echo '<li>' . esc_html__( 'New Relic:', 'safe-publish' ) . ' Custom Events → Safe_Publish_Auth_Event</li>';
		echo '</ul>';
	}

	/**
	 * Returns a human-readable label for the source of the shared secret.
	 *
	 * @return string Label for the secret source.
	 */
	private function get_secret_source_label(): string {
		if ( defined( 'SAFE_PUBLISH_SHARED_SECRET' ) && ! empty( constant( 'SAFE_PUBLISH_SHARED_SECRET' ) ) ) {
			return __( 'Environment Variable (SAFE_PUBLISH_SHARED_SECRET)', 'safe-publish' );
		}

		if ( ! empty( getenv( 'SAFE_PUBLISH_SHARED_SECRET' ) ) ) {
			return __( 'Environment Variable (getenv)', 'safe-publish' );
		}

		return __( 'Not configured', 'safe-publish' );
	}
}
