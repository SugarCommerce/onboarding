/**
 * Where the focus lands when a step opens.
 *
 * Each step is a new page, and a new page starts a screen reader at the top
 * of the admin. Focus goes to the step's heading instead, so the first
 * thing read is the question -- or to the list of errors, when the step
 * came back refused, so the first thing read is what to fix.
 */

/**
 * Put the focus on the errors, or else on the heading, and make the error
 * list's links move the focus to their fields.
 *
 * @param {HTMLElement} root The wizard (`.onboarding`).
 */
export function focusOnOpen( root ) {
	const errors = root.querySelector( '.onboarding__errors' );
	const heading = root.querySelector( '.onboarding__heading' );

	if ( errors ) {
		errors.focus();
		errors.addEventListener( 'click', followErrorLink );
	} else if ( heading ) {
		heading.focus( { preventScroll: true } );
	}
}

/**
 * A link in the error list moves focus to its field, not only the view: a
 * fragment link scrolls, and leaves the focus where it was.
 *
 * @param {MouseEvent} event The click.
 */
function followErrorLink( event ) {
	const link = event.target.closest( 'a[href^="#"]' );
	const target =
		link &&
		link.ownerDocument.getElementById(
			link.getAttribute( 'href' ).slice( 1 )
		);

	if ( target ) {
		event.preventDefault();
		target.focus();
		target.scrollIntoView( { block: 'center' } );
	}
}
