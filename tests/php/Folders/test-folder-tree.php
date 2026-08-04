<?php
/**
 * Folder tree and attachment query tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\AttachmentQuery;
use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Folders\FolderTree;
use Eighteen73\MediaManager\Install\Installer;

/**
 * Tests tree counts and query filtering.
 */
class Test_Folder_Tree extends WP_UnitTestCase {

	/**
	 * Service.
	 *
	 * @var FolderService
	 */
	private $service;

	/**
	 * Repository.
	 *
	 * @var FolderRepository
	 */
	private $repository;

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->service    = new FolderService();
		$this->repository = new FolderRepository();
	}

	/**
	 * Tree returns nested resources with direct and recursive counts.
	 *
	 * @return void
	 */
	public function test_tree_counts_and_ordering() {
		$alpha = $this->service->create( 'Alpha' );
		$beta  = $this->service->create( 'Beta' );
		$child = $this->service->create( 'Child', (int) $alpha->term_id );

		$a1 = self::factory()->attachment->create_object(
			[
				'file'           => 'a1.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'A1',
			]
		);
		$c1 = self::factory()->attachment->create_object(
			[
				'file'           => 'c1.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'C1',
			]
		);

		$this->repository->set_attachment_folders( $a1, [ (int) $alpha->term_id ], false );
		$this->repository->set_attachment_folders( $c1, [ (int) $child->term_id ], false );

		$tree = ( new FolderTree() )->get_tree( true );

		$this->assertSame( 2, $tree['summary']['all_count'] );
		$this->assertSame( 0, $tree['summary']['uncategorized_count'] );
		$this->assertCount( 2, $tree['folders'] );
		$this->assertSame( 'Alpha', $tree['folders'][0]['name'] );
		$this->assertSame( 'Beta', $tree['folders'][1]['name'] );
		$this->assertSame( 1, $tree['folders'][0]['direct_count'] );
		$this->assertSame( 2, $tree['folders'][0]['count'] );
		$this->assertSame( 0, $tree['folders'][1]['count'] );
		$this->assertSame( 'Child', $tree['folders'][0]['children'][0]['name'] );
		$this->assertSame( 1, $tree['folders'][0]['children'][0]['count'] );
	}
}

/**
 * Tests attachment query folder filtering.
 */
class Test_Attachment_Query extends WP_UnitTestCase {

	/**
	 * Query helper.
	 *
	 * @var AttachmentQuery
	 */
	private $queries;

	/**
	 * Setup.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->queries = new AttachmentQuery();
	}

	/**
	 * Selection normalisation.
	 *
	 * @return void
	 */
	public function test_selection_from_request() {
		$this->assertNull( $this->queries->selection_from_request( null ) );
		$this->assertNull( $this->queries->selection_from_request( 'all' ) );
		$this->assertSame( 0, $this->queries->selection_from_request( '0' ) );
		$this->assertSame( 0, $this->queries->selection_from_request( 'uncategorized' ) );
	}

	/**
	 * Apply semantics for all, uncategorized, and folder filters.
	 *
	 * @return void
	 */
	public function test_apply_semantics() {
		$service = new FolderService();
		$folder  = $service->create( 'Docs' );

		$all = $this->queries->apply( [ 'post_type' => 'attachment' ], null );
		$this->assertArrayNotHasKey( 'tax_query', $all );

		$uncategorized = $this->queries->apply( [], 0 );
		$this->assertSame( 'NOT EXISTS', $uncategorized['tax_query'][0]['operator'] );

		$filtered = $this->queries->apply(
			[
				'tax_query' => [
					[
						'taxonomy' => 'category',
						'terms'    => [ 1 ],
					],
				],
			],
			(int) $folder->term_id
		);

		$this->assertSame( 'AND', $filtered['tax_query']['relation'] );
		$this->assertTrue( $filtered['tax_query'][1]['include_children'] );

		$invalid = $this->queries->apply( [], 999999 );
		$this->assertSame( [ 0 ], $invalid['post__in'] );
	}
}
