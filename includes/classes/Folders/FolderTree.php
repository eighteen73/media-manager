<?php
/**
 * Nested folder tree builder with counts.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

defined( 'ABSPATH' ) || exit;

/**
 * Builds nested folder tree resources.
 */
class FolderTree {

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
	 * Get nested folder tree.
	 *
	 * @param bool $recursive_counts Whether parent counts include descendants.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_tree( bool $recursive_counts = true ): array {
		$terms = $this->repository->all();
		$direct_sets = $this->attachment_sets_by_folder();

		$all_attachments = $this->all_attachment_ids();
		$assigned        = [];

		foreach ( $direct_sets as $set ) {
			$assigned += $set;
		}

		$summary = [
			'all_count'           => count( $all_attachments ),
			'uncategorized_count' => count( array_diff_key( $all_attachments, $assigned ) ),
		];

		if ( empty( $terms ) ) {
			return [
				'summary' => $summary,
				'folders' => [],
			];
		}

		$children = [];
		$nodes    = [];

		foreach ( $terms as $term ) {
			$id     = (int) $term->term_id;
			$parent = (int) $term->parent;

			$nodes[ $id ] = [
				'id'           => $id,
				'name'         => $term->name,
				'parent'       => $parent,
				'depth'        => 1,
				'direct_count' => isset( $direct_sets[ $id ] ) ? count( $direct_sets[ $id ] ) : 0,
				'count'        => 0,
				'children'     => [],
			];

			$children[ $parent ]   = $children[ $parent ] ?? [];
			$children[ $parent ][] = $id;
		}

		foreach ( array_keys( $nodes ) as $id ) {
			$nodes[ $id ]['depth'] = $this->calculate_depth( $id, $nodes );
		}

		$attachment_unions = [];

		foreach ( $this->ordered_bottom_up( $nodes, $children ) as $id ) {
			$set = $direct_sets[ $id ] ?? [];

			if ( $recursive_counts ) {
				foreach ( $children[ $id ] ?? [] as $child_id ) {
					$set = $set + ( $attachment_unions[ $child_id ] ?? [] );
				}
			}

			$attachment_unions[ $id ] = $set;
			$nodes[ $id ]['count']    = count( $set );
		}

		return [
			'summary' => $summary,
			'folders' => $this->nest( 0, $children, $nodes ),
		];
	}

	/**
	 * All attachment IDs keyed by ID.
	 *
	 * @return array<int, true>
	 */
	private function all_attachment_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status IN ('inherit','private')"
		);

		$set = [];

		foreach ( (array) $ids as $id ) {
			$set[ (int) $id ] = true;
		}

		return $set;
	}

	/**
	 * Attachment ID sets keyed by folder ID.
	 *
	 * @return array<int, array<int, true>>
	 */
	private function attachment_sets_by_folder(): array {
		global $wpdb;

		$taxonomy = FolderTaxonomy::NAME;
		$sets     = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr.object_id, tt.term_id
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy = %s
				AND p.post_type = 'attachment'",
				$taxonomy
			)
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		foreach ( $rows as $row ) {
			$term_id = (int) $row->term_id;
			$object  = (int) $row->object_id;

			$sets[ $term_id ]            = $sets[ $term_id ] ?? [];
			$sets[ $term_id ][ $object ] = true;
		}

		return $sets;
	}

	/**
	 * Calculate folder depth.
	 *
	 * @param int                             $id    Folder ID.
	 * @param array<int, array<string,mixed>> $nodes Node map.
	 * @return int
	 */
	private function calculate_depth( int $id, array $nodes ): int {
		$depth   = 1;
		$current = $id;
		$seen    = [];

		while ( ! empty( $nodes[ $current ]['parent'] ) && ! isset( $seen[ $current ] ) ) {
			$seen[ $current ] = true;
			$current          = (int) $nodes[ $current ]['parent'];
			++$depth;
		}

		return $depth;
	}

	/**
	 * Order folder IDs deepest-first.
	 *
	 * @param array<int, array<string,mixed>> $nodes    Nodes.
	 * @param array<int, int[]>               $children Children map.
	 * @return int[]
	 */
	private function ordered_bottom_up( array $nodes, array $children ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$ids = array_keys( $nodes );
		usort(
			$ids,
			static function ( int $a, int $b ) use ( $nodes ): int {
				return ( $nodes[ $b ]['depth'] <=> $nodes[ $a ]['depth'] ) ?: ( $a <=> $b );
			}
		);

		return $ids;
	}

	/**
	 * Nest children under a parent.
	 *
	 * @param int                             $parent_id Parent ID.
	 * @param array<int, int[]>               $children  Children map.
	 * @param array<int, array<string,mixed>> $nodes     Nodes.
	 * @return array<int, array<string, mixed>>
	 */
	private function nest( int $parent_id, array $children, array $nodes ): array {
		$branch = [];

		foreach ( $children[ $parent_id ] ?? [] as $child_id ) {
			$node             = $nodes[ $child_id ];
			$node['children'] = $this->nest( $child_id, $children, $nodes );
			$branch[]         = $node;
		}

		usort(
			$branch,
			static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['name'], (string) $b['name'] ) ?: ( $a['id'] <=> $b['id'] );
			}
		);

		return $branch;
	}
}
