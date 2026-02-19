<?php
/**
 * Embed Processor class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Content;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Embed Processor Class.
 *
 * Handles processing of various embed types in HTML content, including
 * iframes, oEmbed elements, and social media embeds.
 */
class Embed_Processor {

	/**
	 * Processes iframe elements for embeds.
	 *
	 * @param \DOMElement $iframe          Iframe element.
	 * @param string      $source_site_url Source site URL.
	 */
	public function process_iframe( \DOMElement $iframe, string $source_site_url ): void {
		$src = $iframe->getAttribute( 'src' );

		if ( empty( $src ) ) {
			return;
		}

		// Make iframe src absolute if it's relative.
		if ( ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
			$absolute_src = rtrim( $source_site_url, '/' ) . '/' . ltrim( $src, '/' );
			$iframe->setAttribute( 'src', $absolute_src );
		}

		// Add security attributes for iframes.
		$iframe->setAttribute( 'loading', 'lazy' );
		$iframe->setAttribute( 'referrerpolicy', 'no-referrer-when-downgrade' );

		// Set default dimensions if not present.
		if ( ! $iframe->hasAttribute( 'width' ) ) {
			$iframe->setAttribute( 'width', '100%' );
		}
		if ( ! $iframe->hasAttribute( 'height' ) ) {
			$iframe->setAttribute( 'height', '400' );
		}

		// Add responsive wrapper class for WordPress.
		$class = $iframe->getAttribute( 'class' );
		$iframe->setAttribute( 'class', trim( $class . ' wp-embedded-content' ) );
	}

	/**
	 * Processes embed elements.
	 *
	 * @param \DOMElement $embed           Embed element.
	 * @param string      $source_site_url Source site URL.
	 */
	public function process_embed( \DOMElement $embed, string $source_site_url ): void {
		$src = $embed->getAttribute( 'src' );

		if ( empty( $src ) ) {
			return;
		}

		// Make embed src absolute if it's relative.
		if ( ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
			$absolute_src = rtrim( $source_site_url, '/' ) . '/' . ltrim( $src, '/' );
			$embed->setAttribute( 'src', $absolute_src );
		}
	}

	/**
	 * Processes figure elements that may contain embeds.
	 *
	 * @param \DOMElement $figure          Figure element.
	 * @param string      $source_site_url Source site URL.
	 */
	public function process_figure_embeds( \DOMElement $figure, string $source_site_url ): void {
		// Check if figure contains WordPress embed blocks.
		$class = $figure->getAttribute( 'class' );

		if ( strpos( $class, 'wp-block-embed' ) !== false ) {
			// This is a WordPress embed block, process any iframes inside.
			$nested_iframes = $figure->getElementsByTagName( 'iframe' );
			foreach ( $nested_iframes as $iframe ) {
				$this->process_iframe( $iframe, $source_site_url );
			}

			// Add WordPress embed styling.
			$figure->setAttribute( 'class', trim( $class . ' wp-embed-responsive' ) );
		}

		// Process any oembed divs.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$xpath       = new \DOMXPath( $figure->ownerDocument );
		$oembed_divs = $xpath->query( './/div[contains(@class, "oembed")]', $figure );
		if ( $oembed_divs ) {
			foreach ( $oembed_divs as $oembed_div ) {
				$this->process_oembed_div( $oembed_div, $source_site_url );
			}
		}
	}

	/**
	 * Processes blockquote elements that may contain social media embeds.
	 *
	 * @param \DOMElement $blockquote      Blockquote element.
	 * @param string      $source_site_url Source site URL.
	 */
	public function process_blockquote_embeds(
		\DOMElement $blockquote,
		string $source_site_url
	): void {
		$class = $blockquote->getAttribute( 'class' );

		// Handle Twitter embeds.
		if ( strpos( $class, 'twitter-tweet' ) !== false ) {
			$this->process_twitter_embed( $blockquote );
		}

		// Handle Instagram embeds.
		if ( strpos( $class, 'instagram-media' ) !== false ) {
			$this->process_instagram_embed( $blockquote );
		}

		// Handle other social media embeds.
		$cite = $blockquote->getAttribute( 'cite' );
		if ( ! empty( $cite ) && ! filter_var( $cite, FILTER_VALIDATE_URL ) ) {
			$absolute_cite = rtrim( $source_site_url, '/' ) . '/' . ltrim( $cite, '/' );
			$blockquote->setAttribute( 'cite', $absolute_cite );
		}
	}

	/**
	 * Processes oEmbed div elements.
	 *
	 * @param \DOMElement $oembed_div      oEmbed div element.
	 * @param string      $source_site_url Source site URL.
	 */
	public function process_oembed_div( \DOMElement $oembed_div, string $source_site_url ): void {
		// Look for data attributes that might contain URLs.
		$data_url = $oembed_div->getAttribute( 'data-url' );
		if ( ! empty( $data_url ) && ! filter_var( $data_url, FILTER_VALIDATE_URL ) ) {
			$absolute_url = rtrim( $source_site_url, '/' ) . '/' . ltrim( $data_url, '/' );
			$oembed_div->setAttribute( 'data-url', $absolute_url );
		}
	}

	/**
	 * Processes Twitter embed blockquotes.
	 *
	 * @param \DOMElement $blockquote Twitter blockquote element.
	 */
	public function process_twitter_embed( \DOMElement $blockquote ): void {
		// Ensure Twitter script will be loaded in WordPress.
		$blockquote->setAttribute( 'data-twitter-embed', 'true' );

		// Add WordPress classes for Twitter embeds.
		$class = $blockquote->getAttribute( 'class' );
		$blockquote->setAttribute( 'class', trim( $class . ' wp-embedded-twitter' ) );
	}

	/**
	 * Processes Instagram embed blockquotes.
	 *
	 * @param \DOMElement $blockquote Instagram blockquote element.
	 */
	public function process_instagram_embed( \DOMElement $blockquote ): void {
		// Add WordPress classes for Instagram embeds.
		$class = $blockquote->getAttribute( 'class' );
		$blockquote->setAttribute( 'class', trim( $class . ' wp-embedded-instagram' ) );
	}
}
