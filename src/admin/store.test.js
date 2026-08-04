/**
 * Store unit tests.
 */

import { filterTree, reducer, sortTree, DEFAULT_STATE, actions } from './store';
import * as api from './api';

jest.mock( './api' );

describe( 'filterTree', () => {
	const tree = [
		{
			id: 1,
			name: 'Reports',
			children: [ { id: 2, name: 'Annual', children: [] } ],
		},
		{
			id: 3,
			name: 'Policies',
			children: [],
		},
	];

	it( 'preserves hierarchy for matching descendants', () => {
		const filtered = filterTree( tree, 'ann' );
		expect( filtered ).toHaveLength( 1 );
		expect( filtered[ 0 ].name ).toBe( 'Reports' );
		expect( filtered[ 0 ].children[ 0 ].name ).toBe( 'Annual' );
	} );

	it( 'returns the original tree when search is empty', () => {
		expect( filterTree( tree, '' ) ).toEqual( tree );
	} );
} );

describe( 'sortTree', () => {
	it( 'keeps system roots first, then user folders alphabetically', () => {
		const tree = [
			{ id: 1, name: 'Zebra', is_system_root: false, children: [] },
			{ id: 2, name: 'Posts', is_system_root: true, children: [] },
			{ id: 3, name: 'Alpha', is_system_root: false, children: [] },
			{ id: 4, name: 'Pages', is_system_root: true, children: [] },
		];

		const sorted = sortTree( tree, 'a-z' );
		expect( sorted.map( ( node ) => node.name ) ).toEqual( [
			'Pages',
			'Posts',
			'Alpha',
			'Zebra',
		] );
	} );
} );

describe( 'reducer', () => {
	it( 'updates selected folder and search', () => {
		let state = reducer( DEFAULT_STATE, {
			type: 'SET_SELECTED_FOLDER',
			selectedFolder: 4,
		} );
		state = reducer( state, { type: 'SET_SEARCH', search: 'doc' } );
		expect( state.selectedFolder ).toBe( 4 );
		expect( state.search ).toBe( 'doc' );
	} );

	it( 'bumps request tokens to avoid stale overwrites', () => {
		const state = reducer( DEFAULT_STATE, { type: 'BUMP_REQUEST_TOKEN' } );
		expect( state.requestToken ).toBe( 1 );
	} );

	it( 'tracks busy overlay state', () => {
		const state = reducer( DEFAULT_STATE, {
			type: 'SET_BUSY',
			isBusy: true,
		} );
		expect( state.isBusy ).toBe( true );
	} );
} );

describe( 'downloadFolder action', () => {
	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'downloads a zip blob on success', async () => {
		const blob = new Blob( [ 'zip' ], { type: 'application/zip' } );
		api.downloadFolder.mockResolvedValue( {
			blob,
			filename: 'Photos.zip',
		} );
		api.triggerBlobDownload.mockImplementation( () => {} );

		const dispatch = {
			setBusy: jest.fn(),
			setNotice: jest.fn(),
		};

		const thunk = actions.downloadFolder( 12 );
		await thunk( { dispatch } );

		expect( api.downloadFolder ).toHaveBeenCalledWith( 12 );
		expect( api.triggerBlobDownload ).toHaveBeenCalledWith(
			blob,
			'Photos.zip'
		);
		expect( dispatch.setBusy ).toHaveBeenCalledWith( true );
		expect( dispatch.setBusy ).toHaveBeenCalledWith( false );
		expect( dispatch.setNotice ).toHaveBeenCalledWith( null );
	} );

	it( 'surfaces an error notice when download fails', async () => {
		api.downloadFolder.mockRejectedValue(
			new Error( 'This folder has no downloadable files.' )
		);

		const dispatch = {
			setBusy: jest.fn(),
			setNotice: jest.fn(),
		};

		const thunk = actions.downloadFolder( 12 );
		await thunk( { dispatch } );

		expect( dispatch.setNotice ).toHaveBeenCalledWith( {
			status: 'error',
			message: 'This folder has no downloadable files.',
		} );
		expect( dispatch.setBusy ).toHaveBeenCalledWith( false );
	} );
} );

describe( 'assign action', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		window.confirm = jest.fn( () => true );
		window.mediaManagerAdmin = {
			strings: {
				privateConfirmEnter: 'Enter private?',
				privateConfirmLeave: 'Leave private?',
			},
		};
	} );

	afterEach( () => {
		delete window.confirm;
		delete window.mediaManagerAdmin;
	} );

	it( 'forces move + confirm when targeting a private folder', async () => {
		api.assignAttachments.mockResolvedValue( { assigned: true } );

		const dispatch = {
			setBusy: jest.fn(),
			setNotice: jest.fn(),
			setSelectedAttachmentIds: jest.fn(),
			loadFolders: jest.fn( () => Promise.resolve() ),
		};
		const select = {
			getSelectedAttachmentIds: () => [ 1, 2 ],
			getTree: () => [
				{ id: 9, name: 'Vault', is_private: true, children: [] },
			],
		};

		const result = await actions.assign( 9, 'add' )( { dispatch, select } );

		expect( window.confirm ).toHaveBeenCalledWith( 'Enter private?' );
		expect( api.assignAttachments ).toHaveBeenCalledWith( {
			attachmentIds: [ 1, 2 ],
			folderId: 9,
			mode: 'move',
			confirm: true,
		} );
		expect( result ).toBe( true );
	} );

	it( 'returns false when private confirm is cancelled', async () => {
		window.confirm.mockReturnValue( false );

		const dispatch = {
			setBusy: jest.fn(),
			setNotice: jest.fn(),
		};
		const select = {
			getSelectedAttachmentIds: () => [ 1 ],
			getTree: () => [
				{ id: 9, name: 'Vault', is_private: true, children: [] },
			],
		};

		const result = await actions.assign( 9, 'move' )( { dispatch, select } );

		expect( result ).toBe( false );
		expect( api.assignAttachments ).not.toHaveBeenCalled();
	} );

	it( 'retries after confirmation_required from the server', async () => {
		api.assignAttachments
			.mockRejectedValueOnce( {
				code: 'media_manager_confirmation_required',
				message: 'Confirm leave',
				data: { needs_enter: false },
			} )
			.mockResolvedValueOnce( { assigned: true } );

		const dispatch = {
			setBusy: jest.fn(),
			setNotice: jest.fn(),
			setSelectedAttachmentIds: jest.fn(),
			loadFolders: jest.fn( () => Promise.resolve() ),
		};
		const select = {
			getSelectedAttachmentIds: () => [ 3 ],
			getTree: () => [
				{ id: 4, name: 'Public', is_private: false, children: [] },
			],
		};

		const result = await actions.assign( 4, 'move' )( { dispatch, select } );

		expect( window.confirm ).toHaveBeenCalledWith( 'Leave private?' );
		expect( api.assignAttachments ).toHaveBeenNthCalledWith( 2, {
			attachmentIds: [ 3 ],
			folderId: 4,
			mode: 'move',
			confirm: true,
		} );
		expect( result ).toBe( true );
	} );
} );
