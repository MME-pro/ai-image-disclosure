<?php
/**
 * Image processing abstraction (PRD §5.3).
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Common interface for the Imagick and GD backends.
 */
abstract class AI_Label_Image_Processor {

	/**
	 * Absolute path of the currently loaded file.
	 *
	 * @var string
	 */
	protected $path = '';

	/**
	 * MIME type of the currently loaded file.
	 *
	 * @var string
	 */
	protected $mime = '';

	/**
	 * Image formats every backend can rewrite. Backends add the formats their build of
	 * the extension actually supports (AVIF, HEIC) on top of this.
	 *
	 * @return array
	 */
	public static function supported_mimes() {
		return apply_filters(
			'ai_label_supported_mimes',
			array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ),
			static::class
		);
	}

	/**
	 * Picks the best available backend (PRD §5.3: Imagick preferred, GD fallback).
	 *
	 * @param string|null $force 'imagick' or 'gd' to force a backend.
	 * @return AI_Label_Image_Processor|WP_Error
	 */
	public static function create( $force = null ) {
		$prefer = null !== $force ? $force : apply_filters( 'ai_label_preferred_backend', 'imagick' );

		if ( 'gd' !== $prefer && AI_Label_Imagick_Processor::is_available() ) {
			return new AI_Label_Imagick_Processor();
		}
		if ( AI_Label_GD_Processor::is_available() ) {
			return new AI_Label_GD_Processor();
		}
		if ( AI_Label_Imagick_Processor::is_available() ) {
			return new AI_Label_Imagick_Processor();
		}

		return new WP_Error(
			'ai_label_no_backend',
			__( 'Neither the Imagick nor the GD PHP extension is available, so images cannot be processed on this host.', 'ai-image-disclosure' )
		);
	}

	/**
	 * Human-readable name of the active backend.
	 *
	 * @return string
	 */
	abstract public function name();

	/**
	 * Whether this backend can run on this host.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return false;
	}

	/**
	 * Opens an image file.
	 *
	 * @param string $path Absolute path.
	 * @return true|WP_Error
	 */
	abstract public function load( $path );

	/**
	 * Width and height of the loaded image.
	 *
	 * @return array { width: int, height: int }
	 */
	abstract public function get_dimensions();

	/**
	 * Mean perceived luminance (0–255) of a region of the loaded image.
	 *
	 * @param int $x Left.
	 * @param int $y Top.
	 * @param int $w Width.
	 * @param int $h Height.
	 * @return float|WP_Error
	 */
	abstract public function sample_region_brightness( $x, $y, $w, $h );

	/**
	 * Composites a PNG over the loaded image.
	 *
	 * @param string $badge_path Absolute path of the badge PNG.
	 * @param int    $x          Left.
	 * @param int    $y          Top.
	 * @param int    $w          Target width.
	 * @param int    $h          Target height.
	 * @return true|WP_Error
	 */
	abstract public function composite( $badge_path, $x, $y, $w, $h );

	/**
	 * Scales the loaded image in place.
	 *
	 * @param int $w Target width.
	 * @param int $h Target height.
	 * @return true|WP_Error
	 */
	abstract public function resize( $w, $h );

	/**
	 * Writes the loaded image out.
	 *
	 * @param string      $path    Destination path.
	 * @param string|null $mime    Target MIME, null keeps the source format.
	 * @param int         $quality 1–100.
	 * @return true|WP_Error
	 */
	abstract public function save( $path, $mime = null, $quality = 90 );

	/**
	 * Frees resources.
	 */
	abstract public function destroy();

	/**
	 * Resolves the MIME type of a file by sniffing its bytes, not its extension.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	protected function sniff_mime( $path ) {
		$info = wp_getimagesize( $path );
		return is_array( $info ) && ! empty( $info['mime'] ) ? $info['mime'] : '';
	}

	/**
	 * Guards load() against formats we must not rewrite.
	 *
	 * @param string $path Absolute path.
	 * @return string|WP_Error Detected MIME.
	 */
	protected function validate_source( $path ) {
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error(
				'ai_label_unreadable',
				sprintf(
					/* translators: %s: file name. */
					__( 'The file %s cannot be read.', 'ai-image-disclosure' ),
					wp_basename( $path )
				)
			);
		}

		$mime = $this->sniff_mime( $path );
		if ( ! in_array( $mime, static::supported_mimes(), true ) ) {
			return new WP_Error(
				'ai_label_unsupported_mime',
				sprintf(
					/* translators: %s: MIME type. */
					__( 'Images of type %s cannot be labelled.', 'ai-image-disclosure' ),
					'' !== $mime ? $mime : __( 'unknown', 'ai-image-disclosure' )
				)
			);
		}

		return $mime;
	}

	/**
	 * Moves a freshly written temp file over the live path.
	 *
	 * Backends always write to a temp file first, so a failed encode can never truncate
	 * the live image (PRD §6.2). Windows and some network filesystems refuse to rename
	 * over a file another handle still holds, so fall back to an overwriting copy.
	 *
	 * @param string $temp Temp file path.
	 * @param string $path Destination path.
	 * @return true|WP_Error
	 */
	protected function move_into_place( $temp, $path ) {
		if ( ! file_exists( $temp ) ) {
			return new WP_Error( 'ai_label_write_failed', __( 'The labelled image was not written.', 'ai-image-disclosure' ) );
		}

		if ( AI_Label_Backup::filesystem()->move( $temp, $path, true ) ) {
			return true;
		}

		if ( copy( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- see above.
			wp_delete_file( $temp );
			return true;
		}

		wp_delete_file( $temp );
		return new WP_Error( 'ai_label_move_failed', __( 'Could not move the labelled image into place. Check the file permissions of the uploads folder.', 'ai-image-disclosure' ) );
	}

	/**
	 * Geometry of the badge for one image (PRD §5.3, §6.3).
	 *
	 * @param int    $image_w  Image width.
	 * @param int    $image_h  Image height.
	 * @param int    $badge_w  Native badge width.
	 * @param int    $badge_h  Native badge height.
	 * @param string $position One of AI_Label_Settings::positions().
	 * @param array  $settings Plugin settings.
	 * @return array { x, y, width, height, below_floor }
	 */
	public static function calculate_geometry( $image_w, $image_h, $badge_w, $badge_h, $position, array $settings ) {
		$shorter = max( 1, min( (int) $image_w, (int) $image_h ) );

		$target_w = (int) round( $shorter * ( (int) $settings['badge_scale_pct'] / 100 ) );
		$target_w = max( (int) $settings['badge_min_px'], min( (int) $settings['badge_max_px'], $target_w ) );
		$target_w = min( $target_w, (int) $image_w );

		$ratio    = $badge_w > 0 ? ( $badge_h / $badge_w ) : 1;
		$target_h = max( 1, (int) round( $target_w * $ratio ) );

		if ( $target_h > $image_h ) {
			$target_h = (int) $image_h;
			$target_w = max( 1, (int) round( $target_h / max( $ratio, 0.0001 ) ) );
		}

		$pad = (int) round( $shorter * ( (int) $settings['badge_padding_pct'] / 100 ) );

		switch ( $position ) {
			case 'top-left':
				$x = $pad;
				$y = $pad;
				break;
			case 'top-right':
				$x = $image_w - $target_w - $pad;
				$y = $pad;
				break;
			case 'bottom-left':
				$x = $pad;
				$y = $image_h - $target_h - $pad;
				break;
			case 'bottom-right':
			default:
				$x = $image_w - $target_w - $pad;
				$y = $image_h - $target_h - $pad;
				break;
		}

		$floor = isset( $settings['legibility_floor'] )
			? max( 1, (int) $settings['legibility_floor'] )
			: AI_Label_Settings::legibility_floor();

		return array(
			'x'           => max( 0, (int) $x ),
			'y'           => max( 0, (int) $y ),
			'width'       => $target_w,
			'height'      => $target_h,
			'floor'       => $floor,
			'below_floor' => $target_w < $floor,
		);
	}
}
