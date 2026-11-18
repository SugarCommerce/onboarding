<?php
/**
 * Wizard tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Onboarding\Onboarding;
use SugarCommerce\Onboarding\Wizard;
use OnboardingDied;
use OnboardingRedirect;

/**
 * A setup wizard is a form that runs before anything is set up, which is the
 * awkward part: it writes settings it does not own, on behalf of a user who
 * has not seen the plugin yet, across several requests with a redirect
 * between each, and it has to pick up where it left off.
 *
 * So what is tested here is the crossing points — which steps apply, which
 * one is current, where a submission goes, and when the thing is finished.
 */
final class WizardTest extends OnboardingTestCase {

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * A wizard with nothing to ask is not a wizard.
	 */
	public function test_a_wizard_without_steps_is_refused(): void {
		$this->assertNull( Onboarding::register( 'empty', [ 'steps' => [] ] ) );
		$this->assertNull( Onboarding::register( '', [ 'steps' => $this->steps() ] ) );
	}

	/**
	 * Registering attaches every hook the screen and the routes need, once.
	 */
	public function test_the_shared_hooks_are_attached_once(): void {
		$this->wizard();
		$this->wizard( [], 'another' );

		foreach ( [ 'admin_menu', 'admin_head', 'in_admin_header', 'admin_enqueue_scripts', 'admin_init', 'rest_api_init', 'wp_dashboard_setup', 'admin_body_class' ] as $hook ) {
			$this->assertTrue( has_action( $hook ), "{$hook} is not hooked." );
		}

		$this->assertCount( 1, $GLOBALS['ob_hooks']['admin_init'] );
	}

	/**
	 * The defaults a wizard that says little gets.
	 */
	public function test_the_defaults(): void {
		$wizard = $this->wizard();

		$this->assertSame( 'focus', $wizard->get( 'layout' ) );
		$this->assertSame( 'myplugin', $wizard->slug() );
		$this->assertSame( 'manage_options', $wizard->get( 'capability' ) );
		$this->assertSame( 'myplugin_onboarding', $wizard->state()->option() );
		$this->assertSame( 'onboarding_myplugin', $wizard->input_name() );
		$this->assertSame( 'Continue', $wizard->label( 'next' ) );
		$this->assertIsArray( $wizard->get( 'finish' ) );
	}

	/**
	 * A layout that is not one of the two is the default.
	 */
	public function test_an_unknown_layout_is_the_focus_layout(): void {
		$this->assertSame( 'admin', $this->wizard( [ 'layout' => 'admin' ] )->get( 'layout' ) );
		$this->assertSame( 'focus', $this->wizard( [ 'layout' => 'sideways' ], 'other' )->get( 'layout' ) );
	}

	/**
	 * The page is registered under its parent and taken back off the menu.
	 */
	public function test_the_page_is_registered_and_hidden(): void {
		$wizard = $this->wizard();

		Onboarding::register_menus();
		Onboarding::hide_menus();

		$this->assertArrayHasKey( 'myplugin', $GLOBALS['ob_menu'] );
		$this->assertSame( 'index.php', $GLOBALS['ob_menu']['myplugin']['parent_slug'] );
		$this->assertContains( 'myplugin', $GLOBALS['ob_removed_menu'] );
		$this->assertSame( 'toplevel_page_myplugin', $wizard->hook() );
	}

	/**
	 * One that asks to stay on the menu stays.
	 */
	public function test_a_visible_wizard_stays_on_the_menu(): void {
		$this->wizard( [ 'hidden' => false ] );

		Onboarding::register_menus();
		Onboarding::hide_menus();

		$this->assertNotContains( 'myplugin', $GLOBALS['ob_removed_menu'] );
	}

	/* ---------------------------------------------------------------------
	 * Steps
	 * ------------------------------------------------------------------ */

	/**
	 * A step whose callable says no is absent, not hidden.
	 */
	public function test_a_step_that_does_not_apply_is_absent(): void {
		$steps                     = $this->steps();
		$steps['store']['show_if'] = static fn( Wizard $wizard ): bool => false;

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->assertSame( [ 'welcome', 'done' ], $wizard->keys() );
		$this->assertNull( $wizard->step( 'store' ) );
		$this->assertFalse( $wizard->is_reachable( 'store' ) );
	}

