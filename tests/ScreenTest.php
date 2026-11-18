<?php
/**
 * Screen tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Onboarding\Onboarding;
use SugarCommerce\Onboarding\Utils\Runtime;
use SugarCommerce\Onboarding\Wizard;
use OnboardingDied;

/**
 * What a step looks like, to the eye and to a screen reader.
 *
 * The decisions worth guarding are the ones that are easy to undo without
 * noticing: that Enter means Continue, that the progress is a list of places
 * with one marked current, that a step comes back saying exactly what to fix
 * and where, and that nothing on a step reaches for a page or a script it
 * does not need.
 */
final class ScreenTest extends OnboardingTestCase {

	/* ---------------------------------------------------------------------
	 * Progress
	 * ------------------------------------------------------------------ */

	/**
	 * By default the progress is above the card: a count with the step's
	 * name, and a named list of one segment per step, the current marked.
	 */
	public function test_the_progress_is_above_the_card(): void {
		$html = $this->render( $this->wizard() );

		$this->assertStringContainsString( 'class="onboarding__layout onboarding__layout--top"', $html );
		$this->assertStringContainsString( '<nav class="onboarding__progress" aria-label="Setup progress">', $html );
		$this->assertStringContainsString( '<ol class="onboarding__segments" style="--onboarding-steps:3">', $html );
		$this->assertSame( 3, substr_count( $html, 'class="onboarding__segment ' ) );
		$this->assertSame( 1, substr_count( $html, 'aria-current="step"' ) );
		$this->assertMatchesRegularExpression( '/is-current" aria-current="step">.*?Welcome/s', $html );
		$this->assertStringContainsString( '>Step 1 of 3</span>', $html );
		$this->assertStringContainsString( '<span class="onboarding__count-name" aria-hidden="true"> · Welcome</span>', $html );
		$this->assertStringNotContainsString( 'onboarding__rail', $html );
		$this->assertLessThan( strpos( $html, 'onboarding__card' ), strpos( $html, 'onboarding__progress' ), 'The progress comes before the card.' );
	}

