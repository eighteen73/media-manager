/**
 * Tests for attachment details adapter helpers.
 */

import {
	ensureAttachmentFolderMount,
	folderIdsFromModel,
} from './attachment-details-helpers';

describe( 'attachment-details helpers', () => {
	it( 'reads folder IDs from a model', () => {
		const model = {
			get: ( key ) =>
				key === 'media_manager_folders' ? [ '3', 5, 0, 'x' ] : null,
		};
		expect( folderIdsFromModel( model ) ).toEqual( [ 3, 5 ] );
	} );

	it( 'inserts a mount node into settings', () => {
		const settings = document.createElement( 'div' );
		settings.className = 'settings';
		const info = document.createElement( 'div' );
		info.className = 'attachment-info';
		info.appendChild( settings );
		const el = document.createElement( 'div' );
		el.className = 'attachment-details';
		el.appendChild( info );

		const mount = ensureAttachmentFolderMount( { el } );
		expect( mount ).toBeTruthy();
		expect( mount.className ).toBe(
			'media-manager-attachment-folders-root'
		);
		expect( settings.lastChild ).toBe( mount );
		expect( ensureAttachmentFolderMount( { el } ) ).toBe( mount );
	} );
} );
