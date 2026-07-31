<?php
/**
 * Private storage configuration and health checks.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\PrivateMedia;

use Eighteen73\MediaManager\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and exposes the configured private storage path.
 */
class StorageConfig {

	/**
	 * Settings key.
	 *
	 * @var string
	 */
	public const SETTING_KEY = 'private_storage_path';

	/**
	 * Configured absolute path (trimmed, no trailing slash) or empty.
	 *
	 * @return string
	 */
	public function path(): string {
		$settings = Settings::get_settings();
		$path     = isset( $settings[ self::SETTING_KEY ] ) ? (string) $settings[ self::SETTING_KEY ] : '';
		$path     = trim( $path );

		return untrailingslashit( $path );
	}

	/**
	 * Whether private storage is configured and healthy.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		$health = $this->health();

		return ! empty( $health['ok'] );
	}

	/**
	 * Run health checks against the configured path.
	 *
	 * @return array{ok: bool, path: string, message: string, checks: array<string, bool>}
	 */
	public function health(): array {
		$path = $this->path();

		$result = [
			'ok'      => false,
			'path'    => $path,
			'message' => '',
			'checks'  => [
				'configured'      => '' !== $path,
				'exists'          => false,
				'is_dir'          => false,
				'writable'        => false,
				'outside_web'     => false,
				'outside_uploads' => false,
				'io'              => false,
			],
		];

		if ( '' === $path ) {
			$result['message'] = __( 'No private storage path is configured.', 'media-manager' );
			return $result;
		}

		if ( ! file_exists( $path ) ) {
			$result['message'] = __( 'The private storage path does not exist.', 'media-manager' );
			return $result;
		}

		$result['checks']['exists'] = true;

		if ( ! is_dir( $path ) ) {
			$result['message'] = __( 'The private storage path is not a directory.', 'media-manager' );
			return $result;
		}

		$result['checks']['is_dir'] = true;

		$real = realpath( $path );
		if ( false === $real ) {
			$result['message'] = __( 'Unable to resolve the private storage path.', 'media-manager' );
			return $result;
		}

		$result['path'] = $real;

		$abspath        = realpath( ABSPATH );
		$uploads        = wp_upload_dir();
		$upload_basedir = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;

		$outside_web                     = $abspath && 0 !== strpos( $real . DIRECTORY_SEPARATOR, $abspath . DIRECTORY_SEPARATOR );
		$result['checks']['outside_web'] = (bool) $outside_web;

		$outside_uploads                     = ! $upload_basedir || 0 !== strpos( $real . DIRECTORY_SEPARATOR, $upload_basedir . DIRECTORY_SEPARATOR );
		$result['checks']['outside_uploads'] = (bool) $outside_uploads;

		if ( ! $outside_web || ! $outside_uploads ) {
			$result['message'] = __( 'Private storage must be outside the web root and public uploads directory.', 'media-manager' );
			return $result;
		}

		if ( ! is_writable( $real ) ) {
			$result['message'] = __( 'The private storage path is not writable.', 'media-manager' );
			return $result;
		}

		$result['checks']['writable'] = true;

		$probe = $real . DIRECTORY_SEPARATOR . '.media-manager-health-' . wp_generate_password( 8, false );
		$ok    = false;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false !== file_put_contents( $probe, 'ok' ) ) {
			$renamed = $probe . '.renamed';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( @rename( $probe, $renamed ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
				$content = @file_get_contents( $renamed );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $renamed );
				$ok = ( 'ok' === $content );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $probe );
			}
		}

		$result['checks']['io'] = $ok;

		if ( ! $ok ) {
			$result['message'] = __( 'Private storage failed create/read/write/rename/delete checks.', 'media-manager' );
			return $result;
		}

		$result['ok']      = true;
		$result['message'] = __( 'Private storage is healthy.', 'media-manager' );

		return $result;
	}
}
