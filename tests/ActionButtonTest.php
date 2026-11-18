<?php
/**
 * Action button tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Fields\Actions\Actions;
use SugarCommerce\Fields\Rest\ActionController;
use SugarCommerce\Onboarding\Onboarding;
use WP_REST_Request;

/**
 * A button on a step that does something and reports back.
 *
 * The button is the kit's `action_button`, run over the kit's own REST route.
 * What this library owes it is the part the kit cannot know: the request that
 * presses the button is a REST request, where no wizard screen is drawn, so
 * the wizard has to declare every step's handlers on `rest_api_init` or the
 * press comes back "Unknown action". And whoever may run the wizard may press
 * its buttons, not only administrators.
 */
final class ActionButtonTest extends OnboardingTestCase {

	/**
	 * A wizard with a Test Connection button on its second step.
	 *
	 * @param array<string, mixed> $button Overrides for the button.
	 * @param string               $id     The wizard.
	 * @param array<string, mixed> $config Overrides for the wizard.
	 *
	 * @return \SugarCommerce\Onboarding\Wizard
	 */
	private function with_button( array $button = [], string $id = 'myplugin', array $config = [] ): \SugarCommerce\Onboarding\Wizard {
		return $this->wizard(
			array_merge(
				[
					'steps' => [
						'welcome' => [ 'title' => 'Welcome' ],
						'connect' => [
							'title'  => 'Connect',
							'fields' => [
								'api_key' => [
									'type'  => 'text',
									'label' => 'Key',
								],
								'test'    => array_merge(
									[
										'type'            => 'action_button',
										'label'           => 'Test',
										'action_callback' => static fn( array $payload ): array => [
											'success' => 'good' === ( $payload['api_key'] ?? '' ),
											'message' => 'good' === ( $payload['api_key'] ?? '' ) ? 'Connected.' : 'That key did not work.',
										],
									],
									$button
								),
							],
						],
					],
				],
				$config
			),
			$id
		);
	}

	/**
	 * The registered name of a wizard's button.
	 *
	 * @param string $id The wizard.
	 *
	 * @return string
	 */
	private static function handler( string $id = 'myplugin' ): string {
		return sanitize_key( 'onboarding_' . str_replace( '-', '_', $id ) . '_test_run' );
	}

	/**
	 * The handler exists on the REST request, before any screen is drawn.
	 */
	public function test_the_handler_exists_on_a_rest_request(): void {
		$this->with_button();

		$this->assertFalse( Actions::shared()->has( self::handler() ), 'Registering the wizard alone declares nothing.' );

		do_action( 'rest_api_init' );

		$this->assertTrue( Actions::shared()->has( self::handler() ) );
	}

	/**
	 * A step that does not apply yet still answers its buttons: whether it
	 * applies can depend on an answer saved a moment ago.
	 */
	public function test_a_step_that_does_not_apply_still_answers(): void {
		$this->with_button(
			[],
			'later',
			[
				'steps' => [
					'connect' => [
						'title'   => 'Connect',
						'show_if' => static fn(): bool => false,
						'fields'  => [
							'test' => [
								'type'            => 'action_button',
								'label'           => 'Test',
								'action_callback' => static fn(): string => 'ok',
							],
						],
					],
				],
			]
		);

		Onboarding::register_endpoints();

		$this->assertTrue( Actions::shared()->has( self::handler( 'later' ) ) );
	}

	/**
	 * The button may be pressed by whoever may run the wizard.
	 */
	public function test_the_button_takes_the_wizards_capability(): void {
		$this->with_button( [], 'managers', [ 'capability' => 'manage_shop' ] );

		Onboarding::register_endpoints();

		$this->assertSame( 'manage_shop', Actions::shared()->get( self::handler( 'managers' ) )->capability() );
	}

	/**
	 * Unless the button says otherwise.
	 */
	public function test_a_button_can_name_its_own_capability(): void {
		$this->with_button( [ 'action_capability' => 'install_plugins' ], 'own' );

		Onboarding::register_endpoints();

		$this->assertSame( 'install_plugins', Actions::shared()->get( self::handler( 'own' ) )->capability() );
	}

	/**
	 * Two wizards with a button of the same key do not share a handler.
	 */
	public function test_two_wizards_do_not_share_a_handler(): void {
		$this->with_button( [ 'action_callback' => static fn(): string => 'first' ], 'first' );
		$this->with_button( [ 'action_callback' => static fn(): string => 'second' ], 'second' );

		Onboarding::register_endpoints();

		$this->assertSame( 'first', Actions::shared()->get( self::handler( 'first' ) )->handle( [] )['message'] );
		$this->assertSame( 'second', Actions::shared()->get( self::handler( 'second' ) )->handle( [] )['message'] );
	}

	/**
	 * Pressed through the kit's route, the handler reports back, and is
	 * refused to somebody who may not run the wizard.
	 */
	public function test_pressing_the_button(): void {
		$this->with_button( [], 'pressed', [ 'capability' => 'manage_shop' ] );

		Onboarding::register_endpoints();

		$controller = new ActionController();
		$request    = new WP_REST_Request(
			[
				'action'  => self::handler( 'pressed' ),
				'payload' => [ 'api_key' => 'bad' ],
			]
		);

		$GLOBALS['ob_caps'] = [ 'manage_shop' ];

		$this->assertTrue( $controller->check_permission( $request ) );
		$this->assertSame(
			[
				'success' => false,
				'message' => 'That key did not work.',
				'data'    => [],
			],
			$controller->run( $request )->get_data()
		);

		$GLOBALS['ob_caps'] = [];

		$this->assertNotTrue( $controller->check_permission( $request ) );
	}

	/**
	 * The button is drawn with the name the handler was registered under.
	 */
	public function test_the_button_names_its_handler(): void {
		$wizard = $this->with_button();

		$this->submit( $wizard, 'welcome' );

		$this->assertStringContainsString( 'data-action="' . self::handler() . '"', $this->render( $wizard, [ 'step' => 'connect' ] ) );
	}

	/**
	 * A button stores nothing, so a step with one passes and stores the rest.
	 */
	public function test_a_button_stores_nothing(): void {
		$wizard = $this->with_button( [], 'stored', [ 'option' => 'stored_settings' ] );

		$this->submit( $wizard, 'welcome' );
		$this->submit( $wizard, 'connect', [ 'api_key' => 'good' ] );

		$this->assertSame( [ 'api_key' => 'good' ], $GLOBALS['ob_options']['stored_settings'] );
	}
}
