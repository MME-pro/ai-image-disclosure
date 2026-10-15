<?php
/**
 * Plugin Name:       AI Image Disclosure
 * Description:       Bakes an AI-disclosure badge (EU AI Act Art. 50) directly into Media Library image files, with full backup, revert and cache purging.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-image-disclosure
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

define( 'AI_LABEL_VERSION', '1.3.0' );
define( 'AI_LABEL_FILE', __FILE__ );
define( 'AI_LABEL_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_LABEL_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_LABEL_CAP', 'ai_label_manage' );
define( 'AI_LABEL_BACKUP_DIRNAME', 'ai-label-backups' );

require_once AI_LABEL_DIR . 'includes/class-ai-label-install.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-settings.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-text-badge.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-badge-registry.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-backup.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-processor.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-imagick.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-gd.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-labeller.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-jobs.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-cache-purge.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-webp.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-admin.php';
require_once AI_LABEL_DIR . 'includes/class-ai-label-rest.php';

/**
 * Main plugin container. Boots the subsystems and exposes shared helpers.
 */
final class AI_Label_Plugin {

	/** @var AI_Label_Plugin|null */
	private static $instance = null;

	/** @var AI_Label_Jobs */
	public $jobs;

	/** @var AI_Label_Labeller */
	public $labeller;

	/** @var AI_Label_Admin */
	public $admin;

	/** @var AI_Label_REST */
	public $rest;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->labeller = new AI_Label_Labeller();
		$this->jobs     = new AI_Label_Jobs( $this->labeller );
		$this->rest     = new AI_Label_REST( $this->jobs );
		$this->admin    = new AI_Label_Admin( $this->jobs );

		AI_Label_Cache_Purge::init();
		AI_Label_Webp::init();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'user_has_cap', array( $this, 'map_capability' ), 10, 4 );
		add_action( 'plugins_loaded', array( AI_Label_Install::class, 'maybe_upgrade' ) );
		add_action( 'wp_generate_attachment_metadata', array( $this, 'flag_external_regeneration' ), 5, 3 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once AI_LABEL_DIR . 'includes/class-ai-label-cli.php';
			AI_Label_CLI::register();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'ai-image-disclosure', false, dirname( plugin_basename( AI_LABEL_FILE ) ) . '/languages' );
	}

	/**
	 * Capability gate used by every screen and endpoint.
	 */
	public static function user_can() {
		return current_user_can( AI_LABEL_CAP ) || current_user_can( 'manage_options' );
	}

	/**
	 * Maps `ai_label_manage` onto `manage_options` (PRD §2), so the capability works on
	 * sites that were already active before it was registered, and can still be granted
	 * to an Editor role on its own.
	 *
	 * @param array $allcaps All capabilities of the user.
	 * @param array $caps    Required capabilities.
	 * @param array $args    Arguments passed to has_cap().
	 * @param mixed $user    The user object.
	 * @return array
	 */
	public function map_capability( $allcaps, $caps, $args, $user ) {
		unset( $caps, $args, $user );
		if ( empty( $allcaps[ AI_LABEL_CAP ] ) && ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ AI_LABEL_CAP ] = true;
		}
		return $allcaps;
	}

	/**
	 * Someone else (optimisation plugin, regenerate-thumbnails tool, ...) rebuilt the
	 * sub-sizes of an attachment we had labelled. We cannot re-badge silently — the
	 * full-size file may or may not still carry the badge — so flag it for the admin.
	 *
	 * Runs at priority 5 so that we see the metadata before optimisation plugins mangle it
	 * (see PRD §5.8). Deferring can be switched off with `ai_label_should_defer_optimization`.
	 *
	 * @param array  $metadata
	 * @param int    $attachment_id
	 * @param string $context
	 * @return array
	 */
	public function flag_external_regeneration( $metadata, $attachment_id, $context = '' ) {
		if ( AI_Label_Labeller::is_internal_regeneration() ) {
			return $metadata;
		}
		if ( ! apply_filters( 'ai_label_should_defer_optimization', true, $attachment_id ) ) {
			return $metadata;
		}
		$data = AI_Label_Labeller::get_attachment_data( $attachment_id );
		if ( ! empty( $data['status'] ) && 'labelled' === $data['status'] ) {
			$data['needs_reapply']        = true;
			$data['needs_reapply_reason'] = 'external_regeneration';
			AI_Label_Labeller::save_attachment_data( $attachment_id, $data );
		}
		return $metadata;
	}
}

register_activation_hook( __FILE__, array( 'AI_Label_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AI_Label_Install', 'deactivate' ) );

AI_Label_Plugin::instance();
