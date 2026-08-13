<?php
/**
 * Attachment edit screen folder metabox.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Admin;

use Eighteen73\MediaManager\Permissions\AccessPolicy;
use Eighteen73\MediaManager\Permissions\EffectiveAcl;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the assign-only Folders metabox on attachment edit screens.
 */
class AttachmentEdit {

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Effective ACL.
	 *
	 * @var EffectiveAcl
	 */
	private EffectiveAcl $acl;

	/**
	 * Constructor.
	 *
	 * @param AccessPolicy|null $access Optional access policy.
	 * @param EffectiveAcl|null $acl    Optional ACL.
	 */
	public function __construct( ?AccessPolicy $access = null, ?EffectiveAcl $acl = null ) {
		$this->access = $access ?? new AccessPolicy();
		$this->acl    = $acl ?? new EffectiveAcl();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes_attachment', [ $this, 'register_metabox' ] );
	}

	/**
	 * Register the Folders metabox when the user can assign.
	 *
	 * @return void
	 */
	public function register_metabox(): void {
		if ( ! $this->access->can_assign_folders() ) {
			return;
		}

		add_meta_box(
			'media-manager-folders-metabox',
			__( 'Folders', 'media-manager' ),
			[ $this, 'render_metabox' ],
			'attachment',
			'side',
			'default'
		);
	}

	/**
	 * Render the React mount root for the folder field.
	 *
	 * @param \WP_Post $post Attachment post.
	 * @return void
	 */
	public function render_metabox( $post ): void {
		$attachment_id = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$folder_ids    = $attachment_id > 0
			? $this->acl->visible_attachment_folder_ids( $attachment_id )
			: [];

		printf(
			'<div id="media-manager-attachment-folders" class="categorydiv" data-attachment-id="%d" data-folder-ids="%s"></div>',
			(int) $attachment_id,
			esc_attr( wp_json_encode( array_values( array_map( 'intval', $folder_ids ) ) ) )
		);
	}
}