	/**
	 * A step can depend on an earlier answer, as a field-kit condition.
	 */
	public function test_a_step_can_depend_on_an_earlier_answer(): void {
		$steps = [
			'selling'  => [
				'title'  => 'Selling',
				'fields' => [
					'sells' => [
						'type'    => 'select',
						'label'   => 'Sells',
						'options' => [
							'digital'  => 'Digital',
							'physical' => 'Physical',
						],
					],
				],
			],
			'shipping' => [
				'title'   => 'Shipping',
				'show_if' => [ 'sells' => [ 'physical' ] ],
			],
			'done'     => [ 'title' => 'Done' ],
		];

		$wizard = $this->wizard(
			[
				'steps'  => $steps,
				'option' => 'myplugin_settings',
			]
		);

		$this->assertSame( [ 'selling', 'done' ], $wizard->keys() );

		$this->submit( $wizard, 'selling', [ 'sells' => 'physical' ] );

		$this->assertSame( [ 'selling', 'shipping', 'done' ], $wizard->keys() );
	}

	/**
	 * Whether a step applies can be overruled by a filter.
	 */
	public function test_whether_a_step_applies_can_be_filtered(): void {
		add_filter(
			'sugarcommerce_onboarding_step_applies_myplugin',
			static fn( bool $applies, string $key ): bool => 'done' === $key ? false : $applies,
			10,
			2
		);

		$this->assertSame( [ 'welcome', 'store' ], $this->wizard()->keys() );
	}

	/**
	 * An add-on can add a step without touching the configuration, even
	 * when it loads after the wizard was registered.
	 */
	public function test_steps_can_be_added_through_the_filter(): void {
		$wizard = $this->wizard();

		add_filter(
			'sugarcommerce_onboarding_steps_myplugin',
			static fn( array $steps ): array => $steps + [ 'addon' => [ 'title' => 'From an add-on' ] ]
		);

		$this->assertSame( [ 'welcome', 'store', 'done', 'addon' ], $wizard->keys() );
		$this->assertSame( '', $wizard->step( 'addon' )['description'], 'An added step is normalized like any other.' );
	}

	/**
	 * A step that is not an array, or has no usable key, is dropped.
	 */
	public function test_a_malformed_step_is_dropped(): void {
		$wizard = $this->wizard(
			[
				'steps' => [
					'ok'  => [ 'title' => 'Fine' ],
					'bad' => 'not a step',
					'!!!' => [ 'title' => 'No key left' ],
				],
			]
		);

		$this->assertSame( [ 'ok' ], $wizard->keys() );
	}

	/**
	 * Neighbours, ends and positions.
	 */
	public function test_neighbours_and_positions(): void {
		$wizard = $this->wizard();

		$this->assertSame( 'store', $wizard->adjacent( 'welcome', 1 ) );
		$this->assertSame( 'welcome', $wizard->adjacent( 'store', -1 ) );
		$this->assertSame( '', $wizard->adjacent( 'welcome', -1 ) );
		$this->assertSame( '', $wizard->adjacent( 'nowhere', 1 ) );
		$this->assertTrue( $wizard->is_last( 'done' ) );
		$this->assertFalse( $wizard->is_last( 'store' ) );
		$this->assertSame( 2, $wizard->position( 'store' ) );
		$this->assertSame( 0, $wizard->position( 'nowhere' ) );
	}

	/* ---------------------------------------------------------------------
	 * Resume
	 * ------------------------------------------------------------------ */

	/**
	 * A fresh wizard starts at the beginning, and cannot be skipped ahead by URL.
	 */
	public function test_a_fresh_wizard_cannot_be_jumped_ahead(): void {
		$wizard = $this->wizard();

		$_GET = [ 'step' => 'done' ];

		$this->assertSame( 'welcome', $wizard->current_key() );
		$this->assertTrue( $wizard->is_reachable( 'welcome' ) );
		$this->assertFalse( $wizard->is_reachable( 'store' ) );
	}

	/**
	 * Opened with no step, it resumes after the last one dealt with.
	 */
	public function test_it_resumes_where_it_left_off(): void {
		$wizard = $this->wizard();

		$this->submit( $wizard, 'welcome' );

		$_GET = [];

		$this->assertSame( 'store', $wizard->current_key() );
		$this->assertSame( 'store', $wizard->resume_key() );
	}

