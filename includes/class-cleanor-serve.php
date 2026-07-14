<?php
/**
 * Front-end delivery for non-destructive ("keep") mode. Wraps <img> tags whose
 * attachment has WebP/AVIF derivatives in a <picture> element so supporting
 * browsers fetch the modern file, while the original <img> stays as the
 * fallback. Nothing about the stored file or its URL changes.
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Serve {

	/** @var Cleanor_Settings */
	private $settings;

	public function __construct( Cleanor_Settings $settings ) {
		$this->settings = $settings;
	}

	public function hooks() {
		// Front-end only. Feeds and the admin keep plain <img>.
		if ( is_admin() ) {
			return;
		}
		// Content images (post body, blocks) — WP 6.0+ passes the attachment ID.
		add_filter( 'wp_content_img_tag', array( $this, 'wrap_content_image' ), 20, 3 );
		// Template images: featured images, wp_get_attachment_image(), galleries.
		add_filter( 'wp_get_attachment_image', array( $this, 'wrap_attachment_image' ), 20, 5 );
		// Preload the featured image (modern format) to improve LCP on single views.
		add_action( 'wp_head', array( $this, 'preload_lcp' ), 2 );
	}

	/**
	 * On single posts/pages, emit a high-priority <link rel="preload"> for the
	 * featured image, pointing at its WebP/AVIF derivative so the hero starts
	 * downloading sooner. No effect unless the thumbnail was optimized in keep
	 * mode and a derivative exists. WordPress core still owns lazy-loading,
	 * async decoding and width/height.
	 */
	public function preload_lcp() {
		if ( ! $this->settings->get( 'preload_featured' ) ) {
			return;
		}
		if ( ! is_singular() || is_feed() ) {
			return;
		}
		$id = (int) get_post_thumbnail_id();
		if ( ! $id ) {
			return;
		}
		if ( 'keep' !== get_post_meta( $id, '_cleanor_delivery', true ) ) {
			return;
		}
		$derivatives = get_post_meta( $id, '_cleanor_derivatives', true );
		if ( empty( $derivatives ) || ! is_array( $derivatives ) ) {
			return;
		}
		$type = (string) get_post_meta( $id, '_cleanor_derivative_format', true );
		if ( '' === $type ) {
			return;
		}
		$ext       = ( 'image/avif' === $type ) ? 'avif' : 'webp';
		$deriv_set = array_map( 'strval', array_values( $derivatives ) );

		/** The registered image size to preload for the LCP hero. */
		$size   = apply_filters( 'cleanor_lcp_image_size', 'large', $id );
		$srcset = (string) wp_get_attachment_image_srcset( $id, $size );
		$sizes  = (string) wp_get_attachment_image_sizes( $id, $size );

		$imagesrcset = '' !== $srcset ? $this->map_srcset( $srcset, $ext, $deriv_set ) : '';

		if ( '' !== $imagesrcset ) {
			printf(
				'<link rel="preload" as="image" type="%1$s" imagesrcset="%2$s"%3$s fetchpriority="high" />' . "\n",
				esc_attr( $type ),
				esc_attr( $imagesrcset ),
				'' !== $sizes ? ' imagesizes="' . esc_attr( $sizes ) . '"' : ''
			);
			return;
		}

		// No responsive srcset: preload the single derivative URL.
		$url   = wp_get_attachment_image_url( $id, $size );
		$deriv = $url ? $this->derivative_url( $url, $ext, $deriv_set ) : '';
		if ( '' === $deriv ) {
			return;
		}
		printf(
			'<link rel="preload" as="image" type="%1$s" href="%2$s" fetchpriority="high" />' . "\n",
			esc_attr( $type ),
			esc_url( $deriv )
		);
	}

	/**
	 * @param string $filtered_image The <img> tag HTML.
	 * @param string $context        Filter context (unused).
	 * @param int    $attachment_id  Attachment ID (0 if WP could not resolve it).
	 * @return string
	 */
	public function wrap_content_image( $filtered_image, $context, $attachment_id ) {
		return $this->maybe_wrap( $filtered_image, (int) $attachment_id );
	}

	/**
	 * @param string      $html          The <img> tag HTML.
	 * @param int|WP_Post $attachment_id Attachment ID.
	 * @return string
	 */
	public function wrap_attachment_image( $html, $attachment_id, $size = null, $icon = false, $attr = array() ) {
		return $this->maybe_wrap( $html, (int) $attachment_id );
	}

	/**
	 * Wrap an <img> in <picture> if its attachment has usable derivatives.
	 *
	 * @param string $html          The <img> tag.
	 * @param int    $attachment_id Attachment ID (may be 0 → parsed from markup).
	 * @return string
	 */
	private function maybe_wrap( $html, $attachment_id ) {
		if ( is_feed() ) {
			return $html;
		}
		// Never rewrite for REST / block-editor previews; they expect a plain <img>.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $html;
		}
		if ( ! is_string( $html ) || false === strpos( $html, '<img' ) ) {
			return $html;
		}
		if ( false !== strpos( $html, '<picture' ) ) {
			return $html; // Already wrapped by us or a theme.
		}
		if ( ! $attachment_id ) {
			$attachment_id = $this->id_from_html( $html );
		}
		if ( ! $attachment_id ) {
			return $html;
		}
		if ( 'keep' !== get_post_meta( $attachment_id, '_cleanor_delivery', true ) ) {
			return $html;
		}
		$derivatives = get_post_meta( $attachment_id, '_cleanor_derivatives', true );
		if ( empty( $derivatives ) || ! is_array( $derivatives ) ) {
			return $html;
		}
		$type = (string) get_post_meta( $attachment_id, '_cleanor_derivative_format', true );
		if ( '' === $type ) {
			return $html;
		}
		$ext       = ( 'image/avif' === $type ) ? 'avif' : 'webp';
		$deriv_set = array_map( 'strval', array_values( $derivatives ) );

		$srcset = $this->build_source_srcset( $html, $ext, $deriv_set );
		if ( '' === $srcset ) {
			return $html;
		}

		$sizes = '';
		if ( preg_match( '/\ssizes=(["\'])(.*?)\1/i', $html, $m ) ) {
			$sizes = ' sizes="' . esc_attr( $m[2] ) . '"';
		}

		$source = '<source type="' . esc_attr( $type ) . '" srcset="' . esc_attr( $srcset ) . '"' . $sizes . ' />';

		/** Allow themes/plugins to opt an image out of <picture> wrapping. */
		if ( ! apply_filters( 'cleanor_wrap_picture', true, $attachment_id, $html ) ) {
			return $html;
		}

		return '<picture>' . $source . $html . '</picture>';
	}

	/**
	 * Build the <source> srcset by mapping each candidate in the img's srcset
	 * (or its src) to a derivative that actually exists on disk.
	 *
	 * @param string   $html      The <img> markup.
	 * @param string   $ext       Derivative extension (webp|avif).
	 * @param string[] $deriv_set Known derivative basenames for this attachment.
	 * @return string  A srcset string, or '' if no candidate has a derivative.
	 */
	private function build_source_srcset( $html, $ext, $deriv_set ) {
		if ( preg_match( '/\ssrcset=(["\'])(.*?)\1/i', $html, $m ) ) {
			return $this->map_srcset( $m[2], $ext, $deriv_set );
		}
		if ( preg_match( '/\ssrc=(["\'])(.*?)\1/i', $html, $m ) ) {
			return $this->derivative_url( $m[2], $ext, $deriv_set );
		}
		return '';
	}

	/**
	 * Map every candidate in a srcset attribute value to its derivative URL,
	 * keeping the width/density descriptor. Candidates without a derivative on
	 * disk are dropped.
	 *
	 * @param string   $srcset_value Raw srcset attribute value.
	 * @param string   $ext          Derivative extension (webp|avif).
	 * @param string[] $deriv_set    Known derivative basenames.
	 * @return string  A remapped srcset, or '' if nothing matched.
	 */
	private function map_srcset( $srcset_value, $ext, $deriv_set ) {
		$out = array();
		foreach ( explode( ',', $srcset_value ) as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			$parts      = preg_split( '/\s+/', $entry, 2 );
			$url        = $parts[0];
			$descriptor = isset( $parts[1] ) ? ' ' . $parts[1] : '';
			$deriv      = $this->derivative_url( $url, $ext, $deriv_set );
			if ( '' !== $deriv ) {
				$out[] = $deriv . $descriptor;
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Map an original image URL to its derivative URL, but only if the derivative
	 * was actually generated (its basename is in $deriv_set).
	 *
	 * @return string Derivative URL, or '' when none exists.
	 */
	private function derivative_url( $url, $ext, $deriv_set ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $path ) {
			return '';
		}
		$deriv_base = basename( $path ) . '.' . $ext;
		if ( ! in_array( $deriv_base, $deriv_set, true ) ) {
			return '';
		}
		// Drop any query string, then append the derivative extension.
		$clean = explode( '?', $url, 2 );
		return $clean[0] . '.' . $ext;
	}

	/** Pull an attachment ID out of a "wp-image-123" class when WP did not supply one. */
	private function id_from_html( $html ) {
		if ( preg_match( '/wp-image-(\d+)/', $html, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}
}
