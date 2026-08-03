/**
 * Shared media tab for wp.media frames.
 */

const STATE_ID = 'media-manager-shared';
const patchedFrames = new WeakSet();

/**
 * Clear a polluted libraryContent user setting.
 *
 * @return {boolean} True when the setting was cleared.
 */
function clearPollutedLibraryContent() {
	if (
		typeof window.getUserSetting !== 'function' ||
		typeof window.setUserSetting !== 'function'
	) {
		return false;
	}
	if ( window.getUserSetting( 'libraryContent' ) !== STATE_ID ) {
		return false;
	}
	window.setUserSetting( 'libraryContent', 'browse' );
	return true;
}

/**
 * Patch Library prototype so Shared content mode is never persisted/restored.
 * Must run after wp.media.controller.Library exists (may be after first register).
 *
 * @return {{activatePatched: boolean, savePatched: boolean, hasLibrary: boolean, setUserPatched: boolean}} Patch status.
 */
function patchLibraryContentGuards() {
	const wp = window.wp;
	const proto = wp?.media?.controller?.Library?.prototype;
	const status = {
		hasLibrary: !! proto,
		activatePatched: false,
		savePatched: false,
		setUserPatched: false,
	};

	// Hard stop: never persist Shared as the default library content mode.
	if (
		typeof window.setUserSetting === 'function' &&
		! window.setUserSetting._mmSharedPatched
	) {
		const originalSetUserSetting = window.setUserSetting;
		const patchedSetUserSetting = function ( key, value ) {
			if ( key === 'libraryContent' && value === STATE_ID ) {
				return;
			}
			return originalSetUserSetting.apply( this, arguments );
		};
		patchedSetUserSetting._mmSharedPatched = true;
		window.setUserSetting = patchedSetUserSetting;
	}
	status.setUserPatched = !! window.setUserSetting?._mmSharedPatched;

	if ( ! proto ) {
		return status;
	}

	if (
		typeof proto.activate === 'function' &&
		! proto.activate._mmSharedPatched
	) {
		const originalActivate = proto.activate;
		const patchedActivate = function () {
			clearPollutedLibraryContent();
			const result = originalActivate.apply( this, arguments );
			// Library.activate may have copied a previously polluted setting onto
			// this state's content attribute — never keep Shared as library content.
			if ( this?.id !== STATE_ID && this?.get?.( 'content' ) === STATE_ID ) {
				this.set( 'content', 'browse' );
			}
			return result;
		};
		patchedActivate._mmSharedPatched = true;
		proto.activate = patchedActivate;
	}
	status.activatePatched = !! proto.activate?._mmSharedPatched;

	if (
		typeof proto.saveContentMode === 'function' &&
		! proto.saveContentMode._mmSharedPatched
	) {
		const originalSave = proto.saveContentMode;
		const patchedSave = function () {
			const mode = this.frame?.content?.mode?.();
			if ( mode === STATE_ID ) {
				return;
			}
			return originalSave.apply( this, arguments );
		};
		patchedSave._mmSharedPatched = true;
		proto.saveContentMode = patchedSave;
	}
	status.savePatched = !! proto.saveContentMode?._mmSharedPatched;

	return status;
}

/**
 * Resolve shared media boot config.
 *
 * @return {Object|null} Shared media config when enabled.
 */
function getSharedBoot() {
	const boot = window.mediaManagerAdmin || {};
	const shared = boot.sharedMedia;
	if ( ! shared?.enabled || ! boot.capabilities?.shared ) {
		return null;
	}
	return shared;
}

/**
 * Whether a media frame state is the shared library.
 *
 * @param {Object} state Media frame state.
 * @return {boolean} True when shared.
 */
export function isSharedMediaState( state ) {
	return !! state && state.id === STATE_ID;
}

/**
 * Whether an ID is a usable attachment post ID.
 *
 * @param {*} id Candidate id.
 * @return {boolean} True when a positive integer id.
 */
function isValidAttachmentId( id ) {
	if ( id === 0 || id === '0' ) {
		return true;
	}
	const numeric = parseInt( id, 10 );
	return (
		Number.isFinite( numeric ) &&
		numeric > 0 &&
		String( numeric ) === String( id )
	);
}

/**
 * Resolve the best known numeric featured image id.
 *
 * @return {number|null} Featured attachment id.
 */
function resolveFeaturedAttachmentId() {
	const settingsId =
		window.wp?.media?.view?.settings?.post?.featuredImageId;
	const editor = window.wp?.data?.select?.( 'core/editor' );
	const edited = editor?.getEditedPostAttribute?.( 'featured_media' );
	const saved = editor?.getCurrentPost?.( )?.featured_media;

	// Prefer a valid edited value; settings is the best fallback when
	// featured_media has been corrupted to a media-frame state id.
	if ( isValidAttachmentId( edited ) && Number( edited ) > 0 ) {
		return Number( edited );
	}
	if ( isValidAttachmentId( settingsId ) && Number( settingsId ) > 0 ) {
		return Number( settingsId );
	}
	if ( isValidAttachmentId( saved ) && Number( saved ) > 0 ) {
		return Number( saved );
	}

	return null;
}

/**
 * Restore featured_media when it has been corrupted to a non-numeric value.
 *
 * @return {boolean} True when a restore was applied.
 */
