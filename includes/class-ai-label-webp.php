<?php
/**
 * Optional WebP sidecar generation and serving (PRD §3 decision 4).
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes `{file}-ai-labelled.webp` next to each labelled file and, when the setting is on,
 * wraps front-end <img> tags in a <picture> element offering the WebP variant.
 *
 * The primary file keeps its original extension and MIME type — no same-extension WebP.
 */
class AI_Label_Webp {

	const SUFFIX = '-ai-labelled';

	/**
	 * Hooks the front-end output filter.
	 */
	public static function init() {
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'filter_img_tag' ), 20, 3 );
	}

	/**
	 * Whether sidecars are enabled and writable on this host.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) AI_Label_Settings::get( 'serve_webp' );
	}

	/**
	 * Sidecar path for a source file.
	 *
	 * @param string $file Absolute source path.
	 * @return string
	 */
	public static function sidecar_path( $file ) {
		$extension = pathinfo( $file, PATHINFO_EXTENSION );
		$base      = '' !== $extension ? substr( $file, 0, - ( strlen( $extension ) + 1 ) ) : $file;
		return $base . self::SUFFIX . '.webp';
	}

	/**
	 * Generates sidecars for the full-size file and every registered sub-size.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array Relative-to-uploads-dir sidecar basenames.
	 */
	public static function generate_sidecars( $attachment_id ) {
		$created = array();

		$primary = get_attached_file( (int) $attachment_id );
		if ( ! $primary || ! file_exists( $primary ) ) {
			return $created;
		}

		$files = array( $primary );
		$meta  = wp_get_attachment_metadata( $attachment_id );
		$dir   = trailingslashit( dirname( $primary ) );

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && file_exists( $dir . $size['file'] ) ) {
					$files[] = $dir . $size['file'];
				}
			}
		}

		foreach ( array_unique( $files ) as $file ) {
			$target    = self::sidecar_path( $file );
			$processor = AI_Label_Image_Processor::create();
			if ( is_wp_error( $processor ) ) {
				break;
			}
			$loaded = $processor->load( $file );
			if ( is_wp_error( $loaded ) ) {
				$processor->destroy();
				continue;
			}
			$saved = $processor->save( $target, 'image/webp', (int) AI_Label_Settings::get( 'image_quality' ) );
			$processor->destroy();

			if ( ! is_wp_error( $saved ) ) {
				$created[] = wp_basename( $target );
			}
		}

		return $created;
	}

	/**
	 * Deletes the sidecars recorded for an attachment.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $data          Stored label state.
	 */
	public static function delete_sidecars( $attachment_id, array $data = array() ) {
		if ( empty( $data ) ) {
			$data = AI_Label_Labeller::get_attachment_data( $attachment_id );
		}
		if ( empty( $data['webp_files'] ) ) {
			return;
		}

		$primary = get_attached_file( (int) $attachment_id );
		if ( ! $primary ) {
			return;
		}
		$dir = trailingslashit( dirname( $primary ) );

		foreach ( (array) $data['webp_files'] as $basename ) {
			$path = $dir . wp_basename( $basename );
			if ( file_exists( $path ) && false !== strpos( $path, self::SUFFIX . '.webp' ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Offers the WebP sidecar via <picture> for labelled images in post content.
	 *
	 * @param string $filtered_image The img tag markup.
	 * @param string $context        Filter context.
	 * @param int    $attachment_id  Attachment id.
	 * @return string
	 */
	public static function filter_img_tag( $filtered_image, $context, $attachment_id ) {
		if ( ! self::enabled() || ! $attachment_id ) {
			return $filtered_image;
		}
		if ( false !== strpos( $filtered_image, '<picture' ) ) {
			return $filtered_image;
		}

		$data = AI_Label_Labeller::get_attachment_data( $attachment_id );
		if ( empty( $data['status'] ) || 'labelled' !== $data['status'] || empty( $data['webp_files'] ) ) {
			return $filtered_image;
		}

		if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $filtered_image, $match ) ) {
			return $filtered_image;
		}

		$src     = $match[1];
		$sidecar = self::sidecar_path( $src );
		if ( ! in_array( wp_basename( $sidecar ), (array) $data['webp_files'], true ) ) {
			return $filtered_image;
		}

		return sprintf(
			'<picture><source srcset="%s" type="image/webp">%s</picture>',
			esc_url( $sidecar ),
			$filtered_image
		);
	}
}
