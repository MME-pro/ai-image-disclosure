<?php
/**
 * GD processing backend (fallback).
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Used when Imagick is unavailable.
 */
class AI_Label_GD_Processor extends AI_Label_Image_Processor {

	/**
	 * GD image resource/object.
	 *
	 * @var resource|GdImage|null
	 */
	private $image = null;

	/**
	 * Backend name.
	 *
	 * @return string
	 */
	public function name() {
		return 'GD';
	}

	/**
	 * Availability check.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagecopyresampled' );
	}

	/**
	 * Adds AVIF when this PHP build can both read and write it (PHP 8.1+ with libavif).
	 *
	 * @return array
	 */
	public static function supported_mimes() {
		$mimes = parent::supported_mimes();

		if ( function_exists( 'imagecreatefromavif' ) && function_exists( 'imageavif' ) && ! in_array( 'image/avif', $mimes, true ) ) {
			$mimes[] = 'image/avif';
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

		$image = $this->create_from_file( $path, $mime );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		imagealphablending( $image, true );
		imagesavealpha( $image, true );

		$this->image = $image;
		$this->path  = $path;
		$this->mime  = $mime;
		return true;
	}

	/**
	 * Reads a file into a GD image.
	 *
	 * @param string $path Absolute path.
	 * @param string $mime MIME type.
	 * @return resource|GdImage|WP_Error
	 */
	private function create_from_file( $path, $mime ) {
		$image = false;

		switch ( $mime ) {
			case 'image/jpeg':
				if ( function_exists( 'imagecreatefromjpeg' ) ) {
					$image = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD emits warnings for damaged files; the WP_Error below reports it.
				}
				break;
			case 'image/png':
				if ( function_exists( 'imagecreatefrompng' ) ) {
					$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				}
				break;
			case 'image/gif':
				if ( function_exists( 'imagecreatefromgif' ) ) {
					$image = @imagecreatefromgif( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				}
				break;
			case 'image/webp':
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$image = @imagecreatefromwebp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				}
				break;
			case 'image/avif':
				if ( function_exists( 'imagecreatefromavif' ) ) {
					$image = @imagecreatefromavif( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
				}
				break;
		}

		if ( ! $image ) {
			return new WP_Error(
				'ai_label_gd_load',
				sprintf(
					/* translators: %s: MIME type. */
					__( 'GD could not open this image (%s). The file may be damaged or the format unsupported by this PHP build.', 'mme-ai-image-disclosure' ),
					$mime
				)
			);
		}

		// A palette image cannot be composited onto cleanly — promote it to true colour.
		if ( function_exists( 'imageistruecolor' ) && ! imageistruecolor( $image ) && function_exists( 'imagepalettetotruecolor' ) ) {
			imagepalettetotruecolor( $image );
		}

		return $image;
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
		return array(
			'width'  => (int) imagesx( $this->image ),
			'height' => (int) imagesy( $this->image ),
		);
	}

	/**
	 * Mean luminance of a region, sampled on a grid to stay fast on large images.
	 *
	 * @param int $x Left.
	 * @param int $y Top.
	 * @param int $w Width.
	 * @param int $h Height.
	 * @return float|WP_Error
	 */
	public function sample_region_brightness( $x, $y, $w, $h ) {
		if ( ! $this->image ) {
			return new WP_Error( 'ai_label_no_image', __( 'No image loaded.', 'mme-ai-image-disclosure' ) );
		}

		$img_w = imagesx( $this->image );
		$img_h = imagesy( $this->image );

		$x = max( 0, min( $img_w - 1, (int) $x ) );
		$y = max( 0, min( $img_h - 1, (int) $y ) );
		$w = max( 1, min( $img_w - $x, (int) $w ) );
		$h = max( 1, min( $img_h - $y, (int) $h ) );

		$steps  = 40; // At most 40x40 = 1,600 samples.
		$step_x = max( 1, (int) floor( $w / $steps ) );
		$step_y = max( 1, (int) floor( $h / $steps ) );

		$total   = 0.0;
		$samples = 0;

		for ( $sy = $y; $sy < $y + $h; $sy += $step_y ) {
			for ( $sx = $x; $sx < $x + $w; $sx += $step_x ) {
				$rgba  = imagecolorat( $this->image, $sx, $sy );
				$alpha = ( $rgba >> 24 ) & 0x7F;
				$red   = ( $rgba >> 16 ) & 0xFF;
				$green = ( $rgba >> 8 ) & 0xFF;
				$blue  = $rgba & 0xFF;

				// Rec. 601 luma, composited over white so transparency reads as light.
				$luma    = ( 0.299 * $red ) + ( 0.587 * $green ) + ( 0.114 * $blue );
				$opacity = 1 - ( $alpha / 127 );
				$total  += ( $luma * $opacity ) + ( 255 * ( 1 - $opacity ) );
				++$samples;
			}
		}

		if ( ! $samples ) {
			return 128.0;
		}
		return $total / $samples;
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
			return new WP_Error( 'ai_label_no_image', __( 'No image loaded.', 'mme-ai-image-disclosure' ) );
		}

		$badge = $this->create_from_file( $badge_path, 'image/png' );
		if ( is_wp_error( $badge ) ) {
			return $badge;
		}

		$badge_w = imagesx( $badge );
		$badge_h = imagesy( $badge );

		imagealphablending( $badge, true );
		imagesavealpha( $badge, true );
		imagealphablending( $this->image, true );

		$ok = imagecopyresampled(
			$this->image,
			$badge,
			(int) $x,
			(int) $y,
			0,
			0,
			max( 1, (int) $w ),
			max( 1, (int) $h ),
			$badge_w,
			$badge_h
		);

		imagedestroy( $badge );

		if ( ! $ok ) {
			return new WP_Error( 'ai_label_gd_composite', __( 'GD could not composite the badge onto the image.', 'mme-ai-image-disclosure' ) );
		}
		return true;
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
			return new WP_Error( 'ai_label_no_image', __( 'No image loaded.', 'mme-ai-image-disclosure' ) );
		}

		$w      = max( 1, (int) $w );
		$h      = max( 1, (int) $h );
		$target = imagecreatetruecolor( $w, $h );
		imagealphablending( $target, false );
		imagesavealpha( $target, true );
		$transparent = imagecolorallocatealpha( $target, 0, 0, 0, 127 );
		imagefilledrectangle( $target, 0, 0, $w, $h, $transparent );
		imagealphablending( $target, true );

		$ok = imagecopyresampled( $target, $this->image, 0, 0, 0, 0, $w, $h, imagesx( $this->image ), imagesy( $this->image ) );
		if ( ! $ok ) {
			imagedestroy( $target );
			return new WP_Error( 'ai_label_gd_resize', __( 'GD could not resize the image.', 'mme-ai-image-disclosure' ) );
		}

		imagedestroy( $this->image );
		$this->image = $target;
		return true;
	}

