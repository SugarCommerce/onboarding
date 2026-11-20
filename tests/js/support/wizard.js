/**
 * A wizard's step, and its finish screen, as Screen draws them.
 */

/**
 * The list of what a refused step got wrong.
 */
const ERRORS =
	'<div class="onboarding__errors" role="alert" tabindex="-1" aria-labelledby="onboarding-errors-title"><p class="onboarding__errors-title" id="onboarding-errors-title">Some answers need another look.</p><ul><li><a href="#field-contact">Contact is required.</a></li><li><a href="#nowhere">A field that is not on the page.</a></li><li>A step-wide rule.</li></ul></div>';

/**
 * The answers' fields, under the wizard's input name.
 *
 * @param {string} prefix The input name.
 * @return {string} Their markup.
 */
const fields = ( prefix ) =>
	`<div class="onboarding__fields">
		<input type="text" id="field-store_name" name="${ prefix }[store_name]" value="My shop">
		<input type="email" id="field-contact" name="${ prefix }[contact]" value="">
		<select name="${ prefix }[country]"><option value="GB" selected>GB</option></select>
		<input type="checkbox" name="${ prefix }[test_mode]" value="1" checked>
		<input type="checkbox" name="${ prefix }[tags][]" value="a" checked>
		<input type="file" name="${ prefix }[logo]">
		<input type="password" name="${ prefix }[api_key]" value="demo_typed">
		<input type="text" name="other[thing]" value="not ours">
		<input type="text" name="${ prefix }x[near]" value="not ours either">
		<div class="field-kit-action"><button type="button" class="button field-kit-action-button" data-action="test">Test Connection</button></div>
	</div>`;

/**
 * A step.
 *
 * @param {Object}  options        What to draw.
 * @param {boolean} options.errors Whether the step came back refused.
 * @param {string}  options.prefix The wizard's input name ('' for none).
 * @param {boolean} options.sync   Whether Continue waits for an import.
 * @param {boolean} options.skip   Whether the step can be skipped.
 * @return {HTMLElement} The wizard (`.onboarding`), on the page.
 */
export function loadStep( {
	errors = false,
	prefix = 'onboarding_myshop',
	sync = false,
	skip = true,
} = {} ) {
	document.body.innerHTML = `<div class="onboarding onboarding__layout onboarding__layout--focus"><main class="onboarding__main">
		<form method="post" class="onboarding__card onboarding__form" action="#step" data-step="store" data-sync="${
			sync ? '1' : '0'
		}" data-fields="${ prefix }">
			<input type="hidden" name="_wpnonce" value="abc"><input type="hidden" name="onboarding_wizard" value="myshop"><input type="hidden" name="onboarding_step" value="store">
			<a class="onboarding__back" href="#back">Back</a>
			<div class="onboarding__header"><h2 class="onboarding__heading" id="onboarding-heading" tabindex="-1">Your store</h2></div>
			${ errors ? ERRORS : '' }
			<div class="onboarding__content">${ fields( prefix || 'onboarding_myshop' ) }${
				sync
					? '<div class="onboarding__sync"><div class="onboarding__sync-bar"><button type="button" class="button sugar-sync-trigger">Check the Sample Orders</button></div></div>'
					: ''
			}</div>
			<div class="onboarding__actions">
				<button type="submit" name="onboarding_intent" value="next" class="button button-primary onboarding__next">Continue</button>
				${
					skip
						? '<button type="submit" name="onboarding_intent" value="skip" class="onboarding__quiet onboarding__skip" formnovalidate>Skip</button>'
						: ''
				}
				<button type="submit" name="onboarding_intent" value="later" class="onboarding__quiet onboarding__later" formnovalidate>Finish Later</button>
			</div>
		</form>
	</main></div>`;

	return document.querySelector( '.onboarding' );
}

/**
 * The finish screen.
 *
 * @param {boolean} celebrate Whether it asked for confetti.
 * @return {HTMLElement} The wizard (`.onboarding`), on the page.
 */
export function loadFinish( celebrate = true ) {
	document.body.innerHTML = `<div class="onboarding" style="--onboarding-accent: #7c3aed"><main class="onboarding__main"><div class="onboarding__card onboarding__finish" data-celebrate="${
		celebrate ? '1' : '0'
	}"><div class="onboarding__header"><h2 class="onboarding__heading" id="onboarding-heading" tabindex="-1">Done</h2></div></div></main></div>`;

	return document.querySelector( '.onboarding' );
}
