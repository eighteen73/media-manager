/**
 * Store unit tests.
 */

import { filterTree, reducer, sortTree, DEFAULT_STATE } from './store';

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
