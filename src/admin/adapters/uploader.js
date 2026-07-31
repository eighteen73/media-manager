/**
 * Uploader adapter for folder-aware multipart params and upload UI.
 */

import { createRoot } from '@wordpress/element';
import UploadFolderSelect from '../components/UploadFolderSelect';

/**
 * Set the folder ID on an uploader's multipart params.
 *
 * @param {Object}      uploader Uploader / plupload wrapper.
 * @param {number|null} folderId Folder selection.
 */
export function setUploaderFolder( uploader, folderId ) {
	if ( ! uploader ) {
		return;
	}

	const value =
		folderId === null || typeof folderId === 'undefined' || folderId === 0
			? 'all'
			: folderId;

	if ( uploader.uploader?.settings?.multipart_params ) {
		uploader.uploader.settings.multipart_params.media_manager_folder =
			value;
	}

	if ( uploader.param && typeof uploader.param === 'function' ) {
		uploader.param( 'media_manager_folder', value );
	}

	if ( uploader.options?.multipart_params ) {
		uploader.options.multipart_params.media_manager_folder = value;
	}
}

/**
 * Resolve the active Plupload / wp.Uploader instance.
 *
 * @param {Object|null} frame Optional media frame.
 * @return {Object|null} Uploader wrapper.
 */
export function resolveActiveUploader( frame = null ) {
	const mediaFrame = frame || window.wp?.media?.frame;

	if ( mediaFrame ) {
		const fromFrame =
			mediaFrame.uploader?.uploader || mediaFrame.uploader || null;
		if ( fromFrame ) {
			return fromFrame;
		}
	}

	if ( window.uploader ) {
		return window.uploader;
	}

	return null;
}

/**
 * Apply folder selection to every known uploader on the page.
 *
 * @param {number|null} folderId Folder selection.
 * @param {Object|null} frame    Optional media frame.
 */
export function syncAllUploaders( folderId, frame = null ) {
	const seen = new Set();
	const candidates = [
		resolveActiveUploader( frame ),
		window.uploader || null,
		window.wp?.media?.frame
			? resolveActiveUploader( window.wp.media.frame )
			: null,
	];

	candidates.forEach( ( uploader ) => {
		if ( ! uploader || seen.has( uploader ) ) {
			return;
		}
		seen.add( uploader );
		setUploaderFolder( uploader, folderId );
	} );

	if ( window.wpUploaderInit?.multipart_params ) {
		window.wpUploaderInit.multipart_params.media_manager_folder =
			folderId === null ||
			typeof folderId === 'undefined' ||
			folderId === 0
				? 'all'
				: folderId;
	}
}

/**
 * Bind folder updates to a media frame uploader.
 *
 * @param {Object}      frame    Media frame.
 * @param {number|null} folderId Folder selection.
 */
export function syncFrameUploader( frame, folderId ) {
	if ( ! frame ) {
		return;
	}

	const uploader = frame.uploader?.uploader || frame.uploader;
	setUploaderFolder( uploader, folderId );

	const state = typeof frame.state === 'function' ? frame.state() : null;
	const library =
		state?.get?.( 'library' ) ||
		( typeof frame.state?.get === 'function'
			? frame.state.get( 'library' )
			: null );

	if ( library?.props?.set ) {
		library.props.set( {
			media_manager_folder:
				folderId === null || typeof folderId === 'undefined'
					? 'all'
					: folderId,
		} );
	}
}

/**
 * Find DOM hosts that should receive the upload folder select.
 *
 * @return {HTMLElement[]} Host elements.
 */
export function findUploadHosts() {
	const hosts = [];
	const selectors = [
		'.uploader-inline-content',
		'#plupload-upload-ui .drag-drop-inside',
		'#plupload-upload-ui .upload-ui',
	];

	selectors.forEach( ( selector ) => {
		document.querySelectorAll( selector ).forEach( ( el ) => {
			if ( ! hosts.includes( el ) ) {
				hosts.push( el );
			}
		} );
	} );

	return hosts;
}

/**
 * Ensure a mount node exists inside an upload host.
 *
 * @param {HTMLElement} host Upload host element.
 * @return {HTMLElement|null} Mount node.
 */
