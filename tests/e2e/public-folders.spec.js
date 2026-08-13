/**
 * @fileoverview Public folders Media Library E2E coverage.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Public folders', () => {
	test( 'media library screen loads folder root for administrators', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'upload.php' );
		await expect( page.locator( '#media-manager-folder-root' ) ).toBeVisible();
		await expect( page.getByText( 'All files' ) ).toBeVisible();
		await expect( page.getByText( 'Uncategorized' ) ).toBeVisible();
	} );
} );
