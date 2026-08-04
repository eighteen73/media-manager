<?php
/**
 * Folder download REST endpoint tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Folders\FolderZipService;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Permissions\FolderPolicy;

/**
 * Tests folder zip download endpoint and service.
 */
class Test_Folders_Download extends WP_UnitTestCase {

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
	 * Unauthenticated download is rejected.
	 *
	 * @return void
	 */
	public function test_unauthenticated_download_forbidden() {
		wp_set_current_user( 0 );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		$folder = ( new FolderService() )->create( 'Public' );
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/media-manager/v1/folders/' . (int) $folder->term_id . '/download' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Users without folder access cannot download.
	 *
	 * @return void
	 */
	public function test_inaccessible_folder_download_forbidden() {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $admin );

		$folder = ( new FolderService() )->create(
			'Secret',
			0,
			[
				'access_mode'   => FolderPolicy::MODE_USERS,
				'allowed_users' => [ $admin ],
			]
		);

		wp_set_current_user( $editor );
		$request  = new WP_REST_Request( 'GET', '/media-manager/v1/folders/' . (int) $folder->term_id . '/download' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Empty folders return a download error.
	 *
	 * @return void
	 */
	public function test_empty_folder_download_error() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$folder   = ( new FolderService() )->create( 'Empty' );
		$request  = new WP_REST_Request( 'GET', '/media-manager/v1/folders/' . (int) $folder->term_id . '/download' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'media_manager_download_empty', $response->get_data()['code'] );
	}

	/**
	 * Successful download returns zip headers and archive contains originals.
	 *
	 * @return void
	 */
	public function test_download_includes_full_size_original() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is required for download tests.' );
		}

		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$folder     = ( new FolderService() )->create( 'Photos' );
		$attachment = $this->create_attachment_with_file( 'hero-original.jpg', 'full-size-bytes' );
		$basename   = wp_basename( (string) get_attached_file( $attachment ) );
		( new FolderRepository() )->set_attachment_folders( $attachment, [ (int) $folder->term_id ], false );

		// Simulate a crop sibling that must not be included.
		$meta = [
			'width'  => 100,
			'height' => 100,
			'file'   => $basename,
			'sizes'  => [
				'thumbnail' => [
					'file'      => pathinfo( $basename, PATHINFO_FILENAME ) . '-150x150.jpg',
					'width'     => 150,
					'height'    => 150,
					'mime-type' => 'image/jpeg',
				],
			],
		];
		update_post_meta( $attachment, '_wp_attachment_metadata', $meta );

		$request  = new WP_REST_Request( 'GET', '/media-manager/v1/folders/' . (int) $folder->term_id . '/download' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );

		$headers = $response->get_headers();
		$this->assertSame( 'application/zip', $headers['Content-Type'] );
		$this->assertStringContainsString( 'Photos.zip', $headers['Content-Disposition'] );

		$archive = ( new FolderZipService() )->create_archive( (int) $folder->term_id );
		$this->assertIsArray( $archive );
		$this->assertFileExists( $archive['path'] );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $archive['path'] ) );
		$names = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		wp_delete_file( $archive['path'] );

		$this->assertContains( $basename, $names );
		$this->assertNotContains( $meta['sizes']['thumbnail']['file'], $names );
	}

	/**
	 * Parent download includes nested child-folder media.
	 *
	 * @return void
	 */
	public function test_download_includes_child_folder_media() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is required for download tests.' );
		}

		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$service  = new FolderService();
		$parent   = $service->create( 'Parent' );
		$child    = $service->create( 'Child', (int) $parent->term_id );
		$repo     = new FolderRepository();
		$parent_a = $this->create_attachment_with_file( 'parent.jpg', 'parent-bytes' );
		$child_a  = $this->create_attachment_with_file( 'child.jpg', 'child-bytes' );
		$parent_b = wp_basename( (string) get_attached_file( $parent_a ) );
		$child_b  = wp_basename( (string) get_attached_file( $child_a ) );
		$repo->set_attachment_folders( $parent_a, [ (int) $parent->term_id ], false );
		$repo->set_attachment_folders( $child_a, [ (int) $child->term_id ], false );

		$archive = ( new FolderZipService() )->create_archive( (int) $parent->term_id );
		$this->assertIsArray( $archive );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $archive['path'] ) );
		$names = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		wp_delete_file( $archive['path'] );

		$this->assertContains( $parent_b, $names );
		$this->assertContains( $child_b, $names );
	}

	/**
	 * ACL-hidden attachments are excluded from the archive.
	 *
	 * @return void
	 */
	public function test_download_excludes_inaccessible_attachments() {
		if ( ! class_exists( ZipArchive::class ) ) {
			$this->markTestSkipped( 'ZipArchive is required for download tests.' );
		}

		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $admin );

		$service = new FolderService();
		$parent  = $service->create( 'Mix' );
		$visible_folder = $service->create( 'Visible', (int) $parent->term_id );
		$hidden_folder  = $service->create(
			'Hidden',
			(int) $parent->term_id,
			[
				'access_mode'   => FolderPolicy::MODE_USERS,
				'allowed_users' => [ $admin ],
			]
		);

		$repo    = new FolderRepository();
		$visible = $this->create_attachment_with_file( 'visible.jpg', 'visible' );
		$hidden  = $this->create_attachment_with_file( 'hidden.jpg', 'hidden' );
		$visible_name = wp_basename( (string) get_attached_file( $visible ) );
		$hidden_name  = wp_basename( (string) get_attached_file( $hidden ) );
		$repo->set_attachment_folders( $visible, [ (int) $visible_folder->term_id ], false );
		$repo->set_attachment_folders( $hidden, [ (int) $hidden_folder->term_id ], false );

		wp_set_current_user( $editor );
		$archive = ( new FolderZipService() )->create_archive( (int) $parent->term_id );
		$this->assertIsArray( $archive );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $archive['path'] ) );
		$names = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		wp_delete_file( $archive['path'] );

		$this->assertContains( $visible_name, $names );
		$this->assertNotContains( $hidden_name, $names );
	}

	/**
	 * Create an attachment with a real upload file on disk.
	 *
	 * @param string $filename Filename.
	 * @param string $contents File contents.
	 * @return int Attachment ID.
	 */
	private function create_attachment_with_file( string $filename, string $contents ): int {
		// Keep uploads unique so leftover files from prior runs don't rename basenames unexpectedly.
		$unique   = pathinfo( $filename, PATHINFO_FILENAME ) . '-' . wp_generate_password( 8, false, false );
		$ext      = pathinfo( $filename, PATHINFO_EXTENSION );
		$filename = $ext ? $unique . '.' . $ext : $unique;

		$upload = wp_upload_bits( $filename, null, $contents );
		$this->assertEmpty( $upload['error'], (string) $upload['error'] );

		$attachment_id = self::factory()->attachment->create_object(
			[
				'file'           => $upload['file'],
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $filename,
			]
		);

		update_attached_file( $attachment_id, $upload['file'] );

		return (int) $attachment_id;
	}
}
