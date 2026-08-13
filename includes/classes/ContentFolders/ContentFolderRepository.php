<?php
/**
 * Generated / system folder term meta helpers.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\ContentFolders;

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderTaxonomy;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes content-folder term meta.
 */
class ContentFolderRepository {

	/**
	 * System root flag.
	 *
	 * @var string
	 */
	public const META_IS_SYSTEM_ROOT = 'media_manager_is_system_root';

	/**
	 * Generated per-post folder flag.
	 *
	 * @var string
	 */
	public const META_IS_GENERATED = 'media_manager_is_generated';

	/**
	 * Source post ID.
	 *
	 * @var string
	 */
	public const META_SOURCE_POST_ID = 'media_manager_source_post_id';

	/**
	 * Source post type.
	 *
	 * @var string
	 */
	public const META_SOURCE_POST_TYPE = 'media_manager_source_post_type';

	/**
	 * Orphaned flag.
	 *
	 * @var string
	 */
	public const META_IS_ORPHANED = 'media_manager_is_orphaned';

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $folders;

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null $folders Optional folder repository.
	 */
	public function __construct( ?FolderRepository $folders = null ) {
		$this->folders = $folders ?? new FolderRepository();
	}

	/**
	 * Whether a folder is a protected system root.
	 *
	 * @param int $folder_id Folder ID.
	 * @return bool
	 */
	public function is_system_root( int $folder_id ): bool {
		return (bool) get_term_meta( $folder_id, self::META_IS_SYSTEM_ROOT, true );
	}

	/**
	 * Whether a folder is a generated per-post folder.
	 *
	 * @param int $folder_id Folder ID.
	 * @return bool
	 */
	public function is_generated( int $folder_id ): bool {
		return (bool) get_term_meta( $folder_id, self::META_IS_GENERATED, true );
	}

	/**
	 * Whether a generated folder is orphaned.
	 *
	 * @param int $folder_id Folder ID.
	 * @return bool
	 */
	public function is_orphaned( int $folder_id ): bool {
		return (bool) get_term_meta( $folder_id, self::META_IS_ORPHANED, true );
	}

	/**
	 * Source post ID for a generated folder.
	 *
	 * @param int $folder_id Folder ID.
	 * @return int
	 */
	public function source_post_id( int $folder_id ): int {
		return absint( get_term_meta( $folder_id, self::META_SOURCE_POST_ID, true ) );
	}

	/**
	 * Source post type meta.
	 *
	 * @param int $folder_id Folder ID.
	 * @return string
	 */
	public function source_post_type( int $folder_id ): string {
		return sanitize_key( (string) get_term_meta( $folder_id, self::META_SOURCE_POST_TYPE, true ) );
	}

	/**
	 * Whether the folder is managed (generated with a living source post).
	 *
	 * @param int $folder_id Folder ID.
	 * @return bool
	 */
	public function is_managed( int $folder_id ): bool {
		if ( ! $this->is_generated( $folder_id ) || $this->is_orphaned( $folder_id ) ) {
			return false;
		}

		$post_id = $this->source_post_id( $folder_id );
		if ( $post_id < 1 ) {
			return false;
		}

		// Trash keeps the source; only permanent delete orphans the folder.
		$post = get_post( $post_id );
		return $post instanceof \WP_Post;
	}

	/**
	 * Find the system root for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Term|null
	 */
	public function find_system_root( string $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return null;
		}

		$terms = get_terms(
			[
				'taxonomy'   => FolderTaxonomy::NAME,
				'hide_empty' => false,
				'parent'     => 0,
				'number'     => 1,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					[
						'key'   => self::META_IS_SYSTEM_ROOT,
						'value' => '1',
					],
					[
						'key'   => self::META_SOURCE_POST_TYPE,
						'value' => $post_type,
					],
				],
			]
		);

		return ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof WP_Term ) ? $terms[0] : null;
	}

	/**
	 * Find the generated folder for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Term|null
	 */
	public function find_folder_for_post( int $post_id ) {
		if ( $post_id < 1 ) {
			return null;
		}

		$terms = get_terms(
			[
				'taxonomy'   => FolderTaxonomy::NAME,
				'hide_empty' => false,
				'number'     => 1,
				'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => self::META_SOURCE_POST_ID,
						'value' => $post_id,
						'type'  => 'NUMERIC',
					],
				],
			]
		);

		return ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof WP_Term ) ? $terms[0] : null;
	}

	/**
	 * Persist system root meta.
	 *
	 * @param int    $folder_id Folder ID.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function mark_system_root( int $folder_id, string $post_type ): void {
		update_term_meta( $folder_id, self::META_IS_SYSTEM_ROOT, 1 );
		update_term_meta( $folder_id, self::META_SOURCE_POST_TYPE, sanitize_key( $post_type ) );
	}

	/**
	 * Persist generated folder meta.
	 *
	 * @param int    $folder_id Folder ID.
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function mark_generated( int $folder_id, int $post_id, string $post_type ): void {
		update_term_meta( $folder_id, self::META_IS_GENERATED, 1 );
		update_term_meta( $folder_id, self::META_SOURCE_POST_ID, $post_id );
		update_term_meta( $folder_id, self::META_SOURCE_POST_TYPE, sanitize_key( $post_type ) );
		delete_term_meta( $folder_id, self::META_IS_ORPHANED );
	}

	/**
	 * Mark a generated folder orphaned.
	 *
	 * @param int $folder_id Folder ID.
	 * @return void
	 */
	public function mark_orphaned( int $folder_id ): void {
		update_term_meta( $folder_id, self::META_IS_ORPHANED, 1 );
	}

	/**
	 * Flags for API/tree payloads.
	 *
	 * @param int $folder_id Folder ID.
	 * @return array{is_system_root: bool, is_generated: bool, is_orphaned: bool, source_post_id: int, source_post_type: string}
	 */
	public function flags( int $folder_id ): array {
		return [
			'is_system_root'   => $this->is_system_root( $folder_id ),
			'is_generated'     => $this->is_generated( $folder_id ),
			'is_orphaned'      => $this->is_orphaned( $folder_id ),
			'source_post_id'   => $this->source_post_id( $folder_id ),
			'source_post_type' => $this->source_post_type( $folder_id ),
		];
	}
}
