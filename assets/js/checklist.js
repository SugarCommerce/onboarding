/**
 * Onboarding checklist card.
 *
 * Two buttons: hide the card (and bring it back), and tick a task that is
 * ticked by hand. Each posts to the library's REST route and swaps the card
 * for the markup that comes back, so this never has to know how a card is
 * drawn. Opening and closing tasks is the browser's own `<details>`.
 *
 * The compact card has a third, Show All, which opens its list here and now
 * and only then tells the server, so the list is remembered for next time.
 * Nothing is swapped: the focus stays on the button that was pressed.
 *
 * Bound on the document rather than on each card, so a card drawn after the
 * page loaded — a dashboard widget moved, a card swapped in by this script —
 * works the same.
 *
 * @package SugarCommerce\Onboarding
 */

( function () {
	'use strict';

	/**
	 * The focus target for a task ticked from the compact row: the row's new
	 * next task, which is not the one that was ticked.
	 */
	var NEXT = '#next';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-checklist-dismiss], [data-checklist-restore], [data-checklist-mark], [data-checklist-toggle]' );
		var card = button && button.closest( '.onboarding-checklist' );

		if ( ! card || ! card.dataset.endpoint ) {
			return;
		}

		event.preventDefault();

		if ( button.hasAttribute( 'data-checklist-toggle' ) ) {
			toggle( card, button );
		} else if ( button.hasAttribute( 'data-checklist-mark' ) ) {
			// Ticked from the compact row, the task leaves the row, so the
			// focus goes to whatever takes its place there.
			send( card, '/tasks/' + encodeURIComponent( button.dataset.checklistMark ), { done: '1' === button.dataset.done }, button.closest( '.onboarding-checklist__next' ) ? NEXT : button.dataset.checklistMark );
		} else {
			send( card, '/dismiss', { dismissed: button.hasAttribute( 'data-checklist-dismiss' ) }, '' );
		}
	} );

	/**
	 * Open or close the compact card's list, then remember it.
	 *
	 * The page changes first and the request follows, so the list opens at
	 * once however slow the server is. If remembering fails the list stays
	 * as it was asked to be, and only the next visit forgets.
	 *
	 * @param {HTMLElement} card   The card.
	 * @param {HTMLElement} button Its Show All button.
	 */
	function toggle( card, button ) {
		var panel = document.getElementById( button.getAttribute( 'aria-controls' ) );
		var open = 'true' !== button.getAttribute( 'aria-expanded' );
		var label = button.querySelector( '.onboarding-checklist__toggle-label' );

		if ( ! panel ) {
			return;
		}

		panel.hidden = ! open;
		button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		card.classList.toggle( 'is-expanded', open );

		if ( label ) {
			label.textContent = open ? button.dataset.hide : button.dataset.show;
		}

		window.fetch( card.dataset.endpoint + '/expanded', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': card.dataset.nonce || ''
			},
			body: JSON.stringify( { expanded: open } )
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( '' );
			}
		} ).catch( function () {
			announce( card, card.dataset.failed || 'That did not work.' );
		} );
	}

	/**
	 * Post to one of the card's routes and put the answer in its place.
	 *
	 * @param {HTMLElement} card  The card.
	 * @param {string}      path  The route, after the checklist's own.
	 * @param {Object}      body  What to send.
	 * @param {string}      task  The task to put the focus back on, if any, or NEXT.
	 */
	function send( card, path, body, task ) {
		if ( 'true' === card.getAttribute( 'aria-busy' ) ) {
			return;
		}

		card.setAttribute( 'aria-busy', 'true' );
		body.context = card.dataset.context || 'card';
		body.layout = card.dataset.layout || 'full';

		window.fetch( card.dataset.endpoint + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': card.dataset.nonce || ''
			},
			body: JSON.stringify( body )
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				return { ok: response.ok, json: json };
			} );
		} ).then( function ( result ) {
			if ( ! result.ok || ! result.json || 'string' !== typeof result.json.html ) {
				throw new Error( ( result.json && result.json.message ) || '' );
			}

			replace( card, result.json.html, result.json.message || '', task );
		} ).catch( function ( error ) {
			card.removeAttribute( 'aria-busy' );
			announce( card, error.message || card.dataset.failed || 'That did not work.' );
		} );
	}

	/**
	 * Swap the card for its new markup, keeping the focus somewhere sensible.
	 *
	 * @param {HTMLElement} card    The card.
	 * @param {string}      html    Its replacement.
	 * @param {string}      message What happened, to announce.
	 * @param {string}      task    The task to focus, if any, or NEXT.
	 */
	function replace( card, html, message, task ) {
		var holder = document.createElement( 'div' );

		holder.innerHTML = html;

		var fresh = holder.firstElementChild;

		if ( ! fresh ) {
			card.remove();

			return;
		}

		card.replaceWith( fresh );

		var focus;

		if ( NEXT === task ) {
			focus = fresh.querySelector( '.onboarding-checklist__next .button, .onboarding-checklist__next button, [data-checklist-toggle]' );
		} else if ( task ) {
			focus = fresh.querySelector( '[data-task="' + task + '"] summary' );
		} else {
			focus = fresh.querySelector( '[data-checklist-restore], [data-checklist-dismiss]' );
		}

		if ( focus ) {
			focus.focus();
		}

		announce( fresh, message );
	}

	/**
	 * Say something through the card's live region.
	 *
	 * Emptied first and written a frame later, so the same message twice in
	 * a row is still announced the second time.
	 *
	 * @param {HTMLElement} card    The card.
	 * @param {string}      message What to say.
	 */
	function announce( card, message ) {
		var region = card.querySelector( '[aria-live]' );

		if ( ! region || ! message ) {
			return;
		}

		region.textContent = '';

		window.requestAnimationFrame( function () {
			region.textContent = message;
		} );
	}
}() );
