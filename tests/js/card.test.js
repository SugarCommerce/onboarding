/**
 * The card's buttons: each posts to its route through apiFetch, swaps the
 * card for the answer and keeps the focus somewhere sensible; Show All
 * opens the list at once and remembers it after.
 *
 * The first two are tests/js/checklist-request.cjs, which ChecklistScriptTest
 * ran in Node on the old script, ported.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

import { bindChecklists, NEXT, replace } from '../../assets/js/checklist/card';
import { createPost } from '../../assets/js/checklist/request';
import {
	compactCard,
	dismissedCard,
	ENDPOINT,
	fakeApi,
	fakeClock,
	fullCard,
	load,
	refusal,
	settle,
} from './support/card';

let unbind = () => {};

/**
 * Wire the page's cards to a stand-in.
 *
 * @param {Function} answer What each route answers.
 * @return {Function} The stand-in apiFetch.
 */
function wire( answer ) {
	const apiFetch = fakeApi( answer );

	unbind = bindChecklists( document, { post: createPost( apiFetch ) } );

	return apiFetch;
}

const click = ( selector ) => {
	const element = document.querySelector( selector );
	const event = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
	} );

	element.dispatchEvent( event );

	return event;
};

const said = () =>
	document.querySelector( '.onboarding-checklist [aria-live]' ).textContent;

beforeEach( () => {
	document.body.innerHTML = '';
	fakeClock();
} );

afterEach( () => {
	unbind();
	vi.useRealTimers();
} );

describe( 'ported from checklist-request.cjs', () => {
	test( 'a button posts through apiFetch and swaps the card', async () => {
		load( fullCard() );
		const apiFetch = wire( () => ( {
			html: '<div class="fresh"><p aria-live="polite"></p></div>',
			message: 'Hidden.',
		} ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toBe(
			ENDPOINT + '/dismiss'
		);
		expect( apiFetch.mock.calls[ 0 ][ 0 ].method ).toBe( 'POST' );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].body ).toContain(
			'"dismissed":true'
		);
		expect( document.querySelector( '.onboarding-checklist' ) ).toBeNull();
		expect( document.querySelector( '.fresh' ) ).not.toBeNull();
		expect(
			document.querySelector( '.fresh [aria-live]' ).textContent
		).toBe( 'Hidden.' );
	} );

	test( "a refusal announces the server's message", async () => {
		const card = load( fullCard() );

		wire( () =>
			Promise.reject( refusal( 'rest_forbidden', 'Not allowed.', 403 ) )
		);

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( document.querySelector( '.onboarding-checklist' ) ).toBe(
			card
		);
		expect( said() ).toBe( 'Not allowed.' );
	} );
} );

