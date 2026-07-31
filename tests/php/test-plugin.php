<?php
/**
 * Plugin bootstrap test.
 *
 * @package Eighteen73\MediaManager
 */

/**
 * Tests Plugin bootstrap.
 */
class Test_Plugin extends WP_UnitTestCase {

	/**
	 * Plugin class exists and textdomain hook is registered.
	 *
	 * @return void
	 */
	public function test_plugin_bootstraps() {
		$this->assertTrue( class_exists( \Eighteen73\MediaManager\Plugin::class ) );
		$this->assertSame(
			10,
			has_action( 'init', [ \Eighteen73\MediaManager\Plugin::instance(), 'load_textdomain' ] )
		);
	}
}
