<?php
/**
 * Cache and CDN purging (PRD §5.5).
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fires `ai_label_purge_attachment` and ships listeners for the common cache plugins
 * plus one optional CDN integration (Cloudflare).
 */
class AI_Label_Cache_Purge {

	const NOTICE_OPTION = 'ai_label_purge_notice';

	/**
	 * Registers the built-in listeners.
	 */
	public static function init() {
		add_action( 'ai_label_purge_attachment', array( __CLASS__, 'purge_page_caches' ), 10, 2 );
		add_action( 'ai_label_purge_attachment', array( __CLASS__, 'purge_cdn' ), 20, 2 );
	}

	/**
	 * Collects every public URL that changed for an attachment.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array
	 */
	public static function attachment_urls( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$urls          = array();

		$full = wp_get_attachment_url( $attachment_id );
		if ( $full ) {
			$urls[] = $full;
			$base   = trailingslashit( dirname( $full ) );

			$meta = wp_get_attachment_metadata( $attachment_id );
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$urls[] = $base . $size['file'];
					}
				}
			}
			if ( ! empty( $meta['original_image'] ) ) {
				$urls[] = $base . $meta['original_image'];
			}

			$data = AI_Label_Labeller::get_attachment_data( $attachment_id );
			if ( ! empty( $data['webp_files'] ) ) {
				foreach ( (array) $data['webp_files'] as $file ) {
					$urls[] = $base . wp_basename( $file );
				}
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Entry point used by the labeller.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function purge_attachment( $attachment_id ) {
		$urls = self::attachment_urls( $attachment_id );

		/**
		 * Fires so cache and CDN integrations can purge the changed image URLs.
		 *
		 * @param int   $attachment_id Attachment id.
		 * @param array $urls          Public URLs whose bytes changed.
		 */
		do_action( 'ai_label_purge_attachment', (int) $attachment_id, $urls );

		/**
		 * Kept as a separate, CDN-specific hook so integrations can be added without
		 * touching the plugin (PRD §3 decision 2).
		 *
		 * @param int   $attachment_id Attachment id.
		 * @param array $urls          Public URLs whose bytes changed.
		 */
		do_action( 'ai_label_purge_cdn', (int) $attachment_id, $urls );
	}

	/**
	 * Purges the caches of the page-cache plugins that are actually active.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $urls          Changed URLs.
	 */
	public static function purge_page_caches( $attachment_id, $urls ) {
		$purged = array();

		// WP Rocket.
		if ( function_exists( 'rocket_clean_files' ) && ! empty( $urls ) ) {
			rocket_clean_files( $urls );
			$purged[] = 'WP Rocket';
		} elseif ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$purged[] = 'WP Rocket';
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( (int) $attachment_id );
			$purged[] = 'W3 Total Cache';
		}

		// LiteSpeed Cache.
		if ( has_action( 'litespeed_purge_post' ) ) {
			do_action( 'litespeed_purge_post', (int) $attachment_id );
			$purged[] = 'LiteSpeed Cache';
		} elseif ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_post' ) ) {
			LiteSpeed_Cache_API::purge_post( (int) $attachment_id );
			$purged[] = 'LiteSpeed Cache';
		}

		// SG Optimizer, Autoptimize and friends expose generic flush actions.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			$purged[] = 'SiteGround Optimizer';
		}

		if ( ! $purged && ! AI_Label_Settings::has_cloudflare() ) {
			// PRD §5.5: tell the admin their CDN may still hold the old bytes.
			update_option( self::NOTICE_OPTION, 1, false );
		}
	}

	/**
	 * Purges the changed URLs from Cloudflare, if credentials are configured.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $urls          Changed URLs.
	 */
	public static function purge_cdn( $attachment_id, $urls ) {
		if ( ! AI_Label_Settings::has_cloudflare() || empty( $urls ) ) {
			return;
		}

		$zone  = AI_Label_Settings::get( 'cloudflare_zone' );
		$token = AI_Label_Settings::cloudflare_token();
		if ( '' === $zone || '' === $token ) {
			return;
		}

		// Cloudflare accepts at most 30 URLs per call.
		foreach ( array_chunk( $urls, 30 ) as $chunk ) {
			$response = wp_remote_post(
				'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( array( 'files' => array_values( $chunk ) ) ),
				)
			);

			if ( is_wp_error( $response ) ) {
				self::record_cdn_error( $response->get_error_message() );
				return;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code > 299 ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$why  = ( is_array( $body ) && ! empty( $body['errors'][0]['message'] ) ) ? $body['errors'][0]['message'] : 'HTTP ' . $code;
				self::record_cdn_error( $why );
				return;
			}
		}

		delete_option( 'ai_label_cdn_error' );
	}

	/**
	 * Stores the last CDN error for display on the settings screen.
	 *
	 * @param string $message Error message.
	 */
	private static function record_cdn_error( $message ) {
		update_option(
			'ai_label_cdn_error',
			array(
				'message' => sanitize_text_field( $message ),
				'time'    => gmdate( 'c' ),
			),
			false
		);
	}
}
