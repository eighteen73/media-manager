<?php
/**
 * Shared media bootstrap: featured-image marking and URL bridging.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\SharedMedia;

use Eighteen73\MediaManager\PrivateMedia\Delivery;
use Eighteen73\MediaManager\PrivateMedia\TransitionService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers cross-site selection and URL resolution behaviour.
 */
class SharedMedia {

	/**
	 * Attachment resolver.
	 *
	 * @var AttachmentResolver
	 */
	private AttachmentResolver $resolver;

	/**
	 * Transition service.
	 *
	 * @var TransitionService
	 */
	private TransitionService $transitions;

	/**
	 * Delivery helper.
	 *
	 * @var Delivery
	 */
	private Delivery $delivery;

	/**
	 * Constructor.
	 *
	 * @param AttachmentResolver|null $resolver    Optional resolver.
	 * @param TransitionService|null  $transitions Optional transitions.
	 * @param Delivery|null           $delivery    Optional delivery.
	 */
	public function __construct(
		?AttachmentResolver $resolver = null,
		?TransitionService $transitions = null,
		?Delivery $delivery = null
	) {
		$this->resolver    = $resolver ?? new AttachmentResolver();
		$this->transitions = $transitions ?? new TransitionService();
		$this->delivery    = $delivery ?? new Delivery( $this->transitions );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'set_post_thumbnail', [ $this, 'maybe_mark_featured_network' ], 10, 2 );
		add_action( 'added_post_meta', [ $this, 'maybe_mark_featured_from_meta' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'maybe_mark_featured_from_meta' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'maybe_clear_featured_network' ], 10, 4 );

