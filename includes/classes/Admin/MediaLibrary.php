<?php
/**
 * Media Library admin integrations.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Admin;

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderTaxonomy;
use Eighteen73\MediaManager\Permissions\AccessPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Media Library list-view helpers and mount points.
 */
class MediaLibrary {

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy|null     $access     Optional access policy.
	 * @param FolderRepository|null $repository Optional repository.
	 */
	public function __construct( ?AccessPolicy $access = null, ?FolderRepository $repository = null ) {
		$this->access     = $access ?? new AccessPolicy();
		$this->repository = $repository ?? new FolderRepository();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Must run on admin_footer (before footer scripts), not admin_footer-{$hook}
		// which fires after scripts and leaves the React bootstrap without a mount node.
		add_action( 'admin_footer', [ $this, 'render_sidebar_root' ] );
		add_action( 'restrict_manage_posts', [ $this, 'render_list_filter' ], 10, 2 );
	}

	/**
	 * Render the React sidebar mount point on the Media Library screen.
	 *
	 * @return void
	 */
	public function render_sidebar_root(): void {
		if ( ! $this->access->can_view_folders() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}

		echo '<div id="media-manager-folder-root" class="media-manager-folder-root" hidden></div>';
	}

	/**
	 * Render a list-table folder filter when enabled.
	 *
	 * @param string $post_type Post type.
	 * @param string $which     Toolbar location.
	 * @return void
	 */
	public function render_list_filter( string $post_type, string $which ): void {
		if ( 'attachment' !== $post_type || 'bar' !== $which ) {
			return;
		}

		if ( ! $this->access->can_view_folders() ) {
			return;
		}

		$folder_ui = Settings::get_folder_ui();

		if ( empty( $folder_ui['show_list_view'] ) ) {
			return;
		}

		$selected = isset( $_GET['media_manager_folder'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_text_field( wp_unslash( $_GET['media_manager_folder'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'all';

		$folders = $this->repository->all();

		echo '<label class="screen-reader-text" for="media-manager-folder-filter">' . esc_html__( 'Filter by folder', 'media-manager' ) . '</label>';
		echo '<select name="media_manager_folder" id="media-manager-folder-filter">';
		printf(
			'<option value="all"%s>%s</option>',
			selected( $selected, 'all', false ),
			esc_html__( 'All Media', 'media-manager' )
		);
		printf(
			'<option value="0"%s>%s</option>',
			selected( $selected, '0', false ),
			esc_html__( 'Uncategorized', 'media-manager' )
		);

		foreach ( $folders as $folder ) {
			$depth  = count( get_ancestors( (int) $folder->term_id, FolderTaxonomy::NAME, 'taxonomy' ) );
			$prefix = str_repeat( '— ', $depth );

			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $folder->term_id,
				selected( $selected, (string) $folder->term_id, false ),
				esc_html( $prefix . $folder->name )
			);
		}

		echo '</select>';
	}
}
