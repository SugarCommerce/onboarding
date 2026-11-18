<?php
/**
 * State
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Support;

/**
 * Where a wizard has got to.
 *
 * One option per wizard, holding which steps were answered, which were
 * skipped, where to pick up, and whether the whole thing was finished. Site
 * wide rather than per user: setting up a plugin is something the site has
 * done, and a second administrator should land where the first one left off
 * rather than at the welcome screen.
 *
 * The answers themselves are not here. They go wherever the wizard says —
 * usually the option the plugin's settings screen already reads — so resetting
 * the progress runs the wizard again with every answer filled in.
 */
final class State {

	/**
	 * The option holding it.
	 *
	 * @var string
	 */
	private string $option;

	/**
	 * Construct.
	 *
	 * @param string $option The option name.
	 */
	public function __construct( string $option ) {
		$this->option = $option;
	}

	/**
	 * The option name.
	 *
	 * @return string
	 */
	public function option(): string {
		return $this->option;
	}

	/**
	 * Everything stored, with every key present.
	 *
	 * @return array{started: int, updated: int, current: string, done: string[], skipped: string[], completed: int}
	 */
	public function get(): array {
		$stored = get_option( $this->option, [] );
		$stored = is_array( $stored ) ? $stored : [];

		return [
			'started'   => (int) ( $stored['started'] ?? 0 ),
			'updated'   => (int) ( $stored['updated'] ?? 0 ),
			'current'   => (string) ( $stored['current'] ?? '' ),
			'done'      => array_values( array_map( 'strval', (array) ( $stored['done'] ?? [] ) ) ),
			'skipped'   => array_values( array_map( 'strval', (array) ( $stored['skipped'] ?? [] ) ) ),
			'completed' => (int) ( $stored['completed'] ?? 0 ),
		];
	}

	/**
	 * Whether a step was answered.
	 *
	 * @param string $key The step.
	 *
	 * @return bool
	 */
	public function is_done( string $key ): bool {
		return in_array( $key, $this->get()['done'], true );
	}

	/**
	 * Whether a step was skipped and not answered since.
	 *
	 * @param string $key The step.
	 *
	 * @return bool
	 */
	public function is_skipped( string $key ): bool {
		return in_array( $key, $this->get()['skipped'], true );
	}

	/**
	 * Record a step as answered.
	 *
	 * An answer replaces a skip: a step skipped once and answered on a second
	 * pass is done, not both.
	 *
	 * @param string $key The step.
	 *
	 * @return void
	 */
	public function mark_done( string $key ): void {
		$state = $this->get();

		$state['done'][]  = $key;
		$state['done']    = array_values( array_unique( $state['done'] ) );
		$state['skipped'] = array_values( array_diff( $state['skipped'], [ $key ] ) );

		$this->put( $state );
	}

	/**
	 * Record a step as skipped.
	 *
	 * A skip does not undo an answer: a step answered once and skipped on a
	 * second pass keeps its answers, so it stays done.
	 *
	 * @param string $key The step.
	 *
	 * @return void
	 */
	public function mark_skipped( string $key ): void {
		$state = $this->get();

		if ( ! in_array( $key, $state['done'], true ) ) {
			$state['skipped'][] = $key;
			$state['skipped']   = array_values( array_unique( $state['skipped'] ) );
		}

		$this->put( $state );
	}

	/**
	 * Remember where to pick up.
	 *
	 * @param string $key The step.
	 *
	 * @return void
	 */
	public function set_current( string $key ): void {
		$state = $this->get();

		$state['current'] = $key;

		$this->put( $state );
	}

	/**
	 * Record the wizard as finished.
	 *
	 * @return void
	 */
	public function complete(): void {
		$state = $this->get();

		$state['completed'] = time();
		$state['current']   = '';

		$this->put( $state );
	}

	/**
	 * Forget all of it.
	 *
	 * @return bool
	 */
	public function reset(): bool {
		return delete_option( $this->option );
	}

	/**
	 * Store the state, stamping it.
	 *
	 * @param array<string, mixed> $state The state.
	 *
	 * @return void
	 */
	private function put( array $state ): void {
		$now = time();

		if ( 0 === (int) $state['started'] ) {
			$state['started'] = $now;
		}

		$state['updated'] = $now;

		// Not autoloaded: it is read on the wizard's own screen and by
		// whatever asks whether setup is finished, not on every request.
		update_option( $this->option, $state, false );
	}
}
