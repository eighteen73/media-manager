/**
 * Tests for applyPrivateExclusivity.
 */

import { applyPrivateExclusivity } from './applyPrivateExclusivity';

describe( 'applyPrivateExclusivity', () => {
	const byId = new Map( [
		[ 1, { id: 1, is_private: false } ],
		[ 2, { id: 2, is_private: false } ],
		[ 3, { id: 3, is_private: true } ],
		[ 4, { id: 4, is_private: true } ],
	] );

	it( 'keeps multiple public folders', () => {
		expect( applyPrivateExclusivity( [ 1, 2 ], byId, [ 1 ] ) ).toEqual( {
			ids: [ 1, 2 ],
			notice: null,
		} );
	} );

	it( 'replaces public folders when a private folder is added', () => {
		const result = applyPrivateExclusivity( [ 1, 3 ], byId, [ 1 ] );
		expect( result.ids ).toEqual( [ 3 ] );
		expect( result.notice ).toBeTruthy();
	} );

	it( 'drops the private folder when a public folder is added', () => {
		const result = applyPrivateExclusivity( [ 3, 2 ], byId, [ 3 ] );
		expect( result.ids ).toEqual( [ 2 ] );
		expect( result.notice ).toBeTruthy();
	} );

	it( 'keeps only one private folder', () => {
		const result = applyPrivateExclusivity( [ 3, 4 ], byId, [ 3 ] );
		expect( result.ids ).toEqual( [ 4 ] );
		expect( result.notice ).toBeTruthy();
	} );

	it( 'ignores unknown IDs', () => {
		expect( applyPrivateExclusivity( [ 99, 1 ], byId, [] ) ).toEqual( {
			ids: [ 1 ],
			notice: null,
		} );
	} );
} );
