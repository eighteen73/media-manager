<?php
/**
 * Authorised private media delivery.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\PrivateMedia;

use Eighteen73\MediaManager\Permissions\AccessPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Serves private attachment bytes after ACL checks.
 */
class Delivery {

	/**
	 * Query var for opaque token.
	 *
	 * @var string
	 */
	public const QUERY_TOKEN = 'media_manager_private';

	/**
	 * Query var for attachment ID.
	 *
	 * @var string
	 */
	public const QUERY_ID = 'media_manager_attachment';

	/**
	 * Query var for size name.
	 *
	 * @var string
	 */
	public const QUERY_SIZE = 'media_manager_size';

	/**
	 * Transition service.
	 *
	 * @var TransitionService
	 */
	private TransitionService $transitions;

	/**
	 * Private storage.
	 *
	 * @var PrivateStorage
	 */
	private PrivateStorage $storage;

	/**
	 * Access policy.
	 *
	 * @var AccessPolicy
	 */
	private AccessPolicy $access;

	/**
	 * Constructor.
	 *
	 * @param TransitionService|null $transitions Optional transitions.
	 * @param PrivateStorage|null    $storage     Optional storage.
	 * @param AccessPolicy|null      $access      Optional access.
	 */
	public function __construct(
		?TransitionService $transitions = null,
		?PrivateStorage $storage = null,
		?AccessPolicy $access = null
	) {
		$this->transitions = $transitions ?? new TransitionService();
		$this->storage     = $storage ?? new PrivateStorage();
		$this->access      = $access ?? new AccessPolicy();
	}

