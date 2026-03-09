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
		$site_url        = get_option( Options::OPTION_CONNECTED_SITE_URL, '' );
		$number_of_posts = get_option( Options::OPTION_NUMBER_OF_POSTS, 10 );
		$sync_direction  = get_option( Options::OPTION_SYNC_DIRECTION, '' );

		// Basic auth credentials (development only).
		$username = get_option( Options::OPTION_USERNAME, '' );
		$password = get_option( Options::OPTION_PASSWORD, '' );

		$show_receive_fields = in_array(
			$sync_direction,
			array( Options::SYNC_DIRECTION_RECEIVE, Options::SYNC_DIRECTION_BOTH ),
			true
		);

		?>
		<div class="wrap" id="safe-publish-settings-page">
			<h1><?php esc_html_e( 'Safe Publish Settings', 'safe-publish' ); ?></h1>

			<?php settings_errors(); ?>

			<?php if ( '' === $sync_direction || '' === $site_url ) : ?>
			<div class="notice notice-info">
				<p>
					<?php esc_html_e( 'Configure a Sync Direction and Connected Site URL to get started.', 'safe-publish' ); ?>
				</p>
			</div>
			<?php endif; ?>

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
									<?php esc_html_e( 'Sync Direction', 'safe-publish' ); ?>
								</th>
								<td>
									<fieldset>
										<legend class="screen-reader-text">
											<?php esc_html_e( 'Sync Direction', 'safe-publish' ); ?>
										</legend>
										<label>
											<input
												type="radio"
												name="safe_publish_sync_direction"
												value="<?php echo esc_attr( Options::SYNC_DIRECTION_SEND ); ?>"
												<?php checked( $sync_direction, Options::SYNC_DIRECTION_SEND ); ?>
											/>
											<?php esc_html_e( 'Send', 'safe-publish' ); ?>
										</label>
										<br />
										<label>
											<input
												type="radio"
												name="safe_publish_sync_direction"
												value="<?php echo esc_attr( Options::SYNC_DIRECTION_RECEIVE ); ?>"
												<?php checked( $sync_direction, Options::SYNC_DIRECTION_RECEIVE ); ?>
											/>
											<?php esc_html_e( 'Receive', 'safe-publish' ); ?>
										</label>
										<br />
										<label>
											<input
												type="radio"
												name="safe_publish_sync_direction"
												value="<?php echo esc_attr( Options::SYNC_DIRECTION_BOTH ); ?>"
												<?php checked( $sync_direction, Options::SYNC_DIRECTION_BOTH ); ?>
											/>
											<?php esc_html_e( 'Send and Receive', 'safe-publish' ); ?>
										</label>
									</fieldset>
									<p class="description">
										<?php esc_html_e( 'Send: allow the connected site to pull content from this site. Receive: import content from the connected site. Send and Receive: both directions active.', 'safe-publish' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="safe_publish_connected_site_url">
										<?php esc_html_e( 'Connected Site URL', 'safe-publish' ); ?>
									</label>
								</th>
								<td>
									<input
										type="url"
										id="safe_publish_connected_site_url"
										name="safe_publish_connected_site_url"
										value="<?php echo esc_attr( $site_url ); ?>"
										class="regular-text"
										placeholder="<?php echo esc_attr__( 'https://example.com', 'safe-publish' ); ?>"
									/>
									<p class="description">
										<?php esc_html_e( 'URL of the WordPress site to send content to or receive content from.', 'safe-publish' ); ?>
									</p>
								</td>
							</tr>

							<?php if ( $show_receive_fields ) : ?>

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

							<?php endif; // $show_receive_fields ?>
						</table>

						<?php submit_button(); ?>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
