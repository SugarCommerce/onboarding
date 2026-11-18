<?php
/**
 * Checklist tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Onboarding\Checklist;
use SugarCommerce\Onboarding\Onboarding;
use SugarCommerce\Onboarding\Utils\Runtime;

/**
 * Whether a task is done, how many are, and who sees the card.
 *
 * Done is worked out, not remembered, so most of what can go wrong is in
 * what counts as a yes — a callback that returns a count, a list where every
 * task is optional, a list with nothing on it. Each case has its own test,
 * which is what makes the suite worth running against mutations of
 * Checklist::is_done() and Checklist::status().
 */
final class ChecklistTest extends OnboardingTestCase {

	/**
	 * A checklist of the given tasks.
	 *
	 * @param array<string, array<string, mixed>> $tasks  The tasks.
	 * @param array<string, mixed>                $config Overrides.
	 *
	 * @return Checklist
	 */
	private function tasks( array $tasks, array $config = [] ): Checklist {
		return $this->checklist( array_merge( [ 'tasks' => $tasks ], $config ) );
	}

	/**
	 * A task whose check answers the given value.
	 *
	 * @param mixed                $answer   What the check returns.
	 * @param array<string, mixed> $override Overrides.
	 *
	 * @return array<string, mixed>
	 */
	private static function task( mixed $answer, array $override = [] ): array {
		return array_merge(
			[
				'title' => 'A task',
				'done'  => static fn() => $answer,
			],
			$override
		);
	}

	/* ---------------------------------------------------------------------
	 * Done
	 * ------------------------------------------------------------------ */

	/**
	 * Only exactly true is done.
	 *
	 * @param mixed $answer   What the check returns.
	 * @param bool  $expected Whether that is done.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'answerProvider' )]
	public function test_only_true_is_done( mixed $answer, bool $expected ): void {
		$checklist = $this->tasks( [ 'one' => self::task( $answer ) ] );

		$this->assertSame( $expected, $checklist->is_done( 'one' ) );
	}

	/**
	 * Answers, and whether each is done.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function answerProvider(): array {
		return [
			'true'     => [ true, true ],
			'false'    => [ false, false ],
			'a count'  => [ 3, false ],
			'one'      => [ 1, false ],
			'a string' => [ 'yes', false ],
			'null'     => [ null, false ],
		];
	}

	/**
	 * The check is handed the checklist.
	 */
	public function test_the_check_is_handed_the_checklist(): void {
		$seen      = null;
		$checklist = $this->tasks(
			[
				'one' => [
					'title' => 'One',
					'done'  => static function ( Checklist $checklist ) use ( &$seen ): bool {
						$seen = $checklist->id();

						return true;
					},
				],
			]
		);

		$checklist->is_done( 'one' );

		$this->assertSame( 'myguide', $seen );
	}

	/**
	 * A task that does not exist is not done.
	 */
	public function test_an_unknown_task_is_not_done(): void {
		$this->assertFalse( $this->tasks( [ 'one' => self::task( true ) ] )->is_done( 'nothing' ) );
	}

	/**
	 * Each check runs once a request, until asked to run again.
	 */
	public function test_checks_are_remembered_until_refreshed(): void {
		$runs      = 0;
		$checklist = $this->tasks(
			[
				'one' => [
					'title' => 'One',
					'done'  => static function () use ( &$runs ): bool {
						++$runs;

						return true;
					},
				],
			]
		);

		$checklist->is_done( 'one' );
		$checklist->status();
		$checklist->html();

		$this->assertSame( 1, $runs );

		$checklist->refresh();
		$checklist->is_done( 'one' );

		$this->assertSame( 2, $runs );
	}

	/**
	 * Whether a task is done can be overruled by a filter.
	 */
	public function test_done_can_be_filtered(): void {
		add_filter(
			'sugarcommerce_onboarding_checklist_task_done_myguide',
			static fn( bool $done, string $key ): bool => 'two' === $key ? true : $done,
			10,
			2
		);

		$checklist = $this->tasks(
			[
				'one' => self::task( false ),
				'two' => self::task( false ),
			]
		);

		$this->assertFalse( $checklist->is_done( 'one' ) );
		$this->assertTrue( $checklist->is_done( 'two' ) );
	}

