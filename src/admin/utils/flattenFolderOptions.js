/**
 * Flatten a hierarchical folder tree into select options.
 *
 * @param {Array}  nodes Folder nodes with optional children.
 * @param {number} depth Current depth (0 = root).
 * @return {Array<{ id: number, name: string, depth: number }>} Flat options.
 */
export function flattenFolderOptions( nodes, depth = 0 ) {
	const options = [];

	( nodes || [] ).forEach( ( node ) => {
		if ( ! node || typeof node.id === 'undefined' ) {
			return;
		}

		options.push( {
			id: Number( node.id ),
			name: node.name || '',
			depth,
		} );

		if ( node.children?.length ) {
			options.push( ...flattenFolderOptions( node.children, depth + 1 ) );
		}
	} );

	return options;
}