	/**
	 * A wizard that asks for it gets the rail beside the card instead.
	 */
	public function test_the_progress_can_be_a_rail_beside_the_card(): void {
		$html = $this->render( $this->wizard( [ 'progress' => 'side' ] ) );

		$this->assertStringContainsString( 'class="onboarding__layout onboarding__layout--side"', $html );
		$this->assertStringContainsString( '<nav class="onboarding__rail" aria-label="Setup progress">', $html );
		$this->assertStringContainsString( '<ol class="onboarding__steps">', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="step"' ) );
		$this->assertStringNotContainsString( 'onboarding__segments', $html );
	}

	/**
	 * Anything but "side" is the default.
	 */
	public function test_an_unknown_progress_is_the_default(): void {
		$this->assertSame( 'top', $this->wizard()->get( 'progress' ) );
		$this->assertSame( 'top', $this->wizard( [ 'progress' => 'bottom' ], 'other' )->get( 'progress' ) );
	}

	/**
	 * A single step has nothing to show progress through, either way.
	 */
	public function test_a_single_step_has_no_progress(): void {
		$steps = [ 'steps' => [ 'only' => [ 'title' => 'Only' ] ] ];

		$this->assertStringNotContainsString( 'onboarding__progress', $this->render( $this->wizard( $steps ) ) );
		$this->assertStringNotContainsString( 'onboarding__rail', $this->render( $this->wizard( $steps + [ 'progress' => 'side' ], 'other' ) ) );
	}

	/**
	 * Steps already dealt with are links back; the current one and those
	 * ahead are not — in either layout.
	 *
	 * @param string $progress top or side.
	 * @param string $link     The class of a step's link.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'progressProvider' )]
	public function test_only_steps_already_reached_are_links( string $progress, string $link ): void {
		$wizard = $this->wizard( [ 'progress' => $progress ] );

		$this->submit( $wizard, 'welcome' );

		$html = $this->render( $wizard, [ 'step' => 'store' ] );

		$this->assertStringContainsString( '<a class="' . $link . '" href="https://example.test/wp-admin/index.php?page=myplugin&#038;step=welcome"', $html );
		$this->assertStringNotContainsString( 'step=done"', $html );
		$this->assertMatchesRegularExpression( '/is-current" aria-current="step"><span class="' . $link . '">/', $html );
	}

	/**
	 * A done step is ticked and says so; a skipped one says so where it
	 * can be seen — in either layout.
	 *
	 * @param string $progress top or side.
	 * @param string $link     The class of a step's link.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'progressProvider' )]
	public function test_done_and_skipped_steps_say_so( string $progress, string $link ): void {
		$steps                       = $this->steps();
		$steps['store']['skippable'] = true;

		$wizard = $this->wizard(
			[
				'steps'    => $steps,
				'progress' => $progress,
			]
		);

		$this->submit( $wizard, 'welcome' );
		$this->submit( $wizard, 'store', [], 'skip' );

		$html  = $this->render( $wizard, [ 'step' => 'done' ] );
		$block = 'top' === $progress ? 'onboarding__segment' : 'onboarding__step';

		$this->assertStringContainsString( $block . '--done', $html );
		$this->assertStringContainsString( '<span class="screen-reader-text">(completed)</span>', $html );
		$this->assertStringContainsString( $block . '--skipped', $html );
		$this->assertStringContainsString( '<span class="onboarding__step-status">Skipped</span>', $html );
	}

	/**
	 * The two layouts.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function progressProvider(): array {
		return [
			'top'  => [ 'top', 'onboarding__segment-link' ],
			'side' => [ 'side', 'onboarding__step-link' ],
		];
	}

	/**
	 * A step's short title names it in the progress; its heading stays whole.
	 */
	public function test_a_short_title_names_the_step_in_the_progress(): void {
		$steps                           = $this->steps();
		$steps['welcome']['title']       = 'Welcome to the very long name';
		$steps['welcome']['short_title'] = 'Welcome';

		$html = $this->render( $this->wizard( [ 'steps' => $steps ] ) );

		$this->assertStringContainsString( '<span class="onboarding__segment-label">Welcome</span>', $html );
		$this->assertStringContainsString( '>Welcome to the very long name</h2>', $html );
		$this->assertStringContainsString( ' · Welcome to the very long name</span>', $html );
	}

	/**
	 * On the finish screen every segment is done and the count says so.
	 */
	public function test_the_finish_screen_progress_is_complete(): void {
		$wizard = $this->wizard();

		$wizard->complete();

		$html = $this->render( $wizard, [ 'finished' => 1 ] );

		$this->assertStringContainsString( 'Setup complete', $html );
		$this->assertSame( 3, substr_count( $html, 'onboarding__segment--done' ) );
	}

	/* ---------------------------------------------------------------------
	 * The form
	 * ------------------------------------------------------------------ */

	/**
	 * Continue is the first submit button and the only one the browser
	 * validates, so Enter means Continue.
	 */
	public function test_continue_comes_first(): void {
		$steps                       = $this->steps();
		$steps['store']['skippable'] = true;

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->submit( $wizard, 'welcome' );

		$html = $this->render( $wizard, [ 'step' => 'store' ] );

		preg_match_all( '/<button type="submit"[^>]*>/', $html, $buttons );

		$this->assertCount( 3, $buttons[0] );
		$this->assertStringContainsString( 'value="next"', $buttons[0][0] );
		$this->assertStringNotContainsString( 'formnovalidate', $buttons[0][0] );
		$this->assertStringContainsString( 'value="skip"', $buttons[0][1] );
		$this->assertStringContainsString( 'formnovalidate', $buttons[0][1] );
		$this->assertStringContainsString( 'value="later"', $buttons[0][2] );
		$this->assertStringContainsString( 'formnovalidate', $buttons[0][2] );
	}

	/**
	 * Skip only where a step can be skipped, and Save and finish later only
	 * where there is something to save and more to come.
	 */
	public function test_the_quiet_buttons_appear_only_where_they_mean_something(): void {
		$wizard = $this->wizard();

		$welcome = $this->render( $wizard );

		$this->assertStringNotContainsString( 'value="skip"', $welcome );
		$this->assertStringNotContainsString( 'value="later"', $welcome, 'A step with no fields has nothing to save.' );

		$this->answer_until( $wizard, 'done' );

		$this->assertStringNotContainsString( 'value="later"', $this->render( $wizard, [ 'step' => 'done' ] ), 'The last step finishes instead.' );
	}

	/**
	 * A step whose only field is a button has nothing to save later.
	 */
	public function test_a_step_of_only_buttons_has_nothing_to_save_later(): void {
		$steps                    = $this->steps();
		$steps['store']['fields'] = [
			'create' => [
				'type'            => 'action_button',
				'label'           => 'Create',
				'action_callback' => static fn(): string => 'Done.',
			],
		];

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->submit( $wizard, 'welcome' );

		$this->assertStringNotContainsString( 'value="later"', $this->render( $wizard, [ 'step' => 'store' ] ) );
	}

	/**
	 * The main button says Finish on the last step, and a step may name its own.
	 */
	public function test_the_main_button_label(): void {
		$steps                      = $this->steps();
		$steps['welcome']['button'] = 'Get Started';

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->assertStringContainsString( '>Get Started</button>', $this->render( $wizard ) );

		$this->answer_until( $wizard, 'done' );

		$this->assertStringContainsString( '>Finish Setup</button>', $this->render( $wizard, [ 'step' => 'done' ] ) );
	}

	/**
	 * Back is a link above the heading, and the first step has none.
	 */
	public function test_back_is_a_link_to_the_step_before(): void {
		$wizard = $this->wizard();

		$this->assertStringNotContainsString( 'onboarding__back', $this->render( $wizard ) );

		$this->submit( $wizard, 'welcome' );

		$html = $this->render( $wizard, [ 'step' => 'store' ] );

		$this->assertMatchesRegularExpression( '/<a class="onboarding__back" href="[^"]*step=welcome"/', $html );
		$this->assertLessThan( strpos( $html, 'onboarding__heading' ), strpos( $html, 'onboarding__back' ) );
	}

	/**
	 * The form posts to its own step, with its own nonce and its wizard named.
	 */
	public function test_the_form_posts_to_its_own_step(): void {
		$wizard = $this->wizard();

		$this->submit( $wizard, 'welcome' );

		$html = $this->render( $wizard, [ 'step' => 'store' ] );

		$this->assertStringContainsString( 'action="https://example.test/wp-admin/index.php?page=myplugin&#038;step=store"', $html );
		$this->assertStringContainsString( 'name="' . Onboarding::INPUT . '" value="myplugin"', $html );
		$this->assertStringContainsString( 'name="onboarding_step" value="store"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'data-fields="onboarding_myplugin"', $html );
	}

	/**
	 * The heading is where focus goes, so it can take it.
	 */
	public function test_the_heading_can_take_focus(): void {
		$this->assertStringContainsString(
			'<h2 class="onboarding__heading" id="onboarding-heading" tabindex="-1">Welcome</h2>',
			$this->render( $this->wizard() )
		);
	}

	/* ---------------------------------------------------------------------
	 * Errors
	 * ------------------------------------------------------------------ */

	/**
	 * A refused step lists what to fix, each a link to its field, as an alert.
	 */
	public function test_errors_are_an_alert_linking_to_their_fields(): void {
		$steps                                         = $this->steps();
		$steps['store']['fields']['store_name']['required'] = true;
		$steps['store']['validate']                    = static fn(): array => [ 'anything' => 'The service is down.' ];

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->submit( $wizard, 'welcome' );
		$this->submit( $wizard, 'store', [ 'store_name' => '' ] );

		$html = $this->render( $wizard, [ 'step' => 'store' ] );

		$this->assertStringContainsString( 'class="onboarding__errors" role="alert" tabindex="-1"', $html );
		$this->assertStringContainsString( 'Some answers need another look.', $html );

		// The kit's message names the field already, so it is not said twice.
		$this->assertMatchesRegularExpression( '/<li><a href="#[^"]+">Store name is required\.<\/a><\/li>/', $html );
		$this->assertStringContainsString( '<li>The service is down.</li>', $html );

		// And again beside the field, which the kit draws.
		$this->assertStringContainsString( 'aria-invalid="true"', $html );
	}

	/**
	 * A message that does not name its field gets the field's label in front.
	 */
	public function test_a_message_that_does_not_name_its_field_is_labelled(): void {
		$steps                      = $this->steps();
		$steps['store']['validate'] = static fn(): array => [ 'store_name' => 'Already taken.' ];

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->submit( $wizard, 'welcome' );
		$this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] );

		$this->assertStringContainsString( '>Store name: Already taken.</a>', $this->render( $wizard, [ 'step' => 'store' ] ) );
	}

