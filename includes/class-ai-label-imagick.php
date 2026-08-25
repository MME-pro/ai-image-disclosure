<?php
/**
 * Imagick processing backend.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preferred backend — better resampling and colour handling than GD.
 */
class AI_Label_Imagick_Processor extends AI_Label_Image_Processor {

	/**
	 * Loaded image.
	 *
	 * @var Imagick|null
	 */
	private $image = null;

	/**
	 * Backend name.
	 *
	 * @return string
	 */
	public function name() {
		return 'Imagick';
	}

	/**
	 * Availability check.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( ! class_exists( 'Imagick', false ) || ! class_exists( 'ImagickPixel', false ) ) {
			return false;
		}
		$required = array( 'compositeImage', 'getImageGeometry', 'writeImage', 'resizeImage' );
		foreach ( $required as $method ) {
			if ( ! method_exists( 'Imagick', $method ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Adds the modern formats this ImageMagick build can actually read and write.
	 *
	 * @return array
	 */
	public static function supported_mimes() {
		$mimes = parent::supported_mimes();

		if ( self::is_available() ) {
			try {
				$probe = new Imagick();
				foreach ( array(
					'AVIF' => 'image/avif',
					'HEIC' => 'image/heic',
				) as $format => $mime ) {
					if ( $probe->queryFormats( $format ) && ! in_array( $mime, $mimes, true ) ) {
						$mimes[] = $mime;
					}
				}
				$probe->clear();
			} catch ( Exception $e ) {
				unset( $e );
			}
		}

		return $mimes;
	}

	/**
	 * Opens an image.
	 *
	 * @param string $path Absolute path.
	 * @return true|WP_Error
	 */
	public function load( $path ) {
		$mime = $this->validate_source( $path );
		if ( is_wp_error( $mime ) ) {
			return $mime;
		}

		try {
			$image = new Imagick();

			// Read through a blob rather than readImage(): ImageMagick memory-maps the
			// file it reads, and on Windows that open mapping makes it impossible to
			// replace the file afterwards ("Access is denied" on rename).
			$blob = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file; WP_Filesystem is unavailable in cron/CLI jobs.
			if ( false === $blob ) {
				return new WP_Error(
					'ai_label_unreadable',
					sprintf(
						/* translators: %s: file name. */
						__( 'The file %s cannot be read.', 'ai-image-disclosure' ),
						wp_basename( $path )
					)
				);
			}
			$image->readImageBlob( $blob, $path );
			unset( $blob );

			if ( $image->getNumberImages() > 1 ) {
				$image->clear();
				return new WP_Error(
					'ai_label_animated',
					__( 'Animated images cannot be labelled without discarding their animation.', 'ai-image-disclosure' )
				);
			}

			$image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
			$this->image = $image;
			$this->path  = $path;
			$this->mime  = $mime;
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_label_imagick_load', $e->getMessage() );
		}
	}

	/**
	 * Dimensions of the loaded image.
	 *
	 * @return array
	 */
	public function get_dimensions() {
		if ( ! $this->image ) {
			return array(
				'width'  => 0,
				'height' => 0,
			);
		}
		try {
			$geo = $this->image->getImageGeometry();
			return array(
				'width'  => (int) $geo['width'],
				'height' => (int) $geo['height'],
			);
		} catch ( Exception $e ) {
			return array(
				'width'  => 0,
				'height' => 0,
			);
		}
	}

	/**
	 * Mean luminance of a region.
	 *
	 * @param int $x Left.
	 * @param int $y Top.
	 * @param int $w Width.
	 * @param int $h Height.
	 * @return float|WP_Error
	 */
	public function sample_region_brightness( $x, $y, $w, $h ) {
		if ( ! $this->image ) {
			return new WP_Error( 'ai_label_no_image', __( 'No image loaded.', 'ai-image-disclosure' ) );
		}
		try {
			$region = clone $this->image;
			$region->cropImage( max( 1, (int) $w ), max( 1, (int) $h ), (int) $x, (int) $y );

			// Reset the page geometry the crop left behind (the equivalent of +repage).
			// Without this, flattening below composites the crop back onto a canvas the
			// size of the whole image, and the background colour dominates the mean.
			$region->setImagePage( 0, 0, 0, 0 );

			// Flatten so that transparent pixels do not read as black.
			if ( method_exists( $region, 'setImageBackgroundColor' ) ) {
				$region->setImageBackgroundColor( new ImagickPixel( 'white' ) );
			}
			if ( method_exists( $region, 'mergeImageLayers' ) ) {
				$region = $region->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
			}

			$region->transformImageColorspace( Imagick::COLORSPACE_GRAY );
			$stats = $region->getImageChannelMean( Imagick::CHANNEL_GRAY );
			$range = $region->getQuantumRange();
			$region->clear();

			$max = isset( $range['quantumRangeLong'] ) ? (float) $range['quantumRangeLong'] : 65535.0;
			if ( $max <= 0 ) {
				$max = 65535.0;
			}
			return ( (float) $stats['mean'] / $max ) * 255.0;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_label_imagick_sample', $e->getMessage() );
		}
	}

