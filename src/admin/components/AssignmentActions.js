/**
 * Assignment action buttons.
 */

import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Add/Move assignment controls.
 *
 * @param {Object}   props                 Props.
 * @param {boolean}  props.canAssign       Whether user can assign.
 * @param {number}   props.selectedCount   Selected attachment count.
 * @param {boolean}  props.hasFolderTarget Whether a folder is selected.
 * @param {Function} props.onAdd           Add handler.
 * @param {Function} props.onMove          Move handler.
 * @param {Function} props.onClear         Clear/uncategorized move handler.
 * @return {JSX.Element|null} Actions.
 */
export default function AssignmentActions({
	canAssign,
	selectedCount,
	hasFolderTarget,
	onAdd,
	onMove,
	onClear,
}) {
	if (!canAssign) {
		return null;
	}

	return (
		<div className="media-manager-assignment-actions">
			<Button
				variant="secondary"
				disabled={!selectedCount || !hasFolderTarget}
				onClick={onAdd}
			>
				{__('Add to Folder', 'media-manager')}
			</Button>
			<Button
				variant="secondary"
				disabled={!selectedCount || !hasFolderTarget}
				onClick={onMove}
			>
				{__('Move to Folder', 'media-manager')}
			</Button>
			<Button
				variant="tertiary"
				disabled={!selectedCount}
				onClick={onClear}
			>
				{__('Move to Uncategorized', 'media-manager')}
			</Button>
			{selectedCount > 0 && (
				<span
					className="media-manager-selection-count"
					aria-live="polite"
				>
					{selectedCount === 1
						? __('1 item selected', 'media-manager')
						: `${selectedCount} ${__(
								'items selected',
								'media-manager'
							)}`}
				</span>
			)}
		</div>
	);
}
