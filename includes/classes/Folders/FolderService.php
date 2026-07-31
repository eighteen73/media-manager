<?php
/**
 * Folder create/update/delete service.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

use Eighteen73\MediaManager\ContentFolders\ContentFolderService;
use Eighteen73\MediaManager\Permissions\AclCache;
use Eighteen73\MediaManager\Permissions\FolderPolicy;
use Eighteen73\MediaManager\PrivateMedia\TransitionService;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Authoritative folder mutation service.
 */
class FolderService {

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Hierarchy validator.
	 *
	 * @var HierarchyValidator
	 */
	private HierarchyValidator $hierarchy;

	/**
	 * Folder policy helper.
	 *
	 * @var FolderPolicy
	 */
	private FolderPolicy $policy;

	/**
	 * Private transition service.
	 *
	 * @var TransitionService
	 */
	private TransitionService $transitions;

	/**
	 * Content folder service.
	 *
	 * @var ContentFolderService|null
	 */
	private ?ContentFolderService $content_folders = null;

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null     $repository      Optional repository.
	 * @param HierarchyValidator|null   $hierarchy       Optional validator.
	 * @param FolderPolicy|null         $policy          Optional policy helper.
	 * @param TransitionService|null    $transitions     Optional transitions.
	 * @param ContentFolderService|null $content_folders Optional content folders.
	 */
	public function __construct(
		?FolderRepository $repository = null,
		?HierarchyValidator $hierarchy = null,
		?FolderPolicy $policy = null,
		?TransitionService $transitions = null,
		?ContentFolderService $content_folders = null
	) {
		$this->repository      = $repository ?? new FolderRepository();
		$this->hierarchy       = $hierarchy ?? new HierarchyValidator( $this->repository );
		$this->policy          = $policy ?? new FolderPolicy( $this->repository );
		$this->transitions     = $transitions ?? new TransitionService( null, $this->repository, $this->policy );
		$this->content_folders = $content_folders;
	}

	/**
	 * Content folder service (lazy to avoid constructor cycles).
	 *
	 * @return ContentFolderService
	 */
	private function content_folders(): ContentFolderService {
		if ( null === $this->content_folders ) {
			$this->content_folders = new ContentFolderService( null, $this, $this->repository );
		}

		return $this->content_folders;
	}

	/**
	 * Create a folder.
	 *
	 * @param string $name      Folder name.
	 * @param int    $parent_id Parent folder ID.
	 * @param array  $policy    Optional ACL/privacy policy.
	 * @return WP_Term|WP_Error
	 */
	public function create( string $name, int $parent_id = 0, array $policy = [] ) {
		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return new WP_Error(
				'media_manager_invalid_folder_name',
				__( 'Folder name is required.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$valid = $this->hierarchy->validate_parent( 0, $parent_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// New folder is depth 1 under parent.
		$parent_depth = 0;
		if ( $parent_id > 0 ) {
			$parent = $this->repository->find( $parent_id );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
			$ancestors    = get_ancestors( $parent_id, FolderTaxonomy::NAME, 'taxonomy' );
			$parent_depth = 1 + count( $ancestors );
		}

		if ( ( $parent_depth + 1 ) > $this->hierarchy->max_depth() ) {
			return new WP_Error(
				'media_manager_max_depth',
				sprintf(
					/* translators: %d: maximum folder depth */
					__( 'Folders cannot nest more than %d levels deep.', 'media-manager' ),
					$this->hierarchy->max_depth()
				),
				[ 'status' => 400 ]
			);
		}

		if ( $parent_id > 0 && empty( $policy ) ) {
			$parent_policy = $this->policy->get_effective( $parent_id );
			if ( ! is_wp_error( $parent_policy ) && ! empty( $parent_policy['is_private'] ) ) {
				$policy['is_private'] = true;
			}
		}

		if ( ! empty( $policy ) ) {
			$normalised = $this->policy->normalise( array_merge( $this->policy->defaults(), $policy ) );
			if ( is_wp_error( $normalised ) ) {
				return $normalised;
			}
			$check = $this->policy->validate_against_parent( $normalised, $parent_id );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$policy = $normalised;
		}

		$result = $this->repository->insert( $name, $parent_id );

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				return new WP_Error(
					'media_manager_folder_exists',
					__( 'A folder with this name already exists under the selected parent.', 'media-manager' ),
					[ 'status' => 409 ]
				);
			}

			return $result;
		}

		$folder_id = (int) $result['term_id'];

		if ( ! empty( $policy ) ) {
			$saved = $this->policy->save( $folder_id, $policy, $parent_id );
			if ( is_wp_error( $saved ) ) {
				$this->repository->delete( $folder_id );
				return $saved;
			}
		} elseif ( $parent_id > 0 ) {
			$parent_policy = $this->policy->get_effective( $parent_id );
			if ( ! is_wp_error( $parent_policy ) && ! empty( $parent_policy['is_private'] ) ) {
				$this->policy->save(
					$folder_id,
					[
						'access_mode' => FolderPolicy::MODE_UNRESTRICTED,
						'is_private'  => true,
					],
					$parent_id
				);
			}
		}

		AclCache::invalidate_site();

		return $this->repository->find( $folder_id );
	}

