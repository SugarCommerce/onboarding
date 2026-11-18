<?php
/**
 * REST route tests.
 *
 * @package SugarCommerce\Onboarding
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Tests;

use SugarCommerce\Onboarding\Onboarding;
use SugarCommerce\Onboarding\Rest\Controller;
use SugarCommerce\Onboarding\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The routes behind the checklist card, and the read of a wizard's progress.
 *
 * Each route is checked the way core runs it: the permission callback first,
 * and the handler only if that said yes. What matters is the order of the
 * refusals — signed out before anything is looked up, so an anonymous request
 * cannot learn which checklists exist; then unknown; then not allowed.
 */
final class RestTest extends OnboardingTestCase {

	/**
	 * The controller.
	 *
	 * @var Controller
	 */
	private Controller $controller;

	/**
	 * Register a checklist and the routes.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->checklist(
			[
				'tasks' => [
					'product' => [
						'title' => 'Add a product',
						'done'  => static fn(): bool => false,
					],
					'read'    => [ 'title' => 'Read the guide' ],
				],
			]
		);

		$this->controller = new Controller();
		$this->controller->register_routes();
	}

	/**
	 * Run a route as core would: permission first.
	 *
	 * @param string               $permission The permission method.
	 * @param string               $handler    The handler method.
	 * @param array<string, mixed> $params     The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function call( string $permission, string $handler, array $params ): WP_REST_Response|WP_Error {
		$request = new WP_REST_Request( $params );
		$allowed = $this->controller->$permission( $request );

		if ( true !== $allowed ) {
			return $allowed;
		}

		return $this->controller->$handler( $request );
	}

	/**
	 * The status of a refusal.
	 *
	 * @param mixed $result What came back.
	 *
	 * @return int
	 */
	private static function http_status( mixed $result ): int {
		return $result instanceof WP_Error ? (int) $result->get_error_data()['status'] : $result->get_status();
	}

	/**
	 * The routes are under this build's own namespace, once.
	 */
	public function test_the_routes_are_under_this_builds_namespace(): void {
		$namespace = Runtime::rest_namespace();

		$this->assertSame( 'sugarcommerce-onboarding/v1', $namespace );
		$this->assertSame(
			[
				$namespace . '/checklists/(?P<id>[a-z0-9_-]+)',
				$namespace . '/checklists/(?P<id>[a-z0-9_-]+)/dismiss',
				$namespace . '/checklists/(?P<id>[a-z0-9_-]+)/tasks/(?P<task>[a-z0-9_-]+)',
				$namespace . '/checklists/(?P<id>[a-z0-9_-]+)/expanded',
				$namespace . '/wizards/(?P<id>[a-z0-9_-]+)',
			],
			array_keys( $GLOBALS['ob_routes'] )
		);

		$GLOBALS['ob_routes'] = [];
		( new Controller() )->register_routes();

		$this->assertSame( [], $GLOBALS['ob_routes'], 'A second controller does not register the same routes again.' );
	}

	/**
	 * Every route has a permission callback, and none is open.
	 */
	public function test_no_route_is_open(): void {
		foreach ( $GLOBALS['ob_routes'] as $route => $args ) {
			$this->assertIsCallable( $args['permission_callback'] ?? null, "{$route} has no permission check." );
			$this->assertNotSame( '__return_true', $args['permission_callback'], "{$route} is open." );
		}
	}

	/**
	 * Signed out is refused before anything is looked up.
	 */
	public function test_signed_out_is_refused_first(): void {
		$GLOBALS['ob_user_id'] = 0;

		$known   = $this->call( 'can_use_checklist', 'read_checklist', [ 'id' => 'myguide' ] );
		$unknown = $this->call( 'can_use_checklist', 'read_checklist', [ 'id' => 'nothing' ] );

		$this->assertSame( 401, self::http_status( $known ) );
		$this->assertSame( 401, self::http_status( $unknown ), 'An anonymous request learns nothing about which checklists exist.' );
	}

	/**
	 * An unknown checklist, task or wizard is a 404.
	 */
	public function test_unknown_things_are_not_found(): void {
		$this->assertSame( 404, self::http_status( $this->call( 'can_use_checklist', 'read_checklist', [ 'id' => 'nothing' ] ) ) );
		$this->assertSame( 404, self::http_status( $this->call( 'can_use_wizard', 'read_wizard', [ 'id' => 'nothing' ] ) ) );
		$this->assertSame(
			404,
			self::http_status(
				$this->call(
					'can_use_checklist',
					'mark',
					[
						'id'   => 'myguide',
						'task' => 'nothing',
						'done' => true,
					]
				)
			)
		);
	}

	/**
	 * Somebody without the checklist's capability is refused.
	 */
	public function test_the_capability_is_checked(): void {
		$GLOBALS['ob_caps'] = [];

		$this->assertSame( 403, self::http_status( $this->call( 'can_use_checklist', 'dismiss', [ 'id' => 'myguide' ] ) ) );
		$this->assertFalse( Onboarding::checklist( 'myguide' )->is_dismissed() );
	}

