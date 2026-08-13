<?php
/**
 * Network shared media library resolver.
 *
 * @package Eighteen73\MediaManager
 */

namespace Eighteen73\MediaManager\SharedMedia;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the configured network media library site.
 */
class NetworkLibrary {

	/**
	 * Site option name for the network library blog ID.
	 *
	 * @var string
	 */
	public const OPTION = 'media_manager_network_library_id';

	/**
	 * Stored (and filterable) network library blog ID.
	 *
	 * Returns 0 when shared media is disabled.
	 *
	 * @return int
	 */
	public static function get_library_id(): int {
		if ( ! is_multisite() ) {
			return 0;
		}

		$stored = absint( get_site_option( self::OPTION, 0 ) );

		/**
		 * Filter the network media library site ID.
		 *
		 * Return 0 to disable shared media.
		 *
		 * @param int $network_library_id Network library blog ID.
		 */
		$id = (int) apply_filters( 'media_manager_network_library_id', $stored );

		if ( $id < 1 ) {
			return 0;
		}

		$site = get_site( $id );
		if ( ! $site || (int) $site->blog_id !== $id ) {
			return 0;
		}

		return $id;
	}

	/**
	 * Whether a network library site is configured and valid.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return self::get_library_id() > 0;
	}

	/**
	 * Whether shared media access is allowed for a given blog.
	 *
	 * @param int $blog_id Optional blog ID. Defaults to the current blog.
	 * @return bool
	 */
	public static function is_allowed_for_blog( int $blog_id = 0 ): bool {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$blog_id = $blog_id > 0 ? $blog_id : get_current_blog_id();
		$allowed = true;

		/**
		 * Filter whether a sub-site may access the network media library.
		 *
		 * @param bool $allowed Whether access is allowed.
		 * @param int  $blog_id Sub-site blog ID.
		 */
		return (bool) apply_filters( 'media_manager_network_library_allowed', $allowed, $blog_id );
	}

	/**
	 * Shared media tab title.
	 *
	 * @return string
	 */
	public static function tab_title(): string {
		/**
		 * Filter the Shared media tab title.
		 *
		 * @param string $tab_title Tab title.
		 */
		$title = apply_filters(
			'media_manager_shared_media_tab_title',
			__( 'Shared media', 'media-manager' )
		);

		return is_string( $title ) && '' !== $title
			? $title
			: __( 'Shared media', 'media-manager' );
	}

	/**
	 * Whether the current request context may use shared media UI/queries.
	 *
	 * @param int $user_id Optional user ID.
	 * @return bool
	 */
	public static function current_user_can_use( int $user_id = 0 ): bool {
		if ( ! self::is_enabled() || ! self::is_allowed_for_blog() ) {
			return false;
		}

		if ( $user_id > 0 ) {
			return user_can( $user_id, \Eighteen73\MediaManager\Permissions\Capabilities::SHARED );
		}

		return current_user_can( \Eighteen73\MediaManager\Permissions\Capabilities::SHARED );
	}

	/**
	 * Whether the current blog is the configured network library.
	 *
	 * @return bool
	 */
	public static function is_library_site(): bool {
		$library_id = self::get_library_id();

		return $library_id > 0 && get_current_blog_id() === $library_id;
	}

	/**
	 * Persist the network library blog ID.
	 *
	 * @param int $blog_id Blog ID, or 0 to disable.
	 * @return int Sanitised stored value.
	 */
	public static function update_library_id( int $blog_id ): int {
		$blog_id = absint( $blog_id );

		if ( $blog_id > 0 ) {
			$site = get_site( $blog_id );
			if ( ! $site || (int) $site->blog_id !== $blog_id ) {
				$blog_id = 0;
			}
		}

		update_site_option( self::OPTION, $blog_id );

		return $blog_id;
	}
}
