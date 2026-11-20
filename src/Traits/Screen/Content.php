<?php
/**
 * Screen: Content
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Screen;

use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Jobs\Sync;
use SugarCommerce\Onboarding\Utils\Icon;
use SugarCommerce\Onboarding\Wizard;

/**
 * A step's own content: its prose, lists, links and the import step's wait.
 *
 * Part of Screen: only the class uses it.
 */
trait Content {

	/**
	 * What the step shows, top to bottom.
	 *
	 * Any step may have any of these; a step with only `content` explains,
	 * one with only `fields` asks, and one with `render` draws what neither
	 * can. The custom markup comes before the fields, since what it usually
	 * says is what the fields are for; markup between two fields is the
	 * kit's `html` field. A `type` this library does not know is handed to
	 * whoever registered it.
	 *
	 * @param array<string, mixed> $step The step.
	 * @param FieldSet|null        $set  Its fields.
	 *
	 * @return void
	 */
	private function content( array $step, ?FieldSet $set ): void {
		$this->prose( $step );

		if ( is_callable( $step['render'] ) ) {
			echo '<div class="onboarding__custom">';
			call_user_func( $step['render'], $step, $this->wizard );
			echo '</div>';
		}

		if ( null !== $set ) {
			echo '<div class="onboarding__fields field-kit">';
			echo $set->render( 0, array_diff_key( $this->wizard->errors(), [ Wizard::STEP_ERROR => true ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			echo '</div>';
		}

		$type = (string) $step['type'];

		if ( 'sync' === $type ) {
			$this->sync( $step );
		} elseif ( '' !== $type ) {
			/**
			 * Draw a step type this library does not know.
			 *
			 * @param array  $step   The step.
			 * @param Wizard $wizard The wizard.
			 *
			 * @since 1.0.0
			 */
			do_action( "sugarcommerce_onboarding_render_{$type}", $step, $this->wizard );
		}

		$this->links( (array) $step['links'] );
	}

	/**
	 * An image, a paragraph or two, and a list of points.
	 *
	 * @param array<string, mixed> $section A step, or the finish screen.
	 *
	 * @return void
	 */
	private function prose( array $section ): void {
		if ( '' !== (string) $section['image'] ) {
			printf( '<img class="onboarding__image" src="%s" alt="">', esc_url( (string) $section['image'] ) );
		}

		if ( '' !== (string) $section['content'] ) {
			printf( '<div class="onboarding__prose">%s</div>', wp_kses_post( (string) $section['content'] ) );
		}

		$this->items( (array) $section['items'] );
	}

	/**
	 * A list of things, each with an icon and a line or two.
	 *
	 * @param array<int, array<string, string>> $items The items.
	 *
	 * @return void
	 */
	private function items( array $items ): void {
		if ( [] === $items ) {
			return;
		}

		echo '<ul class="onboarding__items">';

		foreach ( $items as $item ) {
			echo '<li class="onboarding__item">';

			// The icon in either form: `heroicons-outline:store`, or a
			// Dashicon (`store`, `dashicons-store`). One that cannot be drawn
			// is the default rather than an empty tile.
			$icon = Icon::render( (string) ( $item['icon'] ?? '' ) );

			printf(
				'<span class="onboarding__item-icon" aria-hidden="true">%s</span>',
				'' !== $icon ? $icon : Icon::render( self::ITEM_ICON ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised SVG, or the Dashicons span, from the field kit.
			);

			echo '<span class="onboarding__item-text">';

			if ( '' !== (string) ( $item['title'] ?? '' ) ) {
				printf( '<strong>%s</strong>', esc_html( (string) $item['title'] ) );
			}

			if ( '' !== (string) ( $item['description'] ?? '' ) ) {
				printf( '<span>%s</span>', esc_html( (string) $item['description'] ) );
			}

			echo '</span></li>';
		}

		echo '</ul>';
	}

	/**
	 * Somewhere to go.
	 *
	 * The first link is the main one and drawn as such; the rest are
	 * secondary, and one that leaves the site says so.
	 *
	 * @param array<int, array<string, mixed>> $links The links.
	 *
	 * @return void
	 */
	private function links( array $links ): void {
		if ( [] === $links ) {
			return;
		}

		echo '<div class="onboarding__links">';

		foreach ( array_values( $links ) as $index => $link ) {
			$external = ! empty( $link['external'] );

			printf(
				'<a class="button %s" href="%s"%s>%s%s</a>',
				0 === $index && ! $external ? 'button-primary' : 'button-secondary',
				esc_url( (string) ( $link['url'] ?? '' ) ),
				$external ? ' target="_blank" rel="noopener noreferrer"' : '',
				esc_html( (string) ( $link['label'] ?? '' ) ),
				$external
					// Said, not just drawn: a link that opens elsewhere and
					// does not say so is the classic one.
					? sprintf(
						'%s<span class="screen-reader-text">%s</span>',
						Icon::render( 'heroicons-outline:arrow-top-right-on-square', 'onboarding__external' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised SVG from the field kit.
						esc_html__( '(opens in a new tab)', 'sugarcommerce' )
					)
					: ''
			);
		}

		echo '</div>';
	}

	/**
	 * A step that runs an import.
	 *
	 * The work is sugarcommerce/jobs' Sync; what is here is where its bar
	 * goes and the button that starts it. Without the package, or with no
	 * sync registered under the step's `sync_id`, the step is drawn without
	 * them and Continue does not wait.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return void
	 */
	private function sync( array $step ): void {
		$sync = '' === (string) $step['sync_id'] || ! class_exists( Sync::class ) ? null : Sync::get( (string) $step['sync_id'] );

		if ( null === $sync ) {
			return;
		}

		echo '<div class="onboarding__sync"><div class="onboarding__sync-bar"></div>';

		$sync->render_button( [ 'container' => '.onboarding__sync-bar' ] );

		echo '</div>';
	}

	/**
	 * Whether Continue waits for the step's import to run.
	 *
	 * Only when there is an import to wait for: a step whose sync is not
	 * registered would otherwise be a step nobody can leave.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return bool
	 */
	private function waits_for_sync( array $step ): bool {
		return 'sync' === (string) $step['type']
			&& '' !== (string) $step['sync_id']
			&& class_exists( Sync::class )
			&& null !== Sync::get( (string) $step['sync_id'] );
	}
}