	/**
	 * A step already dealt with can be gone back to.
	 */
	public function test_an_earlier_step_can_be_reopened(): void {
		$wizard = $this->wizard();

		$this->answer_until( $wizard, 'done' );

		$_GET = [ 'step' => 'welcome' ];

		$this->assertSame( 'welcome', $wizard->current_key() );
		$this->assertTrue( $wizard->is_reachable( 'done' ) );
	}

	/**
	 * Once every step is dealt with, every step can be opened.
	 */
	public function test_every_step_is_reachable_once_all_are_dealt_with(): void {
		$wizard = $this->wizard();

		$this->answer_until( $wizard, '' );

		$this->assertSame( '', $wizard->resume_key() );

		foreach ( $wizard->keys() as $key ) {
			$this->assertTrue( $wizard->is_reachable( $key ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Submission
	 * ------------------------------------------------------------------ */

	/**
	 * Continue stores the step, marks it done and moves on.
	 */
	public function test_continue_moves_to_the_next_step(): void {
		$wizard = $this->wizard();

		$this->assertSame(
			'https://example.test/wp-admin/index.php?page=myplugin&step=store',
			$this->submit( $wizard, 'welcome' )
		);
		$this->assertSame( [ 'welcome' ], $wizard->state()->get()['done'] );
		$this->assertSame( 'store', $wizard->state()->get()['current'] );
		$this->assertTrue( $this->fired( 'sugarcommerce_onboarding_step_saved_myplugin' ) );
	}

	/**
	 * A submission is checked against its own step's nonce.
	 */
	public function test_a_submission_needs_its_nonce(): void {
		$wizard = $this->wizard();

		$GLOBALS['ob_nonce_ok'] = false;

		try {
			$this->submit( $wizard, 'welcome' );
			$this->fail( 'A submission without its nonce went through.' );
		} catch ( OnboardingDied $died ) {
			$this->assertSame( 'nonce', $died->getMessage() );
		}

		$GLOBALS['ob_nonce_ok'] = true;

		$this->submit( $wizard, 'welcome' );

		$this->assertSame( 'onboarding_myplugin_welcome', $GLOBALS['ob_nonce_checked'] );
	}

	/**
	 * Somebody without the capability is refused, and nothing is stored.
	 */
	public function test_a_submission_needs_the_capability(): void {
		$wizard = $this->wizard( [ 'capability' => 'install_plugins' ] );

		$this->expectException( OnboardingDied::class );

		try {
			$this->submit( $wizard, 'welcome' );
		} finally {
			$this->assertSame( [], $wizard->state()->get()['done'] );
		}
	}

	/**
	 * A step not reached yet is not saved, and goes back to where the wizard is.
	 */
	public function test_a_step_not_reached_is_not_saved(): void {
		$wizard = $this->wizard( [ 'option' => 'myplugin_settings' ] );

		$url = $this->submit( $wizard, 'store', [ 'store_name' => 'Early' ] );

		$this->assertSame( 'https://example.test/wp-admin/index.php?page=myplugin', $url );
		$this->assertArrayNotHasKey( 'myplugin_settings', $GLOBALS['ob_options'] );
		$this->assertFalse( $wizard->state()->is_done( 'store' ) );
	}

	/**
	 * A step that stopped applying while its form was open is not saved.
	 */
	public function test_a_step_that_no_longer_applies_is_not_saved(): void {
		$applies                   = true;
		$steps                     = $this->steps();
		$steps['store']['show_if'] = static function () use ( &$applies ): bool {
			return $applies;
		};

		$wizard = $this->wizard(
			[
				'steps'  => $steps,
				'option' => 'myplugin_settings',
			]
		);

		$this->submit( $wizard, 'welcome' );

		$applies = false;

		$this->submit( $wizard, 'store', [ 'store_name' => 'Stale' ] );

		$this->assertArrayNotHasKey( 'myplugin_settings', $GLOBALS['ob_options'] );
	}

	/**
	 * Skip moves on without storing anything, and is remembered as skipped.
	 */
	public function test_skip_moves_on_and_stores_nothing(): void {
		$steps                       = $this->steps();
		$steps['store']['skippable'] = true;

		$wizard = $this->wizard(
			[
				'steps'  => $steps,
				'option' => 'myplugin_settings',
			]
		);

		$this->submit( $wizard, 'welcome' );

		$url = $this->submit( $wizard, 'store', [ 'store_name' => 'Not saved' ], 'skip' );

		$this->assertSame( 'https://example.test/wp-admin/index.php?page=myplugin&step=done', $url );
		$this->assertArrayNotHasKey( 'myplugin_settings', $GLOBALS['ob_options'] );
		$this->assertTrue( $wizard->state()->is_skipped( 'store' ) );
		$this->assertSame( 'skipped', $wizard->statuses()['store'] );
		$this->assertTrue( $this->fired( 'sugarcommerce_onboarding_step_skipped_myplugin' ) );
	}

	/**
	 * A step that cannot be skipped is drawn again, not advanced.
	 */
	public function test_a_step_that_cannot_be_skipped_is_not(): void {
		$wizard = $this->wizard();

		$this->submit( $wizard, 'welcome' );

		$url = $this->submit( $wizard, 'store', [], 'skip' );

		$this->assertSame( 'https://example.test/wp-admin/index.php?page=myplugin&step=store', $url );
		$this->assertFalse( $wizard->state()->is_skipped( 'store' ) );
		$this->assertFalse( $this->fired( 'sugarcommerce_onboarding_step_skipped_myplugin' ) );
	}

	/**
	 * Skipping the last step finishes the wizard.
	 */
	public function test_skipping_the_last_step_finishes(): void {
		$steps                      = $this->steps();
		$steps['done']['skippable'] = true;

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->answer_until( $wizard, 'done' );
		$this->submit( $wizard, 'done', [], 'skip' );

		$this->assertTrue( $wizard->is_completed() );
	}

	/**
	 * An answer replaces an earlier skip.
	 */
	public function test_answering_a_skipped_step_makes_it_done(): void {
		$steps                       = $this->steps();
		$steps['store']['skippable'] = true;

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->submit( $wizard, 'welcome' );
		$this->submit( $wizard, 'store', [], 'skip' );
		$this->submit( $wizard, 'store', [ 'store_name' => 'Now' ] );

		$this->assertSame( 'done', $wizard->statuses()['store'] );
	}

	/**
	 * Save and finish later stores the step and leaves, to resume at the next.
	 */
	public function test_save_and_finish_later(): void {
		$wizard = $this->wizard(
			[
				'option'   => 'myplugin_settings',
				'exit_url' => 'https://example.test/wp-admin/admin.php?page=myplugin-home',
			]
		);

		$this->submit( $wizard, 'welcome' );

		$url = $this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ], 'later' );

		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=myplugin-home', $url );
		$this->assertSame( 'Acme', $GLOBALS['ob_options']['myplugin_settings']['store_name'] );

		$_GET = [];

		$this->assertSame( 'done', $wizard->current_key() );
		$this->assertFalse( $wizard->is_completed() );
	}

	/**
	 * An unknown intent is Continue.
	 */
	public function test_an_unknown_intent_is_continue(): void {
		$wizard = $this->wizard();

		$this->assertStringContainsString( 'step=store', (string) $this->submit( $wizard, 'welcome', [], 'teleport' ) );
	}

	/* ---------------------------------------------------------------------
	 * Completion
	 * ------------------------------------------------------------------ */

	/**
	 * Finishing the last step records it, fires the hook, and opens the finish screen.
	 */
	public function test_finishing_records_it_and_opens_the_finish_screen(): void {
		$wizard = $this->wizard();

		$this->answer_until( $wizard, 'done' );

		$this->assertFalse( $wizard->is_completed() );

		$url = $this->submit( $wizard, 'done' );

		$this->assertSame( 'https://example.test/wp-admin/index.php?page=myplugin&finished=1', $url );
		$this->assertTrue( $wizard->is_completed() );
		$this->assertTrue( (bool) Onboarding::get( 'myplugin' )?->is_completed() );
		$this->assertTrue( $this->fired( 'sugarcommerce_onboarding_completed_myplugin' ) );
	}

	/**
	 * A wizard with its own destination goes there instead.
	 */
	public function test_finishing_goes_to_the_completed_redirect(): void {
		$wizard = $this->wizard( [ 'completed_redirect' => 'https://example.test/wp-admin/admin.php?page=shop' ] );

		$this->answer_until( $wizard, 'done' );

		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=shop', $this->submit( $wizard, 'done' ) );
	}

	/**
	 * With no finish screen, finishing goes to the exit.
	 */
	public function test_finishing_without_a_finish_screen_goes_to_the_exit(): void {
		$wizard = $this->wizard( [ 'finish' => false ] );

		$this->answer_until( $wizard, 'done' );

		$this->assertSame( 'https://example.test/wp-admin/', $this->submit( $wizard, 'done' ) );
		$this->assertFalse( $wizard->is_finish_screen() );
	}

	/**
	 * Where a finished wizard goes can be filtered.
	 */
	public function test_the_completed_redirect_can_be_filtered(): void {
		$wizard = $this->wizard();

		add_filter( 'sugarcommerce_onboarding_completed_redirect_myplugin', static fn(): string => 'https://example.test/elsewhere' );

		$this->answer_until( $wizard, 'done' );

		$this->assertSame( 'https://example.test/elsewhere', $this->submit( $wizard, 'done' ) );
	}

	/**
	 * The finish screen is only for a finished wizard.
	 */
	public function test_the_finish_screen_needs_a_finished_wizard(): void {
		$wizard = $this->wizard();

		$_GET = [ 'finished' => 1 ];

		$this->assertFalse( $wizard->is_finish_screen() );

		$wizard->complete();

		$this->assertTrue( $wizard->is_finish_screen() );

		$_GET = [ 'step' => 'store' ];

		$this->assertFalse( $wizard->is_finish_screen(), 'A finished wizard can still be reopened at a step.' );

		$_GET = [];

		$this->assertTrue( $wizard->is_finish_screen(), 'A finished wizard opened bare shows what to do next.' );
	}

	/**
	 * Resetting runs it again, and keeps the answers.
	 */
	public function test_a_reset_keeps_the_answers(): void {
		$wizard = $this->wizard( [ 'option' => 'myplugin_settings' ] );

		$this->answer_until( $wizard, 'store' );
		$this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] );
		$wizard->complete();

		$this->assertTrue( (bool) Onboarding::get( 'myplugin' )?->reset() );

		$this->assertFalse( $wizard->is_completed() );
		$this->assertSame( 'welcome', $wizard->resume_key() );
		$this->assertSame( 'Acme', $wizard->value( 'store_name' ) );
		$this->assertTrue( $this->fired( 'sugarcommerce_onboarding_reset_myplugin' ) );
	}

