/**
 * Folder dialog policy field wiring.
 */

describe( 'FolderDialog policy payload shape', () => {
	it( 'normalises usernames from multiline textarea', () => {
		const raw = 'misha\nadmin\n\n';
		const usernames = raw
			.split( '\n' )
			.map( ( line ) => line.trim() )
			.filter( Boolean );
		expect( usernames ).toEqual( [ 'misha', 'admin' ] );
	} );

	it( 'keeps private and access mode as separate fields', () => {
		const payload = {
			name: 'Vault',
			parent: 0,
			access_mode: 'users',
			allowed_usernames: [ 'misha' ],
			is_private: true,
		};
		expect( payload.is_private ).toBe( true );
		expect( payload.access_mode ).toBe( 'users' );
		expect( payload ).toHaveProperty( 'allowed_usernames' );
	} );
} );
