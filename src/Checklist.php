<?php
/**
 * Checklist
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding;

use SugarCommerce\Onboarding\Traits\Checklist\Visibility;
use SugarCommerce\Onboarding\Traits\Checklist\Display;

/**
 * A setup guide that outlives the wizard.
 *
 * A wizard is a sitting; getting a plugin properly working is several, and
 * some of what it takes — a first product, a first sale — cannot happen
 * inside a wizard at all. This is the list that stays on screen until it is
 * done: each task says how to tell whether it has been done, and the card
 * counts them.
 *
 * Done is worked out, not remembered. "Add your first product" is done when
 * a product exists, so deleting the only product puts the task back, and a
 * product added through an import ticks it without anybody visiting the
 * card. A task with no way to tell is ticked by hand, and that is stored.
 *
 * Hiding the card is per person: one administrator dismissing it does not
 * take it away from another who has not finished reading it.
 */
final class Checklist {

	use Visibility;
	use Display;

	/**
	 * The checklist's identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Its configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Every task, filtered and normalized, once asked for.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $tasks = null;

	/**
	 * Done-checks already run this request, by task.
	 *
	 * A check may be a query, and a page that draws the card and asks for
	 * its status would otherwise run each one twice.
	 *
	 * @var array<string, bool>
	 */
	private array $checked = [];

	/**
	 * Construct.
	 *
	 * @param string               $id     The checklist's identifier.
	 * @param array<string, mixed> $config Its configuration.
	 */
	public function __construct( string $id, array $config ) {
		$this->id     = $id;
		$this->config = array_merge(
			[
				'title'              => __( 'Setup guide', 'sugarcommerce' ),
				'description'        => '',
				'capability'         => 'manage_options',
				'tasks'              => [],
				'wizard'             => '',
				'dismissible'        => true,
				'hide_when_complete' => false,
				'dashboard_widget'   => false,
				'complete_title'     => __( 'Setup complete', 'sugarcommerce' ),
				'complete_message'   => __( 'Everything on this list is done.', 'sugarcommerce' ),
				'labels'             => [],
			],
			$config
		);

		$this->config['labels'] = array_merge(
			[
				'dismiss'   => __( 'Dismiss', 'sugarcommerce' ),
				'restore'   => __( 'Show Setup Guide', 'sugarcommerce' ),
				'mark_done' => __( 'Mark as Done', 'sugarcommerce' ),
				'mark_todo' => __( 'Mark as Not Done', 'sugarcommerce' ),
				'wizard'    => __( 'Continue Setup', 'sugarcommerce' ),
				'next'      => __( 'Next:', 'sugarcommerce' ),
				'show_all'  => __( 'Show All', 'sugarcommerce' ),
				'show_less' => __( 'Show Less', 'sugarcommerce' ),
			],
			(array) $this->config['labels']
		);
	}

	/**
	 * The checklist's identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * One configuration value.
	 *
	 * @param string $key      The key.
	 * @param mixed  $fallback Returned when it is not set.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->config[ $key ] ?? $fallback;
	}

	/**
	 * One label.
	 *
	 * @param string $key The label.
	 *
	 * @return string
	 */
	public function label( string $key ): string {
		return (string) ( $this->config['labels'][ $key ] ?? '' );
	}

	/**
	 * Whether this user may see it and act on it.
	 *
	 * @return bool
	 */
	public function is_permitted(): bool {
		return current_user_can( (string) $this->config['capability'] );
	}

	/* ---------------------------------------------------------------------
	 * Tasks
	 * ------------------------------------------------------------------ */

