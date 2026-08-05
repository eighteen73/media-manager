/**
 * Assign-only hierarchical folder checklist for attachment details / edit screens.
 */

import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { assignAttachments } from '../api';
import { STORE_NAME } from '../store';
import { flattenFolderOptions } from '../utils/flattenFolderOptions';
import { applyPrivateExclusivity } from '../utils/applyPrivateExclusivity';
import { assignableFolderTree } from '../utils/assignableFolderTree';

/**
 * Normalize folder ID lists for comparison.
 *
 * @param {number[]} ids Folder IDs.
 * @return {string} Stable key.
 */
function folderIdsKey( ids ) {
	return [ ...( ids || [] ) ]
		.map( Number )
		.filter( ( id ) => id > 0 )
		.sort( ( a, b ) => a - b )
		.join( ',' );
}

/**
 * Persist a folder set with private enter/leave confirmation.
 *
 * @param {number}   attachmentId Attachment ID.
 * @param {number[]} folderIds    Folder IDs to set.
 * @return {Promise<boolean>} Whether the save succeeded.
 */
async function saveFolderSet( attachmentId, folderIds ) {
	const boot = window.mediaManagerAdmin || {};
	const ids = ( folderIds || [] ).map( Number ).filter( ( id ) => id > 0 );

	try {
		await assignAttachments( {
			attachmentIds: [ attachmentId ],
			folderIds: ids,
			mode: 'set',
			confirm: false,
		} );
		return true;
	} catch ( error ) {
		const code = error?.code || error?.data?.code;
		if ( code !== 'media_manager_confirmation_required' ) {
			throw error;
		}

		const needsEnter = !! error?.data?.needs_enter;
		const message = needsEnter
			? boot.strings?.privateConfirmEnter || error.message
			: boot.strings?.privateConfirmLeave || error.message;
		const ok = window.confirm( message );
		if ( ! ok ) {
			return false;
		}

		await assignAttachments( {
			attachmentIds: [ attachmentId ],
			folderIds: ids,
			mode: 'set',
			confirm: true,
		} );
		return true;
	}
}

/**
 * Recursive checklist items (returns <li> nodes).
 *
 * @param {Object}   props            Props.
 * @param {Array}    props.nodes      Folder nodes.
 * @param {Set}      props.selected   Selected folder IDs.
 * @param {Function} props.onToggle   Toggle handler.
 * @param {boolean}  props.disabled   Disabled state.
 * @param {string}   props.inputName  Shared input name.
 * @return {JSX.Element[]} List items.
 */
function FolderChecklistItems( {
	nodes,
	selected,
	onToggle,
	disabled,
	inputName,
} ) {
	return ( nodes || [] ).map( ( node ) => {
		const id = Number( node.id );
		const inputId = `${ inputName }-${ id }`;
		return (
			<li key={ id } id={ `media-manager-folder-${ id }` }>
				<label className="selectit" htmlFor={ inputId }>
					<input
						type="checkbox"
						id={ inputId }
						name={ `${ inputName }[]` }
						value={ id }
						checked={ selected.has( id ) }
						disabled={ disabled }
						onChange={ () => onToggle( id ) }
					/>
					<span className="media-manager-folder-checklist__label">
						{ node.name }
						{ !! node.is_private && (
							<span className="media-manager-folder-checklist__private">
								{ ' (' }
								{ __( 'private', 'media-manager' ) }
								{ ')' }
							</span>
						) }
					</span>
				</label>
				{ !! node.children?.length && (
					<ul className="children">
						<FolderChecklistItems
							nodes={ node.children }
							selected={ selected }
							onToggle={ onToggle }
							disabled={ disabled }
							inputName={ inputName }
						/>
					</ul>
				) }
			</li>
		);
	} );
}

/**
 * Attachment folder field.
 *
 * @param {Object}   props                 Props.
 * @param {number}   props.attachmentId    Attachment ID.
 * @param {number[]} props.folderIds       Current folder IDs.
 * @param {boolean}  props.canAssign       Whether the user can assign folders.
 * @param {Function} props.onFoldersChange Called after a successful save with new IDs.
 * @param {string}   props.label           Optional field label.
 * @param {'modal'|'metabox'} props.layout Layout variant.
 * @return {JSX.Element} Field.
 */