function restoreCorruptedFeaturedMedia() {
	const editorSelect = window.wp?.data?.select?.( 'core/editor' );
	const editorDispatch = window.wp?.data?.dispatch?.( 'core/editor' );
	if ( ! editorSelect || ! editorDispatch?.editPost ) {
		return false;
	}

	const edited = editorSelect.getEditedPostAttribute?.( 'featured_media' );
	const settingsId =
		window.wp?.media?.view?.settings?.post?.featuredImageId ?? null;
	const saved = editorSelect.getCurrentPost?.( )?.featured_media;
	const lastGood = restoreCorruptedFeaturedMedia._lastGoodId ?? null;

	if ( ! edited || isValidAttachmentId( edited ) ) {
		if ( isValidAttachmentId( edited ) && Number( edited ) > 0 ) {
			restoreCorruptedFeaturedMedia._lastGoodId = Number( edited );
		}
		return false;
	}

	// Prefer settings / last known good (unsaved edits) over the saved post —
	// restoring from saved alone reverts intentional featured changes on cancel.
	let restore = 0;
	if ( isValidAttachmentId( settingsId ) && Number( settingsId ) > 0 ) {
		restore = Number( settingsId );
	} else if ( isValidAttachmentId( lastGood ) && Number( lastGood ) > 0 ) {
		restore = Number( lastGood );
	} else if ( isValidAttachmentId( saved ) && Number( saved ) > 0 ) {
		restore = Number( saved );
	}

	editorDispatch.editPost( { featured_media: restore } );

	const settings = window.wp?.media?.view?.settings?.post;
	if ( settings ) {
		settings.featuredImageId = restore > 0 ? restore : -1;
	}
	if ( restore > 0 ) {
		restoreCorruptedFeaturedMedia._lastGoodId = restore;
	}

	return true;
}

/**
 * Whether the current featured image is a network/shared attachment.
 *
 * @return {boolean} True when featured image is from the shared library.
 */
function currentFeaturedIsNetwork() {
	const featuredId = resolveFeaturedAttachmentId();
	if ( ! featuredId ) {
		return false;
	}

	const settings = window.wp?.media?.view?.settings || {};
	if ( settings.mediaManagerFeaturedNetwork ) {
		return true;
	}
	if ( window.mediaManagerAdmin?.sharedMedia?.featuredIsNetwork ) {
		return true;
	}
	const attachment = window.wp?.media?.attachment?.( featuredId );
	return !! attachment?.get?.( 'media_manager_network' );
}

/**
 * Remember whether the latest featured selection came from Shared media.
 *
 * @param {boolean} isNetwork Whether selection is network media.
 * @return {void}
 */
function setFeaturedNetworkFlag( isNetwork ) {
	const settings = window.wp?.media?.view?.settings;
	if ( settings ) {
		settings.mediaManagerFeaturedNetwork = !! isNetwork;
	}
	if ( window.mediaManagerAdmin?.sharedMedia ) {
		window.mediaManagerAdmin.sharedMedia.featuredIsNetwork = !! isNetwork;
	}
}

/**
 * Read core/image block attributes for the selected or given client.
 *
 * @param {string|null} clientId Optional client id.
 * @return {Object|null} Attr snapshot.
 */
function getImageBlockAttrs( clientId = null ) {
	const blockEditor = window.wp?.data?.select?.( 'core/block-editor' );
	if ( ! blockEditor ) {
		return null;
	}

	const block = clientId
		? blockEditor.getBlock?.( clientId )
		: blockEditor.getSelectedBlock?.( );
	if ( ! block || block.name !== 'core/image' ) {
		return block
			? { name: block.name, clientId: block.clientId }
			: null;
	}
	return {
		name: block.name,
		clientId: block.clientId,
		id: block.attributes?.id ?? null,
		url: block.attributes?.url ?? null,
	};
}

/**
 * Keep a network image selection applied if something clears it afterward.
 *
 * @param {string} clientId Image block client id.
 * @param {Object} media    Attachment JSON (id, url, alt, caption).
 * @return {void}
 */
function pinNetworkImageSelection( clientId, media ) {
	const blockEditorSelect = window.wp?.data?.select?.( 'core/block-editor' );
	const blockEditorDispatch = window.wp?.data?.dispatch?.( 'core/block-editor' );
	if (
		! clientId ||
		! media?.id ||
		! media?.url ||
		! blockEditorSelect?.getBlock ||
		! blockEditorDispatch?.updateBlockAttributes ||
		! window.wp?.data?.subscribe
	) {
		return;
	}

	if ( pinNetworkImageSelection._unsub ) {
		pinNetworkImageSelection._unsub();
		pinNetworkImageSelection._unsub = null;
	}

	const applied = {
		id: media.id,
		url: media.url,
		alt: media.alt || undefined,
		caption:
			typeof media.caption === 'string' ? media.caption : undefined,
	};

	const write = () => {
		blockEditorDispatch.updateBlockAttributes( clientId, {
			id: applied.id,
			url: applied.url,
			alt: applied.alt,
			caption: applied.caption,
			blob: undefined,
		} );
	};

	const releasePin = () => {
		if ( pinNetworkImageSelection._unsub ) {
			pinNetworkImageSelection._unsub();
			pinNetworkImageSelection._unsub = null;
		}
	};

	const current = getImageBlockAttrs( clientId );
	if ( ! current?.url || current.id !== applied.id ) {
		write();
	}

	let last = current;
	let stableHits = 0;

	pinNetworkImageSelection._unsub = window.wp.data.subscribe( () => {
		const cur = getImageBlockAttrs( clientId );
		if ( ! cur ) {
			return;
		}

		if (
			last &&
			( last.id !== cur.id || last.url !== cur.url )
		) {
			// User replaced with a different attachment — stop pinning.
			if ( cur.id && cur.url && cur.id !== applied.id ) {
				releasePin();
				last = cur;
				return;
			}

			// Only restore when attrs were wiped (empty), not when replaced.
			if (
				last.id === applied.id &&
				last.url &&
				! cur.url &&
				! cur.id
			) {
				write();
				stableHits = 0;
				last = getImageBlockAttrs( clientId );
				return;
			}
		}

		if ( cur.id === applied.id && cur.url === applied.url ) {
			stableHits += 1;
			// Release watcher once attributes have stayed put across updates.
			if ( stableHits > 40 ) {
				releasePin();
			}
		} else {
			stableHits = 0;
		}

		last = cur;
	} );
}

