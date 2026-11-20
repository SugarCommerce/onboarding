<?php
/**
 * Screen
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding;

use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Onboarding\Utils\Icon;
use SugarCommerce\Onboarding\Traits\Screen\Progress;
use SugarCommerce\Onboarding\Traits\Screen\Content;

/**
 * One wizard step, or the screen after the last, drawn.
 *
 * Everything here is markup and the decisions that only matter to markup. The
 * ones worth stating:
 *
 * The primary button comes first in the form, and is the only one without
 * `formnovalidate`. Pressing Enter in a field submits a form's first submit
 * button, so Enter always means Continue — never Skip, never Save and finish
 * later — with no script, and the order the buttons are read in is the order
 * they are drawn in. Back is a link: it saves nothing, so it does not post.
 *
 * Skip and Save and finish later are buttons rather than links because they
 * do something the server has to know about: a skipped step is remembered as
 * skipped, so the progress says so and the wizard resumes past it.
 *
 * The progress is an ordered list with `aria-current`, rather than a progress
 * bar. A bar is a single value; this is a set of named places, one of which
 * you are at, and the ones already passed are links back.
 *
 * Errors are listed once at the top, each linking to its field, and said
 * again beside the field by the kit. The list is what is announced and what
 * gets the focus; the message by the field is what is in view while fixing it.
 */
final class Screen {

	use Progress;
	use Content;

	/**
	 * The id of the heading focus moves to.
	 */
	private const string HEADING = 'onboarding-heading';

	/**
	 * The icon of a list item that names none.
	 */
	private const string ITEM_ICON = 'heroicons-outline:check';

	/**
	 * The wizard being drawn.
	 *
	 * @var Wizard
	 */
	private Wizard $wizard;

	/**
	 * Construct.
	 *
	 * @param Wizard $wizard The wizard.
	 */
	public function __construct( Wizard $wizard ) {
		$this->wizard = $wizard;
	}

	/**
	 * Draw the current step, or the finish screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->wizard->is_permitted() ) {
			wp_die( esc_html__( 'You are not allowed to run this setup.', 'sugarcommerce' ), '', [ 'response' => 403 ] );
		}

		$finished = $this->wizard->is_finish_screen();
		$key      = $finished ? '' : $this->wizard->current_key();
		$step     = $finished ? null : $this->wizard->step( $key );

		if ( ! $finished && null === $step ) {
			return;
		}

		$accent = (string) $this->wizard->get( 'accent', '' );

		printf(
			'<div class="onboarding onboarding--%s"%s>',
			esc_attr( (string) $this->wizard->get( 'layout' ) ),
			'' === $accent ? '' : sprintf( ' style="--onboarding-accent:%s"', esc_attr( $accent ) )
		);

		$this->bar();

		$side = 'side' === $this->wizard->get( 'progress' );

		printf( '<div class="onboarding__layout onboarding__layout--%s">', $side ? 'side' : 'top' );

		if ( $side ) {
			$this->rail( $key, $finished );
		} else {
			$this->stepper( $key, $finished );
		}

		echo '<div class="onboarding__main">';

		if ( null === $step ) {
			$this->finish();
		} else {
			$this->step( $key, $step );
		}

		echo '</div></div></div>';
	}

	/**
	 * One step, as a form.
	 *
	 * @param string               $key  The step's key.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return void
	 */
	private function step( string $key, array $step ): void {
		$set = $this->wizard->fields_to_render( $step );

		// On the form rather than localized into a global. Strauss renames
		// classes and namespaces, not string literals, so two plugins each
		// bundling a prefixed copy would agree on the name of the global and
		// disagree about who set it. An attribute on the element the script
		// already found belongs to that element.
		//
		// A plain full-page post to the wizard's own page, not REST: a step
		// has to work with no script at all.
		printf(
			'<form method="post" class="onboarding__card onboarding__form" action="%s" data-step="%s" data-sync="%s" data-fields="%s">',
			esc_url( $this->wizard->url( $key ) ),
			esc_attr( $key ),
			$this->waits_for_sync( $step ) ? '1' : '0',
			esc_attr( $this->wizard->input_name() )
		);

		wp_nonce_field( 'onboarding_' . $this->wizard->id() . '_' . $key );

		printf(
			'<input type="hidden" name="%s" value="%s"><input type="hidden" name="onboarding_step" value="%s">',
			esc_attr( Onboarding::INPUT ),
			esc_attr( $this->wizard->id() ),
			esc_attr( $key )
		);

		$this->back( $key );
		$this->heading( (string) $step['title'], (string) $step['description'] );
		$this->errors( $set );

		echo '<div class="onboarding__content">';

		/**
		 * Fires before a step's content, inside its form.
		 *
		 * @param string $key    The step's key.
		 * @param array  $step   The step.
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		do_action( "sugarcommerce_onboarding_before_step_{$this->wizard->id()}", $key, $step, $this->wizard );

		$this->content( $step, $set );

		/**
		 * Fires after a step's content, inside its form.
		 *
		 * @param string $key    The step's key.
		 * @param array  $step   The step.
		 * @param Wizard $wizard The wizard.
		 *
		 * @since 1.0.0
		 */
		do_action( "sugarcommerce_onboarding_after_step_{$this->wizard->id()}", $key, $step, $this->wizard );

		echo '</div>';

		$this->actions( $key, $step, null !== $set && [] !== array_filter( $set->fields(), static fn( $field ): bool => $field->type()->stores_value() ) );

		echo '</form>';
	}

