<?php
/**
 * Background job queue and persistent job log.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * One queued job per image (PRD §6.1). Uses Action Scheduler when a plugin on the site
 * provides it, and WP-Cron single events otherwise — no bundled dependency either way.
 */
class AI_Label_Jobs {

	const HOOK_RUN   = 'ai_label_run_job';
	const AS_GROUP   = 'ai-image-disclosure';
	const MAX_PER_RUN = 8;

	/**
	 * Labeller instance.
	 *
	 * @var AI_Label_Labeller
	 */
	private $labeller;

	/**
	 * Wires the runners.
	 *
	 * @param AI_Label_Labeller $labeller Labeller.
	 */
	public function __construct( AI_Label_Labeller $labeller ) {
		$this->labeller = $labeller;

		add_action( self::HOOK_RUN, array( $this, 'run_job' ), 10, 1 );
		add_action( AI_Label_Install::CRON_QUEUE_TICK, array( $this, 'process_queue' ) );
	}

	/**
	 * Whether Action Scheduler is available on this site.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Queues one job per attachment and returns the batch id.
	 *
	 * @param array  $attachment_ids Attachment ids.
	 * @param string $action         apply|restore|reapply.
	 * @param array  $args           Shared job args.
	 * @param array  $overrides      attachment_id => per-image arg overrides (PRD §5.2).
	 * @return string Batch id.
	 */
	public function enqueue_batch( array $attachment_ids, $action, array $args = array(), array $overrides = array() ) {
		$batch_id = 'b' . substr( md5( implode( ',', $attachment_ids ) . microtime() . wp_rand() ), 0, 24 );

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id < 1 ) {
				continue;
			}
			$job_args = isset( $overrides[ $attachment_id ] ) ? array_merge( $args, (array) $overrides[ $attachment_id ] ) : $args;
			$this->enqueue( $attachment_id, $action, $job_args, $batch_id );
		}

		$this->dispatch();

		return $batch_id;
	}

	/**
	 * Inserts a single job row and schedules it.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $action        apply|restore|reapply.
	 * @param array  $args          Job args.
	 * @param string $batch_id      Batch id.
	 * @return int Job id.
	 */
	public function enqueue( $attachment_id, $action, array $args = array(), $batch_id = '' ) {
		global $wpdb;

		$allowed = array( 'apply', 'restore', 'reapply' );
		if ( ! in_array( $action, $allowed, true ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			AI_Label_Install::jobs_table(),
			array(
				'batch_id'      => substr( (string) $batch_id, 0, 32 ),
				'attachment_id' => (int) $attachment_id,
				'action'        => $action,
				'status'        => 'queued',
				'args'          => wp_json_encode( $args ),
				'user_id'       => get_current_user_id(),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);

		$job_id = (int) $wpdb->insert_id;
		if ( ! $job_id ) {
			return 0;
		}

		if ( self::has_action_scheduler() ) {
			as_enqueue_async_action( self::HOOK_RUN, array( $job_id ), self::AS_GROUP );
		} else {
			wp_schedule_single_event( time(), self::HOOK_RUN, array( $job_id ) );
		}

		return $job_id;
	}

	/**
	 * Nudges the queue so work starts without waiting for the next page view.
	 */
	public function dispatch() {
		if ( self::has_action_scheduler() ) {
			return;
		}
		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			return;
		}

		$url = site_url( 'wp-cron.php?doing_wp_cron=' . sprintf( '%.22F', microtime( true ) ) );
		wp_remote_post(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Runs one job. Never throws — every outcome is written to the job row.
	 *
	 * @param int $job_id Job id.
	 */
	public function run_job( $job_id ) {
		global $wpdb;

		$job = $this->get_job( $job_id );
		if ( ! $job || 'queued' !== $job->status ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( AI_Label_Install::jobs_table(), array( 'status' => 'processing' ), array( 'id' => (int) $job->id ), array( '%s' ), array( '%d' ) );

		$args = json_decode( (string) $job->args, true );
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		try {
			switch ( $job->action ) {
				case 'restore':
					$result = $this->labeller->restore( (int) $job->attachment_id );
					break;
				case 'reapply':
					$result = $this->labeller->reapply( (int) $job->attachment_id );
					break;
				case 'apply':
				default:
					$result = $this->labeller->apply( (int) $job->attachment_id, $args );
					break;
			}
		} catch ( Throwable $e ) {
			$result = array(
				'status'  => 'error',
				'step'    => 'exception',
				'message' => $e->getMessage(),
			);
		}

		$status = 'error' === $result['status'] ? 'failed' : 'success';
		$notes  = ! empty( $result['notes'] ) ? implode( ' ', (array) $result['notes'] ) : '';
		$message = trim( $result['message'] . ( '' !== $notes ? ' — ' . $notes : '' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			AI_Label_Install::jobs_table(),
			array(
				'status'        => $status,
				'failed_step'   => isset( $result['step'] ) ? substr( $result['step'], 0, 50 ) : null,
				'error_message' => $message,
				'completed_at'  => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $job->id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Cron safety net: picks up jobs whose scheduled event was lost.
	 */
	public function process_queue() {
		$queued = $this->get_queued_ids( self::MAX_PER_RUN );
		if ( ! $queued ) {
			return;
		}

		$started = microtime( true );
		foreach ( $queued as $job_id ) {
			$this->run_job( $job_id );
			if ( ( microtime( true ) - $started ) > 20 ) {
				break;
			}
		}
	}

	/**
	 * Ids of queued jobs, oldest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function get_queued_ids( $limit = 20 ) {
		global $wpdb;
		$table = AI_Label_Install::jobs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'queued' ORDER BY id ASC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * One job row.
	 *
	 * @param int $job_id Job id.
	 * @return object|null
	 */
	public function get_job( $job_id ) {
		global $wpdb;
		$table = AI_Label_Install::jobs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $job_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rows of one batch.
	 *
	 * @param string $batch_id Batch id.
	 * @return array
	 */
	public function get_batch( $batch_id ) {
		global $wpdb;
		$table = AI_Label_Install::jobs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE batch_id = %s ORDER BY id ASC", (string) $batch_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Progress summary for the admin progress bar (PRD §6.1).
	 *
	 * @param string $batch_id Batch id.
	 * @return array
	 */
	public function batch_progress( $batch_id ) {
		$rows = $this->get_batch( $batch_id );

		$summary = array(
			'batch_id'  => (string) $batch_id,
			'total'     => count( $rows ),
			'done'      => 0,
			'success'   => 0,
			'failed'    => 0,
			'pending'   => 0,
			'complete'  => false,
			'items'     => array(),
		);

		foreach ( $rows as $row ) {
			if ( in_array( $row->status, array( 'success', 'failed' ), true ) ) {
				++$summary['done'];
				if ( 'success' === $row->status ) {
					++$summary['success'];
				} else {
					++$summary['failed'];
				}
			} else {
				++$summary['pending'];
			}

			$summary['items'][] = array(
				'job_id'        => (int) $row->id,
				'attachment_id' => (int) $row->attachment_id,
				'title'         => get_the_title( (int) $row->attachment_id ),
				'thumb'         => wp_get_attachment_image_url( (int) $row->attachment_id, 'thumbnail' ),
				'action'        => $row->action,
				'status'        => $row->status,
				'message'       => (string) $row->error_message,
				'step'          => (string) $row->failed_step,
			);
		}

		$summary['complete'] = ( 0 === $summary['pending'] && $summary['total'] > 0 );

		return $summary;
	}

	/**
	 * Paginated job log for the admin log screen.
	 *
	 * @param array $args status, per_page, page.
	 * @return array { rows: array, total: int }
	 */
	public function get_log( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'per_page' => 25,
				'page'     => 1,
			)
		);

		$table    = AI_Label_Install::jobs_table();
		$per_page = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) * $per_page );
		$statuses = array( 'queued', 'processing', 'success', 'failed' );

		if ( in_array( $args['status'], $statuses, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $args['status'], $per_page, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $args['status'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Deletes finished job rows. Never touches image files or backups.
	 *
	 * @return int Rows deleted.
	 */
	public function clear_log() {
		global $wpdb;
		$table = AI_Label_Install::jobs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( "DELETE FROM {$table} WHERE status IN ('success','failed')" );
	}

	/**
	 * Number of jobs still waiting, for the admin notice.
	 *
	 * @return int
	 */
	public function pending_count() {
		global $wpdb;
		$table = AI_Label_Install::jobs_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('queued','processing')" );
	}
}
