<?php
/**
 * Original-file backup store.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copies originals into /wp-content/uploads/ai-label-backups/{attachment_id}/ and back.
 *
 * Nothing in this class ever deletes a backup except delete_all()/delete_for_attachment(),
 * which are only reachable from the explicit, confirmed "Clear old backups" tool (PRD §5.6).
 */
class AI_Label_Backup {

	/**
	 * Direct-method filesystem for plugin-owned files.
	 *
	 * The global WP_Filesystem may need FTP credentials, which cron and CLI jobs cannot
	 * supply, so this always uses the direct method.
	 *
	 * @return WP_Filesystem_Direct
	 */
	public static function filesystem() {
		static $fs = null;
		if ( null === $fs ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			if ( ! defined( 'FS_CHMOD_DIR' ) ) {
				define( 'FS_CHMOD_DIR', ( fileperms( ABSPATH ) & 0777 | 0755 ) );
			}
			if ( ! defined( 'FS_CHMOD_FILE' ) ) {
				define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
			}
			$fs = new WP_Filesystem_Direct( null );
		}
		return $fs;
	}

	/**
	 * Absolute path of the backup root, without trailing slash.
	 *
	 * @return string
	 */
	public static function root() {
		$uploads = wp_get_upload_dir();
		return untrailingslashit( $uploads['basedir'] ) . '/' . AI_LABEL_BACKUP_DIRNAME;
	}

