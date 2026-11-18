<?php
/**
 * Wizard
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding;

use SugarCommerce\Fields\Assets as FieldAssets;
use SugarCommerce\Fields\Conditions;
use SugarCommerce\Fields\Context\OptionContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Onboarding\Support\Draft;
use SugarCommerce\Onboarding\Support\State;
use SugarCommerce\Onboarding\Support\Storage;
use SugarCommerce\Onboarding\Utils\Assets;
use SugarCommerce\Onboarding\Utils\Runtime;
use SugarCommerce\Settings\Page;
use WP_Error;

/**
 * One setup wizard: its steps, where it stores answers, and how it advances.
 *
 * The rendering is Screen's. What is here is everything that has to be right
 * whether or not anybody looks at the page — which steps apply, which one is
 * current, whether this user may submit it, what a submission sanitizes to,
 * whether it passes, where it is stored, and when the wizard is finished.
 */
final class Wizard {

	/**
	 * The step key reserved for a step's own errors, as opposed to a field's.
	 */
	public const STEP_ERROR = '_step';

	/**
	 * What a step submission can ask for.
	 */
	private const INTENTS = [ 'next', 'skip', 'later' ];

	/**
	 * The wizard's identifier.
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
	 * Every step, filtered and normalized, once asked for.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $all_steps = null;

	/**
	 * Errors from a submission that did not pass, keyed by field.
	 *
	 * @var array<string, string>
	 */
	private array $errors = [];

	/**
	 * What the failed submission held, for drawing it again.
	 *
	 * @var Draft|null
	 */
	private ?Draft $submitted = null;

	/**
	 * The screen hook this wizard's page was registered under.
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Construct.
	 *
	 * @param string               $id     The wizard's identifier.
	 * @param array<string, mixed> $config Its configuration.
	 */
	public function __construct( string $id, array $config ) {
		$this->id     = $id;
		$this->config = self::normalize( $id, $config );
	}

	/**
	 * Fill in everything the configuration did not say.
	 *
	 * @param string               $id     The wizard's identifier.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return array<string, mixed>
	 */
	private static function normalize( string $id, array $config ): array {
		$config = array_merge(
			[
				// Who may run it, and where it lives. index.php rather than
				// nothing: a page with no parent used to be registered against
				// null, which core hands to plugin_basename() and PHP 8.1
				// deprecates. Hidden is the same page with its menu item taken
				// back out, which is how core's own hidden screens do it.
				'capability'         => 'manage_options',
				'parent_slug'        => 'index.php',
				'menu_slug'          => '',
				'hidden'             => true,

				// What it looks like.
				'layout'             => 'focus',
				'progress'           => 'top',
				'page_title'         => '',
				'menu_title'         => '',
				'title'              => '',
				'logo'               => '',
				'accent'             => '',
				'body_class'         => '',
				'notices'            => false,
				'labels'             => [],

				// When it runs, and where it ends.
				'redirect'           => false,
				'exit_url'           => '',
				'completed_redirect' => '',
				'finish'             => [],

				// Where answers go: one option holding every field, a pair of
				// callbacks, a field context, a settings page -- or, saying
				// nothing, an option per field.
				'option'             => '',
				'get_callback'       => null,
				'update_callback'    => null,
				'context'            => null,
				'settings'           => '',

				// Where progress goes.
				'state_option'       => '',

				'steps'              => [],
			],
			$config
		);

		$config['menu_slug'] = sanitize_key( '' === (string) $config['menu_slug'] ? $id : (string) $config['menu_slug'] );
		$config['layout']    = 'admin' === $config['layout'] ? 'admin' : 'focus';
		$config['progress']  = 'side' === $config['progress'] ? 'side' : 'top';

		if ( '' === (string) $config['page_title'] ) {
			$config['page_title'] = __( 'Setup', 'sugarcommerce' );
		}

		foreach ( [ 'menu_title', 'title' ] as $key ) {
			if ( '' === (string) $config[ $key ] ) {
				$config[ $key ] = $config['page_title'];
			}
		}

		if ( '' === (string) $config['state_option'] ) {
			$config['state_option'] = str_replace( '-', '_', sanitize_key( $id ) ) . '_onboarding';
		}

		$config['labels'] = array_merge(
			[
				'next'   => __( 'Continue', 'sugarcommerce' ),
				'back'   => __( 'Back', 'sugarcommerce' ),
				'skip'   => __( 'Skip', 'sugarcommerce' ),
				'finish' => __( 'Finish Setup', 'sugarcommerce' ),
				'later'  => __( 'Save and Finish Later', 'sugarcommerce' ),
				'exit'   => __( 'Exit Setup', 'sugarcommerce' ),
			],
			(array) $config['labels']
		);

		// Hex or nothing. It lands in a style attribute, and esc_attr() stops
		// a value closing the attribute but not one appending a second
		// declaration after a semicolon.
		$config['accent'] = (string) ( sanitize_hex_color( (string) $config['accent'] ) ?? '' );

		// A finish screen unless the wizard says false: the moment somebody
		// finishes is the one moment they are guaranteed to read what to do
		// next, and sending them straight back to the dashboard wastes it.
		$config['finish'] = false === $config['finish'] ? false : array_merge(
			[
				'title'       => __( 'You’re all set', 'sugarcommerce' ),
				'description' => '',
				'content'     => '',
				'image'       => '',
				'items'       => [],
				'links'       => [],
				'checklist'   => '',
				'render'      => null,
				'confetti'    => true,
			],
			(array) $config['finish']
		);

		return $config;
	}

