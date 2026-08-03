<?php
/**
 * Shared media REST endpoints (folder tree from the network library).
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\SharedMedia;

use Eighteen73\MediaManager\Folders\FolderTree;
use Eighteen73\MediaManager\Permissions\AccessPolicy;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only shared folder tree for the Shared media tab.
 */
class SharedFoldersController extends WP_REST_Controller {

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Folder tree builder.
	 *
	 * @var FolderTree
	 */
	private FolderTree $tree;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy|null $access Optional access policy.
	 * @param FolderTree|null   $tree   Optional tree builder.
	 */
	public function __construct( ?AccessPolicy $access = null, ?FolderTree $tree = null ) {
		$this->namespace = 'media-manager/v1';
		$this->rest_base = 'shared-folders';
		$this->access    = $access ?? new AccessPolicy();
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
			]
		);
	}

	/**
	 * Permission check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		unset( $request );

		if ( ! NetworkLibrary::current_user_can_use() ) {
			return new WP_Error(
				'media_manager_shared_forbidden',
				__( 'Shared media is not available.', 'media-manager' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Return the network library folder tree (ACL filtered on that site).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$library_id       = NetworkLibrary::get_library_id();
		$recursive_counts = (bool) $request->get_param( 'recursive_counts' );
		$payload          = null;

		switch_to_blog( $library_id );
		try {
			if ( ! $this->access->can_view_folders() ) {
				return new WP_Error(
					'media_manager_forbidden',
					__( 'You cannot view folders.', 'media-manager' ),
					[ 'status' => 403 ]
				);
			}

			$payload = $this->tree->get_tree( $recursive_counts );
		} finally {
			restore_current_blog();
		}

		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		return rest_ensure_response( $payload );
	}
}
