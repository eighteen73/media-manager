/**
 * Strip system-root wrappers so only assignable folders remain.
 *
 * @param {Array} nodes Folder tree nodes.
 * @return {Array} Assignable tree.
 */
export function assignableFolderTree( nodes ) {
	const result = [];

	( nodes || [] ).forEach( ( node ) => {
		if ( ! node || typeof node.id === 'undefined' ) {
			return;
		}

		if ( node.is_system_root ) {
			result.push( ...assignableFolderTree( node.children || [] ) );
			return;
		}

		result.push( {
			...node,
			children: assignableFolderTree( node.children || [] ),
		} );
	} );

	return result;
}
