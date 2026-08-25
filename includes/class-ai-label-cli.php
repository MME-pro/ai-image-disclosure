<?php
/**
 * WP-CLI commands — the processing engine is testable before touching the UI (PRD §10.2).
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * `wp ai-label <command>`
 */
class AI_Label_CLI {

	/**
	 * Registers the command.
	 */
	public static function register() {
		WP_CLI::add_command( 'ai-label', __CLASS__ );
	}

	/**
	 * Applies a label to one or more attachments, synchronously.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : Attachment IDs.
	 *
	 * [--type=<slug>]
	 * : Label type slug. Defaults to the first registered type.
	 *
	 * [--position=<position>]
	 * : top-left, top-right, bottom-left or bottom-right. Default: bottom-right.
	 *
	 * [--variant=<variant>]
	 * : auto (default), light or dark.
	 *
	 * [--force]
	 * : Restore the original first, then label again.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ai-label apply 42 43 --type=ai-generated --position=bottom-right
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function apply( $args, $assoc_args ) {
		$types = AI_Label_Badge_Registry::get_types();
		if ( ! $types ) {
			WP_CLI::error( 'No label types are registered yet.' );
		}

		$slug     = isset( $assoc_args['type'] ) ? sanitize_key( $assoc_args['type'] ) : $types[0]->slug;
		$position = isset( $assoc_args['position'] ) ? sanitize_key( $assoc_args['position'] ) : 'bottom-right';
		$variant  = isset( $assoc_args['variant'] ) ? sanitize_key( $assoc_args['variant'] ) : 'auto';
		$force    = isset( $assoc_args['force'] );

		$labeller = new AI_Label_Labeller();

		foreach ( $args as $id ) {
			$result = $labeller->apply(
				(int) $id,
				array(
					'label_type' => $slug,
					'position'   => $position,
					'variant'    => $variant,
					'force'      => $force,
				)
			);
			self::report( (int) $id, $result );
		}
	}

	/**
	 * Restores the originals of one or more attachments.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : Attachment IDs.
	 *
	 * @param array $args Positional args.
	 */
	public function restore( $args ) {
		$labeller = new AI_Label_Labeller();
		foreach ( $args as $id ) {
			self::report( (int) $id, $labeller->restore( (int) $id ) );
		}
	}

	/**
	 * Re-applies the recorded label using the current badge and settings.
	 *
	 * ## OPTIONS
	 *
	 * <ids>...
	 * : Attachment IDs.
	 *
	 * @param array $args Positional args.
	 */
	public function reapply( $args ) {
		$labeller = new AI_Label_Labeller();
		foreach ( $args as $id ) {
			self::report( (int) $id, $labeller->reapply( (int) $id ) );
		}
	}

	/**
	 * Prints the stored label state of an attachment.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Attachment ID.
	 *
	 * @param array $args Positional args.
	 */
	public function status( $args ) {
		$data = AI_Label_Labeller::get_attachment_data( (int) $args[0] );
		WP_CLI::log( (string) wp_json_encode( $data, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Runs any jobs still sitting in the queue.
	 *
	 * @subcommand run-queue
	 */
	public function run_queue() {
		$jobs   = AI_Label_Plugin::instance()->jobs;
		$queued = $jobs->get_queued_ids( 500 );

		if ( ! $queued ) {
			WP_CLI::success( 'Queue is empty.' );
			return;
		}

		$progress = WP_CLI\Utils\make_progress_bar( 'Processing jobs', count( $queued ) );
		foreach ( $queued as $job_id ) {
			$jobs->run_job( $job_id );
			$progress->tick();
		}
		$progress->finish();
		WP_CLI::success( sprintf( 'Processed %d jobs.', count( $queued ) ) );
	}

	/**
	 * Prints a single result line.
	 *
	 * @param int   $id     Attachment id.
	 * @param array $result Labeller result.
	 */
	private static function report( $id, array $result ) {
		$line = sprintf( '#%d: %s', $id, $result['message'] );
		if ( ! empty( $result['notes'] ) ) {
			$line .= ' (' . implode( ' ', (array) $result['notes'] ) . ')';
		}

		if ( 'error' === $result['status'] ) {
			WP_CLI::warning( $line );
		} elseif ( 'skipped' === $result['status'] ) {
			WP_CLI::log( $line );
		} else {
			WP_CLI::success( $line );
		}
	}
}
