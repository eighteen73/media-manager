<?php
/**
 * Effective ACL cache helpers.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Site-scoped ACL cache invalidation.
 */
class AclCache {

	/**
	 * Transient/group prefix.
	 *
	 * @var string
	 */
	public const GROUP = 'media_manager_acl';

	/**
	 * Version option used to bust all ACL caches for a site.
	 *
	 * @var string
	 */
	public const VERSION_OPTION = 'media_manager_acl_version';

	/**
	 * Current cache version for the site.
	 *
	 * @return int
	 */
	public static function version(): int {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	/**
	 * Invalidate all ACL caches for the current site.
	 *
	 * @return void
	 */
	public static function invalidate_site(): void {
		$next = self::version() + 1;
		update_option( self::VERSION_OPTION, $next, false );
		wp_cache_set( 'version', $next, self::GROUP );
	}

	/**
	 * Build a cache key for a user access signature.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $signature Access signature.
	 * @return string
	 */
	public static function key( int $user_id, string $signature ): string {
		return sprintf(
			'mm_acl_%d_%d_%s',
			get_current_blog_id(),
			self::version(),
			md5( $user_id . '|' . $signature )
		);
	}

	/**
	 * Get a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed|null Null when missing.
	 */
	public static function get( string $key ) {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );

		return $found ? $value : null;
	}

	/**
	 * Store a cached value.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL seconds.
	 * @return void
	 */
	public static function set( string $key, $value, int $ttl = 300 ): void {
		wp_cache_set( $key, $value, self::GROUP, $ttl );
	}
}
