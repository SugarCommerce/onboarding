<?php
/**
 * Built script tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Plugin\Assets;

/**
 * The scripts are built from src/js into assets/build, and the build is
 * committed: Composer and Strauss copy a package as it is, with nothing run
 * at install. These keep the build and the PHP that loads it in step.
 */
final class ScriptsTest extends TestCase {

	/**
	 * Every entry the PHP loads, and what each imports.
	 */
	private const ENTRIES = [
		'onboarding' => [ 'wp-dom-ready' ],
		'checklist'  => [ 'wp-api-fetch', 'wp-i18n' ],
	];

	/**
	 * A file of the package, for the asset helper to find it from.
	 */
	private const FILE = __DIR__ . '/../src/Wizard.php';

	/**
	 * Every entry is built, with the manifest @wordpress/scripts writes
	 * beside it, and none of them needs jQuery.
	 */
	public function test_every_built_entry_has_its_manifest(): void {
		foreach ( array_keys( self::ENTRIES ) as $entry ) {
			$manifest = Assets::built_manifest( self::FILE, $entry );

			$this->assertNotNull( $manifest, $entry );
			$this->assertFileExists( dirname( __DIR__ ) . '/assets/build/' . $entry . '.js', $entry );
			$this->assertSame( [], preg_grep( '/^(jquery|wp-util)/', $manifest['dependencies'] ), $entry );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{20}$/', (string) $manifest['version'], $entry );
		}

		$this->assertNull( Assets::built_manifest( self::FILE, 'nothing' ) );
	}

	/**
	 * The wizard waits for the page; the card's requests go through
	 * wp.apiFetch, which renews a nonce that ran out while the card was
	 * open, and its last-resort words through wp.i18n.
	 */
	public function test_each_build_imports_what_it_needs(): void {
		foreach ( self::ENTRIES as $entry => $dependencies ) {
			$this->assertSame( $dependencies, Assets::built_manifest( self::FILE, $entry )['dependencies'], $entry );
		}
	}

	/**
	 * Each screen loads its build under the copy's handle.
	 */
	public function test_each_screen_loads_its_build_under_its_handle(): void {
		$this->assertStringContainsString(
			"Assets::enqueue_built_script( Runtime::handle(), __FILE__, 'onboarding' );",
			(string) file_get_contents( dirname( __DIR__ ) . '/src/Wizard.php' )
		);
		$this->assertStringContainsString(
			"Assets::enqueue_built_script( Runtime::handle( 'checklist' ), __FILE__, 'checklist' );",
			(string) file_get_contents( dirname( __DIR__ ) . '/src/Checklist.php' )
		);
	}

	/**
	 * The sources ship beside the build, and nothing loads a script from
	 * the old hand-written folder.
	 */
	public function test_nothing_loads_the_old_scripts(): void {
		$this->assertDirectoryDoesNotExist( dirname( __DIR__ ) . '/assets/js' );

		foreach ( array_keys( self::ENTRIES ) as $entry ) {
			$this->assertFileExists( dirname( __DIR__ ) . '/src/js/' . $entry . '.js' );
		}

		foreach ( glob( dirname( __DIR__ ) . '/src/{,*/,*/*/}*.php', GLOB_BRACE ) as $file ) {
			$this->assertStringNotContainsString( "'js/", (string) file_get_contents( $file ), basename( $file ) );
		}
	}

	/**
	 * The card's words are in the library's text domain.
	 */
	public function test_the_checklist_is_translated_in_the_librarys_domain(): void {
		$GLOBALS['ob_script_translations'] = [];

		Assets::enqueue_built_script( 'a-checklist', dirname( __DIR__ ) . '/src/Checklist.php', 'checklist' );
		Assets::enqueue_built_script( 'a-wizard', self::FILE, 'onboarding' );

		$this->assertSame( [ 'a-checklist' => 'sugarcommerce' ], $GLOBALS['ob_script_translations'] );
	}
}
