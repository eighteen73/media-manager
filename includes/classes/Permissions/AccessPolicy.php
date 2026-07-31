<?php
/**
 * Access policy helpers for folder operations.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Capability-based access checks.
 */
class AccessPolicy {

	/**
	 * Whether the user can view folders.
	 *
	 * @param int $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public function can_view_folders( int $user_id = 0 ): bool {
		return $this->user_can( Capabilities::VIEW, $user_id );
	}

	/**
	 * Whether the user can edit folders.
	 *
	 * @param int $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public function can_edit_folders( int $user_id = 0 ): bool {
		return $this->user_can( Capabilities::EDIT, $user_id );
	}

	/**
	 * Whether the user can delete folders.
	 *
	 * @param int $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public function can_delete_folders( int $user_id = 0 ): bool {
		return $this->user_can( Capabilities::DELETE, $user_id );
	}

	/**
	 * Whether the user can assign folders.
	 *
	 * @param int $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public function can_assign_folders( int $user_id = 0 ): bool {
		return $this->user_can( Capabilities::ASSIGN, $user_id );
	}

	/**
	 * Whether the user can manage settings.
	 *
	 * @param int $user_id Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public function can_manage_settings( int $user_id = 0 ): bool {
		return $this->user_can( Capabilities::SETTINGS, $user_id );
	}

	/**
	 * Whether a user has a capability.
	 *
	 * @param string $capability Capability name.
	 * @param int    $user_id    Optional user ID.
	 * @return bool
	 */
	private function user_can( string $capability, int $user_id = 0 ): bool {
		if ( $user_id > 0 ) {
			return user_can( $user_id, $capability );
		}

		return current_user_can( $capability );
	}
}
