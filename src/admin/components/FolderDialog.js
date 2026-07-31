/**
 * Folder CRUD dialog (Name, Parent, Restrict Access, Private).
 */

import { useState, useEffect, createPortal, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Flatten folder tree for parent select options.
 *
 * @param {Array}  nodes   Folder nodes.
 * @param {number} depth   Depth.
 * @param {number} exclude Excluded folder ID.
 * @return {Array} Options.
 */
function flattenOptions(nodes, depth = 0, exclude = 0) {
	const options = [];

	(nodes || []).forEach((node) => {
		if (node.id === exclude) {
			return;
		}
		options.push({
			id: node.id,
			label: `${'— '.repeat(depth)}${node.name}`,
			isPrivate: !!node.is_private,
		});
		options.push(
			...flattenOptions(node.children || [], depth + 1, exclude)
		);
	});

	return options;
}

/**
 * Resolve boot config.
 *
 * @return {Object} Boot config.
 */
function getBoot() {
	return (
		window.mediaManagerAdmin || {
			capabilities: {},
			roles: [],
			privateStorageReady: false,
			privateStorageMessage: '',
		}
	);
}

/**
 * Create/rename folder dialog.
 *
 * @param {Object}   props Props.
 * @return {JSX.Element|null} Dialog.
 */
export default function FolderDialog({
	isOpen,
	title,
	submitLabel,
	initialName = '',
	initialParent = 0,
	initialAccessMode = 'unrestricted',
	initialAllowedRoles = [],
	initialAllowedUsernames = [],
	initialIsPrivate = false,
	lockStructure = false,
	folders = [],
	excludeId = 0,
	onClose,
	onSubmit,
}) {
	const boot = useMemo(() => getBoot(), []);
	const [name, setName] = useState(initialName);
	const [parent, setParent] = useState(initialParent);
	const [accessMode, setAccessMode] = useState(initialAccessMode);
	const [allowedRoles, setAllowedRoles] = useState(initialAllowedRoles);
	const [allowedUsernames, setAllowedUsernames] = useState(
		(initialAllowedUsernames || []).join('\n')
	);
	const [isPrivate, setIsPrivate] = useState(initialIsPrivate);
	const [isBusy, setIsBusy] = useState(false);
	const [submitError, setSubmitError] = useState('');

	useEffect(() => {
		if (isOpen) {
			setName(initialName);
			setParent(initialParent);
			setAccessMode(initialAccessMode || 'unrestricted');
			setAllowedRoles(initialAllowedRoles || []);
			setAllowedUsernames((initialAllowedUsernames || []).join('\n'));
			setIsPrivate(!!initialIsPrivate);
			setIsBusy(false);
			setSubmitError('');
		}
	}, [
		isOpen,
		initialName,
		initialParent,
		initialAccessMode,
		initialAllowedRoles,
		initialAllowedUsernames,
		initialIsPrivate,
	]);

	if (!isOpen) {
		return null;
	}

	const options = flattenOptions(folders, 0, excludeId);
	const parentOption = options.find(
		(option) => String(option.id) === String(parent)
	);
	const parentForcesPrivate = !!parentOption?.isPrivate;
	const canManagePrivate = !!boot.capabilities?.private;
	const storageReady = !!boot.privateStorageReady;
	const privateDisabled =
		!canManagePrivate ||
		(!storageReady && !isPrivate) ||
		parentForcesPrivate;

	const submit = async (event) => {
		event.preventDefault();
		if (!name.trim()) {
			return;
		}

		if (isPrivate && !initialIsPrivate) {
			const ok = window.confirm(
				__(
					'Private folders store files outside the public web root. Media moved into this folder will lose other public folder relationships. Continue?',
					'media-manager'
				)
			);
			if (!ok) {
				return;
			}
		}

		if (!isPrivate && initialIsPrivate) {
			const ok = window.confirm(
				__(
					'Turning off Private makes folder media publicly reachable again after they leave private storage. Continue?',
					'media-manager'
				)
			);
			if (!ok) {
				return;
			}
		}

		setIsBusy(true);
		setSubmitError('');
		try {
			await onSubmit({
				name: lockStructure ? initialName : name.trim(),
				parent: lockStructure
					? Number(initialParent) || 0
					: Number(parent) || 0,
				access_mode: accessMode,
				allowed_roles: accessMode === 'roles' ? allowedRoles : [],
				allowed_usernames:
					accessMode === 'users'
						? allowedUsernames
								.split('\n')
								.map((line) => line.trim())
								.filter(Boolean)
						: [],
				is_private: parentForcesPrivate ? true : !!isPrivate,
			});
			onClose();
		} catch (error) {
			setSubmitError(
				error?.message || __('Unable to save folder.', 'media-manager')
			);
			setIsBusy(false);
		}
	};

	const toggleRole = (slug) => {
		setAllowedRoles((current) =>
			current.includes(slug)
				? current.filter((role) => role !== slug)
				: [...current, slug]
		);
	};

	return createPortal(
		<>
			<div
				className="media-manager-modal"
				role="dialog"
				aria-modal="true"
			>
				<div className="media-manager-modal__topbar">
					<h1>{title}</h1>
					<button
						type="button"
						className="media-manager-modal__close dashicons dashicons-no-alt"
						onClick={onClose}
					>
						<span className="screen-reader-text">
							{__('Close modal panel', 'media-manager')}
						</span>
					</button>
				</div>
				<form onSubmit={submit}>
					<div className="media-manager-modal__body">
						{submitError && (
							<div className="notice notice-error inline">
								<p>{submitError}</p>
							</div>
						)}
						<label htmlFor="media-manager-folder-name">
							{__('Name', 'media-manager')}
						</label>
						<fieldset>
							<input
								id="media-manager-folder-name"
								className="input-text regular-input"
								type="text"
								value={name}
								onChange={(event) =>
									setName(event.target.value)
								}
								required
								disabled={lockStructure}
								readOnly={lockStructure}
							/>
							{lockStructure && (
								<p className="description">
									{__(
										'Name and parent are managed automatically for post-type folders.',
										'media-manager'
									)}
								</p>
							)}
						</fieldset>

						<label htmlFor="media-manager-folder-parent">
							{__('Parent Folder', 'media-manager')}
						</label>
						<fieldset>
							<select
								id="media-manager-folder-parent"
								value={parent}
								disabled={lockStructure}
								onChange={(event) => {
									const next = event.target.value;
									setParent(next);
									const option = options.find(
										(item) =>
											String(item.id) === String(next)
									);
									if (option?.isPrivate) {
										setIsPrivate(true);
									}
								}}
							>
								<option value="0">
									{__('None', 'media-manager')}
								</option>
								{options.map((option) => (
									<option key={option.id} value={option.id}>
										{option.label}
									</option>
								))}
							</select>
						</fieldset>

						{canManagePrivate && (
							<>
								<label htmlFor="media-manager-folder-private">
									{__('Private', 'media-manager')}
								</label>
								<fieldset>
									<label className="media-manager-checkbox">
										<input
											id="media-manager-folder-private"
											type="checkbox"
											checked={
												parentForcesPrivate || isPrivate
											}
											disabled={privateDisabled}
											onChange={(event) =>
												setIsPrivate(
													event.target.checked
												)
											}
										/>
										{__(
											'Store media privately',
											'media-manager'
										)}
									</label>
									{!storageReady && (
										<p className="description">
											{boot.privateStorageMessage ||
												__(
													'Configure a healthy private storage path in settings first.',
													'media-manager'
												)}
										</p>
									)}
									{parentForcesPrivate && (
										<p className="description">
											{__(
												'Parent folder is private, so this folder must stay private.',
												'media-manager'
											)}
										</p>
									)}
								</fieldset>
							</>
						)}

						<label htmlFor="media-manager-folder-access">
							{__('Restrict Access', 'media-manager')}
						</label>
						<p className="description">
							{__(
								'Controls who can see and use this folder in the Media Library.',
								'media-manager'
							)}
						</p>
						<fieldset>
							<select
								id="media-manager-folder-access"
								value={accessMode}
								onChange={(event) =>
									setAccessMode(event.target.value)
								}
							>
								<option value="unrestricted">
									{__('No restrictions', 'media-manager')}
								</option>
								<option value="roles">
									{__('By user roles', 'media-manager')}
								</option>
								<option value="users">
									{__('By usernames', 'media-manager')}
								</option>
							</select>
						</fieldset>

						{accessMode === 'roles' && (
							<>
								<label>
									{__('Allowed Roles', 'media-manager')}
								</label>
								<fieldset className="media-manager-role-list">
									{(boot.roles || []).map((role) => (
										<label
											key={role.slug}
											className="media-manager-checkbox"
										>
											<input
												type="checkbox"
												checked={allowedRoles.includes(
													role.slug
												)}
												onChange={() =>
													toggleRole(role.slug)
												}
											/>
											{role.label}
										</label>
									))}
								</fieldset>
							</>
						)}

						{accessMode === 'users' && (
							<>
								<label htmlFor="media-manager-folder-users">
									{__('Allowed Usernames', 'media-manager')}
								</label>
								<fieldset>
									<textarea
										id="media-manager-folder-users"
										className="large-text"
										rows={4}
										value={allowedUsernames}
										onChange={(event) =>
											setAllowedUsernames(
												event.target.value
											)
										}
									/>
									<p className="description">
										{__(
											'You can provide multiple usernames; place each one on a separate line.',
											'media-manager'
										)}
									</p>
								</fieldset>
							</>
						)}
					</div>
					<div className="media-manager-modal__bottombar">
						<button
							type="submit"
							className="button button-primary button-large"
							disabled={isBusy || !name.trim()}
						>
							{submitLabel}
						</button>
					</div>
				</form>
			</div>
			<button
				type="button"
				className="media-manager-modal-backdrop"
				aria-label={__('Close modal panel', 'media-manager')}
				onClick={onClose}
			/>
		</>,
		document.body
	);
}
