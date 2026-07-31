<?php
/**
 * Plugin installer and activation routines.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Install;

use Eighteen73\MediaManager\Admin\Settings;
use Eighteen73\MediaManager\Permissions\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Handles activation and versioned schema options.
 */
class Installer {

	/**
	 * Option key for plugin version.
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'media_manager_version';

	/**
	 * Current plugin schema version.
	 *
	 * @var string
	 */
	public const VERSION = '0.1.0';

	/**
	 * Run activation tasks.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Capabilities::grant_defaults();
		update_option( self::VERSION_OPTION, self::VERSION, false );

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
	}

	/**
	 * Run deactivation tasks without removing data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Intentionally preserve capabilities and data.
	}
}
