<?php
/**
 * User preferences REST controller.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Rest;

use Eighteen73\MediaManager\Permissions\AccessPolicy;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Current-user folder UI preferences.
 */
class PreferencesController extends WP_REST_Controller {

	/**
	 * Sidebar width user meta key.
	 *
	 * @var string
	 */
	public const META_WIDTH = 'media_manager_sidebar_width';

	/**
	 * Sidebar collapsed user meta key.
	 *
	 * @var string
	 */
	public const META_COLLAPSED = 'media_manager_sidebar_collapsed';

	/**
	 * Previous folder user meta key.
	 *
	 * @var string
	 */
	public const META_PREVIOUS = 'media_manager_previous_folder';

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy|null $access Optional access policy.
	 */
	public function __construct( ?AccessPolicy $access = null ) {
		$this->namespace = 'media-manager/v1';
		$this->rest_base = 'preferences';
		$this->access    = $access ?? new AccessPolicy();
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
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'permissions_check' ],
					'args'                => [
						'sidebar_width'     => [
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						],
						'sidebar_collapsed' => [
							'type'              => 'boolean',
							'sanitize_callback' => 'rest_sanitize_boolean',
						],
						'previous_folder'   => [
							'sanitize_callback' => [ $this, 'sanitize_previous_folder' ],
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
	public function permissions_check( $request ) {
		unset( $request );

		if ( ! is_user_logged_in() || ! $this->access->can_view_folders() ) {
			return new WP_Error(
				'media_manager_forbidden',
				__( 'Sorry, you are not allowed to manage folder preferences.', 'media-manager' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Sanitize previous folder preference.
	 *
	 * @param mixed $value Raw value.
	 * @return int|null
	 */
	public function sanitize_previous_folder( $value ) {
		if ( null === $value || '' === $value || 'all' === $value ) {
			return null;
		}

		return absint( $value );
	}

	/**
	 * Get preferences.
	 *
	 * @param WP_REST_Request|null $request Optional request.
	 * @return WP_REST_Response
	 */
	public function get_item( $request = null ) {
		unset( $request );

		$user_id = get_current_user_id();
		$width   = (int) get_user_meta( $user_id, self::META_WIDTH, true );
		$width   = $width > 0 ? min( 600, max( 180, $width ) ) : 300;

		$collapsed = get_user_meta( $user_id, self::META_COLLAPSED, true );
		$previous  = get_user_meta( $user_id, self::META_PREVIOUS, true );

		return rest_ensure_response(
			[
				'sidebar_width'     => $width,
				'sidebar_collapsed' => '1' === (string) $collapsed || true === $collapsed,
				'previous_folder'   => ( '' === $previous || false === $previous ) ? null : (int) $previous,
			]
		);
	}

	/**
	 * Update preferences.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_item( $request ) {
		$user_id = get_current_user_id();

		if ( null !== $request->get_param( 'sidebar_width' ) ) {
			$width = min( 600, max( 180, (int) $request->get_param( 'sidebar_width' ) ) );
			update_user_meta( $user_id, self::META_WIDTH, $width );
		}

		if ( null !== $request->get_param( 'sidebar_collapsed' ) ) {
			update_user_meta(
				$user_id,
				self::META_COLLAPSED,
				(bool) $request->get_param( 'sidebar_collapsed' ) ? '1' : '0'
			);
		}

		if ( $request->offsetExists( 'previous_folder' ) ) {
			$previous = $request->get_param( 'previous_folder' );

			if ( null === $previous ) {
				delete_user_meta( $user_id, self::META_PREVIOUS );
			} else {
				update_user_meta( $user_id, self::META_PREVIOUS, (int) $previous );
			}
		}

		return $this->get_item();
	}
}
