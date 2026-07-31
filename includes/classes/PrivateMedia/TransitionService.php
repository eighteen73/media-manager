<?php
/**
 * Private media transition journal and moves.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\PrivateMedia;

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Permissions\AclCache;
use Eighteen73\MediaManager\Permissions\FolderPolicy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Moves attachments between public uploads and private storage.
 */
class TransitionService {

	/**
	 * Journal post meta key.
	 *
	 * @var string
	 */
	public const META_JOURNAL = '_media_manager_private_transition';

	/**
	 * Attachment private flag meta.
	 *
	 * @var string
	 */
	public const META_IS_PRIVATE = '_media_manager_is_private';

	/**
	 * Opaque delivery token meta.
	 *
	 * @var string
	 */
	public const META_TOKEN = '_media_manager_private_token';

	/**
	 * Private storage.
	 *
	 * @var PrivateStorage
	 */
	private PrivateStorage $storage;

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Folder policy.
	 *
	 * @var FolderPolicy
	 */
	private FolderPolicy $policy;

	/**
	 * Constructor.
	 *
	 * @param PrivateStorage|null   $storage    Optional storage.
	 * @param FolderRepository|null $repository Optional repository.
	 * @param FolderPolicy|null     $policy     Optional policy.
	 */
	public function __construct(
		?PrivateStorage $storage = null,
		?FolderRepository $repository = null,
		?FolderPolicy $policy = null
	) {
		$this->storage    = $storage ?? new PrivateStorage();
		$this->repository = $repository ?? new FolderRepository();
		$this->policy     = $policy ?? new FolderPolicy( $this->repository );
	}

	/**
	 * Whether an attachment is currently private.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_private( int $attachment_id ): bool {
		return (bool) get_post_meta( $attachment_id, self::META_IS_PRIVATE, true );
	}

	/**
	 * Get or create a delivery token.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function token( int $attachment_id ): string {
		$token = (string) get_post_meta( $attachment_id, self::META_TOKEN, true );

		if ( '' === $token ) {
			$token = wp_generate_password( 48, false, false );
			update_post_meta( $attachment_id, self::META_TOKEN, $token );
		}

		return $token;
	}

	/**
	 * Rotate the delivery token.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function rotate_token( int $attachment_id ): string {
		$token = wp_generate_password( 48, false, false );
		update_post_meta( $attachment_id, self::META_TOKEN, $token );
		return $token;
	}

	/**
	 * Current journal or null.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null
	 */
	public function journal( int $attachment_id ) {
		$journal = get_post_meta( $attachment_id, self::META_JOURNAL, true );

		return is_array( $journal ) ? $journal : null;
	}

