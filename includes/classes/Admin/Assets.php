<?php
/**
 * Admin asset registration.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Admin;

use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Permissions\AccessPolicy;
use Eighteen73\MediaManager\Rest\PreferencesController;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues Media Manager admin scripts and styles.
 */
class Assets {

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy|null $access Optional access policy.
	 */
	public function __construct( ?AccessPolicy $access = null ) {
		$this->access = $access ?? new AccessPolicy();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
		add_action( 'wp_enqueue_media', [ $this, 'enqueue_media_assets' ] );
	}

	/**
	 * Enqueue assets on Media Library screens.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->access->can_view_folders() ) {
			return;
		}

		$media_screens = [ 'upload.php', 'media-new.php' ];

		if ( ! in_array( $hook_suffix, $media_screens, true ) ) {
			return;
		}

		$this->enqueue_bundle();
	}

	/**
	 * Enqueue assets when wp.media is loaded (modals).
	 *
	 * @return void
	 */
	public function enqueue_media_assets(): void {
		if ( ! is_admin() || ! $this->access->can_view_folders() ) {
			return;
		}

		$this->enqueue_bundle();
	}

	/**
	 * Enqueue the admin bundle once.
	 *
	 * @return void
	 */
	private function enqueue_bundle(): void {
		static $enqueued = false;

		if ( $enqueued ) {
			return;
		}

		$asset_file = MEDIA_MANAGER_PATH . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? include $asset_file
			: [
				'dependencies' => [
					'wp-element',
					'wp-components',
					'wp-api-fetch',
					'wp-data',
					'wp-i18n',
					'media-views',
				],
				'version'      => Installer::VERSION,
			];

		$version      = $asset['version'] ?? Installer::VERSION;
		$dependencies = $asset['dependencies'] ?? [];

		if ( ! in_array( 'media-views', $dependencies, true ) ) {
			$dependencies[] = 'media-views';
		}

		foreach ( [ 'jquery', 'jquery-ui-draggable', 'jquery-ui-droppable' ] as $handle ) {
			if ( ! in_array( $handle, $dependencies, true ) ) {
				$dependencies[] = $handle;
			}
		}

		wp_enqueue_script( 'jquery-ui-draggable' );
		wp_enqueue_script( 'jquery-ui-droppable' );

		wp_enqueue_script(
			'media-manager-admin',
			MEDIA_MANAGER_URL . 'build/admin.js',
			$dependencies,
			$version,
			true
		);

		$style_path = MEDIA_MANAGER_PATH . 'build/admin.css';

		if ( file_exists( $style_path ) ) {
			wp_enqueue_style(
				'media-manager-admin',
				MEDIA_MANAGER_URL . 'build/admin.css',
				[ 'wp-components', 'dashicons' ],
				$version
			);
		}

		wp_set_script_translations( 'media-manager-admin', 'media-manager' );

		wp_localize_script(
			'media-manager-admin',
			'mediaManagerAdmin',
			$this->get_boot_data()
		);

		$enqueued = true;
	}

	/**
	 * Boot data for the admin UI.
	 *
	 * @return array<string, mixed>
	 */
	private function get_boot_data(): array {
		$folder_ui   = Settings::get_folder_ui();
		$preferences = ( new PreferencesController( $this->access ) )->get_item()->get_data();
		$default     = (int) $folder_ui['default_folder'];
		$roles       = [];
		foreach ( wp_roles()->get_names() as $slug => $label ) {
			$roles[] = [
				'slug'  => $slug,
				'label' => translate_user_role( $label ),
			];
		}

		$storage = new \Eighteen73\MediaManager\PrivateMedia\StorageConfig();
		$post_id = $this->resolve_current_post_id();
		$post_type = '';
		$generated_folder_id = null;

		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				$post_type = $post->post_type;
				$content   = new \Eighteen73\MediaManager\ContentFolders\ContentFolderService();
				if ( $content->is_enabled_for_type( $post_type ) ) {
					$folder_id = $content->folder_id_for_post( $post_id );
					if ( $folder_id < 1 ) {
						$ensured = $content->ensure_post_folder( $post_id );
						if ( ! is_wp_error( $ensured ) ) {
							$folder_id = (int) $ensured->term_id;
						}
					}
					$generated_folder_id = $folder_id > 0 ? $folder_id : null;
				}
			}
		}

		return [
			'restUrl'               => esc_url_raw( rest_url( 'media-manager/v1' ) ),
			'restNonce'             => wp_create_nonce( 'wp_rest' ),
			'settings'              => $folder_ui,
			'preferences'           => $preferences,
			'capabilities'          => [
				'view'     => $this->access->can_view_folders(),
				'edit'     => $this->access->can_edit_folders(),
				'delete'   => $this->access->can_delete_folders(),
				'assign'   => $this->access->can_assign_folders(),
				'settings' => $this->access->can_manage_settings(),
				'private'  => $this->access->can_manage_private_folders(),
			],
			'roles'                 => $roles,
			'currentUserId'         => get_current_user_id(),
			'currentPostId'         => $post_id > 0 ? $post_id : null,
			'currentPostType'       => $post_type !== '' ? $post_type : null,
			'generatedFolderId'     => $generated_folder_id,
			'privateStorageReady'   => $storage->is_ready(),
			'privateStorageMessage' => $storage->health()['message'],
			'defaultFolder'         => $default > 0 ? $default : null,
			'strings'               => [
				'allMedia'            => __( 'All files', 'media-manager' ),
				'uncategorized'       => __( 'Uncategorized', 'media-manager' ),
				'folders'             => __( 'Folders', 'media-manager' ),
				'newFolder'           => __( 'New Folder', 'media-manager' ),
				'renameFolder'        => __( 'Rename Folder', 'media-manager' ),
				'deleteFolder'        => __( 'Delete Folder', 'media-manager' ),
				'addToFolder'         => __( 'Add to Folder', 'media-manager' ),
				'moveToFolder'        => __( 'Move to Folder', 'media-manager' ),
				'moveItem'            => __( 'Move 1 item', 'media-manager' ),
				/* translators: %d: number of media items */
				'moveItems'           => __( 'Move %d items', 'media-manager' ),
				'searchFolders'       => __( 'Search folders', 'media-manager' ),
				'loading'             => __( 'Loading folders…', 'media-manager' ),
				'empty'               => __( 'No folders yet.', 'media-manager' ),
				'privateConfirmEnter' => __(
					'Moving into a private folder replaces all public folder relationships and stores files outside the public web root. Continue?',
					'media-manager'
				),
				'privateConfirmLeave' => __(
					'Leaving private storage makes these files publicly reachable again. Continue?',
					'media-manager'
				),
			],
		];
	}

	/**
	 * Resolve the post being edited in the current admin/media context.
	 *
	 * @return int
	 */
	private function resolve_current_post_id(): int {
		$post_id = 0;

		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $post_id < 1 && isset( $_REQUEST['post_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = absint( wp_unslash( $_REQUEST['post_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( $post_id < 1 ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && isset( $screen->base ) && 'post' === $screen->base && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$post_id = absint( wp_unslash( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		return $post_id;
	}
}
