/**
 * The import step: Continue waits until sugarcommerce/jobs' Sync says the
 * import has run, or that it failed.
 */

import { describe, expect, test } from 'vitest';

import { waitForSync } from '../../src/js/onboarding/sync';
import { loadStep } from './support/wizard';

/**
 * Fire one of the Sync's events, as jobs' sync.js does.
 *
 * @param {string} name   complete, error or cancelled.
 * @param {Object} detail Its detail.
 */
const fire = ( name, detail = { id: 'check' } ) =>
	document.dispatchEvent(
		new window.CustomEvent( 'sugar-sync:' + name, { detail } )
	);

/**
 * A fresh document's step, so no listener from another test answers.
 *
 * @return {Object} form, next, trigger and doc.
 */
function step() {
	const form = loadStep( { sync: true } ).querySelector( 'form' );

	return {
		form,
		next: form.querySelector( '.onboarding__next' ),
		trigger: form.querySelector( '.sugar-sync-trigger' ),
	};
}

describe( 'the import step', () => {
	test( 'holds Continue until the import has run, then hides the button that ran it', () => {
		const { form, next, trigger } = step();
		const doc = new window.EventTarget();

		waitForSync( form, doc );

		expect( next.disabled ).toBe( true );
		expect( trigger.hidden ).toBe( false );

		doc.dispatchEvent(
			new window.CustomEvent( 'sugar-sync:complete', {
				detail: { id: 'check', totals: {} },
			} )
		);

		expect( next.disabled ).toBe( false );
		expect( trigger.hidden ).toBe( true );
	} );

	test( 'a failed run still lets you past, and leaves the button to try again', () => {
		const { form, next, trigger } = step();
		const doc = new window.EventTarget();

		waitForSync( form, doc );
		doc.dispatchEvent( new window.CustomEvent( 'sugar-sync:error' ) );

		expect( next.disabled ).toBe( false );
		expect( trigger.hidden ).toBe( false );
	} );

	test( 'a cancelled run keeps Continue waiting', () => {
		const { form, next } = step();
		const doc = new window.EventTarget();

		waitForSync( form, doc );
		doc.dispatchEvent( new window.CustomEvent( 'sugar-sync:cancelled' ) );

		expect( next.disabled ).toBe( true );
	} );

	test( 'listens on the document by default', () => {
		const { form, next, trigger } = step();

		waitForSync( form );
		fire( 'complete' );

		expect( next.disabled ).toBe( false );
		expect( trigger.hidden ).toBe( true );
	} );

	test( 'Skip and Finish Later are never held', () => {
		const { form } = step();

		waitForSync( form, new window.EventTarget() );

		expect( form.querySelector( '.onboarding__skip' ).disabled ).toBe(
			false
		);
		expect( form.querySelector( '.onboarding__later' ).disabled ).toBe(
			false
		);
	} );

	test( 'a step drawn without Continue or the button does not break', () => {
		const { form } = step();
		const doc = new window.EventTarget();

		form.querySelector( '.onboarding__next' ).remove();
		form.querySelector( '.sugar-sync-trigger' ).remove();

		expect( () => {
			waitForSync( form, doc );
			doc.dispatchEvent(
				new window.CustomEvent( 'sugar-sync:complete' )
			);
			doc.dispatchEvent( new window.CustomEvent( 'sugar-sync:error' ) );
		} ).not.toThrow();
	} );
} );
