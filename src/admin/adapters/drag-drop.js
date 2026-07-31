/**
 * jQuery UI drag-and-drop for assigning media to folders.
 *
 * Mirrors Rudrastyh: attachments are draggable, folder rows are droppable.
 */

import { dispatch } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import { resolveLibraryCollection } from './media-library';

const DROP_SELECTOR = '.media-manager-folder-nav[data-id]';
const DRAGGING_CLASS = 'media-manager-attachment-dragging';

/**
 * Resolve attachment IDs for a drag helper.
 *
 * @param {Object} event jQuery UI drag event.
 * @return {number[]} Attachment IDs.
 */
export function resolveDragAttachmentIds( event ) {
	const $ = window.jQuery;
	if ( ! $ ) {
		return [];
	}

	const selected = $(
		'.attachments .selected:not(.selection,:hidden)'
	);
	const ids = [];

	if ( selected.length ) {
		selected.each( function () {
			const id = Number( $( this ).data( 'id' ) );
			if ( id > 0 ) {
				ids.push( id );
			}
		} );
	} else {
		const target = $( event.currentTarget || event.target );
		const id = Number(
			target.data( 'id' ) ||
				target.attr( 'id' )?.replace( 'post-', '' ) ||
				0
		);
		if ( id > 0 ) {
			ids.push( id );
		}
	}

	return [ ...new Set( ids ) ];
}

/**
 * Build the floating drag helper element.
 *
 * @param {Object} event jQuery UI drag event.
 * @return {Object} jQuery helper.
 */
function buildDragHelper( event ) {
	const $ = window.jQuery;
	const ids = resolveDragAttachmentIds( event );
	const strings = window.mediaManagerAdmin?.strings || {};
	const label =
		ids.length > 1
			? sprintf(
					strings.moveItems ||
						/* translators: %d: number of media items */
						__( 'Move %d items', 'media-manager' ),
					ids.length
			  )
			: strings.moveItem || __( 'Move 1 item', 'media-manager' );

	return $(
		`<span data-id="${ ids.join( ',' ) }" class="${ DRAGGING_CLASS }" data-title="${ label }"></span>`
	);
}

/**
 * Bind droppable targets on folder nav rows.
 *
 * @param {Object} options Options.
 * @param {Object} options.controller Optional media frame controller.
 * @return {void}
 */
function bindFolderDroppables( { controller = null } = {} ) {
	const $ = window.jQuery;
	if ( ! $?.fn?.droppable ) {
		return;
	}

	const $targets = $( DROP_SELECTOR );
	if ( ! $targets.length ) {
		return;
	}

	$targets.each( function () {
		const $el = $( this );
		if ( $el.data( 'ui-droppable' ) ) {
			$el.droppable( 'destroy' );
		}
	} );

	$targets.droppable( {
		greedy: true,
		accept: 'li.attachment, tr',
		hoverClass: 'media-manager-folder-nav--drop',
		drop: async ( event, ui ) => {
			const folderId = Number( $( event.target ).data( 'id' ) );
			if ( Number.isNaN( folderId ) ) {
				return;
			}

			const raw =
				ui.helper?.attr?.( 'data-id' ) ||
				ui.draggable?.data?.( 'id' ) ||
				'';
			const attachmentIds = String( raw )
				.split( ',' )
				.map( ( value ) => Number( value ) )
				.filter( ( id ) => id > 0 );

			if ( ! attachmentIds.length ) {
				return;
			}

			const ok = await dispatch( STORE_NAME ).assign(
				folderId,
				'move',
				attachmentIds
			);

			if ( ! ok ) {
				return;
			}

			const selection =
				typeof controller?.state === 'function'
					? controller.state()?.get?.( 'selection' )
					: null;
			selection?.reset?.();

			const selectedFolder = window.wp?.data
				?.select?.( STORE_NAME )
				?.getSelectedFolder?.();

			// When browsing a specific folder, remove moved items from the grid.
			if ( selectedFolder !== null && selectedFolder !== folderId ) {
				attachmentIds.forEach( ( id ) => {
					$( '.attachments-wrapper' )
						.find( `li.attachment[data-id="${ id }"]` )
						.remove();
					$( `#the-list tr#post-${ id }` ).remove();
				} );
			}

			const library =
				controller?.content?.get?.()?.collection ||
				( typeof controller?.state === 'function'
					? controller.state()?.get?.( 'library' )
					: null ) ||
				resolveLibraryCollection();

			if ( library?.props?.get?.( 'query' ) ) {
				// Force a requery while keeping the active folder filter.
				library.props.trigger( 'change' );
			} else if ( library?.fetch ) {
				library.fetch( { reset: true, remove: true } );
			}
		},
	} );
}

