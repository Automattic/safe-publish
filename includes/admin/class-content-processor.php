<?php
/**
 * Content Processor class
 *
 * @package Safe_Publish
 */

namespace Safe_Publish\Admin;

use Safe_Publish\Content\Content_Media_Processor;
use Safe_Publish\Media\Media_Importer;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles content transformation, media import, oEmbed processing, and URL replacement.
 */
class Content_Processor {

	/**
	 * Media Importer instance.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $media_importer;

	/**
	 * Content Media Processor instance.
	 *
	 * @var Content_Media_Processor
	 */
	private Content_Media_Processor $content_media_processor;

	/**
	 * Stores temporarily disabled WordPress filters.
	 *
	 * @var array
	 */
	private array $disabled_filters = array();

	/**
	 * Constructs the Content_Processor instance.
	 *
	 * @param Media_Importer          $media_importer          Media importer instance.
	 * @param Content_Media_Processor $content_media_processor Content media processor instance.
	 */
	public function __construct(
		Media_Importer $media_importer,
		Content_Media_Processor $content_media_processor
	) {
		$this->media_importer          = $media_importer;
		$this->content_media_processor = $content_media_processor;
	}

	/**
	 * Processes post content by importing media, handling oEmbeds, and replacing URLs.
	 *
	 * Detects whether content uses Gutenberg blocks and applies the appropriate
	 * processing strategy. Replaces external URLs in the content after processing.
	 *
	 * @param string $content  Post content to process.
	 * @param string $site_url Source site URL.
	 * @return string Processed content.
	 */
	public function process_content( string $content, string $site_url ): string {
		if ( $this->is_gutenberg_content( $content ) ) {
			$processed_content = $this->process_gutenberg_blocks( $content, $site_url );
		} else {
			$processed_content = $this->content_media_processor->process_content( $content, $site_url );
			$processed_content = $this->process_oembed_content( $processed_content ) ?? $processed_content;
		}

		return $this->replace_external_urls( $processed_content, $site_url );
	}

	/**
	 * Checks if content contains Gutenberg blocks.
	 *
	 * @param string $content Post content.
	 * @return bool True if content contains blocks.
	 */
	public function is_gutenberg_content( string $content ): bool {
		return false !== strpos( $content, '<!-- wp:' );
	}

	/**
	 * Processes content to handle oEmbed URLs.
	 *
	 * @param string $content Post content.
	 * @return ?string Processed content with oEmbeds resolved.
	 */
	public function process_oembed_content( string $content ): ?string {
		if ( empty( $content ) ) {
			return $content;
		}

		// Get WordPress oEmbed handler.
		global $wp_embed;

		// Process auto-embeds (URLs on their own line).
		$content = $wp_embed->autoembed( $content );

		// Process shortcode embeds.
		$content = $wp_embed->run_shortcode( $content );

		// Handle common embed patterns that might not be caught.
		$content = $this->process_manual_embeds( $content );

		return $content;
	}

	/**
	 * Processes Gutenberg blocks and imports media.
	 *
	 * @param string $content  Post content with blocks.
	 * @param string $site_url Source site URL.
	 * @return string Processed content.
	 */
	public function process_gutenberg_blocks( string $content, string $site_url ): string {
		if ( empty( $content ) ) {
			return $content;
		}

		// Store original content for whitespace preservation.
		$original_content = $content;

		// Parse blocks using WordPress parse_blocks function.
		$blocks = parse_blocks( $content );

		if ( empty( $blocks ) ) {
			return $content;
		}

		// Check if any processing is actually needed to avoid unnecessary serialization.
		$needs_processing = $this->content_needs_media_processing( $content, $site_url );

		if ( ! $needs_processing ) {
			// If no external media/links found, return original content to preserve formatting.
			return $original_content;
		}

		// Process each block.
		$processed_blocks = array_map(
			function ( $block ) use ( $site_url ) {
				return $this->process_single_block( $block, $site_url );
			},
			$blocks
		);

		// Serialize blocks back to content.
		$serialized_content = serialize_blocks( $processed_blocks );

		return $serialized_content;
	}

