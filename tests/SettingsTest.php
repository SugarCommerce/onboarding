<?php
/**
 * A wizard that asks for a settings page's own settings.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Onboarding\Onboarding;
use SugarCommerce\Plugin\Assets;
use SugarCommerce\Settings\Page;

/**
 * `'settings' => 'shop'`: the wizard is a friendlier way into the same
 * values the settings page edits, not a second copy of them.
 */
final class SettingsTest extends OnboardingTestCase {

	protected function setUp(): void {
		parent::setUp();

		foreach ( array_keys( Page::all() ) as $id ) {
			Page::unregister( (string) $id );
		}

		Page::register(
			'shop',
			[
				'tabs'   => [
					'general'  => 'General',
					'payments' => 'Payments',
				],
				'fields' => [
					'api_key'  => [
						'type'      => 'password',
						'label'     => 'API key',
						'encrypted' => true,
						'tab'       => 'payments',
						'section'   => 'connect',
						'width'     => 'half',
					],
					'currency' => [
						'type'    => 'select',
						'label'   => 'Currency',
						'options' => [
							'GBP' => 'Pounds',
							'EUR' => 'Euros',
						],
						'default' => 'GBP',
					],
				],
			]
		);
	}

	protected function tearDown(): void {
		foreach ( array_keys( Page::all() ) as $id ) {
			Page::unregister( (string) $id );
		}

		parent::tearDown();
	}

	/**
	 * The steps: the page's fields by key, one of the wizard's own, and one
	 * the page does not have.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function steps(): array {
		return [
			'payments' => [
				'title'  => 'Payments',
				'fields' => [
					'api_key'  => [ 'required' => true ],
					'currency' => [],
					'nickname' => [
						'type'  => 'text',
						'label' => 'What to call it',
					],
					'missing'  => [],
				],
			],
			'done'     => [ 'title' => 'Done' ],
		];
	}

	/**
	 * A field with no type is the page's, with the step's keys over it and
	 * where it sat on the page dropped.
	 */
	public function test_a_field_with_no_type_is_the_pages(): void {
		$fields = $this->wizard( [ 'settings' => 'shop' ] )->all_steps()['payments']['fields'];

		$this->assertSame( 'password', $fields['api_key']['type'] );
		$this->assertTrue( $fields['api_key']['encrypted'] );
		$this->assertTrue( $fields['api_key']['required'] );
		$this->assertArrayNotHasKey( 'tab', $fields['api_key'] );
		$this->assertArrayNotHasKey( 'section', $fields['api_key'] );
		$this->assertArrayNotHasKey( 'width', $fields['api_key'] );

		$this->assertSame( [ 'GBP' => 'Pounds', 'EUR' => 'Euros' ], $fields['currency']['options'] );
		$this->assertSame( 'text', $fields['nickname']['type'], 'A field with a type is the wizard\'s own.' );
		$this->assertArrayNotHasKey( 'missing', $fields, 'A setting the page does not have is not drawn as a box nobody configured.' );
	}

	/**
	 * With no settings page named, nothing is looked up.
	 */
	public function test_without_settings_the_fields_are_as_given(): void {
		$fields = $this->wizard()->all_steps()['payments']['fields'];

		$this->assertSame( [ 'required' => true ], $fields['api_key'] );
		$this->assertArrayHasKey( 'missing', $fields );
	}

	/**
	 * An answer goes where the page keeps it, encrypted as the page does,
	 * and comes back through the page's reader.
	 */
	public function test_answers_are_stored_through_the_page(): void {
		$wizard = $this->wizard( [ 'settings' => 'shop' ] );

		$this->assertNotNull(
			$this->submit(
				$wizard,
				'payments',
				[
					'api_key'  => 'key-1234567',
					'currency' => 'EUR',
					'nickname' => 'Main',
				]
			)
		);

		$stored = $GLOBALS['ob_options']['shop'];

		$this->assertStringStartsWith( 'fkenc:', (string) $stored['api_key'] );
		$this->assertSame( 'EUR', $stored['currency'] );
		$this->assertSame( 'key-1234567', Page::options( 'shop' )->get( 'api_key' ) );
		$this->assertSame( 'key-1234567', $wizard->value( 'api_key' ) );
	}

	/**
	 * A pair of callbacks wins over the page, for a plugin whose own reader
	 * fires its own hooks.
	 */
	public function test_callbacks_win_over_the_page(): void {
		$written = [];

		$wizard = $this->wizard(
			[
				'settings'        => 'shop',
				'get_callback'    => static fn( string $key ) => null,
				'update_callback' => static function ( string $key, $value ) use ( &$written ): void {
					$written[ $key ] = $value;
				},
			]
		);

		$this->submit( $wizard, 'payments', [ 'api_key' => 'key-1234567' ] );

		$this->assertSame( 'key-1234567', $written['api_key'] ?? null );
		$this->assertArrayNotHasKey( 'shop', $GLOBALS['ob_options'] );
	}

	/**
	 * An import step with no import to run does not hold Continue back.
	 */
	public function test_a_sync_step_with_nothing_to_run_does_not_wait(): void {
		$wizard = Onboarding::register(
			'importer',
			[
				'steps' => [
					'import' => [
						'title'   => 'Import',
						'type'    => 'sync',
						'sync_id' => 'products',
					],
					'done'   => [ 'title' => 'Done' ],
				],
			]
		);

		$html = $this->render( $wizard );

		$this->assertStringContainsString( 'data-sync="0"', $html );
		$this->assertStringNotContainsString( 'onboarding__sync', $html );
	}

	/**
	 * The card printed where it is wanted.
	 */
	public function test_a_checklist_prints_its_card(): void {
		$checklist = $this->checklist( [ 'tasks' => [ 'one' => [ 'title' => 'One' ] ] ] );

		ob_start();
		$checklist->render( [ 'layout' => 'compact' ] );
		$html = (string) ob_get_clean();

		$this->assertSame( $checklist->html( [ 'layout' => 'compact' ] ), $html );
		$this->assertStringContainsString( 'onboarding-checklist--compact', $html );
	}

	/**
	 * The stylesheet's address is worked out from where the copy sits.
	 */
	public function test_an_assets_address_follows_the_copy(): void {
		$wizard = dirname( __DIR__ ) . '/src/Wizard.php';

		$this->assertStringEndsWith( '/sugarcommerce/onboarding/assets/css/onboarding.css', (string) Assets::url( $wizard, 'css/onboarding.css' ) );
		$this->assertStringStartsWith( content_url(), (string) Assets::url( $wizard, 'css/onboarding.css' ) );
		$this->assertNull( Assets::url( $wizard, 'css/nothing.css' ) );
	}
}
