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
	public const VERSION = '0.2.2';

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

		// Ensure private delivery rewrites are registered.
		( new \Eighteen73\MediaManager\PrivateMedia\Delivery() )->add_rewrite_rules();
		flush_rewrite_rules( false );

		self::sync_existing_private_folders();
		( new \Eighteen73\MediaManager\PrivateMedia\PrivateStorage() )->migrate_legacy_layout();
	}

	/**
	 * Move media already sitting in private folders into private storage.
	 *
	 * Covers folders marked private before transition-on-toggle existed.
	 *
	 * @return void
	 */
	private static function sync_existing_private_folders(): void {
		$terms = get_terms(
			[
				'taxonomy'   => \Eighteen73\MediaManager\Folders\FolderTaxonomy::NAME,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$policy      = new \Eighteen73\MediaManager\Permissions\FolderPolicy();
		$transitions = new \Eighteen73\MediaManager\PrivateMedia\TransitionService();

		foreach ( $terms as $term_id ) {
			$folder_policy = $policy->get( (int) $term_id );
			if ( is_wp_error( $folder_policy ) || empty( $folder_policy['is_private'] ) ) {
				continue;
			}

			$transitions->sync_folder_privacy( (int) $term_id, true );
		}
	}

	/**
	 * Deactivate without removing data.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules( false );
	}
}
