<?php
/**
 * Private storage and transition tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Admin\Settings;
use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Folders\UploadAssignment;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Permissions\FolderPolicy;
use Eighteen73\MediaManager\PrivateMedia\Delivery;
use Eighteen73\MediaManager\PrivateMedia\PrivateStorage;
use Eighteen73\MediaManager\PrivateMedia\StorageConfig;
use Eighteen73\MediaManager\PrivateMedia\TransitionService;
use Eighteen73\MediaManager\PrivateMedia\UrlFilters;

/**
 * Tests private storage health, transitions, and delivery auth.
 */
class Test_Private_Media extends WP_UnitTestCase {

	/**
	 * Temporary private root.
	 *
	 * @var string
	 */
	private $private_root;

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );

		$this->private_root = trailingslashit( sys_get_temp_dir() ) . 'mm-private-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->private_root );

		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				[ 'private_storage_path' => $this->private_root ]
			),
			false
		);
	}

	/**
	 * Teardown.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->rrmdir( $this->private_root );
		parent::tear_down();
	}

	/**
	 * Health check passes for an outside-root writable path.
	 *
	 * @return void
	 */
	public function test_storage_health_ok() {
		$health = ( new StorageConfig() )->health();
		$this->assertTrue( $health['ok'], $health['message'] );
	}

	/**
	 * Empty setting falls back to the code-derived default path.
	 *
	 * @return void
	 */
	public function test_empty_setting_uses_default_path() {
		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				[ 'private_storage_path' => '' ]
			),
			false
		);

		$config = new StorageConfig();
		$this->assertSame( StorageConfig::default_path(), $config->path() );
		$this->assertStringEndsWith( '/private', $config->path() );
	}

	/**
	 * Default private path can be overridden via filter.
	 *
	 * @return void
	 */
	public function test_default_path_filter() {
		$override = trailingslashit( sys_get_temp_dir() ) . 'mm-default-override-' . wp_generate_password( 6, false );
		wp_mkdir_p( $override );

		add_filter(
			'media_manager_default_private_storage_path',
			static function () use ( $override ) {
				return $override;
			}
		);

		update_option(
			Settings::OPTION,
			array_merge(
				Settings::defaults(),
				[ 'private_storage_path' => '' ]
			),
			false
		);

		$this->assertSame( untrailingslashit( $override ), ( new StorageConfig() )->path() );

		remove_all_filters( 'media_manager_default_private_storage_path' );
		$this->rrmdir( $override );
	}

	/**
	 * Path traversal is rejected.
	 *
	 * @return void
	 */
	public function test_path_traversal_rejected() {
		$storage = new PrivateStorage();
		$result  = $storage->absolute_path( '../escape.txt' );
		$this->assertWPError( $result );
	}

	/**
	 * Public to private transition copies, verifies, and removes public source.
	 *
	 * @return void
	 */
	public function test_enter_private_moves_file() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$uploads = wp_upload_dir();
		$file    = trailingslashit( $uploads['basedir'] ) . 'private-source.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, 'secret-bytes' );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Private Source',
			]
		);
		update_attached_file( $attachment, $file );

		$folder = ( new FolderService() )->create(
			'Vault',
			0,
			[
				'is_private' => true,
			]
		);
		$this->assertInstanceOf( WP_Term::class, $folder );

		$transitions = new TransitionService();
		$result      = $transitions->enter_private( $attachment, (int) $folder->term_id );
		$this->assertTrue( $result );
		$this->assertTrue( $transitions->is_private( $attachment ) );
		$this->assertFalse( file_exists( $file ) );

		$relative = get_post_meta( $attachment, '_wp_attached_file', true );
		$private  = ( new PrivateStorage() )->absolute_path( $relative );
		$this->assertIsString( $private );
		$this->assertFileExists( $private );
		$this->assertSame( 'secret-bytes', file_get_contents( $private ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	}

	/**
	 * Absolute private `_wp_attached_file` values are repaired and can leave private storage.
	 *
	 * @return void
	 */
	public function test_leave_private_repairs_absolute_attached_file_meta() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$uploads = wp_upload_dir();
		$file    = trailingslashit( $uploads['basedir'] ) . 'absolute-meta.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, 'absolute-meta' );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Absolute Meta',
			]
		);
		update_attached_file( $attachment, $file );

		$folder = ( new FolderService() )->create(
			'Vault Absolute',
			0,
			[
				'is_private' => true,
			]
		);
		$this->assertInstanceOf( WP_Term::class, $folder );

		$transitions = new TransitionService();
		$this->assertTrue( $transitions->enter_private( $attachment, (int) $folder->term_id ) );

		$relative = get_post_meta( $attachment, '_wp_attached_file', true );
		$private  = ( new PrivateStorage() )->absolute_path( $relative );
		$this->assertIsString( $private );
		$this->assertFileExists( $private );

		// Simulate an image-editor save that stored the absolute private path.
		update_post_meta( $attachment, '_wp_attached_file', $private );

		// Leave to Uncategorized — target folders must be public (not the private vault).
		$result = $transitions->leave_private( $attachment, [] );
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $transitions->is_private( $attachment ) );
		$this->assertSame(
			$relative,
			get_post_meta( $attachment, '_wp_attached_file', true )
		);
		$this->assertFileExists( trailingslashit( $uploads['basedir'] ) . $relative );
	}

	/**
	 * Unauthorised delivery denies access.
	 *
	 * @return void
	 */
	public function test_delivery_denies_wrong_token() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'deliver-me.txt', 'deliver' );
		$folder     = ( new FolderService() )->create( 'Vault 2', 0, [ 'is_private' => true ] );
		$transitions = new TransitionService();
		$transitions->enter_private( $attachment, (int) $folder->term_id );

		wp_set_current_user( 0 );

		$this->expectException( \WPDieException::class );
		( new Delivery() )->deliver( $attachment, 'wrong-token' );
	}

	/**
	 * Leave private into a public folder restores uploads and clears private meta.
	 *
	 * @return void
	 */
	public function test_leave_private_to_public_folder() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'leave-public.txt', 'leave-public' );
		$service    = new FolderService();
		$private    = $service->create( 'Private Leave', 0, [ 'is_private' => true ] );
		$public     = $service->create( 'Public Leave' );
		$this->assertInstanceOf( WP_Term::class, $private );
		$this->assertInstanceOf( WP_Term::class, $public );

		$transitions = new TransitionService();
		$this->assertTrue( $transitions->enter_private( $attachment, (int) $private->term_id ) );

		$result = $transitions->leave_private( $attachment, [ (int) $public->term_id ] );
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertFalse( $transitions->is_private( $attachment ) );
		$this->assertSame(
			[ (int) $public->term_id ],
			( new FolderRepository() )->attachment_folder_ids( $attachment )
		);

		$relative = get_post_meta( $attachment, '_wp_attached_file', true );
		$uploads  = wp_upload_dir();
		$this->assertFileExists( trailingslashit( $uploads['basedir'] ) . $relative );
	}

	/**
	 * Leaving private storage into another private folder is rejected.
	 *
	 * @return void
	 */
	public function test_leave_private_rejects_private_target() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'still-private.txt', 'still-private' );
		$service    = new FolderService();
		$vault_a    = $service->create( 'Vault A', 0, [ 'is_private' => true ] );
		$vault_b    = $service->create( 'Vault B', 0, [ 'is_private' => true ] );

		$transitions = new TransitionService();
		$this->assertTrue( $transitions->enter_private( $attachment, (int) $vault_a->term_id ) );

		$result = $transitions->leave_private( $attachment, [ (int) $vault_b->term_id ] );
		$this->assertWPError( $result );
		$this->assertSame( 'media_manager_still_private_folder', $result->get_error_code() );
	}

	/**
	 * Entering private requires a private folder target.
	 *
	 * @return void
	 */
	public function test_enter_private_rejects_public_folder() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'not-private-folder.txt', 'x' );
		$folder     = ( new FolderService() )->create( 'Public Only' );

		$result = ( new TransitionService() )->enter_private( $attachment, (int) $folder->term_id );
		$this->assertWPError( $result );
		$this->assertSame( 'media_manager_not_private_folder', $result->get_error_code() );
	}

	/**
	 * Toggling folder privacy moves every member attachment.
	 *
	 * @return void
	 */
	public function test_sync_folder_privacy_via_update() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$service    = new FolderService();
		$folder     = $service->create( 'Toggle Privacy' );
		$attachment = $this->create_text_attachment( 'sync-privacy.txt', 'sync-privacy' );
		( new FolderRepository() )->set_attachment_folders( $attachment, [ (int) $folder->term_id ], false );

		$updated = $service->update(
			(int) $folder->term_id,
			'Toggle Privacy',
			0,
			[ 'is_private' => true ]
		);
		$this->assertInstanceOf( WP_Term::class, $updated );

		$transitions = new TransitionService();
		$this->assertTrue( $transitions->is_private( $attachment ) );

		$relative = get_post_meta( $attachment, '_wp_attached_file', true );
		$private  = ( new PrivateStorage() )->absolute_path( $relative );
		$this->assertIsString( $private );
		$this->assertFileExists( $private );

		$public_again = $service->update(
			(int) $folder->term_id,
			'Toggle Privacy',
			0,
			[ 'is_private' => false ]
		);
		$this->assertInstanceOf( WP_Term::class, $public_again );
		$this->assertFalse( $transitions->is_private( $attachment ) );
	}

	/**
	 * Intermediate sizes are moved with the original on enter/leave.
	 *
	 * @return void
	 */
	public function test_enter_and_leave_private_moves_image_sizes() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$uploads = wp_upload_dir();
		$basedir = trailingslashit( $uploads['basedir'] );
		$subdir  = '2024/01/';
		wp_mkdir_p( $basedir . $subdir );

		$original = $basedir . $subdir . 'photo.jpg';
		$thumb    = $basedir . $subdir . 'photo-150x150.jpg';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $original, 'full-image' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $thumb, 'thumb-image' );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => $original,
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Photo',
			]
		);
		update_attached_file( $attachment, $original );
		update_post_meta(
			$attachment,
			'_wp_attachment_metadata',
			[
				'file'   => $subdir . 'photo.jpg',
				'width'  => 800,
				'height' => 600,
				'sizes'  => [
					'thumbnail' => [
						'file'   => 'photo-150x150.jpg',
						'width'  => 150,
						'height' => 150,
					],
				],
			]
		);

		$folder      = ( new FolderService() )->create( 'Sized Vault', 0, [ 'is_private' => true ] );
		$transitions = new TransitionService();
		$this->assertTrue( $transitions->enter_private( $attachment, (int) $folder->term_id ) );
		$this->assertFalse( file_exists( $original ) );
		$this->assertFalse( file_exists( $thumb ) );

		$storage = new PrivateStorage();
		$this->assertFileExists( $storage->absolute_path( $subdir . 'photo.jpg' ) );
		$this->assertFileExists( $storage->absolute_path( $subdir . 'photo-150x150.jpg' ) );

		$public = ( new FolderService() )->create( 'Sized Public' );
		$this->assertTrue( $transitions->leave_private( $attachment, [ (int) $public->term_id ] ) );
		$this->assertFileExists( $original );
		$this->assertFileExists( $thumb );
	}

	/**
	 * Correct token still denies logged-out users.
	 *
	 * @return void
	 */
	public function test_delivery_denies_logged_out_with_valid_token() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'acl-deliver.txt', 'acl-deliver' );
		$folder     = ( new FolderService() )->create( 'Token Vault Deny', 0, [ 'is_private' => true ] );
		$transitions = new TransitionService();
		$this->assertTrue( $transitions->enter_private( $attachment, (int) $folder->term_id ) );
		$token = $transitions->token( $attachment );

		$url = ( new Delivery() )->url( $attachment );
		$this->assertStringContainsString( 'media_manager_private=', $url );
		$this->assertStringContainsString( rawurlencode( $token ), $url );

		wp_set_current_user( 0 );
		$this->expectException( \WPDieException::class );
		( new Delivery() )->deliver( $attachment, $token );
	}

	/**
	 * Correct token still denies users without folder ACL.
	 *
	 * @return void
	 */
	public function test_delivery_denies_restricted_user_with_valid_token() {
		$admin      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'acl-restrict.txt', 'acl-restrict' );
		$folder     = ( new FolderService() )->create(
			'Restricted Vault',
			0,
			[
				'is_private'    => true,
				'access_mode'   => FolderPolicy::MODE_USERS,
				'allowed_users' => [ $admin ],
			]
		);
		$transitions = new TransitionService();
		$this->assertTrue( $transitions->enter_private( $attachment, (int) $folder->term_id ) );
		$token = $transitions->token( $attachment );

		wp_set_current_user( $subscriber );
		$this->expectException( \WPDieException::class );
		( new Delivery() )->deliver( $attachment, $token );
	}

	/**
	 * UrlFilters rewrite public helpers for private attachments.
	 *
	 * @return void
	 */
	public function test_url_filters_rewrite_private_urls() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'url-filter.txt', 'url-filter' );
		$public_url = wp_get_attachment_url( $attachment );
		$this->assertNotFalse( $public_url );
		$this->assertStringNotContainsString( '/media/', $public_url );

		$folder = ( new FolderService() )->create( 'URL Vault', 0, [ 'is_private' => true ] );
		( new TransitionService() )->enter_private( $attachment, (int) $folder->term_id );

		$private_url = wp_get_attachment_url( $attachment );
		$this->assertStringContainsString( 'media_manager_private=', $private_url );

		$filters  = new UrlFilters();
		$response = $filters->filter_attachment_for_js(
			[
				'url'  => $public_url,
				'link' => $public_url,
			],
			get_post( $attachment ),
			false
		);
		$this->assertTrue( $response['media_manager_is_private'] );
		$this->assertStringContainsString( 'media_manager_private=', $response['url'] );
	}

	/**
	 * In-flight journals block transitions; failed journals remain retryable.
	 *
	 * @return void
	 */
	public function test_journal_blocks_in_flight_but_failed_is_retryable() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'journal.txt', 'journal' );
		$folder     = ( new FolderService() )->create( 'Journal Vault', 0, [ 'is_private' => true ] );
		$transitions = new TransitionService();

		update_post_meta(
			$attachment,
			TransitionService::META_JOURNAL,
			[
				'phase' => 'copying',
			]
		);
		$this->assertTrue( $transitions->is_blocked( $attachment ) );
		$blocked = $transitions->enter_private( $attachment, (int) $folder->term_id );
		$this->assertWPError( $blocked );
		$this->assertSame( 'media_manager_transition_blocked', $blocked->get_error_code() );

		update_post_meta(
			$attachment,
			TransitionService::META_JOURNAL,
			[
				'phase' => 'failed',
				'error' => 'previous failure',
			]
		);
		$this->assertFalse( $transitions->is_blocked( $attachment ) );
		$this->assertTrue( $transitions->enter_private( $attachment, (int) $folder->term_id ) );
		$this->assertTrue( $transitions->is_private( $attachment ) );
	}

	/**
	 * Direct private uploads set private meta and exclusive membership.
	 *
	 * @return void
	 */
	public function test_mark_uploaded_private_and_upload_dir_redirect() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$folder = ( new FolderService() )->create( 'Upload Vault', 0, [ 'is_private' => true ] );
		$_REQUEST['media_manager_folder'] = (string) $folder->term_id;

		$assignment = new UploadAssignment();
		$uploads    = $assignment->filter_upload_dir(
			[
				'path'    => '/tmp/uploads/2024/01',
				'url'     => 'http://example.test/uploads/2024/01',
				'subdir'  => '/2024/01',
				'basedir' => '/tmp/uploads',
				'baseurl' => 'http://example.test/uploads',
				'error'   => false,
			]
		);
		$this->assertStringContainsString( $this->private_root, $uploads['basedir'] );
		$this->assertSame( home_url( '/media' ), $uploads['baseurl'] );

		$attachment = $this->create_text_attachment( 'uploaded-private.txt', 'uploaded-private' );
		$result     = ( new TransitionService() )->mark_uploaded_private( $attachment, (int) $folder->term_id );
		$this->assertTrue( $result );
		$this->assertTrue( ( new TransitionService() )->is_private( $attachment ) );
		$this->assertSame(
			[ (int) $folder->term_id ],
			( new FolderRepository() )->attachment_folder_ids( $attachment )
		);

		unset( $_REQUEST['media_manager_folder'] );
	}

	/**
	 * Leaving private rotates the delivery token.
	 *
	 * @return void
	 */
	public function test_leave_private_rotates_token() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment  = $this->create_text_attachment( 'rotate-token.txt', 'rotate' );
		$folder      = ( new FolderService() )->create( 'Token Vault', 0, [ 'is_private' => true ] );
		$transitions = new TransitionService();
		$this->assertTrue( $transitions->enter_private( $attachment, (int) $folder->term_id ) );

		$old_token = $transitions->token( $attachment );
		$this->assertSame( $attachment, $transitions->attachment_id_from_token( $old_token ) );

		$this->assertTrue( $transitions->leave_private( $attachment, [] ) );
		$new_token = (string) get_post_meta( $attachment, TransitionService::META_TOKEN, true );
		$this->assertNotSame( $old_token, $new_token );
		$this->assertSame( 0, $transitions->attachment_id_from_token( $old_token ) );
		$this->assertSame( $attachment, $transitions->attachment_id_from_token( $new_token ) );
	}

	/**
	 * Missing public bytes with a private copy present recovers into private state.
	 *
	 * @return void
	 */
	public function test_recover_private_if_present() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$attachment = $this->create_text_attachment( 'recover-me.txt', 'recover-me' );
		$relative   = get_post_meta( $attachment, '_wp_attached_file', true );
		$uploads    = wp_upload_dir();
		$public     = trailingslashit( $uploads['basedir'] ) . $relative;

		$storage = new PrivateStorage();
		$copied  = $storage->copy_from( $public, $relative );
		$this->assertFalse( is_wp_error( $copied ), is_wp_error( $copied ) ? $copied->get_error_message() : '' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		unlink( $public );

		$folder      = ( new FolderService() )->create( 'Recover Vault', 0, [ 'is_private' => true ] );
		$transitions = new TransitionService();
		$result      = $transitions->enter_private( $attachment, (int) $folder->term_id );
		$this->assertTrue( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $transitions->is_private( $attachment ) );
		$this->assertSame(
			[ (int) $folder->term_id ],
			( new FolderRepository() )->attachment_folder_ids( $attachment )
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

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		foreach ( (array) $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $path );
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@rmdir( $dir );
	}
}
