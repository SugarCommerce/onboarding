/**
 * The card's buttons.
 *
 * Two of them: hide the card (and bring it back), and tick a task that is
 * ticked by hand. Each posts to the library's REST route and swaps the card
 * for the markup that comes back, so this never has to know how a card is
 * drawn. Opening and closing tasks is the browser's own `<details>`.
 *
 * The compact card has a third, Show All, which opens its list here and now
 * and only then tells the server, so the list is remembered for next time.
 * Nothing is swapped: the focus stays on the button that was pressed.
 *
 * Bound on the document rather than on each card, so a card drawn after the
 * page loaded -- a dashboard widget moved, a card swapped in by this
 * script -- works the same.
 */

import { __ } from '@wordpress/i18n';

import { cardSettings } from '../lib/config';
import { announce } from './announce';

/**
 * The focus target for a task ticked from the compact row: the row's new
 * next task, which is not the one that was ticked.
 */
export const NEXT = '#next';

/**
 * The card's buttons, by their data attributes.
 */
const BUTTONS =
	'[data-checklist-dismiss], [data-checklist-restore], [data-checklist-mark], [data-checklist-toggle]';

/**
 * What to say when a button did not work.
 *
 * @param {Object} settings The card's.
 * @return {string} The server's words, or the last resort for a card drawn
 *                  without them.
 */
const failed = ( settings ) =>
	settings.failed ||
	__( 'That did not work. Please try again.', 'sugarcommerce' );

/**
 * Answer every card's buttons.
 *
 * @param {Document} doc        Where to listen.
 * @param {Object}   deps       What the buttons need.
 * @param {Function} deps.post  createPost()'s function.
 * @param {Window}   [deps.win] The window, for its next frame.
 * @return {Function} Stops listening.
 */
export function bindChecklists( doc, { post, win = window } ) {
	const listener = ( event ) => {
		const button =
			event.target instanceof win.Element
				? event.target.closest( BUTTONS )
				: null;
		const card = button && button.closest( '.onboarding-checklist' );

		if ( ! card || ! card.dataset.endpoint ) {
			return;
		}

		event.preventDefault();

		if ( button.hasAttribute( 'data-checklist-toggle' ) ) {
			toggle( card, button, post, win );
		} else if ( button.hasAttribute( 'data-checklist-mark' ) ) {
			// Ticked from the compact row, the task leaves the row, so the
			// focus goes to whatever takes its place there.
			send(
				card,
				'/tasks/' + encodeURIComponent( button.dataset.checklistMark ),
				{ done: '1' === button.dataset.done },
				button.closest( '.onboarding-checklist__next' )
					? NEXT
					: button.dataset.checklistMark,
				post,
				win
			);
		} else {
			send(
				card,
				'/dismiss',
				{ dismissed: button.hasAttribute( 'data-checklist-dismiss' ) },
				'',
				post,
				win
			);
		}
	};

	doc.addEventListener( 'click', listener );

	return () => doc.removeEventListener( 'click', listener );
}

/**
 * Open or close the compact card's list, then remember it.
 *
 * The page changes first and the request follows, so the list opens at once
 * however slow the server is. If remembering fails the list stays as it was
 * asked to be, and only the next visit forgets.
 *
 * @param {HTMLElement} card   The card.
 * @param {HTMLElement} button Its Show All button.
 * @param {Function}    post   createPost()'s function.
 * @param {Window}      win    The window.
 * @return {Promise|undefined} The request, when there was a list to open.
 */
export function toggle( card, button, post, win = window ) {
	const panel = card.ownerDocument.getElementById(
		button.getAttribute( 'aria-controls' )
	);

	if ( ! panel ) {
		return;
	}

	const open = 'true' !== button.getAttribute( 'aria-expanded' );
	const label = button.querySelector( '.onboarding-checklist__toggle-label' );
	const settings = cardSettings( card );

	panel.hidden = ! open;
	button.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	card.classList.toggle( 'is-expanded', open );

	if ( label ) {
		label.textContent = open ? button.dataset.hide : button.dataset.show;
	}

	return post( settings.endpoint + '/expanded', settings.nonce, {
		expanded: open,
	} ).catch( () => announce( card, failed( settings ), win ) );
}

/**
 * Post to one of the card's routes and put the answer in its place.
 *
 * @param {HTMLElement} card The card.
 * @param {string}      path The route, after the checklist's own.
 * @param {Object}      body What to send.
 * @param {string}      task The task to put the focus back on, if any, or NEXT.
 * @param {Function}    post createPost()'s function.
 * @param {Window}      win  The window.
 * @return {Promise|undefined} The request, unless one was already on its way.
 */
export function send( card, path, body, task, post, win = window ) {
	if ( 'true' === card.getAttribute( 'aria-busy' ) ) {
		return;
	}

	const settings = cardSettings( card );

	card.setAttribute( 'aria-busy', 'true' );

	return post( settings.endpoint + path, settings.nonce, {
		...body,
		context: settings.context,
		layout: settings.layout,
	} )
		.then( ( json ) => {
			if ( ! json || 'string' !== typeof json.html ) {
				throw new Error( ( json && json.message ) || '' );
			}

			replace( card, json.html, json.message || '', task, win );
		} )
		.catch( ( error ) => {
			card.removeAttribute( 'aria-busy' );
			announce( card, error.message || failed( settings ), win );
		} );
}

/**
 * Swap the card for its new markup, keeping the focus somewhere sensible.
 *
 * @param {HTMLElement} card    The card.
 * @param {string}      html    Its replacement, as the server drew it.
 * @param {string}      message What happened, to announce.
 * @param {string}      task    The task to focus, if any, or NEXT.
 * @param {Window}      win     The window.
 * @return {HTMLElement|null} The new card, or null when the answer was none.
 */
export function replace( card, html, message, task, win = window ) {
	const holder = card.ownerDocument.createElement( 'div' );

	holder.innerHTML = html;

	const fresh = holder.firstElementChild;

	if ( ! fresh ) {
		card.remove();

		return null;
	}

	card.replaceWith( fresh );

	let focus;

	if ( NEXT === task ) {
		focus = fresh.querySelector(
			'.onboarding-checklist__next .button, .onboarding-checklist__next button, [data-checklist-toggle]'
		);
	} else if ( task ) {
		focus = fresh.querySelector( '[data-task="' + task + '"] summary' );
	} else {
		focus = fresh.querySelector(
			'[data-checklist-restore], [data-checklist-dismiss]'
		);
	}

	if ( focus ) {
		focus.focus();
	}

	announce( fresh, message, win );

	return fresh;
}
