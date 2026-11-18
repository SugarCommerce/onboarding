<?php
/**
 * Shared test scaffolding.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Onboarding\Checklist;
use SugarCommerce\Onboarding\Onboarding;
use SugarCommerce\Onboarding\Screen;
use SugarCommerce\Onboarding\Wizard;
use OnboardingRedirect;
use PHPUnit\Framework\TestCase;

/**
 * A wizard, a way to draw it, and a way to post to it.
 *
 * A wizard spans requests with a redirect between each, which a test cannot
 * follow. Posting here stops at the redirect and hands back where it went,
 * and drawing reads `$_GET` the way the next request would.
 */
abstract class OnboardingTestCase extends TestCase {

	/**
	 * Reset the stubbed globals and the registry.
	 */
	protected function setUp(): void {
		ob_reset_globals();
	}

	/**
	 * The steps most tests need: a welcome, a question, and an ending.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function steps(): array {
		return [
			'welcome' => [
				'title'   => 'Welcome',
				'content' => '<p>Hello.</p>',
			],
			'store'   => [
				'title'  => 'Your store',
				'fields' => [
					'store_name' => [
						'type'  => 'text',
						'label' => 'Store name',
					],
				],
			],
			'done'    => [
				'title' => 'Last thing',
			],
		];
	}

	/**
	 * Register a wizard.
	 *
	 * @param array<string, mixed> $config Overrides.
	 * @param string               $id     Its identifier.
	 *
	 * @return Wizard
	 */
	protected function wizard( array $config = [], string $id = 'myplugin' ): Wizard {
		$wizard = Onboarding::register( $id, array_merge( [ 'steps' => $this->steps() ], $config ) );

		$this->assertInstanceOf( Wizard::class, $wizard );

		return $wizard;
	}

	/**
	 * Register a checklist.
	 *
	 * @param array<string, mixed> $config Overrides.
	 * @param string               $id     Its identifier.
	 *
	 * @return Checklist
	 */
	protected function checklist( array $config = [], string $id = 'myguide' ): Checklist {
		$checklist = Onboarding::register_checklist( $id, $config );

		$this->assertInstanceOf( Checklist::class, $checklist );

		return $checklist;
	}

	/**
	 * Draw a wizard on a given step and return the markup.
	 *
	 * @param Wizard               $wizard The wizard.
	 * @param array<string, mixed> $get    The query.
	 *
	 * @return string
	 */
	protected function render( Wizard $wizard, array $get = [] ): string {
		$_GET = array_merge( [ 'page' => $wizard->slug() ], $get );

		ob_start();

		try {
			( new Screen( $wizard ) )->render();
		} finally {
			// Cleaned here and returned below: returning from a finally
			// block discards whatever exception was in flight, so a page
			// that refused to render would look like one that rendered
			// nothing.
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * Post a step.
	 *
	 * @param Wizard               $wizard The wizard.
	 * @param string               $step   The step's key.
	 * @param array<string, mixed> $values The field values.
	 * @param string               $intent next, skip or later.
	 *
	 * @return string|null Where it redirected, or null when it came back refused.
	 */
	protected function submit( Wizard $wizard, string $step, array $values = [], string $intent = 'next' ): ?string {
		$_POST = [
			Onboarding::INPUT     => $wizard->id(),
			'onboarding_step'     => $step,
			'onboarding_intent'   => $intent,
			$wizard->input_name() => $values,
		];

		try {
			$wizard->submit();
		} catch ( OnboardingRedirect $redirect ) {
			return $redirect->url;
		}

		return null;
	}

	/**
	 * Answer every step up to, not including, the one named.
	 *
	 * @param Wizard $wizard The wizard.
	 * @param string $until  The step to stop before.
	 *
	 * @return void
	 */
	protected function answer_until( Wizard $wizard, string $until ): void {
		foreach ( $wizard->keys() as $key ) {
			if ( $key === $until ) {
				return;
			}

			$this->assertNotNull( $this->submit( $wizard, $key ), "Step {$key} did not pass." );
		}
	}

	/**
	 * Whether a hook was fired.
	 *
	 * @param string $hook The hook.
	 *
	 * @return bool
	 */
	protected function fired( string $hook ): bool {
		return in_array( $hook, ob_fired(), true );
	}
}
