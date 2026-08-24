<?php
/**
 * Source Post Type Resolver class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\API;

use Safe_Publish\Utils\Post_Type_Map;
use WP_Error;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves source post type metadata used to fetch and validate source posts.
 *
 * The source's authenticated /catalog/post-types response pairs each eligible
 * slug with its REST base and, on newer sources, the raw fields declared by
 * its REST controller. Older sources omit raw_fields and remain compatible
 * through response-shape validation.
 */
final class Source_Post_Type_Resolver {
	/**
	 * Raw post fields Safe Publish imports.
	 */
	private const RAW_FIELDS = array( 'title', 'content', 'excerpt' );

	/**
	 * Per-request source metadata, keyed by source URL and type slug.
	 *
	 * A null raw_fields value means the source predates that catalog property.
	 *
	 * @var array<string, array<string, array{
	 *     rest_base: string,
	 *     raw_fields: array<string, bool>|null
	 * }>>
	 */
	private static array $metadata = array();

	/**
	 * Resolves a post type slug to the REST base segment for its source.
	 *
	 * Built-ins use their fixed WordPress mapping. Custom types fall back to
	 * the input when the source catalog is unavailable or does not know them.
	 *
	 * @param string   $post_type       Source post type slug or REST endpoint.
	 * @param string   $source_site_url Source site URL.
	 * @param callable $make_request    fn($url, $action, $credentials): array|WP_Error.
	 * @param array    $credentials     Authentication credentials.
	 * @return string REST base segment for the wp/v2/{rest_base}/{id} URL.
	 */
	public static function resolve_rest_base(
		string $post_type,
		string $source_site_url,
		callable $make_request,
		array $credentials
	): string {
		if ( Post_Type_Map::is_builtin( $post_type ) ) {
			return Post_Type_Map::to_rest_endpoint( $post_type );
		}

		$metadata = self::get_metadata(
			$source_site_url,
			$make_request,
			$credentials
		);
		$entry    = self::find_entry( $post_type, $metadata );

		return null !== $entry ? $entry['rest_base'] : $post_type;
	}

	/**
	 * Resolves and validates a source post response's type and raw fields.
	 *
	 * Catalog metadata makes response-type and declared-field checks
	 * authoritative when available. An older or temporarily unavailable
	 * catalog falls back to the response type and shape without weakening the
	 * validation of fields that are present.
	 *
	 * @param string   $post_type       Requested source slug or REST base.
	 * @param array    $data            Decoded source post response.
	 * @param string   $source_site_url Source site URL.
	 * @param callable $make_request    fn($url, $action, $credentials): array|WP_Error.
	 * @param array    $credentials     Authentication credentials.
	 * @return array{
	 *     post_type: string,
	 *     raw_values: array{title:string,content:string,excerpt:string}
	 * }|WP_Error Resolved type and raw values, or a validation error.
	 */
	public static function resolve_post_data(
		string $post_type,
		array $data,
		string $source_site_url,
		callable $make_request,
		array $credentials
	): array|WP_Error {
		$metadata = self::get_metadata(
			$source_site_url,
			$make_request,
			$credentials
		);
		// Only valid catalog lists are cached, so cache presence distinguishes
		// an authoritative empty/unmatched catalog from an unavailable one.
		$catalog_available = array_key_exists(
			$source_site_url,
			self::$metadata
		);
		$entry             = self::find_entry( $post_type, $metadata );
		$expected_type     = null !== $entry
			? $entry['slug']
			: Post_Type_Map::to_wp_slug( $post_type );

		if ( array_key_exists( 'type', $data ) ) {
			$response_type = $data['type'];
			if ( ! is_string( $response_type ) ) {
				return self::invalid_post_type_error();
			}

			$sanitized_type = sanitize_key( $response_type );
			if ( '' === $sanitized_type || $response_type !== $sanitized_type ) {
				return self::invalid_post_type_error();
			}

			if (
				null !== $entry
				|| Post_Type_Map::is_builtin( $post_type )
			) {
				if ( $expected_type !== $response_type ) {
					return self::post_type_mismatch_error();
				}
			} elseif (
				$catalog_available
				|| Post_Type_Map::is_builtin( $response_type )
			) {
				// An unresolved custom endpoint may use the response type only
				// when the catalog is unavailable and the type is also custom.
				return self::post_type_mismatch_error();
			}

			$source_post_type = $response_type;
		} else {
			$source_post_type = $expected_type;
			$sanitized_type   = sanitize_key( $source_post_type );
			if (
				'' === $sanitized_type
				|| $source_post_type !== $sanitized_type
			) {
				return self::invalid_post_type_error();
			}
		}

		$raw_fields = $metadata[ $source_post_type ]['raw_fields'] ?? null;
		$raw_values = self::extract_raw_values( $data, $raw_fields );
		if ( is_wp_error( $raw_values ) ) {
			return $raw_values;
		}

		return array(
			'post_type'  => $source_post_type,
			'raw_values' => $raw_values,
		);
	}

