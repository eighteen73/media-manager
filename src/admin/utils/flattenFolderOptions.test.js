/**
 * Tests for flattenFolderOptions.
 */

import { flattenFolderOptions } from './flattenFolderOptions';

describe( 'flattenFolderOptions', () => {
	it( 'flattens nested folders with depth', () => {
		expect(
			flattenFolderOptions( [
				{
					id: 1,
					name: 'Parent',
					children: [
						{
							id: 2,
							name: 'Child',
							children: [ { id: 3, name: 'Grand', children: [] } ],
						},
					],
				},
				{ id: 4, name: 'Sibling', children: [] },
			] )
		).toEqual( [
			{ id: 1, name: 'Parent', depth: 0 },
			{ id: 2, name: 'Child', depth: 1 },
			{ id: 3, name: 'Grand', depth: 2 },
			{ id: 4, name: 'Sibling', depth: 0 },
		] );
	} );

	it( 'returns an empty array for empty input', () => {
		expect( flattenFolderOptions( [] ) ).toEqual( [] );
		expect( flattenFolderOptions( null ) ).toEqual( [] );
	} );
} );
