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

use SugarCommerce\Onboarding\Support\Draft;
use SugarCommerce\Onboarding\Support\State;
use SugarCommerce\Onboarding\Traits\Wizard\Configuration;
use SugarCommerce\Onboarding\Traits\Wizard\Steps;
use SugarCommerce\Onboarding\Traits\Wizard\Fields;
use SugarCommerce\Onboarding\Traits\Wizard\Submission;
use SugarCommerce\Onboarding\Traits\Wizard\Admin;

/**
 * One setup wizard: its steps, where it stores answers, and how it advances.
 *
 * The rendering is Screen's. What is here is everything that has to be right
 * whether or not anybody looks at the page — which steps apply, which one is
 * current, whether this user may submit it, what a submission sanitizes to,
 * whether it passes, where it is stored, and when the wizard is finished.
 */
final class Wizard {

	use Configuration;
	use Steps;
	use Fields;
	use Submission;
	use Admin;

	/**
	 * The step key reserved for a step's own errors, as opposed to a field's.
	 */
	public const string STEP_ERROR = '_step';

	/**
	 * What a step submission can ask for.
	 */
	private const array INTENTS = [ 'next', 'skip', 'later' ];

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

	/* ---------------------------------------------------------------------
	 * Progress
	 * ------------------------------------------------------------------ */

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
		// admin.php, because the parent has no file of its own. Told by the
		// slug as well as by the menu: off an admin screen -- admin-post.php,
		// REST, cron -- the menu was never built, and the parent's slug went
		// into the address as if it were a file.
		$base = ( '' === $parent || isset( $_parent_pages[ $parent ] ) || ! str_contains( $parent, '.php' ) ) ? 'admin.php' : $parent;

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

	/* ---------------------------------------------------------------------
	 * Submission
	 * ------------------------------------------------------------------ */

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------ */
}
