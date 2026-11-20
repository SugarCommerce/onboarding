/**
 * One submission at a time.
 *
 * The buttons are disabled a tick after the submit, not during it: a
 * disabled submitter is left out of the form data, and its value is what
 * says Continue from Skip.
 */

/**
 * The step's buttons.
 *
 * @param {HTMLFormElement} form The step's form.
 * @return {HTMLButtonElement[]} Continue, Skip and Finish Later.
 */
const buttons = ( form ) =>
	Array.from( form.querySelectorAll( '.onboarding__actions button' ) );

/**
 * Refuse a second submit while the first is on its way.
 *
 * @param {HTMLFormElement} form The step's form.
 * @param {Window}          win  The window, for its timer and `pageshow`.
 */
export function oneAtATime( form, win = window ) {
	// The buttons this disabled, and only those: Continue held for the
	// import step's run stays held.
	let held = [];

	form.addEventListener( 'submit', ( event ) => {
		if ( 'true' === form.getAttribute( 'aria-busy' ) ) {
			event.preventDefault();

			return;
		}

		form.setAttribute( 'aria-busy', 'true' );

		win.setTimeout( () => {
			held = buttons( form ).filter( ( button ) => ! button.disabled );
			held.forEach( ( button ) => {
				button.disabled = true;
			} );
		}, 0 );
	} );

	// Coming back with the browser's Back button can restore the page from
	// its cache, buttons still disabled. Only then: `pageshow` also fires
	// on every ordinary load, after the import step has held Continue.
	win.addEventListener( 'pageshow', ( event ) => {
		if ( ! event.persisted ) {
			return;
		}

		form.removeAttribute( 'aria-busy' );
		held.forEach( ( button ) => {
			button.disabled = false;
		} );
		held = [];
	} );
}
