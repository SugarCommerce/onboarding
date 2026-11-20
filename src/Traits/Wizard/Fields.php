<?php
/**
 * Wizard: Fields
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Wizard;

use SugarCommerce\Fields\Context\OptionContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Onboarding\Support\Storage;
use SugarCommerce\Settings\Page;

/**
 * A step's fields: where their values live, and the set drawn and saved.
 *
 * Part of Wizard: only the class uses it.
 */
trait Fields {

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
}
