<?php
/**
 * Folder taxonomy and capability tests.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderTaxonomy;
use Eighteen73\MediaManager\Install\Installer;
use Eighteen73\MediaManager\Permissions\Capabilities;

/**
 * Tests taxonomy registration and default capabilities.
 */
class Test_Folder_Taxonomy extends WP_UnitTestCase {

	/**
	 * Activate defaults before each test.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Installer::activate();
		do_action( 'init' );
	}

	/**
	 * Taxonomy is private, hierarchical, and attachment-scoped.
	 *
	 * @return void
	 */
	public function test_taxonomy_registration() {
		$taxonomy = get_taxonomy( FolderTaxonomy::NAME );

		$this->assertNotFalse( $taxonomy );
		$this->assertTrue( $taxonomy->hierarchical );
		$this->assertFalse( $taxonomy->public );
		$this->assertFalse( $taxonomy->publicly_queryable );
		$this->assertFalse( $taxonomy->show_ui );
		$this->assertFalse( $taxonomy->show_in_menu );
		$this->assertFalse( $taxonomy->show_in_rest );
		$this->assertFalse( $taxonomy->show_admin_column );
		$this->assertFalse( $taxonomy->query_var );
		$this->assertFalse( $taxonomy->rewrite );
		$this->assertFalse( $taxonomy->meta_box_cb );
		$this->assertSame( '_update_generic_term_count', $taxonomy->update_count_callback );
		$this->assertContains( 'attachment', $taxonomy->object_type );
	}

	/**
	 * Administrators receive all capabilities; editors receive defaults.
	 *
	 * @return void
	 */
	public function test_default_capability_grants() {
		$admin  = get_role( 'administrator' );
		$editor = get_role( 'editor' );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertTrue( $admin->has_cap( $capability ), $capability );
		}

		$this->assertTrue( $editor->has_cap( Capabilities::VIEW ) );
		$this->assertTrue( $editor->has_cap( Capabilities::ASSIGN ) );
		$this->assertTrue( $editor->has_cap( Capabilities::SHARED ) );
		$this->assertFalse( $editor->has_cap( Capabilities::EDIT ) );
		$this->assertFalse( $editor->has_cap( Capabilities::DELETE ) );
		$this->assertFalse( $editor->has_cap( Capabilities::SETTINGS ) );
	}

	/**
	 * Activation stores the schema version without autoloading.
	 *
	 * @return void
	 */
	public function test_activation_stores_version() {
		$this->assertSame( Installer::VERSION, get_option( Installer::VERSION_OPTION ) );
	}
}