	/**
	 * Whether a transition is incomplete/failed and blocks further transitions.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function is_blocked( int $attachment_id ): bool {
		$journal = $this->journal( $attachment_id );

		if ( ! $journal ) {
			return false;
		}

		$phase = $journal['phase'] ?? '';

		// Only in-flight phases block. Failed journals must be retryable.
		return in_array( $phase, [ 'queued', 'copying', 'verifying', 'committing' ], true );
	}

	/**
	 * Move an attachment into private storage and assign exclusively to a private folder.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $folder_id     Private folder ID.
	 * @return true|WP_Error
	 */
	public function enter_private( int $attachment_id, int $folder_id ) {
		$policy = $this->policy->get_effective( $folder_id );

		if ( is_wp_error( $policy ) ) {
			return $policy;
		}

		if ( empty( $policy['is_private'] ) ) {
			return new WP_Error(
				'media_manager_not_private_folder',
				__( 'Target folder is not private.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$this->repair_attached_file_meta( $attachment_id );

		// Already private: keep exclusive membership and clear a stale failed journal.
		if ( $this->is_private( $attachment_id ) ) {
			$this->clear_completed_journal( $attachment_id );
			$result = $this->repository->set_attachment_folders( $attachment_id, [ $folder_id ], false );
			return is_wp_error( $result ) ? $result : true;
		}

		if ( $this->is_blocked( $attachment_id ) ) {
			return new WP_Error(
				'media_manager_transition_blocked',
				__( 'This media item has an incomplete private transition and cannot be moved yet.', 'media-manager' ),
				[ 'status' => 409 ]
			);
		}

		// Retry after a failed attempt: drop the stale journal first.
		$this->clear_completed_journal( $attachment_id );

		$files = $this->attachment_files( $attachment_id );

		if ( is_wp_error( $files ) ) {
			// Files may already live in private storage from a prior partial move.
			$recovered = $this->recover_private_if_present( $attachment_id, $folder_id );
			return is_wp_error( $recovered ) ? $files : $recovered;
		}

		$lock = wp_generate_password( 12, false, false );
		$this->write_journal(
			$attachment_id,
			[
				'requested'  => 'private',
				'observed'   => 'public',
				'phase'      => 'queued',
				'lock'       => $lock,
				'folder_id'  => $folder_id,
				'files'      => $files,
				'error'      => '',
				'updated_at' => time(),
			]
		);

		$this->write_journal( $attachment_id, [ 'phase' => 'copying' ] );

		$copied = [];

		foreach ( $files as $relative => $source ) {
			$result = $this->storage->copy_from( $source, $relative );
			if ( is_wp_error( $result ) ) {
				$this->rollback_private_copies( $copied );
				$this->fail( $attachment_id, $result->get_error_message() );
				return $result;
			}
			$copied[] = $relative;
		}

		$this->write_journal(
			$attachment_id,
			[
				'phase'    => 'verifying',
				'observed' => 'mixed',
			]
		);

		foreach ( $files as $relative => $source ) {
			$dest = $this->storage->absolute_path( $relative );
			if ( is_wp_error( $dest ) || ! is_file( $dest ) || filesize( $dest ) !== filesize( $source ) ) {
				$this->rollback_private_copies( $copied );
				$error = new WP_Error(
					'media_manager_private_verify_failed',
					__( 'Private storage verification failed.', 'media-manager' ),
					[ 'status' => 500 ]
				);
				$this->fail( $attachment_id, $error->get_error_message() );
				return $error;
			}
		}

		$this->write_journal( $attachment_id, [ 'phase' => 'committing' ] );

		foreach ( $files as $relative => $source ) {
			$real = realpath( $source );
			if ( $real && is_file( $real ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $real );
			}
		}

		update_post_meta( $attachment_id, self::META_IS_PRIVATE, 1 );
		$this->token( $attachment_id );

		$assigned = $this->repository->set_attachment_folders( $attachment_id, [ $folder_id ], false );
		if ( is_wp_error( $assigned ) ) {
			$this->fail( $attachment_id, $assigned->get_error_message() );
			return $assigned;
		}

		$this->write_journal(
			$attachment_id,
			[
				'phase'      => 'completed',
				'observed'   => 'private',
				'error'      => '',
				'updated_at' => time(),
			]
		);

		AclCache::invalidate_site();

		return true;
	}

	/**
	 * Move an attachment from private storage back to public uploads.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param int[] $folder_ids    Public folder IDs to assign (may be empty).
	 * @return true|WP_Error
	 */
	public function leave_private( int $attachment_id, array $folder_ids = [] ) {
		$this->repair_attached_file_meta( $attachment_id );

		if ( ! $this->is_private( $attachment_id ) ) {
			$this->clear_completed_journal( $attachment_id );
			$result = $this->repository->set_attachment_folders( $attachment_id, $folder_ids, false );
			return is_wp_error( $result ) ? $result : true;
		}

		if ( $this->is_blocked( $attachment_id ) ) {
			return new WP_Error(
				'media_manager_transition_blocked',
				__( 'This media item has an incomplete private transition and cannot be moved yet.', 'media-manager' ),
				[ 'status' => 409 ]
			);
		}

		foreach ( $folder_ids as $folder_id ) {
			$policy = $this->policy->get_effective( (int) $folder_id );
			if ( is_wp_error( $policy ) ) {
				return $policy;
			}
			if ( ! empty( $policy['is_private'] ) ) {
				return new WP_Error(
					'media_manager_still_private_folder',
					__( 'Leaving private storage requires a public folder target (or Uncategorized).', 'media-manager' ),
					[ 'status' => 400 ]
				);
			}
		}

		$files   = $this->attachment_relative_files( $attachment_id );
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error(
				'media_manager_uploads_unavailable',
				(string) $uploads['error'],
				[ 'status' => 500 ]
			);
		}

		$lock = wp_generate_password( 12, false, false );
		$this->write_journal(
			$attachment_id,
			[
				'requested'  => 'public',
				'observed'   => 'private',
				'phase'      => 'queued',
				'lock'       => $lock,
				'folder_ids' => $folder_ids,
				'files'      => $files,
				'error'      => '',
				'updated_at' => time(),
			]
		);

		$this->write_journal( $attachment_id, [ 'phase' => 'copying' ] );

		$copied = [];

		foreach ( $files as $relative ) {
			$dest   = trailingslashit( $uploads['basedir'] ) . ltrim( $relative, '/' );
			$result = $this->storage->copy_to_public( $relative, $dest );
			if ( is_wp_error( $result ) ) {
				foreach ( $copied as $path ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $path );
				}
				$this->fail( $attachment_id, $result->get_error_message() );
				return $result;
			}
			$copied[] = $dest;
		}

		$this->write_journal(
			$attachment_id,
			[
				'phase'    => 'committing',
				'observed' => 'mixed',
			]
		);

		foreach ( $files as $relative ) {
			$deleted = $this->storage->delete( $relative );
			if ( is_wp_error( $deleted ) ) {
				$this->fail( $attachment_id, $deleted->get_error_message() );
				return $deleted;
			}
		}

		delete_post_meta( $attachment_id, self::META_IS_PRIVATE );
		$this->rotate_token( $attachment_id );

		$assigned = $this->repository->set_attachment_folders( $attachment_id, $folder_ids, false );
		if ( is_wp_error( $assigned ) ) {
			$this->fail( $attachment_id, $assigned->get_error_message() );
			return $assigned;
		}

		$this->write_journal(
			$attachment_id,
			[
				'phase'      => 'completed',
				'observed'   => 'public',
				'error'      => '',
				'updated_at' => time(),
			]
		);

		AclCache::invalidate_site();

		return true;
	}

	/**
	 * Mark an attachment as private after a direct private upload (no public source).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $folder_id     Private folder ID.
	 * @return true|WP_Error
	 */
	public function mark_uploaded_private( int $attachment_id, int $folder_id ) {
		update_post_meta( $attachment_id, self::META_IS_PRIVATE, 1 );
		$this->token( $attachment_id );
		$result = $this->repository->set_attachment_folders( $attachment_id, [ $folder_id ], false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		AclCache::invalidate_site();
		return true;
	}

	/**
	 * Transition every attachment in a folder when the folder's private flag changes.
	 *
	 * @param int  $folder_id    Folder ID.
	 * @param bool $make_private Whether the folder is becoming private.
	 * @return true|WP_Error
	 */
	public function sync_folder_privacy( int $folder_id, bool $make_private ) {
		$attachment_ids = $this->repository->attachment_ids_in_folder( $folder_id );
		$errors         = [];

		foreach ( $attachment_ids as $attachment_id ) {
			if ( $make_private ) {
				$result = $this->enter_private( $attachment_id, $folder_id );
			} else {
				$result = $this->leave_private( $attachment_id, [ $folder_id ] );
			}

			if ( is_wp_error( $result ) ) {
				$errors[ $attachment_id ] = $result->get_error_message();
			}
		}

		if ( ! empty( $errors ) ) {
			$first_id      = (int) array_key_first( $errors );
			$first_message = (string) $errors[ $first_id ];
			$message       = sprintf(
				/* translators: 1: attachment ID, 2: error message */
				__( 'Some media items could not be moved with the folder privacy change. First error (#%1$d): %2$s', 'media-manager' ),
				$first_id,
				$first_message
			);

			return new WP_Error(
				'media_manager_folder_privacy_partial',
				$message,
				[
					'status' => 409,
					'errors' => $errors,
				]
			);
		}

		return true;
	}

	/**
	 * Normalise an attached-file path to uploads-relative form.
	 *
	 * Image edits against private storage can persist absolute private paths into
	 * `_wp_attached_file`; transitions must read the relative key instead.
	 *
	 * @param string $path Absolute or relative path.
	 * @return string Relative path (YYYY/MM/file.ext) when recognised, else original.
	 */
	public function normalize_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );
		if ( '' === $path ) {
			return '';
		}

		$prefixes = [];

		$site_root = $this->storage->site_root();
		if ( ! is_wp_error( $site_root ) ) {
			$prefixes[] = untrailingslashit( str_replace( '\\', '/', (string) $site_root ) );
		}

		$prefixes[] = untrailingslashit( str_replace( '\\', '/', $this->storage->legacy_site_root() ) );

		$config_path = ( new StorageConfig() )->path();
		if ( is_string( $config_path ) && '' !== $config_path ) {
			$prefixes[] = untrailingslashit( str_replace( '\\', '/', $config_path ) );
		}

		$uploads = wp_upload_dir();
		if ( empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$prefixes[] = untrailingslashit( str_replace( '\\', '/', (string) $uploads['basedir'] ) );
		}

		foreach ( $prefixes as $prefix ) {
			if ( '' === $prefix ) {
				continue;
			}

			$candidates = array_unique(
				[
					$prefix,
					ltrim( $prefix, '/' ),
				]
			);

			foreach ( $candidates as $candidate ) {
				if ( '' === $candidate ) {
					continue;
				}
				if ( $path === $candidate ) {
					return '';
				}
				if ( str_starts_with( $path, $candidate . '/' ) ) {
					return $this->strip_private_site_prefix(
						ltrim( substr( $path, strlen( $candidate ) ), '/' )
					);
				}
			}
		}

		// Already relative (no leading slash / drive letter): still strip site prefixes.
		if ( ! str_starts_with( $path, '/' ) && ! preg_match( '#^[A-Za-z]:/#', $path ) ) {
			return $this->strip_private_site_prefix( $path );
		}

		return $path;
	}

