<?php
/**
 * Generated media folder tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Admin\Settings;
use Eighteen73\MediaManager\ContentFolders\ContentFolderRepository;
use Eighteen73\MediaManager\ContentFolders\ContentFolderService;
use Eighteen73\MediaManager\ContentFolders\PostLifecycle;
use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Folders\UploadAssignment;
use Eighteen73\MediaManager\Install\Installer;

/**
 * Tests automatic post-type / generated media folders.
 */
class Test_Content_Folders extends WP_UnitTestCase {

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
		$this->enable_generated_for( 'post' );
	}

	/**
	 * Enable generated media folders for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	private function enable_generated_for( string $post_type ): void {
		$settings                              = Settings::get_settings();
		$settings['generated_media_folders']   = [ $post_type => true ];
		update_option( Settings::OPTION, $settings );
	}

	/**
	 * Enabling a type ensures a protected system root.
	 *
	 * @return void
	 */
	public function test_enable_type_creates_system_root() {
		$service = new ContentFolderService();
		$root    = $service->ensure_system_root( 'post' );

		$this->assertNotWPError( $root );
		$content = new ContentFolderRepository();
		$this->assertTrue( $content->is_system_root( (int) $root->term_id ) );
		$this->assertSame( 'post', $content->source_post_type( (int) $root->term_id ) );
		$this->assertSame( 0, (int) $root->parent );
	}

	/**
	 * Saving a real post creates a generated folder with meta.
	 *
	 * @return void
	 */
	public function test_save_post_creates_generated_folder() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$lifecycle = new PostLifecycle();
		$lifecycle->register();

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Alpha Product',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);

		$service = new ContentFolderService();
		$folder  = $service->folder_id_for_post( $post_id );
		$this->assertGreaterThan( 0, $folder );

		$content = new ContentFolderRepository();
		$this->assertTrue( $content->is_generated( $folder ) );
		$this->assertSame( $post_id, $content->source_post_id( $folder ) );
		$this->assertSame( 'post', $content->source_post_type( $folder ) );
		$this->assertFalse( $content->is_orphaned( $folder ) );

		$term = ( new FolderRepository() )->find( $folder );
		$this->assertSame( 'Alpha Product', $term->name );
	}

	/**
	 * Auto-draft posts do not get folders until they become real records.
	 *
	 * @return void
	 */
	public function test_auto_draft_skipped() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		( new PostLifecycle() )->register();

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Drafty',
				'post_status' => 'auto-draft',
				'post_author' => $user,
			]
		);

		$this->assertSame( 0, ( new ContentFolderService() )->folder_id_for_post( $post_id ) );
	}

	/**
	 * Title changes rename the managed folder.
	 *
	 * @return void
	 */
	public function test_title_change_renames_folder() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		( new PostLifecycle() )->register();

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Original',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);

		wp_update_post(
			[
				'ID'         => $post_id,
				'post_title' => 'Renamed',
			]
		);

		$folder_id = ( new ContentFolderService() )->folder_id_for_post( $post_id );
		$term      = ( new FolderRepository() )->find( $folder_id );
		$this->assertSame( 'Renamed', $term->name );
	}

	/**
	 * Duplicate titles append the post ID.
	 *
	 * @return void
	 */
	public function test_duplicate_titles_disambiguate() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		( new PostLifecycle() )->register();

		$first = self::factory()->post->create(
			[
				'post_title'  => 'Same Title',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);
		$second = self::factory()->post->create(
			[
				'post_title'  => 'Same Title',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);

		$service = new ContentFolderService();
		$a       = ( new FolderRepository() )->find( $service->folder_id_for_post( $first ) );
		$b       = ( new FolderRepository() )->find( $service->folder_id_for_post( $second ) );

		$this->assertSame( 'Same Title', $a->name );
		$this->assertSame( sprintf( 'Same Title (#%d)', $second ), $b->name );
	}

	/**
	 * Trashing a post does not orphan the folder.
	 *
	 * @return void
	 */
	public function test_trash_does_not_orphan() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		( new PostLifecycle() )->register();

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Keep Me',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);

		wp_trash_post( $post_id );

		$folder_id = ( new ContentFolderService() )->folder_id_for_post( $post_id );
		$content   = new ContentFolderRepository();
		$this->assertFalse( $content->is_orphaned( $folder_id ) );
		$this->assertTrue( $content->is_managed( $folder_id ) );
	}

	/**
	 * Permanent delete marks the folder orphaned and keeps media.
	 *
	 * @return void
	 */
	public function test_permanent_delete_orphans_folder() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		( new PostLifecycle() )->register();

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Gone',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);

		$service   = new ContentFolderService();
		$folder_id = $service->folder_id_for_post( $post_id );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'keep.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Keep',
				'post_author'    => $user,
			]
		);
		( new FolderRepository() )->set_attachment_folders( $attachment, [ $folder_id ], false );

		wp_delete_post( $post_id, true );

		$content = new ContentFolderRepository();
		$this->assertTrue( $content->is_orphaned( $folder_id ) );
		$this->assertNotWPError( ( new FolderRepository() )->find( $folder_id ) );
		$this->assertSame( [ $folder_id ], ( new FolderRepository() )->attachment_folder_ids( $attachment ) );
	}

	/**
	 * Upload with post_id assigns to the generated folder.
	 *
	 * @return void
	 */
	public function test_upload_with_post_id_assigns_generated_folder() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Upload Target',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);

		$folder = ( new ContentFolderService() )->ensure_post_folder( $post_id );
		$this->assertNotWPError( $folder );

		$_REQUEST['post_id'] = (string) $post_id;
		unset( $_REQUEST['media_manager_folder'] );

		$assignment = new UploadAssignment();
		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'context.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Context',
				'post_author'    => $user,
			]
		);

		$assignment->on_add_attachment( $attachment );

		$ids = ( new FolderRepository() )->attachment_folder_ids( $attachment );
		$this->assertSame( [ (int) $folder->term_id ], $ids );

		unset( $_REQUEST['post_id'] );
	}

	/**
	 * Selecting existing media is not auto-assigned by upload hooks alone.
	 *
	 * @return void
	 */
	public function test_existing_attachment_not_reassigned_without_upload() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Select Existing',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);
		( new ContentFolderService() )->ensure_post_folder( $post_id );

		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'existing.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Existing',
				'post_author'    => $user,
			]
		);

		$_REQUEST['post_id'] = (string) $post_id;
		// No add_attachment / rest_after_insert for an existing selection.
		$ids = ( new FolderRepository() )->attachment_folder_ids( $attachment );
		$this->assertSame( [], $ids );
		unset( $_REQUEST['post_id'] );
	}

	/**
	 * Name/parent stay fixed while managed; delete remains blocked.
	 *
	 * @return void
	 */
	public function test_move_and_delete_blocked_while_managed() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Locked',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);

		$service   = new ContentFolderService();
		$folder    = $service->ensure_post_folder( $post_id );
		$folder_id = (int) $folder->term_id;
		$other     = ( new FolderService() )->create( 'Elsewhere' );
		$original_parent = (int) $folder->parent;

		$folders = new FolderService();
		$moved   = $folders->update( $folder_id, 'Hacked Name', (int) $other->term_id );
		$this->assertNotWPError( $moved );
		$this->assertSame( 'Locked', $moved->name );
		$this->assertSame( $original_parent, (int) $moved->parent );

		$deleted = $folders->delete( $folder_id );
		$this->assertWPError( $deleted );
		$this->assertSame( 'media_manager_folder_locked', $deleted->get_error_code() );

		$root = $service->ensure_system_root( 'post' );
		$root_delete = $folders->delete( (int) $root->term_id );
		$this->assertWPError( $root_delete );
		$this->assertSame( 'media_manager_folder_locked', $root_delete->get_error_code() );
	}

	/**
	 * Orphaned generated folders can be deleted.
	 *
	 * @return void
	 */
	public function test_orphaned_folder_can_be_deleted() {
		$user = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user );

		( new PostLifecycle() )->register();

		$post_id = self::factory()->post->create(
			[
				'post_title'  => 'Orphan Me',
				'post_status' => 'publish',
				'post_author' => $user,
			]
		);

		$folder_id = ( new ContentFolderService() )->folder_id_for_post( $post_id );
		wp_delete_post( $post_id, true );

		$result = ( new FolderService() )->delete( $folder_id );
		$this->assertTrue( $result );
	}

	/**
	 * Eligible post types exclude non-content UI types and keep content types.
	 *
	 * @return void
	 */
	public function test_eligible_post_types_excludes_non_content_types() {
		$types = ( new ContentFolderService() )->eligible_post_types();

		$this->assertArrayHasKey( 'post', $types );
		$this->assertArrayHasKey( 'page', $types );
		$this->assertArrayNotHasKey( 'attachment', $types );
		$this->assertArrayNotHasKey( 'wp_navigation', $types );
		$this->assertArrayNotHasKey( 'wp_block', $types );
		$this->assertArrayNotHasKey( 'wp_template', $types );
		$this->assertArrayNotHasKey( 'wp_template_part', $types );
		$this->assertArrayNotHasKey( 'wp_global_styles', $types );
	}
}