	/**
	 * Replaces external site URLs with current site URLs in content.
	 *
	 * @param string $content           Content to process.
	 * @param string $external_site_url External site URL to replace.
	 * @return string Content with URLs replaced.
	 */
	public function replace_external_urls( string $content, string $external_site_url ): string {
		if ( empty( $content ) || empty( $external_site_url ) ) {
			return $content;
		}

		$current_site_url = get_site_url();
		$external_host    = wp_parse_url( $external_site_url, PHP_URL_HOST );
		$current_host     = wp_parse_url( $current_site_url, PHP_URL_HOST );

		// Skip if URLs are the same.
		if ( $external_host === $current_host ) {
			return $content;
		}

		// Skip DOM processing if the external domain doesn't appear in the content.
		if ( false === strpos( $content, $external_host ) ) {
			return $content;
		}

		// Parse HTML content.
		$dom = new \DOMDocument();
		$dom->loadHTML(
			$content,
			\LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR | \LIBXML_NOWARNING
		);

		// Process anchor tags (links).
		$links = $dom->getElementsByTagName( 'a' );
		foreach ( $links as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( ! empty( $href ) ) {
				$updated_href = $this->replace_url_domain( $href, $external_site_url, $current_site_url );
				if ( $updated_href !== $href ) {
					$link->setAttribute( 'href', $updated_href );
				}
			}
		}

		// Process other elements that might have URLs (like images, forms, etc.).
		$elements_with_urls = array(
			'img'    => 'src',
			'form'   => 'action',
			'iframe' => 'src',
			'embed'  => 'src',
			'object' => 'data',
		);

		foreach ( $elements_with_urls as $tag => $attribute ) {
			$elements = $dom->getElementsByTagName( $tag );
			foreach ( $elements as $element ) {
				$url = $element->getAttribute( $attribute );
				if ( ! empty( $url ) ) {
					$updated_url = $this->replace_url_domain( $url, $external_site_url, $current_site_url );
					if ( $updated_url !== $url ) {
						$element->setAttribute( $attribute, $updated_url );
					}
				}
			}
		}

		// Return processed content.
		$processed_content = '';
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $dom->childNodes as $child ) {
			if ( \XML_DOCUMENT_TYPE_NODE === $child->nodeType ) {
				continue;
			}

			$processed_content .= $dom->saveHTML( $child );
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return $processed_content ? $processed_content : $content;
	}

