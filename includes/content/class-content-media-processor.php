<?php
/**
 * Content Media Processor class
 *
 * @package Safe_Publish
 */

declare(strict_types=1);

namespace Safe_Publish\Content;

use Safe_Publish\Media\Media_Importer;
use DOMDocument;
use DOMElement;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content Media Processor Class.
 *
 * Handles processing of media elements within HTML content, including
 * images, videos, audio, and links. Delegates to Media_Importer for
 * actual media importing and to Embed_Processor for embed handling.
 */
class Content_Media_Processor {

	/**
	 * Media Importer instance.
	 *
	 * @var Media_Importer
	 */
	private Media_Importer $media_importer;

	/**
	 * Embed Processor instance.
	 *
	 * @var Embed_Processor
	 */
	private Embed_Processor $embed_processor;

	/**
	 * Constructs the Content_Media_Processor instance.
	 *
	 * @param Media_Importer  $media_importer  Media importer for handling media files.
	 * @param Embed_Processor $embed_processor Embed processor for handling embeds.
	 */
	public function __construct(
		Media_Importer $media_importer,
		Embed_Processor $embed_processor
	) {
		$this->media_importer  = $media_importer;
		$this->embed_processor = $embed_processor;
	}

	/**
	 * Processes and imports media from external post content.
	 *
	 * @param string $content         Post content with external media URLs.
	 * @param string $source_site_url External site URL for resolving relative URLs.
	 * @return string Processed content with imported media.
	 */
	public function process_content( string $content, string $source_site_url ): string {
		if ( empty( $content ) ) {
			return $content;
		}

		$dom = $this->create_dom_document( $content );

		$this->process_images( $dom, $source_site_url );
		$this->process_links( $dom, $source_site_url );
		$this->process_iframes( $dom, $source_site_url );
		$this->process_videos( $dom, $source_site_url );
		$this->process_audios( $dom, $source_site_url );
		$this->process_embeds( $dom, $source_site_url );
		$this->process_figures( $dom, $source_site_url );
		$this->process_blockquotes( $dom, $source_site_url );

		return $this->extract_content_from_dom( $dom );
	}

	/**
	 * Creates a DOMDocument from HTML content with proper UTF-8 encoding.
	 *
	 * @param string $content HTML content.
	 * @return DOMDocument DOM document.
	 */
	private function create_dom_document( string $content ): DOMDocument {
		$dom = new DOMDocument( '1.0', 'UTF-8' );

		// Suppress libxml errors to handle malformed HTML gracefully.
		$previous_use_errors = libxml_use_internal_errors( true );

		// Prepend meta charset to ensure proper UTF-8 handling.
		$utf8_content = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $content;

		// Load HTML - don't use LIBXML_HTML_NOIMPLIED as we need the body wrapper.
		$dom->loadHTML( $utf8_content, LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING );

		// Restore previous libxml error setting.
		libxml_use_internal_errors( $previous_use_errors );

		return $dom;
	}

	/**
	 * Extracts processed content from DOM document.
	 *
	 * @param DOMDocument $dom DOM document.
	 * @return string Processed HTML content.
	 */
	private function extract_content_from_dom( DOMDocument $dom ): string {
		$body              = $dom->getElementsByTagName( 'body' )->item( 0 );
		$processed_content = '';

		if ( $body ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			foreach ( $body->childNodes as $child ) {
				$processed_content .= $dom->saveHTML( $child );
			}
		} else {
			$processed_content = $dom->saveHTML();
		}

		// Remove the meta charset tag we added for processing.
		$processed_content = preg_replace(
			'/<meta http-equiv="Content-Type" content="text\/html; charset=utf-8"\s*\/?>/i',
			'',
			$processed_content
		);

		return $processed_content;
	}

