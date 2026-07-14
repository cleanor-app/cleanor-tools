<?php
/**
 * On-server image encoder (no external API). Re-encodes raw image bytes to
 * WebP / AVIF / JPEG using whatever the host provides: Imagick first, then GD.
 * Mirrors Cleanor_API::optimize_bytes() so the optimizer can use either.
 *
 * @package Cleanor_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cleanor_Local {

	/**
	 * Detect which local engine and output formats are available.
	 *
	 * @return array { imagick, gd, webp, avif, engine } (engine: 'imagick'|'gd'|'')
	 */
	public static function engines() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$out = array(
			'imagick' => false,
			'gd'      => false,
			'webp'    => false,
			'avif'    => false,
			'engine'  => '',
		);

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			$out['imagick'] = true;
			$out['engine']  = 'imagick';
			try {
				if ( \Imagick::queryFormats( 'WEBP' ) ) {
					$out['webp'] = true;
				}
				if ( \Imagick::queryFormats( 'AVIF' ) ) {
					$out['avif'] = true;
				}
			} catch ( \Exception $e ) {
				$out['imagick'] = false;
				$out['engine']  = '';
			}
		}

		if ( function_exists( 'gd_info' ) ) {
			$out['gd'] = true;
			if ( '' === $out['engine'] ) {
				$out['engine'] = 'gd';
			}
			if ( function_exists( 'imagewebp' ) ) {
				$out['webp'] = true;
			}
			if ( function_exists( 'imageavif' ) ) {
				$out['avif'] = true;
			}
		}

		$cache = $out;
		return $out;
	}

	/** @return bool Whether local encoding is available at all. */
	public function available() {
		$e = self::engines();
		return $e['imagick'] || $e['gd'];
	}

	/**
	 * @param string $format webp|avif|jpeg
	 * @return bool Whether the server can output this format locally.
	 */
	public function can( $format ) {
		$e = self::engines();
		if ( 'jpeg' === $format ) {
			return $e['imagick'] || $e['gd'];
		}
		if ( 'webp' === $format ) {
			return $e['webp'];
		}
		if ( 'avif' === $format ) {
			return $e['avif'];
		}
		return false;
	}

	/**
	 * Optimize raw image bytes locally. Same contract as Cleanor_API.
	 *
	 * @return array|WP_Error { bytes, original, optimized, saved_pct, mime }
	 */
	public function optimize_bytes( $bytes, $source_mime, $format, $quality, $width = null, $strip = false ) {
		if ( ! in_array( $format, array( 'webp', 'avif', 'jpeg' ), true ) ) {
			return new WP_Error( 'cleanor_local_format', __( 'Unsupported output format.', 'cleanor-tools' ) );
		}
		$engines = self::engines();

		if ( $engines['imagick'] ) {
			$r = $this->encode_imagick( $bytes, $format, (int) $quality, $width, $strip );
			if ( ! is_wp_error( $r ) ) {
				return $this->result( $bytes, $r, $format );
			}
			// Fall through to GD on Imagick failure (e.g. missing AVIF delegate).
		}
		if ( $engines['gd'] ) {
			$r = $this->encode_gd( $bytes, $format, (int) $quality, $width );
			if ( ! is_wp_error( $r ) ) {
				return $this->result( $bytes, $r, $format );
			}
			return $r;
		}

		return new WP_Error( 'cleanor_local_none', __( 'No local image library (Imagick or GD) is available.', 'cleanor-tools' ) );
	}

	/** Shape the return value like the API client. */
	private function result( $original_bytes, $encoded, $format ) {
		$original  = strlen( $original_bytes );
		$optimized = strlen( $encoded );
		return array(
			'bytes'     => $encoded,
			'original'  => $original,
			'optimized' => $optimized,
			'saved_pct' => $original > 0 ? (int) round( ( 1 - $optimized / $original ) * 100 ) : 0,
			'mime'      => 'image/' . $format,
		);
	}

	/** @return string|WP_Error Encoded bytes. */
	private function encode_imagick( $bytes, $format, $quality, $width, $strip ) {
		try {
			$im = new \Imagick();
			$im->readImageBlob( $bytes );
			if ( $strip ) {
				$im->stripImage();
			}
			if ( $width && $im->getImageWidth() > (int) $width ) {
				// 0 height keeps the aspect ratio.
				$im->resizeImage( (int) $width, 0, \Imagick::FILTER_LANCZOS, 1 );
			}
			$im->setImageFormat( $format );
			$im->setImageCompressionQuality( $quality );
			$blob = $im->getImageBlob();
			$im->clear();
			$im->destroy();
		} catch ( \Exception $e ) {
			return new WP_Error( 'cleanor_imagick', $e->getMessage() );
		}
		if ( '' === $blob ) {
			return new WP_Error( 'cleanor_imagick', __( 'Imagick produced no output.', 'cleanor-tools' ) );
		}
		return $blob;
	}

	/** @return string|WP_Error Encoded bytes. */
	private function encode_gd( $bytes, $format, $quality, $width ) {
		$src = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $src ) {
			return new WP_Error( 'cleanor_gd', __( 'GD could not read the image.', 'cleanor-tools' ) );
		}
		if ( $width && imagesx( $src ) > (int) $width ) {
			$scaled = imagescale( $src, (int) $width ); // -1 height keeps aspect.
			if ( $scaled ) {
				imagedestroy( $src );
				$src = $scaled;
			}
		}
		// Preserve transparency for WebP/AVIF output.
		imagealphablending( $src, false );
		imagesavealpha( $src, true );

		ob_start();
		$ok = false;
		if ( 'webp' === $format && function_exists( 'imagewebp' ) ) {
			$ok = imagewebp( $src, null, $quality );
		} elseif ( 'avif' === $format && function_exists( 'imageavif' ) ) {
			$ok = imageavif( $src, null, $quality );
		} elseif ( 'jpeg' === $format ) {
			$ok = imagejpeg( $src, null, $quality );
		}
		$blob = ob_get_clean();
		imagedestroy( $src );

		if ( ! $ok || '' === $blob ) {
			return new WP_Error( 'cleanor_gd', __( 'GD could not encode the image in this format.', 'cleanor-tools' ) );
		}
		return $blob;
	}
}
