<?php
/**
 * Uninstall routine.
 *
 * Backup files are never deleted here — they may be the only copy of an original image.
 *
 * @package AI_Image_Disclosure
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$ai_label_settings = get_option( 'ai_label_settings' );
if ( ! is_array( $ai_label_settings ) || empty( $ai_label_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ai_label_badges' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ai_label_types' );
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'ai_label_jobs' );
// phpcs:enable

delete_option( 'ai_label_settings' );
delete_option( 'ai_label_db_version' );
delete_option( 'ai_label_purge_notice' );
delete_option( 'ai_label_cdn_error' );

// The per-attachment state blob goes too, so a fresh install starts clean.
delete_post_meta_by_key( '_ai_label_data' );

foreach ( wp_roles()->role_objects as $ai_label_role ) {
	if ( $ai_label_role->has_cap( 'ai_label_manage' ) ) {
		$ai_label_role->remove_cap( 'ai_label_manage' );
	}
}

wp_clear_scheduled_hook( 'ai_label_queue_tick' );
