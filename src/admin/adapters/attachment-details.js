/**
 * Inject folder multi-select into wp.media attachment details views.
 */

import { createRoot } from '@wordpress/element';
import AttachmentFolderField from '../components/AttachmentFolderField';
import {
	ensureAttachmentFolderMount,
	folderIdsFromModel,
} from './attachment-details-helpers';

export { ensureAttachmentFolderMount, folderIdsFromModel };

/**
 * Patch Attachment.Details views to mount the folder field.
 *
 * @param {{ canAssign?: boolean }} options Options.
 * @return {Function} Teardown.
 */
export function observeAttachmentDetails( options = {} ) {
	const wp = window.wp;
	const boot = window.mediaManagerAdmin || {};
	const canAssign =
		typeof options.canAssign === 'boolean'
			? options.canAssign
			: !! boot.capabilities?.assign;

	if ( ! canAssign || ! wp?.media?.view?.Attachment?.Details ) {
		return () => {};
	}

	const roots = new WeakMap();
	const patched = [];

	const patchView = ( ViewClass ) => {
		if ( ! ViewClass?.prototype?.render ) {
			return;
		}

		// Already patched on this class, or inherited from a patched parent render.
		if (
			ViewClass.__mediaManagerFoldersPatched ||
			ViewClass.prototype.render.__mediaManagerFoldersPatched
		) {
			ViewClass.__mediaManagerFoldersPatched = true;
			return;
		}

		const originalRender = ViewClass.prototype.render;

		const patchedRender = function patchedRender( ...args ) {
			const result = originalRender.apply( this, args );
			mountOnView( this );
			return result;
		};
		patchedRender.__mediaManagerFoldersPatched = true;

		ViewClass.prototype.render = patchedRender;
		ViewClass.__mediaManagerFoldersPatched = true;
		patched.push( { ViewClass, originalRender } );
	};

	const mountOnView = ( view ) => {
		const model = view?.model;
		const attachmentId = Number( model?.id || model?.get?.( 'id' ) || 0 );
		if ( ! attachmentId ) {
			return;
		}

		const mount = ensureAttachmentFolderMount( view );
		if ( ! mount ) {
			return;
		}

		let entry = roots.get( view );
		if ( ! entry ) {
			entry = { reactRoot: createRoot( mount ) };
			roots.set( view, entry );

			const onRemove = () => {
				const current = roots.get( view );
				if ( current?.reactRoot ) {
					current.reactRoot.unmount();
				}
				roots.delete( view );
				view.off?.( 'remove', onRemove );
			};
			view.on?.( 'remove', onRemove );
		}

		const renderField = () => {
			const current = roots.get( view );
			if ( ! current?.reactRoot ) {
				return;
			}

			current.reactRoot.render(
				<AttachmentFolderField
					attachmentId={ attachmentId }
					folderIds={ folderIdsFromModel( model ) }
					canAssign={ canAssign }
					layout="modal"
					onFoldersChange={ ( ids ) => {
						if ( typeof model?.set === 'function' ) {
							model.set( 'media_manager_folders', ids, {
								silent: true,
							} );
						}
					} }
				/>
			);
		};

		renderField();

		if ( ! view.__mediaManagerFoldersBound ) {
			view.__mediaManagerFoldersBound = true;
			model?.on?.( 'change:media_manager_folders', renderField );
		}
	};

	patchView( wp.media.view.Attachment.Details );
	if ( wp.media.view.Attachment.Details.TwoColumn ) {
		patchView( wp.media.view.Attachment.Details.TwoColumn );
	}

	return () => {
		patched.forEach( ( { ViewClass, originalRender } ) => {
			ViewClass.prototype.render = originalRender;
			delete ViewClass.__mediaManagerFoldersPatched;
		} );
	};
}
