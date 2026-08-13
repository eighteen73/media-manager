<?php
/**
 * Shared network media tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Admin\NetworkSettings;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Permissions\Capabilities;
use Eighteen73\MediaManager\SharedMedia\AttachmentResolver;
use Eighteen73\MediaManager\SharedMedia\NetworkLibrary;
use Eighteen73\MediaManager\SharedMedia\SharedMedia;

/**
 * Tests for network library settings and resolver filters.
 */
class Test_Shared_Network_Media extends WP_UnitTestCase {

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		delete_site_option( NetworkLibrary::OPTION );
		remove_all_filters( 'media_manager_network_library_id' );
		remove_all_filters( 'media_manager_network_library_allowed' );
		remove_all_filters( 'media_manager_shared_media_tab_title' );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_site_option( NetworkLibrary::OPTION );
		remove_all_filters( 'media_manager_network_library_id' );
		remove_all_filters( 'media_manager_network_library_allowed' );
		remove_all_filters( 'media_manager_shared_media_tab_title' );
		parent::tear_down();
	}

	/**
	 * Shared media is disabled by default (no library selected).
	 *
	 * @return void
	 */
	public function test_disabled_by_default() {
		$this->assertSame( 0, NetworkLibrary::get_library_id() );
		$this->assertFalse( NetworkLibrary::is_enabled() );
		$this->assertFalse( NetworkLibrary::is_allowed_for_blog() );
		$this->assertFalse( NetworkLibrary::current_user_can_use() );
	}

	/**
	 * Saving a valid site ID enables the network library.
	 *
	 * @return void
	 */
	public function test_update_library_id_enables_shared_media() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$blog_id = self::factory()->blog->create();
		$stored  = NetworkLibrary::update_library_id( (int) $blog_id );

		$this->assertSame( (int) $blog_id, $stored );
		$this->assertSame( (int) $blog_id, NetworkLibrary::get_library_id() );
		$this->assertTrue( NetworkLibrary::is_enabled() );
	}

	/**
	 * Invalid site IDs are stored as disabled (0).
	 *
	 * @return void
	 */
	public function test_update_library_id_rejects_invalid_site() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$stored = NetworkLibrary::update_library_id( 999999 );
		$this->assertSame( 0, $stored );
		$this->assertFalse( NetworkLibrary::is_enabled() );
	}

	/**
	 * Filter can override the configured library ID.
	 *
	 * @return void
	 */
	public function test_network_library_id_filter() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$blog_id = self::factory()->blog->create();
		NetworkLibrary::update_library_id( (int) $blog_id );

		add_filter( 'media_manager_network_library_id', '__return_zero' );

		$this->assertSame( 0, NetworkLibrary::get_library_id() );
		$this->assertFalse( NetworkLibrary::is_enabled() );
	}

	/**
	 * Filter can deny access from a specific blog.
	 *
	 * @return void
	 */
	public function test_network_library_allowed_filter() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$library_id = self::factory()->blog->create();
		$denied_id  = self::factory()->blog->create();
		NetworkLibrary::update_library_id( (int) $library_id );

		add_filter(
			'media_manager_network_library_allowed',
			static function ( $allowed, $blog_id ) use ( $denied_id ) {
				if ( (int) $blog_id === (int) $denied_id ) {
					return false;
				}
				return $allowed;
			},
			10,
			2
		);

		$this->assertTrue( NetworkLibrary::is_allowed_for_blog( get_current_blog_id() ) );
		$this->assertFalse( NetworkLibrary::is_allowed_for_blog( (int) $denied_id ) );
	}

	/**
	 * Tab title filter changes the shared media label.
	 *
	 * @return void
	 */
	public function test_shared_media_tab_title_filter() {
		add_filter(
			'media_manager_shared_media_tab_title',
			static function () {
				return 'Network library';
			}
		);

		$this->assertSame( 'Network library', NetworkLibrary::tab_title() );
	}

	/**
	 * current_user_can_use requires enabled library, allowed blog, and SHARED cap.
	 *
	 * @return void
	 */
	public function test_current_user_can_use_requires_cap_and_config() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$library_id = self::factory()->blog->create();
		$user_id    = self::factory()->user->create( [ 'role' => 'editor' ] );

		wp_set_current_user( $user_id );
		$this->assertFalse( NetworkLibrary::current_user_can_use() );

		NetworkLibrary::update_library_id( (int) $library_id );
		$this->assertTrue( NetworkLibrary::current_user_can_use( $user_id ) );

		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->assertFalse( NetworkLibrary::current_user_can_use( $subscriber ) );
	}

	/**
	 * Network settings page registers under network admin settings.
	 *
	 * @return void
	 */
	public function test_network_settings_page_registers() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		grant_super_admin( $admin );
		wp_set_current_user( $admin );

		global $submenu;
		$submenu = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		( new NetworkSettings() )->register();
		do_action( 'network_admin_menu' );

		$found = false;
		if ( ! empty( $submenu['settings.php'] ) ) {
			foreach ( $submenu['settings.php'] as $item ) {
				if ( isset( $item[2] ) && NetworkSettings::PAGE === $item[2] ) {
					$found = true;
					break;
				}
			}
		}

		$this->assertTrue( $found, 'Network Media submenu should be registered under settings.php.' );
	}

	/**
	 * Featured network meta is set/cleared based on network attachment IDs.
	 *
	 * @return void
	 */
	public function test_maybe_mark_featured_network_meta() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$library_id = self::factory()->blog->create();
		NetworkLibrary::update_library_id( (int) $library_id );

		switch_to_blog( (int) $library_id );
		$network_attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'network-featured.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Network Featured',
			]
		);
		restore_current_blog();

		$post_id = self::factory()->post->create();
		$shared  = new SharedMedia();

		$shared->maybe_mark_featured_network( $post_id, (int) $network_attachment );
		$this->assertSame(
			'1',
			(string) get_post_meta( $post_id, AttachmentResolver::META_FEATURED_NETWORK, true )
		);

		$local = self::factory()->attachment->create_object(
			[
				'file'           => 'local-featured.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Local Featured',
			]
		);
		$shared->maybe_mark_featured_network( $post_id, (int) $local );
		$this->assertSame(
			'',
			(string) get_post_meta( $post_id, AttachmentResolver::META_FEATURED_NETWORK, true )
		);

		$shared->maybe_mark_featured_from_meta( 1, $post_id, '_thumbnail_id', $network_attachment );
		$this->assertSame(
			'1',
			(string) get_post_meta( $post_id, AttachmentResolver::META_FEATURED_NETWORK, true )
		);

		$shared->maybe_clear_featured_network( [ 1 ], $post_id, '_thumbnail_id', $network_attachment );
		$this->assertSame(
			'',
			(string) get_post_meta( $post_id, AttachmentResolver::META_FEATURED_NETWORK, true )
		);
	}

	/**
	 * REST featured_media priming accepts network attachment ids without error.
	 *
	 * @return void
	 */
	public function test_maybe_prime_featured_media() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		$library_id = self::factory()->blog->create();
		NetworkLibrary::update_library_id( (int) $library_id );

		switch_to_blog( (int) $library_id );
		$network_attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'prime-featured.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Prime Featured',
			]
		);
		restore_current_blog();

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/1' );
		$request->set_param( 'featured_media', (int) $network_attachment );

		$shared   = new SharedMedia();
		$response = $shared->maybe_prime_featured_media( null, [], $request );
		$this->assertNull( $response );

		$settings = $shared->filter_media_view_settings( [], null );
		$this->assertArrayHasKey( 'mediaManagerFeaturedNetwork', $settings );
		$this->assertFalse( $settings['mediaManagerFeaturedNetwork'] );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, AttachmentResolver::META_FEATURED_NETWORK, 1 );
		$settings = $shared->filter_media_view_settings( [], get_post( $post_id ) );
		$this->assertTrue( $settings['mediaManagerFeaturedNetwork'] );
	}

	/**
	 * Shared folders REST requires shared media access and returns a tree payload.
	 *
	 * @return void
	 */
	public function test_shared_folders_rest_auth_and_shape() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite required.' );
		}

		do_action( 'rest_api_init' );

		$request  = new WP_REST_Request( 'GET', '/media-manager/v1/shared-folders' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 403, $response->get_status() );

		$library_id = self::factory()->blog->create();
		NetworkLibrary::update_library_id( (int) $library_id );

		switch_to_blog( (int) $library_id );
		Capabilities::grant_defaults();
		restore_current_blog();

		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		add_user_to_blog( (int) $library_id, $editor, 'editor' );
		wp_set_current_user( $editor );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'folders', $data );
		$this->assertArrayHasKey( 'summary', $data );
	}
}
