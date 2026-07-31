<?php
/**
 * Hierarchy validation for media folders.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Validates parent moves and nesting depth.
 */
class HierarchyValidator {

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
	 * Maximum allowed depth.
	 *
	 * @return int
	 */
	public function max_depth(): int {
		/**
		 * Filters the maximum folder nesting depth.
		 *
		 * @param int $depth Maximum depth.
		 */
		return (int) apply_filters( 'media_manager_max_folder_depth', FolderTaxonomy::MAX_DEPTH );
	}

	/**
	 * Validate a proposed parent relationship.
	 *
	 * @param int $folder_id     Folder being moved (0 for new folders).
	 * @param int $new_parent_id Proposed parent ID.
	 * @return true|WP_Error
	 */
	public function validate_parent( int $folder_id, int $new_parent_id ) {
		$maps = $this->build_maps();

		if ( $new_parent_id > 0 ) {
			$parent = $this->repository->find( $new_parent_id );

			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
		}

		if ( $folder_id > 0 && $folder_id === $new_parent_id ) {
			return new WP_Error(
				'media_manager_invalid_parent',
				__( 'A folder cannot be its own parent.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		if ( $folder_id > 0 && $new_parent_id > 0 && $this->is_descendant( $new_parent_id, $folder_id, $maps['children'] ) ) {
			return new WP_Error(
				'media_manager_invalid_parent',
				__( 'A folder cannot be moved under one of its descendants.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$parent_depth = $new_parent_id > 0 ? $this->depth_of( $new_parent_id, $maps['parents'] ) : 0;
		$subtree      = $folder_id > 0 ? $this->subtree_depth( $folder_id ) : 1;
		$resulting    = $parent_depth + $subtree;

		if ( $resulting > $this->max_depth() ) {
			return new WP_Error(
				'media_manager_max_depth',
				sprintf(
					/* translators: %d: maximum folder depth */
					__( 'Folders cannot nest more than %d levels deep.', 'media-manager' ),
					$this->max_depth()
				),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Depth of a folder subtree (1 for a leaf).
	 *
	 * @param int $folder_id Folder ID.
	 * @return int
	 */
	public function subtree_depth( int $folder_id ): int {
		$maps = $this->build_maps();

		return $this->deepest_from( $folder_id, $maps['children'] );
	}

	/**
	 * Build parent/children maps from all folders.
	 *
	 * @return array{parents: array<int,int>, children: array<int,int[]>}
	 */
	private function build_maps(): array {
		$parents  = [];
		$children = [];

		foreach ( $this->repository->all() as $term ) {
			$id                    = (int) $term->term_id;
			$parent                = (int) $term->parent;
			$parents[ $id ]        = $parent;
			$children[ $parent ]   = $children[ $parent ] ?? [];
			$children[ $parent ][] = $id;
		}

		return [
			'parents'  => $parents,
			'children' => $children,
		];
	}

	/**
	 * Calculate depth of a folder (root = 1).
	 *
	 * @param int            $folder_id Folder ID.
	 * @param array<int,int> $parents   Parent map.
	 * @return int
	 */
	private function depth_of( int $folder_id, array $parents ): int {
		$depth   = 0;
		$current = $folder_id;
		$seen    = [];

		while ( $current > 0 && ! isset( $seen[ $current ] ) ) {
			$seen[ $current ] = true;
			++$depth;
			$current = $parents[ $current ] ?? 0;
		}

		return $depth;
	}

	/**
	 * Whether candidate is a descendant of ancestor.
	 *
	 * @param int              $candidate Candidate ID.
	 * @param int              $ancestor  Ancestor ID.
	 * @param array<int,int[]> $children  Children map.
	 * @return bool
	 */
	private function is_descendant( int $candidate, int $ancestor, array $children ): bool {
		$stack = $children[ $ancestor ] ?? [];

		while ( ! empty( $stack ) ) {
			$id = array_pop( $stack );

			if ( (int) $id === $candidate ) {
				return true;
			}

			foreach ( $children[ $id ] ?? [] as $child ) {
				$stack[] = $child;
			}
		}

		return false;
	}

	/**
	 * Deepest subtree length from a folder.
	 *
	 * @param int              $folder_id Folder ID.
	 * @param array<int,int[]> $children  Children map.
	 * @return int
	 */
	private function deepest_from( int $folder_id, array $children ): int {
		$max = 1;

		foreach ( $children[ $folder_id ] ?? [] as $child ) {
			$max = max( $max, 1 + $this->deepest_from( (int) $child, $children ) );
		}

		return $max;
	}
}
