<?php
/**
 * Folder access and privacy policy helpers.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Permissions;

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderTaxonomy;
use WP_Error;
use WP_Term;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates per-folder ACL and private flags.
 */
class FolderPolicy {

	/**
	 * Access mode: unrestricted.
	 *
	 * @var string
	 */
	public const MODE_UNRESTRICTED = 'unrestricted';

	/**
	 * Access mode: role-based.
	 *
	 * @var string
	 */
	public const MODE_ROLES = 'roles';

	/**
	 * Access mode: user-based.
	 *
	 * @var string
	 */
	public const MODE_USERS = 'users';

	/**
	 * Term meta: access mode.
	 *
	 * @var string
	 */
	public const META_ACCESS_MODE = 'media_manager_access_mode';

	/**
	 * Term meta: allowed roles.
	 *
	 * @var string
	 */
	public const META_ALLOWED_ROLES = 'media_manager_allowed_roles';

	/**
	 * Term meta: allowed users.
	 *
	 * @var string
	 */
	public const META_ALLOWED_USERS = 'media_manager_allowed_users';

	/**
	 * Term meta: private flag.
	 *
	 * @var string
	 */
	public const META_IS_PRIVATE = 'media_manager_is_private';

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null $repository Optional repository.
	 */
	public function __construct( ?FolderRepository $repository = null ) {
		$this->repository = $repository ?? new FolderRepository();
	}

	/**
	 * Default policy array.
	 *
	 * @return array{access_mode: string, allowed_roles: string[], allowed_users: int[], is_private: bool}
	 */
	public function defaults(): array {
		return [
			'access_mode'   => self::MODE_UNRESTRICTED,
			'allowed_roles' => [],
			'allowed_users' => [],
			'is_private'    => false,
		];
	}

	/**
	 * Read stored policy for a folder (own meta only, not inherited).
	 *
	 * @param int $folder_id Folder ID.
	 * @return array{access_mode: string, allowed_roles: string[], allowed_users: int[], is_private: bool}|WP_Error
	 */
	public function get( int $folder_id ) {
		$folder = $this->repository->find( $folder_id );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		$mode = (string) get_term_meta( $folder_id, self::META_ACCESS_MODE, true );
		if ( ! in_array( $mode, [ self::MODE_UNRESTRICTED, self::MODE_ROLES, self::MODE_USERS ], true ) ) {
			$mode = self::MODE_UNRESTRICTED;
		}

		$roles = get_term_meta( $folder_id, self::META_ALLOWED_ROLES, true );
		$roles = is_array( $roles ) ? array_values( array_filter( array_map( 'sanitize_key', $roles ) ) ) : [];

		$users = get_term_meta( $folder_id, self::META_ALLOWED_USERS, true );
		$users = is_array( $users ) ? array_values( array_filter( array_map( 'absint', $users ) ) ) : [];

		return [
			'access_mode'   => $mode,
			'allowed_roles' => $roles,
			'allowed_users' => $users,
			'is_private'    => (bool) get_term_meta( $folder_id, self::META_IS_PRIVATE, true ),
		];
	}

	/**
	 * Effective policy after ancestor inheritance (intersection / narrow-only).
	 *
	 * @param int $folder_id Folder ID.
	 * @return array{access_mode: string, allowed_roles: string[], allowed_users: int[], is_private: bool}|WP_Error
	 */
	public function get_effective( int $folder_id ) {
		$own = $this->get( $folder_id );

		if ( is_wp_error( $own ) ) {
			return $own;
		}

		$ancestors = get_ancestors( $folder_id, FolderTaxonomy::NAME, 'taxonomy' );
		$chain     = array_reverse( array_map( 'intval', $ancestors ) );
		$chain[]   = $folder_id;

		$effective = $this->defaults();

		foreach ( $chain as $id ) {
			$policy = ( $id === $folder_id ) ? $own : $this->get( $id );

			if ( is_wp_error( $policy ) ) {
				return $policy;
			}

			$effective = $this->merge_narrow( $effective, $policy );
		}

		return $effective;
	}

