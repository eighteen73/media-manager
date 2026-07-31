<?php
/**
 * Security regression tests for public folder writes.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Install\Installer;

/**
 * Tests unauthorised write paths.
 */
class Test_Security_Regressions extends WP_UnitTestCase {

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
	 * Unauthenticated assignment leaves relationships unchanged.
	 *
	 * @return void
	 */
	public function test_unauthenticated_assignment_is_noop() {
		$folder = ( new FolderService() )->create( 'Secure' );
		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'secure.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Secure',
			]
		);

		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => (int) $folder->term_id,
				'mode'           => 'move',
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertTrue( in_array( $response->get_status(), [ 401, 403 ], true ) );
		$this->assertSame( [], ( new FolderRepository() )->attachment_folder_ids( $attachment ) );
	}

	/**
	 * Forged folder IDs do not create relationships.
	 *
	 * @return void
	 */
	public function test_forged_folder_id_rejected() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'forged.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Forged',
				'post_author'    => $user,
			]
		);

		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => 999999,
				'mode'           => 'move',
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( [], ( new FolderRepository() )->attachment_folder_ids( $attachment ) );
	}
}
