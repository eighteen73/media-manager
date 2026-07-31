<?php
/**
 * Folder assignment REST controller.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Rest;

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Permissions\AccessPolicy;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoint for assigning attachments to folders.
 */
class AssignmentsController extends WP_REST_Controller {

	/**
	 * Maximum attachments per request.
	 *
	 * @var int
	 */
	public const MAX_ITEMS = 100;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy|null     $access     Optional access policy.
	 * @param FolderRepository|null $repository Optional repository.
	 */
	public function __construct( ?AccessPolicy $access = null, ?FolderRepository $repository = null ) {
		$this->namespace  = 'media-manager/v1';
		$this->rest_base  = 'assignments';
		$this->access     = $access ?? new AccessPolicy();
		$this->repository = $repository ?? new FolderRepository();
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'attachment_ids' => [
							'type'              => 'array',
							'required'          => true,
							'items'             => [ 'type' => 'integer' ],
							'sanitize_callback' => [ $this, 'sanitize_attachment_ids' ],
						],
						'folder_id'      => [
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						],
						'mode'           => [
							'type'              => 'string',
							'required'          => true,
							'enum'              => [ 'add', 'move' ],
							'sanitize_callback' => 'sanitize_key',
						],
					],
				],
			]
		);
	}

	/**
	 * Permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		unset( $request );

		if ( ! $this->access->can_assign_folders() ) {
			return new WP_Error(
				'media_manager_forbidden',
				__( 'Sorry, you are not allowed to assign folders.', 'media-manager' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Sanitize attachment IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	public function sanitize_attachment_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $value )
				)
			)
		);
	}

	/**
	 * Assign attachments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$attachment_ids = $request->get_param( 'attachment_ids' );
		$folder_id      = (int) $request->get_param( 'folder_id' );
		$mode           = (string) $request->get_param( 'mode' );

		if ( empty( $attachment_ids ) ) {
			return new WP_Error(
				'media_manager_missing_attachments',
				__( 'Select at least one media item.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		if ( count( $attachment_ids ) > self::MAX_ITEMS ) {
			return new WP_Error(
				'media_manager_too_many_attachments',
				sprintf(
					/* translators: %d: maximum number of attachments */
					__( 'You can assign at most %d media items at once.', 'media-manager' ),
					self::MAX_ITEMS
				),
				[ 'status' => 400 ]
			);
		}

		if ( 'add' === $mode && $folder_id < 1 ) {
			return new WP_Error(
				'media_manager_invalid_folder',
				__( 'Choose a folder when adding media.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		if ( $folder_id > 0 ) {
			$folder = $this->repository->find( $folder_id );

			if ( is_wp_error( $folder ) ) {
				return $folder;
			}
		}

		$errors = [];

		foreach ( $attachment_ids as $attachment_id ) {
			$post = get_post( $attachment_id );

			if ( ! $post || 'attachment' !== $post->post_type ) {
				$errors[ $attachment_id ] = __( 'Invalid attachment.', 'media-manager' );
				continue;
			}

			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				$errors[ $attachment_id ] = __( 'You cannot edit this attachment.', 'media-manager' );
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'media_manager_assignment_conflict',
				__( 'Some media items could not be assigned.', 'media-manager' ),
				[
					'status' => 409,
					'errors' => $errors,
				]
			);
		}

		foreach ( $attachment_ids as $attachment_id ) {
			if ( 'add' === $mode ) {
				$result = $this->repository->set_attachment_folders( $attachment_id, [ $folder_id ], true );
			} else {
				$ids    = $folder_id > 0 ? [ $folder_id ] : [];
				$result = $this->repository->set_attachment_folders( $attachment_id, $ids, false );
			}

			if ( is_wp_error( $result ) ) {
				$errors[ $attachment_id ] = $result->get_error_message();
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'media_manager_assignment_conflict',
				__( 'Some media items could not be assigned.', 'media-manager' ),
				[
					'status' => 409,
					'errors' => $errors,
				]
			);
		}

		return rest_ensure_response(
			[
				'assigned'       => true,
				'attachment_ids' => $attachment_ids,
				'folder_id'      => $folder_id,
				'mode'           => $mode,
			]
		);
	}
}
