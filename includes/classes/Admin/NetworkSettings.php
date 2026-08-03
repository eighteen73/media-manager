<?php
/**
 * Network Admin → Settings → Network Media.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Admin;

use Eighteen73\MediaManager\SharedMedia\NetworkLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Network-scoped settings for the shared media library.
 */
class NetworkSettings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	public const PAGE = 'media-manager-network';

	/**
	 * Network admin edit action for saving.
	 *
	 * @var string
	 */
	public const ACTION = 'media_manager_network';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! is_multisite() ) {
			return;
		}

		add_action( 'network_admin_menu', [ $this, 'add_page' ] );
		add_action( 'network_admin_edit_' . self::ACTION, [ $this, 'handle_save' ] );
	}

	/**
	 * Add Settings → Network Media under network admin.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_submenu_page(
			'settings.php',
			__( 'Network Media', 'media-manager' ),
			__( 'Network Media', 'media-manager' ),
			'manage_network_options',
			self::PAGE,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Persist the network library site option.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage network options.', 'media-manager' ) );
		}

		check_admin_referer( 'media_manager_network_settings' );

		$blog_id = isset( $_POST['network_library_id'] )
			? absint( wp_unslash( $_POST['network_library_id'] ) )
			: 0;

		NetworkLibrary::update_library_id( $blog_id );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => self::PAGE,
					'updated' => 'true',
				],
				network_admin_url( 'settings.php' )
			)
		);
		exit;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage network options.', 'media-manager' ) );
		}

		$current = absint( get_site_option( NetworkLibrary::OPTION, 0 ) );
		$sites   = get_sites(
			[
				'number'   => 0,
				'orderby'  => 'domain',
				'order'    => 'ASC',
				'deleted'  => 0,
				'spam'     => 0,
				'archived' => 0,
				'mature'   => 0,
			]
		);

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Shared Media Library Settings', 'media-manager' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div id="message" class="updated notice is-dismissible"><p><?php echo esc_html__( 'Settings saved.', 'media-manager' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::ACTION ) ); ?>">
				<?php wp_nonce_field( 'media_manager_network_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="network_library_id"><?php echo esc_html__( 'Network Library', 'media-manager' ); ?></label>
						</th>
						<td>
							<select name="network_library_id" id="network_library_id">
								<option value="0" <?php selected( $current, 0 ); ?>>
									<?php echo esc_html__( '— None —', 'media-manager' ); ?>
								</option>
								<?php foreach ( $sites as $site ) : ?>
									<?php
									$blog_id = (int) $site->blog_id;
									$label   = $this->site_label( $site );
									?>
									<option value="<?php echo esc_attr( (string) $blog_id ); ?>" <?php selected( $current, $blog_id ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php echo esc_html__( 'Select a site that will be used as the network media library. Leave as None to disable shared media.', 'media-manager' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Changes', 'media-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Human-readable site label for the dropdown.
	 *
	 * @param \WP_Site $site Site object.
	 * @return string
	 */
	private function site_label( $site ): string {
		$blog_id = (int) $site->blog_id;
		$details = get_blog_details( $blog_id );
		$name    = $details ? (string) $details->blogname : '';
		$url     = $details ? untrailingslashit( (string) $details->siteurl ) : (string) $site->domain;

		if ( '' !== $name ) {
			return sprintf( '%1$s (%2$s) (#%3$d)', $name, $url, $blog_id );
		}

		return sprintf( '%1$s (#%2$d)', $url, $blog_id );
	}
}
