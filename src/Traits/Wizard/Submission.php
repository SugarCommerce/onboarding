<?php
/**
 * Wizard: Submission
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Wizard;

use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Onboarding\Support\Draft;
use SugarCommerce\Onboarding\Wizard;
use WP_Error;

/**
 * Taking a step's submission: checked, held as a draft, saved, skipped or finished.
 *
 * Part of Wizard: only the class uses it.
 */
trait Submission {

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
}
