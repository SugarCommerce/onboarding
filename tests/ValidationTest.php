<?php
/**
 * Step validation tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Onboarding\Wizard;
use WP_Error;

/**
 * Whether a step may move on.
 *
 * A step is one question, so it passes or fails whole: the kit refuses a
 * field that is required and empty or that breaks its own rule, the step's
 * `validate` sees every answer together, a filter may add to either, and
 * only when none of them object is anything stored. Every message lands
 * either beside the field it is about or above the step.
 *
 * Each test names one of those decisions, so a change that breaks one fails
 * one test with its name on it — which is also what makes the suite worth
 * running against mutations of Wizard::check() and Wizard::attempt().
 */
final class ValidationTest extends OnboardingTestCase {

	/**
	 * A wizard whose one question has two fields and the given check.
	 *
	 * @param mixed                $validate The step's `validate`.
	 * @param array<string, mixed> $fields   Field overrides.
	 * @param array<string, mixed> $step     Step overrides.
	 *
	 * @return Wizard
	 */
	private function asking( mixed $validate = null, array $fields = [], array $step = [] ): Wizard {
		return $this->wizard(
			[
				'option' => 'myplugin_settings',
				'steps'  => [
					'store' => array_merge(
						[
							'title'    => 'Your store',
							'fields'   => array_merge(
								[
									'store_name' => [
										'type'  => 'text',
										'label' => 'Store name',
									],
									'email'      => [
										'type'  => 'email',
										'label' => 'Email',
									],
								],
								$fields
							),
							'validate' => $validate,
						],
						$step
					),
					'done'  => [ 'title' => 'Done' ],
				],
			]
		);
	}

	/**
	 * What is stored, if anything.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return (array) ( $GLOBALS['ob_options']['myplugin_settings'] ?? [] );
	}

	/* ---------------------------------------------------------------------
	 * Passing
	 * ------------------------------------------------------------------ */