/**
 * Stop pinning a previous network image selection.
 *
 * @return {void}
 */
function releaseNetworkImagePin() {
	if ( ! pinNetworkImageSelection._unsub ) {
		return;
	}
	pinNetworkImageSelection._unsub();
	pinNetworkImageSelection._unsub = null;
}

/**
 * Ensure a selection model exposes a usable `url` for Gutenberg MediaUpload.
 *
 * Image block clears itself when onSelect receives media without url.
 *
 * @param {Object|null} selection Selection collection.
 * @return {boolean} True when every selected model has a url.
 */
function ensureSelectionAttachmentUrls( selection ) {
	if ( ! selection?.models?.length ) {
		return false;
	}

	let allHaveUrl = true;
	selection.models.forEach( ( model ) => {
		if ( ! model || ! isValidAttachmentId( model.id ) ) {
			return;
		}
		if ( model.get( 'url' ) ) {
			return;
		}
		const fallback =
			model.get( 'source_url' ) ||
			model.get( 'link' ) ||
			model.get( 'sizes' )?.full?.url ||
			model.get( 'sizes' )?.large?.url ||
			model.get( 'sizes' )?.medium?.url ||
			null;
		if ( fallback ) {
			model.set( 'url', fallback );
		}
		if ( ! model.get( 'url' ) ) {
			allHaveUrl = false;
		}
	} );
	return allHaveUrl;
}

/**
 * Drop non-attachment models (e.g. state id "featured-image") from a selection.
 *
 * @param {Object|null} selection Selection collection.
 * @return {void}
 */
function sanitizeAttachmentSelection( selection ) {
	if ( ! selection?.models?.length ) {
		return;
	}

	const invalid = selection.models.filter(
		( model ) => ! isValidAttachmentId( model?.id )
	);
	if ( invalid.length ) {
		selection.remove( invalid );
	}
}

/**
 * Patch Selection so invalid ids cannot enter media selections.
 *
 * @return {void}
 */
function patchSelectionIdGuard() {
	const Selection = window.wp?.media?.model?.Selection;
	if ( ! Selection?.prototype || Selection.prototype._mmIdGuardPatched ) {
		return;
	}

	const originalAdd = Selection.prototype.add;
	const originalReset = Selection.prototype.reset;

	const keepValid = ( models ) => {
		if ( models == null ) {
			return models;
		}
		const list = Array.isArray( models ) ? models : [ models ];
		const valid = list.filter( ( model ) => {
			if ( model == null ) {
				return false;
			}
			if ( typeof model !== 'object' ) {
				return isValidAttachmentId( model );
			}
			const id = model.id ?? model.get?.( 'id' );
			// Allow models that do not yet have an id (uploads in progress).
			if ( id == null || id === '' ) {
				return true;
			}
			return isValidAttachmentId( id );
		} );
		return Array.isArray( models ) ? valid : valid[ 0 ];
	};

	Selection.prototype.add = function ( models, options ) {
		const next = keepValid( models );
		if ( Array.isArray( models ) && Array.isArray( next ) && ! next.length && models.length ) {
			return this;
		}
		if ( ! Array.isArray( models ) && models != null && next == null ) {
			return this;
		}
		return originalAdd.call( this, next, options );
	};

	Selection.prototype.reset = function ( models, options ) {
		return originalReset.call(
			this,
			models == null ? models : keepValid( models ),
			options
		);
	};

	Selection.prototype._mmIdGuardPatched = true;
}

/**
 * Shared-state Select must fire before close.
 *
 * wp.media.view.Toolbar.Select closes first by default. MediaUpload.onClose then
 * detaches the frame; onSelect runs afterward and can apply empty/incomplete
 * media to the Image block (which clears id/url when media.url is missing).
 *
 * @return {void}
 */
function patchToolbarSelectOrder() {
	const Select = window.wp?.media?.view?.Toolbar?.Select;
	if ( ! Select?.prototype || Select.prototype._mmSelectOrderPatched ) {
		return;
	}

	const originalClickSelect = Select.prototype.clickSelect;

	Select.prototype.clickSelect = function () {
		const options = this.options;
		const controller = this.controller;
		const stateId =
			typeof controller?.state === 'function' ? controller.state()?.id : null;

		if ( stateId !== STATE_ID ) {
			return originalClickSelect.apply( this, arguments );
		}

		if ( options.event ) {
			controller.state().trigger( options.event );
		}
		if ( options.close ) {
			controller.close();
		}
		if ( options.state ) {
			controller.setState( options.state );
		}
		// Skip reset for Shared — clearing selections after select has been
		// observed to race with Image block attribute application.
		if ( options.reset && options.reset !== true ) {
			controller.reset();
		}
	};

	Select.prototype._mmSelectOrderPatched = true;
}

/**
 * Sanitize selections before MediaUpload handles the select event.
 *
 * @param {Object} frame Media frame.
 * @return {void}
 */
