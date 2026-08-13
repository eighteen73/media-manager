/**
 * @fileoverview Restricted / private folder smoke coverage.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Restricted folders', () => {
	test( 'folder dialog exposes restrict access controls for administrators', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'upload.php' );
		await expect( page.locator( '#media-manager-folder-root' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Add Folder' } ).click();
		await expect( page.getByLabel( 'Restrict Access' ) ).toBeVisible();
		await expect( page.locator( '#media-manager-folder-private' ) ).toBeVisible();
	} );
} );
