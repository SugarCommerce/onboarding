<?php
/**
 * Storage tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Onboarding\Support\Draft;
use SugarCommerce\Onboarding\Support\State;

/**
 * Where answers and progress go.
 *
 * A wizard writes settings it does not own the storage for, into whatever
 * the plugin's settings screen already reads: one option holding everything,
 * an option per field, the plugin's own pair of callbacks, or a field-kit
 * context of the plugin's choosing. Progress goes somewhere else entirely,
 * so resetting it leaves every answer in place.
 */
final class StorageTest extends OnboardingTestCase {

	/**
	 * A wizard with one question of two fields.
	 *
	 * @param array<string, mixed> $config Overrides.
	 *
	 * @return \SugarCommerce\Onboarding\Wizard
	 */
	private function storing( array $config = [] ): \SugarCommerce\Onboarding\Wizard {
		return $this->wizard(
			array_merge(
				[
					'steps' => [
						'store' => [
							'title'  => 'Store',
							'fields' => [
								'store_name' => [
									'type'  => 'text',
									'label' => 'Name',
								],
								'currency'   => [
									'type'   => 'text',
									'label'  => 'Currency',
									'option' => 'myplugin_currency_code',
								],
							],
						],
						'done'  => [ 'title' => 'Done' ],
					],
				],
				$config
			)
		);
	}

	/**
	 * One option holding every answer.
	 */
	public function test_one_option_holds_everything(): void {
		$this->submit(
			$this->storing( [ 'option' => 'myplugin_settings' ] ),
			'store',
			[
				'store_name' => 'Acme',
				'currency'   => 'GBP',
			]
		);

		$this->assertSame(
			[
				'store_name' => 'Acme',
				'currency'   => 'GBP',
			],
			$GLOBALS['ob_options']['myplugin_settings']
		);
	}

	/**
	 * With nothing said, an option per field, named by the field or by its own `option`.
	 */
	public function test_an_option_per_field(): void {
		$this->submit(
			$this->storing(),
			'store',
			[
				'store_name' => 'Acme',
				'currency'   => 'GBP',
			]
		);

		$this->assertSame( 'Acme', $GLOBALS['ob_options']['store_name'] );
		$this->assertSame( 'GBP', $GLOBALS['ob_options']['myplugin_currency_code'] );
	}

	/**
	 * The plugin's own pair of callbacks.
	 */
	public function test_the_plugins_own_callbacks(): void {
		$store = [ 'store_name' => 'Before' ];

		$wizard = $this->storing(
			[
				'get_callback'    => static function ( string $key ) use ( &$store ) {
					return $store[ $key ] ?? null;
				},
				'update_callback' => static function ( string $key, $value ) use ( &$store ): void {
					$store[ $key ] = $value;
				},
			]
		);

		$this->assertSame( 'Before', $wizard->value( 'store_name' ) );

		$this->submit( $wizard, 'store', [ 'store_name' => 'After' ] );

		$this->assertSame( 'After', $store['store_name'] );
		$this->assertArrayHasKey( 'myplugin_currency_code', $store, 'A cleared field is written as null through the callback.' );
		$this->assertNull( $store['myplugin_currency_code'] );
	}

	/**
	 * Any field-kit context.
	 */
	public function test_a_context_of_the_plugins_choosing(): void {
		$context = new ArrayContext();

		$this->submit( $this->storing( [ 'context' => $context ] ), 'store', [ 'store_name' => 'Acme' ] );

		$this->assertSame( 'Acme', $context->values()['store_name'] );
		$this->assertArrayNotHasKey( 'store_name', $GLOBALS['ob_options'] );
	}

	/**
	 * A field hidden by its condition is cleared, not kept.
	 */
	public function test_a_hidden_field_is_cleared(): void {
		$GLOBALS['ob_options']['myplugin_settings'] = [ 'vat' => 'GB123' ];

		$wizard = $this->wizard(
			[
				'option' => 'myplugin_settings',
				'steps'  => [
					'tax'  => [
						'title'  => 'Tax',
						'fields' => [
							'registered' => [
								'type'  => 'toggle',
								'label' => 'Registered',
							],
							'vat'        => [
								'type'      => 'text',
								'label'     => 'VAT',
								'show_when' => [ 'registered' => 1 ],
							],
						],
					],
					'done' => [ 'title' => 'Done' ],
				],
			]
		);

		$this->submit( $wizard, 'tax', [ 'vat' => 'GB999' ] );

		$this->assertArrayNotHasKey( 'vat', $GLOBALS['ob_options']['myplugin_settings'] );
	}

