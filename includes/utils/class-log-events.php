<?php
/**
 * Log Events constants class.
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Utils;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry of log event codes used with Logger::log_error().
 */
class Log_Events {
	// Media import events.
	const MEDIA_DOWNLOAD_FAILED = 'MEDIA_DOWNLOAD_FAILED';
	const MEDIA_SIDELOAD_FAILED = 'MEDIA_SIDELOAD_FAILED';
	const MEDIA_IMPORT_FAILED   = 'MEDIA_IMPORT_FAILED';
	const INVALID_ATTACHMENT_ID = 'INVALID_ATTACHMENT_ID';

	// Content fetch events.
	const CONTENT_FETCH_FAILED = 'CONTENT_FETCH_FAILED';

	// Auth events.
	const NO_SECRET_CONFIGURED        = 'NO_SECRET_CONFIGURED';
	const TIMESTAMP_EXPIRED           = 'TIMESTAMP_EXPIRED';
	const CONTENT_HASH_MISSING        = 'CONTENT_HASH_MISSING';
	const CONTENT_HASH_MISMATCH       = 'CONTENT_HASH_MISMATCH';
	const NO_CONNECTED_URL_CONFIGURED = 'NO_CONNECTED_URL_CONFIGURED';
	const SITE_URL_HEADER_MISSING     = 'SITE_URL_HEADER_MISSING';
	const SITE_URL_MISMATCH           = 'SITE_URL_MISMATCH';
	const SIGNATURE_INVALID           = 'SIGNATURE_INVALID';

	// Export events.
	const EXPORT_FAILED = 'EXPORT_FAILED';
}