	/**
	 * Repair `_wp_attached_file` when it stores an absolute private/uploads path.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Normalised relative path.
	 */
	public function repair_attached_file_meta( int $attachment_id ): string {
		$raw = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		$normalized = $this->normalize_relative_path( $raw );
		if ( '' !== $normalized && $normalized !== $raw ) {
			update_post_meta( $attachment_id, '_wp_attached_file', $normalized );
		}

		return $normalized;
	}

	/**
	 * Resolve an attachment ID from an opaque delivery token.
	 *
	 * @param string $token Opaque token.
	 * @return int
	 */
	public function attachment_id_from_token( string $token ): int {
		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				self::META_TOKEN,
				$token
			)
		);

		return absint( $attachment_id );
	}

	/**
	 * Strip multisite private site prefixes from a relative path.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private function strip_private_site_prefix( string $relative ): string {
		if ( preg_match( '#^sites/\d+/(.*)$#', $relative, $matches ) ) {
			return $matches[1];
		}
		if ( preg_match( '#^site-\d+/(.*)$#', $relative, $matches ) ) {
			return $matches[1];
		}

		return $relative;
	}

	/**
	 * Absolute file map relative => absolute for a public attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, string>|WP_Error
	 */
	private function attachment_files( int $attachment_id ) {
		$relative = $this->repair_attached_file_meta( $attachment_id );
		$uploads  = wp_upload_dir();
		$basedir  = trailingslashit( $uploads['basedir'] );
		$file     = $relative ? $basedir . $relative : get_attached_file( $attachment_id, true );

		if ( ! $file || ! is_readable( $file ) ) {
			return new WP_Error(
				'media_manager_missing_attachment_file',
				__( 'Attachment file is missing.', 'media-manager' ),
				[ 'status' => 404 ]
			);
		}

		$relative = $this->normalize_relative_path( (string) $file );
		if ( '' === $relative ) {
			$relative = ltrim( str_replace( $basedir, '', (string) $file ), '/' );
		}
		$files = [ $relative => $file ];

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			$dir = trailingslashit( dirname( $relative ) );
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$size_rel = ( '.' === dirname( $relative ) ? '' : $dir ) . $size['file'];
				$size_rel = ltrim( str_replace( '\\', '/', $size_rel ), '/' );
				$abs      = $basedir . $size_rel;
				if ( is_readable( $abs ) ) {
					$files[ $size_rel ] = $abs;
				}
			}
		}

		if ( ! empty( $meta['original_image'] ) ) {
			$dir      = trailingslashit( dirname( $relative ) );
			$orig_rel = ( '.' === dirname( $relative ) ? '' : $dir ) . $meta['original_image'];
			$orig_rel = ltrim( str_replace( '\\', '/', $orig_rel ), '/' );
			$abs      = $basedir . $orig_rel;
			if ( is_readable( $abs ) ) {
				$files[ $orig_rel ] = $abs;
			}
		}

		return $files;
	}

	/**
	 * Relative paths for a private attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	private function attachment_relative_files( int $attachment_id ): array {
		$file  = $this->repair_attached_file_meta( $attachment_id );
		$files = $file ? [ $file ] : [];

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) && $file ) {
			$dir = trailingslashit( dirname( $file ) );
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}
				$files[] = ltrim( ( '.' === dirname( $file ) ? '' : $dir ) . $size['file'], '/' );
			}
		}

		if ( ! empty( $meta['original_image'] ) && $file ) {
			$dir     = trailingslashit( dirname( $file ) );
			$files[] = ltrim( ( '.' === dirname( $file ) ? '' : $dir ) . $meta['original_image'], '/' );
		}

		return array_values( array_unique( $files ) );
	}

	/**
	 * Merge journal updates.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $patch         Patch.
	 * @return void
	 */
	private function write_journal( int $attachment_id, array $patch ): void {
		$current             = $this->journal( $attachment_id );
		$current             = is_array( $current ) ? $current : [];
		$patch['updated_at'] = $patch['updated_at'] ?? time();
		update_post_meta( $attachment_id, self::META_JOURNAL, array_merge( $current, $patch ) );
	}

	/**
	 * Mark transition failed.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $message       Error message.
	 * @return void
	 */
	private function fail( int $attachment_id, string $message ): void {
		$this->write_journal(
			$attachment_id,
			[
				'phase' => 'failed',
				'error' => $message,
			]
		);
	}

	/**
	 * Drop a completed/stale journal so later saves are not blocked.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function clear_completed_journal( int $attachment_id ): void {
		$journal = $this->journal( $attachment_id );
		if ( ! $journal ) {
			return;
		}

		$phase = $journal['phase'] ?? '';
		if ( in_array( $phase, [ 'completed', 'failed', '' ], true ) ) {
			delete_post_meta( $attachment_id, self::META_JOURNAL );
		}
	}

	/**
	 * If public files are gone but private copies exist, mark the attachment private.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $folder_id     Private folder ID.
	 * @return true|WP_Error
	 */
	private function recover_private_if_present( int $attachment_id, int $folder_id ) {
		$relative = $this->repair_attached_file_meta( $attachment_id );
		if ( '' === $relative ) {
			return new WP_Error(
				'media_manager_missing_attachment_file',
				__( 'Attachment file is missing.', 'media-manager' ),
				[ 'status' => 404 ]
			);
		}

		$path = $this->storage->resolve_existing_path( $relative );
		if ( is_wp_error( $path ) || ! is_file( $path ) ) {
			return new WP_Error(
				'media_manager_missing_attachment_file',
				__( 'Attachment file is missing.', 'media-manager' ),
				[ 'status' => 404 ]
			);
		}

		update_post_meta( $attachment_id, self::META_IS_PRIVATE, 1 );
		$this->token( $attachment_id );
		$this->clear_completed_journal( $attachment_id );

		$assigned = $this->repository->set_attachment_folders( $attachment_id, [ $folder_id ], false );
		if ( is_wp_error( $assigned ) ) {
			return $assigned;
		}

		AclCache::invalidate_site();

		return true;
	}

	/**
	 * Remove partially copied private files.
	 *
	 * @param string[] $relative_paths Relative paths.
	 * @return void
	 */
	private function rollback_private_copies( array $relative_paths ): void {
		foreach ( $relative_paths as $relative ) {
			$this->storage->delete( $relative );
		}
	}
}
