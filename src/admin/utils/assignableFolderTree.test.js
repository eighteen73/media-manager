/**
 * Tests for assignableFolderTree helper.
 */

import { assignableFolderTree } from '../utils/assignableFolderTree';

describe( 'assignableFolderTree', () => {
	it( 'unwraps system roots and keeps hierarchy', () => {
		expect(
			assignableFolderTree( [
				{
					id: 1,
					name: 'Posts',
					is_system_root: true,
					children: [
						{
							id: 2,
							name: 'Hello',
							children: [
								{ id: 3, name: 'Nested', children: [] },
							],
						},
					],
				},
				{ id: 4, name: 'Demo', children: [] },
			] )
		).toEqual( [
			{
				id: 2,
				name: 'Hello',
				children: [ { id: 3, name: 'Nested', children: [] } ],
			},
			{ id: 4, name: 'Demo', children: [] },
		] );
	} );
} );
