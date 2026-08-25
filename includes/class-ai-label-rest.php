<?php
/**
 * REST endpoints for the Media Library modal and progress polling.
 *
 * @package AI_Image_Disclosure
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every route below is capability-checked in its permission_callback and, for writes,
 * additionally nonce-checked by the REST cookie authentication layer (PRD §6.5).
 */
class AI_Label_REST {

	const NAMESPACE_V1 = 'ai-label/v1';

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Shared permission callback.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( AI_Label_Plugin::user_can() ) {
			return true;
		}
		return new WP_Error(
			'ai_label_forbidden',
			__( 'You are not allowed to manage AI content labels.', 'ai-image-disclosure' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes() {
		$ids_arg = array(
			'required'          => true,
			'type'              => 'array',
			'items'             => array( 'type' => 'integer' ),
			'sanitize_callback' => array( $this, 'sanitize_ids' ),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'check_permission' ),
				'callback'            => array( $this, 'handle_apply' ),
				'args'                => array(
					'attachment_ids' => $ids_arg,
					'label_type'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'position'       => array(
						'type'              => 'string',
						'default'           => 'bottom-right',
						'sanitize_callback' => 'sanitize_key',
					),
					'variant'        => array(
						'type'              => 'string',
						'default'           => 'auto',
						'sanitize_callback' => 'sanitize_key',
					),
					'overrides'      => array(
						'type'     => 'object',
						'default'  => array(),
						'required' => false,
					),
				),
			)
		);

		foreach ( array( 'restore', 'reapply' ) as $action ) {
			register_rest_route(
				self::NAMESPACE_V1,
				'/' . $action,
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'check_permission' ),
					'callback'            => array( $this, 'restore' === $action ? 'handle_restore' : 'handle_reapply' ),
					'args'                => array( 'attachment_ids' => $ids_arg ),
				)
			);
		}

		register_rest_route(
			self::NAMESPACE_V1,
			'/progress',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'check_permission' ),
				'callback'            => array( $this, 'handle_progress' ),
				'args'                => array(
					'batch_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/text-preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'check_permission' ),
				'callback'            => array( $this, 'handle_text_preview' ),
				'args'                => array(
					'config' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/attachments',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => array( $this, 'check_permission' ),
				'callback'            => array( $this, 'handle_attachments' ),
				'args'                => array( 'attachment_ids' => $ids_arg ),
			)
		);
	}

	/**
	 * Sanitizes a list of attachment ids.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public function sanitize_ids( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		$ids = array_map( 'absint', (array) $value );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Queues apply jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_apply( WP_REST_Request $request ) {
		$ids = $request->get_param( 'attachment_ids' );
		if ( ! $ids ) {
			return new WP_Error( 'ai_label_no_images', __( 'No images were selected.', 'ai-image-disclosure' ), array( 'status' => 400 ) );
		}

		$type = AI_Label_Badge_Registry::get_type_by_slug( $request->get_param( 'label_type' ) );
		if ( ! $type ) {
			return new WP_Error( 'ai_label_bad_type', __( 'Unknown label type.', 'ai-image-disclosure' ), array( 'status' => 400 ) );
		}
		$ready = AI_Label_Badge_Registry::type_is_ready( $type );
		if ( is_wp_error( $ready ) ) {
			return new WP_Error( 'ai_label_type_not_ready', $ready->get_error_message(), array( 'status' => 400 ) );
		}

		$overrides = $this->sanitize_overrides( $request->get_param( 'overrides' ) );

		$batch_id = $this->jobs->enqueue_batch(
			$ids,
			'apply',
			array(
				'label_type' => $type->slug,
				'position'   => $request->get_param( 'position' ),
				'variant'    => $request->get_param( 'variant' ),
			),
			$overrides
		);

		return rest_ensure_response(
			array(
				'batch_id' => $batch_id,
				'queued'   => count( $ids ),
				'progress' => $this->jobs->batch_progress( $batch_id ),
			)
		);
	}

	/**
	 * Queues restore jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_restore( WP_REST_Request $request ) {
		return $this->queue_simple( $request, 'restore' );
	}

	/**
	 * Queues re-apply jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_reapply( WP_REST_Request $request ) {
		return $this->queue_simple( $request, 'reapply' );
	}

	/**
	 * Shared queueing for restore/reapply.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $action  Action.
	 * @return WP_REST_Response|WP_Error
	 */
	private function queue_simple( WP_REST_Request $request, $action ) {
		$ids = $request->get_param( 'attachment_ids' );
		if ( ! $ids ) {
			return new WP_Error( 'ai_label_no_images', __( 'No images were selected.', 'ai-image-disclosure' ), array( 'status' => 400 ) );
		}

		$batch_id = $this->jobs->enqueue_batch( $ids, $action );

		return rest_ensure_response(
			array(
				'batch_id' => $batch_id,
				'queued'   => count( $ids ),
				'progress' => $this->jobs->batch_progress( $batch_id ),
			)
		);
	}

	/**
	 * Batch progress for the progress bar.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_progress( WP_REST_Request $request ) {
		return rest_ensure_response( $this->jobs->batch_progress( $request->get_param( 'batch_id' ) ) );
	}

	/**
	 * Renders a text badge from unsaved settings so the admin can see it while typing.
	 *
	 * Nothing is persisted beyond the render cache, which is regenerable and cleared from
	 * the Tools screen.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_text_preview( WP_REST_Request $request ) {
		$config = AI_Label_Text_Badge::sanitize_config( (array) $request->get_param( 'config' ) );
		$out    = array();

		foreach ( array( 'light', 'dark' ) as $variant ) {
			$path = AI_Label_Text_Badge::render( $config, $variant );
			if ( is_wp_error( $path ) ) {
				return new WP_Error( 'ai_label_preview_failed', $path->get_error_message(), array( 'status' => 400 ) );
			}
			$size = wp_getimagesize( $path );
			$out[ $variant ] = array(
				'url'    => AI_Label_Text_Badge::url_for( $path ),
				'width'  => $size ? (int) $size[0] : 0,
				'height' => $size ? (int) $size[1] : 0,
			);
		}

		return rest_ensure_response( array( 'previews' => $out ) );
	}

	/**
	 * Current label state of the selected attachments, used to pre-fill the modal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_attachments( WP_REST_Request $request ) {
		$out = array();

		foreach ( (array) $request->get_param( 'attachment_ids' ) as $id ) {
			$id   = (int) $id;
			$data = AI_Label_Labeller::get_attachment_data( $id );
			$out[] = array(
				'attachment_id' => $id,
				'title'         => get_the_title( $id ),
				'thumb'         => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'mime'          => get_post_mime_type( $id ),
				'status'        => isset( $data['status'] ) ? $data['status'] : 'none',
				'label_type'    => isset( $data['label_type'] ) ? $data['label_type'] : '',
				'position'      => isset( $data['position'] ) ? $data['position'] : '',
				'variant'       => isset( $data['variant'] ) ? $data['variant'] : '',
				'has_backup'    => AI_Label_Backup::has_backup( $id ),
				'can_reapply'   => AI_Label_Labeller::can_reapply( $id ),
			);
		}

		return rest_ensure_response( array( 'attachments' => $out ) );
	}

	/**
	 * Sanitizes the per-image override map.
	 *
	 * @param mixed $raw Raw overrides.
	 * @return array
	 */
	private function sanitize_overrides( $raw ) {
		$clean     = array();
		$positions = AI_Label_Settings::positions();
		$variants  = AI_Label_Settings::variants();

		foreach ( (array) $raw as $id => $values ) {
			$id = (int) $id;
			if ( $id < 1 || ! is_array( $values ) ) {
				continue;
			}

			$entry = array();
			if ( ! empty( $values['label_type'] ) ) {
				$type = AI_Label_Badge_Registry::get_type_by_slug( sanitize_key( $values['label_type'] ) );
				if ( $type ) {
					$entry['label_type'] = $type->slug;
				}
			}
			if ( ! empty( $values['position'] ) && isset( $positions[ sanitize_key( $values['position'] ) ] ) ) {
				$entry['position'] = sanitize_key( $values['position'] );
			}
			if ( ! empty( $values['variant'] ) ) {
				$variant = sanitize_key( $values['variant'] );
				if ( 'auto' === $variant || isset( $variants[ $variant ] ) ) {
					$entry['variant'] = $variant;
				}
			}

			if ( $entry ) {
				$clean[ $id ] = $entry;
			}
		}

		return $clean;
	}
}
