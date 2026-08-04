/**
 * @fileoverview Media Library folder sidebar CRUD and filter E2E coverage.
 */

const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Folder sidebar', () => {
	test( 'lists, selects, and deletes folders', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const folderName = `E2E Folder ${ Date.now() }`;
		const renamed = `${ folderName } Renamed`;

		const created = await requestUtils.rest( {
			method: 'POST',
			path: '/media-manager/v1/folders',
			data: {
				name: folderName,
				parent: 0,
			},
		} );
		expect( created?.id ).toBeTruthy();

		await requestUtils.rest( {
			method: 'POST',
			path: `/media-manager/v1/folders/${ created.id }`,
			data: {
				name: renamed,
				parent: 0,
			},
		} );

		await admin.visitAdminPage( 'upload.php' );
		await expect( page.locator( '#media-manager-folder-root' ) ).toBeVisible();
		await expect(
			page.locator( '.media-manager-folder-nav', { hasText: renamed } )
		).toBeVisible();

		await page
			.locator( '.media-manager-folder-nav', { hasText: renamed } )
			.click();
		await expect(
			page.locator( '.media-manager-folder-nav.is-current', {
				hasText: renamed,
			} )
		).toBeVisible();

		await page.locator( '.media-manager-folder-nav--all' ).click();
		await expect(
			page.locator( '.media-manager-folder-nav--all.is-current' )
		).toBeVisible();

		await page.locator( '.media-manager-folder-nav[data-id="0"]' ).click();
		await expect(
			page.locator( '.media-manager-folder-nav.is-current[data-id="0"]' )
		).toBeVisible();

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page
			.locator( '.media-manager-folder-nav', { hasText: renamed } )
			.click();
		await page.getByRole( 'button', { name: 'Delete folder' } ).click();
		await expect(
			page.locator( '.media-manager-folder-nav', { hasText: renamed } )
		).toHaveCount( 0 );
	} );

	test( 'modal compact sidebar starts on All files and clears on Upload', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost();
		await editor.insertBlock( { name: 'core/image' } );
		await editor.canvas
			.getByRole( 'button', { name: 'Media Library' } )
			.click();

		const folders = page.locator( '.media-manager-modal-folders' );
		await expect( folders ).toBeVisible();
		await expect(
			folders.locator( '.media-manager-folder-nav--all.is-current' )
		).toBeVisible();

		await page.getByRole( 'tab', { name: 'Upload files' } ).click();
		await expect( page.locator( '.media-manager-modal-folders' ) ).toHaveCount(
			0
		);
	} );
} );
