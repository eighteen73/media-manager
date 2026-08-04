/**
 * @fileoverview Media modal adapter E2E coverage.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Media modal folders', () => {
	test( 'image block media library modal mounts folder UI', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.insertBlock( { name: 'core/image' } );
		await editor.canvas
			.getByRole( 'button', { name: 'Media Library' } )
			.click();
		await expect( page.locator( '.media-manager-modal-folders' ) ).toBeVisible();
	} );
} );
