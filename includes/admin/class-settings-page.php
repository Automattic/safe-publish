<?php
/**
 * Settings Page class
 *
 * @package CCP
 */

namespace CCP\Admin;

use CCP\Utils\Environment;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Page Class.
 */
class Settings_Page {

	/**
	 * Renders the settings page.
	 */
	public function render(): void {
		$site_url        = get_option( 'ccp_external_site_url', '' );
		$number_of_posts = get_option( 'ccp_number_of_posts', 10 );
		$shared_secret   = get_option( 'ccp_shared_secret', '' );

		// Basic auth credentials (development only).
		$username = get_option( 'ccp_username', '' );
		$password = get_option( 'ccp_password', '' );

		?>
		<div class="wrap" id="ccp-settings-page">
			<h1><?php esc_html_e( 'Compliant Content Publisher Settings', 'ccp' ); ?></h1>

			<?php settings_errors(); ?>

			<div class="ccp-admin-container">
				<div class="ccp-settings-section">
					<h2><?php esc_html_e( 'Configuration', 'ccp' ); ?></h2>

					<form method="post" action="options.php">
						<?php
						settings_fields( 'ccp_settings' );
						do_settings_sections( 'ccp_settings' );
						?>

						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="ccp_external_site_url">
										<?php esc_html_e( 'Non-Prod Site URL', 'ccp' ); ?>
									</label>
								</th>
								<td>
									<input
										type="url"
										id="ccp_external_site_url"
										name="ccp_external_site_url"
										value="<?php echo esc_attr( $site_url ); ?>"
										class="regular-text"
										placeholder="https://example.com"
									/>
									<p class="description">
										<?php esc_html_e( 'Enter the URL of the non-prod WordPress site to fetch posts from.', 'ccp' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="ccp_number_of_posts">
										<?php esc_html_e( 'Number of Posts', 'ccp' ); ?>
									</label>
								</th>
								<td>
									<input
										type="number"
										id="ccp_number_of_posts"
										name="ccp_number_of_posts"
										value="<?php echo esc_attr( $number_of_posts ); ?>"
										min="1"
										max="100"
										class="small-text"
									/>
									<p class="description">
										<?php esc_html_e( 'Number of posts to display (1-100).', 'ccp' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row" colspan="2">
									<h3 style="margin-top: 20px; margin-bottom: 10px;">
										<?php esc_html_e( 'Authentication', 'ccp' ); ?>
									</h3>
								</th>
							</tr>

							<tr>
								<th scope="row">
									<label for="ccp_shared_secret">
										<?php esc_html_e( 'Shared Secret', 'ccp' ); ?>
									</label>
								</th>
								<td>
									<input
										type="password"
										id="ccp_shared_secret"
										name="ccp_shared_secret"
										value="<?php echo esc_attr( $shared_secret ); ?>"
										class="regular-text"
										placeholder="Enter a secure shared secret (32+ characters)"
										autocomplete="new-password"
									/>
									<p class="description">
										<?php esc_html_e( 'A shared secret key used for HMAC authentication. Must be configured on both sites. Generate a secure random string of at least 32 characters.', 'ccp' ); ?>
									</p>
								</td>
							</tr>

							<?php if ( Environment::is_development() ) : ?>
							<tr>
								<th scope="row">
									<label for="ccp_username">
										<?php esc_html_e( 'Username', 'ccp' ); ?>
									</label>
								</th>
								<td>
									<input
										type="text"
										id="ccp_username"
										name="ccp_username"
										value="<?php echo esc_attr( $username ); ?>"
										class="regular-text"
										placeholder="Username for Basic authentication"
										autocomplete="username"
									/>
									<p class="description">
										<?php esc_html_e( 'Basic authentication username (development only).', 'ccp' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="ccp_password">
										<?php esc_html_e( 'Password', 'ccp' ); ?>
									</label>
								</th>
								<td>
									<input
										type="password"
										id="ccp_password"
										name="ccp_password"
										value="<?php echo esc_attr( $password ); ?>"
										class="regular-text"
										placeholder="Password for Basic authentication"
										autocomplete="current-password"
									/>
									<p class="description">
										<?php esc_html_e( 'Basic authentication password (development only).', 'ccp' ); ?>
									</p>
								</td>
							</tr>
							<?php endif; ?>
						</table>

						<?php submit_button(); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
