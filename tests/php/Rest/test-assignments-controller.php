<?php
/**
 * Assignments REST controller tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Install\Installer;

/**
 * Tests assignment REST endpoint.
 */
class Test_Assignments_Controller extends WP_UnitTestCase {

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
	 * Add appends; move replaces; uncategorized move clears.
	 *
	 * @return void
	 */
	public function test_add_and_move_modes() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$service = new FolderService();
		$one     = $service->create( 'One' );
		$two     = $service->create( 'Two' );
		$repo    = new FolderRepository();

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'item.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Item',
				'post_author'    => $user,
			]
		);
		$file_before = get_attached_file( $attachment );
		$url_before  = wp_get_attachment_url( $attachment );

		$add = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$add->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => (int) $one->term_id,
				'mode'           => 'add',
			]
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $add )->get_status() );
		$this->assertSame( [ (int) $one->term_id ], $repo->attachment_folder_ids( $attachment ) );

		$move = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$move->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => (int) $two->term_id,
				'mode'           => 'move',
			]
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $move )->get_status() );
		$this->assertSame( [ (int) $two->term_id ], $repo->attachment_folder_ids( $attachment ) );

		$clear = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$clear->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => 0,
				'mode'           => 'move',
			]
		);
		$this->assertSame( 200, rest_get_server()->dispatch( $clear )->get_status() );
		$this->assertSame( [], $repo->attachment_folder_ids( $attachment ) );
		$this->assertSame( $file_before, get_attached_file( $attachment ) );
		$this->assertSame( $url_before, wp_get_attachment_url( $attachment ) );
	}

	/**
	 * More than 100 attachments are rejected.
	 *
	 * @return void
	 */
	public function test_max_items_enforced() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$folder = ( new FolderService() )->create( 'Bulk' );
		$ids    = range( 1, 101 );

		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => $ids,
				'folder_id'      => (int) $folder->term_id,
				'mode'           => 'move',
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'media_manager_too_many_attachments', $response->get_data()['code'] );
	}

	/**
	 * Add to uncategorized is rejected.
	 *
	 * @return void
	 */
	public function test_add_to_uncategorized_rejected() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'item2.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Item 2',
				'post_author'    => $user,
			]
		);

		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => 0,
				'mode'           => 'add',
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'media_manager_invalid_folder', $response->get_data()['code'] );
	}
}
