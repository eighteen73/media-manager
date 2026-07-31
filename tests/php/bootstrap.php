<?php
/**
 * PHPUnit bootstrap for Media Manager.
 *
 * @package Eighteen73\MediaManager
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress test library at {$_tests_dir}." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin for tests.
 *
 * @return void
 */
function media_manager_manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/media-manager.php';
}

tests_add_filter( 'muplugins_loaded', 'media_manager_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