	/**
	 * Fill in every step's defaults.
	 *
	 * @param array<string, array<string, mixed>> $steps The steps.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_steps( array $steps ): array {
		$normalized = [];

		foreach ( $steps as $key => $step ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_array( $step ) ) {
				continue;
			}

			$normalized[ $key ] = array_merge(
				[
					'title'       => '',
					'short_title' => '',
					'description' => '',
					'type'        => '',
					'show_if'     => null,
					'skippable'   => false,
					'skip_label'  => '',
					'button'      => '',

					// What the step shows, top to bottom.
					'image'       => '',
					'content'     => '',
					'items'       => [],
					'fields'      => [],
					'render'      => null,
					'links'       => [],

					// sugarcommerce/jobs, for a step that imports.
					'sync_id'     => '',

					'validate'    => null,
					'save'        => null,
				],
				$step
			);
		}

		return $normalized;
	}

	/**
	 * Fill in each field that is a setting from the settings page.
	 *
	 * With `settings` naming a sugarcommerce/settings page, a step's field
	 * with no `type` is that page's field of the same key: the same control,
	 * the same choices, the same rules and stored the same way -- encrypted
	 * where the page encrypts -- with the step's own keys laid over it.
	 * Where it sits on the page (`tab`, `section`, `width`) means nothing in
	 * a wizard, which is a column a step at a time, and is dropped. A field
	 * the page does not have is dropped too, rather than drawn as a text box
	 * nobody configured.
	 *
	 * @param array<string, array<string, mixed>> $steps The steps.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function with_settings( array $steps ): array {
		$id = (string) $this->config['settings'];

		if ( '' === $id ) {
			return $steps;
		}

		$page = Page::get( $id );

		foreach ( $steps as $key => $step ) {
			$fields = [];

			foreach ( (array) $step['fields'] as $field_key => $field ) {
				if ( ! is_array( $field ) || isset( $field['type'] ) ) {
					$fields[ $field_key ] = $field;

					continue;
				}

				$setting = $page?->get_field( (string) $field_key );

				if ( ! is_array( $setting ) ) {
					continue;
				}

				unset( $setting['tab'], $setting['section'], $setting['width'] );

				$fields[ $field_key ] = array_merge( $setting, $field );
			}

			$steps[ $key ]['fields'] = $fields;
		}

		return $steps;
	}

	/**
	 * The wizard's identifier.
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
	 * The page slug.
	 *
	 * @return string
	 */
	public function slug(): string {
		return (string) $this->config['menu_slug'];
	}

