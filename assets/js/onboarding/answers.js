/**
 * The step's answers, sent with a button's action.
 *
 * The kit sends a button only the inputs inside its own field. A "Test
 * connection" button needs the key typed two fields above it, not yet
 * saved, so every answer on the step goes too, under its field key,
 * wherever the handler did not already get it.
 *
 * The kit asks through `fieldkit:action-payload`, a bubbling CustomEvent
 * whose `detail.payload` a listener writes to before the request is sent.
 */

/**
 * A posted name's field key: `prefix[key]` gives `key`.
 *
 * @param {string} name   The input's name.
 * @param {string} prefix The step's answers' name.
 * @return {string} The key, or '' for a name that is not one of the step's
 *                  answers (another prefix, or nested deeper).
 */
export function fieldKey( name, prefix ) {
	const match = name.match( /^[^[]+\[([^\]]+)\]$/ );

	return match && 0 === name.indexOf( prefix + '[' ) ? match[ 1 ] : '';
}

/**
 * Add the form's answers to a payload, keeping what it already has.
 *
 * The first value of a name wins, as it would have had the handler read
 * the form itself; files are left out.
 *
 * @param {HTMLFormElement} form    The step's form.
 * @param {string}          prefix  The step's answers' name.
 * @param {Object}          payload What the button sends; written to.
 */
export function addAnswers( form, prefix, payload ) {
	new window.FormData( form ).forEach( ( value, name ) => {
		const key = fieldKey( name, prefix );

		if (
			'' === key ||
			'string' !== typeof value ||
			Object.prototype.hasOwnProperty.call( payload, key )
		) {
			return;
		}

		payload[ key ] = value;
	} );
}

/**
 * Send the step's answers with every button's action on the form.
 *
 * @param {HTMLFormElement} form   The step's form.
 * @param {string}          prefix The step's answers' name ('' for a step
 *                                 that has none).
 */
export function sendAnswers( form, prefix ) {
	form.addEventListener( 'fieldkit:action-payload', ( event ) => {
		const payload = event.detail && event.detail.payload;

		if ( payload && prefix ) {
			addAnswers( form, prefix, payload );
		}
	} );
}
