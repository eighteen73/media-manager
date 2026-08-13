<?php
/**
 * Folder policy unit tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Permissions\FolderPolicy;

/**
 * Tests folder policy normalisation and inheritance.
 */
class Test_Folder_Policy extends WP_UnitTestCase {

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
	}

	/**
	 * Private parents force private children.
	 *
	 * @return void
	 */
	public function test_private_parent_requires_private_child() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		// Bypass storage readiness for policy-only test by saving meta directly after create.
		$service = new FolderService();
		$parent  = $service->create( 'Private Parent' );
		update_term_meta( (int) $parent->term_id, FolderPolicy::META_IS_PRIVATE, 1 );

		$policy = new FolderPolicy();
		$result = $policy->validate_against_parent(
			[
				'access_mode'   => FolderPolicy::MODE_UNRESTRICTED,
				'allowed_roles' => [],
				'allowed_users' => [],
				'is_private'    => false,
			],
			(int) $parent->term_id
		);

		$this->assertWPError( $result );
		$this->assertSame( 'media_manager_private_required', $result->get_error_code() );
	}

	/**
	 * Username resolution stores user IDs.
	 *
	 * @return void
	 */
	public function test_normalise_resolves_usernames() {
		$user = self::factory()->user->create(
			[
				'role'         => 'editor',
				'user_login'   => 'folder_editor',
				'user_pass'    => 'pass',
				'user_email'   => 'folder_editor@example.com',
			]
		);

		$policy = new FolderPolicy();
		$result = $policy->normalise(
			[
				'access_mode'       => FolderPolicy::MODE_USERS,
				'allowed_usernames' => [ 'folder_editor' ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( [ $user ], $result['allowed_users'] );
	}
}
