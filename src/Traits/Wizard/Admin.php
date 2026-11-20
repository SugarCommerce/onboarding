<?php
/**
 * Wizard: Admin
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Wizard;

use SugarCommerce\Fields\Assets as FieldAssets;
use SugarCommerce\Onboarding\Onboarding;
use SugarCommerce\Onboarding\Screen;
use SugarCommerce\Onboarding\Utils\Runtime;
use SugarCommerce\Plugin\Assets;

/**
 * The wizard's page in wp-admin: its hidden menu item, its scripts and its endpoints.
 *
 * Part of Wizard: only the class uses it.
 */
trait Admin {

	/**
	 * Register the wizard's admin page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook = (string) add_submenu_page(
			(string) $this->config['parent_slug'],
			(string) $this->config['page_title'],
			(string) $this->config['menu_title'],
			(string) $this->config['capability'],
			$this->slug(),
			fn() => ( new Screen( $this ) )->render()
		);
	}

	/**
	 * Take the menu item back out, leaving the page reachable.
	 *
	 * @return void
	 */
	public function hide_menu(): void {
		if ( (bool) $this->config['hidden'] ) {
			remove_submenu_page( (string) $this->config['parent_slug'], $this->slug() );
		}
	}

	/**
	 * The screen hook the page was registered under.
	 *
	 * @return string
	 */
	public function hook(): string {
		return $this->hook;
	}

	/**
	 * Whether the request is on this wizard's page.
	 *
	 * @return bool
	 */
	public function is_current(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which page is being viewed.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return $page === $this->slug();
	}

	/**
	 * Load what the current screen needs.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$step = $this->is_finish_screen() ? null : $this->step( $this->current_key() );
		$set  = null === $step ? null : $this->fields_to_render( $step );

		if ( null !== $set ) {
			( new FieldAssets() )->enqueue( $set->dependencies() );
		}

		Assets::enqueue_style( Runtime::handle(), __FILE__, 'css/onboarding.css' );

		// Built from assets/js into assets/build, in the footer. Its manifest
		// names what it imports: wp-dom-ready.
		Assets::enqueue_built_script( Runtime::handle(), __FILE__, 'onboarding' );

		$checklist = false === $this->config['finish'] ? '' : (string) $this->config['finish']['checklist'];

		if ( $this->is_finish_screen() && '' !== $checklist ) {
			Onboarding::checklist( $checklist )?->enqueue();
		}
	}

	/**
	 * Build every step's fields, so their buttons and searches answer.
	 *
	 * A button's action is run on a later request than the one that drew
	 * it — a REST request, where no wizard screen is ever rendered — and the
	 * kit only knows a handler once the field set declaring it has been
	 * built. Every step, whether or not it applies: whether it applies can
	 * depend on an answer saved a moment ago.
	 *
	 * @return void
	 */
	public function register_endpoints(): void {
		foreach ( $this->all_steps() as $step ) {
			$this->fields( $step );
		}
	}
}