	/**
	 * Temporarily disables content formatting filters during import.
	 */
	public function disable_content_filters(): void {
		global $wp_filter;

		// Store filters that might affect content formatting.
		$filters_to_disable = array(
			'the_content',
			'content_save_pre',
			'wp_insert_post_data',
		);

		foreach ( $filters_to_disable as $filter_name ) {
			if ( isset( $wp_filter[ $filter_name ] ) ) {
				$this->disabled_filters[ $filter_name ] = $wp_filter[ $filter_name ];
				unset( $wp_filter[ $filter_name ] );
			}
		}

		// Specifically remove common formatting filters.
		remove_filter( 'the_content', 'wpautop' );
		remove_filter( 'the_content', 'wptexturize' );
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );
		remove_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
	}

	/**
	 * Restores content formatting filters after import.
	 */
	public function restore_content_filters(): void {
		global $wp_filter;

		// Restore previously disabled filters.
		foreach ( $this->disabled_filters as $filter_name => $filter_callbacks ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$wp_filter[ $filter_name ] = $filter_callbacks;
		}

		// Clear stored filters.
		$this->disabled_filters = array();

		// Re-add common formatting filters with default priorities.
		add_filter( 'the_content', 'wpautop' );
		add_filter( 'the_content', 'wptexturize' );
		add_filter( 'content_save_pre', 'wp_filter_post_kses' );
		add_filter( 'content_filtered_save_pre', 'wp_filter_post_kses' );
	}

	/**
	 * Replaces domain in a URL if it matches the external site.
	 *
	 * @param string $url               URL to process.
	 * @param string $external_site_url External site URL.
	 * @param string $current_site_url  Current site URL.
	 * @return string Processed URL.
	 */
	private function replace_url_domain(
		string $url,
		string $external_site_url,
		string $current_site_url
	): string {
		// Skip empty URLs, anchors, or non-HTTP URLs.
		if (
			empty( $url ) ||
			strpos( $url, '#' ) === 0 ||
			strpos( $url, 'mailto:' ) === 0 ||
			strpos( $url, 'tel:' ) === 0
		) {
			return $url;
		}

		$external_host = wp_parse_url( $external_site_url, PHP_URL_HOST );
		$url_host      = wp_parse_url( $url, PHP_URL_HOST );

		// If URL is relative, make it absolute with external site first.
		if ( empty( $url_host ) ) {
			if ( strpos( $url, '/' ) === 0 ) {
				// Absolute path.
				$url = rtrim( $external_site_url, '/' ) . $url;
			} else {
				// Relative path - skip for now as it's complex to resolve.
				return $url;
			}
			$url_host = $external_host;
		}

		// Replace domain if it matches the external site.
		if ( $url_host === $external_host ) {
			$url_parts     = wp_parse_url( $url );
			$current_parts = wp_parse_url( $current_site_url );

			$new_url = $current_parts['scheme'] . '://' . $current_parts['host'];

			if ( isset( $current_parts['port'] ) ) {
				$new_url .= ':' . $current_parts['port'];
			}

			if ( isset( $url_parts['path'] ) ) {
				$new_url .= $url_parts['path'];
			}

			if ( isset( $url_parts['query'] ) ) {
				$new_url .= '?' . $url_parts['query'];
			}

			if ( isset( $url_parts['fragment'] ) ) {
				$new_url .= '#' . $url_parts['fragment'];
			}

			return $new_url;
		}

		return $url;
	}

	/**
	 * Processes a single Gutenberg block.
	 *
	 * @param array  $block    Block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	private function process_single_block( array $block, string $site_url ): array {
		if ( empty( $block['blockName'] ) ) {
			return $block;
		}

		switch ( $block['blockName'] ) {
			case 'core/image':
				$block = $this->process_image_block( $block, $site_url );
				break;

			case 'core/gallery':
				$block = $this->process_gallery_block( $block, $site_url );
				break;

			case 'core/video':
				$block = $this->process_video_block( $block, $site_url );
				break;

			case 'core/audio':
				$block = $this->process_audio_block( $block, $site_url );
				break;

			case 'core/embed':
			case 'core-embed/youtube':
			case 'core-embed/vimeo':
			case 'core-embed/twitter':
			case 'core-embed/instagram':
				$block = $this->process_embed_block( $block, $site_url );
				break;

			case 'core/html':
				$block = $this->process_html_block( $block, $site_url );
				break;

			case 'core/paragraph':
			case 'core/heading':
			case 'core/list':
			case 'core/quote':
				$block = $this->process_text_block( $block, $site_url );
				break;

			default:
				// Process innerHTML for any blocks that might contain media or links.
				if ( ! empty( $block['innerHTML'] ) ) {
					$block['innerHTML'] = $this->content_media_processor->process_content(
						$block['innerHTML'],
						$site_url
					);
				}
				break;
		}

		return $block;
	}

	/**
	 * Processes image block to import media and update block attributes.
	 *
	 * @param array  $block    Image block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	private function process_image_block( array $block, string $site_url ): array {
		$original_url = '';

		// First try to get URL from block attributes.
		if ( ! empty( $block['attrs']['url'] ) ) {
			$original_url = $block['attrs']['url'];
		} elseif ( ! empty( $block['innerHTML'] ) ) {
			// Extract URL from innerHTML img src attribute.
			$original_url = $this->extract_img_src_from_html( $block['innerHTML'] );
		}

		if ( empty( $original_url ) ) {
			return $block;
		}

		// Method 1: Try to import and get attachment ID directly.
		$attachment_id = $this->media_importer->import_external_media_as_attachment( $original_url, $site_url );

		if ( $attachment_id && is_numeric( $attachment_id ) ) {
			// Get the new URL from the attachment ID.
			$new_url = wp_get_attachment_url( $attachment_id );
		} else {
			// Method 2: Fallback - use original method if the first didn't work.
			$new_url = $this->media_importer->import_external_media( $original_url, $site_url );

			if ( $new_url ) {
				// Try to get attachment ID from the URL.
				$attachment_id = $this->media_importer->get_attachment_id_from_url( $new_url );
			}
		}

		if ( ! $new_url || ! $attachment_id ) {
			return $block;
		}

		// Initialize attrs if it doesn't exist.
		if ( ! isset( $block['attrs'] ) ) {
			$block['attrs'] = array();
		}

		// Update block attributes with local URL and attachment ID.
		$block['attrs']['url'] = $new_url;
		$block['attrs']['id']  = $attachment_id;

		// Also update other common image attributes that might reference the URL.
		if ( isset( $block['attrs']['src'] ) ) {
			$block['attrs']['src'] = $new_url;
		}

		$url_with_parameters = Media_Importer::reapply_query_parameters( $original_url, $new_url );

		// Update innerHTML with the appropriate URL for correct rendering.
		if ( ! empty( $block['innerHTML'] ) ) {
			$updated_html       = $this->update_img_src_in_html( $block['innerHTML'], $original_url, $url_with_parameters );
			$updated_html       = $this->update_wp_image_class( $updated_html, $attachment_id );
			$block['innerHTML'] = $updated_html;
		}

		// Update innerContent array if it exists (used by serialize_blocks).
		if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $index => $content ) {
				if ( is_string( $content ) ) {
					$updated_content                 = $this->update_img_src_in_html( $content, $original_url, $url_with_parameters );
					$updated_content                 = $this->update_wp_image_class( $updated_content, $attachment_id );
					$block['innerContent'][ $index ] = $updated_content;
				}
			}
		}

		return $block;
	}

	/**
	 * Processes gallery block to import media from all contained images.
	 *
	 * @param array  $block    Gallery block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	private function process_gallery_block( array $block, string $site_url ): array {
		// Handle traditional gallery format with images in attributes.
		if ( ! empty( $block['attrs']['images'] ) && is_array( $block['attrs']['images'] ) ) {
			foreach ( $block['attrs']['images'] as $index => $image ) {
				if ( empty( $image['url'] ) ) {
					continue;
				}

				$original_url  = $image['url'];
				$attachment_id = $this->media_importer->import_external_media_as_attachment( $original_url, $site_url );

				if ( ! $attachment_id ) {
					continue;
				}

				$new_url = wp_get_attachment_url( $attachment_id );

				if ( ! $new_url ) {
					continue;
				}

				// Update block attributes.
				$block['attrs']['images'][ $index ]['url'] = $new_url;
				$block['attrs']['images'][ $index ]['id']  = $attachment_id;

				$url_with_parameters = Media_Importer::reapply_query_parameters( $original_url, $new_url );

				// Update innerHTML with the appropriate URL for correct rendering.
				if ( ! empty( $block['innerHTML'] ) ) {
					$updated_html       = $this->update_img_src_in_html( $block['innerHTML'], $original_url, $url_with_parameters );
					$updated_html       = $this->update_wp_image_class( $updated_html, $attachment_id );
					$block['innerHTML'] = $updated_html;
				}

				// Update innerContent array if it exists (used by serialize_blocks).
				if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
					foreach ( $block['innerContent'] as $content_index => $content ) {
						if ( is_string( $content ) ) {
							$updated_content                         = $this->update_img_src_in_html( $content, $original_url, $url_with_parameters );
							$updated_content                         = $this->update_wp_image_class( $updated_content, $attachment_id );
							$block['innerContent'][ $content_index ] = $updated_content;
						}
					}
				}
			}
		}

		// Handle block-based gallery format with innerBlocks containing image blocks.
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $index => $inner_block ) {
				if ( ! empty( $inner_block['blockName'] ) && 'core/image' === $inner_block['blockName'] ) {
					$block['innerBlocks'][ $index ] = $this->process_image_block( $inner_block, $site_url );
				} else {
					$block['innerBlocks'][ $index ] = $this->process_single_block( $inner_block, $site_url );
				}
			}

			// Update innerContent array to reflect any changes in innerBlocks.
			if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
				$new_inner_content = array();
				$inner_block_index = 0;

				foreach ( $block['innerContent'] as $content ) {
					if ( is_null( $content ) ) {
						// null values represent positions where inner blocks should be inserted.
						if ( isset( $block['innerBlocks'][ $inner_block_index ] ) ) {
							$new_inner_content[] = null; // Keep the null placeholder.
							++$inner_block_index;
						}
					} else {
						// String content remains as is.
						$new_inner_content[] = $content;
					}
				}

				$block['innerContent'] = $new_inner_content;
			}
		}

		return $block;
	}

	/**
	 * Processes video block to import media.
	 *
	 * @param array  $block    Video block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	private function process_video_block( array $block, string $site_url ): array {
		if ( empty( $block['attrs']['src'] ) ) {
			return $block;
		}

		$original_url  = $block['attrs']['src'];
		$attachment_id = $this->media_importer->import_external_media_as_attachment( $original_url, $site_url );

		if ( ! $attachment_id ) {
			return $block;
		}

		$new_url = wp_get_attachment_url( $attachment_id );

		if ( $new_url ) {
			$block['attrs']['src'] = $new_url;
			$block['attrs']['id']  = $attachment_id;
		}

		return $block;
	}

	/**
	 * Processes audio block to import media.
	 *
	 * @param array  $block    Audio block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	private function process_audio_block( array $block, string $site_url ): array {
		if ( empty( $block['attrs']['src'] ) ) {
			return $block;
		}

		$original_url  = $block['attrs']['src'];
		$attachment_id = $this->media_importer->import_external_media_as_attachment( $original_url, $site_url );

		if ( ! $attachment_id ) {
			return $block;
		}

		$new_url = wp_get_attachment_url( $attachment_id );

		if ( $new_url ) {
			$block['attrs']['src'] = $new_url;
			$block['attrs']['id']  = $attachment_id;
		}

		return $block;
	}

	/**
	 * Processes embed block content.
	 *
	 * @param array  $block    Embed block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	private function process_embed_block( array $block, string $site_url ): array {
		// Most embed blocks work with URLs that don't need media import,
		// but we can process the innerHTML for any embedded media.
		if ( ! empty( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $this->content_media_processor->process_content( $block['innerHTML'], $site_url );
		}

		return $block;
	}

	/**
	 * Processes HTML block to import media.
	 *
	 * @param array  $block    HTML block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	private function process_html_block( array $block, string $site_url ): array {
		if ( ! empty( $block['attrs']['content'] ) ) {
			$block['attrs']['content'] = $this->content_media_processor->process_content(
				$block['attrs']['content'],
				$site_url
			);
		}

		if ( ! empty( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $this->content_media_processor->process_content( $block['innerHTML'], $site_url );
		}

		return $block;
	}

	/**
	 * Processes text blocks (paragraph, heading, list, quote) to import media.
	 *
	 * @param array  $block    Text block data.
	 * @param string $site_url Source site URL.
	 * @return array Processed block.
	 */
	private function process_text_block( array $block, string $site_url ): array {
		if ( ! empty( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $this->content_media_processor->process_content( $block['innerHTML'], $site_url );
		}

		return $block;
	}

	/**
	 * Processes manual embed patterns for YouTube, Vimeo, Twitter, and Instagram URLs.
	 *
	 * @param string $content Post content.
	 * @return ?string Processed content with embed shortcodes.
	 */
	private function process_manual_embeds( string $content ): ?string {
		// YouTube embed patterns.
		$content = preg_replace_callback(
			'/(?:https?:\/\/)?(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/',
			function ( $matches ) {
				return "[embed]https://www.youtube.com/watch?v={$matches[1]}[/embed]";
			},
			$content
		);

		// Vimeo embed patterns.
		$content = preg_replace_callback(
			'/(?:https?:\/\/)?(?:www\.)?vimeo\.com\/([0-9]+)/',
			function ( $matches ) {
				return "[embed]https://vimeo.com/{$matches[1]}[/embed]";
			},
			$content
		);

		// Twitter embed patterns.
		$content = preg_replace_callback(
			'/(?:https?:\/\/)?(?:www\.)?twitter\.com\/\w+\/status\/([0-9]+)/',
			function ( $matches ) {
				return "[embed]https://twitter.com/user/status/{$matches[1]}[/embed]";
			},
			$content
		);

		// Instagram embed patterns.
		$content = preg_replace_callback(
			'/(?:https?:\/\/)?(?:www\.)?instagram\.com\/p\/([a-zA-Z0-9_-]+)/',
			function ( $matches ) {
				return "[embed]https://www.instagram.com/p/{$matches[1]}[/embed]";
			},
			$content
		);

		return $content;
	}

	/**
	 * Extracts image src attribute from HTML content.
	 *
	 * @param string $html HTML content.
	 * @return string Extracted src URL or empty string if not found.
	 */
	private function extract_img_src_from_html( string $html ): string {
		if ( empty( $html ) ) {
			return '';
		}

		// Use DOMDocument for safe HTML parsing.
		$dom = new \DOMDocument();

		// Suppress errors for malformed HTML and use UTF-8 encoding.
		$previous_use_errors = libxml_use_internal_errors( true );
		$dom->loadHTML( $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_use_internal_errors( $previous_use_errors );

		$images = $dom->getElementsByTagName( 'img' );

		if ( $images->length > 0 ) {
			$img = $images->item( 0 ); // Get the first image.
			if ( $img instanceof \DOMElement ) {
				$src = $img->getAttribute( 'src' );

				if ( ! empty( $src ) ) {
					return trim( $src );
				}
			}
		}

		// Fallback to regex if DOMDocument fails.
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			return trim( $matches[1] );
		}

		return '';
	}

	/**
	 * Updates image src attribute in HTML content.
	 *
	 * @param string $html    HTML content.
	 * @param string $old_url Old image URL to replace.
	 * @param string $new_url New image URL.
	 * @return ?string Updated HTML content.
	 */
	private function update_img_src_in_html( string $html, string $old_url, string $new_url ): ?string {
		if ( empty( $html ) || empty( $old_url ) || empty( $new_url ) ) {
			return $html;
		}

		// Use a more targeted regex approach to avoid XML declaration issues.
		// This is safer for block innerHTML since we're only replacing the src attribute.
		$pattern     = '/(<img[^>]+src=["\'])' . preg_quote( $old_url, '/' ) . '(["\'][^>]*>)/i';
		$replacement = '${1}' . $new_url . '${2}';

		$updated_html = preg_replace( $pattern, $replacement, $html );

		// If regex replacement worked, apply minimal normalization.
		if ( null !== $updated_html && $updated_html !== $html ) {
			// Only normalize Gutenberg-specific spacing - preserve other whitespace.
			// Gutenberg requires NO space before /> for self-closing tags.
			$updated_html = preg_replace( '/\s+\/>/', '/>', $updated_html );

			// Only compress excessive consecutive spaces within attributes, but preserve line breaks.
			$updated_html = preg_replace( '/( [a-zA-Z-]+=")(\s{2,})/', '${1} ', $updated_html );

			return $updated_html;
		}

		// Fallback to simple string replacement with minimal normalization.
		$fallback_html = str_replace( $old_url, $new_url, $html );

		// Apply the same minimal normalization to the fallback.
		$fallback_html = preg_replace( '/\s+\/>/', '/>', $fallback_html );
		$fallback_html = preg_replace( '/( [a-zA-Z-]+=")(\s{2,})/', '${1} ', $fallback_html );

		return $fallback_html;
	}

	/**
	 * Updates wp-image class with new attachment ID.
	 *
	 * @param string $html              HTML content.
	 * @param int    $new_attachment_id New attachment ID.
	 * @return string Updated HTML content.
	 */
	private function update_wp_image_class( string $html, int $new_attachment_id ): string {
		if ( empty( $html ) || empty( $new_attachment_id ) ) {
			return $html;
		}

		// Pattern to match wp-image-{number} class.
		$pattern     = '/wp-image-\d+/';
		$replacement = 'wp-image-' . $new_attachment_id;

		$updated_html = preg_replace( $pattern, $replacement, $html );

		// If no existing wp-image class found, add it to the img tag.
		if ( $updated_html === $html && strpos( $html, '<img' ) !== false ) {
			// Add wp-image class to img tag that doesn't have one.
			$pattern      = '/(<img[^>]+class=["\'])([^"\']*?)(["\'][^>]*>)/i';
			$replacement  = '${1}${2} wp-image-' . $new_attachment_id . '${3}';
			$updated_html = preg_replace( $pattern, $replacement, $html );

			// If img tag has no class attribute at all, add one.
			if ( $updated_html === $html ) {
				$pattern      = '/(<img[^>]+)(\s*\/?>)/i';
				$replacement  = '${1} class="wp-image-' . $new_attachment_id . '"${2}';
				$updated_html = preg_replace( $pattern, $replacement, $html );
			}
		}

		return $updated_html ? $updated_html : $html;
	}

	/**
	 * Checks if content needs media processing to avoid unnecessary serialization.
	 *
	 * @param string $content  Content to check.
	 * @param string $site_url Source site URL.
	 * @return bool True if content needs processing.
	 */
	private function content_needs_media_processing( string $content, string $site_url ): bool {
		if ( empty( $content ) || empty( $site_url ) ) {
			return false;
		}

		$external_domain = wp_parse_url( $site_url, PHP_URL_HOST );

		// Check for external media URLs.
		if ( strpos( $content, $external_domain ) !== false ) {
			return true;
		}

		// Check for common media file extensions.
		$media_extensions = array( '.jpg', '.jpeg', '.png', '.gif', '.webp', '.svg', '.mp4', '.mov', '.avi', '.mp3', '.wav' );
		foreach ( $media_extensions as $ext ) {
			if ( strpos( $content, $ext ) !== false ) {
				return true;
			}
		}

		return false;
	}
}
