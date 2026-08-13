<?php
/**
 * Settings and preferences tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Admin\Settings;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Rest\PreferencesController;

/**
 * Tests settings defaults and preference isolation.
 */
class Test_Settings_And_Preferences extends WP_UnitTestCase {

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
	 * Tear down filters.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'media_manager_folder_ui' );
		parent::tear_down();
	}

	/**
	 * Stored settings sanitisation covers path and post types only.
	 *
	 * @return void
	 */
	public function test_settings_defaults_and_sanitisation() {
		$settings = new Settings();
		$clean    = $settings->sanitize(
			[
				'private_storage_path'    => " /tmp/private-media\0 ",
				'generated_media_folders' => [
					'post'           => '1',
					'wp_navigation'  => '1',
					'not_a_real_type'=> '1',
				],
				'show_count'              => '1',
				'default_folder'          => 999999,
			]
		);

		$this->assertSame( '/tmp/private-media', $clean['private_storage_path'] );
		$this->assertTrue( $clean['generated_media_folders']['post'] );
		$this->assertArrayNotHasKey( 'wp_navigation', $clean['generated_media_folders'] );
		$this->assertArrayNotHasKey( 'not_a_real_type', $clean['generated_media_folders'] );
		$this->assertArrayNotHasKey( 'show_count', $clean );
		$this->assertArrayNotHasKey( 'default_folder', $clean );
	}

	/**
	 * Folder UI defaults are always-on / All Media.
	 *
	 * @return void
	 */
	public function test_folder_ui_defaults() {
		$ui = Settings::get_folder_ui();

		$this->assertTrue( $ui['show_count'] );
		$this->assertTrue( $ui['show_search'] );
		$this->assertTrue( $ui['show_list_view'] );
		$this->assertTrue( $ui['show_modal_sidebar'] );
		$this->assertSame( 0, $ui['default_folder'] );
	}

	/**
	 * Folder UI behaviour can be overridden via filter.
	 *
	 * @return void
	 */
	public function test_folder_ui_filter_override() {
		add_filter(
			'media_manager_folder_ui',
			static function ( $ui ) {
				$ui['show_count'] = false;
				return $ui;
			}
		);

		$ui = Settings::get_folder_ui();
		$this->assertFalse( $ui['show_count'] );
		$this->assertTrue( $ui['show_search'] );
	}

	/**
	 * Preferences are scoped to the current user and width-bounded.
	 *
	 * @return void
	 */
	public function test_preferences_are_user_scoped() {
		$user_one = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$user_two = self::factory()->user->create( [ 'role' => 'administrator' ] );

		wp_set_current_user( $user_one );
		$request = new WP_REST_Request( 'POST', '/media-manager/v1/preferences' );
		$request->set_body_params(
			[
				'sidebar_width'     => 900,
				'sidebar_collapsed' => true,
				'previous_folder'   => 12,
			]
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 600, $data['sidebar_width'] );
		$this->assertTrue( $data['sidebar_collapsed'] );
		$this->assertSame( 12, $data['previous_folder'] );

		wp_set_current_user( $user_two );
		$other = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/media-manager/v1/preferences' ) );
		$this->assertNull( $other->get_data()['previous_folder'] );
		$this->assertFalse( $other->get_data()['sidebar_collapsed'] );
	}
}
