<?php
/**
 * WordPress function stubs for testing.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

function get_site_transient( string $key ): mixed {
	return $GLOBALS['_test_site_transients'][ $key ] ?? false;
}

function set_site_transient(
	string $key,
	mixed $value,
	int $_expiration = 0
): bool {
	$GLOBALS['_test_site_transients'][ $key ] = $value;
	return true;
}

function add_action(): void {}

/**
 * Records a filter registration.
 *
 * Unit tests do not run WordPress' hook system. The registry lets a test
 * assert what a call registered and removed, and lets the download_url()
 * stub apply the request filters the way WP_Http::request() does.
 *
 * @param string $hook_name      Hook name.
 * @param mixed  $callback       Callback to register.
 * @param int    $_priority      Unused; kept for parity with core's signature.
 * @param int    $_accepted_args Unused; kept for parity with core's signature.
 */
function add_filter(
	string $hook_name = '',
	mixed $callback = null,
	int $_priority = 10,
	int $_accepted_args = 1
): void {
	if ( '' === $hook_name || null === $callback ) {
		return;
	}

	$GLOBALS['_test_registered_filters'][ $hook_name ][] = $callback;
}

/**
 * Removes a recorded filter registration.
 *
 * @param string $hook_name Hook name.
 * @param mixed  $callback  Callback to remove.
 * @param int    $_priority Unused; kept for parity with core's signature.
 */
function remove_filter(
	string $hook_name = '',
	mixed $callback = null,
	int $_priority = 10
): void {
	$registered = get_test_filters( $hook_name );
	$index      = array_search( $callback, $registered, true );

	if ( false !== $index ) {
		unset( $registered[ $index ] );
	}

	$GLOBALS['_test_registered_filters'][ $hook_name ] = array_values( $registered );
}

/**
 * Returns the callbacks recorded for a hook.
 *
 * @param string $hook_name Hook name.
 * @return array Registered callbacks.
 */
function get_test_filters( string $hook_name ): array {
	$registered = $GLOBALS['_test_registered_filters'][ $hook_name ] ?? array();

	return is_array( $registered ) ? $registered : array();
}

/**
 * Applies the callbacks recorded for a hook, the way WordPress would.
 *
 * @param string $hook_name Hook name.
 * @param mixed  $value     Value handed to the first callback.
 * @param mixed  ...$args   Extra arguments passed to every callback.
 * @return mixed Filtered value.
 */
function apply_test_filters(
	string $hook_name,
	mixed $value,
	mixed ...$args
): mixed {
	foreach ( get_test_filters( $hook_name ) as $callback ) {
		if ( is_callable( $callback ) ) {
			$value = $callback( $value, ...$args );
		}
	}

	return $value;
}

function apply_filters( string $filter, mixed $thing, mixed ...$args ): mixed {
	$callback = $GLOBALS['_test_filters'][ $filter ] ?? null;

	return is_callable( $callback ) ? $callback( $thing, ...$args ) : $thing;
}

/**
 * Registers the callback the apply_filters stub runs for one hook. Hooks with
 * no registered callback keep returning the unfiltered value.
 *
 * @param string   $filter   Hook name.
 * @param callable $callback Receives the filtered value and the hook's args.
 */
function set_test_filter( string $filter, callable $callback ): void {
	$GLOBALS['_test_filters'][ $filter ] = $callback;
}

function reset_test_filters(): void {
	unset( $GLOBALS['_test_filters'] );
}

/**
 * Records a fired action so a test can assert on it. Logger::write() fires
 * safe_publish_event_logged here, which is how audit events are observed
 * without a database.
 *
 * @param string $hook Hook name.
 * @param mixed  ...$args Hook arguments.
 */
function do_action( string $hook, mixed ...$args ): void {
	$GLOBALS['_test_actions'][] = array(
		'hook' => $hook,
		'args' => $args,
	);
}

/**
 * Returns every action the do_action stub recorded, oldest first.
 *
 * @return array<int, array{hook: string, args: array<int, mixed>}>
 */
function get_test_actions(): array {
	return $GLOBALS['_test_actions'] ?? array();
}

function reset_test_actions(): void {
	unset( $GLOBALS['_test_actions'] );
}

function __( string $text ): string {
	return $text;
}

function esc_html_e( string $text ): void {
	echo esc_html( $text );
}

function esc_attr( mixed $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr__( string $text ): string {
	return esc_attr( $text );
}

function esc_js( string $text ): string {
	return addslashes( $text );
}

function settings_errors(): void {}

function settings_fields( string $option_group ): void {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $option_group ) . '" />';
}

