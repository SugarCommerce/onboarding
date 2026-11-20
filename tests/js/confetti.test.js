/**
 * Confetti on a finish screen that asked for it, for someone who has not
 * asked for less movement, gone after four seconds.
 */

import { describe, expect, test, vi } from 'vitest';

import {
	celebrate,
	drawFrame,
	DURATION,
	opacity,
	scatter,
} from '../../assets/js/onboarding/confetti';
import { loadFinish, loadStep } from './support/wizard';

/**
 * A canvas context that records what was drawn.
 *
 * @return {Object} The context; `calls` lists each method called.
 */
function fakeContext() {
	const calls = [];
	const record =
		( name ) =>
		( ...args ) =>
			calls.push( [ name, ...args ] );

	return {
		calls,
		save: record( 'save' ),
		restore: record( 'restore' ),
		translate: record( 'translate' ),
		rotate: record( 'rotate' ),
		fillRect: record( 'fillRect' ),
		clearRect: record( 'clearRect' ),
		set fillStyle( value ) {
			calls.push( [ 'fillStyle', value ] );
		},
		set globalAlpha( value ) {
			calls.push( [ 'globalAlpha', value ] );
		},
	};
}

/**
 * A window with frames run when told to, and a motion preference.
 *
 * @param {boolean} reduce Whether less movement was asked for.
 * @return {Object} The window, with frame( now ) to run the next frame.
 */
function fakeWindow( reduce = false ) {
	const queue = [];
	const listeners = {};

	return {
		innerWidth: 800,
		innerHeight: 600,
		matchMedia: vi.fn( ( query ) => ( {
			matches: reduce && '(prefers-reduced-motion: reduce)' === query,
		} ) ),
		getComputedStyle: ( element ) => window.getComputedStyle( element ),
		requestAnimationFrame: ( callback ) => queue.push( callback ),
		addEventListener: ( type, callback ) => {
			listeners[ type ] = callback;
		},
		removeEventListener: ( type, callback ) => {
			if ( listeners[ type ] === callback ) {
				delete listeners[ type ];
			}
		},
		listeners,
		frame: ( now ) => queue.splice( 0 ).forEach( ( cb ) => cb( now ) ),
		get pending() {
			return queue.length;
		},
	};
}

/**
 * Give every canvas a recording context.
 *
 * @return {Object} The context the canvas will hand out.
 */
function canvases() {
	const context = fakeContext();

	window.HTMLCanvasElement.prototype.getContext = () => context;

	return context;
}

describe( 'scatter()', () => {
	test( 'ninety pieces above the canvas, sized, spun and coloured', () => {
		const pieces = scatter( 800, 600, [ 'a', 'b' ], () => 0.5 );

		expect( pieces ).toHaveLength( 90 );
		expect( pieces[ 0 ] ).toEqual( {
			x: 400,
			y: -170,
			w: 9,
			h: 12,
			spin: Math.PI / 2,
			drift: 0,
			fall: 3.5,
			colour: 'b',
		} );
	} );

	test( 'the extremes stay in their ranges', () => {
		const low = scatter( 800, 600, [ 'a', 'b' ], () => 0 )[ 0 ];
		const high = scatter( 800, 600, [ 'a', 'b' ], () => 0.999 )[ 0 ];

		expect( low ).toMatchObject( {
			x: 0,
			y: -20,
			w: 6,
			h: 8,
			drift: -1,
			fall: 2,
			colour: 'a',
		} );
		expect( high.colour ).toBe( 'b' );
		expect( high.y ).toBeCloseTo( -20 - 0.999 * 300 );
		expect( high.w ).toBeLessThan( 12 );
		expect( high.drift ).toBeLessThan( 1 );
		expect( high.fall ).toBeLessThan( 5 );
	} );
} );

