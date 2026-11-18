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
	 * without one.
	 */
	public function test_the_checklist_script_reads_its_message_from_the_page(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/checklist.js' );

		$this->assertStringContainsString( 'card.dataset.failed', $script );
	}
}