	/* ---------------------------------------------------------------------
	 * Ticked by hand
	 * ------------------------------------------------------------------ */

	/**
	 * A task with no check is ticked by hand, and stays ticked.
	 */
	public function test_a_task_with_no_check_is_ticked_by_hand(): void {
		$checklist = $this->tasks( [ 'read' => [ 'title' => 'Read the guide' ] ] );

		$this->assertTrue( $checklist->is_manual( 'read' ) );
		$this->assertFalse( $checklist->is_done( 'read' ) );

		$this->assertTrue( $checklist->mark( 'read', true ) );
		$this->assertTrue( $checklist->is_done( 'read' ) );
		$this->assertSame( [ 'done' => [ 'read' ] ], $GLOBALS['ob_options']['myguide_checklist'] );
		$this->assertTrue( $this->fired( 'sugarcommerce_onboarding_checklist_task_marked_myguide' ) );

		$this->assertTrue( $checklist->mark( 'read', false ) );
		$this->assertFalse( $checklist->is_done( 'read' ) );
	}

	/**
	 * Ticking twice does not list a task twice.
	 */
	public function test_ticking_twice_is_once(): void {
		$checklist = $this->tasks( [ 'read' => [ 'title' => 'Read' ] ] );

		$checklist->mark( 'read', true );
		$checklist->mark( 'read', true );

		$this->assertSame( [ 'read' ], $GLOBALS['ob_options']['myguide_checklist']['done'] );
	}

	/**
	 * A task that is worked out cannot be ticked by hand.
	 */
	public function test_a_worked_out_task_cannot_be_overruled(): void {
		$checklist = $this->tasks( [ 'product' => self::task( false ) ] );

		$this->assertFalse( $checklist->is_manual( 'product' ) );
		$this->assertFalse( $checklist->mark( 'product', true ) );
		$this->assertFalse( $checklist->is_done( 'product' ) );
		$this->assertFalse( $checklist->mark( 'nothing', true ) );
	}

	/* ---------------------------------------------------------------------
	 * Status
	 * ------------------------------------------------------------------ */

	/**
	 * How many of how many, which is next, and each task.
	 */
	public function test_the_status(): void {
		$checklist = $this->tasks(
			[
				'one'   => self::task( true ),
				'two'   => self::task( false, [ 'url' => 'https://example.test/two' ] ),
				'three' => self::task( false, [ 'optional' => true ] ),
				'four'  => [ 'title' => 'By hand' ],
			]
		);

		$status = ( Onboarding::checklist( 'myguide' )?->status() ?? [] );

		$this->assertSame( 4, $status['total'] );
		$this->assertSame( 1, $status['done'] );
		$this->assertSame( 3, $status['remaining'] );
		$this->assertSame( 25, $status['percent'] );
		$this->assertFalse( $status['complete'] );
		$this->assertSame( 'two', $status['next'] );
		$this->assertSame(
			[
				'title'    => 'A task',
				'done'     => false,
				'optional' => false,
				'manual'   => false,
				'url'      => 'https://example.test/two',
			],
			$status['tasks']['two']
		);
		$this->assertTrue( $status['tasks']['three']['optional'] );
		$this->assertTrue( $status['tasks']['four']['manual'] );
		$this->assertSame( [], ( Onboarding::checklist( 'nothing' )?->status() ?? [] ) );
	}

	/**
	 * Next is the first required task not done, passing over an optional one
	 * that comes earlier; the optional ones only once nothing required is
	 * left; and nothing once everything is done.
	 */
	public function test_which_task_is_next(): void {
		$answers = [
			'one'   => true,
			'theme' => false,
			'two'   => false,
			'three' => false,
		];

		$check = static function ( string $key ) use ( &$answers ): \Closure {
			return static function () use ( &$answers, $key ): bool {
				return $answers[ $key ];
			};
		};

		$checklist = $this->tasks(
			[
				'one'   => [ 'done' => $check( 'one' ) ],
				'theme' => [
					'optional' => true,
					'done'     => $check( 'theme' ),
				],
				'two'   => [ 'done' => $check( 'two' ) ],
				'three' => [ 'done' => $check( 'three' ) ],
			]
		);

		$this->assertSame( 'two', $checklist->status()['next'], 'An optional task does not jump the queue.' );

		$answers['two'] = true;
		$checklist->refresh();

		$this->assertSame( 'three', $checklist->status()['next'] );

		$answers['three'] = true;
		$checklist->refresh();

		$this->assertSame( 'theme', $checklist->status()['next'], 'With nothing required left, the optional one is next.' );

		$answers['theme'] = true;
		$checklist->refresh();

		$this->assertSame( '', $checklist->status()['next'] );
	}