	/**
	 * Progress, in the shape an API wants.
	 */
	public function test_progress(): void {
		$steps                       = $this->steps();
		$steps['store']['skippable'] = true;

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->submit( $wizard, 'welcome' );
		$this->submit( $wizard, 'store', [], 'skip' );

		$progress = ( Onboarding::get( 'myplugin' )?->progress() ?? [] );

		$this->assertFalse( $progress['completed'] );
		$this->assertSame( 3, $progress['total'] );
		$this->assertSame( 1, $progress['done'] );
		$this->assertSame( 1, $progress['skipped'] );
		$this->assertSame( 67, $progress['percent'] );
		$this->assertSame( 'done', $progress['resume'] );
		$this->assertSame(
			[
				'title'  => 'Your store',
				'status' => 'skipped',
			],
			$progress['steps']['store']
		);
		$this->assertSame( [], ( Onboarding::get( 'nothing' )?->progress() ?? [] ) );
	}

	/* ---------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------ */

	/**
	 * A step URL is reached through its parent's file.
	 */
	public function test_a_step_url_uses_the_parent_file(): void {
		$wizard = $this->wizard( [ 'parent_slug' => 'tools.php' ] );

		$this->assertSame( 'https://example.test/wp-admin/tools.php?page=myplugin&step=store', $wizard->url( 'store' ) );
		$this->assertSame( 'https://example.test/wp-admin/tools.php?page=myplugin&finished=1', $wizard->finish_url() );
	}

