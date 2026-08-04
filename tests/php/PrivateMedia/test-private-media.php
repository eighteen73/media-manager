<?php
/**
 * Private storage and transition tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Admin\Settings;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\PrivateMedia\Delivery;
use Eighteen73\MediaManager\PrivateMedia\PrivateStorage;
use Eighteen73\MediaManager\PrivateMedia\StorageConfig;
use Eighteen73\MediaManager\PrivateMedia\TransitionService;

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

		$uploads = wp_upload_dir();
		$file    = trailingslashit( $uploads['basedir'] ) . 'deliver-me.txt';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, 'deliver' );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => $file,
				'post_mime_type' => 'text/plain',
				'post_title'     => 'Deliver',
			]
		);
		update_attached_file( $attachment, $file );

		$folder = ( new FolderService() )->create( 'Vault 2', 0, [ 'is_private' => true ] );
		( new TransitionService() )->enter_private( $attachment, (int) $folder->term_id );

		wp_set_current_user( 0 );

		$this->expectException( \WPDieException::class );
		( new Delivery() )->deliver( $attachment, 'wrong-token' );
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
