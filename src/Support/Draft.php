<?php
/**
 * Draft
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Support;

use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Contracts\Flushable;
use SugarCommerce\Fields\Field;

/**
 * A step's answers, held back until the whole step has passed.
 *
 * The kit saves what passes and refuses what does not, field by field. A
 * wizard step is one question, so it wants all or nothing: either every field
 * and the step's own check pass and the answers are stored, or nothing is and
 * the step comes back. This sits in front of the real store and remembers what
 * the kit would have written, so the kit's own walk decides what a field's
 * value is and the wizard decides whether any of it is kept.
 *
 * Reads go through to the real store for anything not yet written. That is
 * what makes a password box left empty mean "keep what is saved": the kit
 * asks the store whether something is there, and an empty in-memory array
 * would answer no and refuse a required key that is in fact set.
 */
final class Draft implements Context {

	/**
	 * Where the answers go once the step passes.
	 *
	 * @var Context
	 */
	private Context $store;

	/**
	 * Values the kit wrote, keyed by field.
	 *
	 * @var array<string, array{0: Field, 1: mixed}>
	 */
	private array $written = [];

	/**
	 * Fields the kit cleared, keyed by field.
	 *
	 * @var array<string, Field>
	 */
	private array $deleted = [];

	/**
	 * Construct.
	 *
	 * @param Context $store The real store.
	 */
	public function __construct( Context $store ) {
		$this->store = $store;
	}

	/**
	 * A field's value: what was written here, or what is stored.
	 *
	 * @param int|string $object_id The object.
	 * @param Field      $field     The field.
	 *
	 * @return mixed
	 */
	#[\Override]
	public function read( int|string $object_id, Field $field ): mixed {
		$key = $field->key();

		if ( isset( $this->written[ $key ] ) ) {
			return $this->written[ $key ][1];
		}

		if ( isset( $this->deleted[ $key ] ) ) {
			return null;
		}

		return $this->store->read( $object_id, $field );
	}

	/**
	 * Remember a value.
	 *
	 * @param int|string $object_id The object.
	 * @param Field      $field     The field.
	 * @param mixed      $value     The sanitized value.
	 *
	 * @return void
	 */
	#[\Override]
	public function write( int|string $object_id, Field $field, mixed $value ): void {
		unset( $this->deleted[ $field->key() ] );

		$this->written[ $field->key() ] = [ $field, $value ];
	}

	/**
	 * Remember a clearing.
	 *
	 * @param int|string $object_id The object.
	 * @param Field      $field     The field.
	 *
	 * @return void
	 */
	#[\Override]
	public function delete( int|string $object_id, Field $field ): void {
		unset( $this->written[ $field->key() ] );

		$this->deleted[ $field->key() ] = $field;
	}

	/**
	 * What the step would store: written values, and null for cleared ones.
	 *
	 * @return array<string, mixed>
	 */
	public function values(): array {
		$values = array_map( static fn( array $entry ): mixed => $entry[1], $this->written );

		foreach ( array_keys( $this->deleted ) as $key ) {
			$values[ $key ] = null;
		}

		return $values;
	}

	/**
	 * Whether the kit touched a field at all.
	 *
	 * A field it left alone — a secret nobody retyped, a locked control — is
	 * neither written nor deleted, and keeps what is stored.
	 *
	 * @param string $key The field.
	 *
	 * @return bool
	 */
	public function touched( string $key ): bool {
		return isset( $this->written[ $key ] ) || isset( $this->deleted[ $key ] );
	}

	/**
	 * Replace values before they are stored.
	 *
	 * For a filter that changed what a step stores: only fields already
	 * written are replaced, since a new key has no field to write through.
	 *
	 * @param array<string, mixed> $values Values by field.
	 *
	 * @return void
	 */
	public function replace( array $values ): void {
		foreach ( $values as $key => $value ) {
			if ( isset( $this->written[ $key ] ) ) {
				$this->written[ $key ][1] = $value;
			}
		}
	}

	/**
	 * Store everything, in one go.
	 *
	 * @return void
	 */
	public function commit(): void {
		foreach ( $this->deleted as $field ) {
			$this->store->delete( 0, $field );
		}

		foreach ( $this->written as [ $field, $value ] ) {
			$this->store->write( 0, $field, $value );
		}

		if ( $this->store instanceof Flushable ) {
			$this->store->save();
		}
	}
}
