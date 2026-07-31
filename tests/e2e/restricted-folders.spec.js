/**
 * @fileoverview Restricted / private folder smoke coverage.
 */

const { test, expect } = require( '@playwright/test' );

const enabled = !! process.env.MEDIA_MANAGER_E2E;

test.describe( 'Restricted folders', () => {
	test.skip(
		! enabled,
		'Set MEDIA_MANAGER_E2E=1 with a running wp-env to enable browser coverage.'
	);

	test( 'folder dialog exposes restrict access controls for administrators', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/upload.php' );
		await expect( page.locator( '#media-manager-folder-root' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Add Folder' } ).click();
		await expect( page.getByLabel( 'Restrict Access' ) ).toBeVisible();
		await expect( page.getByLabel( 'Private' ) ).toBeVisible();
	} );
} );
