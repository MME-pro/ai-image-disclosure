<?php
/**
 * Installation, database schema, capabilities and upgrade routines.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation, schema creation and version upgrades.
 */
class AI_Label_Install {

	const DB_VERSION      = '1.2.0';
	const OPTION_DB_VER   = 'ai_label_db_version';
	const CRON_QUEUE_TICK = 'ai_label_queue_tick';

	/**
	 * Table name for label types.
	 *
	 * @return string
	 */
	public static function types_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_label_types';
	}

	/**
	 * Table name for badge assets.
	 *
	 * @return string
	 */
	public static function badges_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_label_badges';
	}

	/**
	 * Table name for the job log.
	 *
	 * @return string
	 */
	public static function jobs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ai_label_jobs';
	}

	/**
	 * Runs on activation.
	 */
	public static function activate() {
		self::create_tables();
		self::add_capabilities();
		AI_Label_Settings::install_defaults();
		self::seed_label_types();
		AI_Label_Backup::protect_directory();

		if ( ! wp_next_scheduled( self::CRON_QUEUE_TICK ) ) {
			wp_schedule_event( time() + 60, 'ai_label_minute', self::CRON_QUEUE_TICK );
		}

		update_option( self::OPTION_DB_VER, self::DB_VERSION, false );
	}

	/**
	 * Runs on deactivation. Never touches user data or backups.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_QUEUE_TICK );
	}

	/**
	 * Runs the schema routine again after a plugin update.
	 */
	public static function maybe_upgrade() {
		$installed = (string) get_option( self::OPTION_DB_VER );
		if ( $installed === self::DB_VERSION ) {
			return;
		}
		self::create_tables();
		self::add_capabilities();
		AI_Label_Settings::install_defaults();
		AI_Label_Backup::protect_directory();

		if ( '' !== $installed && version_compare( $installed, '1.2.0', '<' ) ) {
			self::migrate_text_shape_defaults();
		}

		update_option( self::OPTION_DB_VER, self::DB_VERSION, false );
	}

	/**
	 * Moves label types that still carry the original padding/radius/border values onto the
	 * current defaults. A type whose shape has been edited is left exactly as it is, and no
	 * already-labelled image is touched either way.
	 */
	private static function migrate_text_shape_defaults() {
		$legacy   = AI_Label_Text_Badge::legacy_shape_defaults();
		$defaults = AI_Label_Text_Badge::default_config();

		foreach ( AI_Label_Badge_Registry::get_types() as $type ) {
			$config = AI_Label_Badge_Registry::text_config_of( $type );

			foreach ( $legacy as $key => $value ) {
				if ( (int) $config[ $key ] !== (int) $value ) {
					continue 2;
				}
			}

			foreach ( array_keys( $legacy ) as $key ) {
				$config[ $key ] = $defaults[ $key ];
			}
			AI_Label_Badge_Registry::update_type( $type->id, array( 'text_config' => $config ) );
		}
	}

	/**
	 * Creates/updates the three custom tables (PRD §7.1).
	 *
	 * The jobs table carries three columns beyond the PRD sketch — batch_id (progress
	 * polling), failed_step and user_id (failure reporting per PRD §6.2).
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$types   = self::types_table();
		$badges  = self::badges_table();
		$jobs    = self::jobs_table();

		$sql = array();

		$sql[] = "CREATE TABLE {$types} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(50) NOT NULL,
			name VARCHAR(100) NOT NULL,
			alt_suffix VARCHAR(190) NOT NULL DEFAULT '',
			badge_mode VARCHAR(10) NOT NULL DEFAULT 'image',
			text_config LONGTEXT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY sort_order (sort_order)
		) {$charset};";

		$sql[] = "CREATE TABLE {$badges} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			label_type_id BIGINT UNSIGNED NOT NULL,
			variant VARCHAR(20) NOT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_variant (label_type_id,variant),
			KEY attachment_id (attachment_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id VARCHAR(32) NOT NULL DEFAULT '',
			attachment_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL,
			args LONGTEXT NULL,
			failed_step VARCHAR(50) NULL,
			error_message TEXT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NULL,
			completed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY attachment_id (attachment_id),
			KEY status (status)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Registers the custom capability on every role that can already manage options.
	 */
	public static function add_capabilities() {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( 'manage_options' ) && ! $role->has_cap( AI_LABEL_CAP ) ) {
				$role->add_cap( AI_LABEL_CAP );
			}
		}
	}

	/**
	 * Seeds a single ready-to-use label type. It defaults to a generated text label so the
	 * plugin is usable immediately, with no badge image to design or upload first; switching
	 * it to an uploaded PNG — or adding more types — is a click away.
	 */
	public static function seed_label_types() {
		if ( AI_Label_Badge_Registry::count_types() > 0 ) {
			return;
		}

		$name = __( 'AI-generated', 'ai-image-disclosure' );

		AI_Label_Badge_Registry::insert_type(
			array(
				'slug'        => 'ai-generated',
				'name'        => $name,
				'alt_suffix'  => __( '(AI-generated image)', 'ai-image-disclosure' ),
				'badge_mode'  => is_wp_error( AI_Label_Text_Badge::is_available() ) ? 'image' : 'text',
				'text_config' => AI_Label_Text_Badge::default_config( $name ),
				'sort_order'  => 0,
			)
		);
	}
}

add_filter(
	'cron_schedules', // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 60s tick only wakes when jobs are queued.
	function ( $schedules ) {
		if ( ! isset( $schedules['ai_label_minute'] ) ) {
			$schedules['ai_label_minute'] = array(
				'interval' => 60,
				'display'  => __( 'Every minute (AI Image Disclosure queue)', 'ai-image-disclosure' ),
			);
		}
		return $schedules;
	}
);
