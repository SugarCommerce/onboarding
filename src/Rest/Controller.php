<?php
/**
 * REST Controller
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Rest;

use SugarCommerce\Onboarding\Checklist;
use SugarCommerce\Onboarding\ChecklistView;
use SugarCommerce\Onboarding\Onboarding;
use SugarCommerce\Onboarding\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The routes behind the checklist card, and a read of each wizard's progress.
 *
 * Dismissing the card and ticking a task are the card's two actions, and both
 * answer with the card's new markup, so the script swaps one element and
 * never has to know how a card is drawn. Opening the compact card's list is
 * a third, which is only remembered: the list is already open in the page.
 * Reading a checklist or a wizard is for everything else that wants to know
 * whether setup is done — a plugin's own REST API, a command-line tool, an
 * agent.
 *
 * Every route needs a signed-in user with the capability the checklist or the
 * wizard was registered with, and a REST nonce, which is WordPress's own
 * check that a cookie-authenticated request came from its own pages. An
 * anonymous request is refused before anything is looked up, so it cannot
 * learn which checklists exist.
 */
final class Controller {

	/**
	 * Namespaces already registered, so two calls do not register twice.
	 *
	 * @var array<string, bool>
	 */
	private static array $claimed = [];

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$namespace = Runtime::rest_namespace();

		if ( isset( self::$claimed[ $namespace ] ) ) {
			return;
		}

		self::$claimed[ $namespace ] = true;

		register_rest_route(
			$namespace,
			'/checklists/(?P<id>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'read_checklist' ],
				'permission_callback' => [ $this, 'can_use_checklist' ],
			]
		);

		register_rest_route(
			$namespace,
			'/checklists/(?P<id>[a-z0-9_-]+)/dismiss',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'dismiss' ],
				'permission_callback' => [ $this, 'can_use_checklist' ],
				'args'                => [
					'dismissed' => [
						'type'    => 'boolean',
						'default' => true,
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/checklists/(?P<id>[a-z0-9_-]+)/tasks/(?P<task>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'mark' ],
				'permission_callback' => [ $this, 'can_use_checklist' ],
				'args'                => [
					'done' => [
						'type'    => 'boolean',
						'default' => true,
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/checklists/(?P<id>[a-z0-9_-]+)/expanded',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'expand' ],
				'permission_callback' => [ $this, 'can_use_checklist' ],
				'args'                => [
					'expanded' => [
						'type'    => 'boolean',
						'default' => true,
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/wizards/(?P<id>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'read_wizard' ],
				'permission_callback' => [ $this, 'can_use_wizard' ],
			]
		);
	}

	/**
	 * Whether the request may touch the checklist it names.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function can_use_checklist( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return self::forbidden();
		}

		$checklist = Onboarding::checklist( (string) $request->get_param( 'id' ) );

		if ( null === $checklist ) {
			return new WP_Error( 'onboarding_unknown_checklist', __( 'There is no such checklist.', 'sugarcommerce' ), [ 'status' => 404 ] );
		}

		return $checklist->is_permitted() ? true : self::forbidden();
	}

	/**
	 * Whether the request may read the wizard it names.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function can_use_wizard( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return self::forbidden();
		}

		$wizard = Onboarding::get( (string) $request->get_param( 'id' ) );

		if ( null === $wizard ) {
			return new WP_Error( 'onboarding_unknown_wizard', __( 'There is no such setup.', 'sugarcommerce' ), [ 'status' => 404 ] );
		}

		return $wizard->is_permitted() ? true : self::forbidden();
	}

	/**
	 * A checklist's status.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function read_checklist( WP_REST_Request $request ): WP_REST_Response {
		$checklist = $this->checklist( $request );
		$status    = $checklist->status();

		$status['dismissed'] = $checklist->is_dismissed();

		return new WP_REST_Response( $status, 200 );
	}

	/**
	 * Hide the card for the current person, or bring it back.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$checklist = $this->checklist( $request );
		$dismissed = (bool) $request->get_param( 'dismissed' );

		if ( ! $checklist->dismiss( $dismissed ) ) {
			return new WP_Error( 'onboarding_not_dismissible', __( 'This checklist cannot be dismissed.', 'sugarcommerce' ), [ 'status' => 400 ] );
		}

		return new WP_REST_Response(
			[
				'success'   => true,
				'dismissed' => $dismissed,
				'message'   => $dismissed ? __( 'Setup guide hidden.', 'sugarcommerce' ) : __( 'Setup guide shown.', 'sugarcommerce' ),
				'html'      => $dismissed ? ( new ChecklistView( $checklist ) )->dismissed( $this->context( $request ) ) : $checklist->html( $this->context( $request ) ),
			],
			200
		);
	}

	/**
	 * Tick or untick a task by hand.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$checklist = $this->checklist( $request );
		$task      = (string) $request->get_param( 'task' );
		$done      = (bool) $request->get_param( 'done' );

		if ( null === $checklist->task( $task ) ) {
			return new WP_Error( 'onboarding_unknown_task', __( 'There is no such task.', 'sugarcommerce' ), [ 'status' => 404 ] );
		}

		// A task that is worked out cannot be overruled from here.
		if ( ! $checklist->mark( $task, $done ) ) {
			return new WP_Error( 'onboarding_task_not_manual', __( 'This task is ticked off automatically.', 'sugarcommerce' ), [ 'status' => 400 ] );
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'done'    => $done,
				'message' => $done ? __( 'Task marked as done.', 'sugarcommerce' ) : __( 'Task marked as not done.', 'sugarcommerce' ),
				'status'  => $checklist->status(),
				'html'    => $checklist->html( $this->context( $request ) ),
			],
			200
		);
	}

	/**
	 * Remember whether the compact card's full list is kept open.
	 *
	 * Answers without markup: the script has already opened or closed the
	 * list in the page, and swapping the card for a fresh copy would throw
	 * away the focus on the button that was just pressed.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function expand( WP_REST_Request $request ): WP_REST_Response {
		$expanded = (bool) $request->get_param( 'expanded' );

		$this->checklist( $request )->expand( $expanded );

		return new WP_REST_Response(
			[
				'success'  => true,
				'expanded' => $expanded,
			],
			200
		);
	}

	/**
	 * A wizard's progress.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function read_wizard( WP_REST_Request $request ): WP_REST_Response {
		$wizard = Onboarding::get( (string) $request->get_param( 'id' ) );

		return new WP_REST_Response( null === $wizard ? [] : $wizard->progress(), 200 );
	}

	/**
	 * The checklist a request names, already checked to exist.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return Checklist
	 */
	private function checklist( WP_REST_Request $request ): Checklist {
		return Onboarding::checklist( (string) $request->get_param( 'id' ) ) ?? new Checklist( '', [] );
	}

	/**
	 * Where and how the card the request came from was drawn, so the card
	 * that comes back is drawn the same way.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, string>
	 */
	private function context( WP_REST_Request $request ): array {
		$args = [
			'context' => (string) $request->get_param( 'context' ),
			'layout'  => (string) $request->get_param( 'layout' ),
		];

		return [
			'context' => ChecklistView::context( $args ),
			'layout'  => ChecklistView::layout( $args ),
		];
	}

	/**
	 * The refusal for somebody who may not.
	 *
	 * @return WP_Error
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'onboarding_forbidden',
			__( 'You are not allowed to do that.', 'sugarcommerce' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
