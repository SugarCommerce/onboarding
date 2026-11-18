<?php
/**
 * Onboarding
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding;

use SugarCommerce\Onboarding\Rest\Controller;
use SugarCommerce\Onboarding\Utils\Runtime;

/**
 * The registered wizards and checklists, and the handful of hooks they share.
 *
 * A plugin registers a wizard once and this puts it on the menu, loads what
 * its current step needs, and routes the one POST every step makes. It
 * registers a checklist once and this answers the card's buttons and puts it
 * on the dashboard if it asked. Nothing here is per wizard: that is Wizard's,
 * so two plugins each with a wizard do not have to agree about anything.
 */
final class Onboarding {

	/**
	 * The registered wizards, by identifier.
	 *
	 * @var array<string, Wizard>
	 */
	private static array $wizards = [];

	/**
	 * The registered checklists, by identifier.
	 *
	 * @var array<string, Checklist>
	 */
	private static array $checklists = [];

	/**
	 * Whether the shared hooks are attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * The input naming the wizard a submission belongs to.
	 */
	public const INPUT = 'onboarding_wizard';

	/**
	 * Register a wizard.
	 *
	 * @param string               $id     Its identifier.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return Wizard|null Null when the configuration cannot make one.
	 */
	public static function register( string $id, array $config ): ?Wizard {
		$id = sanitize_key( $id );

		if ( '' === $id || [] === (array) ( $config['steps'] ?? [] ) ) {
			return null;
		}

		self::hook();

		$wizard = new Wizard( $id, $config );

		self::$wizards[ $id ] = $wizard;

		return $wizard;
	}

	/**
	 * Register a checklist.
	 *
	 * @param string               $id     Its identifier.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return Checklist|null Null when the configuration cannot make one.
	 */
	public static function register_checklist( string $id, array $config ): ?Checklist {
		$id = sanitize_key( $id );

		// No tasks is allowed, unlike a wizard with no steps: an add-on may
		// be the one that fills the list in, through its filter.
		if ( '' === $id ) {
			return null;
		}

		self::hook();

		$checklist = new Checklist( $id, $config );

		self::$checklists[ $id ] = $checklist;

		return $checklist;
	}

	/**
	 * Attach the hooks every wizard and checklist shares, once.
	 *
	 * @return void
	 */
	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		Wizard::declare_config_keys();

