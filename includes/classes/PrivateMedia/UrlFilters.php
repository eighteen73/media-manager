<?php
/**
 * Rewrite core attachment URLs for private media.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\PrivateMedia;

defined( 'ABSPATH' ) || exit;

/**
 * Filters WordPress attachment URL helpers.
 */
class UrlFilters {

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
	 * @param TransitionService|null $transitions Optional transitions.
	 * @param Delivery|null          $delivery    Optional delivery.
	 */
	public function __construct( ?TransitionService $transitions = null, ?Delivery $delivery = null ) {
		$this->transitions = $transitions ?? new TransitionService();
		$this->delivery    = $delivery ?? new Delivery( $this->transitions );
	}

	/**
	 * Register filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url' ], 20, 2 );
		add_filter( 'wp_get_attachment_image_src', [ $this, 'filter_image_src' ], 20, 4 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'filter_srcset' ], 20, 5 );
		add_filter( 'wp_get_attachment_metadata', [ $this, 'filter_attached_file_path' ], 5, 2 );
		add_filter( 'get_attached_file', [ $this, 'filter_get_attached_file' ], 20, 2 );
		add_filter( 'update_attached_file', [ $this, 'filter_update_attached_file' ], 20, 2 );
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'filter_attachment_for_js' ], 20, 3 );
	}

	/**
	 * Replace public URL with authorised delivery URL.
	 *
	 * @param string $url           URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function filter_attachment_url( $url, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id < 1 || ! $this->transitions->is_private( $attachment_id ) ) {
			return $url;
		}

		return $this->delivery->url( $attachment_id );
	}

	/**
	 * Rewrite image src tuples.
	 *
	 * @param array|false  $image         Image data.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Size.
	 * @param bool         $icon          Whether icon.
	 * @return array|false
	 */
	public function filter_image_src( $image, $attachment_id, $size, $icon ) {
		unset( $icon );

		if ( ! is_array( $image ) || ! $this->transitions->is_private( (int) $attachment_id ) ) {
			return $image;
		}

		$size_name = is_string( $size ) ? $size : 'full';
		$image[0]  = $this->delivery->url( (int) $attachment_id, $size_name );

		return $image;
	}

	/**
	 * Rewrite srcset URLs.
	 *
	 * @param array  $sources       Sources.
	 * @param array  $size_array    Size array.
	 * @param string $image_src     Image src.
	 * @param array  $image_meta    Meta.
	 * @param int    $attachment_id Attachment ID.
	 * @return array
	 */
	public function filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		unset( $size_array, $image_src, $image_meta );

		if ( ! is_array( $sources ) || ! $this->transitions->is_private( (int) $attachment_id ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			$sources[ $width ]['url'] = $this->delivery->url( (int) $attachment_id );
		}

		return $sources;
	}

	/**
	 * No-op metadata filter placeholder for future path normalisation.
	 *
	 * @param array|false $data           Metadata.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|false
	 */
	public function filter_attached_file_path( $data, $attachment_id ) {
		unset( $attachment_id );
		return $data;
	}

	/**
	 * Point get_attached_file() at private storage when private.
	 *
	 * @param string|false $file          File path.
	 * @param int          $attachment_id Attachment ID.
	 * @return string|false
	 */
	public function filter_get_attached_file( $file, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id < 1 || ! $this->transitions->is_private( $attachment_id ) ) {
			return $file;
		}

		$relative = $this->transitions->repair_attached_file_meta( $attachment_id );
		if ( '' === $relative ) {
			return $file;
		}

		$storage = new PrivateStorage();
		$path    = $storage->resolve_existing_path( $relative );

		return is_wp_error( $path ) ? $file : $path;
	}

	/**
	 * Keep `_wp_attached_file` relative when editors save against private storage.
	 *
	 * @param string $file          File path.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function filter_update_attached_file( $file, $attachment_id ) {
		unset( $attachment_id );

		if ( ! is_string( $file ) || '' === $file ) {
			return $file;
		}

		$normalized = $this->transitions->normalize_relative_path( $file );

		// Only rewrite when the path pointed at private storage (or uploads absolute).
		return ( $normalized !== $file && '' !== $normalized ) ? $normalized : $file;
	}

	/**
	 * Rewrite Media Library JS attachment models.
	 *
	 * @param array       $response   Attachment data.
	 * @param \WP_Post    $attachment Attachment post.
	 * @param array|false $meta      Attachment meta.
	 * @return array
	 */
	public function filter_attachment_for_js( $response, $attachment, $meta ) {
		unset( $meta );

		if ( ! is_array( $response ) || ! $attachment instanceof \WP_Post ) {
			return $response;
		}

		$attachment_id = (int) $attachment->ID;
		if ( ! $this->transitions->is_private( $attachment_id ) ) {
			return $response;
		}

		$url                                  = $this->delivery->url( $attachment_id );
		$response['url']                      = $url;
		$response['link']                     = $url;
		$response['media_manager_is_private'] = true;

		if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			foreach ( $response['sizes'] as $size => $data ) {
				if ( ! is_array( $data ) ) {
					continue;
				}
				$response['sizes'][ $size ]['url'] = $this->delivery->url( $attachment_id, (string) $size );
			}
		}

		return $response;
	}
}
