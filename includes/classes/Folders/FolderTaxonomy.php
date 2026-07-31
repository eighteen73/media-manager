<?php
/**
 * Media folder taxonomy registration.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the private hierarchical media folder taxonomy.
 */
class FolderTaxonomy {

	/**
	 * Taxonomy name.
	 *
	 * @var string
	 */
	public const NAME = 'media_manager_folder';

	/**
	 * Maximum visible nesting depth.
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 5;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomy' ] );
	}

	/**
	 * Register the taxonomy.
	 *
	 * @return void
	 */
	public function register_taxonomy(): void {
		register_taxonomy(
			self::NAME,
			[ 'attachment' ],
			[
				'labels'                => [
					'name'          => __( 'Folders', 'media-manager' ),
					'singular_name' => __( 'Folder', 'media-manager' ),
				],
				'hierarchical'          => true,
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => false,
				'show_in_menu'          => false,
				'show_in_nav_menus'     => false,
				'show_in_rest'          => false,
				'show_tagcloud'         => false,
				'show_admin_column'     => false,
				'query_var'             => false,
				'rewrite'               => false,
				'meta_box_cb'           => false,
				'update_count_callback' => '_update_generic_term_count',
			]
		);
	}
}
