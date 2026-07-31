<?php
/**
 * Attachment query filtering by folder.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

use Eighteen73\MediaManager\Permissions\AccessPolicy;
use Eighteen73\MediaManager\Permissions\EffectiveAcl;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies folder filters to media queries.
 */
class AttachmentQuery {

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
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null $repository Optional repository.
	 * @param EffectiveAcl|null     $acl        Optional ACL.
	 * @param AccessPolicy|null     $access     Optional access policy.
	 */
	public function __construct(
		?FolderRepository $repository = null,
		?EffectiveAcl $acl = null,
		?AccessPolicy $access = null
	) {
		$this->repository = $repository ?? new FolderRepository();
		$this->access     = $access ?? new AccessPolicy();
		$this->acl        = $acl ?? new EffectiveAcl( $this->repository, null, $this->access );
	}

	/**
	 * Normalise a request folder selection.
	 *
	 * null = All Media, 0 = Uncategorized, positive = folder ID.
	 *
	 * @param mixed $value Raw request value.
	 * @return int|null|WP_Error
	 */
	public function selection_from_request( $value ) {
		if ( null === $value || '' === $value || 'all' === $value ) {
			return null;
		}

		if ( 'uncategorized' === $value || 0 === $value || '0' === $value ) {
			return 0;
		}

		$folder_id = absint( $value );

		if ( $folder_id < 1 ) {
			return new WP_Error(
				'media_manager_invalid_folder',
				__( 'Invalid folder selection.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$folder = $this->repository->find( $folder_id );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		if ( ! $this->acl->can_access_folder( $folder_id ) ) {
			return new WP_Error(
				'media_manager_forbidden_folder',
				__( 'You cannot access that folder.', 'media-manager' ),
				[ 'status' => 403 ]
			);
		}

		return $folder_id;
	}

	/**
	 * Apply a folder filter to a WP_Query-style args array.
	 *
	 * @param array    $query     Query args.
	 * @param int|null $folder_id Folder selection.
	 * @return array
	 */
	public function apply( array $query, $folder_id ): array {
		if ( null !== $folder_id ) {
			$tax_query = isset( $query['tax_query'] ) && is_array( $query['tax_query'] )
				? $query['tax_query']
				: [];

			if ( 0 === (int) $folder_id ) {
				$tax_query[] = [
					'taxonomy' => FolderTaxonomy::NAME,
					'operator' => 'NOT EXISTS',
				];
			} else {
				$folder = $this->repository->find( (int) $folder_id );

				if ( is_wp_error( $folder ) ) {
					$query['post__in'] = [ 0 ];
					return $query;
				}

				$tax_query[] = [
					'taxonomy'         => FolderTaxonomy::NAME,
					'field'            => 'term_id',
					'terms'            => [ (int) $folder_id ],
					'include_children' => true,
				];
			}

			if ( count( $tax_query ) > 1 && ! isset( $tax_query['relation'] ) ) {
				$tax_query['relation'] = 'AND';
			}

			$query['tax_query'] = $tax_query;
		}

		return $this->acl->apply_to_query( $query, $folder_id );
	}

	/**
	 * Register query filters.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'ajax_query_attachments_args', [ $this, 'filter_ajax_query' ], 20 );
		add_filter( 'rest_attachment_query', [ $this, 'filter_rest_query' ], 20, 2 );
		add_action( 'pre_get_posts', [ $this, 'filter_list_table_query' ], 20 );
	}

	/**
	 * Filter classic media AJAX queries.
	 *
	 * @param array $query Query args.
	 * @return array
	 */
	public function filter_ajax_query( array $query ): array {
		$raw = null;

		if ( isset( $_REQUEST['query']['media_manager_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_REQUEST['query']['media_manager_folder'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( isset( $_REQUEST['media_manager_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_REQUEST['media_manager_folder'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$selection = $this->selection_from_request( $raw );

		if ( is_wp_error( $selection ) ) {
			$query['post__in'] = [ 0 ];
			return $query;
		}

		return $this->apply( $query, $selection );
	}

	/**
	 * Filter REST attachment collection queries.
	 *
	 * @param array            $args    Query args.
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public function filter_rest_query( array $args, $request ): array {
		$raw       = $request->get_param( 'media_manager_folder' );
		$selection = $this->selection_from_request( $raw );

		if ( is_wp_error( $selection ) ) {
			$args['post__in'] = [ 0 ];
			return $args;
		}

		return $this->apply( $args, $selection );
	}

	/**
	 * Filter Media Library list table queries.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function filter_list_table_query( $query ): void {
		if ( ! is_admin() || ! $query instanceof \WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		$raw = null;
		if ( isset( $_GET['media_manager_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw = wp_unslash( $_GET['media_manager_folder'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$selection = $this->selection_from_request( $raw );

		if ( is_wp_error( $selection ) ) {
			$query->set( 'post__in', [ 0 ] );
			return;
		}

		$args = $this->apply( $query->query_vars, $selection );

		if ( isset( $args['tax_query'] ) ) {
			$query->set( 'tax_query', $args['tax_query'] );
		}

		if ( isset( $args['post__in'] ) ) {
			$query->set( 'post__in', $args['post__in'] );
		}
	}
}
