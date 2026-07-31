<?php
/**
 * Folders REST controller tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Install\Installer;

/**
 * Tests folder REST endpoints.
 */
class Test_Folders_Controller extends WP_UnitTestCase {

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
	 * Unauthenticated requests are rejected.
	 *
	 * @return void
	 */
	public function test_unauthenticated_get_forbidden() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/media-manager/v1/folders' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Editors can view but not create folders.
	 *
	 * @return void
	 */
	public function test_editor_can_view_not_create() {
		$user = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $user );

		$get = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/media-manager/v1/folders' ) );
		$this->assertSame( 200, $get->get_status() );

		$post = new WP_REST_Request( 'POST', '/media-manager/v1/folders' );
		$post->set_param( 'name', 'Nope' );
		$create = rest_get_server()->dispatch( $post );
		$this->assertSame( 403, $create->get_status() );
	}

	/**
	 * Administrators can CRUD folders.
	 *
	 * @return void
	 */
	public function test_administrator_crud() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$create = new WP_REST_Request( 'POST', '/media-manager/v1/folders' );
		$create->set_param( 'name', 'Admin Folder' );
		$created = rest_get_server()->dispatch( $create );
		$this->assertSame( 201, $created->get_status() );
		$data = $created->get_data();
		$this->assertSame( 'Admin Folder', $data['name'] );

		$update = new WP_REST_Request( 'POST', '/media-manager/v1/folders/' . $data['id'] );
		$update->set_param( 'name', 'Renamed' );
		$update->set_param( 'parent', 0 );
		$updated = rest_get_server()->dispatch( $update );
		$this->assertSame( 200, $updated->get_status() );
		$this->assertSame( 'Renamed', $updated->get_data()['name'] );

		$delete  = new WP_REST_Request( 'DELETE', '/media-manager/v1/folders/' . $data['id'] );
		$deleted = rest_get_server()->dispatch( $delete );
		$this->assertSame( 200, $deleted->get_status() );
		$this->assertTrue( $deleted->get_data()['deleted'] );
	}

	/**
	 * Child delete conflicts return a stable error code.
	 *
	 * @return void
	 */
	public function test_delete_child_conflict() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$service = new FolderService();
		$parent  = $service->create( 'Parent' );
		$service->create( 'Child', (int) $parent->term_id );

		$delete   = new WP_REST_Request( 'DELETE', '/media-manager/v1/folders/' . $parent->term_id );
		$response = rest_get_server()->dispatch( $delete );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'media_manager_folder_has_children', $response->get_data()['code'] );
	}
}
