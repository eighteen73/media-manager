/**
 * UploadFolderSelect tests.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import UploadFolderSelect from './UploadFolderSelect';

describe( 'UploadFolderSelect', () => {
	it( 'renders Choose folder and nested options', () => {
		render(
			<UploadFolderSelect
				folders={ [
					{
						id: 1,
						name: 'Reports',
						children: [ { id: 2, name: '2026', children: [] } ],
					},
				] }
				value={ null }
				onChange={ jest.fn() }
			/>
		);

		expect( screen.getByRole( 'combobox' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Choose folder' ).length ).toBeGreaterThan(
			0
		);
		expect( screen.getByText( 'Reports' ) ).toBeInTheDocument();
		expect( screen.getByText( '— 2026' ) ).toBeInTheDocument();
	} );

	it( 'emits null for placeholder and folder id for selection', () => {
		const onChange = jest.fn();

		render(
			<UploadFolderSelect
				folders={ [ { id: 9, name: 'Uploads', children: [] } ] }
				value={ 9 }
				onChange={ onChange }
			/>
		);

		fireEvent.change( screen.getByRole( 'combobox' ), {
			target: { value: '' },
		} );
		expect( onChange ).toHaveBeenCalledWith( null );

		fireEvent.change( screen.getByRole( 'combobox' ), {
			target: { value: '9' },
		} );
		expect( onChange ).toHaveBeenCalledWith( 9 );
	} );

	it( 'can be disabled', () => {
		render(
			<UploadFolderSelect
				folders={ [] }
				value={ null }
				onChange={ jest.fn() }
				disabled
			/>
		);

		expect( screen.getByRole( 'combobox' ) ).toBeDisabled();
	} );
} );
