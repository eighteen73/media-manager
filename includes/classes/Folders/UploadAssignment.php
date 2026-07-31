<?php
/**
 * Upload-time folder assignment.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

use Eighteen73\MediaManager\ContentFolders\ContentFolderService;
use Eighteen73\MediaManager\Permissions\AccessPolicy;
use Eighteen73\MediaManager\Permissions\AclCache;
use Eighteen73\MediaManager\Permissions\FolderPolicy;
use Eighteen73\MediaManager\PrivateMedia\PrivateStorage;
use Eighteen73\MediaManager\PrivateMedia\StorageConfig;
use Eighteen73\MediaManager\PrivateMedia\TransitionService;
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
	 * Folder policy.
	 *
	 * @var FolderPolicy
	 */
	private FolderPolicy $policy;

	/**
	 * Transition service.
	 *
	 * @var TransitionService
	 */
	private TransitionService $transitions;

	/**
	 * Private storage.
	 *
	 * @var PrivateStorage
	 */
	private PrivateStorage $storage;

	/**
	 * Content folder service.
	 *
	 * @var ContentFolderService
	 */
	private ContentFolderService $content_folders;

	/**
	 * Attachments already assigned in this request.
	 *
	 * @var array<int, true>
	 */
	private array $assigned = [];

	/**
	 * Whether upload_dir override is active.
	 *
	 * @var bool
	 */
	private bool $private_upload_active = false;

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null     $repository      Optional repository.
	 * @param AttachmentQuery|null      $queries         Optional query helper.
	 * @param AccessPolicy|null         $access          Optional access policy.
	 * @param FolderPolicy|null         $policy          Optional policy.
	 * @param TransitionService|null    $transitions     Optional transitions.
	 * @param PrivateStorage|null       $storage         Optional storage.
	 * @param ContentFolderService|null $content_folders Optional content folders.
	 */
	public function __construct(
		?FolderRepository $repository = null,
		?AttachmentQuery $queries = null,
		?AccessPolicy $access = null,
		?FolderPolicy $policy = null,
		?TransitionService $transitions = null,
		?PrivateStorage $storage = null,
		?ContentFolderService $content_folders = null
	) {
		$this->repository      = $repository ?? new FolderRepository();
		$this->access          = $access ?? new AccessPolicy();
		$this->queries         = $queries ?? new AttachmentQuery( $this->repository, null, $this->access );
		$this->policy          = $policy ?? new FolderPolicy( $this->repository );
		$this->transitions     = $transitions ?? new TransitionService( null, $this->repository, $this->policy );
		$this->storage         = $storage ?? new PrivateStorage();
		$this->content_folders = $content_folders ?? new ContentFolderService( null, null, $this->repository );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'upload_dir', [ $this, 'filter_upload_dir' ], 20 );
		add_action( 'add_attachment', [ $this, 'on_add_attachment' ] );
		add_action( 'rest_after_insert_attachment', [ $this, 'on_rest_insert' ], 10, 3 );
	}

	/**
	 * Redirect uploads into private storage when the target folder is private.
	 *
	 * @param array $uploads Upload dir data.
	 * @return array
	 */
	public function filter_upload_dir( array $uploads ): array {
		$folder_id = $this->resolve_upload_folder();

		if ( null === $folder_id || $folder_id < 1 ) {
			return $uploads;
		}

		if ( ! $this->access->can_assign_folders() || ! $this->access->can_access_folder( $folder_id ) ) {
			return $uploads;
		}

		$policy = $this->policy->get_effective( $folder_id );
		if ( is_wp_error( $policy ) || empty( $policy['is_private'] ) ) {
			return $uploads;
		}

		if ( ! ( new StorageConfig() )->is_ready() ) {
			return $uploads;
		}

		$root = $this->storage->site_root();
		if ( is_wp_error( $root ) ) {
			return $uploads;
		}

		$subdir  = isset( $uploads['subdir'] ) ? (string) $uploads['subdir'] : '';
		$basedir = $root;
		$baseurl = home_url( '/media' ); // Placeholder base; delivery URLs are rewritten.
		$path    = $basedir . $subdir;

		if ( ! is_dir( $path ) ) {
			wp_mkdir_p( $path );
		}

		$uploads['path']    = $path;
		$uploads['url']     = $baseurl . $subdir;
		$uploads['basedir'] = $basedir;
		$uploads['baseurl'] = $baseurl;

		$this->private_upload_active = true;

		return $uploads;
	}

	/**
	 * Handle classic uploads.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_add_attachment( int $attachment_id ): void {
		$folder_id = $this->resolve_upload_folder();

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

		if ( ! is_wp_error( $selection ) && null !== $selection && $selection > 0 ) {
			$this->assign_from_request( (int) $attachment->ID, (int) $selection );
			return;
		}

		$folder_id = $this->folder_from_post_context( $request );
		if ( null === $folder_id || $folder_id < 1 ) {
			return;
		}

		$this->assign_from_request( (int) $attachment->ID, $folder_id );
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

		if ( ! $this->access->can_access_folder( $folder_id ) ) {
			return new WP_Error(
				'media_manager_forbidden_folder',
				__( 'You cannot assign media to that folder.', 'media-manager' ),
				[ 'status' => 403 ]
			);
		}

		$folder = $this->repository->find( $folder_id );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		$policy = $this->policy->get_effective( $folder_id );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		if ( ! empty( $policy['is_private'] ) || $this->private_upload_active ) {
			$result = $this->transitions->mark_uploaded_private( $attachment_id, $folder_id );
		} else {
			$result = $this->repository->set_attachment_folders( $attachment_id, [ $folder_id ], false );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->assigned[ $attachment_id ] = true;
		AclCache::invalidate_site();

		return true;
	}

	/**
	 * Resolve the upload target folder (explicit request param, else post context).
	 *
	 * @return int|null
	 */
	private function resolve_upload_folder() {
		$from_request = $this->folder_from_request();
		if ( null !== $from_request && $from_request > 0 ) {
			return $from_request;
		}

		return $this->folder_from_post_context();
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

		$raw = wp_unslash( $_REQUEST['media_manager_folder'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( null === $raw || '' === $raw || 'all' === $raw || 'uncategorized' === $raw || '0' === $raw || 0 === $raw ) {
			return null;
		}

		$folder_id = absint( $raw );
		if ( $folder_id < 1 ) {
			return null;
		}

		$folder = $this->repository->find( $folder_id );
		if ( is_wp_error( $folder ) ) {
			return null;
		}

		return $folder_id;
	}

	/**
	 * Resolve a generated folder from the post being edited.
	 *
	 * @param \WP_REST_Request|null $request Optional REST request.
	 * @return int|null
	 */
	private function folder_from_post_context( $request = null ) {
		$post_id = 0;

		if ( $request instanceof \WP_REST_Request ) {
			$post_id = absint( $request->get_param( 'post' ) );
			if ( $post_id < 1 ) {
				$post_id = absint( $request->get_param( 'post_id' ) );
			}
		}

		if ( $post_id < 1 && isset( $_REQUEST['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( wp_unslash( $_REQUEST['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $post_id < 1 && isset( $_REQUEST['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( wp_unslash( $_REQUEST['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $post_id < 1 || ! $this->content_folders->is_enabled_for_post( $post_id ) ) {
			return null;
		}

		$folder = $this->content_folders->ensure_post_folder( $post_id );
		if ( is_wp_error( $folder ) ) {
			return null;
		}

		return (int) $folder->term_id;
	}
}
