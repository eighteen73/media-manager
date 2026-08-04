<?php
/**
 * Assignments REST controller tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Admin\Settings;
use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\PrivateMedia\TransitionService;

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

	/**
	 * Moving into a private folder requires confirm.
	 *
	 * @return void
	 */
	public function test_private_enter_requires_confirmation() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$this->configure_private_storage();

		$folder     = ( new FolderService() )->create( 'Private Assign', 0, [ 'is_private' => true ] );
		$attachment = $this->create_text_attachment( 'assign-confirm.txt', 'confirm-me' );

		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => (int) $folder->term_id,
				'mode'           => 'move',
				'confirm'        => false,
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'media_manager_confirmation_required', $response->get_data()['code'] );
		$this->assertTrue( $response->get_data()['data']['needs_enter'] );
	}

	/**
	 * Add mode into a private folder is rejected.
	 *
	 * @return void
	 */
	public function test_private_requires_move_mode() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$this->configure_private_storage();

		$folder     = ( new FolderService() )->create( 'Private Add', 0, [ 'is_private' => true ] );
		$attachment = $this->create_text_attachment( 'assign-add.txt', 'add-me' );

		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => (int) $folder->term_id,
				'mode'           => 'add',
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'media_manager_private_requires_move', $response->get_data()['code'] );
	}

	/**
	 * Confirmed move into private storage lands the file privately.
	 *
	 * @return void
	 */
	public function test_private_enter_with_confirm_moves_file() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$this->configure_private_storage();

		$folder     = ( new FolderService() )->create( 'Private Confirmed', 0, [ 'is_private' => true ] );
		$attachment = $this->create_text_attachment( 'assign-move.txt', 'move-me' );
		$uploads    = wp_upload_dir();
		$public     = trailingslashit( $uploads['basedir'] ) . get_post_meta( $attachment, '_wp_attached_file', true );

		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => (int) $folder->term_id,
				'mode'           => 'move',
				'confirm'        => true,
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( ( new TransitionService() )->is_private( $attachment ) );
		$this->assertFalse( file_exists( $public ) );
		$this->assertSame(
			[ (int) $folder->term_id ],
			( new FolderRepository() )->attachment_folder_ids( $attachment )
		);
	}

	/**
	 * Confirmed leave restores a public folder membership.
	 *
	 * @return void
	 */
	public function test_private_leave_with_confirm() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );
		$this->configure_private_storage();

		$service    = new FolderService();
		$private    = $service->create( 'Leave From', 0, [ 'is_private' => true ] );
		$public     = $service->create( 'Leave To' );
		$attachment = $this->create_text_attachment( 'assign-leave.txt', 'leave-me' );
		( new TransitionService() )->enter_private( $attachment, (int) $private->term_id );

		$request = new WP_REST_Request( 'POST', '/media-manager/v1/assignments' );
		$request->set_body_params(
			[
				'attachment_ids' => [ $attachment ],
				'folder_id'      => (int) $public->term_id,
				'mode'           => 'move',
				'confirm'        => true,
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertFalse( ( new TransitionService() )->is_private( $attachment ) );
		$this->assertSame(
			[ (int) $public->term_id ],
			( new FolderRepository() )->attachment_folder_ids( $attachment )
		);
	}

	/**
	 * Point settings at a writable private root for this test class.
	 *
	 * @return void
	 */
	private function configure_private_storage(): void {
		$root = trailingslashit( sys_get_temp_dir() ) . 'mm-assign-private-' . wp_generate_password( 6, false );
		wp_mkdir_p( $root );
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				[ 'private_storage_path' => $root ]
			),
			false
		);
	}

	/**
	 * Create a text attachment with a real uploads file.
	 *
	 * @param string $filename Filename under uploads.
	 * @param string $contents File contents.
	 * @return int Attachment ID.
	 */
	private function create_text_attachment( string $filename, string $contents ): int {
		$uploads = wp_upload_dir();
		$file    = trailingslashit( $uploads['basedir'] ) . $filename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, $contents );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'text/plain',
				'post_title'     => $filename,
			]
		);
		update_attached_file( $attachment, $file );

		return (int) $attachment;
	}
}