	/**
	 * Path relative to the uploads dir, as stored in attachment metadata.
	 *
	 * @param string $absolute Absolute path.
	 * @return string
	 */
	public static function relative( $absolute ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );
		if ( 0 === strpos( $absolute, $base ) ) {
			return substr( $absolute, strlen( $base ) );
		}
		return $absolute;
	}

	/**
	 * Turns a stored relative backup path back into an absolute one.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	public static function absolute( $relative ) {
		if ( '' === $relative ) {
			return '';
		}
		if ( is_file( $relative ) ) {
			return $relative;
		}
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/\\' );
	}

	/**
	 * Directory for one attachment's backups, created on demand.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string|WP_Error
	 */
	public static function dir_for( $attachment_id ) {
		$dir = self::root() . '/' . (int) $attachment_id;
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'ai_label_backup_dir',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create the backup directory %s. Check uploads folder permissions.', 'ai-image-disclosure' ),
					$dir
				)
			);
		}
		self::protect_directory();
		return $dir;
	}

	/**
	 * Backs up one file. Existing backups are never overwritten — the first copy taken is
	 * always the pristine original.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $file          Absolute path of the live file.
	 * @return string|WP_Error Relative backup path.
	 */
	public static function backup_file( $attachment_id, $file ) {
		if ( ! file_exists( $file ) ) {
			return new WP_Error(
				'ai_label_source_missing',
				sprintf(
					/* translators: %s: file path. */
					__( 'The image file %s is missing on disk.', 'ai-image-disclosure' ),
					wp_basename( $file )
				)
			);
		}

		$dir = self::dir_for( $attachment_id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$target = $dir . '/' . wp_basename( $file );
		if ( file_exists( $target ) ) {
			return self::relative( $target );
		}

		if ( ! copy( $file, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- WP_Filesystem cannot be relied on inside cron/CLI jobs; target is plugin-owned.
			return new WP_Error(
				'ai_label_backup_failed',
				sprintf(
					/* translators: %s: file name. */
					__( 'Could not write a backup of %s.', 'ai-image-disclosure' ),
					wp_basename( $file )
				)
			);
		}

		return self::relative( $target );
	}

	/**
	 * Restores one backup over the live path.
	 *
	 * @param string $relative_backup Relative backup path.
	 * @param string $live_path       Absolute destination path.
	 * @return true|WP_Error
	 */
	public static function restore_file( $relative_backup, $live_path ) {
		$source = self::absolute( $relative_backup );
		if ( '' === $source || ! file_exists( $source ) ) {
			return new WP_Error( 'ai_label_backup_missing', __( 'The backup file is missing, so the original cannot be restored.', 'ai-image-disclosure' ) );
		}
		if ( ! copy( $source, $live_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- see backup_file().
			return new WP_Error( 'ai_label_restore_failed', __( 'Could not copy the backup back over the live file.', 'ai-image-disclosure' ) );
		}
		return true;
	}

	/**
	 * Whether an attachment has any backup on disk.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public static function has_backup( $attachment_id ) {
		$dir = self::root() . '/' . (int) $attachment_id;
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		$files = glob( $dir . '/*' );
		return ! empty( $files );
	}

	/**
	 * Total size of the backup store in bytes, plus the attachment count.
	 *
	 * @return array { bytes: int, attachments: int, files: int }
	 */
	public static function usage() {
		$root  = self::root();
		$stats = array(
			'bytes'       => 0,
			'attachments' => 0,
			'files'       => 0,
		);
		if ( ! is_dir( $root ) ) {
			return $stats;
		}

		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$files = glob( $dir . '/*' );
			if ( empty( $files ) ) {
				continue;
			}
			++$stats['attachments'];
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					++$stats['files'];
					$stats['bytes'] += (int) filesize( $file );
				}
			}
		}
		return $stats;
	}

	/**
	 * Attachment ids that have backups but are no longer labelled — i.e. the backups
	 * that "Clear old backups" would free.
	 *
	 * @return array
	 */
	public static function stale_attachment_ids() {
		$root = self::root();
		$out  = array();
		if ( ! is_dir( $root ) ) {
			return $out;
		}
		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			$id = (int) wp_basename( $dir );
			if ( ! $id ) {
				continue;
			}
			$data = AI_Label_Labeller::get_attachment_data( $id );
			if ( empty( $data['status'] ) || 'labelled' !== $data['status'] ) {
				$out[] = $id;
			}
		}
		return $out;
	}

	/**
	 * Size in bytes of the stale backups.
	 *
	 * @param array $ids Attachment ids.
	 * @return int
	 */
	public static function size_of( array $ids ) {
		$bytes = 0;
		foreach ( $ids as $id ) {
			foreach ( (array) glob( self::root() . '/' . (int) $id . '/*' ) as $file ) {
				if ( is_file( $file ) ) {
					$bytes += (int) filesize( $file );
				}
			}
		}
		return $bytes;
	}

	/**
	 * Deletes the backups of one attachment. Refuses while the attachment is labelled.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return true|WP_Error
	 */
	public static function delete_for_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$data          = AI_Label_Labeller::get_attachment_data( $attachment_id );
		if ( ! empty( $data['status'] ) && 'labelled' === $data['status'] ) {
			return new WP_Error( 'ai_label_backup_in_use', __( 'This image is still labelled — its backup is the only copy of the original.', 'ai-image-disclosure' ) );
		}
		$dir = self::root() . '/' . $attachment_id;
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		// Non-fatal if the dir is not empty.
		self::filesystem()->rmdir( $dir );
		return true;
	}

	/**
	 * Creates the backup root and its access protection (PRD §6.5).
	 */
	public static function protect_directory() {
		$root = self::root();
		if ( ! wp_mkdir_p( $root ) ) {
			return;
		}

		$files = array(
			'index.php'   => "<?php\n// Silence is golden.\n",
			'.htaccess'   => "# AI Image Disclosure backups — not for public access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n<IfModule mod_headers.c>\n\tHeader set X-Robots-Tag \"noindex, nofollow\"\n</IfModule>\n",
			'web.config'  => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n",
		);

		foreach ( $files as $name => $contents ) {
			$path = $root . '/' . $name;
			if ( file_exists( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- must also work from cron/CLI where WP_Filesystem credentials are unavailable.
			@file_put_contents( $path, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- absence is surfaced by self::is_protected().
		}
	}

	/**
	 * Whether the protection files are in place.
	 *
	 * @return bool
	 */
	public static function is_protected() {
		return file_exists( self::root() . '/index.php' );
	}
}