	/**
	 * A list of nothing but optional tasks still has a next one.
	 */
	public function test_an_all_optional_list_has_a_next_task(): void {
		$checklist = $this->tasks(
			[
				'a' => self::task( true, [ 'optional' => true ] ),
				'b' => self::task( false, [ 'optional' => true ] ),
			]
		);

		$this->assertSame( 'b', $checklist->status()['next'] );
	}

	/**
	 * Complete when every required task is done, whatever the optional ones say.
	 */
	public function test_complete_ignores_optional_tasks(): void {
		$checklist = $this->tasks(
			[
				'one' => self::task( true ),
				'two' => self::task( false, [ 'optional' => true ] ),
			]
		);

		$this->assertTrue( $checklist->is_complete() );
		$this->assertTrue( (bool) Onboarding::checklist( 'myguide' )?->is_complete() );
	}

	/**
	 * Not complete while one required task is not.
	 */
	public function test_one_required_task_left_is_not_complete(): void {
		$checklist = $this->tasks(
			[
				'one' => self::task( true ),
				'two' => self::task( false ),
				'opt' => self::task( true, [ 'optional' => true ] ),
			]
		);

		$this->assertFalse( $checklist->is_complete() );
	}

	/**
	 * A list where everything is optional is complete when everything is done.
	 */
	public function test_an_all_optional_list_needs_everything(): void {
		$this->assertFalse(
			$this->tasks(
				[
					'one' => self::task( true, [ 'optional' => true ] ),
					'two' => self::task( false, [ 'optional' => true ] ),
				]
			)->is_complete()
		);

		$this->assertTrue(
			$this->tasks(
				[
					'one' => self::task( true, [ 'optional' => true ] ),
					'two' => self::task( true, [ 'optional' => true ] ),
				],
				[],
			)->is_complete()
		);
	}

	/**
	 * An empty list is never complete.
	 */
	public function test_an_empty_list_is_not_complete(): void {
		$status = $this->tasks( [] )->status();

		$this->assertFalse( $status['complete'] );
		$this->assertSame( 0, $status['percent'] );
		$this->assertSame( '', $status['next'] );
		$this->assertFalse( (bool) Onboarding::checklist( 'nothing' )?->is_complete() );
	}

	/**
	 * The status can be filtered.
	 */
	public function test_the_status_can_be_filtered(): void {
		add_filter( 'sugarcommerce_onboarding_checklist_status_myguide', static fn( array $status ): array => [ 'complete' => true ] + $status );

		$this->assertTrue( $this->tasks( [ 'one' => self::task( false ) ] )->is_complete() );
	}

	/* ---------------------------------------------------------------------
	 * Tasks
	 * ------------------------------------------------------------------ */

	/**
	 * A task whose callable says no is not on the list.
	 */
	public function test_a_task_that_does_not_apply_is_absent(): void {
		$checklist = $this->tasks(
			[
				'one' => self::task( true ),
				'two' => self::task( false, [ 'show_if' => static fn(): bool => false ] ),
			]
		);

		$this->assertSame( [ 'one' ], array_keys( $checklist->tasks() ) );
		$this->assertTrue( $checklist->is_complete() );
	}

	/**
	 * An add-on adds its own task through the filter, even registered after.
	 */
	public function test_tasks_can_be_added_through_the_filter(): void {
		$checklist = $this->tasks( [ 'one' => self::task( true ) ] );

		add_filter(
			'sugarcommerce_onboarding_checklist_tasks_myguide',
			static fn( array $tasks ): array => $tasks + [ 'addon' => self::task( false ) ]
		);

		$this->assertSame( [ 'one', 'addon' ], array_keys( $checklist->tasks() ) );
		$this->assertFalse( $checklist->is_complete() );
	}

