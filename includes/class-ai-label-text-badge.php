<?php
/**
 * Generated text badges — an alternative to uploading a badge PNG.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a label type's disclosure text into a transparent PNG, which is then composited
 * by exactly the same code path as an uploaded badge image.
 *
 * Rendered badges are cached in uploads/ai-label-cache/ under a hash of their appearance,
 * so editing the text or colours produces a new file and leaves images already labelled
 * with the old wording untouched until they are re-labelled.
 */
class AI_Label_Text_Badge {

	const CACHE_DIRNAME = 'ai-label-cache';

	/**
	 * Render height of the text in the cached PNG. The badge is scaled down to its final
	 * size when composited, so this only sets the resampling quality.
	 */
	const RENDER_FONT_PX = 96;

	/**
	 * Smallest legible text height on the finished image, in pixels.
	 *
	 * @return int
	 */
	public static function text_floor_px() {
		return max( 6, (int) apply_filters( 'ai_label_text_legibility_floor', 12 ) );
	}

	/**
	 * Default text appearance for a new label type.
	 *
	 * @param string $text Initial label text.
	 * @return array
	 */
	public static function default_config( $text = '' ) {
		return array(
			'text'         => $text,
			'padding'      => 50,
			'radius'       => 50,
			'border_width' => 1,
			'light'        => array(
				'fg'             => '#111111',
				'bg'             => '#FFFFFF',
				'bg_opacity'     => 88,
				'border'         => '#111111',
				'border_opacity' => 35,
			),
			'dark'         => array(
				'fg'             => '#FFFFFF',
				'bg'             => '#000000',
				'bg_opacity'     => 62,
				'border'         => '#FFFFFF',
				'border_opacity' => 45,
			),
		);
	}

	/**
	 * The shape defaults shipped before 1.2.4, used to tell an untouched configuration from
	 * one the admin has deliberately tuned.
	 *
	 * @return array
	 */
	public static function legacy_shape_defaults() {
		return array(
			'padding'      => 26,
			'radius'       => 999,
			'border_width' => 2,
		);
	}

	/**
	 * Sanitizes a submitted text configuration.
	 *
	 * @param array  $input    Raw input.
	 * @param string $fallback Fallback label text.
	 * @return array
	 */
	public static function sanitize_config( $input, $fallback = '' ) {
		$defaults = self::default_config( $fallback );
		$input    = is_array( $input ) ? $input : array();

		$clean          = $defaults;
		$clean['text']  = isset( $input['text'] ) ? substr( sanitize_text_field( $input['text'] ), 0, 120 ) : $defaults['text'];
		$clean['padding'] = isset( $input['padding'] ) ? max( 0, min( 200, (int) $input['padding'] ) ) : $defaults['padding'];
		$clean['radius']  = isset( $input['radius'] ) ? max( 0, min( 999, (int) $input['radius'] ) ) : $defaults['radius'];
		$clean['border_width'] = isset( $input['border_width'] ) ? max( 0, min( 20, (int) $input['border_width'] ) ) : $defaults['border_width'];

		foreach ( array( 'light', 'dark' ) as $variant ) {
			$raw = isset( $input[ $variant ] ) && is_array( $input[ $variant ] ) ? $input[ $variant ] : array();

			foreach ( array( 'fg', 'bg', 'border' ) as $key ) {
				$colour = isset( $raw[ $key ] ) ? sanitize_hex_color( $raw[ $key ] ) : null;
				$clean[ $variant ][ $key ] = $colour ? $colour : $defaults[ $variant ][ $key ];
			}
			foreach ( array( 'bg_opacity', 'border_opacity' ) as $key ) {
				$clean[ $variant ][ $key ] = isset( $raw[ $key ] ) ? max( 0, min( 100, (int) $raw[ $key ] ) ) : $defaults[ $variant ][ $key ];
			}
		}

		return $clean;
	}

	/**
	 * A stable token for the current appearance, used for idempotency and re-label
	 * detection in place of an attachment id.
	 *
	 * @param array  $config  Text config.
	 * @param string $variant light|dark.
	 * @return string
	 */
	public static function token( array $config, $variant ) {
		$font = self::resolve_font();
		return substr(
			md5(
				wp_json_encode(
					array(
						'config'  => $config,
						'variant' => 'dark' === $variant ? 'dark' : 'light',
						'font'    => is_wp_error( $font ) ? '' : wp_basename( $font ),
						'version' => AI_LABEL_VERSION,
					)
				)
			),
			0,
			16
		);
	}