	/**
	 * Processes image elements in the DOM.
	 *
	 * Also handles srcset attributes on <img> elements and srcset attributes on
	 * <source> elements inside <picture> tags.
	 *
	 * @param DOMDocument $dom             DOM document.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_images( DOMDocument $dom, string $source_site_url ): void {
		$images = $dom->getElementsByTagName( 'img' );

		foreach ( $images as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$new_src = $this->media_importer->import_external_media(
					$src,
					$source_site_url
				);
				if ( $new_src ) {
					$img->setAttribute(
						'src',
						Media_Importer::reapply_query_parameters( $src, $new_src )
					);
				}
			}

			$this->process_srcset( $img, $source_site_url );
		}

		$pictures = $dom->getElementsByTagName( 'picture' );
		foreach ( $pictures as $picture ) {
			$sources = $picture->getElementsByTagName( 'source' );
			foreach ( $sources as $source ) {
				$this->process_srcset( $source, $source_site_url );
			}
		}
	}

	/**
	 * Processes the srcset attribute on a DOM element.
	 *
	 * Parses each URL in the srcset descriptor list, imports it via the media
	 * importer, and writes the updated list back to the element.
	 *
	 * @param DOMElement $element         Element with a srcset attribute.
	 * @param string     $source_site_url Source site URL.
	 */
	private function process_srcset( DOMElement $element, string $source_site_url ): void {
		$srcset = $element->getAttribute( 'srcset' );

		if ( empty( $srcset ) ) {
			return;
		}

		$descriptors     = array_map( 'trim', explode( ',', $srcset ) );
		$new_descriptors = array();

		foreach ( $descriptors as $descriptor ) {
			$parts = preg_split( '/\s+/', trim( $descriptor ), 2 );
			$url   = $parts[0] ?? '';
			$size  = $parts[1] ?? '';

			if ( empty( $url ) ) {
				continue;
			}

			$new_url = $this->media_importer->import_external_media( $url, $source_site_url );
			$new_url = $new_url ? $new_url : $url;

			$new_descriptors[] = empty( $size ) ? $new_url : $new_url . ' ' . $size;
		}

		if ( ! empty( $new_descriptors ) ) {
			$element->setAttribute( 'srcset', implode( ', ', $new_descriptors ) );
		}
	}

	/**
	 * Processes link elements in the DOM to make them absolute.
	 *
	 * @param DOMDocument $dom             DOM document.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_links( DOMDocument $dom, string $source_site_url ): void {
		$links = $dom->getElementsByTagName( 'a' );
		foreach ( $links as $link ) {
			$href = $link->getAttribute( 'href' );
			if ( ! empty( $href ) && ! filter_var( $href, FILTER_VALIDATE_URL ) ) {
				// Convert relative URLs to absolute.
				$absolute_href = rtrim( $source_site_url, '/' ) . '/' . ltrim( $href, '/' );
				$link->setAttribute( 'href', $absolute_href );
			}
		}
	}

	/**
	 * Processes iframe elements in the DOM.
	 *
	 * @param DOMDocument $dom             DOM document.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_iframes( DOMDocument $dom, string $source_site_url ): void {
		$iframes = $dom->getElementsByTagName( 'iframe' );
		foreach ( $iframes as $iframe ) {
			$this->embed_processor->process_iframe( $iframe, $source_site_url );
		}
	}

	/**
	 * Processes video elements in the DOM.
	 *
	 * @param DOMDocument $dom             DOM document.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_videos( DOMDocument $dom, string $source_site_url ): void {
		$videos = $dom->getElementsByTagName( 'video' );
		foreach ( $videos as $video ) {
			$this->process_video_element( $video, $source_site_url );
		}
	}

	/**
	 * Processes audio elements in the DOM.
	 *
	 * @param DOMDocument $dom             DOM document.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_audios( DOMDocument $dom, string $source_site_url ): void {
		$audios = $dom->getElementsByTagName( 'audio' );
		foreach ( $audios as $audio ) {
			$this->process_audio_element( $audio, $source_site_url );
		}
	}

	/**
	 * Processes embed elements in the DOM.
	 *
	 * @param DOMDocument $dom             DOM document.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_embeds( DOMDocument $dom, string $source_site_url ): void {
		$embeds = $dom->getElementsByTagName( 'embed' );
		foreach ( $embeds as $embed ) {
			$this->embed_processor->process_embed( $embed, $source_site_url );
		}
	}

	/**
	 * Processes figure elements in the DOM.
	 *
	 * @param DOMDocument $dom             DOM document.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_figures( DOMDocument $dom, string $source_site_url ): void {
		$figures = $dom->getElementsByTagName( 'figure' );
		foreach ( $figures as $figure ) {
			$this->embed_processor->process_figure_embeds( $figure, $source_site_url );
		}
	}

	/**
	 * Processes blockquote elements in the DOM.
	 *
	 * @param DOMDocument $dom             DOM document.
	 * @param string      $source_site_url Source site URL.
	 */
	private function process_blockquotes( DOMDocument $dom, string $source_site_url ): void {
		$blockquotes = $dom->getElementsByTagName( 'blockquote' );
		foreach ( $blockquotes as $blockquote ) {
			$this->embed_processor->process_blockquote_embeds( $blockquote, $source_site_url );
		}
	}