function do_settings_sections(): void {}

function checked( mixed $checked, mixed $current = true ): void {
	if ( $checked === $current ) {
		echo 'checked="checked"';
	}
}

function submit_button(): void {
	echo '<input type="submit" class="button button-primary" value="Save Changes" />';
}

function wp_create_nonce(): string {
	return 'test-nonce';
}

function admin_url( string $path = '' ): string {
	return 'http://localhost/wp-admin/' . ltrim( $path, '/' );
}

function _doing_it_wrong( string $function_name, string $message, string $version ): void {
	$GLOBALS['_test_doing_it_wrong_calls'][] = array(
		'function_name' => $function_name,
		'message'       => $message,
		'version'       => $version,
	);
}

function is_wp_error( mixed $thing ): bool {
	return $thing instanceof WP_Error;
}

function wp_parse_url( string $url, int $component = -1 ): mixed {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	$parsed = parse_url( $url );
	if ( -1 === $component ) {
		return $parsed;
	}

	// Return specific component. Indexes correspond to PHP's PHP_URL_*
	// constants: SCHEME=0, HOST=1, PORT=2, USER=3, PASS=4, PATH=5,
	// QUERY=6, FRAGMENT=7.
	if ( is_array( $parsed ) ) {
		$map = array(
			0 => 'scheme',
			1 => 'host',
			2 => 'port',
			3 => 'user',
			4 => 'pass',
			5 => 'path',
			6 => 'query',
			7 => 'fragment',
		);

		return $parsed[ $map[ $component ] ?? '' ] ?? null;
	}

	return null;
}

function wp_json_encode( mixed $data ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	$string = json_encode( $data );
	return $string ? $string : '';
}

function get_option( string $option, mixed $default = false ): mixed {
	if ( isset( $GLOBALS['_test_options'][ $option ] ) ) {
		return $GLOBALS['_test_options'][ $option ];
	}

	return $default;
}

function get_role( string $_role ): ?WP_Role {
	return null;
}

function set_test_option( string $option, mixed $value ): void {
	$GLOBALS['_test_options'][ $option ] = $value;
}

function reset_test_options(): void {
	$GLOBALS['_test_options']              = array();
	$GLOBALS['_test_doing_it_wrong_calls'] = array();
}

function get_test_doing_it_wrong_calls(): array {
	return $GLOBALS['_test_doing_it_wrong_calls'] ?? array();
}

function get_posts( array $args = array() ): array {
	return $GLOBALS['_test_get_posts_result'] ?? array();
}

function set_test_get_posts_result( array $result ): void {
	$GLOBALS['_test_get_posts_result'] = $result;
}

function reset_test_get_posts_result(): void {
	unset( $GLOBALS['_test_get_posts_result'] );
}

/**
 * Sets or clears an environment variable for a test.
 *
 * @param string      $name  Environment variable name.
 * @param string|null $value Value to set, or null to unset the variable.
 */
function set_test_env( string $name, ?string $value ): void {
	$assignment = null === $value ? $name : "{$name}={$value}";
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
	putenv( $assignment );
}

function get_bloginfo( string $key ): string {
	return 'http://localhost';
}

function get_site_url(): string {
	return 'http://localhost';
}

function home_url( string $path = '' ): string {
	$base = $GLOBALS['_test_home_url'] ?? 'http://localhost';

	return (string) $base . $path;
}

/**
 * Overrides the site the home_url stub reports, so a test can move this site
 * off the default localhost host.
 *
 * @param string $url Home URL without a trailing slash.
 */
function set_test_home_url( string $url ): void {
	$GLOBALS['_test_home_url'] = $url;
}

function reset_test_home_url(): void {
	unset( $GLOBALS['_test_home_url'] );
}

function attachment_url_to_postid( string $url ): int {
	return 0; // Return 0 for tests (not found).
}

function trailingslashit( string $path ): string {
	return rtrim( $path, '/' ) . '/';
}

function wp_get_environment_type(): string {
	return 'production';
}

function wp_get_upload_dir(): array {
	return array(
		'baseurl' => 'http://localhost/wp-content/uploads',
		'error'   => false,
	);
}

function wp_get_audio_extensions(): array {
	return array( 'mp3', 'ogg', 'flac', 'm4a', 'wav' );
}

function wp_get_video_extensions(): array {
	return array( 'mp4', 'm4v', 'webm', 'ogv', 'flv' );
}