		add_action( 'admin_menu', [ __CLASS__, 'register_menus' ] );
		add_action( 'admin_head', [ __CLASS__, 'hide_menus' ] );
		add_action( 'in_admin_header', [ __CLASS__, 'quieten' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'admin_init', [ __CLASS__, 'route' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_endpoints' ], 0 );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'register_widgets' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'body_class' ] );
	}

	/**
	 * A registered wizard.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return Wizard|null
	 */
	public static function get( string $id ): ?Wizard {
		return self::$wizards[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Every registered wizard.
	 *
	 * @return array<string, Wizard>
	 */
	public static function all(): array {
		return self::$wizards;
	}

	/**
	 * A registered checklist.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return Checklist|null
	 */
	public static function checklist( string $id ): ?Checklist {
		return self::$checklists[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Every registered checklist.
	 *
	 * @return array<string, Checklist>
	 */
	public static function checklists(): array {
		return self::$checklists;
	}

	/**
	 * Forget a wizard.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return bool
	 */
	public static function unregister( string $id ): bool {
		$id = sanitize_key( $id );

		if ( ! isset( self::$wizards[ $id ] ) ) {
			return false;
		}

		unset( self::$wizards[ $id ] );

		return true;
	}

	/**
	 * Forget a checklist.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return bool
	 */
	public static function unregister_checklist( string $id ): bool {
		$id = sanitize_key( $id );

		if ( ! isset( self::$checklists[ $id ] ) ) {
			return false;
		}

		unset( self::$checklists[ $id ] );

		return true;
	}

	/**
	 * The wizard whose page is being viewed, if any.
	 *
	 * @return Wizard|null
	 */
	public static function current(): ?Wizard {
		foreach ( self::$wizards as $wizard ) {
			if ( $wizard->is_current() ) {
				return $wizard;
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * Hooks
	 * ------------------------------------------------------------------ */

	/**
	 * Put every wizard on the menu.
	 *
	 * @return void
	 */
	public static function register_menus(): void {
		foreach ( self::$wizards as $wizard ) {
			$wizard->register_menu();
		}
	}

	/**
	 * Take the hidden ones back off it.
	 *
	 * @return void
	 */
	public static function hide_menus(): void {
		foreach ( self::$wizards as $wizard ) {
			$wizard->hide_menu();
		}
	}

	/**
	 * Load what the wizard being viewed needs, and nothing anywhere else.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		self::current()?->enqueue();
	}

	/**
	 * Answer the checklist routes, and every wizard's buttons.
	 *
	 * @return void
	 */
	public static function register_endpoints(): void {
		foreach ( self::$wizards as $wizard ) {
			$wizard->register_endpoints();
		}

		( new Controller() )->register_routes();
	}

	/**
	 * Put the checklists that asked for it on the dashboard.
	 *
	 * @return void
	 */
	public static function register_widgets(): void {
		foreach ( self::$checklists as $checklist ) {
			$checklist->register_widget();
		}
	}

	/**
	 * Clear the admin notices off a wizard's page.
	 *
	 * A wizard is a takeover, and core prints every registered notice above
	 * whatever the page callback returns — so somebody else's "Your licence
	 * expires in 14 days" lands on top of the first screen of a plugin that
	 * has just been installed.
	 *
	 * Off for the wizard's own page only, and a wizard that would rather
	 * keep them sets `notices` to true. From `in_admin_header`, which core
	 * fires immediately before it prints them.
	 *
	 * @return void
	 */
	public static function quieten(): void {
		$wizard = self::current();

		if ( null === $wizard || $wizard->get( 'notices', false ) ) {
			return;
		}

		foreach ( [ 'admin_notices', 'all_admin_notices', 'user_admin_notices', 'network_admin_notices' ] as $hook ) {
			remove_all_actions( $hook );
		}
	}

	/**
	 * Mark the wizard's page, so the takeover and a plugin can style it.
	 *
	 * @param string $classes The body classes.
	 *
	 * @return string
	 */
	public static function body_class( string $classes ): string {
		$wizard = self::current();

		if ( null === $wizard ) {
			return $classes;
		}

		$extra = [
			'onboarding-wizard',
			'onboarding-wizard-' . sanitize_html_class( $wizard->id() ),
			'onboarding-layout-' . (string) $wizard->get( 'layout' ),
		];

		if ( '' !== (string) $wizard->get( 'body_class', '' ) ) {
			$extra[] = sanitize_html_class( (string) $wizard->get( 'body_class' ) );
		}

		return trim( $classes . ' ' . implode( ' ', $extra ) );
	}

	/**
	 * Handle a step submission, and the redirect after activation.
	 *
	 * @return void
	 */
	public static function route(): void {
		self::maybe_redirect();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- naming a wizard is not acting on it; submit() verifies the nonce.
		$id = isset( $_POST[ self::INPUT ] ) ? sanitize_key( wp_unslash( $_POST[ self::INPUT ] ) ) : '';

		self::get( $id )?->submit();
	}

	/**
	 * Send the user to a wizard once, after its plugin is activated.
	 *
	 * @return void
	 */
	private static function maybe_redirect(): void {
		if ( wp_doing_ajax() ) {
			return;
		}

		foreach ( self::$wizards as $id => $wizard ) {
			if ( ! $wizard->get( 'redirect' ) || ! get_transient( self::transient( $id ) ) ) {
				continue;
			}

			delete_transient( self::transient( $id ) );

			// Activating several plugins at once lands on the plugins screen
			// with a list of what happened; taking that over with one
			// plugin's wizard loses the rest of it.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core's own marker on the plugins screen, read only to stay out of the way.
			$redirect = ! isset( $_GET['activate-multi'] ) && ! $wizard->is_completed() && $wizard->is_permitted();

			/**
			 * Filter whether activating the plugin opens its wizard.
			 *
			 * @param bool   $redirect Whether to go there.
			 * @param Wizard $wizard   The wizard.
			 *
			 * @since 1.0.0
			 */
			$redirect = (bool) apply_filters( "sugarcommerce_onboarding_redirect_{$id}", $redirect, $wizard );

			if ( ! $redirect ) {
				continue;
			}

			wp_safe_redirect( $wizard->url() );
			exit;
		}
	}

	/**
	 * Ask for that redirect. Called from a plugin's activation hook.
	 *
	 * @param string $id The wizard's identifier.
	 *
	 * @return void
	 */
	public static function redirect_after_activation( string $id ): void {
		set_transient( self::transient( sanitize_key( $id ) ), 1, 30 );
	}

	/**
	 * Where that request is remembered between the two page loads.
	 *
	 * @param string $id The wizard's identifier.
	 *
	 * @return string
	 */
	private static function transient( string $id ): string {
		return Runtime::key( str_replace( '-', '_', $id ) . '_redirect' );
	}
}