	/**
	 * A malformed task is dropped.
	 */
	public function test_a_malformed_task_is_dropped(): void {
		$checklist = $this->tasks(
			[
				'ok'  => self::task( true ),
				'bad' => 'not a task',
				'!!!' => self::task( true ),
			]
		);

		$this->assertSame( [ 'ok' ], array_keys( $checklist->tasks() ) );
	}

	/* ---------------------------------------------------------------------
	 * Who sees it
	 * ------------------------------------------------------------------ */

	/**
	 * Dismissing is per person.
	 */
	public function test_dismissing_is_per_person(): void {
		$checklist = $this->tasks( [ 'one' => self::task( false ) ] );

		$this->assertTrue( $checklist->dismiss() );
		$this->assertTrue( $checklist->is_dismissed() );
		$this->assertFalse( $checklist->is_visible() );

		$GLOBALS['ob_user_id'] = 2;

		$this->assertFalse( $checklist->is_dismissed() );
		$this->assertTrue( $checklist->is_visible() );

		$GLOBALS['ob_user_id'] = 1;

		$this->assertTrue( $checklist->dismiss( false ) );
		$this->assertFalse( $checklist->is_dismissed() );
		$this->assertTrue( $this->fired( 'sugarcommerce_onboarding_checklist_dismissed_myguide' ) );
	}

	/**
	 * Nobody signed in has nothing to dismiss.
	 */
	public function test_nobody_signed_in_cannot_dismiss(): void {
		$checklist = $this->tasks( [ 'one' => self::task( false ) ] );

		$GLOBALS['ob_user_id'] = 0;

		$this->assertFalse( $checklist->dismiss() );
		$this->assertFalse( $checklist->is_dismissed() );
	}

	/**
	 * A list that cannot be dismissed is not.
	 */
	public function test_a_list_that_cannot_be_dismissed(): void {
		$checklist = $this->tasks( [ 'one' => self::task( false ) ], [ 'dismissible' => false ] );

		$this->assertFalse( $checklist->dismiss() );
		$this->assertStringNotContainsString( 'data-checklist-dismiss', $checklist->html() );
	}

	/**
	 * Hidden from somebody without the capability, and when there is nothing on it.
	 */
	public function test_who_sees_it(): void {
		$this->assertFalse( $this->tasks( [ 'one' => self::task( false ) ], [ 'capability' => 'install_plugins' ] )->is_visible() );
		$this->assertFalse( $this->checklist( [ 'tasks' => [] ], 'empty' )->is_visible() );
	}

	/**
	 * A list that asks to go once done goes once done.
	 */
	public function test_hide_when_complete(): void {
		$done = false;

		$checklist = $this->tasks(
			[
				'one' => [
					'title' => 'One',
					'done'  => static function () use ( &$done ): bool {
						return $done;
					},
				],
			],
			[ 'hide_when_complete' => true ]
		);

		$this->assertTrue( $checklist->is_visible() );

		$done = true;
		$checklist->refresh();

		$this->assertFalse( $checklist->is_visible() );
		$this->assertSame( '', $checklist->html() );
	}

	/* ---------------------------------------------------------------------
	 * The card
	 * ------------------------------------------------------------------ */

	/**
	 * The card: its numbers in words and as a progress bar, the first task
	 * not done open, and a tick button only on a task ticked by hand.
	 */
	public function test_the_card(): void {
		$this->tasks(
			[
				'one'  => self::task( true ),
				'two'  => self::task(
					false,
					[
						'title'       => 'Add a product',
						'description' => 'Your first one.',
						'url'         => 'https://example.test/new',
						'button'      => 'Add Product',
					]
				),
				'read' => [ 'title' => 'Read' ],
			]
		);

		ob_start();
		Onboarding::checklist( 'myguide' )?->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '1 of 3 tasks complete', $html );
		$this->assertStringContainsString( 'role="progressbar" aria-label="1 of 3 tasks complete" aria-valuemin="0" aria-valuemax="3" aria-valuenow="1"', $html );
		$this->assertSame( 1, substr_count( $html, ' open>' ) );
		$this->assertMatchesRegularExpression( '/data-task="two"><details name="onboarding-checklist-myguide" open>/', $html );
		$this->assertStringContainsString( '<a class="button button-primary" href="https://example.test/new">Add Product</a>', $html );
		$this->assertSame( 1, substr_count( $html, 'data-checklist-mark=' ) );
		$this->assertStringContainsString( 'data-checklist-mark="read" data-done="1"', $html );
		$this->assertStringContainsString( '(done)', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( 'data-endpoint="https://example.test/wp-json/sugarcommerce-onboarding/v1/checklists/myguide"', $html );
	}

