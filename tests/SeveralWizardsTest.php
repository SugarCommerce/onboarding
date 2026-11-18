<?php
/**
 * Several wizards and checklists on one install.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Onboarding\Onboarding;
use OnboardingRedirect;

/**
 * Two plugins, each with its own setup and its own guide, on one site.
 *
 * Every store either keeps is named from its own id, so neither reads,
 * finishes, resets or redirects to the other's — which is the whole of what
 * "does not interfere" means, tested a store at a time.
 */
final class SeveralWizardsTest extends OnboardingTestCase {

	/**
	 * Register two of each.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->wizard(
			[
				'option'   => 'shop_settings',
				'redirect' => true,
			],
			'shop-setup'
		);
		$this->wizard(
			[
				'option'   => 'forms_settings',
				'redirect' => true,
			],
			'forms-setup'
		);

		$tasks = [
			'read' => [ 'title' => 'Read' ],
		];

		$this->checklist( [ 'tasks' => $tasks ], 'shop-guide' );
		$this->checklist( [ 'tasks' => $tasks ], 'forms-guide' );
	}

	/**
	 * Each has its own page, input name, progress and answers.
	 */
	public function test_each_wizard_keeps_its_own_stores(): void {
		$shop  = Onboarding::get( 'shop-setup' );
		$forms = Onboarding::get( 'forms-setup' );

		$this->assertNotSame( $shop->slug(), $forms->slug() );
		$this->assertNotSame( $shop->input_name(), $forms->input_name() );
		$this->assertNotSame( $shop->state()->option(), $forms->state()->option() );

		$this->submit( $shop, 'welcome' );
		$this->submit( $shop, 'store', [ 'store_name' => 'Shop' ] );

		$this->assertSame( 'Shop', $GLOBALS['ob_options']['shop_settings']['store_name'] );
		$this->assertArrayNotHasKey( 'forms_settings', $GLOBALS['ob_options'] );
		$this->assertSame( [], $forms->state()->get()['done'] );
	}

	/**
	 * Finishing or resetting one leaves the other where it was.
	 */
	public function test_finishing_one_is_not_finishing_the_other(): void {
		Onboarding::get( 'shop-setup' )->complete();

		$this->assertTrue( (bool) Onboarding::get( 'shop-setup' )?->is_completed() );
		$this->assertFalse( (bool) Onboarding::get( 'forms-setup' )?->is_completed() );

		(bool) Onboarding::get( 'forms-setup' )?->reset();

		$this->assertTrue( (bool) Onboarding::get( 'shop-setup' )?->is_completed() );
	}

	/**
	 * Each nonce is its own wizard's and its own step's.
	 */
	public function test_a_nonce_is_the_wizards_own(): void {
		$this->submit( Onboarding::get( 'forms-setup' ), 'welcome' );

		$this->assertSame( 'onboarding_forms-setup_welcome', $GLOBALS['ob_nonce_checked'] );
	}

	/**
	 * Activating one plugin opens its wizard, not the other's.
	 */
	public function test_activating_one_opens_its_own_wizard(): void {
		Onboarding::redirect_after_activation( 'forms-setup' );

		try {
			Onboarding::route();
			$this->fail( 'Nothing was opened.' );
		} catch ( OnboardingRedirect $redirect ) {
			$this->assertStringContainsString( 'page=forms-setup', $redirect->url );
		}

		$this->assertSame( [], $GLOBALS['ob_transients'] );
	}

	/**
	 * Each page loads its own fields' assets, and only on its own screen.
	 */
	public function test_each_page_loads_only_its_own(): void {
		$_GET = [ 'page' => 'forms-setup' ];

		$this->assertSame( 'forms-setup', Onboarding::current()->id() );
		$this->assertStringContainsString( 'onboarding-wizard-forms-setup', Onboarding::body_class( '' ) );
		$this->assertStringNotContainsString( 'shop-setup', Onboarding::body_class( '' ) );
	}

	/**
	 * Each checklist keeps its own ticks and its own dismissals.
	 */
	public function test_each_checklist_keeps_its_own_state(): void {
		$shop  = Onboarding::checklist( 'shop-guide' );
		$forms = Onboarding::checklist( 'forms-guide' );

		$shop->mark( 'read', true );
		$forms->dismiss();

		$this->assertTrue( $shop->is_done( 'read' ) );
		$this->assertFalse( $forms->is_done( 'read' ) );
		$this->assertTrue( $forms->is_dismissed() );
		$this->assertFalse( $shop->is_dismissed() );
		$this->assertNotSame( $shop->state_option(), $forms->state_option() );
		$this->assertNotSame( $shop->dismissal_key(), $forms->dismissal_key() );
	}

	/**
	 * Each draws its own card, with its own id and route.
	 */
	public function test_each_checklist_draws_its_own_card(): void {
		$shop  = Onboarding::checklist( 'shop-guide' )->html();
		$forms = Onboarding::checklist( 'forms-guide' )->html();

		$this->assertStringContainsString( 'id="onboarding-checklist-shop-guide"', $shop );
		$this->assertStringContainsString( '/checklists/forms-guide"', $forms );
		$this->assertStringContainsString( 'name="onboarding-checklist-forms-guide"', $forms, 'Each list is its own accordion.' );
	}
}
