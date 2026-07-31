<?php
/**
 * Folders REST controller.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Rest;

use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Folders\FolderTree;
use Eighteen73\MediaManager\Permissions\AccessPolicy;
use Eighteen73\MediaManager\Permissions\FolderPolicy;
use Eighteen73\MediaManager\PrivateMedia\StorageConfig;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints for folder CRUD and tree listing.
 */
class FoldersController extends WP_REST_Controller {

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Folder service.
	 *
	 * @var FolderService
	 */
	private FolderService $service;

	/**
	 * Folder tree builder.
	 *
	 * @var FolderTree
	 */
	private FolderTree $tree;

	/**
	 * Folder policy helper.
	 *
	 * @var FolderPolicy
	 */
	private FolderPolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy|null  $access  Optional access policy.
	 * @param FolderService|null $service Optional service.
	 * @param FolderTree|null    $tree    Optional tree builder.
	 * @param FolderPolicy|null  $policy  Optional policy helper.
	 */
	public function __construct(
		?AccessPolicy $access = null,
		?FolderService $service = null,
		?FolderTree $tree = null,
		?FolderPolicy $policy = null
	) {
		$this->namespace = 'media-manager/v1';
		$this->rest_base = 'folders';
		$this->access    = $access ?? new AccessPolicy();
		$this->service   = $service ?? new FolderService();
		$this->tree      = $tree ?? new FolderTree();
		$this->policy    = $policy ?? $this->service->policy();
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
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => [
						'recursive_counts' => [
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
				],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
				],
			]
		);
	}

	/**
	 * GET permissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );

		if ( ! $this->access->can_view_folders() ) {
			return new WP_Error(
				'media_manager_forbidden',
				__( 'Sorry, you are not allowed to view folders.', 'media-manager' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * CREATE permissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		unset( $request );

		if ( ! $this->access->can_edit_folders() ) {
			return new WP_Error(
				'media_manager_forbidden',
				__( 'Sorry, you are not allowed to create folders.', 'media-manager' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * UPDATE permissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return $this->create_item_permissions_check( $request );
	}

	/**
	 * DELETE permissions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		unset( $request );

		if ( ! $this->access->can_delete_folders() ) {
			return new WP_Error(
				'media_manager_forbidden',
				__( 'Sorry, you are not allowed to delete folders.', 'media-manager' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * List folders as a nested tree.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$recursive = (bool) $request->get_param( 'recursive_counts' );
		$tree      = $this->tree->get_tree( $recursive );

		return rest_ensure_response(
			[
				'summary' => $tree['summary'] ?? [
					'all_count'           => 0,
					'uncategorized_count' => 0,
				],
				'folders' => $tree['folders'] ?? [],
			]
		);
	}

	/**
	 * Create a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$policy = $this->policy_from_request( $request );

		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		$result = $this->service->create(
			(string) $request->get_param( 'name' ),
			(int) $request->get_param( 'parent' ),
			$policy
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( $this->prepare_folder( $result ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Update a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$policy = null;
		if ( $this->request_has_policy( $request ) ) {
			$policy = $this->policy_from_request( $request );
			if ( is_wp_error( $policy ) ) {
				return $policy;
			}
		}

		$result = $this->service->update(
			(int) $request['id'],
			(string) $request->get_param( 'name' ),
			(int) $request->get_param( 'parent' ),
			$policy
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $this->prepare_folder( $result ) );
	}

	/**
	 * Delete a folder.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$result = $this->service->delete( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			[
				'deleted' => true,
				'id'      => (int) $request['id'],
			]
		);
	}

	/**
	 * Item schema args.
	 *
	 * @return array
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->schema;
		}

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'media-manager-folder',
			'type'       => 'object',
			'properties' => [
				'id'                => [
					'type'     => 'integer',
					'readonly' => true,
				],
				'name'              => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'parent'            => [
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
				'access_mode'       => [
					'type'              => 'string',
					'enum'              => [
						FolderPolicy::MODE_UNRESTRICTED,
						FolderPolicy::MODE_ROLES,
						FolderPolicy::MODE_USERS,
					],
					'sanitize_callback' => 'sanitize_key',
				],
				'allowed_roles'     => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'allowed_users'     => [
					'type'  => 'array',
					'items' => [
						'type' => [ 'integer', 'string' ],
					],
				],
				'allowed_usernames' => [
					'type'  => 'array',
					'items' => [ 'type' => 'string' ],
				],
				'is_private'        => [
					'type' => 'boolean',
				],
			],
		];

		return $this->schema;
	}

	/**
	 * Whether the request includes policy fields.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function request_has_policy( WP_REST_Request $request ): bool {
		foreach ( [ 'access_mode', 'allowed_roles', 'allowed_users', 'allowed_usernames', 'is_private' ] as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a policy array from the request, enforcing private-cap and storage readiness.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error
	 */
	private function policy_from_request( WP_REST_Request $request ) {
		$policy = [
			'access_mode'       => $request->get_param( 'access_mode' ) ?: FolderPolicy::MODE_UNRESTRICTED,
			'allowed_roles'     => $request->get_param( 'allowed_roles' ) ?: [],
			'allowed_users'     => $request->get_param( 'allowed_users' ) ?: [],
			'allowed_usernames' => $request->get_param( 'allowed_usernames' ) ?: [],
			'is_private'        => (bool) $request->get_param( 'is_private' ),
		];

		$folder_id   = isset( $request['id'] ) ? absint( $request['id'] ) : 0;
		$was_private = false;
		if ( $folder_id > 0 ) {
			$current     = $this->policy->get( $folder_id );
			$was_private = ! is_wp_error( $current ) && ! empty( $current['is_private'] );
		}

		if ( ! empty( $policy['is_private'] ) || ( $was_private && empty( $policy['is_private'] ) ) ) {
			if ( ! $this->access->can_manage_private_folders() ) {
				return new WP_Error(
					'media_manager_forbidden',
					__( 'Sorry, you are not allowed to manage private folders.', 'media-manager' ),
					[ 'status' => rest_authorization_required_code() ]
				);
			}
		}

		if ( ! empty( $policy['is_private'] ) && ! ( new StorageConfig() )->is_ready() ) {
			return new WP_Error(
				'media_manager_private_storage_unready',
				__( 'Configure a healthy private storage path before marking folders private.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		return $policy;
	}

	/**
	 * Prepare a folder resource.
	 *
	 * @param WP_Term $term Folder term.
	 * @return array
	 */
	private function prepare_folder( WP_Term $term ): array {
		$policy = $this->policy->get( (int) $term->term_id );
		$policy = is_wp_error( $policy ) ? $this->policy->defaults() : $policy;
		$flags  = ( new \Eighteen73\MediaManager\ContentFolders\ContentFolderRepository() )->flags( (int) $term->term_id );

		return array_merge(
			[
				'id'                => (int) $term->term_id,
				'name'              => $term->name,
				'parent'            => (int) $term->parent,
				'count'             => (int) $term->count,
				'access_mode'       => $policy['access_mode'],
				'allowed_roles'     => $policy['allowed_roles'],
				'allowed_users'     => $policy['allowed_users'],
				'allowed_usernames' => $this->policy->user_ids_to_logins( $policy['allowed_users'] ),
				'is_private'        => $policy['is_private'],
			],
			$flags
		);
	}
}