	/**
	 * The checklist's icons are Heroicons: the tick on a task done, the
	 * chevron on Show All, the cross that dismisses it, and the mark on a
	 * button that leaves the site.
	 */
	public function test_the_checklists_icons_are_heroicons(): void {
		$tasks = [
			'one'  => self::task( true, [ 'title' => 'Say hello' ] ),
			'away' => self::task(
				false,
				[
					'title'    => 'Read the guide',
					'url'      => 'https://docs.example.test/',
					'button'   => 'Open the Guide',
					'external' => true,
				]
			),
		];

		$html = $this->tasks( $tasks )->html( [ 'layout' => 'compact' ] );

		$this->assertStringContainsString( '<span class="onboarding-checklist__marker" aria-hidden="true">' . Icons::render( 'heroicons-outline:check' ) . '</span>', $html );
		$this->assertSame( 1, substr_count( $html, Icons::render( 'heroicons-outline:check' ) ), 'Only the task done has a tick.' );
		$this->assertMatchesRegularExpression( '/<span class="onboarding-checklist__toggle-label">Show All<\/span>' . preg_quote( Icons::render( 'heroicons-outline:chevron-down' ), '/' ) . '<\/button>/', $html );
		$this->assertMatchesRegularExpression( '/data-checklist-dismiss aria-label="[^"]+">' . preg_quote( Icons::render( 'heroicons-outline:x-mark' ), '/' ) . '<\/button>/', $html );
		$this->assertStringContainsString( 'Open the Guide' . Icons::render( 'heroicons-outline:arrow-top-right-on-square', [ 'class' => 'onboarding-checklist__external' ] ) . '<span class="screen-reader-text">(opens in a new tab)</span></a>', $html );
		$this->assertStringContainsString( 'class="sugarcommerce-icon onboarding-checklist__external"', $html );
		$this->assertStringNotContainsString( 'dashicons', $html );
	}

	/**
	 * Full is the default, and has no Show All.
	 */
	public function test_full_is_the_default_layout(): void {
		$html = $this->tasks( [ 'one' => self::task( false ) ] )->html();

		$this->assertStringContainsString( 'onboarding-checklist--full', $html );
		$this->assertStringContainsString( 'data-layout="full"', $html );
		$this->assertStringNotContainsString( 'data-checklist-toggle', $html );
		$this->assertStringNotContainsString( 'onboarding-checklist__next', $html );
		$this->assertSame( $html, $this->tasks( [ 'one' => self::task( false ) ] )->html( [ 'layout' => 'sideways' ] ), 'A layout it does not know is the full one.' );
	}