	/**
	 * Whether this user may run the wizard.
	 *
	 * @return bool
	 */
	public function is_permitted(): bool {
		return current_user_can( (string) $this->config['capability'] );
	}

	/**
	 * Where progress is kept.
	 *
	 * @return State
	 */
	public function state(): State {
		return new State( (string) $this->config['state_option'] );
	}

	/* ---------------------------------------------------------------------
	 * Steps
	 * ------------------------------------------------------------------ */

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

	/* ---------------------------------------------------------------------
	 * Progress
	 * ------------------------------------------------------------------ */

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

	/* ---------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------ */

	/**
	 * The URL of a step.
	 *
	 * Built rather than taken from menu_page_url(), which escapes what it
	 * returns for display — turning every `&` into an entity, which is right
	 * in an href and wrong in a redirect. The rule for which file a page is
	 * reached through is core's own, from that function.
	 *
	 * @param string               $step The step, or empty for wherever the wizard is.
	 * @param array<string, mixed> $args Anything else to add to the query.
	 *
	 * @return string
	 */
	public function url( string $step = '', array $args = [] ): string {
		global $_parent_pages;

		$slug   = $this->slug();
		$parent = (string) ( $_parent_pages[ $slug ] ?? $this->config['parent_slug'] );

		// A page whose parent is itself a plugin page is reached through
		// admin.php, because the parent has no file of its own.
		$base = ( '' === $parent || isset( $_parent_pages[ $parent ] ) ) ? 'admin.php' : $parent;

		$query = [ 'page' => $slug ];

		if ( '' !== $step ) {
			$query['step'] = $step;
		}

		return admin_url( add_query_arg( array_merge( $query, $args ), $base ) );
	}

	/**
	 * The URL of the screen after the last step.
	 *
	 * @return string
	 */
	public function finish_url(): string {
		return $this->url( '', [ 'finished' => 1 ] );
	}

	/**
	 * Where leaving the wizard goes.
	 *
	 * @return string
	 */
	public function exit_url(): string {
		$url = (string) $this->config['exit_url'];

		return '' === $url ? admin_url() : $url;
	}

	/* ---------------------------------------------------------------------
	 * Completion
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the wizard has been finished.
	 *
	 * @return bool
	 */
	public function is_completed(): bool {
		return $this->state()->get()['completed'] > 0;
	}

