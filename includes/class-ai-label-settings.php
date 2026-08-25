<?php
/**
 * Plugin settings storage, defaults and secret handling.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-site settings (PRD §6.4 — always get_option, never get_site_option).
 */
class AI_Label_Settings {

	const OPTION = 'ai_label_settings';

	/**
	 * Hard floor for badge legibility (PRD §6.3), overridable by filter only.
	 *
	 * @return int
	 */
	public static function legibility_floor() {
		return max( 1, (int) apply_filters( 'ai_label_legibility_floor', 24 ) );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'badge_scale_pct'      => 12,
			'badge_min_px'         => 32,
			'badge_max_px'         => 400,
			'badge_padding_pct'    => 3,
			'text_scale_pct'       => 30,
			'text_min_px'          => 120,
			'font_path'            => '',
			'min_size_width'       => 400,
			'brightness_threshold' => 128,
			'image_quality'        => 90,
			'serve_webp'           => 0,
			'cloudflare_zone'      => '',
			'cloudflare_token'     => '',
			'backup_warn_gb'       => 2,
			'delete_data_on_uninstall' => 0,
		);
	}

	/**
	 * Writes any missing defaults without clobbering existing values.
	 */
	public static function install_defaults() {
		$current = get_option( self::OPTION );
		if ( ! is_array( $current ) ) {
			$current = array();
		}
		update_option( self::OPTION, array_merge( self::defaults(), $current ) );
	}

	/**
	 * All settings, defaults merged in.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Persists a sanitized settings array (merged over the current values).
	 *
	 * @param array $input Raw, already-unslashed input.
	 */
	public static function save( array $input ) {
		$current = self::all();
		$clean   = array();

		$ints = array(
			'badge_scale_pct'      => array( 1, 100 ),
			'badge_min_px'         => array( 8, 2000 ),
			'badge_max_px'         => array( 8, 4000 ),
			'badge_padding_pct'    => array( 0, 45 ),
			'text_scale_pct'       => array( 5, 100 ),
			'text_min_px'          => array( 24, 4000 ),
			'min_size_width'       => array( 0, 10000 ),
			'brightness_threshold' => array( 0, 255 ),
			'image_quality'        => array( 40, 100 ),
			'backup_warn_gb'       => array( 1, 1000 ),
		);

		foreach ( $ints as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = max( $range[0], min( $range[1], (int) $input[ $key ] ) );
			}
		}

		if ( isset( $clean['badge_max_px'], $clean['badge_min_px'] ) && $clean['badge_max_px'] < $clean['badge_min_px'] ) {
			$clean['badge_max_px'] = $clean['badge_min_px'];
		}

		$clean['serve_webp']               = empty( $input['serve_webp'] ) ? 0 : 1;
		$clean['delete_data_on_uninstall'] = empty( $input['delete_data_on_uninstall'] ) ? 0 : 1;

		if ( isset( $input['font_path'] ) ) {
			$font = trim( sanitize_text_field( $input['font_path'] ) );
			// Only accept a path the renderer can actually use; an empty value means
			// "autodetect a system font".
			if ( '' === $font || ! is_wp_error( AI_Label_Text_Badge::validate_font( $font ) ) ) {
				$clean['font_path'] = $font;
				if ( $font !== (string) $current['font_path'] ) {
					AI_Label_Text_Badge::clear_cache();
				}
			}
		}

		if ( isset( $input['cloudflare_zone'] ) ) {
			$clean['cloudflare_zone'] = sanitize_text_field( $input['cloudflare_zone'] );
		}

		// The token field is write-only: an empty submit keeps the stored secret,
		// and the literal string "delete" clears it.
		if ( isset( $input['cloudflare_token'] ) ) {
			$token = trim( sanitize_text_field( $input['cloudflare_token'] ) );
			if ( 'delete' === strtolower( $token ) ) {
				$clean['cloudflare_token'] = '';
			} elseif ( '' !== $token ) {
				$clean['cloudflare_token'] = self::encrypt( $token );
			}
		}

		update_option( self::OPTION, array_merge( $current, $clean ) );
	}

	/**
	 * Whether a Cloudflare token is stored.
	 *
	 * @return bool
	 */
	public static function has_cloudflare() {
		$all = self::all();
		return '' !== (string) $all['cloudflare_token'] && '' !== (string) $all['cloudflare_zone'];
	}

	/**
	 * Decrypted Cloudflare API token. Never rendered back into any form field.
	 *
	 * @return string
	 */
	public static function cloudflare_token() {
		return self::decrypt( (string) self::get( 'cloudflare_token', '' ) );
	}

	/**
	 * Encryption key derived from the site's salts.
	 *
	 * @return string
	 */
	private static function key() {
		// This literal is key material, not a label: changing it would make an already
		// stored Cloudflare token undecryptable. It keeps the plugin's original slug on
		// purpose and must not be renamed.
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|ai-content-labelling', true );
	}

	/**
	 * Encrypts a secret for storage in the options table.
	 *
	 * Note: base64 is used purely as a binary-safe transport for the ciphertext and IV,
	 * not to obscure any code.
	 *
	 * @param string $value Plain text.
	 * @return string
	 */
	public static function encrypt( $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'plain:' . $value;
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $value, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return 'plain:' . $value;
		}
		return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe storage of ciphertext.
	}

	/**
	 * Reverses self::encrypt().
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function decrypt( $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, 'plain:' ) ) {
			return substr( $value, 6 );
		}
		if ( 0 !== strpos( $value, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $value, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Available badge positions.
	 *
	 * @return array
	 */
	public static function positions() {
		return array(
			'top-left'     => __( 'Top left', 'ai-image-disclosure' ),
			'top-right'    => __( 'Top right', 'ai-image-disclosure' ),
			'bottom-left'  => __( 'Bottom left', 'ai-image-disclosure' ),
			'bottom-right' => __( 'Bottom right', 'ai-image-disclosure' ),
		);
	}

	/**
	 * The two contrast variants a label type carries. Auto-contrast picks between them by
	 * sampling the target corner of each image.
	 *
	 * @return array
	 */
	public static function variants() {
		return array(
			'light' => __( 'For light backgrounds', 'ai-image-disclosure' ),
			'dark'  => __( 'For dark backgrounds', 'ai-image-disclosure' ),
		);
	}
}
