<?php
/**
 * Folder policy and effective ACL tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Permissions\AclCache;
use Eighteen73\MediaManager\Permissions\EffectiveAcl;
use Eighteen73\MediaManager\Permissions\FolderPolicy;

/**
 * Tests ACL inheritance and visibility.
 */
class Test_Effective_Acl extends WP_UnitTestCase {

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
		AclCache::invalidate_site();
	}

	/**
	 * Children may narrow but not widen role restrictions.
	 *
	 * @return void
	 */
	public function test_child_cannot_widen_parent_roles() {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );

		$service = new FolderService();
		$parent  = $service->create(
			'Restricted',
			0,
			[
				'access_mode'   => FolderPolicy::MODE_ROLES,
				'allowed_roles' => [ 'editor' ],
			]
		);

		$child = $service->create(
			'Child',
			(int) $parent->term_id,
			[
				'access_mode'   => FolderPolicy::MODE_ROLES,
				'allowed_roles' => [ 'editor', 'author' ],
			]
		);

		$this->assertWPError( $child );
		$this->assertSame( 'media_manager_access_widened', $child->get_error_code() );
	}

	/**
	 * Multi-folder visibility uses OR semantics for accessible folders.
	 *
	 * @return void
	 */
	public function test_attachment_visible_via_any_accessible_folder() {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $admin );

		$service = new FolderService();
		$open    = $service->create( 'Open' );
		$closed  = $service->create(
			'Closed',
			0,
			[
				'access_mode'   => FolderPolicy::MODE_USERS,
				'allowed_users' => [ $admin ],
			]
		);

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'shared.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Shared',
			]
		);

		$repo = new FolderRepository();
		$repo->set_attachment_folders(
			$attachment,
			[ (int) $open->term_id, (int) $closed->term_id ],
			false
		);

		$acl = new EffectiveAcl();
		$this->assertTrue( $acl->can_view_attachment( $attachment, $editor ) );
		$this->assertSame(
			[ (int) $open->term_id ],
			$acl->visible_attachment_folder_ids( $attachment, $editor )
		);
	}

	/**
	 * Attachments only in inaccessible folders are hidden.
	 *
	 * @return void
	 */
	public function test_inaccessible_only_attachment_is_hidden() {
		$admin  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $admin );

		$service = new FolderService();
		$closed  = $service->create(
			'Secret',
			0,
			[
				'access_mode'   => FolderPolicy::MODE_USERS,
				'allowed_users' => [ $admin ],
			]
		);

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'secret.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Secret',
			]
		);

		( new FolderRepository() )->set_attachment_folders( $attachment, [ (int) $closed->term_id ], false );

		$acl = new EffectiveAcl();
		$this->assertFalse( $acl->can_view_attachment( $attachment, $editor ) );
		$this->assertFalse( $acl->can_access_folder( (int) $closed->term_id, $editor ) );
	}

	/**
	 * Cache invalidation changes the version.
	 *
	 * @return void
	 */
	public function test_acl_cache_invalidation_bumps_version() {
		$before = AclCache::version();
		AclCache::invalidate_site();
		$this->assertSame( $before + 1, AclCache::version() );
	}
}
