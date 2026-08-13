/* global HTMLInputElement */

/**
 * Media Library grid adapter.
 */

/**
 * Serialise a folder selection for query props.
 *
 * @param {number|null} folderId Folder selection.
 * @return {string|number} Query value.
 */
export function serializeFolderId(folderId) {
	if (folderId === null || typeof folderId === 'undefined') {
		return 'all';
	}
	return folderId;
}

/**
 * Resolve the active Media Library attachments collection.
 *
 * @param {Object} [frame] Optional media frame.
 * @return {Object|null} Attachments collection.
 */
export function resolveLibraryCollection(frame) {
	const mediaFrame = frame || window.wp?.media?.frame;
	if (!mediaFrame) {
		return null;
	}

	const content = mediaFrame.content?.get?.();
	if (content?.collection) {
		return content.collection;
	}

	// wp.media.frame.state is a method, not a Backbone model.
	const state =
		typeof mediaFrame.state === 'function' ? mediaFrame.state() : null;
	return state?.get?.('library') || null;
}

/**
 * Ensure the library observes wp.Uploader.queue for upload progress.
 *
 * Core Query only auto-observes when query args are in a fixed allowlist.
 * Adding `media_manager_folder` disables that, so uploads never appear in the
 * grid until a full refresh. Re-attach observation whenever we filter.
 *
 * @param {Object} collection Attachments collection.
 */
export function ensureLibraryObservesUploads(collection) {
	if (!collection || typeof collection.observe !== 'function') {
		return;
	}

	const queue = window.wp?.Uploader?.queue;
	if (!queue) {
		return;
	}

	if (collection.__mmObservingUploads) {
		return;
	}

	collection.__mmObservingUploads = true;
	collection.observe(queue);
}

/**
 * Update a media collection folder filter and refetch.
 *
 * @param {Object}      collection Backbone collection.
 * @param {number|null} folderId   Folder selection.
 */
export function setCollectionFolder(collection, folderId) {
	if (!collection || typeof collection.props?.set !== 'function') {
		return;
	}

	const value = serializeFolderId(folderId);
	const current = collection.props.get('media_manager_folder');

	if (current !== value) {
		// Query collections auto-requery when props change.
		collection.props.set({ media_manager_folder: value });

		// Non-query collections need an explicit fetch.
		if (
			!collection.props.get('query') &&
			typeof collection.fetch === 'function'
		) {
			collection.fetch({ reset: true, remove: true });
		}
	}

	// Folder query args remove core's automatic queue observation.
	ensureLibraryObservesUploads(collection);
}

/**
 * Patch Media Library "File URL" / copy-link inputs from a model.
 *
 * @param {Object} model Attachment model.
 * @return {void}
 */
function syncAttachmentUrlFields(model) {
	const url = model?.get?.('url');
	if (!url) {
		return;
	}

	const selectors = [
		'#attachment-details-two-column-copy-link',
		'#attachment-details-copy-link',
		'.attachment-details .copy-to-clipboard-container input',
		'.attachment-info .copy-to-clipboard-container input',
		'.attachment-details input[readonly]',
		'.media-sidebar input[readonly]',
		'.attachment-details input[type="text"]',
	];

	selectors.forEach((selector) => {
		document.querySelectorAll(selector).forEach((input) => {
			if (!(input instanceof HTMLInputElement)) {
				return;
			}
			const looksLikeUrl =
				input.id.includes('copy-link') ||
				input.className.includes('url') ||
				/^https?:\/\//.test(input.value) ||
				input.readOnly;
			if (looksLikeUrl) {
				input.value = url;
			}
		});
	});
}

/**
 * Force-refresh visible Media Library models so private URLs update.
 *
 * @param {Object} [frame] Optional media frame.
 * @return {void}
 */
export function refreshVisibleMedia(frame = null) {
	const mediaFrame = frame || window.wp?.media?.frame;
	let library = null;
	let selection = null;

	try {
		library = resolveLibraryCollection(mediaFrame);
	} catch (_error) {
		library = null;
	}

	try {
		const state =
			mediaFrame && typeof mediaFrame.state === 'function'
				? mediaFrame.state()
				: null;
		selection = state?.get?.('selection') || null;
	} catch (_error) {
		selection = null;
	}

	const refreshed = new Set();
	const pending = [];

	const refreshModel = (model) => {
		if (!model?.fetch) {
			return;
		}

		const id = model.get?.('id') || model.id;
		if (id && refreshed.has(id)) {
			return;
		}
		if (id) {
			refreshed.add(id);
		}

		const apply = () => {
			const url = model.get?.('url');
			if (url && typeof model.set === 'function') {
				model.set({ url, link: url });
			}
			syncAttachmentUrlFields(model);
		};

		const result = model.fetch();
		pending.push(result);
		if (result && typeof result.done === 'function') {
			result.done(apply);
		} else if (result && typeof result.then === 'function') {
			result.then(apply).catch(() => {});
		} else {
			window.setTimeout(apply, 300);
		}
	};

	selection?.each?.(refreshModel);

	const selected = document.querySelectorAll(
		'.attachment.selected, .attachment[aria-checked="true"], .attachment.details, .attachments .selected'
	);
	selected.forEach((el) => {
		const id = parseInt(el.dataset?.id || el.getAttribute('data-id'), 10);
		if (!id || !window.wp?.media?.attachment) {
			return;
		}
		refreshModel(window.wp.media.attachment(id));
	});

	const detailsId = parseInt(
		document
			.querySelector(
				'.attachment-details[data-id], .media-sidebar .attachment-details'
			)
			?.getAttribute?.('data-id') ||
			document.querySelector('.attachment.details')?.dataset?.id ||
			0,
		10
	);
	if (detailsId > 0 && window.wp?.media?.attachment) {
		refreshModel(window.wp.media.attachment(detailsId));
	}

	library?.each?.(refreshModel);

	// Avoid racing a library reset over in-flight model fetches (stale URLs win).
	const refetchLibrary = () => {
		if (library?.fetch) {
			library.fetch({ reset: true, remove: true });
		}
	};

	const jq = window.jQuery;
	if (jq && pending.length && typeof jq.when === 'function') {
		jq.when(...pending.filter(Boolean)).always(refetchLibrary);
	} else {
		window.setTimeout(refetchLibrary, 400);
	}
}