function guardFrameSelect( frame ) {
	if ( ! frame?.states || frame._mmSelectGuarded ) {
		return;
	}

	frame._mmSelectGuarded = true;
	const states = frame.states;
	const originalTrigger = states.trigger.bind( states );

	states.trigger = function ( name ) {
		if ( name === 'select' ) {
			const current =
				typeof frame.state === 'function' ? frame.state() : null;
			const selection = current?.get?.( 'selection' );
			sanitizeAttachmentSelection( selection );
			if ( ! selection?.models?.length ) {
				restoreCorruptedFeaturedMedia();
				return this;
			}

			const hasUrls = ensureSelectionAttachmentUrls( selection );
			const model = selection.models[ 0 ];

			// Image block treats missing url as reset — never propagate that.
			if ( ! hasUrls || ! model?.get?.( 'url' ) ) {
				if ( model && ! model._mmUrlFetchPending ) {
					model._mmUrlFetchPending = true;
					const result = model.fetch?.();
					const args = arguments;
					const done = () => {
						model._mmUrlFetchPending = false;
						ensureSelectionAttachmentUrls( selection );
						if ( model.get( 'url' ) ) {
							originalTrigger.apply( this, args );
						}
					};
					if ( result && typeof result.done === 'function' ) {
						result.done( done ).fail?.( () => {
							model._mmUrlFetchPending = false;
						} );
					} else {
						model._mmUrlFetchPending = false;
					}
				}
				return this;
			}
		}
		return originalTrigger.apply( this, arguments );
	};
}

/**
 * Promote the current selection to the front of the Shared library grid.
 *
 * Selection items sort first (even when also present in the query). Items not
 * in the mirrored query still sort ahead of query results, matching FeaturedImage.
 * Always pair with an explicit more() when the mirror is still empty —
 * observing before fetch would otherwise skip AttachmentsBrowser.more().
 *
 * @param {Object} library   Shared attachments collection.
 * @param {Object} selection Shared selection.
 * @return {void}
 */
function promoteSelectionInSharedLibrary( library, selection ) {
	if ( ! library || ! selection || library._mmPromoteSelection ) {
		return;
	}

	library._mmPromoteSelection = true;

	const comparator = library.comparator;
	library.comparator = function ( a, b ) {
		const aSelected = !! selection.get( a.cid );
		const bSelected = !! selection.get( b.cid );
		if ( aSelected && ! bSelected ) {
			return -1;
		}
		if ( ! aSelected && bSelected ) {
			return 1;
		}

		const mirroring = this.mirroring;
		if ( mirroring ) {
			const aInQuery = !! mirroring.get( a.cid );
			const bInQuery = !! mirroring.get( b.cid );
			if ( ! aInQuery && bInQuery ) {
				return -1;
			}
			if ( aInQuery && ! bInQuery ) {
				return 1;
			}
		}
		return comparator ? comparator.apply( this, arguments ) : 0;
	};

	library.observe( selection );
}

/**
 * Resolve a numeric attachment id currently selected on the frame.
 *
 * Prefers the active/library state selection (MediaUpload.onOpen). Falls back to
 * options.selection / post__in from the frame constructor.
 *
 * @param {Object} frame Media frame.
 * @return {number|null} Attachment id.
 */
function resolveFrameSelectionAttachmentId( frame ) {
	const candidates = [];

	const pushFromSelection = ( selection ) => {
		selection?.models?.forEach( ( model ) => {
			if ( isValidAttachmentId( model?.id ) ) {
				candidates.push( Number( model.id ) );
			}
		} );
	};

	const active = typeof frame.state === 'function' ? frame.state() : null;
	pushFromSelection( active?.get?.( 'selection' ) );
	pushFromSelection( frame.states?.get?.( 'library' )?.get?.( 'selection' ) );
	pushFromSelection( frame.states?.get?.( 'featured-image' )?.get?.( 'selection' ) );
	pushFromSelection( frame.options?.selection );

	if ( candidates.length ) {
		return candidates[ 0 ];
	}

	const postIn =
		frame.options?.selection?.props?.get?.( 'post__in' ) ||
		frame.options?.selection?.props?.post__in ||
		null;
	if ( Array.isArray( postIn ) ) {
		const id = postIn.find( ( value ) => isValidAttachmentId( value ) );
		return id != null ? Number( id ) : null;
	}

	return null;
}

/**
 * Whether an attachment model is known network/shared media.
 *
 * @param {Object|null} attachment Attachment model.
 * @param {number|null} attachmentId Attachment id.
 * @param {boolean}     allowFeaturedMeta Allow featured-network boot flags.
 * @return {boolean} True when network.
 */
function attachmentLooksNetwork( attachment, attachmentId, allowFeaturedMeta ) {
	if ( attachment?.get?.( 'media_manager_network' ) ) {
		return true;
	}
	if (
		allowFeaturedMeta &&
		attachmentId &&
		attachmentId === resolveFeaturedAttachmentId() &&
		currentFeaturedIsNetwork()
	) {
		return true;
	}
	return false;
}

/**
 * Open the Shared media tab and select a network attachment.
 *
 * @param {Object}  frame        Media frame.
 * @param {number}  attachmentId Attachment id.
 * @param {Object}  [options]    Options.
 * @param {boolean} [options.allowFeaturedMeta] Use featured-network boot flags.
 * @return {void}
 */