	/**
	 * The compact card: one row with the count in words and as a bar, the
	 * next task's title and button, Continue Setup, a Show All button that
	 * owns the hidden list, and the dismiss button; the list under it with
	 * nothing open, since the row already shows the next task.
	 */
	public function test_the_compact_card(): void {
		$this->wizard();

		$html = $this->tasks(
			[
				'one'   => self::task( true, [ 'title' => 'Say hello' ] ),
				'theme' => self::task(
					false,
					[
						'title'    => 'Pick a theme',
						'optional' => true,
					]
				),
				'two'   => self::task(
					false,
					[
						'title'       => 'Add a product',
						'description' => 'Your first one.',
						'url'         => 'https://example.test/new',
						'button'      => 'Add Product',
					]
				),
			],
			[
				'wizard'      => 'myplugin',
				'description' => 'Each step ticks itself off.',
			]
		)->html( [ 'layout' => 'compact' ] );

		$this->assertStringContainsString( 'onboarding-checklist--compact', $html );
		$this->assertStringContainsString( 'data-layout="compact"', $html );
		$this->assertStringContainsString( '<span class="onboarding-checklist__count">1 of 3 done</span>', $html );
		$this->assertStringContainsString( 'role="progressbar" aria-label="1 of 3 done" aria-valuemin="0" aria-valuemax="3" aria-valuenow="1"', $html );
		$this->assertStringContainsString(
			'<div class="onboarding-checklist__next" data-task="two"><p class="onboarding-checklist__next-text"><span class="onboarding-checklist__next-label">Next:</span> <span class="onboarding-checklist__next-title">Add a product</span></p>' .
			'<div class="onboarding-checklist__actions"><a class="button button-primary" href="https://example.test/new">Add Product</a></div></div>',
			$html
		);
		$this->assertStringContainsString( 'onboarding-checklist__wizard', $html );
		$this->assertStringContainsString(
			'<button type="button" class="button-link onboarding-checklist__toggle" data-checklist-toggle aria-expanded="false" aria-controls="onboarding-checklist-myguide-tasks" data-show="Show All" data-hide="Show Less"><span class="onboarding-checklist__toggle-label">Show All</span>',
			$html
		);
		$this->assertStringContainsString( '<div class="onboarding-checklist__panel" id="onboarding-checklist-myguide-tasks" hidden>', $html );
		$this->assertStringContainsString( 'Each step ticks itself off.', $html );
		$this->assertSame( 0, substr_count( $html, ' open>' ) );
		$this->assertSame( 3, substr_count( $html, 'class="onboarding-checklist__task ' ) );

		// The reading order is the focus order: next task, Continue Setup,
		// Show All, dismiss, then the list.
		$positions = array_map(
			static fn( string $needle ): int => (int) strpos( $html, $needle ),
			[ 'Add Product</a>', 'onboarding-checklist__wizard', 'data-checklist-toggle', 'data-checklist-dismiss', 'onboarding-checklist__panel' ]
		);
		$sorted    = $positions;
		sort( $sorted );

		$this->assertSame( $sorted, $positions );
		$this->assertNotContains( 0, $positions );
	}

	/**
	 * Kept open, the compact card is drawn open: the list shown, the button
	 * saying so, and its words the other way round.
	 */
	public function test_the_compact_card_remembers_its_list_open(): void {
		$checklist = $this->tasks( [ 'one' => self::task( false ) ] );

		$checklist->expand();

		$html = $checklist->html( [ 'layout' => 'compact' ] );

		$this->assertStringContainsString( ' is-expanded"', $html );
		$this->assertStringContainsString( 'aria-expanded="true"', $html );
		$this->assertStringContainsString( '<span class="onboarding-checklist__toggle-label">Show Less</span>', $html );
		$this->assertStringContainsString( '<div class="onboarding-checklist__panel" id="onboarding-checklist-myguide-tasks">', $html );

		$this->assertStringNotContainsString( 'is-expanded', $checklist->html(), 'The full card has no list to keep open.' );
	}

	/**
	 * Keeping the list open is per person, and closing it forgets it.
	 */
	public function test_keeping_the_list_open_is_per_person(): void {
		$checklist = $this->tasks( [ 'one' => self::task( false ) ] );

		$this->assertFalse( $checklist->is_expanded() );
		$this->assertTrue( $checklist->expand() );
		$this->assertTrue( $checklist->is_expanded() );
		$this->assertFalse( $checklist->is_expanded( 2 ), 'Somebody else still sees it closed.' );
		$this->assertSame( 'myguide_checklist_expanded', $checklist->expanded_key() );

		$this->assertTrue( $checklist->expand( false ) );
		$this->assertFalse( $checklist->is_expanded() );
		$this->assertArrayNotHasKey( 'myguide_checklist_expanded', $GLOBALS['ob_user_meta'][1] ?? [] );

		$GLOBALS['ob_user_id'] = 0;

		$this->assertFalse( $checklist->expand() );
		$this->assertFalse( $checklist->is_expanded() );
	}

	/**
	 * A task ticked by hand is ticked from the compact row too.
	 */
	public function test_the_compact_row_ticks_a_task_by_hand(): void {
		$html = $this->tasks( [ 'read' => [ 'title' => 'Read the guide' ] ] )->html( [ 'layout' => 'compact' ] );

		$this->assertMatchesRegularExpression( '/<div class="onboarding-checklist__next" data-task="read">.*data-checklist-mark="read" data-done="1"/', $html );
	}

