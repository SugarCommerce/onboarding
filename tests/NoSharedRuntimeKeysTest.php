<?php
/**
 * Guard against reintroducing a shared runtime key.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Strauss rewrites namespaces but not string literals, so any runtime string
 * written as a literal in this library is shared by every plugin that bundles
 * it: two plugins, each with a wizard, would load one another's stylesheet,
 * answer one another's REST routes, and send one another's activation to the
 * wrong wizard. This scans the source for the literal forms and requires them
 * to go through Runtime, or to carry the wizard's or checklist's own id.
 *
 * It reads source rather than exercising behaviour on purpose: the failure it
 * guards against is invisible with one plugin installed and only appears on a
 * site running two, which no unit test of this library can set up.
 */
final class NoSharedRuntimeKeysTest extends TestCase {

	/**
	 * Every PHP file in src/, keyed by path.
	 *
	 * @return array<string, string>
	 */
	private function sources(): array {
		$root  = dirname( __DIR__ ) . '/src';
		$files = [];

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) ) as $file ) {
			// Runtime itself documents the literal forms in its docblock.
			if ( $file->isFile() && 'php' === $file->getExtension() && 'Runtime.php' !== $file->getBasename() ) {
				$files[ str_replace( $root . '/', '', $file->getPathname() ) ] = (string) file_get_contents( $file->getPathname() );
			}
		}

		$this->assertNotEmpty( $files );

		return $files;
	}

	/**
	 * Assert no source matches a literal-argument pattern.
	 *
	 * @param string $pattern Regex with the offending literal in group 1.
	 * @param string $label   What the pattern describes.
	 */
	private function assertNoLiteral( string $pattern, string $label ): void {
		$found = [];

		foreach ( $this->sources() as $path => $code ) {
			if ( preg_match_all( $pattern, $code, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$found[] = sprintf( '%s: %s', $path, $match[0] );
				}
			}
		}

		$this->assertSame( [], $found, "{$label} must be derived from Runtime, not written as a literal:\n" . implode( "\n", $found ) );
	}

	/**
	 * REST namespaces decide which plugin answers a request.
	 */
	public function test_rest_namespaces_are_not_literals(): void {
		$this->assertNoLiteral( "/register_rest_route\(\s*'([^']+)'/", 'REST namespaces' );
	}

	/**
	 * Every callback on one wp_ajax action runs; the first to exit wins.
	 */
	public function test_no_ajax_actions(): void {
		$this->assertNoLiteral( "/add_action\(\s*'(wp_ajax_[^']*)'/", 'AJAX actions' );
	}

	/**
	 * Script and style handles decide whose asset loads.
	 */
	public function test_asset_handles_are_not_literals(): void {
		$this->assertNoLiteral( "/(?:wp_(?:enqueue|register)_(?:script|style)|Assets::(?:style|script))\(\s*'([^']+)'/", 'Asset handles' );
	}

	/**
	 * A shared transient sends one plugin's activation to another's wizard.
	 */
	public function test_transient_keys_are_not_literals(): void {
		$this->assertNoLiteral( "/(?:get|set|delete)_transient\(\s*'([^']+)'/", 'Transient keys' );
	}

	/**
	 * A localized global is overwritten by whichever copy localizes last.
	 */
	public function test_no_localized_globals(): void {
		$this->assertNoLiteral( "/wp_localize_script\(\s*[^,]+,\s*'([^']+)'/", 'Localized JS object names' );
	}

	/**
	 * An option or user meta key written literally is one store for every copy.
	 */
	public function test_option_and_meta_keys_are_not_literals(): void {
		$this->assertNoLiteral( "/(?:get|update|delete|add)_(?:option|user_meta)\(\s*(?:[^,()]+,\s*)?'([^']+)'/", 'Option and user meta keys' );
	}

	/**
	 * Every hook carries the wizard's id, the checklist's, or a step type.
	 *
	 * Hooks are the exception to deriving from Runtime, and deliberately: a
	 * hook named from the build's prefix is safe from collision and impossible
	 * for anybody to name. They carry the consumer's own identifier instead,
	 * which is what a hook name should be built from.
	 */
	public function test_every_hook_carries_a_scope(): void {
		$unscoped = [];

		foreach ( $this->sources() as $path => $source ) {
			preg_match_all( '/(?:do_action|apply_filters)\(\s*(?:"([^"]+)"|\'([^\']+)\'\s*(\.?))/', $source, $matches, PREG_SET_ORDER );

			foreach ( $matches as $match ) {
				$interpolated = '' !== ( $match[1] ?? '' );
				$name         = $interpolated ? $match[1] : ( $match[2] ?? '' );

				if ( str_contains( $name, '{$' ) || ( ! $interpolated && '.' === ( $match[3] ?? '' ) ) ) {
					continue;
				}

				$unscoped[] = sprintf( '%s: %s', $path, $name );
			}
		}

		$this->assertSame( [], $unscoped, "Every hook must carry a wizard id, a checklist id or a step type:\n" . implode( "\n", $unscoped ) );
	}

	/**
	 * Every hook starts with the library's own prefix, so a reader can find them all.
	 */
	public function test_every_hook_is_the_librarys(): void {
		$foreign = [];

		foreach ( $this->sources() as $path => $source ) {
			preg_match_all( '/(?:do_action|apply_filters)\(\s*["\']([^"\']+)/', $source, $matches );

			foreach ( $matches[1] as $name ) {
				if ( ! str_starts_with( $name, 'sugarcommerce_onboarding_' ) ) {
					$foreign[] = sprintf( '%s: %s', $path, $name );
				}
			}
		}

		$this->assertSame( [], $foreign, implode( "\n", $foreign ) );
	}
}
