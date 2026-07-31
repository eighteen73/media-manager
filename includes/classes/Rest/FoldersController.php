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
	 * Constructor.
	 *
	 * @param AccessPolicy|null  $access  Optional access policy.
	 * @param FolderService|null $service Optional service.
	 * @param FolderTree|null    $tree    Optional tree builder.
	 */
	public function __construct(
		?AccessPolicy $access = null,
		?FolderService $service = null,
		?FolderTree $tree = null
	) {
		$this->namespace = 'media-manager/v1';
		$this->rest_base = 'folders';
		$this->access    = $access ?? new AccessPolicy();
		$this->service   = $service ?? new FolderService();
		$this->tree      = $tree ?? new FolderTree();
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
		$result = $this->service->create(
			(string) $request->get_param( 'name' ),
			(int) $request->get_param( 'parent' )
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
		$result = $this->service->update(
			(int) $request['id'],
			(string) $request->get_param( 'name' ),
			(int) $request->get_param( 'parent' )
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
				'id'     => [
					'type'     => 'integer',
					'readonly' => true,
				],
				'name'   => [
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'parent' => [
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				],
			],
		];

		return $this->schema;
	}

	/**
	 * Prepare a folder resource.
	 *
	 * @param WP_Term $term Folder term.
	 * @return array
	 */
	private function prepare_folder( WP_Term $term ): array {
		return [
			'id'     => (int) $term->term_id,
			'name'   => $term->name,
			'parent' => (int) $term->parent,
			'count'  => (int) $term->count,
		];
	}
}
