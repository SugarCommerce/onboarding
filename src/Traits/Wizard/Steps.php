<?php
/**
 * Wizard: Steps
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Wizard;

use SugarCommerce\Fields\Conditions;
use SugarCommerce\Onboarding\Wizard;

/**
 * The steps that apply, their order and statuses, and which one is current.
 *
 * Part of Wizard: only the class uses it.
 */
trait Steps {

	/**
	 * Every step, whether or not it applies right now.
	 *
	 * Filtered once, the first time anything asks, rather than when the
	 * wizard is registered: an add-on that adds a step of its own may well
	 * load after the plugin that owns the wizard.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all_steps(): array {
		if ( null !== $this->all_steps ) {
			return $this->all_steps;
		}

		$steps = (array) $this->config['steps'];

		/**
		 * Filter a wizard's steps, to add, remove or reorder them.
		 *
		 * @param array  $steps  The steps, keyed.
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		$steps = (array) apply_filters( "sugarcommerce_onboarding_steps_{$this->id}", $steps, $this );

		$this->all_steps = $this->with_settings( self::normalize_steps( $steps ) );

		return $this->all_steps;
	}

	/**
	 * The steps that apply, in order.
	 *
	 * A step whose `show_if` says no is not hidden, it is absent: it is not
	 * counted in the progress, not reachable by its own URL, and a submission
	 * naming it is refused.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function steps(): array {
		$steps = [];

		foreach ( $this->all_steps() as $key => $step ) {
			if ( $this->applies( $key, $step ) ) {
				$steps[ $key ] = $step;
			}
		}

		return $steps;
	}

	/**
	 * Whether a step applies.
	 *
	 * `show_if` is a callable handed the wizard, or a field-kit condition
	 * over answers already given: `[ 'sells' => 'physical' ]`, or the long
	 * form with an operator. The condition reads stored answers, so a step
	 * that depends on an earlier one appears once that one is saved.
	 *
	 * @param string               $key  The step's key.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return bool
	 */
	private function applies( string $key, array $step ): bool {
		$rule = $step['show_if'];

		if ( is_callable( $rule ) ) {
			$applies = (bool) call_user_func( $rule, $this );
		} elseif ( is_array( $rule ) && [] !== $rule ) {
			$conditions = Conditions::from( $rule );
			$values     = [];

			foreach ( $conditions->to_array() as $condition ) {
				$values[ $condition['field'] ] = $this->value( (string) $condition['field'] );
			}

			$applies = $conditions->are_met( $values );
		} else {
			$applies = true;
		}

		/**
		 * Filter whether a step applies.
		 *
		 * @param bool   $applies Whether it applies.
		 * @param string $key     The step's key.
		 * @param array  $step    The step.
		 * @param Wizard $wizard  The wizard.
		 *
		 * @since 1.0.0
		 */
		$applies = (bool) apply_filters( "sugarcommerce_onboarding_step_applies_{$this->id}", $applies, $key, $step, $this );

		return $applies;
	}

	/**
	 * The keys of the steps that apply.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->steps() );
	}

	/**
	 * One step, if it applies.
	 *
	 * @param string $key The step's key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function step( string $key ): ?array {
		return $this->steps()[ $key ] ?? null;
	}

	/**
	 * The step before or after another.
	 *
	 * @param string $key       The step to move from.
	 * @param int    $direction 1 for the next, -1 for the one before.
	 *
	 * @return string The step's key, or empty at either end.
	 */
	public function adjacent( string $key, int $direction ): string {
		$keys  = $this->keys();
		$index = array_search( $key, $keys, true );

		if ( false === $index ) {
			return '';
		}

		return (string) ( $keys[ $index + $direction ] ?? '' );
	}

	/**
	 * Whether a step is the last that applies.
	 *
	 * @param string $key The step's key.
	 *
	 * @return bool
	 */
	public function is_last( string $key ): bool {
		return '' === $this->adjacent( $key, 1 );
	}

