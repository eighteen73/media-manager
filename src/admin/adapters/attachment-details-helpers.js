/**
 * Helpers for attachment details folder field mounting.
 */

/**
 * Ensure a mount node exists inside an attachment details view.
 *
 * @param {Object} view Backbone attachment details view.
 * @return {HTMLElement|null} Mount element.
 */
export function ensureAttachmentFolderMount( view ) {
	const el = view?.el || view?.$el?.get?.( 0 );
	if ( ! el || ! el.querySelector ) {
		return null;
	}

	const settings =
		el.querySelector( '.attachment-info .settings' ) ||
		el.querySelector( '.settings' ) ||
		el.querySelector( '.attachment-info' );

	let mount = el.querySelector( '.media-manager-attachment-folders-root' );
	if ( ! mount ) {
		mount = document.createElement( 'div' );
		mount.className = 'media-manager-attachment-folders-root';
	}

	// Always place after the native fields (Alt Text, Title, Caption, etc.).
	if ( settings ) {
		settings.appendChild( mount );
	} else if ( ! mount.parentNode ) {
		el.appendChild( mount );
	}

	return mount;
}

/**
 * Read folder IDs from a Backbone attachment model.
 *
 * @param {Object} model Attachment model.
 * @return {number[]} Folder IDs.
 */
export function folderIdsFromModel( model ) {
	const raw =
		model?.get?.( 'media_manager_folders' ) ??
		model?.attributes?.media_manager_folders ??
		[];
	if ( ! Array.isArray( raw ) ) {
		return [];
	}
	return raw.map( Number ).filter( ( id ) => id > 0 );
}
