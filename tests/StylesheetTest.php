<?php
/**
 * Stylesheet tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a stylesheet can get wrong without anybody noticing.
 *
 * CSS has no errors. A rule naming a custom property nobody defined is
 * dropped and the element looks almost right; a hex where core publishes a
 * variable is a wizard that stays blue when the user picks Midnight; a rule
 * on a bare admin selector restyles somebody else's screen, since the
 * checklist's sheet loads wherever a plugin draws the card; and `left` where
 * `inline-start` was meant is a layout that is backwards in Arabic.
 */
final class StylesheetTest extends TestCase {

	/**
	 * Every stylesheet the library ships.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function stylesheetProvider(): array {
		$cases = [];

		foreach ( glob( dirname( __DIR__ ) . '/assets/css/*.css' ) as $path ) {
			$cases[ basename( $path ) ] = [ $path ];
		}

		return $cases;
	}

	/**
	 * A sheet's rules, without comments.
	 *
	 * @param string $path The stylesheet.
	 *
	 * @return string
	 */
	private static function css( string $path ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', (string) file_get_contents( $path ) );
	}

	/**
	 * Every selector list in a sheet, outside at-rule headers.
	 *
	 * @param string $path The stylesheet.
	 *
	 * @return string[]
	 */
	private static function selectors( string $path ): array {
		preg_match_all( '/(?:^|[{}])\s*([^{}@]+?)\s*\{/', self::css( $path ), $rules );

		$selectors = [];

		foreach ( $rules[1] as $list ) {
			foreach ( explode( ',', $list ) as $selector ) {
				$selectors[] = trim( $selector );
			}
		}

		return array_filter( $selectors, static fn( string $selector ): bool => '' !== $selector && ! str_starts_with( $selector, 'from' ) && ! str_starts_with( $selector, 'to' ) );
	}

	/**
	 * Every library custom property a rule names is defined somewhere.
	 *
	 * @param string $path The stylesheet.
	 */
	#[DataProvider( 'stylesheetProvider' )]
	public function test_every_variable_used_is_defined( string $path ): void {
		preg_match_all( '/var\(\s*(--onboarding-[a-z0-9-]+)/', self::css( $path ), $used );
		preg_match_all( '/(--onboarding-[a-z0-9-]+)\s*:/', self::css( $path ), $defined );

		$undefined = array_values( array_diff( array_unique( $used[1] ), $defined[1] ) );

		$this->assertNotEmpty( $used[1], basename( $path ) . ' uses no tokens at all.' );
		$this->assertSame( [], $undefined, basename( $path ) . ' names ' . implode( ', ', $undefined ) . ', which it does not define.' );
	}

	/**
	 * The accent follows the admin colour scheme, and core's blue appears
	 * only as the fallback in that one definition.
	 *
	 * @param string $path The stylesheet.
	 */
	#[DataProvider( 'stylesheetProvider' )]
	public function test_the_accent_follows_the_admin_colour_scheme( string $path ): void {
		$css = strtolower( self::css( $path ) );

		$this->assertMatchesRegularExpression( '/--onboarding(?:-checklist)?-accent:\s*var\(--wp-admin-theme-color,\s*#2271b1\)/', $css );

		$without = (string) preg_replace( '/var\(--wp-admin-theme-color[a-z0-9-]*,\s*#[0-9a-f]{6}\)/', '', $css );

		foreach ( [ '#2271b1', '#135e96', '#0a4b78', '#3858e9' ] as $blue ) {
			$this->assertStringNotContainsString( $blue, $without, basename( $path ) . " hardcodes {$blue}." );
		}
	}

	/**
	 * Every rule is scoped to the library's own markup or the wizard's page.
	 *
	 * @param string $path The stylesheet.
	 */
	#[DataProvider( 'stylesheetProvider' )]
	public function test_no_rule_reaches_outside_the_library( string $path ): void {
		$scopes = [ '.onboarding', 'body.onboarding-', 'html.wp-toolbar:has(body.onboarding-', '[dir="rtl"] .onboarding' ];
		$loose  = [];

		foreach ( self::selectors( $path ) as $selector ) {
			$scoped = false;

			foreach ( $scopes as $scope ) {
				$scoped = $scoped || str_starts_with( $selector, $scope );
			}

			if ( ! $scoped ) {
				$loose[] = $selector;
			}
		}

		$this->assertSame( [], $loose, basename( $path ) . ' styles markup that is not its own: ' . implode( ', ', $loose ) );
	}

	/**
	 * The checklist's sheet, which loads on any screen, touches no admin chrome.
	 */
	public function test_the_checklist_sheet_leaves_the_admin_alone(): void {
		foreach ( self::selectors( dirname( __DIR__ ) . '/assets/css/checklist.css' ) as $selector ) {
			$this->assertMatchesRegularExpression( '/^(\[dir="rtl"\] )?\.onboarding-checklist/', $selector );
		}
	}

	/**
	 * Directions are logical, so right-to-left needs no second sheet.
	 *
	 * @param string $path The stylesheet.
	 */
	#[DataProvider( 'stylesheetProvider' )]
	public function test_directions_are_logical( string $path ): void {
		$css = self::css( $path );

		$this->assertDoesNotMatchRegularExpression( '/(?:margin|padding|border)-(?:left|right)\s*:/', $css );
		$this->assertDoesNotMatchRegularExpression( '/[;{\s](?:left|right)\s*:/', $css );
		$this->assertDoesNotMatchRegularExpression( '/text-align:\s*(?:left|right)/', $css );
		$this->assertDoesNotMatchRegularExpression( '/(?:margin|padding):[ \t]*[^;\s]+[ \t]+[^;\s]+[ \t]+[^;\s]+[ \t]+[^;\s]+[ \t]*;/', $css, 'Four-value margins and paddings are physical: use the block and inline forms.' );
	}

	/**
	 * Movement stops for somebody who asked for less.
	 *
	 * @param string $path The stylesheet.
	 */
	#[DataProvider( 'stylesheetProvider' )]
	public function test_reduced_motion_is_honoured( string $path ): void {
		$css = self::css( $path );

		if ( ! str_contains( $css, 'transition' ) && ! str_contains( $css, 'animation' ) ) {
			$this->addToAssertionCount( 1 );

			return;
		}

		$this->assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $css );
	}

	/**
	 * Nothing is fetched from anywhere else.
	 *
	 * @param string $path The stylesheet.
	 */
	#[DataProvider( 'stylesheetProvider' )]
	public function test_no_external_requests( string $path ): void {
		$css = self::css( $path );

		$this->assertDoesNotMatchRegularExpression( '/url\(\s*["\']?https?:/', $css );
		$this->assertStringNotContainsString( '@import', $css );
	}

	/**
	 * The scripts fetch nothing from anywhere else either.
	 */
	public function test_the_scripts_make_no_external_requests(): void {
		foreach ( glob( dirname( __DIR__ ) . '/assets/js/*.js' ) as $path ) {
			$this->assertDoesNotMatchRegularExpression( '#https?://#', (string) file_get_contents( $path ), basename( $path ) );
		}
	}
}
