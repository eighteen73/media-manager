<?php
/**
 * ACL-aware query and REST regression tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\AttachmentQuery;
use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Permissions\FolderPolicy;

/**
 * Tests query/REST ACL enforcement.
 */
class Test_Acl_Queries extends WP_UnitTestCase {

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	/**
	 * Selecting an inaccessible folder returns an empty query.
	 *
	 * @return void
	 */
	public function test_inaccessible_folder_selection_forbidden() {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $admin );

		$folder = ( new FolderService() )->create(
			'Hidden',
			0,
			[
				'access_mode'   => FolderPolicy::MODE_USERS,
				'allowed_users' => [ $admin ],
			]
		);

		wp_set_current_user( $editor );
		$selection = ( new AttachmentQuery() )->selection_from_request( (int) $folder->term_id );
		$this->assertWPError( $selection );
		$this->assertSame( 'media_manager_forbidden_folder', $selection->get_error_code() );
	}

	/**
	 * Crafted assignment to a restricted folder is rejected for editors.
	 *
	 * @return void
	 */
	public function test_assignment_to_restricted_folder_denied() {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $admin );

		$folder = ( new FolderService() )->create(
			'Admins Only',
			0,
			[
				'access_mode'   => FolderPolicy::MODE_USERS,
				'allowed_users' => [ $admin ],
			]
		);

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'denied.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Denied',
				'post_author'    => $editor,
			]
		);

		wp_set_current_user( $editor );
		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => (int) $folder->term_id,
				'mode'           => 'move',
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], ( new FolderRepository() )->attachment_folder_ids( $attachment ) );
	}

	/**
	 * Folder tree omits inaccessible folders for editors.
	 *
	 * @return void
	 */
	public function test_folders_list_omits_inaccessible() {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $admin );

		( new FolderService() )->create( 'Visible' );
		( new FolderService() )->create(
			'Hidden',
			0,
			[
				'access_mode'   => FolderPolicy::MODE_USERS,
				'allowed_users' => [ $admin ],
			]
		);

		wp_set_current_user( $editor );
		$request  = new WP_REST_Request( 'GET', '/media-manager/v1/folders' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$names    = wp_list_pluck( $data['folders'], 'name' );

		$this->assertContains( 'Visible', $names );
		$this->assertNotContains( 'Hidden', $names );
	}
}
