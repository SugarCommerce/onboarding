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
use SugarCommerce\Jobs\Sync;
use SugarCommerce\Onboarding\Utils\Icon;

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

	/**
	 * The id of the heading focus moves to.
	 */
	private const HEADING = 'onboarding-heading';

	/**
	 * The icon of a list item that names none.
	 */
	private const ITEM_ICON = 'heroicons-outline:check';

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
	 * The strip across the top: whose setup this is, and the way out.
	 *
	 * @return void
	 */
	private function bar(): void {
		$logo  = (string) $this->wizard->get( 'logo', '' );
		$title = (string) $this->wizard->get( 'title' );

		echo '<header class="onboarding__bar"><div class="onboarding__brand">';

		if ( '' !== $logo ) {
			// The heading is still there, for anyone not looking at the logo.
			printf(
				'<img class="onboarding__logo" src="%s" alt=""><h1 class="screen-reader-text">%s</h1>',
				esc_url( $logo ),
				esc_html( $title )
			);
		} else {
			printf( '<h1 class="onboarding__title">%s</h1>', esc_html( $title ) );
		}

		printf(
			'</div><a class="onboarding__exit" href="%s">%s</a></header>',
			esc_url( $this->wizard->exit_url() ),
			esc_html( $this->wizard->label( 'exit' ) )
		);
	}

	/**
	 * Where this step sits among the others.
	 *
	 * @param string $current  The current step's key, empty on the finish screen.
	 * @param bool   $finished Whether this is the finish screen.
	 *
	 * @return void
	 */
	private function rail( string $current, bool $finished ): void {
		$steps    = $this->wizard->steps();
		$statuses = $this->wizard->statuses();
		$total    = count( $steps );

		if ( $total < 2 && ! $finished ) {
			return;
		}

		$progress = $this->wizard->progress();

		printf(
			'<nav class="onboarding__rail" aria-label="%s">',
			esc_attr__( 'Setup progress', 'sugarcommerce' )
		);

		printf(
			'<p class="onboarding__count">%s</p>',
			esc_html(
				$finished
					? __( 'Setup complete', 'sugarcommerce' )
					: sprintf(
						/* translators: 1: the step's number, 2: how many steps there are. */
						__( 'Step %1$s of %2$s', 'sugarcommerce' ),
						number_format_i18n( $this->wizard->position( $current ) ),
						number_format_i18n( $total )
					)
			)
		);

		// The picture of the count above it, so hidden from a screen reader,
		// which has just read the count.
		printf(
			'<div class="onboarding__meter" aria-hidden="true"><span style="width:%d%%"></span></div>',
			$finished ? 100 : (int) $progress['percent']
		);

		echo '<ol class="onboarding__steps">';

		$position = 0;

		foreach ( $steps as $key => $step ) {
			++$position;

			$status    = $statuses[ $key ];
			$is_here   = $key === $current;
			$reachable = ! $is_here && $this->wizard->is_reachable( $key );

			printf(
				'<li class="onboarding__step onboarding__step--%s%s"%s>',
				esc_attr( $status ),
				$is_here ? ' is-current' : '',
				$is_here ? ' aria-current="step"' : ''
			);

			$inner = sprintf(
				'<span class="onboarding__marker" aria-hidden="true">%s</span><span class="onboarding__step-title">%s</span>%s',
				$this->marker( $status, $position ),
				esc_html( self::short_title( $step ) ),
				$this->status_text( $status )
			);

			if ( $reachable ) {
				printf( '<a class="onboarding__step-link" href="%s">%s</a>', esc_url( $this->wizard->url( $key ) ), $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			} else {
				printf( '<span class="onboarding__step-link">%s</span>', $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			}

			echo '</li>';
		}

		echo '</ol></nav>';
	}

	/**
	 * Where this step sits among the others, above the card.
	 *
	 * The default. One column, so the eye goes down the page and nowhere
	 * else: a count and the step's name, then a bar of one segment per
	 * step, each segment labelled with its step's name on a wide screen.
	 * Done, skipped, current and to come differ in shape as well as colour
	 * — solid, hatched, thick and outlined — and in what the label says.
	 *
	 * It is still the ordered list of named places the rail is, with the
	 * current one marked, so a screen reader hears the same thing in either
	 * layout; done and skipped steps are links back.
	 *
	 * @param string $current  The current step's key, empty on the finish screen.
	 * @param bool   $finished Whether this is the finish screen.
	 *
	 * @return void
	 */
	private function stepper( string $current, bool $finished ): void {
		$steps    = $this->wizard->steps();
		$statuses = $this->wizard->statuses();
		$total    = count( $steps );

		if ( $total < 2 && ! $finished ) {
			return;
		}

		printf(
			'<nav class="onboarding__progress" aria-label="%s">',
			esc_attr__( 'Setup progress', 'sugarcommerce' )
		);

		if ( $finished ) {
			printf( '<p class="onboarding__count">%s</p>', esc_html__( 'Setup complete', 'sugarcommerce' ) );
		} else {
			printf(
				'<p class="onboarding__count"><span class="onboarding__count-number">%1$s</span><span class="onboarding__count-name" aria-hidden="true"> · %2$s</span></p>',
				esc_html(
					sprintf(
						/* translators: 1: the step's number, 2: how many steps there are. */
						__( 'Step %1$s of %2$s', 'sugarcommerce' ),
						number_format_i18n( $this->wizard->position( $current ) ),
						number_format_i18n( $total )
					)
				),
				esc_html( (string) ( $steps[ $current ]['title'] ?? '' ) )
			);
		}

		printf( '<ol class="onboarding__segments" style="--onboarding-steps:%d">', (int) $total );

		foreach ( $steps as $key => $step ) {
			$status    = $finished ? 'done' : $statuses[ $key ];
			$is_here   = $key === $current;
			$reachable = ! $is_here && $this->wizard->is_reachable( $key );

			printf(
				'<li class="onboarding__segment onboarding__segment--%s%s"%s>',
				esc_attr( $status ),
				$is_here ? ' is-current' : '',
				$is_here ? ' aria-current="step"' : ''
			);

			$inner = sprintf(
				'<span class="onboarding__segment-bar" aria-hidden="true"></span><span class="onboarding__segment-label">%s%s</span>%s',
				'done' === $status ? Icon::render( 'heroicons-outline:check' ) : '',
				esc_html( self::short_title( $step ) ),
				$this->status_text( $status )
			);

			if ( $reachable ) {
				printf( '<a class="onboarding__segment-link" href="%s">%s</a>', esc_url( $this->wizard->url( $key ) ), $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			} else {
				printf( '<span class="onboarding__segment-link">%s</span>', $inner ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			}

			echo '</li>';
		}

		echo '</ol></nav>';
	}

	/**
	 * What the progress calls a step: its `short_title` where it has one.
	 *
	 * A heading can be a sentence — "Tell SugarCart when somebody pays" —
	 * and a label under a segment a fifth of the card wide cannot.
	 *
	 * @param array<string, mixed> $step The step.
	 *
	 * @return string
	 */
	private static function short_title( array $step ): string {
		$short = (string) $step['short_title'];

		return '' === $short ? (string) $step['title'] : $short;
	}

	/**
	 * What goes in a step's marker: a tick, a dash, or its number.
	 *
	 * @param string $status   done, skipped or todo.
	 * @param int    $position Its place in the list, from one.
	 *
	 * @return string
	 */
	private function marker( string $status, int $position ): string {
		if ( 'done' === $status ) {
			return Icon::render( 'heroicons-outline:check' );
		}

		if ( 'skipped' === $status ) {
			return Icon::render( 'heroicons-outline:minus' );
		}

		return esc_html( (string) number_format_i18n( $position ) );
	}

	/**
	 * What a step's status is, in words.
	 *
	 * Skipped is shown as well as said: a dash alone does not tell anybody
	 * the step is there to come back to. Done is only said, since the tick
	 * already shows it.
	 *
	 * @param string $status done, skipped or todo.
	 *
	 * @return string
	 */
	private function status_text( string $status ): string {
		if ( 'skipped' === $status ) {
			return sprintf( '<span class="onboarding__step-status">%s</span>', esc_html__( 'Skipped', 'sugarcommerce' ) );
		}

		if ( 'done' === $status ) {
			return sprintf( '<span class="screen-reader-text">%s</span>', esc_html__( '(completed)', 'sugarcommerce' ) );
		}

		return '';
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
