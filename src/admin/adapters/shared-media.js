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
	clearPollutedLibraryContent();

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
	clearPollutedLibraryContent();

	if ( ! wp?.media?.view?.Modal ) {
		return () => {};
	}

	const OriginalModal = wp.media.view.Modal;
	let active = true;

	wp.media.view.Modal = OriginalModal.extend( {
		open() {
			const result = OriginalModal.prototype.open.apply( this, arguments );
			if ( active && this?.options?.controller ) {
				const frame = this.options.controller;
				ensureSharedMediaState( frame );
			}
			return result;
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