export function ensureUploadSelectMount( host ) {
	if ( ! host ) {
		return null;
	}

	let mount = host.querySelector(
		':scope > .media-manager-upload-folder-root'
	);
	if ( ! mount ) {
		mount = host.querySelector( '.media-manager-upload-folder-root' );
	}
	if ( mount ) {
		return mount;
	}

	mount = document.createElement( 'div' );
	mount.className = 'media-manager-upload-folder-root';

	const after =
		host.querySelector( '.drag-drop-buttons' ) ||
		host.querySelector( '.browser' ) ||
		host.querySelector( 'button.browser' ) ||
		host.querySelector( '#plupload-browse-button' ) ||
		null;

	if ( after ) {
		after.insertAdjacentElement( 'afterend', mount );
	} else {
		host.appendChild( mount );
	}

	return mount;
}

/**
 * Mount Choose folder selects into upload hosts and keep uploaders synced.
 *
 * @param {Object}   options          Options.
 * @param {Function} options.getProps () => { folders, value, canAssign }.
 * @param {Function} options.onChange (folderId: number|null) => void.
 * @return {Function} Teardown.
 */
export function mountUploadFolderSelects( { getProps, onChange } ) {
	const roots = new Map();
	let timer = null;
	let mutating = false;
	let lastSyncedFolderId = undefined;
	let lastRenderKey = '';

	const renderAll = () => {
		timer = null;
		if ( mutating ) {
			return;
		}

		const props = typeof getProps === 'function' ? getProps() : {};
		const { folders = [], value = null, canAssign = false } = props;
		const folderId =
			value && Number( value ) > 0 ? Number( value ) : null;
		const hosts = findUploadHosts();
		const renderKey = JSON.stringify( {
			folderId,
			canAssign,
			folderCount: Array.isArray( folders ) ? folders.length : -1,
			folderNames: ( folders || [] ).map( ( node ) => [
				node.id,
				node.name,
				( node.children || [] ).length,
			] ),
			hostCount: hosts.length,
		} );

		const folderChanged = lastSyncedFolderId !== folderId;
		const shouldRender = renderKey !== lastRenderKey;

		if ( folderChanged ) {
			lastSyncedFolderId = folderId;
			syncAllUploaders( folderId );
		}

		if ( ! shouldRender ) {
			return;
		}
		lastRenderKey = renderKey;

		if ( ! canAssign ) {
			mutating = true;
			roots.forEach( ( entry ) => {
				entry.root.unmount();
				entry.mount.remove();
			} );
			roots.clear();
			mutating = false;
			return;
		}

		mutating = true;
		try {
			const liveMounts = new Set();

			hosts.forEach( ( host ) => {
				const mount = ensureUploadSelectMount( host );
				if ( ! mount ) {
					return;
				}

				liveMounts.add( mount );

				let entry = roots.get( mount );
				if ( ! entry ) {
					entry = { root: createRoot( mount ), mount };
					roots.set( mount, entry );
				}

				entry.root.render(
					<UploadFolderSelect
						id={ `media-manager-upload-folder-${ roots.size }` }
						folders={ folders }
						value={ folderId }
						onChange={ onChange }
					/>
				);
			} );

			roots.forEach( ( entry, mount ) => {
				if ( ! liveMounts.has( mount ) ) {
					entry.root.unmount();
					entry.mount.remove();
					roots.delete( mount );
				}
			} );
		} finally {
			mutating = false;
		}
	};

	const schedule = () => {
		if ( timer ) {
			return;
		}
		timer = window.setTimeout( renderAll, 100 );
	};

	renderAll();

	const observer = new MutationObserver( () => {
		if ( mutating ) {
			return;
		}

		const needsMount = findUploadHosts().some(
			( host ) =>
				! host.querySelector( '.media-manager-upload-folder-root' )
		);
		if ( ! needsMount ) {
			return;
		}

		schedule();
	} );
	observer.observe( document.body, { childList: true, subtree: true } );

	// Only react when Media Manager upload props change, not every wp.data tick.
	let previousStoreKey = '';
	const unsubscribe = window.wp?.data?.subscribe?.( () => {
		const next = typeof getProps === 'function' ? getProps() : {};
		const key = JSON.stringify( {
			value: next.value,
			canAssign: next.canAssign,
			folderCount: Array.isArray( next.folders )
				? next.folders.length
				: -1,
			names: ( next.folders || [] ).map( ( node ) => node.name ),
		} );
		if ( key === previousStoreKey ) {
			return;
		}
		previousStoreKey = key;
		schedule();
	} );

	return () => {
		observer.disconnect();
		if ( typeof unsubscribe === 'function' ) {
			unsubscribe();
		}
		if ( timer ) {
			window.clearTimeout( timer );
		}
		mutating = true;
		roots.forEach( ( entry ) => {
			entry.root.unmount();
			entry.mount.remove();
		} );
		roots.clear();
	};
}
