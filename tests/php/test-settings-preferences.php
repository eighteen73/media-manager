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
	 * Settings defaults and sanitisation.
	 *
	 * @return void
	 */
	public function test_settings_defaults_and_sanitisation() {
		$settings = new Settings();
		$clean    = $settings->sanitize(
			[
				'show_count'         => '1',
				'show_search'        => '',
				'show_list_view'     => 'yes',
				'show_modal_sidebar' => 0,
				'default_folder'     => 999999,
			]
		);

		$this->assertTrue( $clean['show_count'] );
		$this->assertFalse( $clean['show_search'] );
		$this->assertTrue( $clean['show_list_view'] );
		$this->assertFalse( $clean['show_modal_sidebar'] );
		$this->assertSame( 0, $clean['default_folder'] );
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
