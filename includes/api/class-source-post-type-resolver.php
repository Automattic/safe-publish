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
 * Built-in types resolve from the static Post_Type_Map with no network call.
 * Custom post types whose rest_base differs from their slug (e.g. a `movie`
 * type exposed at wp/v2/movies) are resolved from the source's authoritative
 * /catalog/post-types response, which pairs every catalog-eligible slug with
 * its rest_base.
 *
 * The fetched slug => rest_base map is memoized per source URL for the
 * duration of the request, so a bulk import resolves it once rather than once
 * per post.
 */
final class Source_Post_Type_Resolver {

	/**
	 * Per-request slug => rest_base maps, keyed by source site URL.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static array $maps = array();

	/**
	 * Per-request raw fields, keyed by source URL and type slug.
	 *
	 * @var array<string, array<string, array<string, mixed>|WP_Error>>
	 */
	private static array $raw_fields = array();

	/**
	 * Resolves a post type slug to the REST base segment for its source.
	 *
	 * Falls back to passing the slug through unchanged when the source can't
	 * be consulted or doesn't know the type — this keeps built-ins and custom
	 * types whose rest_base equals their slug working without a reachable
	 * source.
	 *
	 * @param string   $post_type       Source post type slug (or endpoint).
	 * @param string   $source_site_url Source site URL.
	 * @param callable $make_request    fn($url, $action, $credentials, $args = array()): array|WP_Error.
	 * @param array    $credentials     Authentication credentials.
	 * @return string REST base segment for the wp/v2/{rest_base}/{id} URL.
	 */
	public static function resolve_rest_base(
		string $post_type,
		string $source_site_url,
		callable $make_request,
		array $credentials
	): string {
		// Built-in types have a core-fixed rest_base; resolve them without a
		// network call.
		if ( Post_Type_Map::is_builtin( $post_type ) ) {
			return Post_Type_Map::to_rest_endpoint( $post_type );
		}

		$map = self::get_map( $source_site_url, $make_request, $credentials );

		return $map[ $post_type ]
			?? Post_Type_Map::to_rest_endpoint( $post_type );
	}

	/**
	 * Resolves a source post type slug or REST base to its canonical slug.
	 *
	 * @param string   $post_type       Source post type slug or REST base.
	 * @param string   $source_site_url Source site URL.
	 * @param callable $make_request    fn($url, $action, $credentials, $args = array()): array|WP_Error.
	 * @param array    $credentials     Authentication credentials.
	 * @return string Canonical post type slug, or the input when unresolved.
	 */
	public static function resolve_slug(
		string $post_type,
		string $source_site_url,
		callable $make_request,
		array $credentials
	): string {
		if ( Post_Type_Map::is_builtin( $post_type ) ) {
			return Post_Type_Map::to_wp_slug( $post_type );
		}

		$map = self::get_map( $source_site_url, $make_request, $credentials );
		if ( isset( $map[ $post_type ] ) ) {
			return $post_type;
		}

		$slug = array_search( $post_type, $map, true );

		return false !== $slug ? $slug : $post_type;
	}

