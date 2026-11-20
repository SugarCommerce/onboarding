<?php
/**
 * Screen: Progress
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Screen;

use SugarCommerce\Onboarding\Utils\Icon;

/**
 * The wizard's top bar, rail and stepper: where the user is, and what is done.
 *
 * Part of Screen: only the class uses it.
 */
trait Progress {

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
}
