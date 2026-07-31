<?php
/**
 * Deterministic seed fixture for performance benchmarks.
 *
 * Usage (WP-CLI inside wp-env):
 *   wp eval-file tests/fixtures/seed-media.php
 *
 * Creates attachments and folders without real image processing.
 *
 * @package Eighteen73\MediaManager
 */

use Eighteen73\MediaManager\Folders\FolderRepository;
use Eighteen73\MediaManager\Folders\FolderService;
use Eighteen73\MediaManager\Folders\FolderTaxonomy;
use Eighteen73\MediaManager\Install\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

Installer::activate();

if ( ! taxonomy_exists( FolderTaxonomy::NAME ) ) {
	( new \Eighteen73\MediaManager\Folders\FolderTaxonomy() )->register_taxonomy();
}

$service    = new FolderService();
$repository = new FolderRepository();

$folder_ids = [];
$parent_id  = 0;

for ( $i = 1; $i <= 500; $i++ ) {
	$parent = ( 0 === $i % 10 ) ? $parent_id : 0;
	$result = $service->create( sprintf( 'Folder %04d', $i ), $parent );

	if ( is_wp_error( $result ) ) {
		continue;
	}

	$folder_ids[] = (int) $result->term_id;

	if ( 0 === $i % 10 ) {
		$parent_id = (int) $result->term_id;
	}
}

$started = microtime( true );

for ( $i = 1; $i <= 10000; $i++ ) {
	$attachment_id = wp_insert_attachment(
		[
			'post_mime_type' => 'image/jpeg',
			'post_title'     => sprintf( 'Seed Media %05d', $i ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'guid'           => home_url( sprintf( '/wp-content/uploads/seed-%05d.jpg', $i ) ),
		],
		sprintf( 'seed-%05d.jpg', $i )
	);

	if ( ! empty( $folder_ids ) && 0 === $i % 3 ) {
		$folder_id = $folder_ids[ $i % count( $folder_ids ) ];
		$repository->set_attachment_folders( $attachment_id, [ $folder_id ], false );
	}

	if ( 0 === $i % 1000 ) {
		WP_CLI::log( sprintf( 'Seeded %d attachments…', $i ) );
	}
}

$elapsed = microtime( true ) - $started;

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::success(
		sprintf(
			'Seeded %d folders and 10000 attachments in %.2f seconds.',
			count( $folder_ids ),
			$elapsed
		)
	);
}