	/**
	 * One problem is talked about in the singular.
	 */
	public function test_one_problem_is_singular(): void {
		$steps                      = $this->steps();
		$steps['store']['validate'] = static fn(): string => 'No.';

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->submit( $wizard, 'welcome' );
		$this->submit( $wizard, 'store' );

		$this->assertStringContainsString( 'One answer needs another look.', $this->render( $wizard, [ 'step' => 'store' ] ) );
	}

	/* ---------------------------------------------------------------------
	 * Content
	 * ------------------------------------------------------------------ */

	/**
	 * Prose is filtered, titles escaped, items and links drawn.
	 */
	public function test_content_is_filtered_and_titles_are_escaped(): void {
		$html = $this->render(
			$this->wizard(
				[
					'steps' => [
						'welcome' => [
							'title'   => '<script>x</script>Hello',
							'content' => '<p>Fine</p><script>alert(1)</script>',
							'items'   => [
								[
									'icon'  => 'dashicons-chart-bar',
									'title' => 'Charts',
								],
							],
						],
					],
				]
			)
		);

		$this->assertStringContainsString( '&lt;script&gt;x&lt;/script&gt;Hello', $html );
		$this->assertStringContainsString( '<p>Fine</p>', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( 'class="dashicons dashicons-chart-bar sugarcommerce-icon"', $html, 'An icon keeps its whole name.' );
	}

	/**
	 * An item's icon is a `set:name` icon as inline SVG, or a Dashicon by
	 * its name or its class as the font's span; with none, or one that
	 * cannot be drawn, it is a tick.
	 */
	public function test_an_items_icon_in_either_form(): void {
		$html = $this->render(
			$this->wizard(
				[
					'steps' => [
						'welcome' => [
							'title' => 'Welcome',
							'items' => [
								[
									'icon'  => 'heroicons-outline:chart-bar',
									'title' => 'Set',
								],
								[
									'icon'  => 'chart-bar',
									'title' => 'Bare',
								],
								[
									'icon'  => 'dashicons-chart-bar',
									'title' => 'Class',
								],
								[ 'title' => 'None' ],
								[
									'icon'  => 'heroicons-outline:no-such-icon',
									'title' => 'Missing',
								],
							],
						],
					],
				]
			)
		);

		$icon = static fn( string $markup ): string => '<span class="onboarding__item-icon" aria-hidden="true">' . $markup . '</span>';

		$this->assertSame( 1, substr_count( $html, $icon( Icons::render( 'heroicons-outline:chart-bar' ) ) ) );
		$this->assertStringContainsString( 'class="sugarcommerce-icon"', Icons::render( 'heroicons-outline:chart-bar' ) );
		$this->assertSame( 2, substr_count( $html, $icon( '<span class="dashicons dashicons-chart-bar sugarcommerce-icon" aria-hidden="true"></span>' ) ) );
		$this->assertSame( 2, substr_count( $html, $icon( Icons::render( 'heroicons-outline:check' ) ) ) );
	}

	/**
	 * The wizard's own icons are Heroicons: a tick and a dash in the
	 * progress, the chevron back, the tick on the finish screen and the
	 * mark on a link that leaves the site.
	 */
	public function test_the_wizards_own_icons_are_heroicons(): void {
		$wizard = $this->wizard();

		$wizard->state()->mark_done( 'welcome' );
		$wizard->state()->mark_skipped( 'store' );

		$check = Icons::render( 'heroicons-outline:check' );
		$html  = $this->render( $wizard, [ 'step' => 'done' ] );

		$this->assertStringContainsString( '<span class="onboarding__segment-label">' . $check . 'Welcome</span>', $html );
		$this->assertStringContainsString( 'step=store">' . Icons::render( 'heroicons-outline:chevron-left' ) . 'Back</a>', $html );

		$rail = $this->wizard( [ 'progress' => 'side' ], 'railed' );

		$rail->state()->mark_done( 'welcome' );
		$rail->state()->mark_skipped( 'store' );

		$html = $this->render( $rail, [ 'step' => 'done' ] );

		$this->assertMatchesRegularExpression( '/onboarding__step--done[^>]*>.*?<span class="onboarding__marker"[^>]*>' . preg_quote( $check, '/' ) . '<\/span>/s', $html );
		$this->assertMatchesRegularExpression( '/onboarding__step--skipped[^>]*>.*?<span class="onboarding__marker"[^>]*>' . preg_quote( Icons::render( 'heroicons-outline:minus' ), '/' ) . '<\/span>/s', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );

		$finished = $this->wizard(
			[
				'finish' => [
					'title' => 'All set',
					'links' => [
						[
							'label'    => 'Docs',
							'url'      => 'https://docs.example.test/',
							'external' => true,
						],
					],
				],
			],
			'finished'
		);

		$finished->complete();

		$html = $this->render( $finished, [ 'finished' => 1 ] );

		$this->assertStringContainsString( '<span class="onboarding__finish-mark" aria-hidden="true">' . $check . '</span>', $html );
		$this->assertStringContainsString( 'Docs' . Icons::render( 'heroicons-outline:arrow-top-right-on-square', [ 'class' => 'onboarding__external' ] ) . '<span class="screen-reader-text">(opens in a new tab)</span></a>', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
	}

	/**
	 * A link that leaves the site opens a new tab and says so.
	 */
	public function test_an_external_link_says_that_it_is_external(): void {
		$html = $this->render(
			$this->wizard(
				[
					'steps' => [
						'welcome' => [
							'title' => 'Welcome',
							'links' => [
								[
									'label' => 'Home',
									'url'   => 'https://example.test/',
								],
								[
									'label'    => 'Docs',
									'url'      => 'https://docs.example.test/',
									'external' => true,
								],
							],
						],
					],
				]
			)
		);

		$this->assertStringContainsString( 'class="button button-primary" href="https://example.test/"', $html );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $html );
		$this->assertStringContainsString( '(opens in a new tab)', $html );
	}

	/**
	 * A callback step draws itself, before the step's fields.
	 */
	public function test_a_callback_draws_before_the_fields(): void {
		$steps                    = $this->steps();
		$steps['store']['render'] = static function ( array $step, Wizard $wizard ): void {
			echo '<p class="custom">Drawn by ' . esc_html( $wizard->id() ) . '</p>';
		};

		$wizard = $this->wizard( [ 'steps' => $steps ] );

		$this->submit( $wizard, 'welcome' );

		$html = $this->render( $wizard, [ 'step' => 'store' ] );

		$this->assertStringContainsString( '<p class="custom">Drawn by myplugin</p>', $html );
		$this->assertLessThan( strpos( $html, 'onboarding__fields' ), strpos( $html, 'class="custom"' ) );
	}

	/**
	 * A step type this library does not know is handed to whoever registered it.
	 */
	public function test_an_unknown_step_type_is_handed_on(): void {
		add_action(
			'sugarcommerce_onboarding_render_quiz',
			static function ( array $step ): void {
				echo '<p class="quiz">' . esc_html( $step['title'] ) . '</p>';
			}
		);

		$html = $this->render(
			$this->wizard(
				[
					'steps' => [
						'quiz' => [
							'type'  => 'quiz',
							'title' => 'A quiz',
						],
					],
				]
			)
		);

		$this->assertStringContainsString( '<p class="quiz">A quiz</p>', $html );
	}

	/**
	 * Markup can be added before and after any step's content.
	 */
	public function test_before_and_after_a_step(): void {
		add_action( 'sugarcommerce_onboarding_before_step_myplugin', static fn() => print( '<i>before</i>' ) );
		add_action( 'sugarcommerce_onboarding_after_step_myplugin', static fn() => print( '<i>after</i>' ) );

		$html = $this->render( $this->wizard() );

		$this->assertLessThan( strpos( $html, '<p>Hello.</p>' ), strpos( $html, '<i>before</i>' ) );
		$this->assertGreaterThan( strpos( $html, '<p>Hello.</p>' ), strpos( $html, '<i>after</i>' ) );
	}

	/* ---------------------------------------------------------------------
	 * The frame
	 * ------------------------------------------------------------------ */

	/**
	 * An accent has to be a colour, since it lands in a style attribute.
	 */
	public function test_an_accent_has_to_be_a_colour(): void {
		$this->assertStringContainsString( 'style="--onboarding-accent:#ff6600"', $this->render( $this->wizard( [ 'accent' => '#ff6600' ] ) ) );
		$this->assertStringNotContainsString( '--onboarding-accent:', $this->render( $this->wizard( [ 'accent' => 'red;background:url(x)' ], 'other' ) ) );
	}

	/**
	 * A logo keeps the page's heading, for anyone not looking at the logo.
	 */
	public function test_a_logo_keeps_the_heading(): void {
		$html = $this->render(
			$this->wizard(
				[
					'logo'  => 'https://example.test/logo.svg',
					'title' => 'Set up Shop',
				]
			)
		);

		$this->assertStringContainsString( '<img class="onboarding__logo" src="https://example.test/logo.svg" alt="">', $html );
		$this->assertStringContainsString( '<h1 class="screen-reader-text">Set up Shop</h1>', $html );
	}

	/**
	 * The way out is always there.
	 */
	public function test_the_exit_is_always_there(): void {
		$this->assertStringContainsString(
			'<a class="onboarding__exit" href="https://example.test/x">Exit Setup</a>',
			$this->render( $this->wizard( [ 'exit_url' => 'https://example.test/x' ] ) )
		);
	}

	/**
	 * The layout is on the wrapper and the body, which is what hides the admin chrome.
	 */
	public function test_the_layout_is_marked(): void {
		$wizard = $this->wizard( [ 'body_class' => 'shop-setup' ] );

		$_GET = [ 'page' => 'myplugin' ];

		$this->assertSame(
			'wp-admin onboarding-wizard onboarding-wizard-myplugin onboarding-layout-focus shop-setup',
			Onboarding::body_class( 'wp-admin' )
		);
		$this->assertStringContainsString( 'class="onboarding onboarding--focus"', $this->render( $wizard ) );
	}

	/**
	 * Another page is left alone.
	 */
	public function test_another_page_is_left_alone(): void {
		$this->wizard();

		$_GET = [ 'page' => 'something-else' ];

		$this->assertSame( 'wp-admin', Onboarding::body_class( 'wp-admin' ) );
	}

	/**
	 * Somebody without the capability is refused the page.
	 */
	public function test_the_page_is_refused_without_the_capability(): void {
		$this->expectException( OnboardingDied::class );

		$this->render( $this->wizard( [ 'capability' => 'install_plugins' ] ) );
	}

	/* ---------------------------------------------------------------------
	 * Notices and assets
	 * ------------------------------------------------------------------ */

	/**
	 * Other plugins' notices are cleared off the wizard, unless it wants them.
	 */
	public function test_notices_are_cleared_from_the_wizard_only(): void {
		$this->wizard();

		add_action( 'admin_notices', static fn() => null );

		$_GET = [ 'page' => 'elsewhere' ];
		Onboarding::quieten();
		$this->assertTrue( has_action( 'admin_notices' ), 'Notices survive on every other page.' );

		$_GET = [ 'page' => 'myplugin' ];
		Onboarding::quieten();
		$this->assertFalse( has_action( 'admin_notices' ) );
	}

	/**
	 * A wizard that keeps notices keeps them.
	 */
	public function test_notices_can_be_kept(): void {
		$this->wizard( [ 'notices' => true ] );

		add_action( 'admin_notices', static fn() => null );

		$_GET = [ 'page' => 'myplugin' ];
		Onboarding::quieten();

		$this->assertTrue( has_action( 'admin_notices' ) );
	}

	/**
	 * The stylesheet and script load on the wizard's page and nowhere else.
	 */
	public function test_assets_load_on_the_wizards_page_only(): void {
		$this->wizard();

		$_GET = [ 'page' => 'elsewhere' ];
		Onboarding::enqueue();
		$this->assertSame( [], $GLOBALS['ob_styles'] );
		$this->assertSame( [], $GLOBALS['ob_scripts'] );

		$_GET = [ 'page' => 'myplugin' ];
		Onboarding::enqueue();
		$this->assertArrayHasKey( Runtime::handle(), $GLOBALS['ob_styles'] );
		$this->assertArrayHasKey( Runtime::handle(), $GLOBALS['ob_scripts'] );
	}

	/**
	 * A step with no fields does not load the field kit.
	 */
	public function test_a_step_without_fields_does_not_load_the_kit(): void {
		$this->wizard();

		$_GET = [ 'page' => 'myplugin' ];
		Onboarding::enqueue();

		$this->assertSame( [ Runtime::handle() ], array_keys( $GLOBALS['ob_styles'] ) );
	}

	/* ---------------------------------------------------------------------
	 * The finish screen
	 * ------------------------------------------------------------------ */

	/**
	 * The finish screen draws its checklist and somewhere to go.
	 */
	public function test_the_finish_screen(): void {
		$this->checklist(
			[
				'tasks' => [
					'one' => [
						'title' => 'One thing',
						'done'  => static fn(): bool => false,
					],
				],
			]
		);

		$wizard = $this->wizard(
			[
				'finish' => [
					'title'     => 'All set',
					'checklist' => 'myguide',
				],
			]
		);

		$wizard->complete();

		$html = $this->render( $wizard, [ 'finished' => 1 ] );

		$this->assertStringContainsString( 'onboarding__finish" data-celebrate="1"', $html );
		$this->assertStringContainsString( '>All set</h2>', $html );
		$this->assertStringContainsString( 'Setup complete', $html );
		$this->assertStringContainsString( 'onboarding-checklist', $html );
		$this->assertStringContainsString( '>Done</a>', $html, 'A finish screen with no links of its own still has a way on.' );
		$this->assertStringNotContainsString( '<form', $html );
	}

	/**
	 * Confetti is the wizard's to turn off.
	 */
	public function test_confetti_can_be_turned_off(): void {
		$wizard = $this->wizard( [ 'finish' => [ 'confetti' => false ] ] );

		$wizard->complete();

		$this->assertStringContainsString( 'data-celebrate="0"', $this->render( $wizard, [ 'finished' => 1 ] ) );
	}
}
