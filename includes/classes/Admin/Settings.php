<?php
/**
 * Media Manager settings (Settings → Media).
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Admin;

use Eighteen73\MediaManager\PrivateMedia\StorageConfig;

defined( 'ABSPATH' ) || exit;

/**
 * Settings registered on the core Media settings page.
 */
class Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	public const OPTION = 'media_manager_settings';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Default stored settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'private_storage_path'    => '',
			'generated_media_folders' => [],
		];
	}

	/**
	 * Default folder UI behaviour (not stored; overridable via filter).
	 *
	 * @return array<string, mixed>
	 */
	public static function folder_ui_defaults(): array {
		return [
			'show_count'         => true,
			'show_search'        => true,
			'show_list_view'     => true,
			'show_modal_sidebar' => true,
			'default_folder'     => 0,
		];
	}

	/**
	 * Get sanitised settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$merged = array_merge( self::defaults(), $stored );

		return array_intersect_key( $merged, self::defaults() );
	}

	/**
	 * Folder UI behaviour that is no longer exposed in settings.
	 *
	 * @return array{
	 *     show_count: bool,
	 *     show_search: bool,
	 *     show_list_view: bool,
	 *     show_modal_sidebar: bool,
	 *     default_folder: int
	 * }
	 */
	public static function get_folder_ui(): array {
		$defaults = self::folder_ui_defaults();

		/**
		 * Filter folder UI behaviour that is no longer exposed in settings.
		 *
		 * @param array $ui {
		 *     @type bool $show_count         Show item counts in the folder tree.
		 *     @type bool $show_search        Show folder search.
		 *     @type bool $show_list_view     Show folder filter in list view.
		 *     @type bool $show_modal_sidebar Show folders in the media modal.
		 *     @type int  $default_folder     Term ID, or 0 for All Media.
		 * }
		 */
		$ui = apply_filters( 'media_manager_folder_ui', $defaults );

		if ( ! is_array( $ui ) ) {
			$ui = [];
		}

		$ui = array_merge( $defaults, $ui );

		return [
			'show_count'         => ! empty( $ui['show_count'] ),
			'show_search'        => ! empty( $ui['show_search'] ),
			'show_list_view'     => ! empty( $ui['show_list_view'] ),
			'show_modal_sidebar' => ! empty( $ui['show_modal_sidebar'] ),
			'default_folder'     => absint( $ui['default_folder'] ?? 0 ),
		];
	}

	/**
	 * Register Settings API fields on Settings → Media.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'media',
			self::OPTION,
			[
				'type'              => 'object',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			]
		);

		add_settings_section(
			'media_manager_private',
			__( 'Private storage', 'media-manager' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Private folders store files outside the public web root. Leave blank to use the site default private directory, or set an absolute writable path outside ABSPATH and uploads.', 'media-manager' ) . '</p>';
			},
			'media'
		);

		add_settings_field(
			'media_manager_private_storage_path',
			__( 'Private storage path', 'media-manager' ),
			[ $this, 'render_private_storage_field' ],
			'media',
			'media_manager_private'
		);

		add_settings_section(
			'media_manager_generated',
			__( 'Generated media folders', 'media-manager' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Generate a media library folder per post type and automatically uploaded files there.', 'media-manager' ) . '</p>';
			},
			'media'
		);

		add_settings_field(
			'media_manager_generated_media_folders',
			__( 'Enable for post types', 'media-manager' ),
			[ $this, 'render_generated_media_folders_field' ],
			'media',
			'media_manager_generated'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input  = is_array( $input ) ? $input : [];
		$output = self::defaults();

		$path                           = isset( $input['private_storage_path'] ) ? trim( (string) $input['private_storage_path'] ) : '';
		$path                           = str_replace( "\0", '', $path );
		$output['private_storage_path'] = untrailingslashit( $path );

		$enabled_types = [];
		$raw_types     = isset( $input['generated_media_folders'] ) && is_array( $input['generated_media_folders'] )
			? $input['generated_media_folders']
			: [];
		$service       = new \Eighteen73\MediaManager\ContentFolders\ContentFolderService();
		$eligible      = $service->eligible_post_types();
		foreach ( array_keys( $eligible ) as $post_type ) {
			if ( ! empty( $raw_types[ $post_type ] ) ) {
				$enabled_types[ $post_type ] = true;
				$service->ensure_system_root( $post_type );
			}
		}
		$output['generated_media_folders'] = $enabled_types;

		// Keep private delivery rewrites current whenever settings are saved.
		( new \Eighteen73\MediaManager\PrivateMedia\Delivery() )->add_rewrite_rules();
		flush_rewrite_rules( false );

		return $output;
	}

	/**
	 * Render private storage path + health.
	 *
	 * @return void
	 */
	public function render_private_storage_field(): void {
		$settings     = self::get_settings();
		$path         = (string) $settings['private_storage_path'];
		$default_path = StorageConfig::default_path();
		$health       = ( new StorageConfig() )->health();

		printf(
			'<input type="text" class="large-text code" name="%1$s[private_storage_path]" value="%2$s" placeholder="%3$s" />',
			esc_attr( self::OPTION ),
			esc_attr( $path ),
			esc_attr( $default_path )
		);

		echo '<p class="description">' . esc_html__( 'Leave blank to use the site default:', 'media-manager' ) . ' <code>' . esc_html( $default_path ) . '</code></p>';

		$class = ! empty( $health['ok'] ) ? 'notice notice-success inline' : 'notice notice-warning inline';
		printf(
			'<div class="%1$s"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $health['message'] )
		);
	}

	/**
	 * Render generated media folder post type checkboxes.
	 *
	 * @return void
	 */
	public function render_generated_media_folders_field(): void {
		$settings = self::get_settings();
		$map      = isset( $settings['generated_media_folders'] ) && is_array( $settings['generated_media_folders'] )
			? $settings['generated_media_folders']
			: [];
		$service  = new \Eighteen73\MediaManager\ContentFolders\ContentFolderService();
		$types    = $service->eligible_post_types();

		if ( empty( $types ) ) {
			echo '<p class="description">' . esc_html__( 'No eligible post types found.', 'media-manager' ) . '</p>';
			return;
		}

		echo '<fieldset>';
		foreach ( $types as $slug => $object ) {
			$label = $object->labels->name ?? $slug;
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[generated_media_folders][%2$s]" value="1" %3$s /> %4$s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $slug ),
				checked( ! empty( $map[ $slug ] ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}
}
