<?php
/**
 * Media Manager settings page.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Admin;

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Permissions\AccessPolicy;
use Eighteen73\MediaManager\Permissions\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Settings > Media Manager.
 */
class Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	public const OPTION = 'media_manager_settings';

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
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
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

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Register the settings submenu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Media Manager', 'media-manager' ),
			__( 'Media Manager', 'media-manager' ),
			Capabilities::SETTINGS,
			'media-manager',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Register Settings API fields.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'media_manager_settings_group',
			self::OPTION,
			[
				'type'              => 'object',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			]
		);

		add_settings_section(
			'media_manager_general',
			__( 'Public folders', 'media-manager' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure how public media folders appear in the Media Library and media modal.', 'media-manager' ) . '</p>';
			},
			'media-manager'
		);

		$fields = [
			'show_count'         => __( 'Show item counts in the folder tree', 'media-manager' ),
			'show_search'        => __( 'Show folder search', 'media-manager' ),
			'show_list_view'     => __( 'Show folder filter in list view', 'media-manager' ),
			'show_modal_sidebar' => __( 'Show folders in the media modal', 'media-manager' ),
		];

		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				[ $this, 'render_checkbox_field' ],
				'media-manager',
				'media_manager_general',
				[
					'key'   => $key,
					'label' => $label,
				]
			);
		}

		add_settings_field(
			'default_folder',
			__( 'Default folder selection', 'media-manager' ),
			[ $this, 'render_default_folder_field' ],
			'media-manager',
			'media_manager_general'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : [];
		$output   = $defaults;

		foreach ( [ 'show_count', 'show_search', 'show_list_view', 'show_modal_sidebar' ] as $key ) {
			$output[ $key ] = ! empty( $input[ $key ] );
		}

		$default_folder = isset( $input['default_folder'] ) ? absint( $input['default_folder'] ) : 0;

		if ( $default_folder > 0 ) {
			$folder = $this->repository->find( $default_folder );
			if ( is_wp_error( $folder ) ) {
				$default_folder = 0;
			}
		}

		$output['default_folder'] = $default_folder;

		return $output;
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_checkbox_field( array $args ): void {
		$settings = self::get_settings();
		$key      = $args['key'];
		$checked  = ! empty( $settings[ $key ] );

		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			checked( $checked, true, false ),
			esc_html( $args['label'] )
		);
	}

	/**
	 * Render the default folder select.
	 *
	 * @return void
	 */
	public function render_default_folder_field(): void {
		$settings = self::get_settings();
		$selected = (int) $settings['default_folder'];
		$folders  = $this->repository->all();

		printf( '<select name="%s[default_folder]">', esc_attr( self::OPTION ) );
		printf(
			'<option value="0"%s>%s</option>',
			selected( $selected, 0, false ),
			esc_html__( 'All Media', 'media-manager' )
		);

		foreach ( $folders as $folder ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $folder->term_id,
				selected( $selected, (int) $folder->term_id, false ),
				esc_html( $folder->name )
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used when a user has no previous folder preference.', 'media-manager' ) . '</p>';
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! $this->access->can_manage_settings() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Media Manager settings.', 'media-manager' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Media Manager', 'media-manager' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( 'media_manager_settings_group' );
		do_settings_sections( 'media-manager' );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
}