		add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url' ], 15, 2 );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'filter_image_src' ], 15, 4 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'filter_srcset' ], 15, 5 );
		add_filter( 'get_attached_file', [ $this, 'filter_get_attached_file' ], 15, 2 );
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'filter_attachment_for_js' ], 15, 3 );
		add_filter( 'post_thumbnail_html', [ $this, 'filter_post_thumbnail_html' ], 15, 5 );

		add_filter( 'rest_pre_dispatch', [ $this, 'maybe_rest_media_item' ], 10, 3 );
		add_filter( 'rest_request_before_callbacks', [ $this, 'maybe_prime_featured_media' ], 10, 3 );
		add_action( 'wp_ajax_get-attachment', [ $this, 'maybe_ajax_get_attachment' ], 0 );
		add_action( 'wp_ajax_set-post-thumbnail', [ $this, 'maybe_prime_ajax_thumbnail' ], 0 );
		add_filter( 'media_view_settings', [ $this, 'filter_media_view_settings' ], 10, 2 );
	}

	/**
	 * Expose whether the current post's featured image is from the network library.
	 *
	 * @param array         $settings Media view settings.
	 * @param \WP_Post|null $post     Current post.
	 * @return array
	 */
	public function filter_media_view_settings( $settings, $post ) {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		$featured_network = false;
		if ( $post instanceof \WP_Post ) {
			$featured_network = (bool) get_post_meta(
				(int) $post->ID,
				AttachmentResolver::META_FEATURED_NETWORK,
				true
			);
		}

		$settings['mediaManagerFeaturedNetwork'] = $featured_network;

		return $settings;
	}

	/**
	 * Prime network attachment before classic set-post-thumbnail AJAX.
	 *
	 * @return void
	 */
	public function maybe_prime_ajax_thumbnail(): void {
		if ( empty( $_REQUEST['thumbnail_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$attachment_id = (int) $_REQUEST['thumbnail_id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $attachment_id > 0 && $this->resolver->is_network_attachment( $attachment_id ) ) {
			$this->resolver->prime_network_attachment( $attachment_id );
		}
	}

	/**
	 * Serve network library attachments via GET /wp/v2/media/{id}.
	 *
	 * @param mixed            $result  Dispatch result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function maybe_rest_media_item( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		if ( 'GET' !== $request->get_method() ) {
			return $result;
		}

		$route = $request->get_route();
		if ( ! is_string( $route ) || ! preg_match( '#/wp/v2/media/(?P<id>\d+)$#', $route, $matches ) ) {
			return $result;
		}

		$attachment_id = (int) $matches['id'];
		if ( ! $this->resolver->is_network_attachment( $attachment_id ) ) {
			return $result;
		}

		if ( ! current_user_can( 'upload_files' ) || ! NetworkLibrary::current_user_can_use() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to view shared media.', 'media-manager' ),
				[ 'status' => 403 ]
			);
		}

		$prepared = $this->prepare_network_attachment_rest( $attachment_id, $request );
		if ( null === $prepared ) {
			return $result;
		}

		return $prepared;
	}

	/**
	 * Prime network attachment cache before REST featured_media updates.
	 *
	 * @param mixed                 $response Response.
	 * @param array<string, mixed>  $handler  Handler.
	 * @param \WP_REST_Request|null $request  Request.
	 * @return mixed
	 */
	public function maybe_prime_featured_media( $response, $handler, $request ) {
		unset( $handler );

		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		$featured = $request->get_param( 'featured_media' );
		if ( null === $featured || '' === $featured ) {
			return $response;
		}

		$attachment_id = (int) $featured;
		if ( $attachment_id < 1 || ! $this->resolver->is_network_attachment( $attachment_id ) ) {
			return $response;
		}

		$this->resolver->prime_network_attachment( $attachment_id );

		return $response;
	}

	/**
	 * Handle wp_ajax_get-attachment for network library IDs.
	 *
	 * @return void
	 */
	public function maybe_ajax_get_attachment(): void {
		if ( empty( $_REQUEST['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$attachment_id = (int) $_REQUEST['id']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $attachment_id < 1 || ! $this->resolver->is_network_attachment( $attachment_id ) ) {
			return;
		}

		if ( ! current_user_can( 'upload_files' ) || ! NetworkLibrary::current_user_can_use() ) {
			wp_send_json_error();
		}

		$prepared = $this->resolver->with_library(
			static function () use ( $attachment_id ) {
				$post = get_post( $attachment_id );
				return $post instanceof \WP_Post ? wp_prepare_attachment_for_js( $post ) : null;
			}
		);

		if ( ! is_array( $prepared ) ) {
			wp_send_json_error();
		}

		$prepared['media_manager_network']         = true;
		$prepared['media_manager_network_blog_id'] = NetworkLibrary::get_library_id();

		wp_send_json_success( $prepared );
	}

	/**
	 * Prepare a network attachment as a REST media response.
	 *
	 * @param int              $attachment_id Attachment ID.
	 * @param \WP_REST_Request $request       Request.
	 * @return \WP_REST_Response|null
	 */
	private function prepare_network_attachment_rest( int $attachment_id, \WP_REST_Request $request ) {
		$controller = new \WP_REST_Attachments_Controller( 'attachment' );

		$response = $this->resolver->with_library(
			function () use ( $controller, $attachment_id, $request ) {
				$post = get_post( $attachment_id );
				if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
					return null;
				}

				$data = $controller->prepare_item_for_response( $post, $request );
				if ( is_wp_error( $data ) ) {
					return null;
				}

				return $data;
			}
		);

		if ( ! $response instanceof \WP_REST_Response ) {
			return null;
		}

		$data = $response->get_data();
		if ( is_array( $data ) ) {
			$data['media_manager_network']         = true;
			$data['media_manager_network_blog_id'] = NetworkLibrary::get_library_id();
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Mark featured images selected from the network library.
	 *
	 * @param int $post_id       Post ID.
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function maybe_mark_featured_network( int $post_id, int $attachment_id ): void {
		if ( $this->resolver->is_network_attachment( $attachment_id ) ) {
			update_post_meta( $post_id, AttachmentResolver::META_FEATURED_NETWORK, 1 );
			return;
		}

		delete_post_meta( $post_id, AttachmentResolver::META_FEATURED_NETWORK );
	}

	/**
	 * Mark network featured images when `_thumbnail_id` is written via REST/meta APIs.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function maybe_mark_featured_from_meta( $meta_id, $post_id, $meta_key, $meta_value ): void {
		unset( $meta_id );

		if ( '_thumbnail_id' !== $meta_key ) {
			return;
		}

		$this->maybe_mark_featured_network( (int) $post_id, (int) $meta_value );
	}

	/**
	 * Clear network featured flag when the thumbnail meta is removed.
	 *
	 * @param string[] $meta_ids  Meta IDs.
	 * @param int      $post_id   Post ID.
	 * @param string   $meta_key  Meta key.
	 * @param mixed    $meta_value Meta value.
	 * @return void
	 */
	public function maybe_clear_featured_network( $meta_ids, $post_id, $meta_key, $meta_value ): void {
		unset( $meta_ids, $meta_value );

		if ( '_thumbnail_id' === $meta_key ) {
			delete_post_meta( (int) $post_id, AttachmentResolver::META_FEATURED_NETWORK );
		}
	}

	/**
	 * Resolve network attachment URLs on the library site.
	 *
	 * @param string $url           URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$resolved      = $this->resolver->with_network_attachment(
			$attachment_id,
			function ( int $id ) {
				if ( $this->transitions->is_private( $id ) ) {
					return $this->delivery->url( $id );
				}
				return wp_get_attachment_url( $id );
			}
		);

		return is_string( $resolved ) && '' !== $resolved ? $resolved : $url;
	}

	/**
	 * Resolve network image src tuples on the library site.
	 *
	 * @param array|false  $image         Image data.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Size.
	 * @param bool         $icon          Whether icon.
	 * @return array|false
	 */
	public function filter_image_src( $image, $attachment_id, $size, $icon ) {
		$attachment_id = (int) $attachment_id;
		$resolved      = $this->resolver->with_network_attachment(
			$attachment_id,
			static function ( int $id ) use ( $size, $icon ) {
				return wp_get_attachment_image_src( $id, $size, $icon );
			}
		);

		return is_array( $resolved ) || false === $resolved ? $resolved : $image;
	}

	/**
	 * Resolve network srcset on the library site.
	 *
	 * @param array  $sources       Sources.
	 * @param array  $size_array    Size array.
	 * @param string $image_src     Image src.
	 * @param array  $image_meta    Meta.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$resolved      = $this->resolver->with_network_attachment(
			$attachment_id,
			static function ( int $id ) use ( $size_array, $image_src, $image_meta ) {
				return wp_calculate_image_srcset( $size_array, $image_src, $image_meta, $id );
			}
		);

		return is_array( $resolved ) ? $resolved : $sources;
	}

	/**
	 * Resolve attached file path on the library site for network attachments.
	 *
	 * @param string|false $file          File path.
	 * @param int          $attachment_id Attachment ID.
	 * @return string|false
	 */
	public function filter_get_attached_file( $file, $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$resolved      = $this->resolver->with_network_attachment(
			$attachment_id,
			static function ( int $id ) {
				return get_attached_file( $id );
			}
		);

		return ( is_string( $resolved ) || false === $resolved ) ? $resolved : $file;
	}

	/**
	 * Ensure network attachments prepared for JS keep their network markers.
	 *
	 * @param array       $response   Attachment data.
	 * @param \WP_Post    $attachment Attachment post.
	 * @param array|false $meta       Attachment meta.
	 * @return array
	 */
	public function filter_attachment_for_js( $response, $attachment, $meta ) {
		unset( $meta );

		if ( ! is_array( $response ) || ! $attachment instanceof \WP_Post ) {
			return $response;
		}

		if ( ! empty( $response['media_manager_network'] ) ) {
			return $response;
		}

		// When preparing on the library site during a shared query, markers are set by SharedQuery.
		return $response;
	}

	/**
	 * Rebuild featured image HTML from the network library when marked.
	 *
	 * @param string       $html              HTML.
	 * @param int          $post_id           Post ID.
	 * @param int          $post_thumbnail_id Thumbnail ID.
	 * @param string|int[] $size              Size.
	 * @param array        $attr              Attributes.
	 * @return string
	 */
	public function filter_post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
		if ( ! get_post_meta( (int) $post_id, AttachmentResolver::META_FEATURED_NETWORK, true ) ) {
			return $html;
		}

		$resolved = $this->resolver->with_network_attachment(
			(int) $post_thumbnail_id,
			static function ( int $id ) use ( $size, $attr ) {
				return wp_get_attachment_image( $id, $size, false, $attr );
			}
		);

		return is_string( $resolved ) && '' !== $resolved ? $resolved : $html;
	}
}
