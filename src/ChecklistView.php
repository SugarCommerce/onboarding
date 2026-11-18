<?php
/**
 * Checklist View
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding;

use SugarCommerce\Onboarding\Utils\Icon;
use SugarCommerce\Onboarding\Utils\Runtime;

/**
 * A checklist, drawn as a card.
 *
 * The tasks are a list of disclosure widgets sharing a `name`, which is the
 * browser's own exclusive accordion: opening one closes the others, with the
 * keyboard handling, the announcements and the open state all native and no
 * script involved. The first task not done yet starts open, so the card
 * always shows the one thing to do next with its button — Shopify's setup
 * guide, which is the model, does the same.
 *
 * The progress is a real `progressbar` with its numbers, and the same numbers
 * in words beside it, because "3 of 6 tasks done" is what a person reads and
 * a bar is only the picture of it.
 *
 * The compact layout is the same card folded to one row, for a screen people
 * open every day — a list of orders — where a card of seven tasks would push
 * the work they came for below the fold on every visit until the setup is
 * finished. The row keeps what moves somebody forward: how far along they
 * are, the one task to do next with its button, and the way back into the
 * wizard. The whole list is a disclosure away, and stays open for a person
 * who opens it.
 */
final class ChecklistView {

	/**
	 * The checklist being drawn.
	 *
	 * @var Checklist
	 */
	private Checklist $checklist;

	/**
	 * Construct.
	 *
	 * @param Checklist $checklist The checklist.
	 */
	public function __construct( Checklist $checklist ) {
		$this->checklist = $checklist;
	}