	/**
	 * Composites the badge.
	 *
	 * @param string $badge_path Badge PNG path.
	 * @param int    $x          Left.
	 * @param int    $y          Top.
	 * @param int    $w          Width.
	 * @param int    $h          Height.
	 * @return true|WP_Error
	 */
	public function composite( $badge_path, $x, $y, $w, $h ) {
		if ( ! $this->image ) {
			return new WP_Error( 'ai_label_no_image', __( 'No image loaded.', 'ai-image-disclosure' ) );
		}
		try {
			$badge = new Imagick();
			$badge->readImage( $badge_path );
			$badge->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
			$badge->resizeImage( max( 1, (int) $w ), max( 1, (int) $h ), Imagick::FILTER_LANCZOS, 1 );

			$this->image->compositeImage( $badge, Imagick::COMPOSITE_OVER, (int) $x, (int) $y );
			$badge->clear();
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_label_imagick_composite', $e->getMessage() );
		}
	}

	/**
	 * Scales the loaded image.
	 *
	 * @param int $w Width.
	 * @param int $h Height.
	 * @return true|WP_Error
	 */
	public function resize( $w, $h ) {
		if ( ! $this->image ) {
			return new WP_Error( 'ai_label_no_image', __( 'No image loaded.', 'ai-image-disclosure' ) );
		}
		try {
			$this->image->resizeImage( max( 1, (int) $w ), max( 1, (int) $h ), Imagick::FILTER_LANCZOS, 1 );
			return true;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_label_imagick_resize', $e->getMessage() );
		}
	}

	/**
	 * Writes the image out.
	 *
	 * @param string      $path    Destination.
	 * @param string|null $mime    Target MIME.
	 * @param int         $quality Quality.
	 * @return true|WP_Error
	 */
	public function save( $path, $mime = null, $quality = 90 ) {
		if ( ! $this->image ) {
			return new WP_Error( 'ai_label_no_image', __( 'No image loaded.', 'ai-image-disclosure' ) );
		}
		$mime = $mime ? $mime : $this->mime;

		try {
			$formats = array(
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
				'image/avif' => 'avif',
				'image/heic' => 'heic',
			);
			if ( isset( $formats[ $mime ] ) ) {
				$this->image->setImageFormat( $formats[ $mime ] );
			}
			if ( 'image/jpeg' === $mime ) {
				$this->image->setImageCompression( Imagick::COMPRESSION_JPEG );
				$this->image->setImageBackgroundColor( new ImagickPixel( 'white' ) );
				$this->image = $this->image->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
			}
			$this->image->setImageCompressionQuality( max( 1, min( 100, (int) $quality ) ) );

			// Write to a temp file and move into place, so a failed write never truncates
			// the live image (PRD §6.2 — fail loud, never leave a half-written file).
			$temp = $path . '.ai-label-tmp';
			if ( ! $this->image->writeImage( $temp ) ) {
				return new WP_Error( 'ai_label_write_failed', __( 'Imagick could not write the labelled image.', 'ai-image-disclosure' ) );
			}

			return $this->move_into_place( $temp, $path );
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_label_imagick_save', $e->getMessage() );
		}
	}

	/**
	 * Frees the Imagick handle.
	 */
	public function destroy() {
		if ( $this->image ) {
			try {
				$this->image->clear();
			} catch ( Exception $e ) {
				unset( $e );
			}
			$this->image = null;
		}
	}
}
