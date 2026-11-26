<?php
/**
 * Markup comparator integration tests
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Tests\Integration;

use Safe_Publish\Utils\Markup_Comparator;

/**
 * Markup Comparator Test Class.
 */
class Markup_Comparator_Test extends Integration_Test_Case {

	/**
	 * Provides markup and the forms kses rewrites it to, keeping its content.
	 *
	 * @return array<string, array{0: string, 1: string}> Original and rewritten markup.
	 */
	public static function rewritten_markup_provider(): array {
		return array(
			'attribute order and void slash' => array(
				'<img src="a.jpg" alt="b" class="c"/>',
				'<img alt="b" class="c" src="a.jpg">',
			),
			'void slash spacing'             => array(
				'<hr class="x"/>',
				'<hr class="x" />',
			),
			'character references'           => array(
				'<p>a&nbsp;b &#8220;q&#8221; &mdash;</p>',
				"<p>a\u{a0}b “q” —</p>",
			),
			'bare ampersand'                 => array(
				'Fish & Chips',
				'Fish &amp; Chips',
			),
			'case and quotes'                => array(
				"<P CLASS='x' id=y>a</P>",
				'<p class="x" id="y">a</p>',
			),
			'windows-1252 reference'         => array(
				'<p>a &#150; b</p>',
				'<p>a &#8211; b</p>',
			),
			'duplicate attribute'            => array(
				'<p class="a" class="b">x</p>',
				'<p class="a">x</p>',
			),
			'style spacing'                  => array(
				'<p style="text-align: center; color: #ff0000;">a</p>',
				'<p style="text-align: center;color: #ff0000">a</p>',
			),
			'newlines'                       => array(
				"<pre>\ncode</pre><p>a\r\nb</p>",
				"<pre>code</pre><p>a\nb</p>",
			),
			'misnested formatting'           => array(
				'<b><i>x</b>y</i><br/>',
				'<b><i>x</b>y</i><br>',
			),
			'block delimiter'                => array(
				// wp_json_encode() writes é as a Unicode escape.
				'<!-- wp:core/paragraph '
					. wp_json_encode( array( 'p' => 'café' ) )
					. ' --><p>a</p><!-- /wp:core/paragraph -->',
				'<!-- wp:paragraph {"p":"café"} -->'
					. '<p>a</p><!-- /wp:paragraph -->',
			),
		);
	}

	/**
	 * Provides markup and forms whose content differs from it.
	 *
	 * @return array<string, array{0: string, 1: string}> Original and changed markup.
	 */
	public static function changed_markup_provider(): array {
		return array(
			'removed attribute'         => array(
				'<p onclick="alert(1)">a</p>',
				'<p>a</p>',
			),
			'removed element'           => array(
				'<p>a</p><script>alert(1)</script>',
				'<p>a</p>alert(1)',
			),
			'changed attribute value'   => array(
				'<a href="https://example.org/a">x</a>',
				'<a href="https://example.org/b">x</a>',
			),
			'removed style declaration' => array(
				'<p style="color: red; behavior: url(x.htc);">a</p>',
				'<p style="color: red">a</p>',
			),
			'semicolons outside styles' => array(
				'<p title="a; b">x</p>',
				'<p title="a;b">x</p>',
			),
			'valueless style'           => array(
				'<p style>x</p>',
				'<p>x</p>',
			),
			'changed block attribute'   => array(
				'<!-- wp:button {"url":"https://example.org/?a=1&b=2"} -->'
					. '<div></div><!-- /wp:button -->',
				'<!-- wp:button {"url":"https://example.org/?a=1&amp;b=2"} -->'
					. '<div></div><!-- /wp:button -->',
			),
			'removed body tag'          => array(
				'<body class="x"><p>a</p>',
				'<p>a</p>',
			),
			'changed text'              => array(
				'<p>a < b and 1 > 0</p>',
				'<p>a <b> 0</p>',
			),
			'end tag'                   => array(
				'<p>a</p>',
				'<p>a<p>',
			),
			'comment kind'              => array(
				'<!--x-->',
				'<![CDATA[x]]>',
			),
			'instruction target'        => array(
				'<?x a?>',
				'<?y a?>',
			),
			'doctype and text'          => array(
				'<!DOCTYPE html>',
				' html',
			),
			'truncated tag'             => array(
				'<p>a</p><img src="x',
				'<p>a</p>',
			),
			'truncated tags that vary'  => array(
				'<p>a</p><img src="x',
				'<p>b</p><img src="x',
			),
		);
	}

	/**
	 * Verifies that markup kses only rewrites counts as equivalent.
	 *
	 * @dataProvider rewritten_markup_provider
	 *
	 * @param string $original  Original markup.
	 * @param string $rewritten Rewritten markup.
	 */
	public function test_rewritten_markup_is_equivalent(
		string $original,
		string $rewritten
	): void {
		// ACT: Compare the markup with its rewritten form, both ways round.
		$forward  = Markup_Comparator::equivalent( $original, $rewritten );
		$backward = Markup_Comparator::equivalent( $rewritten, $original );

		// ASSERT: The rewrite is no difference.
		$this->assertTrue( $forward );
		$this->assertTrue( $backward );
	}

	/**
	 * Verifies that markup whose content changed is not equivalent.
	 *
	 * @dataProvider changed_markup_provider
	 *
	 * @param string $original Original markup.
	 * @param string $changed  Changed markup.
	 */
	public function test_changed_markup_is_not_equivalent(
		string $original,
		string $changed
	): void {
		// ACT: Compare the markup with its changed form, both ways round.
		$forward  = Markup_Comparator::equivalent( $original, $changed );
		$backward = Markup_Comparator::equivalent( $changed, $original );

		// ASSERT: The change is a difference.
		$this->assertFalse( $forward );
		$this->assertFalse( $backward );
	}

	/**
	 * Verifies that identical markup is equivalent even when it ends inside a
	 * tag.
	 */
	public function test_identical_truncated_markup_is_equivalent(): void {
		// ACT + ASSERT: Equal bytes match without a key.
		$this->assertTrue(
			Markup_Comparator::equivalent( '<img src="x', '<img src="x' )
		);
	}
}
