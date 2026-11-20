/**
 * What PHP printed for the scripts.
 *
 * The only module that reads it. Everything is on the element it belongs
 * to -- the step's form, the checklist card -- rather than localized into a
 * global: Strauss renames classes and namespaces, not string literals, so
 * two plugins each bundling a prefixed copy would agree on the name of the
 * global and disagree about who set it. An attribute on the element the
 * script already found belongs to that element.
 */

/**
 * A step's form: Screen::step() draws it.
 *
 * @param {HTMLFormElement} form The form.
 * @return {Object} prefix (the name every answer is posted under, '' for
 *                  none) and sync (whether Continue waits for an import).
 */
export function formSettings( form ) {
	return {
		prefix: form.dataset.fields || '',
		sync: '1' === form.dataset.sync,
	};
}

/**
 * A checklist card: ChecklistView::render() draws it.
 *
 * @param {HTMLElement} card The card.
 * @return {Object} endpoint (the checklist's REST route, '' for none),
 *                  nonce, context ("card" or "widget"), layout ("full" or
 *                  "compact") and failed (what to say when a button did not
 *                  work, as the server translated it; '' for none).
 */
export function cardSettings( card ) {
	return {
		endpoint: card.dataset.endpoint || '',
		nonce: card.dataset.nonce || '',
		context: card.dataset.context || 'card',
		layout: card.dataset.layout || 'full',
		failed: card.dataset.failed || '',
	};
}
