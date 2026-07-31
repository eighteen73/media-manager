<?php
/**
 * Capability constants and default grants.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Folder capability helpers.
 */
final class Capabilities {

	/**
	 * View folders capability.
	 *
	 * @var string
	 */
	public const VIEW = 'media_manager_view_folders';

	/**
	 * Edit folders capability.
	 *
	 * @var string
	 */
	public const EDIT = 'media_manager_edit_folders';

	/**
	 * Delete folders capability.
	 *
	 * @var string
	 */
	public const DELETE = 'media_manager_delete_folders';

	/**
	 * Assign folders capability.
	 *
	 * @var string
	 */
	public const ASSIGN = 'media_manager_assign_folders';

	/**
	 * Manage private folders capability.
	 *
	 * @var string
	 */
	public const PRIVATE = 'media_manager_manage_private_folders';

	/**
	 * Use shared media capability.
	 *
	 * @var string
	 */
	public const SHARED = 'media_manager_use_shared_media';

	/**
	 * Manage settings capability.
	 *
	 * @var string
	 */
	public const SETTINGS = 'media_manager_manage_settings';

	/**
	 * Manage cleanup capability.
	 *
	 * @var string
	 */
	public const CLEANUP = 'media_manager_manage_cleanup';

	/**
	 * All plugin capabilities.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return [
			self::VIEW,
			self::EDIT,
			self::DELETE,
			self::ASSIGN,
			self::PRIVATE,
			self::SHARED,
			self::SETTINGS,
			self::CLEANUP,
		];
	}

	/**
	 * Grant default capabilities to core roles.
	 *
	 * @return void
	 */
	public static function grant_defaults(): void {
		$administrator = get_role( 'administrator' );

		if ( $administrator ) {
			foreach ( self::all() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}

		$editor = get_role( 'editor' );

		if ( $editor ) {
			$editor->add_cap( self::VIEW );
			$editor->add_cap( self::ASSIGN );
			$editor->add_cap( self::SHARED );
		}
	}
}
