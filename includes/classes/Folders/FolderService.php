<?php
/**
 * Folder create/update/delete service.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

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
	 * Constructor.
	 *
	 * @param FolderRepository|null   $repository Optional repository.
	 * @param HierarchyValidator|null $hierarchy  Optional validator.
	 */
	public function __construct( ?FolderRepository $repository = null, ?HierarchyValidator $hierarchy = null ) {
		$this->repository = $repository ?? new FolderRepository();
		$this->hierarchy  = $hierarchy ?? new HierarchyValidator( $this->repository );
	}

	/**
	 * Create a folder.
	 *
	 * @param string $name      Folder name.
	 * @param int    $parent_id Parent folder ID.
	 * @return WP_Term|WP_Error
	 */
	public function create( string $name, int $parent_id = 0 ) {
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

		return $this->repository->find( (int) $result['term_id'] );
	}

	/**
	 * Update a folder.
	 *
	 * @param int    $folder_id Folder ID.
	 * @param string $name      Folder name.
	 * @param int    $parent_id Parent folder ID.
	 * @return WP_Term|WP_Error
	 */
	public function update( int $folder_id, string $name, int $parent_id ) {
		$existing = $this->repository->find( $folder_id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
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

		return true;
	}
}
