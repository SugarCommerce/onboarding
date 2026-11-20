/**
 * Onboarding wizard.
 *
 * Everything here is an enhancement: every step submits, skips, goes back
 * and finishes with no script at all. What this adds is where the focus
 * lands, the step's answers travelling with a button's action, a guard
 * against a double submit, the import step's wait, and the confetti.
 *
 * Loaded by Wizard::enqueue() on the wizard's own page, under the copy's
 * handle (Runtime::handle()), in the footer.
 */

import domReady from '@wordpress/dom-ready';

import { formSettings } from './lib/config';
import { sendAnswers } from './onboarding/answers';
import { celebrate } from './onboarding/confetti';
import { focusOnOpen } from './onboarding/focus';
import { oneAtATime } from './onboarding/submit';
import { waitForSync } from './onboarding/sync';

domReady( () => {
	const root = document.querySelector( '.onboarding' );

	if ( ! root ) {
		return;
	}

	focusOnOpen( root );

	const form = root.querySelector( '.onboarding__form' );

	if ( form ) {
		const settings = formSettings( form );

		sendAnswers( form, settings.prefix );
		oneAtATime( form );

		if ( settings.sync ) {
			waitForSync( form );
		}
	}

	celebrate( root );
} );
