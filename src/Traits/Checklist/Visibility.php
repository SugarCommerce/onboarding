<?php
/**
 * Checklist: Visibility
 *
 * @package     SugarCommerce\Onboarding
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Onboarding\Traits\Checklist;

use SugarCommerce\Onboarding\Checklist;

/**
 * Whether a checklist shows, per user: dismissed, expanded, permitted.
 *
 * Part of Checklist: only the class uses it.
 */
trait Visibility {

	/**
	 * Whether a person has hidden the card.
	 *
	 * @param int $user_id The person. The current one by default.
	 *
	 * @return bool
	 */
	public function is_dismissed( int $user_id = 0 ): bool {
		$user_id = 0 === $user_id ? get_current_user_id() : $user_id;

		return $user_id > 0 && (bool) get_user_meta( $user_id, $this->dismissal_key(), true );
	}

	/**
	 * Hide the card for a person, or bring it back.
	 *
	 * @param bool $dismissed Whether to hide it.
	 * @param int  $user_id   The person. The current one by default.
	 *
	 * @return bool False when there is nobody to hide it for, or it cannot be dismissed.
	 */
	public function dismiss( bool $dismissed = true, int $user_id = 0 ): bool {
		$user_id = 0 === $user_id ? get_current_user_id() : $user_id;

		if ( $user_id <= 0 || ( $dismissed && ! $this->config['dismissible'] ) ) {
			return false;
		}

		if ( $dismissed ) {
			update_user_meta( $user_id, $this->dismissal_key(), time() );
		} else {
			delete_user_meta( $user_id, $this->dismissal_key() );
		}

		/**
		 * Fires when a person hides the card or brings it back.
		 *
		 * @param bool      $dismissed Whether it is now hidden.
		 * @param int       $user_id   The person.
		 * @param Checklist $checklist The checklist.
		 *
		 * @since 1.0.0
		 */
		do_action( "sugarcommerce_onboarding_checklist_dismissed_{$this->id}", $dismissed, $user_id, $this );

		return true;
	}

	/**
	 * The user meta key holding a dismissal.
	 *
	 * @return string
	 */
	public function dismissal_key(): string {
		return str_replace( '-', '_', sanitize_key( $this->id ) ) . '_checklist_dismissed';
	}

	/**
	 * Whether a person keeps the compact card's full list open.
	 *
	 * Remembered rather than reset on each visit: the compact card sits on a
	 * screen somebody opens many times a day, and a person who wants the
	 * whole list in view should not have to ask for it every time.
	 *
	 * @param int $user_id The person. The current one by default.
	 *
	 * @return bool
	 */
	public function is_expanded( int $user_id = 0 ): bool {
		$user_id = 0 === $user_id ? get_current_user_id() : $user_id;

		return $user_id > 0 && (bool) get_user_meta( $user_id, $this->expanded_key(), true );
	}

	/**
	 * Open or close the compact card's full list for a person.
	 *
	 * @param bool $expanded Whether to keep it open.
	 * @param int  $user_id  The person. The current one by default.
	 *
	 * @return bool False when there is nobody to remember it for.
	 */
	public function expand( bool $expanded = true, int $user_id = 0 ): bool {
		$user_id = 0 === $user_id ? get_current_user_id() : $user_id;

		if ( $user_id <= 0 ) {
			return false;
		}

		// Closed is the default, so closing forgets rather than stores a no.
		if ( $expanded ) {
			update_user_meta( $user_id, $this->expanded_key(), 1 );
		} else {
			delete_user_meta( $user_id, $this->expanded_key() );
		}

		return true;
	}

	/**
	 * The user meta key holding whether the full list is kept open.
	 *
	 * @return string
	 */
	public function expanded_key(): string {
		return str_replace( '-', '_', sanitize_key( $this->id ) ) . '_checklist_expanded';
	}

	/**
	 * Whether the card should be drawn for the current person at all.
	 *
	 * @return bool
	 */
	public function is_visible(): bool {
		if ( ! $this->is_permitted() || [] === $this->tasks() ) {
			return false;
		}

		if ( $this->config['dismissible'] && $this->is_dismissed() ) {
			return false;
		}

		return ! ( $this->config['hide_when_complete'] && $this->is_complete() );
	}
}