	/**
	 * Finished, the compact row says so where the next task was.
	 */
	public function test_a_complete_compact_card_says_so(): void {
		$html = $this->tasks(
			[ 'one' => self::task( true ) ],
			[
				'complete_title'   => 'All done here',
				'complete_message' => 'Nothing left to do.',
			]
		)->html( [ 'layout' => 'compact' ] );

		$this->assertStringContainsString( 'All done here', $html );
		$this->assertStringContainsString( '<div class="onboarding-checklist__next"><span class="onboarding-checklist__next-title">Nothing left to do.</span></div>', $html );
		$this->assertStringNotContainsString( 'Next:', $html );
	}

	/**
	 * A complete list says so instead of its title.
	 */
	public function test_a_complete_card_says_so(): void {
		$html = $this->tasks( [ 'one' => self::task( true ) ], [ 'complete_title' => 'All done here' ] )->html();

		$this->assertStringContainsString( 'is-complete', $html );
		$this->assertStringContainsString( 'All done here', $html );
	}

	/**
	 * In a dashboard widget the widget's own title is the visible one.
	 */
	public function test_in_a_widget_the_title_is_for_screen_readers(): void {
		$html = $this->tasks( [ 'one' => self::task( false ) ] )->html( [ 'context' => 'widget' ] );

		$this->assertStringContainsString( 'onboarding-checklist--widget', $html );
		$this->assertStringContainsString( 'class="onboarding-checklist__title screen-reader-text"', $html );
	}

	/**
	 * A way back into an unfinished wizard.
	 */
	public function test_the_card_links_to_an_unfinished_wizard(): void {
		$wizard    = $this->wizard();
		$checklist = $this->tasks( [ 'one' => self::task( false ) ], [ 'wizard' => 'myplugin' ] );

		$this->assertStringContainsString( 'onboarding-checklist__wizard', $checklist->html() );

		$wizard->complete();

		$this->assertStringNotContainsString( 'onboarding-checklist__wizard', $checklist->html() );
	}

	/**
	 * Drawing the card loads its own assets, which are not the wizard's.
	 */
	public function test_the_card_loads_its_own_assets(): void {
		$this->tasks( [ 'one' => self::task( false ) ] );

		Onboarding::checklist( 'myguide' )?->enqueue();

		$this->assertSame( [ Runtime::handle( 'checklist' ) ], array_keys( $GLOBALS['ob_styles'] ) );
		$this->assertSame( [ Runtime::handle( 'checklist' ) ], array_keys( $GLOBALS['ob_scripts'] ) );
	}

	/* ---------------------------------------------------------------------
	 * The dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Only a list that asks for a widget gets one, and only while it is visible.
	 */
	public function test_the_dashboard_widget(): void {
		$this->checklist( [ 'tasks' => [ 'one' => self::task( false ) ] ], 'quiet' );
		$loud = $this->checklist(
			[
				'tasks'            => [ 'one' => self::task( false ) ],
				'dashboard_widget' => 'Shop setup',
			],
			'loud'
		);

		Onboarding::register_widgets();

		$this->assertSame( [ 'loud_checklist' ], array_keys( $GLOBALS['ob_widgets'] ) );
		$this->assertSame( 'Shop setup', $GLOBALS['ob_widgets']['loud_checklist']['title'] );

		ob_start();
		( $GLOBALS['ob_widgets']['loud_checklist']['callback'] )();
		$this->assertStringContainsString( 'onboarding-checklist--widget', (string) ob_get_clean() );

		$GLOBALS['ob_widgets'] = [];
		$loud->dismiss();

		Onboarding::register_widgets();

		$this->assertSame( [], $GLOBALS['ob_widgets'] );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * A checklist needs an identifier, and can be forgotten.
	 */
	public function test_registration(): void {
		$this->assertNull( Onboarding::register_checklist( '', [] ) );

		$this->tasks( [] );

		$this->assertInstanceOf( Checklist::class, Onboarding::checklist( 'myguide' ) );
		$this->assertTrue( Onboarding::unregister_checklist( 'myguide' ) );
		$this->assertFalse( Onboarding::unregister_checklist( 'myguide' ) );
		$this->assertNull( Onboarding::checklist( 'myguide' ) );

		ob_start();
		Onboarding::checklist( 'myguide' )?->render();
		$this->assertSame( '', ob_get_clean() );
	}
}
