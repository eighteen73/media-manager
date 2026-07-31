/**
 * Media Manager data store.
 */

import { createReduxStore, register } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import * as api from './api';

export const STORE_NAME = 'media-manager/folders';

export const DEFAULT_STATE = {
	tree: [],
	summary: {
		all_count: 0,
		uncategorized_count: 0,
	},
	selectedFolder: null,
	isLoading: false,
	isBusy: false,
	error: null,
	notice: null,
	search: '',
	sort: '',
	selectedAttachmentIds: [],
	expanded: {},
	preferences: {
		sidebar_width: 300,
		sidebar_collapsed: false,
		previous_folder: null,
	},
	requestToken: 0,
};

/**
 * Filter a folder tree by search query without destroying hierarchy.
 *
 * @param {Array}  nodes Folder nodes.
 * @param {string} query Search query.
 * @return {Array} Filtered nodes.
 */
export function filterTree(nodes, query) {
	const normalised = (query || '').trim().toLowerCase();
	if (!normalised) {
		return nodes;
	}

	const walk = (list) =>
		list
			.map((node) => {
				const children = walk(node.children || []);
				const matches = node.name.toLowerCase().includes(normalised);
				if (matches || children.length) {
					return { ...node, children };
				}
				return null;
			})
			.filter(Boolean);

	return walk(nodes);
}

/**
 * Sort a folder tree by name or count without destroying hierarchy.
 * Top-level system roots stay first (then user folders); each group uses the active sort.
 *
 * @param {Array}  nodes Folder nodes.
 * @param {string} sort  Sort key.
 * @param {number} depth Nesting depth (0 = top level).
 * @return {Array} Sorted nodes.
 */
export function sortTree(nodes, sort, depth = 0) {
	const list = (nodes || []).map((node) => ({
		...node,
		children: sortTree(node.children || [], sort, depth + 1),
	}));

	const compare = (a, b) => {
		if (sort === 'count') {
			return b.count - a.count || a.name.localeCompare(b.name) || a.id - b.id;
		}
		if (sort === 'a-z' || sort === '') {
			return a.name.localeCompare(b.name) || a.id - b.id;
		}
		return 0;
	};

	if (depth === 0) {
		const system = list.filter((node) => node.is_system_root).sort(compare);
		const user = list.filter((node) => !node.is_system_root).sort(compare);
		return [...system, ...user];
	}

	if (sort === 'count' || sort === 'a-z') {
		list.sort(compare);
	}

	return list;
}

export function reducer(state = DEFAULT_STATE, action) {
	switch (action.type) {
		case 'SET_TREE':
			return {
				...state,
				tree: action.tree,
				summary: action.summary
					? { ...state.summary, ...action.summary }
					: state.summary,
			};
		case 'SET_LOADING':
			return { ...state, isLoading: action.isLoading };
		case 'SET_BUSY':
			return { ...state, isBusy: action.isBusy };
		case 'SET_ERROR':
			return { ...state, error: action.error };
		case 'SET_NOTICE':
			return { ...state, notice: action.notice };
		case 'SET_SEARCH':
			return { ...state, search: action.search };
		case 'SET_SORT':
			return { ...state, sort: action.sort };
		case 'SET_SELECTED_FOLDER':
			return { ...state, selectedFolder: action.selectedFolder };
		case 'SET_SELECTED_ATTACHMENT_IDS':
			return {
				...state,
				selectedAttachmentIds: action.selectedAttachmentIds,
			};
		case 'SET_EXPANDED':
			return { ...state, expanded: action.expanded };
		case 'SET_PREFERENCES':
			return {
				...state,
				preferences: { ...state.preferences, ...action.preferences },
			};
		case 'BUMP_REQUEST_TOKEN':
			return { ...state, requestToken: state.requestToken + 1 };
		default:
			return state;
	}
}

