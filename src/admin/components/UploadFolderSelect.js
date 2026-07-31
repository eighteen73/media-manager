/**
 * Native select for choosing an upload target folder.
 */

import { __ } from '@wordpress/i18n';
import { flattenFolderOptions } from '../utils/flattenFolderOptions';

/**
 * Upload folder select.
 *
 * @param {Object}      props          Props.
 * @param {Array}       props.folders  Folder tree nodes.
 * @param {number|null} props.value    Selected folder ID, or null for none.
 * @param {Function}    props.onChange Called with number|null.
 * @param {boolean}     props.disabled Whether the control is disabled.
 * @param {string}      props.id       Optional field id.
 * @return {JSX.Element} Select control.
 */
export default function UploadFolderSelect( {
	folders = [],
	value = null,
	onChange,
	disabled = false,
	id = 'media-manager-upload-folder',
} ) {
	const options = flattenFolderOptions( folders );
	const selectValue =
		value && Number( value ) > 0 ? String( Number( value ) ) : '';

	return (
		<div className="media-manager-upload-folder">
			<label className="screen-reader-text" htmlFor={ id }>
				{ __( 'Choose folder', 'media-manager' ) }
			</label>
			<select
				id={ id }
				className="media-manager-upload-folder__select"
				value={ selectValue }
				disabled={ disabled }
				onChange={ ( event ) => {
					const next = event.target.value;
					onChange( next === '' ? null : Number( next ) );
				} }
			>
				<option value="">
					{ __( 'Choose folder', 'media-manager' ) }
				</option>
				{ options.map( ( option ) => (
					<option key={ option.id } value={ String( option.id ) }>
						{ `${ '— '.repeat( option.depth ) }${ option.name }` }
					</option>
				) ) }
			</select>
		</div>
	);
}