	/**
	 * With no check, a step passes and its answers are stored.
	 */
	public function test_a_step_with_no_check_passes(): void {
		$wizard = $this->asking();

		$this->assertNotNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] ) );
		$this->assertSame( [], $wizard->errors() );
		$this->assertSame( 'Acme', $this->stored()['store_name'] );
	}

	/**
	 * True, null and an empty array all mean "fine".
	 *
	 * @param mixed $answer What the check returns.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'passingProvider' )]
	public function test_a_check_that_says_yes_passes( mixed $answer ): void {
		$wizard = $this->asking( static fn() => $answer );

		$this->assertNotNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] ) );
		$this->assertSame( 'Acme', $this->stored()['store_name'] );
	}

	/**
	 * What counts as yes.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function passingProvider(): array {
		return [
			'true'               => [ true ],
			'null'               => [ null ],
			'empty array'        => [ [] ],
			'empty string'       => [ '' ],
			'array of empties'   => [ [ 'store_name' => '' ] ],
			'an unrelated value' => [ 42 ],
		];
	}

	/* ---------------------------------------------------------------------
	 * Refusing
	 * ------------------------------------------------------------------ */

	/**
	 * False refuses the step with a general message.
	 */
	public function test_false_refuses_the_step(): void {
		$wizard = $this->asking( static fn(): bool => false );

		$this->assertNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] ) );
		$this->assertSame( [ Wizard::STEP_ERROR => 'Please check this step before continuing.' ], $wizard->errors() );
		$this->assertSame( [], $this->stored() );
		$this->assertFalse( $wizard->state()->is_done( 'store' ) );
	}

	/**
	 * A string refuses the step with that message.
	 */
	public function test_a_string_refuses_the_step_with_it(): void {
		$wizard = $this->asking( static fn(): string => 'Not today.' );

		$this->assertNull( $this->submit( $wizard, 'store' ) );
		$this->assertSame( [ Wizard::STEP_ERROR => 'Not today.' ], $wizard->errors() );
	}

	/**
	 * A WP_Error whose code is a field puts the message beside that field.
	 */
	public function test_an_error_coded_for_a_field_goes_beside_it(): void {
		$wizard = $this->asking( static fn() => new WP_Error( 'email', 'That address bounced.' ) );

		$this->assertNull( $this->submit( $wizard, 'store', [ 'email' => 'a@example.com' ] ) );
		$this->assertSame( [ 'email' => 'That address bounced.' ], $wizard->errors() );
	}

	/**
	 * A WP_Error whose code is not a field is about the step.
	 */
	public function test_an_error_coded_for_nothing_is_about_the_step(): void {
		$wizard = $this->asking( static fn() => new WP_Error( 'http_failure', 'The service is down.' ) );

		$this->assertNull( $this->submit( $wizard, 'store' ) );
		$this->assertSame( [ Wizard::STEP_ERROR => 'The service is down.' ], $wizard->errors() );
	}

	/**
	 * A WP_Error with several codes and messages says all of them, each in its place.
	 */
	public function test_an_error_with_several_codes_says_each(): void {
		$wizard = $this->asking(
			static function () {
				$error = new WP_Error( 'store_name', 'Too short.' );

				$error->add( 'store_name', 'And taken.' );
				$error->add( 'quota', 'Over quota.' );
				$error->add( 'other', 'Also down.' );

				return $error;
			}
		);

		$this->submit( $wizard, 'store' );

		$this->assertSame(
			[
				'store_name'        => 'Too short. And taken.',
				Wizard::STEP_ERROR => 'Over quota. Also down.',
			],
			$wizard->errors()
		);
	}

	/**
	 * An array of messages, keyed by field or by anything else.
	 */
	public function test_an_array_of_messages(): void {
		$wizard = $this->asking(
			static fn(): array => [
				'email'   => 'Use a work address.',
				'nowhere' => 'Something else.',
				'blank'   => '',
			]
		);

		$this->submit( $wizard, 'store' );

		$this->assertSame(
			[
				'email'             => 'Use a work address.',
				Wizard::STEP_ERROR => 'Something else.',
			],
			$wizard->errors()
		);
	}

	/**
	 * The check is handed every answer, sanitized, and the wizard.
	 */
	public function test_the_check_sees_sanitized_answers_and_the_wizard(): void {
		$seen = null;

		$wizard = $this->asking(
			static function ( array $values, Wizard $wizard ) use ( &$seen ): bool {
				$seen = [ $values, $wizard->id() ];

				return true;
			}
		);

		$this->submit(
			$wizard,
			'store',
			[
				'store_name' => '  <b>Acme</b>  ',
				'email'      => 'help@example.com',
			]
		);

		$this->assertSame( 'Acme', $seen[0]['store_name'] );
		$this->assertSame( 'help@example.com', $seen[0]['email'] );
		$this->assertSame( 'myplugin', $seen[1] );
	}

	/* ---------------------------------------------------------------------
	 * The kit's own refusals
	 * ------------------------------------------------------------------ */

	/**
	 * A required field left empty stops the step, beside the field.
	 */
	public function test_a_required_field_stops_the_step(): void {
		$wizard = $this->asking( null, [ 'store_name' => [ 'type' => 'text', 'label' => 'Store name', 'required' => true ] ] );

		$this->assertNull( $this->submit( $wizard, 'store', [ 'email' => 'a@example.com' ] ) );
		$this->assertSame( [ 'store_name' => 'Store name is required.' ], $wizard->errors() );
		$this->assertSame( [], $this->stored(), 'Nothing is stored while any field is refused, not even the fields that passed.' );
	}

	/**
	 * A field's own rule stops the step too.
	 */
	public function test_a_fields_own_rule_stops_the_step(): void {
		$wizard = $this->asking(
			null,
			[
				'store_name' => [
					'type'     => 'text',
					'label'    => 'Store name',
					'validate' => static fn( $value ) => strlen( (string) $value ) >= 3 ? true : 'Too short.',
				],
			]
		);

		$this->assertNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Ab' ] ) );
		$this->assertSame( [ 'store_name' => 'Too short.' ], $wizard->errors() );
	}

	/**
	 * The step's check runs even when a field was refused, so every problem
	 * shows at once rather than one per submission.
	 */
	public function test_every_problem_shows_at_once(): void {
		$wizard = $this->asking(
			static fn() => new WP_Error( 'email', 'Bounced.' ),
			[ 'store_name' => [ 'type' => 'text', 'label' => 'Store name', 'required' => true ] ]
		);

		$this->submit( $wizard, 'store', [ 'email' => 'a@example.com' ] );

		$this->assertSame( [ 'store_name', 'email' ], array_keys( $wizard->errors() ) );
	}

	/* ---------------------------------------------------------------------
	 * The filter
	 * ------------------------------------------------------------------ */

	/**
	 * A filter can refuse a step its own configuration would pass.
	 */
	public function test_a_filter_can_refuse_a_step(): void {
		$wizard = $this->asking();

		add_filter(
			'sugarcommerce_onboarding_validate_myplugin',
			static fn( array $errors, string $key, array $values ): array => 'Acme' === ( $values['store_name'] ?? '' ) ? [ 'store_name' => 'Taken.' ] : $errors,
			10,
			3
		);

		$this->assertNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] ) );
		$this->assertSame( [ 'store_name' => 'Taken.' ], $wizard->errors() );
		$this->assertNotNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Other' ] ) );
	}

	/**
	 * And can let through what it would refuse.
	 */
	public function test_a_filter_can_pass_a_step(): void {
		$wizard = $this->asking( static fn(): bool => false );

		add_filter( 'sugarcommerce_onboarding_validate_myplugin', static fn(): array => [] );

		$this->assertNotNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] ) );
	}

	/* ---------------------------------------------------------------------
	 * Storing
	 * ------------------------------------------------------------------ */

	/**
	 * What is stored can be changed by a filter on the way in.
	 */
	public function test_the_stored_values_can_be_filtered(): void {
		$wizard = $this->asking();

		add_filter(
			'sugarcommerce_onboarding_values_myplugin',
			static fn( array $values ): array => array_merge( $values, [ 'store_name' => strtoupper( (string) $values['store_name'] ) ] )
		);

		$this->submit( $wizard, 'store', [ 'store_name' => 'acme' ] );

		$this->assertSame( 'ACME', $this->stored()['store_name'] );
	}

	/**
	 * A step's own save replaces storing, and is handed the answers.
	 */
	public function test_a_save_callback_replaces_storing(): void {
		$seen = null;

		$wizard = $this->asking(
			null,
			[],
			[
				'save' => static function ( array $values ) use ( &$seen ): void {
					$seen = $values;
				},
			]
		);

		$this->assertNotNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] ) );
		$this->assertSame( 'Acme', $seen['store_name'] );
		$this->assertSame( [], $this->stored() );
	}

	/**
	 * A save that fails stops the step with what it said.
	 *
	 * @param mixed  $answer   What the save returns.
	 * @param string $expected The message.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'failedSaveProvider' )]
	public function test_a_save_that_fails_stops_the_step( mixed $answer, string $expected ): void {
		$wizard = $this->asking( null, [], [ 'save' => static fn() => $answer ] );

		$this->assertNull( $this->submit( $wizard, 'store', [ 'store_name' => 'Acme' ] ) );
		$this->assertSame( [ Wizard::STEP_ERROR => $expected ], $wizard->errors() );
		$this->assertFalse( $wizard->state()->is_done( 'store' ) );
	}

	/**
	 * Ways a save can fail.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function failedSaveProvider(): array {
		return [
			'WP_Error' => [ new WP_Error( 'x', 'The pages could not be created.' ), 'The pages could not be created.' ],
			'string'   => [ 'No room.', 'No room.' ],
			'false'    => [ false, 'Please check this step before continuing.' ],
		];
	}

	/**
	 * The saved action is told what was stored.
	 */
	public function test_the_saved_action_is_handed_the_answers(): void {
		$seen = null;

		add_action(
			'sugarcommerce_onboarding_step_saved_myplugin',
			static function ( string $key, array $values ) use ( &$seen ): void {
				$seen = [ $key, $values['store_name'] ?? null ];
			},
			10,
			2
		);

		$this->submit( $this->asking(), 'store', [ 'store_name' => 'Acme' ] );

		$this->assertSame( [ 'store', 'Acme' ], $seen );
	}

	/**
	 * A refused step does not fire the saved action.
	 */
	public function test_a_refused_step_is_not_saved(): void {
		$this->submit( $this->asking( static fn(): bool => false ), 'store', [ 'store_name' => 'Acme' ] );

		$this->assertFalse( $this->fired( 'sugarcommerce_onboarding_step_saved_myplugin' ) );
	}

	/* ---------------------------------------------------------------------
	 * Secrets and drawing it again
	 * ------------------------------------------------------------------ */

	/**
	 * A secret left empty means "keep it" — and the check is handed the kept one.
	 */
	public function test_a_kept_secret_is_handed_to_the_check(): void {
		$GLOBALS['ob_options']['myplugin_settings'] = [ 'api_key' => 'rk_test_stored' ];

		$seen   = null;
		$wizard = $this->asking(
			static function ( array $values ) use ( &$seen ): bool {
				$seen = $values['api_key'] ?? null;

				return true;
			},
			[
				'api_key' => [
					'type'     => 'password',
					'label'    => 'Key',
					'required' => true,
				],
			]
		);

		$this->assertNotNull( $this->submit( $wizard, 'store', [ 'api_key' => '' ] ), 'A required secret already stored is not refused for being left empty.' );
		$this->assertSame( 'rk_test_stored', $seen );
		$this->assertSame( 'rk_test_stored', $this->stored()['api_key'] );
	}

	/**
	 * A refused step is drawn with what was typed, not what is stored.
	 */
	public function test_a_refused_step_shows_what_was_typed(): void {
		$GLOBALS['ob_options']['myplugin_settings'] = [ 'store_name' => 'Stored' ];

		$wizard = $this->asking(
			null,
			[
				'store_name' => [
					'type'     => 'text',
					'label'    => 'Store name',
					'validate' => static fn( $value ) => 'Typed!' === $value ? 'No exclamation marks.' : true,
				],
			]
		);

		$this->submit( $wizard, 'store', [ 'store_name' => 'Typed!' ] );

		$html = $this->render( $wizard, [ 'step' => 'store' ] );

		$this->assertStringContainsString( 'value="Typed!"', $html );
		$this->assertStringNotContainsString( 'value="Stored"', $html );
	}

	/**
	 * A field cleared in a refused step stays cleared when drawn again.
	 */
	public function test_a_cleared_field_stays_cleared(): void {
		$GLOBALS['ob_options']['myplugin_settings'] = [ 'email' => 'old@example.com' ];

		$wizard = $this->asking( static fn(): bool => false );

		$this->submit( $wizard, 'store', [ 'email' => '' ] );

		$this->assertStringNotContainsString( 'old@example.com', $this->render( $wizard, [ 'step' => 'store' ] ) );
		$this->assertSame( 'old@example.com', $this->stored()['email'], 'And nothing was stored.' );
	}
}
