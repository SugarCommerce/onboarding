<?php
/**
 * Runtime Key Derivation
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Utils;

use SugarCommerce\Plugin\Build;

/**
 * Every runtime string this library registers, derived from its own namespace.
 *
 * Strauss rewrites class namespaces and leaves string literals alone. Two
 * plugins each bundling a prefixed copy of this library therefore get
 * distinct classes but would otherwise register identical script handles and
 * identical transient keys.
 *
 * That is not merely untidy. `wp_enqueue_style()` ignores a handle that is
 * already registered, so the plugin that enqueued second gets the other
 * plugin's stylesheet — from a directory its own build may not even contain.
 * And a shared transient means one plugin's activation sends the user to the
 * other plugin's wizard.
 *
 * The derivation exploits the one thing Strauss does rewrite: this file's
 * namespace. In a prefixed build `__NAMESPACE__` begins with the consumer's
 * prefix ("MyPlugin\SugarCommerce\Onboarding\Utils"), unique per plugin
 * by construction, so every key comes out distinct with no configuration.
 */
final class Runtime {

	/**
	 * This library's own identifier, used when running unprefixed.
	 */
	private const string LIBRARY = 'sugarcommerce-onboarding';

	/**
	 * How many segments this class's own namespace has —
	 * SugarCommerce\Onboarding\Utils — before any prefix. Counted rather than
	 * written out: Strauss rewrites a namespace it finds in a string, and
	 * would prefix the very thing being compared against.
	 */
	private const int OWN_SEGMENTS = 3;

	/**
	 * The per-build prefix.
	 *
	 * "sugarcommerce-onboarding" for a plain Composer install — development,
	 * or a single consumer that does not use Strauss — and
	 * "{prefix}-sugarcommerce-onboarding" for a prefixed build.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		return self::prefix_for( __NAMESPACE__ );
	}

	/**
	 * The prefix a given namespace would produce.
	 *
	 * Split out so the rule can be tested. prefix() reads __NAMESPACE__,
	 * which cannot be changed at runtime, so a test can only reach the
	 * prefixed case by asking the rule directly — this takes the namespace
	 * as an argument, and prefix() hands it __NAMESPACE__.
	 *
	 * The alternative, compiling a copy of this file under another namespace
	 * at runtime, was tried and abandoned: the interpreter that does it
	 * refuses the `declare( strict_types=1 )` at the top of this file on PHP
	 * 8.3 and 8.4, so the test passed on 8.5 alone and reported the local PHP
	 * version rather than the code. It is also a construct that has no place
	 * in a file that ships inside somebody's plugin.
	 *
	 * The rule is {@see Build::name()}, shared by every package that names
	 * its build: a plugin's own "…\Lib" namespace is named for every
	 * segment before "Lib", so two plugins of one vendor never share names.
	 *
	 * @param string $under The namespace this class is compiled under, prefixed or not.
	 *
	 * @return string
	 */
	public static function prefix_for( string $under ): string {
		$build = Build::name( $under, self::OWN_SEGMENTS );

		return '' === $build ? self::LIBRARY : $build . '-' . self::LIBRARY;
	}

	/**
	 * The REST namespace for this build.
	 *
	 * "sugarcommerce-onboarding/v1" unprefixed and
	 * "{prefix}-sugarcommerce-onboarding/v1" in a prefixed build, so two plugins bundling a copy each answer on their own routes
	 * and neither's checklist endpoint is the other's.
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		return self::prefix() . '/v1';
	}

	/**
	 * A script or style handle for this build.
	 *
	 * @param string $suffix Optional handle suffix.
	 *
	 * @return string
	 */
	public static function handle( string $suffix = '' ): string {
		return '' === $suffix ? self::prefix() : self::prefix() . '-' . $suffix;
	}

	/**
	 * An option or transient key for this build.
	 *
	 * @param string $suffix Optional key suffix.
	 *
	 * @return string
	 */
	public static function key( string $suffix = '' ): string {
		$base = str_replace( '-', '_', self::prefix() );

		return '' === $suffix ? $base : $base . '_' . $suffix;
	}
}
