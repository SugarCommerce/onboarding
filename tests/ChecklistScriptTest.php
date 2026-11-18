<?php
/**
 * The checklist script's requests, run in Node.
 *
 * @package SugarCommerce\Onboarding\Tests
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The card's buttons go through wp.apiFetch, which renews an expired nonce,
 * and read its answer the way they read fetch's.
 */
final class ChecklistScriptTest extends TestCase {

	/**
	 * Run the script against a pretend page.
	 *
	 * @param string $scenario `ok` or `denied`.
	 *
	 * @return array<string, mixed>
	 */
	private function run_script( string $scenario ): array {
		$node = trim( (string) shell_exec( 'command -v node' ) );

		if ( '' === $node ) {
			$this->markTestSkipped( 'Node is not installed.' );
		}

		$output = shell_exec(
			escapeshellarg( $node ) . ' ' . escapeshellarg( __DIR__ . '/js/checklist-request.cjs' ) . ' '
			. escapeshellarg( dirname( __DIR__ ) . '/assets/js/checklist.js' ) . ' ' . escapeshellarg( $scenario )
		);

		$report = json_decode( (string) $output, true );
		$this->assertIsArray( $report, (string) $output );

		return $report;
	}

	public function test_a_button_posts_through_api_fetch_and_swaps_the_card(): void {
		$report = $this->run_script( 'ok' );

		$this->assertSame( [], $report['fetch'] );
		$this->assertCount( 1, $report['apiFetch'] );
		$this->assertSame( 'https://example.test/wp-json/guide/v1/checklist/dismiss', $report['apiFetch'][0]['url'] );
		$this->assertSame( 'POST', $report['apiFetch'][0]['method'] );
		$this->assertStringContainsString( '"dismissed":true', $report['apiFetch'][0]['body'] );
		$this->assertSame( '<div class="fresh"></div>', $report['replaced'] );
		$this->assertSame( 'Hidden.', $report['announced'] );
	}

	public function test_a_refusal_announces_the_servers_message(): void {
		$report = $this->run_script( 'denied' );

		$this->assertSame( '', $report['replaced'] );
		$this->assertSame( 'Not allowed.', $report['announced'] );
	}
}
