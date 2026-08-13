<?php
/**
 * Effective folder ACL resolution.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Permissions;

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderTaxonomy;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves accessible folders and attachment visibility for a user.
 */
class EffectiveAcl {

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Folder policy helper.
	 *
	 * @var FolderPolicy
	 */
	private FolderPolicy $policy;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Per-request memo of accessible folder IDs by user.
	 *
	 * @var array<int, int[]>
	 */
	private array $accessible_memo = [];

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null $repository Optional repository.
	 * @param FolderPolicy|null     $policy     Optional policy helper.
	 * @param AccessPolicy|null     $access     Optional access policy.
	 */
	public function __construct(
		?FolderRepository $repository = null,
		?FolderPolicy $policy = null,
		?AccessPolicy $access = null
	) {
		$this->repository = $repository ?? new FolderRepository();
		$this->policy     = $policy ?? new FolderPolicy( $this->repository );
		$this->access     = $access ?? new AccessPolicy();
	}

	/**
	 * Access signature for cache keys.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function access_signature( int $user_id = 0 ): string {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$user    = get_userdata( $user_id );
		$roles   = $user instanceof WP_User ? implode( ',', (array) $user->roles ) : '';

		return $user_id . '|' . $roles . '|' . ( $this->access->can_edit_folders( $user_id ) ? '1' : '0' );
	}

	/**
	 * Folder IDs the user may access.
	 *
	 * @param int $user_id Optional user ID.
	 * @return int[]
	 */
	public function accessible_folder_ids( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		if ( isset( $this->accessible_memo[ $user_id ] ) ) {
			return $this->accessible_memo[ $user_id ];
		}

		if ( $user_id < 1 || ! $this->access->can_view_folders( $user_id ) ) {
			$this->accessible_memo[ $user_id ] = [];
			return [];
		}

		$cache_key = AclCache::key( $user_id, 'folders|' . $this->access_signature( $user_id ) );
		$cached    = AclCache::get( $cache_key );

		if ( is_array( $cached ) ) {
			$this->accessible_memo[ $user_id ] = $cached;
			return $cached;
		}

		$ids = [];

		foreach ( $this->repository->all() as $term ) {
			$folder_id = (int) $term->term_id;
			if ( $this->can_access_folder( $folder_id, $user_id ) ) {
				$ids[] = $folder_id;
			}
		}

		AclCache::set( $cache_key, $ids );
		$this->accessible_memo[ $user_id ] = $ids;

		return $ids;
	}

	/**
	 * Whether the user can access a folder.
	 *
	 * @param int $folder_id Folder ID.
	 * @param int $user_id   Optional user ID.
	 * @return bool
	 */
	public function can_access_folder( int $folder_id, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		if ( $user_id < 1 || ! $this->access->can_view_folders( $user_id ) ) {
			return false;
		}

		if ( $this->access->can_edit_folders( $user_id ) ) {
			return true;
		}

		$effective = $this->policy->get_effective( $folder_id );

		if ( is_wp_error( $effective ) ) {
			return false;
		}

		if ( ! empty( $effective['is_private'] ) && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) && ! is_super_admin( $user_id ) ) {
			return false;
		}