	/**
	 * Persist a folder policy after validation against the parent.
	 *
	 * @param int   $folder_id Folder ID.
	 * @param array $policy    Policy payload.
	 * @param int   $parent_id Parent folder ID (for inheritance checks).
	 * @return true|WP_Error
	 */
	public function save( int $folder_id, array $policy, int $parent_id = -1 ) {
		$folder = $this->repository->find( $folder_id );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		if ( $parent_id < 0 ) {
			$parent_id = (int) $folder->parent;
		}

		$normalised = $this->normalise( $policy );

		if ( is_wp_error( $normalised ) ) {
			return $normalised;
		}

		$valid = $this->validate_against_parent( $normalised, $parent_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		update_term_meta( $folder_id, self::META_ACCESS_MODE, $normalised['access_mode'] );
		update_term_meta( $folder_id, self::META_ALLOWED_ROLES, $normalised['allowed_roles'] );
		update_term_meta( $folder_id, self::META_ALLOWED_USERS, $normalised['allowed_users'] );
		update_term_meta( $folder_id, self::META_IS_PRIVATE, $normalised['is_private'] ? 1 : 0 );

		AclCache::invalidate_site();

		return true;
	}

	/**
	 * Normalise and sanitise a policy payload.
	 *
	 * @param array $policy Raw policy.
	 * @return array{access_mode: string, allowed_roles: string[], allowed_users: int[], is_private: bool}|WP_Error
	 */
	public function normalise( array $policy ) {
		$defaults = $this->defaults();
		$mode     = isset( $policy['access_mode'] ) ? sanitize_key( (string) $policy['access_mode'] ) : $defaults['access_mode'];

		if ( ! in_array( $mode, [ self::MODE_UNRESTRICTED, self::MODE_ROLES, self::MODE_USERS ], true ) ) {
			return new WP_Error(
				'media_manager_invalid_access_mode',
				__( 'Invalid folder access mode.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$roles = [];
		if ( ! empty( $policy['allowed_roles'] ) && is_array( $policy['allowed_roles'] ) ) {
			$wp_roles = wp_roles()->get_names();
			foreach ( $policy['allowed_roles'] as $role ) {
				$role = sanitize_key( (string) $role );
				if ( isset( $wp_roles[ $role ] ) ) {
					$roles[] = $role;
				}
			}
			$roles = array_values( array_unique( $roles ) );
		}

		$users = [];
		if ( ! empty( $policy['allowed_users'] ) && is_array( $policy['allowed_users'] ) ) {
			foreach ( $policy['allowed_users'] as $user ) {
				$user_id = $this->resolve_user_id( $user );
				if ( $user_id > 0 ) {
					$users[] = $user_id;
				}
			}
			$users = array_values( array_unique( $users ) );
		}

		if ( ! empty( $policy['allowed_usernames'] ) && is_array( $policy['allowed_usernames'] ) ) {
			foreach ( $policy['allowed_usernames'] as $login ) {
				$user_id = $this->resolve_user_id( $login );
				if ( $user_id > 0 ) {
					$users[] = $user_id;
				}
			}
			$users = array_values( array_unique( $users ) );
		}

		if ( self::MODE_ROLES === $mode && empty( $roles ) ) {
			return new WP_Error(
				'media_manager_missing_roles',
				__( 'Select at least one role when restricting by roles.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		if ( self::MODE_USERS === $mode && empty( $users ) ) {
			return new WP_Error(
				'media_manager_missing_users',
				__( 'Provide at least one username when restricting by usernames.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		if ( self::MODE_UNRESTRICTED === $mode ) {
			$roles = [];
			$users = [];
		} elseif ( self::MODE_ROLES === $mode ) {
			$users = [];
		} else {
			$roles = [];
		}

		return [
			'access_mode'   => $mode,
			'allowed_roles' => $roles,
			'allowed_users' => $users,
			'is_private'    => ! empty( $policy['is_private'] ),
		];
	}

	/**
	 * Ensure a child policy does not widen parent restrictions.
	 *
	 * @param array $policy    Candidate policy.
	 * @param int   $parent_id Parent folder ID.
	 * @return true|WP_Error
	 */
	public function validate_against_parent( array $policy, int $parent_id ) {
		if ( $parent_id < 1 ) {
			return true;
		}

		$parent = $this->get_effective( $parent_id );

		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		if ( ! empty( $parent['is_private'] ) && empty( $policy['is_private'] ) ) {
			return new WP_Error(
				'media_manager_private_required',
				__( 'Child folders of a private folder must also be private.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		if ( self::MODE_UNRESTRICTED === $parent['access_mode'] ) {
			return true;
		}

		if ( self::MODE_UNRESTRICTED === $policy['access_mode'] ) {
			return new WP_Error(
				'media_manager_access_widened',
				__( 'Child folders cannot remove a parent access restriction.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		if ( self::MODE_ROLES === $parent['access_mode'] ) {
			if ( self::MODE_ROLES === $policy['access_mode'] ) {
				$extra = array_diff( $policy['allowed_roles'], $parent['allowed_roles'] );
				if ( ! empty( $extra ) ) {
					return new WP_Error(
						'media_manager_access_widened',
						__( 'Child folders may only narrow the parent role list.', 'media-manager' ),
						[ 'status' => 400 ]
					);
				}
				return true;
			}

			if ( self::MODE_USERS === $policy['access_mode'] ) {
				foreach ( $policy['allowed_users'] as $user_id ) {
					if ( ! $this->user_has_any_role( $user_id, $parent['allowed_roles'] ) ) {
						return new WP_Error(
							'media_manager_access_widened',
							__( 'Allowed users must belong to a parent-allowed role.', 'media-manager' ),
							[ 'status' => 400 ]
						);
					}
				}
				return true;
			}
		}

		if ( self::MODE_USERS === $parent['access_mode'] ) {
			if ( self::MODE_ROLES === $policy['access_mode'] ) {
				return new WP_Error(
					'media_manager_access_widened',
					__( 'A user-restricted parent cannot be widened to roles.', 'media-manager' ),
					[ 'status' => 400 ]
				);
			}

			$extra = array_diff( $policy['allowed_users'], $parent['allowed_users'] );
			if ( ! empty( $extra ) ) {
				return new WP_Error(
					'media_manager_access_widened',
					__( 'Child folders may only narrow the parent user list.', 'media-manager' ),
					[ 'status' => 400 ]
				);
			}
		}

		return true;
	}

	/**
	 * Whether a user satisfies a concrete (effective) policy.
	 *
	 * @param array        $policy  Effective policy.
	 * @param int          $user_id User ID.
	 * @param WP_User|null $user Optional user object.
	 * @return bool
	 */
	public function user_matches( array $policy, int $user_id, ?WP_User $user = null ): bool {
		if ( $user_id < 1 ) {
			return false;
		}

		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( $user_id );
		}

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		// Unrestricted (including private+unrestricted for authenticated members) is open.
		if ( self::MODE_UNRESTRICTED === $policy['access_mode'] ) {
			return true;
		}

		if ( self::MODE_ROLES === $policy['access_mode'] ) {
			return $this->user_has_any_role( $user_id, $policy['allowed_roles'], $user );
		}

		return in_array( $user_id, $policy['allowed_users'], true );
	}

	/**
	 * Resolve a user ID from an ID or login.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public function resolve_user_id( $value ): int {
		if ( is_numeric( $value ) ) {
			$id = absint( $value );
			return $id > 0 && get_userdata( $id ) ? $id : 0;
		}

		$login = sanitize_user( (string) $value, true );
		if ( '' === $login ) {
			return 0;
		}

		$user = get_user_by( 'login', $login );

		return $user instanceof WP_User ? (int) $user->ID : 0;
	}

	/**
	 * Map user IDs to login names for UI.
	 *
	 * @param int[] $user_ids User IDs.
	 * @return string[]
	 */
	public function user_ids_to_logins( array $user_ids ): array {
		$logins = [];

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user instanceof WP_User ) {
				$logins[] = $user->user_login;
			}
		}

		return $logins;
	}

	/**
	 * Merge policies so the result is at least as narrow as both.
	 *
	 * @param array $base   Running effective policy.
	 * @param array $next   Next policy in the chain.
	 * @return array
	 */
	private function merge_narrow( array $base, array $next ): array {
		$merged               = $base;
		$merged['is_private'] = ! empty( $base['is_private'] ) || ! empty( $next['is_private'] );

		if ( self::MODE_UNRESTRICTED === $base['access_mode'] ) {
			$merged['access_mode']   = $next['access_mode'];
			$merged['allowed_roles'] = $next['allowed_roles'];
			$merged['allowed_users'] = $next['allowed_users'];
			return $merged;
		}

		if ( self::MODE_UNRESTRICTED === $next['access_mode'] ) {
			return $merged;
		}

		if ( self::MODE_USERS === $base['access_mode'] || self::MODE_USERS === $next['access_mode'] ) {
			$base_users = self::MODE_USERS === $base['access_mode']
				? $base['allowed_users']
				: $this->users_with_roles( $base['allowed_roles'] );
			$next_users = self::MODE_USERS === $next['access_mode']
				? $next['allowed_users']
				: $this->users_with_roles( $next['allowed_roles'] );

			$merged['access_mode']   = self::MODE_USERS;
			$merged['allowed_roles'] = [];
			$merged['allowed_users'] = array_values( array_intersect( $base_users, $next_users ) );
			return $merged;
		}

		$merged['access_mode']   = self::MODE_ROLES;
		$merged['allowed_roles'] = array_values( array_intersect( $base['allowed_roles'], $next['allowed_roles'] ) );
		$merged['allowed_users'] = [];

		return $merged;
	}

	/**
	 * Whether a user has any of the given roles.
	 *
	 * @param int          $user_id User ID.
	 * @param string[]     $roles   Role slugs.
	 * @param WP_User|null $user    Optional user.
	 * @return bool
	 */
	private function user_has_any_role( int $user_id, array $roles, ?WP_User $user = null ): bool {
		if ( empty( $roles ) ) {
			return false;
		}

		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( $user_id );
		}

		if ( ! $user instanceof WP_User ) {
			return false;
		}

		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	/**
	 * User IDs that currently have any of the given roles (best-effort for inheritance merge).
	 *
	 * @param string[] $roles Role slugs.
	 * @return int[]
	 */
	private function users_with_roles( array $roles ): array {
		if ( empty( $roles ) ) {
			return [];
		}

		$ids = get_users(
			[
				'role__in' => $roles,
				'fields'   => 'ID',
			]
		);

		return array_map( 'intval', (array) $ids );
	}
}
