/**
 * Nested folder tree (Rudrastyh-style).
 */

import { __ } from '@wordpress/i18n';

const CHEVRON = (
	<svg
		xmlns="http://www.w3.org/2000/svg"
		width="26"
		height="26"
		viewBox="0 0 24 24"
		aria-hidden="true"
		focusable="false"
	>
		<path d="M10.8622 8.04053L14.2805 12.0286L10.8622 16.0167L9.72327 15.0405L12.3049 12.0286L9.72327 9.01672L10.8622 8.04053Z" />
	</svg>
);

/**
 * Folder tree list.
 *
 * @param {Object}      props                Props.
 * @param {Array}       props.nodes          Folder nodes.
 * @param {Object}      props.summary        All/uncategorized counts.
 * @param {number|null} props.selectedFolder Selected folder.
 * @param {Object}      props.expanded       Expanded map.
 * @param {boolean}     props.showCount      Whether to show counts.
 * @param {boolean}     props.showSearch     Whether to show search.
 * @param {string}      props.search         Search value.
 * @param {Function}    props.onSearch       Search handler.
 * @param {Function}    props.onSelect       Select handler.
 * @param {Function}    props.onToggle       Expand toggle handler.
 * @return {JSX.Element} Tree.
 */
export default function FolderTree( {
	nodes,
	summary,
	selectedFolder,
	expanded,
	showCount,
	showSearch,
	search,
	onSearch,
	onSelect,
	onToggle,
} ) {
	const hasTree = ( nodes || [] ).some(
		( node ) => ( node.children || [] ).length > 0
	);

	return (
		<div className="media-manager-sidebar__navigation">
			<ul className="media-manager-folders-toplevel media-manager-folders-toplevel--main-nav">
				<li>
					<span
						role="button"
						tabIndex={ 0 }
						className={
							[
								'media-manager-folder-nav',
								'media-manager-folder-nav--all',
								'dashicons-before',
								showCount
									? 'media-manager-folder-nav--with-count'
									: '',
								selectedFolder === null ? 'is-current' : '',
							]
								.filter( Boolean )
								.join( ' ' )
						}
						onClick={ () => onSelect( null ) }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Enter' || event.key === ' ' ) {
								event.preventDefault();
								onSelect( null );
							}
						} }
					>
						{ __( 'All files', 'media-manager' ) }
						{ showCount && (
							<span className="media-manager-folder-nav__count">
								{ summary?.all_count || 0 }
							</span>
						) }
					</span>
				</li>
				<li>
					<span
						role="button"
						tabIndex={ 0 }
						data-id="0"
						className={
							[
								'media-manager-folder-nav',
								'dashicons-before',
								showCount
									? 'media-manager-folder-nav--with-count'
									: '',
								selectedFolder === 0 ? 'is-current' : '',
							]
								.filter( Boolean )
								.join( ' ' )
						}
						onClick={ () => onSelect( 0 ) }
						onKeyDown={ ( event ) => {
							if ( event.key === 'Enter' || event.key === ' ' ) {
								event.preventDefault();
								onSelect( 0 );
							}
						} }
					>
						{ __( 'Uncategorized', 'media-manager' ) }
						{ showCount && (
							<span className="media-manager-folder-nav__count">
								{ summary?.uncategorized_count || 0 }
							</span>
						) }
					</span>
				</li>
			</ul>

			{ showSearch && (
				<div className="media-manager-search">
					<span
						className="dashicons dashicons-search"
						aria-hidden="true"
					/>
					<input
						type="search"
						className="search"
						placeholder={
							__( 'Search folders', 'media-manager' ) + '…'
						}
						value={ search }
						onChange={ ( event ) =>
							onSearch( event.target.value )
						}
						autoComplete="off"
					/>
				</div>
			) }

			{ nodes.length > 0 && (
				<ul
					className={
						hasTree
							? 'media-manager-folders-toplevel media-manager-folders-ul media-manager-folders-ul--tree'
							: 'media-manager-folders-toplevel media-manager-folders-ul'
					}
				>
					{ nodes.map( ( node ) => (
						<FolderNode
							key={ node.id }
							node={ node }
							selectedFolder={ selectedFolder }
							expanded={ expanded }
							showCount={ showCount }
							onSelect={ onSelect }
							onToggle={ onToggle }
						/>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * Single folder node.
 *
 * @param {Object} props Props.
 * @return {JSX.Element} Node.
 */
function FolderNode( {
	node,
	selectedFolder,
	expanded,
	showCount,
	onSelect,
	onToggle,
} ) {
	const hasChildren = ( node.children || [] ).length > 0;
	const isExpanded = !! expanded[ node.id ];
	const isSelected = selectedFolder === node.id;

	let className = 'media-manager-folder-nav dashicons-before';
	if ( showCount ) {
		className += ' media-manager-folder-nav--with-count';
	}
	if ( hasChildren ) {
		className += ' media-manager-folder-nav--has-children';
	}
	if ( isExpanded ) {
		className += ' is-opened';
	}
	if ( isSelected ) {
		className += ' is-current';
	}

	return (
		<li data-count={ node.count }>
			<span
				role="button"
				tabIndex={ 0 }
				data-id={ node.id }
				className={ className }
				onClick={ () => onSelect( node.id ) }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						onSelect( node.id );
					}
				} }
			>
				{ hasChildren && (
					<span
						className="media-manager-folder-nav__open-close"
						onClick={ ( event ) => {
							event.preventDefault();
							event.stopPropagation();
							onToggle( node.id );
						} }
					>
						{ CHEVRON }
					</span>
				) }
				<span className="media-manager-folder-nav__name">
					{ node.name }
					{ node.access_mode &&
						node.access_mode !== 'unrestricted' && (
							<span
								className="media-manager-folder-nav__badge media-manager-folder-nav__restricted dashicons dashicons-lock"
								aria-label={ __(
									'Restricted folder',
									'media-manager'
								) }
								title={ __(
									'Restricted folder',
									'media-manager'
								) }
							/>
						) }
					{ !! node.is_private && (
						<span
							className="media-manager-folder-nav__badge media-manager-folder-nav__private dashicons dashicons-hidden"
							aria-label={ __(
								'Private folder',
								'media-manager'
							) }
							title={ __( 'Private folder', 'media-manager' ) }
						/>
					) }
				</span>
				{ showCount && (
					<span className="media-manager-folder-nav__count">
						{ node.count }
					</span>
				) }
			</span>
			{ hasChildren && isExpanded && (
				<ul className="media-manager-folders-children">
					{ node.children.map( ( child ) => (
						<FolderNode
							key={ child.id }
							node={ child }
							selectedFolder={ selectedFolder }
							expanded={ expanded }
							showCount={ showCount }
							onSelect={ onSelect }
							onToggle={ onToggle }
						/>
					) ) }
				</ul>
			) }
		</li>
	);
}
