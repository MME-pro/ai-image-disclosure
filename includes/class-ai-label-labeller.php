<?php
/**
 * Apply / restore / re-apply orchestration.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bakes badges into image files and takes them back out again.
 *
 * Every public method returns an array with at least `status` (success|skipped|error),
 * `message` and `notes`; failures additionally carry `step` (PRD §6.2).
 */
class AI_Label_Labeller {

	const META_KEY = '_ai_label_data';

	/**
	 * True while this plugin is the one regenerating attachment metadata, so that our own
	 * regeneration does not trip the "someone else regenerated this" flag.
	 *
	 * @var bool
	 */
	private static $internal_regeneration = false;

	/**
	 * Whether the current metadata write comes from this plugin.
	 *
	 * @return bool
	 */
	public static function is_internal_regeneration() {
		return self::$internal_regeneration;
	}

	/**
	 * Reads the per-attachment state blob (PRD §5.4).
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array
	 */
	public static function get_attachment_data( $attachment_id ) {
		$raw = get_post_meta( (int) $attachment_id, self::META_KEY, true );
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array( 'status' => 'none' );
	}

	/**
	 * Writes the per-attachment state blob.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $data          State.
	 */
	public static function save_attachment_data( $attachment_id, array $data ) {
		update_post_meta( (int) $attachment_id, self::META_KEY, wp_json_encode( $data ) );
	}

	/**
	 * Idempotency signature: the same type, position and badge means "already done".
	 *
	 * @param string $type_slug Label type slug.
	 * @param string $position  Position key.
	 * @param string $token     Badge identity — the PNG's attachment id, or a hash of the
	 *                          text badge's appearance.
	 * @return string
	 */
	public static function signature( $type_slug, $position, $token ) {
		return md5( $type_slug . '|' . $position . '|' . (string) $token );
	}

	/**
	 * Geometry settings for a badge source. Text badges are wide strips, so they use their
	 * own scale and a legibility floor derived from their height.
	 *
	 * @param array $settings Plugin settings.
	 * @param array $source   Resolved badge source.
	 * @param int   $badge_w  Native badge width.
	 * @param int   $badge_h  Native badge height.
	 * @return array
	 */
	private static function geometry_settings( array $settings, array $source, $badge_w, $badge_h ) {
		if ( 'text' !== $source['mode'] ) {
			$settings['legibility_floor'] = AI_Label_Settings::legibility_floor();
			return $settings;
		}

		$settings['badge_scale_pct'] = (int) $settings['text_scale_pct'];
		$settings['badge_min_px']    = (int) $settings['text_min_px'];
		$settings['badge_max_px']    = max( (int) $settings['text_min_px'], (int) $settings['badge_max_px'] );

		// Require the rendered wording to stay at least text_floor_px tall.
		$ratio                        = $badge_h > 0 ? ( $badge_w / $badge_h ) : 1;
		$settings['legibility_floor'] = max(
			AI_Label_Settings::legibility_floor(),
			(int) ceil( AI_Label_Text_Badge::text_floor_px() * $ratio )
		);

		return $settings;
	}

	/**
	 * Applies a label to one attachment.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $args          label_type, position, variant, force.
	 * @return array
	 */
	public function apply( $attachment_id, array $args ) {
		$attachment_id = (int) $attachment_id;
		$notes         = array();

		$args = wp_parse_args(
			$args,
			array(
				'label_type' => '',
				'position'   => 'bottom-right',
				'variant'    => 'auto',
				'force'      => false,
			)
		);

		$settings = AI_Label_Settings::all();

		// --- Validate the attachment -------------------------------------------------
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return self::error( 'validate', __( 'That attachment does not exist.', 'mme-ai-image-disclosure' ) );
		}

		$primary = get_attached_file( $attachment_id );
		if ( ! $primary || ! file_exists( $primary ) ) {
			return self::error( 'validate', __( 'The image file is missing on disk.', 'mme-ai-image-disclosure' ) );
		}

		// --- Resolve label type and badge --------------------------------------------
		$type = AI_Label_Badge_Registry::get_type_by_slug( $args['label_type'] );
		if ( ! $type ) {
			return self::error( 'validate', __( 'Unknown label type.', 'mme-ai-image-disclosure' ) );
		}

		$positions = AI_Label_Settings::positions();
		$position  = isset( $positions[ $args['position'] ] ) ? $args['position'] : 'bottom-right';