export default function AttachmentFolderField( {
	attachmentId,
	folderIds = [],
	canAssign = false,
	onFoldersChange,
	label = __( 'Folders', 'media-manager' ),
	layout = 'modal',
} ) {
	const boot = window.mediaManagerAdmin || {};
	const { loadFolders } = useDispatch( STORE_NAME );
	const tree = useSelect( ( select ) => select( STORE_NAME ).getTree(), [] );
	const isLoading = useSelect(
		( select ) => select( STORE_NAME ).isLoading(),
		[]
	);

	const assignableTree = useMemo(
		() => assignableFolderTree( tree ),
		[ tree ]
	);
	const options = useMemo( () => flattenFolderOptions( tree ), [ tree ] );
	const byId = useMemo( () => {
		const map = new Map();
		options.forEach( ( option ) => {
			map.set( option.id, option );
		} );
		return map;
	}, [ options ] );

	const folderKey = folderIdsKey( folderIds );
	const [ selectedIds, setSelectedIds ] = useState( () =>
		[ ...( folderIds || [] ) ].map( Number ).filter( ( id ) => id > 0 )
	);
	const [ notice, setNotice ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isSaving, setIsSaving ] = useState( false );
	const savedKeyRef = useRef( folderKey );
	const savedIdsRef = useRef( [ ...( folderIds || [] ) ].map( Number ) );
	const selectedRef = useRef( selectedIds );
	const attachmentRef = useRef( attachmentId );
	selectedRef.current = selectedIds;

	const selectedSet = useMemo(
		() => new Set( selectedIds.map( Number ) ),
		[ selectedIds ]
	);

	const inputName = `media_manager_folder_${ attachmentId || 0 }`;

	useEffect( () => {
		if ( ! tree.length && ! isLoading ) {
			loadFolders();
		}
	}, [ tree.length, isLoading, loadFolders ] );

	useEffect( () => {
		const attachmentChanged = attachmentRef.current !== attachmentId;
		attachmentRef.current = attachmentId;

		if ( attachmentChanged || folderKey !== savedKeyRef.current ) {
			savedKeyRef.current = folderKey;
			savedIdsRef.current = [ ...( folderIds || [] ) ]
				.map( Number )
				.filter( ( id ) => id > 0 );
			setNotice( null );
			setError( null );
		}

		setSelectedIds( [ ...savedIdsRef.current ] );
	}, [ attachmentId, folderKey ] );

	const persist = async ( nextIds ) => {
		const ids = nextIds.map( Number ).filter( ( id ) => id > 0 );
		const nextKey = folderIdsKey( ids );

		if ( nextKey === savedKeyRef.current ) {
			return;
		}

		if ( ! attachmentId || ! canAssign ) {
			return;
		}

		setIsSaving( true );
		setError( null );

		try {
			const ok = await saveFolderSet( attachmentId, ids );
			if ( ! ok ) {
				setSelectedIds( [ ...savedIdsRef.current ] );
				return;
			}
			savedKeyRef.current = nextKey;
			savedIdsRef.current = ids;
			onFoldersChange?.( ids );
			loadFolders();
		} catch ( err ) {
			setError(
				err?.message ||
					__( 'Unable to update folders.', 'media-manager' )
			);
			setSelectedIds( [ ...savedIdsRef.current ] );
		} finally {
			setIsSaving( false );
		}
	};

	const onToggle = ( folderId ) => {
		const id = Number( folderId );
		const current = selectedRef.current.map( Number );
		const next = current.includes( id )
			? current.filter( ( value ) => value !== id )
			: [ ...current, id ];

		const adjusted = applyPrivateExclusivity( next, byId, current );
		setSelectedIds( adjusted.ids );
		setNotice( adjusted.notice );
		persist( adjusted.ids );
	};

	if ( ! canAssign && ! boot.capabilities?.assign ) {
		return null;
	}

	const assignable = canAssign || !! boot.capabilities?.assign;
	const disabled = ! assignable || isSaving || isLoading;

	let panelContent = null;
	if ( isLoading && ! assignableTree.length ) {
		panelContent = (
			<p className="description">
				{ __( 'Loading folders…', 'media-manager' ) }
			</p>
		);
	} else if ( assignableTree.length ) {
		panelContent = (
			<ul className="categorychecklist checklist">
				<FolderChecklistItems
					nodes={ assignableTree }
					selected={ selectedSet }
					onToggle={ onToggle }
					disabled={ disabled }
					inputName={ inputName }
				/>
			</ul>
		);
	} else {
		panelContent = (
			<p className="description">
				{ __( 'No folders yet.', 'media-manager' ) }
			</p>
		);
	}

	const statusMessages = (
		<>
			{ isSaving && (
				<p className="description media-manager-attachment-folders__status">
					{ __( 'Saving…', 'media-manager' ) }
				</p>
			) }
			{ notice && (
				<p className="media-manager-attachment-folders__notice description">
					{ notice }
				</p>
			) }
			{ error && (
				<p className="media-manager-attachment-folders__error">
					{ error }
				</p>
			) }
		</>
	);

	if ( layout === 'metabox' ) {
		return (
			<div className="media-manager-attachment-folders media-manager-attachment-folders--metabox">
				<div className="tabs-panel">
					{ panelContent }
				</div>
				{ statusMessages }
			</div>
		);
	}

	return (
		<div className="setting media-manager-attachment-folders media-manager-attachment-folders--modal">
			<span className="name">{ label }</span>
			<div className="media-manager-folder-checklist-wrap">
				<div className="media-manager-folder-checklist">
					{ panelContent }
				</div>
				{ statusMessages }
			</div>
		</div>
	);
}
