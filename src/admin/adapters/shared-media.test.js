/**
 * Shared media featured-image helper tests.
 */

import {
	attachmentLooksNetwork,
	currentFeaturedIsNetwork,
	isSharedMediaState,
	isValidAttachmentId,
	resolveFeaturedAttachmentId,
	restoreCorruptedFeaturedMedia,
	sanitizeAttachmentSelection,
	setFeaturedNetworkFlag,
} from './shared-media';

describe( 'isValidAttachmentId', () => {
	it( 'accepts positive numeric ids and zero', () => {
		expect( isValidAttachmentId( 12 ) ).toBe( true );
		expect( isValidAttachmentId( '12' ) ).toBe( true );
		expect( isValidAttachmentId( 0 ) ).toBe( true );
		expect( isValidAttachmentId( '0' ) ).toBe( true );
	} );

	it( 'rejects media-frame state ids', () => {
		expect( isValidAttachmentId( 'featured-image' ) ).toBe( false );
		expect( isValidAttachmentId( 'media-manager-shared' ) ).toBe( false );
		expect( isValidAttachmentId( null ) ).toBe( false );
	} );
} );

describe( 'resolveFeaturedAttachmentId', () => {
	afterEach( () => {
		delete window.wp;
	} );

	it( 'prefers a valid edited featured_media value', () => {
		window.wp = {
			media: { view: { settings: { post: { featuredImageId: 7 } } } },
			data: {
				select: () => ( {
					getEditedPostAttribute: () => 42,
					getCurrentPost: () => ( { featured_media: 7 } ),
				} ),
			},
		};

		expect( resolveFeaturedAttachmentId() ).toBe( 42 );
	} );

	it( 'falls back to settings then saved when edited is corrupted', () => {
		window.wp = {
			media: { view: { settings: { post: { featuredImageId: 9 } } } },
			data: {
				select: () => ( {
					getEditedPostAttribute: () => 'featured-image',
					getCurrentPost: () => ( { featured_media: 3 } ),
				} ),
			},
		};

		expect( resolveFeaturedAttachmentId() ).toBe( 9 );
	} );
} );

describe( 'restoreCorruptedFeaturedMedia', () => {
	afterEach( () => {
		delete window.wp;
		delete restoreCorruptedFeaturedMedia._lastGoodId;
	} );

	it( 'restores featured_media when corrupted to a state id', () => {
		const editPost = jest.fn();
		window.wp = {
			media: { view: { settings: { post: { featuredImageId: 55 } } } },
			data: {
				select: () => ( {
					getEditedPostAttribute: () => 'featured-image',
					getCurrentPost: () => ( { featured_media: 10 } ),
				} ),
				dispatch: () => ( { editPost } ),
			},
		};

		expect( restoreCorruptedFeaturedMedia() ).toBe( true );
		expect( editPost ).toHaveBeenCalledWith( { featured_media: 55 } );
		expect( window.wp.media.view.settings.post.featuredImageId ).toBe( 55 );
	} );

	it( 'does nothing when featured_media is already valid', () => {
		const editPost = jest.fn();
		window.wp = {
			media: { view: { settings: { post: { featuredImageId: 55 } } } },
			data: {
				select: () => ( {
					getEditedPostAttribute: () => 55,
					getCurrentPost: () => ( { featured_media: 55 } ),
				} ),
				dispatch: () => ( { editPost } ),
			},
		};

		expect( restoreCorruptedFeaturedMedia() ).toBe( false );
		expect( editPost ).not.toHaveBeenCalled();
	} );
} );

describe( 'sanitizeAttachmentSelection', () => {
	it( 'removes non-attachment models from the selection', () => {
		const remove = jest.fn();
		const selection = {
			models: [ { id: 12 }, { id: 'featured-image' }, { id: 34 } ],
			remove,
		};

		sanitizeAttachmentSelection( selection );

		expect( remove ).toHaveBeenCalledWith( [ { id: 'featured-image' } ] );
	} );
} );

describe( 'featured network helpers', () => {
	afterEach( () => {
		delete window.wp;
		delete window.mediaManagerAdmin;
	} );

	it( 'reads and writes featured-network boot flags', () => {
		window.wp = { media: { view: { settings: {} } } };
		window.mediaManagerAdmin = { sharedMedia: {} };

		setFeaturedNetworkFlag( true );
		expect( window.wp.media.view.settings.mediaManagerFeaturedNetwork ).toBe(
			true
		);
		expect( window.mediaManagerAdmin.sharedMedia.featuredIsNetwork ).toBe(
			true
		);

		window.wp = {
			media: {
				view: { settings: { mediaManagerFeaturedNetwork: true } },
				attachment: () => null,
			},
			data: {
				select: () => ( {
					getEditedPostAttribute: () => 99,
					getCurrentPost: () => ( { featured_media: 99 } ),
				} ),
			},
		};
		expect( currentFeaturedIsNetwork() ).toBe( true );
	} );

	it( 'detects network attachments from model attrs or featured meta', () => {
		window.wp = {
			media: {
				view: {
					settings: {
						post: { featuredImageId: 5 },
						mediaManagerFeaturedNetwork: true,
					},
				},
			},
			data: {
				select: () => ( {
					getEditedPostAttribute: () => 5,
					getCurrentPost: () => ( { featured_media: 5 } ),
				} ),
			},
		};

		expect(
			attachmentLooksNetwork(
				{ get: () => false },
				5,
				true
			)
		).toBe( true );
		expect(
			attachmentLooksNetwork(
				{ get: ( key ) => key === 'media_manager_network' },
				8,
				false
			)
		).toBe( true );
	} );
} );

describe( 'isSharedMediaState', () => {
	it( 'matches the shared state id', () => {
		expect( isSharedMediaState( { id: 'media-manager-shared' } ) ).toBe(
			true
		);
		expect( isSharedMediaState( { id: 'featured-image' } ) ).toBe( false );
		expect( isSharedMediaState( null ) ).toBe( false );
	} );
} );