		$processor = AI_Label_Image_Processor::create();
		if ( is_wp_error( $processor ) ) {
			return self::error( 'backend', $processor->get_error_message() );
		}

		$loaded = $processor->load( $primary );
		if ( is_wp_error( $loaded ) ) {
			$processor->destroy();
			return self::error( 'open', $loaded->get_error_message() );
		}

		$dimensions = $processor->get_dimensions();
		if ( $dimensions['width'] < 1 || $dimensions['height'] < 1 ) {
			$processor->destroy();
			return self::error( 'open', __( 'The image reports zero dimensions.', 'mme-ai-image-disclosure' ) );
		}

		// --- Decide the contrast variant ---------------------------------------------
		$variant = $args['variant'];
		if ( ! in_array( $variant, array_keys( AI_Label_Settings::variants() ), true ) ) {
			$variant = 'auto';
		}

		// The probe only establishes the badge's aspect ratio for geometry and brightness
		// sampling; both contrast variants of a type share the same dimensions.
		$probe = AI_Label_Badge_Registry::resolve_badge_source( $type, 'light' );
		if ( is_wp_error( $probe ) ) {
			$processor->destroy();
			return self::error( 'badge', $probe->get_error_message() );
		}

		$probe_size = wp_getimagesize( $probe['path'] );
		if ( ! $probe_size ) {
			$processor->destroy();
			return self::error( 'badge', __( 'The badge image could not be read.', 'mme-ai-image-disclosure' ) );
		}

		// A text badge is a wide, short strip, so scaling it like a square seal would make
		// the wording unreadable. Text types get their own scale, and their legibility
		// floor is derived from the text height rather than the badge width.
		$geometry_settings = self::geometry_settings( $settings, $probe, (int) $probe_size[0], (int) $probe_size[1] );

		$geometry = AI_Label_Image_Processor::calculate_geometry(
			$dimensions['width'],
			$dimensions['height'],
			(int) $probe_size[0],
			(int) $probe_size[1],
			$position,
			$geometry_settings
		);

		if ( $geometry['below_floor'] ) {
			$processor->destroy();
			return self::error(
				'geometry',
				sprintf(
					/* translators: 1: computed badge width, 2: legibility floor. */
					__( 'The badge would only be %1$dpx wide on this image, below the %2$dpx legibility floor. Raise the badge scale or the minimum badge size in settings.', 'mme-ai-image-disclosure' ),
					$geometry['width'],
					(int) $geometry_settings['legibility_floor']
				)
			);
		}

		if ( 'auto' === $variant ) {
			$margin     = (int) round( max( $geometry['width'], $geometry['height'] ) * 0.15 );
			$brightness = $processor->sample_region_brightness(
				max( 0, $geometry['x'] - $margin ),
				max( 0, $geometry['y'] - $margin ),
				$geometry['width'] + ( 2 * $margin ),
				$geometry['height'] + ( 2 * $margin )
			);
			if ( is_wp_error( $brightness ) ) {
				$variant = 'light';
				$notes[] = __( 'Brightness sampling failed; used the light-background badge.', 'mme-ai-image-disclosure' );
			} else {
				$variant = ( $brightness >= (int) $settings['brightness_threshold'] ) ? 'light' : 'dark';
				$notes[] = sprintf(
					/* translators: 1: measured brightness, 2: chosen variant. */
					__( 'Sampled brightness %1$d/255, chose the "%2$s" badge.', 'mme-ai-image-disclosure' ),
					(int) round( $brightness ),
					$variant
				);
			}
		}

		$badge = AI_Label_Badge_Registry::resolve_badge_source( $type, $variant );
		if ( is_wp_error( $badge ) ) {
			$processor->destroy();
			return self::error( 'badge', $badge->get_error_message() );
		}
		$badge_path = $badge['path'];
		$variant    = $badge['variant'];

		// --- Idempotency (PRD §5.3) ---------------------------------------------------
		$data      = self::get_attachment_data( $attachment_id );
		$signature = self::signature( $type->slug, $position, $badge['token'] );

