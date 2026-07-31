<?php
/**
 * Upload assignment tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\UploadAssignment;
use Eighteen73\MediaManager\Install\Installer;

/**
 * Tests upload-time folder assignment.
 */
class Test_Upload_Assignment extends WP_UnitTestCase {

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
	}

	/**
	 * Classic uploads assign once using the request folder.
	 *
	 * @return void
	 */
	public function test_classic_upload_assignment() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$folder = ( new FolderService() )->create( 'Uploads' );
		$_REQUEST['media_manager_folder'] = (string) $folder->term_id;

		$assignment = new UploadAssignment();
		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'upload.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Upload',
				'post_author'    => $user,
			]
		);

		$assignment->on_add_attachment( $attachment );
		$assignment->on_add_attachment( $attachment );

		$ids = ( new FolderRepository() )->attachment_folder_ids( $attachment );
		$this->assertSame( [ (int) $folder->term_id ], $ids );

		unset( $_REQUEST['media_manager_folder'] );
	}

	/**
	 * Assignment fails without assign capability.
	 *
	 * @return void
	 */
	public function test_assignment_requires_capability() {
		$user = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $user );

		$folder     = ( new FolderService() )->create( 'Restricted' );
		$assignment = new UploadAssignment();
		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'denied.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Denied',
				'post_author'    => $user,
			]
		);

		$result = $assignment->assign_from_request( $attachment, (int) $folder->term_id );
		$this->assertWPError( $result );
		$this->assertSame( 'media_manager_forbidden', $result->get_error_code() );
	}
}
