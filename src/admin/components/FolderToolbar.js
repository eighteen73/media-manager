/**
 * Folder sidebar header and toolbar (Rudrastyh-style).
 */

import { __ } from '@wordpress/i18n';

/**
 * Toolbar for folder CRUD and sorting.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.capabilities Capability flags.
 * @param {string}   props.sort         Sort value.
 * @param {Function} props.onSort       Sort handler.
 * @param {Function} props.onCreate     Create handler.
 * @param {Function} props.onRename     Rename handler.
 * @param {Function} props.onDelete     Delete handler.
 * @param {boolean}  props.hasSelection Whether a folder is selected.
 * @return {JSX.Element} Toolbar.
 */
export default function FolderToolbar( {
	capabilities,
	sort,
	onSort,
	onCreate,
	onRename,
	onDelete,
	hasSelection,
} ) {
	const actionsClass = hasSelection ? '' : ' is-disabled';

	return (
		<>
			<div className="media-manager-sidebar__header">
				<h1 className="wp-heading-inline">
					{ __( 'Folders', 'media-manager' ) }
				</h1>
				{ capabilities.edit && (
					<button
						type="button"
						className="button media-manager-add-folder"
						onClick={ onCreate }
					>
						{ __( 'Add Folder', 'media-manager' ) }
					</button>
				) }
			</div>
			<div className="media-manager-sidebar__toolbar">
				<div className="media-manager-folder-actions">
					{ capabilities.edit && (
						<button
							type="button"
							className={ `media-manager-edit-folder${ actionsClass }` }
							onClick={ onRename }
							disabled={ ! hasSelection }
							aria-label={ __( 'Edit folder', 'media-manager' ) }
						/>
					) }
					{ capabilities.delete && (
						<button
							type="button"
							className={ `media-manager-delete-folder${ actionsClass }` }
							onClick={ onDelete }
							disabled={ ! hasSelection }
							aria-label={ __( 'Delete folder', 'media-manager' ) }
						/>
					) }
				</div>
				<select
					id="media-manager-sort-folders"
					value={ sort || '' }
					onChange={ ( event ) => onSort( event.target.value ) }
				>
					<option value="">
						{ __( 'Sort by', 'media-manager' ) }
					</option>
					<option value="a-z">A → Z</option>
					<option value="count">
						{ __( 'Media count', 'media-manager' ) }
					</option>
				</select>
			</div>
		</>
	);
}