	/**
	 * Register rewrite and request handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'add_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'maybe_deliver' ], 0 );
	}

	/**
	 * Add rewrite rule for pretty private URLs.
	 *
	 * Token-only paths keep attachment IDs out of the URL.
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^media/([a-zA-Z0-9]+)/?$',
			'index.php?' . self::QUERY_TOKEN . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^media/([a-zA-Z0-9]+)/([^/]+)/?$',
			'index.php?' . self::QUERY_TOKEN . '=$matches[1]&' . self::QUERY_SIZE . '=$matches[2]',
			'top'
		);

		// Legacy plugin-prefixed delivery URLs.
		add_rewrite_rule(
			'^media-manager/private/([a-zA-Z0-9]+)/?$',
			'index.php?' . self::QUERY_TOKEN . '=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^media-manager/private/([a-zA-Z0-9]+)/([^/]+)/?$',
			'index.php?' . self::QUERY_TOKEN . '=$matches[1]&' . self::QUERY_SIZE . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Register query vars.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_TOKEN;
		$vars[] = self::QUERY_ID;
		$vars[] = self::QUERY_SIZE;
		return $vars;
	}

	/**
	 * Build a private delivery URL.
	 *
	 * Uses an opaque token path (no attachment ID) so the URL is not guessable.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|null $size          Optional size name.
	 * @return string
	 */
	public function url( int $attachment_id, ?string $size = null ): string {
		$token = $this->transitions->token( $attachment_id );
		$path  = 'media/' . rawurlencode( $token );

		if ( $size && 'full' !== $size ) {
			$path .= '/' . rawurlencode( $size );
		}

		// Prefer pretty permalinks; also expose a query-string form that always works.
		$pretty = home_url( user_trailingslashit( $path ) );
		if ( get_option( 'permalink_structure' ) ) {
			return $pretty;
		}

		$args = [ self::QUERY_TOKEN => $token ];
		if ( $size && 'full' !== $size ) {
			$args[ self::QUERY_SIZE ] = $size;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Deliver the file or 404.
	 *
	 * @return void
	 */
	public function maybe_deliver(): void {
		$token = (string) get_query_var( self::QUERY_TOKEN );

		if ( '' === $token && isset( $_GET[ self::QUERY_TOKEN ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_TOKEN ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		// Legacy URLs that included the attachment ID.
		$attachment_id = absint( get_query_var( self::QUERY_ID ) );
		if ( $attachment_id < 1 && isset( $_GET[ self::QUERY_ID ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$attachment_id = absint( wp_unslash( $_GET[ self::QUERY_ID ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( '' === $token && $attachment_id < 1 ) {
			return;
		}

		if ( $attachment_id < 1 ) {
			$attachment_id = $this->transitions->attachment_id_from_token( $token );
		}

		if ( $attachment_id < 1 || '' === $token ) {
			$this->deny();
		}

		$size = (string) get_query_var( self::QUERY_SIZE );
		if ( '' === $size && isset( $_GET[ self::QUERY_SIZE ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$size = sanitize_key( wp_unslash( $_GET[ self::QUERY_SIZE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		$this->deliver( $attachment_id, $token, $size ?: null );
	}

	/**
	 * Authorise and stream a private file.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $token         Opaque token.
	 * @param string|null $size          Optional size.
	 * @return void
	 */
	public function deliver( int $attachment_id, string $token, ?string $size = null ): void {
		$expected = (string) get_post_meta( $attachment_id, TransitionService::META_TOKEN, true );

		if ( '' === $expected || ! hash_equals( $expected, $token ) ) {
			$this->deny();
		}

		if ( ! $this->transitions->is_private( $attachment_id ) ) {
			$this->deny();
		}

		if ( ! is_user_logged_in() || ! $this->access->can_view_attachment( $attachment_id ) ) {
			$this->deny();
		}

		$relative = $this->relative_for_size( $attachment_id, $size );
		if ( null === $relative ) {
			$this->deny();
		}

		$path = $this->storage->resolve_existing_path( $relative );
		if ( is_wp_error( $path ) || ! is_readable( $path ) ) {
			$this->deny();
		}

		$real = realpath( $path );
		if ( false === $real || ! $this->storage->is_contained( $real ) ) {
			$this->deny();
		}

		$mime       = get_post_mime_type( $attachment_id );
		$mime       = $mime ? $mime : 'application/octet-stream';
		$size_bytes = filesize( $real );
		$size_bytes = false === $size_bytes ? 0 : (int) $size_bytes;

		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );

		$disposition = 'inline';
		$filename    = wp_basename( $real );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

		if ( 'HEAD' === $method ) {
			header( 'Content-Length: ' . $size_bytes );
			exit;
		}

		$start = 0;
		$end   = max( 0, $size_bytes - 1 );

		if ( isset( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $matches ) ) {
			if ( '' !== $matches[1] ) {
				$start = (int) $matches[1];
			}
			if ( '' !== $matches[2] ) {
				$end = (int) $matches[2];
			}
			if ( $end >= $size_bytes ) {
				$end = $size_bytes - 1;
			}
			if ( $start > $end ) {
				status_header( 416 );
				header( "Content-Range: bytes */{$size_bytes}" );
				exit;
			}
			status_header( 206 );
			header( "Content-Range: bytes {$start}-{$end}/{$size_bytes}" );
			header( 'Content-Length: ' . ( $end - $start + 1 ) );
			header( 'Accept-Ranges: bytes' );
		} else {
			header( 'Content-Length: ' . $size_bytes );
			header( 'Accept-Ranges: bytes' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $real, 'rb' );
		if ( false === $handle ) {
			$this->deny();
		}

		if ( $start > 0 ) {
			fseek( $handle, $start );
		}

		$remaining = $end - $start + 1;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, min( 8192, $remaining ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$remaining -= strlen( $chunk );
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Resolve relative path for a size.
	 *
	 * @param int         $attachment_id Attachment ID.
	 * @param string|null $size          Size name.
	 * @return string|null
	 */
	private function relative_for_size( int $attachment_id, ?string $size ) {
		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $file ) || '' === $file ) {
			return null;
		}

		$file = ltrim( str_replace( '\\', '/', $file ), '/' );

		if ( ! $size || 'full' === $size ) {
			return $file;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( empty( $meta['sizes'][ $size ]['file'] ) ) {
			return $file;
		}

		$dir = trailingslashit( dirname( $file ) );
		return ltrim( ( '.' === dirname( $file ) ? '' : $dir ) . $meta['sizes'][ $size ]['file'], '/' );
	}

	/**
	 * Emit a 404 without leaking existence details.
	 *
	 * @return void
	 */
	private function deny(): void {
		status_header( 404 );
		nocache_headers();
		wp_die( '', '', [ 'response' => 404 ] );
	}
}