	/**
	 * The card.
	 *
	 * @param array<string, mixed> $args `context`: "card" or "widget".
	 *                                   `layout`: "full" or "compact".
	 *
	 * @return string
	 */
	public function render( array $args = [] ): string {
		$this->checklist->enqueue();

		$id       = $this->checklist->id();
		$status   = $this->checklist->status();
		$context  = self::context( $args );
		$layout   = self::layout( $args );
		$heading  = 'onboarding-checklist-' . sanitize_html_class( $id ) . '-title';
		$expanded = 'compact' === $layout && $this->checklist->is_expanded();

		$html = sprintf(
			'<section class="onboarding-checklist onboarding-checklist--%1$s onboarding-checklist--%8$s%2$s%9$s" id="onboarding-checklist-%3$s" aria-labelledby="%4$s" data-endpoint="%5$s" data-nonce="%6$s" data-context="%1$s" data-layout="%8$s" data-failed="%7$s">',
			esc_attr( $context ),
			$status['complete'] ? ' is-complete' : '',
			esc_attr( sanitize_html_class( $id ) ),
			esc_attr( $heading ),
			esc_url( rest_url( Runtime::rest_namespace() . '/checklists/' . rawurlencode( $id ) ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_attr__( 'That did not work. Please try again.', 'sugarcommerce' ),
			esc_attr( $layout ),
			$expanded ? ' is-expanded' : ''
		);

		if ( 'compact' === $layout ) {
			$html .= $this->compact( $status, $heading, 'widget' === $context, $expanded );
		} else {
			$html .= $this->header( $status, $heading, 'widget' === $context );
			$html .= $this->tasks( $status );
		}

		// Where the script says what happened, announced politely.
		$html .= '<p class="onboarding-checklist__status screen-reader-text" aria-live="polite"></p>';

		return $html . '</section>';
	}

	/**
	 * Where a card is drawn: "card" or "widget".
	 *
	 * @param array<string, mixed> $args The drawing arguments.
	 *
	 * @return string
	 */
	public static function context( array $args ): string {
		return 'widget' === ( $args['context'] ?? '' ) ? 'widget' : 'card';
	}

	/**
	 * How a card is laid out: "full" or "compact".
	 *
	 * @param array<string, mixed> $args The drawing arguments.
	 *
	 * @return string
	 */
	public static function layout( array $args ): string {
		return 'compact' === ( $args['layout'] ?? '' ) ? 'compact' : 'full';
	}

	/**
	 * The compact card: one row, and the whole list behind a disclosure.
	 *
	 * In reading order, which is also the focus order: the title and how far
	 * along, the next task and its button, Continue Setup, Show All, and the
	 * dismiss button last, as it is in the full card. The list comes after
	 * the button that opens it, so opening it puts the tasks next in line.
	 *
	 * @param array<string, mixed> $status    The checklist's status.
	 * @param string               $heading   The heading's id.
	 * @param bool                 $in_widget Whether a dashboard widget already titles it.
	 * @param bool                 $expanded  Whether this person keeps the list open.
	 *
	 * @return string
	 */
	private function compact( array $status, string $heading, bool $in_widget, bool $expanded ): string {
		$complete = (bool) $status['complete'];
		$panel    = 'onboarding-checklist-' . sanitize_html_class( $this->checklist->id() ) . '-tasks';

		$html = '<div class="onboarding-checklist__row"><div class="onboarding-checklist__summary">';

		$html .= sprintf(
			'<h2 class="onboarding-checklist__title%s" id="%s">%s</h2>',
			$in_widget ? ' screen-reader-text' : '',
			esc_attr( $heading ),
			esc_html( (string) $this->checklist->get( $complete ? 'complete_title' : 'title' ) )
		);

		$html .= $this->meter(
			$status,
			sprintf(
				/* translators: 1: tasks done, 2: tasks in all. */
				__( '%1$s of %2$s done', 'sugarcommerce' ),
				number_format_i18n( (int) $status['done'] ),
				number_format_i18n( (int) $status['total'] )
			),
			''
		);

		$html .= '</div>' . $this->next_line( $status ) . '<div class="onboarding-checklist__controls">';

		$html .= $this->wizard_link();

		$html .= sprintf(
			'<button type="button" class="button-link onboarding-checklist__toggle" data-checklist-toggle aria-expanded="%1$s" aria-controls="%2$s" data-show="%3$s" data-hide="%4$s">' .
			'<span class="onboarding-checklist__toggle-label">%5$s</span>%6$s</button>',
			$expanded ? 'true' : 'false',
			esc_attr( $panel ),
			esc_attr( $this->checklist->label( 'show_all' ) ),
			esc_attr( $this->checklist->label( 'show_less' ) ),
			esc_html( $this->checklist->label( $expanded ? 'show_less' : 'show_all' ) ),
			Icon::render( 'heroicons-outline:chevron-down' )
		);

		$html .= '</div>' . $this->dismiss_button() . '</div>';

		$html .= sprintf( '<div class="onboarding-checklist__panel" id="%s"%s>', esc_attr( $panel ), $expanded ? '' : ' hidden' );

		$description = (string) $this->checklist->get( $complete ? 'complete_message' : 'description' );

		if ( '' !== $description ) {
			$html .= sprintf( '<p class="onboarding-checklist__description">%s</p>', esc_html( $description ) );
		}

		return $html . $this->tasks( $status, false ) . '</div>';
	}

	/**
	 * The compact card's one task: what to do next, and its button.
	 *
	 * Once nothing is left, the list's own word that it is finished.
	 *
	 * @param array<string, mixed> $status The checklist's status.
	 *
	 * @return string
	 */
	private function next_line( array $status ): string {
		$key  = (string) $status['next'];
		$task = '' === $key ? null : $this->checklist->task( $key );

		if ( null === $task ) {
			return sprintf(
				'<div class="onboarding-checklist__next"><span class="onboarding-checklist__next-title">%s</span></div>',
				esc_html( (string) $this->checklist->get( 'complete_message' ) )
			);
		}

		return sprintf(
			'<div class="onboarding-checklist__next" data-task="%1$s"><p class="onboarding-checklist__next-text"><span class="onboarding-checklist__next-label">%2$s</span> <span class="onboarding-checklist__next-title">%3$s</span></p>%4$s</div>',
			esc_attr( $key ),
			esc_html( $this->checklist->label( 'next' ) ),
			esc_html( (string) $task['title'] ),
			$this->task_actions( $key, $task, false )
		);
	}

	/**
	 * The title, the count, the bar and the way out.
	 *
	 * @param array<string, mixed> $status   The checklist's status.
	 * @param string               $heading  The heading's id.
	 * @param bool                 $in_widget Whether a dashboard widget already titles it.
	 *
	 * @return string
	 */
	private function header( array $status, string $heading, bool $in_widget ): string {
		$complete    = (bool) $status['complete'];
		$title       = $complete ? (string) $this->checklist->get( 'complete_title' ) : (string) $this->checklist->get( 'title' );
		$description = $complete ? (string) $this->checklist->get( 'complete_message' ) : (string) $this->checklist->get( 'description' );

		$html = '<div class="onboarding-checklist__header"><div class="onboarding-checklist__intro">';

		$html .= sprintf(
			'<h2 class="onboarding-checklist__title%s" id="%s">%s</h2>',
			$in_widget ? ' screen-reader-text' : '',
			esc_attr( $heading ),
			esc_html( $title )
		);

		if ( '' !== $description ) {
			$html .= sprintf( '<p class="onboarding-checklist__description">%s</p>', esc_html( $description ) );
		}

		$html .= '</div>' . $this->dismiss_button() . '</div>';

		$label = sprintf(
			/* translators: 1: tasks done, 2: tasks in all. */
			__( '%1$s of %2$s tasks complete', 'sugarcommerce' ),
			number_format_i18n( (int) $status['done'] ),
			number_format_i18n( (int) $status['total'] )
		);

		return $html . $this->meter( $status, $label, $this->wizard_link() );
	}

	/**
	 * The count in words and the bar, with anything to put after them.
	 *
	 * The bar is named by the same words that are shown, so a screen reader
	 * hears what a sighted person reads rather than a bare percentage.
	 *
	 * @param array<string, mixed> $status The checklist's status.
	 * @param string               $label  The count, in words.
	 * @param string               $after  Markup after the bar, already escaped.
	 *
	 * @return string
	 */
	private function meter( array $status, string $label, string $after ): string {
		return sprintf(
			'<div class="onboarding-checklist__progress"><span class="onboarding-checklist__count">%1$s</span>' .
			'<span class="onboarding-checklist__meter" role="progressbar" aria-label="%1$s" aria-valuemin="0" aria-valuemax="%2$d" aria-valuenow="%3$d">' .
			'<span class="onboarding-checklist__meter-fill" style="width:%4$d%%"></span></span>%5$s</div>',
			esc_html( $label ),
			(int) $status['total'],
			(int) $status['done'],
			(int) $status['percent'],
			$after
		);
	}

	/**
	 * The button that hides the card, if it may be hidden.
	 *
	 * @return string
	 */
	private function dismiss_button(): string {
		if ( ! $this->checklist->get( 'dismissible' ) ) {
			return '';
		}

		return sprintf(
			'<button type="button" class="onboarding-checklist__dismiss" data-checklist-dismiss aria-label="%s">%s</button>',
			esc_attr(
				sprintf(
					/* translators: %s: the checklist's title. */
					__( 'Dismiss %s', 'sugarcommerce' ),
					(string) $this->checklist->get( 'title' )
				)
			),
			Icon::render( 'heroicons-outline:x-mark' )
		);
	}

	/**
	 * A way back into the wizard, while it is unfinished.
	 *
	 * @return string
	 */
	private function wizard_link(): string {
		$wizard = Onboarding::get( (string) $this->checklist->get( 'wizard', '' ) );

		if ( null === $wizard || $wizard->is_completed() || ! $wizard->is_permitted() ) {
			return '';
		}

		return sprintf(
			'<a class="button onboarding-checklist__wizard" href="%s">%s</a>',
			esc_url( $wizard->url() ),
			esc_html( $this->checklist->label( 'wizard' ) )
		);
	}

	/**
	 * The tasks.
	 *
	 * @param array<string, mixed> $status    The checklist's status.
	 * @param bool                 $open_next Whether the next task starts open.
	 *                                        Not under the compact row, which
	 *                                        already shows it with its button.
	 *
	 * @return string
	 */
	private function tasks( array $status, bool $open_next = true ): string {
		$group = 'onboarding-checklist-' . sanitize_html_class( $this->checklist->id() );
		$html  = '<ol class="onboarding-checklist__tasks">';

		foreach ( $this->checklist->tasks() as $key => $task ) {
			$done = (bool) $status['tasks'][ $key ]['done'];
			$open = $open_next && $key === $status['next'];

			$html .= sprintf(
				'<li class="onboarding-checklist__task onboarding-checklist__task--%s" data-task="%s"><details name="%s"%s><summary>',
				$done ? 'done' : 'todo',
				esc_attr( $key ),
				esc_attr( $group ),
				$open ? ' open' : ''
			);

			$html .= sprintf(
				'<span class="onboarding-checklist__marker" aria-hidden="true">%s</span>',
				$done ? Icon::render( 'heroicons-outline:check' ) : ''
			);

			$html .= sprintf( '<span class="onboarding-checklist__task-title">%s</span>', esc_html( (string) $task['title'] ) );

			// Said, not only drawn: the tick is decoration.
			$html .= sprintf(
				'<span class="screen-reader-text">%s</span>',
				esc_html( $done ? __( '(done)', 'sugarcommerce' ) : __( '(not done)', 'sugarcommerce' ) )
			);

			if ( $task['optional'] ) {
				$html .= sprintf( '<span class="onboarding-checklist__optional">%s</span>', esc_html__( 'Optional', 'sugarcommerce' ) );
			}

			$html .= '</summary><div class="onboarding-checklist__body">';

			if ( '' !== (string) $task['description'] ) {
				$html .= sprintf( '<p>%s</p>', esc_html( (string) $task['description'] ) );
			}

			$html .= $this->task_actions( $key, $task, $done );

			$html .= '</div></details></li>';
		}

		return $html . '</ol>';
	}

	/**
	 * A task's button, and its tick for a task ticked by hand.
	 *
	 * @param string               $key  The task's key.
	 * @param array<string, mixed> $task The task.
	 * @param bool                 $done Whether it is done.
	 *
	 * @return string
	 */
	private function task_actions( string $key, array $task, bool $done ): string {
		$html = '';

		if ( '' !== (string) $task['url'] && '' !== (string) $task['button'] ) {
			$external = (bool) $task['external'];

			$html .= sprintf(
				'<a class="button %s" href="%s"%s>%s%s</a>',
				$done ? 'button-secondary' : 'button-primary',
				esc_url( (string) $task['url'] ),
				$external ? ' target="_blank" rel="noopener noreferrer"' : '',
				esc_html( (string) $task['button'] ),
				$external
					? sprintf(
						'%s<span class="screen-reader-text">%s</span>',
						Icon::render( 'heroicons-outline:arrow-top-right-on-square', 'onboarding-checklist__external' ),
						esc_html__( '(opens in a new tab)', 'sugarcommerce' )
					)
					: ''
			);
		}

		if ( $this->checklist->is_manual( $key ) ) {
			$html .= sprintf(
				'<button type="button" class="button-link onboarding-checklist__mark" data-checklist-mark="%s" data-done="%s">%s</button>',
				esc_attr( $key ),
				$done ? '0' : '1',
				esc_html( $this->checklist->label( $done ? 'mark_todo' : 'mark_done' ) )
			);
		}

		return '' === $html ? '' : '<div class="onboarding-checklist__actions">' . $html . '</div>';
	}

	/**
	 * What replaces the card once it is dismissed.
	 *
	 * A line and a way to undo it, so a mis-click does not cost the card.
	 * It carries the card's context and layout, so the undo brings back the
	 * card that was there and not the default one.
	 *
	 * @param array<string, mixed> $args The card's `context` and `layout`.
	 *
	 * @return string
	 */
	public function dismissed( array $args = [] ): string {
		return sprintf(
			'<div class="onboarding-checklist onboarding-checklist--dismissed" id="onboarding-checklist-%1$s" data-endpoint="%2$s" data-nonce="%3$s" data-context="%7$s" data-layout="%8$s" data-failed="%6$s"><p>%4$s</p><button type="button" class="button-link" data-checklist-restore>%5$s</button>' .
			'<p class="onboarding-checklist__status screen-reader-text" aria-live="polite"></p></div>',
			esc_attr( sanitize_html_class( $this->checklist->id() ) ),
			esc_url( rest_url( Runtime::rest_namespace() . '/checklists/' . rawurlencode( $this->checklist->id() ) ) ),
			esc_attr( wp_create_nonce( 'wp_rest' ) ),
			esc_html__( 'The setup guide is hidden.', 'sugarcommerce' ),
			esc_html( $this->checklist->label( 'restore' ) ),
			esc_attr__( 'That did not work. Please try again.', 'sugarcommerce' ),
			esc_attr( self::context( $args ) ),
			esc_attr( self::layout( $args ) )
		);
	}
}
