<?php
/**
 * Settings Page class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Environment;
use Safe_Publish\Utils\Options;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Page Class.
 */
final class Settings_Page {

	/**
	 * Renders the settings page.
	 */
	public function render(): void {
		$site_url        = get_option( Options::OPTION_EXTERNAL_SITE_URL, '' );
		$number_of_posts = get_option( Options::OPTION_NUMBER_OF_POSTS, 10 );

		// Basic auth credentials (development only).
		$username = get_option( Options::OPTION_USERNAME, '' );
		$password = get_option( Options::OPTION_PASSWORD, '' );

		?>
		<div class="wrap" id="safe-publish-settings-page">
			<h1><?php esc_html_e( 'Safe Publish Settings', 'safe-publish' ); ?></h1>

			<?php settings_errors(); ?>

			<div class="safe-publish-admin-container">
				<div class="safe-publish-settings-section">
					<h2><?php esc_html_e( 'Configuration', 'safe-publish' ); ?></h2>

					<form method="post" action="options.php">
						<?php
						settings_fields( Options::SETTINGS_GROUP );
						do_settings_sections( Options::SETTINGS_GROUP );
						?>

						<table class="form-table">
							<tr>
								<th scope="row">
									<label for="safe_publish_external_site_url">
										<?php esc_html_e( 'Non-Prod Site URL', 'safe-publish' ); ?>
									</label>
								</th>
								<td>
									<input
										type="url"
										id="safe_publish_external_site_url"
										name="safe_publish_external_site_url"
										value="<?php echo esc_attr( $site_url ); ?>"
										class="regular-text"
										placeholder="<?php echo esc_attr__( 'https://example.com', 'safe-publish' ); ?>"
									/>
									<p class="description">
										<?php esc_html_e( 'Enter the URL of the non-prod WordPress site to fetch posts from.', 'safe-publish' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="safe_publish_number_of_posts">
										<?php esc_html_e( 'Number of Posts', 'safe-publish' ); ?>
									</label>
								</th>
								<td>
									<input
										type="number"
										id="safe_publish_number_of_posts"
										name="safe_publish_number_of_posts"
										value="<?php echo esc_attr( $number_of_posts ); ?>"
										min="1"
										max="100"
										class="small-text"
									/>
									<p class="description">
										<?php esc_html_e( 'Number of posts to display (1-100).', 'safe-publish' ); ?>
									</p>
								</td>
							</tr>

							<?php if ( Environment::is_development() ) : ?>
							<tr>
								<th scope="row">
									<label for="safe_publish_username">
										<?php esc_html_e( 'Username', 'safe-publish' ); ?>
									</label>
								</th>
								<td>
									<input
										type="text"
										id="safe_publish_username"
										name="safe_publish_username"
										value="<?php echo esc_attr( $username ); ?>"
										class="regular-text"
										placeholder="<?php echo esc_attr__( 'Username for Basic authentication', 'safe-publish' ); ?>"
										autocomplete="username"
									/>
									<p class="description">
										<?php esc_html_e( 'Basic authentication username (development only).', 'safe-publish' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="safe_publish_password">
										<?php esc_html_e( 'Password', 'safe-publish' ); ?>
									</label>
								</th>
								<td>
									<input
										type="password"
										id="safe_publish_password"
										name="safe_publish_password"
										value="<?php echo esc_attr( $password ); ?>"
										class="regular-text"
										placeholder="<?php echo esc_attr__( 'Password for Basic authentication', 'safe-publish' ); ?>"
										autocomplete="current-password"
									/>
									<p class="description">
										<?php esc_html_e( 'Basic authentication password (development only).', 'safe-publish' ); ?>
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
