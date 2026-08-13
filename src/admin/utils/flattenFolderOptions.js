/**
 * Flatten a hierarchical folder tree into select / token options.
 *
 * @param {Array}  nodes   Folder nodes with optional children.
 * @param {number} depth   Current depth (0 = root).
 * @param {string} pathPrefix Parent path label (without trailing separator).
 * @return {Array<{ id: number, name: string, depth: number, path: string, is_private: boolean }>} Flat options.
 */
export function flattenFolderOptions( nodes, depth = 0, pathPrefix = '' ) {
	const options = [];

	( nodes || [] ).forEach( ( node ) => {
		if ( ! node || typeof node.id === 'undefined' ) {
			return;
		}

		// Skip virtual system roots (All / Uncategorized) from assignable lists.
		if ( node.is_system_root ) {
			if ( node.children?.length ) {
				options.push(
					...flattenFolderOptions( node.children, depth, pathPrefix )
				);
			}
			return;
		}

		const name = node.name || '';
		const path = pathPrefix ? `${ pathPrefix } › ${ name }` : name;

		options.push( {
			id: Number( node.id ),
			name,
			depth,
			path,
			is_private: !! node.is_private,
		} );

		if ( node.children?.length ) {
			options.push(
				...flattenFolderOptions( node.children, depth + 1, path )
			);
		}
	} );

	return options;
}