	/**
	 * Progress is its own option, which a reset deletes and nothing else.
	 */
	public function test_progress_is_kept_apart(): void {
		$wizard = $this->storing(
			[
				'option'       => 'myplugin_settings',
				'state_option' => 'myplugin_progress',
			]
		);

		$this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] );

		$this->assertSame( [ 'store' ], $GLOBALS['ob_options']['myplugin_progress']['done'] );

		$wizard->reset();

		$this->assertArrayNotHasKey( 'myplugin_progress', $GLOBALS['ob_options'] );
		$this->assertSame( 'Acme', $GLOBALS['ob_options']['myplugin_settings']['store_name'] );
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * A fresh state has every key, empty.
	 */
	public function test_a_fresh_state(): void {
		$GLOBALS['ob_options']['broken'] = 'not an array';

		$this->assertSame(
			[
				'started'   => 0,
				'updated'   => 0,
				'current'   => '',
				'done'      => [],
				'skipped'   => [],
				'completed' => 0,
			],
			( new State( 'broken' ) )->get()
		);
	}

	/**
	 * An answer replaces a skip, a skip does not replace an answer, and
	 * neither is listed twice.
	 */
	public function test_done_and_skipped(): void {
		$state = new State( 'progress' );

		$state->mark_skipped( 'a' );
		$state->mark_skipped( 'a' );
		$this->assertSame( [ 'a' ], $state->get()['skipped'] );

		$state->mark_done( 'a' );
		$state->mark_done( 'a' );
		$this->assertSame( [ 'a' ], $state->get()['done'] );
		$this->assertSame( [], $state->get()['skipped'] );

		$state->mark_skipped( 'a' );
		$this->assertSame( [], $state->get()['skipped'] );
		$this->assertTrue( $state->is_done( 'a' ) );
		$this->assertFalse( $state->is_skipped( 'a' ) );
	}

	/**
	 * The state is stamped when it starts and whenever it changes.
	 */
	public function test_the_state_is_stamped(): void {
		$state = new State( 'progress' );

		$state->set_current( 'b' );

		$this->assertGreaterThan( 0, $state->get()['started'] );
		$this->assertGreaterThanOrEqual( $state->get()['started'], $state->get()['updated'] );

		$state->complete();

		$this->assertGreaterThan( 0, $state->get()['completed'] );
		$this->assertSame( '', $state->get()['current'] );
	}

	/* ---------------------------------------------------------------------
	 * Draft
	 * ------------------------------------------------------------------ */

	/**
	 * The draft reads through to the store until it is written, and writes
	 * nothing until committed.
	 */
	public function test_the_draft_holds_back_until_committed(): void {
		$store = new ArrayContext(
			[
				'kept'    => 'stored',
				'cleared' => 'stored',
			]
		);
		$draft = new Draft( $store );
		$set   = new FieldSet(
			[
				'kept'    => [ 'type' => 'text' ],
				'changed' => [ 'type' => 'text' ],
				'cleared' => [ 'type' => 'text' ],
			],
			$draft
		);

		$kept    = $set->field( 'kept' );
		$changed = $set->field( 'changed' );
		$cleared = $set->field( 'cleared' );

		$draft->write( 0, $changed, 'new' );
		$draft->delete( 0, $cleared );

		$this->assertSame( 'stored', $draft->read( 0, $kept ) );
		$this->assertSame( 'new', $draft->read( 0, $changed ) );
		$this->assertNull( $draft->read( 0, $cleared ) );
		$this->assertFalse( $draft->touched( 'kept' ) );
		$this->assertTrue( $draft->touched( 'cleared' ) );
		$this->assertSame(
			[
				'changed' => 'new',
				'cleared' => null,
			],
			$draft->values()
		);
		$this->assertSame( 'stored', $store->values()['cleared'], 'Nothing reaches the store before commit.' );

		$draft->replace(
			[
				'changed' => 'replaced',
				'kept'    => 'not written, so not replaced',
			]
		);
		$draft->commit();

		$this->assertSame(
			[
				'kept'    => 'stored',
				'changed' => 'replaced',
			],
			$store->values()
		);
	}

	/**
	 * A write after a delete, and a delete after a write, are the later one.
	 */
	public function test_the_last_instruction_wins(): void {
		$draft = new Draft( new ArrayContext( [ 'a' => 'stored' ] ) );
		$field = ( new FieldSet( [ 'a' => [ 'type' => 'text' ] ], $draft ) )->field( 'a' );

		$draft->delete( 0, $field );
		$draft->write( 0, $field, 'again' );
		$this->assertSame( [ 'a' => 'again' ], $draft->values() );

		$draft->delete( 0, $field );
		$this->assertSame( [ 'a' => null ], $draft->values() );
	}
}
