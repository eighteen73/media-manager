/**
 * Media Manager REST API helpers.
 */

import apiFetch from '@wordpress/api-fetch';

const ROOT = 'media-manager/v1';

/**
 * Fetch the nested folder tree.
 *
 * @param {boolean} recursiveCounts Whether counts include descendants.
 * @return {Promise<Object>} Tree response.
 */
export async function fetchFolders(recursiveCounts = true) {
	const query = recursiveCounts
		? '?recursive_counts=true'
		: '?recursive_counts=false';
	return apiFetch({ path: `${ROOT}/folders${query}` });
}

/**
 * Create a folder.
 *
 * @param {{ name: string, parent?: number }} data Folder data.
 * @return {Promise<Object>} Created folder.
 */
export async function createFolder(data) {
	return apiFetch({
		path: `${ROOT}/folders`,
		method: 'POST',
		data: {
			name: data.name,
			parent: data.parent || 0,
		},
	});
}

/**
 * Update a folder.
 *
 * @param {number}                           id   Folder ID.
 * @param {{ name: string, parent: number }} data Folder data.
 * @return {Promise<Object>} Updated folder.
 */
export async function updateFolder(id, data) {
	return apiFetch({
		path: `${ROOT}/folders/${id}`,
		method: 'POST',
		data: {
			name: data.name,
			parent: data.parent,
		},
	});
}

/**
 * Delete a folder.
 *
 * @param {number} id Folder ID.
 * @return {Promise<Object>} Delete response.
 */
export async function deleteFolder(id) {
	return apiFetch({
		path: `${ROOT}/folders/${id}`,
		method: 'DELETE',
	});
}

/**
 * Assign attachments to a folder.
 *
 * @param {{ attachmentIds: number[], folderId: number, mode: 'add'|'move' }} data Assignment payload.
 * @return {Promise<Object>} Assignment response.
 */
export async function assignAttachments(data) {
	return apiFetch({
		path: `${ROOT}/assignments`,
		method: 'POST',
		data: {
			attachment_ids: data.attachmentIds,
			folder_id: data.folderId,
			mode: data.mode,
		},
	});
}

/**
 * Fetch current-user preferences.
 *
 * @return {Promise<Object>} Preferences.
 */
export async function fetchPreferences() {
	return apiFetch({ path: `${ROOT}/preferences` });
}

/**
 * Update current-user preferences.
 *
 * @param {Object} data Preference patch.
 * @return {Promise<Object>} Updated preferences.
 */
export async function updatePreferences(data) {
	return apiFetch({
		path: `${ROOT}/preferences`,
		method: 'POST',
		data,
	});
}