	/**
	 * Processes video elements and imports video files.
	 *
	 * @param DOMElement $video           Video element.
	 * @param string     $source_site_url Source site URL.
	 */
	private function process_video_element( DOMElement $video, string $source_site_url ): void {
		// Process video source elements.
		$sources = $video->getElementsByTagName( 'source' );
		foreach ( $sources as $source ) {
			$src = $source->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$new_src = $this->media_importer->import_external_media(
					$src,
					$source_site_url
				);
				if ( $new_src ) {
					$source->setAttribute( 'src', $new_src );
				}
			}
		}

		// Process direct video src attribute.
		$video_src = $video->getAttribute( 'src' );
		if ( ! empty( $video_src ) ) {
			$new_src = $this->media_importer->import_external_media(
				$video_src,
				$source_site_url
			);
			if ( $new_src ) {
				$video->setAttribute( 'src', $new_src );
			}
		}

		// Process poster image.
		$poster = $video->getAttribute( 'poster' );
		if ( ! empty( $poster ) ) {
			$new_poster = $this->media_importer->import_external_media(
				$poster,
				$source_site_url
			);
			if ( $new_poster ) {
				$video->setAttribute( 'poster', $new_poster );
			}
		}

		// Add WordPress video classes.
		$class = $video->getAttribute( 'class' );
		$video->setAttribute( 'class', trim( $class . ' wp-video-shortcode' ) );

		// Ensure responsive behavior.
		$video->setAttribute( 'controls', 'controls' );
		$video->setAttribute( 'preload', 'metadata' );
	}

	/**
	 * Processes audio elements and imports audio files.
	 *
	 * @param DOMElement $audio           Audio element.
	 * @param string     $source_site_url Source site URL.
	 */
	private function process_audio_element( DOMElement $audio, string $source_site_url ): void {
		// Process audio source elements.
		$sources = $audio->getElementsByTagName( 'source' );
		foreach ( $sources as $source ) {
			$src = $source->getAttribute( 'src' );
			if ( ! empty( $src ) ) {
				$new_src = $this->media_importer->import_external_media(
					$src,
					$source_site_url
				);
				if ( $new_src ) {
					$source->setAttribute( 'src', $new_src );
				}
			}
		}

		// Process direct audio src attribute.
		$audio_src = $audio->getAttribute( 'src' );
		if ( ! empty( $audio_src ) ) {
			$new_src = $this->media_importer->import_external_media(
				$audio_src,
				$source_site_url
			);
			if ( $new_src ) {
				$audio->setAttribute( 'src', $new_src );
			}
		}

		// Add WordPress audio classes.
		$class = $audio->getAttribute( 'class' );
		$audio->setAttribute( 'class', trim( $class . ' wp-audio-shortcode' ) );

		// Ensure controls are visible.
		$audio->setAttribute( 'controls', 'controls' );
		$audio->setAttribute( 'preload', 'metadata' );
	}
}