const actions = {
	setTree(tree, summary = null) {
		return { type: 'SET_TREE', tree, summary };
	},
	setLoading(isLoading) {
		return { type: 'SET_LOADING', isLoading };
	},
	setBusy(isBusy) {
		return { type: 'SET_BUSY', isBusy };
	},
	setError(error) {
		return { type: 'SET_ERROR', error };
	},
	setNotice(notice) {
		return { type: 'SET_NOTICE', notice };
	},
	setSearch(search) {
		return { type: 'SET_SEARCH', search };
	},
	setSort(sort) {
		return { type: 'SET_SORT', sort };
	},
	setSelectedFolder(selectedFolder) {
		return { type: 'SET_SELECTED_FOLDER', selectedFolder };
	},
	setSelectedAttachmentIds(selectedAttachmentIds) {
		return {
			type: 'SET_SELECTED_ATTACHMENT_IDS',
			selectedAttachmentIds,
		};
	},
	setExpanded(expanded) {
		return { type: 'SET_EXPANDED', expanded };
	},
	setPreferences(preferences) {
		return { type: 'SET_PREFERENCES', preferences };
	},
	bumpRequestToken() {
		return { type: 'BUMP_REQUEST_TOKEN' };
	},
	loadFolders() {
		return async ({ dispatch, select }) => {
			dispatch.bumpRequestToken();
			const token = select.getRequestToken();
			dispatch.setLoading(true);
			dispatch.setError(null);
			try {
				const response = await api.fetchFolders(true);
				if (token === select.getRequestToken()) {
					dispatch.setTree(
						response.folders || [],
						response.summary || null
					);
				}
			} catch (error) {
				dispatch.setError(
					error?.message ||
						__('Unable to load folders.', 'media-manager')
				);
			} finally {
				dispatch.setLoading(false);
			}
		};
	},
	selectFolder(folderId) {
		return async ({ dispatch }) => {
			dispatch.setSelectedFolder(folderId);
			try {
				const preferences = await api.updatePreferences({
					previous_folder: folderId,
				});
				dispatch.setPreferences(preferences);
			} catch (_error) {
				// Preference failures are non-destructive.
			}
		};
	},
	createFolder(payload, parent = 0) {
		return async ({ dispatch }) => {
			const data =
				typeof payload === 'string'
					? { name: payload, parent }
					: { parent: 0, ...payload };
			dispatch.setBusy(true);
			dispatch.setNotice(null);
			try {
				await api.createFolder(data);
				await dispatch.loadFolders();
			} catch (error) {
				dispatch.setNotice({
					status: 'error',
					message:
						error?.message ||
						__('Unable to create folder.', 'media-manager'),
				});
				throw error;
			} finally {
				dispatch.setBusy(false);
			}
		};
	},
	updateFolder(id, payload, parent = 0) {
		return async ({ dispatch }) => {
			const data =
				typeof payload === 'string'
					? { name: payload, parent }
					: { ...payload };
			dispatch.setBusy(true);
			dispatch.setNotice(null);
			try {
				await api.updateFolder(id, data);
				await dispatch.loadFolders();
			} catch (error) {
				dispatch.setNotice({
					status: 'error',
					message:
						error?.message ||
						__('Unable to update folder.', 'media-manager'),
				});
				throw error;
			} finally {
				dispatch.setBusy(false);
			}
		};
	},
	deleteFolder(id) {
		return async ({ dispatch, select }) => {
			dispatch.setBusy(true);
			dispatch.setNotice(null);
			try {
				await api.deleteFolder(id);
				if (select.getSelectedFolder() === id) {
					dispatch.setSelectedFolder(null);
				}
				await dispatch.loadFolders();
			} catch (error) {
				dispatch.setNotice({
					status: 'error',
					message:
						error?.message ||
						__('Unable to delete folder.', 'media-manager'),
				});
				throw error;
			} finally {
				dispatch.setBusy(false);
			}
		};
	},
	downloadFolder(id) {
		return async ({ dispatch }) => {
			dispatch.setBusy(true);
			dispatch.setNotice(null);
			try {
				const { blob, filename } = await api.downloadFolder(id);
				api.triggerBlobDownload(blob, filename);
			} catch (error) {
				dispatch.setNotice({
					status: 'error',
					message:
						error?.message ||
						__('Unable to download folder.', 'media-manager'),
				});
			} finally {
				dispatch.setBusy(false);
			}
		};
	},
	assign(folderId, mode, attachmentIds = null) {
		return async ({ dispatch, select }) => {
			const ids =
				Array.isArray(attachmentIds) && attachmentIds.length
					? attachmentIds
					: select.getSelectedAttachmentIds();
			if (!ids.length) {
				dispatch.setNotice({
					status: 'error',
					message: __('Select media items first.', 'media-manager'),
				});
				return false;
			}

			const folder = findFolderNode(select.getTree(), folderId);
			const boot = window.mediaManagerAdmin || {};
			let confirm = false;

			if (folder?.is_private) {
				const ok = window.confirm(
					boot.strings?.privateConfirmEnter ||
						__(
							'Moving into a private folder replaces public relationships. Continue?',
							'media-manager'
						)
				);
				if (!ok) {
					return false;
				}
				confirm = true;
				mode = 'move';
			}

			dispatch.setBusy(true);
			dispatch.setNotice(null);
			try {
				await api.assignAttachments({
					attachmentIds: ids,
					folderId,
					mode,
					confirm,
				});
				dispatch.setSelectedAttachmentIds([]);
				await dispatch.loadFolders();
				return true;
			} catch (error) {
				const code = error?.code || error?.data?.code;
				if (code === 'media_manager_confirmation_required') {
					const needsEnter = !!error?.data?.needs_enter;
					const message = needsEnter
						? boot.strings?.privateConfirmEnter || error.message
						: boot.strings?.privateConfirmLeave || error.message;
					const ok = window.confirm(message);
					if (ok) {
						try {
							await api.assignAttachments({
								attachmentIds: ids,
								folderId,
								mode: needsEnter ? 'move' : mode,
								confirm: true,
							});
							dispatch.setSelectedAttachmentIds([]);
							await dispatch.loadFolders();
							return true;
						} catch (retryError) {
							dispatch.setNotice({
								status: 'error',
								message:
									retryError?.message ||
									__(
										'Unable to assign media to folder.',
										'media-manager'
									),
							});
							return false;
						}
					}
					return false;
				}

				dispatch.setNotice({
					status: 'error',
					message:
						error?.message ||
						__(
							'Unable to assign media to folder.',
							'media-manager'
						),
				});
				return false;
			} finally {
				dispatch.setBusy(false);
			}
		};
	},
	savePreferences(data) {
		return async ({ dispatch }) => {
			try {
				const preferences = await api.updatePreferences(data);
				dispatch.setPreferences(preferences);
			} catch (error) {
				dispatch.setNotice({
					status: 'error',
					message:
						error?.message ||
						__('Unable to save preferences.', 'media-manager'),
				});
			}
		};
	},
};

