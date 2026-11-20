<?php
/**
 * Translation tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every string the library shows is translatable, in the one text domain
 * every SugarCommerce package uses.
 *
 * A string in the wrong domain, or in none, is English on every site
 * whatever its language — and nothing fails, so nobody notices. A plugin
 * that wants its own wording passes `labels`, which it translates itself.
 */
final class TranslationTest extends TestCase {

	/**
	 * Every gettext call in src/ names the library's domain.
	 */
	public function test_every_string_is_in_the_librarys_domain(): void {
		$wrong   = [];
		$checked = 0;

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src' ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$tokens = \PhpToken::tokenize( (string) file_get_contents( $file->getPathname() ) );

			foreach ( $tokens as $index => $token ) {
				if ( ! $token->is( T_STRING ) || ! in_array( $token->text, [ '__', '_e', '_n', '_x', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e' ], true ) ) {
					continue;
				}

				// The call's last string argument, read up to its closing bracket.
				$depth  = 0;
				$domain = null;

				for ( $i = $index + 1, $count = count( $tokens ); $i < $count; $i++ ) {
					$text = $tokens[ $i ]->text;

					if ( '(' === $text ) {
						++$depth;
					} elseif ( ')' === $text && 0 === --$depth ) {
						break;
					} elseif ( $tokens[ $i ]->is( T_CONSTANT_ENCAPSED_STRING ) && 1 === $depth ) {
						$domain = trim( $text, '\'"' );
					}
				}

				++$checked;

				if ( 'sugarcommerce' !== $domain ) {
					$wrong[] = sprintf( '%s:%d %s()', $file->getFilename(), $token->line, $token->text );
				}
			}
		}

		$this->assertGreaterThan( 40, $checked, 'The scan found almost no strings, so it is not reading the source.' );
		$this->assertSame( [], $wrong, "Not in the sugarcommerce domain:\n" . implode( "\n", $wrong ) );
	}

	/**
	 * The scripts say nothing in English that the page did not hand them.
	 *
	 * A message a script writes itself is a message no translator can reach.
	 * The checklist script reads its failure message from the card, which
	 * the server translated; its last-resort string is only for a card drawn
	 * without one, and is the server's own words, through wp.i18n.
	 */
	public function test_the_checklist_script_reads_its_message_from_the_page(): void {
		$config = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/lib/config.js' );
		$card   = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/checklist/card.js' );

		$this->assertStringContainsString( 'card.dataset.failed', $config );
		$this->assertStringContainsString( "__( 'That did not work. Please try again.', 'sugarcommerce' )", $card );
		$this->assertStringContainsString( "esc_attr__( 'That did not work. Please try again.', 'sugarcommerce' )", (string) file_get_contents( dirname( __DIR__ ) . '/src/ChecklistView.php' ) );
	}

	/**
	 * Every string the scripts translate themselves is in the library's
	 * domain too: the build is registered with wp_set_script_translations()
	 * under it, and a string in another is English on every site.
	 */
	public function test_every_script_string_is_in_the_librarys_domain(): void {
		$calls = 0;

		foreach ( glob( dirname( __DIR__ ) . '/assets/js/{,*/}*.js', GLOB_BRACE ) as $path ) {
			preg_match_all( '/\b(?:__|_x|_n|_nx)\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'\s*,\s*){1,3}\'([^\']*)\'\s*\)/', (string) file_get_contents( $path ), $matches );

			// Every call is read: one the pattern missed would go unchecked.
			$this->assertSame( preg_match_all( '/\b(?:__|_x|_n|_nx)\(/', (string) file_get_contents( $path ) ), count( $matches[1] ), basename( $path ) );

			foreach ( $matches[1] as $domain ) {
				++$calls;
				$this->assertSame( 'sugarcommerce', $domain, basename( $path ) );
			}
		}

		$this->assertGreaterThan( 0, $calls, 'The scan found no strings, so it is not reading the scripts.' );
	}
}