function openSharedForNetworkAttachment( frame, attachmentId, options = {} ) {
	const wp = window.wp;
	const allowFeaturedMeta = !! options.allowFeaturedMeta;

	if ( ! frame?.states?.get?.( STATE_ID ) || ! isValidAttachmentId( attachmentId ) ) {
		return;
	}

	const id = Number( attachmentId );

	if ( allowFeaturedMeta && wp?.media?.view?.settings?.post ) {
		wp.media.view.settings.post.featuredImageId = id;
	}

	const sharedState = frame.states.get( STATE_ID );
	// Prefer an already-hydrated model from the library selection when present.
	const libraryModel = frame.states
		?.get?.( 'library' )
		?.get?.( 'selection' )
		?.models?.find?.( ( model ) => Number( model?.id ) === id );
	const attachment = libraryModel || wp.media.attachment( id );

	const apply = () => {
		if ( ! attachmentLooksNetwork( attachment, id, allowFeaturedMeta ) ) {
			return;
		}

		ensureSelectionAttachmentUrls(
			new wp.media.model.Selection( [ attachment ] )
		);
		if ( ! attachment.get( 'url' ) ) {
			const fallback =
				attachment.get( 'source_url' ) ||
				attachment.get( 'sizes' )?.full?.url ||
				null;
			if ( fallback ) {
				attachment.set( 'url', fallback );
			}
		}

		const selection = sharedState.get( 'selection' );
		const library = sharedState.get( 'library' );
		if ( selection ) {
			selection.reset( [ attachment ] );
		}

		if ( library && selection ) {
			promoteSelectionInSharedLibrary( library, selection );
		}

		if ( frame.state()?.id !== STATE_ID ) {
			frame.setState( STATE_ID );
		}
		if (
			typeof frame.content?.mode === 'function' &&
			frame.content.mode() !== STATE_ID
		) {
			frame.content.mode( STATE_ID );
		}

		const router = resolveRouterView( frame );
		if ( typeof router?.select === 'function' && router.get?.( STATE_ID ) ) {
			router.select( STATE_ID );
		}

		// Observing selection makes length > 0 before fetch; force more() so the
		// rest of the shared library still loads (AttachmentsBrowser skips it).
		if (
			library &&
			typeof library.more === 'function' &&
			library.mirroring &&
			library.mirroring.length === 0
		) {
			library.more().done( () => {
				if ( typeof library.sort === 'function' ) {
					library.sort();
				}
			} );
		} else if ( library && typeof library.sort === 'function' ) {
			library.sort();
		}
	};

	if ( attachmentLooksNetwork( attachment, id, allowFeaturedMeta ) ) {
		apply();
	}

	if ( ! attachment.get( 'media_manager_network' ) ) {
		const result = attachment.fetch();
		if ( result && typeof result.done === 'function' ) {
			result.done( () => {
				if ( attachmentLooksNetwork( attachment, id, allowFeaturedMeta ) ) {
					apply();
				}
			} );
		}
	}
}

/**
 * On frame open, switch to Shared when the current selection/featured is network.
 *
 * @param {Object} frame Media frame.
 * @return {void}
 */
function maybeOpenSharedForCurrentNetwork( frame ) {
	const isFeaturedFrame = !! frame.states?.get?.( 'featured-image' );

	if ( isFeaturedFrame ) {
		// Opening the featured frame can corrupt featured_media to a state id —
		// heal immediately so cancel/close does not fall back to the saved post.
		restoreCorruptedFeaturedMedia();
		const featuredIsNetwork = currentFeaturedIsNetwork();
		const featuredId = resolveFeaturedAttachmentId();
		const selectionModel =
			frame.states?.get?.( 'featured-image' )?.get?.( 'selection' )
				?.models?.[ 0 ] || null;
		const selectionIsLocal =
			!! selectionModel &&
			isValidAttachmentId( selectionModel.id ) &&
			! selectionModel.get?.( 'media_manager_network' ) &&
			Number( selectionModel.id ) !== Number( featuredId );
		// User already chose a different local attachment — do not hijack back to Shared.
		if ( selectionIsLocal ) {
			return;
		}
		if ( ! featuredIsNetwork ) {
			return;
		}
		if ( featuredId ) {
			openSharedForNetworkAttachment( frame, featuredId, {
				allowFeaturedMeta: true,
			} );
		}
		return;
	}

	// Image block / Select: only switch when the frame's own selection is network.
	const selectedId = resolveFrameSelectionAttachmentId( frame );
	if ( ! selectedId ) {
		return;
	}

	openSharedForNetworkAttachment( frame, selectedId, {
		allowFeaturedMeta: false,
	} );
}

/**
 * Keep network/shared attachments out of local Media Library grids.
 *
 * FeaturedImage (and ReplaceImage) call library.observe(selection), which pushes
 * the current selection into the grid and sorts non-query items first. When the
 * current featured image is a network attachment, that wrongly shows it in the
 * Media Library tab.
 *
 * @param {Object} frame Media frame.
 * @return {void}
 */
function excludeNetworkFromLocalLibraries( frame ) {
	if ( ! frame?.states ) {
		return;
	}

	const stateIds = [
		'featured-image',
		'library',
		'insert',
		'replace-image',
		'gallery',
		'playlist',
		'video-playlist',
	];

	stateIds.forEach( ( stateId ) => {
		const state = frame.states.get( stateId );
		const library = state?.get?.( 'library' );
		if ( ! library || library._mmExcludeNetwork ) {
			return;
		}

		library._mmExcludeNetwork = true;
		library.filters = library.filters || {};
		library.filters.media_manager_local_only = function ( attachment ) {
			return ! attachment?.get?.( 'media_manager_network' );
		};

		const purgeNetwork = ( model ) => {
			if ( model?.get?.( 'media_manager_network' ) && library.get( model.cid ) ) {
				library.remove( model );
			}
		};

		library.on( 'add', purgeNetwork );
		library.on( 'change', purgeNetwork );
		library.each( purgeNetwork );
	} );
}

/**
 * Add the Shared media library state to a media frame.
 *
 * @param {Object} frame Media frame.
 * @return {void}
 */
