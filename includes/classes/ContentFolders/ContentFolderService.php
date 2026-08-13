<?php
/**
 * Generated media folder lifecycle service.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\ContentFolders;

use Eighteen73\MediaManager\Admin\Settings;
use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Permissions\AclCache;
use WP_Error;
use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Ensures system roots and per-post generated folders.
 */
class ContentFolderService {

	/**
	 * Content folder repository.
	 *
	 * @var ContentFolderRepository
	 */
	private ContentFolderRepository $content;

	/**
	 * Folder mutation service.
	 *
	 * @var FolderService
	 */
	private FolderService $folders;

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param ContentFolderRepository|null $content    Optional content repository.
	 * @param FolderService|null           $folders    Optional folder service.
	 * @param FolderRepository|null        $repository Optional folder repository.
	 */
	public function __construct(
		?ContentFolderRepository $content = null,
		?FolderService $folders = null,
		?FolderRepository $repository = null
	) {
		$this->repository = $repository ?? new FolderRepository();
		$this->content    = $content ?? new ContentFolderRepository( $this->repository );
		$this->folders    = $folders ?? new FolderService( $this->repository );
	}

	/**
	 * Eligible post types for generated media folders.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public function eligible_post_types(): array {
		$types = get_post_types( [ 'show_ui' => true ], 'objects' );
		unset( $types['attachment'] );

		foreach ( self::excluded_post_types() as $slug ) {
			unset( $types[ $slug ] );
		}

		/**
		 * Filter eligible post types for generated media folders.
		 *
		 * @param array<string, \WP_Post_Type> $types Post type objects keyed by name.
		 */
		$filtered = apply_filters( 'media_manager_eligible_post_types', $types );

