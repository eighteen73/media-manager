<?php
/**
 * Upload-time folder assignment.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

use Eighteen73\MediaManager\Permissions\AccessPolicy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Assigns newly uploaded attachments to a selected folder.
 */
class UploadAssignment {

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Attachment query helper.
	 *
	 * @var AttachmentQuery
	 */
	private AttachmentQuery $queries;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Attachments already assigned in this request.
	 *
	 * @var array<int, true>
	 */
	private array $assigned = [];

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null $repository Optional repository.
	 * @param AttachmentQuery|null  $queries    Optional query helper.
	 * @param AccessPolicy|null     $access     Optional access policy.
	 */
	public function __construct(
		?FolderRepository $repository = null,
		?AttachmentQuery $queries = null,
		?AccessPolicy $access = null
	) {
		$this->repository = $repository ?? new FolderRepository();
		$this->queries    = $queries ?? new AttachmentQuery( $this->repository );
		$this->access     = $access ?? new AccessPolicy();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_attachment', [ $this, 'on_add_attachment' ] );
		add_action( 'rest_after_insert_attachment', [ $this, 'on_rest_insert' ], 10, 3 );
	}

	/**
	 * Handle classic uploads.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_add_attachment( int $attachment_id ): void {
		$folder_id = $this->folder_from_request();

		if ( null === $folder_id || $folder_id < 1 ) {
			return;
		}

		$this->assign_from_request( $attachment_id, $folder_id );
	}

	/**
	 * Handle REST uploads.
	 *
	 * @param \WP_Post         $attachment Attachment post.
	 * @param \WP_REST_Request $request    Request.
	 * @param bool             $creating   Whether creating.
	 * @return void
	 */
	public function on_rest_insert( $attachment, $request, bool $creating ): void {
		if ( ! $creating || ! $attachment instanceof \WP_Post ) {
			return;
		}

		$raw       = $request->get_param( 'media_manager_folder' );
		$selection = $this->queries->selection_from_request( $raw );

		if ( is_wp_error( $selection ) || null === $selection || $selection < 1 ) {
			return;
		}

		$this->assign_from_request( (int) $attachment->ID, (int) $selection );
	}

	/**
	 * Assign an attachment to a folder from an upload context.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $folder_id     Folder ID.
	 * @return true|WP_Error
	 */
	public function assign_from_request( int $attachment_id, int $folder_id ) {
		if ( isset( $this->assigned[ $attachment_id ] ) ) {
			return true;
		}

		if ( ! $this->access->can_assign_folders() || ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error(
				'media_manager_forbidden',
				__( 'You are not allowed to assign this media to a folder.', 'media-manager' ),
				[ 'status' => 403 ]
			);
		}

		$folder = $this->repository->find( $folder_id );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		$result = $this->repository->set_attachment_folders( $attachment_id, [ $folder_id ], false );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->assigned[ $attachment_id ] = true;

		return true;
	}

	/**
	 * Read folder ID from the current request.
	 *
	 * @return int|null
	 */
	private function folder_from_request() {
		if ( ! isset( $_REQUEST['media_manager_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return null;
		}

		$raw       = wp_unslash( $_REQUEST['media_manager_folder'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$selection = $this->queries->selection_from_request( $raw );

		if ( is_wp_error( $selection ) ) {
			return null;
		}

		return $selection;
	}
}
