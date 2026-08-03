/**
 * wp.media modal frame adapter.
 */

const observedFrames = new WeakSet();

/**
 * Observe media frames as they open.
 *
 * @param {{ onFrame: Function }} options Callbacks.
 * @return {Function} Teardown function.
 */
export function observeMediaFrames({ onFrame }) {
	const wp = window.wp;
	if (!wp?.media?.view?.Modal || typeof onFrame !== 'function') {
		return () => {};
	}

	const OriginalModal = wp.media.view.Modal;
	let active = true;

	wp.media.view.Modal = OriginalModal.extend({
		open() {
			const result = OriginalModal.prototype.open.apply(this, arguments);
			if (active && this?.options?.controller) {
				const frame = this.options.controller;
				if (!observedFrames.has(frame)) {
					observedFrames.add(frame);
					onFrame(frame);
				}
			}
			return result;
		},
	});

	if (wp.media.frame && !observedFrames.has(wp.media.frame)) {
		observedFrames.add(wp.media.frame);
		onFrame(wp.media.frame);
	}

	return () => {
		active = false;
		wp.media.view.Modal = OriginalModal;
	};
}

/**
 * Subscribe to attachment selection changes on a frame.
 *
 * @param {Object}   frame    Media frame.
 * @param {Function} callback Selection callback.
 * @return {Function} Unsubscribe function.
 */
export function subscribeToSelection(frame, callback) {
	if (!frame || typeof callback !== 'function') {
		return () => {};
	}

	const selection = frame.state?.get?.('selection');
	if (!selection) {
		return () => {};
	}

	const notify = () => {
		const ids = selection.map((model) => model.id);
		callback(ids);
	};

	selection.on(
		'selection:single selection:unsingle add remove reset',
		notify
	);
	notify();

	return () => {
		selection.off(
			'selection:single selection:unsingle add remove reset',
			notify
		);
	};
}

/**
 * Inject a full-height folder sidebar mount into an attachment browser.
 *
 * @param {Object} frame Media frame.
 * @return {HTMLElement|null} Mount element.
 */
/**
 * Inject a full-height folder sidebar mount into an attachment browser.
 *
 * @param {Object}      frame       Media frame.
 * @param {Object|null} browserView Optional AttachmentsBrowser from content:create.
 * @return {HTMLElement|null} Mount element.
 */
export function ensureModalFolderMount(frame, browserView = null) {
	if (!frame?.$el) {
		return null;
	}

	const frameEl = frame.$el.get?.(0) || frame.$el[0] || null;
	let browser = frame.$el.find('.attachments-browser').get(0);

	// During content:create the browser view exists but is not in the frame DOM yet.
	if (!browser && browserView) {
		if (browserView.el?.classList?.contains('attachments-browser')) {
			browser = browserView.el;
		} else if (browserView.$el?.length) {
			browser =
				browserView.$el.filter('.attachments-browser').get(0) ||
				browserView.$el.find('.attachments-browser').get(0) ||
				null;
		}
	}

	if (!browser) {
		const view = frame.content?.get?.() || null;
		if (view?.el?.classList?.contains('attachments-browser')) {
			browser = view.el;
		}
	}

	if (!browser) {
		return null;
	}

	browser.classList.add('has-media-manager-folders');
	if (frameEl) {
		frameEl.classList.add('has-media-manager-folders');
	}

	let mount = browser.querySelector('.media-manager-modal-folders');
	if (!mount) {
		mount = document.createElement('div');
		mount.className = 'media-manager-modal-folders';
		mount.setAttribute('data-media-manager-modal', 'true');
		browser.insertBefore(mount, browser.firstChild);
	}

	return mount;
}
