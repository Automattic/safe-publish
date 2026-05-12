<?php
/**
 * Content Logger class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Admin;

use Safe_Publish\Utils\Log_Events;
use Safe_Publish\Utils\Logger;

/**
 * Logger for Safe Publish content fetch events.
 */
class Content_Logger extends Logger {

	/**
	 * Constructs the Content_Logger instance.
	 */
	public function __construct() {
		$this->channel = 'content';
	}

	/**
	 * Logs a content fetch failure.
	 *
	 * @param int    $external_post_id External post ID that was being fetched.
	 * @param string $source_site_url  Source site the fetch targeted.
	 * @param string $error            Error message from the underlying failure.
	 */
	public function content_fetch_failed(
		int $external_post_id,
		string $source_site_url,
		string $error
	): void {
		$this->log_error(
			Log_Events::CONTENT_FETCH_FAILED,
			array(
				'external_post_id' => $external_post_id,
				'source_site_url'  => $source_site_url,
				'error'            => $error,
			)
		);
	}

	/**
	 * Logs a content fetch that returned a non-array or empty response body.
	 *
	 * @param int    $external_post_id External post ID that was being fetched.
	 * @param string $source_site_url  Source site the fetch targeted.
	 */
	public function content_fetch_invalid_response(
		int $external_post_id,
		string $source_site_url
	): void {
		$this->log_error(
			Log_Events::CONTENT_FETCH_INVALID_RESPONSE,
			array(
				'external_post_id' => $external_post_id,
				'source_site_url'  => $source_site_url,
			)
		);
	}

	/**
	 * Logs a content fetch whose response lacked the raw edit-context fields.
	 *
	 * @param int    $external_post_id External post ID that was being fetched.
	 * @param string $source_site_url  Source site the fetch targeted.
	 */
	public function content_fetch_raw_unavailable(
		int $external_post_id,
		string $source_site_url
	): void {
		$this->log_error(
			Log_Events::CONTENT_FETCH_RAW_UNAVAILABLE,
			array(
				'external_post_id' => $external_post_id,
				'source_site_url'  => $source_site_url,
			)
		);
	}
}
