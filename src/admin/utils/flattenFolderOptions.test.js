/**
 * Tests for flattenFolderOptions.
 */

import { flattenFolderOptions } from './flattenFolderOptions';

describe( 'flattenFolderOptions', () => {
	it( 'flattens nested folders with depth and path labels', () => {
		expect(
			flattenFolderOptions( [
				{
					id: 1,
					name: 'Parent',
					is_private: false,
					children: [
						{
							id: 2,
							name: 'Child',
							is_private: true,
							children: [
								{
									id: 3,
									name: 'Grand',
									is_private: false,
									children: [],
								},
							],
						},
					],
				},
				{ id: 4, name: 'Sibling', is_private: false, children: [] },
			] )
		).toEqual( [
			{
				id: 1,
				name: 'Parent',
				depth: 0,
				path: 'Parent',
				is_private: false,
			},
			{
				id: 2,
				name: 'Child',
				depth: 1,
				path: 'Parent › Child',
				is_private: true,
			},
			{
				id: 3,
				name: 'Grand',
				depth: 2,
				path: 'Parent › Child › Grand',
				is_private: false,
			},
			{
				id: 4,
				name: 'Sibling',
				depth: 0,
				path: 'Sibling',
				is_private: false,
			},
		] );
	} );

	it( 'skips system roots but keeps their children', () => {
		expect(
			flattenFolderOptions( [
				{
					id: 10,
					name: 'Posts',
					is_system_root: true,
					children: [
						{ id: 11, name: 'Hello World', children: [] },
					],
				},
			] )
		).toEqual( [
			{
				id: 11,
				name: 'Hello World',
				depth: 0,
				path: 'Hello World',
				is_private: false,
			},
		] );
	} );

	it( 'returns an empty array for empty input', () => {
		expect( flattenFolderOptions( [] ) ).toEqual( [] );
		expect( flattenFolderOptions( null ) ).toEqual( [] );
	} );
} );