/**
 * Make a jQuery element draggable for folder assignment.
 *
 * @param {Object} $el        jQuery element.
 * @param {Object} controller Optional media frame.
 * @return {void}
 */
function makeDraggable( $el, controller = null ) {
	const $ = window.jQuery;
	if ( ! $?.fn?.draggable || ! $el?.length ) {
		return;
	}

	if ( $el.data( 'ui-draggable' ) ) {
		return;
	}

	$el.draggable( {
		appendTo: 'body',
		helper: buildDragHelper,
		cursor: 'move',
		cursorAt: { top: 0, left: 0 },
		start() {
			const selected = $(
				'.attachments .selected:not(.selection,:hidden)'
			);
			if ( ! selected.length ) {
				$( this ).addClass( 'disabled-pointer' );
			}
			bindFolderDroppables( { controller } );
		},
		stop() {
			$( this ).removeClass( 'disabled-pointer' );
		},
	} );
}

/**
 * Enable drag/drop for Media Library grid attachments and list-table rows.
 *
 * @return {Function} Teardown function.
 */
export function enableFolderDragDrop() {
	const wp = window.wp;
	const $ = window.jQuery;
	const canAssign = !! window.mediaManagerAdmin?.capabilities?.assign;

	if ( ! canAssign || ! wp?.media?.view?.Attachment?.Library || ! $ ) {
		return () => {};
	}

	const OriginalLibrary = wp.media.view.Attachment.Library;
	let active = true;

	wp.media.view.Attachment.Library = OriginalLibrary.extend( {
		initialize() {
			OriginalLibrary.prototype.initialize.apply( this, arguments );
			this.on( 'ready', function () {
				if ( ! active ) {
					return;
				}
				if ( ! document.querySelector( '.media-manager-sidebar' ) ) {
					return;
				}
				makeDraggable( this.$el, this.controller );
				bindFolderDroppables( { controller: this.controller } );
			} );
		},
	} );

	// List view rows on upload.php.
	if ( document.getElementById( 'media-manager-folder-root' ) ) {
		$( '#the-list tr' ).each( function () {
			makeDraggable( $( this ), wp.media.frame || null );
		} );
		bindFolderDroppables( { controller: wp.media.frame || null } );
	}

	// Re-bind droppables when the React folder tree re-renders.
	let rebindTimer = null;
	const observer = new MutationObserver( () => {
		window.clearTimeout( rebindTimer );
		rebindTimer = window.setTimeout( () => {
			if ( document.querySelector( DROP_SELECTOR ) ) {
				bindFolderDroppables( {
					controller: wp.media.frame || null,
				} );
			}
		}, 50 );
	} );
	const root = document.getElementById( 'media-manager-folder-root' );
	if ( root ) {
		observer.observe( root, { childList: true, subtree: true } );
	}

	return () => {
		active = false;
		observer.disconnect();
		wp.media.view.Attachment.Library = OriginalLibrary;
		$( DROP_SELECTOR ).each( function () {
			const $el = $( this );
			if ( $el.data( 'ui-droppable' ) ) {
				$el.droppable( 'destroy' );
			}
		} );
		$( 'li.attachment.ui-draggable, #the-list tr.ui-draggable' ).each(
			function () {
				const $el = $( this );
				if ( $el.data( 'ui-draggable' ) ) {
					$el.draggable( 'destroy' );
				}
			}
		);
	};
}