export function ensureSharedMediaState( frame ) {
	const shared = getSharedBoot();
	if ( ! shared || ! frame?.states ) {
		return;
	}

	const wp = window.wp;
	if ( ! wp?.media?.controller?.Library ) {
		return;
	}

	// Ensure prototype guards exist even if Library loaded after first register.
	patchLibraryContentGuards();
	patchSelectionIdGuard();
	guardFrameSelect( frame );
	patchToolbarSelectOrder();
	clearPollutedLibraryContent();
	excludeNetworkFromLocalLibraries( frame );

	// Repair state attributes polluted by a prior Shared visit / activate().
	// library.content=STATE_ID makes the next open render Shared before handlers
	// exist (blank modal). Toolbar Replace does not force mode:"browse"; the
	// placeholder does — which is why one path recovers and the other does not.
	const libraryStateEarly = frame.states.get( 'library' );
	const featuredStateEarly = frame.states.get( 'featured-image' );
	const sharedStateEarly = frame.states.get( STATE_ID );
	[ libraryStateEarly, featuredStateEarly ].forEach( ( state ) => {
		if ( state?.get?.( 'content' ) === STATE_ID ) {
			state.set( 'content', 'browse' );
		}
	} );
	if ( sharedStateEarly ) {
		if ( sharedStateEarly.get( 'content' ) !== STATE_ID ) {
			sharedStateEarly.set( 'content', STATE_ID );
		}
		if ( sharedStateEarly.get( 'router' ) !== STATE_ID ) {
			sharedStateEarly.set( 'router', STATE_ID );
		}
		if ( sharedStateEarly.get( 'contentUserSetting' ) !== false ) {
			sharedStateEarly.set( 'contentUserSetting', false );
		}
	}

	// Frame may have been patched earlier but lost the shared state — re-add.
	const alreadyPatched = patchedFrames.has( frame );
	const hasState = !! frame.states.get( STATE_ID );

	if ( hasState && alreadyPatched ) {
		return;
	}

	const insertState = frame.states.get( 'insert' );
	const featuredState = frame.states.get( 'featured-image' );
	const libraryState = frame.states.get( 'library' );
	const multiple =
		frame.options?.multiple ??
		insertState?.get?.( 'multiple' ) ??
		libraryState?.get?.( 'multiple' ) ??
		false;

	let toolbar = 'select';
	if ( insertState ) {
		toolbar = 'main-insert';
	} else if ( featuredState ) {
		toolbar = 'main-insert';
	} else if ( libraryState ) {
		toolbar = libraryState.get( 'toolbar' ) || 'select';
	}

	const libraryProps = {
		media_manager_shared: '1',
		media_manager_folder: 'all',
	};

	// Match mime filter from insert/library when the frame is image-only.
	const type =
		insertState?.get?.( 'library' )?.props?.get?.( 'type' ) ||
		featuredState?.get?.( 'library' )?.props?.get?.( 'type' ) ||
		libraryState?.get?.( 'library' )?.props?.get?.( 'type' );
	if ( type ) {
		libraryProps.type = type;
	}

	if ( ! hasState ) {
		frame.states.add(
			new wp.media.controller.Library( {
				id: STATE_ID,
				title: shared.tabTitle || 'Shared media',
				priority: 25,
				search: true,
				filterable: 'all',
				library: wp.media.query( libraryProps ),
				multiple,
				editable: false,
				allowLocalEdits: false,
				displaySettings: true,
				displayUserSettings: true,
				toolbar,
				menu: insertState ? 'default' : false,
				// Use a dedicated content/router mode so the Shared media tab stays
				// selected (browse mode would highlight Media Library instead).
				router: ! insertState ? STATE_ID : false,
				content: ! insertState ? STATE_ID : undefined,
				// Prevent Library.activate() from overwriting content with the
				// persisted libraryContent user setting (usually "browse").
				contentUserSetting: false,
				syncSelection: false,
			} )
		);
	}

	if ( typeof frame.remove === 'function' && ! frame._mmRemovePatched ) {
		const originalRemove = frame.remove;
		frame._mmRemovePatched = true;
		frame.remove = function () {
			const frameElement = frame.$el?.get?.( 0 ) || null;
			const modalElement =
				frame.modal?.$el?.get?.( 0 ) ||
				frameElement?.closest?.( '.media-modal' ) ||
				null;
			const modalIsOpen =
				!! frameElement?.isConnected &&
				!! modalElement?.isConnected &&
				window.getComputedStyle( modalElement ).display !== 'none';

			if ( modalIsOpen ) {
				if ( ! frame._mmRemoveOnClose ) {
					frame._mmRemoveOnClose = true;
					frame.once( 'close', function () {
						frame._mmRemoveOnClose = false;
						originalRemove.call( frame );
					} );
				}
				return frame;
			}

			return originalRemove.apply( this, arguments );
		};
	}

	// Only wire router/content handlers once per frame.
	if ( alreadyPatched ) {
		return;
	}

	// Featured-image / Select frames only expose Upload + Media Library router
	// tabs. Wire Shared media into that router and activate our state on click.
	if ( ! insertState ) {
		wireSelectFrameRouter( frame, shared, featuredState, libraryState );
	}

	// Featured-image frames use a custom toolbar; mirror select behaviour.
	if ( featuredState && ! insertState ) {
		frame.on( 'toolbar:create:main-insert', function ( toolbarView ) {
			const current = frame.state();
			if ( ! isSharedMediaState( current ) ) {
				return;
			}
			frame.createSelectToolbar( toolbarView, {
				text:
					frame.options?.button?.text ||
					wp.media.view.l10n.setFeaturedImage,
				controller: frame,
			} );
		} );
	}

	patchedFrames.add( frame );
}

/**
 * Resolve the media frame router view (views.get returns an array).
 *
 * @param {Object} frame Media frame.
 * @return {Object|null} Router view.
 */
function resolveRouterView( frame ) {
	let view = frame.views?.get?.( '.media-frame-router' );
	if ( Array.isArray( view ) ) {
		view = view[ 0 ] || null;
	}
	if ( ! view ) {
		view = frame.views?.get?.( '.media-router' );
		if ( Array.isArray( view ) ) {
			view = view[ 0 ] || null;
		}
	}
	if ( ! view && typeof frame.router?.get === 'function' ) {
		view = frame.router.get();
	}
	if ( ! view && typeof frame.router?.view === 'function' ) {
		view = frame.router.view();
	}
	if ( view && typeof view.set !== 'function' ) {
		return null;
	}
	return view || null;
}

