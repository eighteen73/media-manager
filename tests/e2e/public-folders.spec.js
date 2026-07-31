/**
 * @fileoverview Public folders Media Library E2E coverage.
 */

const { test, expect } = require( '@playwright/test' );

const enabled = !! process.env.MEDIA_MANAGER_E2E;

test.describe( 'Public folders', () => {
	test.skip(
		! enabled,
		'Set MEDIA_MANAGER_E2E=1 with a running wp-env to enable browser coverage.'
	);

	test( 'media library screen loads folder root for administrators', async ( {
		page,
	} ) => {
		await page.goto( '/wp-admin/upload.php' );
		await expect( page.locator( '#media-manager-folder-root' ) ).toBeVisible();
		await expect( page.getByText( 'All files' ) ).toBeVisible();
		await expect( page.getByText( 'Uncategorized' ) ).toBeVisible();
	} );
} );
