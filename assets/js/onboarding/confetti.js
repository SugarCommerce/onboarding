/**
 * Confetti, on a finish screen that asked for it.
 *
 * Only for someone who has not asked the browser for less movement. Ninety
 * pieces fall for four seconds, fading as they go, on a canvas over the
 * page that is taken away when they have gone.
 */

/**
 * How long the confetti falls, in milliseconds.
 */
export const DURATION = 4000;

/**
 * How many pieces.
 */
const COUNT = 90;

/**
 * The colours after the accent: WordPress's own green, yellow, red, grey.
 */
const COLOURS = [ '#00a32a', '#dba617', '#d63638', '#8c8f94' ];

/**
 * The pieces, scattered above the top of a canvas.
 *
 * @param {number}   width   The canvas's width.
 * @param {number}   height  Its height.
 * @param {string[]} colours What to colour them.
 * @param {Function} random  Math.random, or a stand-in.
 * @return {Object[]} Each piece's place, size, spin, drift, fall and colour.
 */
export function scatter( width, height, colours, random = Math.random ) {
	const pieces = [];

	for ( let i = 0; i < COUNT; i++ ) {
		pieces.push( {
			x: random() * width,
			y: -20 - random() * height * 0.5,
			w: 6 + random() * 6,
			h: 8 + random() * 8,
			spin: random() * Math.PI,
			drift: -1 + random() * 2,
			fall: 2 + random() * 3,
			colour: colours[ Math.floor( random() * colours.length ) ],
		} );
	}

	return pieces;
}

/**
 * How opaque the pieces are, so far in.
 *
 * @param {number} elapsed Milliseconds since the first frame.
 * @return {number} 1 at the start, 0 by the end.
 */
export const opacity = ( elapsed ) => Math.max( 0, 1 - elapsed / DURATION );

/**
 * Move every piece one frame on and draw it.
 *
 * @param {CanvasRenderingContext2D} context The canvas's.
 * @param {Object[]}                 pieces  The pieces; moved.
 * @param {number}                   elapsed Milliseconds since the first frame.
 */
export function drawFrame( context, pieces, elapsed ) {
	pieces.forEach( ( piece ) => {
		piece.y += piece.fall;
		piece.x += piece.drift;
		piece.spin += 0.05;

		context.save();
		context.translate( piece.x, piece.y );
		context.rotate( piece.spin );
		context.fillStyle = piece.colour;
		context.globalAlpha = opacity( elapsed );
		context.fillRect( -piece.w / 2, -piece.h / 2, piece.w, piece.h );
		context.restore();
	} );
}

/**
 * Throw the confetti, if the finish screen asked for it.
 *
 * @param {HTMLElement} root The wizard (`.onboarding`), whose
 *                           `--onboarding-accent` is the first colour.
 * @param {Window}      win  The window.
 * @return {boolean} Whether it was thrown.
 */
export function celebrate( root, win = window ) {
	const finish = root.querySelector( '.onboarding__finish' );

	if (
		! finish ||
		'1' !== finish.dataset.celebrate ||
		win.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
	) {
		return false;
	}

	const doc = root.ownerDocument;
	const canvas = doc.createElement( 'canvas' );
	const accent = win
		.getComputedStyle( root )
		.getPropertyValue( '--onboarding-accent' )
		.trim();

	canvas.className = 'onboarding__confetti';
	canvas.setAttribute( 'aria-hidden', 'true' );
	doc.body.appendChild( canvas );

	const context = canvas.getContext( '2d' );
	const size = () => {
		canvas.width = win.innerWidth;
		canvas.height = win.innerHeight;
	};

	size();
	win.addEventListener( 'resize', size );

	const pieces = scatter( canvas.width, canvas.height, [
		accent || '#2271b1',
		...COLOURS,
	] );
	let started = null;

	const frame = ( now ) => {
		if ( null === started ) {
			started = now;
		}

		const elapsed = now - started;

		context.clearRect( 0, 0, canvas.width, canvas.height );
		drawFrame( context, pieces, elapsed );

		if ( elapsed < DURATION ) {
			win.requestAnimationFrame( frame );
		} else {
			canvas.remove();
			win.removeEventListener( 'resize', size );
		}
	};

	win.requestAnimationFrame( frame );

	return true;
}
