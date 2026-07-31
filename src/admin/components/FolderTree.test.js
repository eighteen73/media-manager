/**
 * FolderTree smoke tests.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import FolderTree from './FolderTree';

describe( 'FolderTree', () => {
	it( 'renders All files, Uncategorized, and folder nodes', () => {
		const onSelect = jest.fn();

		render(
			<FolderTree
				nodes={ [
					{
						id: 1,
						name: 'Reports',
						count: 2,
						children: [],
					},
				] }
				summary={ { all_count: 10, uncategorized_count: 8 } }
				selectedFolder={ null }
				expanded={ {} }
				showCount
				showSearch
				search=""
				onSearch={ jest.fn() }
				onSelect={ onSelect }
				onToggle={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'All files' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Uncategorized' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Reports' ) ).toBeInTheDocument();
		expect( screen.getByText( '2' ) ).toBeInTheDocument();

		fireEvent.click( screen.getByText( 'Reports' ) );
		expect( onSelect ).toHaveBeenCalledWith( 1 );
	} );
} );
