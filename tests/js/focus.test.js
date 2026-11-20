/**
 * A step opens with the focus on its question, or on what to fix.
 */

import { describe, expect, test, vi } from 'vitest';

import { focusOnOpen } from '../../assets/js/onboarding/focus';
import { loadFinish, loadStep } from './support/wizard';

const click = ( element ) => {
	const event = new window.MouseEvent( 'click', {
		bubbles: true,
		cancelable: true,
	} );

	element.dispatchEvent( event );

	return event;
};

describe( 'on opening', () => {
	test( 'the heading takes the focus, without scrolling', () => {
		const root = loadStep();
		const heading = root.querySelector( '.onboarding__heading' );
		const focus = vi.spyOn( heading, 'focus' );

		focusOnOpen( root );

		expect( document.activeElement ).toBe( heading );
		expect( focus ).toHaveBeenCalledWith( { preventScroll: true } );
	} );

	test( 'a refused step focuses its errors instead', () => {
		const root = loadStep( { errors: true } );

		focusOnOpen( root );

		expect( document.activeElement ).toBe(
			root.querySelector( '.onboarding__errors' )
		);
	} );

	test( 'the finish screen focuses its heading', () => {
		const root = loadFinish( false );

		focusOnOpen( root );

		expect( document.activeElement ).toBe(
			root.querySelector( '.onboarding__heading' )
		);
	} );

	test( 'a screen with neither leaves the focus alone', () => {
		document.body.innerHTML = '<div class="onboarding"></div>';

		expect( () =>
			focusOnOpen( document.querySelector( '.onboarding' ) )
		).not.toThrow();
		expect( document.activeElement ).toBe( document.body );
	} );
} );

describe( "an error's link", () => {
	test( 'moves the focus to its field and brings it into view', () => {
		const root = loadStep( { errors: true } );
		const field = document.getElementById( 'field-contact' );

		field.scrollIntoView = vi.fn();
		focusOnOpen( root );

		const event = click( root.querySelector( 'a[href="#field-contact"]' ) );

		expect( event.defaultPrevented ).toBe( true );
		expect( document.activeElement ).toBe( field );
		expect( field.scrollIntoView ).toHaveBeenCalledWith( {
			block: 'center',
		} );
	} );

	test( 'to a field that is not on the page is left to the browser', () => {
		const root = loadStep( { errors: true } );

		focusOnOpen( root );

		const event = click( root.querySelector( 'a[href="#nowhere"]' ) );

		expect( event.defaultPrevented ).toBe( false );
		expect( document.activeElement ).toBe(
			root.querySelector( '.onboarding__errors' )
		);
	} );

	test( 'a click in the list off a link does nothing', () => {
		const root = loadStep( { errors: true } );

		focusOnOpen( root );

		const event = click(
			root.querySelector( '.onboarding__errors li:last-child' )
		);

		expect( event.defaultPrevented ).toBe( false );
	} );

	test( 'only the error list is listened to', () => {
		const root = loadStep();
		const field = document.getElementById( 'field-contact' );

		field.scrollIntoView = vi.fn();
		focusOnOpen( root );
		root.querySelector( '.onboarding__fields' ).insertAdjacentHTML(
			'beforeend',
			'<a href="#field-contact" id="elsewhere">Contact</a>'
		);

		const event = click( document.getElementById( 'elsewhere' ) );

		expect( event.defaultPrevented ).toBe( false );
		expect( field.scrollIntoView ).not.toHaveBeenCalled();
	} );
} );
