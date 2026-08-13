<?php
/**
 * Resolve attachment data from the network library site when needed.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\SharedMedia;

defined( 'ABSPATH' ) || exit;

/**
 * Cross-site attachment resolution helpers.
 */
class AttachmentResolver {

	/**
	 * Post meta marking a featured image as a network library attachment.
	 *
	 * @var string
	 */
	public const META_FEATURED_NETWORK = '_media_manager_featured_network';

	/**
	 * Whether an attachment ID should be resolved on the network library site.
	 *
	 * Local attachments win when they exist in this site's database. Object-cache
	 * hits alone are not enough (we may prime cache with a remote post).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_network_attachment( int $attachment_id ): bool {
		if ( $attachment_id < 1 || ! NetworkLibrary::is_enabled() || NetworkLibrary::is_library_site() ) {
			return false;
		}

		if ( $this->attachment_exists_locally( $attachment_id ) ) {
			return false;
		}

		$library_id = NetworkLibrary::get_library_id();
		$exists     = false;

		switch_to_blog( $library_id );
		$remote = get_post( $attachment_id );
		if ( $remote instanceof \WP_Post && 'attachment' === $remote->post_type ) {
			$exists = true;
		}
		restore_current_blog();

		return $exists;
	}

	/**
	 * Whether the attachment row exists in the current site's posts table.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function attachment_exists_locally( int $attachment_id ): bool {
		global $wpdb;

		if ( $attachment_id < 1 ) {
			return false;
		}

		$id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment' LIMIT 1",
				$attachment_id
			)
		);

		return $id === $attachment_id;
	}

	/**
	 * Prime the post object cache with the network library attachment so core
	 * APIs like set_post_thumbnail() and REST media GETs can resolve it.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return \WP_Post|null
	 */
	public function prime_network_attachment( int $attachment_id ): ?\WP_Post {
		if ( ! $this->is_network_attachment( $attachment_id ) ) {
			return null;
		}

		$remote = $this->with_library(
			static function () use ( $attachment_id ) {
				$post = get_post( $attachment_id );
				return $post instanceof \WP_Post ? $post : null;
			}
		);

		if ( ! $remote instanceof \WP_Post ) {
			return null;
		}

		// Clone so cache mutations do not affect the switched-blog instance.
		$cached         = clone $remote;
		$cached->filter = 'raw';
		wp_cache_set( $attachment_id, $cached, 'posts' );

		return $cached;
	}

	/**
	 * Run a callback in the network library blog context when the attachment is remote.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param callable $callback      Callback receiving the attachment ID.
	 * @return mixed Callback return value, or null when not a network attachment.
	 */
	public function with_network_attachment( int $attachment_id, callable $callback ) {
		if ( ! $this->is_network_attachment( $attachment_id ) ) {
			return null;
		}

		$library_id = NetworkLibrary::get_library_id();
		switch_to_blog( $library_id );
		try {
			return $callback( $attachment_id );
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Run a callback on the network library site.
	 *
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public function with_library( callable $callback ) {
		$library_id = NetworkLibrary::get_library_id();
		if ( $library_id < 1 ) {
			return null;
		}

		if ( get_current_blog_id() === $library_id ) {
			return $callback( $library_id );
		}

		switch_to_blog( $library_id );
		try {
			return $callback( $library_id );
		} finally {
			restore_current_blog();
		}
	}
}