	/**
	 * The way back a step.
	 *
	 * Above the heading, where Stripe and Shopify put it: below the form it
	 * sits beside Continue, and a Back that close to the main button gets
	 * pressed by accident.
	 *
	 * @param string $key The current step's key.
	 *
	 * @return void
	 */
	private function back( string $key ): void {
		$previous = $this->wizard->adjacent( $key, -1 );

		if ( '' === $previous ) {
			return;
		}

		printf(
			'<a class="onboarding__back" href="%s">%s%s</a>',
			esc_url( $this->wizard->url( $previous ) ),
			Icon::render( 'heroicons-outline:chevron-left' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised SVG from the field kit.
			esc_html( $this->wizard->label( 'back' ) )
		);
	}

	/**
	 * A heading, and the line under it.
	 *
	 * The heading takes focus when the screen opens, so a screen reader
	 * starts each step at its question rather than at the top of the admin.
	 *
	 * @param string $title       The title.
	 * @param string $description The line under it.
	 *
	 * @return void
	 */
	private function heading( string $title, string $description ): void {
		echo '<div class="onboarding__header">';

		printf(
			'<h2 class="onboarding__heading" id="%s" tabindex="-1">%s</h2>',
			esc_attr( self::HEADING ),
			esc_html( $title )
		);

		if ( '' !== $description ) {
			printf( '<p class="onboarding__description">%s</p>', esc_html( $description ) );
		}

		echo '</div>';
	}

	/**
	 * Whatever the last submission was refused for.
	 *
	 * @param FieldSet|null $set The step's fields.
	 *
	 * @return void
	 */
	private function errors( ?FieldSet $set ): void {
		$errors = $this->wizard->errors();

		if ( [] === $errors ) {
			return;
		}

		printf(
			'<div class="onboarding__errors" role="alert" tabindex="-1" aria-labelledby="onboarding-errors-title"><p class="onboarding__errors-title" id="onboarding-errors-title">%s</p><ul>',
			esc_html(
				_n( 'One answer needs another look.', 'Some answers need another look.', count( $errors ), 'sugarcommerce' )
			)
		);

		foreach ( $errors as $key => $message ) {
			$field = Wizard::STEP_ERROR === $key ? null : $set?->field( (string) $key );

			if ( null === $field ) {
				printf( '<li>%s</li>', esc_html( $message ) );

				continue;
			}

			// The kit's own messages already name the field; a label in
			// front of "Store name is required." would say it twice.
			$label = $field->label();

			printf(
				'<li><a href="#%s">%s</a></li>',
				esc_attr( $field->input_id() ),
				esc_html( '' === $label || str_contains( $message, $label ) ? $message : $label . ': ' . $message )
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Continue, Skip, and Save and finish later.
	 *
	 * @param string               $key        The current step's key.
	 * @param array<string, mixed> $step       The step.
	 * @param bool                 $has_fields Whether any field stores an answer.
	 *
	 * @return void
	 */
	private function actions( string $key, array $step, bool $has_fields ): void {
		$is_last = $this->wizard->is_last( $key );
		$label   = (string) $step['button'];

		if ( '' === $label ) {
			$label = $this->wizard->label( $is_last ? 'finish' : 'next' );
		}

		echo '<div class="onboarding__actions">';

		printf(
			'<button type="submit" name="onboarding_intent" value="next" class="button button-primary onboarding__next">%s</button>',
			esc_html( $label )
		);

		if ( ! empty( $step['skippable'] ) ) {
			printf(
				'<button type="submit" name="onboarding_intent" value="skip" class="onboarding__quiet onboarding__skip" formnovalidate>%s</button>',
				esc_html( '' !== (string) $step['skip_label'] ? (string) $step['skip_label'] : $this->wizard->label( 'skip' ) )
			);
		}

		// Only where there is something to save — a step whose only field
		// is a button has nothing — and not on the last step, where
		// finishing is one press away.
		if ( $has_fields && ! $is_last ) {
			printf(
				'<button type="submit" name="onboarding_intent" value="later" class="onboarding__quiet onboarding__later" formnovalidate>%s</button>',
				esc_html( $this->wizard->label( 'later' ) )
			);
		}

		echo '</div>';
	}

	/**
	 * The screen after the last step.
	 *
	 * @return void
	 */
	private function finish(): void {
		$finish = (array) $this->wizard->get( 'finish' );

		printf(
			'<div class="onboarding__card onboarding__finish" data-celebrate="%s">',
			empty( $finish['confetti'] ) ? '0' : '1'
		);

		echo '<span class="onboarding__finish-mark" aria-hidden="true">' . Icon::render( 'heroicons-outline:check' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised SVG from the field kit.

		$this->heading( (string) $finish['title'], (string) $finish['description'] );

		echo '<div class="onboarding__content">';

		$this->prose( $finish );

		if ( is_callable( $finish['render'] ) ) {
			echo '<div class="onboarding__custom">';
			call_user_func( $finish['render'], $this->wizard );
			echo '</div>';
		}

		$checklist = Onboarding::checklist( (string) $finish['checklist'] );

		if ( null !== $checklist ) {
			echo $checklist->html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		}

		$links = (array) $finish['links'];

		// Somewhere to go is not optional: a finish screen with no button
		// is a dead end that has to be left through the admin menu.
		if ( [] === $links ) {
			$links = [
				[
					'label' => __( 'Done', 'sugarcommerce' ),
					'url'   => $this->wizard->exit_url(),
				],
			];
		}

		$this->links( $links );

		echo '</div></div>';
	}
}
