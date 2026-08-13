/**
 * Read-only folder sidebar for the Shared media tab.
 */

import { useEffect, useState, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchSharedFolders } from '../api';
import FolderTree from './FolderTree';
import {
	setCollectionFolder,
	resolveLibraryCollection,
} from '../adapters/media-library';

/**
 * Shared media folder sidebar (browse-only).
 *
 * @param {Object} props            Props.
 * @param {Object} props.collection Media collection.
 * @param {Object} props.frame      Media frame.
 * @return {JSX.Element} Sidebar.
 */
export default function SharedFolderSidebar( {
	collection = null,
	frame = null,
} ) {
	const boot = window.mediaManagerAdmin || {};
	const [ nodes, setNodes ] = useState( [] );
	const [ summary, setSummary ] = useState( null );
	const [ selectedFolder, setSelectedFolder ] = useState( null );
	const [ search, setSearch ] = useState( '' );
	const [ expanded, setExpanded ] = useState( {} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let cancelled = false;
		setIsLoading( true );
		fetchSharedFolders( true )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				setNodes( response.folders || [] );
				setSummary( response.summary || null );
				setError( null );
			} )
			.catch( ( err ) => {
				if ( cancelled ) {
					return;
				}
				setError(
					err?.message ||
						__( 'Unable to load shared folders.', 'media-manager' )
				);
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		const mediaFrame = frame || window.wp?.media?.frame;
		const library = resolveLibraryCollection( mediaFrame ) || collection;
		if ( library ) {
			setCollectionFolder( library, selectedFolder );
		}
	}, [ selectedFolder, collection, frame ] );

	const onToggle = useCallback( ( id ) => {
		setExpanded( ( prev ) => ( { ...prev, [ id ]: ! prev[ id ] } ) );
	}, [] );

	return (
		<aside
			className="media-manager-sidebar is-compact is-shared"
			aria-label={ __( 'Shared media folders', 'media-manager' ) }
		>
			<div className="media-manager-sidebar__header">
				<strong>
					{ boot.sharedMedia?.tabTitle ||
						__( 'Shared media', 'media-manager' ) }
				</strong>
			</div>

			{ error && (
				<div className="notice notice-error inline">
					<p>{ error }</p>
				</div>
			) }

			<div className="media-manager-sidebar__body">
				{ isLoading && (
					<div
						className="media-manager-sidebar__overlay"
						aria-busy="true"
						aria-live="polite"
					>
						<span className="spinner is-active" />
						<span className="screen-reader-text">
							{ __( 'Loading folders…', 'media-manager' ) }
						</span>
					</div>
				) }
				{ ! isLoading && ! error && (
					<FolderTree
						nodes={ nodes }
						summary={ summary }
						selectedFolder={ selectedFolder }
						expanded={ expanded }
						showCount={ !! boot.settings?.show_count }
						showSearch={ !! boot.settings?.show_search }
						search={ search }
						onSearch={ setSearch }
						onSelect={ setSelectedFolder }
						onToggle={ onToggle }
					/>
				) }
			</div>
		</aside>
	);
}