function untrailingslashit( string $path ): string {
	return rtrim( $path, '/\\' );
}

function wp_remote_get( string $url, array $args = array() ): array|WP_Error {
	$GLOBALS['_test_http_last_url']  = $url;
	$GLOBALS['_test_http_last_args'] = $args;

	$default = array( 'response' => array( 'code' => 200 ) );
	return $GLOBALS['_test_http_response'] ?? $default;
}

function wp_safe_remote_get( string $url, array $args = array() ): array|WP_Error {
	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Test stub delegating to the existing wp_remote_get stub.
	return wp_remote_get( $url, $args );
}

function wp_remote_retrieve_response_code( array|WP_Error $response ): int {
	if ( $response instanceof WP_Error ) {
		return 0;
	}

	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array|WP_Error $response ): string {
	if ( $response instanceof WP_Error ) {
		return '';
	}

	return (string) ( $response['body'] ?? '' );
}

function set_test_http_response( array|WP_Error $response ): void {
	$GLOBALS['_test_http_response'] = $response;
}

function reset_test_http_response(): void {
	unset(
		$GLOBALS['_test_http_response'],
		$GLOBALS['_test_http_last_url'],
		$GLOBALS['_test_http_last_args']
	);
}

/**
 * Reads a header from a stubbed response, case-insensitively.
 *
 * @param array|WP_Error $response Stubbed response.
 * @param string         $header   Header name.
 * @return string Header value, or '' when the response carries none.
 */
function wp_remote_retrieve_header(
	array|WP_Error $response,
	string $header
): string {
	if ( $response instanceof WP_Error ) {
		return '';
	}

	$headers = $response['headers'] ?? array();
	if ( ! is_array( $headers ) ) {
		return '';
	}

	return (string) ( $headers[ strtolower( $header ) ] ?? '' );
}

/**
 * Temporary file path the download_url() stub reports on success.
 *
 * @return string Temporary file path.
 */
function test_download_temp_file(): string {
	return '/tmp/safe-publish-download.tmp';
}

/**
 * URL standing in for another request in flight while a download runs.
 *
 * The download_url() stub runs both HTTP hooks for it as well, so a test can
 * prove a filter only acts on the URL it was registered for.
 *
 * @return string Unrelated request URL.
 */
function test_unrelated_request_url(): string {
	return 'https://unrelated.example.org/other.jpg';
}

/**
 * Minimal port of WordPress core's download_url() over the filter registry.
 *
 * Applies http_request_args and http_response the way WP_Http::request()
 * does, and turns a non-200 response into the http_404 WP_Error core returns,
 * so redirect handling can be exercised without a transport.
 *
 * @param string $url     File URL.
 * @param int    $timeout Request timeout in seconds.
 * @return string|WP_Error Temporary file path, or error.
 */
function download_url( string $url, int $timeout = 300 ): string|WP_Error {
	$defaults = array(
		'timeout'            => $timeout,
		'redirection'        => 5,
		'reject_unsafe_urls' => true,
		'stream'             => true,
		'filename'           => test_download_temp_file(),
	);

	$args = apply_test_filters( 'http_request_args', $defaults, $url );

	$unrelated_url  = test_unrelated_request_url();
	$unrelated_args = apply_test_filters(
		'http_request_args',
		$defaults,
		$unrelated_url
	);

	$args           = is_array( $args ) ? $args : $defaults;
	$unrelated_args = is_array( $unrelated_args ) ? $unrelated_args : $defaults;

	$GLOBALS['_test_download_url_calls'][] = array(
		'url'              => $url,
		'args'             => $args,
		'unrelated_args'   => $unrelated_args,
		'request_filters'  => count( get_test_filters( 'http_request_args' ) ),
		'response_filters' => count( get_test_filters( 'http_response' ) ),
	);

	$unrelated_response = $GLOBALS['_test_unrelated_response'] ?? null;
	if ( is_array( $unrelated_response ) ) {
		apply_test_filters(
			'http_response',
			$unrelated_response,
			$unrelated_args,
			$unrelated_url
		);
	}

	$queued   = $GLOBALS['_test_download_responses'] ?? array();
	$response = array_shift( $queued );

	$GLOBALS['_test_download_responses'] = $queued;

	if ( null === $response ) {
		$response = array( 'response' => array( 'code' => 200 ) );
	}

	if ( $response instanceof WP_Error ) {
		return $response;
	}

	$filtered = apply_test_filters( 'http_response', $response, $args, $url );
	$response = is_array( $filtered ) ? $filtered : $response;
	$code     = wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		return new WP_Error(
			'http_404',
			'Not Found',
			array( 'code' => $code )
		);
	}

	return (string) $args['filename'];
}

