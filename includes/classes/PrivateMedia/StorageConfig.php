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
	 * Configured absolute path, or the code-derived default when unset.
	 *
	 * @return string
	 */
	public function path(): string {
		$settings = Settings::get_settings();
		$path     = isset( $settings[ self::SETTING_KEY ] ) ? (string) $settings[ self::SETTING_KEY ] : '';
		$path     = trim( $path );

		if ( '' === $path ) {
			$path = self::default_path();
		}

		return untrailingslashit( $path );
	}

	/**
	 * Default private storage path outside the web root.
	 *
	 * Bedrock-style installs (…/web/wp) resolve to {project}/private.
	 * Standard installs resolve to a sibling of ABSPATH named private.
	 *
	 * @return string
	 */
	public static function default_path(): string {
		$abspath = wp_normalize_path( untrailingslashit( ABSPATH ) );

		if ( 'wp' === basename( $abspath ) ) {
			$root = dirname( $abspath, 2 );
		} else {
			$root = dirname( $abspath );
		}

		$path = wp_normalize_path( $root . '/private' );

		/**
		 * Filter the default private storage path used when none is configured.
		 *
		 * @param string $path Absolute filesystem path.
		 */
		$filtered = apply_filters( 'media_manager_default_private_storage_path', $path );

		return untrailingslashit( is_string( $filtered ) && '' !== $filtered ? $filtered : $path );
	}

	/**
	 * Create the private storage directory when missing (best-effort).
	 *
	 * @return bool True when the directory exists and is writable.
	 */
	public function ensure_directory(): bool {
		$path = $this->path();

		if ( '' === $path ) {
			return false;
		}

		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}

		return is_dir( $path ) && wp_is_writable( $path );
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

		// Attempt to create the default (or configured) path when missing.
		if ( ! file_exists( $path ) ) {
			$this->ensure_directory();
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

		if ( ! wp_is_writable( $real ) ) {
			$result['message'] = __( 'The private storage path is not writable.', 'media-manager' );
			return $result;
		}

		$result['checks']['writable'] = true;

		$ok                     = $this->probe_io( $real );
		$result['checks']['io'] = $ok;

		if ( ! $ok ) {
			$result['message'] = __( 'Private storage failed create/read/write/rename/delete checks.', 'media-manager' );
			return $result;
		}

		$result['ok']      = true;
		$result['message'] = __( 'Private storage is healthy.', 'media-manager' );

		return $result;
	}

	/**
	 * Probe create / rename / read / delete via WP_Filesystem.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool
	 */
	private function probe_io( string $dir ): bool {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Private storage is outside ABSPATH; allow relaxed ownership for the direct transport.
		if ( ! WP_Filesystem( false, $dir, true ) ) {
			return false;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return false;
		}

		$probe   = trailingslashit( $dir ) . '.media-manager-health-' . wp_generate_password( 8, false );
		$renamed = $probe . '.renamed';

		if ( ! $wp_filesystem->put_contents( $probe, 'ok', FS_CHMOD_FILE ) ) {
			return false;
		}

		if ( ! $wp_filesystem->move( $probe, $renamed ) ) {
			$wp_filesystem->delete( $probe );
			return false;
		}

		$content = $wp_filesystem->get_contents( $renamed );
		$wp_filesystem->delete( $renamed );

		return 'ok' === $content;
	}
}