	/**
	 * Record that it has been finished.
	 *
	 * @return void
	 */
	public function complete(): void {
		$this->state()->complete();

		/**
		 * Fires when a wizard is finished.
		 *
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		do_action( "sugarcommerce_onboarding_completed_{$this->id}", $this );
	}

	/**
	 * Let it run again.
	 *
	 * Progress only. The answers stay where they were stored, so the wizard
	 * runs again with each of them filled in.
	 *
	 * @return bool
	 */
	public function reset(): bool {
		$reset = $this->state()->reset();

		/**
		 * Fires when a wizard's progress is reset.
		 *
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		do_action( "sugarcommerce_onboarding_reset_{$this->id}", $this );

		return $reset;
	}

	/* ---------------------------------------------------------------------
	 * Fields
	 * ------------------------------------------------------------------ */

	/**
	 * Where this wizard's answers are stored.
	 *
	 * @return Context
	 */
	public function context(): Context {
		$context = $this->config['context'];

		if ( $context instanceof Context ) {
			return $context;
		}

		if ( '' !== (string) $this->config['option'] ) {
			return new OptionContext( (string) $this->config['option'] );
		}

		$reader = is_callable( $this->config['get_callback'] ) ? $this->config['get_callback'] : null;
		$writer = is_callable( $this->config['update_callback'] ) ? $this->config['update_callback'] : null;

		// Through the settings page's own reader, so a secret is written
		// encrypted and read decrypted exactly as the page does it. A pair
		// of callbacks wins, for a plugin whose own wrapper fires hooks.
		if ( null === $reader && null === $writer && '' !== (string) $this->config['settings'] ) {
			$options = Page::options( (string) $this->config['settings'] );

			$reader = static fn( string $key ): mixed => $options->get( $key, null );
			$writer = static fn( string $key, mixed $value ): bool => null === $value ? $options->delete( $key ) : $options->update( $key, $value );
		}

		return new Storage( $reader, $writer );
	}

	/**
	 * The field set for a step.
	 *
	 * A button's action is run by whoever may run the wizard, unless the
	 * field says otherwise: the kit's own default is manage_options, and a
	 * wizard opened to shop managers would draw them buttons that refuse.
	 *
	 * @param array<string, mixed> $step    The step.
	 * @param Context|null         $context Where values come from and go.
	 *
	 * @return FieldSet|null Null when the step has no fields.
	 */
	public function fields( array $step, ?Context $context = null ): ?FieldSet {
		$configs = [];

		foreach ( (array) $step['fields'] as $key => $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			if ( ! isset( $config['action_capability'] ) && ( isset( $config['action_callback'] ) || isset( $config['actions'] ) ) ) {
				$config['action_capability'] = (string) $this->config['capability'];
			}

			$configs[ $key ] = $config;
		}

		if ( [] === $configs ) {
			return null;
		}

		return new FieldSet( $configs, $context ?? $this->context(), $this->input_name() );
	}

	/**
	 * The field set for the step being drawn.
	 *
	 * Seeded with the submission when one failed, so a field the user
	 * cleared stays cleared and one they mistyped still shows what they
	 * typed, rather than either reverting to what is stored.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return FieldSet|null
	 */
	public function fields_to_render( array $step ): ?FieldSet {
		return $this->fields( $step, $this->submitted );
	}

	/**
	 * One stored answer, from whichever step asks for it.
	 *
	 * @param string $key The field's key.
	 *
	 * @return mixed Null when no step has the field, or nothing is stored.
	 */
	public function value( string $key ): mixed {
		foreach ( $this->all_steps() as $step ) {
			if ( ! isset( $step['fields'][ $key ] ) ) {
				continue;
			}

			$field = $this->fields( $step )?->field( $key );

			return null === $field || ! $field->type()->stores_value() ? null : $field->value();
		}

		return null;
	}

	/**
	 * The name a step's fields are submitted under.
	 *
	 * @return string
	 */
	public function input_name(): string {
		return 'onboarding_' . str_replace( '-', '_', sanitize_key( $this->id ) );
	}

	/**
	 * Errors from a submission that did not pass.
	 *
	 * @return array<string, string>
	 */
	public function errors(): array {
		return $this->errors;
	}

	/* ---------------------------------------------------------------------
	 * Submission
	 * ------------------------------------------------------------------ */

	/**
	 * Handle a submission of one of this wizard's steps.
	 *
	 * Continue validates and stores the step and moves on. Skip stores
	 * nothing and moves on. Save and finish later validates and stores the
	 * step and leaves, to pick up at the next one. Returning means the step
	 * did not pass and is drawn again with its errors.
	 *
	 * @return void
	 */
	public function submit(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified two lines down, once the step is known.
		$key = isset( $_POST['onboarding_step'] ) ? sanitize_key( wp_unslash( $_POST['onboarding_step'] ) ) : '';

		check_admin_referer( 'onboarding_' . $this->id . '_' . $key );

		if ( ! $this->is_permitted() ) {
			wp_die(
				esc_html__( 'You are not allowed to run this setup.', 'sugarcommerce' ),
				'',
				[ 'response' => 403 ]
			);
		}

		$intent = $this->intent();
		$step   = $this->step( $key );

		// A step that no longer applies — its show_if changed while the form
		// was open — or one not reached yet is not saved. Back to where the
		// wizard is rather than an error page: the answers that got here are
		// still stored.
		if ( null === $step || ! $this->is_reachable( $key ) ) {
			$this->go( $this->url() );
		}

		if ( 'skip' === $intent ) {
			$this->skip( $key, $step );
		}

		$errors = $this->attempt( $key, $step );

		if ( [] !== $errors ) {
			$this->errors = $errors;

			return;
		}

		$this->state()->mark_done( $key );

		$next = $this->adjacent( $key, 1 );

		if ( 'later' === $intent ) {
			$this->state()->set_current( '' === $next ? $key : $next );
			$this->go( $this->exit_url() );
		}

		if ( '' === $next ) {
			$this->finish();
		}

		$this->state()->set_current( $next );
		$this->go( $this->url( $next ) );
	}

	/**
	 * What the submission asked for.
	 *
	 * @return string next, skip or later.
	 */
	private function intent(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- submit() verified the nonce.
		$intent = isset( $_POST['onboarding_intent'] ) ? sanitize_key( wp_unslash( $_POST['onboarding_intent'] ) ) : 'next';

		return in_array( $intent, self::INTENTS, true ) ? $intent : 'next';
	}

	/**
	 * Move past a step without storing anything.
	 *
	 * @param string               $key  The step's key.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return never
	 */
	private function skip( string $key, array $step ): never {
		// A step that cannot be skipped is drawn again, not advanced: a
		// hand-built request is the only way to ask, and it gets nothing.
		if ( empty( $step['skippable'] ) ) {
			$this->go( $this->url( $key ) );
		}

		$this->state()->mark_skipped( $key );

		/**
		 * Fires when a step is skipped.
		 *
		 * @param string $key    The step's key.
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		do_action( "sugarcommerce_onboarding_step_skipped_{$this->id}", $key, $this );

		$next = $this->adjacent( $key, 1 );

		if ( '' === $next ) {
			$this->finish();
		}

		$this->state()->set_current( $next );
		$this->go( $this->url( $next ) );
	}

	/**
	 * Record the wizard as finished and go wherever it ends.
	 *
	 * @return never
	 */
	private function finish(): never {
		$this->complete();

		$url = (string) $this->config['completed_redirect'];

		if ( '' === $url ) {
			$url = false === $this->config['finish'] ? $this->exit_url() : $this->finish_url();
		}

		/**
		 * Filter where a finished wizard goes.
		 *
		 * @param string $url    The URL.
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		$url = (string) apply_filters( "sugarcommerce_onboarding_completed_redirect_{$this->id}", $url, $this );

		$this->go( $url );
	}

	/**
	 * Check a step and, if it passes, store it.
	 *
	 * All or nothing. The kit sanitizes each field by its type and refuses
	 * what is required and missing or fails its own `validate` rule; then
	 * the step's `validate` sees every answer together; then the filter. Only
	 * when none of them object is anything written.
	 *
	 * @param string               $key  The step's key.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return array<string, string> Messages keyed by field, or by STEP_ERROR.
	 */
	private function attempt( string $key, array $step ): array {
		$draft  = new Draft( $this->context() );
		$set    = $this->fields( $step, $draft );
		$errors = [];

		if ( null !== $set ) {
			// Still slashed: the field set unslashes once, at its own boundary.
			$set->save( $this->raw_input() );
			$errors = $set->errors();
		}

		$values = $this->values( $set, $draft, $errors );

		$errors = array_merge( $errors, $this->check( $step, $values ) );

		/**
		 * Filter what a step's submission is refused for.
		 *
		 * Add a message under a field's key to show it beside that field, or
		 * under Wizard::STEP_ERROR to show it for the step as a whole.
		 *
		 * @param array  $errors Messages keyed by field.
		 * @param string $key    The step's key.
		 * @param array  $values The sanitized answers.
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		$errors = array_map( 'strval', (array) apply_filters( "sugarcommerce_onboarding_validate_{$this->id}", $errors, $key, $values, $this ) );

		if ( [] !== $errors ) {
			$this->hold( $set, $draft, $errors );

			return $errors;
		}

		/**
		 * Filter a step's answers before they are stored.
		 *
		 * @param array  $values The sanitized answers.
		 * @param string $key    The step's key.
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		$values = (array) apply_filters( "sugarcommerce_onboarding_values_{$this->id}", $values, $key, $this );

		if ( is_callable( $step['save'] ) ) {
			$message = self::message( call_user_func( $step['save'], $values, $this ) );

			if ( '' !== $message ) {
				$this->submitted = $draft;

				return [ self::STEP_ERROR => $message ];
			}
		} else {
			$draft->replace( $values );
			$draft->commit();
		}

		/**
		 * Fires when a step's answers are stored.
		 *
		 * @param string $key    The step's key.
		 * @param array  $values The answers.
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		do_action( "sugarcommerce_onboarding_step_saved_{$this->id}", $key, $values, $this );

		return [];
	}

	/**
	 * Every answer the step's own check should see.
	 *
	 * What the kit would store, plus what is already stored for a field it
	 * left alone — a key nobody retyped is still the key, and a check that
	 * tests a connection has to be handed it.
	 *
	 * @param FieldSet|null         $set    The step's fields.
	 * @param Draft                 $draft  What the kit wrote.
	 * @param array<string, string> $errors What it refused.
	 *
	 * @return array<string, mixed>
	 */
	private function values( ?FieldSet $set, Draft $draft, array $errors ): array {
		$values = $draft->values();

		if ( null === $set ) {
			return $values;
		}

		foreach ( $set->fields() as $field ) {
			$key = $field->key();

			if ( $draft->touched( $key ) || isset( $errors[ $key ] ) || ! $field->type()->stores_value() ) {
				continue;
			}

			$values[ $key ] = $field->value();
		}

		return $values;
	}

	/**
	 * What the step's own `validate` says.
	 *
	 * It is handed every answer and the wizard, and may answer true (or
	 * nothing) to pass; false or a string to refuse the step; a WP_Error,
	 * whose codes that name a field put that message beside the field; or
	 * an array of messages keyed by field.
	 *
	 * @param array<string, mixed> $step   The step.
	 * @param array<string, mixed> $values The answers.
	 *
	 * @return array<string, string>
	 */
	private function check( array $step, array $values ): array {
		if ( ! is_callable( $step['validate'] ) ) {
			return [];
		}

		$result = call_user_func( $step['validate'], $values, $this );
		$fields = array_map( 'strval', array_keys( (array) $step['fields'] ) );

		if ( $result instanceof WP_Error ) {
			$errors = [];

			foreach ( $result->get_error_codes() as $code ) {
				$target = in_array( (string) $code, $fields, true ) ? (string) $code : self::STEP_ERROR;
				$text   = implode( ' ', $result->get_error_messages( $code ) );

				$errors[ $target ] = isset( $errors[ $target ] ) ? $errors[ $target ] . ' ' . $text : $text;
			}

			return $errors;
		}

		if ( is_array( $result ) ) {
			$errors = [];

			foreach ( $result as $target => $text ) {
				$text = (string) $text;

				if ( '' === $text ) {
					continue;
				}

				$target = in_array( (string) $target, $fields, true ) ? (string) $target : self::STEP_ERROR;

				$errors[ $target ] = isset( $errors[ $target ] ) ? $errors[ $target ] . ' ' . $text : $text;
			}

			return $errors;
		}

		$message = self::message( $result );

		return '' === $message ? [] : [ self::STEP_ERROR => $message ];
	}

	/**
	 * What a callback's answer says went wrong, if anything.
	 *
	 * @param mixed $result True, null or nothing to pass; false, a string or a WP_Error to refuse.
	 *
	 * @return string Empty when it passed.
	 */
	private static function message( mixed $result ): string {
		if ( $result instanceof WP_Error ) {
			return $result->get_error_message();
		}

		if ( false === $result ) {
			return __( 'Please check this step before continuing.', 'sugarcommerce' );
		}

		return is_string( $result ) ? $result : '';
	}

	/**
	 * Keep a refused submission for drawing it again.
	 *
	 * What passed is in the draft already. What the kit refused is not —
	 * it leaves a refused field untouched — so each is sanitized by its own
	 * type and held too, which is what the person typed and what they need
	 * to see to fix it.
	 *
	 * @param FieldSet|null         $set    The step's fields.
	 * @param Draft                 $draft  The draft.
	 * @param array<string, string> $errors The refusals.
	 *
	 * @return void
	 */
	private function hold( ?FieldSet $set, Draft $draft, array $errors ): void {
		$this->submitted = $draft;

		if ( null === $set ) {
			return;
		}

		$raw = wp_unslash( $this->raw_input() );

		foreach ( array_keys( $errors ) as $key ) {
			$field = $set->field( (string) $key );

			if ( null === $field || ! $field->type()->stores_value() || ! array_key_exists( $key, $raw ) ) {
				continue;
			}

			$override = $field->get( 'sanitize_callback' );

			$draft->write(
				0,
				$field,
				is_callable( $override ) ? $override( $raw[ $key ], $field ) : $field->type()->sanitize( $raw[ $key ], $field )
			);
		}
	}

	/**
	 * The step's submission, exactly as it arrived.
	 *
	 * Neither unslashed nor sanitized here, and both on purpose: the field
	 * set unslashes once at its own boundary — doing it twice eats every
	 * backslash the user typed — and sanitizes per field, by type, which is
	 * the only place that knows a number from an email address.
	 *
	 * @return array<string, mixed>
	 */
	private function raw_input(): array {
		$name = $this->input_name();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- submit() verified the nonce; the field set unslashes and sanitizes, per field.
		return isset( $_POST[ $name ] ) ? (array) $_POST[ $name ] : [];
	}

	/**
	 * Go somewhere and stop.
	 *
	 * @param string $url Where to.
	 *
	 * @return never
	 */
	private function go( string $url ): never {
		wp_safe_redirect( $url );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */

	/**
	 * Register the wizard's admin page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook = (string) add_submenu_page(
			(string) $this->config['parent_slug'],
			(string) $this->config['page_title'],
			(string) $this->config['menu_title'],
			(string) $this->config['capability'],
			$this->slug(),
			fn() => ( new Screen( $this ) )->render()
		);
	}

	/**
	 * Take the menu item back out, leaving the page reachable.
	 *
	 * @return void
	 */
	public function hide_menu(): void {
		if ( (bool) $this->config['hidden'] ) {
			remove_submenu_page( (string) $this->config['parent_slug'], $this->slug() );
		}
	}

	/**
	 * The screen hook the page was registered under.
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->hook;
	}

	/**
	 * Whether the request is on this wizard's page.
	 *
	 * @return bool
	 */
	public function is_current(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which page is being viewed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $page === $this->slug();
	}

	/**
	 * Load what the current screen needs.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$step = $this->is_finish_screen() ? null : $this->step( $this->current_key() );
		$set  = null === $step ? null : $this->fields_to_render( $step );

		if ( null !== $set ) {
			( new FieldAssets() )->enqueue( $set->dependencies() );
		}

		Assets::style( Runtime::handle(), 'css/onboarding.css' );
		Assets::script( Runtime::handle(), 'js/onboarding.js' );

		$checklist = false === $this->config['finish'] ? '' : (string) $this->config['finish']['checklist'];

		if ( $this->is_finish_screen() && '' !== $checklist ) {
			Onboarding::checklist( $checklist )?->enqueue();
		}
	}

	/**
	 * Build every step's fields, so their buttons and searches answer.
	 *
	 * A button's action is run on a later request than the one that drew
	 * it — a REST request, where no wizard screen is ever rendered — and the
	 * kit only knows a handler once the field set declaring it has been
	 * built. Every step, whether or not it applies: whether it applies can
	 * depend on an answer saved a moment ago.
	 *
	 * @return void
	 */
	public function register_endpoints(): void {
		foreach ( $this->all_steps() as $step ) {
			$this->fields( $step );
		}
	}

	/**
	 * Declare the configuration this library reads on top of the kit's.
	 *
	 * Without this the kit warns, under WP_DEBUG, that it does not know what
	 * `option` is for — which is the point of the warning, and would be
	 * wrong here.
	 *
	 * @return void
	 */
	public static function declare_config_keys(): void {
		Field::allow_config_keys( [ 'option' ] );
	}
}