	/**
	 * Extracts raw title, content, and excerpt values from a source response.
	 *
	 * @param array                    $data       Decoded source response.
	 * @param array<string, bool>|null $raw_fields Declared raw fields, or null
	 *                                             for an older source.
	 * @return array{title:string,content:string,excerpt:string}|WP_Error
	 */
	private static function extract_raw_values(
		array $data,
		?array $raw_fields
	): array|WP_Error {
		$raw_values     = array(
			'title'   => '',
			'content' => '',
			'excerpt' => '',
		);
		$missing_fields = array();

		foreach ( self::RAW_FIELDS as $field ) {
			$field_value = $data[ $field ] ?? null;
			if (
				is_array( $field_value )
				&& array_key_exists( 'raw', $field_value )
				&& is_string( $field_value['raw'] )
			) {
				$raw_values[ $field ] = $field_value['raw'];
				continue;
			}

			if (
				array_key_exists( $field, $data )
				|| ( null !== $raw_fields && isset( $raw_fields[ $field ] ) )
			) {
				$missing_fields[] = $field;
				continue;
			}
		}

		if ( array() !== $missing_fields ) {
			return new WP_Error(
				'fresh_content_raw_fields_missing',
				sprintf(
					/* translators: %s: Comma-separated list of field names. */
					__(
						'The source response is missing required raw values for supported fields: %s.',
						'safe-publish'
					),
					implode( ', ', $missing_fields )
				),
				array( 'status' => 403 )
			);
		}

		return $raw_values;
	}

	/**
	 * Returns source post type metadata, fetching it once on success.
	 *
	 * Failed or malformed responses are not memoized, allowing a later bulk
	 * item to retry. Individual malformed entries are ignored; an invalid
	 * raw_fields property conservatively requires every supported field.
	 *
	 * @param string   $source_site_url Source site URL.
	 * @param callable $make_request    fn($url, $action, $credentials): array|WP_Error.
	 * @param array    $credentials     Authentication credentials.
	 * @return array<string, array{
	 *     rest_base: string,
	 *     raw_fields: array<string, bool>|null
	 * }> Metadata keyed by post type slug.
	 */
	private static function get_metadata(
		string $source_site_url,
		callable $make_request,
		array $credentials
	): array {
		if ( array_key_exists( $source_site_url, self::$metadata ) ) {
			return self::$metadata[ $source_site_url ];
		}

		$url = trailingslashit( $source_site_url )
			. 'wp-json/safe-publish/v1/catalog/post-types';

		$response = $make_request(
			$url,
			Request_Actions::LIST_ITEMS,
			$credentials
		);
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			return array();
		}

		$metadata = array();
		foreach ( $data as $type ) {
			if ( ! is_array( $type ) ) {
				continue;
			}

			$slug      = $type['slug'] ?? null;
			$rest_base = $type['rest_base'] ?? null;
			if (
				! is_string( $slug )
				|| '' === $slug
				|| sanitize_key( $slug ) !== $slug
				|| ! is_string( $rest_base )
				|| 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $rest_base )
			) {
				continue;
			}

			$metadata[ $slug ] = array(
				'rest_base'  => $rest_base,
				'raw_fields' => self::parse_raw_fields( $type ),
			);
		}

		self::$metadata[ $source_site_url ] = $metadata;

		return $metadata;
	}

	/**
	 * Parses an optional catalog raw_fields property.
	 *
	 * @param array $type Catalog post type entry.
	 * @return array<string, bool>|null Parsed fields, or null when unavailable.
	 */
	private static function parse_raw_fields( array $type ): ?array {
		if ( ! array_key_exists( 'raw_fields', $type ) ) {
			return null;
		}

		$fields = $type['raw_fields'];
		if ( ! is_array( $fields ) || ! array_is_list( $fields ) ) {
			return array_fill_keys( self::RAW_FIELDS, true );
		}

		$raw_fields = array();
		foreach ( $fields as $field ) {
			if (
				! is_string( $field )
				|| ! in_array(
					$field,
					self::RAW_FIELDS,
					true
				)
			) {
				return array_fill_keys( self::RAW_FIELDS, true );
			}
			$raw_fields[ $field ] = true;
		}

		return $raw_fields;
	}

	/**
	 * Finds metadata by either a post type slug or REST base.
	 *
	 * @param string $post_type Source slug or REST base.
	 * @param array  $metadata  Source metadata keyed by slug.
	 * @return array{
	 *     slug: string,
	 *     rest_base: string,
	 *     raw_fields: array<string, bool>|null
	 * }|null Matching entry, or null.
	 */
	private static function find_entry(
		string $post_type,
		array $metadata
	): ?array {
		$slug = Post_Type_Map::to_wp_slug( $post_type );
		if ( isset( $metadata[ $slug ] ) ) {
			return array(
				'slug'       => $slug,
				'rest_base'  => $metadata[ $slug ]['rest_base'],
				'raw_fields' => $metadata[ $slug ]['raw_fields'],
			);
		}

		foreach ( $metadata as $candidate_slug => $entry ) {
			if ( $post_type === $entry['rest_base'] ) {
				return array(
					'slug'       => $candidate_slug,
					'rest_base'  => $entry['rest_base'],
					'raw_fields' => $entry['raw_fields'],
				);
			}
		}

		return null;
	}

	/**
	 * Returns the invalid source post type error.
	 *
	 * @return WP_Error Invalid-type error.
	 */
	private static function invalid_post_type_error(): WP_Error {
		return new WP_Error(
			'source_post_type_invalid',
			__(
				'The source response did not identify a valid post type.',
				'safe-publish'
			),
			array( 'status' => 502 )
		);
	}

	/**
	 * Returns the source post type mismatch error.
	 *
	 * @return WP_Error Type-mismatch error.
	 */
	private static function post_type_mismatch_error(): WP_Error {
		return new WP_Error(
			'fresh_content_post_type_mismatch',
			__(
				'The source response does not match the requested post type.',
				'safe-publish'
			),
			array( 'status' => 502 )
		);
	}

	/**
	 * Clears the per-request memoized metadata.
	 *
	 * For tests that exercise multiple source responses for the same URL
	 * within a single process.
	 */
	public static function reset_cache(): void {
		self::$metadata = array();
	}
}
