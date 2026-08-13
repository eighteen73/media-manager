<?php
/**
 * Shared media attachment queries against the network library site.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\SharedMedia;

use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Intercepts media AJAX queries for the Shared media tab.
 */
class SharedQuery {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'wp_ajax_query-attachments', [ $this, 'maybe_handle_query' ], 0 );
	}

	/**
	 * Handle query-attachments for the shared library before core runs.
	 *
	 * @return void
	 */
	public function maybe_handle_query(): void {
		if ( ! $this->request_wants_shared() ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) || ! NetworkLibrary::current_user_can_use() ) {
			wp_send_json_error( [ 'message' => __( 'Shared media is not available.', 'media-manager' ) ], 403 );
		}

		$library_id = NetworkLibrary::get_library_id();
		$query_args = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? wp_unslash( $_REQUEST['query'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: [];

		$posts = [];

		switch_to_blog( $library_id );
		try {
			$query    = $this->build_query_args( $query_args );
			$query    = apply_filters( 'ajax_query_attachments_args', $query );
			$wp_query = new WP_Query( $query );

			foreach ( $wp_query->posts as $post ) {
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				$prepared = wp_prepare_attachment_for_js( $post );
				if ( ! is_array( $prepared ) ) {
					continue;
				}

				$prepared['media_manager_network']         = true;
				$prepared['media_manager_network_blog_id'] = $library_id;
				$posts[]                                   = $prepared;
			}
		} finally {
			restore_current_blog();
		}

		wp_send_json_success( $posts );
	}

	/**
	 * Whether the current AJAX request targets shared media.
	 *
	 * @return bool
	 */
	private function request_wants_shared(): bool {
		if ( empty( $_REQUEST['query'] ) || ! is_array( $_REQUEST['query'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}

		$query = wp_unslash( $_REQUEST['query'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( empty( $query['media_manager_shared'] ) ) {
			return false;
		}

		$value = $query['media_manager_shared'];
		return '1' === (string) $value || 1 === (int) $value || true === $value;
	}

	/**
	 * Build WP_Query args from the media modal query payload.
	 *
	 * Mirrors core wp_ajax_query_attachments defaults closely enough for browsing.
	 *
	 * @param array $query_args Raw query args from the request.
	 * @return array
	 */
	private function build_query_args( array $query_args ): array {
		$keys = [
			's',
			'order',
			'orderby',
			'posts_per_page',
			'paged',
			'post_mime_type',
			'post_parent',
			'author',
			'post__in',
			'post__not_in',
			'year',
			'monthnum',
		];

		$query = [
			'post_status'    => 'inherit',
			'post_type'      => 'attachment',
			'posts_per_page' => 40,
			'paged'          => 1,
		];

		foreach ( $keys as $key ) {
			if ( isset( $query_args[ $key ] ) ) {
				$query[ $key ] = $query_args[ $key ];
			}
		}

		if ( isset( $query_args['type'] ) && ! isset( $query['post_mime_type'] ) ) {
			$query['post_mime_type'] = $query_args['type'];
		}

		$query['posts_per_page'] = min( 200, max( 1, absint( $query['posts_per_page'] ) ) );
		$query['paged']          = max( 1, absint( $query['paged'] ) );

		if ( isset( $query['order'] ) ) {
			$query['order'] = 'ASC' === strtoupper( (string) $query['order'] ) ? 'ASC' : 'DESC';
		}

		if ( isset( $query['s'] ) ) {
			$query['s'] = sanitize_text_field( (string) $query['s'] );
		}

		/**
		 * Filters the arguments passed to WP_Query during shared media AJAX.
		 *
		 * AttachmentQuery also hooks this to apply folder + ACL filters from the request.
		 *
		 * @param array $query Query arguments.
		 */
		return $query;
	}
}
