/**
 * The card's live region: emptied, then written a frame later.
 */

import { describe, expect, test } from 'vitest';

import { announce } from '../../assets/js/checklist/announce';

/**
 * A window whose next frame runs when told to.
 *
 * @return {Object} requestAnimationFrame, and run() for the frame.
 */
function frames() {
	const queue = [];

	return {
		requestAnimationFrame: ( callback ) => queue.push( callback ),
		run: () => queue.splice( 0 ).forEach( ( callback ) => callback() ),
		get pending() {
			return queue.length;
		},
	};
}

const card = () => {
	document.body.innerHTML =
		'<section class="onboarding-checklist"><p aria-live="polite">Before.</p></section>';

	return document.querySelector( '.onboarding-checklist' );
};

describe( 'announce()', () => {
	test( 'empties the region at once and writes the message a frame later', () => {
		const win = frames();
		const region = card().querySelector( '[aria-live]' );

		announce( region.parentElement, 'Hidden.', win );

		expect( region.textContent ).toBe( '' );
		win.run();
		expect( region.textContent ).toBe( 'Hidden.' );
	} );

	test( 'the same message twice is announced twice: emptied in between', () => {
		const win = frames();
		const box = card();
		const region = box.querySelector( '[aria-live]' );

		announce( box, 'Again.', win );
		win.run();
		announce( box, 'Again.', win );

		expect( region.textContent ).toBe( '' );
		win.run();
		expect( region.textContent ).toBe( 'Again.' );
	} );

	test( 'nothing to say leaves the region as it was', () => {
		const win = frames();
		const box = card();

		announce( box, '', win );

		expect( box.querySelector( '[aria-live]' ).textContent ).toBe(
			'Before.'
		);
		expect( win.pending ).toBe( 0 );
	} );

	test( 'a card with no region is left alone', () => {
		const win = frames();

		document.body.innerHTML = '<div class="onboarding-checklist"></div>';
		announce( document.body.firstChild, 'Hidden.', win );

		expect( win.pending ).toBe( 0 );
	} );
} );