	/**
	 * Under another plugin's page, through admin.php.
	 */
	public function test_a_page_under_a_plugin_page_uses_admin_php(): void {
		$GLOBALS['_parent_pages']['myplugin-home'] = '';

		$this->wizard( [ 'parent_slug' => 'myplugin-home' ] );

		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=myplugin', ( Onboarding::get( 'myplugin' )?->url() ?? '' ) );
		$this->assertSame( '', ( Onboarding::get( 'nothing' )?->url() ?? '' ) );
	}

	/**
	 * Under a plugin's page when the menu has not been built -- from
	 * admin-post.php, REST or cron -- still through admin.php, not through a
	 * file named after the parent's slug.
	 */
	public function test_a_page_under_a_plugin_page_uses_admin_php_without_the_menu(): void {
		$GLOBALS['_parent_pages'] = [];

		$wizard = $this->wizard( [ 'parent_slug' => 'myplugin-home' ] );

		$this->assertSame( 'https://example.test/wp-admin/admin.php?page=myplugin&step=store', $wizard->url( 'store' ) );
	}

	/**
	 * The way out defaults to the dashboard.
	 */
	public function test_the_exit(): void {
		$this->assertSame( 'https://example.test/wp-admin/', $this->wizard()->exit_url() );
		$this->assertSame( 'https://example.test/x', $this->wizard( [ 'exit_url' => 'https://example.test/x' ], 'other' )->exit_url() );
	}

