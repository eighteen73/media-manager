<?php
/**
 * Post lifecycle hooks for generated media folders.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\ContentFolders;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, syncs, and orphans generated folders with post saves/deletes.
 */
class PostLifecycle {

	/**
	 * Content folder service.
	 *
	 * @var ContentFolderService
	 */
	private ContentFolderService $service;

	/**
	 * Constructor.
	 *
	 * @param ContentFolderService|null $service Optional service.
	 */
	public function __construct( ?ContentFolderService $service = null ) {
		$this->service = $service ?? new ContentFolderService();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'save_post', [ $this, 'on_save_post' ], 20, 3 );
		add_action( 'before_delete_post', [ $this, 'on_before_delete_post' ], 10, 2 );
	}

	/**
	 * Ensure/sync generated folder after a post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 * @param bool     $update  Whether updating.
	 * @return void
	 */
	public function on_save_post( int $post_id, $post, bool $update ): void {
		unset( $update );

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( 'auto-draft' === $post->post_status || 'inherit' === $post->post_status ) {
			return;
		}

		if ( ! $this->service->is_enabled_for_type( $post->post_type ) ) {
			return;
		}

		$this->service->ensure_post_folder( $post_id );
	}

	/**
	 * Orphan the generated folder when the source post is permanently deleted.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object.
	 * @return void
	 */
	public function on_before_delete_post( int $post_id, $post = null ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Trashing fires wp_trash_post, not before_delete_post. This is permanent delete only.
		if ( ! $this->service->is_enabled_for_type( $post->post_type ) && $this->service->folder_id_for_post( $post_id ) < 1 ) {
			return;
		}

		$this->service->orphan_for_post( $post_id );
	}
}
