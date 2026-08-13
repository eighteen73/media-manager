/**
 * Folder sidebar root component.
 */

import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useMemo, useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store';
import FolderToolbar from './FolderToolbar';
import FolderTree from './FolderTree';
import FolderDialog from './FolderDialog';
import {
	setCollectionFolder,
	resolveLibraryCollection,
	refreshVisibleMedia,
	clampSidebarWidth,
} from '../adapters/media-library';
import { syncFrameUploader } from '../adapters/uploader';

/**
 * Resolve boot config from localized script data.
 *
 * @return {Object} Boot config.
 */
function getBoot() {
	return (
		window.mediaManagerAdmin || {
			settings: {
				show_count: true,
				show_search: true,
				show_modal_sidebar: true,
			},
			capabilities: {
				view: true,
				edit: false,
				delete: false,
				assign: false,
			},
			preferences: {},
			defaultFolder: null,
		}
	);
}

/**
 * Public folder sidebar.
 *
 * @param {Object}   props                Props.
 * @param {Object}   props.collection     Optional media collection.
 * @param {Object}   props.frame          Optional media frame.
 * @param {boolean}  props.compact        Compact modal mode.
 * @param {Function} props.onFolderChange Folder change callback.
 * @return {JSX.Element} Sidebar.
 */
export default function FolderSidebar({
	collection = null,
	frame = null,
	compact = false,
	onFolderChange = null,
}) {
	const boot = useMemo(() => getBoot(), []);
	const [dialog, setDialog] = useState(null);
	const {
		tree,
		fullTree,
		summary,
		selectedFolder,
		isLoading,
		isBusy,
		error,
		notice,
		search,
		sort,
		selectedAttachmentIds,
		expanded,
		preferences,
	} = useSelect(
		(select) => ({
			tree: select(STORE_NAME).getFilteredTree(),
			fullTree: select(STORE_NAME).getTree(),
			summary: select(STORE_NAME).getSummary(),
			selectedFolder: select(STORE_NAME).getSelectedFolder(),
			isLoading: select(STORE_NAME).isLoading(),
			isBusy: select(STORE_NAME).isBusy(),
			error: select(STORE_NAME).getError(),
			notice: select(STORE_NAME).getNotice(),
			search: select(STORE_NAME).getSearch(),
			sort: select(STORE_NAME).getSort(),
			selectedAttachmentIds:
				select(STORE_NAME).getSelectedAttachmentIds(),
			expanded: select(STORE_NAME).getExpanded(),
			preferences: select(STORE_NAME).getPreferences(),
		}),
		[]
	);

	const {
		loadFolders,
		selectFolder,
		setSearch,
		setSort,
		setExpanded,
		setNotice,
		setPreferences,
		createFolder,
		updateFolder,
		downloadFolder,
		deleteFolder,
		assign,
	} = useDispatch(STORE_NAME);

	useEffect(() => {
		if (boot.preferences) {
			setPreferences(boot.preferences);
		}
		// In the media modal, start browsing on All files. The post-generated
		// folder remains the upload destination via Choose folder / uploader sync,
		// so an empty content folder does not hide the library grid.
		const initial = compact
			? null
			: boot.generatedFolderId ??
				boot.preferences?.previous_folder ??
				boot.defaultFolder ??
				null;
		selectFolder(initial);
		loadFolders();
	}, []);

	useEffect(() => {
		const mediaFrame = frame || window.wp?.media?.frame;
		const library =
			resolveLibraryCollection(mediaFrame) || collection;
		if (library) {
			setCollectionFolder(library, selectedFolder);
		}
		if (mediaFrame) {
			const uploadFolder =
				selectedFolder && Number(selectedFolder) > 0
					? selectedFolder
					: boot.generatedFolderId &&
						  Number(boot.generatedFolderId) > 0
						? Number(boot.generatedFolderId)
						: selectedFolder;
			syncFrameUploader(mediaFrame, uploadFolder);
		}
		if (typeof onFolderChange === 'function') {
			onFolderChange(selectedFolder);
		}
	}, [selectedFolder, collection, frame, onFolderChange]);

	const refreshCollections = useCallback(() => {
		const mediaFrame = frame || window.wp?.media?.frame;

		// URL refresh is the critical path — never let collection fetches abort it.
		try {
			refreshVisibleMedia(mediaFrame);
		} catch ( _error ) {
			// Refresh must never block folder save / dialog close.
		}

		try {
			if (collection?.fetch) {
				collection.fetch({ reset: true });
			}
		} catch ( _error ) {
			// Non-critical grid refresh failure.
		}
	}, [collection, frame]);

	useEffect(() => {
		const onKeyDown = (event) => {
			if (!boot.capabilities.assign || !selectedAttachmentIds.length) {
				return;
			}
			if (
				event.altKey &&
				event.key.toLowerCase() === 'a' &&
				selectedFolder > 0
			) {
				event.preventDefault();
				assign(selectedFolder, 'add').then(refreshCollections);
			}
			if (event.altKey && event.key.toLowerCase() === 'm') {
				event.preventDefault();
				const target = selectedFolder > 0 ? selectedFolder : 0;
				assign(target, 'move').then(refreshCollections);
			}
		};
		window.addEventListener('keydown', onKeyDown);
		return () => window.removeEventListener('keydown', onKeyDown);
	}, [
		selectedAttachmentIds,
		selectedFolder,
		assign,
		boot.capabilities.assign,
		refreshCollections,
	]);

	const onToggle = (id) => {
		setExpanded({ ...expanded, [id]: !expanded[id] });
	};

	const selectedFolderNode = useMemo(
		() => findFolder(fullTree, selectedFolder),
		[fullTree, selectedFolder]
	);
	const folderMutationLocked = !!(
		selectedFolderNode?.is_system_root ||
		(selectedFolderNode?.is_generated && !selectedFolderNode?.is_orphaned)
	);

	const width = clampSidebarWidth(preferences.sidebar_width || 300);

	useEffect(() => {
		if (compact) {
			return undefined;
		}

		const root = document.getElementById('media-manager-folder-root');
		if (!root) {
			return undefined;
		}

		root.style.width = `${width}px`;
		return undefined;
	}, [compact, width]);

	return (
		<aside
			className={
				compact
					? 'media-manager-sidebar is-compact'
					: 'media-manager-sidebar'
			}
			aria-label={__('Media folders', 'media-manager')}
			data-width={width}
		>
			<FolderToolbar
				capabilities={boot.capabilities}
				sort={sort}
				onSort={setSort}
				onCreate={() =>
					setDialog({
						type: 'create',
						title: __('Add Folder', 'media-manager'),
						submitLabel: __('Create folder', 'media-manager'),
						initialName: '',
						initialParent:
							selectedFolder && selectedFolder > 0
								? selectedFolder
								: 0,
					})
				}
				onRename={() => {
					const folder = findFolder(fullTree, selectedFolder);
					if (!folder) {
						return;
					}
					setDialog({
						type: 'rename',
						title: __('Edit Folder', 'media-manager'),
						submitLabel: __('Save Folder', 'media-manager'),
						initialName: folder.name,
						initialParent: folder.parent,
						initialAccessMode: folder.access_mode || 'unrestricted',
						initialAllowedRoles: folder.allowed_roles || [],
						initialAllowedUsernames: folder.allowed_usernames || [],
						initialIsPrivate: !!folder.is_private,
						lockStructure: !!(
							folder.is_system_root ||
							(folder.is_generated && !folder.is_orphaned)
						),
						id: folder.id,
					});
				}}
				onDownload={async () => {
					if (
						!selectedFolder ||
						selectedFolder < 1 ||
						!(selectedFolderNode?.count > 0)
					) {
						return;
					}
					await downloadFolder(selectedFolder);
				}}
				onDelete={async () => {
					if (!selectedFolder || selectedFolder < 1 || folderMutationLocked) {
						return;
					}
					const confirmed = window.confirm(
						__(
							"You are about to permanently delete this folder from your site.\nThis action cannot be undone.\n'Cancel' to stop, 'OK' to delete.",
							'media-manager'
						)
					);
					if (confirmed) {
						await deleteFolder(selectedFolder);
					}
				}}
				hasSelection={selectedFolder > 0}
				canDownload={(selectedFolderNode?.count || 0) > 0}
				canDelete={!folderMutationLocked}
			/>

			{error && (
				<div className="notice notice-error inline">
					<p>{error}</p>
				</div>
			)}

			{notice?.status === 'error' && (
				<div className="notice notice-error inline is-dismissible">
					<p>{notice.message}</p>
					<button
						type="button"
						className="notice-dismiss"
						onClick={() => setNotice(null)}
					>
						<span className="screen-reader-text">
							{__('Dismiss this notice.', 'media-manager')}
						</span>
					</button>
				</div>
			)}

			<div className="media-manager-sidebar__body">
				{(isBusy || isLoading) && (
					<div
						className="media-manager-sidebar__overlay"
						aria-busy="true"
						aria-live="polite"
					>
						<span className="spinner is-active" />
						<span className="screen-reader-text">
							{__('Updating folders…', 'media-manager')}
						</span>
					</div>
				)}
				<FolderTree
					nodes={tree}
					summary={summary}
					selectedFolder={selectedFolder}
					expanded={expanded}
					showCount={!!boot.settings.show_count}
					showSearch={!!boot.settings.show_search}
					search={search}
					onSearch={setSearch}
					onSelect={selectFolder}
					onToggle={onToggle}
				/>
			</div>

			{dialog && (
				<FolderDialog
					key={`${dialog.type}-${dialog.id ?? 'new'}`}
					title={dialog.title || ''}
					submitLabel={
						dialog.submitLabel || __('Save', 'media-manager')
					}
					initialName={dialog.initialName || ''}
					initialParent={dialog.initialParent || 0}
					initialAccessMode={
						dialog.initialAccessMode || 'unrestricted'
					}
					initialAllowedRoles={dialog.initialAllowedRoles || []}
					initialAllowedUsernames={
						dialog.initialAllowedUsernames || []
					}
					initialIsPrivate={!!dialog.initialIsPrivate}
					lockStructure={!!dialog.lockStructure}
					folders={fullTree}
					excludeId={dialog.id || 0}
					onClose={() => setDialog(null)}
					onSubmit={async (payload) => {
						if (dialog.type === 'create') {
							await createFolder(payload);
							return;
						}
						if (dialog.type === 'rename') {
							await updateFolder(dialog.id, payload);
							// Defer refresh so a media refresh failure cannot keep the modal open.
							// A short delay lets attachment meta/URL filters settle after the move.
							window.setTimeout(() => {
								refreshCollections();
							}, 150);
						}
					}}
				/>
			)}
		</aside>
	);
}

/**
 * Find a folder node by ID.
 *
 * @param {Array}  nodes Folder tree.
 * @param {number} id    Folder ID.
 * @return {Object|null} Folder node.
 */
function findFolder(nodes, id) {
	for (const node of nodes || []) {
		if (node.id === id) {
			return node;
		}
		const child = findFolder(node.children || [], id);
		if (child) {
			return child;
		}
	}
	return null;
}
