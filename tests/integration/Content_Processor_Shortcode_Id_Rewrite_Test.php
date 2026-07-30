<?php
/**
 * Integration tests for gallery/playlist shortcode ID rewriting through
 * Content_Processor::process_content().
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Content_Processor;
use Safe_Publish\API\HTTP_Client;
use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Content\Shortcode_ID_Rewriter;
use Safe_Publish\Media\Media_Importer;

/**
 * Drives gallery/playlist shortcode ID rewriting via the public
 * process_content() entry point with a stubbed source-ID resolver, asserting
 * that resolved IDs become destination IDs, a dangling reference is left in
 * place and warned, and a sideload failure is recorded so the import aborts.
 */
class Content_Processor_Shortcode_Id_Rewrite_Test extends Integration_Test_Case {

	private const SOURCE = 'https://source.example.com';

	/**
	 * Builds a Content_Processor whose Media_Importer resolves source IDs from a
	 * canned map, recording each lookup.
	 *
	 * @param array<int, int|false|null> $resolutions Source ID => resolver result.
	 * @return Content_Processor
	 */
	private function build_processor( array $resolutions ): Content_Processor {
		$media_importer = new class( new HTTP_Client(), $resolutions ) extends Media_Importer {

			/**
			 * Source ID => canned resolver result.
			 *
			 * @var array<int, int|false|null>
			 */
			private array $resolutions;

			/**
			 * Source IDs passed to the resolver, in call order.
			 *
			 * @var array<int, int>
			 */
			public array $calls = array();

			/**
			 * @param HTTP_Client                $http_client HTTP client.
			 * @param array<int, int|false|null> $resolutions Canned results.
			 */
			public function __construct( HTTP_Client $http_client, array $resolutions ) {
				parent::__construct( $http_client );
				$this->resolutions = $resolutions;
			}

			/**
			 * Returns the canned resolution for a source ID and records the call.
			 *
			 * @param int    $source_id        Source attachment ID.
			 * @param string $source_site_url  Source site URL (unused).
			 * @param array  $auth_credentials Authentication credentials (unused).
			 * @return int|false|null Canned result, or null when none is set.
			 */
			#[\Override]
			public function import_source_media_by_id(
				int $source_id,
				string $source_site_url,
				array $auth_credentials = array()
			): int|false|null {
				$this->calls[] = $source_id;

				return $this->resolutions[ $source_id ] ?? null;
			}
		};

		return new Content_Processor(
			$media_importer,
			new Content_Media_Processor( $media_importer ),
			new Shortcode_ID_Rewriter()
		);
	}

	/**
	 * Verifies that gallery and playlist shortcode IDs are rewritten to their
	 * resolved destination IDs, leaving other attributes intact and raising no
	 * warnings or failures.
	 */
	public function test_gallery_and_playlist_ids_rewritten_to_dest(): void {
		// ARRANGE: a resolver that maps every referenced source ID.
		$processor = $this->build_processor(
			array(
				705 => 12,
				704 => 13,
				555 => 88,
			)
		);
		$content   = "[gallery ids=\"705,704\" columns=\"2\"]\n"
			. '[playlist type="audio" ids="555"]';

		// ACT: process the content.
		$result = $processor->process_content( $content, self::SOURCE );

		// ASSERT: both shortcodes carry dest IDs; nothing warned or failed.
		$this->assertStringContainsString( '[gallery ids="12,13" columns="2"]', $result );
		$this->assertStringContainsString( '[playlist type="audio" ids="88"]', $result );
		$this->assertSame( array(), $processor->get_warnings() );
		$this->assertSame( array(), $processor->get_failed_media() );
	}

	/**
	 * Verifies that a source ID the resolver cannot map (a dangling reference)
	 * is left in place and surfaced as an unmapped-shortcode-reference warning,
	 * without failing the import.
	 */
	public function test_unresolved_id_is_left_and_warned(): void {
		// ARRANGE: the referenced ID resolves to null (dangling).
		$processor = $this->build_processor( array( 705 => null ) );
		$content   = '[gallery ids="705"]';

		// ACT: process the content.
		$result = $processor->process_content( $content, self::SOURCE );

		// ASSERT: the ID is untouched and a warning is recorded; no failure.
		$this->assertStringContainsString( '[gallery ids="705"]', $result );
		$this->assertSame( array(), $processor->get_failed_media() );
		$this->assertSame(
			array(
				array(
					'type'      => 'unmapped_shortcode_reference',
					'source_id' => 705,
				),
			),
			$processor->get_warnings()
		);
	}

	/**
	 * Verifies that a resolved reference whose media fails to sideload is
	 * recorded as a failed media file so the import aborts downstream.
	 */
	public function test_sideload_failure_recorded_as_failed_media(): void {
		// ARRANGE: the referenced ID resolves but its sideload fails.
		$processor = $this->build_processor( array( 705 => false ) );
		$content   = '[gallery ids="705"]';

		// ACT: process the content.
		$result = $processor->process_content( $content, self::SOURCE );

		// ASSERT: the ID is left in place and a media failure is recorded.
		$this->assertStringContainsString( '[gallery ids="705"]', $result );
		$this->assertSame( array(), $processor->get_warnings() );
		$this->assertNotSame( array(), $processor->get_failed_media() );
		$this->assertStringContainsString(
			'705',
			(string) $processor->get_failed_media_error_message()
		);
	}

	/**
	 * Verifies that a source ID shared by a gallery and a playlist is rewritten
	 * in both. Resolver memoization is asserted in the rewriter unit test.
	 */
	public function test_shared_id_rewritten_in_both_shortcodes(): void {
		// ARRANGE: the same ID appears in a gallery and a playlist.
		$processor = $this->build_processor( array( 7 => 70 ) );
		$content   = '[gallery ids="7"][playlist ids="7"]';

		// ACT: process the content.
		$result = $processor->process_content( $content, self::SOURCE );

		// ASSERT: the shared ID is rewritten in both shortcodes.
		$this->assertStringContainsString( '[gallery ids="70"]', $result );
		$this->assertStringContainsString( '[playlist ids="70"]', $result );
	}
}
