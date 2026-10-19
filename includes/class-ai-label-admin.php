<?php
/**
 * Admin screens, Media Library integration and form handlers.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * All wp-admin surfaces of the plugin.
 */
class AI_Label_Admin {

	const PAGE      = 'ai-image-disclosure';
	const BULK_APPLY = 'ai_label_apply';
	const BULK_RESTORE = 'ai_label_restore';

	/**
	 * Job queue.
	 *
	 * @var AI_Label_Jobs
	 */
	private $jobs;

	/**
	 * Constructor.
	 *
	 * @param AI_Label_Jobs $jobs Queue.
	 */
	public function __construct( AI_Label_Jobs $jobs ) {
		$this->jobs = $jobs;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );

		// Media Library integration.
		add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'media_row_actions', array( $this, 'register_row_actions' ), 10, 2 );
		add_filter( 'manage_media_columns', array( $this, 'register_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 10, 2 );

		// Form handlers.
		add_action( 'admin_post_ai_label_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_ai_label_save_type', array( $this, 'handle_save_type' ) );
		add_action( 'admin_post_ai_label_delete_type', array( $this, 'handle_delete_type' ) );
		add_action( 'admin_post_ai_label_save_badge', array( $this, 'handle_save_badge' ) );
		add_action( 'admin_post_ai_label_delete_badge', array( $this, 'handle_delete_badge' ) );
		add_action( 'admin_post_ai_label_apply', array( $this, 'handle_apply_form' ) );
		add_action( 'admin_post_ai_label_clear_backups', array( $this, 'handle_clear_backups' ) );
		add_action( 'admin_post_ai_label_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_post_ai_label_clear_text_cache', array( $this, 'handle_clear_text_cache' ) );
		add_action( 'admin_post_ai_label_dismiss_purge_notice', array( $this, 'handle_dismiss_purge_notice' ) );
	}

	/* ---------------------------------------------------------------------------
	 * Menu and assets
	 * ------------------------------------------------------------------------ */

	/**
	 * Adds the screen under Media.
	 */
	public function register_menu() {
		add_submenu_page(
			'upload.php',
			__( 'MME AI Image Disclosure', 'mme-ai-image-disclosure' ),
			__( 'MME AI Image Disclosure', 'mme-ai-image-disclosure' ),
			AI_LABEL_CAP,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'apply'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$allowed = array( 'apply', 'types', 'settings', 'log', 'tools' );
		return in_array( $tab, $allowed, true ) ? $tab : 'apply';
	}

	/**
	 * Loads CSS/JS on the plugin screen and the Media Library.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$is_plugin_page = ( 'media_page_' . self::PAGE ) === $hook;
		$is_media       = in_array( $hook, array( 'upload.php' ), true );

		if ( ! $is_plugin_page && ! $is_media ) {
			return;
		}
		if ( ! AI_Label_Plugin::user_can() ) {
			return;
		}

		wp_enqueue_style( 'ai-label-admin', AI_LABEL_URL . 'assets/admin.css', array(), AI_LABEL_VERSION );

		if ( $is_plugin_page ) {
			wp_enqueue_media();
		}

		wp_enqueue_script( 'ai-label-admin', AI_LABEL_URL . 'assets/admin.js', array( 'jquery' ), AI_LABEL_VERSION, true );

		$types = array();
		foreach ( AI_Label_Badge_Registry::get_types() as $type ) {
			$types[] = array(
				'slug'      => $type->slug,
				'name'      => $type->name,
				'has_badge' => AI_Label_Badge_Registry::get_badges( $type->id ) || '' !== AI_Label_Badge_Registry::bundled_badge_path( $type->slug, 'light' ),
			);
		}

		wp_localize_script(
			'ai-label-admin',
			'aiLabelData',
			array(
				'restBase'  => esc_url_raw( rest_url( AI_Label_REST::NAMESPACE_V1 ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'types'     => $types,
				'positions' => AI_Label_Settings::positions(),
				'variants'  => AI_Label_Settings::variants(),
				'applyUrl'  => esc_url_raw( admin_url( 'upload.php?page=' . self::PAGE . '&tab=apply' ) ),
				'i18n'      => array(
					'modalTitle'   => __( 'Add AI label', 'mme-ai-image-disclosure' ),
					'labelType'    => __( 'Label type', 'mme-ai-image-disclosure' ),
					'position'     => __( 'Badge position', 'mme-ai-image-disclosure' ),
					'contrast'     => __( 'Contrast variant', 'mme-ai-image-disclosure' ),
					'auto'         => __( 'Auto (sample the image)', 'mme-ai-image-disclosure' ),
					'apply'        => __( 'Apply', 'mme-ai-image-disclosure' ),
					'cancel'       => __( 'Cancel', 'mme-ai-image-disclosure' ),
					'selectBadge'  => __( 'Select badge PNG', 'mme-ai-image-disclosure' ),
					'noSelection'  => __( 'Select at least one image first.', 'mme-ai-image-disclosure' ),
					'noTypes'      => __( 'No label type has a badge image yet. Set one up under Media → MME AI Image Disclosure.', 'mme-ai-image-disclosure' ),
					'queued'       => __( 'Queued. Processing runs in the background — you can leave this page.', 'mme-ai-image-disclosure' ),
					/* translators: 1: processed count, 2: total count. */
					'progress'     => __( 'Processed %1$d of %2$d', 'mme-ai-image-disclosure' ),
					/* translators: 1: succeeded count, 2: failed count. */
					'done'         => __( 'Finished: %1$d succeeded, %2$d failed.', 'mme-ai-image-disclosure' ),
					'confirmRestore' => __( 'Restore the original file? The badge will be removed from the image bytes.', 'mme-ai-image-disclosure' ),
					'error'        => __( 'Something went wrong.', 'mme-ai-image-disclosure' ),
					'addLabel'     => __( 'Add AI Label', 'mme-ai-image-disclosure' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------------
	 * Media Library integration
	 * ------------------------------------------------------------------------ */

	/**
	 * Adds the bulk actions.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		if ( ! AI_Label_Plugin::user_can() ) {
			return $actions;
		}
		$actions[ self::BULK_APPLY ]   = __( 'Add AI Label', 'mme-ai-image-disclosure' );
		$actions[ self::BULK_RESTORE ] = __( 'Restore original (remove AI label)', 'mme-ai-image-disclosure' );
		return $actions;
	}

	/**
	 * Bulk action handler. "Add AI Label" needs options, so it hands off to the apply
	 * screen; "Restore" is unambiguous and queues immediately.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Chosen action.
	 * @param array  $ids         Attachment ids.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_to, $action, $ids ) {
		if ( ! in_array( $action, array( self::BULK_APPLY, self::BULK_RESTORE ), true ) ) {
			return $redirect_to;
		}
		if ( ! AI_Label_Plugin::user_can() ) {
			wp_die(
				esc_html__( 'You are not allowed to manage AI content labels.', 'mme-ai-image-disclosure' ),
				'',
				array( 'response' => 403 )
			);
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
		if ( ! $ids ) {
			return $redirect_to;
		}

		if ( self::BULK_APPLY === $action ) {
			return add_query_arg(
				array(
					'page' => self::PAGE,
					'tab'  => 'apply',
					'ids'  => implode( ',', $ids ),
				),
				admin_url( 'upload.php' )
			);
		}

		$batch_id = $this->jobs->enqueue_batch( $ids, 'restore' );

		return add_query_arg(
			array(
				'page'  => self::PAGE,
				'tab'   => 'apply',
				'batch' => $batch_id,
			),
			admin_url( 'upload.php' )
		);
	}

	/**
	 * Row actions in the list view.
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Attachment.
	 * @return array
	 */
	public function register_row_actions( $actions, $post ) {
		if ( ! AI_Label_Plugin::user_can() || ! wp_attachment_is_image( $post->ID ) ) {
			return $actions;
		}

		$data      = AI_Label_Labeller::get_attachment_data( $post->ID );
		$labelled  = ! empty( $data['status'] ) && 'labelled' === $data['status'];
		$apply_url = add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => 'apply',
				'ids'  => $post->ID,
			),
			admin_url( 'upload.php' )
		);

		if ( ! $labelled ) {
			$actions['ai_label_apply'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $apply_url ),
				esc_html__( 'Add AI Label', 'mme-ai-image-disclosure' )
			);
			return $actions;
		}

		// Changing a label always restores the original first, so this is safe to offer
		// on an already-labelled image — it can never stack a second badge.
		$actions['ai_label_relabel'] = sprintf(
			'<a href="%s" title="%s">%s</a>',
			esc_url( $apply_url ),
			esc_attr__( 'Restores the original file first, then applies the label you choose', 'mme-ai-image-disclosure' ),
			esc_html__( 'Change AI label', 'mme-ai-image-disclosure' )
		);

		$actions['ai_label_restore'] = sprintf(
			'<a href="#" class="ai-label-row-action" data-ai-label-action="restore" data-ai-label-id="%d">%s</a>',
			(int) $post->ID,
			esc_html__( 'Restore original', 'mme-ai-image-disclosure' )
		);

		if ( AI_Label_Labeller::can_reapply( $post->ID ) ) {
			$actions['ai_label_reapply'] = sprintf(
				'<a href="#" class="ai-label-row-action" data-ai-label-action="reapply" data-ai-label-id="%d" title="%s">%s</a>',
				(int) $post->ID,
				esc_attr__( 'Restores the original, then re-applies this label with the current badge and settings', 'mme-ai-image-disclosure' ),
				esc_html__( 'Re-apply with current badge', 'mme-ai-image-disclosure' )
			);
		}

		return $actions;
	}

	/**
	 * Adds the status column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function register_media_column( $columns ) {
		if ( AI_Label_Plugin::user_can() ) {
			$columns['ai_label'] = __( 'AI label', 'mme-ai-image-disclosure' );
		}
		return $columns;
	}

	/**
	 * Renders the status column.
	 *
	 * @param string $column        Column key.
	 * @param int    $attachment_id Attachment id.
	 */
	public function render_media_column( $column, $attachment_id ) {
		if ( 'ai_label' !== $column ) {
			return;
		}
		echo wp_kses_post( $this->status_html( (int) $attachment_id ) );
	}

	/**
	 * Small status badge markup, reused in several screens.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return string
	 */
	private function status_html( $attachment_id ) {
		$data = AI_Label_Labeller::get_attachment_data( $attachment_id );

		if ( empty( $data['status'] ) || 'labelled' !== $data['status'] ) {
			return '<span class="ai-label-pill ai-label-pill--none">' . esc_html__( 'Not labelled', 'mme-ai-image-disclosure' ) . '</span>';
		}

		$type = ! empty( $data['label_type'] ) ? AI_Label_Badge_Registry::get_type_by_slug( $data['label_type'] ) : null;
		$name = $type ? $type->name : $data['label_type'];

		$positions = AI_Label_Settings::positions();
		$position  = isset( $positions[ $data['position'] ] ) ? $positions[ $data['position'] ] : '';

		$html = '<span class="ai-label-pill ai-label-pill--on">' . esc_html( $name ) . '</span>';
		if ( '' !== $position ) {
			$html .= '<br><span class="ai-label-meta">' . esc_html( $position ) . '</span>';
		}
		if ( ! empty( $data['needs_reapply'] ) ) {
			$html .= '<br><span class="ai-label-meta ai-label-meta--warn">' . esc_html__( 'Sizes were regenerated elsewhere — re-apply recommended.', 'mme-ai-image-disclosure' ) . '</span>';
		}
		return $html;
	}

	/**
	 * Read-only status inside the attachment details modal.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public function attachment_fields( $fields, $post ) {
		if ( ! AI_Label_Plugin::user_can() || ! wp_attachment_is_image( $post->ID ) ) {
			return $fields;
		}

		$apply_url = add_query_arg(
			array(
				'page' => self::PAGE,
				'tab'  => 'apply',
				'ids'  => $post->ID,
			),
			admin_url( 'upload.php' )
		);

		$fields['ai_label_status'] = array(
			'label'         => __( 'AI label', 'mme-ai-image-disclosure' ),
			'input'         => 'html',
			'html'          => $this->status_html( $post->ID ) . sprintf(
				'<p><a href="%s" class="button button-small">%s</a></p>',
				esc_url( $apply_url ),
				esc_html__( 'Manage AI label', 'mme-ai-image-disclosure' )
			),
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $fields;
	}

	/* ---------------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------------ */

	/**
	 * Stores a notice for the next screen.
	 *
	 * @param string $type    error|success|warning|info.
	 * @param string $message Message.
	 */
	private function add_notice( $type, $message ) {
		set_transient(
			'ai_label_notice_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			60
		);
	}

	/**
	 * Prints queued notices plus the standing environment warnings.
	 */
	public function render_notices() {
		if ( ! AI_Label_Plugin::user_can() ) {
			return;
		}

		$key    = 'ai_label_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( is_array( $notice ) && ! empty( $notice['message'] ) ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( in_array( $notice['type'], array( 'error', 'success', 'warning', 'info' ), true ) ? $notice['type'] : 'info' ),
				esc_html( $notice['message'] )
			);
		}

		$screen = get_current_screen();
		$on_our_screens = $screen && in_array( $screen->id, array( 'upload', 'media_page_' . self::PAGE, 'dashboard' ), true );
		if ( ! $on_our_screens ) {
			return;
		}

		$backend = AI_Label_Image_Processor::create();
		if ( is_wp_error( $backend ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $backend->get_error_message() ) );
		}

		if ( ! AI_Label_Backup::is_protected() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'MME AI Image Disclosure could not write the access-protection files into its backup folder. Check that wp-content/uploads is writable.', 'mme-ai-image-disclosure' )
			);
		}

		$usage     = AI_Label_Backup::usage();
		$threshold = (int) AI_Label_Settings::get( 'backup_warn_gb' ) * GB_IN_BYTES;
		if ( $usage['bytes'] > $threshold ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: current size, 2: threshold. */
						__( 'AI label backups now use %1$s, above your %2$s warning threshold. Review them under Media → MME AI Image Disclosure → Tools. Nothing is ever deleted automatically.', 'mme-ai-image-disclosure' ),
						size_format( $usage['bytes'], 1 ),
						size_format( $threshold, 0 )
					)
				)
			);
		}

		if ( get_option( AI_Label_Cache_Purge::NOTICE_OPTION ) ) {
			printf(
				'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Image files were replaced in place. Your CDN or proxy cache may still serve the unlabelled versions — purge it manually.', 'mme-ai-image-disclosure' ),
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=ai_label_dismiss_purge_notice' ),
						'ai_label_dismiss_purge_notice'
					)
				),
				esc_html__( 'Dismiss', 'mme-ai-image-disclosure' )
			);
		}

		$pending = $this->jobs->pending_count();
		if ( $pending > 0 ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of jobs. */
						_n( '%d AI labelling job is still queued in the background.', '%d AI labelling jobs are still queued in the background.', $pending, 'mme-ai-image-disclosure' ),
						$pending
					)
				)
			);
		}
	}

	/**
	 * Dashboard widget with backup usage (PRD §3 decision 5).
	 */
	public function register_dashboard_widget() {
		if ( ! AI_Label_Plugin::user_can() ) {
			return;
		}
		wp_add_dashboard_widget(
			'ai_label_backups',
			__( 'AI label backups', 'mme-ai-image-disclosure' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	/**
	 * Dashboard widget body.
	 */
	public function render_dashboard_widget() {
		$usage     = AI_Label_Backup::usage();
		$threshold = (int) AI_Label_Settings::get( 'backup_warn_gb' ) * GB_IN_BYTES;
		?>
		<p>
			<strong><?php echo esc_html( size_format( $usage['bytes'], 1 ) ); ?></strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of files, 2: number of images. */
					_n( 'in %1$d backup file across %2$d image.', 'in %1$d backup files across %2$d images.', $usage['attachments'], 'mme-ai-image-disclosure' ),
					$usage['files'],
					$usage['attachments']
				)
			);
			?>
		</p>
		<?php if ( $usage['bytes'] > $threshold ) : ?>
			<p class="ai-label-warn">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: threshold size. */
						__( 'Above your %s warning threshold.', 'mme-ai-image-disclosure' ),
						size_format( $threshold, 0 )
					)
				);
				?>
			</p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( admin_url( 'upload.php?page=' . self::PAGE . '&tab=tools' ) ); ?>">
				<?php esc_html_e( 'Manage backups', 'mme-ai-image-disclosure' ); ?>
			</a>
		</p>
		<?php
	}

	/* ---------------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------------ */

	/**
	 * Router for the tabbed screen.
	 */
	public function render_page() {
		if ( ! AI_Label_Plugin::user_can() ) {
			wp_die(
				esc_html__( 'You are not allowed to manage AI content labels.', 'mme-ai-image-disclosure' ),
				'',
				array( 'response' => 403 )
			);
		}

		$tab  = $this->current_tab();
		$tabs = array(
			'apply'    => __( 'Apply labels', 'mme-ai-image-disclosure' ),
			'types'    => __( 'Label types & badges', 'mme-ai-image-disclosure' ),
			'settings' => __( 'Settings', 'mme-ai-image-disclosure' ),
			'log'      => __( 'Job log', 'mme-ai-image-disclosure' ),
			'tools'    => __( 'Tools', 'mme-ai-image-disclosure' ),
		);
		?>
		<div class="wrap ai-label-wrap">
			<?php // Core moves admin notices after this marker; without it they land inside the flex header beside the title. ?>
			<hr class="wp-header-end">
			<div class="ai-label-header">
				<div class="ai-label-header__title">
					<?php $this->render_brand_mark(); ?>
					<h1 class="ai-label-title">
						<?php esc_html_e( 'MME AI Image Disclosure', 'mme-ai-image-disclosure' ); ?>
						<span class="ai-label-badge-version">v<?php echo esc_html( AI_LABEL_VERSION ); ?></span>
						<span class="ai-label-title__sub"><?php esc_html_e( 'Disclosure badges written into the image files themselves — reversible, never automatic.', 'mme-ai-image-disclosure' ); ?></span>
					</h1>
				</div>
			</div>

			<nav class="ai-label-tabs" aria-label="<?php esc_attr_e( 'MME AI Image Disclosure sections', 'mme-ai-image-disclosure' ); ?>">
				<?php
				$pending = $this->jobs->pending_count();
				foreach ( $tabs as $slug => $label ) :
					?>
					<a href="<?php echo esc_url( admin_url( 'upload.php?page=' . self::PAGE . '&tab=' . $slug ) ); ?>"
						class="ai-label-tab <?php echo $tab === $slug ? 'is-active' : ''; ?>"
						<?php echo $tab === $slug ? 'aria-current="page"' : ''; ?>>
						<?php $this->render_tab_icon( $slug ); ?>
						<span class="ai-label-tab__text"><?php echo esc_html( $label ); ?></span>
						<?php if ( 'log' === $slug && $pending > 0 ) : ?>
							<span class="ai-label-tab__count" title="<?php esc_attr_e( 'Jobs still running', 'mme-ai-image-disclosure' ); ?>"><?php echo esc_html( number_format_i18n( $pending ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<?php
			switch ( $tab ) {
				case 'types':
					$this->render_types_tab();
					break;
				case 'settings':
					$this->render_settings_tab();
					break;
				case 'log':
					$this->render_log_tab();
					break;
				case 'tools':
					$this->render_tools_tab();
					break;
				default:
					$this->render_apply_tab();
			}
			?>

			<?php $this->render_env_chips(); ?>
		</div>
		<?php
	}

	/**
	 * The environment summary in the page footer: which image backend and queue runner are
	 * live, whether text rendering is possible, and how much the backups weigh.
	 */
	private function render_env_chips() {
		$backend = AI_Label_Image_Processor::create();
		$font    = AI_Label_Text_Badge::is_available();
		$usage   = AI_Label_Backup::usage();
		$pending = $this->jobs->pending_count();

		$chips = array();

		$chips[] = is_wp_error( $backend )
			? array(
				'warn' => true,
				'html' => esc_html__( 'No image backend', 'mme-ai-image-disclosure' ),
			)
			: array(
				'warn' => false,
				'html' => sprintf(
					/* translators: %s: backend name, e.g. Imagick. */
					esc_html__( 'Engine %s', 'mme-ai-image-disclosure' ),
					'<strong>' . esc_html( $backend->name() ) . '</strong>'
				),
			);

		$chips[] = array(
			'warn' => false,
			'html' => sprintf(
				/* translators: %s: queue runner name. */
				esc_html__( 'Queue %s', 'mme-ai-image-disclosure' ),
				'<strong>' . esc_html( AI_Label_Jobs::has_action_scheduler() ? 'Action Scheduler' : 'WP-Cron' ) . '</strong>'
			),
		);

		$chips[] = is_wp_error( $font )
			? array(
				'warn' => true,
				'html' => esc_html__( 'Text labels unavailable', 'mme-ai-image-disclosure' ),
			)
			: array(
				'warn' => false,
				'html' => esc_html__( 'Text labels ready', 'mme-ai-image-disclosure' ),
			);

		if ( $pending > 0 ) {
			$chips[] = array(
				'warn' => true,
				'html' => sprintf(
					/* translators: %d: number of queued jobs. */
					esc_html( _n( '%d job running', '%d jobs running', $pending, 'mme-ai-image-disclosure' ) ),
					$pending
				),
			);
		}

		$chips[] = array(
			'warn' => false,
			'html' => sprintf(
				/* translators: %s: formatted file size. */
				esc_html__( 'Backups %s', 'mme-ai-image-disclosure' ),
				'<strong>' . esc_html( size_format( $usage['bytes'], 1 ) ) . '</strong>'
			),
		);
		?>
		<footer class="ai-label-footer">
			<span class="ai-label-footer__label"><?php esc_html_e( 'Environment', 'mme-ai-image-disclosure' ); ?></span>
			<div class="ai-label-env">
				<?php foreach ( $chips as $chip ) : ?>
					<span class="ai-label-chip <?php echo $chip['warn'] ? 'ai-label-chip--warn' : ''; ?>">
						<span class="ai-label-chip__dot" aria-hidden="true"></span>
						<?php echo wp_kses( $chip['html'], array( 'strong' => array() ) ); ?>
					</span>
				<?php endforeach; ?>
			</div>
		</footer>
		<?php
	}

	/**
	 * Apply screen: the selection carried over from the Media Library, the options form
	 * and the live progress/results panel.
	 */
	private function render_apply_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation parameters.
		$raw_ids = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
		$batch   = isset( $_GET['batch'] ) ? sanitize_key( wp_unslash( $_GET['batch'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$ids = array_values( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) );
		$ids = array_filter( $ids, 'wp_attachment_is_image' );

		$types     = AI_Label_Badge_Registry::get_types();
		$positions = AI_Label_Settings::positions();

		if ( $batch ) {
			$progress = $this->jobs->batch_progress( $batch );
			$percent  = $progress['total'] ? round( 100 * $progress['done'] / $progress['total'] ) : 0;
			?>
			<div class="ai-label-card" id="ai-label-batch" data-batch="<?php echo esc_attr( $batch ); ?>">
				<div class="ai-label-progress-head">
					<p class="ai-label-progress-text">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: processed count, 2: total count. */
								__( 'Processed %1$d of %2$d', 'mme-ai-image-disclosure' ),
								(int) $progress['done'],
								(int) $progress['total']
							)
						);
						?>
					</p>
					<span class="ai-label-progress-count"><?php echo esc_html( $percent . '%' ); ?></span>
				</div>
				<div class="ai-label-progress <?php echo $progress['complete'] ? '' : 'is-running'; ?>">
					<div class="ai-label-progress__bar" style="width:<?php echo esc_attr( $percent ); ?>%"></div>
				</div>
				<table class="widefat ai-label-results">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Image', 'mme-ai-image-disclosure' ); ?></th>
							<th><?php esc_html_e( 'Action', 'mme-ai-image-disclosure' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mme-ai-image-disclosure' ); ?></th>
							<th><?php esc_html_e( 'Detail', 'mme-ai-image-disclosure' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
				<div class="ai-label-card__actions ai-label-card__actions--plain">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>"><?php esc_html_e( 'Back to the Media Library', 'mme-ai-image-disclosure' ); ?></a>
					<span class="ai-label-hint"><?php esc_html_e( 'Processing runs in the background — you can safely leave this page.', 'mme-ai-image-disclosure' ); ?></span>
				</div>
			</div>
			<?php
			return;
		}

		if ( ! $ids ) {
			?>
			<div class="ai-label-empty">
				<span class="ai-label-empty__icon"><span class="dashicons dashicons-images-alt2"></span></span>
				<h2><?php esc_html_e( 'Pick the images you want to disclose', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'Labelling is always a deliberate action — nothing is ever processed automatically. Select images in the Media Library, then choose “Add AI Label” from the bulk actions menu, or use the row action on a single image.', 'mme-ai-image-disclosure' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>">
					<?php esc_html_e( 'Open the Media Library', 'mme-ai-image-disclosure' ); ?>
				</a>
			</div>
			<?php
			return;
		}

		if ( ! $types ) {
			?>
			<div class="ai-label-empty">
				<span class="ai-label-empty__icon"><span class="dashicons dashicons-tag"></span></span>
				<h2><?php esc_html_e( 'No label types yet', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'Create a label type first. You can upload a badge PNG, or have the plugin render a text label for you — no image required.', 'mme-ai-image-disclosure' ); ?></p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'upload.php?page=' . self::PAGE . '&tab=types' ) ); ?>">
					<?php esc_html_e( 'Set up label types', 'mme-ai-image-disclosure' ); ?>
				</a>
			</div>
			<?php
			return;
		}
		?>

		<?php
		$already = array_values(
			array_filter(
				$ids,
				function ( $id ) {
					$state = AI_Label_Labeller::get_attachment_data( $id );
					return ! empty( $state['status'] ) && 'labelled' === $state['status'];
				}
			)
		);
		$no_backup = array_values(
			array_filter(
				$already,
				function ( $id ) {
					return ! AI_Label_Backup::has_backup( $id );
				}
			)
		);
		?>

		<?php if ( $already ) : ?>
			<div class="ai-label-inline-notice ai-label-inline-notice--info"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of images. */
						_n(
							'%d of these images is already labelled. Its original will be restored from the backup first, then the label you choose below is applied to those original bytes — a second badge is never stacked on top.',
							'%d of these images are already labelled. Their originals will be restored from the backups first, then the label you choose below is applied to those original bytes — a second badge is never stacked on top.',
							count( $already ),
							'mme-ai-image-disclosure'
						),
						count( $already )
					)
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( $no_backup ) : ?>
			<div class="ai-label-inline-notice ai-label-inline-notice--error"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of images. */
						_n(
							'%d labelled image has no backup on disk, so it cannot be relabelled — the plugin refuses to composite onto an already-badged file. Restore it from your own backup first.',
							'%d labelled images have no backup on disk, so they cannot be relabelled — the plugin refuses to composite onto already-badged files. Restore them from your own backups first.',
							count( $no_backup ),
							'mme-ai-image-disclosure'
						),
						count( $no_backup )
					)
				);
				?>
			</p></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ai-label-form">
			<?php $this->form_fields( 'ai_label_apply' ); ?>
			<input type="hidden" name="action" value="ai_label_apply">
			<input type="hidden" name="ids" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">

			<div class="ai-label-cols">
			<div>
			<div class="ai-label-card">
				<div class="ai-label-card__head">
					<h2>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of images. */
								_n( 'Label %d image', 'Label %d images', count( $ids ), 'mme-ai-image-disclosure' ),
								count( $ids )
							)
						);
						?>
					</h2>
					<p><?php esc_html_e( 'The badge is written into the image file itself. The original is copied to a protected backup folder first, and “Restore original” puts it back byte-for-byte.', 'mme-ai-image-disclosure' ); ?></p>
				</div>

				<div class="ai-label-field">
					<label for="ai-label-type"><?php esc_html_e( 'Label type', 'mme-ai-image-disclosure' ); ?></label>
						<select name="label_type" id="ai-label-type" required>
							<?php foreach ( $types as $type ) : ?>
								<?php $ready = AI_Label_Badge_Registry::type_is_ready( $type ); ?>
								<option value="<?php echo esc_attr( $type->slug ); ?>" <?php disabled( is_wp_error( $ready ) ); ?>>
									<?php
									echo esc_html( $type->name );
									if ( 'text' === AI_Label_Badge_Registry::mode_of( $type ) ) {
										echo esc_html__( ' (text label)', 'mme-ai-image-disclosure' );
									}
									if ( is_wp_error( $ready ) ) {
										echo esc_html__( ' — not ready', 'mme-ai-image-disclosure' );
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
				</div>

				<div class="ai-label-field">
					<span class="ai-label-label"><?php esc_html_e( 'Badge position', 'mme-ai-image-disclosure' ); ?></span>
					<div class="ai-label-position-picker">
						<?php foreach ( $positions as $key => $label ) : ?>
							<label class="ai-label-position ai-label-position--<?php echo esc_attr( $key ); ?>">
								<input type="radio" name="position" value="<?php echo esc_attr( $key ); ?>" <?php checked( 'bottom-right', $key ); ?>>
								<span class="ai-label-position__mock" aria-hidden="true">
									<span class="ai-label-position__badge"></span>
								</span>
								<span class="ai-label-position__label"><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="ai-label-field">
					<label for="ai-label-variant"><?php esc_html_e( 'Contrast handling', 'mme-ai-image-disclosure' ); ?></label>
					<select name="variant" id="ai-label-variant">
						<option value="auto"><?php esc_html_e( 'Auto — sample the corner and pick the readable badge', 'mme-ai-image-disclosure' ); ?></option>
						<?php foreach ( AI_Label_Settings::variants() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="ai-label-hint"><?php esc_html_e( 'Auto measures the brightness of the target corner on every image, so a light badge never lands on a light background.', 'mme-ai-image-disclosure' ); ?></span>
				</div>

				<div class="ai-label-disclosure">
				<button type="button" class="button-link ai-label-toggle-overrides" aria-expanded="false">
					<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					<?php esc_html_e( 'Per-image overrides', 'mme-ai-image-disclosure' ); ?>
				</button>
			<div class="ai-label-overrides" hidden>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Image', 'mme-ai-image-disclosure' ); ?></th>
							<th><?php esc_html_e( 'Current state', 'mme-ai-image-disclosure' ); ?></th>
							<th><?php esc_html_e( 'Label type', 'mme-ai-image-disclosure' ); ?></th>
							<th><?php esc_html_e( 'Position', 'mme-ai-image-disclosure' ); ?></th>
							<th><?php esc_html_e( 'Variant', 'mme-ai-image-disclosure' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $ids as $id ) : ?>
						<tr>
							<td>
								<span class="ai-label-media-cell">
									<?php echo wp_kses_post( wp_get_attachment_image( $id, array( 60, 60 ), true ) ); ?>
									<span><?php echo esc_html( get_the_title( $id ) ); ?></span>
								</span>
							</td>
							<td><?php echo wp_kses_post( $this->status_html( $id ) ); ?></td>
							<td>
								<select name="overrides[<?php echo esc_attr( $id ); ?>][label_type]" aria-label="<?php esc_attr_e( "Label type override for this image", "mme-ai-image-disclosure" ); ?>">
									<option value=""><?php esc_html_e( '— use the choice above —', 'mme-ai-image-disclosure' ); ?></option>
									<?php foreach ( $types as $type ) : ?>
										<option value="<?php echo esc_attr( $type->slug ); ?>"><?php echo esc_html( $type->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="overrides[<?php echo esc_attr( $id ); ?>][position]" aria-label="<?php esc_attr_e( "Badge position override for this image", "mme-ai-image-disclosure" ); ?>">
									<option value=""><?php esc_html_e( '— use the choice above —', 'mme-ai-image-disclosure' ); ?></option>
									<?php foreach ( $positions as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
							<td>
								<select name="overrides[<?php echo esc_attr( $id ); ?>][variant]" aria-label="<?php esc_attr_e( "Contrast override for this image", "mme-ai-image-disclosure" ); ?>">
									<option value=""><?php esc_html_e( '— use the choice above —', 'mme-ai-image-disclosure' ); ?></option>
									<option value="auto"><?php esc_html_e( 'Auto', 'mme-ai-image-disclosure' ); ?></option>
									<?php foreach ( AI_Label_Settings::variants() as $key => $label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div><!-- .ai-label-overrides -->
			</div><!-- .ai-label-disclosure -->

				<div class="ai-label-card__actions ai-label-card__actions--plain">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Apply labels', 'mme-ai-image-disclosure' ); ?>
					</button>
					<a class="button ai-label-ghost" href="<?php echo esc_url( admin_url( 'upload.php?mode=list' ) ); ?>"><?php esc_html_e( 'Cancel', 'mme-ai-image-disclosure' ); ?></a>
					<span class="ai-label-hint"><?php esc_html_e( 'One background job per image — a failure on one never blocks the rest.', 'mme-ai-image-disclosure' ); ?></span>
				</div>
			</div>
			</div>

			<aside class="ai-label-card">
				<div class="ai-label-card__head">
					<h2><?php esc_html_e( 'Selection', 'mme-ai-image-disclosure' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of images. */
								_n( '%d image queued for labelling.', '%d images queued for labelling.', count( $ids ), 'mme-ai-image-disclosure' ),
								count( $ids )
							)
						);
						?>
					</p>
				</div>
				<div class="ai-label-selection">
					<?php foreach ( $ids as $id ) : ?>
						<?php
						$state    = AI_Label_Labeller::get_attachment_data( $id );
						$labelled = ! empty( $state['status'] ) && 'labelled' === $state['status'];
						$thumb    = wp_get_attachment_image_url( $id, 'thumbnail' );
						?>
						<figure class="ai-label-thumb">
							<?php if ( $thumb ) : ?>
								<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
							<?php endif; ?>
							<?php if ( $labelled ) : ?>
								<span class="ai-label-thumb__flag"><?php esc_html_e( 'Labelled', 'mme-ai-image-disclosure' ); ?></span>
							<?php endif; ?>
							<figcaption class="ai-label-thumb__state">
								<?php echo esc_html( get_the_title( $id ) ? get_the_title( $id ) : '#' . $id ); ?>
							</figcaption>
						</figure>
					<?php endforeach; ?>
				</div>
			</aside>
			</div>
		</form>
		<?php
	}

	/**
	 * Label types and badge variants CRUD.
	 */
	private function render_types_tab() {
		$types    = AI_Label_Badge_Registry::get_types();
		$variants = AI_Label_Settings::variants();
		?>
		<div class="ai-label-toolbar">
			<p class="ai-label-toolbar__text">
				<?php esc_html_e( 'Each label type carries either an uploaded badge PNG or a text label the plugin renders itself. Two contrast variants let auto-contrast pick the readable one per image.', 'mme-ai-image-disclosure' ); ?>
			</p>
			<?php $this->render_add_type_button( __( 'Add label type', 'mme-ai-image-disclosure' ) ); ?>
		</div>

		<?php foreach ( $types as $type ) : ?>
			<?php
			$badges      = AI_Label_Badge_Registry::get_badges( $type->id );
			$mode        = AI_Label_Badge_Registry::mode_of( $type );
			$text_config = AI_Label_Badge_Registry::text_config_of( $type );
			$text_ready  = AI_Label_Text_Badge::is_available();
			?>
			<div class="ai-label-card" data-ai-label-type-card="1" data-ai-label-saved-mode="<?php echo esc_attr( $mode ); ?>">
				<div class="ai-label-card__head ai-label-card__head--row">
					<h2><?php echo esc_html( $type->name ); ?></h2>
				</div>

					<?php $form_id = 'ai-label-type-form-' . (int) $type->id; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ai-label-mode-form" id="<?php echo esc_attr( $form_id ); ?>" data-ai-label-preview-form="1">
						<?php $this->form_fields( 'ai_label_save_type' ); ?>
						<input type="hidden" name="action" value="ai_label_save_type">
						<input type="hidden" name="type_id" value="<?php echo esc_attr( $type->id ); ?>">

						<div class="ai-label-form-grid">
							<div class="ai-label-field">
								<label for="ai-label-name-<?php echo esc_attr( $type->id ); ?>"><?php esc_html_e( 'Label type name', 'mme-ai-image-disclosure' ); ?></label>
								<input type="text" id="ai-label-name-<?php echo esc_attr( $type->id ); ?>" name="name" value="<?php echo esc_attr( $type->name ); ?>" class="regular-text" required>
							</div>
							<div class="ai-label-field">
								<label for="ai-label-alt-<?php echo esc_attr( $type->id ); ?>"><?php esc_html_e( 'Alt-text disclosure suffix', 'mme-ai-image-disclosure' ); ?></label>
								<input type="text" id="ai-label-alt-<?php echo esc_attr( $type->id ); ?>" name="alt_suffix" value="<?php echo esc_attr( $type->alt_suffix ); ?>" class="regular-text">
								<span class="ai-label-hint"><?php esc_html_e( 'Appended to the alt text on apply, removed on restore.', 'mme-ai-image-disclosure' ); ?></span>
							</div>
						</div>

							<div class="ai-label-source">
								<span class="ai-label-source__title"><?php esc_html_e( 'Choose the badge source', 'mme-ai-image-disclosure' ); ?></span>
								<div class="ai-label-segment ai-label-segment--lg">
									<label>
										<input type="radio" name="badge_mode" value="image" <?php checked( 'image', $mode ); ?> data-ai-label-mode="image">
										<?php $this->render_source_icon( 'image' ); ?>
										<span><?php esc_html_e( 'Badge image', 'mme-ai-image-disclosure' ); ?></span>
									</label>
									<label>
										<input type="radio" name="badge_mode" value="text" <?php checked( 'text', $mode ); ?> <?php disabled( is_wp_error( $text_ready ) ); ?> data-ai-label-mode="text">
										<?php $this->render_source_icon( 'text' ); ?>
										<span><?php esc_html_e( 'Generated text', 'mme-ai-image-disclosure' ); ?></span>
									</label>
								</div>
								<?php if ( is_wp_error( $text_ready ) ) : ?>
									<span class="ai-label-hint ai-label-warn"><?php echo esc_html( $text_ready->get_error_message() ); ?></span>
								<?php else : ?>
									<span class="ai-label-hint"><?php esc_html_e( 'Only the settings for the source you pick are shown below.', 'mme-ai-image-disclosure' ); ?></span>
								<?php endif; ?>
							</div>

							<div class="ai-label-panel" data-ai-label-panel="text" <?php echo 'text' === $mode ? '' : 'hidden'; ?>>
								<div class="ai-label-text-row">
									<div class="ai-label-field">
										<label for="ai-label-text-<?php echo esc_attr( $type->id ); ?>"><?php esc_html_e( 'Label text', 'mme-ai-image-disclosure' ); ?></label>
										<input type="text" id="ai-label-text-<?php echo esc_attr( $type->id ); ?>" name="text_config[text]" value="<?php echo esc_attr( $text_config['text'] ); ?>" maxlength="120" data-ai-label-preview-input="1">
									</div>
									<div class="ai-label-field">
										<label for="ai-label-padding-<?php echo esc_attr( $type->id ); ?>"><?php esc_html_e( 'Padding', 'mme-ai-image-disclosure' ); ?></label>
										<input type="number" id="ai-label-padding-<?php echo esc_attr( $type->id ); ?>" name="text_config[padding]" value="<?php echo esc_attr( $text_config['padding'] ); ?>" min="0" max="200" data-ai-label-preview-input="1">
									</div>
									<div class="ai-label-field">
										<label for="ai-label-radius-<?php echo esc_attr( $type->id ); ?>"><?php esc_html_e( 'Corner radius', 'mme-ai-image-disclosure' ); ?></label>
										<input type="number" id="ai-label-radius-<?php echo esc_attr( $type->id ); ?>" name="text_config[radius]" value="<?php echo esc_attr( $text_config['radius'] ); ?>" min="0" max="999" data-ai-label-preview-input="1">
									</div>
									<div class="ai-label-field">
										<label for="ai-label-border-<?php echo esc_attr( $type->id ); ?>"><?php esc_html_e( 'Border width', 'mme-ai-image-disclosure' ); ?></label>
										<input type="number" id="ai-label-border-<?php echo esc_attr( $type->id ); ?>" name="text_config[border_width]" value="<?php echo esc_attr( $text_config['border_width'] ); ?>" min="0" max="20" data-ai-label-preview-input="1">
									</div>
								</div>

								<div class="ai-label-variants">
									<?php
									$variant_labels = array(
										'light' => __( 'On light backgrounds', 'mme-ai-image-disclosure' ),
										'dark'  => __( 'On dark backgrounds', 'mme-ai-image-disclosure' ),
									);
									foreach ( $variant_labels as $key => $variant_label ) :
										$preview = AI_Label_Text_Badge::render( $text_config, $key );
										$url     = is_wp_error( $preview ) ? '' : AI_Label_Text_Badge::url_for( $preview );
										?>
										<div class="ai-label-variant">
											<h5><?php echo esc_html( $variant_label ); ?></h5>
											<div class="ai-label-variant__preview ai-label-variant__preview--<?php echo esc_attr( $key ); ?>" data-ai-label-preview="<?php echo esc_attr( $key ); ?>">
												<?php if ( $url ) : ?>
													<img src="<?php echo esc_url( $url ); ?>" alt="" class="ai-label-badge-preview">
												<?php else : ?>
													<p class="ai-label-warn"><?php echo esc_html( is_wp_error( $preview ) ? $preview->get_error_message() : '' ); ?></p>
												<?php endif; ?>
											</div>

											<div class="ai-label-stack">
												<label>
													<span><?php esc_html_e( 'Text colour', 'mme-ai-image-disclosure' ); ?></span>
													<span class="ai-label-colour-field">
														<input type="color" value="<?php echo esc_attr( $text_config[ $key ]['fg'] ); ?>" data-ai-label-colour-for="text_config[<?php echo esc_attr( $key ); ?>][fg]" aria-hidden="true" tabindex="-1">
														<input type="text" name="text_config[<?php echo esc_attr( $key ); ?>][fg]" value="<?php echo esc_attr( $text_config[ $key ]['fg'] ); ?>" class="ai-label-colour" maxlength="7" data-ai-label-preview-input="1">
													</span>
												</label>
												<label>
													<span><?php esc_html_e( 'Background colour', 'mme-ai-image-disclosure' ); ?></span>
													<span class="ai-label-colour-field">
														<input type="color" value="<?php echo esc_attr( $text_config[ $key ]['bg'] ); ?>" data-ai-label-colour-for="text_config[<?php echo esc_attr( $key ); ?>][bg]" aria-hidden="true" tabindex="-1">
														<input type="text" name="text_config[<?php echo esc_attr( $key ); ?>][bg]" value="<?php echo esc_attr( $text_config[ $key ]['bg'] ); ?>" class="ai-label-colour" maxlength="7" data-ai-label-preview-input="1">
													</span>
												</label>
												<label>
													<span><?php esc_html_e( 'Background opacity', 'mme-ai-image-disclosure' ); ?></span>
													<span class="ai-label-unit">
														<input type="number" name="text_config[<?php echo esc_attr( $key ); ?>][bg_opacity]" value="<?php echo esc_attr( $text_config[ $key ]['bg_opacity'] ); ?>" min="0" max="100" class="small-text" data-ai-label-preview-input="1">
														<span class="ai-label-unit__suffix">%</span>
													</span>
												</label>
												<label>
													<span><?php esc_html_e( 'Border colour', 'mme-ai-image-disclosure' ); ?></span>
													<span class="ai-label-colour-field">
														<input type="color" value="<?php echo esc_attr( $text_config[ $key ]['border'] ); ?>" data-ai-label-colour-for="text_config[<?php echo esc_attr( $key ); ?>][border]" aria-hidden="true" tabindex="-1">
														<input type="text" name="text_config[<?php echo esc_attr( $key ); ?>][border]" value="<?php echo esc_attr( $text_config[ $key ]['border'] ); ?>" class="ai-label-colour" maxlength="7" data-ai-label-preview-input="1">
													</span>
												</label>
												<label>
													<span><?php esc_html_e( 'Border opacity', 'mme-ai-image-disclosure' ); ?></span>
													<span class="ai-label-unit">
														<input type="number" name="text_config[<?php echo esc_attr( $key ); ?>][border_opacity]" value="<?php echo esc_attr( $text_config[ $key ]['border_opacity'] ); ?>" min="0" max="100" class="small-text" data-ai-label-preview-input="1">
														<span class="ai-label-unit__suffix">%</span>
													</span>
												</label>
											</div>
										</div>
									<?php endforeach; ?>
								</div>

								<p class="ai-label-preview-note">
									<?php esc_html_e( 'Previews update as you type and show exactly what will be baked into the image. Saving new wording never rewrites images already labelled — they are flagged for re-labelling instead.', 'mme-ai-image-disclosure' ); ?>
								</p>
							</div>

					</form>
				<div class="ai-label-panel" data-ai-label-panel="image" <?php echo 'image' === $mode ? '' : 'hidden'; ?>>
					<h3 class="ai-label-section-title"><?php esc_html_e( 'Badge images', 'mme-ai-image-disclosure' ); ?></h3>
					<div class="ai-label-variants">
						<?php
						$this->render_badge_variant( $type, 'light', $variants['light'], $badges );
						$this->render_badge_variant( $type, 'dark', $variants['dark'], $badges );
						?>
					</div>

					<p class="ai-label-hint">
						<?php esc_html_e( 'PNG with transparency, any size — it is scaled to the badge width set under Settings. Auto-contrast picks between these two per image.', 'mme-ai-image-disclosure' ); ?>
					</p>
				</div><!-- [data-ai-label-panel="image"] -->

				<div class="ai-label-card__actions ai-label-card__actions--plain">
					<?php // The submit lives outside the form because the badge slots above carry their own forms, which cannot nest. ?>
					<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="button button-primary">
						<?php esc_html_e( 'Save label type', 'mme-ai-image-disclosure' ); ?>
					</button>
					<span class="ai-label-unsaved" data-ai-label-unsaved hidden>
						<?php esc_html_e( 'Badge source changed — save to switch this type over.', 'mme-ai-image-disclosure' ); ?>
					</span>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ai-label-delete-type">
						<?php $this->form_fields( 'ai_label_delete_type' ); ?>
						<input type="hidden" name="action" value="ai_label_delete_type">
						<input type="hidden" name="type_id" value="<?php echo esc_attr( $type->id ); ?>">
						<button type="submit" class="button-link delete ai-label-confirm" data-confirm="<?php esc_attr_e( 'Delete this label type? Types still applied to images cannot be deleted.', 'mme-ai-image-disclosure' ); ?>">
							<?php esc_html_e( 'Delete label type', 'mme-ai-image-disclosure' ); ?>
						</button>
					</form>
				</div>
			</div>
		<?php endforeach; ?>

		<?php if ( ! $types ) : ?>
			<div class="ai-label-empty">
				<span class="ai-label-empty__icon"><span class="dashicons dashicons-tag"></span></span>
				<h2><?php esc_html_e( 'No label types yet', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'A label type is the disclosure you apply to an image — its wording or badge, and the alt-text suffix that goes with it.', 'mme-ai-image-disclosure' ); ?></p>
				<?php $this->render_add_type_button( __( 'Add your first label type', 'mme-ai-image-disclosure' ) ); ?>
			</div>
		<?php endif; ?>

		<?php $text_ready = AI_Label_Text_Badge::is_available(); ?>
		<dialog class="ai-label-modal" id="ai-label-add-type" aria-labelledby="ai-label-add-type-title">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ai-label-mode-scope="1">
				<?php $this->form_fields( 'ai_label_save_type' ); ?>
				<input type="hidden" name="action" value="ai_label_save_type">

				<div class="ai-label-modal__head">
					<h2 id="ai-label-add-type-title"><?php esc_html_e( 'Add label type', 'mme-ai-image-disclosure' ); ?></h2>
					<button type="button" class="ai-label-modal__close" data-ai-label-modal-close aria-label="<?php esc_attr_e( 'Close', 'mme-ai-image-disclosure' ); ?>">
						<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
					</button>
				</div>

				<div class="ai-label-modal__body">
					<div class="ai-label-field">
						<label for="ai-label-new-name"><?php esc_html_e( 'Label type name', 'mme-ai-image-disclosure' ); ?></label>
						<input type="text" id="ai-label-new-name" name="name" class="regular-text" required autocomplete="off">
						<span class="ai-label-hint"><?php esc_html_e( 'Shown to you when picking a label — e.g. “AI-generated” or “AI-edited”.', 'mme-ai-image-disclosure' ); ?></span>
					</div>

					<div class="ai-label-field">
						<label for="ai-label-new-suffix"><?php esc_html_e( 'Alt-text disclosure suffix', 'mme-ai-image-disclosure' ); ?></label>
						<input type="text" id="ai-label-new-suffix" name="alt_suffix" class="regular-text" placeholder="<?php esc_attr_e( '(AI-generated image)', 'mme-ai-image-disclosure' ); ?>" autocomplete="off">
						<span class="ai-label-hint"><?php esc_html_e( 'Appended to each image’s alt text on apply, and removed again on restore.', 'mme-ai-image-disclosure' ); ?></span>
					</div>

					<div class="ai-label-field">
						<span class="ai-label-label"><?php esc_html_e( 'Badge source', 'mme-ai-image-disclosure' ); ?></span>
						<div class="ai-label-segment">
							<label>
								<input type="radio" name="badge_mode" value="image" checked data-ai-label-mode="image">
								<?php esc_html_e( 'Badge image', 'mme-ai-image-disclosure' ); ?>
							</label>
							<label>
								<input type="radio" name="badge_mode" value="text" <?php disabled( is_wp_error( $text_ready ) ); ?> data-ai-label-mode="text">
								<?php esc_html_e( 'Generated text', 'mme-ai-image-disclosure' ); ?>
							</label>
						</div>
					</div>

					<div class="ai-label-panel" data-ai-label-panel="image">
						<p class="ai-label-hint">
							<?php esc_html_e( 'You will be able to upload the light- and dark-background PNGs right after the type is created.', 'mme-ai-image-disclosure' ); ?>
						</p>
					</div>

					<div class="ai-label-panel" data-ai-label-panel="text" hidden>
						<div class="ai-label-field">
							<label for="ai-label-new-text"><?php esc_html_e( 'Label text', 'mme-ai-image-disclosure' ); ?></label>
							<input type="text" id="ai-label-new-text" name="text_config[text]" class="regular-text" maxlength="120" placeholder="<?php esc_attr_e( 'AI-generated', 'mme-ai-image-disclosure' ); ?>" autocomplete="off">
							<span class="ai-label-hint"><?php esc_html_e( 'Rendered by the plugin — no image to design. Colours and shape can be tuned after saving.', 'mme-ai-image-disclosure' ); ?></span>
						</div>
					</div>

					<?php if ( is_wp_error( $text_ready ) ) : ?>
						<p class="ai-label-hint ai-label-warn"><?php echo esc_html( $text_ready->get_error_message() ); ?></p>
					<?php endif; ?>

					<details class="ai-label-details">
						<summary><?php esc_html_e( 'Advanced', 'mme-ai-image-disclosure' ); ?></summary>
						<div class="ai-label-field">
							<label for="ai-label-new-slug"><?php esc_html_e( 'Slug', 'mme-ai-image-disclosure' ); ?></label>
							<input type="text" id="ai-label-new-slug" name="slug" class="regular-text" autocomplete="off">
							<span class="ai-label-hint"><?php esc_html_e( 'Optional. Generated from the label type name if left empty, and fixed afterwards so stored labels never dangle.', 'mme-ai-image-disclosure' ); ?></span>
						</div>
					</details>
				</div>

				<div class="ai-label-modal__foot">
					<button type="button" class="button ai-label-ghost" data-ai-label-modal-close><?php esc_html_e( 'Cancel', 'mme-ai-image-disclosure' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add label type', 'mme-ai-image-disclosure' ); ?></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	/**
	 * The primary call to action that opens the "add label type" dialog.
	 *
	 * The plus is an inline SVG rather than a dashicon so it always renders, whatever the
	 * icon font is doing on the host.
	 *
	 * @param string $label Button label.
	 */
	private function render_add_type_button( $label ) {
		?>
		<button type="button" class="ai-label-cta" data-ai-label-modal-open="ai-label-add-type">
			<span class="ai-label-cta__icon" aria-hidden="true">
				<svg width="12" height="12" viewBox="0 0 12 12" focusable="false" aria-hidden="true">
					<path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none" />
				</svg>
			</span>
			<span class="ai-label-cta__text"><?php echo esc_html( $label ); ?></span>
		</button>
		<?php
	}

	/**
	 * The plugin mark: a picture frame with a disclosure seal stamped into its corner —
	 * what the plugin actually does, drawn as inline SVG so it needs no image asset and
	 * scales cleanly on any screen.
	 */
	private function render_brand_mark() {
		?>
		<span class="ai-label-mark" aria-hidden="true">
			<svg width="24" height="24" viewBox="0 0 24 24" fill="none" focusable="false" aria-hidden="true">
				<rect x="2.6" y="4" width="18.8" height="16" rx="3.2" stroke="currentColor" stroke-width="1.7" opacity=".95" />
				<circle cx="8.4" cy="9.6" r="1.7" fill="currentColor" />
				<path d="M3.6 17.4l4.6-4.6 3.3 3.3 2.4-2.4 4.2 4.2" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" opacity=".95" />
				<circle cx="17.6" cy="16.4" r="5" fill="currentColor" />
				<path d="M15.5 16.5l1.5 1.5 2.7-3" stroke="var(--ail-accent-dark, #135e96)" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" fill="none" />
			</svg>
		</span>
		<?php
	}

	/**
	 * Icon for one tab. Inline SVG for the same reason as the brand mark.
	 *
	 * @param string $slug Tab slug.
	 */
	private function render_tab_icon( $slug ) {
		$paths = array(
			// A stamp being applied.
			'apply'    => '<path d="M3 13.5h10M8 3v7.5M8 3l3 2.4M8 3L5 5.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
			// Stacked swatches.
			'types'    => '<rect x="2.5" y="2.5" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.5"/><rect x="6.5" y="6.5" width="7" height="7" rx="1.6" stroke="currentColor" stroke-width="1.5"/>',
			// Sliders.
			'settings' => '<path d="M3 4.5h10M3 11.5h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="6" cy="4.5" r="1.8" fill="currentColor"/><circle cx="10.5" cy="11.5" r="1.8" fill="currentColor"/>',
			// A log line with a tick.
			'log'      => '<path d="M3 4h7M3 8h5M3 12h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M9.5 11.2l1.5 1.5 2.5-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
			// Wrench.
			'tools'    => '<path d="M9.8 3.2a3.4 3.4 0 00-4.2 4.3L3 10.1l2.9 2.9 2.6-2.6a3.4 3.4 0 004.3-4.2l-2 2-2.1-2.1 2.1-1.9z" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>',
		);

		if ( ! isset( $paths[ $slug ] ) ) {
			return;
		}
		?>
		<span class="ai-label-tab__icon" aria-hidden="true">
			<svg width="16" height="16" viewBox="0 0 16 16" fill="none" focusable="false" aria-hidden="true">
				<?php
				echo wp_kses(
					$paths[ $slug ],
					array(
						'path'   => array(
							'd'                => array(),
							'stroke'           => array(),
							'stroke-width'     => array(),
							'stroke-linecap'   => array(),
							'stroke-linejoin'  => array(),
							'fill'             => array(),
						),
						'rect'   => array(
							'x'            => array(),
							'y'            => array(),
							'width'        => array(),
							'height'       => array(),
							'rx'           => array(),
							'stroke'       => array(),
							'stroke-width' => array(),
							'fill'         => array(),
						),
						'circle' => array(
							'cx'           => array(),
							'cy'           => array(),
							'r'            => array(),
							'fill'         => array(),
							'stroke'       => array(),
							'stroke-width' => array(),
						),
					)
				);
				?>
			</svg>
		</span>
		<?php
	}

	/**
	 * Icon for a badge source option. Inline SVG so it never depends on an icon font.
	 *
	 * @param string $source image|text.
	 */
	private function render_source_icon( $source ) {
		?>
		<span class="ai-label-segment__icon" aria-hidden="true">
			<?php if ( 'text' === $source ) : ?>
				<svg width="18" height="18" viewBox="0 0 18 18" fill="none" focusable="false" aria-hidden="true">
					<rect x="1.5" y="4" width="15" height="10" rx="5" stroke="currentColor" stroke-width="1.5" />
					<path d="M6 8.5h6M6 11h3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
				</svg>
			<?php else : ?>
				<svg width="18" height="18" viewBox="0 0 18 18" fill="none" focusable="false" aria-hidden="true">
					<rect x="2" y="3" width="14" height="12" rx="2.5" stroke="currentColor" stroke-width="1.5" />
					<circle cx="6.5" cy="7" r="1.4" fill="currentColor" />
					<path d="M3 13l3.6-3.6 3 3 2.2-2.2L15 12.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
				</svg>
			<?php endif; ?>
		</span>
		<?php
	}

	/**
	 * One badge-image slot: preview, picker and remove control.
	 *
	 * @param object $type   Label type row.
	 * @param string $key    Variant key.
	 * @param string $label  Variant label.
	 * @param array  $badges Existing badge rows keyed by variant.
	 */
	private function render_badge_variant( $type, $key, $label, array $badges ) {
		$badge    = isset( $badges[ $key ] ) ? $badges[ $key ] : null;
		$badge_id = $badge ? (int) $badge->attachment_id : 0;
		$dark     = ( false !== strpos( $key, 'dark' ) );
		$bundled  = $badges ? '' : AI_Label_Badge_Registry::bundled_badge_url( $type->slug, $key );
		?>
		<div class="ai-label-variant">
			<h5><?php echo esc_html( $label ); ?></h5>
			<div class="ai-label-variant__preview <?php echo $dark ? 'ai-label-variant__preview--dark' : ''; ?>">
				<?php if ( $badge_id ) : ?>
					<?php echo wp_kses_post( wp_get_attachment_image( $badge_id, array( 160, 160 ), false, array( 'class' => 'ai-label-badge-preview' ) ) ); ?>
				<?php elseif ( $bundled ) : ?>
					<img src="<?php echo esc_url( $bundled ); ?>" alt="" class="ai-label-badge-preview">
				<?php else : ?>
					<p class="ai-label-meta"><?php esc_html_e( 'No badge yet', 'mme-ai-image-disclosure' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( $bundled ) : ?>
				<p class="ai-label-meta"><?php esc_html_e( 'Built-in default — upload a PNG to replace it.', 'mme-ai-image-disclosure' ); ?></p>
			<?php endif; ?>

			<?php if ( $badge_id && ! AI_Label_Badge_Registry::png_has_alpha( (string) get_attached_file( $badge_id ) ) ) : ?>
				<p class="ai-label-warn"><?php esc_html_e( 'No transparency channel — this badge will show as a solid rectangle.', 'mme-ai-image-disclosure' ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ai-label-badge-form">
				<?php $this->form_fields( 'ai_label_save_badge' ); ?>
				<input type="hidden" name="action" value="ai_label_save_badge">
				<input type="hidden" name="type_id" value="<?php echo esc_attr( $type->id ); ?>">
				<input type="hidden" name="variant" value="<?php echo esc_attr( $key ); ?>">
				<input type="hidden" name="attachment_id" value="<?php echo esc_attr( $badge_id ); ?>" class="ai-label-badge-input">
				<button type="button" class="button ai-label-pick-badge">
					<?php echo $badge_id ? esc_html__( 'Replace', 'mme-ai-image-disclosure' ) : esc_html__( 'Choose PNG', 'mme-ai-image-disclosure' ); ?>
				</button>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'mme-ai-image-disclosure' ); ?></button>
			</form>

			<?php if ( $badge ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php $this->form_fields( 'ai_label_delete_badge' ); ?>
					<input type="hidden" name="action" value="ai_label_delete_badge">
					<input type="hidden" name="badge_id" value="<?php echo esc_attr( $badge->id ); ?>">
					<button type="submit" class="button-link delete"><?php esc_html_e( 'Remove', 'mme-ai-image-disclosure' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Global settings form.
	 */
	private function render_settings_tab() {
		$settings = AI_Label_Settings::all();
		$backend  = AI_Label_Image_Processor::create();
		$cdn_error = get_option( 'ai_label_cdn_error' );
		?>
		<?php if ( is_wp_error( $backend ) ) : ?>
			<div class="ai-label-inline-notice ai-label-inline-notice--error">
				<p><?php echo esc_html( $backend->get_error_message() ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->form_fields( 'ai_label_save_settings' ); ?>
			<input type="hidden" name="action" value="ai_label_save_settings">

			<div class="ai-label-card">
			<div class="ai-label-card__head">
				<h2><?php esc_html_e( 'Badge geometry', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'How large the badge is and how far it sits from the edge, relative to each image — so the same settings work on a 600px and a 4000px photo.', 'mme-ai-image-disclosure' ); ?></p>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="badge_scale_pct"><?php esc_html_e( 'Badge scale', 'mme-ai-image-disclosure' ); ?></label></th>
					<td>
						<input type="number" min="1" max="100" id="badge_scale_pct" name="badge_scale_pct" value="<?php echo esc_attr( $settings['badge_scale_pct'] ); ?>" class="small-text"> %
						<p class="description"><?php esc_html_e( 'Badge width as a percentage of the image’s shorter side.', 'mme-ai-image-disclosure' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="badge_min_px"><?php esc_html_e( 'Minimum badge width', 'mme-ai-image-disclosure' ); ?></label></th>
					<td>
						<input type="number" min="8" max="2000" id="badge_min_px" name="badge_min_px" value="<?php echo esc_attr( $settings['badge_min_px'] ); ?>" class="small-text"> px
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: legibility floor in px. */
									__( 'Badges computed below the %dpx legibility floor are refused rather than rendered illegibly.', 'mme-ai-image-disclosure' ),
									AI_Label_Settings::legibility_floor()
								)
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="badge_max_px"><?php esc_html_e( 'Maximum badge width', 'mme-ai-image-disclosure' ); ?></label></th>
					<td><input type="number" min="8" max="4000" id="badge_max_px" name="badge_max_px" value="<?php echo esc_attr( $settings['badge_max_px'] ); ?>" class="small-text"> px</td>
				</tr>
				<tr>
					<th scope="row"><label for="badge_padding_pct"><?php esc_html_e( 'Edge padding', 'mme-ai-image-disclosure' ); ?></label></th>
					<td><input type="number" min="0" max="45" id="badge_padding_pct" name="badge_padding_pct" value="<?php echo esc_attr( $settings['badge_padding_pct'] ); ?>" class="small-text"> %</td>
				</tr>
				<tr>
					<th scope="row"><label for="brightness_threshold"><?php esc_html_e( 'Auto-contrast threshold', 'mme-ai-image-disclosure' ); ?></label></th>
					<td>
						<input type="number" min="0" max="255" id="brightness_threshold" name="brightness_threshold" value="<?php echo esc_attr( $settings['brightness_threshold'] ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Mean luminance (0–255) above which the light-background badge is used.', 'mme-ai-image-disclosure' ); ?></p>
					</td>
				</tr>
			</table>
			</div>

			<div class="ai-label-card">
			<div class="ai-label-card__head">
				<h2><?php esc_html_e( 'Text labels', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'Applies to label types whose badge source is generated text rather than an uploaded PNG.', 'mme-ai-image-disclosure' ); ?></p>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="text_scale_pct"><?php esc_html_e( 'Text label scale', 'mme-ai-image-disclosure' ); ?></label></th>
					<td>
						<span class="ai-label-unit">
							<input type="number" min="5" max="100" id="text_scale_pct" name="text_scale_pct" value="<?php echo esc_attr( $settings['text_scale_pct'] ); ?>" class="small-text">
							<span class="ai-label-unit__suffix">%</span>
						</span>
						<p class="description"><?php esc_html_e( 'Used instead of the badge scale for label types that render text. Text strips are wide and short, so they need more width than a square badge to stay readable.', 'mme-ai-image-disclosure' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="text_min_px"><?php esc_html_e( 'Minimum text label width', 'mme-ai-image-disclosure' ); ?></label></th>
					<td>
						<span class="ai-label-unit">
							<input type="number" min="24" max="4000" id="text_min_px" name="text_min_px" value="<?php echo esc_attr( $settings['text_min_px'] ); ?>" class="small-text">
							<span class="ai-label-unit__suffix">px</span>
						</span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="font_path"><?php esc_html_e( 'Font file', 'mme-ai-image-disclosure' ); ?></label></th>
					<td>
						<input type="text" id="font_path" name="font_path" value="<?php echo esc_attr( $settings['font_path'] ); ?>" class="large-text code" placeholder="/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf">
						<p class="description">
							<?php esc_html_e( 'Server path to a .ttf, .otf or .ttc file. Leave empty to autodetect a system font.', 'mme-ai-image-disclosure' ); ?>
							<?php
							$font = AI_Label_Text_Badge::resolve_font();
							if ( is_wp_error( $font ) ) {
								echo '<br><span class="ai-label-warn">' . esc_html( $font->get_error_message() ) . '</span>';
							} else {
								echo '<br>' . esc_html(
									sprintf(
										/* translators: %s: font file path. */
										__( 'Currently using: %s', 'mme-ai-image-disclosure' ),
										$font
									)
								);
							}
							?>
						</p>
					</td>
				</tr>
			</table>
			</div>

			<div class="ai-label-card">
			<div class="ai-label-card__head">
				<h2><?php esc_html_e( 'Image sizes and output', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'Which registered sizes keep the badge, and how the rewritten files are encoded.', 'mme-ai-image-disclosure' ); ?></p>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="min_size_width"><?php esc_html_e( 'Badge sizes down to', 'mme-ai-image-disclosure' ); ?></label></th>
					<td>
						<input type="number" min="0" max="10000" id="min_size_width" name="min_size_width" value="<?php echo esc_attr( $settings['min_size_width'] ); ?>" class="small-text"> px
						<p class="description"><?php esc_html_e( 'Registered sizes at least this wide always keep the badge. Narrower sizes keep it only while the scaled badge stays above the legibility floor; otherwise they are rebuilt from the unlabelled original.', 'mme-ai-image-disclosure' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="image_quality"><?php esc_html_e( 'JPEG/WebP quality', 'mme-ai-image-disclosure' ); ?></label></th>
					<td><input type="number" min="40" max="100" id="image_quality" name="image_quality" value="<?php echo esc_attr( $settings['image_quality'] ); ?>" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'WebP variant', 'mme-ai-image-disclosure' ); ?></th>
					<td>
						<label class="ai-label-switch">
							<input type="checkbox" name="serve_webp" value="1" <?php checked( ! empty( $settings['serve_webp'] ) ); ?>>
							<span class="ai-label-switch__track" aria-hidden="true"></span>
							<span class="ai-label-switch__text">
								<strong><?php esc_html_e( 'Write a labelled WebP sidecar', 'mme-ai-image-disclosure' ); ?></strong>
								<span><?php esc_html_e( 'Served through a <picture> element on the front end. Off by default — the primary file always keeps its own extension and MIME type.', 'mme-ai-image-disclosure' ); ?></span>
							</span>
						</label>
					</td>
				</tr>
			</table>
			</div>

			<div class="ai-label-card">
			<div class="ai-label-card__head">
				<h2><?php esc_html_e( 'CDN purging', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'Optional. WP Rocket, W3 Total Cache, LiteSpeed Cache and SiteGround Optimizer are purged automatically when active — no configuration needed.', 'mme-ai-image-disclosure' ); ?></p>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cloudflare_zone"><?php esc_html_e( 'Cloudflare zone ID', 'mme-ai-image-disclosure' ); ?></label></th>
					<td><input type="text" id="cloudflare_zone" name="cloudflare_zone" value="<?php echo esc_attr( $settings['cloudflare_zone'] ); ?>" class="regular-text" autocomplete="off"></td>
				</tr>
				<tr>
					<th scope="row"><label for="cloudflare_token"><?php esc_html_e( 'Cloudflare API token', 'mme-ai-image-disclosure' ); ?></label></th>
					<td>
						<input type="password" id="cloudflare_token" name="cloudflare_token" value="" class="regular-text" autocomplete="off"
							placeholder="<?php echo esc_attr( AI_Label_Settings::has_cloudflare() ? __( 'Saved — leave empty to keep it', 'mme-ai-image-disclosure' ) : __( 'Not set', 'mme-ai-image-disclosure' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Stored encrypted and never shown again. Type "delete" to remove it. Needs the "Cache Purge" permission only.', 'mme-ai-image-disclosure' ); ?></p>
						<?php if ( is_array( $cdn_error ) && ! empty( $cdn_error['message'] ) ) : ?>
							<p class="ai-label-warn">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: error message. */
										__( 'Last Cloudflare purge failed: %s', 'mme-ai-image-disclosure' ),
										$cdn_error['message']
									)
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			</div>

			<div class="ai-label-card">
			<div class="ai-label-card__head">
				<h2><?php esc_html_e( 'Backups and data', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'Backup files are never deleted automatically — not on restore, not on deactivation, not on uninstall.', 'mme-ai-image-disclosure' ); ?></p>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="backup_warn_gb"><?php esc_html_e( 'Warn above', 'mme-ai-image-disclosure' ); ?></label></th>
					<td><input type="number" min="1" max="1000" id="backup_warn_gb" name="backup_warn_gb" value="<?php echo esc_attr( $settings['backup_warn_gb'] ); ?>" class="small-text"> GB</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Uninstall', 'mme-ai-image-disclosure' ); ?></th>
					<td>
						<label class="ai-label-switch">
							<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>>
							<span class="ai-label-switch__track" aria-hidden="true"></span>
							<span class="ai-label-switch__text">
								<strong><?php esc_html_e( 'Remove plugin data on uninstall', 'mme-ai-image-disclosure' ); ?></strong>
								<span><?php esc_html_e( 'Deletes label types, the badge registry, settings and the job log when the plugin is deleted. Backup files are kept regardless.', 'mme-ai-image-disclosure' ); ?></span>
							</span>
						</label>
					</td>
				</tr>
			</table>
			</div>

			<div class="ai-label-card">
				<div class="ai-label-card__actions ai-label-card__actions--plain" style="margin:-22px -24px;">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'mme-ai-image-disclosure' ); ?></button>
					<span class="ai-label-hint"><?php esc_html_e( 'Changes apply to the next labelling job — images already labelled are untouched.', 'mme-ai-image-disclosure' ); ?></span>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Job log screen.
	 */
	private function render_log_tab() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation parameters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = 25;
		$log      = $this->jobs->get_log(
			array(
				'status'   => $status,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		?>
		<ul class="ai-label-filters">
			<?php
			$filters = array(
				''        => __( 'All', 'mme-ai-image-disclosure' ),
				'failed'  => __( 'Failed', 'mme-ai-image-disclosure' ),
				'success' => __( 'Succeeded', 'mme-ai-image-disclosure' ),
				'queued'  => __( 'Queued', 'mme-ai-image-disclosure' ),
			);
			foreach ( $filters as $key => $label ) :
				?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'tab' => 'log', 'status' => $key ), admin_url( 'upload.php' ) ) ); ?>"
						class="<?php echo $status === $key ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

		<table class="widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'mme-ai-image-disclosure' ); ?></th>
					<th><?php esc_html_e( 'Image', 'mme-ai-image-disclosure' ); ?></th>
					<th><?php esc_html_e( 'Action', 'mme-ai-image-disclosure' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mme-ai-image-disclosure' ); ?></th>
					<th><?php esc_html_e( 'Failed step', 'mme-ai-image-disclosure' ); ?></th>
					<th><?php esc_html_e( 'Detail', 'mme-ai-image-disclosure' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $log['rows'] ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No jobs logged yet.', 'mme-ai-image-disclosure' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $log['rows'] as $row ) : ?>
				<tr>
					<td><span class="ai-label-meta"><?php echo esc_html( $row->completed_at ? $row->completed_at : $row->created_at ); ?></span></td>
					<td>
						<span class="ai-label-media-cell">
							<?php $thumb = wp_get_attachment_image_url( (int) $row->attachment_id, 'thumbnail' ); ?>
							<?php if ( $thumb ) : ?>
								<img src="<?php echo esc_url( $thumb ); ?>" alt="" loading="lazy">
							<?php endif; ?>
							<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row->attachment_id ) ); ?>">
								<?php echo esc_html( get_the_title( (int) $row->attachment_id ) ? get_the_title( (int) $row->attachment_id ) : '#' . (int) $row->attachment_id ); ?>
							</a>
						</span>
					</td>
					<td><?php echo esc_html( $row->action ); ?></td>
					<td>
						<span class="ai-label-pill ai-label-pill--<?php echo esc_attr( 'failed' === $row->status ? 'fail' : ( 'success' === $row->status ? 'on' : 'none' ) ); ?>">
							<?php echo esc_html( $row->status ); ?>
						</span>
					</td>
					<td><?php echo esc_html( (string) $row->failed_step ); ?></td>
					<td><?php echo esc_html( (string) $row->error_message ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$pages = (int) ceil( $log['total'] / $per_page );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					)
				)
			);
			echo '</div></div>';
		}
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ai-label-actions-row">
			<?php $this->form_fields( 'ai_label_clear_log' ); ?>
			<input type="hidden" name="action" value="ai_label_clear_log">
			<button type="submit" class="button ai-label-ghost ai-label-confirm" data-confirm="<?php esc_attr_e( 'Delete all finished job log entries? Images and backups are not affected.', 'mme-ai-image-disclosure' ); ?>">
				<?php esc_html_e( 'Clear finished log entries', 'mme-ai-image-disclosure' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Tools: backup inventory and the manual purge action.
	 */
	private function render_tools_tab() {
		$usage = AI_Label_Backup::usage();
		$stale = AI_Label_Backup::stale_attachment_ids();
		$freed = AI_Label_Backup::size_of( $stale );
		?>
		<?php
		$threshold = (int) AI_Label_Settings::get( 'backup_warn_gb' ) * GB_IN_BYTES;
		$over      = $usage['bytes'] > $threshold;
		?>
		<div class="ai-label-stats">
			<div class="ai-label-stat <?php echo $over ? 'ai-label-stat--warn' : ''; ?>">
				<span class="ai-label-stat__value"><?php echo esc_html( size_format( $usage['bytes'], 1 ) ); ?></span>
				<span class="ai-label-stat__label">
					<?php
					echo esc_html(
						$over
							? sprintf(
								/* translators: %s: threshold size. */
								__( 'Backups — above your %s threshold', 'mme-ai-image-disclosure' ),
								size_format( $threshold, 0 )
							)
							: __( 'Backup storage used', 'mme-ai-image-disclosure' )
					);
					?>
				</span>
			</div>
			<div class="ai-label-stat">
				<span class="ai-label-stat__value"><?php echo esc_html( number_format_i18n( $usage['attachments'] ) ); ?></span>
				<span class="ai-label-stat__label"><?php esc_html_e( 'Images with a backup', 'mme-ai-image-disclosure' ); ?></span>
			</div>
			<div class="ai-label-stat">
				<span class="ai-label-stat__value"><?php echo esc_html( number_format_i18n( count( $stale ) ) ); ?></span>
				<span class="ai-label-stat__label"><?php esc_html_e( 'Backups that can be freed', 'mme-ai-image-disclosure' ); ?></span>
			</div>
			<div class="ai-label-stat">
				<span class="ai-label-stat__value"><?php echo esc_html( size_format( $freed, 1 ) ); ?></span>
				<span class="ai-label-stat__label"><?php esc_html_e( 'Space they would free', 'mme-ai-image-disclosure' ); ?></span>
			</div>
		</div>

		<div class="ai-label-card">
		<div class="ai-label-card__head">
			<h2><?php esc_html_e( 'Clear old backups', 'mme-ai-image-disclosure' ); ?></h2>
			<p>
				<?php esc_html_e( 'Manual only — nothing here ever runs on a schedule. Backups of images that are still labelled are refused, because they are the only copy of the original.', 'mme-ai-image-disclosure' ); ?>
				<br><span class="ai-label-code"><?php echo esc_html( AI_Label_Backup::relative( AI_Label_Backup::root() ) ); ?></span>
			</p>
		</div>
		<?php if ( ! $stale ) : ?>
			<p><?php esc_html_e( 'Every backup on disk belongs to an image that is currently labelled, so nothing can be cleared. Restore an original first if you want to free its backup.', 'mme-ai-image-disclosure' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of images, 2: size to be freed. */
						_n(
							'%1$d image is no longer labelled; deleting its backup would free %2$s.',
							'%1$d images are no longer labelled; deleting their backups would free %2$s.',
							count( $stale ),
							'mme-ai-image-disclosure'
						),
						count( $stale ),
						size_format( $freed, 1 )
					)
				);
				?>
			</p>
			<ul class="ai-label-stale-list">
				<?php foreach ( array_slice( $stale, 0, 50 ) as $id ) : ?>
					<li>
						<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>">
							<?php echo esc_html( get_the_title( $id ) ? get_the_title( $id ) : '#' . $id ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $stale ) > 50 ) : ?>
				<p class="ai-label-meta">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of further images. */
							__( '…and %d more.', 'mme-ai-image-disclosure' ),
							count( $stale ) - 50
						)
					);
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->form_fields( 'ai_label_clear_backups' ); ?>
				<input type="hidden" name="action" value="ai_label_clear_backups">
				<button type="submit" class="button button-secondary ai-label-confirm"
					data-confirm="<?php echo esc_attr( sprintf( /* translators: %s: size. */ __( 'Permanently delete these backups and free %s? Labelled images are never affected.', 'mme-ai-image-disclosure' ), size_format( $freed, 1 ) ) ); ?>">
					<?php esc_html_e( 'Delete unused backups', 'mme-ai-image-disclosure' ); ?>
				</button>
			</form>
		<?php endif; ?>

		</div>

		<div class="ai-label-card">
			<div class="ai-label-card__head">
				<h2><?php esc_html_e( 'Rendered text badges', 'mme-ai-image-disclosure' ); ?></h2>
				<p><?php esc_html_e( 'Generated text labels are cached as PNGs so they are not re-rendered for every image. Clearing the cache is safe at any time — files are rebuilt on demand and labelled images are unaffected.', 'mme-ai-image-disclosure' ); ?></p>
			</div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $this->form_fields( 'ai_label_clear_text_cache' ); ?>
				<input type="hidden" name="action" value="ai_label_clear_text_cache">
				<button type="submit" class="button ai-label-ghost"><?php esc_html_e( 'Clear rendered text badge cache', 'mme-ai-image-disclosure' ); ?></button>
			</form>
		</div>

		<div class="ai-label-card">
			<div class="ai-label-card__head">
				<h2><?php esc_html_e( 'Optimisation plugins', 'mme-ai-image-disclosure' ); ?></h2>
			</div>
			<p class="ai-label-hint">
				<?php esc_html_e( 'If an image optimiser (Smush, ShortPixel, EWWW, …) regenerates thumbnails after labelling, re-run its bulk optimise once labelling has finished. Images whose sizes were rebuilt elsewhere are flagged in the Media Library so you can re-apply the label.', 'mme-ai-image-disclosure' ); ?>
			</p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------------ */

	/**
	 * Prints the nonce and referer fields for one of our forms.
	 *
	 * wp_nonce_field() gives its inputs an id matching their name, which produces duplicate
	 * element ids on screens that carry several forms — so the fields are written by hand
	 * here, without ids. check_admin_referer() still validates them normally.
	 *
	 * @param string $action Nonce action.
	 */
	private function form_fields( $action ) {
		printf(
			'<input type="hidden" name="_wpnonce" value="%1$s"><input type="hidden" name="_wp_http_referer" value="%2$s">',
			esc_attr( wp_create_nonce( $action ) ),
			esc_attr( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' )
		);
	}

	/**
	 * Shared guard for every admin-post handler.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private function guard( $nonce_action ) {
		if ( ! AI_Label_Plugin::user_can() ) {
			wp_die(
				esc_html__( 'You are not allowed to manage AI content labels.', 'mme-ai-image-disclosure' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Sends the user back to a tab.
	 *
	 * @param string $tab   Tab slug.
	 * @param array  $extra Extra query args.
	 */
	private function redirect( $tab, array $extra = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$extra
			),
			admin_url( 'upload.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Saves the settings form.
	 */
	public function handle_save_settings() {
		$this->guard( 'ai_label_save_settings' );

		$fields = array(
			'badge_scale_pct',
			'badge_min_px',
			'badge_max_px',
			'badge_padding_pct',
			'text_scale_pct',
			'text_min_px',
			'font_path',
			'min_size_width',
			'brightness_threshold',
			'image_quality',
			'backup_warn_gb',
			'serve_webp',
			'delete_data_on_uninstall',
			'cloudflare_zone',
			'cloudflare_token',
		);

		$input = array();
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$input[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			}
		}
		$input['serve_webp']               = isset( $_POST['serve_webp'] ) ? 1 : 0;
		$input['delete_data_on_uninstall'] = isset( $_POST['delete_data_on_uninstall'] ) ? 1 : 0;

		AI_Label_Settings::save( $input );
		$this->add_notice( 'success', __( 'Settings saved.', 'mme-ai-image-disclosure' ) );
		$this->redirect( 'settings' );
	}

	/**
	 * Creates or updates a label type.
	 */
	public function handle_save_type() {
		$this->guard( 'ai_label_save_type' );

		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;

		// The label type card posts from several small forms, so only the submitted fields
		// are updated — an omitted field must not be blanked.
		$data = array();
		if ( isset( $_POST['name'] ) ) {
			$data['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ) );
		}
		if ( isset( $_POST['slug'] ) ) {
			$data['slug'] = sanitize_title( wp_unslash( $_POST['slug'] ) );
		}
		if ( isset( $_POST['alt_suffix'] ) ) {
			$data['alt_suffix'] = sanitize_text_field( wp_unslash( $_POST['alt_suffix'] ) );
		}
		if ( isset( $_POST['sort_order'] ) ) {
			$data['sort_order'] = (int) wp_unslash( $_POST['sort_order'] );
		}
		if ( isset( $_POST['badge_mode'] ) ) {
			$data['badge_mode'] = AI_Label_Badge_Registry::sanitize_mode( sanitize_key( wp_unslash( $_POST['badge_mode'] ) ) );
		}
		if ( isset( $_POST['text_config'] ) && is_array( $_POST['text_config'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every leaf is sanitized in AI_Label_Text_Badge::sanitize_config().
			$data['text_config'] = AI_Label_Text_Badge::sanitize_config( wp_unslash( $_POST['text_config'] ) );
		}

		$result = $type_id
			? AI_Label_Badge_Registry::update_type( $type_id, $data )
			: AI_Label_Badge_Registry::insert_type( $data );

		if ( is_wp_error( $result ) ) {
			$this->add_notice( 'error', $result->get_error_message() );
		} else {
			$this->add_notice( 'success', $type_id ? __( 'Label type updated.', 'mme-ai-image-disclosure' ) : __( 'Label type added.', 'mme-ai-image-disclosure' ) );
		}

		$this->redirect( 'types' );
	}

	/**
	 * Deletes a label type.
	 */
	public function handle_delete_type() {
		$this->guard( 'ai_label_delete_type' );

		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
		$result  = AI_Label_Badge_Registry::delete_type( $type_id );

		if ( is_wp_error( $result ) ) {
			$this->add_notice( 'error', $result->get_error_message() );
		} else {
			$this->add_notice( 'success', __( 'Label type deleted.', 'mme-ai-image-disclosure' ) );
		}

		$this->redirect( 'types' );
	}

	/**
	 * Assigns a badge PNG to a variant.
	 */
	public function handle_save_badge() {
		$this->guard( 'ai_label_save_badge' );

		$type_id       = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0;
		$variant       = isset( $_POST['variant'] ) ? sanitize_key( wp_unslash( $_POST['variant'] ) ) : '';
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;

		if ( ! $attachment_id ) {
			$this->add_notice( 'error', __( 'Choose a PNG file first.', 'mme-ai-image-disclosure' ) );
			$this->redirect( 'types' );
		}

		$result = AI_Label_Badge_Registry::set_badge( $type_id, $variant, $attachment_id );

		if ( is_wp_error( $result ) ) {
			$this->add_notice( 'error', $result->get_error_message() );
		} elseif ( ! AI_Label_Badge_Registry::png_has_alpha( (string) get_attached_file( $attachment_id ) ) ) {
			$this->add_notice( 'warning', __( 'Badge saved, but this PNG has no transparency channel — it will show as a solid rectangle.', 'mme-ai-image-disclosure' ) );
		} else {
			$this->add_notice( 'success', __( 'Badge saved.', 'mme-ai-image-disclosure' ) );
		}

		$this->redirect( 'types' );
	}

	/**
	 * Removes a badge variant.
	 */
	public function handle_delete_badge() {
		$this->guard( 'ai_label_delete_badge' );

		$badge_id = isset( $_POST['badge_id'] ) ? absint( wp_unslash( $_POST['badge_id'] ) ) : 0;
		AI_Label_Badge_Registry::delete_badge( $badge_id );
		$this->add_notice( 'success', __( 'Badge removed. Images already labelled with it keep their baked-in badge.', 'mme-ai-image-disclosure' ) );
		$this->redirect( 'types' );
	}

	/**
	 * Queues an apply batch from the apply screen (works without JavaScript).
	 */
	public function handle_apply_form() {
		$this->guard( 'ai_label_apply' );

		$raw_ids = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		$ids     = array_values( array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) ) );
		$ids     = array_values( array_filter( $ids, 'wp_attachment_is_image' ) );

		if ( ! $ids ) {
			$this->add_notice( 'error', __( 'No valid images were selected.', 'mme-ai-image-disclosure' ) );
			$this->redirect( 'apply' );
		}

		$label_type = isset( $_POST['label_type'] ) ? sanitize_key( wp_unslash( $_POST['label_type'] ) ) : '';
		$type       = AI_Label_Badge_Registry::get_type_by_slug( $label_type );
		if ( ! $type ) {
			$this->add_notice( 'error', __( 'Choose a label type.', 'mme-ai-image-disclosure' ) );
			$this->redirect( 'apply', array( 'ids' => implode( ',', $ids ) ) );
		}

		$positions = AI_Label_Settings::positions();
		$position  = isset( $_POST['position'] ) ? sanitize_key( wp_unslash( $_POST['position'] ) ) : 'bottom-right';
		$position  = isset( $positions[ $position ] ) ? $position : 'bottom-right';

		$variants = AI_Label_Settings::variants();
		$variant  = isset( $_POST['variant'] ) ? sanitize_key( wp_unslash( $_POST['variant'] ) ) : 'auto';
		$variant  = ( 'auto' === $variant || isset( $variants[ $variant ] ) ) ? $variant : 'auto';

		$overrides = array();
		if ( isset( $_POST['overrides'] ) && is_array( $_POST['overrides'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each leaf is sanitized below.
			$raw = wp_unslash( $_POST['overrides'] );
			foreach ( (array) $raw as $id => $values ) {
				$id = absint( $id );
				if ( ! $id || ! is_array( $values ) ) {
					continue;
				}
				$entry = array();

				if ( ! empty( $values['label_type'] ) ) {
					$override_type = AI_Label_Badge_Registry::get_type_by_slug( sanitize_key( $values['label_type'] ) );
					if ( $override_type ) {
						$entry['label_type'] = $override_type->slug;
					}
				}
				if ( ! empty( $values['position'] ) && isset( $positions[ sanitize_key( $values['position'] ) ] ) ) {
					$entry['position'] = sanitize_key( $values['position'] );
				}
				if ( ! empty( $values['variant'] ) ) {
					$override_variant = sanitize_key( $values['variant'] );
					if ( 'auto' === $override_variant || isset( $variants[ $override_variant ] ) ) {
						$entry['variant'] = $override_variant;
					}
				}
				if ( $entry ) {
					$overrides[ $id ] = $entry;
				}
			}
		}

		$batch_id = $this->jobs->enqueue_batch(
			$ids,
			'apply',
			array(
				'label_type' => $type->slug,
				'position'   => $position,
				'variant'    => $variant,
			),
			$overrides
		);

		$this->add_notice(
			'success',
			sprintf(
				/* translators: %d: number of images. */
				_n( '%d image queued. Processing continues in the background.', '%d images queued. Processing continues in the background.', count( $ids ), 'mme-ai-image-disclosure' ),
				count( $ids )
			)
		);
		$this->redirect( 'apply', array( 'batch' => $batch_id ) );
	}

	/**
	 * Deletes the backups of images that are no longer labelled.
	 */
	public function handle_clear_backups() {
		$this->guard( 'ai_label_clear_backups' );

		$ids     = AI_Label_Backup::stale_attachment_ids();
		$freed   = AI_Label_Backup::size_of( $ids );
		$deleted = 0;

		foreach ( $ids as $id ) {
			if ( true === AI_Label_Backup::delete_for_attachment( $id ) ) {
				++$deleted;
			}
		}

		$this->add_notice(
			'success',
			sprintf(
				/* translators: 1: number of images, 2: size freed. */
				__( 'Cleared the backups of %1$d images, freeing %2$s.', 'mme-ai-image-disclosure' ),
				$deleted,
				size_format( $freed, 1 )
			)
		);
		$this->redirect( 'tools' );
	}

	/**
	 * Clears finished job rows.
	 */
	public function handle_clear_log() {
		$this->guard( 'ai_label_clear_log' );

		$deleted = $this->jobs->clear_log();
		$this->add_notice(
			'success',
			sprintf(
				/* translators: %d: number of rows. */
				_n( 'Removed %d log entry.', 'Removed %d log entries.', $deleted, 'mme-ai-image-disclosure' ),
				$deleted
			)
		);
		$this->redirect( 'log' );
	}

	/**
	 * Deletes the cached text badge renders.
	 */
	public function handle_clear_text_cache() {
		$this->guard( 'ai_label_clear_text_cache' );

		$removed = AI_Label_Text_Badge::clear_cache();
		$this->add_notice(
			'success',
			sprintf(
				/* translators: %d: number of files. */
				_n( 'Removed %d rendered badge file.', 'Removed %d rendered badge files.', $removed, 'mme-ai-image-disclosure' ),
				$removed
			)
		);
		$this->redirect( 'tools' );
	}

	/**
	 * Dismisses the "purge your CDN" notice.
	 */
	public function handle_dismiss_purge_notice() {
		$this->guard( 'ai_label_dismiss_purge_notice' );

		delete_option( AI_Label_Cache_Purge::NOTICE_OPTION );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}
}
