/**
 * @fileoverview Private folder lifecycle E2E coverage.
 */

const path = require( 'path' );
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Private folders', () => {
	test( 'shows a private badge and filters the library grid', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( 'upload.php' );
		await expect( page.locator( '#media-manager-folder-root' ) ).toBeVisible();

		const storageReady = await page.evaluate(
			() => !! window.mediaManagerAdmin?.privateStorageReady
		);
		test.skip(
			! storageReady,
			'Private storage is not ready in this environment'
		);

		const folderName = `Private Vault ${ Date.now() }`;
		const folder = await requestUtils.rest( {
			method: 'POST',
			path: '/media-manager/v1/folders',
			data: {
				name: folderName,
				parent: 0,
				is_private: true,
			},
		} );
		expect( folder?.id ).toBeTruthy();

		await admin.visitAdminPage( 'upload.php' );
		const folderNav = page.locator( '.media-manager-folder-nav', {
			hasText: folderName,
		} );
		await expect( folderNav ).toBeVisible();
		await expect( folderNav.getByLabel( 'Private folder' ) ).toBeVisible();

		await folderNav.click();
		await expect( folderNav ).toHaveClass( /is-current/ );

		await page.locator( '.media-manager-folder-nav--all' ).click();
		await expect(
			page.locator( '.media-manager-folder-nav--all.is-current' )
		).toBeVisible();
	} );

	test( 'moves media into a private folder and rewrites the source URL', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( 'upload.php' );
		const storageReady = await page.evaluate(
			() => !! window.mediaManagerAdmin?.privateStorageReady
		);
		test.skip(
			! storageReady,
			'Private storage is not ready in this environment'
		);

		const folderName = `REST Private ${ Date.now() }`;
		const folder = await requestUtils.rest( {
			method: 'POST',
			path: '/media-manager/v1/folders',
			data: {
				name: folderName,
				parent: 0,
				is_private: true,
			},
		} );
		expect( folder?.id ).toBeTruthy();

		const media = await requestUtils.uploadMedia(
			path.join( __dirname, '../fixtures/pixel.png' )
		);
		expect( media?.id ).toBeTruthy();

		await requestUtils.rest( {
			method: 'POST',
			path: '/media-manager/v1/assignments',
			data: {
				attachment_ids: [ media.id ],
				folder_id: folder.id,
				mode: 'move',
				confirm: true,
			},
		} );

		await admin.visitAdminPage( 'upload.php' );
		await page
			.locator( '.media-manager-folder-nav', { hasText: folderName } )
			.click();

		const attachment = await requestUtils.rest( {
			method: 'GET',
			path: `/wp/v2/media/${ media.id }`,
			params: { context: 'edit' },
		} );

		expect( attachment.media_manager_is_private ).toBeTruthy();
		expect( attachment.source_url || attachment.url ).toMatch(
			/media_manager_private=|\/media\//
		);
	} );
} );
