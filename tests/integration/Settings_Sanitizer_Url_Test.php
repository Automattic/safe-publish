<?php
/**
 * Integration tests for connected site URL sanitization
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Admin\Settings_Sanitizer;

/**
 * Exercises the connected site URL setting with values carrying the whitespace
 * a URL parser discards. esc_url_raw() percent-encodes such a character rather
 * than dropping it, so without normalization the stored base keeps a %20 that
 * every later media resolution and identity comparison inherits.
 */
class Settings_Sanitizer_Url_Test extends Integration_Test_Case {

	/**
	 * System under test.
	 *
	 * @var Settings_Sanitizer
	 */
	private Settings_Sanitizer $sanitizer;

	/**
	 * Builds the sanitizer.
	 */
	#[\Override]
	protected function setUp(): void {
		parent::setUp();

		$this->sanitizer = new Settings_Sanitizer();
	}

	/**
	 * Verifies that whitespace around a connection URL is stripped rather than
	 * percent-encoded into the stored value.
	 *
	 * @dataProvider whitespace_url_provider
	 *
	 * @param string $input    Value as submitted.
	 * @param string $expected Expected stored value.
	 */
	public function test_sanitize_url_strips_whitespace(
		string $input,
		string $expected
	): void {
		// ACT & ASSERT: The stored value carries neither whitespace nor an
		// encoding of it.
		$this->assertSame( $expected, $this->sanitizer->sanitize_url( $input ) );
	}

	/**
	 * Data provider for sanitize_url().
	 *
	 * @return array<string, array{input: string, expected: string}>
	 */
	public static function whitespace_url_provider(): array {
		$subsite = 'https://source.example.com/blog';
		$host    = 'https://source.example.com';

		return array(
			'clean URL untouched'    => array(
				'input'    => $subsite,
				'expected' => $subsite,
			),
			'subsite trailing space' => array(
				'input'    => $subsite . ' ',
				'expected' => $subsite,
			),
			'subsite leading space'  => array(
				'input'    => ' ' . $subsite,
				'expected' => $subsite,
			),
			'subsite internal LF'    => array(
				'input'    => "https://source.example.com/bl\nog",
				'expected' => $subsite,
			),
			'host trailing space'    => array(
				'input'    => $host . ' ',
				'expected' => $host,
			),
			'whitespace only'        => array(
				'input'    => " \t\n",
				'expected' => '',
			),
		);
	}
}