	/**
	 * Reading a checklist answers its status and whether it is dismissed.
	 */
	public function test_reading_a_checklist(): void {
		$data = $this->call( 'can_use_checklist', 'read_checklist', [ 'id' => 'myguide' ] )->get_data();

		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 0, $data['done'] );
		$this->assertFalse( $data['dismissed'] );
	}

	/**
	 * Dismissing stores it for this person and answers with what replaces the card.
	 */
	public function test_dismissing_and_bringing_back(): void {
		$response = $this->call(
			'can_use_checklist',
			'dismiss',
			[
				'id'        => 'myguide',
				'dismissed' => true,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['dismissed'] );
		$this->assertStringContainsString( 'data-checklist-restore', $response->get_data()['html'] );
		$this->assertTrue( Onboarding::checklist( 'myguide' )->is_dismissed() );

		$response = $this->call(
			'can_use_checklist',
			'dismiss',
			[
				'id'        => 'myguide',
				'dismissed' => false,
				'context'   => 'widget',
			]
		);

		$this->assertFalse( Onboarding::checklist( 'myguide' )->is_dismissed() );
		$this->assertStringContainsString( 'onboarding-checklist--widget', $response->get_data()['html'], 'The card comes back drawn the way it was.' );
	}

	/**
	 * A compact card dismissed and brought back is compact again: the line
	 * that replaces it remembers how it was drawn.
	 */
	public function test_a_compact_card_comes_back_compact(): void {
		$response = $this->call(
			'can_use_checklist',
			'dismiss',
			[
				'id'        => 'myguide',
				'dismissed' => true,
				'layout'    => 'compact',
			]
		);

		$this->assertStringContainsString( 'data-layout="compact"', $response->get_data()['html'] );

		$response = $this->call(
			'can_use_checklist',
			'dismiss',
			[
				'id'        => 'myguide',
				'dismissed' => false,
				'layout'    => 'compact',
			]
		);

		$this->assertStringContainsString( 'onboarding-checklist--compact', $response->get_data()['html'] );

		$response = $this->call(
			'can_use_checklist',
			'mark',
			[
				'id'     => 'myguide',
				'task'   => 'read',
				'done'   => true,
				'layout' => 'compact',
			]
		);

		$this->assertStringContainsString( 'onboarding-checklist--compact', $response->get_data()['html'], 'Ticking a task keeps the layout too.' );
	}

	/**
	 * Opening the compact card's list is remembered for this person, and
	 * answers with no markup to swap in.
	 */
	public function test_keeping_the_list_open(): void {
		$response = $this->call(
			'can_use_checklist',
			'expand',
			[
				'id'       => 'myguide',
				'expanded' => true,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['expanded'] );
		$this->assertArrayNotHasKey( 'html', $response->get_data() );
		$this->assertTrue( Onboarding::checklist( 'myguide' )->is_expanded() );

		$this->call(
			'can_use_checklist',
			'expand',
			[
				'id'       => 'myguide',
				'expanded' => false,
			]
		);

		$this->assertFalse( Onboarding::checklist( 'myguide' )->is_expanded() );

		$GLOBALS['ob_caps'] = [];

		$this->assertSame( 403, self::http_status( $this->call( 'can_use_checklist', 'expand', [ 'id' => 'myguide' ] ) ) );
	}

	/**
	 * A checklist that cannot be dismissed says so.
	 */
	public function test_a_checklist_that_cannot_be_dismissed(): void {
		$this->checklist( [ 'dismissible' => false ], 'fixed' );

		$this->assertSame(
			400,
			self::http_status(
				$this->call(
					'can_use_checklist',
					'dismiss',
					[
						'id'        => 'fixed',
						'dismissed' => true,
					]
				)
			)
		);
	}

	/**
	 * A task ticked by hand is ticked, and the new card comes back.
	 */
	public function test_ticking_a_task(): void {
		$response = $this->call(
			'can_use_checklist',
			'mark',
			[
				'id'   => 'myguide',
				'task' => 'read',
				'done' => true,
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['status']['done'] );
		$this->assertStringContainsString( '1 of 2 tasks complete', $response->get_data()['html'] );
		$this->assertTrue( Onboarding::checklist( 'myguide' )->is_done( 'read' ) );
	}

	/**
	 * A task that is worked out cannot be ticked from here.
	 */
	public function test_a_worked_out_task_cannot_be_ticked(): void {
		$result = $this->call(
			'can_use_checklist',
			'mark',
			[
				'id'   => 'myguide',
				'task' => 'product',
				'done' => true,
			]
		);

		$this->assertSame( 400, self::http_status( $result ) );
		$this->assertFalse( Onboarding::checklist( 'myguide' )->is_done( 'product' ) );
	}

	/**
	 * Reading a wizard answers its progress, for whoever may run it.
	 */
	public function test_reading_a_wizard(): void {
		$this->wizard();

		$data = $this->call( 'can_use_wizard', 'read_wizard', [ 'id' => 'myplugin' ] )->get_data();

		$this->assertSame( 'myplugin', $data['id'] );
		$this->assertSame( 3, $data['total'] );

		$this->wizard( [ 'capability' => 'install_plugins' ], 'locked' );

		$this->assertSame( 403, self::http_status( $this->call( 'can_use_wizard', 'read_wizard', [ 'id' => 'locked' ] ) ) );
	}

	/**
	 * The routes are registered on rest_api_init, with every wizard's fields.
	 */
	public function test_the_routes_are_registered_on_rest_api_init(): void {
		$GLOBALS['ob_routes'] = [];
		ob_reset_globals();

		$this->wizard();

		do_action( 'rest_api_init' );

		$this->assertArrayHasKey( Runtime::rest_namespace() . '/wizards/(?P<id>[a-z0-9_-]+)', $GLOBALS['ob_routes'] );
		$this->assertNotNull( Onboarding::get( 'myplugin' ) );
	}
}
