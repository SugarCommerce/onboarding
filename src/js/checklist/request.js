/**
 * The card's requests.
 *
 * Through wp.apiFetch, because the nonce printed with the card runs out
 * after a day: a dashboard left open overnight then gets a 403 from every
 * button. apiFetch notices the stale nonce, asks for a fresh one and sends
 * the request again.
 */

/**
 * A function that posts JSON to one of a card's routes.
 *
 * @param {Function} apiFetch wp.apiFetch, or a stand-in.
 * @return {Function} ( url, nonce, body ) => Promise of the decoded answer.
 *                    It rejects with an Error whose message is the server's
 *                    reason for a refusal, apiFetch's for a request that
 *                    never got an answer, or '' when there is none to give.
 */
export function createPost( apiFetch ) {
	return ( url, nonce, body ) =>
		apiFetch( {
			url,
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
			},
			body: JSON.stringify( body ),
			parse: true,
		} ).catch( ( error ) => {
			throw new Error( String( ( error && error.message ) || '' ) );
		} );
}
