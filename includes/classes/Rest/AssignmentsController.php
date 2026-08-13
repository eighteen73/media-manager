<?php
/**
 * Folder assignment REST controller.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Rest;

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Permissions\AccessPolicy;
use Eighteen73\MediaManager\Permissions\AclCache;
use Eighteen73\MediaManager\Permissions\FolderPolicy;
use Eighteen73\MediaManager\PrivateMedia\TransitionService;
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
	 * Folder policy.
	 *
	 * @var FolderPolicy
	 */
	private FolderPolicy $policy;

	/**
	 * Transition service.
	 *
	 * @var TransitionService
	 */
	private TransitionService $transitions;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy|null      $access      Optional access policy.
	 * @param FolderRepository|null  $repository  Optional repository.
	 * @param FolderPolicy|null      $policy      Optional policy.
	 * @param TransitionService|null $transitions Optional transitions.
	 */
	public function __construct(
		?AccessPolicy $access = null,
		?FolderRepository $repository = null,
		?FolderPolicy $policy = null,
		?TransitionService $transitions = null
	) {
		$this->namespace   = 'media-manager/v1';
		$this->rest_base   = 'assignments';
		$this->access      = $access ?? new AccessPolicy();
		$this->repository  = $repository ?? new FolderRepository();
		$this->policy      = $policy ?? new FolderPolicy( $this->repository );
		$this->transitions = $transitions ?? new TransitionService( null, $this->repository, $this->policy );
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
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						],
						'folder_ids'     => [
							'type'              => 'array',
							'required'          => false,
							'default'           => [],
							'items'             => [ 'type' => 'integer' ],
							'sanitize_callback' => [ $this, 'sanitize_attachment_ids' ],
						],
						'mode'           => [
							'type'              => 'string',
							'required'          => true,
							'enum'              => [ 'add', 'move', 'set' ],
							'sanitize_callback' => 'sanitize_key',
						],
						'confirm'        => [
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
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
	 * Sanitize attachment or folder ID lists.
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
		$mode           = (string) $request->get_param( 'mode' );
		$confirm        = (bool) $request->get_param( 'confirm' );

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

		if ( 'set' === $mode ) {
			$resolved = $this->resolve_set_folders( $request->get_param( 'folder_ids' ) );
		} else {
			$resolved = $this->resolve_single_folder(
				(int) $request->get_param( 'folder_id' ),
				$mode
			);
		}

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$folder_ids     = $resolved['folder_ids'];
		$folder_id      = $resolved['folder_id'];
		$target_private = $resolved['target_private'];

		$errors      = [];
		$needs_enter = false;
		$needs_leave = false;

		foreach ( $attachment_ids as $attachment_id ) {
			$post = get_post( $attachment_id );

			if ( ! $post || 'attachment' !== $post->post_type ) {
				$errors[ $attachment_id ] = __( 'Invalid attachment.', 'media-manager' );
				continue;
			}

			if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
				$errors[ $attachment_id ] = __( 'You cannot edit this attachment.', 'media-manager' );
				continue;
			}

			if ( ! $this->access->can_view_attachment( $attachment_id ) ) {
				$errors[ $attachment_id ] = __( 'You cannot access this attachment.', 'media-manager' );
				continue;
			}

			if ( $this->transitions->is_blocked( $attachment_id ) ) {
				$errors[ $attachment_id ] = __( 'Incomplete private transition blocks this change.', 'media-manager' );
				continue;
			}

			$is_private = $this->transitions->is_private( $attachment_id );

			if ( $target_private && ! $is_private ) {
				$needs_enter = true;
			}

			if ( ! $target_private && $is_private ) {
				$needs_leave = true;
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

		if ( ( $needs_enter || $needs_leave ) && ! $confirm ) {
			return new WP_Error(
				'media_manager_confirmation_required',
				$needs_enter
					? __( 'Moving into a private folder replaces all public folder relationships and stores files privately. Confirm to continue.', 'media-manager' )
					: __( 'Leaving private storage makes these files public again. Confirm to continue.', 'media-manager' ),
				[
					'status'      => 409,
					'needs_enter' => $needs_enter,
					'needs_leave' => $needs_leave,
				]
			);
		}

		foreach ( $attachment_ids as $attachment_id ) {
			$is_private = $this->transitions->is_private( $attachment_id );

			if ( $target_private ) {
				$result = $this->transitions->enter_private( $attachment_id, $folder_id );
			} elseif ( $is_private ) {
				$result = $this->transitions->leave_private( $attachment_id, $folder_ids );
			} elseif ( 'add' === $mode ) {
				$result = $this->repository->set_attachment_folders( $attachment_id, [ $folder_id ], true );
			} else {
				// move or set (public / empty).
				$result = $this->repository->set_attachment_folders( $attachment_id, $folder_ids, false );
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

		AclCache::invalidate_site();

		$response = [
			'assigned'       => true,
			'attachment_ids' => $attachment_ids,
			'mode'           => $target_private ? ( 'set' === $mode ? 'set' : 'move' ) : $mode,
			'folder_ids'     => $folder_ids,
		];

		if ( 'set' !== $mode ) {
			$response['folder_id'] = $folder_id;
		}

		return rest_ensure_response( $response );
	}

	/**
	 * Resolve folder targets for add/move modes.
	 *
	 * @param int    $folder_id Folder ID (0 = uncategorized for move).
	 * @param string $mode      add|move.
	 * @return array{folder_ids: int[], folder_id: int, target_private: bool}|WP_Error
	 */
	private function resolve_single_folder( int $folder_id, string $mode ) {
		if ( 'add' === $mode && $folder_id < 1 ) {
			return new WP_Error(
				'media_manager_invalid_folder',
				__( 'Choose a folder when adding media.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$target_private = false;

		if ( $folder_id > 0 ) {
			$validated = $this->validate_folder_access( $folder_id );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$target_private = $validated;

			if ( $target_private && 'add' === $mode ) {
				return new WP_Error(
					'media_manager_private_requires_move',
					__( 'Private folders are exclusive. Use Move instead of Add.', 'media-manager' ),
					[ 'status' => 400 ]
				);
			}
		}

		$folder_ids = $folder_id > 0 ? [ $folder_id ] : [];

		return [
			'folder_ids'     => $folder_ids,
			'folder_id'      => $folder_id,
			'target_private' => $target_private,
		];
	}

	/**
	 * Resolve folder targets for set (replace) mode.
	 *
	 * @param mixed $raw_folder_ids Raw folder_ids param.
	 * @return array{folder_ids: int[], folder_id: int, target_private: bool}|WP_Error
	 */
	private function resolve_set_folders( $raw_folder_ids ) {
		$folder_ids  = $this->sanitize_attachment_ids( $raw_folder_ids );
		$private_ids = [];

		foreach ( $folder_ids as $folder_id ) {
			$validated = $this->validate_folder_access( $folder_id );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			if ( $validated ) {
				$private_ids[] = $folder_id;
			}
		}

		$private_count = count( $private_ids );

		if ( $private_count > 1 || ( 1 === $private_count && count( $folder_ids ) > 1 ) ) {
			return new WP_Error(
				'media_manager_private_exclusive',
				__( 'Private folders are exclusive. Choose a single private folder, or only public folders.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$target_private = 1 === $private_count;
		$folder_id      = $target_private ? (int) $private_ids[0] : ( $folder_ids[0] ?? 0 );

		return [
			'folder_ids'     => $folder_ids,
			'folder_id'      => $folder_id,
			'target_private' => $target_private,
		];
	}

	/**
	 * Ensure a folder exists and is accessible. Returns whether it is private.
	 *
	 * @param int $folder_id Folder term ID.
	 * @return bool|WP_Error True when private, false when public, WP_Error on failure.
	 */
	private function validate_folder_access( int $folder_id ) {
		$folder = $this->repository->find( $folder_id );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		if ( ! $this->access->can_access_folder( $folder_id ) ) {
			return new WP_Error(
				'media_manager_forbidden_folder',
				__( 'You cannot assign media to that folder.', 'media-manager' ),
				[ 'status' => 403 ]
			);
		}

		$policy = $this->policy->get_effective( $folder_id );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		return ! empty( $policy['is_private'] );
	}
}