describe( 'the request', () => {
	test( 'is JSON, with the nonce the card was printed with, parsed', async () => {
		load( fullCard() );
		const apiFetch = wire( () => ( { html: dismissedCard() } ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toEqual( {
			url: ENDPOINT + '/dismiss',
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': 'stale',
			},
			body: '{"dismissed":true,"context":"card","layout":"full"}',
			parse: true,
		} );
	} );

	test( "carries the card's context and layout, so the answer is drawn the same", async () => {
		load( compactCard( { data: { context: 'widget', nonce: 'fresh' } } ) );
		const apiFetch = wire( () => ( { html: dismissedCard() } ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( apiFetch.requests[ 0 ].body ).toEqual( {
			dismissed: true,
			context: 'widget',
			layout: 'compact',
		} );
		expect( apiFetch.requests[ 0 ].headers[ 'X-WP-Nonce' ] ).toBe(
			'fresh'
		);
	} );

	test( 'a card printed without context or layout is a full card', async () => {
		load( fullCard( { context: '', layout: '', nonce: '' } ) );
		const apiFetch = wire( () => ( { html: dismissedCard() } ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( apiFetch.requests[ 0 ].body ).toEqual( {
			dismissed: true,
			context: 'card',
			layout: 'full',
		} );
		expect( apiFetch.requests[ 0 ].headers[ 'X-WP-Nonce' ] ).toBe( '' );
	} );
} );

describe( 'hiding and bringing back', () => {
	test( 'Restore posts dismissed: false and focuses the new Hide', async () => {
		load( dismissedCard() );
		const apiFetch = wire( () => ( {
			html: fullCard(),
			message: 'Shown again.',
		} ) );

		click( '[data-checklist-restore]' );
		await settle();

		expect( apiFetch.requests[ 0 ] ).toMatchObject( {
			route: '/dismiss',
			body: { dismissed: false },
		} );
		expect( document.activeElement ).toBe(
			document.querySelector( '[data-checklist-dismiss]' )
		);
		expect( said() ).toBe( 'Shown again.' );
	} );

	test( 'Hide focuses the new Restore', async () => {
		load( fullCard() );
		wire( () => ( { html: dismissedCard() } ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( document.activeElement ).toBe(
			document.querySelector( '[data-checklist-restore]' )
		);
		// Nothing to say: the region stays empty.
		expect( said() ).toBe( '' );
	} );

	test( 'an empty answer takes the card away', async () => {
		load( fullCard() );
		wire( () => ( { html: '' } ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( document.querySelector( '.onboarding-checklist' ) ).toBeNull();
		expect( document.querySelector( '.wrap' ).children ).toHaveLength( 0 );
	} );
} );

describe( 'ticking a task by hand', () => {
	test( 'posts done: true to its route and focuses the same task', async () => {
		load( fullCard() );
		const apiFetch = wire( () => ( { html: fullCard() } ) );

		click( '[data-task="domain"] [data-checklist-mark]' );
		await settle();

		expect( apiFetch.requests[ 0 ] ).toMatchObject( {
			route: '/tasks/domain',
			body: { done: true, context: 'card', layout: 'full' },
		} );
		expect( document.activeElement ).toBe(
			document.querySelector( '[data-task="domain"] summary' )
		);
	} );

	test( 'unticking posts done: false', async () => {
		load( fullCard().replace( 'data-done="1"', 'data-done="0"' ) );
		const apiFetch = wire( () => ( { html: fullCard() } ) );

		click( '[data-task="domain"] [data-checklist-mark]' );
		await settle();

		expect( apiFetch.requests[ 0 ].body.done ).toBe( false );
	} );

	test( 'a key is sent as a path segment', async () => {
		load(
			fullCard().replace(
				'data-checklist-mark="domain"',
				'data-checklist-mark="a b/c"'
			)
		);
		const apiFetch = wire( () => ( { html: fullCard() } ) );

		click( '[data-task="domain"] [data-checklist-mark]' );
		await settle();

		expect( apiFetch.requests[ 0 ].route ).toBe( '/tasks/a%20b%2Fc' );
	} );

	test( "ticked from the compact row, the focus goes to the row's new next task", async () => {
		load( compactCard() );
		const apiFetch = wire( () => ( {
			html: compactCard( { next: 'docs' } ),
			message: 'Marked as done.',
		} ) );

		click( '.onboarding-checklist__next [data-checklist-mark]' );
		await settle();

		expect( apiFetch.requests[ 0 ].body ).toEqual( {
			done: true,
			context: 'card',
			layout: 'compact',
		} );
		expect( document.activeElement ).toBe(
			document.querySelector( '.onboarding-checklist__next .button' )
		);
		expect( said() ).toBe( 'Marked as done.' );
	} );

	test( 'with nothing left in the row, the focus goes to Show All', async () => {
		load( compactCard() );
		wire( () => ( { html: compactCard( { next: '' } ) } ) );

		click( '.onboarding-checklist__next [data-checklist-mark]' );
		await settle();

		expect( document.activeElement ).toBe(
			document.querySelector( '[data-checklist-toggle]' )
		);
	} );
} );

describe( 'one request at a time', () => {
	test( 'a second press while the first is on its way does nothing', async () => {
		const card = load( fullCard() );
		let finish;
		const apiFetch = wire(
			() => new Promise( ( resolve ) => ( finish = resolve ) )
		);

		click( '[data-checklist-dismiss]' );
		expect( card.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		click( '[data-checklist-dismiss]' );
		click( '[data-task="domain"] [data-checklist-mark]' );
		await settle();

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		finish( { html: dismissedCard() } );
		await settle();

		// The new card is not busy.
		expect(
			document
				.querySelector( '.onboarding-checklist' )
				.hasAttribute( 'aria-busy' )
		).toBe( false );
	} );

	test( 'a failure frees the card for another try', async () => {
		const card = load( fullCard() );
		let calls = 0;
		const apiFetch = wire( () => {
			calls++;

			return 1 === calls
				? Promise.reject( refusal( 'rest_error', 'Try again.' ) )
				: { html: dismissedCard() };
		} );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( card.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( said() ).toBe( 'Try again.' );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect(
			document.querySelector( '[data-checklist-restore]' )
		).not.toBeNull();
	} );
} );

describe( 'what is said when it does not work', () => {
	test( "a refusal with no message says the card's own words", async () => {
		load( fullCard( { failed: 'Das hat nicht geklappt.' } ) );
		wire( () => Promise.reject( { code: 'rest_error', data: {} } ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( said() ).toBe( 'Das hat nicht geklappt.' );
	} );

	test( 'an answer that is not a card says its message, or the card’s words', async () => {
		load( fullCard() );
		wire( ( route ) =>
			'/dismiss' === route ? { message: 'Nope.' } : null
		);

		click( '[data-checklist-dismiss]' );
		await settle();
		expect( said() ).toBe( 'Nope.' );

		click( '[data-task="domain"] [data-checklist-mark]' );
		await settle();
		expect( said() ).toBe( 'That did not work. Please try again.' );
		expect(
			document.querySelector( '[data-checklist-dismiss]' )
		).not.toBeNull();
	} );

	test( 'an answer whose html is not a string is not drawn', async () => {
		load( fullCard() );
		wire( () => ( { html: 42 } ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect(
			document.querySelector( '[data-checklist-dismiss]' )
		).not.toBeNull();
		expect( said() ).toBe( 'That did not work. Please try again.' );
	} );

	test( 'a request that never got an answer says apiFetch’s words', async () => {
		load( fullCard() );
		wire( () =>
			Promise.reject( {
				code: 'offline_error',
				message: 'You are probably offline.',
			} )
		);

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( said() ).toBe( 'You are probably offline.' );
	} );

	test( 'a card printed without words falls back to the library’s', async () => {
		load( fullCard( { failed: '' } ) );
		wire( () => Promise.reject( 'down' ) );

		click( '[data-checklist-dismiss]' );
		await settle();

		expect( said() ).toBe( 'That did not work. Please try again.' );
	} );
} );

describe( 'Show All', () => {
	test( 'opens the list at once, then remembers it', async () => {
		const card = load( compactCard() );
		let finish;
		const apiFetch = wire(
			() => new Promise( ( resolve ) => ( finish = resolve ) )
		);

		const event = click( '[data-checklist-toggle]' );
		const button = document.querySelector( '[data-checklist-toggle]' );

		expect( event.defaultPrevented ).toBe( true );
		expect(
			document.getElementById( 'onboarding-checklist-guide-tasks' ).hidden
		).toBe( false );
		expect( button.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( card.classList.contains( 'is-expanded' ) ).toBe( true );
		expect(
			button.querySelector( '.onboarding-checklist__toggle-label' )
				.textContent
		).toBe( 'Show Less' );

		await settle();
		expect( apiFetch.requests[ 0 ] ).toEqual( {
			route: '/expanded',
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': 'stale',
			},
			parse: true,
			body: { expanded: true },
		} );

		finish( { expanded: true } );
		await settle();

		// Nothing swapped, and the focus is where it was.
		expect( document.querySelector( '.onboarding-checklist' ) ).toBe(
			card
		);
		expect( said() ).toBe( '' );
	} );

	test( 'closes an open list and remembers that', async () => {
		const card = load( compactCard( { expanded: true } ) );
		const apiFetch = wire( () => ( { expanded: false } ) );

		click( '[data-checklist-toggle]' );
		await settle();

		expect(
			document.getElementById( 'onboarding-checklist-guide-tasks' ).hidden
		).toBe( true );
		expect( card.classList.contains( 'is-expanded' ) ).toBe( false );
		expect(
			document
				.querySelector( '[data-checklist-toggle]' )
				.getAttribute( 'aria-expanded' )
		).toBe( 'false' );
		expect(
			document.querySelector( '.onboarding-checklist__toggle-label' )
				.textContent
		).toBe( 'Show All' );
		expect( apiFetch.requests[ 0 ].body ).toEqual( { expanded: false } );
	} );

	test( "if remembering fails the list stays open, and the card's words are said", async () => {
		load( compactCard() );
		wire( () =>
			Promise.reject( refusal( 'rest_forbidden', 'Not allowed.', 403 ) )
		);

		click( '[data-checklist-toggle]' );
		await settle();

		expect(
			document.getElementById( 'onboarding-checklist-guide-tasks' ).hidden
		).toBe( false );
		expect( said() ).toBe( 'That did not work. Please try again.' );
	} );

	test( 'is not held up by a request on its way', async () => {
		const card = load( compactCard() );
		const apiFetch = wire( () => ( {} ) );

		card.setAttribute( 'aria-busy', 'true' );
		click( '[data-checklist-toggle]' );
		await settle();

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'with no list to open, does nothing', async () => {
		load(
			compactCard().replace(
				'id="onboarding-checklist-guide-tasks"',
				'id="elsewhere"'
			)
		);
		const apiFetch = wire( () => ( {} ) );

		click( '[data-checklist-toggle]' );
		await settle();

		expect( apiFetch ).not.toHaveBeenCalled();
		expect(
			document
				.querySelector( '[data-checklist-toggle]' )
				.getAttribute( 'aria-expanded' )
		).toBe( 'false' );
	} );
} );

describe( 'what the buttons answer to', () => {
	test( 'a card drawn after the page loaded works the same', async () => {
		document.body.innerHTML = '<div class="wrap"></div>';
		const apiFetch = wire( () => ( { html: dismissedCard() } ) );

		document.querySelector( '.wrap' ).innerHTML = fullCard();
		click( '[data-checklist-dismiss] ' );
		await settle();

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'a press inside a button counts as the button', async () => {
		load( compactCard() );
		const apiFetch = wire( () => ( {} ) );

		click( '.onboarding-checklist__toggle-label' );
		await settle();

		expect( apiFetch.requests[ 0 ].route ).toBe( '/expanded' );
	} );

	test( 'a button outside a card, or a card with no route, is left alone', async () => {
		document.body.innerHTML = `<button type="button" data-checklist-dismiss id="loose">x</button>${ fullCard(
			{ endpoint: '' }
		) }`;
		const apiFetch = wire( () => ( {} ) );

		const loose = click( '#loose' );
		const card = click( '.onboarding-checklist [data-checklist-dismiss]' );
		await settle();

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( loose.defaultPrevented ).toBe( false );
		expect( card.defaultPrevented ).toBe( false );
	} );

	test( 'a link in a task is followed, not posted', async () => {
		load( fullCard() );
		const apiFetch = wire( () => ( {} ) );

		const event = click( '[data-task="domain"] a.button' );
		await settle();

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( event.defaultPrevented ).toBe( false );
	} );

	test( 'unbinding stops the buttons', async () => {
		load( fullCard() );
		const apiFetch = wire( () => ( {} ) );

		unbind();
		click( '[data-checklist-dismiss]' );
		await settle();

		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'replace()', () => {
	test( 'a task that is not in the new card leaves the focus alone', () => {
		const card = load( fullCard() );
		const before = document.activeElement;

		const fresh = replace( card, fullCard(), '', 'gone' );

		expect( fresh.isConnected ).toBe( true );
		expect( document.activeElement ).toBe( before );
	} );

	test( 'NEXT is its own value, not a task key', () => {
		expect( NEXT ).toBe( '#next' );
	} );
} );
