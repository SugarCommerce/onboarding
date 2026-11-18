<?php
/**
 * Assets
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Utils;

/**
 * The wizard's and the checklist's own stylesheets and scripts.
 *
 * A Composer library has no plugin file to hand plugins_url(), so a file's
 * address is worked out from where this copy sits: under the content
 * directory, under the site root, or inside a symlinked plugin. Each is
 * enqueued under a handle of this build's own (Runtime::handle()), so two
 * plugins bundling a copy each load their own files.
 *
 * @internal
 */
final class Assets {

	/**
	 * Enqueue a stylesheet from the assets folder.
	 *
	 * @param string $handle Its handle.
	 * @param string $file   Its path in the assets folder.
	 *
	 * @return void
	 */
	public static function style( string $handle, string $file ): void {
		wp_enqueue_style( $handle, self::url( $file ), [], self::version( $file ) );
	}

	/**
	 * Enqueue a script from the assets folder, in the footer.
	 *
	 * @param string $handle Its handle.
	 * @param string $file   Its path in the assets folder.
	 *
	 * @return void
	 */
	public static function script( string $handle, string $file ): void {
		wp_enqueue_script( $handle, self::url( $file ), [], self::version( $file ), true );
	}

	/**
	 * A file's URL in this copy of the package.
	 *
	 * @param string $file From the assets folder.
	 *
	 * @return string Empty when this copy is not under the site.
	 */
	public static function url( string $file ): string {
		$path = wp_normalize_path( self::path( $file ) );

		// A symlinked plugin: the path here is the real one. Core keeps a
		// map from each plugin's path under the content directory to it.
		foreach ( (array) ( $GLOBALS['wp_plugin_paths'] ?? [] ) as $plugin_path => $real_path ) {
			$real_path = rtrim( wp_normalize_path( (string) $real_path ), '/' );

			if ( '' !== $real_path && str_starts_with( $path, $real_path . '/' ) ) {
				$path = wp_normalize_path( (string) $plugin_path ) . substr( $path, strlen( $real_path ) );
			}
		}

		foreach ( [ [ WP_CONTENT_DIR, content_url() ], [ ABSPATH, site_url( '/' ) ] ] as [ $base, $base_url ] ) {
			$base = rtrim( wp_normalize_path( (string) $base ), '/' );

			if ( '' !== $base && str_starts_with( $path, $base . '/' ) ) {
				return rtrim( (string) $base_url, '/' ) . substr( $path, strlen( $base ) );
			}
		}

		return '';
	}

	/**
	 * A file's version: when it last changed, so a new copy is fetched.
	 *
	 * @param string $file From the assets folder.
	 *
	 * @return string
	 */
	public static function version( string $file ): string {
		$time = @filemtime( self::path( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is a version, not a warning.

		return false === $time ? '1' : (string) $time;
	}

	/**
	 * A file's path in this copy of the package.
	 *
	 * @param string $file From the assets folder.
	 *
	 * @return string
	 */
	private static function path( string $file ): string {
		return dirname( __DIR__, 2 ) . '/assets/' . ltrim( $file, '/' );
	}
}