	/**
	 * Where a step is in the order, from one.
	 *
	 * @param string $key The step's key.
	 *
	 * @return int Zero when it does not apply.
	 */
	public function position( string $key ): int {
		$index = array_search( $key, $this->keys(), true );

		return false === $index ? 0 : $index + 1;
	}

	/**
	 * The first step neither answered nor skipped.
	 *
	 * @return string Empty when every step has been dealt with.
	 */
	public function resume_key(): string {
		$state = $this->state()->get();

		foreach ( $this->keys() as $key ) {
			if ( ! in_array( $key, $state['done'], true ) && ! in_array( $key, $state['skipped'], true ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Whether a step may be opened.
	 *
	 * Any step up to the first one nobody has dealt with. Later steps can
	 * depend on earlier answers — a shipping step on whether anything ships
	 * — so jumping ahead by URL lands where the wizard actually is.
	 *
	 * @param string $key The step's key.
	 *
	 * @return bool
	 */
	public function is_reachable( string $key ): bool {
		$position = $this->position( $key );

		if ( 0 === $position ) {
			return false;
		}

		$resume = $this->resume_key();

		return '' === $resume || $position <= $this->position( $resume );
	}

	/**
	 * The status of every step that applies.
	 *
	 * @return array<string, string> done, skipped or todo, keyed by step.
	 */
	public function statuses(): array {
		$state    = $this->state()->get();
		$statuses = [];

		foreach ( $this->keys() as $key ) {
			if ( in_array( $key, $state['done'], true ) ) {
				$statuses[ $key ] = 'done';
			} elseif ( in_array( $key, $state['skipped'], true ) ) {
				$statuses[ $key ] = 'skipped';
			} else {
				$statuses[ $key ] = 'todo';
			}
		}

		return $statuses;
	}

	/**
	 * Everything worth knowing about where the wizard is.
	 *
	 * The shape an API or an agent wants: counts, the step to resume at, and
	 * each step's title and status.
	 *
	 * @return array<string, mixed>
	 */
	public function progress(): array {
		$state    = $this->state()->get();
		$statuses = $this->statuses();
		$steps    = [];

		foreach ( $this->steps() as $key => $step ) {
			$steps[ $key ] = [
				'title'  => (string) $step['title'],
				'status' => $statuses[ $key ],
			];
		}

		$handled = count( array_filter( $statuses, static fn( string $status ): bool => 'todo' !== $status ) );

		return [
			'id'        => $this->id,
			'completed' => $state['completed'] > 0,
			'total'     => count( $statuses ),
			'done'      => count( array_keys( $statuses, 'done', true ) ),
			'skipped'   => count( array_keys( $statuses, 'skipped', true ) ),
			'percent'   => [] === $statuses ? 0 : (int) round( 100 * $handled / count( $statuses ) ),
			'resume'    => $this->resume_key(),
			'url'       => $this->url(),
			'steps'     => $steps,
		];
	}

	/**
	 * The step the request is on.
	 *
	 * The one asked for if it may be opened. Otherwise where the wizard
	 * left off — the step after the last one dealt with — or the first step
	 * of a wizard already finished.
	 *
	 * @return string
	 */
	public function current_key(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which step to show, not acting on it.
		$asked = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';

		if ( '' !== $asked && $this->is_reachable( $asked ) ) {
			return $asked;
		}

		$resume = $this->resume_key();

		return '' !== $resume ? $resume : (string) ( $this->keys()[0] ?? '' );
	}

	/**
	 * Whether the request is for the screen after the last step.
	 *
	 * Only once the wizard is finished: a finish screen reached early would
	 * congratulate somebody on setup they have not done.
	 *
	 * @return bool
	 */
	public function is_finish_screen(): bool {
		if ( false === $this->config['finish'] || ! $this->is_completed() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen to show, not acting on it.
		if ( isset( $_GET['finished'] ) ) {
			return true;
		}

		// A finished wizard opened with no step asked for lands on what to
		// do next, rather than back at the welcome screen.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- as above.
		return ! isset( $_GET['step'] );
	}
}
