/**
 * Folder CRUD dialog (Name + Parent).
 */

import { useState, useEffect, createPortal } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Flatten folder tree for parent select options.
 *
 * @param {Array}  nodes   Folder nodes.
 * @param {number} depth   Depth.
 * @param {number} exclude Excluded folder ID.
 * @return {Array} Options.
 */
function flattenOptions( nodes, depth = 0, exclude = 0 ) {
	const options = [];

	( nodes || [] ).forEach( ( node ) => {
		if ( node.id === exclude ) {
			return;
		}
		options.push( {
			id: node.id,
			label: `${ '— '.repeat( depth ) }${ node.name }`,
		} );
		options.push(
			...flattenOptions( node.children || [], depth + 1, exclude )
		);
	} );

	return options;
}

/**
 * Create/rename folder dialog.
 *
 * @param {Object}   props               Props.
 * @param {boolean}  props.isOpen        Whether open.
 * @param {string}   props.title         Dialog title.
 * @param {string}   props.submitLabel   Submit button label.
 * @param {string}   props.initialName   Initial name.
 * @param {number}   props.initialParent Initial parent.
 * @param {Array}    props.folders       Folder tree for parent select.
 * @param {number}   props.excludeId     Folder ID to exclude from parents.
 * @param {Function} props.onClose       Close handler.
 * @param {Function} props.onSubmit      Submit handler.
 * @return {JSX.Element|null} Dialog.
 */
export default function FolderDialog( {
	isOpen,
	title,
	submitLabel,
	initialName = '',
	initialParent = 0,
	folders = [],
	excludeId = 0,
	onClose,
	onSubmit,
} ) {
	const [ name, setName ] = useState( initialName );
	const [ parent, setParent ] = useState( initialParent );
	const [ isBusy, setIsBusy ] = useState( false );

	useEffect( () => {
		if ( isOpen ) {
			setName( initialName );
			setParent( initialParent );
			setIsBusy( false );
		}
	}, [ isOpen, initialName, initialParent ] );

	if ( ! isOpen ) {
		return null;
	}

	const submit = async ( event ) => {
		event.preventDefault();
		if ( ! name.trim() ) {
			return;
		}
		setIsBusy( true );
		try {
			await onSubmit( name.trim(), Number( parent ) || 0 );
			onClose();
		} catch ( _error ) {
			setIsBusy( false );
		}
	};

	const options = flattenOptions( folders, 0, excludeId );

	return createPortal(
		<>
			<div className="media-manager-modal" role="dialog" aria-modal="true">
				<div className="media-manager-modal__topbar">
					<h1>{ title }</h1>
					<button
						type="button"
						className="media-manager-modal__close dashicons dashicons-no-alt"
						onClick={ onClose }
					>
						<span className="screen-reader-text">
							{ __( 'Close modal panel', 'media-manager' ) }
						</span>
					</button>
				</div>
				<form onSubmit={ submit }>
					<div className="media-manager-modal__body">
						<label htmlFor="media-manager-folder-name">
							{ __( 'Name', 'media-manager' ) }
						</label>
						<fieldset>
							<input
								id="media-manager-folder-name"
								className="input-text regular-input"
								type="text"
								value={ name }
								onChange={ ( event ) =>
									setName( event.target.value )
								}
								required
							/>
						</fieldset>
						<label htmlFor="media-manager-folder-parent">
							{ __( 'Parent Folder', 'media-manager' ) }
						</label>
						<fieldset>
							<select
								id="media-manager-folder-parent"
								value={ parent }
								onChange={ ( event ) =>
									setParent( event.target.value )
								}
							>
								<option value="0">
									{ __( 'None', 'media-manager' ) }
								</option>
								{ options.map( ( option ) => (
									<option
										key={ option.id }
										value={ option.id }
									>
										{ option.label }
									</option>
								) ) }
							</select>
						</fieldset>
					</div>
					<div className="media-manager-modal__bottombar">
						<button
							type="submit"
							className="button button-primary button-large"
							disabled={ isBusy || ! name.trim() }
						>
							{ submitLabel }
						</button>
					</div>
				</form>
			</div>
			<button
				type="button"
				className="media-manager-modal-backdrop"
				aria-label={ __( 'Close modal panel', 'media-manager' ) }
				onClick={ onClose }
			/>
		</>,
		document.body
	);
}