describe( 'a frame', () => {
	test( 'fades from whole to nothing over four seconds', () => {
		expect( DURATION ).toBe( 4000 );
		expect( opacity( 0 ) ).toBe( 1 );
		expect( opacity( 1000 ) ).toBe( 0.75 );
		expect( opacity( 4000 ) ).toBe( 0 );
		expect( opacity( 6000 ) ).toBe( 0 );
	} );

	test( 'moves each piece and draws it centred, spun, in its colour', () => {
		const context = fakeContext();
		const piece = {
			x: 10,
			y: 20,
			w: 6,
			h: 8,
			spin: 1,
			drift: -1,
			fall: 2,
			colour: 'red',
		};

		drawFrame( context, [ piece ], 2000 );

		expect( piece ).toMatchObject( { x: 9, y: 22, spin: 1.05 } );
		expect( context.calls ).toEqual( [
			[ 'save' ],
			[ 'translate', 9, 22 ],
			[ 'rotate', 1.05 ],
			[ 'fillStyle', 'red' ],
			[ 'globalAlpha', 0.5 ],
			[ 'fillRect', -3, -4, 6, 8 ],
			[ 'restore' ],
		] );
	} );
} );

describe( 'celebrate()', () => {
	test( 'throws it on a finish screen that asked, in the accent first', () => {
		const context = canvases();
		const win = fakeWindow();
		const random = vi.spyOn( Math, 'random' ).mockReturnValue( 0 );

		expect( celebrate( loadFinish(), win ) ).toBe( true );
		random.mockRestore();

		const canvas = document.querySelector( 'canvas.onboarding__confetti' );

		expect( canvas.parentElement ).toBe( document.body );
		expect( canvas.getAttribute( 'aria-hidden' ) ).toBe( 'true' );
		expect( canvas.width ).toBe( 800 );
		expect( canvas.height ).toBe( 600 );

		win.frame( 100 );

		expect( context.calls[ 0 ] ).toEqual( [ 'clearRect', 0, 0, 800, 600 ] );
		expect( context.calls ).toContainEqual( [ 'fillStyle', '#7c3aed' ] );
		expect( context.calls ).toContainEqual( [ 'globalAlpha', 1 ] );
	} );

	test( 'without an accent, WordPress blue first', () => {
		const context = canvases();
		const win = fakeWindow();
		const random = vi.spyOn( Math, 'random' ).mockReturnValue( 0 );
		const root = loadFinish();

		root.removeAttribute( 'style' );
		celebrate( root, win );
		random.mockRestore();
		win.frame( 0 );

		expect( context.calls ).toContainEqual( [ 'fillStyle', '#2271b1' ] );
	} );

	test( 'follows the window as it is resized, then stops at four seconds', () => {
		canvases();
		const win = fakeWindow();

		celebrate( loadFinish(), win );

		const canvas = document.querySelector( 'canvas.onboarding__confetti' );

		win.innerWidth = 390;
		win.listeners.resize();
		expect( canvas.width ).toBe( 390 );

		win.frame( 1000 );
		win.frame( 4999 );
		expect( canvas.isConnected ).toBe( true );
		expect( win.pending ).toBe( 1 );

		win.frame( 5000 );
		expect( canvas.isConnected ).toBe( false );
		expect( win.pending ).toBe( 0 );
		expect( win.listeners.resize ).toBeUndefined();
	} );

	test( 'a first frame at time 0 is still the start', () => {
		canvases();
		const win = fakeWindow();

		celebrate( loadFinish(), win );

		const canvas = document.querySelector( 'canvas.onboarding__confetti' );

		win.frame( 0 );
		win.frame( 4000 );

		expect( canvas.isConnected ).toBe( false );
		expect( win.pending ).toBe( 0 );
	} );

	test( 'counts from its first frame, not from when the page opened', () => {
		const context = canvases();
		const win = fakeWindow();

		celebrate( loadFinish(), win );
		win.frame( 90000 );

		expect( context.calls ).toContainEqual( [ 'globalAlpha', 1 ] );
		expect( win.pending ).toBe( 1 );
	} );

	test.each( [
		[
			'a finish screen that did not ask',
			() => loadFinish( false ),
			false,
		],
		[ 'someone who asked for less movement', () => loadFinish(), true ],
		[ 'a step', () => loadStep(), false ],
	] )( 'not for %s', ( what, load, reduce ) => {
		canvases();
		document.body.innerHTML = '';

		const win = fakeWindow( reduce );

		expect( celebrate( load(), win ) ).toBe( false );
		expect( document.querySelector( 'canvas' ) ).toBeNull();
		expect( win.pending ).toBe( 0 );
	} );
} );
