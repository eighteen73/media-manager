/**
 * Apply private-folder exclusivity to a selection of folder IDs.
 */

import { __ } from '@wordpress/i18n';

/**
 * Apply private-folder exclusivity to selected folder IDs.
 *
 * @param {number[]}                                         ids      Selected folder IDs.
 * @param {Map<number, { id: number, is_private: boolean }>} byId     Folder lookup.
 * @param {number[]}                                         previous Previous selected IDs.
 * @return {{ ids: number[], notice: string|null }} Adjusted selection.
 */
export function applyPrivateExclusivity( ids, byId, previous = [] ) {
	const unique = [];
	const seen = new Set();

	( ids || [] ).forEach( ( rawId ) => {
		const id = Number( rawId );
		if ( ! byId.has( id ) || seen.has( id ) ) {
			return;
		}
		seen.add( id );
		unique.push( id );
	} );

	const privateIds = unique.filter( ( id ) => byId.get( id )?.is_private );
	const publicIds = unique.filter( ( id ) => ! byId.get( id )?.is_private );

	const privateAddedNotice = __(
		'Private folders are exclusive. Other folders were cleared.',
		'media-manager'
	);
	const privateRemovedNotice = __(
		'Private folders are exclusive. The private folder was removed.',
		'media-manager'
	);

	const prev = ( previous || [] ).map( Number );

	if ( privateIds.length && publicIds.length ) {
		const prevHadPrivate = prev.some( ( id ) => byId.get( id )?.is_private );
		const addedPrivate = privateIds.find( ( id ) => ! prev.includes( id ) );

		if ( addedPrivate ) {
			return {
				ids: [ addedPrivate ],
				notice: privateAddedNotice,
			};
		}

		if ( prevHadPrivate ) {
			return {
				ids: publicIds,
				notice: privateRemovedNotice,
			};
		}

		return {
			ids: [ privateIds[ 0 ] ],
			notice: privateAddedNotice,
		};
	}

	if ( privateIds.length > 1 ) {
		const added =
			privateIds.find( ( id ) => ! prev.includes( id ) ) ||
			privateIds[ privateIds.length - 1 ];
		return {
			ids: [ added ],
			notice: privateAddedNotice,
		};
	}

	return { ids: unique, notice: null };
}