		if ( ! empty( $data['status'] ) && 'labelled' === $data['status'] ) {
			if ( ! $args['force'] && isset( $data['signature'] ) && $data['signature'] === $signature ) {
				$processor->destroy();
				return array(
					'status'  => 'skipped',
					'code'    => 'already_labelled',
					'message' => __( 'Already labelled with this type, position and badge — nothing to do.', 'mme-ai-image-disclosure' ),
					'notes'   => $notes,
				);
			}

			// Re-labelling always starts from the original so badges never stack.
			$processor->destroy();
			$restored = $this->restore( $attachment_id, array( 'silent' => true ) );
			if ( 'error' === $restored['status'] ) {
				return self::error( 'restore_before_reapply', $restored['message'] );
			}
			$notes[] = __( 'Restored the original before re-applying, so no badge is stacked.', 'mme-ai-image-disclosure' );

			// Paths and dimensions may have changed — start the whole apply over.
			$args['force'] = false;
			$result        = $this->apply( $attachment_id, $args );
			$result['notes'] = array_merge( $notes, isset( $result['notes'] ) ? (array) $result['notes'] : array() );
			return $result;
		}

		// A badge must only ever be composited onto pristine bytes. Anything that reaches
		// this point with a live label means the restore above did not happen, so stop
		// rather than stack a second badge (PRD §5.6).
		$state_now = self::get_attachment_data( $attachment_id );
		if ( ! empty( $state_now['status'] ) && 'labelled' === $state_now['status'] ) {
			$processor->destroy();
			return self::error(
				'guard',
				__( 'This image is still marked as labelled, so it was not touched. Restore the original first, then apply the new label.', 'mme-ai-image-disclosure' )
			);
		}

		// --- Which files to badge -----------------------------------------------------
		$targets  = array( $primary );
		$original = wp_get_original_image_path( $attachment_id );
		if ( $original && $original !== $primary && file_exists( $original ) ) {
			$targets[] = $original;
			$notes[]   = __( 'This image has a separate full-resolution original; it was labelled too.', 'mme-ai-image-disclosure' );
		}

		// --- Backup ------------------------------------------------------------------
		$backups = array();
		foreach ( $targets as $target ) {
			$relative = AI_Label_Backup::backup_file( $attachment_id, $target );
			if ( is_wp_error( $relative ) ) {
				$processor->destroy();
				return self::error( 'backup', $relative->get_error_message() );
			}
			$backups[ wp_basename( $target ) ] = $relative;
		}

		// --- Composite ---------------------------------------------------------------
		$primary_geometry = $geometry;

		foreach ( $targets as $index => $target ) {
			if ( 0 === $index ) {
				$worker = $processor;
			} else {
				$worker = AI_Label_Image_Processor::create();
				if ( is_wp_error( $worker ) ) {
					$processor->destroy();
					return self::error( 'backend', $worker->get_error_message() );
				}
				$opened = $worker->load( $target );
				if ( is_wp_error( $opened ) ) {
					$worker->destroy();
					$processor->destroy();
					return self::error( 'open', $opened->get_error_message() );
				}
			}

			$size = $worker->get_dimensions();
			$geo  = AI_Label_Image_Processor::calculate_geometry(
				$size['width'],
				$size['height'],
				(int) $probe_size[0],
				(int) $probe_size[1],
				$position,
				$geometry_settings
			);
			if ( 0 === $index ) {
				$primary_geometry = $geo;
			}

			$composited = $worker->composite( $badge_path, $geo['x'], $geo['y'], $geo['width'], $geo['height'] );
			if ( is_wp_error( $composited ) ) {
				$worker->destroy();
				if ( 0 !== $index ) {
					$processor->destroy();
				}
				$this->rollback( $attachment_id, $backups, $targets );
				return self::error( 'composite', $composited->get_error_message() );
			}

			$saved = $worker->save( $target, null, (int) $settings['image_quality'] );
			$worker->destroy();

			if ( is_wp_error( $saved ) ) {
				if ( 0 !== $index ) {
					$processor->destroy();
				}
				$this->rollback( $attachment_id, $backups, $targets );
				return self::error( 'save', $saved->get_error_message() );
			}
		}

		clearstatcache();

		// --- Regenerate sub-sizes -----------------------------------------------------
		$regen = $this->regenerate_sizes( $attachment_id, $primary, $primary_geometry, $backups );
		$notes = array_merge( $notes, $regen['notes'] );

		// --- WebP sidecars (opt-in) ---------------------------------------------------
		$webp = array();
		if ( ! empty( $settings['serve_webp'] ) ) {
			$webp    = AI_Label_Webp::generate_sidecars( $attachment_id );
			$notes[] = sprintf(
				/* translators: %d: number of files. */
				_n( 'Generated %d WebP sidecar file.', 'Generated %d WebP sidecar files.', count( $webp ), 'mme-ai-image-disclosure' ),
				count( $webp )
			);
		}

