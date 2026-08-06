/**
 * Adapter contract tests.
 */

import {
	serializeFolderId,
	setCollectionFolder,
	ensureLibraryObservesUploads,
	mountMediaLibrarySidebar,
	resolveLibraryCollection,
	clampSidebarWidth,
} from './media-library';
import { setUploaderFolder, findUploadHosts, ensureUploadSelectMount, observeUploadsForFolderCounts } from './uploader';
import { observeMediaFrames, ensureModalFolderMount } from './media-modal';
import { resolveDragAttachmentIds } from './drag-drop';

beforeAll( () => {
	global.fetch = jest.fn( () => Promise.resolve( { ok: true } ) );
} );

describe( 'media library adapters', () => {
	it( 'clamps sidebar width to the resizable range', () => {
		expect( clampSidebarWidth( undefined ) ).toBe( 300 );
		expect( clampSidebarWidth( 180 ) ).toBe( 250 );
		expect( clampSidebarWidth( 320 ) ).toBe( 320 );
		expect( clampSidebarWidth( 800 ) ).toBe( 500 );
	} );

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
		window.mediaManagerAdmin = {
			preferences: { sidebar_width: 360 },
		};
		document.body.innerHTML =
			'<div id="wpbody"><div id="wpbody-content"></div><div id="root" hidden><aside class="media-manager-sidebar"></aside></div></div>';
		const root = document.getElementById( 'root' );
		const teardown = mountMediaLibrarySidebar( { root } );

		expect( root.hidden ).toBe( false );
		expect( root.style.width ).toBe( '360px' );
		expect(
			root.classList.contains( 'media-manager-folder-root--mounted' )
		).toBe( true );
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
		expect( root.style.width ).toBe( '' );
		expect(
			root.classList.contains( 'media-manager-folder-root--mounted' )
		).toBe( false );
		expect( document.getElementById( 'media-manager-handler' ) ).toBeNull();
		delete window.mediaManagerAdmin;
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

	describe( 'observeUploadsForFolderCounts', () => {
		let loadFolders;
		let queueHandlers;
		let originalWp;

		beforeEach( () => {
			jest.useFakeTimers();
			originalWp = window.wp;
			loadFolders = jest.fn();
			queueHandlers = {};
			window.wp = {
				data: {
					dispatch: jest.fn( ( store ) =>
						store === 'media-manager/folders'
							? { loadFolders }
							: {}
					),
				},
				Uploader: {
					queue: {
						on: jest.fn( ( event, handler ) => {
							queueHandlers[ event ] = handler;
						} ),
						off: jest.fn( ( event, handler ) => {
							if ( queueHandlers[ event ] === handler ) {
								delete queueHandlers[ event ];
							}
						} ),
					},
				},
			};
		} );

		afterEach( () => {
			jest.useRealTimers();
			window.wp = originalWp;
		} );

		it( 'reloads folder counts when an upload finishes', () => {
			const attachmentHandlers = {};
			const attachment = {
				get: jest.fn( ( key ) =>
					key === 'uploading' ? true : undefined
				),
				on: jest.fn( ( event, handler ) => {
					attachmentHandlers[ event ] = handler;
				} ),
			};

			const teardown = observeUploadsForFolderCounts();
			expect( window.wp.Uploader.queue.on ).toHaveBeenCalledWith(
				'add',
				expect.any( Function )
			);

			queueHandlers.add( attachment );
			expect( attachment.on ).toHaveBeenCalledWith(
				'change:uploading',
				expect.any( Function )
			);
			expect( loadFolders ).not.toHaveBeenCalled();

			attachment.get.mockImplementation( ( key ) =>
				key === 'uploading' ? false : undefined
			);
			attachmentHandlers[ 'change:uploading' ]( attachment );

			jest.advanceTimersByTime( 150 );
			expect( loadFolders ).toHaveBeenCalledTimes( 1 );
			expect( window.wp.data.dispatch ).toHaveBeenCalledWith(
				'media-manager/folders'
			);

			teardown();
			expect( window.wp.Uploader.queue.off ).toHaveBeenCalledWith(
				'add',
				expect.any( Function )
			);
		} );

		it( 'reloads folder counts for each finished upload', () => {
			const makeAttachment = () => {
				const handlers = {};
				const attachment = {
					get: jest.fn( ( key ) =>
						key === 'uploading' ? true : undefined
					),
					on: jest.fn( ( event, handler ) => {
						handlers[ event ] = handler;
					} ),
					triggerFinish() {
						attachment.get.mockImplementation( ( key ) =>
							key === 'uploading' ? false : undefined
						);
						handlers[ 'change:uploading' ]( attachment );
					},
				};
				return attachment;
			};

			observeUploadsForFolderCounts();

			const first = makeAttachment();
			const second = makeAttachment();
			queueHandlers.add( first );
			queueHandlers.add( second );

			first.triggerFinish();
			jest.advanceTimersByTime( 150 );
			expect( loadFolders ).toHaveBeenCalledTimes( 1 );

			second.triggerFinish();
			jest.advanceTimersByTime( 150 );
			expect( loadFolders ).toHaveBeenCalledTimes( 2 );
		} );

		it( 'reloads immediately when a finished attachment is added', () => {
			const attachment = {
				get: jest.fn( ( key ) =>
					key === 'uploading' ? false : undefined
				),
				on: jest.fn(),
			};

			observeUploadsForFolderCounts();
			queueHandlers.add( attachment );

			expect( attachment.on ).not.toHaveBeenCalled();
			jest.advanceTimersByTime( 150 );
			expect( loadFolders ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'is a no-op teardown when the upload queue is missing', () => {
			window.wp = { data: { dispatch: () => ( { loadFolders } ) } };
			const teardown = observeUploadsForFolderCounts();
			expect( typeof teardown ).toBe( 'function' );
			teardown();
			expect( loadFolders ).not.toHaveBeenCalled();
		} );
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

	it( 'mounts from a content:create browserView before DOM attach', () => {
		const browser = document.createElement( 'div' );
		browser.className = 'attachments-browser';
		const frameEl = document.createElement( 'div' );
		const frame = {
			$el: {
				get: () => frameEl,
				0: frameEl,
				find: () => ( { get: () => null } ),
			},
			content: { get: () => null },
		};

		const mount = ensureModalFolderMount( frame, { el: browser } );

		expect( mount ).toBeTruthy();
		expect( mount.className ).toBe( 'media-manager-modal-folders' );
		expect( browser.firstChild ).toBe( mount );
		expect( browser.classList.contains( 'has-media-manager-folders' ) ).toBe(
			true
		);
		expect(
			frameEl.classList.contains( 'has-media-manager-folders' )
		).toBe( true );
		expect( ensureModalFolderMount( frame, { el: browser } ) ).toBe( mount );
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
