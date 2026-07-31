/**
 * Media Manager admin entry point.
 */

import { createRoot } from '@wordpress/element';
import { dispatch, select } from '@wordpress/data';
import './index.scss';
import { registerStore, STORE_NAME } from './store';
import FolderSidebar from './components/FolderSidebar';
import {
	mountMediaLibrarySidebar,
	observeLibraryCollection,
} from './adapters/media-library';
import {
	observeMediaFrames,
	subscribeToSelection,
	ensureModalFolderMount,
} from './adapters/media-modal';
import { enableFolderDragDrop } from './adapters/drag-drop';
import { mountUploadFolderSelects } from './adapters/uploader';

registerStore();

const boot = window.mediaManagerAdmin || {};
const teardowns = [];
let booted = false;

/**
 * Mount the library sidebar when the Media Library screen is present.
 */
function bootstrapLibrary() {
	const root = document.getElementById( 'media-manager-folder-root' );
	if ( ! root ) {
		return;
	}

	teardowns.push( mountMediaLibrarySidebar( { root } ) );

	const reactRoot = createRoot( root );
	let collection = null;

	const render = () => {
		reactRoot.render(
			<FolderSidebar collection={ collection } compact={ false } />
		);
	};

	render();

	teardowns.push(
		observeLibraryCollection( {
			onCollection( next ) {
				collection = next;
				render();
			},
		} )
	);

	if ( window.wp?.media?.frame ) {
		teardowns.push(
			subscribeToSelection( window.wp.media.frame, ( ids ) => {
				dispatch( STORE_NAME ).setSelectedAttachmentIds( ids );
			} )
		);
	}

	teardowns.push( () => reactRoot.unmount() );
}

/**
 * Enhance media modal frames with folder UI.
 */
function bootstrapModals() {
	if ( boot.settings && boot.settings.show_modal_sidebar === false ) {
		return;
	}

	teardowns.push(
		observeMediaFrames( {
			onFrame( frame ) {
				let reactRoot = null;
				let unsubscribe = null;
				let mountEl = null;
				let retryTimer = null;

				const cleanupMount = () => {
					if ( retryTimer ) {
						window.clearTimeout( retryTimer );
						retryTimer = null;
					}
					if ( unsubscribe ) {
						unsubscribe();
						unsubscribe = null;
					}
					if ( reactRoot ) {
						reactRoot.unmount();
						reactRoot = null;
					}
					if ( mountEl ) {
						delete mountEl.dataset.mounted;
						mountEl.remove();
						mountEl = null;
					}
					frame.$el
						?.find?.( '.attachments-browser' )
						?.removeClass?.( 'has-media-manager-folders' );
					frame.$el
						?.get?.( 0 )
						?.classList.remove( 'has-media-manager-folders' );
				};

				const mount = () => {
					const el = ensureModalFolderMount( frame );
					if ( ! el ) {
						// Browse mode can fire render before AttachmentsBrowser is in the DOM.
						if ( ! retryTimer ) {
							retryTimer = window.setTimeout( () => {
								retryTimer = null;
								mount();
							}, 50 );
						}
						return;
					}

					// Same browser node already hosting the sidebar.
					if ( el === mountEl && el.dataset.mounted === '1' ) {
						return;
					}

					// Browser was recreated (tab switch) — replace the previous root.
					if ( reactRoot && el !== mountEl ) {
						reactRoot.unmount();
						reactRoot = null;
						if ( unsubscribe ) {
							unsubscribe();
							unsubscribe = null;
						}
					}

					mountEl = el;
					el.dataset.mounted = '1';

					if ( ! reactRoot ) {
						reactRoot = createRoot( el );
					}

					const library =
						typeof frame.state === 'function'
							? frame.state()?.get?.( 'library' )
							: frame.state?.get?.( 'library' );

					reactRoot.render(
						<FolderSidebar
							collection={ library }
							frame={ frame }
							compact
						/>
					);

					if ( ! unsubscribe ) {
						unsubscribe = subscribeToSelection( frame, ( ids ) => {
							dispatch( STORE_NAME ).setSelectedAttachmentIds(
								ids
							);
						} );
					}
				};

				frame.on?.( 'ready', mount );
				// activate fires after the browse view is in the DOM; render can be too early.
				frame.on?.( 'content:activate:browse', mount );
				frame.on?.( 'content:render:browse', mount );
				frame.on?.( 'content:create:browse', mount );
				frame.on?.( 'content:render:upload', cleanupMount );
				frame.on?.( 'content:activate:upload', cleanupMount );
				frame.on?.( 'close', cleanupMount );
				mount();
			},
		} )
	);
}

/**
 * Mount Choose folder controls on upload surfaces.
 */
function bootstrapUploadFolderSelect() {
	if ( ! boot.capabilities?.assign ) {
		return;
	}

	const hasSidebarRoot = !! document.getElementById(
		'media-manager-folder-root'
	);

	if ( ! hasSidebarRoot ) {
		// Load folders for Choose folder controls. Do not pre-select the
		// generated post folder here — that would filter the modal library to an
		// often-empty folder. Upload defaults use generatedFolderId as fallback.
		dispatch( STORE_NAME ).loadFolders();
	}

	teardowns.push(
		mountUploadFolderSelects( {
			getProps: () => {
				const store = select( STORE_NAME );
				const selected = store.getSelectedFolder();
				const generated =
					boot.generatedFolderId &&
					Number( boot.generatedFolderId ) > 0
						? Number( boot.generatedFolderId )
						: null;
				return {
					folders: store.getTree(),
					value:
						selected && Number( selected ) > 0
							? Number( selected )
							: generated,
					canAssign: !! boot.capabilities.assign,
				};
			},
			onChange: ( folderId ) => {
				dispatch( STORE_NAME ).selectFolder(
					folderId && Number( folderId ) > 0
						? Number( folderId )
						: null
				);
			},
		} )
	);
}

/**
 * Boot once. admin_footer prints the mount node before footer scripts.
 */
function bootWhenReady() {
	if ( booted ) {
		return;
	}
	booted = true;

	bootstrapModals();
	bootstrapUploadFolderSelect();
	teardowns.push( enableFolderDragDrop() );

	const startLibrary = () => {
		bootstrapLibrary();
	};

	if ( document.getElementById( 'media-manager-folder-root' ) ) {
		startLibrary();
		return;
	}

	// Fallback if the root is injected later than expected.
	const observer = new MutationObserver( () => {
		if ( document.getElementById( 'media-manager-folder-root' ) ) {
			observer.disconnect();
			startLibrary();
		}
	} );
	observer.observe( document.documentElement, {
		childList: true,
		subtree: true,
	} );
	window.setTimeout( () => observer.disconnect(), 5000 );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', bootWhenReady );
} else {
	bootWhenReady();
}

window.mediaManagerTeardown = () => {
	while ( teardowns.length ) {
		const teardown = teardowns.pop();
		if ( typeof teardown === 'function' ) {
			teardown();
		}
	}
};