		return $this->policy->user_matches( $effective, $user_id );
	}

	/**
	 * Whether the user can view an attachment via folder ACL.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       Optional user ID.
	 * @return bool
	 */
	public function can_view_attachment( int $attachment_id, int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		if ( $this->access->can_edit_folders( $user_id ) ) {
			return true;
		}

		$folder_ids = $this->repository->attachment_folder_ids( $attachment_id );

		if ( empty( $folder_ids ) ) {
			return $this->access->can_view_folders( $user_id );
		}

		$accessible = $this->accessible_folder_ids( $user_id );

		foreach ( $folder_ids as $folder_id ) {
			if ( in_array( (int) $folder_id, $accessible, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Accessible folder IDs assigned to an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       Optional user ID.
	 * @return int[]
	 */
	public function visible_attachment_folder_ids( int $attachment_id, int $user_id = 0 ): array {
		$assigned   = $this->repository->attachment_folder_ids( $attachment_id );
		$accessible = $this->accessible_folder_ids( $user_id );

		return array_values( array_intersect( $assigned, $accessible ) );
	}

	/**
	 * Filter a nested folder tree to accessible nodes and attach policy fields.
	 *
	 * Inaccessible folders are omitted entirely. Accessible descendants are
	 * promoted under the nearest accessible ancestor (or root).
	 *
	 * @param array $folders Nested folders.
	 * @param int   $user_id Optional user ID.
	 * @return array
	 */
	public function filter_tree( array $folders, int $user_id = 0 ): array {
		$accessible = array_fill_keys( $this->accessible_folder_ids( $user_id ), true );

		return $this->filter_tree_nodes( $folders, $accessible );
	}

	/**
	 * Recursively filter and promote accessible nodes.
	 *
	 * @param array            $folders    Nested folders.
	 * @param array<int, true> $accessible Accessible ID map.
	 * @return array
	 */
	private function filter_tree_nodes( array $folders, array $accessible ): array {
		$filtered = [];

		foreach ( $folders as $node ) {
			$id       = (int) ( $node['id'] ?? 0 );
			$children = $this->filter_tree_nodes( $node['children'] ?? [], $accessible );

			if ( ! isset( $accessible[ $id ] ) ) {
				foreach ( $children as $child ) {
					$filtered[] = $child;
				}
				continue;
			}

			$policy = $this->policy->get( $id );
			if ( ! is_wp_error( $policy ) ) {
				$node['access_mode']       = $policy['access_mode'];
				$node['allowed_roles']     = $policy['allowed_roles'];
				$node['allowed_users']     = $policy['allowed_users'];
				$node['allowed_usernames'] = $this->policy->user_ids_to_logins( $policy['allowed_users'] );
				$node['is_private']        = $policy['is_private'];
			}

			$node['children'] = $children;
			$filtered[]       = $node;
		}

		return $filtered;
	}

	/**
	 * Apply ACL visibility constraints to a WP_Query-style args array.
	 *
	 * @param array    $query     Query args.
	 * @param int|null $folder_id Folder selection (null=all, 0=uncategorized).
	 * @param int      $user_id   Optional user ID.
	 * @return array
	 */
	public function apply_to_query( array $query, $folder_id, int $user_id = 0 ): array {
		$user_id    = $user_id > 0 ? $user_id : get_current_user_id();
		$accessible = $this->accessible_folder_ids( $user_id );

		if ( $this->access->can_edit_folders( $user_id ) ) {
			return $query;
		}

		$tax_query = isset( $query['tax_query'] ) && is_array( $query['tax_query'] )
			? $query['tax_query']
			: [];

		if ( null === $folder_id ) {
			// All Media: uncategorized OR in an accessible folder.
			$visibility = [
				'relation' => 'OR',
				[
					'taxonomy' => FolderTaxonomy::NAME,
					'operator' => 'NOT EXISTS',
				],
			];

			if ( ! empty( $accessible ) ) {
				$visibility[] = [
					'taxonomy'         => FolderTaxonomy::NAME,
					'field'            => 'term_id',
					'terms'            => $accessible,
					'include_children' => false,
					'operator'         => 'IN',
				];
			}

			$tax_query[] = $visibility;
		} elseif ( 0 !== (int) $folder_id && ! in_array( (int) $folder_id, $accessible, true ) ) {
			$query['post__in'] = [ 0 ];
			return $query;
		}

		if ( count( $tax_query ) > 1 && ! isset( $tax_query['relation'] ) ) {
			$tax_query['relation'] = 'AND';
		}

		if ( ! empty( $tax_query ) ) {
			$query['tax_query'] = $tax_query;
		}

		return $query;
	}

	/**
	 * Clear per-request memo (tests / after mutations).
	 *
	 * @return void
	 */
	public function clear_memo(): void {
		$this->accessible_memo = [];
	}
}