		// --- Alt text (PRD §5.7) ------------------------------------------------------
		if ( $this->append_alt_suffix( $attachment_id, $type ) ) {
			$notes[] = __( 'Added the disclosure suffix to the alt text.', 'mme-ai-image-disclosure' );
		}

		// --- Persist state ------------------------------------------------------------
		$new_data = array(
			'status'         => 'labelled',
			'label_type'     => $type->slug,
			'label_type_id'  => (int) $type->id,
			'position'       => $position,
			'variant'        => $variant,
			'badge_mode'     => $badge['mode'],
			'badge_token'    => $badge['token'],
			'badge_text'     => $badge['text'],
			'badge_asset_id' => (int) $badge['asset_id'],
			'badge_row_id'   => (int) $badge['row_id'],
			'applied_at'     => gmdate( 'c' ),
			'backup_path'    => isset( $backups[ wp_basename( $primary ) ] ) ? $backups[ wp_basename( $primary ) ] : '',
			'backups'        => $backups,
			'webp_sidecar'   => ! empty( $webp ),
			'webp_files'     => $webp,
			'signature'      => $signature,
			'backend'        => $processor->name(),
			'badge_width'    => (int) $primary_geometry['width'],
			'alt_suffix'     => $type->alt_suffix,
			'unbadged_sizes' => $regen['clean_sizes'],
		);
		self::save_attachment_data( $attachment_id, $new_data );
		$processor->destroy();

		// --- Cache / CDN --------------------------------------------------------------
		AI_Label_Cache_Purge::purge_attachment( $attachment_id );

		/**
		 * Fires after an attachment has been labelled.
		 *
		 * @param int   $attachment_id Attachment id.
		 * @param array $new_data      Stored state.
		 */
		do_action( 'ai_label_applied', $attachment_id, $new_data );