	/* ---------------------------------------------------------------------
	 * Activation redirect
	 * ------------------------------------------------------------------ */

	/**
	 * Activation sends the user to the wizard once.
	 */
	public function test_the_activation_redirect_happens_once(): void {
		$this->wizard( [ 'redirect' => true ] );

		Onboarding::redirect_after_activation( 'myplugin' );

		try {
			Onboarding::route();
			$this->fail( 'Activation did not redirect.' );
		} catch ( OnboardingRedirect $redirect ) {
			$this->assertSame( 'https://example.test/wp-admin/index.php?page=myplugin', $redirect->url );
		}

		Onboarding::route();

		$this->assertSame( [], $GLOBALS['ob_transients'] );
	}

	/**
	 * Not to a finished wizard, not in a bulk activation, not for somebody
	 * who cannot run it, not over AJAX, not to one that did not ask, and not
	 * when a filter says no.
	 *
	 * @param string $situation What is different.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'noRedirectProvider' )]
	public function test_the_activation_redirect_stays_out_of_the_way( string $situation ): void {
		$wizard = $this->wizard( [ 'redirect' => 'unasked' !== $situation ] );

		if ( 'finished' === $situation ) {
			$wizard->complete();
		} elseif ( 'bulk' === $situation ) {
			$_GET['activate-multi'] = 'true';
		} elseif ( 'capability' === $situation ) {
			$GLOBALS['ob_caps'] = [];
		} elseif ( 'ajax' === $situation ) {
			$GLOBALS['ob_doing_ajax'] = true;
		} elseif ( 'filtered' === $situation ) {
			add_filter( 'sugarcommerce_onboarding_redirect_myplugin', static fn(): bool => false );
		}

		Onboarding::redirect_after_activation( 'myplugin' );

		Onboarding::route();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The situations.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function noRedirectProvider(): array {
		return [
			'finished'   => [ 'finished' ],
			'bulk'       => [ 'bulk' ],
			'capability' => [ 'capability' ],
			'ajax'       => [ 'ajax' ],
			'unasked'    => [ 'unasked' ],
			'filtered'   => [ 'filtered' ],
		];
	}

	/* ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------ */

	/**
	 * A submission is handed to the wizard it names, and only that one.
	 */
	public function test_a_submission_reaches_the_wizard_it_names(): void {
		$first  = $this->wizard();
		$second = $this->wizard( [], 'second' );

		$_POST = [
			Onboarding::INPUT   => 'second',
			'onboarding_step'   => 'welcome',
			'onboarding_intent' => 'next',
		];

		try {
			Onboarding::route();
			$this->fail( 'The submission was not handled.' );
		} catch ( OnboardingRedirect $redirect ) {
			$this->assertStringContainsString( 'page=second', $redirect->url );
		}

		$this->assertTrue( $second->state()->is_done( 'welcome' ) );
		$this->assertFalse( $first->state()->is_done( 'welcome' ) );
	}

	/**
	 * A wizard can be forgotten.
	 */
	public function test_unregistering(): void {
		$this->wizard();

		$this->assertTrue( Onboarding::unregister( 'myplugin' ) );
		$this->assertFalse( Onboarding::unregister( 'myplugin' ) );
		$this->assertNull( Onboarding::get( 'myplugin' ) );
		$this->assertFalse( (bool) Onboarding::get( 'myplugin' )?->is_completed() );
		$this->assertFalse( (bool) Onboarding::get( 'myplugin' )?->reset() );
	}
}