	/**
	 * Absolute path of the render cache, created on demand.
	 *
	 * @return string|WP_Error
	 */
	public static function cache_dir() {
		$uploads = wp_get_upload_dir();
		$dir     = untrailingslashit( $uploads['basedir'] ) . '/' . self::CACHE_DIRNAME;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'ai_label_cache_dir',
				__( 'Could not create the badge render folder inside wp-content/uploads.', 'ai-image-disclosure' )
			);
		}

		return $dir;
	}

	/**
	 * Public URL of a cached render, for previews.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function url_for( $path ) {
		$uploads = wp_get_upload_dir();
		$base    = str_replace( '\\', '/', untrailingslashit( $uploads['basedir'] ) );
		$path    = str_replace( '\\', '/', $path );

		if ( 0 === strpos( $path, $base ) ) {
			return untrailingslashit( $uploads['baseurl'] ) . substr( $path, strlen( $base ) );
		}
		return '';
	}

	/**
	 * Font file used for rendering: the configured one, else the first of the usual
	 * system locations, else an error telling the admin to set one.
	 *
	 * @return string|WP_Error
	 */
	public static function resolve_font() {
		$configured = (string) AI_Label_Settings::get( 'font_path', '' );
		if ( '' !== $configured ) {
			$valid = self::validate_font( $configured );
			return is_wp_error( $valid ) ? $valid : $configured;
		}

		foreach ( self::font_candidates() as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return new WP_Error(
			'ai_label_no_font',
			__( 'No usable font file was found on this server. Enter the full path to a .ttf or .otf font under Settings → Font file to use generated text labels.', 'ai-image-disclosure' )
		);
	}

	/**
	 * Checks that a path is a readable, plausible font file.
	 *
	 * @param string $path Absolute path.
	 * @return true|WP_Error
	 */
	public static function validate_font( $path ) {
		if ( ! preg_match( '/\.(ttf|otf|ttc)$/i', $path ) ) {
			return new WP_Error( 'ai_label_font_type', __( 'The font must be a .ttf, .otf or .ttc file.', 'ai-image-disclosure' ) );
		}
		if ( ! is_readable( $path ) || ! is_file( $path ) ) {
			return new WP_Error( 'ai_label_font_missing', __( 'That font file cannot be read by PHP.', 'ai-image-disclosure' ) );
		}
		return true;
	}

	/**
	 * Where fonts usually live, plus anything the host has under the standard font roots.
	 *
	 * @return array
	 */
	public static function font_candidates() {
		$candidates = array(
			'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
			'/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
			'/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
			'/System/Library/Fonts/Supplemental/Arial Bold.ttf',
			'/Library/Fonts/Arial Bold.ttf',
			'C:/Windows/Fonts/arialbd.ttf',
			'C:/Windows/Fonts/arial.ttf',
			'C:/Windows/Fonts/segoeuib.ttf',
			'C:/Windows/Fonts/segoeui.ttf',
		);

		foreach ( array( '/usr/share/fonts', '/usr/local/share/fonts' ) as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			foreach ( (array) glob( $root . '/*/*.ttf' ) as $found ) {
				$candidates[] = $found;
			}
		}

		/**
		 * Filters the font files the text renderer will try, in order.
		 *
		 * @param array $candidates Absolute paths.
		 */
		return array_values( array_unique( (array) apply_filters( 'ai_label_font_candidates', $candidates ) ) );
	}

	/**
	 * Whether generated text labels can be produced on this host at all.
	 *
	 * @return true|WP_Error
	 */
	public static function is_available() {
		$font = self::resolve_font();
		if ( is_wp_error( $font ) ) {
			return $font;
		}
		if ( function_exists( 'imagettftext' ) && function_exists( 'imagettfbbox' ) ) {
			return true;
		}
		if ( class_exists( 'ImagickDraw', false ) && AI_Label_Imagick_Processor::is_available() ) {
			return true;
		}
		return new WP_Error(
			'ai_label_no_text_renderer',
			__( 'Neither GD with FreeType nor ImagickDraw is available, so text labels cannot be rendered on this host. Upload a badge PNG instead.', 'ai-image-disclosure' )
		);
	}

	/**
	 * Renders (or returns the cached) PNG for a label type's text badge.
	 *
	 * @param array  $config  Text config.
	 * @param string $variant light|dark.
	 * @return string|WP_Error Absolute path of the PNG.
	 */
	public static function render( array $config, $variant ) {
		$variant = 'dark' === $variant ? 'dark' : 'light';
		$config  = self::sanitize_config( $config, isset( $config['text'] ) ? $config['text'] : '' );

		if ( '' === trim( (string) $config['text'] ) ) {
			return new WP_Error( 'ai_label_no_text', __( 'This label type has no label text yet.', 'ai-image-disclosure' ) );
		}

		$available = self::is_available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$dir = self::cache_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$path = $dir . '/text-' . $variant . '-' . self::token( $config, $variant ) . '.png';
		if ( file_exists( $path ) && filesize( $path ) > 0 ) {
			return $path;
		}

		$font = self::resolve_font();
		if ( is_wp_error( $font ) ) {
			return $font;
		}

		if ( function_exists( 'imagettftext' ) && function_exists( 'imagettfbbox' ) ) {
			return self::render_with_gd( $config, $variant, $font, $path );
		}
		return self::render_with_imagick( $config, $variant, $font, $path );
	}

	/**
	 * Splits a hex colour into RGB components.
	 *
	 * @param string $hex Hex colour.
	 * @return array
	 */
	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			$hex = '000000';
		}
		return array(
			hexdec( substr( $hex, 0, 2 ) ),
			hexdec( substr( $hex, 2, 2 ) ),
			hexdec( substr( $hex, 4, 2 ) ),
		);
	}

	/**
	 * GD renderer (FreeType).
	 *
	 * @param array  $config  Text config.
	 * @param string $variant light|dark.
	 * @param string $font    Font path.
	 * @param string $path    Destination PNG path.
	 * @return string|WP_Error
	 */
	private static function render_with_gd( array $config, $variant, $font, $path ) {
		$text    = (string) $config['text'];
		$size    = self::RENDER_FONT_PX;
		$padding = (int) $config['padding'];
		$colours = $config[ $variant ];

		$box = imagettfbbox( $size, 0, $font, $text );
		if ( ! $box ) {
			return new WP_Error( 'ai_label_text_measure', __( 'The font could not be used to measure the label text.', 'ai-image-disclosure' ) );
		}

		$text_w = (int) ceil( abs( $box[2] - $box[0] ) );
		$text_h = (int) ceil( abs( $box[7] - $box[1] ) );
		$width  = $text_w + ( 2 * $padding );
		$height = $text_h + ( 2 * $padding );

		$image = imagecreatetruecolor( $width, $height );
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		imagefilledrectangle( $image, 0, 0, $width, $height, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );

		$radius = min( (int) $config['radius'], (int) floor( $height / 2 ) );
		$border = (int) $config['border_width'];

		if ( $border > 0 && $colours['border_opacity'] > 0 ) {
			list( $r, $g, $b ) = self::rgb( $colours['border'] );
			$colour = imagecolorallocatealpha( $image, $r, $g, $b, self::gd_alpha( $colours['border_opacity'] ) );
			self::gd_rounded_rect( $image, 0, 0, $width - 1, $height - 1, $radius, $colour );
		} else {
			$border = 0;
		}

		list( $r, $g, $b ) = self::rgb( $colours['bg'] );
		$bg = imagecolorallocatealpha( $image, $r, $g, $b, self::gd_alpha( $colours['bg_opacity'] ) );
		self::gd_rounded_rect(
			$image,
			$border,
			$border,
			$width - 1 - $border,
			$height - 1 - $border,
			max( 0, $radius - $border ),
			$bg
		);

		imagealphablending( $image, true );
		list( $r, $g, $b ) = self::rgb( $colours['fg'] );
		$fg = imagecolorallocate( $image, $r, $g, $b );
		imagettftext( $image, $size, 0, $padding - $box[0], $padding - $box[7], $fg, $font, $text );

		$temp    = $path . '.tmp';
		$written = imagepng( $image, $temp );
		imagedestroy( $image );

		if ( ! $written ) {
			return new WP_Error( 'ai_label_text_write', __( 'The rendered text badge could not be written to disk.', 'ai-image-disclosure' ) );
		}
		if ( ! AI_Label_Backup::filesystem()->move( $temp, $path, true ) ) {
			if ( ! copy( $temp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- plugin-owned cache file.
				wp_delete_file( $temp );
				return new WP_Error( 'ai_label_text_write', __( 'The rendered text badge could not be moved into the cache folder.', 'ai-image-disclosure' ) );
			}
			wp_delete_file( $temp );
		}

		return $path;
	}

	/**
	 * Converts a 0–100 opacity into GD's 0–127 alpha (127 being fully transparent).
	 *
	 * @param int $opacity Opacity percentage.
	 * @return int
	 */
	private static function gd_alpha( $opacity ) {
		return (int) round( 127 - ( ( max( 0, min( 100, (int) $opacity ) ) / 100 ) * 127 ) );
	}

	/**
	 * Draws a filled rounded rectangle. Called with alpha blending off, so the overlapping
	 * shapes replace rather than accumulate their alpha.
	 *
	 * @param resource|GdImage $image  Target image.
	 * @param int              $x1     Left.
	 * @param int              $y1     Top.
	 * @param int              $x2     Right.
	 * @param int              $y2     Bottom.
	 * @param int              $radius Corner radius.
	 * @param int              $colour Allocated colour.
	 */
	private static function gd_rounded_rect( $image, $x1, $y1, $x2, $y2, $radius, $colour ) {
		$radius = max( 0, (int) $radius );

		if ( $radius < 1 ) {
			imagefilledrectangle( $image, $x1, $y1, $x2, $y2, $colour );
			return;
		}

		imagefilledrectangle( $image, $x1 + $radius, $y1, $x2 - $radius, $y2, $colour );
		imagefilledrectangle( $image, $x1, $y1 + $radius, $x2, $y2 - $radius, $colour );

		$diameter = $radius * 2;
		imagefilledellipse( $image, $x1 + $radius, $y1 + $radius, $diameter, $diameter, $colour );
		imagefilledellipse( $image, $x2 - $radius, $y1 + $radius, $diameter, $diameter, $colour );
		imagefilledellipse( $image, $x1 + $radius, $y2 - $radius, $diameter, $diameter, $colour );
		imagefilledellipse( $image, $x2 - $radius, $y2 - $radius, $diameter, $diameter, $colour );
	}

	/**
	 * ImagickDraw renderer, used when GD has no FreeType support.
	 *
	 * @param array  $config  Text config.
	 * @param string $variant light|dark.
	 * @param string $font    Font path.
	 * @param string $path    Destination PNG path.
	 * @return string|WP_Error
	 */
	private static function render_with_imagick( array $config, $variant, $font, $path ) {
		try {
			$text    = (string) $config['text'];
			$size    = self::RENDER_FONT_PX;
			$padding = (int) $config['padding'];
			$colours = $config[ $variant ];

			$draw = new ImagickDraw();
			$draw->setFont( $font );
			$draw->setFontSize( $size );

			$probe   = new Imagick();
			$metrics = $probe->queryFontMetrics( $draw, $text );
			$probe->clear();

			$width  = (int) ceil( $metrics['textWidth'] ) + ( 2 * $padding );
			$height = (int) ceil( $metrics['textHeight'] ) + ( 2 * $padding );

			$image = new Imagick();
			$image->newImage( $width, $height, new ImagickPixel( 'transparent' ), 'png' );

			$radius = min( (int) $config['radius'], (int) floor( $height / 2 ) );
			$border = (int) $config['border_width'];

			$shape = new ImagickDraw();
			if ( $border > 0 && $colours['border_opacity'] > 0 ) {
				$shape->setFillColor( self::imagick_pixel( $colours['border'], $colours['border_opacity'] ) );
				$shape->roundRectangle( 0, 0, $width - 1, $height - 1, $radius, $radius );
			} else {
				$border = 0;
			}
			$shape->setFillColor( self::imagick_pixel( $colours['bg'], $colours['bg_opacity'] ) );
			$shape->roundRectangle(
				$border,
				$border,
				$width - 1 - $border,
				$height - 1 - $border,
				max( 0, $radius - $border ),
				max( 0, $radius - $border )
			);
			$image->drawImage( $shape );

			$draw->setFillColor( self::imagick_pixel( $colours['fg'], 100 ) );
			$draw->setGravity( Imagick::GRAVITY_CENTER );
			$image->annotateImage( $draw, 0, 0, 0, $text );

			$image->setImageFormat( 'png' );
			$written = $image->writeImage( $path );
			$image->clear();

			if ( ! $written ) {
				return new WP_Error( 'ai_label_text_write', __( 'The rendered text badge could not be written to disk.', 'ai-image-disclosure' ) );
			}
			return $path;
		} catch ( Exception $e ) {
			return new WP_Error( 'ai_label_text_render', $e->getMessage() );
		}
	}

	/**
	 * Builds an ImagickPixel from a hex colour and an opacity percentage.
	 *
	 * @param string $hex     Hex colour.
	 * @param int    $opacity 0–100.
	 * @return ImagickPixel
	 */
	private static function imagick_pixel( $hex, $opacity ) {
		list( $r, $g, $b ) = self::rgb( $hex );
		return new ImagickPixel(
			sprintf( 'rgba(%d,%d,%d,%.3f)', $r, $g, $b, max( 0, min( 100, (int) $opacity ) ) / 100 )
		);
	}

	/**
	 * Deletes every cached render. They are rebuilt on demand.
	 *
	 * @return int Files removed.
	 */
	public static function clear_cache() {
		$dir = self::cache_dir();
		if ( is_wp_error( $dir ) ) {
			return 0;
		}
		$removed = 0;
		foreach ( (array) glob( $dir . '/text-*.png' ) as $file ) {
			wp_delete_file( $file );
			++$removed;
		}
		return $removed;
	}
}
