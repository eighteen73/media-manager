/**
 * @fileoverview Media modal adapter E2E coverage.
 */

const { test, expect } = require( '@playwright/test' );

const enabled = !! process.env.MEDIA_MANAGER_E2E;

test.describe( 'Media modal folders', () => {
	test.skip(
		! enabled,
		'Set MEDIA_MANAGER_E2E=1 with a running wp-env to enable browser coverage.'
	);

	test( 'featured image modal can open with folder mount', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php' );
		await page.getByRole( 'button', { name: /set featured image/i } ).click();
		await expect( page.locator( '.media-manager-modal-folders' ) ).toBeVisible();
	} );
} );