/**
 * Queues the responses the download_url() stub returns, in order.
 *
 * @param array $responses Stubbed responses.
 */
function set_test_download_responses( array $responses ): void {
	$GLOBALS['_test_download_responses'] = $responses;
}

/**
 * Stubs the response another request in flight receives during a download.
 *
 * @param array $response Stubbed response for the unrelated request.
 */
function set_test_unrelated_response( array $response ): void {
	$GLOBALS['_test_unrelated_response'] = $response;
}

/**
 * Returns what each download_url() stub call was asked to download.
 *
 * @return array Recorded calls.
 */
function get_test_download_url_calls(): array {
	$calls = $GLOBALS['_test_download_url_calls'] ?? array();

	return is_array( $calls ) ? $calls : array();
}

function reset_test_downloads(): void {
	unset(
		$GLOBALS['_test_download_responses'],
		$GLOBALS['_test_download_url_calls'],
		$GLOBALS['_test_unrelated_response']
	);
}

function add_query_arg( array $args, string $url ): string {
	$separator = ( strpos( $url, '?' ) === false ) ? '?' : '&';
	return $url . $separator . http_build_query( $args );
}

function esc_url_raw( string $url ): string {
	return $url;
}

function esc_url( string $url ): string {
	return $url;
}

function esc_html( string $text ): string {
	return $text;
}

function absint( mixed $value ): int {
	return abs( (int) $value );
}

/**
 * Faithful port of WordPress core's get_shortcode_regex() for the given
 * explicit tag names. Kept byte-identical to core so the rewriter's unit tests
 * exercise the same matcher that runs against real WordPress at import time.
 *
 * @param array<int, string> $tagnames Shortcode tags to match.
 * @return string Regular expression body (unanchored, no delimiters).
 */
function get_shortcode_regex( array $tagnames ): string {
	$tagregexp = implode( '|', array_map( 'preg_quote', $tagnames ) );

	// phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound
	return '\\['                          // Opening bracket.
		. '(\\[?)'                        // 1: Optional second opening bracket for escaping.
		. "($tagregexp)"                  // 2: Shortcode name.
		. '(?![\\w-])'                    // Not followed by word character or hyphen.
		. '('                             // 3: Inside the opening shortcode tag.
		.     '[^\\]\\/]*'                // Not a closing bracket or forward slash.
		.     '(?:'
		.         '\\/(?!\\])'            // A forward slash not followed by a closing bracket.
		.         '[^\\]\\/]*'            // Not a closing bracket or forward slash.
		.     ')*?'
		. ')'
		. '(?:'
		.     '(\\/)'                     // 4: Self closing tag...
		.     '\\]'                       // ...and closing bracket.
		. '|'
		.     '\\]'                       // Closing bracket.
		.     '(?:'
		.         '('                     // 5: Optionally, anything between the opening and closing tags.
		.             '[^\\[]*+'          // Not an opening bracket.
		.             '(?:'
		.                 '\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing tag.
		.                 '[^\\[]*+'      // Not an opening bracket.
		.             ')*+'
		.         ')'
		.         '\\[\\/\\2\\]'          // Closing shortcode tag.
		.     ')?'
		. ')'
		. '(\\]?)';                       // 6: Optional second closing bracket for escaping.
	// phpcs:enable Squiz.Strings.ConcatenationSpacing.PaddingFound
}

class WP_Error {
	public function __construct(
		private string $code = '',
		private string $message = '',
		private mixed $data = null
	) {}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_data(): mixed {
		return $this->data;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

/**
 * Strips tags and whitespace for plain-text unit fixtures.
 *
 * @param mixed $value Raw fixture value.
 * @return string Plain text.
 */
function sanitize_text_field( mixed $value ): string {
	// phpcs:ignore WordPressVIPMinimum.Functions.StripTags.StripTagsOneParameter -- unit fixtures contain no scripts or styles.
	return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
}

/**
 * Sanitizes a unit fixture key.
 *
 * @param string $key Raw key.
 * @return string Sanitized key.
 */
function sanitize_key( string $key ): string {
	return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

/**
 * Sanitizes a unit fixture slug.
 *
 * @param string $title Raw slug.
 * @return string Sanitized slug.
 */
function sanitize_title( string $title ): string {
	return sanitize_key( str_replace( ' ', '-', $title ) );
}
