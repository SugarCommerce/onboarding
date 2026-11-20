/**
 * Say something through a card's live region.
 *
 * Emptied first and written a frame later, so the same message twice in a
 * row is still announced the second time.
 *
 * @param {HTMLElement} card    The card.
 * @param {string}      message What to say.
 * @param {Window}      win     The window, for its next frame.
 */
export function announce( card, message, win = window ) {
	const region = card.querySelector( '[aria-live]' );

	if ( ! region || ! message ) {
		return;
	}

	region.textContent = '';

	win.requestAnimationFrame( () => {
		region.textContent = message;
	} );
}
