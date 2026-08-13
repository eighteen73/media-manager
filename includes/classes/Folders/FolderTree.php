<?php
/**
 * Nested folder tree builder with counts.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

use Eighteen73\MediaManager\ContentFolders\ContentFolderRepository;
use Eighteen73\MediaManager\Permissions\EffectiveAcl;
use Eighteen73\MediaManager\Permissions\FolderPolicy;

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
	 * Effective ACL.
	 *
	 * @var EffectiveAcl
	 */
	private EffectiveAcl $acl;

	/**
	 * Folder policy.
	 *
	 * @var FolderPolicy
	 */
	private FolderPolicy $policy;

	/**
	 * Content folder repository.
	 *
	 * @var ContentFolderRepository
	 */
	private ContentFolderRepository $content;

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null        $repository Optional repository.
	 * @param EffectiveAcl|null            $acl        Optional ACL.
	 * @param FolderPolicy|null            $policy     Optional policy.
	 * @param ContentFolderRepository|null $content    Optional content repository.
	 */
	public function __construct(
		?FolderRepository $repository = null,
		?EffectiveAcl $acl = null,
		?FolderPolicy $policy = null,
		?ContentFolderRepository $content = null
	) {
		$this->repository = $repository ?? new FolderRepository();
		$this->policy     = $policy ?? new FolderPolicy( $this->repository );
		$this->acl        = $acl ?? new EffectiveAcl( $this->repository, $this->policy );
		$this->content    = $content ?? new ContentFolderRepository( $this->repository );
	}

	/**
	 * Get nested folder tree.
	 *
	 * @param bool $recursive_counts Whether parent counts include descendants.
	 * @param int  $user_id          Optional user ID for ACL filtering.
	 * @return array<string, mixed>
	 */
	public function get_tree( bool $recursive_counts = true, int $user_id = 0 ): array {
		$user_id     = $user_id > 0 ? $user_id : get_current_user_id();
		$terms       = $this->repository->all();
		$direct_sets = $this->attachment_sets_by_folder();
		$accessible  = array_fill_keys( $this->acl->accessible_folder_ids( $user_id ), true );

		$visible_attachments = [];
		foreach ( $direct_sets as $folder_id => $set ) {
			if ( ! isset( $accessible[ (int) $folder_id ] ) ) {
				continue;
			}
			$visible_attachments += $set;
		}

		$all_attachments = $this->all_attachment_ids();
		$any_assigned    = [];
		foreach ( $direct_sets as $set ) {
			$any_assigned += $set;
		}

		// Uncategorized = no folder relationships at all.
		$uncategorized = array_diff_key( $all_attachments, $any_assigned );

		$summary = [
			'all_count'           => count( $uncategorized ) + count( $visible_attachments ),
			'uncategorized_count' => count( $uncategorized ),
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
			$policy = $this->policy->get( $id );
			$policy = is_wp_error( $policy ) ? $this->policy->defaults() : $policy;

			$nodes[ $id ] = array_merge(
				[
					'id'                => $id,
					'name'              => $term->name,
					'parent'            => $parent,
					'depth'             => 1,
					'direct_count'      => isset( $direct_sets[ $id ] ) ? count( $direct_sets[ $id ] ) : 0,
					'count'             => 0,
					'children'          => [],
					'access_mode'       => $policy['access_mode'],
					'allowed_roles'     => $policy['allowed_roles'],
					'allowed_users'     => $policy['allowed_users'],
					'allowed_usernames' => $this->policy->user_ids_to_logins( $policy['allowed_users'] ),
					'is_private'        => $policy['is_private'],
				],
				$this->content->flags( $id )
			);

			$children[ $parent ]   = $children[ $parent ] ?? [];
			$children[ $parent ][] = $id;
		}

		foreach ( array_keys( $nodes ) as $id ) {
			$nodes[ $id ]['depth'] = $this->calculate_depth( $id, $nodes );
		}

		$attachment_unions = [];

		foreach ( $this->ordered_bottom_up( $nodes, $children ) as $id ) {
			$set = [];

			if ( isset( $accessible[ $id ] ) ) {
				$set = $direct_sets[ $id ] ?? [];
			}

			if ( $recursive_counts ) {
				foreach ( $children[ $id ] ?? [] as $child_id ) {
					$set = $set + ( $attachment_unions[ $child_id ] ?? [] );
				}
			}

			$attachment_unions[ $id ] = $set;
			$nodes[ $id ]['count']    = count( $set );
		}

		$nested = $this->nest( 0, $children, $nodes );

		return [
			'summary' => $summary,
			'folders' => $this->acl->filter_tree( $nested, $user_id ),
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
				$group_a = self::sort_group( $a );
				$group_b = self::sort_group( $b );
				if ( $group_a !== $group_b ) {
					return $group_a <=> $group_b;
				}

				return strcasecmp( (string) $a['name'], (string) $b['name'] ) ?: ( $a['id'] <=> $b['id'] );
			}
		);

		return $branch;
	}

	/**
	 * Sort group: system roots, then generated, then user folders.
	 *
	 * @param array<string, mixed> $node Folder node.
	 * @return int
	 */
	private static function sort_group( array $node ): int {
		if ( ! empty( $node['is_system_root'] ) ) {
			return 0;
		}
		if ( ! empty( $node['is_generated'] ) ) {
			return 1;
		}

		return 2;
	}
}
