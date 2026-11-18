<?php
/**
 * Runtime key tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Onboarding\Utils\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Two plugins bundling a prefixed copy each get their own handles, routes and
 * keys. The rule is tested through prefix_for(), which takes the namespace
 * the class would be compiled under — see Runtime for why that is the seam
 * rather than compiling a renamed copy.
 */
final class RuntimeTest extends TestCase {

	/**
	 * Unprefixed, the library's own names.
	 */
	public function test_an_unprefixed_build_keeps_the_librarys_names(): void {
		$this->assertSame( 'sugarcommerce-onboarding', Runtime::prefix() );
		$this->assertSame( 'sugarcommerce-onboarding', Runtime::handle() );
		$this->assertSame( 'sugarcommerce-onboarding-checklist', Runtime::handle( 'checklist' ) );
		$this->assertSame( 'sugarcommerce_onboarding_myplugin_redirect', Runtime::key( 'myplugin_redirect' ) );
		$this->assertSame( 'sugarcommerce-onboarding/v1', Runtime::rest_namespace() );
	}

	/**
	 * Prefixed, each build's own, and never another's.
	 */
	public function test_two_prefixed_builds_do_not_collide(): void {
		$sugarcart = Runtime::prefix_for( 'SugarCart\Vendor\SugarCommerce\Onboarding\Utils' );
		$shop      = Runtime::prefix_for( 'ShopKit\SugarCommerce\Onboarding\Utils' );

		$this->assertSame( 'sugarcart-sugarcommerce-onboarding', $sugarcart );
		$this->assertSame( 'shopkit-sugarcommerce-onboarding', $shop );
		$this->assertNotSame( $sugarcart, $shop );
		$this->assertNotSame( 'sugarcommerce-onboarding', $sugarcart );
	}

	/**
	 * A namespace segment becomes a clean slug.
	 */
	public function test_a_prefix_is_slugged(): void {
		$this->assertSame( 'my-plugin-2-sugarcommerce-onboarding', Runtime::prefix_for( 'My_Plugin__2\SugarCommerce\Onboarding\Utils' ) );
		$this->assertSame( 'sugarcommerce-onboarding', Runtime::prefix_for( '' ) );
	}

	/**
	 * A plugin of the family prefixes with a namespace that also starts
	 * "SugarCommerce", and its build is still its own.
	 */
	public function test_a_family_plugin_build_is_not_taken_for_the_library(): void {
		$playground = Runtime::prefix_for( 'SugarCommerce\Playground\Vendor\SugarCommerce\Onboarding\Utils' );

		$this->assertSame( 'sugarcommerce-playground-vendor-sugarcommerce-onboarding', $playground );
		$this->assertNotSame(
			$playground,
			Runtime::prefix_for( 'SugarCommerce\Kitchen\Vendor\SugarCommerce\Onboarding\Utils' ),
			'Two plugins of the family share a name.'
		);
		$this->assertSame( 'sugarcart-sugarcommerce-onboarding', Runtime::prefix_for( 'SugarCart\Vendor\SugarCommerce\Onboarding\Utils' ), 'A plugin outside the family keeps its name.' );
		$this->assertSame( 'sugarcommerce-onboarding', Runtime::prefix_for( '\SugarCommerce\Onboarding\Utils\\' ) );
	}
}
