<?php
/**
 * Build zip archives of folder media originals.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Folders;

use Eighteen73\MediaManager\Permissions\AccessPolicy;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Creates zip downloads of full-size attachment files in a folder.
 */
class FolderZipService {

	/**
	 * Soft limit on attachments per download.
	 *
	 * @var int
	 */
	public const MAX_FILES = 500;

	/**
	 * Folder repository.
	 *
	 * @var FolderRepository
	 */
	private FolderRepository $repository;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Constructor.
	 *
	 * @param FolderRepository|null $repository Optional repository.
	 * @param AccessPolicy|null     $access     Optional access policy.
	 */
	public function __construct( ?FolderRepository $repository = null, ?AccessPolicy $access = null ) {
		$this->repository = $repository ?? new FolderRepository();
		$this->access     = $access ?? new AccessPolicy();
	}

	/**
	 * Build a zip of full-size originals for a folder (including descendants).
	 *
	 * @param int $folder_id Folder term ID.
	 * @return array{path:string,filename:string,count:int}|WP_Error
	 */
	public function create_archive( int $folder_id ) {
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error(
				'media_manager_zip_unavailable',
				__( 'Zip support is not available on this server.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$folder = $this->repository->find( $folder_id );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		$attachment_ids = $this->attachment_ids_for_folder( $folder_id );

		if ( count( $attachment_ids ) > self::MAX_FILES ) {
			return new WP_Error(
				'media_manager_download_too_large',
				sprintf(
					/* translators: %d: maximum number of files */
					__( 'This folder has too many files to download at once (maximum %d).', 'media-manager' ),
					self::MAX_FILES
				),
				[ 'status' => 413 ]
			);
		}

		$entries = [];

		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! $this->access->can_view_attachment( $attachment_id ) ) {
				continue;
			}

			$file = get_attached_file( $attachment_id );

			if ( ! is_string( $file ) || '' === $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
				continue;
			}

			$entries[] = [
				'path'     => $file,
				'basename' => wp_basename( $file ),
				'id'       => $attachment_id,
			];
		}

		if ( empty( $entries ) ) {
			return new WP_Error(
				'media_manager_download_empty',
				__( 'This folder has no downloadable files.', 'media-manager' ),
				[ 'status' => 404 ]
			);
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return new WP_Error(
				'media_manager_zip_temp_failed',
				__( 'Unable to create a temporary download file.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$temp = trailingslashit( $uploads['basedir'] ) . 'media-manager-dl-' . wp_generate_password( 12, false, false ) . '.zip';

		$zip    = new ZipArchive();
		$opened = $zip->open( $temp, ZipArchive::CREATE );

		if ( true !== $opened ) {
			return new WP_Error(
				'media_manager_zip_open_failed',
				__( 'Unable to create the download archive.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		$used_names = [];
		$added      = 0;

		foreach ( $entries as $entry ) {
			$name                = $this->unique_entry_name( $entry['basename'], (int) $entry['id'], $used_names );
			$used_names[ $name ] = true;
			if ( $zip->addFile( $entry['path'], $name ) ) {
				++$added;
			}
		}

		$zip->close();

		if ( $added < 1 || ! file_exists( $temp ) || 0 === (int) filesize( $temp ) ) {
			if ( file_exists( $temp ) ) {
				wp_delete_file( $temp );
			}

			return new WP_Error(
				'media_manager_zip_build_failed',
				__( 'Unable to build the download archive.', 'media-manager' ),
				[ 'status' => 500 ]
			);
		}

		return [
			'path'     => $temp,
			'filename' => $this->archive_filename( $folder->name, $folder_id ),
			'count'    => $added,
		];
	}

	/**
	 * Collect attachment IDs in a folder and its descendants.
	 *
	 * @param int $folder_id Folder term ID.
	 * @return int[]
	 */
	public function attachment_ids_for_folder( int $folder_id ): array {
		$query = new \WP_Query(
			[
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => self::MAX_FILES + 1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => [
					[
						'taxonomy'         => FolderTaxonomy::NAME,
						'field'            => 'term_id',
						'terms'            => [ $folder_id ],
						'include_children' => true,
					],
				],
			]
		);

		return array_map( 'intval', $query->posts );
	}

	/**
	 * Build a safe download filename for the archive.
	 *
	 * @param string $folder_name Folder name.
	 * @param int    $folder_id   Folder ID.
	 * @return string
	 */
	public function archive_filename( string $folder_name, int $folder_id ): string {
		$base = sanitize_file_name( $folder_name );

		if ( '' === $base ) {
			$base = 'folder-' . $folder_id;
		}

		return $base . '.zip';
	}

	/**
	 * Ensure zip entry names are unique within the archive.
	 *
	 * @param string             $basename   Original basename.
	 * @param int                $attachment_id Attachment ID.
	 * @param array<string,bool> $used_names Names already used.
	 * @return string
	 */
	private function unique_entry_name( string $basename, int $attachment_id, array $used_names ): string {
		$basename = sanitize_file_name( $basename );

		if ( '' === $basename ) {
			$basename = 'file-' . $attachment_id;
		}

		if ( ! isset( $used_names[ $basename ] ) ) {
			return $basename;
		}

		$info = pathinfo( $basename );
		$stem = $info['filename'] ?? 'file';
		$ext  = isset( $info['extension'] ) && '' !== $info['extension']
			? '.' . $info['extension']
			: '';

		$candidate = $stem . '-' . $attachment_id . $ext;

		if ( ! isset( $used_names[ $candidate ] ) ) {
			return $candidate;
		}

		$i = 2;
		while ( isset( $used_names[ $stem . '-' . $attachment_id . '-' . $i . $ext ] ) ) {
			++$i;
		}

		return $stem . '-' . $attachment_id . '-' . $i . $ext;
	}
}
