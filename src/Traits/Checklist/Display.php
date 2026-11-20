<?php
/**
 * Checklist: Display
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Checklist;

use SugarCommerce\Onboarding\ChecklistView;
use SugarCommerce\Onboarding\Utils\Runtime;
use SugarCommerce\Plugin\Assets;

/**
 * Drawing a checklist as a card or a dashboard widget, with its script.
 *
 * Part of Checklist: only the class uses it.
 */
trait Display {

	/**
	 * The card's markup, or nothing when it is not for this person.
	 *
	 * @param array<string, mixed> $args `context`: "card" (default) or "widget",
	 *                                   which leaves the title to the widget's own.
	 *                                   `layout`: "full" (default), every task
	 *                                   listed; or "compact", one row with the
	 *                                   next task and the rest behind Show All.
	 *
	 * @return string
	 */
	public function html( array $args = [] ): string {
		if ( ! $this->is_visible() ) {
			return '';
		}

		return ( new ChecklistView( $this ) )->render( $args );
	}

	/**
	 * Print the card, or nothing when it is not for this person.
	 *
	 * @param array<string, mixed> $args As html().
	 *
	 * @return void
	 */
	public function render( array $args = [] ): void {
		echo $this->html( $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
	}

	/**
	 * Load the card's stylesheet and script.
	 *
	 * Call it from `admin_enqueue_scripts` on the screens that draw the card,
	 * so the styles are in the head. Drawing the card also asks, which is in
	 * time for the script and late for the styles.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		Assets::enqueue_style( Runtime::handle( 'checklist' ), __FILE__, 'css/checklist.css' );

		// Built from assets/js into assets/build, in the footer. Its manifest
		// names what it imports -- wp-api-fetch, which renews a nonce that
		// ran out while the card was open, and wp-i18n -- and the helper puts
		// its words in the library's text domain.
		Assets::enqueue_built_script( Runtime::handle( 'checklist' ), __FILE__, 'checklist' );
	}

	/**
	 * Put the card on the dashboard, if it asked to be.
	 *
	 * @return void
	 */
	public function register_widget(): void {
		$widget = $this->config['dashboard_widget'];

		if ( false === $widget || ! $this->is_visible() ) {
			return;
		}

		$this->enqueue();

		wp_add_dashboard_widget(
			str_replace( '-', '_', sanitize_key( $this->id ) ) . '_checklist',
			esc_html( is_string( $widget ) && '' !== $widget ? $widget : (string) $this->config['title'] ),
			function (): void {
				echo $this->html( [ 'context' => 'widget' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			}
		);
	}
}