/**
 * Add Shared media as a third router tab on Select / Featured Image frames.
 *
 * @param {Object}      frame         Media frame.
 * @param {Object}      shared        Shared boot config.
 * @param {Object|null} featuredState Featured image state.
 * @param {Object|null} libraryState  Library state.
 * @return {void}
 */
function wireSelectFrameRouter( frame, shared, featuredState, libraryState ) {
	const wp = window.wp;
	const l10n = wp.media.view.l10n || {};
	const primaryStateId =
		featuredState?.id || libraryState?.id || frame.state()?.id;

	const applySharedTab = ( routerView ) => {
		if ( Array.isArray( routerView ) ) {
			routerView = routerView[ 0 ];
		}
		if ( ! routerView || typeof routerView.set !== 'function' ) {
			return;
		}

		routerView.set( {
			upload: {
				text: l10n.uploadFilesTitle || 'Upload files',
				priority: 20,
			},
			browse: {
				text: l10n.mediaLibraryTitle || 'Media Library',
				priority: 40,
			},
			[ STATE_ID ]: {
				text: shared.tabTitle || 'Shared media',
				priority: 60,
			},
		} );

		// set() rebuilds buttons and clears `.active` — restore highlight.
		const mode = frame.content?.mode?.() || 'browse';
		const activeMode = mode === STATE_ID ? STATE_ID : mode;
		if ( typeof routerView.select === 'function' && routerView.get?.( activeMode ) ) {
			routerView.select( activeMode );
		} else {
			routerView.deselect?.();
			routerView.get?.( activeMode )?.$el?.addClass( 'active' );
		}
	};

	clearPollutedLibraryContent();

	[ featuredState, libraryState ].forEach( ( state ) => {
		if ( state?.get?.( 'content' ) === STATE_ID ) {
			state.set( 'content', 'browse' );
		}
	} );

	// Patch browseRouter so later renders keep the Shared media tab.
	const originalBrowseRouter =
		typeof frame.browseRouter === 'function'
			? frame.browseRouter.bind( frame )
			: null;
	frame.browseRouter = function ( routerView ) {
		try {
			if ( originalBrowseRouter ) {
				originalBrowseRouter( routerView );
			}
		} catch {}
		applySharedTab( routerView );
	};

	// Region create/render for our dedicated mode (Select only wires "browse").
	if ( typeof frame.createRouter === 'function' ) {
		frame.on( 'router:create:' + STATE_ID, frame.createRouter, frame );
	}

	/**
	 * Build the shared attachments browser (no inline uploader).
	 *
	 * Always binds the shared state's library — do not use frame.state(), which is
	 * still the previous state during the first content:create after a tab click.
	 *
	 * @param {Object} contentRegion Region create payload (`{ view }`).
	 * @return {void}
	 */
	const createSharedContent = ( contentRegion ) => {
		const wp = window.wp;
		const state = frame.states.get( STATE_ID );
		if ( ! state || ! wp?.media?.view?.AttachmentsBrowser ) {
			return;
		}

		frame.$el.removeClass( 'hide-toolbar' );

		contentRegion.view = new wp.media.view.AttachmentsBrowser( {
			controller: frame,
			collection: state.get( 'library' ),
			selection: state.get( 'selection' ),
			model: state,
			sortable: state.get( 'sortable' ),
			search: state.get( 'searchable' ),
			filters: state.get( 'filterable' ),
			date: state.get( 'date' ),
			display: state.has( 'display' )
				? state.get( 'display' )
				: state.get( 'displaySettings' ),
			dragInfo: state.get( 'dragInfo' ),
			idealColumnWidth: state.get( 'idealColumnWidth' ),
			suggestedWidth: state.get( 'suggestedWidth' ),
			suggestedHeight: state.get( 'suggestedHeight' ),
			AttachmentView: state.get( 'AttachmentView' ),
		} );

		const browser = contentRegion.view;
		if ( browser?.uploader?.$el ) {
			browser.uploader.$el.hide().addClass( 'hidden' );
		}
		browser?.$el?.addClass( 'media-manager-shared-browser' );
	};

	/**
	 * Rebuild content after the current Region.render() finishes.
	 * Nested render() inside create/render lets the outer set() overwrite the view.
	 * Region.render() does not fire activate — trigger it so sidebars remount.
	 *
	 * @param {string} mode Content mode to render.
	 * @return {void}
	 */
	const deferContentRender = ( mode ) => {
		window.setTimeout( () => {
			if ( typeof frame.content?.render !== 'function' ) {
				return;
			}
			frame.content.render( mode );
			frame.trigger( 'content:activate:' + mode );
		}, 0 );
	};

	frame.on( 'content:create:' + STATE_ID, createSharedContent );

	frame.on( 'router:render:browse', applySharedTab );
	frame.on( 'router:render:' + STATE_ID, applySharedTab );

	let activatingShared = false;
	let leavingShared = false;

	frame.on( 'open', function () {
		frame._mmImageAttrsOnOpen = getImageBlockAttrs();

		activatingShared = true;
		maybeOpenSharedForCurrentNetwork( frame );
		activatingShared = false;

		// MediaUpload.onOpen may add the selection after our open handler.
		// Only watch library inserts for image-block flows — featured open is
		// handled above via currentFeaturedIsNetwork(). Watching featured-image
		// selection.add wrongly re-opens Shared when the user picks a local item.
		const watchStates = [ 'library' ];
		watchStates.forEach( ( stateId ) => {
			const selection = frame.states?.get?.( stateId )?.get?.( 'selection' );
			if ( ! selection || selection._mmOpenWatch ) {
				return;
			}
			selection._mmOpenWatch = true;
			const onAdd = function ( model ) {
				selection.off( 'add', onAdd );
				selection._mmOpenWatch = false;
				if ( frame.state?.()?.id === STATE_ID ) {
					return;
				}
				// Never hijack a local Media Library selection back to Shared.
				if ( model && ! model.get?.( 'media_manager_network' ) ) {
					return;
				}
				activatingShared = true;
				maybeOpenSharedForCurrentNetwork( frame );
				activatingShared = false;
			};
			selection.on( 'add', onAdd );
			frame.once( 'close', function () {
				selection.off( 'add', onAdd );
				selection._mmOpenWatch = false;
			} );
		} );
	} );

	frame.on( 'select', function () {
		const state = frame.state?.() || null;
		sanitizeAttachmentSelection( state?.get?.( 'selection' ) );
		const selection = state?.get?.( 'selection' );
		const selected = selection?.models?.[ 0 ] || null;
		const selectedId = selected?.id;
		const isFeaturedFrame = !! frame.states?.get?.( 'featured-image' );

		if ( ! isValidAttachmentId( selectedId ) ) {
			if ( isFeaturedFrame ) {
				restoreCorruptedFeaturedMedia();
			}
			return;
		}

		// Keep media settings in sync with successful featured selections so
		// resolveFeaturedAttachmentId does not prefer a stale saved settings id.
		if ( isFeaturedFrame && window.wp?.media?.view?.settings?.post ) {
			window.wp.media.view.settings.post.featuredImageId = Number( selectedId );
			restoreCorruptedFeaturedMedia._lastGoodId = Number( selectedId );
		}

		// Only update featured network meta for featured-image frames.
		if ( isFeaturedFrame ) {
			setFeaturedNetworkFlag( !! selected?.get?.( 'media_manager_network' ) );
			return;
		}

		// Pin network selections onto the active image block.
		if (
			selected?.get?.( 'media_manager_network' ) &&
			selected?.get?.( 'url' )
		) {
			const clientId =
				frame._mmImageAttrsOnOpen?.clientId ||
				getImageBlockAttrs()?.clientId ||
				null;
			if ( clientId ) {
				pinNetworkImageSelection( clientId, selected.toJSON() );
			}
		} else {
			releaseNetworkImagePin();
		}
	} );

	frame.on( 'close', function () {
		const state = frame.state?.() || null;
		const isFeaturedFrame = !! frame.states?.get?.( 'featured-image' );
		sanitizeAttachmentSelection( state?.get?.( 'selection' ) );
		if ( isFeaturedFrame ) {
			restoreCorruptedFeaturedMedia();
		}
	} );

	frame.on( 'content:render:' + STATE_ID, function () {
		const sharedState = frame.states.get( STATE_ID );

		if ( ! sharedState ) {
			ensureSharedMediaState( frame );
		}

		if ( frame.state()?.id !== STATE_ID ) {
			activatingShared = true;
			frame.setState( STATE_ID );
			activatingShared = false;
			// createSharedContent already bound the shared library — no deferred
			// rebuild (that was destroying the folder sidebar mount).
		}
	} );

	frame.on( 'content:activate:' + STATE_ID, function () {
		const browser = frame.content?.get?.();
		const sharedLib = frame.states.get( STATE_ID )?.get?.( 'library' );
		const browserIsSharedLib =
			!! browser?.collection && browser.collection === sharedLib;

		// Safety net if create somehow bound the wrong collection.
		if ( ! browserIsSharedLib && frame.state()?.id === STATE_ID ) {
			deferContentRender( STATE_ID );
		}
	} );

	frame.on( 'content:render:browse', function () {
		if ( activatingShared || leavingShared ) {
			return;
		}
		if ( frame.state()?.id === STATE_ID && primaryStateId ) {
			leavingShared = true;
			frame.setState( primaryStateId );
			leavingShared = false;
			deferContentRender( 'browse' );
		}
	} );

	frame.on( 'content:render:upload', function () {
		if ( activatingShared || leavingShared ) {
			return;
		}
		if ( frame.state()?.id === STATE_ID && primaryStateId ) {
			leavingShared = true;
			frame.setState( primaryStateId );
			leavingShared = false;
		}
	} );

	const existingRouter = resolveRouterView( frame );

	if ( existingRouter ) {
		applySharedTab( existingRouter );
	} else {
		frame.once( 'content:activate:browse', function () {
			const router = resolveRouterView( frame );
			if ( router ) {
				applySharedTab( router );
			}
		} );
		frame.once( 'content:activate:upload', function () {
			const router = resolveRouterView( frame );
			if ( router ) {
				applySharedTab( router );
			}
		} );
	}

}

/**
 * Observe frames and inject the Shared media state.
 *
 * @return {Function} Teardown.
 */
export function registerSharedMediaTab() {
	const shared = getSharedBoot();
	const wp = window.wp;
	if ( ! shared ) {
		return () => {};
	}

	patchLibraryContentGuards();
	patchSelectionIdGuard();
	patchToolbarSelectOrder();
	clearPollutedLibraryContent();

	if ( ! wp?.media?.view?.Modal ) {
		return () => {};
	}

	const OriginalModal = wp.media.view.Modal;
	let active = true;

	wp.media.view.Modal = OriginalModal.extend( {
		open() {
			// Attach before open so FeaturedImage.updateSelection is observed.
			if ( active && this?.options?.controller ) {
				ensureSharedMediaState( this.options.controller );
			}
			return OriginalModal.prototype.open.apply( this, arguments );
		},
	} );

	if ( wp.media.frame ) {
		ensureSharedMediaState( wp.media.frame );
	}

	return () => {
		active = false;
		wp.media.view.Modal = OriginalModal;
	};
}
