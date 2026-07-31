/**
 * Adapter contract tests.
 */

import {
	serializeFolderId,
	setCollectionFolder,
	ensureLibraryObservesUploads,
	mountMediaLibrarySidebar,
	resolveLibraryCollection,
} from './media-library';
import { setUploaderFolder, findUploadHosts, ensureUploadSelectMount } from './uploader';
import { observeMediaFrames } from './media-modal';
import { resolveDragAttachmentIds } from './drag-drop';

beforeAll( () => {
	global.fetch = jest.fn( () => Promise.resolve( { ok: true } ) );
} );

describe( 'media library adapters', () => {
	it( 'serializes folder selections', () => {
		expect( serializeFolderId( null ) ).toBe( 'all' );
		expect( serializeFolderId( 0 ) ).toBe( 0 );
		expect( serializeFolderId( 12 ) ).toBe( 12 );
	} );

	it( 'updates collection props for query collections', () => {
		const collection = {
			props: {
				get: jest.fn( ( key ) => {
					if ( key === 'query' ) {
						return true;
					}
					if ( key === 'media_manager_folder' ) {
						return undefined;
					}
					return undefined;
				} ),
				set: jest.fn(),
			},
			fetch: jest.fn(),
			observe: jest.fn(),
		};
		window.wp = { Uploader: { queue: { id: 'queue' } } };

		setCollectionFolder( collection, 5 );

		expect( collection.props.set ).toHaveBeenCalledWith( {
			media_manager_folder: 5,
		} );
		expect( collection.fetch ).not.toHaveBeenCalled();
		expect( collection.observe ).toHaveBeenCalledWith(
			window.wp.Uploader.queue
		);
	} );

	it( 'observes the upload queue even when folder is unchanged', () => {
		const collection = {
			props: {
				get: jest.fn( () => 5 ),
				set: jest.fn(),
			},
			observe: jest.fn(),
		};
		window.wp = { Uploader: { queue: { id: 'queue' } } };

		setCollectionFolder( collection, 5 );

		expect( collection.props.set ).not.toHaveBeenCalled();
		expect( collection.observe ).toHaveBeenCalledWith(
			window.wp.Uploader.queue
		);
		ensureLibraryObservesUploads( collection );
		expect( collection.observe ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fetches non-query collections after setting props', () => {
		const collection = {
			props: {
				get: jest.fn( ( key ) => {
					if ( key === 'query' ) {
						return false;
					}
					return undefined;
				} ),
				set: jest.fn(),
			},
			fetch: jest.fn(),
			observe: jest.fn(),
		};
		window.wp = { Uploader: { queue: { id: 'queue' } } };

		setCollectionFolder( collection, 5 );

		expect( collection.props.set ).toHaveBeenCalledWith( {
			media_manager_folder: 5,
		} );
		expect( collection.fetch ).toHaveBeenCalledWith( {
			reset: true,
			remove: true,
		} );
	} );

	it( 'resolves library collection via frame.state()', () => {
		const library = { id: 'library' };
		const frame = {
			content: { get: () => null },
			state: () => ( {
				get: ( key ) => ( key === 'library' ? library : null ),
			} ),
		};

		expect( resolveLibraryCollection( frame ) ).toBe( library );
	} );

	it( 'mounts and tears down the sidebar root', () => {
		document.body.innerHTML =
			'<div id="wpbody"><div id="wpbody-content"></div><div id="root" hidden><aside class="media-manager-sidebar"></aside></div></div>';
		const root = document.getElementById( 'root' );
		const teardown = mountMediaLibrarySidebar( { root } );

		expect( root.hidden ).toBe( false );
		expect(
			document
				.getElementById( 'wpbody' )
				.classList.contains( 'media-manager-has-folders' )
		).toBe( true );
		expect( document.getElementById( 'media-manager-handler' ) ).toBeTruthy();
		expect(
			document.body.classList.contains( 'media-manager-folders-active' )
		).toBe( true );

		teardown();
		expect( root.hidden ).toBe( true );
		expect( document.getElementById( 'media-manager-handler' ) ).toBeNull();
	} );
} );

describe( 'uploader adapter', () => {
	it( 'sets multipart folder params', () => {
		const uploader = {
			uploader: {
				settings: {
					multipart_params: {},
				},
			},
			param: jest.fn(),
			options: {
				multipart_params: {},
			},
		};

		setUploaderFolder( uploader, 9 );

		expect(
			uploader.uploader.settings.multipart_params.media_manager_folder
		).toBe( 9 );
		expect( uploader.param ).toHaveBeenCalledWith(
			'media_manager_folder',
			9
		);
		expect( uploader.options.multipart_params.media_manager_folder ).toBe(
			9
		);
	} );

	it( 'maps null and zero to all for uploads', () => {
		const uploader = {
			uploader: { settings: { multipart_params: {} } },
			options: { multipart_params: {} },
		};

		setUploaderFolder( uploader, null );
		expect(
			uploader.uploader.settings.multipart_params.media_manager_folder
		).toBe( 'all' );

		setUploaderFolder( uploader, 0 );
		expect(
			uploader.uploader.settings.multipart_params.media_manager_folder
		).toBe( 'all' );
	} );

	it( 'finds upload hosts and creates a mount node', () => {
		document.body.innerHTML = `
			<div id="plupload-upload-ui">
				<div class="drag-drop-inside">
					<p class="drag-drop-buttons">
						<input id="plupload-browse-button" type="button" class="button" value="Select Files" />
					</p>
				</div>
			</div>
		`;

		const hosts = findUploadHosts();
		expect( hosts ).toHaveLength( 1 );

		const mount = ensureUploadSelectMount( hosts[ 0 ] );
		expect( mount ).toBeTruthy();
		expect( mount.className ).toBe( 'media-manager-upload-folder-root' );
		expect(
			hosts[ 0 ].querySelector( '.drag-drop-buttons' ).nextSibling
		).toBe( mount );
		expect( ensureUploadSelectMount( hosts[ 0 ] ) ).toBe( mount );
	} );
} );

describe( 'media modal adapter', () => {
	it( 'does not double-observe when torn down', () => {
		const open = jest.fn( function () {
			return this;
		} );
		const OriginalModal = function Modal() {};
		OriginalModal.prototype.open = open;
		OriginalModal.extend = function ( proto ) {
			function Child() {}
			Child.prototype = Object.assign(
				Object.create( OriginalModal.prototype ),
				proto
			);
			return Child;
		};

		window.wp = {
			media: {
				view: {
					Modal: OriginalModal,
				},
			},
		};

		const onFrame = jest.fn();
		const teardown = observeMediaFrames( { onFrame } );
		const Modal = window.wp.media.view.Modal;
		const modal = new Modal();
		modal.options = { controller: { id: 1 } };
		modal.open();
		modal.open();

		expect( onFrame ).toHaveBeenCalledTimes( 1 );
		teardown();
		expect( window.wp.media.view.Modal ).toBe( OriginalModal );
	} );
} );

describe( 'drag-drop adapter', () => {
	afterEach( () => {
		document.body.innerHTML = '';
		delete window.jQuery;
	} );

	it( 'resolves selected attachment ids for a drag', () => {
		document.body.innerHTML = `
			<ul class="attachments">
				<li class="attachment selected" data-id="11"></li>
				<li class="attachment selected" data-id="12"></li>
				<li class="attachment" data-id="13"></li>
			</ul>
		`;

		const nodes = [ ...document.querySelectorAll( '.attachment.selected' ) ];
		window.jQuery = ( selector ) => {
			if (
				selector === '.attachments .selected:not(.selection,:hidden)'
			) {
				return {
					length: nodes.length,
					each( callback ) {
						nodes.forEach( ( node, index ) => {
							callback.call( node, index );
						} );
					},
				};
			}

			const el = typeof selector === 'object' ? selector : null;
			return {
				length: el ? 1 : 0,
				data( key ) {
					return el ? Number( el.getAttribute( `data-${ key }` ) ) : undefined;
				},
				attr() {
					return undefined;
				},
				each() {},
			};
		};
		window.jQuery.fn = {};

		expect( resolveDragAttachmentIds( {} ) ).toEqual( [ 11, 12 ] );
	} );
} );