	/**
	 * Update a folder.
	 *
	 * @param int        $folder_id Folder ID.
	 * @param string     $name      Folder name.
	 * @param int        $parent_id Parent folder ID.
	 * @param array|null $policy    Optional ACL/privacy policy (null = leave unchanged).
	 * @return WP_Term|WP_Error
	 */
	public function update( int $folder_id, string $name, int $parent_id, ?array $policy = null ) {
		$existing = $this->repository->find( $folder_id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( $this->content_folders()->is_mutation_locked( $folder_id ) ) {
			// Name and parent are owned by the post lifecycle; only ACL/privacy may change.
			$name      = (string) $existing->name;
			$parent_id = (int) $existing->parent;
		}

		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return new WP_Error(
				'media_manager_invalid_folder_name',
				__( 'Folder name is required.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$valid = $this->hierarchy->validate_parent( $folder_id, $parent_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$previous_policy = $this->policy->get( $folder_id );
		$was_private     = ! is_wp_error( $previous_policy ) && ! empty( $previous_policy['is_private'] );

		if ( null !== $policy ) {
			$current    = is_wp_error( $previous_policy ) ? $this->policy->defaults() : $previous_policy;
			$normalised = $this->policy->normalise( array_merge( $current, $policy ) );
			if ( is_wp_error( $normalised ) ) {
				return $normalised;
			}
			$check = $this->policy->validate_against_parent( $normalised, $parent_id );
			if ( is_wp_error( $check ) ) {
				return $check;
			}
			$policy = $normalised;
		} else {
			$current = $this->policy->get( $folder_id );
			if ( ! is_wp_error( $current ) ) {
				$check = $this->policy->validate_against_parent( $current, $parent_id );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
			}
		}

		$result = $this->repository->update( $folder_id, $name, $parent_id );

		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				return new WP_Error(
					'media_manager_folder_exists',
					__( 'A folder with this name already exists under the selected parent.', 'media-manager' ),
					[ 'status' => 409 ]
				);
			}

			return $result;
		}

		if ( null !== $policy ) {
			$saved = $this->policy->save( $folder_id, $policy, $parent_id );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$now_private = ! empty( $policy['is_private'] );
			if ( $was_private !== $now_private ) {
				$synced = $this->transitions->sync_folder_privacy( $folder_id, $now_private );
				if ( is_wp_error( $synced ) ) {
					return $synced;
				}
			} elseif ( $now_private ) {
				// Best-effort heal when a prior privacy sync left some items public.
				$this->transitions->sync_folder_privacy( $folder_id, true );
			}
		}

		AclCache::invalidate_site();

		return $this->repository->find( $folder_id );
	}

	/**
	 * Delete a folder.
	 *
	 * @param int $folder_id Folder ID.
	 * @return true|WP_Error
	 */
	public function delete( int $folder_id ) {
		$existing = $this->repository->find( $folder_id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		if ( $this->content_folders()->is_mutation_locked( $folder_id ) ) {
			return new WP_Error(
				'media_manager_folder_locked',
				__( 'Managed or system folders cannot be deleted while their source exists.', 'media-manager' ),
				[ 'status' => 409 ]
			);
		}

		$children = $this->repository->children_of( $folder_id );

		if ( ! empty( $children ) ) {
			return new WP_Error(
				'media_manager_folder_has_children',
				__( 'Move or delete child folders before deleting this folder.', 'media-manager' ),
				[ 'status' => 409 ]
			);
		}

		$result = $this->repository->delete( $folder_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		AclCache::invalidate_site();

		return true;
	}

	/**
	 * Policy helper.
	 *
	 * @return FolderPolicy
	 */
	public function policy(): FolderPolicy {
		return $this->policy;
	}
}