	/**
	 * Writes the image out via a temp file, then moves it into place.
	 *
	 * @param string      $path    Destination.
	 * @param string|null $mime    Target MIME.
	 * @param int         $quality Quality.
	 * @return true|WP_Error
	 */
	public function save( $path, $mime = null, $quality = 90 ) {
		if ( ! $this->image ) {
			return new WP_Error( 'ai_label_no_image', __( 'No image loaded.', 'mme-ai-image-disclosure' ) );
		}

		$mime    = $mime ? $mime : $this->mime;
		$quality = max( 1, min( 100, (int) $quality ) );
		$temp    = $path . '.ai-label-tmp';
		$written = false;

		switch ( $mime ) {
			case 'image/jpeg':
				$flat = $this->flatten_for_jpeg();
				$written = function_exists( 'imagejpeg' ) ? imagejpeg( $flat, $temp, $quality ) : false;
				if ( $flat !== $this->image ) {
					imagedestroy( $flat );
				}
				break;
			case 'image/png':
				imagesavealpha( $this->image, true );
				$written = function_exists( 'imagepng' ) ? imagepng( $this->image, $temp ) : false;
				break;
			case 'image/gif':
				$written = function_exists( 'imagegif' ) ? imagegif( $this->image, $temp ) : false;
				break;
			case 'image/webp':
				$written = function_exists( 'imagewebp' ) ? imagewebp( $this->image, $temp, $quality ) : false;
				break;
			case 'image/avif':
				$written = function_exists( 'imageavif' ) ? imageavif( $this->image, $temp, $quality ) : false;
				break;
		}

		if ( ! $written || ! file_exists( $temp ) ) {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}
			return new WP_Error(
				'ai_label_write_failed',
				sprintf(
					/* translators: %s: MIME type. */
					__( 'GD could not write the labelled image as %s.', 'mme-ai-image-disclosure' ),
					$mime
				)
			);
		}

		return $this->move_into_place( $temp, $path );
	}

	/**
	 * JPEG has no alpha channel — flatten onto white first.
	 *
	 * @return resource|GdImage
	 */
	private function flatten_for_jpeg() {
		$w    = imagesx( $this->image );
		$h    = imagesy( $this->image );
		$flat = imagecreatetruecolor( $w, $h );
		$white = imagecolorallocate( $flat, 255, 255, 255 );
		imagefilledrectangle( $flat, 0, 0, $w, $h, $white );
		imagealphablending( $flat, true );
		imagecopy( $flat, $this->image, 0, 0, 0, 0, $w, $h );
		return $flat;
	}

	/**
	 * Frees the GD resource.
	 */
	public function destroy() {
		if ( $this->image ) {
			imagedestroy( $this->image );
			$this->image = null;
		}
	}
}
