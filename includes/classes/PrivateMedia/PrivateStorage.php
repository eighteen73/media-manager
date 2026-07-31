<?php
/**
 * Path-safe private storage operations.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\PrivateMedia;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Site-partitioned private file storage.
 */
class PrivateStorage {

	/**
	 * Storage config.
	 *
	 * @var StorageConfig
	 */
	private StorageConfig $config;

	/**
	 * Constructor.
	 *
	 * @param StorageConfig|null $config Optional config.
	 */
	public function __construct( ?StorageConfig $config = null ) {
		$this->config = $config ?? new StorageConfig();
	}

	/**
	 * Absolute base directory for the current site.
	 *
	 * Single site: private root itself (date folders live directly underneath).
	 * Multisite: {root}/sites/{blog_id}/ mirroring uploads/sites/{id}.
	 *
	 * @return string|WP_Error
	 */
	public function site_root() {
		if ( ! $this->config->is_ready() ) {
			return new WP_Error(
				'media_manager_private_storage_unready',
				__( 'Private storage is not ready.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$base = $this->config->path();
		$root = is_multisite()
			? $base . '/sites/' . get_current_blog_id()
			: $base;

		if ( ! is_dir( $root ) && ! wp_mkdir_p( $root ) ) {
			return new WP_Error(
				'media_manager_private_storage_mkdir',
				__( 'Unable to create the private storage site directory.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$real = realpath( $root );

		if ( false === $real || ! $this->is_contained( $real ) ) {
			return new WP_Error(
				'media_manager_private_storage_path',
				__( 'Private storage site path failed containment checks.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		return $real;
	}

	/**
	 * Legacy site root used by earlier builds (`site-{id}`).
	 *
	 * @return string
	 */
	public function legacy_site_root(): string {
		return untrailingslashit( $this->config->path() ) . '/site-' . get_current_blog_id();
	}

	/**
	 * Absolute path for an attachment-relative file under private storage (preferred layout).
	 *
	 * @param string $relative Relative path (as stored in attachment metadata).
	 * @return string|WP_Error
	 */
	public function absolute_path( string $relative ) {
		$root = $this->site_root();

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return new WP_Error(
				'media_manager_invalid_relative_path',
				__( 'Invalid private media relative path.', 'media-manager' ),
				[ 'status' => 400 ]
			);
		}

		$candidate = $root . '/' . $relative;
		$parent    = dirname( $candidate );

		if ( ! is_dir( $parent ) ) {
			$nearest = $this->nearest_existing_parent( $parent, $root );
			if ( is_wp_error( $nearest ) ) {
				return $nearest;
			}
		}

		return $candidate;
	}

	/**
	 * Resolve an existing private file, falling back to the legacy site-{id} layout.
	 *
	 * @param string $relative Relative path.
	 * @return string|WP_Error Absolute path.
	 */
	public function resolve_existing_path( string $relative ) {
		$preferred = $this->absolute_path( $relative );

		if ( ! is_wp_error( $preferred ) && is_readable( $preferred ) ) {
			$real = realpath( $preferred );
			return false === $real ? $preferred : $real;
		}

		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );
		$legacy   = $this->legacy_site_root() . '/' . $relative;

		if ( is_readable( $legacy ) ) {
			$real = realpath( $legacy );
			if ( false !== $real && $this->is_contained( $real ) ) {
				return $real;
			}
		}

		if ( is_wp_error( $preferred ) ) {
			return $preferred;
		}

		return new WP_Error(
			'media_manager_private_source_missing',
			__( 'Private media file is missing or unreadable.', 'media-manager' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Ensure the directory for a destination path exists and is contained.
	 *
	 * @param string $absolute Absolute destination file path.
	 * @return true|WP_Error
	 */
	public function ensure_directory( string $absolute ) {
		$dir = dirname( $absolute );

		if ( is_dir( $dir ) ) {
			$real = realpath( $dir );
			if ( false === $real || ! $this->is_contained( $real ) ) {
				return new WP_Error(
					'media_manager_private_storage_path',
					__( 'Private storage directory failed containment checks.', 'media-manager' ),
					[ 'status' => 500 ]
				);
			}
			return true;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'media_manager_private_storage_mkdir',
				__( 'Unable to create a private storage directory.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$real = realpath( $dir );
		if ( false === $real || ! $this->is_contained( $real ) ) {
			return new WP_Error(
				'media_manager_private_storage_path',
				__( 'Private storage directory failed containment checks.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		return true;
	}

	/**
	 * Copy a file into private storage and verify size.
	 *
	 * @param string $source   Absolute source path.
	 * @param string $relative Destination relative path.
	 * @return string|WP_Error Absolute destination path.
	 */
	public function copy_from( string $source, string $relative ) {
		$destination = $this->absolute_path( $relative );

		if ( is_wp_error( $destination ) ) {
			return $destination;
		}

		$ensured = $this->ensure_directory( $destination );
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		if ( ! is_readable( $source ) ) {
			return new WP_Error(
				'media_manager_private_source_missing',
				__( 'Source media file is missing or unreadable.', 'media-manager' ),
				[ 'status' => 404 ]
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		if ( ! @copy( $source, $destination ) ) {
			return new WP_Error(
				'media_manager_private_copy_failed',
				__( 'Unable to copy media into private storage.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$expected = filesize( $source );
		$actual   = filesize( $destination );

		if ( false === $expected || false === $actual || (int) $expected !== (int) $actual ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $destination );
			return new WP_Error(
				'media_manager_private_verify_failed',
				__( 'Private storage copy failed verification.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$real = realpath( $destination );
		if ( false === $real || ! $this->is_contained( $real ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $destination );
			return new WP_Error(
				'media_manager_private_storage_path',
				__( 'Private destination failed containment checks.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		return $real;
	}

	/**
	 * Copy a private file back to a public destination.
	 *
	 * @param string $relative Private relative path.
	 * @param string $dest     Absolute public destination.
	 * @return true|WP_Error
	 */
	public function copy_to_public( string $relative, string $dest ) {
		$source = $this->resolve_existing_path( $relative );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'media_manager_public_mkdir',
				__( 'Unable to create the public uploads directory.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
		if ( ! @copy( $source, $dest ) ) {
			return new WP_Error(
				'media_manager_public_copy_failed',
				__( 'Unable to copy media into public uploads.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$expected = filesize( $source );
		$actual   = filesize( $dest );

		if ( false === $expected || false === $actual || (int) $expected !== (int) $actual ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $dest );
			return new WP_Error(
				'media_manager_public_verify_failed',
				__( 'Public storage copy failed verification.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		return true;
	}

	/**
	 * Delete a private file if contained.
	 *
	 * @param string $relative Relative path.
	 * @return true|WP_Error
	 */
	public function delete( string $relative ) {
		$path = $this->resolve_existing_path( $relative );

		if ( is_wp_error( $path ) ) {
			// Already missing is success.
			if ( 'media_manager_private_source_missing' === $path->get_error_code() ) {
				return true;
			}
			return $path;
		}

		if ( ! file_exists( $path ) ) {
			return true;
		}

		$real = realpath( $path );
		if ( false === $real || ! $this->is_contained( $real ) || ! is_file( $real ) ) {
			return new WP_Error(
				'media_manager_private_storage_path',
				__( 'Refusing to delete a path outside private storage.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( ! @unlink( $real ) ) {
			return new WP_Error(
				'media_manager_private_delete_failed',
				__( 'Unable to delete a private media file.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		return true;
	}

	/**
	 * Whether an absolute path is contained in the configured private root.
	 *
	 * @param string $absolute Absolute path (preferably realpath).
	 * @return bool
	 */
	public function is_contained( string $absolute ): bool {
		$root = $this->config->path();
		if ( '' === $root ) {
			return false;
		}

		$root_real = realpath( $root );
		if ( false === $root_real ) {
			// During directory creation the leaf may not exist yet; validate against config path string.
			$root_real = $root;
		}

		$prefix = trailingslashit( str_replace( '\\', '/', $root_real ) );
		$needle = str_replace( '\\', '/', $absolute );

		return 0 === strpos( $needle . '/', $prefix ) || 0 === strpos( $needle, rtrim( $prefix, '/' ) );
	}

	/**
	 * Move files from the legacy site-{id} layout into the current layout.
	 *
	 * @return int Number of files moved.
	 */
	public function migrate_legacy_layout(): int {
		$legacy = $this->legacy_site_root();
		if ( ! is_dir( $legacy ) ) {
			return 0;
		}

		$root = $this->site_root();
		if ( is_wp_error( $root ) ) {
			return 0;
		}

		$moved = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $legacy, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$source = $item->getPathname();
			$rel    = ltrim( str_replace( '\\', '/', substr( $source, strlen( $legacy ) ) ), '/' );
			if ( '' === $rel ) {
				continue;
			}

			$dest = $root . '/' . $rel;

			if ( $item->isDir() ) {
				if ( ! is_dir( $dest ) ) {
					wp_mkdir_p( $dest );
				}
				continue;
			}

			if ( ! is_file( $source ) ) {
				continue;
			}

			$ensured = $this->ensure_directory( $dest );
			if ( is_wp_error( $ensured ) ) {
				continue;
			}

			if ( ! file_exists( $dest ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename
				if ( @rename( $source, $dest ) ) {
					++$moved;
				}
			}
		}

		return $moved;
	}

	/**
	 * Validate the nearest existing parent for a new destination directory.
	 *
	 * @param string $dir  Target directory.
	 * @param string $root Site root.
	 * @return string|WP_Error
	 */
	private function nearest_existing_parent( string $dir, string $root ) {
		$current = $dir;
		while ( $current && $current !== dirname( $current ) ) {
			if ( is_dir( $current ) ) {
				$real = realpath( $current );
				if ( false === $real || ! $this->is_contained( $real ) ) {
					return new WP_Error(
						'media_manager_private_storage_path',
						__( 'Private storage parent failed containment checks.', 'media-manager' ),
						[ 'status' => 500 ]
					);
				}
				return $real;
			}
			if ( 0 !== strpos( str_replace( '\\', '/', $current ), str_replace( '\\', '/', $root ) ) ) {
				break;
			}
			$current = dirname( $current );
		}

		$real = realpath( $root );
		return false === $real
			? new WP_Error(
				'media_manager_private_storage_path',
				__( 'Private storage root failed containment checks.', 'media-manager' ),
				[ 'status' => 500 ]
			)
			: $real;
	}
}
