/**
 * One submission at a time, the buttons disabled a tick after it, and
 * back again when the page comes back from the browser's cache.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

import { oneAtATime } from '../../assets/js/onboarding/submit';
import { loadStep } from './support/wizard';

const submit = ( form ) => {
	const event = new window.Event( 'submit', {
		bubbles: true,
		cancelable: true,
	} );

	form.dispatchEvent( event );

	return event;
};

const disabled = ( form ) =>
	Array.from( form.querySelectorAll( '.onboarding__actions button' ) ).map(
		( button ) => button.disabled
	);

beforeEach( () => {
	vi.useFakeTimers();
} );

afterEach( () => {
	vi.useRealTimers();
} );

describe( 'a submit', () => {
	test( 'goes, marks the form busy and disables the buttons a tick later', () => {
		const form = loadStep().querySelector( 'form' );

		oneAtATime( form );

		const event = submit( form );

		expect( event.defaultPrevented ).toBe( false );
		expect( form.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		// Not yet: a disabled submitter is left out of the form data.
		expect( disabled( form ) ).toEqual( [ false, false, false ] );

		vi.advanceTimersByTime( 0 );

		expect( disabled( form ) ).toEqual( [ true, true, true ] );
	} );

	test( 'the button pressed is still in the form data when it goes', () => {
		const form = loadStep().querySelector( 'form' );
		let sent = null;

		oneAtATime( form );
		form.addEventListener( 'submit', ( event ) => {
			sent = new window.FormData( form, event.submitter ).get(
				'onboarding_intent'
			);
		} );

		const skip = form.querySelector( '.onboarding__skip' );
		const event = new window.SubmitEvent( 'submit', {
			bubbles: true,
			cancelable: true,
			submitter: skip,
		} );

		form.dispatchEvent( event );

		expect( sent ).toBe( 'skip' );
	} );

	test( 'a second, while the first is on its way, is stopped', () => {
		const form = loadStep().querySelector( 'form' );

		oneAtATime( form );
		submit( form );
		vi.advanceTimersByTime( 0 );

		expect( submit( form ).defaultPrevented ).toBe( true );
		expect( form.getAttribute( 'aria-busy' ) ).toBe( 'true' );
	} );

	test( 'buttons outside the actions are left alone', () => {
		const form = loadStep().querySelector( 'form' );

		oneAtATime( form );
		submit( form );
		vi.advanceTimersByTime( 0 );

		expect(
			form.querySelector( '.field-kit-action-button' ).disabled
		).toBe( false );
	} );
} );

/**
 * The page coming back: from the browser's cache (`persisted`), or an
 * ordinary load.
 *
 * @param {EventTarget} win       Where to fire it.
 * @param {boolean}     persisted Whether it came from the cache.
 */
const pageshow = ( win, persisted ) =>
	win.dispatchEvent(
		new window.PageTransitionEvent( 'pageshow', { persisted } )
	);

describe( 'coming back from the cache', () => {
	test( 'frees the form and its buttons', () => {
		const form = loadStep().querySelector( 'form' );

		oneAtATime( form );
		submit( form );
		vi.advanceTimersByTime( 0 );

		pageshow( window, true );

		expect( form.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( disabled( form ) ).toEqual( [ false, false, false ] );
		expect( submit( form ).defaultPrevented ).toBe( false );
	} );

	test( 'an ordinary load is not a page coming back: a held Continue stays held', () => {
		const form = loadStep( { sync: true } ).querySelector( 'form' );

		form.querySelector( '.onboarding__next' ).disabled = true;
		oneAtATime( form );

		pageshow( window, false );

		expect( disabled( form ) ).toEqual( [ true, false, false ] );
	} );

	test( 'frees only the buttons it disabled', () => {
		const form = loadStep( { sync: true } ).querySelector( 'form' );

		form.querySelector( '.onboarding__next' ).disabled = true;
		oneAtATime( form );
		submit( form );
		vi.advanceTimersByTime( 0 );

		expect( disabled( form ) ).toEqual( [ true, true, true ] );

		pageshow( window, true );

		expect( disabled( form ) ).toEqual( [ true, false, false ] );
		expect( form.hasAttribute( 'aria-busy' ) ).toBe( false );
	} );

	test( 'an ordinary load leaves a form on its way alone', () => {
		const form = loadStep().querySelector( 'form' );

		oneAtATime( form );
		submit( form );
		vi.advanceTimersByTime( 0 );

		pageshow( window, false );

		expect( form.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( disabled( form ) ).toEqual( [ true, true, true ] );
	} );

	test( 'listens on the window it is given', () => {
		const form = loadStep().querySelector( 'form' );
		const win = new window.EventTarget();

		win.setTimeout = ( callback ) => callback();
		oneAtATime( form, win );
		submit( form );

		expect( disabled( form ) ).toEqual( [ true, true, true ] );

		pageshow( win, true );

		expect( disabled( form ) ).toEqual( [ false, false, false ] );
	} );
} );