	/**
	 * Every task that applies, in order.
	 *
	 * Filtered once, the first time anything asks, so an add-on loaded after
	 * the plugin that owns the list can still add to it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function tasks(): array {
		if ( null === $this->tasks ) {
			$tasks = (array) $this->config['tasks'];

			/**
			 * Filter a checklist's tasks, to add, remove or reorder them.
			 *
			 * @param array     $tasks     The tasks, keyed.
			 * @param Checklist $checklist The checklist.
			 *
			 * @since 1.0.0
			 */
			$tasks = (array) apply_filters( "sugarcommerce_onboarding_checklist_tasks_{$this->id}", $tasks, $this );

			$this->tasks = [];

			foreach ( $tasks as $key => $task ) {
				$key = sanitize_key( (string) $key );

				if ( '' === $key || ! is_array( $task ) ) {
					continue;
				}

				$this->tasks[ $key ] = array_merge(
					[
						'title'       => '',
						'description' => '',
						'done'        => null,
						'url'         => '',
						'button'      => '',
						'external'    => false,
						'optional'    => false,
						'show_if'     => null,
					],
					$task
				);
			}
		}

		return array_filter(
			$this->tasks,
			static fn( array $task ): bool => ! is_callable( $task['show_if'] ) || (bool) call_user_func( $task['show_if'] )
		);
	}

	/**
	 * One task, if it applies.
	 *
	 * @param string $key The task's key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function task( string $key ): ?array {
		return $this->tasks()[ $key ] ?? null;
	}

	/**
	 * Whether a task is ticked by hand rather than worked out.
	 *
	 * @param string $key The task's key.
	 *
	 * @return bool
	 */
	public function is_manual( string $key ): bool {
		$task = $this->task( $key );

		return null !== $task && ! is_callable( $task['done'] );
	}

	/**
	 * Whether a task is done.
	 *
	 * Asked of the task's `done` callback when it has one — its answer is
	 * taken as a yes only when it is exactly true, so a callback that returns
	 * a count or an id has to say what it means — and of what was ticked by
	 * hand when it does not.
	 *
	 * @param string $key The task's key.
	 *
	 * @return bool
	 */
	public function is_done( string $key ): bool {
		if ( isset( $this->checked[ $key ] ) ) {
			return $this->checked[ $key ];
		}

		$task = $this->task( $key );

		if ( null === $task ) {
			return false;
		}

		$done = is_callable( $task['done'] )
			? true === call_user_func( $task['done'], $this )
			: in_array( $key, $this->ticked(), true );

		/**
		 * Filter whether a task is done.
		 *
		 * @param bool      $done      Whether it is done.
		 * @param string    $key       The task's key.
		 * @param array     $task      The task.
		 * @param Checklist $checklist The checklist.
		 *
		 * @since 1.0.0
		 */
		$done = (bool) apply_filters( "sugarcommerce_onboarding_checklist_task_done_{$this->id}", $done, $key, $task, $this );

		$this->checked[ $key ] = $done;

		return $done;
	}

	/**
	 * Tick or untick a task by hand.
	 *
	 * Only a task with no `done` callback: one that is worked out cannot be
	 * overruled, or the card would say a product exists when it does not.
	 *
	 * @param string $key  The task's key.
	 * @param bool   $done Whether it is done.
	 *
	 * @return bool False when the task does not exist or is worked out.
	 */
	public function mark( string $key, bool $done ): bool {
		if ( ! $this->is_manual( $key ) ) {
			return false;
		}

		$ticked = array_values( array_diff( $this->ticked(), [ $key ] ) );

		if ( $done ) {
			$ticked[] = $key;
		}

		update_option( $this->state_option(), [ 'done' => $ticked ], false );

		unset( $this->checked[ $key ] );

		/**
		 * Fires when a task is ticked or unticked by hand.
		 *
		 * @param string    $key       The task's key.
		 * @param bool      $done      Whether it is now done.
		 * @param Checklist $checklist The checklist.
		 *
		 * @since 1.0.0
		 */
		do_action( "sugarcommerce_onboarding_checklist_task_marked_{$this->id}", $key, $done, $this );

		return true;
	}

	/**
	 * The tasks ticked by hand.
	 *
	 * @return string[]
	 */
	private function ticked(): array {
		$stored = get_option( $this->state_option(), [] );

		return array_values( array_map( 'strval', (array) ( is_array( $stored ) ? ( $stored['done'] ?? [] ) : [] ) ) );
	}

	/**
	 * The option holding what was ticked by hand.
	 *
	 * @return string
	 */
	public function state_option(): string {
		return str_replace( '-', '_', sanitize_key( $this->id ) ) . '_checklist';
	}

	/**
	 * Forget every check run this request.
	 *
	 * For code that changes what a check would find and then draws the card
	 * again in the same request.
	 *
	 * @return void
	 */
	public function refresh(): void {
		$this->checked = [];
	}

	/* ---------------------------------------------------------------------
	 * Status
	 * ------------------------------------------------------------------ */

	/**
	 * Where the list stands.
	 *
	 * The shape an API or an agent wants: how many of how many, whether
	 * everything required is done, and each task with its own status.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$tasks    = [];
		$done     = 0;
		$required = 0;
		$missing  = 0;

		foreach ( $this->tasks() as $key => $task ) {
			$is_done = $this->is_done( $key );

			$tasks[ $key ] = [
				'title'    => (string) $task['title'],
				'done'     => $is_done,
				'optional' => (bool) $task['optional'],
				'manual'   => ! is_callable( $task['done'] ),
				'url'      => (string) $task['url'],
			];

			$done += $is_done ? 1 : 0;

			if ( ! $task['optional'] ) {
				++$required;
				$missing += $is_done ? 0 : 1;
			}
		}

		$status = [
			'id'        => $this->id,
			'title'     => (string) $this->config['title'],
			'total'     => count( $tasks ),
			'done'      => $done,
			'remaining' => count( $tasks ) - $done,
			// Every required task done; or, on a list where nothing is
			// required, everything done. An empty list is never complete:
			// there is nothing to congratulate anybody on.
			'complete'  => [] !== $tasks && ( $required > 0 ? 0 === $missing : $done === count( $tasks ) ),
			'percent'   => [] === $tasks ? 0 : (int) round( 100 * $done / count( $tasks ) ),
			'next'      => self::next_of( $tasks ),
			'tasks'     => $tasks,
		];

		/**
		 * Filter a checklist's status.
		 *
		 * @param array     $status    The status.
		 * @param Checklist $checklist The checklist.
		 *
		 * @since 1.0.0
		 */
		$status = (array) apply_filters( "sugarcommerce_onboarding_checklist_status_{$this->id}", $status, $this );

		return $status;
	}

	/**
	 * The task to do next, out of a status's tasks.
	 *
	 * The first one not done that is required, and only once every required
	 * task is done the first optional one. The compact card shows exactly one
	 * task, so it has to be the one that holds the list back: offering "Pick a
	 * theme" while payments are still not connected would send somebody the
	 * wrong way. In order otherwise, because the order is the plugin's own
	 * advice about what comes first.
	 *
	 * @param array<string, array<string, mixed>> $tasks Each task's status.
	 *
	 * @return string The task's key, or nothing when every task is done.
	 */
	private static function next_of( array $tasks ): string {
		$open     = array_filter( $tasks, static fn( array $task ): bool => ! $task['done'] );
		$required = array_filter( $open, static fn( array $task ): bool => ! $task['optional'] );

		return (string) ( array_key_first( [] === $required ? $open : $required ) ?? '' );
	}

	/**
	 * Whether every task that is not optional is done.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return (bool) $this->status()['complete'];
	}

	/* ---------------------------------------------------------------------
	 * Dismissal
	 * ------------------------------------------------------------------ */

	/* ---------------------------------------------------------------------
	 * The compact card's list
	 * ------------------------------------------------------------------ */

	/* ---------------------------------------------------------------------
	 * Drawing
	 * ------------------------------------------------------------------ */
}