const selectors = {
	getTree(state) {
		return state.tree;
	},
	getSummary(state) {
		return state.summary;
	},
	getSelectedFolder(state) {
		return state.selectedFolder;
	},
	isLoading(state) {
		return state.isLoading;
	},
	isBusy(state) {
		return state.isBusy;
	},
	getError(state) {
		return state.error;
	},
	getNotice(state) {
		return state.notice;
	},
	getSearch(state) {
		return state.search;
	},
	getSort(state) {
		return state.sort;
	},
	getSelectedAttachmentIds(state) {
		return state.selectedAttachmentIds;
	},
	getExpanded(state) {
		return state.expanded;
	},
	getPreferences(state) {
		return state.preferences;
	},
	getRequestToken(state) {
		return state.requestToken;
	},
	getFilteredTree(state) {
		const filtered = filterTree(state.tree, state.search);
		return sortTree(filtered, state.sort);
	},
};

export const store = createReduxStore(STORE_NAME, {
	reducer,
	actions,
	selectors,
});

let registered = false;

/**
 * Register the store once.
 */
export function registerStore() {
	if (registered) {
		return;
	}
	register(store);
	registered = true;
}

export { actions, selectors };

/**
 * Find a folder node by ID.
 *
 * @param {Array}  nodes Folder tree.
 * @param {number} id    Folder ID.
 * @return {Object|null} Folder node.
 */
function findFolderNode(nodes, id) {
	for (const node of nodes || []) {
		if (node.id === id) {
			return node;
		}
		const child = findFolderNode(node.children || [], id);
		if (child) {
			return child;
		}
	}
	return null;
}