	/**
	 * Returns the raw fields exposed by a source post type's REST route.
	 *
	 * WordPress' OPTIONS schema reflects the fields its REST controller exposes.
	 * Results are memoized so a bulk import fetches it once per source type.
	 *
	 * @param string   $post_type       Source post type slug.
	 * @param string   $source_site_url Source site URL.
	 * @param callable $make_request    fn($url, $action, $credentials, $args = array()): array|WP_Error.
	 * @param array    $credentials     Authentication credentials.
	 * @return array<string, bool>|WP_Error Declared raw fields, or an error.
	 */
	public static function resolve_raw_fields(
		string $post_type,
		string $source_site_url,
		callable $make_request,
		array $credentials
	): array|WP_Error {
		$sanitized_post_type = sanitize_key( $post_type );

		if ( '' === $sanitized_post_type || $post_type !== $sanitized_post_type ) {
			return new WP_Error(
				'source_post_type_invalid',
				__( 'The source response did not identify its post type.', 'safe-publish' )
			);
		}

		$post_type = $sanitized_post_type;

		if ( isset( self::$raw_fields[ $source_site_url ][ $post_type ] ) ) {
			return self::$raw_fields[ $source_site_url ][ $post_type ];
		}

		$rest_base = self::resolve_rest_base(
			$post_type,
			$source_site_url,
			$make_request,
			$credentials
		);
		$url       = trailingslashit( $source_site_url )
			. 'wp-json/wp/v2/' . $rest_base;
		$response  = $make_request(
			$url,
			Request_Actions::LIST_ITEMS,
			$credentials,
			array( 'method' => 'OPTIONS' )
		);

		if ( is_wp_error( $response ) ) {
			self::$raw_fields[ $source_site_url ][ $post_type ] = $response;
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if (
			! ( $data instanceof \stdClass )
			|| ! ( ( $data->schema ?? null ) instanceof \stdClass )
			|| ! ( ( $data->schema->properties ?? null ) instanceof \stdClass )
		) {
			$error = new WP_Error(
				'source_post_type_schema_invalid',
				__(
					'The source site returned an invalid post route schema.',
					'safe-publish'
				)
			);
			self::$raw_fields[ $source_site_url ][ $post_type ] = $error;
			return $error;
		}

		$raw_fields = array();
		foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
			$field_schema = $data->schema->properties->{$field} ?? null;
			if (
				$field_schema instanceof \stdClass
				&& ( $field_schema->properties->raw ?? null ) instanceof \stdClass
			) {
				$raw_fields[ $field ] = true;
			}
		}

		self::$raw_fields[ $source_site_url ][ $post_type ] = $raw_fields;

		return $raw_fields;
	}

	/**
	 * Returns the source's slug => rest_base map, fetching it once per request.
	 *
	 * A failed or unparseable response yields an empty map that is not
	 * memoized, so a later call can retry once the source is reachable.
	 *
	 * @param string   $source_site_url Source site URL.
	 * @param callable $make_request    fn($url, $action, $credentials, $args = array()): array|WP_Error.
	 * @param array    $credentials     Authentication credentials.
	 * @return array<string, string> Map of post type slug to rest_base.
	 */
	private static function get_map(
		string $source_site_url,
		callable $make_request,
		array $credentials
	): array {
		if ( isset( self::$maps[ $source_site_url ] ) ) {
			return self::$maps[ $source_site_url ];
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

		// The catalog endpoint returns a JSON list. A non-list body — a REST
		// error envelope ({code, ...}) or anything else — is treated as a
		// transient failure: Return empty without memoizing so a later call
		// can retry.
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			return array();
		}

		$map = array();
		foreach ( $data as $type ) {
			// rest_base is interpolated into the wp/v2 request path, and HMAC
			// vouches for the source's identity but not its honesty — reject
			// anything outside the REST-path charset so a compromised source
			// can't shape the request URL. The /D anchor keeps a trailing
			// newline (which $ would otherwise allow) out of the value.
			if (
				is_array( $type )
				&& isset( $type['slug'], $type['rest_base'] )
				&& is_string( $type['slug'] )
				&& is_string( $type['rest_base'] )
				&& 1 === preg_match( '/^[A-Za-z0-9_-]+$/D', $type['rest_base'] )
			) {
				$map[ $type['slug'] ] = $type['rest_base'];
			}
		}

		self::$maps[ $source_site_url ] = $map;

		return $map;
	}

	/**
	 * Clears the per-request memoized maps.
	 *
	 * For tests that exercise multiple source responses for the same URL
	 * within a single process.
	 */
	public static function reset_cache(): void {
		self::$maps       = array();
		self::$raw_fields = array();
	}
}