/**
 * Clamp Media Library sidebar width to the resizable range.
 *
 * @param {number} width Preferred width.
 * @return {number} Clamped width.
 */
export function clampSidebarWidth(width) {
	const value = Number(width);
	if (!Number.isFinite(value) || value <= 0) {
		return 300;
	}
	return Math.min(500, Math.max(250, value));
}

/**
 * Mount the Media Library sidebar root into #wpbody with a resize handle.
 *
 * @param {{ root: HTMLElement, store?: Object }} options Mount options.
 * @return {Function} Teardown function.
 */
export function mountMediaLibrarySidebar({ root }) {
	if (!root) {
		return () => {};
	}

	const wpBody = document.getElementById('wpbody');
	const host = wpBody || document.getElementById('wpbody-content');

	if (!host) {
		return () => {};
	}

	const preferredWidth = clampSidebarWidth(
		window.mediaManagerAdmin?.preferences?.sidebar_width
	);

	root.hidden = false;
	root.classList.add('media-manager-folder-root--mounted');
	root.style.width = `${preferredWidth}px`;
	host.classList.add('media-manager-has-folders');
	document.body.classList.add('media-manager-folders-active');

	let handler = document.getElementById('media-manager-handler');
	if (!handler) {
		handler = document.createElement('div');
		handler.id = 'media-manager-handler';
	}

	if (root.parentElement !== host) {
		host.insertBefore(root, host.firstChild);
	}
	if (handler.parentElement !== host) {
		host.insertBefore(handler, root.nextSibling);
	}

	const onMouseDown = (event) => {
		event.preventDefault();

		const startX = event.clientX;
		const startWidth = root.getBoundingClientRect().width;
		let latest = startWidth;

		const onMove = (moveEvent) => {
			latest = clampSidebarWidth(
				startWidth + (moveEvent.clientX - startX)
			);
			root.style.width = `${latest}px`;
		};

		const onUp = () => {
			document.removeEventListener('mousemove', onMove);
			document.removeEventListener('mouseup', onUp);
			if (window.wp?.data?.dispatch) {
				window.wp.data
					.dispatch('media-manager/folders')
					.savePreferences({ sidebar_width: latest });
			}
		};

		document.addEventListener('mousemove', onMove);
		document.addEventListener('mouseup', onUp);
	};

	handler.addEventListener('mousedown', onMouseDown);

	return () => {
		handler.removeEventListener('mousedown', onMouseDown);
		root.hidden = true;
		root.classList.remove('media-manager-folder-root--mounted');
		root.style.width = '';
		host.classList.remove('media-manager-has-folders');
		document.body.classList.remove('media-manager-folders-active');
		handler.remove();
	};
}

/**
 * Observe the primary Media Library frame/collection.
 *
 * @param {{ onCollection?: Function }} options Callbacks.
 * @return {Function} Teardown function.
 */
export function observeLibraryCollection({ onCollection } = {}) {
	const wp = window.wp;
	if (!wp?.media || typeof onCollection !== 'function') {
		return () => {};
	}

	let cancelled = false;
	let tries = 0;
	let boundFrame = null;

	const notify = () => {
		if (cancelled) {
			return false;
		}
		const collection = resolveLibraryCollection(wp.media.frame);
		if (collection) {
			onCollection(collection);
			return true;
		}
		return false;
	};

	const bindFrame = (frame) => {
		if (!frame || boundFrame === frame) {
			return;
		}
		if (boundFrame) {
			boundFrame.off?.('ready', notify);
			boundFrame.off?.('content:render', notify);
		}
		boundFrame = frame;
		frame.on?.('ready', notify);
		frame.on?.('content:render', notify);
		notify();
	};

	if (wp.media.frame) {
		bindFrame(wp.media.frame);
	}

	// Frame/content often mounts after our bootstrap on upload.php.
	const timer = window.setInterval(() => {
		tries += 1;
		if (wp.media.frame) {
			bindFrame(wp.media.frame);
		}
		if (notify() || tries > 40) {
			window.clearInterval(timer);
		}
	}, 250);

	return () => {
		cancelled = true;
		window.clearInterval(timer);
		boundFrame?.off?.('ready', notify);
		boundFrame?.off?.('content:render', notify);
	};
}
