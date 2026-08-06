<?php
/**
 * Main plugin class.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager;

use Eighteen73\MediaManager\Admin\Assets;
use Eighteen73\MediaManager\Admin\AttachmentEdit;
use Eighteen73\MediaManager\Admin\MediaLibrary;
use Eighteen73\MediaManager\Admin\NetworkSettings;
use Eighteen73\MediaManager\Admin\Settings;
use Eighteen73\MediaManager\ContentFolders\PostLifecycle;
use Eighteen73\MediaManager\Folders\AttachmentQuery;
use Eighteen73\MediaManager\Folders\FolderTaxonomy;
use Eighteen73\MediaManager\Folders\UploadAssignment;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\PrivateMedia\Delivery;
use Eighteen73\MediaManager\PrivateMedia\UrlFilters;
use Eighteen73\MediaManager\Rest\AssignmentsController;
use Eighteen73\MediaManager\Rest\AttachmentFields;
use Eighteen73\MediaManager\Rest\FoldersController;
use Eighteen73\MediaManager\Rest\PreferencesController;
use Eighteen73\MediaManager\SharedMedia\SharedFoldersController;
use Eighteen73\MediaManager\SharedMedia\SharedMedia;
use Eighteen73\MediaManager\SharedMedia\SharedQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Main Plugin class.
 */
class Plugin {

	use Singleton;

	/**
	 * Setup the plugin.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'admin_init', [ $this, 'maybe_install' ] );

		( new FolderTaxonomy() )->register();
		( new AttachmentQuery() )->register();
		( new UploadAssignment() )->register();
		( new PostLifecycle() )->register();
		( new AttachmentFields() )->register();
		( new Delivery() )->register();
		( new UrlFilters() )->register();
		( new Assets() )->register();
		( new MediaLibrary() )->register();
		( new AttachmentEdit() )->register();
		( new Settings() )->register();

		if ( is_multisite() ) {
			( new NetworkSettings() )->register();
			( new SharedQuery() )->register();
			( new SharedMedia() )->register();
		}
	}

	/**
	 * Ensure activation tasks ran even if the activation hook was skipped.
	 *
	 * @return void
	 */
	public function maybe_install(): void {
		if ( Installer::VERSION === get_option( Installer::VERSION_OPTION ) ) {
			return;
		}

		Installer::activate();
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'media-manager',
			false,
			dirname( plugin_basename( MEDIA_MANAGER_PATH . 'media-manager.php' ) ) . '/languages'
		);
	}

	/**
	 * Register REST controllers.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		( new FoldersController() )->register_routes();
		( new AssignmentsController() )->register_routes();
		( new PreferencesController() )->register_routes();

		if ( is_multisite() ) {
			( new SharedFoldersController() )->register_routes();
		}
	}

	/**
	 * Plugin activation.
	 *
	 * @return void
	 */
	public static function activation(): void {
		Installer::activate();
	}

	/**
	 * Plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivation(): void {
		Installer::deactivate();
	}
}