		return is_array( $filtered ) ? $filtered : $types;
	}

	/**
	 * Non-content post types excluded from generated media folders.
	 *
	 * @return string[]
	 */
	private static function excluded_post_types(): array {
		return [
			'wp_navigation',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_font_face',
			'wp_font_family',
			'shop_order',
			'shop_order_refund',
			'shop_coupon',
		];
	}

	/**
	 * Whether generated media folders are enabled for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function is_enabled_for_type( string $post_type ): bool {
		$post_type = sanitize_key( $post_type );
		$eligible  = $this->eligible_post_types();
		if ( ! isset( $eligible[ $post_type ] ) ) {
			return false;
		}

		$settings = Settings::get_settings();
		$map      = isset( $settings['generated_media_folders'] ) && is_array( $settings['generated_media_folders'] )
			? $settings['generated_media_folders']
			: [];

		return ! empty( $map[ $post_type ] );
	}

	/**
	 * Whether generated folders are enabled for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function is_enabled_for_post( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		return $this->is_enabled_for_type( $post->post_type );
	}

	/**
	 * Folder ID for a post if it already exists.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public function folder_id_for_post( int $post_id ): int {
		$term = $this->content->find_folder_for_post( $post_id );
		return $term instanceof WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Ensure the protected system root for a post type exists.
	 *
	 * @param string $post_type Post type.
	 * @return WP_Term|WP_Error
	 */
	public function ensure_system_root( string $post_type ) {
		$post_type = sanitize_key( $post_type );
		$existing  = $this->content->find_system_root( $post_type );
		if ( $existing instanceof WP_Term ) {
			return $existing;
		}

		$types = $this->eligible_post_types();
		$label = isset( $types[ $post_type ] ) ? $types[ $post_type ]->labels->name : $post_type;
		$label = sanitize_text_field( (string) $label );
		if ( '' === $label ) {
			$label = $post_type;
		}

		$created = $this->folders->create( $label, 0 );
		if ( is_wp_error( $created ) ) {
			if ( 'media_manager_folder_exists' === $created->get_error_code() ) {
				// Reuse an existing top-level folder with the same name if present.
				foreach ( $this->repository->children_of( 0 ) as $child ) {
					if ( $child->name === $label ) {
						$this->content->mark_system_root( (int) $child->term_id, $post_type );
						return $child;
					}
				}
			}
			return $created;
		}

		$this->content->mark_system_root( (int) $created->term_id, $post_type );
		AclCache::invalidate_site();

		return $created;
	}

	/**
	 * Ensure a generated folder exists for a post and sync its name.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_Term|WP_Error
	 */
	public function ensure_post_folder( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'media_manager_invalid_post',
				__( 'Invalid post for generated folder.', 'media-manager' ),
				[ 'status' => 404 ]
			);
		}

		if ( wp_is_post_revision( $post ) || 'auto-draft' === $post->post_status ) {
			return new WP_Error(
				'media_manager_post_not_ready',
				__( 'Generated folders are created after the post leaves auto-draft.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! $this->is_enabled_for_type( $post->post_type ) ) {
			return new WP_Error(
				'media_manager_generated_disabled',
				__( 'Generated media folders are not enabled for this post type.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$existing = $this->content->find_folder_for_post( $post_id );
		if ( $existing instanceof WP_Term ) {
			$this->sync_folder_name( (int) $existing->term_id, $post );
			$refreshed = $this->repository->find( (int) $existing->term_id );
			return is_wp_error( $refreshed ) ? $existing : $refreshed;
		}

		$root = $this->ensure_system_root( $post->post_type );
		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$name    = $this->display_name_for_post( $post );
		$created = $this->folders->create( $name, (int) $root->term_id );
		if ( is_wp_error( $created ) ) {
			// Collision: force disambiguated name.
			if ( 'media_manager_folder_exists' === $created->get_error_code() ) {
				$name    = $this->display_name_for_post( $post, true );
				$created = $this->folders->create( $name, (int) $root->term_id );
			}
			if ( is_wp_error( $created ) ) {
				return $created;
			}
		}

		$this->content->mark_generated( (int) $created->term_id, $post_id, $post->post_type );
		AclCache::invalidate_site();

		return $created;
	}

	/**
	 * Mark the generated folder orphaned when its source is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function orphan_for_post( int $post_id ): void {
		$term = $this->content->find_folder_for_post( $post_id );
		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$this->content->mark_orphaned( (int) $term->term_id );
		AclCache::invalidate_site();
	}

	/**
	 * Whether move/delete of this folder must be blocked.
	 *
	 * @param int $folder_id Folder ID.
	 * @return bool
	 */
	public function is_mutation_locked( int $folder_id ): bool {
		if ( $this->content->is_system_root( $folder_id ) ) {
			return true;
		}

		return $this->content->is_managed( $folder_id );
	}

	/**
	 * Content flags helper.
	 *
	 * @return ContentFolderRepository
	 */
	public function repository(): ContentFolderRepository {
		return $this->content;
	}

	/**
	 * Display name for a generated folder.
	 *
	 * @param WP_Post $post          Post.
	 * @param bool    $force_post_id Force post ID suffix.
	 * @return string
	 */
	public function display_name_for_post( WP_Post $post, bool $force_post_id = false ): string {
		$title = sanitize_text_field( $post->post_title );
		if ( '' === $title ) {
			$title = sprintf(
				/* translators: %d: post ID */
				__( 'Untitled (#%d)', 'media-manager' ),
				(int) $post->ID
			);
			return $title;
		}

		if ( $force_post_id || $this->sibling_name_taken( $title, $post ) ) {
			return sprintf( '%s (#%d)', $title, (int) $post->ID );
		}

		return $title;
	}

	/**
	 * Sync managed folder name with the post title.
	 *
	 * @param int     $folder_id Folder ID.
	 * @param WP_Post $post      Post.
	 * @return void
	 */
	private function sync_folder_name( int $folder_id, WP_Post $post ): void {
		if ( $this->content->is_orphaned( $folder_id ) ) {
			return;
		}

		$term = $this->repository->find( $folder_id );
		if ( is_wp_error( $term ) ) {
			return;
		}

		$name = $this->display_name_for_post( $post );
		if ( $term->name === $name ) {
			return;
		}

		$result = $this->repository->update( $folder_id, $name, (int) $term->parent );
		if ( is_wp_error( $result ) && 'term_exists' === $result->get_error_code() ) {
			$name = $this->display_name_for_post( $post, true );
			if ( $term->name !== $name ) {
				$this->repository->update( $folder_id, $name, (int) $term->parent );
			}
		}
	}

	/**
	 * Whether another sibling under the system root already uses this name.
	 *
	 * @param string  $name Desired name.
	 * @param WP_Post $post Source post.
	 * @return bool
	 */
	private function sibling_name_taken( string $name, WP_Post $post ): bool {
		$root = $this->content->find_system_root( $post->post_type );
		if ( ! $root instanceof WP_Term ) {
			return false;
		}

		$own    = $this->content->find_folder_for_post( (int) $post->ID );
		$own_id = $own instanceof WP_Term ? (int) $own->term_id : 0;

		foreach ( $this->repository->children_of( (int) $root->term_id ) as $child ) {
			if ( (int) $child->term_id === $own_id ) {
				continue;
			}
			if ( $child->name === $name ) {
				return true;
			}
		}

		return false;
	}
}
