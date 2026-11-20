/**
 * The entries: the wizard's waits for the page and wires what the screen
 * has; the card's binds the document at once, through the imported
 * apiFetch.
 */

import { beforeEach, describe, expect, test, vi } from 'vitest';

import { compactCard, ENDPOINT, load, settle } from './support/card';
import { loadFinish, loadStep } from './support/wizard';

const ready = vi.hoisted( () => ( { run: null } ) );
const apiFetch = vi.hoisted( () => vi.fn() );

vi.mock( '@wordpress/dom-ready', () => ( {
	default: ( callback ) => {
		ready.run = callback;
	},
} ) );

vi.mock( '@wordpress/api-fetch', () => ( { default: apiFetch } ) );

beforeEach( () => {
	vi.resetModules();
	ready.run = null;
	apiFetch.mockReset();
	window.matchMedia = () => ( { matches: false } );
} );

describe( 'the wizard', () => {
	test( 'waits for the page, then focuses, guards the form and sends its answers', async () => {
		const root = loadStep( { errors: true } );
		const form = root.querySelector( 'form' );

		await import( '../../assets/js/onboarding.js' );

		expect( typeof ready.run ).toBe( 'function' );
		expect( document.activeElement ).toBe( document.body );

		ready.run();

		expect( document.activeElement ).toBe(
			root.querySelector( '.onboarding__errors' )
		);

		const payload = {};

		root.querySelector( '.field-kit-action-button' ).dispatchEvent(
			new window.CustomEvent( 'fieldkit:action-payload', {
				bubbles: true,
				detail: { payload },
			} )
		);
		expect( payload.store_name ).toBe( 'My shop' );

		form.dispatchEvent(
			new window.Event( 'submit', { cancelable: true } )
		);
		expect( form.getAttribute( 'aria-busy' ) ).toBe( 'true' );

		// Not an import step: Continue is not held.
		expect( form.querySelector( '.onboarding__next' ).disabled ).toBe(
			false
		);
	} );

	test( 'holds Continue on an import step', async () => {
		const form = loadStep( { sync: true } ).querySelector( 'form' );

		await import( '../../assets/js/onboarding.js' );
		ready.run();

		expect( form.querySelector( '.onboarding__next' ).disabled ).toBe(
			true
		);

		document.dispatchEvent(
			new window.CustomEvent( 'sugar-sync:complete' )
		);

		expect( form.querySelector( '.onboarding__next' ).disabled ).toBe(
			false
		);
	} );

	test( 'throws the confetti on a finish screen that asked', async () => {
		const frame = vi
			.spyOn( window, 'requestAnimationFrame' )
			.mockImplementation( () => 0 );

		window.HTMLCanvasElement.prototype.getContext = () => ( {} );
		loadFinish();

		await import( '../../assets/js/onboarding.js' );
		ready.run();

		expect(
			document.querySelector( 'canvas.onboarding__confetti' )
		).not.toBeNull();
		expect( frame ).toHaveBeenCalledTimes( 1 );
		frame.mockRestore();
	} );

	test( 'does nothing on a page with no wizard', async () => {
		document.body.innerHTML = '<form class="onboarding__form"></form>';

		await import( '../../assets/js/onboarding.js' );

		expect( () => ready.run() ).not.toThrow();
	} );
} );

describe( 'the checklist', () => {
	test( 'binds the document at once and posts through the imported apiFetch', async () => {
		apiFetch.mockResolvedValue( { expanded: true } );

		await import( '../../assets/js/checklist.js' );

		// Drawn after the script ran: still answered.
		load( compactCard() );
		document
			.querySelector( '[data-checklist-toggle]' )
			.dispatchEvent(
				new window.MouseEvent( 'click', { bubbles: true } )
			);
		await settle();

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			url: ENDPOINT + '/expanded',
			method: 'POST',
			body: '{"expanded":true}',
		} );
	} );
} );
