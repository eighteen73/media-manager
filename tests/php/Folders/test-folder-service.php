<?php
/**
 * Folder service domain tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Folders\FolderTaxonomy;
use Eighteen73\MediaManager\Install\Installer;

/**
 * Tests folder CRUD and hierarchy rules.
 */
class Test_Folder_Service extends WP_UnitTestCase {

	/**
	 * Folder service.
	 *
	 * @var FolderService
	 */
	private $service;

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private $repository;

	/**
	 * Prepare taxonomy and service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
		$this->service    = new FolderService();
		$this->repository = new FolderRepository();
	}

	/**
	 * Creating a folder sanitises the name.
	 *
	 * @return void
	 */
	public function test_create_sanitises_name() {
		$folder = $this->service->create( '  Reports <b>Q1</b>  ' );

		$this->assertInstanceOf( WP_Term::class, $folder );
		$this->assertSame( 'Reports Q1', $folder->name );
	}

	/**
	 * Duplicate names under the same parent are rejected.
	 *
	 * @return void
	 */
	public function test_duplicate_under_parent_rejected() {
		$first = $this->service->create( 'Policies' );
		$this->assertInstanceOf( WP_Term::class, $first );

		$duplicate = $this->service->create( 'Policies' );
		$this->assertWPError( $duplicate );
		$this->assertSame( 'media_manager_folder_exists', $duplicate->get_error_code() );
	}

	/**
	 * Folders can be renamed and moved to a valid parent.
	 *
	 * @return void
	 */
	public function test_rename_and_valid_move() {
		$parent = $this->service->create( 'Parent' );
		$child  = $this->service->create( 'Child' );

		$updated = $this->service->update( (int) $child->term_id, 'Child Renamed', (int) $parent->term_id );

		$this->assertInstanceOf( WP_Term::class, $updated );
		$this->assertSame( 'Child Renamed', $updated->name );
		$this->assertSame( (int) $parent->term_id, (int) $updated->parent );
	}

	/**
	 * Cycles are rejected.
	 *
	 * @return void
	 */
	public function test_cycle_rejected() {
		$parent = $this->service->create( 'Parent' );
		$child  = $this->service->create( 'Child', (int) $parent->term_id );

		$result = $this->service->update( (int) $parent->term_id, 'Parent', (int) $child->term_id );

		$this->assertWPError( $result );
		$this->assertSame( 'media_manager_invalid_parent', $result->get_error_code() );
	}

	/**
	 * Maximum depth of five is enforced.
	 *
	 * @return void
	 */
	public function test_max_depth_enforced() {
		$parent_id = 0;

		for ( $i = 1; $i <= 5; $i++ ) {
			$folder    = $this->service->create( "Level {$i}", $parent_id );
			$this->assertInstanceOf( WP_Term::class, $folder, "Failed at level {$i}" );
			$parent_id = (int) $folder->term_id;
		}

		$sixth = $this->service->create( 'Level 6', $parent_id );
		$this->assertWPError( $sixth );
		$this->assertSame( 'media_manager_max_depth', $sixth->get_error_code() );
	}

	/**
	 * Deleting a folder with children is rejected.
	 *
	 * @return void
	 */
	public function test_delete_with_children_rejected() {
		$parent = $this->service->create( 'Parent' );
		$this->service->create( 'Child', (int) $parent->term_id );

		$result = $this->service->delete( (int) $parent->term_id );

		$this->assertWPError( $result );
		$this->assertSame( 'media_manager_folder_has_children', $result->get_error_code() );
	}

	/**
	 * Deleting a folder preserves attachments.
	 *
	 * @return void
	 */
	public function test_delete_preserves_attachments() {
		$folder     = $this->service->create( 'Docs' );
		$attachment = self::factory()->attachment->create_object(
			[
				'file'           => 'test.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Test',
			]
		);

		$this->repository->set_attachment_folders( $attachment, [ (int) $folder->term_id ], false );
		$this->assertSame( [ (int) $folder->term_id ], $this->repository->attachment_folder_ids( $attachment ) );

		$result = $this->service->delete( (int) $folder->term_id );
		$this->assertTrue( $result );
		$this->assertSame( $attachment, get_post( $attachment )->ID );
		$this->assertSame( [], $this->repository->attachment_folder_ids( $attachment ) );
		$this->assertNull( get_term( (int) $folder->term_id, FolderTaxonomy::NAME ) );
	}
}
