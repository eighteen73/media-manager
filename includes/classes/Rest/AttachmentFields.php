<?php
/**
 * Attachment REST field helpers for folder ACL visibility.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Rest;

use Eighteen73\MediaManager\Permissions\EffectiveAcl;
use Eighteen73\MediaManager\PrivateMedia\TransitionService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers attachment fields and filters inaccessible relationships.
 */
class AttachmentFields {

	/**
	 * Effective ACL.
	 *
	 * @var EffectiveAcl
	 */
	private EffectiveAcl $acl;

	/**
	 * Transition service.
	 *
	 * @var TransitionService
	 */
	private TransitionService $transitions;

	/**
	 * Constructor.
	 *
	 * @param EffectiveAcl|null      $acl         Optional ACL.
	 * @param TransitionService|null $transitions Optional transitions.
	 */
	public function __construct( ?EffectiveAcl $acl = null, ?TransitionService $transitions = null ) {
		$this->acl         = $acl ?? new EffectiveAcl();
		$this->transitions = $transitions ?? new TransitionService();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_fields' ] );
		add_filter( 'rest_prepare_attachment', [ $this, 'filter_prepare_attachment' ], 20, 3 );
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'prepare_attachment_for_js' ], 20, 3 );
	}

	/**
	 * Register custom REST fields.
	 *
	 * @return void
	 */
	public function register_fields(): void {
		register_rest_field(
			'attachment',
			'media_manager_folders',
			[
				'get_callback' => [ $this, 'get_folders_field' ],
				'schema'       => [
					'description' => __( 'Accessible Media Manager folder IDs.', 'media-manager' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'context'     => [ 'view', 'edit' ],
				],
			]
		);

		register_rest_field(
			'attachment',
			'media_manager_is_private',
			[
				'get_callback' => [ $this, 'get_private_field' ],
				'schema'       => [
					'description' => __( 'Whether the attachment is in private storage.', 'media-manager' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
				],
			]
		);
	}

	/**
	 * Visible folder IDs for the current user.
	 *
	 * @param array $item Attachment object.
	 * @return int[]
	 */
	public function get_folders_field( array $item ): array {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		return $id > 0 ? $this->acl->visible_attachment_folder_ids( $id ) : [];
	}

	/**
	 * Private flag.
	 *
	 * @param array $item Attachment object.
	 * @return bool
	 */
	public function get_private_field( array $item ): bool {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		return $id > 0 && $this->transitions->is_private( $id );
	}

	/**
	 * Hide attachments the user cannot view, and strip source_url path leaks.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Attachment.
	 * @param \WP_REST_Request  $request  Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function filter_prepare_attachment( $response, $post, $request ) {
		unset( $request );

		if ( ! $this->acl->can_view_attachment( (int) $post->ID ) ) {
			return new \WP_Error(
				'media_manager_forbidden',
				__( 'You cannot view this attachment.', 'media-manager' ),
				[ 'status' => 403 ]
			);
		}

		return $response;
	}

	/**
	 * Expose visible folder IDs and private flag on media JS models.
	 *
	 * @param array      $response   Attachment data for JS.
	 * @param \WP_Post   $attachment Attachment post.
	 * @param array|null $meta       Attachment meta (unused).
	 * @return array
	 */
	public function prepare_attachment_for_js( $response, $attachment, $meta = null ) {
		unset( $meta );

		if ( ! is_array( $response ) || ! $attachment instanceof \WP_Post ) {
			return $response;
		}

		$id = (int) $attachment->ID;

		$response['media_manager_folders']    = $id > 0 ? $this->acl->visible_attachment_folder_ids( $id ) : [];
		$response['media_manager_is_private'] = $id > 0 && $this->transitions->is_private( $id );

		return $response;
	}
}