		return array(
			'status'  => 'success',
			'code'    => 'labelled',
			'message' => sprintf(
				/* translators: 1: label type name, 2: position. */
				__( 'Labelled as "%1$s" (%2$s).', 'mme-ai-image-disclosure' ),
				$type->name,
				isset( $positions[ $position ] ) ? $positions[ $position ] : $position
			),
			'notes'   => $notes,
		);
	}

	/**
	 * Restores the original file(s) and clears the label state.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $args          silent (bool) to suppress the purge/action hooks.
	 * @return array
	 */
	public function restore( $attachment_id, array $args = array() ) {
		$attachment_id = (int) $attachment_id;
		$silent        = ! empty( $args['silent'] );
		$notes         = array();

		$data = self::get_attachment_data( $attachment_id );
		if ( empty( $data['backups'] ) && empty( $data['backup_path'] ) ) {
			return self::error( 'restore', __( 'No backup is recorded for this image.', 'mme-ai-image-disclosure' ) );
		}

		$primary = get_attached_file( $attachment_id );
		if ( ! $primary ) {
			return self::error( 'restore', __( 'The attachment has no file path.', 'mme-ai-image-disclosure' ) );
		}

		$backups = ! empty( $data['backups'] ) ? (array) $data['backups'] : array( wp_basename( $primary ) => $data['backup_path'] );
		$dir     = dirname( $primary );

		foreach ( $backups as $basename => $relative ) {
			$live     = $dir . '/' . $basename;
			$restored = AI_Label_Backup::restore_file( $relative, $live );
			if ( is_wp_error( $restored ) ) {
				return self::error( 'restore', $restored->get_error_message() );
			}
		}

		clearstatcache();

		AI_Label_Webp::delete_sidecars( $attachment_id, $data );

		$regen = $this->regenerate_sizes( $attachment_id, $primary, null, array() );
		$notes = array_merge( $notes, $regen['notes'] );

		$this->strip_alt_suffix( $attachment_id, $data );

		self::save_attachment_data(
			$attachment_id,
			array(
				'status'       => 'none',
				'restored_at'  => gmdate( 'c' ),
				'backups'      => $backups,
				'backup_path'  => isset( $data['backup_path'] ) ? $data['backup_path'] : '',
				'last_label'   => isset( $data['label_type'] ) ? $data['label_type'] : '',
			)
		);

		if ( ! $silent ) {
			AI_Label_Cache_Purge::purge_attachment( $attachment_id );

			/**
			 * Fires after an attachment has been restored to its original bytes.
			 *
			 * @param int   $attachment_id Attachment id.
			 * @param array $data          Previous state.
			 */
			do_action( 'ai_label_restored', $attachment_id, $data );
		}

		return array(
			'status'  => 'success',
			'code'    => 'restored',
			'message' => __( 'Original restored. The backup file was kept.', 'mme-ai-image-disclosure' ),
			'notes'   => $notes,
		);
	}

	/**
	 * Re-applies the current settings/badge to an already-labelled image.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array
	 */
	public function reapply( $attachment_id ) {
		$data = self::get_attachment_data( $attachment_id );
		if ( empty( $data['label_type'] ) ) {
			return self::error( 'reapply', __( 'This image has no recorded label to re-apply.', 'mme-ai-image-disclosure' ) );
		}

		return $this->apply(
			$attachment_id,
			array(
				'label_type' => $data['label_type'],
				'position'   => isset( $data['position'] ) ? $data['position'] : 'bottom-right',
				'variant'    => 'auto',
				'force'      => true,
			)
		);
	}

	/**
	 * Whether a newer badge asset exists than the one baked in (PRD §5.6).
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public static function can_reapply( $attachment_id ) {
		$data = self::get_attachment_data( $attachment_id );
		if ( empty( $data['status'] ) || 'labelled' !== $data['status'] ) {
			return false;
		}
		if ( ! empty( $data['needs_reapply'] ) ) {
			return true;
		}
		if ( empty( $data['label_type'] ) ) {
			return false;
		}

		$type = AI_Label_Badge_Registry::get_type_by_slug( $data['label_type'] );
		if ( ! $type ) {
			return false;
		}

		$source = AI_Label_Badge_Registry::resolve_badge_source( $type, isset( $data['variant'] ) ? $data['variant'] : 'light' );
		if ( is_wp_error( $source ) ) {
			return false;
		}

		// The badge changed identity: a different PNG, different wording or colours, or a
		// switch between image and text mode.
		if ( isset( $data['badge_token'] ) && (string) $data['badge_token'] !== (string) $source['token'] ) {
			return true;
		}
		if ( $source['mode'] !== ( isset( $data['badge_mode'] ) ? $data['badge_mode'] : 'image' ) ) {
			return true;
		}
		if ( ! isset( $data['badge_token'] ) && (int) $source['asset_id'] !== (int) ( isset( $data['badge_asset_id'] ) ? $data['badge_asset_id'] : 0 ) ) {
			return true;
		}

		// Same badge, but its row was updated after the label was applied.
		if ( $source['row_id'] ) {
			$badge   = AI_Label_Badge_Registry::get_badge( $type->id, $source['variant'] );
			$applied = isset( $data['applied_at'] ) ? strtotime( $data['applied_at'] ) : 0;
			$badged  = ( $badge && $badge->created_at ) ? strtotime( $badge->created_at . ' UTC' ) : 0;
			return $badged > $applied;
		}

		return false;
	}

	/**
	 * Puts the original files back after a mid-way failure.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $backups       basename => relative backup path.
	 * @param array $targets       Absolute live paths.
	 */
	private function rollback( $attachment_id, array $backups, array $targets ) {
		foreach ( $targets as $target ) {
			$basename = wp_basename( $target );
			if ( isset( $backups[ $basename ] ) ) {
				AI_Label_Backup::restore_file( $backups[ $basename ], $target );
			}
		}
		clearstatcache();
	}

	/**
	 * Rebuilds every registered sub-size from the labelled full-size file (PRD §5.3).
	 *
	 * Sizes that are both narrower than the configured threshold and would carry a badge
	 * below the legibility floor are rebuilt from the unlabelled backup instead, and
	 * reported as skipped (PRD §3 decision 1, §6.3).
	 *
	 * @param int        $attachment_id Attachment id.
	 * @param string     $primary       Absolute path of the (labelled) full-size file.
	 * @param array|null $geometry      Badge geometry on the full-size file, or null when restoring.
	 * @param array      $backups       basename => relative backup path.
	 * @return array { notes: array, clean_sizes: array }
	 */
	private function regenerate_sizes( $attachment_id, $primary, $geometry, array $backups ) {
		$notes       = array();
		$clean_names = array();

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		if ( ! isset( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			$meta['sizes'] = array();
		}

		$full = wp_getimagesize( $primary );
		if ( ! $full ) {
			$notes[] = __( 'Could not read the full-size image, so sub-sizes were left untouched.', 'mme-ai-image-disclosure' );
			return array(
				'notes'       => $notes,
				'clean_sizes' => $clean_names,
			);
		}

		$full_w        = (int) $full[0];
		$full_h        = (int) $full[1];
		$meta['width'] = $full_w;
		$meta['height'] = $full_h;
		$meta['filesize'] = (int) filesize( $primary );

		$threshold = (int) AI_Label_Settings::get( 'min_size_width' );
		$floor     = ( is_array( $geometry ) && ! empty( $geometry['floor'] ) )
			? (int) $geometry['floor']
			: AI_Label_Settings::legibility_floor();
		$badge_w   = ( is_array( $geometry ) && ! empty( $geometry['width'] ) ) ? (int) $geometry['width'] : 0;

		$badged_sizes = array();
		$clean_sizes  = array();

		foreach ( self::registered_sizes() as $name => $size ) {
			$dims = image_resize_dimensions( $full_w, $full_h, $size['width'], $size['height'], $size['crop'] );
			if ( ! $dims ) {
				continue;
			}
			$dst_w = (int) $dims[4];
			$dst_h = (int) $dims[5];
			if ( $dst_w < 1 || $dst_h < 1 ) {
				continue;
			}

			$scaled_badge = $badge_w > 0 ? ( $badge_w * ( $dst_w / max( 1, $full_w ) ) ) : 0;
			$keep_badge   = ( 0 === $badge_w ) || ( $dst_w >= $threshold ) || ( $scaled_badge >= $floor );

			if ( $keep_badge ) {
				$badged_sizes[ $name ] = $size;
			} else {
				$clean_sizes[ $name ] = $size;
				$clean_names[]        = $name;
			}
		}

		// Pass 1: everything that keeps the badge, straight from the labelled full-size.
		if ( $badged_sizes ) {
			$editor = wp_get_image_editor( $primary );
			if ( is_wp_error( $editor ) ) {
				$notes[] = sprintf(
					/* translators: %s: error message. */
					__( 'Sub-sizes could not be regenerated: %s', 'mme-ai-image-disclosure' ),
					$editor->get_error_message()
				);
			} else {
				$editor->set_quality( (int) AI_Label_Settings::get( 'image_quality' ) );
				$resized = $editor->multi_resize( $badged_sizes );
				if ( is_array( $resized ) ) {
					foreach ( $resized as $name => $info ) {
						$meta['sizes'][ $name ] = $info;
					}
					$notes[] = sprintf(
						/* translators: %d: number of sizes. */
						_n( 'Regenerated %d image size from the labelled original.', 'Regenerated %d image sizes from the labelled original.', count( $resized ), 'mme-ai-image-disclosure' ),
						count( $resized )
					);
				}
			}
		}

		// Pass 2: sizes too small for a legible badge, rebuilt from the untouched backup.
		if ( $clean_sizes ) {
			$source = '';
			$key    = wp_basename( $primary );
			if ( isset( $backups[ $key ] ) ) {
				$source = AI_Label_Backup::absolute( $backups[ $key ] );
			}
			if ( $source && file_exists( $source ) ) {
				$built = $this->build_sizes_from( $source, $primary, $clean_sizes );
				foreach ( $built as $name => $info ) {
					$meta['sizes'][ $name ] = $info;
				}
				$notes[] = sprintf(
					/* translators: 1: number of sizes, 2: legibility floor in px. */
					_n(
						'%1$d size skipped the badge (below the %2$dpx legibility floor) and was rebuilt from the unlabelled original.',
						'%1$d sizes skipped the badge (below the %2$dpx legibility floor) and were rebuilt from the unlabelled original.',
						count( $built ),
						'mme-ai-image-disclosure'
					),
					count( $built ),
					$floor
				);
			}
		}

		self::$internal_regeneration = true;
		wp_update_attachment_metadata( $attachment_id, $meta );
		self::$internal_regeneration = false;

		return array(
			'notes'       => $notes,
			'clean_sizes' => $clean_names,
		);
	}

	/**
	 * Generates specific sub-sizes from an alternative source file, writing them to the
	 * filenames WordPress itself would use next to the live full-size image.
	 *
	 * @param string $source  Absolute source path (may live outside the uploads folder).
	 * @param string $primary Absolute path of the live full-size image.
	 * @param array  $sizes   name => { width, height, crop }.
	 * @return array name => metadata array.
	 */
	private function build_sizes_from( $source, $primary, array $sizes ) {
		$out       = array();
		$dir       = dirname( $primary );
		$extension = pathinfo( $primary, PATHINFO_EXTENSION );
		$base      = wp_basename( $primary, '.' . $extension );

		foreach ( $sizes as $name => $size ) {
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) ) {
				continue;
			}
			$editor->set_quality( (int) AI_Label_Settings::get( 'image_quality' ) );
			if ( is_wp_error( $editor->resize( $size['width'], $size['height'], $size['crop'] ) ) ) {
				continue;
			}
			$dims  = $editor->get_size();
			$path  = $dir . '/' . $base . '-' . (int) $dims['width'] . 'x' . (int) $dims['height'] . '.' . $extension;
			$saved = $editor->save( $path );
			if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
				continue;
			}
			$out[ $name ] = array(
				'file'      => $saved['file'],
				'width'     => (int) $saved['width'],
				'height'    => (int) $saved['height'],
				'mime-type' => $saved['mime-type'],
				'filesize'  => isset( $saved['filesize'] ) ? (int) $saved['filesize'] : ( file_exists( $path ) ? (int) filesize( $path ) : 0 ),
			);
		}

		return $out;
	}

	/**
	 * All registered image sizes with their crop flags, without loading wp-admin.
	 *
	 * @return array name => { width, height, crop }.
	 */
	public static function registered_sizes() {
		$sizes = array();

		foreach ( array( 'thumbnail', 'medium', 'medium_large', 'large' ) as $name ) {
			$width  = (int) get_option( $name . '_size_w' );
			$height = (int) get_option( $name . '_size_h' );
			if ( 'medium_large' === $name ) {
				$width  = (int) get_option( 'medium_large_size_w' );
				$height = (int) get_option( 'medium_large_size_h' );
			}
			if ( $width < 1 && $height < 1 ) {
				continue;
			}
			$sizes[ $name ] = array(
				'width'  => $width,
				'height' => $height,
				'crop'   => 'thumbnail' === $name ? (bool) get_option( 'thumbnail_crop' ) : false,
			);
		}

		foreach ( wp_get_additional_image_sizes() as $name => $size ) {
			$sizes[ $name ] = array(
				'width'  => (int) $size['width'],
				'height' => (int) $size['height'],
				'crop'   => $size['crop'],
			);
		}

		/** This filter is documented in wp-admin/includes/image.php */
		$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes, array(), 0 );

		return is_array( $sizes ) ? $sizes : array();
	}

	/**
	 * Appends the disclosure suffix to the alt text if it is not already there.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param object $type          Label type row.
	 * @return bool Whether the alt text changed.
	 */
	private function append_alt_suffix( $attachment_id, $type ) {
		$suffix = trim( (string) $type->alt_suffix );
		if ( '' === $suffix ) {
			return false;
		}

		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== $alt && false !== strpos( $alt, $suffix ) ) {
			return false;
		}

		$new = '' === trim( $alt ) ? $suffix : rtrim( $alt ) . ' ' . $suffix;
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $new ) );
		return true;
	}

	/**
	 * Removes a previously appended disclosure suffix.
	 *
	 * @param int   $attachment_id Attachment id.
	 * @param array $data          Stored state.
	 * @return bool Whether the alt text changed.
	 */
	private function strip_alt_suffix( $attachment_id, array $data ) {
		$suffix = isset( $data['alt_suffix'] ) ? trim( (string) $data['alt_suffix'] ) : '';
		if ( '' === $suffix ) {
			return false;
		}

		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' === $alt || false === strpos( $alt, $suffix ) ) {
			return false;
		}

		$new = trim( str_replace( $suffix, '', $alt ) );
		$new = preg_replace( '/\s{2,}/', ' ', (string) $new );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $new );
		return true;
	}

	/**
	 * Builds an error result.
	 *
	 * @param string $step    Step identifier.
	 * @param string $message Message.
	 * @return array
	 */
	private static function error( $step, $message ) {
		return array(
			'status'  => 'error',
			'code'    => 'failed',
			'step'    => $step,
			'message' => $message,
			'notes'   => array(),
		);
	}
}
