/**
 * Checklist cards as ChecklistView draws them, and a stand-in for the
 * checklist's REST routes behind a stand-in apiFetch.
 */

import { vi } from 'vitest';

export const ENDPOINT =
	'https://example.test/wp-json/sugarcommerce-onboarding/v1/checklists/guide';

/**
 * What every card carries, as ChecklistView::render() prints it.
 *
 * @param {Object} data Attributes to change ('' leaves one out).
 * @return {string} The attributes.
 */
const attributes = ( data = {} ) =>
	Object.entries( {
		endpoint: ENDPOINT,
		nonce: 'stale',
		context: 'card',
		layout: 'full',
		failed: 'That did not work. Please try again.',
		...data,
	} )
		.filter( ( [ , value ] ) => '' !== value )
		.map( ( [ key, value ] ) => `data-${ key }="${ value }"` )
		.join( ' ' );

/**
 * One task in the list.
 *
 * @param {string}  key    The task.
 * @param {boolean} done   Whether it is done.
 * @param {boolean} manual Whether it is ticked by hand.
 * @return {string} Its markup.
 */
const task = ( key, done, manual ) =>
	`<li class="onboarding-checklist__task onboarding-checklist__task--${
		done ? 'done' : 'todo'
	}" data-task="${ key }"><details name="onboarding-checklist-guide"><summary><span class="onboarding-checklist__task-title">${ key }</span></summary><div class="onboarding-checklist__body"><div class="onboarding-checklist__actions"><a class="button button-primary" href="#go-${ key }">Go</a>${
		manual
			? `<button type="button" class="button-link onboarding-checklist__mark" data-checklist-mark="${ key }" data-done="${
					done ? '0' : '1'
				}">${ done ? 'Mark as not done' : 'Mark as done' }</button>`
			: ''
	}</div></div></details></li>`;

const TASKS = `<ol class="onboarding-checklist__tasks">${ task(
	'orders',
	true,
	false
) }${ task( 'domain', false, true ) }${ task( 'docs', false, false ) }</ol>`;

const STATUS =
	'<p class="onboarding-checklist__status screen-reader-text" aria-live="polite"></p>';

/**
 * The full card.
 *
 * @param {Object} data Attributes to change.
 * @return {string} Its markup.
 */
export const fullCard = ( data = {} ) =>
	`<section class="onboarding-checklist onboarding-checklist--card onboarding-checklist--full" id="onboarding-checklist-guide" ${ attributes(
		data
	) }><div class="onboarding-checklist__header"><h2 class="onboarding-checklist__title">Get ready</h2><button type="button" class="onboarding-checklist__dismiss" data-checklist-dismiss aria-label="Hide">x</button></div>${ TASKS }${ STATUS }</section>`;

/**
 * The compact card: one row with the next task, the list behind Show All.
 *
 * @param {Object}  options          What to draw.
 * @param {boolean} options.expanded Whether the list starts open.
 * @param {string}  options.next     The next task ('' when all are done).
 * @param {Object}  options.data     Attributes to change.
 * @return {string} Its markup.
 */
export const compactCard = ( {
	expanded = false,
	next = 'domain',
	data = {},
} = {} ) =>
	`<section class="onboarding-checklist onboarding-checklist--card onboarding-checklist--compact${
		expanded ? ' is-expanded' : ''
	}" id="onboarding-checklist-guide" ${ attributes( {
		layout: 'compact',
		...data,
	} ) }><div class="onboarding-checklist__row"><div class="onboarding-checklist__summary"><h2 class="onboarding-checklist__title">Get ready</h2></div>${
		next
			? `<div class="onboarding-checklist__next" data-task="${ next }"><p class="onboarding-checklist__next-text"><span class="onboarding-checklist__next-label">Next:</span> <span class="onboarding-checklist__next-title">${ next }</span></p><div class="onboarding-checklist__actions"><a class="button button-primary" href="#go-${ next }">Go</a><button type="button" class="button-link onboarding-checklist__mark" data-checklist-mark="${ next }" data-done="1">Mark as done</button></div></div>`
			: '<div class="onboarding-checklist__next"><span class="onboarding-checklist__next-title">All done.</span></div>'
	}<div class="onboarding-checklist__controls"><button type="button" class="button-link onboarding-checklist__toggle" data-checklist-toggle aria-expanded="${
		expanded ? 'true' : 'false'
	}" aria-controls="onboarding-checklist-guide-tasks" data-show="Show All" data-hide="Show Less"><span class="onboarding-checklist__toggle-label">${
		expanded ? 'Show Less' : 'Show All'
	}</span></button></div><button type="button" class="onboarding-checklist__dismiss" data-checklist-dismiss aria-label="Hide">x</button></div><div class="onboarding-checklist__panel" id="onboarding-checklist-guide-tasks"${
		expanded ? '' : ' hidden'
	}>${ TASKS }</div>${ STATUS }</section>`;

/**
 * What replaces a dismissed card.
 *
 * @param {Object} data Attributes to change.
 * @return {string} Its markup.
 */
export const dismissedCard = ( data = {} ) =>
	`<div class="onboarding-checklist onboarding-checklist--dismissed" id="onboarding-checklist-guide" ${ attributes(
		data
	) }><p>The setup guide is hidden.</p><button type="button" class="button-link" data-checklist-restore>Show it again</button>${ STATUS }</div>`;

/**
 * Put markup on the page, in place of whatever was there.
 *
 * @param {string} html The markup.
 * @return {HTMLElement} The card.
 */
export function load( html ) {
	document.body.innerHTML = `<div class="wrap">${ html }</div>`;

	return document.querySelector( '.onboarding-checklist' );
}

/**
 * A refusal, as apiFetch rejects with WordPress's answer.
 *
 * @param {string} code    The error's code.
 * @param {string} message Its message.
 * @param {number} status  The HTTP status.
 * @return {Object} The rejection.
 */
export const refusal = ( code, message, status = 400 ) => ( {
	code,
	message,
	data: { status },
} );

/**
 * A stand-in apiFetch for the checklist's routes.
 *
 * @param {Function} answer ( route, body ) => the answer, or a rejected
 *                          promise.
 * @return {Function} apiFetch, with `requests`: each one's route, method,
 *                    headers and decoded body.
 */
export function fakeApi( answer ) {
	const requests = [];

	const apiFetch = vi.fn( ( request ) => {
		const route = request.url.slice( ENDPOINT.length );
		const body = JSON.parse( request.body );

		requests.push( {
			route,
			method: request.method,
			headers: request.headers,
			parse: request.parse,
			body,
		} );

		return Promise.resolve().then( () => answer( route, body ) );
	} );

	apiFetch.requests = requests;

	return apiFetch;
}

/**
 * Let every pending promise and the next frame run.
 *
 * @return {Promise} Settled.
 */
export const settle = () =>
	new Promise( ( resolve ) => setTimeout( resolve, 30 ) );
