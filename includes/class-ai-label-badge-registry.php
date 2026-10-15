<?php
/**
 * Label type and badge asset registry (custom tables, PRD §7.1).
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD for label types and their badge variants.
 *
 * All queries in this class are simple, prepared, single-table reads/writes against
 * plugin-owned tables; no core API exists for them.
 */
class AI_Label_Badge_Registry {

	/**
	 * Number of registered label types.
	 *
	 * @return int
	 */
	public static function count_types() {
		global $wpdb;
		$table = AI_Label_Install::types_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * All label types, ordered.
	 *
	 * @return array Array of row objects.
	 */
	public static function get_types() {
		global $wpdb;
		$table = AI_Label_Install::types_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY sort_order ASC, name ASC" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Single label type by id.
	 *
	 * @param int $id Type id.
	 * @return object|null
	 */
	public static function get_type( $id ) {
		global $wpdb;
		$table = AI_Label_Install::types_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Single label type by slug.
	 *
	 * @param string $slug Type slug.
	 * @return object|null
	 */
	public static function get_type_by_slug( $slug ) {
		global $wpdb;
		$table = AI_Label_Install::types_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Inserts a label type.
	 *
	 * @param array $data slug, name, alt_suffix, sort_order.
	 * @return int|WP_Error New id.
	 */
	public static function insert_type( array $data ) {
		global $wpdb;

		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'ai_label_type_name', __( 'A label type needs a name.', 'ai-image-disclosure' ) );
		}
		$slug = sanitize_title( ! empty( $data['slug'] ) ? $data['slug'] : $name );
		if ( '' === $slug ) {
			return new WP_Error( 'ai_label_type_slug', __( 'A label type needs a valid slug.', 'ai-image-disclosure' ) );
		}
		if ( self::get_type_by_slug( $slug ) ) {
			return new WP_Error( 'ai_label_type_exists', __( 'A label type with that slug already exists.', 'ai-image-disclosure' ) );
		}

		$text_config = AI_Label_Text_Badge::sanitize_config(
			isset( $data['text_config'] ) ? $data['text_config'] : array(),
			$name
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			AI_Label_Install::types_table(),
			array(
				'slug'        => substr( $slug, 0, 50 ),
				'name'        => substr( $name, 0, 100 ),
				'alt_suffix'  => isset( $data['alt_suffix'] ) ? substr( sanitize_text_field( $data['alt_suffix'] ), 0, 190 ) : '',
				'badge_mode'  => self::sanitize_mode( isset( $data['badge_mode'] ) ? $data['badge_mode'] : 'image' ),
				'text_config' => wp_json_encode( $text_config ),
				'sort_order'  => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'ai_label_db', __( 'Could not save the label type.', 'ai-image-disclosure' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Updates a label type. The slug is intentionally immutable once created so that
	 * attachment metadata written against it never dangles.
	 *
	 * @param int   $id   Type id.
	 * @param array $data name, alt_suffix, sort_order.
	 * @return bool|WP_Error
	 */
	public static function update_type( $id, array $data ) {
		global $wpdb;

		$type = self::get_type( $id );
		if ( ! $type ) {
			return new WP_Error( 'ai_label_type_missing', __( 'That label type no longer exists.', 'ai-image-disclosure' ) );
		}

		$fields = array();
		if ( isset( $data['name'] ) ) {
			$name = sanitize_text_field( $data['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'ai_label_type_name', __( 'A label type needs a name.', 'ai-image-disclosure' ) );
			}
			$fields['name'] = substr( $name, 0, 100 );
		}
		if ( isset( $data['alt_suffix'] ) ) {
			$fields['alt_suffix'] = substr( sanitize_text_field( $data['alt_suffix'] ), 0, 190 );
		}
		if ( isset( $data['sort_order'] ) ) {
			$fields['sort_order'] = (int) $data['sort_order'];
		}
		if ( isset( $data['badge_mode'] ) ) {
			$fields['badge_mode'] = self::sanitize_mode( $data['badge_mode'] );
		}
		if ( isset( $data['text_config'] ) ) {
			$fields['text_config'] = wp_json_encode(
				AI_Label_Text_Badge::sanitize_config( $data['text_config'], $type->name )
			);
		}
		if ( ! $fields ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( AI_Label_Install::types_table(), $fields, array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Deletes a label type, guarding against deletion while in use (PRD §5.1).
	 *
	 * @param int $id Type id.
	 * @return true|WP_Error
	 */
	public static function delete_type( $id ) {
		global $wpdb;

		$type = self::get_type( $id );
		if ( ! $type ) {
			return new WP_Error( 'ai_label_type_missing', __( 'That label type no longer exists.', 'ai-image-disclosure' ) );
		}

		$in_use = self::count_attachments_using_type( $type->slug );
		if ( $in_use > 0 ) {
			return new WP_Error(
				'ai_label_type_in_use',
				sprintf(
					/* translators: %d: number of attachments. */
					_n(
						'This label type is still applied to %d image. Restore the original(s) first.',
						'This label type is still applied to %d images. Restore the originals first.',
						$in_use,
						'ai-image-disclosure'
					),
					$in_use
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( AI_Label_Install::badges_table(), array( 'label_type_id' => (int) $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( AI_Label_Install::types_table(), array( 'id' => (int) $id ), array( '%d' ) );
		return true;
	}

	/**
	 * How many attachments currently carry a label of the given type.
	 *
	 * @param string $slug Type slug.
	 * @return int
	 */
	public static function count_attachments_using_type( $slug ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only guard, runs once per delete attempt.
					array(
						'key'     => AI_Label_Labeller::META_KEY,
						'value'   => '"label_type":"' . $slug . '"',
						'compare' => 'LIKE',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Badge rows for a label type, keyed by variant.
	 *
	 * @param int $type_id Type id.
	 * @return array
	 */
	public static function get_badges( $type_id ) {
		global $wpdb;
		$table = AI_Label_Install::badges_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE label_type_id = %d ORDER BY variant ASC", (int) $type_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->variant ] = $row;
		}
		return $out;
	}

	/**
	 * A single badge row.
	 *
	 * @param int    $type_id Type id.
	 * @param string $variant Variant key.
	 * @return object|null
	 */
	public static function get_badge( $type_id, $variant ) {
		$badges = self::get_badges( $type_id );
		return isset( $badges[ $variant ] ) ? $badges[ $variant ] : null;
	}

	/**
	 * Picks the badge for a variant, falling back to its alternate then to any badge.
	 *
	 * @param int    $type_id Type id.
	 * @param string $variant Preferred variant.
	 * @return object|null
	 */
	public static function resolve_badge( $type_id, $variant ) {
		$badges = self::get_badges( $type_id );
		if ( ! $badges ) {
			return null;
		}
		// Fall back to the opposite contrast variant rather than refusing to label at all;
		// legacy rows from before the variant list was trimmed are still matched by key.
		$order = array( $variant );
		if ( 'light' === $variant ) {
			$order[] = 'light2';
			$order[] = 'dark';
		} elseif ( 'dark' === $variant ) {
			$order[] = 'dark2';
			$order[] = 'light';
		} else {
			$order[] = 'light';
			$order[] = 'dark';
		}
		foreach ( $order as $key ) {
			if ( isset( $badges[ $key ] ) ) {
				return $badges[ $key ];
			}
		}
		return reset( $badges );
	}

	/**
	 * The badge PNG shipped with the plugin for a type slug, used until one is uploaded.
	 *
	 * @param string $slug    Type slug.
	 * @param string $variant Variant key.
	 * @return string Absolute path, or '' when the plugin ships no default for this slug.
	 */
	public static function bundled_badge_path( $slug, $variant ) {
		$variant = 'dark' === $variant || 'dark2' === $variant ? 'dark' : 'light';
		$path    = AI_LABEL_DIR . 'assets/badges/' . sanitize_file_name( $slug . '-' . $variant . '.png' );
		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Public URL of a bundled default badge.
	 *
	 * @param string $slug    Type slug.
	 * @param string $variant Variant key.
	 * @return string URL, or '' when there is none.
	 */
	public static function bundled_badge_url( $slug, $variant ) {
		$path = self::bundled_badge_path( $slug, $variant );
		return '' === $path ? '' : AI_LABEL_URL . 'assets/badges/' . wp_basename( $path );
	}

	/**
	 * Stores (or replaces) a badge variant for a label type.
	 *
	 * @param int    $type_id       Type id.
	 * @param string $variant       Variant key.
	 * @param int    $attachment_id Badge PNG attachment id.
	 * @return true|WP_Error
	 */
	public static function set_badge( $type_id, $variant, $attachment_id ) {
		global $wpdb;

		$type_id       = (int) $type_id;
		$attachment_id = (int) $attachment_id;
		$variants      = AI_Label_Settings::variants();

		if ( ! self::get_type( $type_id ) ) {
			return new WP_Error( 'ai_label_type_missing', __( 'That label type no longer exists.', 'ai-image-disclosure' ) );
		}
		if ( ! isset( $variants[ $variant ] ) ) {
			return new WP_Error( 'ai_label_variant', __( 'Unknown badge variant.', 'ai-image-disclosure' ) );
		}

		$check = self::validate_badge_file( $attachment_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$existing = self::get_badge( $type_id, $variant );
		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				AI_Label_Install::badges_table(),
				array(
					'attachment_id' => $attachment_id,
					'created_at'    => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $existing->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				AI_Label_Install::badges_table(),
				array(
					'label_type_id' => $type_id,
					'variant'       => $variant,
					'attachment_id' => $attachment_id,
					'created_at'    => current_time( 'mysql', true ),
				),
				array( '%d', '%s', '%d', '%s' )
			);
		}

		return true;
	}

	/**
	 * Removes a badge variant.
	 *
	 * @param int $badge_id Badge row id.
	 * @return bool
	 */
	public static function delete_badge( $badge_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( AI_Label_Install::badges_table(), array( 'id' => (int) $badge_id ), array( '%d' ) );
	}

	/**
	 * Validates an uploaded badge: must exist, must really be a PNG (MIME sniffed from
	 * the file, not the extension) and should carry an alpha channel (warn, don't block).
	 *
	 * @param int $attachment_id Badge attachment id.
	 * @return true|WP_Error
	 */
	public static function validate_badge_file( $attachment_id ) {
		$path = get_attached_file( (int) $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'ai_label_badge_missing', __( 'The selected badge file could not be found.', 'ai-image-disclosure' ) );
		}

		$sniffed = wp_getimagesize( $path );
		$mime    = is_array( $sniffed ) && ! empty( $sniffed['mime'] ) ? $sniffed['mime'] : '';
		if ( 'image/png' !== $mime ) {
			return new WP_Error( 'ai_label_badge_mime', __( 'Badges must be PNG files. The selected file is not a PNG.', 'ai-image-disclosure' ) );
		}

		return true;
	}

	/**
	 * Whether a PNG has an alpha channel. Used only to raise a warning.
	 *
	 * @param string $path Absolute file path.
	 * @return bool
	 */
	public static function png_has_alpha( $path ) {
		// Byte 25 of the IHDR chunk holds the PNG colour type; 4 and 6 carry alpha.
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading 26 bytes of a local PNG header.
		if ( ! $handle ) {
			return false;
		}
		$header = fread( $handle, 26 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $header || strlen( $header ) < 26 ) {
			return false;
		}
		$color_type = ord( $header[25] );
		return in_array( $color_type, array( 4, 6 ), true ) || 3 === $color_type;
	}

	/**
	 * Normalises the badge mode.
	 *
	 * @param string $mode Raw mode.
	 * @return string image|text
	 */
	public static function sanitize_mode( $mode ) {
		return 'text' === $mode ? 'text' : 'image';
	}

	/**
	 * The badge mode of a type, defaulting to image for rows written before 1.1.0.
	 *
	 * @param object $type Type row.
	 * @return string
	 */
	public static function mode_of( $type ) {
		return self::sanitize_mode( isset( $type->badge_mode ) ? $type->badge_mode : 'image' );
	}

	/**
	 * The decoded text configuration of a type.
	 *
	 * @param object $type Type row.
	 * @return array
	 */
	public static function text_config_of( $type ) {
		$raw = isset( $type->text_config ) ? json_decode( (string) $type->text_config, true ) : null;
		return AI_Label_Text_Badge::sanitize_config( is_array( $raw ) ? $raw : array(), isset( $type->name ) ? $type->name : '' );
	}

	/**
	 * Resolves the badge to composite for a type and contrast variant, whatever the mode.
	 *
	 * This is the single place that knows the difference between an uploaded PNG and a
	 * generated text badge; everything downstream just gets a file path and a token.
	 *
	 * @param object $type    Type row.
	 * @param string $variant Preferred variant.
	 * @return array|WP_Error { path, mode, variant, token, asset_id, row_id }
	 */
	public static function resolve_badge_source( $type, $variant ) {
		if ( ! is_object( $type ) ) {
			return new WP_Error( 'ai_label_type_missing', __( 'That label type no longer exists.', 'ai-image-disclosure' ) );
		}

		if ( 'text' === self::mode_of( $type ) ) {
			$config  = self::text_config_of( $type );
			$variant = 'dark' === $variant || 'dark2' === $variant ? 'dark' : 'light';
			$path    = AI_Label_Text_Badge::render( $config, $variant );

			if ( is_wp_error( $path ) ) {
				return $path;
			}

			return array(
				'path'     => $path,
				'mode'     => 'text',
				'variant'  => $variant,
				'token'    => AI_Label_Text_Badge::token( $config, $variant ),
				'asset_id' => 0,
				'row_id'   => 0,
				'text'     => $config['text'],
			);
		}

		$badge = self::resolve_badge( $type->id, $variant );
		if ( ! $badge ) {
			$bundled = self::bundled_badge_path( $type->slug, $variant );
			if ( '' !== $bundled ) {
				$variant = 'dark' === $variant || 'dark2' === $variant ? 'dark' : 'light';
				return array(
					'path'     => $bundled,
					'mode'     => 'image',
					'variant'  => $variant,
					// Hash of the file, so a redesigned default flags labelled images for re-labelling.
					'token'    => 'bundled-' . substr( (string) md5_file( $bundled ), 0, 12 ),
					'asset_id' => 0,
					'row_id'   => 0,
					'text'     => '',
				);
			}

			return new WP_Error(
				'ai_label_no_badge',
				sprintf(
					/* translators: %s: label type name. */
					__( 'No badge image has been uploaded for the label type "%s" yet. Upload a PNG, or switch that type to a generated text label.', 'ai-image-disclosure' ),
					$type->name
				)
			);
		}

		$path = get_attached_file( (int) $badge->attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'ai_label_badge_missing', __( 'The badge file is missing from the Media Library.', 'ai-image-disclosure' ) );
		}

		return array(
			'path'     => $path,
			'mode'     => 'image',
			'variant'  => $badge->variant,
			'token'    => (string) (int) $badge->attachment_id,
			'asset_id' => (int) $badge->attachment_id,
			'row_id'   => (int) $badge->id,
			'text'     => '',
		);
	}

	/**
	 * Whether a type is ready to be applied — a badge PNG, or text plus a usable font.
	 *
	 * @param object $type Type row.
	 * @return true|WP_Error
	 */
	public static function type_is_ready( $type ) {
		$source = self::resolve_badge_source( $type, 'light' );
		return is_wp_error( $source ) ? $source : true;
	}

	/**
	 * Label types that can actually be applied right now.
	 *
	 * @return array
	 */
	public static function usable_types() {
		$out = array();
		foreach ( self::get_types() as $type ) {
			if ( true === self::type_is_ready( $type ) ) {
				$out[] = $type;
			}
		}
		return $out;
	}
}
