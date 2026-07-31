<?php
/**
 * Folder term repository.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around WordPress term APIs for media folders.
 */
class FolderRepository {

	/**
	 * Find a folder by ID.
	 *
	 * @param int $folder_id Folder term ID.
	 * @return WP_Term|WP_Error
	 */
	public function find( int $folder_id ) {
		$term = get_term( $folder_id, FolderTaxonomy::NAME );

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error(
				'media_manager_invalid_folder',
				__( 'Invalid folder.', 'media-manager' ),
				[ 'status' => 404 ]
			);
		}

		return $term;
	}

	/**
	 * Get all folders.
	 *
	 * @return WP_Term[]
	 */
	public function all(): array {
		$terms = get_terms(
			[
				'taxonomy'   => FolderTaxonomy::NAME,
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		return is_array( $terms ) ? $terms : [];
	}

	/**
	 * Get direct children of a folder.
	 *
	 * @param int $folder_id Parent folder ID.
	 * @return WP_Term[]
	 */
	public function children_of( int $folder_id ): array {
		$terms = get_terms(
			[
				'taxonomy'   => FolderTaxonomy::NAME,
				'hide_empty' => false,
				'parent'     => $folder_id,
				'orderby'    => 'name',
				'order'      => 'ASC',
			]
		);

		return is_array( $terms ) ? $terms : [];
	}

	/**
	 * Get folder IDs assigned to an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int[]
	 */
	public function attachment_folder_ids( int $attachment_id ): array {
		$terms = wp_get_object_terms( $attachment_id, FolderTaxonomy::NAME, [ 'fields' => 'ids' ] );

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return [];
		}

		return array_map( 'intval', $terms );
	}

	/**
	 * Attachment IDs directly assigned to a folder.
	 *
	 * @param int $folder_id Folder ID.
	 * @return int[]
	 */
	public function attachment_ids_in_folder( int $folder_id ): array {
		if ( $folder_id < 1 ) {
			return [];
		}

		$ids = get_objects_in_term( $folder_id, FolderTaxonomy::NAME );

		if ( is_wp_error( $ids ) || ! is_array( $ids ) ) {
			return [];
		}

		$attachment_ids = [];

		foreach ( $ids as $object_id ) {
			$object_id = (int) $object_id;
			if ( $object_id > 0 && 'attachment' === get_post_type( $object_id ) ) {
				$attachment_ids[] = $object_id;
			}
		}

		return array_values( array_unique( $attachment_ids ) );
	}

	/**
	 * Set folder assignments for an attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param int[] $folder_ids    Folder IDs.
	 * @param bool  $append        Whether to append.
	 * @return array|WP_Error
	 */
	public function set_attachment_folders( int $attachment_id, array $folder_ids, bool $append = false ) {
		$folder_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $folder_ids ),
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);

		return wp_set_object_terms( $attachment_id, $folder_ids, FolderTaxonomy::NAME, $append );
	}

	/**
	 * Insert a folder term.
	 *
	 * @param string $name      Folder name.
	 * @param int    $parent_id Parent folder ID.
	 * @return array|WP_Error
	 */
	public function insert( string $name, int $parent_id = 0 ) {
		return wp_insert_term(
			$name,
			FolderTaxonomy::NAME,
			[
				'parent' => max( 0, $parent_id ),
			]
		);
	}

	/**
	 * Update a folder term.
	 *
	 * @param int    $folder_id Folder ID.
	 * @param string $name      Folder name.
	 * @param int    $parent_id Parent folder ID.
	 * @return array|WP_Error
	 */
	public function update( int $folder_id, string $name, int $parent_id ) {
		return wp_update_term(
			$folder_id,
			FolderTaxonomy::NAME,
			[
				'name'   => $name,
				'parent' => max( 0, $parent_id ),
			]
		);
	}

	/**
	 * Delete a folder term.
	 *
	 * @param int $folder_id Folder ID.
	 * @return bool|WP_Error
	 */
	public function delete( int $folder_id ) {
		return wp_delete_term( $folder_id, FolderTaxonomy::NAME );
	}
}
