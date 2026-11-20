/**
 * The import step.
 *
 * Continue waits until the import has run. The events are
 * sugarcommerce/jobs' Sync's: native CustomEvents on the document.
 */

/**
 * Hold Continue until the step's import has run.
 *
 * @param {HTMLFormElement} form The step's form, marked `data-sync="1"`.
 * @param {Document}        doc  Where the Sync's events arrive.
 */
export function waitForSync( form, doc = document ) {
	const next = form.querySelector( '.onboarding__next' );
	const trigger = form.querySelector( '.sugar-sync-trigger' );

	if ( next ) {
		next.disabled = true;
	}

	doc.addEventListener( 'sugar-sync:complete', () => {
		if ( next ) {
			next.disabled = false;
		}

		if ( trigger ) {
			trigger.hidden = true;
		}
	} );

	// A run that failed still lets you past: a wizard that cannot be
	// finished because an import failed is a plugin that cannot be set up
	// at all.
	doc.addEventListener( 'sugar-sync:error', () => {
		if ( next ) {
			next.disabled = false;
		}
	} );
}
